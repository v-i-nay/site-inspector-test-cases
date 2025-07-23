#!/usr/bin/env bash

set -e

# Set variables from input args or default
DB_NAME=${1:-wordpress_test}
DB_USER=${2:-root}
DB_PASS=${3:-root}
DB_HOST=${4:-127.0.0.1}
WP_VERSION=${5:-latest}

# Paths
WP_TESTS_DIR=${WP_TESTS_DIR-/tmp/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-/tmp/wordpress}

download() {
  if [ "$(which curl)" ]; then
    curl -s "$1" > "$2"
  elif [ "$(which wget)" ]; then
    wget -nv -O "$2" "$1"
  fi
}

install_wp() {
  if [ -d "$WP_CORE_DIR" ]; then
    return
  fi

  mkdir -p "$WP_CORE_DIR"
  cd "$WP_CORE_DIR"

  if [ "$WP_VERSION" == "latest" ]; then
    ARCHIVE_NAME="latest"
  else
    ARCHIVE_NAME="wordpress-$WP_VERSION"
  fi

  download https://wordpress.org/${ARCHIVE_NAME}.tar.gz /tmp/wordpress.tar.gz
  tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz
}

install_test_suite() {
  mkdir -p "$WP_TESTS_DIR"
  cd "$WP_TESTS_DIR"

  download https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/functions.php includes/functions.php
  download https://develop.svn.wordpress.org/trunk/tests/phpunit/includes/bootstrap.php includes/bootstrap.php
  download https://develop.svn.wordpress.org/trunk/tests/phpunit/wp-tests-config-sample.php wp-tests-config.php

  sed -i "s/youremptytestdbnamehere/$DB_NAME/" wp-tests-config.php
  sed -i "s/yourusernamehere/$DB_USER/" wp-tests-config.php
  sed -i "s/yourpasswordhere/$DB_PASS/" wp-tests-config.php
  sed -i "s|localhost|$DB_HOST|" wp-tests-config.php
}

install_db() {
  # Create DB
  mysql -u "$DB_USER" -p"$DB_PASS" -h "$DB_HOST" -e "DROP DATABASE IF EXISTS $DB_NAME; CREATE DATABASE $DB_NAME;"
}

install_wp
install_test_suite
install_db
