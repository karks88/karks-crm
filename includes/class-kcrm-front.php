<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-companies.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-customers.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-services.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-invoices.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-reports.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-tools.php';

/**
 * Front-end counterpart to KCRM_Plugin: a [karks_crm] shortcode, placed by
 * KCRM_Activator on an auto-created "CRM" page, that lets any logged-in
 * user with the KCRM_CAPABILITY capability manage companies/customers/
 * services/invoices without needing wp-admin access.
 */
class KCRM_Front {

	/** Rewrite endpoints registered under the CRM page, in nav order. */
	const ENDPOINTS = array( 'companies', 'customers', 'services', 'invoices', 'reports', 'tools' );

	/** @var array<string,KCRM_Controller_Base> */
	private $screens = array();

	public function run() {
		$this->screens = array(
			'companies' => new KCRM_Front_Companies(),
			'customers' => new KCRM_Front_Customers(),
			'services'  => new KCRM_Front_Services(),
			'invoices'  => new KCRM_Front_Invoices(),
			'reports'   => new KCRM_Front_Reports(),
			'tools'     => new KCRM_Front_Tools(),
		);

		add_action( 'init', array( $this, 'register_endpoints' ) );
		add_filter( 'request', array( $this, 'sanitize_query_vars' ) );
		add_filter( 'redirect_canonical', array( $this, 'prevent_front_page_endpoint_redirect' ) );
		add_shortcode( 'karks_crm', array( $this, 'render_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'redirect_bare_endpoint' ) );
		add_action( 'template_redirect', array( $this, 'handle_screen_actions' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_kcrm_export_report_csv', array( $this->screens['reports'], 'handle_csv_export' ) );
	}

	/**
	 * WordPress merges $_GET/$_POST into the main query for any key that
	 * matches a registered public query var (e.g. "name", "order",
	 * "orderby") -- which corrupts the main query, and 404s the CRM page,
	 * the moment one of our own form fields or list-sort links happens to
	 * reuse one of those names. Once a request has matched one of our
	 * rewrite endpoints, keep only the query vars that actually came from
	 * the URL match (pagename/page_id + the matched endpoint) and drop
	 * everything else WordPress folded in from request data.
	 */
	public function sanitize_query_vars( $query_vars ) {
		$matched_endpoint = null;
		foreach ( self::ENDPOINTS as $endpoint ) {
			if ( array_key_exists( $endpoint, $query_vars ) ) {
				$matched_endpoint = $endpoint;
				break;
			}
		}

		if ( null === $matched_endpoint ) {
			return $query_vars;
		}

		return array_intersect_key( $query_vars, array_flip( array( 'pagename', 'page_id', $matched_endpoint ) ) );
	}

	public function register_endpoints() {
		foreach ( self::ENDPOINTS as $endpoint ) {
			add_rewrite_endpoint( $endpoint, EP_PAGES );
		}
		self::maybe_add_front_page_rewrite_rules();
	}

	/**
	 * WordPress generates the EP_PAGES rules above per-page from each page's
	 * own slug (e.g. crm/customers/), which stops covering our endpoints the
	 * moment the CRM page is set as the site's static homepage: the front
	 * page's own rewrite rule is just `^$`, with no equivalent for a bare
	 * `/customers/`, `/invoices/`, etc. at the site root -- those requests
	 * 404 (and the CRM page's own slug URL, e.g. /crm/customers/, redirects
	 * to the home URL instead, since WordPress treats it as a non-canonical
	 * alias for the front page and drops the extra path segment). Add
	 * explicit rules mapping the bare endpoint path straight to the CRM page
	 * whenever it's the configured homepage, so links keep working either
	 * way. KCRM_Activator::maybe_flush_rewrite_rules() confirms these are
	 * actually persisted, so toggling the homepage setting self-heals on
	 * the next request instead of 404ing until Permalinks is re-saved.
	 */
	public static function maybe_add_front_page_rewrite_rules() {
		$page_id = self::page_id();
		if ( ! $page_id || 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) !== $page_id ) {
			return;
		}

		foreach ( self::ENDPOINTS as $endpoint ) {
			add_rewrite_rule( '^' . $endpoint . '/?$', 'index.php?page_id=' . $page_id . '&' . $endpoint . '=1', 'top' );
		}
	}

	/** @return int The CRM page ID, or 0 if it hasn't been created yet. */
	public static function page_id() {
		return (int) get_option( 'kcrm_front_page_id' );
	}

	public static function is_crm_page() {
		$page_id = self::page_id();
		return $page_id && is_page( $page_id );
	}

	/**
	 * Builds a URL to an endpoint under the CRM page. Mirrors
	 * WooCommerce's wc_get_endpoint_url(): a path segment under pretty
	 * permalinks, a query var otherwise.
	 */
	public static function endpoint_url( $endpoint, array $args = array() ) {
		$page_id = self::page_id();
		$base    = $page_id ? get_permalink( $page_id ) : home_url( '/' );

		if ( $endpoint ) {
			$base = get_option( 'permalink_structure' )
				? trailingslashit( trailingslashit( $base ) . $endpoint )
				: add_query_arg( $endpoint, '1', $base );
		}

		return $args ? add_query_arg( $args, $base ) : $base;
	}

	/** Which registered endpoint matches the current request, or '' for the bare CRM page. */
	private function current_endpoint() {
		global $wp_query;
		foreach ( self::ENDPOINTS as $endpoint ) {
			if ( isset( $wp_query->query_vars[ $endpoint ] ) ) {
				return $endpoint;
			}
		}
		return '';
	}

	/**
	 * When the CRM page is the site's homepage, WordPress's own
	 * redirect_canonical() treats any request for it other than the bare
	 * "/" as a non-canonical alias and 301s straight back to the home URL --
	 * which would otherwise undo maybe_add_front_page_rewrite_rules()'s
	 * whole point by stripping the endpoint on every request before this
	 * plugin ever gets to render it. Leave the redirect alone for anything
	 * that isn't one of our own endpoints.
	 */
	public function prevent_front_page_endpoint_redirect( $redirect_url ) {
		return $this->current_endpoint() ? false : $redirect_url;
	}

	/**
	 * The bare CRM page (no endpoint matched) used to render a multi-company
	 * Dashboard; that's now the Tools tab instead, so bare requests jump
	 * straight to the current company's Profile -- or to Tools if there's no
	 * current company yet (nothing to show a Profile for), so "add your
	 * first company" is still reachable.
	 */
	public function redirect_bare_endpoint() {
		if ( ! self::is_crm_page() || ! is_user_logged_in() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		if ( '' !== $this->current_endpoint() ) {
			return;
		}

		$company_id  = KCRM_Context::get_current_company_id();
		$destination = $company_id
			? self::endpoint_url( 'companies', array( 'view' => 'overview', 'id' => $company_id ) )
			: self::endpoint_url( 'tools' );

		wp_safe_redirect( $destination );
		exit;
	}

	public function render_shortcode() {
		if ( ! is_user_logged_in() ) {
			ob_start();
			?>
			<div class="kcrm-front kcrm-front-login">
				<?php wp_login_form( array( 'redirect' => get_permalink() ) ); ?>
			</div>
			<?php
			return ob_get_clean();
		}

		if ( ! current_user_can( KCRM_CAPABILITY ) ) {
			return '<div class="kcrm-front"><p>' . esc_html__( 'You do not have permission to access the CRM.', 'karks-crm' ) . '</p></div>';
		}

		$endpoint = $this->current_endpoint();
		$screen   = isset( $this->screens[ $endpoint ] ) ? $this->screens[ $endpoint ] : null;

		ob_start();
		echo '<div class="kcrm-front">';
		$this->render_nav( $endpoint );
		if ( $screen ) {
			$screen->render();
		} else {
			// Bare endpoint -- redirect_bare_endpoint() handles this on template_redirect for real requests; this is just a safety net (e.g. a shortcode preview) so the page isn't left blank.
			$this->screens['tools']->render();
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Companies' own list/add/edit screens are deliberately left out of the
	 * nav -- a "Company Profile" tab is shown instead, linking straight to
	 * the *current* company's overview hub (that "companies" endpoint still
	 * exists and is still reachable; add/edit/delete all redirect back to
	 * it, it's just not a top-level tab). "Add a Company" and the
	 * all-companies-at-a-glance table live under the Tools tab instead,
	 * which is why it's placed last -- it's a utility, not a primary
	 * destination the way Company Profile is (that's the bare endpoint's
	 * redirect target too, see redirect_bare_endpoint()).
	 */
	private function render_nav( $current ) {
		$labels = array(
			'customers' => __( 'Customers', 'karks-crm' ),
			'services'  => __( 'Services', 'karks-crm' ),
			'invoices'  => __( 'Invoices', 'karks-crm' ),
			'reports'   => __( 'Reports', 'karks-crm' ),
			'tools'     => __( 'Tools', 'karks-crm' ),
		);
		$icons = array(
			'customers' => 'groups',
			'services'  => 'hammer',
			'invoices'  => 'media-spreadsheet',
			'reports'   => 'chart-bar',
			'tools'     => 'admin-tools',
		);
		$current_company_id = KCRM_Context::get_current_company_id();
		?>
		<nav class="kcrm-front-nav">
			<?php if ( $current_company_id ) : ?>
				<a href="<?php echo esc_url( self::endpoint_url( 'companies', array( 'view' => 'overview', 'id' => $current_company_id ) ) ); ?>" class="<?php echo 'companies' === $current ? 'is-active' : ''; ?>"><span class="dashicons dashicons-building"></span> <?php esc_html_e( 'Company Profile', 'karks-crm' ); ?></a>
			<?php endif; ?>
			<?php foreach ( $labels as $endpoint => $label ) : ?>
				<a href="<?php echo esc_url( self::endpoint_url( $endpoint ) ); ?>" class="<?php echo $current === $endpoint ? 'is-active' : ''; ?>"><span class="dashicons dashicons-<?php echo esc_attr( $icons[ $endpoint ] ); ?>"></span> <?php echo esc_html( $label ); ?></a>
			<?php endforeach; ?>
		</nav>
		<?php
	}

	public function handle_screen_actions() {
		if ( ! self::is_crm_page() || ! is_user_logged_in() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// Only the screen matching the current endpoint should process
		// actions -- several screens share generic query args (action=
		// delete&id=), and check_admin_referer() wp_die()s on a mismatched
		// nonce, so calling every screen's handle_actions() unconditionally
		// would let the wrong screen's delete() 403 the real request.
		$endpoint = $this->current_endpoint();
		if ( isset( $this->screens[ $endpoint ] ) ) {
			$this->screens[ $endpoint ]->handle_actions();
		}
	}

	public function enqueue_assets() {
		if ( ! self::is_crm_page() ) {
			return;
		}
		wp_enqueue_style( 'dashicons' );
		if ( ! KCRM_Colors::styles_disabled() ) {
			wp_enqueue_style( 'kcrm-front', KCRM_PLUGIN_URL . 'assets/css/front.css', array( 'dashicons' ), KCRM_VERSION );
			wp_add_inline_style( 'kcrm-front', KCRM_Colors::inline_css() );
		}
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'kcrm-admin', KCRM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), KCRM_VERSION, true );
	}
}
