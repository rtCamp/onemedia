<?php
/**
 * Tests for settings admin screen.
 *
 * @package OneMedia\Tests\Unit\Modules\Settings
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Settings;

use OneMedia\Modules\Core\Assets;
use OneMedia\Modules\Settings\Admin;
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
		global $menu;
		global $submenu;

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets admin menu globals.
		$menu = [];
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets admin submenu globals.
		$submenu = [];

		wp_dequeue_script( Assets::SETTINGS_SCRIPT_HANDLE );
		wp_dequeue_style( Assets::SETTINGS_SCRIPT_HANDLE );
		wp_deregister_script( Assets::SETTINGS_SCRIPT_HANDLE );
		wp_deregister_style( Assets::SETTINGS_SCRIPT_HANDLE );
		wp_dequeue_script( Assets::ONBOARDING_SCRIPT_HANDLE );
		wp_dequeue_style( Assets::ONBOARDING_SCRIPT_HANDLE );
		wp_deregister_script( Assets::ONBOARDING_SCRIPT_HANDLE );
		wp_deregister_style( Assets::ONBOARDING_SCRIPT_HANDLE );

		set_current_screen( 'front' );

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
	 * Tests screen callback output.
	 */
	public function test_screen_callback_outputs_settings_root(): void {
		ob_start();
		( new Admin() )->screen_callback();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Settings', (string) $output );
		$this->assertStringContainsString( 'onemedia-settings-page', (string) $output );
	}

	/**
	 * Tests admin menu and submenu registration, including removal of the default submenu.
	 */
	public function test_admin_menu_methods_register_and_remove_expected_pages(): void {
		global $menu;
		global $submenu;

		$admin = new Admin();
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets admin menu globals.
		$menu = [];
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test fixture resets admin submenu globals.
		$submenu = [];
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

		$admin->add_admin_menu();
		$admin->add_submenu();

		$this->assertArrayHasKey( Admin::MENU_SLUG, $submenu );
		$this->assertContains( Admin::MENU_SLUG, array_column( $submenu[ Admin::MENU_SLUG ], 2 ) );
		$this->assertContains( Admin::SCREEN_ID, array_column( $submenu[ Admin::MENU_SLUG ], 2 ) );

		$admin->remove_default_submenu();

		$this->assertNotContains( Admin::MENU_SLUG, array_column( $submenu[ Admin::MENU_SLUG ], 2 ) );
		$this->assertContains( Admin::SCREEN_ID, array_column( $submenu[ Admin::MENU_SLUG ], 2 ) );
	}

	/**
	 * Tests settings action link generation.
	 */
	public function test_add_action_links_appends_settings_link(): void {
		$result = ( new Admin() )->add_action_links( [ 'deactivate' => 'Deactivate' ] );

		$this->assertSame( 'Deactivate', $result['deactivate'] );
		$this->assertStringContainsString( 'page=onemedia-settings', end( $result ) );
	}

	/**
	 * Tests non-array action links are defensively handled.
	 *
	 * @expectedIncorrectUsage OneMedia\Modules\Settings\Admin::add_action_links
	 */
	public function test_add_action_links_handles_non_array_input(): void {
		$result = ( new Admin() )->add_action_links( 'invalid' );

		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'Settings', $result[0] );
	}

	/**
	 * Tests the plugins screen enqueues onboarding assets but not settings screen assets.
	 */
	public function test_enqueue_scripts_enqueues_only_onboarding_assets_on_plugins_screen(): void {
		$this->register_settings_admin_assets();
		set_current_screen( 'plugins' );

		( new Admin() )->enqueue_scripts( 'plugins.php' );

		$this->assertTrue( wp_script_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'OneMediaOnboarding', (string) wp_scripts()->get_data( Assets::ONBOARDING_SCRIPT_HANDLE, 'data' ) );
		$this->assertFalse( wp_script_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Tests ineligible screens skip both onboarding and settings assets.
	 */
	public function test_enqueue_scripts_skips_assets_on_ineligible_screens(): void {
		$this->register_settings_admin_assets();
		set_current_screen( 'dashboard' );

		( new Admin() )->enqueue_scripts( 'index.php' );

		$this->assertFalse( wp_script_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_style_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Tests the settings screen enqueues both onboarding and settings assets.
	 */
	public function test_enqueue_scripts_enqueues_settings_and_onboarding_assets_on_settings_screen(): void {
		$this->register_settings_admin_assets();
		set_current_screen( 'onemedia_page_onemedia-settings' );

		( new Admin() )->enqueue_scripts( 'onemedia_page_onemedia-settings' );

		$this->assertTrue( wp_script_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'page=onemedia-settings', (string) wp_scripts()->get_data( Assets::ONBOARDING_SCRIPT_HANDLE, 'data' ) );
		$this->assertTrue( wp_script_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_style_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertStringContainsString( 'OneMediaSettings', (string) wp_scripts()->get_data( Assets::SETTINGS_SCRIPT_HANDLE, 'data' ) );
	}

	/**
	 * Tests site selection modal output only appears on eligible screens without a site type.
	 */
	public function test_inject_site_selection_modal_handles_early_returns_and_render_path(): void {
		$admin = new Admin();

		set_current_screen( 'dashboard' );
		ob_start();
		$admin->inject_site_selection_modal();
		$this->assertSame( '', (string) ob_get_clean() );

		set_current_screen( 'plugins' );
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		ob_start();
		$admin->inject_site_selection_modal();
		$this->assertSame( '', (string) ob_get_clean() );

		delete_option( Settings::OPTION_SITE_TYPE );
		ob_start();
		$admin->inject_site_selection_modal();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'onemedia-site-selection-modal', (string) $output );
	}

	/**
	 * Tests admin body classes for modal and missing sites.
	 */
	public function test_add_body_classes_appends_modal_and_missing_site_classes(): void {
		$admin = new Admin();

		set_current_screen( 'plugins' );

		$classes = $admin->add_body_classes( 'base' );

		$this->assertStringContainsString( 'onemedia-site-selection-modal', $classes );
		$this->assertStringContainsString( 'onemedia-missing-brand-sites', $classes );

		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'key',
				],
			]
		);

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertSame( 'base', $admin->add_body_classes( 'base' ) );
	}

	/**
	 * Tests body classes are left unchanged when there is no current screen.
	 */
	public function test_add_body_classes_returns_original_classes_without_current_screen(): void {
		$previous_screen = $GLOBALS['current_screen'] ?? null;
		unset( $GLOBALS['current_screen'] );

		$this->assertSame( 'base', ( new Admin() )->add_body_classes( 'base' ) );

		if ( null !== $previous_screen ) {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore previous test screen state.
			$GLOBALS['current_screen'] = $previous_screen;
		}
	}

	/**
	 * Registers placeholder settings admin assets for enqueue tests.
	 */
	private function register_settings_admin_assets(): void {
		wp_register_script( Assets::SETTINGS_SCRIPT_HANDLE, false, [], '1.0.0', true );
		wp_register_style( Assets::SETTINGS_SCRIPT_HANDLE, false, [], '1.0.0' );
		wp_register_script( Assets::ONBOARDING_SCRIPT_HANDLE, false, [], '1.0.0', true );
		wp_register_style( Assets::ONBOARDING_SCRIPT_HANDLE, false, [], '1.0.0' );
	}
}
