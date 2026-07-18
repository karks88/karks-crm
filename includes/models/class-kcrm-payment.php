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
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $table WHERE invoice_id = %d", $invoice_id )
		);
	}

	/** Lifetime total paid by a customer. */
	public static function total_for_customer( $customer_id ) {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $table WHERE customer_id = %d", $customer_id )
		);
	}

	/** Total paid by a customer within a given calendar year (based on payment_date). */
	public static function total_for_customer_in_year( $customer_id, $year ) {
		global $wpdb;
		$table = self::table();
		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $table WHERE customer_id = %d AND YEAR(payment_date) = %d", $customer_id, $year )
		);
	}

	/** Lifetime total paid across a set of customer ids (a customer plus its Jobs). */
	public static function total_for_customers( array $customer_ids ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return 0.0;
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );

		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $table WHERE customer_id IN ($placeholders)", $customer_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** Total paid within a given calendar year across a set of customer ids (a customer plus its Jobs). */
	public static function total_for_customers_in_year( array $customer_ids, $year ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return 0.0;
		}

		$table        = self::table();
		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$params       = array_merge( $customer_ids, array( $year ) );

		return (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $table WHERE customer_id IN ($placeholders) AND YEAR(payment_date) = %d", $params ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
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
