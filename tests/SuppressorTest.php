<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Assets\Graph;
use BeltoftPerformance\Assets\Suppressor;
use BeltoftPerformance\Rules\Rule;

final class SuppressorTest extends TestCase {
	private Graph $g;

	protected function setUp(): void {
		$this->g = new Graph( [
			'wc-settings'        => [ 'src' => '/wp-content/plugins/woocommerce/assets/client/blocks/wc-settings.js', 'deps' => [] ],
			'wc-blocks-registry' => [ 'src' => '/wp-content/plugins/woocommerce/assets/client/blocks/wc-blocks-registry.js', 'deps' => [ 'wc-settings' ] ],
			'wc-blocks-checkout' => [ 'src' => '/wp-content/plugins/woocommerce/x.js', 'deps' => [ 'wc-blocks-registry' ] ],
			'sweetalert2'        => [ 'src' => 'https://staging.mindthetrash.pt/wp-content/plugins/woo-smart-wishlist/sweetalert2.js', 'deps' => [] ],
			'bricks-scripts'     => [ 'src' => '/wp-content/themes/bricks/assets/js/bricks.min.js', 'deps' => [ 'wc-settings' ] ],
			'x-lib'              => [ 'src' => '/wp-content/plugins/x/lib.js', 'deps' => [] ],
			'bp-probe'           => [ 'src' => '/wp-content/plugins/beltoft-performance/assets/probe.js', 'deps' => [ 'x-lib' ] ],
		] );
	}

	public function test_locked_cascade_is_skipped_while_unrelated_rule_survives(): void {
		// Rule 1: targets x-lib; cascade includes bp-probe (the performance plugin's own file: locked), so rule 1 is skipped
		$rule1 = new Rule( 'script:x-lib', [ 'front' ], [ 'retail' ] );
		// Rule 2: targets wc-blocks-checkout; cascade is just itself
		$rule2 = new Rule( 'script:wc-blocks-checkout', [ 'front' ], [ 'retail' ] );

		$result = Suppressor::select( $this->g, [ $rule1, $rule2 ], 'script', 'front', 'retail' );

		// rule1 skipped (cascade has locked bp-probe); rule2 yields wc-blocks-checkout only
		$this->assertSame( [ 'wc-blocks-checkout' ], $result );
	}

	public function test_cascade_reaching_shop_core_and_bricks_now_applies(): void {
		$rule = new Rule( 'script:wc-settings', [ 'front' ], [ 'retail' ] );
		$this->assertSame( [ 'bricks-scripts', 'wc-blocks-checkout', 'wc-blocks-registry', 'wc-settings' ], Suppressor::select( $this->g, [ $rule ], 'script', 'front', 'retail' ) );
	}

	public function test_locked_plugin_rules_are_refused_shop_core_plugin_rules_apply(): void {
		$rule_bp = new Rule( 'plugin:beltoft-performance', [ 'front' ], [ 'retail' ] );
		$this->assertSame( [], Suppressor::select( $this->g, [ $rule_bp ], 'script', 'front', 'retail' ) ); // fails validation

		$rule_wc      = new Rule( 'plugin:woocommerce', [ 'front' ], [ 'retail' ] );
		$rule_beltoft = new Rule( 'plugin:beltoft-gift-cards', [ 'front' ], [ 'retail' ] );
		$result       = Suppressor::select( $this->g, [ $rule_wc, $rule_beltoft ], 'script', 'front', 'retail' );
		// Both validate now: WooCommerce's files go with their dependents (beltoft-gift-cards has none in this graph).
		$this->assertSame( [ 'bricks-scripts', 'wc-blocks-checkout', 'wc-blocks-registry', 'wc-settings' ], $result );
	}

	public function test_plugin_rule_yields_plugin_handles_and_dependents(): void {
		$rule = new Rule( 'plugin:woo-smart-wishlist', [ 'front' ], [ 'retail' ] );

		$result = Suppressor::select( $this->g, [ $rule ], 'script', 'front', 'retail' );

		// sweetalert2 belongs to woo-smart-wishlist and has no dependents
		$this->assertSame( [ 'sweetalert2' ], $result );
	}

	public function test_rule_for_different_page_yields_nothing(): void {
		$rule = new Rule( 'script:wc-blocks-checkout', [ 'shop' ], [ 'retail' ] );

		$result = Suppressor::select( $this->g, [ $rule ], 'script', 'front', 'retail' );

		$this->assertSame( [], $result );
	}

	public function test_rule_for_different_host_yields_nothing(): void {
		$rule = new Rule( 'script:wc-blocks-checkout', [ 'front' ], [ 'trade' ] );

		$result = Suppressor::select( $this->g, [ $rule ], 'script', 'front', 'retail' );

		$this->assertSame( [], $result );
	}

	public function test_script_rules_do_not_affect_style_kind(): void {
		$script_rule = new Rule( 'script:wc-blocks-checkout', [ 'front' ], [ 'retail' ] );

		// Request style handles (none in our graph, so result should be empty)
		$result = Suppressor::select( $this->g, [ $script_rule ], 'style', 'front', 'retail' );

		$this->assertSame( [], $result );
	}

	public function test_url_scope_matches_that_path_only(): void {
		$rule = new Rule( 'style:sweetalert2', [ 'url:/sobre-nos/' ], [ 'retail' ] );
		$this->assertSame( [ 'sweetalert2' ], Suppressor::select( $this->g, [ $rule ], 'style', 'page', 'retail', '/sobre-nos/', [] ) );
		$this->assertSame( [], Suppressor::select( $this->g, [ $rule ], 'style', 'page', 'retail', '/blog/', [] ) );
	}

	public function test_template_scope_matches_when_id_present(): void {
		$rule = new Rule( 'style:sweetalert2', [ 'template:150335' ], [ 'retail' ] );
		$this->assertSame( [ 'sweetalert2' ], Suppressor::select( $this->g, [ $rule ], 'style', 'product', 'retail', '/x/', [ 1, 150335 ] ) );
		$this->assertSame( [], Suppressor::select( $this->g, [ $rule ], 'style', 'product', 'retail', '/x/', [ 1 ] ) );
	}

	public function test_page_type_rule_unchanged_and_locked_still_blocked(): void {
		$rule = new Rule( 'style:sweetalert2', [ 'page' ], [ 'retail' ] );
		$this->assertSame( [ 'sweetalert2' ], Suppressor::select( $this->g, [ $rule ], 'style', 'page', 'retail', '/blog/', [] ) );
		$locked = new Rule( 'script:x-lib', [ 'url:/sobre-nos/' ], [ 'retail' ] );
		$this->assertSame( [], Suppressor::select( $this->g, [ $locked ], 'script', 'page', 'retail', '/sobre-nos/', [] ) );
		$core = new Rule( 'script:wc-settings', [ 'url:/sobre-nos/' ], [ 'retail' ] );
		$this->assertContains( 'wc-settings', Suppressor::select( $this->g, [ $core ], 'script', 'page', 'retail', '/sobre-nos/', [] ) );
	}
}
