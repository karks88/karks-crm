<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Business logic shared between the wp-admin and front-end Invoices
 * screens (KCRM_Admin_Invoices / KCRM_Front_Invoices). Rendering is
 * left to those subclasses.
 */
abstract class KCRM_Invoices_Controller extends KCRM_Controller_Base {

	/** wp-admin menu slug, used by KCRM_Admin_Screen_Trait. */
	const PAGE = 'karks-crm-invoices';

	/** Front-end rewrite endpoint, used by KCRM_Front_Screen_Trait. */
	const ENDPOINT = 'invoices';

	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_invoice' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; add_payment() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'add_payment' === $_POST['kcrm_action'] ) {
			$this->add_payment();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; send_invoice_email() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'send_invoice_email' === $_POST['kcrm_action'] ) {
			$this->send_invoice_email();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_invoice_import_upload() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_invoices_upload' === $_POST['kcrm_action'] ) {
			$this->handle_invoice_import_upload();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_invoice_import_run() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_invoices_run' === $_POST['kcrm_action'] ) {
			$this->handle_invoice_import_run();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_payment_import_upload() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_payments_upload' === $_POST['kcrm_action'] ) {
			$this->handle_payment_import_upload();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; handle_payment_import_run() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'import_payments_run' === $_POST['kcrm_action'] ) {
			$this->handle_payment_import_run();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete_payment() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['payment_id'] ) && 'delete_payment' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete_payment() verifies the nonce itself.
			$this->delete_payment( absint( $_GET['payment_id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_invoice' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$existing   = $id ? KCRM_Invoice::find( $id ) : null;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		$customer_id = isset( $_POST['customer_id'] ) ? absint( $_POST['customer_id'] ) : 0;
		$customer    = $customer_id ? KCRM_Customer::find( $customer_id ) : null;

		if ( ! $customer || (int) $customer->company_id !== $company_id ) {
			$this->redirect( array( 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		$status = $this->field_or_existing( 'status', function ( $v ) { return sanitize_key( wp_unslash( $v ) ); }, $existing, KCRM_Invoice::STATUS_OPEN );
		if ( ! array_key_exists( $status, KCRM_Invoice::statuses() ) ) {
			$status = KCRM_Invoice::STATUS_OPEN;
		}

		$invoice_type = $this->field_or_existing( 'invoice_type', function ( $v ) { return sanitize_key( wp_unslash( $v ) ); }, $existing, KCRM_Invoice::TYPE_OTHER );
		if ( ! array_key_exists( $invoice_type, KCRM_Invoice::types() ) ) {
			$invoice_type = KCRM_Invoice::TYPE_OTHER;
		}

		$data = array(
			'company_id'         => $company_id,
			'customer_id'        => $customer_id,
			'status'             => $status,
			'issue_date'         => $this->field_or_existing( 'issue_date', function ( $v ) { return $this->sanitize_date( $v ); }, $existing ),
			'due_date'           => $this->field_or_existing( 'due_date', function ( $v ) { return $this->sanitize_date( $v ); }, $existing ),
			'invoice_type'       => $invoice_type,
			'invoice_type_month' => KCRM_Invoice::TYPE_MONTH_YEAR === $invoice_type ? $this->field_or_existing( 'invoice_type_month', function ( $v ) { return $this->sanitize_month( $v ); }, $existing ) : null,
			'invoice_type_other' => KCRM_Invoice::TYPE_OTHER === $invoice_type ? $this->field_or_existing( 'invoice_type_other', function ( $v ) { return sanitize_text_field( wp_unslash( $v ) ); }, $existing ) : null,
			'notes'              => $this->field_or_existing( 'notes', function ( $v ) { return sanitize_textarea_field( wp_unslash( $v ) ); }, $existing ),
			'tax_rate'           => $this->field_or_existing( 'tax_rate', function ( $v ) { return (float) $v; }, $existing, 0 ),
		);

		if ( $id ) {
			unset( $data['company_id'] );
			KCRM_Invoice::save( $id, $data );
		} else {
			$data['invoice_number'] = KCRM_Company::next_invoice_number( $company_id );
			$id                     = KCRM_Invoice::create( $data );
		}

		$this->save_line_items( $id );
		KCRM_Invoice::recalculate_totals( $id );

		$this->redirect( array( 'view' => 'edit', 'id' => $id, 'kcrm_notice' => 'saved' ) );
	}

	private function save_line_items( $invoice_id ) {
		// A real form submission always includes item_description (the
		// form always renders at least one, blank, row) -- if it's absent
		// entirely, this is a partial/malformed request, so leave the
		// invoice's existing line items alone instead of wiping them with
		// nothing to replace them.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified in save() (the only caller) before this is called.
		if ( ! isset( $_POST['item_description'] ) ) {
			return;
		}

		KCRM_Invoice_Item::delete_for_invoice( $invoice_id );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); each value is sanitized per-row below (sanitize_text_field).
		$descriptions = isset( $_POST['item_description'] ) ? (array) wp_unslash( $_POST['item_description'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); each value is sanitized per-row below (sanitize_key).
		$types = isset( $_POST['item_type'] ) ? (array) wp_unslash( $_POST['item_type'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); each value is cast to float per-row below.
		$quantities = isset( $_POST['item_quantity'] ) ? (array) wp_unslash( $_POST['item_quantity'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); each value is cast to float per-row below.
		$rates = isset( $_POST['item_rate'] ) ? (array) wp_unslash( $_POST['item_rate'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); each value is cast with absint() per-row below.
		$service_ids = isset( $_POST['item_service_id'] ) ? (array) wp_unslash( $_POST['item_service_id'] ) : array();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified in save(); each value is compared to '1' per-row below. A hidden input paired with each row's checkbox guarantees one array entry per row regardless of checked state.
		$is_taxable_flags = isset( $_POST['item_is_taxable'] ) ? (array) wp_unslash( $_POST['item_is_taxable'] ) : array();

		$sort = 0;
		foreach ( $descriptions as $index => $description ) {
			$description = sanitize_text_field( $description );
			$quantity    = isset( $quantities[ $index ] ) ? (float) $quantities[ $index ] : 0;
			$rate        = isset( $rates[ $index ] ) ? (float) $rates[ $index ] : 0;

			if ( '' === $description && 0.0 === $quantity && 0.0 === $rate ) {
				continue; // Skip blank rows.
			}

			$type = isset( $types[ $index ] ) ? sanitize_key( $types[ $index ] ) : KCRM_Service::TYPE_PROJECT;
			if ( ! array_key_exists( $type, KCRM_Service::types() ) ) {
				$type = KCRM_Service::TYPE_PROJECT;
			}

			$service_id = isset( $service_ids[ $index ] ) ? absint( $service_ids[ $index ] ) : 0;
			$is_taxable = isset( $is_taxable_flags[ $index ] ) && '1' === (string) $is_taxable_flags[ $index ] ? 1 : 0;

			KCRM_Invoice_Item::insert(
				array(
					'invoice_id'  => $invoice_id,
					'service_id'  => $service_id ?: null,
					'description' => $description,
					'type'        => $type,
					'quantity'    => $quantity,
					'rate'        => $rate,
					'amount'      => round( $quantity * $rate, 2 ),
					'is_taxable'  => $is_taxable,
					'sort_order'  => $sort++,
				)
			);
		}
	}

	private function add_payment() {
		check_admin_referer( 'kcrm_add_payment' );

		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;
		$invoice    = $invoice_id ? KCRM_Invoice::find( $invoice_id ) : null;

		if ( ! $invoice || (int) $invoice->company_id !== $this->current_company_id() ) {
			$this->redirect( array( 'kcrm_notice' => 'error' ) );
		}

		$method = sanitize_text_field( wp_unslash( $_POST['method'] ?? '' ) );
		if ( '__other__' === $method ) {
			$method = sanitize_text_field( wp_unslash( $_POST['method_other'] ?? '' ) );
		}

		$amount = isset( $_POST['amount'] ) ? (float) $_POST['amount'] : 0;
		if ( $amount > 0 ) {
			KCRM_Payment::create(
				array(
					'invoice_id'   => $invoice_id,
					'customer_id'  => $invoice->customer_id,
					'company_id'   => $invoice->company_id,
					'amount'       => $amount,
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_date() unslashes and validates internally.
					'payment_date' => $this->sanitize_date( $_POST['payment_date'] ?? '' ),
					'method'       => $method,
					'note'         => sanitize_text_field( wp_unslash( $_POST['note'] ?? '' ) ),
				)
			);
		}

		$this->redirect( array( 'view' => 'edit', 'id' => $invoice_id, 'kcrm_notice' => 'saved' ) );
	}

	/**
	 * Emails the invoice as an HTML message with the same PDF that
	 * "Download PDF Invoice" generates attached (built fresh via
	 * KCRM_PDF::invoice_pdf_bytes(), never touching disk -- attached
	 * straight from memory via a one-shot phpmailer_init hook). Delivery
	 * itself goes through wp_mail(), so it's handled by whatever SMTP
	 * plugin is configured, same as every other WordPress email.
	 */
	private function send_invoice_email() {
		$invoice_id = isset( $_POST['invoice_id'] ) ? absint( $_POST['invoice_id'] ) : 0;
		check_admin_referer( 'kcrm_send_invoice_email_' . $invoice_id );

		$invoice = $invoice_id ? KCRM_Invoice::find( $invoice_id ) : null;

		if ( ! $invoice || (int) $invoice->company_id !== $this->current_company_id() ) {
			$this->redirect( array( 'kcrm_notice' => 'error' ) );
		}

		$to_email = isset( $_POST['email_to'] ) ? sanitize_email( wp_unslash( $_POST['email_to'] ) ) : '';
		$to_name  = isset( $_POST['email_to_name'] ) ? sanitize_text_field( wp_unslash( $_POST['email_to_name'] ) ) : '';
		$subject  = isset( $_POST['email_subject'] ) ? sanitize_text_field( wp_unslash( $_POST['email_subject'] ) ) : '';
		$body     = isset( $_POST['email_body'] ) ? wp_kses_post( wp_unslash( $_POST['email_body'] ) ) : '';

		if ( ! is_email( $to_email ) || '' === $subject || '' === $body ) {
			$this->redirect( array( 'view' => 'edit', 'id' => $invoice_id, 'kcrm_notice' => 'email_error' ) );
		}

		$pdf_bytes = KCRM_PDF::invoice_pdf_bytes( $invoice );
		$filename  = sanitize_file_name( $invoice->invoice_number ? $invoice->invoice_number : 'invoice-' . $invoice->id ) . '.pdf';

		$attach_pdf = function ( $phpmailer ) use ( $pdf_bytes, $filename ) {
			$phpmailer->addStringAttachment( $pdf_bytes, $filename, 'base64', 'application/pdf' );
		};

		$to = $to_name ? "$to_name <$to_email>" : $to_email;

		add_action( 'phpmailer_init', $attach_pdf );
		$sent = wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		remove_action( 'phpmailer_init', $attach_pdf );

		if ( $sent ) {
			KCRM_Invoice_Email::create(
				array(
					'invoice_id'    => $invoice_id,
					'sent_to_name'  => $to_name,
					'sent_to_email' => $to_email,
					'sent_by'       => get_current_user_id(),
				)
			);
		}

		$this->redirect(
			array(
				'view'        => 'edit',
				'id'          => $invoice_id,
				'kcrm_notice' => $sent ? 'email_sent' : 'email_error',
			)
		);
	}

	private function delete_payment( $payment_id ) {
		check_admin_referer( 'kcrm_delete_payment_' . $payment_id );

		$payment = KCRM_Payment::find( $payment_id );
		KCRM_Payment::delete_and_refresh( $payment_id );

		$this->redirect( array( 'view' => 'edit', 'id' => $payment ? $payment->invoice_id : 0, 'kcrm_notice' => 'deleted' ) );
	}

	private function delete( $invoice_id ) {
		check_admin_referer( 'kcrm_delete_invoice_' . $invoice_id );
		KCRM_Invoice_Item::delete_for_invoice( $invoice_id );
		KCRM_Payment::delete_for_invoice( $invoice_id );
		KCRM_Invoice::delete( $invoice_id );
		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}

	private function handle_invoice_import_upload() {
		check_admin_referer( 'kcrm_import_invoices_upload' );

		if ( ! $this->current_company_id() ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES is never magic-quotes-slashed by WordPress (wp_magic_quotes() only touches $_GET/$_POST/$_COOKIE/$_SERVER), so wp_unslash() here would incorrectly strip backslashes out of a Windows tmp_name path; KCRM_CSV_Import::store_upload() validates tmp_name, error, size, and extension before use.
		$file  = isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : array();
		$token = KCRM_CSV_Import::store_upload( $file );

		if ( is_wp_error( $token ) ) {
			$this->redirect( array( 'view' => 'import_invoices', 'kcrm_notice' => 'error' ) );
		}

		$this->redirect( array( 'view' => 'import_invoices', 'stage' => 'map', 'file' => $token ) );
	}

	/**
	 * Rows sharing the same (mapped) Invoice Number are grouped into one
	 * invoice with a line item per row -- e.g. an hourly client's invoice
	 * broken out by day/task in the source export. A row with no invoice
	 * number mapped, or blank on that row, has nothing to group it with,
	 * so it becomes its own single-line invoice (auto-assigned a number)
	 * exactly like before. Status is left Open (or Draft/Void, if mapped)
	 * — Open/Partial/Paid are always derived from actual payments, so
	 * historical invoices only reach "Paid" once their payments are
	 * imported via the payments importer below, same as they would from a
	 * payment recorded by hand.
	 */
	private function handle_invoice_import_run() {
		check_admin_referer( 'kcrm_import_invoices_run' );

		$company_id = $this->current_company_id();
		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		$token = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$path  = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			$this->redirect( array( 'view' => 'import_invoices', 'kcrm_notice' => 'error' ) );
		}

		$map = isset( $_POST['map'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['map'] ) ) : array();

		if ( ( $map['customer_name'] ?? -1 ) < 0 || ( $map['issue_date'] ?? -1 ) < 0 || ( $map['amount'] ?? -1 ) < 0 ) {
			$this->redirect( array( 'view' => 'import_invoices', 'stage' => 'map', 'file' => $token, 'kcrm_notice' => 'error' ) );
		}

		$rows = KCRM_CSV_Import::read_rows( $path );

		$customers_by_name      = array();
		$customers_by_norm_name = array();
		$jobs_by_parent         = array();
		foreach ( KCRM_Customer::for_company( $company_id ) as $customer ) {
			$customers_by_name[ strtolower( trim( $customer->company_name ) ) ]                 = (int) $customer->id;
			$customers_by_norm_name[ $this->normalize_company_name( $customer->company_name ) ] = (int) $customer->id;

			if ( $customer->parent_customer_id ) {
				$jobs_by_parent[ (int) $customer->parent_customer_id ][ $this->normalize_company_name( $customer->company_name ) ] = (int) $customer->id;
			}
		}

		$existing_numbers = array();
		foreach ( KCRM_Invoice::for_company( $company_id ) as $invoice ) {
			if ( '' !== trim( (string) $invoice->invoice_number ) ) {
				$existing_numbers[ strtolower( trim( $invoice->invoice_number ) ) ] = true;
			}
		}

		$services_by_name = array();
		foreach ( KCRM_Service::for_company( $company_id ) as $service ) {
			$services_by_name[ strtolower( trim( $service->name ) ) ] = array(
				'id'         => (int) $service->id,
				'is_taxable' => (int) $service->is_taxable,
			);
		}

		// Group rows sharing a mapped invoice number into one invoice; a
		// row with no number mapped/present is its own single-row group,
		// since there's nothing to group it with.
		$groups      = array();
		$group_order = array();
		foreach ( $rows as $i => $row ) {
			$invoice_number = $this->mapped_cell( $row, $map, 'invoice_number' );
			$group_key      = '' !== $invoice_number ? 'num:' . strtolower( $invoice_number ) : 'row:' . $i;

			if ( ! isset( $groups[ $group_key ] ) ) {
				$groups[ $group_key ] = array();
				$group_order[]        = $group_key;
			}
			$groups[ $group_key ][] = $row;
		}

		$imported            = 0;
		$skipped_missing     = 0;
		$skipped_no_customer = 0;
		$skipped_duplicate   = 0;
		$skipped_lines       = 0;
		$services_created    = 0;
		$seen_numbers        = array();

		foreach ( $group_order as $group_key ) {
			$group_rows = $groups[ $group_key ];
			$first_row  = $group_rows[0];

			$customer_name = $this->mapped_cell( $first_row, $map, 'customer_name' );
			$issue_date    = $this->sanitize_date( $this->mapped_cell( $first_row, $map, 'issue_date' ) );

			if ( '' === $customer_name || ! $issue_date ) {
				$skipped_missing++;
				continue;
			}

			$customer_id = $customers_by_name[ strtolower( $customer_name ) ] ?? 0;
			if ( ! $customer_id ) {
				$customer_id = $customers_by_norm_name[ $this->normalize_company_name( $customer_name ) ] ?? 0;
			}
			if ( ! $customer_id && false !== strpos( $customer_name, ':' ) ) {
				$customer_id = $this->match_parent_child_customer( $customer_name, $customers_by_name, $customers_by_norm_name, $jobs_by_parent );
			}
			if ( ! $customer_id ) {
				$skipped_no_customer++;
				continue;
			}

			$invoice_number = $this->mapped_cell( $first_row, $map, 'invoice_number' );
			if ( '' !== $invoice_number ) {
				$key = strtolower( $invoice_number );
				if ( isset( $existing_numbers[ $key ] ) || isset( $seen_numbers[ $key ] ) ) {
					$skipped_duplicate++;
					continue;
				}
				$seen_numbers[ $key ] = true;
			} else {
				$invoice_number = KCRM_Company::next_invoice_number( $company_id );
			}

			// Build one line item per row in the group -- malformed
			// individual lines (unparseable amount) are dropped rather
			// than discarding the whole invoice over one bad row.
			$items = array();
			foreach ( $group_rows as $row ) {
				$amount = $this->mapped_amount( $row, $map, 'amount' );
				if ( null === $amount ) {
					$skipped_lines++;
					continue;
				}

				$service_name = $this->mapped_cell( $row, $map, 'service_name' );

				$description = $this->mapped_cell( $row, $map, 'description' );
				if ( '' === $description ) {
					$description = '' !== $service_name ? $service_name : __( 'Imported Invoice', 'karks-crm' );
				}

				// A line whose service/description names it as a discount
				// is recorded as a negative amount here, so it actually
				// reduces the invoice total -- regardless of how the
				// source export represented its sign. (QuickBooks itself
				// isn't consistent about this: a normal revenue line's
				// credit-side "Sales" row exports negative -- already
				// normalized to positive by mapped_amount() above -- while
				// a "Discounts Given"-account discount line exports
				// positive, the opposite of what it needs to be here.)
				if ( false !== stripos( $service_name, 'discount' ) || false !== stripos( $description, 'discount' ) ) {
					$amount = -$amount;
				}

				// An optional Quantity/Hours column preserves the real
				// breakdown (e.g. "0.25 hrs" against a maintenance
				// package's allotted hours) instead of collapsing every
				// line to a flat quantity of 1 -- the rate is derived by
				// dividing the mapped amount back out, so the invoice
				// total is identical either way.
				$quantity_raw = $this->mapped_amount( $row, $map, 'quantity' );
				$quantity     = ( null !== $quantity_raw && $quantity_raw > 0 ) ? $quantity_raw : 1.0;
				$rate         = round( $amount / $quantity, 2 );
				$item_type    = ( null !== $quantity_raw && $quantity_raw > 0 ) ? KCRM_Service::TYPE_HOURLY : KCRM_Service::TYPE_PROJECT;

				$service_id      = null;
				$item_is_taxable = 0;
				if ( '' !== $service_name ) {
					$service_key = strtolower( $service_name );
					if ( isset( $services_by_name[ $service_key ] ) ) {
						$service_id      = $services_by_name[ $service_key ]['id'];
						$item_is_taxable = $services_by_name[ $service_key ]['is_taxable'];
					} else {
						$service_id = KCRM_Service::create(
							array(
								'company_id' => $company_id,
								'name'       => sanitize_text_field( $service_name ),
								'type'       => $item_type,
								'rate'       => $rate,
							)
						);
						$services_by_name[ $service_key ] = array(
							'id'         => $service_id,
							'is_taxable' => 0,
						);
						$services_created++;
					}
				}

				$items[] = array(
					'description' => $description,
					'service_id'  => $service_id,
					'type'        => $item_type,
					'quantity'    => $quantity,
					'rate'        => $rate,
					'amount'      => $amount,
					'is_taxable'  => $item_is_taxable,
				);
			}

			if ( empty( $items ) ) {
				$skipped_missing++;
				continue;
			}

			$tax_rate = $this->mapped_amount( $first_row, $map, 'tax_rate' );

			// A single line's description doubles as the invoice's own
			// label (matches the previous one-row-one-invoice behavior);
			// a multi-line grouped invoice uses a generic label instead
			// of arbitrarily picking one line's description for the whole
			// invoice.
			$invoice_label = 1 === count( $items ) ? $items[0]['description'] : __( 'Imported Invoice', 'karks-crm' );

			$invoice_id = KCRM_Invoice::create(
				array(
					'company_id'         => $company_id,
					'customer_id'        => $customer_id,
					'invoice_number'     => $invoice_number,
					'status'             => $this->parse_invoice_status( $this->mapped_cell( $first_row, $map, 'status_source' ) ),
					'issue_date'         => $issue_date,
					'due_date'           => $this->sanitize_date( $this->mapped_cell( $first_row, $map, 'due_date' ) ),
					'invoice_type'       => KCRM_Invoice::TYPE_OTHER,
					'invoice_type_other' => $invoice_label,
					'notes'              => sanitize_textarea_field( $this->mapped_cell( $first_row, $map, 'notes' ) ),
					'tax_rate'           => $tax_rate ?? 0,
				)
			);

			$sort = 0;
			foreach ( $items as $item ) {
				KCRM_Invoice_Item::insert(
					array(
						'invoice_id'  => $invoice_id,
						'service_id'  => $item['service_id'],
						'description' => $item['description'],
						'type'        => $item['type'],
						'quantity'    => $item['quantity'],
						'rate'        => $item['rate'],
						'amount'      => $item['amount'],
						'is_taxable'  => $item['is_taxable'],
						'sort_order'  => $sort++,
					)
				);
			}

			KCRM_Invoice::recalculate_totals( $invoice_id );

			$imported++;
		}

		KCRM_CSV_Import::delete( $token );

		$this->redirect(
			array(
				'view'                => 'import_invoices',
				'stage'               => 'done',
				'imported'            => $imported,
				'skipped_no_customer' => $skipped_no_customer,
				'skipped_duplicate'   => $skipped_duplicate,
				'skipped_missing'     => $skipped_missing,
				'skipped_lines'       => $skipped_lines,
				'services_created'    => $services_created,
			)
		);
	}

	private function parse_invoice_status( $value ) {
		$value = strtolower( trim( $value ) );
		if ( false !== strpos( $value, 'void' ) ) {
			return KCRM_Invoice::STATUS_VOID;
		}
		if ( false !== strpos( $value, 'draft' ) ) {
			return KCRM_Invoice::STATUS_DRAFT;
		}
		return KCRM_Invoice::STATUS_OPEN;
	}

	private function handle_payment_import_upload() {
		check_admin_referer( 'kcrm_import_payments_upload' );

		if ( ! $this->current_company_id() ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- $_FILES is never magic-quotes-slashed by WordPress (wp_magic_quotes() only touches $_GET/$_POST/$_COOKIE/$_SERVER), so wp_unslash() here would incorrectly strip backslashes out of a Windows tmp_name path; KCRM_CSV_Import::store_upload() validates tmp_name, error, size, and extension before use.
		$file  = isset( $_FILES['import_file'] ) ? $_FILES['import_file'] : array();
		$token = KCRM_CSV_Import::store_upload( $file );

		if ( is_wp_error( $token ) ) {
			$this->redirect( array( 'view' => 'import_payments', 'kcrm_notice' => 'error' ) );
		}

		$this->redirect( array( 'view' => 'import_payments', 'stage' => 'map', 'file' => $token ) );
	}

	private function handle_payment_import_run() {
		check_admin_referer( 'kcrm_import_payments_run' );

		$company_id = $this->current_company_id();
		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		$token = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$path  = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			$this->redirect( array( 'view' => 'import_payments', 'kcrm_notice' => 'error' ) );
		}

		$map = isset( $_POST['map'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['map'] ) ) : array();

		if ( ( $map['invoice_number'] ?? -1 ) < 0 || ( $map['amount'] ?? -1 ) < 0 || ( $map['payment_date'] ?? -1 ) < 0 ) {
			$this->redirect( array( 'view' => 'import_payments', 'stage' => 'map', 'file' => $token, 'kcrm_notice' => 'error' ) );
		}

		$rows = KCRM_CSV_Import::read_rows( $path );

		$invoices_by_number = array();
		foreach ( KCRM_Invoice::for_company( $company_id ) as $invoice ) {
			if ( '' !== trim( (string) $invoice->invoice_number ) ) {
				$invoices_by_number[ strtolower( trim( $invoice->invoice_number ) ) ] = $invoice;
			}
		}

		$imported           = 0;
		$skipped_missing    = 0;
		$skipped_no_invoice = 0;
		$skipped_duplicate  = 0;

		foreach ( $rows as $row ) {
			$invoice_number = $this->mapped_cell( $row, $map, 'invoice_number' );
			$payment_date   = $this->sanitize_date( $this->mapped_cell( $row, $map, 'payment_date' ) );
			$amount         = $this->mapped_amount( $row, $map, 'amount' );

			if ( '' === $invoice_number || ! $payment_date || null === $amount || $amount <= 0 ) {
				$skipped_missing++;
				continue;
			}

			$invoice = $invoices_by_number[ strtolower( $invoice_number ) ] ?? null;
			if ( ! $invoice ) {
				$skipped_no_invoice++;
				continue;
			}

			if ( KCRM_Payment::exists_duplicate( $invoice->id, $payment_date, $amount ) ) {
				$skipped_duplicate++;
				continue;
			}

			KCRM_Payment::create(
				array(
					'invoice_id'   => $invoice->id,
					'customer_id'  => $invoice->customer_id,
					'company_id'   => $company_id,
					'amount'       => $amount,
					'payment_date' => $payment_date,
					'method'       => $this->mapped_cell( $row, $map, 'method' ),
					'note'         => $this->mapped_cell( $row, $map, 'note' ),
				)
			);

			$imported++;
		}

		KCRM_CSV_Import::delete( $token );

		$this->redirect(
			array(
				'view'               => 'import_payments',
				'stage'              => 'done',
				'imported'           => $imported,
				'skipped_no_invoice' => $skipped_no_invoice,
				'skipped_duplicate'  => $skipped_duplicate,
				'skipped_missing'    => $skipped_missing,
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

	/**
	 * @return string Lowercased company name with a trailing entity suffix
	 * (Inc/LLC/Corp/Co/Ltd, with or without a comma/period) and periods
	 * within initials stripped, so e.g. "Center for Spiritual Formation"
	 * and "Center for Spiritual Formation, Inc." -- or "E.W. Mainhart LLC"
	 * and "EW Mainhart LLC" -- match. QuickBooks Desktop's own reports are
	 * inconsistent about including a customer's full legal suffix, so
	 * matching import rows to existing customers by exact name is too
	 * strict for real-world exports.
	 */
	protected function normalize_company_name( $name ) {
		$name = preg_replace( '/\s*,?\s*(Inc\.?|LLC\.?|Corp\.?|Co\.?|Ltd\.?)\s*$/i', '', trim( $name ) );
		$name = str_replace( '.', '', $name );
		$name = preg_replace( '/\s+/', ' ', trim( $name ) );
		return strtolower( $name );
	}

	/**
	 * Matches QuickBooks "Parent:Child" sub-customer naming (e.g.
	 * "Kessler Freedman, Inc.:AAA Federation") to the corresponding Job --
	 * a child customer record whose own company_name is just "AAA
	 * Federation" (Karks CRM's own convention; see
	 * KCRM_Customer::display_name()) -- by splitting on the last colon,
	 * finding the parent customer, then matching the remainder against
	 * that parent's own Jobs specifically, not a global name search, so
	 * two different parents' same-named Jobs can't collide.
	 *
	 * @param array $jobs_by_parent Parent customer id => [ normalized Job company_name => Job customer id ].
	 * @return int Matched Job's customer id, or 0 if no match.
	 */
	private function match_parent_child_customer( $customer_name, array $customers_by_name, array $customers_by_norm_name, array $jobs_by_parent ) {
		$parts       = explode( ':', $customer_name );
		$job_part    = trim( array_pop( $parts ) );
		$parent_part = trim( implode( ':', $parts ) );

		if ( '' === $job_part || '' === $parent_part ) {
			return 0;
		}

		$parent_id = $customers_by_name[ strtolower( $parent_part ) ] ?? $customers_by_norm_name[ $this->normalize_company_name( $parent_part ) ] ?? 0;
		if ( ! $parent_id || ! isset( $jobs_by_parent[ $parent_id ] ) ) {
			return 0;
		}

		return $jobs_by_parent[ $parent_id ][ $this->normalize_company_name( $job_part ) ] ?? 0;
	}

	/**
	 * @return float|null Parsed numeric value (currency symbols/commas
	 * stripped), or null if blank/non-numeric. Always non-negative --
	 * QuickBooks-style exports commonly show the credit side of a
	 * transaction as negative (e.g. a "Sales" account row on an invoice
	 * whose "Accounts Receivable" row carries the same amount positive),
	 * and this plugin has no concept of a negative invoice/line/payment
	 * amount, so the sign is normalized away here rather than per caller.
	 */
	protected function mapped_amount( array $row, array $map, $field ) {
		$raw = $this->mapped_cell( $row, $map, $field );
		if ( '' === $raw ) {
			return null;
		}
		$clean = preg_replace( '/[^0-9.\-]/', '', $raw );
		return is_numeric( $clean ) ? abs( (float) $clean ) : null;
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

	/** Target invoice fields for the CSV importer, with candidate header names (lowercase) used to guess a default column mapping. */
	protected function import_invoice_fields() {
		return array(
			'customer_name'  => array(
				'label'    => __( 'Customer / Company Name', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'customer name', 'customer', 'company name', 'company' ),
			),
			'invoice_number' => array(
				'label' => __( 'Invoice Number (rows sharing a number become one invoice with a line per row; leave unmapped to auto-assign a separate number to every row instead)', 'karks-crm' ),
				'guess' => array( 'invoice #', 'invoice no', 'invoice number', 'number' ),
			),
			'issue_date'     => array(
				'label'    => __( 'Issue Date', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'issue date', 'invoice date', 'date' ),
			),
			'due_date'       => array(
				'label' => __( 'Due Date', 'karks-crm' ),
				'guess' => array( 'due date' ),
			),
			'amount'         => array(
				'label'    => __( 'Amount (pre-tax)', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'amount', 'subtotal', 'total' ),
			),
			'quantity'       => array(
				'label' => __( 'Quantity / Hours (optional; preserves the real hours/quantity per line -- e.g. "0.25 hrs" -- with the rate derived from Amount, instead of every line showing quantity 1)', 'karks-crm' ),
				'guess' => array( 'qty', 'quantity', 'hours' ),
			),
			'tax_rate'       => array(
				'label' => __( 'Tax Rate (%)', 'karks-crm' ),
				'guess' => array( 'tax rate', 'tax %', 'tax' ),
			),
			'description'    => array(
				'label' => __( "Description (each row's line item label; also becomes the invoice's own label, for single-line invoices)", 'karks-crm' ),
				'guess' => array( 'description', 'memo' ),
			),
			'service_name'   => array(
				'label' => __( "Service (matched by name; created automatically if it doesn't exist yet)", 'karks-crm' ),
				'guess' => array( 'product/service', 'service', 'item' ),
			),
			'status_source'  => array(
				'label' => __( 'Status column (only Draft/Void are recognized)', 'karks-crm' ),
				'guess' => array( 'status' ),
			),
			'notes'          => array(
				'label' => __( 'Notes', 'karks-crm' ),
				'guess' => array( 'notes' ),
			),
		);
	}

	/** Target payment fields for the CSV importer, with candidate header names (lowercase) used to guess a default column mapping. */
	protected function import_payment_fields() {
		return array(
			'invoice_number' => array(
				'label'    => __( 'Invoice Number', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'invoice #', 'invoice no', 'invoice number', 'number' ),
			),
			'amount'         => array(
				'label'    => __( 'Amount', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'amount', 'payment amount', 'payment' ),
			),
			'payment_date'   => array(
				'label'    => __( 'Payment Date', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'payment date', 'date' ),
			),
			'method'         => array(
				'label' => __( 'Method', 'karks-crm' ),
				'guess' => array( 'method', 'payment method' ),
			),
			'note'           => array(
				'label' => __( 'Note', 'karks-crm' ),
				'guess' => array( 'note', 'memo' ),
			),
		);
	}

	/**
	 * @return string|null A 'Y-m-d' date, or null if $value isn't a
	 * recognized date. Accepts the plugin's own ISO 'Y-m-d' (what every
	 * `<input type=date>` submits, regardless of the visitor's locale)
	 * and, for CSV imports, US-style 'M/D/Y' or 'MM/DD/YYYY' -- what
	 * QuickBooks Desktop exports by default.
	 */
	protected function sanitize_date( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );

		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $value, $matches ) ) {
			list( , $month, $day, $year ) = $matches;
			if ( checkdate( (int) $month, (int) $day, (int) $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );
			}
		}

		return null;
	}

	protected function sanitize_month( $value ) {
		$value = sanitize_text_field( wp_unslash( $value ) );
		if ( ! preg_match( '/^\d{4}-\d{2}$/', $value ) ) {
			return null;
		}
		return $value;
	}

	/**
	 * admin-post handler: streams the invoice as a PDF download. Registered
	 * once (in KCRM_Plugin) regardless of whether the download link that
	 * pointed here was rendered in wp-admin or on the front end -- admin-post.php
	 * works the same either way.
	 */
	public function handle_pdf_download() {
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'kcrm_download_invoice_pdf_' . $id );

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ) );
		}

		$invoice = $id ? KCRM_Invoice::find( $id ) : null;
		if ( ! $invoice ) {
			wp_die( esc_html__( 'Invoice not found.', 'karks-crm' ) );
		}

		KCRM_PDF::stream_invoice( $invoice );
	}
}
