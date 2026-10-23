<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Assets\Graph;

final class GraphTest extends TestCase {
	private Graph $g;
	protected function setUp(): void {
		$this->g = new Graph( [
			'wc-settings'        => [ 'src' => '/wp-content/plugins/woocommerce/assets/client/blocks/wc-settings.js', 'deps' => [] ],
			'wc-blocks-registry' => [ 'src' => '/wp-content/plugins/woocommerce/assets/client/blocks/wc-blocks-registry.js', 'deps' => [ 'wc-settings' ] ],
			'wc-blocks-checkout' => [ 'src' => '/wp-content/plugins/woocommerce/x.js', 'deps' => [ 'wc-blocks-registry' ] ],
			'sweetalert2'        => [ 'src' => 'https://staging.mindthetrash.pt/wp-content/plugins/woo-smart-wishlist/sweetalert2.js', 'deps' => [] ],
			'cycle-a'            => [ 'src' => '', 'deps' => [ 'cycle-b' ] ],
			'cycle-b'            => [ 'src' => '//cdn.example/x.js', 'deps' => [ 'cycle-a' ] ],
			'bricks-scripts'     => [ 'src' => '/wp-content/themes/bricks/assets/js/bricks.min.js', 'deps' => [ 'wc-settings' ] ],
			'bp-probe'           => [ 'src' => '/wp-content/plugins/beltoft-performance/assets/probe.js', 'deps' => [ 'sweetalert2' ] ],
		] );
	}
	public function test_cascade_is_transitive(): void {
		$this->assertSame( [ 'bricks-scripts', 'wc-blocks-checkout', 'wc-blocks-registry', 'wc-settings' ], $this->g->cascade( [ 'wc-settings' ] ) );
	}
	public function test_cycles_terminate(): void {
		$this->assertSame( [ 'cycle-a', 'cycle-b' ], $this->g->cascade( [ 'cycle-a' ] ) );
	}
	public function test_owner_plugin_from_src_variants(): void {
		$this->assertSame( 'woo-smart-wishlist', $this->g->owner_plugin( 'https://staging.mindthetrash.pt/wp-content/plugins/woo-smart-wishlist/sweetalert2.js' ) );
		$this->assertSame( 'woocommerce', $this->g->owner_plugin( '/wp-content/plugins/woocommerce/x.js' ) );
		$this->assertSame( '', $this->g->owner_plugin( '//cdn.example/x.js' ) );
		$this->assertSame( '', $this->g->owner_plugin( '' ) );
	}
	public function test_handles_of_plugin(): void {
		$this->assertSame( [ 'sweetalert2' ], $this->g->handles_of_plugin( 'woo-smart-wishlist' ) );
	}
	public function test_locked_in_cascade_is_reported_shop_core_is_not(): void {
		$this->assertSame( [ 'bp-probe' ], $this->g->protected_in( $this->g->cascade( [ 'sweetalert2' ] ) ) ); // by src under /plugins/beltoft-performance/
		$this->assertSame( [ 'beltoft-performance-x' ], $this->g->protected_in( [ 'beltoft-performance-x', 'sweetalert2' ] ) ); // by handle
		// WooCommerce and Bricks files no longer stop a rule.
		$this->assertSame( [], $this->g->protected_in( $this->g->cascade( [ 'wc-settings' ] ) ) );
	}
}
