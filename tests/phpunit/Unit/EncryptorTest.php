<?php
/**
 * Tests for encryption helpers.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

use OneMedia\Encryptor;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Encryptor::class )]
final class EncryptorTest extends TestCase {
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
}
