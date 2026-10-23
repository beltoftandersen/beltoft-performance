<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\FileCandidates;
use BeltoftPerformance\Check\Recorder;
use BeltoftPerformance\Check\Runner;
use BeltoftPerformance\Check\Trial;
use BeltoftPerformance\Rules\Rule;
use BeltoftPerformance\Rules\Store;

/** I2: plugins whose effects do not show on the page (page cache, security/SSO, email/marketing tracking, analytics). */
final class NeverAcceptTest extends TestCase {
	protected function setUp(): void { $GLOBALS['bp_test_options'] = []; }

	public function test_the_list_and_the_pattern(): void {
		foreach ( [ 'wp-fastest-cache', 'wp-optimize', 'beltoft-cache-support', 'woocommerce-follow-up-emails', 'beltoft-followups', 'daggerhart-openid-connect-generic', 'perfmatters', 'duracelltomi-google-tag-manager', 'api-mailer-for-aws-ses', 'wordfence-security', 'some-firewall', 'miniorange-sso', 'wp-mail-smtp', 'google-analytics-x', 'facebook-pixel-x' ] as $p ) {
			$this->assertNotNull( Store::never_accept_reason( $p ), $p );
			$this->assertStringContainsString( $p, (string) Store::never_accept_reason( $p ) );
		}
		foreach ( [ 'super-socializer', 'woo-smart-wishlist', 'mcp-adapter', 'woo-dpd-portugal', 'relevanssi' ] as $p ) {
			$this->assertNull( Store::never_accept_reason( $p ), $p );
		}
	}
	public function test_store_refuses_proven_rules_but_allows_them_by_hand(): void {
		$res = Store::add( new Rule( 'unload:wp-fastest-cache', [ 'front' ] ) );
		$this->assertIsString( $res );
		$this->assertStringContainsString( 'page cache', $res );
		$this->assertIsString( Store::add( new Rule( 'plugin:duracelltomi-google-tag-manager', [ 'page' ] ) ) );
		$this->assertSame( [], Store::all() );
		// By hand the owner decides (like Perfmatters), and the rule records the override.
		$this->assertTrue( Store::switch_off( 'unload:api-mailer-for-aws-ses', [ 'front' ], 'chris', [] ) );
		$this->assertTrue( Store::switch_off( 'plugin:w3-total-cache', [ 'page' ], 'chris', [] ) );
		$this->assertTrue( Store::find( 'unload:api-mailer-for-aws-ses' )->owner_override );
		$this->assertTrue( Store::find( 'plugin:w3-total-cache' )->owner_override );
		$this->assertTrue( Store::add( new Rule( 'unload:super-socializer', [ 'front' ] ) ) );
	}
	public function test_owner_switches_off_by_hand_and_the_rule_remembers(): void {
		// No override flag needed any more: switched off, recorded on the rule.
		$this->assertTrue( Store::switch_off( 'plugin:woocommerce-follow-up-emails', [ 'front' ], 'chris', [] ) );
		$this->assertTrue( Store::switch_off( 'plugin:woocommerce-follow-up-emails', [ 'shop' ], 'chris', [], '', true ) );
		$r = Store::find( 'plugin:woocommerce-follow-up-emails' );
		$this->assertNotNull( $r );
		$this->assertTrue( $r->owner_override );
		$this->assertStringContainsString( 'overridden', $r->reason );
		// Adding a page keeps the flag; switching one page back on keeps it too.
		$this->assertTrue( Store::switch_off( 'plugin:woocommerce-follow-up-emails', [ 'product' ], 'chris', [] ) );
		$this->assertTrue( Store::switch_on( 'plugin:woocommerce-follow-up-emails', [ 'front' ] ) );
		$r = Store::find( 'plugin:woocommerce-follow-up-emails' );
		$this->assertTrue( $r->owner_override );
		$this->assertSame( [ 'product', 'shop' ], ( static function ( $p ) { sort( $p ); return $p; } )( $r->pages ) );
		// Survives the round trip through the option.
		$this->assertTrue( Rule::from_array( $r->to_array() )->owner_override );
		// Proposals and accept still refuse it.
		$this->assertIsString( Store::add( new Rule( 'unload:woocommerce-follow-up-emails', [ 'blog' ] ) ) );
	}

	public function test_override_flag_is_ignored_for_ordinary_plugins(): void {
		$this->assertTrue( Store::switch_off( 'unload:woo-dpd-portugal', [ 'front' ], 'chris', [], '', true ) );
		$this->assertFalse( Store::find( 'unload:woo-dpd-portugal' )->owner_override );
	}

/** Ruling: the object cache runs from the object-cache.php drop-in, not from the redis-cache plugin. */
	public function test_redis_cache_is_an_explicit_exception(): void {
		$this->assertNull( Store::never_accept_reason( 'redis-cache' ) );
		$this->assertNull( Store::never_accept_error( 'unload:redis-cache' ) );
		$this->assertNull( Store::never_accept_error( 'plugin:redis-cache' ) );
		$this->assertNotNull( Store::never_accept_reason( 'redis-cache-pro' ) ); // only the exact directory
		$this->assertTrue( Store::switch_off( 'unload:redis-cache', [ 'front' ], 'chris', [] ) );
		$rep = [ 'status' => 200, 'plugins' => [ 'redis-cache' ], 'signatures' => [], 'assets' => [] ];
		$this->assertSame( [ 'unload:redis-cache' ], array_column( Runner::candidates_from( [ 'shop' => [ 'guest' => $rep ] ] ), 'target' ) );
	}
	public function test_an_existing_rule_can_still_be_switched_on(): void {
		$r = new Rule( 'unload:redis-cache', [ 'front', 'shop' ], [ 'retail' ], 'old', 'chris', '2026-10-01' );
		$GLOBALS['bp_test_options'][ Store::OPTION ] = [ $r->key() => $r->to_array() ];
		$this->assertTrue( Store::switch_on( 'unload:redis-cache', [ 'shop' ] ) );
		$this->assertSame( [ 'front' ], Store::all()[0]->pages );
	}
	public function test_never_proposed(): void {
		$rep = static fn( array $plugins, array $assets = [] ) => [ 'status' => 200, 'plugins' => $plugins, 'signatures' => [], 'assets' => $assets ];
		$inv = [ 'shop' => [ 'guest' => $rep( [ 'wp-fastest-cache', 'duracelltomi-google-tag-manager', 'idle' ] ) ], 'page' => [ 'guest' => $rep( [ 'perfmatters', 'idle' ] ) ] ];
		$t   = array_map( fn( $c ) => $c['target'], Runner::candidates_from( $inv ) );
		sort( $t );
		$this->assertSame( [ 'plugin:idle', 'unload:idle' ], $t );
		$a   = [ 'kind' => 'script', 'handle' => 'gtm4wp-x', 'src' => 'https://s/wp-content/plugins/duracelltomi-google-tag-manager/a.js', 'plugin' => 'duracelltomi-google-tag-manager', 'deps' => [] ];
		$u   = [ 'style' => [], 'script' => [ '/wp-content/plugins/duracelltomi-google-tag-manager/a.js' => [ 'called' => 0, 'listeners' => 0 ] ] ];
		$this->assertSame( [], FileCandidates::from( [ 'front' => [ 'guest' => [ 'status' => 200, 'assets' => [ $a ] ] ] ], [ 'front' => [ 'guest' => $u ] ], [] ) );
	}
	public function test_content_hash_keeps_structured_data_noscript_and_canonical_links(): void {
		$page = static fn( string $head, string $body = '' ) => '<html><head>' . $head . '</head><body><p>Hi</p>' . $body . '</body></html>';
		$base = $page( '' );
		// Still ignored: plain scripts, styles, stylesheet/preload links.
		$this->assertSame( Recorder::content_hash( $base ), Recorder::content_hash( $page( '<link rel="stylesheet" href="/x.css"><link rel=preload href="/f.woff2"><style>a{}</style><script>var a=1;</script><script type="module" src="/m.js"></script>' ) ) );
		// Kept: JSON-LD, noscript, canonical/alternate/prev/next.
		$this->assertNotSame( Recorder::content_hash( $base ), Recorder::content_hash( $page( '<script type="application/ld+json">{"@type":"Product"}</script>' ) ) );
		$this->assertNotSame( Recorder::content_hash( $base ), Recorder::content_hash( $page( '', '<noscript><img src="/px.gif"></noscript>' ) ) );
		$this->assertNotSame( Recorder::content_hash( $base ), Recorder::content_hash( $page( '', '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-X"></iframe></noscript>' ) ) );
		foreach ( [ 'canonical', 'alternate', 'prev', 'next' ] as $rel ) {
			$this->assertNotSame( Recorder::content_hash( $base ), Recorder::content_hash( $page( '<link rel="' . $rel . '" href="https://x/a/">' ) ), $rel );
		}
		$this->assertNotSame( Recorder::content_hash( $page( '<script type="application/ld+json">{"a":1}</script>' ) ), Recorder::content_hash( $page( '<script type="application/ld+json">{"a":2}</script>' ) ) );
	}
	public function test_response_facts_from_headers(): void {
		$f = Recorder::response_facts( [ 'Content-Type: text/html', 'Set-Cookie: wp_woocommerce_session_abc=1%7C2; path=/', 'set-cookie: pll_language=pt; path=/', 'Set-Cookie: wp_woocommerce_session_abc=2', 'Location: https://staging.mindthetrash.pt/loja/' ] );
		$this->assertSame( [ 'pll_language', 'wp_woocommerce_session_abc' ], $f['cookies'] );
		$this->assertSame( 'https://staging.mindthetrash.pt/loja/', $f['location'] );
		$this->assertSame( [ 'cookies' => [], 'location' => '' ], Recorder::response_facts( [ 'Content-Type: text/html' ] ) );
	}
	public function test_trial_flags_changed_cookies_or_location(): void {
		$base = [ 'status' => 200, 'errors' => [], 'html_hash' => 'h', 'plugins' => [ 'a', 'b' ], 'assets' => [], 'cookies' => [ 'sess' ], 'location' => '' ];
		$t    = $base; $t['plugins'] = [ 'a' ];
		$this->assertSame( [], Trial::problem( $base, $t, 'shop', 'unload:b' ) );
		$c = $t; $c['cookies'] = [];
		$this->assertStringContainsString( 'cookies', Trial::problem( $base, $c, 'shop', 'unload:b' )['problem'] ?? '' );
		$c = $t; $c['cookies'] = [ 'sess', 'tracker' ];
		$this->assertNotEmpty( Trial::problem( $base, $c, 'shop', 'unload:b' )['problem'] ?? '' );
		$l = $t; $l['location'] = 'https://x/';
		$this->assertStringContainsString( 'redirect', Trial::problem( $base, $l, 'shop', 'unload:b' )['problem'] ?? '' );
		// A file trial is judged the same way.
		$fb = $base; $fb['assets'] = [ [ 'kind' => 'style', 'handle' => 'x', 'plugin' => 'b', 'deps' => [] ] ];
		$ft = $base; $ft['cookies'] = [];
		$this->assertNotEmpty( Trial::problem( $fb, $ft, 'shop', 'style:x' )['problem'] ?? '' );
		// Reports from before this change have no cookie/location facts: not a problem.
		$old = $base; unset( $old['cookies'], $old['location'] );
		$this->assertSame( [], Trial::problem( $old, $t, 'shop', 'unload:b' ) );
	}
}
