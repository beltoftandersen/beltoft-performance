<?php
/** Switched off: one card per thing with an inline "Switch back on…" chooser; the suggestions the owner chose to keep. */
defined( 'ABSPATH' ) || exit;
use BeltoftPerformance\Admin\OverviewPage;
use BeltoftPerformance\Admin\Sorting;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Admin\Words;

$ctx   = OverviewPage::context();
$cards = Sorting::off_cards( $ctx['rules'], array(
	'headers'     => $ctx['headers'],
	'owners'      => $ctx['owners'],
	'templates'   => $ctx['templates'],
	'page_titles' => $ctx['page_titles'],
	'names'       => OverviewPage::display_names( $ctx['rules'] ),
	'installed'   => array_keys( $ctx['headers'] ),
) );
$kept = Ui::dismissed_rows( $ctx['rejected'], $ctx['rejected_pages'] );
?>
<?php if ( ! $cards ) : ?>
	<section class="bp-card"><p><?php esc_html_e( 'Nothing is switched off yet. Open a page under Overview to see what can be.', 'beltoft-performance' ); ?></p></section>
<?php endif; ?>
<div class="bp-offlist">
	<?php foreach ( $cards as $i => $c ) : ?>
		<section class="bp-card bp-offcard">
			<div class="bp-offcard-text">
				<h2><?php echo esc_html( $c['name'] ); ?></h2>
				<span class="bp-where"><?php echo esc_html( $c['where'] ); ?></span>
				<span class="bp-prov bp-prov--<?php echo esc_attr( $c['provenance']['tone'] ); ?>"><?php echo esc_html( $c['provenance']['text'] ); ?></span>
				<?php foreach ( $c['warnings'] as $w ) : ?><span class="bp-warning"><?php echo esc_html( $w ); ?></span><?php endforeach; ?>
			</div>
			<details class="bp-switch-back">
				<summary class="bp-btn bp-btn--outline"><?php esc_html_e( 'Switch back on…', 'beltoft-performance' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-chooser">
					<?php wp_nonce_field( 'bp_switch_back_on' ); ?><input type="hidden" name="action" value="bp_switch_back_on">
					<?php foreach ( $c['keys'] as $k ) : ?><input type="hidden" name="keys[]" value="<?php echo esc_attr( $k ); ?>"><?php endforeach; ?>
					<?php /* translators: %s: name of the thing */ ?>
					<p><strong><?php echo esc_html( sprintf( __( 'Where should %s load again?', 'beltoft-performance' ), $c['name'] ) ); ?></strong></p>
					<div class="bp-choices">
						<?php foreach ( $c['choices'] as $ch ) : ?>
							<label><input type="checkbox" name="on[]" value="<?php echo esc_attr( $ch['value'] ); ?>"> <?php echo esc_html( $ch['label'] ); ?></label>
						<?php endforeach; ?>
						<label class="bp-everywhere"><input type="checkbox" name="everywhere" value="1" data-bp-all<?php echo $c['choices'] ? '' : ' checked'; ?>> <?php esc_html_e( 'Everywhere', 'beltoft-performance' ); ?></label>
					</div>
					<button class="bp-btn bp-btn--primary"><?php esc_html_e( 'Switch back on', 'beltoft-performance' ); ?></button>
				</form>
			</details>
		</section>
	<?php endforeach; ?>
</div>

<div class="bp-offfoot">
	<?php /* translators: %d: number of things */ ?>
	<span><?php echo esc_html( sprintf( _n( '%d thing switched off', '%d things switched off', count( $cards ), 'beltoft-performance' ), count( $cards ) ) ); ?></span>
	<?php if ( $kept ) : ?>
		<details class="bp-kept">
			<?php /* translators: %d: number of suggestions */ ?>
			<summary><?php echo esc_html( sprintf( __( 'Suggestions you chose to keep (%d)', 'beltoft-performance' ), count( $kept ) ) ); ?></summary>
			<p class="bp-muted"><?php esc_html_e( "You said \"Keep it\" to these, so they aren't suggested any more. Click \"Suggest again\" to see them after the next check.", 'beltoft-performance' ); ?></p>
			<ul>
				<?php foreach ( $kept as $d ) : ?>
					<li>
						<?php /* translators: 1: name, 2: where */ ?>
						<span><?php echo esc_html( sprintf( __( '%1$s — kept on %2$s', 'beltoft-performance' ), Words::thing_name( $d['target'], $ctx['owners'], $ctx['headers'] ), '' === $d['page'] ? __( 'every page', 'beltoft-performance' ) : Words::page_name( $d['page'] ) ) ); ?></span>
						<?php echo OverviewPage::button_form( 'restore_dismissed', array( 'target' => $d['target'], 'page_type' => $d['page'] ), __( 'Suggest again', 'beltoft-performance' ), 'bp-link' ); // escaped inside ?>
					</li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</div>
