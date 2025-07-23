<?php
$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Load WordPress test functions
require_once $_tests_dir . '/includes/functions.php';

// Manually load plugin
function _load_plugin() {
    require dirname(__DIR__) . '/wp-site-inspector.php'; // Adjust to your plugin’s main file
}
tests_add_filter('muplugins_loaded', '_load_plugin');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';
