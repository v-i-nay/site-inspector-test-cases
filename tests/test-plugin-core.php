<?php

class WPSI_Core_Tests extends WP_UnitTestCase {

    public function test_plugin_loaded() {
        $this->assertTrue( function_exists('add_action') ); // WordPress core loaded
    }

    public function test_debug_toggle_enabled() {
        update_option('wpsi_enable_debug_log', '1');

        // Re-require plugin manually to re-trigger the debug logic
        require dirname(__DIR__) . '/wp-site-inspector.php';

        $this->assertTrue( defined('WP_DEBUG') && WP_DEBUG );
        $this->assertTrue( defined('WP_DEBUG_LOG') && WP_DEBUG_LOG );
        $this->assertTrue( defined('WP_DEBUG_DISPLAY') && WP_DEBUG_DISPLAY === false );
    }

    public function test_default_options_created() {
        // Trigger admin_init
        do_action('admin_init');

        $this->assertNotEmpty( get_option('wpsi_error_threshold') );
        $this->assertNotEmpty( get_option('wpsi_alert_emails') );
        $this->assertTrue( is_email( get_option('wpsi_alert_emails') ) );
    }

    public function test_ajax_callbacks_registered() {
        global $wp_filter;

        $this->assertArrayHasKey('wp_ajax_wpsi_load_tab_content', $wp_filter);
        $this->assertArrayHasKey('wp_ajax_wpsi_load_page', $wp_filter);
        $this->assertArrayHasKey('wp_ajax_wpsi_ask_ai', $wp_filter);
    }

    public function test_ai_chat_response_mock() {
        // Simulate a POST request
        $_POST['nonce'] = wp_create_nonce('wpsi_ajax_nonce');
        $_POST['message'] = 'hello test';

        // Start output buffering to catch `wp_send_json_success`
        ob_start();
        wpsi_handle_ai_chat();
        $output = ob_get_clean();

        $data = json_decode($output, true);
        $this->assertEquals('success', $data['success']);
        $this->assertStringContainsString('I received your message', $data['data']['response']);
    }
}
