# ICal Calendar View

A WordPress plugin that displays events from iCal (.ics) feeds in a clean monthly calendar view. Supports multiple feeds with configurable username and password per feed.

## Features

- **Multiple Calendar Feeds** – Configure as many iCal feeds as you need, each with a unique ID.
- **iCal Feed Integration** – Fetches and parses any standard `.ics` calendar feed (RFC 5545).
- **Basic Authentication** – Supports username/password per feed for protected calendar endpoints.
- **Encrypted Credentials** – Passwords are stored encrypted (AES-256-CBC) in the database.
- **Caching** – Configurable cache lifetime to reduce external requests (defaults to 1 hour).
- **Shortcode** – Display any feed anywhere via `[icalcv_calendar id="my-feed"]`.
- **7-Day Window** – Always fetches and displays only the next 7 days of events.
- **Responsive Design** – Clean event list adapts to mobile screens.
- **Tooltips** – Hover over events to see their description.

## Installation

1. Download or clone this repository into your WordPress `wp-content/plugins/` directory:
   ```bash
   cd wp-content/plugins/
   git clone <repository-url> ical-calendar-view
   ```
2. Activate the plugin via **Plugins → Installed Plugins** in the WordPress admin.
3. Navigate to **Settings → ICal Calendar View** to configure your feeds.

## Configuration

Go to **Settings → ICal Calendar View** in the WordPress admin panel.

### Adding a Feed

Click **+ Add Feed** and fill in:

| Field             | Description                                                                |
|-------------------|----------------------------------------------------------------------------|
| **Feed ID**       | Unique identifier used in the shortcode (lowercase, hyphens, underscores). |
| **iCal Feed URL** | Full URL to the `.ics` calendar feed.                                      |
| **Username**      | Username for Basic Auth (leave blank for public feeds).                    |
| **Password**      | Password for Basic Auth (stored encrypted, leave blank if unused).         |

You can add multiple feeds — each one gets its own ID.

### General Settings

| Setting            | Description                                                        |
|--------------------|--------------------------------------------------------------------|
| **Cache Lifetime** | How long fetched data is cached in seconds (0 disables caching).   |

## Usage

### Basic Shortcode

Reference a configured feed by its ID:

```
[icalcv_calendar id="my-feed"]
```

### Shortcode Attributes

| Attribute | Required | Default | Description                                       |
|-----------|----------|---------|---------------------------------------------------|
| `id`      | **yes**  | —       | The feed ID configured in Settings → ICal Calendar View. |

**Examples:**

```
[icalcv_calendar id="team-calendar"]
[icalcv_calendar id="hr-events"]
```

## File Structure

```
ical-calendar-view/
├── ical-calendar-view.php   # Main plugin bootstrap
├── includes/
│   ├── class-icalcv-settings.php # Admin settings page (multi-feed) & encryption helpers
│   ├── class-icalcv-fetcher.php  # HTTP fetcher with auth & caching (by feed ID)
│   ├── class-icalcv-parser.php   # iCal RFC 5545 parser with date-range filtering
│   └── class-icalcv-shortcode.php# [icalcv_calendar] shortcode renderer
├── tests/
│   ├── bootstrap.php             # WP function stubs for standalone testing
│   ├── ICalCVParserUnitTest.php  # Parser unit tests
│   ├── ICalCVSettingsUnitTest.php# Settings unit tests
│   └── ICalCVFetcherUnitTest.php # Fetcher unit tests
├── assets/
│   ├── css/
│   │   └── calendar.css          # Front-end calendar styles
│   └── js/
│       └── calendar.js           # Tooltip interactions
├── .github/
│   └── workflows/
│       ├── ci.yml                # GitHub Actions CI pipeline
│       ├── release.yml           # Automated release on version tags
│       └── wordpress-deploy.yml  # Deploy to WordPress.org SVN
├── .wordpress-org/               # Plugin directory assets (banners, icons)
├── .distignore                   # Files excluded from WordPress.org deploy
└── README.md
```

## Testing

Run the test suite locally:

```bash
composer install
vendor/bin/phpunit --testdox
```

Tests run without a WordPress installation — the bootstrap file provides lightweight stubs for all required WP functions.

### CI Pipeline

GitHub Actions runs the full test suite on every push and pull request against `main`/`master`. Tests are executed across PHP 7.4, 8.0, 8.1, 8.2, and 8.3.

### Releases

Push a version tag to automatically build and publish a release `.zip` on GitHub:

```bash
git tag v1.0.0
git push origin v1.0.0
```

The release workflow runs tests first, then packages only the runtime files (no tests, dev config, or vendor directory) into `ical-calendar-view-1.0.0.zip` and attaches it to a GitHub Release.

Users can download the `.zip` from the [Releases page](../../releases) and install via **Plugins → Add New → Upload Plugin**.

### WordPress.org Deploy

When a version tag is pushed, the deploy workflow also publishes the plugin to the WordPress.org SVN repository.

**Setup (one-time):**

1. Register at [wordpress.org](https://login.wordpress.org/register) and [submit the plugin for review](https://wordpress.org/plugins/developers/add/).
2. Once approved, add these **repository secrets** in GitHub under **Settings → Secrets and variables → Actions**:
   - `WORDPRESS_ORG_USERNAME` – your WordPress.org SVN username
   - `WORDPRESS_ORG_PASSWORD` – your WordPress.org SVN password
3. Place plugin directory images (banner, icon) in the `.wordpress-org/` folder.

The workflow automatically syncs `readme.txt` (with the correct Stable tag), all runtime files, and `.wordpress-org/` assets to SVN.

## Requirements

- WordPress 5.6 or later
- PHP 7.4 or later
- OpenSSL PHP extension (recommended for password encryption; falls back to Base64)

## License

GPL-2.0-or-later
