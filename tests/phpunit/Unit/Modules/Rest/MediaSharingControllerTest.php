<?php
/**
 * Tests for media sharing REST controller.
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
 * @covers \OneMedia\Modules\Rest\Media_Sharing_Controller
 * @covers \OneMedia\Modules\Rest\Abstract_REST_Controller
 */
#[CoversClass( Media_Sharing_Controller::class )]
final class MediaSharingControllerTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );
		delete_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION );

		parent::tearDown();
	}

	/**
	 * Invoke a private controller method.
	 *
	 * @param object|class-string $target      Target object or class.
	 * @param string              $method_name Method name.
	 * @param array<mixed>        $args        Arguments.
	 */
	private function invoke_private_method( object|string $target, string $method_name, array $args = [] ): mixed {
		$method = new \ReflectionMethod( $target, $method_name );

		return $method->invokeArgs( is_object( $target ) ? $target : null, $args );
	}

	/**
	 * Tests synced media mapping response.
	 */
	public function test_brand_sites_synced_media_callback_maps_urls_to_names(): void {
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test/',
					'api_key' => 'key',
				],
			]
		);
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				12 => [
					'https://brand.test/'   => 34,
					'https://unknown.test/' => 56,
				],
			],
			false
		);

		$data = ( new Media_Sharing_Controller() )->brand_sites_synced_media_callback()->get_data();

		$this->assertSame( 'Brand', $data['data'][12]['https://brand.test'] );
		$this->assertSame( 'Unknown Site', $data['data'][12]['https://unknown.test'] );
	}

	/**
	 * Tests metadata deletion endpoint.
	 */
	public function test_delete_media_metadata_validates_and_deletes_attachment_meta(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/delete-media-metadata' );

		$request->set_param( 'attachment_id', 0 );
		$this->assertSame( 'invalid_data', $controller->delete_media_metadata( $request )->get_error_code() );

		$attachment_id = self::factory()->attachment->create();
		Attachment::set_is_synced( $attachment_id, true );
		update_post_meta( $attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, [ [ 'site' => 'https://brand.test' ] ] );
		update_post_meta( $attachment_id, Attachment::SYNC_STATUS_POSTMETA_KEY, Attachment::SYNC_STATUS_SYNC );
		$request->set_param( 'attachment_id', $attachment_id );

		$response = $controller->delete_media_metadata( $request )->get_data();

		$this->assertTrue( $response['success'] );
		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::SYNC_STATUS_POSTMETA_KEY, true ) );
	}

	/**
	 * Tests media listing endpoint.
	 */
	public function test_get_media_files_returns_non_synced_and_synced_lists(): void {
		$controller  = new Media_Sharing_Controller();
		$unsynced_id = self::factory()->attachment->create(
			[
				'post_title'     => 'Local Image',
				'post_mime_type' => 'image/jpeg',
			]
		);
		$synced_id   = self::factory()->attachment->create(
			[
				'post_title'     => 'Synced Image',
				'post_mime_type' => 'image/png',
			]
		);
		Attachment::set_is_synced( $synced_id, true );
		Attachment::update_sync_attachment_versions( $synced_id, [ [ 'file' => [ 'path' => '/tmp/synced.png' ] ] ] );

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/media' );
		$request->set_param( 'page', 0 );
		$request->set_param( 'per_page', 150 );
		$data = $controller->get_media_files( $request )->get_data();

		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 100, $data['per_page'] );
		$this->assertContains( $unsynced_id, wp_list_pluck( $data['media_files'], 'id' ) );

		$request->set_param( 'image_type', 'synced' );
		$data = $controller->get_media_files( $request )->get_data();

		$this->assertContains( $synced_id, wp_list_pluck( $data['media_files'], 'id' ) );
	}

	/**
	 * Tests sync media validation branches.
	 */
	public function test_sync_media_files_validates_inputs_before_remote_requests(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );

		$this->assertSame( 'invalid_data', $controller->sync_media_files( $request )->get_error_code() );

		$request->set_param( 'brand_sites', [ 'not-a-url' ] );
		$this->assertSame( 'invalid_site_url', $controller->sync_media_files( $request )->get_error_code() );

		$request->set_param( 'brand_sites', [ 'https://brand.test' ] );
		$request->set_param( 'sync_option', 'bad' );
		$this->assertSame( 'invalid_sync_option', $controller->sync_media_files( $request )->get_error_code() );

		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$this->assertSame( 'invalid_data', $controller->sync_media_files( $request )->get_error_code() );

		$request->set_param( 'media_details', [ [ 'id' => 0 ] ] );
		$this->assertSame( 'invalid_media_details', $controller->sync_media_files( $request )->get_error_code() );

		$request->set_param(
			'media_details',
			[
				[
					'id'        => 123,
					'url'       => 'https://example.test/file.txt',
					'title'     => 'File',
					'mime_type' => 'text/plain',
				],
			]
		);
		$this->assertSame( 'invalid_mime_type', $controller->sync_media_files( $request )->get_error_code() );
	}

	/**
	 * Tests sync media happy path with mocked remote response.
	 */
	public function test_sync_media_files_updates_sync_sites_from_successful_remote_response(): void {
		$controller    = new Media_Sharing_Controller();
		$attachment_id = self::factory()->attachment->create(
			[
				'post_title'     => 'File',
				'post_mime_type' => 'image/jpeg',
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'token',
				],
			]
		);

		$http_filter = static function () use ( $attachment_id ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'media' => [
							[
								'id'        => 987,
								'parent_id' => $attachment_id,
							],
						],
					]
				),
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $http_filter, 10, 0 );

		$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
		$request->set_param( 'brand_sites', [ 'https://brand.test/' ] );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param(
			'media_details',
			[
				[
					'id'        => $attachment_id,
					'url'       => 'https://example.test/file.jpg',
					'title'     => 'File',
					'mime_type' => 'image/jpeg',
				],
			]
		);

		$data = $controller->sync_media_files( $request )->get_data();

		remove_filter( 'pre_http_request', $http_filter, 10 );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertTrue( $data['success'] );
		$this->assertTrue( Attachment::is_sync_attachment( $attachment_id ) );
		$this->assertSame(
			[
				[
					'site' => 'https://brand.test/',
					'id'   => 987,
				],
			],
			Attachment::get_sync_sites( $attachment_id )
		);
		$this->assertSame( [ 'https://brand.test/' => 987 ], Settings::get_brand_sites_synced_media()[ $attachment_id ] );
	}

	/**
	 * Tests add-media validation and already mapped branch.
	 */
	public function test_add_media_files_validates_and_uses_existing_attachment_mapping(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );

		$this->assertSame( 'invalid_sync_option', $controller->add_media_files( $request )->get_error_code() );

		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$this->assertSame( 'invalid_data', $controller->add_media_files( $request )->get_error_code() );

		$request->set_param( 'media_files', [ [ 'id' => 0 ] ] );
		$this->assertSame( 'invalid_media_details', $controller->add_media_files( $request )->get_error_code() );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		update_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION, [ 55 => $attachment_id ], false );
		$request->set_param(
			'media_files',
			[
				[
					'id'              => 55,
					'url'             => 'https://example.test/file.jpg',
					'title'           => 'File',
					'mime_type'       => 'image/jpeg',
					'attachment_data' => [
						'post_title' => 'Updated File',
						'alt_text'   => 'Alt text',
					],
				],
			]
		);

		$data = $controller->add_media_files( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $attachment_id, $data['media'][0]['id'] );
		$this->assertTrue( Attachment::is_sync_attachment( $attachment_id ) );
		$this->assertSame( 'Updated File', get_the_title( $attachment_id ) );
		$this->assertSame( 'Alt text', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Tests add-media unsupported type response.
	 */
	public function test_add_media_files_returns_unsupported_type_error(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param(
			'media_files',
			[
				[
					'id'        => 55,
					'url'       => 'https://example.test/file.txt',
					'title'     => 'File',
					'mime_type' => 'text/plain',
				],
			]
		);

		$result = ( new Media_Sharing_Controller() )->add_media_files( $request );

		$this->assertSame( 'unsupported_file_types', $result->get_error_code() );
		$this->assertTrue( $result->get_error_data()['is_mime_type_error'] );
	}

	/**
	 * Tests existing attachment update endpoint.
	 */
	public function test_update_existing_attachment_validates_and_marks_no_sync(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );

		$request->set_param( 'attachment_id', 0 );
		$this->assertSame( 'invalid_data', $controller->update_existing_attachment( $request )->get_error_code() );

		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'application/pdf' ] );
		$request->set_param( 'attachment_id', $attachment_id );
		$request->set_param( 'sync_option', 'bad' );
		$this->assertSame( 'invalid_data', $controller->update_existing_attachment( $request )->get_error_code() );

		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$this->assertSame( 'invalid_data', $controller->update_existing_attachment( $request )->get_error_code() );

		wp_update_post(
			[
				'ID'             => $attachment_id,
				'post_mime_type' => 'image/jpeg',
			]
		);
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_NO_SYNC );
		$data = $controller->update_existing_attachment( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertFalse( Attachment::is_sync_attachment( $attachment_id ) );
	}

	/**
	 * Tests sync state and version endpoints.
	 */
	public function test_sync_state_and_versions_endpoints(): void {
		$controller    = new Media_Sharing_Controller();
		$request       = new WP_REST_Request( 'POST', '/onemedia/v1/is-sync-attachment' );
		$attachment_id = self::factory()->attachment->create();

		$request->set_param( 'attachment_id', 0 );
		$this->assertSame( 'invalid_attachment_id', $controller->is_sync_attachment( $request )->get_error_code() );

		$request->set_param( 'attachment_id', $attachment_id );
		$this->assertFalse( $controller->is_sync_attachment( $request )->get_data()['is_sync'] );

		$this->assertSame( 'not_sync_attachment', $controller->sync_attachment_versions( $request )->get_error_code() );

		Attachment::set_is_synced( $attachment_id, true );
		Attachment::update_sync_attachment_versions( $attachment_id, [ [ 'file' => [ 'path' => '/tmp/file.jpg' ] ] ] );
		$this->assertTrue( $controller->is_sync_attachment( $request )->get_data()['is_sync'] );
		$this->assertSame( [ [ 'file' => [ 'path' => '/tmp/file.jpg' ] ] ], $controller->sync_attachment_versions( $request )->get_data()['versions'] );
	}

	/**
	 * Tests private utility helpers.
	 */
	public function test_private_utility_helpers(): void {
		$controller = new Media_Sharing_Controller();

		$this->assertSame( 'SVG', $this->invoke_private_method( Media_Sharing_Controller::class, 'get_file_type_label', [ 'image/svg+xml' ] ) );
		$this->assertSame( '', $this->invoke_private_method( Media_Sharing_Controller::class, 'get_file_type_label', [ '' ] ) );
		$this->assertTrue( $this->invoke_private_method( Media_Sharing_Controller::class, 'is_valid_url', [ 'https://example.test/path/file.jpg' ] ) );
		$this->assertFalse( $this->invoke_private_method( Media_Sharing_Controller::class, 'is_valid_url', [ 'not-a-url' ] ) );

		$this->assertSame( [], $this->invoke_private_method( Media_Sharing_Controller::class, 'get_attachment_key_map' ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		update_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION, [ 1 => 2 ], false );
		$this->assertSame( [ 1 => 2 ], $this->invoke_private_method( Media_Sharing_Controller::class, 'get_attachment_key_map' ) );

		$result = $this->invoke_private_method( $controller, 'handle_local_url', [ 'https://example.test' ] );
		$this->assertSame( 'invalid_url', $result->get_error_code() );
	}

	/**
	 * Tests remote file fetching through mocked HTTP responses.
	 */
	public function test_fetch_remote_file_handles_failure_and_success(): void {
		$controller = new Media_Sharing_Controller();

		$failure_filter = static function () {
			return [
				'headers'  => [],
				'body'     => '',
				'response' => [
					'code'    => 404,
					'message' => 'Not Found',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $failure_filter, 10, 0 );
		$this->assertSame( 'download_failed', $this->invoke_private_method( $controller, 'fetch_remote_file', [ 'https://example.test/file.jpg' ] )->get_error_code() );
		remove_filter( 'pre_http_request', $failure_filter, 10 );

		$success_filter = static function () {
			return [
				'headers'  => [],
				'body'     => 'image-data',
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
		add_filter( 'pre_http_request', $success_filter, 10, 0 );
		$result = $this->invoke_private_method( $controller, 'fetch_remote_file', [ 'https://example.test/file.jpg', '', true ] );
		remove_filter( 'pre_http_request', $success_filter, 10 );

		$this->assertSame( 'remote', $result['source'] );
		$this->assertSame( 'image-data', $result['file_data'] );
		$this->assertFileExists( $result['path'] );

		unlink( $result['path'] ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}
}
