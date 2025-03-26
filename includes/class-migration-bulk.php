<?php

class Migration_Bulk {
    public static function bulk_build_pages() {
        $parent_id = intval($_POST['parent_id']);
        $hierarchy = get_option('migration_page_hierarchy', []);

        foreach ($hierarchy as $old_path => $new_path) {
            if ($parent_id == get_post_field('post_parent', $new_path)) {
                $data = json_decode(file_get_contents(MIGRATION_CLEANED_DATA . '/' . $old_path), true);
                $new_page_id = wp_insert_post([
                    'post_title' => $data['title'],
                    'post_content' => $data['cleaned_content'],
                    'post_status' => 'draft',
                    'post_parent' => $parent_id,
                    'post_type' => 'page'
                ]);
                Migration_Functions::store_page_hierarchy($old_path, get_permalink($new_page_id));
            }
        }

        echo "Bulk build complete!";
        wp_die();
    }

    public static function populate_old_pages_from_cleaned_data() {
        global $wpdb;
    
        $dir = MIGRATION_CLEANED_DATA;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
    
        foreach ($iterator as $fileinfo) {
            if ($fileinfo->getExtension() !== 'json') continue;
    
            $path = $fileinfo->getPathname();
            $relative_path = ltrim(str_replace($dir, '', $path), '/');
            $json = file_get_contents($path);
            $data = json_decode($json, true);
    
            if (!is_array($data)) {
                error_log("Failed to decode JSON in file: $relative_path");
                continue;
            }
    
            $title = isset($data['title']) ? $data['title'] : pathinfo($fileinfo->getFilename(), PATHINFO_FILENAME);
            $url = isset($data['url']) ? $data['url'] : pathinfo($fileinfo->getFilename(), PATHINFO_FILENAME);
    
            // Check if it already exists
            $exists = $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM Old_Pages WHERE URL = %s", $url)
            );
    
            if ($exists > 0) {
                error_log("Skipping existing: $url");
                continue;
            }
    
            $wpdb->insert('Old_Pages', [
                'Title' => $title,
                'URL' => $url,
                'Status' => 'unmerged',
                'Mapped_To' => null
            ]);
    
            error_log("Inserted old page: $title ($url)");
        }
    
        error_log("Old pages population complete.");
    }
}
add_action('admin_init', function() {
    if (isset($_GET['populate_old_pages'])) {
        Migration_Bulk::populate_old_pages_from_cleaned_data();
        wp_die('Old pages populated!');
    }
});
