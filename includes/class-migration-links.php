<?php

class Migration_Links {
    public static function update_links($content) {
        $mappings = get_option('migration_url_mappings', []);

        return preg_replace_callback('/href=["\'](.*?)["\']/', function ($matches) use ($mappings) {
            $old_url = $matches[1];
            return isset($mappings[$old_url]) ? 'href="' . esc_url($mappings[$old_url]) . '"' : 'href="[PENDING URL]"';
        }, $content);
    }
    public static function extract_and_store_links($html, $new_page_id) {
        global $wpdb;

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $anchors = $doc->getElementsByTagName('a');

        foreach ($anchors as $anchor) {
            $href = $anchor->getAttribute('href');
            $text = $anchor->textContent;

            if (empty($href) || strpos($href, 'http') !== 0) continue; // Skip relative or malformed links

            // Insert or retrieve link ID from Links table
            $link = $wpdb->get_row($wpdb->prepare("SELECT ID FROM Links WHERE Old_URL = %s", $href));

            if (!$link) {
                $wpdb->insert('Links', [
                    'Old_URL' => $href,
                    'New_URL' => null,
                    'Status' => 'unresolved'
                ]);
                $link_id = $wpdb->insert_id;
            } else {
                $link_id = $link->ID;
            }

            // Insert occurrence (if not already recorded)
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM Link_Occurences WHERE Link_ID = %d AND New_Page_ID = %d",
                $link_id,
                $new_page_id
            ));

            if ($exists == 0) {
                $wpdb->insert('Link_Occurences', [
                    'Link_ID' => $link_id,
                    'New_Page_ID' => $new_page_id,
                    'Location_Info' => null,
                    'Anchor_Text' => $text
                ]);
            }
        }
    }
}
