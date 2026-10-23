<?php
/**
 * "For experts: every single file on this page" — the per-file list with its toggles (Manager::save: manual rules on this scope only,
 * protected/never-switch-off rows locked, the script warning, "I tested this flow by hand" on cart/checkout/account).
 * Expects $scope, $data (Manager::view_data), $ctx (OverviewPage::context) and $is_type from page.php.
 */
defined( 'ABSPATH' ) || exit;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Rules\Store;

$rules     = $ctx['rules'];
$templates = $ctx['templates'];
$groups    = $data['report'] ? Manager::group_inventory( $data['assets'], $rules, $scope, $data['usage'], $data['meta'] ) : Manager::group_inventory( array(), $rules, $scope, array() );
$groups    = Ui::with_suggestions( $groups, $ctx['candidates'], $ctx['trials'], $ctx['check_id'], $scope, $rules );
$files     = 0;
$bytes     = 0;
foreach ( $data['assets'] as $a ) {
	++$files;
	$bytes += (int) ( $a['size'] ?? 0 );
}
$badges = array(
	'unused'         => __( 'unused here', 'beltoft-performance' ),
	'used'           => __( 'used', 'beltoft-performance' ),
	'not_measured'   => __( 'not measured', 'beltoft-performance' ),
	'protected'      => __( 'protected', 'beltoft-performance' ),
	'trial_passed'   => __( 'test passed', 'beltoft-performance' ),
	'browser_passed' => __( 'test passed', 'beltoft-performance' ),
	'needs_browser'  => __( 'browser test missing', 'beltoft-performance' ),
	'trial_failed'   => __( 'test failed', 'beltoft-performance' ),
);
$states = array(
	'not_tested'    => __( 'Not tested', 'beltoft-performance' ),
	'passed'        => __( 'Test passed', 'beltoft-performance' ),
	'failed'        => __( 'Test failed', 'beltoft-performance' ),
	'needs_browser' => __( 'Browser test missing', 'beltoft-performance' ),
);
/** The status cell: the suggestion state (actions live in the sections above) or the evidence badge. */
$status = static function ( array $row, ?string $badge ) use ( $states, $badges ): string {
	$s = $row['suggestion'] ?? null;
	if ( null === $s ) {
		return ( null === $badge || in_array( $badge, array( 'not_tested', 'removed' ), true ) ) ? '' : sprintf( '<span class="bp-badge bp-badge--%s">%s</span>', esc_attr( $badge ), esc_html( $badges[ $badge ] ?? $badge ) );
	}
	$text = $states[ $s['state'] ] . ( '' !== $s['reason'] ? ': ' . $s['reason'] : '' );
	$html = sprintf( '<span class="bp-badge bp-badge--suggested">%s</span> <span class="bp-state bp-state--%s" title="%s">%s</span>', esc_html__( 'Suggested', 'beltoft-performance' ), esc_attr( $s['state'] ), esc_attr( $text ), esc_html( wp_html_excerpt( $text, 110, '…' ) ) );
	if ( '' !== $s['command'] ) {
		$html .= ' <code class="bp-cmd">' . esc_html( $s['command'] ) . '</code>';
	}
	return $html;
};
$manage = static function ( string $via ) use ( $templates ): string {
	/* translators: %s: page type, URL or template */
	return sprintf( '<a href="%s">%s</a>', esc_url( Manager::tab_url( $via ) ), esc_html( sprintf( __( 'already off for %s', 'beltoft-performance' ), Ui::scope_label( $via, $templates ) ) ) );
};
?>
<details class="bp-experts"<?php echo $is_type ? '' : ' open'; ?>>
	<summary>
		<?php
		if ( $files ) {
			/* translators: 1: number of files, 2: total size */
			echo esc_html( sprintf( _n( 'For experts: every single file on this page (%1$d file, %2$s)', 'For experts: every single file on this page (%1$d files, %2$s)', $files, 'beltoft-performance' ), $files, sprintf( '%.1f MB', $bytes / 1048576 ) ) );
		} else {
			esc_html_e( 'For experts: every single file on this page', 'beltoft-performance' );
		}
		?>
	</summary>
	<?php
	$browser = array_filter( $s['decide'] ?? array(), static fn( $i ) => 'browser' === $i['action'] && '' !== $i['command'] );
	if ( $browser ) :
		$pw = (string) apply_filters( 'beltoft_performance_playwright_path', '/root/.npm/_npx/a78846c3c5730f70/node_modules/playwright-core' );
		?>
		<div class="bp-browser-cmds">
			<p><strong><?php esc_html_e( 'Waiting for the browser check', 'beltoft-performance' ); ?></strong> — <?php esc_html_e( 'run these on the server as root (the browser needs it), the last line gives the files back to the web server; then reload this page:', 'beltoft-performance' ); ?></p>
			<?php foreach ( $browser as $i ) : ?>
				<code class="bp-cmd"><?php echo esc_html( Ui::browser_command( (string) $i['command'], ABSPATH, $pw ) ); ?></code><br>
			<?php endforeach; ?>
			<code class="bp-cmd"><?php echo esc_html( Ui::chown_command( ABSPATH ) ); ?></code>
		</div>
	<?php endif; ?>
	<div class="bp-scope-head">
		<code><?php echo esc_html( $scope ); ?></code>
		<?php echo OverviewPage::button_form( 'manager_load', array( 'scope' => $scope ), $data['report'] ? __( 'Load again', 'beltoft-performance' ) : __( 'Load', 'beltoft-performance' ), 'button', __( 'Fetch this page as a guest and list what it prints', 'beltoft-performance' ) ); // escaped inside ?>
		<span class="bp-meta"><?php echo $data['report'] ? esc_html( $data['source'] ) : esc_html__( 'Not loaded yet.', 'beltoft-performance' ); ?></span>
	</div>
	<?php if ( '' !== $data['evidence'] ) : ?><p class="description"><?php echo esc_html( $data['evidence'] ); ?></p><?php endif; ?>
	<?php if ( $groups ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="bp-manager-form" data-warning="<?php echo esc_attr__( 'Scripts can be needed on click or by other scripts — check the page by hand after switching it off.', 'beltoft-performance' ); ?>">
		<?php wp_nonce_field( 'bp_manager_save' ); ?><input type="hidden" name="action" value="bp_manager_save"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
		<table class="widefat bp-manager">
			<thead><tr><th class="bp-toggle" scope="col"><?php esc_html_e( 'Off', 'beltoft-performance' ); ?></th><th scope="col"><?php esc_html_e( 'File', 'beltoft-performance' ); ?></th><th scope="col"><?php esc_html_e( 'Size', 'beltoft-performance' ); ?></th><th scope="col"><?php esc_html_e( 'Status', 'beltoft-performance' ); ?></th></tr></thead>
			<?php foreach ( $groups as $g ) :
				if ( '__off' === $g['key'] ) {
					$glabel = __( 'Off here (no longer printed)', 'beltoft-performance' );
				} elseif ( '__inherited' === $g['key'] ) {
					$glabel = __( 'Already off through the page type or template', 'beltoft-performance' );
				} elseif ( '__suggested' === $g['key'] ) {
					$glabel = __( 'Other suggestions', 'beltoft-performance' );
				} elseif ( '' === $g['key'] ) {
					$glabel = __( 'Core / theme', 'beltoft-performance' );
				} else {
					$glabel = $g['plugin'];
				}
				?>
				<tbody class="bp-group<?php echo $g['protected'] ? ' bp-locked' : ''; ?>">
				<tr class="bp-group-head"><th class="bp-toggle">
					<?php if ( null !== $g['target'] && null !== $g['inherited'] ) : ?>
						<?php /* translators: %s: rule target */ ?>
						<input type="checkbox" checked disabled aria-label="<?php echo esc_attr( sprintf( __( '%s is already off', 'beltoft-performance' ), $g['target'] ) ); ?>">
					<?php elseif ( null !== $g['target'] ) : ?>
						<?php /* translators: %s: rule target */ ?>
						<input type="hidden" name="t[<?php echo esc_attr( $g['target'] ); ?>]" value="0"><input type="checkbox" name="t[<?php echo esc_attr( $g['target'] ); ?>]" value="1" data-kind="plugin" <?php checked( $g['off'] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Switch off %s', 'beltoft-performance' ), $g['target'] ) ); ?>">
					<?php elseif ( $g['protected'] ) : ?>
						<input type="checkbox" disabled aria-label="<?php esc_attr_e( 'Protected', 'beltoft-performance' ); ?>">
					<?php endif; ?>
				</th><th colspan="2"><?php echo esc_html( $glabel ); ?>
					<?php /* translators: %d: number of files */ ?>
					<?php if ( null !== $g['target'] ) : ?> <small><?php echo esc_html( Ui::target_kind_label( $g['target'] ) . ' · ' . ( count( $g['files'] ) ? sprintf( _n( '%d file', '%d files', count( $g['files'] ), 'beltoft-performance' ), count( $g['files'] ) ) : __( 'no files', 'beltoft-performance' ) ) ); ?></small><?php endif; ?>
					<?php if ( $g['protected'] ) : ?> <span class="bp-badge bp-badge--protected"><?php echo esc_html( $badges['protected'] ); ?></span><?php endif; ?>
					<?php if ( null !== $g['target'] && null !== Store::never_accept_reason( (string) $g['plugin'] ) ) : ?> <span class="bp-badge bp-badge--protected" title="<?php echo esc_attr( (string) Store::never_accept_reason( (string) $g['plugin'] ) ); ?>"><?php esc_html_e( 'never suggested', 'beltoft-performance' ); ?></span><?php endif; ?>
				</th><th>
					<?php if ( null !== $g['target'] && null !== $g['inherited'] ) : ?><?php echo $manage( (string) $g['inherited'] ); // escaped inside ?><?php else : ?><?php echo $status( $g, $g['badge'] ); // escaped inside ?><?php endif; ?>
				</th></tr>
				<?php foreach ( $g['files'] as $f ) : ?>
					<tr class="<?php echo $f['protected'] ? 'bp-locked' : ''; ?>">
						<td class="bp-toggle">
							<?php if ( null !== $f['inherited'] ) : ?>
								<?php /* translators: %s: rule target */ ?>
								<input type="checkbox" checked disabled aria-label="<?php echo esc_attr( sprintf( __( '%s is already off', 'beltoft-performance' ), $f['target'] ) ); ?>">
							<?php elseif ( $f['protected'] ) : ?>
								<input type="checkbox" disabled aria-label="<?php esc_attr_e( 'Protected', 'beltoft-performance' ); ?>">
							<?php else : ?>
								<?php /* translators: %s: rule target */ ?>
								<input type="hidden" name="t[<?php echo esc_attr( $f['target'] ); ?>]" value="0"><input type="checkbox" name="t[<?php echo esc_attr( $f['target'] ); ?>]" value="1" data-kind="<?php echo esc_attr( $f['kind'] ); ?>" <?php checked( $f['off'] ); ?> aria-label="<?php echo esc_attr( sprintf( __( 'Switch off %s', 'beltoft-performance' ), $f['target'] ) ); ?>">
							<?php endif; ?>
						</td>
						<td><code title="<?php echo esc_attr( $f['src'] ); ?>"><?php echo esc_html( $f['handle'] ); ?></code> <small class="bp-kind"><?php echo esc_html( Ui::target_kind_label( $f['target'] ) ); ?></small>
							<?php /* translators: %s: other files */ ?>
							<?php if ( $f['also'] ) : ?><br><small class="bp-bad"><?php echo esc_html( sprintf( __( 'Also removes: %s', 'beltoft-performance' ), implode( ', ', $f['also'] ) ) ); ?></small><?php endif; ?>
						</td>
						<td class="bp-size"><?php echo null !== $f['size'] ? esc_html( size_format( $f['size'] ) ) : ( '' !== $f['src'] ? esc_html__( 'external', 'beltoft-performance' ) : '—' ); ?></td>
						<td><?php echo null !== $f['inherited'] ? $manage( (string) $f['inherited'] ) : $status( $f, $f['badge'] ); // escaped inside ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			<?php endforeach; ?>
		</table>
		<p class="description"><?php esc_html_e( 'Everything here can be switched off by hand except the performance plugin itself. Plugins marked "never suggested" (cache, security, e-mail, statistics) and the shop itself (WooCommerce, Bricks, jQuery, your own plugins) are never proposed by the checks: what they do often only shows later, on another page or in the checkout. Switch one off only if you know what it does, then open the page and click around.', 'beltoft-performance' ); ?></p>
		<?php if ( in_array( Manager::scope_page( $scope, \BeltoftPerformance\Request\RouteMap::build() ), Manager::FLOW_PAGES, true ) ) : ?>
			<p><label><input type="checkbox" name="bp_flow_tested" value="1"> <?php esc_html_e( 'I tested this flow by hand', 'beltoft-performance' ); ?></label>
			<br><span class="description"><?php esc_html_e( 'Needed to switch a plugin off here without a passed browser test: add to cart, check out and pay with it off first.', 'beltoft-performance' ); ?></span></p>
		<?php endif; ?>
		<p><button class="button button-primary"><?php esc_html_e( 'Save', 'beltoft-performance' ); ?></button> <span class="description"><?php esc_html_e( 'Ticked = off on this page only. Changes show under "Switched off" as switched off by hand.', 'beltoft-performance' ); ?></span></p>
	</form>
	<script>
	( function () {
		var form = document.getElementById( 'bp-manager-form' );
		if ( ! form ) { return; }
		form.addEventListener( 'submit', function ( e ) {
			var boxes = form.querySelectorAll( 'input[type=checkbox][data-kind=script]' );
			for ( var i = 0; i < boxes.length; i++ ) {
				if ( boxes[ i ].checked && ! boxes[ i ].defaultChecked ) {
					if ( ! window.confirm( form.getAttribute( 'data-warning' ) ) ) { e.preventDefault(); }
					return;
				}
			}
		} );
	} )();
	</script>
	<?php endif; ?>
</details>
