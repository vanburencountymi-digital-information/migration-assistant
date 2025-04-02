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
                    // Remove "template-" prefix and ".php" extension to get the template name
                    $template_name = str_replace('template-', '', basename($template_file, '.php'));
                    // Use theme name as prefix for the template key to avoid conflicts
                    $template_key = $theme_name . '-' . $template_name;
                    // Create a readable display name
                    $display_name = ucwords(str_replace(['-', '_'], ' ', $theme_name)) . ' - ' . 
                                   ucwords(str_replace(['-', '_'], ' ', $template_name));
                    
                    $templates[$template_key] = $display_name;
                }
            }
        }
        
        return $templates;
    }

    public static function display_template_selection($relative_path) {
        echo '<label>Select Template:</label>';
        echo '<select id="template">';
        foreach (self::get_available_templates() as $key => $name) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($name) . '</option>';
        }
        echo '</select>';
        echo '<button id="apply-template" data-path="' . esc_attr($relative_path) . '">Apply</button>';
    }
}
