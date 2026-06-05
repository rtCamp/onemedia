<?php
/**
 * Tests for the Rest\Basic_Options_Controller class.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Rest;

use OneMedia\Modules\Rest\Basic_Options_Controller;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Tests for the Rest\Basic_Options_Controller class.
 */
#[CoversClass( Basic_Options_Controller::class )]
final class BasicOptionsControllerTest extends TestCase {
	/**
	 * Controller under test.
	 *
	 * @var \OneMedia\Modules\Rest\Basic_Options_Controller
	 */
	private Basic_Options_Controller $controller;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->controller = new Basic_Options_Controller();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );

		parent::tearDown();
	}

	/**
	 * Tests no errors on hook registration.
	 */
	public function test_register_hooks_adds_rest_api_init_action(): void {
		$this->controller->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests get_site_type returns the current option value.
	 */
	public function test_get_site_type_returns_current_option(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );

		$response = $this->controller->get_site_type();

		$this->assertSame(
			[
				'success'   => true,
				'site_type' => Settings::SITE_TYPE_CONSUMER,
			],
			$response->get_data()
		);
	}

	/**
	 * Tests set_site_type updates the option and returns the saved value.
	 */
	public function test_set_site_type_updates_option_and_returns_response(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/site-type' );
		$request->set_param( 'site_type', Settings::SITE_TYPE_GOVERNING );

		$response = $this->controller->set_site_type( $request );

		$this->assertSame(
			[
				'success'   => true,
				'site_type' => Settings::SITE_TYPE_GOVERNING,
			],
			$response->get_data()
		);
		$this->assertSame( Settings::SITE_TYPE_GOVERNING, get_option( Settings::OPTION_SITE_TYPE ) );
	}

	/**
	 * Tests get_shared_sites returns array values from the stored shared sites.
	 */
	public function test_get_shared_sites_returns_array_values(): void {
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

		$response = $this->controller->get_shared_sites();

		$this->assertSame(
			[
				'success'      => true,
				'shared_sites' => [
					[
						'api_key' => 'brand-one-key',
						'id'      => 'brand-1',
						'name'    => 'Brand One',
						'url'     => 'https://brand-one.example/',
					],
				],
			],
			$response->get_data()
		);
	}

	/**
	 * Tests set_shared_sites rejects duplicate URLs.
	 */
	public function test_set_shared_sites_returns_error_for_duplicate_urls(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/shared-sites' );
		$request->set_body(
			wp_json_encode(
				[
					'shared_sites' => [
						[
							'name' => 'Brand One',
							'url'  => 'https://brand-one.example',
						],
						[
							'name' => 'Brand One Duplicate',
							'url'  => 'https://brand-one.example',
						],
					],
				]
			)
		);

		$result = $this->controller->set_shared_sites( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_site_url', $result->get_error_code() );
		$this->assertSame( [ 'status' => 400 ], $result->get_error_data() );
	}

	/**
	 * Tests set_shared_sites stores sites and generates missing IDs.
	 */
	public function test_set_shared_sites_stores_sites_and_generates_missing_ids(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/shared-sites' );
		$request->set_body(
			wp_json_encode(
				[
					'shared_sites' => [
						[
							'name'    => 'Brand One',
							'url'     => 'https://brand-one.example',
							'api_key' => 'brand-one-key',
						],
					],
				]
			)
		);

		$response = $this->controller->set_shared_sites( $request );
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertCount( 1, $data['shared_sites'] );
		$this->assertMatchesRegularExpression( '/^[0-9a-f-]{36}$/i', $data['shared_sites'][0]['id'] );
		$this->assertSame( 'Brand One', Settings::get_shared_site_by_url( 'https://brand-one.example' )['name'] );
	}

	/**
	 * Tests health_check returns the expected success payload.
	 */
	public function test_health_check_returns_success_response(): void {
		$response = $this->controller->health_check();

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Health check passed successfully.',
			],
			$response->get_data()
		);
	}

	/**
	 * Tests get_governing_site returns the stored parent site URL.
	 */
	public function test_get_governing_site_returns_parent_site_url(): void {
		Settings::set_parent_site_url( 'https://governing.example' );

		$response = $this->controller->get_governing_site();

		$this->assertSame(
			[
				'success'            => true,
				'governing_site_url' => 'https://governing.example/',
			],
			$response->get_data()
		);
	}

	/**
	 * Tests remove_governing_site deletes the stored parent site URL.
	 */
	public function test_remove_governing_site_deletes_parent_site_url(): void {
		Settings::set_parent_site_url( 'https://governing.example' );

		$response = $this->controller->remove_governing_site();

		$this->assertSame(
			[
				'success' => true,
				'message' => 'Governing site removed successfully.',
			],
			$response->get_data()
		);
		$this->assertFalse( get_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL ) );
	}

	/**
	 * Tests get_secret_key returns a generated key.
	 */
	public function test_get_secret_key_returns_current_key(): void {
		$response = $this->controller->get_secret_key();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertIsString( $data['secret_key'] );
		$this->assertNotSame( '', $data['secret_key'] );
		$this->assertSame( Settings::get_api_key(), $data['secret_key'] );
	}

	/**
	 * Tests regenerate_secret_key returns a new key.
	 */
	public function test_regenerate_secret_key_returns_new_key(): void {
		$old_key  = Settings::get_api_key();
		$response = $this->controller->regenerate_secret_key();
		$data     = $response->get_data();

		$this->assertTrue( $data['success'] );
		$this->assertSame( 'Secret key regenerated successfully.', $data['message'] );
		$this->assertNotSame( $old_key, $data['secret_key'] );
		$this->assertSame( Settings::get_api_key(), $data['secret_key'] );
	}

	/**
	 * Tests check_sites_connected returns an error for invalid attachment IDs.
	 */
	public function test_check_sites_connected_returns_error_for_invalid_attachment(): void {
		$request = new WP_REST_Request( 'POST', '/onemedia/v1/check-sites-connected' );
		$request->set_param( 'attachment_id', 0 );

		$result = $this->controller->check_sites_connected( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );
		$this->assertSame(
			[
				'status'  => 400,
				'success' => false,
			],
			$result->get_error_data()
		);
	}

	/**
	 * Tests fetch_multisite_type returns single on a non-multisite install.
	 */
	public function test_fetch_multisite_type_returns_single(): void {
		$this->assertSame( 'single', $this->controller->fetch_multisite_type() );
	}

	/**
	 * Tests get_multisite_type returns the expected response payload.
	 */
	public function test_get_multisite_type_returns_response_payload(): void {
		$response = $this->controller->get_multisite_type();

		$this->assertSame(
			[
				'status'         => 500,
				'multisite_type' => 'single',
				'success'        => true,
			],
			$response->get_data()
		);
	}
}
