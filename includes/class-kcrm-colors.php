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

	/** Option name for the "Disable Plugin Styles" checkbox on the Appearance screen. */
	const DISABLE_STYLES_OPTION = 'kcrm_disable_styles';

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

	/**
	 * @return bool True if "Disable Plugin Styles" is checked on the
	 * Appearance screen -- KCRM_Front::enqueue_assets() skips front.css and
	 * the inline color variables when this is on, so a site can rely
	 * entirely on its theme/custom CSS instead. Dashicons and the plugin's
	 * JavaScript keep loading regardless; this only affects front.css's own
	 * layout/color styling.
	 */
	public static function styles_disabled() {
		return (bool) get_option( self::DISABLE_STYLES_OPTION, false );
	}

	/**
	 * @return string A `:root { --kcrm-color-x: ...; }` block overriding
	 * front.css's defaults with the resolved colors, plus computed
	 * WCAG 2.1 AA (4.5:1) pairings so a badly-chosen custom color can't
	 * make text unreadable:
	 * - `--kcrm-color-{primary,secondary,highlight}-text`: black or white,
	 *   whichever contrasts better, for text sitting ON TOP of that color
	 *   used as a background (e.g. the Primary button, the table heading).
	 * - `--kcrm-color-{primary,accent}-readable`: the color itself, darkened
	 *   just enough to hit 4.5:1 against white if it doesn't already, for
	 *   that color used AS TEXT on the page's white/light background (e.g.
	 *   the active nav tab, stat card numbers). Left unchanged if already
	 *   compliant, so this only kicks in for colors that would otherwise
	 *   fail.
	 */
	public static function inline_css() {
		$colors = self::get();
		$vars   = array();

		foreach ( $colors as $key => $value ) {
			$vars[] = '--kcrm-color-' . $key . ': ' . $value . ';';
		}

		$vars[] = '--kcrm-color-primary-text: ' . self::contrast_text( $colors['primary'] ) . ';';
		$vars[] = '--kcrm-color-secondary-text: ' . self::contrast_text( $colors['secondary'] ) . ';';
		$vars[] = '--kcrm-color-highlight-text: ' . self::contrast_text( $colors['highlight'] ) . ';';
		$vars[] = '--kcrm-color-primary-readable: ' . self::readable_foreground( $colors['primary'] ) . ';';
		$vars[] = '--kcrm-color-accent-readable: ' . self::readable_foreground( $colors['accent'] ) . ';';

		return ':root { ' . implode( ' ', $vars ) . ' }';
	}

	/** @return string '#000000' or '#ffffff', whichever contrasts better against $bg_hex. */
	public static function contrast_text( $bg_hex ) {
		return self::contrast_ratio( $bg_hex, '#000000' ) >= self::contrast_ratio( $bg_hex, '#ffffff' ) ? '#000000' : '#ffffff';
	}

	/**
	 * Darkens $hex (in HSL lightness) until it reaches at least $min_ratio
	 * contrast against $bg_hex, for a customizable color used as text on a
	 * fixed light background. Returns $hex unchanged if it already meets
	 * $min_ratio.
	 */
	public static function readable_foreground( $hex, $bg_hex = '#ffffff', $min_ratio = 4.5 ) {
		if ( self::contrast_ratio( $hex, $bg_hex ) >= $min_ratio ) {
			return $hex;
		}

		list( $r, $g, $b ) = self::hex_to_rgb( $hex );
		list( $h, $s, $l )  = self::rgb_to_hsl( $r, $g, $b );

		for ( $step = 1; $step <= 50; $step++ ) {
			$l_try                = max( 0, $l - ( $step * 0.02 ) );
			list( $tr, $tg, $tb ) = self::hsl_to_rgb( $h, $s, $l_try );
			$candidate             = self::rgb_to_hex( $tr, $tg, $tb );

			if ( self::contrast_ratio( $candidate, $bg_hex ) >= $min_ratio ) {
				return $candidate;
			}
			if ( 0.0 === $l_try ) {
				return $candidate; // Black; as dark as this color can get.
			}
		}

		return '#000000';
	}

	/** @return float WCAG 2.1 contrast ratio (1 to 21) between two hex colors. */
	public static function contrast_ratio( $hex_a, $hex_b ) {
		$l1 = self::relative_luminance( $hex_a );
		$l2 = self::relative_luminance( $hex_b );
		return ( max( $l1, $l2 ) + 0.05 ) / ( min( $l1, $l2 ) + 0.05 );
	}

	/** @return float WCAG 2.1 relative luminance (0 to 1) of a hex color. */
	private static function relative_luminance( $hex ) {
		list( $r, $g, $b ) = self::hex_to_rgb( $hex );
		$channels           = array_map(
			static function ( $c ) {
				$c /= 255;
				return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
			},
			array( $r, $g, $b )
		);
		return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
	}

	/** @return array [ $r, $g, $b ] (0-255 each), or black if $hex isn't a valid 3/6-digit hex color. */
	private static function hex_to_rgb( $hex ) {
		$hex = ltrim( (string) $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) || ! ctype_xdigit( $hex ) ) {
			return array( 0, 0, 0 );
		}
		return array( hexdec( substr( $hex, 0, 2 ) ), hexdec( substr( $hex, 2, 2 ) ), hexdec( substr( $hex, 4, 2 ) ) );
	}

	/** @return string 6-digit hex, each channel clamped to [0, 255]. */
	private static function rgb_to_hex( $r, $g, $b ) {
		return sprintf( '#%02x%02x%02x', max( 0, min( 255, (int) round( $r ) ) ), max( 0, min( 255, (int) round( $g ) ) ), max( 0, min( 255, (int) round( $b ) ) ) );
	}

	/** @return array [ $h, $s, $l ], each 0 to 1. */
	private static function rgb_to_hsl( $r, $g, $b ) {
		$r    /= 255;
		$g    /= 255;
		$b    /= 255;
		$max   = max( $r, $g, $b );
		$min   = min( $r, $g, $b );
		$l     = ( $max + $min ) / 2;

		if ( $max === $min ) {
			return array( 0.0, 0.0, $l );
		}

		$d = $max - $min;
		$s = $l > 0.5 ? $d / ( 2 - $max - $min ) : $d / ( $max + $min );

		if ( $max === $r ) {
			$h = ( $g - $b ) / $d + ( $g < $b ? 6 : 0 );
		} elseif ( $max === $g ) {
			$h = ( $b - $r ) / $d + 2;
		} else {
			$h = ( $r - $g ) / $d + 4;
		}
		$h /= 6;

		return array( $h, $s, $l );
	}

	/** @return array [ $r, $g, $b ] (0-255 each). */
	private static function hsl_to_rgb( $h, $s, $l ) {
		if ( 0.0 === $s ) {
			$v = $l * 255;
			return array( $v, $v, $v );
		}

		$hue_to_rgb = static function ( $p, $q, $t ) {
			if ( $t < 0 ) {
				$t += 1;
			}
			if ( $t > 1 ) {
				$t -= 1;
			}
			if ( $t < 1 / 6 ) {
				return $p + ( $q - $p ) * 6 * $t;
			}
			if ( $t < 1 / 2 ) {
				return $q;
			}
			if ( $t < 2 / 3 ) {
				return $p + ( $q - $p ) * ( 2 / 3 - $t ) * 6;
			}
			return $p;
		};

		$q = $l < 0.5 ? $l * ( 1 + $s ) : $l + $s - $l * $s;
		$p = 2 * $l - $q;

		return array(
			$hue_to_rgb( $p, $q, $h + 1 / 3 ) * 255,
			$hue_to_rgb( $p, $q, $h ) * 255,
			$hue_to_rgb( $p, $q, $h - 1 / 3 ) * 255,
		);
	}
}
