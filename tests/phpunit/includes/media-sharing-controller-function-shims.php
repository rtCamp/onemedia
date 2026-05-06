<?php
/**
 * Media sharing controller test-only namespace shims.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Modules\Rest;

/**
 * Override attachment insertion in tests.
 *
 * @param mixed ...$args Function arguments.
 */
function wp_insert_attachment( mixed ...$args ): int|\WP_Error {
	if ( array_key_exists( 'onemedia_test_wp_insert_attachment_result', $GLOBALS ) ) {
		return $GLOBALS['onemedia_test_wp_insert_attachment_result'];
	}

	return \wp_insert_attachment( ...$args );
}

/**
 * Override unlink for temp-file failure simulation in tests.
 *
 * @param string $filename File path.
 */
function unlink( string $filename ): bool {
	$temp_dir = function_exists( '\\get_temp_dir' )
		? rtrim( (string) \get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR
		: rtrim( (string) sys_get_temp_dir(), '/\\' ) . DIRECTORY_SEPARATOR;

	if ( ! empty( $GLOBALS['onemedia_test_fail_temp_unlink'] ) && str_starts_with( $filename, $temp_dir ) ) {
		return false;
	}

	return \unlink( $filename );
}
