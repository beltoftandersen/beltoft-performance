<?php
/** One page's view: a page type, or (Advanced) one URL or a Bricks template. $scope is valid (OverviewPage::render). */
defined( 'ABSPATH' ) || exit;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Admin\Sorting;
use BeltoftPerformance\Admin\Words;
use BeltoftPerformance\Rules\Store;

$ctx     = OverviewPage::context();
$is_type = in_array( $scope, Store::PAGE_TYPES, true );
$data    = Manager::view_data( $scope );
$via     = array();
if ( $is_type ) {
	$reports = array_values( (array) ( $ctx['inv'][ $scope ] ?? array() ) );
	$page    = $scope;
} else {
	$reports = $data['report'] ? array( $data['report'] ) : array();
	$page    = 0 === strpos( $scope, 'url:' ) ? Manager::scope_page( $scope, \BeltoftPerformance\Request\RouteMap::build() ) : '';
	if ( in_array( (string) $data['meta']['page'], Store::PAGE_TYPES, true ) ) {
		$via[] = (string) $data['meta']['page'];
	}
	if ( 0 === strpos( $scope, 'url:' ) ) {
		foreach ( (array) $data['meta']['templates'] as $tid ) {
			if ( (int) $tid > 0 ) {
				$via[] = 'template:' . (int) $tid;
			}
		}
	}
}
$queue_states = array();
foreach ( array_keys( $ctx['queue']['waiting'] ) as $t ) {
	$queue_states[ (string) $t ] = 'waiting';
}
if ( null !== $ctx['queue']['running'] ) {
	$queue_states[ (string) $ctx['queue']['running']['target'] ] = 'running';
}
$s = Sorting::page_sections( array(
	'scope'       => $scope,
	'page'        => $page,
	'reports'     => $reports,
	'inv'         => $ctx['inv'],
	'candidates'  => $ctx['candidates'],
	'trials'      => $ctx['trials'],
	'check_id'    => $ctx['check_id'],
	'rules'       => $ctx['rules'],
	'headers'     => $ctx['headers'],
	'owners'      => $ctx['owners'],
	'via'         => $via,
	'kept'        => $is_type ? OverviewPage::kept_on( $scope ) : array(),
	'queue'       => $queue_states,
	'templates'   => $ctx['templates'],
	'page_titles' => $ctx['page_titles'],
) );
$name = $is_type ? Words::page_name( $scope ) : ucfirst( Words::scope_name( $scope, $ctx['templates'], $ctx['page_titles'] ) );
$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
$path = '';
foreach ( $reports as $r ) {
	if ( '' === $path && 200 === (int) ( $r['status'] ?? 0 ) ) {
		$path = rtrim( (string) preg_replace( '/([?&])bp_check=[^&]*(&|$)/', '$1', (string) ( $r['url'] ?? '' ) ), '?&' );
	}
}
$single = in_array( $scope, array( 'front', 'shop', 'cart', 'checkout', 'order_received', 'account', 'blog' ), true ) || ! $is_type;
?>
<p class="bp-back"><a href="<?php echo esc_url( OverviewPage::tab_url( 'overview' ) ); ?>"><?php esc_html_e( '← Back to all pages', 'beltoft-performance' ); ?></a></p>
<div class="bp-head"><div class="bp-head-text">
	<h1><?php echo esc_html( $name ); ?></h1>
	<?php if ( $is_type && isset( \BeltoftPerformance\Check\Runner::targets()[ $scope ] ) && '' !== (string) ( get_option( 'beltoft_performance_last_check', array() )['id'] ?? '' ) && ! \BeltoftPerformance\Check\Runner::progress_view( (array) get_option( \BeltoftPerformance\Check\Runner::PROGRESS, array() ), time() ) ) : ?>
		<?php echo OverviewPage::button_form( 'check', array( 'pages' => $scope ), __( 'Check this page again', 'beltoft-performance' ), 'bp-btn bp-btn--outline', __( 'Looks at just this kind of page again, in the background; the other pages keep their results', 'beltoft-performance' ) ); // escaped inside ?>
	<?php endif; ?>
	<p class="bp-intro">
		<?php
		if ( '' !== $path ) {
			if ( 0 === strpos( $scope, 'template:' ) ) {
				/* translators: %s: page address */
				echo esc_html( sprintf( __( 'We looked at %s, which uses this template.', 'beltoft-performance' ), $host . $path ) ) . ' ';
			} else {
				/* translators: %s: page address */
				echo esc_html( $single ? sprintf( __( 'This is %s.', 'beltoft-performance' ), $host . $path ) : sprintf( __( 'This covers all %1$s. The check looked at %2$s.', 'beltoft-performance' ), strtolower( $name ), $host . $path ) ) . ' ';
			}
			/* translators: %d: number of plugins */
			echo esc_html( sprintf( _n( '%d plugin adds something to it.', '%d plugins add something to it.', $s['loading'], 'beltoft-performance' ), $s['loading'] ) ) . ' ';
			if ( $is_type ) {
				esc_html_e( 'Below is what you can safely switch off, what needs your decision, and what has to stay.', 'beltoft-performance' );
			} elseif ( 0 === strpos( $scope, 'template:' ) ) {
				esc_html_e( 'Changes here apply to every page using this template.', 'beltoft-performance' );
			} else {
				esc_html_e( 'Changes here apply to this one page only.', 'beltoft-performance' );
			}
		} elseif ( $is_type ) {
			esc_html_e( "The last check didn't look at this kind of page. Check the shop again from the overview.", 'beltoft-performance' );
		} else {
			esc_html_e( "We haven't looked at this page yet. Load it first to see what it uses.", 'beltoft-performance' );
		}
		?>
	</p>
</div></div><hr class="wp-header-end">

<?php $n = 0; // sections are numbered as they are shown ?>
<?php if ( ! $is_type ) : ?>
	<section class="bp-card">
		<h2><?php echo esc_html( ( ++$n ) . '. ' . __( 'Switching off by hand', 'beltoft-performance' ) ); ?></h2>
		<p><?php esc_html_e( 'Suggestions and tests are made for whole kinds of page (see the overview). Here you can switch things off by hand, under "For experts" below — nothing is tested, so open the page afterwards and click around once.', 'beltoft-performance' ); ?></p>
		<?php if ( 0 === strpos( $scope, 'template:' ) ) : ?>
			<p class="bp-muted"><?php esc_html_e( 'Plugins start before WordPress knows the template, so here a plugin can only lose its styling and code files.', 'beltoft-performance' ); ?></p>
		<?php endif; ?>
		<?php echo OverviewPage::button_form( 'manager_load', array( 'scope' => $scope ), $data['report'] ? __( 'Look at this page again', 'beltoft-performance' ) : __( 'Load this page', 'beltoft-performance' ), $data['report'] ? 'bp-btn bp-btn--outline' : 'bp-btn bp-btn--primary', '', array( 'data-bp-busy' => __( 'Loading…', 'beltoft-performance' ) ) ); // escaped inside ?>
	</section>
<?php else : ?>
	<?php
	/** The files a suggestion takes off this page, for the "What goes" list. $pick: name of the checkboxes ('' = a plain list). */
	$file_list = static function ( array $files, string $pick = '', bool $checked = false ) {
		echo '<ul class="bp-files-list">';
		foreach ( $files as $f ) {
			$meta = ( 'script' === $f['kind'] ? __( 'script', 'beltoft-performance' ) : __( 'styling', 'beltoft-performance' ) ) . ' · ' . $f['handle'] . ( $f['size'] ? ' · ' . size_format( $f['size'], 1 ) : '' );
			echo '<li>';
			if ( '' !== $pick ) {
				$per = false !== strpos( $pick, '%s' ); // t[%s] = 1 per file (Manager::save), or one list of values (files[target][])
				printf( '<label><input type="checkbox" name="%1$s" value="%2$s"%3$s> <code>%4$s</code> <span class="bp-muted">%5$s</span></label>', esc_attr( $per ? sprintf( $pick, $f['target'] ) : $pick ), esc_attr( $per ? '1' : $f['target'] ), $checked ? ' checked' : '', esc_html( $f['file'] ), esc_html( $meta ) );
			} else {
				printf( '<code>%1$s</code> <span class="bp-muted">%2$s</span>', esc_html( $f['file'] ), esc_html( $meta ) );
			}
			echo '</li>';
		}
		echo '</ul>';
	};
	// Safe items: picking "Only the files I tick" opens the list and greys out "Where" (files go on this page only); "The whole plugin" undoes it.
	$files_summary = static function ( array $files, bool $plugin ): string {
		$bytes = array_sum( array_column( $files, 'size' ) );
		/* translators: 1: number of files, 2: size */
		$n = $bytes ? sprintf( _n( '%1$d file, %2$s', '%1$d files, %2$s', count( $files ), 'beltoft-performance' ), count( $files ), size_format( $bytes, 1 ) ) : sprintf( _n( '%d file', '%d files', count( $files ), 'beltoft-performance' ), count( $files ) ); // inline code has no file size
		/* translators: %s: number of files and size */
		return $plugin ? sprintf( __( 'What goes: the plugin\'s code and its %s on this page', 'beltoft-performance' ), $n ) : sprintf( __( 'What goes: %s', 'beltoft-performance' ), $n );
	};
	$rest_tests = array(); // safe here only: offer a test of the other pages below the form
	?>
	<section class="bp-card bp-section bp-section--safe<?php echo $s['safe'] ? '' : ' is-empty'; ?>">
		<h2><?php echo esc_html( ( ++$n ) . '. ' . __( 'Safe to switch off', 'beltoft-performance' ) ); ?></h2>
		<?php if ( $s['safe'] ) : ?>
			<?php /* translators: %s: page name */ ?>
			<p><?php echo esc_html( sprintf( __( 'We loaded %s with and without these — as a visitor, with a full cart and logged in. Nothing changed. For each one, choose what to switch off and where.', 'beltoft-performance' ), $name ) ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-safe-form">
				<?php wp_nonce_field( 'bp_accept_selected' ); ?><input type="hidden" name="action" value="bp_accept_selected"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
				<?php foreach ( $s['safe'] as $item ) :
					$t      = $item['target'];
					$others = array_values( array_diff( (array) $item['pages'], array( $scope ) ) );
					if ( ! $item['all'] && $others ) {
						$rest_tests[] = $item;
					}
					?>
					<div class="bp-item bp-item--safe bp-item--choice">
						<label class="bp-item-pick">
							<input type="checkbox" name="targets[]" value="<?php echo esc_attr( $t ); ?>" checked>
							<span class="bp-item-text"><strong><?php echo esc_html( $item['name'] ); ?></strong><span><?php echo esc_html( $item['detail'] ); ?></span></span>
						</label>
						<div class="bp-choice">
							<?php $buying = in_array( $scope, \BeltoftPerformance\Admin\Manager::FLOW_PAGES, true ); ?>
							<?php if ( $item['plugin'] ) : ?>
								<fieldset>
									<legend><?php esc_html_e( 'What to switch off', 'beltoft-performance' ); ?></legend>
									<label><input type="radio" name="what[<?php echo esc_attr( $t ); ?>]" value="plugin" checked> <?php esc_html_e( 'The whole plugin', 'beltoft-performance' ); ?> <span class="bp-muted"><?php esc_html_e( '— its code doesn\'t run, so its scripts and styles go too (tested)', 'beltoft-performance' ); ?></span></label>
									<?php if ( $item['files'] && ! $buying ) : ?>
										<label><input type="radio" name="what[<?php echo esc_attr( $t ); ?>]" value="files" data-bp-files> <?php esc_html_e( 'Only its scripts and styles I tick below', 'beltoft-performance' ); ?> <span class="bp-muted"><?php esc_html_e( '— the plugin keeps running; on this page only, not tested', 'beltoft-performance' ); ?></span></label>
									<?php else : ?>
										<label class="bp-muted"><input type="radio" disabled> <?php esc_html_e( 'Only its scripts and styles', 'beltoft-performance' ); ?> — <?php echo esc_html( $buying ? __( 'not on pages that are part of buying: only whole, tested plugins here', 'beltoft-performance' ) : __( 'it has no scripts or styles on this page, so there is nothing smaller to switch off', 'beltoft-performance' ) ); ?></label>
									<?php endif; ?>
								</fieldset>
							<?php endif; ?>
							<fieldset data-bp-where>
								<legend><?php esc_html_e( 'Where', 'beltoft-performance' ); ?></legend>
								<input type="hidden" name="where_shown[<?php echo esc_attr( $t ); ?>]" value="1">
								<?php
								$rest  = array_values( array_intersect( \BeltoftPerformance\Rules\Store::PAGE_TYPES, array_diff( (array) $item['pages'], array( $scope ) ) ) ); // shop order
								$order = array_merge( in_array( $scope, (array) $item['pages'], true ) ? array( $scope ) : array(), $rest );
								foreach ( $order as $pg ) :
									$ok = in_array( $pg, (array) $item['proven'], true );
									?>
									<label<?php echo $ok ? '' : ' class="bp-muted"'; ?>><input type="checkbox" name="where[<?php echo esc_attr( $t ); ?>][]" value="<?php echo esc_attr( $pg ); ?>"<?php echo $ok ? ' checked' : ' disabled'; ?>> <?php echo esc_html( Words::page_name( $pg ) . ( $pg === $scope ? ' ' . __( '(this page)', 'beltoft-performance' ) : '' ) . ( $ok ? '' : ' — ' . __( 'not tested yet', 'beltoft-performance' ) ) ); ?></label>
								<?php endforeach; ?>
								<?php if ( count( $item['proven'] ) > 1 ) : ?>
									<span class="bp-where-quick"><button type="button" class="bp-link" data-bp-where-only="<?php echo esc_attr( $scope ); ?>"><?php esc_html_e( 'Only this page', 'beltoft-performance' ); ?></button> <button type="button" class="bp-link" data-bp-where-all><?php esc_html_e( 'All tested pages', 'beltoft-performance' ); ?></button></span>
								<?php endif; ?>
						</div>
						<?php if ( ! $item['files'] && $item['plugin'] ) : ?>
							<p class="bp-files bp-muted"><?php esc_html_e( 'What goes: only the plugin\'s code. It adds no scripts or styling files to this page, so this saves server time, not downloads.', 'beltoft-performance' ); ?></p>
						<?php endif; ?>
						<?php if ( $item['files'] ) : ?>
							<details class="bp-files">
								<summary><?php echo esc_html( $files_summary( $item['files'], $item['plugin'] ) ); ?></summary>
								<?php $file_list( $item['files'], $item['plugin'] && ! in_array( $scope, \BeltoftPerformance\Admin\Manager::FLOW_PAGES, true ) ? 'files[' . $t . '][]' : '', false ); // ticked only for "Only the files I tick" ?>
							</details>
						<?php endif; ?>
					</div>
				<?php endforeach; ?>
				<div class="bp-actions">
					<button class="bp-btn bp-btn--primary bp-btn--big"><?php esc_html_e( 'Switch off selected', 'beltoft-performance' ); ?></button>
					<span class="bp-muted"><?php esc_html_e( 'You can undo this any time under "Switched off".', 'beltoft-performance' ); ?></span>
				</div>
			</form>
			<?php foreach ( $rest_tests as $item ) : ?>
				<div class="bp-actions bp-rest-test">
					<?php /* translators: 1: name, 2: page names */ ?>
					<span><?php echo esc_html( sprintf( __( '%1$s is proven here only. To switch it off on %2$s too:', 'beltoft-performance' ), $item['name'], Words::page_list( array_values( array_diff( (array) $item['pages'], array( $scope ) ) ) ) ) ); ?></span>
					<?php echo OverviewPage::button_form( 'trial', array( 'target' => $item['target'], 'scope' => $scope, 'where' => 'rest' ), __( 'Test on the other pages', 'beltoft-performance' ), 'bp-btn bp-btn--outline', __( 'Runs in the background. You can leave this page.', 'beltoft-performance' ) ); // escaped inside ?>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="bp-muted"><?php esc_html_e( 'Nothing on this page has passed all the tests yet.', 'beltoft-performance' ); ?></p>
		<?php endif; ?>
	</section>

	<section class="bp-card bp-section bp-section--decide<?php echo $s['decide'] ? '' : ' is-empty'; ?>">
		<h2><?php echo esc_html( ( ++$n ) . '. ' . __( 'Your decision', 'beltoft-performance' ) ); ?></h2>
		<?php if ( $s['decide'] ) : ?>
			<?php /* translators: %s: page name */ ?>
			<p><?php echo esc_html( sprintf( __( "Not proven safe — some weren't tested yet, some failed a test. Test one on this page only (quicker) or on every page it is suggested for. You can also switch one off without a test; then open %s afterwards and click around once.", 'beltoft-performance' ), $name ) ); ?></p>
			<?php
			$progress = \BeltoftPerformance\Check\TestQueue::progress_text( $ctx['queue'], $scope ); // this page's items only
			$testable = array_column( array_filter( $s['decide'], static fn( $i ) => in_array( $i['action'], array( 'test', 'retest' ), true ) ), 'target' );
			$flow     = in_array( $scope, \BeltoftPerformance\Admin\Manager::FLOW_PAGES, true );
			?>
			<?php if ( '' !== $progress ) : ?>
				<p class="bp-progress" role="status" data-bp-test-progress data-ajax="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'bp_test_progress' ) ); ?>" data-scope="<?php echo esc_attr( $scope ); ?>" data-done="<?php echo esc_attr( (string) count( (array) ( $ctx['queue']['done'] ?? array() ) ) ); ?>"><span class="bp-spinner" aria-hidden="true"></span><span data-bp-text><?php echo esc_html( $progress ); ?></span></p>
			<?php endif; ?>
			<?php if ( count( $testable ) > 1 ) : ?>
				<div class="bp-actions bp-test-all">
					<?php foreach ( array( 'here' => sprintf( /* translators: %d: number */ __( 'Test all %d on this page', 'beltoft-performance' ), count( $testable ) ), 'all' => sprintf( /* translators: %d: number */ __( 'Test all %d on all their pages', 'beltoft-performance' ), count( $testable ) ) ) as $w => $label ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'bp_test_all' ); ?><input type="hidden" name="action" value="bp_test_all"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>"><input type="hidden" name="where" value="<?php echo esc_attr( $w ); ?>">
							<?php foreach ( $testable as $tt ) : ?><input type="hidden" name="targets[]" value="<?php echo esc_attr( $tt ); ?>"><?php endforeach; ?>
							<button class="bp-btn <?php echo 'here' === $w ? 'bp-btn--primary' : 'bp-btn--outline'; ?>"><?php echo esc_html( $label ); ?></button>
						</form>
					<?php endforeach; ?>
					<span class="bp-muted"><?php esc_html_e( 'Runs in the background, one after the other. You can leave this page; tested ones move to "Safe to switch off" on their own.', 'beltoft-performance' ); ?></span>
				</div>
			<?php endif; ?>
			<?php foreach ( $s['decide'] as $item ) :
				$t      = $item['target'];
				$others = array_values( array_diff( (array) $item['pages'], array( $scope ) ) );
				?>
				<div class="bp-item bp-item--decide bp-item--choice">
					<div class="bp-item-head">
						<div class="bp-item-text">
							<strong><?php echo esc_html( $item['name'] ); ?></strong>
							<span><?php echo esc_html( $item['detail'] ); ?></span>
							<?php if ( $others ) : ?>
								<?php /* translators: %s: page names */ ?>
								<span><?php echo esc_html( sprintf( __( 'Also suggested for: %s.', 'beltoft-performance' ), Words::page_list( $others ) ) ); ?></span>
							<?php endif; ?>
						</div>
						<span class="bp-item-actions">
							<?php
							if ( in_array( $item['action'], array( 'test', 'retest' ), true ) ) {
								$again = 'retest' === $item['action'];
								/* translators: %s: how long */
								echo OverviewPage::button_form( 'trial', array( 'target' => $t, 'scope' => $scope, 'where' => 'here' ), $again ? __( 'Test again on this page', 'beltoft-performance' ) : __( 'Test on this page', 'beltoft-performance' ), 'bp-btn bp-btn--outline', __( 'Runs in the background. You can leave this page.', 'beltoft-performance' ) ); // escaped inside
								if ( $others ) {
									/* translators: %d: number of pages */
									echo OverviewPage::button_form( 'trial', array( 'target' => $t, 'scope' => $scope, 'where' => 'all' ), sprintf( _n( 'Test on %d page', 'Test on all %d pages', count( $item['pages'] ), 'beltoft-performance' ), count( $item['pages'] ) ), 'bp-btn bp-btn--outline', sprintf( __( 'Runs in the background (%s). You can leave this page.', 'beltoft-performance' ), $item['time'] ) ); // escaped inside
								}
							}
							echo OverviewPage::button_form( 'reject_page', array( 'target' => $t, 'page_type' => $scope, 'scope' => $scope ), __( 'Keep it', 'beltoft-performance' ), 'bp-link', __( 'Stop suggesting it for this kind of page', 'beltoft-performance' ) ); // escaped inside
							?>
						</span>
					</div>
					<?php if ( in_array( $item['action'], array( 'retest', 'queued' ), true ) ) : // failed, or being tested: no switch-off by hand here ?>
						<?php if ( $item['files'] ) : ?>
							<details class="bp-files"><summary><?php echo esc_html( $files_summary( $item['files'], $item['plugin'] ) ); ?></summary><?php $file_list( $item['files'] ); ?></details>
						<?php endif; ?>
					<?php else : ?>
					<details class="bp-files">
						<summary><?php echo esc_html( $item['files'] ? $files_summary( $item['files'], $item['plugin'] ) . ' — ' . __( 'or switch it off without a test', 'beltoft-performance' ) : ( $item['plugin'] ? __( 'What goes: only the plugin\'s code (no files on this page) — or switch it off without a test', 'beltoft-performance' ) : __( 'Switch it off without a test', 'beltoft-performance' ) ) ); ?></summary>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-by-hand"<?php echo 'script' === $item['action'] ? ' data-bp-confirm="' . esc_attr__( 'Scripts can be needed on click or by other scripts — check the page by hand after switching it off.', 'beltoft-performance' ) . '"' : ''; ?>>
							<?php wp_nonce_field( 'bp_manager_save' ); ?><input type="hidden" name="action" value="bp_manager_save"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
							<?php if ( $item['plugin'] ) : ?>
								<label class="bp-by-hand-whole"><input type="checkbox" name="t[<?php echo esc_attr( $t ); ?>]" value="1" data-bp-whole> <strong><?php esc_html_e( 'The whole plugin', 'beltoft-performance' ); ?></strong> <span class="bp-muted"><?php esc_html_e( '— its code doesn\'t run here, so all its files go too', 'beltoft-performance' ); ?></span></label>
								<?php if ( $item['files'] ) : ?>
									<p class="bp-muted"><?php esc_html_e( 'Or only some of its files (the plugin keeps running):', 'beltoft-performance' ); ?></p>
									<?php $file_list( $item['files'], 't[%s]', false ); ?>
								<?php endif; ?>
							<?php else : ?>
								<?php if ( $item['files'] ) { $file_list( $item['files'] ); } ?>
								<label><input type="checkbox" name="t[<?php echo esc_attr( $t ); ?>]" value="1"> <?php esc_html_e( 'Switch this file off', 'beltoft-performance' ); ?></label>
							<?php endif; ?>
							<?php if ( $flow ) : ?>
								<label><input type="checkbox" name="bp_flow_tested" value="1" required> <?php esc_html_e( 'I clicked through checkout myself (add to cart, check out, pay) with it switched off, and everything worked', 'beltoft-performance' ); ?></label>
							<?php endif; ?>
							<?php /* translators: %s: page name */ ?>
							<button class="bp-btn bp-btn--outline"><?php echo esc_html( sprintf( __( 'Switch off on %s, without a test', 'beltoft-performance' ), $name ) ); ?></button>
						</form>
					</details>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="bp-muted"><?php esc_html_e( 'Nothing waiting for you here.', 'beltoft-performance' ); ?></p>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( $reports ) : ?>
	<section class="bp-card bp-section">
		<h2><?php echo esc_html( ( ++$n ) . '. ' . ( $is_type ? __( 'Needed here — leave these on', 'beltoft-performance' ) : __( 'Loading on this page', 'beltoft-performance' ) ) ); ?></h2>
		<p><?php echo esc_html( $is_type ? __( 'These stay on here.', 'beltoft-performance' ) : __( 'These plugins load here. To switch one off for this page only, use "For experts" below.', 'beltoft-performance' ) ); ?></p>
		<div class="bp-tiles">
			<?php foreach ( $s['needed'] as $item ) : ?>
				<div class="bp-tile"><strong><?php echo esc_html( $item['name'] ); ?></strong><span><?php echo esc_html( $item['phrase'] ); ?></span></div>
			<?php endforeach; ?>
			<?php if ( $s['always'] ) : ?>
				<div class="bp-tile"><strong><?php esc_html_e( 'WooCommerce, Bricks and your own plugins', 'beltoft-performance' ); ?></strong><span><?php esc_html_e( 'the shop itself — never suggested. You can still switch single files off yourself under "For experts" below.', 'beltoft-performance' ); ?></span></div>
			<?php endif; ?>
		</div>
	</section>
<?php endif; ?>

<section class="bp-card bp-section">
	<h2><?php echo esc_html( ( ++$n ) . '. ' . __( 'Already switched off here', 'beltoft-performance' ) ); ?></h2>
	<?php if ( $s['off'] ) : ?>
		<p><?php echo esc_html( implode( ', ', $s['off'] ) . '.' ); ?></p>
		<p><a href="<?php echo esc_url( OverviewPage::tab_url( 'off' ) ); ?>"><?php esc_html_e( 'Undo one →', 'beltoft-performance' ); ?></a></p>
	<?php else : ?>
		<p class="bp-muted"><?php esc_html_e( 'Nothing is switched off here yet.', 'beltoft-performance' ); ?></p>
	<?php endif; ?>
</section>

<?php include __DIR__ . '/experts.php'; ?>
