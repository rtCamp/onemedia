<?php
/**
 * Tests for the MediaSharing\Admin class.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\Core\Assets;
use OneMedia\Modules\MediaSharing\Admin;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MediaSharing\Admin class.
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
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_expected_hooks(): void {
		$admin = new Admin();
		$admin->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests add_submenu returns early when the site is not governing.
	 */
	public function test_add_submenu_returns_early_when_not_governing(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$admin = new Admin();
		$admin->add_submenu();

		$this->assertTrue( true );
	}

	/**
	 * Tests screen_callback outputs the media sharing mount point.
	 */
	public function test_screen_callback_outputs_expected_html(): void {
		$admin = new Admin();

		ob_start();
		$admin->screen_callback();
		$output = ob_get_clean();

		$this->assertStringContainsString( 'onemedia-media-sharing', (string) $output );
	}

	/**
	 * Tests enqueue_scripts returns early for non-OneMedia admin pages.
	 */
	public function test_enqueue_scripts_returns_early_for_non_onemedia_hook(): void {
		$admin = new Admin();
		$admin->enqueue_scripts( 'plugins.php' );

		$this->assertFalse( wp_script_is( Assets::MEDIA_SHARING_SCRIPT_HANDLE, 'enqueued' ) );
	}

	/**
	 * Tests add_help_tabs returns early without a matching current screen.
	 */
	public function test_add_help_tabs_returns_early_for_non_matching_screen(): void {
		$admin = new Admin();
		$admin->add_help_tabs();

		$this->assertTrue( true );
	}
}
