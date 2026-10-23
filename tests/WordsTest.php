<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Words;
use BeltoftPerformance\Rules\Rule;

/** Plain-words helpers of the admin screen: page names, friendly names, sentences. */
final class WordsTest extends TestCase {
	private const HEADERS = [ 'relevanssi' => 'Relevanssi', 'woo-dpd-portugal' => 'DPD Portugal for WooCommerce', 'super-socializer' => 'Super Socializer', 'odd-thing' => '' ];

	public function test_page_names(): void {
		$this->assertSame( 'Home page', Words::page_name( 'front' ) );
		$this->assertSame( 'Product pages', Words::page_name( 'product' ) );
		$this->assertSame( 'Category pages', Words::page_name( 'archive_cat' ) );
		$this->assertSame( 'Order confirmation', Words::page_name( 'order_received' ) );
		$this->assertSame( 'My account', Words::page_name( 'account' ) );
		$this->assertSame( 'Blog posts', Words::page_name( 'post' ) );
		$this->assertSame( 'Other pages', Words::page_name( 'page' ) );
		$this->assertSame( 'odd', Words::page_name( 'odd' ) );
		$this->assertSame( 'Home page, Shop, Blog', Words::page_list( [ 'blog', 'front', 'shop' ] ) ); // shop order, not given order
		$this->assertCount( 14, Words::PAGE_ORDER );
	}

	public function test_plugin_names(): void {
		$this->assertSame( 'Relevanssi (better search)', Words::plugin_name( 'relevanssi', self::HEADERS ) ); // the short map wins
		$this->assertSame( 'Wishlist', Words::plugin_name( 'woo-smart-wishlist', [ 'woo-smart-wishlist' => 'WPC Smart Wishlist for WooCommerce' ] ) );
		$this->assertSame( 'Super Socializer', Words::plugin_name( 'super-socializer', self::HEADERS ) );
		$this->assertSame( 'Envio', Words::plugin_name( 'envio', [ 'envio' => 'Envio for WooCommerce' ] ) ); // suffix trimmed
		$this->assertSame( 'Odd thing', Words::plugin_name( 'odd-thing', self::HEADERS ) ); // no header: the folder, readable
	}

	public function test_thing_names(): void {
		$owners = [ 'style:x-css' => 'super-socializer', 'script:y-js' => 'super-socializer' ];
		$this->assertSame( 'Relevanssi (better search)', Words::thing_name( 'unload:relevanssi', $owners, self::HEADERS ) );
		$this->assertSame( 'Relevanssi (better search)', Words::thing_name( 'plugin:relevanssi', $owners, self::HEADERS ) );
		$this->assertSame( 'Super Socializer styling', Words::thing_name( 'style:x-css', $owners, self::HEADERS ) );
		$this->assertSame( 'Super Socializer code', Words::thing_name( 'script:y-js', $owners, self::HEADERS ) );
		$this->assertSame( 'Wishlist icons', Words::thing_name( 'style:woosw-icons', [], [] ) ); // known handle
		$this->assertSame( 'Styling file "zz"', Words::thing_name( 'style:zz', [], [] ) );
		$this->assertSame( 'Code file "zz"', Words::thing_name( 'script:zz', [], [] ) );
	}

	public function test_scope_names_and_where(): void {
		$titles = [ '/sobre-nos/' => 'Sobre nós' ];
		$tpl    = [ 12 => 'Single product (wc_product)' ];
		$this->assertSame( 'Shop', Words::scope_name( 'shop', $tpl, $titles ) );
		$this->assertSame( 'Sobre nós (/sobre-nos/)', Words::scope_name( 'url:/sobre-nos/', $tpl, $titles ) );
		$this->assertSame( 'the page /x/', Words::scope_name( 'url:/x/', $tpl, $titles ) );
		$this->assertSame( 'pages using the Bricks template "Single product (wc_product)"', Words::scope_name( 'template:12', $tpl, $titles ) );
		$this->assertSame( 'pages using Bricks template #13', Words::scope_name( 'template:13', $tpl, $titles ) );

		$all = [ 'front', 'shop', 'product', 'archive_cat', 'archive_brand', 'archive_tag', 'search', 'cart', 'checkout', 'order_received', 'account', 'blog', 'post', 'page' ];
		$this->assertSame( 'Off on: every page of the shop', Words::where_text( $all, $tpl, $titles ) );
		$this->assertSame( 'Off on: every page except Cart and Order confirmation', Words::where_text( array_diff( $all, [ 'cart', 'order_received' ] ), $tpl, $titles ) );
		$this->assertSame( 'Off on one page only: Sobre nós (/sobre-nos/)', Words::where_text( [ 'url:/sobre-nos/' ], $tpl, $titles ) );
		$this->assertSame( 'Off on: Shop, Blog, Blog posts', Words::where_text( [ 'post', 'blog', 'shop' ], $tpl, $titles ) );
		$this->assertSame( 'Off on: Shop, Sobre nós (/sobre-nos/)', Words::where_text( [ 'url:/sobre-nos/', 'shop' ], $tpl, $titles ) );
	}

	public function test_provenance(): void {
		$auto   = new Rule( 'unload:a', [ 'shop', 'blog' ], [ 'retail' ], 'x', 'chris', '2026-10-03T08:04:23+00:00' );
		$hand   = new Rule( 'style:b', [ 'url:/x/' ], [ 'retail' ], 'Switched off by hand.', 'chris', '2026-10-04T10:00:00+00:00', [ 'url:/x/' ] );
		$mixed  = new Rule( 'style:c', [ 'shop', 'url:/x/' ], [ 'retail' ], 'x', 'chris', '2026-10-03T08:04:23+00:00', [ 'url:/x/' ] );
		$import = new Rule( 'style:d', [ 'shop' ], [ 'retail' ], 'Imported from Perfmatters Script Manager.', 'chris', '2026-10-03T08:04:23+00:00', [ 'shop' ] );
		$names  = [ 'chris' => 'Chris' ];
		$this->assertSame( [ 'text' => 'Tested automatically · approved by Chris on 3 Oct', 'tone' => 'ok' ], Words::provenance( [ $auto ], $names, [], [] ) );
		$this->assertSame( [ 'text' => 'Switched off by hand by Chris on 4 Oct — not tested', 'tone' => 'warn' ], Words::provenance( [ $hand ], $names, [], [] ) );
		$this->assertSame( 'Tested automatically · approved by Chris on 3 Oct · switched off by hand, not tested, on: the page /x/', Words::provenance( [ $mixed ], $names, [], [] )['text'] );
		$this->assertSame( 'Imported from Perfmatters on 3 Oct', Words::provenance( [ $import ], $names, [], [] )['text'] );
		$this->assertSame( 'Tested automatically · approved by bob', Words::provenance( [ new Rule( 'unload:a', [ 'shop' ], [ 'retail' ], '', 'bob', '' ) ], [], [], [] )['text'] ); // unknown user, no date
	}

	public function test_fail_reasons_in_plain_words(): void {
		$this->assertSame( 'The page looked different without it.', Words::fail_reason( 'The page layout changed: 390 img…' ) );
		$this->assertSame( 'The page looked different without it.', Words::fail_reason( 'O aspeto da página mudou: 390 img' ) ); // old Portuguese evidence
		$this->assertSame( 'The page looked different without it.', Words::fail_reason( 'The page content changed (beyond the removed files).' ) );
		$this->assertSame( 'Checkout didn\'t work the same without it.', Words::fail_reason( 'Cart/checkout stopped working in the trial.' ) );
		$this->assertSame( 'Switching it off also took away things other plugins need.', Words::fail_reason( 'Also removes files from other plugins: style:x' ) );
		$this->assertSame( 'The page didn\'t load normally without it.', Words::fail_reason( 'HTTP status changed: 200 → 500' ) );
		$this->assertSame( 'The page showed errors without it.', Words::fail_reason( 'New PHP errors.' ) );
		$this->assertSame( 'Carts or logins behaved differently without it.', Words::fail_reason( 'The response sets other cookies: a → b' ) );
		$this->assertSame( 'The page sent visitors somewhere else without it.', Words::fail_reason( 'The redirect changed: - → /x' ) );
		$this->assertSame( 'The test couldn\'t finish. Try testing again.', Words::fail_reason( 'Page too unstable to judge: repeat the trial.' ) );
		$this->assertSame( 'The test couldn\'t finish. Try testing again.', Words::fail_reason( 'The trial request failed.' ) );
		$this->assertSame( 'There was no page of this kind to test on.', Words::fail_reason( 'No test page for this type.' ) );
		$this->assertSame( 'The test found a difference.', Words::fail_reason( 'something new' ) );
	}

	public function test_test_time(): void {
		$this->assertSame( 'about 1 minute', Words::test_time( 4 ) );
		$this->assertSame( 'about 2 minutes', Words::test_time( 58 ) ); // 58 fetches × 1.2 s (measured on staging: 42 in 48 s)
	}

	public function test_needed_phrases(): void {
		$this->assertSame( 'the heart on each product', Words::needed_phrase( 'woo-smart-wishlist', 'shop' ) );
		$this->assertSame( 'used on this page', Words::needed_phrase( 'woo-smart-wishlist', 'checkout' ) ); // no product grid there
		$this->assertSame( 'used on this page', Words::needed_phrase( 'woo-smart-wishlist' ) ); // page unknown (a template)
		$this->assertSame( 'the free-shipping notice', Words::needed_phrase( 'flexible-shipping', 'cart' ) );
		$this->assertSame( 'used on this page', Words::needed_phrase( 'flexible-shipping', 'blog' ) );
		$this->assertSame( 'the PayPal buttons', Words::needed_phrase( 'pymntpl-paypal-woocommerce', 'product' ) );
		$this->assertSame( 'used on this page', Words::needed_phrase( 'multibanco-ifthen-software-gateway-for-woocommerce', 'account' ) );
		$this->assertSame( 'the cookie banner', Words::needed_phrase( 'pressidium-cookie-consent', 'blog' ) ); // every page
		$this->assertSame( 'statistics — never suggested (For experts: switch off yourself)', Words::needed_phrase( 'duracelltomi-google-tag-manager', 'checkout' ) );
		$this->assertSame( 'speeds up the site — never suggested (For experts: switch off yourself)', Words::needed_phrase( 'wp-fastest-cache' ) ); // never-accept kind
		$this->assertSame( 'e-mails — never suggested (For experts: switch off yourself)', Words::needed_phrase( 'some-smtp' ) );
		$this->assertSame( 'used on this page', Words::needed_phrase( 'other' ) );
	}

	public function test_page_hint(): void {
		$this->assertSame( 'mindthetrash.pt', Words::page_hint( 'front', '/?bp_check=browser', 'mindthetrash.pt' ) );
		$this->assertSame( '/loja/', Words::page_hint( 'shop', '/loja/?bp_check=browser', 'x' ) );
		$this->assertSame( 'every product', Words::page_hint( 'product', '/loja/p/', 'x' ) );
		$this->assertSame( 'every blog post', Words::page_hint( 'post', '/p/', 'x' ) );
		$this->assertSame( 'e.g. /categoria/cosmeticos/', Words::page_hint( 'archive_cat', '/categoria/cosmeticos/?bp_check=browser', 'x' ) );
		$this->assertSame( 'e.g. /?s=escova', Words::page_hint( 'search', '/?s=escova&bp_check=browser', 'x' ) );
		$this->assertSame( 'e.g. /sobre-nos/', Words::page_hint( 'page', '/sobre-nos/', 'x' ) );
	}

	public function test_settings_rows_and_bricks_line(): void {
		$defs = [ 'emojis' => [ 'Emojis', true, 'disableEmojis' ], 'dashicons' => [ 'Dashicons for visitors', true, null ], 'xmlrpc' => [ 'XML-RPC', true, null ], 'new_one' => [ 'New one', true, null ] ];
		$rows = Words::settings_rows( $defs );
		$this->assertSame( [ 'dashicons', 'xmlrpc', 'new_one' ], array_keys( $rows ) ); // Bricks-handled ones left out
		$this->assertSame( [ 'Leave out the WordPress icon set for visitors', 'logged-in staff still get it' ], $rows['dashicons'] );
		$this->assertSame( [ 'Block XML-RPC', 'an old remote-access door bots attack' ], $rows['xmlrpc'] );
		$this->assertSame( [ 'New one', '' ], $rows['new_one'] );
		$this->assertSame( [ 'text' => 'Emojis, embeds and lazy-loading images are handled by Bricks.', 'ok' => true ], Words::bricks_line( [ 'Emojis' => true, 'Embeds' => true, 'jQuery Migrate' => true, 'Lazy loading' => true ] ) );
		$this->assertSame( [ 'text' => 'Bricks is not handling: Embeds, Lazy loading. Switch them on in the Bricks settings.', 'ok' => false ], Words::bricks_line( [ 'Emojis' => true, 'Embeds' => false, 'jQuery Migrate' => true, 'Lazy loading' => false ] ) );
	}
}
