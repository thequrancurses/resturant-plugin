<?php
/**
 * Notifications: inbox + queue, email, WhatsApp webhook, Web Push (VAPID, RFC 8291).
 */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Templates
 * ---------------------------------------------------------------------- */

/**
 * Fill a message template. Placeholders: {name} {store} + custom $vars.
 */
function ls_tpl( $key, $vars = array(), $customer_id = 0 ) {
	$tpl  = (string) ls_setting( $key, '' );
	$name = __( 'friend', 'loyalty-stamps' );
	if ( $customer_id ) {
		$c = ls_get_customer( (int) $customer_id );
		if ( $c && '' !== trim( $c->name ) ) {
			$name = $c->name;
		}
	}
	$replace = array_merge(
		array(
			'name'  => $name,
			'store' => ls_setting( 'business_name', 'Grill Hub' ),
		),
		$vars
	);
	foreach ( $replace as $k => $v ) {
		$tpl = str_replace( '{' . $k . '}', (string) $v, $tpl );
	}
	return $tpl;
}

/* -------------------------------------------------------------------------
 * Queueing
 * ---------------------------------------------------------------------- */

/**
 * Create an inbox entry immediately and queue email/push/whatsapp
 * according to global settings + customer preferences.
 */
function ls_notify( $customer_id, $kind, $title, $message, $payload = array() ) {
	global $wpdb;
	$p           = $wpdb->prefix;
	$customer_id = (int) $customer_id;
	$customer    = $customer_id ? ls_get_customer( $customer_id ) : null;
	$now         = ls_now();
	$payload_js  = $payload ? wp_json_encode( $payload ) : null;

	if ( ls_setting( 'enable_inbox', 1 ) ) {
		$wpdb->insert(
			"{$p}ls_notifications",
			array(
				'customer_id' => $customer_id ?: null,
				'channel'     => 'inbox',
				'kind'        => $kind,
				'title'       => $title,
				'message'     => $message,
				'payload'     => $payload_js,
				'status'      => 'sent',
				'sent_at'     => $now,
				'created_at'  => $now,
			)
		);
	}

	if ( ! $customer ) {
		return;
	}

	$channels = array();
	if ( ls_setting( 'enable_email', 1 ) && $customer->notify_email && $customer->email ) {
		$channels[] = 'email';
	}
	if ( ls_setting( 'enable_push', 1 ) && $customer->notify_push ) {
		$channels[] = 'push';
	}
	if ( ls_setting( 'enable_whatsapp', 0 ) && $customer->notify_whatsapp && $customer->phone ) {
		$channels[] = 'whatsapp';
	}

	foreach ( $channels as $ch ) {
		$wpdb->insert(
			"{$p}ls_notifications",
			array(
				'customer_id' => $customer_id,
				'channel'     => $ch,
				'kind'        => $kind,
				'title'       => $title,
				'message'     => $message,
				'payload'     => $payload_js,
				'status'      => 'queued',
				'created_at'  => $now,
			)
		);
	}
}

/** Queue a notification for "nearby" promos with a cooldown check built in. */
function ls_queue_nearby( $customer_id, $store ) {
	global $wpdb;
	$p           = $wpdb->prefix;
	$customer_id = (int) $customer_id;

	$cooldown = max( 1, (int) ls_setting( 'geofence_cooldown_h', 24 ) );
	$since    = date( 'Y-m-d H:i:s', strtotime( "-{$cooldown} hours", current_time( 'timestamp' ) ) );
	$already  = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$p}ls_notifications
			 WHERE customer_id = %d AND kind = 'nearby' AND created_at >= %s
			 AND (status = 'sent' OR status = 'queued')",
			$customer_id,
			$since
		)
	);
	if ( $already > 0 ) {
		return false; // cooldown: already notified recently
	}

	$message = $store->promo_message
		? $store->promo_message
		: ls_tpl( 'tpl_nearby', array( 'store' => $store->name ), $customer_id );

	ls_notify(
		$customer_id,
		'nearby',
		sprintf( __( 'Near %s', 'loyalty-stamps' ), $store->name ),
		$message,
		array( 'store_id' => (int) $store->id )
	);
	return true;
}

/** Number of unread inbox notifications. */
function ls_unread_count( $customer_id ) {
	global $wpdb;
	// Inbox entries are stored as sent; "read" tracking is per-session on the card page.
	// We count inbox messages from the last 30 days as a simple badge.
	$since = date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) );
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->prefix}ls_notifications
			 WHERE customer_id = %d AND channel = 'inbox' AND created_at >= %s",
			(int) $customer_id,
			$since
		)
	);
}

/**
 * Get inbox notifications for a customer.
 *
 * @param int $customer_id Customer ID.
 * @param int $limit       Maximum number of entries (default 30).
 * @return array List of notification rows, newest first.
 */
function ls_get_inbox( $customer_id, $limit = 30 ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$wpdb->prefix}ls_notifications
			 WHERE customer_id = %d AND channel = 'inbox'
			 ORDER BY id DESC LIMIT %d",
			(int) $customer_id,
			$limit
		)
	);
}

/* -------------------------------------------------------------------------
 * Queue processor (cron)
 * ---------------------------------------------------------------------- */

/**
 * Process queued notifications (email, WhatsApp, push).
 *
 * Sends due entries, marks them sent, and fails them after 3 attempts.
 *
 * @param int $limit Maximum number of entries to process (default 50).
 * @return int Number of entries processed.
 */
function ls_process_queue( $limit = 50 ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$p}ls_notifications
			 WHERE status = 'queued' AND (scheduled_at IS NULL OR scheduled_at <= %s)
			 ORDER BY id ASC LIMIT %d",
			ls_now(),
			$limit
		)
	);
	foreach ( $rows as $n ) {
		$wpdb->update(
			"{$p}ls_notifications",
			array( 'attempts' => (int) $n->attempts + 1 ),
			array( 'id' => $n->id )
		);
		$customer = $n->customer_id ? ls_get_customer( (int) $n->customer_id ) : null;
		$result   = false;
		$error    = '';

		switch ( $n->channel ) {
			case 'email':
				$result = ls_send_email_notification( $n, $customer );
				break;
			case 'whatsapp':
				$result = ls_send_whatsapp_notification( $n, $customer );
				break;
			case 'push':
				$result = ls_send_push_notification( $n, $customer );
				break;
			default:
				$error = 'unknown channel';
		}
		if ( true === $result ) {
			$wpdb->update(
				"{$p}ls_notifications",
				array( 'status' => 'sent', 'sent_at' => ls_now(), 'error' => null ),
				array( 'id' => $n->id )
			);
		} else {
			$error = is_string( $result ) && '' !== $result ? $result : $error;
			$wpdb->update(
				"{$p}ls_notifications",
				array(
					'status' => ( (int) $n->attempts + 1 ) >= 3 ? 'failed' : 'queued',
					'error'  => substr( (string) $error, 0, 500 ),
				),
				array( 'id' => $n->id )
			);
		}
	}
	return count( $rows );
}

/**
 * Send a single email notification via wp_mail().
 *
 * @param object $n        Notification row.
 * @param object $customer Customer row.
 * @return true|string True on success, error message on failure.
 */
function ls_send_email_notification( $n, $customer ) {
	if ( ! $customer || ! $customer->email ) {
		return 'no email address';
	}
	$subject = '[' . ls_setting( 'business_name', 'Grill Hub' ) . '] ' . $n->title;
	$headers = array( 'Content-Type: text/html; charset=UTF-8' );
	return wp_mail( $customer->email, $subject, ls_email_html( $n, $customer ), $headers ) ? true : 'wp_mail failed';
}

/**
 * Modern e-mail: brand header, message, big stamp counter, stamp dots, "N left", personal QR code, buttons.
 * Table based + inline CSS so it looks the same in Gmail / Apple Mail / Outlook. Language = the customer's language.
 */
function ls_email_html( $n, $customer ) {
	$L = array(
		'pl' => array( 'hi' => 'Cześć', 'of' => 'pieczątek', 'left' => 'Jeszcze %d do nagrody', 'done' => 'Nagroda czeka na Ciebie!', 'qr' => 'Twój kod – pokaż go przy kasie', 'open' => 'Otwórz moją kartę', 'wallet' => 'Dodaj do portfela', 'foot' => 'Dostajesz tę wiadomość, bo jesteś w naszym programie lojalnościowym.', 'dir' => 'ltr' ),
		'en' => array( 'hi' => 'Hi', 'of' => 'stamps', 'left' => '%d more to your reward', 'done' => 'Your reward is waiting!', 'qr' => 'Your code - show it at the till', 'open' => 'Open my card', 'wallet' => 'Add to wallet', 'foot' => 'You receive this message because you are in our loyalty programme.', 'dir' => 'ltr' ),
		'ar' => array( 'hi' => 'أهلاً', 'of' => 'ختم', 'left' => 'باقي %d للمكافأة', 'done' => 'مكافأتك في انتظارك!', 'qr' => 'كودك – اعرضه عند الكاشير', 'open' => 'افتح كارتي', 'wallet' => 'أضف إلى المحفظة', 'foot' => 'وصلتك الرسالة لأنك مشترك في برنامج الولاء.', 'dir' => 'rtl' ),
	);
	$lang  = isset( $L[ $customer->lang ] ) ? $customer->lang : ( function_exists( 'ls_default_lang' ) ? ls_default_lang() : 'pl' );
	$t     = $L[ $lang ];
	$brand = (string) ls_setting( 'business_name', 'Grill Hub' );
	$logo  = function_exists( 'ls_logo_url' ) ? ls_logo_url() : '';
	$state = function_exists( 'ls_wallet_card_state' ) ? ls_wallet_card_state( (int) $customer->id ) : null;
	$card_url = function_exists( 'ls_card_url' ) ? ls_card_url( $customer->token ) : home_url( '/' );
	$qr    = str_replace( '{data}', rawurlencode( $card_url ), 'https://api.qrserver.com/v1/create-qr-code/?size=360x360&margin=8&data={data}' );
	$qr    = apply_filters( 'ls_email_qr_url', $qr, $card_url );
	$color = '#e11e26';
	$stamps = 0; $req = 0; $reward = '';
	if ( $state ) {
		$stamps = (int) $state['card']->stamps; $req = max( 1, (int) $state['program']->stamps_required ); $reward = ls_program_reward_title( $state['program'], $lang );
		if ( ! empty( $state['program']->color ) ) {
			$color = $state['program']->color;
		}
	}
	$dots = '';
	if ( $req > 0 ) {
		for ( $i = 0; $i < min( $req, 20 ); $i++ ) {
			$on    = $i < $stamps;
			$dots .= '<td style="padding:3px"><div style="width:26px;height:26px;line-height:26px;border-radius:13px;text-align:center;font-size:13px;font-weight:800;' . ( $on ? 'background:#ffffff;color:' . esc_attr( $color ) . ';' : 'background:rgba(255,255,255,.15);color:rgba(255,255,255,.7);border:2px dashed rgba(255,255,255,.55);line-height:22px;' ) . '">' . ( $on ? '&#9733;' : ( $i + 1 ) ) . '</div></td>';
			if ( 9 === $i % 10 && $i + 1 < min( $req, 20 ) ) {
				$dots .= '</tr><tr>';
			}
		}
	}
	$left = max( 0, $req - $stamps );
	$msg  = nl2br( esc_html( $n->message ) );
	ob_start();
	?>
<!doctype html><html lang="<?php echo esc_attr( $lang ); ?>"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="color-scheme" content="light"></head>
<body style="margin:0;padding:0;background:#f6f4f1;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f4f1;"><tr><td align="center" style="padding:20px 12px;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" dir="<?php echo esc_attr( $t['dir'] ); ?>" style="max-width:520px;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#141416;">
	<tr><td style="padding:6px 6px 16px;text-align:center;">
		<?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" alt="" height="40" style="height:40px;vertical-align:middle;border:0;"> <?php endif; ?>
		<span style="font-size:20px;font-weight:800;letter-spacing:.04em;vertical-align:middle;text-transform:uppercase;"><?php echo esc_html( $brand ); ?></span>
	</td></tr>
	<tr><td style="background:#ffffff;border-radius:24px;padding:26px 24px;box-shadow:0 6px 24px rgba(20,20,22,.08);">
		<p style="margin:0 0 4px;color:#6f6a64;font-size:14px;"><?php echo esc_html( $t['hi'] . ( $customer->name ? ', ' . $customer->name : '' ) ); ?> &#128075;</p>
		<h1 style="margin:0 0 10px;font-size:24px;line-height:1.2;"><?php echo esc_html( $n->title ); ?></h1>
		<p style="margin:0 0 20px;font-size:15px;line-height:1.5;color:#3d3a37;"><?php echo $msg; // phpcs:ignore ?></p>
		<?php if ( $state ) : ?>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-radius:22px;background:<?php echo esc_attr( $color ); ?>;background-image:linear-gradient(155deg,<?php echo esc_attr( $color ); ?>,#7c0c10);color:#ffffff;"><tr><td style="padding:22px;">
			<div style="font-size:13px;font-weight:700;opacity:.9;"><?php echo esc_html( $state['program']->name ); ?></div>
			<div style="margin:6px 0 2px;direction:ltr;text-align:<?php echo 'rtl' === $t['dir'] ? 'right' : 'left'; ?>;"><span style="font-size:56px;font-weight:800;line-height:1;"><?php echo (int) $stamps; ?></span> <span style="font-size:16px;opacity:.85;">/ <?php echo (int) $req . ' ' . esc_html( $t['of'] ); ?></span></div>
			<div style="font-size:14px;margin-bottom:14px;"><?php echo esc_html( $left > 0 ? sprintf( $t['left'], $left ) : $t['done'] ); ?><?php echo $reward ? ' &middot; <b>' . esc_html( $reward ) . '</b>' : ''; ?></div>
			<table role="presentation" cellpadding="0" cellspacing="0" dir="ltr"><tr><?php echo $dots; // phpcs:ignore ?></tr></table>
		</td></tr></table>
		<?php endif; ?>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;"><tr><td align="center" style="padding:16px;border:1px dashed #d9d3ca;border-radius:20px;">
			<img src="<?php echo esc_url( $qr ); ?>" width="170" height="170" alt="QR" style="display:block;border:0;border-radius:12px;">
			<div style="font-size:12px;color:#6f6a64;margin-top:8px;"><?php echo esc_html( $t['qr'] ); ?></div>
		</td></tr></table>
		<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:22px;"><tr><td align="center">
			<a href="<?php echo esc_url( $card_url ); ?>" style="display:inline-block;background:#e11e26;color:#ffffff;text-decoration:none;font-weight:800;font-size:16px;padding:14px 28px;border-radius:999px;"><?php echo esc_html( $t['open'] ); ?></a>
		</td></tr></table>
	</td></tr>
	<tr><td style="padding:16px 10px;text-align:center;font-size:12px;color:#8a847c;"><?php echo esc_html( $t['foot'] ); ?><br><?php echo esc_html( $brand ); ?></td></tr>
</table></td></tr></table></body></html>
	<?php
	return ob_get_clean();
}

/**
 * Send a single WhatsApp notification via the configured webhook.
 *
 * @param object $n        Notification row.
 * @param object $customer Customer row.
 * @return true|string True on success, error message on failure.
 */
function ls_send_whatsapp_notification( $n, $customer ) {
	if ( ! $customer || ! $customer->phone ) {
		return 'no phone number';
	}
	$url = trim( (string) ls_setting( 'whatsapp_webhook', '' ) );
	if ( '' === $url ) {
		return 'whatsapp webhook not configured';
	}
	$text    = $n->title . "\n" . $n->message;
	$replace = array(
		'{phone}'   => $customer->phone,
		'{message}' => $text,
		'{title}'   => $n->title,
		'{name}'    => $customer->name,
	);
	$url = str_replace( array_keys( $replace ), array_values( $replace ), $url );

	if ( 'POST' === strtoupper( (string) ls_setting( 'whatsapp_method', 'GET' ) ) ) {
		$res = wp_remote_post(
			str_replace( array( '{phone}', '{message}', '{title}', '{name}' ), '', $url ),
			array(
				'timeout' => 15,
				'body'    => array(
					'phone'   => $customer->phone,
					'message' => $text,
				),
			)
		);
	} else {
		$res = wp_remote_get( $url, array( 'timeout' => 15 ) );
	}
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	return ( $code >= 200 && $code < 300 ) ? true : 'http ' . $code;
}

/**
 * Send a single Web Push notification to all of the customer's subscriptions.
 *
 * @param object $n        Notification row.
 * @param object $customer Customer row.
 * @return true|string True on success, error message on failure.
 */
function ls_send_push_notification( $n, $customer ) {
	if ( ! $customer ) {
		return 'no customer';
	}
	$subs = ls_get_push_subs( (int) $customer->id );
	if ( ! $subs ) {
		return 'no push subscription';
	}
	$url   = home_url( '/' );
	$ok    = false;
	$errors = array();
	foreach ( $subs as $sub ) {
		$r = ls_webpush_send(
			array(
				'endpoint' => $sub->endpoint,
				'p256dh'   => $sub->p256dh,
				'auth'     => $sub->auth,
			),
			$n->title,
			$n->message,
			$url
		);
		if ( true === $r ) {
			$ok = true;
		} elseif ( 'subscription gone' === $r ) {
			ls_delete_push_sub( (int) $sub->id );
		} else {
			$errors[] = $r;
		}
	}
	if ( $ok ) {
		return true;
	}
	return $errors ? implode( '; ', array_unique( $errors ) ) : 'push failed';
}

/* -------------------------------------------------------------------------
 * Push subscriptions
 * ---------------------------------------------------------------------- */

/**
 * Save (or replace) a Web Push subscription for a customer.
 *
 * @param int   $customer_id Customer ID.
 * @param array $sub         Subscription data (endpoint + keys).
 * @return bool True on success.
 */
function ls_save_push_sub( $customer_id, $sub ) {
	global $wpdb;
	$p = $wpdb->prefix;
	if ( empty( $sub['endpoint'] ) || empty( $sub['keys']['p256dh'] ) || empty( $sub['keys']['auth'] ) ) {
		return false;
	}
	$hash = hash( 'sha256', $sub['endpoint'] );
	$wpdb->replace(
		"{$p}ls_push_subs",
		array(
			'customer_id'   => (int) $customer_id,
			'endpoint'      => $sub['endpoint'],
			'endpoint_hash' => $hash,
			'p256dh'        => $sub['keys']['p256dh'],
			'auth'          => $sub['keys']['auth'],
			'created_at'    => ls_now(),
		)
	);
	return true;
}

/**
 * Get all Web Push subscriptions of a customer.
 *
 * @param int $customer_id Customer ID.
 * @return array List of subscription rows.
 */
function ls_get_push_subs( $customer_id ) {
	global $wpdb;
	return $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_push_subs WHERE customer_id = %d", (int) $customer_id )
	);
}

/**
 * Delete a Web Push subscription.
 *
 * @param int $id Subscription ID.
 */
function ls_delete_push_sub( $id ) {
	global $wpdb;
	$wpdb->delete( "{$wpdb->prefix}ls_push_subs", array( 'id' => (int) $id ) );
}

/* -------------------------------------------------------------------------
 * Web Push crypto (pure PHP, no dependencies)
 * ---------------------------------------------------------------------- */

/**
 * Base64url-encode binary data (no padding).
 *
 * @param string $data Raw data.
 * @return string Base64url-encoded string.
 */
function ls_b64url_encode( $data ) {
	return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
}

/**
 * Base64url-decode a string.
 *
 * @param string $data Base64url-encoded string.
 * @return string|false Decoded data or false on failure.
 */
function ls_b64url_decode( $data ) {
	$data = strtr( (string) $data, '-_', '+/' );
	$pad  = strlen( $data ) % 4;
	if ( $pad ) {
		$data .= str_repeat( '=', 4 - $pad );
	}
	return base64_decode( $data, true );
}

/** Generate and store a VAPID keypair. Returns array(public, private) or WP_Error. */
function ls_vapid_generate() {
	$res = openssl_pkey_new(
		array(
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		)
	);
	if ( ! $res ) {
		return new WP_Error( 'ls_openssl', __( 'OpenSSL could not generate an EC key.', 'loyalty-stamps' ) );
	}
	openssl_pkey_export( $res, $pem );
	$d   = openssl_pkey_get_details( $res );
	$pub = "\x04"
		. str_pad( $d['ec']['x'], 32, "\x00", STR_PAD_LEFT )
		. str_pad( $d['ec']['y'], 32, "\x00", STR_PAD_LEFT );
	$keys = array(
		'public'  => ls_b64url_encode( $pub ),
		'private' => $pem,
	);
	ls_update_settings(
		array(
			'vapid_public'  => $keys['public'],
			'vapid_private' => $pem,
		)
	);
	update_option( 'ghm_vapid', array( 'priv' => $pem, 'pub' => $keys['public'] ), false ); // one key pair for the whole plugin (order push + loyalty push)
	return $keys;
}

/** Build a VAPID JWT for the given audience (push service origin). */
function ls_vapid_jwt( $aud ) {
	$priv_pem = (string) ls_setting( 'vapid_private', '' );
	if ( '' === $priv_pem ) {
		return new WP_Error( 'ls_no_vapid', __( 'VAPID keys are not configured.', 'loyalty-stamps' ) );
	}
	$key = openssl_pkey_get_private( $priv_pem );
	if ( ! $key ) {
		return new WP_Error( 'ls_bad_vapid', __( 'Invalid VAPID private key.', 'loyalty-stamps' ) );
	}
	$header = ls_b64url_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
	$claims = ls_b64url_encode(
		wp_json_encode(
			array(
				'aud' => $aud,
				'exp' => time() + 43200,
				'sub' => ls_setting( 'vapid_subject', 'mailto:admin@example.com' ),
			)
		)
	);
	$input = $header . '.' . $claims;
	if ( ! openssl_sign( $input, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'ls_sign', __( 'Could not sign VAPID token.', 'loyalty-stamps' ) );
	}
	$raw = ls_der_to_raw_sig( $der );
	if ( ! $raw ) {
		return new WP_Error( 'ls_sig', __( 'Could not parse signature.', 'loyalty-stamps' ) );
	}
	return $input . '.' . ls_b64url_encode( $raw );
}

/** Convert DER ECDSA signature to raw r||s (64 bytes). */
function ls_der_to_raw_sig( $der ) {
	$p = 0;
	$n = strlen( $der );
	if ( $n < 8 || ord( $der[ $p++ ] ) !== 0x30 ) {
		return false;
	}
	$len = ord( $der[ $p++ ] );
	if ( $len & 0x80 ) {
		$c = $len & 0x7f;
		if ( $c > 2 || $p + $c > $n ) {
			return false;
		}
		$len = 0;
		for ( $i = 0; $i < $c; $i++ ) {
			$len = ( $len << 8 ) | ord( $der[ $p++ ] );
		}
	}
	if ( $p >= $n || ord( $der[ $p++ ] ) !== 0x02 ) {
		return false;
	}
	$rl = ord( $der[ $p++ ] );
	$r  = substr( $der, $p, $rl );
	$p += $rl;
	if ( $p >= $n || ord( $der[ $p++ ] ) !== 0x02 ) {
		return false;
	}
	$sl = ord( $der[ $p++ ] );
	$s  = substr( $der, $p, $sl );

	$r = str_pad( ltrim( $r, "\x00" ), 32, "\x00", STR_PAD_LEFT );
	$s = str_pad( ltrim( $s, "\x00" ), 32, "\x00", STR_PAD_LEFT );
	if ( strlen( $r ) !== 32 || strlen( $s ) !== 32 ) {
		return false;
	}
	return $r . $s;
}

/**
 * Send a Web Push message (RFC 8291, aes128gcm).
 * $sub: array(endpoint, p256dh base64url, auth base64url).
 * Returns true or an error string ('subscription gone' means delete the sub).
 */
function ls_webpush_send( $sub, $title, $body, $url = '' ) {
	$endpoint = $sub['endpoint'];
	$p256dh   = ls_b64url_decode( $sub['p256dh'] );
	$auth     = ls_b64url_decode( $sub['auth'] );

	if ( ! $p256dh || strlen( $p256dh ) !== 65 || ! $auth || strlen( $auth ) < 16 ) {
		return 'invalid subscription keys';
	}

	// Ephemeral EC key for this message.
	$local = openssl_pkey_new(
		array(
			'private_key_type' => OPENSSL_KEYTYPE_EC,
			'curve_name'       => 'prime256v1',
		)
	);
	if ( ! $local ) {
		return 'openssl ec failed';
	}
	$ld        = openssl_pkey_get_details( $local );
	$local_pub = "\x04"
		. str_pad( $ld['ec']['x'], 32, "\x00", STR_PAD_LEFT )
		. str_pad( $ld['ec']['y'], 32, "\x00", STR_PAD_LEFT );

	// Import client public key (SPKI DER has a fixed prefix for P-256).
	$der  = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . $p256dh;
	$pem  = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
	$peer = openssl_pkey_get_public( $pem );
	if ( ! $peer ) {
		return 'peer key import failed';
	}
	$shared = openssl_pkey_derive( $peer, $local );
	if ( false === $shared ) {
		return 'ecdh failed';
	}

	// RFC 8291 key derivation.
	$prk   = hash_hkdf( 'sha256', $shared, 32, '', $auth );
	$ikm   = hash_hkdf( 'sha256', $prk, 32, "WebPush: info\x00" . $p256dh . $local_pub, '' );
	$salt  = random_bytes( 16 );
	$prk2  = hash_hkdf( 'sha256', $ikm, 32, '', $salt );
	$cek   = hash_hkdf( 'sha256', $prk2, 16, "Content-Encoding: aes128gcm\x00", '' );
	$nonce = hash_hkdf( 'sha256', $prk2, 12, "Content-Encoding: nonce\x00", '' );

	$payload   = wp_json_encode(
		array(
			'title' => $title,
			'body'  => wp_strip_all_tags( $body ),
			'url'   => $url,
		),
		JSON_UNESCAPED_UNICODE
	);
	$ct = openssl_encrypt( $payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
	if ( false === $ct ) {
		return 'encrypt failed';
	}
	$record = $salt . pack( 'N', strlen( $ct ) + 16 ) . chr( 65 ) . $local_pub . $ct . $tag;

	$parts = parse_url( $endpoint );
	if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
		return 'bad endpoint';
	}
	$aud = $parts['scheme'] . '://' . $parts['host'];
	$jwt = ls_vapid_jwt( $aud );
	if ( is_wp_error( $jwt ) ) {
		return $jwt->get_error_message();
	}

	$res = wp_remote_post(
		$endpoint,
		array(
			'timeout' => 15,
			'headers' => array(
				'TTL'              => '86400',
				'Content-Type'     => 'application/octet-stream',
				'Content-Encoding' => 'aes128gcm',
				'Encryption'       => 'salt=' . ls_b64url_encode( $salt ),
				'Crypto-Key'       => 'dh=' . ls_b64url_encode( $local_pub ) . ';p256ecdsa=' . ls_setting( 'vapid_public', '' ),
				'Authorization'    => 'WebPush ' . $jwt,
			),
			'body'    => $record,
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res->get_error_message();
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 404 === $code || 410 === $code ) {
		return 'subscription gone';
	}
	if ( $code < 200 || $code >= 300 ) {
		return 'push http ' . $code;
	}
	return true;
}
