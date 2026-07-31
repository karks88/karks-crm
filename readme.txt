=== Karks CRM ===
Contributors: karkovack
Tags: crm, invoicing, customers, invoices
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.9.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A simple customer relationship management and invoicing plugin for tracking customers, services, and invoices across multiple companies.

== Description ==

Karks CRM is an internal customer relationship and invoicing tool for managing multiple companies from a single WordPress install. It tracks customers (including "Jobs" nested under a parent customer), billable services, invoices with line items, and payments, and can generate PDF invoices.

Features:

* Multi-company support with a company switcher in the admin and on the front end
* A front-end interface (a `/crm/` page) so a dedicated "CRM Manager" role can manage everything without wp-admin access
* Customer records with optional parent/Job relationships
* Hourly and project-based services, optionally marked Taxable
* Invoices with line items, per-line tax, and automatic status tracking (Open/Partially Paid/Paid) based on recorded payments
* Receive Payment: split one payment across several open invoices for a customer and its Jobs in a single entry, from the customer profile
* User-managed invoice types
* CSV import for customers, services, invoices, and payments (e.g. from QuickBooks exports), including automatic service creation from imported invoices
* PDF invoice generation, download, and emailing, with CC support and one-click suggestions from the customer's other email addresses on file
* Customer Open Balance PDF/CSV export (a customer + its Jobs, oldest open invoice first), plus a company-wide Open Balance total on the Customers list
* Reports (front-end): Revenue with a 12-month chart, per-customer revenue, and an Aging (accounts receivable) report, each with CSV export
* Export/import a full company as a JSON file, for migrating between sites or duplicating a company as a template
* Customizable front-end colors with automatic WCAG 2.1 contrast correction

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/karks-crm` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin. The Dompdf library used for PDF invoice generation ships bundled in `vendor/` -- no `composer install` step needed.
3. Go to Karks CRM → Companies and add your first company.

== Changelog ==

= 0.9.1 =
* Invoices screen: a "Customers with Multiple Jobs" section groups a customer's own invoices together with all of its Jobs' invoices (sorted by issue date), instead of scattering them through the regular sorted list; wp-admin also gets a Customer filter to narrow the list down to one customer (+ its Jobs).
* New "Send Invoices To" fields on the customer profile (Name + Email, optional) -- when set, the Email Invoice form defaults to this contact instead of the primary one.
* Email Invoice now has a CC field. It starts blank, with one-click suggestions for any other email addresses on file for that customer; "Last emailed to X on Y" now also shows who was CC'd.
* New "Open Balance" PDF/CSV export for a customer (+ its Jobs), available from the customer profile and the per-customer Report; the Customers list also shows a company-wide Open Balance total.

= 0.9.0 =
* New "Receive Payment" section on the customer profile (front end): split one payment across several open invoices for a customer and its Jobs in a single entry, with an oldest-invoice-first auto-fill you can still adjust per invoice before saving.
* Pagination Previous/Next links (Customers, Invoices, and a customer's Payments Received list) now jump back to that list instead of scrolling to the top of the page.

= 0.8.0 =
* The front-end Dashboard is no longer a separate landing screen -- visiting the CRM page now goes straight to the current company's Profile. Its "Add a Company" button and the all-companies overview table moved to a new Tools tab at the end of the navigation.
* PDF invoices now color-code the status badge (Paid/Partially Paid/Open/Void/Draft), matching the front end's existing status colors, so a Paid invoice stands out at a glance.
* Fixed the Company Profile's Recent Actions "Show more" toggle not working on the first click.
* Performance: added database indexes for the most common invoice, payment, and customer lookups; batched dozens of duplicate per-row database queries across the Dashboard, Company Profile, Customers, Invoices, and Reports screens; added pagination to the Customers and Invoices screens (200 per page) instead of always loading every row.
* Fixed a bug where adding a new front-end URL could silently 404 until Permalinks was manually re-saved.

= 0.7.2 =
* PDF invoice downloads/attachments and the default "Email Invoice" subject line now use a "{Customer Name} - {Type} Invoice" pattern instead of just the invoice number.

= 0.7.1 =
* Instant, client-side search added to the Customers and Invoices screens (wp-admin and front end).
* Paid invoices are now hidden from the Invoices screen by default (Draft, Open, and Partially Paid show unless you choose otherwise).
* Recent Actions area added to the Company Profile, showing recent invoice and customer activity.
* Various security hardening fixes identified via the WordPress Plugin Check tool.

= 0.6.1 =
* Fixed a 404 on every front-end link (Customers, Invoices, Services, Reports, etc.) when the CRM page is set as the site's homepage under Settings -> Reading. Self-heals automatically, the same way the front end already recovers after a site clone/restore.

= 0.6.0 =
* Company Name, Status, Invoice #, Issue Date, Due Date, and Balance Due columns are now sortable on the Customers and Invoices screens (wp-admin and front end).
* Invoices screen: filter the list down to specific statuses (Draft, Open, Partially Paid, Paid, Void) instead of always seeing everything.
* Fixed a contrast gap where a sortable column header's link (e.g. Status) could fall back to the page's default link color instead of the WCAG-checked header text color, on the front end's dark table headers.

= 0.5.0 =
* Front-end interface at /crm/, with a new "CRM Manager" role/capability for using it without wp-admin access.
* Reports tab (front-end only): Revenue with a 12-month chart, a per-customer report, and an Aging report, each with CSV export.
* Export a full company (profile, customers, services, invoices, payments) as a JSON file and import it into another site.
* CSV import for services, and Product/Service mapping (with automatic service creation) when importing invoices.
* Taxable services: mark a service (and its invoice line items) as taxable; invoice totals now only tax the marked lines.
* User-managed Invoice Types (Karks CRM -> Invoice Types), replacing the previously hardcoded list.
* Custom Appearance colors are now checked against WCAG 2.1 AA contrast automatically, with an option to disable the front end's stylesheet entirely.
* Delete button added to the invoice edit screen.
* New Getting Started screen (Karks CRM -> Getting Started).
* Self-healing rewrite rules: the front end no longer requires manually re-saving Permalinks after cloning or restoring a site.

= 0.4.0 =
* Customers screen and customer CSV import.

= 0.1.0 =
* Initial release.
