<?php
/**
 * Tests for media sharing actions.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

require_once dirname( __DIR__, 3 ) . '/includes/media-actions-function-shims.php';

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\MediaSharing\MediaActions;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Test class.
 */
#[CoversClass( MediaActions::class )]
final class MediaActionsTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		unset( $GLOBALS['onemedia_media_actions_test_move_uploaded_file'], $GLOBALS['onemedia_media_actions_test_generate_metadata'], $GLOBALS['onemedia_media_actions_test_wp_update_post'] );
		unset( $_REQUEST['id'], $_REQUEST['action'], $_REQUEST['nonce'], $_REQUEST['_ajax_nonce'] );
		unset( $_POST['_ajax_nonce'], $_POST['file'], $_FILES['file'] );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Tests no errors on class lifecycle methods.
	 */
	public function test_class_instantiation(): void {
		$actions = new MediaActions();

		$actions->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests replace-media field visibility.
	 */
	public function test_add_replace_media_button_only_for_governing_synced_attachments(): void {
		$actions       = new MediaActions();
		$attachment_id = self::factory()->attachment->create();
		$post          = get_post( $attachment_id );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$this->assertSame( [], $actions->add_replace_media_button( [], $post ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		$this->assertSame( [], $actions->add_replace_media_button( [], $post ) );

		Attachment::set_is_synced( $attachment_id, true );
		$result = $actions->add_replace_media_button( [], $post );

		$this->assertArrayHasKey( 'replace_media', $result );
		$this->assertStringContainsString( 'data-attachment-id="' . $attachment_id . '"', $result['replace_media']['html'] );
	}

	/**
	 * Tests sync metadata removal without remote errors.
	 */
	public function test_remove_sync_meta_clears_local_mapping_when_remote_has_no_api_key(): void {
		$attachment_id = self::factory()->attachment->create();
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$attachment_id => [
					'https://brand.test' => 123,
				],
			],
			false
		);
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.test',
					'id'   => 123,
				],
			]
		);
		Attachment::set_is_synced( $attachment_id, true );

		( new MediaActions() )->remove_sync_meta( $attachment_id );

		$this->assertSame( [], get_option( Settings::BRAND_SITES_SYNCED_MEDIA ) );
		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::SYNC_SITES_POSTMETA_KEY, true ) );
		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );
	}

	/**
	 * Tests sync metadata removal returns early without a synced mapping.
	 */
	public function test_remove_sync_meta_returns_early_without_synced_mapping(): void {
		$attachment_id = self::factory()->attachment->create();

		Attachment::set_is_synced( $attachment_id, true );
		( new MediaActions() )->remove_sync_meta( $attachment_id );

		$this->assertTrue( Attachment::is_sync_attachment( $attachment_id ) );
	}

	/**
	 * Tests sync metadata removal skips invalid connected site entries.
	 */
	public function test_remove_sync_meta_skips_invalid_connected_site_entries(): void {
		$attachment_id = self::factory()->attachment->create();

		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$attachment_id => [
					'' => 0,
				],
			],
			false
		);
		Attachment::set_is_synced( $attachment_id, true );

		( new MediaActions() )->remove_sync_meta( $attachment_id );

		$this->assertSame( [], get_option( Settings::BRAND_SITES_SYNCED_MEDIA ) );
	}

	/**
	 * Tests sync metadata removal continues after a successful remote deletion.
	 */
	public function test_remove_sync_meta_removes_mapping_after_successful_remote_deletion(): void {
		$attachment_id = self::factory()->attachment->create();
		$calls         = [];

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$attachment_id => [
					'https://brand.test' => 123,
				],
			],
			false
		);
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.test',
					'id'   => 123,
				],
			]
		);
		Attachment::set_is_synced( $attachment_id, true );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'brand-token',
				],
			]
		);

		$http_filter = static function ( $_preempt, array $parsed_args, string $url ) use ( &$calls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Filter signature requires the first parameter.
			$calls[] = [
				'url'  => $url,
				'args' => $parsed_args,
			];

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
		( new MediaActions() )->remove_sync_meta( $attachment_id );
		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertCount( 1, $calls );
		$this->assertSame( [], get_option( Settings::BRAND_SITES_SYNCED_MEDIA ) );
	}

	/**
	 * Tests sync metadata removal returns early when no synced-media mapping exists for the attachment.
	 */
	public function test_remove_sync_meta_returns_early_when_attachment_has_no_synced_media_mapping(): void {
		$attachment_id = self::factory()->attachment->create();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				999999 => [
					'https://brand.test' => 123,
				],
			],
			false
		);

		( new MediaActions() )->remove_sync_meta( $attachment_id );

		$this->assertSame(
			[
				999999 => [
					'https://brand.test' => 123,
				],
			],
			get_option( Settings::BRAND_SITES_SYNCED_MEDIA )
		);
	}

	/**
	 * Tests sync metadata deletion stops on remote errors.
	 */
	#[RunInSeparateProcess]
	public function test_remove_sync_meta_dies_when_remote_metadata_deletion_fails(): void {
		$attachment_id  = self::factory()->attachment->create();
		$wp_die_handler = static function (): callable {
			return static function ( string $message = '' ): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsWpDieException( esc_html( $message ) );
			};
		};

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$attachment_id => [
					'https://brand.test' => 123,
				],
			],
			false
		);
		Attachment::set_is_synced( $attachment_id, true );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'brand-token',
				],
			]
		);

		$http_filter = static fn () => new \WP_Error( 'http_request_failed', 'Connection failed' );

		add_filter( 'pre_http_request', $http_filter, 10, 0 );
		add_filter( 'wp_die_handler', $wp_die_handler );

		try {
			( new MediaActions() )->remove_sync_meta( $attachment_id );
			$this->fail( 'Expected remote metadata deletion failure to stop execution.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsWpDieException $exception ) {
			$this->assertStringContainsString( 'Failed to delete media metadata on site https://brand.test', $exception->getMessage() );
			ob_start();
			do_action( 'admin_notices' );
			$output = ob_get_clean();
			$this->assertStringContainsString( 'Connection failed', (string) $output );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			remove_filter( 'wp_die_handler', $wp_die_handler );
		}
	}

	/**
	 * Tests pre-update sync guard returns early for non-attachments and unsynced attachments.
	 */
	public function test_pre_update_sync_attachments_returns_early_for_unblocked_posts(): void {
		$post_id       = self::factory()->post->create();
		$attachment_id = self::factory()->attachment->create();

		( new MediaActions() )->pre_update_sync_attachments( $post_id );
		( new MediaActions() )->pre_update_sync_attachments( $attachment_id );

		$this->assertTrue( true );
	}

	/**
	 * Tests pre-update sync guard allows synced attachments when health checks succeed.
	 */
	public function test_pre_update_sync_attachments_returns_when_synced_attachment_health_check_succeeds(): void {
		$attachment_id = self::factory()->attachment->create();

		Attachment::set_is_synced( $attachment_id, true );

		ob_start();
		( new MediaActions() )->pre_update_sync_attachments( $attachment_id );
		$output = ob_get_clean();

		$this->assertSame( '', (string) $output );
	}

	/**
	 * Tests pre-update sync guard sends a JSON error when a synced attachment has unhealthy connected sites.
	 */
	#[RunInSeparateProcess]
	public function test_pre_update_sync_attachments_sends_json_error_when_health_check_fails(): void {
		$attachment_id  = self::factory()->attachment->create();
		$wp_die_handler = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};

		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Attachment::set_is_synced( $attachment_id, true );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.test',
					'id'   => 123,
				],
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'brand-token',
				],
			]
		);

		$http_filter = static fn () => new \WP_Error( 'http_request_failed', 'Connection failed' );

		add_filter( 'pre_http_request', $http_filter, 10, 0 );
		add_filter( 'wp_die_ajax_handler', $wp_die_handler );
		$this->expectOutputRegex( '/(?s)"success":false.*Failed to update media/' );

		try {
			( new MediaActions() )->pre_update_sync_attachments( $attachment_id );
			$this->fail( 'Expected JSON error for failed attachment health check.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			remove_filter( 'wp_die_ajax_handler', $wp_die_handler );
		}
	}

	/**
	 * Tests AJAX pre-update guard returns early for invalid actions and unsynced attachments.
	 */
	public function test_pre_update_sync_attachments_ajax_returns_early_for_invalid_action_or_unsynced_attachment(): void {
		$attachment_id = self::factory()->attachment->create();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'edit-attachment';

		ob_start();
		( new MediaActions() )->pre_update_sync_attachments_ajax();
		$invalid_action_output = ob_get_clean();

		$_REQUEST['action'] = 'save-attachment';

		ob_start();
		( new MediaActions() )->pre_update_sync_attachments_ajax();
		$unsynced_output = ob_get_clean();

		$this->assertSame( '', (string) $invalid_action_output );
		$this->assertSame( '', (string) $unsynced_output );
	}

	/**
	 * Tests AJAX pre-update guard returns early when synced attachment health checks succeed.
	 */
	public function test_pre_update_sync_attachments_ajax_returns_when_synced_attachment_health_check_succeeds(): void {
		$attachment_id = self::factory()->attachment->create();

		Attachment::set_is_synced( $attachment_id, true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'save-attachment';

		ob_start();
		( new MediaActions() )->pre_update_sync_attachments_ajax();
		$output = ob_get_clean();

		$this->assertSame( '', (string) $output );
	}

	/**
	 * Tests AJAX pre-update guard rejects users without edit permission.
	 */
	#[RunInSeparateProcess]
	public function test_pre_update_sync_attachments_ajax_returns_json_error_for_users_without_permission(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create();
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'save-attachment';

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*You do not have permission to edit this attachment/' );

		try {
			( new MediaActions() )->pre_update_sync_attachments_ajax();
			$this->fail( 'Expected AJAX termination for unauthorized attachment update.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests AJAX pre-update guard sends a JSON error when a synced attachment has unhealthy connected sites.
	 */
	#[RunInSeparateProcess]
	public function test_pre_update_sync_attachments_ajax_sends_json_error_when_health_check_fails(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create();
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Attachment::set_is_synced( $attachment_id, true );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.test',
					'id'   => 123,
				],
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'brand-token',
				],
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'save-attachment';

		$http_filter = static fn () => new \WP_Error( 'http_request_failed', 'Connection failed' );

		add_filter( 'pre_http_request', $http_filter, 10, 0 );
		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*Failed to update media/' );

		try {
			( new MediaActions() )->pre_update_sync_attachments_ajax();
			$this->fail( 'Expected AJAX termination for failed attachment health check.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'pre_http_request', $http_filter, 10 );
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests sync attachment updates return early for unsynced attachments and missing API keys.
	 */
	public function test_update_sync_attachments_returns_early_for_unsynced_attachments_or_missing_api_keys(): void {
		$attachment_id = self::factory()->attachment->create();

		( new MediaActions() )->update_sync_attachments( $attachment_id );

		Attachment::set_is_synced( $attachment_id, true );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.test',
					'id'   => 123,
				],
			]
		);

		( new MediaActions() )->update_sync_attachments( $attachment_id );

		$this->assertTrue( true );
	}

	/**
	 * Tests sync updates skip invalid site entries and return when API keys are missing.
	 */
	public function test_update_sync_attachments_skips_invalid_sites_and_returns_for_missing_api_key(): void {
		$attachment_id = self::factory()->attachment->create();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Attachment::set_is_synced( $attachment_id, true );
		update_post_meta(
			$attachment_id,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => '',
					'id'   => 0,
				],
				[
					'site' => 'https://brand.test',
					'id'   => 123,
				],
			]
		);

		ob_start();
		( new MediaActions() )->update_sync_attachments( $attachment_id );
		$output = ob_get_clean();

		$this->assertSame( '', (string) $output );
	}

	/**
	 * Tests sync updates post an empty metadata array when attachment metadata is unavailable.
	 */
	public function test_update_sync_attachments_posts_empty_metadata_when_attachment_metadata_is_missing(): void {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_title' => 'Shared attachment',
			]
		);
		$calls         = [];

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Attachment::set_is_synced( $attachment_id, true );
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
		delete_post_meta( $attachment_id, '_wp_attachment_metadata' );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'brand-token',
				],
			]
		);

		$http_filter = static function ( $_preempt, array $parsed_args, string $url ) use ( &$calls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Filter signature requires the first parameter.
			$calls[] = [
				'url'  => $url,
				'args' => $parsed_args,
			];

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
		( new MediaActions() )->update_sync_attachments( $attachment_id );
		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertCount( 1, $calls );
		$this->assertSame( 'Shared attachment', $calls[0]['args']['body']['attachment_data']['title'] );
		$this->assertArrayNotHasKey( 'width', $calls[0]['args']['body']['attachment_data'] );
	}

	/**
	 * Tests sync attachment updates post metadata to connected brand sites.
	 */
	public function test_update_sync_attachments_posts_updates_to_connected_sites(): void {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_title'   => 'Shared attachment',
				'post_excerpt' => 'Attachment caption',
				'post_content' => 'Attachment description',
			]
		);
		$calls         = [];

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Attachment::set_is_synced( $attachment_id, true );
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
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Attachment alt' );
		wp_update_attachment_metadata(
			$attachment_id,
			[
				'width'  => 50,
				'height' => 25,
			]
		);
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'brand-token',
				],
			]
		);

		$http_filter = static function ( $_preempt, array $parsed_args, string $url ) use ( &$calls ) { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Filter signature requires the first parameter.
			$calls[] = [
				'url'  => $url,
				'args' => $parsed_args,
			];

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

		( new MediaActions() )->update_sync_attachments( $attachment_id );

		remove_filter( 'pre_http_request', $http_filter, 10 );

		$this->assertCount( 1, $calls );
		$this->assertStringContainsString( '/wp-json/' . \OneMedia\Modules\Rest\Abstract_REST_Controller::NAMESPACE . '/update-attachment', $calls[0]['url'] );
		$this->assertSame( 'brand-token', $calls[0]['args']['headers']['X-OneMedia-Token'] );
		$this->assertSame( 321, $calls[0]['args']['body']['attachment_id'] );
		$this->assertSame( 'Shared attachment', $calls[0]['args']['body']['attachment_data']['title'] );
		$this->assertSame( 'Attachment alt', $calls[0]['args']['body']['attachment_data']['alt_text'] );
		$this->assertSame( 'Attachment caption', $calls[0]['args']['body']['attachment_data']['caption'] );
		$this->assertSame( 'Attachment description', $calls[0]['args']['body']['attachment_data']['description'] );
		$this->assertTrue( $calls[0]['args']['body']['attachment_data']['is_sync'] );
	}

	/**
	 * Tests file input sanitization.
	 */
	public function test_sanitize_file_input_validates_and_sanitizes_file_data(): void {
		$actions = new MediaActions();

		$this->assertWPError( $actions->sanitize_file_input( null ) );
		$this->assertSame(
			'invalid_file_type',
			$actions->sanitize_file_input(
				[
					'name' => 'bad.txt',
					'type' => 'text/plain',
				]
			)->get_error_code()
		);

		$result = $actions->sanitize_file_input(
			[
				'name'          => 'Image &amp; One.jpg',
				'type'          => 'image/jpeg',
				'tmp_name'      => '/tmp/image.jpg',
				'error'         => 0,
				'size'          => 123,
				'attachment_id' => '10',
				'guid'          => 'https://example.test/image-guid.jpg',
				'filename'      => 'Image &amp; One.jpg',
				'url'           => 'https://example.test/image.jpg',
				'alt'           => ' Alt text ',
				'caption'       => ' Caption text ',
				'metadata'      => [ 'width' => 10 ],
				'dimensions'    => [
					'width'  => 10,
					'height' => 20,
				],
				'checksum'      => ' abc123 ',
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Image-amp-One.jpg', $result['name'] );
		$this->assertSame( 10, $result['attachment_id'] );
		$this->assertSame( 'https://example.test/image-guid.jpg', $result['guid'] );
		$this->assertSame( 'Image-amp-One.jpg', $result['filename'] );
		$this->assertSame( 'Alt text', $result['alt'] );
		$this->assertSame( 'Caption text', $result['caption'] );
		$this->assertSame( [ 'width' => 10 ], $result['metadata'] );
		$this->assertSame(
			[
				'width'  => 10,
				'height' => 20,
			],
			$result['dimensions']
		);
		$this->assertSame( 'abc123', $result['checksum'] );
	}

	/**
	 * Tests filtered input wrapper delegates to the shared utility.
	 */
	public function test_get_filtered_input_delegates_to_utils(): void {
		$actions = new MediaActionsPublicFilteredInputDouble();

		$this->assertSame(
			\OneMedia\Utils::get_filtered_input( INPUT_POST, 'current_media_id', FILTER_VALIDATE_INT ),
			$actions->read_filtered_input( INPUT_POST, 'current_media_id', FILTER_VALIDATE_INT )
		);
	}

	/**
	 * Tests update attachment validation branches.
	 */
	public function test_update_attachment_handles_invalid_and_missing_restore_data(): void {
		$actions       = new MediaActions();
		$attachment_id = self::factory()->attachment->create();

		$this->assertSame( 'invalid_attachment', $actions->update_attachment( 0, [] )->get_error_code() );
		$this->assertSame( 'missing_version_data', $actions->update_attachment( $attachment_id, [], true )->get_error_code() );
		$this->assertSame(
			'file_not_found',
			$actions->update_attachment(
				$attachment_id,
				[],
				true,
				[
					'file' => [
						'path' => '/tmp/onemedia-missing-file.jpg',
						'url'  => 'https://example.test/missing.jpg',
						'type' => 'image/jpeg',
						'name' => 'missing.jpg',
					],
				]
			)->get_error_code()
		);
	}

	/**
	 * Tests update attachment restores from an existing version file.
	 */
	public function test_update_attachment_restores_from_existing_version_file(): void {
		$actions       = new MediaActions();
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Original attachment',
			]
		);
		$version_file  = wp_tempnam( 'restore-image.jpg' );

		file_put_contents( $version_file, 'restored-image' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$result = $actions->update_attachment(
			$attachment_id,
			[
				'name' => 'restore-image.jpg',
				'type' => 'image/jpeg',
			],
			true,
			[
				'file' => [
					'path'     => $version_file,
					'url'      => 'https://example.test/uploads/restore-image.jpg',
					'type'     => 'image/jpeg',
					'name'     => 'restore-image.jpg',
					'metadata' => [
						'width'  => 12,
						'height' => 6,
					],
				],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( $version_file, $result['target_path'] );
		$this->assertSame( 'https://example.test/uploads/restore-image.jpg', $result['new_url'] );

		unlink( $version_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests update attachment returns an error when moving a new upload fails.
	 */
	public function test_update_attachment_returns_error_when_upload_move_fails(): void {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
			]
		);
		$source_file   = wp_tempnam( 'new-upload-source.jpg' );

		$result = ( new MediaActions() )->update_attachment(
			$attachment_id,
			[
				'name'     => 'new-upload.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $source_file,
			]
		);

		$this->assertSame( 'file_move_failed', $result->get_error_code() );
		unlink( $source_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests update attachment returns an error when metadata generation fails for new uploads.
	 */
	public function test_update_attachment_returns_error_when_metadata_generation_fails_for_new_upload(): void {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
			]
		);
		$source_file   = wp_tempnam( 'metadata-failure-source.jpg' );

		file_put_contents( $source_file, 'new-upload' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$GLOBALS['onemedia_media_actions_test_move_uploaded_file'] = static function ( string $from, string $to ): bool {
			return copy( $from, $to );
		};
		$GLOBALS['onemedia_media_actions_test_generate_metadata']  = static fn (): bool => false;

		$result = ( new MediaActions() )->update_attachment(
			$attachment_id,
			[
				'name'     => 'metadata-failure.jpg',
				'type'     => 'image/jpeg',
				'tmp_name' => $source_file,
			]
		);

		$this->assertSame( 'metadata_generation_failed', $result->get_error_code() );

		$attached_file = get_attached_file( $attachment_id );
		if ( is_string( $attached_file ) && '' !== $attached_file && file_exists( $attached_file ) ) {
			unlink( $attached_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
		unlink( $source_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests restoring a version errors when the requested snapshot is missing.
	 */
	public function test_restore_attachment_version_errors_when_requested_snapshot_is_missing(): void {
		$attachment_id = self::factory()->attachment->create();

		Attachment::update_sync_attachment_versions(
			$attachment_id,
			[
				[
					'last_used' => time(),
					'file'      => [
						'path' => '/tmp/found-version.jpg',
					],
				],
			]
		);

		$result = ( new MediaActions() )->restore_attachment_version(
			$attachment_id,
			[
				'path' => '/tmp/missing-version.jpg',
			]
		);

		$this->assertSame( 'no_version_history', $result->get_error_code() );
	}

	/**
	 * Tests restoring a version reorders the version history on success.
	 */
	public function test_restore_attachment_version_reorders_versions_on_success(): void {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
			]
		);
		$first_file    = wp_tempnam( 'version-a.jpg' );
		$second_file   = wp_tempnam( 'version-b.jpg' );

		file_put_contents( $first_file, 'version-a' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents( $second_file, 'version-b' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		Attachment::update_sync_attachment_versions(
			$attachment_id,
			[
				[
					'last_used' => 1,
					'file'      => [
						'path'     => $first_file,
						'url'      => 'https://example.test/version-a.jpg',
						'type'     => 'image/jpeg',
						'name'     => 'version-a.jpg',
						'metadata' => [],
					],
				],
				[
					'last_used' => 2,
					'file'      => [
						'path'     => $second_file,
						'url'      => 'https://example.test/version-b.jpg',
						'type'     => 'image/jpeg',
						'name'     => 'version-b.jpg',
						'metadata' => [],
					],
				],
			]
		);

		$result = ( new MediaActions() )->restore_attachment_version(
			$attachment_id,
			[
				'path' => $second_file,
			]
		);

		$versions = Attachment::get_sync_attachment_versions( $attachment_id );

		$this->assertIsArray( $result );
		$this->assertSame( $second_file, $versions[0]['file']['path'] );
		$this->assertSame( $first_file, $versions[1]['file']['path'] );

		unlink( $first_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		unlink( $second_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests restoring a version propagates update errors.
	 */
	public function test_restore_attachment_version_propagates_update_errors(): void {
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
			]
		);
		$version_file  = wp_tempnam( 'restore-error.jpg' );

		file_put_contents( $version_file, 'restore-error' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		Attachment::update_sync_attachment_versions(
			$attachment_id,
			[
				[
					'last_used' => 1,
					'file'      => [
						'path' => $version_file,
						'url'  => 'https://example.test/uploads/restore-error.jpg',
						'type' => 'image/jpeg',
						'name' => 'restore-error.jpg',
					],
				],
			]
		);
		$GLOBALS['onemedia_media_actions_test_wp_update_post'] = static fn (): \WP_Error => new \WP_Error( 'db_update_failed', 'DB update failed' );

		$result = ( new MediaActions() )->restore_attachment_version(
			$attachment_id,
			[
				'path' => $version_file,
			]
		);

		$this->assertSame( 'update_failed', $result->get_error_code() );

		unlink( $version_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests attachment version history updates.
	 */
	public function test_update_attachment_versions_stores_current_and_new_snapshots(): void {
		$actions       = new MediaActions();
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Original',
			]
		);
		$old_file      = wp_tempnam( 'old.jpg' );
		$new_file      = wp_tempnam( 'new.jpg' );

		file_put_contents( $old_file, 'old' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents( $new_file, 'new' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		$actions->update_attachment_versions(
			$attachment_id,
			[
				'type' => 'image/jpeg',
				'size' => 3,
			],
			[
				'target_path' => $new_file,
				'new_url'     => 'https://example.test/new.jpg',
				'metadata'    => [
					'width'  => 20,
					'height' => 10,
				],
			],
			[
				'attachment' => get_post( $attachment_id ),
				'metadata'   => [
					'width'  => 10,
					'height' => 5,
				],
				'file_path'  => $old_file,
				'url'        => 'https://example.test/old.jpg',
				'alt_text'   => 'Alt',
				'caption'    => 'Caption',
			]
		);

		$versions = Attachment::get_sync_attachment_versions( $attachment_id );

		$this->assertCount( 2, $versions );
		$this->assertSame( $new_file, $versions[0]['file']['path'] );
		$this->assertSame( $old_file, $versions[1]['file']['path'] );

		unlink( $old_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		unlink( $new_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests subsequent attachment version updates prepend the newest snapshot to existing history.
	 */
	public function test_update_attachment_versions_prepends_new_snapshot_to_existing_versions(): void {
		$actions       = new MediaActions();
		$attachment_id = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Updated',
			]
		);
		$old_file      = wp_tempnam( 'existing-old.jpg' );
		$new_file      = wp_tempnam( 'existing-new.jpg' );

		file_put_contents( $old_file, 'old-file' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		file_put_contents( $new_file, 'new-file' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents

		Attachment::update_sync_attachment_versions(
			$attachment_id,
			[
				[
					'file' => [
						'path' => $old_file,
						'url'  => 'https://example.test/existing-old.jpg',
					],
				],
			]
		);

		$actions->update_attachment_versions(
			$attachment_id,
			[
				'type' => 'image/jpeg',
				'size' => 8,
			],
			[
				'target_path' => $new_file,
				'new_url'     => 'https://example.test/existing-new.jpg',
				'metadata'    => [
					'width'  => 40,
					'height' => 20,
				],
			],
			[
				'attachment' => get_post( $attachment_id ),
				'metadata'   => [
					'width'  => 10,
					'height' => 5,
				],
				'file_path'  => $old_file,
				'url'        => 'https://example.test/current-old.jpg',
				'alt_text'   => 'Alt',
				'caption'    => 'Caption',
			]
		);

		$versions = Attachment::get_sync_attachment_versions( $attachment_id );

		$this->assertCount( 2, $versions );
		$this->assertSame( $new_file, $versions[0]['file']['path'] );
		$this->assertSame( $old_file, $versions[1]['file']['path'] );

		unlink( $old_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		unlink( $new_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests sync meta response decoration.
	 */
	public function test_add_sync_meta_adds_attachment_sync_state(): void {
		$actions       = new MediaActions();
		$attachment_id = self::factory()->attachment->create();
		$post          = get_post( $attachment_id );

		$this->assertSame( [], $actions->add_sync_meta( [], (object) [] ) );

		Attachment::set_is_synced( $attachment_id, true );
		$result = $actions->add_sync_meta( [], $post );

		$this->assertTrue( $result['is_onemedia_sync'] );
	}

	/**
	 * Tests media replacement rejects unauthorized requests.
	 */
	#[RunInSeparateProcess]
	public function test_handle_media_replace_rejects_unauthorized_requests(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*You do not have permission to replace media/' );

		try {
			( new MediaActions() )->handle_media_replace();
			$this->fail( 'Expected unauthorized replace-media request to terminate.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests media replacement returns an invalid media ID error after file validation.
	 */
	#[RunInSeparateProcess]
	public function test_handle_media_replace_returns_invalid_media_id_error(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};
		$actions          = new MediaActionsFilteredInputDouble(
			[
				'is_version_restore' => false,
				'current_media_id'   => 0,
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture populates the AJAX nonce.
		$_POST['_ajax_nonce'] = wp_create_nonce( 'onemedia_upload_media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture mirrors the AJAX request payload.
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		$_FILES['file']          = [
			'name'     => 'valid-image.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => '/tmp/valid-image.jpg',
			'error'    => 0,
			'size'     => 10,
		];

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*Invalid media ID/' );

		try {
			$actions->handle_media_replace();
			$this->fail( 'Expected invalid media ID request to terminate.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests media replacement returns a validation error when no file is provided.
	 */
	#[RunInSeparateProcess]
	public function test_handle_media_replace_returns_file_validation_error(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture populates the AJAX nonce.
		$_POST['_ajax_nonce'] = wp_create_nonce( 'onemedia_upload_media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture mirrors the AJAX request payload.
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*No file uploaded/' );

		try {
			( new MediaActions() )->handle_media_replace();
			$this->fail( 'Expected missing-file request to terminate.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests media replacement updates attachments and version history for new uploads.
	 */
	#[RunInSeparateProcess]
	public function test_handle_media_replace_updates_attachment_and_versions_for_new_upload(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
				'post_title'     => 'Original media',
			]
		);
		$source_file      = wp_tempnam( 'replacement-image-source.jpg' );
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};
		$actions          = new MediaActionsFilteredInputDouble(
			[
				'is_version_restore' => false,
				'current_media_id'   => $attachment_id,
			]
		);

		file_put_contents( $source_file, 'replacement-image' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', 'Existing alt' );
		wp_update_attachment_metadata(
			$attachment_id,
			[
				'width'  => 10,
				'height' => 10,
			]
		);
		$GLOBALS['onemedia_media_actions_test_move_uploaded_file'] = static function ( string $from, string $to ): bool {
			return copy( $from, $to );
		};
		$GLOBALS['onemedia_media_actions_test_generate_metadata']  = static fn (): array => [
			'width'  => 25,
			'height' => 15,
		];

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture populates the AJAX nonce.
		$_POST['_ajax_nonce'] = wp_create_nonce( 'onemedia_upload_media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture mirrors the AJAX request payload.
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		$_FILES['file']          = [
			'name'     => 'replacement-image.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => $source_file,
			'error'    => 0,
			'size'     => filesize( $source_file ),
		];

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":true.*Media replaced successfully/' );

		try {
			$actions->handle_media_replace();
			$this->fail( 'Expected replace-media success response to terminate.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}

		$versions = Attachment::get_sync_attachment_versions( $attachment_id );
		$this->assertCount( 2, $versions );

		$attached_file = get_attached_file( $attachment_id );
		if ( is_string( $attached_file ) && '' !== $attached_file && file_exists( $attached_file ) ) {
			unlink( $attached_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
		unlink( $source_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
	}

	/**
	 * Tests media replacement returns update errors for new uploads.
	 */
	#[RunInSeparateProcess]
	public function test_handle_media_replace_returns_update_error_for_new_upload(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create();
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};
		$actions          = new MediaActionsUpdateAttachmentErrorDouble(
			[
				'is_version_restore' => false,
				'current_media_id'   => $attachment_id,
			]
		);

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture populates the AJAX nonce.
		$_POST['_ajax_nonce'] = wp_create_nonce( 'onemedia_upload_media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture mirrors the AJAX request payload.
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		$_FILES['file']          = [
			'name'     => 'replacement-image.jpg',
			'type'     => 'image/jpeg',
			'tmp_name' => '/tmp/replacement-image.jpg',
			'error'    => 0,
			'size'     => 10,
		];

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*Replace failed/' );

		try {
			$actions->handle_media_replace();
			$this->fail( 'Expected replace-media error response to terminate.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests media replacement can restore an existing version.
	 */
	#[RunInSeparateProcess]
	public function test_handle_media_replace_restores_existing_version_successfully(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create(
			[
				'post_mime_type' => 'image/jpeg',
			]
		);
		$version_file     = wp_tempnam( 'restore-version.jpg' );
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException();
			};
		};
		$actions          = new MediaActionsFilteredInputDouble(
			[
				'is_version_restore' => true,
				'current_media_id'   => $attachment_id,
			]
		);

		file_put_contents( $version_file, 'restore-version' ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_file_put_contents
		Attachment::update_sync_attachment_versions(
			$attachment_id,
			[
				[
					'last_used' => 1,
					'file'      => [
						'path'     => $version_file,
						'url'      => 'https://example.test/uploads/restore-version.jpg',
						'type'     => 'image/jpeg',
						'name'     => 'restore-version.jpg',
						'metadata' => [
							'width'  => 20,
							'height' => 10,
						],
					],
				],
			]
		);
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Test fixture populates the AJAX nonce.
		$_POST['_ajax_nonce'] = wp_create_nonce( 'onemedia_upload_media' );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotValidated,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Test fixture mirrors the AJAX request payload.
		$_REQUEST['_ajax_nonce'] = $_POST['_ajax_nonce'];
		$_POST['file']           = wp_json_encode(
			[
				'name'      => 'restore-version.jpg',
				'type'      => 'image/jpeg',
				'path'      => $version_file,
				'url'       => 'https://example.test/uploads/restore-version.jpg',
				'mime_type' => 'image/jpeg',
				'metadata'  => [
					'width'  => 20,
					'height' => 10,
				],
			]
		);

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":true.*Media replaced successfully/' );

		try {
			$actions->handle_media_replace();
			$this->fail( 'Expected restore-media success response to terminate.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaSharing\MediaActionsAjaxTerminationException::class, $exception );
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
			unlink( $version_file ); // phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_unlink
		}
	}
}

/**
 * Test exception used to intercept wp_die() calls.
 */
final class MediaActionsWpDieException extends \RuntimeException {
}

/**
 * Test exception used to intercept AJAX termination.
 */
final class MediaActionsAjaxTerminationException extends \RuntimeException {
}

/**
 * Test double that exposes the protected filtered-input helper.
 */
final class MediaActionsPublicFilteredInputDouble extends MediaActions {
	/**
	 * Call the protected filtered-input helper.
	 *
	 * @param int                      $type     Input type.
	 * @param string                   $var_name Input name.
	 * @param int                      $filter   Filter to apply.
	 * @param array<string, mixed>|int $options  Filter options.
	 */
	public function read_filtered_input( int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0 ): mixed {
		return $this->get_filtered_input( $type, $var_name, $filter, $options );
	}
}

/**
 * Test double that returns canned filtered-input values.
 */
class MediaActionsFilteredInputDouble extends MediaActions {
	/**
	 * Filtered input values keyed by variable name.
	 *
	 * @var array<string, mixed>
	 */
	private array $values;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $values Filtered input values.
	 */
	public function __construct( array $values ) {
		$this->values = $values;
	}

	/**
	 * Return canned filtered-input values.
	 *
	 * @param int                      $type     Input type.
	 * @param string                   $var_name Input name.
	 * @param int                      $filter   Filter to apply.
	 * @param array<string, mixed>|int $options  Filter options.
	 */
	protected function get_filtered_input( int $type, string $var_name, int $filter = FILTER_DEFAULT, array|int $options = 0 ): mixed { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Signature must match the parent method.
		return $this->values[ $var_name ] ?? null;
	}
}

/**
 * Test double that forces update_attachment() failures.
 */
final class MediaActionsUpdateAttachmentErrorDouble extends MediaActionsFilteredInputDouble {
	/**
	 * Return a canned update error.
	 *
	 * @param int                  $attachment_id      Attachment ID.
	 * @param array<string, mixed> $file               Uploaded file data.
	 * @param bool                 $is_version_restore Whether a version restore is running.
	 * @param array<string, mixed> $version_data       Version restore data.
	 */
	public function update_attachment( int $attachment_id, array $file, bool $is_version_restore = false, array $version_data = [] ): array|\WP_Error { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Test double ignores the parent method inputs.
		return new \WP_Error( 'replace_failed', 'Replace failed' );
	}
}
