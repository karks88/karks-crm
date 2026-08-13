<?php
/**
 * PHPUnit bootstrap: boots the real WP core test suite (via wp-phpunit,
 * installed in this directory's own vendor/ -- see composer.json's
 * docblock for why it's a separate composer project from the plugin's
 * own vendor/) against this plugin's actual code, so tests exercise real
 * $wpdb/dbDelta()/hooks behavior instead of a mocked stand-in for them.
 */

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

// Exact constant name required by WP core's own WP_UnitTestCase bootstrap contract -- not renameable to a kcrm_-prefixed one.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WP_TESTS_PHPUNIT_POLYFILLS_PATH', __DIR__ . '/vendor/yoast/phpunit-polyfills' );

$kcrm_wp_phpunit_dir = __DIR__ . '/vendor/wp-phpunit/wp-phpunit';

if ( ! file_exists( $kcrm_wp_phpunit_dir . '/includes/functions.php' ) ) {
	// CLI-only diagnostic before WordPress (and WP_Filesystem) has loaded at all -- not a file write, just a stderr message.
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	fwrite( STDERR, "wp-phpunit not found -- run `composer install` inside tests/ first.\n" );
	exit( 1 );
}

require_once $kcrm_wp_phpunit_dir . '/includes/functions.php';

/**
 * Loads the plugin exactly the way karks-crm.php's own plugins_loaded hook
 * would -- just calling kcrm_run(), which registers KCRM_Activator::maybe_upgrade()
 * on `init` (not here). Calling activate()/maybe_upgrade() directly from
 * muplugins_loaded, before $wp_rewrite exists, is exactly the crash CLAUDE.md
 * warns about (wp_insert_post() -> get_permalink() needs it); letting WP's own
 * bootstrap fire `init` naturally, same as production, avoids that entirely.
 */
function kcrm_load_plugin_under_test() {
	require dirname( __DIR__ ) . '/karks-crm.php';
	kcrm_run();
}
tests_add_filter( 'muplugins_loaded', 'kcrm_load_plugin_under_test' );

require $kcrm_wp_phpunit_dir . '/includes/bootstrap.php';
