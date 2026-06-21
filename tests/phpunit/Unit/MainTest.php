<?php
/**
 * Tests for the Main bootstrap class.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

use OneMedia\Main;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Main bootstrap class.
 */
#[CoversClass( Main::class )]
final class MainTest extends TestCase {
	/**
	 * Holds the original permalink structure to restore after tests.
	 *
	 * @var string|false|null
	 */
	private string|false|null $original_permalink_structure = null;

	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->original_permalink_structure = get_option( 'permalink_structure', null );

		$this->reset_main_singleton();
	}

	protected function tearDown(): void {
		$this->reset_main_singleton();

		null === $this->original_permalink_structure
			? delete_option( 'permalink_structure' )
			: update_option( 'permalink_structure', $this->original_permalink_structure );

		parent::tearDown();
	}

	/**
	 * Tests the main plugin class returns a singleton instance.
	 */
	public function test_instance_returns_singleton(): void {
		$this->assertSame( Main::instance(), Main::instance() );
	}

	/**
	 * Reset the Main singleton between tests.
	 */
	private function reset_main_singleton(): void {
		$property = new \ReflectionProperty( Main::class, 'instance' );
		$property->setValue( null, null );
	}
}
