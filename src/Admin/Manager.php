<?php
namespace BeltoftPerformance\Admin;

use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Check\Trial;
use BeltoftPerformance\Check\Usage;
use BeltoftPerformance\Rules\Compiler;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/**
 * The experts' file list of a page view ("For experts"): load a scope (page type, URL or Bricks template), switch plugins/files off or on by hand.
 * The top half is pure (tested in ManagerTest); the bottom half reads WordPress and handles the two forms.
 */
final class Manager {
	public const LOADS_OPTION = 'beltoft_performance_manual_loads';

	/* ---------------------------------------------------------------- pure */

	/** Pure: the plugin-level target for a scope — unload: where the URL tells the page apart (page types, url:), else plugin: (files only). */
	public static function plugin_target( string $scope, string $plugin ): string {
		$unload = 0 === strpos( $scope, 'url:' ) || in_array( $scope, Store::URL_PAGE_TYPES, true );
		return ( $unload ? 'unload:' : 'plugin:' ) . $plugin;
	}

	/** Pure: true when a retail rule names $target on exactly $scope. */
	private static function is_off( array $rules, string $target, string $scope ): bool {
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule && $r->target === $target && in_array( 'retail', $r->hosts, true ) && in_array( $scope, $r->pages, true ) ) {
				return true;
			}
		}
		return false;
	}

	/** Pure: the trial badge of a file if it was tried for this scope, else "unused" / "used" from measured coverage, else "not_measured"; "protected" first. */
	private static function file_badge( array $a, bool $protected, array $usage ): string {
		if ( $protected ) {
			return 'protected';
		}
		$kind  = (string) ( $a['kind'] ?? '' );
		$trial = (string) ( $usage['trials'][ $kind . ':' . ( $a['handle'] ?? '' ) ] ?? 'not_tested' );
		if ( 'not_tested' !== $trial ) {
			return $trial;
		}
		$path = (string) parse_url( (string) ( $a['src'] ?? '' ), PHP_URL_PATH );
		if ( '' !== $path && 'style' === $kind && isset( $usage['style'][ $path ] ) ) {
			return 0 === (int) $usage['style'][ $path ] ? 'unused' : 'used';
		}
		if ( '' !== $path && 'script' === $kind && isset( $usage['script'][ $path ] ) && is_array( $usage['script'][ $path ] ) ) {
			$s = $usage['script'][ $path ];
			return 0 === (int) ( $s['called'] ?? 0 ) && 0 === (int) ( $s['listeners'] ?? 0 ) ? 'unused' : 'used';
		}
		return 'not_measured';
	}

	/**
	 * Pure: what the stored trial of a target says for $scope: "not_tested" (none, another check, or not on this scope),
	 * "trial_failed" (a problem, or the browser pass failed), "needs_browser" (fine so far, browser pass missing),
	 * "browser_passed" (trial and browser pass), "trial_passed" (trial passed, no browser pass needed or run).
	 */
	public static function trial_badge( ?array $trial, string $check_id, string $scope ): string {
		if ( ! $trial || '' === $check_id || ( $trial['check'] ?? '' ) !== $check_id || ! in_array( $scope, (array) ( $trial['pages'] ?? array() ), true ) ) {
			return 'not_tested';
		}
		if ( isset( $trial['passed'] ) && is_array( $trial['passed'] ) ) { // per page (Trial::with_passed): only this page's rows count
			$browser_pages = array_map( 'strval', (array) ( $trial['browser']['pages'] ?? $trial['pages'] ?? array() ) );
			$browsed       = isset( $trial['browser'] ) && in_array( $scope, $browser_pages, true );
			if ( $browsed && empty( $trial['browser']['ok'] ) ) {
				return 'trial_failed';
			}
			$needs = false;
			foreach ( (array) ( $trial['evidence'] ?? array() ) as $row ) {
				if ( (string) ( $row['page'] ?? '' ) !== $scope ) {
					continue;
				}
				if ( ! empty( $row['problem'] ) ) {
					return 'trial_failed';
				}
				$needs = $needs || ! empty( $row['needs_browser'] );
			}
			if ( in_array( $scope, array_map( 'strval', $trial['passed'] ), true ) ) {
				return $browsed ? 'browser_passed' : 'trial_passed';
			}
			return $needs ? 'needs_browser' : 'trial_failed';
		}
		$needs = false;
		foreach ( (array) ( $trial['evidence'] ?? array() ) as $row ) {
			if ( ! empty( $row['problem'] ) ) {
				return 'trial_failed';
			}
			$needs = $needs || ! empty( $row['needs_browser'] );
		}
		if ( isset( $trial['browser'] ) && empty( $trial['browser']['ok'] ) ) {
			return 'trial_failed';
		}
		if ( empty( $trial['ok'] ) ) {
			return $needs ? 'needs_browser' : 'trial_failed';
		}
		return ! empty( $trial['browser']['ok'] ) ? 'browser_passed' : 'trial_passed';
	}

	/** Page types where switching a plugin, script or styling file off by hand needs "I tested this flow by hand" (or a passed trial + browser pass). */
	public const FLOW_PAGES = array( 'cart', 'checkout', 'order_received', 'account', 'checkout_endpoint', 'account_endpoint' );

	/** Pure: true for any switchable target (unload:/plugin:/script:/style:) on a cart/checkout/order received/account page type. */
	public static function needs_flow_confirmation( string $target, string $scope_page ): bool {
		return (bool) preg_match( '/^(unload|plugin|script|style):/', $target ) && in_array( $scope_page, self::FLOW_PAGES, true );
	}

	/** Pure given $routes: the page type a scope stands for (a url: scope classified by its path; '' for a template). */
	public static function scope_page( string $scope, array $routes ): string {
		if ( in_array( $scope, Store::PAGE_TYPES, true ) ) {
			return $scope;
		}
		if ( 0 === strpos( $scope, 'url:' ) ) {
			$req = \BeltoftPerformance\Request\Classifier::classify( substr( $scope, 4 ), array( 'REQUEST_METHOD' => 'GET' ), $routes, true );
			return 'page' === $req['type'] ? (string) $req['page'] : '';
		}
		return '';
	}

	/**
	 * Pure: [ target => refusal ] for the switch-offs that need the by-hand confirmation and have neither it nor a current
	 * passed trial with a passed browser pass on this scope.
	 */
	public static function flow_refusals( array $targets, string $scope, string $scope_page, bool $tested_by_hand, array $trials, string $check_id ): array {
		// A template can be the checkout's (Bricks "Finalizar"): treat every template as part of buying.
		if ( 0 === strpos( $scope, 'template:' ) ) {
			$scope_page = 'checkout';
		}
		$out = array();
		foreach ( $targets as $t ) {
			$t = (string) $t;
			if ( $tested_by_hand || ! self::needs_flow_confirmation( $t, $scope_page ) || 'browser_passed' === self::trial_badge( is_array( $trials[ $t ] ?? null ) ? $trials[ $t ] : null, $check_id, $scope ) ) {
				continue;
			}
			$out[ $t ] = __( 'Switching a plugin or file off on cart, checkout, order received or account needs "I tested this flow by hand" ticked (or a passed trial with a passed browser test).', 'beltoft-performance' );
		}
		return $out;
	}

	/**
	 * Pure: the printed files of one report, grouped by plugin for the scope.
	 * $usage: [ 'style' => [ path => used bytes ], 'script' => [ path => [ called, listeners ] ], 'trials' => [ target => trial_badge() ] ] (any key optional).
	 * $meta: [ 'plugins' => loaded plugin dirs, 'signatures' => [ plugin => needles ], 'page' => page type of the loaded page, 'templates' => its active Bricks template ids ] from the same report.
	 * Order: "switched off here, not printed any more" (__off), "off through the page type / template" (__inherited), plugins A–Z, core/theme ('') last.
	 * Group: key, plugin, protected, target (plugin toggle or null), off, inherited (scope it is already off through, or null), badge (used|trial_badge()|null), files[].
	 * File: target, kind, handle, src, size, badge, protected, off, inherited, also (other printed handles the cascade removes).
	 * Inherited: on a url:/template: scope, retail rules on the loaded page's type (and, for url:, on its active templates) already apply;
	 * those rows are ticked and locked — they are managed on that scope.
	 */
	public static function group_inventory( array $assets, array $rules, string $scope, array $usage, array $meta = array() ): array {
		$groups  = array();
		$printed = array();
		$via     = array();
		if ( ! in_array( $scope, Store::PAGE_TYPES, true ) ) {
			if ( in_array( (string) ( $meta['page'] ?? '' ), Store::PAGE_TYPES, true ) ) {
				$via[] = (string) $meta['page'];
			}
			if ( 0 === strpos( $scope, 'url:' ) ) {
				foreach ( (array) ( $meta['templates'] ?? array() ) as $tid ) {
					if ( (int) $tid > 0 ) {
						$via[] = 'template:' . (int) $tid;
					}
				}
			}
		}
		$inherited = static function ( array $targets ) use ( $rules, $via, $scope ): ?string {
			foreach ( $targets as $t ) {
				if ( self::is_off( $rules, $t, $scope ) ) {
					return null; // own rule: editable here
				}
			}
			foreach ( $via as $v ) {
				foreach ( $targets as $t ) {
					if ( self::is_off( $rules, $t, $v ) ) {
						return $v;
					}
				}
			}
			return null;
		};
		$make    = static function ( string $plugin ) use ( $rules, $scope, $usage, $meta, $inherited ): array {
			$protected = '' !== $plugin && Store::is_locked_plugin( $plugin ); // only this plugin itself; the shop core is the owner's call
			$target    = ( '' === $plugin || $protected ) ? null : self::plugin_target( $scope, $plugin );
			$badge     = null;
			if ( null !== $target ) {
				$badge = ! empty( $meta['signatures'][ $plugin ] ) ? 'used' : (string) ( $usage['trials'][ $target ] ?? 'not_tested' );
			}
			$inh = null === $target ? null : $inherited( array( 'unload:' . $plugin, 'plugin:' . $plugin ) );
			return array( 'key' => $plugin, 'plugin' => $plugin, 'protected' => $protected, 'target' => $target, 'off' => null !== $target && ( null !== $inh || self::is_off( $rules, $target, $scope ) ), 'inherited' => $inh, 'badge' => $badge, 'files' => array() );
		};
		$src_of  = array();
		foreach ( $assets as $a ) {
			$src_of[ ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' ) ] = (string) ( $a['src'] ?? '' );
		}
		foreach ( $assets as $a ) {
			$kind   = (string) ( $a['kind'] ?? '' );
			$handle = (string) ( $a['handle'] ?? '' );
			if ( ! in_array( $kind, array( 'script', 'style' ), true ) || '' === $handle || isset( $printed[ $kind . ':' . $handle ] ) ) {
				continue;
			}
			$printed[ $kind . ':' . $handle ] = true;
			$plugin = (string) ( $a['plugin'] ?? '' );
			$groups[ $plugin ] ??= $make( $plugin );
			$also      = array_values( array_diff( Trial::cascade_from_assets( $assets, $kind, $handle ), array( $handle ) ) );
			$protected = $groups[ $plugin ]['protected'] || Store::is_locked_handle( $handle, (string) ( $a['src'] ?? '' ) );
			foreach ( $also as $h ) {
				// The Suppressor skips a rule whose cascade reaches a locked file: offering the toggle would do nothing.
				$protected = $protected || Store::is_locked_handle( $h, (string) ( $src_of[ $kind . ':' . $h ] ?? '' ) );
			}
			$inh = $inherited( array( $kind . ':' . $handle ) );
			$groups[ $plugin ]['files'][] = array(
				'target'    => $kind . ':' . $handle,
				'kind'      => $kind,
				'handle'    => $handle,
				'src'       => (string) ( $a['src'] ?? '' ),
				'size'      => isset( $a['size'] ) ? (int) $a['size'] : null,
				'badge'     => self::file_badge( $a, $protected, $usage ),
				'protected' => $protected,
				'off'       => null !== $inh || self::is_off( $rules, $kind . ':' . $handle, $scope ),
				'inherited' => $inh,
				'also'      => $also,
			);
		}
		if ( 0 === strpos( self::plugin_target( $scope, 'x' ), 'unload:' ) ) {
			foreach ( (array) ( $meta['plugins'] ?? array() ) as $plugin ) {
				$plugin = (string) $plugin;
				if ( '' !== $plugin && ! Store::is_locked_plugin( $plugin ) ) { // a locked plugin without files offers nothing
					$groups[ $plugin ] ??= $make( $plugin );
				}
			}
		}
		// Rules on this scope whose target is no longer printed (they removed it): still listed, so they can be switched on.
		$shown = array();
		foreach ( $groups as $g ) {
			if ( null !== $g['target'] ) {
				$shown[ $g['target'] ] = true;
			}
		}
		$gone = array();
		foreach ( $rules as $r ) {
			if ( ! $r instanceof Rule || ! in_array( 'retail', $r->hosts, true ) || ! in_array( $scope, $r->pages, true ) || isset( $printed[ $r->target ] ) || isset( $shown[ $r->target ] ) ) {
				continue;
			}
			$gone[ $r->target ] = array( 'target' => $r->target, 'kind' => $r->kind(), 'handle' => $r->name(), 'src' => '', 'size' => null, 'badge' => 'removed', 'protected' => false, 'off' => true, 'inherited' => null, 'also' => array() );
		}
		ksort( $gone );
		$by_target = array();
		foreach ( $assets as $a ) {
			$by_target[ ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' ) ] ??= $a;
		}
		$inh_rows = array();
		foreach ( $via as $v ) {
			foreach ( $rules as $r ) {
				if ( ! $r instanceof Rule || isset( $inh_rows[ $r->target ] ) || ! in_array( 'retail', $r->hosts, true ) || ! in_array( $v, $r->pages, true ) || in_array( $scope, $r->pages, true ) ) {
					continue;
				}
				$a = $by_target[ $r->target ] ?? array();
				$inh_rows[ $r->target ] = array( 'target' => $r->target, 'kind' => $r->kind(), 'handle' => $r->name(), 'src' => (string) ( $a['src'] ?? '' ), 'size' => isset( $a['size'] ) ? (int) $a['size'] : null, 'badge' => 'inherited', 'protected' => false, 'off' => true, 'inherited' => $v, 'also' => array() );
			}
		}
		ksort( $inh_rows );
		$core = $groups[''] ?? null;
		unset( $groups[''] );
		ksort( $groups );
		$out = array();
		if ( $gone ) {
			$out[] = array( 'key' => '__off', 'plugin' => '', 'protected' => false, 'target' => null, 'off' => false, 'inherited' => null, 'badge' => null, 'files' => array_values( $gone ) );
		}
		if ( $inh_rows ) {
			$out[] = array( 'key' => '__inherited', 'plugin' => '', 'protected' => false, 'target' => null, 'off' => false, 'inherited' => $via[0] ?? null, 'badge' => null, 'files' => array_values( $inh_rows ) );
		}
		$out = array_merge( $out, array_values( $groups ) );
		if ( $core ) {
			$out[] = $core;
		}
		return $out;
	}

	/**
	 * Pure: what one Save changes on $scope only. $posted is [ target => bool switched off ] for the toggles shown.
	 * Returned rules carry just $scope (the handler passes them to Store::switch_off / switch_on, which leave other scopes alone).
	 * @return array{add:Rule[],remove:Rule[]}
	 */
	public static function rule_changes( array $current_rules, array $posted, string $scope ): array {
		$out = array( 'add' => array(), 'remove' => array() );
		ksort( $posted );
		foreach ( $posted as $target => $off ) {
			$target = (string) $target;
			if ( ! preg_match( '/^(unload|plugin|script|style):[a-z0-9][a-z0-9_.\-]*$/i', $target ) ) {
				continue;
			}
			$now = self::is_off( $current_rules, $target, $scope );
			if ( (bool) $off !== $now ) {
				$out[ $off ? 'add' : 'remove' ][] = new Rule( $target, array( $scope ), array( 'retail' ), '', '', '', array( $scope ) );
			}
		}
		return $out;
	}

	/** Pure: why a Load did not give a usable report, or null. */
	public static function load_outcome( int $status, ?array $report ): ?string {
		if ( 0 === $status ) {
			return __( 'The page could not be fetched.', 'beltoft-performance' );
		}
		if ( $status >= 300 && $status < 400 ) {
			return sprintf( __( 'The page redirects (HTTP %d): load the address it redirects to.', 'beltoft-performance' ), $status );
		}
		if ( 200 !== $status ) {
			return sprintf( __( 'The page answered HTTP %d: nothing was stored.', 'beltoft-performance' ), $status );
		}
		if ( null === $report ) {
			return __( 'The page loaded but wrote no report (served from a cache, or not a normal page): nothing was stored.', 'beltoft-performance' );
		}
		if ( 200 !== (int) ( $report['status'] ?? 0 ) ) {
			return sprintf( __( 'The page answered HTTP %d: nothing was stored.', 'beltoft-performance' ), (int) ( $report['status'] ?? 0 ) );
		}
		return null;
	}

	/** Pure: the file on disk behind a same-site src under $abspath, or '' (other hosts, traversal, empty). */
	public static function local_path( string $src, string $home, string $abspath ): string {
		if ( '' === $src ) {
			return '';
		}
		$host = parse_url( $src, PHP_URL_HOST );
		if ( null !== $host && false !== $host && strtolower( (string) $host ) !== strtolower( (string) parse_url( $home, PHP_URL_HOST ) ) ) {
			return '';
		}
		$path = (string) parse_url( $src, PHP_URL_PATH );
		if ( '' === $path || '/' !== $path[0] || str_contains( $path, '..' ) ) {
			return '';
		}
		return rtrim( $abspath, '/' ) . $path;
	}

	/** Pure: a decoded path with every segment percent-encoded again, for fetching. */
	public static function encode_path( string $path ): string {
		return implode( '/', array_map( 'rawurlencode', explode( '/', $path ) ) );
	}

	/** Pure: manual-<Ymd> report dirs beyond the 3 newest (other check dirs are never touched). */
	public static function dirs_to_prune( array $names, int $keep = 3 ): array {
		$manual = array_values( array_filter( array_map( 'strval', $names ), static fn( $n ) => (bool) preg_match( '/^manual-\d{8}$/', $n ) ) );
		rsort( $manual );
		return array_slice( $manual, $keep );
	}

	/* ----------------------------------------------------------- WordPress */

	/** The scope from a request value, normalised, or null. */
	public static function scope_from( string $raw ): ?string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return null;
		}
		if ( ! in_array( $raw, Store::PAGE_TYPES, true ) && 0 !== strpos( $raw, 'template:' ) && 0 !== strpos( $raw, 'url:' ) ) {
			$raw = 'url:' . $raw; // a bare path from the URL field
		}
		return Store::normalise_scope( $raw );
	}

	/** Published pages: [ path => title ]. */
	public static function page_titles(): array {
		static $out = null;
		if ( null !== $out ) {
			return $out;
		}
		$out = array();
		foreach ( get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 300, 'orderby' => 'title', 'order' => 'ASC' ) ) as $p ) {
			$path = (string) wp_parse_url( (string) get_permalink( $p ), PHP_URL_PATH );
			if ( '' !== $path ) {
				$out[ $path ] = (string) get_the_title( $p );
			}
		}
		return $out;
	}

	/** Published pages for the page select: [ path => "title (path)" ]. */
	public static function page_choices(): array {
		$out = array();
		foreach ( self::page_titles() as $path => $title ) {
			$out[ $path ] = $title . ' (' . $path . ')';
		}
		return $out;
	}

	/** Bricks templates that render the content of a single post/page/product: [ id => "title (type)" ]. */
	public static function template_choices(): array {
		$out = array();
		if ( ! defined( 'BRICKS_DB_TEMPLATE_SLUG' ) ) {
			return $out;
		}
		$ids = get_posts( array( 'post_type' => BRICKS_DB_TEMPLATE_SLUG, 'post_status' => 'publish', 'numberposts' => 200, 'fields' => 'ids', 'orderby' => 'title', 'order' => 'ASC', 'meta_query' => array( array( 'key' => '_bricks_template_type', 'value' => Runner::CONTENT_TEMPLATE_TYPES, 'compare' => 'IN' ) ) ) );
		foreach ( $ids as $id ) {
			$out[ (int) $id ] = get_the_title( $id ) . ' (' . get_post_meta( $id, '_bricks_template_type', true ) . ')';
		}
		return $out;
	}

	/** The stored manual load for $scope with its report, or null. */
	public static function manual_report( string $scope ): ?array {
		$load = ( (array) get_option( self::LOADS_OPTION, array() ) )[ $scope ] ?? null;
		if ( ! is_array( $load ) || empty( $load['id'] ) || empty( $load['file'] ) ) {
			return null;
		}
		$file = Runner::dir( (string) $load['id'] ) . '/' . basename( (string) $load['file'] );
		$r    = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		return is_array( $r ) ? array( 'load' => $load, 'report' => $r ) : null;
	}

	/**
	 * What the tab shows for $scope: [ report, source, usage, meta ].
	 * Page type: the newer of the last check (guest state, else any) and a manual load; usage + proposals from the last check.
	 * URL / template: only the scope's own manual load (no usage: badges say "not measured").
	 */
	public static function view_data( string $scope ): array {
		$last   = (array) get_option( 'beltoft_performance_last_check', array() );
		$manual = self::manual_report( $scope );
		$report   = null;
		$source   = '';
		$evidence = '';
		$usage    = array();
		if ( in_array( $scope, Store::PAGE_TYPES, true ) && ! empty( $last['id'] ) ) {
			$inv    = Runner::inventory( (string) $last['id'] );
			$states = (array) ( $inv[ $scope ] ?? array() );
			$report = $states['guest'] ?? ( $states ? reset( $states ) : null );
			if ( $report ) {
				$source = sprintf( __( 'Check %1$s (%2$s state)', 'beltoft-performance' ), $last['id'], (string) ( $report['state'] ?? '' ) );
				if ( $manual && (int) $manual['load']['time'] > (int) ( $last['time'] ?? 0 ) ) {
					$report   = null;
					$evidence = sprintf( __( 'Evidence badges come from check %s (same files by address), not from this load.', 'beltoft-performance' ), $last['id'] );
				}
			}
			$u     = Usage::load( (string) $last['id'] );
			$state = (string) ( ( $report ?? $manual['report'] ?? array() )['state'] ?? 'guest' );
			$usage = (array) ( $u[ $scope ][ $state ] ?? array() );
			$usage['trials'] = self::trial_badges( $scope, (string) $last['id'] );
		}
		if ( null === $report && $manual ) {
			$report = $manual['report'];
			$source = sprintf( __( 'Loaded %1$s from %2$s', 'beltoft-performance' ), wp_date( 'Y-m-d H:i', (int) $manual['load']['time'] ), (string) ( $manual['load']['url'] ?? '' ) );
		}
		$assets = array();
		foreach ( (array) ( $report['assets'] ?? array() ) as $a ) {
			$file = self::local_path( (string) ( $a['src'] ?? '' ), home_url(), ABSPATH );
			if ( '' !== $file && is_file( $file ) ) {
				$a['size'] = (int) filesize( $file );
			}
			$assets[] = $a;
		}
		return array(
			'report' => $report,
			'source'   => $source,
			'evidence' => $evidence,
			'assets'   => $assets,
			'usage'    => $usage,
			'meta'     => array( 'plugins' => (array) ( $report['plugins'] ?? array() ), 'signatures' => (array) ( $report['signatures'] ?? array() ), 'page' => (string) ( $report['page'] ?? '' ), 'templates' => array_map( 'intval', (array) ( $report['templates'] ?? array() ) ) ),
		);
	}

	/** [ target => trial_badge() ] of every stored trial, for $scope and check $check_id. */
	public static function trial_badges( string $scope, string $check_id ): array {
		$out = array();
		foreach ( (array) get_option( 'beltoft_performance_trials', array() ) as $target => $t ) {
			$out[ (string) $target ] = self::trial_badge( is_array( $t ) ? $t : null, $check_id, $scope );
		}
		return $out;
	}

	/** Inventory for Store::manual_file_error: the last check plus this scope's own manual load. */
	public static function inventory_for( string $scope ): array {
		$last = (array) get_option( 'beltoft_performance_last_check', array() );
		$inv  = ! empty( $last['id'] ) ? Runner::inventory( (string) $last['id'] ) : array();
		$m    = self::manual_report( $scope );
		if ( $m ) {
			$inv[ 'manual:' . $scope ]['guest'] = $m['report'];
		}
		return $inv;
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'beltoft-performance' ), 403 );
		}
		check_admin_referer( 'bp_' . $action );
	}

	/** The page view of $scope (Settings › Performance › Overview); '' = the overview itself. */
	public static function tab_url( string $scope ): string {
		$args = array( 'page' => OverviewPage::SLUG, 'tab' => 'scripts' );
		if ( '' !== $scope ) {
			$args['scope'] = rawurlencode( $scope );
		}
		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/** Redirect target after a handler. */
	public static function back_url( string $scope, string $notice ): string {
		return add_query_arg( 'bp_notice', rawurlencode( $notice ), self::tab_url( $scope ) );
	}

	private static function back( string $scope, string $notice ): void {
		wp_safe_redirect( self::back_url( $scope, $notice ) );
		exit;
	}

	/** Load: fetch the scope as a guest and remember its report. @return string the notice. */
	public static function load( string $scope ): string {
		$res = Runner::load_scope( $scope );
		if ( is_wp_error( $res ) ) {
			return $res->get_error_message();
		}
		return sprintf( __( 'Loaded %1$s: %2$d files.', 'beltoft-performance' ), $res['url'], (int) $res['files'] );
	}

	/** Save: applies the toggles of one scope. $tested_by_hand: the "I tested this flow by hand" box; $override: the "I know what these plugins do" box for never-removed plugins. @return string the notice. */
	public static function save( string $scope, array $posted, string $by, bool $tested_by_hand = false, bool $override = false ): string {
		$changes = self::rule_changes( Store::all(), $posted, $scope );
		$inv     = self::inventory_for( $scope );
		$done    = array( 'off' => 0, 'on' => 0 );
		$refused = array();
		$last    = (array) get_option( 'beltoft_performance_last_check', array() );
		$flow    = self::flow_refusals( array_map( static fn( Rule $r ) => $r->target, $changes['add'] ), $scope, self::scope_page( $scope, \BeltoftPerformance\Request\RouteMap::build() ), $tested_by_hand, (array) get_option( 'beltoft_performance_trials', array() ), (string) ( $last['id'] ?? '' ) );
		foreach ( $changes['add'] as $r ) {
			if ( isset( $flow[ $r->target ] ) ) {
				$refused[] = $r->target . ': ' . $flow[ $r->target ];
				continue;
			}
			$res = Store::switch_off( $r->target, array( $scope ), $by, $inv, '', $override );
			if ( true === $res ) {
				$done['off']++;
			} else {
				$refused[] = $r->target . ': ' . $res;
			}
		}
		foreach ( $changes['remove'] as $r ) {
			$res = Store::switch_on( $r->target, array( $scope ) );
			if ( true === $res ) {
				$done['on']++;
			} else {
				$refused[] = $r->target . ': ' . $res;
			}
		}
		$notice = sprintf( __( 'Saved: %1$d switched off, %2$d switched on.', 'beltoft-performance' ), $done['off'], $done['on'] );
		if ( $done['off'] || $done['on'] ) {
			$error = Compiler::change_error( true, Compiler::compile_from_store() );
			if ( null !== $error ) {
				$notice .= ' ' . $error;
			}
		}
		if ( $refused ) {
			$notice .= ' ' . sprintf( __( 'Refused: %s', 'beltoft-performance' ), implode( '; ', $refused ) );
		}
		return $notice;
	}

	public static function handle_load(): void {
		self::guard( 'manager_load' );
		$scope = self::scope_from( trim( (string) wp_unslash( $_POST['scope'] ?? '' ) ) ); // no sanitize_text_field: it strips %-encoding; normalise_scope validates
		if ( null === $scope ) {
			self::back( '', __( 'Unknown page type, URL or template.', 'beltoft-performance' ) );
		}
		self::back( $scope, self::load( $scope ) );
	}

	public static function handle_save(): void {
		self::guard( 'manager_save' );
		$scope = self::scope_from( trim( (string) wp_unslash( $_POST['scope'] ?? '' ) ) ); // no sanitize_text_field: it strips %-encoding; normalise_scope validates
		if ( null === $scope ) {
			self::back( '', __( 'Unknown page type, URL or template.', 'beltoft-performance' ) );
		}
		$posted = array();
		foreach ( (array) wp_unslash( $_POST['t'] ?? array() ) as $target => $v ) {
			$posted[ sanitize_text_field( (string) $target ) ] = '1' === (string) $v;
		}
		self::back( $scope, self::save( $scope, $posted, wp_get_current_user()->user_login, '1' === (string) wp_unslash( $_POST['bp_flow_tested'] ?? '' ), '1' === (string) wp_unslash( $_POST['bp_override_protection'] ?? '' ) ) );
	}
}
