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

            // Debug the raw href value
            error_log("Processing link: " . $href);
            
            // Clean up any unexpected characters at the beginning
            $href = ltrim($href, '@');
            
            if (empty($href) || strpos($href, 'http') !== 0) {
                error_log("Skipping non-http link: " . $href);
                continue; // Skip relative or malformed links
            }

            // Handle excessively long URLs (255 char limit in database)
            $original_href = $href;
            if (strlen($href) > 255) {
                error_log("Warning: URL exceeds 255 characters: " . $href);
                
                // Option 1: Simply truncate to 255 chars
                // $href = substr($href, 0, 255);
                
                // Option 2: Store a hash of the URL instead
                $href_hash = md5($href);
                $href = "URL_HASH:" . $href_hash;
                
                // Store the full URL in a separate option for reference
                $long_urls = get_option('migration_long_urls', []);
                $long_urls[$href_hash] = $original_href;
                update_option('migration_long_urls', $long_urls);
                
                error_log("Stored long URL with hash: " . $href_hash);
            }

            // Insert or retrieve link ID from Links table
            $link = $wpdb->get_row($wpdb->prepare("SELECT ID FROM Links WHERE Old_URL = %s", $href));

            if (!$link) {
                $type = 'external';
                $status = 'resolved';
                
                // Use case-insensitive matching
                if (stripos($href, 'DocumentCenter') !== false) {
                    error_log("URL matched DocumentCenter: " . $href);
                    $type = 'document';
                    $status = 'unresolved';
                } elseif (stripos($href, 'FormCenter') !== false) {
                    error_log("URL matched FormCenter: " . $href);
                    $type = 'form';
                    $status = 'unresolved';
                } elseif (stripos($href, 'vanburencountymi.gov') !== false) {
                    error_log("URL matched vanburencountymi.gov: " . $href);
                    $type = 'internal';
                    $status = 'unresolved';
                }
                
                // Debug the values before insert
                error_log("Inserting link with Type: " . $type . ", Status: " . $status);
                
                // Make sure all fields are explicitly included in the insert
                $insert_result = $wpdb->insert('Links', [
                    'Old_URL' => $href,
                    'New_URL' => null,
                    'Status' => $status,
                    'Type' => $type
                ]);
                
                // Check if insert was successful
                if ($insert_result === false) {
                    error_log("Database insert error: " . $wpdb->last_error);
                }
                
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
    public static function generate_internal_link_mappings() {
        global $wpdb;
    
        // Get internal links that don't yet have a New_URL
        $internal_links = $wpdb->get_results("SELECT * FROM Links WHERE Type = 'internal' AND New_URL IS NULL");
    
        foreach ($internal_links as $link) {
            $old_url = $link->Old_URL;
    
            // Try to find a matching Old_Page
            $old_page = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM Old_Pages WHERE Old_URL = %s AND Mapped_To IS NOT NULL",
                $old_url
            ));
    
            if ($old_page) {
                $new_page = $wpdb->get_row($wpdb->prepare(
                    "SELECT WP_Page_ID FROM New_Pages WHERE ID = %d",
                    $old_page->Mapped_To
                ));
    
                if ($new_page && $new_page->WP_Page_ID) {
                    $relative_path = get_permalink($new_page->WP_Page_ID);
                    if ($relative_path) {
                        // Strip domain from permalink to make it relative
                        $parsed = wp_parse_url($relative_path);
                        $path = isset($parsed['path']) ? $parsed['path'] : '/';
    
                        // Update the Links table with this relative path
                        $wpdb->update(
                            'Links',
                            ['New_URL' => $path],
                            ['ID' => $link->ID]
                        );
    
                        error_log("Mapped internal link: {$old_url} → {$path}");
                    }
                }
            }
        }
    
        error_log("Finished generating internal New_URLs.");
    }
    
    public static function fix_all_links() {
        global $wpdb;
    
        // Get all resolved links
        $links = $wpdb->get_results("SELECT ID, old_url, new_url FROM Links WHERE new_url IS NOT NULL");
    
        foreach ($links as $link) {
            $occurrences = $wpdb->get_results($wpdb->prepare(
                "SELECT lo.New_Page_ID, np.WP_Page_ID
                 FROM Link_Occurences lo
                 JOIN New_Pages np ON lo.New_Page_ID = np.ID
                 WHERE lo.Link_ID = %d",
                $link->ID
            ));
    
            foreach ($occurrences as $occurrence) {
                $wp_page_id = intval($occurrence->WP_Page_ID);
    
                // Load current post content
                $post = get_post($wp_page_id);
                if (!$post) continue;
    
                // Replace old_url with new_url
                $updated_content = str_replace($link->old_url, $link->new_url, $post->post_content);
    
                if ($updated_content !== $post->post_content) {
                    wp_update_post([
                        'ID' => $wp_page_id,
                        'post_content' => $updated_content
                    ]);
    
                    error_log("Fixed link on page ID {$wp_page_id}: {$link->old_url} → {$link->new_url}");
                }
            }
        }
    
        error_log("Link fixing completed.");
    }
    
}

add_action('admin_init', function() {
    if (isset($_GET['fix_links'])) {
        Migration_Links::fix_all_links();
        wp_die('Link fixing complete!');
    }
});

add_action('admin_init', function() {
    if (isset($_GET['generate_links'])) {
        Migration_Links::generate_internal_link_mappings();
        wp_die('Internal link mapping complete!');
    }
});


