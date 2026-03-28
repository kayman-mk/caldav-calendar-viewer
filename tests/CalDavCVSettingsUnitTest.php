<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CalDavCVSettings class.
 */
class CalDavCVSettingsUnitTest extends TestCase {

    protected function setUp(): void {
        // Reset the in-memory option store before each test.
        global $cdcv_test_options;
        $cdcv_test_options = array();
    }

    /* ------------------------------------------------------------------
     * encrypt / decrypt
     * ----------------------------------------------------------------*/

    public function test_should_returnOriginalValue_when_encryptedThenDecrypted(): void {
        $plain = 'my-secret-password';

        $encrypted = CalDavCVSettings::encrypt( $plain );
        $decrypted = CalDavCVSettings::decrypt( $encrypted );

        $this->assertSame( $plain, $decrypted );
    }

    public function test_should_produceDifferentCiphertext_when_encryptingSameValueTwice(): void {
        $plain = 'same-password';

        $first  = CalDavCVSettings::encrypt( $plain );
        $second = CalDavCVSettings::encrypt( $plain );

        // Different IV each time → different ciphertext.
        $this->assertNotSame( $first, $second );
    }

    public function test_should_returnEmptyString_when_decryptingEmptyString(): void {
        $result = CalDavCVSettings::decrypt( '' );

        $this->assertSame( '', $result );
    }

    public function test_should_returnEmptyString_when_decryptingTamperedCiphertext(): void {
        $encrypted = CalDavCVSettings::encrypt( 'sensitive-data' );
        $this->assertNotEmpty( $encrypted );

        // Tamper with a byte in the middle of the base64-encoded ciphertext.
        $bytes    = base64_decode( $encrypted );
        $bytes[0] = $bytes[0] === "\x00" ? "\x01" : "\x00";
        $tampered = base64_encode( $bytes );

        $result = CalDavCVSettings::decrypt( $tampered );

        $this->assertSame( '', $result );
    }

    public function test_should_returnEmptyString_when_decryptingGarbageInput(): void {
        $this->assertSame( '', CalDavCVSettings::decrypt( 'not-valid-encrypted-data' ) );
        $this->assertSame( '', CalDavCVSettings::decrypt( '!!!' ) );
    }

    /* ------------------------------------------------------------------
     * getAllFeeds
     * ----------------------------------------------------------------*/

    public function test_should_returnEmptyArray_when_noFeedsConfigured(): void {
        $feeds = CalDavCVSettings::getAllFeeds();

        $this->assertSame( array(), $feeds );
    }

    public function test_should_returnAllFeeds_when_feedsExistInOptions(): void {
        update_option( 'cdcv_feeds', array(
            'team' => array( 'url' => 'https://example.com/team.ics', 'username' => 'user', 'password' => 'enc' ),
            'hr'   => array( 'url' => 'https://example.com/hr.ics', 'username' => '', 'password' => '' ),
        ) );

        $feeds = CalDavCVSettings::getAllFeeds();

        $this->assertCount( 2, $feeds );
        $this->assertArrayHasKey( 'team', $feeds );
        $this->assertArrayHasKey( 'hr', $feeds );
    }

    public function test_should_returnEmptyArray_when_optionIsNotArray(): void {
        update_option( 'cdcv_feeds', 'invalid-string' );

        $feeds = CalDavCVSettings::getAllFeeds();

        $this->assertSame( array(), $feeds );
    }

    /* ------------------------------------------------------------------
     * getFeed
     * ----------------------------------------------------------------*/

    public function test_should_returnNull_when_feedIdDoesNotExist(): void {
        $feed = CalDavCVSettings::getFeed( 'nonexistent' );

        $this->assertNull( $feed );
    }

    public function test_should_returnFeedData_when_feedIdExists(): void {
        update_option( 'cdcv_feeds', array(
            'team' => array( 'url' => 'https://example.com/team.ics', 'username' => 'admin', 'password' => 'secret_enc' ),
        ) );

        $feed = CalDavCVSettings::getFeed( 'team' );

        $this->assertNotNull( $feed );
        $this->assertSame( 'https://example.com/team.ics', $feed['url'] );
        $this->assertSame( 'admin', $feed['username'] );
        $this->assertSame( 'secret_enc', $feed['password_enc'] );
    }

    public function test_should_returnEmptyDefaults_when_feedHasMissingKeys(): void {
        update_option( 'cdcv_feeds', array(
            'minimal' => array(),
        ) );

        $feed = CalDavCVSettings::getFeed( 'minimal' );

        $this->assertNotNull( $feed );
        $this->assertSame( '', $feed['url'] );
        $this->assertSame( '', $feed['username'] );
        $this->assertSame( '', $feed['password_enc'] );
    }

    /* ------------------------------------------------------------------
     * getFeedPassword
     * ----------------------------------------------------------------*/

    public function test_should_returnEmptyString_when_feedDoesNotExist(): void {
        $password = CalDavCVSettings::getFeedPassword( 'nonexistent' );

        $this->assertSame( '', $password );
    }

    public function test_should_returnDecryptedPassword_when_feedHasEncryptedPassword(): void {
        $plain     = 'super-secret';
        $encrypted = CalDavCVSettings::encrypt( $plain );

        update_option( 'cdcv_feeds', array(
            'team' => array( 'url' => 'https://example.com', 'username' => 'u', 'password' => $encrypted ),
        ) );

        $password = CalDavCVSettings::getFeedPassword( 'team' );

        $this->assertSame( $plain, $password );
    }

    /* ------------------------------------------------------------------
     * getCacheTtl
     * ----------------------------------------------------------------*/

    public function test_should_returnDefaultTtl_when_optionNotSet(): void {
        $ttl = CalDavCVSettings::getCacheTtl();

        $this->assertSame( 3600, $ttl );
    }

    public function test_should_returnConfiguredTtl_when_optionIsSet(): void {
        update_option( 'cdcv_cache_ttl', 7200 );

        $ttl = CalDavCVSettings::getCacheTtl();

        $this->assertSame( 7200, $ttl );
    }

    /* ------------------------------------------------------------------
     * sanitizeFeeds
     * ----------------------------------------------------------------*/

    public function test_should_returnEmptyArray_when_inputIsNotArray(): void {
        $settings = new CalDavCVSettings();
        $result   = $settings->sanitizeFeeds( 'not-an-array' );

        $this->assertSame( array(), $result );
    }

    public function test_should_skipEntry_when_feedIdIsEmpty(): void {
        $settings = new CalDavCVSettings();
        $result   = $settings->sanitizeFeeds( array(
            array( 'id' => '', 'url' => 'https://example.com' ),
        ) );

        $this->assertSame( array(), $result );
    }

    public function test_should_sanitizeFeedCorrectly_when_validInputProvided(): void {
        $settings = new CalDavCVSettings();
        $result   = $settings->sanitizeFeeds( array(
            array(
                'id'       => 'My-Feed',
                'url'      => 'https://example.com/cal.ics',
                'username' => ' admin ',
                'password' => 'secret123',
            ),
        ) );

        // sanitize_key lowercases and strips invalid chars.
        $this->assertArrayHasKey( 'my-feed', $result );
        $this->assertSame( 'https://example.com/cal.ics', $result['my-feed']['url'] );
        $this->assertSame( 'admin', $result['my-feed']['username'] );
        // Password should be encrypted (non-empty, different from plain text).
        $this->assertNotEmpty( $result['my-feed']['password'] );
        $this->assertNotSame( 'secret123', $result['my-feed']['password'] );
    }

    public function test_should_rejectNonHttpSchemes_when_feedUrlUsesFtp(): void {
        $settings = new CalDavCVSettings();
        $result   = $settings->sanitizeFeeds( array(
            array(
                'id'       => 'evil',
                'url'      => 'ftp://internal-server/secret.ics',
                'username' => '',
                'password' => '',
            ),
        ) );

        $this->assertArrayHasKey( 'evil', $result );
        $this->assertSame( '', $result['evil']['url'] );
    }

    public function test_should_keepExistingPassword_when_passwordFieldIsEmpty(): void {
        // Pre-populate a feed with an encrypted password.
        $existingEnc = CalDavCVSettings::encrypt( 'old-password' );
        update_option( 'cdcv_feeds', array(
            'team' => array( 'url' => 'https://example.com', 'username' => 'u', 'password' => $existingEnc ),
        ) );

        $settings = new CalDavCVSettings();
        $result   = $settings->sanitizeFeeds( array(
            array( 'id' => 'team', 'url' => 'https://example.com/new.ics', 'username' => 'u', 'password' => '' ),
        ) );

        // Password should be preserved from existing option.
        $this->assertSame( $existingEnc, $result['team']['password'] );
    }
}

