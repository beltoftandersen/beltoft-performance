<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Cli\Command;

final class CommandRunDecisionTest extends TestCase {
	private function good(): array {
		return array(
			'pages'  => array(
				array( 'url' => 'https://x/', 'viewport' => 390, 'status' => 200, 'errors' => array() ),
				array( 'url' => 'https://x/', 'viewport' => 1440, 'status' => 200, 'errors' => array() ),
			),
			'errors' => array(),
		);
	}

	public function test_good_run_is_not_failed(): void {
		$this->assertFalse( Command::run_failed( $this->good(), 2 ) );
	}

	public function test_fatal_goto_empty_count_and_status_zero_fail(): void {
		$r = $this->good(); $r['errors'][] = 'fatal: browser died';
		$this->assertTrue( Command::run_failed( $r, 2 ) );
		$r = $this->good(); $r['errors'][] = '390 https://x/ goto: Timeout';
		$this->assertTrue( Command::run_failed( $r, 2 ) );
		$this->assertTrue( Command::run_failed( array( 'pages' => array(), 'errors' => array( 'fatal: x' ) ), 2 ) );
		$this->assertTrue( Command::run_failed( array( 'pages' => array(), 'errors' => array() ), 2 ) );
		$this->assertTrue( Command::run_failed( $this->good(), 4 ) );
		$r = $this->good(); $r['pages'][1]['status'] = 0;
		$this->assertTrue( Command::run_failed( $r, 2 ) );
	}

	public function test_status_changes(): void {
		$with = $this->good(); $with['pages'][1]['status'] = 500;
		$ch = Command::status_changes( $with, $this->good() );
		$this->assertCount( 1, $ch );
		$this->assertStringContainsString( '1440', $ch[0] );
		$this->assertSame( array(), Command::status_changes( $this->good(), $this->good() ) );
	}

	private function pg( int $vp, array $over = array() ): array {
		return $over + array( 'viewport' => $vp, 'status' => 200, 'errors' => array(), 'layout' => array( 'h1@0|0,0,100,20', 'p@0|0,30,100,20' ), 'facts' => array( 'cart_items' => 1, 'shipping' => array( 'Envio: 4,00' ) ), 'flow_error' => '' );
	}

	private function run_of( array $over390 = array(), array $over1440 = array() ): array {
		return array( 'pages' => array( $this->pg( 390, $over390 ), $this->pg( 1440, $over1440 ) ), 'errors' => array() );
	}

	public function test_job_problem_identical_is_empty(): void {
		$this->assertSame( '', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of(), true ) );
	}

	public function test_job_problem_layout_change(): void {
		$moved = array( 'layout' => array( 'h1@0|0,0,100,20', 'p@0|0,300,100,20' ) );
		$this->assertStringContainsString( 'layout', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of( array(), $moved ), false ) );
	}

	private function filler( int $n ): array {
		$o = array();
		for ( $i = 0; $i < $n; $i++ ) { $o[] = "li@$i|0,$i,100,20|block"; }
		return $o;
	}

	public function test_job_problem_noisy_element_masked(): void {
		$a = array( 'layout' => array_merge( $this->filler( 20 ), array( 'h1@0|0,0,100,20', 'p@0|0,30,100,20' ) ) );
		$b = array( 'layout' => array_merge( $this->filler( 20 ), array( 'h1@0|0,0,100,20', 'p@0|0,90,100,20' ) ) );
		$this->assertSame( '', Command::job_problem( $this->run_of( $a, $a ), $this->run_of( $b, $a ), $this->run_of( $a, $a ), false ) );
	}

	public function test_job_problem_too_much_noise_is_a_problem(): void {
		$a = array( 'layout' => array_merge( $this->filler( 10 ), array( 'h1@0|0,0,100,20', 'p@0|0,30,100,20' ) ) );
		$b = array( 'layout' => array_merge( $this->filler( 10 ), array( 'h1@0|0,0,100,20', 'p@0|0,90,100,20' ) ) ); // 1 of 12 = 8.3 %
		$this->assertStringContainsString( 'too unstable', Command::job_problem( $this->run_of( $a, $a ), $this->run_of( $b, $a ), $this->run_of( $a, $a ), false ) );
		$c = array( 'layout' => array_merge( $this->filler( 11 ), array( 'h1@0|0,0,100,20', 'p@0|0,30,100,20' ) ) );
		$d = array( 'layout' => array_merge( $this->filler( 11 ), array( 'h1@0|0,0,100,20', 'p@0|0,90,100,20' ) ) ); // 1 of 13 = 7.7 %
		$this->assertSame( '', Command::job_problem( $this->run_of( $c, $c ), $this->run_of( $d, $c ), $this->run_of( $c, $c ), false ) );
	}

	public function test_job_problem_truncated_layout_in_any_run(): void {
		$tr = array( 'layout_truncated' => true );
		$this->assertStringContainsString( 'too large', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of( $tr ), false ) );
		$this->assertStringContainsString( 'too large', Command::job_problem( $this->run_of( array(), $tr ), $this->run_of(), $this->run_of(), false ) );
		$this->assertStringContainsString( 'too large', Command::job_problem( $this->run_of(), $this->run_of( $tr ), $this->run_of(), false ) );
		$this->assertSame( '', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of( array( 'layout_truncated' => false ) ), false ) );
	}

	public function test_job_problem_trial_flow_failed(): void {
		$this->assertStringContainsString( 'Cart/checkout', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of( array( 'flow_error' => 'no form' ) ), true ) );
	}

	public function test_job_problem_shipping_fact_changed(): void {
		$this->assertStringContainsString( 'Checkout changed', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of( array( 'facts' => array( 'cart_items' => 1, 'shipping' => array( 'Envio: 9,00' ) ) ) ), true ) );
	}

	public function test_job_problem_trial_layout_failed(): void {
		$this->assertNotSame( '', Command::job_problem( $this->run_of(), $this->run_of(), $this->run_of( array( 'layout' => array(), 'layout_error' => 'boom' ) ), false ) );
	}

	public function test_merge_plugin_row_with_job_problem_is_not_ok(): void {
		$r = Command::merge_browser_results( array( array( 'page' => 'shop', 'state' => 'guest' ) ), array( 'shop|guest' => 'The page layout changed: x' ), array(), array() );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'layout', $r['evidence'][0]['problem'] );
	}

	public function test_merge_appends_to_existing_problem(): void {
		$r = Command::merge_browser_results( array( array( 'page' => 'shop', 'state' => 'guest', 'problem' => 'antes' ) ), array( 'shop|guest' => 'depois' ), array(), array() );
		$this->assertStringContainsString( 'antes', $r['evidence'][0]['problem'] );
		$this->assertStringContainsString( 'depois', $r['evidence'][0]['problem'] );
	}

	public function test_merge_clean_browsed_file_rule_clears_needs_browser(): void {
		$r = Command::merge_browser_results( array( array( 'page' => 'shop', 'state' => 'guest', 'needs_browser' => true ) ), array(), array(), array( 'shop|guest' => true ) );
		$this->assertTrue( $r['ok'] );
		$this->assertArrayNotHasKey( 'needs_browser', $r['evidence'][0] );
	}

	public function test_merge_inactive_trial_is_a_problem(): void {
		$r = Command::merge_browser_results( array( array( 'page' => 'shop', 'state' => 'guest' ) ), array(), array( 'shop|guest' => true ), array() );
		$this->assertFalse( $r['ok'] );
		$this->assertNotEmpty( $r['evidence'][0]['problem'] );
	}

	public function test_merge_unbrowsed_needs_browser_is_not_ok(): void {
		$r = Command::merge_browser_results( array( array( 'page' => 'shop', 'state' => 'guest', 'needs_browser' => true ) ), array(), array(), array() );
		$this->assertFalse( $r['ok'] );
	}
	public function test_stored_browser_result_drops_raw_runs(): void {
		$raw  = array( 'with' => $this->run_of(), 'without' => $this->run_of(), 'new_errors' => array( 'x' ), 'problem' => 'p', 'trial_active' => true );
		$res  = array( 'jobs' => array( 'checkout|cart' => $raw ), 'new_errors' => array( 'checkout|cart x' ), 'ok' => false );
		$slim = Command::stored_browser_result( $res );
		$this->assertSame( array( 'checkout|cart' => array( 'new_errors' => array( 'x' ), 'problem' => 'p', 'trial_active' => true ) ), $slim['jobs'] );
		$this->assertSame( array( 'checkout|cart x' ), $slim['new_errors'] );
		$this->assertFalse( $slim['ok'] );
		$this->assertLessThan( strlen( serialize( $res ) ), strlen( serialize( $slim ) ) );
	}
}
