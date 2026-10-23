<?php
namespace BeltoftPerformance;

defined( 'ABSPATH' ) || exit;

/** Plugin deletion (uninstall.php only, never on deactivation): options, the uploads folder and the mu loader. */
final class Uninstaller {
	public static function run(): void {
		global $wpdb;
		$names = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'beltoft_performance_' ) . '%', $wpdb->esc_like( '_transient_beltoft_performance_' ) . '%', $wpdb->esc_like( '_transient_timeout_beltoft_performance_' ) . '%' ) ); // options and the content-scan transients
		foreach ( $names as $o ) {
			delete_option( (string) $o );
		}
		wp_clear_scheduled_hook( \BeltoftPerformance\Cleanup\Database::HOOK );
		$uploads = wp_upload_dir()['basedir'];
		self::rmtree( $uploads . '/beltoft-performance', $uploads );
		$rules = \BeltoftPerformance\Rules\Compiler::file(); // the loader's path, should it differ from wp_upload_dir()
		self::rmtree( dirname( $rules ), dirname( $rules, 2 ) );
		\BeltoftPerformance\Loader\LoaderManager::remove( \BeltoftPerformance\Loader\LoaderManager::default_dir() );
	}

	/** Deletes $dir recursively, but only a directory named beltoft-performance directly under $root (no links followed). */
	public static function rmtree( string $dir, string $root ): bool {
		$real = realpath( $dir );
		$base = realpath( $root );
		if ( false === $real || false === $base || ! is_dir( $real ) || 'beltoft-performance' !== basename( $real ) || dirname( $real ) !== $base ) {
			return false;
		}
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $real, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::CHILD_FIRST );
		foreach ( $it as $f ) {
			if ( $f->isDir() && ! $f->isLink() ) {
				@rmdir( $f->getPathname() );
			} else {
				@unlink( $f->getPathname() );
			}
		}
		return @rmdir( $real );
	}
}
