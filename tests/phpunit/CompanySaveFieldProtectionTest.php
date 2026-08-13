<?php
/**
 * Regression coverage for the exact incident this test suite exists
 * because of: a real save() call against KCRM_Admin_Companies with an
 * incomplete $_POST silently cleared payment_links and invoice_bcc_enabled
 * on a live company. field_or_existing() (KCRM_Controller_Base) is what's
 * supposed to prevent that for ordinary fields -- these tests prove it
 * actually does, and pin down the documented, intentional exception
 * (checkbox groups/repeaters) so that gap can't silently get worse without
 * a test having to change first.
 */
class CompanySaveFieldProtectionTest extends WP_UnitTestCase {

	private $company_id;
	private $original_post;
	private $original_request;
	private $original_user;

	public function set_up() {
		parent::set_up();

		$this->company_id = KCRM_Company::create(
			array(
				'name'                       => 'Field Protection Test Co',
				'email'                      => 'original@example.com',
				'address_street'             => '1 Original St',
				'check_payable_to'           => 'Original Payee',
				'other_payment_instructions' => 'Original Venmo handle',
				'accepted_payment_types'     => 'credit_card,ach,check',
				'payment_links'              => '[{"label":"Pay Online","url":"https:\/\/example.com\/pay"}]',
				'invoice_bcc_enabled'        => 1,
				'invoice_bcc_email'          => 'bcc@example.com',
			)
		);

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- snapshotting the current superglobal state to restore in tear_down(), not processing a submitted request.
		$this->original_post    = $_POST;
		$this->original_request = $_REQUEST;
		$this->original_user    = get_current_user_id();

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down() {
		$_POST    = $this->original_post;
		$_REQUEST = $this->original_request;
		wp_set_current_user( $this->original_user );
		parent::tear_down();
	}

	/** Submits a minimal, realistic-looking-but-incomplete $_POST (missing most fields) through the real save() controller method and returns the saved row. */
	private function save_with_partial_post( array $post ) {
		$screen = new KCRM_Admin_Companies();
		$save   = new ReflectionMethod( $screen, 'save' );
		$save->setAccessible( true );

		$nonce               = wp_create_nonce( 'kcrm_save_company' );
		$_POST                = array_merge(
			array(
				'kcrm_action' => 'save_company',
				'id'          => (string) $this->company_id,
				'_wpnonce'    => $nonce,
			),
			$post
		);
		$_REQUEST['_wpnonce'] = $nonce;

		add_filter(
			'wp_redirect',
			function ( $location ) {
				throw new Exception( esc_html( 'redirect:' . $location ) );
			}
		);

		try {
			$save->invoke( $screen );
		} catch ( Exception $e ) {
			// Expected -- redirect() always fires on a successful save; this just unwinds the exit() past it.
		}

		return KCRM_Company::find( $this->company_id );
	}

	public function test_partial_post_preserves_ordinary_text_fields() {
		// Only 'name' is submitted -- every other field_or_existing() field is entirely absent from $_POST.
		$company = $this->save_with_partial_post( array( 'name' => 'Field Protection Test Co' ) );

		$this->assertSame( 'original@example.com', $company->email );
		$this->assertSame( '1 Original St', $company->address_street );
		$this->assertSame( 'Original Payee', $company->check_payable_to );
		$this->assertSame( 'Original Venmo handle', $company->other_payment_instructions );
	}

	/**
	 * Documents current, intentional behavior (see KCRM_Controller_Base::field_or_existing()
	 * docblock and CLAUDE.md): a real checkbox group can't distinguish "user
	 * unchecked everything" from "field never submitted" by presence alone,
	 * so it does NOT fall back to the existing value -- omission clears it.
	 * This is exactly what caused the real incident; it's captured here as a
	 * known, named gap rather than a silent one.
	 */
	public function test_partial_post_clears_checkbox_and_repeater_fields() {
		$company = $this->save_with_partial_post( array( 'name' => 'Field Protection Test Co' ) );

		$this->assertSame( '', $company->accepted_payment_types, 'accepted_payment_types should be cleared when omitted (documented gap)' );
		$this->assertSame( '', $company->payment_links, 'payment_links should be cleared when omitted (documented gap)' );
		$this->assertSame( 0, (int) $company->invoice_bcc_enabled, 'invoice_bcc_enabled should be cleared when omitted (documented gap)' );
	}

	/** A realistic mixed submission: some fields explicitly changed, others (still) omitted -- explicit ones update, omitted-but-protected ones survive. */
	public function test_mixed_post_updates_submitted_fields_and_preserves_omitted_protected_ones() {
		$company = $this->save_with_partial_post(
			array(
				'name'                       => 'Field Protection Test Co',
				'email'                      => 'updated@example.com',
				'accepted_payment_types'     => array( 'venmo' ),
				'payment_link_label'         => array( 'New Link' ),
				'payment_link_url'           => array( 'https://example.com/new' ),
				'invoice_bcc_enabled'        => '1',
				'other_payment_instructions' => 'Updated Venmo handle',
			)
		);

		$this->assertSame( 'updated@example.com', $company->email );
		$this->assertSame( 'venmo', $company->accepted_payment_types );
		$this->assertStringContainsString( 'New Link', $company->payment_links );
		$this->assertSame( 1, (int) $company->invoice_bcc_enabled );
		$this->assertSame( 'Updated Venmo handle', $company->other_payment_instructions );
		// Untouched ordinary field from the original record should still survive a real, full submission too.
		$this->assertSame( '1 Original St', $company->address_street );
	}
}
