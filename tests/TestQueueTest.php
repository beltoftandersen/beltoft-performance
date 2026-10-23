<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\TestQueue;

/** The options table as $wpdb sees it: the row itself, never a cached copy. */
final class BpTestQueueDb {
	/** @var array<string,callable> the stubs below (kept out of $GLOBALS: separate-process tests serialize globals) */
	public static $fn = array();
	public $options = 'wp_options';
	public function prepare( $sql, ...$args ) { return array( $sql, $args ); }
	public function get_var( $q ) {
		[ $sql, $args ] = $q;
		if ( false !== strpos( $sql, 'GET_LOCK' ) || false !== strpos( $sql, 'RELEASE_LOCK' ) ) {
			return '1';
		}
		return array_key_exists( $args[0], $GLOBALS['bp_test_options'] ) ? serialize( $GLOBALS['bp_test_options'][ $args[0] ] ) : null;
	}
}

// WordPress seams for the stored-queue tests: the options table is $GLOBALS['bp_test_options'] (update_option writes it), the object cache is
// $GLOBALS['bp_test_cache'], WP-Cron is $GLOBALS['bp_test_cron'] [ hook => timestamp ].
foreach ( array(
	'wp_cache_delete'          => static function ( $k, $g = '' ) { unset( $GLOBALS['bp_test_cache'][ $g ][ $k ] ); return true; },
	'wp_cache_get'             => static function ( $k, $g = '' ) { return $GLOBALS['bp_test_cache'][ $g ][ $k ] ?? false; },
	'wp_cache_set'             => static function ( $k, $v, $g = '' ) { $GLOBALS['bp_test_cache'][ $g ][ $k ] = $v; return true; },
	'maybe_unserialize'        => static function ( $v ) { return is_string( $v ) && false !== @unserialize( $v ) ? unserialize( $v ) : $v; },
	'wp_next_scheduled'        => static function ( $h ) { return $GLOBALS['bp_test_cron'][ $h ] ?? false; },
	'wp_schedule_single_event' => static function ( $t, $h ) { $GLOBALS['bp_test_cron'][ $h ] = (int) $t; return true; },
	'wp_clear_scheduled_hook'  => static function ( $h ) { unset( $GLOBALS['bp_test_cron'][ $h ] ); return 1; },
	'spawn_cron'               => static function () { $GLOBALS['bp_test_spawned'] = ( $GLOBALS['bp_test_spawned'] ?? 0 ) + 1; return true; },
) as $fn => $impl ) {
	if ( ! function_exists( $fn ) ) {
		BpTestQueueDb::$fn[ $fn ] = $impl;
		eval( "function $fn( ...\$a ) { return BpTestQueueDb::\$fn['$fn']( ...\$a ); }" );
	}
}

/** The background test queue: pure state transitions, progress text, and the stored queue (fresh reads, merge on finish, retry schedule). */
final class TestQueueTest extends TestCase {
	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $GLOBALS['wp_filter'][ 'option_' . TestQueue::OPTION ] );
		$GLOBALS['bp_test_options'] = array();
		$GLOBALS['bp_test_cron']    = array();
		$GLOBALS['bp_test_cache']   = array();
	}

	/** spawn_cron() ignores DISABLE_WP_CRON: it would run a 1–2 min test inside a wp-cron.php web request (PHP-FPM request_terminate_timeout). With system cron the queue waits for it. */
	public function test_no_loopback_spawn_when_system_cron_runs_the_queue(): void {
		defined( 'DISABLE_WP_CRON' ) || define( 'DISABLE_WP_CRON', true );
		$GLOBALS['wpdb']            = new BpTestQueueDb();
		$GLOBALS['bp_test_options'] = array( 'beltoft_performance_last_check' => array( 'id' => 'c1' ) );
		$GLOBALS['bp_test_spawned'] = 0;
		TestQueue::enqueue_candidates( array( 'unload:a' => array( 'shop' ) ), 'c1' );
		$this->assertArrayHasKey( TestQueue::HOOK, $GLOBALS['bp_test_cron'] );
		$this->assertSame( 0, $GLOBALS['bp_test_spawned'] );
	}
	public function test_enqueue_skips_duplicates_and_keeps_order(): void {
		$q = TestQueue::enqueue( TestQueue::EMPTY, [ 'unload:a' => [ 'shop' ], 'unload:b' => [ 'shop', 'blog' ] ], 'c1', 100 );
		$q = TestQueue::enqueue( $q, [ 'unload:b' => [ 'shop' ], 'unload:c' => [ 'shop' ] ], 'c1', 101 );
		$this->assertSame( 'c1', $q['check'] );
		$this->assertSame( [ 'unload:a', 'unload:b', 'unload:c' ], array_keys( $q['waiting'] ) );
		$this->assertSame( [ 'shop', 'blog' ], $q['waiting']['unload:b'] ); // first entry kept
		[ $q, $t ] = TestQueue::next( $q, 200 );
		$q = TestQueue::enqueue( $q, [ 'unload:a' => [ 'shop' ] ], 'c1', 201 ); // running: not queued again
		$this->assertSame( [ 'unload:b', 'unload:c' ], array_keys( $q['waiting'] ) );
		$q = TestQueue::finish( $q, 'unload:a' );
		$q = TestQueue::enqueue( $q, [ 'unload:a' => [ 'shop' ] ], 'c1', 202 ); // done in this batch: may be tested again (Test again)
		$this->assertSame( [ 'unload:b', 'unload:c', 'unload:a' ], array_keys( $q['waiting'] ) );
	}

	public function test_a_new_check_starts_a_fresh_queue(): void {
		$q = TestQueue::enqueue( TestQueue::EMPTY, [ 'unload:a' => [ 'shop' ] ], 'c1', 100 );
		$q = TestQueue::enqueue( $q, [ 'unload:b' => [ 'shop' ] ], 'c2', 101 );
		$this->assertSame( 'c2', $q['check'] );
		$this->assertSame( [ 'unload:b' ], array_keys( $q['waiting'] ) );
		$this->assertSame( TestQueue::EMPTY, TestQueue::normalise( $q, 'c3', 102 ) ); // stale check: dropped
		$this->assertSame( $q, TestQueue::normalise( $q, 'c2', 102 ) );
	}

	public function test_next_takes_one_at_a_time_and_a_stuck_lock_times_out(): void {
		$q = TestQueue::enqueue( TestQueue::EMPTY, [ 'unload:a' => [ 'shop' ], 'unload:b' => [ 'shop' ] ], 'c1', 100 );
		[ $q, $t ] = TestQueue::next( $q, 200 );
		$this->assertSame( 'unload:a', $t );
		$this->assertSame( [ 'target' => 'unload:a', 'since' => 200 ], $q['running'] );
		[ $q2, $t2 ] = TestQueue::next( $q, 300 );
		$this->assertNull( $t2 ); // locked
		$this->assertSame( $q, $q2 );
		$stuck = TestQueue::normalise( $q, 'c1', 200 + 601 );
		$this->assertNull( $stuck['running'] );
		$this->assertSame( [ 'unload:a' ], $stuck['done'] );
		[ , $t3 ] = TestQueue::next( $q, 200 + 601 ); // next() also releases a stuck lock
		$this->assertSame( 'unload:b', $t3 );
		$q = TestQueue::finish( $q, 'unload:a' );
		$this->assertNull( $q['running'] );
		$this->assertSame( [ 'unload:a' ], $q['done'] );
		[ $q, $t ] = TestQueue::next( $q, 400 );
		$q = TestQueue::finish( $q, $t );
		$this->assertFalse( TestQueue::active( $q ) );
		[ , $none ] = TestQueue::next( $q, 500 );
		$this->assertNull( $none );
	}

	public function test_states_and_progress_text(): void {
		$q = TestQueue::enqueue( TestQueue::EMPTY, [ 'unload:a' => [ 'shop' ], 'unload:b' => [ 'shop' ], 'unload:c' => [ 'shop' ] ], 'c1', 100 );
		$this->assertSame( '', TestQueue::progress_text( TestQueue::EMPTY ) );
		[ $q ] = TestQueue::next( $q, 200 );
		$this->assertSame( 'running', TestQueue::state_of( $q, 'unload:a' ) );
		$this->assertSame( 'waiting', TestQueue::state_of( $q, 'unload:b' ) );
		$this->assertSame( '', TestQueue::state_of( $q, 'unload:x' ) );
		$this->assertSame( 'Testing 1 of 3 in the background — this page updates by itself when each one is done.', TestQueue::progress_text( $q ) );
		$q = TestQueue::finish( $q, 'unload:a' );
		[ $q ] = TestQueue::next( $q, 300 );
		$this->assertSame( 'Testing 2 of 3 in the background — this page updates by itself when each one is done.', TestQueue::progress_text( $q ) );
		$this->assertSame( 'Testing 3 suggestions in the background — 1 done so far.', TestQueue::status_text( $q ) );
		$this->assertSame( '', TestQueue::status_text( TestQueue::EMPTY ) );
	}

	private function stored(): array {
		return $GLOBALS['bp_test_options'][ TestQueue::OPTION ];
	}

	/** I1: a test finishing after 1–2 minutes must not overwrite what the admin queued meanwhile — even when this process holds a stale cached copy. */
	public function test_an_item_added_during_a_run_survives_its_finish(): void {
		$GLOBALS['wpdb']            = new BpTestQueueDb();
		$GLOBALS['bp_test_options'] = array( 'beltoft_performance_last_check' => array( 'id' => 'c1' ) );
		$GLOBALS['bp_test_cron']    = array();
		TestQueue::enqueue_candidates( array( 'unload:a' => array( 'shop' ) ), 'c1' );
		[ $check, $target ] = TestQueue::claim();
		$this->assertSame( array( 'c1', 'unload:a' ), array( $check, $target ) );
		$at_claim = $this->stored();
		// This (cron) process's caches still hold the queue as it was when the run started: get_option would return it.
		$GLOBALS['wp_filter'][ 'option_' . TestQueue::OPTION ] = array( static fn() => $at_claim );
		// Meanwhile the admin queues another item (another process: it writes the row).
		TestQueue::enqueue_candidates( array( 'unload:b' => array( 'blog' ) ), 'c1' );
		$this->assertSame( array( 'unload:b' ), array_keys( $this->stored()['waiting'] ) );
		TestQueue::complete_run( 'c1', 'unload:a' );
		unset( $GLOBALS['wp_filter'][ 'option_' . TestQueue::OPTION ] );
		$q = $this->stored();
		$this->assertSame( array( 'unload:b' ), array_keys( $q['waiting'] ), 'the item queued during the run is still waiting' );
		$this->assertNull( $q['running'] );
		$this->assertSame( array( 'unload:a' ), $q['done'] );
		$this->assertSame( array( 'unload:b' ), array_keys( TestQueue::load()['waiting'] ) );
	}

	public function test_load_reads_the_row_not_a_cached_copy(): void {
		$GLOBALS['wpdb']            = new BpTestQueueDb();
		$GLOBALS['bp_test_options'] = array( 'beltoft_performance_last_check' => array( 'id' => 'c1' ) );
		TestQueue::enqueue_candidates( array( 'unload:a' => array( 'shop' ) ), 'c1' );
		$GLOBALS['bp_test_cache']['options'][ TestQueue::OPTION ] = TestQueue::EMPTY;
		$GLOBALS['bp_test_cache']['options']['notoptions']        = array( TestQueue::OPTION => true );
		$GLOBALS['wp_filter'][ 'option_' . TestQueue::OPTION ]    = array( static fn() => TestQueue::EMPTY );
		$this->assertSame( array( 'unload:a' ), array_keys( TestQueue::load()['waiting'] ) );
		unset( $GLOBALS['wp_filter'][ 'option_' . TestQueue::OPTION ] );
		$this->assertArrayNotHasKey( TestQueue::OPTION, $GLOBALS['bp_test_cache']['options'] ); // dropped, so update_option compares with the row
		$this->assertSame( array(), $GLOBALS['bp_test_cache']['options']['notoptions'] );
	}

	public function test_complete_leaves_another_checks_queue_alone(): void {
		$q = TestQueue::enqueue( TestQueue::EMPTY, array( 'unload:x' => array( 'shop' ) ), 'c2', 100 );
		$this->assertSame( $q, TestQueue::complete( $q, 'c1', 'unload:a' ) );
	}

	/** Minor 3: a lock held by a run that crashed gets a retry when it times out; the retry moves to now once the lock is released. */
	public function test_a_held_lock_schedules_a_retry_at_its_timeout(): void {
		$GLOBALS['wpdb']            = new BpTestQueueDb();
		$GLOBALS['bp_test_options'] = array( 'beltoft_performance_last_check' => array( 'id' => 'c1' ) );
		$GLOBALS['bp_test_cron']    = array();
		TestQueue::enqueue_candidates( array( 'unload:a' => array( 'shop' ), 'unload:b' => array( 'shop' ) ), 'c1' );
		$this->assertLessThanOrEqual( time(), $GLOBALS['bp_test_cron'][ TestQueue::HOOK ] );
		$GLOBALS['bp_test_cron'] = array(); // the event fired
		TestQueue::claim();
		$since = (int) $this->stored()['running']['since'];
		TestQueue::run_one(); // another event while the lock is held: nothing runs, a retry is scheduled at the time-out
		$this->assertSame( 'unload:a', $this->stored()['running']['target'] );
		$this->assertSame( $since + TestQueue::TIMEOUT + 1, $GLOBALS['bp_test_cron'][ TestQueue::HOOK ] );
		TestQueue::complete_run( 'c1', 'unload:a' );
		$this->assertLessThanOrEqual( time(), $GLOBALS['bp_test_cron'][ TestQueue::HOOK ] ); // moved to now: unload:b waits
		$this->assertSame( 101 + TestQueue::TIMEOUT + 1, TestQueue::next_run_at( array( 'check' => 'c1', 'running' => array( 'target' => 'x', 'since' => 101 ) ), 500 ) );
		$this->assertNull( TestQueue::next_run_at( TestQueue::EMPTY, 500 ) );
	}

	/** Minor 7: the page view counts only its own page's items; the overview card counts the whole queue; a new check cancels the waiting ones. */
	public function test_progress_counts_this_page_only_and_cancel(): void {
		$q = TestQueue::enqueue( TestQueue::EMPTY, array( 'unload:a' => array( 'shop' ), 'unload:b' => array( 'blog' ), 'unload:c' => array( 'shop', 'blog' ) ), 'c1', 100 );
		[ $q ] = TestQueue::next( $q, 200 );
		$q = TestQueue::finish( $q, 'unload:a' );
		[ $q ] = TestQueue::next( $q, 300 ); // b running, c waiting
		$this->assertSame( 'Testing 2 of 2 in the background — this page updates by itself when each one is done.', TestQueue::progress_text( $q, 'shop' ) );
		$this->assertSame( 'Testing 1 of 2 in the background — this page updates by itself when each one is done.', TestQueue::progress_text( $q, 'blog' ) );
		$this->assertSame( '', TestQueue::progress_text( $q, 'cart' ) );
		$this->assertSame( 'Testing 3 suggestions in the background — 1 done so far.', TestQueue::status_text( $q ) );
		$c = TestQueue::cancel_waiting( $q );
		$this->assertSame( array(), $c['waiting'] );
		$this->assertSame( 'unload:b', $c['running']['target'] );
		$this->assertSame( '', TestQueue::progress_text( $c, 'shop' ) ); // c cancelled, a done: nothing of the shop left
		$this->assertSame( 'Testing 1 of 1 in the background — this page updates by itself when each one is done.', TestQueue::progress_text( $c, 'blog' ) );
	}
}
