<?php
/**
 * Transactional emails for tryout registrations.
 *
 * After a registrant is saved, two HTML emails are sent:
 *   1. Confirmation to the registrant (the address they entered).
 *   2. Notification to the team (Settings → Team notification email,
 *      defaulting to the site admin_email).
 *
 * Both messages are TEMPLATE-DRIVEN, Contact-Form-7 style: the subject and body
 * are editable in Settings and may contain mail-tags like [first_name] or
 * [details_table] that are substituted at send time (see ch_tryout_render_*).
 * The editable body is the inner content; ch_tryout_email_wrap() supplies the
 * branded shell around it.
 *
 * Sending is best-effort: a failed send is logged (when WP_DEBUG) but never
 * blocks the submission or changes what the registrant sees. Called from the
 * submit handler once the DB insert has succeeded.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* ---------------------------------------------------------------------------
 * Templates
 * ------------------------------------------------------------------------- */

/**
 * Built-in default templates. Saved Settings values (when non-empty) override
 * these — see ch_tryout_get_template().
 *
 * @return array<string,array{enabled:bool,subject:string,body:string}>
 */
function ch_tryout_email_templates() {
	return array(
		'confirm' => array(
			'enabled' => true,
			'subject' => 'Tryout registration received - [site_name]',
			'body'    => "Hi [first_name],\n\n"
				. "Thanks for registering for tryouts with [site_name]. We've received your information — a coach will be in touch with the details. No further action is needed right now.\n\n"
				. "Here's what you submitted:\n\n"
				. "[details_table]\n\n"
				. 'If anything above looks wrong, just reply to this email and let us know.',
		),
		'team'    => array(
			'enabled' => true,
			'subject' => 'New tryout registration: [full_name]',
			'body'    => "A new player just registered for tryouts.\n\n"
				. "[details_table]\n\n"
				. '[admin_button]',
		),
	);
}

/**
 * Resolve the effective template for a type, merging saved Settings over the
 * built-in defaults. Blank saved subject/body fall back to the default.
 *
 * @param string $type 'confirm' | 'team'
 * @return array{enabled:bool,subject:string,body:string}
 */
function ch_tryout_get_template( $type ) {
	$defaults = ch_tryout_email_templates();
	$def      = isset( $defaults[ $type ] ) ? $defaults[ $type ] : array( 'enabled' => true, 'subject' => '', 'body' => '' );
	$s        = ch_tryout_settings();

	$enabled_key = $type . '_enabled';
	$subject_key = $type . '_subject';
	$body_key    = $type . '_body';

	return array(
		'enabled' => array_key_exists( $enabled_key, $s ) ? ( '' !== $s[ $enabled_key ] ) : $def['enabled'],
		'subject' => ! empty( $s[ $subject_key ] ) ? $s[ $subject_key ] : $def['subject'],
		'body'    => ! empty( $s[ $body_key ] ) ? $s[ $body_key ] : $def['body'],
	);
}

/* ---------------------------------------------------------------------------
 * Mail-tags
 * ------------------------------------------------------------------------- */

/**
 * Human-readable catalog of every available mail-tag, for the Settings UI.
 *
 * @return array<string,string> '[tag]' => description
 */
function ch_tryout_available_tags() {
	$tags = array();
	foreach ( ch_tryout_fields() as $field ) {
		$tags[ '[' . $field['key'] . ']' ] = $field['label'];
	}
	$tags['[full_name]']     = 'First + last name';
	$tags['[site_name]']     = 'Site / team name';
	$tags['[submitted_at]']  = 'Date & time submitted';
	$tags['[details_table]'] = 'Table of every answer';
	$tags['[admin_button]']  = 'Button linking to the registrants list';
	$tags['[admin_url]']     = 'Plain URL of the registrants list';
	$tags['[home_url]']      = 'Site home URL';
	return $tags;
}

/**
 * Scalar (text) tag => raw value map. Values are escaped at render time for
 * HTML bodies, used as-is for plain-text subjects.
 *
 * @param array $data Clean field values, incl. 'created_at'.
 * @return array<string,string>
 */
function ch_tryout_template_scalar_tags( $data ) {
	$tags = array();
	foreach ( ch_tryout_fields() as $field ) {
		$tags[ '[' . $field['key'] . ']' ] = isset( $data[ $field['key'] ] ) ? (string) $data[ $field['key'] ] : '';
	}
	$first = isset( $data['first_name'] ) ? $data['first_name'] : '';
	$last  = isset( $data['last_name'] ) ? $data['last_name'] : '';

	$tags['[full_name]']    = trim( $first . ' ' . $last );
	$tags['[site_name]']    = get_bloginfo( 'name' );
	$tags['[home_url]']     = home_url();
	$tags['[admin_url]']    = admin_url( 'admin.php?page=ch-tryout-registrants' );
	$tags['[submitted_at]'] = isset( $data['created_at'] ) ? (string) $data['created_at'] : '';
	return $tags;
}

/**
 * Block (HTML) tag => raw HTML map. Injected before paragraph formatting so
 * wpautop() leaves the block markup intact.
 *
 * @param array $data Clean field values.
 * @return array<string,string>
 */
function ch_tryout_template_block_tags( $data ) {
	$admin_url = admin_url( 'admin.php?page=ch-tryout-registrants' );
	return array(
		'[details_table]' => ch_tryout_email_details_table( $data ),
		'[admin_button]'  => '<a href="' . esc_url( $admin_url ) . '" style="display:inline-block;background:#0f1115;color:#ffffff;text-decoration:none;padding:10px 18px;border-radius:6px;font-size:14px;font-weight:600;">View all registrants</a>',
	);
}

/**
 * Render a body template to HTML: inject block tags, run wpautop for line
 * breaks/paragraphs (block elements preserved), then substitute the escaped
 * scalar tags. strtr() replaces simultaneously, so a value can never be
 * re-interpreted as another tag.
 *
 * @param string $template Raw body template.
 * @param array  $data     Clean field values.
 * @return string HTML.
 */
function ch_tryout_render_body( $template, $data ) {
	$out = strtr( (string) $template, ch_tryout_template_block_tags( $data ) );
	$out = wpautop( $out );

	$escaped = array();
	foreach ( ch_tryout_template_scalar_tags( $data ) as $tag => $value ) {
		$escaped[ $tag ] = esc_html( $value );
	}
	return strtr( $out, $escaped );
}

/**
 * Render a subject template to plain text: substitute scalar tags raw, drop any
 * block tags and stray markup.
 *
 * @param string $template Raw subject template.
 * @param array  $data     Clean field values.
 * @return string
 */
function ch_tryout_render_subject( $template, $data ) {
	$out = strtr( (string) $template, ch_tryout_template_scalar_tags( $data ) );
	$out = str_replace( array_keys( ch_tryout_template_block_tags( $data ) ), '', $out );
	return trim( wp_strip_all_tags( $out ) );
}

/**
 * Build the final {subject, html} for one email type and dataset. Shared by the
 * live sender, the on-page preview, and the test-email action.
 *
 * @param string $type 'confirm' | 'team'
 * @param array  $data Clean field values.
 * @return array{subject:string,html:string}
 */
function ch_tryout_email_build( $type, $data ) {
	$tpl       = ch_tryout_get_template( $type );
	$subject   = ch_tryout_render_subject( $tpl['subject'], $data );
	$preheader = 'confirm' === $type ? 'We received your tryout registration.' : 'A new player registered for tryouts.';
	$html      = ch_tryout_email_wrap( $preheader, ch_tryout_render_body( $tpl['body'], $data ) );
	return array(
		'subject' => '' !== $subject ? $subject : get_bloginfo( 'name' ),
		'html'    => $html,
	);
}

/**
 * Sample registrant used for previews and test emails.
 *
 * @return array
 */
function ch_tryout_sample_data() {
	$sample = array( 'created_at' => current_time( 'mysql' ) );
	foreach ( ch_tryout_fields() as $field ) {
		switch ( $field['type'] ) {
			case 'select':
				$sample[ $field['key'] ] = $field['options'][0];
				break;
			case 'email':
				$sample[ $field['key'] ] = 'player@example.com';
				break;
			case 'tel':
				$sample[ $field['key'] ] = '5551234567';
				break;
			case 'date':
				$sample[ $field['key'] ] = '2005-04-12';
				break;
			default:
				$sample[ $field['key'] ] = 'Sample ' . $field['label'];
		}
	}
	$sample['first_name'] = 'Jordan';
	$sample['last_name']  = 'Sample';
	$sample['hometown']   = 'Bozeman, MT';
	$sample['last_team']  = 'Bozeman Icedogs (NA3HL)';
	return $sample;
}

/* ---------------------------------------------------------------------------
 * Sending
 * ------------------------------------------------------------------------- */

/**
 * Send both emails for a saved registrant. Best-effort; never throws.
 *
 * @param array $data Clean field values (key => value), incl. 'created_at'.
 * @param int   $id   Registrant row ID.
 */
function ch_tryout_send_emails( $data, $id ) {
	ch_tryout_send_confirmation_email( $data );
	ch_tryout_send_team_email( $data, $id );
}

/**
 * Resolve the team notification recipients.
 *
 * Uses the comma-separated Settings value; falls back to the site admin email
 * when blank or when none of the configured addresses are valid.
 *
 * @return string[] One or more email addresses.
 */
function ch_tryout_notify_recipients() {
	$settings = ch_tryout_settings();
	$raw      = isset( $settings['notify_email'] ) ? trim( (string) $settings['notify_email'] ) : '';

	if ( '' !== $raw ) {
		$emails = array_filter( array_map( 'trim', explode( ',', $raw ) ), 'is_email' );
		if ( $emails ) {
			return array_values( $emails );
		}
	}

	$admin = get_option( 'admin_email' );
	return is_email( $admin ) ? array( $admin ) : array();
}

/**
 * Confirmation email to the registrant.
 *
 * @param array $data Clean field values.
 */
function ch_tryout_send_confirmation_email( $data ) {
	$tpl = ch_tryout_get_template( 'confirm' );
	if ( ! $tpl['enabled'] ) {
		return;
	}

	$to = isset( $data['email'] ) ? $data['email'] : '';
	if ( ! is_email( $to ) ) {
		return;
	}

	$built = ch_tryout_email_build( 'confirm', $data );

	// Replies go to the team, so the registrant can reach a real inbox.
	$recipients = ch_tryout_notify_recipients();
	$reply_to   = $recipients ? $recipients[0] : '';

	ch_tryout_mail( $to, $built['subject'], $built['html'], $reply_to );
}

/**
 * Notification email to the team.
 *
 * @param array $data Clean field values.
 * @param int   $id   Registrant row ID.
 */
function ch_tryout_send_team_email( $data, $id ) {
	$tpl = ch_tryout_get_template( 'team' );
	if ( ! $tpl['enabled'] ) {
		return;
	}

	$recipients = ch_tryout_notify_recipients();
	if ( empty( $recipients ) ) {
		return;
	}

	$built = ch_tryout_email_build( 'team', $data );

	// Reply goes straight to the player.
	$reply_to = isset( $data['email'] ) && is_email( $data['email'] ) ? $data['email'] : '';

	ch_tryout_mail( $recipients, $built['subject'], $built['html'], $reply_to );
}

/* ---------------------------------------------------------------------------
 * HTML shell + low-level send
 * ------------------------------------------------------------------------- */

/**
 * Build the two-column "label / value" details table used by the
 * [details_table] tag. Skips empty values; mirrors ch_tryout_fields() order.
 *
 * @param array $data Clean field values.
 * @return string HTML table.
 */
function ch_tryout_email_details_table( $data ) {
	$rows = '';
	$i    = 0;
	foreach ( ch_tryout_fields() as $field ) {
		$val = isset( $data[ $field['key'] ] ) ? trim( (string) $data[ $field['key'] ] ) : '';
		if ( '' === $val ) {
			continue;
		}
		$bg    = ( $i % 2 ) ? '#ffffff' : '#f6f7f9';
		$rows .= '<tr>'
			. '<td style="padding:8px 12px;background:' . $bg . ';border:1px solid #e3e6ea;font-weight:600;color:#1a1d21;width:42%;">' . esc_html( $field['label'] ) . '</td>'
			. '<td style="padding:8px 12px;background:' . $bg . ';border:1px solid #e3e6ea;color:#1a1d21;">' . esc_html( $val ) . '</td>'
			. '</tr>';
		$i++;
	}
	return '<table role="presentation" cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;font-size:14px;">' . $rows . '</table>';
}

/**
 * Wrap body HTML in a branded, email-client-safe shell (inline styles, 600px
 * centered card, dark header bar with the site name).
 *
 * @param string $preheader Hidden inbox preview text.
 * @param string $body_html Inner content.
 * @return string Full HTML document.
 */
function ch_tryout_email_wrap( $preheader, $body_html ) {
	$site = esc_html( get_bloginfo( 'name' ) );
	$home = esc_html( home_url() );

	return '<!doctype html><html><body style="margin:0;padding:0;background:#eef0f2;">'
		. '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html( $preheader ) . '</div>'
		. '<table role="presentation" cellpadding="0" cellspacing="0" width="100%" style="background:#eef0f2;padding:24px 0;">'
		. '<tr><td align="center">'
		. '<table role="presentation" cellpadding="0" cellspacing="0" width="600" style="max-width:600px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;">'
		. '<tr><td style="background:#0f1115;padding:20px 28px;"><span style="color:#ffffff;font-size:18px;font-weight:700;letter-spacing:.5px;">' . $site . '</span></td></tr>'
		. '<tr><td style="padding:28px;color:#1a1d21;font-size:15px;line-height:1.6;">' . $body_html . '</td></tr>'
		. '<tr><td style="padding:16px 28px;background:#f6f7f9;border-top:1px solid #e3e6ea;color:#6b7077;font-size:12px;line-height:1.5;">Sent automatically by the tryout registration form at ' . $home . '.</td></tr>'
		. '</table>'
		. '</td></tr></table></body></html>';
}

/**
 * Send one HTML email with the site name as the From name. The From address is
 * left at WordPress's default (wordpress@<site-domain>) so it stays on-domain
 * and passes SPF on shared hosting; only the display name is overridden.
 *
 * @param string|string[] $to       Recipient(s).
 * @param string          $subject  Subject line.
 * @param string          $html     HTML body.
 * @param string          $reply_to Optional Reply-To address.
 * @return bool Whether wp_mail accepted the message for delivery.
 */
function ch_tryout_mail( $to, $subject, $html, $reply_to = '' ) {
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	if ( $reply_to && is_email( $reply_to ) ) {
		$headers[] = 'Reply-To: ' . $reply_to;
	}

	$from_name = get_bloginfo( 'name' );
	$set_name  = static function () use ( $from_name ) {
		return $from_name;
	};

	if ( '' !== $from_name ) {
		add_filter( 'wp_mail_from_name', $set_name );
	}
	$sent = wp_mail( $to, $subject, $html, $headers );
	if ( '' !== $from_name ) {
		remove_filter( 'wp_mail_from_name', $set_name );
	}

	if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		error_log( 'CH Tryout: email send failed to ' . ( is_array( $to ) ? implode( ', ', $to ) : $to ) );
	}

	return $sent;
}
