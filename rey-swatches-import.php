<?php
/**
 * Plugin Name:  Universal Import
 * Plugin URI:   https://github.com/jodukkan-max/universal-import
 * Description:  Receives CSV data from the Cosmetics Scraper app and imports
 *               WooCommerce variable products with full swatch support
 *               (color swatches, image swatches, extra variation images).
 * Version:      1.19.7
 * Author:       Cosmetics Scraper Team
 * License:      GPL-2.0+
 * Requires PHP: 7.4
 * Requires at least: 5.8
 * Text Domain:  rey-swatches-import
 */

if (!defined('ABSPATH')) {
    exit;
}

define('RSI_VERSION', '1.19.7');
define('RSI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('RSI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('RSI_REST_NAMESPACE', 'scraper/v1');
define('RSI_REST_ROUTE', '/import-csv');

// Autoload classes.
require_once RSI_PLUGIN_DIR . 'includes/class-key-generator.php';
require_once RSI_PLUGIN_DIR . 'includes/class-csv-parser.php';
require_once RSI_PLUGIN_DIR . 'includes/class-image-handler.php';
require_once RSI_PLUGIN_DIR . 'includes/class-rey-swatches.php';
require_once RSI_PLUGIN_DIR . 'includes/class-product-creator.php';
require_once RSI_PLUGIN_DIR . 'includes/class-rest-endpoint.php';
require_once RSI_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once RSI_PLUGIN_DIR . 'includes/class-universal-import-updater.php';

/**
 * Register the self-update mechanism powered by a GitHub-hosted version.json
 * file. No wordpress.org listing needed.
 */
new Universal_Import_Updater( __FILE__, RSI_VERSION );

/**
 * Generate the auth key on plugin activation.
 */
function rsi_generate_key_on_activate(): void {
    if (!get_option('rsi_auth_key')) {
        update_option('rsi_auth_key', Rsi_Key_Generator::generate());
    }
}
register_activation_hook(__FILE__, 'rsi_generate_key_on_activate');

/**
 * Fix CORS so the Chrome extension can call this endpoint.
 */
add_filter('rest_pre_serve_request', function ($served) {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
    header('Access-Control-Allow-Headers: X-Scraper-Key, Content-Type');
    return $served;
});

/**
 * Register the admin page.
 */
function rsi_admin_init(): void {
    if (!class_exists('WooCommerce')) {
        return;
    }
    $admin = new Rsi_Admin_Page();
    $admin->register();
}
add_action('admin_menu', 'rsi_admin_init');

/**
 * Register the REST endpoint.
 */
function rsi_init(): void {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e(
                'Universal Import requires WooCommerce to be installed and activated.',
                'rey-swatches-import'
            );
            echo '</p></div>';
        });
        return;
    }

    $endpoint = new Rsi_Rest_Endpoint();
    $endpoint->register();
}
add_action('rest_api_init', 'rsi_init');
