<?php
/**
 * Tests for basic options REST controller.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Rest;

require_once dirname( __DIR__, 3 ) . '/includes/basic-options-controller-function-shims.php';

use OneMedia\Modules\Rest\Abstract_REST_Controller;
use OneMedia\Modules\Rest\Basic_Options_Controller;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

	/**
	 * Class BasicOptionsControllerTest
	 */
	#[CoversClass( Basic_Options_Controller::class )]
	#[CoversClass( Abstract_REST_Controller::class )]
final class BasicOptionsControllerTest extends TestCase {
	/**
	 * Invoke a private controller method.
	 *
	 * @param object|class-string $target      Target object or class.
	 * @param string              $method_name Method name.
	 * @param array<mixed>        $args        Arguments.
	 */
	private function invoke_private_method( object|string $target, string $method_name, array $args = [] ): mixed {
		$method = new \ReflectionMethod( $target, $method_name );

		return $method->invokeArgs( is_object( $target ) ? $target : null, $args );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );
		unset( $GLOBALS['onemedia_test_is_multisite'], $GLOBALS['onemedia_test_is_subdomain_install'] );

		parent::tearDown();
	}

	/**
	 * Tests no errors on class lifecycle methods.
	 */
	public function test_class_instantiation(): void {
		$controller = new Basic_Options_Controller();

		$controller->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests route registration exposes the expected endpoints and argument schemas.
	 */
	public function test_register_routes_registers_expected_rest_endpoints(): void {
		$controller = new Basic_Options_Controller();

		$controller->register_hooks();
		do_action( 'rest_api_init' );

		$routes                = rest_get_server()->get_routes();
		$site_type_routes      = $routes[ '/' . Abstract_REST_Controller::NAMESPACE . '/site-type' ];
		$shared_sites_routes   = $routes[ '/' . Abstract_REST_Controller::NAMESPACE . '/shared-sites' ];
		$health_check_routes   = $routes[ '/' . Abstract_REST_Controller::NAMESPACE . '/health-check' ];
		$secret_key_routes     = $routes[ '/' . Abstract_REST_Controller::NAMESPACE . '/secret-key' ];
		$check_sites_routes    = $routes[ '/' . Abstract_REST_Controller::NAMESPACE . '/check-sites-connected' ];
		$governing_site_routes = $routes[ '/' . Abstract_REST_Controller::NAMESPACE . '/governing-site' ];

		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/site-type', $routes );
		$this->assertGreaterThanOrEqual( 2, count( $site_type_routes ) );
		$this->assertSame( 'string', $site_type_routes[1]['args']['site_type']['type'] );

		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/shared-sites', $routes );
		$this->assertSame( 'array', $shared_sites_routes[1]['args']['shared_sites']['type'] );

		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/health-check', $routes );
		$this->assertIsArray( $health_check_routes[0]['permission_callback'] );
		$this->assertInstanceOf( Basic_Options_Controller::class, $health_check_routes[0]['permission_callback'][0] );
		$this->assertSame( 'check_api_permissions', $health_check_routes[0]['permission_callback'][1] );

		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/secret-key', $routes );
		$this->assertGreaterThanOrEqual( 2, count( $secret_key_routes ) );

		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/check-sites-connected', $routes );
		$this->assertSame( 'integer', $check_sites_routes[0]['args']['attachment_id']['type'] );

		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/multisite-type', $routes );
		$this->assertArrayHasKey( '/' . Abstract_REST_Controller::NAMESPACE . '/governing-site', $routes );
		$this->assertGreaterThanOrEqual( 2, count( $governing_site_routes ) );
	}

	/**
	 * Tests simple option endpoints.
	 */
	public function test_site_type_and_shared_site_endpoints(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/site-type' );
		$request->set_param( 'site_type', Settings::SITE_TYPE_GOVERNING );

		$this->assertSame( Settings::SITE_TYPE_GOVERNING, $controller->set_site_type( $request )->get_data()['site_type'] );
		$this->assertSame( Settings::SITE_TYPE_GOVERNING, $controller->get_site_type()->get_data()['site_type'] );

		$shared_request = new WP_REST_Request( 'POST', '/onemedia/v1/shared-sites' );
		$shared_request->set_body(
			wp_json_encode(
				[
					'shared_sites' => [
						[
							'name'    => 'Brand',
							'url'     => 'https://brand.test',
							'api_key' => 'key',
						],
					],
				]
			)
		);

		$response = $controller->set_shared_sites( $shared_request )->get_data();

		$this->assertTrue( $response['success'] );
		$this->assertCount( 1, $response['shared_sites'] );
		$this->assertNotEmpty( $response['shared_sites'][0]['id'] );
		$this->assertCount( 1, $controller->get_shared_sites()->get_data()['shared_sites'] );
	}

	/**
	 * Tests duplicate shared-site validation.
	 */
	public function test_set_shared_sites_rejects_duplicate_urls(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/shared-sites' );
		$request->set_body(
			wp_json_encode(
				[
					'shared_sites' => [
						[ 'url' => 'https://brand.test' ],
						[ 'url' => 'https://brand.test' ],
					],
				]
			)
		);

		$result = $controller->set_shared_sites( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'duplicate_site_url', $result->get_error_code() );
	}

	/**
	 * Tests shared sites keep a pre-existing site ID instead of generating a new one.
	 */
	public function test_set_shared_sites_preserves_existing_site_ids(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/shared-sites' );
		$request->set_body(
			wp_json_encode(
				[
					'shared_sites' => [
						[
							'id'      => 'existing-site-id',
							'name'    => 'Brand',
							'url'     => 'https://brand.test',
							'api_key' => 'key',
						],
					],
				]
			)
		);

		$response     = $controller->set_shared_sites( $request )->get_data();
		$stored_sites = Settings::get_shared_sites();

		$this->assertSame( 'existing-site-id', $response['shared_sites'][0]['id'] );
		$this->assertArrayHasKey( 'https://brand.test/', $stored_sites );
		$this->assertSame( 'existing-site-id', $stored_sites['https://brand.test/']['id'] );
	}

	/**
	 * Tests basic response endpoints.
	 */
	public function test_basic_response_endpoints(): void {
		$controller = new Basic_Options_Controller();

		$this->assertTrue( $controller->health_check()->get_data()['success'] );
		$this->assertNotSame( '', $controller->get_secret_key()->get_data()['secret_key'] );
		$this->assertNotSame( '', $controller->regenerate_secret_key()->get_data()['secret_key'] );
		$this->assertSame( 'single', $controller->fetch_multisite_type() );
		$this->assertSame( 'single', $controller->get_multisite_type()->get_data()['multisite_type'] );

		Settings::set_parent_site_url( 'https://parent.test' );

		$this->assertSame( 'https://parent.test/', $controller->get_governing_site()->get_data()['governing_site_url'] );
		$this->assertTrue( $controller->remove_governing_site()->get_data()['success'] );
		$this->assertNull( Settings::get_parent_site_url() );
	}

	/**
	 * Tests connected-sites validation.
	 */
	public function test_check_sites_connected_validates_attachment_id(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'POST', '/onemedia/v1/check-sites-connected' );
		$request->set_param( 'attachment_id', 0 );

		$result = $controller->check_sites_connected( $request );

		$this->assertWPError( $result );
		$this->assertSame( 'invalid_data', $result->get_error_code() );

		$attachment_id = self::factory()->attachment->create();
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		$request->set_param( 'attachment_id', $attachment_id );

		$this->assertTrue( $controller->check_sites_connected( $request )->get_data()['success'] );
	}

	/**
	 * Tests same-origin and missing-origin requests fall back to manage_options capability checks.
	 */
	public function test_check_api_permissions_uses_manage_options_for_same_origin_requests(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'GET', '/onemedia/v1/shared-sites' );

		$this->assertFalse( $controller->check_api_permissions( $request ) );

		$admin_user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin_user_id );

		$this->assertTrue( $controller->check_api_permissions( $request ) );

		$request->set_header( 'Origin', get_site_url() );
		$this->assertTrue( $controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests remote requests fail without a valid OneMedia token.
	 */
	public function test_check_api_permissions_rejects_missing_or_invalid_remote_tokens(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'GET', '/onemedia/v1/shared-sites' );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'shared-token',
				],
			]
		);

		$request->set_header( 'Origin', 'https://brand.test' );
		$this->assertFalse( $controller->check_api_permissions( $request ) );

		$request->set_header( 'X-OneMedia-Token', 'wrong-token' );
		$this->assertFalse( $controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests governing-site remote requests succeed with a valid mapped token.
	 */
	public function test_check_api_permissions_allows_governing_site_remote_requests_with_valid_token(): void {
		$controller = new Basic_Options_Controller();
		$request    = new WP_REST_Request( 'GET', '/onemedia/v1/shared-sites' );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'shared-token',
				],
			]
		);

		$request->set_header( 'Origin', 'https://brand.test' );
		$request->set_header( 'X-OneMedia-Token', 'shared-token' );

		$this->assertTrue( $controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests consumer-site permission handling for health checks and governing-site matching.
	 */
	public function test_check_api_permissions_handles_consumer_health_check_and_parent_matching(): void {
		$controller = new Basic_Options_Controller();
		$api_key    = '';

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$api_key = Settings::get_api_key();

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/shared-sites' );
		$request->set_header( 'Origin', 'https://governing.test' );
		$request->set_header( 'X-OneMedia-Token', $api_key );

		$this->assertFalse( $controller->check_api_permissions( $request ) );

		Settings::set_parent_site_url( 'https://governing.test' );
		$this->assertTrue( $controller->check_api_permissions( $request ) );

		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		$health_request = new WP_REST_Request( 'GET', '/' . Abstract_REST_Controller::NAMESPACE . '/health-check' );
		$health_request->set_header( 'Origin', 'https://governing.test' );
		$health_request->set_header( 'X-OneMedia-Token', $api_key );

		$this->assertTrue( $controller->check_api_permissions( $health_request ) );
		$this->assertSame( 'https://governing.test/', Settings::get_parent_site_url() );
	}

	/**
	 * Tests private helper branches for host matching and stored API key lookup.
	 */
	public function test_abstract_rest_controller_private_helpers_cover_host_and_key_lookup_edges(): void {
		$controller = new Basic_Options_Controller();

		$this->assertFalse( $this->invoke_private_method( $controller, 'is_url_from_host', [ 'not-a-url', 'example.test', null ] ) );
		$this->assertFalse( $this->invoke_private_method( $controller, 'is_url_from_host', [ 'https://example.test:8080', 'example.test', 9090 ] ) );
		$this->assertTrue( $this->invoke_private_method( $controller, 'is_url_from_host', [ 'https://example.test', 'example.test', null ] ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_GOVERNING, false );
		$this->assertSame( '', $this->invoke_private_method( $controller, 'get_stored_api_key', [ null ] ) );

		Settings::set_shared_sites(
			[
				[
					'name'    => 'Brand',
					'url'     => 'https://brand.test',
					'api_key' => 'shared-token',
				],
			]
		);
		$this->assertSame( 'shared-token', $this->invoke_private_method( $controller, 'get_stored_api_key', [ 'https://brand.test/' ] ) );

		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER, false );
		$this->assertSame( Settings::get_api_key(), $this->invoke_private_method( $controller, 'get_stored_api_key', [ null ] ) );
	}

	/**
	 * Tests multisite-type detection for subdirectory and subdomain installs.
	 */
	public function test_fetch_multisite_type_covers_multisite_variants(): void {
		$controller = new Basic_Options_Controller();

		$GLOBALS['onemedia_test_is_multisite']         = true;
		$GLOBALS['onemedia_test_is_subdomain_install'] = false;

		$this->assertSame( 'subdirectory', $controller->fetch_multisite_type() );

		$GLOBALS['onemedia_test_is_subdomain_install'] = true;

		$this->assertSame( 'subdomain', $controller->fetch_multisite_type() );
	}
}
