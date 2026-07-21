<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared behavior for every Karks CRM screen controller (companies,
 * customers, services, invoices), regardless of whether it ends up
 * rendered in wp-admin or on the front end. Concrete screen controllers
 * provide the business logic (save/delete/import) and declare a PAGE
 * (admin menu slug) and ENDPOINT (front-end rewrite endpoint) constant;
 * KCRM_Admin_Screen_Trait / KCRM_Front_Screen_Trait supply screen_url()
 * for each context, and each context's own subclass supplies render().
 */
abstract class KCRM_Controller_Base {

	/** Builds a URL to this screen, merging $args onto the base list URL. */
	abstract public function screen_url( array $args = array() );

	/** Render the screen body. */
	abstract public function render();

	/** Process any POST/GET actions for this screen (called before output is sent). */
	public function handle_actions() {}

	protected function redirect( array $args = array() ) {
		wp_safe_redirect( $this->screen_url( $args ) );
		exit;
	}

	protected function current_company_id() {
		return KCRM_Context::get_current_company_id();
	}

	/**
	 * Reads $_POST[$key] through $sanitize when the key is present at all;
	 * if it's missing entirely (as opposed to submitted-but-blank), falls
	 * back to $existing's current value instead of a hardcoded default.
	 * Real edit forms always resubmit every field, so this only kicks in
	 * for a partial/malformed request -- guarding against it silently
	 * wiping the rest of the record's data instead of failing loudly.
	 *
	 * Not appropriate for checkbox groups or repeater arrays, where "key
	 * entirely absent" is itself a legitimate submitted state (nothing
	 * checked / all rows removed) -- those still need their own explicit
	 * handling.
	 */
	protected function field_or_existing( $key, callable $sanitize, $existing, $default = '' ) {
		if ( array_key_exists( $key, $_POST ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified by the caller before this is used; $sanitize() unslashes/sanitizes internally.
			return $sanitize( $_POST[ $key ] );
		}
		return ( $existing && isset( $existing->$key ) ) ? $existing->$key : $default;
	}

	protected function render_notice_from_query() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrm_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['kcrm_notice'] ) );

		$messages = array(
			'saved'      => array( 'success', __( 'Saved successfully.', 'karks-crm' ) ),
			'deleted'    => array( 'success', __( 'Deleted successfully.', 'karks-crm' ) ),
			'error'      => array( 'error', __( 'Something went wrong. Please try again.', 'karks-crm' ) ),
			'no_company' => array( 'error', __( 'Please create a company first.', 'karks-crm' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $message ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	protected function company_switcher() {
		$companies = KCRM_Company::all_ordered();
		if ( empty( $companies ) ) {
			return;
		}
		$current = $this->current_company_id();
		$base    = $this->screen_url();
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

/** screen_url() for wp-admin screens: admin.php?page=<PAGE>&... (today's behavior, unchanged). */
trait KCRM_Admin_Screen_Trait {
	public function screen_url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => static::PAGE ), $args ), admin_url( 'admin.php' ) );
	}
}

/** screen_url() for front-end screens: the <ENDPOINT> rewrite endpoint under the CRM page. */
trait KCRM_Front_Screen_Trait {
	public function screen_url( array $args = array() ) {
		return add_query_arg( $args, KCRM_Front::endpoint_url( static::ENDPOINT ) );
	}
}
