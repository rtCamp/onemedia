<?php
/**
 * Tests for the MediaLibrary\Admin class.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaLibrary
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaLibrary;

use OneMedia\Modules\MediaLibrary\Admin;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MediaLibrary\Admin class.
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * Original $_REQUEST state, restored after each test.
	 *
	 * @var array<string, mixed>
	 */
	private array $original_request;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_request = $_REQUEST; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		$_REQUEST = $this->original_request; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		parent::tearDown();
	}

	/**
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$admin = new Admin();
		$admin->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests filter_ajax_query_attachments_args returns unchanged when meta_query is already set.
	 */
	public function test_filter_ajax_query_attachments_args_passthrough_when_meta_query_present(): void {
		$admin = new Admin();
		$query = [
			'meta_query' => [ // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				[
					'key'     => 'custom_key',
					'value'   => '1',
					'compare' => '=',
				],
			],
		];

		$this->assertSame( $query, $admin->filter_ajax_query_attachments_args( $query ) );
	}

	/**
	 * Tests filter_ajax_query_attachments_args returns unchanged when no sync filter is in the request.
	 */
	public function test_filter_ajax_query_attachments_args_passthrough_without_sync_filter(): void {
		$admin = new Admin();
		$query = [ 'post_type' => 'attachment' ];

		$this->assertSame( $query, $admin->filter_ajax_query_attachments_args( $query ) );
	}

	/**
	 * Tests filter_ajax_query_attachments_args adds sync meta_query for onemedia_sync_status = sync.
	 */
	public function test_filter_ajax_query_attachments_args_adds_meta_query_for_sync_status(): void {
		$_REQUEST['query'] = [ 'onemedia_sync_status' => Attachment::SYNC_STATUS_SYNC ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$admin  = new Admin();
		$result = $admin->filter_ajax_query_attachments_args( [ 'post_type' => 'attachment' ] );

		$this->assertSame(
			[
				[
					'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
					'value'   => '1',
					'compare' => '=',
				],
			],
			$result['meta_query'] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
	}

	/**
	 * Tests filter_ajax_query_attachments_args adds no_sync meta_query for onemedia_sync_status = no_sync.
	 */
	public function test_filter_ajax_query_attachments_args_adds_meta_query_for_no_sync_status(): void {
		$_REQUEST['query'] = [ 'onemedia_sync_status' => Attachment::SYNC_STATUS_NO_SYNC ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$admin  = new Admin();
		$result = $admin->filter_ajax_query_attachments_args( [ 'post_type' => 'attachment' ] );

		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
					'value'   => '0',
					'compare' => '=',
				],
				[
					'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
			$result['meta_query'] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
	}

	/**
	 * Tests filter_ajax_query_attachments_args adds sync meta_query for is_onemedia_sync = true.
	 */
	public function test_filter_ajax_query_attachments_args_adds_meta_query_for_is_onemedia_sync_true(): void {
		$_REQUEST['query'] = [ 'is_onemedia_sync' => 'true' ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$admin  = new Admin();
		$result = $admin->filter_ajax_query_attachments_args( [ 'post_type' => 'attachment' ] );

		$this->assertSame(
			[
				[
					'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
					'value'   => '1',
					'compare' => '=',
				],
			],
			$result['meta_query'] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
	}

	/**
	 * Tests filter_ajax_query_attachments_args adds no_sync meta_query for is_onemedia_sync = false.
	 */
	public function test_filter_ajax_query_attachments_args_adds_meta_query_for_is_onemedia_sync_false(): void {
		$_REQUEST['query'] = [ 'is_onemedia_sync' => 'false' ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$admin  = new Admin();
		$result = $admin->filter_ajax_query_attachments_args( [ 'post_type' => 'attachment' ] );

		$this->assertSame(
			[
				'relation' => 'OR',
				[
					'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
					'value'   => '0',
					'compare' => '=',
				],
				[
					'key'     => Attachment::IS_SYNC_POSTMETA_KEY,
					'compare' => 'NOT EXISTS',
				],
			],
			$result['meta_query'] // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		);
	}
}
