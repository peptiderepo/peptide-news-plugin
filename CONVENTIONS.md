# Peptide News Aggregator - Conventions

Project-specific patterns and step-by-step extension guides.

## Naming conventions

**Classes:** `Peptide_News_{Name}` (WordPress standard, one class per file).
**Files:** `class-peptide-news-{name}.php` in `includes/`, `admin/`, or `public/`.
**Options:** All prefixed `peptide_news_` (e.g., `peptide_news_fetch_interval`).
**Transients:** Prefixed `peptide_news_` (e.g., `peptide_news_fetch_lock`).
**Database tables:** `{$wpdb->prefix}peptide_news_{name}` (e.g., `wp_peptide_news_articles`).
**Hooks:** Custom actions/filters prefixed `peptide_news_` (e.g., `peptide_news_cron_fetch`).
**CSS classes:** `.pn-*` namespace to avoid collisions with theme and other plugins.
**Constants:** `PEPTIDE_NEWS_*` (e.g., `PEPTIDE_NEWS_VERSION`, `PEPTIDE_NEWS_PLUGIN_DIR`).

## Hook registration pattern

All hooks are registered centrally in `class-peptide-news.php` via the Loader class. Never call `add_action()` or `add_filter()` directly in a module class. Instead, register in the appropriate `define_*_hooks()` method:

```php
// In class-peptide-news.php
private function define_cron_hooks() {
    $fetcher = new Peptide_News_Fetcher();
    $this->loader->add_action( 'peptide_news_cron_fetch', $fetcher, 'fetch_all_sources' );
}
```

## Error handling patterns

**External API calls:** Always check `is_wp_error()` on `wp_remote_post()`/`wp_remote_get()` responses. Return `WP_Error` from methods that make API calls. Log errors via `Peptide_News_Logger`.

**AJAX handlers:** Always verify nonce with `check_ajax_referer( 'peptide_news_admin', 'nonce' )` and check `current_user_can( 'manage_options' )` before any action. Return via `wp_send_json_success()` / `wp_send_json_error()`.

**Fail-open for optional features:** If encryption is unavailable (no OpenSSL), store keys as plaintext. If LLM classification fails, let the article through (`'editorial'`). Never block core functionality due to optional feature failures.

**Logging:** Use `Peptide_News_Logger` with appropriate levels and context tags. Available contexts: `fetch`, `llm`, `admin`, `cron`, `general`. The logger auto-prunes at 2000 rows.

## How to add a new news source

1. Add a new private method to `class-peptide-news-fetcher.php`:
   ```php
   private function fetch_new_source() {
       // Return array of article arrays matching this structure:
       // [ 'source' => 'SourceName', 'source_url' => '...', 'title' => '...', 
       //   'excerpt' => '...', 'content' => '...', 'author' => '...', 
       //   'thumbnail_url' => '', 'published_at' => 'Y-m-d H:i:s', 'categories' => '' ]
   }
   ```

2. Call the method from `fetch_all_sources()`, gated by an option:
   ```php
   if ( get_option( 'peptide_news_new_source_enabled', 0 ) ) {
       $articles = array_merge( $articles, $this->fetch_new_source() );
   }
   ```

3. Add the setting to `class-peptide-news-admin.php` in `get_sanitize_callback()`.
4. Add a field renderer method and register it in `register_settings()`.
5. Update `ARCHITECTURE.md` with the new source in the data flow diagram.

## How to add a new admin setting

1. In `class-peptide-news-admin.php`:
   - Add the sanitize callback in `get_sanitize_callback()`.
   - Add a `render_{setting_name}_field()` method.
   - Register the field in `register_settings()` using `$this->register_field()`.

2. In `class-peptide-news-activator.php`:
   - Add a default value in the `$defaults` array in `set_default_options()`.

3. The option is automatically registered with the Settings API and will appear in the admin UI.

## How to add a new LLM provider

The plugin currently talks to OpenRouter, which proxies to multiple LLM providers. To add a direct provider:

1. Create `includes/class-peptide-news-{provider}.php` implementing the same interface as `LLM::call_openrouter()`: takes `(string $api_key, string $model, string $prompt)` and returns `string|WP_Error`.

2. Add a provider selection setting to the admin page.

3. Update `LLM::process_article()` to dispatch to the selected provider.

4. Encrypt the new provider's API key using `Peptide_News_Encryption::encrypt()` on save.

## How to add a new REST API endpoint

1. In `class-peptide-news-rest-api.php`:
   - Add the route in `register_routes()`.
   - Add the callback method.
   - Use `check_admin_permissions` for admin-only endpoints.
   - Use `'permission_callback' => '__return_true'` for public endpoints.

2. For analytics endpoints, use the shared `get_date_range_args()` for consistent parameter handling.

## How to add cost tracking for a new LLM operation

1. In the method that calls the LLM API, use `call_openrouter_with_usage()` instead of `call_openrouter()`:
   ```php
   $response = self::call_openrouter_with_usage( $api_key, $model, $prompt );
   ```

2. After the API call, log the cost via `Peptide_News_Cost_Tracker::log_api_call()`:
   ```php
   if ( class_exists( 'Peptide_News_Cost_Tracker' ) && ! empty( $response['usage'] ) ) {
       Peptide_News_Cost_Tracker::log_api_call(
           $model,
           'your_operation_name', // e.g., 'keywords', 'summary', 'filter'
           $response['usage'],
           $article_id,
           $response['request_id'] ?? '',
           $response['cost'] ?? 0.0
       );
   }
   ```

3. Before the API call, check the budget gate:
   ```php
   if ( class_exists( 'Peptide_News_Cost_Tracker' ) && Peptide_News_Cost_Tracker::is_budget_exceeded() ) {
       // Skip the call, log a warning, return early
   }
   ```

4. If the model isn't in `DEFAULT_MODEL_PRICING`, add it to the constant array in `class-peptide-news-cost-tracker.php`.

## Security checklist for every change

- Sanitize all input: `sanitize_text_field()`, `absint()`, `esc_url_raw()`.
- Escape all output: `esc_html()`, `esc_attr()`, `esc_url()`, `wp_kses_post()`.
- Nonce on every form/AJAX: `wp_nonce_field()` / `check_ajax_referer()`.
- Capability check: `current_user_can( 'manage_options' )` on every admin action.
- API keys: always encrypt via `Peptide_News_Encryption::encrypt()`, decrypt via `::decrypt()`.
- Never expose API keys to the browser (all API calls are server-side only).

## Database migration pattern

- Schema changes go in `Peptide_News_Activator::create_tables()` using `dbDelta()`.
- The `plugins_loaded` hook in the bootstrap file checks `peptide_news_db_version` against `PEPTIDE_NEWS_VERSION` and re-runs activation if outdated.
- Always use `$wpdb->prepare()` for queries with dynamic values.
- Use `// phpcs:ignore` comments for unavoidable PHPCS warnings on `$wpdb->query()` calls.

## Type safety

All PHP files use `declare(strict_types=1)`. All public and private methods have typed parameters and return types. This catches type errors at call time rather than at runtime and gives AI agents structural information for reasoning about code.

## Git Workflow — PR-Gated, Soft-Enforced

This repo is private on GitHub's free plan, which does not support branch protection or rulesets. The review gate is enforced at the agent layer, not server-side. Every agent and human contributor follows these rules; the `.github/workflows/main-push-audit.yml` tripwire opens an audit issue on any direct push to `main` that did not come from a merged PR.

### Rules

1. **Never push to `main` directly.** Every change lands via a pull request that the maintainer merges. No self-merging. No force-pushing to `main`.

2. **Branch naming**: `claude/<scope>-<YYYYMMDD>` for agent-authored work, `fix/<scope>` or `feat/<scope>` for human-authored. The scope is a 1–3 word kebab-case description.

3. **Commit trailer**: every commit authored by an agent must end with an `Agent-Session:` trailer so commits can be correlated back to the conversation that produced them, even though all commits share the `peptiderepo` bot identity.

   ```
   feat: add per-request cost tracking

   Agent-Session: cowork-2026-04-14-cost-audit
   ```

4. **PR description template**: every PR description covers
   - **What changed** (one paragraph)
   - **Why** (motivation or incident link)
   - **Risk flags** (schema changes, API contract changes, cost impact, compatibility)
   - **Test plan** (what was run locally, what to smoke-test after merge)

5. **Emergency push exception**: if a situation genuinely requires pushing to `main` without a PR (site down, CI broken, one-line hotfix), surface it in the chat before doing it. Every emergency push gets a follow-up PR that commits the same changes through the normal flow so git stays the source of truth. The tripwire will open an audit issue — close it with a comment explaining why.

### Opening a PR from an agent session

The `gh` CLI is not installed in the Cowork sandbox. Use `curl` with the PAT from the workspace `.env.credentials`:

```bash
GH_TOKEN=$(grep "^GITHUB_PAT=" "$WORKSPACE/.env.credentials" | cut -d= -f2)

git push -u origin HEAD

curl -s -X POST -H "Authorization: token $GH_TOKEN" \
  -H "Content-Type: application/json" \
  "https://api.github.com/repos/peptiderepo/<repo>/pulls" \
  -d "$(jq -cn --arg title "<title>" --arg head "<branch>" --arg body "<body>" \
      '{title:$title, head:$head, base:"main", body:$body}')"
```

### When this changes

If the peptiderepo GitHub account is ever upgraded to Pro (or the repo goes public), replace this soft-enforcement section with a note pointing to the real branch protection rules in repo settings.