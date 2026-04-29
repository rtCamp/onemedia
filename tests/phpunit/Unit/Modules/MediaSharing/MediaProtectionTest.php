<?php
/**
 * Tests for media protection helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\MediaSharing\MediaProtection;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Modules\MediaSharing\MediaProtection
 */
#[CoversClass( MediaProtection::class )]
final class MediaProtectionTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_transient( 'onemedia_delete_notice' );
		wp_set_current_user( 0 );

		parent::tearDown();
	}

	/**
	 * Tests hook registration on non-consumer and consumer sites.
	 */
	public function test_register_hooks_adds_base_hooks_and_consumer_cap_filter(): void {
		$protection = new MediaProtection();

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		$protection->register_hooks();

		$this->assertSame( 10, has_action( 'admin_notices', [ $protection, 'show_deletion_notice' ] ) );
		$this->assertSame( 10, has_action( 'add_attachment', [ $protection, 'add_term_to_attachment' ] ) );
		$this->assertFalse( has_filter( 'map_meta_cap', [ $protection, 'prevent_sync_media_editing' ] ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$protection->register_hooks();

		$this->assertSame( 10, has_filter( 'map_meta_cap', [ $protection, 'prevent_sync_media_editing' ] ) );
	}

	/**
	 * Tests that non-AJAX uploads are ignored.
	 */
	public function test_add_term_to_attachment_ignores_non_ajax_requests(): void {
		$attachment_id = self::factory()->attachment->create();

		( new MediaProtection() )->add_term_to_attachment( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );
	}

	/**
	 * Tests deletion notice output and transient cleanup.
	 */
	public function test_show_deletion_notice_outputs_notice_once(): void {
		$protection = new MediaProtection();

		ob_start();
		$protection->show_deletion_notice();
		$this->assertSame( '', ob_get_clean() );

		set_transient( 'onemedia_delete_notice', true, 30 );

		ob_start();
		$protection->show_deletion_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'You cannot delete media', $output );
		$this->assertFalse( get_transient( 'onemedia_delete_notice' ) );
	}

	/**
	 * Tests synced attachment capability restrictions through WordPress capabilities.
	 */
	public function test_user_can_blocks_edit_and_delete_for_synced_attachments(): void {
		$protection    = new MediaProtection();
		$attachment_id = self::factory()->attachment->create();
		$user_id       = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$protection->register_hooks();

		$this->assertTrue( user_can( $user_id, 'edit_post', $attachment_id ) );
		Attachment::set_is_synced( $attachment_id, true );

		$this->assertFalse( user_can( $user_id, 'edit_post', $attachment_id ) );
		$this->assertFalse( user_can( $user_id, 'delete_post', $attachment_id ) );

		Attachment::set_is_synced( $attachment_id, false );

		$this->assertTrue( user_can( $user_id, 'edit_post', $attachment_id ) );
	}
}
