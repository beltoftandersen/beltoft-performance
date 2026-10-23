<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Runner;

final class RunnerCandidatesTest extends TestCase {
	private function rep( string $page, string $state, array $plugins, array $sigs = [], array $assets = [] ): array {
		return [ 'page' => $page, 'state' => $state, 'status' => 200, 'plugins' => $plugins, 'signatures' => $sigs, 'assets' => $assets ];
	}
	private function by_target( array $c ): array {
		$o = [];
		foreach ( $c as $x ) { $o[ $x['target'] ] = $x; }
		return $o;
	}
	public function test_candidates(): void {
		$inv = [
			'shop' => [
				'guest' => $this->rep( 'shop', 'guest', [ 'woocommerce', 'beltoft-gift-cards', 'used-plugin', 'idle-plugin' ], [ 'used-plugin' => [ 'x' ] ], [ [ 'kind' => 'script', 'handle' => 'idle-js', 'src' => '', 'plugin' => 'idle-plugin', 'deps' => [] ] ] ),
				'cart'  => $this->rep( 'shop', 'cart', [ 'woocommerce', 'used-plugin', 'idle-plugin' ] ),
			],
			'page' => [
				'guest' => $this->rep( 'page', 'guest', [ 'idle-plugin' ] ),
			],
			'product' => [
				'guest' => $this->rep( 'product', 'guest', [ 'idle-plugin' ] ),
				'cart'  => $this->rep( 'product', 'cart', [ 'idle-plugin' ], [ 'idle-plugin' => [ 'y' ] ] ),
			],
		];
		$c = $this->by_target( Runner::candidates_from( $inv ) );
		$this->assertArrayHasKey( 'unload:idle-plugin', $c );
		$this->assertSame( [ 'shop' ], $c['unload:idle-plugin']['pages'] );
		$this->assertArrayHasKey( 'plugin:idle-plugin', $c );
		$this->assertSame( [ 'page' ], $c['plugin:idle-plugin']['pages'] );
		$this->assertArrayNotHasKey( 'unload:used-plugin', $c );
		$this->assertArrayNotHasKey( 'unload:woocommerce', $c );
		$this->assertArrayNotHasKey( 'unload:beltoft-gift-cards', $c );
		$this->assertNotContains( 'product', $c['unload:idle-plugin']['pages'] );
	}
	public function test_non_200_reports_are_ignored(): void {
		$redirect = $this->rep( 'cart', 'guest', [ 'idle-plugin' ] ); $redirect['status'] = 302;
		$ok = $this->rep( 'shop', 'guest', [ 'idle-plugin' ] ); $ok['status'] = 200;
		$only = Runner::candidates_from( [ 'cart' => [ 'guest' => $redirect ] ] );
		$this->assertSame( [], $only );
		$both = $this->by_target( Runner::candidates_from( [ 'cart' => [ 'guest' => $redirect ], 'shop' => [ 'guest' => $ok ] ] ) );
		$this->assertSame( [ 'shop' ], $both['unload:idle-plugin']['pages'] );
		$blocked = $this->rep( 'shop', 'cart', [ 'idle-plugin' ], [ 'idle-plugin' => [ 'x' ] ] ); $blocked['status'] = 302;
		$this->assertArrayHasKey( 'unload:idle-plugin', $this->by_target( Runner::candidates_from( [ 'shop' => [ 'guest' => $ok, 'cart' => $blocked ] ] ) ) );
	}
}
