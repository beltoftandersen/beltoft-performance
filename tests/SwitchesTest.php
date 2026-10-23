<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Cleanup\Switches;

final class SwitchesTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['bp_test_options'] = [];
	}

	public function test_default_on_for_non_bricks_switch(): void {
		$this->assertTrue( Switches::enabled( 'dashicons' ) );
	}

	public function test_xmlrpc_switch_defaults_on_and_can_be_turned_off(): void {
		$this->assertTrue( Switches::enabled( 'xmlrpc' ) );
		$GLOBALS['bp_test_options'][ Switches::OPTION ] = [ 'xmlrpc' => false ];
		$this->assertFalse( Switches::enabled( 'xmlrpc' ) );
	}

	public function test_saved_value_overrides_default(): void {
		$GLOBALS['bp_test_options'][ Switches::OPTION ] = [ 'feeds' => false ];
		$this->assertFalse( Switches::enabled( 'feeds' ) );
		$this->assertTrue( Switches::enabled( 'heartbeat' ) );
	}

	public function test_bricks_handled_and_unknown_keys_are_never_enabled(): void {
		$GLOBALS['bp_test_options'][ Switches::OPTION ] = [ 'emojis' => true ];
		$this->assertFalse( Switches::enabled( 'emojis' ) );
		$this->assertFalse( Switches::enabled( 'nope' ) );
	}

	public function test_keep_heartbeat_true_cases(): void {
		$this->assertTrue( Switches::keep_heartbeat( true, 'post.php', array(), false ) );
		$this->assertTrue( Switches::keep_heartbeat( true, 'post-new.php', array(), false ) );
		$this->assertTrue( Switches::keep_heartbeat( false, 'index.php', array( 'bricks' => 'run' ), false ) );
		$this->assertTrue( Switches::keep_heartbeat( false, 'index.php', array(), true ) );
	}

	public function test_keep_heartbeat_false_cases(): void {
		$this->assertFalse( Switches::keep_heartbeat( false, 'index.php', array(), false ) );
		$this->assertFalse( Switches::keep_heartbeat( true, 'edit.php', array(), false ) );
	}

	public function test_script_switches_default_off(): void {
		$this->assertFalse( Switches::enabled( 'jquery_footer' ) );
		$this->assertFalse( Switches::enabled( 'gtm_delay' ) );
		$GLOBALS['bp_test_options'][ Switches::OPTION ] = [ 'gtm_delay' => true ];
		$this->assertTrue( Switches::enabled( 'gtm_delay' ) );
	}

	public function test_jquery_dependents_follow_the_chain(): void {
		$deps = [
			'jquery'        => [ 'jquery-core' ],
			'jquery-core'   => [],
			'wc-add-to-cart'=> [ 'jquery', 'wc-jquery-blockui' ],
			'wc-jquery-blockui' => [ 'jquery' ],
			'thing'         => [ 'wc-add-to-cart' ],
			'consent-mode'  => [],
			'loop-a'        => [ 'loop-b' ],
			'loop-b'        => [ 'loop-a' ],
		];
		$out = Switches::jquery_dependents( $deps );
		sort( $out );
		$this->assertSame( [ 'jquery', 'jquery-core', 'thing', 'wc-add-to-cart', 'wc-jquery-blockui' ], $out );
	}

	public function test_gtm_on_interaction_js(): void {
		$js = Switches::gtm_on_interaction_js( [ 'GTM-PRPM9VW', 'bad id', '' ], 'dataLayer' );
		$this->assertStringContainsString( '["GTM-PRPM9VW"]', $js );
		$this->assertStringNotContainsString( 'bad id', $js );
		$this->assertStringContainsString( 'setTimeout(load,10000)', $js );
		$this->assertSame( '', Switches::gtm_on_interaction_js( [ 'nope' ], 'dataLayer' ) );
		$this->assertStringContainsString( '"dataLayer"', Switches::gtm_on_interaction_js( [ 'GTM-A1' ], 'bad-name;' ) );
	}

	public function test_jquery_dependents_do_not_depend_on_order_inside_a_loop(): void {
		// a needs b and jQuery, b needs a: both move, whichever comes first.
		foreach ( [ [ 'a' => [ 'b', 'jquery' ], 'b' => [ 'a' ] ], [ 'b' => [ 'a' ], 'a' => [ 'b', 'jquery' ] ] ] as $deps ) {
			$out = Switches::jquery_dependents( $deps );
			sort( $out );
			$this->assertSame( [ 'a', 'b' ], $out );
		}
	}

	public function test_gtm_delay_applies_only_to_plain_browsing(): void {
		$o = [ 'gtm-code-placement' => 2, 'gtm-containers' => [ [ 'id' => 'GTM-A1', 'gtm_auth' => '', 'gtm_preview' => '', 'domain' => '', 'path' => '' ] ] ];
		$this->assertTrue( Switches::gtm_delay_applies( $o, false, [], 'production' ) );
		// Cart / checkout / order received: the purchase event must reach GTM even if the tab closes at once.
		$this->assertFalse( Switches::gtm_delay_applies( $o, true, [], 'production' ) );
		// Ad and campaign landings: the click id / UTM landing page must be recorded.
		foreach ( [ 'gclid', 'gbraid', 'wbraid', 'fbclid', 'msclkid', 'utm_source', 'UTM_campaign', '_gl' ] as $k ) {
			$this->assertFalse( Switches::gtm_delay_applies( $o, false, [ $k => 'x' ], 'production' ), $k );
		}
		$this->assertTrue( Switches::gtm_delay_applies( $o, false, [ 'm_categorias' => 'x', 'utm' => 'x' ], 'production' ) );
		// GTM4WP would not print its standard loader: leave it alone.
		$this->assertFalse( Switches::gtm_delay_applies( [ 'gtm-code-placement' => 3 ] + $o, false, [], 'production' ) );
		$this->assertFalse( Switches::gtm_delay_applies( [ 'gtm-production-only' => true ] + $o, false, [], 'staging' ) );
		$this->assertTrue( Switches::gtm_delay_applies( [ 'gtm-production-only' => true ] + $o, false, [], 'production' ) );
		$this->assertFalse( Switches::gtm_delay_applies( [ 'gtm-domain-name' => 'sgtm.example.com' ] + $o, false, [], 'production' ) );
		$c = $o; $c['gtm-containers'][0]['gtm_auth'] = 'abc';
		$this->assertFalse( Switches::gtm_delay_applies( $c, false, [], 'production' ) );
		$c = $o; $c['gtm-containers'][0]['path'] = '/gtag';
		$this->assertFalse( Switches::gtm_delay_applies( $c, false, [], 'production' ) );
	}

	public function test_gtm_snippet_guards_double_load_and_late_scripts(): void {
		$js = Switches::gtm_on_interaction_js( [ 'GTM-A1' ], 'dataLayer' );
		$this->assertStringContainsString( 'w.google_tag_manager[i]', $js );
		$this->assertStringContainsString( 'd.readyState==="complete"', $js );
		$this->assertSame( '', Switches::gtm_on_interaction_js( [ "GTM-A1\n" ], 'dataLayer' ) );
	}
}
