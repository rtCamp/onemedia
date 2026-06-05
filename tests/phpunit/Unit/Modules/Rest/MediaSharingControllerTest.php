<?php
/**
 * Tests for the Rest\Media_Sharing_Controller class.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Rest;

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Rest\Media_Sharing_Controller;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the Rest\Media_Sharing_Controller class.
 */
#[CoversClass( Media_Sharing_Controller::class )]
final class MediaSharingControllerTest extends TestCase {
	/**
	 * Controller under test.
	 *
	 * @var \OneMedia\Modules\Rest\Media_Sharing_Controller
	 */
	private Media_Sharing_Controller $controller;

	/**
	 * Attachment ID for testing.
	 *
	 * @var int
	 */
	private int $attachment_id;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->controller    = new Media_Sharing_Controller();
		$this->attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
			]
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );
		delete_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION );

		parent::tearDown();
	}

	/**
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_rest_api_init_action(): void {
		$this->controller->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests brand_sites_synced_media_callback returns an empty mapping when no data is stored.
	 */
	public function test_brand_sites_synced_media_callback_returns_empty_mapping(): void {
		$response = $this->controller->brand_sites_synced_media_callback();

		$this->assertSame(
			[
				'status'  => 200,
				'data'    => [],
				'success' => true,
			],
			$response->get_data()
		);
	}

	/**
	 * Tests brand_sites_synced_media_callback maps URLs to known and unknown site names.
	 */
	public function test_brand_sites_synced_media_callback_maps_site_names(): void {
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
			]
		);
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$this->attachment_id => [
					'https://brand-one.example/' => 11,
					'https://unknown.example/'   => 22,
				],
			]
		);

		$response = $this->controller->brand_sites_synced_media_callback();

		$this->assertSame(
			[
				'status'  => 200,
				'data'    => [
					$this->attachment_id => [
						'https://brand-one.example' => 'Brand One',
						'https://unknown.example'   => 'Unknown Site',
					],
				],
				'success' => true,
			],
			$response->get_data()
		);
	}

	/**
	 * Tests delete_media_metadata returns an error for invalid attachment IDs.
	 */
	public function test_delete_media_metadata_returns_error_for_invalid_attachment(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/delete-media-metadata' );
		$request->set_param( 'attachment_id', 0 );

		$result = $this->controller->delete_media_metadata( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
		$this->assertSame(
			[
				'status'  => 400,
				'success' => false,
			],
			$result->get_error_data()
		);
	}

	/**
	 * Tests delete_media_metadata deletes sync meta for valid attachments.
	 */
	public function test_delete_media_metadata_deletes_sync_meta(): void {
		Attachment::set_is_synced( $this->attachment_id, true );
		update_post_meta( $this->attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, [ 'https://brand.example' => 12 ] );
		update_post_meta( $this->attachment_id, Attachment::SYNC_STATUS_POSTMETA_KEY, Attachment::SYNC_STATUS_SYNC );

		$request = new WP_REST_Request( 'POST', '/onemedia/v1/delete-media-metadata' );
		$request->set_param( 'attachment_id', $this->attachment_id );

		$response = $this->controller->delete_media_metadata( $request );

		$this->assertSame(
			[
				'message' => 'Media metadata deleted successfully.',
				'status'  => 200,
				'success' => true,
			],
			$response->get_data()
		);
		$this->assertFalse( Attachment::is_sync_attachment( $this->attachment_id ) );
		$this->assertSame( '', get_post_meta( $this->attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, true ) );
		$this->assertSame( '', get_post_meta( $this->attachment_id, Attachment::SYNC_STATUS_POSTMETA_KEY, true ) );
	}

	/**
	 * Tests update_media_files returns an error for invalid attachment IDs.
	 */
	public function test_update_media_files_returns_error_for_invalid_attachment(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/update-media' );
		$request->set_param( 'attachment_id', 0 );

		$result = $this->controller->update_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests update_media_files returns an error for invalid URLs.
	 */
	public function test_update_media_files_returns_error_for_invalid_url(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/update-media' );
		$request->set_param( 'attachment_id', $this->attachment_id );
		$request->set_param( 'attachment_url', 'not-a-url' );
		$request->set_param( 'attachment_data', [ 'title' => 'Updated title' ] );

		$result = $this->controller->update_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests update_media_files returns an error for invalid attachment data.
	 */
	public function test_update_media_files_returns_error_for_invalid_attachment_data(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/update-media' );
		$request->set_param( 'attachment_id', $this->attachment_id );
		$request->set_param( 'attachment_url', 'https://example.com/image.jpg' );
		$request->set_param( 'attachment_data', [ 'title' => [ 'invalid' ] ] );

		$result = $this->controller->update_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
		$this->assertSame(
			[
				'status'  => 400,
				'success' => false,
			],
			$result->get_error_data()
		);
	}

	/**
	 * Tests get_media_files returns an empty successful response by default.
	 */
	public function test_get_media_files_returns_empty_response_by_default(): void {
		$request = new WP_REST_Request( 'GET', '/onemedia/v1/media' );

		$response = $this->controller->get_media_files( $request );
		$data     = $response->get_data();

		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 10, $data['per_page'] );
		$this->assertSame( 200, $data['status'] );
		$this->assertTrue( $data['success'] );
		$this->assertIsArray( $data['media_files'] );
	}

	/**
	 * Tests sync_media_files validates brand sites.
	 */
	public function test_sync_media_files_returns_error_for_invalid_brand_sites(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
		$request->set_param( 'brand_sites', [] );

		$result = $this->controller->sync_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests sync_media_files validates site URLs.
	 */
	public function test_sync_media_files_returns_error_for_invalid_site_url(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
		$request->set_param( 'brand_sites', [ 'not-a-url' ] );

		$result = $this->controller->sync_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_site_url', $result->get_error_code() );
	}

	/**
	 * Tests sync_media_files validates sync option.
	 */
	public function test_sync_media_files_returns_error_for_invalid_sync_option(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
		$request->set_param( 'brand_sites', [ 'https://brand.test' ] );
		$request->set_param( 'sync_option', 'invalid' );

		$result = $this->controller->sync_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_sync_option', $result->get_error_code() );
	}

	/**
	 * Tests sync_media_files validates media details.
	 */
	public function test_sync_media_files_returns_error_for_invalid_media_details(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
		$request->set_param( 'brand_sites', [ 'https://brand.test' ] );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param( 'media_details', [] );

		$result = $this->controller->sync_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests sync_media_files validates unsupported mime types before remote calls.
	 */
	public function test_sync_media_files_returns_error_for_invalid_mime_type(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
		$request->set_param( 'brand_sites', [ 'https://brand.test' ] );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param(
			'media_details',
			[
				[
					'id'        => $this->attachment_id,
					'url'       => 'https://example.com/document.pdf',
					'title'     => 'Document',
					'mime_type' => 'application/pdf',
				],
			]
		);

		$result = $this->controller->sync_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_mime_type', $result->get_error_code() );
	}

	/**
	 * Tests add_media_files validates sync option.
	 */
	public function test_add_media_files_returns_error_for_invalid_sync_option(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$request->set_param( 'sync_option', 'invalid' );

		$result = $this->controller->add_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_sync_option', $result->get_error_code() );
	}

	/**
	 * Tests add_media_files validates media files.
	 */
	public function test_add_media_files_returns_error_for_invalid_media_files(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param( 'media_files', [] );

		$result = $this->controller->add_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests add_media_files validates individual media details.
	 */
	public function test_add_media_files_returns_error_for_invalid_media_details(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param( 'media_files', [ [ 'id' => 0 ] ] );

		$result = $this->controller->add_media_files( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_media_details', $result->get_error_code() );
	}

	/**
	 * Tests update_existing_attachment validates attachment IDs.
	 */
	public function test_update_existing_attachment_returns_error_for_invalid_attachment(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );
		$request->set_param( 'attachment_id', 0 );

		$result = $this->controller->update_existing_attachment( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests update_existing_attachment validates sync options.
	 */
	public function test_update_existing_attachment_returns_error_for_invalid_sync_option(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );
		$request->set_param( 'attachment_id', $this->attachment_id );
		$request->set_param( 'sync_option', 'invalid' );

		$result = $this->controller->update_existing_attachment( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
	}

	/**
	 * Tests update_existing_attachment marks an attachment as not synced.
	 */
	public function test_update_existing_attachment_marks_attachment_as_not_synced(): void {
		Attachment::set_is_synced( $this->attachment_id, true );

		$request = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );
		$request->set_param( 'attachment_id', $this->attachment_id );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_NO_SYNC );

		$response = $this->controller->update_existing_attachment( $request );

		$this->assertSame(
			[
				'attachment_id' => $this->attachment_id,
				'message'       => 'Attachment marked as sync media.',
				'status'        => 200,
				'success'       => true,
			],
			$response->get_data()
		);
		$this->assertFalse( Attachment::is_sync_attachment( $this->attachment_id ) );
	}

	/**
	 * Tests is_sync_attachment returns an error for invalid attachment IDs.
	 */
	public function test_is_sync_attachment_returns_error_for_invalid_attachment(): void {
		$request = new WP_REST_Request( 'GET', '/onemedia/v1/is-sync-attachment' );
		$request->set_param( 'attachment_id', 0 );

		$result = $this->controller->is_sync_attachment( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_attachment_id', $result->get_error_code() );
	}

	/**
	 * Tests is_sync_attachment returns the attachment sync status.
	 */
	public function test_is_sync_attachment_returns_sync_status(): void {
		Attachment::set_is_synced( $this->attachment_id, true );

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/is-sync-attachment' );
		$request->set_param( 'attachment_id', $this->attachment_id );

		$response = $this->controller->is_sync_attachment( $request );

		$this->assertSame(
			[
				'attachment_id' => $this->attachment_id,
				'is_sync'       => true,
				'status'        => 200,
				'success'       => true,
			],
			$response->get_data()
		);
	}

	/**
	 * Tests sync_attachment_versions returns an error for invalid attachment IDs.
	 */
	public function test_sync_attachment_versions_returns_error_for_invalid_attachment(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-attachment-versions' );
		$request->set_param( 'attachment_id', 0 );

		$result = $this->controller->sync_attachment_versions( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_attachment_id', $result->get_error_code() );
	}

	/**
	 * Tests sync_attachment_versions returns an error for non-synced attachments.
	 */
	public function test_sync_attachment_versions_returns_error_for_non_synced_attachment(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-attachment-versions' );
		$request->set_param( 'attachment_id', $this->attachment_id );

		$result = $this->controller->sync_attachment_versions( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'not_sync_attachment', $result->get_error_code() );
	}

	/**
	 * Tests sync_attachment_versions returns stored versions for synced attachments.
	 */
	public function test_sync_attachment_versions_returns_versions_for_synced_attachment(): void {
		$versions = [
			[
				'last_used' => 1000000,
				'file'      => [ 'url' => 'https://example.com/image.jpg' ],
			],
		];
		Attachment::set_is_synced( $this->attachment_id, true );
		Attachment::update_sync_attachment_versions( $this->attachment_id, $versions );

		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-attachment-versions' );
		$request->set_param( 'attachment_id', $this->attachment_id );

		$response = $this->controller->sync_attachment_versions( $request );

		$this->assertSame(
			[
				'attachment_id' => $this->attachment_id,
				'versions'      => $versions,
				'status'        => 200,
				'success'       => true,
			],
			$response->get_data()
		);
	}
}
