<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Admin\Sorting;
use BeltoftPerformance\Rules\Rule;

/** Sorting every item of a page into Safe / Your decision / Needed / Always on / Already off; overview counts; status card; switched-off cards. */
final class SortingTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	private static function rep( array $plugins, array $sigs = [], array $assets = [], int $status = 200 ): array {
		return [ 'status' => $status, 'plugins' => $plugins, 'signatures' => $sigs, 'assets' => $assets, 'url' => '/loja/?bp_check=browser' ];
	}
	private static function trial( bool $ok, array $pages, array $evidence = [], ?bool $browser = null ): array {
		$t = [ 'ok' => $ok, 'check' => 'c1', 'pages' => $pages, 'evidence' => $evidence ?: array_map( fn( $p ) => [ 'page' => $p, 'state' => 'guest' ], $pages ) ];
		if ( null !== $browser ) {
			$t['browser'] = [ 'ok' => $browser ];
		}
		return $t;
	}
	private static function base( array $over = [] ): array {
		$assets = [
			[ 'kind' => 'style', 'handle' => 'woosw-icons', 'plugin' => 'woo-smart-wishlist' ],
			[ 'kind' => 'script', 'handle' => 'soc-js', 'plugin' => 'super-socializer' ],
			[ 'kind' => 'style', 'handle' => 'wc-blocks', 'plugin' => 'woocommerce' ],
		];
		$inv = [
			'shop'     => [ 'guest' => self::rep( [ 'woocommerce', 'idle', 'tested', 'woo-smart-wishlist', 'super-socializer', 'duracelltomi-google-tag-manager', 'kept', 'files-off' ], [ 'woo-smart-wishlist' => [ 'woosw-' ] ], $assets ), 'cart' => self::rep( [ 'woocommerce', 'idle' ] ) ],
			'checkout' => [ 'cart' => self::rep( [ 'woocommerce', 'idle', 'flowy' ] ) ],
		];
		return array_merge( [
			'scope'       => 'shop',
			'page'        => 'shop',
			'reports'     => array_values( $inv['shop'] ),
			'inv'         => $inv,
			'candidates'  => [
				[ 'target' => 'unload:idle', 'pages' => [ 'shop', 'checkout' ] ],
				[ 'target' => 'unload:tested', 'pages' => [ 'shop' ] ],
				[ 'target' => 'style:woosw-icons', 'pages' => [ 'shop' ] ],
				[ 'target' => 'script:soc-js', 'pages' => [ 'shop' ] ],
				[ 'target' => 'unload:flowy', 'pages' => [ 'checkout' ] ],
			],
			'trials'      => [
				'unload:tested'     => self::trial( true, [ 'shop' ] ),
				'style:woosw-icons' => self::trial( false, [ 'shop' ], [ [ 'page' => 'shop', 'state' => 'guest', 'problem' => 'The page layout changed: x' ] ] ),
			],
			'check_id'    => 'c1',
			'rules'       => [ new Rule( 'plugin:files-off', [ 'shop' ] ), new Rule( 'unload:gone', [ 'shop', 'blog' ] ), new Rule( 'unload:elsewhere', [ 'blog' ] ) ],
			'headers'     => [ 'idle' => 'Idle Plugin', 'tested' => 'Tested Plugin', 'files-off' => 'Files Off', 'gone' => 'Gone', 'kept' => 'Kept', 'super-socializer' => 'Super Socializer' ],
			'owners'      => [ 'style:woosw-icons' => 'woo-smart-wishlist', 'script:soc-js' => 'super-socializer' ],
			'via'         => [],
			'kept'        => [ 'unload:kept' ],
			'templates'   => [],
			'page_titles' => [],
		], $over );
	}

	public function test_page_sections_sort_every_item(): void {
		$s = Sorting::page_sections( self::base() );
		$this->assertSame( [ 'unload:tested' ], array_column( $s['safe'], 'target' ) );
		$this->assertSame( 'Tested Plugin', $s['safe'][0]['name'] );
		$this->assertContains( 'shop', $s['safe'][0]['pages'] ); // the pages are offered as a choice now, not listed in the text
		$this->assertTrue( $s['safe'][0]['all'] );

		$decide = array_column( $s['decide'], null, 'target' );
		$this->assertSame( [ 'unload:idle', 'script:soc-js', 'style:woosw-icons' ], array_keys( $decide ) ); // by name A–Z
		$this->assertSame( 'test', $decide['unload:idle']['action'] );
		$this->assertStringContainsString( 'Not tested yet', $decide['unload:idle']['detail'] );
		$this->assertStringContainsString( 'about 1 minute', $decide['unload:idle']['detail'] );
		$this->assertSame( 'retest', $decide['style:woosw-icons']['action'] );
		$this->assertStringContainsString( 'The page looked different without it.', $decide['style:woosw-icons']['detail'] );
		$this->assertSame( 'script', $decide['script:soc-js']['action'] );
		$this->assertSame( 'Super Socializer code', $decide['script:soc-js']['name'] );
		$this->assertFalse( $decide['unload:idle']['flow'] ); // the shop is not a checkout page

		$needed = array_column( $s['needed'], 'phrase', 'plugin' );
		$this->assertSame( 'the heart on each product', $needed['woo-smart-wishlist'] );
		$this->assertSame( 'statistics — never suggested (For experts: switch off yourself)', $needed['duracelltomi-google-tag-manager'] );
		$this->assertSame( 'you chose to keep it', $needed['kept'] );
		$this->assertSame( 'used on this page', $needed['super-socializer'] ); // its code is a suggestion, the plugin itself stays
		$this->assertArrayNotHasKey( 'idle', $needed );
		$this->assertArrayNotHasKey( 'woocommerce', $needed );
		$this->assertArrayNotHasKey( 'files-off', $needed ); // its files are off here
		$this->assertTrue( $s['always'] );
		$this->assertSame( [ 'Files Off', 'Gone' ], $s['off'] );
		$this->assertSame( 7, $s['loading'] ); // every loaded plugin but WooCommerce
	}

	public function test_scripts_with_a_passed_test_are_still_your_decision(): void {
		$s = Sorting::page_sections( self::base( [ 'trials' => [ 'script:soc-js' => self::trial( true, [ 'shop' ], [], true ) ] ] ) );
		$this->assertNotContains( 'script:soc-js', array_column( $s['safe'], 'target' ) );
		$this->assertSame( 'script', array_column( $s['decide'], 'action', 'target' )['script:soc-js'] );
	}

	public function test_a_test_of_this_page_only_is_safe_here_only(): void {
		$s = Sorting::page_sections( self::base( [ 'trials' => [ 'unload:idle' => self::trial( true, [ 'shop' ] ) ] ] ) ); // candidate is shop+checkout
		$safe = array_column( $s['safe'], null, 'target' )['unload:idle'];
		$this->assertFalse( $safe['all'] );
		$this->assertStringContainsString( 'Tested on this page only', $safe['detail'] );
	}

	public function test_a_test_of_other_pages_is_not_safe_here(): void {
		$s = Sorting::page_sections( self::base( [ 'trials' => [ 'unload:idle' => self::trial( true, [ 'checkout' ] ) ] ] ) ); // this page (shop) not tested
		$this->assertNotContains( 'unload:idle', array_column( $s['safe'], 'target' ) );
		$this->assertContains( 'unload:idle', array_column( $s['decide'], 'target' ) );
	}

	public function test_files_of_a_plugin_and_of_one_file(): void {
		$reports = [ [ 'status' => 200, 'assets' => [
			[ 'kind' => 'style', 'handle' => 'p-css', 'plugin' => 'p', 'src' => 'https://x/wp-content/plugins/p/a.css?ver=1', 'size' => 100 ],
			[ 'kind' => 'script', 'handle' => 'p-js', 'plugin' => 'p', 'src' => 'https://x/wp-content/plugins/p/b.js', 'size' => 200 ],
			[ 'kind' => 'script', 'handle' => 'q-js', 'plugin' => 'q', 'src' => 'https://x/q.js', 'size' => 5 ],
		] ], [ 'status' => 500, 'assets' => [ [ 'kind' => 'script', 'handle' => 'p-late', 'plugin' => 'p', 'src' => '', 'size' => 1 ] ] ] ];
		$f = Sorting::files_of( 'unload:p', $reports );
		$this->assertSame( [ 'script:p-js', 'style:p-css' ], array_column( $f, 'target' ) );
		$this->assertSame( 'a.css', $f[1]['file'] );
		$this->assertSame( [ 'script:q-js' ], array_column( Sorting::files_of( 'script:q-js', $reports ), 'target' ) );
		$this->assertSame( [], Sorting::files_of( 'unload:none', $reports ) );
	}

	public function test_checkout_items_offer_the_by_hand_route_and_browser_wait(): void {
		$in = self::base( [ 'scope' => 'checkout', 'page' => 'checkout', 'reports' => array_values( self::base()['inv']['checkout'] ) ] );
		$in['trials']['unload:flowy'] = self::trial( false, [ 'checkout' ], [ [ 'page' => 'checkout', 'state' => 'cart', 'needs_browser' => true ] ] );
		$s = Sorting::page_sections( $in );
		$this->assertSame( [], $s['safe'] );
		$d = array_column( $s['decide'], null, 'target' );
		$this->assertTrue( $d['unload:flowy']['flow'] );
		$this->assertSame( 'browser', $d['unload:flowy']['action'] );
		$this->assertSame( 'wp beltoft-performance browser unload:flowy', $d['unload:flowy']['command'] );
		$this->assertStringContainsString( 'one more check by your developer', $d['unload:flowy']['detail'] );
		$this->assertTrue( $d['unload:idle']['flow'] );
	}

	/** I2: a script on a checkout-flow page is offered only with "I clicked through checkout myself"; on the shop it keeps its plain Switch off. */
	public function test_scripts_on_checkout_flow_pages_need_the_by_hand_form(): void {
		foreach ( [ 'cart', 'checkout', 'order_received', 'account' ] as $p ) {
			$in = self::base( [ 'scope' => $p, 'page' => $p, 'reports' => array_values( self::base()['inv']['shop'] ), 'candidates' => [ [ 'target' => 'script:soc-js', 'pages' => [ $p ] ] ] ] );
			$d  = array_column( Sorting::page_sections( $in )['decide'], null, 'target' )['script:soc-js'];
			$this->assertSame( 'script', $d['action'], $p );
			$this->assertTrue( $d['flow'], $p );
		}
		$d = array_column( Sorting::page_sections( self::base() )['decide'], null, 'target' )['script:soc-js'];
		$this->assertFalse( $d['flow'] );
	}

	public function test_a_single_page_view_has_no_suggestions_and_shows_inherited_offs(): void {
		$s = Sorting::page_sections( self::base( [ 'scope' => 'url:/x/', 'page' => 'page', 'via' => [ 'page' ], 'rules' => [ new Rule( 'style:a', [ 'url:/x/' ] ), new Rule( 'unload:b', [ 'page' ] ) ], 'headers' => [ 'b' => 'Bee' ] ] ) );
		$this->assertSame( [], $s['safe'] );
		$this->assertSame( [], $s['decide'] );
		$this->assertSame( [ 'Bee', 'Styling file "a"' ], $s['off'] );
		$needed = array_column( $s['needed'], 'phrase', 'plugin' );
		$this->assertSame( 'loads here', $needed['idle'] ); // one page is not judged: no "used" claim
		$this->assertSame( 'used on this page', $needed['woo-smart-wishlist'] ); // its marker is on the page, but a plain page has no product grid
	}

	public function test_queued_items_wait_and_browser_waits_are_almost_done(): void {
		$in = self::base( [ 'queue' => [ 'unload:idle' => 'running', 'style:woosw-icons' => 'waiting' ] ] );
		$in['trials']['unload:tested'] = self::trial( false, [ 'shop' ], [ [ 'page' => 'shop', 'state' => 'guest', 'needs_browser' => true ] ] );
		$d = array_column( Sorting::page_sections( $in )['decide'], null, 'target' );
		$this->assertSame( [ 'queued', 'Testing now…' ], [ $d['unload:idle']['action'], $d['unload:idle']['detail'] ] );
		$this->assertSame( [ 'queued', 'Waiting to be tested.' ], [ $d['style:woosw-icons']['action'], $d['style:woosw-icons']['detail'] ] );
		$this->assertSame( 'Almost done — needs one more check by your developer.', $d['unload:tested']['detail'] );
		$this->assertSame( 'browser', $d['unload:tested']['action'] );
	}

	public function test_owners(): void {
		$inv = [ 'shop' => [ 'guest' => self::rep( [], [], [ [ 'kind' => 'style', 'handle' => 'a', 'plugin' => 'p' ], [ 'kind' => 'script', 'handle' => 'b', 'plugin' => '' ] ] ) ] ];
		$this->assertSame( [ 'style:a' => 'p' ], Sorting::owners( $inv ) );
	}

	public function test_overview_rows(): void {
		$b = self::base();
		$o = Sorting::overview( [ 'inv' => $b['inv'], 'candidates' => $b['candidates'], 'trials' => $b['trials'], 'check_id' => 'c1', 'rules' => $b['rules'], 'headers' => $b['headers'], 'owners' => $b['owners'], 'kept_pages' => [ 'unload:kept' => [ 'shop' ] ], 'host' => 'mindthetrash.pt' ] );
		$this->assertSame( [ 'shop', 'checkout' ], array_keys( $o['rows'] ) ); // shop order
		$shop = $o['rows']['shop'];
		$this->assertSame( [ 'Shop', '/loja/', 7, 2, 1, 3, false ], [ $shop['name'], $shop['hint'], $shop['loading'], $shop['off'], $shop['safe'], $shop['decide'], $shop['care'] ] );
		$this->assertTrue( $o['rows']['checkout']['care'] ); // checkout, nothing safe
		$this->assertSame( 1, $o['safe_total'] );
		$this->assertSame( 'shop', $o['first_safe'] );
	}

	public function test_status_card(): void {
		$ok = [ 'loader' => 'installed', 'rules' => 'ok', 'has_check' => true, 'pages' => 35, 'failed' => 0, 'off' => 7, 'date' => '3 October' ];
		$this->assertSame( [ 'tone' => 'ok', 'title' => 'Everything is working. 7 things are already switched off.', 'detail' => 'Last checked 3 October — 35 pages looked at, no problems found.', 'action' => 'check_again' ], Sorting::status( $ok ) );
		$this->assertSame( 'Everything is working. Nothing is switched off yet.', Sorting::status( [ 'off' => 0 ] + $ok )['title'] );
		$this->assertSame( 'Everything is working. 1 thing is already switched off.', Sorting::status( [ 'off' => 1 ] + $ok )['title'] );
		$this->assertSame( [ 'warn', 'check_again' ], array_values( array_intersect_key( Sorting::status( [ 'failed' => 2 ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertStringContainsString( '2 of 35 pages', Sorting::status( [ 'failed' => 2 ] + $ok )['title'] );
		$this->assertSame( [ 'warn', 'check' ], array_values( array_intersect_key( Sorting::status( [ 'has_check' => false ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertSame( [ 'bad', 'repair' ], array_values( array_intersect_key( Sorting::status( [ 'loader' => 'missing' ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertSame( [ 'warn', 'repair' ], array_values( array_intersect_key( Sorting::status( [ 'loader' => 'outdated' ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertSame( [ 'bad', '' ], array_values( array_intersect_key( Sorting::status( [ 'loader' => 'not_writable' ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertSame( [ 'bad', 'repair' ], array_values( array_intersect_key( Sorting::status( [ 'rules' => 'missing' ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertSame( [ 'bad', '' ], array_values( array_intersect_key( Sorting::status( [ 'rules' => 'unreadable' ] + $ok ), array_flip( [ 'tone', 'action' ] ) ) ) );
		$this->assertSame( 'Nothing is being switched off right now.', Sorting::status( [ 'rules' => 'wrong_owner', 'loader' => 'missing' ] + $ok )['title'] ); // the file problem first
	}

	public function test_off_cards_group_a_plugin_and_its_files_rule(): void {
		$rules = [
			new Rule( 'unload:mcp', [ 'shop', 'blog' ], [ 'retail' ], '', 'chris', '2026-10-03T08:00:00+00:00' ),
			new Rule( 'plugin:mcp', [ 'page', 'post' ], [ 'retail' ], '', 'chris', '2026-10-03T08:00:00+00:00' ),
			new Rule( 'style:x', [ 'url:/sobre-nos/' ], [ 'retail' ], '', 'chris', '2026-10-04T08:00:00+00:00', [ 'url:/sobre-nos/' ] ),
			new Rule( 'unload:wp-fastest-cache', [ 'shop' ], [ 'retail', 'trade' ] ),
		];
		$cards = Sorting::off_cards( $rules, [ 'headers' => [ 'mcp' => 'MCP Adapter' ], 'owners' => [], 'templates' => [], 'page_titles' => [ '/sobre-nos/' => 'Sobre nós' ], 'names' => [ 'chris' => 'Chris' ], 'installed' => [ 'mcp', 'wp-fastest-cache' ] ] );
		$this->assertCount( 3, $cards );
		$this->assertSame( 'MCP Adapter', $cards[0]['name'] );
		$this->assertSame( 'Off on: Shop, Blog, Blog posts, Other pages', $cards[0]['where'] );
		$this->assertSame( [ 'unload:mcp|blog,shop|retail', 'plugin:mcp|page,post|retail' ], $cards[0]['keys'] );
		$this->assertSame( [ 'Shop', 'Blog', 'Blog posts', 'Other pages' ], array_column( $cards[0]['choices'], 'label' ) );
		$this->assertSame( [ 'unload:mcp', 'shop' ], Sorting::parse_choice( $cards[0]['choices'][0]['value'] ) );
		$this->assertSame( 'Off on one page only: Sobre nós (/sobre-nos/)', $cards[1]['where'] );
		$this->assertSame( 'warn', $cards[1]['provenance']['tone'] );
		$this->assertSame( [ 'url:/sobre-nos/' ], array_map( fn( $c ) => Sorting::parse_choice( $c['value'] )[1], $cards[1]['choices'] ) );
		$this->assertSame( [], $cards[2]['choices'] ); // also on the trade site: only "everywhere"
		$this->assertNotEmpty( $cards[2]['warnings'] ); // on the never-switch-off list
		$this->assertSame( 3, Sorting::off_count( $rules ) );
		$this->assertNull( Sorting::parse_choice( 'nope' ) );
		$this->assertNull( Sorting::parse_choice( 'bad target|shop' ) );
	}
}
