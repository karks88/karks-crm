<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business logic shared between the wp-admin and front-end Services
 * screens (KCRM_Admin_Services / KCRM_Front_Services). Rendering is
 * left to those subclasses.
 */
abstract class KCRM_Services_Controller extends KCRM_Controller_Base {

	/** wp-admin menu slug, used by KCRM_Admin_Screen_Trait. */
	const PAGE = 'karks-crm-services';

	/** Front-end rewrite endpoint, used by KCRM_Front_Screen_Trait. */
	const ENDPOINT = 'services';

	public function handle_actions() {
		if ( isset( $_POST['kcrm_action'] ) && 'save_service' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_service' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$existing   = $id ? KCRM_Service::find( $id ) : null;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		$type = $this->field_or_existing( 'type', function ( $v ) { return sanitize_key( wp_unslash( $v ) ); }, $existing, KCRM_Service::TYPE_HOURLY );
		if ( ! array_key_exists( $type, KCRM_Service::types() ) ) {
			$type = KCRM_Service::TYPE_HOURLY;
		}

		$data = array(
			'company_id'  => $company_id,
			'name'        => $this->field_or_existing( 'name', function ( $v ) { return sanitize_text_field( wp_unslash( $v ) ); }, $existing ),
			'description' => $this->field_or_existing( 'description', function ( $v ) { return sanitize_textarea_field( wp_unslash( $v ) ); }, $existing ),
			'type'        => $type,
			'rate'        => $this->field_or_existing( 'rate', function ( $v ) { return (float) $v; }, $existing, 0 ),
			'is_active'   => isset( $_POST['is_active'] ) ? 1 : 0,
		);

		if ( '' === $data['name'] ) {
			$this->redirect( array( 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		if ( $id ) {
			unset( $data['company_id'] );
			KCRM_Service::save( $id, $data );
		} else {
			$id = KCRM_Service::create( $data );
		}

		$this->redirect( array( 'kcrm_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_service_' . $id );
		KCRM_Service::delete( $id );
		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}
}
