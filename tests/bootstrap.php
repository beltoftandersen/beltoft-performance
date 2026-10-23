<?php
// Pure unit tests: no WordPress. Minimal stubs for the few WP functions the pure classes touch.
define( 'ABSPATH', sys_get_temp_dir() . '/bp-wp/' );
$GLOBALS['bp_test_options'] = [];
// get_option runs the option_<name> filters kept in $GLOBALS['wp_filter'] as plain callable lists (like option_home on the B2B host).
function get_option( $k, $d = false ) {
	$v = $GLOBALS['bp_test_options'][ $k ] ?? ( 'home' === $k ? 'http://staging.mindthetrash.pt' : $d );
	foreach ( (array) ( $GLOBALS['wp_filter'][ 'option_' . $k ] ?? array() ) as $cb ) {
		$v = $cb( $v );
	}
	return $v;
}
function update_option( $k, $v, $a = null ) { $GLOBALS['bp_test_options'][ $k ] = $v; return true; }
function wp_json_encode( $v, $f = 0 ) { return json_encode( $v, $f ); }
function sanitize_key( $k ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) ); }
function __( $t, $d = null ) { return $t; }
function trailingslashit( $s ) { return rtrim( $s, '/' ) . '/'; }
function untrailingslashit( $s ) { return rtrim( $s, '/\\' ); }
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function home_url( $path = '', $scheme = null ) { return rtrim( (string) get_option( 'home', 'http://staging.mindthetrash.pt' ), '/' ) . $path; }
function get_permalink( $post = 0 ) { return ''; }
function wc_get_page_permalink( $page ) { return $GLOBALS['bp_test_options'][ "wc_page_$page" ] ?? ''; }
function wc_get_cart_url() { return $GLOBALS['bp_test_options']['wc_cart_url'] ?? ''; }
function wc_get_checkout_url() { return $GLOBALS['bp_test_options']['wc_checkout_url'] ?? ''; }
spl_autoload_register( static function ( $class ) {
	if ( 0 === strpos( $class, 'BeltoftPerformance\\' ) ) {
		$f = dirname( __DIR__ ) . '/src/' . str_replace( '\\', '/', substr( $class, 19 ) ) . '.php';
		if ( is_file( $f ) ) { require $f; }
	}
} );
define( 'BELTOFT_PERFORMANCE_DIR', dirname( __DIR__ ) );
define( 'BELTOFT_PERFORMANCE_VERSION', '0.1.0' );
function _n( $s, $p, $n, $d = null ) { return 1 === (int) $n ? $s : $p; }
