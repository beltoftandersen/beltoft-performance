<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Runner;

/** Single-page checks merged into the last check, and the progress bar's numbers. */
final class RunnerPartialTest extends TestCase {
	private array $targets = [ 'front' => 'https://x/', 'checkout' => 'https://x/c/', 'product' => 'https://x/p/' ];
	private array $samples = [ 'variable' => 'https://x/v/' ];

	public function test_plan_skips_guest_checkout_and_adds_samples_only_with_product(): void {
		$all = Runner::plan( $this->targets, $this->samples, [], [ 'guest', 'logged_in' ] );
		$this->assertSame( [ 'front|guest', 'front|logged_in', 'checkout|logged_in', 'product|guest', 'product|logged_in', 'product-variable|guest', 'product-variable|logged_in' ], array_map( fn( $r ) => $r[0] . '|' . $r[1], $all ) );
		$this->assertSame( 'product-variable', $all[5][3] );
		$front = Runner::plan( $this->targets, $this->samples, [ 'front' ], [ 'guest', 'cart', 'logged_in' ] );
		$this->assertCount( 3, $front );
		$this->assertSame( [], Runner::plan( $this->targets, $this->samples, [ 'nope' ], [ 'guest' ] ) );
	}

	public function test_merge_replaces_only_the_pages_and_states_loaded_again(): void {
		$old = [ [ 'page' => 'front', 'state' => 'guest', 'code' => 200 ], [ 'page' => 'front', 'state' => 'cart', 'code' => 200 ], [ 'page' => 'checkout', 'state' => 'logged_in', 'code' => 302 ] ];
		$new = [ [ 'page' => 'checkout', 'state' => 'logged_in', 'code' => 200 ] ];
		$m   = Runner::merge_fetched( $old, $new );
		$this->assertCount( 3, $m );
		$this->assertSame( 200, $m[2]['code'] );
		// A one-state re-check keeps the page's other states.
		$m = Runner::merge_fetched( $old, [ [ 'page' => 'front', 'state' => 'guest', 'code' => 500 ] ] );
		$this->assertSame( [ 'front|cart', 'checkout|logged_in', 'front|guest' ], array_map( fn( $r ) => $r['page'] . '|' . $r['state'], $m ) );
	}

	public function test_progress_view(): void {
		$now = 1000000;
		$this->assertNull( Runner::progress_view( [], $now ) );
		$this->assertNull( Runner::progress_view( [ 'done' => 3, 'total' => 3, 'started' => $now - 5, 'finished' => $now ], $now ) );
		$this->assertNull( Runner::progress_view( [ 'done' => 1, 'total' => 9, 'started' => $now - 901, 'finished' => 0 ], $now ), 'stuck: hidden after 15 min' );
		$v = Runner::progress_view( [ 'done' => 12, 'total' => 44, 'started' => $now - 30, 'finished' => 0, 'pages' => [] ], $now );
		$this->assertSame( [ 'done' => 12, 'total' => 44, 'queued' => false, 'pages' => [] ], $v );
		$this->assertTrue( Runner::progress_view( [ 'done' => 0, 'total' => 0, 'started' => $now, 'finished' => 0, 'pages' => [ 'front' ] ], $now )['queued'] );
		$this->assertSame( 5, Runner::progress_view( [ 'done' => 9, 'total' => 5, 'started' => $now ], $now )['done'], 'never more than the total' );
	}

	public function test_heartbeat_frees_the_buttons_when_a_run_dies(): void {
		$now = 1000000;
		$this->assertNotNull( Runner::progress_view( [ 'done' => 5, 'total' => 44, 'started' => $now - 1200, 'updated' => $now - 30, 'finished' => 0 ], $now ), 'a long run that keeps loading pages stays visible' );
		$this->assertNull( Runner::progress_view( [ 'done' => 5, 'total' => 44, 'started' => $now - 300, 'updated' => $now - 181, 'finished' => 0 ], $now ), 'no page load for 3 minutes: the run died' );
		$this->assertNull( Runner::progress_view( [ 'done' => 0, 'total' => 0, 'started' => $now - 181, 'finished' => 0 ], $now ), 'queued but never started (cron not running)' );
	}

	public function test_a_recheck_drops_only_the_trials_of_that_check_and_page(): void {
		$trials = [
			'unload:a' => [ 'check' => 'C1', 'pages' => [ 'front', 'shop' ] ],
			'unload:b' => [ 'check' => 'C1', 'pages' => [ 'blog' ] ],
			'unload:c' => [ 'check' => 'C0', 'pages' => [ 'front' ] ],
			'style:d'  => [ 'check' => 'C1', 'pages' => [ 'product-variable' ] ],
		];
		$this->assertSame( [ 'unload:a' ], Runner::stale_trials( $trials, 'C1', [ 'front' ] ) );
		$this->assertSame( [ 'style:d' ], Runner::stale_trials( $trials, 'C1', [ 'product' ] ), 'product samples count as product' );
		$this->assertSame( [], Runner::stale_trials( $trials, 'C1', [ 'account' ] ) );
	}

	public function test_progress_text(): void {
		$this->assertSame( 'Checking the shop: starting…', Runner::progress_text( [ 'queued' => true, 'done' => 0, 'total' => 0 ], 'the shop' ) );
		$this->assertSame( 'Checking Home page: 2 of 3 page loads', Runner::progress_text( [ 'queued' => false, 'done' => 2, 'total' => 3 ], 'Home page' ) );
		$this->assertSame( 'Checking Home page: 1 of 1 page load', Runner::progress_text( [ 'queued' => false, 'done' => 1, 'total' => 1 ], 'Home page' ) );
	}
}
