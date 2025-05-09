<?php

/**
 * Handles FAQ import functionality
 */
class Migration_FAQs {
    
    /**
     * Import FAQs from a CSV file
     * 
     * @param string $csv_file Path to the CSV file
     * @return int|WP_Error Number of FAQs imported or error
     */
    public static function import_from_csv($csv_file = null) {
        if ($csv_file === null) {
            $csv_file = plugin_dir_path(dirname(__FILE__)) . 'FAQs.csv';
        }
        
        if (!file_exists($csv_file)) {
            return new WP_Error('file_not_found', 'FAQs.csv file not found');
        }
        
        // Open the CSV file
        $file = fopen($csv_file, 'r');
        if (!$file) {
            return new WP_Error('file_open_error', 'Unable to open FAQs.csv file');
        }
        
        // Read the CSV header
        $header = fgetcsv($file);
        
        // Define expected headers and their positions
        $expected_headers = ['Question', 'Answer', 'Category', 'Tags'];
        $header_map = [];
        
        // Map the actual headers to expected ones
        foreach ($expected_headers as $expected) {
            $position = array_search($expected, $header);
            if ($position !== false) {
                $header_map[$expected] = $position;
            }
        }
        
        // Check if required headers exist
        if (!isset($header_map['Question']) || !isset($header_map['Answer'])) {
            fclose($file);
            return new WP_Error('invalid_format', 'CSV file must contain at least Question and Answer columns');
        }
        
        $imported_count = 0;
        
        // Process each row
        while (($row = fgetcsv($file)) !== false) {
            // Get data from the row
            $question = isset($row[$header_map['Question']]) ? sanitize_text_field($row[$header_map['Question']]) : '';
            $answer = isset($row[$header_map['Answer']]) ? wp_kses_post($row[$header_map['Answer']]) : '';
            $category = isset($header_map['Category']) && isset($row[$header_map['Category']]) ? 
                         sanitize_text_field($row[$header_map['Category']]) : '';
            $tags = isset($header_map['Tags']) && isset($row[$header_map['Tags']]) ? 
                     sanitize_text_field($row[$header_map['Tags']]) : '';
            
            // Skip if question or answer is empty
            if (empty($question) || empty($answer)) {
                continue;
            }
            
            // Create a new FAQ post
            $post_id = self::create_faq_post($question, $answer, $category, $tags);
            
            if (!is_wp_error($post_id)) {
                $imported_count++;
            }
        }
        
        fclose($file);
        return $imported_count;
    }
    
    /**
     * Create an FAQ post with the provided data
     * 
     * @param string $question The FAQ question
     * @param string $answer The FAQ answer
     * @param string $category The FAQ category
     * @param string $tags The FAQ tags, comma-separated
     * @return int|WP_Error The post ID or error
     */
    private static function create_faq_post($question, $answer, $category = '', $tags = '') {
        // Create a new FAQ post
        $post_data = array(
            'post_title'    => $question,
            'post_content'  => $answer,
            'post_status'   => 'publish',
            'post_type'     => 'faq', // Assuming 'faq' is your custom post type
        );
        
        // Insert the post
        $post_id = wp_insert_post($post_data);
        
        if (!is_wp_error($post_id)) {
            // Set category if it exists
            if (!empty($category)) {
                // Get or create the category term
                $term = term_exists($category, 'faq_category');
                if (!$term) {
                    $term = wp_insert_term($category, 'faq_category');
                }
                
                if (!is_wp_error($term)) {
                    wp_set_object_terms($post_id, (int)$term['term_id'], 'faq_category');
                }
            }
            
            // Set tags if they exist
            if (!empty($tags)) {
                $tag_array = array_map('trim', explode(',', $tags));
                wp_set_object_terms($post_id, $tag_array, 'faq_tag');
            }
        }
        
        return $post_id;
    }
} 