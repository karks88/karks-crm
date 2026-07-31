<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer Open Balance PDF template. Rendered inside
 * KCRM_PDF::render_customer_open_balance(), which defines: $customer,
 * $company, $customers, $invoices, $balances, $logo_data, $rollup_ids.
 * Layout mirrors QuickBooks' "Customer Open Balance" report: one group per
 * customer/Job with its open invoices, a subtotal row, then a grand total.
 */

$kcrm_currency      = $company && $company->currency ? $company->currency : 'USD';
$kcrm_format_money  = function ( $amount ) use ( $kcrm_currency ) {
	return $kcrm_currency . ' ' . number_format( (float) $amount, 2 );
};

$kcrm_colors    = KCRM_Colors::get();
$kcrm_hex_or    = function ( $value, $fallback ) {
	return preg_match( '/^#[0-9a-fA-F]{6}$/', (string) $value ) ? $value : $fallback;
};
$kcrm_primary   = $kcrm_hex_or( $kcrm_colors['primary'], '#1e3a5f' );
$kcrm_secondary = $kcrm_hex_or( $kcrm_colors['secondary'], '#1f2937' );
$kcrm_highlight = $kcrm_hex_or( $kcrm_colors['highlight'], '#fff6b3' );
$kcrm_accent    = $kcrm_hex_or( KCRM_Company::pdf_accent_color( $company ), $kcrm_primary );

// Group invoices by the customer/Job they actually belong to, preserving
// $rollup_ids order (the primary customer first, then its Jobs), and
// skipping any with no open invoices.
$kcrm_groups        = array();
$kcrm_grand_balance = 0.0;
$kcrm_grand_amount  = 0.0;

foreach ( $rollup_ids as $kcrm_id ) {
	$kcrm_id              = (int) $kcrm_id;
	$kcrm_group_customer = $customers[ $kcrm_id ] ?? null;
	if ( ! $kcrm_group_customer ) {
		continue;
	}

	$kcrm_group_invoices = array_values(
		array_filter(
			$invoices,
			static function ( $kcrm_inv ) use ( $kcrm_id ) {
				return (int) $kcrm_inv->customer_id === $kcrm_id;
			}
		)
	);
	if ( empty( $kcrm_group_invoices ) ) {
		continue;
	}

	$kcrm_subtotal_balance = 0.0;
	$kcrm_subtotal_amount  = 0.0;
	foreach ( $kcrm_group_invoices as $kcrm_inv ) {
		$kcrm_subtotal_balance += $balances[ $kcrm_inv->id ];
		$kcrm_subtotal_amount  += (float) $kcrm_inv->total;
	}

	$kcrm_groups[] = array(
		'customer'         => $kcrm_group_customer,
		'invoices'         => $kcrm_group_invoices,
		'subtotal_balance' => round( $kcrm_subtotal_balance, 2 ),
		'subtotal_amount'  => round( $kcrm_subtotal_amount, 2 ),
	);
	$kcrm_grand_balance += $kcrm_subtotal_balance;
	$kcrm_grand_amount  += $kcrm_subtotal_amount;
}
$kcrm_grand_balance = round( $kcrm_grand_balance, 2 );
$kcrm_grand_amount  = round( $kcrm_grand_amount, 2 );
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
	.report-title { font-size: 22px; font-weight: bold; text-align: right; color: <?php echo esc_html( $kcrm_accent ); ?>; }
	.report-meta { text-align: right; margin-top: 6px; }
	.group { margin-top: 18px; }
	.group h4 { margin: 0 0 4px; font-size: 12px; text-transform: uppercase; color: <?php echo esc_html( $kcrm_accent ); ?>; border-bottom: 1px solid #ddd; padding-bottom: 2px; }
	table.items { width: 100%; border-collapse: collapse; margin-top: 4px; }
	table.items th { text-align: left; background: <?php echo esc_html( $kcrm_secondary ); ?>; padding: 5px 8px; font-size: 10px; text-transform: uppercase; color: #fff; }
	table.items td { padding: 5px 8px; border-bottom: 1px solid #eee; text-align: left; }
	table.items tbody tr.data-row:nth-child(even) td { background: <?php echo esc_html( $kcrm_highlight ); ?>; }
	.text-right { text-align: right; }
	tr.subtotal-row td { font-weight: bold; border-top: 1px solid #ccc; }
	table.grand-total { width: 280px; margin-left: auto; margin-top: 20px; border-collapse: collapse; }
	table.grand-total th, table.grand-total td { padding: 6px 8px; text-align: right; }
	table.grand-total tr.total-row td { font-weight: bold; border-top: 2px solid <?php echo esc_html( $kcrm_accent ); ?>; color: <?php echo esc_html( $kcrm_accent ); ?>; font-size: 14px; }
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
				<div class="report-title"><?php esc_html_e( 'OPEN BALANCE', 'karks-crm' ); ?></div>
				<div class="report-meta">
					<div><strong><?php echo esc_html( KCRM_Customer::display_name( $customer ) ); ?></strong></div>
					<div>
						<?php
						/* translators: %s: report generation date. */
						echo esc_html( sprintf( __( 'As of %s', 'karks-crm' ), date_i18n( get_option( 'date_format' ) ) ) );
						?>
					</div>
				</div>
			</td>
		</tr>
	</table>
</div>

<?php if ( empty( $kcrm_groups ) ) : ?>
	<p><?php esc_html_e( 'No open invoices.', 'karks-crm' ); ?></p>
<?php endif; ?>

<?php foreach ( $kcrm_groups as $kcrm_group ) : ?>
	<div class="group">
		<h4><?php echo esc_html( $kcrm_group['customer']->company_name ); ?></h4>
		<table class="items">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Type', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Num', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Due Date', 'karks-crm' ); ?></th>
					<th class="text-right"><?php esc_html_e( 'Open Balance', 'karks-crm' ); ?></th>
					<th class="text-right"><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $kcrm_group['invoices'] as $kcrm_inv ) : ?>
					<tr class="data-row">
						<td><?php esc_html_e( 'Invoice', 'karks-crm' ); ?></td>
						<td><?php echo esc_html( $kcrm_inv->issue_date ); ?></td>
						<td><?php echo esc_html( $kcrm_inv->invoice_number ); ?></td>
						<td><?php echo esc_html( $kcrm_inv->due_date ); ?></td>
						<td class="text-right"><?php echo esc_html( $kcrm_format_money( $balances[ $kcrm_inv->id ] ) ); ?></td>
						<td class="text-right"><?php echo esc_html( $kcrm_format_money( $kcrm_inv->total ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				<tr class="subtotal-row">
					<td colspan="4">
						<?php
						/* translators: %s: customer or Job name. */
						echo esc_html( sprintf( __( 'Total %s', 'karks-crm' ), $kcrm_group['customer']->company_name ) );
						?>
					</td>
					<td class="text-right"><?php echo esc_html( $kcrm_format_money( $kcrm_group['subtotal_balance'] ) ); ?></td>
					<td class="text-right"><?php echo esc_html( $kcrm_format_money( $kcrm_group['subtotal_amount'] ) ); ?></td>
				</tr>
			</tbody>
		</table>
	</div>
<?php endforeach; ?>

<table class="grand-total">
	<tr>
		<th><?php esc_html_e( 'Open Balance', 'karks-crm' ); ?></th>
		<th><?php esc_html_e( 'Amount', 'karks-crm' ); ?></th>
	</tr>
	<tr class="total-row">
		<td><?php echo esc_html( $kcrm_format_money( $kcrm_grand_balance ) ); ?></td>
		<td><?php echo esc_html( $kcrm_format_money( $kcrm_grand_amount ) ); ?></td>
	</tr>
</table>

</body>
</html>
