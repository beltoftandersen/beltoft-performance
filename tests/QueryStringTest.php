<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Assets\Graph;
use BeltoftPerformance\Assets\Suppressor;
use BeltoftPerformance\Request\Classifier;
use BeltoftPerformance\Request\PageType;
use BeltoftPerformance\Rules\Rule;

require_once dirname( __DIR__ ) . '/mu/beltoft-performance-loader.php';
require_once __DIR__ . '/PageTypeTest.php'; // conditional-tag stubs

/** I1: a GET with query keys outside the allow-list is not the page type its path says. Loader, Suppressor and PageType agree. */
final class QueryStringTest extends TestCase {
	private array $routes = [ 'shop' => '/loja/', 'product_base' => '/loja/', 'cat' => '/categoria/', 'brand' => '/marca/', 'tag' => '/etiqueta/', 'cart' => '/carrinho-de-compras/', 'checkout' => '/finalizar/', 'order_received' => '/encomenda-recebida/', 'account' => '/a-minha-conta/', 'blog' => '/blog/', 'retail_host' => 'staging.mindthetrash.pt', 'query_keys' => [ 'm_categorias', 'm_pesquisar', 'q' ], 'checkout_keys' => [ 'pagar-encomenda', 'encomenda-recebida' ], 'account_keys' => [ 'ver-encomenda', 'encomendas' ] ];
	private string $file;
	private array $active = [ 'woocommerce/woocommerce.php', 'beltoft-performance/beltoft-performance.php', 'super-socializer/super_socializer.php' ];
	private $get;

	protected function setUp(): void {
		$this->get  = $_GET;
		$this->file = sys_get_temp_dir() . '/bp-qs-' . getmypid() . '.php';
		$unload     = [];
		foreach ( [ 'front', 'shop', 'checkout', 'account', 'cart', 'product', 'search' ] as $p ) {
			$unload['retail'][ $p ] = [ 'super-socializer' ];
		}
		file_put_contents( $this->file, '<?php return ' . var_export( [ 'v' => 1, 'routes' => $this->routes, 'unload' => $unload, 'unload_urls' => [ 'retail' => [ '/finalizar/' => [ 'super-socializer' ], '/a-minha-conta/' => [ 'super-socializer' ], '/' => [ 'super-socializer' ] ] ] ], true ) . ';' );
	}
	protected function tearDown(): void {
		@unlink( $this->file );
		$_GET = $this->get;
		$GLOBALS['bp_cond'] = [];
	}
	private function c( string $uri ): array {
		$r = Classifier::classify( $uri, [ 'HTTP_HOST' => 'staging.mindthetrash.pt', 'REQUEST_METHOD' => 'GET' ], $this->routes, true );
		return [ $r['type'], $r['page'] ];
	}
	private function loaded( string $uri ): bool {
		return in_array( 'super-socializer/super_socializer.php', beltoft_performance_filter_plugins( $this->active, $this->file, $uri, [ 'HTTP_HOST' => 'staging.mindthetrash.pt', 'REQUEST_METHOD' => 'GET' ], [] ), true );
	}

	public function test_unknown_query_keys_keep_all(): void {
		$this->assertSame( [ 'page', 'page_query' ], $this->c( '/?sfn_trk=1&sfn_data=x' ) );
		$this->assertSame( [ 'page', 'page_query' ], $this->c( '/?add-to-cart=1' ) );
		$this->assertSame( [ 'page', 'page_query' ], $this->c( '/loja/?foo=1' ) );
		$this->assertSame( [ 'page', 'page_query' ], $this->c( '/?p=12' ) );
	}
	public function test_woocommerce_endpoint_query_vars(): void {
		$this->assertSame( [ 'page', 'checkout_endpoint' ], $this->c( '/finalizar/?order-pay=12&pay_for_order=true' ) );
		$this->assertSame( [ 'page', 'checkout_endpoint' ], $this->c( '/finalizar/?pagar-encomenda=12&pay_for_order=true&key=wc_order_x' ) );
		$this->assertSame( [ 'page', 'checkout_endpoint' ], $this->c( '/finalizar/?order-received=12&key=wc_order_x' ) );
		$this->assertSame( [ 'page', 'account_endpoint' ], $this->c( '/a-minha-conta/?view-order=5' ) );
		$this->assertSame( [ 'page', 'account_endpoint' ], $this->c( '/a-minha-conta/?ver-encomenda=5' ) );
		$this->assertSame( [ 'page', 'account_endpoint' ], $this->c( '/a-minha-conta/?lost-password' ) );
		$this->assertSame( [ 'page', 'account_endpoint' ], $this->c( '/a-minha-conta/?add-payment-method' ) );
		// The order key is fine on the path-based order-received page.
		$this->assertSame( [ 'page', 'order_received' ], $this->c( '/finalizar/encomenda-recebida/123/?key=wc_order_x' ) );
		$this->assertSame( [ 'page', 'page_query' ], $this->c( '/loja/?key=x' ) );
	}
	public function test_allowed_keys_keep_the_page_type(): void {
		$this->assertSame( [ 'page', 'shop' ], $this->c( '/loja/?orderby=price' ) );
		$this->assertSame( [ 'page', 'shop' ], $this->c( '/loja/?orderby=price&order=asc&min_price=1&max_price=9&paged=2' ) );
		$this->assertSame( [ 'page', 'front' ], $this->c( '/?utm_source=x&utm_campaign=y&gclid=1&fbclid=2&msclkid=3' ) );
		$this->assertSame( [ 'page', 'shop' ], $this->c( '/loja/?filter_cor=azul&query_type_cor=or&product_cat=x&product_tag=y' ) );
		$this->assertSame( [ 'page', 'shop' ], $this->c( '/loja/?brx_oguwcf=1&m_categorias[]=x&bp_check=abc&nc=1' ) );
		$this->assertSame( [ 'page', 'search' ], $this->c( '/?s=escova&post_type=product&q=1' ) );
	}
	public function test_previews_are_not_pages(): void {
		foreach ( [ '/?bricks=run', '/loja/?bricks=preview', '/?preview=true&p=5', '/?customize_changeset_uuid=abc', '/?wp_customize=on' ] as $uri ) {
			$this->assertSame( 'preview', $this->c( $uri )[0], $uri );
		}
	}
	public function test_loader_keeps_every_plugin_on_query_and_endpoint_requests(): void {
		$this->assertFalse( $this->loaded( '/' ) );
		$this->assertFalse( $this->loaded( '/?utm_source=x' ) );
		$this->assertFalse( $this->loaded( '/loja/?orderby=price' ) );
		$this->assertTrue( $this->loaded( '/?sfn_trk=1&sfn_data=x' ) );
		$this->assertTrue( $this->loaded( '/finalizar/?order-pay=12&pay_for_order=true' ) ); // also beats url:/finalizar/
		$this->assertTrue( $this->loaded( '/a-minha-conta/?view-order=5' ) );                // also beats url:/a-minha-conta/
		$this->assertTrue( $this->loaded( '/?add-to-cart=1' ) );
		$this->assertTrue( $this->loaded( '/?bricks=run' ) );
	}
	public function test_suppressor_drops_nothing_off_rule_page_types(): void {
		$g     = new Graph( [ 'x' => [ 'src' => '/wp-content/plugins/super-socializer/x.css', 'deps' => [] ] ] );
		$rules = [ new Rule( 'style:x', [ 'url:/finalizar/', 'front' ], [ 'retail' ] ) ];
		$this->assertSame( [ 'x' ], Suppressor::select( $g, $rules, 'style', 'front', 'retail', '/' ) );
		$this->assertSame( [ 'x' ], Suppressor::select( $g, $rules, 'style', 'checkout', 'retail', '/finalizar/' ) );
		foreach ( [ 'page_query', 'checkout_endpoint', 'account_endpoint', 'preview' ] as $page ) {
			$this->assertSame( [], Suppressor::select( $g, $rules, 'style', $page, 'retail', '/finalizar/' ), $page );
		}
	}
	public function test_page_type_agrees_with_the_classifier(): void {
		$at = function ( array $cond, array $get ): string {
			$GLOBALS['bp_cond'] = $cond;
			$_GET               = $get;
			return PageType::current();
		};
		$this->assertSame( 'page_query', $at( [ 'is_front_page' => true ], [ 'sfn_trk' => '1', 'sfn_data' => 'x' ] ) );
		$this->assertSame( 'front', $at( [ 'is_front_page' => true ], [ 'utm_source' => 'x' ] ) );
		$this->assertSame( 'shop', $at( [ 'is_shop' => true ], [ 'orderby' => 'price' ] ) );
		$this->assertSame( 'checkout_endpoint', $at( [ 'is_checkout' => true ], [ 'order-pay' => '12', 'pay_for_order' => 'true' ] ) );
		$this->assertSame( 'account_endpoint', $at( [ 'is_account_page' => true ], [ 'view-order' => '5' ] ) );
		$this->assertSame( 'page_query', $at( [ 'is_front_page' => true ], [ 'add-to-cart' => '1' ] ) );
		$this->assertSame( 'preview', $at( [ 'is_front_page' => true ], [ 'bricks' => 'run' ] ) );
	}
}
