<?php
/**
 * Plugin Name: Karks CRM
 * Plugin URI: https://karks-crm.com
 * Description: Manage customers, services, and invoices across multiple companies.
 * Version: 0.9.9.7
 * Author: Eric Karkovack
 * Author URI: https://karks.com
 * Text Domain: karks-crm
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * License: GPL v3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KCRM_VERSION', '0.9.9.7' );
define( 'KCRM_DB_VERSION', '1.17.0' );
define( 'KCRM_PLUGIN_FILE', __FILE__ );
define( 'KCRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KCRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Capability required to manage companies/customers/services/invoices,
 * in wp-admin or on the front end. Granted to Administrators and to the
 * kcrm_manager role (see KCRM_Activator::add_role_and_caps()).
 */
define( 'KCRM_CAPABILITY', 'kcrm_manage' );

if ( file_exists( KCRM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once KCRM_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-activator.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-db.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-csv-import.php';

require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-model-base.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-company.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-customer.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-service.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-invoice.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-invoice-type.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-invoice-item.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-payment.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-invoice-email.php';

require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-context.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-company-transfer.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-colors.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-countries.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-merge-tags.php';
require_once KCRM_PLUGIN_DIR . 'includes/controllers/class-kcrm-controller-base.php';
require_once KCRM_PLUGIN_DIR . 'includes/controllers/class-kcrm-companies-controller.php';
require_once KCRM_PLUGIN_DIR . 'includes/controllers/class-kcrm-customers-controller.php';
require_once KCRM_PLUGIN_DIR . 'includes/controllers/class-kcrm-services-controller.php';
require_once KCRM_PLUGIN_DIR . 'includes/controllers/class-kcrm-invoices-controller.php';

require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-front.php';

register_activation_hook( __FILE__, array( 'KCRM_Activator', 'activate' ) );

/**
 * Boot the plugin.
 */
function kcrm_run() {
	$plugin = new KCRM_Plugin();
	$plugin->run();

	$front = new KCRM_Front();
	$front->run();
}
add_action( 'plugins_loaded', 'kcrm_run' );
