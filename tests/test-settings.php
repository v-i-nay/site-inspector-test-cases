<?php

class WPSI_Settings_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_settings() {
        $settings = new WP_Site_Inspector_Settings();
        $this->assertInstanceOf('WP_Site_Inspector_Settings', $settings);
    }

    public function test_register_settings_does_not_throw() {
        $settings = new WP_Site_Inspector_Settings();
        $settings->register_settings();
        $this->assertTrue(true); // If no error, registration works
    }

    public function test_add_settings_submenu() {
        $settings = new WP_Site_Inspector_Settings();
        $settings->add_settings_submenu();
        $this->assertTrue(true); // If no error, submenu added
    }
} 