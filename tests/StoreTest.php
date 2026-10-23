<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

final class StoreTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }
	public function test_valid_rule_is_stored_once(): void {
		$r = new Rule( 'unload:pymntpl-paypal-woocommerce', [ 'shop', 'archive_cat' ], [ 'retail' ], 'no PayPal markup' );
		$this->assertTrue( Store::add( $r ) );
		$this->assertTrue( Store::add( $r ) );
		$this->assertCount( 1, Store::all() );
		$this->assertSame( 'unload', Store::all()[0]->tier() );
	}
	public function test_locked_and_unknown_are_refused_shop_core_is_allowed(): void {
		$this->assertIsString( Store::add( new Rule( 'unload:beltoft-performance', [ 'shop' ] ) ) );
		$this->assertIsString( Store::add( new Rule( 'plugin:beltoft-performance', [ 'shop' ] ) ) );
		$this->assertIsString( Store::add( new Rule( 'style:beltoft-performance-admin', [ 'shop' ] ) ) );
		$this->assertIsString( Store::add( new Rule( 'script:x', [ 'nonsense_page' ] ) ) );
		$this->assertIsString( Store::add( new Rule( 'banana:x', [ 'shop' ] ) ) );
		$this->assertIsString( Store::add( new Rule( 'unload:../evil', [ 'shop' ] ) ) );
		$this->assertCount( 0, Store::all() );
		// Shop core is no longer locked: the owner's call (it is still never suggested, see is_protected_*).
		$this->assertTrue( Store::add( new Rule( 'unload:woocommerce', [ 'shop' ] ) ) );
		$this->assertTrue( Store::add( new Rule( 'unload:beltoft-loyalty-rewards', [ 'shop' ] ) ) );
		$this->assertTrue( Store::add( new Rule( 'script:jquery-core', [ 'shop' ], [ 'retail' ], '', '', '', [ 'shop' ] ) ) );
		$this->assertCount( 3, Store::all() );
		$this->assertTrue( Store::is_protected_plugin( 'woocommerce' ) );
		$this->assertTrue( Store::is_protected_handle( 'jquery-core', '' ) );
	}
	public function test_remove(): void {
		$r = new Rule( 'style:wc-blocks-style', [ 'product' ] );
		Store::add( $r );
		$this->assertTrue( Store::remove( $r->key() ) );
		$this->assertCount( 0, Store::all() );
	}
	public function test_protected_handle_by_src(): void {
		$this->assertTrue( Store::is_protected_handle( 'x', '/wp-content/themes/bricks/assets/js/bricks.min.js' ) );
		$this->assertTrue( Store::is_protected_handle( 'bricks-photoswipe', '' ) );
		$this->assertFalse( Store::is_protected_handle( 'sweetalert2', '/wp-content/plugins/x/sweetalert2.js' ) );
	}
	public function test_unload_on_post_is_refused_but_plugin_is_accepted(): void {
		$this->assertIsString( Store::add( new Rule( 'unload:super-socializer', [ 'post' ] ) ) );
		$this->assertTrue( Store::add( new Rule( 'plugin:super-socializer', [ 'post' ] ) ) );
		$this->assertCount( 1, Store::all() );
	}
	public function test_all_skips_corrupt_rows(): void {
		$GLOBALS['bp_test_options'][ Store::OPTION ] = [ 'x' => 'garbage', 'y' => null, 'z' => [ 'target' => [ 'no' ] ], 'n' => [ 'pages' => [ 'shop' ] ], 'ok' => ( new Rule( 'unload:super-socializer', [ 'shop' ] ) )->to_array() ];
		$all = Store::all();
		$this->assertCount( 1, $all );
		$this->assertSame( 'unload:super-socializer', $all[0]->target );
		$GLOBALS['bp_test_options'][ Store::OPTION ] = 'not-an-array';
		$this->assertCount( 0, Store::all() );
	}
	public function test_url_and_template_scopes_are_accepted_and_normalised(): void {
		$this->assertTrue( Store::add( new Rule( 'unload:super-socializer', [ 'url:/Sobre-Nos?x=1' ] ) ) );
		$this->assertTrue( Store::add( new Rule( 'style:wc-blocks-style', [ 'template:42', 'url:/a//b' ] ) ) );
		$all = Store::all();
		$this->assertSame( [ 'url:/sobre-nos/' ], $all[0]->pages );
		$this->assertSame( [ 'template:42', 'url:/a/b/' ], $all[1]->pages );
	}
	public function test_unload_with_template_is_refused(): void {
		$e = Store::add( new Rule( 'unload:super-socializer', [ 'template:42' ] ) );
		$this->assertSame( 'Plugins load before the template is known: use a page type or URL.', $e );
		$this->assertIsString( Store::add( new Rule( 'unload:super-socializer', [ 'shop', 'template:42' ] ) ) );
	}
	public function test_non_page_urls_and_bad_scopes_are_refused(): void {
		foreach ( [ 'url:/wp-admin/x/', 'url:/wp-json/a', 'url:', 'template:abc', 'template:0', 'template:', 'url:/wp-login.php' ] as $s ) {
			$this->assertIsString( Store::add( new Rule( 'style:x', [ $s ] ) ), $s );
		}
		$this->assertCount( 0, Store::all() );
	}
	public function test_normalise_scope(): void {
		$this->assertSame( 'url:/sobre-nos/', Store::normalise_scope( 'url:/Sobre-Nos?x=1' ) );
		$this->assertSame( 'url:/sobre-nos/', Store::normalise_scope( 'url://sobre-nos//' ) );
		$this->assertSame( 'url:/', Store::normalise_scope( 'url:/' ) );
		$this->assertSame( 'template:7', Store::normalise_scope( 'template:7' ) );
		$this->assertSame( 'shop', Store::normalise_scope( 'shop' ) );
		$this->assertNull( Store::normalise_scope( 'url:/wp-admin/x/' ) );
		$this->assertNull( Store::normalise_scope( 'template:-1' ) );
		$this->assertNull( Store::normalise_scope( 'banana' ) );
	}
	private const ROUTES = [ 'shop' => '/loja/', 'checkout' => '/finalizar/', 'order_received' => '/order-received/', 'account' => '/a-minha-conta/', 'cat' => '/categoria-produto/' ];
	/** @runInSeparateProcess */
	public function test_url_scope_normalises_under_wp_cli(): void {
		define( 'WP_CLI', true );
		$this->assertSame( 'url:/sobre-nos/', Store::normalise_scope( 'url:/sobre-nos/' ) );
		$this->assertTrue( Store::add( new Rule( 'unload:super-socializer', [ 'url:/Sobre-Nos' ] ) ) );
	}
	public function test_endpoint_urls_are_refused_with_real_routes(): void {
		foreach ( [ 'url:/finalizar/order-pay/5/', 'url:/a-minha-conta/orders/' ] as $s ) {
			$this->assertNull( Store::normalise_scope( $s, self::ROUTES ), $s );
		}
		$this->assertSame( 'url:/finalizar/', Store::normalise_scope( 'url:/finalizar/', self::ROUTES ) );
		$this->assertSame( 'url:/loja/x/', Store::normalise_scope( 'url:/loja/x/', self::ROUTES ) );
		$this->assertSame( 'url:/sobre/', Store::normalise_scope( 'url:/sobre/', self::ROUTES ) );
	}
	public function test_url_error_message_is_specific(): void {
		$m = 'This URL is not a normal page (admin, REST, login, checkout or account endpoint).';
		$this->assertSame( $m, Store::add( new Rule( 'unload:super-socializer', [ 'url:/wp-admin/x/' ] ) ) );
		$this->assertSame( $m, Store::add( new Rule( 'style:x', [ 'url:https://evil.example/a/' ] ) ) );
	}
	public function test_full_urls_keep_only_this_sites_path(): void {
		$this->assertSame( 'url:/sobre-nos/', Store::normalise_scope( 'url:https://staging.mindthetrash.pt/Sobre-Nos/?a=1', [] ) );
		$this->assertSame( 'url:/sobre-nos/', Store::normalise_scope( 'url:http://STAGING.mindthetrash.pt/sobre-nos', [] ) );
		$this->assertNull( Store::normalise_scope( 'url:https://other.example/sobre-nos/', [] ) );
	}
}
