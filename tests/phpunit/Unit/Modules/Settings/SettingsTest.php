<?php
/**
 * Tests for settings helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\Settings
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Settings;

use OneMedia\Encryptor;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Settings::class )]
final class SettingsTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );

		parent::tearDown();
	}

	/**
	 * Tests hook registration.
	 */
	public function test_register_hooks_registers_settings_hooks(): void {
		$settings = new Settings();

		$settings->register_hooks();

		$this->assertSame( 10, has_action( 'admin_init', [ $settings, 'register_settings' ] ) );
		$this->assertSame( 10, has_action( 'rest_api_init', [ $settings, 'register_settings' ] ) );
		$this->assertSame( 10, has_action( 'update_option_' . Settings::OPTION_SITE_TYPE, [ $settings, 'on_site_type_change' ] ) );
	}

	/**
	 * Tests setting registration for both site modes.
	 */
	public function test_register_settings_registers_mode_specific_options(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );

		( new Settings() )->register_settings();

		$registered_settings = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION_SITE_TYPE, $registered_settings );
		$this->assertArrayHasKey( Settings::OPTION_CONSUMER_API_KEY, $registered_settings );
		$this->assertArrayHasKey( Settings::OPTION_CONSUMER_PARENT_SITE_URL, $registered_settings );
		$this->assertArrayNotHasKey( Settings::OPTION_GOVERNING_SHARED_SITES, $registered_settings );
		$this->assertSame( Settings::SITE_TYPE_CONSUMER, sanitize_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER ) );
		$this->assertSame( '', sanitize_option( Settings::OPTION_SITE_TYPE, 'invalid' ) );
		$this->assertSame( 'https://example.com/path', sanitize_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://example.com/path/' ) );
		$this->assertNull( sanitize_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL, [] ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		( new Settings() )->register_settings();
		$registered_settings = get_registered_settings();

		$this->assertArrayHasKey( Settings::OPTION_GOVERNING_SHARED_SITES, $registered_settings );
	}

	/**
	 * Tests shared site sanitization.
	 */
	public function test_sanitize_shared_sites_keeps_valid_sites_and_skips_invalid_entries(): void {
		$this->assertSame( [], Settings::sanitize_shared_sites( 'invalid' ) );
		$this->assertSame( [], Settings::sanitize_shared_sites( [] ) );

		$sanitized = Settings::sanitize_shared_sites(
			[
				'not-array',
				[
					'name'    => '',
					'url'     => 'https://missing-name.test',
					'api_key' => 'key',
				],
				[
					'id'      => '',
					'name'    => '<b>Brand Site</b>',
					'url'     => 'https://brand.test',
					'api_key' => 'token',
				],
			]
		);

		$this->assertCount( 1, $sanitized );
		$this->assertNotSame( '', $sanitized[0]['id'] );
		$this->assertSame( 'Brand Site', $sanitized[0]['name'] );
		$this->assertSame( 'https://brand.test/', $sanitized[0]['url'] );
		$this->assertSame( 'token', $sanitized[0]['api_key'] );
	}

	/**
	 * Tests shared site getters and setters.
	 */
	public function test_shared_site_helpers_encrypt_store_and_lookup_sites(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertTrue(
			Settings::set_shared_sites(
				[
					[
						'id'      => 'site-1',
						'name'    => 'Brand One',
						'url'     => 'https://brand-one.test',
						'api_key' => 'plain-key',
					],
					[
						'id'   => 'site-2',
						'name' => 'Missing Key',
						'url'  => '',
					],
				]
			)
		);

		$stored = get_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		$this->assertIsArray( $stored );
		$this->assertNotSame( 'plain-key', $stored[0]['api_key'] );

		$sites = Settings::get_shared_sites();

		$this->assertSame( 'plain-key', $sites['https://brand-one.test/']['api_key'] );
		$this->assertSame( 'Brand One', Settings::get_shared_site_by_url( 'https://brand-one.test' )['name'] );
		$this->assertNull( Settings::get_shared_site_by_url( 'https://missing.test' ) );
		$this->assertSame( 'plain-key', Settings::get_brand_site_api_key( 'https://brand-one.test/' ) );
		$this->assertSame( '', Settings::get_brand_site_api_key( 'https://missing.test/' ) );
		$this->assertSame( '', Settings::get_brand_site_api_key( '' ) );
	}

	/**
	 * Tests site type helpers.
	 */
	public function test_site_type_helpers_read_current_site_mode(): void {
		$this->assertNull( Settings::get_site_type() );
		$this->assertFalse( Settings::is_governing_site() );
		$this->assertFalse( Settings::is_consumer_site() );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );

		$this->assertSame( Settings::SITE_TYPE_CONSUMER, Settings::get_site_type() );
		$this->assertTrue( Settings::is_consumer_site() );
		$this->assertFalse( Settings::is_governing_site() );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );

		$this->assertTrue( Settings::is_governing_site() );
		$this->assertFalse( Settings::is_consumer_site() );
	}

	/**
	 * Tests API key helpers.
	 */
	public function test_api_key_helpers_generate_decrypt_and_regenerate_keys(): void {
		$generated_key = Settings::get_api_key();
		$stored_key    = get_option( Settings::OPTION_CONSUMER_API_KEY );

		$this->assertNotSame( '', $generated_key );
		$this->assertIsString( $stored_key );
		$this->assertSame( $generated_key, Encryptor::decrypt( $stored_key ) );
		$this->assertSame( $generated_key, Settings::get_api_key() );

		$regenerated_key = Settings::regenerate_api_key();

		$this->assertNotSame( '', $regenerated_key );
		$this->assertNotSame( $generated_key, $regenerated_key );
	}

	/**
	 * Tests site type change side effect.
	 */
	public function test_on_site_type_change_generates_api_key_for_consumer_sites_only(): void {
		$settings = new Settings();

		$settings->on_site_type_change( '', Settings::SITE_TYPE_GOVERNING );
		$this->assertSame( '', get_option( Settings::OPTION_CONSUMER_API_KEY, '' ) );

		$settings->on_site_type_change( '', Settings::SITE_TYPE_CONSUMER );
		$this->assertNotSame( '', get_option( Settings::OPTION_CONSUMER_API_KEY, '' ) );
	}

	/**
	 * Tests parent site URL helpers.
	 */
	public function test_parent_site_url_helpers_store_trailing_slash_url(): void {
		$this->assertNull( Settings::get_parent_site_url() );

		$this->assertTrue( Settings::set_parent_site_url( 'https://parent.test/path' ) );
		$this->assertSame( 'https://parent.test/path/', Settings::get_parent_site_url() );
	}

	/**
	 * Tests site name resolution.
	 */
	public function test_get_sitename_by_url_uses_shared_site_or_host_name(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Settings::set_shared_sites(
			[
				[
					'id'      => 'site-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.test',
					'api_key' => 'plain-key',
				],
			]
		);

		$this->assertSame( 'Brand One', Settings::get_sitename_by_url( 'https://brand-one.test/' ) );
		$this->assertSame( '', Settings::get_sitename_by_url( 'https://missing.test/' ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );

		$this->assertSame( 'Brand One', Settings::get_sitename_by_url( 'https://brand-one.example.com' ) );
		$this->assertSame( '', Settings::get_sitename_by_url( 'not-a-url' ) );
	}

	/**
	 * Tests synced media option getter.
	 */
	public function test_get_brand_sites_synced_media_returns_option_array(): void {
		$this->assertSame( [], Settings::get_brand_sites_synced_media() );

		update_option( Settings::BRAND_SITES_SYNCED_MEDIA, [ 10 => [ 'https://brand.test' => 20 ] ], false );

		$this->assertSame( [ 10 => [ 'https://brand.test' => 20 ] ], Settings::get_brand_sites_synced_media() );
	}
}
