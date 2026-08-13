<?php
/**
 * KCRM_Model_Base::update() only ever writes columns present in both the
 * model's columns() whitelist AND the $data passed to it -- this is what
 * makes a partial update() call (e.g. field_or_existing() building up only
 * some keys) safe instead of nulling out everything not explicitly passed.
 */
class ModelColumnWhitelistTest extends WP_UnitTestCase {

	private $company_id;

	public function set_up() {
		parent::set_up();
		$this->company_id = KCRM_Company::create(
			array(
				'name'              => 'Whitelist Test Co',
				'email'             => 'original@example.com',
				'phone'             => '555-0100',
				'check_payable_to'  => 'Original Payee',
			)
		);
	}

	public function test_update_with_one_field_leaves_others_untouched() {
		KCRM_Company::update( $this->company_id, array( 'phone' => '555-0199' ) );

		$company = KCRM_Company::find( $this->company_id );
		$this->assertSame( '555-0199', $company->phone );
		$this->assertSame( 'original@example.com', $company->email, 'update() with only phone set clobbered email' );
		$this->assertSame( 'Whitelist Test Co', $company->name, 'update() with only phone set clobbered name' );
		$this->assertSame( 'Original Payee', $company->check_payable_to, 'update() with only phone set clobbered check_payable_to' );
	}

	public function test_update_ignores_keys_not_in_the_column_whitelist() {
		$result = KCRM_Company::update(
			$this->company_id,
			array(
				'phone'                  => '555-0199',
				'id'                     => 999999, // Not a declared column -- must not be writable via update().
				'this_column_is_made_up' => 'should be silently ignored',
			)
		);

		$this->assertNotFalse( $result );
		$company = KCRM_Company::find( $this->company_id );
		$this->assertSame( $this->company_id, (int) $company->id, 'update() let an unlisted "id" key overwrite the primary key' );
		$this->assertSame( '555-0199', $company->phone );
	}

	public function test_update_with_no_recognized_keys_is_a_safe_no_op() {
		$before = KCRM_Company::find( $this->company_id );

		$result = KCRM_Company::update( $this->company_id, array( 'not_a_real_column' => 'x' ) );

		$this->assertFalse( $result, 'update() with nothing whitelisted should return false, not attempt an empty write' );
		$this->assertEquals( $before, KCRM_Company::find( $this->company_id ) );
	}
}
