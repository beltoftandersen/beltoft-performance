<?php
namespace BeltoftPerformance\Check;

use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

final class Runner {
	public static function dir( string $id ): string {
		return wp_upload_dir()['basedir'] . '/beltoft-performance/checks/' . $id;
	}

	public static function new_id(): string {
		return gmdate( 'Ymd-His' ) . '-' . substr( bin2hex( random_bytes( 3 ) ), 0, 6 );
	}

	public static function targets(): array {
		$saved = (array) get_option( 'beltoft_performance_targets', array() );
		$q     = static fn( $args ) => get_posts( $args + array( 'fields' => 'ids', 'numberposts' => 1, 'post_status' => 'publish' ) )[0] ?? 0;
		$term  = static function ( $tax ) { $t = get_terms( array( 'taxonomy' => $tax, 'orderby' => 'count', 'order' => 'DESC', 'number' => 1, 'hide_empty' => true ) ); return ( $t && ! is_wp_error( $t ) ) ? get_term_link( $t[0] ) : ''; };
		$best_simple = $q( array( 'post_type' => 'product', 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'simple' ) ) ) );
		$defaults = array_filter( array(
			'front'         => home_url( '/' ),
			'shop'          => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '',
			'archive_cat'   => $term( 'product_cat' ),
			'archive_brand' => taxonomy_exists( 'product_brand' ) ? $term( 'product_brand' ) : '',
			'archive_tag'   => $term( 'product_tag' ),
			'product'       => $best_simple ? get_permalink( $best_simple ) : '',
			'search'        => home_url( '/?s=escova' ),
			'post'          => ( $p = $q( array( 'post_type' => 'post' ) ) ) ? get_permalink( $p ) : '',
			'blog'          => get_option( 'page_for_posts' ) ? get_permalink( (int) get_option( 'page_for_posts' ) ) : '',
			'checkout'      => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : '',
			'account'       => function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : '',
			'page'          => ( $pg = $q( array( 'post_type' => 'page', 'post__not_in' => array_filter( array( (int) get_option( 'page_on_front' ), (int) wc_get_page_id( 'shop' ), (int) wc_get_page_id( 'cart' ), (int) wc_get_page_id( 'checkout' ), (int) wc_get_page_id( 'myaccount' ) ) ) ) ) ) ? get_permalink( $pg ) : '',
		) );
		return array_merge( $defaults, array_intersect_key( $saved, $defaults ) );
	}

	public static function fetch( string $url, array $payload, array $cookies = array() ) {
		$url = add_query_arg( 'bp_check', $payload['id'], $url ); // a query string: the nginx page cache never answers it
		return wp_remote_get( $url, array(
			'timeout'     => 30,
			'redirection' => 0,
			'headers'     => array( Signature::HEADER => Signature::header_for( $payload, $url ), 'User-Agent' => 'Mozilla/5.0 (X11; Linux x86_64) BeltoftPerformanceCheck/1.0' ),
			'cookies'     => $cookies,
		) );
	}

	/** Best-selling in-stock simple product (what the cart state adds) with room for one more: the browser flow adds it again on top of the cart state's unit. */
	public static function flow_product_id(): int {
		$stock = array( 'relation' => 'OR', array( 'key' => '_manage_stock', 'value' => 'no' ), array( 'key' => '_stock', 'value' => 3, 'compare' => '>=', 'type' => 'NUMERIC' ) );
		return (int) ( get_posts( array( 'post_type' => 'product', 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'meta_query' => array( array( 'key' => '_stock_status', 'value' => 'instock' ), $stock ), 'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => 'simple' ) ) ) )[0] ?? 0 );
	}

	/** Permalink of the flow product, for the browser checkout flow. */
	public static function flow_product_url(): string {
		$id = self::flow_product_id();
		return $id ? (string) get_permalink( $id ) : '';
	}

	public static function cart_cookies(): array {
		$product = self::flow_product_id();
		$r = wp_remote_get( add_query_arg( array( 'add-to-cart' => $product, 'bp_check' => 'cart' ), home_url( '/' ) ), array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 BeltoftPerformanceCheck/1.0' ) ) );
		if ( is_wp_error( $r ) ) {
			return array();
		}
		$cookies = (array) wp_remote_retrieve_cookies( $r );
		// The "added to your cart" notice waits in the session for the first page that prints notices: consume it here,
		// or that page's cart baseline differs from every later fetch (and every trial of it fails).
		$shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/' );
		wp_remote_get( add_query_arg( 'bp_check', 'cart', $shop ), array( 'timeout' => 30, 'redirection' => 0, 'headers' => array( 'User-Agent' => 'Mozilla/5.0 BeltoftPerformanceCheck/1.0' ), 'cookies' => $cookies ) );
		return $cookies;
	}

	public const PROGRESS = 'beltoft_performance_check_progress';

	/**
	 * Pure: the page loads a check makes, as [page, state, url, target-or-''] - the same order run() fetches them.
	 * Checkout is skipped for guests (an empty checkout redirects by design); product samples ride on 'product'.
	 */
	public static function plan( array $targets, array $samples, array $pages, array $states ): array {
		$out = array();
		foreach ( $targets as $page => $url ) {
			if ( $pages && ! in_array( $page, $pages, true ) ) {
				continue;
			}
			foreach ( $states as $state ) {
				if ( 'checkout' === $page && 'guest' === $state ) {
					continue;
				}
				$out[] = array( $page, $state, $url, '' );
			}
		}
		if ( ! $pages || in_array( 'product', $pages, true ) ) {
			foreach ( $samples as $type => $url ) {
				foreach ( $states as $state ) {
					$out[] = array( self::sample_target( $type ), $state, $url, self::sample_target( $type ) );
				}
			}
		}
		return $out;
	}

	/**
	 * Pure: the fetched rows of a partial check merged into the previous check's: each (page, state) that was loaded
	 * again is replaced, every other row keeps its earlier result (order: earlier rows first, then the new ones).
	 */
	public static function merge_fetched( array $old, array $new ): array {
		$key  = static fn( $r ) => (string) ( $r['page'] ?? '' ) . '|' . (string) ( $r['state'] ?? '' );
		$seen = array_flip( array_map( $key, $new ) );
		return array_merge( array_values( array_filter( $old, static fn( $r ) => ! isset( $seen[ $key( $r ) ] ) ) ), $new );
	}

	public const LOCK = 'beltoft_performance_check';

	/**
	 * Pure: the trials of check $id that a re-check of $pages makes stale (their evidence came from the old reports),
	 * as the target keys to drop. Product samples (product-*) count as 'product'.
	 */
	public static function stale_trials( array $trials, string $id, array $pages ): array {
		$norm = static fn( $p ) => str_starts_with( (string) $p, 'product-' ) ? 'product' : (string) $p;
		$hit  = array_map( $norm, $pages );
		return array_values( array_map( 'strval', array_keys( array_filter( $trials, static fn( $t ) => is_array( $t ) && ( $t['check'] ?? '' ) === $id && array_intersect( array_map( $norm, (array) ( $t['pages'] ?? array() ) ), $hit ) ) ) ) );
	}

	/**
	 * Runs a check. $pages empty = every kind of page under a new id; $merge = re-check only $pages inside the last
	 * check ($id is then that check's id): its other pages, suggestions and tests keep their results, the re-checked
	 * pages' tests are dropped (their evidence is old). One check at a time (MySQL lock): a second one does nothing.
	 */
	public static function run( string $id, array $pages = array(), array $states = array( 'guest', 'cart', 'logged_in' ), bool $merge = false ): array {
		global $wpdb;
		if ( ! (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK, 2 ) ) ) {
			error_log( 'beltoft-performance: a check is already running; check ' . $id . ' was not started' );
			return array();
		}
		try {
			return self::run_locked( $id, $pages, $states, $merge );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK ) );
		}
	}

	private static function run_locked( string $id, array $pages, array $states, bool $merge ): array {
		$last  = (array) get_option( 'beltoft_performance_last_check', array() );
		$asked = $merge;
		$merge = $merge && ( $last['id'] ?? '' ) === $id;
		if ( $asked && ! $merge ) {
			error_log( 'beltoft-performance: re-check of ' . implode( ',', $pages ) . ' skipped: check ' . $id . ' is no longer the last check' );
			delete_option( self::PROGRESS );
			return array();
		}
		$done = array();
		$key  = 'beltoft_performance_cart_cookies_' . $id;
		$jar  = $merge ? (array) get_option( $key, array() ) : array(); // a re-check reuses the check's cart session: its trials' baselines use it
		if ( ! $jar && in_array( 'cart', $states, true ) ) {
			foreach ( self::cart_cookies() as $ck ) {
				$jar[ $ck->name ] = $ck->value;
			}
			if ( $jar || ! $merge ) {
				update_option( $key, $jar, false ); // Trial reuses this session: a fresh one would change the nonces.
			}
		} elseif ( ! $merge ) {
			update_option( $key, $jar, false );
		}
		$plan    = self::plan( self::targets(), self::product_samples(), $pages, $states );
		$total   = count( $plan );
		$started = time();
		$beat    = static fn( int $n, string $page = '' ) => update_option( self::PROGRESS, array( 'id' => $id, 'done' => $n, 'total' => $total, 'pages' => $pages, 'started' => $started, 'updated' => time(), 'finished' => 0, 'page' => $page ), false );
		$beat( 0 );
		foreach ( $plan as $i => [ $page, $state, $url, $target ] ) {
			$job = array( 'id' => $id, 'state' => $state );
			if ( '' !== $target ) {
				$job['target'] = $target;
			}
			$r      = self::fetch( $url, $job, 'cart' === $state ? $jar : array() );
			$done[] = array( 'page' => $page, 'state' => $state, 'code' => is_wp_error( $r ) ? 0 : wp_remote_retrieve_response_code( $r ) );
			$beat( $i + 1, $page );
			usleep( 300000 );
		}
		if ( $merge ) {
			// The re-checked pages' tests and browser coverage describe the old reports: drop them.
			$trials = (array) get_option( 'beltoft_performance_trials', array() );
			$stale  = self::stale_trials( $trials, $id, $pages );
			if ( $stale ) {
				update_option( 'beltoft_performance_trials', array_diff_key( $trials, array_flip( $stale ) ), false );
			}
			foreach ( array_unique( array_column( $done, 'page' ) ) as $p ) {
				foreach ( (array) glob( self::dir( $id ) . '/usage-' . $p . '-*.json' ) as $f ) {
					if ( is_file( $f ) ) {
						unlink( $f );
					}
				}
			}
			TestQueue::cancel();
			$last = (array) get_option( 'beltoft_performance_last_check', array() ); // fresh: nothing else writes it while we hold the lock
			$rechecked = (array) ( $last['rechecked'] ?? array() );
			foreach ( array_unique( array_column( $done, 'page' ) ) as $p ) {
				$rechecked[ $p ] = time();
			}
			update_option( 'beltoft_performance_last_check', array( 'id' => $id, 'time' => (int) ( $last['time'] ?? time() ), 'fetched' => self::merge_fetched( (array) ( $last['fetched'] ?? array() ), $done ), 'rechecked' => $rechecked ), false );
		} else {
			update_option( 'beltoft_performance_last_check', array( 'id' => $id, 'time' => time(), 'fetched' => $done ), false );
		}
		update_option( self::PROGRESS, array( 'id' => $id, 'done' => $total, 'total' => $total, 'pages' => $pages, 'started' => $started, 'updated' => time(), 'finished' => time() ), false );
		self::prune_checks();
		return $done;
	}

	/** The progress line, e.g. "Checking the shop: 12 of 44 page loads". $what: the shop / names of page types. */
	public static function progress_text( array $v, string $what ): string {
		if ( $v['queued'] ) {
			/* translators: %s: what is checked (the shop, or kinds of page) */
			return sprintf( __( 'Checking %s: starting…', 'beltoft-performance' ), $what );
		}
		/* translators: 1: what is checked (the shop, or kinds of page), 2: page loads done, 3: page loads in total */
		return sprintf( _n( 'Checking %1$s: %2$d of %3$d page load', 'Checking %1$s: %2$d of %3$d page loads', (int) $v['total'], 'beltoft-performance' ), $what, (int) $v['done'], (int) $v['total'] );
	}

	/**
	 * Pure: progress for the status card, or null when nothing runs: finished, or no page load for 3 minutes
	 * (the run died; the buttons come back). A queued run that has not started counts from when it was queued.
	 */
	public static function progress_view( array $p, int $now ): ?array {
		$beat = (int) ( $p['updated'] ?? ( $p['started'] ?? 0 ) );
		if ( ! $p || ! empty( $p['finished'] ) || $now - $beat > 180 ) {
			return null;
		}
		$total = max( 0, (int) ( $p['total'] ?? 0 ) );
		$done  = min( $total, max( 0, (int) ( $p['done'] ?? 0 ) ) );
		return array( 'done' => $done, 'total' => $total, 'queued' => 0 === $total, 'pages' => array_values( (array) ( $p['pages'] ?? array() ) ) );
	}

	/** Bricks template types that render a single post/page/product (the Manager's template select). */
	public const CONTENT_TEMPLATE_TYPES = array( 'content', 'wc_product' );

	/**
	 * The most recent published post/page/product that Bricks renders with content template $template_id, or 0.
	 * Candidates come from the template's own conditions (specific ids first, then the post types it names, else
	 * post/page/product); each is confirmed with Bricks' own resolver, \Bricks\Database::find_template_id(), the
	 * same call Bricks makes on a singular front-end request. Posts with their own Bricks content are skipped.
	 */
	public static function template_post( int $template_id ): int {
		if ( $template_id <= 0 || ! class_exists( '\\Bricks\\Database' ) || ! class_exists( '\\Bricks\\Helpers' ) || ! method_exists( '\\Bricks\\Database', 'find_template_id' ) ) {
			return 0;
		}
		$conditions = (array) \Bricks\Helpers::get_template_setting( 'templateConditions', $template_id );
		$ids        = array();
		$types      = array();
		$terms      = array();
		foreach ( $conditions as $c ) {
			if ( ! is_array( $c ) || isset( $c['exclude'] ) ) {
				continue;
			}
			if ( 'ids' === ( $c['main'] ?? '' ) ) {
				$ids = array_merge( $ids, array_map( 'intval', (array) ( $c['ids'] ?? array() ) ) );
			}
			if ( 'terms' === ( $c['main'] ?? '' ) ) {
				foreach ( (array) ( $c['terms'] ?? array() ) as $t ) {
					$pair = explode( '::', (string) $t, 2 );
					if ( 2 === count( $pair ) && taxonomy_exists( $pair[0] ) && ctype_digit( $pair[1] ) ) {
						$terms[] = array( 'taxonomy' => $pair[0], 'field' => 'term_id', 'terms' => array( (int) $pair[1] ) );
					}
				}
			}
			if ( 'postType' === ( $c['main'] ?? '' ) ) {
				$types = array_merge( $types, array_map( 'strval', (array) ( $c['postType'] ?? array() ) ) );
			}
		}
		$types = array_values( array_intersect( $types ? $types : array( 'post', 'page', 'product' ), get_post_types( array( 'public' => true ) ) ) );
		$cands = $ids ? get_posts( array( 'post__in' => $ids, 'post_type' => 'any', 'post_status' => 'publish', 'fields' => 'ids', 'numberposts' => 20, 'orderby' => 'date', 'order' => 'DESC' ) ) : array();
		if ( $terms ) { // term conditions: look among posts in those terms, not only the newest overall
			$cands = array_merge( $cands, get_posts( array( 'post_type' => 'any', 'post_status' => 'publish', 'has_password' => false, 'fields' => 'ids', 'numberposts' => 30, 'orderby' => 'date', 'order' => 'DESC', 'tax_query' => array_merge( array( 'relation' => 'OR' ), $terms ) ) ) );
		}
		if ( $types ) {
			$cands = array_merge( $cands, get_posts( array( 'post_type' => $types, 'post_status' => 'publish', 'has_password' => false, 'fields' => 'ids', 'numberposts' => 30, 'orderby' => 'date', 'order' => 'DESC' ) ) );
		}
		$all = \Bricks\Database::get_all_templates_by_type();
		foreach ( array_unique( array_map( 'intval', $cands ) ) as $pid ) {
			if ( get_post_meta( $pid, '_bricks_page_content_2', true ) ) {
				continue;
			}
			if ( (int) \Bricks\Database::find_template_id( $all, 'content', 'content', $pid, '' ) === $template_id ) {
				return $pid;
			}
		}
		return 0;
	}

	/** Keeps the 3 newest manual-<Ymd> report dirs (plus today's, about to be written) and forgets loads that pointed into the removed ones. */
	public static function prune_manual_dirs(): void {
		$base  = dirname( self::dir( 'x' ) );
		$names = array_map( 'basename', (array) glob( $base . '/manual-*', GLOB_ONLYDIR ) );
		$drop  = \BeltoftPerformance\Admin\Manager::dirs_to_prune( array_unique( array_merge( $names, array( 'manual-' . gmdate( 'Ymd' ) ) ) ) );
		foreach ( $drop as $name ) {
			foreach ( (array) glob( $base . '/' . $name . '/*' ) as $f ) {
				if ( is_file( $f ) ) {
					unlink( $f );
				}
			}
			@rmdir( $base . '/' . $name );
		}
		if ( $drop ) {
			$loads = (array) get_option( \BeltoftPerformance\Admin\Manager::LOADS_OPTION, array() );
			$keep  = array_filter( $loads, static fn( $l ) => is_array( $l ) && ! in_array( (string) ( $l['id'] ?? '' ), $drop, true ) );
			if ( count( $keep ) !== count( $loads ) ) {
				update_option( \BeltoftPerformance\Admin\Manager::LOADS_OPTION, $keep, false );
			}
		}
	}

	/** The URL a scope is loaded from, or WP_Error. */
	public static function scope_url( string $scope ) {
		if ( in_array( $scope, Store::PAGE_TYPES, true ) ) {
			$url = (string) ( self::targets()[ $scope ] ?? '' );
			return '' !== $url ? $url : new \WP_Error( 'bp_no_page', __( 'No test page for this type.', 'beltoft-performance' ) );
		}
		if ( 0 === strpos( $scope, 'url:' ) ) {
			return home_url( \BeltoftPerformance\Admin\Manager::encode_path( substr( $scope, 4 ) ) ); // the scope holds the decoded path
		}
		$pid = self::template_post( (int) substr( $scope, 9 ) );
		return $pid ? (string) get_permalink( $pid ) : new \WP_Error( 'bp_no_post', __( 'No page uses this template yet (checked its own page ids and terms and the 30 newest published posts of each matching type).', 'beltoft-performance' ) );
	}

	/**
	 * Manager "Load": fetches the scope's URL as a guest with a signed header under check id manual-<Ymd>, keeps the
	 * report as scope-<hash>.json (several scopes of one page type share the Recorder's file name) and stores
	 * beltoft_performance_manual_loads[scope] = { id, page, file, time, url, files }. A redirect, error or missing report stores nothing.
	 * @return array|\WP_Error
	 */
	public static function load_scope( string $scope ) {
		$scope = Store::normalise_scope( $scope );
		if ( null === $scope ) {
			return new \WP_Error( 'bp_scope', __( 'Unknown page type, URL or template.', 'beltoft-performance' ) );
		}
		$url = self::scope_url( $scope );
		if ( is_wp_error( $url ) ) {
			return $url;
		}
		self::prune_manual_dirs();
		$id    = 'manual-' . gmdate( 'Ymd' );
		$dir   = self::dir( $id );
		$start = time();
		$r     = self::fetch( $url, array( 'id' => $id, 'state' => 'guest' ) );
		$code  = is_wp_error( $r ) ? 0 : (int) wp_remote_retrieve_response_code( $r );
		$path  = \BeltoftPerformance\Request\Classifier::request_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$found = null;
		$name  = '';
		clearstatcache();
		foreach ( (array) glob( $dir . '/*-guest.json' ) as $f ) {
			if ( filemtime( $f ) < $start || 0 === strpos( basename( $f ), 'scope-' ) ) {
				continue;
			}
			$rep = json_decode( (string) file_get_contents( $f ), true );
			if ( is_array( $rep ) && empty( $rep['trial'] ) && \BeltoftPerformance\Request\Classifier::request_path( (string) ( $rep['url'] ?? '' ) ) === $path ) {
				$found = $rep;
				$name  = basename( $f );
			}
		}
		$error = \BeltoftPerformance\Admin\Manager::load_outcome( $code, $found );
		if ( null === $error && 0 === strpos( $scope, 'template:' ) && isset( $found['templates'] ) && ! in_array( (int) substr( $scope, 9 ), array_map( 'intval', (array) $found['templates'] ), true ) ) {
			$error = __( 'The page did not render with this template: nothing was stored.', 'beltoft-performance' );
		}
		if ( null !== $error ) {
			return new \WP_Error( 'bp_load', $error );
		}
		$file = 'scope-' . substr( md5( $scope ), 0, 12 ) . '-guest.json';
		if ( ! copy( $dir . '/' . $name, $dir . '/' . $file ) ) {
			return new \WP_Error( 'bp_load', __( 'Could not store the report.', 'beltoft-performance' ) );
		}
		$load            = array( 'id' => $id, 'page' => (string) ( $found['page'] ?? '' ), 'file' => $file, 'time' => time(), 'url' => $url, 'files' => count( (array) ( $found['assets'] ?? array() ) ) );
		$loads           = (array) get_option( \BeltoftPerformance\Admin\Manager::LOADS_OPTION, array() );
		$loads[ $scope ] = $load;
		update_option( \BeltoftPerformance\Admin\Manager::LOADS_OPTION, $loads, false );
		return $load;
	}

	/** [ page => [ state => report ] ]; extra samples of a page type (payload 'target', e.g. one product per product type) are merged into it. */
	/** Pure: check dirs (Ymd-His-hex) beyond the $keep newest; manual-* and anything else is never touched here. */
	public static function checks_to_prune( array $names, int $keep = 5 ): array {
		$checks = array_values( array_filter( array_map( 'strval', $names ), static fn( $n ) => (bool) preg_match( '/^\d{8}-\d{6}-[0-9a-f]{6}\z/', $n ) ) );
		rsort( $checks );
		return array_slice( $checks, $keep );
	}

	/** Pure: cart-cookie options (beltoft_performance_cart_cookies_<id>) whose check is not among $keep_ids. */
	public static function cookie_options_to_drop( array $option_names, array $keep_ids ): array {
		$out = array();
		foreach ( $option_names as $o ) {
			$o = (string) $o;
			if ( 0 === strpos( $o, 'beltoft_performance_cart_cookies_' ) && ! in_array( substr( $o, strlen( 'beltoft_performance_cart_cookies_' ) ), $keep_ids, true ) ) {
				$out[] = $o;
			}
		}
		return $out;
	}

	/** Keeps the 5 newest check report dirs and their cart-cookie options; deletes the rest. */
	public static function prune_checks(): void {
		global $wpdb;
		$base  = dirname( self::dir( 'x' ) );
		$names = array_map( 'basename', (array) glob( $base . '/*', GLOB_ONLYDIR ) );
		$drop  = self::checks_to_prune( $names );
		foreach ( $drop as $name ) {
			foreach ( (array) glob( $base . '/' . $name . '/*' ) as $f ) {
				if ( is_file( $f ) ) {
					@unlink( $f );
				}
			}
			@rmdir( $base . '/' . $name );
		}
		$keep = array_values( array_diff( array_filter( $names, static fn( $n ) => (bool) preg_match( '/^\d{8}-\d{6}-[0-9a-f]{6}\z/', $n ) ), $drop ) );
		$opts = (array) $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'beltoft_performance_cart_cookies_' ) . '%' ) );
		foreach ( self::cookie_options_to_drop( $opts, $keep ) as $o ) {
			delete_option( $o );
		}
	}

	public static function inventory( string $id ): array {
		$out     = array();
		$samples = array();
		foreach ( (array) glob( self::dir( $id ) . '/*.json' ) as $f ) {
			if ( 0 === strpos( basename( $f ), 'usage-' ) ) {
				continue;
			}
			$r = json_decode( (string) file_get_contents( $f ), true );
			if ( ! is_array( $r ) || ! empty( $r['trial'] ) || ! isset( $r['page'], $r['state'] ) ) {
				continue;
			}
			if ( ! empty( $r['target'] ) ) {
				$samples[ $r['page'] ][ $r['state'] ][] = $r;
				continue;
			}
			$out[ $r['page'] ][ $r['state'] ] = $r;
		}
		foreach ( $samples as $page => $states ) {
			foreach ( $states as $state => $list ) {
				if ( isset( $out[ $page ][ $state ] ) ) {
					$out[ $page ][ $state ] = self::merge_samples( $out[ $page ][ $state ], $list );
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Pure: the main report of a page type and state with its extra samples folded in: loaded plugins and signatures are the
	 * union over every 200 sample (a plugin used on any sample counts as used); files, hash and status stay the main report's,
	 * since trials compare against those. 'samples' names the merged samples.
	 */
	public static function merge_samples( array $main, array $samples ): array {
		$plugins = array_values( array_map( 'strval', (array) ( $main['plugins'] ?? array() ) ) );
		$sigs    = (array) ( $main['signatures'] ?? array() );
		$names   = array();
		foreach ( $samples as $s ) {
			if ( ! is_array( $s ) || 200 !== (int) ( $s['status'] ?? 0 ) ) {
				continue;
			}
			$names[] = (string) ( $s['target'] ?? '' );
			foreach ( (array) ( $s['plugins'] ?? array() ) as $p ) {
				if ( ! in_array( (string) $p, $plugins, true ) ) {
					$plugins[] = (string) $p;
				}
			}
			foreach ( (array) ( $s['signatures'] ?? array() ) as $p => $needles ) {
				$sigs[ $p ] = array_values( array_unique( array_merge( (array) ( $sigs[ $p ] ?? array() ), (array) $needles ) ) );
			}
		}
		$main['plugins']    = $plugins;
		$main['signatures'] = $sigs;
		$main['samples']    = $names;
		return $main;
	}

	/** Pure: the payload 'target' (and report name prefix) of the product sample of one product type. */
	public static function sample_target( string $type ): string {
		return 'product-' . preg_replace( '/[^a-z0-9\-]/', '', strtolower( $type ) );
	}

	/** One published product per product type other than simple (the main product target is a simple one): [ type => URL ]. */
	public static function product_samples(): array {
		if ( ! taxonomy_exists( 'product_type' ) ) {
			return array();
		}
		$out   = array();
		$types = get_terms( array( 'taxonomy' => 'product_type', 'hide_empty' => true, 'fields' => 'slugs' ) );
		foreach ( is_array( $types ) ? $types : array() as $type ) {
			if ( ! is_string( $type ) || 'simple' === $type ) {
				continue;
			}
			$pid = (int) ( get_posts( array( 'post_type' => 'product', 'post_status' => 'publish', 'has_password' => false, 'fields' => 'ids', 'numberposts' => 1, 'meta_key' => 'total_sales', 'orderby' => 'meta_value_num', 'order' => 'DESC', 'tax_query' => array( array( 'taxonomy' => 'product_type', 'field' => 'slug', 'terms' => $type ) ) ) )[0] ?? 0 );
			if ( $pid ) {
				$out[ $type ] = (string) get_permalink( $pid );
			}
		}
		return $out;
	}

	/** Pure: the "kind:handle" files $plugin printed on $pages in the inventory, every state. */
	public static function printed_handles( array $inv, array $pages, string $plugin ): array {
		$out = array();
		foreach ( $pages as $page ) {
			foreach ( (array) ( $inv[ $page ] ?? array() ) as $r ) {
				foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
					if ( ( $a['plugin'] ?? '' ) === $plugin ) {
						$out[ ( $a['kind'] ?? '' ) . ':' . ( $a['handle'] ?? '' ) ] = true;
					}
				}
			}
		}
		return array_keys( $out );
	}

	public static function candidates( string $id ): array {
		$inv     = self::inventory( $id );
		$used_on = $inv ? ContentSignatures::scan() : array();
		$plugin  = self::candidates_from( $inv, $used_on );
		$by_page = array();
		foreach ( $plugin as $p ) {
			foreach ( $p['pages'] as $pg ) {
				$by_page[ $pg ][] = substr( $p['target'], strpos( $p['target'], ':' ) + 1 );
			}
		}
		$files = FileCandidates::from( $inv, Usage::load( $id ), $by_page, $used_on );
		$cands = self::drop_rejected_pages( array_merge( $plugin, $files ), (array) get_option( 'beltoft_performance_rejected_pages', array() ) );
		$cands = self::drop_dismissed( $cands, (array) get_option( 'beltoft_performance_rejected', array() ) );
		return self::drop_covered( $cands, Store::all() );
	}

	/** Pure: candidates without the targets dismissed everywhere ([ target => time ]), so the CLI and the admin pages agree. */
	public static function drop_dismissed( array $cands, array $dismissed ): array {
		return array_values( array_filter( $cands, static fn( $c ) => ! isset( $dismissed[ (string) ( $c['target'] ?? '' ) ] ) ) );
	}

	/**
	 * Pure: candidates without the page types a retail rule of the same target already covers (a plugin: rule leaves the
	 * plugin loaded, so the check would propose it again); a candidate left with no page goes.
	 * @param \BeltoftPerformance\Rules\Rule[] $rules
	 */
	public static function drop_covered( array $cands, array $rules ): array {
		$covered = array();
		foreach ( $rules as $r ) {
			if ( $r instanceof \BeltoftPerformance\Rules\Rule && in_array( 'retail', $r->hosts, true ) ) {
				foreach ( $r->pages as $p ) {
					$covered[ $r->target ][] = (string) $p;
				}
			}
		}
		$out = array();
		foreach ( $cands as $c ) {
			$drop = $covered[ $c['target'] ?? '' ] ?? array();
			if ( $drop ) {
				$c = self::narrow( $c, array_values( array_diff( (array) $c['pages'], $drop ) ) );
			}
			if ( $c['pages'] ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/** Pure: $c restricted to $pages (pages, why and evidence). */
	public static function narrow( array $c, array $pages ): array {
		$c['pages'] = array_values( array_intersect( (array) ( $c['pages'] ?? array() ), $pages ) );
		foreach ( array( 'why', 'evidence' ) as $f ) {
			if ( isset( $c[ $f ] ) && is_array( $c[ $f ] ) ) {
				$c[ $f ] = array_intersect_key( $c[ $f ], array_flip( $c['pages'] ) );
			}
		}
		return $c;
	}

	/** Pure: candidates without the page types rejected one by one ([ target => [ page, … ] ]); a candidate left with no page goes. */
	public static function drop_rejected_pages( array $cands, array $rejected ): array {
		$out = array();
		foreach ( $cands as $c ) {
			$drop = (array) ( $rejected[ $c['target'] ?? '' ] ?? array() );
			if ( $drop ) {
				$c = self::narrow( $c, array_values( array_diff( (array) $c['pages'], $drop ) ) );
			}
			if ( $c['pages'] ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * Pure: inventory [ page => [ state => report ] ] → candidate list. Post/page types cannot be URL-unloaded, so they get plugin: (file suppression).
	 * $used_on: [ plugin => page types whose site content carries its markers ] (ContentSignatures::scan()): never proposed there.
	 */
	public static function candidates_from( array $inv, array $used_on = array() ): array {
		$c = array();
		foreach ( $inv as $page => $states ) {
			$loaded = array();
			$used   = array();
			$states = array_filter( (array) $states, static fn( $r ) => 200 === (int) ( $r['status'] ?? 0 ) ); // redirects/errors say nothing about plugin use
			foreach ( $states as $r ) {
				foreach ( (array) ( $r['plugins'] ?? array() ) as $p ) { $loaded[ $p ] = true; }
				foreach ( array_keys( (array) ( $r['signatures'] ?? array() ) ) as $p ) { $used[ $p ] = true; }
			}
			$kind = in_array( $page, Store::URL_PAGE_TYPES, true ) ? 'unload:' : 'plugin:';
			foreach ( array_keys( $loaded ) as $plugin ) {
				$plugin = (string) $plugin;
				if ( Store::is_protected_plugin( $plugin ) || null !== Store::never_accept_reason( $plugin ) || isset( $used[ $plugin ] ) || in_array( $page, (array) ( $used_on[ $plugin ] ?? array() ), true ) ) {
					continue;
				}
				$printed = array();
				foreach ( $states as $r ) {
					foreach ( (array) ( $r['assets'] ?? array() ) as $a ) {
						if ( ( $a['plugin'] ?? '' ) === $plugin ) { $printed[] = $a['kind'] . ':' . $a['handle']; }
					}
				}
				$key = $kind . $plugin;
				$c[ $key ]['target']        = $key;
				$c[ $key ]['pages'][]       = $page;
				$c[ $key ]['why'][ $page ] = $printed
					? sprintf( __( 'No signs on the page; loads %d file(s): %s', 'beltoft-performance' ), count( array_unique( $printed ) ), implode( ', ', array_unique( $printed ) ) )
					: __( 'No signs on the page and no files printed.', 'beltoft-performance' );
			}
		}
		return array_values( $c );
	}
}
