<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Admin_Customers extends KCRM_Admin_Base {

	const PAGE = 'karks-crm-customers';

	public function handle_actions() {
		if ( ! isset( $_GET['page'] ) || self::PAGE !== $_GET['page'] ) {
			return;
		}

		if ( isset( $_POST['kcrm_action'] ) && 'save_customer' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		if ( isset( $_POST['kcrm_action'] ) && 'import_upload' === $_POST['kcrm_action'] ) {
			$this->handle_import_upload();
		}

		if ( isset( $_POST['kcrm_action'] ) && 'import_run' === $_POST['kcrm_action'] ) {
			$this->handle_import_run();
		}

		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			$this->delete();
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_customer' );

		$id         = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$company_id = $this->current_company_id();

		if ( ! $company_id ) {
			$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'no_company' ) );
		}

		$status = sanitize_key( wp_unslash( $_POST['status'] ?? KCRM_Customer::STATUS_ACTIVE ) );
		if ( ! array_key_exists( $status, KCRM_Customer::statuses() ) ) {
			$status = KCRM_Customer::STATUS_ACTIVE;
		}

		$parent_id = $this->validated_parent_id( $id, $company_id );

		$data = array(
			'company_id'               => $company_id,
			'parent_customer_id'       => $parent_id ?: null,
			'company_name'             => sanitize_text_field( wp_unslash( $_POST['company_name'] ?? '' ) ),
			'contact_person'           => sanitize_text_field( wp_unslash( $_POST['contact_person'] ?? '' ) ),
			'secondary_contact_person' => sanitize_text_field( wp_unslash( $_POST['secondary_contact_person'] ?? '' ) ),
			'address_street'           => sanitize_text_field( wp_unslash( $_POST['address_street'] ?? '' ) ),
			'address_city'             => sanitize_text_field( wp_unslash( $_POST['address_city'] ?? '' ) ),
			'address_state'            => sanitize_text_field( wp_unslash( $_POST['address_state'] ?? '' ) ),
			'address_postal_code'      => sanitize_text_field( wp_unslash( $_POST['address_postal_code'] ?? '' ) ),
			'phone'                    => sanitize_text_field( wp_unslash( $_POST['phone'] ?? '' ) ),
			'email'                    => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ),
			'secondary_email'          => sanitize_email( wp_unslash( $_POST['secondary_email'] ?? '' ) ),
			'notes'                    => sanitize_textarea_field( wp_unslash( $_POST['notes'] ?? '' ) ),
			'status'                   => $status,
		);

		if ( '' === $data['company_name'] ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => $id ? 'edit' : 'add', 'id' => $id, 'kcrm_notice' => 'error' ) );
		}

		if ( $id ) {
			unset( $data['company_id'] );
			KCRM_Customer::save( $id, $data );
		} else {
			$id = KCRM_Customer::create( $data );
		}

		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'saved' ) );
	}

	private function delete() {
		$id = absint( $_GET['id'] );
		check_admin_referer( 'kcrm_delete_customer_' . $id );

		foreach ( KCRM_Customer::jobs_for( $id ) as $job ) {
			KCRM_Customer::delete( $job->id );
		}
		KCRM_Customer::delete( $id );

		$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'deleted' ) );
	}

	/**
	 * A customer may only become a Job of a *top-level* customer in the same
	 * company (no more than one level of nesting), and can't become a Job
	 * of itself or of one of its own Jobs, and can't be assigned a parent
	 * while it already has Jobs of its own.
	 */
	private function validated_parent_id( $id, $company_id ) {
		$parent_id = isset( $_POST['parent_customer_id'] ) ? absint( $_POST['parent_customer_id'] ) : 0;

		if ( ! $parent_id ) {
			return 0;
		}

		if ( $id && ( $parent_id === $id || ! empty( KCRM_Customer::jobs_for( $id ) ) ) ) {
			return 0;
		}

		$parent = KCRM_Customer::find( $parent_id );
		if ( ! $parent || (int) $parent->company_id !== $company_id || ! empty( $parent->parent_customer_id ) ) {
			return 0;
		}

		return $parent_id;
	}

	private function handle_import_upload() {
		check_admin_referer( 'kcrm_import_upload' );

		if ( ! $this->current_company_id() ) {
			$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'no_company' ) );
		}

		$token = KCRM_CSV_Import::store_upload( $_FILES['import_file'] ?? array() );

		if ( is_wp_error( $token ) ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => 'import', 'kcrm_notice' => 'error' ) );
		}

		$this->redirect( array( 'page' => self::PAGE, 'view' => 'import', 'stage' => 'map', 'file' => $token ) );
	}

	private function handle_import_run() {
		check_admin_referer( 'kcrm_import_run' );

		$company_id = $this->current_company_id();
		if ( ! $company_id ) {
			$this->redirect( array( 'page' => self::PAGE, 'kcrm_notice' => 'no_company' ) );
		}

		$token = isset( $_POST['file'] ) ? sanitize_text_field( wp_unslash( $_POST['file'] ) ) : '';
		$path  = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => 'import', 'kcrm_notice' => 'error' ) );
		}

		$map = isset( $_POST['map'] ) ? array_map( 'intval', (array) wp_unslash( $_POST['map'] ) ) : array();

		$company_col = $map['company_name'] ?? -1;
		if ( $company_col < 0 ) {
			$this->redirect( array( 'page' => self::PAGE, 'view' => 'import', 'stage' => 'map', 'file' => $token, 'kcrm_notice' => 'error' ) );
		}

		$rows = KCRM_CSV_Import::read_rows( $path );

		$existing = array();
		foreach ( KCRM_Customer::for_company( $company_id ) as $existing_customer ) {
			$existing[ strtolower( trim( $existing_customer->company_name ) ) ] = true;
		}

		$imported                 = 0;
		$skipped_no_name          = 0;
		$skipped_duplicate_in_file = 0;
		$skipped_existing         = 0;
		$seen_in_file             = array();

		foreach ( $rows as $row ) {
			$company_name = trim( (string) ( $row[ $company_col ] ?? '' ) );

			if ( '' === $company_name ) {
				$skipped_no_name++;
				continue;
			}

			$key = strtolower( $company_name );

			if ( isset( $seen_in_file[ $key ] ) ) {
				$skipped_duplicate_in_file++;
				continue;
			}
			$seen_in_file[ $key ] = true;

			if ( isset( $existing[ $key ] ) ) {
				$skipped_existing++;
				continue;
			}

			$contact_person = $this->mapped_cell( $row, $map, 'contact_person' );
			if ( '' === $contact_person ) {
				// QuickBooks sometimes leaves "Primary Contact" blank and only
				// fills in separate First/Last Name columns instead.
				$contact_person = trim( $this->mapped_cell( $row, $map, 'first_name' ) . ' ' . $this->mapped_cell( $row, $map, 'last_name' ) );
			}

			list( $address_street, $address_city, $address_state, $address_postal_code ) = $this->parse_address_block( $row, $map, $contact_person );

			$data = array(
				'company_id'               => $company_id,
				'company_name'             => sanitize_text_field( $company_name ),
				'contact_person'           => sanitize_text_field( $contact_person ),
				'secondary_contact_person' => $this->mapped_cell( $row, $map, 'secondary_contact_person' ),
				'phone'                    => $this->mapped_cell( $row, $map, 'phone' ),
				'email'                    => sanitize_email( $this->mapped_cell( $row, $map, 'email' ) ),
				'secondary_email'          => sanitize_email( $this->mapped_cell( $row, $map, 'secondary_email' ) ),
				'address_street'           => $address_street,
				'address_city'             => $address_city,
				'address_state'            => $address_state,
				'address_postal_code'      => $address_postal_code,
				'notes'                    => sanitize_textarea_field( $this->mapped_cell( $row, $map, 'notes' ) ),
				'status'                   => $this->parse_status( $this->mapped_cell( $row, $map, 'status_source' ) ),
			);

			KCRM_Customer::create( $data );
			$imported++;
		}

		KCRM_CSV_Import::delete( $token );

		$this->redirect(
			array(
				'page'              => self::PAGE,
				'view'              => 'import',
				'stage'             => 'done',
				'imported'          => $imported,
				'skipped_no_name'   => $skipped_no_name,
				'skipped_duplicate' => $skipped_duplicate_in_file,
				'skipped_existing'  => $skipped_existing,
			)
		);
	}

	private function mapped_cell( array $row, array $map, $field ) {
		$index = $map[ $field ] ?? -1;
		if ( $index < 0 || ! isset( $row[ $index ] ) ) {
			return '';
		}
		return sanitize_text_field( trim( (string) $row[ $index ] ) );
	}

	private function parse_status( $value ) {
		$value = strtolower( trim( $value ) );
		if ( '' === $value ) {
			return KCRM_Customer::STATUS_ACTIVE;
		}
		return ( false !== strpos( $value, 'inactive' ) ) ? KCRM_Customer::STATUS_INACTIVE : KCRM_Customer::STATUS_ACTIVE;
	}

	/**
	 * QuickBooks-style "Bill to" address blocks are a variable number of
	 * free-form lines (name, attention, street, suite, city/state/zip) whose
	 * position shifts row to row depending on how many lines were used. So
	 * rather than trusting one fixed column per part, this scans every
	 * column in the mapped [from, to] range, finds the last cell that looks
	 * like "City, ST 12345", and treats everything before it as the street
	 * (skipping any line that just repeats the contact person's name).
	 *
	 * @return array [ street, city, state, postal_code ]
	 */
	private function parse_address_block( array $row, array $map, $contact_person ) {
		$from = $map['address_from'] ?? -1;
		$to   = $map['address_to'] ?? -1;

		if ( $from < 0 || $to < 0 || $to < $from ) {
			return array( '', '', '', '' );
		}

		$cells = array();
		for ( $i = $from; $i <= $to; $i++ ) {
			$value = isset( $row[ $i ] ) ? sanitize_text_field( trim( (string) $row[ $i ] ) ) : '';
			if ( '' !== $value ) {
				$cells[ $i ] = $value;
			}
		}

		$city         = '';
		$state        = '';
		$postal       = '';
		$match_index  = null;

		foreach ( array_reverse( $cells, true ) as $i => $value ) {
			if ( preg_match( '/^(.*?),\s*([A-Za-z]{2})\.?\s+(\d{5}(?:-\d{4})?)\s*$/', $value, $matches ) ) {
				$city        = sanitize_text_field( trim( $matches[1] ) );
				$state       = strtoupper( $matches[2] );
				$postal      = sanitize_text_field( $matches[3] );
				$match_index = $i;
				break;
			}
		}

		$street_parts = array();
		foreach ( $cells as $i => $value ) {
			if ( null !== $match_index && $i >= $match_index ) {
				continue;
			}
			if ( '' !== $contact_person && 0 === strcasecmp( $value, $contact_person ) ) {
				continue; // Skip an "Attention: Name" line that just repeats the contact person.
			}
			$street_parts[] = $value;
		}

		return array( sanitize_text_field( implode( ', ', $street_parts ) ), $city, $state, $postal );
	}

	/**
	 * Target customer fields for the CSV importer, with candidate header
	 * names (lowercase) used to guess a default column mapping.
	 */
	private function import_fields() {
		return array(
			'company_name'             => array(
				'label'    => __( 'Company Name', 'karks-crm' ),
				'required' => true,
				'guess'    => array( 'company' ),
			),
			'contact_person'           => array(
				'label' => __( 'Contact Person', 'karks-crm' ),
				'guess' => array( 'primary contact', 'contact person', 'contact' ),
			),
			'first_name'               => array(
				'label' => __( 'First Name (used if Contact Person is blank)', 'karks-crm' ),
				'guess' => array( 'first name' ),
			),
			'last_name'                => array(
				'label' => __( 'Last Name (used if Contact Person is blank)', 'karks-crm' ),
				'guess' => array( 'last name' ),
			),
			'secondary_contact_person' => array(
				'label' => __( 'Secondary Contact Person', 'karks-crm' ),
				'guess' => array( 'secondary contact' ),
			),
			'email'                    => array(
				'label' => __( 'Email Address', 'karks-crm' ),
				'guess' => array( 'main email', 'email' ),
			),
			'secondary_email'          => array(
				'label' => __( 'Secondary Email Address', 'karks-crm' ),
				'guess' => array( 'secondary email' ),
			),
			'phone'                    => array(
				'label' => __( 'Phone Number', 'karks-crm' ),
				'guess' => array( 'main phone', 'phone' ),
			),
			'status_source'            => array(
				'label' => __( 'Status column (e.g. Active/Inactive)', 'karks-crm' ),
				'guess' => array( 'active status', 'status' ),
			),
			'notes'                    => array(
				'label' => __( 'Notes', 'karks-crm' ),
				'guess' => array(),
			),
		);
	}

	/** Finds the first header column matching any candidate (exact match first, then substring). */
	private function guess_column( array $header, array $candidates ) {
		foreach ( $candidates as $candidate ) {
			foreach ( $header as $i => $label ) {
				if ( strtolower( trim( $label ) ) === $candidate ) {
					return $i;
				}
			}
		}
		foreach ( $candidates as $candidate ) {
			foreach ( $header as $i => $label ) {
				if ( '' !== $candidate && false !== strpos( strtolower( $label ), $candidate ) ) {
					return $i;
				}
			}
		}
		return -1;
	}

	/**
	 * Guesses the [from, to] column range for the free-form address block.
	 * QuickBooks-style exports repeat several "Bill to N" columns; the first
	 * one is always the name/company line, so that one is excluded.
	 *
	 * @return array [ from, to ] column indexes, or [ -1, -1 ] if none found.
	 */
	private function guess_address_range( array $header ) {
		$bill_to = array();
		foreach ( $header as $i => $label ) {
			if ( false !== stripos( $label, 'bill to' ) ) {
				$bill_to[] = $i;
			}
		}

		if ( $bill_to ) {
			sort( $bill_to );
			return count( $bill_to ) > 1
				? array( $bill_to[1], end( $bill_to ) )
				: array( $bill_to[0], $bill_to[0] );
		}

		$from = $this->guess_column( $header, array( 'street', 'address' ) );
		return $from < 0 ? array( -1, -1 ) : array( $from, $from );
	}

	public function render() {
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Customers', 'karks-crm' ) . '</h1>';
		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=add' ) ), esc_html__( 'Add New', 'karks-crm' ) );
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=import' ) ), esc_html__( 'Import from CSV', 'karks-crm' ) );
		}
		echo '<hr class="wp-header-end">';

		$this->company_switcher( self::PAGE );
		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Karks CRM → Companies.', 'karks-crm' ) . '</p></div>';
			return;
		}

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} elseif ( 'import' === $view ) {
			$this->render_import();
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_import() {
		$stage = isset( $_GET['stage'] ) ? sanitize_key( $_GET['stage'] ) : 'upload';

		if ( 'done' === $stage ) {
			$this->render_import_done();
		} elseif ( 'map' === $stage && isset( $_GET['file'] ) ) {
			$this->render_import_map( sanitize_text_field( wp_unslash( $_GET['file'] ) ) );
		} else {
			$this->render_import_upload();
		}
	}

	private function render_import_upload() {
		?>
		<h2><?php esc_html_e( 'Import Customers from CSV', 'karks-crm' ); ?></h2>
		<p><?php esc_html_e( "Upload a CSV export (e.g. from QuickBooks) and you'll be able to choose which columns map to which fields before anything is imported. Rows sharing the same company name only import once, and companies that already exist here are skipped automatically — it's safe to re-run.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" enctype="multipart/form-data">
			<?php wp_nonce_field( 'kcrm_import_upload' ); ?>
			<input type="hidden" name="kcrm_action" value="import_upload">
			<table class="form-table">
				<tr>
					<th><label for="import_file"><?php esc_html_e( 'CSV File', 'karks-crm' ); ?></label></th>
					<td><input type="file" name="import_file" id="import_file" accept=".csv" required></td>
				</tr>
			</table>
			<?php submit_button( __( 'Upload & Continue', 'karks-crm' ) ); ?>
		</form>
		<?php
	}

	private function render_import_map( $token ) {
		$path = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			echo '<p>' . esc_html__( 'That upload could not be found — it may have expired. Please upload the file again.', 'karks-crm' ) . '</p>';
			printf( '<p><a class="button" href="%s">%s</a></p>', esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=import' ) ), esc_html__( 'Start Over', 'karks-crm' ) );
			return;
		}

		$header = KCRM_CSV_Import::read_header( $path );
		$fields = $this->import_fields();
		?>
		<h2><?php esc_html_e( 'Map CSV Columns', 'karks-crm' ); ?></h2>
		<p><?php esc_html_e( "Choose which column in your file maps to each customer field. We've guessed a few based on common column names — double check before importing.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<?php wp_nonce_field( 'kcrm_import_run' ); ?>
			<input type="hidden" name="kcrm_action" value="import_run">
			<input type="hidden" name="file" value="<?php echo esc_attr( $token ); ?>">
			<table class="form-table">
				<?php foreach ( $fields as $key => $field ) : ?>
					<?php $guess = $this->guess_column( $header, $field['guess'] ); ?>
					<tr>
						<th>
							<label for="map_<?php echo esc_attr( $key ); ?>">
								<?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' *' : ''; ?>
							</label>
						</th>
						<td>
							<select name="map[<?php echo esc_attr( $key ); ?>]" id="map_<?php echo esc_attr( $key ); ?>">
								<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
								<?php foreach ( $header as $i => $label ) : ?>
									<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $guess, $i ); ?>>
										<?php echo esc_html( '' !== trim( (string) $label ) ? $label : sprintf( __( 'Column %d', 'karks-crm' ), $i + 1 ) ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php list( $address_from_guess, $address_to_guess ) = $this->guess_address_range( $header ); ?>
				<tr>
					<th><?php esc_html_e( 'Address Block', 'karks-crm' ); ?></th>
					<td>
						<?php esc_html_e( 'From', 'karks-crm' ); ?>
						<select name="map[address_from]">
							<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
							<?php foreach ( $header as $i => $label ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $address_from_guess, $i ); ?>>
									<?php echo esc_html( '' !== trim( (string) $label ) ? $label : sprintf( __( 'Column %d', 'karks-crm' ), $i + 1 ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<?php esc_html_e( 'To', 'karks-crm' ); ?>
						<select name="map[address_to]">
							<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
							<?php foreach ( $header as $i => $label ) : ?>
								<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $address_to_guess, $i ); ?>>
									<?php echo esc_html( '' !== trim( (string) $label ) ? $label : sprintf( __( 'Column %d', 'karks-crm' ), $i + 1 ) ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The range of columns making up the address (street, suite, city/state/zip). We scan this range for the line that looks like a city/state/zip and treat everything before it as the street — handles QuickBooks-style address blocks whose line count varies row to row.', 'karks-crm' ); ?></p>
					</td>
				</tr>
			</table>
			<p class="description">* <?php esc_html_e( 'Required. Rows with a blank value in this column are skipped.', 'karks-crm' ); ?></p>
			<?php submit_button( __( 'Import Customers', 'karks-crm' ) ); ?>
		</form>
		<?php
	}

	private function render_import_done() {
		$imported          = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0;
		$skipped_no_name   = isset( $_GET['skipped_no_name'] ) ? absint( $_GET['skipped_no_name'] ) : 0;
		$skipped_duplicate = isset( $_GET['skipped_duplicate'] ) ? absint( $_GET['skipped_duplicate'] ) : 0;
		$skipped_existing  = isset( $_GET['skipped_existing'] ) ? absint( $_GET['skipped_existing'] ) : 0;
		?>
		<h2><?php esc_html_e( 'Import Complete', 'karks-crm' ); ?></h2>
		<ul>
			<li><?php echo esc_html( sprintf( __( '%d customers imported.', 'karks-crm' ), $imported ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( '%d rows skipped — already existed as a customer.', 'karks-crm' ), $skipped_existing ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( '%d rows skipped — duplicate company name within the file.', 'karks-crm' ), $skipped_duplicate ) ); ?></li>
			<li><?php echo esc_html( sprintf( __( '%d rows skipped — no company name in the mapped column.', 'karks-crm' ), $skipped_no_name ) ); ?></li>
		</ul>
		<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>"><?php esc_html_e( 'View Customers', 'karks-crm' ); ?></a></p>
		<?php
	}

	private function render_list() {
		$orderby = isset( $_GET['orderby'] ) && 'status' === $_GET['orderby'] ? 'status' : 'company_name';
		$order   = isset( $_GET['order'] ) && 'desc' === strtolower( $_GET['order'] ) ? 'DESC' : 'ASC';

		$order_by  = 'status' === $orderby ? "status $order, company_name ASC" : "company_name $order";
		$customers = KCRM_Customer::top_level_for_company( $this->current_company_id(), $order_by );
		$statuses  = KCRM_Customer::statuses();

		$status_sort_url = add_query_arg(
			array(
				'page'    => self::PAGE,
				'orderby' => 'status',
				'order'   => ( 'status' === $orderby && 'ASC' === $order ) ? 'desc' : 'asc',
			),
			admin_url( 'admin.php' )
		);
		?>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Email', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Phone', 'karks-crm' ); ?></th>
					<th>
						<a href="<?php echo esc_url( $status_sort_url ); ?>">
							<?php esc_html_e( 'Status', 'karks-crm' ); ?>
							<?php if ( 'status' === $orderby ) : ?>
								<span aria-hidden="true"><?php echo 'ASC' === $order ? '&#9650;' : '&#9660;'; ?></span>
							<?php endif; ?>
						</a>
					</th>
					<th><?php esc_html_e( 'Balance', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $customers ) ) : ?>
					<tr><td colspan="7"><?php esc_html_e( 'No customers yet for this company.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $customers as $customer ) : ?>
					<?php
					$jobs     = KCRM_Customer::jobs_for( $customer->id );
					$job_ids  = wp_list_pluck( $jobs, 'id' );
					$balance  = KCRM_Customer::balance_for_ids( array_merge( array( $customer->id ), $job_ids ) );
					?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $customer->id ) ); ?>">
									<?php echo esc_html( $customer->company_name ); ?>
								</a>
							</strong>
							<?php if ( $jobs ) : ?>
								<br><span class="description"><?php echo esc_html( sprintf( _n( '%d Job', '%d Jobs', count( $jobs ), 'karks-crm' ), count( $jobs ) ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $customer->contact_person ); ?></td>
						<td><?php echo esc_html( $customer->email ); ?></td>
						<td><?php echo esc_html( $customer->phone ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $customer->status ); ?>"><?php echo esc_html( $statuses[ $customer->status ] ?? $customer->status ); ?></span></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td>
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $customer->id ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $customer->id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=delete&id=' . $customer->id ), 'kcrm_delete_customer_' . $customer->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( $jobs ? __( 'Delete this customer and all of its Jobs?', 'karks-crm' ) : __( 'Delete this customer?', 'karks-crm' ) ); ?>');">
								<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
							</a>
						</td>
					</tr>
					<?php foreach ( $jobs as $job ) : ?>
						<?php $job_balance = KCRM_Customer::balance_for_ids( array( $job->id ) ); ?>
						<tr class="kcrm-job-row">
							<td>
								&#8627;
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $job->id ) ); ?>">
									<?php echo esc_html( $job->company_name ); ?>
								</a>
							</td>
							<td><?php echo esc_html( $job->contact_person ); ?></td>
							<td><?php echo esc_html( $job->email ); ?></td>
							<td><?php echo esc_html( $job->phone ); ?></td>
							<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $job->status ); ?>"><?php echo esc_html( $statuses[ $job->status ] ?? $job->status ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $job_balance, 2 ) ); ?></td>
							<td>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $job->id ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
								|
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $job->id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
								|
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=delete&id=' . $job->id ), 'kcrm_delete_customer_' . $job->id ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Delete this Job?', 'karks-crm' ) ); ?>');">
									<?php esc_html_e( 'Delete', 'karks-crm' ); ?>
								</a>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_form( $view ) {
		$id       = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$customer = $id ? KCRM_Customer::find( $id ) : null;

		if ( 'edit' === $view && ! $customer ) {
			echo '<p>' . esc_html__( 'Customer not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$v = function ( $field, $default = '' ) use ( $customer ) {
			return $customer ? esc_attr( $customer->$field ) : $default;
		};
		$notes = $customer ? esc_textarea( $customer->notes ) : '';

		$has_jobs         = $id ? ! empty( KCRM_Customer::jobs_for( $id ) ) : false;
		$preselect_parent = isset( $_GET['parent_id'] ) ? absint( $_GET['parent_id'] ) : ( $customer ? (int) $customer->parent_customer_id : 0 );
		$parent_options   = $has_jobs ? array() : KCRM_Customer::top_level_for_company( $this->current_company_id() );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>">
			<?php wp_nonce_field( 'kcrm_save_customer' ); ?>
			<input type="hidden" name="kcrm_action" value="save_customer">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<table class="form-table">
				<tr>
					<th><label for="status"><?php esc_html_e( 'Status', 'karks-crm' ); ?></label></th>
					<td>
						<select name="status" id="status">
							<?php foreach ( KCRM_Customer::statuses() as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $customer ? $customer->status : KCRM_Customer::STATUS_ACTIVE, $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<?php if ( ! $has_jobs ) : ?>
					<tr>
						<th><label for="parent_customer_id"><?php esc_html_e( 'This is a Job of', 'karks-crm' ); ?></label></th>
						<td>
							<select name="parent_customer_id" id="parent_customer_id">
								<option value="0"><?php esc_html_e( '— None (top-level customer) —', 'karks-crm' ); ?></option>
								<?php foreach ( $parent_options as $option ) : ?>
									<?php if ( (int) $option->id === $id ) { continue; } ?>
									<option value="<?php echo esc_attr( $option->id ); ?>" <?php selected( $preselect_parent, (int) $option->id ); ?>>
										<?php echo esc_html( $option->company_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Optional. Use this for a specific project or division under an existing customer (like QuickBooks Jobs) — its own address, invoices, and revenue are tracked separately, and roll up into the parent customer\'s totals.', 'karks-crm' ); ?></p>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th><label for="company_name"><?php esc_html_e( 'Company Name', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="company_name" id="company_name" value="<?php echo $v( 'company_name' ); ?>" required></td>
				</tr>
				<tr>
					<th><label for="contact_person"><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="contact_person" id="contact_person" value="<?php echo $v( 'contact_person' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="secondary_contact_person"><?php esc_html_e( 'Secondary Contact Person', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="secondary_contact_person" id="secondary_contact_person" value="<?php echo $v( 'secondary_contact_person' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_street"><?php esc_html_e( 'Street Address', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_street" id="address_street" value="<?php echo $v( 'address_street' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_city"><?php esc_html_e( 'City', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_city" id="address_city" value="<?php echo $v( 'address_city' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_state"><?php esc_html_e( 'State', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_state" id="address_state" value="<?php echo $v( 'address_state' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="address_postal_code"><?php esc_html_e( 'Postal Code', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="address_postal_code" id="address_postal_code" value="<?php echo $v( 'address_postal_code' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="phone"><?php esc_html_e( 'Phone Number', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="phone" id="phone" value="<?php echo $v( 'phone' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="email"><?php esc_html_e( 'Email Address', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="email" id="email" value="<?php echo $v( 'email' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="secondary_email"><?php esc_html_e( 'Secondary Email Address', 'karks-crm' ); ?></label></th>
					<td><input type="email" class="regular-text" name="secondary_email" id="secondary_email" value="<?php echo $v( 'secondary_email' ); ?>"></td>
				</tr>
				<tr>
					<th><label for="notes"><?php esc_html_e( 'Notes', 'karks-crm' ); ?></label></th>
					<td><textarea class="large-text" rows="4" name="notes" id="notes"><?php echo $notes; ?></textarea></td>
				</tr>
			</table>

			<?php submit_button( $id ? __( 'Update Customer', 'karks-crm' ) : __( 'Add Customer', 'karks-crm' ) ); ?>
		</form>

		<?php if ( $customer ) : ?>
			<?php
			$job_ids     = wp_list_pluck( KCRM_Customer::jobs_for( $customer->id ), 'id' );
			$rollup_ids  = array_merge( array( $customer->id ), $job_ids );
			?>
			<?php if ( ! $customer->parent_customer_id ) : ?>
				<?php $this->render_jobs_section( $customer ); ?>
			<?php endif; ?>
			<?php $this->render_revenue_section( $rollup_ids, ! empty( $job_ids ) ); ?>
			<?php $this->render_invoices_section( $rollup_ids, $customer->id, ! empty( $job_ids ) ); ?>
		<?php endif; ?>
		<?php
	}

	private function render_jobs_section( $customer ) {
		$jobs = KCRM_Customer::jobs_for( $customer->id );
		?>
		<h2><?php esc_html_e( 'Jobs', 'karks-crm' ); ?></h2>
		<p>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=add&parent_id=' . $customer->id ) ); ?>"><?php esc_html_e( '+ Add Job', 'karks-crm' ); ?></a>
		</p>
		<?php if ( $jobs ) : ?>
			<table class="wp-list-table widefat fixed striped" style="max-width:700px;">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Job', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Contact Person', 'karks-crm' ); ?></th>
						<th><?php esc_html_e( 'Balance', 'karks-crm' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $jobs as $job ) : ?>
						<tr>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&view=edit&id=' . $job->id ) ); ?>"><?php echo esc_html( $job->company_name ); ?></a></td>
							<td><?php echo esc_html( $job->contact_person ); ?></td>
							<td><?php echo esc_html( number_format_i18n( KCRM_Customer::balance_for_ids( array( $job->id ) ), 2 ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/** @param array $customer_ids The customer plus its Jobs (rolled up), when it has any. */
	private function render_revenue_section( array $customer_ids, $is_rollup ) {
		$this_year     = (int) current_time( 'Y' );
		$last_year     = $this_year - 1;
		$this_year_total = KCRM_Payment::total_for_customers_in_year( $customer_ids, $this_year );
		$last_year_total  = KCRM_Payment::total_for_customers_in_year( $customer_ids, $last_year );
		$lifetime_total   = KCRM_Payment::total_for_customers( $customer_ids );
		?>
		<h2><?php esc_html_e( 'Revenue', 'karks-crm' ); ?></h2>
		<?php if ( $is_rollup ) : ?>
			<p class="description"><?php esc_html_e( 'Includes this customer and all of its Jobs.', 'karks-crm' ); ?></p>
		<?php endif; ?>
		<div class="kcrm-dashboard-cards">
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $this_year_total, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php echo esc_html( sprintf( __( '%d Revenue', 'karks-crm' ), $this_year ) ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $last_year_total, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php echo esc_html( sprintf( __( '%d Revenue', 'karks-crm' ), $last_year ) ); ?></span>
			</div>
			<div class="kcrm-card">
				<span class="kcrm-card-number"><?php echo esc_html( number_format_i18n( $lifetime_total, 2 ) ); ?></span>
				<span class="kcrm-card-label"><?php esc_html_e( 'Lifetime Revenue', 'karks-crm' ); ?></span>
			</div>
		</div>
		<?php
	}

	/**
	 * @param array $customer_ids The customer plus its Jobs (rolled up), when it has any.
	 * @param int   $primary_customer_id Used for the "New Invoice" / customer_id links.
	 */
	private function render_invoices_section( array $customer_ids, $primary_customer_id, $is_rollup ) {
		$show_all = ! empty( $_GET['kcrm_invoice_filter'] ) && 'all' === $_GET['kcrm_invoice_filter'];
		$statuses = $show_all ? null : KCRM_Invoice::default_customer_statuses();
		$invoices = KCRM_Invoice::for_customers_with_statuses( $customer_ids, $statuses );
		$all_statuses = KCRM_Invoice::statuses();

		$toggle_url = $show_all ? remove_query_arg( 'kcrm_invoice_filter' ) : add_query_arg( 'kcrm_invoice_filter', 'all' );
		?>
		<h2><?php esc_html_e( 'Invoices', 'karks-crm' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Invoices with a status of Draft, Open, and Partially Paid are displayed by default.', 'karks-crm' ); ?></p>
		<?php if ( $is_rollup ) : ?>
			<p class="description"><?php esc_html_e( 'Includes this customer and all of its Jobs.', 'karks-crm' ); ?></p>
		<?php endif; ?>
		<p>
			<a href="<?php echo esc_url( $toggle_url ); ?>">
				<?php echo $show_all
					? esc_html__( 'Show default statuses only (Draft, Open, Partially Paid)', 'karks-crm' )
					: esc_html__( 'Show invoices with all statuses', 'karks-crm' ); ?>
			</a>
			|
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=add&customer_id=' . $primary_customer_id ) ); ?>"><?php esc_html_e( 'New Invoice', 'karks-crm' ); ?></a>
		</p>
		<table class="wp-list-table widefat fixed striped" style="max-width:900px;">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Invoice #', 'karks-crm' ); ?></th>
					<?php if ( $is_rollup ) : ?>
						<th><?php esc_html_e( 'Billed To', 'karks-crm' ); ?></th>
					<?php endif; ?>
					<th><?php esc_html_e( 'Issue Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Due Date', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Total', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Balance Due', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Status', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $invoices ) ) : ?>
					<tr><td colspan="<?php echo $is_rollup ? '7' : '6'; ?>"><?php esc_html_e( 'No invoices found.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $invoices as $invoice ) : ?>
					<?php $balance = KCRM_Invoice::balance_due( $invoice->id ); ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( admin_url( 'admin.php?page=karks-crm-invoices&view=edit&id=' . $invoice->id ) ); ?>">
									<?php echo esc_html( $invoice->invoice_number ); ?>
								</a>
							</strong>
						</td>
						<?php if ( $is_rollup ) : ?>
							<td>
								<?php
								$billed_to = (int) $invoice->customer_id === (int) $primary_customer_id ? null : KCRM_Customer::find( $invoice->customer_id );
								echo esc_html( $billed_to ? $billed_to->company_name : __( '(this customer)', 'karks-crm' ) );
								?>
							</td>
						<?php endif; ?>
						<td><?php echo esc_html( $invoice->issue_date ); ?></td>
						<td><?php echo esc_html( $invoice->due_date ); ?></td>
						<td><?php echo esc_html( number_format_i18n( (float) $invoice->total, 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $balance, 2 ) ); ?></td>
						<td><span class="kcrm-status kcrm-status-<?php echo esc_attr( $invoice->status ); ?>"><?php echo esc_html( $all_statuses[ $invoice->status ] ?? $invoice->status ); ?></span></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
