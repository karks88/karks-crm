<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Payment extends KCRM_Model_Base {

	public static function table() {
		return KCRM_DB::payments();
	}

	protected static function columns() {
		return array(
			'invoice_id'   => '%d',
			'customer_id'  => '%d',
			'company_id'   => '%d',
			'amount'       => '%f',
			'payment_date' => '%s',
			'method'       => '%s',
			'note'         => '%s',
			'created_at'   => '%s',
		);
	}

	public static function for_invoice( $invoice_id ) {
		return self::where( array( 'invoice_id' => $invoice_id ), 'payment_date DESC, id DESC' );
	}

	/** Deletes every payment recorded against an invoice (called when the invoice itself is deleted, so they don't become orphaned). */
	public static function delete_for_invoice( $invoice_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; $wpdb->delete() already escapes values.
		return $wpdb->delete( self::table(), array( 'invoice_id' => $invoice_id ), array( '%d' ) );
	}

	public static function for_customer( $customer_id ) {
		return self::where( array( 'customer_id' => $customer_id ), 'payment_date DESC, id DESC' );
	}

	public static function total_for_invoice( $invoice_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE invoice_id = %d', self::table(), $invoice_id )
		);
	}

	/** Lifetime total paid by a customer. */
	public static function total_for_customer( $customer_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE customer_id = %d', self::table(), $customer_id )
		);
	}

	/** Total paid by a customer within a given calendar year (based on payment_date). */
	public static function total_for_customer_in_year( $customer_id, $year ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE customer_id = %d AND YEAR(payment_date) = %d', self::table(), $customer_id, $year )
		);
	}

	/** Total paid across an entire company within a given calendar year (based on payment_date), for the company overview's revenue card. */
	public static function total_for_company_in_year( $company_id, $year ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE company_id = %d AND YEAR(payment_date) = %d', self::table(), $company_id, $year )
		);
	}

	/** Lifetime total paid across a set of customer ids (a customer plus its Jobs). */
	public static function total_for_customers( array $customer_ids ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return 0.0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only repeated %d placeholder syntax (its count matches count( $customer_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE customer_id IN (' . $placeholders . ')', array_merge( array( self::table() ), $customer_ids ) ) );
	}

	/**
	 * Payments across a set of customer ids (a customer plus its Jobs),
	 * most recent first, optionally paginated and/or date-bounded.
	 *
	 * @param string|null $date_from Limit to payment_date >= this ('Y-m-d'), or null for no lower bound.
	 * @param string|null $date_to   Limit to payment_date <= this ('Y-m-d'), or null for no upper bound.
	 */
	public static function for_customers( array $customer_ids, $limit = 0, $offset = 0, $date_from = null, $date_to = null ) {
		global $wpdb;

		list( $where, $params ) = self::customers_where( $customer_ids, $date_from, $date_to );
		if ( null === $where ) {
			return array();
		}

		$sql = 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY payment_date DESC, id DESC';

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from %d/%s placeholder syntax only (see customers_where()); $params holds one value per placeholder, passed as $wpdb->prepare()'s documented array-of-args form.
		return $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table() ), $params ) ) );
	}

	/** Count of payments across a set of customer ids, for pagination. */
	public static function count_for_customers( array $customer_ids, $date_from = null, $date_to = null ) {
		global $wpdb;

		list( $where, $params ) = self::customers_where( $customer_ids, $date_from, $date_to );
		if ( null === $where ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from %d/%s placeholder syntax only (see customers_where()), not user input; query text and args are passed to $wpdb->prepare() on this line.
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ' . $where, array_merge( array( self::table() ), $params ) ) );
	}

	/**
	 * Shared "customer_id IN (...) [AND payment_date BETWEEN ...]" builder
	 * for for_customers()/count_for_customers().
	 *
	 * @return array [ $where_sql|null, $params ] -- $where_sql is null if $customer_ids is empty (nothing to query).
	 */
	private static function customers_where( array $customer_ids, $date_from, $date_to ) {
		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return array( null, array() );
		}

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$where        = 'customer_id IN (' . $placeholders . ')';
		$params        = $customer_ids;

		if ( $date_from ) {
			$where   .= ' AND payment_date >= %s';
			$params[] = $date_from;
		}

		if ( $date_to ) {
			$where   .= ' AND payment_date <= %s';
			$params[] = $date_to;
		}

		return array( $where, $params );
	}

	/** Total paid within a given calendar year across a set of customer ids (a customer plus its Jobs). */
	public static function total_for_customers_in_year( array $customer_ids, $year ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return 0.0;
		}

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$params       = array_merge( array( self::table() ), $customer_ids, array( $year ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only repeated %d placeholder syntax; $params holds one value per placeholder ($customer_ids plus $year), passed as $wpdb->prepare()'s documented array-of-args form.
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE customer_id IN (' . $placeholders . ') AND YEAR(payment_date) = %d', $params ) );
	}

	/**
	 * Shared "company_id = X [AND payment_date BETWEEN ...]" builder for
	 * for_company()/total_for_company().
	 *
	 * @return array [ $where_sql, $params ]
	 */
	private static function company_where( $company_id, $date_from, $date_to ) {
		$where  = 'company_id = %d';
		$params = array( (int) $company_id );

		if ( $date_from ) {
			$where   .= ' AND payment_date >= %s';
			$params[] = $date_from;
		}

		if ( $date_to ) {
			$where   .= ' AND payment_date <= %s';
			$params[] = $date_to;
		}

		return array( $where, $params );
	}

	/**
	 * Payments across an entire company, most recent first, optionally date-bounded.
	 *
	 * @param string|null $date_from Limit to payment_date >= this ('Y-m-d'), or null for no lower bound.
	 * @param string|null $date_to   Limit to payment_date <= this ('Y-m-d'), or null for no upper bound.
	 */
	public static function for_company( $company_id, $date_from = null, $date_to = null ) {
		global $wpdb;

		list( $where, $params ) = self::company_where( $company_id, $date_from, $date_to );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from %d/%s placeholder syntax only (see company_where()); $params holds one value per placeholder, passed as $wpdb->prepare()'s documented array-of-args form.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE ' . $where . ' ORDER BY payment_date DESC, id DESC', array_merge( array( self::table() ), $params ) ) );
	}

	/** Total paid across an entire company, optionally date-bounded (see total_for_company_in_year() for the whole-calendar-year shortcut). */
	public static function total_for_company( $company_id, $date_from = null, $date_to = null ) {
		global $wpdb;

		list( $where, $params ) = self::company_where( $company_id, $date_from, $date_to );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $where is built from %d/%s placeholder syntax only (see company_where()); $params holds one value per placeholder, passed as $wpdb->prepare()'s documented array-of-args form.
		return (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE ' . $where, array_merge( array( self::table() ), $params ) ) );
	}

	/**
	 * Monthly payment totals for a company across the trailing $months
	 * months (including the current month), oldest first -- for the
	 * Reports revenue bar chart. Months with no payments are included
	 * with a total of 0.
	 *
	 * @return array List of [ 'label' => 'Jan 2026', 'total' => 1234.56 ].
	 */
	public static function monthly_totals_for_company( $company_id, $months = 12 ) {
		global $wpdb;

		$start = gmdate( 'Y-m-01', strtotime( '-' . ( $months - 1 ) . ' months' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE_FORMAT(payment_date, '%%Y-%%m') AS ym, COALESCE(SUM(amount), 0) AS total FROM %i WHERE company_id = %d AND payment_date >= %s GROUP BY ym",
				self::table(),
				$company_id,
				$start
			)
		);

		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ $row->ym ] = (float) $row->total;
		}

		$result = array();
		for ( $i = $months - 1; $i >= 0; $i-- ) {
			$timestamp = strtotime( "-{$i} months" );
			$ym        = gmdate( 'Y-m', $timestamp );
			$result[]  = array(
				'label' => date_i18n( 'M Y', $timestamp ),
				'total' => isset( $totals[ $ym ] ) ? $totals[ $ym ] : 0.0,
			);
		}

		return $result;
	}

	/**
	 * Record a payment, then let the invoice recompute its status.
	 */
	public static function create( $data ) {
		$data['created_at'] = current_time( 'mysql' );
		$id                  = self::insert( $data );

		if ( $id && ! empty( $data['invoice_id'] ) ) {
			KCRM_Invoice::refresh_payment_status( $data['invoice_id'] );
		}

		return $id;
	}

	public static function delete_and_refresh( $id ) {
		$payment = self::find( $id );
		if ( ! $payment ) {
			return false;
		}
		$result = self::delete( $id );
		KCRM_Invoice::refresh_payment_status( $payment->invoice_id );
		return $result;
	}
}
