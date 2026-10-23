<?php
namespace BeltoftPerformance\Rules;

defined( 'ABSPATH' ) || exit;

final class Store {
	public const OPTION     = 'beltoft_performance_rules';
	public const PAGE_TYPES = array( 'front', 'shop', 'product', 'archive_cat', 'archive_brand', 'archive_tag', 'search', 'cart', 'checkout', 'order_received', 'account', 'blog', 'post', 'page' );
	public const URL_PAGE_TYPES = array( 'front', 'shop', 'product', 'archive_cat', 'archive_brand', 'archive_tag', 'search', 'cart', 'checkout', 'order_received', 'account', 'blog' );
	public const HOSTS      = array( 'retail', 'trade' );
	private const KINDS     = array( 'unload', 'plugin', 'script', 'style' );

	/** @return Rule[] */
	public static function all(): array {
		$rows = array_filter( (array) get_option( self::OPTION, array() ), static fn( $r ) => is_array( $r ) && isset( $r['target'] ) && is_string( $r['target'] ) );
		return array_map( array( Rule::class, 'from_array' ), array_values( $rows ) );
	}

	/** Plugins whose work does not show on the page: never proposed or accepted; switched off by hand only with the owner's override. */
	public const NEVER_ACCEPT = array(
		'wp-fastest-cache'                  => 'page cache',
		'wp-optimize'                       => 'page cache',
		'beltoft-cache-support'             => 'page cache',
		'perfmatters'                       => 'page cache',
		'woocommerce-follow-up-emails'      => 'email/marketing tracking',
		'beltoft-followups'                 => 'email/marketing tracking',
		'api-mailer-for-aws-ses'            => 'email/marketing tracking',
		'daggerhart-openid-connect-generic' => 'security/SSO',
		'duracelltomi-google-tag-manager'   => 'analytics',
	);

	/** Directory name patterns of the same kinds of plugin, with the kind named in the refusal. */
	public const NEVER_ACCEPT_PATTERNS = array(
		'/cache/'                  => 'cache',
		'/security|firewall/'      => 'security',
		'/openid|(^|[-_])sso([-_]|$)/' => 'security/SSO', // a word "sso", not "expresso"
		'/mail|smtp/'              => 'email/marketing tracking',
		'/analytics|tag-manager|pixel/' => 'analytics',
	);

	/** Exact directories exempt from the list and its patterns (ruling: the object cache runs from the object-cache.php drop-in, not from the plugin). */
	public const NEVER_ACCEPT_EXCEPTIONS = array( 'redis-cache' );

	/** Pure: why a plugin may never lose a rule (proposed, accepted or by hand), or null. */
	public static function never_accept_reason( string $plugin ): ?string {
		if ( in_array( $plugin, self::NEVER_ACCEPT_EXCEPTIONS, true ) ) {
			return null;
		}
		$kind = self::NEVER_ACCEPT[ $plugin ] ?? null;
		if ( null === $kind ) {
			foreach ( self::NEVER_ACCEPT_PATTERNS as $re => $k ) {
				if ( preg_match( $re, strtolower( $plugin ) ) ) {
					$kind = $k;
					break;
				}
			}
		}
		/* translators: 1: plugin directory, 2: kind of plugin (page cache, security/SSO, email/marketing tracking, analytics) */
		return null === $kind ? null : sprintf( __( 'Never removed: %1$s is a %2$s plugin. What it does does not show on the page, so no check can prove it unused.', 'beltoft-performance' ), $plugin, $kind );
	}

	/** Pure: the never-accept refusal for a plugin-level target (unload:/plugin:), or null. */
	public static function never_accept_error( string $target ): ?string {
		$kind = (string) strstr( $target, ':', true );
		return in_array( $kind, array( 'unload', 'plugin' ), true ) ? self::never_accept_reason( (string) substr( $target, strlen( $kind ) + 1 ) ) : null;
	}

	/** Adds $r, merged into the existing rule of the same target and sites (one row per target and sites). @return true|string */
	public static function add( Rule $r ) {
		$error = self::never_accept_error( $r->target );
		if ( null !== $error ) {
			return $error;
		}
		$old = self::find( $r->target, $r->hosts );
		return $old ? self::save( self::merge( $old, $r ), $old->key() ) : self::save( $r, null );
	}

	/**
	 * Pure: $old with $new folded in: union of scopes (old order first) and of manual scopes, the earliest approval
	 * (an empty date never counts as earlier) and each reason part once.
	 */
	public static function merge( Rule $old, Rule $new ): Rule {
		$pages  = array_values( array_unique( array_merge( $old->pages, $new->pages ) ) );
		$manual = array_values( array_unique( array_merge( $old->manual_pages, $new->manual_pages ) ) );
		$newer  = '' !== $new->approved_at && ( '' === $old->approved_at || strcmp( $new->approved_at, $old->approved_at ) < 0 );
		$parts  = array_filter( array_map( 'trim', array_merge( explode( ' · ', $old->reason ), explode( ' · ', $new->reason ) ) ), 'strlen' );
		return new Rule( $old->target, $pages, $old->hosts, implode( ' · ', array_values( array_unique( $parts ) ) ), $newer ? $new->approved_by : $old->approved_by, $newer ? $new->approved_at : $old->approved_at, $manual, $old->owner_override || $new->owner_override );
	}

	/** Validates and writes $r in one update, replacing the row $old_key when given. @return true|string */
	private static function save( Rule $r, ?string $old_key ) {
		$manual = array_values( array_intersect( self::normalise_scopes( $r->manual_pages ), self::normalise_scopes( $r->pages ) ) );
		$pages  = self::normalise_scopes( $r->pages );
		// A script is accepted only when every scope was added by hand.
		$manual_ok = $pages && ! array_diff( $pages, $manual );
		$error     = ( $manual_ok ? null : self::accept_error( $r->target ) ) ?? self::validate( $r );
		if ( null !== $error ) {
			return $error;
		}
		$r    = new Rule( $r->target, $pages, $r->hosts, $r->reason, $r->approved_by, $r->approved_at, $manual, $r->owner_override );
		$rows = (array) get_option( self::OPTION, array() );
		if ( null !== $old_key ) {
			unset( $rows[ $old_key ] );
		}
		$rows[ $r->key() ] = $r->to_array();
		update_option( self::OPTION, $rows, false );
		return true;
	}

	/** Ruling: a script is never accepted as a rule — load/scroll evidence says nothing about clicks. (Trials of scripts still validate.) */
	public static function accept_error( string $target ): ?string {
		return 0 === strpos( $target, 'script:' ) ? __( 'Scripts can only be removed by hand: the load-time proof does not cover clicks.', 'beltoft-performance' ) : null;
	}

	/** Pure: the rule without these scopes (also dropped from manual_pages), or null when none is left. */
	public static function without_scopes( Rule $r, array $scopes ): ?Rule {
		$left = array_values( array_diff( $r->pages, $scopes ) );
		return $left ? new Rule( $r->target, $left, $r->hosts, $r->reason, $r->approved_by, $r->approved_at, array_values( array_diff( $r->manual_pages, $scopes ) ), $r->owner_override ) : null;
	}

	/**
	 * Pure: why a manual file rule is refused, or null. $inventory is [ page => [ state => report ] ] from the last check.
	 * The handle must be known and must not belong to this plugin itself (is_locked_*); shop core and theme files are the owner's call.
	 */
	public static function manual_file_error( string $kind, string $handle, array $inventory ): ?string {
		$found = false;
		foreach ( $inventory as $states ) {
			foreach ( (array) $states as $report ) {
				foreach ( (array) ( $report['assets'] ?? array() ) as $a ) {
					if ( ( $a['kind'] ?? '' ) !== $kind || ( $a['handle'] ?? '' ) !== $handle ) {
						continue;
					}
					$found = true;
					$owner = (string) ( $a['plugin'] ?? '' );
					if ( ( '' !== $owner && self::is_locked_plugin( $owner ) ) || self::is_locked_handle( $handle, (string) ( $a['src'] ?? '' ) ) ) {
						return __( 'This file belongs to the performance plugin itself and stays on.', 'beltoft-performance' );
					}
				}
			}
		}
		return $found ? null : __( 'Unknown file: load a page that prints it first.', 'beltoft-performance' );
	}

	/** @return string|null the error for a scope that does not normalise. */
	private static function scope_error( array $scopes ): ?string {
		foreach ( $scopes as $s ) {
			if ( ! is_string( $s ) || null === self::normalise_scope( $s ) ) {
				return ( is_string( $s ) && 0 === strpos( $s, 'url:' ) )
					? __( 'This URL is not a normal page (admin, REST, login, checkout or account endpoint).', 'beltoft-performance' )
					: __( 'Unknown page type.', 'beltoft-performance' );
			}
		}
		return $scopes ? null : __( 'Unknown page type.', 'beltoft-performance' );
	}

	/**
	 * Manual switch-off: merges into an existing retail rule of the same target, keeping its approver. $reason: '' = "Switched off by hand.".
	 * $override: kept for callers; a never-suggested plugin is always allowed by hand now and recorded as an owner override on the rule.
	 * @return true|string
	 */
	public static function switch_off( string $target, array $scopes, string $by, array $inventory, string $reason = '', bool $override = false ) {
		$never    = self::never_accept_error( $target );
		$override = null !== $never; // by hand the owner decides (like Perfmatters); the rule records that it was a never-suggested plugin
		$error    = self::scope_error( $scopes );
		if ( null !== $error ) {
			return $error;
		}
		$pages = self::normalise_scopes( $scopes );
		$kind  = (string) strstr( $target, ':', true );
		if ( in_array( $kind, array( 'script', 'style' ), true ) ) {
			$error = self::manual_file_error( $kind, (string) substr( $target, strlen( $kind ) + 1 ), $inventory );
			if ( null !== $error ) {
				return $error;
			}
		}
		$old = self::find( $target );
		if ( ! $old ) {
			$why = '' !== $reason ? $reason : ( $override ? __( 'Switched off by hand, protection overridden by the owner.', 'beltoft-performance' ) : __( 'Switched off by hand.', 'beltoft-performance' ) );
			return self::save( new Rule( $target, $pages, array( 'retail' ), $why, $by, gmdate( 'c' ), $pages, $override ), null );
		}
		$added = array_values( array_diff( $pages, $old->pages ) );
		if ( ! $added ) {
			return true; // nothing new: an existing rule is not re-flagged
		}
		$why = '' === $reason ? $old->reason : implode( ' · ', array_values( array_unique( array_filter( array( $old->reason, $reason ), 'strlen' ) ) ) );
		return self::save( new Rule( $target, array_merge( $old->pages, $added ), $old->hosts, $why, $old->approved_by, $old->approved_at, array_merge( $old->manual_pages, $added ), $old->owner_override || $override ), $old->key() );
	}

	/** The rule of $target on exactly these sites (order ignored), or null. */
	public static function find( string $target, array $hosts = array( 'retail' ) ): ?Rule {
		sort( $hosts );
		foreach ( self::all() as $e ) {
			$h = $e->hosts;
			sort( $h );
			if ( $e->target === $target && $hosts === $h ) {
				return $e;
			}
		}
		return null;
	}

	/** Manual switch-on: removes these scopes from the target's retail rule, in one write. On failure the old rule stays. @return true|string */
	public static function switch_on( string $target, array $scopes ) {
		$error = self::scope_error( $scopes );
		if ( null !== $error ) {
			return $error;
		}
		$scopes = self::normalise_scopes( $scopes );
		$e      = self::find( $target );
		if ( ! $e || ! array_intersect( $e->pages, $scopes ) ) {
			return __( 'No such rule.', 'beltoft-performance' );
		}
		$left = self::without_scopes( $e, $scopes );
		if ( ! $left ) {
			self::remove( $e->key() );
			return true;
		}
		return self::save( $left, $e->key() );
	}

	/** Drops these scopes from the stored rule $r (the whole row when none is left). @return true|string */
	public static function remove_scopes( Rule $r, array $scopes ) {
		$left = self::without_scopes( $r, $scopes );
		if ( ! $left ) {
			return self::remove( $r->key() ) ? true : __( 'No such rule.', 'beltoft-performance' );
		}
		return self::save( $left, $r->key() );
	}

	public static function remove( string $key ): bool {
		$rows = (array) get_option( self::OPTION, array() );
		if ( ! isset( $rows[ $key ] ) ) {
			return false;
		}
		unset( $rows[ $key ] );
		update_option( self::OPTION, $rows, false );
		return true;
	}

	public static function validate( Rule $r ): ?string {
		if ( ! in_array( $r->kind(), self::KINDS, true ) ) {
			return __( 'Unknown rule type.', 'beltoft-performance' );
		}
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_.\-]*$/i', $r->name() ) ) {
			return __( 'Invalid name.', 'beltoft-performance' );
		}
		if ( ! $r->pages ) {
			return __( 'Unknown page type.', 'beltoft-performance' );
		}
		foreach ( $r->pages as $scope ) {
			if ( ! is_string( $scope ) || null === self::normalise_scope( $scope ) ) {
				return ( is_string( $scope ) && 0 === strpos( $scope, 'url:' ) )
					? __( 'This URL is not a normal page (admin, REST, login, checkout or account endpoint).', 'beltoft-performance' )
					: __( 'Unknown page type.', 'beltoft-performance' );
			}
			if ( 'unload' === $r->tier() && 0 === strpos( $scope, 'template:' ) ) {
				return __( 'Plugins load before the template is known: use a page type or URL.', 'beltoft-performance' );
			}
		}
		if ( ! $r->hosts || array_diff( $r->hosts, self::HOSTS ) ) {
			return __( 'Unknown site.', 'beltoft-performance' );
		}
		if ( 'unload' === $r->tier() && array_filter( $r->pages, static fn( $p ) => ! in_array( $p, self::URL_PAGE_TYPES, true ) && 0 !== strpos( (string) $p, 'url:' ) ) ) {
			return __( 'This page type cannot be told apart by URL: use a files rule.', 'beltoft-performance' );
		}
		// Buying needs WooCommerce and jQuery: never off on cart, checkout, order received, account or a
		// template (which may be the checkout's), whatever is ticked.
		$buying = array_filter( $r->pages, static fn( $p ) => in_array( $p, array( 'cart', 'checkout', 'order_received', 'account', 'checkout_endpoint', 'account_endpoint' ), true ) || 0 === strpos( (string) $p, 'template:' ) );
		if ( $buying && ( ( in_array( $r->kind(), array( 'unload', 'plugin' ), true ) && 'woocommerce' === $r->name() ) || ( in_array( $r->kind(), array( 'script' ), true ) && in_array( $r->name(), array( 'jquery', 'jquery-core', 'wc-checkout', 'wc-cart' ), true ) ) ) ) {
			return __( 'WooCommerce and jQuery stay on where people buy (cart, checkout, order received, account, templates).', 'beltoft-performance' );
		}
		if ( in_array( $r->kind(), array( 'unload', 'plugin' ), true ) && self::is_locked_plugin( $r->name() ) ) {
			return __( 'The performance plugin itself stays on: switching it off would switch off every rule with it.', 'beltoft-performance' );
		}
		if ( in_array( $r->kind(), array( 'script', 'style' ), true ) && self::is_locked_handle( $r->name(), '' ) ) {
			return __( 'This file belongs to the performance plugin itself and stays on.', 'beltoft-performance' );
		}
		return null;
	}

	/**
	 * Pure given $routes: the canonical scope (page type, "url:/path/" or "template:<id>"), or null when invalid.
	 * A URL must classify, with the real routes and without the WP-CLI/cron context, as a page type a rule may name.
	 */
	public static function normalise_scope( string $scope, ?array $routes = null ): ?string {
		if ( in_array( $scope, self::PAGE_TYPES, true ) ) {
			return $scope;
		}
		if ( 0 === strpos( $scope, 'template:' ) ) {
			$id = substr( $scope, 9 );
			return ( ctype_digit( $id ) && (int) $id > 0 ) ? 'template:' . (int) $id : null;
		}
		if ( 0 === strpos( $scope, 'url:' ) ) {
			$raw = trim( substr( $scope, 4 ) );
			if ( preg_match( '#^https?://([^/?\#]*)(.*)$#i', $raw, $m ) ) {
				$home = \BeltoftPerformance\Request\RouteMap::retail_host(); // canonical: home_url() is the request host on the B2B host
				if ( '' === $home || strtolower( $m[1] ) !== $home ) {
					return null;
				}
				$raw = $m[2];
			}
			if ( '' === $raw ) {
				return null;
			}
			if ( null === $routes ) {
				try {
					$routes = class_exists( '\\BeltoftPerformance\\Request\\RouteMap' ) ? \BeltoftPerformance\Request\RouteMap::build() : array();
				} catch ( \Throwable $e ) {
					$routes = array();
				}
			}
			$path = \BeltoftPerformance\Request\Classifier::request_path( $raw );
			$req  = \BeltoftPerformance\Request\Classifier::classify( $path, array( 'REQUEST_METHOD' => 'GET' ), $routes, true );
			return ( 'page' === $req['type'] && in_array( $req['page'], self::PAGE_TYPES, true ) ) ? 'url:' . $path : null;
		}
		return null;
	}

	/** @return string[] */
	private static function normalise_scopes( array $pages ): array {
		$out = array();
		foreach ( $pages as $p ) {
			$n = is_string( $p ) ? self::normalise_scope( $p ) : null;
			if ( null !== $n && ! in_array( $n, $out, true ) ) {
				$out[] = $n;
			}
		}
		return $out;
	}

	/**
	 * Locked for everyone, by hand too: this plugin itself (switching it off would take the loader and every rule with it, with no
	 * way back from its own screen). Everything else can be switched off by hand, like Perfmatters; is_protected_*() below only
	 * keeps the shop core out of the suggestions.
	 */
	public static function is_locked_plugin( string $dir ): bool {
		return 'beltoft-performance' === $dir;
	}

	public static function is_locked_handle( string $handle, string $src ): bool {
		return str_starts_with( $handle, 'beltoft-performance' ) || false !== strpos( $src, '/plugins/beltoft-performance/' );
	}

	/** Never suggested (shop core: WooCommerce, the beltoft-* plugins); switched off by hand only. */
	public static function is_protected_plugin( string $dir ): bool {
		return in_array( $dir, array( 'woocommerce', 'beltoft-performance' ), true ) || str_starts_with( $dir, 'beltoft-' );
	}

	public static function is_protected_handle( string $handle, string $src ): bool {
		if ( in_array( $handle, array( 'jquery', 'jquery-core' ), true ) || str_starts_with( $handle, 'beltoft-' ) || str_starts_with( $handle, 'bricks-' ) ) {
			return true;
		}
		return (bool) preg_match( '#/wp-content/themes/bricks(-child)?/#', $src );
	}
}
