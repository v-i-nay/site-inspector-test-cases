#!/usr/bin/env bash

set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=$4
WP_VERSION=${5:-latest}
WP_TESTS_DIR=/tmp/wordpress-tests-lib
WP_CORE_DIR=/tmp/wordpress

# Install SVN if missing
if ! command -v svn >/dev/null 2>&1; then
  echo "SVN not found. Please install it."
  exit 1
fi

# Download test library
mkdir -p "$WP_TESTS_DIR"
svn co --quiet https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/includes/ "$WP_TESTS_DIR/includes"
svn co --quiet https://develop.svn.wordpress.org/tags/${WP_VERSION}/tests/phpunit/data/ "$WP_TESTS_DIR/data"

# wp-tests-config.php
wget -nv -O "$WP_TESTS_DIR/wp-tests-config.php" https://develop.svn.wordpress.org/tags/${WP_VERSION}/wp-tests-config-sample.php
sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
sed -i "s|localhost|$DB_HOST|" "$WP_TESTS_DIR/wp-tests-config.php"

# Download WP Core
mkdir -p "$WP_CORE_DIR"
wget -nv -O /tmp/wordpress.tar.gz https://wordpress.org/wordpress-${WP_VERSION}.tar.gz
tar --strip-components=1 -zxmf /tmp/wordpress.tar.gz -C "$WP_CORE_DIR"

echo "WordPress and test library installed."
