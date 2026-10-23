<?php
namespace BeltoftPerformance\Admin;

use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/** Pure: the plain words of the admin screen — page names, friendly plugin and file names, sentences. Tested in WordsTest. */
final class Words {
	/** Page types in the order a shop owner reads them. */
	public const PAGE_ORDER = array( 'front', 'shop', 'product', 'archive_cat', 'archive_brand', 'archive_tag', 'search', 'cart', 'checkout', 'order_received', 'account', 'blog', 'post', 'page' );

	/** The six rows the overview shows first. */
	public const MAIN_PAGES = array( 'front', 'shop', 'product', 'archive_cat', 'checkout', 'blog' );

	/** Short names that read better than the plugin header (keep them short and plain). */
	private const PLUGIN_NAMES = array(
		'woo-smart-wishlist'                                 => 'Wishlist',
		'pressidium-cookie-consent'                          => 'Cookie consent',
		'duracelltomi-google-tag-manager'                    => 'Google Tag Manager',
		'multibanco-ifthen-software-gateway-for-woocommerce' => 'Multibanco and MB WAY payments',
		'pymntpl-paypal-woocommerce'                         => 'PayPal payments',
		'wpc-composite-products'                             => 'Composite Products',
		'redis-cache'                                        => 'Redis cache admin',
		'woo-cancel-abandoned-order'                         => 'Cancel unpaid orders',
		'woo-dpd-portugal'                                   => 'DPD Portugal',
		'portugal-chronopost-pickup-woocommerce'             => 'DPD pickup points',
		'checkout-subscribe'                                 => 'Envio checkout webhook',
		'nif-num-de-contribuinte-portugues-for-woocommerce'  => 'Tax number (NIF) field',
		'portugal-states-distritos-for-woocommerce'          => 'Portuguese districts',
		'relevanssi'                                         => 'Relevanssi (better search)',
		'woocommerce-subscriptions'                          => 'Subscriptions',
		'ctt-expresso-para-woocommerce'                      => 'CTT Expresso',
	);

	/** Known files with a nicer name than "<plugin> styling". */
	private const FILE_NAMES = array(
		'style:woosw-icons'                     => 'Wishlist icons',
		'style:woosw-frontend'                  => 'Wishlist styling',
		'style:the_champ_frontend_css'          => 'Super Socializer styling',
		'script:the_champ_ss_general_scripts'   => 'Super Socializer sharing buttons code',
		'style:wooco-frontend'                  => 'Styling for "Composite Products" bundles',
		'style:wooco-blocks'                    => 'Block styling for "Composite Products" bundles',
		'style:wcsatt-css'                      => 'Subscriptions styling',
		'style:flexible-shipping-free-shipping' => 'Free-shipping notice styling',
	);

	/** Page types that show a grid of products. */
	private const LISTINGS = array( 'front', 'shop', 'product', 'archive_cat', 'archive_brand', 'archive_tag', 'search' );

	/** What a plugin visibly does, when we know it: [ phrase, page types it is about (null = every page) ]. */
	private const NEEDED = array(
		'woo-smart-wishlist'                                 => array( 'the heart on each product', self::LISTINGS ),
		'pressidium-cookie-consent'                          => array( 'the cookie banner', null ),
		'flexible-shipping'                                  => array( 'the free-shipping notice', array( 'cart', 'checkout' ) ),
		'pymntpl-paypal-woocommerce'                         => array( 'the PayPal buttons', array( 'product', 'cart', 'checkout' ) ),
		'multibanco-ifthen-software-gateway-for-woocommerce' => array( 'payment options at checkout', array( 'checkout' ) ),
		'super-socializer'                                   => array( 'the sharing and social-login buttons', array( 'product', 'post', 'account', 'checkout' ) ),
		'wpc-composite-products'                             => array( 'the bundle builder', array( 'product' ) ),
		'woocommerce-subscriptions'                          => array( 'the subscription options', array( 'product', 'cart', 'checkout', 'account' ) ),
		'beltoft-in-stock-notifier'                          => array( 'the "tell me when it\'s back" button', array( 'product' ) ),
	);

	public static function page_name( string $type ): string {
		$names = array(
			'front'          => __( 'Home page', 'beltoft-performance' ),
			'shop'           => __( 'Shop', 'beltoft-performance' ),
			'product'        => __( 'Product pages', 'beltoft-performance' ),
			'archive_cat'    => __( 'Category pages', 'beltoft-performance' ),
			'archive_brand'  => __( 'Brand pages', 'beltoft-performance' ),
			'archive_tag'    => __( 'Tag pages', 'beltoft-performance' ),
			'search'         => __( 'Search results', 'beltoft-performance' ),
			'cart'           => __( 'Cart', 'beltoft-performance' ),
			'checkout'       => __( 'Checkout', 'beltoft-performance' ),
			'order_received' => __( 'Order confirmation', 'beltoft-performance' ),
			'account'        => __( 'My account', 'beltoft-performance' ),
			'blog'           => __( 'Blog', 'beltoft-performance' ),
			'post'           => __( 'Blog posts', 'beltoft-performance' ),
			'page'           => __( 'Other pages', 'beltoft-performance' ),
		);
		return $names[ $type ] ?? $type;
	}

	/** Page types sorted in PAGE_ORDER (unknown ones last). */
	public static function sort_pages( array $types ): array {
		$types = array_values( array_unique( array_map( 'strval', $types ) ) );
		usort( $types, static function ( $a, $b ) {
			$ia = array_search( $a, self::PAGE_ORDER, true );
			$ib = array_search( $b, self::PAGE_ORDER, true );
			return ( false === $ia ? 99 : $ia ) <=> ( false === $ib ? 99 : $ib );
		} );
		return $types;
	}

	/** "Home page, Shop, Blog". */
	public static function page_list( array $types ): string {
		return implode( ', ', array_map( array( __CLASS__, 'page_name' ), self::sort_pages( $types ) ) );
	}

	/** The friendly name of a plugin folder: the short map, else the header Name without "for WooCommerce", else the folder made readable. */
	public static function plugin_name( string $dir, array $headers ): string {
		if ( isset( self::PLUGIN_NAMES[ $dir ] ) ) {
			return self::PLUGIN_NAMES[ $dir ];
		}
		$name = trim( (string) ( $headers[ $dir ] ?? '' ) );
		if ( '' !== $name ) {
			return trim( (string) preg_replace( '/\s+for\s+(WooCommerce|WordPress)\s*$/i', '', $name ) );
		}
		return ucfirst( str_replace( array( '-', '_' ), ' ', $dir ) );
	}

	/** The friendly name of anything that can be switched off. $owners: [ "kind:handle" => plugin folder ]. */
	public static function thing_name( string $target, array $owners, array $headers ): string {
		$kind = (string) strstr( $target, ':', true );
		$name = (string) substr( $target, strlen( $kind ) + 1 );
		if ( in_array( $kind, array( 'unload', 'plugin' ), true ) ) {
			return self::plugin_name( $name, $headers );
		}
		if ( isset( self::FILE_NAMES[ $target ] ) ) {
			return self::FILE_NAMES[ $target ];
		}
		$owner = (string) ( $owners[ $target ] ?? '' );
		if ( '' !== $owner ) {
			/* translators: %s: plugin name */
			return sprintf( 'script' === $kind ? __( '%s code', 'beltoft-performance' ) : __( '%s styling', 'beltoft-performance' ), self::plugin_name( $owner, $headers ) );
		}
		/* translators: %s: file name */
		return sprintf( 'script' === $kind ? __( 'Code file "%s"', 'beltoft-performance' ) : __( 'Styling file "%s"', 'beltoft-performance' ), $name );
	}

	/** A page type, one page ("Sobre nós (/sobre-nos/)") or a Bricks template, in words. $page_titles: [ path => title ]; $templates: [ id => label ]. */
	public static function scope_name( string $scope, array $templates, array $page_titles ): string {
		if ( 0 === strpos( $scope, 'url:' ) ) {
			$path = substr( $scope, 4 );
			/* translators: 1: page title, 2: page address */
			return isset( $page_titles[ $path ] ) ? sprintf( __( '%1$s (%2$s)', 'beltoft-performance' ), $page_titles[ $path ], $path ) : sprintf( __( 'the page %s', 'beltoft-performance' ), $path );
		}
		if ( 0 === strpos( $scope, 'template:' ) ) {
			$id = (int) substr( $scope, 9 );
			/* translators: %s: Bricks template name */
			return isset( $templates[ $id ] ) ? sprintf( __( 'pages using the Bricks template "%s"', 'beltoft-performance' ), $templates[ $id ] ) : sprintf( __( 'pages using Bricks template #%d', 'beltoft-performance' ), $id );
		}
		return self::page_name( $scope );
	}

	/** "Off on: …" for the scopes of one switched-off thing. */
	public static function where_text( array $scopes, array $templates, array $page_titles ): string {
		$scopes = array_values( array_unique( array_map( 'strval', $scopes ) ) );
		$types  = array_values( array_intersect( $scopes, Store::PAGE_TYPES ) );
		$other  = array_values( array_diff( $scopes, Store::PAGE_TYPES ) );
		if ( ! $types && 1 === count( $other ) && 0 === strpos( $other[0], 'url:' ) ) {
			/* translators: %s: page title and address */
			return sprintf( __( 'Off on one page only: %s', 'beltoft-performance' ), self::scope_name( $other[0], $templates, $page_titles ) );
		}
		if ( ! $other ) {
			$missing = array_values( array_diff( Store::PAGE_TYPES, $types ) );
			if ( ! $missing ) {
				return __( 'Off on: every page of the shop', 'beltoft-performance' );
			}
			if ( count( $missing ) <= 3 && count( $types ) >= 8 ) {
				$names = array_map( array( __CLASS__, 'page_name' ), self::sort_pages( $missing ) );
				$last  = array_pop( $names );
				/* translators: %s: page names */
				return sprintf( __( 'Off on: every page except %s', 'beltoft-performance' ), $names ? implode( ', ', $names ) . ' ' . __( 'and', 'beltoft-performance' ) . ' ' . $last : $last );
			}
		}
		$parts = array_map( array( __CLASS__, 'page_name' ), self::sort_pages( $types ) );
		foreach ( $other as $s ) {
			$parts[] = self::scope_name( $s, $templates, $page_titles );
		}
		/* translators: %s: page names */
		return sprintf( __( 'Off on: %s', 'beltoft-performance' ), implode( ', ', $parts ) );
	}

	/** "3 Oct" from an ISO date, '' when there is none. */
	public static function short_date( string $iso ): string {
		$t = '' !== $iso ? strtotime( $iso ) : false;
		return false === $t ? '' : gmdate( 'j M', $t );
	}

	/**
	 * How one switched-off thing got there: [ text, tone ok|warn ]. $rules: the rules of that thing; $names: [ login => display name ].
	 * Stored reasons are not shown (old ones are Portuguese); only the Perfmatters import marker is read.
	 */
	public static function provenance( array $rules, array $names, array $templates, array $page_titles ): array {
		$first = null;
		$hand  = array();
		$all   = array();
		$import = false;
		foreach ( $rules as $r ) {
			if ( ! $r instanceof Rule ) {
				continue;
			}
			$first  = $first ?? $r;
			$hand   = array_merge( $hand, $r->manual_pages );
			$all    = array_merge( $all, $r->pages );
			$import = $import || false !== strpos( $r->reason, \BeltoftPerformance\Rules\PerfmattersImport::REASON );
		}
		if ( null === $first ) {
			return array( 'text' => '', 'tone' => 'ok' );
		}
		$who  = $names[ $first->approved_by ] ?? $first->approved_by;
		$date = self::short_date( $first->approved_at );
		if ( $import ) {
			/* translators: %s: date */
			return array( 'text' => '' !== $date ? sprintf( __( 'Imported from Perfmatters on %s', 'beltoft-performance' ), $date ) : __( 'Imported from Perfmatters', 'beltoft-performance' ), 'tone' => 'ok' );
		}
		$by = '' !== $date ? sprintf( /* translators: 1: person, 2: date */ __( '%1$s on %2$s', 'beltoft-performance' ), $who, $date ) : $who;
		$hand = array_values( array_unique( $hand ) );
		if ( $hand && ! array_diff( $all, $hand ) ) {
			/* translators: %s: person and date */
			return array( 'text' => sprintf( __( 'Switched off by hand by %s — not tested', 'beltoft-performance' ), $by ), 'tone' => 'warn' );
		}
		/* translators: %s: person and date */
		$text = sprintf( __( 'Tested automatically · approved by %s', 'beltoft-performance' ), $by );
		if ( $hand ) {
			$types = array_values( array_intersect( $hand, Store::PAGE_TYPES ) );
			$parts = array_map( array( __CLASS__, 'page_name' ), self::sort_pages( $types ) );
			foreach ( array_diff( $hand, Store::PAGE_TYPES ) as $s ) {
				$parts[] = self::scope_name( (string) $s, $templates, $page_titles );
			}
			/* translators: %s: pages */
			$text .= ' · ' . sprintf( __( 'switched off by hand, not tested, on: %s', 'beltoft-performance' ), implode( ', ', $parts ) );
		}
		return array( 'text' => $text, 'tone' => 'ok' );
	}

	/** A test failure (English or old Portuguese evidence) in plain words. */
	public static function fail_reason( string $problem ): string {
		$map = array(
			'/layout changed|aspeto da p|content changed|conte[uú]do da p/i'      => __( 'The page looked different without it.', 'beltoft-performance' ),
			'/checkout stopped|checkout changed|finalizar|carrinho deixou/i'      => __( 'Checkout didn\'t work the same without it.', 'beltoft-performance' ),
			'/also removes|tamb[eé]m remove/i'                                     => __( 'Switching it off also took away things other plugins need.', 'beltoft-performance' ),
			'/HTTP status|estado HTTP/i'                                          => __( 'The page didn\'t load normally without it.', 'beltoft-performance' ),
			'/PHP errors|erros PHP/i'                                             => __( 'The page showed errors without it.', 'beltoft-performance' ),
			'/cookies/i'                                                          => __( 'Carts or logins behaved differently without it.', 'beltoft-performance' ),
			'/redirect|redirecionamento/i'                                        => __( 'The page sent visitors somewhere else without it.', 'beltoft-performance' ),
			'/no test page|sem p[aá]gina de teste/i'                              => __( 'There was no page of this kind to test on.', 'beltoft-performance' ),
			'/unstable|too large|could not measure|did not run|request failed|no trial report|did not remove|inst[aá]vel|falhou/i' => __( 'The test couldn\'t finish. Try testing again.', 'beltoft-performance' ),
		);
		foreach ( $map as $re => $text ) {
			if ( preg_match( $re, $problem ) ) {
				return $text;
			}
		}
		return __( 'The test found a difference.', 'beltoft-performance' );
	}

	/** "about 2 minutes" for a test of $fetches page loads (about 1.2 s each, measured on staging). */
	public static function test_time( int $fetches ): string {
		$min = max( 1, (int) ceil( $fetches * 1.2 / 60 ) );
		/* translators: %d: minutes */
		return sprintf( _n( 'about %d minute', 'about %d minutes', $min, 'beltoft-performance' ), $min );
	}

	/** What a plugin that has to stay does on page type $page, in a few words: a known phrase only when it is about that page; never-accept plugins say they are never switched off; else "used on this page". */
	public static function needed_phrase( string $plugin, string $page = '' ): string {
		$never = Store::never_accept_reason( $plugin );
		if ( null === $never && isset( self::NEEDED[ $plugin ] ) ) {
			[ $phrase, $pages ] = self::NEEDED[ $plugin ];
			return ( null === $pages || in_array( $page, $pages, true ) ) ? $phrase : __( 'used on this page', 'beltoft-performance' );
		}
		if ( null !== $never ) {
			$kinds = array(
				'cache'     => __( 'speeds up the site — never suggested (For experts: switch off yourself)', 'beltoft-performance' ),
				'mail'      => __( 'e-mails — never suggested (For experts: switch off yourself)', 'beltoft-performance' ),
				'security'  => __( 'security and logins — never suggested (For experts: switch off yourself)', 'beltoft-performance' ),
				'analytics' => __( 'statistics — never suggested (For experts: switch off yourself)', 'beltoft-performance' ),
			);
			foreach ( array( 'cache' => 'cache', 'email' => 'mail', 'security' => 'security', 'analytics' => 'analytics' ) as $needle => $k ) {
				if ( false !== strpos( $never, ' ' . $needle ) ) {
					return $kinds[ $k ];
				}
			}
			return __( 'never switched off', 'beltoft-performance' );
		}
		return __( 'used on this page', 'beltoft-performance' );
	}

	/** Where a page type was looked at, for the overview row. $url: the path the check fetched. */
	public static function page_hint( string $type, string $url, string $host ): string {
		$path = (string) preg_replace( '/([?&])bp_check=[^&]*(&|$)/', '$1', $url );
		$path = rtrim( $path, '?&' );
		if ( 'front' === $type ) {
			return $host;
		}
		if ( 'product' === $type ) {
			return __( 'every product', 'beltoft-performance' );
		}
		if ( 'post' === $type ) {
			return __( 'every blog post', 'beltoft-performance' );
		}
		if ( in_array( $type, array( 'archive_cat', 'archive_brand', 'archive_tag', 'search', 'page' ), true ) ) {
			/* translators: %s: an example address */
			return '' !== $path ? sprintf( __( 'e.g. %s', 'beltoft-performance' ), $path ) : '';
		}
		return $path;
	}

	/** Settings tab: [ key => [ label, note ] ] for the switches the plugin handles itself (Bricks-handled ones are left out). */
	public static function settings_rows( array $definitions ): array {
		$plain = array(
			'dashicons'        => array( __( 'Leave out the WordPress icon set for visitors', 'beltoft-performance' ), __( 'logged-in staff still get it', 'beltoft-performance' ) ),
			'global_styles'    => array( __( 'Leave out block-editor styling', 'beltoft-performance' ), __( 'the site is built with Bricks', 'beltoft-performance' ) ),
			'head_links'       => array( __( 'Hide technical tags in the page header', 'beltoft-performance' ), '' ),
			'feeds'            => array( __( 'Turn off RSS feeds', 'beltoft-performance' ), '' ),
			'self_pingbacks'   => array( __( 'Stop the site pinging itself', 'beltoft-performance' ), '' ),
			'heartbeat'        => array( __( 'Less background traffic in the admin', 'beltoft-performance' ), __( 'kept while editing posts, orders and in Bricks', 'beltoft-performance' ) ),
			'wc_status_widget' => array( __( 'Hide the "WooCommerce status" box on the dashboard', 'beltoft-performance' ), __( 'it slows the dashboard down', 'beltoft-performance' ) ),
			'xmlrpc'           => array( __( 'Block XML-RPC', 'beltoft-performance' ), __( 'an old remote-access door bots attack', 'beltoft-performance' ) ),
			'db_cleanup'       => array( __( 'Clean up the database every night', 'beltoft-performance' ), __( 'at 03:30, like Perfmatters did: all post revisions, auto-drafts and trashed posts, spam and trashed comments, expired and all transients, and an optimise of non-InnoDB tables', 'beltoft-performance' ) ),
			'jquery_footer'    => array( __( 'Load jQuery at the end of the page', 'beltoft-performance' ), __( 'pages show sooner; cart, checkout and account keep it in the head. Switch off if a button or form stops working', 'beltoft-performance' ) ),
			'gtm_delay'        => array( __( 'Start Google Tag Manager on the first scroll, tap or key press', 'beltoft-performance' ), __( 'or after 10 seconds; faster pages, but visitors who leave sooner without touching the page are not counted. Cart, checkout, order received and visits from ads or campaign links start it at once', 'beltoft-performance' ) ),
		);
		$out = array();
		foreach ( $definitions as $k => $d ) {
			if ( null !== ( $d[2] ?? null ) ) {
				continue;
			}
			$out[ (string) $k ] = $plain[ $k ] ?? array( (string) $d[0], '' );
		}
		return $out;
	}

	/** The Bricks line under WordPress extras: [ text, ok ]. $states: Ui::bricks_states(). */
	public static function bricks_line( array $states ): array {
		$off = array_keys( array_filter( $states, static fn( $on ) => ! $on ) );
		if ( ! $off ) {
			return array( 'text' => __( 'Emojis, embeds and lazy-loading images are handled by Bricks.', 'beltoft-performance' ), 'ok' => true );
		}
		/* translators: %s: features */
		return array( 'text' => sprintf( __( 'Bricks is not handling: %s. Switch them on in the Bricks settings.', 'beltoft-performance' ), implode( ', ', $off ) ), 'ok' => false );
	}
}
