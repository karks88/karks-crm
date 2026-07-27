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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_service' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_import_upload() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_services_upload' === $_POST['kcrm_action'] ) {
			$this->handle_import_upload();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_import_run() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_services_run' === $_POST['kcrm_action'] ) {
			$this->handle_import_run();
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
			'is_taxable'  => isset( $_POST['is_taxable'] ) ? 1 : 0,
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

		$this->redirect( array( 'view' => 'edit', 'id' => $id, 'kcrm_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_service_' . $id );
		KCRM_Service::delete( $id );
		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}

	private function handle_import_upload() {
		check_admin_referer( 'kcrm_import_services_upload' );

		if ( ! $this->current_company_id() ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES is never magic-quotes-slashed by WordPress (wp_magic_quotes() only touches $_GET/$_POST/$_COOKIE/$_SERVER), so wp_unslash() here would incorrectly strip backslashes out of a Windows tmp_name path; KCRM_CSV_Import::store_upload() validates tmp_name, error, size, and extension before use.
		$file  = isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : array();
		$token = KCRM_CSV_Import::store_upload( $file );

		if ( is_wp_error( $token ) ) {
			$this->redirect( array( 'view' => 'import', 'kcrm_notice' => 'error' ) );
		}

		$this->redirect( array( 'view' => 'import', 'stage' => 'map', 'file' => $token ) );
	}

	/**
	 * Each row becomes a service. Rows sharing the same name only import
	 * once, and a service that already exists (by name) for this company is
	 * skipped -- safe to re-run against an updated export. There's no
	 * column in a typical QuickBooks items export that maps cleanly to our
	 * Hourly/Project-based distinction, so every imported row gets the one
	 * default type chosen on the mapping screen; edit individual services
	 * afterward if some should be the other type.
	 */
	private function handle_import_run() {
		check_admin_referer( 'kcrm_import_services_run' );

		$company_id = $this->current_company_id();
		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		$token = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$path  = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			$this->redirect( array( 'view' => 'import', 'kcrm_notice' => 'error' ) );
		}

		$map = isset( $_POST['map'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['map'] ) ) : array();

		if ( ( $map['name'] ?? -1 ) < 0 ) {
			$this->redirect( array( 'view' => 'import', 'stage' => 'map', 'file' => $token, 'kcrm_notice' => 'error' ) );
		}

		$default_type = isset( $_POST['service_type'] ) ? sanitize_key( wp_unslash( $_POST['service_type'] ) ) : KCRM_Service::TYPE_PROJECT;
		if ( ! array_key_exists( $default_type, KCRM_Service::types() ) ) {
			$default_type = KCRM_Service::TYPE_PROJECT;
		}

		$rows = KCRM_CSV_Import::read_rows( $path );

		$existing = array();
		foreach ( KCRM_Service::for_company( $company_id ) as $existing_service ) {
			$existing[ strtolower( trim( $existing_service->name ) ) ] = true;
		}

		$imported                  = 0;
		$skipped_no_name           = 0;
		$skipped_duplicate_in_file = 0;
		$skipped_existing          = 0;
		$seen_in_file              = array();

		foreach ( $rows as $row ) {
			$name = $this->mapped_cell( $row, $map, 'name' );

			if ( '' === $name ) {
				$skipped_no_name++;
				continue;
			}

			$key = strtolower( $name );

			if ( isset( $seen_in_file[ $key ] ) ) {
				$skipped_duplicate_in_file++;
				continue;
			}
			$seen_in_file[ $key ] = true;

			if ( isset( $existing[ $key ] ) ) {
				$skipped_existing++;
				continue;
			}

			KCRM_Service::create(
				array(
					'company_id'  => $company_id,
					'name'        => sanitize_text_field( $name ),
					'description' => sanitize_textarea_field( $this->mapped_cell( $row, $map, 'description' ) ),
					'type'        => $default_type,
					'rate'        => $this->mapped_amount( $row, $map, 'rate' ) ?? 0,
					'is_active'   => $this->parse_active_status( $this->mapped_cell( $row, $map, 'active_status' ) ),
				)
			);

			$imported++;
		}

		KCRM_CSV_Import::delete( $token );

		$this->redirect(
			array(
				'view'              => 'import',
				'stage'             => 'done',
				'imported'          => $imported,
				'skipped_no_name'   => $skipped_no_name,
				'skipped_duplicate' => $skipped_duplicate_in_file,
				'skipped_existing'  => $skipped_existing,
			)
		);
	}

	protected function mapped_cell( array $row, array $map, $field ) {
		$index = $map[ $field ] ?? -1;
		if ( $index < 0 || ! isset( $row[ $index ] ) ) {
			return '';
		}
		return sanitize_text_field( trim( (string) $row[ $index ] ) );
	}

	/** @return float|null Parsed numeric value (currency symbols/commas stripped), or null if blank/non-numeric. */
	protected function mapped_amount( array $row, array $map, $field ) {
		$raw = $this->mapped_cell( $row, $map, $field );
		if ( '' === $raw ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9.\-]/', '', $raw );
		return is_numeric( $clean ) ? (float) $clean : null;
	}

	private function parse_active_status( $value ) {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return 1;
		}
		return ( false !== strpos( $value, 'inactive' ) ) ? 0 : 1;
	}

	/** Header label for column $i, falling back to "Column N" (1-based) when the CSV header cell is blank. */
	protected function column_label( $label, $i ) {
		if ( '' !== trim( (string) $label ) ) {
			return $label;
		}
		/* translators: %d: 1-based CSV column number. */
		return sprintf( __( 'Column %d', 'karks-crm' ), $i + 1 );
	}

	/** Finds the first header column matching any candidate (exact match first, then substring). */
	protected function guess_column( array $header, array $candidates ) {
		foreach ( $candidates as $candidate ) {
			foreach ( $header as $i => $label ) {
				if ( strtolower( trim( $label ) ) === $candidate ) {
					return $i;
				}
			}
		}
		foreach ( $candidates as $candidate ) {
			foreach ( $header as $i => $label ) {
				if ( '' !== $candidate && false !== strpos( strtolower( $label ), $candidate ) ) {
					return $i;
				}
			}
		}
		return -1;
	}

	/** Target service fields for the CSV importer, with candidate header names (lowercase) used to guess a default column mapping. */
	protected function import_fields() {
		return array(
			'name'          => array(
				'label'    => __( 'Service Name', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'item', 'product/service', 'name', 'service' ),
			),
			'description'   => array(
				'label' => __( 'Description', 'karks-crm' ),
				'guess' => array( 'description', 'purchase description' ),
			),
			'rate'          => array(
				'label' => __( 'Rate', 'karks-crm' ),
				'guess' => array( 'price', 'sales price', 'rate' ),
			),
			'active_status' => array(
				'label' => __( 'Status column (e.g. Active/Inactive)', 'karks-crm' ),
				'guess' => array( 'active status', 'status' ),
			),
		);
	}
}
