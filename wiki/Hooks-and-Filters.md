# Hooks and Filters

Karks CRM exposes a small, growing set of extension points for add-ons. Everything else it hooks into is WordPress core (`init`, `admin_menu`, `admin_post_*`, `wp_enqueue_scripts`, etc.) rather than something the plugin itself defines.

## Actions

### `kcrm_customer_edit_after_sections`

Fires at the end of the customer edit screen, after all of Karks CRM's own built-in sections (Jobs, Revenue, Invoices, and — front-end only — Payments) have rendered. Fires in both the wp-admin customer screen and the front-end `/crm/` customer screen. Use it to render additional read-only sections or summary boxes for a specific customer without modifying core files.

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

If you need to customize behavior beyond what the [Appearance](User-Guide#appearance) settings, [CSS classes](CSS-Reference), and the hook above already allow, that currently requires a code change to the plugin itself (a child customization or a fork). Worth opening an issue describing the specific use case if you hit one of these; targeted `do_action()`/`apply_filters()` calls can be added at the relevant point rather than building out speculative hooks nobody's using yet.

This page will be updated as more hooks are added.
