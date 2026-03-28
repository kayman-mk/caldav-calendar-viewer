<?php
/**
 * Registers the [icalcv_calendar] shortcode and renders an event list.
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
     *   id – references a feed configured in Settings → ICal Calendar View (required)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( array(
            'id' => '',
        ), $atts, 'icalcv_calendar' );

        wp_enqueue_style( 'icalcv-calendar-style' );
        wp_enqueue_script( 'icalcv-calendar-script' );

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

        return $this->buildEventListHtml( $events );
    }

    /**
     * Build an event list grouped by date for the next 7 days.
     *
     * @param array $events Parsed events from ICalCVParser.
     * @return string HTML markup.
     */
    private function buildEventListHtml( array $events ): string {
        $eventsByDate = array();
        foreach ( $events as $event ) {
            $dateKey = substr( $event['dtstart'], 0, 10 );
            $eventsByDate[ $dateKey ][] = $event;
        }

        $tz    = wp_timezone();
        $today = new DateTimeImmutable( 'today', $tz );

        ob_start();
        echo '<div class="icalcv-calendar">';

        foreach ( $eventsByDate as $dateStr => $dayEvents ) {
            $dateObj  = new DateTimeImmutable( $dateStr, $tz );
            $isToday  = ( $dateStr === $today->format( 'Y-m-d' ) );
            $dayLabel = $isToday
                ? __( 'Today', 'ical-calendar-view' ) . ' — ' . wp_date( 'l, j F', $dateObj->getTimestamp() )
                : wp_date( 'l, j F', $dateObj->getTimestamp() );

            echo '<div class="icalcv-day-group' . ( $isToday ? ' icalcv-today' : '' ) . '">';
            echo '<h3 class="icalcv-day-heading">' . esc_html( $dayLabel ) . '</h3>';
            echo '<ul class="icalcv-event-list">';

            foreach ( $dayEvents as $ev ) {
                $this->renderEventItem( $ev );
            }

            echo '</ul>';
            echo '</div>';
        }

        echo '</div>';

        return ob_get_clean();
    }

    /**
     * Render a single event as a list item.
     *
     * @param array $ev Parsed event data.
     */
    private function renderEventItem( array $ev ): void {
        $time = '';
        if ( ! $ev['all_day'] && strlen( $ev['dtstart'] ) > 10 ) {
            $time = substr( $ev['dtstart'], 11 );
        }

        echo '<li class="icalcv-event" title="' . esc_attr( wp_strip_all_tags( $ev['description'] ) ) . '">';

        if ( ! empty( $ev['url'] ) ) {
            echo '<a href="' . esc_url( $ev['url'] ) . '" target="_blank" rel="noopener">';
        }

        if ( $time ) {
            echo '<span class="icalcv-event-time">' . esc_html( $time ) . '</span> ';
        }
        echo '<span class="icalcv-event-title">' . esc_html( $ev['summary'] ) . '</span>';

        if ( ! empty( $ev['location'] ) ) {
            echo '<span class="icalcv-event-location"> — ' . esc_html( $ev['location'] ) . '</span>';
        }

        if ( ! empty( $ev['url'] ) ) {
            echo '</a>';
        }

        echo '</li>';
    }
}

