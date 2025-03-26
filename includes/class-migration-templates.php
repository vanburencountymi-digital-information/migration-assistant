<?php

class Migration_Templates {
    public static function get_available_templates() {
        return ['default' => 'Default', 'department' => 'Department Page', 'news' => 'News Page'];
    }

    public static function display_template_selection($relative_path) {
        echo '<label>Select Template:</label>';
        echo '<select id="template">';
        foreach (self::get_available_templates() as $key => $name) {
            echo '<option value="' . esc_attr($key) . '">' . esc_html($name) . '</option>';
        }
        echo '</select>';
        echo '<button id="apply-template" data-path="' . esc_attr($relative_path) . '">Apply</button>';
    }
}
