<?php
/**
 * Basic options controller test-only namespace shims.
 *
 * @package OneMedia\Tests\Unit\Modules\Rest
 */

declare( strict_types = 1 );

namespace OneMedia\Modules\Rest;

/**
 * Override multisite detection in tests.
 */
function is_multisite(): bool {
	return (bool) ( $GLOBALS['onemedia_test_is_multisite'] ?? false );
}

/**
 * Override subdomain install detection in tests.
 */
function is_subdomain_install(): bool {
	return (bool) ( $GLOBALS['onemedia_test_is_subdomain_install'] ?? false );
}
