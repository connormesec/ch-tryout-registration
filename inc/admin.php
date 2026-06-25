<?php
/**
 * Admin: "Tryout Registrants" list + CSV export.
 *
 * Lists every saved registrant (newest first), flags rows whose Google Sheet
 * sync failed, and offers a CSV download — the recovery path if the Sheet write
 * ever breaks, and a queryable record of everyone who registered.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'ch_tryout_registrants_menu' );
add_action( 'admin_post_ch_tryout_export', 'ch_tryout_export_csv' );

function ch_tryout_registrants_menu() {
	add_menu_page(
		'Tryout Registrants',
		'Tryout Registrants',
		'manage_options',
		'ch-tryout-registrants',
		'ch_tryout_render_registrants',
		'dashicons-clipboard',
		26
	);

	// Rename the auto-created first submenu, then add a shortcut to Settings.
	add_submenu_page(
		'ch-tryout-registrants',
		'Tryout Registrants',
		'Registrants',
		'manage_options',
		'ch-tryout-registrants',
		'ch_tryout_render_registrants'
	);
	add_submenu_page(
		'ch-tryout-registrants',
		'Tryout Registration Settings',
		'Settings',
		'manage_options',
		'options-general.php?page=ch-tryout'
	);
}

/**
 * Column headers for the admin table and CSV: id/date + fields + sync status.
 *
 * @return array<string,string> column key => label
 */
function ch_tryout_columns() {
	$cols = array(
		'id'         => 'ID',
		'created_at' => 'Submitted',
	);
	foreach ( ch_tryout_fields() as $field ) {
		$cols[ $field['key'] ] = $field['label'];
	}
	$cols['sheets_status'] = 'Sheet sync';
	return $cols;
}

function ch_tryout_render_registrants() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	global $wpdb;
	$table = ch_tryout_table();
	// Table name is built from $wpdb->prefix (not user input); safe to interpolate.
	$rows    = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 500", ARRAY_A );
	$failed  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE sheets_status = 'failed'" );
	$columns = ch_tryout_columns();

	$export_url = wp_nonce_url(
		admin_url( 'admin-post.php?action=ch_tryout_export' ),
		'ch_tryout_export'
	);
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline">Tryout Registrants</h1>
		<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action">Export CSV</a>
		<a href="<?php echo esc_url( admin_url( 'options-general.php?page=ch-tryout' ) ); ?>" class="page-title-action">Settings</a>
		<hr class="wp-header-end">

		<?php if ( $failed > 0 ) : ?>
			<div class="notice notice-warning">
				<p><strong><?php echo (int) $failed; ?></strong> registration(s) did not sync to Google Sheets. They are saved here and included in the CSV export. Hover the "Failed" status to see why.</p>
			</div>
		<?php endif; ?>

		<?php if ( empty( $rows ) ) : ?>
			<p>No registrations yet.</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<?php foreach ( $columns as $label ) : ?>
							<th><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<?php foreach ( $columns as $key => $label ) : ?>
								<td>
									<?php
									if ( 'sheets_status' === $key ) {
										echo ch_tryout_status_badge( $row );
									} else {
										echo esc_html( isset( $row[ $key ] ) ? $row[ $key ] : '' );
									}
									?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Showing up to 500 most recent. Use Export CSV for the full list.</p>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render the sync-status cell, with the error as a tooltip on failures.
 *
 * @param array $row
 * @return string
 */
function ch_tryout_status_badge( $row ) {
	$status = isset( $row['sheets_status'] ) ? $row['sheets_status'] : '';
	switch ( $status ) {
		case 'synced':
			return '<span style="color:#46b450;">✔ Synced</span>';
		case 'failed':
			$err = isset( $row['sheets_error'] ) ? $row['sheets_error'] : '';
			return '<span style="color:#dc3232;" title="' . esc_attr( $err ) . '">✖ Failed</span>';
		default:
			return '<span style="color:#999;">Pending</span>';
	}
}

/**
 * Stream all registrants as a CSV download.
 */
function ch_tryout_export_csv() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'ch_tryout_export' );

	global $wpdb;
	$table   = ch_tryout_table();
	$rows    = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A );
	$columns = ch_tryout_columns();

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=tryout-registrants-' . gmdate( 'Y-m-d' ) . '.csv' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array_values( $columns ) );
	foreach ( (array) $rows as $row ) {
		$line = array();
		foreach ( $columns as $key => $label ) {
			$line[] = isset( $row[ $key ] ) ? $row[ $key ] : '';
		}
		fputcsv( $out, $line );
	}
	fclose( $out );
	exit;
}
