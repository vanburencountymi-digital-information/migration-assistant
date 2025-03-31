<?php

class Migration_Pages {
    public static function get_parent_page_dropdown() {
        $pages = get_pages(['post_status' => 'publish']);
    
        echo '<label for="parent_page">Select Parent Page:</label>';
        echo '<select id="parent_page">';
        echo '<option value="">-- Select Parent --</option>';
        foreach ($pages as $page) {
            echo '<option value="' . esc_attr($page->ID) . '">' . esc_html($page->post_title) . '</option>';
        }
        echo '</select>';
    }
    
    public static function display_file($relative_path) {
        $file = MIGRATION_CLEANED_DATA . $relative_path;
    
        if (!file_exists($file)) {
            echo '<h2>File not found</h2>';
            echo '<p>The file "' . $relative_path . '" does not exist in the cleaned data directory.</p>';
            return;
        }
    
        $data = json_decode(file_get_contents($file), true);
        $title = isset($data['title']) ? esc_html($data['title']) : 'Untitled';
        $content = isset($data['cleaned_content']) ? Migration_Links::update_links($data['cleaned_content']) : '';
    
        echo '<h2>' . $title . '</h2>';
        echo '<div>' . $content . '</div>';
        
        // Dropdown to select an existing WordPress page
        echo '<label for="existing_page">Select Destination Page:</label>';
        self::get_existing_pages_dropdown();
    
        // Template selection for the page
        Migration_Templates::display_template_selection($relative_path);
    
        // Find subpages and get their titles
        $subpages = self::find_subpages($relative_path);
        $subpage_count = count($subpages);
        
        // Subpage checkbox option
        echo '<div class="subpage-options">';
        echo '<input type="checkbox" id="build-subpages-checkbox" name="build_subpages" checked>';
        echo '<label for="build-subpages-checkbox">Build subpages too</label>';
        echo '<span class="subpage-count">(' . $subpage_count . ' subpages found)</span>';
        
        // Display subpage titles if any exist
        if ($subpage_count > 0) {
            echo '<div class="subpage-list" style="margin-top: 10px; margin-left: 20px;">';
            echo '<strong>Subpages to be created:</strong>';
            echo '<ul style="margin-top: 5px;">';
            
            foreach ($subpages as $subpage_path) {
                $subpage_file = MIGRATION_CLEANED_DATA . $subpage_path;
                if (file_exists($subpage_file)) {
                    $subpage_data = json_decode(file_get_contents($subpage_file), true);
                    $subpage_title = isset($subpage_data['title']) ? esc_html($subpage_data['title']) : 'Untitled Subpage';
                    echo '<li>' . $subpage_title . '</li>';
                } else {
                    echo '<li><em>Unknown subpage</em>: ' . esc_html($subpage_path) . '</li>';
                }
            }
            
            echo '</ul>';
            echo '</div>';
        }
        
        echo '</div>';
    
        // Merge Content Button
        echo '<button id="merge-content" data-file="' . esc_attr($relative_path) . '">Merge Content</button>';
        
        // Add JavaScript for handling the new page option
        ?>
        <script>
        jQuery(document).ready(function($) {
            // Show/hide new page title input based on dropdown selection
            $('#existing_page').on('change', function() {
                if ($(this).val() === 'new_page') {
                    $('#new-page-title-container').show();
                } else {
                    $('#new-page-title-container').hide();
                }
            });
        });
        </script>
        <?php
    }
    

    public static function get_existing_pages_dropdown() {
        $pages = get_pages(['post_status' => 'publish']); // Fetch published pages
    
        echo '<select id="existing_page">';
        echo '<option value="new_page">-- Create New Page --</option>';
        echo '<option value="">-- Select Existing Page --</option>';
        foreach ($pages as $page) {
            echo '<option value="' . esc_attr($page->ID) . '">' . esc_html($page->post_title) . '</option>';
        }
        echo '</select>';
    }
    private static $block_map = [
        'h1' => 'core/heading', 
        'h2' => 'core/heading',
        'h3' => 'core/heading',
        'h4' => 'core/heading',
        'h5' => 'core/heading',
        'h6' => 'core/heading',
        'p' => 'core/paragraph',
        'span' => 'core/paragraph',
        'strong' => 'core/paragraph',
        'em' => 'core/paragraph',
        'ul' => 'core/list',
        'ol' => 'core/list',
        'li' => 'core/list-item',
        'img' => 'core/image',
        'blockquote' => 'core/quote',
        'pre' => 'core/code'
    ];
    private static function remove_unnecessary_divs(&$doc) {
        $xpath = new DOMXPath($doc);
        $divs = $xpath->query('//div');
    
        foreach ($divs as $div) {
            if ($div->childNodes->length === 1 && $div->firstChild->nodeType === XML_TEXT_NODE) {
                // If the div contains only text, replace it with a paragraph
                $p = $doc->createElement("p", $div->textContent);
                $div->parentNode->replaceChild($p, $div);
            } elseif ($div->childNodes->length === 1 && $div->firstChild->nodeName === "a") {
                // If the div contains only a link, remove the div wrapper
                $div->parentNode->replaceChild($div->firstChild, $div);
            } elseif ($div->childNodes->length === 1 && $div->firstChild->nodeName === "img") {
                // If the div contains only an image, remove the div wrapper
                $div->parentNode->replaceChild($div->firstChild, $div);
            }
        }
    }
    private static function handle_paragraph_links($element) {
        $content = '';
        foreach ($element->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $content .= $node->textContent;
            } elseif ($node->nodeName === 'a') {
                $href = $node->getAttribute('href');
                $text = $node->textContent;
                if (!empty($href) && !empty($text)) {
                    // Convert links properly with `data-type="link"` as seen in Gutenberg
                    $content .= '<a href="' . esc_url($href) . '" data-type="link" data-id="' . esc_url($href) . '">' . esc_html($text) . '</a>';
                }
            }
        }
        return '<p>' . trim($content) . '</p>';
    }
    
    public static function clean_html($html) {
        // Remove all opening <div> tags
        $html = preg_replace('/<div[^>]*>/', '', $html);

        // Remove all closing </div> tags
        $html = preg_replace('/<\/div>/', '', $html);

        // Replace multiple newlines with a single newline
        $html = preg_replace('/\n+/', "\n", $html);
        
        return $html;
    }
    
    public static function rewrite_with_ai($html) {
        $api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : null;
    
        if (!$api_key) {
            error_log("Missing OpenAI API key. Define OPENAI_API_KEY.");
            return $html; // Fail gracefully
        }
    
        $endpoint = 'https://api.openai.com/v1/chat/completions';
    
        // You can fine-tune this system prompt to guide tone, reading level, etc.
        $system_prompt = <<<EOT
    You are a professional web content editor for a local government website.
    Rewrite the following HTML content to:
    - Use a clear, friendly, and consistent tone
    - Improve readability and accessibility (aim for grade 9 reading level)
    - Keep or restructure headings for clarity and navigation
    - Preserve any valid HTML and return it as cleaned, structured HTML
    
    Only return the revised HTML — do not include commentary or explanation.
    EOT;
    
        $payload = [
            'model' => 'gpt-4',
            'temperature' => 0.4,
            'messages' => [
                ['role' => 'system', 'content' => $system_prompt],
                ['role' => 'user', 'content' => $html]
            ]
        ];
    
        $response = wp_remote_post($endpoint, [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode($payload),
            'timeout' => 60,
        ]);
    
        if (is_wp_error($response)) {
            error_log("OpenAI API request failed: " . $response->get_error_message());
            return $html;
        }
    
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
    
        if ($status_code !== 200) {
            error_log("OpenAI API returned non-200 status: $status_code — Response body: $body");
            return $html;
        }
    
        $data = json_decode($body, true);
    
        if (!isset($data['choices'][0]['message']['content'])) {
            error_log("Unexpected response structure from OpenAI: " . print_r($data, true));
            return $html;
        }
    
        $rewritten = trim($data['choices'][0]['message']['content']);
        error_log("Successfully received rewritten content from OpenAI");
        return $rewritten;
    }
    
    public static function convert_html_to_blocks($html) {
        // Clean the HTML before processing
        $html = self::clean_html($html);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding('<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();

        $blocks = [];
        $xpath = new DOMXPath($doc);
        
        // Process block-level elements only
        $blockElements = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6|//p|//ul|//ol|//blockquote|//pre|//img');
        
        foreach ($blockElements as $element) {
            $tag = strtolower($element->nodeName);
            
            // Skip if this element is a child of another block element we'll process
            $parent = $element->parentNode;
            $skipThisElement = false;
            while ($parent && $parent->nodeType === XML_ELEMENT_NODE && $parent->nodeName !== 'div') {
                $parentTag = strtolower($parent->nodeName);
                // Only skip if parent is blockquote or pre, but allow ul/ol to be processed
                if (in_array($parentTag, ['blockquote', 'pre'])) {
                    $skipThisElement = true;
                    break;
                }
                $parent = $parent->parentNode;
            }
            
            if ($skipThisElement) {
                continue;
            }
            
            // For all elements, create a paragraph block with the HTML content
            // This simplifies handling and preserves all formatting
            $innerHTML = $doc->saveHTML($element);
            
            // For images, use the image block
            if ($tag === 'img') {
                $src = $element->getAttribute('src');
                if (!empty($src)) {
                    $blocks[] = [
                        'blockName' => 'core/image',
                        'attrs' => ['src' => esc_url($src)],
                        'innerBlocks' => [],
                        'innerHTML' => $innerHTML,
                        'innerContent' => [$innerHTML]
                    ];
                }
            } 
            // For unordered lists, use the list block with the correct attributes
            else if ($tag === 'ul') {
                $blocks[] = [
                    'blockName' => 'core/list',
                    'attrs' => ['ordered' => false],
                    'innerBlocks' => [],
                    'innerHTML' => $innerHTML,
                    'innerContent' => [$innerHTML]
                ];
            }
            // For ordered lists, use the list block with the correct attributes
            else if ($tag === 'ol') {
                $blocks[] = [
                    'blockName' => 'core/list',
                    'attrs' => ['ordered' => true],
                    'innerBlocks' => [],
                    'innerHTML' => $innerHTML,
                    'innerContent' => [$innerHTML]
                ];
            }
            else {
                // For everything else, use paragraph blocks with HTML content
                $blocks[] = [
                    'blockName' => 'core/paragraph',
                    'attrs' => [],
                    'innerBlocks' => [],
                    'innerHTML' => $innerHTML,
                    'innerContent' => [$innerHTML]
                ];
            }
        }
        
        // Remove the first title block
        if ($blocks && count($blocks) > 1) {
            $blocks = array_slice($blocks, 1);
        }
        
        // Process blocks to merge consecutive paragraphs
        $mergedBlocks = [];
        $currentParagraph = null;

        foreach ($blocks as $block) {
            if ($block['blockName'] === 'core/paragraph') {
                if ($currentParagraph === null) {
                    $currentParagraph = $block;
                } else {
                    // Merge paragraph content, including those with links
                    $currentParagraph['innerHTML'] .= ' ' . $block['innerHTML'];
                    $currentParagraph['innerContent'][0] .= ' ' . $block['innerContent'][0];
                }
            } else {
                if ($currentParagraph !== null) {
                    $mergedBlocks[] = $currentParagraph;
                    $currentParagraph = null;
                }
                $mergedBlocks[] = $block;
            }
        }

        // Add the last paragraph if it exists
        if ($currentParagraph !== null) {
            $mergedBlocks[] = $currentParagraph;
        }

        return serialize_blocks($mergedBlocks);
    }
    
    public static function merge_content_into_page() {
        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        // Check if this is a request to clear a lock
        if (isset($_POST['clear_lock']) && $_POST['clear_lock'] === 'true') {
            $file = isset($_POST['file']) ? $_POST['file'] : '';
            if (!empty($file)) {
                $lock_key = 'parent_page_lock_' . md5($file);
                $_SESSION[$lock_key] = false;
                error_log("Manually cleared lock for file: " . $file);
                wp_send_json_success(['message' => 'Lock cleared successfully']);
                return;
            }
        }
        
        // Dump all POST data for debugging
        error_log("POST data received: " . print_r($_POST, true));
        
        // Check if this is a legacy format request (no process_type)
        if (!isset($_POST['process_type'])) {
            error_log("Legacy format request detected - redirecting to new format");
            // Return an error to force the client to use the new format
            // wp_send_json_error(['message' => 'You are hitting this error that comes from checking the process type.']);
            return;
        }
        
        $page_id = isset($_POST['page_id']) ? $_POST['page_id'] : '';
        $file_path = isset($_POST['file']) ? MIGRATION_CLEANED_DATA . $_POST['file'] : '';
        $build_subpages = isset($_POST['build_subpages']) && $_POST['build_subpages'] === 'true';
        $template = isset($_POST['template']) ? sanitize_text_field($_POST['template']) : '';
        $process_type = $_POST['process_type']; // We know this exists now
        
        error_log("Merge content request received");
        error_log("Process type: " . $process_type);
        error_log("Page ID/option: " . $page_id);
        error_log("File path: " . $file_path);
        error_log("Build subpages flag: " . ($build_subpages ? 'true' : 'false'));
        error_log("Template: " . $template);
        
        if (!file_exists($file_path)) {
            error_log("File not found: " . $file_path);
            wp_send_json_error(['message' => 'File not found']);
            return;
        }

        $data = json_decode(file_get_contents($file_path), true);
        
        // Process parent page
        if ($process_type === 'parent') {
            // Create a unique lock key for this file
            $lock_key = 'parent_page_lock_' . md5($_POST['file']);
            
            // Check if this file is already being processed
            if (isset($_SESSION[$lock_key]) && $_SESSION[$lock_key] === true) {
                error_log("Parent page already being processed for file: " . $_POST['file']);
                wp_send_json_error([
                    'message' => 'This page is already being processed. Please wait.',
                    'lock_key' => $lock_key,
                    'file' => $_POST['file'],
                    'can_clear' => true
                ]);
                return;
            }
            
            // Set the lock
            $_SESSION[$lock_key] = true;
            
            try {
                $result = self::process_parent_page($page_id, $file_path, $data, $template);
                
                // Release the lock
                $_SESSION[$lock_key] = false;
                
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                    return;
                }
                
                // Return success with the parent page ID for the next step
                wp_send_json_success([
                    'message' => 'Parent page processed successfully',
                    'parent_page_id' => $result['page_id'],
                    'new_page_db_id' => $result['new_page_db_id'],
                    'has_subpages' => $build_subpages && count(self::find_subpages($_POST['file'])) > 0
                ]);
            } catch (Exception $e) {
                // Release the lock in case of error
                $_SESSION[$lock_key] = false;
                wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
                return;
            }
        } 
        // Process subpages
        else if ($process_type === 'subpages') {
            $parent_page_id = intval($_POST['parent_page_id']);
            
            if (!$parent_page_id) {
                wp_send_json_error(['message' => 'Invalid parent page ID']);
                return;
            }
            
            // Create a unique lock key for subpages of this parent
            $lock_key = 'subpages_lock_' . $parent_page_id;
            
            // Check if these subpages are already being processed
            if (isset($_SESSION[$lock_key]) && $_SESSION[$lock_key] === true) {
                error_log("Subpages already being processed for parent ID: " . $parent_page_id);
                wp_send_json_error([
                    'message' => 'Subpages are already being processed. Please wait.',
                    'lock_key' => $lock_key,
                    'parent_id' => $parent_page_id,
                    'can_clear' => true
                ]);
                return;
            }
            
            // Set the lock
            $_SESSION[$lock_key] = true;
            
            try {
                $result = self::process_subpages($parent_page_id, $_POST['file'], $template);
                
                // Release the lock
                $_SESSION[$lock_key] = false;
                
                if (is_wp_error($result)) {
                    wp_send_json_error(['message' => $result->get_error_message()]);
                    return;
                }
                
                wp_send_json_success(['message' => 'Subpages processed successfully', 'count' => $result]);
            } catch (Exception $e) {
                // Release the lock in case of error
                $_SESSION[$lock_key] = false;
                wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
                return;
            }
        } else {
            wp_send_json_error(['message' => 'Invalid process type']);
        }
    }
    
    /**
     * Process the parent page creation or update
     */
    private static function process_parent_page($page_id, $file_path, $data, $template) {
        global $wpdb;
        
        $title = isset($data['title']) ? $data['title'] : 'Untitled';
        $cleaned_content = isset($data['cleaned_content']) ? $data['cleaned_content'] : '';
        
        // Convert HTML to structured Gutenberg blocks
        $blocks = self::convert_html_to_blocks($cleaned_content);
        
        $new_page_db_id = null;
        
        // Check if we need to create a new page
        if ($page_id === 'new_page') {
            // Use the title from the content.json file, or from the input if provided
            $new_page_title = !empty($_POST['new_page_title']) ? 
                              sanitize_text_field($_POST['new_page_title']) : $title;
            
            error_log("Creating new page with title: " . $new_page_title);
            
            // Create a new page
            $page_id = wp_insert_post([
                'post_title' => $new_page_title,
                'post_content' => $blocks,
                'post_status' => 'publish',
                'post_type' => 'page'
            ]);
            
            if (is_wp_error($page_id)) {
                error_log("Error creating new page: " . $page_id->get_error_message());
                return $page_id; // Return the WP_Error
            }
            
            error_log("New page created with ID: " . $page_id);
            
            // Apply template if specified
            if (!empty($template)) {
                update_post_meta($page_id, '_wp_page_template', $template);
                error_log("Applied template to new page: " . $template);
            }
            
            // Insert into New_Pages table
            $wpdb->insert('New_Pages', [
                'WP_Page_ID' => $page_id,
                'Title' => $new_page_title,
                'URL' => get_permalink($page_id),
                'Status' => 'New_Page_Created',
                'Created_At' => current_time('mysql'),
                'Updated_At' => current_time('mysql')
            ]);
            
            $new_page_db_id = $wpdb->insert_id;
            error_log("Inserted new page into New_Pages table with ID: " . $new_page_db_id);
            
        } else {
            // Convert to integer for existing page
            $page_id = intval($page_id);
            error_log("Using existing page with ID: " . $page_id);
            
            // Append blocks to existing content
            $existing_content = get_post_field('post_content', $page_id);
            $new_content = $existing_content . "\n\n" . $blocks;
        
            wp_update_post([
                'ID' => $page_id,
                'post_content' => $new_content
            ]);
            
            // Update New_Pages record to map it to this WordPress page
            $new_page_row = $wpdb->get_row(
                $wpdb->prepare("SELECT ID FROM New_Pages WHERE WP_Page_ID = %d", $page_id)
            );
            
            if (!$new_page_row) {
                $wpdb->insert('New_Pages', [
                    'WP_Page_ID' => $page_id,
                    'Title' => get_the_title($page_id),
                    'URL' => get_permalink($page_id),
                    'Status' => 'Content_Merged',
                    'Created_At' => current_time('mysql'),
                    'Updated_At' => current_time('mysql')
                ]);
            
                $new_page_db_id = $wpdb->insert_id;
                error_log("Inserted existing page into New_Pages table with ID: " . $new_page_db_id);
            } else {
                $new_page_db_id = $new_page_row->ID;
                error_log("Found existing page in New_Pages table with ID: " . $new_page_db_id);
            }
        }

        // Update Old_Pages record to map it to this WordPress page
        if (isset($data['url'])) {
            $url = $data['url'];
            $wpdb->update(
                'Old_Pages',
                [
                    'Mapped_To' => $new_page_db_id,
                    'Status' => 'merged'
                ],
                ['URL' => $url]
            );

            error_log("Updated Old_Pages: $url → page ID $page_id (DB ID: $new_page_db_id)");
        } else {
            error_log("No 'url' key found in cleaned_data file: " . $_POST['file']);
        }
        
        // Extract and store links
        if (!empty($cleaned_content)) {
            Migration_Links::extract_and_store_links($cleaned_content, $new_page_db_id);
            error_log("Extracted links from parent page content");
        }
        
        return [
            'page_id' => $page_id,
            'new_page_db_id' => $new_page_db_id
        ];
    }

    /**
     * Process subpages for a parent page
     */
    private static function process_subpages($parent_page_id, $parent_file_path, $template) {
        global $wpdb;
        $subpages = self::find_subpages($parent_file_path);
        $processed_count = 0;
        
        if (empty($subpages)) {
            error_log("No subpages found to process");
            return 0;
        }
        
        error_log("Found " . count($subpages) . " subpages to process");
        
        foreach ($subpages as $subpage_path) {
            $subpage_file = MIGRATION_CLEANED_DATA . $subpage_path;
            error_log("Processing subpage: " . $subpage_file);
            
            if (file_exists($subpage_file)) {
                $subpage_data = json_decode(file_get_contents($subpage_file), true);
                $subpage_title = isset($subpage_data['title']) ? $subpage_data['title'] : 'Untitled Subpage';
                error_log("Subpage title: " . $subpage_title);
                
                $subpage_content = isset($subpage_data['cleaned_content']) ? $subpage_data['cleaned_content'] : '';
            
                $new_subpage_id = wp_insert_post([
                    'post_title' => $subpage_title,
                    'post_content' => self::convert_html_to_blocks($subpage_content),
                    'post_status' => 'publish',
                    'post_parent' => $parent_page_id,
                    'post_type' => 'page'
                ]);
                
                if (is_wp_error($new_subpage_id)) {
                    error_log("Error creating subpage: " . $new_subpage_id->get_error_message());
                    continue;
                }
                
                error_log("Created subpage with ID: " . $new_subpage_id);
            
                if ($template) {
                    update_post_meta($new_subpage_id, '_wp_page_template', $template);
                    error_log("Applied template to subpage: " . $template);
                }
                
                // Update the subpage in the database tables
                if ($new_subpage_id) {
                    $wpdb->insert('New_Pages', [
                        'WP_Page_ID' => $new_subpage_id,
                        'Title' => $subpage_title,
                        'URL' => get_permalink($new_subpage_id),
                        'Status' => 'Subpage_Created',
                        'Created_At' => current_time('mysql'),
                        'Updated_At' => current_time('mysql')
                    ]);
                    
                    $new_subpage_db_id = $wpdb->insert_id;
                    error_log("Inserted subpage into New_Pages table with ID: " . $new_subpage_db_id);
                    
                    // Extract and store links from the subpage content
                    if (!empty($subpage_content)) {
                        error_log("Extracting links from subpage: " . $subpage_title);
                        Migration_Links::extract_and_store_links($subpage_content, $new_subpage_db_id);
                    }
                    
                    // Update Old_Pages if URL exists
                    if (isset($subpage_data['url'])) {
                        $wpdb->update(
                            'Old_Pages',
                            [
                                'Mapped_To' => $new_subpage_db_id,
                                'Status' => 'subpage_created'
                            ],
                            ['URL' => $subpage_data['url']]
                        );
                        
                        error_log("Updated Old_Pages for subpage: " . $subpage_data['url'] . " → page ID " . $new_subpage_id);
                    } else {
                        error_log("No 'url' key found in subpage file: " . $subpage_path);
                    }
                    
                    $processed_count++;
                }
            } else {
                error_log("Subpage file does not exist: " . $subpage_file);
            }
        }
        
        return $processed_count;
    }
    
    public static function build_subpages() {
        $parent_id = intval($_POST['parent_id']);
        $template = sanitize_text_field($_POST['template']);
        $subpages = json_decode(file_get_contents($_POST['subpages']), true);
    
        foreach ($subpages as $subpage) {
            $data = json_decode(file_get_contents(MIGRATION_CLEANED_DATA . '/' . $subpage), true);
            $cleaned_content = isset($data['cleaned_content']) ? $data['cleaned_content'] : '';
    
            $new_page_id = wp_insert_post([
                'post_title' => $data['title'],
                'post_content' => self::convert_html_to_blocks($cleaned_content),
                'post_status' => 'publish',
                'post_parent' => $parent_id,
                'post_type' => 'page'
            ]);
    
            update_post_meta($new_page_id, '_wp_page_template', $template);
        }
    
        wp_send_json_success(['message' => 'Subpages built successfully!']);
    }
    
    /**
     * Find all subpages for a given page path
     * 
     * @param string $relative_path Path to the parent page content.json
     * @return array Array of subpage paths relative to MIGRATION_CLEANED_DATA
     */
    public static function find_subpages($relative_path) {
        $subpages = [];
        
        // Get the directory containing the parent page
        $parent_dir = dirname(MIGRATION_CLEANED_DATA . $relative_path);
        
        // Look for subdirectories that contain content.json files
        if (is_dir($parent_dir)) {
            $items = scandir($parent_dir);
            foreach ($items as $item) {
                $item_path = $parent_dir . '/' . $item;
                
                // Skip . and .. directories and non-directories
                if ($item === '.' || $item === '..' || !is_dir($item_path)) {
                    continue;
                }
                
                // Check if this directory contains a content.json file
                $content_file = $item_path . '/content.json';
                if (file_exists($content_file)) {
                    // Store the path relative to MIGRATION_CLEANED_DATA
                    $relative_subpage_path = str_replace(MIGRATION_CLEANED_DATA, '', $content_file);
                    // Remove leading slash if present
                    $relative_subpage_path = ltrim($relative_subpage_path, '/');
                    $subpages[] = $relative_subpage_path;
                }
            }
        }
        
        return $subpages;
    }
}
add_action('wp_ajax_merge_content', array('Migration_Pages', 'merge_content_into_page'));
add_action('wp_ajax_build_subpages', array('Migration_Pages', 'build_subpages'));