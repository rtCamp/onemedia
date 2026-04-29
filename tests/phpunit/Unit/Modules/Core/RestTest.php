<?php
/**
 * Tests for REST core helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\Core
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\Core;

use OneMedia\Modules\Core\Rest;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Modules\Core\Rest
 */
#[CoversClass( Rest::class )]
final class RestTest extends TestCase {
	/**
	 * Tests no errors on class instantiation.
	 */
	public function test_class_instantiation(): void {
		$rest = new Rest();

		$rest->register_hooks();

		$this->assertTrue( true );
	}

	/**
	 * Tests that the OneMedia token header is added once.
	 */
	public function test_allowed_cors_headers_adds_onemedia_token_once(): void {
		$rest = new Rest();

		$this->assertSame(
			[ 'X-WP-Nonce', 'X-OneMedia-Token' ],
			$rest->allowed_cors_headers( [ 'X-WP-Nonce' ] ),
			'Token should be added to headers'
		);

		$this->assertSame(
			[ 'X-OneMedia-Token' ],
			$rest->allowed_cors_headers( [ 'X-OneMedia-Token' ] ),
			'Token should not be readded'
		);
	}
}
