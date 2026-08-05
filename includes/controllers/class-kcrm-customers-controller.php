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

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; receive_payment() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'receive_payment' === $_POST['kcrm_action'] ) {
			$this->receive_payment();
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
			'invoice_recipient_name'   => $this->field_or_existing( 'invoice_recipient_name', $text, $existing ),
			'invoice_recipient_email'  => $this->field_or_existing( 'invoice_recipient_email', function ( $v ) { return sanitize_email( wp_unslash( $v ) ); }, $existing ),
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

		$this->redirect( array( 'view' => 'edit', 'id' => $id, 'kcrm_notice' => 'saved' ) );
	}

	/**
	 * Splits one payment across several open invoices for a customer and its
	 * Jobs in a single submission -- QuickBooks' "Receive Payment" workflow.
	 * Every row this creates is a completely normal single-invoice payment
	 * (see KCRM_Payment::create()); they're only linked by a shared batch_id
	 * so they can be traced back to the same submission later. Validated
	 * fully before any row is written, so a rejected submission never
	 * partially applies.
	 */
	private function receive_payment() {
		check_admin_referer( 'kcrm_receive_payment' );

		$company_id  = $this->current_company_id();
		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;

		if ( ! $company_id || ! $customer_id ) {
			$this->redirect( array( 'kcrm_notice' => 'error' ) );
		}

		$method = sanitize_text_field( wp_unslash( $_POST['method'] ?? '' ) );
		if ( '__other__' === $method ) {
			$method = sanitize_text_field( wp_unslash( $_POST['method_other'] ?? '' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified above via check_admin_referer(); sanitize_date_or_null() sanitizes internally.
		$payment_date = isset( $_POST['payment_date'] ) ? $this->sanitize_date_or_null( wp_unslash( $_POST['payment_date'] ) ) : null;
		if ( ! $payment_date ) {
			$payment_date = current_time( 'Y-m-d' );
		}

		$note = sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce already verified above; each entry is cast with absint() below.
		$invoice_ids = isset( $_POST['invoice_id'] ) ? (array) wp_unslash( $_POST['invoice_id'] ) : array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- nonce already verified above; each entry is cast with (float) below.
		$amounts = isset( $_POST['allocation_amount'] ) ? (array) wp_unslash( $_POST['allocation_amount'] ) : array();

		$rows = array();
		foreach ( $invoice_ids as $index => $raw_invoice_id ) {
			$amount = isset( $amounts[ $index ] ) ? (float) $amounts[ $index ] : 0;
			if ( $amount <= 0 ) {
				continue;
			}

			$invoice = KCRM_Invoice::find( absint( $raw_invoice_id ) );
			if ( ! $invoice || (int) $invoice->company_id !== $company_id ) {
				$this->redirect( array( 'view' => 'edit', 'id' => $customer_id, 'kcrm_notice' => 'error' ) );
			}

			// Reject the whole submission rather than capping the amount --
			// silently truncating would apply less than the client actually
			// paid without telling anyone.
			if ( $amount > KCRM_Invoice::balance_due( $invoice->id ) + 0.005 ) {
				$this->redirect( array( 'view' => 'edit', 'id' => $customer_id, 'kcrm_notice' => 'overpay' ) );
			}

			$rows[] = array(
				'invoice' => $invoice,
				'amount'  => round( $amount, 2 ),
			);
		}

		if ( empty( $rows ) ) {
			$this->redirect( array( 'view' => 'edit', 'id' => $customer_id, 'kcrm_notice' => 'error' ) );
		}

		$batch_id = wp_generate_uuid4();
		foreach ( $rows as $row ) {
			KCRM_Payment::create(
				array(
					'invoice_id'   => $row['invoice']->id,
					'customer_id'  => $row['invoice']->customer_id,
					'company_id'   => $company_id,
					'amount'       => $row['amount'],
					'payment_date' => $payment_date,
					'method'       => $method,
					'note'         => $note,
					'batch_id'     => $batch_id,
				)
			);
		}

		$this->redirect( array( 'view' => 'edit', 'id' => $customer_id, 'kcrm_notice' => 'saved' ) );
	}

	/** Nonce-protected admin-post URL for the Open Balance PDF/CSV export links -- shared by the admin and front-end Customers screens (KCRM_Front_Reports' Customer Report builds its own copy, since it isn't part of this class hierarchy). */
	protected function open_balance_export_url( $customer_id, $format ) {
		$url = add_query_arg(
			array(
				'action'      => 'kcrm_export_customer_open_balance_' . $format,
				'customer_id' => $customer_id,
			),
			admin_url( 'admin-post.php' )
		);
		return wp_nonce_url( $url, 'kcrm_export_open_balance_' . $customer_id );
	}

	/**
	 * admin-post handler: streams a customer's (plus its Jobs') Open Balance
	 * PDF (see KCRM_PDF::stream_customer_open_balance()). Registered once,
	 * in KCRM_Plugin::run(), and reachable from both wp-admin and the front
	 * end since admin-post.php isn't wp-admin-only.
	 */
	public function handle_open_balance_pdf() {
		list( $customer, $rollup_ids ) = $this->resolve_open_balance_export_request();
		KCRM_PDF::stream_customer_open_balance( $customer, $rollup_ids );
	}

	/** admin-post handler: streams a customer's (plus its Jobs') Open Balance CSV. See handle_open_balance_pdf() docblock. */
	public function handle_open_balance_csv() {
		list( $customer, $rollup_ids ) = $this->resolve_open_balance_export_request();

		$invoices  = KCRM_Invoice::open_for_customers( $rollup_ids );
		$balances  = KCRM_Invoice::balances_for( $invoices );
		$customers = KCRM_Customer::find_many( $rollup_ids );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( KCRM_Customer::display_name( $customer ) . '-open-balance-' . gmdate( 'Y-m-d' ) ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( __( 'Customer', 'karks-crm' ), __( 'Type', 'karks-crm' ), __( 'Date', 'karks-crm' ), __( 'Num', 'karks-crm' ), __( 'Due Date', 'karks-crm' ), __( 'Open Balance', 'karks-crm' ), __( 'Amount', 'karks-crm' ) ) );

		$total_balance = 0.0;
		$total_amount  = 0.0;
		foreach ( $rollup_ids as $id ) {
			$id             = (int) $id;
			$group_customer = $customers[ $id ] ?? null;
			if ( ! $group_customer ) {
				continue;
			}
			foreach ( $invoices as $invoice ) {
				if ( (int) $invoice->customer_id !== $id ) {
					continue;
				}
				$balance        = $balances[ $invoice->id ];
				$total_balance += $balance;
				$total_amount  += (float) $invoice->total;
				fputcsv(
					$out,
					array(
						$group_customer->company_name,
						__( 'Invoice', 'karks-crm' ),
						$invoice->issue_date,
						$invoice->invoice_number,
						$invoice->due_date,
						number_format( $balance, 2, '.', '' ),
						number_format( (float) $invoice->total, 2, '.', '' ),
					)
				);
			}
		}
		fputcsv( $out, array( '', '', '', '', __( 'Total', 'karks-crm' ), number_format( $total_balance, 2, '.', '' ), number_format( $total_amount, 2, '.', '' ) ) );

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
	}

	/** Shared GET-parsing + auth/nonce/lookup step for both open-balance export handlers. @return array{0:object,1:int[]} [ $customer, $rollup_ids ] */
	private function resolve_open_balance_export_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read only to build the nonce action name; check_admin_referer() verifies it on the next line.
		$customer_id = isset( $_GET['customer_id'] ) ? absint( $_GET['customer_id'] ) : 0;
		check_admin_referer( 'kcrm_export_open_balance_' . $customer_id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ) );
		}

		$customer = $customer_id ? KCRM_Customer::find( $customer_id ) : null;
		if ( ! $customer ) {
			wp_die( esc_html__( 'Customer not found.', 'karks-crm' ) );
		}

		$job_ids    = wp_list_pluck( KCRM_Customer::jobs_for( $customer_id ), 'id' );
		$rollup_ids = array_map( 'absint', array_merge( array( $customer_id ), $job_ids ) );

		return array( $customer, $rollup_ids );
	}

	/** Nonce-protected admin-post URL for the Customers list CSV export, carrying over the active/inactive filter so the export matches what's currently on screen. */
	public function export_customers_csv_url() {
		$args = array( 'action' => 'kcrm_export_customers_csv' );
		if ( $this->show_all_customers_requested() ) {
			$args['kcrm_status_filter'] = 'all';
		}
		$url = add_query_arg( $args, admin_url( 'admin-post.php' ) );
		return wp_nonce_url( $url, 'kcrm_export_customers_csv' );
	}

	/**
	 * admin-post handler: streams a CSV of the current company's top-level
	 * customers (plus their Jobs, always included alongside their parent
	 * regardless of the filter -- same as how the list table displays them)
	 * respecting the active/inactive filter from the Customers list.
	 */
	public function handle_export_customers_csv() {
		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ) );
		}
		check_admin_referer( 'kcrm_export_customers_csv' );

		$company_id = $this->current_company_id();
		$company    = $company_id ? KCRM_Company::find( $company_id ) : null;
		if ( ! $company ) {
			wp_die( esc_html__( 'Please create a company first.', 'karks-crm' ) );
		}

		$status_filter = $this->show_all_customers_requested() ? null : KCRM_Customer::STATUS_ACTIVE;
		$statuses      = KCRM_Customer::statuses();

		$top_level          = KCRM_Customer::top_level_for_company( $company_id, 'company_name ASC', $status_filter );
		$jobs_by_parent     = KCRM_Customer::jobs_for_many( wp_list_pluck( $top_level, 'id' ) );
		$all_jobs           = empty( $jobs_by_parent ) ? array() : array_merge( ...array_values( $jobs_by_parent ) );
		$top_level_balances = KCRM_Customer::balances_for_top_level( $top_level );
		$job_balances       = KCRM_Customer::balances_for( wp_list_pluck( $all_jobs, 'id' ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $company->name . '-customers-' . gmdate( 'Y-m-d' ) ) . '.csv"' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- streaming a CSV download to php://output, not a real file; WP_Filesystem has no equivalent.
		$out = fopen( 'php://output', 'w' );
		fputcsv(
			$out,
			array(
				__( 'Company Name', 'karks-crm' ),
				__( 'Job Of', 'karks-crm' ),
				__( 'Contact Person', 'karks-crm' ),
				__( 'Secondary Contact Person', 'karks-crm' ),
				__( 'Email', 'karks-crm' ),
				__( 'Secondary Email', 'karks-crm' ),
				__( 'Phone', 'karks-crm' ),
				__( 'Street Address', 'karks-crm' ),
				__( 'City', 'karks-crm' ),
				__( 'State', 'karks-crm' ),
				__( 'Postal Code', 'karks-crm' ),
				__( 'Invoice Recipient Name', 'karks-crm' ),
				__( 'Invoice Recipient Email', 'karks-crm' ),
				__( 'Status', 'karks-crm' ),
				__( 'Balance', 'karks-crm' ),
			)
		);

		$customer_row = function ( $customer, $job_of, $balance ) use ( $statuses ) {
			return array(
				$customer->company_name,
				$job_of,
				$customer->contact_person,
				$customer->secondary_contact_person,
				$customer->email,
				$customer->secondary_email,
				$customer->phone,
				$customer->address_street,
				$customer->address_city,
				$customer->address_state,
				$customer->address_postal_code,
				$customer->invoice_recipient_name,
				$customer->invoice_recipient_email,
				$statuses[ $customer->status ] ?? $customer->status,
				number_format( $balance, 2, '.', '' ),
			);
		};

		foreach ( $top_level as $customer ) {
			fputcsv( $out, $customer_row( $customer, '', $top_level_balances[ (int) $customer->id ] ) );

			foreach ( $jobs_by_parent[ $customer->id ] ?? array() as $job ) {
				fputcsv( $out, $customer_row( $job, $customer->company_name, $job_balances[ (int) $job->id ] ) );
			}
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- closing the php://output stream handle opened above, not a real file.
		exit;
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
