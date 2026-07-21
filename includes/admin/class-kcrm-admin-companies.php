<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Companies extends KCRM_Companies_Controller {

	use KCRM_Admin_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Companies', 'karks-crm' ) . '</h1>';

		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
		}
		echo '<hr class="wp-header-end">';

		$this->render_notice_from_query();

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_list() {
		$companies = KCRM_Company::all_ordered();
		?>
		<table class="wp-list-table widefat fixed striped">
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
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $company->id ) ) ); ?>">
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
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
			<?php wp_nonce_field( 'kcrm_save_company' ); ?>
			<input type="hidden" name="kcrm_action" value="save_company">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="name" id="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="email"><?php esc_html_e( 'Email', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="email" id="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="phone"><?php esc_html_e( 'Phone', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="phone" id="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_street"><?php esc_html_e( 'Street Address', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_street" id="address_street" value="<?php echo esc_attr( $v( 'address_street' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_city"><?php esc_html_e( 'City', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_city" id="address_city" value="<?php echo esc_attr( $v( 'address_city' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_state"><?php esc_html_e( 'State', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_state" id="address_state" value="<?php echo esc_attr( $v( 'address_state' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_postal_code"><?php esc_html_e( 'Postal Code', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_postal_code" id="address_postal_code" value="<?php echo esc_attr( $v( 'address_postal_code' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="logo_attachment_id"><?php esc_html_e( 'Logo', 'karks-crm' ); ?></label></th>
					<td>
						<?php $logo_id = $company ? (int) $company->logo_attachment_id : 0; ?>
						<div id="kcrm-logo-preview" style="margin-bottom:8px;">
							<?php if ( $logo_id ) : ?>
								<?php echo wp_get_attachment_image( $logo_id, array( 150, 150 ) ); ?>
							<?php endif; ?>
						</div>
						<input type="hidden" name="logo_attachment_id" id="logo_attachment_id" value="<?php echo esc_attr( $logo_id ); ?>">
						<button type="button" class="button" id="kcrm-select-logo"><?php esc_html_e( 'Select Logo', 'karks-crm' ); ?></button>
						<button type="button" class="button" id="kcrm-remove-logo" style="<?php echo $logo_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'karks-crm' ); ?></button>
						<p class="description"><?php esc_html_e( 'Appears on PDF invoices for this company.', 'karks-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="invoice_prefix"><?php esc_html_e( 'Invoice Number Prefix', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="invoice_prefix" id="invoice_prefix" value="<?php echo esc_attr( $v( 'invoice_prefix', 'INV-' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="next_invoice_number"><?php esc_html_e( 'Next Invoice Number', 'karks-crm' ); ?></label></th>
					<td>
						<input type="number" step="1" min="1" name="next_invoice_number" id="next_invoice_number" value="<?php echo esc_attr( $v( 'next_invoice_number', '1' ) ); ?>">
						<p class="description"><?php esc_html_e( 'The number that will be assigned to the next invoice created for this company (combined with the prefix above). Set this once to choose a starting number; it then advances automatically.', 'karks-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="default_tax_rate"><?php esc_html_e( 'Default Tax Rate (%)', 'karks-crm' ); ?></label></th>
					<td><input type="number" step="0.001" min="0" name="default_tax_rate" id="default_tax_rate" value="<?php echo esc_attr( $v( 'default_tax_rate', '0' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="currency"><?php esc_html_e( 'Currency Symbol', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="small-text" name="currency" id="currency" value="<?php echo esc_attr( $v( 'currency', 'USD' ) ); ?>" maxlength="10"></td>
				</tr>
				<tr>
					<th><label for="invoice_footer"><?php esc_html_e( 'Invoice Footer', 'karks-crm' ); ?></label></th>
					<td>
						<textarea class="large-text" rows="4" name="invoice_footer" id="invoice_footer"><?php echo $company ? esc_textarea( $company->invoice_footer ) : ''; ?></textarea>
						<p class="description"><?php esc_html_e( 'Custom text shown at the bottom of every PDF invoice for this company (e.g. payment terms, bank details, a thank-you note).', 'karks-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Accepted Payment Types', 'karks-crm' ); ?></th>
					<td>
						<?php $kcrm_accepted_types = KCRM_Company::accepted_payment_type_keys( $company ); ?>
						<?php foreach ( KCRM_Company::payment_types() as $kcrm_type_key => $kcrm_type_label ) : ?>
							<label style="display:inline-block;margin:0 16px 8px 0;">
								<input type="checkbox" name="accepted_payment_types[]" class="kcrm-payment-type-checkbox" data-type="<?php echo esc_attr( $kcrm_type_key ); ?>" value="<?php echo esc_attr( $kcrm_type_key ); ?>" <?php checked( in_array( $kcrm_type_key, $kcrm_accepted_types, true ) ); ?>>
								<?php echo esc_html( $kcrm_type_label ); ?>
							</label>
						<?php endforeach; ?>
					</td>
				</tr>
				<tr id="kcrm-check-payable-to-row" style="<?php echo in_array( 'check', $kcrm_accepted_types, true ) ? '' : 'display:none;'; ?>">
					<th><label for="check_payable_to"><?php esc_html_e( 'Make Checks Payable To', 'karks-crm' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" name="check_payable_to" id="check_payable_to" value="<?php echo esc_attr( $company ? $company->check_payable_to : '' ); ?>">
						<p class="description"><?php esc_html_e( 'Printed in larger type on PDF invoices so checks aren\'t mistakenly made out to the wrong name.', 'karks-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Payment Links', 'karks-crm' ); ?></th>
					<td>
						<table class="widefat" style="max-width:600px;">
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
										<td><input type="url" name="payment_link_url[]" value="<?php echo esc_attr( $kcrm_link['url'] ?? '' ); ?>" placeholder="https://" class="regular-text"></td>
										<td><button type="button" class="button kcrm-remove-payment-link">&times;</button></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
						<p><button type="button" class="button" id="kcrm-add-payment-link"><?php esc_html_e( '+ Add Link', 'karks-crm' ); ?></button></p>
						<p class="description"><?php esc_html_e( 'Shown on invoices as quick ways for customers to pay online (e.g. a PayPal.me link, Stripe payment link).', 'karks-crm' ); ?></p>
					</td>
				</tr>
			</table>

			<?php submit_button( $id ? __( 'Update Company', 'karks-crm' ) : __( 'Add Company', 'karks-crm' ) ); ?>
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
