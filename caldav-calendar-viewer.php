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

add_action( 'wp_ajax_cdcv_get_calendar', 'cdcv_ajax_get_calendar' );
add_action( 'wp_ajax_nopriv_cdcv_get_calendar', 'cdcv_ajax_get_calendar' );

function cdcv_ajax_get_calendar() {
    if ( ! isset( $_POST['feed_id'], $_POST['nonce'] ) ) {
        wp_send_json_error( array( 'message' => __( 'Missing parameters.', 'caldav-calendar-viewer' ) ) );
    }
    $feed_id = sanitize_key( $_POST['feed_id'] );
    if ( ! wp_verify_nonce( $_POST['nonce'], 'cdcv_get_calendar' ) ) {
        wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'caldav-calendar-viewer' ) ) );
    }
    if ( empty( $feed_id ) ) {
        wp_send_json_error( array( 'message' => __( 'No feed ID provided.', 'caldav-calendar-viewer' ) ) );
    }

    // Resolve label filter: comma-separated list of category names (case-insensitive).
    // A label prefixed with "!" means the category must NOT be present on the event.
    $labelString = ! empty( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

    $tz         = wp_timezone();
    $today      = new DateTimeImmutable( 'today', $tz );
    $rangeStart = $today->format( 'Y-m-d' );
    $rangeEnd   = $today->modify( '+7 days' )->format( 'Y-m-d' );
    $icalBody   = CalDavCVFetcher::fetch( $feed_id );
    if ( is_wp_error( $icalBody ) ) {
        wp_send_json_error( array( 'message' => __( 'Unable to load calendar. Please try again later.', 'caldav-calendar-viewer' ) ) );
    }
    $events = CalDavCVParser::parse( $icalBody, $rangeStart, $rangeEnd );
    $events = CalDavCVParser::filterByLabel( $events, $labelString );

    if ( empty( $events ) ) {
        $html = '<div class="cdcv-no-events">' . esc_html__( 'No upcoming events found.', 'caldav-calendar-viewer' ) . '</div>';
    } else {
        $shortcode = new CalDavCVShortcode();
        $html = $shortcode->buildEventListHtml( $events );
    }
    wp_send_json_success( array( 'html' => $html ) );
}
