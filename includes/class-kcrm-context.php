<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks which Company the current admin user is "looking at" so every
 * screen (customers, services, invoices) can filter by it.
 */
class KCRM_Context {

	const META_KEY = 'kcrm_current_company_id';

	/**
	 * Handles the company switcher form and returns the active company id
	 * for the current user (persisted in user meta).
	 */
	private static $resolved_company_id = null;

	/**
	 * Memoized per-request -- this is called repeatedly across a single
	 * page render (header, company switcher, screen body, ...) and the
	 * answer can't change mid-request, so only the first call actually
	 * hits the database.
	 */
	public static function get_current_company_id() {
		if ( null !== self::$resolved_company_id ) {
			return self::$resolved_company_id;
		}

		self::$resolved_company_id = self::resolve_current_company_id();
		return self::$resolved_company_id;
	}

	private static function resolve_current_company_id() {
		$user_id = get_current_user_id();

		if ( isset( $_GET['kcrm_company'] ) && isset( $_GET['_wpnonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'kcrm_switch_company' ) ) {
			$requested = absint( $_GET['kcrm_company'] );
			if ( $requested && KCRM_Company::find( $requested ) ) {
				update_user_meta( $user_id, self::META_KEY, $requested );
				return $requested;
			}
		}

		$stored = (int) get_user_meta( $user_id, self::META_KEY, true );
		if ( $stored && KCRM_Company::find( $stored ) ) {
			return $stored;
		}

		$companies = KCRM_Company::all_ordered();
		if ( ! empty( $companies ) ) {
			$first = (int) $companies[0]->id;
			update_user_meta( $user_id, self::META_KEY, $first );
			return $first;
		}

		return 0;
	}

	public static function switch_company_url( $company_id, $base_url ) {
		$url = add_query_arg( 'kcrm_company', $company_id, $base_url );
		return wp_nonce_url( $url, 'kcrm_switch_company' );
	}
}
