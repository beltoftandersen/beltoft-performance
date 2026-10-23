<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Trial;

final class TrialApprovesTest extends TestCase {
	public function test_same_check_and_pages_in_any_order_approves(): void {
		$this->assertTrue( Trial::approves( [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'shop', 'front' ] ], 'c1', [ 'front', 'shop' ] ) );
	}
	public function test_not_ok_does_not_approve(): void {
		$this->assertFalse( Trial::approves( [ 'ok' => false, 'check' => 'c1', 'pages' => [ 'shop' ] ], 'c1', [ 'shop' ] ) );
	}
	public function test_other_check_does_not_approve(): void {
		$this->assertFalse( Trial::approves( [ 'ok' => true, 'check' => 'c0', 'pages' => [ 'shop' ] ], 'c1', [ 'shop' ] ) );
	}
	public function test_different_pages_do_not_approve(): void {
		$this->assertFalse( Trial::approves( [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'shop' ] ], 'c1', [ 'shop', 'front' ] ) );
	}
	public function test_part_of_the_tested_pages_approves(): void {
		$this->assertTrue( Trial::approves( [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'shop', 'front', 'blog' ] ], 'c1', [ 'front' ] ) );
		$this->assertFalse( Trial::approves( [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'shop', 'front' ] ], 'c1', [] ) );
	}
	public function test_merge_adds_pages_of_a_second_pass(): void {
		$old = [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'front' ], 'evidence' => [ [ 'page' => 'front', 'state' => 'guest' ] ] ];
		$new = [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'shop' ], 'evidence' => [ [ 'page' => 'shop', 'state' => 'guest' ] ] ];
		$m   = Trial::merge( $old, $new );
		$this->assertSame( [ 'front', 'shop' ], $m['pages'] );
		$this->assertCount( 2, $m['evidence'] );
		$this->assertTrue( Trial::approves( $m, 'c1', [ 'front', 'shop' ] ) );
	}
	public function test_merge_keeps_other_pages_when_one_page_fails(): void {
		$old = Trial::merge( null, [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'front', 'shop' ], 'evidence' => [ [ 'page' => 'front', 'state' => 'guest' ], [ 'page' => 'shop', 'state' => 'guest' ] ] ] );
		$bad = [ 'ok' => false, 'check' => 'c1', 'pages' => [ 'shop' ], 'evidence' => [ [ 'page' => 'shop', 'state' => 'guest', 'problem' => 'x' ] ] ];
		$m   = Trial::merge( $old, $bad );
		$this->assertSame( [ 'front' ], $m['passed'] );
		$this->assertFalse( $m['ok'] );
		$this->assertTrue( Trial::approves( $m, 'c1', [ 'front' ] ) );
		$this->assertFalse( Trial::approves( $m, 'c1', [ 'shop' ] ) );
		// and a pass on front never hides the failure on shop
		$again = Trial::merge( $m, [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'front' ], 'evidence' => [ [ 'page' => 'front', 'state' => 'guest' ] ] ] );
		$this->assertSame( [ 'front' ], $again['passed'] );
		$this->assertContains( 'x', array_column( $again['evidence'], 'problem' ) );
	}
	public function test_merge_replaces_another_check(): void {
		$old   = [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'front', 'shop' ], 'evidence' => [] ];
		$other = [ 'ok' => true, 'check' => 'c2', 'pages' => [ 'shop' ], 'evidence' => [ [ 'page' => 'shop', 'state' => 'guest' ] ] ];
		$this->assertSame( [ 'shop' ], Trial::merge( $old, $other )['pages'] );
		$this->assertSame( [ 'shop' ], Trial::merge( null, $other )['passed'] );
	}
	public function test_passed_pages_needs_the_browser_for_its_own_pages(): void {
		$t = [ 'pages' => [ 'cart', 'shop' ], 'evidence' => [ [ 'page' => 'cart', 'needs_browser' => true ], [ 'page' => 'shop' ] ] ];
		$this->assertSame( [ 'shop' ], Trial::passed_pages( $t ) );
		$this->assertSame( [ 'cart', 'shop' ], Trial::passed_pages( $t + [ 'browser' => [ 'ok' => true, 'pages' => [ 'cart' ] ] ] ) );
		$this->assertSame( [ 'shop' ], Trial::passed_pages( $t + [ 'browser' => [ 'ok' => true, 'pages' => [ 'shop' ] ] ] ) ); // the browser never saw cart
		$this->assertSame( [], Trial::passed_pages( $t + [ 'browser' => [ 'ok' => false, 'pages' => [ 'cart', 'shop' ] ] ] ) );
	}
	public function test_merge_retest_of_a_page_replaces_its_evidence_and_drops_a_browser_pass_over_it(): void {
		$old = [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'front', 'shop' ], 'browser' => [ 'ok' => true ], 'evidence' => [ [ 'page' => 'front', 'old' => 1 ], [ 'page' => 'shop', 'old' => 1 ] ] ];
		$new = [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'front' ], 'evidence' => [ [ 'page' => 'front', 'new' => 1 ] ] ];
		$m   = Trial::merge( $old, $new );
		$this->assertSame( [ 'front', 'shop' ], $m['pages'] );
		$this->assertSame( [ [ 'page' => 'shop', 'old' => 1 ], [ 'page' => 'front', 'new' => 1 ] ], $m['evidence'] );
		$this->assertArrayNotHasKey( 'browser', $m );
	}
	public function test_missing_keys_do_not_approve(): void {
		$this->assertFalse( Trial::approves( [], 'c1', [ 'shop' ] ) );
		$this->assertFalse( Trial::approves( [ 'ok' => true ], 'c1', [ 'shop' ] ) );
		$this->assertFalse( Trial::approves( [ 'ok' => true, 'check' => 'c1' ], 'c1', [ 'shop' ] ) );
	}
}
