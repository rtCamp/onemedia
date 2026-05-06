<?php
/**
 * Tests for encryption helpers.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

require_once dirname( __DIR__ ) . '/includes/encryptor-function-shims.php';

use OneMedia\Encryptor;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

if ( ! defined( 'ONEPRESS_ENCRYPTION_KEY' ) ) {
	define( 'ONEPRESS_ENCRYPTION_KEY', 'onemedia-test-key' );
}

if ( ! defined( 'ONEPRESS_ENCRYPTION_SALT' ) ) {
	define( 'ONEPRESS_ENCRYPTION_SALT', 'onemedia-test-salt' );
}

	/**
	 * Test class.
	 */
	#[CoversClass( Encryptor::class )]
final class EncryptorTest extends TestCase {
	/**
	 * {@inheritDoc}
	 */
	protected function tearDown(): void {
		unset(
			$GLOBALS['onemedia_test_extension_loaded'],
			$GLOBALS['onemedia_test_openssl_iv_length'],
			$GLOBALS['onemedia_test_openssl_random_bytes'],
			$GLOBALS['onemedia_test_openssl_encrypt_result'],
			$GLOBALS['onemedia_test_openssl_decrypt_result'],
			$GLOBALS['onemedia_test_defined_overrides']
		);

		parent::tearDown();
	}

	/**
	 * Invoke a private encryptor method.
	 *
	 * @param string       $method_name Method name.
	 * @param array<mixed> $args        Arguments.
	 */
	private function invoke_private_method( string $method_name, array $args = [] ): mixed {
		$method = new \ReflectionMethod( Encryptor::class, $method_name );

		return $method->invokeArgs( null, $args );
	}

	/**
	 * Tests that encrypted values decrypt back to their original value.
	 */
	public function test_encrypt_and_decrypt_round_trip_value(): void {
		$raw_value = 'https://example.com/private-media.jpg?token=abc123';

		$encrypted_value = Encryptor::encrypt( $raw_value );

		$this->assertIsString( $encrypted_value );
		$this->assertNotSame( $raw_value, $encrypted_value );
		$this->assertSame( $raw_value, Encryptor::decrypt( $encrypted_value ) );
	}

	/**
	 * Tests that invalid base64 input is treated as an already plain value.
	 */
	public function test_decrypt_returns_raw_value_when_value_is_not_base64(): void {
		$raw_value = 'not encrypted % value';

		$this->assertSame( $raw_value, Encryptor::decrypt( $raw_value ) );
	}

	/**
	 * Tests that decrypting tampered encrypted data fails.
	 */
	public function test_decrypt_returns_false_when_payload_is_tampered(): void {
		$encrypted_value = Encryptor::encrypt( 'secret value' );

		$this->assertIsString( $encrypted_value );

		$decoded_value = base64_decode( $encrypted_value, true );

		$this->assertIsString( $decoded_value );

		$tampered_value = base64_encode( $decoded_value . 'tampered' );

		$this->assertFalse( Encryptor::decrypt( $tampered_value ) );
	}

	/**
	 * Tests encryption and decryption return the raw value when OpenSSL is unavailable.
	 */
	public function test_encrypt_and_decrypt_return_raw_values_without_openssl(): void {
		$GLOBALS['onemedia_test_extension_loaded'] = [ 'openssl' => false ];
		$raw_value                                 = 'plain value';

		$this->assertSame( $raw_value, Encryptor::encrypt( $raw_value ) );
		$this->assertSame( $raw_value, Encryptor::decrypt( $raw_value ) );
	}

	/**
	 * Tests encryption returns false when OpenSSL encryption fails.
	 */
	public function test_encrypt_returns_false_when_openssl_encrypt_fails(): void {
		$GLOBALS['onemedia_test_openssl_iv_length']      = 16;
		$GLOBALS['onemedia_test_openssl_random_bytes']   = str_repeat( 'i', 16 );
		$GLOBALS['onemedia_test_openssl_encrypt_result'] = false;

		$this->assertFalse( Encryptor::encrypt( 'secret value' ) );
	}

	/**
	 * Tests decrypt returns false when OpenSSL decryption fails.
	 */
	public function test_decrypt_returns_false_when_openssl_decrypt_fails(): void {
		$encrypted_value = Encryptor::encrypt( 'secret value' );

		$this->assertIsString( $encrypted_value );

		$GLOBALS['onemedia_test_openssl_decrypt_result'] = false;

		$this->assertFalse( Encryptor::decrypt( $encrypted_value ) );
	}

	/**
	 * Tests private key and salt helpers use OnePress constants when present.
	 */
	public function test_private_key_and_salt_helpers_use_onemedia_constants(): void {
		$this->assertSame( ONEPRESS_ENCRYPTION_KEY, $this->invoke_private_method( 'get_key' ) );
		$this->assertSame( ONEPRESS_ENCRYPTION_SALT, $this->invoke_private_method( 'get_salt' ) );
	}

	/**
	 * Tests private key and salt helpers fall back to WordPress logged-in constants.
	 */
	public function test_private_key_and_salt_helpers_fall_back_to_logged_in_constants(): void {
		$this->assertTrue( defined( 'LOGGED_IN_KEY' ) );
		$this->assertTrue( defined( 'LOGGED_IN_SALT' ) );

		$GLOBALS['onemedia_test_defined_overrides'] = [
			'ONEPRESS_ENCRYPTION_KEY'  => false,
			'ONEPRESS_ENCRYPTION_SALT' => false,
		];

		$this->assertSame( LOGGED_IN_KEY, $this->invoke_private_method( 'get_key' ) );
		$this->assertSame( LOGGED_IN_SALT, $this->invoke_private_method( 'get_salt' ) );
	}

	/**
	 * Tests private key and salt helpers fall back to placeholder values when constants are unavailable.
	 */
	public function test_private_key_and_salt_helpers_fall_back_to_placeholders(): void {
		$GLOBALS['onemedia_test_defined_overrides'] = [
			'ONEPRESS_ENCRYPTION_KEY'  => false,
			'LOGGED_IN_KEY'            => false,
			'ONEPRESS_ENCRYPTION_SALT' => false,
			'LOGGED_IN_SALT'           => false,
		];

		$this->assertSame( 'this-is-not-a-real-key-change-me', $this->invoke_private_method( 'get_key' ) );
		$this->assertSame( 'this-is-not-a-real-salt-change-me', $this->invoke_private_method( 'get_salt' ) );
	}
}
