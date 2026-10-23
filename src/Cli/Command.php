<?php
namespace BeltoftPerformance\Cli;

use BeltoftPerformance\Check\BrowserEvidence;
use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Check\Signature;
use BeltoftPerformance\Check\Trial;
use BeltoftPerformance\Check\Usage;
use BeltoftPerformance\Loader\LoaderManager;
use BeltoftPerformance\Rules\Compiler;
use BeltoftPerformance\Rules\PerfmattersImport;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;
use BeltoftPerformance\Support\Owner;

defined( 'ABSPATH' ) || exit;

/** Performance: check, proposals and rules. */
final class Command {
	/** Pure: errors seen with the trial that were not already seen without it (bp_check query value ignored). */
	public static function new_errors( array $with, array $without ): array {
		$norm = static fn( $e ) => preg_replace( '/([?&]bp_check=)[^&\s"\']*/', '$1', (string) $e );
		$seen = array_flip( array_map( $norm, $without ) );
		return array_values( array_filter( $with, static fn( $e ) => ! isset( $seen[ $norm( $e ) ] ) ) );
	}

	/** Pure: true when a node run proves nothing (browser died, navigation failed, pages missing, status 0). */
	public static function run_failed( array $result, int $expected_pages ): bool {
		foreach ( (array) ( $result['errors'] ?? array() ) as $e ) {
			if ( 1 === preg_match( '/^(\d+ \S+ )?(fatal|goto):/', (string) $e ) ) {
				return true;
			}
		}
		$pages = (array) ( $result['pages'] ?? array() );
		if ( ! $pages || count( $pages ) !== $expected_pages ) {
			return true;
		}
		foreach ( $pages as $p ) {
			if ( 0 === (int) ( $p['status'] ?? 0 ) ) {
				return true;
			}
		}
		return false;
	}

	/** Pure: per url+viewport, a status that differs between the trial run and the baseline run. */
	public static function status_changes( array $with, array $without ): array {
		$base = array();
		foreach ( (array) ( $without['pages'] ?? array() ) as $p ) {
			$base[ ( $p['viewport'] ?? '' ) . ' ' . ( $p['url'] ?? '' ) ] = (int) ( $p['status'] ?? 0 );
		}
		$out = array();
		foreach ( (array) ( $with['pages'] ?? array() ) as $p ) {
			$k = ( $p['viewport'] ?? '' ) . ' ' . ( $p['url'] ?? '' );
			if ( isset( $base[ $k ] ) && $base[ $k ] !== (int) ( $p['status'] ?? 0 ) ) {
				$out[] = $k . ' status: ' . $base[ $k ] . ' -> ' . (int) ( $p['status'] ?? 0 );
			}
		}
		return $out;
	}

	/** Pure: the row problem ('' when fine) for one job, from the two baseline runs and the trial run (each a run result with pages), matched by viewport. */
	public static function job_problem( array $base1, array $base2, array $trial, bool $flow ): string {
		$by = static function ( array $run ): array {
			$m = array();
			foreach ( (array) ( $run['pages'] ?? array() ) as $p ) {
				$m[ (int) ( $p['viewport'] ?? 0 ) ] = (array) $p;
			}
			return $m;
		};
		$b1 = $by( $base1 );
		$b2 = $by( $base2 );
		$problems = array();
		$layout   = array();
		$facts    = array();
		$broken   = false;
		$flowdead = false;
		$unstable = false;
		$cut      = false;
		foreach ( array( $base1, $base2, $trial ) as $run ) {
			foreach ( (array) ( $run['pages'] ?? array() ) as $p ) {
				$cut = $cut || ! empty( $p['layout_truncated'] ); // a cut signature hides whatever lies past the cut
			}
		}
		foreach ( $by( $trial ) as $vp => $t ) {
			$a = $b1[ $vp ] ?? array();
			$b = $b2[ $vp ] ?? array();
			if ( BrowserEvidence::layout_failed( $t ) ) {
				$broken = true;
				continue;
			}
			if ( BrowserEvidence::noise_ratio( (array) ( $a['layout'] ?? array() ), (array) ( $b['layout'] ?? array() ) ) > BrowserEvidence::MAX_NOISE ) {
				$unstable = true; // the mask would hide too much of the page
				continue;
			}
			foreach ( BrowserEvidence::layout_changes( (array) ( $a['layout'] ?? array() ), (array) ( $b['layout'] ?? array() ), (array) ( $t['layout'] ?? array() ) ) as $ch ) {
				$layout[] = $vp . ' ' . $ch;
			}
			if ( $flow ) {
				if ( BrowserEvidence::flow_failed( $t ) ) {
					$flowdead = true;
				} else {
					foreach ( BrowserEvidence::flow_fact_changes( (array) ( $a['facts'] ?? array() ), (array) ( $t['facts'] ?? array() ) ) as $ch ) {
						$facts[] = $vp . ' ' . $ch;
					}
				}
			}
		}
		if ( $broken ) {
			$problems[] = __( 'Could not measure the page layout in the trial.', 'beltoft-performance' );
		}
		if ( $cut ) {
			$problems[] = __( 'Page too large to measure the full layout.', 'beltoft-performance' );
		}
		if ( $unstable ) {
			$problems[] = __( 'Page too unstable to judge: repeat the trial.', 'beltoft-performance' );
		}
		if ( $layout ) {
			/* translators: %s: list of changed elements */
			$problems[] = sprintf( __( 'The page layout changed: %s', 'beltoft-performance' ), implode( '; ', array_slice( $layout, 0, 5 ) ) );
		}
		if ( $flowdead ) {
			$problems[] = __( 'Cart/checkout stopped working in the trial.', 'beltoft-performance' );
		}
		if ( $facts ) {
			/* translators: %s: list of changes */
			$problems[] = sprintf( __( 'Checkout changed: %s', 'beltoft-performance' ), implode( '; ', $facts ) );
		}
		return implode( ' ', $problems );
	}

	/** Pure: the browser result as kept in the trials option — per job only the verdict (new errors, problem, trial active); the raw runs (layout per element, several MB per target) are printed but never stored, or the option outgrows max_allowed_packet. */
	public static function stored_browser_result( array $res ): array {
		$jobs = array();
		foreach ( (array) ( $res['jobs'] ?? array() ) as $key => $job ) {
			$jobs[ $key ] = array(
				'new_errors'   => (array) ( $job['new_errors'] ?? array() ),
				'problem'      => (string) ( $job['problem'] ?? '' ),
				'trial_active' => ! empty( $job['trial_active'] ),
			);
		}
		return array( 'jobs' => $jobs, 'new_errors' => (array) ( $res['new_errors'] ?? array() ), 'ok' => ! empty( $res['ok'] ) );
	}

	/** Pure: folds the browser pass into the evidence rows (job problems and inactive trials become row problems, clean browsed rows stop needing the browser) and says whether every row is now fine. */
	public static function merge_browser_results( array $evidence, array $problems, array $bad, array $browsed ): array {
		$ev = array();
		foreach ( $evidence as $row ) {
			$key = ( $row['page'] ?? '' ) . '|' . ( $row['state'] ?? '' );
			if ( ! empty( $problems[ $key ] ) ) {
				$row['problem'] = trim( ( empty( $row['problem'] ) ? '' : $row['problem'] . ' ' ) . $problems[ $key ] );
			}
			if ( ! empty( $bad[ $key ] ) && empty( $row['problem'] ) ) {
				$row['problem'] = __( 'The browser test did not run.', 'beltoft-performance' );
			}
			if ( ! empty( $row['needs_browser'] ) && ! empty( $browsed[ $key ] ) ) {
				unset( $row['needs_browser'] );
			}
			$ev[] = $row;
		}
		$ok = (bool) $ev;
		foreach ( $ev as $row ) {
			$ok = $ok && empty( $row['problem'] ) && empty( $row['needs_browser'] );
		}
		return array( 'evidence' => $ev, 'ok' => $ok );
	}

	/**
	 * [--pages=<list>] [--states=<list>] [--new]
	 *
	 * Without --pages: a new check of every kind of page. With --pages: only those page types are checked again,
	 * inside the last check (the other pages, suggestions and tests keep their results); --new starts a fresh
	 * check of just those pages instead.
	 */
	public function check( $args, $assoc ): void {
		$pages  = array_values( array_filter( explode( ',', (string) ( $assoc['pages'] ?? '' ) ) ) );
		$states = array_values( array_filter( explode( ',', (string) ( $assoc['states'] ?? 'guest,cart,logged_in' ) ) ) );
		$last   = (array) get_option( 'beltoft_performance_last_check', array() );
		$merge  = $pages && empty( $assoc['new'] ) && ! empty( $last['id'] );
		$id     = $merge ? (string) $last['id'] : Runner::new_id();
		if ( Runner::progress_view( (array) get_option( Runner::PROGRESS, array() ), time() ) ) {
			\WP_CLI::error( 'A check is already running; try again when it is done.' );
		}
		if ( $merge ) {
			\WP_CLI::log( 'Re-checking ' . implode( ',', $pages ) . ' inside check ' . $id . ' (use --new for a fresh check of just these pages).' );
		}
		$dir_error = Owner::writable_error( wp_upload_dir()['basedir'] . '/beltoft-performance' );
		if ( null !== $dir_error ) {
			\WP_CLI::error( $dir_error );
		}
		$done   = Runner::run( $id, $pages, $states, $merge );
		if ( ! $done ) {
			\WP_CLI::error( 'Nothing was checked: another check holds the lock, or the last check changed meanwhile.' );
		}
		\WP_CLI\Utils\format_items( 'table', $done, array( 'page', 'state', 'code' ) );
		\WP_CLI::success( "check $id" );
	}

	/**
	 * [--dry-run]
	 *
	 * The nightly database clean-up now (Settings › Database): old revisions, auto-drafts, spam/trash comments,
	 * expired transients. --dry-run only counts.
	 *
	 * @subcommand db-cleanup
	 */
	public function db_cleanup( $args, $assoc ): void {
		$dry = ! empty( $assoc['dry-run'] );
		$res = \BeltoftPerformance\Cleanup\Database::run( $dry );
		foreach ( $res as $k => $n ) {
			\WP_CLI::line( str_pad( $k, 20 ) . $n );
		}
		\WP_CLI::success( $dry ? 'dry run: nothing removed' : 'cleaned' );
	}

	public function status(): void {
		$l    = LoaderManager::status( LoaderManager::default_dir() );
		$last = (array) get_option( 'beltoft_performance_last_check', array() );
		\WP_CLI::line( 'loader: ' . $l['state'] . ' ' . $l['version'] );
		\WP_CLI::line( 'rules: ' . count( Store::all() ) . ' | last check: ' . ( $last['id'] ?? '-' ) );
		foreach ( Store::all() as $r ) {
			$never = $r->owner_override ? null : Store::never_accept_error( $r->target );
			if ( null !== $never ) {
				\WP_CLI::warning( $r->target . ' (' . implode( ',', $r->pages ) . '): ' . $never . ' Review and remove this rule.' );
			}
		}
		$groups = \BeltoftPerformance\Admin\Proposals::group( Runner::candidates( (string) ( $last['id'] ?? '' ) ) );
		foreach ( array( 'plugins', 'files' ) as $g ) {
			foreach ( $groups[ $g ] as $c ) {
				\WP_CLI::line( 'candidate ' . $c['target'] . ' on ' . implode( ',', $c['pages'] ) . ( 'files' === $g && ! empty( $c['caveat'] ) ? ' [' . $c['caveat'] . ']' : '' ) );
			}
		}
	}

	public function rules(): void {
		\WP_CLI\Utils\format_items( 'table', array_map( static fn( $r ) => array_merge( $r->to_array(), array( 'manual' => $r->is_manual() ? implode( ',', $r->manual_pages ) : '', 'key' => $r->key() ) ), Store::all() ), array( 'target', 'pages', 'manual', 'approved_by', 'key' ) );
	}

	/** <target> [--pages=<list>] */
	public function trial( $args, $assoc = array() ): void {
		$last = (array) get_option( 'beltoft_performance_last_check', array() );
		foreach ( Runner::candidates( (string) ( $last['id'] ?? '' ) ) as $c ) {
			if ( $c['target'] === $args[0] ) {
				$pages = self::accept_pages( (array) $c['pages'], (string) ( $assoc['pages'] ?? '' ) );
				if ( is_string( $pages ) ) {
					\WP_CLI::error( $pages );
				}
				$c                 = Runner::narrow( $c, $pages );
				$res               = Trial::run( $last['id'], $c );
				$t                 = (array) get_option( 'beltoft_performance_trials', array() );
				$t[ $c['target'] ] = Trial::merge( is_array( $t[ $c['target'] ] ?? null ) ? $t[ $c['target'] ] : null, $res + array( 'check' => $last['id'], 'time' => time(), 'pages' => $c['pages'] ) );
				update_option( 'beltoft_performance_trials', $t, false );
				\WP_CLI::print_value( $res, array( 'format' => 'json' ) );
				return;
			}
		}
		\WP_CLI::error( 'not a current candidate' );
	}

	/** Pure: adds --chrome=<path> to the options the browser script receives, when given. */
	public static function with_chrome( array $opts, array $assoc ): array {
		$chrome = (string) ( $assoc['chrome'] ?? '' );
		if ( '' !== $chrome ) {
			$opts['chrome'] = $chrome;
		}
		return $opts;
	}

	/** Pure: the node command line — no signed header in it (argv is visible to every local user in ps). */
	public static function node_argv( string $node, string $script, string $pw, string $url, array $cookies, array $opts ): array {
		return array( $node, $script, $pw, (string) wp_json_encode( array( $url ) ), (string) wp_json_encode( (object) $cookies ), (string) wp_json_encode( (object) $opts ) );
	}

	/** Pure: the environment for node: this process's plus BP_CHECK_HEADER (the signed X-BP-Check header). */
	public static function node_env( array $env, string $header ): array {
		$env['BP_CHECK_HEADER'] = $header;
		return $env;
	}

	/** Signs a fresh header (it expires after 120 s) and runs the browser script once; returns the decoded result. */
	private function run_node( array $payload, string $url, array $cookies, array $opts, string $node, string $pw ): array {
		$argv = self::node_argv( $node, BELTOFT_PERFORMANCE_DIR . '/bin/browser-check.js', $pw, $url, $cookies, $opts );
		$proc = proc_open( $argv, array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes, null, self::node_env( (array) getenv(), Signature::header_for( $payload, $url ) ) );
		$out  = '';
		if ( is_resource( $proc ) ) {
			$out = (string) stream_get_contents( $pipes[1] );
			$err = (string) stream_get_contents( $pipes[2] );
			fclose( $pipes[1] );
			fclose( $pipes[2] );
			proc_close( $proc );
			if ( '' === trim( $out ) ) {
				$out = $err;
			}
		}
		$res = json_decode( $out, true );
		if ( ! is_array( $res ) ) {
			\WP_CLI::error( 'browser check failed: ' . $out );
		}
		return $res;
	}

	/** [--pages=<list>] [--states=<list>] [--node=<path>] [--pw=<path>] [--chrome=<path>] */
	public function usage( $args, $assoc ): void {
		$last    = (array) get_option( 'beltoft_performance_last_check', array() );
		$cid     = (string) ( $last['id'] ?? '' );
		$inv     = Runner::inventory( $cid );
		$targets = Runner::targets();
		$jar     = (array) get_option( 'beltoft_performance_cart_cookies_' . $cid, array() );
		$node    = (string) ( $assoc['node'] ?? '/usr/bin/node' );
		$pw      = (string) ( $assoc['pw'] ?? '' );
		$pages   = array_filter( explode( ',', (string) ( $assoc['pages'] ?? '' ) ) );
		$states  = array_filter( explode( ',', (string) ( $assoc['states'] ?? '' ) ) );
		$rows    = array();
		foreach ( $inv as $page => $have ) {
			if ( empty( $targets[ $page ] ) || ( $pages && ! in_array( $page, $pages, true ) ) ) {
				continue;
			}
			foreach ( array_keys( $have ) as $state ) {
				if ( $states && ! in_array( $state, $states, true ) ) {
					continue;
				}
				$res = $this->run_node( array( 'id' => $cid, 'state' => $state ), $targets[ $page ], 'cart' === $state ? $jar : array(), self::with_chrome( array( 'mode' => 'usage' ), $assoc ), $node, $pw );
				if ( self::run_failed( $res, 2 ) ) {
					\WP_CLI::error( "usage run failed for $page|$state: " . wp_json_encode( $res['errors'] ?? array() ) );
				}
				Usage::store( $cid, $page, $state, $res );
				$cov = BrowserEvidence::coverage_by_src( (array) $res['pages'] );
				$rows[] = array( 'page' => $page, 'state' => $state, 'styles' => count( $cov['style'] ), 'scripts' => count( $cov['script'] ) );
			}
		}
		if ( ! $rows ) {
			\WP_CLI::error( 'no pages to measure' );
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'page', 'state', 'styles', 'scripts' ) );
		\WP_CLI::success( "usage $cid" );
	}

	/** Pure: the persistent-cart meta key to empty for this user — only when it is the configured QA customer (same guard as the Recorder), else ''. */
	public static function qa_cart_meta_key( array $cfg, string $login, array $roles, bool $can_edit_posts, string $email, int $blog_id ): string {
		if ( '' === $login || $login !== (string) ( $cfg['login'] ?? '' ) || ! \BeltoftPerformance\Check\Recorder::qa_user_ok( $roles, $can_edit_posts, $email, $cfg ) ) {
			return '';
		}
		return '_woocommerce_persistent_cart_' . $blog_id;
	}

	/** Empties the QA customer's saved cart so the logged-in flow starts from a known cart. Never touches anyone else. */
	private static function empty_qa_cart(): void {
		$cfg  = (array) get_option( 'beltoft_performance_qa_user', array() );
		$user = ! empty( $cfg['login'] ) ? get_user_by( 'login', (string) $cfg['login'] ) : false;
		if ( ! $user ) {
			return;
		}
		$key = self::qa_cart_meta_key( $cfg, (string) $user->user_login, (array) $user->roles, $user->has_cap( 'edit_posts' ), (string) $user->user_email, get_current_blog_id() );
		if ( '' !== $key ) {
			delete_user_meta( $user->ID, $key );
		}
	}

	/** <target> [--node=<path>] [--pw=<path>] [--chrome=<path>] */
	public function browser( $args, $assoc ): void {
		$last = (array) get_option( 'beltoft_performance_last_check', array() );
		$t    = (array) get_option( 'beltoft_performance_trials', array() );
		$cid  = (string) ( $last['id'] ?? '' );
		$c    = null;
		foreach ( Runner::candidates( $cid ) as $x ) {
			if ( $x['target'] === $args[0] ) {
				$c = $x;
			}
		}
		$trial = (array) ( $t[ $args[0] ] ?? array() );
		if ( ! $c || ! $trial || ( $trial['check'] ?? '' ) !== $cid || ! isset( $trial['pages'] ) ) {
			\WP_CLI::error( 'run trial first' );
		}
		$targets = Runner::targets();
		$jar     = (array) get_option( 'beltoft_performance_cart_cookies_' . $cid, array() );
		$rule    = array( 'target' => $c['target'], 'pages' => $c['pages'], 'hosts' => array( 'retail' ) );
		$node    = (string) ( $assoc['node'] ?? '/usr/bin/node' );
		$pw      = (string) ( $assoc['pw'] ?? '' );
		$product  = Runner::flow_product_url();
		$run      = fn( array $payload, string $url, array $cookies, string $mode ) => $this->run_node( $payload, $url, $cookies, self::with_chrome( array( 'mode' => $mode, 'product_url' => $product ), $assoc ), $node, $pw );

		// One job per (page, state) present in the stored trial evidence.
		$jobs = array();
		foreach ( (array) ( $trial['evidence'] ?? array() ) as $row ) {
			$page  = (string) ( $row['page'] ?? '' );
			$state = (string) ( $row['state'] ?? '' );
			if ( '' !== $state && ! empty( $targets[ $page ] ) ) {
				$jobs[ $page . '|' . $state ] = array( $page, $state );
			}
		}
		if ( ! $jobs ) {
			\WP_CLI::error( 'no browsable pages in the trial' );
		}
		foreach ( $jobs as list( $page, $state ) ) {
			if ( 'logged_in' === $state && in_array( $page, array( 'cart', 'checkout' ), true ) ) {
				self::empty_qa_cart();
				break;
			}
		}
		$new      = array();
		$bad      = array(); // page|state => reason (trial not active)
		$browsed  = array(); // page|state => true when browsed without new errors
		$problems = array(); // page|state => layout/flow problem
		$results  = array();
		foreach ( $jobs as $key => list( $page, $state ) ) {
			$cookies = 'cart' === $state ? $jar : array();
			$url     = $targets[ $page ];
			$flow    = in_array( $page, array( 'cart', 'checkout' ), true );
			$mode    = $flow ? 'flow' : 'nav';
			$payload = array( 'id' => $cid, 'state' => $state );
			$without = $run( $payload, $url, $cookies, $mode ); // baseline: same job, no trial
			$noise   = $run( $payload, $url, $cookies, $mode ); // second baseline: tells noise from change
			foreach ( array( 'baseline' => $without, 'second baseline' => $noise ) as $label => $r ) {
				if ( self::run_failed( $r, 2 ) ) {
					\WP_CLI::error( "browser $label failed for $key: " . wp_json_encode( $r['errors'] ?? array() ) );
				}
				foreach ( (array) $r['pages'] as $p ) {
					if ( BrowserEvidence::layout_failed( (array) $p ) ) {
						\WP_CLI::error( "browser $label layout failed for $key: " . wp_json_encode( $p['layout_error'] ?? 'empty layout' ) );
					}
					if ( $flow && BrowserEvidence::flow_failed( (array) $p ) ) {
						\WP_CLI::error( "checkout flow does not work in the $label for $key (fix the test, not the rule): " . wp_json_encode( $p['flow_error'] ?? '' ) );
					}
				}
			}
			$file = Runner::dir( $cid ) . '/' . Trial::report_name( $page, $state, $rule );
			if ( is_file( $file ) ) {
				@unlink( $file );
			}
			$start = time();
			$with  = $run( $payload + array( 'trial' => array( $rule ) ), $url, $cookies, $mode );
			if ( self::run_failed( $with, 2 ) ) {
				\WP_CLI::error( "browser trial run failed for $key: " . wp_json_encode( $with['errors'] ?? array() ) );
			}
			$job_new = array_merge( self::new_errors( (array) $with['errors'], (array) $without['errors'] ), self::status_changes( $with, $without ) );
			$problem = self::job_problem( $without, $noise, $with, $flow );
			clearstatcache( true, $file );
			if ( ! is_file( $file ) || filemtime( $file ) < $start ) {
				$bad[ $key ] = true; // the Recorder never saw the trial rule
			}
			if ( '' !== $problem ) {
				$problems[ $key ] = $problem;
			}
			if ( ! $job_new && '' === $problem && empty( $bad[ $key ] ) ) {
				$browsed[ $key ] = true;
			}
			$new[]            = array_map( static fn( $e ) => "$key $e", $job_new );
			$results[ $key ] = array( 'with' => $with, 'without' => $without, 'new_errors' => $job_new, 'problem' => $problem, 'trial_active' => empty( $bad[ $key ] ) );
		}
		$new = array_merge( ...$new );

		$merged = self::merge_browser_results( (array) ( $trial['evidence'] ?? array() ), $problems, $bad, $browsed );
		$ev     = $merged['evidence'];
		$ok     = $merged['ok'];
		$res = array( 'jobs' => $results, 'new_errors' => $new, 'ok' => $ok && ! $new );
		$t[ $args[0] ]['browser']  = self::stored_browser_result( $res );
		$t[ $args[0] ]['evidence'] = $ev;
		// The browser pass counts for the pages the trial covered, as long as they are still pages of the candidate.
		$tested                    = array_map( 'strval', (array) ( $t[ $args[0] ]['pages'] ?? array() ) );
		$t[ $args[0] ]['browser']['pages'] = $tested; // Trial::merge keeps it only while none of these pages is tested again
		$t[ $args[0] ]['browser']['ok'] = ! empty( $t[ $args[0] ]['browser']['ok'] ) && ! $new;
		$t[ $args[0] ]             = Trial::with_passed( $t[ $args[0] ] ); // per page: a page passes with its rows clean and this browser pass
		$t[ $args[0] ]['ok']       = $t[ $args[0] ]['ok'] && $res['ok'] && $tested && ! array_diff( $tested, (array) $c['pages'] );
		update_option( 'beltoft_performance_trials', $t, false );
		\WP_CLI::print_value( $res, array( 'format' => 'json' ) );
	}

	/** Pure: the pages to accept — every candidate page when $arg is '', else the listed subset; an error string for a page outside the candidate or an empty list. */
	public static function accept_pages( array $candidate_pages, string $arg ) {
		if ( '' === trim( $arg ) ) {
			return array_values( $candidate_pages );
		}
		$want = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', $arg ) ) ) ) );
		if ( ! $want ) {
			return __( 'Page list is empty.', 'beltoft-performance' );
		}
		$out = array_values( array_diff( $want, $candidate_pages ) );
		if ( $out ) {
			/* translators: %s: page types */
			return sprintf( __( 'Pages outside the proposal: %s', 'beltoft-performance' ), implode( ', ', $out ) );
		}
		return array_values( array_intersect( $candidate_pages, $want ) );
	}

	/** <target> [--by=<login>] [--pages=<list>] */
	public function accept( $args, $assoc ): void {
		$refused = Store::accept_error( (string) ( $args[0] ?? '' ) );
		if ( null !== $refused ) {
			\WP_CLI::error( $refused );
		}
		$t    = (array) get_option( 'beltoft_performance_trials', array() );
		$last = (array) get_option( 'beltoft_performance_last_check', array() );
		foreach ( Runner::candidates( (string) ( $last['id'] ?? '' ) ) as $c ) {
			if ( $c['target'] === $args[0] ) {
				$pages = self::accept_pages( (array) $c['pages'], (string) ( $assoc['pages'] ?? '' ) );
				if ( is_string( $pages ) ) {
					\WP_CLI::error( $pages );
				}
				if ( ! Trial::approves( (array) ( $t[ $args[0] ] ?? array() ), (string) ( $last['id'] ?? '' ), $pages ) ) {
					\WP_CLI::error( 'trial has not passed on these pages (or is not current)' );
				}
				$c   = Runner::narrow( $c, $pages );
				$res = Store::add( new Rule( $c['target'], $c['pages'], array( 'retail' ), implode( ' · ', (array) $c['why'] ), (string) ( $assoc['by'] ?? 'cli' ), gmdate( 'c' ) ) );
				$error = Compiler::change_error( $res, Compiler::compile_from_store() );
				if ( true === $res && ! array_diff( array_map( 'strval', (array) ( $t[ $args[0] ]['pages'] ?? array() ) ), $pages ) ) {
					unset( $t[ $args[0] ] ); // off on every tested page: used up (a part keeps it for the other pages, like the admin screen)
					update_option( 'beltoft_performance_trials', $t, false );
				}
				if ( null !== $error ) {
					\WP_CLI::error( $error );
				}
				\WP_CLI::success( 'accepted' );
				return;
			}
		}
		\WP_CLI::error( 'not a current candidate' );
	}

	/** <target> */
	public function reject( $args ): void {
		$rej             = (array) get_option( 'beltoft_performance_rejected', array() );
		$rej[ $args[0] ] = time();
		update_option( 'beltoft_performance_rejected', $rej, false );
		\WP_CLI::success( 'rejected' );
	}

	/**
	 * <target> --scope=<scope>[,<scope>] --by=<login> [--tested-by-hand] [--override-protection]
	 *
	 * --tested-by-hand is required to switch a plugin off on cart, checkout, order received or account
	 * (unless it has a passed trial and browser test there).
	 *
	 * --override-protection: the owner switches off a never-removed plugin (cache, security, e-mail, analytics)
	 * knowing what it does; the rule records it. WooCommerce and this plugin can never be switched off.
	 *
	 * @subcommand switch-off
	 */
	public function switch_off( $args, $assoc ): void {
		$target = (string) ( $args[0] ?? '' );
		$scopes = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['scope'] ?? '' ) ) ) ) );
		$by     = trim( (string) ( $assoc['by'] ?? '' ) );
		if ( '' === $target || ! $scopes || '' === $by ) {
			\WP_CLI::error( 'Usage: switch-off <target> --scope=<scope>[,<scope>] --by=<login> (all required)' );
		}
		if ( 0 === strpos( $target, 'script:' ) ) {
			\WP_CLI::warning( 'Scripts are not covered by the load-time proof: check clicks by hand.' );
		}
		$last   = (array) get_option( 'beltoft_performance_last_check', array() );
		$routes = \BeltoftPerformance\Request\RouteMap::build();
		$trials = (array) get_option( 'beltoft_performance_trials', array() );
		foreach ( $scopes as $scope ) {
			$norm = Store::normalise_scope( $scope ) ?? $scope;
			$flow = \BeltoftPerformance\Admin\Manager::flow_refusals( array( $target ), $norm, \BeltoftPerformance\Admin\Manager::scope_page( $norm, $routes ), ! empty( $assoc['tested-by-hand'] ), $trials, (string) ( $last['id'] ?? '' ) );
			if ( $flow ) {
				\WP_CLI::error( $flow[ $target ] . ' (--tested-by-hand)' );
			}
		}
		$res   = Store::switch_off( $target, $scopes, $by, ! empty( $last['id'] ) ? Runner::inventory( (string) $last['id'] ) : array(), '', ! empty( $assoc['override-protection'] ) );
		$error = Compiler::change_error( $res, Compiler::compile_from_store() );
		if ( null !== $error ) {
			\WP_CLI::error( $error );
		}
		\WP_CLI::success( 'switched off' );
	}

	/**
	 * <target> --scope=<scope>[,<scope>]
	 *
	 * @subcommand switch-on
	 */
	public function switch_on( $args, $assoc ): void {
		$scopes = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $assoc['scope'] ?? '' ) ) ) ) );
		if ( empty( $args[0] ) || ! $scopes ) {
			\WP_CLI::error( 'Usage: switch-on <target> --scope=<scope>[,<scope>]' );
		}
		$res   = Store::switch_on( (string) $args[0], $scopes );
		$error = Compiler::change_error( $res, Compiler::compile_from_store() );
		if ( null !== $error ) {
			\WP_CLI::error( $error );
		}
		\WP_CLI::success( 'switched on' );
	}

	/**
	 * Converts Perfmatters' Script Manager removals (perfmatters_script_manager) into manual rules before Perfmatters is
	 * switched off. Protected, never-accept, unknown and inexpressible entries are skipped and listed. Refused unless
	 * Perfmatters' removals are live (active, Script Manager on, testing mode and safe mode off) — --force overrides.
	 * --undo removes the manual scopes of every rule whose reason is the import reason.
	 *
	 * --by=<login> [--dry-run] [--force] | --undo [--dry-run]
	 *
	 * @subcommand import-perfmatters
	 */
	public function import_perfmatters( $args, $assoc ): void {
		$by    = trim( (string) ( $assoc['by'] ?? '' ) );
		$dry   = ! empty( $assoc['dry-run'] );
		$force = ! empty( $assoc['force'] );
		$undo  = ! empty( $assoc['undo'] );
		if ( '' === $by && ! $undo ) {
			\WP_CLI::error( 'Usage: import-perfmatters --by=<login> [--dry-run] [--force] | --undo [--dry-run]' );
		}
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$state = PerfmattersImport::live_state( is_plugin_active( 'perfmatters/perfmatters.php' ), get_option( 'perfmatters_tools', array() ), get_option( 'perfmatters_script_manager_settings', array() ) );
		\WP_CLI::log( PerfmattersImport::live_line( $state ) );
		if ( $undo ) {
			$this->undo_perfmatters( $dry );
			return;
		}
		$check = PerfmattersImport::live_check( $state, $force );
		if ( null !== $check['refuse'] ) {
			\WP_CLI::error( $check['refuse'] );
		}
		foreach ( $check['warnings'] as $w ) {
			\WP_CLI::warning( $w );
		}
		$option = get_option( 'perfmatters_script_manager', array() );
		if ( ! is_array( $option ) || empty( $option['disabled'] ) ) {
			\WP_CLI::success( 'Perfmatters Script Manager has no entries: nothing to import.' );
			return;
		}
		$last  = (array) get_option( 'beltoft_performance_last_check', array() );
		$inv   = ! empty( $last['id'] ) ? Runner::inventory( (string) $last['id'] ) : array();
		$known = self::known_files( $inv );
		$plan  = PerfmattersImport::plan(
			$option,
			array(
				'posts'     => self::import_posts( PerfmattersImport::post_ids( $option ) ),
				'type_urls' => self::type_urls(),
				'known'     => $known,
				'plugins'   => self::installed_plugins(),
			)
		);
		// switch_off checks file handles against the inventory: the files registered in this process count too.
		$assets = array();
		foreach ( $known as $kind => $files ) {
			foreach ( $files as $h => $f ) {
				$assets[] = array( 'kind' => $kind, 'handle' => (string) $h, 'src' => (string) $f['src'], 'plugin' => (string) $f['plugin'] );
			}
		}
		$inv['__registered']['cli'] = array( 'assets' => $assets );
		$imported = 0;
		foreach ( $plan as $i => $row ) {
			$plan[ $i ]['result'] = $dry ? '' : '-';
			if ( 'import' !== $row['action'] ) {
				continue;
			}
			if ( $dry ) {
				++$imported;
				continue;
			}
			$res                  = Store::switch_off( $row['target'], $row['scopes'], $by, $inv, PerfmattersImport::REASON );
			$plan[ $i ]['result'] = true === $res ? 'imported' : (string) $res;
			$imported            += true === $res ? 1 : 0;
		}
		$table = array_map( static fn( $r ) => array( 'perfmatters' => $r['source'], 'action' => $r['action'], 'target' => $r['target'], 'scopes' => self::scopes_cell( $r['scopes'] ), 'urls' => $r['urls'], 'result' => $r['result'], 'note' => $r['note'] ), $plan );
		\WP_CLI\Utils\format_items( 'table', $table, $dry ? array( 'perfmatters', 'action', 'target', 'scopes', 'urls', 'note' ) : array( 'perfmatters', 'action', 'target', 'scopes', 'urls', 'result', 'note' ) );
		$skipped = count( array_filter( $plan, static fn( $r ) => 'skip' === $r['action'] ) );
		$scripts = array_column( array_filter( $plan, static fn( $r ) => ! empty( $r['scripts'] ) ), 'target' );
		if ( $scripts ) {
			\WP_CLI::warning( 'Scripts are not covered by the load-time proof: check clicks by hand (' . implode( ',', $scripts ) . ').' );
		}
		foreach ( $check['warnings'] as $w ) {
			\WP_CLI::warning( $w ); // repeated under the table, where it is read
		}
		if ( $dry ) {
			\WP_CLI::success( "dry run: $imported to import, $skipped skipped (nothing written)" );
			return;
		}
		$error = Compiler::change_error( true, Compiler::compile_from_store() );
		if ( null !== $error ) {
			\WP_CLI::error( $error );
		}
		\WP_CLI::success( "imported $imported, skipped $skipped" );
	}

	/** --undo: removes the manual scopes of every imported rule (reason = the import reason). */
	private function undo_perfmatters( bool $dry ): void {
		if ( $dry ) {
			$plan    = PerfmattersImport::undo_plan( Store::all() );
			$removed = count( array_filter( $plan, static fn( $r ) => 'remove' === $r['action'] ) );
		} else {
			$res     = PerfmattersImport::undo( Store::all() );
			$plan    = $res['plan'];
			$removed = $res['removed'];
		}
		$table = array_map( static fn( $r ) => array( 'target' => $r['target'], 'action' => $r['action'], 'scopes' => self::scopes_cell( $r['scopes'] ), 'result' => (string) ( $r['result'] ?? '' ), 'note' => $r['note'] ), $plan );
		if ( $table ) {
			\WP_CLI\Utils\format_items( 'table', $table, $dry ? array( 'target', 'action', 'scopes', 'note' ) : array( 'target', 'action', 'scopes', 'result', 'note' ) );
		}
		if ( $dry ) {
			\WP_CLI::success( "dry run: $removed imported rules to remove (nothing written)" );
			return;
		}
		$error = Compiler::change_error( true, Compiler::compile_from_store() );
		if ( null !== $error ) {
			\WP_CLI::error( $error );
		}
		\WP_CLI::success( "removed $removed imported rules" );
	}

	/** Page types in full; more than 3 url: scopes are counted, not listed. */
	private static function scopes_cell( array $scopes ): string {
		$urls  = array_values( array_filter( $scopes, static fn( $s ) => 0 === strpos( (string) $s, 'url:' ) ) );
		$other = array_values( array_diff( $scopes, $urls ) );
		return implode( ',', count( $urls ) > 3 ? array_merge( $other, array( '+' . count( $urls ) . ' url:' ) ) : $scopes );
	}

	/**
	 * [ page type => url: scopes of its published pages ] for the posts, pages and products; a page type is null when its
	 * post type has more than 5 × PerfmattersImport::MAX_URLS posts (the plan refuses lists over MAX_URLS anyway).
	 */
	private static function type_urls(): array {
		$out   = array();
		$limit = 5 * PerfmattersImport::MAX_URLS;
		foreach ( array( 'page' => 'page', 'post' => 'post', 'product' => 'product' ) as $pt => $page ) {
			$ids = get_posts( array( 'post_type' => $pt, 'post_status' => 'publish', 'numberposts' => $limit + 1, 'fields' => 'ids', 'orderby' => 'ID', 'order' => 'ASC', 'suppress_filters' => true ) );
			if ( count( $ids ) > $limit ) {
				$out[ $page ] = null;
				continue;
			}
			foreach ( self::import_posts( array_map( 'intval', $ids ) ) as $p ) {
				// A post or product is its post type's page type whatever its URL looks like; a page is what its URL routes to (front, cart, page…).
				$type = 'page' === $pt ? $p['page'] : $page;
				if ( '' !== $p['scope'] && '' !== $type && ( ! array_key_exists( $type, $out ) || null !== $out[ $type ] ) ) {
					$out[ $type ][] = $p['scope'];
				}
			}
		}
		foreach ( $out as $page => $list ) {
			if ( is_array( $list ) ) {
				$list = array_values( array_unique( $list ) );
				sort( $list );
				$out[ $page ] = $list;
			}
		}
		return $out;
	}

	/** Files known on this site: the last check's inventory, then the scripts/styles registered in this process (their deps win). */
	private static function known_files( array $inv ): array {
		$known = array( 'script' => array(), 'style' => array() );
		foreach ( array( 'script' => wp_scripts(), 'style' => wp_styles() ) as $kind => $deps ) {
			$graph = \BeltoftPerformance\Assets\Graph::from_wp( $deps );
			foreach ( $deps->registered as $h => $r ) {
				$src                  = is_string( $r->src ) ? $r->src : '';
				$known[ $kind ][ $h ] = array( 'src' => $src, 'plugin' => $graph->owner_plugin( $src ), 'deps' => array_values( (array) $r->deps ) );
			}
		}
		foreach ( $inv as $states ) {
			foreach ( (array) $states as $report ) {
				foreach ( array_merge( (array) ( $report['assets'] ?? array() ), (array) ( $report['blanked'] ?? array() ) ) as $a ) {
					$kind = (string) ( $a['kind'] ?? '' );
					if ( isset( $known[ $kind ] ) && ! isset( $known[ $kind ][ (string) ( $a['handle'] ?? '' ) ] ) && '' !== (string) ( $a['handle'] ?? '' ) ) {
						$known[ $kind ][ (string) $a['handle'] ] = array( 'src' => (string) ( $a['src'] ?? '' ), 'plugin' => (string) ( $a['plugin'] ?? '' ), 'deps' => (array) ( $a['deps'] ?? array() ) );
					}
				}
			}
		}
		return $known;
	}

	/** [ id => [ scope, page, post_type ] ] for the published posts among $ids (scope '' when the URL is not a normal page). */
	private static function import_posts( array $ids ): array {
		$routes = \BeltoftPerformance\Request\RouteMap::build();
		$out    = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( ! $post || 'publish' !== $post->post_status ) {
				continue;
			}
			$path  = \BeltoftPerformance\Request\Classifier::request_path( (string) wp_parse_url( (string) get_permalink( $post ), PHP_URL_PATH ) );
			$scope = Store::normalise_scope( 'url:' . $path, $routes );
			$out[ (int) $id ] = array( 'scope' => (string) $scope, 'page' => null === $scope ? '' : \BeltoftPerformance\Admin\Manager::scope_page( $scope, $routes ), 'post_type' => (string) $post->post_type );
		}
		return $out;
	}

	/** Installed plugin directories. */
	private static function installed_plugins(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return array_values( array_unique( array_map( static fn( $f ) => (string) strtok( (string) $f, '/' ), array_keys( (array) get_plugins() ) ) ) );
	}
}
