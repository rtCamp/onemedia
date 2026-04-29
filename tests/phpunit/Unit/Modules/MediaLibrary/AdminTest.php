<?php
/**
 * Tests for media library admin helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaLibrary
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaLibrary;

use OneMedia\Modules\MediaLibrary\Admin;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_Query;

/**
 * @covers \OneMedia\Modules\MediaLibrary\Admin
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		unset( $_REQUEST['query'], $_REQUEST['_ajax_nonce'], $_GET[ Attachment::SYNC_STATUS_POSTMETA_KEY ], $_GET['onemedia_sync_nonce'] );

		parent::tearDown();
	}

	/**
	 * Tests hook registration.
	 */
	public function test_register_hooks_adds_expected_callbacks(): void {
		$admin = new Admin();

		$admin->register_hooks();

		$this->assertSame( 20, has_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_scripts' ] ) );
		$this->assertSame( 10, has_filter( 'ajax_query_attachments_args', [ $admin, 'filter_ajax_query_attachments_args' ] ) );
		$this->assertSame( 10, has_action( 'restrict_manage_posts', [ $admin, 'add_sync_filter' ] ) );
		$this->assertSame( 10, has_action( 'parse_query', [ $admin, 'filter_sync_attachments' ] ) );
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
}
