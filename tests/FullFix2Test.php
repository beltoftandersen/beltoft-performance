<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Check\ContentSignatures;
use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Cleanup\Switches;
use BeltoftPerformance\Plugin;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $cb, $prio = 10, $args = 1 ) { $GLOBALS['bp_test_actions'][ $hook ][] = $cb; return true; }
}

/** Full review round 2: merge on add, covered candidates, accept text, dismiss undo, scan cache key, SSO pattern, recompile hooks, HPOS heartbeat. */
final class FullFix2Test extends TestCase {
	protected function setUp(): void {
		$GLOBALS['bp_test_options'] = [];
		$GLOBALS['bp_test_actions'] = [];
	}

	/* ---------------------------------------------------------------- 5: Store::add merges */

	public function test_add_merges_same_target_and_hosts_into_one_row(): void {
		$this->assertTrue( Store::add( new Rule( 'plugin:idle', [ 'page' ], [ 'retail' ], 'later', 'bob', '2026-10-04T10:00:00+00:00' ) ) );
		$this->assertTrue( Store::add( new Rule( 'plugin:idle', [ 'post', 'page' ], [ 'retail' ], 'earlier', 'chris', '2026-10-03T10:00:00+00:00' ) ) );
		$all = Store::all();
		$this->assertCount( 1, $all );
		$this->assertSame( [ 'page', 'post' ], $all[0]->pages );
		$this->assertSame( 'chris', $all[0]->approved_by );
		$this->assertSame( '2026-10-03T10:00:00+00:00', $all[0]->approved_at );
		$this->assertCount( 1, (array) get_option( Store::OPTION ) );
	}

	public function test_add_keeps_older_approver_and_unions_manual_pages(): void {
		$this->assertTrue( Store::switch_off( 'unload:idle', [ 'shop' ], 'chris', [] ) );
		$this->assertTrue( Store::add( new Rule( 'unload:idle', [ 'blog' ], [ 'retail' ], 'proven', 'bob', gmdate( 'c', time() + 60 ) ) ) );
		$all = Store::all();
		$this->assertCount( 1, $all );
		$this->assertSame( [ 'shop', 'blog' ], $all[0]->pages );
		$this->assertSame( [ 'shop' ], $all[0]->manual_pages );
		$this->assertSame( 'chris', $all[0]->approved_by );
	}

	public function test_other_hosts_stay_separate(): void {
		Store::add( new Rule( 'unload:idle', [ 'shop' ], [ 'retail' ] ) );
		Store::add( new Rule( 'unload:idle', [ 'blog' ], [ 'retail', 'trade' ] ) );
		$this->assertCount( 2, Store::all() );
	}

	public function test_find_and_switch_on_work_on_a_merged_scope(): void {
		Store::add( new Rule( 'unload:idle', [ 'shop' ], [ 'retail' ], 'a', 'chris', '2026-10-03T10:00:00+00:00' ) );
		Store::add( new Rule( 'unload:idle', [ 'blog', 'front' ], [ 'retail' ], 'b', 'chris', '2026-10-04T10:00:00+00:00' ) );
		$found = Store::find( 'unload:idle' );
		$this->assertNotNull( $found );
		$this->assertSame( [ 'shop', 'blog', 'front' ], $found->pages );
		$this->assertTrue( Store::switch_on( 'unload:idle', [ 'blog' ] ) );
		$all = Store::all();
		$this->assertCount( 1, $all );
		$this->assertSame( [ 'shop', 'front' ], $all[0]->pages );
	}

	public function test_merge_is_pure_and_unions_reasons(): void {
		$m = Store::merge( new Rule( 'style:x', [ 'shop' ], [ 'retail' ], 'r1 · r2', 'a', '' ), new Rule( 'style:x', [ 'blog' ], [ 'retail' ], 'r2 · r3', 'b', '2026-10-04T00:00:00+00:00' ) );
		$this->assertSame( [ 'shop', 'blog' ], $m->pages );
		$this->assertSame( 'r1 · r2 · r3', $m->reason );
		$this->assertSame( 'b', $m->approved_by ); // an empty date is not "earlier"
	}

	/* ---------------------------------------------------------------- 2: covered candidates */

	public function test_candidates_drop_scopes_a_retail_rule_covers(): void {
		$cands = [
			[ 'target' => 'plugin:idle', 'pages' => [ 'page', 'post' ], 'why' => [ 'page' => 'a', 'post' => 'b' ] ],
			[ 'target' => 'style:x', 'pages' => [ 'shop' ], 'why' => [ 'shop' => 'c' ] ],
			[ 'target' => 'unload:other', 'pages' => [ 'shop' ], 'why' => [ 'shop' => 'd' ] ],
		];
		$rules = [ new Rule( 'plugin:idle', [ 'page' ] ), new Rule( 'style:x', [ 'shop' ] ), new Rule( 'unload:other', [ 'shop' ], [ 'trade' ] ) ];
		$out   = Runner::drop_covered( $cands, $rules );
		$this->assertCount( 2, $out );
		$this->assertSame( 'plugin:idle', $out[0]['target'] );
		$this->assertSame( [ 'post' ], $out[0]['pages'] );
		$this->assertSame( [ 'post' => 'b' ], $out[0]['why'] );
		$this->assertSame( 'unload:other', $out[1]['target'] ); // a trade-only rule does not cover retail
	}

	public function test_suggestion_is_null_where_a_rule_already_covers_the_scope(): void {
		$c = [ 'plugin:idle' => [ 'target' => 'plugin:idle', 'pages' => [ 'page', 'post' ] ] ];
		$this->assertNotNull( Ui::suggestion( 'plugin:idle', $c, [], 'c1', 'page' ) );
		$this->assertNull( Ui::suggestion( 'plugin:idle', $c, [], 'c1', 'page', [ new Rule( 'plugin:idle', [ 'page' ] ) ] ) );
	}

	/* ---------------------------------------------------------------- 6: dismiss undo */

	public function test_dismissed_rows_and_restore(): void {
		$rej   = [ 'style:a' => 1700000000 ];
		$pages = [ 'unload:b' => [ 'shop', 'blog' ], 'style:c' => [] ];
		$rows  = Ui::dismissed_rows( $rej, $pages );
		$this->assertSame( [
			[ 'target' => 'style:a', 'page' => '', 'label' => 'everywhere' ],
			[ 'target' => 'unload:b', 'page' => 'blog', 'label' => 'Blog' ],
			[ 'target' => 'unload:b', 'page' => 'shop', 'label' => 'Shop' ],
		], $rows );
		[ $r2, $p2 ] = Ui::restore_dismissed( $rej, $pages, 'unload:b', 'shop' );
		$this->assertSame( $rej, $r2 );
		$this->assertSame( [ 'unload:b' => [ 'blog' ] ], $p2 );
		[ $r3, $p3 ] = Ui::restore_dismissed( $rej, $p2, 'unload:b', 'blog' );
		$this->assertSame( [], $p3 );
		[ $r4 ] = Ui::restore_dismissed( $rej, $p3, 'style:a', '' );
		$this->assertSame( [], $r4 );
		[ $r5, $p5 ] = Ui::restore_dismissed( $rej, $pages, 'nope', 'shop' );
		$this->assertSame( $rej, $r5 );
	}

	/* ---------------------------------------------------------------- 4: scan cache key */

	public function test_scan_cache_key(): void {
		$k = ContentSignatures::cache_key( '0.1 123', [ 'b/b.php', 'a/a.php' ] );
		$this->assertSame( $k, ContentSignatures::cache_key( '0.1 123', [ 'a/a.php', 'b/b.php' ] ) );
		$this->assertNotSame( $k, ContentSignatures::cache_key( '0.1 124', [ 'a/a.php', 'b/b.php' ] ) );
		$this->assertNotSame( $k, ContentSignatures::cache_key( '0.1 123', [ 'a/a.php' ] ) );
		$this->assertStringStartsWith( 'beltoft_performance_sigs_', $k );
		$this->assertLessThanOrEqual( 172, strlen( $k ) );
	}

	/* ---------------------------------------------------------------- 7: SSO pattern */

	public function test_sso_pattern_does_not_match_expresso(): void {
		$this->assertNull( Store::never_accept_reason( 'ctt-expresso-para-woocommerce' ) );
		$this->assertNull( Store::never_accept_reason( 'espresso' ) );
		$this->assertNotNull( Store::never_accept_reason( 'wp-sso' ) );
		$this->assertNotNull( Store::never_accept_reason( 'sso_login' ) );
		$this->assertNotNull( Store::never_accept_reason( 'sso' ) );
		$this->assertNotNull( Store::never_accept_reason( 'miniorange-sso-client' ) );
		$this->assertNotNull( Store::never_accept_reason( 'daggerhart-openid-connect-generic-old' ) );
	}

	/* ---------------------------------------------------------------- 9: recompile hooks */

	public function test_recompile_hooks(): void {
		$want = [
			'update_option_woocommerce_permalinks',
			'permalink_structure_changed',
			'update_option_page_on_front',
			'update_option_page_for_posts',
			'update_option_woocommerce_shop_page_id',
			'update_option_woocommerce_cart_page_id',
			'update_option_woocommerce_checkout_page_id',
			'update_option_woocommerce_myaccount_page_id',
		];
		$this->assertSame( $want, Plugin::RECOMPILE_HOOKS );
		Plugin::register_recompile();
		foreach ( $want as $h ) {
			$this->assertSame( [ [ Plugin::class, 'queue_recompile' ] ], $GLOBALS['bp_test_actions'][ $h ] ?? null, $h );
		}
	}

	/* ---------------------------------------------------------------- 10: HPOS edit lock */

	public function test_heartbeat_kept_on_hpos_order_edit(): void {
		$this->assertTrue( Switches::keep_heartbeat( true, 'admin.php', [ 'page' => 'wc-orders', 'action' => 'edit' ], false ) );
		$this->assertFalse( Switches::keep_heartbeat( true, 'admin.php', [ 'page' => 'wc-orders' ], false ) );
		$this->assertFalse( Switches::keep_heartbeat( true, 'admin.php', [ 'page' => 'other', 'action' => 'edit' ], false ) );
		$this->assertFalse( Switches::keep_heartbeat( false, 'admin.php', [ 'page' => 'wc-orders', 'action' => 'edit' ], false ) );
	}
}
