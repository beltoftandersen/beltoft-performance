<?php
use PHPUnit\Framework\TestCase;
use BeltoftPerformance\Check\ContentSignatures;
use BeltoftPerformance\Check\Recorder;
use BeltoftPerformance\Check\Runner;

/** I3: plugin-level candidates respect site content (derived needles) and every product type sample. */
final class PluginCandidatesContentTest extends TestCase {
	private const P = '/var/www/site/wp-content/plugins/';

	public function test_needles_derived_from_shortcodes_elements_and_blocks(): void {
		$d = ContentSignatures::derive_needles(
			[ 'zerolang_switcher' => self::P . 'zerolang/inc/sc.php', 'relevanssi_didyoumean' => self::P . 'relevanssi/lib/x.php', 'product' => self::P . 'woocommerce/includes/class-wc-shortcodes.php', 'bisn_form' => self::P . 'beltoft-in-stock-notifier/src/x.php', 'gallery' => '/var/www/site/wp-includes/media.php' ],
			[ 'b2b-scan-button' => self::P . 'b2b-scanner/src/Ui/element-scan-button.php', 'heading' => '/var/www/site/wp-content/themes/bricks/includes/elements/heading.php' ],
			[ 'relevanssi/did-you-mean' => [ 'https://s.example/wp-content/plugins/relevanssi/build/did-you-mean.js' ], 'pressidium/cookies' => [ self::P . 'pressidium-cookie-consent/public/block.js', '' ], 'core/paragraph' => [ '/wp-includes/blocks/p.js' ], 'woocommerce/cart' => [ self::P . 'woocommerce/assets/cart.js' ] ]
		);
		$this->assertSame( [ '[zerolang_switcher ', '[zerolang_switcher]' ], $d['zerolang'] );
		$this->assertSame( [ '[relevanssi_didyoumean ', '[relevanssi_didyoumean]', '<!-- wp:relevanssi/' ], $d['relevanssi'] );
		$this->assertSame( [ '"b2b-scan-button"' ], $d['b2b-scanner'] );
		$this->assertSame( [ '<!-- wp:pressidium/' ], $d['pressidium-cookie-consent'] );
		$this->assertArrayNotHasKey( 'woocommerce', $d );               // protected: never a candidate anyway
		$this->assertArrayNotHasKey( 'beltoft-in-stock-notifier', $d );
		$this->assertSame( [ 'b2b-scanner', 'pressidium-cookie-consent', 'relevanssi', 'zerolang' ], array_keys( $d ) );
	}
	public function test_derived_needles_join_the_static_ones(): void {
		$d = [ 'zerolang' => [ '[zerolang_switcher ' ], 'super-socializer' => [ '[TheChamp-Sharing ' ] ];
		$this->assertContains( '[zerolang_switcher ', ContentSignatures::needles( 'zerolang', $d ) );
		$this->assertContains( '[the_champ', ContentSignatures::needles( 'super-socializer', $d ) );
		$this->assertContains( '[TheChamp-Sharing ', ContentSignatures::needles( 'super-socializer', $d ) );
		$this->assertContains( 'zerolang', ContentSignatures::plugins( $d ) );
		$map = ContentSignatures::from_docs( [ [ 'pages' => [ 'page' ], 'text' => 'x [zerolang_switcher style="a"] y' ], [ 'pages' => [ 'post' ], 'text' => '[TheChamp-Sharing ]' ] ], [], $d );
		$this->assertSame( [ 'page' ], $map['zerolang'] );
		$this->assertSame( [ 'post' ], $map['super-socializer'] );
	}
	public function test_plugin_candidates_skip_page_types_whose_content_uses_the_plugin(): void {
		$rep = static fn( array $plugins ) => [ 'status' => 200, 'plugins' => $plugins, 'signatures' => [], 'assets' => [] ];
		$inv = [ 'page' => [ 'guest' => $rep( [ 'zerolang', 'idle' ] ) ], 'post' => [ 'guest' => $rep( [ 'zerolang', 'idle' ] ) ], 'shop' => [ 'guest' => $rep( [ 'zerolang' ] ) ], 'product' => [ 'guest' => $rep( [ 'zerolang' ] ) ] ];
		$c   = [];
		foreach ( Runner::candidates_from( $inv, [ 'zerolang' => [ 'page', 'product' ] ] ) as $x ) {
			$c[ $x['target'] ] = $x['pages'];
		}
		$this->assertSame( [ 'post' ], $c['plugin:zerolang'] );
		$this->assertSame( [ 'shop' ], $c['unload:zerolang'] );
		$this->assertSame( [ 'page', 'post' ], $c['plugin:idle'] );
	}
	public function test_samples_merge_into_the_page_type(): void {
		$main   = [ 'page' => 'product', 'state' => 'guest', 'status' => 200, 'plugins' => [ 'a', 'b' ], 'signatures' => [ 'a' => [ 'a-' ] ], 'assets' => [ [ 'kind' => 'style', 'handle' => 'x' ] ] ];
		$sample = [ 'page' => 'product', 'state' => 'guest', 'target' => 'product-composite', 'status' => 200, 'plugins' => [ 'a', 'b', 'c' ], 'signatures' => [ 'b' => [ 'b-' ], 'a' => [ 'a2' ] ], 'assets' => [] ];
		$broken = [ 'page' => 'product', 'state' => 'guest', 'target' => 'product-variable', 'status' => 500, 'plugins' => [ 'd' ], 'signatures' => [ 'd' => [ 'd-' ] ] ];
		$m = Runner::merge_samples( $main, [ $sample, $broken ] );
		$this->assertSame( [ 'a', 'b', 'c' ], $m['plugins'] );
		$this->assertSame( [ 'a' => [ 'a-', 'a2' ], 'b' => [ 'b-' ] ], $m['signatures'] );
		$this->assertSame( $main['assets'], $m['assets'] ); // files and hash stay the main sample's (trials compare those)
		$this->assertSame( [ 'product-composite' ], $m['samples'] );
		// A plugin used on any sample is no candidate there.
		$c = array_map( fn( $x ) => $x['target'], Runner::candidates_from( [ 'product' => [ 'guest' => $m ] ] ) );
		$this->assertSame( [ 'unload:c' ], $c );
	}
	public function test_sample_report_names(): void {
		$this->assertSame( 'product-guest.json', Recorder::file_name( 'product', 'guest', [], '' ) );
		$this->assertSame( 'product-composite-cart.json', Recorder::file_name( 'product', 'cart', [], 'product-composite' ) );
		$this->assertStringStartsWith( 'product-guest-trial-', Recorder::file_name( 'product', 'guest', [ [ 'target' => 'unload:x' ] ], '' ) );
		$this->assertSame( 'product-guest.json', Recorder::file_name( 'product', 'guest', [], '../evil' ) ); // invalid target ignored
		$this->assertSame( 'product-variable', Runner::sample_target( 'variable' ) );
		$this->assertSame( 'product-variable-subscription', Runner::sample_target( 'variable-subscription' ) );
	}
}
