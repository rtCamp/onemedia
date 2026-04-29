<?php
/**
 * Tests for media sharing actions.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\MediaSharing\MediaActions;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Modules\MediaSharing\MediaActions
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

		parent::tearDown();
	}

	/**
	 * Tests hook registration.
	 */
	public function test_register_hooks_adds_expected_callbacks(): void {
		$actions = new MediaActions();

		$actions->register_hooks();

		$this->assertSame( 10, has_action( 'pre_post_update', [ $actions, 'pre_update_sync_attachments' ] ) );
		$this->assertSame( 0, has_action( 'wp_ajax_save-attachment', [ $actions, 'pre_update_sync_attachments_ajax' ] ) );
		$this->assertSame( 10, has_action( 'attachment_updated', [ $actions, 'update_sync_attachments' ] ) );
		$this->assertSame( 10, has_action( 'delete_attachment', [ $actions, 'remove_sync_meta' ] ) );
		$this->assertSame( 10, has_filter( 'attachment_fields_to_edit', [ $actions, 'add_replace_media_button' ] ) );
		$this->assertSame( 10, has_filter( 'wp_prepare_attachment_for_js', [ $actions, 'add_sync_meta' ] ) );
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
				'url'           => 'https://example.test/image.jpg',
				'metadata'      => [ 'width' => 10 ],
			]
		);

		$this->assertIsArray( $result );
		$this->assertSame( 'Image-amp-One.jpg', $result['name'] );
		$this->assertSame( 10, $result['attachment_id'] );
		$this->assertSame( [ 'width' => 10 ], $result['metadata'] );
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
}
