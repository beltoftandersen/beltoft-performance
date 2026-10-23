<?php
namespace BeltoftPerformance\Assets;

use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/** Reverse-dependency view of registered scripts or styles. Pure: built from plain arrays. */
final class Graph {
	private array $rdeps = array();

	public function __construct( private array $registered ) {
		foreach ( $registered as $h => $r ) {
			foreach ( (array) ( $r['deps'] ?? array() ) as $d ) {
				$this->rdeps[ $d ][] = $h;
			}
		}
	}

	public static function from_wp( \WP_Dependencies $deps ): self {
		$out = array();
		foreach ( $deps->registered as $h => $r ) {
			$out[ $h ] = array( 'src' => is_string( $r->src ) ? $r->src : '', 'deps' => (array) $r->deps );
		}
		return new self( $out );
	}

	public function cascade( array $handles ): array {
		$seen  = array();
		$queue = array_values( $handles );
		while ( $queue ) {
			$h = array_shift( $queue );
			if ( isset( $seen[ $h ] ) ) {
				continue;
			}
			$seen[ $h ] = true;
			foreach ( $this->rdeps[ $h ] ?? array() as $dependent ) {
				$queue[] = $dependent;
			}
		}
		$out = array_keys( $seen );
		sort( $out );
		return $out;
	}

	public function owner_plugin( string $src ): string {
		return preg_match( '#/wp-content/plugins/([^/]+)/#', $src, $m ) && ! str_starts_with( $src, '//' ) ? $m[1] : '';
	}

	public function handles_of_plugin( string $dir ): array {
		$out = array();
		foreach ( $this->registered as $h => $r ) {
			if ( $this->owner_plugin( (string) ( $r['src'] ?? '' ) ) === $dir ) {
				$out[] = $h;
			}
		}
		sort( $out );
		return $out;
	}

	public function protected_in( array $handles ): array {
		return array_values( array_filter( $handles, fn( $h ) => Store::is_locked_handle( $h, (string) ( $this->registered[ $h ]['src'] ?? '' ) ) ) ); // only this plugin's own files stop a rule
	}
}
