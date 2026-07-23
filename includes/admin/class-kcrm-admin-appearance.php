<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin settings screen for the front end's 4-color scheme
 * (KCRM_Colors). Standalone rather than part of KCRM_Plugin's shared
 * $screens dispatch -- it has no company/customer/invoice business logic
 * or front-end counterpart to share via KCRM_Controller_Base, just a
 * form that saves an option.
 */
class KCRM_Admin_Appearance {

	const PAGE = 'karks-crm-appearance';

	public function handle_actions() {
		if ( ! is_admin() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route check; the real nonce check is in save() below.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_colors' === $_POST['kcrm_action'] ) {
			$this->save();
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_colors' );

		$colors = array();
		foreach ( KCRM_Colors::defaults() as $key => $default ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_hex_color() unslashes/validates internally, returning null for anything invalid.
			$submitted     = isset( $_POST[ "color_$key" ] ) ? sanitize_hex_color( wp_unslash( $_POST[ "color_$key" ] ) ) : null;
			$colors[ $key ] = $submitted ? $submitted : $default;
		}

		update_option( KCRM_Colors::OPTION, $colors );
		update_option( KCRM_Colors::DISABLE_STYLES_OPTION, isset( $_POST['disable_styles'] ) ? 1 : 0 );

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE, 'kcrm_notice' => 'saved' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		$colors          = KCRM_Colors::get();
		$labels          = KCRM_Colors::labels();
		$defaults        = KCRM_Colors::defaults();
		$styles_disabled = KCRM_Colors::styles_disabled();
		?>
		<div class="wrap kcrm-wrap">
			<h1><?php esc_html_e( 'Karks CRM Appearance', 'karks-crm' ); ?></h1>
			<?php $this->render_notice(); ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the Companies screen, with "custom accent color" as the link text. */
					esc_html__( 'Choose the colors used across the front-end CRM pages. Each company can also choose a %s in their profile.', 'karks-crm' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=karks-crm-companies' ) ) . '">' . esc_html__( 'custom accent color', 'karks-crm' ) . '</a>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( add_query_arg( 'page', self::PAGE, admin_url( 'admin.php' ) ) ); ?>">
				<?php wp_nonce_field( 'kcrm_save_colors' ); ?>
				<input type="hidden" name="kcrm_action" value="save_colors">
				<table class="form-table">
					<tr>
						<th><label for="color_primary"><?php echo esc_html( $labels['primary'] ); ?></label></th>
						<td>
							<input type="text" class="kcrm-color-picker" name="color_primary" id="color_primary" value="<?php echo esc_attr( $colors['primary'] ); ?>" data-default-color="<?php echo esc_attr( $defaults['primary'] ); ?>">
							<p class="description"><?php esc_html_e( 'Buttons, active nav tab, links, card hover.', 'karks-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="color_secondary"><?php echo esc_html( $labels['secondary'] ); ?></label></th>
						<td>
							<input type="text" class="kcrm-color-picker" name="color_secondary" id="color_secondary" value="<?php echo esc_attr( $colors['secondary'] ); ?>" data-default-color="<?php echo esc_attr( $defaults['secondary'] ); ?>">
							<p class="description"><?php esc_html_e( 'Table heading background.', 'karks-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="color_accent"><?php echo esc_html( $labels['accent'] ); ?></label></th>
						<td>
							<input type="text" class="kcrm-color-picker" name="color_accent" id="color_accent" value="<?php echo esc_attr( $colors['accent'] ); ?>" data-default-color="<?php echo esc_attr( $defaults['accent'] ); ?>">
							<p class="description"><?php esc_html_e( 'Dashboard/company stat numbers.', 'karks-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="color_highlight"><?php echo esc_html( $labels['highlight'] ); ?></label></th>
						<td>
							<input type="text" class="kcrm-color-picker" name="color_highlight" id="color_highlight" value="<?php echo esc_attr( $colors['highlight'] ); ?>" data-default-color="<?php echo esc_attr( $defaults['highlight'] ); ?>">
							<p class="description"><?php esc_html_e( 'Table row hover background.', 'karks-crm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Plugin Styles', 'karks-crm' ); ?></th>
						<td>
							<label><input type="checkbox" name="disable_styles" id="kcrm-disable-styles" value="1" <?php checked( $styles_disabled ); ?>> <?php esc_html_e( 'Disable plugin styles on the front end', 'karks-crm' ); ?></label>
							<p class="description"><?php esc_html_e( "Stops the front-end CRM pages from loading this plugin's own stylesheet and the colors above, so you can rely entirely on your theme or custom CSS instead. Dashicons and the plugin's JavaScript (media picker, line-item editor, etc.) keep working either way.", 'karks-crm' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save Colors', 'karks-crm' ) ); ?>
			</form>
		</div>
		<script>
		jQuery(function ($) {
			$('.kcrm-color-picker').wpColorPicker();
		});
		</script>
		<?php
	}

	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrm_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( 'saved' === sanitize_key( wp_unslash( $_GET['kcrm_notice'] ) ) ) {
			printf( '<div class="notice notice-success is-dismissible"><p>%s</p></div>', esc_html__( 'Colors saved.', 'karks-crm' ) );
		}
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, self::PAGE ) === false ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
	}
}
