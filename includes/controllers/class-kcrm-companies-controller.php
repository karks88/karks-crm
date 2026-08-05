<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business logic shared between the wp-admin and front-end Companies
 * screens (KCRM_Admin_Companies / KCRM_Front_Companies). Rendering is
 * left to those subclasses.
 */
abstract class KCRM_Companies_Controller extends KCRM_Controller_Base {

	/** wp-admin menu slug, used by KCRM_Admin_Screen_Trait. */
	const PAGE = 'karks-crm-companies';

	/** Front-end rewrite endpoint, used by KCRM_Front_Screen_Trait. */
	const ENDPOINT = 'companies';

	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_company' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_import_company() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_company' === $_POST['kcrm_action'] ) {
			$this->handle_import_company();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_company' );

		$id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$existing = $id ? KCRM_Company::find( $id ) : null;

		$text = function ( $v ) { return sanitize_text_field( wp_unslash( $v ) ); };
		$html = function ( $v ) { return wp_kses_post( wp_unslash( $v ) ); };

		$data = array(
			'name'                   => $this->field_or_existing( 'name', $text, $existing ),
			'email'                  => $this->field_or_existing( 'email', function ( $v ) { return sanitize_email( wp_unslash( $v ) ); }, $existing ),
			'phone'                  => $this->field_or_existing( 'phone', $text, $existing ),
			'address_street'         => $this->field_or_existing( 'address_street', $text, $existing ),
			'address_city'           => $this->field_or_existing( 'address_city', $text, $existing ),
			'address_state'          => $this->field_or_existing( 'address_state', $text, $existing ),
			'address_postal_code'    => $this->field_or_existing( 'address_postal_code', $text, $existing ),
			'logo_attachment_id'     => $this->field_or_existing( 'logo_attachment_id', 'absint', $existing, 0 ),
			'invoice_prefix'         => $this->field_or_existing( 'invoice_prefix', $text, $existing, 'INV-' ),
			'next_invoice_number'    => $this->field_or_existing( 'next_invoice_number', function ( $v ) { return max( 1, absint( $v ) ); }, $existing, 1 ),
			'default_tax_rate'       => $this->field_or_existing( 'default_tax_rate', function ( $v ) { return (float) $v; }, $existing, 0 ),
			'currency'               => $this->field_or_existing( 'currency', $text, $existing, 'USD' ),
			'invoice_footer'         => $this->field_or_existing( 'invoice_footer', $html, $existing ),
			'accepted_payment_types' => implode( ',', $this->sanitized_payment_types() ),
			'payment_links'          => $this->sanitized_payment_links(),
			'check_payable_to'       => $this->field_or_existing( 'check_payable_to', $text, $existing ),
			'pdf_accent_color'       => $this->field_or_existing( 'pdf_accent_color', function ( $v ) { $hex = sanitize_hex_color( wp_unslash( $v ) ); return $hex ? $hex : ''; }, $existing ),
			'email_template'         => $this->field_or_existing( 'email_template', $html, $existing ),
			// A real checkbox: unchecked means entirely absent from $_POST, not "field not submitted" -- field_or_existing() can't tell those apart, so check presence directly instead of falling back to the existing value.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified above via check_admin_referer().
			'invoice_bcc_enabled'    => isset( $_POST['invoice_bcc_enabled'] ) ? 1 : 0,
			'invoice_bcc_email'      => $this->field_or_existing( 'invoice_bcc_email', function ( $v ) { return sanitize_email( wp_unslash( $v ) ); }, $existing ),
		);

		if ( '' === $data['name'] ) {
			$this->redirect( array( 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		if ( $id ) {
			KCRM_Company::save( $id, $data );
		} else {
			$id = KCRM_Company::create( $data );
			update_user_meta( get_current_user_id(), KCRM_Context::META_KEY, $id );
		}

		$this->redirect( array( 'view' => 'edit', 'id' => $id, 'kcrm_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_company_' . $id );
		KCRM_Company::delete( $id );
		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}

	/**
	 * Imports a full company (profile, customers, services, invoices, line
	 * items, payments) from a previously-exported JSON file -- always as a
	 * brand-new company (see KCRM_Company_Transfer), never merged into an
	 * existing one.
	 */
	private function handle_import_company() {
		check_admin_referer( 'kcrm_import_company' );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- tmp_name/error/size are server-generated, never user input; $_FILES isn't slashed by WordPress in the first place (wp_unslash() here would corrupt a Windows tmp_name path). 'name' is validated (extension/size) below before use.
		$file = isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : array();

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ( ! empty( $file['error'] ) && UPLOAD_ERR_OK !== $file['error'] ) ) {
			$this->redirect( array( 'view' => 'import_company', 'kcrm_notice' => 'error' ) );
		}

		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( 'json' !== $ext || $file['size'] > 5242880 ) {
			$this->redirect( array( 'view' => 'import_company', 'kcrm_notice' => 'error' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_get_contents -- reading a just-uploaded tmp file, not a remote URL; WP_Filesystem offers no benefit here.
		$contents = file_get_contents( $file['tmp_name'] );
		$data     = json_decode( (string) $contents, true );

		if ( ! is_array( $data ) ) {
			$this->redirect( array( 'view' => 'import_company', 'kcrm_notice' => 'error' ) );
		}

		$result = KCRM_Company_Transfer::import( $data );

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				array(
					'view'              => 'import_company',
					'kcrm_import_error' => rawurlencode( $result->get_error_message() ),
				)
			);
		}

		$this->redirect(
			array(
				'view'       => 'import_company',
				'stage'      => 'done',
				'company_id' => $result['company_id'],
				'customers'  => $result['customers'],
				'services'   => $result['services'],
				'invoices'   => $result['invoices'],
				'payments'   => $result['payments'],
			)
		);
	}

	/**
	 * admin-post handler: streams this company's full data (profile,
	 * customers, services, invoices, line items, payments) as a JSON file
	 * download, for migrating to (or duplicating on) another site. Reachable
	 * from both wp-admin (Companies list) and the front end (Tools screen)
	 * since admin-post.php isn't wp-admin-only -- see KCRM_Company_Transfer.
	 */
	public function handle_export_download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'kcrm_export_company_' . $id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ) );
		}

		$company = $id ? KCRM_Company::find( $id ) : null;
		if ( ! $company ) {
			wp_die( esc_html__( 'Company not found.', 'karks-crm' ) );
		}

		$data = KCRM_Company_Transfer::export( $id );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $company->name . '-export-' . gmdate( 'Y-m-d' ) ) . '.json"' );
		echo wp_json_encode( $data );
		exit;
	}

	/** @return string[] The submitted checkboxes, restricted to known KCRM_Company::payment_types() keys. */
	private function sanitized_payment_types() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save() before this is called; sanitized via array_map('sanitize_key', ...) below.
		$submitted = isset( $_POST['accepted_payment_types'] ) ? (array) wp_unslash( $_POST['accepted_payment_types'] ) : array();
		return array_values( array_intersect( array_map( 'sanitize_key', $submitted ), array_keys( KCRM_Company::payment_types() ) ) );
	}

	/** @return string JSON-encoded [label, url] pairs from the payment-link repeater rows, blank rows dropped. */
	private function sanitized_payment_links() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save() before this is called; each label is sanitized per-row below (sanitize_text_field).
		$labels = isset( $_POST['payment_link_label'] ) ? (array) wp_unslash( $_POST['payment_link_label'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save() before this is called; each URL is sanitized per-row below (esc_url_raw).
		$urls = isset( $_POST['payment_link_url'] ) ? (array) wp_unslash( $_POST['payment_link_url'] ) : array();

		$links = array();
		foreach ( $labels as $index => $label ) {
			$label = sanitize_text_field( $label );
			$url   = isset( $urls[ $index ] ) ? esc_url_raw( trim( $urls[ $index ] ) ) : '';

			if ( '' === $label && '' === $url ) {
				continue; // Skip blank rows.
			}

			$links[] = array(
				'label' => $label,
				'url'   => $url,
			);
		}

		return $links ? wp_json_encode( $links ) : '';
	}
}
