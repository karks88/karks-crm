<?php
/** Confirms the WP test-suite bootstrap actually loads WordPress and this plugin before any real test suite is trusted. */
class SmokeTest extends WP_UnitTestCase {

	public function test_wordpress_loaded() {
		$this->assertTrue( function_exists( 'wp_insert_post' ) );
	}

	public function test_plugin_loaded() {
		$this->assertTrue( class_exists( 'KCRM_Company' ) );
		$this->assertTrue( defined( 'KCRM_VERSION' ) );
	}

	public function test_custom_tables_exist() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only schema introspection, not request-time data access.
		$this->assertNotEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'karkscrm_companies' ) ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only schema introspection, not request-time data access.
		$this->assertNotEmpty( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'karkscrm_customers' ) ) );
	}
}
