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
}
