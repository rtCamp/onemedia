<?php
/**
 * Encryptor test-only namespace shims.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia;

/**
 * Override extension loading checks in tests.
 *
 * @param string $extension Extension name.
 */
function extension_loaded( string $extension ): bool {
	$overrides = $GLOBALS['onemedia_test_extension_loaded'] ?? [];

	if ( array_key_exists( $extension, $overrides ) ) {
		return (bool) $overrides[ $extension ];
	}

	return \extension_loaded( $extension );
}

/**
 * Override IV length lookup in tests.
 *
 * @param string $cipher_algo Cipher algorithm.
 */
function openssl_cipher_iv_length( string $cipher_algo ): int|false {
	if ( array_key_exists( 'onemedia_test_openssl_iv_length', $GLOBALS ) ) {
		return $GLOBALS['onemedia_test_openssl_iv_length'];
	}

	return \openssl_cipher_iv_length( $cipher_algo );
}

/**
 * Override random bytes generation in tests.
 *
 * @param int       $length        Byte length.
 * @param bool|null $strong_result Whether a cryptographically strong algorithm was used.
 */
function openssl_random_pseudo_bytes( int $length, ?bool &$strong_result = null ): string|false {
	if ( array_key_exists( 'onemedia_test_openssl_random_bytes', $GLOBALS ) ) {
		$strong_result = true;

		return $GLOBALS['onemedia_test_openssl_random_bytes'];
	}

	return \openssl_random_pseudo_bytes( $length, $strong_result );
}

/**
 * Override OpenSSL encrypt in tests.
 *
 * @param mixed ...$args Function arguments.
 */
function openssl_encrypt( mixed ...$args ): string|false {
	if ( array_key_exists( 'onemedia_test_openssl_encrypt_result', $GLOBALS ) ) {
		return $GLOBALS['onemedia_test_openssl_encrypt_result'];
	}

	return \openssl_encrypt( ...$args );
}

/**
 * Override OpenSSL decrypt in tests.
 *
 * @param mixed ...$args Function arguments.
 */
function openssl_decrypt( mixed ...$args ): string|false {
	if ( array_key_exists( 'onemedia_test_openssl_decrypt_result', $GLOBALS ) ) {
		return $GLOBALS['onemedia_test_openssl_decrypt_result'];
	}

	return \openssl_decrypt( ...$args );
}

/**
 * Override constant definition checks in tests.
 *
 * @param string $constant_name Constant name.
 */
function defined( string $constant_name ): bool {
	$overrides = $GLOBALS['onemedia_test_defined_overrides'] ?? [];

	if ( array_key_exists( $constant_name, $overrides ) ) {
		return (bool) $overrides[ $constant_name ];
	}

	return \defined( $constant_name );
}
