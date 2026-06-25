<?php
/**
 * Google Sheets sync via an Apps Script Web App.
 *
 * No Google Cloud project / OAuth. A small Apps Script bound to the target
 * sheet runs as the sheet owner and appends rows. This plugin just POSTs each
 * registrant (with a shared secret) to that Web App URL. The script creates the
 * tab and writes the header row on first use.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Header row the Apps Script writes if the tab is empty: timestamp + labels.
 *
 * @return string[]
 */
function ch_tryout_sheet_headers() {
	$headers = array( 'Submitted At' );
	foreach ( ch_tryout_fields() as $field ) {
		$headers[] = $field['label'];
	}
	return $headers;
}

/**
 * POST one registrant to the Apps Script Web App.
 *
 * @param array $data Field key => value, plus 'created_at'.
 * @return true|WP_Error
 */
function ch_tryout_sync_to_sheet( $data ) {
	$settings = ch_tryout_settings();
	$url      = $settings['web_app_url'];
	$tab      = $settings['tab_name'] ? $settings['tab_name'] : 'Registrations';

	if ( ! $url ) {
		return new WP_Error( 'ch_tryout_no_url', 'No Apps Script Web App URL is configured.' );
	}

	// Build the row in header order: timestamp first, then each field.
	$row = array( $data['created_at'] ?? '' );
	foreach ( ch_tryout_fields() as $field ) {
		$row[] = isset( $data[ $field['key'] ] ) ? (string) $data[ $field['key'] ] : '';
	}

	$response = wp_remote_post(
		$url,
		array(
			'timeout'     => 25,
			'redirection' => 0, // Follow the redirect ourselves — see below.
			'headers'     => array( 'Content-Type' => 'application/json' ),
			'body'        => wp_json_encode(
				array(
					'secret'  => ch_tryout_get_secret(),
					'tab'     => $tab,
					'headers' => ch_tryout_sheet_headers(),
					'row'     => $row,
				)
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	// Apps Script 302-redirects POSTs to googleusercontent.com and expects the
	// follow-up to be a GET. WordPress's HTTP client would re-POST (Google then
	// returns 400), so we follow the redirect manually as a GET instead.
	$code = wp_remote_retrieve_response_code( $response );
	$hops = 0;
	while ( $code >= 300 && $code < 400 && $hops < 5 ) {
		$location = wp_remote_retrieve_header( $response, 'location' );
		if ( ! $location ) {
			break;
		}
		$response = wp_remote_get( $location, array( 'timeout' => 25 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$hops++;
	}

	if ( $code < 200 || $code >= 300 ) {
		// Apps Script returns 401/403 HTML when not deployed for "Anyone".
		return new WP_Error( 'ch_tryout_http', 'Web App returned HTTP ' . $code . '. Check the deployment is set to "Anyone".' );
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $body ) || empty( $body['ok'] ) ) {
		$msg = is_array( $body ) && ! empty( $body['error'] ) ? $body['error'] : 'Unexpected response from the Web App.';
		return new WP_Error( 'ch_tryout_appsscript', $msg );
	}

	return true;
}
