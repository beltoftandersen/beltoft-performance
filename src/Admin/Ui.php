<?php
namespace BeltoftPerformance\Admin;

use BeltoftPerformance\Check\Trial;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

/** Pure helpers of the admin screen: tabs, the suggestion of one target on one page (Trial::approves), the experts' file list, dismissals, Bricks states. Tested in UiTest. */
final class Ui {
	public const TABS = array( 'scripts', 'off', 'settings' );

	/** Old ?tab= values (bookmarks) → the tab that holds that function now. */
	private const OLD_TABS = array( 'overview' => 'scripts', 'pages' => 'scripts', 'manager' => 'scripts', 'proposals' => 'scripts', 'rules' => 'off', 'log' => 'off', 'bricks' => 'settings', 'switches' => 'settings' );

	/** Pure: the tab for a ?tab= value; unknown → overview. */
	public static function tab( string $raw ): string {
		if ( in_array( $raw, self::TABS, true ) ) {
			return $raw;
		}
		return self::OLD_TABS[ $raw ] ?? 'scripts';
	}

	/** Pure: "Category pages" for archive_cat; unknown types as given. */
	public static function page_type_label( string $type ): string {
		return Words::page_name( $type );
	}

	/** Pure: "Shop" / "URL /sobre-nos/" / "Template: Single product" (or "Template #12" when the title is unknown). */
	public static function scope_label( string $scope, array $template_titles ): string {
		if ( 0 === strpos( $scope, 'url:' ) ) {
			/* translators: %s: page path */
			return sprintf( __( 'URL %s', 'beltoft-performance' ), substr( $scope, 4 ) );
		}
		if ( 0 === strpos( $scope, 'template:' ) ) {
			$id = (int) substr( $scope, 9 );
			/* translators: %s: Bricks template title */
			return isset( $template_titles[ $id ] ) ? sprintf( __( 'Template: %s', 'beltoft-performance' ), $template_titles[ $id ] ) : sprintf( __( 'Template #%d', 'beltoft-performance' ), $id );
		}
		return self::page_type_label( $scope );
	}

	/** Pure: Plugin (unload:), Plugin files (plugin:), Script, Style. */
	public static function target_kind_label( string $target ): string {
		$labels = array(
			'unload' => __( 'Plugin', 'beltoft-performance' ),
			'plugin' => __( 'Plugin files', 'beltoft-performance' ),
			'script' => __( 'Script', 'beltoft-performance' ),
			'style'  => __( 'Style', 'beltoft-performance' ),
		);
		$kind = (string) strstr( $target, ':', true );
		return $labels[ $kind ] ?? $kind;
	}

	/** Pure: the browser pass as the runbook runs it — as root in the site dir (the browser needs root's playwright), --allow-root, the playwright path. */
	public static function browser_command( string $command, string $abspath, string $pw ): string {
		return 'cd ' . untrailingslashit( $abspath ) . ' && ' . preg_replace( '/^wp /', 'wp --allow-root ', $command ) . ' --pw=' . $pw;
	}

	/** Pure: hands the files a root run wrote back to the web server (runbook step 6). */
	public static function chown_command( string $abspath ): string {
		return 'cd ' . untrailingslashit( $abspath ) . ' && chown -R www-data:www-data wp-content/uploads/beltoft-performance wp-content/mu-plugins/beltoft-performance-loader.php';
	}

	/**
	 * Pure: the suggestion shown on a row for $target on page type $scope, or null when it is not a current candidate there.
	 * state: not_tested | passed | passed_here (proven on $scope only) | failed | needs_browser; reason: why (failure, outdated test, scripts); command: the CLI browser test
	 * when it is missing; accept: true for a passed, current test covering at least $scope (Trial::approves) that Store may accept; all: it covered every page of the suggestion.
	 * $candidates: [ target => candidate ]. $rules: a retail rule of $target already covering $scope means no suggestion.
	 */
	public static function suggestion( string $target, array $candidates, array $trials, string $check_id, string $scope, array $rules = array() ): ?array {
		$c = $candidates[ $target ] ?? null;
		if ( ! is_array( $c ) || ! in_array( $scope, Store::PAGE_TYPES, true ) || ! in_array( $scope, (array) ( $c['pages'] ?? array() ), true ) ) {
			return null;
		}
		$off = array();
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule && $r->target === $target && in_array( 'retail', $r->hosts, true ) ) {
				if ( in_array( $scope, $r->pages, true ) ) {
					return null;
				}
				$off = array_merge( $off, $r->pages );
			}
		}
		$pages  = array_values( array_diff( (array) $c['pages'], $off ) ); // pages where it is already off are not offered again
		$trial  = is_array( $trials[ $target ] ?? null ) ? $trials[ $target ] : null;
		$badge  = Manager::trial_badge( $trial, $check_id, $scope );
		$state  = 'not_tested';
		$reason = '';
		$cmd    = '';
		if ( 'trial_failed' === $badge ) {
			$state = 'failed';
			foreach ( (array) ( $trial['evidence'] ?? array() ) as $row ) {
				if ( ! empty( $row['problem'] ) ) {
					$reason = (string) $row['problem'];
					break;
				}
			}
			if ( '' === $reason ) {
				$reason = isset( $trial['browser'] ) && empty( $trial['browser']['ok'] ) ? __( 'The browser test failed.', 'beltoft-performance' ) : __( 'The test failed.', 'beltoft-performance' );
			}
		} elseif ( 'needs_browser' === $badge ) {
			$state = 'needs_browser';
			$cmd   = 'wp beltoft-performance browser ' . $target;
		} elseif ( in_array( $badge, array( 'trial_passed', 'browser_passed' ), true ) ) {
			if ( Trial::approves( (array) $trial, $check_id, $pages ) ) {
				$state = 'passed';
			} elseif ( Trial::approves( (array) $trial, $check_id, array( $scope ) ) ) {
				$state = 'passed_here'; // "Test on this page": this page is proven, the others are not tested yet
			} else {
				$reason = __( 'The pages changed since the test: test again.', 'beltoft-performance' );
			}
		}
		$refusal = Store::accept_error( $target );
		if ( null !== $refusal && '' === $reason ) {
			$reason = __( 'scripts are switched off by hand', 'beltoft-performance' );
		}
		return array( 'state' => $state, 'reason' => $reason, 'command' => $cmd, 'accept' => in_array( $state, array( 'passed', 'passed_here' ), true ) && null === $refusal, 'all' => 'passed' === $state, 'pages' => $pages );
	}

	/**
	 * Pure: Manager::group_inventory() groups with a 'suggestion' (suggestion() or null) on every group and file; current candidates of the
	 * page type that no row shows are appended (a plugin as its own group, files in one '__suggested' group).
	 */
	public static function with_suggestions( array $groups, array $candidates, array $trials, string $check_id, string $scope, array $rules = array() ): array {
		$by    = array();
		foreach ( $candidates as $c ) {
			$by[ (string) ( $c['target'] ?? '' ) ] = $c;
		}
		$shown = array();
		foreach ( $groups as $i => $g ) {
			$t                           = $g['target'] ?? null;
			$groups[ $i ]['suggestion'] = null !== $t ? self::suggestion( (string) $t, $by, $trials, $check_id, $scope, $rules ) : null;
			if ( null !== $t ) {
				$shown[ (string) $t ] = true;
			}
			foreach ( (array) ( $g['files'] ?? array() ) as $j => $f ) {
				$groups[ $i ]['files'][ $j ]['suggestion'] = self::suggestion( (string) $f['target'], $by, $trials, $check_id, $scope, $rules );
				$shown[ (string) $f['target'] ]            = true;
			}
		}
		$files = array();
		foreach ( $by as $target => $c ) {
			$s = isset( $shown[ $target ] ) ? null : self::suggestion( (string) $target, $by, $trials, $check_id, $scope, $rules );
			if ( null === $s ) {
				continue;
			}
			$kind = (string) strstr( (string) $target, ':', true );
			$name = (string) substr( (string) $target, strlen( $kind ) + 1 );
			if ( in_array( $kind, array( 'unload', 'plugin' ), true ) ) {
				$groups[] = array( 'key' => $name, 'plugin' => $name, 'protected' => false, 'target' => (string) $target, 'off' => false, 'inherited' => null, 'badge' => null, 'files' => array(), 'suggestion' => $s );
			} else {
				$files[] = array( 'target' => (string) $target, 'kind' => $kind, 'handle' => $name, 'src' => '', 'size' => null, 'badge' => 'not_measured', 'protected' => false, 'off' => false, 'inherited' => null, 'also' => array(), 'suggestion' => $s );
			}
		}
		if ( $files ) {
			$groups[] = array( 'key' => '__suggested', 'plugin' => '', 'protected' => false, 'target' => null, 'off' => false, 'inherited' => null, 'badge' => null, 'files' => $files, 'suggestion' => null );
		}
		return $groups;
	}

	/**
	 * Pure: dismissed suggestions, one row per target (dismissed everywhere: page '') or per target and page type,
	 * sorted by target then page. $rejected: [ target => time ]; $rejected_pages: [ target => [ page, … ] ].
	 * @return array<int,array{target:string,page:string,label:string}>
	 */
	public static function dismissed_rows( array $rejected, array $rejected_pages ): array {
		$rows = array();
		foreach ( array_keys( $rejected ) as $t ) {
			$rows[] = array( 'target' => (string) $t, 'page' => '', 'label' => __( 'everywhere', 'beltoft-performance' ) );
		}
		foreach ( $rejected_pages as $t => $pages ) {
			foreach ( array_unique( array_map( 'strval', (array) $pages ) ) as $p ) {
				$rows[] = array( 'target' => (string) $t, 'page' => $p, 'label' => self::page_type_label( $p ) );
			}
		}
		usort( $rows, static fn( $a, $b ) => array( $a['target'], $a['page'] ) <=> array( $b['target'], $b['page'] ) );
		return $rows;
	}

	/** Pure: [ rejected, rejected_pages ] with one dismissal undone ($page '' = the everywhere dismissal); empty targets dropped. */
	public static function restore_dismissed( array $rejected, array $rejected_pages, string $target, string $page ): array {
		if ( '' === $page ) {
			unset( $rejected[ $target ] );
		} elseif ( isset( $rejected_pages[ $target ] ) ) {
			$left = array_values( array_diff( (array) $rejected_pages[ $target ], array( $page ) ) );
			if ( $left ) {
				$rejected_pages[ $target ] = $left;
			} else {
				unset( $rejected_pages[ $target ] );
			}
		}
		return array( $rejected, array_filter( $rejected_pages, static fn( $p ) => (bool) $p ) );
	}

	/** Pure: what Bricks handles (label → on), from bricks_global_settings. "On" means Bricks takes care of it. */
	public static function bricks_states( array $g ): array {
		return array(
			__( 'Emojis', 'beltoft-performance' )         => ! empty( $g['disableEmojis'] ),
			__( 'Embeds', 'beltoft-performance' )         => ! empty( $g['disableEmbed'] ),
			__( 'jQuery Migrate', 'beltoft-performance' ) => ! empty( $g['disableJqueryMigrate'] ),
			__( 'Lazy loading', 'beltoft-performance' )   => empty( $g['disableLazyLoad'] ),
		);
	}
}
