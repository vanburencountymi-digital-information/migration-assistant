<?php
/*
Plugin Name: Migration Assistant
Description: Assists in migrating and structuring website content from the old site to the new one.
Version: 2.0
Author: Your Name
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Define constants
define('MIGRATION_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MIGRATION_CLEANED_DATA', MIGRATION_PLUGIN_DIR . 'cleaned_data/');

// Include necessary files
require_once MIGRATION_PLUGIN_DIR . 'includes/class-migration-admin.php';
require_once MIGRATION_PLUGIN_DIR . 'includes/class-migration-pages.php';
require_once MIGRATION_PLUGIN_DIR . 'includes/class-migration-links.php';
require_once MIGRATION_PLUGIN_DIR . 'includes/class-migration-bulk.php';
require_once MIGRATION_PLUGIN_DIR . 'includes/class-migration-templates.php';
require_once MIGRATION_PLUGIN_DIR . 'includes/migration-functions.php';

// Hook plugin activation to setup function
register_activation_hook(__FILE__, 'migration_assistant_activate');

// Hook plugin deactivation (if needed)
register_deactivation_hook(__FILE__, 'migration_assistant_deactivate');

// Hook uninstallation to cleanup function
register_uninstall_hook(__FILE__, 'migration_assistant_uninstall');

require_once MIGRATION_PLUGIN_DIR . 'includes/migration-setup.php';

// Initialize the main admin class
new Migration_Admin();
