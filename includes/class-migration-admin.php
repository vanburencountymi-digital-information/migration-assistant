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
        
        // Add inline styles
        echo '<style>
            #ma-container { 
                display: flex; 
                margin-bottom: 20px;
                border: 1px solid #ddd;
                border-radius: 4px;
                overflow: hidden;
                height: 600px; /* Fixed height container */
            }
            #ma-menu { 
                width: 30%; 
                padding: 15px;
                border-right: 1px solid #ddd;
                overflow: auto; /* Scrollable */
                height: 100%;
                background: #f9f9f9;
            }
            #ma-content { 
                width: 70%; 
                padding: 15px;
                overflow: auto; /* Scrollable */
                height: 100%;
            }
            
            /* File tree styling */
            .folder > ul { margin-left: 20px; }
            .folder > a.directory { 
                font-weight: bold; 
                color: #0073aa; 
                text-decoration: none;
                cursor: pointer;
                display: block;
                padding: 5px 0;
            }
            .file > a {
                color: #444;
                text-decoration: none;
                display: block;
                padding: 3px 0;
            }
            .toggle-icon {
                display: inline-block;
                width: 16px;
                height: 16px;
                text-align: center;
                margin-right: 5px;
                font-size: 10px;
            }
            .folder > a.directory:hover,
            .file > a:hover {
                text-decoration: underline;
            }
            
            /* Tools section styling */
            .tools-section {
                margin-top: 20px;
                padding: 15px;
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
            }
        </style>';
        
        echo '<div id="ma-container">';
        
        echo '<div id="ma-menu">';
        echo '<h2>Files</h2>';
        echo Migration_Functions::generate_tree_html(MIGRATION_CLEANED_DATA);
        echo '</div>';
        
        echo '<div id="ma-content">';
        if (isset($_GET['file'])) {
            echo '<p>File: ' . urldecode($_GET['file']) . '</p>';
            Migration_Pages::display_file(urldecode($_GET['file']));
        } else {
            echo '<p>Select a folder to view its content.</p>';
        }
        echo '</div>'; // closes ma-content
        echo '</div>'; // closes ma-container
        
        // Tools section
        echo '<div class="tools-section">';
        echo '<h2>Link Management Tools</h2>';
        echo '<form method="post">';
        wp_nonce_field('run_migration_tools');

        echo '<p><button type="submit" name="generate_internal_links" class="button button-primary">Generate Internal Link Mappings</button></p>';
        echo '<p><button type="submit" name="fix_all_links" class="button button-secondary">Fix All Links in Page Content</button></p>';
        echo '</form>';
        echo '</div>'; // closes tools-section

        echo '</div>'; // closes .wrap
    }
}

