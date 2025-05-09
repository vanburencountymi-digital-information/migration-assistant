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
    
        error_log("Starting fix_all_links process");
        
        // Get all links with a New_URL (including broken ones)
        $links = $wpdb->get_results("SELECT ID, Old_URL, New_URL, Status FROM Links WHERE New_URL IS NOT NULL");
        error_log("Found " . count($links) . " links with New_URL values to process");
    
        foreach ($links as $link) {
            error_log("Processing link ID: {$link->ID}, Old_URL: {$link->Old_URL}, New_URL: {$link->New_URL}, Status: {$link->Status}");
            
            $occurrences = $wpdb->get_results($wpdb->prepare(
                "SELECT lo.New_Page_ID, np.WP_Page_ID
                 FROM Link_Occurences lo
                 JOIN New_Pages np ON lo.New_Page_ID = np.ID
                 WHERE lo.Link_ID = %d",
                $link->ID
            ));
            
            error_log("Found " . count($occurrences) . " occurrences of this link");
    
            foreach ($occurrences as $occurrence) {
                $wp_page_id = intval($occurrence->WP_Page_ID);
                error_log("Processing occurrence on WP page ID: {$wp_page_id}");
    
                // Load current post content
                $post = get_post($wp_page_id);
                if (!$post) {
                    error_log("Could not load post with ID {$wp_page_id}");
                    continue;
                }
    
                $updated_content = $post->post_content;
                $made_changes = false;
    
                if ($link->Status === 'broken') {
                    error_log("Link is marked as broken, will mark it visually in content");
                    
                    // For broken links, replace with a visually distinct marker
                    $broken_link_html = '<a href="#broken-link" class="broken-link" title="Original URL: ' . esc_attr($link->Old_URL) . '" style="color: red; text-decoration: line-through;">';
                    
                    // Find the original link and replace it with our marked-up version
                    $pattern = '/<a[^>]*href=["\']' . preg_quote($link->Old_URL, '/') . '["\'][^>]*>(.*?)<\/a>/i';
                    $replacement = $broken_link_html . '$1</a> <span class="broken-link-note" style="color: red; font-size: 0.8em;">[Broken Link]</span>';
                    
                    // Log the pattern we're searching for
                    error_log("Searching for pattern: " . $pattern);
                    
                    $new_content = preg_replace($pattern, $replacement, $updated_content);
                    if ($new_content !== $updated_content) {
                        error_log("Found and marked broken link in content");
                        $updated_content = $new_content;
                        $made_changes = true;
                    } else {
                        error_log("Did not find the broken link pattern in content");
                    }
                } else {
                    error_log("Link is not broken, attempting to update it");
                    
                    // For working links, first try direct URL replacement
                    $old_href = 'href="' . $link->Old_URL . '"';
                    $new_href = 'href="' . $link->New_URL . '"';
                    
                    error_log("Attempting direct replacement: {$old_href} -> {$new_href}");
                    
                    $new_content = str_replace($old_href, $new_href, $updated_content);
                    
                    if ($new_content !== $updated_content) {
                        error_log("Direct replacement successful");
                        $updated_content = $new_content;
                        $made_changes = true;
                    } else {
                        error_log("Direct replacement failed, trying title attribute pattern");
                        
                        // If direct replacement didn't work, try finding links with the title attribute
                        // First, let's get the actual title attribute value as it would appear in HTML
                        $title_value = "Original URL: " . $link->Old_URL;
                        error_log("Looking for title attribute with value: " . $title_value);
                        
                        // Use a more robust approach with DOM parsing instead of regex for complex URLs
                        $dom = new DOMDocument();
                        libxml_use_internal_errors(true); // Suppress HTML5 parsing errors
                        $dom->loadHTML($updated_content);
                        libxml_clear_errors();
                        
                        $xpath = new DOMXPath($dom);
                        $broken_links = $xpath->query("//a[contains(@title, 'Original URL: " . addslashes($link->Old_URL) . "') and @href='#broken-link']");
                        
                        error_log("Found " . $broken_links->length . " broken links with matching title attribute");
                        
                        if ($broken_links->length > 0) {
                            // We found matches, now we need to replace them in the original HTML
                            foreach ($broken_links as $broken_link) {
                                error_log("Processing broken link: " . $dom->saveHTML($broken_link));
                                
                                // Create a new link with the correct href
                                $new_link = $dom->createElement('a', $broken_link->textContent);
                                $new_link->setAttribute('href', $link->New_URL);
                                
                                // Replace the old link with the new one
                                $broken_link->parentNode->replaceChild($new_link, $broken_link);
                                
                                // Find and remove the [Broken Link] span that follows
                                $next_sibling = $new_link->nextSibling;
                                while ($next_sibling) {
                                    // Check if this is a text node (whitespace) or an element
                                    if ($next_sibling->nodeType === XML_TEXT_NODE) {
                                        // Just move to the next sibling
                                        $next_sibling = $next_sibling->nextSibling;
                                        continue;
                                    }
                                    
                                    // If we found an element node
                                    if ($next_sibling->nodeType === XML_ELEMENT_NODE) {
                                        // Check if it's our span
                                        if ($next_sibling->nodeName === 'span' && 
                                            strpos($next_sibling->getAttribute('class'), 'broken-link-note') !== false) {
                                            error_log("Found and removing broken-link-note span");
                                            $to_remove = $next_sibling;
                                            $next_sibling = $next_sibling->nextSibling; // Move to next before removing
                                            $to_remove->parentNode->removeChild($to_remove);
                                        } else {
                                            // If it's some other element, we're done looking
                                            break;
                                        }
                                    } else {
                                        // If it's not a text node or element node, move on
                                        $next_sibling = $next_sibling->nextSibling;
                                    }
                                }
                            }
                            
                            // Get the updated HTML
                            $new_content = $dom->saveHTML();
                            error_log("DOM replacement completed");
                            
                            if ($new_content !== $updated_content) {
                                error_log("Fixed previously broken link using DOM parsing");
                                $updated_content = $new_content;
                                $made_changes = true;
                            } else {
                                error_log("DOM replacement didn't change content despite finding matches");
                            }
                        } else {
                            error_log("No broken links found with DOM parsing, trying fallback regex approach");
                            
                            // Try a more lenient pattern that just looks for the title attribute and href=#broken-link
                            $lenient_pattern = '/<a[^>]*title=["\'][^"\']*' . preg_quote($link->Old_URL, '/') . '[^"\']*["\'][^>]*href=["\']#broken-link["\'][^>]*>(.*?)<\/a>/i';
                            error_log("Trying lenient pattern: " . $lenient_pattern);
                            
                            if (preg_match($lenient_pattern, $updated_content, $matches)) {
                                error_log("Found match with lenient pattern. Match: " . print_r($matches, true));
                                
                                $lenient_replacement = '<a href="' . esc_attr($link->New_URL) . '">' . '$1</a>';
                                $new_content = preg_replace($lenient_pattern, $lenient_replacement, $updated_content);
                                
                                if ($new_content !== $updated_content) {
                                    error_log("Lenient pattern replacement successful");
                                    $updated_content = $new_content;
                                    $made_changes = true;
                                } else {
                                    error_log("Lenient pattern replacement failed despite finding a match");
                                }
                            } else {
                                error_log("No match found with lenient pattern either");
                            }
                        }
                    }
                    
                    // Update the Status to 'resolved' if it's not already
                    if ($link->Status !== 'resolved') {
                        if ($made_changes) {
                            error_log("Updating link status to 'resolved' for ID {$link->ID} because changes were made");
                            $wpdb->update(
                                'Links',
                                ['Status' => 'resolved'],
                                ['ID' => $link->ID]
                            );
                        } else {
                            error_log("NOT updating link status to 'resolved' for ID {$link->ID} because no changes were made");
                        }
                    }
                }
    
                if ($made_changes) {
                    error_log("Updating post content for page ID {$wp_page_id}");
                    wp_update_post([
                        'ID' => $wp_page_id,
                        'post_content' => $updated_content
                    ]);
    
                    $status_text = ($link->Status === 'broken') ? " (marked as broken)" : "";
                    error_log("Fixed link on page ID {$wp_page_id}: {$link->Old_URL} → {$link->New_URL}{$status_text}");
                } else {
                    error_log("No changes made to page ID {$wp_page_id}");
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
    
    public static function fix_specific_link($link_id) {
        global $wpdb;
        
        error_log("Starting fix_specific_link for link ID: $link_id");
        
        // Get the link details
        $link = $wpdb->get_row($wpdb->prepare("SELECT ID, Old_URL, New_URL, Status FROM Links WHERE ID = %d", $link_id));
        
        if (!$link || !$link->New_URL) {
            error_log("Link not found or has no New_URL");
            return 0;
        }
        
        $fixed_count = 0;
        
        // Get all occurrences of this link
        $occurrences = $wpdb->get_results($wpdb->prepare(
            "SELECT lo.New_Page_ID, np.WP_Page_ID
             FROM Link_Occurences lo
             JOIN New_Pages np ON lo.New_Page_ID = np.ID
             WHERE lo.Link_ID = %d",
            $link_id
        ));
        
        error_log("Found " . count($occurrences) . " occurrences of link ID: $link_id");
        
        foreach ($occurrences as $occurrence) {
            $wp_page_id = intval($occurrence->WP_Page_ID);
            
            // Load current post content
            $post = get_post($wp_page_id);
            if (!$post) continue;
            
            $updated_content = $post->post_content;
            $made_changes = false;
            
            // Use DOM parsing to find and update the link
            $dom = new DOMDocument();
            libxml_use_internal_errors(true); // Suppress HTML5 parsing errors
            $dom->loadHTML(mb_convert_encoding($updated_content, 'HTML-ENTITIES', 'UTF-8'));
            libxml_clear_errors();
            
            $xpath = new DOMXPath($dom);
            
            // Try to find the link by its original URL
            $links_to_update = $xpath->query("//a[contains(@href, '" . addslashes($link->Old_URL) . "')]");
            
            // If not found, try to find it by title attribute (for previously marked broken links)
            if ($links_to_update->length === 0) {
                $links_to_update = $xpath->query("//a[contains(@title, 'Original URL: " . addslashes($link->Old_URL) . "') and @href='#broken-link']");
            }
            
            error_log("Found " . $links_to_update->length . " links to update in post ID: $wp_page_id");
            
            if ($links_to_update->length > 0) {
                foreach ($links_to_update as $link_to_update) {
                    // Update the href attribute
                    $link_to_update->setAttribute('href', $link->New_URL);
                    
                    // Remove any broken link styling
                    $link_to_update->removeAttribute('class');
                    $link_to_update->removeAttribute('style');
                    
                    // Find and remove the [Broken Link] span if it exists
                    $next_sibling = $link_to_update->nextSibling;
                    while ($next_sibling) {
                        if ($next_sibling->nodeType === XML_TEXT_NODE) {
                            $next_sibling = $next_sibling->nextSibling;
                            continue;
                        }
                        
                        if ($next_sibling->nodeType === XML_ELEMENT_NODE) {
                            if ($next_sibling->nodeName === 'span' && 
                                strpos($next_sibling->getAttribute('class'), 'broken-link-note') !== false) {
                                $to_remove = $next_sibling;
                                $next_sibling = $next_sibling->nextSibling;
                                $to_remove->parentNode->removeChild($to_remove);
                            } else {
                                break;
                            }
                        } else {
                            $next_sibling = $next_sibling->nextSibling;
                        }
                    }
                }
                
                // Get the updated HTML
                $new_content = $dom->saveHTML();
                
                if ($new_content !== $updated_content) {
                    wp_update_post([
                        'ID' => $wp_page_id,
                        'post_content' => $new_content
                    ]);
                    
                    $fixed_count++;
                    error_log("Fixed link on page ID {$wp_page_id}: {$link->Old_URL} → {$link->New_URL}");
                }
            }
        }
        
        // Update the link status to resolved
        $wpdb->update(
            'Links',
            ['Status' => 'resolved'],
            ['ID' => $link_id]
        );
        
        error_log("Link fixing completed for link ID: $link_id. Fixed $fixed_count occurrences.");
        
        return $fixed_count;
    }

    /**
     * Fix URLs with incorrect path segments
     * 
     * This function specifically targets URLs with the incorrect "departments-offices" segment
     * in court-related URLs and removes it.
     */
    public static function fix_court_urls() {
        global $wpdb;
        
        error_log("Starting fix_court_urls process");
        
        // Find all links with the incorrect pattern
        $incorrect_links = $wpdb->get_results("
            SELECT ID, Old_URL, New_URL 
            FROM Links 
            WHERE New_URL LIKE '/departments/departments-offices/county-courts/%'
        ");
        
        error_log("Found " . count($incorrect_links) . " links with incorrect court URLs");
        
        $fixed_count = 0;
        
        foreach ($incorrect_links as $link) {
            // Create the corrected URL by removing the extra segment
            $corrected_url = str_replace('/departments/departments-offices/county-courts/', '/departments/county-courts/', $link->New_URL);
            
            error_log("Fixing link ID {$link->ID}: {$link->New_URL} → {$corrected_url}");
            
            // Update the link in the database and set status to unresolved to ensure it gets processed
            $wpdb->update(
                'Links',
                [
                    'New_URL' => $corrected_url,
                    'Status' => 'unresolved'  // Set to unresolved so fix_all_links will process it
                ],
                ['ID' => $link->ID]
            );
            
            $fixed_count++;
        }
        
        error_log("Fixed $fixed_count links with incorrect court URLs and set them to unresolved status");
        
        // Now run fix_all_links to update the content with the corrected URLs
        if ($fixed_count > 0) {
            self::fix_all_links();
        }
        
        return $fixed_count;
    }

    /**
     * Fix court URLs in content that have the incorrect path segment
     */
    public static function fix_court_urls_in_content() {
        global $wpdb;
        
        error_log("Starting fix_court_urls_in_content process");
        
        // Get all links with the corrected court URLs
        $court_links = $wpdb->get_results("
            SELECT ID, Old_URL, New_URL 
            FROM Links 
            WHERE New_URL LIKE '/departments/county-courts/%'
        ");
        
        error_log("Found " . count($court_links) . " court links to process");
        
        $fixed_count = 0;
        $page_count = 0;
        
        foreach ($court_links as $link) {
            // Construct the incorrect URL that's currently in the content
            $incorrect_url = str_replace('/departments/county-courts/', '/departments/departments-offices/county-courts/', $link->New_URL);
            
            error_log("Looking to replace incorrect URL: {$incorrect_url} with correct URL: {$link->New_URL}");
            
            // Get all occurrences of this link
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
                
                // Check if the incorrect URL exists in the content
                if (strpos($post->post_content, $incorrect_url) === false) {
                    continue; // Skip if the incorrect URL isn't found
                }
                
                // Replace the incorrect URL with the correct one
                $updated_content = str_replace(
                    'href="' . $incorrect_url . '"', 
                    'href="' . $link->New_URL . '"', 
                    $post->post_content
                );
                
                if ($updated_content !== $post->post_content) {
                    // Update the post content
                    wp_update_post([
                        'ID' => $wp_page_id,
                        'post_content' => $updated_content
                    ]);
                    
                    $fixed_count++;
                    error_log("Fixed court URL on page ID {$wp_page_id}: {$incorrect_url} → {$link->New_URL}");
                }
            }
            
            // Update the link status to resolved
            $wpdb->update(
                'Links',
                ['Status' => 'resolved'],
                ['ID' => $link->ID]
            );
            
            $page_count++;
        }
        
        error_log("Court URL fixing completed. Fixed {$fixed_count} URLs across {$page_count} pages.");
        
        return [
            'fixed_count' => $fixed_count,
            'page_count' => $page_count
        ];
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


