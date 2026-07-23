=== Karks CRM ===
Contributors: karkovack
Tags: crm, invoicing, customers, invoices
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

A simple customer relationship management and invoicing plugin for tracking customers, services, and invoices across multiple companies.

== Description ==

Karks CRM is an internal customer relationship and invoicing tool for managing multiple companies from a single WordPress install. It tracks customers (including "Jobs" nested under a parent customer), billable services, invoices with line items, and payments, and can generate PDF invoices.

Features:

* Multi-company support with a company switcher in the admin
* Customer records with optional parent/Job relationships
* Hourly and project-based services
* Invoices with line items, tax, and automatic status tracking (Open/Partially Paid/Paid) based on recorded payments
* CSV import for customers, invoices, and payments (e.g. from QuickBooks exports)
* PDF invoice generation and download

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/karks-crm` directory, or install the plugin through the WordPress plugins screen.
2. Activate the plugin. The Dompdf library used for PDF invoice generation ships bundled in `vendor/` -- no `composer install` step needed.
3. Go to Karks CRM → Companies and add your first company.

== Changelog ==

= 0.4.0 =
* Customers screen and customer CSV import.

= 0.1.0 =
* Initial release.
