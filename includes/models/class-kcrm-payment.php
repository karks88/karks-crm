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
