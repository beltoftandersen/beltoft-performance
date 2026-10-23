<?php
/**
 * Plugin Name: Beltoft Performance loader
 * Description: Installed and managed by the Beltoft Performance plugin. Do not edit: removes plugins per page type before they load.
 * Version:     0.1.4
 */
// Copied into wp-content/mu-plugins by BeltoftPerformance\Loader\LoaderManager. Self-contained: no plugin code,
// no database, only the compiled rule file. Any doubt → change nothing.

if ( ! function_exists( 'beltoft_performance_special_context' ) ) {
	/** True for AJAX, admin, XML-RPC, REST and cron requests (constants set by their entry scripts). $flags overrides the constants (tests). */
	function beltoft_performance_special_context( $flags = null ) {
		if ( null === $flags ) {
			$flags = array();
			foreach ( array( 'DOING_AJAX', 'WP_ADMIN', 'XMLRPC_REQUEST', 'REST_REQUEST', 'DOING_CRON' ) as $c ) {
				$flags[ $c ] = defined( $c ) && constant( $c );
			}
		}
		foreach ( (array) $flags as $on ) {
			if ( $on ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'beltoft_performance_merge_removed' ) ) {
	/** Pure: puts back every plugin this request unloaded that is still in the unfiltered list, at its original place, without duplicates. */
	function beltoft_performance_merge_removed( $new, $unfiltered, $removed ) {
		if ( ! is_array( $new ) ) {
			return $new;
		}
		$out        = array_values( array_unique( $new, SORT_REGULAR ) );
		$unfiltered = array_values( (array) $unfiltered );
		foreach ( $unfiltered as $i => $p ) {
			if ( ! in_array( $p, (array) $removed, true ) || in_array( $p, $out, true ) ) {
				continue;
			}
			$pos = 0;
			for ( $j = $i - 1; $j >= 0; $j-- ) {
				$k = array_search( $unfiltered[ $j ], $out, true );
				if ( false !== $k ) {
					$pos = $k + 1;
					break;
				}
			}
			array_splice( $out, $pos, 0, array( $p ) );
		}
		return $out;
	}
}

if ( ! function_exists( 'beltoft_performance_remember' ) ) {
	/** Records what this request unloaded (for beltoft_performance_keep_removed) and returns the filtered list. */
	function beltoft_performance_remember( $unfiltered, $filtered ) {
		$GLOBALS['beltoft_performance_removed'] = array(
			'unfiltered' => $unfiltered,
			'removed'    => array_values( array_diff( $unfiltered, $filtered ) ),
		);
		return $filtered;
	}
}

if ( ! function_exists( 'beltoft_performance_keep_removed' ) ) {
	/** pre_update_option_active_plugins: a front-end write (e.g. deactivate_plugins) must never persist the per-request filtered list. */
	function beltoft_performance_keep_removed( $value ) {
		$state = $GLOBALS['beltoft_performance_removed'] ?? null;
		if ( ! is_array( $state ) || empty( $state['removed'] ) ) {
			return $value;
		}
		return beltoft_performance_merge_removed( $value, (array) $state['unfiltered'], (array) $state['removed'] );
	}
}

if ( ! function_exists( 'beltoft_performance_keep_rewrite_rules' ) ) {
	/** pre_update_option_rewrite_rules: rules rebuilt on a request that unloaded plugins miss their rewrites — keep the stored ones. */
	function beltoft_performance_keep_rewrite_rules( $value, $old = null ) {
		$state = $GLOBALS['beltoft_performance_removed'] ?? null;
		if ( ! is_array( $state ) || empty( $state['removed'] ) ) {
			return $value;
		}
		return $old;
	}
}

if ( ! function_exists( 'beltoft_performance_rules_path' ) ) {
	/** The compiled rule file: the uploads base (ABSPATH . UPLOADS when that constant is set, else WP_CONTENT_DIR/uploads), as BeltoftPerformance\Rules\Compiler::path_for(). */
	function beltoft_performance_rules_path( $content_dir, $abspath, $uploads = null ) {
		$base = ( null !== $uploads && '' !== trim( (string) $uploads, '/' ) ) ? rtrim( (string) $abspath, '/' ) . '/' . trim( (string) $uploads, '/' ) : rtrim( (string) $content_dir, '/' ) . '/uploads';
		return $base . '/beltoft-performance/rules.php';
	}
}

if ( ! function_exists( 'beltoft_performance_filter_plugins' ) ) {
	function beltoft_performance_filter_plugins( $plugins, $rules_file = null, $uri = null, $server = null, $flags = null ) {
		if ( ! is_array( $plugins ) || ( defined( 'BELTOFT_PERFORMANCE_DISABLED' ) && BELTOFT_PERFORMANCE_DISABLED ) || beltoft_performance_special_context( $flags ) ) {
			return $plugins;
		}
		// The owning plugin must be active: a loader left behind never acts on its own.
		if ( ! in_array( 'beltoft-performance/beltoft-performance.php', $plugins, true ) ) {
			return $plugins;
		}
		$rules_file = $rules_file ?? ( defined( 'WP_CONTENT_DIR' ) && defined( 'ABSPATH' ) ? beltoft_performance_rules_path( WP_CONTENT_DIR, ABSPATH, defined( 'UPLOADS' ) ? UPLOADS : null ) : '' );
		if ( ! $rules_file || ! is_readable( $rules_file ) ) {
			return $plugins;
		}
		// option_active_plugins fires many times per request: memoize on the inputs plus the rule file's stat.
		static $cache = array();
		$server = $server ?? $_SERVER;
		$uri    = $uri ?? (string) ( $server['REQUEST_URI'] ?? '/' );
		clearstatcache( true, $rules_file );
		$key = md5( serialize( array( $plugins, $rules_file, $uri, $server['HTTP_HOST'] ?? '', $server['HTTP_X_BP_CHECK'] ?? '', $server['SCRIPT_NAME'] ?? '', $server['REQUEST_METHOD'] ?? '', @filemtime( $rules_file ), @filesize( $rules_file ) ) ) );
		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ] === $plugins ? $plugins : beltoft_performance_remember( $plugins, $cache[ $key ] );
		}
		$cache[ $key ] = $plugins;
		try {
			$data = include $rules_file;
			if ( ! is_array( $data ) || 1 !== ( $data['v'] ?? 0 ) || ! is_array( $data['unload'] ?? array() ) ) {
				return $plugins;
			}
			// A rule-less file is only worth reading further when a check secret could verify a trial header.
			$urls = is_array( $data['unload_urls'] ?? null ) ? $data['unload_urls'] : array();
			if ( empty( $data['unload'] ) && empty( $urls ) && empty( $data['check_secret'] ) ) {
				return $plugins;
			}
			if ( ! class_exists( 'BeltoftPerformance\\Request\\Classifier' ) ) {
				$classifier = WP_PLUGIN_DIR . '/beltoft-performance/src/Request/Classifier.php';
				if ( ! is_readable( $classifier ) ) {
					return $plugins;
				}
				require_once $classifier;
			}
			$req = \BeltoftPerformance\Request\Classifier::classify( $uri, $server, (array) ( $data['routes'] ?? array() ) );
			// Query-string pages and checkout/account endpoints keep every plugin, even under a url: rule for their path.
			if ( 'page' !== $req['type'] || in_array( $req['page'], \BeltoftPerformance\Request\Classifier::KEEP_ALL_PAGES, true ) ) {
				return $plugins;
			}
			// Trial: a signed X-BP-Check header (verified against the secret in the rule file) can add unload targets for this request only.
			$trial = array();
			$hdr   = (string) ( $server['HTTP_X_BP_CHECK'] ?? '' );
			if ( '' !== $hdr && ! empty( $data['check_secret'] ) && false !== strpos( $uri, 'bp_check=' ) ) {
				$p = explode( '.', $hdr, 3 );
				if ( 3 === count( $p ) && ctype_digit( $p[0] ) && abs( time() - (int) $p[0] ) <= 120 ) {
					$json = base64_decode( strtr( $p[2], '-_', '+/' ), true );
					if ( false !== $json && hash_equals( hash_hmac( 'sha256', $p[0] . '.' . $json, (string) $data['check_secret'] ), $p[1] ) ) {
						$payload = json_decode( $json, true );
						// The header is signed for one path: replayed against another URL it does nothing.
						if ( ! is_array( $payload ) || ! isset( $payload['path'] ) || \BeltoftPerformance\Request\Classifier::request_path( $uri ) !== $payload['path'] ) {
							$payload = array();
						}
						foreach ( (array) ( $payload['trial'] ?? array() ) as $r ) {
							if ( is_array( $r ) && in_array( $req['host'], (array) ( $r['hosts'] ?? array() ), true ) && 0 === strpos( (string) ( $r['target'] ?? '' ), 'unload:' ) && in_array( $req['page'], (array) ( $r['pages'] ?? array() ), true ) ) {
								$trial[] = substr( $r['target'], 7 );
							}
						}
					}
				}
			}
			$by_url = $urls[ $req['host'] ][ \BeltoftPerformance\Request\Classifier::request_path( $uri ) ] ?? array();
			$drop   = array_merge( (array) ( $data['unload'][ $req['host'] ][ $req['page'] ] ?? array() ), is_array( $by_url ) ? $by_url : array(), $trial );
			// Defense in depth: core commerce and our own plugins are never unloaded.
			$drop = array_values( array_filter( $drop, static function ( $d ) {
				return is_string( $d ) && 'woocommerce' !== $d && 0 !== strpos( $d, 'beltoft-' );
			} ) );
			if ( ! $drop ) {
				return $plugins;
			}
			$cache[ $key ] = array_values( array_filter( $plugins, static function ( $p ) use ( $drop ) {
				return ! in_array( strtok( (string) $p, '/' ), $drop, true );
			} ) );
		} catch ( \Throwable $e ) {
			return $plugins;
		}
		return beltoft_performance_remember( $plugins, $cache[ $key ] );
	}
}

if ( function_exists( 'add_filter' ) && ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	add_filter( 'option_active_plugins', 'beltoft_performance_filter_plugins', 1 );
	add_filter( 'pre_update_option_active_plugins', 'beltoft_performance_keep_removed', 1 );
	add_filter( 'pre_update_option_rewrite_rules', 'beltoft_performance_keep_rewrite_rules', 1, 2 );
}
