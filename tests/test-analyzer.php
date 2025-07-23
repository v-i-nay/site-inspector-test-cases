<?php

class WPSI_Analyzer_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_analyzer() {
        $analyzer = new WP_Site_Inspector_Analyzer();
        $this->assertInstanceOf('WP_Site_Inspector_Analyzer', $analyzer);
    }

    public function test_analyze_tab_theme_returns_array() {
        $analyzer = new WP_Site_Inspector_Analyzer();
        $result = $analyzer->analyze_tab('theme');
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
    }

    public function test_analyze_tab_plugins_returns_array() {
        $analyzer = new WP_Site_Inspector_Analyzer();
        $result = $analyzer->analyze_tab('plugins');
        $this->assertIsArray($result);
    }

    public function test_analyze_tab_invalid_returns_false() {
        $analyzer = new WP_Site_Inspector_Analyzer();
        $result = $analyzer->analyze_tab('not-a-real-tab');
        $this->assertFalse($result);
    }
} 