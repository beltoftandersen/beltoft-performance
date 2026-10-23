<?php
namespace BeltoftPerformance;

defined( 'ABSPATH' ) || exit;

final class Plugin {
	/** Changes that move the routes stored in the rules file (page ids, permalink bases): the file is rewritten at the end of that request. */
	public const RECOMPILE_HOOKS = array(
		'update_option_woocommerce_permalinks',
		'permalink_structure_changed',
		'update_option_page_on_front',
		'update_option_page_for_posts',
		'update_option_woocommerce_shop_page_id',
		'update_option_woocommerce_cart_page_id',
		'update_option_woocommerce_checkout_page_id',
		'update_option_woocommerce_myaccount_page_id',
	);

	public static function register_recompile(): void {
		foreach ( self::RECOMPILE_HOOKS as $hook ) {
			add_action( $hook, array( __CLASS__, 'queue_recompile' ) );
		}
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
	}

	/** The pages whose permalink is a route in the rules file: WooCommerce shop/cart/checkout/account, posts page, front page. */
	public const ROUTED_PAGE_OPTIONS = array( 'woocommerce_shop_page_id', 'woocommerce_cart_page_id', 'woocommerce_checkout_page_id', 'woocommerce_myaccount_page_id', 'page_for_posts', 'page_on_front' );

	/** Pure: true when a routed page ($watched ids) got a new slug or parent. $before/$after: [ 'slug' => , 'parent' => ]. */
	public static function post_update_moves_routes( int $post_id, array $before, array $after, array $watched ): bool {
		if ( $post_id <= 0 || ! in_array( $post_id, array_map( 'intval', $watched ), true ) ) {
			return false;
		}
		return (string) ( $before['slug'] ?? '' ) !== (string) ( $after['slug'] ?? '' ) || (int) ( $before['parent'] ?? 0 ) !== (int) ( $after['parent'] ?? 0 );
	}

	public static function on_post_updated( $post_id, $after = null, $before = null ): void {
		if ( ! $after instanceof \WP_Post || ! $before instanceof \WP_Post ) {
			return;
		}
		$watched = array_map( static fn( $o ) => (int) get_option( $o ), self::ROUTED_PAGE_OPTIONS );
		if ( self::post_update_moves_routes( (int) $post_id, array( 'slug' => $before->post_name, 'parent' => $before->post_parent ), array( 'slug' => $after->post_name, 'parent' => $after->post_parent ), $watched ) ) {
			self::queue_recompile();
		}
	}

	/** Once per request, after every option of the change is saved. */
	public static function queue_recompile(): void {
		if ( false === has_action( 'shutdown', array( __CLASS__, 'recompile' ) ) ) {
			add_action( 'shutdown', array( __CLASS__, 'recompile' ) );
		}
	}

	public static function recompile(): void {
		\BeltoftPerformance\Request\RouteMap::flush();
		\BeltoftPerformance\Rules\Compiler::compile_from_store();
	}

	public static function disabled(): bool {
		return defined( 'BELTOFT_PERFORMANCE_DISABLED' ) && BELTOFT_PERFORMANCE_DISABLED;
	}

	/** A visitor's page request: not wp-admin, AJAX, cron, REST or WP-CLI. Only the switch-offs run then. */
	public static function is_front(): bool {
		return ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( 'WP_CLI' ) && WP_CLI ) && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) && false === strpos( (string) ( $_SERVER['REQUEST_URI'] ?? '' ), '/wp-json/' );
	}

	public static function init(): void {
		// Front end: nothing but removing what is switched off (files, and the cleanup switches). No translations, no
		// schedules, no checks — plugins themselves are already kept from loading by the must-use loader.
		\BeltoftPerformance\Assets\Suppressor::register();
		\BeltoftPerformance\Cleanup\Switches::register();
		if ( self::is_front() ) {
			return;
		}
		load_plugin_textdomain( 'beltoft-performance', false, dirname( plugin_basename( BELTOFT_PERFORMANCE_FILE ) ) . '/languages' );
		add_action( 'admin_init', static function () {
			$dir = \BeltoftPerformance\Loader\LoaderManager::default_dir();
			if ( 'outdated' === \BeltoftPerformance\Loader\LoaderManager::status( $dir )['state'] ) {
				\BeltoftPerformance\Loader\LoaderManager::install( $dir );
			}
		} );
		self::register_recompile();
		\BeltoftPerformance\Cleanup\Database::register();
		add_action( 'beltoft_performance_run_check', static function ( $id, $pages = array(), $merge = false ) { \BeltoftPerformance\Check\Runner::run( (string) $id, array_values( array_filter( (array) $pages, 'is_string' ) ), array( 'guest', 'cart', 'logged_in' ), (bool) $merge ); }, 10, 3 ); // cron requests are not admin
		add_action( \BeltoftPerformance\Check\TestQueue::HOOK, array( \BeltoftPerformance\Check\TestQueue::class, 'run_one' ) ); // background tests, one per event
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'beltoft-performance', \BeltoftPerformance\Cli\Command::class );
		}
		if ( is_admin() ) {
			\BeltoftPerformance\Admin\OverviewPage::register();
		}
	}

	public static function activate(): void {
		\BeltoftPerformance\Loader\LoaderManager::install( \BeltoftPerformance\Loader\LoaderManager::default_dir() );
		\BeltoftPerformance\Rules\Compiler::compile_from_store();
	}

	public static function deactivate(): void {
		\BeltoftPerformance\Loader\LoaderManager::remove( \BeltoftPerformance\Loader\LoaderManager::default_dir() );
	}
}
