<?php
/**
 * KCRM_Company_Transfer::import() builds its record arrays from a
 * hand-editable JSON file and passes them straight to the model
 * create()/insert() methods, which only apply the %s/%d/%f column
 * whitelist -- no content sanitization. Every other save path sanitizes
 * first; these tests pin that import() now does too, for customers,
 * services, invoices, line items and payments (the company profile was
 * already covered by sanitize_company_data()).
 */
class CompanyTransferImportSanitizationTest extends WP_UnitTestCase {

	private $original_user;

	public function set_up() {
		parent::set_up();
		$this->original_user = get_current_user_id();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		wp_set_current_user( $this->original_user );
		parent::tear_down();
	}

	/** A structurally valid export with hostile values in every free-text/date/enum field. */
	private function tampered_export() {
		$xss = '<script>alert(1)</script>';

		return array(
			'format_version' => KCRM_VERSION,
			'exported_at'    => current_time( 'mysql' ),
			'company'        => array(
				'name'  => 'Import Sanitize Co',
				'email' => 'co@example.com',
			),
			'customers'      => array(
				array(
					'id'                 => 1,
					'parent_customer_id' => null,
					'company_name'       => 'Acme ' . $xss,
					'contact_person'     => 'Jane ' . $xss,
					'address_street'     => '1 ' . $xss . ' St',
					'phone'              => '555' . $xss,
					'email'              => 'jane@example.com',
					'notes'              => "line1\n" . $xss,
					'address_country'    => 'ZZ',
					'status'             => 'active',
				),
			),
			'services'       => array(
				array(
					'id'          => 1,
					'name'        => 'Consulting ' . $xss,
					'description' => 'Does things ' . $xss,
					'type'        => 'hourly',
					'rate'        => 100,
					'is_active'   => 1,
					'is_taxable'  => 1,
				),
			),
			'invoices'       => array(
				array(
					'customer_id'        => 1,
					'invoice_number'     => 'INV-1 ' . $xss,
					'status'             => 'open',
					'issue_date'         => 'not-a-date',
					'due_date'           => 'garbage',
					'invoice_type'       => 'other',
					'invoice_type_month' => 'bogus',
					'invoice_type_other' => 'Retainer ' . $xss,
					'notes'              => 'Invoice notes ' . $xss,
					'tax_rate'           => 0,
					'items'              => array(
						array(
							'service_id'  => 1,
							'description' => 'Work item ' . $xss,
							'type'        => 'project',
							'quantity'    => 2,
							'rate'        => 10,
							'amount'      => 999,
							'is_taxable'  => 0,
							'sort_order'  => 0,
						),
					),
					'payments'           => array(
						array(
							'amount'       => 15,
							'payment_date' => 'whenever',
							'method'       => 'Cash ' . $xss,
							'note'         => 'Paid ' . $xss,
						),
					),
				),
			),
		);
	}

	public function test_import_sanitizes_every_record_type() {
		$result = KCRM_Company_Transfer::import( $this->tampered_export() );

		$this->assertIsArray( $result );
		$company_id = $result['company_id'];

		$customers = KCRM_Customer::for_company( $company_id );
		$this->assertCount( 1, $customers );
		$customer = $customers[0];
		foreach ( array( 'company_name', 'contact_person', 'address_street', 'phone', 'notes' ) as $field ) {
			$this->assertStringNotContainsString( '<', $customer->$field, "customer.$field still contains markup" );
			$this->assertStringNotContainsString( 'script', strtolower( $customer->$field ), "customer.$field still contains a script tag" );
		}
		$this->assertContains(
			$customer->address_country,
			array_keys( KCRM_Countries::list() ),
			'invalid address_country should have fallen back to a known code'
		);
		$this->assertSame( KCRM_Countries::DEFAULT_CODE, $customer->address_country );

		$services = KCRM_Service::for_company( $company_id );
		$this->assertCount( 1, $services );
		$this->assertStringNotContainsString( '<', $services[0]->name );
		$this->assertStringNotContainsString( '<', $services[0]->description );

		$invoices = KCRM_Invoice::for_company( $company_id );
		$this->assertCount( 1, $invoices );
		$invoice = $invoices[0];
		$this->assertStringNotContainsString( '<', $invoice->invoice_number );
		$this->assertStringNotContainsString( '<', $invoice->notes );
		$this->assertStringNotContainsString( '<', (string) $invoice->invoice_type_other );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $invoice->issue_date, 'issue_date should be a valid Y-m-d after an invalid input' );
		$this->assertNull( $invoice->due_date, 'an invalid due_date should be stored as NULL' );
		$this->assertNull( $invoice->invoice_type_month, 'an invalid YYYY-MM should be stored as NULL' );

		$items = KCRM_Invoice_Item::for_invoice( $invoice->id );
		$this->assertCount( 1, $items );
		$this->assertStringNotContainsString( '<', $items[0]->description );
		$this->assertEquals( 20.0, (float) $items[0]->amount, 'amount should be recomputed as quantity * rate, not trusted from the file' );

		$payments = KCRM_Payment::for_invoice( $invoice->id );
		$this->assertCount( 1, $payments );
		$this->assertStringNotContainsString( '<', $payments[0]->method );
		$this->assertStringNotContainsString( '<', $payments[0]->note );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}$/', $payments[0]->payment_date );

		// The invoice total is summed from the (recomputed) line-item amounts.
		$this->assertEquals( 20.0, (float) KCRM_Invoice::find( $invoice->id )->total );
	}

	public function test_clean_import_round_trips_unchanged() {
		$data = $this->tampered_export();
		// Replace the hostile values with benign ones.
		$data['customers'][0]['company_name']            = 'Acme LLC';
		$data['customers'][0]['contact_person']          = 'Jane Doe';
		$data['customers'][0]['address_street']          = '1 Main St';
		$data['customers'][0]['phone']                   = '555-1000';
		$data['customers'][0]['notes']                   = 'Preferred client';
		$data['customers'][0]['address_country']         = 'US';
		$data['services'][0]['name']                     = 'Consulting';
		$data['services'][0]['description']              = 'Advisory work';
		$data['invoices'][0]['invoice_number']           = 'INV-1042';
		$data['invoices'][0]['issue_date']               = '2026-01-15';
		$data['invoices'][0]['due_date']                 = '2026-02-15';
		$data['invoices'][0]['invoice_type_month']       = null;
		$data['invoices'][0]['invoice_type_other']       = 'Retainer';
		$data['invoices'][0]['notes']                    = 'Net 30';
		$data['invoices'][0]['items'][0]['description']  = 'Work item';
		$data['invoices'][0]['payments'][0]['payment_date'] = '2026-01-20';
		$data['invoices'][0]['payments'][0]['method']    = 'Cash';
		$data['invoices'][0]['payments'][0]['note']      = 'Deposit';

		$result = KCRM_Company_Transfer::import( $data );

		$this->assertSame( 1, $result['customers'] );
		$this->assertSame( 1, $result['services'] );
		$this->assertSame( 1, $result['invoices'] );
		$this->assertSame( 1, $result['payments'] );

		$customer = KCRM_Customer::for_company( $result['company_id'] )[0];
		$this->assertSame( 'Acme LLC', $customer->company_name );
		$this->assertSame( 'US', $customer->address_country );

		$invoice = KCRM_Invoice::for_company( $result['company_id'] )[0];
		$this->assertSame( 'INV-1042', $invoice->invoice_number );
		$this->assertSame( '2026-01-15', $invoice->issue_date );
		$this->assertSame( '2026-02-15', $invoice->due_date );
	}
}
