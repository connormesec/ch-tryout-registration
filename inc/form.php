<?php
/**
 * [ch_tryout_form] — the public registration form.
 *
 * Posts to admin-post.php (never cached). The page holding this shortcode is
 * excluded from LiteSpeed cache (see inc/cache.php) so the nonce stays fresh.
 * Includes a honeypot + timestamp time-trap for spam.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_shortcode( 'ch_tryout_form', 'ch_tryout_form_shortcode' );

/**
 * Register front-end assets (enqueued only when the shortcode renders).
 */
function ch_tryout_register_assets() {
	$css = CH_TRYOUT_PATH . 'assets/tryout.css';
	$js  = CH_TRYOUT_PATH . 'assets/tryout.js';
	wp_register_style( 'ch-tryout', CH_TRYOUT_URL . 'assets/tryout.css', array(), file_exists( $css ) ? filemtime( $css ) : CH_TRYOUT_VERSION );
	wp_register_script( 'ch-tryout', CH_TRYOUT_URL . 'assets/tryout.js', array(), file_exists( $js ) ? filemtime( $js ) : CH_TRYOUT_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'ch_tryout_register_assets' );

/**
 * Render one form field from its config entry.
 *
 * @param array $field
 * @return string
 */
function ch_tryout_render_field( $field ) {
	$id          = 'ch_tryout_' . $field['key'];
	$name        = 'ch_tryout[' . $field['key'] . ']';
	$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
	$required    = ! isset( $field['required'] ) || ! empty( $field['required'] );
	$options     = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : array();

	ob_start();
	?>
	<p class="ch-tryout-field ch-tryout-field--<?php echo esc_attr( $field['type'] ); ?>">
		<label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $field['label'] ); ?> <?php if ( $required ) : ?><span class="ch-tryout-req" aria-hidden="true">*</span><?php else : ?><span class="ch-tryout-opt">(optional)</span><?php endif; ?></label>
		<?php if ( 'select' === $field['type'] ) : ?>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ); ?>"<?php echo $required ? ' required' : ''; ?>>
				<option value="">— Please choose an option —</option>
				<?php foreach ( $options as $opt ) : ?>
					<option value="<?php echo esc_attr( $opt ); ?>"><?php echo esc_html( $opt ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input
				type="<?php echo esc_attr( $field['type'] ); ?>"
				id="<?php echo esc_attr( $id ); ?>"
				name="<?php echo esc_attr( $name ); ?>"
				<?php if ( $placeholder ) : ?>placeholder="<?php echo esc_attr( $placeholder ); ?>"<?php endif; ?>
				<?php if ( 'number' === $field['type'] ) : ?>
					<?php if ( isset( $field['min'] ) && '' !== $field['min'] ) : ?>min="<?php echo esc_attr( $field['min'] ); ?>" <?php endif; ?>
					<?php if ( isset( $field['max'] ) && '' !== $field['max'] ) : ?>max="<?php echo esc_attr( $field['max'] ); ?>" <?php endif; ?>
					step="1" inputmode="numeric"
				<?php endif; ?>
				<?php echo $required ? 'required' : ''; ?>>
		<?php endif; ?>
	</p>
	<?php
	return ob_get_clean();
}

/**
 * Shortcode callback.
 *
 * @return string
 */
function ch_tryout_form_shortcode() {
	wp_enqueue_style( 'ch-tryout' );
	wp_enqueue_script( 'ch-tryout' );

	$status = isset( $_GET['ch_tryout_status'] ) ? sanitize_key( wp_unslash( $_GET['ch_tryout_status'] ) ) : '';

	ob_start();
	echo '<div class="ch-tryout" id="ch-tryout">';

	if ( 'success' === $status ) {
		echo '<div class="ch-tryout-notice ch-tryout-notice--success"><p>Thanks for registering! We\'ve received your information.</p></div>';
		// Still render the form below so additional players can sign up.
	} elseif ( 'error' === $status ) {
		$msg = isset( $_GET['ch_tryout_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ch_tryout_msg'] ) ) : 'Something went wrong. Please try again.';
		echo '<div class="ch-tryout-notice ch-tryout-notice--error"><p>' . esc_html( $msg ) . '</p></div>';
	}
	?>
	<form class="ch-tryout-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
		<input type="hidden" name="action" value="ch_tryout_submit">
		<input type="hidden" name="ch_tryout_redirect" value="<?php echo esc_url( ch_tryout_current_url() ); ?>">
		<input type="hidden" name="ch_tryout_ts" value="<?php echo esc_attr( time() ); ?>">
		<?php wp_nonce_field( 'ch_tryout_submit', 'ch_tryout_nonce' ); ?>

		<?php // Honeypot — must stay empty. Hidden from humans via CSS. ?>
		<div class="ch-tryout-hp" aria-hidden="true">
			<label for="ch_tryout_website">Website</label>
			<input type="text" id="ch_tryout_website" name="ch_tryout_website" tabindex="-1" autocomplete="off">
		</div>

		<div class="ch-tryout-grid">
			<?php
			foreach ( ch_tryout_fields() as $field ) {
				echo ch_tryout_render_field( $field ); // Escaped inside.
			}
			?>
		</div>

		<p class="ch-tryout-submit">
			<button type="submit">Register!</button>
		</p>
	</form>
	<?php
	echo '</div>';
	return ob_get_clean();
}

/**
 * Current front-end URL (used as the post-submit redirect target).
 *
 * @return string
 */
function ch_tryout_current_url() {
	$id = get_queried_object_id();
	if ( $id && get_permalink( $id ) ) {
		return get_permalink( $id );
	}
	return home_url( add_query_arg( array() ) );
}
