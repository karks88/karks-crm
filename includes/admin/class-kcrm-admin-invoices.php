<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Invoices extends KCRM_Invoices_Controller {

	use KCRM_Admin_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Invoices', 'karks-crm' ) . '</h1>';
		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'import_invoices' ) ) ), esc_html__( 'Import Invoices', 'karks-crm' ) );
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'import_payments' ) ) ), esc_html__( 'Import Payments', 'karks-crm' ) );
		}
		echo '<hr class="wp-header-end">';

		$this->company_switcher();
		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Karks CRM → Companies.', 'karks-crm' ) . '</p></div>';
			return;
		}

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} elseif ( 'import_invoices' === $view ) {
			$this->render_invoice_import();
		} elseif ( 'import_payments' === $view ) {
			$this->render_payment_import();
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_invoice_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing params, no state change.
		$stage = isset( $_GET['stage'] ) ? sanitize_key( $_GET['stage'] ) : 'upload';

		if ( 'done' === $stage ) {
			$this->render_import_done(
				array(
					/* translators: %d: number of invoices imported. */
					array( 'imported', __( '%d invoices imported.', 'karks-crm' ) ),
					/* translators: %d: number of rows skipped because the customer name did not match an existing customer. */
					array( 'skipped_no_customer', __( '%d rows skipped — customer name did not match an existing customer.', 'karks-crm' ) ),
					/* translators: %d: number of rows skipped because an invoice with that number already exists. */
					array( 'skipped_duplicate', __( '%d rows skipped — an invoice with that number already exists.', 'karks-crm' ) ),
					/* translators: %d: number of rows skipped due to missing customer name, issue date, or amount. */
					array( 'skipped_missing', __( '%d rows skipped — missing customer name, issue date, or amount.', 'karks-crm' ) ),
				)
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		} elseif ( 'map' === $stage && isset( $_GET['file'] ) ) {
			$this->render_import_map(
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
				sanitize_text_field( wp_unslash( $_GET['file'] ) ),
				$this->import_invoice_fields(),
				'import_invoices',
				'kcrm_import_invoices_run',
				'import_invoices_run',
				__( 'Map CSV Columns', 'karks-crm' ),
				__( 'Choose which column maps to each invoice field. Each row becomes an invoice with a single line item for the mapped amount — open the invoice afterward to add more detail. Status starts as Open and moves to Partially Paid/Paid automatically once you import the matching payments below; map the status column only to flag rows as Draft or Void.', 'karks-crm' )
			);
		} else {
			$this->render_import_upload(
				'kcrm_import_invoices_upload',
				'import_invoices_upload',
				__( 'Import Invoices from CSV', 'karks-crm' ),
				__( "Upload a CSV export and you'll be able to choose which columns map to which fields before anything is imported. Import your customers first if you haven't already — each row is matched to an existing customer by name.", 'karks-crm' )
			);
		}
	}

	private function render_payment_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing params, no state change.
		$stage = isset( $_GET['stage'] ) ? sanitize_key( $_GET['stage'] ) : 'upload';

		if ( 'done' === $stage ) {
			$this->render_import_done(
				array(
					/* translators: %d: number of payments imported. */
					array( 'imported', __( '%d payments imported.', 'karks-crm' ) ),
					/* translators: %d: number of rows skipped because the invoice number did not match an existing invoice. */
					array( 'skipped_no_invoice', __( '%d rows skipped — invoice number did not match an existing invoice.', 'karks-crm' ) ),
					/* translators: %d: number of rows skipped due to missing invoice number, date, or a positive amount. */
					array( 'skipped_missing', __( '%d rows skipped — missing invoice number, date, or a positive amount.', 'karks-crm' ) ),
				)
			);
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		} elseif ( 'map' === $stage && isset( $_GET['file'] ) ) {
			$this->render_import_map(
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
				sanitize_text_field( wp_unslash( $_GET['file'] ) ),
				$this->import_payment_fields(),
				'import_payments',
				'kcrm_import_payments_run',
				'import_payments_run',
				__( 'Map CSV Columns', 'karks-crm' ),
				__( "Choose which column maps to each payment field. Each row is matched to an existing invoice by its invoice number, and that invoice's status updates automatically based on the payments recorded against it.", 'karks-crm' )
			);
		} else {
			$this->render_import_upload(
				'kcrm_import_payments_upload',
				'import_payments_upload',
				__( 'Import Payments from CSV', 'karks-crm' ),
				__( "Upload a CSV export and you'll be able to choose which columns map to which fields before anything is imported. Import your invoices first if you haven't already — each row is matched to an existing invoice by number.", 'karks-crm' )
			);
		}
	}

	private function render_import_upload( $nonce_action, $kcrm_action, $heading, $description ) {
		?>
		<h2><?php echo esc_html( $heading ); ?></h2>
		<p><?php echo esc_html( $description ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( $nonce_action ); ?>
			<input type="hidden" name="kcrm_action" value="<?php echo esc_attr( $kcrm_action ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="import_file"><?php esc_html_e( 'CSV File', 'karks-crm' ); ?></label></th>
					<td><input type="file" name="import_file" id="import_file" accept=".csv" required></td>
				</tr>
			</table>
			<?php submit_button( __( 'Upload & Continue', 'karks-crm' ) ); ?>
		</form>
		<?php
	}

	private function render_import_map( $token, array $fields, $view, $nonce_action, $kcrm_action, $heading, $description ) {
		$path = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			echo '<p>' . esc_html__( 'That upload could not be found — it may have expired. Please upload the file again.', 'karks-crm' ) . '</p>';
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $this->screen_url( array( 'view' => $view ) ) ), esc_html__( 'Start Over', 'karks-crm' ) );
			return;
		}

		$header = KCRM_CSV_Import::read_header( $path );
		?>
		<h2><?php echo esc_html( $heading ); ?></h2>
		<p><?php echo esc_html( $description ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
			<?php wp_nonce_field( $nonce_action ); ?>
			<input type="hidden" name="kcrm_action" value="<?php echo esc_attr( $kcrm_action ); ?>">
			<input type="hidden" name="file" value="<?php echo esc_attr( $token ); ?>">
			<table class="form-table">
				<?php foreach ( $fields as $key => $field ) : ?>
					<?php $guess = $this->guess_column( $header, $field['guess'] ); ?>
					<tr>
						<th>
							<label for="map_<?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' *' : ''; ?>
							</label>
						</th>
						<td>
							<select name="map[<?php echo esc_attr( $key ); ?>]" id="map_<?php echo esc_attr( $key ); ?>">
								<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
								<?php foreach ( $header as $i => $label ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $guess, $i ); ?>>
										<?php echo esc_html( $this->column_label( $label, $i ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
			</table>
			<p class="description">* <?php esc_html_e( 'Required. Rows with a blank value in this column are skipped.', 'karks-crm' ); ?></p>
			<?php submit_button( __( 'Import', 'karks-crm' ) ); ?>
		</form>
		<?php
	}

	private function render_import_done( array $rows ) {
		?>
		<h2><?php esc_html_e( 'Import Complete', 'karks-crm' ); ?></h2>
		<ul>
			<?php foreach ( $rows as list( $key, $format ) ) : ?>
				<li>
					<?php
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counter, no state change.
					echo esc_html( sprintf( $format, isset( $_GET[ $key ] ) ? absint( $_GET[ $key ] ) : 0 ) );
					?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p><a class="button button-primary" href="<?php echo esc_url( $this->screen_url() ); ?>"><?php esc_html_e( 'View Invoices', 'karks-crm' ); ?></a></p>
		<?php
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
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>">
									<?php echo esc_html( $invoice->invoice_number ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $customer ? KCRM_Customer::display_name( $customer ) : '' ); ?></td>
						<td><?php echo esc_html( $invoice->issue_date ); ?></td>
						<td><?php echo esc_html( $invoice->due_date ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $invoice->total, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( $statuses[ $invoice->status ] ?? $invoice->status ); ?></span></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrm_download_invoice_pdf&id=' . $invoice->id ), 'kcrm_download_invoice_pdf_' . $invoice->id ) ); ?>"><?php esc_html_e( 'PDF', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $invoice->id ) ), 'kcrm_delete_invoice_' . $invoice->id ) ); ?>"
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$invoice = $id ? KCRM_Invoice::find( $id ) : null;

		if ( 'edit' === $view && ! $invoice ) {
			echo '<p>' . esc_html__( 'Invoice not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$company_id = $this->current_company_id();
		$company    = KCRM_Company::find( $company_id );
		$customers  = KCRM_Customer::for_company( $company_id );
		$services   = KCRM_Service::active_for_company( $company_id );
		$items      = $id ? KCRM_Invoice_Item::for_invoice( $id ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$preselect_customer = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : ( $invoice ? (int) $invoice->customer_id : 0 );

		if ( empty( $items ) ) {
			$items = array( (object) array( 'service_id' => 0, 'description' => '', 'type' => KCRM_Service::TYPE_PROJECT, 'quantity' => 1, 'rate' => 0 ) );
		}

		$services_js = array();
		foreach ( $services as $service ) {
			$services_js[] = array(
				'id'         => (int) $service->id,
				'name'       => $service->name,
				'type'       => $service->type,
				'rate'       => (float) $service->rate,
				'is_taxable' => (int) $service->is_taxable,
			);
		}
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" id="kcrm-invoice-form">
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
									<?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?>
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
						<th style="width:10%;"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
						<th style="width:6%;"><?php esc_html_e( 'Taxable', 'karks-crm' ); ?></th>
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
			<?php $this->render_payments_section( $invoice, $company ); ?>
			<p>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrm_download_invoice_pdf&id=' . $invoice->id ), 'kcrm_download_invoice_pdf_' . $invoice->id ) ); ?>">
					<?php esc_html_e( 'Download PDF', 'karks-crm' ); ?>
				</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $invoice->id ) ), 'kcrm_delete_invoice_' . $invoice->id ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Delete this invoice?', 'karks-crm' ) ); ?>');">
					<?php esc_html_e( 'Delete Invoice', 'karks-crm' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php
	}

	private function render_item_row( $item ) {
		$service_id  = isset( $item->service_id ) ? (int) $item->service_id : 0;
		$description = isset( $item->description ) ? $item->description : '';
		$type        = isset( $item->type ) ? $item->type : KCRM_Service::TYPE_PROJECT;
		$quantity    = isset( $item->quantity ) ? $item->quantity : '1';
		$rate        = isset( $item->rate ) ? $item->rate : '0.00';
		$is_taxable  = ! empty( $item->is_taxable );
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
			<td><input type="text" class="regular-text kcrm-item-description" name="item_description[]" value="<?php echo esc_attr( $description ); ?>"></td>
			<td>
				<select class="kcrm-item-type" name="item_type[]">
					<?php foreach ( KCRM_Service::types() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="number" step="0.01" min="0" class="kcrm-item-quantity" name="item_quantity[]" value="<?php echo esc_attr( $quantity ); ?>" style="width:100%;"></td>
			<td><input type="number" step="0.01" min="0" class="kcrm-item-rate" name="item_rate[]" value="<?php echo esc_attr( $rate ); ?>" style="width:100%;"></td>
			<td class="kcrm-item-amount">0.00</td>
			<td>
				<input type="hidden" class="kcrm-item-taxable-value" name="item_is_taxable[]" value="<?php echo $is_taxable ? '1' : '0'; ?>">
				<input type="checkbox" class="kcrm-item-taxable" <?php checked( $is_taxable ); ?>>
			</td>
			<td><button type="button" class="button-link kcrm-remove-line" aria-label="<?php esc_attr_e( 'Remove line', 'karks-crm' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	private function render_payments_section( $invoice, $company ) {
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
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete_payment', 'payment_id' => $payment->id ) ), 'kcrm_delete_payment_' . $payment->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this payment?', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h3><?php esc_html_e( 'Record a Payment', 'karks-crm' ); ?></h3>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
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
				<?php $kcrm_accepted_type_keys = KCRM_Company::accepted_payment_type_keys( $company ); ?>
				<?php if ( ! empty( $kcrm_accepted_type_keys ) ) : ?>
					<?php $kcrm_all_types = KCRM_Company::payment_types(); ?>
					<tr>
						<th><label for="method"><?php esc_html_e( 'Method', 'karks-crm' ); ?></label></th>
						<td>
							<select name="method" id="method">
								<?php foreach ( $kcrm_accepted_type_keys as $kcrm_type_key ) : ?>
									<option value="<?php echo esc_attr( $kcrm_all_types[ $kcrm_type_key ] ?? $kcrm_type_key ); ?>"><?php echo esc_html( $kcrm_all_types[ $kcrm_type_key ] ?? $kcrm_type_key ); ?></option>
								<?php endforeach; ?>
								<option value="__other__"><?php esc_html_e( 'Other…', 'karks-crm' ); ?></option>
							</select>
						</td>
					</tr>
					<tr id="kcrm-method-other-row" style="display:none;">
						<th><label for="method_other"><?php esc_html_e( 'Other Method', 'karks-crm' ); ?></label></th>
						<td><input type="text" class="regular-text" name="method_other" id="method_other"></td>
					</tr>
				<?php else : ?>
					<tr>
						<th><label for="method"><?php esc_html_e( 'Method', 'karks-crm' ); ?></label></th>
						<td><input type="text" name="method" id="method" placeholder="<?php esc_attr_e( 'e.g. Check, ACH, Credit Card', 'karks-crm' ); ?>"></td>
					</tr>
				<?php endif; ?>
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
