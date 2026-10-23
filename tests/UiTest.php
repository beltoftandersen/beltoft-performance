<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Rules\Rule;

/** Pure helpers of the three-tab admin screen: tabs, row suggestions, labels. */
final class UiTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	private static function rep( array $plugins, array $sigs = [], int $status = 200 ): array {
		return [ 'status' => $status, 'plugins' => $plugins, 'signatures' => $sigs, 'assets' => [] ];
	}
	private static function trial( bool $ok, array $pages, array $evidence = [], string $check = 'c1', ?bool $browser = null ): array {
		$t = [ 'ok' => $ok, 'check' => $check, 'pages' => $pages, 'evidence' => $evidence ?: array_map( fn( $p ) => [ 'page' => $p, 'state' => 'guest' ], $pages ) ];
		if ( null !== $browser ) {
			$t['browser'] = [ 'ok' => $browser ];
		}
		return $t;
	}

	public function test_labels(): void {
		$this->assertSame( 'Shop', Ui::page_type_label( 'shop' ) );
		$this->assertSame( 'Category pages', Ui::page_type_label( 'archive_cat' ) );
		$this->assertSame( 'odd', Ui::page_type_label( 'odd' ) );
		$this->assertSame( 'Shop', Ui::scope_label( 'shop', [] ) );
		$this->assertSame( 'URL /sobre-nos/', Ui::scope_label( 'url:/sobre-nos/', [] ) );
		$this->assertSame( 'Template: Single product', Ui::scope_label( 'template:12', [ 12 => 'Single product' ] ) );
		$this->assertSame( 'Template #13', Ui::scope_label( 'template:13', [ 12 => 'Single product' ] ) );
		$this->assertSame( 'Plugin', Ui::target_kind_label( 'unload:x' ) );
		$this->assertSame( 'Plugin files', Ui::target_kind_label( 'plugin:x' ) );
		$this->assertSame( 'Script', Ui::target_kind_label( 'script:x' ) );
		$this->assertSame( 'Style', Ui::target_kind_label( 'style:x' ) );
	}

	public function test_suggestion_states(): void {
		$c = [ 'unload:a' => [ 'target' => 'unload:a', 'pages' => [ 'shop', 'blog' ] ] ];
		$this->assertNull( Ui::suggestion( 'unload:b', $c, [], 'c1', 'shop' ) );
		$this->assertNull( Ui::suggestion( 'unload:a', $c, [], 'c1', 'product' ) ); // not on this page type
		$this->assertNull( Ui::suggestion( 'unload:a', $c, [], 'c1', 'url:/x/' ) );

		$s = Ui::suggestion( 'unload:a', $c, [], 'c1', 'shop' );
		$this->assertSame( 'not_tested', $s['state'] );
		$this->assertFalse( $s['accept'] );
		$this->assertSame( [ 'shop', 'blog' ], $s['pages'] );

		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( true, [ 'shop', 'blog' ] ) ], 'c1', 'shop' );
		$this->assertSame( 'passed', $s['state'] );
		$this->assertTrue( $s['accept'] );
		$this->assertSame( '', $s['command'] );

		// Passed for more pages than the suggestion has now (a page was dismissed since): still proven for these.
		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( true, [ 'shop', 'blog', 'front' ] ) ], 'c1', 'shop' );
		$this->assertSame( 'passed', $s['state'] );
		$this->assertTrue( $s['all'] );

		// "Test on this page": proven here only; the other page of the suggestion is not tested.
		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( true, [ 'shop' ] ) ], 'c1', 'shop' );
		$this->assertSame( 'passed_here', $s['state'] );
		$this->assertTrue( $s['accept'] );
		$this->assertFalse( $s['all'] );
		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( true, [ 'shop' ] ) ], 'c1', 'blog' );
		$this->assertSame( 'not_tested', $s['state'] );
		$this->assertFalse( $s['accept'] );

		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( false, [ 'shop', 'blog' ], [ [ 'page' => 'shop', 'state' => 'guest', 'problem' => 'New PHP errors.' ] ] ) ], 'c1', 'shop' );
		$this->assertSame( 'failed', $s['state'] );
		$this->assertSame( 'New PHP errors.', $s['reason'] );

		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( false, [ 'shop', 'blog' ], [ [ 'page' => 'shop', 'state' => 'cart', 'needs_browser' => true ] ] ) ], 'c1', 'shop' );
		$this->assertSame( 'needs_browser', $s['state'] );
		$this->assertSame( 'wp beltoft-performance browser unload:a', $s['command'] );
		$this->assertFalse( $s['accept'] );

		$s = Ui::suggestion( 'unload:a', $c, [ 'unload:a' => self::trial( true, [ 'shop', 'blog' ], [], 'c1', false ) ], 'c1', 'shop' );
		$this->assertSame( 'failed', $s['state'] );
		$this->assertSame( 'The browser test failed.', $s['reason'] );

		// Scripts: never accepted, whatever the trial says.
		$c = [ 'script:j' => [ 'target' => 'script:j', 'pages' => [ 'shop' ] ] ];
		$s = Ui::suggestion( 'script:j', $c, [ 'script:j' => self::trial( true, [ 'shop' ] ) ], 'c1', 'shop' );
		$this->assertSame( 'passed', $s['state'] );
		$this->assertFalse( $s['accept'] );
		$this->assertNotSame( '', $s['reason'] );
	}

	public function test_with_suggestions_marks_rows_and_appends_the_rest(): void {
		$groups = [
			[ 'key' => 'p', 'plugin' => 'p', 'target' => 'unload:p', 'files' => [ [ 'target' => 'style:p-css' ], [ 'target' => 'script:p-js' ] ] ],
			[ 'key' => '', 'plugin' => '', 'target' => null, 'files' => [] ],
		];
		$cands  = [ [ 'target' => 'unload:p', 'pages' => [ 'shop' ] ], [ 'target' => 'style:p-css', 'pages' => [ 'shop' ] ], [ 'target' => 'unload:q', 'pages' => [ 'shop' ] ], [ 'target' => 'unload:r', 'pages' => [ 'blog' ] ] ];
		$out    = Ui::with_suggestions( $groups, $cands, [], 'c1', 'shop' );
		$this->assertSame( 'not_tested', $out[0]['suggestion']['state'] );
		$this->assertSame( 'not_tested', $out[0]['files'][0]['suggestion']['state'] );
		$this->assertNull( $out[0]['files'][1]['suggestion'] );
		$this->assertNull( $out[1]['suggestion'] );
		$this->assertCount( 3, $out );
		$this->assertSame( 'q', $out[2]['plugin'] );
		$this->assertSame( 'unload:q', $out[2]['target'] );
		$this->assertSame( 'not_tested', $out[2]['suggestion']['state'] );
		$this->assertFalse( $out[2]['off'] );
		$this->assertSame( [], $out[2]['files'] );
		// Nothing appended for a URL scope.
		$this->assertCount( 2, Ui::with_suggestions( $groups, $cands, [], 'c1', 'url:/x/' ) );
	}

	public function test_tab_from_old_values(): void {
		foreach ( [ 'overview' => 'scripts', 'manager' => 'scripts', 'proposals' => 'scripts', 'pages' => 'scripts', 'scripts' => 'scripts', 'rules' => 'off', 'log' => 'off', 'off' => 'off', 'bricks' => 'settings', 'switches' => 'settings', 'settings' => 'settings', '' => 'scripts', 'junk' => 'scripts' ] as $in => $out ) {
			$this->assertSame( $out, Ui::tab( $in ), $in );
		}
	}

	public function test_bricks_states(): void {
		$b = Ui::bricks_states( [ 'disableEmojis' => true, 'disableEmbed' => '', 'disableJqueryMigrate' => 1 ] );
		$this->assertSame( [ 'Emojis' => true, 'Embeds' => false, 'jQuery Migrate' => true, 'Lazy loading' => true ], $b );
		$this->assertFalse( Ui::bricks_states( [ 'disableLazyLoad' => true ] )['Lazy loading'] );
	}

	/** Minor 1: the experts' browser-check line is the runbook's full command (as root, --allow-root, the playwright path) plus the chown line. */
	public function test_browser_and_chown_commands(): void {
		$this->assertSame(
			'cd /var/www/staging.mindthetrash.pt && wp --allow-root beltoft-performance browser unload:x --pw=/root/pw',
			Ui::browser_command( 'wp beltoft-performance browser unload:x', '/var/www/staging.mindthetrash.pt/', '/root/pw' )
		);
		$this->assertSame(
			'cd /var/www/staging.mindthetrash.pt && chown -R www-data:www-data wp-content/uploads/beltoft-performance wp-content/mu-plugins/beltoft-performance-loader.php',
			Ui::chown_command( '/var/www/staging.mindthetrash.pt/' )
		);
	}
}
