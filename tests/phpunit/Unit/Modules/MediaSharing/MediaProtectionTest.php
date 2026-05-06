<?php
/**
 * Tests for media protection helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

require_once dirname( __DIR__, 3 ) . '/includes/media-protection-function-shims.php';

use OneMedia\Modules\MediaSharing\Attachment;
use OneMedia\Modules\MediaSharing\MediaProtection;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( MediaProtection::class )]
final class MediaProtectionTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_transient( 'onemedia_delete_notice' );
		unset( $GLOBALS['onemedia_media_protection_wp_doing_ajax_callback'], $_POST['_wpnonce'], $_POST['is_onemedia_sync'], $_REQUEST['action'] );
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
	 * Tests AJAX uploads are ignored when the nonce is missing or invalid.
	 */
	public function test_add_term_to_attachment_ignores_invalid_ajax_nonce(): void {
		$attachment_id = self::factory()->attachment->create();
		$GLOBALS['onemedia_media_protection_wp_doing_ajax_callback'] = static fn (): bool => true;
		$_REQUEST['action'] = 'upload-attachment';

		( new MediaProtection() )->add_term_to_attachment( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );

		$_POST['_wpnonce'] = 'invalid';

		( new MediaProtection() )->add_term_to_attachment( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );
	}

	/**
	 * Tests AJAX uploads are ignored when they are not attachment uploads.
	 */
	public function test_add_term_to_attachment_ignores_non_upload_attachment_actions(): void {
		$attachment_id = self::factory()->attachment->create();
		$GLOBALS['onemedia_media_protection_wp_doing_ajax_callback'] = static fn (): bool => true;
		$_POST['_wpnonce']  = wp_create_nonce( 'media-form' );
		$_REQUEST['action'] = 'save-attachment';

		( new MediaProtection() )->add_term_to_attachment( $attachment_id );

		$this->assertSame( '', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );
	}

	/**
	 * Tests AJAX uploads persist the sync flag from the request payload.
	 */
	public function test_add_term_to_attachment_updates_sync_meta_for_ajax_uploads(): void {
		$attachment_id = self::factory()->attachment->create();
		$GLOBALS['onemedia_media_protection_wp_doing_ajax_callback'] = static fn (): bool => true;
		$_POST['_wpnonce']         = wp_create_nonce( 'media-form' );
		$_REQUEST['action']        = 'upload-attachment';
		$_POST['is_onemedia_sync'] = 'false';

		( new MediaProtection() )->add_term_to_attachment( $attachment_id );

		$this->assertSame( '0', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );

		$_POST['is_onemedia_sync'] = 'true';

		( new MediaProtection() )->add_term_to_attachment( $attachment_id );

		$this->assertSame( '1', get_post_meta( $attachment_id, Attachment::IS_SYNC_POSTMETA_KEY, true ) );
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

	/**
	 * Tests capability filtering returns the original capabilities for editable attachments.
	 */
	public function test_prevent_sync_media_editing_leaves_caps_unchanged_for_non_synced_attachments(): void {
		$protection    = new MediaProtection();
		$attachment_id = self::factory()->attachment->create();
		$caps          = [ 'edit_posts' ];

		$this->assertSame(
			$caps,
			$protection->prevent_sync_media_editing( $caps, 'edit_post', 1, [ $attachment_id ] )
		);
	}

	/**
	 * Tests capability filtering returns the original capabilities for non-attachment targets.
	 */
	public function test_prevent_sync_media_editing_leaves_caps_unchanged_for_non_attachments(): void {
		$protection = new MediaProtection();
		$post_id    = self::factory()->post->create();
		$caps       = [ 'edit_posts' ];

		$this->assertSame(
			$caps,
			$protection->prevent_sync_media_editing( $caps, 'edit_post', 1, [ $post_id ] )
		);
	}
}
