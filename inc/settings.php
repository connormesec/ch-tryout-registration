<?php
/**
 * Settings screen: Settings -> Tryout Registration.
 *
 * Apps Script model: the user pastes the Web App URL of a script bound to their
 * Google Sheet, plus a shared secret (generated here and embedded in the script
 * they paste). No Google Cloud project or OAuth.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', 'ch_tryout_settings_menu' );
add_action( 'admin_init', 'ch_tryout_register_settings' );
add_action( 'admin_post_ch_tryout_test', 'ch_tryout_handle_test' );
add_action( 'admin_post_ch_tryout_test_email', 'ch_tryout_handle_test_email' );

/**
 * Settings accessor + defaults.
 *
 * @return array
 */
function ch_tryout_settings() {
	return wp_parse_args(
		(array) get_option( 'ch_tryout_settings', array() ),
		array(
			'web_app_url'     => '',
			'tab_name'        => 'Registrations',
			'notify_email'    => '',
			'confirm_enabled' => '1',
			'confirm_subject' => '',
			'confirm_body'    => '',
			'team_enabled'    => '1',
			'team_subject'    => '',
			'team_body'       => '',
		)
	);
}

/**
 * The shared secret, stored in its OWN option so the settings sanitize
 * callback can never clobber it. Generated once on first read.
 *
 * @return string
 */
function ch_tryout_get_secret() {
	$secret = (string) get_option( 'ch_tryout_secret', '' );
	if ( '' === $secret ) {
		$secret = wp_generate_password( 32, false );
		update_option( 'ch_tryout_secret', $secret, false );
	}
	return $secret;
}

function ch_tryout_settings_menu() {
	add_options_page(
		'Tryout Registration',
		'Tryout Registration',
		'manage_options',
		'ch-tryout',
		'ch_tryout_render_settings'
	);
}

function ch_tryout_register_settings() {
	register_setting(
		'ch_tryout_settings_group',
		'ch_tryout_settings',
		array(
			'type'              => 'array',
			'sanitize_callback' => 'ch_tryout_sanitize_settings',
		)
	);
}

/**
 * Sanitize editable settings. The secret lives in its own option and is not
 * touched here.
 *
 * @param array $input
 * @return array
 */
function ch_tryout_sanitize_settings( $input ) {
	$input = (array) $input;
	return array(
		'web_app_url'     => isset( $input['web_app_url'] ) ? esc_url_raw( trim( $input['web_app_url'] ) ) : '',
		'tab_name'        => isset( $input['tab_name'] ) && '' !== trim( $input['tab_name'] )
			? sanitize_text_field( trim( $input['tab_name'] ) )
			: 'Registrations',
		'notify_email'    => isset( $input['notify_email'] ) ? ch_tryout_sanitize_emails( $input['notify_email'] ) : '',
		// Email templates. Subjects are plain text; bodies allow safe HTML
		// (authored by manage_options users). Mail-tags like [first_name] pass
		// through untouched.
		'confirm_enabled' => empty( $input['confirm_enabled'] ) ? '' : '1',
		'confirm_subject' => isset( $input['confirm_subject'] ) ? sanitize_text_field( $input['confirm_subject'] ) : '',
		'confirm_body'    => isset( $input['confirm_body'] ) ? wp_kses_post( trim( $input['confirm_body'] ) ) : '',
		'team_enabled'    => empty( $input['team_enabled'] ) ? '' : '1',
		'team_subject'    => isset( $input['team_subject'] ) ? sanitize_text_field( $input['team_subject'] ) : '',
		'team_body'       => isset( $input['team_body'] ) ? wp_kses_post( trim( $input['team_body'] ) ) : '',
	);
}

/**
 * Sanitize a comma-separated list of emails down to the valid ones.
 *
 * @param string $raw
 * @return string Comma-separated list of valid emails (may be empty).
 */
function ch_tryout_sanitize_emails( $raw ) {
	$valid = array();
	foreach ( array_map( 'trim', explode( ',', (string) $raw ) ) as $part ) {
		$email = sanitize_email( $part );
		if ( $email && is_email( $email ) ) {
			$valid[] = $email;
		}
	}
	return implode( ', ', $valid );
}

/**
 * Send a test row to the Web App and report the result.
 */
function ch_tryout_handle_test() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'ch_tryout_test' );

	$sample = array( 'created_at' => current_time( 'mysql' ) );
	foreach ( ch_tryout_fields() as $field ) {
		$sample[ $field['key'] ] = 'select' === $field['type'] ? $field['options'][0] : ( 'TEST ' . $field['label'] );
	}
	$result = ch_tryout_sync_to_sheet( $sample );

	$args = is_wp_error( $result )
		? array( 'ch_tryout_test' => 'fail', 'ch_tryout_msg' => rawurlencode( $result->get_error_message() ) )
		: array( 'ch_tryout_test' => 'ok' );
	wp_safe_redirect( add_query_arg( $args, admin_url( 'options-general.php?page=ch-tryout' ) ) );
	exit;
}

/**
 * Send both registration emails (rendered from the saved templates with sample
 * data) to the current admin so they can see exactly what registrants and the
 * team will receive. Sends both regardless of the enabled toggles.
 */
function ch_tryout_handle_test_email() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Insufficient permissions.' );
	}
	check_admin_referer( 'ch_tryout_test_email' );

	$to = wp_get_current_user()->user_email;
	$ok = is_email( $to );

	if ( $ok ) {
		$sample = ch_tryout_sample_data();
		foreach ( array( 'confirm', 'team' ) as $type ) {
			$built = ch_tryout_email_build( $type, $sample );
			if ( ! ch_tryout_mail( $to, '[TEST] ' . $built['subject'], $built['html'] ) ) {
				$ok = false;
			}
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array( 'ch_tryout_email' => $ok ? 'ok' : 'fail' ),
			admin_url( 'options-general.php?page=ch-tryout' )
		)
	);
	exit;
}

/**
 * Build the ready-to-paste Apps Script with the secret embedded.
 *
 * @param string $secret
 * @return string
 */
function ch_tryout_code_gs( $secret ) {
	$secret_js = str_replace( "'", "\\'", $secret );
	return <<<GS
// Tryout Registration — paste into the bound sheet (Extensions → Apps Script),
// then Deploy → New deployment → Web app → Execute as: Me, Who has access: Anyone.
var CH_TRYOUT_SECRET = '{$secret_js}';

function doPost(e) {
  try {
    var data = JSON.parse(e.postData.contents);
    if (String(data.secret) !== CH_TRYOUT_SECRET) {
      return _out({ ok: false, error: 'bad secret' });
    }
    var ss = SpreadsheetApp.getActiveSpreadsheet();
    var tab = data.tab || 'Registrations';
    var sheet = ss.getSheetByName(tab) || ss.insertSheet(tab);
    if (sheet.getLastRow() === 0 && data.headers && data.headers.length) {
      sheet.appendRow(data.headers);
    }
    sheet.appendRow(data.row);
    return _out({ ok: true });
  } catch (err) {
    return _out({ ok: false, error: String(err) });
  }
}

function _out(obj) {
  return ContentService.createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
GS;
}

/**
 * Map query flags to admin notices.
 */
function ch_tryout_admin_notice() {
	if ( ! empty( $_GET['ch_tryout_test'] ) ) {
		$ok = 'ok' === sanitize_key( wp_unslash( $_GET['ch_tryout_test'] ) );
		if ( $ok ) {
			echo '<div class="notice notice-success is-dismissible"><p>Test row sent — check your sheet. The connection works.</p></div>';
		} else {
			$msg = isset( $_GET['ch_tryout_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['ch_tryout_msg'] ) ) : '';
			echo '<div class="notice notice-error is-dismissible"><p>Test failed: ' . esc_html( $msg ) . '</p></div>';
		}
	}

	if ( ! empty( $_GET['ch_tryout_email'] ) ) {
		if ( 'ok' === sanitize_key( wp_unslash( $_GET['ch_tryout_email'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>Test emails sent to <strong>' . esc_html( wp_get_current_user()->user_email ) . '</strong> — check your inbox.</p></div>';
		} else {
			echo '<div class="notice notice-error is-dismissible"><p>Could not send the test emails. On Local, mail may not be delivered; on production, an SMTP plugin is recommended.</p></div>';
		}
	}
}

/**
 * Render one email-template editor (enable toggle + subject + body + the
 * clickable mail-tag inserter), Contact-Form-7 style. Lives inside the main
 * settings <form> so it saves with everything else.
 *
 * @param string $type  'confirm' | 'team'
 * @param string $title Section heading.
 * @param string $intro Helper text under the heading.
 */
function ch_tryout_render_email_editor( $type, $title, $intro ) {
	$tpl     = ch_tryout_get_template( $type );
	$subj_id = 'ch_tryout_' . $type . '_subject';
	$body_id = 'ch_tryout_' . $type . '_body';
	?>
	<h3 style="margin:1.6em 0 .2em;"><?php echo esc_html( $title ); ?></h3>
	<p class="description" style="margin:0 0 .8em;"><?php echo esc_html( $intro ); ?></p>
	<p>
		<label>
			<input type="checkbox" name="ch_tryout_settings[<?php echo esc_attr( $type ); ?>_enabled]" value="1" <?php checked( $tpl['enabled'] ); ?>>
			Send this email
		</label>
	</p>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $subj_id ); ?>">Subject</label></th>
			<td>
				<input class="large-text ch-tryout-tagfield" type="text" id="<?php echo esc_attr( $subj_id ); ?>" name="ch_tryout_settings[<?php echo esc_attr( $type ); ?>_subject]" value="<?php echo esc_attr( $tpl['subject'] ); ?>">
			</td>
		</tr>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $body_id ); ?>">Message body</label></th>
			<td>
				<textarea class="large-text code ch-tryout-tagfield" rows="11" id="<?php echo esc_attr( $body_id ); ?>" name="ch_tryout_settings[<?php echo esc_attr( $type ); ?>_body]"><?php echo esc_textarea( $tpl['body'] ); ?></textarea>
				<p class="description" style="margin:.7em 0 .4em;">Click your cursor into the Subject or Body, then click a tag to insert it. Remove a tag by deleting its text. Basic HTML is allowed in the body.</p>
				<p class="ch-tryout-tags" style="display:flex;flex-wrap:wrap;gap:5px;">
					<?php foreach ( ch_tryout_available_tags() as $tag => $label ) : ?>
						<button type="button" class="button button-small ch-tryout-tag" data-tag="<?php echo esc_attr( $tag ); ?>" data-fallback="<?php echo esc_attr( $body_id ); ?>" title="<?php echo esc_attr( $label ); ?>"><?php echo esc_html( $tag ); ?></button>
					<?php endforeach; ?>
				</p>
			</td>
		</tr>
	</table>
	<?php
}

/**
 * Render the read-only "what's being sent" preview for one email type, using
 * the saved template + sample data. The HTML is shown in a sandboxed iframe so
 * the email's styles can't bleed into the admin screen.
 *
 * @param string $type  'confirm' | 'team'
 * @param string $label Heading.
 * @param string $to    Description of who receives it.
 */
function ch_tryout_render_email_preview( $type, $label, $to ) {
	$tpl   = ch_tryout_get_template( $type );
	$built = ch_tryout_email_build( $type, ch_tryout_sample_data() );
	?>
	<h3 style="margin:1.4em 0 .3em;">
		<?php echo esc_html( $label ); ?>
		<?php if ( ! $tpl['enabled'] ) : ?>
			<span style="color:#dc3232;font-size:12px;font-weight:400;">(disabled — not currently sent)</span>
		<?php endif; ?>
	</h3>
	<p style="margin:0 0 .4em;"><strong>To:</strong> <?php echo esc_html( $to ); ?></p>
	<p style="margin:0 0 .6em;"><strong>Subject:</strong> <?php echo esc_html( $built['subject'] ); ?></p>
	<iframe title="<?php echo esc_attr( $label ); ?> preview" sandbox="" style="width:100%;max-width:660px;height:540px;border:1px solid #dcdcde;border-radius:6px;background:#fff;" srcdoc="<?php echo esc_attr( $built['html'] ); ?>"></iframe>
	<?php
}

/**
 * Inline JS for the mail-tag inserter: clicking a [tag] button drops it at the
 * caret of the last-focused subject/body field (or that group's body as a
 * fallback). Kept inline — it's a few lines and only loads on this screen.
 */
function ch_tryout_render_email_tools_script() {
	?>
	<script>
	( function () {
		var active = null;
		document.querySelectorAll( '.ch-tryout-tagfield' ).forEach( function ( el ) {
			el.addEventListener( 'focus', function () { active = el; } );
		} );
		document.querySelectorAll( '.ch-tryout-tag' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var tag    = btn.getAttribute( 'data-tag' );
				var target = active || document.getElementById( btn.getAttribute( 'data-fallback' ) );
				if ( ! target ) { return; }
				var start = target.selectionStart || 0;
				var end   = target.selectionEnd || 0;
				target.value = target.value.slice( 0, start ) + tag + target.value.slice( end );
				target.focus();
				target.selectionStart = target.selectionEnd = start + tag.length;
				active = target;
			} );
		} );
	} )();
	</script>
	<?php
}

function ch_tryout_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings   = ch_tryout_settings();
	$secret     = ch_tryout_get_secret(); // Generates + stores on first read.
	$configured = ! empty( $settings['web_app_url'] );
	?>
	<div class="wrap">
		<h1>Tryout Registration</h1>
		<?php ch_tryout_admin_notice(); ?>

		<h2 class="title">1. Add the script to your Google Sheet</h2>
		<p>Open the Google Sheet you want registrations in → <strong>Extensions → Apps Script</strong>. Delete any sample code, paste this in, and <strong>Save</strong>:</p>
		<textarea readonly rows="22" class="large-text code" onclick="this.select()"><?php echo esc_textarea( ch_tryout_code_gs( $secret ) ); ?></textarea>
		<p>Then <strong>Deploy → New deployment → Web app</strong>. Set <em>Execute as: Me</em> and <em>Who has access: Anyone</em>. Authorize when prompted, then copy the <strong>Web app URL</strong>.</p>

		<form method="post" action="options.php">
			<?php settings_fields( 'ch_tryout_settings_group' ); ?>
			<h2 class="title">2. Connect it here</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ch_tryout_web_app_url">Web App URL</label></th>
					<td>
						<input name="ch_tryout_settings[web_app_url]" id="ch_tryout_web_app_url" type="url" class="large-text" value="<?php echo esc_attr( $settings['web_app_url'] ); ?>" placeholder="https://script.google.com/macros/s/…/exec">
						<p class="description">The deployment URL from the step above. It ends in <code>/exec</code>.</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="ch_tryout_tab_name">Tab name</label></th>
					<td>
						<input name="ch_tryout_settings[tab_name]" id="ch_tryout_tab_name" type="text" class="regular-text" value="<?php echo esc_attr( $settings['tab_name'] ); ?>">
						<p class="description">The tab to write into — created automatically (with a header row) if it doesn't exist.</p>
					</td>
				</tr>
			</table>

			<h2 class="title">3. Email notifications</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="ch_tryout_notify_email">Team notification email</label></th>
					<td>
						<input name="ch_tryout_settings[notify_email]" id="ch_tryout_notify_email" type="text" class="large-text" value="<?php echo esc_attr( $settings['notify_email'] ); ?>" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
						<p class="description">Where the team's "new registration" alert is sent. Separate multiple addresses with commas. Leave blank to use the site admin email (<code><?php echo esc_html( get_option( 'admin_email' ) ); ?></code>). The registrant's confirmation always goes to the address they enter on the form.</p>
					</td>
				</tr>
			</table>

			<?php
			ch_tryout_render_email_editor(
				'confirm',
				'Confirmation email → the registrant',
				'Sent to the player at the email they entered. The header bar, footer, and styling are added automatically — you control the subject and the body below.'
			);
			ch_tryout_render_email_editor(
				'team',
				'Notification email → the team',
				'Sent to the team address above when someone registers. [admin_button] and [admin_url] only work here (they link into wp-admin).'
			);
			?>

			<?php submit_button( 'Save settings' ); ?>
		</form>

		<?php ch_tryout_render_email_tools_script(); ?>

		<h2 class="title">4. Test the sheet connection</h2>
		<?php if ( $configured ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ch_tryout_test">
				<?php wp_nonce_field( 'ch_tryout_test' ); ?>
				<?php submit_button( 'Send test row', 'secondary', 'submit', false ); ?>
			</form>
		<?php else : ?>
			<p class="description">Save a Web App URL above to enable the test.</p>
		<?php endif; ?>
		<p class="description">The secret is generated once and embedded in the script above, linking this site to your sheet — paste the script a single time and it keeps working. You won't need to touch it again.</p>

		<h2 class="title">5. Preview &amp; test emails</h2>
		<p class="description">These previews use the <strong>saved</strong> templates filled with sample data — save your changes above, then reload to see them here.</p>
		<?php
		ch_tryout_render_email_preview( 'confirm', 'Confirmation email', 'the email each registrant enters on the form' );
		$recipients = ch_tryout_notify_recipients();
		ch_tryout_render_email_preview( 'team', 'Team notification email', $recipients ? implode( ', ', $recipients ) : '(no recipient set)' );
		?>
		<div style="margin-top:1em;">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="ch_tryout_test_email">
				<?php wp_nonce_field( 'ch_tryout_test_email' ); ?>
				<?php submit_button( 'Send both test emails to me', 'secondary', 'submit', false ); ?>
				<span class="description">Delivers to <code><?php echo esc_html( wp_get_current_user()->user_email ); ?></code> using the saved templates.</span>
			</form>
		</div>

		<h2 class="title">Usage</h2>
		<p>Place the shortcode <code>[ch_tryout_form]</code> on any page to display the registration form. That page is automatically excluded from LiteSpeed page cache so the form's security nonce never goes stale.</p>
	</div>
	<?php
}
