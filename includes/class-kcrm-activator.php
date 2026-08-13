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
		$existing_install = (bool) get_option( 'kcrm_db_version' );

		$errors = self::create_tables();
		if ( empty( $errors ) ) {
			add_option( 'kcrm_db_version', KCRM_DB_VERSION );
			self::clear_upgrade_failure();
		} else {
			self::record_upgrade_failure( $errors );
		}

		self::seed_invoice_types_if_empty( $existing_install );
		self::add_role_and_caps();
		self::create_front_page();
		self::maybe_flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		$existing_install = (bool) get_option( 'kcrm_db_version' );

		if ( get_option( 'kcrm_db_version' ) !== KCRM_DB_VERSION && ! self::upgrade_recently_failed() ) {
			$errors = self::create_tables();
			if ( empty( $errors ) ) {
				update_option( 'kcrm_db_version', KCRM_DB_VERSION );
				self::clear_upgrade_failure();
			} else {
				self::record_upgrade_failure( $errors );
			}
		}

		self::seed_invoice_types_if_empty( $existing_install );
		self::add_role_and_caps();
		self::create_front_page();
		self::maybe_flush_rewrite_rules();
	}

	/**
	 * Whether create_tables() failed recently enough that maybe_upgrade()
	 * shouldn't retry it again this request. Without this, a persistent
	 * failure (e.g. the DB user lacking ALTER privileges) would re-run the
	 * full dbDelta() pass -- several queries -- on every single page load,
	 * since kcrm_db_version is deliberately left un-bumped on failure so the
	 * upgrade keeps being attempted rather than silently marked "done".
	 */
	private static function upgrade_recently_failed() {
		$failed_at = get_option( 'kcrm_db_upgrade_failed_at' );
		return $failed_at && ( time() - (int) $failed_at ) < HOUR_IN_SECONDS;
	}

	/** Records a failed migration attempt for render_upgrade_failure_notice() and upgrade_recently_failed(). */
	private static function record_upgrade_failure( array $errors ) {
		update_option( 'kcrm_db_upgrade_error', implode( "\n", array_unique( $errors ) ) );
		update_option( 'kcrm_db_upgrade_failed_at', time() );
	}

	/** Clears any previously recorded failure once a migration succeeds. */
	private static function clear_upgrade_failure() {
		delete_option( 'kcrm_db_upgrade_error' );
		delete_option( 'kcrm_db_upgrade_failed_at' );
	}

	/**
	 * Persistent wp-admin notice (all screens, not just Karks CRM's own) so
	 * a failed database update can't go unnoticed -- shown to anyone who
	 * can manage_options, since fixing a DB permissions/schema problem is
	 * outside what the kcrm_manage capability is meant to cover.
	 */
	public static function render_upgrade_failure_notice() {
		$error = get_option( 'kcrm_db_upgrade_error' );
		if ( ! $error || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Karks CRM: database update failed.', 'karks-crm' ); ?></strong>
				<?php esc_html_e( 'The plugin will keep retrying automatically, but some features may not work correctly until this is resolved. Details for your host/developer:', 'karks-crm' ); ?>
			</p>
			<p><code><?php echo esc_html( $error ); ?></code></p>
		</div>
		<?php
	}

	/**
	 * Seeds the invoice_types table the first time it's empty, and only
	 * then -- once any type exists (whether seeded here or added by hand),
	 * this never runs again.
	 *
	 * A brand-new install only gets "Month/Year", per the plugin's design:
	 * that's the one type with real conditional behavior baked into the
	 * invoice form (the Month/Year picker), so it always needs to exist.
	 * Everything else is now a user-managed list (Karks CRM -> Invoice
	 * Types) instead of a hardcoded one.
	 *
	 * A site *upgrading* from a version before this feature existed gets
	 * the same three extra types ("Web Hosting", "Website Maintenance
	 * Package", "Other") that used to be hardcoded constants, seeded as
	 * real rows with the exact same type_key strings -- so invoices that
	 * already reference them keep displaying/working exactly as before.
	 *
	 * @param bool $existing_install Whether this site had already run the
	 *                               plugin (any version) before right now.
	 */
	private static function seed_invoice_types_if_empty( $existing_install ) {
		if ( KCRM_Invoice_Type::count_where() > 0 ) {
			return;
		}

		KCRM_Invoice_Type::insert(
			array(
				'type_key' => 'month_year',
				'label'    => __( 'Month/Year', 'karks-crm' ),
			)
		);

		if ( ! $existing_install ) {
			return;
		}

		KCRM_Invoice_Type::insert(
			array(
				'type_key' => 'web_hosting',
				'label'    => __( 'Web Hosting', 'karks-crm' ),
			)
		);
		KCRM_Invoice_Type::insert(
			array(
				'type_key' => 'maintenance',
				'label'    => __( 'Website Maintenance Package', 'karks-crm' ),
			)
		);
		KCRM_Invoice_Type::insert(
			array(
				'type_key' => 'other',
				'label'    => __( 'Other', 'karks-crm' ),
			)
		);
	}

	/**
	 * Flushes rewrite rules if this site's actual persisted rules don't
	 * already include our front-end endpoints -- checked directly against
	 * the 'rewrite_rules' option rather than a "have we ever flushed this
	 * version" flag. That flag can travel along with a cloned/restored
	 * database onto a site whose own rewrite rules were never actually
	 * regenerated on it (e.g. cloning an existing install to spin up a
	 * new test/demo site), leaving the front end 404ing until someone
	 * happens to visit Settings -> Permalinks and save. Checking the real
	 * rules instead makes this self-healing regardless of how the site
	 * came to exist -- including a site where the CRM page has just been
	 * set as the static homepage (see
	 * KCRM_Front::maybe_add_front_page_rewrite_rules()), which needs its
	 * own rule that the generic check below wouldn't otherwise notice is
	 * missing (the page's ordinary /crm/customers/-style rule is already
	 * present and would satisfy it even though the homepage-specific one
	 * isn't).
	 */
	private static function maybe_flush_rewrite_rules() {
		$rules = get_option( 'rewrite_rules' );

		if ( is_array( $rules ) && self::has_endpoint_rules( $rules ) && self::has_front_page_rule_if_needed( $rules ) ) {
			return; // Our endpoints are already present -- nothing to do.
		}

		flush_rewrite_rules();
	}

	/**
	 * Every registered endpoint needs its own rule present, not just one --
	 * checking a single endpoint (e.g. only the first) would report "we're
	 * fine" as soon as that one endpoint's rule exists, even if a newer
	 * endpoint added to KCRM_Front::ENDPOINTS later never got its own rule
	 * persisted, silently 404ing that one endpoint until Permalinks is
	 * re-saved by hand.
	 */
	private static function has_endpoint_rules( array $rules ) {
		foreach ( KCRM_Front::ENDPOINTS as $endpoint ) {
			if ( ! self::rules_contain( $rules, $endpoint . '=' ) ) {
				return false;
			}
		}
		return true;
	}

	/** @see maybe_flush_rewrite_rules() */
	private static function has_front_page_rule_if_needed( array $rules ) {
		$page_id = KCRM_Front::page_id();
		if ( ! $page_id || 'page' !== get_option( 'show_on_front' ) || (int) get_option( 'page_on_front' ) !== $page_id ) {
			return true; // Not applicable -- the CRM page isn't the homepage.
		}

		foreach ( KCRM_Front::ENDPOINTS as $endpoint ) {
			if ( ! self::rules_contain( $rules, 'page_id=' . $page_id . '&' . $endpoint ) ) {
				return false;
			}
		}
		return true;
	}

	/** @see has_endpoint_rules() @see has_front_page_rule_if_needed() */
	private static function rules_contain( array $rules, $needle ) {
		foreach ( $rules as $rewrite ) {
			if ( false !== strpos( $rewrite, $needle ) ) {
				return true;
			}
		}
		return false;
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
		$invoice_emails = KCRM_DB::invoice_emails();
		$invoice_types  = KCRM_DB::invoice_types();

		$sql = array();

		$sql[] = "CREATE TABLE $companies (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL,
			email VARCHAR(255) NULL,
			phone VARCHAR(64) NULL,
			address_street VARCHAR(255) NULL,
			address_street_2 VARCHAR(255) NULL,
			address_city VARCHAR(128) NULL,
			address_state VARCHAR(128) NULL,
			address_postal_code VARCHAR(32) NULL,
			address_country VARCHAR(2) NOT NULL DEFAULT 'US',
			logo_attachment_id BIGINT UNSIGNED NULL,
			invoice_prefix VARCHAR(20) NOT NULL DEFAULT 'INV-',
			next_invoice_number BIGINT UNSIGNED NOT NULL DEFAULT 1,
			default_tax_rate DECIMAL(6,3) NOT NULL DEFAULT 0,
			currency VARCHAR(10) NOT NULL DEFAULT 'USD',
			invoice_footer TEXT NULL,
			accepted_payment_types VARCHAR(255) NULL,
			payment_links TEXT NULL,
			check_payable_to VARCHAR(255) NULL,
			other_payment_instructions VARCHAR(255) NULL,
			pdf_accent_color VARCHAR(7) NULL,
			email_template TEXT NULL,
			invoice_bcc_enabled TINYINT(1) NOT NULL DEFAULT 0,
			invoice_bcc_email VARCHAR(255) NULL,
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
			address_street_2 VARCHAR(255) NULL,
			address_city VARCHAR(128) NULL,
			address_state VARCHAR(128) NULL,
			address_postal_code VARCHAR(32) NULL,
			address_country VARCHAR(2) NOT NULL DEFAULT 'US',
			phone VARCHAR(64) NULL,
			email VARCHAR(255) NULL,
			secondary_email VARCHAR(255) NULL,
			invoice_recipient_name VARCHAR(255) NULL,
			invoice_recipient_email VARCHAR(255) NULL,
			notes TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY company_id (company_id),
			KEY parent_customer_id (parent_customer_id),
			KEY status (status)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $services (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			company_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(255) NOT NULL,
			description TEXT NULL,
			type VARCHAR(20) NOT NULL DEFAULT 'hourly',
			rate DECIMAL(12,2) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			is_taxable TINYINT(1) NOT NULL DEFAULT 0,
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
			KEY customer_id (customer_id),
			KEY status (status),
			KEY due_date (due_date),
			KEY company_status (company_id, status)
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
			is_taxable TINYINT(1) NOT NULL DEFAULT 0,
			sort_order INT NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY invoice_id (invoice_id),
			KEY service_id (service_id)
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
			batch_id VARCHAR(36) NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY invoice_id (invoice_id),
			KEY customer_id (customer_id),
			KEY company_id (company_id),
			KEY payment_date (payment_date),
			KEY batch_id (batch_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $invoice_emails (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			sent_to_name VARCHAR(255) NULL,
			sent_to_email VARCHAR(255) NOT NULL,
			sent_cc VARCHAR(500) NULL,
			sent_bcc VARCHAR(255) NULL,
			sent_by BIGINT UNSIGNED NULL,
			sent_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY invoice_id (invoice_id)
		) $charset_collate;";

		$sql[] = "CREATE TABLE $invoice_types (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type_key VARCHAR(30) NOT NULL,
			label VARCHAR(255) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY type_key (type_key)
		) $charset_collate;";

		// dbDelta() doesn't return or throw on a failed query -- it just moves on -- so
		// the only signal available is $wpdb->last_error, which itself only reflects
		// the most recent query. Resetting it before each statement and checking it
		// right after at least catches the common failure modes (a CREATE TABLE that
		// fails outright, or the final ALTER in a batch) instead of assuming success
		// unconditionally. @return string[] Any $wpdb error messages encountered, keyed
		// by nothing in particular -- empty means every statement appeared to succeed.
		$errors = array();
		foreach ( $sql as $statement ) {
			$wpdb->last_error = '';
			dbDelta( $statement );
			if ( $wpdb->last_error ) {
				$errors[] = $wpdb->last_error;
			}
		}

		return $errors;
	}
}
