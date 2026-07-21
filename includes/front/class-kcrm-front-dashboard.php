<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end landing screen (bare /crm/, no endpoint matched). Mirrors
 * KCRM_Admin_Dashboard; kept as its own small class since there's no
 * save/delete logic worth sharing via a controller.
 */
class KCRM_Front_Dashboard extends KCRM_Controller_Base {

	use KCRM_Front_Screen_Trait;

	const ENDPOINT = '';

	public function render() {
		echo '<div class="kcrm-front-screen">';
		echo '<h2>' . esc_html__( 'Welcome!', 'karks-crm' ) . '</h2>';
		echo '<p>' . esc_html__( 'Please choose or add a company below:', 'karks-crm' ) . '</p>';

		//$this->company_switcher();
		$this->render_notice_from_query();

		printf(
			'<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s">%s</a></div>',
			esc_url( KCRM_Front::endpoint_url( 'companies', array( 'view' => 'add' ) ) ),
			esc_html__( 'Add a Company', 'karks-crm' )
		);

		$companies = KCRM_Company::all_ordered();

		if ( empty( $companies ) ) {
			echo '<p>' . esc_html__( 'Welcome! Start by adding your first company.', 'karks-crm' ) . '</p>';
			echo '</div>';
			return;
		}
		?>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Company', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Customers', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Open Invoices', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Outstanding Balance', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $companies as $company ) : ?>
					<?php
					$customer_count = count( KCRM_Customer::for_company( $company->id ) );
					$invoices       = KCRM_Invoice::for_company( $company->id );
					$outstanding    = 0.0;
					$open_invoices  = 0;

					foreach ( $invoices as $invoice ) {
						if ( KCRM_Invoice::STATUS_VOID === $invoice->status ) {
							continue;
						}
						$due = KCRM_Invoice::balance_due( $invoice->id );
						if ( $due > 0.004 ) {
							$outstanding += $due;
							$open_invoices++;
						}
					}
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'companies', array( 'view' => 'overview', 'id' => $company->id ) ) ); ?>">
									<?php echo esc_html( $company->name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $customer_count ); ?></td>
						<td><?php echo esc_html( $open_invoices ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $outstanding, 2 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		echo '</div>';
	}
}
