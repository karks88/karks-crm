<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The activity log view shared between wp-admin (KCRM_Admin_Activity) and
 * the front end (KCRM_Front_Activity): the current company's KCRM_Activity
 * history, filterable by type / customer / date and grouped by day.
 * Settings (on/off, retention, clearing the log) live only on the wp-admin
 * screen.
 */
abstract class KCRM_Activity_Controller extends KCRM_Controller_Base {

	/** wp-admin menu slug, used by KCRM_Admin_Screen_Trait. */
	const PAGE = 'karks-crm-activity';

	/** Front-end rewrite endpoint, used by KCRM_Front_Screen_Trait. */
	const ENDPOINT = 'activity';

	const PER_PAGE = 50;

	/** Query args, all kcrm_-prefixed so none collide with a WordPress public query var. */
	const TYPE_ARG     = 'kcrm_type';
	const CUSTOMER_ARG = 'kcrm_customer';
	const PAGE_ARG     = 'kcrm_activity_page';
	const RANGE_PREFIX = 'kcrm_activity';

	/**
	 * Filters + the day-grouped, paginated list for the current company.
	 *
	 * @param string $context 'front' or 'admin' -- decides where entry links point (see KCRM_Activity_Feed::describe_rows()).
	 */
	protected function render_log( $context ) {
		$company_id = $this->current_company_id();
		$type       = $this->current_type();
		$customers  = $this->customer_options( $company_id );
		$customer   = $this->current_customer_id( $customers );

		list( $range, $from, $to ) = $this->resolve_date_range( self::RANGE_PREFIX, 'this_year' );

		$filters = array(
			'from' => $from,
			'to'   => $to,
		);
		if ( $type ) {
			$filters['events'] = KCRM_Activity::event_groups()[ $type ];
		}
		if ( $customer ) {
			$filters['customer_ids'] = array_merge( array( $customer ), wp_list_pluck( KCRM_Customer::jobs_for( $customer ), 'id' ) );
		}

		$this->render_type_chips( $type );
		echo '<div class="kcrm-activity-filters">';
		$this->render_customer_filter( $customers, $customer );
		$this->render_date_range_filter( self::RANGE_PREFIX, $range, $from, $to );
		echo '</div>';

		$total       = KCRM_Activity::count_query( $company_id, $filters );
		$total_pages = (int) ceil( $total / self::PER_PAGE );
		$page        = $this->current_page_number( self::PAGE_ARG, $total_pages );
		$rows        = KCRM_Activity::query( $company_id, $filters, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE );

		if ( empty( $rows ) ) {
			$nothing_logged = 0 === KCRM_Activity::count_query( $company_id );
			echo '<p class="kcrm-activity-empty">' . esc_html(
				$nothing_logged
					? __( 'No activity recorded yet. Actions taken from now on will appear here.', 'karks-crm' )
					: __( 'No activity matches these filters.', 'karks-crm' )
			) . '</p>';
			return;
		}

		KCRM_Activity_Feed::render_grouped_by_day( KCRM_Activity_Feed::describe_rows( $rows, $context ) );
		$this->render_pagination( $page, $total_pages, self::PAGE_ARG );
	}

	/** "Activity is kept for 24 months, then removed automatically." */
	protected function retention_note() {
		$months = KCRM_Activity::retention_months();
		return $months
			/* translators: %s: retention period, e.g. "24 months". */
			? sprintf( __( 'Activity is kept for %s, then removed automatically.', 'karks-crm' ), KCRM_Activity_Feed::retention_label( $months ) )
			: __( 'Activity is kept forever.', 'karks-crm' );
	}

	/** @return string A key of KCRM_Activity::event_groups(), or '' for all types. */
	private function current_type() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fail-open nav_nonce_valid() check on the same line stands in for this; read-only display filter.
		$type = ( isset( $_GET[ self::TYPE_ARG ] ) && $this->nav_nonce_valid() ) ? sanitize_key( wp_unslash( $_GET[ self::TYPE_ARG ] ) ) : '';
		return isset( KCRM_Activity::event_groups()[ $type ] ) ? $type : '';
	}

	/**
	 * Top-level customers in this company, for the customer filter -- Jobs
	 * aren't listed separately since picking a customer already includes
	 * its Jobs (same rollup as the customer profile).
	 *
	 * @return array<int,string> id => name, sorted by name.
	 */
	private function customer_options( $company_id ) {
		$options = array();
		foreach ( KCRM_Customer::for_company( $company_id ) as $customer ) {
			if ( empty( $customer->parent_customer_id ) ) {
				$options[ (int) $customer->id ] = $customer->company_name;
			}
		}
		natcasesort( $options );
		return $options;
	}

	/**
	 * The customer filter, accepted only if it's one of this company's own
	 * top-level customers -- anything else (another company's id, a Job id,
	 * garbage) is ignored rather than trusted.
	 *
	 * @param array<int,string> $customers From customer_options().
	 */
	private function current_customer_id( array $customers ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- fail-open nav_nonce_valid() check on the same line stands in for this; read-only display filter.
		$id = ( isset( $_GET[ self::CUSTOMER_ARG ] ) && $this->nav_nonce_valid() ) ? absint( $_GET[ self::CUSTOMER_ARG ] ) : 0;
		return isset( $customers[ $id ] ) ? $id : 0;
	}

	/** The current filter query args minus $drop (pagination is never carried), for building filter links that keep the other active filters. */
	private function current_filter_args( array $drop = array() ) {
		$keep = array( self::TYPE_ARG, self::CUSTOMER_ARG, self::RANGE_PREFIX . '_range', self::RANGE_PREFIX . '_from', self::RANGE_PREFIX . '_to' );
		$args = array();
		if ( ! $this->nav_nonce_valid() ) {
			return $args;
		}
		foreach ( array_diff( $keep, $drop ) as $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nav_nonce_valid() above is the check; read-only display filters.
			if ( isset( $_GET[ $key ] ) && ! is_array( $_GET[ $key ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nav_nonce_valid() above is the check; read-only display filters.
				$args[ $key ] = sanitize_text_field( wp_unslash( $_GET[ $key ] ) );
			}
		}
		return $args;
	}

	private function render_type_chips( $current ) {
		$labels = array(
			''          => __( 'All', 'karks-crm' ),
			'invoices'  => __( 'Invoices', 'karks-crm' ),
			'payments'  => __( 'Payments', 'karks-crm' ),
			'emails'    => __( 'Emails', 'karks-crm' ),
			'customers' => __( 'Customers', 'karks-crm' ),
			'services'  => __( 'Services', 'karks-crm' ),
			'company'   => __( 'Company', 'karks-crm' ),
		);
		$base   = $this->current_filter_args( array( self::TYPE_ARG ) );
		?>
		<nav class="kcrm-activity-chips" aria-label="<?php esc_attr_e( 'Filter by type', 'karks-crm' ); ?>">
			<?php foreach ( $labels as $key => $label ) : ?>
				<?php $args = '' === $key ? $base : array_merge( $base, array( self::TYPE_ARG => $key ) ); ?>
				<a class="kcrm-activity-chip<?php echo $key === $current ? ' is-active' : ''; ?>" href="<?php echo esc_url( $this->screen_url( $this->nav_nonce_args( $args ) ) ); ?>"<?php echo $key === $current ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	/** @param array<int,string> $customers From customer_options(). */
	private function render_customer_filter( array $customers, $current ) {
		if ( empty( $customers ) ) {
			return;
		}
		// Rebuilt as hidden fields (rather than relying on the form's action URL) since a GET form replaces the action URL's own query string -- this also carries wp-admin's required page= arg.
		$hidden = array_merge( $this->screen_query_args(), $this->current_filter_args( array( self::CUSTOMER_ARG ) ), $this->nav_nonce_args() );
		?>
		<form method="get" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-activity-customer-filter">
			<?php foreach ( $hidden as $key => $value ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $value ); ?>">
			<?php endforeach; ?>
			<label>
				<?php esc_html_e( 'Customer:', 'karks-crm' ); ?>
				<select name="<?php echo esc_attr( self::CUSTOMER_ARG ); ?>" onchange="this.form.submit();">
					<option value=""><?php esc_html_e( 'All customers', 'karks-crm' ); ?></option>
					<?php foreach ( $customers as $id => $name ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $current, $id ); ?>><?php echo esc_html( $name ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<noscript><button type="submit" class="kcrm-button button"><?php esc_html_e( 'Apply', 'karks-crm' ); ?></button></noscript>
		</form>
		<?php
	}

	/** Query args screen_url() itself depends on (wp-admin's page=), which a GET form submission would otherwise drop. */
	private function screen_query_args() {
		$args = array();
		wp_parse_str( (string) wp_parse_url( $this->screen_url(), PHP_URL_QUERY ), $args );
		return $args;
	}
}
