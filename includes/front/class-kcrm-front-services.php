<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KCRM_Front_Services extends KCRM_Services_Controller {

	use KCRM_Front_Screen_Trait;

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : 'list';

		echo '<div class="kcrm-front-screen">';
		$this->render_company_header();
		$this->render_heading( $view );

		if ( 'list' === $view ) {
			printf( '<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s"><span class="dashicons dashicons-plus-alt2"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-upload"></span> %s</a> ', esc_url( $this->screen_url( array( 'view' => 'import' ) ) ), esc_html__( 'Import from CSV', 'karks-crm' ) );
			printf( '<a class="kcrm-button" href="%s"><span class="dashicons dashicons-download"></span> %s</a></div>', esc_url( $this->export_services_csv_url() ), esc_html__( 'Export CSV', 'karks-crm' ) );
		}

		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p></div>';
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

	/** Renders the H2 -- linked back to the list once we're off it, with the service's name appended when editing one. */
	private function render_heading( $view ) {
		$label = __( 'Services', 'karks-crm' );

		if ( 'list' === $view ) {
			echo '<h2>' . esc_html( $label ) . '</h2>';
			return;
		}

		$link = sprintf( '<a href="%s">%s</a>', esc_url( $this->screen_url() ), esc_html( $label ) );

		if ( 'edit' === $view ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only routing param, no state change.
			$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			$service = $id ? KCRM_Service::find( $id ) : null;

			if ( $service ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
				echo '<h2>' . $link . ': ' . esc_html( $service->name ) . '</h2>';
				return;
			}
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link is built from esc_url()/esc_html() above; safe to output as-is.
		echo '<h2>' . $link . '</h2>';
	}

	private function render_import() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing params, no state change.
		$stage = isset( $_GET['stage'] ) ? sanitize_key( $_GET['stage'] ) : 'upload';

		if ( 'done' === $stage ) {
			$this->render_import_done();
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		} elseif ( 'map' === $stage && isset( $_GET['file'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
			$this->render_import_map( sanitize_text_field( wp_unslash( $_GET['file'] ) ) );
		} else {
			$this->render_import_upload();
		}
	}

	private function render_import_upload() {
		?>
		<p><?php esc_html_e( "Upload a CSV export (e.g. from QuickBooks) and you'll be able to choose which columns map to which fields before anything is imported. Rows sharing the same name only import once, and services that already exist here (matched by name) are skipped automatically — it's safe to re-run.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" enctype="multipart/form-data" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_import_services_upload' ); ?>
			<input type="hidden" name="kcrm_action" value="import_services_upload">
			<p>
				<label for="import_file"><?php esc_html_e( 'CSV File', 'karks-crm' ); ?></label>
				<input type="file" name="import_file" id="import_file" accept=".csv" required>
			</p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Upload & Continue', 'karks-crm' ); ?></button></p>
		</form>
		<?php
	}

	private function render_import_map( $token ) {
		$path = KCRM_CSV_Import::path_for_token( $token );

		if ( ! $path ) {
			echo '<p>' . esc_html__( 'That upload could not be found — it may have expired. Please upload the file again.', 'karks-crm' ) . '</p>';
			printf( '<div class="kcrm-button-group"><a class="kcrm-button" href="%s">%s</a></div>', esc_url( $this->screen_url( array( 'view' => 'import' ) ) ), esc_html__( 'Start Over', 'karks-crm' ) );
			return;
		}

		$header = KCRM_CSV_Import::read_header( $path );
		$fields = $this->import_fields();
		?>
		<p><?php esc_html_e( "Choose which column in your file maps to each service field. We've guessed a few based on common column names — double check before importing.", 'karks-crm' ); ?></p>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_import_services_run' ); ?>
			<input type="hidden" name="kcrm_action" value="import_services_run">
			<input type="hidden" name="file" value="<?php echo esc_attr( $token ); ?>">
			<?php foreach ( $fields as $key => $field ) : ?>
				<?php $guess = $this->guess_column( $header, $field['guess'] ); ?>
				<p>
					<label for="map_<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $field['label'] ); ?><?php echo ! empty( $field['required'] ) ? ' *' : ''; ?>
					</label>
					<select name="map[<?php echo esc_attr( $key ); ?>]" id="map_<?php echo esc_attr( $key ); ?>">
						<option value="-1"><?php esc_html_e( '— Skip —', 'karks-crm' ); ?></option>
						<?php foreach ( $header as $i => $label ) : ?>
							<option value="<?php echo esc_attr( $i ); ?>" <?php selected( $guess, $i ); ?>>
								<?php echo esc_html( $this->column_label( $label, $i ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php endforeach; ?>
			<p>
				<label for="service_type"><?php esc_html_e( 'Type for all imported services', 'karks-crm' ); ?></label>
				<select name="service_type" id="service_type">
					<?php foreach ( KCRM_Service::types() as $type_key => $type_label ) : ?>
						<option value="<?php echo esc_attr( $type_key ); ?>" <?php selected( $type_key, KCRM_Service::TYPE_PROJECT ); ?>><?php echo esc_html( $type_label ); ?></option>
					<?php endforeach; ?>
				</select>
				<br><small><?php esc_html_e( "There's usually no column for this in an export, so every imported row gets the type chosen here — edit individual services afterward if some should be the other type.", 'karks-crm' ); ?></small>
			</p>
			<p class="description">* <?php esc_html_e( 'Required. Rows with a blank value in this column are skipped.', 'karks-crm' ); ?></p>
			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php esc_html_e( 'Import Services', 'karks-crm' ); ?></button></p>
		</form>
		<?php
	}

	private function render_import_done() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$imported = isset( $_GET['imported'] ) ? absint( $_GET['imported'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$skipped_no_name = isset( $_GET['skipped_no_name'] ) ? absint( $_GET['skipped_no_name'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$skipped_duplicate = isset( $_GET['skipped_duplicate'] ) ? absint( $_GET['skipped_duplicate'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display counters, no state change.
		$skipped_existing = isset( $_GET['skipped_existing'] ) ? absint( $_GET['skipped_existing'] ) : 0;
		?>
		<ul>
			<li>
				<?php
				/* translators: %d: number of services imported. */
				echo esc_html( sprintf( __( '%d services imported.', 'karks-crm' ), $imported ) );
				?>
			</li>
			<li>
				<?php
				/* translators: %d: number of rows skipped because the name column was blank. */
				echo esc_html( sprintf( __( '%d rows skipped — missing a name.', 'karks-crm' ), $skipped_no_name ) );
				?>
			</li>
			<li>
				<?php
				/* translators: %d: number of rows skipped because that name appeared more than once in the file. */
				echo esc_html( sprintf( __( '%d rows skipped — duplicate name within the file.', 'karks-crm' ), $skipped_duplicate ) );
				?>
			</li>
			<li>
				<?php
				/* translators: %d: number of rows skipped because a service with that name already exists. */
				echo esc_html( sprintf( __( '%d rows skipped — a service with that name already exists.', 'karks-crm' ), $skipped_existing ) );
				?>
			</li>
		</ul>
		<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="<?php echo esc_url( $this->screen_url() ); ?>"><?php esc_html_e( 'View Services', 'karks-crm' ); ?></a></div>
		<?php
	}

	private function render_list() {
		$services = KCRM_Service::for_company( $this->current_company_id() );
		$types    = KCRM_Service::types();
		?>
		<table class="kcrm-front-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Name', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Type', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Rate', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Taxable', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Active', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $services ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No services yet for this company.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $services as $service ) : ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $service->id ) ) ); ?>">
									<?php echo esc_html( $service->name ); ?>
								</a>
							</strong>
						</td>
						<td><?php echo esc_html( $types[ $service->type ] ?? $service->type ); ?></td>
						<td>
							<?php
							echo esc_html( number_format_i18n( (float) $service->rate, 2 ) );
							echo KCRM_Service::TYPE_HOURLY === $service->type ? esc_html__( '/hr', 'karks-crm' ) : '';
							?>
						</td>
						<td><?php echo $service->is_taxable ? esc_html__( 'Yes', 'karks-crm' ) : esc_html__( 'No', 'karks-crm' ); ?></td>
						<td><?php echo $service->is_active ? esc_html__( 'Yes', 'karks-crm' ) : esc_html__( 'No', 'karks-crm' ); ?></td>
						<td>
							<a href="<?php echo esc_url( $this->screen_url( array( 'view' => 'edit', 'id' => $service->id ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( $this->screen_url( array( 'action' => 'delete', 'id' => $service->id ) ), 'kcrm_delete_service_' . $service->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this service?', 'karks-crm' ) ); ?>');">
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
		$service = $id ? KCRM_Service::find( $id ) : null;

		if ( 'edit' === $view && ! $service ) {
			echo '<p>' . esc_html__( 'Service not found.', 'karks-crm' ) . '</p>';
			return;
		}

		$name        = $service ? $service->name : '';
		$description = $service ? $service->description : '';
		$type        = $service ? $service->type : KCRM_Service::TYPE_HOURLY;
		$rate        = $service ? $service->rate : '0.00';
		$is_active   = $service ? (bool) $service->is_active : true;
		$is_taxable  = $service ? (bool) $service->is_taxable : false;
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" class="kcrm-front-form">
			<?php wp_nonce_field( 'kcrm_save_service' ); ?>
			<input type="hidden" name="kcrm_action" value="save_service">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">

			<p>
				<label for="name"><?php esc_html_e( 'Service Name', 'karks-crm' ); ?></label>
				<input type="text" name="name" id="name" value="<?php echo esc_attr( $name ); ?>" required>
			</p>
			<p>
				<label for="description"><?php esc_html_e( 'Description', 'karks-crm' ); ?></label>
				<textarea rows="3" name="description" id="description"><?php echo esc_textarea( $description ); ?></textarea>
			</p>
			<p>
				<label for="type"><?php esc_html_e( 'Pricing Type', 'karks-crm' ); ?></label>
				<select name="type" id="type">
					<?php foreach ( KCRM_Service::types() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="rate"><?php esc_html_e( 'Rate', 'karks-crm' ); ?></label>
				<input type="number" step="0.01" min="0" name="rate" id="rate" value="<?php echo esc_attr( $rate ); ?>">
				<br><small><?php esc_html_e( 'For hourly services this is the rate per hour; for project-based services this is the flat project price.', 'karks-crm' ); ?></small>
			</p>
			<p>
				<label><input type="checkbox" name="is_active" value="1" <?php checked( $is_active ); ?>> <?php esc_html_e( 'Available to select on new invoices', 'karks-crm' ); ?></label>
			</p>
			<p>
				<label><input type="checkbox" name="is_taxable" value="1" <?php checked( $is_taxable ); ?>> <?php esc_html_e( 'Taxable (applies the invoice\'s tax rate to this line item)', 'karks-crm' ); ?></label>
				<br><small><?php esc_html_e( "Off by default. When on, invoices using this service apply the company's tax rate to this line item's amount.", 'karks-crm' ); ?></small>
			</p>

			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo esc_html( $id ? __( 'Update Service', 'karks-crm' ) : __( 'Add Service', 'karks-crm' ) ); ?></button></p>
		</form>
		<?php
	}
}
