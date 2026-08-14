<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the {{first_name}}/{{last_name}}/{{customer}}/{{service}}/
 * {{invoice_amount}} merge tags used in a company's Email Invoice template
 * (KCRM_Company::email_template()) against a specific invoice/customer.
 */
class KCRM_Merge_Tags {

	/** @return array<string,string> Tag (without braces) => label, for help text in the settings form. */
	public static function tags() {
		return array(
			'first_name'     => __( "Customer contact's first name", 'karks-crm' ),
			'last_name'      => __( "Customer contact's last name", 'karks-crm' ),
			'customer'       => __( "Customer's company name", 'karks-crm' ),
			'service'        => __( "Invoice's type/service label", 'karks-crm' ),
			'invoice_amount' => __( 'Invoice total, formatted with currency', 'karks-crm' ),
		);
	}

	/** @return string $template with every {{tag}} replaced with its resolved value for this invoice/customer. */
	public static function replace( $template, $invoice, $customer, $company ) {
		list( $first_name, $last_name ) = self::split_name( $customer ? $customer->contact_person : '' );

		$currency = $company && $company->currency ? $company->currency : 'USD';

		$values = array(
			'{{first_name}}'     => wp_strip_all_tags( $first_name ),
			'{{last_name}}'      => wp_strip_all_tags( $last_name ),
			'{{customer}}'       => $customer ? wp_strip_all_tags( $customer->company_name ) : '',
			'{{service}}'        => $invoice ? wp_strip_all_tags( KCRM_Invoice::type_label( $invoice ) ) : '',
			'{{invoice_amount}}' => $invoice ? $currency . ' ' . number_format( (float) $invoice->total, 2 ) : '',
		);

		return str_replace( array_keys( $values ), array_values( $values ), (string) $template );
	}

	/** @return string[] [ $first_name, $last_name ], splitting on the first space; $last_name is '' if there's no space. */
	private static function split_name( $contact_person ) {
		$contact_person = trim( (string) $contact_person );
		if ( '' === $contact_person ) {
			return array( '', '' );
		}
		$parts = explode( ' ', $contact_person, 2 );
		return array( $parts[0], $parts[1] ?? '' );
	}
}
