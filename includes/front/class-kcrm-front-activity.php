<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end Activity Log (/crm/activity/). Reached from the "View full
 * activity log" link under the company profile's Recent Actions and from
 * each customer profile's Activity tab -- not a top-level nav item.
 * View-only: on/off, retention, and clearing the log are managed from
 * wp-admin (KCRM_Admin_Activity).
 */
class KCRM_Front_Activity extends KCRM_Activity_Controller {

	use KCRM_Front_Screen_Trait;

	public function render() {
		$company_id = $this->current_company_id();

		echo '<div class="kcrm-front-screen kcrm-activity-screen">';
		$this->render_company_header();
		$this->render_heading( $company_id );

		if ( ! KCRM_Activity::is_enabled() ) {
			// The route stays reachable (old bookmarks shouldn't 404), it just has nothing to show.
			echo '<p>' . esc_html__( 'The activity log is turned off.', 'karks-crm' ) . '</p></div>';
			return;
		}

		if ( ! $company_id ) {
			echo '<p>' . esc_html__( 'Create a company first under Companies.', 'karks-crm' ) . '</p></div>';
			return;
		}

		$this->render_log( 'front' );

		echo '<p class="description kcrm-activity-retention-note">' . esc_html( $this->retention_note() ) . '</p>';
		echo '</div>';
	}

	private function render_heading( $company_id ) {
		if ( ! $company_id ) {
			echo '<h2>' . esc_html__( 'Activity Log', 'karks-crm' ) . '</h2>';
			return;
		}
		printf(
			'<h2><a href="%s">%s</a>: %s</h2>',
			esc_url( KCRM_Front::endpoint_url( 'companies', array( 'view' => 'overview', 'id' => $company_id ) ) ),
			esc_html__( 'Company Profile', 'karks-crm' ),
			esc_html__( 'Activity Log', 'karks-crm' )
		);
	}
}
