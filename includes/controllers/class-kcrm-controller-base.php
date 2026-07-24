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
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already verified by the caller before this is used.
		if ( array_key_exists( $key, $_POST ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce already verified by the caller before this is used; $sanitize() unslashes/sanitizes internally.
			return $sanitize( $_POST[ $key ] );
		}
		return ( $existing && isset( $existing->$key ) ) ? $existing->$key : $default;
	}

	/**
	 * Reads a 1-based page number from $_GET[ $query_arg ], clamped to
	 * [1, max(1, $total_pages)]. Kept off WordPress's reserved 'page'/
	 * 'paged' query var names by convention (callers should use a
	 * kcrm_-prefixed name) so it can't collide with the public query
	 * vars KCRM_Front::sanitize_query_vars() already has to guard against.
	 */
	protected function current_page_number( $query_arg, $total_pages ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only pagination param, no state change.
		$page = isset( $_GET[ $query_arg ] ) ? absint( $_GET[ $query_arg ] ) : 1;
		$page = max( 1, $page );
		return min( $page, max( 1, $total_pages ) );
	}

	/** Renders Prev/Next + a page-count status for a paginated list, preserving the current URL's other query args. */
	protected function render_pagination( $current_page, $total_pages, $query_arg ) {
		if ( $total_pages <= 1 ) {
			return;
		}
		?>
		<div class="kcrm-pagination">
			<?php if ( $current_page > 1 ) : ?>
				<a class="kcrm-button" href="<?php echo esc_url( add_query_arg( $query_arg, $current_page - 1 ) ); ?>">&laquo; <?php esc_html_e( 'Previous', 'karks-crm' ); ?></a>
			<?php endif; ?>
			<span class="kcrm-pagination-status">
				<?php
				printf(
					/* translators: 1: current page number, 2: total number of pages. */
					esc_html__( 'Page %1$d of %2$d', 'karks-crm' ),
					(int) $current_page,
					(int) $total_pages
				);
				?>
			</span>
			<?php if ( $current_page < $total_pages ) : ?>
				<a class="kcrm-button" href="<?php echo esc_url( add_query_arg( $query_arg, $current_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'karks-crm' ); ?> &raquo;</a>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resolves a date-range filter from $_GET["{$prefix}_range"]
	 * (this_year/last_year/all/custom), plus $_GET["{$prefix}_from"/"_to"]
	 * for a custom range.
	 *
	 * @param string $default_range Used when the query var is absent -- pass 'all' for lists (like open invoices) where older records still matter by default, or 'this_year' for a historical log where recent is what you want to see first.
	 * @return array [ $range_key, $date_from|null, $date_to|null ] -- 'Y-m-d' strings, or null for no bound.
	 */
	protected function resolve_date_range( $prefix, $default_range = 'this_year' ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$range = isset( $_GET[ "{$prefix}_range" ] ) ? sanitize_key( wp_unslash( $_GET[ "{$prefix}_range" ] ) ) : $default_range;
		if ( ! in_array( $range, array( 'this_year', 'last_year', 'all', 'custom' ), true ) ) {
			$range = $default_range;
		}

		$this_year = (int) current_time( 'Y' );

		if ( 'last_year' === $range ) {
			return array( $range, ( $this_year - 1 ) . '-01-01', ( $this_year - 1 ) . '-12-31' );
		}

		if ( 'all' === $range ) {
			return array( $range, null, null );
		}

		if ( 'custom' === $range ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only display filter, no state change; sanitize_date_or_null() sanitizes internally.
			$from = isset( $_GET[ "{$prefix}_from" ] ) ? $this->sanitize_date_or_null( wp_unslash( $_GET[ "{$prefix}_from" ] ) ) : null;
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- read-only display filter, no state change; sanitize_date_or_null() sanitizes internally.
			$to = isset( $_GET[ "{$prefix}_to" ] ) ? $this->sanitize_date_or_null( wp_unslash( $_GET[ "{$prefix}_to" ] ) ) : null;
			return array( $range, $from, $to );
		}

		return array( 'this_year', $this_year . '-01-01', $this_year . '-12-31' );
	}

	/** @return string|null A 'Y-m-d' date, or null if $value isn't one. */
	protected function sanitize_date_or_null( $value ) {
		$value = sanitize_text_field( $value );
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : null;
	}

	/**
	 * Renders a This Year/Last Year/All/Custom Range filter form (GET, so
	 * it's bookmarkable/shareable), preserving every other query arg
	 * already on the URL as hidden fields so submitting it doesn't lose
	 * page context (e.g. view=edit&id=X).
	 */
	protected function render_date_range_filter( $prefix, $range, $from, $to ) {
		$labels = array(
			'this_year' => __( 'This Year', 'karks-crm' ),
			'last_year' => __( 'Last Year', 'karks-crm' ),
			'all'       => __( 'All', 'karks-crm' ),
			'custom'    => __( 'Custom Range', 'karks-crm' ),
		);

		$preserve = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, used only to rebuild hidden fields for this same filter form.
			$key = sanitize_key( $key );
			if ( in_array( $key, array( "{$prefix}_range", "{$prefix}_from", "{$prefix}_to" ), true ) ) {
				continue;
			}
			$preserve[ $key ] = sanitize_text_field( wp_unslash( $value ) );
		}
		?>
		<form method="get" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-date-range-filter">
			<?php foreach ( $preserve as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<label>
				<?php esc_html_e( 'Date Range:', 'karks-crm' ); ?>
				<select name="<?php echo esc_attr( "{$prefix}_range" ); ?>">
					<?php foreach ( $labels as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $range, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<span class="kcrm-date-range-custom" style="<?php echo 'custom' === $range ? '' : 'display:none;'; ?>">
				<label><?php esc_html_e( 'From', 'karks-crm' ); ?> <input type="date" name="<?php echo esc_attr( "{$prefix}_from" ); ?>" value="<?php echo esc_attr( $from ); ?>"></label>
				<label><?php esc_html_e( 'To', 'karks-crm' ); ?> <input type="date" name="<?php echo esc_attr( "{$prefix}_to" ); ?>" value="<?php echo esc_attr( $to ); ?>"></label>
			</span>
			<button type="submit" class="kcrm-button"><?php esc_html_e( 'Apply', 'karks-crm' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Resolves a multi-select status filter from $_GET["{$prefix}[]"],
	 * with an explicit "submitted" marker ($_GET["{$prefix}_filtered"]) so
	 * an unchecked checkbox (which browsers simply omit from the request)
	 * can be told apart from the filter never having been touched at all --
	 * the former means "show nothing selected", the latter means "show
	 * everything" (today's unfiltered default, before this filter existed).
	 *
	 * @param array $all_statuses status_key => label, e.g. KCRM_Invoice::statuses().
	 * @return array [ $selected_status_keys|null, $filtered ] -- null means no filtering (show all); $filtered is true once the form has been submitted at all.
	 */
	protected function resolve_status_filter( $prefix, array $all_statuses ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		if ( ! isset( $_GET[ "{$prefix}_filtered" ] ) ) {
			return array( null, false );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$raw      = isset( $_GET[ $prefix ] ) && is_array( $_GET[ $prefix ] ) ? wp_unslash( $_GET[ $prefix ] ) : array();
		$selected = array_values( array_intersect( array_keys( $all_statuses ), array_map( 'sanitize_key', $raw ) ) );

		return array( $selected, true );
	}

	/**
	 * Renders a checkbox-per-status filter form (GET, so it's
	 * bookmarkable/shareable) for resolve_status_filter(), preserving every
	 * other query arg already on the URL as hidden fields so submitting it
	 * doesn't lose page context (e.g. view=edit&id=X) or an active sort.
	 *
	 * @param array      $all_statuses status_key => label.
	 * @param array|null $selected Currently-selected status keys, or null when unfiltered (checks every box).
	 */
	protected function render_status_filter( $prefix, array $all_statuses, $selected ) {
		$checked_keys = null === $selected ? array_keys( $all_statuses ) : $selected;

		$preserve = array();
		foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, used only to rebuild hidden fields for this same filter form.
			$key = sanitize_key( $key );
			if ( in_array( $key, array( $prefix, "{$prefix}_filtered" ), true ) || is_array( $value ) ) {
				continue;
			}
			$preserve[ $key ] = sanitize_text_field( wp_unslash( $value ) );
		}
		?>
		<form method="get" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-status-filter">
			<?php foreach ( $preserve as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<input type="hidden" name="<?php echo esc_attr( "{$prefix}_filtered" ); ?>" value="1">
			<fieldset>
				<legend><?php esc_html_e( 'Statuses:', 'karks-crm' ); ?></legend>
				<?php foreach ( $all_statuses as $key => $label ) : ?>
					<label>
						<input type="checkbox" name="<?php echo esc_attr( $prefix ); ?>[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $checked_keys, true ) ); ?>>
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</fieldset>
			<button type="submit" class="kcrm-button"><?php esc_html_e( 'Apply', 'karks-crm' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Filters a customer list down to active-only unless
	 * $_GET['kcrm_status_filter'] = all. Centralizes the active/inactive
	 * filter shared by the Customers list and the company overview page,
	 * so the query var and behavior can't drift between them.
	 *
	 * @return array [ $filtered_customers, $show_all ]
	 */
	protected function filter_active_customers( array $customers ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display filter, no state change.
		$show_all = ! empty( $_GET['kcrm_status_filter'] ) && 'all' === sanitize_key( wp_unslash( $_GET['kcrm_status_filter'] ) );

		if ( $show_all ) {
			return array( $customers, true );
		}

		$active = array_values(
			array_filter(
				$customers,
				static function ( $customer ) {
					return KCRM_Customer::STATUS_ACTIVE === $customer->status;
				}
			)
		);

		return array( $active, false );
	}

	/**
	 * Renders the toggle link for filter_active_customers() -- the one
	 * place this wording lives, so "show everything" doesn't end up
	 * phrased differently screen to screen.
	 */
	protected function render_active_customers_toggle( $show_all ) {
		$toggle_url = $show_all ? remove_query_arg( 'kcrm_status_filter' ) : add_query_arg( 'kcrm_status_filter', 'all' );
		?>
		<p>
			<a href="<?php echo esc_url( $toggle_url ); ?>">
				<span class="dashicons dashicons-editor-ol"></span>
				<?php
				echo $show_all
					? esc_html__( 'Show active customers only', 'karks-crm' )
					: esc_html__( 'Show ALL customers (including inactive)', 'karks-crm' );
				?>
			</a>
		</p>
		<?php
	}

	/** @return string The empty-state message for a filter_active_customers() list, matching whether $show_all was in effect. */
	protected function no_customers_message( $show_all ) {
		return $show_all
			? __( 'No customers yet for this company.', 'karks-crm' )
			: __( 'No active customers found.', 'karks-crm' );
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
			'email_sent' => array( 'success', __( 'Invoice emailed successfully.', 'karks-crm' ) ),
			'email_error' => array( 'error', __( 'Could not send the email. Please check the recipient address and try again.', 'karks-crm' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $message ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	/**
	 * @param callable|null $destination_for_company Given a company row, returns the URL to land on after switching to it. Defaults to this screen's own base URL for every option -- pass this when the current view is itself tied to a specific company id (e.g. the company overview page), so picking a different company lands on *that* company's version of the same view instead of falling back to this screen's generic list.
	 */
	protected function company_switcher( $destination_for_company = null ) {
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
					<?php $destination = $destination_for_company ? $destination_for_company( $company ) : $base; ?>
					<option value="<?php echo esc_url( KCRM_Context::switch_company_url( $company->id, $destination ) ); ?>" <?php selected( (int) $company->id, $current ); ?>>
						<?php echo esc_html( $company->name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</div>
		<?php
	}

	/** Shows the current company's logo, if it has one. */
	protected function render_current_company_logo() {
		$company = KCRM_Company::find( $this->current_company_id() );
		if ( ! $company || empty( $company->logo_attachment_id ) ) {
			return;
		}
		echo '<div class="kcrm-current-company-logo">' . wp_get_attachment_image( $company->logo_attachment_id, 'medium' ) . '</div>';
	}

	/**
	 * Two-column header shown on every front-end screen except the
	 * Dashboard: the current company's logo on the left, the switcher on
	 * the right (stacks on narrow screens).
	 *
	 * @param callable|null $destination_for_company See company_switcher().
	 */
	protected function render_company_header( $destination_for_company = null ) {
		echo '<div class="kcrm-company-header">';
		$this->render_current_company_logo();
		$this->company_switcher( $destination_for_company );
		echo '</div>';
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
