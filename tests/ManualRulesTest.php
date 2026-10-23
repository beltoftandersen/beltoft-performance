<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

final class ManualRulesTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	private static function inv(): array {
		$a = static fn( $k, $h, $src, $plugin ) => [ 'kind' => $k, 'handle' => $h, 'src' => $src, 'plugin' => $plugin ];
		return [ 'blog' => [ 'guest' => [ 'assets' => [
			$a( 'script', 'x-js', '/wp-content/plugins/x/a.js', 'x' ),
			$a( 'style', 'x-css', '/wp-content/plugins/x/a.css', 'x' ),
			$a( 'style', 'woocommerce-general', '/wp-content/plugins/woocommerce/a.css', 'woocommerce' ),
			$a( 'style', 'theme-thing', '/wp-content/themes/bricks/a.css', '' ),
			$a( 'style', 'bp-admin', '/wp-content/plugins/beltoft-performance/assets/admin.css', 'beltoft-performance' ),
			$a( 'script', 'beltoft-performance-x', '/wp-content/plugins/elsewhere/x.js', '' ),
		] ] ] ];
	}

	public function test_manual_script_accepted_only_when_all_scopes_manual(): void {
		$this->assertIsString( Store::add( new Rule( 'script:x-js', [ 'blog' ] ) ) );
		$this->assertIsString( Store::add( new Rule( 'script:x-js', [ 'blog', 'shop' ], [ 'retail' ], '', 'c', '', [ 'shop' ] ) ) );
		$this->assertTrue( Store::add( new Rule( 'script:x-js', [ 'blog' ], [ 'retail' ], '', 'c', '', [ 'blog' ] ) ) );
		$this->assertTrue( Store::all()[0]->is_manual() );
	}

	public function test_manual_locked_refused_shop_core_allowed(): void {
		foreach ( [ 'unload:beltoft-performance', 'plugin:beltoft-performance', 'style:beltoft-performance-admin' ] as $t ) {
			$this->assertIsString( Store::add( new Rule( $t, [ 'shop' ], [ 'retail' ], '', '', '', [ 'shop' ] ) ) );
		}
		$this->assertCount( 0, Store::all() );
		foreach ( [ 'unload:woocommerce', 'style:bricks-frontend', 'unload:beltoft-x' ] as $t ) {
			$this->assertTrue( Store::add( new Rule( $t, [ 'shop' ], [ 'retail' ], '', '', '', [ 'shop' ] ) ), $t );
		}
		$this->assertCount( 3, Store::all() );
	}

	public function test_old_rows_not_manual_and_key_unchanged(): void {
		$r = Rule::from_array( [ 'target' => 'style:x', 'pages' => [ 'shop' ] ] );
		$this->assertFalse( $r->is_manual() );
		$m = new Rule( 'style:x', [ 'shop' ], [ 'retail' ], '', '', '', [ 'shop' ] );
		$this->assertSame( $r->key(), $m->key() );
		$this->assertSame( [ 'shop' ], Rule::from_array( $m->to_array() )->manual_pages );
	}

	public function test_without_scopes_drops_manual_pages(): void {
		$r = new Rule( 'style:x', [ 'shop', 'blog' ], [ 'retail' ], 'why', 'c', 'd', [ 'blog' ] );
		$o = Store::without_scopes( $r, [ 'blog' ] );
		$this->assertSame( [ 'shop' ], $o->pages );
		$this->assertFalse( $o->is_manual() );
		$this->assertNull( Store::without_scopes( $o, [ 'shop' ] ) );
	}

	public function test_manual_file_error(): void {
		$i = self::inv();
		$this->assertNull( Store::manual_file_error( 'style', 'x-css', $i ) );
		$this->assertNull( Store::manual_file_error( 'script', 'x-js', $i ) );
		$this->assertNull( Store::manual_file_error( 'style', 'woocommerce-general', $i ) );
		$this->assertNull( Store::manual_file_error( 'style', 'theme-thing', $i ) );
		$locked = 'This file belongs to the performance plugin itself and stays on.';
		$this->assertSame( $locked, Store::manual_file_error( 'style', 'bp-admin', $i ) ); // by owner and src
		$this->assertSame( $locked, Store::manual_file_error( 'script', 'beltoft-performance-x', $i ) ); // by handle
		$this->assertSame( 'Unknown file: load a page that prints it first.', Store::manual_file_error( 'style', 'nope', $i ) );
		$this->assertSame( 'Unknown file: load a page that prints it first.', Store::manual_file_error( 'script', 'x-css', $i ) );
	}

	public function test_switch_off_refuses_locked_and_unknown_files_allows_shop_core(): void {
		$this->assertIsString( Store::switch_off( 'style:bp-admin', [ 'blog' ], 'c', self::inv() ) );
		$this->assertIsString( Store::switch_off( 'style:nope', [ 'blog' ], 'c', self::inv() ) );
		$this->assertCount( 0, Store::all() );
		$this->assertTrue( Store::switch_off( 'style:woocommerce-general', [ 'blog' ], 'c', self::inv() ) );
		$this->assertTrue( Store::switch_off( 'style:theme-thing', [ 'blog' ], 'c', self::inv() ) );
		$this->assertCount( 2, Store::all() );
	}

	public function test_switch_off_on_roundtrip(): void {
		$i = self::inv();
		$this->assertTrue( Store::switch_off( 'script:x-js', [ 'shop' ], 'claude', $i ) );
		$this->assertSame( 'Switched off by hand.', Store::all()[0]->reason );
		$this->assertTrue( Store::switch_off( 'script:x-js', [ 'blog' ], 'claude', $i ) );
		$this->assertSame( [ 'shop', 'blog' ], Store::all()[0]->pages );
		$this->assertTrue( Store::switch_on( 'script:x-js', [ 'shop' ] ) );
		$this->assertSame( [ 'blog' ], Store::all()[0]->pages );
		$this->assertTrue( Store::switch_on( 'script:x-js', [ 'blog' ] ) );
		$this->assertCount( 0, Store::all() );
		$this->assertSame( 'No such rule.', Store::switch_on( 'script:x-js', [ 'blog' ] ) );
	}

	public function test_merge_keeps_approver_and_marks_only_manual_scopes(): void {
		Store::add( new Rule( 'style:x-css', [ 'shop' ], [ 'retail' ], 'proof', 'alice', '2026-01-01T00:00:00+00:00' ) );
		$this->assertTrue( Store::switch_off( 'style:x-css', [ 'blog' ], 'claude', self::inv() ) );
		$r = Store::all()[0];
		$this->assertSame( 'alice', $r->approved_by );
		$this->assertSame( '2026-01-01T00:00:00+00:00', $r->approved_at );
		$this->assertSame( 'proof', $r->reason );
		$this->assertSame( [ 'blog' ], $r->manual_pages );
		$this->assertSame( [ 'shop', 'blog' ], $r->pages );
		// already present scope: no change
		$before = $GLOBALS['bp_test_options'];
		$this->assertTrue( Store::switch_off( 'style:x-css', [ 'shop' ], 'claude', self::inv() ) );
		$this->assertSame( $before, $GLOBALS['bp_test_options'] );
		// switching the manual scope back on leaves a non-manual rule
		$this->assertTrue( Store::switch_on( 'style:x-css', [ 'blog' ] ) );
		$r = Store::all()[0];
		$this->assertFalse( $r->is_manual() );
		$this->assertSame( 'alice', $r->approved_by );
	}

	public function test_switch_on_is_atomic_when_remaining_rule_is_invalid(): void {
		$rows = [ 'k' => [ 'target' => 'style:x-css', 'pages' => [ 'shop', 'bogus' ], 'hosts' => [ 'retail' ], 'approved_by' => 'a' ] ];
		$GLOBALS['bp_test_options'][ Store::OPTION ] = $rows;
		$this->assertIsString( Store::switch_on( 'style:x-css', [ 'shop' ] ) );
		$this->assertSame( $rows, $GLOBALS['bp_test_options'][ Store::OPTION ] );
	}

	public function test_specific_scope_errors(): void {
		$this->assertStringContainsString( 'not a normal page', Store::switch_off( 'unload:super-socializer', [ 'url:/wp-admin/' ], 'c', [] ) );
		$this->assertSame( 'Unknown page type.', Store::switch_on( 'style:x-css', [ 'bogus' ] ) );
	}
}
