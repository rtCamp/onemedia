<?php
/**
 * Tests for the autoloader wrapper.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

use OneMedia\Autoloader;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Autoloader::class )]
final class AutoloaderTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		$property = new \ReflectionProperty( Autoloader::class, 'is_loaded' );
		$property->setValue( null, false );

		parent::tearDown();
	}

	/**
	 * Tests that autoload loads the Composer autoloader once.
	 */
	public function test_autoload_loads_composer_autoloader(): void {
		$property = new \ReflectionProperty( Autoloader::class, 'is_loaded' );
		$property->setValue( null, false );

		$this->assertTrue( Autoloader::autoload() );
		$this->assertTrue( Autoloader::autoload() );
	}

	/**
	 * Tests protected missing-autoloader branch.
	 */
	public function test_require_autoloader_registers_notice_for_missing_file(): void {
		$method = new \ReflectionMethod( Autoloader::class, 'require_autoloader' );

		$this->assertFalse( $method->invoke( null, sys_get_temp_dir() . '/missing-onemedia-autoload.php' ) );
		$this->assertNotFalse( has_action( 'admin_notices' ) );
		$this->assertNotFalse( has_action( 'network_admin_notices' ) );

		$this->expectOutputRegex( '/OneMedia: The Composer autoloader was not found/' );
		do_action( 'admin_notices' );

		$this->expectOutputRegex( '/OneMedia: The Composer autoloader was not found/' );
		do_action( 'network_admin_notices' );
	}
}
