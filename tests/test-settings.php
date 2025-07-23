<?php

class WPSI_Settings_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_settings() {
        $settings = new WP_Site_Inspector_Settings();
        $this->assertInstanceOf('WP_Site_Inspector_Settings', $settings);
    }

    public function test_register_settings() {
        $settings = new WP_Site_Inspector_Settings();
        $settings->register_settings();
        $this->assertTrue(true); // If no error, registration works
    }
} 