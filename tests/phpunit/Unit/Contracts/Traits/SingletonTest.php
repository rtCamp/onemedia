<?php
/**
 * Tests for the singleton trait.
 *
 * @package OneMedia\Tests\Unit\Contracts\Traits
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Contracts\Traits;

use OneMedia\Contracts\Traits\Singleton;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversTrait;

/**
 * Test fixture for Singleton trait.
 */
final class SingletonFixture {
	use Singleton;

	/**
	 * Fixture method to keep trait-use spacing valid.
	 */
	public function fixture_method(): void {
		// Intentionally empty fixture method.
	}
}

/**
 * Test class.
 */
#[CoversTrait( Singleton::class )]
final class SingletonTest extends TestCase {
	/**
	 * Tests that instance returns the same object.
	 */
	public function test_instance_returns_singleton_instance(): void {
		$this->assertSame( SingletonFixture::instance(), SingletonFixture::instance() );
	}

	/**
	 * Tests clone protection.
	 *
	 * @expectedIncorrectUsage __clone
	 */
	public function test_clone_triggers_doing_it_wrong(): void {
		$fixture = SingletonFixture::instance();

		$fixture->__clone();

		$this->assertTrue( true );
	}

	/**
	 * Tests wakeup protection.
	 *
	 * @expectedIncorrectUsage __wakeup
	 */
	public function test_wakeup_triggers_doing_it_wrong(): void {
		$fixture = SingletonFixture::instance();

		$fixture->__wakeup();

		$this->assertTrue( true );
	}
}
