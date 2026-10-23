<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Trial;

final class TrialFileRuleTest extends TestCase {
	private array $base;
	protected function setUp(): void {
		$this->base = [ 'status' => 200, 'errors' => [], 'html_hash' => 'h', 'assets' => [
			[ 'kind' => 'script', 'handle' => 'sweetalert2', 'plugin' => 'woo-smart-wishlist', 'deps' => [] ],
			[ 'kind' => 'script', 'handle' => 'woosw-frontend', 'plugin' => 'woo-smart-wishlist', 'deps' => [ 'sweetalert2' ] ],
			[ 'kind' => 'script', 'handle' => 'other', 'plugin' => 'x', 'deps' => [ 'jquery' ] ],
		] ];
	}
	public function test_cascade_from_assets(): void {
		$this->assertSame( [ 'sweetalert2', 'woosw-frontend' ], Trial::cascade_from_assets( $this->base['assets'], 'script', 'sweetalert2' ) );
	}
	public function test_file_rule_needs_browser_when_clean(): void {
		$t = $this->base; $t['assets'] = [ $this->base['assets'][2] ];
		$this->assertSame( [ 'needs_browser' => true ], Trial::problem( $this->base, $t, 'blog', 'script:sweetalert2' ) );
	}
	public function test_file_rule_needs_browser_on_sensitive_page(): void {
		$t = $this->base; $t['assets'] = [ $this->base['assets'][2] ];
		$this->assertSame( [ 'needs_browser' => true ], Trial::problem( $this->base, $t, 'checkout', 'script:sweetalert2' ) );
	}
	public function test_file_rule_not_removed(): void {
		$this->assertSame( 'The trial did not remove the file.', Trial::problem( $this->base, $this->base, 'blog', 'script:sweetalert2' )['problem'] );
	}
	public function test_file_rule_takes_unrelated_file(): void {
		$t = $this->base; $t['assets'] = [];
		$r = Trial::problem( $this->base, $t, 'blog', 'script:sweetalert2' );
		$this->assertSame( [ 'script:other' ], $r['gone'] );
	}
	public function test_file_rule_takes_core_file(): void {
		$b = $this->base; $b['assets'][] = [ 'kind' => 'script', 'handle' => 'jquery', 'plugin' => '', 'deps' => [] ];
		$t = $b; $t['assets'] = [ $b['assets'][2] ];
		$r = Trial::problem( $b, $t, 'blog', 'script:sweetalert2' );
		$this->assertContains( 'script:jquery', $r['gone'] );
	}
	public function test_file_rule_target_not_on_page(): void {
		$this->assertSame( 'This file does not appear on this page.', Trial::problem( $this->base, $this->base, 'blog', 'script:nope' )['problem'] );
	}
}
