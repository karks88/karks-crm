<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-base.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-companies.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-customers.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-services.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-invoices.php';
require_once KCRM_PLUGIN_DIR . 'includes/admin/class-kcrm-admin-dashboard.php';
require_once KCRM_PLUGIN_DIR . 'includes/pdf/class-kcrm-pdf.php';

class KCRM_Plugin {

	/** @var array<string,KCRM_Admin_Base> */
	private $screens = array();

	public function run() {
		KCRM_Activator::maybe_upgrade();

		$this->screens = array(
			'dashboard' => new KCRM_Admin_Dashboard(),
			'companies' => new KCRM_Admin_Companies(),
			'customers' => new KCRM_Admin_Customers(),
			'services'  => new KCRM_Admin_Services(),
			'invoices'  => new KCRM_Admin_Invoices(),
		);

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_init', array( $this, 'handle_screen_actions' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_kcrm_download_invoice_pdf', array( $this->screens['invoices'], 'handle_pdf_download' ) );
	}

	public function register_menu() {
		add_menu_page(
			__( 'Karks CRM', 'karks-crm' ),
			__( 'Karks CRM', 'karks-crm' ),
			'manage_options',
			'karks-crm',
			array( $this->screens['dashboard'], 'render' ),
			'dashicons-groups',
			26
		);

		add_submenu_page( 'karks-crm', __( 'Dashboard', 'karks-crm' ), __( 'Dashboard', 'karks-crm' ), 'manage_options', 'karks-crm', array( $this->screens['dashboard'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Customers', 'karks-crm' ), __( 'Customers', 'karks-crm' ), 'manage_options', 'karks-crm-customers', array( $this->screens['customers'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Services', 'karks-crm' ), __( 'Services', 'karks-crm' ), 'manage_options', 'karks-crm-services', array( $this->screens['services'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Invoices', 'karks-crm' ), __( 'Invoices', 'karks-crm' ), 'manage_options', 'karks-crm-invoices', array( $this->screens['invoices'], 'render' ) );
		add_submenu_page( 'karks-crm', __( 'Companies', 'karks-crm' ), __( 'Companies', 'karks-crm' ), 'manage_options', 'karks-crm-companies', array( $this->screens['companies'], 'render' ) );
	}

	/**
	 * Each screen may process its own form submissions before any HTML is sent.
	 */
	public function handle_screen_actions() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		foreach ( $this->screens as $screen ) {
			$screen->handle_actions();
		}
	}

	public function enqueue_assets( $hook ) {
		if ( strpos( (string) $hook, 'karks-crm' ) === false ) {
			return;
		}
		wp_enqueue_style( 'kcrm-admin', KCRM_PLUGIN_URL . 'assets/css/admin.css', array(), KCRM_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'kcrm-admin', KCRM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), KCRM_VERSION, true );
	}
}
