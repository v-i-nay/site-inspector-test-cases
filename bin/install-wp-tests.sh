#!/usr/bin/env bash

# Exit if any command fails
set -e

# Set defaults
DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}

# Directory to install test suite and WordPress core
WP_TESTS_DIR=${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR:-/tmp/wordpress/}

download() {
  if [ "$(command -v curl)" ]; then
    curl -sL "$1" > "$2"
  elif [ "$(command -v wget)" ]; then
    wget -nv -O "$2" "$1"
  fi
}

install_wp() {
  if [ ! -d "$WP_CORE_DIR" ]; then
    mkdir -p "$WP_CORE_DIR"
  fi

  if [ "$WP_VERSION" == 'latest' ]; then
    ARCHIVE_NAME='latest'
  else
    ARCHIVE_NAME="wordpress-${WP_VERSION}"
  fi

  download "https://wordpress.org/${ARCHIVE_NAME}.tar.gz" /tmp/wordpress.tar.gz
  tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"
}

install_test_suite() {
  if [ ! -d "$WP_TESTS_DIR" ]; then
    mkdir -p "$WP_TESTS_DIR"
  fi

  svn export --quiet https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
  svn export --quiet https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/ "$WP_TESTS_DIR/data"

  download https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php "$WP_TESTS_DIR/wp-tests-config.php"

  sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
  sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"
}

setup_db() {
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
}

install_wp
install_test_suite
setup_db
