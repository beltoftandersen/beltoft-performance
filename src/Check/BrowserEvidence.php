<?php
namespace BeltoftPerformance\Check;

defined( 'ABSPATH' ) || exit;

/** Pure helpers that turn browser-check.js output into decisions. */
final class BrowserEvidence {
	/** Noise ratio above which a page is too unstable to judge (noisy keys / baseline entries, per viewport). */
	public const MAX_NOISE = 0.08;

	/**
	 * Entries that differ with the trial and not between the two baselines. An element that differs between the
	 * baselines (a "noisy" key) is still compared without its position (left/top), so its size and styles count;
	 * only when it also differs in size/style between the baselines is it masked whole.
	 */
	public static function layout_changes( array $base, array $base2, array $trial ): array {
		$base  = array_map( array( self::class, 'round_sub_pixels' ), $base );
		$base2 = array_map( array( self::class, 'round_sub_pixels' ), $base2 );
		$trial = array_map( array( self::class, 'round_sub_pixels' ), $trial );
		$noisy = self::noisy_keys( $base, $base2 );
		$strip = static fn( $s ) => isset( $noisy[ self::key_of( $s ) ] ) ? self::without_position( $s ) : $s;
		$base  = array_map( $strip, $base );
		$base2 = array_map( $strip, $base2 );
		$trial = array_map( $strip, $trial );
		$still = array(); // keys that differ between the baselines even without position: masked whole
		foreach ( array_merge( array_diff( $base, $base2 ), array_diff( $base2, $base ) ) as $n ) {
			$still[ self::key_of( (string) $n ) ] = true;
		}
		$diff = array_merge( array_diff( $base, $trial ), array_diff( $trial, $base ) );
		$out  = array_values( array_unique( array_filter( $diff, static fn( $s ) => ! isset( $still[ self::key_of( $s ) ] ) ) ) );
		sort( $out );
		return array_slice( $out, 0, 30 );
	}

	/** Pure: noisy keys (entries differing between the two baselines) / baseline entries. */
	public static function noise_ratio( array $base, array $base2 ): float {
		$base  = array_map( array( self::class, 'round_sub_pixels' ), $base );
		$base2 = array_map( array( self::class, 'round_sub_pixels' ), $base2 );
		$total = max( count( $base ), count( $base2 ) );
		return $total ? count( self::noisy_keys( $base, $base2 ) ) / $total : 0.0;
	}

	private static function noisy_keys( array $base, array $base2 ): array {
		$keys = array();
		foreach ( array_merge( array_diff( $base, $base2 ), array_diff( $base2, $base ) ) as $n ) {
			$keys[ self::key_of( (string) $n ) ] = true;
		}
		return $keys;
	}

	/** "key|left,top,w,h|styles" → "key|~,~,w,h|styles". */
	private static function without_position( string $s ): string {
		$p = explode( '|', $s, 3 );
		if ( count( $p ) < 2 ) {
			return $s;
		}
		$box = explode( ',', $p[1] );
		if ( count( $box ) >= 2 ) {
			$box[0] = '~';
			$box[1] = '~';
		}
		$p[1] = implode( ',', $box );
		return implode( '|', $p );
	}

	/** A header box-shadow mid-transition differs per load (none / rgba(0, 0, 0, 0) 0.00169027px … / 5.92711e-08px … 0): round fractional px to whole px and treat a fully transparent shadow as none. */
	private static function round_sub_pixels( $s ): string {
		$s = (string) preg_replace_callback( '/-?(?:\d*\.\d+|\d+)(?:e[-+]?\d+)?px/i', static fn( $m ) => ( (int) round( (float) $m[0] ) ) . 'px', (string) $s );
		return (string) preg_replace( '/rgba\(\d+, \d+, \d+, 0\)(?: -?\d+(?:px)?){2,4}(?: inset)?/', 'none', $s );
	}

	private static function key_of( string $s ): string {
		return (string) strtok( $s, '|' );
	}

	public static function coverage_by_src( array $pages ): array {
		$out = array( 'style' => array(), 'script' => array() );
		foreach ( $pages as $p ) {
			foreach ( (array) ( $p['coverage']['styles'] ?? array() ) as $s ) {
				$k = (string) wp_parse_url( (string) ( $s['url'] ?? '' ), PHP_URL_PATH );
				$out['style'][ $k ] = ( $out['style'][ $k ] ?? 0 ) + (int) ( $s['used'] ?? 0 );
			}
			foreach ( (array) ( $p['coverage']['scripts'] ?? array() ) as $s ) {
				$k = (string) wp_parse_url( (string) ( $s['url'] ?? '' ), PHP_URL_PATH );
				$cur = $out['script'][ $k ] ?? array( 'called' => 0, 'listeners' => 0 );
				$out['script'][ $k ] = array( 'called' => $cur['called'] + (int) ( $s['called'] ?? 0 ), 'listeners' => $cur['listeners'] + (int) ( $s['listeners'] ?? 0 ) );
			}
		}
		return $out;
	}

	public static function flow_fact_changes( array $base, array $trial ): array {
		$out = array();
		foreach ( array( 'fields', 'shipping', 'payment' ) as $f ) {
			$b = (array) ( $base[ $f ] ?? array() );
			$t = (array) ( $trial[ $f ] ?? array() );
			$gone = array_values( array_diff( $b, $t ) );
			$new  = array_values( array_diff( $t, $b ) );
			if ( $gone || $new ) {
				$out[] = trim( $f . ':' . ( $gone ? ' -' . implode( ', -', $gone ) : '' ) . ( $new ? ' +' . implode( ', +', $new ) : '' ) );
			}
		}
		if ( (int) ( $base['cart_items'] ?? 0 ) !== (int) ( $trial['cart_items'] ?? 0 ) ) {
			$out[] = 'cart_items: ' . (int) ( $base['cart_items'] ?? 0 ) . ' → ' . (int) ( $trial['cart_items'] ?? 0 );
		}
		return $out;
	}

	public static function flow_failed( array $page ): bool {
		return '' !== (string) ( $page['flow_error'] ?? 'missing' ) || (int) ( $page['facts']['cart_items'] ?? 0 ) < 1;
	}

	public static function layout_failed( array $page ): bool {
		return '' !== (string) ( $page['layout_error'] ?? '' ) || empty( $page['layout'] );
	}
}
