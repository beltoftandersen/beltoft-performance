<?php
/**
 * Plugin Name: Beltoft Performance
 * Description: Loads only the plugins and files each page type uses. Rules are proposed by a check and approved by you.
 * Version:     0.1.0
 * Author:      beltoft.net
 * Requires PHP: 8.3
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: beltoft-performance
 */
defined( 'ABSPATH' ) || exit;

define( 'BELTOFT_PERFORMANCE_VERSION', '0.1.0' );
define( 'BELTOFT_PERFORMANCE_FILE', __FILE__ );
define( 'BELTOFT_PERFORMANCE_DIR', __DIR__ );

spl_autoload_register( static function ( $class ) {
	if ( 0 !== strpos( $class, 'BeltoftPerformance\\' ) ) {
		return;
	}
	$file = __DIR__ . '/src/' . str_replace( '\\', '/', substr( $class, strlen( 'BeltoftPerformance\\' ) ) ) . '.php';
	if ( is_file( $file ) ) {
		require $file;
	}
} );

add_action( 'plugins_loaded', array( 'BeltoftPerformance\\Check\\Recorder', 'boot' ), PHP_INT_MIN );
add_action( 'plugins_loaded', array( 'BeltoftPerformance\\Plugin', 'init' ) );
register_activation_hook( __FILE__, array( 'BeltoftPerformance\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BeltoftPerformance\\Plugin', 'deactivate' ) );
