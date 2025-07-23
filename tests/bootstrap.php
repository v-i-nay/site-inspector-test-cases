<?php
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}

$_tests_dir = getenv('WP_TESTS_DIR');
if (!$_tests_dir) {
    $_tests_dir = '/tmp/wordpress-tests-lib';
}

// Automatically install the WP test suite if missing
if (!file_exists($_tests_dir . '/includes/functions.php')) {
    // Try to run the install script
    $script = dirname(__DIR__) . '/bin/install-wp-tests.sh';
    if (file_exists($script)) {
        $cmd = sprintf('bash %s wordpress_test root root 127.0.0.1 6.6', escapeshellarg($script));
        echo "\nWP test suite not found. Running: $cmd\n";
        system($cmd);
    } else {
        fwrite(STDERR, "\nERROR: WP test suite missing and install script not found at $script\n");
        exit(1);
    }
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