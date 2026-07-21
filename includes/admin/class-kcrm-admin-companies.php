<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Companies extends KCRM_Admin_Base {

	const PAGE = 'karks-crm-companies';

	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_company' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] && isset( $_GET['page'] ) && self::PAGE === $_GET['page'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_company' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$data = array(
			'name'                => sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) ),
			'email'               => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'phone'               => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'address_street'      => sanitize_text_field( wp_unslash( $_POST['address_street'] ?? '' ) ),
			'address_city'        => sanitize_text_field( wp_unslash( $_POST['address_city'] ?? '' ) ),
			'address_state'       => sanitize_text_field( wp_unslash( $_POST['address_state'] ?? '' ) ),
			'address_postal_code' => sanitize_text_field( wp_unslash( $_POST['address_postal_code'] ?? '' ) ),
			'logo_attachment_id'  => isset( $_POST['logo_attachment_id'] ) ? absint( $_POST['logo_attachment_id'] ) : 0,
			'invoice_prefix'      => sanitize_text_field( wp_unslash( $_POST['invoice_prefix'] ?? 'INV-' ) ),
			'next_invoice_number' => isset( $_POST['next_invoice_number'] ) ? max( 1, absint( $_POST['next_invoice_number'] ) ) : 1,
			'default_tax_rate'    => isset( $_POST['default_tax_rate'] ) ? (float) $_POST['default_tax_rate'] : 0,
			'currency'            => sanitize_text_field( wp_unslash( $_POST['currency'] ?? 'USD' ) ),
			'invoice_footer'      => sanitize_textarea_field( wp_unslash( $_POST['invoice_footer'] ?? '' ) ),
		);

		if ( '' === $data['name'] ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		if ( $id ) {
			KCRM_Company::save( $id, $data );
		} else {
			$id = KCRM_Company::create( $data );
			update_user_meta( get_current_user_id(), KCRM_Context::META_KEY, $id );
		}

		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_company_' . $id );
		KCRM_Company::delete( $id );
		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'deleted' ) );
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';
		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Companies', 'karks-crm' ) . '</h1>';

		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=add' ) ), esc_html__( 'Add New', 'karks-crm' ) );
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
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $company->id ) ); ?>">
									<?php echo esc_html( $company->name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $company->email ); ?></td>
						<td><?php echo esc_html( $company->phone ); ?></td>
						<td><?php echo esc_html( $company->invoice_prefix ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $company->id ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=delete&id=' . $company->id ), 'kcrm_delete_company_' . $company->id ) ); ?>"
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
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
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
		});
		</script>
		<?php
	}
}
