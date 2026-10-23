<?php
namespace BeltoftPerformance\Admin;

defined( 'ABSPATH' ) || exit;

/** Pure helpers for the CLI candidate listing and the accept check. */
final class Proposals {
	public static function is_file_target( string $target ): bool {
		return 0 === strpos( $target, 'script:' ) || 0 === strpos( $target, 'style:' );
	}

	/** Pure: whether "Accept" may be offered for $target (never for scripts). */
	public static function acceptable( string $target ): bool {
		return null === \BeltoftPerformance\Rules\Store::accept_error( $target );
	}

	/** Pure: plugins first, then files; stable within each group. */
	public static function group( array $candidates ): array {
		$plugins = array();
		$files   = array();
		foreach ( $candidates as $c ) {
			if ( self::is_file_target( (string) $c['target'] ) ) { $files[] = $c; } else { $plugins[] = $c; }
		}
		return array( 'plugins' => $plugins, 'files' => $files );
	}
}
