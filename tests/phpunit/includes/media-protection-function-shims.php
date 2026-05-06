<?php
/**
 * Media protection test-only namespace shims.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Modules\MediaSharing;

/**
 * Override AJAX detection in tests.
 */
function wp_doing_ajax(): bool {
	$callback = $GLOBALS['onemedia_media_protection_wp_doing_ajax_callback'] ?? null;

	if ( is_callable( $callback ) ) {
		return (bool) $callback();
	}

	return \wp_doing_ajax();
}
