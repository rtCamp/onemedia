<?php
/**
 * Tests for basic options REST controller.
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
 * @covers \OneMedia\Modules\Rest\Basic_Options_Controller
 * @covers \OneMedia\Modules\Rest\Abstract_REST_Controller
 */
#[CoversClass( Basic_Options_Controller::class )]
final class BasicOptionsControllerTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		delete_option( Settings::OPTION_SITE_TYPE );
		delete_option( Settings::OPTION_CONSUMER_PARENT_SITE_URL );
		delete_option( Settings::OPTION_GOVERNING_SHARED_SITES );
		delete_option( Settings::OPTION_CONSUMER_API_KEY );

		parent::tearDown();
	}

	/**
	 * Tests route hook registration.
	 */
	public function test_register_hooks_adds_rest_api_init_action(): void {
		$controller = new Basic_Options_Controller();

		$controller->register_hooks();

		$this->assertSame( 10, has_action( 'rest_api_init', [ $controller, 'register_routes' ] ) );
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
}
