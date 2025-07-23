#!/usr/bin/env bash

# Exit on error
set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=$4
WP_VERSION=$5

# Fallbacks
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress/}

download() {
  if [ "$(which curl)" ]; then
    curl -s "$1" > "$2"
  elif [ "$(which wget)" ]; then
    wget -nv -O "$2" "$1"
  fi
}

install_wp() {
  if [ -d $WP_CORE_DIR ]; then
    return
  fi

  mkdir -p $WP_CORE_DIR
  download https://wordpress.org/wordpress-${WP_VERSION}.tar.gz /tmp/wordpress.tar.gz
  tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C $WP_CORE_DIR
}

install_test_suite() {
  if [ -d $WP_TESTS_DIR ]; then
    return
  fi

  mkdir -p $WP_TESTS_DIR
  svn co --quiet https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/ $WP_TESTS_DIR/includes
  svn co --quiet https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/ $WP_TESTS_DIR/data
  download https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php "$WP_TESTS_DIR"/wp-tests-config.php

  sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR/':" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s/yourdbnamehere/$DB_NAME/" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR"/wp-tests-config.php
  sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR"/wp-tests-config.php
}

install_db() {
  mysqladmin create $DB_NAME --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
}

install_wp
install_test_suite
install_db
