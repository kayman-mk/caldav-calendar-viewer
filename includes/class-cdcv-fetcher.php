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

    /** Option key used to track all active cache transient names. */
    private const CACHE_KEY_REGISTRY = 'cdcv_cache_key_registry';

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

        if ( false !== $cached ) {
            // Ensure the key is tracked even when the transient pre-dates the registry
            // (e.g. after a plugin update) or was read before the write path ran.
            self::registerCacheKey( $cacheKey );
            return $cached;
        }

        return null;
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
            self::registerCacheKey( $cacheKey );
        }
    }

    /**
     * Record a transient key in the persistent registry so it can be counted
     * and deleted later without a direct database query.
     *
     * @param string $cacheKey Transient name (without the _transient_ prefix).
     */
    private static function registerCacheKey( string $cacheKey ): void {
        $registry = get_option( self::CACHE_KEY_REGISTRY, array() );
        if ( ! is_array( $registry ) ) {
            $registry = array();
        }
        if ( ! in_array( $cacheKey, $registry, true ) ) {
            $registry[] = $cacheKey;
            update_option( self::CACHE_KEY_REGISTRY, $registry, false );
        }
    }

    /**
     * Return the number of calendar feed responses currently held in the cache.
     *
     * Entries whose transient has already expired are not counted.
     *
     * @return int Number of live cache entries.
     */
    public static function getCacheEntryCount(): int {
        $registry = get_option( self::CACHE_KEY_REGISTRY, array() );
        if ( ! is_array( $registry ) ) {
            return 0;
        }
        return count( array_filter( $registry, function ( string $key ): bool {
            return false !== get_transient( $key );
        } ) );
    }

    /**
     * Delete all cached calendar feed responses and reset the registry.
     *
     * @return int Number of entries that were deleted.
     */
    public static function clearCache(): int {
        $registry = get_option( self::CACHE_KEY_REGISTRY, array() );
        if ( ! is_array( $registry ) ) {
            delete_option( self::CACHE_KEY_REGISTRY );
            return 0;
        }
        $count = 0;
        foreach ( $registry as $key ) {
            if ( delete_transient( $key ) ) {
                $count++;
            }
        }
        delete_option( self::CACHE_KEY_REGISTRY );
        return $count;
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
            'timeout'             => 7,
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
