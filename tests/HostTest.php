<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Request\Classifier;
use BeltoftPerformance\Request\PageType;
use BeltoftPerformance\Request\RouteMap;

/** C1: beltoft-commerce rewrites home/siteurl to the request host on the B2B host; the host decision must use the stored home. */
final class HostTest extends TestCase {
	private $server;
	protected function setUp(): void {
		$this->server = $_SERVER;
		$GLOBALS['bp_test_options']['home'] = 'https://staging.mindthetrash.pt';
		// What BeltoftCommerce\Domain\UrlFilter does on b2b.staging.mindthetrash.pt.
		$GLOBALS['wp_filter']['option_home'] = array( static fn( $v ) => str_replace( '://staging.mindthetrash.pt', '://b2b.staging.mindthetrash.pt', (string) $v ) );
	}
	protected function tearDown(): void {
		$_SERVER = $this->server;
		$GLOBALS['bp_test_options']['home'] = 'http://staging.mindthetrash.pt';
		unset( $GLOBALS['wp_filter']['option_home'] );
	}
	public function test_home_url_is_filtered_in_this_setup(): void {
		$this->assertSame( 'https://b2b.staging.mindthetrash.pt', home_url() );
	}
	public function test_retail_host_reads_the_unfiltered_home_and_keeps_the_filter(): void {
		$this->assertSame( 'staging.mindthetrash.pt', RouteMap::retail_host() );
		$this->assertCount( 1, $GLOBALS['wp_filter']['option_home'] );
		$this->assertSame( 'https://b2b.staging.mindthetrash.pt', home_url() );
	}
	public function test_page_type_host_on_b2b_is_trade(): void {
		$_SERVER['HTTP_HOST'] = 'b2b.staging.mindthetrash.pt';
		$this->assertSame( 'trade', PageType::host() );
		$_SERVER['HTTP_HOST'] = 'B2B.staging.mindthetrash.pt:443';
		$this->assertSame( 'trade', PageType::host() );
		$_SERVER['HTTP_HOST'] = 'staging.mindthetrash.pt';
		$this->assertSame( 'retail', PageType::host() );
		$_SERVER['HTTP_HOST'] = 'Staging.MindTheTrash.pt:443';
		$this->assertSame( 'retail', PageType::host() );
		unset( $_SERVER['HTTP_HOST'] );
		$this->assertSame( 'retail', PageType::host() ); // CLI / cron: no request host
	}
	public function test_route_map_compiles_the_canonical_host(): void {
		$this->assertSame( 'staging.mindthetrash.pt', RouteMap::build()['retail_host'] );
	}
	public function test_host_key_is_shared_by_classifier(): void {
		$this->assertSame( 'trade', Classifier::host_key( 'b2b.staging.mindthetrash.pt', 'staging.mindthetrash.pt' ) );
		$this->assertSame( 'retail', Classifier::host_key( 'staging.mindthetrash.pt:8080', 'staging.mindthetrash.pt' ) );
		$this->assertSame( 'retail', Classifier::host_key( '', 'staging.mindthetrash.pt' ) );
		$this->assertSame( 'retail', Classifier::host_key( 'x.example', '' ) );
		$routes = array( 'retail_host' => 'staging.mindthetrash.pt' );
		$this->assertSame( 'retail', Classifier::classify( '/', array( 'HTTP_HOST' => 'staging.mindthetrash.pt:443' ), $routes )['host'] );
		$this->assertSame( 'trade', Classifier::classify( '/', array( 'HTTP_HOST' => 'b2b.staging.mindthetrash.pt' ), $routes )['host'] );
	}
}
