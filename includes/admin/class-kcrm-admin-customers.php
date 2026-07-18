<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Customers extends KCRM_Admin_Base {

	const PAGE = 'karks-crm-customers';

	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_POST['kcrm_action'] ) && 'save_customer' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$this->delete();
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_customer' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'no_company' ) );
		}

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? KCRM_Customer::STATUS_ACTIVE ) );
		if ( ! array_key_exists( $status, KCRM_Customer::statuses() ) ) {
			$status = KCRM_Customer::STATUS_ACTIVE;
		}

		$data = array(
			'company_id'               => $company_id,
			'company_name'             => sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) ),
			'contact_person'           => sanitize_text_field( wp_unslash( $_POST['contact_person'] ?? '' ) ),
			'secondary_contact_person' => sanitize_text_field( wp_unslash( $_POST['secondary_contact_person'] ?? '' ) ),
			'address_street'           => sanitize_text_field( wp_unslash( $_POST['address_street'] ?? '' ) ),
			'address_city'             => sanitize_text_field( wp_unslash( $_POST['address_city'] ?? '' ) ),
			'address_state'            => sanitize_text_field( wp_unslash( $_POST['address_state'] ?? '' ) ),
			'address_postal_code'      => sanitize_text_field( wp_unslash( $_POST['address_postal_code'] ?? '' ) ),
			'phone'                    => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'email'                    => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'secondary_email'          => sanitize_email( wp_unslash( $_POST['secondary_email'] ?? '' ) ),
			'notes'                    => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'status'                   => $status,
		);

		if ( '' === $data['company_name'] ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		if ( $id ) {
			unset( $data['company_id'] );
			KCRM_Customer::save( $id, $data );
		} else {
			$id = KCRM_Customer::create( $data );
		}

		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'saved' ) );
	}

	private function delete() {
		check_admin_referer( 'kcrm_delete_customer_' . absint( $_GET['id'] ) );
		KCRM_Customer::delete( absint( $_GET['id'] ) );
		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'deleted' ) );
	}

	public function render() {
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Customers', 'karks-crm' ) . '</h1>';
		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=add' ) ), esc_html__( 'Add New', 'karks-crm' ) );
		}
		echo '<hr class="wp-header-end">';

		$this->company_switcher( self::PAGE );
		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Karks CRM → Companies.', 'karks-crm' ) . '</p></div>';
			return;
		}

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_list() {
		$orderby = isset( $_GET['orderby'] ) && 'status' === $_GET['orderby'] ? 'status' : 'company_name';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( $_GET['order'] ) ? 'DESC' : 'ASC';

		$order_by  = 'status' === $orderby ? "status $order, company_name ASC" : "company_name $order";
		$customers = KCRM_Customer::for_company( $this->current_company_id(), $order_by );
		$statuses  = KCRM_Customer::statuses();

		$status_sort_url = add_query_arg(
			array(
				'page'    => self::PAGE,
				'orderby' => 'status',
				'order'   => ( 'status' === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
			),
			admin_url( 'admin.php' )
		);
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Email', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'karks-crm' ); ?></th>
					<th>
						<a href="<?php echo esc_url( $status_sort_url ); ?>">
							<?php esc_html_e( 'Status', 'karks-crm' ); ?>
							<?php if ( 'status' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<th><?php esc_html_e( 'Balance', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $customers ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No customers yet for this company.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $customers as $customer ) : ?>
					<?php $balance = KCRM_Customer::balance( $customer->id ); ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $customer->id ) ); ?>">
									<?php echo esc_html( $customer->company_name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $customer->contact_person ); ?></td>
						<td><?php echo esc_html( $customer->email ); ?></td>
						<td><?php echo esc_html( $customer->phone ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $customer->status ); ?>"><?php echo esc_html( $statuses[ $customer->status ] ?? $customer->status ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $customer->id ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $customer->id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=delete&id=' . $customer->id ), 'kcrm_delete_customer_' . $customer->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this customer?', 'karks-crm' ) ); ?>');">
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
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$customer = $id ? KCRM_Customer::find( $id ) : null;

		if ( 'edit' === $view && ! $customer ) {
			echo '<p>' . esc_html__( 'Customer not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$v = function ( $field, $default = '' ) use ( $customer ) {
			return $customer ? esc_attr( $customer->$field ) : $default;
		};
		$notes = $customer ? esc_textarea( $customer->notes ) : '';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<?php wp_nonce_field( 'kcrm_save_customer' ); ?>
			<input type="hidden" name="kcrm_action" value="save_customer">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="status"><?php esc_html_e( 'Status', 'karks-crm' ); ?></label></th>
					<td>
						<select name="status" id="status">
							<?php foreach ( KCRM_Customer::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $customer ? $customer->status : KCRM_Customer::STATUS_ACTIVE, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="company_name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="company_name" id="company_name" value="<?php echo $v( 'company_name' ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="contact_person"><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="contact_person" id="contact_person" value="<?php echo $v( 'contact_person' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="secondary_contact_person"><?php esc_html_e( 'Secondary Contact Person', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="secondary_contact_person" id="secondary_contact_person" value="<?php echo $v( 'secondary_contact_person' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_street"><?php esc_html_e( 'Street Address', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_street" id="address_street" value="<?php echo $v( 'address_street' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_city"><?php esc_html_e( 'City', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_city" id="address_city" value="<?php echo $v( 'address_city' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_state"><?php esc_html_e( 'State', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_state" id="address_state" value="<?php echo $v( 'address_state' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_postal_code"><?php esc_html_e( 'Postal Code', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_postal_code" id="address_postal_code" value="<?php echo $v( 'address_postal_code' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="phone"><?php esc_html_e( 'Phone Number', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="phone" id="phone" value="<?php echo $v( 'phone' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="email"><?php esc_html_e( 'Email Address', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="email" id="email" value="<?php echo $v( 'email' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="secondary_email"><?php esc_html_e( 'Secondary Email Address', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="secondary_email" id="secondary_email" value="<?php echo $v( 'secondary_email' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="notes"><?php esc_html_e( 'Notes', 'karks-crm' ); ?></label></th>
					<td><textarea class="large-text" rows="4" name="notes" id="notes"><?php echo $notes; ?></textarea></td>
				</tr>
			</table>

			<?php submit_button( $id ? __( 'Update Customer', 'karks-crm' ) : __( 'Add Customer', 'karks-crm' ) ); ?>
		</form>
		<?php
	}
}
