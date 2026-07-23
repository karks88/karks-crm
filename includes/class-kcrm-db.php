<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central place for table names so every model/query agrees on them.
 */
class KCRM_DB {

	public static function companies() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_companies';
	}

	public static function customers() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_customers';
	}

	public static function services() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_services';
	}

	public static function invoices() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_invoices';
	}

	public static function invoice_items() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_invoice_items';
	}

	public static function payments() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_payments';
	}

	public static function invoice_emails() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_invoice_emails';
	}

	public static function invoice_types() {
		global $wpdb;
		return $wpdb->prefix . 'karkscrm_invoice_types';
	}
}
