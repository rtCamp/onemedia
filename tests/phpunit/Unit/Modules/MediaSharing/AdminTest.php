<?php
/**
 * Tests for media sharing admin screen.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\Admin;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Modules\MediaSharing\Admin
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		parent::tearDown();
	}

	/**
	 * Tests hook registration.
	 */
	public function test_register_hooks_adds_expected_callbacks(): void {
		$admin = new Admin();

		$admin->register_hooks();

		$this->assertSame( 20, has_action( 'admin_menu', [ $admin, 'add_submenu' ] ) );
		$this->assertSame( 20, has_action( 'admin_enqueue_scripts', [ $admin, 'enqueue_scripts' ] ) );
		$this->assertSame( 10, has_action( 'current_screen', [ $admin, 'add_help_tabs' ] ) );
	}

	/**
	 * Tests submenu registration does not run on consumer sites.
	 */
	public function test_add_submenu_skips_consumer_site(): void {
		global $submenu;

		$submenu = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets the submenu global.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );

		( new Admin() )->add_submenu();

		$this->assertArrayNotHasKey( 'onemedia', $submenu );
	}

	/**
	 * Tests screen callback output.
	 */
	public function test_screen_callback_outputs_media_sharing_root(): void {
		ob_start();
		( new Admin() )->screen_callback();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Media Sharing', (string) $output );
		$this->assertStringContainsString( 'onemedia-media-sharing', (string) $output );
	}

	/**
	 * Tests enqueue_scripts bails on non-plugin hooks.
	 */
	public function test_enqueue_scripts_ignores_non_onemedia_hook(): void {
		( new Admin() )->enqueue_scripts( 'plugins.php' );

		$this->assertFalse( wp_script_is( 'onemedia-media-sharing', 'enqueued' ) );
	}
}
