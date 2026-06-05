<?php
/**
 * Tests for the MediaSharing\MediaActions class.
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
 * Tests for the MediaSharing\MediaActions class.
 */
#[CoversClass( MediaActions::class )]
final class MediaActionsTest extends TestCase {
	/**
	 * Attachment post for testing.
	 *
	 * @var \WP_Post
	 */
	private \WP_Post $attachment;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->attachment = get_post( self::factory()->attachment->create() );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );

		parent::tearDown();
	}

	/**
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$actions = new MediaActions();
		$actions->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests add_replace_media_button returns unchanged fields on consumer sites.
	 */
	public function test_add_replace_media_button_returns_fields_on_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$actions     = new MediaActions();
		$form_fields = [ 'title' => [ 'label' => 'Title' ] ];

		$this->assertSame( $form_fields, $actions->add_replace_media_button( $form_fields, $this->attachment ) );
	}

	/**
	 * Tests add_replace_media_button returns unchanged fields for non-synced media.
	 */
	public function test_add_replace_media_button_returns_fields_for_non_synced_media(): void {
		$actions     = new MediaActions();
		$form_fields = [ 'title' => [ 'label' => 'Title' ] ];

		$this->assertSame( $form_fields, $actions->add_replace_media_button( $form_fields, $this->attachment ) );
	}

	/**
	 * Tests add_replace_media_button adds the replace media field for synced governing media.
	 */
	public function test_add_replace_media_button_adds_field_for_synced_media(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Attachment::set_is_synced( $this->attachment->ID, true );

		$actions = new MediaActions();
		$result  = $actions->add_replace_media_button( [], $this->attachment );

		$this->assertArrayHasKey( 'replace_media', $result );
		$this->assertSame( 'Replace Media', $result['replace_media']['label'] );
		$this->assertStringContainsString( 'replace-media-react-container', $result['replace_media']['html'] );
	}

	/**
	 * Tests remove_sync_meta returns early when there is no synced media option.
	 */
	public function test_remove_sync_meta_returns_early_without_synced_media_option(): void {
		Attachment::set_is_synced( $this->attachment->ID, true );

		$actions = new MediaActions();
		$actions->remove_sync_meta( $this->attachment->ID );

		$this->assertTrue( Attachment::is_sync_attachment( $this->attachment->ID ) );
	}

	/**
	 * Tests remove_sync_meta removes local sync metadata and option mapping.
	 */
	public function test_remove_sync_meta_removes_local_sync_data(): void {
		Attachment::set_is_synced( $this->attachment->ID, true );
		update_post_meta(
			$this->attachment->ID,
			Attachment::SYNC_SITES_POSTMETA_KEY,
			[
				[
					'site' => 'https://brand.example',
					'id'   => 123,
				],
			]
		);
		update_option(
			Settings::BRAND_SITES_SYNCED_MEDIA,
			[
				$this->attachment->ID => [ 'https://brand.example' => 123 ],
			]
		);

		$actions = new MediaActions();
		$actions->remove_sync_meta( $this->attachment->ID );

		$this->assertFalse( Attachment::is_sync_attachment( $this->attachment->ID ) );
		$this->assertSame( '', get_post_meta( $this->attachment->ID, Attachment::SYNC_SITES_POSTMETA_KEY, true ) );
		$this->assertSame( [], get_option( Settings::BRAND_SITES_SYNCED_MEDIA ) );
	}

	/**
	 * Tests sanitize_file_input returns an error when no file is provided.
	 */
	public function test_sanitize_file_input_returns_error_for_missing_file(): void {
		$actions = new MediaActions();
		$result  = $actions->sanitize_file_input( null );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_input', $result->get_error_code() );
	}

	/**
	 * Tests sanitize_file_input returns an error for unsupported mime types.
	 */
	public function test_sanitize_file_input_returns_error_for_invalid_file_type(): void {
		$actions = new MediaActions();
		$result  = $actions->sanitize_file_input(
			[
				'name'     => 'document.pdf',
				'type'     => 'application/pdf',
				'tmp_name' => '/tmp/document.pdf',
				'error'    => 0,
				'size'     => 100,
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_file_type', $result->get_error_code() );
	}

	/**
	 * Tests sanitize_file_input returns sanitized supported file data.
	 */
	public function test_sanitize_file_input_returns_sanitized_file_data(): void {
		$actions = new MediaActions();
		$result  = $actions->sanitize_file_input(
			[
				'name'          => 'Test &amp; Image.jpg',
				'type'          => 'image/jpeg',
				'tmp_name'      => '/tmp/test-image.jpg',
				'error'         => 0,
				'size'          => '100',
				'attachment_id' => '12',
				'url'           => 'https://example.com/test-image.jpg',
				'alt'           => 'Alt text',
				'caption'       => 'Caption text',
			]
		);

		$this->assertSame(
			[
				'name'          => 'Test-amp-Image.jpg',
				'type'          => 'image/jpeg',
				'tmp_name'      => '/tmp/test-image.jpg',
				'error'         => 0,
				'size'          => 100,
				'attachment_id' => 12,
				'url'           => 'https://example.com/test-image.jpg',
				'alt'           => 'Alt text',
				'caption'       => 'Caption text',
			],
			$result
		);
	}

	/**
	 * Tests update_attachment returns an error for invalid attachments.
	 */
	public function test_update_attachment_returns_error_for_invalid_attachment(): void {
		$actions = new MediaActions();
		$result  = $actions->update_attachment( 0, [] );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_attachment', $result->get_error_code() );
	}

	/**
	 * Tests restore_attachment_version returns an error when no version history exists.
	 */
	public function test_restore_attachment_version_returns_error_without_version_history(): void {
		$actions = new MediaActions();
		$result  = $actions->restore_attachment_version(
			$this->attachment->ID,
			[
				'path' => '/tmp/missing.jpg',
			]
		);

		$this->assertWPError( $result );
		$this->assertSame( 'no_version_history', $result->get_error_code() );
	}

	/**
	 * Tests add_sync_meta returns the original response when attachment ID is missing.
	 */
	public function test_add_sync_meta_returns_response_when_attachment_id_missing(): void {
		$actions  = new MediaActions();
		$response = [ 'id' => 0 ];

		$this->assertSame( $response, $actions->add_sync_meta( $response, new \stdClass() ) );
	}

	/**
	 * Tests add_sync_meta adds the sync status for attachment responses.
	 */
	public function test_add_sync_meta_adds_sync_status(): void {
		Attachment::set_is_synced( $this->attachment->ID, true );

		$actions = new MediaActions();

		$this->assertSame(
			[
				'id'               => $this->attachment->ID,
				'is_onemedia_sync' => true,
			],
			$actions->add_sync_meta( [ 'id' => $this->attachment->ID ], $this->attachment )
		);
	}
}
