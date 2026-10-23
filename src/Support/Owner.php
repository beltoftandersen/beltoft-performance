<?php
namespace BeltoftPerformance\Support;

defined( 'ABSPATH' ) || exit;

/** Keeps the plugin's uploads folder owned by the web server when WP-CLI runs as root (--allow-root), and says why it can't be written. */
final class Owner {
	/** Pure: [uid, gid] to hand files to, or null when nothing should change (not root, or the uploads owner is root too). */
	public static function target( int $euid, int $base_uid, int $base_gid ): ?array {
		if ( 0 !== $euid || $base_uid <= 0 ) {
			return null;
		}
		return array( $base_uid, $base_gid );
	}

	/** The owner for files under $root: that of its parent (the uploads base dir), when running as root. */
	private static function owner_for( string $root ): ?array {
		if ( ! function_exists( 'posix_geteuid' ) ) {
			return null;
		}
		$base = dirname( $root );
		$uid  = @fileowner( $base );
		$gid  = @filegroup( $base );
		return ( false === $uid || false === $gid ) ? null : self::target( posix_geteuid(), (int) $uid, (int) $gid );
	}

	/** Gives $root and everything below it to the uploads owner. A no-op unless running as root. */
	public static function adopt_tree( string $root ): void {
		$to = self::owner_for( $root );
		if ( null === $to || ! is_dir( $root ) ) {
			return;
		}
		$set = static function ( string $p ) use ( $to ): void {
			@chown( $p, $to[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chown
			@chgrp( $p, $to[1] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chgrp
		};
		$set( $root );
		$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ), \RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $f ) {
			$set( $f->getPathname() );
		}
	}

	/** An error message when $dir can't be written to (or created), else null. */
	public static function writable_error( string $dir ): ?string {
		$probe = $dir;
		while ( ! file_exists( $probe ) && dirname( $probe ) !== $probe ) {
			$probe = dirname( $probe );
		}
		if ( ! is_dir( $probe ) ) {
			return sprintf( 'Cannot write to %s: %s is not a directory.', $dir, $probe );
		}
		if ( ! is_writable( $probe ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			return sprintf( 'Cannot write to %s: %s is not writable by this user (often left owned by root after activating as root). Fix: chown -R <web user>: %s', $dir, $probe, $probe );
		}
		return null;
	}
}
