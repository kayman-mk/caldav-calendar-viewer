<?php
/**
 * Registers the [cdcv_calendar] shortcode and renders an event list.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CalDavCVShortcode {

    public function __construct() {
        add_shortcode( 'cdcv_calendar', array( $this, 'render' ) );
    }

    /**
     * Shortcode handler for [cdcv_calendar].
     *
     * Attributes:
     *   id – references a feed configured in Settings → CalDav Calendar Viewer (required)
     *
     * @param array|string $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render( $atts ): string {
        $atts = shortcode_atts( array(
            'id' => '',
        ), $atts, 'cdcv_calendar' );

        wp_enqueue_style( 'cdcv-calendar-style' );
        wp_enqueue_script( 'cdcv-calendar-script' );

        $tz         = wp_timezone();
        $today      = new DateTimeImmutable( 'today', $tz );
        $rangeStart = $today->format( 'Y-m-d' );
        $rangeEnd   = $today->modify( '+7 days' )->format( 'Y-m-d' );

        $feedId   = sanitize_key( $atts['id'] );
        $icalBody = CalDavCVFetcher::fetch( $feedId );

        if ( is_wp_error( $icalBody ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'cdcv_calendar: ' . $icalBody->get_error_code() . ' – ' . $icalBody->get_error_message() );
            }
            return '<div class="cdcv-error">'
                . esc_html__( 'Unable to load calendar. Please try again later.', 'caldav-calendar-viewer' )
                . '</div>';
        }

        $events = CalDavCVParser::parse( $icalBody, $rangeStart, $rangeEnd );

        if ( empty( $events ) ) {
            return '<div class="cdcv-no-events">'
                . esc_html__( 'No upcoming events found.', 'caldav-calendar-viewer' )
                . '</div>';
        }

        return $this->buildEventListHtml( $events );
    }

    /**
     * Build an event list grouped by date for the next 7 days.
     *
     * @param array $events Parsed events from CalDavCVParser.
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
        echo '<div class="cdcv-calendar">';

        foreach ( $eventsByDate as $dateStr => $dayEvents ) {
            $dateObj  = new DateTimeImmutable( $dateStr, $tz );
            $isToday  = ( $dateStr === $today->format( 'Y-m-d' ) );
            $dayLabel = $isToday
                ? __( 'Today', 'caldav-calendar-viewer' ) . ' — ' . wp_date( 'l, j F', $dateObj->getTimestamp() )
                : wp_date( 'l, j F', $dateObj->getTimestamp() );

            echo '<div class="' . esc_attr( 'cdcv-day-group' . ( $isToday ? ' cdcv-today' : '' ) ) . '">';
            echo '<h3 class="cdcv-day-heading">' . esc_html( $dayLabel ) . '</h3>';
            echo '<ul class="cdcv-event-list">';

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

        $tooltip = $this->buildTooltip( $ev );

        echo '<li class="cdcv-event" title="' . esc_attr( $tooltip ) . '">';

        if ( ! empty( $ev['url'] ) ) {
            echo '<a href="' . esc_url( $ev['url'] ) . '" target="_blank" rel="noopener">';
        }

        if ( $time ) {
            echo '<span class="cdcv-event-time">' . esc_html( $time ) . '</span> ';
        }
        echo '<span class="cdcv-event-title">' . esc_html( $ev['summary'] ) . '</span>';

        if ( ! empty( $ev['location'] ) ) {
            echo '<span class="cdcv-event-location"> — ' . esc_html( $ev['location'] ) . '</span>';
        }

        if ( ! empty( $ev['url'] ) ) {
            echo '</a>';
        }

        echo '</li>';
    }

    /**
     * Build tooltip text showing start/end time and description.
     *
     * @param array $ev Parsed event data.
     * @return string Plain-text tooltip.
     */
    private function buildTooltip( array $ev ): string {
        $parts = array();

        if ( $ev['all_day'] ) {
            $parts[] = __( 'All day', 'caldav-calendar-viewer' );
        } elseif ( strlen( $ev['dtstart'] ) > 10 ) {
            $start = substr( $ev['dtstart'], 11 );
            $end   = ( ! empty( $ev['dtend'] ) && strlen( $ev['dtend'] ) > 10 )
                ? substr( $ev['dtend'], 11 )
                : '';
            $parts[] = $end ? $start . ' – ' . $end : $start;
        }

        $desc = trim( wp_strip_all_tags( $ev['description'] ) );
        if ( '' !== $desc ) {
            $parts[] = $desc;
        }

        return implode( "\n", $parts );
    }
}

