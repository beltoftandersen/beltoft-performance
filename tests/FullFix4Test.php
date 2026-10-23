<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Rules\PerfmattersImport;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

/** Full review round 4: Perfmatters must be live before an import; exceptions, page post type, 404, groups, plugin cascades, undo. */
final class FullFix4Test extends TestCase {
	private const ALL = Store::PAGE_TYPES;

	protected function setUp(): void {
		$GLOBALS['bp_test_options'] = [];
	}

	private static function row( array $plan, string $source ): array {
		foreach ( $plan as $r ) {
			if ( $r['source'] === $source ) {
				return $r;
			}
		}
		throw new \RuntimeException( "no row $source" );
	}

	private static function live( array $over = [] ): array {
		return array_merge( [ 'active' => true, 'script_manager' => true, 'testing_mode' => false, 'safe_mode' => false, 'mu_mode' => false ], $over );
	}

	/* ---------------------------------------------------------------- I1: Perfmatters must be live */

	public function test_live_state_reads_the_real_option_keys(): void {
		$s = PerfmattersImport::live_state( true, [ 'script_manager' => '1', 'safe_mode' => '1' ], [ 'testing_mode' => '1', 'mu_mode' => '' ] );
		$this->assertSame( [ 'active' => true, 'script_manager' => true, 'testing_mode' => true, 'safe_mode' => true, 'mu_mode' => false ], $s );
		$s = PerfmattersImport::live_state( false, false, false );
		$this->assertSame( [ 'active' => false, 'script_manager' => false, 'testing_mode' => false, 'safe_mode' => false, 'mu_mode' => false ], $s );
	}

	public function test_live_line_prints_the_values(): void {
		$this->assertSame( 'Perfmatters: active=yes script_manager=1 testing_mode=0 safe_mode=0 mu_mode=1', PerfmattersImport::live_line( self::live( [ 'mu_mode' => true ] ) ) );
	}

	public function test_live_check_passes_when_live(): void {
		$this->assertSame( [ 'refuse' => null, 'warnings' => [] ], PerfmattersImport::live_check( self::live(), false ) );
	}

	public function test_live_check_refuses_each_dead_state(): void {
		foreach ( [ [ 'active' => false ], [ 'script_manager' => false ], [ 'testing_mode' => true ], [ 'safe_mode' => true ] ] as $over ) {
			$c = PerfmattersImport::live_check( self::live( $over ), false );
			$this->assertNotNull( $c['refuse'], json_encode( $over ) );
			$this->assertStringContainsString( '--force', $c['refuse'] );
		}
		$this->assertStringContainsString( 'not active', PerfmattersImport::live_check( self::live( [ 'active' => false ] ), false )['refuse'] );
		$this->assertStringContainsString( 'testing mode', PerfmattersImport::live_check( self::live( [ 'testing_mode' => true ] ), false )['refuse'] );
		$this->assertStringContainsString( 'safe mode', PerfmattersImport::live_check( self::live( [ 'safe_mode' => true ] ), false )['refuse'] );
	}

	public function test_force_overrides_with_a_loud_warning(): void {
		$c = PerfmattersImport::live_check( self::live( [ 'active' => false, 'safe_mode' => true ] ), true );
		$this->assertNull( $c['refuse'] );
		$this->assertCount( 1, $c['warnings'] );
		$this->assertStringContainsString( 'FORCED', $c['warnings'][0] );
		$this->assertStringContainsString( 'not active', $c['warnings'][0] );
		$this->assertStringContainsString( 'safe mode', $c['warnings'][0] );
	}

	public function test_mu_mode_warns(): void {
		$c = PerfmattersImport::live_check( self::live( [ 'mu_mode' => true ] ), false );
		$this->assertNull( $c['refuse'] );
		$this->assertStringContainsString( 'MU mode', $c['warnings'][0] );
		$this->assertStringContainsString( 'PHP', $c['warnings'][0] );
	}

	/* ---------------------------------------------------------------- exceptions for one URL keep the page type */

	private static function ctx( array $type_urls = [] ): array {
		return array(
			'posts'     => array(
				10 => array( 'scope' => 'url:/sobre/', 'page' => 'page', 'post_type' => 'page' ),
				11 => array( 'scope' => 'url:/finalizar/', 'page' => 'checkout', 'post_type' => 'page' ),
				12 => array( 'scope' => 'url:/produto/x/', 'page' => 'product', 'post_type' => 'product' ),
				14 => array( 'scope' => 'url:/loja/', 'page' => 'shop', 'post_type' => 'page' ),
			),
			'type_urls' => $type_urls,
			'known'     => array(
				'script' => array(
					'foo'    => array( 'src' => 'https://x/wp-content/plugins/foo/foo.js', 'plugin' => 'foo', 'deps' => array() ),
					'grp'    => array( 'src' => 'https://x/wp-content/plugins/grp/grp.js', 'plugin' => 'grp', 'deps' => array() ),
					'cur'    => array( 'src' => 'https://x/wp-content/plugins/cur/cur.js', 'plugin' => 'cur', 'deps' => array() ),
					'sj'     => array( 'src' => 'https://x/wp-content/plugins/scripty/s.js', 'plugin' => 'scripty', 'deps' => array() ),
					'sj-use' => array( 'src' => 'https://x/wp-content/plugins/other/u.js', 'plugin' => 'other', 'deps' => array( 'sj' ) ),
					'bj'     => array( 'src' => 'https://x/wp-content/plugins/blocked/b.js', 'plugin' => 'blocked', 'deps' => array() ),
					'bricks-y' => array( 'src' => 'https://x/wp-content/themes/bricks/y.js', 'plugin' => '', 'deps' => array( 'bj' ) ),
					'lj'       => array( 'src' => 'https://x/wp-content/plugins/locked/l.js', 'plugin' => 'locked', 'deps' => array() ),
					'bp-y'     => array( 'src' => 'https://x/wp-content/plugins/beltoft-performance/y.js', 'plugin' => 'beltoft-performance', 'deps' => array( 'lj' ) ),
				),
				'style'  => array(
					'half-css'  => array( 'src' => 'https://x/wp-content/plugins/half/h.css', 'plugin' => 'half', 'deps' => array() ),
					'bricks-x'  => array( 'src' => 'https://x/wp-content/themes/bricks/x.css', 'plugin' => '', 'deps' => array( 'half-css' ) ),
					'half-ok'   => array( 'src' => 'https://x/wp-content/plugins/half/ok.css', 'plugin' => 'half', 'deps' => array() ),
					'lhalf-css' => array( 'src' => 'https://x/wp-content/plugins/lhalf/h.css', 'plugin' => 'lhalf', 'deps' => array() ),
					'bp-x'      => array( 'src' => 'https://x/wp-content/plugins/beltoft-performance/x.css', 'plugin' => 'beltoft-performance', 'deps' => array( 'lhalf-css' ) ),
					'lhalf-ok'  => array( 'src' => 'https://x/wp-content/plugins/lhalf/ok.css', 'plugin' => 'lhalf', 'deps' => array() ),
				),
			),
			'plugins'   => array( 'foo', 'grp', 'cur', 'scripty', 'other', 'blocked', 'half', 'locked', 'lhalf', 'idle', 'pagey' ),
		);
	}

	private static function plan( array $disabled, array $enabled = [], array $type_urls = [] ): array {
		return PerfmattersImport::plan( [ 'disabled' => $disabled, 'enabled' => $enabled ], self::ctx( $type_urls ) );
	}

	public function test_single_url_exception_keeps_the_rest_of_its_page_type(): void {
		$plan = self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ '10' ] ] ] ], [ 'page' => [ 'url:/sobre/', 'url:/b/', 'url:/a/' ] ] );
		$r    = self::row( $plan, 'js:foo' );
		$this->assertSame( 'import', $r['action'] );
		$this->assertSame( array_merge( array_values( array_diff( self::ALL, [ 'page', 'search' ] ) ), [ 'url:/a/', 'url:/b/' ] ), $r['scopes'] );
		$this->assertSame( 2, $r['urls'] );
		$this->assertStringContainsString( '2 other published pages', $r['note'] );
		$this->assertStringContainsString( 'archives', $r['note'] ); // the page type also covers archives/404: not covered
	}

	public function test_single_url_exception_over_200_pages_skips_that_page_type(): void {
		$many = array_map( static fn( $i ) => "url:/p$i/", range( 1, 201 ) );
		$r    = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ 12 ] ] ] ], [ 'product' => array_merge( [ 'url:/produto/x/' ], $many ) ] ), 'js:foo' );
		$this->assertSame( array_values( array_diff( self::ALL, [ 'product' ] ) ), $r['scopes'] );
		$this->assertStringContainsString( 'more than 200', $r['note'] );
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ 12 ] ] ] ], [ 'product' => null ] ), 'js:foo' );
		$this->assertSame( array_values( array_diff( self::ALL, [ 'product' ] ) ), $r['scopes'] );
		$this->assertStringContainsString( 'more than 200', $r['note'] );
	}

	public function test_exactly_200_pages_are_imported(): void {
		$list = array_map( static fn( $i ) => sprintf( 'url:/p%03d/', $i ), range( 1, 200 ) );
		$r    = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ 10 ] ] ] ], [ 'page' => array_merge( [ 'url:/sobre/' ], $list ) ] ), 'js:foo' );
		$this->assertSame( 200, $r['urls'] );
		$this->assertContains( 'url:/p200/', $r['scopes'] );
		$this->assertNotContains( 'url:/sobre/', $r['scopes'] );
	}

	public function test_checkout_exception_also_keeps_order_received(): void {
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ 11 ] ] ] ] ), 'js:foo' );
		$this->assertNotContains( 'checkout', $r['scopes'] );
		$this->assertNotContains( 'order_received', $r['scopes'] );
	}

	/* ---------------------------------------------------------------- M1: disabled post_types:page */

	public function test_disabled_page_post_type_maps_to_page_urls(): void {
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'post_types' => [ 'page', 'product' ] ] ] ], [], [ 'page' => [ 'url:/b/', 'url:/a/' ] ] ), 'js:foo' );
		$this->assertSame( [ 'import', [ 'product', 'url:/a/', 'url:/b/' ] ], [ $r['action'], $r['scopes'] ] );
		$this->assertSame( 2, $r['urls'] );
		$this->assertNotContains( 'page', $r['scopes'] );

		$many = array_map( static fn( $i ) => "url:/p$i/", range( 1, 201 ) );
		$r    = self::row( self::plan( [ 'js' => [ 'foo' => [ 'post_types' => [ 'page' ] ] ] ], [], [ 'page' => $many ] ), 'js:foo' );
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'more than 200', $r['note'] );
	}

	/* ---------------------------------------------------------------- M2: exception on a post also holds on its post type's page type */

	public function test_search_is_kept_when_a_post_or_page_exception_exists(): void {
		// Perfmatters falls back to the first result's ID on search.
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ 10 ] ] ] ], [ 'page' => [ 'url:/sobre/' ] ] ), 'js:foo' );
		$this->assertNotContains( 'search', $r['scopes'] );
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'post_types' => [ 'page' ] ] ] ] ), 'js:foo' );
		$this->assertNotContains( 'search', $r['scopes'] );
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'post_types' => [ 'post' ] ] ] ] ), 'js:foo' );
		$this->assertNotContains( 'search', $r['scopes'] );
	}

	public function test_exception_on_a_post_uses_its_post_type_page_too(): void {
		// Shop page (a page post): Perfmatters' current ID is the page on the whole shop page type -> shop kept, page narrowed.
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ 'current' => [ 14 ] ] ] ], [ 'page' => [ 'url:/sobre/' ] ] ), 'js:foo' );
		$this->assertNotContains( 'shop', $r['scopes'] );
		$this->assertNotContains( 'page', $r['scopes'] );
		$this->assertContains( 'url:/sobre/', $r['scopes'] );
	}

	/* ---------------------------------------------------------------- M3: exception on 404 */

	public function test_404_exception_keeps_the_page_type(): void {
		$r = self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ], [ 'js' => [ 'foo' => [ '404' => 1 ] ] ], [ 'page' => [ 'url:/sobre/' ] ] ), 'js:foo' );
		$this->assertSame( array_values( array_diff( self::ALL, [ 'page' ] ) ), $r['scopes'] );
		$this->assertStringContainsString( '404', $r['note'] );
	}

	/* ---------------------------------------------------------------- M4: per-file entries under a conditioned plugin group */

	public function test_file_entry_of_a_conditioned_group_is_skipped(): void {
		$plan = self::plan( [ 'js' => [ 'grp' => [ 'everywhere' => 1 ], 'cur' => [ 'everywhere' => 1 ] ], 'plugins' => [ 'grp' => [ 'post_types' => [ 'product' ] ], 'cur' => [ 'current' => [ 12 ] ] ] ] );
		$r    = self::row( $plan, 'js:grp' );
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'plugins:grp', $r['note'] );
		$this->assertSame( 'import', self::row( $plan, 'plugins:grp' )['action'] );
		$r = self::row( $plan, 'js:cur' ); // a group by post only overrides on that post: the file entry still counts elsewhere
		$this->assertSame( [ 'import', self::ALL ], [ $r['action'], $r['scopes'] ] );
		$this->assertStringContainsString( 'plugins:cur', $r['note'] );
	}

	/* ---------------------------------------------------------------- M5: plugin rows: cascade, protected dependents, scripts */

	public function test_plugin_rows_get_the_cascade_check(): void {
		$plan = self::plan( [ 'plugins' => [ 'scripty' => [ 'everywhere' => 1 ], 'blocked' => [ 'everywhere' => 1 ], 'half' => [ 'everywhere' => 1 ], 'locked' => [ 'everywhere' => 1 ], 'lhalf' => [ 'everywhere' => 1 ], 'idle' => [ 'everywhere' => 1 ] ] ] );

		$r = self::row( $plan, 'plugins:scripty' );
		$this->assertSame( 'import', $r['action'] );
		$this->assertTrue( $r['scripts'] );
		$this->assertStringContainsString( 'sj-use', $r['note'] );

		// A Bricks dependent no longer blocks a plugin row: it goes with the plugin's files.
		$r = self::row( $plan, 'plugins:blocked' );
		$this->assertSame( 'import', $r['action'] );
		$this->assertTrue( $r['scripts'] );
		$this->assertStringContainsString( 'bricks-y', $r['note'] );

		$r = self::row( $plan, 'plugins:half' );
		$this->assertSame( 'import', $r['action'] );
		$this->assertStringContainsString( 'bricks-x', $r['note'] );
		$this->assertFalse( $r['scripts'] );

		// A dependent of the performance plugin itself (locked) still blocks.
		$r = self::row( $plan, 'plugins:locked' ); // its only files can never go: the rule would do nothing
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'bp-y', $r['note'] );

		$r = self::row( $plan, 'plugins:lhalf' ); // styles blocked by a locked dependent: the rule keeps them all
		$this->assertSame( 'skip', $r['action'] );
		$this->assertStringContainsString( 'bp-x', $r['note'] );
		$this->assertFalse( $r['scripts'] );

		$r = self::row( $plan, 'plugins:idle' );
		$this->assertSame( 'import', $r['action'] );
		$this->assertFalse( $r['scripts'] );
		$this->assertStringContainsString( 'No files', $r['note'] );

		$this->assertTrue( self::row( self::plan( [ 'js' => [ 'foo' => [ 'everywhere' => 1 ] ] ] ), 'js:foo' )['scripts'] );
	}

	/* ---------------------------------------------------------------- M9: --undo */

	public function test_undo_plan_removes_imported_manual_scopes_only(): void {
		$rules = [
			new Rule( 'script:a', [ 'shop', 'url:/x/' ], [ 'retail' ], PerfmattersImport::REASON, 'claude', 't', [ 'shop', 'url:/x/' ] ),
			new Rule( 'style:b', [ 'shop', 'blog' ], [ 'retail' ], PerfmattersImport::REASON, 'claude', 't', [ 'blog' ] ),
			new Rule( 'plugin:c', [ 'shop' ], [ 'retail' ], 'Unused on 3 pages. · ' . PerfmattersImport::REASON, 'chris', 't', [ 'shop' ] ),
			new Rule( 'plugin:d', [ 'shop' ], [ 'retail' ], 'Switched off by hand.', 'chris', 't', [ 'shop' ] ),
			new Rule( 'plugin:e', [ 'shop' ], [ 'retail' ], PerfmattersImport::REASON, 'chris', 't', [] ),
		];
		$plan = PerfmattersImport::undo_plan( $rules );
		$this->assertSame( [ 'script:a', 'style:b', 'plugin:c', 'plugin:e' ], array_column( $plan, 'target' ) );
		$this->assertSame( [ 'remove', [ 'shop', 'url:/x/' ] ], [ $plan[0]['action'], $plan[0]['scopes'] ] );
		$this->assertSame( [ 'remove', [ 'blog' ] ], [ $plan[1]['action'], $plan[1]['scopes'] ] );
		$this->assertStringContainsString( 'shop', $plan[1]['note'] ); // accepted scope stays
		$this->assertSame( 'skip', $plan[2]['action'] );
		$this->assertSame( 'skip', $plan[3]['action'] );
		$this->assertSame( $rules[0]->key(), $plan[0]['key'] );
	}

	public function test_undo_applies(): void {
		update_option( Store::OPTION, [] );
		Store::switch_off( 'plugin:idle', [ 'shop', 'blog' ], 'claude', [], PerfmattersImport::REASON );
		Store::switch_off( 'plugin:other', [ 'shop' ], 'chris', [] );
		$res = PerfmattersImport::undo( Store::all() );
		$this->assertSame( 1, $res['removed'] );
		$this->assertSame( [ 'plugin:other' ], array_map( static fn( $r ) => $r->target, Store::all() ) );
	}
}
