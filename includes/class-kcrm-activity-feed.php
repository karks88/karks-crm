<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns KCRM_Activity rows into what's shown on screen (icon, label, link)
 * and renders them -- shared by the company profile's Recent Actions feed,
 * the Activity Log screen, and the customer profile's Activity tab, so all
 * three read identically.
 */
class KCRM_Activity_Feed {

	/**
	 * Describes a page of rows. Links are only built for records that still
	 * exist, checked with one find_many() per record type for the whole
	 * page rather than one find() per row.
	 *
	 * @param object[] $rows    KCRM_Activity rows.
	 * @param string   $context 'front' links to the /crm/ endpoints, 'admin' to the wp-admin screens.
	 * @return array[] Each: array( 'time' => 'Y-m-d H:i:s', 'icon' => string, 'label' => string, 'url' => string|null ).
	 */
	public static function describe_rows( array $rows, $context = 'front' ) {
		$decoded = array();
		$wanted  = array(
			'invoice'  => array(),
			'customer' => array(),
			'service'  => array(),
		);

		foreach ( $rows as $index => $row ) {
			$details           = json_decode( (string) $row->details, true );
			$details           = is_array( $details ) ? $details : array();
			$decoded[ $index ] = $details;

			$target = self::link_target( $row, $details );
			if ( $target ) {
				$wanted[ $target[0] ][] = $target[1];
			}
		}

		$existing = array(
			'invoice'  => KCRM_Invoice::find_many( $wanted['invoice'] ),
			'customer' => KCRM_Customer::find_many( $wanted['customer'] ),
			'service'  => KCRM_Service::find_many( $wanted['service'] ),
		);

		$items = array();
		foreach ( $rows as $index => $row ) {
			$details = $decoded[ $index ];
			$target  = self::link_target( $row, $details );
			$url     = null;
			if ( $target && isset( $existing[ $target[0] ][ $target[1] ] ) ) {
				$url = self::record_url( $target[0], $target[1], $context );
			}

			$items[] = array(
				'time'  => $row->created_at,
				'icon'  => self::icon( $row->event ),
				'label' => self::label( $row->event, $details ),
				'url'   => $url,
			);
		}

		return $items;
	}

	/** Edit-screen URL for an invoice/customer/service, on the front end or in wp-admin. */
	private static function record_url( $type, $id, $context ) {
		$controllers = array(
			'invoice'  => 'KCRM_Invoices_Controller',
			'customer' => 'KCRM_Customers_Controller',
			'service'  => 'KCRM_Services_Controller',
		);
		$controller  = $controllers[ $type ];
		$args        = array(
			'view' => 'edit',
			'id'   => $id,
		);

		if ( 'admin' === $context ) {
			return add_query_arg( array_merge( array( 'page' => $controller::PAGE ), $args ), admin_url( 'admin.php' ) );
		}
		return KCRM_Front::endpoint_url( $controller::ENDPOINT, KCRM_Front::nav_nonce_args( $args ) );
	}

	/**
	 * Which record an entry links to, if any. Deletions never link -- the
	 * record is gone by definition.
	 *
	 * @return array{0: string, 1: int}|null [ record type, id ]
	 */
	private static function link_target( $row, array $details ) {
		if ( '.deleted' === substr( $row->event, -8 ) && 'payment.deleted' !== $row->event ) {
			return null;
		}

		switch ( $row->object_type ) {
			case 'invoice':
			case 'payment':
				$id = absint( $details['invoice_id'] ?? 0 );
				return $id ? array( 'invoice', $id ) : null;
			case 'customer':
			case 'service':
				$id = absint( $row->object_id );
				return $id ? array( $row->object_type, $id ) : null;
		}
		return null;
	}

	private static function icon( $event ) {
		if ( '.deleted' === substr( $event, -8 ) || 'log.cleared' === $event ) {
			return 'dashicons-trash';
		}
		if ( 0 === strpos( $event, 'import.' ) || 'company.imported' === $event ) {
			return 'dashicons-upload';
		}

		$icons = array(
			'invoice.emailed'            => 'dashicons-email',
			'invoice.status_changed'     => 'dashicons-flag',
			'company.exported'           => 'dashicons-download',
			'settings.retention_changed' => 'dashicons-admin-generic',
		);
		if ( isset( $icons[ $event ] ) ) {
			return $icons[ $event ];
		}

		$by_type = array(
			'invoice'  => 'dashicons-portfolio',
			'payment'  => 'dashicons-money-alt',
			'customer' => 'dashicons-groups',
			'service'  => 'dashicons-hammer',
			'company'  => 'dashicons-building',
		);
		$type = strtok( $event, '.' );
		return $by_type[ $type ] ?? 'dashicons-marker';
	}

	/** Plain-text label (escaped by whoever outputs it) built from the snapshot in $details. */
	private static function label( $event, array $d ) {
		$number   = (string) ( $d['invoice_number'] ?? '' );
		$customer = (string) ( $d['customer_name'] ?? '' );
		$amount   = number_format_i18n( (float) ( $d['amount'] ?? 0 ), 2 );
		$count    = (int) ( $d['count'] ?? 0 );

		switch ( $event ) {
			case 'invoice.created':
				/* translators: 1: invoice number, 2: customer name. */
				return $customer ? sprintf( __( 'Invoice %1$s created for %2$s', 'karks-crm' ), $number, $customer )
					/* translators: %s: invoice number. */
					: sprintf( __( 'Invoice %s created', 'karks-crm' ), $number );

			case 'invoice.updated':
				/* translators: 1: invoice number, 2: customer name. */
				return $customer ? sprintf( __( 'Invoice %1$s for %2$s updated', 'karks-crm' ), $number, $customer )
					/* translators: %s: invoice number. */
					: sprintf( __( 'Invoice %s updated', 'karks-crm' ), $number );

			case 'invoice.deleted':
				/* translators: 1: invoice number, 2: customer name. */
				return $customer ? sprintf( __( 'Invoice %1$s for %2$s deleted', 'karks-crm' ), $number, $customer )
					/* translators: %s: invoice number. */
					: sprintf( __( 'Invoice %s deleted', 'karks-crm' ), $number );

			case 'invoice.status_changed':
				$statuses = KCRM_Invoice::statuses();
				$from     = $statuses[ $d['from'] ?? '' ] ?? (string) ( $d['from'] ?? '' );
				$to       = $statuses[ $d['to'] ?? '' ] ?? (string) ( $d['to'] ?? '' );
				/* translators: 1: invoice number, 2: previous status, 3: new status. */
				return sprintf( __( 'Invoice %1$s changed from %2$s to %3$s', 'karks-crm' ), $number, $from, $to );

			case 'invoice.emailed':
				$recipient = (string) ( $d['recipient_name'] ?? '' );
				/* translators: 1: invoice number, 2: recipient name. */
				return $recipient ? sprintf( __( 'Invoice %1$s emailed to %2$s', 'karks-crm' ), $number, $recipient )
					/* translators: %s: invoice number. */
					: sprintf( __( 'Invoice %s emailed', 'karks-crm' ), $number );

			case 'payment.created':
				/* translators: 1: payment amount, 2: invoice number, 3: customer name. */
				return $customer ? sprintf( __( 'Payment of %1$s received for Invoice %2$s from %3$s', 'karks-crm' ), $amount, $number, $customer )
					/* translators: 1: payment amount, 2: invoice number. */
					: sprintf( __( 'Payment of %1$s received for Invoice %2$s', 'karks-crm' ), $amount, $number );

			case 'payment.deleted':
				/* translators: 1: payment amount, 2: invoice number. */
				return sprintf( __( 'Payment of %1$s on Invoice %2$s deleted', 'karks-crm' ), $amount, $number );

			case 'customer.created':
				/* translators: %s: Job name. */
				return ! empty( $d['is_job'] ) ? sprintf( __( 'New Job added: %s', 'karks-crm' ), $customer )
					/* translators: %s: customer name. */
					: sprintf( __( 'New customer added: %s', 'karks-crm' ), $customer );

			case 'customer.updated':
				/* translators: %s: Job name. */
				return ! empty( $d['is_job'] ) ? sprintf( __( 'Job updated: %s', 'karks-crm' ), $customer )
					/* translators: %s: customer name. */
					: sprintf( __( 'Customer updated: %s', 'karks-crm' ), $customer );

			case 'customer.deleted':
				if ( ! empty( $d['is_job'] ) ) {
					/* translators: %s: Job name. */
					return sprintf( __( 'Job deleted: %s', 'karks-crm' ), $customer );
				}
				$jobs = (int) ( $d['jobs'] ?? 0 );
				return $jobs
					/* translators: 1: customer name, 2: number of Jobs deleted along with it. */
					? sprintf( _n( 'Customer deleted: %1$s (and %2$d Job)', 'Customer deleted: %1$s (and %2$d Jobs)', $jobs, 'karks-crm' ), $customer, $jobs )
					/* translators: %s: customer name. */
					: sprintf( __( 'Customer deleted: %s', 'karks-crm' ), $customer );

			case 'service.created':
				/* translators: %s: service name. */
				return sprintf( __( 'New service added: %s', 'karks-crm' ), (string) ( $d['service_name'] ?? '' ) );

			case 'service.updated':
				/* translators: %s: service name. */
				return sprintf( __( 'Service updated: %s', 'karks-crm' ), (string) ( $d['service_name'] ?? '' ) );

			case 'service.deleted':
				/* translators: %s: service name. */
				return sprintf( __( 'Service deleted: %s', 'karks-crm' ), (string) ( $d['service_name'] ?? '' ) );

			case 'company.updated':
				return __( 'Company profile updated', 'karks-crm' );

			case 'company.imported':
				return sprintf(
					/* translators: 1: customers imported, 2: invoices imported, 3: payments imported. */
					__( 'Company imported from a JSON export (%1$d customers, %2$d invoices, %3$d payments)', 'karks-crm' ),
					(int) ( $d['customers'] ?? 0 ),
					(int) ( $d['invoices'] ?? 0 ),
					(int) ( $d['payments'] ?? 0 )
				);

			case 'company.exported':
				return __( 'Company data exported', 'karks-crm' );

			case 'import.customers':
				/* translators: %d: number of customers imported. */
				return sprintf( _n( '%d customer imported from CSV', '%d customers imported from CSV', $count, 'karks-crm' ), $count );

			case 'import.services':
				/* translators: %d: number of services imported. */
				return sprintf( _n( '%d service imported from CSV', '%d services imported from CSV', $count, 'karks-crm' ), $count );

			case 'import.invoices':
				/* translators: %d: number of invoices imported. */
				return sprintf( _n( '%d invoice imported from CSV', '%d invoices imported from CSV', $count, 'karks-crm' ), $count );

			case 'import.payments':
				/* translators: %d: number of payments imported. */
				return sprintf( _n( '%d payment imported from CSV', '%d payments imported from CSV', $count, 'karks-crm' ), $count );

			case 'settings.retention_changed':
				return sprintf(
					/* translators: 1: previous retention period, 2: new retention period. */
					__( 'Activity log retention changed from %1$s to %2$s', 'karks-crm' ),
					self::retention_label( (int) ( $d['from'] ?? 0 ) ),
					self::retention_label( (int) ( $d['to'] ?? 0 ) )
				);

			case 'log.cleared':
				return __( 'Activity log cleared', 'karks-crm' );
		}

		return (string) $event;
	}

	/** "24 months" / "Forever" for a retention value. */
	public static function retention_label( $months ) {
		return $months
			/* translators: %d: number of months. */
			? sprintf( _n( '%d month', '%d months', $months, 'karks-crm' ), $months )
			: __( 'Forever', 'karks-crm' );
	}

	/**
	 * Renders described items as the shared Recent Actions-style list.
	 *
	 * @param array[] $items         From describe_rows().
	 * @param int     $visible_limit Items past this are hidden behind the existing "Show N more" toggle (0 = show all).
	 * @param bool    $time_only     Show just the time (for lists already grouped under a day heading) instead of date + time.
	 */
	public static function render_list( array $items, $visible_limit = 0, $time_only = false ) {
		$format = $time_only ? get_option( 'time_format' ) : get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
		?>
		<ul class="kcrm-recent-actions">
			<?php foreach ( array_values( $items ) as $index => $item ) : ?>
				<li<?php echo ( $visible_limit && $index >= $visible_limit ) ? ' class="kcrm-recent-actions-extra"' : ''; ?>>
					<span class="dashicons <?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
					<?php if ( $item['url'] ) : ?>
						<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
					<?php else : ?>
						<span><?php echo esc_html( $item['label'] ); ?></span>
					<?php endif; ?>
					<span class="kcrm-recent-actions-time"><?php echo esc_html( date_i18n( $format, strtotime( $item['time'] ) ) ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Renders items grouped under Today / Yesterday / date headings.
	 *
	 * @param array[] $items From describe_rows(), newest first.
	 */
	public static function render_grouped_by_day( array $items ) {
		$today     = current_time( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' -1 day' ) );

		$days = array();
		foreach ( $items as $item ) {
			$days[ substr( $item['time'], 0, 10 ) ][] = $item;
		}

		foreach ( $days as $day => $day_items ) {
			if ( $day === $today ) {
				$heading = __( 'Today', 'karks-crm' );
			} elseif ( $day === $yesterday ) {
				$heading = __( 'Yesterday', 'karks-crm' );
			} else {
				$heading = date_i18n( get_option( 'date_format' ), strtotime( $day ) );
			}
			echo '<h4 class="kcrm-activity-day">' . esc_html( $heading ) . '</h4>';
			self::render_list( $day_items, 0, true );
		}
	}
}
