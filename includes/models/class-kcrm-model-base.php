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

	public static function find( $id ) {
		global $wpdb;
		$table = static::table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table CRUD helper; no core caching API applies.
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ) );
	}

	/**
	 * @param array  $where    Column => value equality filters (column names are hardcoded by callers, not user input).
	 * @param string $order_by Raw ORDER BY clause (not user input); restricted to identifier characters as defense in depth.
	 */
	public static function where( $where = array(), $order_by = 'id DESC', $limit = 0, $offset = 0 ) {
		global $wpdb;
		$table = static::table();

		$sql    = 'SELECT * FROM %i';
		$params = array( $table );

		if ( ! empty( $where ) ) {
			$clauses = array();
			foreach ( $where as $column => $value ) {
				$clauses[] = "$column = %s";
				$params[]  = $value;
			}
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
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

		if ( ! empty( $where ) ) {
			$clauses = array();
			foreach ( $where as $column => $value ) {
				$clauses[] = "$column = %s";
				$params[]  = $value;
			}
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
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
