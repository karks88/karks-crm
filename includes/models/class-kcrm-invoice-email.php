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

	/**
	 * Email sends on/after a cutoff (a 'Y-m-d H:i:s' string) for invoices
	 * belonging to a company -- for the company profile's Recent Actions
	 * feed. Joins to the invoices table since this table has no company_id
	 * of its own, and pulls invoice_number/customer_id along for the link.
	 */
	public static function recent_for_company( $company_id, $since ) {
		global $wpdb;
		$sql = 'SELECT e.*, i.invoice_number, i.customer_id FROM %i e INNER JOIN %i i ON i.id = e.invoice_id WHERE i.company_id = %d AND e.sent_at >= %s ORDER BY e.sent_at DESC, e.id DESC';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a %i/%d/%s placeholder template filled in via $wpdb->prepare() on the same line.
		return $wpdb->get_results( $wpdb->prepare( $sql, self::table(), KCRM_DB::invoices(), $company_id, $since ) );
	}
}
