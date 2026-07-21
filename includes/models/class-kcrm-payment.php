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
