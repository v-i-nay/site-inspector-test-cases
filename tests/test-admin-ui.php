<?php

class WPSI_Admin_UI_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_admin_ui() {
        $ui = new WP_Site_Inspector_Admin_UI();
        $this->assertInstanceOf('WP_Site_Inspector_Admin_UI', $ui);
    }

    public function test_register_menu_adds_menu() {
        $ui = new WP_Site_Inspector_Admin_UI();
        $ui->register_menu();
        global $menu;
        $found = false;
        foreach ($menu as $item) {
            if (isset($item[2]) && $item[2] === 'wp-site-inspector') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Site Inspector menu not registered');
    }
} 