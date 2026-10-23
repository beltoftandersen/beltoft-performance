<?php
namespace BeltoftPerformance\Cleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Daily database clean-up (the "Database" switch, off by default): the same work as Perfmatters' scheduled
 * optimisation on this shop (Chris's ruling: exactly like Perfmatters) - all post revisions, auto-drafts and trashed
 * posts, spam and trashed comments, expired transients, then all transients, and OPTIMIZE of non-InnoDB tables
 * that have free space. Same queries as perfmatters/inc/classes/DatabaseOptimizationProcess.php.
 */
final class Database {
	public const HOOK = 'beltoft_performance_db_cleanup';
	public const LAST = 'beltoft_performance_db_cleanup_last';

	/** Pure: the next daily run at 03:30 site time after $now (site offset in seconds). */
	public static function next_run( int $now, int $offset ): int {
		$local = $now + $offset;
		$at    = (int) floor( $local / 86400 ) * 86400 + 3 * 3600 + 1800;
		if ( $at <= $local ) {
			$at += 86400;
		}
		return $at - $offset;
	}

	public static function register(): void {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', static function () {
			$next = wp_next_scheduled( self::HOOK );
			if ( Switches::enabled( 'db_cleanup' ) && ! $next ) {
				wp_schedule_event( self::next_run( time(), (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ), 'daily', self::HOOK );
			} elseif ( ! Switches::enabled( 'db_cleanup' ) && $next ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
		} );
	}

	/** Runs (or, with $dry, only counts) the clean-up. @return array<string,int> what was (or would be) removed */
	public static function run( $dry = false ): array {
		global $wpdb;
		$dry = true === $dry; // the cron event passes nothing
		if ( ! $dry && function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 );
		}
		$col = static fn( string $sql ): array => (array) $wpdb->get_col( $sql );
		$sets = array(
			'post_revisions'     => $col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision'" ),
			'post_auto_drafts'   => $col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'auto-draft'" ),
			'trashed_posts'      => $col( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'trash'" ),
			'spam_comments'      => $col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_approved = 'spam'" ),
			'trashed_comments'   => $col( "SELECT comment_ID FROM {$wpdb->comments} WHERE (comment_approved = 'trash' OR comment_approved = 'post-trashed')" ),
			'expired_transients' => $col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d", $wpdb->esc_like( '_transient_timeout' ) . '%', time() ) ),
		);
		$tables = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT table_name AS t FROM information_schema.tables WHERE table_schema = %s AND Engine <> %s AND data_free > 0', DB_NAME, 'InnoDB' ) );
		if ( $dry ) {
			$all = $col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) );
			return array_map( 'count', $sets ) + array( 'all_transients' => count( $all ), 'tables' => count( $tables ) );
		}
		$out = array_fill_keys( array_keys( $sets ), 0 );
		foreach ( $sets['post_revisions'] as $id ) {
			$out['post_revisions'] += wp_delete_post_revision( (int) $id ) instanceof \WP_Post ? 1 : 0;
		}
		foreach ( $sets['post_auto_drafts'] as $id ) {
			$out['post_auto_drafts'] += wp_delete_post( (int) $id, true ) instanceof \WP_Post ? 1 : 0;
		}
		foreach ( $sets['trashed_posts'] as $id ) {
			$out['trashed_posts'] += wp_delete_post( (int) $id, true ) instanceof \WP_Post ? 1 : 0;
		}
		foreach ( array( 'spam_comments', 'trashed_comments' ) as $k ) {
			foreach ( $sets[ $k ] as $id ) {
				$out[ $k ] += (int) wp_delete_comment( (int) $id, true );
			}
		}
		foreach ( $sets['expired_transients'] as $name ) {
			$out['expired_transients'] += (int) delete_transient( str_replace( '_transient_timeout_', '', (string) $name ) );
		}
		// All transients: read after the expired ones are gone, as Perfmatters' sequence does.
		$out['all_transients'] = 0;
		foreach ( $col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_' ) . '%', $wpdb->esc_like( '_site_transient_' ) . '%' ) ) as $name ) {
			$name = (string) $name;
			$out['all_transients'] += false !== strpos( $name, '_site_transient_' ) ? (int) delete_site_transient( str_replace( '_site_transient_', '', $name ) ) : (int) delete_transient( str_replace( '_transient_', '', $name ) );
		}
		$out['tables'] = 0;
		foreach ( $tables as $t ) {
			$out['tables'] += (int) $wpdb->query( 'OPTIMIZE TABLE `' . str_replace( '`', '', (string) $t->t ) . '`' );
		}
		update_option( self::LAST, array( 'time' => time() ) + $out, false );
		return $out;
	}
}
