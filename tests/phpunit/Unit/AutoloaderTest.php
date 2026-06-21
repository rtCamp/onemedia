<?php
/**
 * Tests for the Autoloader class.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

use OneMedia\Autoloader;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the Autoloader class.
 */
#[CoversClass( Autoloader::class )]
final class AutoloaderTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->reset_autoloader();
	}

	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		$this->reset_autoloader();

		parent::tearDown();
	}

	/**
	 * Tests autoload succeeds when the Composer autoloader exists.
	 */
	public function test_autoload_returns_true_when_autoloader_exists(): void {
		$this->assertTrue( Autoloader::autoload() );

		$property = new \ReflectionProperty( Autoloader::class, 'is_loaded' );
		$this->assertTrue( $property->getValue() );
	}

	/**
	 * Tests autoload returns true on repeat calls without reloading.
	 */
	public function test_autoload_is_idempotent(): void {
		Autoloader::autoload();

		$this->assertTrue( Autoloader::autoload(), 'Autoload should return true on subsequent calls' );
	}

	/**
	 * Tests missing autoloader notice registers hooks for both admin screens.
	 */
	public function test_missing_autoloader_notice_adds_admin_notices(): void {
		$method = new \ReflectionMethod( Autoloader::class, 'missing_autoloader_notice' );
		$method->invoke( null );

		$this->expectOutputRegex( '/OneMedia: The Composer autoloader was not found/' );
		do_action( 'admin_notices' );

		$this->expectOutputRegex( '/OneMedia: The Composer autoloader was not found/' );
		do_action( 'network_admin_notices' );
	}

	/**
	 * Reset static autoloader state between tests.
	 */
	private function reset_autoloader(): void {
		$property = new \ReflectionProperty( Autoloader::class, 'is_loaded' );
		$property->setValue( null, false );
	}
}
