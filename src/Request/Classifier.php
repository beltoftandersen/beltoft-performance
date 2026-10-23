<?php
namespace BeltoftPerformance\Request;

defined( 'ABSPATH' ) || exit;

/**
 * Decides a request's type and page type from the URL alone. Runs in the mu loader, before WordPress
 * has a query: no conditional tags exist yet. Pure: no WordPress calls.
 */
final class Classifier {
	/** Page types that keep every plugin and file: no rule (page type, url: or template:) applies on them. */
	public const KEEP_ALL_PAGES = array( 'page_query', 'checkout_endpoint', 'account_endpoint' );

	/** Query keys that leave the page type alone (tracking, sorting, paging, price/attribute filters, search, our own). */
	public const QUERY_ALLOW = array( 'gclid', 'fbclid', 'msclkid', 'paged', 'orderby', 'order', 'min_price', 'max_price', 's', 'post_type', 'product_cat', 'product_tag', 'bp_check', 'nc' );

	/** Query key prefixes that leave the page type alone (Bricks filters use brx_<id> unless they have a nice name). */
	public const QUERY_ALLOW_PREFIXES = array( 'utm_', 'filter_', 'query_type_', 'brx_' );

	/** Builder, preview and customizer requests: not pages at all. */
	public const PREVIEW_KEYS = array( 'bricks', 'preview', 'preview_id', 'preview_nonce', 'customize_changeset_uuid', 'wp_customize', 'customize_theme', 'customize_messenger_channel' );

	/** WooCommerce checkout endpoints as query vars (plain-permalink form); the site's own slugs come in $routes['checkout_keys']. */
	public const CHECKOUT_QUERY = array( 'order-pay', 'pay_for_order', 'order-received' );

	/** WooCommerce account endpoints as query vars; the site's own slugs come in $routes['account_keys']. */
	public const ACCOUNT_QUERY = array( 'view-order', 'orders', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'lost-password', 'customer-logout', 'show-reset-form' );

	public static function classify( string $uri, array $server, array $routes, bool $ignore_context = false ): array {
		$hostk = self::host_key( (string) ( $server['HTTP_HOST'] ?? '' ), (string) ( $routes['retail_host'] ?? '' ) );
		$uri   = explode( '#', $uri, 2 )[0];
		$parts = explode( '?', $uri, 2 );
		$path  = self::request_path( $uri );
		$query = (string) ( $parts[1] ?? '' );
		parse_str( $query, $q );
		$out = static fn( $type, $page = '' ) => [ 'type' => $type, 'page' => $page, 'host' => $hostk ];

		if ( ! $ignore_context && defined( 'WP_CLI' ) && WP_CLI ) { return $out( 'cli' ); }
		if ( ( ! $ignore_context && defined( 'DOING_CRON' ) && DOING_CRON ) || str_starts_with( $path, '/wp-cron.php' ) ) { return $out( 'cron' ); }
		if ( str_starts_with( $path, '/wp-admin/admin-ajax.php' ) || isset( $q['wc-ajax'] ) ) { return $out( 'ajax' ); }
		if ( str_starts_with( $path, '/wp-admin' ) ) { return $out( 'admin' ); }
		if ( str_starts_with( $path, '/wp-json/' ) || isset( $q['rest_route'] ) ) { return $out( 'rest' ); }
		if ( str_starts_with( $path, '/xmlrpc.php' ) ) { return $out( 'xmlrpc' ); }
		if ( str_starts_with( $path, '/wp-login.php' ) ) { return $out( 'login' ); }
		// Payment/shipping webhooks (?wc-api= or /wc-api/) and form posts (login, lost password, registration…) keep every plugin.
		if ( isset( $q['wc-api'] ) || str_starts_with( $path, '/wc-api/' ) ) { return $out( 'api' ); }
		if ( ! in_array( strtoupper( (string) ( $server['REQUEST_METHOD'] ?? 'GET' ) ), array( 'GET', 'HEAD' ), true ) ) { return $out( 'form' ); }
		// PHP runs whatever script nginx resolved: anything but the front controller (admin-post.php, admin-ajax.php…) is not a page.
		if ( isset( $server['SCRIPT_NAME'] ) && 'index.php' !== basename( (string) $server['SCRIPT_NAME'] ) ) { return $out( 'script' ); }

		[ $type, $page ] = self::query_page( $q, self::path_page( $path, $q, $routes ), $routes );
		return $out( $type, 'page' === $type ? $page : '' );
	}

	/** The page type a front-controller GET's path names (query keys aside, except ?s=). */
	private static function path_page( string $path, array $q, array $routes ): string {
		if ( isset( $q['s'] ) ) { return 'search'; }
		if ( '/' === $path ) { return 'front'; }
		$is = static fn( $key ) => '' !== ( $routes[ $key ] ?? '' ) && str_starts_with( $path, strtolower( $routes[ $key ] ) );
		// Sub-endpoints (order-pay, add-payment-method, view-order…) get types no rule can target: never unloaded.
		if ( $is( 'checkout' ) ) {
			$received = '/' . trim( strtolower( (string) ( $routes['order_received'] ?? '' ) ) ?: 'order-received', '/' ) . '/';
			if ( strtolower( $routes['checkout'] ) === $path ) { return 'checkout'; }
			return ( str_contains( $path, $received ) || str_contains( $path, '/order-received/' ) ) ? 'order_received' : 'checkout_endpoint';
		}
		if ( $is( 'cart' ) ) { return 'cart'; }
		if ( $is( 'account' ) ) { return strtolower( $routes['account'] ) === $path ? 'account' : 'account_endpoint'; }
		if ( $is( 'cat' ) ) { return 'archive_cat'; }
		if ( $is( 'brand' ) ) { return 'archive_brand'; }
		if ( $is( 'tag' ) ) { return 'archive_tag'; }
		if ( $is( 'blog' ) ) { return 'blog'; }
		if ( $is( 'shop' ) ) {
			$rest = substr( $path, strlen( strtolower( $routes['shop'] ) ) );
			return ( '' === $rest || preg_match( '#^page/\d+/$#', $rest ) ) ? 'shop' : 'product';
		}
		return 'page';
	}

	/**
	 * Pure: [ type, page ] once the query keys are known. Previews are not pages; a WooCommerce endpoint query var makes
	 * the checkout/account endpoint type; any other key outside the allow-list (plus the Bricks filter nice names in
	 * $routes['query_keys']) makes 'page_query'. All three keep every plugin and file.
	 */
	public static function query_page( array $q, string $page, array $routes = array() ): array {
		if ( ! $q ) {
			return array( 'page', $page );
		}
		$keys = array_map( 'strval', array_keys( $q ) );
		if ( array_intersect( $keys, self::PREVIEW_KEYS ) ) {
			return array( 'preview', '' );
		}
		$list = static fn( string $k ): array => array_values( array_filter( array_map( 'strval', (array) ( $routes[ $k ] ?? array() ) ), 'strlen' ) );
		if ( array_intersect( $keys, array_merge( self::CHECKOUT_QUERY, $list( 'checkout_keys' ) ) ) ) {
			return array( 'page', 'checkout_endpoint' );
		}
		if ( array_intersect( $keys, array_merge( self::ACCOUNT_QUERY, $list( 'account_keys' ) ) ) ) {
			return array( 'page', 'account_endpoint' );
		}
		$allow = array_merge( self::QUERY_ALLOW, $list( 'query_keys' ), 'order_received' === $page ? array( 'key' ) : array() );
		foreach ( $keys as $k ) {
			if ( in_array( $k, $allow, true ) ) {
				continue;
			}
			foreach ( self::QUERY_ALLOW_PREFIXES as $prefix ) {
				if ( str_starts_with( $k, $prefix ) ) {
					continue 2;
				}
			}
			return array( 'page', 'page_query' );
		}
		return array( 'page', $page );
	}

	/**
	 * The one host decision (loader, Suppressor, Recorder): 'retail' when the request host is the canonical retail host
	 * (case and port ignored), when there is no request host (CLI, cron) or no retail host is known; else 'trade'.
	 */
	public static function host_key( string $http_host, string $retail_host ): string {
		$norm   = static fn( string $h ): string => explode( ':', strtolower( trim( $h ) ) )[0];
		$host   = $norm( $http_host );
		$retail = $norm( $retail_host );
		return ( '' === $host || '' === $retail || $host === $retail ) ? 'retail' : 'trade';
	}

	/**
	 * The request path as nginx resolves it: query/fragment cut, percent-decoded once (so %2e and %2f count),
	 * slashes collapsed, dot segments resolved, lower-cased, always "/…/". Shared by the classifier, the
	 * check signature (payload path) and the loader's trial branch.
	 */
	public static function request_path( string $uri ): string {
		$path = explode( '?', explode( '#', $uri, 2 )[0], 2 )[0];
		$path = strtolower( rawurldecode( $path ) );
		$out  = array();
		foreach ( explode( '/', $path ) as $seg ) {
			if ( '' === $seg || '.' === $seg ) {
				continue;
			}
			if ( '..' === $seg ) {
				array_pop( $out );
				continue;
			}
			$out[] = $seg;
		}
		return $out ? '/' . implode( '/', $out ) . '/' : '/';
	}
}
