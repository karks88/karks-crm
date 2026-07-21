<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Service extends KCRM_Model_Base {

	const TYPE_HOURLY = 'hourly';
	const TYPE_PROJECT = 'project';

	public static function table() {
		return KCRM_DB::services();
	}

	protected static function columns() {
		return array(
			'company_id'  => '%d',
			'name'        => '%s',
			'description' => '%s',
			'type'        => '%s',
			'rate'        => '%f',
			'is_active'   => '%d',
			'created_at'  => '%s',
			'updated_at'  => '%s',
		);
	}

	public static function types() {
		return array(
			self::TYPE_HOURLY  => __( 'Hourly', 'karks-crm' ),
			self::TYPE_PROJECT => __( 'Project-based', 'karks-crm' ),
		);
	}

	public static function for_company( $company_id, $order_by = 'name ASC' ) {
		return self::where( array( 'company_id' => $company_id ), $order_by );
	}

	public static function active_for_company( $company_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND is_active = 1 ORDER BY name ASC', self::table(), $company_id )
		);
	}

	public static function create( $data ) {
		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		if ( ! isset( $data['is_active'] ) ) {
			$data['is_active'] = 1;
		}
		return self::insert( $data );
	}

	public static function save( $id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return self::update( $id, $data );
	}
}
