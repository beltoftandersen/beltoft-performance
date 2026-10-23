<?php
/**
 * The script manager (the whole tool, like Perfmatters): pick a page type, one page or a template, load it, see what loads there,
 * tick a whole plugin and/or single scripts and styles off, Save. Manager::load / view_data / group_inventory / save do the work.
 * Expects $scope (valid scope or null).
 */
defined( 'ABSPATH' ) || exit;
use BeltoftPerformance\Admin\Manager;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Admin\Words;
use BeltoftPerformance\Rules\Store;

$kind      = null === $scope ? 'type' : ( 0 === strpos( $scope, 'url:' ) ? 'page' : ( 0 === strpos( $scope, 'template:' ) ? 'template' : 'type' ) );
$pages     = Manager::page_choices();
$templates = Manager::template_choices();
$headers   = OverviewPage::plugin_headers();
?>
<form method="get" action="<?php echo esc_url( admin_url( 'options-general.php' ) ); ?>" class="bp-pick">
	<input type="hidden" name="page" value="<?php echo esc_attr( OverviewPage::SLUG ); ?>">
	<input type="hidden" name="tab" value="scripts">
	<strong><?php esc_html_e( 'Manage', 'beltoft-performance' ); ?></strong>
	<label><input type="radio" name="bp_kind" value="type" <?php checked( 'type', $kind ); ?>> <?php esc_html_e( 'Page type', 'beltoft-performance' ); ?></label>
	<select name="bp_type" aria-label="<?php esc_attr_e( 'Page type', 'beltoft-performance' ); ?>">
		<?php foreach ( Store::PAGE_TYPES as $t ) : ?>
			<option value="<?php echo esc_attr( $t ); ?>" <?php selected( $t, $scope ); ?>><?php echo esc_html( Words::page_name( $t ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<label><input type="radio" name="bp_kind" value="page" <?php checked( 'page', $kind ); ?>> <?php esc_html_e( 'One page', 'beltoft-performance' ); ?></label>
	<select name="bp_page" aria-label="<?php esc_attr_e( 'One page', 'beltoft-performance' ); ?>">
		<?php foreach ( $pages as $path => $label ) : ?>
			<option value="<?php echo esc_attr( $path ); ?>" <?php selected( 'url:' . $path, $scope ); ?>><?php echo esc_html( $label ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php if ( $templates ) : ?>
		<label><input type="radio" name="bp_kind" value="template" <?php checked( 'template', $kind ); ?>> <?php esc_html_e( 'Template', 'beltoft-performance' ); ?></label>
		<select name="bp_template" aria-label="<?php esc_attr_e( 'Template', 'beltoft-performance' ); ?>">
			<?php foreach ( $templates as $id => $label ) : ?>
				<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( 'template:' . $id, $scope ); ?>><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
	<?php endif; ?>
	<button class="button button-primary"><?php esc_html_e( 'Show', 'beltoft-performance' ); ?></button>
</form>

<?php if ( null === $scope ) : ?>
	<p><?php esc_html_e( 'Choose what to manage and press Show. Then tick what should not load there and press Save.', 'beltoft-performance' ); ?></p>
	<?php return; ?>
<?php endif; ?>

<?php
$data   = Manager::view_data( $scope );
$rules  = Store::all();
$groups = Manager::group_inventory( $data['assets'], $rules, $scope, array(), $data['meta'] );
$files  = count( $data['assets'] );
$bytes  = array_sum( array_map( static fn( $a ) => (int) ( $a['size'] ?? 0 ), $data['assets'] ) );
$name   = Ui::scope_label( $scope, $templates );
$flow   = 0 === strpos( $scope, 'template:' ) || Manager::needs_flow_confirmation( 'unload:x', Manager::scope_page( $scope, \BeltoftPerformance\Request\RouteMap::build() ) );
$label  = static function ( array $g ) use ( $headers ): string {
	if ( '__off' === $g['key'] ) {
		return __( 'Switched off here', 'beltoft-performance' );
	}
	if ( '__inherited' === $g['key'] ) {
		return __( 'Off through the page type or template', 'beltoft-performance' );
	}
	if ( '' === $g['key'] ) {
		return __( 'WordPress and theme', 'beltoft-performance' );
	}
	return Words::plugin_name( (string) $g['plugin'], $headers );
};
?>
<div class="bp-scope">
	<h2><?php echo esc_html( $name ); ?></h2>
	<span class="bp-muted">
		<?php
		if ( $data['report'] ) {
			/* translators: 1: number of files, 2: size, 3: when and where it was loaded */
			echo esc_html( sprintf( _n( '%1$d file, %2$s — %3$s', '%1$d files, %2$s — %3$s', $files, 'beltoft-performance' ), $files, size_format( $bytes, 1 ), $data['source'] ) );
		} else {
			esc_html_e( 'Not loaded yet.', 'beltoft-performance' );
		}
		?>
	</span>
	<?php echo OverviewPage::button_form( 'manager_load', array( 'scope' => $scope ), $data['report'] ? __( 'Load again', 'beltoft-performance' ) : __( 'Load this page', 'beltoft-performance' ), $data['report'] ? 'button' : 'button button-primary', __( 'Opens the page as a visitor and lists everything it loads', 'beltoft-performance' ), array( 'data-bp-busy' => __( 'Loading…', 'beltoft-performance' ) ) ); // escaped inside ?>
</div>

<?php if ( $groups ) : ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-scripts-form">
	<?php wp_nonce_field( 'bp_manager_save' ); ?><input type="hidden" name="action" value="bp_manager_save"><input type="hidden" name="scope" value="<?php echo esc_attr( $scope ); ?>">
	<p class="bp-save"><button class="button button-primary"><?php esc_html_e( 'Save', 'beltoft-performance' ); ?></button> <span class="bp-muted"><?php esc_html_e( 'Ticked = does not load here.', 'beltoft-performance' ); ?></span></p>
	<table class="widefat bp-scripts">
		<thead><tr><th class="bp-toggle" scope="col"><?php esc_html_e( 'Off', 'beltoft-performance' ); ?></th><th scope="col"><?php esc_html_e( 'Plugin / file', 'beltoft-performance' ); ?></th><th class="bp-size" scope="col"><?php esc_html_e( 'Size', 'beltoft-performance' ); ?></th></tr></thead>
		<?php foreach ( $groups as $g ) : ?>
			<tbody class="bp-group">
				<tr class="bp-group-head">
					<td class="bp-toggle">
						<?php if ( null !== $g['target'] && null !== $g['inherited'] ) : ?>
							<input type="checkbox" checked disabled>
						<?php elseif ( null !== $g['target'] ) : ?>
							<input type="hidden" name="t[<?php echo esc_attr( $g['target'] ); ?>]" value="0"><input type="checkbox" name="t[<?php echo esc_attr( $g['target'] ); ?>]" value="1" data-bp-whole <?php checked( $g['off'] ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: plugin */ __( 'Switch off all of %s', 'beltoft-performance' ), $label( $g ) ) ); ?>">
						<?php endif; ?>
					</td>
					<th scope="rowgroup" colspan="2">
						<?php echo esc_html( $label( $g ) ); ?>
						<?php if ( null !== $g['target'] ) : ?>
							<small class="bp-muted"><?php echo esc_html( 0 === strpos( (string) $g['target'], 'unload:' ) ? __( 'whole plugin', 'beltoft-performance' ) : __( 'all its files', 'beltoft-performance' ) ); ?></small>
						<?php endif; ?>
						<?php if ( null !== $g['target'] && null !== $g['inherited'] ) : ?>
							<small><a href="<?php echo esc_url( Manager::tab_url( (string) $g['inherited'] ) ); ?>"><?php echo esc_html( sprintf( /* translators: %s: page type or template */ __( 'off for %s', 'beltoft-performance' ), Ui::scope_label( (string) $g['inherited'], $templates ) ) ); ?></a></small>
						<?php elseif ( $g['protected'] ) : ?>
							<small class="bp-muted"><?php esc_html_e( 'this tool — always on', 'beltoft-performance' ); ?></small>
						<?php endif; ?>
					</th>
				</tr>
				<?php foreach ( $g['files'] as $f ) : ?>
					<tr>
						<td class="bp-toggle">
							<?php if ( null !== $f['inherited'] ) : ?>
								<input type="checkbox" checked disabled>
							<?php elseif ( $f['protected'] ) : ?>
								<input type="checkbox" disabled>
							<?php else : ?>
								<input type="hidden" name="t[<?php echo esc_attr( $f['target'] ); ?>]" value="0"><input type="checkbox" name="t[<?php echo esc_attr( $f['target'] ); ?>]" value="1" <?php checked( $f['off'] ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %s: file */ __( 'Switch off %s', 'beltoft-performance' ), $f['handle'] ) ); ?>">
							<?php endif; ?>
						</td>
						<td>
							<?php if ( in_array( $f['kind'], array( 'unload', 'plugin' ), true ) ) : // a whole plugin, or all its files, switched off here ?>
								<strong><?php echo esc_html( Words::plugin_name( (string) $f['handle'], $headers ) ); ?></strong>
								<small class="bp-muted"><?php echo esc_html( 'unload' === $f['kind'] ? __( 'whole plugin', 'beltoft-performance' ) : __( 'all its files', 'beltoft-performance' ) ); ?></small>
							<?php else : ?>
							<code title="<?php echo esc_attr( $f['src'] ); ?>"><?php echo esc_html( '' !== $f['src'] ? basename( (string) wp_parse_url( $f['src'], PHP_URL_PATH ) ) : $f['handle'] ); ?></code>
							<small class="bp-muted"><?php echo esc_html( ( 'script' === $f['kind'] ? __( 'script', 'beltoft-performance' ) : __( 'style', 'beltoft-performance' ) ) . ' · ' . $f['handle'] ); ?></small>
							<?php endif; ?>
							<?php if ( $f['also'] ) : ?><br><small class="bp-warn"><?php echo esc_html( sprintf( /* translators: %s: other files */ __( 'Also takes off: %s', 'beltoft-performance' ), implode( ', ', $f['also'] ) ) ); ?></small><?php endif; ?>
						</td>
						<td class="bp-size"><?php echo null !== $f['size'] && $f['size'] ? esc_html( size_format( $f['size'], 1 ) ) : ( '' !== $f['src'] ? esc_html__( 'external', 'beltoft-performance' ) : '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		<?php endforeach; ?>
	</table>
	<?php if ( $flow ) : ?>
		<p><label><input type="checkbox" name="bp_flow_tested" value="1"> <?php esc_html_e( 'This page is part of buying. I clicked through checkout myself (add to cart, check out, pay) with these switched off, and everything worked.', 'beltoft-performance' ); ?></label></p>
	<?php endif; ?>
	<p class="bp-save"><button class="button button-primary"><?php esc_html_e( 'Save', 'beltoft-performance' ); ?></button> <span class="bp-muted"><?php esc_html_e( 'Open the page afterwards and click around once. Undo any time here or under "Switched off".', 'beltoft-performance' ); ?></span></p>
</form>
<?php elseif ( $data['report'] ) : ?>
	<p><?php esc_html_e( 'Nothing loads on this page that can be switched off.', 'beltoft-performance' ); ?></p>
<?php endif; ?>
