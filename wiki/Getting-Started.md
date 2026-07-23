# Getting Started

## Installation

1. Upload the plugin to `/wp-content/plugins/karks-crm`, or install it through the WordPress Plugins screen.
2. Activate it. On activation (and automatically on every upgrade afterward) the plugin:
   - Creates its database tables.
   - Adds a `kcrm_manager` role and grants the `kcrm_manage` capability to it and to Administrators.
   - Creates a published Page titled "CRM" at the `/crm/` slug, containing the `[karks_crm]` shortcode — this is the front-end interface.
   - Flushes rewrite rules once, so `/crm/` and its sub-pages resolve immediately.
3. The Dompdf library used for PDF invoice generation ships bundled in `vendor/` — there's no `composer install` step.
4. In wp-admin, go to **Karks CRM → Getting Started** for an in-app version of the walkthrough below, or **Karks CRM → Companies** to jump straight in.

## First-time setup, in order

Each step below depends on the one before it — a company has to exist before you can add customers/services under it, and both of those need to exist before you can invoice anyone.

### 1. Add a Company

Go to **Karks CRM → Companies → Add New**. Everything else belongs to a company — even if you only do business under one name, you need to add that one company first.

Worth setting up at the same time, since it carries through to every invoice:

- **Invoice Number Prefix** and **Next Invoice Number** — e.g. prefix `INV-`, starting at `1`, produces `INV-0001`, `INV-0002`, etc. The counter advances automatically after that; you only set the starting point once.
- **Default Tax Rate** — applied to invoices for this company by default (only to line items individually marked Taxable — see [Services](User-Guide#services)).
- **Currency Symbol**, **Logo**, **Invoice Footer**, **PDF Accent Color** — all shown on the generated PDF.
- **Accepted Payment Types** and **Payment Links** — shown to customers on the invoice/PDF as ways to pay.

If you manage more than one company, a company switcher (dropdown) appears on every screen once a second company exists.

### 2. Add Services

Go to **Karks CRM → Services → Add New**. Services are the things you bill for — add the ones you use repeatedly so they're a dropdown pick on invoices instead of manual entry every time.

Each service has:
- A **Pricing Type** — Hourly or Project-based (changes how quantity is labeled: hours vs. a flat count).
- A **Rate**.
- A **Taxable** checkbox — off by default; turn it on if this service's amount should have the invoice's tax rate applied to it.
- An **Active** checkbox — inactive services stop appearing in the invoice line-item dropdown without deleting their history.

Already have a service list elsewhere (e.g. QuickBooks)? See [CSV Import](User-Guide#csv-import) instead of adding them one at a time.

### 3. Add a Customer

Go to **Karks CRM → Customers → Add New**. A customer can optionally have "Jobs" nested under it (e.g. separate properties or projects for the same client) — these roll up together into that customer's balance and revenue totals, but each Job can be invoiced separately. See [Customers & Jobs](User-Guide#customers--jobs) for details.

### 4. Create, Download, and Send an Invoice

Go to **Karks CRM → Invoices → Add New**. Pick the customer, add a line per service (quantity/hours × rate computes the line amount automatically), and save.

Once saved:
- **Download PDF Invoice** generates a PDF using the company's logo/accent color/footer.
- **Email Invoice** opens a composer pre-filled from the company's email template, with that same PDF attached.
- **Record a Payment** appears below the invoice — recording payments is what moves the invoice's status between Open → Partially Paid → Paid automatically (see [Invoices, Line Items & Payments](User-Guide#invoices-line-items--payments)).

## Where to go next

- [User Guide](User-Guide) covers every feature in depth, including CSV import, Reports, company export/import, Appearance customization, and the front-end interface.
- [CSS Reference](CSS-Reference) if you're customizing the front-end's look.
