<?php

class WPSI_Email_Handler_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_email_handler() {
        $handler = new WP_Site_Inspector_Email_Handler();
        $this->assertInstanceOf('WP_Site_Inspector_Email_Handler', $handler);
    }
} 