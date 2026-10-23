<?php
defined( 'ABSPATH' ) || exit;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Admin\Sorting;
use BeltoftPerformance\Admin\Words;
use BeltoftPerformance\Loader\LoaderManager;
use BeltoftPerformance\Rules\Compiler;

$ctx     = OverviewPage::context();
$last    = $ctx['last'];
$fetched = (array) ( $last['fetched'] ?? array() );
$failed  = count( array_filter( $fetched, static fn( $f ) => 200 !== (int) ( $f['code'] ?? 0 ) ) );
$loader  = LoaderManager::status( LoaderManager::default_dir() );
$status  = Sorting::status( array(
	'loader'    => $loader['state'],
	'rules'     => OverviewPage::rules_file_state(),
	'has_check' => '' !== $ctx['check_id'],
	'pages'     => count( $fetched ),
	'failed'    => $failed,
	'off'       => Sorting::off_count( $ctx['rules'] ),
	'date'      => gmdate( 'j F', (int) ( $last['time'] ?? 0 ) + (int) round( (float) get_option( 'gmt_offset' ) * HOUR_IN_SECONDS ) ), // English month: the UI is English on a pt_PT site
) );
$kept_all = array_map( 'strval', array_keys( $ctx['rejected'] ) );
$board    = Sorting::overview( array(
	'inv'        => $ctx['inv'],
	'candidates' => $ctx['candidates'],
	'trials'     => $ctx['trials'],
	'check_id'   => $ctx['check_id'],
	'rules'      => $ctx['rules'],
	'headers'    => $ctx['headers'],
	'owners'     => $ctx['owners'],
	'kept_pages' => $ctx['rejected_pages'],
	'kept_all'   => $kept_all,
	'host'       => (string) wp_parse_url( home_url(), PHP_URL_HOST ),
) );
$icons = array(
	'ok'   => '<path d="M5 12l5 5L20 7"></path>',
	'warn' => '<path d="M12 8v5M12 16.5v.5"></path>',
	'bad'  => '<path d="M7 7l10 10M17 7L7 17"></path>',
);
?>
<section class="bp-card bp-status bp-status--<?php echo esc_attr( $status['tone'] ); ?>">
	<span class="bp-status-icon" aria-hidden="true"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><?php echo $icons[ $status['tone'] ]; // fixed markup ?></svg></span>
	<div class="bp-status-text">
		<strong class="bp-status-title"><?php echo esc_html( $status['title'] ); ?></strong>
		<span class="bp-muted"><?php echo esc_html( $status['detail'] ); ?></span>
		<?php $checking = \BeltoftPerformance\Check\Runner::progress_view( (array) get_option( \BeltoftPerformance\Check\Runner::PROGRESS, array() ), time() ); ?>
		<?php if ( $checking ) : ?>
			<?php $line = \BeltoftPerformance\Check\Runner::progress_text( $checking, OverviewPage::checking_what( $checking ) ); ?>
			<div class="bp-checking" id="bp-checking" data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bp_check_progress' ) ); ?>" data-back="<?php echo esc_url( OverviewPage::tab_url( 'overview' ) ); ?>">
				<progress max="<?php echo (int) max( 1, $checking['total'] ); ?>"<?php echo $checking['queued'] ? '' : ' value="' . (int) $checking['done'] . '"'; ?> aria-labelledby="bp-checking-text"></progress>
				<span id="bp-checking-text" class="bp-muted"><?php echo esc_html( $line ); ?></span>
				<span class="screen-reader-text" role="status"><?php echo esc_html( $line ); ?></span>
			</div>
			<script>
			( function () {
				var box = document.getElementById( 'bp-checking' ), bar = box.querySelector( 'progress' ), txt = document.getElementById( 'bp-checking-text' );
				function poll() {
					var body = new URLSearchParams( { action: 'bp_check_progress', nonce: box.dataset.nonce } );
					fetch( box.dataset.ajax, { method: 'POST', credentials: 'same-origin', body: body } ).then( function ( r ) { return r.json(); } ).then( function ( j ) {
						if ( ! j || ! j.success || ! j.data.running ) { window.location.href = box.dataset.back; return; } // without the old notice
						if ( j.data.total ) { bar.max = j.data.total; bar.value = j.data.done; }
						txt.textContent = j.data.text;
						setTimeout( poll, 2000 );
					} ).catch( function () { setTimeout( poll, 5000 ); } );
				}
				setTimeout( poll, 2000 );
			} )();
			</script>
		<?php endif; ?>
		<?php $testing = \BeltoftPerformance\Check\TestQueue::status_text( $ctx['queue'] ); ?>
		<?php if ( '' !== $testing ) : ?><span class="bp-progress" role="status" data-bp-test-progress data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bp_test_progress' ) ); ?>" data-scope="" data-done="<?php echo esc_attr( (string) count( (array) ( $ctx['queue']['done'] ?? array() ) ) ); ?>"><span class="bp-spinner" aria-hidden="true"></span><span data-bp-text><?php echo esc_html( $testing ); ?></span></span><?php endif; ?>
		<?php if ( in_array( OverviewPage::rules_file_state(), array( 'unreadable', 'wrong_owner' ), true ) ) : ?>
			<details class="bp-expert-note"><summary><?php esc_html_e( 'For your developer', 'beltoft-performance' ); ?></summary><p><?php echo esc_html( (string) OverviewPage::rules_file_status() ); ?> <code><?php echo esc_html( Compiler::file() ); ?></code></p></details>
		<?php endif; ?>
	</div>
	<?php
	if ( 'repair' === $status['action'] ) {
		echo OverviewPage::button_form( 'repair_loader', array(), __( 'Repair', 'beltoft-performance' ), 'bp-btn bp-btn--primary' ); // escaped inside
	} elseif ( 'check' === $status['action'] ) {
		echo OverviewPage::button_form( 'check', array(), __( 'Check the shop', 'beltoft-performance' ), 'bp-btn bp-btn--primary bp-btn--big' ); // escaped inside
	} elseif ( 'check_again' === $status['action'] && ! $checking ) {
		echo OverviewPage::button_form( 'check', array(), __( 'Check the shop again', 'beltoft-performance' ), 'bp-btn bp-btn--outline', __( 'Looks at every kind of page again, in the background (about a minute)', 'beltoft-performance' ) ); // escaped inside
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-inline bp-check-one">
			<input type="hidden" name="action" value="bp_check"><?php wp_nonce_field( 'bp_check' ); ?>
			<label class="screen-reader-text" for="bp-check-one"><?php esc_html_e( 'Kind of page to check again', 'beltoft-performance' ); ?></label>
			<select id="bp-check-one" name="pages">
				<?php foreach ( array_keys( \BeltoftPerformance\Check\Runner::targets() ) as $pt ) : ?>
					<option value="<?php echo esc_attr( $pt ); ?>"><?php echo esc_html( Words::scope_name( $pt, array(), array() ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="bp-btn bp-btn--outline" title="<?php esc_attr_e( 'Looks at just this kind of page again; the other pages keep their results', 'beltoft-performance' ); ?>"><?php esc_html_e( 'Check one kind of page', 'beltoft-performance' ); ?></button>
		</form>
		<?php
	}
	?>
</section>

<?php if ( '' !== $ctx['check_id'] ) : ?>
	<?php if ( $board['safe_total'] > 0 ) : ?>
		<section class="bp-card bp-todo">
			<div class="bp-todo-text">
				<?php /* translators: %d: number of things */ ?>
				<strong class="bp-todo-title"><?php echo esc_html( sprintf( _n( 'What to do now: %d safe thing to switch off', 'What to do now: %d safe things to switch off', $board['safe_total'], 'beltoft-performance' ), $board['safe_total'] ) ); ?></strong>
				<span><?php esc_html_e( 'They were tested on your shop and nothing changed on the pages. Open a page below to switch them off.', 'beltoft-performance' ); ?></span>
			</div>
			<a class="bp-btn bp-btn--primary" href="<?php echo esc_url( Manager::tab_url( $board['first_safe'] ) ); ?>"><?php esc_html_e( 'Show me', 'beltoft-performance' ); ?></a>
		</section>
	<?php else : ?>
		<section class="bp-card bp-todo bp-todo--none">
			<div class="bp-todo-text">
				<strong class="bp-todo-title"><?php esc_html_e( 'Nothing to do right now.', 'beltoft-performance' ); ?></strong>
				<span><?php esc_html_e( 'Nothing new has passed all the tests. Each page below lists what still needs your decision.', 'beltoft-performance' ); ?></span>
			</div>
		</section>
	<?php endif; ?>

	<?php
	$row = static function ( array $r ): void {
		$dash = '<span class="bp-muted">—</span>';
		printf( '<a class="bp-row" href="%s">', esc_url( Manager::tab_url( $r['type'] ) ) );
		printf( '<span class="bp-cell bp-cell--page"><strong>%s</strong><small>%s</small></span>', esc_html( $r['name'] ), esc_html( $r['hint'] ) );
		printf( '<span class="bp-cell" data-label="%s">%d</span>', esc_attr__( 'Plugins loading', 'beltoft-performance' ), (int) $r['loading'] );
		printf( '<span class="bp-cell" data-label="%s">%s</span>', esc_attr__( 'Already off', 'beltoft-performance' ), $r['off'] ? (int) $r['off'] : $dash ); // int or fixed markup
		printf( '<span class="bp-cell bp-safe-n" data-label="%s">%s</span>', esc_attr__( 'Safe to switch off', 'beltoft-performance' ), $r['safe'] ? (int) $r['safe'] : $dash );
		if ( $r['care'] ) {
			printf( '<span class="bp-cell bp-muted" data-label="%s">%s</span>', esc_attr__( 'Your decision', 'beltoft-performance' ), esc_html__( 'Handled with care: only tested changes', 'beltoft-performance' ) );
		} else {
			printf( '<span class="bp-cell bp-decide-n" data-label="%s">%s</span>', esc_attr__( 'Your decision', 'beltoft-performance' ), $r['decide'] ? (int) $r['decide'] : $dash );
		}
		printf( '<span class="bp-cell bp-open">%s</span></a>', esc_html__( 'Open →', 'beltoft-performance' ) );
	};
	$main  = array_intersect_key( $board['rows'], array_flip( Words::MAIN_PAGES ) );
	$other = array_diff_key( $board['rows'], $main );
	?>
	<section class="bp-card bp-pages">
		<div class="bp-card-head">
			<h2><?php esc_html_e( "Your shop's pages", 'beltoft-performance' ); ?></h2>
			<span class="bp-muted"><?php esc_html_e( 'Every product page works the same way, so one row covers all of them.', 'beltoft-performance' ); ?></span>
		</div>
		<div class="bp-row bp-row--head" aria-hidden="true">
			<span><?php esc_html_e( 'Page', 'beltoft-performance' ); ?></span><span><?php esc_html_e( 'Plugins loading', 'beltoft-performance' ); ?></span><span><?php esc_html_e( 'Already off', 'beltoft-performance' ); ?></span><span><?php esc_html_e( 'Safe to switch off', 'beltoft-performance' ); ?></span><span><?php esc_html_e( 'Your decision', 'beltoft-performance' ); ?></span><span></span>
		</div>
		<?php foreach ( $main as $r ) { $row( $r ); } ?>
		<?php if ( $other ) : ?>
			<details class="bp-more">
				<?php /* translators: %d: number of kinds of page */ ?>
				<summary><?php echo esc_html( sprintf( _n( 'Show the other %d kind of page', 'Show the other %d kinds of page', count( $other ), 'beltoft-performance' ), count( $other ) ) ); ?></summary>
				<?php foreach ( $other as $r ) { $row( $r ); } ?>
			</details>
		<?php endif; ?>
		<div class="bp-card-foot">
			<span><?php esc_html_e( 'Need one specific page?', 'beltoft-performance' ); ?></span>
			<a href="<?php echo esc_url( add_query_arg( 'advanced', '1', OverviewPage::tab_url( 'overview' ) ) . '#bp-advanced' ); ?>"><?php esc_html_e( 'Check one address or a Bricks template', 'beltoft-performance' ); ?></a>
		</div>
	</section>

	<p class="bp-legend">
		<span><b class="bp-c-safe"><?php esc_html_e( 'Safe to switch off', 'beltoft-performance' ); ?></b> — <?php esc_html_e( 'tested, the page looked and worked the same without it.', 'beltoft-performance' ); ?></span>
		<span><b class="bp-c-decide"><?php esc_html_e( 'Your decision', 'beltoft-performance' ); ?></b> — <?php esc_html_e( "not proven safe — some weren't tested yet, some failed a test.", 'beltoft-performance' ); ?></span>
		<span><b><?php esc_html_e( 'Already off', 'beltoft-performance' ); ?></b> — <?php esc_html_e( 'switched off earlier; you can undo it any time.', 'beltoft-performance' ); ?></span>
	</p>
<?php endif; ?>

<?php
/* ------------------------------------------------------------- advanced: one page, a URL or a Bricks template */
$raw_scope = trim( (string) wp_unslash( $_GET['scope'] ?? '' ) );
$bad_scope = '' !== $raw_scope; // a valid scope renders the page view instead of this file
$open      = $bad_scope || '1' === (string) wp_unslash( $_GET['advanced'] ?? '' );
$picker    = static function ( string $label, string $control ): void {
	printf( '<form method="get" action="%s" class="bp-picker"><input type="hidden" name="page" value="%s"><input type="hidden" name="tab" value="overview"><label><span>%s</span> ', esc_url( admin_url( 'options-general.php' ) ), esc_attr( OverviewPage::SLUG ), esc_html( $label ) );
	echo $control; // built from escaped parts below
	printf( '</label> <button class="bp-btn bp-btn--outline">%s</button></form>', esc_html__( 'Open', 'beltoft-performance' ) );
};
$select = static function ( array $options, string $empty ): string {
	$html = '<select name="scope"><option value="">' . esc_html( $empty ) . '</option>';
	foreach ( $options as $value => $label ) {
		$html .= sprintf( '<option value="%s">%s</option>', esc_attr( (string) $value ), esc_html( (string) $label ) );
	}
	return $html . '</select>';
};
$pages = array();
foreach ( Manager::page_choices() as $path => $title ) {
	$pages[ 'url:' . $path ] = $title;
}
$tpl = array();
foreach ( $ctx['templates'] as $id => $title ) {
	$tpl[ 'template:' . $id ] = $title;
}
?>
<details class="bp-card bp-advanced" id="bp-advanced"<?php echo $open ? ' open' : ''; ?>>
	<summary><?php esc_html_e( 'Advanced: open one specific page or a Bricks template', 'beltoft-performance' ); ?></summary>
	<p class="bp-muted"><?php esc_html_e( 'Changes made there apply to that one page (or every page using that template) only, and are switched off by hand — not tested.', 'beltoft-performance' ); ?></p>
	<?php if ( $bad_scope ) : ?>
		<div class="notice notice-error inline"><p><?php esc_html_e( "That page can't be opened here: it is not a normal shop page (admin, login, checkout steps and account sub-pages are always left alone).", 'beltoft-performance' ); ?></p></div>
	<?php endif; ?>
	<div class="bp-pickers">
		<?php
		$picker( __( 'Page', 'beltoft-performance' ), $select( $pages, __( '— choose a page —', 'beltoft-performance' ) ) );
		$picker( __( 'Web address', 'beltoft-performance' ), sprintf( '<input type="text" name="scope" class="regular-text" placeholder="/sobre-nos/" value="%s">', esc_attr( 0 === strpos( $raw_scope, 'url:' ) ? substr( $raw_scope, 4 ) : $raw_scope ) ) );
		if ( $tpl ) {
			$picker( __( 'Bricks template', 'beltoft-performance' ), $select( $tpl, __( '— choose a template —', 'beltoft-performance' ) ) );
		}
		?>
	</div>
</details>
