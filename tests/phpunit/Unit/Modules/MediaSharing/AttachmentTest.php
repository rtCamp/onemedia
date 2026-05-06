<?php
/**
 * Tests for attachment media sharing helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Attachment::class )]
final class AttachmentTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		parent::tearDown();
	}

	/**
	 * Tests no errors on class instantiation.
	 */
	public function test_class_instantiation(): void {
		$attachment = new Attachment();

		$attachment->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests attachment post meta registration.
	 */
	public function test_register_attachment_post_meta_registers_expected_meta_keys(): void {
		( new Attachment() )->register_attachment_post_meta();

		$is_sync_args = get_registered_meta_keys( 'post', 'attachment' )[ Attachment::IS_SYNC_POSTMETA_KEY ];
		$versions     = get_registered_meta_keys( 'post', 'attachment' )[ Attachment::SYNC_VERSIONS_POSTMETA_KEY ];

		$this->assertTrue( $is_sync_args['single'] );
		$this->assertSame( 'boolean', $is_sync_args['type'] );
		$this->assertFalse( $is_sync_args['auth_callback']() );
		$this->assertSame( 'array', $versions['type'] );
		$this->assertFalse( $versions['auth_callback']() );
		$this->assertSame( [], $versions['sanitize_callback']( 'invalid' ) );
		$this->assertSame( [ 'valid' ], $versions['sanitize_callback']( [ 'valid' ] ) );
	}

	/**
	 * Tests sync site metadata access.
	 */
	public function test_get_sync_sites_requires_governing_site_and_array_meta(): void {
		$attachment_id = self::factory()->attachment->create();

		update_post_meta( $attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, [ [ 'site' => 'https://brand.test' ] ] );

		$this->assertSame( [], Attachment::get_sync_sites( $attachment_id ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertSame( [ [ 'site' => 'https://brand.test' ] ], Attachment::get_sync_sites( $attachment_id ) );

		update_post_meta( $attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, 'invalid' );

		$this->assertSame( [], Attachment::get_sync_sites( $attachment_id ) );
		$this->assertSame( [], Attachment::get_sync_sites( 0 ) );
	}

	/**
	 * Tests sync status helpers.
	 */
	public function test_sync_status_helpers_read_and_write_attachment_meta(): void {
		$attachment_id = self::factory()->attachment->create();

		$this->assertFalse( Attachment::is_sync_attachment( 0 ) );
		$this->assertFalse( Attachment::is_sync_attachment( $attachment_id ) );

		$this->assertIsInt( Attachment::set_is_synced( $attachment_id, true ) );
		$this->assertTrue( Attachment::is_sync_attachment( $attachment_id ) );
	}

	/**
	 * Tests sync version helpers.
	 */
	public function test_sync_attachment_version_helpers_validate_and_store_versions(): void {
		$attachment_id = self::factory()->attachment->create();
		$versions      = [
			[
				'last_used' => 123,
				'file'      => [
					'path' => '/tmp/image.jpg',
				],
			],
		];

		$this->assertFalse( Attachment::update_sync_attachment_versions( 0, $versions ) );
		$this->assertFalse( Attachment::update_sync_attachment_versions( $attachment_id, [] ) );
		$this->assertSame( [], Attachment::get_sync_attachment_versions( 0 ) );
		$this->assertSame( [], Attachment::get_sync_attachment_versions( $attachment_id ) );

		$this->assertTrue( Attachment::update_sync_attachment_versions( $attachment_id, $versions ) );
		$this->assertSame( $versions, Attachment::get_sync_attachment_versions( $attachment_id ) );

		update_post_meta( $attachment_id, Attachment::SYNC_VERSIONS_POSTMETA_KEY, 'invalid' );
		$this->assertSame( [], Attachment::get_sync_attachment_versions( $attachment_id ) );
	}

	/**
	 * Tests health check responses for invalid and empty attachments.
	 */
	public function test_health_check_attachment_brand_sites_handles_invalid_or_unshared_attachments(): void {
		$this->assertSame(
			[
				'success'      => false,
				'failed_sites' => [],
				'message'      => 'Invalid attachment ID.',
			],
			Attachment::health_check_attachment_brand_sites( null )
		);

		$attachment_id = self::factory()->attachment->create();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertSame(
			[
				'success'      => true,
				'failed_sites' => [],
				'message'      => 'No connected brand sites for this attachment.',
			],
			Attachment::health_check_attachment_brand_sites( $attachment_id )
		);
	}

	/**
	 * Tests health check behavior for missing API keys, failed requests and successful requests.
	 */
	public function test_health_check_attachment_brand_sites_checks_unique_brand_sites(): void {
		$attachment_id = self::factory()->attachment->create();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[ 'site' => 'https://missing-key.test' ],
				[ 'site' => 'https://error-site.test' ],
				[ 'site' => 'https://ok-site.test' ],
				[ 'site' => 'https://ok-site.test/' ],
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Error Site',
					'url'     => 'https://error-site.test',
					'api_key' => 'error-key',
				],
				[
					'name'    => 'Ok Site',
					'url'     => 'https://ok-site.test',
					'api_key' => 'ok-key',
				],
			]
		);

		$http_filter = static function ( $response, array $parsed_args, string $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter
			if ( str_contains( $url, 'error-site.test' ) ) {
				return new \WP_Error( 'http_request_failed', 'Connection failed' );
			}

			return [
				'headers'  => [],
				'body'     => '',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};

		add_filter( 'pre_http_request', $http_filter, 10, 3 );

		$result = Attachment::health_check_attachment_brand_sites( $attachment_id );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 2, $result['failed_sites'] );
		$this->assertSame( 'API key not found', $result['failed_sites'][0]['message'] );
		$this->assertSame( '', $result['failed_sites'][0]['site_name'] );
		$this->assertSame( 'Connection failed', $result['failed_sites'][1]['message'] );
		$this->assertStringContainsString( 'Error Site', $result['message'] );
	}

	/**
	 * Tests health check behavior for HTTP failures and all-success responses.
	 */
	public function test_health_check_attachment_brand_sites_handles_http_status_and_success(): void {
		$attachment_id = self::factory()->attachment->create();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[ 'site' => 'https://bad-status.test' ],
				[ 'missing_site_key' => 'https://skipped.test' ],
				[ 'site' => 'https://bad-status.test/' ],
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Bad Status',
					'url'     => 'https://bad-status.test',
					'api_key' => 'bad-status-key',
				],
			]
		);

		$http_filter = static fn () => [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 500,
				'message' => 'Server Error',
			],
			'cookies'  => [],
			'filename' => null,
		];

		add_filter( 'pre_http_request', $http_filter, 10, 0 );

		$result = Attachment::health_check_attachment_brand_sites( $attachment_id );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 1, $result['failed_sites'] );
		$this->assertSame( 'HTTP 500 response', $result['failed_sites'][0]['message'] );

		$success_filter = static fn () => [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 200,
				'message' => 'OK',
			],
			'cookies'  => [],
			'filename' => null,
		];

		add_filter( 'pre_http_request', $success_filter, 10, 0 );

		$result = Attachment::health_check_attachment_brand_sites( $attachment_id );

		remove_filter( 'pre_http_request', $success_filter, 10 );

		$this->assertSame(
			[
				'success'      => true,
				'failed_sites' => [],
				'message'      => 'All connected sites are reachable.',
			],
			$result
		);
	}

	/**
	 * Tests health check keeps duplicate failed entries but deduplicates site names in the summary.
	 */
	public function test_health_check_attachment_brand_sites_deduplicates_failed_site_names_in_message(): void {
		$attachment_id = self::factory()->attachment->create();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[ 'site' => 'https://dup-status-a.test' ],
				[ 'site' => 'https://dup-status-b.test' ],
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Duplicate Status',
					'url'     => 'https://dup-status-a.test',
					'api_key' => 'dup-key-a',
				],
				[
					'name'    => 'Duplicate Status',
					'url'     => 'https://dup-status-b.test',
					'api_key' => 'dup-key-b',
				],
			]
		);

		$http_filter = static fn () => [
			'headers'  => [],
			'body'     => '',
			'response' => [
				'code'    => 500,
				'message' => 'Server Error',
			],
			'cookies'  => [],
			'filename' => null,
		];

		add_filter( 'pre_http_request', $http_filter, 10, 0 );
		$result = Attachment::health_check_attachment_brand_sites( $attachment_id );
		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertFalse( $result['success'] );
		$this->assertCount( 2, $result['failed_sites'] );
		$this->assertSame( 'HTTP 500 response', $result['failed_sites'][0]['message'] );
		$this->assertSame( 'Duplicate Status', $result['failed_sites'][0]['site_name'] );
		$this->assertSame( 1, substr_count( $result['message'], 'Duplicate Status' ) );
	}
}
