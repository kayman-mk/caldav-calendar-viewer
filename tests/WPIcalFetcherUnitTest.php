<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the WPIcalFetcher class.
 */
class WPIcalFetcherUnitTest extends TestCase {

    protected function setUp(): void {
        global $wpical_test_options, $wpical_test_transients, $wpical_test_http_response;
        $wpical_test_options       = array();
        $wpical_test_transients    = array();
        $wpical_test_http_response = null;
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    private function configureFeed( string $id, string $url, string $username = '', string $password = '' ): void {
        $passwordEnc = ! empty( $password ) ? WPIcalSettings::encrypt( $password ) : '';
        update_option( 'wpical_feeds', array(
            $id => array( 'url' => $url, 'username' => $username, 'password' => $passwordEnc ),
        ) );
    }

    private function setHttpResponse( int $statusCode, string $body ): void {
        global $wpical_test_http_response;
        $wpical_test_http_response = array(
            'response' => array( 'code' => $statusCode ),
            'body'     => $body,
        );
    }

    private function setHttpError( string $message ): void {
        global $wpical_test_http_response;
        $wpical_test_http_response = new WP_Error( 'http_request_failed', $message );
    }

    /* ------------------------------------------------------------------
     * Validation errors
     * ----------------------------------------------------------------*/

    public function test_should_returnError_when_feedIdIsEmpty(): void {
        $result = WPIcalFetcher::fetch( '' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wpical_no_id', $result->get_error_code() );
    }

    public function test_should_returnError_when_feedIdIsNotConfigured(): void {
        $result = WPIcalFetcher::fetch( 'unknown-feed' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wpical_unknown_feed', $result->get_error_code() );
        $this->assertStringContainsString( 'unknown-feed', $result->get_error_message() );
    }

    public function test_should_returnError_when_feedUrlIsEmpty(): void {
        update_option( 'wpical_feeds', array(
            'empty-url' => array( 'url' => '', 'username' => '', 'password' => '' ),
        ) );

        $result = WPIcalFetcher::fetch( 'empty-url' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wpical_no_url', $result->get_error_code() );
    }

    /* ------------------------------------------------------------------
     * Successful fetch
     * ----------------------------------------------------------------*/

    public function test_should_returnIcalBody_when_httpRequestSucceeds(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpResponse( 200, 'BEGIN:VCALENDAR' );

        // Disable cache so we always hit HTTP.
        update_option( 'wpical_cache_ttl', 0 );

        $result = WPIcalFetcher::fetch( 'team' );

        $this->assertSame( 'BEGIN:VCALENDAR', $result );
    }

    /* ------------------------------------------------------------------
     * HTTP errors
     * ----------------------------------------------------------------*/

    public function test_should_returnWpError_when_httpRequestFails(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpError( 'Connection timeout' );
        update_option( 'wpical_cache_ttl', 0 );

        $result = WPIcalFetcher::fetch( 'team' );

        $this->assertInstanceOf( WP_Error::class, $result );
    }

    public function test_should_returnHttpError_when_statusCodeIsNot2xx(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpResponse( 401, 'Unauthorized' );
        update_option( 'wpical_cache_ttl', 0 );

        $result = WPIcalFetcher::fetch( 'team' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertSame( 'wpical_http_error', $result->get_error_code() );
        $this->assertStringContainsString( '401', $result->get_error_message() );
    }

    public function test_should_returnHttpError_when_statusIs500(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        $this->setHttpResponse( 500, 'Internal Server Error' );
        update_option( 'wpical_cache_ttl', 0 );

        $result = WPIcalFetcher::fetch( 'team' );

        $this->assertInstanceOf( WP_Error::class, $result );
        $this->assertStringContainsString( '500', $result->get_error_message() );
    }

    /* ------------------------------------------------------------------
     * Caching
     * ----------------------------------------------------------------*/

    public function test_should_returnCachedResponse_when_transientExists(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'wpical_cache_ttl', 3600 );

        // Pre-populate transient cache.
        $cacheKey = 'wpical_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $wpical_test_transients;
        $wpical_test_transients[ $cacheKey ] = 'CACHED:VCALENDAR';

        // HTTP should NOT be called; set it to error to prove it.
        $this->setHttpError( 'Should not be reached' );

        $result = WPIcalFetcher::fetch( 'team' );

        $this->assertSame( 'CACHED:VCALENDAR', $result );
    }

    public function test_should_skipCache_when_ttlIsZero(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'wpical_cache_ttl', 0 );
        $this->setHttpResponse( 200, 'FRESH:VCALENDAR' );

        $result = WPIcalFetcher::fetch( 'team' );

        $this->assertSame( 'FRESH:VCALENDAR', $result );
    }

    public function test_should_storeInCache_when_fetchSucceedsAndTtlPositive(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'wpical_cache_ttl', 3600 );
        $this->setHttpResponse( 200, 'NEW:VCALENDAR' );

        WPIcalFetcher::fetch( 'team' );

        $cacheKey = 'wpical_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $wpical_test_transients;
        $this->assertSame( 'NEW:VCALENDAR', $wpical_test_transients[ $cacheKey ] );
    }

    public function test_should_notStoreInCache_when_fetchFails(): void {
        $this->configureFeed( 'team', 'https://example.com/team.ics' );
        update_option( 'wpical_cache_ttl', 3600 );
        $this->setHttpResponse( 404, 'Not Found' );

        WPIcalFetcher::fetch( 'team' );

        $cacheKey = 'wpical_feed_cache_' . md5( 'team|https://example.com/team.ics' );
        global $wpical_test_transients;
        $this->assertArrayNotHasKey( $cacheKey, $wpical_test_transients );
    }

    /* ------------------------------------------------------------------
     * Authentication header
     * ----------------------------------------------------------------*/

    public function test_should_sendAuthHeader_when_credentialsConfigured(): void {
        $this->configureFeed( 'private', 'https://example.com/private.ics', 'admin', 's3cret' );
        update_option( 'wpical_cache_ttl', 0 );

        $capturedArgs = null;
        global $wpical_test_http_response;
        $wpical_test_http_response = function ( string $url, array $args ) use ( &$capturedArgs ) {
            $capturedArgs = $args;
            return array( 'response' => array( 'code' => 200 ), 'body' => 'OK' );
        };

        WPIcalFetcher::fetch( 'private' );

        $this->assertNotNull( $capturedArgs );
        $this->assertArrayHasKey( 'headers', $capturedArgs );
        $this->assertArrayHasKey( 'Authorization', $capturedArgs['headers'] );
        $this->assertStringStartsWith( 'Basic ', $capturedArgs['headers']['Authorization'] );

        $decoded = base64_decode( substr( $capturedArgs['headers']['Authorization'], 6 ) );
        $this->assertSame( 'admin:s3cret', $decoded );
    }

    public function test_should_notSendAuthHeader_when_noCredentialsConfigured(): void {
        $this->configureFeed( 'public', 'https://example.com/public.ics' );
        update_option( 'wpical_cache_ttl', 0 );

        $capturedArgs = null;
        global $wpical_test_http_response;
        $wpical_test_http_response = function ( string $url, array $args ) use ( &$capturedArgs ) {
            $capturedArgs = $args;
            return array( 'response' => array( 'code' => 200 ), 'body' => 'OK' );
        };

        WPIcalFetcher::fetch( 'public' );

        $this->assertNotNull( $capturedArgs );
        $this->assertArrayNotHasKey( 'headers', $capturedArgs );
    }
}

