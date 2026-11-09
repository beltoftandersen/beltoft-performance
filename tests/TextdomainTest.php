<?php
use PHPUnit\Framework\TestCase;

/** Translations load on every request (front end too) and the header names the folder. */
final class TextdomainTest extends TestCase {
	public function test_textdomain_loads_before_the_front_end_return() {
		$src   = (string) file_get_contents( dirname( __DIR__ ) . '/src/Plugin.php' );
		$load  = strpos( $src, "load_plugin_textdomain( 'beltoft-performance'" );
		$front = strpos( $src, 'if ( self::is_front() )' );
		$this->assertNotFalse( $load );
		$this->assertNotFalse( $front );
		$this->assertLessThan( $front, $load );
	}

	public function test_header_has_domain_path() {
		$head = (string) file_get_contents( dirname( __DIR__ ) . '/beltoft-performance.php', false, null, 0, 1200 );
		$this->assertMatchesRegularExpression( '/^\s*\*\s*Domain Path:\s*\/languages\s*$/m', $head );
	}
}
