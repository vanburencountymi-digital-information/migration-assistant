<?php

class Migration_Functions {
    public static function store_page_hierarchy($old_path, $new_path) {
        $hierarchy = get_option('migration_page_hierarchy', []);
        $hierarchy[$old_path] = $new_path;
        update_option('migration_page_hierarchy', $hierarchy);
    }

    public static function generate_tree_html($dir) {
    /**
     * Recursively builds a tree-like nested HTML list of directories and files.
     *
     * At the root level, if a file named content.json is found, it is displayed as a file.
     * For any non-root directory, the folder itself is rendered as a clickable link
     * that automatically points to the directory's content.json.
     *
     * @param string $dir The current directory path.
     * @return string HTML markup for the file tree.
     */
        $html = '<div class="file-tree"><ul>';
        $items = scandir($dir);
        
        // Get the root directory path from the constant
        $root_dir = MIGRATION_CLEANED_DATA;
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            
            $full_path = trailingslashit($dir) . $item;
            
            // Calculate relative path by removing the root directory prefix
            // Make sure to handle the trailing slash consistently
            $relative_path = ltrim(str_replace(rtrim($root_dir, '/') . '/', '', $full_path), '/');
            
            if (is_dir($full_path)) {
                // For directories, automatically link to the folder's content.json
                $link = add_query_arg('file', urlencode($relative_path . '/content.json'), admin_url('admin.php?page=migration-assistant'));
                $html .= '<li class="folder"><a class="directory" data-path="' . esc_attr($relative_path) . '" href="' . esc_url($link) . '">' . esc_html($item) . '</a>';
                // Recursively list subdirectories
                $html .= self::generate_tree_html($full_path);
                $html .= '</li>';
            } else {
                // Only display content.json files
                if ($item === 'content.json') {
                    $link = add_query_arg('file', urlencode($relative_path), admin_url('admin.php?page=migration-assistant'));
                    $data = json_decode(file_get_contents($full_path), true);
                    $title = isset($data['title']) ? $data['title'] : $item;
                    $html .= '<li class="file"><a href="' . esc_url($link) . '">' . esc_html($title) . ' (' . esc_html($relative_path) . ')</a></li>';
                }
            }
        }
        
        $html .= '</ul>';
        
        // Close the div if this is the root call
        if ($dir === MIGRATION_CLEANED_DATA) {
            $html .= '</div>';
        }
        
        return $html;
    }
}
