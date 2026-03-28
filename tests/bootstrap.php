<?php
/**
 * PHPUnit bootstrap file.
 *
 * Provides lightweight stubs for WordPress functions and constants so plugin
 * classes can be loaded and tested without a full WordPress installation.
 *
 * Note: All stubs below intentionally use WordPress's snake_case naming,
 * unused parameters and empty bodies to match the WordPress API contract.
 *
 * @SuppressWarnings(PHPMD)
 */

// phpcs:disable WordPress.NamingConventions, Generic.CodeAnalysis.UnusedFunctionParameter

// Prevent the ABSPATH guard from exiting.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', dirname( __DIR__ ) . '/' );
}

// WordPress AUTH_KEY used for encryption in CalDavCVSettings.
if ( ! defined( 'AUTH_KEY' ) ) {
    define( 'AUTH_KEY', 'test-auth-key-for-unit-tests-only' );
}

/* ------------------------------------------------------------------
 * In-memory option store used by get_option / update_option stubs.
 * ----------------------------------------------------------------*/
global $cdcv_test_options;
$cdcv_test_options = array();

if ( ! function_exists( 'get_option' ) ) {
    function get_option( string $key, $default = false ) { // NOSONAR - WordPress API stub
        global $cdcv_test_options;
        return array_key_exists( $key, $cdcv_test_options ) ? $cdcv_test_options[ $key ] : $default;
    }
}

if ( ! function_exists( 'update_option' ) ) {
    function update_option( string $key, $value ): bool { // NOSONAR - WordPress API stub
        global $cdcv_test_options;
        $cdcv_test_options[ $key ] = $value;
        return true;
    }
}

if ( ! function_exists( 'delete_option' ) ) {
    function delete_option( string $key ): bool { // NOSONAR - WordPress API stub
        global $cdcv_test_options;
        unset( $cdcv_test_options[ $key ] );
        return true;
    }
}

/* ------------------------------------------------------------------
 * In-memory transient store.
 * ----------------------------------------------------------------*/
global $cdcv_test_transients;
$cdcv_test_transients = array();

if ( ! function_exists( 'get_transient' ) ) {
    function get_transient( string $key ) { // NOSONAR - WordPress API stub
        global $cdcv_test_transients;
        return $cdcv_test_transients[ $key ] ?? false;
    }
}

if ( ! function_exists( 'set_transient' ) ) {
    function set_transient( string $key, $value, int $expiration = 0 ): bool { // NOSONAR - matches WP API signature
        global $cdcv_test_transients;
        $cdcv_test_transients[ $key ] = $value;
        return true;
    }
}

/* ------------------------------------------------------------------
 * Text / i18n stubs.
 * ----------------------------------------------------------------*/
if ( ! function_exists( '__' ) ) {
    function __( string $text, string $domain = 'default' ): string { // NOSONAR - matches WP API signature
        return $text;
    }
}

if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( string $text ): string { // NOSONAR - WordPress API stub
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_html__' ) ) {
    function esc_html__( string $text, string $domain = 'default' ): string { // NOSONAR - WordPress API stub
        return esc_html( $text );
    }
}

if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( string $text ): string { // NOSONAR - WordPress API stub
        return htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
    }
}

if ( ! function_exists( 'esc_url' ) ) {
    function esc_url( string $url ): string { // NOSONAR - WordPress API stub
        return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
    }
}

if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( string $url, array $protocols = null ): string { // NOSONAR - WordPress API stub
        $sanitized = filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
        if ( ! empty( $protocols ) && ! empty( $sanitized ) ) {
            $scheme = parse_url( $sanitized, PHP_URL_SCHEME );
            if ( $scheme && ! in_array( strtolower( $scheme ), $protocols, true ) ) {
                return '';
            }
        }
        return $sanitized;
    }
}

if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string { // NOSONAR - WordPress API stub
        return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
    }
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string { // NOSONAR - WordPress API stub
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
    function wp_strip_all_tags( string $text ): string { // NOSONAR - WordPress API stub
        return strip_tags( $text );
    }
}

if ( ! function_exists( 'wp_timezone' ) ) {
    function wp_timezone(): DateTimeZone { // NOSONAR - WordPress API stub
        return new DateTimeZone( 'UTC' );
    }
}

if ( ! function_exists( 'wp_date' ) ) {
    function wp_date( string $format, int $timestamp = 0 ): string { // NOSONAR - WordPress API stub
        $dt = new DateTimeImmutable( '@' . $timestamp );
        return $dt->format( $format );
    }
}

/* ------------------------------------------------------------------
 * WP_Error stub.
 * ----------------------------------------------------------------*/
if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error { // NOSONAR - WordPress API stub
        private string $code;
        private string $message;

        public function __construct( string $code = '', string $message = '' ) {
            $this->code    = $code;
            $this->message = $message;
        }

        public function get_error_code(): string { // NOSONAR - WordPress API stub
            return $this->code;
        }

        public function get_error_message(): string { // NOSONAR - WordPress API stub
            return $this->message;
        }
    }
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ): bool { // NOSONAR - WordPress API stub
        return $thing instanceof WP_Error;
    }
}

/* ------------------------------------------------------------------
 * HTTP stub (wp_remote_get) — overridable via global callback.
 * ----------------------------------------------------------------*/
global $cdcv_test_http_response;
$cdcv_test_http_response = null;

if ( ! function_exists( 'wp_remote_get' ) ) {
    function wp_remote_get( string $url, array $args = array() ) { // NOSONAR - WordPress API stub
        global $cdcv_test_http_response;
        if ( is_callable( $cdcv_test_http_response ) ) {
            return ( $cdcv_test_http_response )( $url, $args );
        }
        return $cdcv_test_http_response;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
    function wp_remote_retrieve_response_code( $response ): int { // NOSONAR - WordPress API stub
        return $response['response']['code'] ?? 0;
    }
}

if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
    function wp_remote_retrieve_body( $response ): string { // NOSONAR - WordPress API stub
        return $response['body'] ?? '';
    }
}

/* ------------------------------------------------------------------
 * Hook stubs (no-ops for unit tests).
 * ----------------------------------------------------------------*/
if ( ! function_exists( 'add_action' ) ) {
    function add_action(): void { /* No-op stub */ } // NOSONAR
}

if ( ! function_exists( 'add_shortcode' ) ) {
    function add_shortcode(): void { /* No-op stub */ } // NOSONAR
}

if ( ! function_exists( 'wp_enqueue_style' ) ) {
    function wp_enqueue_style(): void { /* No-op stub */ } // NOSONAR
}

if ( ! function_exists( 'wp_enqueue_script' ) ) {
    function wp_enqueue_script(): void { /* No-op stub */ } // NOSONAR
}

if ( ! function_exists( 'shortcode_atts' ) ) {
    function shortcode_atts( array $defaults, $atts, string $shortcode = '' ): array { // NOSONAR - matches WP API signature
        $atts = (array) $atts;
        $result = $defaults;
        foreach ( $atts as $key => $value ) {
            if ( array_key_exists( $key, $result ) ) {
                $result[ $key ] = $value;
            }
        }
        return $result;
    }
}

/* ------------------------------------------------------------------
 * Load plugin classes.
 * ----------------------------------------------------------------*/
require_once dirname( __DIR__ ) . '/includes/class-cdcv-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-cdcv-fetcher.php';
require_once dirname( __DIR__ ) . '/includes/class-cdcv-parser.php';
require_once dirname( __DIR__ ) . '/includes/class-cdcv-shortcode.php';

