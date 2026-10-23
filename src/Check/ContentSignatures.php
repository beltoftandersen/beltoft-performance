<?php
namespace BeltoftPerformance\Check;

use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Where a plugin appears in the site's own content: a file candidate is judged on one URL per page type but applies to
 * every URL of that type, so a page type whose published content (or Bricks template) carries the owner plugin's
 * markers is never proposed for that plugin's files.
 */
final class ContentSignatures {
	/** The page types judged on one sample URL whose other URLs can carry different content. */
	public const PAGE_TYPES = array( 'page', 'post', 'product', 'account', 'checkout', 'cart' );

	/** Shortcode prefixes per plugin: content "[<prefix>…" means the plugin renders there. */
	public const SHORTCODES = array(
		'woo-smart-wishlist'        => array( 'woosw' ),
		'super-socializer'          => array( 'the_champ', 'heateor' ),
		'wpc-composite-products'    => array( 'wooco' ),
		'woocommerce-subscriptions' => array( 'wcsatt', 'subscription' ),
		'flexible-shipping'         => array( 'flexible_shipping' ),
	);

	/** A product of this type (any status) renders through the plugin on the product page type. */
	public const PRODUCT_TYPES = array( 'composite' => 'wpc-composite-products' );

	/** Pure: content needles for $plugin (Recorder signatures + shortcode openers + needles derived from what it registers). */
	public static function needles( string $plugin, array $derived = array() ): array {
		$out = (array) ( Recorder::SIGNATURES[ $plugin ] ?? array() );
		foreach ( (array) ( self::SHORTCODES[ $plugin ] ?? array() ) as $prefix ) {
			$out[] = '[' . $prefix;
		}
		$out = array_merge( $out, array_map( 'strval', (array) ( $derived[ $plugin ] ?? array() ) ) );
		return array_values( array_unique( $out ) );
	}

	/** Pure: plugins that have needles. */
	public static function plugins( array $derived = array() ): array {
		return array_values( array_unique( array_merge( array_keys( Recorder::SIGNATURES ), array_keys( self::SHORTCODES ), array_map( 'strval', array_keys( $derived ) ) ) ) );
	}

	/**
	 * Pure: [ plugin => needles ] from what each plugin registers. $shortcodes [ tag => file of its callback ] → "[tag " and
	 * "[tag]"; $elements [ Bricks element name => file of its class ] → '"name"' (as stored in Bricks data); $blocks
	 * [ block name => script/style files or URLs ] → "<!-- wp:namespace/". The owner is the plugin directory in the path;
	 * core, theme and protected plugins are left out.
	 */
	public static function derive_needles( array $shortcodes, array $elements, array $blocks ): array {
		$out   = array();
		$owner = static fn( string $f ): string => preg_match( '#/wp-content/plugins/([^/]+)/#', $f, $m ) ? $m[1] : '';
		$add   = static function ( string $plugin, array $needles ) use ( &$out ): void {
			if ( '' === $plugin || Store::is_protected_plugin( $plugin ) ) {
				return;
			}
			foreach ( $needles as $n ) {
				if ( ! in_array( $n, $out[ $plugin ] ?? array(), true ) ) {
					$out[ $plugin ][] = $n;
				}
			}
		};
		foreach ( $shortcodes as $tag => $file ) {
			if ( '' !== (string) $tag ) {
				$add( $owner( (string) $file ), array( '[' . $tag . ' ', '[' . $tag . ']' ) );
			}
		}
		foreach ( $elements as $name => $file ) {
			if ( '' !== (string) $name ) {
				$add( $owner( (string) $file ), array( '"' . $name . '"' ) );
			}
		}
		foreach ( $blocks as $name => $files ) {
			$ns = (string) strtok( (string) $name, '/' );
			foreach ( (array) $files as $f ) {
				$p = $owner( (string) $f );
				if ( '' !== $p && '' !== $ns ) {
					$add( $p, array( '<!-- wp:' . $ns . '/' ) );
					break;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/** The file a callable is defined in, or ''. */
	private static function callable_file( $fn ): string {
		try {
			if ( is_array( $fn ) && 2 === count( $fn ) ) {
				$ref = new \ReflectionMethod( is_object( $fn[0] ) ? get_class( $fn[0] ) : (string) $fn[0], (string) $fn[1] );
			} elseif ( is_string( $fn ) && str_contains( $fn, '::' ) ) {
				$ref = new \ReflectionMethod( $fn );
			} elseif ( is_string( $fn ) || $fn instanceof \Closure ) {
				$ref = new \ReflectionFunction( $fn );
			} elseif ( is_object( $fn ) && method_exists( $fn, '__invoke' ) ) {
				$ref = new \ReflectionMethod( $fn, '__invoke' );
			} else {
				return '';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		return (string) $ref->getFileName();
	}

	/** Needles derived from the registered shortcodes, Bricks elements and blocks of every loaded plugin (admin/CLI: all are loaded). */
	public static function derived(): array {
		global $shortcode_tags;
		$sc = array();
		foreach ( (array) $shortcode_tags as $tag => $cb ) {
			$f = self::callable_file( $cb );
			if ( '' !== $f ) {
				$sc[ (string) $tag ] = $f;
			}
		}
		$el = array();
		if ( class_exists( '\\Bricks\\Elements' ) && isset( \Bricks\Elements::$elements ) ) {
			foreach ( (array) \Bricks\Elements::$elements as $name => $e ) {
				$class = is_array( $e ) ? (string) ( $e['class'] ?? '' ) : '';
				if ( '' !== $class && class_exists( $class ) ) {
					try {
						$el[ (string) $name ] = (string) ( new \ReflectionClass( $class ) )->getFileName();
					} catch ( \ReflectionException $x ) {
						continue;
					}
				}
			}
		}
		$bl = array();
		if ( class_exists( '\\WP_Block_Type_Registry' ) ) {
			$src = static function ( \WP_Dependencies $deps, $handle ): string {
				return isset( $deps->registered[ $handle ] ) && is_string( $deps->registered[ $handle ]->src ) ? $deps->registered[ $handle ]->src : '';
			};
			foreach ( \WP_Block_Type_Registry::get_instance()->get_all_registered() as $b ) {
				$files = array();
				foreach ( array( 'editor_script_handles', 'script_handles', 'view_script_handles' ) as $p ) {
					foreach ( (array) ( $b->$p ?? array() ) as $h ) {
						$files[] = $src( wp_scripts(), $h );
					}
				}
				foreach ( array( 'style_handles', 'editor_style_handles' ) as $p ) {
					foreach ( (array) ( $b->$p ?? array() ) as $h ) {
						$files[] = $src( wp_styles(), $h );
					}
				}
				$bl[ (string) $b->name ] = array_values( array_filter( $files ) );
			}
		}
		return self::derive_needles( $sc, $el, $bl );
	}

	/** Pure: the scanned page type a published post renders as ('' when not one of PAGE_TYPES). $ids: cart/checkout/account/front/shop page ids. */
	public static function page_type( string $post_type, int $id, array $ids ): string {
		if ( 'post' === $post_type || 'product' === $post_type ) {
			return $post_type;
		}
		if ( 'page' !== $post_type ) {
			return '';
		}
		foreach ( array( 'cart', 'checkout', 'account' ) as $t ) {
			if ( $id && (int) ( $ids[ $t ] ?? 0 ) === $id ) {
				return $t;
			}
		}
		foreach ( array( 'front', 'shop' ) as $t ) {
			if ( $id && (int) ( $ids[ $t ] ?? 0 ) === $id ) {
				return '';
			}
		}
		return 'page';
	}

	/** Pure: scanned page types a Bricks template of $type (with its template settings) can render on. Unknown placement → every type. */
	public static function template_pages( string $type, array $settings ): array {
		if ( 'wc_product' === $type ) {
			return array( 'product' );
		}
		if ( in_array( $type, array( 'wc_cart', 'wc_cart_empty' ), true ) ) {
			return array( 'cart' );
		}
		if ( 'wc_checkout' === $type ) {
			return array( 'checkout' );
		}
		if ( 0 === strpos( $type, 'wc_account' ) ) {
			return array( 'account' );
		}
		if ( in_array( $type, array( 'archive', 'search', 'error', 'wc_archive', 'wc_thankyou' ), true ) ) {
			return array();
		}
		if ( in_array( $type, array( 'content', 'single' ), true ) ) {
			$out = array();
			foreach ( (array) ( $settings['templateConditions'] ?? array() ) as $cond ) {
				if ( 'postType' !== ( $cond['main'] ?? '' ) ) {
					return self::PAGE_TYPES;
				}
				foreach ( (array) ( $cond['postType'] ?? array() ) as $pt ) {
					$out[] = in_array( $pt, array( 'post', 'product' ), true ) ? $pt : ( 'page' === $pt ? 'page' : '' );
				}
			}
			$out = array_values( array_unique( array_filter( $out ) ) );
			return $out ? $out : self::PAGE_TYPES;
		}
		return self::PAGE_TYPES; // header, footer, section, popup …: anywhere
	}

	/** Pure: [ plugin => sorted page types ] from docs [ [ pages => [...], text => '...' ] ] and the product types present. */
	public static function from_docs( array $docs, array $product_types, array $derived = array() ): array {
		$out = array();
		foreach ( self::plugins( $derived ) as $plugin ) {
			$needles = self::needles( $plugin, $derived );
			foreach ( $docs as $d ) {
				$text = (string) ( $d['text'] ?? '' );
				foreach ( $needles as $n ) {
					if ( '' !== $n && false !== stripos( $text, $n ) ) {
						foreach ( (array) ( $d['pages'] ?? array() ) as $p ) {
							$out[ $plugin ][ $p ] = true;
						}
						break;
					}
				}
			}
		}
		foreach ( self::PRODUCT_TYPES as $type => $plugin ) {
			if ( in_array( $type, $product_types, true ) ) {
				$out[ $plugin ]['product'] = true;
			}
		}
		$map = array();
		foreach ( $out as $plugin => $pages ) {
			$pages = array_keys( array_intersect_key( $pages, array_flip( self::PAGE_TYPES ) ) );
			sort( $pages );
			if ( $pages ) {
				$map[ $plugin ] = $pages;
			}
		}
		ksort( $map );
		return $map;
	}

	/** Reads published content, Bricks page data and published Bricks templates (content, header and footer data) (SQL prefilter on the needles), plus product types in any status. */
	/** Pure: the transient name of a scan, from the posts cache generation (bumped by any post or post meta change, Bricks content included) and the active plugins. */
	public static function cache_key( string $posts_last_changed, array $active_plugins ): string {
		$plugins = array_map( 'strval', $active_plugins );
		sort( $plugins );
		return 'beltoft_performance_sigs_' . md5( $posts_last_changed . '|' . implode( ',', $plugins ) );
	}

	/** scan_uncached(), kept for an hour per cache_key() (the Pages tab and every CLI command call it). */
	public static function scan(): array {
		$key = self::cache_key( (string) wp_cache_get_last_changed( 'posts' ), (array) get_option( 'active_plugins', array() ) );
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) {
			return $hit;
		}
		$out = self::scan_uncached();
		set_transient( $key, $out, HOUR_IN_SECONDS );
		return $out;
	}

	public static function scan_uncached(): array {
		global $wpdb;
		$derived = self::derived();
		$likes   = array();
		foreach ( self::plugins( $derived ) as $plugin ) {
			foreach ( self::needles( $plugin, $derived ) as $n ) {
				$likes[] = '%' . $wpdb->esc_like( $n ) . '%';
			}
		}
		$likes = array_values( array_unique( $likes ) );
		$docs  = array();
		if ( $likes ) {
			$or_c = implode( ' OR ', array_fill( 0, count( $likes ), 'p.post_content LIKE %s' ) );
			$or_m = implode( ' OR ', array_fill( 0, count( $likes ), 'm.meta_value LIKE %s' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders only
			$sql  = "SELECT p.ID, p.post_type, p.post_content, m.meta_value AS bricks FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_bricks_page_content_2','_bricks_page_header_2','_bricks_page_footer_2') WHERE p.post_status = 'publish' AND p.post_type IN ('page','post','product','bricks_template') AND ( ( $or_c ) OR ( $or_m ) )";
			$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $likes, $likes ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$ids  = array(
				'cart'     => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'cart' ) : 0,
				'checkout' => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0,
				'account'  => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0,
				'shop'     => function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'shop' ) : 0,
				'front'    => (int) get_option( 'page_on_front' ),
			);
			foreach ( $rows as $r ) {
				$id = (int) $r['ID'];
				if ( 'bricks_template' === $r['post_type'] ) {
					$pages = self::template_pages( (string) get_post_meta( $id, '_bricks_template_type', true ), (array) get_post_meta( $id, '_bricks_template_settings', true ) );
				} else {
					$t     = self::page_type( (string) $r['post_type'], $id, $ids );
					$pages = '' === $t ? array() : array( $t );
				}
				$docs[] = array( 'pages' => $pages, 'text' => (string) $r['post_content'] . ' ' . (string) $r['bricks'] );
			}
		}
		// WPC Smart Wishlist adds its wishlist tab to My Account when page_myaccount is on.
		$woosw = (array) get_option( 'woosw_settings', array() );
		if ( 'yes' === ( $woosw['page_myaccount'] ?? '' ) ) {
			$docs[] = array( 'pages' => array( 'account' ), 'text' => 'woosw-' );
		}
		$types = array();
		foreach ( array_keys( self::PRODUCT_TYPES ) as $type ) {
			$n = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN {$wpdb->terms} t ON t.term_id = tt.term_id JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = 'product_type' AND t.slug = %s AND p.post_type = 'product'", $type ) );
			if ( $n > 0 ) {
				$types[] = $type;
			}
		}
		return self::from_docs( $docs, $types, $derived );
	}
}
