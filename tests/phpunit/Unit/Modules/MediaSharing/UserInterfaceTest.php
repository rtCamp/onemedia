<?php
/**
 * Tests for media sharing UI helpers.
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
 * @covers \OneMedia\Modules\MediaSharing\UserInterface
 */
#[CoversClass( UserInterface::class )]
final class UserInterfaceTest extends TestCase {
	/**
	 * Tests hook registration.
	 */
	public function test_register_hooks_registers_media_ui_hooks(): void {
		$ui = new UserInterface();

		$ui->register_hooks();

		$this->assertSame( 10, has_filter( 'manage_media_columns', [ $ui, 'add_sync_column' ] ) );
		$this->assertSame( 10, has_action( 'manage_media_custom_column', [ $ui, 'render_sync_column' ] ) );
		$this->assertSame( 10, has_filter( 'media_row_actions', [ $ui, 'filter_media_row_actions' ] ) );
	}

	/**
	 * Tests sync column registration.
	 */
	public function test_add_sync_column_adds_sync_status_column(): void {
		$this->assertSame(
			[
				'title'                => 'Title',
				'onemedia_sync_status' => 'Sync Status',
			],
			( new UserInterface() )->add_sync_column( [ 'title' => 'Title' ] )
		);
	}

	/**
	 * Tests sync column output.
	 */
	public function test_render_sync_column_outputs_badge_for_sync_column_only(): void {
		$ui            = new UserInterface();
		$attachment_id = self::factory()->attachment->create();

		ob_start();
		$ui->render_sync_column( 'title', $attachment_id );
		$this->assertSame( '', ob_get_clean() );

		ob_start();
		$ui->render_sync_column( 'onemedia_sync_status', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'dashicons-no', $output );
		$this->assertStringContainsString( 'Not synced', $output );

		Attachment::set_is_synced( $attachment_id, true );

		ob_start();
		$ui->render_sync_column( 'onemedia_sync_status', $attachment_id );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'onemedia-sync-badge', $output );
		$this->assertStringContainsString( 'Synced', $output );
	}

	/**
	 * Tests media row action filtering.
	 */
	public function test_filter_media_row_actions_removes_delete_for_synced_attachments(): void {
		$ui            = new UserInterface();
		$attachment_id = self::factory()->attachment->create();
		$post_id       = self::factory()->post->create();
		$actions       = [
			'edit'   => 'Edit',
			'delete' => 'Delete',
		];

		$this->assertSame( $actions, $ui->filter_media_row_actions( $actions, get_post( $post_id ) ) );
		$this->assertSame( $actions, $ui->filter_media_row_actions( $actions, get_post( $attachment_id ) ) );

		Attachment::set_is_synced( $attachment_id, true );

		$this->assertSame( [ 'edit' => 'Edit' ], $ui->filter_media_row_actions( $actions, get_post( $attachment_id ) ) );
	}
}
