<?php
/**
 * Tests for media sharing admin screen.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\Core\Assets;
use OneMedia\Modules\MediaSharing\Admin;
use OneMedia\Modules\Settings\Admin as Settings_Admin;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		wp_dequeue_script( Assets::MEDIA_SHARING_SCRIPT_HANDLE );
		wp_dequeue_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE );
		wp_deregister_script( Assets::MEDIA_SHARING_SCRIPT_HANDLE );
		wp_deregister_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE );
		wp_dequeue_style( Assets::MAIN_STYLE_HANDLE );
		wp_deregister_style( Assets::MAIN_STYLE_HANDLE );

		$current_screen = get_current_screen();
		if ( $current_screen instanceof \WP_Screen ) {
			$current_screen->remove_help_tabs();
		}

		parent::tearDown();
	}

	/**
	 * Tests no errors on class lifecycle methods.
	 */
	public function test_class_instantiation(): void {
		$admin = new Admin();

		$admin->register_hooks();
		$admin->enqueue_scripts( 'plugins.php' );

		$this->assertTrue( true );
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
	 * Tests submenu registration runs on governing sites.
	 */
	public function test_add_submenu_registers_media_sharing_page_for_governing_site(): void {
		global $submenu, $_parent_pages, $_registered_pages;

		$submenu           = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets the submenu global.
		$_parent_pages     = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets the registered parent-page mapping.
		$_registered_pages = []; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets the registered page hooks.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		add_menu_page( 'OneMedia', 'OneMedia', 'manage_options', Settings_Admin::MENU_SLUG, '__return_null' );

		( new Admin() )->add_submenu();

		$this->assertArrayHasKey( 'toplevel_page_' . Settings_Admin::MENU_SLUG, $_registered_pages );
		$this->assertArrayHasKey( Settings_Admin::MENU_SLUG, $_parent_pages );
		$this->assertFalse( $_parent_pages[ Settings_Admin::MENU_SLUG ] );
		$this->assertSame( [], $submenu );
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

	/**
	 * Tests enqueue_scripts bails when the current screen is not a OneMedia screen.
	 */
	public function test_enqueue_scripts_ignores_non_onemedia_screen(): void {
		$this->register_media_sharing_assets();
		set_current_screen( 'dashboard' );

		( new Admin() )->enqueue_scripts( 'toplevel_page_onemedia' );

		$this->assertFalse( wp_script_is( Assets::MEDIA_SHARING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::MAIN_STYLE_HANDLE, 'enqueued' ) );
	}

	/**
	 * Tests enqueue_scripts localizes and enqueues assets on the media sharing screen.
	 */
	public function test_enqueue_scripts_enqueues_media_sharing_assets(): void {
		$this->register_media_sharing_assets();
		set_current_screen( 'toplevel_page_onemedia' );

		( new Admin() )->enqueue_scripts( 'toplevel_page_onemedia' );

		$this->assertTrue( wp_script_is( Assets::MEDIA_SHARING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::MAIN_STYLE_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'OneMediaMediaSharing', (string) wp_scripts()->get_data( Assets::MEDIA_SHARING_SCRIPT_HANDLE, 'data' ) );
		$this->assertStringContainsString( 'OneMediaMediaFrame', (string) wp_scripts()->get_data( Assets::MEDIA_FRAME_SCRIPT_HANDLE, 'data' ) );
	}

	/**
	 * Tests help tabs are ignored outside the media sharing screen.
	 */
	public function test_add_help_tabs_returns_early_for_non_target_screen(): void {
		set_current_screen( 'dashboard' );

		( new Admin() )->add_help_tabs();

		$this->assertSame( [], get_current_screen()->get_help_tabs() );
	}

	/**
	 * Tests help tabs are added to the media sharing screen.
	 */
	public function test_add_help_tabs_registers_expected_tabs(): void {
		set_current_screen( 'toplevel_page_onemedia' );

		( new Admin() )->add_help_tabs();

		$tabs = get_current_screen()->get_help_tabs();

		$this->assertCount( 4, $tabs );
		$this->assertSame( 'Overview', $tabs['onemedia-overview']['title'] );
		$this->assertSame( 'How to Share', $tabs['onemedia-how-to-share']['title'] );
		$this->assertSame( 'Sharing Modes', $tabs['onemedia-sharing-modes']['title'] );
		$this->assertSame( 'Tips & Best Practices', $tabs['onemedia-best-practices']['title'] );
	}

	/**
	 * Registers placeholder assets for media sharing enqueue tests.
	 */
	private function register_media_sharing_assets(): void {
		wp_register_script( Assets::MEDIA_SHARING_SCRIPT_HANDLE, false, [], '1.0.0', true );
		wp_register_script( Assets::MEDIA_FRAME_SCRIPT_HANDLE, false, [], '1.0.0', true );
		wp_register_style( Assets::MAIN_STYLE_HANDLE, false, [], '1.0.0' );
	}
}
