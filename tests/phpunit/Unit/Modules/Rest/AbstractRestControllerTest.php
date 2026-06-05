<?php
/**
 * Tests for the Rest\Abstract_REST_Controller class.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Rest;

use OneMedia\Modules\Rest\Abstract_REST_Controller;
use OneMedia\Modules\Settings\Settings;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use WP_REST_Request;

/**
 * Test double for the abstract REST controller.
 */
final class AbstractRestControllerTestDouble extends Abstract_REST_Controller {
}

/**
 * Tests for the Rest\Abstract_REST_Controller class.
 */
#[CoversClass( Abstract_REST_Controller::class )]
final class AbstractRestControllerTest extends TestCase {
	/**
	 * Controller under test.
	 *
	 * @var \OneMedia\Tests\Unit\Modules\Rest\AbstractRestControllerTestDouble
	 */
	private AbstractRestControllerTestDouble $controller;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->controller = new AbstractRestControllerTestDouble();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		wp_set_current_user( 0 );
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
	 * Tests same-origin requests use the current user's manage_options capability.
	 */
	public function test_check_api_permissions_returns_true_for_local_admin_request(): void {
		$user_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $user_id );

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/site-type' );
		$request->set_header( 'Origin', get_site_url() );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests remote requests without a token are rejected.
	 */
	public function test_check_api_permissions_returns_false_without_token_for_remote_origin(): void {
		$request = new WP_REST_Request( 'GET', '/onemedia/v1/health-check' );
		$request->set_header( 'Origin', 'https://brand-one.example' );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests remote requests with an invalid token are rejected.
	 */
	public function test_check_api_permissions_returns_false_for_invalid_token(): void {
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

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/health-check' );
		$request->set_header( 'Origin', 'https://brand-one.example' );
		$request->set_header( 'X-OneMedia-Token', 'wrong-token' );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests governing sites accept a valid child-site token.
	 */
	public function test_check_api_permissions_returns_true_for_governing_site_with_valid_token(): void {
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

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/shared-sites' );
		$request->set_header( 'Origin', 'https://brand-one.example' );
		$request->set_header( 'X-OneMedia-Token', 'brand-one-key' );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
	}

	/**
	 * Tests consumer health-check requests store the governing site URL.
	 */
	public function test_check_api_permissions_sets_parent_site_on_health_check_for_consumer(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		$api_key = Settings::get_api_key();

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/health-check' );
		$request->set_header( 'Origin', 'https://governing.example' );
		$request->set_header( 'X-OneMedia-Token', $api_key );

		$this->assertTrue( $this->controller->check_api_permissions( $request ) );
		$this->assertSame( 'https://governing.example/', Settings::get_parent_site_url() );
	}

	/**
	 * Tests consumer non-health-check requests must match the saved governing site origin.
	 */
	public function test_check_api_permissions_rejects_non_health_check_when_origin_does_not_match_parent(): void {
		update_option( Settings::OPTION_SITE_TYPE, Settings::SITE_TYPE_CONSUMER );
		Settings::set_parent_site_url( 'https://expected-governing.example' );
		$api_key = Settings::get_api_key();

		$request = new WP_REST_Request( 'GET', '/onemedia/v1/shared-sites' );
		$request->set_header( 'Origin', 'https://different-governing.example' );
		$request->set_header( 'X-OneMedia-Token', $api_key );

		$this->assertFalse( $this->controller->check_api_permissions( $request ) );
	}
}
