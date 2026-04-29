<?php
/**
 * Tests for media replacement helpers.
 *
 * @package OneMedia\Tests\Unit\Modules\MediaSharing
 */

declare( strict_types = 1 );

namespace OneMedia\Tests\Unit\Modules\MediaSharing;

use OneMedia\Modules\MediaSharing\MediaReplacement;
use OneMedia\Tests\TestCase;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * @covers \OneMedia\Modules\MediaSharing\MediaReplacement
 */
#[CoversClass( MediaReplacement::class )]
final class MediaReplacementTest extends TestCase {
	/**
	 * Tests that posts and post meta containing the attachment image are updated.
	 */
	public function test_replace_image_across_all_post_types_updates_post_content_and_meta(): void {
		$attachment_id = self::factory()->attachment->create();
		$post_id       = self::factory()->post->create(
			[
				'post_content' => sprintf(
					'<figure class="wp-block-image"><img class="wp-image-%1$d" src="https://old.test/image.jpg" srcset="old 1x" sizes="100vw" alt="Old"><figcaption>Old caption</figcaption></figure>',
					$attachment_id
				),
			]
		);

		update_post_meta(
			$post_id,
			'_onemedia_content',
			sprintf( '<img class="wp-image-%d" src="https://old.test/meta.jpg" alt="Old meta">', $attachment_id )
		);
		update_post_meta( $post_id, '_onemedia_unmatched_content', sprintf( 'wp-image-%d appears without an image tag', $attachment_id ) );
		$template_id = self::factory()->post->create(
			[
				'post_type'    => 'wp_template',
				'post_content' => sprintf( '<img class="wp-image-%d" src="https://old.test/template.jpg">', $attachment_id ),
			]
		);

		MediaReplacement::replace_image_across_all_post_types(
			$attachment_id,
			'https://new.test/image.jpg',
			'New alt',
			'New caption'
		);

		$post_content = get_post_field( 'post_content', $post_id );
		$meta_content = get_post_meta( $post_id, '_onemedia_content', true );
		$template     = get_post_field( 'post_content', $template_id );

		$this->assertStringContainsString( 'src="https://new.test/image.jpg"', $post_content );
		$this->assertStringNotContainsString( 'srcset=', $post_content );
		$this->assertStringNotContainsString( 'sizes=', $post_content );
		$this->assertStringContainsString( 'alt="New alt"', $post_content );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">New caption</figcaption>', $post_content );
		$this->assertStringContainsString( 'src="https://new.test/image.jpg"', $meta_content );
		$this->assertStringContainsString( 'alt="New alt"', $meta_content );
		$this->assertStringContainsString( 'src="https://new.test/image.jpg"', $template );
		$this->assertSame( sprintf( 'wp-image-%d appears without an image tag', $attachment_id ), get_post_meta( $post_id, '_onemedia_unmatched_content', true ) );
	}

	/**
	 * Tests that content without matching images is left untouched.
	 */
	public function test_replace_image_across_all_post_types_leaves_unmatched_content_unchanged(): void {
		$post_id = self::factory()->post->create(
			[
				'post_content' => '<p>No matching image.</p>',
			]
		);

		MediaReplacement::replace_image_across_all_post_types( 999999, 'https://new.test/image.jpg' );

		$this->assertSame( '<p>No matching image.</p>', get_post_field( 'post_content', $post_id ) );
	}

	/**
	 * Tests private HTML replacement branches that are not always reachable from database fixtures.
	 */
	public function test_replace_image_content_adds_missing_alt_and_caption(): void {
		$reflection = new \ReflectionMethod( MediaReplacement::class, 'replace_image_content' );

		$result = $reflection->invoke(
			null,
			[
				'<figure class="wp-block-image"><img class="wp-image-10" src="https://old.test/image.jpg"></figure>',
			],
			'https://new.test/image.jpg',
			'New alt',
			'New <strong>caption</strong>'
		);

		$this->assertIsString( $result );
		$this->assertStringContainsString( 'src="https://new.test/image.jpg"', $result );
		$this->assertStringContainsString( 'alt="New alt"', $result );
		$this->assertStringContainsString( '<figcaption class="wp-element-caption">New <strong>caption</strong></figcaption>', $result );
	}
}
