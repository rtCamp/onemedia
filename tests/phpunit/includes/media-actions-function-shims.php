<?php
/**
 * Media actions test-only namespace shims.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Modules\MediaSharing;

/**
 * Override uploaded file moves in tests.
 *
 * @param string $from Source path.
 * @param string $to   Destination path.
 */
function move_uploaded_file( string $from, string $to ): bool {
	if ( isset( $GLOBALS['onemedia_media_actions_test_move_uploaded_file'] ) && is_callable( $GLOBALS['onemedia_media_actions_test_move_uploaded_file'] ) ) {
		return (bool) $GLOBALS['onemedia_media_actions_test_move_uploaded_file']( $from, $to );
	}

	return \move_uploaded_file( $from, $to );
}

/**
 * Override attachment metadata generation in tests.
 *
 * @param int    $attachment_id Attachment ID.
 * @param string $file          File path.
 */
function wp_generate_attachment_metadata( int $attachment_id, string $file ): mixed {
	if ( isset( $GLOBALS['onemedia_media_actions_test_generate_metadata'] ) && is_callable( $GLOBALS['onemedia_media_actions_test_generate_metadata'] ) ) {
		return $GLOBALS['onemedia_media_actions_test_generate_metadata']( $attachment_id, $file );
	}

	return \wp_generate_attachment_metadata( $attachment_id, $file );
}

/**
 * Override wp_update_post in tests.
 *
 * @param array<string, mixed> $postarr          Post update payload.
 * @param bool                 $wp_error         Whether to return WP_Error on failure.
 * @param bool                 $fire_after_hooks Whether to fire after hooks.
 */
function wp_update_post( array $postarr = [], bool $wp_error = false, bool $fire_after_hooks = true ): int|\WP_Error {
	if ( isset( $GLOBALS['onemedia_media_actions_test_wp_update_post'] ) && is_callable( $GLOBALS['onemedia_media_actions_test_wp_update_post'] ) ) {
		return $GLOBALS['onemedia_media_actions_test_wp_update_post']( $postarr, $wp_error, $fire_after_hooks );
	}

	return \wp_update_post( $postarr, $wp_error, $fire_after_hooks );
}
