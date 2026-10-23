<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Recorder;

final class RecorderTest extends TestCase {
	public function test_is_page_request(): void {
		$get = array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/loja/' );
		$this->assertTrue( Recorder::is_page_request( $get, array() ) );
		$this->assertFalse( Recorder::is_page_request( array( 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/loja/' ), array() ) );
		$this->assertFalse( Recorder::is_page_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/wp-json/wc/store/v1/cart' ), array() ) );
		$this->assertFalse( Recorder::is_page_request( array( 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/?rest_route=/wc/store' ), array( 'rest_route' => '/wc/store' ) ) );
		$this->assertFalse( Recorder::is_page_request( $get, array( 'wc-ajax' => 'get_refreshed_fragments' ) ) );
	}

	public function test_qa_user_ok(): void {
		$cfg = array( 'login' => 'qa', 'email' => 'qa@mindthetrash.pt' );
		$this->assertTrue( Recorder::qa_user_ok( array( 'customer' ), false, 'QA@mindthetrash.pt', $cfg ) );
		$this->assertFalse( Recorder::qa_user_ok( array(), false, 'qa@mindthetrash.pt', $cfg ) );
		$this->assertFalse( Recorder::qa_user_ok( array( 'customer', 'shop_manager' ), false, 'qa@mindthetrash.pt', $cfg ) );
		$this->assertFalse( Recorder::qa_user_ok( array( 'customer' ), true, 'qa@mindthetrash.pt', $cfg ) );
		$this->assertFalse( Recorder::qa_user_ok( array( 'customer' ), false, 'other@mindthetrash.pt', $cfg ) );
		$cfg2 = array( 'login' => 'qa', 'email' => 'qa@example.com' );
		$this->assertFalse( Recorder::qa_user_ok( array( 'customer' ), false, 'qa@example.com', $cfg2 ) );
	}
	public function test_job_matches_request_path_and_query(): void {
		$job = array( 'id' => 'r1', 'path' => '/loja/' );
		$this->assertTrue( Recorder::job_matches_request( $job, '/loja/?bp_check=r1' ) );
		$this->assertTrue( Recorder::job_matches_request( $job, '/Loja//?orderby=x&bp_check=r1' ) );
		$this->assertFalse( Recorder::job_matches_request( $job, '/loja/' ) );                       // no bp_check
		$this->assertFalse( Recorder::job_matches_request( $job, '/carrinho/?bp_check=r1' ) );       // replayed elsewhere
		$this->assertFalse( Recorder::job_matches_request( array( 'id' => 'r1' ), '/loja/?bp_check=r1' ) ); // unsigned path
	}
}
