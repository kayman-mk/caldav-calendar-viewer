<?php
/**
 * Plugin Name: ICal Calendar View
 * Plugin URI:  https://github.com/kayman-mk/ical-calendar-view
 * Description: Displays events from an iCal (.ics) feed in a calendar view. Supports authenticated (username/password) iCal endpoints.
 * Version:     1.0.0
 * Author:      kaymanmk
 * License:     GPL-2.0-or-later
 * Text Domain: ical-calendar-view
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'ICALCV_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ICALCV_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'ICALCV_VERSION', '1.0.0' );

require_once ICALCV_PLUGIN_DIR . 'includes/class-icalcv-settings.php';
require_once ICALCV_PLUGIN_DIR . 'includes/class-icalcv-fetcher.php';
require_once ICALCV_PLUGIN_DIR . 'includes/class-icalcv-parser.php';
require_once ICALCV_PLUGIN_DIR . 'includes/class-icalcv-shortcode.php';

/**
 * Holds references to the plugin component instances to prevent garbage collection.
 *
 * @var array<string, object>
 */
global $icalcv_instances;
$icalcv_instances = array();

/**
 * Initialize the plugin components.
 */
function icalcv_init() {
    global $icalcv_instances;

    $icalcv_instances['settings']  = new ICalCVSettings();
    $icalcv_instances['shortcode'] = new ICalCVShortcode();
}
add_action( 'plugins_loaded', 'icalcv_init' );

/**
 * Add a "Settings" link on the Plugins list page.
 *
 * @param array $links Existing action links.
 * @return array Modified action links.
 */
function icalcv_plugin_action_links( array $links ): array {
    $settings_link = '<a href="' . esc_url( admin_url( 'options-general.php?page=icalcv-settings' ) ) . '">'
        . esc_html__( 'Settings', 'ical-calendar-view' )
        . '</a>';

    array_unshift( $links, $settings_link );

    return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'icalcv_plugin_action_links' );

/**
 * Enqueue front-end assets when the shortcode is used.
 */
function icalcv_enqueue_assets() {
    wp_register_style(
        'icalcv-calendar-style',
        ICALCV_PLUGIN_URL . 'assets/css/calendar.css',
        array(),
        ICALCV_VERSION
    );
    wp_register_script(
        'icalcv-calendar-script',
        ICALCV_PLUGIN_URL . 'assets/js/calendar.js',
        array(),
        ICALCV_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'icalcv_enqueue_assets' );

