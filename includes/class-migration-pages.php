<?php

class Migration_Pages {
    private static $airtable_departments_cache = null;


    public static function add_department_to_main_menu($page_id) {
        error_log("Calling add_department_to_main_menu");
        if (!function_exists('wp_get_nav_menu_object')) return;
        // error_log("wp_get_nav_menu_object exists");
        // Get the menu object by location
        $locations = get_nav_menu_locations();
        error_log("locations: " . print_r($locations, true));
        if (!isset($locations['main-menu'])) {
            // error_log('Main menu location not found.');
            return;
        }
    
        $menu_id = $locations['main-menu'];
        // error_log("menu_id: " . $menu_id);
        $menu_items = wp_get_nav_menu_items($menu_id);
        // error_log("menu_items: " . print_r($menu_items, true));
        // Optional: Find "Departments" menu item to nest under
        $parent_id = 0;
        foreach ($menu_items as $item) {
            if (trim($item->title) === 'DEPARTMENTS &#038; OFFICES') {
                $parent_id = $item->ID;
                error_log("parent_id: " . $parent_id);
                break;
            }
        }
    
        // Add page to menu
        wp_update_nav_menu_item($menu_id, 0, array(
            'menu-item-title'     => get_the_title($page_id),
            'menu-item-object'    => 'page',
            'menu-item-object-id' => $page_id,
            'menu-item-type'      => 'post_type',
            'menu-item-status'    => 'publish',
            'menu-item-parent-id' => $parent_id > 0 ? $parent_id : 0,
        ));
    }
    
    // Fetch Airtable departments
    private static function fetch_airtable_departments() {
        error_log("Fetching Airtable departments");
        if (self::$airtable_departments_cache !== null) {
            error_log("Returning cached Airtable departments");
            return self::$airtable_departments_cache;
        }
        error_log("Fetching Airtable departments from API");
        $url = 'https://api.airtable.com/v0/' . AIRTABLE_BASE_ID . '/Departments'; // or your table name

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . AIRTABLE_API_KEY
            ]
        ]);
        // error_log("Airtable departments response: " . print_r($response, true));
        if (is_wp_error($response)) {
            error_log('Error fetching Airtable departments: ' . $response->get_error_message());
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        self::$airtable_departments_cache = [];

        foreach ($data['records'] as $record) {
            $fields = $record['fields'];
            if (isset($fields['Department Name']) && isset($fields['Department ID'])) {
                self::$airtable_departments_cache[] = [
                    'id' => $fields['Department ID'],
                    'name' => $fields['Department Name']
                ];
            }
        }

        return self::$airtable_departments_cache;
    }
    public static function get_airtable_departments() {
        $departments = self::fetch_airtable_departments();
        return $departments;
    }

    private static function maybe_set_department_meta($post_id, $template, $title) {
        if ($template !== 'template-department-homepage.php') {
            error_log("❌ Template is not department homepage: '$template'");
            return;
        }
    
        // Fetch and cache Airtable departments
        $departments = self::get_airtable_departments(); // Make sure this method exists and is static
    
        $normalize = function($string) {
            return strtolower(preg_replace('/[^\w\s]/', '', $string)); // remove punctuation and lowercase
        };
    
        $normalized_title = $normalize($title);
    
        foreach ($departments as $dept) {
            $normalized_dept_name = $normalize($dept['name']);
    
            if (strpos($normalized_dept_name, $normalized_title) !== false || strpos($normalized_title, $normalized_dept_name) !== false) {
                update_post_meta($post_id, 'department_id', $dept['id']);
                update_post_meta($post_id, 'department_name', $dept['name']);
                error_log("✅ Set department meta for '{$dept['name']}' (ID: {$dept['id']}) on post ID $post_id");
                return;
            }
        }
    
        error_log("❌ No department match found for title: '$title'");
    }
    

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
    
        // Display just the file content
        echo '<div class="file-preview">';
        echo '<h2>' . $title . '</h2>';
        echo '<div class="file-content">' . $content . '</div>';
        echo '</div>';
    }
    
    /**
     * Display action tools for a file
     * 
     * @param string $relative_path Path to the file relative to MIGRATION_CLEANED_DATA
     */
    public static function display_file_actions($relative_path) {
        // Find subpages
        $subpages = self::find_subpages($relative_path);
        $subpage_count = count($subpages);
        
        echo '<div class="file-actions">';
        
        // Build the subpage tree first
        $subpage_tree = self::build_subpage_tree($relative_path);
        // Count total nodes in the tree
        $subpage_count = self::count_subpage_tree_nodes($subpage_tree);

        echo '<div class="action-section subpage-options">';
        echo '<h3>Subpages</h3>';
        echo '<input type="checkbox" id="build-subpages-checkbox" name="build_subpages" checked>';
        echo '<label for="build-subpages-checkbox">Build subpages too</label>';
        echo '<span class="subpage-count">(' . $subpage_count . ' subpages found)</span>';

        // Display subpage tree if any exist
        if ($subpage_count > 0) {
            echo '<div class="subpage-list" style="margin-top: 10px; margin-left: 20px;">';
            echo '<strong>Subpages to be created:</strong>';
            echo '<ul id="subpage-preview-tree" style="margin-top: 5px;">';
            
            // Add the hidden data element for the tree
            echo '<div id="subpage-tree-preview-data" style="display:none;" data-tree=\'' . json_encode($subpage_tree) . '\'></div>';
            
            echo '</ul>';
            echo '</div>';
        }
        echo '</div>';
        // Close options container

        // Open tool-stack
        //--------------------------------
        echo '<div class="tool-stack">';

        // Destination selection container
        echo '<div class="action-section destination-selection">';
        echo '<h3>Destination</h3>';
        // Dropdown to select an existing WordPress page
        echo '<label for="existing_page">Select Destination Page:</label>';
        self::get_existing_pages_dropdown();
        
        // New page title container (hidden by default, shown via JS)
        echo '<div id="new-page-title-container" style="display:none; margin-top: 10px;">';
        echo '<label for="new_page_title">New Page Title:</label>';
        echo '<input type="text" id="new_page_title" name="new_page_title" placeholder="Enter page title">';
        echo '</div>';
        echo '</div>';
        // Close destination selection container

        // Parent page selection container
        echo '<div class="action-section parent-page-selection">';
        echo '<h3>Parent Page</h3>';
        echo '<label for="top-level-parent">Select Top-Level Parent Page:</label>';
        echo '<select id="top-level-parent">';
        echo '<option value="0">None (top-level page)</option>';
        $pages = get_pages(['sort_column' => 'post_title', 'sort_order' => 'asc']);
        foreach ($pages as $page) {
            echo '<option value="' . esc_attr($page->ID) . '">' . esc_html($page->post_title) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        // Close parent page selection container

        // Template selection container
        echo '<div class="action-section template-selection">';
        echo '<h3>Template</h3>';
        // Template selection for the page
        Migration_Templates::display_template_selection($relative_path);
        echo '</div>';
        // Close template selection container

        // Merge Content Button 
        echo '<button id="merge-content" class="button button-primary" data-file="' . esc_attr($relative_path) . '">Merge Content</button>';
        // Close merge content button

        echo '</div>';
        //--------------------------------
        // Close tool-stack



        
        // Subpage tree container
        echo '<div id="subpage-tree-container" class="action-section results-section" style="display:block;">';
        echo '<h3>Subpages Created</h3>';
        echo '<ul id="subpage-tree-list"></ul>';
        echo '</div>';
        // Close subpage tree container


        echo '</div>'; // Close file-actions

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
            
            // Render the subpage tree preview
            if ($('#subpage-tree-preview-data').length) {
                try {
                    const treeData = JSON.parse($('#subpage-tree-preview-data').attr('data-tree'));
                    const container = document.getElementById('subpage-preview-tree');
                    
                    function renderTree(tree, container, depth = 0) {
                        tree.forEach(node => {
                            const li = document.createElement("li");
                            li.textContent = `${"—".repeat(depth)} ${node.title}`;
                            container.appendChild(li);
                    
                            if (node.children && node.children.length > 0) {
                                const ul = document.createElement("ul");
                                ul.style.marginLeft = "20px";
                                container.appendChild(ul);
                                renderTree(node.children, ul, depth + 1);
                            }
                        });
                    }
                    
                    renderTree(treeData, container);
                } catch (e) {
                    console.error("Error rendering subpage tree:", e);
                }
            }
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
            return $html;
        }
    
        $endpoint = 'https://api.openai.com/v1/responses';
    
        $instructions = <<<EOT
        You are a web content editor for a local government website.
        
        Your job is to:
        - Rewrite the content to be clear, friendly, warm, and welcoming, while maintaining a professional tone appropriate for a government audience
        - Aim for a 9th grade reading level using plain language and short, active sentences
        - Improve accessibility for users with cognitive, visual, or language-based disabilities
        - Use descriptive and concise section headings to improve readability and navigation
        - Preserve or enhance the semantic HTML structure (e.g., use <section>, <h1>-<h3>, <p>, <ul>, <a>, etc. appropriately)
        - Remove unnecessary inline styles or extra classes; return clean, minimal HTML only
        - Do not include explanations or commentary—only return the revised HTML content
        
        Follow the principles of inclusive, client-centered communication. Help all users feel respected, supported, and informed.
        
        EOT;
        
    
        $payload = [
            'model' => 'gpt-4o',
            'input' => $html,
            'instructions' => $instructions,
            'temperature' => 0.4,
            'text' => [
                'format' => ['type' => 'text']
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
    
        $status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
    
        if ($status !== 200) {
            error_log("Non-200 status from OpenAI API: $status. Body: $body");
            return $html;
        }
    
        $data = json_decode($body, true);
    
        if (
            !isset($data['output'][0]['content'][0]['text']) ||
            $data['output'][0]['type'] !== 'message'
        ) {
            error_log("Unexpected OpenAI response structure: " . print_r($data, true));
            return $html;
        }
    
        $rewritten = trim($data['output'][0]['content'][0]['text']);
        
        // Check if the rewritten content is empty and fall back to original if needed
        if (empty($rewritten)) {
            error_log("OpenAI returned empty content, falling back to original");
            return $html;
        }
        
        error_log("Successfully received rewritten content via /v1/responses");
    
        return $rewritten;
    }
    

    public static function get_or_rewrite_with_ai($file_path, $cleaned_content) {
        $data = json_decode(file_get_contents($file_path), true);
    
        if (isset($data['rewritten_content']) && !empty($data['rewritten_content'])) {
            error_log("Using cached rewritten content for: " . $file_path);
            return $data['rewritten_content'];
        }
    
        error_log("Calling OpenAI API to rewrite content for: " . $file_path);
        $rewritten = self::rewrite_with_ai($cleaned_content);
        
        // Only save if we got valid rewritten content (not the fallback)
        if ($rewritten !== $cleaned_content) {
            // Save rewritten content back to content.json
            $data['rewritten_content'] = $rewritten;
            file_put_contents($file_path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            error_log("Using original content as fallback for: " . $file_path);
        }
    
        return $rewritten;
    }
    
    
    public static function convert_html_to_blocks($html, $file_path) {
        $use_ai_rewrites = defined('USE_AI_REWRITES') && USE_AI_REWRITES;
        error_log("Converting HTML to blocks for: " . $file_path);
        error_log("Using AI rewrites: " . $use_ai_rewrites);
        // Clean the HTML before processing
        $html = self::clean_html($html);

        if ($use_ai_rewrites && !empty(trim($html))) {
            error_log("Using AI rewrites for: " . $file_path);
            $html = self::get_or_rewrite_with_ai($file_path, $html);
        } elseif ($use_ai_rewrites) {
            error_log("Skipping AI rewrite for empty content: " . $file_path);
        }

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
                $lock_key = 'page_lock_' . md5($file);
                $_SESSION[$lock_key] = false;
                error_log("Manually cleared lock for file: " . $file);
                wp_send_json_success(['message' => 'Lock cleared successfully']);
                return;
            }
        }
        
        // Validate request parameters
        $file_path = isset($_POST['file']) ? MIGRATION_CLEANED_DATA . $_POST['file'] : '';
        $page_id = isset($_POST['page_id']) ? $_POST['page_id'] : '';
        $parent_id = isset($_POST['parent_id']) ? intval($_POST['parent_id']) : 0;
        $top_level_parent = isset($_POST['top_level_parent']) ? intval($_POST['top_level_parent']) : 0;
        $template = isset($_POST['template']) ? $_POST['template'] : '';
        $is_subpage = isset($_POST['is_subpage']) && $_POST['is_subpage'] === 'true';
        
        error_log("Processing page request received");
        error_log("File path: " . $file_path);
        error_log("Page ID/option: " . $page_id);
        error_log("Parent ID: " . $parent_id);
        error_log("Top level parent: " . $top_level_parent);
        error_log("Template: " . $template);
        error_log("Is subpage: " . ($is_subpage ? 'true' : 'false'));
        
        if (!file_exists($file_path)) {
            error_log("File not found: " . $file_path);
            wp_send_json_error(['message' => 'File not found']);
            return;
        }
        
        // Create a unique lock key for this file
        $lock_key = 'page_lock_' . md5($_POST['file']);
        
        // Check if this file is already being processed
        if (isset($_SESSION[$lock_key]) && $_SESSION[$lock_key] === true) {
            error_log("Page already being processed for file: " . $_POST['file']);
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
            // Get subpage tree and count before processing
            $subpage_tree = self::build_subpage_tree($_POST['file']);
            $subpage_count = self::count_subpage_tree_nodes($subpage_tree);
            
            // Process the page
            $result = self::process_page(
                $page_id,
                $file_path,
                $parent_id,
                $top_level_parent,
                $template,
                $is_subpage
            );
            
            // Release the lock
            $_SESSION[$lock_key] = false;
            
            if (is_wp_error($result)) {
                wp_send_json_error(['message' => $result->get_error_message()]);
                return;
            }
            
            wp_send_json_success([
                'message' => 'Page processed successfully',
                'page_id' => $result['page_id'],
                'new_page_db_id' => $result['new_page_db_id'],
                'title' => $result['title'],
                'has_subpages' => $subpage_count > 0,
                'subpage_tree' => $subpage_tree,
                'subpage_count' => $subpage_count,
                'total_pages' => $subpage_count + 1  // +1 for the parent page itself
            ]);
        } catch (Exception $e) {
            // Release the lock in case of error
            $_SESSION[$lock_key] = false;
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
            return;
        }
    }
    
    /**
     * Process a single page (parent or subpage)
     * 
     * @param string|int $page_id ID of existing page or 'new_page' to create new
     * @param string $file_path Full path to the content.json file
     * @param int $parent_id Parent page ID (for subpages)
     * @param int $top_level_parent Top level parent ID (for new parent pages)
     * @param string $template Template to use for the page
     * @param bool $is_subpage Whether this is a subpage
     * @return array|WP_Error Result data or error
     */
    private static function process_page($page_id, $file_path, $parent_id = 0, $top_level_parent = 0, $template = '', $is_subpage = false) {
        global $wpdb;
        
        if (!file_exists($file_path)) {
            error_log("File not found: " . $file_path);
            return new WP_Error('file_not_found', 'The specified file does not exist: ' . $file_path);
        }
        
        $data = json_decode(file_get_contents($file_path), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON parse error for file: " . $file_path . " - " . json_last_error_msg());
            return new WP_Error('json_parse_error', 'Failed to parse JSON from file: ' . json_last_error_msg());
        }
        
        $title = isset($data['title']) ? $data['title'] : 'Untitled';
        $cleaned_content = isset($data['cleaned_content']) ? $data['cleaned_content'] : '';
        
        if (empty($cleaned_content)) {
            error_log("Warning: Empty content for file: " . $file_path);
            // Continue anyway, but log the warning
        }
        
        // Determine the post parent
        $post_parent = 0;
        if ($is_subpage && $parent_id > 0) {
            $post_parent = $parent_id;
            error_log("Using provided parent ID for subpage: " . $parent_id);
        } else if ($top_level_parent > 0) {
            $post_parent = $top_level_parent;
            error_log("Using top level parent ID: " . $top_level_parent);
        }
        
        // Convert HTML to blocks
        try {
            $cleaned_blocks = self::convert_html_to_blocks($cleaned_content, $file_path);
        } catch (Exception $e) {
            error_log("Error converting HTML to blocks: " . $e->getMessage());
            return new WP_Error('block_conversion_error', 'Failed to convert HTML to blocks: ' . $e->getMessage());
        }
        
        $new_page_db_id = null;
        
        error_log("Processing page: " . $title);
        error_log("Is subpage: " . ($is_subpage ? 'true' : 'false'));
        error_log("Post parent: " . $post_parent);
        error_log("Template: " . $template);
        
        // Check if we need to create a new page
        if ($page_id === 'new_page') {
            // Use the title from the content.json file, or from the input if provided
            $new_page_title = !empty($_POST['new_page_title']) ? 
                              sanitize_text_field($_POST['new_page_title']) : $title;
            
            error_log("Creating new page with title: " . $new_page_title);
            
            // Create a new page
            $page_id = wp_insert_post([
                'post_title' => $new_page_title,
                'post_content' => $cleaned_blocks,
                'post_status' => 'publish',
                'post_type' => 'page',
                'post_parent' => $post_parent
            ]);
            
            if (is_wp_error($page_id)) {
                error_log("Error creating new page: " . $page_id->get_error_message());
                return $page_id; // Return the WP_Error
            }
            
            error_log("New page created with ID: " . $page_id);
            
            // Apply template if specified and set department meta if template is department homepage
            if (!empty($template)) {
                update_post_meta($page_id, '_wp_page_template', $template);
                error_log("Applied template to new page: " . $template);
                // Set department meta if template is department homepage
                self::maybe_set_department_meta($page_id, $template, $new_page_title);
                // Add department to main menu if template is department homepage
                if ($template === 'template-department-homepage.php') {
                    self::add_department_to_main_menu($page_id);
                }
            }
            
            // Insert into New_Pages table
            $wpdb->insert('New_Pages', [
                'WP_Page_ID' => $page_id,
                'Title' => $new_page_title,
                'URL' => get_permalink($page_id),
                'Status' => $is_subpage ? 'Subpage_Created' : 'New_Page_Created',
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
            $new_content = $existing_content . "\n\n" . $cleaned_blocks;
        
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
                    'Status' => $is_subpage ? 'subpage_created' : 'merged'
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
            error_log("Extracted links from page content");
        }
        
        return [
            'page_id' => $page_id,
            'new_page_db_id' => $new_page_db_id,
            'title' => $title,
            'status' => $is_subpage ? 'subpage_created' : 'parent_created',
            'relative_path' => $_POST['file']
        ];
    }
    
    public static function build_subpages() {
        $parent_id = intval($_POST['parent_id']);
        $template = sanitize_text_field($_POST['template']);
        $subpages = json_decode(file_get_contents($_POST['subpages']), true);
    
        foreach ($subpages as $subpage) {
            $file_path = MIGRATION_CLEANED_DATA . '/' . $subpage;
            $data = json_decode(file_get_contents($file_path), true);
            $cleaned_content = isset($data['cleaned_content']) ? $data['cleaned_content'] : '';
    
            $new_page_id = wp_insert_post([
                'post_title' => $data['title'],
                'post_content' => self::convert_html_to_blocks($cleaned_content, $file_path),
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

    /**
     * Recursively build a tree of subpages
     * 
     * @param string $relative_path Path to the parent page content.json
     * @param int $depth Current depth level (to prevent infinite recursion)
     * @return array Tree structure of subpages
     */
    public static function build_subpage_tree($relative_path, $depth = 0) {
        // Prevent infinite recursion
        if ($depth > MIGRATION_MAX_DEPTH || $depth > 10) {
            return [];
        }
        
        $tree = [];
        $subpages = self::find_subpages($relative_path);
        
        foreach ($subpages as $subpage_path) {
            $subpage_file = MIGRATION_CLEANED_DATA . $subpage_path;
            if (file_exists($subpage_file)) {
                $subpage_data = json_decode(file_get_contents($subpage_file), true);
                $subpage_title = isset($subpage_data['title']) ? esc_html($subpage_data['title']) : 'Untitled Subpage';
                
                // Recursively get children
                $children = self::build_subpage_tree($subpage_path, $depth + 1);
                
                $tree[] = [
                    'title' => $subpage_title,
                    'path' => $subpage_path,
                    'children' => $children
                ];
            }
        }
        
        return $tree;
    }

    /**
     * Count the total number of nodes in the subpage tree
     * 
     * @param array $tree The subpage tree structure
     * @return int Total number of nodes in the tree
     */
    public static function count_subpage_tree_nodes($tree) {
        $count = 0;
        
        foreach ($tree as $node) {
            // Count this node
            $count++;
            
            // Count all children recursively
            if (!empty($node['children'])) {
                $count += self::count_subpage_tree_nodes($node['children']);
            }
        }
        
        return $count;
    }

    /**
     * Calculate the maximum depth of the subpage tree
     * 
     * @param array $tree The subpage tree structure
     * @return int Maximum depth of the tree
     */
    public static function get_max_tree_depth($tree) {
        $max_depth = 0;
        
        foreach ($tree as $node) {
            // If this node has children, calculate their depth
            if (!empty($node['children'])) {
                // Get the max depth of children and add 1 for this level
                $child_depth = 1 + self::get_max_tree_depth($node['children']);
                // Update max_depth if this branch is deeper
                $max_depth = max($max_depth, $child_depth);
            } else {
                // Leaf nodes have depth of 0 in children, but we count this level
                $max_depth = max($max_depth, 1);
            }
        }
        
        return $max_depth;
    }
}
add_action('wp_ajax_merge_content', array('Migration_Pages', 'merge_content_into_page'));
add_action('wp_ajax_build_subpages', array('Migration_Pages', 'build_subpages'));
add_action('wp_ajax_get_airtable_departments', array('Migration_Pages', 'get_airtable_departments'));
add_action('wp_ajax_populate_old_pages', function () {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Unauthorized']);
    }

    Migration_Bulk::populate_old_pages_from_cleaned_data();
    wp_send_json_success(['message' => 'Old pages populated']);
});

