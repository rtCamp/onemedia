<?php
/**
 * Tests for utility helpers.
 *
 * @package OneMedia\Tests\Unit
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit;

use OneMedia\Tests\TestCase;
use OneMedia\Utils;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Test class.
 */
#[CoversClass( Utils::class )]
final class UtilsTest extends TestCase {
	/**
	 * Tests that HTML entities in file names are decoded.
	 */
	public function test_decode_filename_decodes_html_entities(): void {
		$this->assertSame(
			'Brand "Logo" & Icon\'s.png',
			Utils::decode_filename( 'Brand &quot;Logo&quot; &amp; Icon&#039;s.png' )
		);
	}

	/**
	 * Tests that supported mime types only include OneMedia image mime types.
	 */
	public function test_get_supported_mime_types_filters_to_onemedia_image_mimes(): void {
		add_filter( // phpcs:ignore WordPressVIPMinimum.Hooks.RestrictedHooks.upload_mimes -- Test fixture covers OneMedia's supported mime filtering.
			'upload_mimes',
			static function ( array $mimes ): array {
				$mimes['pdf']     = 'application/pdf';
				$mimes['webp']    = 'image/webp';
				$mimes['custom']  = 'image/custom';
				$mimes['svg']     = 'image/svg+xml';
				$mimes['svgz']    = 'image/svg+xml';
				$mimes['notreal'] = 'application/x-not-real';

				return $mimes;
			}
		);

		$supported_mimes = Utils::get_supported_mime_types();

		$this->assertSame( 'image/jpeg', $supported_mimes['jpg|jpeg|jpe'] );
		$this->assertSame( 'image/png', $supported_mimes['png'] );
		$this->assertSame( 'image/webp', $supported_mimes['webp'] );
		$this->assertSame( 'image/svg+xml', $supported_mimes['svg'] );
		$this->assertArrayNotHasKey( 'pdf', $supported_mimes );
		$this->assertArrayNotHasKey( 'custom', $supported_mimes );
		$this->assertArrayNotHasKey( 'notreal', $supported_mimes );
	}

	/**
	 * Tests that existing templates render and receive variables.
	 */
	public function test_get_template_content_renders_existing_template_with_variables(): void {
		$content = Utils::get_template_content(
			'brand-site/sync-status',
			[
				'sync_status' => 'sync',
			]
		);

		$this->assertStringContainsString( '<select name="onemedia_sync_status"', $content );
		$this->assertMatchesRegularExpression( '/<option\\b[^>]*\\bvalue=([\'"])sync\\1[^>]*\\bselected(?:=([\'"])selected\\2)?[^>]*>/', $content );
		$this->assertStringContainsString( 'name="onemedia_sync_nonce"', $content );
	}

	/**
	 * Tests that missing templates return an empty string.
	 */
	public function test_get_template_content_returns_empty_string_for_missing_template(): void {
		$this->assertSame( '', Utils::get_template_content( 'missing/template' ) );
	}
}
