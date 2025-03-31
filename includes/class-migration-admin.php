<?php

class Migration_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
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
            #ma-container { display: flex; }
            #ma-menu { width: 30%; padding-right: 20px; }
            #ma-content { width: 70%; }
            
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
        </style>';
        
        echo '<div id="ma-container">';
        
        echo '<div id="ma-menu">';
        echo '<h2>Files</h2>';
        echo Migration_Functions::generate_tree_html(MIGRATION_CLEANED_DATA);
        echo '</div>';
        
        echo '<div id="ma-content">';
        if (isset($_GET['file'])) {
            Migration_Pages::display_file(urldecode($_GET['file']));
            echo '<p>File: ' . urldecode($_GET['file']) . '</p>';
        } else {
            echo '<p>Select a folder to view its content.</p>';
        }
        echo '</div>';

        echo '</div>';
        echo '</div>';
    }
}

