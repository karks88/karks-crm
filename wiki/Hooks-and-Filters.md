# Hooks and Filters

As of the current version, **Karks CRM does not define any of its own custom actions or filters** (`do_action()`/`apply_filters()`). Everything it hooks into is WordPress core (`init`, `admin_menu`, `admin_post_*`, `wp_enqueue_scripts`, etc.) rather than an extensibility point the plugin itself exposes.

If you need to customize behavior beyond what the [Appearance](User-Guide#appearance) settings and [CSS classes](CSS-Reference) already allow — e.g. modifying invoice data before a PDF renders, changing which fields a CSV importer maps, or altering merge-tag resolution — that would currently require a code change to the plugin itself (a child customization or a fork), not a hook. Worth opening an issue describing the specific use case if you hit one of these; targeted `do_action()`/`apply_filters()` calls can be added at the relevant point rather than building out speculative hooks nobody's using yet.

This page will be updated with a full list (hook name, location, parameters, example) the first time any are actually added.
