<?php
$_tests_dir = getenv('WP_TESTS_DIR');

if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Load WordPress test functions
require_once $_tests_dir . '/includes/functions.php';

// Manually load plugin
function _load_plugin() {
    $plugin_file = dirname(__DIR__) . '/wp-site-inspector.php';
    if (!file_exists($plugin_file)) {
        fwrite(STDERR, "\nERROR: Plugin main file not found at $plugin_file\n");
        exit(1);
    }
    require $plugin_file;
}
tests_add_filter('muplugins_loaded', '_load_plugin');

// Start up the WP testing environment
require $_tests_dir . '/includes/bootstrap.php';
