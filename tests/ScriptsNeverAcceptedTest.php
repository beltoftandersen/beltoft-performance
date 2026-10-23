<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Proposals;
use BeltoftPerformance\Check\FileCandidates;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

final class ScriptsNeverAcceptedTest extends TestCase {
	private const MSG = 'Scripts can only be removed by hand: the load-time proof does not cover clicks.';

	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	public function test_store_refuses_script_rules(): void {
		$this->assertSame( self::MSG, Store::add( new Rule( 'script:the_champ_ss_general_scripts', [ 'blog' ], [ 'retail' ] ) ) );
		$this->assertCount( 0, Store::all() );
		$this->assertTrue( Store::add( new Rule( 'style:wooco-blocks', [ 'blog' ], [ 'retail' ] ) ) );
	}

	public function test_accept_error(): void {
		$this->assertSame( self::MSG, Store::accept_error( 'script:x-js' ) );
		$this->assertNull( Store::accept_error( 'style:x-css' ) );
		$this->assertNull( Store::accept_error( 'unload:x' ) );
	}

	public function test_trial_rules_still_validate(): void {
		$this->assertNull( Store::validate( new Rule( 'script:x-js', [ 'blog' ], [ 'retail' ] ) ) ); // trials of scripts still run
	}

	public function test_proposals_offer_no_accept_for_scripts(): void {
		$this->assertFalse( Proposals::acceptable( 'script:x-js' ) );
		$this->assertTrue( Proposals::acceptable( 'style:x-css' ) );
		$this->assertTrue( Proposals::acceptable( 'plugin:x' ) );
	}

	public function test_script_why_wording(): void {
		$js  = [ 'kind' => 'script', 'handle' => 'x-js', 'src' => 'https://s/wp-content/plugins/x/a.js', 'plugin' => 'x', 'deps' => [] ];
		$inv = [ 'blog' => [ 'guest' => [ 'status' => 200, 'assets' => [ $js ] ] ] ];
		$c   = FileCandidates::from( $inv, [ 'blog' => [ 'guest' => [ 'style' => [], 'script' => [ '/wp-content/plugins/x/a.js' => [ 'called' => 0, 'listeners' => 0 ] ] ] ] ], [] );
		$this->assertSame( 'Not used on load and scroll (does not prove clicks).', $c[0]['why']['blog'] );
	}
}
