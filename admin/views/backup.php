<?php
if (!defined('ABSPATH')) exit; // Exit if accessed directly


$export_url = wp_nonce_url(admin_url('admin-post.php?action=wpsi_export_backup'), 'wpsi_export_backup');
$max_upload_size = wp_max_upload_size();
?>


<div class="wrap">
    <h1><?php esc_html_e('Site Backup & Migration', 'wp-site-inspector'); ?></h1>
    
    <?php if (isset($_GET['import']) && $_GET['import'] === 'success') : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e('Site import completed successfully!', 'wp-site-inspector'); ?></p>
        </div>
    <?php endif; ?>


    <!-- Backup Section -->
    <div class="card">
        <h2 class="title"><?php esc_html_e('Create Backup', 'wp-site-inspector'); ?></h2>
        <p><?php esc_html_e('Create a complete backup of your WordPress site including database, themes, plugins, and uploads.', 'wp-site-inspector'); ?></p>
        <form action="<?php echo esc_url($export_url); ?>" method="post">
            <?php wp_nonce_field('wpsi_export_backup'); ?>
            <p>
                <input type="submit" class="button button-primary" value="<?php esc_attr_e('Create Backup', 'wp-site-inspector'); ?>">
            </p>
        </form>
    </div>


    <!-- Restore Section -->
    <div class="card">
        <h2 class="title"><?php esc_html_e('Restore Backup', 'wp-site-inspector'); ?></h2>
        <p><?php esc_html_e('Restore your WordPress site from a backup file. Maximum file size: ', 'wp-site-inspector'); 
           echo esc_html(size_format($max_upload_size)); ?></p>
           
        <div id="wpsi-backup-list">
            <h3><?php esc_html_e('Available Backups', 'wp-site-inspector'); ?></h3>
            <div id="wpsi-backup-list-content" style="margin: 10px 0;">
                <p class="description"><?php esc_html_e('Loading backup list...', 'wp-site-inspector'); ?></p>
            </div>
        </div>
        
        <form id="wpsi-restore-form" method="post" enctype="multipart/form-data">
            <?php wp_nonce_field('wpsi_restore_backup', 'wpsi_restore_nonce'); ?>
            <input type="hidden" name="action" value="wpsi_restore_backup">
<!--             <p>
                <label for="wpsi-restore-file"><?php esc_html_e('Or upload a backup file:', 'wp-site-inspector'); ?></label>
                <input type="file" name="wpsi_restore_file" id="wpsi-restore-file" accept=".wpsi,.zip" style="margin-top: 5px;">
            </p> -->
            <p>
                <button type="button" id="wpsi-start-restore" class="button button-primary">
                    <?php esc_html_e('Restore Backup', 'wp-site-inspector'); ?>
                </button>
            </p>
        </form>
    </div>


    <!-- Progress Section -->
    <div id="wpsi-progress-container" style="display:none;">
        <div class="card">
            <h2 class="title"><?php esc_html_e('Restore Progress', 'wp-site-inspector'); ?></h2>
            <div id="wpsi-progress">
                <div class="progress-label"></div>
                <div class="progress-bar">
                    <div class="progress-bar-fill"></div>
                </div>
                <div class="progress-details">
                    <div id="wpsi-progress-percent">0%</div>
                    <div id="wpsi-progress-stats"></div>
                </div>
                <div id="wpsi-debug-log"></div>
            </div>
            <div id="wpsi-actions" style="margin-top:15px; display:none;">
                <button id="wpsi-cancel-restore" class="button button-secondary">
                    <?php esc_html_e('Cancel Restore', 'wp-site-inspector'); ?>
                </button>
                <button id="wpsi-retry-restore" class="button button-primary" style="display:none;">
                    <?php esc_html_e('Retry', 'wp-site-inspector'); ?>
                </button>
            </div>
        </div>
    </div>
</div>


<script>
jQuery(document).ready(function($) {
    const restoreForm = $('#wpsi-restore-form');
    const startRestoreBtn = $('#wpsi-start-restore');
    const backupList = $('#wpsi-backup-list-content');
    const progressContainer = $('#wpsi-progress-container');
    const progress = $('#wpsi-progress');
    const label = $('.progress-label');
    const bar = $('.progress-bar-fill');
    const percentText = $('#wpsi-progress-percent');
    const statsText = $('#wpsi-progress-stats');
    const debugLog = $('#wpsi-debug-log');
    const actions = $('#wpsi-actions');
    const cancelBtn = $('#wpsi-cancel-restore');
    const retryBtn = $('#wpsi-retry-restore');
    const fileInput = $('#wpsi-restore-file');
    const maxSize = <?php echo $max_upload_size; ?>;
    
    let currentRestore = null;
    let isChunkedUpload = false;
    let restoreState = {
        file: null,
        chunkSize: 16 * 1024 * 1024, // 16MB chunks
        currentChunk: 0,
        totalChunks: 0,
        retryCount: 0,
        maxRetries: 3,
        currentStep: null,
        extractDir: null
    };
    
    // Load available backups
    function loadBackupList() {
        $.post(ajaxurl, {
            action: 'wpsi_list_backups',
            nonce: $('#wpsi_restore_nonce').val()
        }).done(function(response) {
            if (response.success) {
                if (response.data.backups.length > 0) {
                    let html = '<table class="wp-list-table widefat fixed striped">';
                    html += '<thead><tr>';
                    html += '<th>' + '<?php esc_html_e('Backup File', 'wp-site-inspector'); ?>' + '</th>';
                    html += '<th>' + '<?php esc_html_e('Size', 'wp-site-inspector'); ?>' + '</th>';
                    html += '<th>' + '<?php esc_html_e('Date', 'wp-site-inspector'); ?>' + '</th>';
                    html += '<th>' + '<?php esc_html_e('Actions', 'wp-site-inspector'); ?>' + '</th>';
                    html += '</tr></thead><tbody>';
                    
                    $.each(response.data.backups, function(index, backup) {
                        html += '<tr>';
                        html += '<td>' + backup.name + '</td>';
                        html += '<td>' + backup.size + '</td>';
                        html += '<td>' + backup.date + '</td>';
                        html += '<td>';
                        html += '<button class="button button-primary wpsi-restore-existing" data-file="' + backup.path + '">' + '<?php esc_html_e('Restore', 'wp-site-inspector'); ?>' + '</button> ';
                        html += '<button class="button button-secondary wpsi-delete-backup" data-file="' + backup.path + '">' + '<?php esc_html_e('Delete', 'wp-site-inspector'); ?>' + '</button>';
                        html += '</td>';
                        html += '</tr>';
                    });
                    
                    html += '</tbody></table>';
                    backupList.html(html);
                    
                    // Add event handlers for the new buttons
                    $('.wpsi-restore-existing').on('click', function() {
                        const backupFile = $(this).data('file');
                        startRestoreProcess(backupFile);
                    });
                    
                    $('.wpsi-delete-backup').on('click', function() {
                        if (confirm('<?php esc_html_e('Are you sure you want to delete this backup?', 'wp-site-inspector'); ?>')) {
                            const backupFile = $(this).data('file');
                            deleteBackup(backupFile);
                        }
                    });
                } else {
                    backupList.html('<p>' + '<?php esc_html_e('No backup files found.', 'wp-site-inspector'); ?>' + '</p>');
                }
            } else {
                backupList.html('<div class="notice notice-error"><p>' + response.data + '</p></div>');
            }
        }).fail(function() {
            backupList.html('<div class="notice notice-error"><p>' + '<?php esc_html_e('Failed to load backup list.', 'wp-site-inspector'); ?>' + '</p></div>');
        });
    }
    
    // Delete a backup file
    function deleteBackup(backupFile) {
        $.post(ajaxurl, {
            action: 'wpsi_delete_backup',
            backup_file: backupFile,
            nonce: $('#wpsi_restore_nonce').val()
        }).done(function(response) {
            if (response.success) {
                alert(response.data.message);
                loadBackupList();
            } else {
                alert(response.data);
            }
        }).fail(function() {
            alert('<?php esc_html_e('Failed to delete backup.', 'wp-site-inspector'); ?>');
        });
    }
    
    // Helper functions
    function formatBytes(bytes) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2) + ' ' + sizes[i]);
    }
    
    function updateProgress(percent, message) {
        bar.css('width', percent + '%');
        percentText.text(percent + '%');
        label.text(message);
        
        if (percent < 30) {
            bar.css('background-color', '#dc3232');
        } else if (percent < 70) {
            bar.css('background-color', '#ffb900');
        } else {
            bar.css('background-color', '#46b450');
        }
    }
    
    function showError(message) {
        bar.css('background-color', '#dc3232');
        label.html('<strong><?php esc_html_e('Error:', 'wp-site-inspector'); ?></strong> ' + message);
        actions.show();
        retryBtn.show();
    }
    
    function logDebug(message) {
        const timestamp = new Date().toLocaleTimeString();
        debugLog.append('<div><small>[' + timestamp + ']</small> ' + message + '</div>');
        debugLog.scrollTop(debugLog[0].scrollHeight);
    }
    
    function resetRestoreState() {
        restoreState = {
            file: null,
            chunkSize: 16 * 1024 * 1024,
            currentChunk: 0,
            totalChunks: 0,
            retryCount: 0,
            currentStep: null,
            extractDir: null
        };
    }
    
    // Event handlers
    fileInput.on('change', function() {
        const file = this.files[0];
        if (file && file.size > maxSize) {
            alert('<?php esc_html_e('File too large. Maximum size: ', 'wp-site-inspector'); ?>' + formatBytes(maxSize));
            this.value = '';
        }
    });
    
    startRestoreBtn.on('click', function() {
        if (!fileInput[0].files.length) {
            alert('<?php esc_html_e('Please select a backup file', 'wp-site-inspector'); ?>');
            return;
        }
        
        const file = fileInput[0].files[0];
        if (file.size > maxSize) {
            alert('<?php esc_html_e('File too large. Maximum size: ', 'wp-site-inspector'); ?>' + formatBytes(maxSize));
            return;
        }
        
        startRestoreProcess(file);
    });
    
    cancelBtn.on('click', function() {
        if (currentRestore) {
            currentRestore.abort();
            showError('<?php esc_html_e('Restore cancelled by user', 'wp-site-inspector'); ?>');
            logDebug('Restore cancelled by user');
        }
    });
    
    retryBtn.on('click', function() {
        if (restoreState.retryCount < restoreState.maxRetries) {
            restoreState.retryCount++;
            logDebug('Retry attempt ' + restoreState.retryCount + ' of ' + restoreState.maxRetries);
            startRestoreProcess(restoreState.file, true);
        } else {
            showError('<?php esc_html_e('Maximum retry attempts reached', 'wp-site-inspector'); ?>');
        }
    });
    
    // Core restore functionality
    function startRestoreProcess(fileOrPath, isRetry = false) {
        // Setup UI
        progressContainer.show();
        progress.show();
        actions.hide();
        retryBtn.hide();
        debugLog.empty();
        statsText.empty();
        
        if (!isRetry) {
            resetRestoreState();
            
            if (typeof fileOrPath === 'string') {
                // Existing backup file
                restoreState.file = fileOrPath;
                updateProgress(0, '<?php esc_html_e('Preparing to restore from existing backup...', 'wp-site-inspector'); ?>');
                logDebug('Starting restore from existing backup: ' + fileOrPath);
                processRestore({
                    backup_file: fileOrPath,
                    step: 'restore_database'
                });
            } else {
                // New file upload
                restoreState.file = fileOrPath;
                restoreState.totalChunks = Math.ceil(fileOrPath.size / restoreState.chunkSize);
                updateProgress(0, '<?php esc_html_e('Preparing upload...', 'wp-site-inspector'); ?>');
                logDebug('Starting upload for file: ' + fileOrPath.name + ' (' + formatBytes(fileOrPath.size) + ')');
                
                // Determine upload method
                isChunkedUpload = fileOrPath.size > (16 * 1024 * 1024); // Use chunked for >16MB
                
                if (isChunkedUpload) {
                    logDebug('Using chunked upload (16MB chunks)');
                    uploadNextChunk();
                } else {
                    logDebug('Using standard upload');
                    performStandardUpload();
                }
            }
        } else {
            // Retry logic
            if (typeof fileOrPath === 'string') {
                processRestore({
                    backup_file: fileOrPath,
                    step: restoreState.currentStep || 'restore_database',
                    extract_dir: restoreState.extractDir
                });
            } else {
                if (isChunkedUpload) {
                    uploadNextChunk();
                } else {
                    performStandardUpload();
                }
            }
        }
    }
    
    function performStandardUpload() {
        const formData = new FormData(restoreForm[0]);
        formData.append('action', 'wpsi_restore_backup');
        
        currentRestore = new XMLHttpRequest();
        
        currentRestore.upload.addEventListener('progress', function(e) {
            if (e.lengthComputable) {
                const percent = Math.round((e.loaded / e.total) * 100);
                updateProgress(percent, '<?php esc_html_e('Uploading...', 'wp-site-inspector'); ?>');
                statsText.text(formatBytes(e.loaded) + ' / ' + formatBytes(e.total));
                logDebug('Upload progress: ' + percent + '%');
            }
        });
        
        currentRestore.onreadystatechange = function() {
            if (currentRestore.readyState === 4) {
                if (currentRestore.status === 200) {
                    try {
                        const response = JSON.parse(currentRestore.responseText);
                        if (response.success) {
                            logDebug('File upload complete. Starting restore process...');
                            processRestore(response.data);
                        } else {
                            showError(response.data || '<?php esc_html_e('Upload failed', 'wp-site-inspector'); ?>');
                            logDebug('Upload error: ' + response.data);
                        }
                    } catch (e) {
                        showError('<?php esc_html_e('Invalid server response', 'wp-site-inspector'); ?>');
                        logDebug('Invalid JSON response: ' + currentRestore.responseText);
                    }
                } else {
                    showError('<?php esc_html_e('Connection error', 'wp-site-inspector'); ?> (' + currentRestore.status + ')');
                    logDebug('HTTP error: ' + currentRestore.status + ' - ' + currentRestore.statusText);
                }
            }
        };
        
        currentRestore.open('POST', ajaxurl, true);
        currentRestore.send(formData);
        logDebug('Standard upload started');
    }
    
    function uploadNextChunk() {
        if (restoreState.currentChunk >= restoreState.totalChunks) {
            logDebug('All chunks uploaded successfully');
            return;
        }
        
        const start = restoreState.currentChunk * restoreState.chunkSize;
        const end = Math.min(restoreState.file.size, start + restoreState.chunkSize);
        const chunk = restoreState.file.slice(start, end);
        
        const formData = new FormData();
        formData.append('action', 'wpsi_chunked_upload');
        formData.append('nonce', $('#wpsi_restore_nonce').val());
        formData.append('name', restoreState.file.name);
        formData.append('chunk', restoreState.currentChunk);
        formData.append('chunks', restoreState.totalChunks);
        formData.append('file', chunk);
        
        const percent = Math.round((restoreState.currentChunk / restoreState.totalChunks) * 100);
        updateProgress(percent, 
            '<?php esc_html_e('Uploading chunk', 'wp-site-inspector'); ?> ' + 
            (restoreState.currentChunk + 1) + 
            ' <?php esc_html_e('of', 'wp-site-inspector'); ?> ' + 
            restoreState.totalChunks
        );
        
        statsText.text(
            formatBytes(start) + ' / ' + 
            formatBytes(restoreState.file.size) + 
            ' (' + percent + '%)'
        );
        
        logDebug('Uploading chunk ' + (restoreState.currentChunk + 1) + ' of ' + restoreState.totalChunks);
        
        currentRestore = $.ajax({
            url: ajaxurl,
            type: 'POST',
            data: formData,
            contentType: false,
            processData: false,
            cache: false,
            success: function(response) {
                if (response.success) {
                    if (response.data.complete) {
                        logDebug('All chunks uploaded successfully. Starting restore...');
                        processRestore(response.data);
                    } else {
                        restoreState.currentChunk++;
                        uploadNextChunk();
                    }
                } else {
                    showError(response.data || '<?php esc_html_e('Chunk upload failed', 'wp-site-inspector'); ?>');
                    logDebug('Chunk upload failed: ' + response.data);
                }
            },
            error: function(xhr, status, error) {
                showError('<?php esc_html_e('Connection error', 'wp-site-inspector'); ?>: ' + error);
                logDebug('Chunk upload error: ' + error + ' (Status: ' + xhr.status + ')');
            }
        });
    }
    
    function processRestore(data) {
        updateProgress(data.progress || 5, data.message);
        logDebug(data.message);
        
        if (data.complete) {
            updateProgress(100, '<?php esc_html_e('Restore completed!', 'wp-site-inspector'); ?>');
            logDebug('Restore process completed successfully');
            setTimeout(() => {
                window.location.href = '<?php echo esc_url(admin_url('admin.php?page=wpsi-backup&import=success')); ?>';
            }, 1500);
            return;
        }
        
        // Store current state for potential retries
        restoreState.currentStep = data.next_step;
        restoreState.extractDir = data.extract_dir;
        
        currentRestore = $.post(ajaxurl, {
            action: 'wpsi_restore_backup',
            step: data.next_step,
            backup_file: data.backup_file || null,
            extract_dir: data.extract_dir || null,
            nonce: $('#wpsi_restore_nonce').val()
        }).done(function(response) {
            if (response.success) {
                processRestore(response.data);
            } else {
                showError(response.data || '<?php esc_html_e('Restore failed', 'wp-site-inspector'); ?>');
                logDebug('Restore error: ' + response.data);
            }
        }).fail(function(xhr) {
            showError('<?php esc_html_e('Connection error', 'wp-site-inspector'); ?>');
            logDebug('Restore connection error: ' + xhr.responseText);
        });
    }
    
    // Initial load of backup list
    loadBackupList();
});
</script>


<style>
.card {
    background: #fff;
    border-left: 4px solid #0073aa;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}
.card .title {
    margin-top: 0;
    color: #23282d;
}
.progress-bar {
    background: #f1f1f1;
    height: 20px;
    border-radius: 4px;
    overflow: hidden;
    margin-bottom: 5px;
}
.progress-bar-fill {
    background: #0073aa;
    height: 100%;
    width: 0%;
    transition: width 0.5s ease, background-color 0.3s ease;
}
.progress-label {
    font-weight: bold;
    margin-bottom: 5px;
}
.progress-details {
    display: flex;
    justify-content: space-between;
    margin-top: 5px;
    font-size: 13px;
    color: #666;
}
#wpsi-progress-container {
    margin-top: 20px;
}
#wpsi-progress {
    padding: 15px;
    background: #f8f9fa;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}
#wpsi-debug-log {
    margin-top: 15px;
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    background: #f1f1f1;
    border-radius: 3px;
    font-family: monospace;
    font-size: 12px;
    line-height: 1.5;
}
#wpsi-debug-log div {
    margin-bottom: 5px;
    border-bottom: 1px solid #ddd;
    padding-bottom: 5px;
}
#wpsi-debug-log div:last-child {
    border-bottom: none;
}
.button-primary {
    background: #0073aa;
    border-color: #006799;
    color: #fff;
    text-shadow: none;
}
.button-primary:hover {
    background: #008ec2;
    border-color: #006799;
}
.button-secondary {
    background: #f0f0f1;
    border-color: #ccc;
    color: #2c3338;
}
.button-secondary:hover {
    background: #e0e0e0;
    border-color: #999;
}
input[type="file"] {
    padding: 5px;
    border: 1px solid #ddd;
    border-radius: 3px;
    width: 100%;
    max-width: 400px;
}
.wp-list-table {
    width: 100%;
    border-collapse: collapse;
}
.wp-list-table th, .wp-list-table td {
    padding: 8px 10px;
    border: 1px solid #e5e5e5;
}
.wp-list-table th {
    background: #f5f5f5;
    text-align: left;
}
.wp-list-table.striped tbody tr:nth-child(odd) {
    background-color: #f9f9f9;
}
</style>
