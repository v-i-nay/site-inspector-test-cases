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
    }
} 