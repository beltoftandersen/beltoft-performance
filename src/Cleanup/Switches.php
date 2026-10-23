<?php
namespace BeltoftPerformance\Cleanup;

use BeltoftPerformance\Plugin;

defined( 'ABSPATH' ) || exit;

final class Switches {
	public const OPTION = 'beltoft_performance_switches';

	/** Switches shown under "Scripts" rather than "WordPress extras". */
	public const SCRIPT_KEYS = array( 'jquery_footer', 'gtm_delay' );

	/** Switches shown under "Database". */
	public const DB_KEYS = array( 'db_cleanup' );

	private const JQUERY = array( 'jquery', 'jquery-core', 'jquery-migrate' );

	public static function definitions(): array {
		return array(
			'emojis'          => array( __( 'Emojis', 'beltoft-performance' ), true, 'disableEmojis' ),
			'embeds'          => array( __( 'Embeds (oEmbed)', 'beltoft-performance' ), true, 'disableEmbed' ),
			'jquery_migrate'  => array( __( 'jQuery Migrate', 'beltoft-performance' ), true, 'disableJqueryMigrate' ),
			'dashicons'       => array( __( 'Dashicons for visitors', 'beltoft-performance' ), true, null ),
			'global_styles'   => array( __( 'Block editor global styles', 'beltoft-performance' ), true, null ),
			'head_links'      => array( __( 'RSD links, shortlink and WordPress version in the <head>', 'beltoft-performance' ), true, null ),
			'feeds'           => array( __( 'RSS feeds (redirect to the home page)', 'beltoft-performance' ), true, null ),
			'self_pingbacks'  => array( __( 'Self-pingbacks', 'beltoft-performance' ), true, null ),
			'heartbeat'       => array( __( 'Heartbeat only when editing posts or orders and in Bricks', 'beltoft-performance' ), true, null ),
			'wc_status_widget'=> array( __( '"WooCommerce status" dashboard widget', 'beltoft-performance' ), true, null ),
			'xmlrpc'          => array( __( 'XML-RPC and pingbacks (xmlrpc.php returns 403)', 'beltoft-performance' ), true, null ),
			// Scripts: off by default, each has a trade-off (see Words::settings_rows()).
			'jquery_footer'   => array( __( 'Load jQuery at the end of the page', 'beltoft-performance' ), false, null ),
			'gtm_delay'       => array( __( 'Start Google Tag Manager on the first interaction', 'beltoft-performance' ), false, null ),
			// Database: off by default (Cleanup\Database).
			'db_cleanup'      => array( __( 'Clean up the database every night', 'beltoft-performance' ), false, null ),
		);
	}

	public static function enabled( string $key ): bool {
		$def = self::definitions()[ $key ] ?? null;
		if ( ! $def || null !== $def[2] ) {
			return false; // handled by Bricks: never duplicated
		}
		$saved = (array) get_option( self::OPTION, array() );
		return array_key_exists( $key, $saved ) ? (bool) $saved[ $key ] : (bool) $def[1];
	}

	/** Pure: dashicons go only for guests — logged-in customers and staff can see plugin UI (admin bar, account widgets) that uses them. */
	public static function drop_dashicons( bool $logged_in ): bool {
		return ! $logged_in;
	}

	public static function keep_heartbeat( bool $is_admin, string $pagenow, array $get, bool $is_builder ): bool {
		if ( $is_builder || isset( $get['bricks'] ) ) {
			return true;
		}
		if ( ! $is_admin ) {
			return false;
		}
		// The HPOS order screen takes its edit lock through heartbeat, like post.php.
		$hpos_edit = 'admin.php' === $pagenow && 'wc-orders' === ( $get['page'] ?? '' ) && 'edit' === ( $get['action'] ?? '' );
		return $hpos_edit || in_array( $pagenow, array( 'post.php', 'post-new.php' ), true );
	}

	/**
	 * Pure: the handles that must move to the footer with jQuery - jQuery itself and every script that
	 * needs it, directly or through another script. WordPress prints a dependency wherever its first
	 * dependent goes, so one head script left needing jQuery would pull it back into the head.
	 *
	 * @param array<string, string[]> $deps handle => dependency handles.
	 * @return string[]
	 */
	public static function jquery_dependents( array $deps ): array {
		// Grow the set from jQuery until nothing new needs it: order-independent and safe with dependency loops.
		$in = array_fill_keys( self::JQUERY, true );
		do {
			$grew = false;
			foreach ( $deps as $h => $ds ) {
				$h = (string) $h;
				if ( ! isset( $in[ $h ] ) && array_intersect_key( array_flip( array_map( 'strval', (array) $ds ) ), $in ) ) {
					$in[ $h ] = true;
					$grew     = true;
				}
			}
		} while ( $grew );
		return array_values( array_filter( array_map( 'strval', array_keys( $deps ) ), static fn( $h ) => isset( $in[ $h ] ) ) );
	}

	/**
	 * Pure: whether starting Google Tag Manager late is safe for this request. It is not when GTM4WP would not
	 * print its standard loader anyway (placement off, production-only on a non-production copy, a custom
	 * server-side domain/path or environment), on cart/checkout/order received (the purchase event fires
	 * there and the shopper may close the page at once) and on ad/campaign landings (the click id and
	 * UTM landing page must be recorded before the visitor taps away).
	 *
	 * @param array<string, mixed> $o         gtm4wp-options.
	 * @param bool                 $sensitive Cart, checkout or order received page.
	 * @param array<string, mixed> $get       Query parameters.
	 * @param string               $env       wp_get_environment_type().
	 */
	public static function gtm_delay_applies( array $o, bool $sensitive, array $get, string $env ): bool {
		if ( $sensitive || 3 === (int) ( $o['gtm-code-placement'] ?? 0 ) ) {
			return false;
		}
		if ( ! empty( $o['gtm-production-only'] ) && 'production' !== $env ) {
			return false;
		}
		if ( ! empty( $o['gtm-domain-name'] ) || ! empty( $o['gtm-env-gtm-auth'] ) || ! empty( $o['gtm-env-gtm-preview'] ) ) {
			return false;
		}
		foreach ( (array) ( $o['gtm-containers'] ?? array() ) as $c ) {
			foreach ( array( 'gtm_auth', 'gtm_preview', 'domain', 'path' ) as $k ) {
				if ( ! empty( $c[ $k ] ) ) {
					return false;
				}
			}
		}
		foreach ( array_keys( $get ) as $k ) {
			if ( preg_match( '/^(utm_[a-z]+|gclid|gbraid|wbraid|fbclid|msclkid|dclid|_gl)$/i', (string) $k ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Pure: the Google Tag Manager loader started on the first scroll, tap, key or mouse move, or 10 s
	 * after the page loaded - the same container snippet GTM4WP prints, only later. The data layer and
	 * consent defaults GTM4WP and the cookie banner write early are queued and read when GTM starts.
	 *
	 * @param string[] $ids   Container IDs (GTM-XXXX).
	 * @param string   $layer Data layer variable name.
	 */
	public static function gtm_on_interaction_js( array $ids, string $layer ): string {
		$ids = array_values( array_filter( $ids, static fn( $i ) => (bool) preg_match( '/^GTM-[A-Z0-9]+$/D', (string) $i ) ) );
		if ( ! $ids ) {
			return '';
		}
		$layer = preg_match( '/^[A-Za-z_$][A-Za-z0-9_$]*$/', $layer ) ? $layer : 'dataLayer';
		return '(function(w,d,ids,l){var go=false,ev=["scroll","pointerdown","keydown","touchstart","mousemove"];'
			. 'function load(){if(go){return;}go=true;ev.forEach(function(e){w.removeEventListener(e,load);});'
			. 'ids.forEach(function(i){if(w.google_tag_manager&&w.google_tag_manager[i]){return;}w[l]=w[l]||[];w[l].push({"gtm.start":new Date().getTime(),event:"gtm.js"});'
			. 'var j=d.createElement("script");j.async=true;j.src="https://www.googletagmanager.com/gtm.js?id="+i+(l!=="dataLayer"?"&l="+l:"");d.head.appendChild(j);});}'
			. 'ev.forEach(function(e){w.addEventListener(e,load,{passive:true});});'
			. 'if(d.readyState==="complete"){setTimeout(load,10000);}else{w.addEventListener("load",function(){setTimeout(load,10000);});}'
			. '})(window,document,' . wp_json_encode( $ids ) . ',' . wp_json_encode( $layer ) . ');';
	}

	public static function register(): void {
		if ( Plugin::disabled() ) {
			return;
		}
		if ( self::enabled( 'dashicons' ) ) {
			// Deregistered, not only dequeued: core thickbox (enqueued by plugins) depends on it and would pull it back in.
			add_action( 'wp_enqueue_scripts', static function () { if ( self::drop_dashicons( is_user_logged_in() ) ) { wp_dequeue_style( 'dashicons' ); wp_deregister_style( 'dashicons' ); } }, 100 );
		}
		if ( self::enabled( 'global_styles' ) ) {
			// WP 6.9+ classic themes enqueue global styles at wp_footer and hoist them into <head>: unhook both calls.
			remove_action( 'wp_enqueue_scripts', 'wp_enqueue_global_styles' );
			remove_action( 'wp_footer', 'wp_enqueue_global_styles', 1 );
			add_action( 'wp_enqueue_scripts', static function () { wp_dequeue_style( 'global-styles' ); wp_dequeue_style( 'classic-theme-styles' ); }, 100 );
			remove_action( 'wp_body_open', 'wp_global_styles_render_svg_filters' );
		}
		if ( self::enabled( 'head_links' ) ) {
			remove_action( 'wp_head', 'rsd_link' );
			remove_action( 'wp_head', 'wp_shortlink_wp_head' );
			remove_action( 'template_redirect', 'wp_shortlink_header', 11 );
			remove_action( 'wp_head', 'wp_generator' );
			add_filter( 'the_generator', '__return_empty_string' );
		}
		if ( self::enabled( 'feeds' ) ) {
			remove_action( 'wp_head', 'feed_links', 2 );
			remove_action( 'wp_head', 'feed_links_extra', 3 );
			foreach ( array( 'do_feed', 'do_feed_rdf', 'do_feed_rss', 'do_feed_rss2', 'do_feed_atom', 'do_feed_rss2_comments', 'do_feed_atom_comments' ) as $feed ) {
				add_action( $feed, static function () { wp_safe_redirect( home_url( '/' ), 301 ); exit; }, 1 );
			}
		}
		if ( self::enabled( 'self_pingbacks' ) ) {
			add_action( 'pre_ping', static function ( &$links ) { $home = home_url(); foreach ( $links as $i => $l ) { if ( str_starts_with( $l, $home ) ) { unset( $links[ $i ] ); } } } );
		}
		if ( self::enabled( 'heartbeat' ) ) {
			add_action( 'init', static function () { global $pagenow; $builder = function_exists( 'bricks_is_builder' ) && bricks_is_builder(); if ( ! self::keep_heartbeat( is_admin(), (string) $pagenow, $_GET, $builder ) ) { wp_deregister_script( 'heartbeat' ); } }, 1 );
		}
		if ( self::enabled( 'xmlrpc' ) ) {
			add_filter( 'xmlrpc_enabled', '__return_false' );
			add_filter( 'pings_open', '__return_false', 9999 );
			add_filter( 'wp_headers', static function ( $h ) { unset( $h['X-Pingback'] ); return $h; } );
			if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
				add_action( 'init', static function () { status_header( 403 ); exit; }, 1 );
			}
		}
		if ( self::enabled( 'jquery_footer' ) ) {
			add_action(
				'wp_enqueue_scripts',
				static function () {
					// Cart, checkout, order received and account keep jQuery in the head: payment gateways print
					// inline jQuery in their fields (MB WAY), and those pages need a checkout flow test first.
					$commerce = ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_account_page' ) && is_account_page() );
					if ( $commerce || ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() ) ) {
						return;
					}
					$scripts = wp_scripts();
					$deps    = array();
					foreach ( $scripts->registered as $h => $dep ) {
						$deps[ $h ] = (array) $dep->deps;
					}
					foreach ( self::jquery_dependents( $deps ) as $h ) {
						$scripts->add_data( $h, 'group', 1 );
					}
				},
				PHP_INT_MAX
			);
		}
		if ( self::enabled( 'gtm_delay' ) && ! is_admin() ) {
			add_action( 'wp', array( self::class, 'delay_gtm' ) );
		}
		if ( self::enabled( 'wc_status_widget' ) ) {
			add_action( 'wp_dashboard_setup', static function () { remove_meta_box( 'woocommerce_dashboard_status', 'dashboard', 'normal' ); }, 40 );
		}
	}

	/** Runs on 'wp' (page type known, before GTM4WP prints its loader in the head). */
	public static function delay_gtm(): void {
		$o         = (array) get_option( 'gtm4wp-options', array() );
		$sensitive = ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) || ( function_exists( 'is_order_received_page' ) && is_order_received_page() );
		if ( ! self::gtm_delay_applies( $o, $sensitive, $_GET, wp_get_environment_type() ) ) {
			return; // GTM4WP prints its loader as usual.
		}
		// GTM4WP's own kill switch: skips only its container loader; data layer, consent defaults and events stay.
		add_filter( 'gtm4wp_output_container', '__return_false' );
		add_action(
			'wp_footer',
			static function () use ( $o ) {
				// Same roles GTM4WP leaves out (staff, so admin visits do not count as traffic).
				$skip = array_filter( array_map( 'trim', explode( ',', (string) ( $o['gtm-no-gtm-for-logged-in'] ?? '' ) ) ) );
				if ( $skip && is_user_logged_in() && array_intersect( $skip, (array) wp_get_current_user()->roles ) ) {
					return;
				}
				$ids = array_map( static fn( $c ) => (string) ( $c['id'] ?? '' ), (array) ( $o['gtm-containers'] ?? array() ) );
				if ( ! array_filter( $ids ) && ! empty( $o['gtm-code'] ) ) {
					$ids = array_map( 'trim', explode( ',', (string) $o['gtm-code'] ) );
				}
				$js = self::gtm_on_interaction_js( $ids, (string) ( $o['gtm-datalayer-variable-name'] ?? '' ) ?: 'dataLayer' );
				if ( '' !== $js ) {
					wp_print_inline_script_tag( $js, array( 'id' => 'bp-gtm-on-interaction' ) );
				}
			},
			99
		);
	}
}
