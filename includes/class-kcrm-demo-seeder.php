<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Populates one demo company with sample customers, services, invoices, and
 * payments so a WordPress Playground preview (assets/blueprints/blueprint.json)
 * doesn't land on an empty CRM. Only ever invoked via the 'kcrm_run_demo_seeder'
 * action (see karks-crm.php), which that blueprint's runPHP step fires --
 * nothing on a real install ever calls that action.
 */
class KCRM_Demo_Seeder {

	const DEMO_COMPANY_NAME = 'Riverside Web Studio';

	/**
	 * Seeds demo data once. Safe to call more than once (e.g. a Playground
	 * restart re-running the blueprint) -- does nothing if the demo company
	 * already exists.
	 */
	public static function maybe_seed() {
		if ( self::demo_company_id() ) {
			return;
		}

		$company_id = self::create_company();
		self::point_admin_at_company( $company_id );

		$customer_id = self::create_customers( $company_id );
		$services    = self::create_services( $company_id );

		self::create_paid_invoice( $company_id, $customer_id, $services );
		self::create_partial_invoice( $company_id, $customer_id, $services );
		self::create_overdue_invoice( $company_id, $customer_id, $services );
	}

	/** @return int|null The demo company's id if it already exists, else null. */
	private static function demo_company_id() {
		$rows = KCRM_Company::where( array( 'name' => self::DEMO_COMPANY_NAME ), 'id ASC', 1 );
		return $rows ? (int) $rows[0]->id : null;
	}

	private static function create_company() {
		return KCRM_Company::create(
			array(
				'name'                => self::DEMO_COMPANY_NAME,
				'email'                => 'billing@riversidewebstudio.test',
				'phone'                => '555-0142',
				'address_street'       => '128 Harbor Ave',
				'address_city'         => 'Riverside',
				'address_state'        => 'CA',
				'address_postal_code'  => '92501',
				'address_country'      => 'US',
				'invoice_prefix'       => 'INV-',
				'next_invoice_number'  => 1,
				'default_tax_rate'     => 0,
				'currency'             => 'USD',
				'accepted_payment_types' => 'credit_card,ach,check',
			)
		);
	}

	/** Makes this the current company for the admin user, so wp-admin lands on it immediately. */
	private static function point_admin_at_company( $company_id ) {
		$admin = get_user_by( 'login', 'admin' );
		if ( $admin ) {
			update_user_meta( $admin->ID, 'kcrm_current_company_id', $company_id );
		}
	}

	/** @return int The top-level customer's id (its nested Job is created too, but not needed by the invoice steps below). */
	private static function create_customers( $company_id ) {
		$customer_id = KCRM_Customer::create(
			array(
				'company_id'             => $company_id,
				'company_name'           => 'Blue Harbor Realty',
				'contact_person'         => 'Dana Whitfield',
				'address_street'         => '44 Pier St',
				'address_city'           => 'Riverside',
				'address_state'          => 'CA',
				'address_postal_code'    => '92501',
				'address_country'        => 'US',
				'phone'                  => '555-0110',
				'email'                  => 'dana@blueharborrealty.test',
				'invoice_recipient_name' => 'Dana Whitfield',
				'invoice_recipient_email' => 'dana@blueharborrealty.test',
			)
		);

		KCRM_Customer::create(
			array(
				'company_id'         => $company_id,
				'parent_customer_id' => $customer_id,
				'company_name'       => 'Blue Harbor Realty - Downtown Listings',
				'contact_person'     => 'Dana Whitfield',
				'email'              => 'dana@blueharborrealty.test',
			)
		);

		KCRM_Customer::create(
			array(
				'company_id'             => $company_id,
				'company_name'           => 'Coastal Roasters Cafe',
				'contact_person'         => 'Marcus Ito',
				'address_street'         => '9 Market Row',
				'address_city'           => 'Riverside',
				'address_state'          => 'CA',
				'address_postal_code'    => '92501',
				'address_country'        => 'US',
				'phone'                  => '555-0177',
				'email'                  => 'marcus@coastalroasters.test',
				'invoice_recipient_name' => 'Marcus Ito',
				'invoice_recipient_email' => 'marcus@coastalroasters.test',
			)
		);

		return $customer_id;
	}

	/** @return array{hourly:int, project:int} service ids. */
	private static function create_services( $company_id ) {
		$hourly = KCRM_Service::create(
			array(
				'company_id'  => $company_id,
				'name'        => 'Website Maintenance',
				'description' => 'Ongoing updates, backups, and support.',
				'type'        => KCRM_Service::TYPE_HOURLY,
				'rate'        => 95,
				'is_taxable'  => 0,
			)
		);

		$project = KCRM_Service::create(
			array(
				'company_id'  => $company_id,
				'name'        => 'Website Redesign',
				'description' => 'Fixed-scope redesign and launch.',
				'type'        => KCRM_Service::TYPE_PROJECT,
				'rate'        => 2400,
				'is_taxable'  => 0,
			)
		);

		return array(
			'hourly'  => $hourly,
			'project' => $project,
		);
	}

	/** @return int New invoice id, with its line item(s) already added and totals recalculated. */
	private static function create_invoice( $company_id, $customer_id, $issue_date, $due_date, array $items ) {
		$invoice_id = KCRM_Invoice::create(
			array(
				'company_id'     => $company_id,
				'customer_id'    => $customer_id,
				'invoice_number' => KCRM_Company::next_invoice_number( $company_id ),
				'issue_date'     => $issue_date,
				'due_date'       => $due_date,
				'invoice_type'   => KCRM_Invoice::TYPE_OTHER,
				'invoice_type_other' => 'Services Rendered',
				'tax_rate'       => 0,
			)
		);

		$sort_order = 0;
		foreach ( $items as $item ) {
			KCRM_Invoice_Item::insert(
				array(
					'invoice_id'  => $invoice_id,
					'service_id'  => $item['service_id'],
					'description' => $item['description'],
					'type'        => $item['type'],
					'quantity'    => $item['quantity'],
					'rate'        => $item['rate'],
					'amount'      => $item['quantity'] * $item['rate'],
					'is_taxable'  => 0,
					'sort_order'  => $sort_order++,
				)
			);
		}

		KCRM_Invoice::recalculate_totals( $invoice_id );

		return $invoice_id;
	}

	private static function create_paid_invoice( $company_id, $customer_id, array $services ) {
		$invoice_id = self::create_invoice(
			$company_id,
			$customer_id,
			gmdate( 'Y-m-d', strtotime( '-45 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-15 days' ) ),
			array(
				array(
					'service_id'  => $services['project'],
					'description' => 'Website Redesign',
					'type'        => KCRM_Service::TYPE_PROJECT,
					'quantity'    => 1,
					'rate'        => 2400,
				),
			)
		);

		KCRM_Payment::create(
			array(
				'invoice_id'   => $invoice_id,
				'customer_id'  => $customer_id,
				'company_id'   => $company_id,
				'amount'       => 2400,
				'payment_date' => gmdate( 'Y-m-d', strtotime( '-20 days' ) ),
				'method'       => 'credit_card',
				'note'         => 'Paid in full',
			)
		);
	}

	private static function create_partial_invoice( $company_id, $customer_id, array $services ) {
		$invoice_id = self::create_invoice(
			$company_id,
			$customer_id,
			gmdate( 'Y-m-d', strtotime( '-20 days' ) ),
			gmdate( 'Y-m-d', strtotime( '+10 days' ) ),
			array(
				array(
					'service_id'  => $services['hourly'],
					'description' => 'Website Maintenance - 8 hrs',
					'type'        => KCRM_Service::TYPE_HOURLY,
					'quantity'    => 8,
					'rate'        => 95,
				),
			)
		);

		KCRM_Payment::create(
			array(
				'invoice_id'   => $invoice_id,
				'customer_id'  => $customer_id,
				'company_id'   => $company_id,
				'amount'       => 400,
				'payment_date' => gmdate( 'Y-m-d', strtotime( '-5 days' ) ),
				'method'       => 'check',
				'note'         => 'Partial payment',
			)
		);
	}

	private static function create_overdue_invoice( $company_id, $customer_id, array $services ) {
		self::create_invoice(
			$company_id,
			$customer_id,
			gmdate( 'Y-m-d', strtotime( '-40 days' ) ),
			gmdate( 'Y-m-d', strtotime( '-10 days' ) ),
			array(
				array(
					'service_id'  => $services['hourly'],
					'description' => 'Website Maintenance - 4 hrs',
					'type'        => KCRM_Service::TYPE_HOURLY,
					'quantity'    => 4,
					'rate'        => 95,
				),
			)
		);
	}
}
