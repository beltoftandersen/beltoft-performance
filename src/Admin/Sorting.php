<?php
namespace BeltoftPerformance\Admin;

use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Pure: sorts what the last check found into the sections a shop owner reads — Safe to switch off, Your decision, Needed here,
 * Always on, Already off — plus the overview counts, the status card and the Switched off cards. Tested in SortingTest.
 * Safety stays with Ui::suggestion() (Trial::approves) here and again in the handlers.
 */
final class Sorting {
	private const TARGET_RE = '/^(unload|plugin|script|style):[a-z0-9][a-z0-9_.\-]*$/i';

	/** [ "kind:handle" => owning plugin folder ] from every report of the inventory (core/theme files left out). */
	public static function owners( array $inv ): array {
		$out = array();
		foreach ( $inv as $states ) {
			foreach ( (array) $states as $r ) {
				foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
					$p = (string) ( $a['plugin'] ?? '' );
					if ( '' !== $p ) {
						$out[ ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' ) ] ??= $p;
					}
				}
			}
		}
		return $out;
	}

	/** True when a retail rule switches $target off on one of $scopes. */
	private static function is_off( array $rules, string $target, array $scopes ): bool {
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule && $r->target === $target && in_array( 'retail', $r->hosts, true ) && array_intersect( $scopes, $r->pages ) ) {
				return true;
			}
		}
		return false;
	}

	/** Page loads one test of $c makes (a fresh baseline and a trial per page and state). */
	private static function fetches( array $c, array $inv ): int {
		$n = 0;
		foreach ( (array) ( $c['pages'] ?? array() ) as $p ) {
			$n += max( 1, count( (array) ( $inv[ $p ] ?? array() ) ) );
		}
		return 2 * $n;
	}

	/**
	 * Pure: the files a target takes off this page, from the page's reports — every script and style of the plugin (unload:/plugin:),
	 * or the one file (script:/style:). Each once: [ target (script:h|style:h), kind, handle, file (name), size (bytes) ], scripts first.
	 */
	public static function files_of( string $target, array $reports ): array {
		list( $kind, $name ) = array_pad( explode( ':', $target, 2 ), 2, '' );
		$whole = in_array( $kind, array( 'unload', 'plugin' ), true );
		$out   = array();
		foreach ( $reports as $r ) {
			if ( 200 !== (int) ( $r['status'] ?? 0 ) ) {
				continue;
			}
			foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
				$ak = (string) ( $a['kind'] ?? '' );
				$ah = (string) ( $a['handle'] ?? '' );
				if ( ! in_array( $ak, array( 'script', 'style' ), true ) || '' === $ah ) {
					continue;
				}
				if ( $whole ? (string) ( $a['plugin'] ?? '' ) !== $name : ( $ak !== $kind || $ah !== $name ) ) {
					continue;
				}
				$src = (string) ( $a['src'] ?? '' );
				$out[ $ak . ':' . $ah ] = array(
					'target' => $ak . ':' . $ah,
					'kind'   => $ak,
					'handle' => $ah,
					'file'   => '' !== $src ? basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) : __( 'code in the page', 'beltoft-performance' ),
					'size'   => (int) ( $a['size'] ?? 0 ),
				);
			}
		}
		uasort( $out, static fn( $a, $b ) => array( $a['kind'], $a['handle'] ) <=> array( $b['kind'], $b['handle'] ) ); // script before style, then by name
		return array_values( $out );
	}

	/**
	 * One page's sections. $in: scope, page (the page type it stands for, '' for a template), reports (the reports of that page),
	 * inv (whole inventory), candidates, trials, check_id, rules (Rule[]), headers [ folder => Name ], owners, via (scopes whose rules
	 * also apply here), kept (targets the owner chose to keep on this page), queue [ target => waiting|running ] (TestQueue), templates, page_titles.
	 * @return array{safe:array,decide:array,needed:array,always:bool,off:string[],loading:int}
	 */
	public static function page_sections( array $in ): array {
		$scope   = (string) $in['scope'];
		$page    = (string) ( $in['page'] ?? '' );
		$rules   = (array) ( $in['rules'] ?? array() );
		$headers = (array) ( $in['headers'] ?? array() );
		$owners  = (array) ( $in['owners'] ?? array() );
		$via     = array_values( array_map( 'strval', (array) ( $in['via'] ?? array() ) ) );
		$kept    = array_map( 'strval', (array) ( $in['kept'] ?? array() ) );
		$flow    = in_array( $page, Manager::FLOW_PAGES, true );
		$loaded  = array();
		$sigs    = array();
		$always  = false;
		foreach ( (array) ( $in['reports'] ?? array() ) as $r ) {
			if ( 200 !== (int) ( $r['status'] ?? 0 ) ) {
				continue;
			}
			foreach ( (array) ( $r['plugins'] ?? array() ) as $p ) {
				$loaded[ (string) $p ] = true;
			}
			foreach ( array_keys( (array) ( $r['signatures'] ?? array() ) ) as $p ) {
				$sigs[ (string) $p ] = true;
			}
			foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
				$always = $always || '' === (string) ( $a['plugin'] ?? '' );
			}
		}

		$by = array();
		foreach ( (array) ( $in['candidates'] ?? array() ) as $c ) {
			$by[ (string) ( $c['target'] ?? '' ) ] = $c;
		}
		$safe       = array();
		$decide     = array();
		$plugin_cand = array();
		foreach ( $by as $target => $c ) {
			$target = (string) $target;
			$s      = Ui::suggestion( $target, $by, (array) ( $in['trials'] ?? array() ), (string) ( $in['check_id'] ?? '' ), $scope, $rules );
			if ( null === $s ) {
				continue;
			}
			$kind   = (string) strstr( $target, ':', true );
			$plugin = in_array( $kind, array( 'unload', 'plugin' ), true );
			if ( $plugin ) {
				$plugin_cand[ (string) substr( $target, strlen( $kind ) + 1 ) ] = true;
			}
			$name = Words::thing_name( $target, $owners, $headers );
			if ( $s['accept'] ) {
				$trial   = is_array( $in['trials'][ $target ] ?? null ) ? $in['trials'][ $target ] : null;
				$browser = 'browser_passed' === Manager::trial_badge( $trial, (string) ( $in['check_id'] ?? '' ), $scope );
				$detail = $plugin ? __( 'Adds nothing visible to this page.', 'beltoft-performance' ) : __( 'Its styling isn\'t used on this page.', 'beltoft-performance' );
				$proven = array_values( array_filter( (array) $s['pages'], static fn( $p ) => \BeltoftPerformance\Check\Trial::approves( (array) $trial, (string) ( $in['check_id'] ?? '' ), array( $p ) ) ) );
				if ( ! $s['all'] ) {
					$detail .= ' ' . ( array( $scope ) === $proven ? __( 'Tested on this page only so far.', 'beltoft-performance' ) : __( 'Not tested on all its pages yet.', 'beltoft-performance' ) );
				}
				if ( $browser ) {
					$detail .= ' ' . __( 'Also checked in a real browser on phone and computer.', 'beltoft-performance' );
				}
				$safe[] = array( 'target' => $target, 'name' => $name, 'detail' => $detail, 'all' => (bool) $s['all'], 'pages' => $s['pages'], 'proven' => $proven, 'files' => self::files_of( $target, (array) ( $in['reports'] ?? array() ) ), 'plugin' => $plugin );
				continue;
			}
			$time   = Words::test_time( self::fetches( $c, (array) ( $in['inv'] ?? array() ) ) );
			$action = 'none';
			$queued = (string) ( $in['queue'][ $target ] ?? '' );
			if ( 'running' === $queued ) {
				$detail = __( 'Testing now…', 'beltoft-performance' );
				$action = 'queued';
			} elseif ( 'waiting' === $queued ) {
				$detail = __( 'Waiting to be tested.', 'beltoft-performance' );
				$action = 'queued';
			} elseif ( 'failed' === $s['state'] ) {
				$detail = Words::fail_reason( $s['reason'] ) . ' ' . __( 'Better keep it, or test it again.', 'beltoft-performance' );
				$action = 'retest';
			} elseif ( 'script' === $kind ) {
				$detail = __( 'Didn\'t run when the page loaded. It may only be needed when someone clicks something, so it can\'t be fully tested.', 'beltoft-performance' );
				$action = 'script';
			} elseif ( 'needs_browser' === $s['state'] ) {
				$detail = __( 'Almost done — needs one more check by your developer.', 'beltoft-performance' );
				$action = 'browser';
			} elseif ( 'not_tested' === $s['state'] && '' !== $s['reason'] ) {
				/* translators: %s: how long a test takes */
				$detail = sprintf( __( 'The shop changed since the last test. Test it again — it takes %s.', 'beltoft-performance' ), $time );
				$action = 'retest';
			} elseif ( 'not_tested' === $s['state'] ) {
				$detail  = $plugin ? __( 'Adds nothing visible to this page.', 'beltoft-performance' ) : __( 'Its styling doesn\'t seem to be used here.', 'beltoft-performance' );
				/* translators: %s: how long a test takes */
				$detail .= ' ' . sprintf( __( 'Not tested yet — testing takes %s.', 'beltoft-performance' ), $time );
				$action  = 'test';
			} else {
				$detail = __( 'Tested, but it can only be switched off by hand.', 'beltoft-performance' );
			}
			// Part of buying: a plugin not proven yet, or a script (never proven), is offered only with "I clicked through checkout myself".
			$by_hand = $flow && ( ( $plugin && in_array( $s['state'], array( 'not_tested', 'needs_browser' ), true ) ) || 'script' === $action );
			$decide[] = array( 'target' => $target, 'name' => $name, 'detail' => $detail, 'action' => $action, 'flow' => $by_hand, 'command' => (string) $s['command'], 'time' => $time, 'pages' => $s['pages'], 'files' => self::files_of( $target, (array) ( $in['reports'] ?? array() ) ), 'plugin' => $plugin );
		}
		$by_name = static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] );
		usort( $safe, $by_name );
		usort( $decide, $by_name );

		$scopes = array_merge( array( $scope ), $via );
		$needed = array();
		$count  = 0;
		foreach ( array_keys( $loaded ) as $p ) {
			$p = (string) $p;
			if ( Store::is_protected_plugin( $p ) ) {
				$always = true;
				continue;
			}
			++$count;
			if ( isset( $plugin_cand[ $p ] ) || self::is_off( $rules, 'unload:' . $p, $scopes ) || self::is_off( $rules, 'plugin:' . $p, $scopes ) ) {
				continue;
			}
			if ( in_array( 'unload:' . $p, $kept, true ) || in_array( 'plugin:' . $p, $kept, true ) ) {
				$phrase = __( 'you chose to keep it', 'beltoft-performance' );
			} elseif ( isset( $sigs[ $p ] ) || null !== Store::never_accept_reason( $p ) ) {
				$phrase = Words::needed_phrase( $p, $page );
			} elseif ( in_array( $scope, Store::PAGE_TYPES, true ) ) {
				$phrase = __( 'used on this page', 'beltoft-performance' ); // loaded, never suggested: the check or the site content found it in use
			} else {
				$phrase = __( 'loads here', 'beltoft-performance' ); // one page or template: not judged by the check
			}
			$needed[] = array( 'plugin' => $p, 'name' => Words::plugin_name( $p, $headers ), 'phrase' => $phrase );
		}
		usort( $needed, $by_name );

		$off = array();
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule && in_array( 'retail', $r->hosts, true ) && array_intersect( $scopes, $r->pages ) ) {
				$off[] = Words::thing_name( $r->target, $owners, $headers );
			}
		}
		$off = array_values( array_unique( $off ) );
		usort( $off, 'strnatcasecmp' );
		return array( 'safe' => $safe, 'decide' => $decide, 'needed' => $needed, 'always' => $always, 'off' => $off, 'loading' => $count );
	}

	/**
	 * Overview rows, one per page type the last check looked at, in shop order. $in: inv, candidates, trials, check_id, rules, headers,
	 * owners, kept_pages [ target => page types ], kept_all [ targets ], host.
	 * @return array{rows:array,safe_total:int,first_safe:string}
	 */
	public static function overview( array $in ): array {
		$inv   = (array) ( $in['inv'] ?? array() );
		$rows  = array();
		$safe  = array();
		$first = '';
		foreach ( Words::PAGE_ORDER as $type ) {
			if ( empty( $inv[ $type ] ) ) {
				continue;
			}
			$kept = array_map( 'strval', (array) ( $in['kept_all'] ?? array() ) );
			foreach ( (array) ( $in['kept_pages'] ?? array() ) as $t => $pages ) {
				if ( in_array( $type, (array) $pages, true ) ) {
					$kept[] = (string) $t;
				}
			}
			$reports = array_values( (array) $inv[ $type ] );
			$s       = self::page_sections( array(
				'scope'      => $type,
				'page'       => $type,
				'reports'    => $reports,
				'inv'        => $inv,
				'candidates' => (array) ( $in['candidates'] ?? array() ),
				'trials'     => (array) ( $in['trials'] ?? array() ),
				'check_id'   => (string) ( $in['check_id'] ?? '' ),
				'rules'      => (array) ( $in['rules'] ?? array() ),
				'headers'    => (array) ( $in['headers'] ?? array() ),
				'owners'     => (array) ( $in['owners'] ?? array() ),
				'kept'       => $kept,
			) );
			foreach ( $s['safe'] as $item ) {
				$safe[ $item['target'] ] = true;
			}
			if ( '' === $first && $s['safe'] ) {
				$first = $type;
			}
			$url = '';
			foreach ( $reports as $r ) {
				if ( '' === $url && 200 === (int) ( $r['status'] ?? 0 ) ) {
					$url = (string) ( $r['url'] ?? '' );
				}
			}
			$rows[ $type ] = array(
				'type'    => $type,
				'name'    => Words::page_name( $type ),
				'hint'    => Words::page_hint( $type, $url, (string) ( $in['host'] ?? '' ) ),
				'loading' => $s['loading'],
				'off'     => count( $s['off'] ),
				'safe'    => count( $s['safe'] ),
				'decide'  => count( $s['decide'] ),
				'care'    => in_array( $type, Manager::FLOW_PAGES, true ) && ! $s['safe'],
			);
		}
		return array( 'rows' => $rows, 'safe_total' => count( $safe ), 'first_safe' => $first );
	}

	/**
	 * The status card: [ tone ok|warn|bad, title, detail, action repair|check|check_again|'' ].
	 * $in: loader (LoaderManager state), rules (ok|missing|unreadable|wrong_owner), has_check, pages, failed, off (things), date.
	 */
	public static function status( array $in ): array {
		$rules  = (string) ( $in['rules'] ?? 'ok' );
		$loader = (string) ( $in['loader'] ?? 'installed' );
		if ( 'ok' !== $rules ) {
			return array(
				'tone'   => 'bad',
				'title'  => __( 'Nothing is being switched off right now.', 'beltoft-performance' ),
				'detail' => 'missing' === $rules ? __( 'A file the speed tool needs is missing. Click Repair to write it again.', 'beltoft-performance' ) : __( 'A file the speed tool needs can\'t be read or updated. Ask your developer to check its file permissions.', 'beltoft-performance' ),
				'action' => 'missing' === $rules ? 'repair' : '',
			);
		}
		if ( 'missing' === $loader ) {
			return array( 'tone' => 'bad', 'title' => __( 'The speed tool\'s helper isn\'t installed, so nothing is switched off.', 'beltoft-performance' ), 'detail' => __( 'Click Repair to install it again.', 'beltoft-performance' ), 'action' => 'repair' );
		}
		if ( 'not_writable' === $loader ) {
			return array( 'tone' => 'bad', 'title' => __( 'The speed tool\'s helper isn\'t installed, so nothing is switched off.', 'beltoft-performance' ), 'detail' => __( 'WordPress isn\'t allowed to install it. Ask your developer to fix the folder permissions.', 'beltoft-performance' ), 'action' => '' );
		}
		if ( 'outdated' === $loader ) {
			return array( 'tone' => 'warn', 'title' => __( 'The speed tool\'s helper is out of date.', 'beltoft-performance' ), 'detail' => __( 'Click Repair to update it.', 'beltoft-performance' ), 'action' => 'repair' );
		}
		if ( empty( $in['has_check'] ) ) {
			return array( 'tone' => 'warn', 'title' => __( 'The shop hasn\'t been checked yet.', 'beltoft-performance' ), 'detail' => __( 'The check looks at every kind of page as a visitor, with a full cart and logged in. It runs in the background and takes about a minute.', 'beltoft-performance' ), 'action' => 'check' );
		}
		$pages  = (int) ( $in['pages'] ?? 0 );
		$failed = (int) ( $in['failed'] ?? 0 );
		$date   = (string) ( $in['date'] ?? '' );
		if ( $failed > 0 ) {
			return array(
				/* translators: 1: pages that failed, 2: pages looked at */
				'title'  => sprintf( __( 'The last check had problems: %1$d of %2$d pages didn\'t load.', 'beltoft-performance' ), $failed, $pages ),
				'tone'   => 'warn',
				/* translators: %s: date */
				'detail' => sprintf( __( 'Last checked %s. Check again; if it keeps happening, ask your developer.', 'beltoft-performance' ), $date ),
				'action' => 'check_again',
			);
		}
		$off = (int) ( $in['off'] ?? 0 );
		/* translators: %d: number of things */
		$title = $off ? sprintf( _n( 'Everything is working. %d thing is already switched off.', 'Everything is working. %d things are already switched off.', $off, 'beltoft-performance' ), $off ) : __( 'Everything is working. Nothing is switched off yet.', 'beltoft-performance' );
		/* translators: 1: date, 2: number of pages */
		return array( 'tone' => 'ok', 'title' => $title, 'detail' => sprintf( _n( 'Last checked %1$s — %2$d page looked at, no problems found.', 'Last checked %1$s — %2$d pages looked at, no problems found.', $pages, 'beltoft-performance' ), $date, $pages ), 'action' => 'check_again' );
	}

	/** One switched-off thing: a plugin (its unload: and plugin: rules together) or one file. */
	public static function thing_key( string $target ): string {
		$kind = (string) strstr( $target, ':', true );
		return in_array( $kind, array( 'unload', 'plugin' ), true ) ? 'plugin:' . substr( $target, strlen( $kind ) + 1 ) : $target;
	}

	/** How many things are switched off. */
	public static function off_count( array $rules ): int {
		$keys = array();
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule ) {
				$keys[ self::thing_key( $r->target ) ] = true;
			}
		}
		return count( $keys );
	}

	/** The posted value of one "switch back on here" box. */
	public static function choice_value( string $target, string $scope ): string {
		return rawurlencode( $target ) . '|' . rawurlencode( $scope );
	}

	/** [ target, scope ] from a posted box value, or null. */
	public static function parse_choice( string $value ): ?array {
		$parts = explode( '|', $value );
		if ( 2 !== count( $parts ) ) {
			return null;
		}
		$target = rawurldecode( $parts[0] );
		$scope  = rawurldecode( $parts[1] );
		return ( preg_match( self::TARGET_RE, $target ) && '' !== trim( $scope ) ) ? array( $target, $scope ) : null;
	}

	/**
	 * Switched off tab: one card per thing, A–Z. $ctx: headers, owners, templates, page_titles, names [ login => display name ], installed [ folders ].
	 * Card: key, name, where, provenance [ text, tone ], warnings[], choices [ value, label ] (retail-only rules), keys (rule keys).
	 */
	public static function off_cards( array $rules, array $ctx ): array {
		$groups = array();
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule ) {
				$groups[ self::thing_key( $r->target ) ][] = $r;
			}
		}
		$cards = array();
		foreach ( $groups as $key => $list ) {
			$scopes   = array();
			$choices  = array();
			$keys     = array();
			$trade    = true;
			$warnings = array();
			foreach ( $list as $r ) {
				$scopes = array_merge( $scopes, $r->pages );
				$keys[] = $r->key();
				$trade  = $trade && ! in_array( 'retail', $r->hosts, true );
				if ( array( 'retail' ) === array_values( $r->hosts ) ) {
					foreach ( Words::sort_pages( $r->pages ) as $s ) {
						$choices[] = array( 'value' => self::choice_value( $r->target, $s ), 'label' => Words::scope_name( $s, (array) ( $ctx['templates'] ?? array() ), (array) ( $ctx['page_titles'] ?? array() ) ) );
					}
				}
			}
			$first = $list[0];
			if ( null !== Store::never_accept_error( $first->target ) ) {
				$warnings[] = $first->owner_override
					? __( 'Switched off by you on purpose: this kind of plugin is never suggested, because what it does doesn\'t show on the page and no test can prove it unused.', 'beltoft-performance' )
					: __( 'This kind of plugin is normally never switched off: what it does doesn\'t show on the page, so no test can prove it\'s unused. Switching it back on is recommended.', 'beltoft-performance' );
			}
			if ( in_array( $first->kind(), array( 'unload', 'plugin' ), true ) && ! in_array( $first->name(), (array) ( $ctx['installed'] ?? array() ), true ) ) {
				$warnings[] = __( 'This plugin is no longer installed, so this does nothing. Switch it back on everywhere to tidy up.', 'beltoft-performance' );
			}
			$where = Words::where_text( $scopes, (array) ( $ctx['templates'] ?? array() ), (array) ( $ctx['page_titles'] ?? array() ) );
			if ( $trade ) {
				$where .= ' ' . __( '(on the trade site only)', 'beltoft-performance' );
			}
			$cards[] = array(
				'key'        => (string) $key,
				'name'       => Words::thing_name( $first->target, (array) ( $ctx['owners'] ?? array() ), (array) ( $ctx['headers'] ?? array() ) ),
				'where'      => $where,
				'provenance' => Words::provenance( $list, (array) ( $ctx['names'] ?? array() ), (array) ( $ctx['templates'] ?? array() ), (array) ( $ctx['page_titles'] ?? array() ) ),
				'warnings'   => $warnings,
				'choices'    => $choices,
				'keys'       => $keys,
			);
		}
		usort( $cards, static fn( $a, $b ) => strnatcasecmp( $a['name'], $b['name'] ) );
		return $cards;
	}
}
