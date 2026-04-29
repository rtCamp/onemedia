<?php
/**
 * Tests for main plugin bootstrap class.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

use OneMedia\Main;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Main
 */
#[CoversClass( Main::class )]
final class MainTest extends TestCase {
	/**
	 * Reset singleton instance before each test.
	 */
	public function set_up(): void {
		parent::set_up();

		$property = new \ReflectionProperty( Main::class, 'instance' );
		$property->setValue( null, null );
	}

	/**
	 * Tests the main plugin class returns a singleton instance.
	 */
	public function test_get_instance_returns_singleton(): void {
		$this->assertSame( Main::instance(), Main::instance() );
	}
}
