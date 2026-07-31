<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_PDF {

	/**
	 * Render an invoice to HTML, convert with Dompdf, and stream it as a
	 * PDF download. Ends the request.
	 */
	public static function stream_invoice( $invoice ) {
		self::require_dompdf_or_die();

		$dompdf   = self::render_invoice( $invoice );
		$filename = sanitize_file_name( KCRM_Invoice::display_title( $invoice ) );
		$dompdf->stream( $filename . '.pdf', array( 'Attachment' => true ) );
		exit;
	}

	/**
	 * Same rendering as stream_invoice(), but returns the raw PDF bytes
	 * instead of streaming to the browser -- for attaching to an email
	 * (KCRM_Invoices_Controller::send_invoice_email()) rather than downloading.
	 */
	public static function invoice_pdf_bytes( $invoice ) {
		self::require_dompdf_or_die();
		return self::render_invoice( $invoice )->output();
	}

	/**
	 * Render a customer's (plus its Jobs') open invoices to HTML, convert
	 * with Dompdf, and stream it as a PDF download. Ends the request.
	 *
	 * @param object $customer   The primary customer (or Job) the export was requested for.
	 * @param int[]  $rollup_ids $customer's id plus its Jobs' ids, in display order.
	 */
	public static function stream_customer_open_balance( $customer, array $rollup_ids ) {
		self::require_dompdf_or_die();

		$dompdf   = self::render_customer_open_balance( $customer, $rollup_ids );
		$filename = sanitize_file_name( KCRM_Customer::display_name( $customer ) . ' - Open Balance' );
		$dompdf->stream( $filename . '.pdf', array( 'Attachment' => true ) );
		exit;
	}

	/** Shared HTML-build + Dompdf-render step for stream_customer_open_balance(). */
	private static function render_customer_open_balance( $customer, array $rollup_ids ) {
		$company   = KCRM_Company::find( $customer->company_id );
		$customers = KCRM_Customer::find_many( $rollup_ids );
		$invoices  = KCRM_Invoice::open_for_customers( $rollup_ids );
		$balances  = KCRM_Invoice::balances_for( $invoices );
		$logo_data = self::logo_data_uri( $company );

		ob_start();
		include KCRM_PLUGIN_DIR . 'templates/customer-open-balance-pdf.php';
		$html = ob_get_clean();

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'Helvetica' );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'letter' );
		$dompdf->render();

		return $dompdf;
	}

	private static function require_dompdf_or_die() {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die(
				esc_html__( 'PDF export is not available: the Dompdf library is missing. Run "composer install" inside the Karks CRM plugin folder.', 'karks-crm' )
			);
		}
	}

	/** Shared HTML-build + Dompdf-render step for stream_invoice()/invoice_pdf_bytes(). */
	private static function render_invoice( $invoice ) {
		$company     = KCRM_Company::find( $invoice->company_id );
		$customer    = KCRM_Customer::find( $invoice->customer_id );
		$items       = KCRM_Invoice_Item::for_invoice( $invoice->id );
		$payments    = KCRM_Payment::for_invoice( $invoice->id );
		$balance_due = KCRM_Invoice::balance_due( $invoice->id );
		$logo_data   = self::logo_data_uri( $company );

		ob_start();
		include KCRM_PLUGIN_DIR . 'templates/invoice-pdf.php';
		$html = ob_get_clean();

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'Helvetica' );

		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( $html );
		$dompdf->setPaper( 'letter' );
		$dompdf->render();

		return $dompdf;
	}

	public static function logo_data_uri( $company ) {
		if ( ! $company || empty( $company->logo_attachment_id ) ) {
			return '';
		}

		$path = get_attached_file( (int) $company->logo_attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return '';
		}

		$mime = get_post_mime_type( (int) $company->logo_attachment_id );
		if ( ! $mime ) {
			return '';
		}

		$data = file_get_contents( $path );
		if ( false === $data ) {
			return '';
		}

		return 'data:' . $mime . ';base64,' . base64_encode( $data );
	}
}
