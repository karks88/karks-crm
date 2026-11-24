<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The company activity log: one row per action taken in the CRM (invoice
 * saved, payment recorded, customer deleted, CSV imported...), written by
 * the controllers at the moment the action happens. Shown on the company
 * profile's Recent Actions feed, the front-end Activity Log screen, and
 * each customer profile's Activity tab (see KCRM_Activity_Feed).
 *
 * Deliberately records *what* happened, never *who* did it -- there's no
 * user column, and nothing in `details` identifies the acting user.
 *
 * `details` is a JSON snapshot of the display values at the time (invoice
 * number, customer name, amount...) so an entry still reads correctly after
 * the record it describes is edited or deleted. Labels themselves are built
 * at display time (KCRM_Activity_Feed::describe()), so they stay
 * translatable.
 */
class KCRM_Activity extends KCRM_Model_Base {

	/** Daily WP-Cron hook that runs prune_expired(). */
	const PRUNE_HOOK = 'kcrm_activity_prune';

	/** Site-wide on/off switch for recording activity (1/0; missing means on). Set from KCRM_Admin_Activity. */
	const ENABLED_OPTION = 'kcrm_activity_enabled';

	/** Option holding how many months of activity to keep (0 = forever). */
	const RETENTION_OPTION = 'kcrm_activity_retention_months';

	const DEFAULT_RETENTION_MONTHS = 24;

	/** Rows deleted per query by prune()/clear_for_company(), so a large cleanup never holds one long-running lock. */
	const DELETE_BATCH_SIZE = 1000;

	public static function table() {
		return KCRM_DB::activity();
	}

	protected static function columns() {
		return array(
			'company_id'  => '%d',
			'customer_id' => '%d',
			'event'       => '%s',
			'object_type' => '%s',
			'object_id'   => '%d',
			'details'     => '%s',
			'created_at'  => '%s',
		);
	}

	/**
	 * Filter groups for the Activity Log screen's type chips: group key =>
	 * the exact event names it covers. Used as a whitelist -- a group key
	 * that isn't listed here is ignored rather than passed into SQL.
	 *
	 * @return array<string,string[]>
	 */
	public static function event_groups() {
		return array(
			'invoices'  => array( 'invoice.created', 'invoice.updated', 'invoice.deleted', 'invoice.status_changed', 'import.invoices' ),
			'payments'  => array( 'payment.created', 'payment.deleted', 'import.payments' ),
			'emails'    => array( 'invoice.emailed' ),
			'customers' => array( 'customer.created', 'customer.updated', 'customer.deleted', 'import.customers' ),
			'services'  => array( 'service.created', 'service.updated', 'service.deleted', 'import.services' ),
			'company'   => array( 'company.updated', 'company.imported', 'company.exported', 'settings.retention_changed', 'log.cleared' ),
		);
	}

	/** @return int[] The retention choices offered on the Activity Log screen, in months (0 = forever). */
	public static function retention_choices() {
		return array( 6, 12, 24, 0 );
	}

	/** Whether new activity is recorded (and the front-end activity UI shown). */
	public static function is_enabled() {
		return (bool) get_option( self::ENABLED_OPTION, 1 );
	}

	/** @return int Months of activity to keep; 0 means keep forever. */
	public static function retention_months() {
		$months = (int) get_option( self::RETENTION_OPTION, self::DEFAULT_RETENTION_MONTHS );
		return in_array( $months, self::retention_choices(), true ) ? $months : self::DEFAULT_RETENTION_MONTHS;
	}

	/**
	 * Records one activity entry, unless logging is turned off. Never throws
	 * and never blocks the action being logged -- a failed (or skipped)
	 * write just returns 0.
	 *
	 * @param string $event e.g. 'invoice.created'. The part before the dot becomes object_type.
	 * @param array  $args  Optional: company_id (defaults to the current company), customer_id, object_id, details (array).
	 * @return int The new row id, or 0 if nothing was written.
	 */
	public static function log( $event, array $args = array() ) {
		if ( ! self::is_enabled() ) {
			return 0;
		}

		try {
			$company_id = isset( $args['company_id'] ) ? absint( $args['company_id'] ) : KCRM_Context::get_current_company_id();
			if ( ! $company_id ) {
				return 0;
			}

			$parts = explode( '.', (string) $event, 2 );

			return self::insert(
				array(
					'company_id'  => $company_id,
					'customer_id' => ! empty( $args['customer_id'] ) ? absint( $args['customer_id'] ) : null,
					'event'       => substr( (string) $event, 0, 50 ),
					'object_type' => substr( $parts[0], 0, 30 ),
					'object_id'   => ! empty( $args['object_id'] ) ? absint( $args['object_id'] ) : null,
					'details'     => wp_json_encode( isset( $args['details'] ) && is_array( $args['details'] ) ? $args['details'] : array() ),
					'created_at'  => current_time( 'mysql' ),
				)
			);
		} catch ( \Throwable $e ) {
			return 0;
		}
	}

	/** Snapshot of the values an invoice entry needs to stay readable after the invoice changes or is deleted. */
	public static function invoice_details( $invoice, array $extra = array() ) {
		$customer = ! empty( $invoice->customer_id ) ? KCRM_Customer::find( $invoice->customer_id ) : null;
		return array_merge(
			array(
				'invoice_id'     => (int) $invoice->id,
				'invoice_number' => (string) $invoice->invoice_number,
				'customer_name'  => $customer ? KCRM_Customer::display_name( $customer ) : '',
			),
			$extra
		);
	}

	/** Logs an invoice.* event for $invoice (a KCRM_Invoice row). */
	public static function log_invoice( $event, $invoice, array $extra = array() ) {
		if ( ! $invoice ) {
			return 0;
		}
		return self::log(
			$event,
			array(
				'company_id'  => $invoice->company_id,
				'customer_id' => $invoice->customer_id,
				'object_id'   => $invoice->id,
				'details'     => self::invoice_details( $invoice, $extra ),
			)
		);
	}

	/**
	 * Logs invoice.status_changed if $invoice_id's status is no longer
	 * $old_status. Called by controllers after anything that can move an
	 * invoice's status (an edit, or a payment being added/removed) --
	 * deliberately not from KCRM_Invoice::refresh_payment_status() itself,
	 * so bulk work (company import, the demo seeder) doesn't flood the log.
	 */
	public static function log_status_change( $invoice_id, $old_status ) {
		$invoice = KCRM_Invoice::find( $invoice_id );
		if ( ! $invoice || $invoice->status === $old_status ) {
			return 0;
		}
		return self::log_invoice(
			'invoice.status_changed',
			$invoice,
			array(
				'from' => (string) $old_status,
				'to'   => (string) $invoice->status,
			)
		);
	}

	/** Logs a payment.* event. $invoice is the payment's invoice row, if it still exists. */
	public static function log_payment( $event, $payment_id, $amount, $invoice ) {
		if ( ! $invoice ) {
			return 0;
		}
		return self::log(
			$event,
			array(
				'company_id'  => $invoice->company_id,
				'customer_id' => $invoice->customer_id,
				'object_id'   => $payment_id,
				'details'     => self::invoice_details( $invoice, array( 'amount' => round( (float) $amount, 2 ) ) ),
			)
		);
	}

	/** Logs a customer.* event for $customer (a KCRM_Customer row). */
	public static function log_customer( $event, $customer, array $extra = array() ) {
		if ( ! $customer ) {
			return 0;
		}
		return self::log(
			$event,
			array(
				'company_id'  => $customer->company_id,
				'customer_id' => $customer->id,
				'object_id'   => $customer->id,
				'details'     => array_merge(
					array(
						'customer_name' => KCRM_Customer::display_name( $customer ),
						'is_job'        => ! empty( $customer->parent_customer_id ),
					),
					$extra
				),
			)
		);
	}

	/** Logs a service.* event for $service (a KCRM_Service row). */
	public static function log_service( $event, $service ) {
		if ( ! $service ) {
			return 0;
		}
		return self::log(
			$event,
			array(
				'company_id' => $service->company_id,
				'object_id'  => $service->id,
				'details'    => array( 'service_name' => (string) $service->name ),
			)
		);
	}

	/**
	 * Builds the shared WHERE clause for query()/count_query(). The company
	 * condition is always present, whatever else is filtered on.
	 *
	 * @param array $filters events (string[]), customer_ids (int[]), since ('Y-m-d H:i:s'), from/to ('Y-m-d').
	 * @return array{0: string, 1: array} [ $where_sql, $params ]
	 */
	private static function where_sql( $company_id, array $filters ) {
		$clauses = array( 'company_id = %d' );
		$params  = array( absint( $company_id ) );

		if ( ! empty( $filters['events'] ) ) {
			$events    = array_values( array_map( 'strval', (array) $filters['events'] ) );
			$clauses[] = 'event IN (' . implode( ', ', array_fill( 0, count( $events ), '%s' ) ) . ')';
			$params    = array_merge( $params, $events );
		}

		if ( isset( $filters['customer_ids'] ) ) {
			$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $filters['customer_ids'] ) ) ) );
			if ( empty( $ids ) ) {
				$clauses[] = '1 = 0'; // An explicit, empty customer filter matches nothing rather than everything.
			} else {
				$clauses[] = 'customer_id IN (' . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')';
				$params    = array_merge( $params, $ids );
			}
		}

		if ( ! empty( $filters['since'] ) ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = (string) $filters['since'];
		}

		if ( ! empty( $filters['from'] ) ) {
			$clauses[] = 'created_at >= %s';
			$params[]  = $filters['from'] . ' 00:00:00';
		}

		if ( ! empty( $filters['to'] ) ) {
			$clauses[] = 'created_at <= %s';
			$params[]  = $filters['to'] . ' 23:59:59';
		}

		return array( implode( ' AND ', $clauses ), $params );
	}

	/**
	 * A company's activity, newest first.
	 *
	 * @param array $filters See where_sql().
	 */
	public static function query( $company_id, array $filters = array(), $limit = 0, $offset = 0 ) {
		global $wpdb;

		list( $where, $params ) = self::where_sql( $company_id, $filters );
		$sql                    = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY created_at DESC, id DESC';
		$params                 = array_merge( array( self::table() ), $params );

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = (int) $limit;
			$params[] = (int) $offset;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from %i/%s/%d placeholders only (the IN lists are repeated placeholder syntax whose count matches their params), filled in via $wpdb->prepare() on the same line.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/** @see query() */
	public static function count_query( $company_id, array $filters = array() ) {
		global $wpdb;

		list( $where, $params ) = self::where_sql( $company_id, $filters );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- built from %i/%s/%d placeholders only, filled in via $wpdb->prepare() on the same line.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, array_merge( array( self::table() ), $params ) ) );
	}

	/**
	 * Deletes entries created before $before ('Y-m-d H:i:s'), across every
	 * company, in batches.
	 *
	 * @return int Rows deleted.
	 */
	public static function prune( $before ) {
		global $wpdb;

		$deleted = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table cleanup; no core API applies.
			$batch    = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s LIMIT %d', self::table(), $before, self::DELETE_BATCH_SIZE ) );
			$deleted += $batch;
		} while ( self::DELETE_BATCH_SIZE === $batch );

		return $deleted;
	}

	/** WP-Cron callback for PRUNE_HOOK: applies the retention setting. */
	public static function prune_expired() {
		$months = self::retention_months();
		if ( ! $months ) {
			return 0;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months', current_time( 'timestamp' ) ) );
		return self::prune( $cutoff );
	}

	/** Deletes every entry for one company (the Activity Log screen's admin-only "Clear" action). @return int Rows deleted. */
	public static function clear_for_company( $company_id ) {
		global $wpdb;

		$deleted = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table cleanup; no core API applies.
			$batch    = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE company_id = %d LIMIT %d', self::table(), $company_id, self::DELETE_BATCH_SIZE ) );
			$deleted += $batch;
		} while ( self::DELETE_BATCH_SIZE === $batch );

		return $deleted;
	}
}
