<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Cli\Command;

final class QaCartTest extends TestCase {
	private array $cfg = array( 'login' => 'qa-shop-shot', 'email' => 'qa-shop-shot@mindthetrash.pt' );

	public function test_only_the_configured_qa_customer_gets_a_cart_key(): void {
		$this->assertSame( '_woocommerce_persistent_cart_1', Command::qa_cart_meta_key( $this->cfg, 'qa-shop-shot', array( 'customer' ), false, 'qa-shop-shot@mindthetrash.pt', 1 ) );
	}

	public function test_anyone_else_gets_nothing(): void {
		$this->assertSame( '', Command::qa_cart_meta_key( $this->cfg, 'maria', array( 'customer' ), false, 'qa-shop-shot@mindthetrash.pt', 1 ) );
		$this->assertSame( '', Command::qa_cart_meta_key( $this->cfg, 'qa-shop-shot', array( 'customer' ), false, 'maria@gmail.com', 1 ) );
		$this->assertSame( '', Command::qa_cart_meta_key( $this->cfg, 'qa-shop-shot', array( 'administrator' ), true, 'qa-shop-shot@mindthetrash.pt', 1 ) );
		$this->assertSame( '', Command::qa_cart_meta_key( $this->cfg, 'qa-shop-shot', array( 'customer', 'shop_manager' ), false, 'qa-shop-shot@mindthetrash.pt', 1 ) );
		$this->assertSame( '', Command::qa_cart_meta_key( array(), '', array( 'customer' ), false, '', 1 ) );
	}

	public function test_checkout_check_gets_a_one_product_cart_only_for_the_qa_customer_on_an_empty_checkout(): void {
		$this->assertTrue( \BeltoftPerformance\Check\Recorder::needs_qa_cart( true, true, true, 42 ) );
		$this->assertFalse( \BeltoftPerformance\Check\Recorder::needs_qa_cart( false, true, true, 42 ), 'not checkout' );
		$this->assertFalse( \BeltoftPerformance\Check\Recorder::needs_qa_cart( true, false, true, 42 ), 'not the QA customer' );
		$this->assertFalse( \BeltoftPerformance\Check\Recorder::needs_qa_cart( true, true, false, 42 ), 'cart already has items' );
		$this->assertFalse( \BeltoftPerformance\Check\Recorder::needs_qa_cart( true, true, true, 0 ), 'no product to add' );
	}
}
