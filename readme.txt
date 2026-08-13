=== Karks CRM ===
Contributors: karkovack
Tags: crm, invoicing, customers, invoices
Requires at least: 6.2
Tested up to: 7.0.4
Requires PHP: 7.4
Stable tag: 0.9.9.1
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
* PDF invoice generation, download, an HTML preview, and emailing, with CC/BCC support and one-click CC suggestions from the customer's other email addresses on file
* Customer Open Balance PDF/CSV export (a customer + its Jobs, oldest open invoice first), plus a company-wide Open Balance total on the Customers list
* Reports (front-end): Revenue with a 12-month chart, per-customer revenue, and an Aging (accounts receivable) report, each with CSV export
* Export/import a full company as a JSON file, for migrating between sites or duplicating a company as a template
* Customizable front-end colors with automatic WCAG 2.1 contrast correction

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/karks-crm` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin. The Dompdf library used for PDF invoice generation ships bundled in `vendor/` -- no `composer install` step needed.
3. Go to Karks CRM → Companies and add your first company.

== Changelog ==

= 0.9.9.1 =
* Confirmed compatible with WordPress 7.0.4.
* New "Other Payment Instructions" field on the Company profile (wp-admin and front end) -- checking "Other" as an Accepted Payment Type now reveals a text field for it, the same way "Check" reveals "Make Checks Payable To". Shown on invoices and PDFs whenever "Other" is accepted and this field is filled in.

= 0.9.8 =
* New Country field on the Company and Customer profiles (wp-admin and front end), for tracking international customers. Defaults to United States for existing records. The invoice PDF's From/Bill To addresses now show the country when it's set to anything other than the United States, and the front-end Customers CSV export includes a Country column.
* New Street Address 2 field on the Company and Customer profiles, for apartment/suite/unit numbers or a second address line. Shows up wherever Street Address does: both edit screens, the invoice PDF, and the Customers CSV export.

= 0.9.7 =
* The front-end invoice edit screen's "Update Invoice"/"Create Invoice" button now stands out more -- larger, bolder, with a drop shadow and extra spacing above it -- instead of blending in with the page's other buttons.
* The Company Switcher is now hidden if you only have one company, since there's nothing to switch to.
* The Company Profile's Recent Actions feed now includes payments received, alongside invoices created/emailed and customers added.

= 0.9.6 =
* New "Log Out" link in the front-end nav, for CRM Manager users who don't have the wp-admin toolbar available to log out from.
* The front-end nav now collapses to a hamburger menu on narrow screens (768px and below), and uses smaller, tighter-spaced tabs on tablet-width screens (769px-1100px).

= 0.9.5 =
* The front-end customer profile is now tabbed (Home, Jobs, Invoices & Payments) instead of one long stacked page. The active tab lives in the URL, so a link or bookmark to a customer's invoices lands on the right tab; the Invoices screen's links into a customer's profile now point straight at Invoices & Payments.
* New `kcrm_customer_profile_tabs` filter so an add-on can contribute its own tab on the customer profile (see the Hooks and Filters wiki page).
* New CSV exports for the Customers, Services, and Invoices screens (front end), respecting whatever filter is currently applied (e.g. active-only customers, the selected invoice statuses).
* The front-end Tools screen can now export a company as a JSON file (previously wp-admin only), with a tooltip explaining what it's for.
* Removed the redundant "Open Balance" card from the front-end Customers list.

= 0.9.4 =
* Revenue report: each month on the 12-month chart now shows its total (with the company's currency symbol) under the month label, in the theme's primary color -- not just on hover.
* The front-end Company Profile is simpler: the full Customers and Invoices tables are gone, replaced by a "Search Customers" box that jumps straight to the Customers list already filtered to your search. The stat cards now link to the real Customers/Invoices screens instead of on-page sections that no longer exist.
* Fixed: an invoice line item's description always copied the selected Service's Name, even though Services have their own separate Description field -- it's now used instead (falling back to the Name only if a service has no description set).
* Fixed: the Company Profile's Recent Actions could show a slightly wrong "last 2 days" window depending on the server's PHP timezone setting.

= 0.9.3 =
* The front-end login form (shown to logged-out visitors on the CRM page) is now styled to match the rest of the front end instead of appearing as a bare, unstyled form.
* The Reports overview now has a short "Choose a report below:" description under the heading, and each report card has its own title (Revenue/Aging/Customer Report).
* Karks CRM's wp-admin menu order: "Companies" now sits right under "Getting Started" instead of near the bottom of the list.

= 0.9.2 =
* Negative line-item amounts (e.g. a discount) now display in parentheses -- e.g. "(50.00)" -- instead of a plain minus sign, everywhere an invoice's line items, totals, or balance due are shown: the edit screen, the Invoices list, the customer profile, and both PDFs.
* New "Preview" button next to "Download PDF" opens the invoice as a plain HTML page in a new tab -- same layout as the PDF, for a quick look without downloading anything. Internal use only for now, not yet a link meant for sharing with customers.
* The Invoices screen (list and edit screen) now links every customer name directly to that customer's profile.
* Fixed: typing raw HTML (e.g. a hand-typed link) directly into the "Email Invoice Template" or "Invoice Footer" field's Visual tab could silently corrupt straight quotes into curly quotes, breaking the HTML. Both fields are now a plain HTML/Quicktags editor instead of a rich-text (TinyMCE) one, which avoids this entirely.
* New "BCC Invoice Emails" company setting (off by default) -- BCCs a configured address on every "Email Invoice" send, e.g. to keep your own copy.
* Fixed: the PDF wasn't actually attached to invoice emails on sites where a mail-sending integration (e.g. Mailgun) replaces WordPress's own mail-sending internals. Also fixed hand-typed line breaks in the Email Invoice Template/Invoice Footer not showing up in the sent email/PDF (a side effect of the Visual-tab fix above).

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
