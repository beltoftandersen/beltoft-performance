<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Request\PageType;

// WordPress/WooCommerce conditional tags, driven by $GLOBALS['bp_cond'] (only PageType calls them in the suite).
foreach ( [ 'is_search', 'is_front_page', 'is_order_received_page', 'is_checkout', 'is_cart', 'is_account_page', 'is_shop', 'is_product', 'is_home' ] as $fn ) {
	if ( ! function_exists( $fn ) ) {
		eval( "function $fn() { return ! empty( \$GLOBALS['bp_cond']['$fn'] ); }" );
	}
}
if ( ! function_exists( 'is_tax' ) ) { function is_tax( $t = '' ) { return ! empty( $GLOBALS['bp_cond'][ 'is_tax:' . $t ] ); } }
if ( ! function_exists( 'is_singular' ) ) { function is_singular( $t = '' ) { return ! empty( $GLOBALS['bp_cond'][ 'is_singular:' . $t ] ); } }
if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
	function is_wc_endpoint_url( $e = false ) {
		$cur = $GLOBALS['bp_cond']['endpoint'] ?? '';
		return false === $e ? '' !== $cur : $cur === $e;
	}
}

final class PageTypeTest extends TestCase {
	private function type_at( array $cond ): string {
		$GLOBALS['bp_cond'] = $cond;
		return PageType::current();
	}
	protected function tearDown(): void { $GLOBALS['bp_cond'] = []; }
	public function test_endpoints_agree_with_classifier(): void {
		$this->assertSame( 'checkout', $this->type_at( [ 'is_checkout' => true ] ) );
		$this->assertSame( 'order_received', $this->type_at( [ 'is_checkout' => true, 'is_order_received_page' => true, 'endpoint' => 'order-received' ] ) );
		$this->assertSame( 'checkout_endpoint', $this->type_at( [ 'is_checkout' => true, 'endpoint' => 'order-pay' ] ) );
		$this->assertSame( 'account', $this->type_at( [ 'is_account_page' => true ] ) );
		$this->assertSame( 'account_endpoint', $this->type_at( [ 'is_account_page' => true, 'endpoint' => 'orders' ] ) );
		$this->assertSame( 'account_endpoint', $this->type_at( [ 'is_account_page' => true, 'endpoint' => 'lost-password' ] ) );
		$this->assertSame( 'cart', $this->type_at( [ 'is_cart' => true ] ) );
		$this->assertSame( 'page', $this->type_at( [] ) );
	}
}
