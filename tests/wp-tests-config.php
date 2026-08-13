<?php
/**
 * WP core test-suite config. Everything environment-specific (DB creds,
 * host/port) comes from env vars rather than being hardcoded here, since
 * this file is committed but a LocalWP dev DB's port changes every time
 * Local restarts the site (see the wpcli-setup memory/CLAUDE.md note on
 * that) and CI uses its own DB service container entirely.
 *
 * ABSPATH is derived from this file's own location (wp-content/plugins/
 * karks-crm/tests/) rather than hardcoded, so it works on any machine/CI
 * checkout that preserves the standard plugin directory layout.
 */

define( 'ABSPATH', dirname( __DIR__, 4 ) . '/' );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'karks_crm_test' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: '127.0.0.1' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = getenv( 'WP_TESTS_TABLE_PREFIX' ) ?: 'wptests_';

// The constant names on this block are required, exact-match, by WP core's
// own test-suite bootstrap (wp-phpunit/includes/bootstrap.php checks for
// these precise names) -- none of them are renameable to a kcrm_-prefixed one.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WP_TESTS_DOMAIN', 'example.org' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WP_TESTS_TITLE', 'Karks CRM Test Suite' );

// install.php shells out to this via system() to bootstrap the test DB -- the
// bare string 'php' resolves via PATH, which can silently land on a different,
// unconfigured PHP install (e.g. missing mysqli) instead of the one actually
// running this test process. PHP_BINARY is the interpreter running right now.
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WP_PHP_BINARY', PHP_BINARY );
// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound
define( 'WPLANG', '' );
