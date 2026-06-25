<?php
/**
 * Database schema for tryout registrants.
 *
 * The table is created on activation and also lazily upgraded on `init` when
 * the stored db_version is behind — this matters because production deploys via
 * file rsync (no DB sync), so the table must be able to appear when the plugin
 * is activated on prod.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build the CREATE TABLE statement from the field config.
 *
 * @return string
 */
function ch_tryout_schema_sql() {
	global $wpdb;
	$table           = ch_tryout_table();
	$charset_collate = $wpdb->get_charset_collate();

	$field_cols = '';
	foreach ( ch_tryout_fields() as $field ) {
		$field_cols .= "\n\t\t{$field['key']} {$field['col']},";
	}

	// dbDelta is whitespace/format sensitive: two spaces after PRIMARY KEY, etc.
	return "CREATE TABLE {$table} (
		id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
		created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',{$field_cols}
		sheets_status VARCHAR(20) NOT NULL DEFAULT 'pending',
		sheets_error TEXT NULL,
		ip VARCHAR(45) NOT NULL DEFAULT '',
		user_agent VARCHAR(255) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY sheets_status (sheets_status),
		KEY created_at (created_at)
	) {$charset_collate};";
}

/**
 * Run dbDelta to create/update the table and stamp the db version.
 */
function ch_tryout_install() {
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( ch_tryout_schema_sql() );
	update_option( 'ch_tryout_db_version', CH_TRYOUT_DB_VERSION );
}

/**
 * Lazily create/upgrade the table when the stored version is behind.
 */
function ch_tryout_maybe_upgrade_db() {
	if ( get_option( 'ch_tryout_db_version' ) === CH_TRYOUT_DB_VERSION ) {
		return;
	}
	ch_tryout_install();
}
