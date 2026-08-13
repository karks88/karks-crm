# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Karks CRM is a standalone WordPress plugin: an internal customer relationship/invoicing tool for managing multiple companies from one WordPress install (customers, "Jobs" nested under a parent customer, hourly/project services, invoices with line items, payments, PDF invoice generation). It's usable entirely from wp-admin, or from a front-end `/crm/` page by a dedicated "CRM Manager" role that doesn't need wp-admin access at all.

The `karks-crm-packages` plugin in the sibling directory is a separate, decoupled add-on that depends on this plugin. See its own `CLAUDE.md` for how it integrates — it only touches a small, deliberate surface documented in `wiki/Hooks-and-Filters.md` and this plugin's own `KCRM_CAPABILITY`/`KCRM_Context`/model classes. When changing anything in that surface (the `kcrm_customer_edit_after_sections` hook signature, the `kcrm_customer_profile_tabs` filter, `KCRM_Model_Base`, `KCRM_Context::get_current_company_id()`, `KCRM_Colors::get()`, `KCRM_Company::pdf_accent_color()`, `KCRM_PDF::logo_data_uri()`, `KCRM_Front::is_crm_page()`/`endpoint_url()`), check whether it breaks that add-on too.

There is no linter config or JS build step in this repo — plain PHP/CSS/JS loaded directly by WordPress, plus Dompdf vendored in `vendor/` via Composer (`composer.json` declares `dompdf/dompdf`; `vendor/` is committed so no `composer install` step is needed after checkout/activation). To exercise changes, activate the plugin in a WordPress install and use wp-admin (`Karks CRM` menu) or the front-end `/crm/` page. There is a PHPUnit suite — see Testing below.

## Release process

Pushing a tag matching `v*` triggers `.github/workflows/release.yml`, which stages the repo (respecting `.distignore`, which also excludes `wiki/`) into a `karks-crm/` folder and zips it, so the release asset's top-level folder name is stable across versions (unlike GitHub's auto-generated source zip). Bump `Version:` in `karks-crm.php` and `KCRM_VERSION` together before tagging, and add a changelog entry to `readme.txt`.

## Testing

`tests/phpunit/` is a PHPUnit suite (`WP_UnitTestCase`, real WP core + a real MySQL DB — not mocked) focused on data integrity: schema/upgrade safety (`SchemaIntegrityTest`), the model layer's column whitelist (`ModelColumnWhitelistTest`), the `field_or_existing()` partial-POST protection and its documented checkbox/repeater exception (`CompanySaveFieldProtectionTest`), and the `maybe_upgrade()` failure-handling added above (`UpgradeFailureHandlingTest`). Runs automatically on push/PR via `.github/workflows/tests.yml`.

Test tooling (PHPUnit, `wp-phpunit/wp-phpunit`, `yoast/phpunit-polyfills`) lives in `tests/composer.json` — a **separate** Composer project with its own `tests/vendor/` (gitignored, install via `composer install` inside `tests/`), deliberately kept out of the plugin's own root `vendor/` (which ships Dompdf in releases) so dev-only tooling never risks leaking into a release zip or the production autoloader.

To run locally: point `tests/wp-tests-config.php` at a real DB via env vars (`WP_TESTS_DB_NAME`/`_USER`/`_PASSWORD`/`_HOST`) and run `php tests/vendor/bin/phpunit -c phpunit.xml.dist` from the plugin root — `ABSPATH` is derived from `tests/`'s own location, so this works against whatever full WordPress install the plugin is actually checked out inside (a LocalWP site, a `wp-core` checkout in CI, etc.) without further config.

## Documentation

`wiki/` (GitHub wiki source, not shipped in releases) has more detail than this file for user-facing behavior: `Getting-Started.md`, `User-Guide.md`, `CSS-Reference.md` (front-end theming classes), and `Hooks-and-Filters.md` (the add-on extension API — currently just `kcrm_customer_edit_after_sections`; if a change needs a new extension point for `karks-crm-packages` or another add-on, add it there and document it in that file rather than exposing more surface silently).

## Architecture

### Two parallel UIs, one set of controllers

Every feature (companies, customers, services, invoices, reports) is reachable from **both** wp-admin and the front-end `/crm/` page, sharing one controller class hierarchy:

- `KCRM_Controller_Base` (`includes/controllers/class-kcrm-controller-base.php`) holds everything context-agnostic: pagination, date-range/status filter widgets, notices, the company switcher, `field_or_existing()` (see below).
- `KCRM_Admin_Screen_Trait` / `KCRM_Front_Screen_Trait` (bottom of the same file) each supply a different `screen_url()` — `admin.php?page=<PAGE>` vs. the front-end rewrite endpoint — so the same business-logic subclass pattern works in both contexts without duplicating URL-building logic.
- Concrete screens live in `includes/admin/class-kcrm-admin-*.php` (wp-admin) and `includes/front/class-kcrm-front-*.php` (front end) — e.g. `KCRM_Admin_Customers` and `KCRM_Front_Customers` both handle the "customers" feature but are separate classes, each wired into their own dispatcher (see below). When changing behavior for a feature that exists in both, check both files.

### Boot sequence

`karks-crm.php` requires models → `KCRM_Context`/`KCRM_Colors`/`KCRM_Merge_Tags`/controllers base → `KCRM_Plugin` (wp-admin, requires all `includes/admin/*`) → `KCRM_Front` (front end, requires all `includes/front/*`), then boots both from `plugins_loaded` via `kcrm_run()`. `KCRM_Activator::maybe_upgrade()` runs on `init` at priority 20 (deferred past `plugins_loaded` because it may call `wp_insert_post()`/`flush_rewrite_rules()`, neither safe before `$wp_rewrite` exists).

Each dispatcher (`KCRM_Plugin::handle_screen_actions()`, `KCRM_Front::handle_screen_actions()`) routes to **only the one screen matching the current page/endpoint** before calling its `handle_actions()` — several screens share generic query args like `action=delete&id=`, and `check_admin_referer()` calls `wp_die()` on a nonce mismatch, so dispatching to every screen unconditionally would let the wrong screen's `delete()` 403 a legitimate request on a different screen.

### Data layer

All custom tables (`karkscrm_*`, names centralized in `KCRM_DB`) and their DDL live in `KCRM_Activator::create_tables()`, gated by the `kcrm_db_version` option vs `KCRM_DB_VERSION` — bump the latter on schema changes. Every model in `includes/models/` extends `KCRM_Model_Base`, which provides generic `find()`/`find_many()`/`where()`/`count_where()`/`insert()`/`update()`/`delete()` built on `%i`/`%s`/`%d` `$wpdb->prepare()` placeholders; add a new model by declaring `table()` and a `columns()` whitelist (column => `%d`/`%s`/`%f` format), not by writing new raw SQL. `update()`/`insert()` only ever write columns present in that whitelist, so a partial `$data` array can't accidentally clobber unlisted columns — this is what makes `field_or_existing()` (see Security/validation conventions below) safe.

`create_tables()` runs through `dbDelta()`, which only ever adds/widens columns — it never drops one, even a column the current `CREATE TABLE` string doesn't mention at all — so re-running it on every version bump can't lose existing data by itself. `maybe_upgrade()` additionally checks `$wpdb->last_error` after each statement (best-effort, since `dbDelta()` doesn't return per-statement success/failure) and, on a detected failure, does *not* bump `kcrm_db_version` — it keeps retrying (throttled to once/hour via `kcrm_db_upgrade_failed_at`) and shows a persistent `manage_options`-gated admin notice via `kcrm_db_upgrade_error`, rather than silently marking a failed migration "done".

**Balances/totals are computed live, never cached-and-trusted**: `KCRM_Payment::total_for_invoice()`/`balance_due()` sum payment rows on demand rather than storing a running balance on the invoice (same precedent `karks-crm-packages` follows for package hours-remaining). Batched variants (`find_many()`, and payment/invoice equivalents) exist specifically so list screens can avoid N+1 per-row queries — prefer them over calling `find()`/a per-invoice total method inside a loop.

`KCRM_Company_Transfer` exports/imports a whole company (profile, customers, services, invoices, items, payments) as JSON for migrating/duplicating between sites; it deliberately excludes the logo attachment and any cached invoice totals, recomputing totals fresh on import rather than trusting a possibly-stale export.

### Company scoping (`KCRM_Context`)

Almost every screen is scoped to "the company the current user is currently looking at," resolved by `KCRM_Context::get_current_company_id()` and memoized per-request (it's called repeatedly across one page render — header, switcher, screen body). Persisted in user meta (`kcrm_current_company_id`), changed via a nonce-verified `?kcrm_company=` query arg. New screens that list/filter company-scoped data should go through this rather than inventing another way to track "current company."

### Front-end routing (`KCRM_Front`)

The front end is a `[karks_crm]` shortcode on an auto-created "CRM" page, with each feature registered as a rewrite endpoint (`KCRM_Front::ENDPOINTS`) under that page. Two non-obvious pieces of plumbing exist purely to make that robust:

- `sanitize_query_vars()` strips any WordPress public query var (e.g. `name`, `order`, `orderby`) that isn't the matched endpoint or `pagename`/`page_id` — without it, a form field or sort link on the CRM page that happens to reuse one of those reserved names corrupts the main query and 404s the page.
- If the CRM page is set as the site's static homepage, WordPress's own `EP_PAGES` rewrite rules and `redirect_canonical()` both stop covering the endpoints (the homepage's rule is just `^$`); `maybe_add_front_page_rewrite_rules()` (called from `register_endpoints()`) and `prevent_front_page_endpoint_redirect()` compensate, and `KCRM_Activator::maybe_flush_rewrite_rules()` checks the *actual persisted* `rewrite_rules` option (not a version flag) so this self-heals after a site clone/restore or a homepage-setting change, without requiring someone to manually re-save Permalinks.

If you add a new endpoint to `KCRM_Front::ENDPOINTS`, both of those need to keep working for it — nothing marks a "front-page-safe" endpoint as opt-in.

### Front-end theming

`KCRM_Colors` holds a 4-color scheme (Primary/Secondary/Accent/Highlight) editable from the Appearance screen, emitted as CSS custom properties (`inline_css()`) that override `front.css`'s defaults. It also computes WCAG 2.1 AA-compliant text/foreground pairings (`contrast_text()`, `readable_foreground()`) so a badly-chosen custom color can't make text unreadable — any new UI element using a custom color as a background or as text-on-white should reuse these rather than hardcoding contrast assumptions. "Disable Plugin Styles" (`KCRM_Colors::styles_disabled()`) skips `front.css`/inline colors entirely but keeps Dashicons and JS loading, for sites that want to restyle from their own theme.

### PDF generation

`KCRM_PDF` (`includes/pdf/`) renders `templates/invoice-pdf.php` / `templates/customer-open-balance-pdf.php` to HTML via `ob_start()`, then converts with the vendored `\Dompdf\Dompdf`. `KCRM_PDF::logo_data_uri()` and the accent-color resolution here are the exact logic `karks-crm-packages`' own PDF reuses to stay visually consistent — changes to logo rendering or color fallback here should be checked against that add-on too.

### Security/validation conventions used throughout

- Every state-changing handler calls `check_admin_referer()`/nonce verification and a `current_user_can( KCRM_CAPABILITY )` check itself, even though the outer dispatcher (`handle_screen_actions()`) already routed by page/endpoint — that outer check is read-only dispatch, not authorization.
- `field_or_existing()` (`KCRM_Controller_Base`) reads a POSTed field if the key is present at all, else falls back to the existing record's current value (not a hardcoded default) — guards a partial/malformed request against silently wiping the rest of a record, since real edit forms always resubmit every field. Not appropriate for checkbox groups/repeaters, where "key entirely absent" is itself a legitimate submitted state.
- `// phpcs:ignore WordPress.Security.NonceVerification...` comments mark request-param reads that are intentionally pre-nonce-check (routing/view/pagination params, or reads immediately followed by the real nonce check) — not blanket suppressions. Follow the same justify-inline pattern rather than removing/broadening these.
