<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Small shared CRUD helper. Subclasses just declare a table name and
 * (optionally) a whitelist of insert/update-able columns.
 */
abstract class KCRM_Model_Base {

	/** @return string Fully-prefixed table name. */
	abstract public static function table();

	/**
	 * Column => format ('%d', '%s', '%f') for everything the model
	 * accepts on insert/update. Subclasses override this.
	 *
	 * @return array
	 */
	protected static function columns() {
		return array();
	}

	/**
	 * Restricts an ORDER BY clause to identifier characters, whitespace,
	 * commas, and dots so it can be safely concatenated into a query
	 * (the %i placeholder only covers single identifiers, not a full
	 * "column ASC, column2 DESC" clause).
	 */
	protected static function safe_order_by( $order_by, $default = 'id DESC' ) {
		return ( is_string( $order_by ) && '' !== $order_by && preg_match( '/^[a-zA-Z0-9_.,\s]+$/', $order_by ) )
			? $order_by
			: $default;
	}

	/**
	 * Builds "$column = %s" clauses for where()/count_where(), restricted to
	 * $where keys that are actually declared columns() (or 'id') -- the
	 * column name itself can't go through a %s/%i placeholder the way a
	 * value can, so every caller today passes hardcoded literal keys; this
	 * is a second, structural guard against that ever silently stopping
	 * being true, the same whitelist discipline insert()/update() already
	 * apply to values.
	 *
	 * @return array{0: string[], 1: array} [ $clauses, $params ] -- a column
	 * not in the whitelist is simply dropped from $where, not fatal.
	 */
	private static function safe_where_clauses( array $where ) {
		$allowed = array_keys( static::columns() );
		$allowed[] = 'id';

		$clauses = array();
		$params  = array();
		foreach ( $where as $column => $value ) {
			if ( ! in_array( $column, $allowed, true ) ) {
				continue;
			}
			$clauses[] = "$column = %s";
			$params[]  = $value;
		}
		return array( $clauses, $params );
	}

	public static function find( $id ) {
		global $wpdb;
		$table = static::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
	}

	/**
	 * Batched find() for a list of ids -- one query instead of one per id,
	 * for call sites that would otherwise call find() inside a loop.
	 *
	 * @return array<int,object> Row objects keyed by id. Missing/invalid ids are simply absent.
	 */
	public static function find_many( array $ids ) {
		global $wpdb;

		$ids = array_unique( array_filter( array_map( 'absint', $ids ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$table        = static::table();
		$placeholders = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $placeholders is only repeated %d placeholder syntax (its count matches count( $ids )), not user input; query text and args are passed to $wpdb->prepare() on this line.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE id IN (' . $placeholders . ')', array_merge( array( $table ), $ids ) ) );

		$indexed = array();
		foreach ( $rows as $row ) {
			$indexed[ (int) $row->id ] = $row;
		}
		return $indexed;
	}

	/**
	 * @param array  $where    Column => value equality filters (column names are hardcoded by callers, not user input; also enforced by safe_where_clauses() as defense in depth).
	 * @param string $order_by Raw ORDER BY clause (not user input); restricted to identifier characters as defense in depth.
	 */
	public static function where( $where = array(), $order_by = 'id DESC', $limit = 0, $offset = 0 ) {
		global $wpdb;
		$table = static::table();

		$sql    = 'SELECT * FROM %i';
		$params = array( $table );

		list( $clauses, $clause_params ) = static::safe_where_clauses( $where );
		if ( $clauses ) {
			$sql   .= ' WHERE ' . implode( ' AND ', $clauses );
			$params = array_merge( $params, $clause_params );
		}

		if ( $order_by ) {
			$sql .= ' ORDER BY ' . static::safe_order_by( $order_by );
		}

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from %i/%s/%d placeholders only, filled in via $wpdb->prepare() on the same line; the dynamic WHERE/LIMIT clause count can't be a static literal.
		return $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
	}

	public static function count_where( $where = array() ) {
		global $wpdb;
		$table = static::table();

		$sql    = 'SELECT COUNT(*) FROM %i';
		$params = array( $table );

		list( $clauses, $clause_params ) = static::safe_where_clauses( $where );
		if ( $clauses ) {
			$sql   .= ' WHERE ' . implode( ' AND ', $clauses );
			$params = array_merge( $params, $clause_params );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built from %i/%s placeholders only, filled in via $wpdb->prepare() on the same line; the dynamic WHERE clause count can't be a static literal.
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
	}

	public static function insert( $data ) {
		global $wpdb;
		$table   = static::table();
		$columns = static::columns();

		$filtered = array();
		$formats  = array();
		foreach ( $columns as $column => $format ) {
			if ( array_key_exists( $column, $data ) ) {
				$filtered[ $column ] = $data[ $column ];
				$formats[]           = $format;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- custom table CRUD helper; $wpdb->insert() already escapes values.
		$wpdb->insert( $table, $filtered, $formats );
		return (int) $wpdb->insert_id;
	}

	public static function update( $id, $data ) {
		global $wpdb;
		$table   = static::table();
		$columns = static::columns();

		$filtered = array();
		$formats  = array();
		foreach ( $columns as $column => $format ) {
			if ( array_key_exists( $column, $data ) ) {
				$filtered[ $column ] = $data[ $column ];
				$formats[]           = $format;
			}
		}

		if ( empty( $filtered ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; $wpdb->update() already escapes values.
		return $wpdb->update( $table, $filtered, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$table = static::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; $wpdb->delete() already escapes values.
		return $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}
}
