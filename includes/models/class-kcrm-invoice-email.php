<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A lightweight log of "Email Invoice" sends (KCRM_Invoices_Controller::
 * send_invoice_email()) -- just enough to show "Last emailed to X on Y"
 * on the invoice screen, not a full delivery/audit trail.
 */
class KCRM_Invoice_Email extends KCRM_Model_Base {

	public static function table() {
		return KCRM_DB::invoice_emails();
	}

	protected static function columns() {
		return array(
			'invoice_id'    => '%d',
			'sent_to_name'  => '%s',
			'sent_to_email' => '%s',
			'sent_by'       => '%d',
			'sent_at'       => '%s',
		);
	}

	public static function create( $data ) {
		$data['sent_at'] = current_time( 'mysql' );
		return self::insert( $data );
	}

	/** @return object|null The most recent send for an invoice, or null if it's never been emailed. */
	public static function most_recent_for_invoice( $invoice_id ) {
		$rows = self::where( array( 'invoice_id' => $invoice_id ), 'sent_at DESC, id DESC', 1 );
		return $rows ? $rows[0] : null;
	}
}
