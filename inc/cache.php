<?php
/**
 * Exclude the form page from full-page caching.
 *
 * LiteSpeed (and other caches) store the rendered HTML including the form's
 * wp_nonce_field value. Nonces expire on a ~12-24h tick, so a cached page can
 * serve a stale nonce and the submission fails verification. Marking any page
 * that contains [ch_tryout_form] as non-cacheable means the nonce is generated
 * fresh on every request. The admin-post.php endpoint it posts to is never
 * cached regardless.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'wp', 'ch_tryout_maybe_no_cache' );

function ch_tryout_maybe_no_cache() {
	if ( is_admin() || ! is_singular() ) {
		return;
	}

	$content = (string) get_post_field( 'post_content', get_queried_object_id() );
	if ( ! has_shortcode( $content, 'ch_tryout_form' ) ) {
		return;
	}

	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true ); // Honored by LiteSpeed + most page caches.
	}

	// LiteSpeed-native control hook.
	do_action( 'litespeed_control_set_nocache', 'ch_tryout_form present — keep nonce fresh' );
}
