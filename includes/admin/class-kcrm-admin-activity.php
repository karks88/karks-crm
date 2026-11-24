<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * wp-admin Activity Log (Karks CRM → Activity Log): the same log as the
 * front end's /crm/activity/, plus the log's settings -- turning logging
 * on/off site-wide, retention, and clearing a company's log.
 *
 * Anyone with KCRM_CAPABILITY can view the log; the settings are shown to,
 * and only accepted from, users with manage_options (checked in each
 * handler, not just by hiding the form).
 */
class KCRM_Admin_Activity extends KCRM_Activity_Controller {

	use KCRM_Admin_Screen_Trait;

	public function handle_actions() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save_settings() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_activity_settings' === $_POST['kcrm_action'] ) {
			$this->save_settings();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; clear_log() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'clear_activity_log' === $_POST['kcrm_action'] ) {
			$this->clear_log();
		}
	}

	/** Managing the log is admin-only -- KCRM_CAPABILITY alone (the CRM Manager role) isn't enough. */
	private function can_manage_log() {
		return current_user_can( 'manage_options' );
	}

	private function save_settings() {
		check_admin_referer( 'kcrm_activity_settings' );
		if ( ! $this->can_manage_log() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ), 403 );
		}

		$months = isset( $_POST['retention_months'] ) ? absint( $_POST['retention_months'] ) : -1;
		if ( ! in_array( $months, KCRM_Activity::retention_choices(), true ) ) {
			$this->redirect( array( 'kcrm_notice' => 'error' ) );
		}

		// A real checkbox: unchecked means entirely absent from $_POST.
		update_option( KCRM_Activity::ENABLED_OPTION, isset( $_POST['activity_enabled'] ) ? 1 : 0 );

		$previous = KCRM_Activity::retention_months();
		if ( $months !== $previous ) {
			update_option( KCRM_Activity::RETENTION_OPTION, $months, false );
			KCRM_Activity::log(
				'settings.retention_changed',
				array(
					'details' => array(
						'from' => $previous,
						'to'   => $months,
					),
				)
			);
		}

		$this->redirect( array( 'kcrm_notice' => 'saved' ) );
	}

	private function clear_log() {
		check_admin_referer( 'kcrm_activity_clear' );
		if ( ! $this->can_manage_log() ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'karks-crm' ), 403 );
		}

		$company_id = $this->current_company_id();
		if ( ! $company_id ) {
			$this->redirect( array( 'kcrm_notice' => 'no_company' ) );
		}

		KCRM_Activity::clear_for_company( $company_id );
		// Leaves one entry behind (when logging is on) so the empty log reads as "cleared" rather than "nothing ever happened".
		KCRM_Activity::log( 'log.cleared', array( 'company_id' => $company_id ) );

		$this->redirect( array( 'kcrm_notice' => 'activity_cleared' ) );
	}

	public function render() {
		$company_id = $this->current_company_id();
		?>
		<div class="wrap kcrm-wrap kcrm-activity-screen">
			<h1><?php esc_html_e( 'Activity Log', 'karks-crm' ); ?></h1>
			<?php
			$this->company_switcher();
			$this->render_notice_from_query();

			if ( ! KCRM_Activity::is_enabled() ) {
				echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Activity logging is turned off. Nothing new is being recorded, and the activity log is hidden on the front end. Existing entries are shown below.', 'karks-crm' ) . '</p></div>';
			}

			if ( $company_id ) {
				$this->render_log( 'admin' );
			} else {
				echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p>';
			}

			$this->render_settings( $company_id );
			?>
		</div>
		<?php
	}

	/** Read-only retention note for everyone; the settings form and Clear button for admins only. */
	private function render_settings( $company_id ) {
		?>
		<h2 class="kcrm-activity-settings-heading"><?php esc_html_e( 'Settings', 'karks-crm' ); ?></h2>
		<?php if ( ! $this->can_manage_log() ) : ?>
			<p class="description"><?php echo esc_html( $this->retention_note() ); ?> <?php esc_html_e( 'Only administrators can change the activity log settings.', 'karks-crm' ); ?></p>
			<?php
			return;
		endif;

		$months = KCRM_Activity::retention_months();
		?>
		<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>">
			<?php wp_nonce_field( 'kcrm_activity_settings' ); ?>
			<input type="hidden" name="kcrm_action" value="save_activity_settings">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Activity Logging', 'karks-crm' ); ?></th>
					<td>
						<label><input type="checkbox" name="activity_enabled" value="1" <?php checked( KCRM_Activity::is_enabled() ); ?>> <?php esc_html_e( 'Record activity for all companies', 'karks-crm' ); ?></label>
						<p class="description"><?php esc_html_e( 'When turned off, nothing new is recorded, and Recent Actions, the Activity Log, and the customer Activity tab are hidden on the front end. Existing entries are kept and still listed here.', 'karks-crm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="kcrm-retention-months"><?php esc_html_e( 'Keep Activity For', 'karks-crm' ); ?></label></th>
					<td>
						<select name="retention_months" id="kcrm-retention-months">
							<?php foreach ( KCRM_Activity::retention_choices() as $choice ) : ?>
								<option value="<?php echo esc_attr( $choice ); ?>" <?php selected( $months, $choice ); ?>><?php echo esc_html( KCRM_Activity_Feed::retention_label( $choice ) ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Older entries are removed automatically once a day, for every company.', 'karks-crm' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'karks-crm' ) ); ?>
		</form>

		<?php if ( $company_id ) : ?>
			<?php $company = KCRM_Company::find( $company_id ); ?>
			<h2><?php esc_html_e( 'Clear Activity Log', 'karks-crm' ); ?></h2>
			<p>
				<?php
				printf(
					/* translators: %s: company name. */
					esc_html__( 'Permanently deletes every activity entry for %s. Other companies are not affected.', 'karks-crm' ),
					'<strong>' . esc_html( $company ? $company->name : '' ) . '</strong>'
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( $this->screen_url() ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Permanently delete all activity for this company? This cannot be undone.', 'karks-crm' ) ); ?>');">
				<?php wp_nonce_field( 'kcrm_activity_clear' ); ?>
				<input type="hidden" name="kcrm_action" value="clear_activity_log">
				<?php submit_button( __( "Clear This Company's Activity Log", 'karks-crm' ), 'delete', 'submit', false ); ?>
			</form>
		<?php endif; ?>
		<?php
	}
}
