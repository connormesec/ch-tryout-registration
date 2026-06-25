<?php
/**
 * Plugin Name:       CH Tryout Registration
 * Description:        Tryout registration form that stores players in the database and syncs them to a Google Sheet tab via the Google Sheets API (OAuth). Shortcode: [ch_tryout_form].
 * Version:           1.0.0
 * Author:            Connor Mesec
 * License:           GPL-2.0-or-later
 * Text Domain:       ch-tryout
 *
 * Companion to the Club Hockey Divi child theme. Uses the same ch_ naming
 * conventions but is namespaced ch_tryout_* to avoid collisions with the theme.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CH_TRYOUT_VERSION', '1.0.0' );
define( 'CH_TRYOUT_DB_VERSION', '1' );
define( 'CH_TRYOUT_FILE', __FILE__ );
define( 'CH_TRYOUT_PATH', plugin_dir_path( __FILE__ ) );
define( 'CH_TRYOUT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Single source of truth for the registration fields.
 *
 * Drives: the form markup, server-side validation/sanitization, the DB table
 * columns, and the Google Sheet header row (in this order). Every field is
 * required. To change the form, change this array.
 *
 * Each field:
 *   key      => column / input name (snake_case)
 *   label    => visible label + Sheet header
 *   type     => text|email|tel|date|select
 *   sanitize => text|email|tel|date|select (how the handler cleans it)
 *   options  => array of allowed values (select only; also the whitelist)
 *   placeholder => optional input placeholder
 *   col      => MySQL column definition for the schema
 *
 * @return array<int,array<string,mixed>>
 */
function ch_tryout_fields() {
	return array(
		array(
			'key'      => 'first_name',
			'label'    => 'First name',
			'type'     => 'text',
			'sanitize' => 'text',
			'col'      => "VARCHAR(100) NOT NULL DEFAULT ''",
		),
		array(
			'key'      => 'last_name',
			'label'    => 'Last name',
			'type'     => 'text',
			'sanitize' => 'text',
			'col'      => "VARCHAR(100) NOT NULL DEFAULT ''",
		),
		array(
			'key'         => 'email',
			'label'       => 'Email',
			'type'        => 'email',
			'sanitize'    => 'email',
			'placeholder' => 'example@msuhockey.com',
			'col'         => "VARCHAR(190) NOT NULL DEFAULT ''",
		),
		array(
			'key'         => 'phone',
			'label'       => 'Cell phone number',
			'type'        => 'tel',
			'sanitize'    => 'tel',
			'placeholder' => '1234567890',
			'col'         => "VARCHAR(40) NOT NULL DEFAULT ''",
		),
		array(
			'key'      => 'birthday',
			'label'    => 'Birthday',
			'type'     => 'date',
			'sanitize' => 'date',
			'col'      => 'DATE NULL DEFAULT NULL',
		),
		array(
			'key'      => 'year_in_school',
			'label'    => 'Year in school',
			'type'     => 'select',
			'sanitize' => 'select',
			'options'  => array( 'Freshman', 'Sophomore', 'Junior', 'Senior', 'Graduate' ),
			'col'      => "VARCHAR(40) NOT NULL DEFAULT ''",
		),
		array(
			'key'      => 'position',
			'label'    => 'Position',
			'type'     => 'select',
			'sanitize' => 'select',
			'options'  => array( 'Forward', 'Defense', 'Goalie' ),
			'col'      => "VARCHAR(40) NOT NULL DEFAULT ''",
		),
		array(
			'key'      => 'jersey_size',
			'label'    => 'Jersey size',
			'type'     => 'select',
			'sanitize' => 'select',
			'options'  => array( 'S', 'M', 'L', 'XL', 'XXL', 'XXXL' ),
			'col'      => "VARCHAR(10) NOT NULL DEFAULT ''",
		),
		array(
			'key'         => 'height',
			'label'       => 'Height',
			'type'        => 'text',
			'sanitize'    => 'text',
			'placeholder' => '5\'11"',
			'col'         => "VARCHAR(20) NOT NULL DEFAULT ''",
		),
		array(
			'key'      => 'shoots',
			'label'    => 'Shoots',
			'type'     => 'select',
			'sanitize' => 'select',
			'options'  => array( 'Left', 'Right' ),
			'col'      => "VARCHAR(10) NOT NULL DEFAULT ''",
		),
		array(
			'key'         => 'hometown',
			'label'       => 'Hometown',
			'type'        => 'text',
			'sanitize'    => 'text',
			'placeholder' => 'City, State',
			'col'         => "VARCHAR(120) NOT NULL DEFAULT ''",
		),
		array(
			'key'         => 'last_team',
			'label'       => 'Last team',
			'type'         => 'text',
			'sanitize'    => 'text',
			'placeholder' => 'Team / League',
			'col'         => "VARCHAR(150) NOT NULL DEFAULT ''",
		),
	);
}

/** Fully-qualified registrants table name. */
function ch_tryout_table() {
	global $wpdb;
	return $wpdb->prefix . 'ch_tryout_registrants';
}

require_once CH_TRYOUT_PATH . 'inc/schema.php';
require_once CH_TRYOUT_PATH . 'inc/sheets-sync.php';
require_once CH_TRYOUT_PATH . 'inc/settings.php';
require_once CH_TRYOUT_PATH . 'inc/emails.php';
require_once CH_TRYOUT_PATH . 'inc/form.php';
require_once CH_TRYOUT_PATH . 'inc/cache.php';
require_once CH_TRYOUT_PATH . 'inc/handler.php';
require_once CH_TRYOUT_PATH . 'inc/admin.php';

register_activation_hook( __FILE__, 'ch_tryout_install' );

/* Create/upgrade the table on every load if the stored version is behind. */
add_action( 'init', 'ch_tryout_maybe_upgrade_db' );
