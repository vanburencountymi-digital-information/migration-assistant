<?php

class Migration_Templates {
    public static function get_available_templates() {
        $templates = ['default' => 'Default']; // Keep default as fallback
        
        // Path to themes directory (3 levels up, then /themes)
        $themes_dir = dirname(dirname(dirname(dirname(__FILE__)))) . '/themes/';
        error_log('Themes directory: ' . $themes_dir);
        
        // Check if directory exists
        if (is_dir($themes_dir)) {
            // Get all subdirectories in the themes directory
            $theme_dirs = array_filter(glob($themes_dir . '*'), 'is_dir');
            
            // Loop through each theme directory
            foreach ($theme_dirs as $theme_dir) {
                $theme_name = basename($theme_dir);
                
                // Look for template files in this theme directory that start with "template-"
                $template_files = glob($theme_dir . '/template-*.php');
                
                // Process each template file
                foreach ($template_files as $template_file) {
                    $template_filename = basename($template_file); // e.g., 'template-department-homepage.php'
                    $template_key = $template_filename; // This will be used to assign the template to the page
                    
                    // Create a display name: remove 'template-' and '.php', then prettify
                    $raw_name = basename($template_file, '.php'); // e.g., 'template-department-homepage'
                    $display_base = str_replace('template-', '', $raw_name); // 'department-homepage'
                    $display_name = ucwords(str_replace(['-', '_'], ' ', $display_base)); // 'Department Homepage'
                    
                    
                    $templates[$template_key] = $theme_name . ' - ' . $display_name;

                }
            }
        }
        
        return $templates;
    }

    public static function display_template_selection($relative_path) {
        $subpage_tree = Migration_Pages::build_subpage_tree($relative_path);
        $max_depth = Migration_Pages::get_max_tree_depth($subpage_tree);

        for ($level = 0; $level <= $max_depth; $level++) {
            echo '<div class="template-selection-level">';
            echo '<label for="page_template_' . $level . '">Select Template for Level ' . $level . ':</label>';
            echo '<select id="page_template_' . $level . '" class="page_template_dropdown" data-level="' . $level . '">';
            
            // Use your available templates
            foreach (Migration_Templates::get_available_templates() as $key => $name) {
                echo '<option value="' . esc_attr($key) . '">' . esc_html($name) . '</option>';
            }
            
            echo '</select>';
            echo '</div>';
        }

    }
}
