=== Karks CRM ===
Contributors: karks88
Tags: crm, invoicing, customers, invoices
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.10.3
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

== Frequently Asked Questions ==

= Who is Karks CRM for? =

Karks CRM is a great fit for any small business looking for a simple way to manage customers and send invoices.

= How do email invoices work? =

With Karks CRM, you can email customer invoices directly within WordPress. You can customize the email to meet your needs, and there are merge tags for further personalization. Your message and a copy of the PDF will be sent to your customer. The plugin also uses the contact information already in a customer’s profile, saving you from repeatedly filling out the same address.

The plugin uses the built-in WordPress email functionality. However, we recommend using a mail delivery service or sending via a separate SMTP server to avoid spam issues. 

= What customer data does Karks CRM store? =

The plugin stores any customer contact and invoice data you input. That could include (but is not limited to) physical addresses, email addresses, contact names, phone numbers, and invoice details (pricing, services rendered, etc.)

Karks CRM **DOES NOT** collect payment information by default. It doesn’t connect to any third-party payment gateways. Please do not use this plugin to store sensitive data!

= Who can access the CRM? =

By default, only site Administrators and users with the included custom **CRM Manager** roles can access customer data. Other users won’t be able to access the CRM.

= What are the server requirements for Karks CRM? =

You can use Karks CRM on any WordPress installation, whether locally or through a traditional web host. The plugin will work on any existing site. However, you could create a separate site just for your invoicing needs.

As Karks CRM stores customer data, we recommend using the most secure hosting environment possible.

= Why doesn’t the plugin work with payment gateways or other third-party services? =

It’s all about security and ease of maintenance. Building connections to payment gateways or other services requires constant vigilance. That clashes with the simple approach of this plugin.

**Note:** The plugin allows you to add [custom payment links](https://github.com/karks88/karks-crm/wiki/User-Guide#companies), which will appear as buttons on your invoices. You can also add payment instructions to your emails.

== Screenshots ==

1. The front-end company dashboard provides quick access to common tasks.
2. Use the Customers screen to find and manage your customers.
3. Create a custom invoice for your customers with hourly or project-based line items.
4. Keep track of invoices via a searchable listing.
5. Customer profiles let you view invoice status, payments received, and more.
6. Download your invoice as a custom PDF or email it directly within WordPress.
7. Custom reports let you know where you stand.
8. Your company profile includes your contact info, a custom logo, invoice footer, email message, and more. You can manage most aspects of your company on the front or back end.
9. Edit your color scheme or disable all CSS styles for total control of the CRM’s look.
10. Custom Invoice Types add flexibility to invoicing.
11. Add your services and choose from hourly or project-based billing. Services can be imported as a CSV file.
12. Custom PDF invoices include your logo, a custom accent color, and custom payment links.


== Changelog ==

= 0.9.10.3 =
* Fixed: saving a customer, receiving a payment, or any other action that redirects back to a front-end record-edit screen (`KCRM_Controller_Base::redirect()`, used by every controller's save/delete/payment/import handler) could land on "This link has expired" -- the redirect itself never carried the nav nonce that 0.9.10.2 started requiring. `redirect()` now always includes it.
* Hardening: `KCRM_Company_Transfer::import()` now runs every imported customer, service, invoice, line-item, and payment field through the same sanitizers as the normal save screens (previously only the company profile was sanitized) -- so a hand-edited export file can't store unescaped markup, an invalid country, or a malformed date. Imported line-item amounts are recomputed from quantity x rate rather than trusted from the file. Per WordPress.org Plugin Review Team feedback.
* Hardening: `GET` parameters read through `sanitize_key()` are now unslashed first, and three admin-post download handlers gained the standard nonce-verification comment, for Plugin Check compliance. No behavior change.

= 0.9.10.2 =
* Fixed: the front-end customer profile's `id` query arg now fails closed instead of open when its nav nonce is missing or invalid -- previously it silently fell back to "Customer not found," which is what add-ons linking into that screen without the nonce (e.g. Karks CRM Packages' "Log Usage" flow) hit after this plugin's nonce hardening. Now shows an explicit "link has expired" message instead, and `KCRM_Front::nav_nonce()`/`nav_nonce_args()` are available for add-ons to build valid links.
* Corrected "Tested up to" (7.0 -> 7.1).

= 0.9.10.1 =
* Hardening: replaced inline `<script>` blocks in the Company Profile, Invoice, and Appearance screens (wp-admin and front end) with properly enqueued/inlined scripts via `wp_enqueue_script()`/`wp_add_inline_script()`, per WordPress.org Plugin Review Team feedback.
* Corrected the Contributors field in this file (karkovack -> karks88).

= 0.9.10.0 =
* Added an extension point for add-ons: a new `kcrm_front_tools_after_sections` action fires at the end of the front-end Tools screen (see the wiki's Hooks and Filters page).
* `KCRM_Company_Transfer::import()` gained an optional second parameter to restore a company's data in place (for the upcoming Karks CRM Backups add-on); the existing Import feature's behavior (always creates a new company) is unchanged.
* Fixed: the front end's h3 subheading spacing (added so a section's own subheading has visible separation from unrelated content above it) was being applied even when an h3 immediately follows its own h2 -- e.g. "Companies" under the Tools screen's "Tools" heading -- pushing it down and adding an unwanted divider line right below the section title it belongs to.

= 0.9.9.7 =
* Hardening: the Email Invoice merge tags ({{first_name}}, {{last_name}}, {{customer}}, {{service}}) now strip any HTML from customer/service data before substituting into the email template, so a customer record can't inject markup into an outbound email regardless of which code path sends it.

= Older versions =

See changelog.txt for the complete version history.
