<?php
/**
 * Guards the core promise the whole upgrade path (KCRM_Activator) relies
 * on: running create_tables() again -- which happens on every version bump,
 * and via maybe_upgrade() on every request until it succeeds -- must never
 * drop a column or lose row data, even for columns dbDelta() doesn't know
 * about from the current CREATE TABLE strings.
 */
class SchemaIntegrityTest extends WP_UnitTestCase {

	/** Every column added to the companies/customers tables should show up here -- catches a create_tables() edit that silently drops one. */
	public function test_expected_columns_exist_on_companies_table() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only schema introspection, not request-time data access.
		$columns = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $wpdb->prefix . 'karkscrm_companies' ), 0 );

		foreach ( array( 'address_street', 'address_street_2', 'address_city', 'address_state', 'address_postal_code', 'address_country', 'check_payable_to', 'other_payment_instructions', 'invoice_bcc_enabled', 'invoice_bcc_email' ) as $expected ) {
			$this->assertContains( $expected, $columns, "companies table is missing column '$expected'" );
		}
	}

	public function test_expected_columns_exist_on_customers_table() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only schema introspection, not request-time data access.
		$columns = $wpdb->get_col( $wpdb->prepare( 'DESCRIBE %i', $wpdb->prefix . 'karkscrm_customers' ), 0 );

		foreach ( array( 'address_street', 'address_street_2', 'address_city', 'address_state', 'address_postal_code', 'address_country' ) as $expected ) {
			$this->assertContains( $expected, $columns, "customers table is missing column '$expected'" );
		}
	}

	/** New records get the documented default country without the caller having to set it explicitly. */
	public function test_address_country_defaults_to_us() {
		$company_id = KCRM_Company::create( array( 'name' => 'Test Co' ) );
		$company    = KCRM_Company::find( $company_id );

		$this->assertSame( 'US', $company->address_country );
	}

	/**
	 * Re-running create_tables() (what every maybe_upgrade() call does once
	 * kcrm_db_version is behind) must be a safe no-op against real data --
	 * this is the literal mechanism "will an upgrade lose my data" depends on.
	 */
	public function test_rerunning_create_tables_preserves_existing_rows() {
		$company_id = KCRM_Company::create(
			array(
				'name'                       => 'Data Integrity Test Co',
				'email'                      => 'billing@example.com',
				'address_street'             => '123 Main St',
				'address_street_2'           => 'Suite 400',
				'address_country'            => 'FR',
				'check_payable_to'           => 'Data Integrity Test Co LLC',
				'other_payment_instructions' => 'Venmo @data-integrity-test',
				'accepted_payment_types'     => 'check,other',
				'payment_links'              => '[{"label":"Pay","url":"https://example.com/pay"}]',
				'invoice_bcc_enabled'        => 1,
				'invoice_bcc_email'          => 'bcc@example.com',
			)
		);
		$before = KCRM_Company::find( $company_id );

		$ref = new ReflectionMethod( 'KCRM_Activator', 'create_tables' );
		$ref->setAccessible( true );
		$errors = $ref->invoke( null );

		$this->assertSame( array(), $errors, 'create_tables() reported an error on an already-current schema' );

		$after = KCRM_Company::find( $company_id );
		$this->assertEquals( $before, $after, 're-running create_tables() altered an existing row' );
	}

	/**
	 * dbDelta() is documented (WordPress core) to only ever add/widen columns,
	 * never drop one -- even a column that exists in the live table but isn't
	 * mentioned in the current CREATE TABLE string at all (e.g. one that
	 * predates a refactor). This proves that guarantee holds for our actual
	 * table, with real data in the column, not just as a claim about dbDelta
	 * in the abstract.
	 */
	public function test_create_tables_does_not_drop_an_unrecognized_existing_column() {
		global $wpdb;
		$table = $wpdb->prefix . 'karkscrm_companies';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- test-only DDL against a throwaway column, simulating a pre-refactor column create_tables() doesn't know about; %i is filled in via prepare() on the same line.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i ADD COLUMN kcrm_test_legacy_column VARCHAR(50) NULL', $table ) );

		$company_id = KCRM_Company::create( array( 'name' => 'Legacy Column Test Co' ) );
		$wpdb->update( $table, array( 'kcrm_test_legacy_column' => 'keep-me' ), array( 'id' => $company_id ) );

		$ref = new ReflectionMethod( 'KCRM_Activator', 'create_tables' );
		$ref->setAccessible( true );
		$ref->invoke( null );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only read of the throwaway column above.
		$value = $wpdb->get_var( $wpdb->prepare( 'SELECT kcrm_test_legacy_column FROM %i WHERE id = %d', $table, $company_id ) );
		$this->assertSame( 'keep-me', $value, 'create_tables() dropped or cleared a column it doesn\'t know about' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- cleaning up the throwaway column added above; %i is filled in via prepare() on the same line.
		$wpdb->query( $wpdb->prepare( 'ALTER TABLE %i DROP COLUMN kcrm_test_legacy_column', $table ) );
	}
}
