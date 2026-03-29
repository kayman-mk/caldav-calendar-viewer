<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CalDavCVFetcher class.
 */
class CalDavCVFetcherUnitTest extends TestCase {

    protected function setUp(): void {
        global $cdcv_test_options, $cdcv_test_transients, $cdcv_test_http_response;
        $cdcv_test_options       = array();
        $cdcv_test_transients    = array();
        $cdcv_test_http_response = null;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    private function configureFeed( string $id, string $url, string $username = '', string $password = '' ): void {
        $passwordEnc = ! empty( $password ) ? CalDavCVSettings::encrypt( $password ) : '';
        update_option( 'cdcv_feeds', array(
            $id => array( 'url' => $url, 'username' => $username, 'password' => $passwordEnc ),
        ) );
    }

    private function setHttpResponse( int $statusCode, string $body ): void {
        global $cdcv_test_http_response;
        $cdcv_test_http_response = array(
            'response' => array( 'code' => $statusCode ),
            'body'     => $body,
        );
    }

    private function setHttpError( string $message ): void {
        global $cdcv_test_http_response;
        $cdcv_test_http_response = new WP_Error( 'http_request_failed', $message );
    }

    /* ------------------------------------------------------------------
     * Validation errors
     * ----------------------------------------------------------------*/

    public function test_should_returnError_when_feedIdIsEmpty(): void {
        $result = CalDavCVFetcher::fetch( '' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'cdcv_no_id', $result->get_error_code() );
    }

    public function test_should_returnError_when_feedIdIsNotConfigured(): void {
        $result = CalDavCVFetcher::fetch( 'unknown-feed' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'cdcv_unknown_feed', $result->get_error_code() );
        $this->assertStringContainsString( 'unknown-feed', $result->get_error_message() );
    }

    public function test_should_returnError_when_feedUrlIsEmpty(): void {
        update_option( 'cdcv_feeds', array(
            'empty-url' => array( 'url' => '', 'username' => '', 'password' => '' ),
        ) );

        $result = CalDavCVFetcher::fetch( 'empty-url' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'cdcv_no_url', $result->get_error_code() );
    }

    /* ------------------------------------------------------------------
     * Successful fetch
     * ----------------------------------------------------------------*/

    public function test_should_returnIcalBody_when_httpRequestSucceeds(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        // Disable cache so we always hit HTTP.
        update_option( 'cdcv_cache_ttl', 0 );

        $result = CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 'BEGIN:VCALENDAR', $result );
    }

    /* ------------------------------------------------------------------
     * HTTP errors
     * ----------------------------------------------------------------*/

    public function test_should_returnWpError_when_httpRequestFails(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpError( 'Connection timeout' );
        update_option( 'cdcv_cache_ttl', 0 );

        $result = CalDavCVFetcher::fetch( 'team' );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_should_returnHttpError_when_statusCodeIsNot2xx(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpResponse( 401, 'Unauthorized' );
        update_option( 'cdcv_cache_ttl', 0 );

        $result = CalDavCVFetcher::fetch( 'team' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'cdcv_http_error', $result->get_error_code() );
        $this->assertStringContainsString( '401', $result->get_error_message() );
    }

    public function test_should_returnHttpError_when_statusIs500(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpResponse( 500, 'Internal Server Error' );
        update_option( 'cdcv_cache_ttl', 0 );

        $result = CalDavCVFetcher::fetch( 'team' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( '500', $result->get_error_message() );
    }

    /* ------------------------------------------------------------------
     * Caching
     * ----------------------------------------------------------------*/

    public function test_should_returnCachedResponse_when_transientExists(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );

        // Pre-populate transient cache.
        $cacheKey = 'cdcv_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $cdcv_test_transients;
        $cdcv_test_transients[ $cacheKey ] = 'CACHED:VCALENDAR';

        // HTTP should NOT be called; set it to error to prove it.
        $this->setHttpError( 'Should not be reached' );

        $result = CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 'CACHED:VCALENDAR', $result );
    }

    public function test_should_skipCache_when_ttlIsZero(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 0 );
        $this->setHttpResponse( 200, 'FRESH:VCALENDAR' );

        $result = CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 'FRESH:VCALENDAR', $result );
    }

    public function test_should_storeInCache_when_fetchSucceedsAndTtlPositive(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'NEW:VCALENDAR' );

        CalDavCVFetcher::fetch( 'team' );

        $cacheKey = 'cdcv_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $cdcv_test_transients;
        $this->assertSame( 'NEW:VCALENDAR', $cdcv_test_transients[ $cacheKey ] );
    }

    public function test_should_notStoreInCache_when_fetchFails(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 404, 'Not Found' );

        CalDavCVFetcher::fetch( 'team' );

        $cacheKey = 'cdcv_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $cdcv_test_transients;
        $this->assertArrayNotHasKey( $cacheKey, $cdcv_test_transients );
    }

    /* ------------------------------------------------------------------
     * Authentication header
     * ----------------------------------------------------------------*/

    public function test_should_sendAuthHeader_when_credentialsConfigured(): void {
        $this->configureFeed( 'private', 'https://example.com/private.ics', 'admin', 's3cret' );
        update_option( 'cdcv_cache_ttl', 0 );

        $capturedArgs = null;
        global $cdcv_test_http_response;
        $cdcv_test_http_response = function ( string $_url, array $args ) use ( &$capturedArgs ) {
            $capturedArgs = $args;
            return array( 'response' => array( 'code' => 200 ), 'body' => 'OK' );
        };

        CalDavCVFetcher::fetch( 'private' );

        $this->assertNotNull( $capturedArgs );
        $this->assertArrayHasKey( 'headers', $capturedArgs );
        $this->assertArrayHasKey( 'Authorization', $capturedArgs['headers'] );
        $this->assertStringStartsWith( 'Basic ', $capturedArgs['headers']['Authorization'] );

        $decoded = base64_decode( substr( $capturedArgs['headers']['Authorization'], 6 ) );
        $this->assertSame( 'admin:s3cret', $decoded );
    }

    public function test_should_notSendAuthHeader_when_noCredentialsConfigured(): void {
        $this->configureFeed( 'public', 'https://example.com/public.ics' );
        update_option( 'cdcv_cache_ttl', 0 );

        $capturedArgs = null;
        global $cdcv_test_http_response;
        $cdcv_test_http_response = function ( string $_url, array $args ) use ( &$capturedArgs ) {
            $capturedArgs = $args;
            return array( 'response' => array( 'code' => 200 ), 'body' => 'OK' );
        };

        CalDavCVFetcher::fetch( 'public' );

        $this->assertNotNull( $capturedArgs );
        $this->assertArrayNotHasKey( 'headers', $capturedArgs );
    }

    /* ------------------------------------------------------------------
     * getCacheEntryCount()
     * ----------------------------------------------------------------*/

    public function test_should_returnZero_when_nothingHasBeenCached(): void {
        $this->assertSame( 0, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_returnOne_when_singleFeedIsCached(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 1, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_returnTwo_when_twoDistinctFeedsAreCached(): void {
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        $this->configureFeed( 'feed-a', 'https://example.com/a.ics' );
        CalDavCVFetcher::fetch( 'feed-a' );

        $this->configureFeed( 'feed-b', 'https://example.com/b.ics' );
        CalDavCVFetcher::fetch( 'feed-b' );

        $this->assertSame( 2, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_notDoubleCount_when_sameFeedFetchedTwice(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        CalDavCVFetcher::fetch( 'team' );
        // Second fetch hits the cache; registry must not grow.
        CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 1, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_returnZero_when_transientHasExpired(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        CalDavCVFetcher::fetch( 'team' );

        // Simulate transient expiry by wiping the in-memory store.
        global $cdcv_test_transients;
        $cdcv_test_transients = array();

        $this->assertSame( 0, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_returnZero_when_cachingIsDisabled(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 0 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 0, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_countPreExistingTransient_when_fetchedAfterRegistryWasEmpty(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );

        // Simulate a transient that was written before the registry feature existed
        // (e.g. a transient created by a previous plugin version, or a manual set_transient call).
        $cacheKey = 'cdcv_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $cdcv_test_transients;
        $cdcv_test_transients[ $cacheKey ] = 'PRE-EXISTING:VCALENDAR';
        // Registry is deliberately left empty – simulates state after plugin upgrade.

        // On the first fetch the cache is hit; the key must be registered.
        $this->setHttpError( 'Should not be reached' );
        CalDavCVFetcher::fetch( 'team' );

        $this->assertSame( 1, CalDavCVFetcher::getCacheEntryCount() );
    }

    /* ------------------------------------------------------------------
     * clearCache()
     * ----------------------------------------------------------------*/

    public function test_should_returnZero_when_clearingEmptyCache(): void {
        $this->assertSame( 0, CalDavCVFetcher::clearCache() );
    }

    public function test_should_returnOne_when_clearingCacheWithSingleEntry(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );
        CalDavCVFetcher::fetch( 'team' );

        $cleared = CalDavCVFetcher::clearCache();

        $this->assertSame( 1, $cleared );
    }

    public function test_should_returnTwo_when_clearingCacheWithTwoEntries(): void {
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        $this->configureFeed( 'feed-a', 'https://example.com/a.ics' );
        CalDavCVFetcher::fetch( 'feed-a' );
        $this->configureFeed( 'feed-b', 'https://example.com/b.ics' );
        CalDavCVFetcher::fetch( 'feed-b' );

        $cleared = CalDavCVFetcher::clearCache();

        $this->assertSame( 2, $cleared );
    }

    public function test_should_removeCachedTransients_when_clearCacheIsCalled(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );
        CalDavCVFetcher::fetch( 'team' );

        CalDavCVFetcher::clearCache();

        $this->assertSame( 0, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_resetRegistry_when_clearCacheIsCalled(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );
        CalDavCVFetcher::fetch( 'team' );

        CalDavCVFetcher::clearCache();

        // Re-fetch: cache count must be 1 again, proving the registry was reset cleanly.
        CalDavCVFetcher::fetch( 'team' );
        $this->assertSame( 1, CalDavCVFetcher::getCacheEntryCount() );
    }

    public function test_should_notCountAlreadyExpiredEntries_when_clearing(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'cdcv_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );
        CalDavCVFetcher::fetch( 'team' );

        // Simulate transient expiry before clearing.
        global $cdcv_test_transients;
        $cdcv_test_transients = array();

        // delete_transient returns false for missing keys; cleared count should be 0.
        $cleared = CalDavCVFetcher::clearCache();
        $this->assertSame( 0, $cleared );
    }
}

