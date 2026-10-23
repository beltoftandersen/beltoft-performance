<?php
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__ ) . '/mu/beltoft-performance-loader.php';

final class LoaderTest extends TestCase {
	private string $file;
	private array $active = [ 'woocommerce/woocommerce.php', 'beltoft-performance/beltoft-performance.php', 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php', 'super-socializer/super_socializer.php' ];
	protected function setUp(): void {
		$this->file = sys_get_temp_dir() . '/bp-loader-' . getmypid() . '.php';
		file_put_contents( $this->file, '<?php return ' . var_export( [ 'v' => 1, 'routes' => [ 'shop' => '/loja/', 'retail_host' => 'staging.mindthetrash.pt' ], 'unload' => [ 'retail' => [ 'shop' => [ 'pymntpl-paypal-woocommerce' ] ] ] ], true ) . ';' );
	}
	protected function tearDown(): void { @unlink( $this->file ); }
	private function run_for( string $uri, ?array $active = null ): array {
		return beltoft_performance_filter_plugins( $active ?? $this->active, $this->file, $uri, [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ] );
	}
	public function test_unloads_on_matching_page(): void {
		$this->assertNotContains( 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php', $this->run_for( '/loja/' ) );
		$this->assertContains( 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php', $this->run_for( '/loja/produto-x/' ) );
	}
	public function test_never_on_ajax_rest_admin(): void {
		foreach ( [ '/?wc-ajax=x', '/wp-json/a', '/wp-admin/', '/wp-admin/admin-ajax.php' ] as $u ) {
			$this->assertSame( $this->active, $this->run_for( $u ), $u );
		}
	}
	public function test_does_nothing_when_plugin_inactive(): void {
		$without = array_values( array_diff( $this->active, [ 'beltoft-performance/beltoft-performance.php' ] ) );
		$this->assertSame( $without, $this->run_for( '/loja/', $without ) );
	}
	public function test_missing_or_broken_file_is_harmless(): void {
		$this->assertSame( $this->active, beltoft_performance_filter_plugins( $this->active, '/nope.php', '/loja/', [ 'HTTP_HOST' => 'x' ] ) );
		file_put_contents( $this->file, '<?php return "garbage";' );
		$this->assertSame( $this->active, $this->run_for( '/loja/' ) );
		file_put_contents( $this->file, '<?php this is not php' );
		$this->assertSame( $this->active, $this->run_for( '/loja/' ) );
	}
	public function test_manager_install_status_remove(): void {
		$dir = sys_get_temp_dir() . '/bp-mu-' . getmypid();
		@mkdir( $dir );
		$this->assertSame( 'missing', \BeltoftPerformance\Loader\LoaderManager::status( $dir )['state'] );
		$this->assertTrue( \BeltoftPerformance\Loader\LoaderManager::install( $dir ) );
		$this->assertSame( 'installed', \BeltoftPerformance\Loader\LoaderManager::status( $dir )['state'] );
		$this->assertTrue( \BeltoftPerformance\Loader\LoaderManager::remove( $dir ) );
		$this->assertFileDoesNotExist( $dir . '/beltoft-performance-loader.php' );
		@rmdir( $dir );
	}
	public function test_wrong_typed_rules_are_harmless(): void {
		file_put_contents( $this->file, '<?php return ' . var_export( [ 'v' => 1, 'unload' => [ 'retail' => [ 'shop' => [ 'x' ] ] ], 'routes' => [ 'shop' => [] ] ], true ) . ';' );
		$this->assertSame( $this->active, $this->run_for( '/loja/' ) );
	}
	public function test_repeated_calls_are_identical_and_memoized(): void {
		$a = $this->run_for( '/loja/' );
		$this->assertSame( $a, $this->run_for( '/loja/' ) );
		$this->assertNotSame( $this->active, $a );
		// Same stat (size+mtime) but unreadable content would differ if re-included: keep size, change nothing observable.
		$this->assertSame( $a, $this->run_for( '/loja/' ) );
	}
	public function test_protected_plugins_are_never_dropped(): void {
		$active = array_merge( $this->active, [ 'beltoft-gift-cards/beltoft-gift-cards.php' ] );
		file_put_contents( $this->file, '<?php return ' . var_export( [ 'v' => 1, 'routes' => [ 'shop' => '/loja/', 'retail_host' => 'staging.mindthetrash.pt' ], 'unload' => [ 'retail' => [ 'shop' => [ 'woocommerce', 'beltoft-gift-cards', 'beltoft-performance', 'super-socializer' ] ] ] ], true ) . ';' );
		$out = $this->run_for( '/loja/', $active );
		$this->assertContains( 'woocommerce/woocommerce.php', $out );
		$this->assertContains( 'beltoft-gift-cards/beltoft-gift-cards.php', $out );
		$this->assertContains( 'beltoft-performance/beltoft-performance.php', $out );
		$this->assertNotContains( 'super-socializer/super_socializer.php', $out );
	}
	private function trial_header( array $rule, string $secret = 's', ?string $path = '/loja/' ): string {
		$payload = [ 'id' => 'r1', 'state' => 'guest', 'trial' => [ $rule ] ];
		if ( null !== $path ) { $payload['path'] = $path; }
		$json = json_encode( $payload );
		$ts = time();
		return $ts . '.' . hash_hmac( 'sha256', "$ts.$json", $secret ) . '.' . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}
	private function with_secret(): void {
		$data = include $this->file; $data['check_secret'] = 's'; $data['unload'] = [];
		file_put_contents( $this->file, '<?php return ' . var_export( $data, true ) . ';' );
	}
	private function trial_run( string $uri, string $h ): array {
		return beltoft_performance_filter_plugins( $this->active, $this->file, $uri, [ 'HTTP_HOST' => 'staging.mindthetrash.pt', 'HTTP_X_BP_CHECK' => $h ] );
	}
	public function test_trial_header_unloads_for_that_request_only(): void {
		$this->with_secret();
		$h = $this->trial_header( [ 'target' => 'unload:super-socializer', 'pages' => [ 'shop' ], 'hosts' => [ 'retail' ] ] );
		$this->assertNotContains( 'super-socializer/super_socializer.php', $this->trial_run( '/loja/?bp_check=x', $h ) );
		$p = explode( '.', $h );
		$this->assertContains( 'super-socializer/super_socializer.php', $this->trial_run( '/loja/?bp_check=x', $p[0] . '.bad.' . $p[2] ) );
		$plain = beltoft_performance_filter_plugins( $this->active, $this->file, '/loja/', [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ] );
		$this->assertContains( 'super-socializer/super_socializer.php', $plain );
	}
	public function test_trial_requires_bp_check_query_and_matching_host(): void {
		$this->with_secret();
		$h = $this->trial_header( [ 'target' => 'unload:super-socializer', 'pages' => [ 'shop' ], 'hosts' => [ 'retail' ] ] );
		$this->assertContains( 'super-socializer/super_socializer.php', $this->trial_run( '/loja/', $h ) );
		$trade = $this->trial_header( [ 'target' => 'unload:super-socializer', 'pages' => [ 'shop' ], 'hosts' => [ 'trade' ] ] );
		$this->assertContains( 'super-socializer/super_socializer.php', $this->trial_run( '/loja/?bp_check=x', $trade ) );
	}
	public function test_bails_in_special_contexts(): void {
		foreach ( [ 'DOING_AJAX', 'WP_ADMIN', 'XMLRPC_REQUEST', 'REST_REQUEST', 'DOING_CRON' ] as $c ) {
			$this->assertTrue( beltoft_performance_special_context( [ $c => true ] ), $c );
			$this->assertSame( $this->active, beltoft_performance_filter_plugins( $this->active, $this->file, '/loja/', [ 'HTTP_HOST' => 'staging.mindthetrash.pt' ], [ $c => true ] ), $c );
		}
		$this->assertFalse( beltoft_performance_special_context( [ 'DOING_AJAX' => false ] ) );
		$this->assertFalse( beltoft_performance_special_context() ); // none of the constants defined in the test process
	}
	public function test_dot_segment_and_script_name_bypass_keep_all_plugins(): void {
		$this->assertSame( $this->active, $this->run_for( '/loja/../wp-admin/admin-ajax.php' ) );
		$this->assertSame( $this->active, beltoft_performance_filter_plugins( $this->active, $this->file, '/loja/', [ 'HTTP_HOST' => 'staging.mindthetrash.pt', 'SCRIPT_NAME' => '/wp-admin/admin-ajax.php' ] ) );
		$this->assertNotSame( $this->active, beltoft_performance_filter_plugins( $this->active, $this->file, '/loja/', [ 'HTTP_HOST' => 'staging.mindthetrash.pt', 'SCRIPT_NAME' => '/index.php' ] ) );
	}
	public function test_trial_requires_signed_path_to_match_request(): void {
		$this->with_secret();
		$rule = [ 'target' => 'unload:super-socializer', 'pages' => [ 'shop' ], 'hosts' => [ 'retail' ] ];
		$this->assertNotContains( 'super-socializer/super_socializer.php', $this->trial_run( '/LOJA//?bp_check=x', $this->trial_header( $rule ) ) );
		$this->assertContains( 'super-socializer/super_socializer.php', $this->trial_run( '/loja/?bp_check=x', $this->trial_header( $rule, 's', '/carrinho/' ) ) );
		$this->assertContains( 'super-socializer/super_socializer.php', $this->trial_run( '/loja/?bp_check=y', $this->trial_header( $rule, 's', null ) ) );
	}
	public function test_loader_and_signature_share_the_path_normalisation(): void {
		$src = (string) file_get_contents( dirname( __DIR__ ) . '/mu/beltoft-performance-loader.php' );
		$this->assertStringContainsString( 'Classifier::request_path(', $src );
	}
	public function test_merge_removed_restores_order_without_duplicates(): void {
		$unfiltered = [ 'a/a.php', 'b/b.php', 'c/c.php', 'd/d.php' ];
		// The request dropped b and d; a front-end caller deactivates c from the filtered list.
		$this->assertSame( [ 'a/a.php', 'b/b.php', 'd/d.php' ], beltoft_performance_merge_removed( [ 'a/a.php' ], $unfiltered, [ 'b/b.php', 'd/d.php' ] ) );
		$this->assertSame( [ 'b/b.php', 'c/c.php', 'd/d.php' ], beltoft_performance_merge_removed( [ 'c/c.php' ], $unfiltered, [ 'b/b.php', 'd/d.php' ] ) );
		$this->assertSame( [ 'a/a.php', 'b/b.php', 'c/c.php' ], beltoft_performance_merge_removed( [ 'a/a.php', 'b/b.php', 'c/c.php' ], $unfiltered, [ 'b/b.php' ] ) );
		$this->assertSame( [ 'a/a.php', 'c/c.php', 'x/x.php' ], beltoft_performance_merge_removed( [ 'a/a.php', 'c/c.php', 'x/x.php' ], $unfiltered, [] ) );
		$this->assertSame( 'junk', beltoft_performance_merge_removed( 'junk', $unfiltered, [ 'b/b.php' ] ) );
	}
	public function test_front_end_update_of_active_plugins_keeps_unloaded_plugins(): void {
		$filtered = $this->run_for( '/loja/' );
		$this->assertNotContains( 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php', $filtered );
		$saved = beltoft_performance_keep_removed( array_values( array_diff( $filtered, [ 'super-socializer/super_socializer.php' ] ) ) );
		$this->assertSame( [ 'woocommerce/woocommerce.php', 'beltoft-performance/beltoft-performance.php', 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php' ], $saved );
	}

	private function write_urls( $urls, array $unload = [], array $extra = [] ): void {
		file_put_contents( $this->file, '<?php return ' . var_export( array_merge( [ 'v' => 1, 'routes' => [ 'shop' => '/loja/', 'retail_host' => 'staging.mindthetrash.pt' ], 'unload' => $unload, 'unload_urls' => $urls ], $extra ), true ) . ';' );
	}
	public function test_url_rule_drops_on_normalised_path_only(): void {
		$this->write_urls( [ 'retail' => [ '/sobre-nos/' => [ 'super-socializer' ] ] ] );
		$gone = 'super-socializer/super_socializer.php';
		$this->assertNotContains( $gone, $this->run_for( '/Sobre-Nos?utm_source=1' ) );
		$this->assertNotContains( $gone, $this->run_for( '//sobre-nos//' ) );
		$this->assertContains( $gone, $this->run_for( '/sobre-nos/extra/' ) );
		$this->assertContains( $gone, $this->run_for( '/loja/' ) );
	}
	public function test_url_and_page_type_rules_combine(): void {
		$this->write_urls( [ 'retail' => [ '/loja/' => [ 'super-socializer' ] ] ], [ 'retail' => [ 'shop' => [ 'pymntpl-paypal-woocommerce' ] ] ] );
		$r = $this->run_for( '/loja/' );
		$this->assertNotContains( 'super-socializer/super_socializer.php', $r );
		$this->assertNotContains( 'pymntpl-paypal-woocommerce/pymntpl-paypal-woocommerce.php', $r );
	}
	public function test_url_rule_never_applies_to_non_page_requests_or_protected(): void {
		$this->write_urls( [ 'retail' => [ '/wp-json/a/' => [ 'super-socializer' ], '/sobre-nos/' => [ 'woocommerce', 'beltoft-x' ] ] ] );
		$this->assertSame( $this->active, $this->run_for( '/wp-json/a/' ) );
		$this->assertSame( $this->active, $this->run_for( '/sobre-nos/' ) );
	}
	public function test_template_rule_is_ignored_and_bad_urls_table_harmless(): void {
		$this->write_urls( [], [], [ 'templates' => [ 'retail' => [ 5 => [ 'super-socializer' ] ] ] ] );
		$this->assertSame( $this->active, $this->run_for( '/sobre-nos/' ) );
		$this->write_urls( [ 'retail' => 'garbage' ] );
		$this->assertSame( $this->active, $this->run_for( '/sobre-nos/' ) );
		$this->write_urls( 'garbage' );
		$this->assertSame( $this->active, $this->run_for( '/sobre-nos/' ) );
	}

	public function test_template_rule_as_compiled_is_ignored(): void {
		\BeltoftPerformance\Rules\Compiler::compile( [ new \BeltoftPerformance\Rules\Rule( 'unload:super-socializer', [ 'template:5' ] ), new \BeltoftPerformance\Rules\Rule( 'unload:super-socializer', [ 'url:/sobre-nos/' ] ) ], [ 'shop' => '/loja/', 'retail_host' => 'staging.mindthetrash.pt' ], $this->file );
		$gone = 'super-socializer/super_socializer.php';
		$this->assertContains( $gone, $this->run_for( '/loja/' ) );
		$this->assertContains( $gone, $this->run_for( '/outra/' ) );
		$this->assertNotContains( $gone, $this->run_for( '/sobre-nos/' ) );
	}
}
