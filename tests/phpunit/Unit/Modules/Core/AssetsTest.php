<?php
/**
 * Tests for asset registration helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\Core
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Core;

use OneMedia\Modules\Core\Assets;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Assets::class )]
final class AssetsTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		$property = new \ReflectionProperty( Assets::class, 'localized_data' );
		$property->setValue( null, [] );

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );

		parent::tearDown();
	}

	/**
	 * Tests localized data is prepared and cached.
	 */
	public function test_get_localized_data_returns_expected_script_data(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );

		$data = Assets::get_localized_data();

		$this->assertArrayHasKey( 'restUrl', $data );
		$this->assertArrayHasKey( 'restNonce', $data );
		$this->assertArrayHasKey( 'apiKey', $data );
		$this->assertSame( Settings::SITE_TYPE_CONSUMER, $data['siteType'] );
		$this->assertSame( 'onemedia_sync_status', $data['syncStatus'] );
		$this->assertSame( $data, Assets::get_localized_data() );
	}

	/**
	 * Tests no errors on class instantiation.
	 */
	public function test_assets_class_instantiation(): void {
		$assets = new Assets();

		$assets->register_hooks();
		$assets->register_assets();
		$assets->enqueue_scripts();

		$this->assertTrue( true );
	}

	/**
	 * Tests registering one script and one style from existing assets.
	 */
	public function test_registration_helpers_register_existing_assets(): void {
		$assets        = new Assets();
		$script_method = new \ReflectionMethod( Assets::class, 'register_script' );
		$style_method  = new \ReflectionMethod( Assets::class, 'register_style' );
		$script_handle = 'onemedia-test-settings';
		$style_handle  = 'onemedia-test-main';

		$this->assertTrue( $script_method->invoke( $assets, $script_handle, 'settings' ) );
		$this->assertTrue( $style_method->invoke( $assets, $style_handle, 'main' ) );
		$this->assertTrue( wp_script_is( $script_handle, 'registered' ) );
		$this->assertTrue( wp_style_is( $style_handle, 'registered' ) );
	}

	/**
	 * Tests defer script filtering.
	 */
	public function test_defer_scripts_adds_defer_to_settings_script_only_once(): void {
		$assets = new Assets();
		$tag    = '<script src="settings.js"></script>';

		$this->assertSame( '<script defer src="settings.js"></script>', $assets->defer_scripts( $tag, Assets::SETTINGS_SCRIPT_HANDLE ), 'Settings script should be deferred' );
		$this->assertSame( '<script defer src="settings.js"></script>', $assets->defer_scripts( '<script defer src="settings.js"></script>', Assets::SETTINGS_SCRIPT_HANDLE ), 'Existing defer attribute should not be duplicated' );
		$this->assertSame( $tag, $assets->defer_scripts( $tag, Assets::MEDIA_FRAME_SCRIPT_HANDLE ), 'Unlisted handles should not be deferred' );
	}

	/**
	 * Tests asset registration failure branches for missing files.
	 */
	public function test_registration_helpers_return_false_for_missing_assets(): void {
		$assets   = new Assets();
		$dir_prop = new \ReflectionProperty( Assets::class, 'plugin_dir' );
		$dir_prop->setValue( $assets, sys_get_temp_dir() . '/onemedia-missing-assets/' );
		$script_method = new \ReflectionMethod( Assets::class, 'register_script' );
		$style_method  = new \ReflectionMethod( Assets::class, 'register_style' );

		$this->assertFalse( $script_method->invoke( $assets, 'missing-script', 'missing' ) );
		$this->assertFalse( $style_method->invoke( $assets, 'missing-style', 'missing' ) );
	}
}
