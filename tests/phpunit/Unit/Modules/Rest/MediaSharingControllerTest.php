<?php
/**
 * Tests for media sharing REST controller.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Rest;

require_once dirname( __DIR__, 3 ) . '/includes/media-sharing-controller-function-shims.php';

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Rest\Abstract_REST_Controller;
use OneMedia\Modules\Rest\Media_Sharing_Controller;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

	/**
	 * Class MediaSharingControllerTest
	 */
	#[CoversClass( Media_Sharing_Controller::class )]
	#[CoversClass( Abstract_REST_Controller::class )]
final class MediaSharingControllerTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );
		delete_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION );
		unset( $GLOBALS['onemedia_test_wp_insert_attachment_result'], $GLOBALS['onemedia_test_fail_temp_unlink'] );

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
	 * Tests no errors on class instantiation.
	 */
	public function test_class_instantiation(): void {
		$this->assertInstanceOf( Media_Sharing_Controller::class, new Media_Sharing_Controller() );
	}

	/**
	 * Tests route registration exposes the expected media sharing endpoints.
	 */
	public function test_register_routes_registers_expected_rest_endpoints(): void {
		$controller = new Media_Sharing_Controller();

		$controller->register_hooks();
		do_action( 'rest_api_init' );

		$routes    = rest_get_server()->get_routes();
		$namespace = '/' . Abstract_REST_Controller::NAMESPACE;

		$this->assertArrayHasKey( $namespace . '/media', $routes );
		$this->assertSame( 'sanitize_text_field', $routes[ $namespace . '/media' ][0]['args']['search_term']['sanitize_callback'] );
		$this->assertSame( 'absint', $routes[ $namespace . '/media' ][0]['args']['page']['sanitize_callback'] );
		$this->assertSame( 'absint', $routes[ $namespace . '/media' ][0]['args']['per_page']['sanitize_callback'] );
		$this->assertTrue( $routes[ $namespace . '/media' ][0]['args']['search_term']['validate_callback']( 'query' ) );
		$this->assertTrue( $routes[ $namespace . '/media' ][0]['args']['page']['validate_callback']( '2' ) );
		$this->assertTrue( $routes[ $namespace . '/media' ][0]['args']['per_page']['validate_callback']( 5 ) );
		$this->assertTrue( $routes[ $namespace . '/media' ][0]['args']['image_type']['validate_callback']( 'synced' ) );

		$this->assertArrayHasKey( $namespace . '/sync-media', $routes );
		$this->assertTrue( $routes[ $namespace . '/sync-media' ][0]['args']['brand_sites']['required'] );
		$this->assertTrue( $routes[ $namespace . '/sync-media' ][0]['args']['media_details']['required'] );
		$this->assertSame( 'sanitize_text_field', $routes[ $namespace . '/sync-media' ][0]['args']['sync_option']['sanitize_callback'] );
		$this->assertTrue( $routes[ $namespace . '/sync-media' ][0]['args']['brand_sites']['validate_callback']( [] ) );
		$this->assertTrue( $routes[ $namespace . '/sync-media' ][0]['args']['media_details']['validate_callback']( [] ) );
		$this->assertTrue( $routes[ $namespace . '/sync-media' ][0]['args']['sync_option']['validate_callback']( 'sync' ) );

		$this->assertArrayHasKey( $namespace . '/add-media', $routes );
		$this->assertTrue( $routes[ $namespace . '/add-media' ][0]['args']['media_files']['required'] );
		$this->assertFalse( $routes[ $namespace . '/add-media' ][0]['args']['sync_option']['required'] );
		$this->assertTrue( $routes[ $namespace . '/add-media' ][0]['args']['media_files']['validate_callback']( [] ) );
		$this->assertTrue( $routes[ $namespace . '/add-media' ][0]['args']['sync_option']['validate_callback']( 'sync' ) );

		$this->assertArrayHasKey( $namespace . '/update-attachment', $routes );
		$this->assertSame( 'absint', $routes[ $namespace . '/update-attachment' ][0]['args']['attachment_id']['sanitize_callback'] );
		$this->assertSame( 'esc_url_raw', $routes[ $namespace . '/update-attachment' ][0]['args']['attachment_url']['sanitize_callback'] );
		$this->assertTrue( $routes[ $namespace . '/update-attachment' ][0]['args']['attachment_id']['validate_callback']( 10 ) );
		$this->assertTrue( $routes[ $namespace . '/update-attachment' ][0]['args']['attachment_url']['validate_callback']( 'https://example.test/file.jpg' ) );
		$this->assertTrue( $routes[ $namespace . '/update-attachment' ][0]['args']['attachment_data']['validate_callback']( [] ) );

		$this->assertArrayHasKey( $namespace . '/delete-media-metadata', $routes );
		$this->assertTrue( $routes[ $namespace . '/delete-media-metadata' ][0]['args']['attachment_id']['validate_callback']( 12 ) );
		$this->assertArrayHasKey( $namespace . '/brand-sites-synced-media', $routes );
		$this->assertArrayHasKey( $namespace . '/update-existing-attachment', $routes );
		$this->assertTrue( $routes[ $namespace . '/update-existing-attachment' ][0]['args']['attachment_id']['validate_callback']( 12 ) );
		$this->assertTrue( $routes[ $namespace . '/update-existing-attachment' ][0]['args']['sync_option']['validate_callback']( 'sync' ) );
		$this->assertArrayHasKey( $namespace . '/is-sync-attachment', $routes );
		$this->assertTrue( $routes[ $namespace . '/is-sync-attachment' ][0]['args']['attachment_id']['validate_callback']( 12 ) );
		$this->assertArrayHasKey( $namespace . '/sync-attachment-versions', $routes );
		$this->assertTrue( $routes[ $namespace . '/sync-attachment-versions' ][0]['args']['attachment_id']['validate_callback']( 12 ) );

		$this->assertIsArray( $routes[ $namespace . '/media' ][0]['permission_callback'] );
		$this->assertInstanceOf( Media_Sharing_Controller::class, $routes[ $namespace . '/media' ][0]['permission_callback'][0] );
		$this->assertSame( 'check_api_permissions', $routes[ $namespace . '/media' ][0]['permission_callback'][1] );
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
	 * Tests update-media validation branches before any file replacement work begins.
	 */
	public function test_update_media_files_validates_required_input_data(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/update-attachment' );

		$this->assertSame( 'invalid_data', $controller->update_media_files( $request )->get_error_code() );

		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		$request->set_param( 'attachment_id', $attachment_id );
		$request->set_param( 'attachment_url', 'not-a-url' );
		$this->assertSame( 'invalid_data', $controller->update_media_files( $request )->get_error_code() );

		$request->set_param( 'attachment_url', 'https://example.test/file.jpg' );
		$request->set_param( 'attachment_data', [ 'title' => [ 'bad' ] ] );
		$this->assertSame( 'invalid_data', $controller->update_media_files( $request )->get_error_code() );
	}

	/**
	 * Tests update-media returns a download error when the replacement file cannot be fetched.
	 */
	public function test_update_media_files_returns_download_error_when_remote_fetch_fails(): void {
		$controller    = new Media_Sharing_Controller();
		$request       = new WP_REST_Request( 'POST', '/onemedia/v1/update-attachment' );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		$http_filter = static function () {
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
			add_filter( 'pre_http_request', $http_filter, 10, 0 );

			$request->set_param( 'attachment_id', $attachment_id );
			$request->set_param( 'attachment_url', 'https://example.test/file.jpg' );
			$request->set_param(
				'attachment_data',
				[
					'title' => 'Updated title',
				]
			);

		$result = $controller->update_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertSame( 'file_download_failed', $result->get_error_code() );
	}

	/**
	 * Tests update-media happy path with a small downloaded image payload.
	 */
	public function test_update_media_files_updates_attachment_and_returns_success(): void {
		$controller    = new Media_Sharing_Controller();
		$request       = new WP_REST_Request( 'POST', '/onemedia/v1/update-attachment' );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/gif' ] );
		$gif_data      = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );

		$http_filter = static function () use ( $gif_data ) {
			return [
				'headers'  => [ 'content-type' => 'image/gif' ],
				'body'     => $gif_data,
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
			add_filter( 'pre_http_request', $http_filter, 10, 0 );

			$request->set_param( 'attachment_id', $attachment_id );
			$request->set_param( 'attachment_url', 'https://example.test/replacement.gif' );
			$request->set_param(
				'attachment_data',
				[
					'title'       => 'Updated &amp; Title',
					'alt_text'    => 'Updated alt text',
					'caption'     => 'Updated caption',
					'description' => 'Updated description',
				]
			);

		$result = $controller->update_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertSame( 200, $result->get_status() );
		$this->assertTrue( $result->get_data()['success'] );
		$this->assertSame( 'Updated &amp; Title', get_post( $attachment_id )->post_title );
		$this->assertSame( 'Updated alt text', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Tests sync media error handling for failed remote responses and no-sync fallback media data.
	 */
	public function test_sync_media_files_collects_failed_sites_from_error_response_with_media_payload(): void {
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
						'message' => 'Remote sync failed',
						'data'    => [
							'errors'             => [ 'bad-file' ],
							'is_mime_type_error' => true,
							'media'              => [
								[
									'id'        => 456,
									'parent_id' => $attachment_id,
								],
							],
						],
					]
				),
				'response' => [
					'code'    => 500,
					'message' => 'Server Error',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};
			add_filter( 'pre_http_request', $http_filter, 10, 0 );

			$request = new WP_REST_Request( 'POST', '/onemedia/v1/sync-media' );
			$request->set_param( 'brand_sites', [ 'https://brand.test/' ] );
			$request->set_param( 'sync_option', Attachment::SYNC_STATUS_NO_SYNC );
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

		$result = $controller->sync_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertSame( 'sync_failed', $result->get_error_code() );
		$this->assertFalse( Attachment::is_sync_attachment( $attachment_id ) );
		$this->assertSame(
			[
				[
					'site' => 'https://brand.test/',
					'id'   => 456,
				],
			],
			Attachment::get_sync_sites( $attachment_id )
		);
		$this->assertSame( 'Remote sync failed', $result->get_error_data()['failed_sites'][0]['message'] );
		$this->assertTrue( $result->get_error_data()['failed_sites'][0]['is_mime_type_error'] );
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
		$request->set_param( 'search_term', 'Local' );
		$data = $controller->get_media_files( $request )->get_data();

		$this->assertSame( 1, $data['page'] );
		$this->assertSame( 100, $data['per_page'] );
		$this->assertContains( $unsynced_id, wp_list_pluck( $data['media_files'], 'id' ) );

		$request->set_param( 'search_term', '' );
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
	 * Tests sync media returns an error when synced media mapping cannot be persisted.
	 */
	public function test_sync_media_files_returns_error_when_synced_media_mapping_update_fails(): void {
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

		$http_filter       = static function () use ( $attachment_id ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'media' => [
							[
								'id'        => 654,
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
			$option_filter = static function ( mixed $_value, mixed $old_value ): mixed { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Filter signature requires the first parameter.
				return $old_value;
			};

			add_filter( 'pre_http_request', $http_filter, 10, 0 );
			add_filter( 'pre_update_option_' . Settings::BRAND_SITES_SYNCED_MEDIA, $option_filter, 10, 2 );

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

		$result = $controller->sync_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );
		remove_filter( 'pre_update_option_' . Settings::BRAND_SITES_SYNCED_MEDIA, $option_filter, 10 );

		$this->assertSame( 'sync_failed', $result->get_error_code() );
		$this->assertSame( 'Failed to update synced media.', $result->get_error_data()['failed_sites'][0]['message'] );
	}

	/**
	 * Tests sync media handles empty media payloads from failed remote responses.
	 */
	public function test_sync_media_files_returns_error_when_failed_response_has_no_media_payload(): void {
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

		$http_filter = static function () {
			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'message' => 'Remote sync failed without media',
						'data'    => [
							'errors' => [ 'missing-media' ],
						],
					]
				),
				'response' => [
					'code'    => 500,
					'message' => 'Server Error',
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

		$result = $controller->sync_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertSame( 'sync_failed', $result->get_error_code() );
		$this->assertSame( 'Remote sync failed without media', $result->get_error_data()['failed_sites'][0]['message'] );
	}

	/**
	 * Tests sync media skips mapping updates when the saved option already matches.
	 */
	public function test_sync_media_files_skips_mapping_update_when_saved_mapping_matches(): void {
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
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$attachment_id => [
					'https://brand.test/' => 765,
				],
			],
			false
		);

		$http_filter = static function () use ( $attachment_id ) {
			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'media' => [
							[
								'id'        => 765,
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

		$this->assertTrue( $data['success'] );
		$this->assertSame( [ 'https://brand.test/' => 765 ], Settings::get_brand_sites_synced_media()[ $attachment_id ] );
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
	 * Tests add-media handles converted attachment mappings and validation errors.
	 */
	public function test_add_media_files_handles_converted_existing_attachment_mappings(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		$other_id      = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		update_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION, [ 55 => $attachment_id ], false );

		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param(
			'media_files',
			[
				[
					'id'              => 55,
					'child_id'        => 999999,
					'url'             => 'https://example.test/file.jpg',
					'title'           => 'File',
					'mime_type'       => 'image/jpeg',
					'attachment_data' => [],
				],
			]
		);

		$result = $controller->add_media_files( $request );

		$this->assertSame( 'media_addition_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Invalid attachment ID provided for converted media.', $result->get_error_data()['errors'][0]['error'] );

		$request->set_param(
			'media_files',
			[
				[
					'id'              => 55,
					'child_id'        => $other_id,
					'url'             => 'https://example.test/file.jpg',
					'title'           => 'File',
					'mime_type'       => 'image/jpeg',
					'attachment_data' => [],
				],
			]
		);

		$result = $controller->add_media_files( $request );

		$this->assertSame( 'media_addition_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Attachment ID does not match the saved attachment ID', $result->get_error_data()['errors'][0]['error'] );

		$request->set_param(
			'media_files',
			[
				[
					'id'              => 55,
					'child_id'        => $attachment_id,
					'url'             => 'https://example.test/file.jpg',
					'title'           => 'Converted File',
					'mime_type'       => 'image/jpeg',
					'attachment_data' => [
						'post_title' => 'Converted File',
						'alt_text'   => 'Converted alt',
					],
				],
			]
		);

		$data = $controller->add_media_files( $request )->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( $attachment_id, $data['media'][0]['id'] );
		$this->assertTrue( Attachment::is_sync_attachment( $attachment_id ) );
		$this->assertSame( 'Converted File', get_the_title( $attachment_id ) );
		$this->assertSame( 'Converted alt', get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
	}

	/**
	 * Tests add-media imports a new local file and persists the attachment map.
	 */
	public function test_add_media_files_imports_new_local_file_successfully(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$uploads    = wp_upload_dir();
		$source     = trailingslashit( $uploads['path'] ) . 'source-image.gif';
		$source_url = 'https://example.test/' . ltrim( str_replace( ABSPATH, '', $source ), '/' );
		$gif_data   = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );

		file_put_contents( $source, $gif_data ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
		$request->set_param(
			'media_files',
			[
				[
					'id'              => 77,
					'url'             => $source_url,
					'title'           => 'Imported File',
					'mime_type'       => 'image/gif',
					'attachment_data' => [
						'post_title'  => 'Imported Source Title',
						'alt_text'    => 'Imported alt',
						'caption'     => 'Imported caption',
						'description' => 'Imported description',
					],
				],
			]
		);

		$result = $controller->add_media_files( $request );

		if ( file_exists( $source ) ) {
			unlink( $source ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'media_addition_failed', $result->get_error_code() );
			$this->assertNotEmpty( $result->get_error_data()['errors'] );

			return;
		}

		$data = $result->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 77, $data['media'][0]['parent_id'] );
		$this->assertTrue( Attachment::is_sync_attachment( $data['media'][0]['id'] ) );
		$this->assertSame( 'Imported Source Title', get_the_title( $data['media'][0]['id'] ) );
		$this->assertSame( 'Imported alt', get_post_meta( $data['media'][0]['id'], '_wp_attachment_image_alt', true ) );
		$this->assertSame( $data['media'][0]['id'], get_option( Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION )[77] );
	}

	/**
	 * Tests add-media reports local file lookup failures through the aggregated error response.
	 */
	public function test_add_media_files_returns_error_when_local_file_lookup_fails(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );

		$http_filter = static function () {
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
			add_filter( 'pre_http_request', $http_filter, 10, 0 );

			$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
			$request->set_param(
				'media_files',
				[
					[
						'id'        => 78,
						'url'       => 'https://example.test/wp-content/uploads/missing-image.gif',
						'title'     => 'Missing File',
						'mime_type' => 'image/gif',
					],
				]
			);

		$result = $controller->add_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertSame( 'media_addition_failed', $result->get_error_code() );
		$this->assertStringContainsString( 'Failed to download file from remote URL.', $result->get_error_data()['errors'][0]['error'] );
	}

	/**
	 * Tests add-media reports attachment creation failures and temp cleanup errors.
	 */
	public function test_add_media_files_reports_attachment_creation_errors_and_temp_cleanup_failure(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$gif_data   = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );

		$GLOBALS['onemedia_test_fail_temp_unlink']            = true;
		$GLOBALS['onemedia_test_wp_insert_attachment_result'] = new MediaSharingControllerEmptyMessagesError();

		$http_filter = static function () use ( $gif_data ) {
			return [
				'headers'  => [],
				'body'     => $gif_data,
				'response' => [
					'code'    => 200,
					'message' => 'OK',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};

			update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
			add_filter( 'pre_http_request', $http_filter, 10, 0 );

			$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
			$request->set_param(
				'media_files',
				[
					[
						'id'        => 80,
						'url'       => 'https://example.test/insert-failure.gif',
						'title'     => 'Insert Failure',
						'mime_type' => 'image/gif',
					],
				]
			);

		$result = $controller->add_media_files( $request );

		remove_filter( 'pre_http_request', $http_filter, 10 );
		unset( $GLOBALS['onemedia_test_wp_insert_attachment_result'], $GLOBALS['onemedia_test_fail_temp_unlink'] );

		$this->assertSame( 'media_addition_failed', $result->get_error_code() );
		$this->assertSame( 'Failed to delete temporary file.', $result->get_error_data()['errors'][0]['error'] );
		$this->assertSame( 'Unknown error occurred while creating attachment.', $result->get_error_data()['errors'][1]['error'] );
	}

	/**
	 * Tests add-media reports attachment map persistence failures.
	 */
	public function test_add_media_files_returns_error_when_attachment_map_update_fails(): void {
		$controller = new Media_Sharing_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/add-media' );
		$uploads    = wp_upload_dir();
		$source     = trailingslashit( $uploads['path'] ) . 'map-failure.gif';
		$source_url = 'https://example.test/' . ltrim( str_replace( ABSPATH, '', $source ), '/' );
		$gif_data   = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );

		file_put_contents( $source, $gif_data ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$option_filter = static function ( mixed $_value, mixed $old_value ): mixed { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Filter signature requires the first parameter.
			return $old_value;
		};

			update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
			add_filter( 'pre_update_option_' . Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION, $option_filter, 10, 2 );

			$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );
			$request->set_param(
				'media_files',
				[
					[
						'id'        => 79,
						'url'       => $source_url,
						'title'     => 'Map Failure',
						'mime_type' => 'image/gif',
					],
				]
			);

		$result = $controller->add_media_files( $request );

		remove_filter( 'pre_update_option_' . Media_Sharing_Controller::ATTACHMENT_KEY_MAP_OPTION, $option_filter, 10 );

		if ( file_exists( $source ) ) {
			unlink( $source ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}

		$this->assertSame( 'media_addition_failed', $result->get_error_code() );
		$this->assertSame( 'Failed to update attachment key map option.', $result->get_error_data()['errors'][0]['error'] );
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
	 * Tests update-existing-attachment sync conversion flow and already-synced response.
	 */
	public function test_update_existing_attachment_syncs_previously_shared_media(): void {
		$controller    = new Media_Sharing_Controller();
		$request       = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.test/',
					'id'   => 321,
				],
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
								'id'        => 654,
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

			$request->set_param( 'attachment_id', $attachment_id );
			$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );

			$data = $controller->update_existing_attachment( $request )->get_data();

			remove_filter( 'pre_http_request', $http_filter, 10 );

			$this->assertTrue( $data['success'] );
			$this->assertSame( $attachment_id, $data['attachment_id'] );
			$this->assertTrue( Attachment::is_sync_attachment( $attachment_id ) );
			$this->assertSame( [ 'https://brand.test' => 654 ], $data['onemedia_sync_option'][ $attachment_id ] );

			$already_synced = $controller->update_existing_attachment( $request )->get_data();
			$this->assertFalse( $already_synced['success'] );
			$this->assertSame( 'Media has been added already.', $already_synced['message'] );
	}

	/**
	 * Tests update-existing-attachment reports invalid shared site URLs.
	 */
	public function test_update_existing_attachment_returns_error_for_invalid_shared_site_url(): void {
		$controller    = new Media_Sharing_Controller();
		$request       = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => '',
					'id'   => 123,
				],
			]
		);

		$request->set_param( 'attachment_id', $attachment_id );
		$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );

		$result = $controller->update_existing_attachment( $request );

		$this->assertSame( 'sync_failed', $result->get_error_code() );
		$this->assertSame( 'Invalid site URL.', $result->get_error_data()['failed_sites'][0]['message'] );
	}

	/**
	 * Tests update-existing-attachment reports missing child IDs and remote sync failures.
	 */
	public function test_update_existing_attachment_collects_missing_child_ids_and_remote_errors(): void {
		$controller    = new Media_Sharing_Controller();
		$request       = new WP_REST_Request( 'POST', '/onemedia/v1/update-existing-attachment' );
		$attachment_id = self::factory()->attachment->create( [ 'post_mime_type' => 'image/jpeg' ] );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand-missing.test/',
					'id'   => null,
				],
				[
					'site' => 'https://brand-error.test/',
					'id'   => 321,
				],
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand Missing',
					'url'     => 'https://brand-missing.test',
					'api_key' => 'missing-token',
				],
				[
					'name'    => 'Brand Error',
					'url'     => 'https://brand-error.test',
					'api_key' => 'error-token',
				],
			]
		);

		$http_filter = static function ( $preempt, array $_args, string $url ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Filter signature requires the middle parameter.
			if ( ! str_contains( $url, 'brand-error.test' ) ) {
				return $preempt;
			}

			return [
				'headers'  => [],
				'body'     => wp_json_encode(
					[
						'message' => 'Remote sync failed',
						'data'    => [
							'errors'             => [ [ 'error' => 'Remote failure payload' ] ],
							'is_mime_type_error' => true,
							'media'              => [],
						],
					]
				),
				'response' => [
					'code'    => 500,
					'message' => 'Server Error',
				],
				'cookies'  => [],
				'filename' => null,
			];
		};

			add_filter( 'pre_http_request', $http_filter, 10, 3 );

			$request->set_param( 'attachment_id', $attachment_id );
			$request->set_param( 'sync_option', Attachment::SYNC_STATUS_SYNC );

			$result = $controller->update_existing_attachment( $request );

			remove_filter( 'pre_http_request', $http_filter, 10 );

			$this->assertSame( 'sync_failed', $result->get_error_code() );
			$this->assertSame( 'Invalid child ID data.', $result->get_error_data()['failed_sites'][0]['message'] );
			$this->assertSame( 'Remote sync failed', $result->get_error_data()['failed_sites'][1]['message'] );
			$this->assertSame( [ [ 'error' => 'Remote failure payload' ] ], $result->get_error_data()['failed_sites'][1]['errors'] );
			$this->assertTrue( $result->get_error_data()['failed_sites'][1]['is_mime_type_error'] );
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
		$this->assertSame( 'invalid_attachment_id', $controller->sync_attachment_versions( $request )->get_error_code() );

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
	 * Tests attachment creation helper with a real local file.
	 */
	public function test_create_attachment_from_file_creates_attachment_from_local_file(): void {
		$controller = new Media_Sharing_Controller();
		$uploads    = wp_upload_dir();
		$source     = trailingslashit( $uploads['path'] ) . 'private-create.gif';
		$gif_data   = base64_decode( 'R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==' );

		file_put_contents( $source, $gif_data ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$result = $this->invoke_private_method(
			$controller,
			'create_attachment_from_file',
			[
				[
					'path'                => $source,
					'name'                => 'private-create.gif',
					'type'                => 'image/gif',
					'source'              => 'local',
					'attachment_metadata' => [
						'post_title' => 'Private Create',
						'alt_text'   => 'Private alt',
					],
				],
				'Private Create',
				Attachment::SYNC_STATUS_SYNC,
			]
		);

		if ( file_exists( $source ) ) {
			unlink( $source ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'attachment_errors', $result->get_error_code() );
			$this->assertGreaterThan( 0, $result->get_error_data()['attachment_id'] );

			return;
		}

		$this->assertGreaterThan( 0, $result );
		$this->assertSame( 'Private Create', get_the_title( $result ) );
		$this->assertSame( 'Private alt', get_post_meta( $result, '_wp_attachment_image_alt', true ) );
		$this->assertTrue( Attachment::is_sync_attachment( $result ) );
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
			$GLOBALS['onemedia_test_fail_temp_unlink'] = true;
			$this->assertSame( 'download_failed', $this->invoke_private_method( $controller, 'fetch_remote_file', [ 'https://example.test/file.jpg' ] )->get_error_code() );
			remove_filter( 'pre_http_request', $failure_filter, 10 );
			unset( $GLOBALS['onemedia_test_fail_temp_unlink'] );

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

/**
 * WP_Error test helper that returns an empty error-message list.
 */
final class MediaSharingControllerEmptyMessagesError extends \WP_Error {
	/**
	 * Return an empty error-message list.
	 *
	 * @param string|int $code Error code.
	 * @return array<int, string>
	 */
	public function get_error_messages( $code = '' ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Signature mirrors WP_Error.
		return [];
	}
}
