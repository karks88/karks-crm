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
		$sql = 'SELECT * FROM %i WHERE company_id = %d AND parent_customer_id IS NULL ORDER BY ' . self::safe_order_by( $order_by );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a %i/%d placeholder template filled in via $wpdb->prepare() on the same line; the ORDER BY suffix is restricted to safe identifier characters by safe_order_by().
		return $wpdb->get_results( $wpdb->prepare( $sql, self::table(), $company_id ) );
	}

	/** The Jobs that belong to a given (top-level) customer. */
	public static function jobs_for( $parent_customer_id, $order_by = 'company_name ASC' ) {
		return self::where( array( 'parent_customer_id' => $parent_customer_id ), $order_by );
	}

	/** Customers (including Jobs) added to this company on/after a cutoff (a 'Y-m-d H:i:s' string) -- for the company profile's Recent Actions feed. */
	public static function created_since( $company_id, $since ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is a %i/%d/%s placeholder template filled in via $wpdb->prepare() on the same line.
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE company_id = %d AND created_at >= %s ORDER BY created_at DESC, id DESC', self::table(), $company_id, $since ) );
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

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only repeated %d placeholder syntax (its count matches count( $customer_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		$invoiced = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(total), 0) FROM %i WHERE customer_id IN (' . $placeholders . ") AND status != 'void'", array_merge( array( KCRM_DB::invoices() ), $customer_ids ) ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- $placeholders is only repeated %d placeholder syntax (its count matches count( $customer_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		$paid = (float) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE customer_id IN (' . $placeholders . ')', array_merge( array( KCRM_DB::payments() ), $customer_ids ) ) );

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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		$invoiced = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total), 0) FROM %i WHERE customer_id = %d AND status != 'void'",
				KCRM_DB::invoices(),
				$customer_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		$paid = (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE customer_id = %d', KCRM_DB::payments(), $customer_id )
		);

		return round( $invoiced - $paid, 2 );
	}
}
