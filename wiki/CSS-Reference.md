# CSS Reference

Every CSS class the plugin defines. The front end (`assets/css/front.css`, loaded on `/crm/`) is themeable via [Appearance](User-Guide#appearance) and can be turned off entirely; wp-admin (`assets/css/admin.css`) is fixed and always loads on Karks CRM's own wp-admin pages.

## Front end (`assets/css/front.css`)

### Color variables

Four CSS custom properties, set as a `:root` fallback in `front.css` and overridden by an inline `<style>` block from the saved [Appearance](User-Guide#appearance) settings:

| Variable | Purpose |
|---|---|
| `--kcrm-color-primary` | Buttons, active nav tab, links, card hover border. |
| `--kcrm-color-secondary` | Table heading background. |
| `--kcrm-color-accent` | Stat-card numbers/icons, heading top-border, link underline color. |
| `--kcrm-color-highlight` | Table row hover background. |

Plus five computed pairings (see `KCRM_Colors::inline_css()`) that keep text readable no matter which of the four colors above is picked, without needing you to check contrast yourself:

| Variable | Purpose |
|---|---|
| `--kcrm-color-primary-text` | Black or white (whichever contrasts better) — text color for anything using Primary as a *background* (e.g. `.kcrm-button-primary`). |
| `--kcrm-color-secondary-text` | Same, for Secondary as a background (table headings). |
| `--kcrm-color-highlight-text` | Same, for Highlight as a background (table row hover). |
| `--kcrm-color-primary-readable` | Primary itself, darkened just enough to hit 4.5:1 against white if it doesn't already — used where Primary is the *text* color on the page's white background (active nav tab, outline buttons, card arrows). |
| `--kcrm-color-accent-readable` | Same idea, for Accent used as text (stat-card numbers/icons). |

### Layout & navigation

| Class | Purpose |
|---|---|
| `.kcrm-front` | Outer wrapper for the whole `/crm/` page — max-width, base font size/line-height. |
| `.kcrm-front-screen` | Wrapper for a single screen's content within `.kcrm-front`. |
| `.kcrm-front-nav` | The tab bar (Company Profile/Customers/Services/Invoices/Reports/Tools). |
| `.kcrm-front-nav a.is-active` | The current tab. |
| `.kcrm-company-header` | Two-column header (logo left, company switcher right) shown above the heading on every screen except Tools. |
| `.kcrm-current-company-logo` | Wraps the company logo image inside `.kcrm-company-header`. |
| `.kcrm-company-switcher` | Wraps the company `<select>` dropdown. |

### Buttons & cards

| Class | Purpose |
|---|---|
| `.kcrm-button` | Base button/link style (outline). |
| `.kcrm-button-primary` | Filled/primary variant. |
| `.kcrm-button-group` | Spacing wrapper around one or more buttons. |
| `.kcrm-dashboard-cards` | Flex row wrapping a set of `.kcrm-card` stat tiles. |
| `.kcrm-card` | A single stat tile (Company Overview, Reports overview, customer revenue summary). |
| `.kcrm-card-icon` | Dashicon inside a card. |
| `.kcrm-card-number` | The large number/value in a card. |
| `.kcrm-card-label` | The small caption under a card's number. |
| `.kcrm-card-arrow` | The small "→" affordance icon on clickable overview cards, animates on hover. |

### Tables, forms & pagination

| Class | Purpose |
|---|---|
| `.kcrm-front-table` | Base table style used for every list (customers, services, invoices, payments). |
| `.kcrm-front-form` | Wraps a form for consistent label/input spacing. |
| `.kcrm-pagination` / `.kcrm-pagination-status` | Prev/Next controls and the "Page X of Y" text. |
| `.kcrm-date-range-filter` | The This Year/Last Year/All/Custom Range filter form (Invoices, Reports). |
| `.kcrm-date-range-custom` | The From/To date inputs, shown only when "Custom Range" is selected. |

### Status badges

| Class | Purpose |
|---|---|
| `.kcrm-status` | Base pill/badge style. |
| `.kcrm-status-paid` / `-partial` / `-open` / `-void` / `-draft` | Invoice status colors. |
| `.kcrm-status-active` / `-inactive` | Customer status colors. |

### Reports bar chart

| Class | Purpose |
|---|---|
| `.kcrm-bar-chart` | Container for the Revenue report's trailing-12-month chart. |
| `.kcrm-bar-chart-col` | One month's column (bar + label). |
| `.kcrm-bar-chart-bar` | The bar itself, height set inline per month's value. |
| `.kcrm-bar-chart-label` | The month label under a bar. |

### Modal

| Class | Purpose |
|---|---|
| `.kcrm-modal-overlay` | Full-screen dimmed backdrop (Email Invoice modal). |
| `.kcrm-modal` | The modal panel itself. |

## wp-admin (`assets/css/admin.css`)

Scoped under `.kcrm-wrap` (the outer wrapper on every Karks CRM wp-admin screen). Reuses `.kcrm-dashboard-cards`/`.kcrm-card`/`.kcrm-card-number`/`.kcrm-card-label` and the `.kcrm-status*` badges from above (same names, plain colors — no CSS variables/Appearance theming in wp-admin). One admin-only class:

| Class | Purpose |
|---|---|
| `.kcrm-job-row` | Indents and shades a Job's row under its parent customer in the Customers list table. |
