<?php
/**
 * Registers the [icalcv_calendar] shortcode and renders the calendar HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class ICalCVShortcode {

    public function __construct() {
        add_shortcode( 'icalcv_calendar', array( $this, 'render' ) );
    }

    /**
     * Shortcode handler for [icalcv_calendar].
     *
     * Attributes:
     *   id     – references a feed configured in Settings → ICal Calendar View (required)
     *   months – number of months to display (default 2)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( array(
            'id'     => '',
            'months' => 2,
        ), $atts, 'icalcv_calendar' );

        wp_enqueue_style( 'icalcv-calendar-style' );
        wp_enqueue_script( 'icalcv-calendar-script' );

        $monthsToShow = min( 12, max( 1, (int) $atts['months'] ) );

        // Always fetch the next 7 days starting from today.
        $tz         = wp_timezone();
        $today      = new DateTimeImmutable( 'today', $tz );
        $rangeStart = $today->format( 'Y-m-d' );
        $rangeEnd   = $today->modify( '+7 days' )->format( 'Y-m-d' );

        $feedId   = sanitize_key( $atts['id'] );
        $icalBody = ICalCVFetcher::fetch( $feedId );

        if ( is_wp_error( $icalBody ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'icalcv_calendar: ' . $icalBody->get_error_code() . ' – ' . $icalBody->get_error_message() );
            }
            return '<div class="icalcv-error">'
                . esc_html__( 'Unable to load calendar. Please try again later.', 'ical-calendar-view' )
                . '</div>';
        }

        $events = ICalCVParser::parse( $icalBody, $rangeStart, $rangeEnd );

        if ( empty( $events ) ) {
            return '<div class="icalcv-no-events">'
                . esc_html__( 'No upcoming events found.', 'ical-calendar-view' )
                . '</div>';
        }

        return $this->buildCalendarHtml( $events, $monthsToShow );
    }

    /**
     * Build a month-grid calendar HTML for the given events.
     *
     * @param array $events       Parsed events from ICalCVParser.
     * @param int   $monthsToShow Number of months to render.
     * @return string HTML markup.
     */
    private function buildCalendarHtml( array $events, int $monthsToShow ): string {
        $eventsByDate = array();
        foreach ( $events as $event ) {
            $dateKey = substr( $event['dtstart'], 0, 10 );
            $eventsByDate[ $dateKey ][] = $event;
        }

        $today = new DateTimeImmutable( 'today', wp_timezone() );

        ob_start();
        echo '<div class="icalcv-calendar">';

        for ( $m = 0; $m < $monthsToShow; $m++ ) {
            $monthStart = $today->modify( "first day of +{$m} month" );
            $this->renderMonth( $monthStart, $eventsByDate );
        }

        echo '</div>';

        return ob_get_clean();
    }

    /**
     * Render a single month grid.
     *
     * @param DateTimeImmutable $monthStart   First day of the month.
     * @param array             $eventsByDate Events indexed by Y-m-d.
     */
    private function renderMonth( DateTimeImmutable $monthStart, array $eventsByDate ): void {
        $year        = (int) $monthStart->format( 'Y' );
        $month       = (int) $monthStart->format( 'n' );
        $daysInMonth = (int) $monthStart->format( 't' );
        $firstWeekday = (int) $monthStart->format( 'N' ); // 1=Mon … 7=Sun
        $monthLabel  = wp_date( 'F Y', $monthStart->getTimestamp() );
        $todayStr    = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

        $this->renderMonthHeader( $monthLabel );
        $this->renderMonthBody( $year, $month, $daysInMonth, $firstWeekday, $todayStr, $eventsByDate );
    }

    /**
     * Render the month title and weekday header row.
     *
     * @param string $monthLabel Formatted month/year label.
     */
    private function renderMonthHeader( string $monthLabel ): void {
        $weekdays = array(
            __( 'Mon', 'ical-calendar-view' ),
            __( 'Tue', 'ical-calendar-view' ),
            __( 'Wed', 'ical-calendar-view' ),
            __( 'Thu', 'ical-calendar-view' ),
            __( 'Fri', 'ical-calendar-view' ),
            __( 'Sat', 'ical-calendar-view' ),
            __( 'Sun', 'ical-calendar-view' ),
        );
        ?>
        <div class="icalcv-month">
            <h3 class="icalcv-month-title"><?php echo esc_html( $monthLabel ); ?></h3>
            <table class="icalcv-table">
                <thead>
                    <tr>
                        <?php foreach ( $weekdays as $wd ) : ?>
                            <th><?php echo esc_html( $wd ); ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
        <?php
    }

    /**
     * Render the month table body rows and closing tags.
     *
     * @param int    $year         Four-digit year.
     * @param int    $month        Month number (1-12).
     * @param int    $daysInMonth  Total days in the month.
     * @param int    $firstWeekday ISO weekday of the first day (1=Mon).
     * @param string $todayStr     Today's date as Y-m-d.
     * @param array  $eventsByDate Events indexed by Y-m-d.
     */
    private function renderMonthBody( int $year, int $month, int $daysInMonth, int $firstWeekday, string $todayStr, array $eventsByDate ): void {
        echo '<tbody>';

        $dayCounter = 1;
        $cell       = 1;

        for ( $row = 0; $row < 6 && $dayCounter <= $daysInMonth; $row++ ) {
            echo '<tr>';
            for ( $col = 1; $col <= 7; $col++, $cell++ ) {
                if ( $cell < $firstWeekday || $dayCounter > $daysInMonth ) {
                    echo '<td class="icalcv-empty"></td>';
                } else {
                    $dateStr = sprintf( '%04d-%02d-%02d', $year, $month, $dayCounter );
                    $this->renderDayCell( $dateStr, $dayCounter, $todayStr, $eventsByDate );
                    $dayCounter++;
                }
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    /**
     * Render a single day table cell.
     *
     * @param string $dateStr      Date as Y-m-d.
     * @param int    $dayNumber    Day of the month.
     * @param string $todayStr     Today's date as Y-m-d.
     * @param array  $eventsByDate Events indexed by Y-m-d.
     */
    private function renderDayCell( string $dateStr, int $dayNumber, string $todayStr, array $eventsByDate ): void {
        $hasEvents = isset( $eventsByDate[ $dateStr ] );
        $isToday   = ( $dateStr === $todayStr );

        $classes = 'icalcv-day';
        if ( $hasEvents ) {
            $classes .= ' icalcv-has-events';
        }
        if ( $isToday ) {
            $classes .= ' icalcv-today';
        }

        echo '<td class="' . esc_attr( $classes ) . '">';
        echo '<span class="icalcv-day-number">' . esc_html( $dayNumber ) . '</span>';

        if ( $hasEvents ) {
            echo '<div class="icalcv-events">';
            foreach ( $eventsByDate[ $dateStr ] as $ev ) {
                $this->renderEventItem( $ev );
            }
            echo '</div>';
        }

        echo '</td>';
    }

    /**
     * Render a single event badge inside a day cell.
     *
     * @param array $ev Parsed event data.
     */
    private function renderEventItem( array $ev ): void {
        $time = '';
        if ( ! $ev['all_day'] && strlen( $ev['dtstart'] ) > 10 ) {
            $time = substr( $ev['dtstart'], 11 ) . ' ';
        }

        echo '<div class="icalcv-event" title="' . esc_attr( wp_strip_all_tags( $ev['description'] ) ) . '">';

        if ( ! empty( $ev['url'] ) ) {
            echo '<a href="' . esc_url( $ev['url'] ) . '" target="_blank" rel="noopener">';
        }

        echo '<span class="icalcv-event-time">' . esc_html( $time ) . '</span>';
        echo '<span class="icalcv-event-title">' . esc_html( $ev['summary'] ) . '</span>';

        if ( ! empty( $ev['location'] ) ) {
            echo '<span class="icalcv-event-location"> — ' . esc_html( $ev['location'] ) . '</span>';
        }

        if ( ! empty( $ev['url'] ) ) {
            echo '</a>';
        }

        echo '</div>';
    }
}

