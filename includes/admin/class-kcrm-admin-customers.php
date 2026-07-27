<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Customers extends KCRM_Customers_Controller {

	use KCRM_Admin_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Customers', 'karks-crm' ) . '</h1>';
		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( $this->screen_url( array( 'view' => 'import' ) ) ), esc_html__( 'Import from CSV', 'karks-crm' ) );
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
		} elseif ( 'import' === $view ) {
			$this->render_import();
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing params, no state change.
		$stage = isset( $_GET['stage'] ) ? sanitize_key( $_GET['stage'] ) : 'upload';

		if ( 'done' === $stage ) {
			$this->render_import_done();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		} elseif ( 'map' === $stage && isset( $_GET['file'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
			$this->render_import_map( sanitize_text_field( wp_unslash( $_GET['file'] ) ) );
		} else {
			$this->render_import_upload();
		}
	}

	private function render_import_upload() {
		?>
		<h2><?php esc_html_e( 'Import Customers from CSV', 'karks-crm' ); ?></h2>
		<p><?php esc_html_e( "Upload a CSV export (e.g. from QuickBooks) and you'll be able to choose which columns map to which fields before anything is imported. Rows sharing the same company name only import once, and companies that already exist here are skipped automatically — it's safe to re-run.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'kcrm_import_upload' ); ?>
			<input type="hidden" name="kcrm_action" value="import_upload">
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

	private function render_import_map( $token ) {
		$path = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			echo '<p>' . esc_html__( 'That upload could not be found — it may have expired. Please upload the file again.', 'karks-crm' ) . '</p>';
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( $this->screen_url( array( 'view' => 'import' ) ) ), esc_html__( 'Start Over', 'karks-crm' ) );
			return;
		}

		$header = KCRM_CSV_Import::read_header( $path );
		$fields = $this->import_fields();
		?>
		<h2><?php esc_html_e( 'Map CSV Columns', 'karks-crm' ); ?></h2>
		<p><?php esc_html_e( "Choose which column in your file maps to each customer field. We've guessed a few based on common column names — double check before importing.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
			<?php wp_nonce_field( 'kcrm_import_run' ); ?>
			<input type="hidden" name="kcrm_action" value="import_run">
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
				<?php list( $address_from_guess, $address_to_guess ) = $this->guess_address_range( $header ); ?>
				<tr>
					<th><?php esc_html_e( 'Address Block', 'karks-crm' ); ?></th>
					<td>
						<?php esc_html_e( 'From', 'karks-crm' ); ?>
						<select name="map[address_from]">
							<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
							<?php foreach ( $header as $i => $label ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $address_from_guess, $i ); ?>>
									<?php echo esc_html( $this->column_label( $label, $i ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php esc_html_e( 'To', 'karks-crm' ); ?>
						<select name="map[address_to]">
							<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
							<?php foreach ( $header as $i => $label ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $address_to_guess, $i ); ?>>
									<?php echo esc_html( $this->column_label( $label, $i ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The range of columns making up the address (street, suite, city/state/zip). We scan this range for the line that looks like a city/state/zip and treat everything before it as the street — handles QuickBooks-style address blocks whose line count varies row to row.', 'karks-crm' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description">* <?php esc_html_e( 'Required. Rows with a blank value in this column are skipped.', 'karks-crm' ); ?></p>
			<?php submit_button( __( 'Import Customers', 'karks-crm' ) ); ?>
		</form>
		<?php
	}

	private function render_import_done() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$skipped_no_name = isset( $_GET['skipped_no_name'] ) ? absint( $_GET['skipped_no_name'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$skipped_duplicate = isset( $_GET['skipped_duplicate'] ) ? absint( $_GET['skipped_duplicate'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$skipped_existing = isset( $_GET['skipped_existing'] ) ? absint( $_GET['skipped_existing'] ) : 0;
		?>
		<h2><?php esc_html_e( 'Import Complete', 'karks-crm' ); ?></h2>
		<ul>
			<li>
				<?php
				/* translators: %d: number of customers imported. */
				echo esc_html( sprintf( __( '%d customers imported.', 'karks-crm' ), $imported ) );
				?>
			</li>
			<li>
				<?php
				/* translators: %d: number of rows skipped because the company already existed. */
				echo esc_html( sprintf( __( '%d rows skipped — already existed as a customer.', 'karks-crm' ), $skipped_existing ) );
				?>
			</li>
			<li>
				<?php
				/* translators: %d: number of rows skipped due to a duplicate company name within the file. */
				echo esc_html( sprintf( __( '%d rows skipped — duplicate company name within the file.', 'karks-crm' ), $skipped_duplicate ) );
				?>
			</li>
			<li>
				<?php
				/* translators: %d: number of rows skipped because the mapped company name column was blank. */
				echo esc_html( sprintf( __( '%d rows skipped — no company name in the mapped column.', 'karks-crm' ), $skipped_no_name ) );
				?>
			</li>
		</ul>
		<p><a class="button button-primary" href="<?php echo esc_url( $this->screen_url() ); ?>"><?php esc_html_e( 'View Customers', 'karks-crm' ); ?></a></p>
		<?php
	}

	private function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
		$raw_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$orderby     = in_array( $raw_orderby, array( 'company_name', 'status' ), true ) ? $raw_orderby : 'company_name';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
		$order = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC';

		$order_by  = 'status' === $orderby ? "status $order, company_name ASC" : "company_name $order";
		$customers = KCRM_Customer::top_level_for_company( $this->current_company_id(), $order_by );
		$statuses  = KCRM_Customer::statuses();

		$sort_url = function ( $column ) use ( $orderby, $order ) {
			return $this->screen_url(
				array(
					'orderby' => $column,
					'order'   => ( $column === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
				)
			);
		};
		?>
		<?php if ( ! empty( $customers ) ) : ?>
			<p class="kcrm-list-search">
				<label for="kcrm-customer-search" class="screen-reader-text"><?php esc_html_e( 'Search customers', 'karks-crm' ); ?></label>
				<input type="search" id="kcrm-customer-search" class="regular-text kcrm-instant-search" data-kcrm-search-table="kcrm-customers-table" data-kcrm-search-empty="<?php esc_attr_e( 'No customers match your search.', 'karks-crm' ); ?>" placeholder="<?php esc_attr_e( 'Search by company, contact, or email…', 'karks-crm' ); ?>">
			</p>
		<?php endif; ?>
		<table class="wp-list-table widefat fixed striped" id="kcrm-customers-table">
			<thead>
				<tr>
					<th>
						<a href="<?php echo esc_url( $sort_url( 'company_name' ) ); ?>">
							<?php esc_html_e( 'Company Name', 'karks-crm' ); ?>
							<?php if ( 'company_name' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Email', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'karks-crm' ); ?></th>
					<th>
						<a href="<?php echo esc_url( $sort_url( 'status' ) ); ?>">
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
					<?php
					$jobs    = KCRM_Customer::jobs_for( $customer->id );
					$job_ids = wp_list_pluck( $jobs, 'id' );
					$balance = KCRM_Customer::balance_for_ids( array_merge( array( $customer->id ), $job_ids ) );
					?>
					<tr class="kcrm-customer-row" data-kcrm-customer-row="<?php echo esc_attr( $customer->id ); ?>">
						<td>
							<strong>
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $customer->id ) ) ); ?>">
									<?php echo esc_html( $customer->company_name ); ?>
								</a>
							</strong>
							<?php if ( $jobs ) : ?>
								<br>
								<a href="#" class="kcrm-jobs-toggle" data-kcrm-jobs-parent="<?php echo esc_attr( $customer->id ); ?>">
									<?php
									/* translators: %d: number of Jobs under this customer. */
									echo esc_html( sprintf( _n( '%d Job', '%d Jobs', count( $jobs ), 'karks-crm' ), count( $jobs ) ) );
									?>
									<span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span>
								</a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $customer->contact_person ); ?></td>
						<td><?php echo esc_html( $customer->email ); ?></td>
						<td><?php echo esc_html( $customer->phone ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $customer->status ); ?>"><?php echo esc_html( $statuses[ $customer->status ] ?? $customer->status ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $customer->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $customer->id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $customer->id ) ), 'kcrm_delete_customer_' . $customer->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( $jobs ? __( 'Delete this customer and all of its Jobs?', 'karks-crm' ) : __( 'Delete this customer?', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
					<?php foreach ( $jobs as $job ) : ?>
						<?php $job_balance = KCRM_Customer::balance_for_ids( array( $job->id ) ); ?>
						<tr class="kcrm-job-row" data-kcrm-jobs-parent="<?php echo esc_attr( $customer->id ); ?>" style="display:none;">
							<td>
								&#8627;
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $job->id ) ) ); ?>">
									<?php echo esc_html( $job->company_name ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $job->contact_person ); ?></td>
							<td><?php echo esc_html( $job->email ); ?></td>
							<td><?php echo esc_html( $job->phone ); ?></td>
							<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $statuses[ $job->status ] ?? $job->status ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $job_balance, 2 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $job->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
								|
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $job->id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
								|
								<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $job->id ) ), 'kcrm_delete_customer_' . $job->id ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Delete this Job?', 'karks-crm' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_form( $view ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$customer = $id ? KCRM_Customer::find( $id ) : null;

		if ( 'edit' === $view && ! $customer ) {
			echo '<p>' . esc_html__( 'Customer not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$v = function ( $field, $default = '' ) use ( $customer ) {
			return $customer ? $customer->$field : $default;
		};
		$notes = $customer ? $customer->notes : '';

		$has_jobs = $id ? ! empty( KCRM_Customer::jobs_for( $id ) ) : false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$preselect_parent = isset( $_GET['parent_id'] ) ? absint( $_GET['parent_id'] ) : ( $customer ? (int) $customer->parent_customer_id : 0 );
		$parent_options   = $has_jobs ? array() : KCRM_Customer::top_level_for_company( $this->current_company_id() );
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
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
				<?php if ( ! $has_jobs ) : ?>
					<tr>
						<th><label for="parent_customer_id"><?php esc_html_e( 'This is a Job of', 'karks-crm' ); ?></label></th>
						<td>
							<select name="parent_customer_id" id="parent_customer_id">
								<option value="0"><?php esc_html_e( '— None (top-level customer) —', 'karks-crm' ); ?></option>
								<?php foreach ( $parent_options as $option ) : ?>
									<?php if ( (int) $option->id === $id ) { continue; } ?>
									<option value="<?php echo esc_attr( $option->id ); ?>" <?php selected( $preselect_parent, (int) $option->id ); ?>>
										<?php echo esc_html( $option->company_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optional. Use this for a specific project or division under an existing customer (like QuickBooks Jobs) — its own address, invoices, and revenue are tracked separately, and roll up into the parent customer\'s totals.', 'karks-crm' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><label for="company_name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="company_name" id="company_name" value="<?php echo esc_attr( $v( 'company_name' ) ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="contact_person"><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="contact_person" id="contact_person" value="<?php echo esc_attr( $v( 'contact_person' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="secondary_contact_person"><?php esc_html_e( 'Secondary Contact Person', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="secondary_contact_person" id="secondary_contact_person" value="<?php echo esc_attr( $v( 'secondary_contact_person' ) ); ?>"></td>
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
					<th><label for="phone"><?php esc_html_e( 'Phone Number', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="phone" id="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="email"><?php esc_html_e( 'Email Address', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="email" id="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="secondary_email"><?php esc_html_e( 'Secondary Email Address', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="secondary_email" id="secondary_email" value="<?php echo esc_attr( $v( 'secondary_email' ) ); ?>"></td>
				</tr>
				<tr>
					<th><label for="notes"><?php esc_html_e( 'Notes', 'karks-crm' ); ?></label></th>
					<td><textarea class="large-text" rows="4" name="notes" id="notes"><?php echo esc_textarea( $notes ); ?></textarea></td>
				</tr>
			</table>

			<?php submit_button( $id ? __( 'Update Customer', 'karks-crm' ) : __( 'Add Customer', 'karks-crm' ) ); ?>
		</form>

		<?php if ( $customer ) : ?>
			<?php
			$job_ids    = wp_list_pluck( KCRM_Customer::jobs_for( $customer->id ), 'id' );
			$rollup_ids = array_merge( array( $customer->id ), $job_ids );
			?>
			<?php if ( ! $customer->parent_customer_id ) : ?>
				<?php $this->render_jobs_section( $customer ); ?>
			<?php endif; ?>
			<?php $this->render_revenue_section( $rollup_ids, ! empty( $job_ids ) ); ?>
			<?php $this->render_invoices_section( $rollup_ids, $customer->id, ! empty( $job_ids ) ); ?>
			<?php do_action( 'kcrm_customer_edit_after_sections', $customer, $rollup_ids ); ?>
		<?php endif; ?>
		<?php
	}

	private function render_jobs_section( $customer ) {
		$jobs = KCRM_Customer::jobs_for( $customer->id );
		?>
		<h2><?php esc_html_e( 'Jobs', 'karks-crm' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'add', 'parent_id' => $customer->id ) ) ); ?>"><?php esc_html_e( '+ Add Job', 'karks-crm' ); ?></a>
		</p>
		<?php if ( $jobs ) : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'karks-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $job->id ) ) ); ?>"><?php echo esc_html( $job->company_name ); ?></a></td>
							<td><?php echo esc_html( $job->contact_person ); ?></td>
							<td><?php echo esc_html( number_format_i18n( KCRM_Customer::balance_for_ids( array( $job->id ) ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/** @param array $customer_ids The customer plus its Jobs (rolled up), when it has any. */
	private function render_revenue_section( array $customer_ids, $is_rollup ) {
		$this_year        = (int) current_time( 'Y' );
		$last_year        = $this_year - 1;
		$this_year_total  = KCRM_Payment::total_for_customers_in_year( $customer_ids, $this_year );
		$last_year_total  = KCRM_Payment::total_for_customers_in_year( $customer_ids, $last_year );
		$lifetime_total   = KCRM_Payment::total_for_customers( $customer_ids );
		$balance          = KCRM_Customer::balance_for_ids( $customer_ids );
		?>
		<h2><?php esc_html_e( 'Revenue', 'karks-crm' ); ?></h2>
		<?php if ( $is_rollup ) : ?>
			<p class="description"><?php esc_html_e( 'Includes this customer and all of its Jobs.', 'karks-crm' ); ?></p>
		<?php endif; ?>
		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Current Balance', 'karks-crm' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $this_year_total, 2 ) ); ?></span>
				<span class="kcrm-card-label">
					<?php
					/* translators: %d: calendar year. */
					echo esc_html( sprintf( __( '%d Revenue', 'karks-crm' ), $this_year ) );
					?>
				</span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $last_year_total, 2 ) ); ?></span>
				<span class="kcrm-card-label">
					<?php
					/* translators: %d: calendar year. */
					echo esc_html( sprintf( __( '%d Revenue', 'karks-crm' ), $last_year ) );
					?>
				</span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $lifetime_total, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Lifetime Revenue', 'karks-crm' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $customer_ids The customer plus its Jobs (rolled up), when it has any.
	 * @param int   $primary_customer_id Used for the "New Invoice" / customer_id links.
	 */
	private function render_invoices_section( array $customer_ids, $primary_customer_id, $is_rollup ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$show_all = ! empty( $_GET['kcrm_invoice_filter'] ) && 'all' === sanitize_key( wp_unslash( $_GET['kcrm_invoice_filter'] ) );
		$statuses = $show_all ? null : KCRM_Invoice::default_customer_statuses();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
		$raw_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$orderby     = in_array( $raw_orderby, array( 'invoice_number', 'issue_date', 'due_date', 'balance_due' ), true ) ? $raw_orderby : 'issue_date';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
		if ( isset( $_GET['order'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
			$order = 'asc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		} else {
			$order = 'issue_date' === $orderby ? 'DESC' : 'ASC';
		}

		$order_by_sql = 'balance_due' === $orderby ? 'issue_date DESC, id DESC' : "$orderby $order, id DESC";
		$invoices     = KCRM_Invoice::for_customers_with_statuses( $customer_ids, $statuses, $order_by_sql );
		$all_statuses = KCRM_Invoice::statuses();

		$balances = array();
		foreach ( $invoices as $invoice ) {
			$balances[ $invoice->id ] = KCRM_Invoice::balance_due( $invoice->id );
		}
		if ( 'balance_due' === $orderby ) {
			usort(
				$invoices,
				function ( $a, $b ) use ( $balances, $order ) {
					$diff = $balances[ $a->id ] <=> $balances[ $b->id ];
					return 'DESC' === $order ? -$diff : $diff;
				}
			);
		}

		$toggle_url = $show_all ? remove_query_arg( 'kcrm_invoice_filter' ) : add_query_arg( 'kcrm_invoice_filter', 'all' );
		$sort_url   = function ( $column ) use ( $orderby, $order ) {
			return add_query_arg(
				array(
					'orderby' => $column,
					'order'   => ( $column === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
				)
			);
		};
		?>
		<h2><?php esc_html_e( 'Invoices', 'karks-crm' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Invoices with a status of Draft, Open, and Partially Paid are displayed by default.', 'karks-crm' ); ?></p>
		<?php if ( $is_rollup ) : ?>
			<p class="description"><?php esc_html_e( 'Includes this customer and all of its Jobs.', 'karks-crm' ); ?></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $toggle_url ); ?>">
				<?php
				echo $show_all
					? esc_html__( 'Show default statuses only (Draft, Open, Partially Paid)', 'karks-crm' )
					: esc_html__( 'Show invoices with all statuses', 'karks-crm' );
				?>
			</a>
			|
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $primary_customer_id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
		</p>
		<table class="wp-list-table widefat fixed striped" style="max-width:900px;">
			<thead>
				<tr>
					<th>
						<a href="<?php echo esc_url( $sort_url( 'invoice_number' ) ); ?>">
							<?php esc_html_e( 'Invoice #', 'karks-crm' ); ?>
							<?php if ( 'invoice_number' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<?php if ( $is_rollup ) : ?>
						<th><?php esc_html_e( 'Billed To', 'karks-crm' ); ?></th>
					<?php endif; ?>
					<th>
						<a href="<?php echo esc_url( $sort_url( 'issue_date' ) ); ?>">
							<?php esc_html_e( 'Issue Date', 'karks-crm' ); ?>
							<?php if ( 'issue_date' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<th>
						<a href="<?php echo esc_url( $sort_url( 'due_date' ) ); ?>">
							<?php esc_html_e( 'Due Date', 'karks-crm' ); ?>
							<?php if ( 'due_date' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<th><?php esc_html_e( 'Total', 'karks-crm' ); ?></th>
					<th>
						<a href="<?php echo esc_url( $sort_url( 'balance_due' ) ); ?>">
							<?php esc_html_e( 'Balance Due', 'karks-crm' ); ?>
							<?php if ( 'balance_due' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<th><?php esc_html_e( 'Status', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $invoices ) ) : ?>
					<tr><td colspan="<?php echo $is_rollup ? '7' : '6'; ?>"><?php esc_html_e( 'No invoices found.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php $total_balance = 0; ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php
					$balance         = $balances[ $invoice->id ];
					$total_balance  += $balance;
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=edit&id=' . $invoice->id ) ); ?>">
									<?php echo esc_html( $invoice->invoice_number ); ?>
								</a>
							</strong>
						</td>
						<?php if ( $is_rollup ) : ?>
							<td>
								<?php
								$billed_to = (int) $invoice->customer_id === (int) $primary_customer_id ? null : KCRM_Customer::find( $invoice->customer_id );
								echo esc_html( $billed_to ? $billed_to->company_name : __( '(this customer)', 'karks-crm' ) );
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $invoice->issue_date ); ?></td>
						<td><?php echo esc_html( $invoice->due_date ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $invoice->total, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( $all_statuses[ $invoice->status ] ?? $invoice->status ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $invoices ) ) : ?>
			<p><strong><?php esc_html_e( 'Balance Owed (invoices shown above):', 'karks-crm' ); ?></strong> <?php echo esc_html( number_format_i18n( $total_balance, 2 ) ); ?></p>
		<?php endif; ?>
		<?php
	}
}
