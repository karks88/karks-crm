<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Invoice extends KCRM_Model_Base {

	const STATUS_DRAFT   = 'draft';
	const STATUS_OPEN    = 'open';
	const STATUS_PARTIAL = 'partial';
	const STATUS_PAID    = 'paid';
	const STATUS_VOID    = 'void';

	const TYPE_MONTH_YEAR = 'month_year';
	const TYPE_HOSTING    = 'web_hosting';
	const TYPE_MAINTENANCE = 'maintenance';
	const TYPE_OTHER      = 'other';

	public static function table() {
		return KCRM_DB::invoices();
	}

	protected static function columns() {
		return array(
			'company_id'     => '%d',
			'customer_id'    => '%d',
			'invoice_number' => '%s',
			'status'         => '%s',
			'issue_date'     => '%s',
			'due_date'       => '%s',
			'invoice_type'       => '%s',
			'invoice_type_month' => '%s',
			'invoice_type_other' => '%s',
			'notes'          => '%s',
			'subtotal'       => '%f',
			'tax_rate'       => '%f',
			'tax_amount'     => '%f',
			'total'          => '%f',
			'created_at'     => '%s',
			'updated_at'     => '%s',
		);
	}

	public static function types() {
		return array(
			self::TYPE_MONTH_YEAR  => __( 'Month/Year', 'karks-crm' ),
			self::TYPE_HOSTING     => __( 'Web Hosting', 'karks-crm' ),
			self::TYPE_MAINTENANCE => __( 'Website Maintenance Package', 'karks-crm' ),
			self::TYPE_OTHER       => __( 'Other', 'karks-crm' ),
		);
	}

	/**
	 * Human-readable invoice type, resolving the Month/Year and Other variants
	 * to their stored values instead of just the generic option label.
	 */
	public static function type_label( $invoice ) {
		$types = self::types();
		$type  = $invoice->invoice_type ?? self::TYPE_OTHER;

		if ( self::TYPE_MONTH_YEAR === $type && ! empty( $invoice->invoice_type_month ) ) {
			$timestamp = strtotime( $invoice->invoice_type_month . '-01' );
			if ( $timestamp ) {
				return date_i18n( 'F Y', $timestamp );
			}
		}

		if ( self::TYPE_OTHER === $type && ! empty( $invoice->invoice_type_other ) ) {
			return $invoice->invoice_type_other;
		}

		return $types[ $type ] ?? $type;
	}

	public static function statuses() {
		return array(
			self::STATUS_DRAFT   => __( 'Draft', 'karks-crm' ),
			self::STATUS_OPEN    => __( 'Open', 'karks-crm' ),
			self::STATUS_PARTIAL => __( 'Partially Paid', 'karks-crm' ),
			self::STATUS_PAID    => __( 'Paid', 'karks-crm' ),
			self::STATUS_VOID    => __( 'Void', 'karks-crm' ),
		);
	}

	public static function for_company( $company_id, $order_by = 'issue_date DESC, id DESC' ) {
		return self::where( array( 'company_id' => $company_id ), $order_by );
	}

	public static function for_customer( $customer_id, $order_by = 'issue_date DESC, id DESC' ) {
		return self::where( array( 'customer_id' => $customer_id ), $order_by );
	}

	/** Statuses shown by default on the customer profile's invoice list. */
	public static function default_customer_statuses() {
		return array( self::STATUS_DRAFT, self::STATUS_OPEN, self::STATUS_PARTIAL );
	}

	/**
	 * @param array|null $statuses Limit to these statuses, or null for all statuses.
	 */
	public static function for_customer_with_statuses( $customer_id, $statuses = null, $order_by = 'issue_date DESC, id DESC' ) {
		global $wpdb;

		if ( null === $statuses ) {
			return self::for_customer( $customer_id, $order_by );
		}

		if ( empty( $statuses ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$params       = array_merge( array( self::table(), $customer_id ), array_values( $statuses ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is only repeated %s placeholder syntax (its count matches count( $statuses )); $params holds one value per placeholder, passed as $wpdb->prepare()'s documented array-of-args form.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE customer_id = %d AND status IN (' . $placeholders . ') ORDER BY ' . self::safe_order_by( $order_by ), $params ) );
	}

	/**
	 * Same as for_customer_with_statuses() but across a set of customer ids
	 * (a customer plus its Jobs), for the profile's rolled-up invoice list.
	 *
	 * @param array      $customer_ids
	 * @param array|null $statuses Limit to these statuses, or null for all statuses.
	 */
	public static function for_customers_with_statuses( array $customer_ids, $statuses = null, $order_by = 'issue_date DESC, id DESC' ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return array();
		}

		if ( is_array( $statuses ) && empty( $statuses ) ) {
			return array();
		}

		$customer_placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$params                = array_merge( array( self::table() ), $customer_ids );

		$sql = 'SELECT * FROM %i WHERE customer_id IN (' . $customer_placeholders . ')';

		if ( is_array( $statuses ) ) {
			$status_placeholders = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
			$sql                .= ' AND status IN (' . $status_placeholders . ')';
			$params              = array_merge( $params, array_values( $statuses ) );
		}

		$sql .= ' ORDER BY ' . self::safe_order_by( $order_by );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $customer_placeholders/$status_placeholders are only repeated placeholder syntax (their counts match count( $customer_ids )/count( $statuses )), not user input; $sql/$params are passed to $wpdb->prepare() on this line.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function create( $data ) {
		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		if ( empty( $data['status'] ) ) {
			$data['status'] = self::STATUS_OPEN;
		}
		return self::insert( $data );
	}

	public static function save( $id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return self::update( $id, $data );
	}

	/**
	 * Recompute subtotal/tax/total from this invoice's line items and persist them.
	 */
	public static function recalculate_totals( $invoice_id ) {
		$invoice = self::find( $invoice_id );
		if ( ! $invoice ) {
			return;
		}

		$items    = KCRM_Invoice_Item::for_invoice( $invoice_id );
		$subtotal = 0.0;
		foreach ( $items as $item ) {
			$subtotal += (float) $item->amount;
		}

		$tax_rate   = (float) $invoice->tax_rate;
		$tax_amount = round( $subtotal * ( $tax_rate / 100 ), 2 );
		$total      = round( $subtotal + $tax_amount, 2 );

		self::save(
			$invoice_id,
			array(
				'subtotal'   => round( $subtotal, 2 ),
				'tax_amount' => $tax_amount,
				'total'      => $total,
			)
		);

		self::refresh_payment_status( $invoice_id );
	}

	/**
	 * Move status between open/partial/paid based on payments recorded so far.
	 * Leaves draft/sent/void alone since those are set explicitly by the user.
	 */
	public static function refresh_payment_status( $invoice_id ) {
		$invoice = self::find( $invoice_id );
		$manual_statuses = array( self::STATUS_DRAFT, self::STATUS_VOID );
		if ( ! $invoice || in_array( $invoice->status, $manual_statuses, true ) ) {
			return;
		}

		$paid = KCRM_Payment::total_for_invoice( $invoice_id );
		$total = (float) $invoice->total;

		if ( $paid <= 0 ) {
			$status = self::STATUS_OPEN;
		} elseif ( $paid + 0.005 >= $total ) {
			$status = self::STATUS_PAID;
		} else {
			$status = self::STATUS_PARTIAL;
		}

		if ( $status !== $invoice->status ) {
			self::save( $invoice_id, array( 'status' => $status ) );
		}
	}

	public static function balance_due( $invoice_id ) {
		$invoice = self::find( $invoice_id );
		if ( ! $invoice ) {
			return 0.0;
		}
		$paid = KCRM_Payment::total_for_invoice( $invoice_id );
		return round( (float) $invoice->total - $paid, 2 );
	}
}
