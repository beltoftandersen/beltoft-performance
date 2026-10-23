<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Recorder;
use BeltoftPerformance\Check\Trial;

final class TrialTest extends TestCase {
	public function test_content_hash_ignores_scripts_styles_links_only(): void {
		$a = '<html><head></head><body><p>Hello</p></body></html>';
		$b = '<html><head><link rel="stylesheet" href="/x.css"><style>.a{color:red}</style></head><body><p>Hello</p><script type="text/javascript">var a = "<b>";
</script></body></html>';
		$this->assertSame( Recorder::content_hash( $a ), Recorder::content_hash( $b ) );
		$this->assertNotSame( Recorder::content_hash( $a ), Recorder::content_hash( '<html><body><p>Adeus</p></body></html>' ) );
	}
	public function test_content_hash_ignores_per_render_random_ids(): void {
		$a = '<button data-interaction-id="f5a973">x</button><input id="form-field-1c3ba8" name="form-field-xbmcrr"><label for="quantity_6ac0af012a0ca">Q</label><input id="quantity_6ac0af012a0ca">';
		$b = '<button data-interaction-id="b6049e">x</button><input id="form-field-4a7c08" name="form-field-xbmcrr"><label for="quantity_6ac0af01a9d31">Q</label><input id="quantity_6ac0af01a9d31">';
		$this->assertSame( Recorder::content_hash( $a ), Recorder::content_hash( $b ) );
		$this->assertNotSame( Recorder::content_hash( $a ), Recorder::content_hash( str_replace( 'xbmcrr', 'other1', $a ) ) ); // stable field names still count
		$this->assertNotSame( Recorder::content_hash( $a ), Recorder::content_hash( str_replace( '>Q<', '>R<', $a ) ) );
	}
	private function base(): array { return [ 'status' => 200, 'errors' => [], 'html_hash' => 'h', 'plugins' => [ 'a', 'b' ], 'assets' => [] ]; }
	public function test_evaluate_requires_the_plugin_to_be_gone(): void {
		$t = $this->base();
		$this->assertNotEmpty( Trial::problem( $this->base(), $t, 'shop', 'unload:b' )['problem'] ?? '' );
		$t['plugins'] = [ 'a' ];
		$this->assertSame( [], Trial::problem( $this->base(), $t, 'shop', 'unload:b' ) );
		$t = $this->base(); $t['assets'] = [ [ 'plugin' => 'b' ] ];
		$this->assertNotEmpty( Trial::problem( $this->base(), $t, 'page', 'plugin:b' )['problem'] ?? '' );
		$t['assets'] = [];
		$this->assertSame( [], Trial::problem( $this->base(), $t, 'page', 'plugin:b' ) );
	}
	public function test_evaluate_status_errors_hash_and_sensitive(): void {
		$t = $this->base(); $t['plugins'] = [ 'a' ];
		$bad = $t; $bad['status'] = 500;
		$this->assertNotEmpty( Trial::problem( $this->base(), $bad, 'shop', 'unload:b' )['problem'] );
		$bad = $t; $bad['errors'] = [ 'e' ];
		$this->assertNotEmpty( Trial::problem( $this->base(), $bad, 'shop', 'unload:b' )['problem'] );
		unset( $bad['errors'] ); // missing key tolerated
		$this->assertSame( [], Trial::problem( $this->base(), $bad, 'shop', 'unload:b' ) );
		$bad = $t; $bad['html_hash'] = 'x';
		$this->assertNotEmpty( Trial::problem( $this->base(), $bad, 'shop', 'unload:b' )['problem'] );
		$this->assertTrue( Trial::problem( $this->base(), $t, 'checkout', 'unload:b' )['needs_browser'] );
	}
	public function test_report_name_matches_recorder(): void {
		$rule = [ 'target' => 'unload:b', 'pages' => [ 'shop' ], 'hosts' => [ 'retail' ] ];
		$this->assertSame( 'shop-guest-trial-' . substr( md5( json_encode( [ $rule ] ) ), 0, 8 ) . '.json', Trial::report_name( 'shop', 'guest', $rule ) );
	}
	public function test_cascade_to_other_plugins_handles_is_a_problem(): void {
		$base = $this->base();
		$base['assets'] = [
			[ 'kind' => 'script', 'handle' => 'b-js', 'plugin' => 'b' ],
			[ 'kind' => 'style', 'handle' => 'c-css', 'plugin' => 'c' ],
			[ 'kind' => 'script', 'handle' => 'wp-util', 'plugin' => '' ],
		];
		$t = $base; $t['plugins'] = [ 'a' ];
		$t['assets'] = [ [ 'kind' => 'style', 'handle' => 'c-css', 'plugin' => 'c' ], [ 'kind' => 'script', 'handle' => 'wp-util', 'plugin' => '' ], [ 'kind' => 'script', 'handle' => 'new', 'plugin' => 'z' ] ];
		$this->assertSame( [], Trial::problem( $base, $t, 'shop', 'unload:b' ) ); // only the target's own handle went
		$this->assertSame( [], Trial::gone_handles( $base, $t, 'b' ) );
		$t['assets'] = [];
		$this->assertSame( [ 'style:c-css', 'script:wp-util' ], Trial::gone_handles( $base, $t, 'b' ) );
		$p = Trial::problem( $base, $t, 'shop', 'unload:b' );
		$this->assertSame( 'Also removes files from other plugins: style:c-css, script:wp-util', $p['problem'] );
		$this->assertSame( [ 'style:c-css', 'script:wp-util' ], $p['gone'] );
	}
	public function test_printed_handles_of_a_plugin(): void {
		$inv = [
			'shop' => [ 'guest' => [ 'assets' => [ [ 'kind' => 'script', 'handle' => 'b-js', 'plugin' => 'b' ], [ 'kind' => 'style', 'handle' => 'x', 'plugin' => 'x' ] ] ], 'cart' => [ 'assets' => [ [ 'kind' => 'script', 'handle' => 'b-js', 'plugin' => 'b' ] ] ] ],
			'front' => [ 'guest' => [ 'assets' => [ [ 'kind' => 'style', 'handle' => 'b-css', 'plugin' => 'b' ] ] ] ],
			'cart' => [ 'guest' => [ 'assets' => [ [ 'kind' => 'style', 'handle' => 'b-cart', 'plugin' => 'b' ] ] ] ],
		];
		$this->assertSame( [ 'script:b-js', 'style:b-css' ], \BeltoftPerformance\Check\Runner::printed_handles( $inv, [ 'shop', 'front' ], 'b' ) );
	}
}
