<?php
/**
 * Tests for shared REST controller behavior.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Rest;

use OneMedia\Modules\Rest\Abstract_REST_Controller;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Modules\Rest\Abstract_REST_Controller
 */
#[CoversClass( Abstract_REST_Controller::class )]
final class AbstractRestControllerTest extends TestCase {
	/**
	 * Tests that the shared REST controller base can be extended.
	 */
	public function test_class_instantiation(): void {
		$controller = new class() extends Abstract_REST_Controller {
			/**
			 * {@inheritDoc}
			 */
			public function register_routes(): void {
				// Empty test fixture implementation.
			}
		};

		$this->assertInstanceOf( Abstract_REST_Controller::class, $controller );
	}
}
