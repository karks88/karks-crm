<?php
/**
 * Plugin Name: Karks CRM
 * Description: Manage customers, services, and invoices across multiple companies.
 * Version: 0.1.0
 * Author: Eric Karkovack
 * Author URI: https://karks.com
 * Text Domain: karks-crm
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KCRM_VERSION', '0.3.0' );
define( 'KCRM_DB_VERSION', '1.2.0' );
define( 'KCRM_PLUGIN_FILE', __FILE__ );
define( 'KCRM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'KCRM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

if ( file_exists( KCRM_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once KCRM_PLUGIN_DIR . 'vendor/autoload.php';
}

require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-activator.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-db.php';

require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-model-base.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-company.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-customer.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-service.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-invoice.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-invoice-item.php';
require_once KCRM_PLUGIN_DIR . 'includes/models/class-kcrm-payment.php';

require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm.php';
require_once KCRM_PLUGIN_DIR . 'includes/class-kcrm-context.php';

register_activation_hook( __FILE__, array( 'KCRM_Activator', 'activate' ) );

/**
 * Boot the plugin.
 */
function kcrm_run() {
	$plugin = new KCRM_Plugin();
	$plugin->run();
}
add_action( 'plugins_loaded', 'kcrm_run' );
