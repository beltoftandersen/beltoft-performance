<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Request\Classifier;

final class ClassifierTest extends TestCase {
	private array $routes = [ 'shop' => '/loja/', 'product_base' => '/loja/', 'cat' => '/categoria/', 'brand' => '/marca/', 'tag' => '/etiqueta/', 'cart' => '/carrinho-de-compras/', 'checkout' => '/finalizar/', 'account' => '/a-minha-conta/', 'blog' => '/blog/', 'retail_host' => 'staging.mindthetrash.pt', 'query_keys' => [ 'm_categorias' ] ];
	private function page( string $uri, array $server = [] ): string {
		return Classifier::classify( $uri, $server + [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ], $this->routes )['page'];
	}
	private function type( string $uri, array $server = [] ): string {
		return Classifier::classify( $uri, $server + [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ], $this->routes )['type'];
	}
	public function test_page_types(): void {
		$this->assertSame( 'front', $this->page( '/' ) );
		$this->assertSame( 'shop', $this->page( '/loja/' ) );
		$this->assertSame( 'shop', $this->page( '/loja/page/3/' ) );
		$this->assertSame( 'shop', $this->page( '/loja/?orderby=price' ) );
		$this->assertSame( 'shop', $this->page( '/LOJA' ) );
		$this->assertSame( 'product', $this->page( '/loja/champo-solido/' ) );
		$this->assertSame( 'archive_cat', $this->page( '/categoria/cosmeticos/page/2/' ) );
		$this->assertSame( 'archive_brand', $this->page( '/marca/georganics/' ) );
		$this->assertSame( 'archive_tag', $this->page( '/etiqueta/vegano/' ) );
		$this->assertSame( 'search', $this->page( '/?s=escova' ) );
		$this->assertSame( 'search', $this->page( '/page/2/?s=escova&m_categorias[]=x' ) );
		$this->assertSame( 'cart', $this->page( '/carrinho-de-compras/' ) );
		$this->assertSame( 'checkout', $this->page( '/finalizar/' ) );
		$this->assertSame( 'order_received', $this->page( '/finalizar/order-received/123/?key=wc_order_x' ) );
		$this->assertSame( 'account_endpoint', $this->page( '/a-minha-conta/orders/' ) );
		$this->assertSame( 'blog', $this->page( '/blog/' ) );
		$this->assertSame( 'page', $this->page( '/mtt-club/' ) );
		$this->assertSame( 'page', $this->page( '/%E2%80%8B/weird/' ) );
	}
	public function test_request_types_never_page(): void {
		$this->assertSame( 'admin', $this->type( '/wp-admin/edit.php' ) );
		$this->assertSame( 'ajax', $this->type( '/wp-admin/admin-ajax.php' ) );
		$this->assertSame( 'ajax', $this->type( '/?wc-ajax=get_refreshed_fragments' ) );
		$this->assertSame( 'rest', $this->type( '/wp-json/wc/v3/products' ) );
		$this->assertSame( 'rest', $this->type( '/?rest_route=/wp/v2/posts' ) );
		$this->assertSame( 'cron', $this->type( '/wp-cron.php' ) );
		$this->assertSame( 'xmlrpc', $this->type( '/xmlrpc.php' ) );
		$this->assertSame( 'login', $this->type( '/wp-login.php' ) );
		$this->assertSame( 'page', $this->type( '/loja/' ) );
	}
	public function test_form_posts_and_webhooks_never_page(): void {
		$this->assertSame( 'form', $this->type( '/a-minha-conta/lost-password/', [ 'REQUEST_METHOD' => 'POST' ] ) );
		$this->assertSame( 'form', $this->type( '/finalizar/', [ 'REQUEST_METHOD' => 'post' ] ) );
		$this->assertSame( 'page', $this->type( '/loja/', [ 'REQUEST_METHOD' => 'GET' ] ) );
		$this->assertSame( 'page', $this->type( '/loja/', [ 'REQUEST_METHOD' => 'HEAD' ] ) );
		$this->assertSame( 'api', $this->type( '/?wc-api=WC_MultibancoIfthen_Webhook&chave=x' ) );
		$this->assertSame( 'api', $this->type( '/wc-api/wc_gateway_paypal/' ) );
		$this->assertSame( 'api', $this->type( '//wc-api/x' ) );
	}
	public function test_host(): void {
		$this->assertSame( 'retail', Classifier::classify( '/', [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ], $this->routes )['host'] );
		$this->assertSame( 'trade', Classifier::classify( '/', [ 'HTTP_HOST' => 'b2b.staging.mindthetrash.pt' ], $this->routes )['host'] );
	}
	public function test_double_slash_edge_cases(): void {
		$this->assertSame( 'ajax', $this->type( '//wp-admin/admin-ajax.php' ) );
		$this->assertSame( 'admin', $this->type( '//wp-admin/edit.php' ) );
		$this->assertSame( 'login', $this->type( '//wp-login.php' ) );
		$this->assertSame( 'rest', $this->type( '///wp-json/wc/v3/x' ) );
		$this->assertSame( 'shop', $this->page( '//loja//page/2/' ) );
	}
	public function test_empty_path_front(): void {
		$this->assertSame( 'front', $this->page( '' ) );
		$this->assertSame( 'front', $this->page( '?utm_source=1' ) );
		$this->assertSame( 'front', $this->page( '#x' ) );
	}
	public function test_dot_segments_resolve_like_nginx(): void {
		$this->assertNotSame( 'page', $this->type( '/loja/../wp-admin/admin-ajax.php' ) );
		$this->assertNotSame( 'page', $this->type( '/loja/../wp-login.php' ) );
		$this->assertNotSame( 'page', $this->type( '/%2e%2e/xmlrpc.php' ) );
		$this->assertNotSame( 'page', $this->type( '/loja/%2E%2E%2Fwp-admin%2fadmin-post.php' ) );
		$this->assertNotSame( 'page', $this->type( '/loja/./../wp-admin/admin-post.php' ) );
		$this->assertSame( 'shop', $this->page( '/loja/x/../' ) );
	}
	public function test_script_name_other_than_index_is_never_page(): void {
		$this->assertNotSame( 'page', $this->type( '/loja/', [ 'SCRIPT_NAME' => '/wp-admin/admin-ajax.php' ] ) );
		$this->assertNotSame( 'page', $this->type( '/loja/', [ 'SCRIPT_NAME' => '/wp-admin/admin-post.php' ] ) );
		$this->assertSame( 'shop', $this->page( '/loja/', [ 'SCRIPT_NAME' => '/index.php' ] ) );
		$this->assertSame( 'page', $this->type( '/loja/', [ 'SCRIPT_NAME' => '/index.php' ] ) );
	}
	public function test_request_path_is_shared_normalisation(): void {
		$this->assertSame( '/loja/', Classifier::request_path( '/LOJA//x/..?bp_check=1#f' ) );
		$this->assertSame( '/xmlrpc.php/', Classifier::request_path( '/%2e%2e/xmlrpc.php' ) );
		$this->assertSame( '/', Classifier::request_path( '' ) );
	}
	public function test_checkout_and_account_endpoints_get_their_own_types(): void {
		$this->assertSame( 'checkout', $this->page( '/finalizar/' ) );
		$this->assertSame( 'checkout', $this->page( '/finalizar/?utm_source=x' ) );
		$this->assertSame( 'checkout_endpoint', $this->page( '/finalizar/order-pay/123/?pay_for_order=true&key=x' ) );
		$this->assertSame( 'checkout_endpoint', $this->page( '/finalizar/add-payment-method/' ) );
		$this->assertSame( 'order_received', $this->page( '/finalizar/order-received/123/' ) );
		$this->assertSame( 'account', $this->page( '/a-minha-conta/' ) );
		$this->assertSame( 'account_endpoint', $this->page( '/a-minha-conta/view-order/9/' ) );
		$this->assertSame( 'account_endpoint', $this->page( '/a-minha-conta/lost-password/' ) );
		// A translated order-received endpoint comes from the route map.
		$routes = $this->routes + [ 'order_received' => '/encomenda-recebida/' ];
		$this->assertSame( 'order_received', Classifier::classify( '/finalizar/encomenda-recebida/5/?key=k', [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ], $routes )['page'] );
		$this->assertSame( 'checkout_endpoint', Classifier::classify( '/finalizar/order-pay/5/', [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ], $routes )['page'] );
	}
	public function test_endpoint_types_are_never_rule_targets(): void {
		foreach ( [ 'checkout_endpoint', 'account_endpoint' ] as $t ) {
			$this->assertNotContains( $t, \BeltoftPerformance\Rules\Store::PAGE_TYPES );
			$this->assertIsString( \BeltoftPerformance\Rules\Store::validate( new \BeltoftPerformance\Rules\Rule( 'unload:super-socializer', [ $t ] ) ) );
		}
	}
}
