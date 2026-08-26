<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports a single company (profile, customers, services, invoices, line
 * items, payments) to a plain JSON-serializable array, and imports that
 * same shape back in as a brand-new company -- e.g. for migrating a
 * company between sites or duplicating one as a template. Export is
 * reachable from both wp-admin (Companies list) and the front end (Tools
 * screen); import remains wp-admin only -- see KCRM_Admin_Companies and
 * KCRM_Front_Tools for the Export/Import UI.
 *
 * Deliberately excluded from the export: the company logo (a media
 * attachment, not row data -- re-upload it manually on the new site) and
 * the cached subtotal/tax_amount/total on each invoice (recomputed fresh
 * on import from the imported line items/payments instead of trusting a
 * possibly-stale export).
 *
 * Import creates a new company by default, even if the name matches one
 * already on this site (auto-suffixing the name on collision), rather than
 * an implicit cascading overwrite of whatever's already attached to that
 * company. Passing $target_company_id to import() opts into that overwrite
 * instead -- wiping and replacing one specific existing company's data in
 * place -- for callers (e.g. a backup/restore add-on) that need it
 * explicitly; nothing in this plugin's own UI does that.
 */
class KCRM_Company_Transfer {

	/** Shared tooltip text for the Export link, so wp-admin and the front end can't drift into different wording. */
	public static function export_tooltip() {
		return __( 'Export this company as a JSON file. Use it as a backup or import it into another Karks CRM installation.', 'karks-crm' );
	}

	/** @return array A plain array, ready for wp_json_encode(). */
	public static function export( $company_id ) {
		$company = KCRM_Company::find( $company_id );
		if ( ! $company ) {
			return array();
		}

		$data = array(
			'format_version' => KCRM_VERSION,
			'exported_at'    => current_time( 'mysql' ),
			'company'        => array(
				'name'                   => $company->name,
				'email'                  => $company->email,
				'phone'                  => $company->phone,
				'address_street'         => $company->address_street,
				'address_street_2'       => $company->address_street_2,
				'address_city'           => $company->address_city,
				'address_state'          => $company->address_state,
				'address_postal_code'    => $company->address_postal_code,
				'address_country'        => $company->address_country,
				'invoice_prefix'         => $company->invoice_prefix,
				'next_invoice_number'    => (int) $company->next_invoice_number,
				'default_tax_rate'       => (float) $company->default_tax_rate,
				'currency'               => $company->currency,
				'invoice_footer'         => $company->invoice_footer,
				'accepted_payment_types' => $company->accepted_payment_types,
				'payment_links'          => $company->payment_links,
				'check_payable_to'       => $company->check_payable_to,
				'other_payment_instructions' => $company->other_payment_instructions,
				'pdf_accent_color'       => $company->pdf_accent_color,
				'email_template'         => $company->email_template,
			),
			'customers'      => array(),
			'services'       => array(),
			'invoices'       => array(),
		);

		foreach ( KCRM_Customer::for_company( $company_id ) as $customer ) {
			$data['customers'][] = array(
				'id'                       => (int) $customer->id,
				'parent_customer_id'       => $customer->parent_customer_id ? (int) $customer->parent_customer_id : null,
				'company_name'             => $customer->company_name,
				'contact_person'           => $customer->contact_person,
				'secondary_contact_person' => $customer->secondary_contact_person,
				'address_street'           => $customer->address_street,
				'address_street_2'         => $customer->address_street_2,
				'address_city'             => $customer->address_city,
				'address_state'            => $customer->address_state,
				'address_postal_code'      => $customer->address_postal_code,
				'address_country'          => $customer->address_country,
				'phone'                    => $customer->phone,
				'email'                    => $customer->email,
				'secondary_email'          => $customer->secondary_email,
				'notes'                    => $customer->notes,
				'status'                   => $customer->status,
			);
		}

		foreach ( KCRM_Service::for_company( $company_id ) as $service ) {
			$data['services'][] = array(
				'id'          => (int) $service->id,
				'name'        => $service->name,
				'description' => $service->description,
				'type'        => $service->type,
				'rate'        => (float) $service->rate,
				'is_active'   => (int) $service->is_active,
				'is_taxable'  => (int) $service->is_taxable,
			);
		}

		foreach ( KCRM_Invoice::for_company( $company_id ) as $invoice ) {
			$data['invoices'][] = array(
				'customer_id'        => (int) $invoice->customer_id,
				'invoice_number'     => $invoice->invoice_number,
				'status'             => $invoice->status,
				'issue_date'         => $invoice->issue_date,
				'due_date'           => $invoice->due_date,
				'invoice_type'       => $invoice->invoice_type,
				'invoice_type_month' => $invoice->invoice_type_month,
				'invoice_type_other' => $invoice->invoice_type_other,
				'notes'              => $invoice->notes,
				'tax_rate'           => (float) $invoice->tax_rate,
				'items'              => array_map(
					static function ( $item ) {
						return array(
							'service_id'  => $item->service_id ? (int) $item->service_id : null,
							'description' => $item->description,
							'type'        => $item->type,
							'quantity'    => (float) $item->quantity,
							'rate'        => (float) $item->rate,
							'amount'      => (float) $item->amount,
							'is_taxable'  => (int) $item->is_taxable,
							'sort_order'  => (int) $item->sort_order,
						);
					},
					KCRM_Invoice_Item::for_invoice( $invoice->id )
				),
				'payments'           => array_map(
					static function ( $payment ) {
						return array(
							'amount'       => (float) $payment->amount,
							'payment_date' => $payment->payment_date,
							'method'       => $payment->method,
							'note'         => $payment->note,
						);
					},
					KCRM_Payment::for_invoice( $invoice->id )
				),
			);
		}

		return $data;
	}

	/**
	 * @param array    $data              Decoded export (see export()).
	 * @param int|null $target_company_id If given, restore into this existing company in place
	 *                                    (wiping its current customers/services/invoices/items/
	 *                                    payments first and overwriting its profile fields) instead
	 *                                    of creating a brand-new company. The company itself must
	 *                                    already exist; callers are responsible for their own
	 *                                    authorization/confirmation around this destructive path --
	 *                                    this method does not gate it further.
	 * @return array|WP_Error [ 'company_id', 'customers', 'services', 'invoices', 'payments' ] counts on success.
	 */
	public static function import( array $data, $target_company_id = null ) {
		if ( empty( $data['format_version'] ) || KCRM_VERSION !== $data['format_version'] ) {
			return new WP_Error(
				'kcrm_import_version_mismatch',
				sprintf(
					/* translators: 1: the export file's plugin version, 2: this site's plugin version. */
					__( 'This export was made with Karks CRM %1$s, but this site is running %2$s. Both sites need to be running the same plugin version to import.', 'karks-crm' ),
					empty( $data['format_version'] ) ? __( 'an unknown version', 'karks-crm' ) : $data['format_version'],
					KCRM_VERSION
				)
			);
		}

		if ( empty( $data['company'] ) || ! is_array( $data['company'] ) ) {
			return new WP_Error( 'kcrm_import_invalid', __( 'That file does not look like a Karks CRM company export.', 'karks-crm' ) );
		}

		$target_company_id = $target_company_id ? (int) $target_company_id : 0;
		if ( $target_company_id && ! KCRM_Company::find( $target_company_id ) ) {
			return new WP_Error( 'kcrm_import_target_missing', __( 'The company to restore into no longer exists.', 'karks-crm' ) );
		}

		$company_data = self::sanitize_company_data( $data['company'] );

		if ( $target_company_id ) {
			$company_id = $target_company_id;
			self::wipe_company_data( $company_id );
			KCRM_Company::update( $company_id, $company_data );
		} else {
			$company_data['name'] = self::unique_company_name( $company_data['name'] ?? __( 'Imported Company', 'karks-crm' ) );
			$company_id           = KCRM_Company::create( $company_data );
		}

		$customer_id_map = array();
		$customers       = is_array( $data['customers'] ?? null ) ? $data['customers'] : array();

		// Two passes so a Job's parent_customer_id can be remapped -- top-level customers (no parent) must exist first.
		foreach ( $customers as $customer ) {
			if ( ! empty( $customer['parent_customer_id'] ) ) {
				continue;
			}
			$customer_id_map[ $customer['id'] ] = self::import_customer( $company_id, $customer, null );
		}
		foreach ( $customers as $customer ) {
			if ( empty( $customer['parent_customer_id'] ) ) {
				continue;
			}
			$new_parent_id                      = $customer_id_map[ $customer['parent_customer_id'] ] ?? null;
			$customer_id_map[ $customer['id'] ] = self::import_customer( $company_id, $customer, $new_parent_id );
		}

		$service_id_map = array();
		foreach ( ( is_array( $data['services'] ?? null ) ? $data['services'] : array() ) as $service ) {
			$service_id_map[ $service['id'] ] = KCRM_Service::create(
				array(
					'company_id'  => $company_id,
					'name'        => $service['name'] ?? '',
					'description' => $service['description'] ?? '',
					'type'        => in_array( $service['type'] ?? '', array_keys( KCRM_Service::types() ), true ) ? $service['type'] : KCRM_Service::TYPE_HOURLY,
					'rate'        => (float) ( $service['rate'] ?? 0 ),
					'is_active'   => empty( $service['is_active'] ) ? 0 : 1,
					'is_taxable'  => empty( $service['is_taxable'] ) ? 0 : 1,
				)
			);
		}

		$invoices      = is_array( $data['invoices'] ?? null ) ? $data['invoices'] : array();
		$payment_count = 0;

		foreach ( $invoices as $invoice ) {
			$new_customer_id = $customer_id_map[ $invoice['customer_id'] ] ?? 0;
			if ( ! $new_customer_id ) {
				continue; // Orphaned reference in a malformed/hand-edited file -- skip rather than create a broken invoice.
			}

			$status = in_array( $invoice['status'] ?? '', array_keys( KCRM_Invoice::statuses() ), true ) ? $invoice['status'] : KCRM_Invoice::STATUS_OPEN;
			$type   = in_array( $invoice['invoice_type'] ?? '', array_keys( KCRM_Invoice::types() ), true ) ? $invoice['invoice_type'] : KCRM_Invoice::TYPE_OTHER;

			$new_invoice_id = KCRM_Invoice::create(
				array(
					'company_id'         => $company_id,
					'customer_id'        => $new_customer_id,
					'invoice_number'     => $invoice['invoice_number'] ?? '',
					'status'             => $status,
					'issue_date'         => $invoice['issue_date'] ?? current_time( 'Y-m-d' ),
					'due_date'           => $invoice['due_date'] ?? null,
					'invoice_type'       => $type,
					'invoice_type_month' => $invoice['invoice_type_month'] ?? null,
					'invoice_type_other' => $invoice['invoice_type_other'] ?? null,
					'notes'              => $invoice['notes'] ?? '',
					'tax_rate'           => (float) ( $invoice['tax_rate'] ?? 0 ),
				)
			);

			$sort = 0;
			foreach ( (array) ( $invoice['items'] ?? array() ) as $item ) {
				$old_service_id = $item['service_id'] ?? null;
				KCRM_Invoice_Item::insert(
					array(
						'invoice_id'  => $new_invoice_id,
						'service_id'  => $old_service_id ? ( $service_id_map[ $old_service_id ] ?? null ) : null,
						'description' => $item['description'] ?? '',
						'type'        => in_array( $item['type'] ?? '', array_keys( KCRM_Service::types() ), true ) ? $item['type'] : KCRM_Service::TYPE_PROJECT,
						'quantity'    => (float) ( $item['quantity'] ?? 0 ),
						'rate'        => (float) ( $item['rate'] ?? 0 ),
						'amount'      => (float) ( $item['amount'] ?? 0 ),
						'is_taxable'  => empty( $item['is_taxable'] ) ? 0 : 1,
						'sort_order'  => isset( $item['sort_order'] ) ? (int) $item['sort_order'] : $sort,
					)
				);
				$sort++;
			}

			foreach ( (array) ( $invoice['payments'] ?? array() ) as $payment ) {
				KCRM_Payment::create(
					array(
						'invoice_id'   => $new_invoice_id,
						'customer_id'  => $new_customer_id,
						'company_id'   => $company_id,
						'amount'       => (float) ( $payment['amount'] ?? 0 ),
						'payment_date' => $payment['payment_date'] ?? current_time( 'Y-m-d' ),
						'method'       => $payment['method'] ?? '',
						'note'         => $payment['note'] ?? '',
					)
				);
				$payment_count++;
			}

			// Recomputes subtotal/tax/total from the items just inserted, then
			// refreshes open/partial/paid from the payments just inserted --
			// rather than trusting the export's own (possibly stale) cached totals.
			KCRM_Invoice::recalculate_totals( $new_invoice_id );
		}

		return array(
			'company_id' => $company_id,
			'customers'  => count( $customer_id_map ),
			'services'   => count( $service_id_map ),
			'invoices'   => count( $invoices ),
			'payments'   => $payment_count,
		);
	}

	/**
	 * Runs the same sanitizers KCRM_Companies_Controller::save() applies to
	 * a normal profile edit, against an imported company's data instead of
	 * $_POST. Without this, an imported file's `invoice_footer` (rendered
	 * unescaped via wpautop() in the invoice PDF -- see templates/invoice-pdf.php)
	 * and `email_template` would reach the database as raw, unsanitized HTML;
	 * every other save path relies on this having already happened, so
	 * import() -- which builds this array from a hand-editable JSON file
	 * instead of a same-site form submission -- can't skip it.
	 *
	 * @return array The same shape as $data, with known fields sanitized.
	 */
	private static function sanitize_company_data( array $data ) {
		$text = static function ( $v ) { return sanitize_text_field( (string) $v ); };
		$html = static function ( $v ) { return wp_kses_post( (string) $v ); };

		$sanitizers = array(
			'name'                       => $text,
			'email'                      => static function ( $v ) { return sanitize_email( (string) $v ); },
			'phone'                      => $text,
			'address_street'             => $text,
			'address_street_2'           => $text,
			'address_city'               => $text,
			'address_state'              => $text,
			'address_postal_code'        => $text,
			'invoice_prefix'             => $text,
			'currency'                   => $text,
			'invoice_footer'             => $html,
			'check_payable_to'           => $text,
			'other_payment_instructions' => $text,
			'email_template'             => $html,
		);

		foreach ( $sanitizers as $key => $sanitize ) {
			if ( isset( $data[ $key ] ) ) {
				$data[ $key ] = $sanitize( $data[ $key ] );
			}
		}

		if ( ! empty( $data['address_country'] ) && ! array_key_exists( $data['address_country'], KCRM_Countries::list() ) ) {
			$data['address_country'] = KCRM_Countries::DEFAULT_CODE;
		}

		return $data;
	}

	private static function import_customer( $company_id, array $customer, $new_parent_id ) {
		return KCRM_Customer::create(
			array(
				'company_id'               => $company_id,
				'parent_customer_id'       => $new_parent_id,
				'company_name'             => $customer['company_name'] ?? '',
				'contact_person'           => $customer['contact_person'] ?? '',
				'secondary_contact_person' => $customer['secondary_contact_person'] ?? '',
				'address_street'           => $customer['address_street'] ?? '',
				'address_street_2'         => $customer['address_street_2'] ?? '',
				'address_city'             => $customer['address_city'] ?? '',
				'address_state'            => $customer['address_state'] ?? '',
				'address_postal_code'      => $customer['address_postal_code'] ?? '',
				'address_country'          => $customer['address_country'] ?? KCRM_Countries::DEFAULT_CODE,
				'phone'                    => $customer['phone'] ?? '',
				'email'                    => $customer['email'] ?? '',
				'secondary_email'          => $customer['secondary_email'] ?? '',
				'notes'                    => $customer['notes'] ?? '',
				'status'                   => in_array( $customer['status'] ?? '', array_keys( KCRM_Customer::statuses() ), true ) ? $customer['status'] : KCRM_Customer::STATUS_ACTIVE,
			)
		);
	}

	/**
	 * Deletes every customer/service/invoice (+ items + payments) belonging
	 * to $company_id, in preparation for import() rebuilding it from a
	 * restore -- leaves the company row itself untouched (import() updates
	 * its profile fields separately).
	 */
	private static function wipe_company_data( $company_id ) {
		foreach ( KCRM_Invoice::for_company( $company_id ) as $invoice ) {
			KCRM_Invoice_Item::delete_for_invoice( $invoice->id );
			KCRM_Payment::delete_for_invoice( $invoice->id );
			KCRM_Invoice::delete( $invoice->id );
		}

		foreach ( KCRM_Service::for_company( $company_id ) as $service ) {
			KCRM_Service::delete( $service->id );
		}

		foreach ( KCRM_Customer::for_company( $company_id ) as $customer ) {
			KCRM_Customer::delete( $customer->id );
		}
	}

	/** @return string $name, or "$name (2)", "$name (3)", ... if a company with that exact name already exists. */
	private static function unique_company_name( $name ) {
		$existing = wp_list_pluck( KCRM_Company::all_ordered(), 'name' );
		if ( ! in_array( $name, $existing, true ) ) {
			return $name;
		}
		$i = 2;
		while ( in_array( "$name ($i)", $existing, true ) ) {
			$i++;
		}
		return "$name ($i)";
	}
}
