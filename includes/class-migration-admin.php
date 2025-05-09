<?php

class Migration_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'handle_tools_form'));
        add_action('wp_ajax_update_broken_link', array($this, 'ajax_update_broken_link'));
    }

    public function add_menu() {
        add_menu_page(
            'Migration Assistant',
            'Migration Assistant',
            'manage_options',
            'migration-assistant',
            array($this, 'display_page'),
            'dashicons-analytics',
            80
        );
    }
    public function handle_tools_form() {
        if (!current_user_can('manage_options')) return;
        if (!isset($_POST['_wpnonce']) || !wp_verify_nonce($_POST['_wpnonce'], 'run_migration_tools')) return;
    
        if (isset($_POST['generate_internal_links'])) {
            Migration_Links::generate_internal_link_mappings();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>Internal link mappings generated.</p></div>';
            });
        }
    
        if (isset($_POST['fix_all_links'])) {
            Migration_Links::fix_all_links();
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>All internal links have been updated in post content.</p></div>';
            });
        }
        
        if (isset($_POST['fix_court_urls'])) {
            $fixed_count = Migration_Links::fix_court_urls();
            add_action('admin_notices', function() use ($fixed_count) {
                echo '<div class="notice notice-success"><p>Fixed ' . $fixed_count . ' court URLs with incorrect paths.</p></div>';
            });
        }
        
        if (isset($_POST['fix_court_urls_in_content'])) {
            $result = Migration_Links::fix_court_urls_in_content();
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-success"><p>Fixed ' . $result['fixed_count'] . ' court URLs in content across ' . $result['page_count'] . ' pages.</p></div>';
            });
        }
        
        if (isset($_POST['import_faqs'])) {
            $result = Migration_FAQs::import_from_csv();
            
            if (is_wp_error($result)) {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-error"><p>Error: ' . esc_html($result->get_error_message()) . '</p></div>';
                });
            } else {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-success"><p>Successfully imported ' . intval($result) . ' FAQs.</p></div>';
                });
            }
        }
    }
    
    public function enqueue_scripts($hook) {
        if ($hook != 'toplevel_page_migration-assistant') {
            return;
        }
    
        // Enqueue jQuery UI for accordion functionality
        wp_enqueue_script('jquery-ui-accordion');
    
        // Enqueue custom scripts
        wp_enqueue_script(
            'migration-admin-js', 
            plugins_url('../assets/js/migration-admin.js', __FILE__), 
            array('jquery', 'jquery-ui-accordion'), 
            filemtime(plugin_dir_path(__FILE__) . '../assets/js/migration-admin.js'), 
            true
        );
    
        // Enqueue migration pages script
        wp_enqueue_script(
            'migration-pages-js', 
            plugins_url('../assets/js/migration-pages.js', __FILE__), 
            array('jquery'), 
            filemtime(plugin_dir_path(__FILE__) . '../assets/js/migration-pages.js'), 
            true
        );
    
        // Enqueue custom styles
        wp_enqueue_style(
            'migration-style', 
            plugins_url('../assets/css/migration-style.css', __FILE__),
            array(),
            filemtime(plugin_dir_path(__FILE__) . '../assets/css/migration-style.css')
        );
    
        // Pass PHP data to JavaScript
        wp_localize_script('migration-admin-js', 'migrationAdminData', array(
            'ajax_url'  => admin_url('admin-ajax.php'),
            'treeState' => json_encode(get_option('migration_tree_state', [])), // Store tree state persistently
        ));
    }
    

    public function display_page() {
        echo '<div class="wrap">';
        echo '<h1>Migration Assistant</h1>';
        
        echo '<div id="ma-container">';
        
        echo '<div id="ma-menu">';
        echo '<h2>Files</h2>';
        echo Migration_Functions::generate_tree_html(MIGRATION_CLEANED_DATA);
        echo '</div>';
        
        echo '<div id="ma-content">';
        if (isset($_GET['file'])) {
            // Only display the file content in the main content area
            $file_path = urldecode($_GET['file']);
            Migration_Pages::display_file($file_path);
            echo '<p class="file-path">File: ' . $file_path . '</p>';
        } else {
            echo '<p>Select a file from the tree to view its content.</p>';
        }
        echo '</div>'; // closes ma-content
        echo '</div>'; // closes ma-container
        
        // File actions section (only shown when a file is selected)

        if (isset($_GET['file'])) {
            echo '<div class="tools-section file-tools-section">';
            echo '<h2>File Actions</h2>';
            // Display the file actions in a separate section
            Migration_Pages::display_file_actions(urldecode($_GET['file']));
            echo '</div>';
        }
        
        // Global tools section
        echo '<div class="tools-section global-tools-section">';
        echo '<h2>Link Management Tools</h2>';
        echo '<form method="post">';
        wp_nonce_field('run_migration_tools');

        echo '<p><button type="submit" name="generate_internal_links" class="button button-primary">Generate Internal Link Mappings</button></p>';
        echo '<p><button type="submit" name="fix_all_links" class="button button-secondary">Fix All Links in Page Content</button></p>';
        echo '<p><button type="submit" name="fix_court_urls" class="button button-secondary">Fix Court URLs in Database</button></p>';
        echo '<p><button type="submit" name="fix_court_urls_in_content" class="button button-secondary">Fix Court URLs in Content</button></p>';
        
        // Add FAQ Import section
        echo '<hr>';
        echo '<h2>FAQ Import Tool</h2>';
        echo '<p>Import FAQs from the FAQs.csv file in the plugin directory.</p>';
        echo '<p><button type="submit" name="import_faqs" class="button button-primary">Import FAQs</button></p>';
        
        echo '</form>';
        
        // Add the broken links management section
        $this->display_broken_links_manager();
        
        echo '<hr>';
        echo '<h2>Test Airtable Connection</h2>';
        echo '<p><button id="test-airtable-button" class="button">Test Airtable Department Fetch</button></p>';
        echo '<p>';
        echo '<label for="test-department-name">Department name to test: </label>';
        echo '<input type="text" id="test-department-name" placeholder="e.g. Public Defender\'s" style="width: 300px;" />';
        echo '</p>';
        echo '<pre id="airtable-log" style="background: #f1f1f1; padding: 10px; display: none;"></pre>';
        echo '<hr>';
        echo '<h2>Data Utilities</h2>';
        echo '<p>';
        echo '<button id="populate-old-pages-button" class="button">Populate Old Pages Table</button>';
        echo '</p>';
        echo '<pre id="old-pages-log" style="background: #f1f1f1; padding: 10px; display: none;"></pre>';
        
        echo '</div>'; // closes tools-section
        echo <<<HTML
            <div id="migration-progress-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.6); z-index:10000; align-items:center; justify-content:center;">
            <div style="background:#fff; padding:20px 30px; border-radius:10px; box-shadow:0 4px 12px rgba(0,0,0,0.3); max-width:500px; width:90%;">
                <h2 style="margin-top:0;">Migration Progress</h2>
                <div id="migration-status" style="margin-bottom:10px;">Preparing...</div>
                <div style="background:#eee; height:20px; width:100%; border:1px solid #ccc; border-radius:5px; overflow:hidden;">
                <div id="migration-progress-bar" style="background:#0073aa; width:0%; height:100%; transition:width 0.3s;"></div>
                </div>
                <div id="migration-close-btn-container" style="text-align:right; margin-top:15px; display:none;">
                <button id="migration-close-btn" class="button button-secondary">Close</button>
                </div>
            </div>
            </div>
            HTML;

        echo '</div>'; // closes .wrap
    }
    
    private function display_broken_links_manager() {
        global $wpdb;
        
        echo '<hr>';
        echo '<h2>Broken Links Manager</h2>';
        
        // Get all broken links
        $broken_links = $wpdb->get_results("
            SELECT l.ID, l.Old_URL, l.Type, COUNT(lo.ID) as occurrence_count
            FROM Links l
            JOIN Link_Occurences lo ON l.ID = lo.Link_ID
            WHERE l.Status = 'broken' OR (l.Status = 'unresolved' AND l.New_URL = '#broken-link')
            GROUP BY l.ID
            ORDER BY occurrence_count DESC
        ");
        
        if (empty($broken_links)) {
            echo '<p>No broken links found. Great job!</p>';
            return;
        }
        
        echo '<p>Found ' . count($broken_links) . ' broken links. Fix them by entering a new URL and clicking "Update".</p>';
        
        echo '<div class="broken-links-table-wrapper" style="max-height: 500px; overflow-y: auto; margin-bottom: 20px;">';
        echo '<table class="wp-list-table widefat fixed striped broken-links-table">';
        echo '<thead>
                <tr>
                    <th style="width: 35%;">Original URL</th>
                    <th style="width: 10%;">Link ID</th>
                    <th style="width: 10%;">Type</th>
                    <th style="width: 10%;">Occurrences</th>
                    <th style="width: 25%;">New URL</th>
                    <th style="width: 10%;">Action</th>
                </tr>
              </thead>';
        echo '<tbody>';
        
        foreach ($broken_links as $link) {
            echo '<tr data-link-id="' . esc_attr($link->ID) . '">';
            
            // Original URL column
            echo '<td class="original-url">';
            echo '<div style="max-width: 400px; overflow-wrap: break-word;">';
            echo esc_html($link->Old_URL);
            echo '</div>';
            echo '</td>';
            

            // Link ID column
            echo '<td>' . esc_html($link->ID) . '</td>';

            // Type column
            echo '<td>' . esc_html($link->Type) . '</td>';
            
            // Occurrences column
            echo '<td>' . intval($link->occurrence_count) . '</td>';
            
            // New URL input column
            echo '<td><input type="url" class="new-url-input" style="width: 100%;" placeholder="Enter new URL"></td>';
            
            // Action column
            echo '<td><button class="button update-link-button">Update</button></td>';
            
            echo '</tr>';
            
            // Add a row for status/feedback
            echo '<tr class="link-status-row" data-link-id="' . esc_attr($link->ID) . '" style="display: none;">';
            echo '<td colspan="5" class="link-status"></td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        
        // Add JavaScript for handling the AJAX updates
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('.update-link-button').on('click', function() {
                var row = $(this).closest('tr');
                var linkId = row.data('link-id');
                var newUrl = row.find('.new-url-input').val();
                var statusRow = $('.link-status-row[data-link-id="' + linkId + '"]');
                
                if (!newUrl) {
                    statusRow.show().find('.link-status').html('<div class="notice notice-error inline"><p>Please enter a URL</p></div>');
                    return;
                }
                
                // Disable the button and show loading state
                $(this).prop('disabled', true).text('Updating...');
                
                // Show the status row with loading message
                statusRow.show().find('.link-status').html('<div class="notice notice-info inline"><p>Updating link...</p></div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'update_broken_link',
                        link_id: linkId,
                        new_url: newUrl,
                        nonce: '<?php echo wp_create_nonce('update_broken_link_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            statusRow.find('.link-status').html('<div class="notice notice-success inline"><p>' + response.data.message + '</p></div>');
                            
                            // If all occurrences were fixed, fade out the rows after a delay
                            setTimeout(function() {
                                row.fadeOut(500);
                                statusRow.fadeOut(500, function() {
                                    // Remove the rows if they've all been fixed
                                    row.remove();
                                    statusRow.remove();
                                    
                                    // If no more broken links, show success message
                                    if ($('.broken-links-table tbody tr').length === 0) {
                                        $('.broken-links-table-wrapper').html('<div class="notice notice-success"><p>All broken links have been fixed!</p></div>');
                                    }
                                });
                            }, 3000);
                        } else {
                            statusRow.find('.link-status').html('<div class="notice notice-error inline"><p>Error: ' + response.data + '</p></div>');
                            $(this).prop('disabled', false).text('Update');
                        }
                    },
                    error: function() {
                        statusRow.find('.link-status').html('<div class="notice notice-error inline"><p>Server error occurred</p></div>');
                        $(this).prop('disabled', false).text('Update');
                    }
                });
            });
        });
        </script>
        <?php
    }

    public function ajax_update_broken_link() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'update_broken_link_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }

        // Check for required data
        if (!isset($_POST['link_id']) || !isset($_POST['new_url'])) {
            wp_send_json_error('Missing required data');
            return;
        }

        global $wpdb;
        $link_id = intval($_POST['link_id']);
        $new_url = esc_url_raw($_POST['new_url']);

        // Update the link in the database
        $result = $wpdb->update(
            'Links',
            [
                'New_URL' => $new_url,
                'Status' => 'unresolved' // Set to unresolved so fix_all_links will process it
            ],
            ['ID' => $link_id]
        );

        if ($result === false) {
            wp_send_json_error('Database update failed: ' . $wpdb->last_error);
            return;
        }

        // Run fix_all_links for just this specific link
        $fixed_count = Migration_Links::fix_specific_link($link_id);

        wp_send_json_success([
            'message' => "Link updated successfully. Fixed $fixed_count occurrences.",
            'fixed_count' => $fixed_count
        ]);
    }
}

