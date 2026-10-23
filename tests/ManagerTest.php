<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() { return [ 'basedir' => $GLOBALS['bp_test_uploads'] ]; }
}
if ( ! function_exists( 'wp_date' ) ) {
	function wp_date( $f, $t = null ) { return gmdate( $f, (int) $t ); }
}

final class ManagerTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	protected function tearDown(): void {
		$dir = $GLOBALS['bp_test_uploads'] ?? '';
		if ( '' !== $dir && is_dir( $dir ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $it as $f ) { $f->isDir() ? rmdir( (string) $f ) : unlink( (string) $f ); }
			rmdir( $dir );
		}
		unset( $GLOBALS['bp_test_uploads'] );
	}

	private static function a( string $kind, string $handle, string $src, string $plugin, array $deps = [] ): array {
		return [ 'kind' => $kind, 'handle' => $handle, 'src' => $src, 'plugin' => $plugin, 'deps' => $deps, 'inline' => 0 ];
	}

	private static function assets(): array {
		return [
			self::a( 'style', 'x-css', '/wp-content/plugins/x/a.css', 'x' ),
			self::a( 'script', 'x-js', '/wp-content/plugins/x/a.js', 'x' ),
			self::a( 'script', 'x-addon', '/wp-content/plugins/x/addon.js', 'x', [ 'x-js' ] ),
			self::a( 'style', 'woocommerce-general', '/wp-content/plugins/woocommerce/a.css', 'woocommerce' ),
			self::a( 'script', 'jquery-core', '/wp-includes/js/jquery/jquery.min.js', '' ),
			self::a( 'style', 'wp-block-library', '/wp-includes/css/dist/block-library/style.min.css', '' ),
			self::a( 'style', 'a-plugin-css', '/wp-content/plugins/aaa/x.css', 'aaa' ),
		];
	}

	private static function by_key( array $groups ): array {
		return array_column( $groups, null, 'key' );
	}

	public function test_groups_by_plugin_core_last_locked_greyed(): void {
		$g = Manager::group_inventory( self::assets(), [], 'blog', [] );
		$this->assertSame( [ 'aaa', 'woocommerce', 'x', '' ], array_column( $g, 'key' ) );
		$k = self::by_key( $g );
		// Shop core is the owner's call now: toggles offered.
		$this->assertFalse( $k['woocommerce']['protected'] );
		$this->assertSame( 'unload:woocommerce', $k['woocommerce']['target'] );
		$this->assertFalse( $k['woocommerce']['files'][0]['protected'] );
		$this->assertSame( 'unload:x', $k['x']['target'] );
		$this->assertNull( $k['']['target'] );
		$core = array_column( $k['']['files'], null, 'handle' );
		$this->assertFalse( $core['jquery-core']['protected'] );
		$this->assertSame( 'script:jquery-core', $core['jquery-core']['target'] );
		$this->assertFalse( $core['wp-block-library']['protected'] );
		// Only the performance plugin itself is locked: no plugin toggle, files greyed.
		$bp = self::by_key( Manager::group_inventory( [ self::a( 'style', 'bp-admin', '/wp-content/plugins/beltoft-performance/a.css', 'beltoft-performance' ), self::a( 'script', 'beltoft-performance-x', '/wp-content/plugins/elsewhere/x.js', '' ) ], [], 'blog', [] ) );
		$this->assertTrue( $bp['beltoft-performance']['protected'] );
		$this->assertNull( $bp['beltoft-performance']['target'] );
		$this->assertTrue( $bp['beltoft-performance']['files'][0]['protected'] );
		$this->assertTrue( $bp['']['files'][0]['protected'] );
		$this->assertSame( 'style:wp-block-library', $core['wp-block-library']['target'] );
	}

	public function test_plugin_toggle_kind_follows_scope(): void {
		$this->assertSame( 'unload:x', Manager::plugin_target( 'blog', 'x' ) );
		$this->assertSame( 'unload:x', Manager::plugin_target( 'url:/sobre-nos/', 'x' ) );
		$this->assertSame( 'plugin:x', Manager::plugin_target( 'template:150335', 'x' ) );
		$this->assertSame( 'plugin:x', Manager::plugin_target( 'page', 'x' ) );
		$g = self::by_key( Manager::group_inventory( self::assets(), [], 'template:5', [] ) );
		$this->assertSame( 'plugin:x', $g['x']['target'] );
	}

	public function test_badges_from_usage_and_proposals(): void {
		$usage = [
			'style'    => [ '/wp-content/plugins/x/a.css' => 0, '/wp-content/plugins/aaa/x.css' => 40 ],
			'script'   => [ '/wp-content/plugins/x/a.js' => [ 'called' => 3, 'listeners' => 0 ], '/wp-content/plugins/x/addon.js' => [ 'called' => 0, 'listeners' => 0 ] ],
			'proposed' => [ 'style:wp-block-library' ],
		];
		$files = [];
		foreach ( Manager::group_inventory( self::assets(), [], 'blog', $usage ) as $grp ) {
			foreach ( $grp['files'] as $f ) { $files[ $f['target'] ] = $f['badge']; }
		}
		$this->assertSame( 'unused', $files['style:x-css'] );
		$this->assertSame( 'used', $files['script:x-js'] );
		$this->assertSame( 'unused', $files['script:x-addon'] );
		$this->assertSame( 'used', $files['style:a-plugin-css'] );
		$this->assertSame( 'not_measured', $files['style:wp-block-library'] ); // being proposed is no evidence (I4)
		$this->assertSame( 'not_measured', $files['style:woocommerce-general'] ); // shop core is no longer locked
		$bp = Manager::group_inventory( [ self::a( 'style', 'bp-admin', '/wp-content/plugins/beltoft-performance/a.css', 'beltoft-performance' ) ], [], 'blog', $usage );
		$this->assertSame( 'protected', $bp[0]['files'][0]['badge'] );
		$this->assertSame( 'not_measured', Manager::group_inventory( self::assets(), [], 'url:/a/', [] )[0]['files'][0]['badge'] );
	}

	public function test_off_state_and_rules_not_printed_any_more(): void {
		$rules = [
			new Rule( 'style:x-css', [ 'url:/a/', 'blog' ] ),
			new Rule( 'script:gone-js', [ 'url:/a/' ], [ 'retail' ], '', '', '', [ 'url:/a/' ] ),
			new Rule( 'unload:zzz', [ 'url:/a/' ] ),
			new Rule( 'style:a-plugin-css', [ 'blog' ] ),
			new Rule( 'style:wp-block-library', [ 'url:/a/' ], [ 'trade' ] ),
		];
		$g = self::by_key( Manager::group_inventory( self::assets(), $rules, 'url:/a/', [] ) );
		$x = array_column( $g['x']['files'], null, 'handle' );
		$this->assertTrue( $x['x-css']['off'] );
		$this->assertFalse( $x['x-js']['off'] );
		$this->assertFalse( array_column( $g['aaa']['files'], null, 'handle' )['a-plugin-css']['off'] );
		$this->assertFalse( array_column( $g['']['files'], null, 'handle' )['wp-block-library']['off'], 'trade-only rules do not count' );
		$this->assertSame( '__off', array_key_first( $g ) );
		$this->assertSame( [ 'script:gone-js', 'unload:zzz' ], array_column( $g['__off']['files'], 'target' ) );
		$this->assertTrue( $g['__off']['files'][0]['off'] );
	}

	public function test_plugins_without_files_only_where_unload_is_possible(): void {
		$meta = [ 'plugins' => [ 'x', 'nofiles', 'woocommerce', 'beltoft-gift-cards', 'beltoft-performance' ], 'signatures' => [ 'x' => [ 'x-' ] ] ];
		$g    = self::by_key( Manager::group_inventory( self::assets(), [], 'blog', [], $meta ) );
		$this->assertSame( [], $g['nofiles']['files'] );
		$this->assertSame( 'unload:nofiles', $g['nofiles']['target'] );
		$this->assertSame( 'not_tested', $g['nofiles']['badge'] );
		$this->assertSame( 'used', $g['x']['badge'] );
		$this->assertArrayNotHasKey( 'beltoft-performance', $g, 'the locked plugin without files is not listed' );
		$this->assertSame( 'unload:beltoft-gift-cards', $g['beltoft-gift-cards']['target'], 'shop core without files is listed now' );
		$t = self::by_key( Manager::group_inventory( self::assets(), [], 'template:9', [], $meta ) );
		$this->assertArrayNotHasKey( 'nofiles', $t );
		$this->assertArrayHasKey( 'x', $t );
	}

	public function test_cascade_note_lists_other_printed_handles(): void {
		$g = self::by_key( Manager::group_inventory( self::assets(), [], 'blog', [] ) );
		$x = array_column( $g['x']['files'], null, 'handle' );
		$this->assertSame( [ 'x-addon' ], $x['x-js']['also'] );
		$this->assertSame( [], $x['x-addon']['also'] );
		$this->assertSame( [], $x['x-css']['also'] );
	}

	public function test_file_whose_cascade_reaches_a_locked_file_is_locked(): void {
		$assets = [ self::a( 'script', 'lib', '/wp-content/plugins/x/lib.js', 'x' ), self::a( 'script', 'bp-thing', '/wp-content/plugins/beltoft-performance/t.js', '', [ 'lib' ] ) ];
		$x      = self::by_key( Manager::group_inventory( $assets, [], 'blog', [] ) )['x']['files'][0];
		$this->assertSame( [ 'bp-thing' ], $x['also'] );
		$this->assertTrue( $x['protected'] );
		$this->assertSame( 'protected', $x['badge'] );
		// A cascade reaching a Bricks theme file no longer locks it.
		$assets = [ self::a( 'script', 'lib', '/wp-content/plugins/x/lib.js', 'x' ), self::a( 'script', 'bricks-thing', '/wp-content/themes/bricks/t.js', '', [ 'lib' ] ) ];
		$x      = self::by_key( Manager::group_inventory( $assets, [], 'blog', [] ) )['x']['files'][0];
		$this->assertSame( [ 'bricks-thing' ], $x['also'] );
		$this->assertFalse( $x['protected'] );
		$this->assertNotSame( 'protected', $x['badge'] );
	}

	public function test_rule_changes_touch_only_the_given_scope(): void {
		$current = [
			new Rule( 'style:a', [ 'blog', 'shop' ], [ 'retail' ], 'proof', 'chris', '2026-01-01' ),
			new Rule( 'style:b', [ 'shop' ], [ 'retail' ], 'proof', 'chris', '2026-01-01' ),
			new Rule( 'style:c', [ 'blog' ], [ 'trade' ] ),
		];
		$posted = [ 'style:a' => false, 'style:b' => true, 'style:c' => true, 'script:d' => false, 'unload:e' => true, 'bad target' => true ];
		$ch     = Manager::rule_changes( $current, $posted, 'blog' );
		$this->assertSame( [ 'style:b', 'style:c', 'unload:e' ], array_map( static fn( $r ) => $r->target, $ch['add'] ) );
		$this->assertSame( [ 'style:a' ], array_map( static fn( $r ) => $r->target, $ch['remove'] ) );
		foreach ( array_merge( $ch['add'], $ch['remove'] ) as $r ) {
			$this->assertSame( [ 'blog' ], $r->pages );
			$this->assertSame( [ 'blog' ], $r->manual_pages );
			$this->assertSame( [ 'retail' ], $r->hosts );
		}
		$none = Manager::rule_changes( $current, [ 'style:a' => true, 'style:b' => false ], 'blog' );
		$this->assertSame( [ 'add' => [], 'remove' => [] ], $none );
	}

	public function test_load_outcome(): void {
		$this->assertNull( Manager::load_outcome( 200, [ 'status' => 200, 'assets' => [] ] ) );
		foreach ( [ 301, 302, 404, 500, 0 ] as $code ) {
			$this->assertIsString( Manager::load_outcome( $code, [ 'status' => $code ] ), (string) $code );
		}
		$this->assertStringContainsString( '301', Manager::load_outcome( 301, null ) );
		$this->assertStringContainsString( '404', Manager::load_outcome( 404, null ) );
		$this->assertIsString( Manager::load_outcome( 200, null ) );
		$this->assertIsString( Manager::load_outcome( 200, [ 'status' => 404 ] ) );
	}

	public function test_local_path(): void {
		$this->assertSame( '/srv/wp/wp-includes/a.css', Manager::local_path( '/wp-includes/a.css?ver=1', 'https://s.example', '/srv/wp/' ) );
		$this->assertSame( '/srv/wp/wp-content/p/a.js', Manager::local_path( 'https://s.example/wp-content/p/a.js', 'https://s.example', '/srv/wp/' ) );
		$this->assertSame( '', Manager::local_path( 'https://cdn.other/a.js', 'https://s.example', '/srv/wp/' ) );
		$this->assertSame( '', Manager::local_path( '/wp-content/../../etc/passwd', 'https://s.example', '/srv/wp/' ) );
		$this->assertSame( '', Manager::local_path( '', 'https://s.example', '/srv/wp/' ) );
	}

	public function test_inherited_page_type_rules_show_ticked_and_locked(): void {
		$rules = [
			new Rule( 'style:x-css', [ 'page', 'post' ] ),
			new Rule( 'plugin:aaa', [ 'page' ] ),
			new Rule( 'style:gone-css', [ 'page' ] ),
			new Rule( 'unload:zzz', [ 'front' ] ),
			new Rule( 'style:tpl-css', [ 'template:77' ] ),
			new Rule( 'style:other-tpl', [ 'template:88' ] ),
			new Rule( 'style:trade-only', [ 'page' ], [ 'trade' ] ),
		];
		$assets = self::assets();
		$assets[0]['size'] = 1234;
		$g = self::by_key( Manager::group_inventory( $assets, $rules, 'url:/sobre-nos/', [], [ 'page' => 'page', 'templates' => [ 77, 5 ] ] ) );
		$this->assertSame( [ '__inherited', 'aaa', 'woocommerce', 'x', '' ], array_keys( $g ) );
		$inh = array_column( $g['__inherited']['files'], null, 'target' );
		$this->assertSame( [ 'plugin:aaa', 'style:gone-css', 'style:tpl-css', 'style:x-css' ], array_keys( $inh ) );
		$this->assertSame( 'page', $inh['style:gone-css']['inherited'] );
		$this->assertSame( 'template:77', $inh['style:tpl-css']['inherited'] );
		$this->assertSame( 1234, $inh['style:x-css']['size'] );
		$this->assertTrue( $inh['style:x-css']['off'] );
		// The plugin row: unload:aaa is the toggle here, but plugin:aaa on "page" already applies.
		$this->assertSame( 'page', $g['aaa']['inherited'] );
		$this->assertTrue( $g['aaa']['off'] );
		$x = array_column( $g['x']['files'], null, 'handle' );
		$this->assertSame( 'page', $x['x-css']['inherited'] );
		$this->assertTrue( $x['x-css']['off'] );
		$this->assertNull( $x['x-js']['inherited'] );
		$this->assertNull( $g['x']['inherited'] );
	}

	public function test_template_scope_inherits_the_page_type_of_its_post(): void {
		$rules = [ new Rule( 'style:x-css', [ 'product' ] ), new Rule( 'style:a-plugin-css', [ 'template:150335' ] ) ];
		$g = self::by_key( Manager::group_inventory( self::assets(), $rules, 'template:150335', [], [ 'page' => 'product', 'templates' => [ 150335 ] ] ) );
		$this->assertSame( [ 'style:x-css' ], array_column( $g['__inherited']['files'], 'target' ) );
		$this->assertTrue( array_column( $g['aaa']['files'], null, 'handle' )['a-plugin-css']['off'] );
		$this->assertNull( array_column( $g['aaa']['files'], null, 'handle' )['a-plugin-css']['inherited'], 'own scope is editable, not inherited' );
		$this->assertArrayNotHasKey( '__inherited', self::by_key( Manager::group_inventory( self::assets(), $rules, 'product', [], [ 'page' => 'product' ] ) ) );
	}

	public function test_scope_from_keeps_percent_encoding_and_encode_path(): void {
		$this->assertSame( 'url:/produto/café/', Manager::scope_from( '/produto/caf%C3%A9/' ) );
		$this->assertSame( 'url:/produto/café/', Manager::scope_from( 'url:/produto/caf%C3%A9/' ) );
		$this->assertSame( '/produto/caf%C3%A9/', Manager::encode_path( '/produto/café/' ) );
		$this->assertSame( '/sobre-nos/', Manager::encode_path( '/sobre-nos/' ) );
	}

	public function test_dirs_to_prune_keeps_three_newest_manual(): void {
		$this->assertSame( [ 'manual-20261001', 'manual-20260930' ], Manager::dirs_to_prune( [ 'manual-20261003', '20261003-161340-fe22db', 'manual-20260930', 'manual-20261004', 'manual-20261001', 'manual-20261002', 'verify-x' ] ) );
		$this->assertSame( [], Manager::dirs_to_prune( [ 'manual-20261003', 'manual-20261004' ] ) );
	}

	private function uploads(): string {
		$dir = sys_get_temp_dir() . '/bp-manager-test-' . getmypid();
		$GLOBALS['bp_test_uploads'] = $dir;
		foreach ( [ 'check-1', 'manual-20261004' ] as $id ) {
			@mkdir( "$dir/beltoft-performance/checks/$id", 0777, true );
		}
		$a = static fn( $h ) => [ 'kind' => 'style', 'handle' => $h, 'src' => "/wp-content/plugins/x/$h.css", 'plugin' => 'x', 'deps' => [] ];
		file_put_contents( "$dir/beltoft-performance/checks/check-1/blog-guest.json", json_encode( [ 'page' => 'blog', 'state' => 'guest', 'status' => 200, 'assets' => [ $a( 'in-check' ) ] ] ) );
		file_put_contents( "$dir/beltoft-performance/checks/manual-20261004/scope-abc-guest.json", json_encode( [ 'page' => 'product', 'state' => 'guest', 'status' => 200, 'assets' => [ $a( 'only-manual' ) ], 'plugins' => [ 'x' ], 'signatures' => [], 'templates' => [ 7 ] ] ) );
		$GLOBALS['bp_test_options']['beltoft_performance_last_check'] = [ 'id' => 'check-1', 'time' => 100 ];
		$GLOBALS['bp_test_options'][ Manager::LOADS_OPTION ] = [ 'template:7' => [ 'id' => 'manual-20261004', 'page' => 'product', 'file' => 'scope-abc-guest.json', 'time' => 200, 'url' => 'https://s/p/' ] ];
		return $dir;
	}

	public function test_inventory_for_includes_the_scope_manual_report(): void {
		$this->uploads();
		$inv = Manager::inventory_for( 'template:7' );
		$this->assertArrayHasKey( 'blog', $inv );
		$this->assertNull( Store::manual_file_error( 'style', 'only-manual', $inv ) );
		$this->assertNotNull( Store::manual_file_error( 'style', 'only-manual', Manager::inventory_for( 'template:8' ) ) );
		$this->assertTrue( Store::switch_off( 'style:only-manual', [ 'template:7' ], 'qa', $inv ) );
		$this->assertSame( [ 'template:7' ], Store::all()[0]->manual_pages );
	}

	public function test_view_data_shape_for_a_loaded_template(): void {
		$this->uploads();
		$d = Manager::view_data( 'template:7' );
		$this->assertSame( [ 'report', 'source', 'evidence', 'assets', 'usage', 'meta' ], array_keys( $d ) );
		$this->assertSame( 'only-manual', $d['assets'][0]['handle'] );
		$this->assertSame( [ 'plugins' => [ 'x' ], 'signatures' => [], 'page' => 'product', 'templates' => [ 7 ] ], $d['meta'] );
		$this->assertSame( [], $d['usage'] );
		$this->assertStringContainsString( 'https://s/p/', $d['source'] );
		$this->assertNull( Manager::view_data( 'template:8' )['report'] );
	}
}
