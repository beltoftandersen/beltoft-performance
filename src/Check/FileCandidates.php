<?php
namespace BeltoftPerformance\Check;

use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/** Pure: scripts/styles a page type prints but never uses (browser coverage, every state and viewport). */
final class FileCandidates {
	/** $used_on: [ plugin => page types where its markers appear in site content ] (ContentSignatures::scan()); those page types are never proposed for its files. */
	public static function from( array $inv, array $usage, array $plugin_candidates, array $used_on = array() ): array {
		$c = array();
		foreach ( $inv as $page => $states ) {
			$states = array_filter( (array) $states, static fn( $r ) => 200 === (int) ( $r['status'] ?? 0 ) );
			if ( ! $states ) {
				continue;
			}
			foreach ( array_keys( $states ) as $state ) {
				if ( empty( $usage[ $page ][ $state ] ) ) {
					continue 2; // no usage for every state: say nothing about this page
				}
			}
			$printed = null; // "kind:handle" => asset, printed in EVERY state
			$any     = array(); // "kind:handle" => asset, printed in ANY state (reverse dependencies)
			$inline  = array(); // "kind:handle" => true when it carries inline output in any state
			foreach ( $states as $r ) {
				$here = array();
				foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
					$k            = ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' );
					$here[ $k ]   = $a;
					$any[ $k ]    = $a;
					if ( (int) ( $a['inline'] ?? 0 ) > 0 ) {
						$inline[ $k ] = true;
					}
				}
				$printed = null === $printed ? $here : array_intersect_key( $printed, $here );
			}
			$raw = array();
			foreach ( (array) $printed as $key => $a ) {
				if ( isset( $inline[ $key ] ) ) {
					continue; // removing the file would drop its inline before/after/data output
				}
				$plugin = (string) ( $a['plugin'] ?? '' );
				$handle = (string) ( $a['handle'] ?? '' );
				$src    = (string) ( $a['src'] ?? '' );
				if ( '' === $plugin || Store::is_protected_plugin( $plugin ) || null !== Store::never_accept_reason( $plugin ) || Store::is_protected_handle( $handle, $src ) || in_array( $plugin, (array) ( $plugin_candidates[ $page ] ?? array() ), true ) || in_array( $page, (array) ( $used_on[ $plugin ] ?? array() ), true ) ) {
					continue;
				}
				$path   = (string) wp_parse_url( $src, PHP_URL_PATH );
				$unused = true;
				$ev     = array();
				foreach ( array_keys( $states ) as $state ) {
					$u = $usage[ $page ][ $state ];
					if ( 'style' === $a['kind'] ) {
						$used         = $u['style'][ $path ] ?? null;
						$unused       = $unused && 0 === $used;
						$ev[ $state ] = $used;
					} else {
						$s            = $u['script'][ $path ] ?? null;
						$unused       = $unused && is_array( $s ) && 0 === (int) $s['called'] && 0 === (int) $s['listeners'];
						$ev[ $state ] = $s;
					}
				}
				if ( $unused ) {
					$raw[ $key ] = array( $a, $ev );
				}
			}
			// Dependents (transitive, same kind, printed in any state): removing a handle drops them too.
			$dependents = static function ( string $key ) use ( $any ): array {
				[ $kind, $handle ] = explode( ':', $key, 2 );
				$found = array();
				$queue = array( $handle );
				while ( $queue ) {
					$h = array_shift( $queue );
					foreach ( $any as $k => $x ) {
						if ( ( $x['kind'] ?? '' ) === $kind && in_array( $h, (array) ( $x['deps'] ?? array() ), true ) && ! isset( $found[ $k ] ) && $k !== $key ) {
							$found[ $k ] = (string) $x['handle'];
							$queue[]     = (string) $x['handle'];
						}
					}
				}
				return $found;
			};
			$roots = array();
			foreach ( $raw as $key => $_ ) {
				$d = $dependents( $key );
				if ( ! array_diff_key( $d, $raw ) ) { // every dependent is itself an unused candidate
					$roots[ $key ] = $d;
				}
			}
			foreach ( $roots as $key => $d ) {
				foreach ( $roots as $other => $od ) {
					if ( $other !== $key && isset( $od[ $key ] ) ) {
						continue 2; // goes away with another root
					}
				}
				[ $a, $ev ] = $raw[ $key ];
				$why        = 'style' === $a['kind']
					? __( 'Styles never used on this page (all versions and screens).', 'beltoft-performance' )
					: __( 'Not used on load and scroll (does not prove clicks).', 'beltoft-performance' );
				if ( $d ) {
					$why .= ' ' . sprintf( __( 'Also removes: %s.', 'beltoft-performance' ), implode( ', ', array_values( $d ) ) );
				}
				$c[ $key ]['target']            = $key;
				$c[ $key ]['pages'][]           = $page;
				$c[ $key ]['why'][ $page ]      = $why;
				$c[ $key ]['evidence'][ $page ] = $ev;
				if ( 'script' === $a['kind'] ) {
					$c[ $key ]['caveat'] = 'load_only';
				}
			}
		}
		ksort( $c );
		return array_values( $c );
	}
}
