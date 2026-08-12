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
			'address_street_2'          => '%s',
			'address_city'              => '%s',
			'address_state'             => '%s',
			'address_postal_code'       => '%s',
			'address_country'           => '%s',
			'phone'                     => '%s',
			'email'                     => '%s',
			'secondary_email'           => '%s',
			'invoice_recipient_name'    => '%s',
			'invoice_recipient_email'   => '%s',
			'notes'                     => '%s',
			'status'                    => '%s',
			'created_at'                => '%s',
			'updated_at'                => '%s',
		);
	}

	public static function for_company( $company_id, $order_by = 'company_name ASC' ) {
		return self::where( array( 'company_id' => $company_id ), $order_by );
	}

	/**
	 * Customers that aren't a Job of another customer.
	 *
	 * @param string|null $status Limit to this status (e.g. 'active'), or null for all statuses.
	 */
	public static function top_level_for_company( $company_id, $order_by = 'company_name ASC', $status = null, $limit = 0, $offset = 0 ) {
		global $wpdb;

		$sql    = 'SELECT * FROM %i WHERE company_id = %d AND parent_customer_id IS NULL';
		$params = array( self::table(), $company_id );

		if ( null !== $status ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}

		$sql .= ' ORDER BY ' . self::safe_order_by( $order_by );

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from %i/%d/%s placeholder syntax only, filled in via $wpdb->prepare() on the same line; the ORDER BY suffix is restricted to safe identifier characters by safe_order_by().
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	/**
	 * Count companion to top_level_for_company(), for pagination.
	 *
	 * @param string|null $status Limit to this status (e.g. 'active'), or null for all statuses.
	 */
	public static function count_top_level_for_company( $company_id, $status = null ) {
		global $wpdb;

		$sql    = 'SELECT COUNT(*) FROM %i WHERE company_id = %d AND parent_customer_id IS NULL';
		$params = array( self::table(), $company_id );

		if ( null !== $status ) {
			$sql     .= ' AND status = %s';
			$params[] = $status;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from %i/%d/%s placeholder syntax only, filled in via $wpdb->prepare() on the same line.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	/** The Jobs that belong to a given (top-level) customer. */
	public static function jobs_for( $parent_customer_id, $order_by = 'company_name ASC' ) {
		return self::where( array( 'parent_customer_id' => $parent_customer_id ), $order_by );
	}

	/**
	 * Batched jobs_for() across a list of (top-level) parent customer ids --
	 * one query instead of one per parent, for call sites that would
	 * otherwise call jobs_for() inside a loop.
	 *
	 * @return array<int,object[]> parent_customer_id => list of Job rows. Parents with no Jobs are simply absent.
	 */
	public static function jobs_for_many( array $parent_customer_ids ) {
		global $wpdb;

		$parent_customer_ids = array_unique( array_filter( array_map( 'absint', $parent_customer_ids ) ) );
		if ( empty( $parent_customer_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $parent_customer_ids ), '%d' ) );
		$sql          = 'SELECT * FROM %i WHERE parent_customer_id IN (' . $placeholders . ') ORDER BY company_name ASC';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is only repeated %d placeholder syntax (its count matches count( $parent_customer_ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( array( self::table() ), $parent_customer_ids ) ) );

		$grouped = array();
		foreach ( $rows as $row ) {
			$grouped[ (int) $row->parent_customer_id ][] = $row;
		}
		return $grouped;
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
	 * Outstanding balance across every customer (and Job) in a company -- same
	 * invoiced-minus-paid definition as balance()/balance_for_ids(), scoped by
	 * company_id directly rather than a customer id list, so it's unaffected
	 * by list-view pagination.
	 */
	public static function balance_for_company( $company_id ) {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		$invoiced = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(total), 0) FROM %i WHERE company_id = %d AND status != 'void'",
				KCRM_DB::invoices(),
				$company_id
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		$paid = (float) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COALESCE(SUM(amount), 0) FROM %i WHERE company_id = %d', KCRM_DB::payments(), $company_id )
		);

		return round( $invoiced - $paid, 2 );
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

	/**
	 * Batched balance_for_ids() across a list of top-level customers -- each
	 * customer's balance already includes its own Jobs (same definition as
	 * balance_for_ids()), but computed with 3 queries total instead of up
	 * to 3 queries per customer. For call sites that would otherwise call
	 * jobs_for() + balance_for_ids() inside a loop.
	 *
	 * @param object[] $customers Top-level customer rows (each needs ->id).
	 * @return array<int,float> customer_id => balance due, keyed by the top-level customer's own id.
	 */
	public static function balances_for_top_level( array $customers ) {
		if ( empty( $customers ) ) {
			return array();
		}

		$jobs_by_parent = self::jobs_for_many( wp_list_pluck( $customers, 'id' ) );

		$family_ids = array();
		foreach ( $customers as $customer ) {
			$cid                = (int) $customer->id;
			$job_ids            = wp_list_pluck( $jobs_by_parent[ $cid ] ?? array(), 'id' );
			$family_ids[ $cid ] = array_merge( array( $cid ), $job_ids );
		}

		$all_ids  = array_unique( array_merge( ...array_values( $family_ids ) ) );
		$invoiced = self::sum_by_customer( KCRM_DB::invoices(), 'total', $all_ids, "status != 'void'" );
		$paid     = self::sum_by_customer( KCRM_DB::payments(), 'amount', $all_ids );

		$result = array();
		foreach ( $family_ids as $cid => $ids_in_family ) {
			$inv = 0.0;
			$pd  = 0.0;
			foreach ( $ids_in_family as $fid ) {
				$inv += $invoiced[ $fid ] ?? 0.0;
				$pd  += $paid[ $fid ] ?? 0.0;
			}
			$result[ $cid ] = round( $inv - $pd, 2 );
		}
		return $result;
	}

	/**
	 * Batched balance() across a list of individual customer ids -- each
	 * id's own balance, NOT combined with any Jobs (see
	 * balances_for_top_level() for that). One query pair total instead of
	 * one balance() call per id.
	 *
	 * @return array<int,float> customer_id => balance due.
	 */
	public static function balances_for( array $customer_ids ) {
		$customer_ids = array_unique( array_filter( array_map( 'absint', $customer_ids ) ) );
		if ( empty( $customer_ids ) ) {
			return array();
		}

		$invoiced = self::sum_by_customer( KCRM_DB::invoices(), 'total', $customer_ids, "status != 'void'" );
		$paid     = self::sum_by_customer( KCRM_DB::payments(), 'amount', $customer_ids );

		$result = array();
		foreach ( $customer_ids as $cid ) {
			$result[ $cid ] = round( ( $invoiced[ $cid ] ?? 0.0 ) - ( $paid[ $cid ] ?? 0.0 ), 2 );
		}
		return $result;
	}

	/** Shared "SUM($sum_column) ... GROUP BY customer_id" helper for balances_for_top_level()/balances_for(). */
	private static function sum_by_customer( $table, $sum_column, array $customer_ids, $extra_where = '' ) {
		global $wpdb;

		$customer_ids = array_unique( array_filter( array_map( 'absint', $customer_ids ) ) );
		if ( empty( $customer_ids ) ) {
			return array();
		}

		$placeholders = implode( ', ', array_fill( 0, count( $customer_ids ), '%d' ) );
		$where        = 'customer_id IN (' . $placeholders . ')' . ( $extra_where ? " AND $extra_where" : '' );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sum_column/$extra_where/$placeholders are hardcoded by callers (not user input); $placeholders' count matches count( $customer_ids ); query text and args are passed to $wpdb->prepare() on this line.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT customer_id, COALESCE(SUM($sum_column), 0) AS total FROM %i WHERE $where GROUP BY customer_id", array_merge( array( $table ), $customer_ids ) ) );

		$totals = array();
		foreach ( $rows as $row ) {
			$totals[ (int) $row->customer_id ] = (float) $row->total;
		}
		return $totals;
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
