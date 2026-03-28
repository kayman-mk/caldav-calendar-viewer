<?php
/**
 * Lightweight iCal (RFC 5545) parser.
 *
 * Extracts VEVENT components from raw iCal text and returns them as
 * associative arrays sorted by start date.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPIcalParser {

    /**
     * Parse raw iCal text into an array of events.
     *
     * Each event is an associative array with keys:
     *   summary, description, location, dtstart, dtend, uid, url
     *
     * When $rangeStart and/or $rangeEnd are provided the result set is
     * filtered to only include events that overlap with the given window.
     * An event is considered inside the range when its start date is before
     * rangeEnd AND its end date (or start date if no end) is on or after
     * rangeStart.
     *
     * @param string $icalText   Raw iCal (.ics) content.
     * @param string $rangeStart Inclusive start of the date range (Y-m-d), empty to skip.
     * @param string $rangeEnd   Exclusive end of the date range (Y-m-d), empty to skip.
     * @return array<int, array<string, string>> Sorted list of events.
     */
    public static function parse( string $icalText, string $rangeStart = '', string $rangeEnd = '' ): array {
        $events = array();

        // Unfold long lines per RFC 5545 §3.1.
        $unfolded = preg_replace( "/\r\n[ \t]/", '', $icalText );
        if ( null === $unfolded ) {
            return $events;
        }
        $icalText = str_replace( "\r", "\n", $unfolded );

        // Extract VEVENT blocks.
        preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $icalText, $matches );

        if ( empty( $matches[1] ) ) {
            return $events;
        }

        foreach ( $matches[1] as $eventBlock ) {
            $event = self::parseEventBlock( $eventBlock );
            if ( empty( $event['dtstart'] ) ) {
                continue;
            }

            // Filter by date range when boundaries are provided.
            if ( ! self::eventInRange( $event, $rangeStart, $rangeEnd ) ) {
                continue;
            }

            $events[] = $event;
        }

        // Sort events by start date ascending.
        usort( $events, function ( $a, $b ) {
            return strcmp( $a['dtstart'], $b['dtstart'] );
        });

        return $events;
    }

    /**
     * Check whether an event overlaps with the requested date range.
     *
     * The comparison uses the date portion (first 10 chars) of dtstart / dtend.
     * An event overlaps when:
     *   eventEnd >= rangeStart  AND  eventStart < rangeEnd
     *
     * @param array  $event      Parsed event array.
     * @param string $rangeStart Inclusive start boundary (Y-m-d), empty = no lower bound.
     * @param string $rangeEnd   Exclusive end boundary (Y-m-d), empty = no upper bound.
     * @return bool
     */
    private static function eventInRange( array $event, string $rangeStart, string $rangeEnd ): bool {
        if ( empty( $rangeStart ) && empty( $rangeEnd ) ) {
            return true;
        }

        $eventStart = substr( $event['dtstart'], 0, 10 );
        $eventEnd   = ! empty( $event['dtend'] ) ? substr( $event['dtend'], 0, 10 ) : $eventStart;

        $afterStart = empty( $rangeStart ) || $eventEnd >= $rangeStart;
        $beforeEnd  = empty( $rangeEnd ) || $eventStart < $rangeEnd;

        return $afterStart && $beforeEnd;
    }

    /**
     * Parse a single VEVENT block into an associative array.
     *
     * @param string $block The text between BEGIN:VEVENT and END:VEVENT.
     * @return array<string, string>
     */
    private static function parseEventBlock( string $block ): array {
        $event = array(
            'summary'     => '',
            'description' => '',
            'location'    => '',
            'dtstart'     => '',
            'dtend'       => '',
            'uid'         => '',
            'url'         => '',
            'all_day'     => false,
        );

        $lines = explode( "\n", trim( $block ) );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            if ( empty( $line ) ) {
                continue;
            }

            // Split property name (with optional params) from value.
            $colonPos = strpos( $line, ':' );
            if ( false === $colonPos ) {
                continue;
            }

            $propertyPart = substr( $line, 0, $colonPos );
            $value        = substr( $line, $colonPos + 1 );

            // Strip parameters (e.g., DTSTART;TZID=Europe/Berlin:20260101T090000).
            $propertyName = strtoupper( explode( ';', $propertyPart )[0] );

            switch ( $propertyName ) {
                case 'SUMMARY':
                    $event['summary'] = self::unescape( $value );
                    break;
                case 'DESCRIPTION':
                    $event['description'] = self::unescape( $value );
                    break;
                case 'LOCATION':
                    $event['location'] = self::unescape( $value );
                    break;
                case 'DTSTART':
                    $event['dtstart'] = self::normalizeDatetime( $value );
                    if ( strlen( $value ) === 8 ) {
                        $event['all_day'] = true;
                    }
                    break;
                case 'DTEND':
                    $event['dtend'] = self::normalizeDatetime( $value );
                    break;
                case 'UID':
                    $event['uid'] = trim( $value );
                    break;
                case 'URL':
                    $event['url'] = trim( $value );
                    break;
                default:
                    // Ignore unsupported iCal properties.
                    break;
            }
        }

        return $event;
    }

    /**
     * Normalize an iCal datetime value to 'Y-m-d H:i' or 'Y-m-d' for all-day events.
     *
     * @param string $value Raw datetime string, e.g. 20260315T140000Z or 20260315.
     * @return string Human-readable date(time).
     */
    private static function normalizeDatetime( string $value ): string {
        $value = trim( $value );

        // All-day event (date only).
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
            return sprintf( '%s-%s-%s', $m[1], $m[2], $m[3] );
        }

        // Date with time.
        if ( preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/', $value, $m ) ) {
            return sprintf( '%s-%s-%s %s:%s', $m[1], $m[2], $m[3], $m[4], $m[5] );
        }

        return $value;
    }

    /**
     * Un-escape iCal text values per RFC 5545 §3.3.11.
     *
     * @param string $text Escaped iCal text.
     * @return string Plain text.
     */
    private static function unescape( string $text ): string {
        $text = str_replace( '\\n', "\n", $text );
        $text = str_replace( '\\N', "\n", $text );
        $text = str_replace( '\\,', ',', $text );
        $text = str_replace( '\\;', ';', $text );
        $text = str_replace( '\\\\', '\\', $text );
        return trim( $text );
    }
}
