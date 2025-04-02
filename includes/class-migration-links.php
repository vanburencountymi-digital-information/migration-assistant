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
        error_log("Found " . count($internal_links) . " internal links to process.");
        
        foreach ($internal_links as $link) {
            $old_url = $link->Old_URL;
            error_log("Processing Old_URL: " . $old_url);
            
            // Normalize URLs to handle the extra slash issue
            // Create variations of the URL to try matching
            $url_variations = [
                $old_url,
                rtrim($old_url, '/'),
                rtrim($old_url, '/') . '/',
                preg_replace('~(https?://[^/]+)/~', '$1', $old_url),  // Remove slash after domain
                preg_replace('~(https?://[^/]+)~', '$1/', $old_url)   // Add slash after domain
            ];
            
            $old_page = null;
            
            // Try each URL variation until we find a match
            foreach ($url_variations as $url_variant) {
                $old_page = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM Old_Pages WHERE URL = %s AND Mapped_To IS NOT NULL",
                    $url_variant
                ));
                
                if ($old_page) {
                    error_log("Found match with URL variant: " . $url_variant);
                    break;
                }
            }
            
            if (!$old_page) {
                error_log("No matching Old_Page found for any URL variant of: " . $old_url);
                
                // Mark this link as broken
                $wpdb->update(
                    'Links',
                    [
                        'Status' => 'broken',
                        'New_URL' => '#broken-link'  // Special marker for broken links
                    ],
                    ['ID' => $link->ID]
                );
                
                continue;
            }
            
            $new_page = $wpdb->get_row($wpdb->prepare(
                "SELECT WP_Page_ID FROM New_Pages WHERE ID = %d",
                $old_page->Mapped_To
            ));
            
            if (!$new_page || !$new_page->WP_Page_ID) {
                error_log("No matching New_Page found for Old_Page ID: " . $old_page->Mapped_To);
                
                // Mark this link as broken too
                $wpdb->update(
                    'Links',
                    [
                        'Status' => 'broken',
                        'New_URL' => '#broken-link'  // Special marker for broken links
                    ],
                    ['ID' => $link->ID]
                );
                
                continue;
            }
            
            $relative_path = get_permalink($new_page->WP_Page_ID);
            if ($relative_path) {
                // Strip domain from permalink to make it relative
                $parsed = wp_parse_url($relative_path);
                $path = isset($parsed['path']) ? $parsed['path'] : '/';

                // Update the Links table with this relative path
                $wpdb->update(
                    'Links',
                    [
                        'New_URL' => $path,
                        'Status' => 'resolved'
                    ],
                    ['ID' => $link->ID]
                );

                error_log("Mapped internal link: {$old_url} → {$path}");
            }
        }
    
        error_log("Finished generating internal New_URLs.");
    }
    
    public static function fix_all_links() {
        global $wpdb;
    
        // Get all links with a New_URL (including broken ones)
        $links = $wpdb->get_results("SELECT ID, Old_URL, New_URL, Status FROM Links WHERE New_URL IS NOT NULL");
    
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
    
                if ($link->Status === 'broken') {
                    // For broken links, replace with a visually distinct marker
                    $broken_link_html = '<a href="#broken-link" class="broken-link" title="Original URL: ' . esc_attr($link->Old_URL) . '" style="color: red; text-decoration: line-through;">';
                    
                    // Find the original link and replace it with our marked-up version
                    $pattern = '/<a[^>]*href=["\']' . preg_quote($link->Old_URL, '/') . '["\'][^>]*>(.*?)<\/a>/i';
                    $replacement = $broken_link_html . '$1</a> <span class="broken-link-note" style="color: red; font-size: 0.8em;">[Broken Link]</span>';
                    
                    $updated_content = preg_replace($pattern, $replacement, $post->post_content);
                } else {
                    // For working links, just update the URL
                    $updated_content = str_replace($link->Old_URL, $link->New_URL, $post->post_content);
                    
                    // Update the Status to 'resolved' if it's not already
                    if ($link->Status !== 'resolved') {
                        $wpdb->update(
                            'Links',
                            ['Status' => 'resolved'],
                            ['ID' => $link->ID]
                        );
                        error_log("Updated link status to 'resolved' for ID {$link->ID}");
                    }
                }
    
                if ($updated_content !== $post->post_content) {
                    wp_update_post([
                        'ID' => $wp_page_id,
                        'post_content' => $updated_content
                    ]);
    
                    $status_text = ($link->Status === 'broken') ? " (marked as broken)" : "";
                    error_log("Fixed link on page ID {$wp_page_id}: {$link->Old_URL} → {$link->New_URL}{$status_text}");
                }
            }
        }
    
        // Add some CSS to highlight broken links in the admin area
        add_action('admin_head', function() {
            echo '<style>
                .broken-link { color: red !important; text-decoration: line-through !important; }
                .broken-link-note { color: red; font-size: 0.8em; font-weight: bold; }
            </style>';
        });
    
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


