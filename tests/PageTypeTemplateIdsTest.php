<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Request\PageType;

if ( ! class_exists( 'WP_Post' ) ) { class WP_Post { public $ID = 0; } }

final class PageTypeTemplateIdsTest extends TestCase {
	public function test_from_collects_and_dedupes_active_templates(): void {
		$this->assertSame( [ 5, 7, 9 ], PageType::template_ids_from( [ 'header' => 5, 'footer' => '7', 'content' => 9, 'archive' => 11 ], null, false ) );
		$this->assertSame( [ 5 ], PageType::template_ids_from( [ 'header' => 5, 'footer' => 5, 'content' => 0 ], null, false ) );
	}
	public function test_from_drops_non_numeric_and_zero(): void {
		$this->assertSame( [], PageType::template_ids_from( [ 'header' => 'x', 'footer' => 0, 'content' => null ], null, false ) );
	}
	public function test_from_includes_queried_post_only_when_wp_post_with_bricks_content(): void {
		$post = new WP_Post(); $post->ID = 42;
		$this->assertSame( [ 9, 42 ], PageType::template_ids_from( [ 'content' => 9 ], $post, true ) );
		$this->assertSame( [ 9 ], PageType::template_ids_from( [ 'content' => 9 ], $post, false ) );
		$term = (object) [ 'term_id' => 42, 'ID' => 42 ];
		$this->assertSame( [ 9 ], PageType::template_ids_from( [ 'content' => 9 ], $term, true ) );
	}

	public function test_template_ids_safe_without_bricks(): void {
		$this->assertSame( [], PageType::template_ids() );
	}
}
