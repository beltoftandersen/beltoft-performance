<?php
namespace BeltoftPerformance\Request;

defined( 'ABSPATH' ) || exit;

/** The page type once WordPress knows the query (suppression, recorder). Same vocabulary as Classifier. */
final class PageType {
	/** The page type, with the classifier's query-key rule applied ('page_query', endpoints, 'preview'): same answer as the loader. */
	public static function current(): string {
		[ $type, $page ] = Classifier::query_page( (array) $_GET, self::from_query(), RouteMap::query_routes() );
		return 'page' === $type ? $page : $type;
	}

	private static function from_query(): string {
		if ( is_search() ) { return 'search'; }
		if ( is_front_page() ) { return 'front'; }
		if ( ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) ) { return 'order_received'; }
		$endpoint = function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url();
		if ( function_exists( 'is_checkout' ) && is_checkout() ) { return $endpoint ? 'checkout_endpoint' : 'checkout'; }
		if ( function_exists( 'is_cart' ) && is_cart() ) { return 'cart'; }
		if ( function_exists( 'is_account_page' ) && is_account_page() ) { return $endpoint ? 'account_endpoint' : 'account'; }
		if ( function_exists( 'is_shop' ) && is_shop() ) { return 'shop'; }
		if ( function_exists( 'is_product' ) && is_product() ) { return 'product'; }
		if ( is_tax( 'product_cat' ) ) { return 'archive_cat'; }
		if ( is_tax( 'product_brand' ) ) { return 'archive_brand'; }
		if ( is_tax( 'product_tag' ) ) { return 'archive_tag'; }
		if ( is_home() ) { return 'blog'; }
		if ( is_singular( 'post' ) ) { return 'post'; }
		return 'page';
	}

	/** 'retail' or 'trade' for this request, against the canonical retail host (never the per-request filtered home_url()). */
	public static function host(): string {
		return Classifier::host_key( (string) ( $_SERVER['HTTP_HOST'] ?? '' ), RouteMap::retail_host() );
	}

	/** Active Bricks template ids (content/header/footer) plus the queried post when it has Bricks content. Safe without Bricks. */
	public static function template_ids(): array {
		$active = array();
		if ( class_exists( '\\Bricks\\Database' ) && isset( \Bricks\Database::$active_templates ) && is_array( \Bricks\Database::$active_templates ) ) {
			$active = \Bricks\Database::$active_templates;
		}
		$obj   = function_exists( 'get_queried_object' ) ? get_queried_object() : null;
		$has   = $obj instanceof \WP_Post && function_exists( 'get_post_meta' ) && (bool) get_post_meta( (int) $obj->ID, '_bricks_page_content_2', true );
		return self::template_ids_from( $active, $obj, $has );
	}

	/** Pure: header/footer/content ids, plus the queried object only when it is a WP_Post with Bricks content (never a term/user id). */
	public static function template_ids_from( array $active_templates, $queried_object, bool $has_bricks_content ): array {
		$ids = array();
		foreach ( array( 'header', 'footer', 'content' ) as $k ) {
			$id = $active_templates[ $k ] ?? 0;
			if ( is_numeric( $id ) && (int) $id > 0 ) {
				$ids[] = (int) $id;
			}
		}
		if ( $has_bricks_content && $queried_object instanceof \WP_Post && (int) $queried_object->ID > 0 ) {
			$ids[] = (int) $queried_object->ID;
		}
		return array_values( array_unique( $ids ) );
	}
}
