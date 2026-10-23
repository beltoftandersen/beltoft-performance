<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Recorder;
use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Plugin;
use BeltoftPerformance\Rules\PerfmattersImport;
use BeltoftPerformance\Rules\Store;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['bp_test_actions'][ $hook ][] = $cb; return true; }
}

/** Full review round 3: blanked files, Perfmatters import, dismissed candidates, recompile on page slug change. */
final class FullFix3Test extends TestCase {
	protected function setUp(): void {
		$GLOBALS['bp_test_options'] = [];
		$GLOBALS['bp_test_actions'] = [];
	}

	/* ---------------------------------------------------------------- I1a: Recorder honours the loader_src filters */

	public function test_blanked_src_is_not_printed(): void {
		$srcs   = [ 'keep' => 'https://x/wp-content/plugins/a/a.js?ver=1', 'gone' => 'https://x/wp-content/plugins/b/b.js', 'alias' => '', 'false' => 'https://x/c.js' ];
		$filter = static function ( $src, $handle ) {
			if ( 'gone' === $handle ) {
				return '';
			}
			return 'false' === $handle ? false : $src;
		};
		$out = Recorder::split_printed( $srcs, $filter );
		$this->assertSame( [ 'keep', 'alias' ], $out['printed'] ); // a handle without src (alias/inline) prints, as in WordPress
		$this->assertSame( [ 'gone', 'false' ], $out['blanked'] );
	}

	public function test_filter_never_called_for_alias(): void {
		$calls = [];
		Recorder::split_printed( [ 'alias' => '' ], static function ( $s, $h ) use ( &$calls ) { $calls[] = $h; return $s; } );
		$this->assertSame( [], $calls );
	}

	public function test_loader_src_like_wordpress(): void {
		$this->assertSame( 'https://x/wp-includes/js/a.js?ver=6.9', Recorder::loader_src( '/wp-includes/js/a.js', false, 'https://x', 'https://x/wp-content', '6.9' ) );
		$this->assertSame( 'https://x/wp-content/plugins/p/a.js?ver=2', Recorder::loader_src( 'https://x/wp-content/plugins/p/a.js', '2', 'https://x', 'https://x/wp-content', '6.9' ) );
		$this->assertSame( '//cdn/a.js', Recorder::loader_src( '//cdn/a.js', null, 'https://x', 'https://x/wp-content', '6.9' ) );
		$this->assertSame( 'https://x/a.js?a=1&ver=3', Recorder::loader_src( 'https://x/a.js?a=1', '3', 'https://x', '', '6.9' ) );
		$this->assertSame( '', Recorder::loader_src( '', '3', 'https://x', '', '6.9' ) );
	}

	/* ---------------------------------------------------------------- I1b: Perfmatters import */

	private const ALL = Store::PAGE_TYPES;

	private static function row( array $plan, string $source ): array {
		foreach ( $plan as $r ) {
			if ( $r['source'] === $source ) {
				return $r;
			}
		}
		throw new \RuntimeException( "no row $source" );
	}

	private static function staging_ctx(): array {
		return array(
			'posts'   => array(
				29     => array( 'scope' => 'url:/', 'page' => 'front', 'post_type' => 'page' ),
				54     => array( 'scope' => 'url:/carrinho-de-compras/', 'page' => 'cart', 'post_type' => 'page' ),
				56     => array( 'scope' => 'url:/a-minha-conta/', 'page' => 'account', 'post_type' => 'page' ),
				149197 => array( 'scope' => 'url:/finalizar/', 'page' => 'checkout', 'post_type' => 'page' ),
				160014 => array( 'scope' => 'url:/iniciar/', 'page' => 'page', 'post_type' => 'page' ),
			),
			'type_urls' => array( 'page' => array( 'url:/iniciar/', 'url:/sobre-nos/' ) ),
			'known'   => array(
				'script' => array(
					'sweetalert2'  => array( 'src' => '/wp-content/plugins/some-plugin/sweetalert2.js', 'plugin' => 'some-plugin', 'deps' => array() ),
					'react'        => array( 'src' => '/wp-includes/js/dist/vendor/react.min.js', 'plugin' => '', 'deps' => array( 'wp-polyfill' ) ),
					'wc-settings'  => array( 'src' => 'https://x/wp-content/plugins/woocommerce/assets/client/blocks/wc-settings.js', 'plugin' => 'woocommerce', 'deps' => array() ),
				),
				'style'  => array(
					'wp-block-library' => array( 'src' => '/wp-includes/css/dist/block-library/style.min.css', 'plugin' => '', 'deps' => array() ),
					'wc-blocks-style'  => array( 'src' => 'https://x/wp-content/plugins/woocommerce/assets/client/blocks/wc-blocks.css', 'plugin' => 'woocommerce', 'deps' => array() ),
				),
			),
			'plugins' => array( 'wpc-composite-products', 'flexible-shipping', 'super-socializer', 'woocommerce-follow-up-emails', 'pymntpl-paypal-woocommerce', 'woocommerce', 'some-plugin' ),
		);
	}

	public function test_staging_fixture(): void {
		$opt  = json_decode( (string) file_get_contents( __DIR__ . '/fixtures/perfmatters-staging.json' ), true );
		$plan = PerfmattersImport::plan( $opt, self::staging_ctx() );

		$r = self::row( $plan, 'plugins:wpc-composite-products' ); // everywhere except regex /kit/
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'regex', $r['note'] );

		$r = self::row( $plan, 'plugins:flexible-shipping' );
		$this->assertSame( [ 'import', 'plugin:flexible-shipping', self::ALL ], [ $r['action'], $r['target'], $r['scopes'] ] );

		$r = self::row( $plan, 'plugins:super-socializer' ); // except /iniciar/ (a page) and checkout (+ order received, same post)
		$this->assertSame( 'import', $r['action'] );
		$this->assertSame( array_merge( array_values( array_diff( self::ALL, [ 'page', 'checkout', 'order_received', 'search' ] ) ), [ 'url:/sobre-nos/' ] ), $r['scopes'] ); // round 4: the other pages stay off

		$this->assertSame( 'skip', self::row( $plan, 'plugins:woocommerce-follow-up-emails' )['action'] );
		$this->assertStringContainsString( 'Never removed', self::row( $plan, 'plugins:woocommerce-follow-up-emails' )['note'] );
		$this->assertStringContainsString( 'not installed', self::row( $plan, 'plugins:wp-loyalty-rules' )['note'] );

		$r = self::row( $plan, 'plugins:pymntpl-paypal-woocommerce' );
		$this->assertSame( [ 'import', [ 'url:/' ] ], [ $r['action'], $r['scopes'] ] );

		$r = self::row( $plan, 'css:wc-blocks-style' );
		$this->assertSame( [ 'skip', 'style:wc-blocks-style' ], [ $r['action'], $r['target'] ] );
		$this->assertStringContainsString( 'protected', $r['note'] );
		$this->assertSame( 'skip', self::row( $plan, 'js:wc-settings' )['action'] );

		$r = self::row( $plan, 'css:wp-block-library' );
		$this->assertSame( [ 'import', 'style:wp-block-library', self::ALL ], [ $r['action'], $r['target'], $r['scopes'] ] );
		$r = self::row( $plan, 'js:sweetalert2' );
		$this->assertSame( [ 'import', 'script:sweetalert2', self::ALL ], [ $r['action'], $r['target'], $r['scopes'] ] );

		$r = self::row( $plan, 'js:react' ); // only on a post that no longer exists
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( '#154732', $r['note'] );

		$r = self::row( $plan, 'js:cwginstock_js' );
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'Unknown', $r['note'] );
		$this->assertSame( 'skip', self::row( $plan, 'css:wc-blocks-vendors-style' )['action'] );
		$this->assertCount( 6 + 6 + 40, $plan ); // one row per disabled entry
	}

	private static function crafted_ctx(): array {
		return array(
			'posts'   => array(
				10 => array( 'scope' => 'url:/sobre/', 'page' => 'page', 'post_type' => 'page' ),
				12 => array( 'scope' => 'url:/produto/x/', 'page' => 'product', 'post_type' => 'product' ),
				13 => array( 'scope' => '', 'page' => '', 'post_type' => 'page' ), // published, but not a normal page
			),
			'type_urls' => array( 'page' => array( 'url:/sobre/' ) ),
			'known'   => array(
				'script' => array(
					'foo'     => array( 'src' => 'https://x/wp-content/plugins/foo/foo.js', 'plugin' => 'foo', 'deps' => array() ),
					'bar'     => array( 'src' => 'https://x/wp-content/plugins/foo/bar.js', 'plugin' => 'foo', 'deps' => array() ),
					'baz'     => array( 'src' => 'https://x/wp-content/plugins/foo/baz.js', 'plugin' => 'foo', 'deps' => array() ),
					'jquery'  => array( 'src' => '', 'plugin' => '', 'deps' => array( 'jquery-core' ) ),
					'gtm'     => array( 'src' => 'https://x/wp-content/plugins/duracelltomi-google-tag-manager/g.js', 'plugin' => 'duracelltomi-google-tag-manager', 'deps' => array() ),
					'lib'     => array( 'src' => 'https://x/wp-content/plugins/foo/lib.js', 'plugin' => 'foo', 'deps' => array() ),
					'lib-use' => array( 'src' => 'https://x/wp-content/plugins/foo/use.js', 'plugin' => 'foo', 'deps' => array( 'lib' ) ),
					'weird'   => array( 'src' => 'https://x/wp-content/plugins/foo/w.js', 'plugin' => 'foo', 'deps' => array() ),
				),
				'style'  => array(
					'foo-css'   => array( 'src' => 'https://x/wp-content/plugins/foo/foo.css', 'plugin' => 'foo', 'deps' => array() ),
					'dep-root'  => array( 'src' => 'https://x/wp-content/plugins/foo/root.css', 'plugin' => 'foo', 'deps' => array() ),
					'bricks-x'  => array( 'src' => 'https://x/wp-content/themes/bricks/x.css', 'plugin' => '', 'deps' => array( 'dep-root' ) ),
					'dashicons' => array( 'src' => '/wp-includes/css/dashicons.css', 'plugin' => '', 'deps' => array() ),
					'bp-root'   => array( 'src' => 'https://x/wp-content/plugins/foo/bp-root.css', 'plugin' => 'foo', 'deps' => array() ),
					'bp-admin'  => array( 'src' => 'https://x/wp-content/plugins/beltoft-performance/admin.css', 'plugin' => 'beltoft-performance', 'deps' => array( 'bp-root' ) ),
				),
			),
			'plugins' => array( 'foo', 'idle', 'pagey', 'woocommerce', 'duracelltomi-google-tag-manager' ),
		);
	}

	public function test_crafted_fixture(): void {
		$opt = array(
			'disabled' => array(
				'js'      => array(
					'foo'     => array( 'everywhere' => 1 ),
					'bar'     => array( 'post_types' => array( 'product', 'post', 'event' ) ),
					'baz'     => array( 'everywhere' => 1 ),
					'jquery'  => array( 'everywhere' => 1 ),
					'gtm'     => array( 'everywhere' => 1 ),
					'lib'     => array( 'current' => array( '12' ) ),
					'weird'   => array( 'user_status' => 'guests', 'device_type' => 'mobile' ),
				),
				'css'     => array(
					'foo-css'   => array( 'current' => array( '10', '11' ), '404' => 1 ),
					'dep-root'  => array( 'everywhere' => 1 ),
					'dashicons' => array( 'everywhere' => 1 ),
					'bp-root'   => array( 'everywhere' => 1 ),
				),
				'plugins' => array(
					'idle'        => array( 'everywhere' => 1 ),
					'woocommerce' => array( 'everywhere' => 1 ),
					'pagey'       => array( 'post_types' => array( 'page' ), 'current' => array( '13' ) ),
				),
				'themes'  => array( 'bricks' => array( 'everywhere' => 1 ) ),
			),
			'enabled'  => array(
				'js'      => array(
					'foo' => array( 'post_types' => array( 'product' ) ),
					'baz' => array( 'user_status' => 'logged_in' ),
				),
				'plugins' => array( 'idle' => array( 'current' => array( '12' ), '404' => 1 ) ),
			),
		);
		$plan = PerfmattersImport::plan( $opt, self::crafted_ctx() );

		$r = self::row( $plan, 'js:foo' ); // everywhere, except everything that can show products
		$this->assertSame( [ 'import', 'script:foo' ], [ $r['action'], $r['target'] ] );
		$this->assertSame( [ 'front', 'cart', 'checkout', 'order_received', 'account', 'blog', 'post', 'page' ], $r['scopes'] );

		$r = self::row( $plan, 'js:bar' );
		$this->assertSame( [ 'import', [ 'product', 'post' ] ], [ $r['action'], $r['scopes'] ] );
		$this->assertStringContainsString( 'event', $r['note'] );

		$r = self::row( $plan, 'js:baz' );
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'user_status', $r['note'] );

		$this->assertStringContainsString( 'protected', self::row( $plan, 'js:jquery' )['note'] );
		$this->assertStringContainsString( 'Never removed', self::row( $plan, 'js:gtm' )['note'] );

		$r = self::row( $plan, 'js:lib' ); // current post 12 -> its URL; the dependent goes with it
		$this->assertSame( [ 'import', [ 'url:/produto/x/' ] ], [ $r['action'], $r['scopes'] ] );
		$this->assertStringContainsString( 'lib-use', $r['note'] );

		$this->assertSame( 'skip', self::row( $plan, 'js:weird' )['action'] );

		$r = self::row( $plan, 'css:foo-css' );
		$this->assertSame( [ 'import', 'style:foo-css', [ 'url:/sobre/' ] ], [ $r['action'], $r['target'], $r['scopes'] ] );
		$this->assertStringContainsString( '#11', $r['note'] );
		$this->assertStringContainsString( '404', $r['note'] );

		$r = self::row( $plan, 'css:dep-root' ); // a Bricks dependent no longer stops the rule: it goes with it
		$this->assertSame( 'import', $r['action'] );
		$this->assertStringContainsString( 'Also removes (dependents): bricks-x', $r['note'] );

		$r = self::row( $plan, 'css:bp-root' ); // a dependent of the performance plugin itself (locked) still stops it
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'bp-admin', $r['note'] );

		$this->assertSame( 'skip', self::row( $plan, 'css:dashicons' )['action'] );

		$r = self::row( $plan, 'plugins:idle' );
		$this->assertSame( [ 'import', 'plugin:idle', array_values( array_diff( self::ALL, [ 'product', 'page' ] ) ) ], [ $r['action'], $r['target'], $r['scopes'] ] ); // round 4: the 404 exception keeps 'page'

		$this->assertStringContainsString( 'protected', self::row( $plan, 'plugins:woocommerce' )['note'] );

		$r = self::row( $plan, 'plugins:pagey' ); // page post type -> its published pages (round 4); post 13 is not a normal page
		$this->assertSame( [ 'import', 'plugin:pagey', [ 'url:/sobre/' ] ], [ $r['action'], $r['target'], $r['scopes'] ] );
		$this->assertStringContainsString( '#13', $r['note'] );

		$this->assertSame( 'skip', self::row( $plan, 'themes:bricks' )['action'] );
	}

	public function test_exception_on_unexpressible_post_skips(): void {
		$opt  = array( 'disabled' => array( 'js' => array( 'foo' => array( 'everywhere' => 1 ) ) ), 'enabled' => array( 'js' => array( 'foo' => array( 'current' => array( 13 ) ) ) ) );
		$r    = self::row( PerfmattersImport::plan( $opt, self::crafted_ctx() ), 'js:foo' );
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( '#13', $r['note'] );
	}

	public function test_post_ids_lists_every_current_id(): void {
		$opt = array(
			'disabled' => array( 'js' => array( 'a' => array( 'current' => array( 5, '6' ) ) ) ),
			'enabled'  => array( 'css' => array( 'b' => array( 'current' => array( '7', 'x' ) ) ) ),
		);
		$this->assertSame( [ 5, 6, 7 ], PerfmattersImport::post_ids( $opt ) );
		$this->assertSame( [], PerfmattersImport::plan( array(), self::crafted_ctx() ) );
	}

	public function test_switch_off_stores_the_import_reason(): void {
		$this->assertTrue( Store::switch_off( 'plugin:idle', [ 'shop' ], 'chris', [], PerfmattersImport::REASON ) );
		$this->assertSame( 'Imported from Perfmatters Script Manager.', Store::all()[0]->reason );
		$this->assertSame( [ 'shop' ], Store::all()[0]->manual_pages );
		$this->assertTrue( Store::switch_off( 'plugin:other', [ 'shop' ], 'chris', [] ) );
		$this->assertSame( 'Switched off by hand.', Store::find( 'plugin:other' )->reason );
	}

	/* ---------------------------------------------------------------- M2: dismissed candidates */

	public function test_dismissed_candidates_dropped(): void {
		$cands = [ [ 'target' => 'unload:a', 'pages' => [ 'shop' ] ], [ 'target' => 'style:b', 'pages' => [ 'blog' ] ] ];
		$this->assertSame( [ $cands[1] ], Runner::drop_dismissed( $cands, [ 'unload:a' => 1700000000 ] ) );
		$this->assertSame( $cands, Runner::drop_dismissed( $cands, [] ) );
	}

	/* ---------------------------------------------------------------- M3: recompile on a routed page's slug change */

	public function test_post_update_moves_routes(): void {
		$watched = [ 7, 9 ];
		$this->assertTrue( Plugin::post_update_moves_routes( 7, [ 'slug' => 'loja', 'parent' => 0 ], [ 'slug' => 'shop', 'parent' => 0 ], $watched ) );
		$this->assertTrue( Plugin::post_update_moves_routes( 9, [ 'slug' => 'blog', 'parent' => 0 ], [ 'slug' => 'blog', 'parent' => 3 ], $watched ) );
		$this->assertFalse( Plugin::post_update_moves_routes( 7, [ 'slug' => 'loja', 'parent' => 0 ], [ 'slug' => 'loja', 'parent' => 0 ], $watched ) );
		$this->assertFalse( Plugin::post_update_moves_routes( 8, [ 'slug' => 'a', 'parent' => 0 ], [ 'slug' => 'b', 'parent' => 0 ], $watched ) );
		$this->assertFalse( Plugin::post_update_moves_routes( 0, [ 'slug' => 'a', 'parent' => 0 ], [ 'slug' => 'b', 'parent' => 0 ], [ 0 ] ) );
	}

	public function test_post_updated_hook_registered(): void {
		Plugin::register_recompile();
		$this->assertSame( [ [ Plugin::class, 'on_post_updated' ] ], $GLOBALS['bp_test_actions']['post_updated'] ?? null );
	}
}
