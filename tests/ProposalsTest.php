<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Proposals;

final class ProposalsTest extends TestCase {
	public function test_group_puts_plugins_before_files(): void {
		$g = Proposals::group( [ [ 'target' => 'style:a' ], [ 'target' => 'plugin:b' ], [ 'target' => 'unload:c' ], [ 'target' => 'script:d' ] ] );
		$this->assertSame( [ 'plugin:b', 'unload:c' ], array_column( $g['plugins'], 'target' ) );
		$this->assertSame( [ 'style:a', 'script:d' ], array_column( $g['files'], 'target' ) );
	}
}
