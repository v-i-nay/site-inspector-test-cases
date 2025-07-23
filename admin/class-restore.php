<!-- /WP-Site-Inspector-Agent-main/admin/class-restore.php -->
<?php
if (!defined('ABSPATH')) exit;


class WPSI_Restore {
    private $backup_dir;
    private $temp_dir;
    private $chunk_size = 16 * 1024 * 1024; // 16MB chunks
    private $max_execution_time = 60;
    private $file_chunk_size = 4 * 1024 * 1024; // 4MB chunks for file operations
    
    public function __construct() {
        $this->detect_backup_directory();
        $this->temp_dir = WP_CONTENT_DIR . '/wpsi-temp/';
        $this->create_directories();
        $this->register_ajax_handlers();
    }
    
    private function detect_backup_directory() {
        $standard_path = WP_CONTENT_DIR . '/wpsi-backups/';
        $subdir_path = ABSPATH . 'wordpress/wp-content/wpsi-backups/';
        $parent_path = dirname(WP_CONTENT_DIR) . '/wpsi-backups/';
        
        if (file_exists($subdir_path)) {
            $this->backup_dir = $subdir_path;
        } elseif (file_exists($parent_path)) {
            $this->backup_dir = $parent_path;
        } else {
            $this->backup_dir = $standard_path;
        }
        
        error_log('WPSI Backup Directory: ' . $this->backup_dir);
    }
    
    private function create_directories() {
        if (!file_exists($this->backup_dir)) {
            wp_mkdir_p($this->backup_dir);
            file_put_contents($this->backup_dir . '.htaccess', 'deny from all');
        }
        
        if (!file_exists($this->temp_dir)) {
            wp_mkdir_p($this->temp_dir);
            file_put_contents($this->temp_dir . '.htaccess', 'deny from all');
        }
    }


    private function register_ajax_handlers() {
        add_action('wp_ajax_wpsi_list_backups', [$this, 'list_backups']);
        add_action('wp_ajax_wpsi_restore_backup', [$this, 'restore_backup']);
        add_action('wp_ajax_wpsi_delete_backup', [$this, 'delete_backup']);
        add_action('wp_ajax_wpsi_chunked_upload', [$this, 'handle_chunked_upload']);
    }
    
    public function list_backups() {
        check_ajax_referer('wpsi_restore_backup', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to restore backups.', 'wp-site-inspector'));
        }
        
        $backups = [];
        
        if (file_exists($this->backup_dir)) {
            $files = scandir($this->backup_dir, SCANDIR_SORT_DESCENDING);
            
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === 'index.php') {
                    continue;
                }
                
                $file_path = $this->backup_dir . $file;
                $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                
                if (in_array($file_ext, ['zip', 'wpsi']) && is_file($file_path)) {
                    $backups[] = [
                        'name' => $file,
                        'path' => $file_path,
                        'size' => size_format(filesize($file_path)),
                        'date' => date_i18n(get_option('date_format') . ' ' . get_option('time_format'), filemtime($file_path)),
                    ];
                }
            }
        }
        
        wp_send_json_success([
            'backups' => $backups,
            'backup_dir' => $this->backup_dir
        ]);
    }
    
    public function handle_chunked_upload() {
        try {
            if (!check_ajax_referer('wpsi_restore_backup', 'wpsi_restore_nonce', false)) {
                throw new Exception(__('Security check failed. Please refresh the page and try again.', 'wp-site-inspector'));
            }
            
            if (!current_user_can('manage_options')) {
                throw new Exception(__('Permission denied', 'wp-site-inspector'));
            }


            @ini_set('memory_limit', '512M');
            @set_time_limit($this->max_execution_time + 30);


            $chunk = isset($_POST['chunk']) ? (int)$_POST['chunk'] : 0;
            $chunks = isset($_POST['chunks']) ? (int)$_POST['chunks'] : 0;
            $file_name = isset($_POST['name']) ? sanitize_file_name($_POST['name']) : 'backup_' . time() . '.wpsi';
            $final_path = $this->temp_dir . $file_name;


            if (!is_writable($this->temp_dir)) {
                throw new Exception(__('Temporary directory is not writable.', 'wp-site-inspector'));
            }


            if (empty($_FILES['file'])) {
                throw new Exception(__('No file chunk received', 'wp-site-inspector'));
            }


            $tmp_name = $_FILES['file']['tmp_name'];


            $out = @fopen($final_path, $chunk == 0 ? 'wb' : 'ab');
            if (!$out) {
                throw new Exception(__('Failed to open output stream', 'wp-site-inspector'));
            }


            $in = @fopen($tmp_name, 'rb');
            if (!$in) {
                fclose($out);
                throw new Exception(__('Failed to open input stream', 'wp-site-inspector'));
            }


            while ($buff = fread($in, $this->file_chunk_size)) {
                fwrite($out, $buff);
            }


            fclose($in);
            fclose($out);
            @unlink($tmp_name);


            $progress = $chunks > 0 ? round(($chunk + 1) / $chunks * 100) : 100;


            if ($chunk == $chunks - 1 || $chunks == 0) {
                wp_send_json_success([
                    'complete' => true,
                    'backup_file' => $final_path,
                    'next_step' => 'restore_database',
                    'progress' => $progress,
                    'message' => __('File upload complete. Starting restore...', 'wp-site-inspector')
                ]);
            } else {
                wp_send_json_success([
                    'complete' => false,
                    'next_action' => 'upload_chunk',
                    'chunk' => $chunk + 1,
                    'progress' => $progress,
                    'message' => sprintf(__('Uploading chunk %d of %d...', 'wp-site-inspector'), $chunk + 1, $chunks)
                ]);
            }
        } catch (Exception $e) {
            error_log('WPSI Restore Error: ' . $e->getMessage());
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function restore_backup() {
        check_ajax_referer('wpsi_restore_backup', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to restore backups.', 'wp-site-inspector'));
        }
        
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field($_POST['backup_file']) : '';
        
        if (empty($backup_file) || !file_exists($backup_file)) {
            wp_send_json_error(__('Backup file not found.', 'wp-site-inspector'));
        }
        
        $file_ext = strtolower(pathinfo($backup_file, PATHINFO_EXTENSION));
        if (!in_array($file_ext, ['zip', 'wpsi'])) {
            wp_send_json_error(__('Invalid file type. Only .zip and .wpsi files are allowed.', 'wp-site-inspector'));
        }
        
        $extract_dir = $this->temp_dir . 'restore-' . current_time('Ymd-His');
        
        if (!wp_mkdir_p($extract_dir)) {
            wp_send_json_error(__('Failed to create extraction directory.', 'wp-site-inspector'));
        }
        
        if (!class_exists('ZipArchive')) {
            wp_send_json_error(__('Your server does not support ZipArchive.', 'wp-site-inspector'));
        }
        
        $zip = new ZipArchive;
        if ($zip->open($backup_file) !== true) {
            wp_send_json_error(__('Failed to open backup file.', 'wp-site-inspector'));
        }
        
        if (!$zip->extractTo($extract_dir)) {
            $zip->close();
            wp_send_json_error(__('Failed to extract backup file.', 'wp-site-inspector'));
        }
        
        $zip->close();
        
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'restore_database';
        
        switch ($step) {
            case 'restore_database':
                $this->restore_database($extract_dir);
                break;
                
            case 'restore_files':
                $this->restore_files($extract_dir);
                break;
                
            case 'finalize':
                $this->finalize_restore($extract_dir);
                break;
                
            default:
                wp_send_json_error(__('Invalid restoration step.', 'wp-site-inspector'));
        }
    }
    
private function restore_database($extract_dir) {
    $sql_file = trailingslashit($extract_dir) . 'database.sql';
    
    if (!file_exists($sql_file)) {
        wp_send_json_error(__('Database backup file not found in archive.', 'wp-site-inspector'));
    }
    
    global $wpdb;
    
    // Initialize all MySQL session variables
    $wpdb->query("SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT");
    $wpdb->query("SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS");
    $wpdb->query("SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION");
    $wpdb->query("SET NAMES utf8mb4");
    $wpdb->query("SET @OLD_TIME_ZONE=@@TIME_ZONE");
    $wpdb->query("SET TIME_ZONE='+00:00'");
    $wpdb->query("SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0");
    $wpdb->query("SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0");
    $wpdb->query("SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO'");
    $wpdb->query("SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0");
    
    $wpdb->query('START TRANSACTION');
    
    try {
        // First pass: Import the database as-is
        $this->import_sql_file($sql_file);
        
        // Get URLs for replacement
        $old_site_url = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'siteurl'");
        $new_site_url = get_site_url();
        $old_home_url = $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = 'home'");
        $new_home_url = get_home_url();
        
        // Second pass: Update all URLs in the database
        if ($old_site_url && $old_site_url != $new_site_url) {
            $this->replace_urls_in_database($old_site_url, $new_site_url);
            
            // Also replace home URL if different
            if ($old_home_url && $old_home_url != $new_home_url && $old_home_url != $old_site_url) {
                $this->replace_urls_in_database($old_home_url, $new_home_url);
            }
            
            // Replace any remaining localhost references
            if (strpos($old_site_url, 'localhost') !== false) {
                $this->replace_urls_in_database('localhost', parse_url($new_site_url, PHP_URL_HOST));
            }
        }
        
        // Third pass: Fix serialized data
        $this->fix_serialized_data();
        
        // Update GUIDs in posts table
        $this->update_post_guids($old_site_url, $new_site_url);
        
        // Regenerate attachment metadata after URL replacements
        $this->regenerate_attachment_metadata();


        $wpdb->query('COMMIT');
        
        // Restore original MySQL settings
        $wpdb->query("SET TIME_ZONE=@OLD_TIME_ZONE");
        $wpdb->query("SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS");
        $wpdb->query("SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS");
        $wpdb->query("SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT");
        $wpdb->query("SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS");
        $wpdb->query("SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION");
        $wpdb->query("SET SQL_MODE=@OLD_SQL_MODE");
        $wpdb->query("SET SQL_NOTES=@OLD_SQL_NOTES");
        
        wp_send_json_success([
            'message' => __('Database restored successfully with all URL replacements.', 'wp-site-inspector'),
            'next_step' => 'restore_files',
            'extract_dir' => $extract_dir,
            'progress' => 50
        ]);
        
    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');
        wp_send_json_error($e->getMessage());
    }
}


private function restore_uploads_folder($extract_dir) {
    WP_Filesystem();
    
    $uploads_src = trailingslashit($extract_dir) . 'wp-content/uploads/';
    $uploads_dst = wp_get_upload_dir()['basedir'];
    
    if (is_dir($uploads_src)) {
        // Copy all files first
        copy_dir($uploads_src, $uploads_dst);
        
        // Process all media files for WordPress registration
        $this->process_media_files($uploads_dst);
        
        // Fix file permissions
        $this->fix_file_permissions($uploads_dst);
        
        // Force media library refresh
        $this->force_media_library_refresh();
        
        // Immediate thumbnail regeneration (no waiting)
        $this->regenerate_thumbnails_cron_action();
    }
}


private function regenerate_thumbnails_cron_action() {
    $attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'post_status' => 'inherit'
    ]);
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    foreach ($attachments as $attachment) {
        $file = get_attached_file($attachment->ID);
        if ($file && file_exists($file) && wp_attachment_is_image($attachment->ID)) {
            $metadata = wp_generate_attachment_metadata($attachment->ID, $file);
            wp_update_attachment_metadata($attachment->ID, $metadata);
        }
    }
}


// Add this to your class constructor or initialization function
// add_action('regenerate_thumbnails_cron', [$this, 'regenerate_thumbnails_cron_action']);


private function import_sql_file($sql_file) {
    global $wpdb;
    
    $handle = fopen($sql_file, 'r');
    if (!$handle) {
        throw new Exception(__('Could not open database file for reading.', 'wp-site-inspector'));
    }


    $current_query = '';
    
    while (($line = fgets($handle)) !== false) {
        // Skip comments and empty lines
        $trimmed = trim($line);
        if (empty($trimmed) || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
            continue;
        }
        
        $current_query .= $line;
        
        // Check for complete query
        if (substr(rtrim($current_query), -1) === ';') {
            $query = trim($current_query);
            $current_query = '';
            
            // Skip session variable settings
            if (preg_match('/^SET\s+(@|SESSION|GLOBAL)\s+/i', $query)) {
                continue;
            }
            
            // Skip empty queries and comments
            if (empty($query) || strpos($query, '/*!') === 0) {
                continue;
            }
            
            // Fix common SQL issues
            $query = $this->fix_import_query($query);
            
            $result = $wpdb->query($query);
            if ($result === false) {
                fclose($handle);
                throw new Exception(sprintf(
                    __('Database query failed: %s', 'wp-site-inspector'), 
                    $wpdb->last_error
                ));
            }
        }
    }
    
    fclose($handle);
}


private function register_single_media_file($filepath) {
    // Get upload directory info
    $upload_dir = wp_get_upload_dir();
    $uploads_basedir = $upload_dir['basedir'];
    $uploads_baseurl = $upload_dir['baseurl'];
    
    // Normalize path separators
    $filepath = str_replace('\\', '/', $filepath);
    $uploads_basedir = str_replace('\\', '/', $uploads_basedir);
    
    // Calculate relative path from uploads directory
    $relative_path = str_replace($uploads_basedir, '', $filepath);
    $relative_path = ltrim($relative_path, '/');
    
    // Create the attachment URL
    $attachment_url = $uploads_baseurl . '/' . $relative_path;
    
    // Check if file is already registered by URL or file path
    $existing_id = attachment_url_to_postid($attachment_url);
    if (!$existing_id) {
        // Try to find by file path
        $existing_id = $this->find_attachment_by_filepath($relative_path);
    }
    
    if ($existing_id) {
        // Update existing attachment metadata
        $filetype = wp_check_filetype($filepath);
        wp_update_post([
            'ID' => $existing_id,
            'post_mime_type' => $filetype['type'],
            'guid' => $attachment_url
        ]);
        
        // Regenerate metadata
        if (wp_attachment_is_image($existing_id)) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            $attach_data = wp_generate_attachment_metadata($existing_id, $filepath);
            wp_update_attachment_metadata($existing_id, $attach_data);
        }
        
        return $existing_id;
    }
    
    // Verify file exists
    if (!file_exists($filepath)) {
        return false;
    }
    
    // Get file info
    $filetype = wp_check_filetype($filepath);
    $filename = basename($filepath);
    
    // Skip thumbnails - they'll be regenerated
    if (preg_match('/-\d+x\d+\./', $filename)) {
        return false;
    }
    
    // Create attachment array
    $attachment = array(
        'guid' => $attachment_url,
        'post_mime_type' => $filetype['type'],
        'post_title' => preg_replace('/\.[^.]+$/', '', $filename),
        'post_content' => '',
        'post_status' => 'inherit'
    );
    
    // Insert the attachment
    $attach_id = wp_insert_attachment($attachment, $filepath);
    
    if (is_wp_error($attach_id)) {
        return false;
    }
    
    // Generate metadata for images
    if (wp_attachment_is_image($attach_id)) {
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attach_data = wp_generate_attachment_metadata($attach_id, $filepath);
        wp_update_attachment_metadata($attach_id, $attach_data);
    }
	
	
	// AFTER inserting attachment, add these lines:
    if ($attach_id && !is_wp_error($attach_id)) {
        // Force attachment metadata refresh
        clean_attachment_cache($attach_id);
        
        // Update the attachment counts
        wp_update_attachment_counts();
        
        // Add this to debug missing files
        error_log("Registered attachment ID {$attach_id} for file: " . basename($filepath));
    }
    
    return $attach_id;
}


private function find_attachment_by_filepath($relative_path) {
    global $wpdb;
    
    // First try _wp_attached_file meta
    $attachment_id = $wpdb->get_var($wpdb->prepare(
        "SELECT post_id FROM $wpdb->postmeta 
        WHERE meta_key = '_wp_attached_file' 
        AND meta_value = %s",
        $relative_path
    ));
    
    if ($attachment_id) {
        return $attachment_id;
    }
    
    // Then try _wp_attachment_metadata meta
    $attachments = $wpdb->get_results(
        "SELECT post_id, meta_value FROM $wpdb->postmeta 
        WHERE meta_key = '_wp_attachment_metadata'"
    );
    
    foreach ($attachments as $attachment) {
        $meta = maybe_unserialize($attachment->meta_value);
        if (isset($meta['file']) && $meta['file'] === $relative_path) {
            return $attachment->post_id;
        }
    }
    
    return 0;
}


private function process_media_files($uploads_dir) {
    $original_files = [];
    
    // Get all image files, excluding thumbnails
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($uploads_dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    
    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }
        
        $filename = $file->getFilename();
        $extension = strtolower($file->getExtension());
        
        // Process all common media file types
        $media_types = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp', 'mp3', 'mp4', 'm4v', 'mov', 'wmv', 'avi', 'mpg', 'ogv', '3gp', '3g2', 'pdf', 'doc', 'docx', 'ppt', 'pptx', 'odt', 'xls', 'xlsx', 'psd', 'ai', 'eps', 'ps', 'zip', 'gz', 'tar', 'rar'];
        
        if (!in_array($extension, $media_types)) {
            continue;
        }
        
        // Skip thumbnails - they'll be regenerated
        if (preg_match('/-\d+x\d+\./', $filename)) {
            continue;
        }
        
        $original_files[] = $file->getPathname();
    }
    
    // Process original files
    foreach ($original_files as $filepath) {
        $attach_id = $this->register_single_media_file($filepath);
        if ($attach_id) {
            // Log success for debugging
            error_log("[WP Site Inspector] Registered media file: " . basename($filepath) . " (ID: $attach_id)");
        }
    }
    
    // Clean up orphaned thumbnails and regenerate metadata
    $this->cleanup_and_regenerate_thumbnails($uploads_dir);
}


private function cleanup_and_regenerate_thumbnails($uploads_dir) {
    // Get all registered attachments
    $attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'post_status' => 'inherit',
        'meta_query' => [
            [
                'key' => '_wp_attached_file',
                'compare' => 'EXISTS'
            ]
        ]
    ]);
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    foreach ($attachments as $attachment) {
        $file = get_attached_file($attachment->ID);
        
        if ($file && file_exists($file) && wp_attachment_is_image($attachment->ID)) {
            // Delete old thumbnails
            $metadata = wp_get_attachment_metadata($attachment->ID);
            if ($metadata && isset($metadata['sizes'])) {
                foreach ($metadata['sizes'] as $size => $size_data) {
                    $thumbnail_path = path_join(dirname($file), $size_data['file']);
                    if (file_exists($thumbnail_path)) {
                        @unlink($thumbnail_path);
                    }
                }
            }
            
            // Regenerate metadata and thumbnails
            $new_metadata = wp_generate_attachment_metadata($attachment->ID, $file);
            wp_update_attachment_metadata($attachment->ID, $new_metadata);
            
            // Update the attachment URL to current site
            $upload_dir = wp_get_upload_dir();
            $relative_path = str_replace($upload_dir['basedir'], '', $file);
            $new_url = $upload_dir['baseurl'] . $relative_path;
            
            wp_update_post([
                'ID' => $attachment->ID,
                'guid' => $new_url
            ]);
        }
    }
}
	
	


private function regenerate_attachment_metadata() {
    $attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'post_status' => 'inherit'
    ]);
    
    require_once(ABSPATH . 'wp-admin/includes/image.php');
    
    foreach ($attachments as $attachment) {
        $file = get_attached_file($attachment->ID);
        if ($file && file_exists($file) && wp_attachment_is_image($attachment->ID)) {
            $metadata = wp_generate_attachment_metadata($attachment->ID, $file);
            wp_update_attachment_metadata($attachment->ID, $metadata);
        }
    }
}
	
	private function force_media_library_refresh() {
    // Clear media cache
    delete_transient('media_library_mode');
    wp_cache_flush();
    
    // Force attachment count refresh
    $count = wp_count_attachments();
    update_option('media_library_mode', 'grid'); // Reset view mode
    
    // Rebuild the media library index
    $query = new WP_Query([
        'post_type' => 'attachment',
        'post_status' => 'inherit',
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    // Prime the cache
    _prime_post_caches($query->posts, true, true);
}


private function fix_file_permissions($path) {
    if (!file_exists($path)) {
        error_log("[WP Site Inspector] Path not found: $path");
        return;
    }


    if (is_dir($path)) {
        if (!@chmod($path, 0755)) {
            error_log("[WP Site Inspector] Failed to chmod directory: $path");
        }


        $items = scandir($path);
        if (!$items) {
            error_log("[WP Site Inspector] Failed to scan directory: $path");
            return;
        }


        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $this->fix_file_permissions($path . '/' . $item);
        }
    } else {
        if (!@chmod($path, 0644)) {
            error_log("[WP Site Inspector] Failed to chmod file: $path");
        }
    }
}


private function replace_urls_in_database($old_url, $new_url) {
    global $wpdb;
    
    // Make sure URLs end with slash for consistent replacement
    $old_url = untrailingslashit($old_url);
    $new_url = untrailingslashit($new_url);
    
    // Tables and columns to search for URLs
    $tables_to_update = [
        $wpdb->options => ['option_value'],
        $wpdb->posts => ['post_content', 'guid', 'post_excerpt'],
        $wpdb->postmeta => ['meta_value'],
        $wpdb->termmeta => ['meta_value'],
        $wpdb->comments => ['comment_content'],
        $wpdb->commentmeta => ['meta_value']
    ];
    
    foreach ($tables_to_update as $table => $columns) {
        foreach ($columns as $column) {
            // Standard URL replacement
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET $column = REPLACE($column, %s, %s) WHERE $column LIKE %s",
                $old_url,
                $new_url,
                '%' . $wpdb->esc_like($old_url) . '%'
            ));
            
            // URL-encoded replacement
            $old_encoded = urlencode($old_url);
            $new_encoded = urlencode($new_url);
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET $column = REPLACE($column, %s, %s) WHERE $column LIKE %s",
                $old_encoded,
                $new_encoded,
                '%' . $wpdb->esc_like($old_encoded) . '%'
            ));
            
            // Serialized URL replacement
            $old_serialized = serialize($old_url);
            $new_serialized = serialize($new_url);
            $wpdb->query($wpdb->prepare(
                "UPDATE $table SET $column = REPLACE($column, %s, %s) WHERE $column LIKE %s",
                $old_serialized,
                $new_serialized,
                '%' . $wpdb->esc_like($old_serialized) . '%'
            ));
        }
    }
    
    // Special handling for widgets
    $widgets = $wpdb->get_results("SELECT option_name, option_value FROM $wpdb->options WHERE option_name LIKE 'widget_%'");
    foreach ($widgets as $widget) {
        $new_value = str_replace($old_url, $new_url, $widget->option_value);
        if ($new_value != $widget->option_value) {
            update_option($widget->option_name, $new_value);
        }
    }
}


private function fix_serialized_data() {
    global $wpdb;
    
    // Fix serialized data in options table
    $options = $wpdb->get_results("SELECT option_id, option_name, option_value FROM $wpdb->options WHERE option_value LIKE 'a:%' OR option_value LIKE 'O:%'");
    
    foreach ($options as $option) {
        if (is_serialized($option->option_value)) {
            $fixed_value = maybe_unserialize($option->option_value);
            if ($fixed_value !== false) {
                $wpdb->update(
                    $wpdb->options,
                    ['option_value' => maybe_serialize($fixed_value)],
                    ['option_id' => $option->option_id]
                );
            }
        }
    }
    
    // Fix serialized data in postmeta
    $postmeta = $wpdb->get_results("SELECT meta_id, meta_value FROM $wpdb->postmeta WHERE meta_value LIKE 'a:%' OR meta_value LIKE 'O:%'");
    
    foreach ($postmeta as $meta) {
        if (is_serialized($meta->meta_value)) {
            $fixed_value = maybe_unserialize($meta->meta_value);
            if ($fixed_value !== false) {
                $wpdb->update(
                    $wpdb->postmeta,
                    ['meta_value' => maybe_serialize($fixed_value)],
                    ['meta_id' => $meta->meta_id]
                );
            }
        }
    }
}


private function update_post_guids($old_url, $new_url) {
    global $wpdb;
    
    $wpdb->query($wpdb->prepare(
        "UPDATE $wpdb->posts SET guid = REPLACE(guid, %s, %s) WHERE guid LIKE %s",
        $old_url,
        $new_url,
        $old_url . '%'
    ));
}


private function fix_import_query($query) {
    // Fix unquoted DEFAULT Y/N/T/F values
    $query = preg_replace_callback(
        '/(DEFAULT\s+)([YNTF])(?=[,\)\s;]|$)/i',
        function($matches) {
            return $matches[1] . "'" . $matches[2] . "'";
        },
        $query
    );
    
    // Fix 0000-00-00 dates
    $query = preg_replace(
        "/'0000-00-00( 00:00:00)?'/",
        "'1970-01-01$1'",
        $query
    );
    
    // Fix unquoted dates in values
    $query = preg_replace(
        "/(VALUES\s*\(|,\s*)(\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?)(?=[,\)])/",
        "$1'$2'",
        $query
    );
    
    // Fix DEFAULT values for datetime fields
    $query = preg_replace(
        "/(DEFAULT\s+)(\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?)(?=[,\)\s;]|$)/",
        "$1'$2'",
        $query
    );
    
    // Fix boolean values in INSERT statements
    $query = preg_replace_callback(
        "/\((.*?)\)/",
        function($matches) {
            $values = preg_replace(
                "/(^|,)\s*([YNTF])\s*(?=,|$)/i",
                "$1'$2'",
                $matches[1]
            );
            return '(' . $values . ')';
        },
        $query
    );
    
    return $query;
}
	


    private function restore_files($extract_dir) {
        $files_dir = trailingslashit($extract_dir) . 'files';
        
        if (!file_exists($files_dir)) {
            wp_send_json_error(__('Files directory not found in backup.', 'wp-site-inspector'));
        }
        
        $directories = [
            'wp-content/plugins' => WP_PLUGIN_DIR,
            'wp-content/themes' => get_theme_root(),
            'wp-content/uploads' => wp_upload_dir()['basedir']
        ];
        
        foreach ($directories as $backup_path => $destination) {
            $source = trailingslashit($files_dir) . $backup_path;
            
            if (!file_exists($source)) {
                continue;
            }
            
            $this->copy_directory($source, $destination);
        }
        
        wp_send_json_success([
            'message' => __('Files restored successfully. Finalizing restoration...', 'wp-site-inspector'),
            'next_step' => 'finalize',
            'extract_dir' => $extract_dir,
            'progress' => 80
        ]);
    }
    
    private function copy_directory($source, $destination) {
        if (!file_exists($destination)) {
            wp_mkdir_p($destination);
        }
        
        $dir = opendir($source);
        
        while (false !== ($file = readdir($dir))) {
            if (($file != '.') && ($file != '..')) {
                if (is_dir($source . '/' . $file)) {
                    $this->copy_directory($source . '/' . $file, $destination . '/' . $file);
                } else {
                    copy($source . '/' . $file, $destination . '/' . $file);
                }
            }
        }
        
        closedir($dir);
    }
    
    private function finalize_restore($extract_dir) {
        if (!empty($extract_dir) && file_exists($extract_dir)) {
            $this->delete_directory($extract_dir);
        }
        
        flush_rewrite_rules();
        wp_cache_flush();
        
        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
        
        wp_send_json_success([
            'message' => __('Restoration completed successfully!', 'wp-site-inspector'),
            'complete' => true,
            'progress' => 100
        ]);
    }
    
    public function delete_backup() {
        check_ajax_referer('wpsi_restore_backup', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('You do not have permission to delete backups.', 'wp-site-inspector'));
        }
        
        $backup_file = isset($_POST['backup_file']) ? sanitize_text_field($_POST['backup_file']) : '';
        
        if (empty($backup_file) || !file_exists($backup_file)) {
            wp_send_json_error(__('Backup file not found.', 'wp-site-inspector'));
        }
        
        if (strpos(realpath($backup_file), realpath($this->backup_dir)) !== 0) {
            wp_send_json_error(__('Invalid backup file path.', 'wp-site-inspector'));
        }
        
        if (!unlink($backup_file)) {
            wp_send_json_error(__('Failed to delete backup file.', 'wp-site-inspector'));
        }
        
        wp_send_json_success([
            'message' => __('Backup file deleted successfully.', 'wp-site-inspector'),
            'backup_file' => $backup_file
        ]);
    }
    
    private function delete_directory($dir) {
        if (!file_exists($dir)) {
            return true;
        }
        
        if (!is_dir($dir)) {
            return unlink($dir);
        }
        
        foreach (scandir($dir) as $item) {
            if ($item == '.' || $item == '..') {
                continue;
            }
            
            if (!$this->delete_directory($dir . DIRECTORY_SEPARATOR . $item)) {
                return false;
            }
        }
        
        return rmdir($dir);
    }
}


new WPSI_Restore();
