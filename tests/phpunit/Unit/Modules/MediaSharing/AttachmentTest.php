<?php
/**
 * Tests for the MediaSharing\Attachment class.
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
 * Tests for the MediaSharing\Attachment class.
 */
#[CoversClass( Attachment::class )]
final class AttachmentTest extends TestCase {
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
		$attachment = new Attachment();
		$attachment->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests is_sync_attachment returns false for a new attachment with no meta.
	 */
	public function test_is_sync_attachment_returns_false_for_new_attachment(): void {
		$this->assertFalse( Attachment::is_sync_attachment( $this->attachment_id ) );
	}

	/**
	 * Tests set_is_synced and is_sync_attachment round-trip.
	 */
	public function test_set_is_synced_and_is_sync_attachment(): void {
		Attachment::set_is_synced( $this->attachment_id, true );
		$this->assertTrue( Attachment::is_sync_attachment( $this->attachment_id ) );

		Attachment::set_is_synced( $this->attachment_id, false );
		$this->assertFalse( Attachment::is_sync_attachment( $this->attachment_id ) );
	}

	/**
	 * Tests get_sync_sites returns empty array when not on a governing site.
	 */
	public function test_get_sync_sites_returns_empty_when_not_governing(): void {
		$this->assertSame( [], Attachment::get_sync_sites( $this->attachment_id ) );
	}

	/**
	 * Tests get_sync_sites returns empty array on governing site when no meta is stored.
	 */
	public function test_get_sync_sites_returns_empty_on_governing_when_no_meta(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$this->assertSame( [], Attachment::get_sync_sites( $this->attachment_id ) );

		delete_option( Settings::OPTION_SITE_TYPE );
	}

	/**
	 * Tests update_sync_attachment_versions and get_sync_attachment_versions round-trip.
	 */
	public function test_update_and_get_sync_attachment_versions(): void {
		$versions = [
			[
				'last_used' => 1000000,
				'file'      => [
					'path' => '/var/www/html/wp-content/uploads/test.jpg',
					'url'  => 'https://example.com/wp-content/uploads/test.jpg',
				],
			],
		];

		$this->assertTrue( Attachment::update_sync_attachment_versions( $this->attachment_id, $versions ) );
		$this->assertSame( $versions, Attachment::get_sync_attachment_versions( $this->attachment_id ) );
	}

	/**
	 * Tests get_sync_attachment_versions returns empty array when no versions are stored.
	 */
	public function test_get_sync_attachment_versions_returns_empty_when_not_set(): void {
		$this->assertSame( [], Attachment::get_sync_attachment_versions( $this->attachment_id ) );
	}
}
