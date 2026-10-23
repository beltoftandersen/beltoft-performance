<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Cleanup\Database;
use BeltoftPerformance\Cleanup\Switches;

/** Nightly database clean-up: cut-offs, schedule time, and its own section in Settings. */
final class DatabaseCleanupTest extends TestCase {
	public function test_next_run_is_the_next_0330_site_time(): void {
		$utc = strtotime( '2026-10-05 08:00:00 UTC' );
		$this->assertSame( strtotime( '2026-10-06 03:30:00 UTC' ), Database::next_run( $utc, 0 ) );
		$this->assertSame( strtotime( '2026-10-06 02:30:00 UTC' ), Database::next_run( $utc, 3600 ), 'site one hour ahead of UTC' );
		$this->assertSame( strtotime( '2026-10-05 03:30:00 UTC' ), Database::next_run( strtotime( '2026-10-05 01:00:00 UTC' ), 0 ), 'still today' );
	}

	public function test_switch_is_off_by_default_and_in_its_own_group(): void {
		$this->assertFalse( Switches::definitions()['db_cleanup'][1] );
		$this->assertSame( [ 'db_cleanup' ], Switches::DB_KEYS );
		$this->assertNotContains( 'db_cleanup', Switches::SCRIPT_KEYS );
	}
}
