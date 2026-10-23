<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Check\TestQueue;
use BeltoftPerformance\Check\Trial;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Rules\Rule;

/** "Only on this page" / "On all pages", "Only the files I tick", "Test on this page". */
final class PageChoicesTest extends TestCase {
	public function test_where_pages(): void {
		$this->assertSame( [ 'front' ], OverviewPage::where_pages( [ 'shop', 'front' ], 'here', 'front' ) );
		$this->assertSame( [ 'shop', 'front' ], OverviewPage::where_pages( [ 'shop', 'front' ], 'all', 'front' ) );
		$this->assertSame( [], OverviewPage::where_pages( [ 'shop', 'front' ], 'here', 'blog' ) ); // no longer suggested here: nothing, never widened to all pages
	}

	public function test_where_pages_from_ticked_pages(): void {
		$this->assertSame( [ 'shop', 'blog' ], OverviewPage::where_pages( [ 'front', 'shop', 'blog' ], [ 'blog', 'shop', 'checkout' ], 'front' ) ); // checkout: not one of its pages
		$this->assertSame( [], OverviewPage::where_pages( [ 'front', 'shop' ], [], 'front' ) ); // all unticked: nowhere
	}

	public function test_chosen_files_only_for_files_choice_and_only_file_targets(): void {
		$out = OverviewPage::chosen_files(
			[ 'unload:a', 'unload:b', 'unload:c' ],
			[ 'unload:a' => 'files', 'unload:b' => 'plugin', 'unload:c' => 'files' ],
			[ 'unload:a' => [ 'script:a-js', 'style:a-css', 'unload:x', 'script:a-js', 'script:bad handle' ], 'unload:b' => [ 'script:b-js' ] ]
		);
		$this->assertSame( [ 'unload:a' => [ 'script:a-js', 'style:a-css' ], 'unload:c' => [] ], $out );
	}

	public function test_as_queued_narrows_to_the_queued_pages(): void {
		$c = [ 'target' => 'unload:a', 'pages' => [ 'shop', 'front' ], 'why' => [ 'shop' => 'x', 'front' => 'y' ] ];
		$this->assertSame( [ 'front' ], TestQueue::as_queued( $c, [ 'front' ] )['pages'] );
		$this->assertSame( [ 'front' => 'y' ], TestQueue::as_queued( $c, [ 'front' ] )['why'] );
		$this->assertSame( [ 'shop', 'front' ], TestQueue::as_queued( $c, [] )['pages'] );
		$this->assertNull( TestQueue::as_queued( $c, [ 'blog' ] ) ); // queued for a page it no longer has: nothing to test, never the others
	}

	public function test_badge_reads_only_this_pages_rows(): void {
		$t = Trial::merge( null, [ 'ok' => false, 'check' => 'c1', 'pages' => [ 'front', 'shop' ], 'evidence' => [ [ 'page' => 'front', 'state' => 'guest' ], [ 'page' => 'shop', 'state' => 'guest', 'problem' => 'x' ] ] ] );
		$this->assertSame( 'trial_passed', Manager::trial_badge( $t, 'c1', 'front' ) );
		$this->assertSame( 'trial_failed', Manager::trial_badge( $t, 'c1', 'shop' ) );
		$this->assertSame( 'not_tested', Manager::trial_badge( $t, 'c1', 'blog' ) );
	}

	public function test_pages_already_off_are_not_offered_again(): void {
		$c     = [ 'unload:a' => [ 'target' => 'unload:a', 'pages' => [ 'shop', 'front' ] ] ];
		$trial = [ 'unload:a' => Trial::merge( null, [ 'ok' => true, 'check' => 'c1', 'pages' => [ 'shop', 'front' ], 'evidence' => [ [ 'page' => 'shop' ], [ 'page' => 'front' ] ] ] ) ];
		$rules = [ new Rule( 'unload:a', [ 'shop' ], [ 'retail' ], '', 'x', '' ) ];
		$s     = Ui::suggestion( 'unload:a', $c, $trial, 'c1', 'front', $rules );
		$this->assertSame( [ 'front' ], $s['pages'] );
		$this->assertNull( Ui::suggestion( 'unload:a', $c, $trial, 'c1', 'shop', $rules ) );
	}
}
