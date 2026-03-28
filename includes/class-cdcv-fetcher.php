<?php
/**
 * Fetches iCal data from a remote URL with optional Basic Auth and caching.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CalDavCVFetcher {

    /** Transient key prefix used for caching. */
    private const CACHE_KEY = 'cdcv_feed_cache';

    /** Maximum allowed response body size in bytes (2 MB). */
    private const MAX_RESPONSE_SIZE = 2 * 1024 * 1024;

    /**
     * Fetch the iCal feed contents for a given feed ID.
     *
     * The feed URL, username, and password are resolved from the plugin settings
     * using the provided $feedId. Results are cached using WordPress transients
     * according to the configured TTL.
     *
     * @param string $feedId The feed identifier configured in Settings → CalDav Calendar Viewer.
     * @return string|WP_Error The raw iCal string on success, WP_Error on failure.
     */
    public static function fetch( string $feedId ) {
        $validationError = self::validateFeed( $feedId );
        if ( null !== $validationError ) {
            return $validationError;
        }

        $feed     = CalDavCVSettings::getFeed( $feedId );
        $url      = $feed['url'];
        $username = $feed['username'];
        $password = CalDavCVSettings::getFeedPassword( $feedId );

        $cached = self::getCachedResponse( $feedId, $url );
        if ( null !== $cached ) {
            return $cached;
        }

        $result = self::executeRequest( $url, $username, $password );

        if ( ! is_wp_error( $result ) ) {
            self::cacheResponse( $feedId, $url, $result );
        }

        return $result;
    }

    /**
     * Validate that a feed ID is provided and configured with a URL.
     *
     * @param string $feedId The feed identifier.
     * @return WP_Error|null Error on failure, null on success.
     */
    private static function validateFeed( string $feedId ): ?WP_Error {
        if ( empty( $feedId ) ) {
            return new WP_Error( 'cdcv_no_id', __( 'No feed ID provided. Use [cdcv_calendar id="your-feed-id"].', 'caldav-calendar-viewer' ) );
        }

        $feed = CalDavCVSettings::getFeed( $feedId );

        if ( null === $feed || empty( $feed['url'] ) ) {
            $errorCode = ( null === $feed ) ? 'cdcv_unknown_feed' : 'cdcv_no_url';
            $errorMsg  = ( null === $feed )
                ? /* translators: %s: feed ID */
                  __( 'Unknown calendar feed ID: "%s". Please configure it under Settings → CalDav Calendar Viewer.', 'caldav-calendar-viewer' )
                : /* translators: %s: feed ID */
                  __( 'No URL configured for feed "%s".', 'caldav-calendar-viewer' );

            return new WP_Error( $errorCode, sprintf( $errorMsg, $feedId ) );
        }

        return null;
    }

    /**
     * Return the cached response if available.
     *
     * @param string $feedId Feed identifier.
     * @param string $url    Feed URL.
     * @return string|null Cached body or null if not cached.
     */
    private static function getCachedResponse( string $feedId, string $url ): ?string {
        $cacheTtl = CalDavCVSettings::getCacheTtl();
        if ( $cacheTtl <= 0 ) {
            return null;
        }

        $cacheKey = self::CACHE_KEY . '_' . md5( $feedId . '|' . $url );
        $cached   = get_transient( $cacheKey );

        return ( false !== $cached ) ? $cached : null;
    }

    /**
     * Store the response body in the transient cache.
     *
     * @param string $feedId Feed identifier.
     * @param string $url    Feed URL.
     * @param string $body   Response body.
     */
    private static function cacheResponse( string $feedId, string $url, string $body ): void {
        $cacheTtl = CalDavCVSettings::getCacheTtl();
        if ( $cacheTtl > 0 ) {
            $cacheKey = self::CACHE_KEY . '_' . md5( $feedId . '|' . $url );
            set_transient( $cacheKey, $body, $cacheTtl );
        }
    }

    /**
     * Execute the HTTP request to fetch the iCal feed.
     *
     * @param string $url      Feed URL.
     * @param string $username Username for Basic Auth.
     * @param string $password Password for Basic Auth.
     * @return string|WP_Error Response body on success, WP_Error on failure.
     */
    private static function executeRequest( string $url, string $username, string $password ) {
        $args = array(
            'timeout'             => 30,
            'sslverify'           => true,
            'limit_response_size' => self::MAX_RESPONSE_SIZE,
        );

        if ( ! empty( $username ) && ! empty( $password ) ) {
            $args['headers'] = array(
                'Authorization' => 'Basic ' . base64_encode( $username . ':' . $password ),
            );
        }

        $response = wp_remote_get( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return new WP_Error(
                'cdcv_http_error',
                sprintf(
                    /* translators: %d: HTTP status code */
                    __( 'Failed to fetch iCal feed. HTTP status: %d', 'caldav-calendar-viewer' ),
                    $code
                )
            );
        }

        return wp_remote_retrieve_body( $response );
    }
}
