<?php
/**
 * Plugin Name: WordPress iCal Calendar
 * Plugin URI:  https://github.com/kayman-mk/wordpress-ical-calendar
 * Description: Displays events from an iCal (.ics) feed in a calendar view. Supports authenticated (username/password) iCal endpoints.
 * Version:     1.0.0
 * Author:      kayman-mk
 * License:     GPL-2.0-or-later
 * Text Domain: wp-ical-calendar
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'WPICAL_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPICAL_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPICAL_VERSION', '1.0.0' );

require_once WPICAL_PLUGIN_DIR . 'includes/class-wpical-settings.php';
require_once WPICAL_PLUGIN_DIR . 'includes/class-wpical-fetcher.php';
require_once WPICAL_PLUGIN_DIR . 'includes/class-wpical-parser.php';
require_once WPICAL_PLUGIN_DIR . 'includes/class-wpical-shortcode.php';

/**
 * Initialize the plugin components.
 */
function wpical_init() {
    new WPIcalSettings();
    new WPIcalShortcode();
}
add_action( 'plugins_loaded', 'wpical_init' );

/**
 * Enqueue front-end assets when the shortcode is used.
 */
function wpical_enqueue_assets() {
    wp_register_style(
        'wpical-calendar-style',
        WPICAL_PLUGIN_URL . 'assets/css/calendar.css',
        array(),
        WPICAL_VERSION
    );
    wp_register_script(
        'wpical-calendar-script',
        WPICAL_PLUGIN_URL . 'assets/js/calendar.js',
        array(),
        WPICAL_VERSION,
        true
    );
}
add_action( 'wp_enqueue_scripts', 'wpical_enqueue_assets' );

