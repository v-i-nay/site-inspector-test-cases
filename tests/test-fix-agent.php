<?php

class WPSI_Fix_Agent_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_fix_agent() {
        $agent = new WP_Site_Inspector_Fix_Agent();
        $this->assertInstanceOf('WP_Site_Inspector_Fix_Agent', $agent);
    }

    public function test_class_exists() {
        $this->assertTrue(class_exists('WP_Site_Inspector_Fix_Agent'));
    }
} 