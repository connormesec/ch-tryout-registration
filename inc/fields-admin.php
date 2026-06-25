<?php
/**
 * Admin: "Form Fields" — manage the registration fields from wp-admin.
 *
 * The field list is stored in the `ch_tryout_fields_config` option (falling back
 * to ch_tryout_default_fields()). Saving rebuilds the option and runs dbDelta so
 * any new field gets a database column. Field `key`s map to DB columns, so they
 * are generated/validated to a strict [a-z][a-z0-9_]* shape and never taken from
 * raw user input; column SQL is chosen from a fixed map, never user-supplied.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Column names that already exist on the table and may not be used as field keys. */
function ch_tryout_reserved_keys() {
	return array( 'id', 'created_at', 'sheets_status', 'sheets_error', 'ip', 'user_agent' );
}

/** Field types offered in the UI: machine value => label. */
function ch_tryout_field_types() {
	return array(
		'text'   => 'Text',
		'email'  => 'Email',
		'tel'    => 'Phone',
		'number' => 'Number',
		'date'   => 'Date',
		'select' => 'Dropdown',
	);
}

/** The sanitizer used for a given field type. */
function ch_tryout_sanitize_for_type( $type ) {
	$map = array( 'text' => 'text', 'email' => 'email', 'tel' => 'tel', 'number' => 'number', 'date' => 'date', 'select' => 'select' );
	return isset( $map[ $type ] ) ? $map[ $type ] : 'text';
}

/** The DB column definition for a given field type (fixed map — never user input). */
function ch_tryout_col_for_type( $type ) {
	if ( 'date' === $type ) {
		return 'DATE NULL DEFAULT NULL';
	}
	if ( 'number' === $type ) {
		return "VARCHAR(20) NOT NULL DEFAULT ''";
	}
	return "VARCHAR(190) NOT NULL DEFAULT ''";
}

/**
 * Turn a label into a safe, unique snake_case DB column key.
 *
 * @param string   $label
 * @param string[] $taken Keys already used in this save.
 * @return string
 */
function ch_tryout_make_key( $label, $taken, $existing_cols = array() ) {
	$key = strtolower( remove_accents( $label ) );
	$key = preg_replace( '/[^a-z0-9_]+/', '_', $key );
	$key = trim( $key, '_' );
	if ( '' === $key ) {
		$key = 'field_' . ( count( $taken ) + 1 );
	} elseif ( ! preg_match( '/^[a-z]/', $key ) ) {
		$key = 'field_' . $key;
	}
	$key = substr( $key, 0, 60 );
	// Avoid this save's keys, the table's structural columns, AND any column that
	// already exists (incl. orphans from removed fields) so a re-added field gets
	// a fresh column instead of inheriting stale data.
	$avoid = array_merge( $taken, ch_tryout_reserved_keys(), array_map( 'strtolower', (array) $existing_cols ) );
	$base  = $key;
	$i     = 2;
	while ( in_array( $key, $avoid, true ) ) {
		$key = $base . '_' . $i;
		$i++;
	}
	return $key;
}

add_action( 'admin_menu', 'ch_tryout_fields_menu' );
function ch_tryout_fields_menu() {
	add_submenu_page(
		'ch-tryout-registrants',
		'Form Fields',
		'Form Fields',
		'manage_options',
		'ch-tryout-fields',
		'ch_tryout_render_fields_admin'
	);
}

add_action( 'admin_post_ch_tryout_save_fields', 'ch_tryout_handle_save_fields' );
function ch_tryout_handle_save_fields() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'ch_tryout_save_fields' );

	// Existing fields, keyed by their column key, so we can preserve keys/cols.
	$by_key = array();
	foreach ( ch_tryout_fields() as $f ) {
		$by_key[ $f['key'] ] = $f;
	}

	global $wpdb;
	$existing_cols = (array) $wpdb->get_col( 'DESC ' . ch_tryout_table() );

	$posted = isset( $_POST['fields'] ) && is_array( $_POST['fields'] ) ? wp_unslash( $_POST['fields'] ) : array();
	$types  = ch_tryout_field_types();
	$rows   = array();
	$taken  = array();

	foreach ( $posted as $row ) {
		if ( ! is_array( $row ) || ! empty( $row['delete'] ) ) {
			continue;
		}
		$label = isset( $row['label'] ) ? sanitize_text_field( trim( $row['label'] ) ) : '';
		if ( '' === $label ) {
			continue; // blank row (e.g. an unused "add field" slot)
		}
		$required    = ! empty( $row['required'] );
		$placeholder = isset( $row['placeholder'] ) ? sanitize_text_field( $row['placeholder'] ) : '';
		$order       = isset( $row['order'] ) ? (int) $row['order'] : 9999;
		$posted_key  = isset( $row['key'] ) ? preg_replace( '/[^a-z0-9_]/', '', strtolower( $row['key'] ) ) : '';

		// An existing field (key matches and not already used in this save) keeps
		// its type and column verbatim — stored data is never re-typed or dropped.
		// A field's type can only be set at creation (delete + re-add to change).
		$is_existing = $posted_key && isset( $by_key[ $posted_key ] ) && ! in_array( $posted_key, $taken, true );

		if ( $is_existing ) {
			$old  = $by_key[ $posted_key ];
			$key  = $posted_key;
			$type = isset( $old['type'] ) ? $old['type'] : 'text';
			$col  = ! empty( $old['col'] ) ? $old['col'] : ch_tryout_col_for_type( $type );
		} else {
			$old  = array();
			$type = isset( $row['type'] ) && isset( $types[ $row['type'] ] ) ? $row['type'] : 'text';
			$key  = ch_tryout_make_key( $label, $taken, $existing_cols );
			$col  = ch_tryout_col_for_type( $type );
		}
		$taken[] = $key;

		$field = array(
			'key'      => $key,
			'label'    => $label,
			'type'     => $type,
			'sanitize' => ch_tryout_sanitize_for_type( $type ),
			'required' => $required,
			'col'      => $col,
		);

		if ( 'select' === $type ) {
			$options = array();
			$raw     = isset( $row['options'] ) ? (string) $row['options'] : '';
			foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $opt ) {
				$opt = sanitize_text_field( trim( $opt ) );
				if ( '' !== $opt && ! in_array( $opt, $options, true ) ) {
					$options[] = $opt;
				}
			}
			if ( empty( $options ) ) {
				$options = ! empty( $old['options'] ) ? $old['options'] : array( 'Option 1' );
			}
			$field['options'] = $options;
		} elseif ( '' !== $placeholder ) {
			$field['placeholder'] = $placeholder;
		}

		// Number range is configured in code (not UI-editable); carry it across saves.
		if ( 'number' === $type ) {
			if ( isset( $old['min'] ) && '' !== $old['min'] ) {
				$field['min'] = (int) $old['min'];
			}
			if ( isset( $old['max'] ) && '' !== $old['max'] ) {
				$field['max'] = (int) $old['max'];
			}
		}

		// Field grouping is configured in code (not UI-editable); preserve it.
		foreach ( array( 'group', 'group_label' ) as $gk ) {
			if ( isset( $old[ $gk ] ) && '' !== $old[ $gk ] ) {
				$field[ $gk ] = $old[ $gk ];
			}
		}

		$rows[] = array( 'order' => $order, 'field' => $field );
	}

	if ( empty( $rows ) ) {
		ch_tryout_fields_redirect( 'empty' );
	}

	// Stable sort by the order field.
	usort(
		$rows,
		function ( $a, $b ) {
			return $a['order'] === $b['order'] ? 0 : ( $a['order'] < $b['order'] ? -1 : 1 );
		}
	);
	$built = array();
	foreach ( $rows as $r ) {
		$built[] = $r['field'];
	}

	update_option( 'ch_tryout_fields_config', $built, false );
	ch_tryout_install(); // dbDelta — adds any new column.

	// Confirm every field now has a column; if the migration didn't take, the
	// saved config would break the form, so surface that instead of "saved".
	$cols_after = (array) $wpdb->get_col( 'DESC ' . ch_tryout_table() );
	foreach ( $built as $f ) {
		if ( ! in_array( $f['key'], $cols_after, true ) ) {
			ch_tryout_fields_redirect( 'dberror' );
		}
	}

	ch_tryout_fields_redirect( 'saved' );
}

add_action( 'admin_post_ch_tryout_reset_fields', 'ch_tryout_handle_reset_fields' );
function ch_tryout_handle_reset_fields() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'ch_tryout_reset_fields' );
	delete_option( 'ch_tryout_fields_config' );
	ch_tryout_install();
	ch_tryout_fields_redirect( 'reset' );
}

function ch_tryout_fields_redirect( $status ) {
	wp_safe_redirect( add_query_arg( 'ch_tryout_fields', $status, admin_url( 'admin.php?page=ch-tryout-fields' ) ) );
	exit;
}

/**
 * Render one editable field card.
 *
 * @param int   $i         Render index (only groups inputs; order comes from the order box).
 * @param array $field     Field data (empty array for a blank "add" card).
 * @param bool  $is_new    Whether this is a blank add card.
 */
function ch_tryout_render_field_card( $i, $field, $is_new = false ) {
	$label       = isset( $field['label'] ) ? $field['label'] : '';
	$type        = isset( $field['type'] ) ? $field['type'] : 'text';
	$required    = ! isset( $field['required'] ) ? false : ! empty( $field['required'] );
	$placeholder = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
	$key         = isset( $field['key'] ) ? $field['key'] : '';
	$options     = isset( $field['options'] ) && is_array( $field['options'] ) ? implode( "\n", $field['options'] ) : '';
	$order       = is_numeric( $i ) ? ( (int) $i + 1 ) * 10 : 999;
	$n           = 'fields[' . $i . ']';
	?>
	<div class="ch-tryout-field-card" style="border:1px solid #c3c4c7;background:#fff;border-radius:6px;padding:14px 16px;margin:0 0 12px;display:grid;grid-template-columns:70px 1fr;gap:12px 16px;align-items:start;">
		<?php if ( $key ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $n ); ?>[key]" value="<?php echo esc_attr( $key ); ?>">
		<?php endif; ?>

		<label style="grid-column:1;">Order<br>
			<input type="number" name="<?php echo esc_attr( $n ); ?>[order]" value="<?php echo esc_attr( $order ); ?>" style="width:60px;" min="0">
		</label>

		<div style="grid-column:2;display:flex;flex-wrap:wrap;gap:12px 18px;align-items:flex-end;">
			<label style="flex:1 1 220px;">Label<br>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="e.g. Emergency contact" style="width:100%;">
			</label>
			<label>Type<br>
				<select name="<?php echo esc_attr( $n ); ?>[type]" class="ch-tryout-type" <?php echo $is_new ? '' : 'disabled'; ?>>
					<?php foreach ( ch_tryout_field_types() as $val => $tlabel ) : ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $type, $val ); ?>><?php echo esc_html( $tlabel ); ?></option>
					<?php endforeach; ?>
				</select>
				<?php if ( ! $is_new ) : ?><br><span class="description" style="font-size:11px;">locked — delete &amp; re-add to change type</span><?php endif; ?>
			</label>
			<label style="white-space:nowrap;">Required<br>
				<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[required]" value="1" <?php checked( $required ); ?>> <span class="description">Must fill in</span>
			</label>
			<?php if ( ! $is_new ) : ?>
				<label style="white-space:nowrap;color:#b32d2e;">Remove<br>
					<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[delete]" value="1"> <span class="description">Delete field</span>
				</label>
			<?php endif; ?>

			<label class="ch-tryout-placeholder-row" style="flex:1 1 100%;<?php echo 'select' === $type ? 'display:none;' : ''; ?>">Placeholder <span class="description">(hint text, optional)</span><br>
				<input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[placeholder]" value="<?php echo esc_attr( $placeholder ); ?>" style="width:100%;">
			</label>
			<label class="ch-tryout-options-row" style="flex:1 1 100%;<?php echo 'select' === $type ? '' : 'display:none;'; ?>">Dropdown options <span class="description">(one per line)</span><br>
				<textarea name="<?php echo esc_attr( $n ); ?>[options]" rows="4" class="large-text code" style="width:100%;"><?php echo esc_textarea( $options ); ?></textarea>
			</label>
		</div>
	</div>
	<?php
}

function ch_tryout_render_fields_admin() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	if ( ! empty( $_GET['ch_tryout_fields'] ) ) {
		$flag = sanitize_key( wp_unslash( $_GET['ch_tryout_fields'] ) );
		if ( 'saved' === $flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>Form fields saved. Any new fields were added to the database.</p></div>';
		} elseif ( 'reset' === $flag ) {
			echo '<div class="notice notice-success is-dismissible"><p>Fields reset to the built-in defaults.</p></div>';
		} elseif ( 'empty' === $flag ) {
			echo '<div class="notice notice-error is-dismissible"><p>You must keep at least one field — nothing was saved.</p></div>';
		} elseif ( 'dberror' === $flag ) {
			echo '<div class="notice notice-error is-dismissible"><p>Your field changes were saved, but the database table could not be fully updated — a column may be missing, so the public form may not work correctly. Please review, or contact your developer.</p></div>';
		}
	}

	$fields    = ch_tryout_fields();
	$using_def = null === get_option( 'ch_tryout_fields_config', null );
	?>
	<div class="wrap">
		<h1>Tryout Form Fields</h1>
		<p class="description" style="max-width:760px;">
			Add, edit, reorder, or remove the fields players fill in. Drag isn't needed —
			set the <strong>Order</strong> number to arrange fields (lowest first). Saving a new
			field automatically adds a column to the registrations database.
			<?php if ( $using_def ) : ?>
				<br><em>Currently showing the built-in default fields.</em>
			<?php endif; ?>
		</p>
		<div class="notice notice-info inline" style="max-width:760px;"><p>
			<strong>Heads-up about Google Sheets:</strong> the sheet's header row is written once.
			If you change fields after registrations have started, update the header row in your
			Google Sheet so the columns line up.
		</p></div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ch_tryout_save_fields">
			<?php wp_nonce_field( 'ch_tryout_save_fields' ); ?>

			<div id="ch-tryout-fields-list">
				<?php
				$i = 0;
				foreach ( $fields as $field ) {
					ch_tryout_render_field_card( $i, $field, false );
					$i++;
				}
				?>
			</div>

			<p>
				<button type="button" class="button" id="ch-tryout-add-field">+ Add field</button>
			</p>

			<?php submit_button( 'Save fields' ); ?>
		</form>

		<hr>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Reset all fields back to the built-in defaults? Your custom field list will be discarded (existing data columns are kept).');">
			<input type="hidden" name="action" value="ch_tryout_reset_fields">
			<?php wp_nonce_field( 'ch_tryout_reset_fields' ); ?>
			<?php submit_button( 'Reset to defaults', 'secondary', 'submit', false ); ?>
		</form>

		<template id="ch-tryout-field-template"><?php ch_tryout_render_field_card( '__I__', array(), true ); ?></template>

		<script>
		( function () {
			// Toggle placeholder/options rows by selected type.
			function syncCard( card ) {
				var type = card.querySelector( '.ch-tryout-type' );
				if ( ! type ) { return; }
				var isSelect = type.value === 'select';
				var opt = card.querySelector( '.ch-tryout-options-row' );
				var ph  = card.querySelector( '.ch-tryout-placeholder-row' );
				if ( opt ) { opt.style.display = isSelect ? '' : 'none'; }
				if ( ph )  { ph.style.display  = isSelect ? 'none' : ''; }
			}
			document.querySelectorAll( '.ch-tryout-field-card' ).forEach( syncCard );
			document.addEventListener( 'change', function ( e ) {
				if ( e.target && e.target.classList.contains( 'ch-tryout-type' ) ) {
					syncCard( e.target.closest( '.ch-tryout-field-card' ) );
				}
			} );

			// Add a blank field card from the template.
			var tpl  = document.getElementById( 'ch-tryout-field-template' );
			var list = document.getElementById( 'ch-tryout-fields-list' );
			var idx  = <?php echo (int) count( $fields ); ?>;
			var btn  = document.getElementById( 'ch-tryout-add-field' );
			if ( btn && tpl && list ) {
				btn.addEventListener( 'click', function () {
					var html = tpl.innerHTML.replace( /__I__/g, idx );
					var wrap = document.createElement( 'div' );
					wrap.innerHTML = html;
					var card = wrap.firstElementChild;
					list.appendChild( card );
					syncCard( card );
					idx++;
				} );
			}
		} )();
		</script>
	</div>
	<?php
}
