<?php
namespace BeltoftPerformance\Check;

defined( 'ABSPATH' ) || exit;

/**
 * Tests run in the background: "Test it" / "Test all for me" only queue targets; WP-Cron runs one Trial::run per event and
 * stores it exactly like the old in-request test did, then schedules the next. A test never accepts anything.
 * Queue option: { check, waiting: { target => pages }, running: { target, since } | null, done: [ targets ], pages: { target => pages } (this batch) }.
 * The top half is pure (TestQueueTest); the bottom half reads WordPress. Every write re-reads the row from the database (never a
 * cached copy) under a MySQL lock and applies its one change to that, so a test finishing after 1–2 minutes keeps what the admin queued meanwhile.
 */
final class TestQueue {
	public const OPTION  = 'beltoft_performance_test_queue';
	public const HOOK    = 'beltoft_performance_run_test';
	public const TIMEOUT = 600; // a run holding the lock longer than this is treated as dead
	public const EMPTY   = array( 'check' => '', 'waiting' => array(), 'running' => null, 'done' => array(), 'pages' => array() );
	private const LOCK   = 'beltoft_performance_test_queue';

	/* ---------------------------------------------------------------- pure */

	/** Pure: $q in its full shape. */
	private static function shape( array $q ): array {
		return array(
			'check'   => (string) ( $q['check'] ?? '' ),
			'waiting' => (array) ( $q['waiting'] ?? array() ),
			'running' => is_array( $q['running'] ?? null ) ? $q['running'] : null,
			'done'    => array_values( (array) ( $q['done'] ?? array() ) ),
			'pages'   => (array) ( $q['pages'] ?? array() ),
		);
	}

	public static function active( array $q ): bool {
		$q = self::shape( $q );
		return (bool) $q['waiting'] || null !== $q['running'];
	}

	/**
	 * Pure: $targets [ target => candidate pages ] queued for check $check. Another check's queue is replaced; a target waiting or
	 * running is not queued twice; an idle queue starts a new batch (done cleared); a target done in this batch is queued again.
	 */
	public static function enqueue( array $q, array $targets, string $check, int $now ): array {
		$q = self::shape( $q );
		if ( $q['check'] !== $check ) {
			$q = self::EMPTY;
			$q['check'] = $check;
		}
		if ( ! self::active( $q ) ) {
			$q['done']  = array();
			$q['pages'] = array();
		}
		foreach ( $targets as $target => $pages ) {
			$target = (string) $target;
			$pages = array_values( array_map( 'strval', (array) $pages ) );
			if ( isset( $q['waiting'][ $target ] ) ) { // waiting already: test the pages asked for now as well
				$q['waiting'][ $target ] = array_values( array_unique( array_merge( (array) $q['waiting'][ $target ], $pages ) ) );
				$q['pages'][ $target ]   = $q['waiting'][ $target ];
				continue;
			}
			if ( null !== $q['running'] && $q['running']['target'] === $target ) {
				continue;
			}
			$q['done']               = array_values( array_diff( $q['done'], array( $target ) ) );
			$q['waiting'][ $target ] = $pages;
			$q['pages'][ $target ]   = $pages;
		}
		return $q;
	}

	/** Pure: the queue as of $now for latest check $latest: a stale check empties it; a lock older than TIMEOUT is released (that target counts as done, untested). */
	public static function normalise( array $q, string $latest, int $now ): array {
		$q = self::shape( $q );
		if ( '' === $q['check'] || $q['check'] !== $latest ) {
			return self::EMPTY;
		}
		if ( null !== $q['running'] && $now - (int) ( $q['running']['since'] ?? 0 ) > self::TIMEOUT ) {
			$q['done'][]  = (string) $q['running']['target'];
			$q['running'] = null;
		}
		return $q;
	}

	/** Pure: [ queue, target to test now or null ]: nothing while another run holds the lock (unless it timed out). */
	public static function next( array $q, int $now ): array {
		$q = self::normalise( $q, self::shape( $q )['check'], $now );
		if ( null !== $q['running'] || ! $q['waiting'] ) {
			return array( $q, null );
		}
		$target = (string) array_key_first( $q['waiting'] );
		unset( $q['waiting'][ $target ] );
		$q['running'] = array( 'target' => $target, 'since' => $now );
		return array( $q, $target );
	}

	/** Pure: $target finished; the lock is released. */
	public static function finish( array $q, string $target ): array {
		$q = self::shape( $q );
		if ( null !== $q['running'] && $q['running']['target'] === $target ) {
			$q['running'] = null;
		}
		unset( $q['waiting'][ $target ] );
		if ( ! in_array( $target, $q['done'], true ) ) {
			$q['done'][] = $target;
		}
		return $q;
	}

	/** Pure: finish() applied to the queue as it is stored now ($fresh), unless that queue belongs to another check by now (then left alone). */
	public static function complete( array $fresh, string $check, string $target ): array {
		$fresh = self::shape( $fresh );
		return $fresh['check'] === $check ? self::finish( $fresh, $target ) : $fresh;
	}

	/** Pure: the queue without its waiting targets (a new check makes them stale); a running test finishes on its own. */
	public static function cancel_waiting( array $q ): array {
		$q = self::shape( $q );
		foreach ( array_keys( $q['waiting'] ) as $t ) {
			unset( $q['pages'][ $t ] );
		}
		$q['waiting'] = array();
		return $q;
	}

	/** Pure: waiting | running | '' for one target. */
	public static function state_of( array $q, string $target ): string {
		$q = self::shape( $q );
		if ( null !== $q['running'] && $q['running']['target'] === $target ) {
			return 'running';
		}
		return isset( $q['waiting'][ $target ] ) ? 'waiting' : '';
	}

	/** Pure: [ position of the test running now (or next), batch size, done ] — for page type $scope only ('' = the whole queue). */
	private static function counts( array $q, string $scope = '' ): array {
		$q   = self::shape( $q );
		$on  = static function ( string $t ) use ( $q, $scope ): bool {
			return '' === $scope || in_array( $scope, (array) ( $q['pages'][ $t ] ?? $q['waiting'][ $t ] ?? array() ), true );
		};
		$done    = count( array_filter( $q['done'], static fn( $t ) => $on( (string) $t ) ) );
		$waiting = count( array_filter( array_keys( $q['waiting'] ), static fn( $t ) => $on( (string) $t ) ) );
		$running = null !== $q['running'] && $on( (string) $q['running']['target'] ) ? 1 : 0;
		return array( $done + max( 1, $running ), $done + $waiting + $running, $done, $waiting + $running );
	}

	/** Pure: the calm progress line of page type $scope's view ('' = whole queue): '' when nothing of that page is waiting or running. */
	public static function progress_text( array $q, string $scope = '' ): string {
		if ( ! self::active( $q ) ) {
			return '';
		}
		[ $at, $total, , $open ] = self::counts( $q, $scope );
		if ( 0 === $open ) {
			return '';
		}
		/* translators: 1: position, 2: total */
		return sprintf( __( 'Testing %1$d of %2$d in the background — this page updates by itself when each one is done.', 'beltoft-performance' ), $at, $total );
	}

	/** Pure: the overview status line (whole queue), '' when idle. */
	public static function status_text( array $q ): string {
		if ( ! self::active( $q ) ) {
			return '';
		}
		[ , $total, $done ] = self::counts( $q );
		/* translators: 1: number of suggestions, 2: number done */
		return sprintf( _n( 'Testing %1$d suggestion in the background — %2$d done so far.', 'Testing %1$d suggestions in the background — %2$d done so far.', $total, 'beltoft-performance' ), $total, $done );
	}

	/** Pure: when the next run should happen, or null for none: now while something waits and no test runs; else the moment a running test's lock times out (a crashed run restarts on its own). */
	public static function next_run_at( array $q, int $now ): ?int {
		$q = self::shape( $q );
		if ( null !== $q['running'] ) {
			return (int) ( $q['running']['since'] ?? $now ) + self::TIMEOUT + 1;
		}
		return $q['waiting'] ? $now : null;
	}

	/* ----------------------------------------------------------- WordPress */

	/** Drops every cached copy of option $name in this process and the object cache (single entry, alloptions, notoptions). */
	private static function forget( string $name ): void {
		wp_cache_delete( $name, 'options' );
		$all = wp_cache_get( 'alloptions', 'options' );
		if ( is_array( $all ) && isset( $all[ $name ] ) ) {
			unset( $all[ $name ] );
			wp_cache_set( 'alloptions', $all, 'options' );
		}
		$not = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $not ) && isset( $not[ $name ] ) ) {
			unset( $not[ $name ] );
			wp_cache_set( 'notoptions', $not, 'options' );
		}
	}

	/** Option $name as stored in the database right now (no runtime or Redis copy). */
	public static function fresh_option( string $name, $default = array() ) {
		global $wpdb;
		self::forget( $name );
		$raw = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name ) );
		return null === $raw ? $default : maybe_unserialize( $raw );
	}

	/** Saves option $name (not autoloaded); the cache is dropped first so update_option compares with the stored row, not a stale copy. */
	private static function save_option( string $name, $value ): void {
		self::forget( $name );
		update_option( $name, $value, false );
	}

	/** Applies $change to the stored queue (fresh from the database, normalised) under a MySQL lock and saves it if it changed. @return array the new queue */
	private static function mutate( callable $change ): array {
		global $wpdb;
		$locked = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, 10 ) );
		$raw    = (array) self::fresh_option( self::OPTION, self::EMPTY );
		$last   = (array) self::fresh_option( 'beltoft_performance_last_check', array() );
		$q      = $change( self::normalise( $raw, (string) ( $last['id'] ?? '' ), time() ) );
		if ( $q !== self::shape( $raw ) ) {
			self::save_option( self::OPTION, $q );
		}
		if ( $locked ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
		return $q;
	}

	/** The stored queue, read fresh and normalised against the latest check (stale or stuck entries dropped and saved). */
	public static function load(): array {
		return self::mutate( static fn( array $q ): array => $q );
	}

	/**
	 * Queues current candidates of the latest check (others are ignored) and makes sure a run is scheduled; $page: test only that page
	 * type of each candidate ("Test on this page"; a candidate without it is skipped), '!<page>' for the pages not passed yet, '' for
	 * all its pages. @return int how many were queued
	 */
	public static function add( array $targets, string $page = '' ): int {
		$last  = (array) self::fresh_option( 'beltoft_performance_last_check', array() );
		$check = (string) ( $last['id'] ?? '' );
		if ( '' === $check ) {
			return 0;
		}
		$cands  = array();
		$trials = (array) self::fresh_option( 'beltoft_performance_trials', array() );
		foreach ( Runner::candidates( $check ) as $c ) {
			if ( ! in_array( $c['target'], $targets, true ) ) {
				continue;
			}
			if ( '' === $page ) {
				$cands[ (string) $c['target'] ] = (array) $c['pages'];
			} elseif ( '!' === $page[0] ) { // "test on the other pages": every page it has not passed on yet
				$trial = (array) ( $trials[ $c['target'] ] ?? array() );
				$rest  = array_values( array_filter( (array) $c['pages'], static fn( $p ) => ! Trial::approves( $trial, $check, array( $p ) ) ) );
				if ( $rest ) {
					$cands[ (string) $c['target'] ] = $rest;
				}
			} elseif ( in_array( $page, (array) $c['pages'], true ) ) {
				$cands[ (string) $c['target'] ] = array( $page );
			}
		}
		self::enqueue_candidates( $cands, $check );
		return count( $cands );
	}

	/** Queues [ target => pages ] of check $check onto the stored queue (read fresh) and schedules a run. */
	public static function enqueue_candidates( array $cands, string $check ): void {
		if ( $cands ) {
			self::mutate( static fn( array $q ): array => self::enqueue( $q, $cands, $check, time() ) );
			self::ensure_scheduled();
		}
	}

	/** "Check the shop again": the waiting tests belong to the old check, so they are dropped. @return int how many were cancelled */
	public static function cancel(): int {
		$n = 0;
		self::mutate( static function ( array $q ) use ( &$n ): array {
			$n = count( $q['waiting'] );
			return self::cancel_waiting( $q );
		} );
		return $n;
	}

	/** One run event at next_run_at(): moved earlier when it is due now, never doubled; none when idle. */
	public static function ensure_scheduled(): void {
		$at   = self::next_run_at( self::load(), time() );
		$next = wp_next_scheduled( self::HOOK );
		if ( null === $at ) {
			if ( false !== $next ) {
				wp_clear_scheduled_hook( self::HOOK ); // idle: a leftover time-out retry would only find nothing to do
			}
			return;
		}
		if ( false !== $next && (int) $next <= $at ) {
			return;
		}
		if ( false !== $next ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
		wp_schedule_single_event( $at, self::HOOK );
		// spawn_cron() does not check DISABLE_WP_CRON: it would run the test in a wp-cron.php web request, where PHP-FPM's
		// request_terminate_timeout (120 s on staging) can kill a long test. With DISABLE_WP_CRON the system cron (wp-cli) runs it within a minute.
		if ( $at <= time() && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) && function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
	}

	/** Takes the lock on the next waiting target. @return array{0:string,1:?string,2:array} [ check, target or null when idle or another run holds the lock, the pages it was queued for ] */
	public static function claim(): array {
		$target = null;
		$pages  = array();
		$q      = self::mutate( static function ( array $q ) use ( &$target, &$pages ): array {
			$waiting        = (array) ( $q['waiting'] ?? array() );
			[ $q, $target ] = self::next( $q, time() );
			if ( null !== $target ) {
				$pages = array_values( array_map( 'strval', (array) ( $waiting[ $target ] ?? array() ) ) );
			}
			return $q;
		} );
		return array( $q['check'], $target, $pages );
	}

	/** Pure: the candidate as it is tested — narrowed to the queued pages still among its pages; null when none is (nothing to test); all its pages when queued for none (older queue rows). */
	public static function as_queued( array $candidate, array $pages ): ?array {
		if ( ! $pages ) {
			return $candidate;
		}
		$keep = array_values( array_intersect( (array) $candidate['pages'], array_map( 'strval', $pages ) ) );
		return $keep ? Runner::narrow( $candidate, $keep ) : null;
	}

	/** Releases the lock of $target on the queue as stored now (items queued during the run stay) and schedules the next run. */
	public static function complete_run( string $check, string $target ): void {
		self::mutate( static fn( array $q ): array => self::complete( $q, $check, $target ) );
		self::ensure_scheduled();
	}

	/** The cron event: one target per run; stores the trial like the old Test button did; schedules the next. */
	public static function run_one(): void {
		[ $check, $target, $pages ] = self::claim();
		self::ensure_scheduled(); // lock held by another run: a retry when it times out; our own lock: the same, in case this run dies
		if ( null === $target ) {
			return;
		}
		foreach ( Runner::candidates( $check ) as $c ) {
			if ( $c['target'] === $target ) {
				$c = self::as_queued( $c, $pages );
				if ( null === $c ) {
					break; // the pages it was queued for are no longer suggested
				}
				$result            = Trial::run( $check, $c ) + array( 'check' => $check, 'time' => time(), 'pages' => $c['pages'] );
				$trials            = (array) self::fresh_option( 'beltoft_performance_trials', array() );
				$trials[ $target ] = Trial::merge( is_array( $trials[ $target ] ?? null ) ? $trials[ $target ] : null, $result );
				self::save_option( 'beltoft_performance_trials', $trials );
				break;
			}
		}
		self::complete_run( $check, $target );
	}
}
