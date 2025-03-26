<?php

class Migration_Setup {

    public static function activate() {
        // Create default plugin options
        add_option('migration_url_mappings', []);
        add_option('migration_page_hierarchy', []);
        add_option('migration_default_templates', [
            'default' => 'Default Template',
            'department' => 'Department Page',
            'news' => 'News Page'
        ]);

        // Ensure cleaned_data directory exists
        self::ensure_directory(MIGRATION_CLEANED_DATA);

        // Create database table if needed
        global $wpdb;
        $table_name = $wpdb->prefix . 'migration_mappings';

        $charset_collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            old_url TEXT NOT NULL,
            new_url TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    public static function deactivate() {
        // Unschedule any cron jobs if we added them
        wp_clear_scheduled_hook('migration_assistant_cron');
    }

    public static function uninstall() {
        // Remove stored options
        delete_option('migration_url_mappings');
        delete_option('migration_page_hierarchy');
        delete_option('migration_default_templates');

        // Drop database table
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS " . $wpdb->prefix . "migration_mappings");
    }

    private static function ensure_directory($path) {
        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }
    }
}

// Register activation hook
function migration_assistant_activate() {
    Migration_Setup::activate();
}

// Register deactivation hook
function migration_assistant_deactivate() {
    Migration_Setup::deactivate();
}

// Register uninstall hook
function migration_assistant_uninstall() {
    Migration_Setup::uninstall();
}
