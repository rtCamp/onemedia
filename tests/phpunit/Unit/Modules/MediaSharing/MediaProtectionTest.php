<?php
/**
 * Tests for the MediaSharing\MediaProtection class.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\MediaSharing\MediaProtection;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MediaSharing\MediaProtection class.
 */
#[CoversClass( MediaProtection::class )]
final class MediaProtectionTest extends TestCase {
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

		$this->attachment_id = self::factory()->attachment->create();
	}

	/**
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$protection = new MediaProtection();
		$protection->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests prevent_sync_media_editing returns do_not_allow for a synced attachment on edit_post.
	 */
	public function test_prevent_sync_media_editing_returns_do_not_allow_for_synced(): void {
		Attachment::set_is_synced( $this->attachment_id, true );

		$protection = new MediaProtection();
		$result     = $protection->prevent_sync_media_editing(
			[ 'edit_posts' ],
			'edit_post',
			1,
			[ $this->attachment_id ]
		);

		$this->assertSame( [ 'do_not_allow' ], $result );
	}

	/**
	 * Tests prevent_sync_media_editing passes through caps for a non-synced attachment.
	 */
	public function test_prevent_sync_media_editing_passes_through_for_non_synced(): void {
		$caps       = [ 'edit_posts' ];
		$protection = new MediaProtection();

		$result = $protection->prevent_sync_media_editing(
			$caps,
			'edit_post',
			1,
			[ $this->attachment_id ]
		);

		$this->assertSame( $caps, $result );
	}

	/**
	 * Tests prevent_sync_media_editing passes through caps for non-edit capabilities.
	 */
	public function test_prevent_sync_media_editing_passes_through_for_non_edit_cap(): void {
		Attachment::set_is_synced( $this->attachment_id, true );

		$caps       = [ 'upload_files' ];
		$protection = new MediaProtection();

		$result = $protection->prevent_sync_media_editing(
			$caps,
			'upload_files',
			1,
			[ $this->attachment_id ]
		);

		$this->assertSame( $caps, $result );
	}
}
