<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\BrowserEvidence as E;

final class BrowserEvidenceTest extends TestCase {
	public function test_layout_changes_masks_noise(): void {
		$b  = [ 'div#a|0,0,100,20|block', 'div.slide|0,40,300,200|block', 'p#t|0,300,200,18|block' ];
		$b2 = [ 'div#a|0,0,100,20|block', 'div.slide|-300,40,300,200|block', 'p#t|0,300,200,18|block' ];
		$t  = [ 'div#a|0,0,100,20|block', 'div.slide|-600,40,300,200|block', 'p#t|0,300,200,22|block' ];
		$this->assertSame( [ 'p#t|0,300,200,18|block', 'p#t|0,300,200,22|block' ], E::layout_changes( $b, $b2, $t ) );
		$this->assertSame( [], E::layout_changes( $b, $b, $b ) );
	}
	public function test_coverage_by_src_sums_over_pages_and_strips_query(): void {
		$pages = [
			[ 'coverage' => [ 'styles' => [ [ 'url' => 'https://s/wp-content/plugins/x/a.css?ver=1', 'used' => 0, 'total' => 900 ] ], 'scripts' => [ [ 'url' => 'https://s/wp-content/plugins/x/a.js?ver=1', 'called' => 0, 'listeners' => 0 ] ] ] ],
			[ 'coverage' => [ 'styles' => [ [ 'url' => 'https://s/wp-content/plugins/x/a.css?ver=1', 'used' => 12, 'total' => 900 ] ], 'scripts' => [ [ 'url' => 'https://s/wp-content/plugins/x/a.js', 'called' => 2, 'listeners' => 1 ] ] ] ],
		];
		$c = E::coverage_by_src( $pages );
		$this->assertSame( 12, $c['style']['/wp-content/plugins/x/a.css'] );
		$this->assertSame( [ 'called' => 2, 'listeners' => 1 ], $c['script']['/wp-content/plugins/x/a.js'] );
	}
	public function test_flow_fact_changes(): void {
		$b = [ 'fields' => [ 'billing_first_name', 'billing_nif' ], 'shipping' => [ 'CTT', 'DPD Pickup' ], 'payment' => [ 'mbway', 'ppcp' ], 'cart_items' => 1 ];
		$t = [ 'fields' => [ 'billing_first_name', 'billing_nif' ], 'shipping' => [ 'CTT' ], 'payment' => [ 'mbway', 'ppcp' ], 'cart_items' => 1 ];
		$this->assertSame( [ 'shipping: -DPD Pickup' ], E::flow_fact_changes( $b, $t ) );
		$this->assertSame( [], E::flow_fact_changes( $b, $b ) );
	}
	public function test_flow_failed(): void {
		$this->assertTrue( E::flow_failed( [ 'flow_error' => 'add to cart failed', 'facts' => [ 'cart_items' => 0 ] ] ) );
		$this->assertTrue( E::flow_failed( [ 'flow_error' => '', 'facts' => [ 'cart_items' => 0 ] ] ) );
		$this->assertTrue( E::flow_failed( [] ) );
		$this->assertFalse( E::flow_failed( [ 'flow_error' => '', 'facts' => [ 'cart_items' => 1 ] ] ) );
	}
	public function test_layout_changes_key_collision_not_masked(): void {
		$b  = [ 'li.product@0|0,0,100,100|block', 'li.product@1|100,0,100,100|block' ];
		$b2 = [ 'li.product@0|-100,0,100,100|block', 'li.product@1|100,0,100,100|block' ];
		$t  = [ 'li.product@0|-200,0,100,100|block' ];
		$this->assertSame( [ 'li.product@1|100,0,100,100|block' ], E::layout_changes( $b, $b2, $t ) );
	}
	public function test_layout_failed(): void {
		$this->assertTrue( E::layout_failed( [ 'layout' => [], 'layout_error' => '' ] ) );
		$this->assertTrue( E::layout_failed( [ 'layout' => [ 'a|1|b' ], 'layout_error' => 'boom' ] ) );
		$this->assertTrue( E::layout_failed( [] ) );
		$this->assertFalse( E::layout_failed( [ 'layout' => [ 'a|1|b' ] ] ) );
	}
	public function test_layout_changes_ignore_sub_pixel_style_values(): void {
		$base  = array( 'section#h@0|0,30,1426,80|flex,rgba(0, 0, 0, 0) 0.00169027px 0.00169027px 0.0169027px 0px,Onest' );
		$trial = array( 'section#h@0|0,30,1426,80|flex,rgba(0, 0, 0, 0) 0.00171106px 0.00171106px 0.0171106px 0px,Onest' );
		$this->assertSame( array(), E::layout_changes( $base, $base, $trial ) );
		$moved = array( 'section#h@0|0,30,1426,80|flex,rgba(0, 0, 0, 0.3) 4px 4px 12px 0px,Onest' );
		$this->assertNotSame( array(), E::layout_changes( $base, $base, $moved ) );
	}
	public function test_layout_changes_treat_transparent_shadow_as_none(): void {
		$base  = array( 'section#h@0|0,0,1426,30|flex,rgb(38, 79, 55),none,Onest' );
		$trial = array( 'section#h@0|0,0,1426,30|flex,rgb(38, 79, 55),rgba(0, 0, 0, 0) 0.0017px 0.0017px 0.017px 0px,Onest' );
		$this->assertSame( array(), E::layout_changes( $base, $base, $trial ) );
		$shadow = array( 'section#h@0|0,0,1426,30|flex,rgb(38, 79, 55),rgba(0, 0, 0, 0.2) 0px 2px 8px 0px,Onest' );
		$this->assertNotSame( array(), E::layout_changes( $base, $base, $shadow ) );
		$bg = array( 'section#h@0|0,0,1426,30|flex,rgba(0, 0, 0, 0),15px,none,Onest' );
		$this->assertSame( $bg, array_values( array_intersect( E::layout_changes( array(), array(), $bg ), $bg ) ) );
	}
	public function test_layout_changes_treat_exponent_transparent_shadow_as_none(): void {
		$base  = array( 'section#h@0|0,0,1426,30|flex,none,Onest' );
		$trial = array( 'section#h@0|0,0,1426,30|flex,rgba(0, 0, 0, 0) 5.92711e-08px 5.92711e-08px 5.92711e-07px 0,Onest' );
		$this->assertSame( array(), E::layout_changes( $base, $base, $trial ) );
		$shadow = array( 'section#h@0|0,0,1426,30|flex,rgba(0, 0, 0, 0.2) 2e+00px 4px 8px 0,Onest' );
		$this->assertNotSame( array(), E::layout_changes( $base, $base, $shadow ) );
	}
	public function test_noisy_element_keeps_size_and_style_compared(): void {
		$b  = [ 'div.slide@0|0,40,300,200|block,red' ];
		$b2 = [ 'div.slide@0|-300,40,300,200|block,red' ];
		$this->assertSame( [], E::layout_changes( $b, $b2, [ 'div.slide@0|-600,40,300,200|block,red' ] ) ); // moved only: noise
		$this->assertNotSame( [], E::layout_changes( $b, $b2, [ 'div.slide@0|-600,40,300,260|block,red' ] ) ); // size changed
		$this->assertNotSame( [], E::layout_changes( $b, $b2, [ 'div.slide@0|-600,40,300,200|block,blue' ] ) ); // style changed
		$this->assertNotSame( [], E::layout_changes( $b, $b2, [] ) ); // gone
	}
	public function test_noise_ratio(): void {
		$b  = [ 'a@0|0,0,1,1|x', 'b@0|0,0,1,1|x', 'c@0|0,0,1,1|x', 'd@0|0,0,1,1|x' ];
		$b2 = [ 'a@0|0,0,1,1|x', 'b@0|9,0,1,1|x', 'c@0|0,0,1,1|x', 'd@0|0,0,1,1|x' ];
		$this->assertEqualsWithDelta( 0.25, E::noise_ratio( $b, $b2 ), 0.0001 );
		$this->assertSame( 0.0, E::noise_ratio( $b, $b ) );
		$this->assertSame( 0.0, E::noise_ratio( [], [] ) );
	}
}
