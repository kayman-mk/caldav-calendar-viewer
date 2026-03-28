=== ICal Calendar View ===
Contributors: kaymanmk
Tags: ical, calendar, ics, events, shortcode
Requires at least: 5.6
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Displays events from iCal (.ics) feeds in a responsive monthly calendar view. Supports multiple feeds with Basic Auth and encrypted credentials.

== Description ==

ICal Calendar View fetches and displays events from any standard iCal (.ics) feed directly on your site using a simple shortcode. It supports multiple calendar feeds, each with its own URL and optional username/password for authenticated endpoints.

**Key Features:**

* **Multiple Calendar Feeds** – Configure as many feeds as you need, each with a unique ID.
* **iCal / RFC 5545 Support** – Parses standard `.ics` calendar feeds including all-day events, times, locations, and descriptions.
* **Basic Authentication** – Supply a username and password per feed for protected calendars.
* **Encrypted Credentials** – Passwords are stored using AES-256-CBC encryption in the database.
* **Caching** – Configurable cache lifetime reduces external HTTP requests (defaults to 1 hour).
* **7-Day Rolling Window** – Automatically fetches and displays only the next 7 days of events.
* **Responsive Event List** – Clean event list layout that adapts to all screen sizes.
* **Event Tooltips** – Hover over an event to see its description.

== Installation ==

1. Upload the `ical-calendar-view` folder to the `/wp-content/plugins/` directory, or install it directly through the WordPress plugin screen.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Settings → ICal Calendar View** to add your calendar feeds.
4. Add the `[icalcv_calendar id="your-feed-id"]` shortcode to any page or post.

== Configuration ==

Navigate to **Settings → ICal Calendar View** in the WordPress admin panel.

= Adding a Feed =

Click **+ Add Feed** and fill in:

* **Feed ID** – A unique lowercase identifier (letters, numbers, hyphens, underscores) used in the shortcode.
* **iCal Feed URL** – The full URL to the `.ics` calendar feed.
* **Username** – Username for Basic Authentication. Leave blank for public feeds.
* **Password** – Password for Basic Authentication. Stored encrypted. Leave blank to keep the current value.

You can configure multiple feeds — each gets its own ID.

= General Settings =

* **Cache Lifetime (seconds)** – How long fetched calendar data is cached. Set to `0` to disable caching. Default: `3600` (1 hour).

== Usage ==

= Basic Shortcode =

`[icalcv_calendar id="my-feed"]`

= Shortcode Attributes =

* `id` (required) – The feed ID configured in **Settings → ICal Calendar View**.

= Examples =

`[icalcv_calendar id="team-calendar"]`

`[icalcv_calendar id="hr-events"]`

== Frequently Asked Questions ==

= What iCal formats are supported? =

The plugin supports any standard iCal feed following RFC 5545 (`.ics` files). This includes feeds from Google Calendar, Microsoft Outlook/Exchange, Nextcloud, Apple Calendar, and most other calendar applications.

= Does it work with password-protected calendars? =

Yes. Each feed can be configured with a username and password for HTTP Basic Authentication. Credentials are stored encrypted using AES-256-CBC.

= Can I display multiple calendars on the same page? =

Yes. Configure multiple feeds in the settings, then use separate shortcodes:

`[icalcv_calendar id="team"]`
`[icalcv_calendar id="holidays"]`

= How often is the calendar data refreshed? =

By default, fetched data is cached for 1 hour (3600 seconds). You can change this in **Settings → ICal Calendar View** under Cache Lifetime. Set to `0` to fetch fresh data on every page load.

= What if OpenSSL is not available? =

The plugin falls back to Base64 encoding for password storage. For production use, the OpenSSL PHP extension is strongly recommended.

= Can I style the calendar? =

Yes. The calendar uses CSS classes prefixed with `icalcv-` that you can override in your theme's stylesheet or via the WordPress Customizer's Additional CSS section.

== Screenshots ==

1. Admin settings page with multiple feed configuration.
2. 7-day event list view on the front end.
3. Event tooltip showing the event description on hover.

== Changelog ==

= 1.0.0 =
* Initial release.
* Multiple iCal feed support with per-feed URL, username, and password.
* AES-256-CBC encrypted password storage.
* `[icalcv_calendar]` shortcode with `id` attribute.
* 7-day event list view.
* 7-day rolling event fetch window.
* Configurable transient cache.
* Event tooltips on hover.
* PHPUnit test suite.
* GitHub Actions CI and automated release workflow.

== Upgrade Notice ==

= 1.0.0 =
Initial release.

