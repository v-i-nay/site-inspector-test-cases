<?php
/**
 * Test cases for WPSI_Restore functionality
 */
class TestWPSIRestore extends WP_UnitTestCase {
    
    private $restore;
    private $backup_dir;
    private $temp_dir;
    
    public function setUp(): void {
        parent::setUp();
        
        // Initialize the restore class
        $this->restore = new WPSI_Restore();
        
        // Get directories using reflection since they're private
        $reflection = new ReflectionClass($this->restore);
        
        $backup_dir_prop = $reflection->getProperty('backup_dir');
        $backup_dir_prop->setAccessible(true);
        $this->backup_dir = $backup_dir_prop->getValue($this->restore);
        
        $temp_dir_prop = $reflection->getProperty('temp_dir');
        $temp_dir_prop->setAccessible(true);
        $this->temp_dir = $temp_dir_prop->getValue($this->restore);
        
        // Debug output
        error_log("Backup directory in tests: " . $this->backup_dir);
        error_log("Temp directory in tests: " . $this->temp_dir);
        
        // Create test admin user
        $this->admin_id = $this->factory->user->create(['role' => 'administrator']);
        wp_set_current_user($this->admin_id);
    }
    
    public function tearDown(): void {
        parent::tearDown();
        
        // Clean up directories
        $this->recursive_rmdir($this->backup_dir);
        $this->recursive_rmdir($this->temp_dir);
    }
    
    private function recursive_rmdir($dir) {
        if (!file_exists($dir)) return;
        
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = "$dir/$file";
            is_dir($path) ? $this->recursive_rmdir($path) : unlink($path);
        }
        @rmdir($dir);
    }
    
    // Test directory detection and creation
    public function test_directory_handling() {
        // First verify the directories exist
        $this->assertDirectoryExists($this->backup_dir, "Backup directory should exist");
        $this->assertDirectoryExists($this->temp_dir, "Temp directory should exist");
        
        // Check directory permissions
        $this->assertTrue(is_writable($this->backup_dir), "Backup directory should be writable");
        $this->assertTrue(is_writable($this->temp_dir), "Temp directory should be writable");
        
        // Verify .htaccess files - skip if running on Windows
        // if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        //     $this->assertFileExists($this->backup_dir . '.htaccess', 
        //         ".htaccess file should exist in backup directory");
        //     $this->assertFileExists($this->temp_dir . '.htaccess',
        //         ".htaccess file should exist in temp directory");
            
        //     // Verify .htaccess content
        //     $backup_htaccess = file_get_contents($this->backup_dir . '.htaccess');
        //     $this->assertEquals('deny from all', trim($backup_htaccess),
        //         ".htaccess should contain 'deny from all'");
        // }
    }
    
    // Test AJAX handler registration
    public function test_ajax_handler_registration() {
        global $wp_filter;
        
        $this->assertArrayHasKey('wp_ajax_wpsi_list_backups', $wp_filter);
        $this->assertArrayHasKey('wp_ajax_wpsi_restore_backup', $wp_filter);
        $this->assertArrayHasKey('wp_ajax_wpsi_delete_backup', $wp_filter);
        $this->assertArrayHasKey('wp_ajax_wpsi_chunked_upload', $wp_filter);
    }
    
    // Test backup listing functionality
    public function test_list_backups() {
        // Create a test backup file
        $test_file = $this->backup_dir . 'test_backup.wpsi';
        file_put_contents($test_file, 'test content');
        
        // Simulate AJAX request
        $_POST['nonce'] = wp_create_nonce('wpsi_restore_backup');
        
        try {
            ob_start();
            $this->restore->list_backups();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertTrue($response['success']);
            $this->assertArrayHasKey('backups', $response['data']);
            $this->assertCount(1, $response['data']['backups']);
            $this->assertEquals('test_backup.wpsi', $response['data']['backups'][0]['name']);
        } catch (WPDieException $e) {
            $this->fail('Unexpected WPDieException');
        }
    }
    
    // Test backup listing with no permissions
    public function test_list_backups_no_permission() {
        wp_set_current_user($this->editor_id);
        
        $_POST['nonce'] = wp_create_nonce('wpsi_restore_backup');
        
        try {
            ob_start();
            $this->restore->list_backups();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertFalse($response['success']);
            $this->assertStringContainsString('permission', $response['data']);
        } catch (WPDieException $e) {
            $this->fail('Unexpected WPDieException');
        }
    }
    
    // Test chunked file upload
    public function test_chunked_upload() {
        $_POST['wpsi_restore_nonce'] = wp_create_nonce('wpsi_restore_backup');
        $_POST['chunk'] = 0;
        $_POST['chunks'] = 1;
        $_POST['name'] = 'test_upload.wpsi';
        
        // Create a test file chunk
        $tmp_file = tempnam(sys_get_temp_dir(), 'wpsi');
        file_put_contents($tmp_file, 'test chunk content');
        $_FILES = [
            'file' => [
                'tmp_name' => $tmp_file,
                'name' => 'test_chunk',
                'type' => 'application/octet-stream',
                'error' => 0,
                'size' => filesize($tmp_file)
            ]
        ];
        
        try {
            ob_start();
            $this->restore->handle_chunked_upload();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertTrue($response['success']);
            $this->assertTrue($response['data']['complete']);
            $this->assertFileExists($response['data']['backup_file']);
        } catch (WPDieException $e) {
            $this->fail('Unexpected WPDieException');
        } finally {
            @unlink($tmp_file);
        }
    }
    
    // Test full restore process with mock backup
    public function test_full_restore_process() {
        // Create a mock backup file structure
        $mock_backup = $this->temp_dir . 'mock_backup.wpsi';
        $extract_dir = $this->temp_dir . 'mock_extract/';
        
        // Create a mock ZIP file with database and files
        $zip = new ZipArchive();
        $zip->open($mock_backup, ZipArchive::CREATE);
        
        // Add mock database
        $db_content = "-- WordPress database dump\n" .
                      "INSERT INTO `wp_options` VALUES (1, 'siteurl', 'http://old-site.com', 'yes');\n" .
                      "INSERT INTO `wp_options` VALUES (2, 'home', 'http://old-site.com', 'yes');\n" .
                      "INSERT INTO `wp_posts` VALUES (1, 1, '2023-01-01 00:00:00', '2023-01-01 00:00:00', 'Test post', 'test-post', 'publish', 'open', 'open', '', 'test-post', '', '', '2023-01-01 00:00:00', '2023-01-01 00:00:00', '', 0, 'http://old-site.com/?p=1', 0, 'post', '', 0);";
        
        $zip->addFromString('database.sql', $db_content);
        
        // Add mock files
        $zip->addEmptyDir('files/wp-content/plugins/test-plugin/');
        $zip->addFromString('files/wp-content/plugins/test-plugin/test-plugin.php', '<?php /* Test Plugin */');
        $zip->close();
        
        // Test database restore
        $_POST['nonce'] = wp_create_nonce('wpsi_restore_backup');
        $_POST['backup_file'] = $mock_backup;
        $_POST['step'] = 'restore_database';
        
        try {
            ob_start();
            $this->restore->restore_backup();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertTrue($response['success']);
            $this->assertEquals('restore_files', $response['data']['next_step']);
            
            // Verify URL replacement
            $site_url = get_option('siteurl');
            $this->assertNotEquals('http://old-site.com', $site_url);
            
            // Test files restore
            $_POST['step'] = 'restore_files';
            $_POST['extract_dir'] = $response['data']['extract_dir'];
            
            ob_start();
            $this->restore->restore_backup();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertTrue($response['success']);
            $this->assertEquals('finalize', $response['data']['next_step']);
            
            // Verify plugin was restored
            $this->assertFileExists(WP_PLUGIN_DIR . '/test-plugin/test-plugin.php');
            
            // Test finalize
            $_POST['step'] = 'finalize';
            $_POST['extract_dir'] = $response['data']['extract_dir'];
            
            ob_start();
            $this->restore->restore_backup();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertTrue($response['success']);
            $this->assertTrue($response['data']['complete']);
            $this->assertDirectoryDoesNotExist($extract_dir);
        } catch (WPDieException $e) {
            $this->fail('Unexpected WPDieException');
        }
    }
    
    // Test database restore with URL replacement
    public function test_database_restore_with_url_replacement() {
        global $wpdb;
        
        // Create a mock SQL file with old URLs
        $sql_file = $this->temp_dir . 'test_db.sql';
        $old_url = 'http://old-site.com';
        $new_url = get_site_url();
        
        $content = "INSERT INTO `wp_options` VALUES (1, 'siteurl', '$old_url', 'yes');\n" .
                   "INSERT INTO `wp_options` VALUES (2, 'home', '$old_url', 'yes');\n" .
                   "INSERT INTO `wp_posts` VALUES (1, 1, NOW(), NOW(), 'Test post', 'test-post', 'publish', 'open', 'open', '', 'test-post', '', '', NOW(), NOW(), '', 0, '$old_url/?p=1', 0, 'post', '', 0);\n" .
                   "INSERT INTO `wp_postmeta` VALUES (1, 1, '_thumbnail_id', 'a:1:{s:4:\"file\";s:20:\"2023/01/test-img.jpg\"}');";
        
        file_put_contents($sql_file, $content);
        
        // Use reflection to call private method
        $method = new ReflectionMethod($this->restore, 'restore_database');
        $method->setAccessible(true);
        
        $extract_dir = $this->temp_dir . 'test_extract/';
        wp_mkdir_p($extract_dir);
        
        try {
            $method->invoke($this->restore, $extract_dir);
            
            // Verify URLs were replaced
            $site_url = get_option('siteurl');
            $this->assertNotEquals($old_url, $site_url);
            $this->assertEquals($new_url, $site_url);
            
            // Verify post GUID was updated
            $post = $wpdb->get_row("SELECT * FROM {$wpdb->posts} WHERE ID = 1");
            $this->assertStringContainsString($new_url, $post->guid);
            
            // Verify serialized data was fixed
            $meta = $wpdb->get_row("SELECT * FROM {$wpdb->postmeta} WHERE meta_id = 1");
            $unserialized = maybe_unserialize($meta->meta_value);
            $this->assertIsArray($unserialized);
        } finally {
            // Clean up
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_id IN (1, 2)");
            $wpdb->query("DELETE FROM {$wpdb->posts} WHERE ID = 1");
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_id = 1");
            $this->recursive_rmdir($extract_dir);
        }
    }
    
    // Test media file registration
    public function test_media_file_registration() {
        // Create a test image in uploads
        $uploads_dir = wp_upload_dir();
        $test_image = $uploads_dir['path'] . '/test-image.jpg';
        file_put_contents($test_image, 'test image content');
        
        // Use reflection to call private method
        $method = new ReflectionMethod($this->restore, 'register_single_media_file');
        $method->setAccessible(true);
        
        $attachment_id = $method->invoke($this->restore, $test_image);
        
        $this->assertIsNumeric($attachment_id);
        $this->assertGreaterThan(0, $attachment_id);
        
        // Verify attachment exists
        $attachment = get_post($attachment_id);
        $this->assertNotNull($attachment);
        $this->assertEquals('attachment', $attachment->post_type);
        
        // Clean up
        wp_delete_attachment($attachment_id, true);
    }
    
    // Test backup deletion
    public function test_delete_backup() {
        // Create a test backup file
        $test_file = $this->backup_dir . 'test_delete.wpsi';
        file_put_contents($test_file, 'test content');
        
        // Simulate AJAX request
        $_POST['nonce'] = wp_create_nonce('wpsi_restore_backup');
        $_POST['backup_file'] = $test_file;
        
        try {
            ob_start();
            $this->restore->delete_backup();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertTrue($response['success']);
            $this->assertFileDoesNotExist($test_file);
        } catch (WPDieException $e) {
            $this->fail('Unexpected WPDieException');
        }
    }
    
    // Test invalid backup file deletion
    public function test_delete_invalid_backup_path() {
        // Try to delete a file outside backup directory
        $test_file = ABSPATH . 'wp-config.php';
        
        $_POST['nonce'] = wp_create_nonce('wpsi_restore_backup');
        $_POST['backup_file'] = $test_file;
        
        try {
            ob_start();
            $this->restore->delete_backup();
            $output = ob_get_clean();
            $response = json_decode($output, true);
            
            $this->assertFalse($response['success']);
            $this->assertFileExists($test_file);
        } catch (WPDieException $e) {
            $this->fail('Unexpected WPDieException');
        }
    }
    
    // Test SQL query fixing
    public function test_sql_query_fixing() {
        $method = new ReflectionMethod($this->restore, 'fix_import_query');
        $method->setAccessible(true);
        
        $queries = [
            // Fix unquoted DEFAULT values
            "CREATE TABLE test (active ENUM('Y','N') DEFAULT N" => "CREATE TABLE test (active ENUM('Y','N') DEFAULT 'N'",
            
            // Fix 0000-00-00 dates
            "INSERT INTO test VALUES ('0000-00-00')" => "INSERT INTO test VALUES ('1970-01-01')",
            
            // Fix unquoted dates in VALUES
            "VALUES (1, 2023-01-01, 'test')" => "VALUES (1, '2023-01-01', 'test')",
            
            // Fix boolean values in INSERT
            "INSERT INTO test VALUES (1, Y, N, T, F)" => "INSERT INTO test VALUES (1, 'Y', 'N', 'T', 'F')"
        ];
        
        foreach ($queries as $input => $expected) {
            $fixed = $method->invoke($this->restore, $input);
            $this->assertEquals($expected, $fixed);
        }
    }
}