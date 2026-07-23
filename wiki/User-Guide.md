# User Guide

Every screen in this guide exists twice — once in wp-admin (**Karks CRM** menu) and once on the front end (`/crm/`) — with identical behavior in both places. Screenshots aren't included here since both interfaces re-theme (front end via [Appearance](#appearance)) and this guide would go stale; menu paths given are for wp-admin, with the front-end equivalent noted where it differs.

## Companies

**Karks CRM → Companies.** The top-level record everything else belongs to. Fields:

| Field | Notes |
|---|---|
| Name, Email, Phone, Address | Shown on invoices/PDFs. |
| Logo | A media library image; shown on PDF invoices. Not included in [company export](#company-exportimport). |
| Invoice Number Prefix / Next Invoice Number | e.g. `INV-` + `1` → next invoice is `INV-0001`. The counter advances on its own after each invoice is created. |
| Default Tax Rate | Applied to invoices for this company, but only to line items individually marked **Taxable** (see [Services](#services)). |
| Currency Symbol | Display only — the plugin doesn't do currency conversion. |
| Invoice Footer | Rich text, shown at the bottom of every PDF invoice for this company (payment terms, bank details, etc.). |
| Accepted Payment Types / Payment Links | Checkboxes (Credit Card, ACH, PayPal, Venmo, Zelle, Check, Cash, Other) plus a repeatable label/URL list (e.g. a PayPal.me link) — both shown on invoices as ways to pay. Checking "Check" reveals a "Make Checks Payable To" field. |
| PDF Accent Color | Used for the invoice title/totals on this company's PDFs. Falls back to the global [Appearance](#appearance) Primary color if left blank. |
| Email Invoice Template | Rich text, pre-fills the body when using "Email Invoice" on an invoice. Supports merge tags (shown on the edit screen) resolved against the invoice/customer/company at send time. |

Deleting a company hides it and switches you to another; its customers/services/invoices remain in the database (not permanently destroyed) but are no longer reachable through the UI.

A **company switcher** dropdown appears on every screen once more than one company exists, so you always know (and can change) which company you're working in — this selection is remembered per-user between visits.

## Customers & Jobs

**Karks CRM → Customers.** Fields: Company Name, Contact Person (+ a Secondary Contact), Address, Phone, Email (+ Secondary Email), Notes, and Status (Active/Inactive — the Customers list and Company Overview default to showing Active only, with a toggle to show all).

**Jobs** are customers nested under a parent customer (e.g. separate properties or ongoing projects for the same client). A Job:
- Is added from the parent customer's own page ("Add Job").
- Displays as "Job Name (Parent Name)" wherever it's picked (e.g. the invoice customer dropdown).
- Rolls up into the parent's combined balance and revenue totals, while still being invoiced individually.

## Services

**Karks CRM → Services.** The billable line items available on invoices. Fields:

- **Pricing Type** — Hourly or Project-based. Purely a label on the invoice line (e.g. "hrs" vs. a flat quantity); both compute the line amount the same way (quantity × rate).
- **Rate**.
- **Taxable** — off by default. When on, this service's amount is included in the taxable base an invoice's tax rate is applied to; when off, its amount is never taxed regardless of the invoice's tax rate. Selecting a service on an invoice line pre-fills this checkbox from the service (still editable per line — see [Invoices](#invoices-line-items--payments)).
- **Active** — inactive services stop appearing in the "Service" dropdown on new invoice lines (existing invoice lines that already reference an inactive service are unaffected).

## Invoices, Line Items & Payments

**Karks CRM → Invoices.** An invoice belongs to one customer and has:

- **Invoice Type** — Month/Year, Web Hosting, Website Maintenance Package, or Other (with a custom label) — a display categorization, not billing logic.
- **Status** — Draft, Open, Partially Paid, Paid, or Void. Open/Partially Paid/Paid are derived automatically from recorded payments (see below) and can't be set by hand; only Draft and Void are manual (e.g. mark an invoice Void instead of deleting it to keep it in the record with a struck-through display).
- **Issue Date** / **Due Date**.
- **Tax Rate** — defaults from the company's Default Tax Rate, editable per invoice.
- **Line Items** — each references a Service (or "Custom" for a one-off line with its own description/type/rate), plus Quantity, Rate, and a per-line **Taxable** checkbox (defaults from the selected service; blank/Custom lines default to non-taxable).
- **Notes**.

**Totals** are always computed, never hand-entered: Subtotal = sum of every line's amount; Tax Amount = the invoice's tax rate applied only to the sum of lines marked Taxable; Total = Subtotal + Tax Amount.

**Recording a Payment** (date, amount, method, note) against an invoice is what advances its status: no payments → Open; partial → Partially Paid; paid in full → Paid. This happens automatically every time a payment is added or removed.

**Actions available once an invoice is saved:**
- **Download PDF Invoice** — streams a PDF (company logo/accent color/footer, line items, totals, payment options).
- **Email Invoice** — a modal composer pre-filled from the company's email template (merge tags resolved), with the same PDF attached automatically. Shows "Last emailed to X on Y" once sent at least once.
- **Delete Invoice** — with a confirmation prompt.

## CSV Import

Available on **Customers**, **Services**, and **Invoices** (which offers two: Invoices and Payments) — look for an "Import" button on each screen's list view. All four follow the same shape: upload a `.csv` file, map its columns to the plugin's fields (a best-guess mapping is pre-selected based on common column names — e.g. QuickBooks exports), then import. Every importer is safe to re-run: rows that would duplicate an existing record (by name or number) are skipped rather than creating a second copy.

| Importer | Required column(s) | Notes |
|---|---|---|
| **Customers** | Company Name | Optional address-block range mapping (handles QuickBooks-style multi-line "Bill To" blocks of varying length). Rows with a company name already in this company are skipped. |
| **Services** | Service Name | No natural "Hourly vs. Project" column in most exports, so you choose one **Type** that applies to every imported row on the mapping screen; edit individual services afterward if some should be the other type. |
| **Invoices** | Customer / Company Name, Issue Date, Amount (pre-tax) | Each row becomes one invoice with a single line item for the mapped amount. Matches the customer by name (skipped if no match). If you map a **Service** column, each row is matched to an existing service by name — **if no match is found, a new service is created automatically** using that name and the row's amount as its rate. Leave Invoice Number unmapped to auto-assign from the company's counter instead. Only Draft/Void are recognized from a mapped Status column; everything else imports as Open, then updates automatically once matching payments are imported. |
| **Payments** | Invoice Number, Amount, Payment Date | Matches an existing invoice by number (import invoices first). Recording the payment updates that invoice's status the same as adding one by hand. |

## Reports

Front-end only (`/crm/reports/`) — a per-company **Reports** tab with three views, each with a **CSV export** button:

- **Revenue** — a date-range filter (This Year/Last Year/All/Custom), a total for the selected range, a trailing-12-month bar chart (always the last 12 calendar months, independent of the filter above), and an itemized payments table (date, customer, invoice #, amount, method).
- **Customer Report** — pick a customer (rolled up with its Jobs), then the same date-range filter, revenue total, current outstanding balance, and itemized payments table for that customer alone.
- **Aging** (accounts receivable) — every open/partially-paid invoice bucketed by how many days past its due date it is: Current, 1-30, 31-60, 61-90, 90+. A snapshot, not date-range filtered.

The Reports overview page also links directly from the Company Overview hub's "Outstanding Balance" and "Revenue" stat cards.

## Company Export/Import

**Karks CRM → Companies** (wp-admin only) — each row has an **Export** link; an **Import Company** button sits next to "Add New".

- **Export** downloads a JSON file containing that company's profile, customers (including Jobs), services, invoices, line items, and payments. The company **logo is not included** — re-upload it manually on the new company's profile afterward if needed.
- **Import** uploads that JSON file back in, **always as a brand-new company** — it never merges into or overwrites an existing one, even if the name matches one already on this site (the name gets auto-suffixed on collision, e.g. "Acme Inc. (2)"). Every relationship (customer↔Job, line item↔service, payment↔invoice) is remapped to the new records created here. Invoice numbers are kept exactly as exported; the new company's invoice prefix/counter are copied from the export so future invoices don't collide with the imported history.
- **Both sites must be running the same Karks CRM version.** The export is stamped with the exporting site's plugin version, and import refuses with a clear error on any mismatch rather than risking a silent partial import.

Use this to migrate a company between sites (e.g. staging → production) or to duplicate one as a starting template.

## Appearance

**Karks CRM → Appearance** (wp-admin only; affects the front end's look). Four colors — Primary, Secondary, Accent, Highlight — control buttons, the active nav tab, table headers, stat-card numbers, and row-hover backgrounds across `/crm/`. Whatever you pick is automatically checked against WCAG 2.1 AA contrast (4.5:1): if a chosen color would produce unreadable text against its background, the plugin computes a corrected version for text specifically (darkening it, or flipping between black/white) without changing anything else — you never have to manually verify contrast yourself.

A **"Disable plugin styles on the front end"** checkbox turns off `front.css` (and the color variables) entirely, if you'd rather theme `/crm/` completely yourself. Dashicons and the plugin's JavaScript (media picker, invoice line-item editor, etc.) keep working regardless — this only affects the plugin's own layout/color CSS.

## Front-End Interface & Roles

Everything above the wp-admin equivalent of also exists at `/crm/` (a normal Page, auto-created on activation, containing the `[karks_crm]` shortcode) — same data, same actions, styled by [Appearance](#appearance) instead of wp-admin's own styling.

Access is gated by the `kcrm_manage` capability:
- **Administrators** have it automatically.
- The **`CRM Manager`** role (`kcrm_manager`) is created on activation with `kcrm_manage` plus `read` and `upload_files` (needed for the logo media picker) — assign this role to anyone who should manage companies/customers/invoices without needing broader wp-admin access.

A logged-in user without `kcrm_manage` sees a plain "you do not have permission" message on `/crm/`; a logged-out visitor sees a login form.
