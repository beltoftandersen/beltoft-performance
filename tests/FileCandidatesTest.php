<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\FileCandidates;

final class FileCandidatesTest extends TestCase {
	private function report( array $assets ): array { return [ 'status' => 200, 'assets' => $assets ]; }
	private function a( string $kind, string $h, string $plugin, string $path ): array { return [ 'kind' => $kind, 'handle' => $h, 'src' => 'https://s' . $path, 'plugin' => $plugin, 'deps' => [] ]; }
	public function test_unused_style_and_script_become_candidates_only_when_unused_everywhere(): void {
		$css = $this->a( 'style', 'woosw-frontend', 'woo-smart-wishlist', '/wp-content/plugins/woo-smart-wishlist/a.css' );
		$js  = $this->a( 'script', 'woosw-frontend', 'woo-smart-wishlist', '/wp-content/plugins/woo-smart-wishlist/a.js' );
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $css, $js ] ), 'logged_in' => $this->report( [ $css, $js ] ) ] ];
		$unused = [ 'style' => [ '/wp-content/plugins/woo-smart-wishlist/a.css' => 0 ], 'script' => [ '/wp-content/plugins/woo-smart-wishlist/a.js' => [ 'called' => 0, 'listeners' => 0 ] ] ];
		$c = FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $unused, 'logged_in' => $unused ] ], [] );
		$this->assertSame( [ 'script:woosw-frontend', 'style:woosw-frontend' ], array_values( array_map( fn( $x ) => $x['target'], $c ) ) );
		}
	public function test_missing_usage_for_a_state_means_no_candidate(): void {
		$css = $this->a( 'style', 'x-css', 'x', '/wp-content/plugins/x/a.css' );
		$inv = [ 'shop' => [ 'guest' => $this->report( [ $css ] ), 'cart' => $this->report( [ $css ] ) ] ];
		$c = FileCandidates::from( $inv, [ 'shop' => [ 'guest' => [ 'style' => [ '/wp-content/plugins/x/a.css' => 0 ], 'script' => [] ] ] ], [] );
		$this->assertSame( [], $c );
	}
	public function test_usage_recorded_only_under_page_path_is_not_a_candidate(): void {
		$css = $this->a( 'style', 'x-css', 'x', '/wp-content/plugins/x/a.css' );
		$inv = [ 'shop' => [ 'guest' => $this->report( [ $css ] ) ] ];
		// inline <style> coverage is recorded under the page URL path, never the file's src path
		$c = FileCandidates::from( $inv, [ 'shop' => [ 'guest' => [ 'style' => [ '/loja/' => 0 ], 'script' => [] ] ] ], [] );
		$this->assertSame( [], $c );
	}
	public function test_protected_and_plugin_candidates_excluded(): void {
		$wc  = $this->a( 'style', 'wc-blocks-style', 'woocommerce', '/wp-content/plugins/woocommerce/a.css' );
		$bri = $this->a( 'script', 'bricks-scripts', '', '/wp-content/themes/bricks/a.js' );
		$gtm = $this->a( 'script', 'gtm4wp', 'duracelltomi-google-tag-manager', '/wp-content/plugins/duracelltomi-google-tag-manager/a.js' );
		$inv = [ 'front' => [ 'guest' => $this->report( [ $wc, $bri, $gtm ] ) ] ];
		$u = [ 'style' => [ '/wp-content/plugins/woocommerce/a.css' => 0 ], 'script' => [ '/wp-content/themes/bricks/a.js' => [ 'called' => 0, 'listeners' => 0 ], '/wp-content/plugins/duracelltomi-google-tag-manager/a.js' => [ 'called' => 0, 'listeners' => 0 ] ] ];
		$this->assertSame( [], FileCandidates::from( $inv, [ 'front' => [ 'guest' => $u ] ], [ 'front' => [ 'duracelltomi-google-tag-manager' ] ] ) );
	}
	public function test_merges_pages(): void {
		$css = $this->a( 'style', 'x-css', 'x', '/wp-content/plugins/x/a.css' );
		$u = [ 'style' => [ '/wp-content/plugins/x/a.css' => 0 ], 'script' => [] ];
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $css ] ) ], 'post' => [ 'guest' => $this->report( [ $css ] ) ] ];
		$c = FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $u ], 'post' => [ 'guest' => $u ] ], [] );
		$this->assertCount( 1, $c );
		$this->assertSame( [ 'blog', 'post' ], $c[0]['pages'] );
	}
	public function test_style_used_in_one_state_is_no_candidate(): void {
		$css = $this->a( 'style', 'x-css', 'x', '/wp-content/plugins/x/a.css' );
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $css ] ), 'logged_in' => $this->report( [ $css ] ) ] ];
		$z = [ 'style' => [ '/wp-content/plugins/x/a.css' => 0 ], 'script' => [] ];
		$u = [ 'style' => [ '/wp-content/plugins/x/a.css' => 40 ], 'script' => [] ];
		$this->assertSame( [], FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $z, 'logged_in' => $u ] ], [] ) );
	}
	public function test_script_called_or_with_listeners_in_one_state_is_no_candidate(): void {
		$js  = $this->a( 'script', 'x-js', 'x', '/wp-content/plugins/x/a.js' );
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $js ] ), 'logged_in' => $this->report( [ $js ] ) ] ];
		$mk  = fn( $c, $l ) => [ 'style' => [], 'script' => [ '/wp-content/plugins/x/a.js' => [ 'called' => $c, 'listeners' => $l ] ] ];
		$this->assertSame( [], FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $mk( 0, 0 ), 'logged_in' => $mk( 3, 0 ) ] ], [] ) );
		$this->assertSame( [], FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $mk( 0, 0 ), 'logged_in' => $mk( 0, 2 ) ] ], [] ) );
	}
	public function test_script_candidate_carries_load_only_caveat(): void {
		$js  = $this->a( 'script', 'x-js', 'x', '/wp-content/plugins/x/a.js' );
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $js ] ) ] ];
		$c = FileCandidates::from( $inv, [ 'blog' => [ 'guest' => [ 'style' => [], 'script' => [ '/wp-content/plugins/x/a.js' => [ 'called' => 0, 'listeners' => 0 ] ] ] ] ], [] );
		$this->assertSame( 'load_only', $c[0]['caveat'] );
		$this->assertStringNotContainsString( "clicks are not tested", $c[0]["why"]["blog"] );
	}
	public function test_asset_with_inline_output_is_no_candidate(): void {
		$css = $this->a( 'style', 'x-css', 'x', '/wp-content/plugins/x/a.css' ) + [ 'inline' => 120 ];
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $css ] ) ] ];
		$this->assertSame( [], FileCandidates::from( $inv, [ 'blog' => [ 'guest' => [ 'style' => [ '/wp-content/plugins/x/a.css' => 0 ], 'script' => [] ] ] ], [] ) );
	}
	public function test_unused_asset_with_used_dependent_is_no_candidate(): void {
		$icons = $this->a( 'style', 'x-icons', 'x', '/wp-content/plugins/x/i.css' );
		$front = [ 'deps' => [ 'x-icons' ] ] + $this->a( 'style', 'x-frontend', 'x', '/wp-content/plugins/x/f.css' );
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $icons, $front ] ) ] ];
		$u = [ 'style' => [ '/wp-content/plugins/x/i.css' => 0, '/wp-content/plugins/x/f.css' => 500 ], 'script' => [] ];
		$this->assertSame( [], FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $u ] ], [] ) );
	}
	public function test_unused_asset_and_unused_dependent_propose_only_the_root(): void {
		$icons = $this->a( 'style', 'x-icons', 'x', '/wp-content/plugins/x/i.css' );
		$front = [ 'deps' => [ 'x-icons' ] ] + $this->a( 'style', 'x-frontend', 'x', '/wp-content/plugins/x/f.css' );
		$inv = [ 'blog' => [ 'guest' => $this->report( [ $icons, $front ] ) ] ];
		$u = [ 'style' => [ '/wp-content/plugins/x/i.css' => 0, '/wp-content/plugins/x/f.css' => 0 ], 'script' => [] ];
		$c = FileCandidates::from( $inv, [ 'blog' => [ 'guest' => $u ] ], [] );
		$this->assertSame( [ 'style:x-icons' ], array_map( fn( $x ) => $x['target'], $c ) );
		$this->assertStringContainsString( 'x-frontend', $c[0]['why']['blog'] );
	}
}
