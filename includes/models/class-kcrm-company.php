<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Company extends KCRM_Model_Base {

	public static function table() {
		return KCRM_DB::companies();
	}

	protected static function columns() {
		return array(
			'name'                 => '%s',
			'email'                => '%s',
			'phone'                => '%s',
			'address_street'       => '%s',
			'address_city'         => '%s',
			'address_state'        => '%s',
			'address_postal_code'  => '%s',
			'logo_attachment_id'   => '%d',
			'invoice_prefix'       => '%s',
			'next_invoice_number'  => '%d',
			'default_tax_rate'     => '%f',
			'currency'             => '%s',
			'invoice_footer'       => '%s',
			'created_at'           => '%s',
			'updated_at'           => '%s',
		);
	}

	public static function all_ordered() {
		return self::where( array(), 'name ASC' );
	}

	public static function create( $data ) {
		$now                 = current_time( 'mysql' );
		$data['created_at']  = $now;
		$data['updated_at']  = $now;
		return self::insert( $data );
	}

	public static function save( $id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return self::update( $id, $data );
	}

	/**
	 * Reserve and return the next invoice number for this company,
	 * formatted with its prefix (e.g. "INV-0007"), and increment the counter.
	 */
	public static function next_invoice_number( $company_id ) {
		global $wpdb;
		$table = self::table();

		$company = self::find( $company_id );
		if ( ! $company ) {
			return null;
		}

		$number = (int) $company->next_invoice_number;

		$wpdb->update(
			$table,
			array( 'next_invoice_number' => $number + 1 ),
			array( 'id' => $company_id ),
			array( '%d' ),
			array( '%d' )
		);

		return $company->invoice_prefix . str_pad( $number, 4, '0', STR_PAD_LEFT );
	}
}
