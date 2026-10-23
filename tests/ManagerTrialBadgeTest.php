<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Rules\Store;

require_once __DIR__ . '/ManagerTest.php'; // wp_upload_dir / wp_date stubs

/** I4: Manager badges come from the trial state; plugin switch-offs on checkout flows need a by-hand confirmation. */
final class ManagerTrialBadgeTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	private static function trial( bool $ok, array $evidence, array $pages = [ 'blog' ], string $check = 'c1', ?bool $browser = null ): array {
		$t = [ 'ok' => $ok, 'evidence' => $evidence, 'check' => $check, 'pages' => $pages, 'time' => 1 ];
		if ( null !== $browser ) {
			$t['browser'] = [ 'ok' => $browser, 'jobs' => [], 'new_errors' => [] ];
		}
		return $t;
	}
	public function test_trial_badge(): void {
		$fine = [ [ 'page' => 'blog', 'state' => 'guest' ] ];
		$this->assertSame( 'not_tested', Manager::trial_badge( null, 'c1', 'blog' ) );
		$this->assertSame( 'not_tested', Manager::trial_badge( self::trial( true, $fine, [ 'blog' ], 'old' ), 'c1', 'blog' ) );
		$this->assertSame( 'not_tested', Manager::trial_badge( self::trial( true, $fine, [ 'shop' ] ), 'c1', 'blog' ) );
		$this->assertSame( 'trial_passed', Manager::trial_badge( self::trial( true, $fine ), 'c1', 'blog' ) );
		$this->assertSame( 'browser_passed', Manager::trial_badge( self::trial( true, $fine, [ 'blog' ], 'c1', true ), 'c1', 'blog' ) );
		$this->assertSame( 'trial_failed', Manager::trial_badge( self::trial( false, [ [ 'page' => 'blog', 'state' => 'guest', 'problem' => 'x' ] ] ), 'c1', 'blog' ) );
		$this->assertSame( 'trial_failed', Manager::trial_badge( self::trial( false, $fine, [ 'blog' ], 'c1', false ), 'c1', 'blog' ) );
		$this->assertSame( 'needs_browser', Manager::trial_badge( self::trial( false, [ [ 'page' => 'checkout', 'state' => 'cart', 'needs_browser' => true ] ], [ 'checkout' ] ), 'c1', 'checkout' ) );
	}
	public function test_badges_come_from_trials_not_candidacy(): void {
		$assets = [ [ 'kind' => 'style', 'handle' => 'x-css', 'src' => '/wp-content/plugins/x/a.css', 'plugin' => 'x', 'deps' => [] ], [ 'kind' => 'style', 'handle' => 'y-css', 'src' => '/wp-content/plugins/y/a.css', 'plugin' => 'y', 'deps' => [] ] ];
		$meta   = [ 'plugins' => [ 'x', 'y', 'z' ], 'signatures' => [ 'y' => [ 'y-' ] ] ];
		$usage  = [ 'proposed' => [ 'unload:x', 'unload:z', 'style:x-css' ], 'trials' => [ 'unload:x' => 'browser_passed', 'style:x-css' => 'trial_failed' ] ];
		$g      = array_column( Manager::group_inventory( $assets, [], 'blog', $usage, $meta ), null, 'key' );
		$this->assertSame( 'browser_passed', $g['x']['badge'] );
		$this->assertSame( 'used', $g['y']['badge'] );
		$this->assertSame( 'not_tested', $g['z']['badge'] ); // proposed, never tried: not "unused"
		$this->assertSame( 'trial_failed', $g['x']['files'][0]['badge'] );
		$this->assertSame( 'not_measured', $g['y']['files'][0]['badge'] );
	}
	public function test_flow_scopes_need_confirmation_for_plugins_and_files(): void {
		foreach ( [ 'cart', 'checkout', 'order_received', 'account', 'checkout_endpoint', 'account_endpoint' ] as $p ) {
			$this->assertTrue( Manager::needs_flow_confirmation( 'unload:x', $p ), $p );
			$this->assertTrue( Manager::needs_flow_confirmation( 'plugin:x', $p ), $p );
			$this->assertTrue( Manager::needs_flow_confirmation( 'script:x', $p ), $p );
			$this->assertTrue( Manager::needs_flow_confirmation( 'style:x', $p ), $p );
		}
		$this->assertFalse( Manager::needs_flow_confirmation( 'unload:x', 'shop' ) );
		$this->assertFalse( Manager::needs_flow_confirmation( 'script:x', 'shop' ) );
		$this->assertFalse( Manager::needs_flow_confirmation( 'script:x', 'page' ) );
		$routes = [ 'checkout' => '/finalizar/', 'account' => '/a-minha-conta/', 'cart' => '/carrinho-de-compras/', 'retail_host' => 'staging.mindthetrash.pt' ];
		$this->assertSame( 'checkout', Manager::scope_page( 'checkout', $routes ) );
		$this->assertSame( 'checkout', Manager::scope_page( 'url:/finalizar/', $routes ) );
		$this->assertSame( 'cart', Manager::scope_page( 'url:/carrinho-de-compras/', $routes ) );
		$this->assertSame( 'page', Manager::scope_page( 'url:/sobre-nos/', $routes ) );
		$this->assertSame( '', Manager::scope_page( 'template:5', $routes ) );
	}
	public function test_flow_refusals(): void {
		$trials = [ 'unload:ok' => [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'checkout' ], 'evidence' => [], 'browser' => [ 'ok' => true ] ], 'unload:nobrowser' => [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'checkout' ], 'evidence' => [] ] ];
		$r = Manager::flow_refusals( [ 'unload:ok', 'unload:nobrowser', 'unload:never', 'style:x', 'script:y' ], 'checkout', 'checkout', false, $trials, 'c1' );
		$this->assertSame( [ 'unload:nobrowser', 'unload:never', 'style:x', 'script:y' ], array_keys( $r ) );
		$this->assertSame( [], Manager::flow_refusals( [ 'script:y' ], 'checkout', 'checkout', true, [], 'c1' ) ); // ticked: allowed
		$this->assertSame( [ 'script:y' ], array_keys( Manager::flow_refusals( [ 'script:y' ], 'url:/finalizar/', 'checkout', false, [], 'c1' ) ) );
		$this->assertStringContainsString( 'I tested this flow by hand', $r['unload:never'] );
		$this->assertSame( [], Manager::flow_refusals( [ 'unload:never' ], 'checkout', 'checkout', true, [], 'c1' ) );
		$this->assertSame( [], Manager::flow_refusals( [ 'unload:never' ], 'shop', 'shop', false, [], 'c1' ) );
	}
	public function test_save_refuses_server_side_without_the_confirmation(): void {
		$notice = Manager::save( 'checkout', [ 'unload:super-socializer' => true ], 'chris' );
		$this->assertStringContainsString( 'Refused', $notice );
		$this->assertStringContainsString( 'I tested this flow by hand', $notice );
		$this->assertSame( [], Store::all() );
	}
	public function test_save_refuses_a_script_on_checkout_without_the_confirmation(): void {
		foreach ( [ 'script:soc-js', 'style:soc-css' ] as $t ) {
			$notice = Manager::save( 'checkout', [ $t => true ], 'chris' );
			$this->assertStringContainsString( 'Refused', $notice, $t );
			$this->assertStringContainsString( 'I tested this flow by hand', $notice, $t );
		}
		$this->assertSame( [], Store::all() );
	}
}
