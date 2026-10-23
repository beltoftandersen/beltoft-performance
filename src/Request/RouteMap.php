<?php
namespace BeltoftPerformance\Request;

defined( 'ABSPATH' ) || exit;

/** The URL prefixes the classifier needs, read from the live permalink setup. Stored in the rule file on save. */
final class RouteMap {
	private static ?array $memo = null;
	private static ?array $query_memo = null;

	/** Forgets the per-request routes (tests; after a permalink change in the same request). */
	public static function flush(): void {
		self::$memo       = null;
		self::$query_memo = null;
	}

	/** The routes, built once per request. */
	public static function build(): array {
		return self::$memo ??= self::compute();
	}

	private static function compute(): array {
		$rel = static fn( $url ) => $url ? trailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) ) : '';
		$wc  = (array) get_option( 'woocommerce_permalinks', array() );
		$blog = (int) get_option( 'page_for_posts' );
		return array(
			'shop'         => function_exists( 'wc_get_page_permalink' ) ? $rel( wc_get_page_permalink( 'shop' ) ) : '',
			'product_base' => '/' . trim( (string) ( $wc['product_base'] ?? 'produto' ), '/' ) . '/',
			'cat'          => '/' . trim( (string) ( $wc['category_base'] ?? 'categoria-produto' ), '/' ) . '/',
			'brand'        => '/marca/',
			'tag'          => '/' . trim( (string) ( $wc['tag_base'] ?? 'etiqueta-produto' ), '/' ) . '/',
			'cart'         => function_exists( 'wc_get_cart_url' ) ? $rel( wc_get_cart_url() ) : '',
			'checkout'     => function_exists( 'wc_get_checkout_url' ) ? $rel( wc_get_checkout_url() ) : '',
			'order_received' => '/' . trim( (string) get_option( 'woocommerce_checkout_order_received_endpoint', 'order-received' ), '/' ) . '/',
			'account'      => function_exists( 'wc_get_page_permalink' ) ? $rel( wc_get_page_permalink( 'myaccount' ) ) : '',
			'blog'         => $blog ? $rel( get_permalink( $blog ) ) : '',
			'retail_host'  => self::retail_host(),
		) + self::query_routes();
	}

	/**
	 * Query keys the classifier needs beyond its defaults: Bricks filter nice names (query_keys) and the site's own
	 * WooCommerce endpoint slugs (checkout_keys, account_keys). Once per request; the Bricks list is object-cached.
	 */
	public static function query_routes(): array {
		if ( null !== self::$query_memo ) {
			return self::$query_memo;
		}
		$slugs = static function ( array $options ): array {
			$out = array();
			foreach ( $options as $o ) {
				$v = trim( (string) get_option( $o, '' ) );
				if ( '' !== $v ) {
					$out[] = $v;
				}
			}
			return array_values( array_unique( $out ) );
		};
		return self::$query_memo = array(
			'query_keys'    => self::bricks_filter_names(),
			'checkout_keys' => $slugs( array( 'woocommerce_checkout_pay_endpoint', 'woocommerce_checkout_order_received_endpoint' ) ),
			'account_keys'  => $slugs( array( 'woocommerce_myaccount_view_order_endpoint', 'woocommerce_myaccount_orders_endpoint', 'woocommerce_myaccount_downloads_endpoint', 'woocommerce_myaccount_edit_account_endpoint', 'woocommerce_myaccount_edit_address_endpoint', 'woocommerce_myaccount_payment_methods_endpoint', 'woocommerce_myaccount_add_payment_method_endpoint', 'woocommerce_myaccount_delete_payment_method_endpoint', 'woocommerce_myaccount_set_default_payment_method_endpoint', 'woocommerce_myaccount_lost_password_endpoint', 'woocommerce_logout_endpoint' ) ),
		);
	}

	/** URL parameter names of Bricks query filters with a nice name (Bricks reads them from its filter element table). */
	private static function bricks_filter_names(): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return array();
		}
		$cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( 'bricks_filter_names', 'beltoft-performance' ) : false;
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$table = $wpdb->prefix . 'bricks_filters_element';
		$names = array();
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			$names = array_values( array_filter( array_map( 'strval', (array) $wpdb->get_col( "SELECT DISTINCT nice_name FROM {$table} WHERE nice_name <> ''" ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only
		}
		if ( function_exists( 'wp_cache_set' ) ) {
			wp_cache_set( 'bricks_filter_names', $names, 'beltoft-performance', HOUR_IN_SECONDS );
		}
		return $names;
	}

	/**
	 * The canonical retail host. beltoft-commerce's UrlFilter rewrites home/siteurl to the request host on the B2B
	 * host, so home_url() there names the B2B host. Order: WP_HOME, beltoft-commerce's own canonical home, else the
	 * home option read with its option_home filters taken off for that one read.
	 */
	public static function retail_host(): string {
		if ( defined( 'WP_HOME' ) && '' !== (string) WP_HOME ) {
			$home = (string) WP_HOME;
		} elseif ( class_exists( '\\BeltoftCommerce\\Domain\\UrlFilter' ) && method_exists( '\\BeltoftCommerce\\Domain\\UrlFilter', 'canonical' ) ) {
			$home = (string) \BeltoftCommerce\Domain\UrlFilter::canonical();
		} else {
			$home = self::unfiltered_home();
		}
		return strtolower( (string) wp_parse_url( $home, PHP_URL_HOST ) );
	}

	/** The stored home option without any option_home filter (restored right after). */
	private static function unfiltered_home(): string {
		global $wp_filter;
		$had   = is_array( $wp_filter ) && array_key_exists( 'option_home', $wp_filter );
		$saved = $had ? $wp_filter['option_home'] : null;
		if ( $had ) {
			unset( $wp_filter['option_home'] );
		}
		try {
			return (string) get_option( 'home' );
		} finally {
			if ( $had ) {
				$wp_filter['option_home'] = $saved;
			}
		}
	}
}
