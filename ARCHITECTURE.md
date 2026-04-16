# Peptide News Aggregator - Architecture

> **Cross-app context:** decisions that affect multiple plugins (Cloudflare AI Gateway routing, OpenRouter account sharing, the interface pattern, image-generation stack, social distributor choice) are recorded in `Peptide Repo CTO/docs/engineering/decisions/`. The incident runbook for cross-app failure modes is at `Peptide Repo CTO/docs/engineering/INCIDENT-RUNBOOK.md`. Read both before making decisions that cross plugin boundaries.

**Version:** 2.4.0
**Last updated:** April 2026

## What it does

Peptide News Aggregator is a WordPress plugin that fetches peptide research news from RSS feeds and NewsAPI, optionally enriches articles with LLM-generated keywords and summaries via OpenRouter, filters out promotional/press-release content, and presents the feed through a React-powered shortcode with click analytics and trend reporting.

## File tree

```
peptide-news-plugin/
├── peptide-news-plugin.php          # Plugin bootstrap: constants, activation hooks, DB upgrade check
├── uninstall.php                    # Clean uninstall: drops all 4 custom tables, deletes all options
├── composer.json                    # Dev dependencies (PHPUnit, WP stubs)
├── phpunit.xml.dist                 # PHPUnit config with coverage for includes/, admin/, public/
├── package.json                     # JS lint config (ESLint)
├── .phpcs.xml.dist                  # PHPCS config (WordPress-Core ruleset)
├── ARCHITECTURE.md                  # This file
├── CONVENTIONS.md                   # Coding patterns and extension guides
├── README.md                        # Plugin overview and setup instructions
│
├── .github/workflows/
│   ├── ci.yml                       # CI: PHP lint x3, PHPCS, PHPUnit x3, security audit, JS lint
│   ├── deploy.yml                   # Deploy: gates on CI, rsync+SSH to Hostinger, cache purge
│   └── rollback.yml                 # Manual rollback workflow
│
├── includes/
│   ├── class-peptide-news.php       # Main orchestrator: loads deps, registers all hooks
│   ├── class-peptide-news-loader.php      # Hook registration utility (actions + filters)
│   ├── class-peptide-news-activator.php   # Activation: creates tables, sets defaults, schedules cron
│   ├── class-peptide-news-deactivator.php # Deactivation: clears cron (preserves data)
│   ├── class-peptide-news-encryption.php  # AES-256-CBC encryption for API keys at rest
│   ├── class-peptide-news-fetcher.php     # RSS + NewsAPI fetching, dedup, storage, cron lock
│   ├── class-peptide-news-llm.php         # OpenRouter integration (keywords + summaries)
│   ├── class-peptide-news-cost-tracker.php # LLM API cost tracking, budget enforcement, reporting
│   ├── class-peptide-news-content-filter.php # Two-tier ad/promo filter (rules + LLM)
│   ├── class-peptide-news-analytics.php   # Click recording, daily aggregation, CSV export
│   ├── class-peptide-news-rest-api.php    # REST API: /articles, /analytics/*, article deletion
│   └── class-peptide-news-logger.php      # Structured DB logging with auto-prune
│
├── admin/
│   ├── class-peptide-news-admin.php       # Main orchestrator (delegator): preserves public interface
│   ├── class-pn-admin-assets.php          # CSS/JS enqueuing for admin pages
│   ├── class-pn-admin-menu.php            # Admin menu & submenu registration
│   ├── class-pn-admin-settings.php        # Settings registration & field renderers (7 sections, 25+ fields)
│   ├── class-pn-admin-settings-page.php   # Settings page render + plugin log viewer + AJAX handlers
│   ├── class-pn-admin-dashboard-pages.php # Dashboard, articles, cost dashboard pages
│   ├── css/admin-style.css                # Admin dashboard styles
│   ├── js/admin-script.js                 # Analytics charts (Chart.js), AJAX handlers
│   ├── partials/
│   │   ├── dashboard.php                  # Analytics dashboard view
│   │   ├── articles-list.php              # Articles management view
│   │   └── cost-dashboard.php             # LLM cost tracking dashboard view
│   └── vendor/chartjs/                    # Bundled Chart.js 4.4.0
│
├── public/
│   ├── class-peptide-news-public.php      # Shortcode, widget, click tracking AJAX handler
│   ├── css/public-style.css               # Frontend feed styles (.pn-* namespace)
│   ├── js/peptide-news-feed.js            # React feed component
│   └── images/default-thumb.png           # Fallback thumbnail
│
├── tests/
│   ├── bootstrap.php                      # Test bootstrap (WP function stubs)
│   └── unit/
│       ├── FetcherTest.php
│       ├── AdminTest.php
│       ├── RestApiTest.php
│       ├── ContentFilterTest.php
│       ├── CostTrackerTest.php
│       ├── LlmUsageTest.php
│       └── PluginBootstrapTest.php
│
└── languages/
    └── peptide-news.pot                   # i18n template
```

## Data flow

```
User configures settings (admin/class-peptide-news-admin.php)
    │
    ▼
WP-Cron fires `peptide_news_cron_fetch`
    │
    ▼
Fetcher::fetch_all_sources()  ─── acquires transient lock (5 min) ───┐
    │                                                                 │
    ├── fetch_rss_feeds()     → SimplePie via fetch_feed()           │
    ├── fetch_newsapi()       → NewsAPI REST (decrypted key)          │
    │                                                                 │
    ▼                                                                 │
Content_Filter::filter_articles()                                     │
    ├── Rule-based: domain blocklist, title keywords, body keywords   │
    └── LLM tier: borderline articles → OpenRouter classification     │
    │                                                                 │
    ▼                                                                 │
Fetcher::store_article()  → SHA-256 dedup → INSERT into articles table│
    │                                                                 │
    ▼                                                                 │
LLM::process_unanalyzed()  (if enabled)                               │
    ├── Budget check: Cost_Tracker::is_budget_exceeded()               │
    ├── Keywords: OpenRouter (Gemini Flash default)                    │
    ├── Summary:  OpenRouter (Gemini Flash default)                    │
    └── Cost logging: Cost_Tracker::log_api_call() after each call     │
    │                                                                 │
    ▼                                                                 │
Results saved to articles table (tags, ai_summary columns)            │
Cost data saved to llm_costs table (tokens, cost, model)              │
    │                                                            lock released
    ▼
REST API serves /articles → React feed component renders on frontend
    │
    ▼
User clicks article → AJAX click tracking → Analytics::record_click()
    │
    ▼
Daily aggregation → Admin dashboard (Chart.js) + CSV export
```

## Custom database tables

| Table | Schema | Written by | Purpose |
|-------|--------|-----------|---------|
| `wp_peptide_news_articles` | id, source, source_url, title, excerpt, content, author, thumbnail_url, thumbnail_local, published_at, fetched_at, categories, tags, language, sentiment_score, ai_summary, hash (UNIQUE), is_active | Fetcher, LLM | Stores fetched articles with SHA-256 dedup hash |
| `wp_peptide_news_clicks` | id, article_id (FK), clicked_at, user_ip, user_agent, referrer_url, page_url, session_id, user_id, country, device_type | Analytics | Granular click events per article |
| `wp_peptide_news_daily_stats` | id, article_id (FK), stat_date, click_count, unique_visitors | Analytics | Aggregated daily metrics for fast trend queries |
| `wp_peptide_news_llm_costs` | id, request_id, model, provider, operation, prompt_tokens, completion_tokens, total_tokens, cost_usd, article_id, created_at | Cost_Tracker | Per-API-call cost log with token counts and USD cost |
| `wp_peptide_news_log` | id, level, context, message, created_at | Logger | Structured application log (auto-pruned at 2000 rows) |

Schema version tracked via `peptide_news_db_version` option. Migrations run via `dbDelta()` on activation and on `plugins_loaded` when version mismatch detected.

## External API integrations

| Service | Purpose | Integration code | Auth |
|---------|---------|-----------------|------|
| NewsAPI.org | Fetch news articles by keyword | `Fetcher::fetch_newsapi()` | API key (encrypted in wp_options) |
| OpenRouter | LLM inference (keywords, summaries, content classification) | `LLM::call_openrouter()` | API key (encrypted in wp_options) |

Both keys are encrypted at rest using AES-256-CBC via `Peptide_News_Encryption` (since v2.3.0). Legacy plaintext keys are decrypted transparently during the migration period.

## Key architectural decisions

**Transient-based fetch lock instead of database mutex.** The fetcher uses a 5-minute transient lock to prevent overlapping cron runs. This is simpler than a DB-level `INSERT IGNORE` mutex and sufficient for the plugin's write volume. The lock is released on completion or expires after 5 minutes as a safety net.

**Two-tier content filter.** Rule-based keyword/domain matching handles obvious spam cheaply. Only borderline cases (1 body keyword match, below threshold) are sent to the LLM for classification. This keeps API costs near zero for most filter operations.

**React frontend on WP-bundled React.** The feed component uses WordPress's bundled React to avoid shipping a duplicate. This ties us to WP's React version but avoids bloating the plugin.

**CSV export capped at 50,000 rows.** The `export_clicks_csv()` method applies a SQL LIMIT to prevent memory exhaustion on high-traffic sites. The cap is defined as a class constant (`CSV_EXPORT_MAX_ROWS`) for easy adjustment.

**API keys encrypted at rest.** All API keys stored in wp_options are encrypted using AES-256-CBC with `wp_salt('auth')` as key material. The encryption class handles legacy plaintext values transparently, so existing installations upgrade without manual re-entry.

**Cascading foreign keys.** The clicks and daily_stats tables have ON DELETE CASCADE foreign keys referencing the articles table, so deleting an article automatically cleans up its analytics data.

**LLM cost tracking in a dedicated table.** API call costs are logged to `wp_peptide_news_llm_costs` rather than post_meta because cost data is high-volume write data (2 rows per article processed) that needs date-range aggregation queries. The table is indexed on `created_at` and `model` for fast dashboard reporting.

**Budget enforcement with hard-stop mode.** The cost tracker checks `is_budget_exceeded()` before every LLM API call. In `hard_stop` mode, calls are blocked when monthly spend meets the configured limit — this prevents silent overspending. Budget alerts fire at 50%, 80%, and 100% thresholds (once per month per threshold). The monthly spend is cached in a 5-minute transient to avoid excessive DB queries during batch processing.

**OpenRouter usage data extraction.** Token counts and costs are extracted from the OpenRouter API response body (`data.usage` field) rather than response headers, because the response body is more reliable and includes model-specific token counts. If the API reports an explicit cost, that takes precedence over our calculated estimate.
