<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-companies.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-customers.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-services.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-invoices.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-dashboard.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-appearance.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-welcome.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-invoice-types.php';
require_once KCRM_PLUGIN_DIR . 'includes/pdf/class-kcrm-pdf.php';

class KCRM_Plugin {

	/** @var array<string,KCRM_Controller_Base> */
	private $screens = array();

	/** @var KCRM_Admin_Appearance Standalone settings screen, not part of $screens (see its class docblock). */
	private $appearance;

	/** @var KCRM_Admin_Welcome Standalone informational screen, not part of $screens (nothing to save, no handle_actions()). */
	private $welcome;

	/** @var KCRM_Admin_Invoice_Types Standalone settings screen, not part of $screens (global, not company-scoped -- see its class docblock). */
	private $invoice_types;

	public function run() {
		// Deferred to init (priority 20, after KCRM_Front registers its rewrite
		// endpoints at the default priority) since maybe_upgrade() may call
		// wp_insert_post()/flush_rewrite_rules(), neither of which is safe to
		// call this early (plugins_loaded runs before $wp_rewrite exists).
		add_action( 'init', array( 'KCRM_Activator', 'maybe_upgrade' ), 20 );

		$this->screens = array(
			'dashboard' => new KCRM_Admin_Dashboard(),
			'companies' => new KCRM_Admin_Companies(),
			'customers' => new KCRM_Admin_Customers(),
			'services'  => new KCRM_Admin_Services(),
			'invoices'  => new KCRM_Admin_Invoices(),
		);
		$this->appearance    = new KCRM_Admin_Appearance();
		$this->welcome       = new KCRM_Admin_Welcome();
		$this->invoice_types = new KCRM_Admin_Invoice_Types();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_screen_actions' ) );
		add_action( 'admin_init', array( $this->appearance, 'handle_actions' ) );
		add_action( 'admin_init', array( $this->invoice_types, 'handle_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this->appearance, 'enqueue_assets' ) );
		add_action( 'admin_post_kcrm_download_invoice_pdf', array( $this->screens['invoices'], 'handle_pdf_download' ) );
		add_action( 'admin_post_kcrm_preview_invoice_html', array( $this->screens['invoices'], 'handle_html_preview' ) );
		add_action( 'admin_post_kcrm_export_company', array( $this->screens['companies'], 'handle_export_download' ) );
		add_action( 'admin_post_kcrm_export_customer_open_balance_pdf', array( $this->screens['customers'], 'handle_open_balance_pdf' ) );
		add_action( 'admin_post_kcrm_export_customer_open_balance_csv', array( $this->screens['customers'], 'handle_open_balance_csv' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Karks CRM', 'karks-crm' ),
			__( 'Karks CRM', 'karks-crm' ),
			KCRM_CAPABILITY,
			'karks-crm',
			array( $this->screens['dashboard'], 'render' ),
			'dashicons-groups',
			26
		);

		add_submenu_page( 'karks-crm', __( 'Dashboard', 'karks-crm' ), __( 'Dashboard', 'karks-crm' ), KCRM_CAPABILITY, 'karks-crm', array( $this->screens['dashboard'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Getting Started', 'karks-crm' ), __( 'Getting Started', 'karks-crm' ), KCRM_CAPABILITY, KCRM_Admin_Welcome::PAGE, array( $this->welcome, 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Companies', 'karks-crm' ), __( 'Companies', 'karks-crm' ), KCRM_CAPABILITY, 'karks-crm-companies', array( $this->screens['companies'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Customers', 'karks-crm' ), __( 'Customers', 'karks-crm' ), KCRM_CAPABILITY, 'karks-crm-customers', array( $this->screens['customers'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Services', 'karks-crm' ), __( 'Services', 'karks-crm' ), KCRM_CAPABILITY, 'karks-crm-services', array( $this->screens['services'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Invoices', 'karks-crm' ), __( 'Invoices', 'karks-crm' ), KCRM_CAPABILITY, 'karks-crm-invoices', array( $this->screens['invoices'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Invoice Types', 'karks-crm' ), __( 'Invoice Types', 'karks-crm' ), KCRM_CAPABILITY, KCRM_Admin_Invoice_Types::PAGE, array( $this->invoice_types, 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Appearance', 'karks-crm' ), __( 'Appearance', 'karks-crm' ), KCRM_CAPABILITY, KCRM_Admin_Appearance::PAGE, array( $this->appearance, 'render' ) );
	}

	/**
	 * The screen matching the current admin page processes its own form
	 * submissions before any HTML is sent. Only that one screen is called --
	 * several screens share generic query args (action=delete&id=), and
	 * check_admin_referer() wp_die()s on a mismatched nonce, so calling
	 * every screen's handle_actions() unconditionally would let the wrong
	 * screen's delete() 403 the real request.
	 */
	public function handle_screen_actions() {
		if ( ! is_admin() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route dispatch; real nonce checks happen in the handler methods below.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		foreach ( $this->screens as $screen ) {
			if ( $page === $screen::PAGE ) {
				$screen->handle_actions();
				return;
			}
		}
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, 'karks-crm' ) === false ) {
			return;
		}
		wp_enqueue_style( 'kcrm-admin', KCRM_PLUGIN_URL . 'assets/css/admin.css', array(), KCRM_VERSION );
		wp_enqueue_media();
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_enqueue_script( 'kcrm-admin', KCRM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), KCRM_VERSION, true );
	}
}
