<?php
namespace BeltoftPerformance\Loader;

defined( 'ABSPATH' ) || exit;

/** The plugin owns the mu loader: installs, updates and removes it, and reports its state. */
final class LoaderManager {
	public const FILE = 'beltoft-performance-loader.php';

	public static function source(): string {
		return BELTOFT_PERFORMANCE_DIR . '/mu/' . self::FILE;
	}

	public static function default_dir(): string {
		return defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : WP_CONTENT_DIR . '/mu-plugins';
	}

	public static function install( string $mu_dir ): bool {
		if ( ! is_dir( $mu_dir ) || ! is_writable( $mu_dir ) ) {
			return false;
		}
		$code = file_get_contents( self::source() );
		if ( false === $code || ! self::looks_valid( $code ) ) {
			return false;
		}
		$tmp = $mu_dir . '/.' . self::FILE . '.' . getmypid() . '.tmp';
		if ( false === file_put_contents( $tmp, $code ) ) {
			return false;
		}
		return rename( $tmp, $mu_dir . '/' . self::FILE );
	}

	public static function remove( string $mu_dir ): bool {
		$f = $mu_dir . '/' . self::FILE;
		return ! file_exists( $f ) || unlink( $f );
	}

	/** @return array{state:string,version:string} */
	public static function status( string $mu_dir ): array {
		$f = $mu_dir . '/' . self::FILE;
		if ( ! file_exists( $f ) ) {
			return array( 'state' => is_writable( $mu_dir ) ? 'missing' : 'not_writable', 'version' => '' );
		}
		$v = self::version_of( (string) file_get_contents( $f ) );
		return array( 'state' => version_compare( $v, self::version_of( (string) file_get_contents( self::source() ) ), '<' ) ? 'outdated' : 'installed', 'version' => $v );
	}

	private static function version_of( string $code ): string {
		return preg_match( '/^\s*\*\s*Version:\s*([0-9.]+)/m', $code, $m ) ? $m[1] : '0';
	}

	private static function looks_valid( string $code ): bool {
		try {
			token_get_all( $code, TOKEN_PARSE );
			return str_contains( $code, 'beltoft_performance_filter_plugins' );
		} catch ( \ParseError $e ) {
			return false;
		}
	}
}
