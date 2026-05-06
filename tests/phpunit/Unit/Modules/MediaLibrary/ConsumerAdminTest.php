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
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

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
		unset( $_GET['post'], $_REQUEST['id'], $_REQUEST['action'], $_REQUEST['nonce'] );
		wp_set_current_user( 0 );

		parent::tearDown();
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
	 * Tests hook registration for consumer sites.
	 */
	public function test_register_hooks_registers_consumer_site_hooks(): void {
		$admin = new ConsumerAdmin();

		$admin->register_hooks();

		$this->assertSame( 10, has_filter( 'delete_attachment', [ $admin, 'prevent_attachment_deletion' ] ) );
		$this->assertSame( 10, has_action( 'admin_notices', [ $admin, 'show_deletion_notice' ] ) );
		$this->assertSame( 10, has_action( 'load-post.php', [ $admin, 'prevent_attachment_edit' ] ) );
		$this->assertSame( 0, has_action( 'wp_ajax_save-attachment', [ $admin, 'prevent_save_attachment_ajax' ] ) );
		$this->assertSame( 10, has_filter( 'media_row_actions', [ $admin, 'remove_edit_delete_links' ] ) );

		remove_filter( 'delete_attachment', [ $admin, 'prevent_attachment_deletion' ], 10 );
		remove_action( 'admin_notices', [ $admin, 'show_deletion_notice' ], 10 );
		remove_action( 'load-post.php', [ $admin, 'prevent_attachment_edit' ], 10 );
		remove_action( 'wp_ajax_save-attachment', [ $admin, 'prevent_save_attachment_ajax' ], 0 );
		remove_filter( 'media_row_actions', [ $admin, 'remove_edit_delete_links' ], 10 );
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
	 * Tests delete-only notice path returns before the edit notice branch.
	 */
	public function test_show_deletion_notice_outputs_delete_notice_only(): void {
		$delete_transient = $this->get_private_constant( 'DELETION_NOTICE_TRANSIENT' );

		set_transient( $delete_transient, true, 30 );

		ob_start();
		( new ConsumerAdmin() )->show_deletion_notice();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'please delete it from there first', (string) $output );
		$this->assertStringNotContainsString( 'please edit it over there', (string) $output );
		$this->assertFalse( get_transient( $delete_transient ) );
	}

	/**
	 * Tests deletion guard early returns for null, non-attachment, and unsynced attachment inputs.
	 */
	public function test_prevent_attachment_deletion_returns_original_value_for_unblocked_inputs(): void {
		$admin           = new ConsumerAdmin();
		$post_id         = self::factory()->post->create();
		$attachment_id   = self::factory()->attachment->create();
		$attachment_post = get_post( $attachment_id );

		$this->assertFalse( $admin->prevent_attachment_deletion( false, null ) );
		$this->assertTrue( $admin->prevent_attachment_deletion( true, get_post( $post_id ) ) );
		$this->assertTrue( $admin->prevent_attachment_deletion( true, $attachment_post ) );
	}

	/**
	 * Tests synced attachments are redirected away from deletion.
	 */
	public function test_prevent_attachment_deletion_redirects_for_synced_attachments(): void {
		$attachment_id    = self::factory()->attachment->create();
		$attachment_post  = get_post( $attachment_id );
		$delete_transient = $this->get_private_constant( 'DELETION_NOTICE_TRANSIENT' );
		$redirect_filter  = static function ( string $location ): string {
			throw new \OneMedia\Tests\Unit\Modules\MediaLibrary\RedirectInterceptedException( esc_url_raw( $location ) );
		};

		Attachment::set_is_synced( $attachment_id, true );
		add_filter( 'wp_redirect', $redirect_filter, 10, 1 );

		try {
			( new ConsumerAdmin() )->prevent_attachment_deletion( true, $attachment_post );
			$this->fail( 'Expected redirect interception for synced attachment deletion.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaLibrary\RedirectInterceptedException $exception ) {
			$this->assertSame( admin_url( 'upload.php' ), $exception->getMessage() );
			$this->assertTrue( (bool) get_transient( $delete_transient ) );
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 10 );
		}
	}

	/**
	 * Tests synced attachments are redirected away from editing.
	 */
	public function test_prevent_attachment_edit_redirects_for_synced_attachments(): void {
		$attachment_id   = self::factory()->attachment->create();
		$edit_transient  = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );
		$redirect_filter = static function ( string $location ): string {
			throw new \OneMedia\Tests\Unit\Modules\MediaLibrary\RedirectInterceptedException( esc_url_raw( $location ) );
		};
		$admin           = new ConsumerAdminPostIdDouble( $attachment_id );

		Attachment::set_is_synced( $attachment_id, true );
		set_current_screen( 'post' );
		$screen            = get_current_screen();
		$screen->post_type = 'attachment';
		add_filter( 'wp_redirect', $redirect_filter, 10, 1 );

		try {
			$admin->prevent_attachment_edit();
			$this->fail( 'Expected redirect interception for synced attachment edit.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaLibrary\RedirectInterceptedException $exception ) {
			$this->assertSame( admin_url( 'upload.php' ), $exception->getMessage() );
			$this->assertTrue( (bool) get_transient( $edit_transient ) );
		} finally {
			remove_filter( 'wp_redirect', $redirect_filter, 10 );
		}
	}

	/**
	 * Tests edit guard returns early when the request does not target an attachment.
	 */
	public function test_prevent_attachment_edit_returns_early_for_invalid_or_non_attachment_targets(): void {
		$edit_transient = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );
		$post_id        = self::factory()->post->create();

		$empty_admin          = new ConsumerAdminNullInputDouble();
		$non_attachment_admin = new ConsumerAdminPostIdDouble( $post_id );

		$empty_admin->prevent_attachment_edit();
		$non_attachment_admin->prevent_attachment_edit();

		$this->assertFalse( get_transient( $edit_transient ) );
	}

	/**
	 * Tests edit guard returns early when not on the attachment edit screen.
	 */
	public function test_prevent_attachment_edit_returns_early_for_non_attachment_screens(): void {
		$attachment_id  = self::factory()->attachment->create();
		$edit_transient = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );
		$admin          = new ConsumerAdminPostIdDouble( $attachment_id );

		set_current_screen( 'dashboard' );
		$admin->prevent_attachment_edit();

		$this->assertFalse( get_transient( $edit_transient ) );
	}

	/**
	 * Tests edit guard returns early for unsynced attachments on the correct edit screen.
	 */
	public function test_prevent_attachment_edit_returns_early_for_unsynced_attachments(): void {
		$attachment_id  = self::factory()->attachment->create();
		$edit_transient = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );
		$admin          = new ConsumerAdminPostIdDouble( $attachment_id );

		set_current_screen( 'post' );
		$screen            = get_current_screen();
		$screen->post_type = 'attachment';

		$admin->prevent_attachment_edit();

		$this->assertFalse( get_transient( $edit_transient ) );
	}

	/**
	 * Tests AJAX save guard returns early for invalid actions and unsynced attachments.
	 */
	public function test_prevent_save_attachment_ajax_returns_early_for_invalid_action_or_unsynced_attachment(): void {
		$attachment_id  = self::factory()->attachment->create();
		$edit_transient = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'edit-attachment';

		ob_start();
		( new ConsumerAdmin() )->prevent_save_attachment_ajax();
		$invalid_action_output = ob_get_clean();

		$_REQUEST['action'] = 'save-attachment';

		ob_start();
		( new ConsumerAdmin() )->prevent_save_attachment_ajax();
		$unsynced_output = ob_get_clean();

		$this->assertSame( '', (string) $invalid_action_output );
		$this->assertSame( '', (string) $unsynced_output );
		$this->assertFalse( get_transient( $edit_transient ) );
	}

	/**
	 * Tests AJAX save guard returns a JSON error when the user cannot edit the attachment.
	 */
	#[RunInSeparateProcess]
	public function test_prevent_save_attachment_ajax_returns_json_error_for_users_without_permission(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create();
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaLibrary\AjaxTerminationInterceptedException();
			};
		};

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'save-attachment';

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/"success":false/' );

		try {
			( new ConsumerAdmin() )->prevent_save_attachment_ajax();
			$this->fail( 'Expected AJAX termination for unauthorized attachment save.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaLibrary\AjaxTerminationInterceptedException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaLibrary\AjaxTerminationInterceptedException::class, $exception );
			return;
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
	}

	/**
	 * Tests AJAX save guard returns a JSON error when the attachment is synced.
	 */
	#[RunInSeparateProcess]
	public function test_prevent_save_attachment_ajax_returns_json_error_for_synced_attachment(): void {
		if ( ! defined( 'DOING_AJAX' ) ) {
			define( 'DOING_AJAX', true );
		}

		$attachment_id    = self::factory()->attachment->create();
		$edit_transient   = $this->get_private_constant( 'EDIT_NOTICE_TRANSIENT' );
		$wp_die_ajax_hook = static function (): callable {
			return static function (): void {
				throw new \OneMedia\Tests\Unit\Modules\MediaLibrary\AjaxTerminationInterceptedException();
			};
		};

		Attachment::set_is_synced( $attachment_id, true );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		$_REQUEST['id']     = (string) $attachment_id;
		$_REQUEST['nonce']  = wp_create_nonce( 'update-post_' . $attachment_id );
		$_REQUEST['action'] = 'save-attachment';

		add_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		$this->expectOutputRegex( '/(?s)"success":false.*please edit it over there/' );

		try {
			( new ConsumerAdmin() )->prevent_save_attachment_ajax();
			$this->fail( 'Expected AJAX termination for synced attachment save.' );
		} catch ( \OneMedia\Tests\Unit\Modules\MediaLibrary\AjaxTerminationInterceptedException $exception ) {
			$this->assertInstanceOf( \OneMedia\Tests\Unit\Modules\MediaLibrary\AjaxTerminationInterceptedException::class, $exception );
			$this->assertTrue( (bool) get_transient( $edit_transient ) );
			return;
		} finally {
			remove_filter( 'wp_die_ajax_handler', $wp_die_ajax_hook );
		}
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

	/**
	 * Reads private class constants used for transient keys.
	 *
	 * @param string $constant_name Constant name.
	 */
	private function get_private_constant( string $constant_name ): string {
		$constant = ( new \ReflectionClass( ConsumerAdmin::class ) )->getReflectionConstant( $constant_name );

		return (string) $constant->getValue();
	}
}

/**
 * ConsumerAdmin test double that returns the configured post ID.
 */
final class ConsumerAdminPostIdDouble extends ConsumerAdmin {
	/**
	 * Stored post ID returned by the filtered input helper.
	 *
	 * @var int
	 */
	private int $post_id;

	/**
	 * Constructor.
	 *
	 * @param int $post_id Post ID to return for the `post` input.
	 */
	public function __construct( int $post_id ) {
		$this->post_id = $post_id;
	}

	/**
	 * Return the configured post ID for attachment-edit checks.
	 *
	 * @param int                   $type Input type.
	 * @param string                $var_name Input name.
	 * @param int                   $_filter Filter id.
	 * @param array<int, mixed>|int $_options Filter options.
	 */
	protected function get_filtered_input( int $type, string $var_name, int $_filter = FILTER_DEFAULT, array|int $_options = 0 ): mixed { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Signature must match the parent method in this test double.
		return INPUT_GET === $type && 'post' === $var_name ? $this->post_id : null;
	}
}

/**
 * ConsumerAdmin test double that always returns null for filtered input.
 */
final class ConsumerAdminNullInputDouble extends ConsumerAdmin {
	/**
	 * Return null for all filtered input requests.
	 *
	 * @param int                   $_type Input type.
	 * @param string                $_var_name Input name.
	 * @param int                   $_filter Filter id.
	 * @param array<int, mixed>|int $_options Filter options.
	 */
	protected function get_filtered_input( int $_type, string $_var_name, int $_filter = FILTER_DEFAULT, array|int $_options = 0 ): mixed { // phpcs:ignore SlevomatCodingStandard.Functions.UnusedParameter.UnusedParameter -- Signature must match the parent method in this test double.
		return null;
	}
}

/**
 * Exception used to intercept redirects in ConsumerAdmin tests.
 */
final class RedirectInterceptedException extends \RuntimeException {
}

/**
 * Exception used to intercept AJAX termination in ConsumerAdmin tests.
 */
final class AjaxTerminationInterceptedException extends \RuntimeException {
}
