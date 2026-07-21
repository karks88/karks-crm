<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-db.php';

/**
 * Creates/updates the plugin's custom database tables on activation.
 */
class KCRM_Activator {

	public static function activate() {
		self::create_tables();
		add_option( 'kcrm_db_version', KCRM_DB_VERSION );
		self::add_role_and_caps();
		self::create_front_page();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'kcrm_db_version' ) !== KCRM_DB_VERSION ) {
			self::create_tables();
			update_option( 'kcrm_db_version', KCRM_DB_VERSION );
		}

		self::add_role_and_caps();
		self::create_front_page();

		if ( ! get_option( 'kcrm_rewrite_flushed_v1' ) ) {
			flush_rewrite_rules();
			add_option( 'kcrm_rewrite_flushed_v1', 1 );
		}
	}

	/**
	 * Grants KCRM_CAPABILITY to Administrators (so nothing currently
	 * working changes) and creates a dedicated kcrm_manager role that can
	 * use the plugin (in wp-admin or on the front end) without needing
	 * full Administrator access. Safe to call on every activation/upgrade.
	 */
	private static function add_role_and_caps() {
		add_role(
			'kcrm_manager',
			__( 'CRM Manager', 'karks-crm' ),
			array(
				'read'          => true,
				'upload_files'  => true,
				KCRM_CAPABILITY => true,
			)
		);

		$administrator = get_role( 'administrator' );
		if ( $administrator && ! $administrator->has_cap( KCRM_CAPABILITY ) ) {
			$administrator->add_cap( KCRM_CAPABILITY );
		}
	}

	/**
	 * Creates the front-end "CRM" page (contains the [karks_crm] shortcode)
	 * that KCRM_Front's rewrite endpoints are scoped to. No-ops if it
	 * already exists.
	 */
	private static function create_front_page() {
		$page_id = (int) get_option( 'kcrm_front_page_id' );
		if ( $page_id && get_post( $page_id ) ) {
			return;
		}

		// The option may be missing even though the page exists (e.g. a
		// request that inserted the page but didn't finish running) --
		// look it up by slug before creating a duplicate.
		$existing = get_page_by_path( 'crm', OBJECT, 'page' );
		if ( $existing ) {
			update_option( 'kcrm_front_page_id', $existing->ID );
			return;
		}

		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'CRM', 'karks-crm' ),
				'post_name'    => 'crm',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '[karks_crm]',
			),
			true
		);

		if ( $page_id && ! is_wp_error( $page_id ) ) {
			update_option( 'kcrm_front_page_id', $page_id );
		}
	}

	private static function create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		$companies      = KCRM_DB::companies();
		$customers      = KCRM_DB::customers();
		$services       = KCRM_DB::services();
		$invoices       = KCRM_DB::invoices();
		$invoice_items  = KCRM_DB::invoice_items();
		$payments       = KCRM_DB::payments();

		$sql = array();

		$sql[] = "CREATE TABLE $companies (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			email VARCHAR(255) NULL,
			phone VARCHAR(64) NULL,
			address_street VARCHAR(255) NULL,
			address_city VARCHAR(128) NULL,
			address_state VARCHAR(128) NULL,
			address_postal_code VARCHAR(32) NULL,
			logo_attachment_id BIGINT UNSIGNED NULL,
			invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV-',
			next_invoice_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
			default_tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			invoice_footer TEXT NULL,
			accepted_payment_types VARCHAR(255) NULL,
			payment_links TEXT NULL,
			check_payable_to VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $customers (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			parent_customer_id BIGINT UNSIGNED NULL,
			company_name VARCHAR(255) NOT NULL,
			contact_person VARCHAR(255) NULL,
			secondary_contact_person VARCHAR(255) NULL,
			address_street VARCHAR(255) NULL,
			address_city VARCHAR(128) NULL,
			address_state VARCHAR(128) NULL,
			address_postal_code VARCHAR(32) NULL,
			phone VARCHAR(64) NULL,
			email VARCHAR(255) NULL,
			secondary_email VARCHAR(255) NULL,
			notes TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY company_id (company_id),
			KEY parent_customer_id (parent_customer_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $services (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'hourly',
			rate DECIMAL(12,2) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY company_id (company_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $invoices (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			invoice_number VARCHAR(50) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			issue_date DATE NOT NULL,
			due_date DATE NULL,
			invoice_type VARCHAR(30) NOT NULL DEFAULT 'other',
			invoice_type_month VARCHAR(7) NULL,
			invoice_type_other VARCHAR(255) NULL,
			notes TEXT NULL,
			subtotal DECIMAL(12,2) NOT NULL DEFAULT 0,
			tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			tax_amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			total DECIMAL(12,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY company_id (company_id),
			KEY customer_id (customer_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $invoice_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			service_id BIGINT UNSIGNED NULL,
			description VARCHAR(255) NOT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'project',
			quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
			rate DECIMAL(12,2) NOT NULL DEFAULT 0,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY invoice_id (invoice_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $payments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED NOT NULL,
			company_id BIGINT UNSIGNED NOT NULL,
			amount DECIMAL(12,2) NOT NULL DEFAULT 0,
			payment_date DATE NOT NULL,
			method VARCHAR(50) NULL,
			note VARCHAR(255) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY invoice_id (invoice_id),
			KEY customer_id (customer_id)
		) $charset_collate;";

		foreach ( $sql as $statement ) {
			dbDelta( $statement );
		}
	}
}
