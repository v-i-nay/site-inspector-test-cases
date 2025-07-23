#!/usr/bin/env bash

set -ex

if ! command -v svn >/dev/null; then
  echo "SVN is required but not installed!" >&2
  exit 1
fi
if ! command -v curl >/dev/null && ! command -v wget >/dev/null; then
  echo "Either curl or wget is required but neither is installed!" >&2
  exit 1
fi

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

WP_TESTS_DIR="${WP_TESTS_DIR-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR-/tmp/wordpress/}"

download() {
  if [ "$(command -v curl)" ]; then
    curl -s "$1" -o "$2"
  elif [ "$(command -v wget)" ]; then
    wget -nv -O "$2" "$1"
  fi
}

install_wp() {
  if [ -d "$WP_CORE_DIR" ]; then
    echo "Removing existing WordPress core directory at $WP_CORE_DIR"
    rm -rf "$WP_CORE_DIR"
  fi
  svn export --quiet https://core.svn.wordpress.org/tags/$WP_VERSION/ "$WP_CORE_DIR"
}

install_test_suite() {
  mkdir -p "$WP_TESTS_DIR"

  svn export --quiet https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
  svn export --quiet https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit/data/ "$WP_TESTS_DIR/data"
  svn export --quiet https://develop.svn.wordpress.org/tags/$WP_VERSION/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"

  sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
}

create_db() {
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" -h "$DB_HOST" || true
}

install_wp
install_test_suite
create_db