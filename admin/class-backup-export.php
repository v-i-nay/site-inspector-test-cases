<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly


class WPSI_Backup_Export {
    
    private $backup_dir;
    private $backup_file;
    private $temp_dir;
    
    public function __construct() {
        $this->backup_dir = WP_CONTENT_DIR . '/wpsi-backups/';
        $this->temp_dir = WP_CONTENT_DIR . '/wpsi-temp/';
        
        // Register export action
        add_action('admin_post_wpsi_export_backup', array($this, 'handle_export'));
        
        // Create directories if they don't exist
        $this->create_directories();
    }
    
    private function create_directories() {
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
        }
        
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
        }
    }
    
    public function handle_export() {
        // Verify nonce and capabilities
        if (!isset($_GET['_wpnonce']) || !wp_verify_nonce($_GET['_wpnonce'], 'wpsi_export_backup')) {
            wp_die(__('Security check failed', 'wp-site-inspector'));
        }
        
        if (!current_user_can('export')) {
            wp_die(__('You do not have sufficient permissions to export the site.', 'wp-site-inspector'));
        }
        
        // Generate unique filename
        $this->backup_file = 'wpsi-backup-' . date('Y-m-d-H-i-s') . '.wpsi';
        
        try {
            // Clean temp directory
            $this->clean_temp_dir();
            
            // Export database
            $this->export_database();
            
            // Export files
            $this->export_files();
            
            // Create final archive
            $this->create_final_archive();
            
            // Clean up
            $this->clean_temp_dir();
            
            // Send the file to browser
            $this->send_backup_to_browser();
            
        } catch (Exception $e) {
            wp_die(sprintf(__('Backup failed: %s', 'wp-site-inspector'), $e->getMessage()));
        }
    }
    
    private function clean_temp_dir() {
        $files = glob($this->temp_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
private function export_database() {
    global $wpdb;


    $sql_file = $this->temp_dir . 'database.sql';
    $handle = fopen($sql_file, 'w');


    if (!$handle) {
        throw new Exception(__('Could not create database export file', 'wp-site-inspector'));
    }


    // Write header information
    fwrite($handle, "-- WordPress Database Export\n");
    fwrite($handle, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
    fwrite($handle, "-- MySQL Server Version: " . $wpdb->db_version() . "\n\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n");
    fwrite($handle, "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n");
    fwrite($handle, "SET AUTOCOMMIT = 0;\n");
    fwrite($handle, "START TRANSACTION;\n\n");


    $tables = $wpdb->get_col('SHOW TABLES');


    foreach ($tables as $table) {
        if (0 !== strpos($table, $wpdb->prefix)) {
            continue;
        }


        // DROP TABLE
        fwrite($handle, "DROP TABLE IF EXISTS `$table`;\n");


        // CREATE TABLE
        $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        $create_sql = $create_table[1];


        // Fix common schema issues
        $create_sql = $this->fix_schema_sql($create_sql);
        fwrite($handle, $create_sql . ";\n\n");


        // Export data
        $this->export_table_data($handle, $table);
    }


    // Write footer information
    fwrite($handle, "COMMIT;\n");
    fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    fwrite($handle, "SET SQL_MODE = @OLD_SQL_MODE;\n");


    fclose($handle);
}


private function export_table_data($handle, $table) {
    global $wpdb;
    
    $rows = $wpdb->get_results("SELECT * FROM `$table`", ARRAY_A);
    if (empty($rows)) return;


    $columns = array_keys($rows[0]);
    fwrite($handle, "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n");


    $total_rows = count($rows);
    $i = 0;
    $batch_size = 100;
    $batch_count = 0;


    foreach ($rows as $row) {
        $escaped_values = array_map(function($value) use ($wpdb) {
            if (is_null($value)) return 'NULL';
            return "'" . $wpdb->_real_escape($value) . "'";
        }, $row);


        $values_line = '(' . implode(', ', $escaped_values) . ')';
        fwrite($handle, $values_line);


        $i++;
        $batch_count++;


        if ($i < $total_rows) {
            if ($batch_count >= $batch_size) {
                fwrite($handle, ";\n");
                fwrite($handle, "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n");
                $batch_count = 0;
            } else {
                fwrite($handle, ",\n");
            }
        } else {
            fwrite($handle, ";\n\n");
        }
    }
}


private function fix_schema_sql($sql) {
    // Fix unquoted DEFAULT Y/N/T/F values in schema
    $sql = preg_replace_callback(
        '/DEFAULT\s+([A-Z])(?=[,\)\s])/i',
        function ($m) {
            $val = strtoupper($m[1]);
            if (in_array($val, ['Y', 'N', 'T', 'F'])) {
                return "DEFAULT '" . $val . "'";
            }
            return $m[0];
        },
        $sql
    );


    // Fix 0000-00-00 dates
    $sql = preg_replace(
        "/DEFAULT '0000-00-00( 00:00:00)?'/",
        "DEFAULT '1970-01-01$1'",
        $sql
    );


    // Fix unquoted 0000-00-00 dates
    $sql = preg_replace(
        "/DEFAULT 0000-00-00( 00:00:00)?(?=[,\)\s])/",
        "DEFAULT '1970-01-01$1'",
        $sql
    );


    return $sql;
}
	
    private function export_files() {
        // Export wp-config.php
        if (file_exists(ABSPATH . 'wp-config.php')) {
            copy(ABSPATH . 'wp-config.php', $this->temp_dir . 'wp-config.php');
        }
        
        // Export .htaccess
        if (file_exists(ABSPATH . '.htaccess')) {
            copy(ABSPATH . '.htaccess', $this->temp_dir . '.htaccess');
        }
        
        // Export WordPress root files
        $this->copy_directory(ABSPATH, $this->temp_dir . 'wordpress/', array(
            'wp-content',
            'wp-config.php',
            '.htaccess'
        ));
        
        // Export wp-content (except our own backup directory)
        $this->copy_directory(WP_CONTENT_DIR, $this->temp_dir . 'wp-content/', array(
            basename($this->backup_dir),
            basename($this->temp_dir)
        ));
    }
    
    private function copy_directory($src, $dst, $exclude = array()) {
        if (!file_exists($dst)) {
            wp_mkdir_p($dst);
        }
        
        $dir = opendir($src);
        
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..') && !in_array($file, $exclude)) {
                if (is_dir($src . '/' . $file)) {
                    $this->copy_directory($src . '/' . $file, $dst . '/' . $file, $exclude);
                } else {
                    copy($src . '/' . $file, $dst . '/' . $file);
                }
            }
        }
        
        closedir($dir);
    }
    
    private function create_final_archive() {
        if (!class_exists('ZipArchive')) {
            throw new Exception(__('ZipArchive class is not available. Please enable it to create backups.', 'wp-site-inspector'));
        }
        
        $zip = new ZipArchive();
        $zip_path = $this->backup_dir . $this->backup_file;
        
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
            throw new Exception(__('Could not create ZIP archive', 'wp-site-inspector'));
        }
        
        // Add files from temp directory to zip
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->temp_dir),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        
        foreach ($files as $name => $file) {
            if (!$file->isDir()) {
                $file_path = $file->getRealPath();
                $relative_path = substr($file_path, strlen($this->temp_dir));
                
                $zip->addFile($file_path, $relative_path);
            }
        }
        
        $zip->close();
    }
    
    private function send_backup_to_browser() {
        $file_path = $this->backup_dir . $this->backup_file;
        
        if (!file_exists($file_path)) {
            wp_die(__('Backup file not found', 'wp-site-inspector'));
        }
        
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename=' . basename($file_path));
        header('Content-Transfer-Encoding: binary');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($file_path));
        
        ob_clean();
        flush();
        readfile($file_path);
        
        exit;
    }
}


new WPSI_Backup_Export();
