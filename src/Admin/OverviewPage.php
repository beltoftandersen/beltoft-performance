<?php
namespace BeltoftPerformance\Admin;

use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Check\TestQueue;
use BeltoftPerformance\Check\Trial;
use BeltoftPerformance\Cleanup\Switches;
use BeltoftPerformance\Loader\LoaderManager;
use BeltoftPerformance\Rules\Compiler;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

defined( 'ABSPATH' ) || exit;

final class OverviewPage {
	public const SLUG = 'beltoft-performance';

	public static function register(): void {
		add_action( 'admin_menu', static function () {
			add_options_page( __( 'Speed', 'beltoft-performance' ), __( 'Performance', 'beltoft-performance' ), 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		} );
		add_action( 'admin_enqueue_scripts', static function ( $hook ) {
			if ( 'settings_page_' . self::SLUG === $hook ) {
				wp_enqueue_style( 'beltoft-performance-admin', plugins_url( 'src/Admin/admin.css', BELTOFT_PERFORMANCE_FILE ), array(), (string) filemtime( __DIR__ . '/admin.css' ) ); // file time: browsers cache the stylesheet for a long time
			}
		} );
		foreach ( array( 'check', 'trial', 'test_all', 'accept', 'accept_selected', 'reject_page', 'restore_dismissed', 'switch_back_on', 'switches', 'repair_loader', 'qa_user' ) as $a ) {
			add_action( 'admin_post_bp_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'admin_post_bp_manager_load', array( Manager::class, 'handle_load' ) );
		add_action( 'wp_ajax_bp_check_progress', array( __CLASS__, 'ajax_check_progress' ) );
		add_action( 'wp_ajax_bp_test_progress', array( __CLASS__, 'ajax_test_progress' ) );
		add_action( 'admin_post_bp_manager_save', array( Manager::class, 'handle_save' ) );
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'beltoft-performance' ), 403 );
		}
		check_admin_referer( 'bp_' . $action );
	}

	private static function back( string $tab, string $notice = '' ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab, 'bp_notice' => rawurlencode( $notice ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	/** Back to the page view of the posted scope, or the overview. */
	private static function back_to_pages( string $notice = '' ): void {
		$scope = Manager::scope_from( trim( (string) wp_unslash( $_POST['scope'] ?? '' ) ) ); // normalise_scope validates
		if ( null === $scope ) {
			self::back( 'overview', $notice );
		}
		wp_safe_redirect( Manager::back_url( $scope, $notice ) );
		exit;
	}

	/** The admin URL of a tab. */
	public static function tab_url( string $tab ): string {
		return add_query_arg( array( 'page' => self::SLUG, 'tab' => $tab ), admin_url( 'options-general.php' ) );
	}

	public static function handle_check(): void {
		self::guard( 'check' );
		if ( Runner::progress_view( (array) get_option( Runner::PROGRESS, array() ), time() ) ) {
			self::back( 'overview', __( 'A check is already running. Wait until it is done, then start the next one.', 'beltoft-performance' ) );
		}
		$page = sanitize_key( (string) wp_unslash( $_POST['pages'] ?? '' ) );
		$last = (array) get_option( 'beltoft_performance_last_check', array() );
		if ( '' !== $page && isset( Runner::targets()[ $page ] ) && ! empty( $last['id'] ) ) {
			// One kind of page again, inside the last check: the other pages, suggestions and queued tests stay.
			update_option( Runner::PROGRESS, array( 'id' => (string) $last['id'], 'done' => 0, 'total' => 0, 'pages' => array( $page ), 'started' => time(), 'finished' => 0 ), false );
			wp_schedule_single_event( time(), 'beltoft_performance_run_check', array( (string) $last['id'], array( $page ), true ) );
			/* translators: %s: kind of page */
			self::back( 'overview', sprintf( __( 'Checking %s again in the background. The bar below shows how far it is.', 'beltoft-performance' ), Words::scope_name( $page, array(), array() ) ) );
		}
		$id = Runner::new_id();
		update_option( Runner::PROGRESS, array( 'id' => $id, 'done' => 0, 'total' => 0, 'pages' => array(), 'started' => time(), 'finished' => 0 ), false );
		wp_schedule_single_event( time(), 'beltoft_performance_run_check', array( $id ) );
		$notice    = __( 'The check has started. It runs in the background and takes about a minute — the bar below shows how far it is.', 'beltoft-performance' );
		$cancelled = TestQueue::cancel(); // their results would belong to the old check
		if ( $cancelled ) {
			/* translators: %d: number of tests */
			$notice .= ' ' . sprintf( _n( '%d test that was waiting was cancelled — start it again once the check is done.', '%d tests that were waiting were cancelled — start them again once the check is done.', $cancelled, 'beltoft-performance' ), $cancelled );
		}
		self::back( 'overview', $notice );
	}

	/** admin-ajax: how far the running check is, for the progress bar ({ running: false } when none runs). */
	public static function ajax_check_progress(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'bp_check_progress', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$v = Runner::progress_view( (array) get_option( Runner::PROGRESS, array() ), time() );
		wp_send_json_success( $v ? array( 'running' => true, 'text' => Runner::progress_text( $v, self::checking_what( $v ) ) ) + $v : array( 'running' => false ) );
	}

	/** "the shop", or the names of the page types a partial check looks at. */
	/** Background tests of one page view: the progress line and how many have finished, so the page can reload itself when one is done. */
	public static function ajax_test_progress(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_ajax_referer( 'bp_test_progress', 'nonce', false ) ) {
			wp_send_json_error( null, 403 );
		}
		$scope = sanitize_key( wp_unslash( $_POST['scope'] ?? '' ) );
		$q     = TestQueue::load();
		wp_send_json_success( array( 'active' => TestQueue::active( $q ), 'done' => count( (array) $q['done'] ), 'text' => TestQueue::progress_text( $q, $scope ) ) );
	}

	public static function checking_what( array $v ): string {
		return $v['pages'] ? implode( ', ', array_map( static fn( $p ) => Words::scope_name( (string) $p, array(), array() ), $v['pages'] ) ) : __( 'the shop', 'beltoft-performance' );
	}

	/** "Test on this page" (where=here, with the page type in scope) or "Test on all pages" (the default). */
	public static function handle_trial(): void {
		self::guard( 'trial' );
		$key = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		self::back_to_pages( TestQueue::add( array( $key ), self::test_page() ) ? __( 'Queued: it is tested in the background. This page updates by itself when it is done.', 'beltoft-performance' ) : __( 'Not a current suggestion: check the shop again.', 'beltoft-performance' ) );
	}

	/** "Test all for me": queues every posted suggestion of a page, on this page or on all their pages. */
	public static function handle_test_all(): void {
		self::guard( 'test_all' );
		$n = TestQueue::add( array_map( static fn( $t ) => sanitize_text_field( (string) $t ), (array) wp_unslash( $_POST['targets'] ?? array() ) ), self::test_page() );
		/* translators: %d: number of suggestions */
		self::back_to_pages( $n ? sprintf( _n( '%d suggestion queued: it is tested in the background. This page updates by itself when they are done.', '%d suggestions queued: they are tested in the background, one after the other. This page updates by itself when they are done.', $n, 'beltoft-performance' ), $n ) : __( 'Nothing to test: check the shop again.', 'beltoft-performance' ) );
	}

	/** The page type a posted test is limited to ('where' = here), or '' for every page of the suggestion. */
	private static function test_page(): string {
		$scope = sanitize_key( wp_unslash( $_POST['scope'] ?? '' ) );
		$where = (string) wp_unslash( $_POST['where'] ?? '' );
		if ( 'rest' === $where && in_array( $scope, Store::PAGE_TYPES, true ) ) {
			return '!' . $scope; // the pages it has not passed yet (TestQueue::add)
		}
		return 'here' === $where && in_array( $scope, Store::PAGE_TYPES, true ) ? $scope : '';
	}

	/**
	 * Pure: the pages to switch a candidate off on. $where: the ticked page types (only those that are still its pages; none ticked =
	 * nothing), 'here' = just $scope (when it is one of them), anything else = all its pages.
	 * @param string|array $where
	 */
	public static function where_pages( array $candidate_pages, $where, string $scope ): array {
		if ( is_array( $where ) ) {
			return array_values( array_intersect( $candidate_pages, array_map( 'strval', $where ) ) );
		}
		if ( 'here' === $where ) {
			return in_array( $scope, $candidate_pages, true ) ? array( $scope ) : array(); // no longer suggested here: nothing, never all pages
		}
		return array_values( $candidate_pages );
	}

	/**
	 * Accepts one current candidate on $pages (all its pages, or a part): only with a passed test of this very check that covered them
	 * (Trial::approves), never a script. Unsets its trial once it is off everywhere it was tested. @return string|null the refusal, or null when accepted (the caller recompiles).
	 */
	private static function accept_one( string $key, array $candidates, array &$trials, string $check_id, ?array $pages = null ): ?string {
		if ( null !== Store::accept_error( $key ) ) {
			return (string) Store::accept_error( $key );
		}
		foreach ( $candidates as $c ) {
			if ( $c['target'] !== $key ) {
				continue;
			}
			$pages = null === $pages ? (array) $c['pages'] : array_values( array_intersect( (array) $c['pages'], $pages ) );
			if ( ! $pages ) {
				return __( 'No page was ticked under "Where" (or it is no longer suggested there).', 'beltoft-performance' );
			}
			if ( ! Trial::approves( (array) ( $trials[ $key ] ?? array() ), $check_id, $pages ) ) {
				return __( 'Only a suggestion with a passed, current test can be switched off. Test it again.', 'beltoft-performance' );
			}
			$c   = Runner::narrow( $c, $pages );
			$res = Store::add( new Rule( $c['target'], $c['pages'], array( 'retail' ), implode( ' · ', (array) $c['why'] ), wp_get_current_user()->user_login, gmdate( 'c' ) ) );
			if ( true !== $res ) {
				return (string) $res;
			}
			if ( ! array_diff( array_map( 'strval', (array) ( $trials[ $key ]['pages'] ?? array() ) ), $pages ) ) {
				$trials[ $key ] = null; // off on every tested page: the test is used up (a part keeps it for the other pages)
			}
			return null;
		}
		return __( 'Not a current suggestion: check the shop again.', 'beltoft-performance' );
	}

	/**
	 * Accepts the posted targets (one "accept" or the ticked boxes of "Switch off selected"), then recompiles once.
	 * $where: [ target => here|all ] on page type $scope. $files: [ target => [ script:h|style:h ] ] for the plugins where "Only some of
	 * its files" was chosen — those files are switched off by hand on $scope (Manager::save: not tested), the plugin itself stays on.
	 */
	private static function accept_targets( array $keys, string $scope = '', array $where = array(), array $files = array(), array $no_files = array() ): void {
		$last     = (array) get_option( 'beltoft_performance_last_check', array() );
		$check_id = (string) ( $last['id'] ?? '' );
		$cands    = Runner::candidates( $check_id );
		$trials   = (array) TestQueue::fresh_option( 'beltoft_performance_trials', array() );
		$headers  = self::plugin_headers();
		$owners   = Sorting::owners( Runner::inventory( $check_id ) );
		$by       = array_column( $cands, null, 'target' );
		$done     = array();
		$refused  = array();
		$by_hand  = array();
		foreach ( array_values( array_unique( $keys ) ) as $key ) {
			$name = Words::thing_name( $key, $owners, $headers );
			if ( isset( $files[ $key ] ) ) {
				foreach ( $files[ $key ] as $f ) {
					$by_hand[ $f ] = true;
				}
				continue;
			}
			$pages = isset( $by[ $key ] ) ? self::where_pages( (array) $by[ $key ]['pages'], $where[ $key ] ?? 'all', $scope ) : null;
			$error = self::accept_one( $key, $cands, $trials, $check_id, $pages );
			if ( null === $error ) {
				$done[] = $name;
			} else {
				$refused[] = $name . ': ' . $error;
			}
		}
		$notice = '';
		if ( $done ) {
			// Re-read: a background test may have finished during this request; only drop the tests used up here.
			$fresh = (array) TestQueue::fresh_option( 'beltoft_performance_trials', array() );
			foreach ( $trials as $k => $v ) {
				if ( null === $v ) {
					unset( $fresh[ $k ] );
				}
			}
			update_option( 'beltoft_performance_trials', $fresh, false );
			$error = Compiler::change_error( true, Compiler::compile_from_store() );
			/* translators: %s: names */
			$notice = $error ?? sprintf( __( 'Switched off: %s. You can undo this any time under "Switched off".', 'beltoft-performance' ), implode( ', ', $done ) );
		}
		if ( $refused ) {
			/* translators: %s: names and reasons */
			$notice = trim( $notice . ' ' . sprintf( __( 'Not switched off: %s', 'beltoft-performance' ), implode( '; ', $refused ) ) );
		}
		if ( $no_files ) {
			/* translators: %s: names */
			$notice = trim( $notice . ' ' . sprintf( __( 'Nothing switched off for %s: you chose "Only the files I tick" but ticked none.', 'beltoft-performance' ), implode( ', ', array_map( static fn( $t ) => Words::thing_name( $t, $owners, $headers ), $no_files ) ) ) );
		}
		if ( $by_hand && in_array( $scope, Store::PAGE_TYPES, true ) ) {
			$notice = trim( $notice . ' ' . __( 'Single files:', 'beltoft-performance' ) . ' ' . Manager::save( $scope, $by_hand, wp_get_current_user()->user_login ) );
		}
		self::back_to_pages( '' !== $notice ? $notice : __( 'Nothing was selected.', 'beltoft-performance' ) );
	}

	/** Pure: [ target => [ file targets ] ] for the posted targets whose "what" is "files": only script:/style: values, at least one. */
	public static function chosen_files( array $targets, array $what, array $files ): array {
		$out = array();
		foreach ( $targets as $t ) {
			if ( 'files' !== (string) ( $what[ $t ] ?? '' ) ) {
				continue;
			}
			$list = array_values( array_unique( array_filter( array_map( 'strval', (array) ( $files[ $t ] ?? array() ) ), static fn( $f ) => (bool) preg_match( '/^(script|style):[^\s]+$/', $f ) ) ) );
			$out[ $t ] = $list;
		}
		return $out;
	}

	public static function handle_accept(): void {
		self::guard( 'accept' );
		self::accept_targets( array( sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) ) ) );
	}

	/** "Switch off selected" on a page: the ticked Safe items, each on this page or all its pages, as the whole plugin or chosen files. */
	public static function handle_accept_selected(): void {
		self::guard( 'accept_selected' );
		$clean   = static fn( $v ) => sanitize_text_field( (string) $v );
		$targets = array_map( $clean, (array) wp_unslash( $_POST['targets'] ?? array() ) );
		$where   = array();
		foreach ( (array) wp_unslash( $_POST['where'] ?? array() ) as $t => $w ) {
			$where[ $clean( $t ) ] = is_array( $w ) ? array_map( 'sanitize_key', $w ) : ( 'here' === (string) $w ? 'here' : 'all' );
		}
		foreach ( (array) wp_unslash( $_POST['where_shown'] ?? array() ) as $t => $v ) {
			$t = $clean( $t );
			if ( ! isset( $where[ $t ] ) ) {
				$where[ $t ] = array(); // page boxes were shown and all unticked: nowhere
			}
		}
		$what = array();
		foreach ( (array) wp_unslash( $_POST['what'] ?? array() ) as $t => $w ) {
			$what[ $clean( $t ) ] = (string) $w;
		}
		$files = array();
		foreach ( (array) wp_unslash( $_POST['files'] ?? array() ) as $t => $list ) {
			$files[ $clean( $t ) ] = array_map( $clean, (array) $list );
		}
		$scope  = sanitize_key( wp_unslash( $_POST['scope'] ?? '' ) );
		$chosen = in_array( $scope, Manager::FLOW_PAGES, true ) ? array() : self::chosen_files( $targets, $what, $files ); // part of buying: whole tested plugins only
		$empty  = array();
		foreach ( $chosen as $t => $list ) {
			if ( ! $list ) {
				unset( $chosen[ $t ] ); // "only the files I tick" with none ticked: nothing to do for it
				$targets = array_values( array_diff( $targets, array( $t ) ) );
				$empty[] = $t;
			}
		}
		self::accept_targets( $targets, $scope, $where, $chosen, $empty );
	}

	/** Removes one page type from one candidate (kept in beltoft_performance_rejected_pages[target][]). */
	public static function handle_reject_page(): void {
		self::guard( 'reject_page' );
		$target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		$page   = sanitize_key( wp_unslash( $_POST['page_type'] ?? '' ) );
		if ( '' !== $target && in_array( $page, Store::PAGE_TYPES, true ) ) {
			$rej            = (array) get_option( 'beltoft_performance_rejected_pages', array() );
			$rej[ $target ]   = array_values( array_unique( array_merge( (array) ( $rej[ $target ] ?? array() ), array( $page ) ) ) );
			update_option( 'beltoft_performance_rejected_pages', $rej, false );
		}
		self::back_to_pages( __( 'Kept: it won\'t be suggested for this kind of page again. You can change that under "Switched off".', 'beltoft-performance' ) );
	}

	/** Undoes one dismissal (Rules tab): the target everywhere (no page_type) or on one page type. */
	public static function handle_restore_dismissed(): void {
		self::guard( 'restore_dismissed' );
		$target = sanitize_text_field( wp_unslash( $_POST['target'] ?? '' ) );
		$page   = sanitize_key( wp_unslash( $_POST['page_type'] ?? '' ) );
		[ $rej, $rej_pages ] = Ui::restore_dismissed( (array) get_option( 'beltoft_performance_rejected', array() ), (array) get_option( 'beltoft_performance_rejected_pages', array() ), $target, $page );
		update_option( 'beltoft_performance_rejected', $rej, false );
		update_option( 'beltoft_performance_rejected_pages', $rej_pages, false );
		self::back( 'off', __( 'It will be suggested again after the next check.', 'beltoft-performance' ) );
	}

	/**
	 * "Switch back on…" on the Switched off tab: either the ticked pages (on[] = Sorting::choice_value(), Store::switch_on validates
	 * each scope against the stored rule), or "Everywhere" (removes the posted rule keys that exist, and their stored tests).
	 */
	public static function handle_switch_back_on(): void {
		self::guard( 'switch_back_on' );
		$errors  = array();
		$changed = false;
		if ( '1' === (string) wp_unslash( $_POST['everywhere'] ?? '' ) ) {
			$existing = array();
			foreach ( Store::all() as $r ) {
				$existing[ $r->key() ] = $r->target;
			}
			$trials = (array) get_option( 'beltoft_performance_trials', array() );
			foreach ( (array) wp_unslash( $_POST['keys'] ?? array() ) as $key ) { // compared with the stored keys only; no sanitize_text_field (it strips %-encoding)
				$key = (string) $key;
				if ( isset( $existing[ $key ] ) && Store::remove( $key ) ) {
					$changed = true;
					unset( $trials[ $existing[ $key ] ] );
				}
			}
			update_option( 'beltoft_performance_trials', $trials, false );
		} else {
			$by_target = array();
			foreach ( (array) wp_unslash( $_POST['on'] ?? array() ) as $value ) {
				$pair = Sorting::parse_choice( (string) $value );
				if ( null !== $pair ) {
					$by_target[ $pair[0] ][] = $pair[1];
				}
			}
			foreach ( $by_target as $target => $scopes ) {
				$res = Store::switch_on( (string) $target, $scopes );
				if ( true === $res ) {
					$changed = true;
				} else {
					$errors[] = (string) $res;
				}
			}
		}
		if ( ! $changed ) {
			self::back( 'off', $errors ? implode( ' ', $errors ) : __( 'Nothing was ticked: tick the pages where it should load again.', 'beltoft-performance' ) );
		}
		$notice = Compiler::change_error( true, Compiler::compile_from_store() ) ?? __( 'Switched back on. It loads again on those pages.', 'beltoft-performance' );
		self::back( 'off', trim( $notice . ' ' . implode( ' ', $errors ) ) );
	}

	public static function handle_switches(): void {
		self::guard( 'switches' );
		$on  = array_map( 'sanitize_key', (array) wp_unslash( $_POST['on'] ?? array() ) );
		$out = array();
		foreach ( Switches::definitions() as $k => $d ) {
			if ( null === $d[2] ) { $out[ $k ] = in_array( $k, $on, true ); }
		}
		update_option( Switches::OPTION, $out, false );
		self::back( 'settings', __( 'Saved.', 'beltoft-performance' ) );
	}

	public static function handle_repair_loader(): void {
		self::guard( 'repair_loader' );
		$ok = LoaderManager::install( LoaderManager::default_dir() ) && Compiler::compile_from_store();
		self::back( 'overview', $ok ? __( 'Repaired.', 'beltoft-performance' ) : __( 'Could not repair: WordPress may not write the files it needs. Ask your developer.', 'beltoft-performance' ) );
	}

	public static function handle_qa_user(): void {
		self::guard( 'qa_user' );
		update_option( 'beltoft_performance_qa_user', array( 'login' => sanitize_user( wp_unslash( $_POST['login'] ?? '' ) ), 'email' => sanitize_email( wp_unslash( $_POST['email'] ?? '' ) ) ), false );
		self::back( 'settings', __( 'Test customer account saved.', 'beltoft-performance' ) );
	}

	/** Pure: what is wrong with the compiled rule file the loader reads, or null. */
	public static function rules_file_problem( bool $exists, bool $readable, string $owner ): ?string {
		if ( ! $exists ) {
			return __( 'The rules file is missing: the loader unloads nothing. Click Repair or save a rule to write it.', 'beltoft-performance' );
		}
		if ( ! $readable ) {
			return __( 'The rules file cannot be read by PHP: the loader unloads nothing. Fix its permissions.', 'beltoft-performance' );
		}
		if ( 'www-data' !== $owner ) {
			/* translators: %s: file owner */
			return sprintf( __( 'The rules file is owned by %s, not www-data: the site cannot rewrite it when rules change. Run chown www-data:www-data on it.', 'beltoft-performance' ), '' !== $owner ? $owner : '?' );
		}
		return null;
	}

	/** Pure: the state of the compiled rule file: ok | missing | unreadable | wrong_owner (same order as rules_file_problem()). */
	public static function rules_file_code( bool $exists, bool $readable, string $owner ): string {
		if ( ! $exists ) {
			return 'missing';
		}
		if ( ! $readable ) {
			return 'unreadable';
		}
		return 'www-data' !== $owner ? 'wrong_owner' : 'ok';
	}

	/** [ exists, readable, owner ] of the real rule file. */
	private static function rules_file_facts(): array {
		$file = Compiler::file();
		clearstatcache( true, $file );
		$exists = is_file( $file );
		$owner  = '';
		if ( $exists ) {
			$uid   = (int) @fileowner( $file );
			$info  = function_exists( 'posix_getpwuid' ) ? posix_getpwuid( $uid ) : false;
			$owner = is_array( $info ) ? (string) $info['name'] : (string) $uid;
		}
		return array( $exists, $exists && is_readable( $file ), $owner );
	}

	/** rules_file_code() for the real file. */
	public static function rules_file_state(): string {
		return self::rules_file_code( ...self::rules_file_facts() );
	}

	/** rules_file_problem() for the real file. */
	public static function rules_file_status(): ?string {
		return self::rules_file_problem( ...self::rules_file_facts() );
	}

	/** [ plugin folder => header Name ] of the installed plugins. */
	public static function plugin_headers(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$dir = dirname( (string) $file );
			if ( '.' !== $dir ) {
				$out[ $dir ] = (string) ( $data['Name'] ?? '' );
			}
		}
		return $out;
	}

	/** What every view reads, once per request: the last check, its inventory and candidates, tests, rules and names. */
	public static function context(): array {
		static $ctx = null;
		if ( null !== $ctx ) {
			return $ctx;
		}
		$last     = (array) get_option( 'beltoft_performance_last_check', array() );
		$check_id = (string) ( $last['id'] ?? '' );
		$inv      = '' !== $check_id ? Runner::inventory( $check_id ) : array();
		$rules    = Store::all();
		$ctx      = array(
			'last'           => $last,
			'check_id'       => $check_id,
			'inv'            => $inv,
			'candidates'     => '' !== $check_id ? Runner::candidates( $check_id ) : array(), // dismissed and covered ones already dropped
			'trials'         => (array) get_option( 'beltoft_performance_trials', array() ),
			'rules'          => $rules,
			'headers'        => self::plugin_headers(),
			'owners'         => Sorting::owners( $inv ),
			'templates'      => Manager::template_choices(),
			'page_titles'    => Manager::page_titles(),
			'rejected'       => (array) get_option( 'beltoft_performance_rejected', array() ),
			'rejected_pages' => (array) get_option( 'beltoft_performance_rejected_pages', array() ),
			'queue'          => TestQueue::load(),
		);
		return $ctx;
	}

	/** Targets the owner chose to keep on page type $type (dismissed there or everywhere). */
	public static function kept_on( string $type ): array {
		$ctx  = self::context();
		$kept = array_map( 'strval', array_keys( $ctx['rejected'] ) );
		foreach ( $ctx['rejected_pages'] as $t => $pages ) {
			if ( in_array( $type, (array) $pages, true ) ) {
				$kept[] = (string) $t;
			}
		}
		return $kept;
	}

	/** [ login => display name ] for whoever approved a rule (unknown logins capitalised). */
	public static function display_names( array $rules ): array {
		$out = array();
		foreach ( $rules as $r ) {
			if ( $r instanceof Rule && '' !== $r->approved_by && ! isset( $out[ $r->approved_by ] ) ) {
				$u = get_user_by( 'login', $r->approved_by );
				$out[ $r->approved_by ] = $u ? (string) $u->display_name : ucfirst( $r->approved_by );
			}
		}
		return $out;
	}

	/** The page/tab heading block: title, intro, tab pills. */
	/** Plain WordPress page head: the title, the usual nav tabs, an optional line of text. */
	private static function head( string $tab, string $title, string $intro, bool $nav = true ): void {
		$labels = array( 'scripts' => __( 'Scripts', 'beltoft-performance' ), 'off' => __( 'Switched off', 'beltoft-performance' ), 'settings' => __( 'Settings', 'beltoft-performance' ) );
		echo '<h1>' . esc_html( $title ) . '</h1>';
		if ( $nav ) {
			echo '<nav class="nav-tab-wrapper">';
			foreach ( $labels as $k => $l ) {
				printf( '<a class="nav-tab%s" href="%s"%s>%s</a>', $k === $tab ? ' nav-tab-active' : '', esc_url( self::tab_url( $k ) ), $k === $tab ? ' aria-current="page"' : '', esc_html( $l ) );
			}
			echo '</nav>';
		}
		if ( '' !== $intro ) {
			echo '<p>' . esc_html( $intro ) . '</p>';
		}
		echo '<hr class="wp-header-end">';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'beltoft-performance' ), 403 );
		}
		$tab = Ui::tab( sanitize_key( wp_unslash( $_GET['tab'] ?? '' ) ) );
		// The picker posts bp_kind + its select; links and redirects carry scope=. Both are validated by Manager::scope_from.
		$kind = sanitize_key( wp_unslash( $_GET['bp_kind'] ?? '' ) );
		$raw  = trim( (string) wp_unslash( $_GET['scope'] ?? '' ) );
		if ( 'type' === $kind ) {
			$raw = sanitize_key( wp_unslash( $_GET['bp_type'] ?? '' ) );
		} elseif ( 'page' === $kind ) {
			$raw = 'url:' . trim( (string) wp_unslash( $_GET['bp_page'] ?? '' ) );
		} elseif ( 'template' === $kind ) {
			$raw = 'template:' . absint( wp_unslash( $_GET['bp_template'] ?? 0 ) );
		}
		$scope = 'scripts' === $tab && '' !== $raw ? Manager::scope_from( $raw ) : null;
		echo '<div class="wrap bp bp-plain">';
		$view  = $tab;
		$heads = array(
			'scripts'  => array( __( 'Performance', 'beltoft-performance' ), '' ),
			'off'      => array( __( 'Switched off', 'beltoft-performance' ), __( 'Everything that does not load, and where. Switch it back on here.', 'beltoft-performance' ) ),
			'settings' => array( __( 'Settings', 'beltoft-performance' ), '' ),
		);
		self::head( $tab, $heads[ $tab ][0], $heads[ $tab ][1] );
		if ( ! empty( $_GET['bp_notice'] ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( rawurldecode( (string) wp_unslash( $_GET['bp_notice'] ) ) ) . '</p></div>';
		}
		include __DIR__ . '/views/' . $view . '.php';
		self::script();
		echo '</div>';
	}

	/** Small helpers for the forms: confirm dialogs, "testing…" while a test runs, the "Everywhere" box. */
	private static function script(): void {
		?>
		<script>
		( function () {
			document.addEventListener( 'submit', function ( e ) {
				var f = e.target;
				if ( ! f.closest || ! f.closest( '.bp' ) ) { return; }
				if ( f.hasAttribute( 'data-bp-confirm' ) && ! window.confirm( f.getAttribute( 'data-bp-confirm' ) ) ) { e.preventDefault(); return; }
				if ( f.hasAttribute( 'data-bp-busy' ) ) {
					var b = f.querySelector( 'button' );
					if ( b ) { b.disabled = true; b.textContent = f.getAttribute( 'data-bp-busy' ); }
				}
			} );
			document.addEventListener( 'click', function ( e ) { // "Only this page" / "All tested pages" under Where
				var b = e.target.closest ? e.target.closest( '[data-bp-where-only],[data-bp-where-all]' ) : null;
				if ( ! b ) { return; }
				var boxes = b.closest( 'fieldset' ).querySelectorAll( 'input[type=checkbox]:not([disabled])' );
				for ( var i = 0; i < boxes.length; i++ ) { boxes[ i ].checked = b.hasAttribute( 'data-bp-where-all' ) || boxes[ i ].value === b.getAttribute( 'data-bp-where-only' ); }
			} );
			// Background tests: keep the progress line current; when a test finishes, fetch this page again and swap in only the
			// page sections (no reload: the scroll position stays). Pages without sections (the overview) reload instead.
			var bpSwap = function ( req ) {
				fetch( req ? req.url : window.location.href, req ? { method: 'POST', credentials: 'same-origin', body: req.body } : { credentials: 'same-origin' } ).then( function ( r ) { return r.text(); } ).then( function ( html ) {
					var doc  = new DOMParser().parseFromString( html, 'text/html' );
					var news = doc.querySelectorAll( '.bp .bp-section' );
					var olds = document.querySelectorAll( '.bp .bp-section' );
					if ( ! olds.length || news.length !== olds.length ) { window.location.reload(); return; }
					for ( var i = 0; i < olds.length; i++ ) { olds[ i ].replaceWith( document.importNode( news[ i ], true ) ); }
					bpWatch();
				} ).catch( function () { window.location.reload(); } );
			};
			var bpWatch = function () {
				var el = document.querySelector( '[data-bp-test-progress]' );
				if ( ! el || el.dataset.watching ) { return; }
				el.dataset.watching = '1';
				var poll = function () {
					if ( ! document.body.contains( el ) ) { return; }
					var body = new URLSearchParams( { action: 'bp_test_progress', nonce: el.dataset.nonce, scope: el.dataset.scope } );
					fetch( el.dataset.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
						if ( ! j || ! j.success ) { setTimeout( poll, 15000 ); return; }
						if ( ! j.data.active || String( j.data.done ) !== el.dataset.done ) { bpSwap(); return; }
						if ( j.data.text && el.dataset.scope ) { el.querySelector( '[data-bp-text]' ).textContent = j.data.text; }
						setTimeout( poll, 4000 );
					} ).catch( function () { setTimeout( poll, 15000 ); } );
				};
				setTimeout( poll, 4000 );
			};
			bpWatch();
			// "Test…" buttons queue in place: the form is posted in the background and the page sections are swapped (no jump to the top).
			document.addEventListener( 'submit', function ( e ) {
				var f = e.target, a = f.querySelector && f.querySelector( 'input[name=action]' );
				if ( e.defaultPrevented || ! a || -1 === [ 'bp_trial', 'bp_test_all' ].indexOf( a.value ) || ! document.querySelector( '.bp .bp-section' ) || ! window.fetch ) { return; }
				e.preventDefault();
				var b = f.querySelector( 'button' );
				if ( b ) { b.disabled = true; }
				bpSwap( { url: f.action, body: new FormData( f ) } );
			} );
			document.addEventListener( 'change', function ( e ) {
				var box = e.target;
				if ( box.hasAttribute && box.hasAttribute( 'data-bp-whole' ) ) { // the whole plugin takes its files with it: single files make no sense then
					var singles = box.form.querySelectorAll( '.bp-files-list input[type=checkbox]' );
					for ( var j = 0; j < singles.length; j++ ) { singles[ j ].disabled = box.checked; if ( box.checked ) { singles[ j ].checked = false; } }
				}
				if ( box.name && 0 === box.name.indexOf( 'what[' ) ) { // "Only the files I tick": show the files, files go on this page only
					var item = box.closest( '.bp-item' ), files = 'files' === box.value && box.checked;
					var where = item && item.querySelector( '[data-bp-where]' ), list = item && item.querySelector( '.bp-files' );
					if ( where ) { where.disabled = files; }
					if ( list && files ) { list.open = true; }
				}
				if ( box.hasAttribute && box.hasAttribute( 'data-bp-all' ) ) {
					var all = box.form.querySelectorAll( 'input[name="on[]"]' );
					for ( var i = 0; i < all.length; i++ ) { all[ i ].checked = box.checked; all[ i ].disabled = box.checked; }
				}
			} );
		} )();
		</script>
		<?php
	}

	/** A hidden POST form for admin-post.php (nonce bp_<action>) whose buttons live elsewhere (form="<id>"): rows of the Save form cannot nest forms. */
	public static function action_form( string $id, string $action, array $fields ): string {
		$html = sprintf( '<form id="%s" method="post" action="%s" hidden>', esc_attr( $id ), esc_url( admin_url( 'admin-post.php' ) ) );
		$html .= sprintf( '<input type="hidden" name="action" value="bp_%s"><input type="hidden" name="_wpnonce" value="%s">', esc_attr( $action ), esc_attr( wp_create_nonce( 'bp_' . $action ) ) );
		foreach ( $fields as $k => $v ) {
			$html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $k ), esc_attr( (string) $v ) );
		}
		return $html . '</form>';
	}

	/** An inline POST form with one button (nonce bp_<action>). $attrs: extra form attributes (data-bp-confirm, data-bp-busy). */
	public static function button_form( string $action, array $fields, string $label, string $class = 'button', string $title = '', array $attrs = array() ): string {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= sprintf( ' %s="%s"', esc_attr( (string) $k ), esc_attr( (string) $v ) );
		}
		$html = sprintf( '<form method="post" action="%s" class="bp-inline"%s><input type="hidden" name="action" value="bp_%s"><input type="hidden" name="_wpnonce" value="%s">', esc_url( admin_url( 'admin-post.php' ) ), $extra, esc_attr( $action ), esc_attr( wp_create_nonce( 'bp_' . $action ) ) );
		foreach ( $fields as $k => $v ) {
			$html .= sprintf( '<input type="hidden" name="%s" value="%s">', esc_attr( (string) $k ), esc_attr( (string) $v ) );
		}
		return $html . sprintf( '<button class="%s"%s>%s</button></form>', esc_attr( $class ), '' !== $title ? ' title="' . esc_attr( $title ) . '"' : '', esc_html( $label ) );
	}
}
