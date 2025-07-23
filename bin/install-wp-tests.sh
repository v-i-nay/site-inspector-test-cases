#!/usr/bin/env bash

set -euo pipefail

DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}

WP_TESTS_DIR="${WP_TESTS_DIR:-/tmp/wordpress-tests-lib}"
WP_CORE_DIR="${WP_CORE_DIR:-/tmp/wordpress/}"

download() {
  if [ "$(command -v curl)" ]; then
    curl -sL "$1" -o "$2"
  else
    wget -nv -O "$2" "$1"
  fi
}

install_wp() {
  if [ ! -d "$WP_CORE_DIR" ]; then
    mkdir -p "$WP_CORE_DIR"
  fi

  if [ "$WP_VERSION" == "latest" ]; then
    WP_VERSION=$(curl -s https://api.wordpress.org/core/version-check/1.7/ | grep -o '"version":"[^"]*' | cut -d'"' -f4 | head -1)
  fi

  echo "Installing WordPress $WP_VERSION..."
  download https://wordpress.org/wordpress-"$WP_VERSION".tar.gz /tmp/wordpress.tar.gz
  tar -xzf /tmp/wordpress.tar.gz -C /tmp
  mv /tmp/wordpress/* "$WP_CORE_DIR"
}

install_test_suite() {
  if [ ! -d "$WP_TESTS_DIR" ]; then
    mkdir -p "$WP_TESTS_DIR"
    svn co --quiet https://develop.svn.wordpress.org/tags/"$WP_VERSION"/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
    svn co --quiet https://develop.svn.wordpress.org/tags/"$WP_VERSION"/tests/phpunit/data/ "$WP_TESTS_DIR/data"
  fi

  download https://develop.svn.wordpress.org/tags/"$WP_VERSION"/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR"/wp-tests-config.php
}

install_db() {
  mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
}

install_wp
install_test_suite
install_db
