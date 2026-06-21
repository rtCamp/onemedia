<?php
/**
 * Admin settings screen unit tests.
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
 * Tests for the Settings\Admin class.
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		set_current_screen( 'dashboard' );

		// Ensure each test starts with a known enqueue state.
		wp_dequeue_script( Assets::SETTINGS_SCRIPT_HANDLE );
		wp_dequeue_script( Assets::ONBOARDING_SCRIPT_HANDLE );

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$this->remove_menu_entries();

		parent::tearDown();
	}

	/**
	 * Ensures the class can be instantiated and hook methods can be called without error.
	 */
	public function test_class_instantiation(): void {
		$admin = new Admin();

		$admin->register_hooks();
		$admin->enqueue_scripts( Admin::SCREEN_ID );

		// If we made it this far with no errors, we are good.
		$this->assertTrue( true );
	}

	/**
	 * Ensures the top-level menu page is registered.
	 */
	public function test_add_admin_menu_registers_menu_page(): void {
		( new Admin() )->add_admin_menu();

		$this->assertTrue( $this->menu_contains_slug( Admin::MENU_SLUG ) );
	}

	/**
	 * Ensures the settings submenu page is registered.
	 */
	public function test_add_submenu_registers_submenu_page(): void {
		$admin = new Admin();

		$admin->add_admin_menu();
		$admin->add_submenu();

		$this->assertArrayHasKey( Admin::MENU_SLUG, $GLOBALS['submenu'] );
		$this->assertTrue( $this->submenu_contains_slug( Admin::MENU_SLUG, Admin::SCREEN_ID ) );
	}

	/**
	 * Tests remove_default_submenu removes the duplicate parent menu slug from the submenu.
	 */
	public function test_remove_default_submenu_removes_duplicate_menu_slug(): void {
		$admin = new Admin();
		$admin->add_admin_menu();
		$admin->add_submenu();
		$admin->remove_default_submenu();

		$this->assertFalse( $this->submenu_contains_slug( Admin::MENU_SLUG, Admin::MENU_SLUG ) );
	}

	/**
	 * Tests screen_callback outputs the settings page mount point.
	 */
	public function test_screen_callback_outputs_expected_html(): void {
		ob_start();
		( new Admin() )->screen_callback();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'onemedia-settings-page', $output );
		$this->assertStringContainsString( 'wrap', $output );
		$this->assertStringContainsString( 'Settings', $output );
	}

	/**
	 * Ensures the plugin action links include a settings link.
	 */
	public function test_add_action_links_appends_settings_link(): void {
		$admin = new Admin();

		// Test input yields 1 link with correct URL.
		$links = $admin->add_action_links( [] );
		$this->assertCount( 1, $links );
		$this->assertStringContainsString( 'Settings', $links[0] );
		$this->assertStringContainsString( 'admin.php?page=' . Admin::SCREEN_ID, $links[0] );

		// Test existing links are preserved.
		$links = $admin->add_action_links( [ '<a href="#">Existing</a>' ] );
		$this->assertCount( 2, $links );

		// Test invalid input triggers _doing_it_wrong.
		$this->setExpectedIncorrectUsage( Admin::class . '::add_action_links' );
		$admin->add_action_links( 'not-an-array' );
	}

	/**
	 * Ensures admin body classes are returned as a string.
	 */
	public function test_add_body_classes_returns_classes_string(): void {
		set_current_screen( 'plugins.php' );
		$admin = new Admin();

		$classes = $admin->add_body_classes( '' );

		$this->assertIsString( $classes );
		$this->assertStringContainsString( 'onemedia-site-selection-modal', $classes );
		$this->assertStringContainsString( 'onemedia-missing-brand-sites', $classes );

		// Test with already configured which should remove the missing-brand-sites class and keep the modal class.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		update_option(
			Settings::OPTION_GOVERNING_SHARED_SITES,
			[
				[
					'url'     => 'https://example.com',
					'name'    => 'Test',
					'api_key' => '',
				],
			]
		);
		$classes = $admin->add_body_classes( '' );
		$this->assertIsString( $classes );
		$this->assertStringNotContainsString( 'onemedia-site-selection-modal', $classes );
		$this->assertStringNotContainsString( 'onemedia-missing-brand-sites', $classes );

		// Test with a bad current screen which should return the original classes unmodified.
		set_current_screen( 'not-a-real-screen' );
		$classes = $admin->add_body_classes( 'original-classes' );
		$this->assertSame( 'original-classes', $classes );
	}

	/**
	 * Ensures the site-selection modal renders when onboarding is needed.
	 */
	public function test_inject_site_selection_modal_outputs_html_when_conditions_met(): void {
		set_current_screen( 'plugins.php' );

		ob_start();
		( new Admin() )->inject_site_selection_modal();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'onemedia-site-selection-modal', $output );
		$this->assertStringContainsString( 'onemedia-modal', $output );
	}

	/**
	 * Ensures the site-selection modal does not render after setup.
	 */
	public function test_inject_site_selection_modal_outputs_nothing_when_site_type_set(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		set_current_screen( 'plugins.php' );

		ob_start();
		( new Admin() )->inject_site_selection_modal();
		$output = (string) ob_get_clean();

		$this->assertSame( '', trim( $output ) );
	}

	/**
	 * Ensures add_body_classes returns the original classes when no current screen is set.
	 *
	 * `set_current_screen('not-a-real-screen')` creates a truthy WP_Screen object and
	 * does NOT exercise the `if ( ! $current_screen )` guard. Only unsetting the
	 * global reaches that branch.
	 */
	public function test_add_body_classes_returns_unchanged_when_no_current_screen(): void {
		unset( $GLOBALS['current_screen'] );

		$result = ( new Admin() )->add_body_classes( 'original-classes' );

		$this->assertSame( 'original-classes', $result );
	}

	/**
	 * Ensures enqueue_scripts enqueues both the settings and onboarding assets
	 * when called on the settings screen.
	 *
	 * The onboarding assertion covers the body of the private
	 * `enqueue_onboarding_scripts()` method, which is invoked unconditionally
	 * whenever a current screen exists.
	 */
	public function test_enqueue_scripts_enqueues_settings_scripts_on_settings_screen(): void {
		set_current_screen( 'toplevel_page_onemedia-settings' );

		( new Admin() )->enqueue_scripts( 'toplevel_page_onemedia-settings' );

		$this->assertTrue( wp_script_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertTrue( wp_script_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Ensures enqueue_scripts bails early when no current screen is set.
	 *
	 * `unset( $GLOBALS['current_screen'] )` is the only way to make
	 * `get_current_screen()` return null; `set_current_screen('')` creates an
	 * empty `WP_Screen` object that does NOT satisfy the instanceof guard.
	 */
	public function test_enqueue_scripts_bails_when_no_current_screen(): void {
		unset( $GLOBALS['current_screen'] );

		( new Admin() )->enqueue_scripts( 'toplevel_page_onemedia-settings' );

		$this->assertFalse( wp_script_is( Assets::SETTINGS_SCRIPT_HANDLE, 'enqueued' ) );
		$this->assertFalse( wp_script_is( Assets::ONBOARDING_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Checks whether a menu slug is present in the admin menu.
	 *
	 * @param string $slug Menu slug.
	 */
	private function menu_contains_slug( string $slug ): bool {
		foreach ( (array) $GLOBALS['menu'] as $menu_item ) {
			if ( isset( $menu_item[2] ) && $slug === $menu_item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether a submenu slug is present for a menu slug.
	 *
	 * @param string $menu_slug    Parent menu slug.
	 * @param string $submenu_slug Submenu slug.
	 */
	private function submenu_contains_slug( string $menu_slug, string $submenu_slug ): bool {
		foreach ( (array) ( $GLOBALS['submenu'][ $menu_slug ] ?? [] ) as $submenu_item ) {
			if ( isset( $submenu_item[2] ) && $submenu_slug === $submenu_item[2] ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Removes menu entries registered by this test.
	 */
	private function remove_menu_entries(): void {
		foreach ( (array) ( $GLOBALS['menu'] ?? [] ) as $index => $menu_item ) {
			if ( isset( $menu_item[2] ) && Admin::MENU_SLUG === $menu_item[2] ) {
				unset( $GLOBALS['menu'][ $index ] );
			}
		}

		unset( $GLOBALS['submenu'][ Admin::MENU_SLUG ] );
	}
}
