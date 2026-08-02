<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Dashboard extends KCRM_Controller_Base {

	use KCRM_Admin_Screen_Trait;

	const PAGE = 'karks-crm';

	public function render() {
		echo '<div class="wrap kcrm-wrap"><h1>' . esc_html__( 'Karks CRM', 'karks-crm' ) . '</h1>';

		$this->company_switcher();
		$this->render_notice_from_query();

		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			echo '<p>' . esc_html__( 'Welcome! Start by adding your first company.', 'karks-crm' ) . '</p>';
			printf(
				'<p><a class="button button-primary" href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=karks-crm-companies&view=add' ) ),
				esc_html__( 'Add a Company', 'karks-crm' )
			);
			echo '</div>';
			return;
		}

		$company        = KCRM_Company::find( $company_id );
		$customer_count = KCRM_Customer::count_where( array( 'company_id' => $company_id ) );
		$invoices       = KCRM_Invoice::for_company( $company_id );
		$balances       = KCRM_Invoice::balances_for( $invoices );
		$outstanding    = 0.0;
		$open_invoices  = 0;

		foreach ( $invoices as $invoice ) {
			if ( KCRM_Invoice::STATUS_VOID === $invoice->status ) {
				continue;
			}
			$due = $balances[ (int) $invoice->id ];
			if ( $due > 0.004 ) {
				$outstanding += $due;
				$open_invoices++;
			}
		}
		?>
		<h2><?php echo esc_html( $company->name ); ?></h2>
		<div class="kcrm-dashboard-cards">
			<a class="kcrm-card" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-customers' ) ); ?>">
				<span class="kcrm-card-number"><?php echo esc_html( $customer_count ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Customers', 'karks-crm' ); ?></span>
			</a>
			<a class="kcrm-card" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices' ) ); ?>">
				<span class="kcrm-card-number"><?php echo esc_html( count( $invoices ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Invoices', 'karks-crm' ); ?></span>
			</a>
			<a class="kcrm-card" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices' ) ); ?>">
				<span class="kcrm-card-number"><?php echo esc_html( $open_invoices ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Open Invoices', 'karks-crm' ); ?></span>
			</a>
			<a class="kcrm-card" href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices' ) ); ?>">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $outstanding, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Outstanding Balance', 'karks-crm' ); ?></span>
			</a>
		</div>

		<h3><?php esc_html_e( 'Recent Invoices', 'karks-crm' ); ?></h3>
		<?php
		$recent          = array_slice( $invoices, 0, 10 );
		$recent_customers = KCRM_Customer::find_many( wp_list_pluck( $recent, 'customer_id' ) );
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Total', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $recent ) ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'No invoices yet.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $recent as $invoice ) : ?>
					<?php $customer = $recent_customers[ (int) $invoice->customer_id ] ?? null; ?>
					<tr>
						<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=edit&id=' . $invoice->id ) ); ?>"><?php echo esc_html( $invoice->invoice_number ); ?></a></td>
						<td><?php echo esc_html( $customer ? $customer->company_name : '' ); ?></td>
						<td><?php echo esc_html( KCRM_Invoice::format_money( (float) $invoice->total ) ); ?></td>
						<td><?php echo esc_html( KCRM_Invoice::statuses()[ $invoice->status ] ?? $invoice->status ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		echo '</div>';
	}
}
