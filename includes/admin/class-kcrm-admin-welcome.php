<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Getting Started" screen: a walkthrough of the plugin's basic setup
 * steps in the order they actually need to happen (a company has to
 * exist before anything else can be created under it). Standalone like
 * KCRM_Admin_Appearance -- purely informational, nothing to save, so it
 * has no controller/business logic to share and no handle_actions().
 */
class KCRM_Admin_Welcome {

	const PAGE = 'karks-crm-welcome';

	public function render() {
		?>
		<div class="wrap kcrm-wrap">
			<h1><?php esc_html_e( 'Getting Started with Karks CRM', 'karks-crm' ); ?></h1>
			<p><?php esc_html_e( 'Karks CRM manages customers, services, and invoices across one or more companies. Set things up in this order the first time -- each step needs the one before it.', 'karks-crm' ); ?></p>

			<h2><?php esc_html_e( '1. Add a Company', 'karks-crm' ); ?></h2>
			<p><?php esc_html_e( 'Everything else in Karks CRM -- customers, services, invoices -- belongs to a company. If you only do work under one business name, you still need to add that one company first. If you run several (e.g. a main business plus side projects), add each one here; a company switcher will appear once you have more than one.', 'karks-crm' ); ?></p>
			<p><?php esc_html_e( "While you're there, also worth setting: invoice number prefix, default tax rate, currency symbol, accepted payment types, and (optionally) a logo and PDF accent color -- these all carry through to every invoice you create for that company.", 'karks-crm' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-companies&view=add' ) ); ?>"><?php esc_html_e( 'Add a Company', 'karks-crm' ); ?></a>
			</p>

			<h2><?php esc_html_e( '2. Add Services', 'karks-crm' ); ?></h2>
			<p><?php esc_html_e( "Services are the things you bill for -- an hourly rate, a flat project fee, monthly hosting, whatever you charge customers for. Add the ones you use regularly now, so they're ready to pick from a dropdown when you build an invoice later instead of typing the same rate in by hand every time.", 'karks-crm' ); ?></p>
			<p><?php esc_html_e( 'Each service is Hourly or Project-based, has a rate, and can be marked Taxable if it should have your company\'s tax rate applied to it on invoices (off by default). You can also import a list of services from a CSV export (e.g. QuickBooks) instead of adding them one at a time.', 'karks-crm' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-services&view=add' ) ); ?>"><?php esc_html_e( 'Add a Service', 'karks-crm' ); ?></a>
			</p>

			<h2><?php esc_html_e( '3. Add a Customer', 'karks-crm' ); ?></h2>
			<p><?php esc_html_e( "Add the people or businesses you invoice. Each customer can optionally have \"Jobs\" nested under it -- separate sub-records for, say, different properties or projects for the same client -- which show up rolled together on that customer's balance and revenue totals.", 'karks-crm' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-customers&view=add' ) ); ?>"><?php esc_html_e( 'Add a Customer', 'karks-crm' ); ?></a>
			</p>

			<h2><?php esc_html_e( '4. Create, Download, and Send an Invoice', 'karks-crm' ); ?></h2>
			<p><?php esc_html_e( "With a company, at least one service, and a customer in place, you're ready to invoice. Pick the customer, add a line for each service (quantity/hours × rate, computed automatically), and save -- the invoice number is assigned for you based on the company's prefix and counter.", 'karks-crm' ); ?></p>
			<p><?php esc_html_e( 'Once an invoice is saved, two buttons appear on it:', 'karks-crm' ); ?></p>
			<ul style="list-style: disc; margin-left: 2em;">
				<li><?php esc_html_e( '"Download PDF Invoice" -- generates a PDF using the company\'s logo, accent color, and invoice footer.', 'karks-crm' ); ?></li>
				<li><?php esc_html_e( '"Email Invoice" -- opens a composer pre-filled from the company\'s email template, with the same PDF attached automatically.', 'karks-crm' ); ?></li>
			</ul>
			<p><?php esc_html_e( "Status (Open/Partially Paid/Paid) updates on its own as you record payments against the invoice -- you don't set it by hand except to mark one Draft or Void.", 'karks-crm' ); ?></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add' ) ); ?>"><?php esc_html_e( 'Create an Invoice', 'karks-crm' ); ?></a>
			</p>

			<h2><?php esc_html_e( 'Also Available', 'karks-crm' ); ?></h2>
			<ul style="list-style: disc; margin-left: 2em;">
				<li>
					<?php
					printf(
						/* translators: %s: link to the front-end CRM page. */
						esc_html__( 'A front-end interface at %s -- everything above, without needing wp-admin access, for anyone given the "CRM Manager" role.', 'karks-crm' ),
						'<code>/crm/</code>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Reports (front-end only, under the Reports tab): Revenue with a 12-month chart, a per-customer report, and an Aging (accounts receivable) report -- each with CSV export.', 'karks-crm' ); ?></li>
				<li><?php esc_html_e( 'CSV import for customers, services, invoices, and payments -- look for an "Import" button on each screen\'s list view.', 'karks-crm' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: link to the Appearance settings screen. */
						esc_html__( '%s -- customize the front end\'s 4 theme colors (contrast-checked automatically) or turn its stylesheet off entirely to use your own.', 'karks-crm' ),
						'<a href="' . esc_url( admin_url( 'admin.php?page=' . KCRM_Admin_Appearance::PAGE ) ) . '">' . esc_html__( 'Appearance settings', 'karks-crm' ) . '</a>'
					);
					?>
				</li>
				<li><?php esc_html_e( 'Export a full company (profile, customers, services, invoices, payments) as a JSON file from the Companies list, and import it into another site running the same plugin version -- handy for migrating between sites or duplicating a company as a template.', 'karks-crm' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
