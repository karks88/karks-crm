<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end "Tools" screen: the multi-company overview (with an "Add a
 * Company" button) that used to be the bare /crm/ landing page. Moved to
 * its own nav tab, at the end of the nav, once the bare endpoint started
 * redirecting straight to the current company's Profile instead -- this is
 * where that "add a new company" capability lives now.
 */
class KCRM_Front_Tools extends KCRM_Controller_Base {

	use KCRM_Front_Screen_Trait;

	const ENDPOINT = 'tools';

	public function render() {
		echo '<div class="kcrm-front-screen">';
		echo '<h2>' . esc_html__( 'Tools', 'karks-crm' ) . '</h2>';

		$this->render_notice_from_query();

		$companies = KCRM_Company::all_ordered();

		echo '<h3>' . esc_html__( 'Companies', 'karks-crm' ) . '</h3>';

		if ( empty( $companies ) ) {
			echo '<p>' . esc_html__( 'Start by adding your first company.', 'karks-crm' ) . '</p>';
		} else {
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
						$customer_count = KCRM_Customer::count_where( array( 'company_id' => $company->id ) );
						$non_void       = array( KCRM_Invoice::STATUS_DRAFT, KCRM_Invoice::STATUS_OPEN, KCRM_Invoice::STATUS_PARTIAL, KCRM_Invoice::STATUS_PAID );
						$invoices       = KCRM_Invoice::for_company_with_statuses( $company->id, $non_void );
						$outstanding    = 0.0;
						$open_invoices  = 0;

						foreach ( KCRM_Invoice::balances_for( $invoices ) as $due ) {
							if ( $due > 0.004 ) {
								$outstanding += $due;
								$open_invoices++;
							}
						}
						?>
						<tr>
							<td>
								<strong>
									<a href="<?php echo esc_url( KCRM_Context::switch_company_url( $company->id, KCRM_Front::endpoint_url( 'companies', array( 'view' => 'overview', 'id' => $company->id ) ) ) ); ?>">
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
		}

		echo '</div>';

		printf(
			'<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s">%s</a></div>',
			esc_url( KCRM_Front::endpoint_url( 'companies', array( 'view' => 'add' ) ) ),
			sprintf(
				/* translators: %s: dashicon HTML */
				esc_html__( '%s Add a Company', 'karks-crm' ),
				'<span class="dashicons dashicons-plus-alt2"></span>'
			)
		);
	}
}
