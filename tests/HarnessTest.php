<?php
use PHPUnit\Framework\TestCase;

final class HarnessTest extends TestCase {
	public function test_autoloader_resolves_plugin_class(): void {
		$this->assertTrue( class_exists( \BeltoftPerformance\Plugin::class ) );
	}
	public function test_disabled_constant_is_respected(): void {
		$this->assertFalse( \BeltoftPerformance\Plugin::disabled() );
	}
}
