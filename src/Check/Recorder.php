<?php
namespace BeltoftPerformance\Check;

use BeltoftPerformance\Request\PageType;
use BeltoftPerformance\Rules\Rule;

defined( 'ABSPATH' ) || exit;

/** Instruments one signed check request and writes what it saw. Normal visitors never reach past boot(). */
final class Recorder {
	private static ?array $job = null;
	private static array $fired = array();
	private static array $errors = array();
	private static array $file_owner = array();
	private static array $cb_owner = array();
	private static string $html = '';
	private static int $ob_level = 0;

	public const SIGNATURES = array(
		'woo-smart-wishlist'                                 => array( 'woosw-' ),
		'beltoft-in-stock-notifier'                          => array( 'bisn-' ),
		'pymntpl-paypal-woocommerce'                         => array( 'wc-ppcp', 'paypal' ),
		'multibanco-ifthen-software-gateway-for-woocommerce' => array( 'multibanco', 'mbway' ),
		'super-socializer'                                   => array( 'the_champ', 'heateor' ),
		'wpc-composite-products'                             => array( 'wooco-' ),
		'pressidium-cookie-consent'                          => array( 'pressidium', 'cc-window', 'cookie-consent' ),
		'woocommerce-subscriptions'                          => array( 'wcs-', 'subscription' ),
		'flexible-shipping'                                  => array( 'flexible-shipping', 'fs-' ),
		'beltoft-loyalty-rewards'                            => array( 'wclr-', 'loyalty' ),
		'beltoft-gift-cards'                                 => array( 'bgcw-' ),
	);

	public static function boot(): void {
		$header = (string) ( $_SERVER['HTTP_X_BP_CHECK'] ?? '' );
		if ( '' === $header || is_admin() ) {
			return;
		}
		if ( ( defined( 'DOING_CRON' ) && DOING_CRON ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || wp_doing_ajax() || ! self::is_page_request( $_SERVER, $_GET ) ) {
			return;
		}
		$job = Signature::verify( $header, Signature::secret(), time() );
		if ( ! $job || empty( $job['id'] ) || ! preg_match( '/^[a-z0-9\-]{6,40}\z/', (string) $job['id'] ) || ! self::job_matches_request( $job, (string) ( $_SERVER['REQUEST_URI'] ?? '' ) ) ) {
			return;
		}
		if ( ! in_array( $job['state'] ?? '', array( 'guest', 'cart', 'logged_in' ), true ) ) {
			return;
		}
		self::$job = $job;
		defined( 'DONOTCACHEPAGE' ) || define( 'DONOTCACHEPAGE', true );
		add_action( 'send_headers', 'nocache_headers' );
		if ( 'logged_in' === ( $job['state'] ?? '' ) ) {
			add_filter( 'determine_current_user', array( __CLASS__, 'qa_user' ), 99 );
			// Checkout with an empty cart redirects by design: give the QA customer one product for this request only.
			add_action( 'wp', array( __CLASS__, 'fill_qa_checkout_cart' ), 0 );
		}
		if ( ! empty( $job['trial'] ) && ! \BeltoftPerformance\Plugin::disabled() ) {
			$rules = array_map( array( Rule::class, 'from_array' ), array_filter( (array) $job['trial'], 'is_array' ) );
			add_action( 'wp_enqueue_scripts', static function () use ( $rules ) { \BeltoftPerformance\Assets\Suppressor::apply( PageType::current(), PageType::host(), $rules ); }, PHP_INT_MAX );
		}
		set_error_handler( static function ( $no, $str, $file, $line ) { self::$errors[] = array( $no, $str, str_replace( ABSPATH, '', (string) $file ), $line ); return false; } );
		add_action( 'all', array( __CLASS__, 'sample' ) );
		add_action( 'template_redirect', static function () {
			ob_start( static function ( $b ) { self::$html .= $b; return $b; } );
			self::$ob_level = ob_get_level();
		}, PHP_INT_MIN );
		add_action( 'shutdown', array( __CLASS__, 'finish' ), 0 );
	}

	/** A job only counts on the path it was signed for, and only on a bp_check= request (never a cached or replayed URL). */
	public static function job_matches_request( array $job, string $uri ): bool {
		$query = (string) ( explode( '?', explode( '#', $uri, 2 )[0], 2 )[1] ?? '' );
		parse_str( $query, $q );
		return isset( $q['bp_check'], $job['path'] ) && \BeltoftPerformance\Request\Classifier::request_path( $uri ) === $job['path'];
	}

	/** Only real page loads are instrumented: no REST, AJAX fragments or non-GET requests. */
	public static function is_page_request( array $server, array $get ): bool {
		if ( 'GET' !== strtoupper( (string) ( $server['REQUEST_METHOD'] ?? '' ) ) ) {
			return false;
		}
		$uri = (string) ( $server['REQUEST_URI'] ?? '' );
		if ( str_contains( $uri, '/wp-json/' ) || str_contains( $uri, 'rest_route=' ) || isset( $get['rest_route'] ) || isset( $get['wc-ajax'] ) ) {
			return false;
		}
		return true;
	}

	/** The QA user must be exactly a customer (no extra roles, no edit_posts) matching the configured login email on @mindthetrash.pt. */
	public static function qa_user_ok( array $roles, bool $can_edit_posts, string $email, array $cfg ): bool {
		$email = strtolower( $email );
		return array( 'customer' ) === array_values( $roles )
			&& ! $can_edit_posts
			&& '' !== $email
			&& $email === strtolower( (string) ( $cfg['email'] ?? '' ) )
			&& str_ends_with( $email, '@mindthetrash.pt' );
	}

	/** True while this request put a product in the QA customer's cart (emptied again at shutdown). */
	private static bool $filled = false;

	/** Pure: whether this check request should get a one-product cart: the QA customer on checkout with an empty cart. */
	public static function needs_qa_cart( bool $is_checkout, bool $is_qa_user, bool $cart_empty, int $product ): bool {
		return $is_checkout && $is_qa_user && $cart_empty && $product > 0;
	}

	/** Checkout, logged in as the QA customer: add the flow product so the page renders instead of redirecting. */
	public static function fill_qa_checkout_cart(): void {
		if ( ! function_exists( 'is_checkout' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$cfg  = (array) get_option( 'beltoft_performance_qa_user', array() );
		$user = wp_get_current_user();
		$qa   = $user->ID && (string) $user->user_login === (string) ( $cfg['login'] ?? '' ) && self::qa_user_ok( (array) $user->roles, $user->has_cap( 'edit_posts' ), (string) $user->user_email, $cfg );
		$id   = Runner::flow_product_id();
		if ( ! self::needs_qa_cart( is_checkout() && ! is_wc_endpoint_url(), $qa, WC()->cart->is_empty(), $id ) ) {
			return;
		}
		// For this request only: never written to the QA customer's saved cart or session (a browser test may be using them).
		add_filter( 'woocommerce_persistent_cart_enabled', '__return_false' );
		if ( WC()->session ) {
			remove_action( 'shutdown', array( WC()->session, 'save_data' ), 20 );
		}
		if ( WC()->cart->add_to_cart( $id, 1 ) ) {
			self::$filled = true;
			wc_clear_notices(); // "added to your cart" would differ between the check and its trials
			// In memory before anything at shutdown reads the cart (e.g. abandoned-cart tracking).
			add_action( 'shutdown', static function () { if ( self::$filled && WC()->cart ) { WC()->cart->empty_cart( false ); } }, 1 );
		}
	}

	public static function qa_user( $user_id ) {
		$cfg  = (array) get_option( 'beltoft_performance_qa_user', array() );
		$user = ! empty( $cfg['login'] ) ? get_user_by( 'login', $cfg['login'] ) : false;
		if ( ! $user || ! self::qa_user_ok( (array) $user->roles, $user->has_cap( 'edit_posts' ), (string) $user->user_email, $cfg ) ) {
			return $user_id; // misconfigured: stay a guest, the report will say so
		}
		return $user->ID;
	}

	/** Counts callbacks registered on hooks that fired, per plugin directory (owner resolved once per callback). */
	public static function sample(): void {
		global $wp_filter;
		$hook = current_filter();
		if ( ! isset( $wp_filter[ $hook ] ) ) {
			return;
		}
		foreach ( $wp_filter[ $hook ]->callbacks as $cbs ) {
			foreach ( $cbs as $idx => $cb ) {
				$dir = self::$cb_owner[ $idx ] ??= self::owner_of( $cb['function'] );
				if ( '' !== $dir ) {
					self::$fired[ $dir ] = ( self::$fired[ $dir ] ?? 0 ) + 1;
				}
			}
		}
	}

	private static function owner_of( $fn ): string {
		try {
			if ( is_array( $fn ) ) {
				$ref = new \ReflectionMethod( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0], $fn[1] );
			} elseif ( is_string( $fn ) && str_contains( $fn, '::' ) ) {
				$ref = new \ReflectionMethod( $fn );
			} elseif ( is_string( $fn ) || $fn instanceof \Closure ) {
				$ref = new \ReflectionFunction( $fn );
			} else {
				return '';
			}
		} catch ( \ReflectionException $e ) {
			return '';
		}
		$file = (string) $ref->getFileName();
		if ( ! isset( self::$file_owner[ $file ] ) ) {
			self::$file_owner[ $file ] = preg_match( '#/wp-content/plugins/([^/]+)/#', $file, $m ) ? $m[1] : '';
		}
		return self::$file_owner[ $file ];
	}

	/**
	 * Hash of the visible markup. Ignored: whole script/style blocks, stylesheet/preload/icon links, whitespace and per-render
	 * random ids (Bricks interactions/form fields, WooCommerce quantity uniqid). Kept: JSON-LD (structured data), <noscript>
	 * (tracking pixels, fallbacks) and canonical/alternate/prev/next links — removing a plugin that prints them is a change.
	 */
	public static function content_hash( string $html ): string {
		$keep = array();
		$hold = static function ( array $m ) use ( &$keep ): string {
			$keep[] = $m[0];
			return "\x00" . ( count( $keep ) - 1 ) . "\x00";
		};
		$html = (string) preg_replace_callback( '#<noscript\b[^>]*>.*?</noscript>#is', $hold, $html );
		$html = (string) preg_replace_callback( '#<script\b[^>]*>.*?</script>#is', static function ( array $m ) use ( $hold ): string {
			return preg_match( '#^<script\b[^>]*\btype\s*=\s*["\']?application/ld\+json#i', $m[0] ) ? $hold( $m ) : '';
		}, $html );
		$html = (string) preg_replace_callback( '#<link\b[^>]*>#i', static function ( array $m ) use ( $hold ): string {
			return preg_match( '#\brel\s*=\s*["\']?\s*(canonical|alternate|prev|next)\b#i', $m[0] ) ? $hold( $m ) : '';
		}, $html );
		$html = (string) preg_replace( '#<style\b[^>]*>.*?</style>#is', '', $html );
		$html = (string) preg_replace_callback( '#\x00(\d+)\x00#', static fn( array $m ): string => $keep[ (int) $m[1] ], $html );
		$html = (string) preg_replace(
			array( '#\s+#', '#(data-interaction-id="|id="form-field-|for="form-field-)[0-9a-f]{6}#', '#\bquantity_[0-9a-f]{13}\b#' ),
			array( '', '$1', 'quantity_' ),
			$html
		);
		return md5( $html );
	}

	/** Pure: a sample name from the payload ('product-variable'), or '' when absent or malformed. */
	public static function valid_target( string $target ): string {
		return preg_match( '/^[a-z0-9][a-z0-9_\-]{0,39}\z/', $target ) ? $target : '';
	}

	/** Pure: the report file name: page-state, sample-state for an extra sample, or page-state-trial-<hash> for a trial (as Trial::report_name). */
	public static function file_name( string $page, string $state, array $trial, string $target ): string {
		if ( $trial ) {
			return $page . '-' . $state . '-trial-' . substr( md5( wp_json_encode( $trial ) ), 0, 8 ) . '.json';
		}
		$target = self::valid_target( $target );
		return ( '' !== $target ? $target : $page ) . '-' . $state . '.json';
	}

	/** Pure: the cookie names this response sets (sorted, unique) and its Location header, from headers_list(). */
	public static function response_facts( array $headers ): array {
		$cookies  = array();
		$location = '';
		foreach ( $headers as $h ) {
			$pair = explode( ':', (string) $h, 2 );
			if ( 2 !== count( $pair ) ) {
				continue;
			}
			$name  = strtolower( trim( $pair[0] ) );
			$value = trim( $pair[1] );
			if ( 'set-cookie' === $name ) {
				$c = trim( explode( '=', $value, 2 )[0] );
				if ( '' !== $c ) {
					$cookies[ $c ] = true;
				}
			} elseif ( 'location' === $name ) {
				$location = $value;
			}
		}
		$cookies = array_map( 'strval', array_keys( $cookies ) );
		sort( $cookies );
		return array( 'cookies' => $cookies, 'location' => $location );
	}

	/** Pure: the src WordPress hands to script_loader_src/style_loader_src: relative paths get the base URL, then ?ver= (null: none, empty: the default version). */
	public static function loader_src( string $src, $ver, string $base_url, string $content_url, string $default_version ): string {
		if ( '' === $src ) {
			return '';
		}
		if ( ! preg_match( '|^(https?:)?//|', $src ) && ! ( '' !== $content_url && str_starts_with( $src, $content_url ) ) ) {
			$src = $base_url . $src;
		}
		$ver = null === $ver ? '' : ( $ver ? (string) $ver : $default_version );
		return '' === $ver ? $src : $src . ( str_contains( $src, '?' ) ? '&' : '?' ) . 'ver=' . rawurlencode( $ver );
	}

	/**
	 * Pure: [ 'printed' => handles, 'blanked' => handles ] from [ handle => loader src ]. Like WordPress: a handle without src
	 * (alias, inline only) prints and is never filtered; one whose filtered src is empty or false prints no file.
	 */
	public static function split_printed( array $srcs, callable $filter ): array {
		$out = array( 'printed' => array(), 'blanked' => array() );
		foreach ( $srcs as $h => $src ) {
			$h = (string) $h;
			if ( '' === (string) $src ) {
				$out['printed'][] = $h;
				continue;
			}
			$filtered = $filter( (string) $src, $h );
			$out[ ( false === $filtered || '' === trim( (string) $filtered ) ) ? 'blanked' : 'printed' ][] = $h;
		}
		return $out;
	}

	public static function finish(): void {
		// End only our own buffer (and any above it), never the ones below.
		while ( self::$ob_level > 0 && ob_get_level() >= self::$ob_level ) {
			if ( ! @ob_end_flush() ) {
				break;
			}
		}
		$html = self::$html;
		$assets  = array();
		$blanked = array();
		foreach ( array( 'script' => wp_scripts(), 'style' => wp_styles() ) as $kind => $deps ) {
			$graph = \BeltoftPerformance\Assets\Graph::from_wp( $deps );
			$srcs  = array();
			foreach ( array_unique( array_merge( (array) $deps->done, (array) $deps->queue ) ) as $h ) {
				$obj        = $deps->registered[ $h ] ?? null;
				$srcs[ $h ] = $obj && is_string( $obj->src ) ? self::loader_src( $obj->src, $obj->ver, (string) $deps->base_url, (string) $deps->content_url, (string) $deps->default_version ) : '';
			}
			// As WordPress prints: a file whose loader_src filter comes back empty (Perfmatters' Script Manager) is not printed.
			$split = self::split_printed( $srcs, static fn( $src, $h ) => apply_filters( $kind . '_loader_src', $src, $h ) );
			foreach ( $split['blanked'] as $h ) {
				$src       = isset( $deps->registered[ $h ] ) && is_string( $deps->registered[ $h ]->src ) ? $deps->registered[ $h ]->src : '';
				$blanked[] = array( 'kind' => $kind, 'handle' => $h, 'src' => $src, 'plugin' => $graph->owner_plugin( $src ) );
			}
			foreach ( $split['printed'] as $h ) {
				$src = isset( $deps->registered[ $h ] ) && is_string( $deps->registered[ $h ]->src ) ? $deps->registered[ $h ]->src : '';
				$extra  = isset( $deps->registered[ $h ] ) ? (array) $deps->registered[ $h ]->extra : array();
				$inline = 0;
				foreach ( array( 'before', 'after' ) as $k ) {
					foreach ( (array) ( $extra[ $k ] ?? array() ) as $chunk ) {
						$inline += is_string( $chunk ) ? strlen( $chunk ) : 0;
					}
				}
				if ( 'script' === $kind && isset( $extra['data'] ) && is_string( $extra['data'] ) ) {
					$inline += strlen( $extra['data'] ); // wp_localize_script
				}
				$assets[] = array( 'kind' => $kind, 'handle' => $h, 'src' => $src, 'plugin' => $graph->owner_plugin( $src ), 'deps' => isset( $deps->registered[ $h ] ) ? array_values( (array) $deps->registered[ $h ]->deps ) : array(), 'inline' => $inline );
			}
		}
		$signatures = array();
		foreach ( self::SIGNATURES as $plugin => $needles ) {
			foreach ( $needles as $n ) {
				if ( '' !== $html && false !== stripos( $html, $n ) ) {
					$signatures[ $plugin ][] = $n;
				}
			}
		}
		$facts  = self::response_facts( headers_list() );
		$report = array(
			'id'         => self::$job['id'],
			'state'      => self::$job['state'] ?? 'guest',
			'trial'      => self::$job['trial'] ?? array(),
			'target'     => self::valid_target( (string) ( self::$job['target'] ?? '' ) ),
			'url'        => (string) ( $_SERVER['REQUEST_URI'] ?? '' ),
			'page'       => PageType::current(),
			'templates'  => PageType::template_ids(),
			'host'       => PageType::host(),
			'user'       => get_current_user_id(),
			'status'     => http_response_code(),
			'plugins'    => array_values( array_map( static fn( $p ) => strtok( $p, '/' ), (array) get_option( 'active_plugins', array() ) ) ),
			'fired'      => self::$fired,
			'assets'     => $assets,
			'blanked'    => $blanked,
			'signatures' => $signatures,
			'errors'     => self::$errors,
			'html_hash'  => self::content_hash( $html ),
			'cookies'    => $facts['cookies'],
			'location'   => $facts['location'],
			'bytes'      => strlen( $html ),
			'ms'         => round( ( microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) * 1000 ),
		);
		$dir = wp_upload_dir()['basedir'] . '/beltoft-performance/checks/' . self::$job['id'];
		wp_mkdir_p( $dir );
		$name = self::file_name( (string) $report['page'], (string) $report['state'], (array) $report['trial'], (string) $report['target'] );
		$tmp = $dir . '/' . $name . '.' . getmypid() . '.tmp';
		if ( false !== file_put_contents( $tmp, wp_json_encode( $report ) ) ) {
			rename( $tmp, $dir . '/' . $name ); // atomic: readers never see a half-written report
		}
	}
}
