<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Invoices extends KCRM_Admin_Base {

	const PAGE = 'karks-crm-invoices';

	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_POST['kcrm_action'] ) && 'save_invoice' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		if ( isset( $_POST['kcrm_action'] ) && 'add_payment' === $_POST['kcrm_action'] ) {
			$this->add_payment();
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$this->delete();
		}

		if ( isset( $_GET['action'], $_GET['payment_id'] ) && 'delete_payment' === $_GET['action'] ) {
			$this->delete_payment();
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_invoice' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'no_company' ) );
		}

		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$customer    = $customer_id ? KCRM_Customer::find( $customer_id ) : null;

		if ( ! $customer || (int) $customer->company_id !== $company_id ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? KCRM_Invoice::STATUS_OPEN ) );
		if ( ! array_key_exists( $status, KCRM_Invoice::statuses() ) ) {
			$status = KCRM_Invoice::STATUS_OPEN;
		}

		$invoice_type = sanitize_key( wp_unslash( $_POST['invoice_type'] ?? KCRM_Invoice::TYPE_OTHER ) );
		if ( ! array_key_exists( $invoice_type, KCRM_Invoice::types() ) ) {
			$invoice_type = KCRM_Invoice::TYPE_OTHER;
		}

		$data = array(
			'company_id'         => $company_id,
			'customer_id'        => $customer_id,
			'status'             => $status,
			'issue_date'         => $this->sanitize_date( $_POST['issue_date'] ?? '' ),
			'due_date'           => $this->sanitize_date( $_POST['due_date'] ?? '' ),
			'invoice_type'       => $invoice_type,
			'invoice_type_month' => KCRM_Invoice::TYPE_MONTH_YEAR === $invoice_type ? $this->sanitize_month( $_POST['invoice_type_month'] ?? '' ) : null,
			'invoice_type_other' => KCRM_Invoice::TYPE_OTHER === $invoice_type ? sanitize_text_field( wp_unslash( $_POST['invoice_type_other'] ?? '' ) ) : null,
			'notes'              => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'tax_rate'           => isset( $_POST['tax_rate'] ) ? (float) $_POST['tax_rate'] : 0,
		);

		if ( $id ) {
			unset( $data['company_id'] );
			KCRM_Invoice::save( $id, $data );
		} else {
			$data['invoice_number'] = KCRM_Company::next_invoice_number( $company_id );
			$id                     = KCRM_Invoice::create( $data );
		}

		$this->save_line_items( $id );
		KCRM_Invoice::recalculate_totals( $id );

		$this->redirect( array( 'page' => self::PAGE, 'view' => 'edit', 'id' => $id, 'kcrm_notice' => 'saved' ) );
	}

	private function save_line_items( $invoice_id ) {
		KCRM_Invoice_Item::delete_for_invoice( $invoice_id );

		$descriptions = isset( $_POST['item_description'] ) ? (array) wp_unslash( $_POST['item_description'] ) : array();
		$types        = isset( $_POST['item_type'] ) ? (array) wp_unslash( $_POST['item_type'] ) : array();
		$quantities   = isset( $_POST['item_quantity'] ) ? (array) wp_unslash( $_POST['item_quantity'] ) : array();
		$rates        = isset( $_POST['item_rate'] ) ? (array) wp_unslash( $_POST['item_rate'] ) : array();
		$service_ids  = isset( $_POST['item_service_id'] ) ? (array) wp_unslash( $_POST['item_service_id'] ) : array();

		$sort = 0;
		foreach ( $descriptions as $index => $description ) {
			$description = sanitize_text_field( $description );
			$quantity    = isset( $quantities[ $index ] ) ? (float) $quantities[ $index ] : 0;
			$rate        = isset( $rates[ $index ] ) ? (float) $rates[ $index ] : 0;

			if ( '' === $description && 0.0 === $quantity && 0.0 === $rate ) {
				continue; // Skip blank rows.
			}

			$type = isset( $types[ $index ] ) ? sanitize_key( $types[ $index ] ) : KCRM_Service::TYPE_PROJECT;
			if ( ! array_key_exists( $type, KCRM_Service::types() ) ) {
				$type = KCRM_Service::TYPE_PROJECT;
			}

			$service_id = isset( $service_ids[ $index ] ) ? absint( $service_ids[ $index ] ) : 0;

			KCRM_Invoice_Item::insert(
				array(
					'invoice_id'  => $invoice_id,
					'service_id'  => $service_id ?: null,
					'description' => $description,
					'type'        => $type,
					'quantity'    => $quantity,
					'rate'        => $rate,
					'amount'      => round( $quantity * $rate, 2 ),
					'sort_order'  => $sort++,
				)
			);
		}
	}

	private function add_payment() {
		check_admin_referer( 'kcrm_add_payment' );

		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;
		$invoice    = $invoice_id ? KCRM_Invoice::find( $invoice_id ) : null;

		if ( ! $invoice || (int) $invoice->company_id !== $this->current_company_id() ) {
			$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'error' ) );
		}

		$amount = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
		if ( $amount > 0 ) {
			KCRM_Payment::create(
				array(
					'invoice_id'   => $invoice_id,
					'customer_id'  => $invoice->customer_id,
					'company_id'   => $invoice->company_id,
					'amount'       => $amount,
					'payment_date' => $this->sanitize_date( $_POST['payment_date'] ?? '' ),
					'method'       => sanitize_text_field( wp_unslash( $_POST['method'] ?? '' ) ),
					'note'         => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
				)
			);
		}

		$this->redirect( array( 'page' => self::PAGE, 'view' => 'edit', 'id' => $invoice_id, 'kcrm_notice' => 'saved' ) );
	}

	private function delete_payment() {
		$payment_id = absint( $_GET['payment_id'] );
		check_admin_referer( 'kcrm_delete_payment_' . $payment_id );

		$payment = KCRM_Payment::find( $payment_id );
		KCRM_Payment::delete_and_refresh( $payment_id );

		$this->redirect( array( 'page' => self::PAGE, 'view' => 'edit', 'id' => $payment ? $payment->invoice_id : 0, 'kcrm_notice' => 'deleted' ) );
	}

	private function delete() {
		check_admin_referer( 'kcrm_delete_invoice_' . absint( $_GET['id'] ) );
		$invoice_id = absint( $_GET['id'] );
		KCRM_Invoice_Item::delete_for_invoice( $invoice_id );
		KCRM_Invoice::delete( $invoice_id );
		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'deleted' ) );
	}

	private function sanitize_date( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		return $value;
	}

	private function sanitize_month( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * admin-post handler: streams the invoice as a PDF download.
	 */
	public function handle_pdf_download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'kcrm_download_invoice_pdf_' . $id );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ) );
		}

		$invoice = $id ? KCRM_Invoice::find( $id ) : null;
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'karks-crm' ) );
		}

		KCRM_PDF::stream_invoice( $invoice );
	}

	public function render() {
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Invoices', 'karks-crm' ) . '</h1>';
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
		$invoices = KCRM_Invoice::for_company( $this->current_company_id() );
		$statuses = KCRM_Invoice::statuses();
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Issue Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Due Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Total', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Balance Due', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $invoices ) ) : ?>
					<tr><td colspan="8"><?php esc_html_e( 'No invoices yet for this company.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php
					$customer = KCRM_Customer::find( $invoice->customer_id );
					$balance  = KCRM_Invoice::balance_due( $invoice->id );
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $invoice->id ) ); ?>">
									<?php echo esc_html( $invoice->invoice_number ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $customer ? $customer->company_name : '' ); ?></td>
						<td><?php echo esc_html( $invoice->issue_date ); ?></td>
						<td><?php echo esc_html( $invoice->due_date ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $invoice->total, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( $statuses[ $invoice->status ] ?? $invoice->status ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $invoice->id ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrm_download_invoice_pdf&id=' . $invoice->id ), 'kcrm_download_invoice_pdf_' . $invoice->id ) ); ?>"><?php esc_html_e( 'PDF', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=delete&id=' . $invoice->id ), 'kcrm_delete_invoice_' . $invoice->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this invoice?', 'karks-crm' ) ); ?>');">
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
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$invoice = $id ? KCRM_Invoice::find( $id ) : null;

		if ( 'edit' === $view && ! $invoice ) {
			echo '<p>' . esc_html__( 'Invoice not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$company_id       = $this->current_company_id();
		$company          = KCRM_Company::find( $company_id );
		$customers        = KCRM_Customer::for_company( $company_id );
		$services         = KCRM_Service::active_for_company( $company_id );
		$items            = $id ? KCRM_Invoice_Item::for_invoice( $id ) : array();
		$preselect_customer = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : ( $invoice ? (int) $invoice->customer_id : 0 );

		if ( empty( $items ) ) {
			$items = array( (object) array( 'service_id' => 0, 'description' => '', 'type' => KCRM_Service::TYPE_PROJECT, 'quantity' => 1, 'rate' => 0 ) );
		}

		$services_js = array();
		foreach ( $services as $service ) {
			$services_js[] = array(
				'id'          => (int) $service->id,
				'name'        => $service->name,
				'type'        => $service->type,
				'rate'        => (float) $service->rate,
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" id="kcrm-invoice-form">
			<?php wp_nonce_field( 'kcrm_save_invoice' ); ?>
			<input type="hidden" name="kcrm_action" value="save_invoice">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="invoice_type"><?php esc_html_e( 'Invoice Type', 'karks-crm' ); ?></label></th>
					<td>
						<select name="invoice_type" id="invoice_type">
							<?php foreach ( KCRM_Invoice::types() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $invoice ? $invoice->invoice_type : KCRM_Invoice::TYPE_OTHER, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr id="kcrm-invoice-type-month-row">
					<th><label for="invoice_type_month"><?php esc_html_e( 'Month/Year', 'karks-crm' ); ?></label></th>
					<td><input type="month" name="invoice_type_month" id="invoice_type_month" value="<?php echo esc_attr( $invoice ? $invoice->invoice_type_month : '' ); ?>"></td>
				</tr>
				<tr id="kcrm-invoice-type-other-row">
					<th><label for="invoice_type_other"><?php esc_html_e( 'Custom Type', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="invoice_type_other" id="invoice_type_other" value="<?php echo esc_attr( $invoice ? $invoice->invoice_type_other : '' ); ?>"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<td><?php echo $invoice ? esc_html( $invoice->invoice_number ) : '<em>' . esc_html__( 'Assigned on save', 'karks-crm' ) . '</em>'; ?></td>
				</tr>
				<tr>
					<th><label for="customer_id"><?php esc_html_e( 'Customer', 'karks-crm' ); ?></label></th>
					<td>
						<select name="customer_id" id="customer_id" required>
							<option value=""><?php esc_html_e( '— Select a customer —', 'karks-crm' ); ?></option>
							<?php foreach ( $customers as $customer ) : ?>
								<option value="<?php echo esc_attr( $customer->id ); ?>" <?php selected( $preselect_customer, (int) $customer->id ); ?>>
									<?php echo esc_html( $customer->company_name ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="status"><?php esc_html_e( 'Status', 'karks-crm' ); ?></label></th>
					<td>
						<select name="status" id="status">
							<?php foreach ( KCRM_Invoice::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $invoice ? $invoice->status : KCRM_Invoice::STATUS_OPEN, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Open/Partial/Paid update automatically as payments are recorded below.', 'karks-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="issue_date"><?php esc_html_e( 'Issue Date', 'karks-crm' ); ?></label></th>
					<td><input type="date" name="issue_date" id="issue_date" value="<?php echo esc_attr( $invoice ? $invoice->issue_date : gmdate( 'Y-m-d' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="due_date"><?php esc_html_e( 'Due Date', 'karks-crm' ); ?></label></th>
					<td><input type="date" name="due_date" id="due_date" value="<?php echo esc_attr( $invoice ? $invoice->due_date : '' ); ?>"></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Line Items', 'karks-crm' ); ?></h2>
			<table class="widefat" id="kcrm-line-items">
				<thead>
					<tr>
						<th style="width:18%;"><?php esc_html_e( 'Service', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Description', 'karks-crm' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Type', 'karks-crm' ); ?></th>
						<th style="width:10%;"><?php esc_html_e( 'Qty / Hours', 'karks-crm' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Rate', 'karks-crm' ); ?></th>
						<th style="width:12%;"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
						<th style="width:4%;"></th>
					</tr>
				</thead>
				<tbody id="kcrm-line-items-body">
					<?php foreach ( $items as $item ) : ?>
						<?php $this->render_item_row( $item ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="kcrm-add-line"><?php esc_html_e( '+ Add Line', 'karks-crm' ); ?></button></p>

			<table class="form-table" style="max-width:400px;margin-left:auto;">
				<tr>
					<th><?php esc_html_e( 'Subtotal', 'karks-crm' ); ?></th>
					<td id="kcrm-subtotal">0.00</td>
				</tr>
				<tr>
					<th><label for="tax_rate"><?php esc_html_e( 'Tax Rate (%)', 'karks-crm' ); ?></label></th>
					<td><input type="number" step="0.001" min="0" name="tax_rate" id="tax_rate" value="<?php echo esc_attr( $invoice ? $invoice->tax_rate : ( $company ? $company->default_tax_rate : 0 ) ); ?>" style="width:100px;"></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Total', 'karks-crm' ); ?></th>
					<td id="kcrm-total"><strong>0.00</strong></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Notes', 'karks-crm' ); ?></h2>
			<textarea class="large-text" rows="3" name="notes"><?php echo $invoice ? esc_textarea( $invoice->notes ) : ''; ?></textarea>

			<?php submit_button( $id ? __( 'Update Invoice', 'karks-crm' ) : __( 'Create Invoice', 'karks-crm' ) ); ?>
		</form>

		<script>
			window.kcrmServices = <?php echo wp_json_encode( $services_js ); ?>;
		</script>

		<?php if ( $invoice ) : ?>
			<?php $this->render_payments_section( $invoice ); ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrm_download_invoice_pdf&id=' . $invoice->id ), 'kcrm_download_invoice_pdf_' . $invoice->id ) ); ?>">
					<?php esc_html_e( 'Download PDF', 'karks-crm' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_item_row( $item ) {
		$service_id  = isset( $item->service_id ) ? (int) $item->service_id : 0;
		$description = isset( $item->description ) ? esc_attr( $item->description ) : '';
		$type        = isset( $item->type ) ? $item->type : KCRM_Service::TYPE_PROJECT;
		$quantity    = isset( $item->quantity ) ? esc_attr( $item->quantity ) : '1';
		$rate        = isset( $item->rate ) ? esc_attr( $item->rate ) : '0.00';
		?>
		<tr class="kcrm-line-item">
			<td>
				<select class="kcrm-item-service" name="item_service_id[]">
					<option value="0"><?php esc_html_e( 'Custom', 'karks-crm' ); ?></option>
					<?php foreach ( KCRM_Service::active_for_company( $this->current_company_id() ) as $service ) : ?>
						<option value="<?php echo esc_attr( $service->id ); ?>" <?php selected( $service_id, (int) $service->id ); ?>><?php echo esc_html( $service->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="regular-text kcrm-item-description" name="item_description[]" value="<?php echo $description; ?>"></td>
			<td>
				<select class="kcrm-item-type" name="item_type[]">
					<?php foreach ( KCRM_Service::types() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="number" step="0.01" min="0" class="kcrm-item-quantity" name="item_quantity[]" value="<?php echo $quantity; ?>" style="width:100%;"></td>
			<td><input type="number" step="0.01" min="0" class="kcrm-item-rate" name="item_rate[]" value="<?php echo $rate; ?>" style="width:100%;"></td>
			<td class="kcrm-item-amount">0.00</td>
			<td><button type="button" class="button-link kcrm-remove-line" aria-label="<?php esc_attr_e( 'Remove line', 'karks-crm' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	private function render_payments_section( $invoice ) {
		$payments = KCRM_Payment::for_invoice( $invoice->id );
		$balance  = KCRM_Invoice::balance_due( $invoice->id );
		?>
		<h2><?php esc_html_e( 'Payments', 'karks-crm' ); ?></h2>
		<p><strong><?php esc_html_e( 'Balance Due:', 'karks-crm' ); ?></strong> <?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></p>

		<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Method', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Note', 'karks-crm' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $payments ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No payments recorded yet.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $payments as $payment ) : ?>
					<tr>
						<td><?php echo esc_html( $payment->payment_date ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $payment->amount, 2 ) ); ?></td>
						<td><?php echo esc_html( $payment->method ); ?></td>
						<td><?php echo esc_html( $payment->note ); ?></td>
						<td>
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=delete_payment&payment_id=' . $payment->id ), 'kcrm_delete_payment_' . $payment->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this payment?', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Record a Payment', 'karks-crm' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<?php wp_nonce_field( 'kcrm_add_payment' ); ?>
			<input type="hidden" name="kcrm_action" value="add_payment">
			<input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice->id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="amount"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></label></th>
					<td><input type="number" step="0.01" min="0.01" name="amount" id="amount" value="<?php echo esc_attr( max( 0, $balance ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="payment_date"><?php esc_html_e( 'Date', 'karks-crm' ); ?></label></th>
					<td><input type="date" name="payment_date" id="payment_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="method"><?php esc_html_e( 'Method', 'karks-crm' ); ?></label></th>
					<td><input type="text" name="method" id="method" placeholder="<?php esc_attr_e( 'e.g. Check, ACH, Credit Card', 'karks-crm' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="note"><?php esc_html_e( 'Note', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="note" id="note"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Record Payment', 'karks-crm' ) ); ?>
		</form>
		<?php
	}
}
