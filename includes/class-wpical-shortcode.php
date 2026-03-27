<?php
/**
 * Registers the [wpical_calendar] shortcode and renders the calendar HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WPIcalShortcode {

    public function __construct() {
        add_shortcode( 'wpical_calendar', array( $this, 'render' ) );
    }

    /**
     * Shortcode handler for [wpical_calendar].
     *
     * Attributes:
     *   id     – references a feed configured in Settings → iCal Calendar (required)
     *   months – number of months to display (default 2)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( array(
            'id'     => '',
            'months' => 2,
        ), $atts, 'wpical_calendar' );

        wp_enqueue_style( 'wpical-calendar-style' );
        wp_enqueue_script( 'wpical-calendar-script' );

        $monthsToShow = max( 1, (int) $atts['months'] );

        // Always fetch the next 7 days starting from today.
        $tz         = wp_timezone();
        $today      = new DateTimeImmutable( 'today', $tz );
        $rangeStart = $today->format( 'Y-m-d' );
        $rangeEnd   = $today->modify( '+7 days' )->format( 'Y-m-d' );

        $feedId   = sanitize_key( $atts['id'] );
        $icalBody = WPIcalFetcher::fetch( $feedId );

        if ( is_wp_error( $icalBody ) ) {
            return '<div class="wpical-error">'
                . esc_html( $icalBody->get_error_message() )
                . '</div>';
        }

        $events = WPIcalParser::parse( $icalBody, $rangeStart, $rangeEnd );

        if ( empty( $events ) ) {
            return '<div class="wpical-no-events">'
                . esc_html__( 'No upcoming events found.', 'wp-ical-calendar' )
                . '</div>';
        }

        return $this->buildCalendarHtml( $events, $monthsToShow );
    }

    /**
     * Build a month-grid calendar HTML for the given events.
     *
     * @param array $events       Parsed events from WPIcalParser.
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
        echo '<div class="wpical-calendar">';

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
            __( 'Mon', 'wp-ical-calendar' ),
            __( 'Tue', 'wp-ical-calendar' ),
            __( 'Wed', 'wp-ical-calendar' ),
            __( 'Thu', 'wp-ical-calendar' ),
            __( 'Fri', 'wp-ical-calendar' ),
            __( 'Sat', 'wp-ical-calendar' ),
            __( 'Sun', 'wp-ical-calendar' ),
        );
        ?>
        <div class="wpical-month">
            <h3 class="wpical-month-title"><?php echo esc_html( $monthLabel ); ?></h3>
            <table class="wpical-table">
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
                    echo '<td class="wpical-empty"></td>';
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

        $classes = 'wpical-day';
        if ( $hasEvents ) {
            $classes .= ' wpical-has-events';
        }
        if ( $isToday ) {
            $classes .= ' wpical-today';
        }

        echo '<td class="' . esc_attr( $classes ) . '">';
        echo '<span class="wpical-day-number">' . esc_html( $dayNumber ) . '</span>';

        if ( $hasEvents ) {
            echo '<div class="wpical-events">';
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

        echo '<div class="wpical-event" title="' . esc_attr( wp_strip_all_tags( $ev['description'] ) ) . '">';

        if ( ! empty( $ev['url'] ) ) {
            echo '<a href="' . esc_url( $ev['url'] ) . '" target="_blank" rel="noopener">';
        }

        echo '<span class="wpical-event-time">' . esc_html( $time ) . '</span>';
        echo '<span class="wpical-event-title">' . esc_html( $ev['summary'] ) . '</span>';

        if ( ! empty( $ev['location'] ) ) {
            echo '<span class="wpical-event-location"> — ' . esc_html( $ev['location'] ) . '</span>';
        }

        if ( ! empty( $ev['url'] ) ) {
            echo '</a>';
        }

        echo '</div>';
    }
}

