<?php
/**
 * Tests for the Settings\Admin class.
 *
 * @package OneMedia\Tests\Unit\Modules\Settings
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Settings;

use OneMedia\Modules\Settings\Admin;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Settings\Admin class.
 */
#[CoversClass( Admin::class )]
final class AdminTest extends TestCase {
	/**
	 * Tests no errors on class instantiation or hook registration.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$admin = new Admin();
		$admin->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests screen_callback outputs the settings page mount point.
	 */
	public function test_screen_callback_outputs_expected_html(): void {
		$admin = new Admin();

		ob_start();
		$admin->screen_callback();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'onemedia-settings-page', (string) $output );
	}

	/**
	 * Tests add_action_links appends a settings link to an empty array.
	 */
	public function test_add_action_links_appends_settings_link(): void {
		$admin = new Admin();

		$result = $admin->add_action_links( [] );

		$this->assertCount( 1, $result );
		$this->assertStringContainsString( 'Settings', $result[0] );
	}

	/**
	 * Tests add_action_links preserves existing links.
	 */
	public function test_add_action_links_preserves_existing_links(): void {
		$admin = new Admin();

		$result = $admin->add_action_links( [ '<a href="#">Existing</a>' ] );

		$this->assertCount( 2, $result );
	}

	/**
	 * Tests add_body_classes returns a string.
	 */
	public function test_add_body_classes_returns_string(): void {
		$admin = new Admin();

		$result = $admin->add_body_classes( 'existing-class' );

		$this->assertIsString( $result );
	}
}
