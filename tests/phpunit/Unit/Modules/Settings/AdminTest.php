<?php
/**
 * Tests for settings admin screen.
 *
 * @package OneMedia\Tests\Unit\Modules\Settings
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Settings;

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
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

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
}
