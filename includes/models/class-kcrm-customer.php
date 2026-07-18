<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Customer extends KCRM_Model_Base {

	const STATUS_ACTIVE   = 'active';
	const STATUS_INACTIVE = 'inactive';

	public static function table() {
		return KCRM_DB::customers();
	}

	public static function statuses() {
		return array(
			self::STATUS_ACTIVE   => __( 'Active', 'karks-crm' ),
			self::STATUS_INACTIVE => __( 'Inactive', 'karks-crm' ),
		);
	}

	protected static function columns() {
		return array(
			'company_id'                => '%d',
			'parent_customer_id'        => '%d',
			'company_name'              => '%s',
			'contact_person'            => '%s',
			'secondary_contact_person'  => '%s',
			'address_street'            => '%s',
			'address_city'              => '%s',
			'address_state'             => '%s',
			'address_postal_code'       => '%s',
			'phone'                     => '%s',
			'email'                     => '%s',
			'secondary_email'           => '%s',
			'notes'                     => '%s',
			'status'                    => '%s',
			'created_at'                => '%s',
			'updated_at'                => '%s',
		);
	}

	public static function for_company( $company_id, $order_by = 'company_name ASC' ) {
		return self::where( array( 'company_id' => $company_id ), $order_by );
	}

	/** Customers that aren't a Job of another customer. */
	public static function top_level_for_company( $company_id, $order_by = 'company_name ASC' ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM $table WHERE company_id = %d AND parent_customer_id IS NULL ORDER BY $order_by", $company_id ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	/** The Jobs that belong to a given (top-level) customer. */
	public static function jobs_for( $parent_customer_id, $order_by = 'company_name ASC' ) {
		return self::where( array( 'parent_customer_id' => $parent_customer_id ), $order_by );
	}

	/** @return string "Job Name (Parent Name)" for a Job, otherwise just the company name. */
	public static function display_name( $customer ) {
		if ( empty( $customer->parent_customer_id ) ) {
			return $customer->company_name;
		}
		$parent = self::find( $customer->parent_customer_id );
		return $parent
			? sprintf( '%s (%s)', $customer->company_name, $parent->company_name )
			: $customer->company_name;
	}

	/**
	 * Outstanding balance across a set of customer ids (a customer plus its Jobs).
	 */
	public static function balance_for_ids( array $customer_ids ) {
		global $wpdb;

		$customer_ids = array_filter( array_map( 'absint', $customer_ids ) );
		if ( empty( $customer_ids ) ) {
			return 0.0;
		}

		$invoices = KCRM_DB::invoices();
		$payments = KCRM_DB::payments();

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );

		$invoiced = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(total), 0) FROM $invoices WHERE customer_id IN ($placeholders) AND status != 'void'", $customer_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		$paid = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $payments WHERE customer_id IN ($placeholders)", $customer_ids ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);

		return round( $invoiced - $paid, 2 );
	}

	public static function create( $data ) {
		$now                = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		if ( empty( $data['status'] ) ) {
			$data['status'] = self::STATUS_ACTIVE;
		}
		return self::insert( $data );
	}

	public static function save( $id, $data ) {
		$data['updated_at'] = current_time( 'mysql' );
		return self::update( $id, $data );
	}

	/**
	 * Outstanding balance = sum of non-void invoice totals minus sum of payments.
	 */
	public static function balance( $customer_id ) {
		global $wpdb;

		$invoices = KCRM_DB::invoices();
		$payments = KCRM_DB::payments();

		$invoiced = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total), 0) FROM $invoices WHERE customer_id = %d AND status != 'void'",
				$customer_id
			)
		);

		$paid = (float) $wpdb->get_var(
			$wpdb->prepare( "SELECT COALESCE(SUM(amount), 0) FROM $payments WHERE customer_id = %d", $customer_id )
		);

		return round( $invoiced - $paid, 2 );
	}
}
