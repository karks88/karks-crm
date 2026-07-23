<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business logic shared between the wp-admin and front-end Customers
 * screens (KCRM_Admin_Customers / KCRM_Front_Customers). Rendering is
 * left to those subclasses.
 */
abstract class KCRM_Customers_Controller extends KCRM_Controller_Base {

	/** wp-admin menu slug, used by KCRM_Admin_Screen_Trait. */
	const PAGE = 'karks-crm-customers';

	/** Front-end rewrite endpoint, used by KCRM_Front_Screen_Trait. */
	const ENDPOINT = 'customers';

	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_customer' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_import_upload() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_upload' === $_POST['kcrm_action'] ) {
			$this->handle_import_upload();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_import_run() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_run' === $_POST['kcrm_action'] ) {
			$this->handle_import_run();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_customer' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$existing   = $id ? KCRM_Customer::find( $id ) : null;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		$status = $this->field_or_existing( 'status', function ( $v ) { return sanitize_key( wp_unslash( $v ) ); }, $existing, KCRM_Customer::STATUS_ACTIVE );
		if ( ! array_key_exists( $status, KCRM_Customer::statuses() ) ) {
			$status = KCRM_Customer::STATUS_ACTIVE;
		}

		$parent_id = $this->validated_parent_id( $id, $company_id );

		$text = function ( $v ) { return sanitize_text_field( wp_unslash( $v ) ); };

		$data = array(
			'company_id'               => $company_id,
			'parent_customer_id'       => $parent_id ?: null,
			'company_name'             => $this->field_or_existing( 'company_name', $text, $existing ),
			'contact_person'           => $this->field_or_existing( 'contact_person', $text, $existing ),
			'secondary_contact_person' => $this->field_or_existing( 'secondary_contact_person', $text, $existing ),
			'address_street'           => $this->field_or_existing( 'address_street', $text, $existing ),
			'address_city'             => $this->field_or_existing( 'address_city', $text, $existing ),
			'address_state'            => $this->field_or_existing( 'address_state', $text, $existing ),
			'address_postal_code'      => $this->field_or_existing( 'address_postal_code', $text, $existing ),
			'phone'                    => $this->field_or_existing( 'phone', $text, $existing ),
			'email'                    => $this->field_or_existing( 'email', function ( $v ) { return sanitize_email( wp_unslash( $v ) ); }, $existing ),
			'secondary_email'          => $this->field_or_existing( 'secondary_email', function ( $v ) { return sanitize_email( wp_unslash( $v ) ); }, $existing ),
			'notes'                    => $this->field_or_existing( 'notes', function ( $v ) { return sanitize_textarea_field( wp_unslash( $v ) ); }, $existing ),
			'status'                   => $status,
		);

		if ( '' === $data['company_name'] ) {
			$this->redirect( array( 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		if ( $id ) {
			unset( $data['company_id'] );
			KCRM_Customer::save( $id, $data );
		} else {
			$id = KCRM_Customer::create( $data );
		}

		$this->redirect( array( 'kcrm_notice' => 'saved' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_customer_' . $id );

		foreach ( KCRM_Customer::jobs_for( $id ) as $job ) {
			KCRM_Customer::delete( $job->id );
		}
		KCRM_Customer::delete( $id );

		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}

	/**
	 * A customer may only become a Job of a *top-level* customer in the same
	 * company (no more than one level of nesting), and can't become a Job
	 * of itself or of one of its own Jobs, and can't be assigned a parent
	 * while it already has Jobs of its own.
	 */
	private function validated_parent_id( $id, $company_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in save() before this is called.
		$parent_id = isset( $_POST['parent_customer_id'] ) ? absint( $_POST['parent_customer_id'] ) : 0;

		if ( ! $parent_id ) {
			return 0;
		}

		if ( $id && ( $parent_id === $id || ! empty( KCRM_Customer::jobs_for( $id ) ) ) ) {
			return 0;
		}

		$parent = KCRM_Customer::find( $parent_id );
		if ( ! $parent || (int) $parent->company_id !== $company_id || ! empty( $parent->parent_customer_id ) ) {
			return 0;
		}

		return $parent_id;
	}

	private function handle_import_upload() {
		check_admin_referer( 'kcrm_import_upload' );

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

	private function handle_import_run() {
		check_admin_referer( 'kcrm_import_run' );

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

		$company_col = $map['company_name'] ?? -1;
		if ( $company_col < 0 ) {
			$this->redirect( array( 'view' => 'import', 'stage' => 'map', 'file' => $token, 'kcrm_notice' => 'error' ) );
		}

		$rows = KCRM_CSV_Import::read_rows( $path );

		$existing = array();
		foreach ( KCRM_Customer::for_company( $company_id ) as $existing_customer ) {
			$existing[ strtolower( trim( $existing_customer->company_name ) ) ] = true;
		}

		$imported                  = 0;
		$skipped_no_name           = 0;
		$skipped_duplicate_in_file = 0;
		$skipped_existing          = 0;
		$seen_in_file              = array();

		foreach ( $rows as $row ) {
			$company_name = trim( (string) ( $row[ $company_col ] ?? '' ) );

			if ( '' === $company_name ) {
				$skipped_no_name++;
				continue;
			}

			$key = strtolower( $company_name );

			if ( isset( $seen_in_file[ $key ] ) ) {
				$skipped_duplicate_in_file++;
				continue;
			}
			$seen_in_file[ $key ] = true;

			if ( isset( $existing[ $key ] ) ) {
				$skipped_existing++;
				continue;
			}

			$contact_person = $this->mapped_cell( $row, $map, 'contact_person' );
			if ( '' === $contact_person ) {
				// QuickBooks sometimes leaves "Primary Contact" blank and only
				// fills in separate First/Last Name columns instead.
				$contact_person = trim( $this->mapped_cell( $row, $map, 'first_name' ) . ' ' . $this->mapped_cell( $row, $map, 'last_name' ) );
			}

			list( $address_street, $address_city, $address_state, $address_postal_code ) = $this->parse_address_block( $row, $map, $contact_person );

			$data = array(
				'company_id'               => $company_id,
				'company_name'             => sanitize_text_field( $company_name ),
				'contact_person'           => sanitize_text_field( $contact_person ),
				'secondary_contact_person' => $this->mapped_cell( $row, $map, 'secondary_contact_person' ),
				'phone'                    => $this->mapped_cell( $row, $map, 'phone' ),
				'email'                    => sanitize_email( $this->mapped_cell( $row, $map, 'email' ) ),
				'secondary_email'          => sanitize_email( $this->mapped_cell( $row, $map, 'secondary_email' ) ),
				'address_street'           => $address_street,
				'address_city'             => $address_city,
				'address_state'            => $address_state,
				'address_postal_code'      => $address_postal_code,
				'notes'                    => sanitize_textarea_field( $this->mapped_cell( $row, $map, 'notes' ) ),
				'status'                   => $this->parse_status( $this->mapped_cell( $row, $map, 'status_source' ) ),
			);

			KCRM_Customer::create( $data );
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

	private function parse_status( $value ) {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return KCRM_Customer::STATUS_ACTIVE;
		}
		return ( false !== strpos( $value, 'inactive' ) ) ? KCRM_Customer::STATUS_INACTIVE : KCRM_Customer::STATUS_ACTIVE;
	}

	/**
	 * QuickBooks-style "Bill to" address blocks are a variable number of
	 * free-form lines (name, attention, street, suite, city/state/zip) whose
	 * position shifts row to row depending on how many lines were used. So
	 * rather than trusting one fixed column per part, this scans every
	 * column in the mapped [from, to] range, finds the last cell that looks
	 * like "City, ST 12345", and treats everything before it as the street
	 * (skipping any line that just repeats the contact person's name).
	 *
	 * @return array [ street, city, state, postal_code ]
	 */
	private function parse_address_block( array $row, array $map, $contact_person ) {
		$from = $map['address_from'] ?? -1;
		$to   = $map['address_to'] ?? -1;

		if ( $from < 0 || $to < 0 || $to < $from ) {
			return array( '', '', '', '' );
		}

		$cells = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$value = isset( $row[ $i ] ) ? sanitize_text_field( trim( (string) $row[ $i ] ) ) : '';
			if ( '' !== $value ) {
				$cells[ $i ] = $value;
			}
		}

		$city        = '';
		$state       = '';
		$postal      = '';
		$match_index = null;

		foreach ( array_reverse( $cells, true ) as $i => $value ) {
			if ( preg_match( '/^(.*?),\s*([A-Za-z]{2})\.?\s+(\d{5}(?:-\d{4})?)\s*$/', $value, $matches ) ) {
				$city        = sanitize_text_field( trim( $matches[1] ) );
				$state       = strtoupper( $matches[2] );
				$postal      = sanitize_text_field( $matches[3] );
				$match_index = $i;
				break;
			}
		}

		$street_parts = array();
		foreach ( $cells as $i => $value ) {
			if ( null !== $match_index && $i >= $match_index ) {
				continue;
			}
			if ( '' !== $contact_person && 0 === strcasecmp( $value, $contact_person ) ) {
				continue; // Skip an "Attention: Name" line that just repeats the contact person.
			}
			$street_parts[] = $value;
		}

		return array( sanitize_text_field( implode( ', ', $street_parts ) ), $city, $state, $postal );
	}

	/**
	 * Target customer fields for the CSV importer, with candidate header
	 * names (lowercase) used to guess a default column mapping.
	 */
	protected function import_fields() {
		return array(
			'company_name'             => array(
				'label'    => __( 'Company Name', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'company' ),
			),
			'contact_person'           => array(
				'label' => __( 'Contact Person', 'karks-crm' ),
				'guess' => array( 'primary contact', 'contact person', 'contact' ),
			),
			'first_name'               => array(
				'label' => __( 'First Name (used if Contact Person is blank)', 'karks-crm' ),
				'guess' => array( 'first name' ),
			),
			'last_name'                => array(
				'label' => __( 'Last Name (used if Contact Person is blank)', 'karks-crm' ),
				'guess' => array( 'last name' ),
			),
			'secondary_contact_person' => array(
				'label' => __( 'Secondary Contact Person', 'karks-crm' ),
				'guess' => array( 'secondary contact' ),
			),
			'email'                    => array(
				'label' => __( 'Email Address', 'karks-crm' ),
				'guess' => array( 'main email', 'email' ),
			),
			'secondary_email'          => array(
				'label' => __( 'Secondary Email Address', 'karks-crm' ),
				'guess' => array( 'secondary email' ),
			),
			'phone'                    => array(
				'label' => __( 'Phone Number', 'karks-crm' ),
				'guess' => array( 'main phone', 'phone' ),
			),
			'status_source'            => array(
				'label' => __( 'Status column (e.g. Active/Inactive)', 'karks-crm' ),
				'guess' => array( 'active status', 'status' ),
			),
			'notes'                    => array(
				'label' => __( 'Notes', 'karks-crm' ),
				'guess' => array(),
			),
		);
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

	/**
	 * Guesses the [from, to] column range for the free-form address block.
	 * QuickBooks-style exports repeat several "Bill to N" columns; the first
	 * one is always the name/company line, so that one is excluded.
	 *
	 * @return array [ from, to ] column indexes, or [ -1, -1 ] if none found.
	 */
	protected function guess_address_range( array $header ) {
		$bill_to = array();
		foreach ( $header as $i => $label ) {
			if ( false !== stripos( $label, 'bill to' ) ) {
				$bill_to[] = $i;
			}
		}

		if ( $bill_to ) {
			sort( $bill_to );
			return count( $bill_to ) > 1
				? array( $bill_to[1], end( $bill_to ) )
				: array( $bill_to[0], $bill_to[0] );
		}

		$from = $this->guess_column( $header, array( 'street', 'address' ) );
		return $from < 0 ? array( -1, -1 ) : array( $from, $from );
	}
}
