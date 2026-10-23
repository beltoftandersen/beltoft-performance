<?php
defined( 'ABSPATH' ) || exit;
use BeltoftPerformance\Admin\Ui;
use BeltoftPerformance\Admin\Words;
use BeltoftPerformance\Cleanup\Switches;

$qa     = (array) get_option( 'beltoft_performance_qa_user', array() );
$bricks = Words::bricks_line( Ui::bricks_states( (array) get_option( 'bricks_global_settings', array() ) ) );
?>
<div class="bp-settings">
	<section class="bp-card">
		<h2><?php esc_html_e( 'WordPress extras', 'beltoft-performance' ); ?></h2>
		<p class="bp-muted"><?php esc_html_e( "Small things WordPress adds to every page that a shop doesn't need. All on is the right choice.", 'beltoft-performance' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-switches">
			<?php wp_nonce_field( 'bp_switches' ); ?><input type="hidden" name="action" value="bp_switches">
			<fieldset><legend class="screen-reader-text"><?php esc_html_e( 'WordPress extras', 'beltoft-performance' ); ?></legend>
			<?php $rows = Words::settings_rows( Switches::definitions() ); ?>
			<?php foreach ( array_diff_key( $rows, array_flip( array_merge( Switches::SCRIPT_KEYS, Switches::DB_KEYS ) ) ) as $k => [ $label, $note ] ) : ?>
				<label><input type="checkbox" name="on[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( Switches::enabled( $k ) ); ?>> <span><?php echo esc_html( $label ); ?><?php if ( '' !== $note ) : ?> <span class="bp-muted">— <?php echo esc_html( $note ); ?></span><?php endif; ?></span></label>
			<?php endforeach; ?>
			</fieldset>
			<h3><?php esc_html_e( 'Scripts', 'beltoft-performance' ); ?></h3>
			<p class="bp-muted"><?php esc_html_e( 'Make pages show sooner on phones. Each has a trade-off, so they start switched off.', 'beltoft-performance' ); ?></p>
			<fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Scripts', 'beltoft-performance' ); ?></legend>
			<?php foreach ( array_intersect_key( $rows, array_flip( Switches::SCRIPT_KEYS ) ) as $k => [ $label, $note ] ) : ?>
				<label><input type="checkbox" name="on[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( Switches::enabled( $k ) ); ?>> <span><?php echo esc_html( $label ); ?><?php if ( '' !== $note ) : ?> <span class="bp-muted">— <?php echo esc_html( $note ); ?></span><?php endif; ?></span></label>
			<?php endforeach; ?>
			</fieldset>
			<h3><?php esc_html_e( 'Database', 'beltoft-performance' ); ?></h3>
			<p class="bp-muted"><?php esc_html_e( 'Removes leftovers WordPress keeps, once a night - the same clean-up Perfmatters ran. Revisions are removed too, so older versions of pages can no longer be restored.', 'beltoft-performance' ); ?></p>
			<fieldset><legend class="screen-reader-text"><?php esc_html_e( 'Database', 'beltoft-performance' ); ?></legend>
			<?php foreach ( array_intersect_key( $rows, array_flip( Switches::DB_KEYS ) ) as $k => [ $label, $note ] ) : ?>
				<label><input type="checkbox" name="on[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( Switches::enabled( $k ) ); ?>> <span><?php echo esc_html( $label ); ?><?php if ( '' !== $note ) : ?> <span class="bp-muted">— <?php echo esc_html( $note ); ?></span><?php endif; ?></span></label>
			<?php endforeach; ?>
			<?php $db_last = (array) get_option( \BeltoftPerformance\Cleanup\Database::LAST, array() ); ?>
			<?php if ( ! empty( $db_last['time'] ) ) : ?>
				<?php $db_parts = array_filter( array_diff_key( $db_last, array( 'time' => 1 ) ) ); ?>
				<?php /* translators: 1: date and time, 2: list like "post_revisions 12, all_transients 40" */ ?>
				<p class="bp-muted"><?php echo esc_html( sprintf( __( 'Last clean-up %1$s: %2$s.', 'beltoft-performance' ), wp_date( 'j F H:i', (int) $db_last['time'] ), $db_parts ? implode( ', ', array_map( static fn( $k, $n ) => str_replace( '_', ' ', (string) $k ) . ' ' . (int) $n, array_keys( $db_parts ), $db_parts ) ) : __( 'nothing to remove', 'beltoft-performance' ) ) ); ?></p>
			<?php endif; ?>
			</fieldset>
			<p class="bp-bricks bp-muted"><?php echo esc_html( $bricks['text'] ); ?> <span class="<?php echo $bricks['ok'] ? 'bp-ok' : 'bp-bad'; ?>" aria-hidden="true"><?php echo $bricks['ok'] ? '✓' : '✗'; ?></span> <a href="<?php echo esc_url( admin_url( 'admin.php?page=bricks-settings#tab-performance' ) ); ?>"><?php esc_html_e( 'Open Bricks settings', 'beltoft-performance' ); ?></a></p>
			<p><button class="bp-btn bp-btn--primary"><?php esc_html_e( 'Save', 'beltoft-performance' ); ?></button></p>
		</form>
	</section>
	<section class="bp-card">
		<h2><?php esc_html_e( 'Test customer account', 'beltoft-performance' ); ?></h2>
		<p class="bp-muted"><?php esc_html_e( 'To check pages as a logged-in customer, the check signs in as this test account. Use a test account — never a real customer.', 'beltoft-performance' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="bp-qa">
			<?php wp_nonce_field( 'bp_qa_user' ); ?><input type="hidden" name="action" value="bp_qa_user">
			<label><?php esc_html_e( 'Username', 'beltoft-performance' ); ?> <input name="login" autocomplete="off" value="<?php echo esc_attr( $qa['login'] ?? '' ); ?>"></label>
			<label><?php esc_html_e( 'E-mail', 'beltoft-performance' ); ?> <input name="email" type="email" autocomplete="off" value="<?php echo esc_attr( $qa['email'] ?? '' ); ?>"></label>
			<p><button class="bp-btn bp-btn--primary"><?php esc_html_e( 'Save', 'beltoft-performance' ); ?></button></p>
		</form>
	</section>
</div>
