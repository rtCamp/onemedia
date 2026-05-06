<?php
/**
 * Tests for media library admin helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaLibrary
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaLibrary;

use OneMedia\Modules\Core\Assets;
use OneMedia\Modules\MediaLibrary\Admin;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use WP_Query;

/**
 * Test class.
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		unset( $_REQUEST['query'], $_REQUEST['_ajax_nonce'], $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ], $_GET['onemedia_sync_nonce'] );

		wp_dequeue_script( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE );
		wp_dequeue_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE );
		wp_deregister_script( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE );
		wp_deregister_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE );
		wp_dequeue_style( Assets::MEDIA_TAXONOMY_STYLE_HANDLE );
		wp_deregister_style( Assets::MEDIA_TAXONOMY_STYLE_HANDLE );

		parent::tearDown();
	}

	/**
	 * Tests no errors on class lifecycle methods.
	 */
	public function test_class_instantiation(): void {
		$admin = new Admin();

		$admin->register_hooks();
		$admin->enqueue_scripts();

		$this->assertTrue( true );
	}

	/**
	 * Tests media-library asset loading on the upload screen.
	 */
	public function test_enqueue_scripts_enqueues_styles_scripts_and_localized_data_on_upload_screen(): void {
		$this->register_media_library_assets();
		set_current_screen( 'upload' );

		( new Admin() )->enqueue_scripts();

		$this->assertTrue( wp_style_is( Assets::MEDIA_TAXONOMY_STYLE_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'OneMediaMediaUpload', (string) wp_scripts()->get_data( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'data' ) );
		$this->assertStringContainsString( '"isMediaPage":"1"', (string) wp_scripts()->get_data( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'data' ) );
		$this->assertStringContainsString( 'OneMediaMediaFrame', (string) wp_scripts()->get_data( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'data' ) );
	}

	/**
	 * Tests taxonomy-style-only behavior on the media taxonomy screen.
	 */
	public function test_enqueue_scripts_only_enqueues_taxonomy_style_for_governing_non_upload_screen(): void {
		$this->register_media_library_assets();
		set_current_screen( 'edit-onemedia_media_type' );

		( new Admin() )->enqueue_scripts();

		$this->assertTrue( wp_style_is( Assets::MEDIA_TAXONOMY_STYLE_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Tests consumer screens still receive script data outside upload.php.
	 */
	public function test_enqueue_scripts_enqueues_consumer_assets_on_non_upload_screen(): void {
		$this->register_media_library_assets();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		set_current_screen( 'dashboard' );

		( new Admin() )->enqueue_scripts();

		$this->assertFalse( wp_style_is( Assets::MEDIA_TAXONOMY_STYLE_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( '"isMediaPage":""', (string) wp_scripts()->get_data( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, 'data' ) );
	}

	/**
	 * Tests AJAX attachment filtering.
	 */
	public function test_filter_ajax_query_attachments_args_handles_sync_status_filters(): void {
		$admin = new Admin();
		$query = [ 'post_type' => 'attachment' ];

		$this->assertSame( [ 'meta_query' => [ 'existing' ] ], $admin->filter_ajax_query_attachments_args( [ 'meta_query' => [ 'existing' ] ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Test fixture asserts the filter preserves an existing meta query.

		$_REQUEST['query'] = [ 'onemedia_sync_status' => Attachment::SYNC_STATUS_SYNC ];
		$result            = $admin->filter_ajax_query_attachments_args( $query );
		$this->assertSame( Attachment::IS_SYNC_POSTMETA_KEY, $result['meta_query'][0]['key'], 'Synced filter should query the OneMedia sync meta key' );
		$this->assertSame( '1', $result['meta_query'][0]['value'], 'Synced filter should match synced media' );

		$_REQUEST['query'] = [ 'onemedia_sync_status' => Attachment::SYNC_STATUS_NO_SYNC ];
		$result            = $admin->filter_ajax_query_attachments_args( $query );
		$this->assertSame( 'OR', $result['meta_query']['relation'], 'Unsynced filter should include false and missing sync meta' );

		$_REQUEST['query'] = [ 'is_onemedia_sync' => 'true' ];
		$result            = $admin->filter_ajax_query_attachments_args( $query );
		$this->assertSame( '1', $result['meta_query'][0]['value'], 'Boolean synced query should match synced media' );

		$_REQUEST['query'] = [ 'is_onemedia_sync' => 'false' ];
		$result            = $admin->filter_ajax_query_attachments_args( $query );
		$this->assertSame( 'OR', $result['meta_query']['relation'], 'Boolean unsynced query should include false and missing sync meta' );
	}

	/**
	 * Tests invalid AJAX nonces leave the query unchanged.
	 */
	#[RunInSeparateProcess]
	public function test_filter_ajax_query_attachments_args_returns_original_query_for_invalid_ajax_nonce(): void {
		define( 'DOING_AJAX', true );

		$query                   = [ 'post_type' => 'attachment' ];
		$_REQUEST['_ajax_nonce'] = 'invalid';
		$_REQUEST['query']       = [ 'onemedia_sync_status' => Attachment::SYNC_STATUS_SYNC ];

		$this->assertSame( $query, ( new Admin() )->filter_ajax_query_attachments_args( $query ) );
	}

	/**
	 * Tests upload filter output on first page load.
	 */
	public function test_add_sync_filter_outputs_template_on_upload_page_without_nonce(): void {
		global $pagenow;

		$previous_pagenow = $pagenow;
		$pagenow          = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.

		ob_start();
		( new Admin() )->add_sync_filter();
		$output = ob_get_clean();

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertStringContainsString( 'onemedia_sync_status', (string) $output );
	}

	/**
	 * Tests upload filter output with a selected sync status.
	 */
	public function test_add_sync_filter_outputs_selected_sync_status(): void {
		global $pagenow;

		$previous_pagenow = $pagenow;
		$pagenow          = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.

		$_GET['onemedia_sync_nonce']                  = wp_create_nonce( 'onemedia_sync_filter' );
		$_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] = Attachment::SYNC_STATUS_SYNC;

		ob_start();
		( new Admin() )->add_sync_filter();
		$output = ob_get_clean();

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertStringContainsString( 'value="' . Attachment::SYNC_STATUS_SYNC . '"  selected=\'selected\'', (string) $output );
	}

	/**
	 * Tests upload filter returns nothing outside the media screen.
	 */
	public function test_add_sync_filter_returns_nothing_outside_upload_page(): void {
		global $pagenow;

		$previous_pagenow = $pagenow;
		$pagenow          = 'plugins.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.

		ob_start();
		( new Admin() )->add_sync_filter();
		$output = ob_get_clean();

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertSame( '', (string) $output );
	}

	/**
	 * Tests invalid filter nonce returns no output.
	 */
	public function test_add_sync_filter_returns_nothing_for_invalid_nonce(): void {
		global $pagenow;

		$previous_pagenow            = $pagenow;
		$pagenow                     = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.
		$_GET['onemedia_sync_nonce'] = 'invalid';

		ob_start();
		( new Admin() )->add_sync_filter();
		$output = ob_get_clean();

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertSame( '', (string) $output );
	}

	/**
	 * Tests valid nonce with no selected status still renders the template.
	 */
	public function test_add_sync_filter_outputs_template_with_valid_nonce_and_empty_status(): void {
		global $pagenow;

		$previous_pagenow            = $pagenow;
		$pagenow                     = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.
		$_GET['onemedia_sync_nonce'] = wp_create_nonce( 'onemedia_sync_filter' );

		ob_start();
		( new Admin() )->add_sync_filter();
		$output = ob_get_clean();

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertStringContainsString( 'onemedia_sync_status', (string) $output );
	}

	/**
	 * Tests parse-query sync status filtering.
	 */
	public function test_filter_sync_attachments_sets_meta_query_for_sync_filter(): void {
		global $pagenow;

		$previous_pagenow                             = $pagenow;
		$pagenow                                      = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.
		$query                                        = new WP_Query();
		$_GET['onemedia_sync_nonce']                  = wp_create_nonce( 'onemedia_sync_filter' );
		$_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] = Attachment::SYNC_STATUS_SYNC;

		( new Admin() )->filter_sync_attachments( $query );

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertSame( Attachment::IS_SYNC_POSTMETA_KEY, $query->get( 'meta_query' )[0]['key'] );
		$this->assertSame( '1', $query->get( 'meta_query' )[0]['value'] );
	}

	/**
	 * Tests parse-query no-sync status filtering.
	 */
	public function test_filter_sync_attachments_sets_or_meta_query_for_no_sync_filter(): void {
		global $pagenow;

		$previous_pagenow                             = $pagenow;
		$pagenow                                      = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.
		$query                                        = new WP_Query();
		$_GET['onemedia_sync_nonce']                  = wp_create_nonce( 'onemedia_sync_filter' );
		$_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] = Attachment::SYNC_STATUS_NO_SYNC;

		( new Admin() )->filter_sync_attachments( $query );

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertSame( 'OR', $query->get( 'meta_query' )['relation'] );
	}

	/**
	 * Tests parse-query returns early when no sync status is selected.
	 */
	public function test_filter_sync_attachments_returns_early_when_sync_status_missing(): void {
		global $pagenow;

		$previous_pagenow = $pagenow;
		$pagenow          = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.
		$query            = new WP_Query();

		( new Admin() )->filter_sync_attachments( $query );

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertSame( '', $query->get( 'meta_query' ) );
	}

	/**
	 * Tests parse-query returns early for missing or invalid nonces.
	 */
	public function test_filter_sync_attachments_returns_early_for_missing_or_invalid_nonce(): void {
		global $pagenow;

		$previous_pagenow                             = $pagenow;
		$pagenow                                      = 'upload.php'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture sets the current admin page.
		$_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ] = Attachment::SYNC_STATUS_SYNC;

		$missing_nonce_query = new WP_Query();
		( new Admin() )->filter_sync_attachments( $missing_nonce_query );
		$this->assertSame( '', $missing_nonce_query->get( 'meta_query' ) );

		$_GET['onemedia_sync_nonce'] = 'invalid';
		$invalid_nonce_query         = new WP_Query();
		( new Admin() )->filter_sync_attachments( $invalid_nonce_query );

		$pagenow = $previous_pagenow; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore test global.

		$this->assertSame( '', $invalid_nonce_query->get( 'meta_query' ) );
	}

	/**
	 * Registers placeholder media-library assets for enqueue tests.
	 */
	private function register_media_library_assets(): void {
		wp_register_style( Assets::MEDIA_TAXONOMY_STYLE_HANDLE, false, [], '1.0.0' );
		wp_register_script( Assets::MEDIA_SYNC_FILTER_SCRIPT_HANDLE, false, [], '1.0.0', true );
		wp_register_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE, false, [], '1.0.0', true );
	}
}
