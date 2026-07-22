<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Invoice_Item extends KCRM_Model_Base {

	public static function table() {
		return KCRM_DB::invoice_items();
	}

	protected static function columns() {
		return array(
			'invoice_id'  => '%d',
			'service_id'  => '%d',
			'description' => '%s',
			'type'        => '%s',
			'quantity'    => '%f',
			'rate'        => '%f',
			'amount'      => '%f',
			'is_taxable'  => '%d',
			'sort_order'  => '%d',
		);
	}

	public static function for_invoice( $invoice_id ) {
		return self::where( array( 'invoice_id' => $invoice_id ), 'sort_order ASC, id ASC' );
	}

	public static function delete_for_invoice( $invoice_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; $wpdb->delete() already escapes values.
		return $wpdb->delete( self::table(), array( 'invoice_id' => $invoice_id ), array( '%d' ) );
	}
}
