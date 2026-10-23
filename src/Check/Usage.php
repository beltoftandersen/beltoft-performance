<?php
namespace BeltoftPerformance\Check;

defined( 'ABSPATH' ) || exit;

final class Usage {
	public static function store( string $id, string $page, string $state, array $result ): void {
		wp_mkdir_p( Runner::dir( $id ) );
		file_put_contents( Runner::dir( $id ) . "/usage-{$page}-{$state}.json", wp_json_encode( BrowserEvidence::coverage_by_src( (array) ( $result['pages'] ?? array() ) ) ) );
	}

	public static function load( string $id ): array {
		$out = array();
		foreach ( (array) glob( Runner::dir( $id ) . '/usage-*.json' ) as $f ) {
			if ( preg_match( '#/usage-([a-z_]+)-(guest|cart|logged_in)\.json$#', $f, $m ) ) {
				$out[ $m[1] ][ $m[2] ] = (array) json_decode( (string) file_get_contents( $f ), true );
			}
		}
		return $out;
	}
}
