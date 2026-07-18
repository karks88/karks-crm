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

	public static function find( $id ) {
		global $wpdb;
		$table = static::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ) );
	}

	/**
	 * @param array $where   Column => value equality filters.
	 * @param string $order_by Raw ORDER BY clause (not user input).
	 */
	public static function where( $where = array(), $order_by = 'id DESC', $limit = 0, $offset = 0 ) {
		global $wpdb;
		$table = static::table();

		$sql    = "SELECT * FROM $table";
		$params = array();

		if ( ! empty( $where ) ) {
			$clauses = array();
			foreach ( $where as $column => $value ) {
				$clauses[] = "$column = %s";
				$params[]  = $value;
			}
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		if ( $order_by ) {
			$sql .= ' ORDER BY ' . $order_by;
		}

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d OFFSET %d';
			$params[] = $limit;
			$params[] = $offset;
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return $wpdb->get_results( $sql );
	}

	public static function count_where( $where = array() ) {
		global $wpdb;
		$table = static::table();

		$sql    = "SELECT COUNT(*) FROM $table";
		$params = array();

		if ( ! empty( $where ) ) {
			$clauses = array();
			foreach ( $where as $column => $value ) {
				$clauses[] = "$column = %s";
				$params[]  = $value;
			}
			$sql .= ' WHERE ' . implode( ' AND ', $clauses );
		}

		if ( ! empty( $params ) ) {
			$sql = $wpdb->prepare( $sql, $params );
		}

		return (int) $wpdb->get_var( $sql );
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

		return $wpdb->update( $table, $filtered, array( 'id' => $id ), $formats, array( '%d' ) );
	}

	public static function delete( $id ) {
		global $wpdb;
		$table = static::table();
		return $wpdb->delete( $table, array( 'id' => $id ), array( '%d' ) );
	}
}
