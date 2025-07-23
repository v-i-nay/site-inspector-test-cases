#!/usr/bin/env bash

# Exit if any command fails
set -e

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}

# Paths
WP_CORE_DIR="/tmp/wordpress/"
WP_TESTS_DIR="${WP_CORE_DIR}tests/phpunit"
WP_CORE_DOWNLOAD_URL="https://wordpress.org/wordpress-${WP_VERSION}.tar.gz"

# Download WordPress if not exists
if [ ! -d "$WP_CORE_DIR" ]; then
  mkdir -p "$WP_CORE_DIR"
  wget -nv -O /tmp/wordpress.tar.gz "$WP_CORE_DOWNLOAD_URL"
  tar -xzf /tmp/wordpress.tar.gz --strip-components=1 -C "$WP_CORE_DIR"
fi

# Download the test suite
if [ ! -d "$WP_TESTS_DIR" ]; then
  mkdir -p "$WP_TESTS_DIR"
  svn co --quiet https://develop.svn.wordpress.org/tags/$WP_VERSION/tests/phpunit "$WP_TESTS_DIR"
fi

# Create wp-tests-config.php
cat > "$WP_CORE_DIR"/wp-tests-config.php <<EOL
<?php
define( 'DB_NAME', '$DB_NAME' );
define( 'DB_USER', '$DB_USER' );
define( 'DB_PASSWORD', '$DB_PASS' );
define( 'DB_HOST', '$DB_HOST' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'Test Blog' );
define( 'WP_PHP_BINARY', 'php' );
define( 'WPLANG', '' );
define( 'WP_DEBUG', true );
EOL

# Create test DB
mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" -f || true
