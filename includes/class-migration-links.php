<?php

class Migration_Links {
    public static function update_links($content) {
        $mappings = get_option('migration_url_mappings', []);

        return preg_replace_callback('/href=["\'](.*?)["\']/', function ($matches) use ($mappings) {
            $old_url = $matches[1];
            return isset($mappings[$old_url]) ? 'href="' . esc_url($mappings[$old_url]) . '"' : 'href="[PENDING URL]"';
        }, $content);
    }
}
