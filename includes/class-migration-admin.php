<?php

class Migration_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('admin_init', array($this, 'handle_tools_form'));

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
        echo '<div id="migration-progress-container" style="display: none; margin-top: 20px;">';
            echo '<div id="migration-status" style="margin-bottom: 10px;">Preparing to merge content...</div>';
            echo '<div style="background: #eee; height: 20px; width: 100%; border: 1px solid #ccc;">';
                echo '<div id="migration-progress-bar" style="background: #0073aa; width: 0%; height: 100%; transition: width 0.3s;"></div>';
            echo '</div>';
        echo '</div>';

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
        echo '</form>';
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

        echo '</div>'; // closes .wrap
    }
}

