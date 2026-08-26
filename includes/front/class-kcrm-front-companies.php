<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Front_Companies extends KCRM_Companies_Controller {

	use KCRM_Front_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="kcrm-front-screen">';

		if ( 'overview' === $view ) {
			// On the overview page, switching companies should land on the
			// *new* company's overview, not fall back to the companies list.
			$this->render_company_header(
				function ( $company ) {
					return $this->screen_url( array( 'view' => 'overview', 'id' => $company->id ) );
				}
			);
		} else {
			$this->render_company_header();
		}

		$this->render_heading( $view );

		if ( 'list' === $view ) {
			printf( '<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s"><span class="dashicons dashicons-plus-alt2"></span> %s</a></div>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
		}

		$this->render_notice_from_query();

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} elseif ( 'overview' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
			$this->render_overview( isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0 );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	/** Renders the H2 -- linked back to the list once we're off it, with the company's name appended on the overview page. */
	private function render_heading( $view ) {
		$label = __( 'Companies', 'karks-crm' );

		if ( 'list' === $view ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';
			return;
		}

		if ( 'overview' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
			$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$company = $id ? KCRM_Company::find( $id ) : null;

			if ( $company ) {
				echo '<h2>' . esc_html( $company->name ) . '</h2>';
				return;
			}
		}

		echo '<h2>' . sprintf( '<a href="%s">%s</a>', esc_url( $this->screen_url() ), esc_html( $label ) ) . '</h2>';
	}

	private function render_overview( $id ) {
		$company = $id ? KCRM_Company::find( $id ) : null;

		if ( ! $company ) {
			echo '<p>' . esc_html__( 'Company not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$customer_count = KCRM_Customer::count_top_level_for_company( $id, KCRM_Customer::STATUS_ACTIVE );

		$all_invoices  = KCRM_Invoice::for_company( $id );
		$balances      = KCRM_Invoice::balances_for( $all_invoices );
		$outstanding   = 0.0;
		$open_invoices = 0;

		foreach ( $all_invoices as $invoice ) {
			if ( KCRM_Invoice::STATUS_VOID === $invoice->status ) {
				continue;
			}
			$due = $balances[ (int) $invoice->id ];
			if ( $due > 0.004 ) {
				$outstanding += $due;
				$open_invoices++;
			}
		}

		$current_year      = (int) current_time( 'Y' );
		$revenue_this_year = KCRM_Payment::total_for_company_in_year( $id, $current_year );
		?>
		<div class="kcrm-overview-columns">
			<div class="kcrm-overview-cards-col">
				<div class="kcrm-button-group">
					<a class="kcrm-button" href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $id ) ) ); ?>"><span class="dashicons dashicons-edit"></span> <?php esc_html_e( 'Edit Company', 'karks-crm' ); ?></a>
					<a class="kcrm-button" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'customers', array( 'view' => 'add' ) ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Customer', 'karks-crm' ); ?></a>
					<a class="kcrm-button" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'add' ) ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Invoice', 'karks-crm' ); ?></a>
					<a class="kcrm-button" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'services', array( 'view' => 'add' ) ) ) ); ?>"><span class="dashicons dashicons-plus-alt2"></span> <?php esc_html_e( 'Add Service', 'karks-crm' ); ?></a>
				</div>

				<div class="kcrm-dashboard-cards">
					<a class="kcrm-card" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'customers' ) ) ); ?>">
						<span class="dashicons dashicons-groups kcrm-card-icon"></span>
						<span class="kcrm-card-number"><?php echo esc_html( $customer_count ); ?></span>
						<span class="kcrm-card-label"><?php esc_html_e( 'Active Customers', 'karks-crm' ); ?> <span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span></span>
					</a>
					<a class="kcrm-card" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'invoices' ) ) ); ?>">
						<span class="dashicons dashicons-portfolio kcrm-card-icon"></span>
						<span class="kcrm-card-number"><?php echo esc_html( $open_invoices ); ?></span>
						<span class="kcrm-card-label"><?php esc_html_e( 'Open Invoices', 'karks-crm' ); ?> <span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span></span>
					</a>
					<a class="kcrm-card" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'reports', array( 'view' => 'aging' ) ) ) ); ?>">
						<span class="dashicons dashicons-warning kcrm-card-icon"></span>
						<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $outstanding, 2 ) ); ?></span>
						<span class="kcrm-card-label"><?php esc_html_e( 'Outstanding Balance', 'karks-crm' ); ?> <span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span></span>
					</a>
					<a class="kcrm-card" href="<?php echo esc_url( KCRM_Context::switch_company_url( $id, KCRM_Front::endpoint_url( 'reports', array( 'view' => 'revenue' ) ) ) ); ?>">
						<span class="dashicons dashicons-chart-line kcrm-card-icon"></span>
						<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $revenue_this_year, 2 ) ); ?></span>
						<span class="kcrm-card-label">
							<?php
							/* translators: %d: calendar year. */
							echo esc_html( sprintf( __( '%d Revenue', 'karks-crm' ), $current_year ) );
							?>
							<span class="dashicons dashicons-arrow-right-alt2 kcrm-card-arrow"></span>
						</span>
					</a>
				</div>

				<h3 id="kcrm-customers"><?php esc_html_e( 'Customer Search', 'karks-crm' ); ?></h3>
				<form method="get" action="<?php echo esc_url( KCRM_Front::endpoint_url( 'customers' ) ); ?>" class="kcrm-list-search">
					<input type="hidden" name="kcrm_company" value="<?php echo esc_attr( $id ); ?>">
					<?php wp_nonce_field( 'kcrm_switch_company', '_wpnonce', false ); ?>
					<label for="kcrm-overview-customer-search" class="screen-reader-text"><?php esc_html_e( 'Search customers', 'karks-crm' ); ?></label>
					<input type="search" name="s" id="kcrm-overview-customer-search" placeholder="<?php esc_attr_e( 'Search by company, contact, or email…', 'karks-crm' ); ?>">
					<button type="submit" class="kcrm-button"><?php esc_html_e( 'Search', 'karks-crm' ); ?></button>
				</form>


			</div>

			<div class="kcrm-overview-actions-col">
				<?php $this->render_recent_actions( $id ); ?>
			</div>
		</div>

		
		<?php
	}

	/**
	 * "What happened lately" feed for the company profile -- invoices
	 * created, invoices emailed, payments received, and customers added in
	 * the last 2 days, merged into one reverse-chronological list.
	 */
	private function render_recent_actions( $company_id ) {
		$since   = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - 2 * DAY_IN_SECONDS );
		$actions = array();

		foreach ( KCRM_Invoice::created_since( $company_id, $since ) as $invoice ) {
			$customer   = KCRM_Customer::find( $invoice->customer_id );
			$actions[] = array(
				'time'  => $invoice->created_at,
				'icon'  => 'dashicons-portfolio',
				'label' => $customer
					? sprintf(
						/* translators: 1: invoice number, 2: customer name. */
						__( 'Invoice %1$s created for %2$s', 'karks-crm' ),
						$invoice->invoice_number,
						KCRM_Customer::display_name( $customer )
					)
					: sprintf(
						/* translators: %s: invoice number. */
						__( 'Invoice %s created', 'karks-crm' ),
						$invoice->invoice_number
					),
				'url'   => KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $invoice->id ) ),
			);
		}

		foreach ( KCRM_Invoice_Email::recent_for_company( $company_id, $since ) as $email ) {
			$recipient  = $email->sent_to_name ? $email->sent_to_name : $email->sent_to_email;
			$actions[] = array(
				'time'  => $email->sent_at,
				'icon'  => 'dashicons-email',
				'label' => sprintf(
					/* translators: 1: invoice number, 2: recipient name or email. */
					__( 'Invoice %1$s emailed to %2$s', 'karks-crm' ),
					$email->invoice_number,
					$recipient
				),
				'url'   => KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $email->invoice_id ) ),
			);
		}

		foreach ( KCRM_Payment::created_since( $company_id, $since ) as $payment ) {
			$customer  = KCRM_Customer::find( $payment->customer_id );
			$actions[] = array(
				'time'  => $payment->created_at,
				'icon'  => 'dashicons-money-alt',
				'label' => $customer
					? sprintf(
						/* translators: 1: payment amount, 2: invoice number, 3: customer name. */
						__( 'Payment of %1$s received for Invoice %2$s from %3$s', 'karks-crm' ),
						number_format_i18n( (float) $payment->amount, 2 ),
						$payment->invoice_number,
						KCRM_Customer::display_name( $customer )
					)
					: sprintf(
						/* translators: 1: payment amount, 2: invoice number. */
						__( 'Payment of %1$s received for Invoice %2$s', 'karks-crm' ),
						number_format_i18n( (float) $payment->amount, 2 ),
						$payment->invoice_number
					),
				'url'   => KCRM_Front::endpoint_url( 'invoices', array( 'view' => 'edit', 'id' => $payment->invoice_id ) ),
			);
		}

		foreach ( KCRM_Customer::created_since( $company_id, $since ) as $customer ) {
			$actions[] = array(
				'time'  => $customer->created_at,
				'icon'  => 'dashicons-groups',
				'label' => sprintf(
					/* translators: %s: customer/company name. */
					__( 'New customer added: %s', 'karks-crm' ),
					KCRM_Customer::display_name( $customer )
				),
				'url'   => KCRM_Front::endpoint_url( 'customers', $this->nav_nonce_args( array( 'view' => 'edit', 'id' => $customer->id ) ) ),
			);
		}

		usort(
			$actions,
			function ( $a, $b ) {
				return strtotime( $b['time'] ) <=> strtotime( $a['time'] );
			}
		);
		?>
		<h3 id="kcrm-recent-actions"><?php esc_html_e( 'Recent Actions', 'karks-crm' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Activity from the past 2 days.', 'karks-crm' ); ?></p>
		<?php if ( empty( $actions ) ) : ?>
			<p><?php esc_html_e( 'No recent activity.', 'karks-crm' ); ?></p>
		<?php else : ?>
			<?php
			$visible_limit = 10;
			$hidden_count  = max( 0, count( $actions ) - $visible_limit );
			$more_label    = sprintf(
				/* translators: %d: number of additional recent actions hidden by default. */
				__( 'Show %d more', 'karks-crm' ),
				$hidden_count
			);
			?>
			<div class="kcrm-recent-actions-wrap">
				<ul class="kcrm-recent-actions">
					<?php foreach ( $actions as $index => $action ) : ?>
						<li<?php echo $index >= $visible_limit ? ' class="kcrm-recent-actions-extra"' : ''; ?>>
							<span class="dashicons <?php echo esc_attr( $action['icon'] ); ?>"></span>
							<?php if ( $action['url'] ) : ?>
								<a href="<?php echo esc_url( $action['url'] ); ?>"><?php echo esc_html( $action['label'] ); ?></a>
							<?php else : ?>
								<?php echo esc_html( $action['label'] ); ?>
							<?php endif; ?>
							<span class="kcrm-recent-actions-time"><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $action['time'] ) ) ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
				<?php if ( $hidden_count > 0 ) : ?>
					<p>
						<a href="#" class="kcrm-recent-actions-toggle" data-kcrm-more-label="<?php echo esc_attr( $more_label ); ?>" data-kcrm-less-label="<?php esc_attr_e( 'Show less', 'karks-crm' ); ?>">
							<span class="kcrm-recent-actions-toggle-label"><?php echo esc_html( $more_label ); ?></span>
							<span class="dashicons dashicons-arrow-down-alt2"></span>
						</a>
					</p>
				<?php endif; ?>
			</div>
		<?php endif;
	}


	private function render_list() {
		$companies = KCRM_Company::all_ordered();
		?>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Email', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Invoice Prefix', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $companies ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No companies yet. Add your first one to get started.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $companies as $company ) : ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( KCRM_Context::switch_company_url( $company->id, $this->screen_url( array( 'view' => 'overview', 'id' => $company->id ) ) ) ); ?>">
									<?php echo esc_html( $company->name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $company->email ); ?></td>
						<td><?php echo esc_html( $company->phone ); ?></td>
						<td><?php echo esc_html( $company->invoice_prefix ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $company->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $company->id ) ), 'kcrm_delete_company_' . $company->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this company and switch to another? Customers, services, and invoices under it will remain in the database but hidden.', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_form( $view ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$company = $id ? KCRM_Company::find( $id ) : null;

		if ( 'edit' === $view && ! $company ) {
			echo '<p>' . esc_html__( 'Company not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$v = function ( $field, $default = '' ) use ( $company ) {
			return $company ? $company->$field : $default;
		};
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_save_company' ); ?>
			<input type="hidden" name="kcrm_action" value="save_company">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<p>
				<label for="name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label>
				<input type="text" name="name" id="name" value="<?php echo esc_attr( $v( 'name' ) ); ?>" required>
			</p>
			<p>
				<label for="email"><?php esc_html_e( 'Email', 'karks-crm' ); ?></label>
				<input type="email" name="email" id="email" value="<?php echo esc_attr( $v( 'email' ) ); ?>">
			</p>
			<p>
				<label for="phone"><?php esc_html_e( 'Phone', 'karks-crm' ); ?></label>
				<input type="text" name="phone" id="phone" value="<?php echo esc_attr( $v( 'phone' ) ); ?>">
			</p>
			<p>
				<label for="address_street"><?php esc_html_e( 'Street Address', 'karks-crm' ); ?></label>
				<input type="text" name="address_street" id="address_street" value="<?php echo esc_attr( $v( 'address_street' ) ); ?>">
			</p>
			<p>
				<label for="address_street_2"><?php esc_html_e( 'Street Address 2', 'karks-crm' ); ?></label>
				<input type="text" name="address_street_2" id="address_street_2" value="<?php echo esc_attr( $v( 'address_street_2' ) ); ?>">
			</p>
			<p>
				<label for="address_city"><?php esc_html_e( 'City', 'karks-crm' ); ?></label>
				<input type="text" name="address_city" id="address_city" value="<?php echo esc_attr( $v( 'address_city' ) ); ?>">
			</p>
			<p>
				<label for="address_state"><?php esc_html_e( 'State', 'karks-crm' ); ?></label>
				<input type="text" name="address_state" id="address_state" value="<?php echo esc_attr( $v( 'address_state' ) ); ?>">
			</p>
			<p>
				<label for="address_postal_code"><?php esc_html_e( 'Postal Code', 'karks-crm' ); ?></label>
				<input type="text" name="address_postal_code" id="address_postal_code" value="<?php echo esc_attr( $v( 'address_postal_code' ) ); ?>">
			</p>
			<p>
				<label for="address_country"><?php esc_html_e( 'Country', 'karks-crm' ); ?></label>
				<select name="address_country" id="address_country">
					<?php foreach ( KCRM_Countries::list() as $code => $label ) : ?>
						<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $v( 'address_country', KCRM_Countries::DEFAULT_CODE ), $code ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="logo_attachment_id"><?php esc_html_e( 'Logo', 'karks-crm' ); ?></label>
				<?php $logo_id = $company ? (int) $company->logo_attachment_id : 0; ?>
				<span id="kcrm-logo-preview" style="display:block;margin-bottom:8px;">
					<?php if ( $logo_id ) : ?>
						<?php echo wp_get_attachment_image( $logo_id, array( 150, 150 ) ); ?>
					<?php endif; ?>
				</span>
				<input type="hidden" name="logo_attachment_id" id="logo_attachment_id" value="<?php echo esc_attr( $logo_id ); ?>">
				<button type="button" class="kcrm-button" id="kcrm-select-logo" data-kcrm-media-title="<?php echo esc_attr__( 'Select Company Logo', 'karks-crm' ); ?>"><?php esc_html_e( 'Select Logo', 'karks-crm' ); ?></button>
				<button type="button" class="kcrm-button" id="kcrm-remove-logo" style="<?php echo $logo_id ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Remove', 'karks-crm' ); ?></button>
				<br><small><?php esc_html_e( 'Appears on PDF invoices for this company.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="invoice_prefix"><?php esc_html_e( 'Invoice Number Prefix', 'karks-crm' ); ?></label>
				<input type="text" name="invoice_prefix" id="invoice_prefix" value="<?php echo esc_attr( $v( 'invoice_prefix', 'INV-' ) ); ?>">
			</p>
			<p>
				<label for="next_invoice_number"><?php esc_html_e( 'Next Invoice Number', 'karks-crm' ); ?></label>
				<input type="number" step="1" min="1" name="next_invoice_number" id="next_invoice_number" value="<?php echo esc_attr( $v( 'next_invoice_number', '1' ) ); ?>">
				<br><small><?php esc_html_e( 'The number that will be assigned to the next invoice created for this company (combined with the prefix above). Set this once to choose a starting number; it then advances automatically.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="default_tax_rate"><?php esc_html_e( 'Default Tax Rate (%)', 'karks-crm' ); ?></label>
				<input type="number" step="0.001" min="0" name="default_tax_rate" id="default_tax_rate" value="<?php echo esc_attr( $v( 'default_tax_rate', '0' ) ); ?>">
			</p>
			<p>
				<label for="currency"><?php esc_html_e( 'Currency Symbol', 'karks-crm' ); ?></label>
				<input type="text" name="currency" id="currency" value="<?php echo esc_attr( $v( 'currency', 'USD' ) ); ?>" maxlength="10">
			</p>
			<p>
				<label for="invoice_footer"><?php esc_html_e( 'Invoice Footer', 'karks-crm' ); ?></label>
				<?php
				wp_editor(
					$company ? $company->invoice_footer : '',
					'invoice_footer',
					array(
						'textarea_name' => 'invoice_footer',
						'textarea_rows' => 6,
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => true,
						// TinyMCE's Visual tab silently "smart-quotes" straight " typed into it (e.g. inside a hand-typed <a href="...">), corrupting raw HTML/merge tags with no way to fix it from that tab -- Text/quicktags-only avoids this entirely.
						'tinymce'       => false,
					)
				);
				?>
				<small><?php esc_html_e( 'Custom content shown at the bottom of every PDF invoice for this company (e.g. payment terms, bank details, a thank-you note).', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label><?php esc_html_e( 'Accepted Payment Types', 'karks-crm' ); ?></label>
				<?php $kcrm_accepted_types = KCRM_Company::accepted_payment_type_keys( $company ); ?>
				<?php foreach ( KCRM_Company::payment_types() as $kcrm_type_key => $kcrm_type_label ) : ?>
					<label style="display:inline-block;font-weight:normal;margin:0 16px 8px 0;">
						<input type="checkbox" name="accepted_payment_types[]" class="kcrm-payment-type-checkbox" data-type="<?php echo esc_attr( $kcrm_type_key ); ?>" value="<?php echo esc_attr( $kcrm_type_key ); ?>" <?php checked( in_array( $kcrm_type_key, $kcrm_accepted_types, true ) ); ?>>
						<?php echo esc_html( $kcrm_type_label ); ?>
					</label>
				<?php endforeach; ?>
			</p>
			<p id="kcrm-check-payable-to-row" style="<?php echo in_array( 'check', $kcrm_accepted_types, true ) ? '' : 'display:none;'; ?>">
				<label for="check_payable_to"><?php esc_html_e( 'Make Checks Payable To', 'karks-crm' ); ?></label>
				<input type="text" name="check_payable_to" id="check_payable_to" value="<?php echo esc_attr( $company ? $company->check_payable_to : '' ); ?>">
				<br><small><?php esc_html_e( 'Printed in larger type on PDF invoices so checks aren\'t mistakenly made out to the wrong name.', 'karks-crm' ); ?></small>
			</p>
			<p id="kcrm-other-payment-instructions-row" style="<?php echo in_array( 'other', $kcrm_accepted_types, true ) ? '' : 'display:none;'; ?>">
				<label for="other_payment_instructions"><?php esc_html_e( 'Other Payment Instructions', 'karks-crm' ); ?></label>
				<input type="text" name="other_payment_instructions" id="other_payment_instructions" value="<?php echo esc_attr( $company ? $company->other_payment_instructions : '' ); ?>">
				<br><small><?php esc_html_e( 'Shown on invoices to explain the "Other" payment method (e.g. wire transfer details, a payment app handle).', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label><?php esc_html_e( 'Payment Links', 'karks-crm' ); ?></label>
				<table class="kcrm-front-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Label', 'karks-crm' ); ?></th>
							<th><?php esc_html_e( 'URL', 'karks-crm' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody id="kcrm-payment-links-body">
						<?php
						$kcrm_payment_links = KCRM_Company::payment_links( $company );
						if ( empty( $kcrm_payment_links ) ) {
							$kcrm_payment_links = array( array( 'label' => '', 'url' => '' ) );
						}
						?>
						<?php foreach ( $kcrm_payment_links as $kcrm_link ) : ?>
							<tr class="kcrm-payment-link-row">
								<td><input type="text" name="payment_link_label[]" value="<?php echo esc_attr( $kcrm_link['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'e.g. PayPal', 'karks-crm' ); ?>"></td>
								<td><input type="url" name="payment_link_url[]" value="<?php echo esc_attr( $kcrm_link['url'] ?? '' ); ?>" placeholder="https://"></td>
								<td><button type="button" class="kcrm-button kcrm-remove-payment-link">&times;</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<button type="button" class="kcrm-button" id="kcrm-add-payment-link"><?php esc_html_e( '+ Add Link', 'karks-crm' ); ?></button>
				<br><small><?php esc_html_e( 'Shown on invoices as quick ways for customers to pay online (e.g. a PayPal.me link, Stripe payment link).', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="pdf_accent_color"><?php esc_html_e( 'PDF Accent Color', 'karks-crm' ); ?></label>
				<input type="text" class="kcrm-color-picker" name="pdf_accent_color" id="pdf_accent_color" value="<?php echo esc_attr( $company ? $company->pdf_accent_color : '' ); ?>" data-default-color="<?php echo esc_attr( KCRM_Colors::get()['primary'] ); ?>">
				<br><small><?php esc_html_e( 'Used for the invoice title and totals on this company\'s PDF invoices. Leave blank to use the global Appearance color.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label for="email_template"><?php esc_html_e( 'Email Invoice Template', 'karks-crm' ); ?></label>
				<?php
				wp_editor(
					KCRM_Company::email_template( $company ),
					'email_template',
					array(
						'textarea_name' => 'email_template',
						'textarea_rows' => 10,
						'media_buttons' => false,
						'teeny'         => true,
						'quicktags'     => true,
						// TinyMCE's Visual tab silently "smart-quotes" straight " typed into it (e.g. inside a hand-typed <a href="...">), corrupting raw HTML/merge tags with no way to fix it from that tab -- Text/quicktags-only avoids this entirely.
						'tinymce'       => false,
					)
				);
				?>
				<small>
					<?php esc_html_e( 'Pre-fills the body when using "Email Invoice." Shown below is the default wording -- edit it to customize. Available merge tags:', 'karks-crm' ); ?>
					<?php foreach ( array_keys( KCRM_Merge_Tags::tags() ) as $kcrm_tag ) : ?>
						<code>{{<?php echo esc_html( $kcrm_tag ); ?>}}</code>
					<?php endforeach; ?>
				</small>
			</p>
			<p>
				<label>
					<input type="checkbox" name="invoice_bcc_enabled" id="invoice_bcc_enabled" value="1" <?php checked( $company && $company->invoice_bcc_enabled ); ?>>
					<?php esc_html_e( 'Send a BCC copy of every "Email Invoice" send to an address below', 'karks-crm' ); ?>
				</label>
				<br><small><?php esc_html_e( 'Off by default. Useful for keeping a copy of every invoice you send, e.g. to yourself.', 'karks-crm' ); ?></small>
			</p>
			<p id="kcrm-invoice-bcc-email-row">
				<label for="invoice_bcc_email"><?php esc_html_e( 'BCC Email', 'karks-crm' ); ?></label>
				<input type="email" name="invoice_bcc_email" id="invoice_bcc_email" value="<?php echo esc_attr( $company ? $company->invoice_bcc_email : '' ); ?>" placeholder="you@example.com">
			</p>

			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo esc_html( $id ? __( 'Update Company', 'karks-crm' ) : __( 'Add Company', 'karks-crm' ) ); ?></button></p>
		</form>
		<?php
	}
}
