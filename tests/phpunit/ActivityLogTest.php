<?php
/**
 * KCRM_Activity: entries are scoped to their company no matter what other
 * filters are passed, carry no record of who acted, stay readable after the
 * record they describe is deleted, and are removed by retention cleanup only
 * once they're past the cutoff.
 */
class ActivityLogTest extends WP_UnitTestCase {

	private $company_id;
	private $other_company_id;

	public function set_up() {
		parent::set_up();
		$this->company_id       = KCRM_Company::create( array( 'name' => 'Activity Test Co' ) );
		$this->other_company_id = KCRM_Company::create( array( 'name' => 'Other Co' ) );
	}

	private function insert_at( $created_at, array $data = array() ) {
		return KCRM_Activity::insert(
			array_merge(
				array(
					'company_id'  => $this->company_id,
					'event'       => 'company.updated',
					'object_type' => 'company',
					'details'     => '{}',
					'created_at'  => $created_at,
				),
				$data
			)
		);
	}

	public function test_table_has_no_user_column() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only schema introspection, not request-time data access.
		$columns = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', KCRM_DB::activity() ), 0 );

		$this->assertContains( 'company_id', $columns );
		$this->assertEmpty( preg_grep( '/user/i', $columns ), 'the activity table must not record who performed an action' );
	}

	public function test_log_writes_a_row_and_derives_object_type() {
		$id  = KCRM_Activity::log( 'service.created', array( 'company_id' => $this->company_id, 'object_id' => 7, 'details' => array( 'service_name' => 'Hosting' ) ) );
		$row = KCRM_Activity::find( $id );

		$this->assertNotEmpty( $row );
		$this->assertSame( 'service', $row->object_type );
		$this->assertSame( 'Hosting', json_decode( $row->details, true )['service_name'] );
	}

	public function test_turning_logging_off_stops_new_entries() {
		update_option( KCRM_Activity::ENABLED_OPTION, 0 );
		$this->assertSame( 0, KCRM_Activity::log( 'company.updated', array( 'company_id' => $this->company_id ) ) );
		$this->assertSame( 0, KCRM_Activity::count_query( $this->company_id ) );

		update_option( KCRM_Activity::ENABLED_OPTION, 1 );
		$this->assertGreaterThan( 0, KCRM_Activity::log( 'company.updated', array( 'company_id' => $this->company_id ) ) );
	}

	public function test_logging_is_on_by_default() {
		delete_option( KCRM_Activity::ENABLED_OPTION );
		$this->assertTrue( KCRM_Activity::is_enabled() );
	}

	public function test_admin_context_links_to_wp_admin_screens() {
		$customer_id = KCRM_Customer::create( array( 'company_id' => $this->company_id, 'company_name' => 'Acme' ) );
		$invoice_id  = KCRM_Invoice::create( array( 'company_id' => $this->company_id, 'customer_id' => $customer_id, 'invoice_number' => 'INV-7' ) );
		KCRM_Activity::log_invoice( 'invoice.created', KCRM_Invoice::find( $invoice_id ) );

		$items = KCRM_Activity_Feed::describe_rows( KCRM_Activity::query( $this->company_id ), 'admin' );

		$this->assertStringContainsString( 'admin.php?page=karks-crm-invoices', $items[0]['url'] );
		$this->assertStringContainsString( 'id=' . $invoice_id, $items[0]['url'] );
	}

	public function test_log_without_a_company_writes_nothing() {
		$this->assertSame( 0, KCRM_Activity::log( 'company.updated', array( 'company_id' => 0 ) ) );
	}

	public function test_query_is_always_scoped_to_the_company() {
		$customer_id = KCRM_Customer::create( array( 'company_id' => $this->other_company_id, 'company_name' => 'Elsewhere LLC' ) );
		KCRM_Activity::log( 'customer.created', array( 'company_id' => $this->other_company_id, 'customer_id' => $customer_id ) );

		// Even filtering on another company's customer id must not reach that company's entries.
		$this->assertSame( array(), KCRM_Activity::query( $this->company_id, array( 'customer_ids' => array( $customer_id ) ) ) );
		$this->assertSame( 0, KCRM_Activity::count_query( $this->company_id ) );
		$this->assertSame( 1, KCRM_Activity::count_query( $this->other_company_id ) );
	}

	public function test_query_filters_by_event_customers_and_date() {
		$this->insert_at( '2026-03-10 09:00:00', array( 'event' => 'invoice.created', 'object_type' => 'invoice', 'customer_id' => 11 ) );
		$this->insert_at( '2026-03-12 09:00:00', array( 'event' => 'payment.created', 'object_type' => 'payment', 'customer_id' => 12 ) );
		$this->insert_at( '2026-05-01 09:00:00', array( 'event' => 'invoice.created', 'object_type' => 'invoice', 'customer_id' => 11 ) );

		$this->assertSame( 2, KCRM_Activity::count_query( $this->company_id, array( 'events' => KCRM_Activity::event_groups()['invoices'] ) ) );
		$this->assertSame( 1, KCRM_Activity::count_query( $this->company_id, array( 'customer_ids' => array( 12 ) ) ) );
		$this->assertSame( 2, KCRM_Activity::count_query( $this->company_id, array( 'from' => '2026-03-01', 'to' => '2026-03-31' ) ) );
		$this->assertSame( 0, KCRM_Activity::count_query( $this->company_id, array( 'customer_ids' => array() ) ), 'an explicit empty customer filter should match nothing' );

		$rows = KCRM_Activity::query( $this->company_id );
		$this->assertSame( '2026-05-01 09:00:00', $rows[0]->created_at, 'newest first' );
	}

	public function test_deleted_invoice_entry_keeps_its_label_and_has_no_link() {
		$customer_id = KCRM_Customer::create( array( 'company_id' => $this->company_id, 'company_name' => 'Acme' ) );
		$invoice_id  = KCRM_Invoice::create( array( 'company_id' => $this->company_id, 'customer_id' => $customer_id, 'invoice_number' => 'INV-1042' ) );
		$invoice     = KCRM_Invoice::find( $invoice_id );

		KCRM_Activity::log_invoice( 'invoice.created', $invoice );
		KCRM_Invoice::delete( $invoice_id );

		$items = KCRM_Activity_Feed::describe_rows( KCRM_Activity::query( $this->company_id ) );

		$this->assertSame( 'Invoice INV-1042 created for Acme', $items[0]['label'] );
		$this->assertNull( $items[0]['url'], 'an entry for a deleted record must not link to it' );
	}

	public function test_status_change_is_logged_only_when_status_moves() {
		$customer_id = KCRM_Customer::create( array( 'company_id' => $this->company_id, 'company_name' => 'Acme' ) );
		$invoice_id  = KCRM_Invoice::create( array( 'company_id' => $this->company_id, 'customer_id' => $customer_id, 'invoice_number' => 'INV-1' ) );

		$this->assertSame( 0, KCRM_Activity::log_status_change( $invoice_id, KCRM_Invoice::STATUS_OPEN ) );

		KCRM_Invoice::save( $invoice_id, array( 'status' => KCRM_Invoice::STATUS_VOID ) );
		$this->assertGreaterThan( 0, KCRM_Activity::log_status_change( $invoice_id, KCRM_Invoice::STATUS_OPEN ) );
	}

	public function test_prune_removes_only_rows_older_than_the_cutoff() {
		$old = $this->insert_at( '2020-01-01 00:00:00' );
		$new = $this->insert_at( '2026-01-01 00:00:00' );

		$this->assertSame( 1, KCRM_Activity::prune( '2025-01-01 00:00:00' ) );
		$this->assertNull( KCRM_Activity::find( $old ) );
		$this->assertNotNull( KCRM_Activity::find( $new ) );
	}

	public function test_forever_retention_prunes_nothing() {
		update_option( KCRM_Activity::RETENTION_OPTION, 0 );
		$this->insert_at( '2000-01-01 00:00:00' );

		$this->assertSame( 0, KCRM_Activity::prune_expired() );
	}

	public function test_unknown_retention_value_falls_back_to_default() {
		update_option( KCRM_Activity::RETENTION_OPTION, 7 );
		$this->assertSame( KCRM_Activity::DEFAULT_RETENTION_MONTHS, KCRM_Activity::retention_months() );
	}

	public function test_clear_for_company_leaves_other_companies_alone() {
		$this->insert_at( '2026-01-01 00:00:00' );
		$this->insert_at( '2026-01-01 00:00:00', array( 'company_id' => $this->other_company_id ) );

		KCRM_Activity::clear_for_company( $this->company_id );

		$this->assertSame( 0, KCRM_Activity::count_query( $this->company_id ) );
		$this->assertSame( 1, KCRM_Activity::count_query( $this->other_company_id ) );
	}
}
