<?php
namespace BeltoftPerformance\Check;

defined( 'ABSPATH' ) || exit;

final class Trial {
	public const SENSITIVE = array( 'cart', 'checkout', 'order_received', 'account' );

	/**
	 * True only for a trial of this very check that passed on every page in $pages (the tested set or a part of it, never more).
	 * Trials record their passing pages per page ('passed', Trial::with_passed); an older trial without it counts as a whole:
	 * ok and covering the pages.
	 */
	public static function approves( array $trial, string $check_id, array $pages ): bool {
		if ( ( $trial['check'] ?? '' ) !== $check_id || ! isset( $trial['pages'] ) || ! is_array( $trial['pages'] ) ) {
			return false;
		}
		$want = array_map( 'strval', $pages );
		if ( ! $want ) {
			return false;
		}
		if ( isset( $trial['passed'] ) && is_array( $trial['passed'] ) ) {
			return ! array_diff( $want, array_map( 'strval', $trial['passed'] ) );
		}
		return ! empty( $trial['ok'] ) && ! array_diff( $want, array_map( 'strval', $trial['pages'] ) );
	}

	/**
	 * Pure: the pages of a trial that passed on their own: no evidence row of the page has a problem, and a row that needs the
	 * browser pass has one that succeeded for that page (browser.pages; an older browser result without pages covers every page).
	 */
	public static function passed_pages( array $trial ): array {
		$browser_ok    = ! empty( $trial['browser']['ok'] );
		$browser_pages = array_map( 'strval', (array) ( $trial['browser']['pages'] ?? $trial['pages'] ?? array() ) );
		$state         = array();
		foreach ( (array) ( $trial['evidence'] ?? array() ) as $row ) {
			$page = (string) ( $row['page'] ?? '' );
			if ( '' === $page ) {
				continue;
			}
			$fine           = empty( $row['problem'] ) && ( empty( $row['needs_browser'] ) || ( $browser_ok && in_array( $page, $browser_pages, true ) ) );
			$state[ $page ] = ( $state[ $page ] ?? true ) && $fine;
		}
		if ( isset( $trial['browser'] ) && ! $browser_ok ) {
			foreach ( $browser_pages as $page ) {
				$state[ $page ] = false; // a failed browser pass fails every page it browsed
			}
		}
		$pages = array_map( 'strval', (array) ( $trial['pages'] ?? array() ) );
		return array_values( array_filter( $pages, static fn( $p ) => ! empty( $state[ $p ] ) ) );
	}

	/** Pure: $trial with 'passed' (passed_pages) and 'ok' (every tested page passed) worked out from its evidence. */
	public static function with_passed( array $trial ): array {
		$trial['passed'] = self::passed_pages( $trial );
		$trial['ok']     = (bool) $trial['passed'] && ! array_diff( array_map( 'strval', (array) ( $trial['pages'] ?? array() ) ), $trial['passed'] );
		return $trial;
	}

	/**
	 * Pure: the trial to store when $new finishes while $old is stored, page by page. For the same check, the pages tested again
	 * take the new evidence and the other pages keep theirs — a pass on one page never wipes a pass or a failure of another.
	 * A browser pass is kept only while none of its pages was tested again. Another check (or nothing stored) is replaced.
	 */
	public static function merge( ?array $old, array $new ): array {
		if ( ! is_array( $old ) || ( $old['check'] ?? '' ) !== ( $new['check'] ?? '' ) || '' === (string) ( $new['check'] ?? '' ) ) {
			return self::with_passed( $new );
		}
		$again  = array_map( 'strval', (array) ( $new['pages'] ?? array() ) );
		$kept   = array_values( array_filter( (array) ( $old['evidence'] ?? array() ), static fn( $row ) => ! in_array( (string) ( $row['page'] ?? '' ), $again, true ) ) );
		$merged = $new;
		$merged['pages']    = array_values( array_unique( array_merge( array_map( 'strval', (array) ( $old['pages'] ?? array() ) ), $again ) ) );
		$merged['evidence'] = array_merge( $kept, (array) ( $new['evidence'] ?? array() ) );
		if ( ! isset( $new['browser'] ) && isset( $old['browser'] ) && ! array_intersect( $again, array_map( 'strval', (array) ( $old['browser']['pages'] ?? $old['pages'] ?? array() ) ) ) ) {
			$merged['browser'] = $old['browser'];
		}
		return self::with_passed( $merged );
	}

	/** The exact file name the Recorder writes for this trial payload. */
	public static function report_name( string $page, string $state, array $rule ): string {
		return "{$page}-{$state}-trial-" . substr( md5( wp_json_encode( array( $rule ) ) ), 0, 8 ) . '.json';
	}

	public static function cascade_from_assets( array $assets, string $kind, string $handle ): array {
		$rdeps = array();
		foreach ( $assets as $a ) {
			if ( ( $a['kind'] ?? '' ) !== $kind ) { continue; }
			foreach ( (array) ( $a['deps'] ?? array() ) as $d ) { $rdeps[ $d ][] = (string) $a['handle']; }
		}
		$seen = array(); $queue = array( $handle );
		while ( $queue ) {
			$h = array_shift( $queue );
			if ( isset( $seen[ $h ] ) ) { continue; }
			$seen[ $h ] = true;
			foreach ( $rdeps[ $h ] ?? array() as $x ) { $queue[] = $x; }
		}
		$out = array_keys( $seen ); sort( $out );
		return $out;
	}

	/** Pure: compares a trial report with its baseline. Returns [] when fine, else a row fragment (problem / needs_browser). */
	public static function problem( array $base, array $t, string $page, string $target ): array {
		list( $kind, $dir ) = array_pad( explode( ':', $target, 2 ), 2, '' );
		if ( 'script' === $kind || 'style' === $kind ) {
			$on_page = false;
			foreach ( (array) ( $base['assets'] ?? array() ) as $a ) {
				if ( ( $a['kind'] ?? '' ) === $kind && ( $a['handle'] ?? '' ) === $dir ) {
					$on_page = true;
				}
			}
			if ( ! $on_page ) {
				return array( 'problem' => __( 'This file does not appear on this page.', 'beltoft-performance' ) );
			}
			foreach ( (array) ( $t['assets'] ?? array() ) as $a ) {
				if ( ( $a['kind'] ?? '' ) === $kind && ( $a['handle'] ?? '' ) === $dir ) {
					return array( 'problem' => __( 'The trial did not remove the file.', 'beltoft-performance' ) );
				}
			}
			$allowed = array_flip( array_map( static fn( $h ) => $kind . ':' . $h, self::cascade_from_assets( (array) ( $base['assets'] ?? array() ), $kind, $dir ) ) );
			$gone    = array_values( array_filter( self::gone_handles( $base, $t, null ), static fn( $k ) => ! isset( $allowed[ $k ] ) ) );
			if ( $gone ) {
				return array( 'problem' => sprintf( __( 'Also removes files from other plugins: %s', 'beltoft-performance' ), implode( ', ', $gone ) ), 'gone' => $gone );
			}
			if ( ( $t['status'] ?? 0 ) !== ( $base['status'] ?? 0 ) ) {
				return array( 'problem' => sprintf( __( 'HTTP status changed: %1$d → %2$d', 'beltoft-performance' ), (int) ( $base['status'] ?? 0 ), (int) ( $t['status'] ?? 0 ) ) );
			}
			if ( count( (array) ( $t['errors'] ?? array() ) ) > count( (array) ( $base['errors'] ?? array() ) ) ) {
				return array( 'problem' => __( 'New PHP errors.', 'beltoft-performance' ) );
			}
			if ( ( $t['html_hash'] ?? '' ) !== ( $base['html_hash'] ?? '' ) ) {
				return array( 'problem' => __( 'The page content changed (beyond the removed files).', 'beltoft-performance' ) );
			}
			$facts = self::response_problem( $base, $t );
			if ( $facts ) {
				return $facts;
			}
			return array( 'needs_browser' => true );
		}
		if ( 'unload' === $kind && in_array( $dir, (array) ( $t['plugins'] ?? array() ), true ) ) {
			return array( 'problem' => __( 'The trial did not remove the plugin.', 'beltoft-performance' ) );
		}
		if ( 'plugin' === $kind ) {
			foreach ( (array) ( $t['assets'] ?? array() ) as $a ) {
				if ( ( $a['plugin'] ?? '' ) === $dir ) {
					return array( 'problem' => __( 'The trial did not remove the plugin.', 'beltoft-performance' ) );
				}
			}
		}
		$gone = self::gone_handles( $base, $t, $dir );
		if ( $gone ) {
			return array( 'problem' => sprintf( __( 'Also removes files from other plugins: %s', 'beltoft-performance' ), implode( ', ', $gone ) ), 'gone' => $gone );
		}
		if ( ( $t['status'] ?? 0 ) !== ( $base['status'] ?? 0 ) ) {
			return array( 'problem' => sprintf( __( 'HTTP status changed: %1$d → %2$d', 'beltoft-performance' ), (int) ( $base['status'] ?? 0 ), (int) ( $t['status'] ?? 0 ) ) );
		}
		if ( count( (array) ( $t['errors'] ?? array() ) ) > count( (array) ( $base['errors'] ?? array() ) ) ) {
			return array( 'problem' => __( 'New PHP errors.', 'beltoft-performance' ) );
		}
		if ( ( $t['html_hash'] ?? '' ) !== ( $base['html_hash'] ?? '' ) ) {
			return array( 'problem' => __( 'The page content changed (beyond the removed files).', 'beltoft-performance' ) );
		}
		$facts = self::response_problem( $base, $t );
		if ( $facts ) {
			return $facts;
		}
		if ( in_array( $page, self::SENSITIVE, true ) ) {
			return array( 'needs_browser' => true ); // cart/checkout/account: only ok after the browser pass (bin/browser-check.js)
		}
		return array();
	}

	/** Pure: a problem row when the trial sets other cookies or redirects elsewhere (sessions, tracking, login); [] when equal or not recorded. */
	public static function response_problem( array $base, array $t ): array {
		if ( isset( $base['cookies'], $t['cookies'] ) ) {
			$a = array_map( 'strval', (array) $base['cookies'] );
			$b = array_map( 'strval', (array) $t['cookies'] );
			sort( $a );
			sort( $b );
			if ( $a !== $b ) {
				/* translators: 1: cookie names before, 2: cookie names in the trial */
				return array( 'problem' => sprintf( __( 'The response sets other cookies: %1$s → %2$s', 'beltoft-performance' ), $a ? implode( ', ', $a ) : '-', $b ? implode( ', ', $b ) : '-' ) );
			}
		}
		if ( array_key_exists( 'location', $base ) && array_key_exists( 'location', $t ) && (string) $base['location'] !== (string) $t['location'] ) {
			/* translators: 1: redirect target before, 2: in the trial */
			return array( 'problem' => sprintf( __( 'The redirect changed: %1$s → %2$s', 'beltoft-performance' ), '' !== (string) $base['location'] ? $base['location'] : '-', '' !== (string) $t['location'] ? $t['location'] : '-' ) );
		}
		return array();
	}

	/** Pure: "kind:handle" printed in the baseline but not in the trial, owned by anything other than $plugin (core, theme or another plugin); a null $plugin means no owner filter. */
	public static function gone_handles( array $base, array $t, ?string $plugin ): array {
		$seen = array();
		foreach ( (array) ( $t['assets'] ?? array() ) as $a ) {
			$seen[ ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' ) ] = true;
		}
		$gone = array();
		foreach ( (array) ( $base['assets'] ?? array() ) as $a ) {
			$k = ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' );
			if ( ( null === $plugin || ( $a['plugin'] ?? '' ) !== $plugin ) && ! isset( $seen[ $k ] ) ) {
				$gone[ $k ] = true;
			}
		}
		return array_keys( $gone );
	}

	/** Pure: the baseline a trial is judged against. A fresh 200 report wins; otherwise the stored one, flagged stale. @return array{0:array,1:bool} */
	public static function pick_baseline( ?array $fresh, array $stored, ?int $mtime = null, ?int $start = null ): array {
		if ( null !== $mtime && null !== $start && $mtime < $start ) {
			return array( $stored, true ); // the report on disk predates the fresh fetch
		}
		if ( is_array( $fresh ) && 200 === (int) ( $fresh['status'] ?? 0 ) ) {
			return array( $fresh, false );
		}
		return array( $stored, true );
	}

	/** @return array{ok:bool,evidence:array} */
	public static function run( string $id, array $candidate ): array {
		$inv      = Runner::inventory( $id );
		$targets  = Runner::targets();
		$evidence = array();
		$rule     = array( 'target' => $candidate['target'], 'pages' => $candidate['pages'], 'hosts' => array( 'retail' ) );
		$jar      = (array) get_option( 'beltoft_performance_cart_cookies_' . $id, array() ); // the session the baseline used
		$off_on = array();
		foreach ( \BeltoftPerformance\Rules\Store::all() as $r ) {
			if ( $r->target === $candidate['target'] && in_array( 'retail', $r->hosts, true ) ) {
				$off_on = array_merge( $off_on, $r->pages );
			}
		}
		foreach ( $candidate['pages'] as $page ) {
			if ( in_array( $page, $off_on, true ) ) {
				// The check's own fetches honour the rules, so with and without would both be "without": nothing to compare.
				$evidence[] = array( 'page' => $page, 'state' => '', 'problem' => __( 'It is already switched off here. Switch it back on to test it.', 'beltoft-performance' ) );
				continue;
			}
			if ( empty( $targets[ $page ] ) || empty( $inv[ $page ] ) ) {
				$evidence[] = array( 'page' => $page, 'state' => '', 'problem' => __( 'No test page for this type.', 'beltoft-performance' ) );
				continue;
			}
			foreach ( $inv[ $page ] as $state => $base ) {
				$row  = array( 'page' => $page, 'state' => $state );
				$file = Runner::dir( $id ) . '/' . self::report_name( $page, $state, $rule );
				if ( is_file( $file ) ) {
					@unlink( $file );
				}
				$cookies = 'cart' === $state ? $jar : array();
				$bfile   = Runner::dir( $id ) . "/{$page}-{$state}.json";
				$start   = time();
				clearstatcache( true, $bfile );
				$br      = Runner::fetch( $targets[ $page ], array( 'id' => $id, 'state' => $state ), $cookies ); // fresh baseline: the page drifts between check and trial
				$fresh   = null;
				if ( ! is_wp_error( $br ) && 200 === (int) wp_remote_retrieve_response_code( $br ) && is_file( $bfile ) ) {
					$fresh = json_decode( (string) file_get_contents( $bfile ), true );
					$fresh = is_array( $fresh ) ? $fresh : null;
				}
				clearstatcache( true, $bfile );
				list( $base, $stale ) = self::pick_baseline( $fresh, $base, is_file( $bfile ) ? (int) filemtime( $bfile ) : null, $start );
				if ( $stale ) {
					$row['baseline'] = 'stale';
				}
				$r = Runner::fetch( $targets[ $page ], array( 'id' => $id, 'state' => $state, 'trial' => array( $rule ) ), $cookies );
				$t = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
				if ( is_wp_error( $r ) || 200 !== (int) wp_remote_retrieve_response_code( $r ) ) {
					$row['problem'] = __( 'The trial request failed.', 'beltoft-performance' );
				} elseif ( ! is_array( $t ) ) {
					$row['problem'] = __( 'No trial report.', 'beltoft-performance' );
				} else {
					$row += self::problem( $base, $t, $page, $candidate['target'] );
				}
				$evidence[] = $row;
			}
		}
		$ok = (bool) $evidence;
		foreach ( $evidence as $row ) {
			$ok = $ok && empty( $row['problem'] ) && empty( $row['needs_browser'] );
		}
		return array( 'ok' => $ok, 'evidence' => $evidence );
	}
}
