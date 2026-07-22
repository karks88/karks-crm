<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The front-end's 4-color scheme (Primary/Secondary/Accent/Highlight),
 * customizable from the Appearance settings screen (KCRM_Admin_Appearance).
 * front.css defines matching CSS custom properties as a fallback default;
 * KCRM_Front::enqueue_assets() overrides them with the saved values (if any)
 * via an inline style, so the whole front end re-themes from one place.
 */
class KCRM_Colors {

	const OPTION = 'kcrm_colors';

	/** @return array<string,string> color key => default hex value, matching the :root fallback in front.css. */
	public static function defaults() {
		return array(
			'primary'   => '#1e3a5f',
			'secondary' => '#1f2937',
			'accent'    => '#b45309',
			'highlight' => '#fff6b3',
		);
	}

	/** @return array<string,string> color key => label, for the settings form. */
	public static function labels() {
		return array(
			'primary'   => __( 'Primary', 'karks-crm' ),
			'secondary' => __( 'Secondary', 'karks-crm' ),
			'accent'    => __( 'Accent', 'karks-crm' ),
			'highlight' => __( 'Highlight', 'karks-crm' ),
		);
	}

	/** @return array<string,string> color key => resolved hex value (saved, falling back to defaults()). */
	public static function get() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/** @return string A `:root { --kcrm-color-x: ...; }` block overriding front.css's defaults with the resolved colors. */
	public static function inline_css() {
		$vars = array();
		foreach ( self::get() as $key => $value ) {
			$vars[] = '--kcrm-color-' . $key . ': ' . $value . ';';
		}
		return ':root { ' . implode( ' ', $vars ) . ' }';
	}
}
