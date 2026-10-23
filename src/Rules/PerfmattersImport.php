<?php
namespace BeltoftPerformance\Rules;

use BeltoftPerformance\Assets\Graph;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Perfmatters' Script Manager option (perfmatters_script_manager) into manual rules, so its removals survive
 * switching Perfmatters off. Shape: disabled → js|css|plugins|themes → handle|dir → everywhere / current (post ids) /
 * post_types / archives / 404 / user_status / device_type / regex; exceptions under enabled → same → current / post_types /
 * archives / 404 / user_status / device_type / regex.
 *
 * Removal is mapped narrowly (only what Perfmatters surely removes), exceptions widely (every page type an exception could
 * touch keeps the file); anything that cannot be expressed is reported and never widens a removal. An exception for single
 * posts keeps only those URLs: the rest of their page type is listed as url: scopes when it has at most MAX_URLS pages.
 *
 * The import only preserves behaviour when Perfmatters' removals are live today (live_check).
 */
final class PerfmattersImport {
	public const REASON = 'Imported from Perfmatters Script Manager.';

	/** Most published pages of one page type that are listed as url: scopes. */
	public const MAX_URLS = 200;

	/** Disabled post type → the page type Perfmatters surely removes it on ('page' is listed as url: scopes: the page type also covers archives and 404 pages). */
	private const POST_TYPE_PAGES = array( 'product' => 'product', 'page' => 'page', 'post' => 'post' );

	/** Excepted post type → every page type the exception can hold on (pmsm_check_post_types uses the global post: archives count). */
	private const EXCEPTED_POST_TYPE_PAGES = array(
		'product' => array( 'product', 'shop', 'archive_cat', 'archive_brand', 'archive_tag', 'search' ),
		'page'    => array( 'page', 'front', 'blog', 'cart', 'checkout', 'order_received', 'account', 'search' ),
		'post'    => array( 'post', 'page', 'search' ),
	);

	private const ARCHIVE_PAGES = array( 'shop', 'archive_cat', 'archive_brand', 'archive_tag', 'search', 'blog', 'page' );

	/** Group conditions that make Perfmatters apply a plugin's group entry to all of its files, on every request. */
	private const GROUP_WIDE = array( 'everywhere', 'post_types', 'archives', 'user_status', 'device_type', 'regex' );

	/* ---------------------------------------------------------------- is Perfmatters removing anything today? */

	/**
	 * Pure: the four values that decide whether Script Manager removals are live. $tools = option perfmatters_tools
	 * (script_manager, safe_mode), $settings = option perfmatters_script_manager_settings (testing_mode, mu_mode).
	 */
	public static function live_state( bool $active, $tools, $settings ): array {
		$t = is_array( $tools ) ? $tools : array();
		$s = is_array( $settings ) ? $settings : array();
		return array(
			'active'         => $active,
			'script_manager' => ! empty( $t['script_manager'] ),
			'testing_mode'   => ! empty( $s['testing_mode'] ),
			'safe_mode'      => ! empty( $t['safe_mode'] ),
			'mu_mode'        => ! empty( $s['mu_mode'] ),
		);
	}

	public static function live_line( array $state ): string {
		return sprintf(
			'Perfmatters: active=%s script_manager=%d testing_mode=%d safe_mode=%d mu_mode=%d',
			empty( $state['active'] ) ? 'no' : 'yes',
			! empty( $state['script_manager'] ),
			! empty( $state['testing_mode'] ),
			! empty( $state['safe_mode'] ),
			! empty( $state['mu_mode'] )
		);
	}

	/** Pure: refuse (string, or null to go on) and warnings. Importing removals that are not live today would add new, untested removals. */
	public static function live_check( array $state, bool $force ): array {
		$problems = array();
		if ( empty( $state['active'] ) ) {
			$problems[] = 'Perfmatters is not active';
		}
		if ( empty( $state['script_manager'] ) ) {
			$problems[] = 'its Script Manager is off (perfmatters_tools[script_manager])';
		}
		if ( ! empty( $state['testing_mode'] ) ) {
			$problems[] = 'Script Manager testing mode is on, so visitors keep every file (perfmatters_script_manager_settings[testing_mode])';
		}
		if ( ! empty( $state['safe_mode'] ) ) {
			$problems[] = 'Perfmatters safe mode is on (perfmatters_tools[safe_mode])';
		}
		$out = array( 'refuse' => null, 'warnings' => array() );
		if ( $problems ) {
			$why = implode( '; ', $problems );
			if ( $force ) {
				$out['warnings'][] = "FORCED: $why. Perfmatters is not removing these files today: every imported row is a NEW, untested removal. Check every page type by hand.";
			} else {
				$out['refuse'] = "Refused: $why. Perfmatters' removals are not live, so importing them would add new, untested removals. Use --force to import anyway.";
			}
		}
		if ( ! empty( $state['mu_mode'] ) ) {
			$out['warnings'][] = 'MU mode is on: Perfmatters stops the PHP of its plugins: entries, but the import only removes their files (CSS/JS). That PHP runs again once Perfmatters is off.';
		}
		return $out;
	}

	/* ---------------------------------------------------------------- plan */

	/** Pure: every post id named under current (disabled and enabled), sorted. */
	public static function post_ids( array $option ): array {
		$ids = array();
		foreach ( array( 'disabled', 'enabled' ) as $side ) {
			foreach ( (array) ( $option[ $side ] ?? array() ) as $entries ) {
				foreach ( (array) $entries as $cond ) {
					foreach ( (array) ( is_array( $cond ) ? ( $cond['current'] ?? array() ) : array() ) as $id ) {
						if ( is_numeric( $id ) && (int) $id > 0 ) {
							$ids[ (int) $id ] = true;
						}
					}
				}
			}
		}
		$ids = array_keys( $ids );
		sort( $ids );
		return $ids;
	}

	/**
	 * Pure: one row per disabled entry: source (js:handle, css:handle, plugins:dir…), target, scopes, action (import|skip),
	 * note, scripts (the rule removes scripts), urls (url: scopes in the row).
	 * $ctx: posts [ id => [ scope => 'url:/path/' or '' (not a normal page), page => page type, post_type ] ] (published only),
	 * type_urls [ page type => url: scopes of its published pages, or null when there are too many to list; missing = none ],
	 * known [ script|style => [ handle => [ src, plugin, deps ] ] ], plugins [ installed directories ].
	 */
	public static function plan( array $option, array $ctx ): array {
		$rows   = array();
		$groups = (array) ( $option['disabled']['plugins'] ?? array() );
		foreach ( (array) ( $option['disabled'] ?? array() ) as $cat => $entries ) {
			foreach ( (array) $entries as $name => $cond ) {
				$rows[] = self::row( (string) $cat, (string) $name, (array) $cond, (array) ( $option['enabled'][ $cat ][ $name ] ?? array() ), $ctx, $groups );
			}
		}
		return $rows;
	}

	private static function row( string $cat, string $name, array $cond, array $except, array $ctx, array $groups ): array {
		$notes   = array();
		$also    = array();
		$target  = '';
		$skip    = null;
		$scripts = false;
		$kinds   = array( 'js' => 'script', 'css' => 'style', 'plugins' => 'plugin' );
		if ( ! isset( $kinds[ $cat ] ) ) {
			$skip = 'themes' === $cat ? 'Theme files: not imported (the theme is protected).' : 'Unknown Perfmatters group.';
		} else {
			$target  = $kinds[ $cat ] . ':' . $name;
			$skip    = self::target_error( $kinds[ $cat ], $name, $ctx, $also, $notes, $scripts );
			$skip  ??= self::group_error( $kinds[ $cat ], $name, $ctx, $groups, $notes );
		}
		$scopes = array();
		if ( null === $skip ) {
			$type_urls = (array) ( $ctx['type_urls'] ?? array() );
			$scopes    = self::disabled_scopes( $cond, (array) ( $ctx['posts'] ?? array() ), $type_urls, $notes );
			$ex        = self::excepted( $except, (array) ( $ctx['posts'] ?? array() ), $notes );
			if ( null !== $ex['error'] ) {
				$skip = $ex['error'];
			} else {
				$scopes = self::apply_exceptions( $scopes, $ex, $type_urls, $notes );
				if ( ! $scopes ) {
					$skip = 'Nothing left to switch off.';
				}
			}
		}
		if ( null !== $skip ) {
			array_unshift( $notes, $skip );
		} elseif ( $also ) {
			$notes[] = 'Also removes (dependents): ' . implode( ',', array_slice( $also, 0, 8 ) ) . ( count( $also ) > 8 ? ',…' : '' ) . '.';
		}
		$final = null === $skip ? array_keys( $scopes ) : array();
		return array(
			'source'  => $cat . ':' . $name,
			'target'  => $target,
			'scopes'  => $final,
			'action'  => null === $skip ? 'import' : 'skip',
			'note'    => implode( ' ', $notes ),
			'scripts' => null === $skip && $scripts,
			'urls'    => count( array_filter( $final, static fn( $s ) => 0 === strpos( $s, 'url:' ) ) ),
		);
	}

	/**
	 * Why this target can't be imported, or null. $also gets the dependents the rule also takes (the Suppressor cascades),
	 * $scripts whether it removes scripts (clicks are not covered by any proof).
	 */
	private static function target_error( string $kind, string $name, array $ctx, array &$also, array &$notes, bool &$scripts ): ?string {
		if ( ! preg_match( '/^[a-z0-9][a-z0-9_.\-]*$/i', $name ) ) {
			return 'Invalid name.';
		}
		if ( 'plugin' === $kind ) {
			if ( Store::is_protected_plugin( $name ) ) {
				return 'Plugin is protected.';
			}
			$never = Store::never_accept_reason( $name );
			if ( null !== $never ) {
				return $never;
			}
			if ( ! in_array( $name, (array) ( $ctx['plugins'] ?? array() ), true ) ) {
				return 'Plugin not installed.';
			}
			return self::plugin_cascade_error( $name, $ctx, $also, $notes, $scripts );
		}
		$known = (array) ( $ctx['known'][ $kind ] ?? array() );
		if ( ! isset( $known[ $name ] ) ) {
			return 'Unknown handle: not registered on this site.';
		}
		if ( 'dashicons' === $name ) {
			return 'Perfmatters keeps dashicons for logged-in users (admin bar); a rule cannot.';
		}
		$src   = (string) ( $known[ $name ]['src'] ?? '' );
		$owner = (string) ( $known[ $name ]['plugin'] ?? '' );
		if ( Store::is_protected_handle( $name, $src ) || ( '' !== $owner && Store::is_protected_plugin( $owner ) ) ) {
			return 'File is protected.';
		}
		if ( '' !== $owner && null !== Store::never_accept_reason( $owner ) ) {
			return (string) Store::never_accept_reason( $owner );
		}
		$graph   = self::graph( $known );
		$cascade = $graph->cascade( array( $name ) );
		$prot    = $graph->protected_in( $cascade );
		if ( $prot ) {
			return 'A protected file depends on it (' . implode( ',', $prot ) . '): the rule would never apply.';
		}
		$also    = array_values( array_diff( $cascade, array( $name ) ) );
		$scripts = 'script' === $kind;
		return null;
	}

	/** A plugin rule drops the plugin's files per kind, with their dependents; a kind whose cascade hits a protected file stays whole. */
	private static function plugin_cascade_error( string $name, array $ctx, array &$also, array &$notes, bool &$scripts ): ?string {
		$seen    = false;
		$ok      = 0;
		$blocked = array();
		foreach ( array( 'script', 'style' ) as $kind ) {
			$known = (array) ( $ctx['known'][ $kind ] ?? array() );
			$graph = self::graph( $known );
			$own   = array_keys( array_filter( $known, static fn( $f ) => (string) ( $f['plugin'] ?? '' ) === $name ) );
			if ( ! $own ) {
				continue;
			}
			$seen    = true;
			$cascade = $graph->cascade( $own );
			$prot    = $graph->protected_in( $cascade );
			if ( $prot ) {
				$blocked[] = $kind . 's stay (a protected file depends on them: ' . implode( ',', $prot ) . ')';
				continue;
			}
			++$ok;
			$also    = array_merge( $also, array_values( array_diff( $cascade, $own ) ) );
			$scripts = $scripts || 'script' === $kind;
		}
		if ( ! $seen ) {
			$notes[] = 'No files of this plugin seen on this site.';
			return null;
		}
		if ( ! $ok ) {
			return 'Its ' . implode( '; ', $blocked ) . ': the rule would never apply.';
		}
		if ( $blocked ) {
			$notes[] = 'Its ' . implode( '; ', $blocked ) . '.';
		}
		return null;
	}

	/** Perfmatters applies a plugin's group entry instead of a file entry of that plugin (perfmatters_dequeue_scripts). */
	private static function group_error( string $kind, string $name, array $ctx, array $groups, array &$notes ): ?string {
		if ( 'plugin' === $kind ) {
			return null;
		}
		$owner = (string) ( $ctx['known'][ $kind ][ $name ]['plugin'] ?? '' );
		$group = '' === $owner ? array() : (array) ( $groups[ $owner ] ?? array() );
		if ( ! $group ) {
			return null;
		}
		foreach ( self::GROUP_WIDE as $k ) {
			if ( ! empty( $group[ $k ] ) ) {
				return 'Perfmatters ignores this file entry: the group entry plugins:' . $owner . ' has conditions and applies to its files instead.';
			}
		}
		if ( ! empty( $group['current'] ) || ! empty( $group['404'] ) ) {
			$notes[] = 'On the posts/404 of plugins:' . $owner . ', Perfmatters applies that group entry instead.';
		}
		return null;
	}

	/** [ scope => page type ] Perfmatters surely removes the file on; the rest goes to $notes. */
	private static function disabled_scopes( array $cond, array $posts, array $type_urls, array &$notes ): array {
		$out = array();
		if ( ! empty( $cond['everywhere'] ) ) {
			foreach ( Store::PAGE_TYPES as $p ) {
				$out[ $p ] = $p;
			}
		}
		foreach ( (array) ( $cond['current'] ?? array() ) as $id ) {
			$post = $posts[ (int) $id ] ?? null;
			if ( ! is_array( $post ) ) {
				$notes[] = 'Post #' . (int) $id . ' not found or not published.';
			} elseif ( '' === (string) ( $post['scope'] ?? '' ) ) {
				$notes[] = 'Post #' . (int) $id . ' is not a normal page.';
			} else {
				$out[ (string) $post['scope'] ] = (string) ( $post['page'] ?? '' );
			}
		}
		foreach ( (array) ( $cond['post_types'] ?? array() ) as $pt ) {
			if ( 'page' === $pt ) {
				// Page type 'page' also covers archives and 404 pages: list the published pages instead.
				$list = array_key_exists( 'page', $type_urls ) ? $type_urls['page'] : array();
				if ( null === $list || count( (array) $list ) > self::MAX_URLS ) {
					$notes[] = 'Post type page: more than ' . self::MAX_URLS . ' published pages, not covered.';
				} elseif ( ! $list ) {
					$notes[] = 'Post type page: no published page of page type page.';
				} else {
					foreach ( (array) $list as $s ) {
						$out[ (string) $s ] = 'page';
					}
					$notes[] = 'Post type page: ' . count( (array) $list ) . ' published pages as url: scopes. Pages published later keep it.';
				}
			} elseif ( isset( self::POST_TYPE_PAGES[ $pt ] ) ) {
				$out[ self::POST_TYPE_PAGES[ $pt ] ] = self::POST_TYPE_PAGES[ $pt ];
			} else {
				$notes[] = 'Post type ' . $pt . ': no page type, not covered.';
			}
		}
		if ( ! empty( $cond['404'] ) ) {
			$notes[] = '404: not covered.';
		}
		foreach ( array( 'archives', 'user_status', 'device_type', 'regex' ) as $k ) {
			if ( ! empty( $cond[ $k ] ) ) {
				$notes[] = $k . ': cannot be expressed, not covered.';
			}
		}
		return $out;
	}

	/**
	 * Where an exception keeps the file: wide = whole page types (post types, archives, 404); scopes = excepted post URLs;
	 * url_pages = the page types those posts can be (URL classification ∪ post type); error when it cannot be expressed.
	 */
	private static function excepted( array $except, array $posts, array &$notes ): array {
		$out = array( 'wide' => array(), 'scopes' => array(), 'url_pages' => array(), 'error' => null );
		foreach ( array( 'user_status', 'device_type', 'regex' ) as $k ) {
			if ( ! empty( $except[ $k ] ) ) {
				$out['error'] = 'Perfmatters exception by ' . $k . ' cannot be expressed.';
				return $out;
			}
		}
		foreach ( (array) ( $except['current'] ?? array() ) as $id ) {
			$post = $posts[ (int) $id ] ?? null;
			if ( ! is_array( $post ) ) {
				$notes[] = 'Exception for missing post #' . (int) $id . ' ignored.';
				continue;
			}
			if ( '' === (string) ( $post['scope'] ?? '' ) || '' === (string) ( $post['page'] ?? '' ) ) {
				$out['error'] = 'Perfmatters exception for post #' . (int) $id . ' cannot be expressed.';
				return $out;
			}
			$out['scopes'][]    = (string) $post['scope'];
			$out['url_pages'][] = (string) $post['page'];
			if ( isset( self::POST_TYPE_PAGES[ (string) ( $post['post_type'] ?? '' ) ] ) ) {
				$out['url_pages'][] = self::POST_TYPE_PAGES[ (string) $post['post_type'] ];
			}
			if ( in_array( (string) ( $post['post_type'] ?? '' ), array( 'post', 'page' ), true ) ) {
				$out['url_pages'][] = 'search'; // Perfmatters falls back to the first result's ID on search
			}
			if ( 'checkout' === $post['page'] ) {
				$out['url_pages'][] = 'order_received'; // Perfmatters' current ID on the order-received endpoint is the checkout page
			}
		}
		foreach ( (array) ( $except['post_types'] ?? array() ) as $pt ) {
			$out['wide'] = array_merge( $out['wide'], self::EXCEPTED_POST_TYPE_PAGES[ $pt ] ?? array( 'page' ) );
		}
		if ( ! empty( $except['archives'] ) ) {
			$out['wide'] = array_merge( $out['wide'], self::ARCHIVE_PAGES );
		}
		if ( ! empty( $except['404'] ) ) {
			$out['wide'][] = 'page';
			$notes[]       = 'Perfmatters exception on 404: kept on page type page (it covers 404 pages).';
		}
		foreach ( array( 'wide', 'scopes', 'url_pages' ) as $k ) {
			$out[ $k ] = array_values( array_unique( $out[ $k ] ) );
		}
		return $out;
	}

	/** Drops excepted scopes; a page type excepted only for some URLs becomes url: scopes of its other published pages (≤ MAX_URLS). */
	private static function apply_exceptions( array $scopes, array $ex, array $type_urls, array &$notes ): array {
		$out  = array();
		$kept = array();
		foreach ( $scopes as $scope => $page ) {
			$scope = (string) $scope;
			if ( 0 === strpos( $scope, 'url:' ) ) {
				if ( in_array( $scope, $ex['scopes'], true ) ) {
					$kept[] = $scope;
				} else {
					$out[ $scope ] = $page;
				}
				continue;
			}
			if ( in_array( $page, $ex['wide'], true ) ) {
				$kept[] = $scope;
				continue;
			}
			if ( ! in_array( $page, $ex['url_pages'], true ) ) {
				$out[ $scope ] = $page;
				continue;
			}
			$kept[] = $scope;
			$list   = array_key_exists( $page, $type_urls ) ? $type_urls[ $page ] : array();
			$left   = null === $list ? null : array_values( array_diff( array_map( 'strval', (array) $list ), $ex['scopes'] ) );
			if ( null === $left || count( $left ) > self::MAX_URLS ) {
				$notes[] = $page . ': the exception holds on single URLs, but more than ' . self::MAX_URLS . ' other published pages are too many to list: kept on the whole page type.';
				continue;
			}
			if ( ! $left ) {
				continue;
			}
			foreach ( $left as $s ) {
				$out[ $s ] = $page;
			}
			$notes[] = $page . ': exception for ' . implode( ',', $ex['scopes'] ) . '; switched off on ' . count( $left ) . ' other published pages instead (url: scopes)'
				. ( 'page' === $page ? '; archives and 404 pages (also page type page) not covered.' : '.' ) . ' Pages published later keep it.';
		}
		if ( $kept ) {
			$notes[] = 'Kept (Perfmatters exception): ' . implode( ',', $kept ) . '.';
		}
		if ( $out ) {
			$order = array_flip( Store::PAGE_TYPES );
			uksort( $out, static fn( $a, $b ) => ( $order[ $a ] ?? 99 ) <=> ( $order[ $b ] ?? 99 ) ?: strcmp( (string) $a, (string) $b ) );
		}
		return $out;
	}

	private static function graph( array $known ): Graph {
		return new Graph( array_map( static fn( $k ) => array( 'src' => (string) ( $k['src'] ?? '' ), 'deps' => (array) ( $k['deps'] ?? array() ) ), $known ) );
	}

	/* ---------------------------------------------------------------- undo */

	/**
	 * Pure: one row per rule carrying the import reason: remove its manual scopes (accepted scopes stay), or skip when the
	 * reason was merged with another one (which scopes came from the import is unknown) or no scope was added by hand.
	 *
	 * @param Rule[] $rules
	 */
	public static function undo_plan( array $rules ): array {
		$rows = array();
		foreach ( $rules as $r ) {
			if ( false === strpos( $r->reason, self::REASON ) ) {
				continue;
			}
			$row = array( 'target' => $r->target, 'key' => $r->key(), 'scopes' => array(), 'action' => 'skip', 'note' => '' );
			if ( self::REASON !== $r->reason ) {
				$row['note'] = 'Reason merged with another one: remove its imported scopes by hand.';
			} elseif ( ! $r->manual_pages ) {
				$row['note'] = 'No scope added by hand.';
			} else {
				$row['action'] = 'remove';
				$row['scopes'] = array_values( $r->manual_pages );
				$stay          = array_values( array_diff( $r->pages, $r->manual_pages ) );
				$row['note']   = $stay ? 'Accepted scopes stay: ' . implode( ',', $stay ) . '.' : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/** Applies undo_plan to the stored rules. @param Rule[] $rules @return array{ plan: array, removed: int } */
	public static function undo( array $rules ): array {
		$plan    = self::undo_plan( $rules );
		$by_key  = array();
		$removed = 0;
		foreach ( $rules as $r ) {
			$by_key[ $r->key() ] = $r;
		}
		foreach ( $plan as $i => $row ) {
			if ( 'remove' !== $row['action'] ) {
				$plan[ $i ]['result'] = '-';
				continue;
			}
			$res                  = Store::remove_scopes( $by_key[ $row['key'] ], $row['scopes'] );
			$plan[ $i ]['result'] = true === $res ? 'removed' : (string) $res;
			$removed             += true === $res ? 1 : 0;
		}
		return array( 'plan' => $plan, 'removed' => $removed );
	}
}
