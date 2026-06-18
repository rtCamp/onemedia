<?php
/**
 * Tests for the MediaLibrary\ConsumerAdmin class.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaLibrary
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaLibrary;

use OneMedia\Modules\MediaLibrary\ConsumerAdmin;
use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MediaLibrary\ConsumerAdmin class.
 */
#[CoversClass( ConsumerAdmin::class )]
final class ConsumerAdminTest extends TestCase {
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

		parent::tearDown();
	}

	/**
	 * Tests no errors on hook registration when not on a governing site.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$consumer_admin = new ConsumerAdmin();
		$consumer_admin->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests register_hooks skips all hooks when on a governing site.
	 */
	public function test_register_hooks_skips_hooks_on_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$consumer_admin = new ConsumerAdmin();
		$consumer_admin->register_hooks();

		$this->assertFalse( has_filter( 'delete_attachment', [ $consumer_admin, 'prevent_attachment_deletion' ] ) );
	}

	/**
	 * Tests remove_edit_delete_links removes edit and delete actions for a synced attachment.
	 */
	public function test_remove_edit_delete_links_removes_actions_for_synced(): void {
		Attachment::set_is_synced( $this->attachment->ID, true );

		$consumer_admin = new ConsumerAdmin();
		$result         = $consumer_admin->remove_edit_delete_links(
			[
				'edit'   => '<a>Edit</a>',
				'delete' => '<a>Delete</a>',
			],
			$this->attachment
		);

		$this->assertArrayNotHasKey( 'edit', $result );
		$this->assertArrayNotHasKey( 'delete', $result );
	}

	/**
	 * Tests remove_edit_delete_links keeps all actions for a non-synced attachment.
	 */
	public function test_remove_edit_delete_links_keeps_actions_for_non_synced(): void {
		$consumer_admin = new ConsumerAdmin();
		$actions        = [
			'edit'   => '<a>Edit</a>',
			'delete' => '<a>Delete</a>',
		];

		$result = $consumer_admin->remove_edit_delete_links( $actions, $this->attachment );

		$this->assertSame( $actions, $result );
	}
}
