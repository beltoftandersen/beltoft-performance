<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Cleanup\Switches;
use BeltoftPerformance\Cli\Command;
use BeltoftPerformance\Request\RouteMap;
use BeltoftPerformance\Rules\Compiler;

require_once dirname( __DIR__ ) . '/mu/beltoft-performance-loader.php';

/** Review round 1 minors: M1 dashicons, M2 rewrite rules, M4 per-request memo, M5 rules path, M6 pruning, M7 header via env. */
final class FullFix1MinorsTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['beltoft_performance_removed'] );
		RouteMap::flush();
	}
	public function test_m1_dashicons_dropped_for_guests_only(): void {
		$this->assertTrue( Switches::drop_dashicons( false ) );
		$this->assertFalse( Switches::drop_dashicons( true ) );
	}
	public function test_m2_rewrite_rules_keep_the_old_value_after_an_unload(): void {
		$this->assertSame( 'new', beltoft_performance_keep_rewrite_rules( 'new', 'old' ) );
		$GLOBALS['beltoft_performance_removed'] = [ 'unfiltered' => [ 'a/a.php', 'b/b.php' ], 'removed' => [] ];
		$this->assertSame( 'new', beltoft_performance_keep_rewrite_rules( 'new', 'old' ) );
		$GLOBALS['beltoft_performance_removed'] = [ 'unfiltered' => [ 'a/a.php', 'b/b.php' ], 'removed' => [ 'b/b.php' ] ];
		$this->assertSame( [ 'r' => 'old' ], beltoft_performance_keep_rewrite_rules( [ 'r' => 'partial' ], [ 'r' => 'old' ] ) );
	}
	public function test_m4_route_map_built_once_per_request(): void {
		RouteMap::flush();
		$GLOBALS['bp_test_options']['wc_page_shop'] = 'https://staging.mindthetrash.pt/loja/';
		$a = RouteMap::build();
		$GLOBALS['bp_test_options']['wc_page_shop'] = 'https://staging.mindthetrash.pt/outra/';
		$this->assertSame( $a, RouteMap::build() );
		RouteMap::flush();
		$this->assertSame( '/outra/', RouteMap::build()['shop'] );
		unset( $GLOBALS['bp_test_options']['wc_page_shop'] );
	}
	public function test_m5_loader_and_compiler_agree_on_the_rules_path(): void {
		$this->assertSame( '/srv/wp/wp-content/uploads/beltoft-performance/rules.php', Compiler::path_for( '/srv/wp/wp-content', '/srv/wp/', null ) );
		$this->assertSame( '/srv/wp/media/beltoft-performance/rules.php', Compiler::path_for( '/srv/wp/wp-content', '/srv/wp/', 'media' ) );
		foreach ( [ null, 'media', 'files/up' ] as $u ) {
			$this->assertSame( Compiler::path_for( '/srv/wp/wp-content', '/srv/wp/', $u ), beltoft_performance_rules_path( '/srv/wp/wp-content', '/srv/wp/', $u ) );
		}
	}
	public function test_m5_rules_file_problem(): void {
		$this->assertNull( OverviewPage::rules_file_problem( true, true, 'www-data' ) );
		$this->assertStringContainsString( 'missing', (string) OverviewPage::rules_file_problem( false, false, '' ) );
		$this->assertStringContainsString( 'cannot be read', (string) OverviewPage::rules_file_problem( true, false, 'www-data' ) );
		$this->assertStringContainsString( 'root', (string) OverviewPage::rules_file_problem( true, true, 'root' ) );
		$this->assertSame( 'ok', OverviewPage::rules_file_code( true, true, 'www-data' ) );
		$this->assertSame( 'missing', OverviewPage::rules_file_code( false, false, '' ) );
		$this->assertSame( 'unreadable', OverviewPage::rules_file_code( true, false, 'www-data' ) );
		$this->assertSame( 'wrong_owner', OverviewPage::rules_file_code( true, true, 'root' ) );
	}
	public function test_m6_keeps_the_five_newest_checks(): void {
		$names = [ '20261001-100000-aaaaaa', '20261003-161340-fe22db', 'manual-20261004', '20261002-100000-bbbbbb', '20260930-100000-cccccc', '20260929-100000-dddddd', '20260928-100000-eeeeee', 'junk' ];
		$this->assertSame( [ '20260928-100000-eeeeee' ], Runner::checks_to_prune( $names ) );
		$this->assertSame( [ '20260929-100000-dddddd', '20260928-100000-eeeeee' ], Runner::checks_to_prune( $names, 4 ) );
		$this->assertSame( [], Runner::checks_to_prune( [ 'manual-20261001', 'x' ] ) );
		$this->assertSame( [ 'beltoft_performance_cart_cookies_old' ], Runner::cookie_options_to_drop( [ 'beltoft_performance_cart_cookies_new', 'beltoft_performance_cart_cookies_old', 'beltoft_performance_rules' ], [ 'new' ] ) );
	}
	public function test_m6_uninstall_removes_only_our_upload_dir(): void {
		$root = sys_get_temp_dir() . '/bp-uninstall-' . getmypid();
		@mkdir( "$root/beltoft-performance/checks/a", 0777, true );
		@mkdir( "$root/other", 0777, true );
		file_put_contents( "$root/beltoft-performance/rules.php", 'x' );
		file_put_contents( "$root/beltoft-performance/checks/a/r.json", 'x' );
		file_put_contents( "$root/other/keep.txt", 'x' );
		$this->assertFalse( \BeltoftPerformance\Uninstaller::rmtree( "$root/other", $root ) ); // not our directory
		$this->assertFalse( \BeltoftPerformance\Uninstaller::rmtree( "$root/beltoft-performance/../other", $root ) );
		$this->assertTrue( \BeltoftPerformance\Uninstaller::rmtree( "$root/beltoft-performance", $root ) );
		$this->assertDirectoryDoesNotExist( "$root/beltoft-performance" );
		$this->assertFileExists( "$root/other/keep.txt" );
		unlink( "$root/other/keep.txt" ); rmdir( "$root/other" ); rmdir( $root );
	}
	public function test_m7_signed_header_goes_through_the_environment(): void {
		$argv = Command::node_argv( '/usr/bin/node', '/p/bin/browser-check.js', '/pw', 'https://s/loja/', [ 'a' => 'b' ], [ 'mode' => 'nav' ] );
		$this->assertSame( [ '/usr/bin/node', '/p/bin/browser-check.js', '/pw', '["https:\/\/s\/loja\/"]', '{"a":"b"}', '{"mode":"nav"}' ], $argv );
		$env = Command::node_env( [ 'PATH' => '/usr/bin' ], '123.mac.payload' );
		$this->assertSame( '123.mac.payload', $env['BP_CHECK_HEADER'] );
		$this->assertSame( '/usr/bin', $env['PATH'] );
		$js = (string) file_get_contents( dirname( __DIR__ ) . '/bin/browser-check.js' );
		$this->assertStringContainsString( 'process.env.BP_CHECK_HEADER', $js );
	}
}
