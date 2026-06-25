<?php
/**
 * Form submission handler (admin-post.php).
 *
 * Order is deliberate: validate -> INSERT into the DB as 'pending' -> attempt
 * the Google Sheets sync -> UPDATE the row to 'synced'/'failed'. The DB write
 * happens BEFORE the network call, so a registrant is never lost even if Google
 * is down.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_post_nopriv_ch_tryout_submit', 'ch_tryout_handle_submit' );
add_action( 'admin_post_ch_tryout_submit', 'ch_tryout_handle_submit' );

const CH_TRYOUT_MIN_SECONDS    = 3;   // Reject submissions faster than this.
const CH_TRYOUT_RATE_MAX       = 6;   // Max submissions...
const CH_TRYOUT_RATE_WINDOW    = HOUR_IN_SECONDS; // ...per IP per window.

function ch_tryout_handle_submit() {
	$redirect = isset( $_POST['ch_tryout_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['ch_tryout_redirect'] ) ) : home_url( '/' );

	// 1. Nonce (reliable because the form page is excluded from cache).
	if ( ! isset( $_POST['ch_tryout_nonce'] ) || ! wp_verify_nonce( $_POST['ch_tryout_nonce'], 'ch_tryout_submit' ) ) {
		ch_tryout_redirect_error( $redirect, 'Your session expired. Please reload the page and try again.' );
	}

	// 2. Honeypot — bots fill hidden fields.
	if ( ! empty( $_POST['ch_tryout_website'] ) ) {
		// Pretend success to the bot; record nothing.
		ch_tryout_redirect_success( $redirect );
	}

	// 3. Time-trap — humans take more than a few seconds.
	$ts = isset( $_POST['ch_tryout_ts'] ) ? absint( $_POST['ch_tryout_ts'] ) : 0;
	if ( ! $ts || ( time() - $ts ) < CH_TRYOUT_MIN_SECONDS ) {
		ch_tryout_redirect_error( $redirect, 'That was a little too fast — please try again.' );
	}

	// 4. Rate limit per IP.
	$ip  = ch_tryout_client_ip();
	$key = 'ch_tryout_rl_' . md5( $ip );
	$hits = (int) get_transient( $key );
	if ( $hits >= CH_TRYOUT_RATE_MAX ) {
		ch_tryout_redirect_error( $redirect, 'Too many submissions from this connection. Please try again later.' );
	}
	set_transient( $key, $hits + 1, CH_TRYOUT_RATE_WINDOW );

	// 5. Validate + sanitize every (required) field.
	$raw   = isset( $_POST['ch_tryout'] ) && is_array( $_POST['ch_tryout'] ) ? wp_unslash( $_POST['ch_tryout'] ) : array();
	$clean = array();
	foreach ( ch_tryout_fields() as $field ) {
		$value = isset( $raw[ $field['key'] ] ) ? $raw[ $field['key'] ] : '';
		$value = ch_tryout_sanitize_value( $field, $value );
		if ( '' === $value ) {
			ch_tryout_redirect_error( $redirect, 'All fields are required. Please complete the form.' );
		}
		$clean[ $field['key'] ] = $value;
	}

	// 6. INSERT as pending (before any network call).
	global $wpdb;
	$table      = ch_tryout_table();
	$created_at = current_time( 'mysql' );

	$row = array_merge(
		$clean,
		array(
			'created_at'    => $created_at,
			'sheets_status' => 'pending',
			'ip'            => $ip,
			'user_agent'    => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
		)
	);
	$inserted = $wpdb->insert( $table, $row );

	if ( false === $inserted ) {
		ch_tryout_redirect_error( $redirect, 'We could not save your registration. Please try again.' );
	}
	$registrant_id = (int) $wpdb->insert_id;

	$clean['created_at'] = $created_at;

	// 7. Notify the registrant and the team. Best-effort: a mail failure must
	//    never block the submission or change what the registrant sees.
	ch_tryout_send_emails( $clean, $registrant_id );

	// 8. Sync to Google Sheet, then record the outcome.
	$result = ch_tryout_sync_to_sheet( $clean );

	if ( is_wp_error( $result ) ) {
		$wpdb->update(
			$table,
			array(
				'sheets_status' => 'failed',
				'sheets_error'  => $result->get_error_message(),
			),
			array( 'id' => $registrant_id )
		);
	} else {
		$wpdb->update(
			$table,
			array(
				'sheets_status' => 'synced',
				'sheets_error'  => '',
			),
			array( 'id' => $registrant_id )
		);
	}

	// Registrant is saved regardless of sync result — always confirm success.
	ch_tryout_redirect_success( $redirect );
}

/**
 * Sanitize/validate a single posted value per its field type.
 *
 * @param array  $field
 * @param string $value
 * @return string Empty string = invalid/missing.
 */
function ch_tryout_sanitize_value( $field, $value ) {
	$value = is_string( $value ) ? trim( $value ) : '';

	switch ( $field['sanitize'] ) {
		case 'email':
			$value = sanitize_email( $value );
			return is_email( $value ) ? $value : '';

		case 'tel':
			// Keep digits and common phone punctuation.
			$value = preg_replace( '/[^0-9+().\- ]/', '', $value );
			return strlen( preg_replace( '/\D/', '', $value ) ) >= 7 ? sanitize_text_field( $value ) : '';

		case 'date':
			// HTML date inputs submit Y-m-d; validate it's a real date.
			$d = DateTime::createFromFormat( 'Y-m-d', $value );
			return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : '';

		case 'select':
			return in_array( $value, $field['options'], true ) ? $value : '';

		case 'text':
		default:
			return sanitize_text_field( $value );
	}
}

/**
 * Best-effort client IP.
 *
 * @return string
 */
function ch_tryout_client_ip() {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
}

/**
 * Redirect back to the form with a success flag.
 *
 * @param string $redirect
 */
function ch_tryout_redirect_success( $redirect ) {
	wp_safe_redirect( add_query_arg( 'ch_tryout_status', 'success', $redirect ) . '#ch-tryout' );
	exit;
}

/**
 * Redirect back to the form with an error message.
 *
 * @param string $redirect
 * @param string $message
 */
function ch_tryout_redirect_error( $redirect, $message ) {
	wp_safe_redirect(
		add_query_arg(
			array(
				'ch_tryout_status' => 'error',
				'ch_tryout_msg'    => rawurlencode( $message ),
			),
			$redirect
		) . '#ch-tryout'
	);
	exit;
}
