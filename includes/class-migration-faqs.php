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
        $headers = fgetcsv($file);
        
        // Find the index of each required field
        $category_index = array_search('Category', $headers);
        $question_index = array_search('Question', $headers);
        $answer_index = array_search('Answer', $headers);
        $question_status_index = array_search('Question Status', $headers);
        
        // Check if required headers exist
        if ($category_index === false || $question_index === false || $answer_index === false) {
            fclose($file);
            return new WP_Error('invalid_format', 'CSV file must contain Category, Question, and Answer columns');
        }
        
        // Use the plugin's CPT & taxonomy slugs
        $faq_pt = 'faq';
        $faq_tx = 'faq-group';
        
        $imported_count = 0;
        
        // Process each row
        while (($row = fgetcsv($file)) !== false) {
            $raw_cat = isset($row[$category_index]) ? trim($row[$category_index]) : '';
            $question = isset($row[$question_index]) ? trim($row[$question_index]) : '';
            $answer = isset($row[$answer_index]) ? trim($row[$answer_index]) : '';
            $question_status = isset($row[$question_status_index]) ? trim($row[$question_status_index]) : '';
            
            // Skip if question or answer is empty
            if (empty($question) || empty($answer)) {
                continue;
            }
            
            // Skip if question status is not Published
            if ($question_status_index !== false && $question_status !== 'Published') {
                continue;
            }
            
            // --- CATEGORY PARSING & TERM CREATION ---
            $term_ids = [];
            if (!empty($raw_cat)) {
                if (strpos($raw_cat, '-') !== false) {
                    list($parent_name, $child_name) = array_map('trim', explode('-', $raw_cat, 2));
                    
                    // Parent term
                    $parent = term_exists($parent_name, $faq_tx);
                    if (!$parent) {
                        $parent = wp_insert_term($parent_name, $faq_tx);
                    }
                    $parent_id = is_array($parent) ? $parent['term_id'] : $parent;
                    
                    // Child term under parent
                    $child = term_exists($child_name, $faq_tx);
                    if (!$child) {
                        $child = wp_insert_term($child_name, $faq_tx, ['parent' => $parent_id]);
                    }
                    $child_id = is_array($child) ? $child['term_id'] : $child;
                    
                    $term_ids = [(int)$child_id];
                } else {
                    // Single-level category
                    $term = term_exists($raw_cat, $faq_tx);
                    if (!$term) {
                        $term = wp_insert_term($raw_cat, $faq_tx);
                    }
                    $term_id = is_array($term) ? $term['term_id'] : $term;
                    $term_ids = [(int)$term_id];
                }
            }
            
            // --- INSERT FAQ POST ---
            $post_id = wp_insert_post([
                'post_type'    => $faq_pt,
                'post_title'   => wp_strip_all_tags($question),
                'post_content' => $answer,
                'post_status'  => 'publish',
            ]);
            
            // Assign the term (child, if hierarchical)
            if (!is_wp_error($post_id) && !empty($term_ids)) {
                wp_set_post_terms($post_id, $term_ids, $faq_tx, false);
            }
            
            if (!is_wp_error($post_id)) {
                $imported_count++;
            }
        }
        
        fclose($file);
        return $imported_count;
    }
} 