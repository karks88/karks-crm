<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-company Reports tab: Revenue (with a trailing-12-month bar chart),
 * a per-customer revenue report, and an Aging (accounts receivable) report.
 * Read-only -- no handle_actions() override needed, every filter/picker on
 * this screen is a GET form. CSV export is a separate admin-post handler
 * (handle_csv_export()) rather than a POST action, so it can stream a file
 * download instead of rendering HTML.
 */
class KCRM_Front_Reports extends KCRM_Controller_Base {

	use KCRM_Front_Screen_Trait;

	const ENDPOINT = 'reports';

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'overview';

		echo '<div class="kcrm-front-screen">';
		$this->render_company_header();
		$this->render_heading( $view );

		$company_id = $this->current_company_id();
		if ( ! $company_id ) {
			echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p></div>';
			return;
		}

		if ( 'revenue' === $view ) {
			$this->render_revenue_report( $company_id );
		} elseif ( 'customer' === $view ) {
			$this->render_customer_report( $company_id );
		} elseif ( 'aging' === $view ) {
			$this->render_aging_report( $company_id );
		} else {
			$this->render_overview( $company_id );
		}

		echo '</div>';
	}

	/** Renders the H2 -- linked back to the Reports overview once off it, with the specific report name appended. */
	private function render_heading( $view ) {
		$label = __( 'Reports', 'karks-crm' );

		if ( 'overview' === $view ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';
			return;
		}

		$sub_labels = array(
			'revenue'  => __( 'Revenue', 'karks-crm' ),
			'customer' => __( 'Customer Report', 'karks-crm' ),
			'aging'    => __( 'Aging', 'karks-crm' ),
		);

		$link = sprintf( '<a href="%s">%s</a>', esc_url( $this->screen_url() ), esc_html( $label ) );

		if ( isset( $sub_labels[ $view ] ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
			echo '<h2>' . $link . ': ' . esc_html( $sub_labels[ $view ] ) . '</h2>';
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
		echo '<h2>' . $link . '</h2>';
	}

	private function render_overview( $company_id ) {
		$current_year      = (int) current_time( 'Y' );
		$revenue_this_year = KCRM_Payment::total_for_company_in_year( $company_id, $current_year );

		$open_invoices = KCRM_Invoice::for_company_with_statuses( $company_id, array( KCRM_Invoice::STATUS_OPEN, KCRM_Invoice::STATUS_PARTIAL ) );
		$outstanding   = 0.0;
		foreach ( $open_invoices as $invoice ) {
			$outstanding += KCRM_Invoice::balance_due( $invoice->id );
		}

		$customer_count = count( KCRM_Customer::top_level_for_company( $company_id ) );
		?>
		<div class="kcrm-dashboard-cards">
			<a class="kcrm-card" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'revenue' ) ) ); ?>">
				<span class="dashicons dashicons-chart-line kcrm-card-icon"></span>
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $revenue_this_year, 2 ) ); ?></span>
				<span class="kcrm-card-label">
					<?php
					/* translators: %d: calendar year. */
					echo esc_html( sprintf( __( '%d Revenue', 'karks-crm' ), $current_year ) );
					?>
					<span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span>
				</span>
			</a>
			<a class="kcrm-card" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'aging' ) ) ); ?>">
				<span class="dashicons dashicons-warning kcrm-card-icon"></span>
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $outstanding, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Outstanding Balance', 'karks-crm' ); ?> <span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span></span>
			</a>
			<a class="kcrm-card" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'customer' ) ) ); ?>">
				<span class="dashicons dashicons-groups kcrm-card-icon"></span>
				<span class="kcrm-card-number"><?php echo esc_html( $customer_count ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Run a Customer Report', 'karks-crm' ); ?> <span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span></span>
			</a>
		</div>
		<?php
	}

	private function render_revenue_report( $company_id ) {
		list( $range, $date_from, $date_to ) = $this->resolve_date_range( 'kcrm_rev', 'this_year' );
		$payments = KCRM_Payment::for_company( $company_id, $date_from, $date_to );
		$total    = array_sum(
			array_map(
				static function ( $payment ) {
					return (float) $payment->amount;
				},
				$payments
			)
		);

		$this->render_date_range_filter( 'kcrm_rev', $range, $date_from, $date_to );
		?>
		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $total, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Revenue in Range', 'karks-crm' ); ?></span>
			</div>
		</div>

		<h3><?php esc_html_e( 'Last 12 Months', 'karks-crm' ); ?></h3>
		<?php $this->render_monthly_bar_chart( KCRM_Payment::monthly_totals_for_company( $company_id, 12 ) ); ?>

		<div class="kcrm-button-group">
			<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->export_url( 'revenue', array( 'kcrm_rev_range' => $range, 'kcrm_rev_from' => $date_from, 'kcrm_rev_to' => $date_to ) ) ); ?>">
				<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'karks-crm' ); ?>
			</a>
		</div>

		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Method', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $payments ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No payments found for this range.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $payments as $payment ) : ?>
					<?php
					$customer = KCRM_Customer::find( $payment->customer_id );
					$invoice  = KCRM_Invoice::find( $payment->invoice_id );
					?>
					<tr>
						<td><?php echo esc_html( $payment->payment_date ); ?></td>
						<td><?php echo esc_html( $customer ? KCRM_Customer::display_name( $customer ) : '' ); ?></td>
						<td>
							<?php if ( $invoice ) : ?>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>"><?php echo esc_html( $invoice->invoice_number ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (float) $payment->amount, 2 ) ); ?></td>
						<td><?php echo esc_html( $payment->method ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array $months List of [ 'label' => 'Jan 2026', 'total' => 1234.56 ], oldest first (see KCRM_Payment::monthly_totals_for_company()). */
	private function render_monthly_bar_chart( array $months ) {
		$max = 0.0;
		foreach ( $months as $month ) {
			$max = max( $max, $month['total'] );
		}
		?>
		<div class="kcrm-bar-chart">
			<?php foreach ( $months as $month ) : ?>
				<?php $pct = $max > 0 ? round( ( $month['total'] / $max ) * 100 ) : 0; ?>
				<div class="kcrm-bar-chart-col">
					<div class="kcrm-bar-chart-bar" style="height: <?php echo esc_attr( $pct ); ?>%;" title="<?php echo esc_attr( $month['label'] . ': ' . number_format_i18n( $month['total'], 2 ) ); ?>"></div>
					<span class="kcrm-bar-chart-label"><?php echo esc_html( $month['label'] ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private function render_customer_report( $company_id ) {
		$customers = KCRM_Customer::top_level_for_company( $company_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
		$customer    = $customer_id ? KCRM_Customer::find( $customer_id ) : null;

		if ( $customer && (int) $customer->company_id !== (int) $company_id ) {
			$customer = null;
		}
		?>
		<form method="get" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<input type="hidden" name="view" value="customer">
			<p>
				<label for="kcrm-report-customer"><?php esc_html_e( 'Customer', 'karks-crm' ); ?></label>
				<select name="customer_id" id="kcrm-report-customer">
					<option value=""><?php esc_html_e( '— Select a customer —', 'karks-crm' ); ?></option>
					<?php foreach ( $customers as $c ) : ?>
						<option value="<?php echo esc_attr( $c->id ); ?>" <?php selected( $customer_id, (int) $c->id ); ?>><?php echo esc_html( $c->company_name ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="kcrm-button"><?php esc_html_e( 'View Report', 'karks-crm' ); ?></button>
			</p>
		</form>
		<?php

		if ( ! $customer ) {
			if ( $customer_id ) {
				echo '<p>' . esc_html__( 'Customer not found.', 'karks-crm' ) . '</p>';
			}
			return;
		}

		list( $range, $date_from, $date_to ) = $this->resolve_date_range( 'kcrm_cust', 'this_year' );
		$job_ids  = wp_list_pluck( KCRM_Customer::jobs_for( $customer_id ), 'id' );
		$ids      = array_merge( array( $customer_id ), $job_ids );
		$payments = KCRM_Payment::for_customers( $ids, 0, 0, $date_from, $date_to );
		$total    = array_sum(
			array_map(
				static function ( $payment ) {
					return (float) $payment->amount;
				},
				$payments
			)
		);
		$balance = KCRM_Customer::balance_for_ids( $ids );

		$this->render_date_range_filter( 'kcrm_cust', $range, $date_from, $date_to );
		?>
		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $total, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Revenue in Range', 'karks-crm' ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Outstanding Balance', 'karks-crm' ); ?></span>
			</div>
		</div>

		<div class="kcrm-button-group">
			<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->export_url( 'customer', array( 'customer_id' => $customer_id, 'kcrm_cust_range' => $range, 'kcrm_cust_from' => $date_from, 'kcrm_cust_to' => $date_to ) ) ); ?>">
				<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'karks-crm' ); ?>
			</a>
		</div>

		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Method', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Note', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $payments ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No payments found for this range.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $payments as $payment ) : ?>
					<?php $invoice = KCRM_Invoice::find( $payment->invoice_id ); ?>
					<tr>
						<td><?php echo esc_html( $payment->payment_date ); ?></td>
						<td>
							<?php if ( $invoice ) : ?>
								<a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $invoice->id ) ) ); ?>"><?php echo esc_html( $invoice->invoice_number ); ?></a>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( number_format_i18n( (float) $payment->amount, 2 ) ); ?></td>
						<td><?php echo esc_html( $payment->method ); ?></td>
						<td><?php echo esc_html( $payment->note ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_aging_report( $company_id ) {
		$rows          = $this->aging_rows( $company_id );
		$bucket_labels = $this->aging_bucket_labels();

		$totals = array_fill_keys( array_keys( $bucket_labels ), 0.0 );
		foreach ( $rows as $row ) {
			$totals[ $row['bucket'] ] += $row['balance'];
		}
		?>
		<div class="kcrm-dashboard-cards">
			<?php foreach ( $bucket_labels as $key => $label ) : ?>
				<div class="kcrm-card">
					<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $totals[ $key ], 2 ) ); ?></span>
					<span class="kcrm-card-label"><?php echo esc_html( $label ); ?></span>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="kcrm-button-group">
			<a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->export_url( 'aging' ) ); ?>">
				<span class="dashicons dashicons-download"></span> <?php esc_html_e( 'Export CSV', 'karks-crm' ); ?>
			</a>
		</div>

		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Customer', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Due Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Days Overdue', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Bucket', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Balance Due', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No outstanding invoices.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $row['invoice']->id ) ) ); ?>"><?php echo esc_html( $row['invoice']->invoice_number ); ?></a></td>
						<td><?php echo esc_html( $row['customer_name'] ); ?></td>
						<td><?php echo esc_html( $row['invoice']->due_date ); ?></td>
						<td><?php echo esc_html( max( 0, $row['days_overdue'] ) ); ?></td>
						<td><?php echo esc_html( $bucket_labels[ $row['bucket'] ] ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $row['balance'], 2 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function aging_bucket_labels() {
		return array(
			'current'  => __( 'Current', 'karks-crm' ),
			'b1_30'    => __( '1-30 Days', 'karks-crm' ),
			'b31_60'   => __( '31-60 Days', 'karks-crm' ),
			'b61_90'   => __( '61-90 Days', 'karks-crm' ),
			'b90_plus' => __( '90+ Days', 'karks-crm' ),
		);
	}

	/**
	 * Every open/partially-paid invoice for the company with a balance still
	 * due, bucketed by how many days past its due date it is (no due date
	 * counts as "Current" -- not yet overdue). Shared by render_aging_report()
	 * and the CSV export so the two can't drift.
	 *
	 * @return array List of [ 'invoice', 'customer_name', 'balance', 'days_overdue', 'bucket' ], most overdue first.
	 */
	private function aging_rows( $company_id ) {
		$invoices = KCRM_Invoice::for_company_with_statuses( $company_id, array( KCRM_Invoice::STATUS_OPEN, KCRM_Invoice::STATUS_PARTIAL ), 'due_date ASC, id ASC' );
		$today    = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- only used for a day-granularity diff against due_date, timezone precision doesn't matter here.

		$rows = array();
		foreach ( $invoices as $invoice ) {
			$balance = KCRM_Invoice::balance_due( $invoice->id );
			if ( $balance <= 0.004 ) {
				continue;
			}

			$days_overdue = 0;
			if ( $invoice->due_date ) {
				$days_overdue = (int) floor( ( $today - strtotime( $invoice->due_date ) ) / DAY_IN_SECONDS );
			}

			if ( $days_overdue <= 0 ) {
				$bucket = 'current';
			} elseif ( $days_overdue <= 30 ) {
				$bucket = 'b1_30';
			} elseif ( $days_overdue <= 60 ) {
				$bucket = 'b31_60';
			} elseif ( $days_overdue <= 90 ) {
				$bucket = 'b61_90';
			} else {
				$bucket = 'b90_plus';
			}

			$customer = KCRM_Customer::find( $invoice->customer_id );

			$rows[] = array(
				'invoice'       => $invoice,
				'customer_name' => $customer ? KCRM_Customer::display_name( $customer ) : '',
				'balance'       => $balance,
				'days_overdue'  => $days_overdue,
				'bucket'        => $bucket,
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['days_overdue'] <=> $a['days_overdue'];
			}
		);

		return $rows;
	}

	/** Builds a nonce-protected admin-post URL to handle_csv_export(). */
	private function export_url( $report, array $args = array() ) {
		$url = add_query_arg(
			array_merge(
				array(
					'action' => 'kcrm_export_report_csv',
					'report' => $report,
				),
				$args
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'kcrm_export_report_csv' );
	}

	/**
	 * admin-post handler: streams a CSV download for one of the three
	 * reports. Registered once (in KCRM_Front) regardless of which report
	 * card's export link pointed here.
	 */
	public function handle_csv_export() {
		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ) );
		}
		check_admin_referer( 'kcrm_export_report_csv' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().
		$report  = isset( $_GET['report'] ) ? sanitize_key( $_GET['report'] ) : '';
		$company = KCRM_Company::find( $this->current_company_id() );

		if ( ! $company || ! in_array( $report, array( 'revenue', 'customer', 'aging' ), true ) ) {
			wp_die( esc_html__( 'Invalid report.', 'karks-crm' ) );
		}

		if ( 'revenue' === $report ) {
			$this->export_revenue_csv( $company );
		} elseif ( 'customer' === $report ) {
			$this->export_customer_csv( $company );
		} else {
			$this->export_aging_csv( $company );
		}
	}

	private function send_csv_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename . '-' . gmdate( 'Y-m-d' ) ) . '.csv"' );
	}

	private function export_revenue_csv( $company ) {
		list( , $date_from, $date_to ) = $this->resolve_date_range( 'kcrm_rev', 'this_year' );
		$payments = KCRM_Payment::for_company( $company->id, $date_from, $date_to );

		$this->send_csv_headers( 'revenue-report-' . $company->name );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( __( 'Date', 'karks-crm' ), __( 'Customer', 'karks-crm' ), __( 'Invoice #', 'karks-crm' ), __( 'Amount', 'karks-crm' ), __( 'Method', 'karks-crm' ) ) );

		foreach ( $payments as $payment ) {
			$customer = KCRM_Customer::find( $payment->customer_id );
			$invoice  = KCRM_Invoice::find( $payment->invoice_id );
			fputcsv(
				$out,
				array(
					$payment->payment_date,
					$customer ? KCRM_Customer::display_name( $customer ) : '',
					$invoice ? $invoice->invoice_number : '',
					number_format( (float) $payment->amount, 2, '.', '' ),
					$payment->method,
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
	}

	private function export_customer_csv( $company ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified in handle_csv_export().
		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
		$customer    = $customer_id ? KCRM_Customer::find( $customer_id ) : null;

		if ( ! $customer || (int) $customer->company_id !== (int) $company->id ) {
			wp_die( esc_html__( 'Customer not found.', 'karks-crm' ) );
		}

		list( , $date_from, $date_to ) = $this->resolve_date_range( 'kcrm_cust', 'this_year' );
		$job_ids  = wp_list_pluck( KCRM_Customer::jobs_for( $customer_id ), 'id' );
		$ids      = array_merge( array( $customer_id ), $job_ids );
		$payments = KCRM_Payment::for_customers( $ids, 0, 0, $date_from, $date_to );

		$this->send_csv_headers( 'customer-report-' . KCRM_Customer::display_name( $customer ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( __( 'Date', 'karks-crm' ), __( 'Invoice #', 'karks-crm' ), __( 'Amount', 'karks-crm' ), __( 'Method', 'karks-crm' ), __( 'Note', 'karks-crm' ) ) );

		foreach ( $payments as $payment ) {
			$invoice = KCRM_Invoice::find( $payment->invoice_id );
			fputcsv(
				$out,
				array(
					$payment->payment_date,
					$invoice ? $invoice->invoice_number : '',
					number_format( (float) $payment->amount, 2, '.', '' ),
					$payment->method,
					$payment->note,
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
	}

	private function export_aging_csv( $company ) {
		$rows = $this->aging_rows( $company->id );
		$bucket_labels = $this->aging_bucket_labels();

		$this->send_csv_headers( 'aging-report-' . $company->name );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( __( 'Invoice #', 'karks-crm' ), __( 'Customer', 'karks-crm' ), __( 'Due Date', 'karks-crm' ), __( 'Days Overdue', 'karks-crm' ), __( 'Bucket', 'karks-crm' ), __( 'Balance Due', 'karks-crm' ) ) );

		foreach ( $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['invoice']->invoice_number,
					$row['customer_name'],
					$row['invoice']->due_date,
					max( 0, $row['days_overdue'] ),
					$bucket_labels[ $row['bucket'] ],
					number_format( $row['balance'], 2, '.', '' ),
				)
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
	}
}
