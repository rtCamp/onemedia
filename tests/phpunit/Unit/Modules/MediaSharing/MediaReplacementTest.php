<?php
/**
 * Tests for the MediaSharing\MediaReplacement class.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\MediaReplacement;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Tests for the MediaSharing\MediaReplacement class.
 */
#[CoversClass( MediaReplacement::class )]
final class MediaReplacementTest extends TestCase {
	/**
	 * Tests replace_image_across_all_post_types updates post content and matching post meta.
	 */
	public function test_replace_image_across_all_post_types_updates_content_and_meta(): void {
		$attachment_id = 987654;
		$post_id       = self::factory()->post->create(
			[
				'post_status'  => 'publish',
				'post_content' => sprintf(
					'<figure class="wp-block-image"><img src="https://example.com/old.jpg" srcset="old-2x.jpg 2x" sizes="100vw" alt="Old alt" class="wp-image-%d" /><figcaption>Old caption</figcaption></figure>',
					$attachment_id
				),
			]
		);
		update_post_meta(
			$post_id,
			'onemedia_test_image_html',
			sprintf(
				'<img src="https://example.com/meta-old.jpg" alt="Meta old alt" class="wp-image-%d" />',
				$attachment_id
			)
		);

		MediaReplacement::replace_image_across_all_post_types(
			$attachment_id,
			'https://cdn.example.com/new.jpg',
			'New alt',
			'New caption'
		);

		$content = get_post_field( 'post_content', $post_id );
		$meta    = get_post_meta( $post_id, 'onemedia_test_image_html', true );

		$this->assertStringContainsString( 'src="https://cdn.example.com/new.jpg"', $content );
		$this->assertStringContainsString( 'alt="New alt"', $content );
		$this->assertStringContainsString( 'New caption', $content );
		$this->assertStringNotContainsString( 'srcset=', $content );
		$this->assertStringNotContainsString( 'sizes=', $content );
		$this->assertStringContainsString( 'src="https://cdn.example.com/new.jpg"', $meta );
		$this->assertStringContainsString( 'alt="New alt"', $meta );
	}
}
