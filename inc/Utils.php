<?php
/**
 * Static utility functions.
 *
 * @package OneMedia
 */

declare( strict_types = 1 );

namespace OneMedia;

/**
 * Class - Utils
 */
final class Utils {
	/**
	 * The templates dir.
	 */
	private const TEMPLATES_PATH = ONEMEDIA_DIR . '/templates';

	/**
	 * Allowed mime types array.
	 *
	 * This is a list of potentially supported mime types, any unsupported mime types will
	 * be removed during usage, on that particular server.
	 *
	 * @var array<string, string>
	 */
	private const ALLOWED_MIME_TYPES = [
		'jpg|jpeg|jpe' => 'image/jpeg',
		'png'          => 'image/png',
		'gif'          => 'image/gif',
		'bmp'          => 'image/bmp',
		'webp'         => 'image/webp',
		'svg'          => 'image/svg+xml',
		'svgz'         => 'image/svg+xml',
	];

	/**
	 * Decode filename to handle special characters.
	 *
	 * @param string $filename The filename to decode.
	 *
	 * @return string The decoded filename.
	 */
	public static function decode_filename( string $filename ): string {
		return html_entity_decode( $filename, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Get supported mime types.
	 *
	 * @return array<string, string> Array of supported mime types by the server.
	 */
	public static function get_supported_mime_types(): array {
		$wp_mimes = get_allowed_mime_types();

		/**
		 * Filter WordPress mime list by allowed mime values.
		 */
		return array_intersect_key(
			$wp_mimes,
			self::ALLOWED_MIME_TYPES
		);
	}

	/**
	 * Return onemedia template content.
	 *
	 * @param string               $slug Template path.
	 * @param array<string, mixed> $vars Template variables.
	 *
	 * @return string Template markup.
	 */
	public static function get_template_content( string $slug, array $vars = [] ): string {
		ob_start();

		$template = sprintf( '%s.php', $slug );

		$located_template = '';
		if ( file_exists( self::TEMPLATES_PATH . '/' . $template ) ) {
			$located_template = self::TEMPLATES_PATH . '/' . $template;
		}

		if ( '' === $located_template ) {
			ob_end_clean();
			return '';
		}

		$vars = $vars;

		include $located_template; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable

		return ob_get_clean() ?: '';
	}
}
