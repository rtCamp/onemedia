<?php
/**
 * Tests for the MediaSharing\UserInterface class.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\MediaSharing\UserInterface;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MediaSharing\UserInterface class.
 */
#[CoversClass( UserInterface::class )]
final class UserInterfaceTest extends TestCase {
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
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$ui = new UserInterface();
		$ui->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests add_sync_column appends the sync status column.
	 */
	public function test_add_sync_column_appends_column(): void {
		$ui = new UserInterface();

		$result = $ui->add_sync_column(
			[
				'title' => 'Title',
				'date'  => 'Date',
			]
		);

		$this->assertArrayHasKey( 'onemedia_sync_status', $result );
	}

	/**
	 * Tests filter_media_row_actions removes delete action for synced attachment.
	 */
	public function test_filter_media_row_actions_removes_delete_for_synced(): void {
		Attachment::set_is_synced( $this->attachment->ID, true );

		$ui     = new UserInterface();
		$result = $ui->filter_media_row_actions(
			[
				'edit'   => '<a>Edit</a>',
				'delete' => '<a>Delete</a>',
			],
			$this->attachment
		);

		$this->assertArrayNotHasKey( 'delete', $result );
	}

	/**
	 * Tests filter_media_row_actions keeps all actions for non-synced attachment.
	 */
	public function test_filter_media_row_actions_keeps_actions_for_non_synced(): void {
		$ui      = new UserInterface();
		$actions = [
			'edit'   => '<a>Edit</a>',
			'delete' => '<a>Delete</a>',
		];

		$result = $ui->filter_media_row_actions( $actions, $this->attachment );

		$this->assertSame( $actions, $result );
	}
}
