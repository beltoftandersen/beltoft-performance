<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\ContentSignatures;
use BeltoftPerformance\Check\FileCandidates;
use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Cli\Command;

final class ContentSignaturesTest extends TestCase {
	private function a( string $kind, string $h, string $plugin, string $path ): array { return [ 'kind' => $kind, 'handle' => $h, 'src' => 'https://s' . $path, 'plugin' => $plugin, 'deps' => [] ]; }

	public function test_needles_are_signatures_plus_shortcodes(): void {
		$n = ContentSignatures::needles( 'wpc-composite-products' );
		$this->assertContains( 'wooco-', $n );
		$this->assertContains( '[wooco', $n );
		$this->assertNotContains( 'wooco', $n ); // a bare "wooco" matches every "woocommerce"
		$s = ContentSignatures::needles( 'super-socializer' );
		$this->assertContains( '[the_champ', $s );
		$this->assertContains( '[heateor', $s );
		$this->assertContains( '[subscription', ContentSignatures::needles( 'woocommerce-subscriptions' ) );
		$this->assertContains( '[wcsatt', ContentSignatures::needles( 'woocommerce-subscriptions' ) );
		$this->assertContains( '[flexible_shipping', ContentSignatures::needles( 'flexible-shipping' ) );
		$this->assertSame( [], ContentSignatures::needles( 'no-such-plugin' ) );
	}

	public function test_page_type_of_a_post(): void {
		$ids = [ 'cart' => 54, 'checkout' => 55, 'account' => 56, 'front' => 10, 'shop' => 53 ];
		$this->assertSame( 'cart', ContentSignatures::page_type( 'page', 54, $ids ) );
		$this->assertSame( 'account', ContentSignatures::page_type( 'page', 56, $ids ) );
		$this->assertSame( 'page', ContentSignatures::page_type( 'page', 999, $ids ) );
		$this->assertSame( '', ContentSignatures::page_type( 'page', 53, $ids ) ); // shop is not a scanned type
		$this->assertSame( '', ContentSignatures::page_type( 'page', 10, $ids ) );
		$this->assertSame( 'post', ContentSignatures::page_type( 'post', 5, $ids ) );
		$this->assertSame( 'product', ContentSignatures::page_type( 'product', 5, $ids ) );
	}

	public function test_template_page_types(): void {
		$all = ContentSignatures::PAGE_TYPES;
		$this->assertSame( [ 'product' ], ContentSignatures::template_pages( 'wc_product', [] ) );
		$this->assertSame( [ 'cart' ], ContentSignatures::template_pages( 'wc_cart', [] ) );
		$this->assertSame( [ 'cart' ], ContentSignatures::template_pages( 'wc_cart_empty', [] ) );
		$this->assertSame( [ 'checkout' ], ContentSignatures::template_pages( 'wc_checkout', [] ) );
		$this->assertSame( [ 'account' ], ContentSignatures::template_pages( 'wc_account_form_login', [] ) );
		$this->assertSame( [], ContentSignatures::template_pages( 'wc_archive', [] ) );
		$this->assertSame( [], ContentSignatures::template_pages( 'search', [] ) );
		$this->assertSame( $all, ContentSignatures::template_pages( 'header', [] ) );
		$this->assertSame( $all, ContentSignatures::template_pages( 'popup', [] ) );
		$this->assertSame( [ 'post' ], ContentSignatures::template_pages( 'content', [ 'templateConditions' => [ [ 'main' => 'postType', 'postType' => [ 'post' ] ] ] ] ) );
		$this->assertSame( $all, ContentSignatures::template_pages( 'content', [] ) ); // unknown placement: assume everywhere
	}

	public function test_from_docs_maps_plugins_to_page_types(): void {
		$docs = [
			[ 'pages' => [ 'page' ], 'text' => '<div class="woosw-list">' ],
			[ 'pages' => [ 'product', 'cart' ], 'text' => '{"name":"woosw-btn"}' ],
			[ 'pages' => [ 'post' ], 'text' => '[the_champ_sharing]' ],
			[ 'pages' => [ 'page' ], 'text' => 'woocommerce only' ],
		];
		$m = ContentSignatures::from_docs( $docs, [] );
		$this->assertSame( [ 'cart', 'page', 'product' ], $m['woo-smart-wishlist'] );
		$this->assertSame( [ 'post' ], $m['super-socializer'] );
		$this->assertArrayNotHasKey( 'wpc-composite-products', $m );
	}

	public function test_composite_product_type_marks_product_used(): void {
		$m = ContentSignatures::from_docs( [], [ 'composite', 'simple' ] );
		$this->assertSame( [ 'product' ], $m['wpc-composite-products'] );
	}

	public function test_file_candidates_drop_page_types_where_owner_appears(): void {
		$css = $this->a( 'style', 'woosw-icons', 'woo-smart-wishlist', '/wp-content/plugins/woo-smart-wishlist/i.css' );
		$u   = [ 'style' => [ '/wp-content/plugins/woo-smart-wishlist/i.css' => 0 ], 'script' => [] ];
		$inv = [];
		$use = [];
		foreach ( [ 'account', 'blog', 'page' ] as $p ) {
			$inv[ $p ] = [ 'guest' => [ 'status' => 200, 'assets' => [ $css ] ] ];
			$use[ $p ] = [ 'guest' => $u ];
		}
		$c = FileCandidates::from( $inv, $use, [], [ 'woo-smart-wishlist' => [ 'page', 'account' ] ] );
		$this->assertSame( [ 'blog' ], $c[0]['pages'] );
		$this->assertSame( [ 'blog' ], array_keys( $c[0]['why'] ) );
		$this->assertSame( [], FileCandidates::from( $inv, $use, [], [ 'woo-smart-wishlist' => [ 'page', 'account', 'blog' ] ] ) );
		$this->assertSame( [ 'account', 'blog', 'page' ], FileCandidates::from( $inv, $use, [], [ 'other' => [ 'page' ] ] )[0]['pages'] );
	}

	public function test_drop_rejected_pages(): void {
		$c = [
			[ 'target' => 'style:a', 'pages' => [ 'blog', 'page' ], 'why' => [ 'blog' => 'x', 'page' => 'y' ], 'evidence' => [ 'blog' => [], 'page' => [] ] ],
			[ 'target' => 'style:b', 'pages' => [ 'page' ], 'why' => [ 'page' => 'y' ] ],
			[ 'target' => 'style:c', 'pages' => [ 'page' ], 'why' => [ 'page' => 'y' ] ],
		];
		$out = Runner::drop_rejected_pages( $c, [ 'style:a' => [ 'page' ], 'style:b' => [ 'page' ] ] );
		$this->assertSame( [ 'style:a', 'style:c' ], array_column( $out, 'target' ) );
		$this->assertSame( [ 'blog' ], $out[0]['pages'] );
		$this->assertSame( [ 'blog' => 'x' ], $out[0]['why'] );
		$this->assertSame( [ 'blog' ], array_keys( $out[0]['evidence'] ) );
	}

	public function test_accept_pages_subset(): void {
		$this->assertSame( [ 'blog', 'checkout', 'post' ], Command::accept_pages( [ 'blog', 'checkout', 'post' ], '' ) );
		$this->assertSame( [ 'blog', 'post' ], Command::accept_pages( [ 'blog', 'checkout', 'post' ], 'post, blog' ) );
		$this->assertIsString( Command::accept_pages( [ 'blog', 'post' ], 'blog,page' ) );
		$this->assertIsString( Command::accept_pages( [ 'blog', 'post' ], ',' ) );
	}

	public function test_candidate_for_pages_keeps_only_those_reasons(): void {
		$c = [ 'target' => 'style:a', 'pages' => [ 'blog', 'page' ], 'why' => [ 'blog' => 'x', 'page' => 'y' ] ];
		$n = Runner::narrow( $c, [ 'blog' ] );
		$this->assertSame( [ 'blog' ], $n['pages'] );
		$this->assertSame( [ 'blog' => 'x' ], $n['why'] );
	}
}
