<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class KCRM_Admin_Base {

	/** Render the admin page body (called as the add_submenu_page callback). */
	abstract public function render();

	/**
	 * Process any POST/GET actions for this screen. Runs on admin_init,
	 * i.e. before headers are sent, so it's safe to redirect from here.
	 */
	public function handle_actions() {}

	protected function redirect( $args = array(), $page = null ) {
		$args['page'] = $page ? $page : $args['page'];
		$url          = add_query_arg( $args, admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}

	protected function current_company_id() {
		return KCRM_Context::get_current_company_id();
	}

	protected function render_notice_from_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrm_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['kcrm_notice'] ) );

		$messages = array(
			'saved'     => array( 'success', __( 'Saved successfully.', 'karks-crm' ) ),
			'deleted'   => array( 'success', __( 'Deleted successfully.', 'karks-crm' ) ),
			'error'     => array( 'error', __( 'Something went wrong. Please try again.', 'karks-crm' ) ),
			'no_company' => array( 'error', __( 'Please create a company first.', 'karks-crm' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $message ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	protected function company_switcher( $page_slug ) {
		$companies = KCRM_Company::all_ordered();
		if ( empty( $companies ) ) {
			return;
		}
		$current = $this->current_company_id();
		$base    = admin_url( 'admin.php?page=' . $page_slug );
		?>
		<div class="kcrm-company-switcher">
			<label for="kcrm-company-select"><?php esc_html_e( 'Company:', 'karks-crm' ); ?></label>
			<select id="kcrm-company-select" onchange="if (this.value) { window.location.href = this.value; }">
				<?php foreach ( $companies as $company ) : ?>
					<option value="<?php echo esc_url( KCRM_Context::switch_company_url( $company->id, $base ) ); ?>" <?php selected( (int) $company->id, $current ); ?>>
						<?php echo esc_html( $company->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}
}
