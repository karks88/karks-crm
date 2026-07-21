<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Front_Companies extends KCRM_Companies_Controller {

	use KCRM_Front_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="kcrm-front-screen">';
		echo '<h2>' . esc_html__( 'Companies', 'karks-crm' ) . '</h2>';

		$this->company_switcher();

		if ( 'list' === $view ) {
			printf( '<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s">%s</a></div>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
		}

		$this->render_notice_from_query();

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} elseif ( 'overview' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
			$this->render_overview( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_overview( $id ) {
		$company = $id ? KCRM_Company::find( $id ) : null;

		if ( ! $company ) {
			echo '<p>' . esc_html__( 'Company not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$customers     = KCRM_Customer::top_level_for_company( $id );
		$all_invoices  = KCRM_Invoice::for_company( $id );
		$outstanding   = 0.0;
		$open_invoices = 0;

		foreach ( $all_invoices as $invoice ) {
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
		<h3><?php echo esc_html( $company->name ); ?></h3>
		<div class="kcrm-button-group">
			<a class="kcrm-button" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $id ) ) ); ?>"><?php esc_html_e( 'Edit Company', 'karks-crm' ); ?></a>
			<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'customers', array( 'view' => 'add' ) ) ) ); ?>"><?php esc_html_e( '+ Add Customer', 'karks-crm' ); ?></a>
			<a class="kcrm-button" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'add' ) ) ) ); ?>"><?php esc_html_e( '+ Add Invoice', 'karks-crm' ); ?></a>
			<a class="kcrm-button" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'services', array( 'view' => 'add' ) ) ) ); ?>"><?php esc_html_e( '+ Add Service', 'karks-crm' ); ?></a>
		</div>

		<div class="kcrm-dashboard-cards">
			<a class="kcrm-card" href="#kcrm-customers">
				<span class="kcrm-card-number"><?php echo esc_html( count( $customers ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Customers', 'karks-crm' ); ?></span>
			</a>
			<a class="kcrm-card" href="#kcrm-invoices">
				<span class="kcrm-card-number"><?php echo esc_html( count( $all_invoices ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Invoices', 'karks-crm' ); ?></span>
			</a>
			<a class="kcrm-card" href="#kcrm-invoices">
				<span class="kcrm-card-number"><?php echo esc_html( $open_invoices ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Open Invoices', 'karks-crm' ); ?></span>
			</a>
			<a class="kcrm-card" href="#kcrm-invoices">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $outstanding, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Outstanding Balance', 'karks-crm' ); ?></span>
			</a>
		</div>

		<h3 id="kcrm-customers"><?php esc_html_e( 'Customers', 'karks-crm' ); ?></h3>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Email', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Balance', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $customers ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No customers yet for this company.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $customers as $customer ) : ?>
					<?php
					$job_ids = wp_list_pluck( KCRM_Customer::jobs_for( $customer->id ), 'id' );
					$balance = KCRM_Customer::balance_for_ids( array_merge( array( $customer->id ), $job_ids ) );
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers', array( 'view' => 'edit', 'id' => $customer->id ) ) ); ?>">
									<?php echo esc_html( $customer->company_name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $customer->contact_person ); ?></td>
						<td><?php echo esc_html( $customer->email ); ?></td>
						<td><?php echo esc_html( $customer->phone ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php $this->render_overview_invoices( $id ); ?>
		<?php
	}

	private function render_overview_invoices( $company_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$show_all     = ! empty( $_GET['kcrm_invoice_filter'] ) && 'all' === sanitize_key( wp_unslash( $_GET['kcrm_invoice_filter'] ) );
		$statuses     = $show_all ? null : KCRM_Invoice::default_customer_statuses();
		$invoices     = KCRM_Invoice::for_company_with_statuses( $company_id, $statuses );
		$all_statuses = KCRM_Invoice::statuses();

		$toggle_url = $show_all ? remove_query_arg( 'kcrm_invoice_filter' ) : add_query_arg( 'kcrm_invoice_filter', 'all' );
		?>
		<h3 id="kcrm-invoices"><?php esc_html_e( 'Invoices', 'karks-crm' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Invoices with a status of Draft, Open, and Partially Paid are displayed by default.', 'karks-crm' ); ?></p>
		<p>
			<a href="<?php echo esc_url( $toggle_url ); ?>">
				<?php
				echo $show_all
					? esc_html__( 'Show default statuses only (Draft, Open, Partially Paid)', 'karks-crm' )
					: esc_html__( 'Show invoices with all statuses', 'karks-crm' );
				?>
			</a>
		</p>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Issue Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Total', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Balance Due', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $invoices ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No invoices found.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php
					$customer = KCRM_Customer::find( $invoice->customer_id );
					$balance  = KCRM_Invoice::balance_due( $invoice->id );
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>">
									<?php echo esc_html( $invoice->invoice_number ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $customer ? $customer->company_name : '' ); ?></td>
						<td><?php echo esc_html( $invoice->issue_date ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $invoice->total, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( $all_statuses[ $invoice->status ] ?? $invoice->status ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_list() {
		$companies = KCRM_Company::all_ordered();
		?>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Email', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Invoice Prefix', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $companies ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No companies yet. Add your first one to get started.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $companies as $company ) : ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'overview', 'id' => $company->id ) ) ); ?>">
									<?php echo esc_html( $company->name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $company->email ); ?></td>
						<td><?php echo esc_html( $company->phone ); ?></td>
						<td><?php echo esc_html( $company->invoice_prefix ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $company->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $company->id ) ), 'kcrm_delete_company_' . $company->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this company and switch to another? Customers, services, and invoices under it will remain in the database but hidden.', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_form( $view ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$company = $id ? KCRM_Company::find( $id ) : null;

		if ( 'edit' === $view && ! $company ) {
			echo '<p>' . esc_html__( 'Company not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$v = function ( $field, $default = '' ) use ( $company ) {
			return $company ? $company->$field : $default;
		};
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_save_company' ); ?>
			<input type="hidden" name="kcrm_action" value="save_company">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<p>
				<label for="name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label>
				<input type="text" name="name" id="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>" required>
			</p>
			<p>
				<label for="email"><?php esc_html_e( 'Email', 'karks-crm' ); ?></label>
				<input type="email" name="email" id="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>">
			</p>
			<p>
				<label for="phone"><?php esc_html_e( 'Phone', 'karks-crm' ); ?></label>
				<input type="text" name="phone" id="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>">
			</p>
			<p>
				<label for="address_street"><?php esc_html_e( 'Street Address', 'karks-crm' ); ?></label>
				<input type="text" name="address_street" id="address_street" value="<?php echo esc_attr( $v( 'address_street' ) ); ?>">
			</p>
			<p>
				<label for="address_city"><?php esc_html_e( 'City', 'karks-crm' ); ?></label>
				<input type="text" name="address_city" id="address_city" value="<?php echo esc_attr( $v( 'address_city' ) ); ?>">
			</p>
			<p>
				<label for="address_state"><?php esc_html_e( 'State', 'karks-crm' ); ?></label>
				<input type="text" name="address_state" id="address_state" value="<?php echo esc_attr( $v( 'address_state' ) ); ?>">
			</p>
			<p>
				<label for="address_postal_code"><?php esc_html_e( 'Postal Code', 'karks-crm' ); ?></label>
				<input type="text" name="address_postal_code" id="address_postal_code" value="<?php echo esc_attr( $v( 'address_postal_code' ) ); ?>">
			</p>
			<p>
				<label for="logo_attachment_id"><?php esc_html_e( 'Logo', 'karks-crm' ); ?></label>
				<?php $logo_id = $company ? (int) $company->logo_attachment_id : 0; ?>
				<span id="kcrm-logo-preview" style="display:block;margin-bottom:8px;">
					<?php if ( $logo_id ) : ?>
						<?php echo wp_get_attachment_image( $logo_id, array( 150, 150 ) ); ?>
					<?php endif; ?>
				</span>
				<input type="hidden" name="logo_attachment_id" id="logo_attachment_id" value="<?php echo esc_attr( $logo_id ); ?>">
				<button type="button" class="kcrm-button" id="kcrm-select-logo"><?php esc_html_e( 'Select Logo', 'karks-crm' ); ?></button>
				<button type="button" class="kcrm-button" id="kcrm-remove-logo" style="<?php echo $logo_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'karks-crm' ); ?></button>
				<br><small><?php esc_html_e( 'Appears on PDF invoices for this company.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="invoice_prefix"><?php esc_html_e( 'Invoice Number Prefix', 'karks-crm' ); ?></label>
				<input type="text" name="invoice_prefix" id="invoice_prefix" value="<?php echo esc_attr( $v( 'invoice_prefix', 'INV-' ) ); ?>">
			</p>
			<p>
				<label for="next_invoice_number"><?php esc_html_e( 'Next Invoice Number', 'karks-crm' ); ?></label>
				<input type="number" step="1" min="1" name="next_invoice_number" id="next_invoice_number" value="<?php echo esc_attr( $v( 'next_invoice_number', '1' ) ); ?>">
				<br><small><?php esc_html_e( 'The number that will be assigned to the next invoice created for this company (combined with the prefix above). Set this once to choose a starting number; it then advances automatically.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="default_tax_rate"><?php esc_html_e( 'Default Tax Rate (%)', 'karks-crm' ); ?></label>
				<input type="number" step="0.001" min="0" name="default_tax_rate" id="default_tax_rate" value="<?php echo esc_attr( $v( 'default_tax_rate', '0' ) ); ?>">
			</p>
			<p>
				<label for="currency"><?php esc_html_e( 'Currency Symbol', 'karks-crm' ); ?></label>
				<input type="text" name="currency" id="currency" value="<?php echo esc_attr( $v( 'currency', 'USD' ) ); ?>" maxlength="10">
			</p>
			<p>
				<label for="invoice_footer"><?php esc_html_e( 'Invoice Footer', 'karks-crm' ); ?></label>
				<textarea rows="4" name="invoice_footer" id="invoice_footer"><?php echo $company ? esc_textarea( $company->invoice_footer ) : ''; ?></textarea>
				<br><small><?php esc_html_e( 'Custom text shown at the bottom of every PDF invoice for this company (e.g. payment terms, bank details, a thank-you note).', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label><?php esc_html_e( 'Accepted Payment Types', 'karks-crm' ); ?></label>
				<?php $kcrm_accepted_types = KCRM_Company::accepted_payment_type_keys( $company ); ?>
				<?php foreach ( KCRM_Company::payment_types() as $kcrm_type_key => $kcrm_type_label ) : ?>
					<label style="display:inline-block;font-weight:normal;margin:0 16px 8px 0;">
						<input type="checkbox" name="accepted_payment_types[]" class="kcrm-payment-type-checkbox" data-type="<?php echo esc_attr( $kcrm_type_key ); ?>" value="<?php echo esc_attr( $kcrm_type_key ); ?>" <?php checked( in_array( $kcrm_type_key, $kcrm_accepted_types, true ) ); ?>>
						<?php echo esc_html( $kcrm_type_label ); ?>
					</label>
				<?php endforeach; ?>
			</p>
			<p id="kcrm-check-payable-to-row" style="<?php echo in_array( 'check', $kcrm_accepted_types, true ) ? '' : 'display:none;'; ?>">
				<label for="check_payable_to"><?php esc_html_e( 'Make Checks Payable To', 'karks-crm' ); ?></label>
				<input type="text" name="check_payable_to" id="check_payable_to" value="<?php echo esc_attr( $company ? $company->check_payable_to : '' ); ?>">
				<br><small><?php esc_html_e( 'Printed in larger type on PDF invoices so checks aren\'t mistakenly made out to the wrong name.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label><?php esc_html_e( 'Payment Links', 'karks-crm' ); ?></label>
				<table class="kcrm-front-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'karks-crm' ); ?></th>
							<th><?php esc_html_e( 'URL', 'karks-crm' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="kcrm-payment-links-body">
						<?php
						$kcrm_payment_links = KCRM_Company::payment_links( $company );
						if ( empty( $kcrm_payment_links ) ) {
							$kcrm_payment_links = array( array( 'label' => '', 'url' => '' ) );
						}
						?>
						<?php foreach ( $kcrm_payment_links as $kcrm_link ) : ?>
							<tr class="kcrm-payment-link-row">
								<td><input type="text" name="payment_link_label[]" value="<?php echo esc_attr( $kcrm_link['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. PayPal', 'karks-crm' ); ?>"></td>
								<td><input type="url" name="payment_link_url[]" value="<?php echo esc_attr( $kcrm_link['url'] ?? '' ); ?>" placeholder="https://"></td>
								<td><button type="button" class="kcrm-button kcrm-remove-payment-link">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="kcrm-button" id="kcrm-add-payment-link"><?php esc_html_e( '+ Add Link', 'karks-crm' ); ?></button>
				<br><small><?php esc_html_e( 'Shown on invoices as quick ways for customers to pay online (e.g. a PayPal.me link, Stripe payment link).', 'karks-crm' ); ?></small>
			</p>

			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo esc_html( $id ? __( 'Update Company', 'karks-crm' ) : __( 'Add Company', 'karks-crm' ) ); ?></button></p>
		</form>
		<script>
		jQuery(function($){
			$('#kcrm-select-logo').on('click', function(e){
				e.preventDefault();
				var frame = wp.media({ title: '<?php echo esc_js( __( 'Select Company Logo', 'karks-crm' ) ); ?>', multiple: false, library: { type: 'image' } });
				frame.on('select', function(){
					var attachment = frame.state().get('selection').first().toJSON();
					$('#logo_attachment_id').val(attachment.id);
					$('#kcrm-logo-preview').html('<img src="' + attachment.url + '" style="max-width:150px;max-height:150px;">');
					$('#kcrm-remove-logo').show();
				});
				frame.open();
			});
			$('#kcrm-remove-logo').on('click', function(e){
				e.preventDefault();
				$('#logo_attachment_id').val('');
				$('#kcrm-logo-preview').empty();
				$(this).hide();
			});
			$('.kcrm-payment-type-checkbox[data-type="check"]').on('change', function(){
				$('#kcrm-check-payable-to-row').toggle(this.checked);
			});
		});
		</script>
		<?php
	}
}
