# Hooks and Filters

Karks CRM exposes a small, growing set of extension points for add-ons. Everything else it hooks into is WordPress core (`init`, `admin_menu`, `admin_post_*`, `wp_enqueue_scripts`, etc.) rather than something the plugin itself defines.

## Actions

### `kcrm_customer_edit_after_sections`

Fires at the end of the customer edit screen, after all of Karks CRM's own built-in sections (Jobs, Revenue, Invoices, and — front-end only — Payments) have rendered. Fires in both the wp-admin customer screen and the front-end `/crm/` customer screen; on the front end this means below the tab shell described in `kcrm_customer_profile_tabs` below, visible regardless of which tab is active, rather than inside any one tab. Use it to render additional read-only sections or summary boxes for a specific customer without modifying core files.

```php
do_action( 'kcrm_customer_edit_after_sections', $customer, $rollup_ids );
```

- `$customer` (object) — the `KCRM_Customer` row being viewed/edited.
- `$rollup_ids` (int[]) — the customer's own ID plus any Job (sub-customer) IDs rolled up under it; matches the same ID set the built-in Revenue/Invoices sections use.

Example listener:

```php
add_action( 'kcrm_customer_edit_after_sections', function ( $customer, $rollup_ids ) {
    echo '<h2>My Add-on</h2>';
    // ... render something using $customer->id / $rollup_ids ...
}, 10, 2 );
```

### `kcrm_front_tools_after_sections`

Front-end only. Fires at the end of the Tools screen (`/crm/tools/`), after the company list and "Add a Company" button. Use it to render additional company-level utility UI (e.g. a backup/restore panel) without a whole new nav tab/rewrite endpoint of your own — this screen is already the home for company-level utility actions like Export.

```php
do_action( 'kcrm_front_tools_after_sections' );
```

Takes no arguments; there's no single "current customer" here the way `kcrm_customer_edit_after_sections` has a current customer, so read whatever context you need yourself (e.g. `KCRM_Context::get_current_company_id()` for "the current company", or loop `KCRM_Company::all_ordered()` for an all-companies view like the built-in table above it).

Example listener:

```php
add_action( 'kcrm_front_tools_after_sections', function () {
    $company_id = KCRM_Context::get_current_company_id();
    if ( ! $company_id ) {
        return;
    }
    echo '<h3>' . esc_html__( 'My Add-on', 'my-addon' ) . '</h3>';
    // ... render something using $company_id ...
} );
```

### `kcrm_company_import_data`

Fires at the end of `KCRM_Company_Transfer::import()`, after core's own data (company profile, customers, services, invoices/items/payments) has been fully imported. Use it to import your own add-on's per-customer/per-company data (e.g. a notes/tasks add-on) from the same decoded file — see `kcrm_company_export_data` below for how to get your data into that file in the first place.

```php
do_action( 'kcrm_company_import_data', $data, $company_id, $customer_id_map, $service_id_map, $target_company_id );
```

- `$data` (array) — the decoded import payload, i.e. whatever your `kcrm_company_export_data` listener returned at export time. Read your own top-level key from it.
- `$company_id` (int) — the resolved company id: either the pre-existing company being restored into, or the newly-created company, depending on which path this import took.
- `$customer_id_map` (array) — old customer id (as it appeared in `$data['customers']`) => newly-inserted customer id. Use this to remap any customer id your own rows reference.
- `$service_id_map` (array) — old service id => newly-inserted service id, same idea, for anything keyed by service.
- `$target_company_id` (int) — `0` when this import created a brand-new company (so there's nothing of yours to wipe first); otherwise the id of the existing company being restored into.

**Only touch existing data when your own key is actually present in `$data`.** An import file made before your add-on existed (or exported while it happened to be inactive) simply won't have your key — treat that as "leave whatever's already there alone," not as "the backup says I have nothing." This is what keeps a restore from an old backup non-destructive toward newer add-on data that backup never knew about.

Example listener (a hypothetical notes add-on, following the wipe-then-rebuild pattern core itself uses for customers/services/invoices, but scoped to its own table and its own key):

```php
add_action( 'kcrm_company_import_data', function ( $data, $company_id, $customer_id_map, $service_id_map, $target_company_id ) {
    if ( ! isset( $data['my_addon_notes'] ) || ! is_array( $data['my_addon_notes'] ) ) {
        return; // This export predates (or was made without) my add-on -- don't touch existing data.
    }
    if ( $target_company_id ) {
        foreach ( My_Addon_Note::where( array( 'company_id' => $target_company_id ) ) as $existing ) {
            My_Addon_Note::delete( $existing->id );
        }
    }
    foreach ( $data['my_addon_notes'] as $row ) {
        $new_customer_id = $customer_id_map[ (int) $row['customer_id'] ] ?? 0;
        if ( ! $new_customer_id ) {
            continue; // The customer it belonged to wasn't restored.
        }
        My_Addon_Note::insert( array( 'company_id' => $company_id, 'customer_id' => $new_customer_id /* ...sanitize and pass through your own fields... */ ) );
    }
}, 10, 5 );
```

## Filters

### `kcrm_company_export_data`

Filters the fully-built export array right before `KCRM_Company_Transfer::export()` returns it. Use it to append your own top-level key with your add-on's own per-customer/per-company data, keyed by the *original* customer/service IDs already present in `$data['customers']`/`$data['services']` — those are exactly what `$customer_id_map`/`$service_id_map` remap on import (see `kcrm_company_import_data` above).

```php
$data = apply_filters( 'kcrm_company_export_data', $data, $company_id );
```

- `$data` (array) — the export array (`format_version`, `exported_at`, `company`, `customers`, `services`, `invoices`).
- `$company_id` (int) — the company being exported.

Namespace your own top-level key so it can't collide with another add-on's (e.g. `my_addon_notes`, not `notes`).

Example listener:

```php
add_filter( 'kcrm_company_export_data', function ( $data, $company_id ) {
    $rows = array();
    foreach ( $data['customers'] as $customer ) {
        foreach ( My_Addon_Note::for_customer( $customer['id'] ) as $note ) {
            $rows[] = array(
                'customer_id' => (int) $note->customer_id,
                'body'        => $note->body,
                // ...whatever other fields your add-on needs to restore later...
            );
        }
    }
    $data['my_addon_notes'] = $rows;
    return $data;
}, 10, 2 );
```

### `kcrm_customer_profile_tabs`

Front-end only. Filters the tabs shown on the customer profile screen (`/crm/customers/?view=edit&id=…`) — Home, Jobs (skipped for a customer that's itself a Job), and Invoices & Payments are the built-in tabs; use this to add another one (e.g. a "Packages" tab) instead of only being able to append content at the very end via `kcrm_customer_edit_after_sections` above, which still fires afterward, below every tab, for anything that doesn't need a tab of its own.

```php
$tabs = apply_filters( 'kcrm_customer_profile_tabs', $tabs, $customer, $rollup_ids );
```

- `$tabs` (array) — `tab_slug => array( 'label' => string, 'badge' => int|string|null, 'render' => callable(): void )`. `render` is called with no arguments and expected to `echo` the panel's HTML directly (same convention as the action hooks on this page); wrap your own data in a closure to capture whatever it needs. A tab whose `label`/`render` don't resolve to something usable is silently dropped rather than fatal-erroring the page.
- `$customer` (object) — the `KCRM_Customer` row being viewed.
- `$rollup_ids` (int[]) — the customer's own ID plus any Job (sub-customer) IDs rolled up under it.

Example listener:

```php
add_filter( 'kcrm_customer_profile_tabs', function ( $tabs, $customer, $rollup_ids ) {
    $tabs['packages'] = array(
        'label'  => __( 'Packages', 'my-addon' ),
        'render' => function () use ( $customer, $rollup_ids ) {
            echo '<h3>' . esc_html__( 'Packages', 'my-addon' ) . '</h3>';
            // ... render your own table using $customer->id / $rollup_ids ...
        },
    );
    return $tabs;
}, 10, 3 );
```

Each tab's URL is a plain `tab=<slug>` query arg (e.g. `&tab=packages`) on the customer profile link, so it's a real bookmarkable/shareable page state, not JS-driven show/hide — link directly to `tab=packages` from elsewhere in your add-on rather than always landing on Home.

If you need to customize behavior beyond what the [Appearance](User-Guide#appearance) settings, [CSS classes](CSS-Reference), and the hooks above already allow, that currently requires a code change to the plugin itself (a child customization or a fork). Worth opening an issue describing the specific use case if you hit one of these; targeted `do_action()`/`apply_filters()` calls can be added at the relevant point rather than building out speculative hooks nobody's using yet.

This page will be updated as more hooks are added.
