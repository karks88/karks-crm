<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Front_Customers extends KCRM_Customers_Controller {

	use KCRM_Front_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="kcrm-front-screen">';
		$this->render_company_header();
		$this->render_heading( $view );

		if ( 'list' === $view ) {
			printf( '<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s"><span class="dashicons dashicons-plus-alt2"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-upload"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'import' ) ) ), esc_html__( 'Import from CSV', 'karks-crm' ) );
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-download"></span> %s</a></div>', esc_url( $this->export_customers_csv_url() ), esc_html__( 'Export CSV', 'karks-crm' ) );
		}

		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p></div>';
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

	/** Renders the H2 -- linked back to the list once we're off it, with the customer's name appended when editing one. */
	private function render_heading( $view ) {
		$label = __( 'Customers', 'karks-crm' );

		if ( 'list' === $view ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';
			return;
		}

		$link = sprintf( '<a href="%s">%s</a>', esc_url( $this->screen_url() ), esc_html( $label ) );

		if ( 'edit' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
			$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$customer = $id ? KCRM_Customer::find( $id ) : null;

			if ( $customer ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
				echo '<h2>' . $link . ': ' . esc_html( KCRM_Customer::display_name( $customer ) ) . '</h2>';
				return;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
		echo '<h2>' . $link . '</h2>';
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
		<p><?php esc_html_e( "Upload a CSV export (e.g. from QuickBooks) and you'll be able to choose which columns map to which fields before anything is imported. Rows sharing the same company name only import once, and companies that already exist here are skipped automatically — it's safe to re-run.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" enctype="multipart/form-data" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_import_upload' ); ?>
			<input type="hidden" name="kcrm_action" value="import_upload">
			<p>
				<label for="import_file"><?php esc_html_e( 'CSV File', 'karks-crm' ); ?></label>
				<input type="file" name="import_file" id="import_file" accept=".csv" required>
			</p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Upload & Continue', 'karks-crm' ); ?></button></p>
		</form>
		<?php
	}

	private function render_import_map( $token ) {
		$path = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			echo '<p>' . esc_html__( 'That upload could not be found — it may have expired. Please upload the file again.', 'karks-crm' ) . '</p>';
			printf( '<div class="kcrm-button-group"><a class="kcrm-button" href="%s">%s</a></div>', esc_url( $this->screen_url( array( 'view' => 'import' ) ) ), esc_html__( 'Start Over', 'karks-crm' ) );
			return;
		}

		$header = KCRM_CSV_Import::read_header( $path );
		$fields = $this->import_fields();
		?>
		<p><?php esc_html_e( "Choose which column in your file maps to each customer field. We've guessed a few based on common column names — double check before importing.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_import_run' ); ?>
			<input type="hidden" name="kcrm_action" value="import_run">
			<input type="hidden" name="file" value="<?php echo esc_attr( $token ); ?>">
			<?php foreach ( $fields as $key => $field ) : ?>
				<?php $guess = $this->guess_column( $header, $field['guess'] ); ?>
				<p>
					<label for="map_<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' *' : ''; ?>
					</label>
					<select name="map[<?php echo esc_attr( $key ); ?>]" id="map_<?php echo esc_attr( $key ); ?>">
						<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
						<?php foreach ( $header as $i => $label ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $guess, $i ); ?>>
								<?php echo esc_html( $this->column_label( $label, $i ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endforeach; ?>
			<?php list( $address_from_guess, $address_to_guess ) = $this->guess_address_range( $header ); ?>
			<p>
				<label><?php esc_html_e( 'Address Block', 'karks-crm' ); ?></label>
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
				<br><small><?php esc_html_e( 'The range of columns making up the address (street, suite, city/state/zip). We scan this range for the line that looks like a city/state/zip and treat everything before it as the street — handles QuickBooks-style address blocks whose line count varies row to row.', 'karks-crm' ); ?></small>
			</p>
			<p class="description">* <?php esc_html_e( 'Required. Rows with a blank value in this column are skipped.', 'karks-crm' ); ?></p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Import Customers', 'karks-crm' ); ?></button></p>
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
		<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->screen_url() ); ?>"><?php esc_html_e( 'View Customers', 'karks-crm' ); ?></a></div>
		<?php
	}

	private function render_list() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
		$raw_orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : '';
		$orderby     = in_array( $raw_orderby, array( 'company_name', 'status' ), true ) ? $raw_orderby : 'company_name';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list-table sort params, no state change.
		$order = isset( $_GET['order'] ) && 'desc' === strtolower( sanitize_key( wp_unslash( $_GET['order'] ) ) ) ? 'DESC' : 'ASC';

		$order_by   = 'status' === $orderby ? "status $order, company_name ASC" : "company_name $order";
		$company_id = $this->current_company_id();
		$statuses   = KCRM_Customer::statuses();

		$show_all      = $this->show_all_customers_requested();
		$status_filter = $show_all ? null : KCRM_Customer::STATUS_ACTIVE;

		$per_page     = 200;
		$total        = KCRM_Customer::count_top_level_for_company( $company_id, $status_filter );
		$total_pages  = (int) ceil( $total / $per_page );
		$current_page = $this->current_page_number( 'kcrm_pg', $total_pages );
		$offset       = ( $current_page - 1 ) * $per_page;

		$customers = KCRM_Customer::top_level_for_company( $company_id, $order_by, $status_filter, $per_page, $offset );

		$sort_url = function ( $column ) use ( $orderby, $order ) {
			return $this->screen_url(
				array(
					'orderby' => $column,
					'order'   => ( $column === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
				)
			);
		};
		$this->render_active_customers_toggle( $show_all );
		?>
		<?php if ( ! empty( $customers ) ) : ?>
			<p class="kcrm-list-search">
				<label for="kcrm-customer-search" class="screen-reader-text"><?php esc_html_e( 'Search customers', 'karks-crm' ); ?></label>
				<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only search term, no state change; only ever used as an initial value for the client-side instant-search filter below, never in a query. ?>
				<input type="search" id="kcrm-customer-search" class="kcrm-instant-search" data-kcrm-search-table="kcrm-front-customers-table" data-kcrm-search-empty="<?php esc_attr_e( 'No customers match your search.', 'karks-crm' ); ?>" placeholder="<?php esc_attr_e( 'Search by company, contact, or email…', 'karks-crm' ); ?>" value="<?php echo isset( $_GET['s'] ) ? esc_attr( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; ?>">
			</p>
		<?php endif; ?>
		<table class="kcrm-front-table" id="kcrm-front-customers-table">
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
					<tr>
						<td colspan="6"><?php echo esc_html( $this->no_customers_message( $show_all ) ); ?></td>
					</tr>
				<?php endif; ?>
				<?php
				$jobs_by_parent     = KCRM_Customer::jobs_for_many( wp_list_pluck( $customers, 'id' ) );
				$top_level_balances = KCRM_Customer::balances_for_top_level( $customers );
				$all_jobs           = empty( $jobs_by_parent ) ? array() : array_merge( ...array_values( $jobs_by_parent ) );
				$job_balances       = KCRM_Customer::balances_for( wp_list_pluck( $all_jobs, 'id' ) );
				?>
				<?php foreach ( $customers as $customer ) : ?>
					<?php
					$jobs    = $jobs_by_parent[ $customer->id ] ?? array();
					$balance = $top_level_balances[ (int) $customer->id ];
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
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $customer->status ); ?>"><?php echo esc_html( $statuses[ $customer->status ] ?? $customer->status ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $customer->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'add', 'customer_id' => $customer->id ) ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $customer->id ) ), 'kcrm_delete_customer_' . $customer->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( $jobs ? __( 'Delete this customer and all of its Jobs?', 'karks-crm' ) : __( 'Delete this customer?', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
					<?php foreach ( $jobs as $job ) : ?>
						<?php $job_balance = $job_balances[ (int) $job->id ]; ?>
						<tr class="kcrm-job-row" data-kcrm-jobs-parent="<?php echo esc_attr( $customer->id ); ?>" style="display:none;">
							<td>
								&#8627;
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $job->id ) ) ); ?>">
									<?php echo esc_html( $job->company_name ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $job->contact_person ); ?></td>
							<td><?php echo esc_html( $job->email ); ?></td>
							<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $statuses[ $job->status ] ?? $job->status ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $job_balance, 2 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $job->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
								|
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'add', 'customer_id' => $job->id ) ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
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
		<?php $this->render_pagination( $current_page, $total_pages, 'kcrm_pg', 'kcrm-front-customers-table' ); ?>
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

		if ( ! $customer ) {
			// Adding a brand-new customer: nothing else to show yet, so skip the tab chrome entirely.
			$this->render_home_tab( $id, $customer );
			return;
		}

		$job_ids    = wp_list_pluck( KCRM_Customer::jobs_for( $customer->id ), 'id' );
		$rollup_ids = array_merge( array( $customer->id ), $job_ids );

		$tabs = array(
			'home' => array(
				'label'  => __( 'Home', 'karks-crm' ),
				'render' => function () use ( $id, $customer ) {
					$this->render_home_tab( $id, $customer );
				},
			),
		);

		if ( ! $customer->parent_customer_id ) {
			$tabs['jobs'] = array(
				'label'  => __( 'Jobs', 'karks-crm' ),
				'badge'  => count( $job_ids ) ?: null,
				'render' => function () use ( $customer ) {
					$this->render_jobs_section( $customer );
				},
			);
		}

		$tabs['billing'] = array(
			'label'  => __( 'Invoices & Payments', 'karks-crm' ),
			'badge'  => count( KCRM_Invoice::open_for_customers( $rollup_ids ) ) ?: null,
			'render' => function () use ( $rollup_ids, $customer, $job_ids ) {
				$this->render_revenue_section( $rollup_ids, $customer->id, ! empty( $job_ids ) );
				$this->render_invoices_section( $rollup_ids, $customer->id, ! empty( $job_ids ) );
				$this->render_payments_section( $rollup_ids, ! empty( $job_ids ) );
				$this->render_receive_payment_section( $rollup_ids, $customer->id );
			},
		);

		/**
		 * Filters the tabs shown on the front-end customer profile screen --
		 * lets an add-on (e.g. Karks CRM Packages) contribute its own tab
		 * instead of only being able to append content at the very end via
		 * kcrm_customer_edit_after_sections. See wiki/Hooks-and-Filters.md.
		 *
		 * @param array  $tabs       tab_slug => array( 'label' => string, 'badge' => int|string|null, 'render' => callable(): void ).
		 * @param object $customer   The KCRM_Customer row being viewed.
		 * @param int[]  $rollup_ids The customer's own ID plus any Job IDs rolled up under it.
		 */
		$tabs = apply_filters( 'kcrm_customer_profile_tabs', $tabs, $customer, $rollup_ids );
		$tabs = array_filter(
			$tabs,
			static function ( $tab ) {
				return is_array( $tab ) && ! empty( $tab['label'] ) && is_callable( $tab['render'] ?? null );
			}
		);

		$this->render_profile_tabs( $tabs, $id );

		do_action( 'kcrm_customer_edit_after_sections', $customer, $rollup_ids );
	}

	/**
	 * Renders the tab nav plus the active tab's panel content. Tabs are
	 * plain links carrying a `tab` query arg rather than JS-driven show/hide,
	 * matching every other filter/sort control in this plugin -- the active
	 * tab survives a reload/bookmark for free since it lives in the URL.
	 *
	 * @param array $tabs tab_slug => array( 'label' => string, 'badge' => int|string|null, 'render' => callable(): void ).
	 */
	private function render_profile_tabs( array $tabs, $id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab-routing param, no state change.
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = array_key_first( $tabs );
		}
		?>
		<div class="kcrm-profile-tabs">
			<nav class="kcrm-profile-tablist">
				<?php foreach ( $tabs as $key => $tab ) : ?>
					<a class="kcrm-profile-tab<?php echo $key === $active ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $id, 'tab' => $key ) ) ); ?>">
						<?php echo esc_html( $tab['label'] ); ?>
						<?php if ( ! empty( $tab['badge'] ) ) : ?>
							<span class="kcrm-profile-tab-badge"><?php echo esc_html( $tab['badge'] ); ?></span>
						<?php endif; ?>
					</a>
				<?php endforeach; ?>
			</nav>
			<div class="kcrm-profile-tab-panel">
				<?php call_user_func( $tabs[ $active ]['render'] ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * The customer's own contact fields and "Send Invoices To" settings --
	 * the Home tab on the profile screen (or the whole page, unwrapped, when
	 * adding a brand-new customer that doesn't have tabs yet).
	 */
	private function render_home_tab( $id, $customer ) {
		$v = function ( $field, $default = '' ) use ( $customer ) {
			return $customer ? $customer->$field : $default;
		};
		$notes = $customer ? $customer->notes : '';

		$has_jobs = $id ? ! empty( KCRM_Customer::jobs_for( $id ) ) : false;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$preselect_parent = isset( $_GET['parent_id'] ) ? absint( $_GET['parent_id'] ) : ( $customer ? (int) $customer->parent_customer_id : 0 );
		$parent_options   = $has_jobs ? array() : KCRM_Customer::top_level_for_company( $this->current_company_id() );
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_save_customer' ); ?>
			<input type="hidden" name="kcrm_action" value="save_customer">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<p>
				<label for="status"><?php esc_html_e( 'Status', 'karks-crm' ); ?></label>
				<select name="status" id="status">
					<?php foreach ( KCRM_Customer::statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $customer ? $customer->status : KCRM_Customer::STATUS_ACTIVE, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php if ( ! $has_jobs ) : ?>
				<p>
					<label for="parent_customer_id"><?php esc_html_e( 'This is a Job of', 'karks-crm' ); ?></label>
					<select name="parent_customer_id" id="parent_customer_id">
						<option value="0"><?php esc_html_e( '— None (top-level customer) —', 'karks-crm' ); ?></option>
						<?php foreach ( $parent_options as $option ) : ?>
							<?php if ( (int) $option->id === $id ) { continue; } ?>
							<option value="<?php echo esc_attr( $option->id ); ?>" <?php selected( $preselect_parent, (int) $option->id ); ?>>
								<?php echo esc_html( $option->company_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<br><small><?php esc_html_e( 'Optional. Use this for a specific project or division under an existing customer (like QuickBooks Jobs) — its own address, invoices, and revenue are tracked separately, and roll up into the parent customer\'s totals.', 'karks-crm' ); ?></small>
				</p>
			<?php endif; ?>
			<p>
				<label for="company_name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label>
				<input type="text" name="company_name" id="company_name" value="<?php echo esc_attr( $v( 'company_name' ) ); ?>" required>
			</p>
			<p>
				<label for="contact_person"><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></label>
				<input type="text" name="contact_person" id="contact_person" value="<?php echo esc_attr( $v( 'contact_person' ) ); ?>">
			</p>
			<p>
				<label for="secondary_contact_person"><?php esc_html_e( 'Secondary Contact Person', 'karks-crm' ); ?></label>
				<input type="text" name="secondary_contact_person" id="secondary_contact_person" value="<?php echo esc_attr( $v( 'secondary_contact_person' ) ); ?>">
			</p>
			<p>
				<label for="address_street"><?php esc_html_e( 'Street Address', 'karks-crm' ); ?></label>
				<input type="text" name="address_street" id="address_street" value="<?php echo esc_attr( $v( 'address_street' ) ); ?>">
			</p>
			<p>
				<label for="address_street_2"><?php esc_html_e( 'Street Address 2', 'karks-crm' ); ?></label>
				<input type="text" name="address_street_2" id="address_street_2" value="<?php echo esc_attr( $v( 'address_street_2' ) ); ?>">
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
				<label for="address_country"><?php esc_html_e( 'Country', 'karks-crm' ); ?></label>
				<select name="address_country" id="address_country">
					<?php foreach ( KCRM_Countries::list() as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $v( 'address_country', KCRM_Countries::DEFAULT_CODE ), $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="phone"><?php esc_html_e( 'Phone Number', 'karks-crm' ); ?></label>
				<input type="text" name="phone" id="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>">
			</p>
			<p>
				<label for="email"><?php esc_html_e( 'Email Address', 'karks-crm' ); ?></label>
				<input type="email" name="email" id="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>">
			</p>
			<p>
				<label for="secondary_email"><?php esc_html_e( 'Secondary Email Address', 'karks-crm' ); ?></label>
				<input type="email" name="secondary_email" id="secondary_email" value="<?php echo esc_attr( $v( 'secondary_email' ) ); ?>">
			</p>

			<h3><?php esc_html_e( 'Send Invoices To', 'karks-crm' ); ?></h3>
			<p>
				<label for="invoice_recipient_name"><?php esc_html_e( 'Name', 'karks-crm' ); ?></label>
				<input type="text" name="invoice_recipient_name" id="invoice_recipient_name" value="<?php echo esc_attr( $v( 'invoice_recipient_name' ) ); ?>">
			</p>
			<p>
				<label for="invoice_recipient_email"><?php esc_html_e( 'Email', 'karks-crm' ); ?></label>
				<input type="email" name="invoice_recipient_email" id="invoice_recipient_email" value="<?php echo esc_attr( $v( 'invoice_recipient_email' ) ); ?>">
				<br><small><?php esc_html_e( 'Optional. If set, the Email Invoice form defaults to this name and address instead of the primary contact.', 'karks-crm' ); ?></small>
			</p>

			<p>
				<label for="notes"><?php esc_html_e( 'Notes', 'karks-crm' ); ?></label>
				<textarea rows="4" name="notes" id="notes"><?php echo esc_textarea( $notes ); ?></textarea>
			</p>

			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo esc_html( $id ? __( 'Update Customer', 'karks-crm' ) : __( 'Add Customer', 'karks-crm' ) ); ?></button></p>
		</form>
		<?php
	}

	private function render_jobs_section( $customer ) {
		$jobs = KCRM_Customer::jobs_for( $customer->id );
		?>
		<h3><?php esc_html_e( 'Jobs', 'karks-crm' ); ?></h3>
		<div class="kcrm-button-group">
			<a class="kcrm-button" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'add', 'parent_id' => $customer->id ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Job', 'karks-crm' ); ?></a>
		</div>
		<?php if ( $jobs ) : ?>
			<table class="kcrm-front-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'karks-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php $job_balances = KCRM_Customer::balances_for( wp_list_pluck( $jobs, 'id' ) ); ?>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $job->id ) ) ); ?>"><?php echo esc_html( $job->company_name ); ?></a></td>
							<td><?php echo esc_html( $job->contact_person ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $job_balances[ (int) $job->id ], 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param array $customer_ids The customer plus its Jobs (rolled up), when it has any.
	 * @param int   $primary_customer_id Used for the Open Balance export links.
	 */
	private function render_revenue_section( array $customer_ids, $primary_customer_id, $is_rollup ) {
		$this_year       = (int) current_time( 'Y' );
		$last_year       = $this_year - 1;
		$this_year_total = KCRM_Payment::total_for_customers_in_year( $customer_ids, $this_year );
		$last_year_total = KCRM_Payment::total_for_customers_in_year( $customer_ids, $last_year );
		$lifetime_total  = KCRM_Payment::total_for_customers( $customer_ids );
		$balance         = KCRM_Customer::balance_for_ids( $customer_ids );
		?>
		<h3><?php esc_html_e( 'Revenue', 'karks-crm' ); ?></h3>
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
		<div class="kcrm-button-group">
			<a class="kcrm-button" href="<?php echo esc_url( $this->open_balance_export_url( $primary_customer_id, 'pdf' ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export Open Balance PDF', 'karks-crm' ); ?></a>
			<a class="kcrm-button" href="<?php echo esc_url( $this->open_balance_export_url( $primary_customer_id, 'csv' ) ); ?>"><span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export Open Balance CSV', 'karks-crm' ); ?></a>
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
		list( $range, $date_from, $date_to ) = $this->resolve_date_range( 'kcrm_inv', 'all' );

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
		$invoices     = KCRM_Invoice::for_customers_with_statuses( $customer_ids, $statuses, $order_by_sql, $date_from, $date_to );
		$all_statuses = KCRM_Invoice::statuses();

		$balances = KCRM_Invoice::balances_for( $invoices );
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
		<h3><?php esc_html_e( 'Invoices', 'karks-crm' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Invoices with a status of Draft, Open, and Partially Paid are displayed by default.', 'karks-crm' ); ?></p>
		<?php if ( $is_rollup ) : ?>
			<p class="description"><?php esc_html_e( 'Includes this customer and all of its Jobs.', 'karks-crm' ); ?></p>
		<?php endif; ?>
		<?php $this->render_date_range_filter( 'kcrm_inv', $range, $date_from, $date_to ); ?>
		<div class="kcrm-button-group">
			<a class="kcrm-button" href="<?php echo esc_url( $toggle_url ); ?>">
				<?php
				echo $show_all
					? esc_html__( 'Show default statuses only (Draft, Open, Partially Paid)', 'karks-crm' )
					: esc_html__( 'Show invoices with all statuses', 'karks-crm' );
				?>
			</a>
			<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'add', 'customer_id' => $primary_customer_id ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
		</div>
		<table class="kcrm-front-table">
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
				<?php $billed_to_customers = $is_rollup ? KCRM_Customer::find_many( wp_list_pluck( $invoices, 'customer_id' ) ) : array(); ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php
					$balance         = $balances[ $invoice->id ];
					$total_balance  += $balance;
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>">
									<?php echo esc_html( $invoice->invoice_number ); ?>
								</a>
							</strong>
						</td>
						<?php if ( $is_rollup ) : ?>
							<td>
								<?php
								$billed_to = (int) $invoice->customer_id === (int) $primary_customer_id ? null : ( $billed_to_customers[ (int) $invoice->customer_id ] ?? null );
								echo esc_html( $billed_to ? $billed_to->company_name : __( '(this customer)', 'karks-crm' ) );
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $invoice->issue_date ); ?></td>
						<td><?php echo esc_html( $invoice->due_date ); ?></td>
						<td><?php echo esc_html( KCRM_Invoice::format_money( (float) $invoice->total ) ); ?></td>
						<td><?php echo esc_html( KCRM_Invoice::format_money( $balance ) ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( $all_statuses[ $invoice->status ] ?? $invoice->status ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $invoices ) ) : ?>
			<p><strong><?php esc_html_e( 'Balance Owed (invoices shown above):', 'karks-crm' ); ?></strong> <?php echo esc_html( KCRM_Invoice::format_money( $total_balance ) ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** @param array $customer_ids The customer plus its Jobs (rolled up), when it has any. */
	private function render_payments_section( array $customer_ids, $is_rollup ) {
		list( $range, $date_from, $date_to ) = $this->resolve_date_range( 'kcrm_pay', 'this_year' );

		$per_page     = 10;
		$total        = KCRM_Payment::count_for_customers( $customer_ids, $date_from, $date_to );
		$total_pages  = (int) ceil( $total / $per_page );
		$current_page = $this->current_page_number( 'kcrm_pg', $total_pages );
		$offset       = ( $current_page - 1 ) * $per_page;

		$payments = KCRM_Payment::for_customers( $customer_ids, $per_page, $offset, $date_from, $date_to );
		?>
		<h3 id="kcrm-payments-received"><?php esc_html_e( 'Payments Received', 'karks-crm' ); ?></h3>
		<?php if ( $is_rollup ) : ?>
			<p class="description"><?php esc_html_e( 'Includes this customer and all of its Jobs.', 'karks-crm' ); ?></p>
		<?php endif; ?>
		<?php $this->render_date_range_filter( 'kcrm_pay', $range, $date_from, $date_to ); ?>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Method', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Note', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $payments ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No payments recorded yet.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php $payment_invoices = KCRM_Invoice::find_many( wp_list_pluck( $payments, 'invoice_id' ) ); ?>
				<?php foreach ( $payments as $payment ) : ?>
					<?php $invoice = $payment_invoices[ (int) $payment->invoice_id ] ?? null; ?>
					<tr>
						<td><?php echo esc_html( $payment->payment_date ); ?></td>
						<td>
							<?php if ( $invoice ) : ?>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>"><?php echo esc_html( $invoice->invoice_number ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $payment->method ); ?></td>
						<td>
							<?php echo esc_html( $payment->note ); ?>
							<?php if ( ! empty( $payment->batch_id ) ) : ?>
								<br><small><?php esc_html_e( 'Split payment', 'karks-crm' ); ?></small>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (float) $payment->amount, 2 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php $this->render_pagination( $current_page, $total_pages, 'kcrm_pg', 'kcrm-payments-received' ); ?>
		<?php
	}

	/**
	 * "Receive Payment" -- splits one payment across several open invoices
	 * for this customer and its Jobs in a single submission (QuickBooks'
	 * "Receive Payment" workflow). Every row this creates is a completely
	 * normal single-invoice payment (see
	 * KCRM_Customers_Controller::receive_payment()); entering an amount for
	 * more than one invoice just means several such rows get created
	 * together, sharing a batch_id so they're traceable as one submission.
	 *
	 * @param array $customer_ids The customer plus its Jobs (rolled up), when it has any.
	 */
	private function render_receive_payment_section( array $customer_ids, $primary_customer_id ) {
		$invoices = KCRM_Invoice::for_customers_with_statuses( $customer_ids, KCRM_Invoice::default_customer_statuses(), 'issue_date ASC' );
		$balances = KCRM_Invoice::balances_for( $invoices );
		$invoices = array_values(
			array_filter(
				$invoices,
				function ( $invoice ) use ( $balances ) {
					return $balances[ $invoice->id ] > 0.005;
				}
			)
		);

		if ( empty( $invoices ) ) {
			return;
		}

		$is_rollup            = count( $customer_ids ) > 1;
		$billed_to_customers  = $is_rollup ? KCRM_Customer::find_many( wp_list_pluck( $invoices, 'customer_id' ) ) : array();
		$company              = KCRM_Company::find( $this->current_company_id() );
		?>
		<h3><?php esc_html_e( 'Receive Payment', 'karks-crm' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Enter a payment amount and split it across as many of these invoices as you like — amounts default to oldest-invoice-first once you enter a total, and can be edited per invoice before saving.', 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form" id="kcrm-receive-payment-form">
			<?php wp_nonce_field( 'kcrm_receive_payment' ); ?>
			<input type="hidden" name="kcrm_action" value="receive_payment">
			<input type="hidden" name="customer_id" value="<?php echo esc_attr( $primary_customer_id ); ?>">

			<p>
				<label for="kcrm-receive-payment-total"><?php esc_html_e( 'Payment Amount', 'karks-crm' ); ?></label>
				<input type="number" step="0.01" min="0" id="kcrm-receive-payment-total">
				<button type="button" class="kcrm-button" id="kcrm-receive-payment-autofill"><?php esc_html_e( 'Auto-fill (oldest first)', 'karks-crm' ); ?></button>
			</p>

			<table class="kcrm-front-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
						<?php if ( $is_rollup ) : ?>
							<th><?php esc_html_e( 'Billed To', 'karks-crm' ); ?></th>
						<?php endif; ?>
						<th><?php esc_html_e( 'Balance Due', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Amount to Apply', 'karks-crm' ); ?></th>
					</tr>
				</thead>
				<tbody id="kcrm-receive-payment-body">
					<?php foreach ( $invoices as $invoice ) : ?>
						<?php $balance = $balances[ $invoice->id ]; ?>
						<tr>
							<td>
								<input type="hidden" name="invoice_id[]" value="<?php echo esc_attr( $invoice->id ); ?>">
								<?php echo esc_html( $invoice->invoice_number ); ?>
							</td>
							<?php if ( $is_rollup ) : ?>
								<td>
									<?php
									$billed_to = (int) $invoice->customer_id === (int) $primary_customer_id ? null : ( $billed_to_customers[ (int) $invoice->customer_id ] ?? null );
									echo esc_html( $billed_to ? $billed_to->company_name : __( '(this customer)', 'karks-crm' ) );
									?>
								</td>
							<?php endif; ?>
							<td class="kcrm-receive-payment-balance" data-balance="<?php echo esc_attr( $balance ); ?>"><?php echo esc_html( KCRM_Invoice::format_money( $balance ) ); ?></td>
							<td><input type="number" step="0.01" min="0" max="<?php echo esc_attr( $balance ); ?>" name="allocation_amount[]" class="kcrm-receive-payment-amount"></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><?php esc_html_e( 'Total allocated:', 'karks-crm' ); ?> <strong id="kcrm-receive-payment-allocated">0.00</strong></p>

			<p>
				<label for="payment_date"><?php esc_html_e( 'Date', 'karks-crm' ); ?></label>
				<input type="date" name="payment_date" id="payment_date" value="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>" required>
			</p>
			<?php $kcrm_accepted_type_keys = KCRM_Company::accepted_payment_type_keys( $company ); ?>
			<?php if ( ! empty( $kcrm_accepted_type_keys ) ) : ?>
				<?php $kcrm_all_types = KCRM_Company::payment_types(); ?>
				<p>
					<label for="method"><?php esc_html_e( 'Method', 'karks-crm' ); ?></label>
					<select name="method" id="method">
						<?php foreach ( $kcrm_accepted_type_keys as $kcrm_type_key ) : ?>
							<option value="<?php echo esc_attr( $kcrm_all_types[ $kcrm_type_key ] ?? $kcrm_type_key ); ?>"><?php echo esc_html( $kcrm_all_types[ $kcrm_type_key ] ?? $kcrm_type_key ); ?></option>
						<?php endforeach; ?>
						<option value="__other__"><?php esc_html_e( 'Other…', 'karks-crm' ); ?></option>
					</select>
				</p>
				<p id="kcrm-method-other-row" style="display:none;">
					<label for="method_other"><?php esc_html_e( 'Other Method', 'karks-crm' ); ?></label>
					<input type="text" name="method_other" id="method_other">
				</p>
			<?php else : ?>
				<p>
					<label for="method"><?php esc_html_e( 'Method', 'karks-crm' ); ?></label>
					<input type="text" name="method" id="method" placeholder="<?php esc_attr_e( 'e.g. Check, ACH, Credit Card', 'karks-crm' ); ?>">
				</p>
			<?php endif; ?>
			<p>
				<label for="note"><?php esc_html_e( 'Note', 'karks-crm' ); ?></label>
				<input type="text" name="note" id="note">
			</p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Record Payment', 'karks-crm' ); ?></button></p>
		</form>
		<?php
	}
}
