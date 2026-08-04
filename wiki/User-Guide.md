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
| Invoice Footer | HTML editor (Text/Quicktags only — no Visual/WYSIWYG tab, to avoid it silently corrupting hand-typed HTML), shown at the bottom of every PDF invoice for this company (payment terms, bank details, etc.). Blank lines between paragraphs are turned into separate paragraphs automatically. |
| Accepted Payment Types / Payment Links | Checkboxes (Credit Card, ACH, PayPal, Venmo, Zelle, Check, Cash, Other) plus a repeatable label/URL list (e.g. a PayPal.me link) — both shown on invoices as ways to pay. Checking "Check" reveals a "Make Checks Payable To" field. |
| PDF Accent Color | Used for the invoice title/totals on this company's PDFs. Falls back to the global [Appearance](#appearance) Primary color if left blank. |
| Email Invoice Template | Same HTML/Quicktags editor as Invoice Footer, pre-fills the body when using "Email Invoice" on an invoice. Supports merge tags (shown on the edit screen) resolved against the invoice/customer/company at send time. |
| BCC Invoice Emails | Off by default. When on, BCCs a configured address on every "Email Invoice" send (e.g. to keep your own copy) — skipped automatically if that address already matches the To or a CC address, so you never get a duplicate copy. |

Deleting a company hides it and switches you to another; its customers/services/invoices remain in the database (not permanently destroyed) but are no longer reachable through the UI.

A **company switcher** dropdown appears on every screen once more than one company exists, so you always know (and can change) which company you're working in — this selection is remembered per-user between visits.

**Company Profile** (front end only, `/crm/companies/?view=overview`) is a summary hub for one company: quick-add buttons, stat cards (Active Customers, Open Invoices, Outstanding Balance, Revenue — each linking to the relevant screen or report), a Recent Actions feed, and a **Search Customers** box that jumps straight to the Customers list already filtered to what you typed.

## Customers & Jobs

**Karks CRM → Customers.** Fields: Company Name, Contact Person (+ a Secondary Contact), Address, Phone, Email (+ Secondary Email), a **Send Invoices To** Name/Email (optional — when set, this is who the Email Invoice form defaults to for this customer, instead of the primary Contact Person/Email), Notes, and Status (Active/Inactive — the Customers list defaults to showing Active only, with a toggle to show all). The Company Name and Status columns are sortable (click the column header). The Customers list also shows a company-wide **Open Balance** total.

**Jobs** are customers nested under a parent customer (e.g. separate properties or ongoing projects for the same client). A Job:
- Is added from the parent customer's own page ("Add Job").
- Displays as "Job Name (Parent Name)" wherever it's picked (e.g. the invoice customer dropdown).
- Rolls up into the parent's combined balance and revenue totals, while still being invoiced individually.

**Open Balance export.** From a customer's own profile page (or the [Customer Report](#reports)), **Export Open Balance PDF/CSV** downloads every open/partially-paid invoice for that customer (rolled up with its Jobs), oldest first — mirrors QuickBooks' "Customer Open Balance" report. $0.00 invoices are left out, the same way QuickBooks' own report excludes them.

## Services

**Karks CRM → Services.** The billable line items available on invoices. Fields:

- **Pricing Type** — Hourly or Project-based. Purely a label on the invoice line (e.g. "hrs" vs. a flat quantity); both compute the line amount the same way (quantity × rate).
- **Description** — optional. Selecting this service on an invoice line pre-fills the line's Description from here (still editable per line); if left blank, the line falls back to the service's Name instead.
- **Rate**.
- **Taxable** — off by default. When on, this service's amount is included in the taxable base an invoice's tax rate is applied to; when off, its amount is never taxed regardless of the invoice's tax rate. Selecting a service on an invoice line pre-fills this checkbox from the service (still editable per line — see [Invoices](#invoices-line-items--payments)).
- **Active** — inactive services stop appearing in the "Service" dropdown on new invoice lines (existing invoice lines that already reference an inactive service are unaffected).

## Invoices, Line Items & Payments

**Karks CRM → Invoices.** An invoice belongs to one customer and has:

- **Invoice Type** — a display categorization, not billing logic, picked from the user-managed list under [Invoice Types](#invoice-types).
- **Status** — Draft, Open, Partially Paid, Paid, or Void. Open/Partially Paid/Paid are derived automatically from recorded payments (see below) and can't be set by hand; only Draft and Void are manual (e.g. mark an invoice Void instead of deleting it to keep it in the record with a struck-through display).
- **Issue Date** / **Due Date**.
- **Tax Rate** — defaults from the company's Default Tax Rate, editable per invoice.
- **Line Items** — each references a Service (or "Custom" for a one-off line with its own description/type/rate), plus Quantity, Rate, and a per-line **Taxable** checkbox (defaults from the selected service; blank/Custom lines default to non-taxable).
- **Notes**.

The Invoices list (wp-admin and front end) can be sorted by Invoice #, Issue Date, Due Date, or Balance Due (click the column header), and filtered down to specific statuses via the checkboxes above the table -- leave all boxes checked (the default) to see everything. Every customer name shown on the list (and on an invoice's own edit screen) links directly to that customer's profile.

**Customers with Multiple Jobs** get their own section at the top of the list: a customer with two or more Jobs shows its own invoices together with every Job's invoices in one collapsible block (sorted by issue date, newest first), instead of those invoices being scattered through the regular sorted list below by whatever column you're sorting on. wp-admin also has a **Customer** filter dropdown to narrow the whole list down to one customer (+ its Jobs).

**Totals** are always computed, never hand-entered: Subtotal = sum of every line's amount; Tax Amount = the invoice's tax rate applied only to the sum of lines marked Taxable; Total = Subtotal + Tax Amount. A negative line (e.g. a discount — just give it a negative Rate) displays in parentheses, e.g. "(50.00)", rather than a plain minus sign, everywhere the amount is shown -- the edit screen, the Invoices list, the customer profile, and PDFs.

**Recording a Payment** (date, amount, method, note) against an invoice is what advances its status: no payments → Open; partial → Partially Paid; paid in full → Paid. This happens automatically every time a payment is added or removed.

**Actions available once an invoice is saved:**
- **Download PDF Invoice** — streams a PDF (company logo/accent color/footer, line items, totals, payment options).
- **Preview** — opens the invoice as a plain HTML page in a new tab, using the exact same layout as the PDF. A quick look without downloading anything; internal use only for now, not a link meant for sharing with customers.
- **Email Invoice** — a modal composer pre-filled from the company's email template (merge tags resolved), with the same PDF attached automatically. To/CC default from the customer's contact info (a customer's "Send Invoices To" Name/Email, if set, takes priority over the primary Contact Person/Email — see [Customers & Jobs](#customers--jobs)); the CC field itself starts blank, with one-click suggestions for any other email addresses on file for that customer. A company's [BCC Invoice Emails](#companies) setting, if enabled, is applied automatically. Shows "Last emailed to X (cc: ...; bcc: ...) on Y" once sent at least once.
- **Delete Invoice** — with a confirmation prompt.

## Invoice Types

**Karks CRM → Invoice Types** (wp-admin only — global across the whole site, not scoped to a company, so it isn't duplicated on the front end the way Companies/Customers/Services/Invoices are). A simple user-managed list: just a Label, shown in the "Invoice Type" dropdown everywhere an invoice is created or edited (wp-admin and front end alike).

- **Add/rename freely.** Renaming a type's label updates everywhere it's used immediately; the internal key behind it is generated once, from the label, at creation time and never changes afterward, so renaming never breaks an invoice that's already using it.
- **Deleting is safe.** An invoice that already used a deleted type keeps displaying whatever it had (the plugin falls back to showing the raw stored value) — deleting just removes it from the dropdown for new invoices going forward.
- **"Month/Year" is special** and always exists: selecting it on an invoice reveals a Month/Year picker instead of a plain label, and it's the one type every install starts with (see below). Nothing else about the list has special behavior baked in.

**New installs start with just "Month/Year."** Sites that already had invoice data before this feature existed (i.e. upgraded from an earlier plugin version) additionally keep the three types that used to be hardcoded — "Web Hosting," "Website Maintenance Package," and "Other" — seeded automatically with the exact same underlying keys, so nothing already using them changes.

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

- **Revenue** — a date-range filter (This Year/Last Year/All/Custom), a total for the selected range, a trailing-12-month bar chart (always the last 12 calendar months, independent of the filter above, each bar showing its month's total underneath it), and an itemized payments table (date, customer, invoice #, amount, method).
- **Customer Report** — pick a customer (rolled up with its Jobs), then the same date-range filter, revenue total, current outstanding balance, and itemized payments table for that customer alone. Also has its own **Export Open Balance PDF/CSV** buttons (see [Customers & Jobs](#customers--jobs)).
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
