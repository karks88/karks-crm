<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-dashboard.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-companies.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-customers.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-services.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-invoices.php';
require_once KCRM_PLUGIN_DIR . 'includes/front/class-kcrm-front-reports.php';

/**
 * Front-end counterpart to KCRM_Plugin: a [karks_crm] shortcode, placed by
 * KCRM_Activator on an auto-created "CRM" page, that lets any logged-in
 * user with the KCRM_CAPABILITY capability manage companies/customers/
 * services/invoices without needing wp-admin access.
 */
class KCRM_Front {

	/** Rewrite endpoints registered under the CRM page, in nav order. */
	const ENDPOINTS = array( 'companies', 'customers', 'services', 'invoices', 'reports' );

	/** @var array<string,KCRM_Controller_Base> */
	private $screens = array();

	public function run() {
		$this->screens = array(
			'companies' => new KCRM_Front_Companies(),
			'customers' => new KCRM_Front_Customers(),
			'services'  => new KCRM_Front_Services(),
			'invoices'  => new KCRM_Front_Invoices(),
			'reports'   => new KCRM_Front_Reports(),
		);

		add_action( 'init', array( $this, 'register_endpoints' ) );
		add_filter( 'request', array( $this, 'sanitize_query_vars' ) );
		add_shortcode( 'karks_crm', array( $this, 'render_shortcode' ) );
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
			( new KCRM_Front_Dashboard() )->render();
		}
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Companies' own list/add/edit screens are deliberately left out of the
	 * nav -- the Dashboard already lists every company (with an "Add a
	 * Company" button) and links to each one's overview hub, which itself
	 * links to Edit Company. That "companies" endpoint still exists and is
	 * still reachable (add/edit/delete all redirect back to it), it's just
	 * not a top-level tab. A "Company Profile" tab is shown instead,
	 * linking straight to the *current* company's overview hub.
	 */
	private function render_nav( $current ) {
		$labels = array(
			''          => __( 'Dashboard', 'karks-crm' ),
			'customers' => __( 'Customers', 'karks-crm' ),
			'services'  => __( 'Services', 'karks-crm' ),
			'invoices'  => __( 'Invoices', 'karks-crm' ),
			'reports'   => __( 'Reports', 'karks-crm' ),
		);
		$icons = array(
			''          => 'dashboard',
			'customers' => 'groups',
			'services'  => 'hammer',
			'invoices'  => 'media-spreadsheet',
			'reports'   => 'chart-bar',
		);
		$current_company_id = KCRM_Context::get_current_company_id();
		?>
		<nav class="kcrm-front-nav">
			<a href="<?php echo esc_url( self::endpoint_url( '' ) ); ?>" class="<?php echo '' === $current ? 'is-active' : ''; ?>"><span class="dashicons dashicons-<?php echo esc_attr( $icons[''] ); ?>"></span> <?php echo esc_html( $labels[''] ); ?></a>
			<?php if ( $current_company_id ) : ?>
				<a href="<?php echo esc_url( self::endpoint_url( 'companies', array( 'view' => 'overview', 'id' => $current_company_id ) ) ); ?>" class="<?php echo 'companies' === $current ? 'is-active' : ''; ?>"><span class="dashicons dashicons-building"></span> <?php esc_html_e( 'Company Profile', 'karks-crm' ); ?></a>
			<?php endif; ?>
			<?php foreach ( $labels as $endpoint => $label ) : ?>
				<?php if ( '' === $endpoint ) { continue; } ?>
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
