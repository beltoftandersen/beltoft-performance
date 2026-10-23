<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Cli\Command;

final class CommandNewErrorsTest extends TestCase {
	public function test_only_errors_that_appear_with_the_trial_count(): void {
		$without = array( '390 https://x/ console: base noise' );
		$with    = array( '390 https://x/ console: base noise', '390 https://x/ pageerror: jQuery is not defined' );
		$this->assertSame( array( '390 https://x/ pageerror: jQuery is not defined' ), Command::new_errors( $with, $without ) );
	}

	public function test_bp_check_query_value_is_ignored(): void {
		$without = array( '1440 https://x/ requestfailed: https://x/a.js?bp_check=browser&v=1' );
		$with    = array( '1440 https://x/ requestfailed: https://x/a.js?bp_check=abc123&v=1' );
		$this->assertSame( array(), Command::new_errors( $with, $without ) );
	}

	public function test_same_error_on_another_viewport_is_new(): void {
		$this->assertSame( array( '1440 u e' ), Command::new_errors( array( '390 u e', '1440 u e' ), array( '390 u e' ) ) );
	}

	public function test_empty_and_identical(): void {
		$this->assertSame( array(), Command::new_errors( array(), array( 'a' ) ) );
		$this->assertSame( array(), Command::new_errors( array( 'a' ), array( 'a' ) ) );
	}
}
