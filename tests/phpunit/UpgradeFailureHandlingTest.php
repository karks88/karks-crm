<?php
/**
 * Covers the failure-tracking helpers added to KCRM_Activator so a failed
 * dbDelta() run gets surfaced (persistent admin notice, kept retrying) instead
 * of silently marking the upgrade "done" via an unconditional kcrm_db_version
 * bump. See KCRM_Activator::create_tables()'s docblock for why detecting the
 * failure itself is necessarily best-effort ($wpdb->last_error only reflects
 * the most recent query) -- these tests cover the parts that are fully under
 * this plugin's own control.
 */
class UpgradeFailureHandlingTest extends WP_UnitTestCase {

	public function tear_down() {
		delete_option( 'kcrm_db_upgrade_error' );
		delete_option( 'kcrm_db_upgrade_failed_at' );
		parent::tear_down();
	}

	private function reflect( $method ) {
		$ref = new ReflectionMethod( 'KCRM_Activator', $method );
		$ref->setAccessible( true );
		return $ref;
	}

	public function test_record_upgrade_failure_sets_error_and_throttle() {
		$this->reflect( 'record_upgrade_failure' )->invoke( null, array( 'boom: syntax error' ) );

		$this->assertStringContainsString( 'boom: syntax error', get_option( 'kcrm_db_upgrade_error' ) );
		$this->assertTrue( $this->reflect( 'upgrade_recently_failed' )->invoke( null ) );
	}

	public function test_clear_upgrade_failure_resets_both_options() {
		$this->reflect( 'record_upgrade_failure' )->invoke( null, array( 'boom' ) );
		$this->reflect( 'clear_upgrade_failure' )->invoke( null );

		$this->assertFalse( get_option( 'kcrm_db_upgrade_error' ) );
		$this->assertFalse( $this->reflect( 'upgrade_recently_failed' )->invoke( null ) );
	}

	public function test_notice_renders_the_error_for_a_user_who_can_manage_options() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->reflect( 'record_upgrade_failure' )->invoke( null, array( 'unique-marker-12345' ) );

		ob_start();
		KCRM_Activator::render_upgrade_failure_notice();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'unique-marker-12345', $html );
	}

	public function test_notice_is_hidden_from_a_user_without_manage_options() {
		// kcrm_manager has kcrm_manage but deliberately not manage_options (see KCRM_Activator::add_role_and_caps() and the User Guide) -- a DB failure is outside what that role is meant to see/fix.
		$user_id = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $user_id );
		$this->reflect( 'record_upgrade_failure' )->invoke( null, array( 'unique-marker-12345' ) );

		ob_start();
		KCRM_Activator::render_upgrade_failure_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	public function test_notice_is_silent_when_nothing_has_failed() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		ob_start();
		KCRM_Activator::render_upgrade_failure_notice();
		$html = ob_get_clean();

		$this->assertSame( '', $html );
	}

	/** Proves the actual detection mechanism create_tables() relies on: dbDelta() leaves a real error in $wpdb->last_error for a genuinely broken statement, and nothing for a valid one. */
	public function test_last_error_detection_catches_a_broken_statement() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table = $wpdb->prefix . 'kcrm_test_upgrade_failure';

		$wpdb->last_error = '';
		dbDelta( "CREATE TABLE $table (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, broken NOT_A_REAL_TYPE NULL, PRIMARY KEY (id)) " . $wpdb->get_charset_collate() . ';' );
		$this->assertNotSame( '', $wpdb->last_error, 'dbDelta() should have left an error for an invalid column type' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- test-only schema introspection of a throwaway table.
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		$this->assertNull( $exists, 'the broken table should not have been created' );

		$wpdb->last_error = '';
		dbDelta( "CREATE TABLE $table (id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, PRIMARY KEY (id)) " . $wpdb->get_charset_collate() . ';' );
		$this->assertSame( '', $wpdb->last_error, 'a valid statement should leave no error' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared -- cleaning up the throwaway table created above; %i is filled in via prepare() on the same line.
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
	}
}
