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
		if ( isset( $_POST['kcrm_action'] ) && 'save_company' === $_POST['kcrm_action'] ) {
			$this->save();
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

		$this->redirect( array( 'kcrm_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_company_' . $id );
		KCRM_Company::delete( $id );
		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}

	/** @return string[] The submitted checkboxes, restricted to known KCRM_Company::payment_types() keys. */
	private function sanitized_payment_types() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in save() before this is called.
		$submitted = isset( $_POST['accepted_payment_types'] ) ? (array) wp_unslash( $_POST['accepted_payment_types'] ) : array();
		return array_values( array_intersect( array_map( 'sanitize_key', $submitted ), array_keys( KCRM_Company::payment_types() ) ) );
	}

	/** @return string JSON-encoded [label, url] pairs from the payment-link repeater rows, blank rows dropped. */
	private function sanitized_payment_links() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in save() before this is called.
		$labels = isset( $_POST['payment_link_label'] ) ? (array) wp_unslash( $_POST['payment_link_label'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in save() before this is called.
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
