<?php

class WPSI_Backup_Export_Tests extends WP_UnitTestCase {
    public function test_can_instantiate_backup_export() {
        $backup = new WPSI_Backup_Export();
        $this->assertInstanceOf('WPSI_Backup_Export', $backup);
    }

    public function test_backup_directories_created() {
        $backup = new WPSI_Backup_Export();
        $this->assertDirectoryExists(WP_CONTENT_DIR . '/wpsi-backups/');
        $this->assertDirectoryExists(WP_CONTENT_DIR . '/wpsi-temp/');
    }
} 