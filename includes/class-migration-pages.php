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
        echo '<label for="existing_page">Select an Existing Page:</label>';
        self::get_existing_pages_dropdown();
    
        // Template selection for the page
        Migration_Templates::display_template_selection($relative_path);
    
        // Merge Content Button
        echo '<button id="merge-content" data-file="' . esc_attr($relative_path) . '">Merge Content</button>';
    
        // Parent Page Selection for Building Subpages
        self::get_parent_page_dropdown();
    
        // Input for subpage selection (Assume it's dynamically populated)
        echo '<label for="subpage-list">Subpages to Build:</label>';
        echo '<input type="text" id="subpage-list" placeholder="Enter subpage filenames as JSON array">';
    
        // Build Subpages Button
        echo '<button id="build-subpages" data-file="' . esc_attr($relative_path) . '">Build Subpages</button>';
    }
    

    public static function get_existing_pages_dropdown() {
        $pages = get_pages(['post_status' => 'publish']); // Fetch published pages
    
        echo '<label for="existing_page">Select Existing Page:</label>';
        echo '<select id="existing_page">';
        echo '<option value="">-- Select a Page --</option>';
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
        'ul' => 'core/list',
        'ol' => 'core/list',
        'li' => 'core/list-item',
        'a' => 'core/html',  // No direct "link block", so we use HTML
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
    
    public static function convert_html_to_blocks($html) {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
        libxml_clear_errors();
    
        $blocks = [];
        self::remove_unnecessary_divs($doc);
    
        foreach ($doc->getElementsByTagName('*') as $element) {
            $tag = strtolower($element->nodeName);
            $content = trim($element->textContent);
    
            if ($content === '' || !isset(self::$block_map[$tag])) {
                continue;
            }
    
            $block_type = self::$block_map[$tag];
    
            switch ($block_type) {
                case 'core/heading':
                    $level = str_replace('h', '', $tag);
                    $blocks[] = [
                        'blockName' => $block_type,
                        'attrs' => ['level' => (int)$level],
                        'innerBlocks' => [],
                        'innerHTML' => '<' . $tag . ' class="wp-block-heading">' . $content . '</' . $tag . '>',
                        'innerContent' => ['<' . $tag . ' class="wp-block-heading">' . $content . '</' . $tag . '>']
                    ];
                    break;
    
                case 'core/paragraph':
                    $blocks[] = [
                        'blockName' => $block_type,
                        'attrs' => [],
                        'innerBlocks' => [],
                        'innerHTML' => self::handle_paragraph_links($element),
                        'innerContent' => [self::handle_paragraph_links($element)]
                    ];
                    break;
    
                case 'core/list':
                    $items = [];
                    foreach ($element->getElementsByTagName('li') as $li) {
                        $liContent = trim($li->textContent);
                        if ($liContent !== '') {
                            $items[] = [
                                'blockName' => 'core/list-item',
                                'attrs' => [],
                                'innerBlocks' => [],
                                'innerHTML' => self::handle_paragraph_links($li),
                                'innerContent' => [self::handle_paragraph_links($li)]
                            ];
                        }
                    }
                    $blocks[] = [
                        'blockName' => $block_type,
                        'attrs' => ['ordered' => ($tag === 'ol')],
                        'innerBlocks' => $items,
                        'innerHTML' => '',
                        'innerContent' => []
                    ];
                    break;
            }
        }
    
        return serialize_blocks($blocks);
    }
    
    
    
    
    
    public static function merge_content_into_page() {
        $page_id = intval($_POST['page_id']);
        $file_path = MIGRATION_CLEANED_DATA . $_POST['file'];
    
        if (!file_exists($file_path)) {
            wp_send_json_error(['message' => 'File not found']);
        }
    
        $data = json_decode(file_get_contents($file_path), true);
        $cleaned_content = isset($data['cleaned_content']) ? $data['cleaned_content'] : '';
    
        // Convert HTML to structured Gutenberg blocks
        $blocks = self::convert_html_to_blocks($cleaned_content);
    
        // Append blocks to existing content
        $existing_content = get_post_field('post_content', $page_id);
        $new_content = $existing_content . "\n\n" . $blocks;
    
        wp_update_post([
            'ID' => $page_id,
            'post_content' => $new_content
        ]);
    
        wp_send_json_success(['message' => 'Content merged successfully']);
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
                'post_status' => 'draft',
                'post_parent' => $parent_id,
                'post_type' => 'page'
            ]);
    
            update_post_meta($new_page_id, '_wp_page_template', $template);
        }
    
        wp_send_json_success(['message' => 'Subpages built successfully!']);
    }
    
}
add_action('wp_ajax_merge_content', array('Migration_Pages', 'merge_content_into_page'));
add_action('wp_ajax_build_subpages', array('Migration_Pages', 'build_subpages'));