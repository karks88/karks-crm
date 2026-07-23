<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * User-managed list of invoice types (Karks CRM -> Invoice Types), shown
 * in the "Invoice Type" dropdown on every invoice (KCRM_Invoice::types()
 * delegates here). Global/site-wide, not scoped to a company -- the
 * vocabulary of "kinds of invoice" is a site setting, not per-company data.
 *
 * `type_key` is generated once from the label at creation time and never
 * changes afterward, since it's what's actually stored on each invoice's
 * `invoice_type` column; renaming a type's label doesn't affect invoices
 * already using it, but changing the key would orphan them. Deleting a
 * type is allowed and safe the same way deleting a Service is -- existing
 * invoices keep their stored key and just display it raw via
 * KCRM_Invoice::type_label()'s `$types[ $type ] ?? $type` fallback.
 *
 * See KCRM_Activator::seed_invoice_types_if_empty() for how this table is
 * seeded differently on a brand-new install vs. one upgrading from before
 * this feature existed.
 */
class KCRM_Invoice_Type extends KCRM_Model_Base {

	public static function table() {
		return KCRM_DB::invoice_types();
	}

	protected static function columns() {
		return array(
			'type_key' => '%s',
			'label'    => '%s',
		);
	}

	public static function all_ordered() {
		return self::where( array(), 'id ASC' );
	}

	/** @return array<string,string> type_key => label, for the Invoice Type dropdown. */
	public static function options() {
		$options = array();
		foreach ( self::all_ordered() as $row ) {
			$options[ $row->type_key ] = $row->label;
		}
		return $options;
	}

	/**
	 * @param string $label
	 * @return int|false New row id, or false if $label is blank.
	 */
	public static function create_from_label( $label ) {
		$label = trim( $label );
		if ( '' === $label ) {
			return false;
		}
		return self::insert(
			array(
				'type_key' => self::unique_key_for( $label ),
				'label'    => $label,
			)
		);
	}

	/**
	 * Renames an existing type. The stored type_key is deliberately left
	 * unchanged -- see the class docblock.
	 *
	 * @return bool Whether the label was updated (false if blank).
	 */
	public static function save_label( $id, $label ) {
		$label = trim( $label );
		if ( '' === $label ) {
			return false;
		}
		self::update( $id, array( 'label' => $label ) );
		return true;
	}

	/** @return string A sanitize_key()'d slug derived from $label, de-duplicated against existing type_keys if needed. */
	private static function unique_key_for( $label ) {
		$base = sanitize_key( $label );
		if ( '' === $base ) {
			$base = 'type';
		}

		$existing = wp_list_pluck( self::all_ordered(), 'type_key' );
		if ( ! in_array( $base, $existing, true ) ) {
			return $base;
		}

		$i = 2;
		while ( in_array( "{$base}-{$i}", $existing, true ) ) {
			$i++;
		}
		return "{$base}-{$i}";
	}
}
