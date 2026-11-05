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
			'sent_cc'       => '%s',
			'sent_bcc'      => '%s',
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
	 * Most recent send time for each of several invoices, in one query -- for
	 * the invoices list's "emailed" indicator, so it doesn't need a
	 * most_recent_for_invoice() call per row.
	 *
	 * @return array invoice_id => sent_at ('Y-m-d H:i:s'); never-emailed invoices are simply absent.
	 */
	public static function last_sent_for_invoices( array $invoice_ids ) {
		global $wpdb;

		$invoice_ids = array_unique( array_filter( array_map( 'absint', $invoice_ids ) ) );
		if ( empty( $invoice_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $invoice_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is only repeated %d placeholder syntax (its count matches count( $invoice_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT invoice_id, MAX(sent_at) AS sent_at FROM %i WHERE invoice_id IN (' . $placeholders . ') GROUP BY invoice_id', array_merge( array( self::table() ), $invoice_ids ) ) );

		$last_sent = array();
		foreach ( $rows as $row ) {
			$last_sent[ (int) $row->invoice_id ] = $row->sent_at;
		}
		return $last_sent;
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
