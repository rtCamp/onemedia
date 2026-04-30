<?php
/**
 * Tests for consumer media library restrictions.
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
 * Test class.
 */
#[CoversClass( ConsumerAdmin::class )]
final class ConsumerAdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_transient( $this->get_private_constant( 'DELETION_NOTICE_TRANSIENT' ) );
		delete_transient( $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' ) );

		parent::tearDown();
	}

	/**
	 * Reads private class constants used for transient keys.
	 *
	 * @param string $constant_name Constant name.
	 */
	private function get_private_constant( string $constant_name ): string {
		$constant = ( new \ReflectionClass( ConsumerAdmin::class ) )->getReflectionConstant( $constant_name );

		return (string) $constant->getValue();
	}

	/**
	 * Tests hook registration is skipped on governing sites.
	 */
	public function test_register_hooks_skips_governing_site(): void {
		$admin = new ConsumerAdmin();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$admin->register_hooks();

		$this->assertFalse( has_filter( 'delete_attachment', [ $admin, 'prevent_attachment_deletion' ] ) );
		$this->assertFalse( has_action( 'admin_notices', [ $admin, 'show_deletion_notice' ] ) );
		$this->assertFalse( has_filter( 'media_row_actions', [ $admin, 'remove_edit_delete_links' ] ) );
	}

	/**
	 * Tests transient notices.
	 */
	public function test_show_deletion_notice_outputs_and_clears_notices(): void {
		$delete_transient = $this->get_private_constant( 'DELETION_NOTICE_TRANSIENT' );
		$edit_transient   = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );

		set_transient( $delete_transient, true, 30 );
		set_transient( $edit_transient, true, 30 );

		ob_start();
		( new ConsumerAdmin() )->show_deletion_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'please delete it from there first', (string) $output );
		$this->assertStringContainsString( 'please edit it over there', (string) $output );
		$this->assertFalse( get_transient( $delete_transient ) );
		$this->assertFalse( get_transient( $edit_transient ) );
	}

	/**
	 * Tests synced attachments have edit/delete row actions removed.
	 */
	public function test_remove_edit_delete_links_only_changes_synced_attachments(): void {
		$admin         = new ConsumerAdmin();
		$attachment_id = self::factory()->attachment->create();
		$post          = get_post( $attachment_id );
		$actions       = [
			'edit'   => 'Edit',
			'delete' => 'Delete',
			'view'   => 'View',
		];

		$this->assertSame( $actions, $admin->remove_edit_delete_links( $actions, $post ) );

		Attachment::set_is_synced( $attachment_id, true );
		$result = $admin->remove_edit_delete_links( $actions, $post );

		$this->assertArrayNotHasKey( 'edit', $result );
		$this->assertArrayNotHasKey( 'delete', $result );
		$this->assertSame( 'View', $result['view'] );
		$this->assertSame( 'invalid', $admin->remove_edit_delete_links( 'invalid', $post ) );
	}
}
