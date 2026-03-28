<?php
/**
 * Lightweight iCal (RFC 5545) parser.
 *
 * Extracts VEVENT components from raw iCal text and returns them as
 * associative arrays sorted by start date.  Supports basic recurrence
 * rules (RRULE) for DAILY, WEEKLY, MONTHLY and YEARLY frequencies.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CalDavCVParser {

    /** Safety limit for generated recurrence instances per event. */
    private const MAX_RECURRENCE_INSTANCES = 730;

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

        // Normalize line endings first, then unfold per RFC 5545 §3.1.
        $icalText = str_replace( "\r\n", "\n", $icalText );
        $icalText = str_replace( "\r", "\n", $icalText );
        $unfolded = preg_replace( "/\n[ \t]/", '', $icalText );
        if ( null === $unfolded ) {
            return $events;
        }
        $icalText = $unfolded;

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

            if ( ! empty( $event['rrule'] ) ) {
                $occurrences = self::expandRecurring( $event, $rangeStart, $rangeEnd );
                foreach ( $occurrences as $occ ) {
                    if ( self::eventInRange( $occ, $rangeStart, $rangeEnd ) ) {
                        $events[] = $occ;
                    }
                }
            } else {
                unset( $event['rrule'], $event['exdates'] );
                if ( self::eventInRange( $event, $rangeStart, $rangeEnd ) ) {
                    $events[] = $event;
                }
            }
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
            'rrule'       => '',
            'exdates'     => array(),
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
                case 'RRULE':
                    $event['rrule'] = trim( $value );
                    break;
                case 'EXDATE':
                    foreach ( explode( ',', $value ) as $d ) {
                        $normalized = self::normalizeDatetime( trim( $d ) );
                        if ( '' !== $normalized ) {
                            $event['exdates'][] = $normalized;
                        }
                    }
                    break;
                default:
                    break;
            }
        }

        return $event;
    }

    /* ------------------------------------------------------------------
     * Recurrence expansion (RRULE)
     * ----------------------------------------------------------------*/

    /**
     * Parse an RRULE value string into its component parts.
     *
     * @param string $rrule Raw RRULE value, e.g. "FREQ=WEEKLY;BYDAY=MO,WE,FR;COUNT=10".
     * @return array Parsed rule with keys: freq, interval, count, until, byday, bymonthday.
     */
    private static function parseRRule( string $rrule ): array {
        $rule = array(
            'freq'       => '',
            'interval'   => 1,
            'count'      => 0,
            'until'      => '',
            'byday'      => array(),
            'bymonthday' => array(),
        );

        foreach ( explode( ';', $rrule ) as $param ) {
            $kv = explode( '=', $param, 2 );
            if ( count( $kv ) < 2 ) {
                continue;
            }
            switch ( strtoupper( trim( $kv[0] ) ) ) {
                case 'FREQ':
                    $rule['freq'] = strtoupper( trim( $kv[1] ) );
                    break;
                case 'INTERVAL':
                    $rule['interval'] = max( 1, (int) $kv[1] );
                    break;
                case 'COUNT':
                    $rule['count'] = (int) $kv[1];
                    break;
                case 'UNTIL':
                    $rule['until'] = self::normalizeDatetime( trim( $kv[1] ) );
                    break;
                case 'BYDAY':
                    $rule['byday'] = array_map(
                        function ( $d ) {
                            return strtoupper( trim( $d ) );
                        },
                        explode( ',', $kv[1] )
                    );
                    break;
                case 'BYMONTHDAY':
                    $rule['bymonthday'] = array_map( 'intval', explode( ',', $kv[1] ) );
                    break;
            }
        }

        return $rule;
    }

    /**
     * Expand a recurring event into individual occurrences.
     *
     * Handles DAILY, WEEKLY (with optional BYDAY), MONTHLY (with optional
     * BYMONTHDAY), and YEARLY frequencies.  Respects COUNT, UNTIL, INTERVAL,
     * and EXDATE.  Includes a skip-forward optimisation so that events whose
     * DTSTART is far in the past do not cause excessive iteration.
     *
     * @param array  $event      Parsed event array (must contain 'rrule').
     * @param string $rangeStart Inclusive range start (Y-m-d), may be empty.
     * @param string $rangeEnd   Exclusive range end   (Y-m-d), may be empty.
     * @return array List of occurrence events with 'rrule'/'exdates' removed.
     */
    private static function expandRecurring( array $event, string $rangeStart, string $rangeEnd ): array {
        $rule = self::parseRRule( $event['rrule'] );
        if ( empty( $rule['freq'] ) ) {
            $clean = $event;
            unset( $clean['rrule'], $clean['exdates'] );
            return array( $clean );
        }

        $origStart = date_create( $event['dtstart'] );
        if ( false === $origStart ) {
            $clean = $event;
            unset( $clean['rrule'], $clean['exdates'] );
            return array( $clean );
        }

        $origDateStr = $origStart->format( 'Y-m-d' );
        $allDay      = $event['all_day'];
        $timePart    = ( ! $allDay && strlen( $event['dtstart'] ) > 10 )
            ? substr( $event['dtstart'], 10 )
            : '';

        // Compute event duration in seconds.
        $durationSec = 0;
        if ( ! empty( $event['dtend'] ) ) {
            $endDt = date_create( $event['dtend'] );
            if ( false !== $endDt ) {
                $durationSec = $endDt->getTimestamp() - $origStart->getTimestamp();
            }
        }

        // Build EXDATE lookup set (date portion only).
        $exdates   = isset( $event['exdates'] ) ? $event['exdates'] : array();
        $exdateSet = array_flip(
            array_map(
                function ( $d ) {
                    return substr( $d, 0, 10 );
                },
                $exdates
            )
        );

        // Compute an exclusive upper-bound date for iteration.
        $untilDate = ! empty( $rule['until'] ) ? substr( $rule['until'], 0, 10 ) : '';
        $ceiling   = '';
        if ( ! empty( $untilDate ) ) {
            // UNTIL is inclusive (RFC 5545); shift by +1 day for exclusive ceiling.
            $ceiling = gmdate( 'Y-m-d', strtotime( $untilDate . ' +1 day' ) );
        }
        if ( ! empty( $rangeEnd ) ) {
            $ceiling = ( empty( $ceiling ) || $rangeEnd < $ceiling ) ? $rangeEnd : $ceiling;
        }
        if ( empty( $ceiling ) ) {
            $ceiling = gmdate( 'Y-m-d', strtotime( $origDateStr . ' +2 years' ) );
        }

        $countLimit  = $rule['count'] > 0 ? $rule['count'] : self::MAX_RECURRENCE_INSTANCES;
        $occurrences = array();
        $count       = 0;

        // ---- WEEKLY with BYDAY -------------------------------------------
        if ( 'WEEKLY' === $rule['freq'] && ! empty( $rule['byday'] ) ) {
            $dayMap = array(
                'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4,
                'FR' => 5, 'SA' => 6, 'SU' => 7,
            );
            $targetDays = array();
            foreach ( $rule['byday'] as $d ) {
                $abbr = preg_replace( '/^-?\d+/', '', $d );
                if ( isset( $dayMap[ $abbr ] ) ) {
                    $targetDays[] = $dayMap[ $abbr ];
                }
            }
            sort( $targetDays );

            // Monday of the DTSTART week.
            $wkDay    = (int) $origStart->format( 'N' );
            $weekBase = date_create( $origDateStr );
            $weekBase->modify( '-' . ( $wkDay - 1 ) . ' days' );

            // Skip forward when rangeStart is far ahead (no COUNT).
            if ( $rule['count'] <= 0 && ! empty( $rangeStart ) ) {
                $skipTarget = date_create( $rangeStart );
                $skipTarget->modify( '-2 weeks' );
                if ( $skipTarget > $weekBase ) {
                    $weeksDiff     = (int) ( ( $skipTarget->getTimestamp() - $weekBase->getTimestamp() ) / ( 7 * 86400 ) );
                    $periodsToSkip = (int) floor( $weeksDiff / $rule['interval'] );
                    if ( $periodsToSkip > 0 ) {
                        $weekBase->modify( '+' . ( $periodsToSkip * $rule['interval'] ) . ' weeks' );
                    }
                }
            }

            while ( $count < $countLimit ) {
                $wbStr = $weekBase->format( 'Y-m-d' );
                if ( $wbStr >= $ceiling ) {
                    break;
                }

                foreach ( $targetDays as $td ) {
                    if ( $count >= $countLimit ) {
                        break;
                    }
                    $offset  = $td - 1;
                    $cand    = date_create( $wbStr );
                    $cand->modify( '+' . $offset . ' days' );
                    $candStr = $cand->format( 'Y-m-d' );

                    if ( $candStr < $origDateStr || $candStr >= $ceiling ) {
                        continue;
                    }

                    ++$count;
                    if ( isset( $exdateSet[ $candStr ] ) ) {
                        continue;
                    }

                    $occurrences[] = self::buildOccurrence( $event, $candStr, $timePart, $durationSec, $allDay );
                }

                $weekBase->modify( '+' . $rule['interval'] . ' weeks' );
            }

        // ---- MONTHLY with BYMONTHDAY ------------------------------------
        } elseif ( 'MONTHLY' === $rule['freq'] && ! empty( $rule['bymonthday'] ) ) {
            $monthBase = date_create( $origStart->format( 'Y-m-01' ) );
            sort( $rule['bymonthday'] );

            // Skip forward.
            if ( $rule['count'] <= 0 && ! empty( $rangeStart ) ) {
                $skipTarget = date_create( $rangeStart );
                $skipTarget->modify( '-2 months' );
                if ( $skipTarget > $monthBase ) {
                    $monthsDiff    = ( (int) $skipTarget->format( 'Y' ) - (int) $monthBase->format( 'Y' ) ) * 12
                                   + ( (int) $skipTarget->format( 'm' ) - (int) $monthBase->format( 'm' ) );
                    $periodsToSkip = (int) floor( $monthsDiff / $rule['interval'] );
                    if ( $periodsToSkip > 0 ) {
                        $monthBase->modify( '+' . ( $periodsToSkip * $rule['interval'] ) . ' months' );
                    }
                }
            }

            while ( $count < $countLimit ) {
                if ( $monthBase->format( 'Y-m-d' ) >= $ceiling ) {
                    break;
                }
                $ym          = $monthBase->format( 'Y-m' );
                $daysInMonth = (int) $monthBase->format( 't' );

                foreach ( $rule['bymonthday'] as $day ) {
                    if ( $count >= $countLimit ) {
                        break;
                    }
                    if ( $day < 1 || $day > $daysInMonth ) {
                        continue;
                    }

                    $candStr = sprintf( '%s-%02d', $ym, $day );
                    if ( $candStr < $origDateStr || $candStr >= $ceiling ) {
                        continue;
                    }

                    ++$count;
                    if ( isset( $exdateSet[ $candStr ] ) ) {
                        continue;
                    }

                    $occurrences[] = self::buildOccurrence( $event, $candStr, $timePart, $durationSec, $allDay );
                }

                $monthBase->modify( '+' . $rule['interval'] . ' months' );
            }

        // ---- Simple: DAILY / WEEKLY / MONTHLY / YEARLY -------------------
        } else {
            $modStr = self::freqToModify( $rule['freq'], $rule['interval'] );
            if ( empty( $modStr ) ) {
                $clean = $event;
                unset( $clean['rrule'], $clean['exdates'] );
                return array( $clean );
            }

            $current = date_create( $origDateStr );

            // Skip forward.
            if ( $rule['count'] <= 0 && ! empty( $rangeStart ) ) {
                $skipTarget = date_create( $rangeStart );
                $skipTarget->modify( '-1 month' );
                if ( $skipTarget > $current ) {
                    $diffDays      = (int) ( ( $skipTarget->getTimestamp() - $current->getTimestamp() ) / 86400 );
                    $daysPerPeriod = 1;
                    switch ( $rule['freq'] ) {
                        case 'DAILY':
                            $daysPerPeriod = $rule['interval'];
                            break;
                        case 'WEEKLY':
                            $daysPerPeriod = 7 * $rule['interval'];
                            break;
                        case 'MONTHLY':
                            $daysPerPeriod = 30 * $rule['interval'];
                            break;
                        case 'YEARLY':
                            $daysPerPeriod = 365 * $rule['interval'];
                            break;
                    }
                    $skip = (int) floor( $diffDays / $daysPerPeriod );
                    if ( $skip > 0 ) {
                        $current->modify( '+' . ( $skip * $rule['interval'] ) . ' ' . self::freqUnit( $rule['freq'] ) );
                    }
                }
            }

            while ( $count < $countLimit ) {
                $candStr = $current->format( 'Y-m-d' );
                if ( $candStr >= $ceiling ) {
                    break;
                }

                if ( $candStr >= $origDateStr ) {
                    ++$count;
                    if ( ! isset( $exdateSet[ $candStr ] ) ) {
                        $occurrences[] = self::buildOccurrence( $event, $candStr, $timePart, $durationSec, $allDay );
                    }
                }

                $current->modify( $modStr );
            }
        }

        return $occurrences;
    }

    /**
     * Build a single occurrence event from a recurring template.
     *
     * @param array  $event       Original recurring event.
     * @param string $dateStr     Date for this occurrence (Y-m-d).
     * @param string $timePart    Time portion (e.g. ' 09:00') or empty.
     * @param int    $durationSec Event duration in seconds.
     * @param bool   $allDay      Whether the event is all-day.
     * @return array Occurrence event with rrule/exdates removed.
     */
    private static function buildOccurrence( array $event, string $dateStr, string $timePart, int $durationSec, bool $allDay ): array {
        $occ = $event;
        unset( $occ['rrule'], $occ['exdates'] );

        $occ['dtstart'] = $allDay ? $dateStr : $dateStr . $timePart;

        if ( ! empty( $event['dtend'] ) && $durationSec > 0 ) {
            if ( $allDay ) {
                $days   = (int) round( $durationSec / 86400 );
                $endDt  = date_create( $dateStr );
                $endDt->modify( '+' . $days . ' days' );
                $occ['dtend'] = $endDt->format( 'Y-m-d' );
            } else {
                $startDt = date_create( $occ['dtstart'] );
                $startDt->modify( '+' . $durationSec . ' seconds' );
                $occ['dtend'] = $startDt->format( 'Y-m-d H:i' );
            }
        }

        return $occ;
    }

    /**
     * Return a DateTime::modify() string for the given frequency and interval.
     */
    private static function freqToModify( string $freq, int $interval ): string {
        switch ( $freq ) {
            case 'DAILY':
                return "+{$interval} days";
            case 'WEEKLY':
                return "+{$interval} weeks";
            case 'MONTHLY':
                return "+{$interval} months";
            case 'YEARLY':
                return "+{$interval} years";
            default:
                return '';
        }
    }

    /**
     * Return the plural unit name for a frequency (used in modify strings).
     */
    private static function freqUnit( string $freq ): string {
        switch ( $freq ) {
            case 'DAILY':
                return 'days';
            case 'WEEKLY':
                return 'weeks';
            case 'MONTHLY':
                return 'months';
            case 'YEARLY':
                return 'years';
            default:
                return 'days';
        }
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
