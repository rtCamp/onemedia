<?php
/**
 * Settings unit tests.
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
 * Tests for the Settings\Settings class.
 */
#[CoversClass( Settings::class )]
final class SettingsTest extends TestCase {
	/**
	 * @var \OneMedia\Modules\Settings\Settings
	 */
	private Settings $settings;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );
	}

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
	 * Tests no errors on class instantiation.
	 */
	public function test_register_hooks_adds_actions(): void {
		$settings = new Settings();

		$settings->register_hooks();
		$settings->register_settings();

		$this->assertTrue( true );
	}

	/**
	 * Tests register_settings registers the site type setting.
	 */
	public function test_register_settings_registers_site_type(): void {
		$settings = new Settings();

		$settings->register_settings();

		$this->assertSettingRegistered( Settings::OPTION_SITE_TYPE );

		$this->assertSettingNotRegistered( Settings::OPTION_CONSUMER_API_KEY );
		$this->assertSettingNotRegistered( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
	}

	/**
	 * Tests register_settings registers consumer options.
	 */
	public function test_register_settings_registers_consumer_options(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$settings = new Settings();
		$settings->register_settings();

		$this->assertSettingRegistered( Settings::OPTION_CONSUMER_API_KEY );
		$this->assertSettingRegistered( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
	}

	/**
	 * Tests register_settings registers governing options.
	 */
	public function test_register_settings_registers_governing_options(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$settings = new Settings();
		$settings->register_settings();

		$this->assertSettingRegistered( Settings::OPTION_GOVERNING_SHARED_SITES );
	}

	/**
	 * Tests get_site_type returns null when not set.
	 */
	public function test_get_site_type_returns_null_when_not_set(): void {
		delete_option( Settings::OPTION_SITE_TYPE );

		$this->assertNull( Settings::get_site_type() );
	}

	/**
	 * Tests get_site_type returns the stored value.
	 */
	public function test_get_site_type_returns_value_when_set(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->assertSame( Settings::SITE_TYPE_CONSUMER, Settings::get_site_type() );
	}

	/**
	 * Tests governing site detection.
	 */
	public function test_is_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );

		$this->assertTrue( Settings::is_governing_site() );
		$this->assertFalse( Settings::is_consumer_site() );
	}

	/**
	 * Tests consumer site detection.
	 */
	public function test_is_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$this->assertTrue( Settings::is_consumer_site() );
		$this->assertFalse( Settings::is_governing_site() );
	}

	/**
	 * Tests sanitize_shared_sites returns empty array for invalid input.
	 */
	public function test_sanitize_shared_sites_returns_empty_for_invalid_input(): void {
		$this->assertSame( [], Settings::sanitize_shared_sites( null ) );
		$this->assertSame( [], Settings::sanitize_shared_sites( [] ) );
		$this->assertSame( [], Settings::sanitize_shared_sites( 'string' ) );
	}

	/** Ensures sanitize_shared_sites handles valid data. */
	public function test_sanitize_shared_sites_with_valid_data(): void {
		$sanitized = Settings::sanitize_shared_sites(
			[
				[
					'id'      => ' site-id ',
					'name'    => ' Demo Site ',
					'url'     => 'https://example.com/path/',
					'api_key' => ' secret-key ',
				],
			]
		);

		$this->assertSame(
			[
				[
					'id'      => 'site-id',
					'name'    => 'Demo Site',
					'url'     => 'https://example.com/path/',
					'api_key' => 'secret-key',
				],
			],
			$sanitized
		);
	}

	/**
	 * Ensures sanitize_shared_sites returns an empty array for non-array input. */
	public function test_sanitize_shared_sites_returns_empty_for_non_array(): void {
		$this->assertSame( [], Settings::sanitize_shared_sites( 'not-an-array' ) );
	}

	/**
	 * Tests sanitize_shared_sites generates a UUID when id is missing.
	 */
	public function test_sanitize_shared_sites_generates_uuid_for_missing_id(): void {
		$sanitized = Settings::sanitize_shared_sites(
			[
				[
					'name' => 'Demo Site',
					'url'  => 'https://example.com',
				],
			]
		);

		$this->assertCount( 1, $sanitized );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/i', $sanitized[0]['id'] );
	}

	/** Ensures sanitize_shared_sites skips entries without a name or URL. */
	public function test_sanitize_shared_sites_skips_sites_without_name_or_url(): void {
		$sanitized = Settings::sanitize_shared_sites(
			[
				[
					'name' => 'Missing URL',
				],
			]
		);

		$this->assertSame( [], $sanitized );
	}

	/** Ensures get_shared_sites returns an empty array when not set. */
	public function test_get_shared_sites_returns_empty_when_not_set(): void {
		$this->assertSame( [], Settings::get_shared_sites() );
	}

	/** Ensures shared sites round-trip through storage. */
	public function test_set_and_get_shared_sites_roundtrip(): void {
		$sites = [
			[
				'id'      => 'brand-1',
				'name'    => 'Brand One',
				'url'     => 'https://brand-one.example',
				'api_key' => 'brand-one-key',
			],
		];

		$this->assertTrue( Settings::set_shared_sites( $sites ) );

		$stored_sites = get_option( Settings::OPTION_GOVERNING_SHARED_SITES, [] );
		$this->assertNotSame( 'brand-one-key', $stored_sites[0]['api_key'] );

		$this->assertSame(
			[
				'https://brand-one.example/' => [
					'api_key' => 'brand-one-key',
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example/',
				],
			],
			Settings::get_shared_sites()
		);
	}

	/** Ensures get_shared_site_by_url returns the matching site. */
	public function test_get_shared_site_by_url(): void {
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
			]
		);

		$this->assertSame( 'Brand One', Settings::get_shared_site_by_url( 'https://brand-one.example' )['name'] );
	}

	/**
	 * Tests get_shared_site_by_url returns null for unknown URLs.
	 */
	public function test_get_shared_site_by_url_returns_null_for_unknown(): void {
		$this->assertNull( Settings::get_shared_site_by_url( 'https://unknown.example' ) );
	}

	/**
	 * Tests parent site URL can be stored and retrieved.
	 */
	public function test_set_parent_site_url_and_get(): void {
		$this->assertTrue( Settings::set_parent_site_url( 'https://governing.example/' ) );
		$this->assertSame( 'https://governing.example/', Settings::get_parent_site_url() );
	}

	/**
	 * Tests get_api_key generates and caches a key when none is set.
	 */
	public function test_get_api_key_generates_if_not_set(): void {
		$api_key = Settings::get_api_key();

		$this->assertNotSame( '', $api_key );
		$this->assertNotSame( $api_key, get_option( Settings::OPTION_CONSUMER_API_KEY, '' ) );
		$this->assertSame( $api_key, Settings::get_api_key() );
	}

	/**
	 * Tests regenerate_api_key returns a different key each time.
	 */
	public function test_regenerate_api_key_returns_new_key(): void {
		$initial_api_key = Settings::get_api_key();
		$new_api_key     = Settings::regenerate_api_key();

		$this->assertNotSame( '', $new_api_key );
		$this->assertNotSame( $initial_api_key, $new_api_key );
		$this->assertSame( $new_api_key, Settings::get_api_key() );
	}

	/**
	 * Tests on_site_type_change generates an API key when switching to consumer.
	 */
	public function test_on_site_type_change_generates_api_key_for_consumer(): void {
		$settings = new Settings();
		$settings->on_site_type_change( '', Settings::SITE_TYPE_CONSUMER );

		$stored_api_key = get_option( Settings::OPTION_CONSUMER_API_KEY, '' );

		$this->assertIsString( $stored_api_key );
		$this->assertNotSame( '', $stored_api_key );
		$this->assertSame( Settings::get_api_key(), Encryptor::decrypt( $stored_api_key ) );
	}

	/**
	 * Tests the site type sanitize callback rejects invalid values.
	 */
	public function test_site_type_sanitize_callback_rejects_invalid_value(): void {
		$settings = new Settings();
		$settings->register_settings();

		$invalid = apply_filters( 'sanitize_option_' . Settings::OPTION_SITE_TYPE, 'invalid-value' );
		$this->assertSame( '', $invalid, 'Invalid site type should sanitize to empty string.' );

		$valid = apply_filters( 'sanitize_option_' . Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$this->assertSame( Settings::SITE_TYPE_CONSUMER, $valid, 'Valid consumer site type should pass through.' );
	}

	/**
	 * Tests the consumer parent site URL sanitize callback strips trailing slashes.
	 */
	public function test_consumer_parent_site_url_sanitize_callback_strips_trailing_slash(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$settings = new Settings();
		$settings->register_settings();

		$stripped = apply_filters( 'sanitize_option_' . Settings::OPTION_CONSUMER_PARENT_SITE_URL, 'https://example.com/' );
		$this->assertSame( 'https://example.com', $stripped, 'Trailing slash should be stripped.' );

		$non_string = apply_filters( 'sanitize_option_' . Settings::OPTION_CONSUMER_PARENT_SITE_URL, 12345 );
		$this->assertNull( $non_string, 'Non-string input should return null.' );
	}

	/**
	 * Tests on_site_type_change skips API key generation for non-consumer site types.
	 */
	public function test_on_site_type_change_skips_api_key_generation_for_non_consumer(): void {
		delete_option( Settings::OPTION_CONSUMER_API_KEY );

		$settings = new Settings();
		$settings->on_site_type_change( '', Settings::SITE_TYPE_GOVERNING );

		$this->assertSame( '', get_option( Settings::OPTION_CONSUMER_API_KEY, '' ), 'API key should not be generated for non-consumer site type.' );
	}

	/**
	 * Tests sanitize_shared_sites skips entries that are not arrays.
	 */
	public function test_sanitize_shared_sites_skips_non_array_entries(): void {
		$input = [
			'string-entry',
			42,
			null,
			[
				'name'    => 'Valid',
				'url'     => 'https://valid.example',
				'api_key' => 'key',
			],
		];

		$result = Settings::sanitize_shared_sites( $input );

		$this->assertCount( 1, $result, 'Only the valid array entry should survive.' );
		$this->assertSame( 'Valid', $result[0]['name'] );
		$this->assertSame( 'https://valid.example/', $result[0]['url'] );
	}

	/**
	 * Tests get_shared_sites filters out entries with an empty URL.
	 */
	public function test_get_shared_sites_filters_out_entries_with_empty_url(): void {
		update_option(
			Settings::OPTION_GOVERNING_SHARED_SITES,
			[
				[
					'id'      => 'a',
					'name'    => 'A',
					'url'     => 'https://a.example',
					'api_key' => '',
				],
				[
					'id'      => 'b',
					'name'    => 'B',
					'url'     => '',
					'api_key' => '',
				],
			]
		);

		$result = Settings::get_shared_sites();

		$this->assertCount( 1, $result, 'Only the entry with non-empty url should survive.' );
		$this->assertArrayHasKey( 'https://a.example/', $result );
		$this->assertSame( 'A', $result['https://a.example/']['name'] );
	}

	/**
	 * Tests set_shared_sites skips entries with an empty api_key or url.
	 */
	public function test_set_shared_sites_skips_entries_with_empty_api_key_or_url(): void {
		$sites = [
			[
				'id'      => 'a',
				'name'    => 'A',
				'url'     => '',
				'api_key' => 'key',
			],
			[
				'id'      => 'b',
				'name'    => 'B',
				'url'     => 'https://b.example',
				'api_key' => '',
			],
		];

		$result = Settings::set_shared_sites( $sites );

		$this->assertTrue( $result, 'update_option should succeed.' );
		$stored = get_option( Settings::OPTION_GOVERNING_SHARED_SITES, [] );
		$this->assertCount( 2, $stored, 'Both entries should be persisted.' );
		$this->assertSame( '', $stored[0]['url'], 'First entry url should remain empty (encryption skipped).' );
		$this->assertSame( '', $stored[1]['api_key'], 'Second entry api_key should remain empty (encryption skipped).' );
	}

	/**
	 * Tests get_brand_site_api_key across all branches.
	 */
	public function test_get_brand_site_api_key_covers_all_branches(): void {
		// Phase 1: not governing -> empty string.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$this->assertSame( '', Settings::get_brand_site_api_key( 'https://brand-one.example' ) );

		// Phase 2: governing + known site -> returns key.
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
			]
		);
		$this->assertSame( 'brand-one-key', Settings::get_brand_site_api_key( 'https://brand-one.example' ) );

		// Phase 3: governing + unknown site -> empty string.
		$this->assertSame( '', Settings::get_brand_site_api_key( 'https://unknown.example' ) );
	}

	/**
	 * Tests get_sitename_by_url on a governing site.
	 */
	public function test_get_sitename_by_url_on_governing_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING );
		Settings::set_shared_sites(
			[
				[
					'id'      => 'brand-1',
					'name'    => 'Brand One',
					'url'     => 'https://brand-one.example',
					'api_key' => 'brand-one-key',
				],
			]
		);

		// Phase 1: known URL -> returns name.
		$this->assertSame( 'Brand One', Settings::get_sitename_by_url( 'https://brand-one.example' ) );

		// Phase 2: unknown URL -> empty string.
		$this->assertSame( '', Settings::get_sitename_by_url( 'https://unknown.example' ) );
	}

	/**
	 * Tests get_sitename_by_url on a consumer site.
	 */
	public function test_get_sitename_by_url_on_consumer_site(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		// Phase 1: valid URL with host -> derives name from hostname.
		$this->assertSame( 'My Site', Settings::get_sitename_by_url( 'https://my-site.example.com' ) );

		// Phase 2: invalid URL (no host) -> empty string.
		$this->assertSame( '', Settings::get_sitename_by_url( 'not-a-valid-url' ) );
	}

	/**
	 * Tests get_brand_sites_synced_media returns the stored option.
	 */
	public function test_get_brand_sites_synced_media(): void {
		// Phase 1: not set -> empty array.
		delete_option( Settings::BRAND_SITES_SYNCED_MEDIA );
		$this->assertSame( [], Settings::get_brand_sites_synced_media() );

		// Phase 2: stored value -> returns it.
		$data = [ 'https://brand.example/' => [ 'attachment_1' => 42 ] ];
		update_option( Settings::BRAND_SITES_SYNCED_MEDIA, $data );
		$this->assertSame( $data, Settings::get_brand_sites_synced_media() );
	}

	/**
	 * Ensures a setting is registered.
	 *
	 * @param string $setting_name Setting name.
	 */
	private function assertSettingRegistered( string $setting_name ): void {
		$registered_settings = get_registered_settings();

		$this->assertArrayHasKey( $setting_name, $registered_settings );

		global $new_allowed_options;

		$this->assertContains( $setting_name, $new_allowed_options[ Settings::SETTING_GROUP ] ?? [] );
	}

	/**
	 * Asserts that a setting is not registered.
	 *
	 * @param string $setting_name Setting name.
	 * @param string $message      Optional failure message.
	 */
	private function assertSettingNotRegistered( string $setting_name, string $message = '' ): void {
		$registered_settings = get_registered_settings();

		$this->assertArrayNotHasKey( $setting_name, $registered_settings, $message );
	}
}
