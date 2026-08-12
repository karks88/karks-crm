# Karks CRM

Karks CRM is an internal customer relationship and invoicing tool for managing multiple companies from a single WordPress install. It tracks customers (including "Jobs" nested under a parent customer), billable services, invoices with line items and payments, and generates PDF invoices — from wp-admin, or from a dedicated front-end interface at `/crm/` for users who don't need full wp-admin access.

## Pages in this wiki

- **[Getting Started](Getting-Started)** — installation and the first-time setup walkthrough (company → services → customers → invoices).
- **[User Guide](User-Guide)** — full reference for every feature: Companies, Customers & Jobs, Services, Invoices, Payments, CSV Import, Reports, Company Export/Import, Appearance, and the front-end interface/roles.
- **[CSS Reference](CSS-Reference)** — every CSS class the plugin defines, front-end and wp-admin, with what each one does.
- **[Hooks and Filters](Hooks-and-Filters)** — extensibility points (currently none — see that page for why, and what to do if you need one).

## Quick facts

- **Website:** [karks-crm.com](https://karks-crm.com)
- **Data model:** Company → Customers (optionally with nested "Jobs") and Services, both feeding Invoices → Line Items and Payments. Everything is scoped to a company; nothing is shared across companies.
- **Capability:** `kcrm_manage`, granted to Administrators automatically and to a dedicated `kcrm_manager` role (see [User Guide § Front-End Interface & Roles](User-Guide#front-end-interface--roles)).
- **PDF generation:** via [Dompdf](https://github.com/dompdf/dompdf), bundled in `vendor/` — no separate install step required.
- **Front-end URL:** `/crm/`, created automatically on activation (a normal published Page containing the `[karks_crm]` shortcode).
