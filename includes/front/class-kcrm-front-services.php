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
		$this->render_heading( $view );

		$this->company_switcher();

		if ( 'list' === $view ) {
			printf( '<div class="kcrm-button-group"><a class="kcrm-button kcrm-button-primary" href="%s">%s</a></div>', esc_url( $this->screen_url( array( 'view' => 'add' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
		}

		$this->render_notice_from_query();

		if ( ! $this->current_company_id() ) {
			echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p></div>';
			return;
		}

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
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
				echo '<h2>' . $link . ': ' . esc_html( $service->name ) . '</h2>';
				return;
			}
		}

		echo '<h2>' . $link . '</h2>';
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
					<th><?php esc_html_e( 'Active', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $services ) ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'No services yet for this company.', 'karks-crm' ); ?></td></tr>
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

			<p><button type="submit" class="kcrm-button kcrm-button-primary"><?php echo esc_html( $id ? __( 'Update Service', 'karks-crm' ) : __( 'Add Service', 'karks-crm' ) ); ?></button></p>
		</form>
		<?php
	}
}
