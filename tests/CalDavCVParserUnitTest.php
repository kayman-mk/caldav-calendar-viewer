<?php

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the CalDavCVParser class.
 */
class CalDavCVParserUnitTest extends TestCase {

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Build a minimal valid iCal string containing the given VEVENT blocks.
     */
    private function buildIcal( string ...$vevents ): string {
        $body = implode( "\n", $vevents );
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n{$body}\r\nEND:VCALENDAR";
    }

    /**
     * Build a single VEVENT block from key/value pairs.
     */
    private function buildEvent( array $props ): string {
        $lines = array( 'BEGIN:VEVENT' );
        foreach ( $props as $key => $value ) {
            $lines[] = "{$key}:{$value}";
        }
        $lines[] = 'END:VEVENT';
        return implode( "\r\n", $lines );
    }

    /* ------------------------------------------------------------------
     * parse() – basic extraction
     * ----------------------------------------------------------------*/

    public function test_should_returnEmptyArray_when_inputContainsNoEvents(): void {
        $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR";

        $result = CalDavCVParser::parse( $ical );

        $this->assertSame( array(), $result );
    }

    public function test_should_returnEmptyArray_when_inputIsEmptyString(): void {
        $result = CalDavCVParser::parse( '' );

        $this->assertSame( array(), $result );
    }

    public function test_should_parseSingleEvent_when_icalContainsOneVevent(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY'     => 'Team Meeting',
                'DTSTART'     => '20260327T090000Z',
                'DTEND'       => '20260327T100000Z',
                'LOCATION'    => 'Room 42',
                'DESCRIPTION' => 'Weekly sync',
                'UID'         => 'abc-123',
                'URL'         => 'https://example.com/meeting',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );

        $event = $events[0];
        $this->assertSame( 'Team Meeting', $event['summary'] );
        $this->assertSame( '2026-03-27 09:00', $event['dtstart'] );
        $this->assertSame( '2026-03-27 10:00', $event['dtend'] );
        $this->assertSame( 'Room 42', $event['location'] );
        $this->assertSame( 'Weekly sync', $event['description'] );
        $this->assertSame( 'abc-123', $event['uid'] );
        $this->assertSame( 'https://example.com/meeting', $event['url'] );
        $this->assertFalse( $event['all_day'] );
    }

    public function test_should_parseMultipleEvents_when_icalContainsSeveralVevents(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Event A',
                'DTSTART' => '20260328T140000Z',
            ) ),
            $this->buildEvent( array(
                'SUMMARY' => 'Event B',
                'DTSTART' => '20260327T080000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 2, $events );
    }

    public function test_should_skipEvent_when_dtStartIsMissing(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'No date event',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 0, $events );
    }

    /* ------------------------------------------------------------------
     * parse() – sorting
     * ----------------------------------------------------------------*/

    public function test_should_sortEventsByStartDateAscending_when_multipleEventsExist(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array( 'SUMMARY' => 'Later', 'DTSTART' => '20260330T100000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'Earlier', 'DTSTART' => '20260328T080000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'Middle', 'DTSTART' => '20260329T120000Z' ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertSame( 'Earlier', $events[0]['summary'] );
        $this->assertSame( 'Middle', $events[1]['summary'] );
        $this->assertSame( 'Later', $events[2]['summary'] );
    }

    /* ------------------------------------------------------------------
     * parse() – all-day events
     * ----------------------------------------------------------------*/

    public function test_should_detectAllDayEvent_when_dtStartHasDateOnly(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Holiday',
                'DTSTART' => '20260401',
                'DTEND'   => '20260402',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );
        $this->assertTrue( $events[0]['all_day'] );
        $this->assertSame( '2026-04-01', $events[0]['dtstart'] );
        $this->assertSame( '2026-04-02', $events[0]['dtend'] );
    }

    /* ------------------------------------------------------------------
     * parse() – datetime normalization
     * ----------------------------------------------------------------*/

    public function test_should_normalizeUtcDatetime_when_valueEndsWithZ(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'UTC Event',
                'DTSTART' => '20260315T143000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertSame( '2026-03-15 14:30', $events[0]['dtstart'] );
    }

    public function test_should_normalizeDatetime_when_valueHasNoZ(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Local Event',
                'DTSTART' => '20260601T180000',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertSame( '2026-06-01 18:00', $events[0]['dtstart'] );
    }

    /* ------------------------------------------------------------------
     * parse() – DTSTART with TZID parameter
     * ----------------------------------------------------------------*/

    public function test_should_parseDatetime_when_dtStartHasTzidParameter(): void {
        $event = "BEGIN:VEVENT\r\n"
               . "SUMMARY:Berlin Meeting\r\n"
               . "DTSTART;TZID=Europe/Berlin:20260401T090000\r\n"
               . "END:VEVENT";

        $ical   = $this->buildIcal( $event );
        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );
        $this->assertSame( '2026-04-01 09:00', $events[0]['dtstart'] );
    }

    /* ------------------------------------------------------------------
     * parse() – line unfolding (RFC 5545 §3.1)
     * ----------------------------------------------------------------*/

    public function test_should_unfoldLongLines_when_continuationLineStartsWithSpace(): void {
        $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
              . "BEGIN:VEVENT\r\n"
              . "SUMMARY:Very long su\r\n"
              . " bject line here\r\n"
              . "DTSTART:20260327T090000Z\r\n"
              . "END:VEVENT\r\n"
              . "END:VCALENDAR";

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );
        $this->assertSame( 'Very long subject line here', $events[0]['summary'] );
    }

    public function test_should_unfoldLongLines_when_continuationLineStartsWithTab(): void {
        $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
              . "BEGIN:VEVENT\r\n"
              . "SUMMARY:Tabbed su\r\n"
              . "\tbject\r\n"
              . "DTSTART:20260327T090000Z\r\n"
              . "END:VEVENT\r\n"
              . "END:VCALENDAR";

        $events = CalDavCVParser::parse( $ical );

        $this->assertSame( 'Tabbed subject', $events[0]['summary'] );
    }

    /* ------------------------------------------------------------------
     * parse() – text unescaping (RFC 5545 §3.3.11)
     * ----------------------------------------------------------------*/

    public function test_should_unescapeNewlines_when_descriptionContainsBackslashN(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY'     => 'Test',
                'DTSTART'     => '20260327T090000Z',
                'DESCRIPTION' => 'Line one\\nLine two\\NLine three',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertSame( "Line one\nLine two\nLine three", $events[0]['description'] );
    }

    public function test_should_unescapeSpecialChars_when_textContainsEscapedCommasSemicolonsBackslashes(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Hello\\, World\\; Path\\\\Foo',
                'DTSTART' => '20260327T090000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertSame( 'Hello, World; Path\\Foo', $events[0]['summary'] );
    }

    /* ------------------------------------------------------------------
     * parse() – date range filtering
     * ----------------------------------------------------------------*/

    public function test_should_returnAllEvents_when_noDateRangeProvided(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array( 'SUMMARY' => 'A', 'DTSTART' => '20250101T090000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'B', 'DTSTART' => '20271231T090000Z' ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 2, $events );
    }

    public function test_should_filterByRangeStart_when_onlyStartProvided(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array( 'SUMMARY' => 'Old', 'DTSTART' => '20250101T090000Z', 'DTEND' => '20250101T100000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'New', 'DTSTART' => '20260401T090000Z', 'DTEND' => '20260401T100000Z' ) )
        );

        $events = CalDavCVParser::parse( $ical, '2026-03-27' );

        $this->assertCount( 1, $events );
        $this->assertSame( 'New', $events[0]['summary'] );
    }

    public function test_should_filterByRangeEnd_when_onlyEndProvided(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array( 'SUMMARY' => 'Before', 'DTSTART' => '20260326T090000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'After', 'DTSTART' => '20260404T090000Z' ) )
        );

        $events = CalDavCVParser::parse( $ical, '', '2026-04-03' );

        $this->assertCount( 1, $events );
        $this->assertSame( 'Before', $events[0]['summary'] );
    }

    public function test_should_filterByBothBounds_when_fullRangeProvided(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array( 'SUMMARY' => 'Too Early', 'DTSTART' => '20260320T090000Z', 'DTEND' => '20260320T100000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'In Range', 'DTSTART' => '20260328T090000Z', 'DTEND' => '20260328T100000Z' ) ),
            $this->buildEvent( array( 'SUMMARY' => 'Too Late', 'DTSTART' => '20260410T090000Z', 'DTEND' => '20260410T100000Z' ) )
        );

        $events = CalDavCVParser::parse( $ical, '2026-03-27', '2026-04-03' );

        $this->assertCount( 1, $events );
        $this->assertSame( 'In Range', $events[0]['summary'] );
    }

    public function test_should_includeEvent_when_eventSpansRangeBoundary(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Multi-day',
                'DTSTART' => '20260326T090000Z',
                'DTEND'   => '20260329T170000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical, '2026-03-27', '2026-04-03' );

        $this->assertCount( 1, $events );
        $this->assertSame( 'Multi-day', $events[0]['summary'] );
    }

    public function test_should_excludeEvent_when_eventEndsBeforeRangeStart(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Ended',
                'DTSTART' => '20260320T090000Z',
                'DTEND'   => '20260326T170000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical, '2026-03-27', '2026-04-03' );

        $this->assertCount( 0, $events );
    }

    public function test_should_includeEvent_when_rangeEndIsExclusive(): void {
        // Event starts exactly on the range end → should be excluded.
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'On boundary',
                'DTSTART' => '20260403T000000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical, '2026-03-27', '2026-04-03' );

        $this->assertCount( 0, $events );
    }

    public function test_should_useStartDateAsEnd_when_eventHasNoDtEnd(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'No end',
                'DTSTART' => '20260328T090000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical, '2026-03-27', '2026-04-03' );

        $this->assertCount( 1, $events );
    }

    /* ------------------------------------------------------------------
     * parse() – \r line ending handling
     * ----------------------------------------------------------------*/

    public function test_should_parseCorrectly_when_inputUsesBareCrLineEndings(): void {
        $ical = "BEGIN:VCALENDAR\rVERSION:2.0\rBEGIN:VEVENT\rSUMMARY:CR Event\rDTSTART:20260327T090000Z\rEND:VEVENT\rEND:VCALENDAR";

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );
        $this->assertSame( 'CR Event', $events[0]['summary'] );
    }

    /* ------------------------------------------------------------------
     * parse() – bare LF line unfolding
     * ----------------------------------------------------------------*/

    public function test_should_unfoldLongLines_when_inputUsesBareLfContinuation(): void {
        $ical = "BEGIN:VCALENDAR\nVERSION:2.0\n"
              . "BEGIN:VEVENT\n"
              . "SUMMARY:Bare LF su\n"
              . " bject line\n"
              . "DTSTART:20260327T090000Z\n"
              . "END:VEVENT\n"
              . "END:VCALENDAR";

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );
        $this->assertSame( 'Bare LF subject line', $events[0]['summary'] );
    }

    /* ------------------------------------------------------------------
     * parse() – default fields
     * ----------------------------------------------------------------*/

    public function test_should_returnEmptyDefaults_when_eventHasOnlyDtStart(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array( 'DTSTART' => '20260327T090000Z' ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 1, $events );
        $this->assertSame( '', $events[0]['summary'] );
        $this->assertSame( '', $events[0]['description'] );
        $this->assertSame( '', $events[0]['location'] );
        $this->assertSame( '', $events[0]['dtend'] );
        $this->assertSame( '', $events[0]['uid'] );
        $this->assertSame( '', $events[0]['url'] );
        $this->assertFalse( $events[0]['all_day'] );
    }

    /* ------------------------------------------------------------------
     * parse() – RRULE basic recurrence
     * ----------------------------------------------------------------*/

    public function test_should_expandDailyRecurrence_when_rruleHasFreqDaily(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Daily Standup',
                'DTSTART' => '20260327T090000Z',
                'DTEND'   => '20260327T091500Z',
                'RRULE'   => 'FREQ=DAILY;COUNT=5',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 5, $events );
        $this->assertSame( '2026-03-27 09:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-03-28 09:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-03-29 09:00', $events[2]['dtstart'] );
        $this->assertSame( '2026-03-30 09:00', $events[3]['dtstart'] );
        $this->assertSame( '2026-03-31 09:00', $events[4]['dtstart'] );
        // Duration should be preserved.
        $this->assertSame( '2026-03-28 09:15', $events[1]['dtend'] );
    }

    public function test_should_expandWeeklyRecurrence_when_rruleHasFreqWeekly(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Weekly Review',
                'DTSTART' => '20260327T140000Z',
                'RRULE'   => 'FREQ=WEEKLY;COUNT=3',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 3, $events );
        $this->assertSame( '2026-03-27 14:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-04-03 14:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-04-10 14:00', $events[2]['dtstart'] );
    }

    public function test_should_expandWeeklyByDay_when_rruleHasByDayRule(): void {
        // Monday DTSTART, recurring MO and WE.
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'MWF Meeting',
                'DTSTART' => '20260323T100000Z',
                'RRULE'   => 'FREQ=WEEKLY;BYDAY=MO,WE;COUNT=4',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 4, $events );
        $this->assertSame( '2026-03-23 10:00', $events[0]['dtstart'] ); // Mon
        $this->assertSame( '2026-03-25 10:00', $events[1]['dtstart'] ); // Wed
        $this->assertSame( '2026-03-30 10:00', $events[2]['dtstart'] ); // Mon
        $this->assertSame( '2026-04-01 10:00', $events[3]['dtstart'] ); // Wed
    }

    public function test_should_expandMonthlyRecurrence_when_rruleHasFreqMonthly(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Monthly Report',
                'DTSTART' => '20260115T090000Z',
                'RRULE'   => 'FREQ=MONTHLY;COUNT=3',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 3, $events );
        $this->assertSame( '2026-01-15 09:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-02-15 09:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-03-15 09:00', $events[2]['dtstart'] );
    }

    public function test_should_expandMonthlyByMonthDay_when_rruleHasByMonthDay(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Payday',
                'DTSTART' => '20260101T000000Z',
                'RRULE'   => 'FREQ=MONTHLY;BYMONTHDAY=1,15;COUNT=4',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 4, $events );
        $this->assertSame( '2026-01-01 00:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-01-15 00:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-02-01 00:00', $events[2]['dtstart'] );
        $this->assertSame( '2026-02-15 00:00', $events[3]['dtstart'] );
    }

    public function test_should_respectUntil_when_rruleHasUntilDate(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Until Event',
                'DTSTART' => '20260327T090000Z',
                'RRULE'   => 'FREQ=DAILY;UNTIL=20260329T235959Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 3, $events );
        $this->assertSame( '2026-03-27 09:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-03-28 09:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-03-29 09:00', $events[2]['dtstart'] );
    }

    public function test_should_respectInterval_when_rruleHasInterval(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Biweekly',
                'DTSTART' => '20260327T090000Z',
                'RRULE'   => 'FREQ=WEEKLY;INTERVAL=2;COUNT=3',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 3, $events );
        $this->assertSame( '2026-03-27 09:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-04-10 09:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-04-24 09:00', $events[2]['dtstart'] );
    }

    public function test_should_excludeExdates_when_rruleEventHasExdate(): void {
        $ical = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\n"
              . "BEGIN:VEVENT\r\n"
              . "SUMMARY:With Exceptions\r\n"
              . "DTSTART:20260327T090000Z\r\n"
              . "RRULE:FREQ=DAILY;COUNT=5\r\n"
              . "EXDATE:20260328T090000Z\r\n"
              . "EXDATE:20260330T090000Z\r\n"
              . "END:VEVENT\r\n"
              . "END:VCALENDAR";

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 3, $events );
        $this->assertSame( '2026-03-27 09:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-03-29 09:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-03-31 09:00', $events[2]['dtstart'] );
    }

    public function test_should_filterRecurringByRange_when_dateRangeProvided(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Daily',
                'DTSTART' => '20260325T090000Z',
                'RRULE'   => 'FREQ=DAILY;COUNT=10',
            ) )
        );

        // Range: Mar 28 – Apr 1 (exclusive).
        $events = CalDavCVParser::parse( $ical, '2026-03-28', '2026-04-01' );

        $this->assertCount( 4, $events );
        $this->assertSame( '2026-03-28 09:00', $events[0]['dtstart'] );
        $this->assertSame( '2026-03-29 09:00', $events[1]['dtstart'] );
        $this->assertSame( '2026-03-30 09:00', $events[2]['dtstart'] );
        $this->assertSame( '2026-03-31 09:00', $events[3]['dtstart'] );
    }

    public function test_should_expandAllDayRecurrence_when_recurringAllDayEvent(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY'         => 'All Day',
                'DTSTART'         => '20260327',
                'DTEND'           => '20260328',
                'RRULE'           => 'FREQ=WEEKLY;COUNT=2',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 2, $events );
        $this->assertTrue( $events[0]['all_day'] );
        $this->assertSame( '2026-03-27', $events[0]['dtstart'] );
        $this->assertSame( '2026-03-28', $events[0]['dtend'] );
        $this->assertSame( '2026-04-03', $events[1]['dtstart'] );
        $this->assertSame( '2026-04-04', $events[1]['dtend'] );
    }

    public function test_should_notIncludeRruleOrExdatesKeys_when_returningEvents(): void {
        $ical = $this->buildIcal(
            $this->buildEvent( array(
                'SUMMARY' => 'Recurring',
                'DTSTART' => '20260327T090000Z',
                'RRULE'   => 'FREQ=DAILY;COUNT=1',
            ) ),
            $this->buildEvent( array(
                'SUMMARY' => 'One-off',
                'DTSTART' => '20260328T090000Z',
            ) )
        );

        $events = CalDavCVParser::parse( $ical );

        $this->assertCount( 2, $events );
        foreach ( $events as $event ) {
            $this->assertArrayNotHasKey( 'rrule', $event );
            $this->assertArrayNotHasKey( 'exdates', $event );
        }
    }
}

