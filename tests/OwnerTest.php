<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Support\Owner;
use BeltoftPerformance\Cli\Command;

final class OwnerTest extends TestCase {
	public function test_root_hands_files_to_the_uploads_owner(): void {
		$this->assertSame( array( 33, 33 ), Owner::target( 0, 33, 33 ) );
	}

	public function test_a_normal_user_changes_nothing(): void {
		$this->assertNull( Owner::target( 33, 33, 33 ) );
	}

	public function test_never_hands_files_to_root(): void {
		$this->assertNull( Owner::target( 0, 0, 0 ) );
	}

	public function test_missing_dir_under_a_writable_parent_is_fine(): void {
		$base = sys_get_temp_dir() . '/bp-owner-' . getmypid();
		mkdir( $base );
		$this->assertNull( Owner::writable_error( $base . '/a/b' ) );
		rmdir( $base );
	}

	public function test_a_file_where_the_dir_should_be_is_an_error(): void {
		$f = tempnam( sys_get_temp_dir(), 'bp' );
		$err = Owner::writable_error( $f );
		unlink( $f );
		$this->assertNotNull( $err );
		$this->assertStringContainsString( 'not a directory', $err );
	}

	public function test_error_names_the_dir(): void {
		$f = tempnam( sys_get_temp_dir(), 'bp' );
		$err = (string) Owner::writable_error( $f . '/sub' );
		unlink( $f );
		$this->assertStringContainsString( '/sub', $err );
	}

	public function test_chrome_option_is_passed_to_the_browser_script(): void {
		$this->assertSame( array( 'mode' => 'nav', 'chrome' => '/opt/c' ), Command::with_chrome( array( 'mode' => 'nav' ), array( 'chrome' => '/opt/c' ) ) );
		$this->assertSame( array( 'mode' => 'nav' ), Command::with_chrome( array( 'mode' => 'nav' ), array() ) );
		$this->assertSame( array( 'mode' => 'nav' ), Command::with_chrome( array( 'mode' => 'nav' ), array( 'chrome' => '' ) ) );
	}
}
