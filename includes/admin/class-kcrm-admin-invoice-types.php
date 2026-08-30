<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Invoice Types" management screen: the user-managed list backing the
 * "Invoice Type" dropdown on every invoice (KCRM_Invoice_Type). Global,
 * not scoped to a company, so this is wp-admin only -- standalone like
 * KCRM_Admin_Appearance, not part of KCRM_Plugin's $screens dispatch,
 * since there's no company-scoped business logic to share via
 * KCRM_Controller_Base.
 */
class KCRM_Admin_Invoice_Types {

	const PAGE = 'karks-crm-invoice-types';

	public function handle_actions() {
		if ( ! is_admin() || ! current_user_can( KCRM_CAPABILITY ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only route check; the real nonce check is in save()/delete() below.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- action name only; save() verifies the nonce itself.
		if ( isset( $_POST['kcrm_action'] ) && 'save_invoice_type' === $_POST['kcrm_action'] ) {
			$this->save();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
		if ( isset( $_GET['action'], $_GET['id'] ) && 'delete' === $_GET['action'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action name only; delete() verifies the nonce itself.
			$this->delete( absint( $_GET['id'] ) );
		}
	}

	private function save() {
		check_admin_referer( 'kcrm_save_invoice_type' );

		$id    = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';

		$ok = $id ? KCRM_Invoice_Type::save_label( $id, $label ) : (bool) KCRM_Invoice_Type::create_from_label( $label );

		$this->redirect( array( 'kcrm_notice' => $ok ? 'saved' : 'error' ) );
	}

	private function delete( $id ) {
		check_admin_referer( 'kcrm_delete_invoice_type_' . $id );
		KCRM_Invoice_Type::delete( $id );
		$this->redirect( array( 'kcrm_notice' => 'deleted' ) );
	}

	private function redirect( array $args = array() ) {
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGE ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function render() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view-routing param, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'list';

		echo '<div class="wrap kcrm-wrap"><h1 class="wp-heading-inline">' . esc_html__( 'Invoice Types', 'karks-crm' ) . '</h1>';
		if ( 'list' === $view ) {
			printf( ' <a href="%s" class="page-title-action">%s</a>', esc_url( add_query_arg( array( 'page' => self::PAGE, 'view' => 'add' ), admin_url( 'admin.php' ) ) ), esc_html__( 'Add New', 'karks-crm' ) );
		}
		echo '<hr class="wp-header-end">';

		$this->render_notice();

		if ( 'add' === $view || 'edit' === $view ) {
			$this->render_form( $view );
		} else {
			$this->render_list();
		}

		echo '</div>';
	}

	private function render_notice() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		if ( empty( $_GET['kcrm_notice'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display notice, no state change.
		$notice = sanitize_key( wp_unslash( $_GET['kcrm_notice'] ) );

		$messages = array(
			'saved'   => array( 'success', __( 'Saved successfully.', 'karks-crm' ) ),
			'deleted' => array( 'success', __( 'Deleted successfully.', 'karks-crm' ) ),
			'error'   => array( 'error', __( 'Please enter a name for the invoice type.', 'karks-crm' ) ),
		);

		if ( isset( $messages[ $notice ] ) ) {
			list( $type, $message ) = $messages[ $notice ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $message ) );
		}
	}

	private function render_list() {
		$types = KCRM_Invoice_Type::all_ordered();
		?>
		<p><?php esc_html_e( 'These appear in the "Invoice Type" dropdown when creating or editing an invoice, in wp-admin and on the front end.', 'karks-crm' ); ?></p>
		<table class="wp-list-table widefat fixed striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'karks-crm' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'karks-crm' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $types ) ) : ?>
					<tr><td colspan="2"><?php esc_html_e( 'No invoice types yet.', 'karks-crm' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $types as $type ) : ?>
					<tr>
						<td>
							<strong>
								<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'view' => 'edit', 'id' => $type->id ), admin_url( 'admin.php' ) ) ); ?>">
									<?php echo esc_html( $type->label ); ?>
								</a>
							</strong>
						</td>
						<td>
							<a href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE, 'view' => 'edit', 'id' => $type->id ), admin_url( 'admin.php' ) ) ); ?>"><?php esc_html_e( 'Edit', 'karks-crm' ); ?></a>
							|
							<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::PAGE, 'action' => 'delete', 'id' => $type->id ), admin_url( 'admin.php' ) ), 'kcrm_delete_invoice_type_' . $type->id ) ); ?>"
								onclick="return confirm('<?php echo esc_js( __( 'Delete this invoice type? Invoices already using it keep showing it, but it will no longer be selectable for new ones.', 'karks-crm' ) ); ?>');">
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
		$id   = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$type = $id ? KCRM_Invoice_Type::find( $id ) : null;

		if ( 'edit' === $view && ! $type ) {
			echo '<p>' . esc_html__( 'Invoice type not found.', 'karks-crm' ) . '</p>';
			return;
		}
		?>
		<form method="post" action="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) ); ?>">
			<?php wp_nonce_field( 'kcrm_save_invoice_type' ); ?>
			<input type="hidden" name="kcrm_action" value="save_invoice_type">
			<input type="hidden" name="id" value="<?php echo esc_attr( $id ); ?>">
			<table class="form-table">
				<tr>
					<th><label for="label"><?php esc_html_e( 'Label', 'karks-crm' ); ?></label></th>
					<td><input type="text" class="regular-text" name="label" id="label" value="<?php echo esc_attr( $type ? $type->label : '' ); ?>" required></td>
				</tr>
			</table>
			<?php submit_button( $id ? __( 'Update Invoice Type', 'karks-crm' ) : __( 'Add Invoice Type', 'karks-crm' ) ); ?>
		</form>
		<?php
	}
}
