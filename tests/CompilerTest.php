<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Compiler;

final class CompilerTest extends TestCase {
	private $temp_dir;

	protected function setUp(): void {
		$this->temp_dir = sys_get_temp_dir();
	}

	protected function tearDown(): void {
		array_map( 'unlink', glob( $this->temp_dir . '/bp-test-rules-' . getmypid() . '*.php' ) );
		array_map( 'unlink', glob( $this->temp_dir . '/bp-test-rules-' . getmypid() . '*.tmp' ) );
		@rmdir( $this->temp_dir . '/bp-unwritable-' . getmypid() );
	}

	public function test_compiles_only_unload_rules_grouped_by_host_and_page(): void {
		$file = $this->temp_dir . '/bp-test-rules-' . getmypid() . '.php';
		$ok = Compiler::compile( [
			new Rule( 'unload:pymntpl-paypal-woocommerce', [ 'shop', 'product' ] ),
			new Rule( 'unload:super-socializer', [ 'shop' ] ),
			new Rule( 'script:sweetalert2', [ 'shop' ] ),
		], [ 'shop' => '/loja/' ], $file );
		$this->assertTrue( $ok );
		$data = include $file;
		$this->assertSame( 1, $data['v'] );
		$this->assertSame( [ 'pymntpl-paypal-woocommerce', 'super-socializer' ], $data['unload']['retail']['shop'] );
		$this->assertSame( [ 'pymntpl-paypal-woocommerce' ], $data['unload']['retail']['product'] );
		$this->assertSame( '/loja/', $data['routes']['shop'] );
	}

	public function test_unwritable_location_returns_false_with_no_tmp(): void {
		$f = tempnam( $this->temp_dir, 'bp-' );
		$unwritable_path = $f . '/sub/rules.php';
		$ok = Compiler::compile( [], [], $unwritable_path );
		$this->assertFalse( $ok );
		$tmp_files = glob( $this->temp_dir . '/bp-*.tmp' );
		$this->assertEmpty( $tmp_files );
		unlink( $f );
	}

	public function test_multiple_compiles_leave_no_tmp_files(): void {
		$file = $this->temp_dir . '/bp-test-rules-' . getmypid() . '.php';
		Compiler::compile( [ new Rule( 'unload:x', [ 'shop' ] ) ], [ 'shop' => '/' ], $file );
		Compiler::compile( [ new Rule( 'unload:y', [ 'shop' ] ) ], [ 'shop' => '/' ], $file );
		$tmp_files = glob( $this->temp_dir . '/bp-test-rules-' . getmypid() . '*.tmp' );
		$this->assertEmpty( $tmp_files );
	}

	public function test_check_secret_written_only_when_passed(): void {
		$file = $this->temp_dir . '/bp-test-rules-' . getmypid() . '-secret.php';
		$this->assertTrue( Compiler::compile( [], [], $file, 'sek' ) );
		$this->assertSame( 'sek', ( include $file )['check_secret'] );
		$this->assertSame( '0640', substr( sprintf( '%o', fileperms( $file ) ), -4 ) );
		$this->assertTrue( Compiler::compile( [], [], $file ) );
		$this->assertArrayNotHasKey( 'check_secret', include $file );
	}
	public function test_change_error_reports_unwritten_rule_file(): void {
		$this->assertNull( Compiler::change_error( true, true ) );
		$this->assertSame( 'The rule was saved but the rules file was not written, so the loader still uses the old rules.', Compiler::change_error( true, false ) );
		$this->assertSame( 'Invalid name.', Compiler::change_error( 'Invalid name.', true ) );
		$this->assertSame( 'Invalid name.', Compiler::change_error( 'Invalid name.', false ) );
	}

	public function test_url_scopes_compile_to_unload_urls(): void {
		$file = $this->temp_dir . '/bp-test-rules-' . getmypid() . '.php';
		$this->assertTrue( Compiler::compile( [
			new Rule( 'unload:b-plugin', [ 'url:/sobre-nos/', 'shop' ] ),
			new Rule( 'unload:a-plugin', [ 'url:/sobre-nos/' ] ),
			new Rule( 'style:s', [ 'url:/x/' ] ),
			new Rule( 'plugin:p', [ 'template:5' ] ),
		], [], $file ) );
		$data = include $file;
		$this->assertSame( [ 'retail' => [ '/sobre-nos/' => [ 'a-plugin', 'b-plugin' ] ] ], $data['unload_urls'] );
		$this->assertSame( [ 'retail' => [ 'shop' => [ 'b-plugin' ] ] ], $data['unload'] );
	}

	public function test_template_scopes_never_reach_the_file_even_for_unload(): void {
		$file = $this->temp_dir . '/bp-test-rules-' . getmypid() . '.php';
		$this->assertTrue( Compiler::compile( [ new Rule( 'unload:x', [ 'template:5', 'shop' ] ), new Rule( 'unload:y', [ 'template:5' ] ) ], [], $file ) );
		$data = include $file;
		$this->assertSame( [ 'retail' => [ 'shop' => [ 'x' ] ] ], $data['unload'] );
		$this->assertSame( [], $data['unload_urls'] );
	}
	public function test_bad_url_shapes_are_skipped(): void {
		$file = $this->temp_dir . '/bp-test-rules-' . getmypid() . '.php';
		$this->assertTrue( Compiler::compile( [ new Rule( 'unload:x', [ 'url:no-slash', 'url:/ok/' ] ) ], [], $file ) );
		$this->assertSame( [ 'retail' => [ '/ok/' => [ 'x' ] ] ], ( include $file )['unload_urls'] );
	}
}
