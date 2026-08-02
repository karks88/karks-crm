<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Front_Invoices extends KCRM_Invoices_Controller {

	use KCRM_Front_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="kcrm-front-screen">';
		$this->render_company_header();
		$this->render_heading( $view );

		if ( 'list' === $view ) {
			printf( '<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s"><span class="dashicons dashicons-plus-alt2"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-upload"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'import_invoices' ) ) ), esc_html__( 'Import Invoices', 'karks-crm' ) );
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-upload"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'import_payments' ) ) ), esc_html__( 'Import Payments', 'karks-crm' ) );
			// Invoice Types is a global (not company-scoped) wp-admin-only settings screen -- see KCRM_Admin_Invoice_Types -- so this crosses over to wp-admin rather than a front-end view. Every user who can reach this screen already has the wp-admin capabilities needed to load it.
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-category"></span> %s</a></div>', esc_url( admin_url( 'admin.php?page=' . KCRM_Admin_Invoice_Types::PAGE ) ), esc_html__( 'Invoice Types', 'karks-crm' ) );
		}

		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p></div>';
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

	/** Renders the H2 -- linked back to the list once we're off it, with the invoice number appended when editing one. */
	private function render_heading( $view ) {
		$label = __( 'Invoices', 'karks-crm' );

		if ( 'list' === $view ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';
			return;
		}

		$link = sprintf( '<a href="%s">%s</a>', esc_url( $this->screen_url() ), esc_html( $label ) );

		if ( 'edit' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
			$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$invoice = $id ? KCRM_Invoice::find( $id ) : null;

			if ( $invoice ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
			echo '<h2>' . $link . ': ' . esc_html( $invoice->invoice_number ) . '</h2>';
				return;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
		echo '<h2>' . $link . '</h2>';
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
					/* translators: %d: number of invoices skipped due to missing customer name, issue date, or every one of its rows having an unusable amount. */
					array( 'skipped_missing', __( '%d invoices skipped — missing customer name, issue date, or amount.', 'karks-crm' ) ),
					/* translators: %d: number of individual line items skipped within an otherwise-imported invoice, due to an unusable amount on that row. */
					array( 'skipped_lines', __( '%d line items skipped within imported invoices — unusable amount on that row.', 'karks-crm' ) ),
					/* translators: %d: number of new services created because the mapped service name didn't match an existing one. */
					array( 'services_created', __( '%d new services created.', 'karks-crm' ) ),
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
				__( 'Choose which column maps to each invoice field. Rows sharing the same Invoice Number are combined into one invoice with a line item per row — handy for an hourly client billed out by day or task; rows with no invoice number each become their own single-line invoice instead, with a number auto-assigned. If you map a Service column, each row is matched to an existing service by name (case-insensitive); if no match is found, a new service is created automatically using that name and the row\'s amount as its rate. Status starts as Open and moves to Partially Paid/Paid automatically once you import the matching payments below; map the status column only to flag rows as Draft or Void.', 'karks-crm' )
			);
		} else {
			$this->render_import_upload(
				'kcrm_import_invoices_upload',
				'import_invoices_upload',
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
					/* translators: %d: number of rows skipped because a payment with the same invoice, date, and amount was already recorded. */
					array( 'skipped_duplicate', __( '%d rows skipped — a payment with the same invoice, date, and amount already exists.', 'karks-crm' ) ),
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
				__( "Choose which column maps to each payment field. Each row is matched to an existing invoice by its invoice number, and that invoice's status updates automatically based on the payments recorded against it.", 'karks-crm' )
			);
		} else {
			$this->render_import_upload(
				'kcrm_import_payments_upload',
				'import_payments_upload',
				__( "Upload a CSV export and you'll be able to choose which columns map to which fields before anything is imported. Import your invoices first if you haven't already — each row is matched to an existing invoice by number.", 'karks-crm' )
			);
		}
	}

	private function render_import_upload( $nonce_action, $kcrm_action, $description ) {
		?>
		<p><?php echo esc_html( $description ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" enctype="multipart/form-data" class="kcrm-front-form">
			<?php wp_nonce_field( $nonce_action ); ?>
			<input type="hidden" name="kcrm_action" value="<?php echo esc_attr( $kcrm_action ); ?>">
			<p>
				<label for="import_file"><?php esc_html_e( 'CSV File', 'karks-crm' ); ?></label>
				<input type="file" name="import_file" id="import_file" accept=".csv" required>
			</p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Upload & Continue', 'karks-crm' ); ?></button></p>
		</form>
		<?php
	}

	private function render_import_map( $token, array $fields, $view, $nonce_action, $kcrm_action, $description ) {
		$path = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			echo '<p>' . esc_html__( 'That upload could not be found — it may have expired. Please upload the file again.', 'karks-crm' ) . '</p>';
			printf( '<div class="kcrm-button-group"><a class="kcrm-button" href="%s">%s</a></div>', esc_url( $this->screen_url( array( 'view' => $view ) ) ), esc_html__( 'Start Over', 'karks-crm' ) );
			return;
		}

		$header = KCRM_CSV_Import::read_header( $path );
		?>
		<p><?php echo esc_html( $description ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( $nonce_action ); ?>
			<input type="hidden" name="kcrm_action" value="<?php echo esc_attr( $kcrm_action ); ?>">
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
			<p class="description">* <?php esc_html_e( 'Required. Rows with a blank value in this column are skipped.', 'karks-crm' ); ?></p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Import', 'karks-crm' ); ?></button></p>
		</form>
		<?php
	}

	private function render_import_done( array $rows ) {
		?>
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
		<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->screen_url() ); ?>"><?php esc_html_e( 'View Invoices', 'karks-crm' ); ?></a></div>
		<?php
	}

	private function render_list() {
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
		$statuses     = KCRM_Invoice::statuses();
		$company_id   = $this->current_company_id();
		$per_page     = 200;

		list( $selected_statuses, $status_filtered ) = $this->resolve_status_filter( 'kcrm_status', $statuses, KCRM_Invoice::default_customer_statuses() );

		/*
		 * Fetch every matching invoice for the company once, then split it into
		 * "grouped" (top-level customers that have Jobs -- their own invoices plus
		 * every Job's, kept together instead of being scattered across the sorted
		 * list by date) and "flat" (everyone else, listed/sorted/paginated same as
		 * before). Company-wide invoice counts are modest enough that fetching
		 * everything up front and doing the split/sort/paginate in PHP is simpler
		 * than juggling two separate SQL paths -- the same tradeoff the balance_due
		 * sort already made.
		 */
		$all_invoices = KCRM_Invoice::for_company_with_statuses( $company_id, $selected_statuses, $order_by_sql );
		$balances     = KCRM_Invoice::balances_for( $all_invoices );

		$top_level_customers = KCRM_Customer::top_level_for_company( $company_id );
		$jobs_by_parent       = KCRM_Customer::jobs_for_many( wp_list_pluck( $top_level_customers, 'id' ) );

		$groups           = array(); // top-level customer id => array( 'customer' => obj, 'invoices' => [] ).
		$job_parent_lookup = array(); // job customer id => its top-level parent's id.
		foreach ( $top_level_customers as $customer ) {
			$jobs = $jobs_by_parent[ (int) $customer->id ] ?? array();
			if ( count( $jobs ) < 2 ) {
				continue;
			}
			$groups[ (int) $customer->id ] = array(
				'customer' => $customer,
				'invoices' => array(),
			);
			foreach ( $jobs as $job ) {
				$job_parent_lookup[ (int) $job->id ] = (int) $customer->id;
			}
		}

		$flat_invoices = array();
		foreach ( $all_invoices as $invoice ) {
			$cid = (int) $invoice->customer_id;
			if ( isset( $groups[ $cid ] ) ) {
				$groups[ $cid ]['invoices'][] = $invoice;
			} elseif ( isset( $job_parent_lookup[ $cid ] ) ) {
				$groups[ $job_parent_lookup[ $cid ] ]['invoices'][] = $invoice;
			} else {
				$flat_invoices[] = $invoice;
			}
		}

		// Only surface a group once it actually has a matching invoice -- a Jobs customer with nothing Open/Draft/Partial right now shouldn't clutter the section.
		$groups = array_filter(
			$groups,
			function ( $group ) {
				return ! empty( $group['invoices'] );
			}
		);

		// Sort each group's own invoices by issue date (newest first) regardless of the flat table's active sort -- it's a chronological history of that customer's work, not a sortable list.
		foreach ( $groups as &$group ) {
			usort(
				$group['invoices'],
				function ( $a, $b ) {
					$cmp = strcmp( $b->issue_date, $a->issue_date );
					return 0 !== $cmp ? $cmp : ( (int) $b->id - (int) $a->id );
				}
			);
		}
		unset( $group );

		uasort(
			$groups,
			function ( $a, $b ) {
				return strcasecmp( $a['customer']->company_name, $b['customer']->company_name );
			}
		);

		if ( 'balance_due' === $orderby ) {
			// balance_due isn't a DB column, so sorting by it needs every matching invoice in memory first (can't LIMIT before sorting).
			usort(
				$flat_invoices,
				function ( $a, $b ) use ( $balances, $order ) {
					$diff = $balances[ $a->id ] <=> $balances[ $b->id ];
					return 'DESC' === $order ? -$diff : $diff;
				}
			);
		}
		// For every other column, $flat_invoices is already in the right order: it was filtered out of $all_invoices, which the query already sorted by $order_by_sql.

		$total        = count( $flat_invoices );
		$total_pages  = (int) ceil( $total / $per_page );
		$current_page = $this->current_page_number( 'kcrm_pg', $total_pages );
		$invoices     = array_slice( $flat_invoices, ( $current_page - 1 ) * $per_page, $per_page );

		$sort_url = function ( $column ) use ( $orderby, $order ) {
			return add_query_arg(
				array(
					'orderby' => $column,
					'order'   => ( $column === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
				)
			);
		};
		$list_customers = KCRM_Customer::find_many( wp_list_pluck( $all_invoices, 'customer_id' ) );
		?>
		<p class="description"><?php esc_html_e( 'Draft, Open, and Partially Paid invoices are shown by default.', 'karks-crm' ); ?></p>
		<?php $this->render_status_filter( 'kcrm_status', $statuses, $selected_statuses ); ?>

		<?php if ( ! empty( $groups ) ) : ?>
			<h3><?php esc_html_e( 'Customers with Multiple Jobs', 'karks-crm' ); ?></h3>
			<table class="kcrm-front-table" id="kcrm-front-invoice-groups-table">
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
					<?php foreach ( $groups as $group_id => $group ) : ?>
						<?php
						$group_balance = 0.0;
						foreach ( $group['invoices'] as $invoice ) {
							$group_balance += $balances[ $invoice->id ];
						}
						?>
						<tr class="kcrm-invoice-group-header">
							<td colspan="8">
								<a href="#" class="kcrm-invoice-group-toggle" data-kcrm-invoice-group="<?php echo esc_attr( $group_id ); ?>">
									<span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span>
								</a>
								<strong><a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers', array( 'view' => 'edit', 'id' => $group['customer']->id ) ) ); ?>"><?php echo esc_html( $group['customer']->company_name ); ?></a></strong>
								&nbsp;&mdash;&nbsp;
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: number of invoices in this customer's group, 2: total balance due across them. */
										_n( '%1$d invoice, %2$s due', '%1$d invoices, %2$s due', count( $group['invoices'] ), 'karks-crm' ),
										count( $group['invoices'] ),
										KCRM_Invoice::format_money( $group_balance )
									)
								);
								?>
							</td>
						</tr>
						<?php foreach ( $group['invoices'] as $invoice ) : ?>
							<?php
							$customer = $list_customers[ (int) $invoice->customer_id ] ?? null;
							$is_job   = (int) $invoice->customer_id !== (int) $group_id;
							?>
							<?php $this->render_invoice_row( $invoice, $customer, $balances[ $invoice->id ], $statuses, $is_job, $group_id ); ?>
						<?php endforeach; ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<h3><?php esc_html_e( 'All Other Invoices', 'karks-crm' ); ?></h3>
		<?php endif; ?>

		<?php if ( ! empty( $invoices ) ) : ?>
			<p class="kcrm-list-search">
				<label for="kcrm-invoice-search" class="screen-reader-text"><?php esc_html_e( 'Search invoices', 'karks-crm' ); ?></label>
				<input type="search" id="kcrm-invoice-search" class="kcrm-instant-search" data-kcrm-search-table="kcrm-front-invoices-table" data-kcrm-search-empty="<?php esc_attr_e( 'No invoices match your search.', 'karks-crm' ); ?>" placeholder="<?php esc_attr_e( 'Search by invoice # or customer…', 'karks-crm' ); ?>">
			</p>
		<?php endif; ?>
		<table class="kcrm-front-table" id="kcrm-front-invoices-table">
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
					<th><?php esc_html_e( 'Customer', 'karks-crm' ); ?></th>
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
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $invoices ) ) : ?>
					<tr><td colspan="8"><?php echo esc_html( $status_filtered ? __( 'No invoices match the selected statuses.', 'karks-crm' ) : __( 'No invoices match the default statuses (Draft, Open, Partially Paid).', 'karks-crm' ) ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php $customer = $list_customers[ (int) $invoice->customer_id ] ?? null; ?>
					<?php $this->render_invoice_row( $invoice, $customer, $balances[ $invoice->id ], $statuses ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php $this->render_pagination( $current_page, $total_pages, 'kcrm_pg', 'kcrm-front-invoices-table' ); ?>
		<?php
	}

	/** One <tr> for the invoices list table -- shared by the flat table and the "Customers with Multiple Jobs" grouped section in render_list(). */
	private function render_invoice_row( $invoice, $customer, $balance, array $statuses, $is_job = false, $group_id = null ) {
		$attrs = array();
		if ( $is_job ) {
			$attrs[] = 'class="kcrm-job-row"';
		}
		if ( null !== $group_id ) {
			$attrs[] = 'data-kcrm-invoice-group="' . esc_attr( $group_id ) . '"';
		}
		?>
		<tr<?php echo $attrs ? ' ' . implode( ' ', $attrs ) : ''; ?>>
			<td>
				<?php if ( $is_job ) : ?>&#8627; <?php endif; ?>
				<strong>
					<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>">
						<?php echo esc_html( $invoice->invoice_number ); ?>
					</a>
				</strong>
			</td>
			<td>
				<?php if ( $customer ) : ?>
					<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers', array( 'view' => 'edit', 'id' => $customer->id ) ) ); ?>"><?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?></a>
				<?php endif; ?>
			</td>
			<td><?php echo esc_html( $invoice->issue_date ); ?></td>
			<td><?php echo esc_html( $invoice->due_date ); ?></td>
			<td><?php echo esc_html( KCRM_Invoice::format_money( (float) $invoice->total ) ); ?></td>
			<td><?php echo esc_html( KCRM_Invoice::format_money( $balance ) ); ?></td>
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

		$company_id       = $this->current_company_id();
		$company          = KCRM_Company::find( $company_id );
		$customers        = KCRM_Customer::for_company( $company_id );
		$services         = KCRM_Service::active_for_company( $company_id );
		$items            = $id ? KCRM_Invoice_Item::for_invoice( $id ) : array();
		$invoice_customer = $invoice ? KCRM_Customer::find( $invoice->customer_id ) : null;
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
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" id="kcrm-invoice-form" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_save_invoice' ); ?>
			<input type="hidden" name="kcrm_action" value="save_invoice">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<p>
				<label for="invoice_type"><?php esc_html_e( 'Invoice Type', 'karks-crm' ); ?></label>
				<select name="invoice_type" id="invoice_type">
					<?php foreach ( KCRM_Invoice::types() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $invoice ? $invoice->invoice_type : KCRM_Invoice::TYPE_OTHER, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php
			$month_year_value = ( $invoice && $invoice->invoice_type_month ) ? $invoice->invoice_type_month : '';
			$selected_month   = $month_year_value ? substr( $month_year_value, 5, 2 ) : '';
			$selected_year    = $month_year_value ? substr( $month_year_value, 0, 4 ) : '';
			?>
			<p id="kcrm-invoice-type-month-row">
				<label for="invoice_type_month_month"><?php esc_html_e( 'Month/Year', 'karks-crm' ); ?></label>
				<select name="invoice_type_month_month" id="invoice_type_month_month">
					<option value=""><?php esc_html_e( 'Month', 'karks-crm' ); ?></option>
					<?php foreach ( $this->month_options() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected_month, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<select name="invoice_type_month_year" id="invoice_type_month_year">
					<option value=""><?php esc_html_e( 'Year', 'karks-crm' ); ?></option>
					<?php foreach ( $this->year_options() as $year ) : ?>
						<option value="<?php echo esc_attr( $year ); ?>" <?php selected( $selected_year, (string) $year ); ?>><?php echo esc_html( $year ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p id="kcrm-invoice-type-other-row">
				<label for="invoice_type_other"><?php esc_html_e( 'Custom Type', 'karks-crm' ); ?></label>
				<input type="text" name="invoice_type_other" id="invoice_type_other" value="<?php echo esc_attr( $invoice ? $invoice->invoice_type_other : '' ); ?>">
			</p>
			<?php if ( $invoice ) : ?>
				<p><strong><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?>:</strong> <?php echo esc_html( $invoice->invoice_number ); ?></p>
			<?php endif; ?>
			<p>
				<label for="customer_id"><?php esc_html_e( 'Customer', 'karks-crm' ); ?></label>
				<select name="customer_id" id="customer_id" required>
					<option value=""><?php esc_html_e( '— Select a customer —', 'karks-crm' ); ?></option>
					<?php foreach ( $customers as $customer ) : ?>
						<option value="<?php echo esc_attr( $customer->id ); ?>" <?php selected( $preselect_customer, (int) $customer->id ); ?>>
							<?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<?php if ( $invoice ) : ?>
					<a class="kcrm-button" href="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers', array( 'view' => 'edit', 'id' => $invoice->customer_id ) ) ); ?>"><?php esc_html_e( 'View Customer Profile', 'karks-crm' ); ?></a>
				<?php endif; ?>
			</p>
			<p>
				<label for="status"><?php esc_html_e( 'Status', 'karks-crm' ); ?></label>
				<select name="status" id="status">
					<?php foreach ( KCRM_Invoice::statuses() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $invoice ? $invoice->status : KCRM_Invoice::STATUS_OPEN, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<br><small><?php esc_html_e( 'Open/Partial/Paid update automatically as payments are recorded below.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="issue_date"><?php esc_html_e( 'Issue Date', 'karks-crm' ); ?></label>
				<input type="date" name="issue_date" id="issue_date" value="<?php echo esc_attr( $invoice ? $invoice->issue_date : gmdate( 'Y-m-d' ) ); ?>" required>
			</p>
			<p>
				<label for="due_date"><?php esc_html_e( 'Due Date', 'karks-crm' ); ?></label>
				<input type="date" name="due_date" id="due_date" value="<?php echo esc_attr( $invoice ? $invoice->due_date : '' ); ?>">
			</p>

			<h3><?php esc_html_e( 'Line Items', 'karks-crm' ); ?></h3>
			<table class="kcrm-front-table" id="kcrm-line-items">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Service', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Description', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Type', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Qty / Hours', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Rate', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Taxable', 'karks-crm' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody id="kcrm-line-items-body">
					<?php foreach ( $items as $item ) : ?>
						<?php $this->render_item_row( $item, $services ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p><button type="button" class="kcrm-button" id="kcrm-add-line"><?php esc_html_e( '+ Add Line', 'karks-crm' ); ?></button></p>

			<p>
				<strong><?php esc_html_e( 'Subtotal:', 'karks-crm' ); ?></strong> <span id="kcrm-subtotal">0.00</span>
			</p>
			<p>
				<label for="tax_rate"><?php esc_html_e( 'Tax Rate (%)', 'karks-crm' ); ?></label>
				<input type="number" step="0.001" min="0" name="tax_rate" id="tax_rate" value="<?php echo esc_attr( $invoice ? $invoice->tax_rate : ( $company ? $company->default_tax_rate : 0 ) ); ?>" style="max-width:100px;">
			</p>
			<p>
				<strong><?php esc_html_e( 'Total:', 'karks-crm' ); ?></strong> <span id="kcrm-total"><strong>0.00</strong></span>
			</p>

			<h3><?php esc_html_e( 'Notes', 'karks-crm' ); ?></h3>
			<textarea rows="3" name="notes"><?php echo $invoice ? esc_textarea( $invoice->notes ) : ''; ?></textarea>

			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo esc_html( $id ? __( 'Update Invoice', 'karks-crm' ) : __( 'Create Invoice', 'karks-crm' ) ); ?></button></p>
		</form>

		<script>
			window.kcrmServices = <?php echo wp_json_encode( $services_js ); ?>;
		</script>

		<?php if ( $invoice ) : ?>
			<?php $this->render_payments_section( $invoice, $company ); ?>
			<?php $this->render_payment_options_section( $company ); ?>
			<h3><?php esc_html_e( 'Actions', 'karks-crm' ); ?></h3>
			<div class="kcrm-button-group">
				<a class="kcrm-button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrm_download_invoice_pdf&id=' . $invoice->id ), 'kcrm_download_invoice_pdf_' . $invoice->id ) ); ?>">
					<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Download PDF Invoice', 'karks-crm' ); ?>
				</a>
				<a class="kcrm-button" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=kcrm_preview_invoice_html&id=' . $invoice->id ), 'kcrm_preview_invoice_html_' . $invoice->id ) ); ?>">
					<span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Preview', 'karks-crm' ); ?>
				</a>
				<button type="button" class="kcrm-button" id="kcrm-open-email-modal"><span class="dashicons dashicons-email"></span> <?php esc_html_e( 'Email Invoice', 'karks-crm' ); ?></button>
				<a class="kcrm-button" href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $invoice->id ) ), 'kcrm_delete_invoice_' . $invoice->id ) ); ?>"
					onclick="return confirm('<?php echo esc_js( __( 'Delete this invoice?', 'karks-crm' ) ); ?>');">
					<span class="dashicons dashicons-trash"></span> <?php esc_html_e( 'Delete Invoice', 'karks-crm' ); ?>
				</a>
			</div>
			<?php $this->render_last_emailed_note( $invoice->id ); ?>
			<?php $this->render_email_modal( $invoice, $invoice_customer, $company ); ?>
		<?php endif; ?>
		<?php
	}

	/** Read-only display of the company's accepted payment types/links, for reference while working an invoice. */
	private function render_payment_options_section( $company ) {
		$type_keys = KCRM_Company::accepted_payment_type_keys( $company );
		$links     = KCRM_Company::payment_links( $company );

		if ( empty( $type_keys ) && empty( $links ) ) {
			return;
		}

		$all_types = KCRM_Company::payment_types();
		?>
		<h3><?php esc_html_e( 'Payment Options', 'karks-crm' ); ?></h3>
		<?php if ( ! empty( $type_keys ) ) : ?>
			<p>
				<?php
				echo esc_html( implode( ', ', array_map( static function ( $key ) use ( $all_types ) {
					return $all_types[ $key ] ?? $key;
				}, $type_keys ) ) );
				?>
			</p>
		<?php endif; ?>
		<?php if ( in_array( 'check', $type_keys, true ) && ! empty( $company->check_payable_to ) ) : ?>
			<p><strong><?php esc_html_e( 'Make checks payable to:', 'karks-crm' ); ?></strong> <?php echo esc_html( $company->check_payable_to ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $links ) ) : ?>
			<div class="kcrm-button-group">
				<?php foreach ( $links as $link ) : ?>
					<?php if ( empty( $link['url'] ) ) { continue; } ?>
					<a class="kcrm-button" href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $link['label'] ? $link['label'] : $link['url'] ); ?></a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/** A small "Last emailed to X on Y" note if this invoice has ever been sent, above the modal/button. */
	private function render_last_emailed_note( $invoice_id ) {
		$last = KCRM_Invoice_Email::most_recent_for_invoice( $invoice_id );
		if ( ! $last ) {
			return;
		}
		$recipient = $last->sent_to_name ? "{$last->sent_to_name} <{$last->sent_to_email}>" : $last->sent_to_email;
		?>
		<p class="description">
			<?php
			$extra = array();
			if ( ! empty( $last->sent_cc ) ) {
				/* translators: %s: cc'd addresses. */
				$extra[] = sprintf( __( 'cc: %s', 'karks-crm' ), $last->sent_cc );
			}
			if ( ! empty( $last->sent_bcc ) ) {
				/* translators: %s: bcc'd address. */
				$extra[] = sprintf( __( 'bcc: %s', 'karks-crm' ), $last->sent_bcc );
			}

			if ( $extra ) {
				printf(
					/* translators: 1: recipient name/email, 2: cc/bcc details, 3: date and time. */
					esc_html__( 'Last emailed to %1$s (%2$s) on %3$s.', 'karks-crm' ),
					esc_html( $recipient ),
					esc_html( implode( '; ', $extra ) ),
					esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last->sent_at ) ) )
				);
			} else {
				printf(
					/* translators: 1: recipient name/email, 2: date and time. */
					esc_html__( 'Last emailed to %1$s on %2$s.', 'karks-crm' ),
					esc_html( $recipient ),
					esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $last->sent_at ) ) )
				);
			}
			?>
		</p>
		<?php
	}

	/**
	 * A mobile-friendly modal (hidden until "Email Invoice" is clicked --
	 * see assets/js/admin.js) that composes an HTML email for this invoice,
	 * pre-filled from the customer's contact info and the company's Email
	 * Invoice template (merge tags already resolved). Submitting it is a
	 * real form POST like every other action in this plugin, not AJAX --
	 * the page reloads with a success/error notice afterward.
	 */
	private function render_email_modal( $invoice, $customer, $company ) {
		$default_subject = KCRM_Invoice::display_title( $invoice, $customer );
		$default_body = KCRM_Merge_Tags::replace( KCRM_Company::email_template( $company ), $invoice, $customer, $company );

		// "Send Invoices To" on the customer profile overrides the primary contact when set.
		$default_to_name  = $customer && ! empty( $customer->invoice_recipient_name ) ? $customer->invoice_recipient_name : ( $customer ? $customer->contact_person : '' );
		$default_to_email = $customer && ! empty( $customer->invoice_recipient_email ) ? $customer->invoice_recipient_email : ( $customer ? $customer->email : '' );

		// Every other email address on file for this customer, offered as one-click CC suggestions -- the field itself starts blank.
		$cc_suggestions = array();
		if ( $customer ) {
			foreach ( array( $customer->email, $customer->secondary_email, $customer->invoice_recipient_email ) as $address ) {
				if ( $address && $address !== $default_to_email && ! in_array( $address, $cc_suggestions, true ) ) {
					$cc_suggestions[] = $address;
				}
			}
		}
		?>
		<div class="kcrm-modal-overlay" id="kcrm-email-modal" style="display:none;">
			<div class="kcrm-modal">
				<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
					<?php wp_nonce_field( 'kcrm_send_invoice_email_' . $invoice->id ); ?>
					<input type="hidden" name="kcrm_action" value="send_invoice_email">
					<input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice->id ); ?>">

					<h3><?php esc_html_e( 'Email Invoice', 'karks-crm' ); ?></h3>
					<p>
						<label for="email_to_name"><?php esc_html_e( 'To (Name)', 'karks-crm' ); ?></label>
						<input type="text" name="email_to_name" id="email_to_name" value="<?php echo esc_attr( $default_to_name ); ?>">
					</p>
					<p>
						<label for="email_to"><?php esc_html_e( 'To (Email)', 'karks-crm' ); ?></label>
						<input type="email" name="email_to" id="email_to" value="<?php echo esc_attr( $default_to_email ); ?>" required>
					</p>
					<p>
						<label for="email_cc"><?php esc_html_e( 'CC', 'karks-crm' ); ?></label>
						<input type="text" name="email_cc" id="email_cc" value="" placeholder="<?php esc_attr_e( 'cc@example.com, another@example.com', 'karks-crm' ); ?>">
						<?php if ( $cc_suggestions ) : ?>
							<br><small>
								<?php esc_html_e( 'Also on file:', 'karks-crm' ); ?>
								<?php foreach ( $cc_suggestions as $i => $address ) : ?>
									<?php echo $i > 0 ? ', ' : ''; ?><a href="#" class="kcrm-cc-suggestion" data-kcrm-cc-email="<?php echo esc_attr( $address ); ?>"><?php echo esc_html( $address ); ?></a>
								<?php endforeach; ?>
							</small>
						<?php endif; ?>
					</p>
					<p>
						<label for="email_subject"><?php esc_html_e( 'Subject', 'karks-crm' ); ?></label>
						<input type="text" name="email_subject" id="email_subject" value="<?php echo esc_attr( $default_subject ); ?>" required>
					</p>
					<p>
						<label for="email_body"><?php esc_html_e( 'Message (HTML)', 'karks-crm' ); ?></label>
						<textarea rows="8" name="email_body" id="email_body" required><?php echo esc_textarea( $default_body ); ?></textarea>
						<br><small><?php esc_html_e( 'The PDF invoice is attached automatically.', 'karks-crm' ); ?></small>
					</p>
					<div class="kcrm-button-group">
						<button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Send Email', 'karks-crm' ); ?></button>
						<button type="button" class="kcrm-button" id="kcrm-close-email-modal"><?php esc_html_e( 'Cancel', 'karks-crm' ); ?></button>
					</div>
				</form>
			</div>
		</div>
		<?php
	}

	private function render_item_row( $item, array $services ) {
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
					<?php foreach ( $services as $service ) : ?>
						<option value="<?php echo esc_attr( $service->id ); ?>" <?php selected( $service_id, (int) $service->id ); ?>><?php echo esc_html( $service->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" class="kcrm-item-description" name="item_description[]" value="<?php echo esc_attr( $description ); ?>"></td>
			<td>
				<select class="kcrm-item-type" name="item_type[]">
					<?php foreach ( KCRM_Service::types() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="number" step="0.01" min="0" class="kcrm-item-quantity" name="item_quantity[]" value="<?php echo esc_attr( $quantity ); ?>"></td>
			<td><input type="number" step="0.01" class="kcrm-item-rate" name="item_rate[]" value="<?php echo esc_attr( $rate ); ?>"></td>
			<td class="kcrm-item-amount">0.00</td>
			<td>
				<input type="hidden" class="kcrm-item-taxable-value" name="item_is_taxable[]" value="<?php echo $is_taxable ? '1' : '0'; ?>">
				<input type="checkbox" class="kcrm-item-taxable" <?php checked( $is_taxable ); ?>>
			</td>
			<td><button type="button" class="kcrm-remove-line" aria-label="<?php esc_attr_e( 'Remove line', 'karks-crm' ); ?>">&times;</button></td>
		</tr>
		<?php
	}

	private function render_payments_section( $invoice, $company ) {
		$payments = KCRM_Payment::for_invoice( $invoice->id );
		$balance  = KCRM_Invoice::balance_due( $invoice->id );
		?>
		<h3><?php esc_html_e( 'Payments', 'karks-crm' ); ?></h3>
		<p><strong><?php esc_html_e( 'Balance Due:', 'karks-crm' ); ?></strong> <?php echo esc_html( KCRM_Invoice::format_money( $balance ) ); ?></p>

		<table class="kcrm-front-table">
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

		<h4><?php esc_html_e( 'Record a Payment', 'karks-crm' ); ?></h4>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_add_payment' ); ?>
			<input type="hidden" name="kcrm_action" value="add_payment">
			<input type="hidden" name="invoice_id" value="<?php echo esc_attr( $invoice->id ); ?>">
			<p>
				<label for="amount"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></label>
				<input type="number" step="0.01" min="0.01" name="amount" id="amount" value="<?php echo esc_attr( max( 0, $balance ) ); ?>" required>
			</p>
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
