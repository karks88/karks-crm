<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Invoice PDF template. Rendered inside KCRM_PDF::stream_invoice(), which
 * defines: $invoice, $company, $customer, $items, $payments, $balance_due, $logo_data.
 */

$kcrm_currency = $company && $company->currency ? $company->currency : 'USD';
$kcrm_statuses = KCRM_Invoice::statuses();

$kcrm_format_money = function ( $amount ) use ( $kcrm_currency ) {
	return $kcrm_currency . ' ' . number_format( (float) $amount, 2 );
};
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
	body { font-family: Helvetica, Arial, sans-serif; font-size: 12px; color: #222; }
	.header { width: 100%; margin-bottom: 24px; }
	.header table { width: 100%; border-collapse: collapse; }
	.header td { vertical-align: top; }
	.logo img { max-width: 200px; max-height: 110px; }
	.company-name { font-size: 18px; font-weight: bold; }
	.invoice-title { font-size: 24px; font-weight: bold; text-align: right; color: #444; }
	.invoice-meta { text-align: right; margin-top: 6px; }
	.addresses { width: 100%; margin: 20px 0; }
	.addresses table { width: 100%; }
	.addresses td { width: 50%; vertical-align: top; }
	.addresses h4 { margin: 0 0 4px; font-size: 11px; text-transform: uppercase; color: #888; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 10px; }
	table.items th { text-align: left; background: #f2f2f2; padding: 6px 8px; font-size: 11px; text-transform: uppercase; color: #555; }
	table.items td { padding: 6px 8px; border-bottom: 1px solid #eee; text-align: left; }
	.text-right { text-align: right; }
	table.totals { width: 260px; margin-left: auto; margin-top: 12px; border-collapse: collapse; }
	table.totals td { padding: 4px 8px; }
	table.totals tr.total-row td { font-weight: bold; border-top: 2px solid #333; font-size: 14px; }
	.status-badge { display: inline-block; padding: 3px 10px; border-radius: 3px; background: #eee; font-size: 11px; text-transform: uppercase; }
	.notes { margin-top: 24px; }
	.payments { margin-top: 20px; }
	.payments table { width: 100%; border-collapse: collapse; }
	.payments th, .payments td { padding: 4px 8px; border-bottom: 1px solid #eee; font-size: 11px; }
	.invoice-footer { margin-top: 30px; padding-top: 12px; border-top: 1px solid #ddd; font-size: 11px; color: #555; }
	.payment-options { margin-top: 20px; }
	.payment-options .payment-links a { display: inline-block; margin: 4px 12px 4px 0; padding: 4px 10px; border: 1px solid #333; border-radius: 3px; background: #222; color: #fff; text-decoration: none; }
	.payment-options .payment-links .link-icon { margin-left: 4px; font-weight: bold; }
	.payment-options .check-payable-to { margin: 1em 0; font-size: 16px; }
</style>
</head>
<body>

<div class="header">
	<table>
		<tr>
			<td class="logo">
				<?php if ( $logo_data ) : ?>
					<img src="<?php echo esc_attr( $logo_data ); ?>" alt="<?php echo esc_attr( $company->name ); ?>">
				<?php else : ?>
					<div class="company-name"><?php echo esc_html( $company->name ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<div class="invoice-title"><?php esc_html_e( 'INVOICE', 'karks-crm' ); ?></div>
				<div class="invoice-meta">
					<div><strong><?php echo esc_html( $invoice->invoice_number ); ?></strong></div>
					<div><?php echo esc_html( KCRM_Invoice::type_label( $invoice ) ); ?></div>
					<div><?php esc_html_e( 'Issued:', 'karks-crm' ); ?> <?php echo esc_html( $invoice->issue_date ); ?></div>
					<?php if ( $invoice->due_date ) : ?>
						<div><?php esc_html_e( 'Due:', 'karks-crm' ); ?> <?php echo esc_html( $invoice->due_date ); ?></div>
					<?php endif; ?>
					<div><span class="status-badge"><?php echo esc_html( $kcrm_statuses[ $invoice->status ] ?? $invoice->status ); ?></span></div>
				</div>
			</td>
		</tr>
	</table>
</div>

<div class="addresses">
	<table>
		<tr>
			<td>
				<h4><?php esc_html_e( 'From', 'karks-crm' ); ?></h4>
				<div><?php echo esc_html( $company->name ); ?></div>
				<?php if ( $company->address_street ) : ?><div><?php echo esc_html( $company->address_street ); ?></div><?php endif; ?>
				<div>
					<?php echo esc_html( trim( implode( ', ', array_filter( array( $company->address_city, $company->address_state ) ) ) ) ); ?>
					<?php echo esc_html( $company->address_postal_code ); ?>
				</div>
				<?php if ( $company->phone ) : ?><div><?php echo esc_html( $company->phone ); ?></div><?php endif; ?>
				<?php if ( $company->email ) : ?><div><?php echo esc_html( $company->email ); ?></div><?php endif; ?>
			</td>
			<td>
				<h4><?php esc_html_e( 'Bill To', 'karks-crm' ); ?></h4>
				<div><?php echo esc_html( $customer->company_name ); ?></div>
				<?php if ( $customer->contact_person ) : ?><div><?php echo esc_html( $customer->contact_person ); ?></div><?php endif; ?>
				<?php if ( $customer->address_street ) : ?><div><?php echo esc_html( $customer->address_street ); ?></div><?php endif; ?>
				<div>
					<?php echo esc_html( trim( implode( ', ', array_filter( array( $customer->address_city, $customer->address_state ) ) ) ) ); ?>
					<?php echo esc_html( $customer->address_postal_code ); ?>
				</div>
				<?php if ( $customer->email ) : ?><div><?php echo esc_html( $customer->email ); ?></div><?php endif; ?>
			</td>
		</tr>
	</table>
</div>

<table class="items">
	<thead>
		<tr>
			<th><?php esc_html_e( 'Description', 'karks-crm' ); ?></th>
			<th class="text-right"><?php esc_html_e( 'Qty/Hours', 'karks-crm' ); ?></th>
			<th class="text-right"><?php esc_html_e( 'Rate', 'karks-crm' ); ?></th>
			<th class="text-right"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $items as $kcrm_item ) : ?>
			<tr>
				<td><?php echo esc_html( $kcrm_item->description ); ?></td>
				<td class="text-right"><?php echo esc_html( number_format( (float) $kcrm_item->quantity, 2 ) ); ?></td>
				<td class="text-right"><?php echo esc_html( $kcrm_format_money( $kcrm_item->rate ) ); ?></td>
				<td class="text-right"><?php echo esc_html( $kcrm_format_money( $kcrm_item->amount ) ); ?></td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>

<table class="totals">
	<tr>
		<td><?php esc_html_e( 'Subtotal', 'karks-crm' ); ?></td>
		<td class="text-right"><?php echo esc_html( $kcrm_format_money( $invoice->subtotal ) ); ?></td>
	</tr>
	<tr>
		<td>
			<?php
			/* translators: %s: tax rate percentage. */
			echo esc_html( sprintf( __( 'Tax (%s%%)', 'karks-crm' ), rtrim( rtrim( number_format( (float) $invoice->tax_rate, 3 ), '0' ), '.' ) ) );
			?>
		</td>
		<td class="text-right"><?php echo esc_html( $kcrm_format_money( $invoice->tax_amount ) ); ?></td>
	</tr>
	<tr class="total-row">
		<td><?php esc_html_e( 'Total', 'karks-crm' ); ?></td>
		<td class="text-right"><?php echo esc_html( $kcrm_format_money( $invoice->total ) ); ?></td>
	</tr>
	<tr>
		<td><?php esc_html_e( 'Balance Due', 'karks-crm' ); ?></td>
		<td class="text-right"><?php echo esc_html( $kcrm_format_money( $balance_due ) ); ?></td>
	</tr>
</table>

<?php if ( ! empty( $payments ) ) : ?>
<div class="payments">
	<h4><?php esc_html_e( 'Payments Received', 'karks-crm' ); ?></h4>
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Date', 'karks-crm' ); ?></th>
				<th><?php esc_html_e( 'Method', 'karks-crm' ); ?></th>
				<th class="text-right"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $payments as $kcrm_payment ) : ?>
				<tr>
					<td><?php echo esc_html( $kcrm_payment->payment_date ); ?></td>
					<td><?php echo esc_html( $kcrm_payment->method ); ?></td>
					<td class="text-right"><?php echo esc_html( $kcrm_format_money( $kcrm_payment->amount ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<?php
$kcrm_payment_type_keys     = KCRM_Company::accepted_payment_type_keys( $company );
$kcrm_payment_links         = KCRM_Company::payment_links( $company );
$kcrm_payment_types         = KCRM_Company::payment_types();
$kcrm_show_check_payable_to = in_array( 'check', $kcrm_payment_type_keys, true ) && ! empty( $company->check_payable_to );
?>
<?php if ( ! empty( $kcrm_payment_type_keys ) || ! empty( $kcrm_payment_links ) ) : ?>
<div class="payment-options">
	<h4><?php esc_html_e( 'Payment Options', 'karks-crm' ); ?></h4>
	<?php if ( ! empty( $kcrm_payment_type_keys ) ) : ?>
		<div>
			<?php
			echo esc_html( implode( ', ', array_map( static function ( $key ) use ( $kcrm_payment_types ) {
				return $kcrm_payment_types[ $key ] ?? $key;
			}, $kcrm_payment_type_keys ) ) );
			?>
		</div>
	<?php endif; ?>
	<?php if ( $kcrm_show_check_payable_to ) : ?>
		<div class="check-payable-to">
			<?php esc_html_e( 'Make checks payable to:', 'karks-crm' ); ?>
			<strong><?php echo esc_html( $company->check_payable_to ); ?></strong>
		</div>
	<?php endif; ?>
	<?php if ( ! empty( $kcrm_payment_links ) ) : ?>
		<div class="payment-links">
			<?php foreach ( $kcrm_payment_links as $kcrm_link ) : ?>
				<?php if ( empty( $kcrm_link['url'] ) ) { continue; } ?>
				<a href="<?php echo esc_url( $kcrm_link['url'] ); ?>"><?php echo esc_html( $kcrm_link['label'] ? $kcrm_link['label'] : $kcrm_link['url'] ); ?> <span class="link-icon">&gt;&gt;</span></a>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
</div>
<?php endif; ?>

<?php if ( ! empty( $invoice->notes ) ) : ?>
<div class="notes">
	<h4><?php esc_html_e( 'Notes', 'karks-crm' ); ?></h4>
	<div><?php echo nl2br( esc_html( $invoice->notes ) ); ?></div>
</div>
<?php endif; ?>

<?php if ( $company && ! empty( $company->invoice_footer ) ) : ?>
<div class="invoice-footer">
	<?php echo nl2br( esc_html( $company->invoice_footer ) ); ?>
</div>
<?php endif; ?>

</body>
</html>
