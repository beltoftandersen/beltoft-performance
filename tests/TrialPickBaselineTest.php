<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\Trial;

final class TrialPickBaselineTest extends TestCase {
	public function test_fresh_valid_wins(): void {
		$f = [ 'status' => 200, 'html_hash' => 'new' ]; $s = [ 'status' => 200, 'html_hash' => 'old' ];
		$this->assertSame( [ $f, false ], Trial::pick_baseline( $f, $s ) );
	}
	public function test_null_falls_back(): void {
		$s = [ 'status' => 200 ];
		$this->assertSame( [ $s, true ], Trial::pick_baseline( null, $s ) );
	}
	public function test_non_200_falls_back(): void {
		$s = [ 'status' => 200 ];
		$this->assertSame( [ $s, true ], Trial::pick_baseline( [ 'status' => 500 ], $s ) );
	}
	public function test_old_file_is_stale(): void {
		$s = [ 'status' => 200, 'html_hash' => 'old' ]; $f = [ 'status' => 200, 'html_hash' => 'x' ];
		$this->assertSame( [ $s, true ], Trial::pick_baseline( $f, $s, 100, 200 ) );
		$this->assertSame( [ $f, false ], Trial::pick_baseline( $f, $s, 200, 200 ) );
	}
}
