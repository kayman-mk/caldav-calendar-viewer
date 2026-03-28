<?php
/**
 * Plugin Name: CalDav Calendar Viewer
 * Plugin URI:  https://github.com/kayman-mk/caldav-calendar-viewer
 * Description: Displays events from an iCal (.ics) feed in a calendar view. Supports authenticated (username/password) iCal endpoints.
 * Version:     1.0.0
 * Author:      kaymanmk
 * License:     GPL-2.0-or-later
 * Text Domain: caldav-calendar-viewer
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'CDCV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CDCV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CDCV_VERSION', '1.0.0' );

require_once CDCV_PLUGIN_DIR . 'includes/class-cdcv-settings.php';
require_once CDCV_PLUGIN_DIR . 'includes/class-cdcv-fetcher.php';
require_once CDCV_PLUGIN_DIR . 'includes/class-cdcv-parser.php';
require_once CDCV_PLUGIN_DIR . 'includes/class-cdcv-shortcode.php';

/**
 * Holds references to the plugin component instances to prevent garbage collection.
 *
 * @var array<string, object>
 */
global $cdcv_instances;
$cdcv_instances = array();

/**
 * Initialize the plugin components.
 */
function cdcv_init() {
    global $cdcv_instances;

    $cdcv_instances['settings']  = new CalDavCVSettings();
    $cdcv_instances['shortcode'] = new CalDavCVShortcode();
}
add_action( 'plugins_loaded', 'cdcv_init' );

/**
 * Add a "Settings" link on the Plugins list page.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function cdcv_plugin_action_links( array $links ): array {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=cdcv-settings' ) ) . '">'
        . esc_html__( 'Settings', 'caldav-calendar-viewer' )
        . '</a>';

    array_unshift( $links, $settings_link );

    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'cdcv_plugin_action_links' );

/**
 * Enqueue front-end assets when the shortcode is used.
 */
function cdcv_enqueue_assets() {
    wp_register_style(
        'cdcv-calendar-style',
        CDCV_PLUGIN_URL . 'assets/css/calendar.css',
        array(),
        CDCV_VERSION
    );
    wp_register_script(
        'cdcv-calendar-script',
        CDCV_PLUGIN_URL . 'assets/js/calendar.js',
        array(),
        CDCV_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'cdcv_enqueue_assets' );

