<?php
/**
 * Admin settings page for ICal Calendar View.
 *
 * Manages multiple iCal calendar feeds (each with URL, username, password)
 * and a global cache TTL via the WordPress Settings API.
 *
 * Feeds are stored as a single option containing an associative array keyed
 * by a user-chosen ID:
 *
 *   icalcv_feeds = [
 *       'team' => [ 'url' => '…', 'username' => '…', 'password' => '…' ],
 *       'hr'   => [ 'url' => '…', 'username' => '',  'password' => ''  ],
 *   ]
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICalCVSettings {

    /** Settings page slug. */
    private const PAGE_SLUG = 'icalcv-settings';

    /** Option keys stored in the database. */
    private const OPT_FEEDS     = 'icalcv_feeds';
    private const OPT_CACHE_TTL = 'icalcv_cache_ttl';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'addSettingsPage' ) );
        add_action( 'admin_init', array( $this, 'registerSettings' ) );
    }

    /**
     * Add the settings page under the WordPress "Settings" menu.
     */
    public function addSettingsPage(): void {
        add_options_page(
            __( 'ICal Calendar View Settings', 'ical-calendar-view' ),
            __( 'ICal Calendar View', 'ical-calendar-view' ),
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'renderSettingsPage' )
        );
    }

    /**
     * Register settings with the WordPress Settings API.
     */
    public function registerSettings(): void {
        register_setting( self::PAGE_SLUG, self::OPT_FEEDS, array(
            'type'              => 'array',
            'sanitize_callback' => array( $this, 'sanitizeFeeds' ),
            'default'           => array(),
        ) );

        register_setting( self::PAGE_SLUG, self::OPT_CACHE_TTL, array(
            'type'              => 'integer',
            'sanitize_callback' => 'absint',
            'default'           => 3600,
        ) );
    }

    /* ------------------------------------------------------------------
     * Sanitization
     * ----------------------------------------------------------------*/

    /**
     * Sanitize the feeds array submitted from the admin form.
     *
     * @param mixed $input Raw form data.
     * @return array Sanitized feeds keyed by ID.
     */
    public function sanitizeFeeds( $input ): array {
        $clean = array();

        if ( ! is_array( $input ) ) {
            return $clean;
        }

        foreach ( $input as $feed ) {
            $id = isset( $feed['id'] ) ? sanitize_key( $feed['id'] ) : '';
            if ( empty( $id ) ) {
                continue;
            }

            // Keep existing encrypted password when the field is left empty.
            $existing = self::getFeed( $id );

            $clean[ $id ] = array(
                'url'      => isset( $feed['url'] ) ? esc_url_raw( $feed['url'], array( 'http', 'https' ) ) : '',
                'username' => isset( $feed['username'] ) ? sanitize_text_field( $feed['username'] ) : '',
                'password' => isset( $feed['password'] ) && $feed['password'] !== ''
                    ? self::encrypt( $feed['password'] )
                    : ( $existing['password_enc'] ?? '' ),
            );
        }

        return $clean;
    }

    /* ------------------------------------------------------------------
     * Admin page rendering
     * ----------------------------------------------------------------*/

    /**
     * Render the full admin settings page.
     */
    public function renderSettingsPage(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $feeds    = self::getAllFeeds();
        $cacheTtl = self::getCacheTtl();
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form action="options.php" method="post">
                <?php settings_fields( self::PAGE_SLUG ); ?>

                <h2><?php esc_html_e( 'Calendar Feeds', 'ical-calendar-view' ); ?></h2>
                <p class="description">
                    <?php esc_html_e( 'Define one or more iCal feeds. Each feed needs a unique ID that you reference in the shortcode.', 'ical-calendar-view' ); ?>
                </p>

                <div id="icalcv-feeds-container">
                    <?php
                    if ( empty( $feeds ) ) {
                        $this->renderFeedRow( '', array(), 0 );
                    } else {
                        $index = 0;
                        foreach ( $feeds as $feedId => $feed ) {
                            $this->renderFeedRow( $feedId, $feed, $index );
                            $index++;
                        }
                    }
                    ?>
                </div>

                <p>
                    <button type="button" class="button" id="icalcv-add-feed">
                        <?php esc_html_e( '+ Add Feed', 'ical-calendar-view' ); ?>
                    </button>
                </p>

                <hr />

                <h2><?php esc_html_e( 'General Settings', 'ical-calendar-view' ); ?></h2>
                <div class="icalcv-field-row">
                    <label for="<?php echo esc_attr( self::OPT_CACHE_TTL ); ?>">
                        <?php esc_html_e( 'Cache Lifetime (seconds)', 'ical-calendar-view' ); ?>
                    </label>
                    <input type="number" id="<?php echo esc_attr( self::OPT_CACHE_TTL ); ?>"
                           name="<?php echo esc_attr( self::OPT_CACHE_TTL ); ?>"
                           value="<?php echo esc_attr( $cacheTtl ); ?>"
                           class="small-text" min="0" step="1" />
                    <p class="description">
                        <?php esc_html_e( 'How long (in seconds) fetched calendar data should be cached. Set to 0 to disable caching.', 'ical-calendar-view' ); ?>
                    </p>
                </div>

                <?php submit_button(); ?>
            </form>

            <hr />
            <h2><?php esc_html_e( 'Usage', 'ical-calendar-view' ); ?></h2>
            <p><?php esc_html_e( 'Add the following shortcode to any page or post, referencing a feed by its ID:', 'ical-calendar-view' ); ?></p>
            <code>[icalcv_calendar id="my-feed"]</code>
            <p><?php esc_html_e( 'Optional: set how many months to display:', 'ical-calendar-view' ); ?></p>
            <code>[icalcv_calendar id="my-feed" months="3"]</code>

            <?php if ( ! empty( $feeds ) ) : ?>
                <h3><?php esc_html_e( 'Configured Feed IDs', 'ical-calendar-view' ); ?></h3>
                <ul>
                    <?php foreach ( array_keys( $feeds ) as $fid ) : ?>
                        <li><code><?php echo esc_html( $fid ); ?></code></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <style>
            .icalcv-field-row { margin-bottom: 16px; }
            .icalcv-field-row label { display: block; font-weight: 600; margin-bottom: 4px; }
            .icalcv-field-row .description { margin-top: 4px; }
            .icalcv-feed-fields { display: grid; grid-template-columns: 1fr; gap: 12px; }
            .icalcv-feed-field label { display: block; font-weight: 600; margin-bottom: 4px; }
            .icalcv-feed-field .description { margin-top: 4px; }
        </style>

        <script>
        (function () {
            var container = document.getElementById('icalcv-feeds-container');
            var addBtn    = document.getElementById('icalcv-add-feed');
            var index     = container.querySelectorAll('.icalcv-feed-row').length;

            addBtn.addEventListener('click', function () {
                var tpl = document.querySelector('.icalcv-feed-row');
                var clone = tpl.cloneNode(true);
                clone.querySelectorAll('input').forEach(function (input) {
                    input.name  = input.name.replace(/\[\d+\]/, '[' + index + ']');
                    input.value = '';

                    var oldId = input.id;
                    if (oldId) {
                        var newId = oldId.replace(/_\d+_/, '_' + index + '_');
                        input.id = newId;
                        var label = clone.querySelector('label[for="' + oldId + '"]');
                        if (label) {
                            label.setAttribute('for', newId);
                        }
                    }
                });
                container.appendChild(clone);
                index++;
            });

            container.addEventListener('click', function (e) {
                if (e.target.classList.contains('icalcv-remove-feed')) {
                    var rows = container.querySelectorAll('.icalcv-feed-row');
                    if (rows.length > 1) {
                        e.target.closest('.icalcv-feed-row').remove();
                    }
                }
            });
        })();
        </script>
        <?php
    }

    /**
     * Render a single feed configuration row.
     *
     * @param string $feedId The feed identifier.
     * @param array  $feed   Feed data (url, username, password).
     * @param int    $index  Row index for form array naming.
     */
    private function renderFeedRow( string $feedId, array $feed, int $index ): void {
        $namePrefix = self::OPT_FEEDS . '[' . $index . ']';
        $idPrefix   = 'icalcv_feed_' . $index . '_';
        ?>
        <div class="icalcv-feed-row" style="border:1px solid #ccd0d4; padding:12px; margin-bottom:10px; background:#f6f7f7; border-radius:4px;">
            <div class="icalcv-feed-fields">
                <div class="icalcv-feed-field">
                    <label for="<?php echo esc_attr( $idPrefix . 'id' ); ?>"><?php esc_html_e( 'Feed ID', 'ical-calendar-view' ); ?></label>
                    <input type="text" id="<?php echo esc_attr( $idPrefix . 'id' ); ?>"
                           name="<?php echo esc_attr( $namePrefix . '[id]' ); ?>"
                           value="<?php echo esc_attr( $feedId ); ?>"
                           class="regular-text" placeholder="my-team-calendar"
                           pattern="[a-z0-9\-_]+" title="<?php esc_attr_e( 'Lowercase letters, numbers, hyphens and underscores only', 'ical-calendar-view' ); ?>" required />
                    <p class="description"><?php esc_html_e( 'Unique identifier used in the shortcode (lowercase, no spaces).', 'ical-calendar-view' ); ?></p>
                </div>
                <div class="icalcv-feed-field">
                    <label for="<?php echo esc_attr( $idPrefix . 'url' ); ?>"><?php esc_html_e( 'iCal Feed URL', 'ical-calendar-view' ); ?></label>
                    <input type="url" id="<?php echo esc_attr( $idPrefix . 'url' ); ?>"
                           name="<?php echo esc_attr( $namePrefix . '[url]' ); ?>"
                           value="<?php echo esc_attr( $feed['url'] ?? '' ); ?>"
                           class="regular-text" placeholder="https://example.com/calendar.ics" />
                </div>
                <div class="icalcv-feed-field">
                    <label for="<?php echo esc_attr( $idPrefix . 'username' ); ?>"><?php esc_html_e( 'Username', 'ical-calendar-view' ); ?></label>
                    <input type="text" id="<?php echo esc_attr( $idPrefix . 'username' ); ?>"
                           name="<?php echo esc_attr( $namePrefix . '[username]' ); ?>"
                           value="<?php echo esc_attr( $feed['username'] ?? '' ); ?>"
                           class="regular-text" autocomplete="off" />
                    <p class="description"><?php esc_html_e( 'Leave blank if the feed does not require authentication.', 'ical-calendar-view' ); ?></p>
                </div>
                <div class="icalcv-feed-field">
                    <label for="<?php echo esc_attr( $idPrefix . 'password' ); ?>"><?php esc_html_e( 'Password', 'ical-calendar-view' ); ?></label>
                    <input type="password" id="<?php echo esc_attr( $idPrefix . 'password' ); ?>"
                           name="<?php echo esc_attr( $namePrefix . '[password]' ); ?>"
                           value="" class="regular-text" autocomplete="new-password"
                           placeholder="<?php echo ! empty( $feed['password'] ) ? '••••••••' : ''; ?>" />
                    <p class="description"><?php esc_html_e( 'Leave blank to keep the current password. The password is stored encrypted.', 'ical-calendar-view' ); ?></p>
                </div>
            </div>
            <p style="text-align:right; margin:0;">
                <button type="button" class="button icalcv-remove-feed"><?php esc_html_e( 'Remove', 'ical-calendar-view' ); ?></button>
            </p>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Static helpers for encryption / decryption
     * ----------------------------------------------------------------*/

    /**
     * Encrypt a value using OpenSSL (AES-256-CBC) with HMAC authentication.
     *
     * The output format is: base64( HMAC-SHA256(32 bytes) + IV(16 bytes) + ciphertext ).
     * The HMAC prevents ciphertext tampering and padding oracle attacks.
     *
     * @param string $value Plain-text value.
     * @return string Base64-encoded authenticated cipher text, or empty string on failure.
     */
    public static function encrypt( string $value ): string {
        $result = '';

        if ( function_exists( 'openssl_encrypt' ) ) {
            $key    = hash( 'sha256', AUTH_KEY, true );
            $iv     = openssl_random_pseudo_bytes( 16 );
            $cipher = openssl_encrypt( $value, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

            if ( false !== $cipher ) {
                $hmac   = hash_hmac( 'sha256', $iv . $cipher, $key, true );
                $result = base64_encode( $hmac . $iv . $cipher );
            }
        }

        return $result;
    }

    /**
     * Decrypt a previously encrypted value.
     *
     * Supports the current HMAC-authenticated format (HMAC + IV + ciphertext)
     * and the legacy format (IV + ciphertext) for backwards compatibility.
     *
     * @param string $encrypted Base64-encoded cipher text.
     * @return string Decrypted plain-text value, or empty string on failure.
     */
    public static function decrypt( string $encrypted ): string {
        if ( empty( $encrypted ) || ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $data = base64_decode( $encrypted, true );
        if ( false === $data ) {
            return '';
        }

        $key = hash( 'sha256', AUTH_KEY, true );

        // Data long enough for the authenticated format must pass HMAC verification.
        // Falling back to legacy on HMAC failure would defeat tamper detection.
        $result = ( strlen( $data ) >= 49 )
            ? self::decryptAuthenticated( $data, $key )
            : self::decryptLegacy( $data, $key );

        return $result ?? '';
    }

    /**
     * Attempt decryption using the HMAC-authenticated format.
     *
     * Expected layout: HMAC(32 bytes) + IV(16 bytes) + ciphertext(>=1 byte).
     *
     * @param string $data Raw decoded bytes.
     * @param string $key  Derived encryption key.
     * @return string|null Decrypted value, or null if format does not match.
     */
    private static function decryptAuthenticated( string $data, string $key ): ?string {
        if ( strlen( $data ) < 49 ) {
            return null;
        }

        $hmac = substr( $data, 0, 32 );
        $iv   = substr( $data, 32, 16 );
        $raw  = substr( $data, 48 );

        $expectedHmac = hash_hmac( 'sha256', $iv . $raw, $key, true );
        if ( ! hash_equals( $expectedHmac, $hmac ) ) {
            return null;
        }

        $decrypted = openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Attempt decryption using the legacy format (IV + ciphertext, no HMAC).
     *
     * Expected layout: IV(16 bytes) + ciphertext(>=1 byte).
     *
     * @param string $data Raw decoded bytes.
     * @param string $key  Derived encryption key.
     * @return string|null Decrypted value, or null if format does not match.
     */
    private static function decryptLegacy( string $data, string $key ): ?string {
        if ( strlen( $data ) < 17 ) {
            return null;
        }

        $iv  = substr( $data, 0, 16 );
        $raw = substr( $data, 16 );

        $decrypted = openssl_decrypt( $raw, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );

        return $decrypted !== false ? $decrypted : null;
    }

    /* ------------------------------------------------------------------
     * Public static getters used by other classes
     * ----------------------------------------------------------------*/

    /**
     * Get all configured feeds.
     *
     * @return array<string, array> Feeds keyed by ID.
     */
    public static function getAllFeeds(): array {
        $feeds = get_option( self::OPT_FEEDS, array() );

        return is_array( $feeds ) ? $feeds : array();
    }

    /**
     * Get a single feed configuration by its ID.
     *
     * @param string $feedId The feed identifier.
     * @return array{url: string, username: string, password_enc: string}|null Feed data or null.
     */
    public static function getFeed( string $feedId ): ?array {
        $feeds = self::getAllFeeds();

        if ( ! isset( $feeds[ $feedId ] ) ) {
            return null;
        }

        $feed = $feeds[ $feedId ];

        return array(
            'url'          => $feed['url'] ?? '',
            'username'     => $feed['username'] ?? '',
            'password_enc' => $feed['password'] ?? '',
        );
    }

    /**
     * Get the decrypted password for a specific feed.
     *
     * @param string $feedId The feed identifier.
     * @return string Plain-text password (empty string if not set).
     */
    public static function getFeedPassword( string $feedId ): string {
        $feed = self::getFeed( $feedId );

        return $feed ? self::decrypt( $feed['password_enc'] ) : '';
    }

    /**
     * Get the global cache TTL.
     *
     * @return int Cache lifetime in seconds.
     */
    public static function getCacheTtl(): int {
        return (int) get_option( self::OPT_CACHE_TTL, 3600 );
    }
}

