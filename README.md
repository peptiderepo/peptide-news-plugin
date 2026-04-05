# Peptide News Aggregator

A WordPress plugin that aggregates and displays the latest peptide research news from multiple sources, with built-in click analytics and trend reporting.

## Features

- **Multi-Source Fetching** — Pulls articles from RSS/Atom feeds (Google News, PubMed, custom) and NewsAPI.org
- **Configurable Refresh** — Admin-controlled cron intervals from 15 minutes to daily
- **Shortcode & Wigget** — `[peptide_news count="10"]` shortcode with card/compact/list layouts, plus a sidebar widget
- **Click Analytics** — Tracks every outbound click with session tracking, device detection, and GDPR-friendly IP anonymization
- **Analytics Dashboard** — Chart.js-powered admin dashboard with trend lines, device breakdown, source performance, and top articles
- **REST API** — Full JSON API at `/wp-json/peptide-news/v1/` for external analytics tools
- **Topic Analysis** — Aggregates clicks by article category to surface popular research topics
- **CSV Export** — Export raw click data for offline analysis
- **Dark Mode** — Automatically adapts to visitor's system theme preference
- **Performance** — Transient caching, deduplication via SHA-256 hashing, and paginated DB queries

## Requirements

- WordPress 5.8+
- PHP 7.4+
- MySQL 5.7+ or MariaDB 10.3+

## Installation

1. Download or clone this repository into `wp-content/plugins/`:
   ```bash
   cd wp-content/plugins/
   git clone https://github.com/peptiderepo/peptide-news-plugin.git
   ```
2. Activate the plugin in **Plugins → Installed Plugins**
3. Navigate to **Peptide News → Settings** to configure your sources and fetch interval
4. Click **Fetch Articles Now** to pull the first batch
5. Add `[peptide_news]` to any page or post, or add the widget via **Appearance → Widgets**

## Configuration

### Settings (Peptide News → Settings)

| Setting | Description | Default |
|---------|-------------|---------|
| Fetch Interval | How often to check for new articles | Twice daily |
| Articles to Display | Number shown in shortcode/widget | 10 |
| Search Keywords | Comma-separated terms for NewsAPI | peptide, peptides, BPC-157... |
| RSS Feeds | One URL per line | Google News + PubMed |
| NewsAPI Key | Optional API key from newsapi.org | — |
| Data Retention | Days to keep analytics data | 365 |
| Anonymize IPs | Zero out last IP octet (GDPR) | Enabled |

### Shortcode Usage

```
[peptide_news]                        <!-- Default: 10 articles, card layout -->
[peptide_news count="5"]              <!-- 5 articles -->
[peptide_news count="8" layout="list"] <!-- List layout -->
[peptide_news layout="compact"]       <!-- Compact layout (widget-style) -->
```

## REST API Endpoints

All analytics endpoints require admin authentication (cookie or Application Password).

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/wp-json/peptide-news/v1/articles` | List stored articles (public) |
| GET | `/wp-json/peptide-news/v1/analytics/top` | Top articles by clicks |
| GET | `/wp-json/peptide-news/v1/analytics/trends` | Daily click trend data |
| GET | `/wp-json/peptide-news/v1/analytics/topics` | Popular topics/categories |
| GET | `/wp-json/peptide-news/v1/analytics/devices` | Device breakdown |
| GET | `/wp-json/peptide-news/v1/analytics/sources` | Source performance |
| GET | `/wp-json/peptide-news/v1/analytics/export` | Raw click data for CSV |

Query parameters: `start_date`, `end_date` (Y-m-d), `limit` (int).

## Database Schema

The plugin creates three custom tables on activation:

- `wp_peptide_news_articles` — Stores all fetched article data (title, excerpt, content, author, thumbnail, categories, source, etc.)
- `wp_peptide_news_clicks` — Raw click events with session, device, referrer data
- `wp_peptide_news_daily_stats` — Pre-aggregated daily stats for fast dashboard queries

All tables are dropped on plugin **uninstall** (not deactivation — data is preserved if you temporarily disable the plugin).

## Architecture

```
peptide-news-plugin/
├── peptide-news-plugin.php          # Bootstrap & hooks
├── uninstall.php                    # Cleanup on uninstall
├── includes/
│   ├── class-peptide-news.php       # Core orchestrator
│   ├── class-peptide-news-loader.php
│   ├── class-peptide-news-activator.php
│   ├── class-peptide-news-deactivator.php
│   ├── class-peptide-news-fetcher.php    # Multi-source fetcher
│   ├── class-peptide-news-analytics.php  # Click tracking & reports
│   └── class-peptide-news-rest-api.php   # REST endpoints
├── admin/
│   ├── class-peptide-news-admin.php      # Settings & dashboard
│   ├── css/admin-style.css
│   ├── js/admin-script.js               # Chart.js dashboard
│   └── partials/
│       ├── dashboard.php
│       └── articles-list.php
├── public/
│   ├── class-peptide-news-public.php     # Shortcode, widget, AJAX
│   ├── css/public-style.css
│   └── js/public-script.js              # Click tracker (sendBeacon)
└── languages/                            # i18n ready
```

## License

GPL-2.0+ — See [LICENSE](LICENSE) for details.
