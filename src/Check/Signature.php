<?php
namespace BeltoftPerformance\Check;

defined( 'ABSPATH' ) || exit;

final class Signature {
	public const HEADER = 'X-BP-Check';

	public static function sign( string $payload_json, int $ts, string $secret ): string {
		return hash_hmac( 'sha256', $ts . '.' . $payload_json, $secret );
	}

	public static function verify( string $header, string $secret, int $now, int $max_age = 120 ): ?array {
		$parts = explode( '.', $header, 3 );
		if ( 3 !== count( $parts ) || ! ctype_digit( $parts[0] ) || '' === $secret ) {
			return null;
		}
		[ $ts, $mac, $b64 ] = $parts;
		if ( abs( $now - (int) $ts ) > $max_age ) {
			return null;
		}
		$json = base64_decode( strtr( $b64, '-_', '+/' ), true );
		if ( false === $json || ! hash_equals( self::sign( $json, (int) $ts, $secret ), $mac ) ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	public static function secret(): string {
		$s = (string) get_option( 'beltoft_performance_secret', '' );
		if ( '' === $s ) {
			$s = bin2hex( random_bytes( 32 ) );
			update_option( 'beltoft_performance_secret', $s, false );
		}
		return $s;
	}

	/** Signs $payload for the path of $url only (Recorder and loader compare it with the request path). */
	public static function header_for( array $payload, string $url ): string {
		$payload['path'] = \BeltoftPerformance\Request\Classifier::request_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$json = wp_json_encode( $payload );
		$ts   = time();
		return $ts . '.' . self::sign( $json, $ts, self::secret() ) . '.' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}
}
