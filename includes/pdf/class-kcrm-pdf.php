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
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die(
				esc_html__( 'PDF export is not available: the Dompdf library is missing. Run "composer install" inside the Karks CRM plugin folder.', 'karks-crm' )
			);
		}

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

		$filename = sanitize_file_name( $invoice->invoice_number ? $invoice->invoice_number : 'invoice-' . $invoice->id );
		$dompdf->stream( $filename . '.pdf', array( 'Attachment' => true ) );
		exit;
	}

	private static function logo_data_uri( $company ) {
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
