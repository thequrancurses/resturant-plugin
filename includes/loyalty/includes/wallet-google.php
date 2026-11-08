<?php
/**
 * Google Wallet: "Save to Google Wallet" links (signed JWT) + object updates.
 *
 * Requires (admin uploads in Loyalty > Wallet):
 *  - Google Cloud service account JSON with Wallet API access
 *  - Wallet Issuer ID
 * Setup guide is on the Wallet admin page.
 */
defined( 'ABSPATH' ) || exit;

/**
 * Resolve the service account JSON path: saved setting first,
 * then a manually FTP-uploaded wp-content/uploads/ls-wallet/google-sa.json
 * (bypasses firewalls that block private keys in POST bodies).
 */
function ls_google_sa_path() {
	$f = ls_setting( 'google_sa_json', '' );
	if ( '' !== $f && file_exists( $f ) ) {
		return $f;
	}
	$manual = ls_wallet_dir() . '/google-sa.json';
	if ( file_exists( $manual ) ) {
		return $manual;
	}
	return '';
}

/** Is Google Wallet fully configured? Returns true or a reason string. */
function ls_google_configured() {
	if ( ! ls_setting( 'wallet_google_enabled', 0 ) ) {
		return __( 'Google Wallet is disabled.', 'loyalty-stamps' );
	}
	if ( '' === trim( (string) ls_setting( 'google_issuer_id', '' ) ) ) {
		return __( 'Issuer ID missing.', 'loyalty-stamps' );
	}
	if ( '' === ls_google_sa_path() ) {
		return __( 'Service account JSON not uploaded.', 'loyalty-stamps' );
	}
	return true;
}

/** Get the Google Wallet issuer ID from settings. @return string Issuer ID. */
function ls_google_issuer_id() {
	return trim( (string) ls_setting( 'google_issuer_id', '' ) );
}

/** Get the Google Wallet loyalty class ID. @return string Class ID. */
function ls_google_class_id() {
	return ls_google_issuer_id() . '.ls_loyalty';
}

/**
 * Get the Google Wallet loyalty object ID for a customer.
 *
 * @param int $customer_id Customer ID.
 * @return string Object ID.
 */
function ls_google_object_id( $customer_id ) {
	return ls_google_issuer_id() . '.ls_' . (int) $customer_id;
}

/**
 * Load the Google service account credentials from the stored JSON file.
 *
 * @return array|null Service account data or null when unavailable/invalid.
 */
function ls_google_sa() {
	$f = ls_google_sa_path();
	if ( '' === $f ) {
		return null;
	}
	$sa = json_decode( @file_get_contents( $f ), true );
	if ( ! is_array( $sa ) || empty( $sa['client_email'] ) || empty( $sa['private_key'] ) ) {
		return null;
	}
	return $sa;
}

/** OAuth2 access token for the Wallet API (cached ~55 min). Returns token or WP_Error. */
function ls_google_access_token() {
	$cached = get_transient( 'ls_google_oauth' );
	if ( $cached ) {
		return $cached;
	}
	$sa = ls_google_sa();
	if ( ! $sa ) {
		return new WP_Error( 'ls_sa', __( 'Invalid service account.', 'loyalty-stamps' ) );
	}
	$now    = time();
	$header = ls_b64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = ls_b64url_encode(
		wp_json_encode(
			array(
				'iss'   => $sa['client_email'],
				'scope' => 'https://www.googleapis.com/auth/wallet_object.issuer',
				'aud'   => 'https://oauth2.googleapis.com/token',
				'iat'   => $now,
				'exp'   => $now + 3600,
			)
		)
	);
	$key = openssl_pkey_get_private( $sa['private_key'] );
	if ( ! $key || ! openssl_sign( $header . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'ls_sign', __( 'Could not sign Google auth token.', 'loyalty-stamps' ) );
	}
	$res = wp_remote_post(
		'https://oauth2.googleapis.com/token',
		array(
			'timeout' => 15,
			'body'    => array(
				'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
				'assertion'  => $header . '.' . $claims . '.' . ls_b64url_encode( $sig ),
			),
		)
	);
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['access_token'] ) ) {
		return new WP_Error( 'ls_oauth', __( 'Google authentication failed.', 'loyalty-stamps' ) );
	}
	set_transient( 'ls_google_oauth', $data['access_token'], 55 * MINUTE_IN_SECONDS );
	return $data['access_token'];
}

/** Ensure the loyalty class exists. Returns true or WP_Error. */
function ls_google_ensure_class() {
	$token = ls_google_access_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$business = ls_setting( 'business_name', 'Grill Hub' );
	$body     = array(
		'id'                => ls_google_class_id(),
		'issuerName'        => $business,
		'programName'       => 'pl' === ls_default_lang() ? 'Karta lojalnościowa' : ( 'ar' === ls_default_lang() ? 'بطاقة الولاء' : 'Loyalty card' ),
		'programLogo'       => array(
			'sourceUri' => array( 'uri' => ls_wallet_logo_url() ),
		),
		'hexBackgroundColor' => '#f3f1ee',
		'reviewStatus'      => 'UNDER_REVIEW',
	);
	$args     = array(
		'timeout' => 20,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
		'body'    => wp_json_encode( $body ),
	);
	// Create via POST to the collection. 409 = already exists → treat as success.
	$res  = wp_remote_post( 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass', $args );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	if ( 409 === $code ) {
		if ( get_option( 'ls_gclass_v' ) !== GHM_VER ) { // refresh look of an existing class once per plugin version
			unset( $body['id'] );
			$r2 = wp_remote_request( 'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyClass/' . rawurlencode( ls_google_class_id() ), array_merge( $args, array( 'method' => 'PATCH', 'body' => wp_json_encode( $body ) ) ) );
			if ( ! is_wp_error( $r2 ) && (int) wp_remote_retrieve_response_code( $r2 ) < 300 ) {
				update_option( 'ls_gclass_v', GHM_VER, false );
			}
		}
		return true;
	}
	if ( $code >= 200 && $code < 300 ) {
		update_option( 'ls_gclass_v', GHM_VER, false );
	}
	if ( $code < 200 || $code >= 300 ) {
		$body_text = wp_remote_retrieve_body( $res );
		$detail    = '';
		if ( '' !== $body_text ) {
			$parsed = json_decode( $body_text, true );
			if ( is_array( $parsed ) && isset( $parsed['error']['message'] ) ) {
				$detail = ' — ' . $parsed['error']['message'];
			} else {
				$detail = ' — ' . substr( $body_text, 0, 200 );
			}
		}
		return new WP_Error( 'ls_class', __( 'Could not create Wallet class.', 'loyalty-stamps' ) . ' HTTP ' . $code . $detail );
	}
	return true;
}

/** Loyalty object payload for a customer (used in Save JWT and updates). */
function ls_google_object_payload( $customer_id ) {
	$customer_id = (int) $customer_id;
	$customer    = ls_get_customer( $customer_id );
	$state       = ls_wallet_card_state( $customer_id );
	$program     = $state['program'];
	$card        = $state['card'];
	$lang        = $customer && in_array( $customer->lang, array( 'pl', 'en', 'ar' ), true ) ? $customer->lang : 'en';
	$stamps      = (int) $card->stamps;
	$req         = (int) $program->stamps_required;

	return array(
		'id'            => ls_google_object_id( $customer_id ),
		'classId'       => ls_google_class_id(),
		'state'         => 'ACTIVE',
		'accountId'     => $customer ? $customer->phone : (string) $customer_id,
		'accountName'   => $customer ? ( $customer->name !== '' ? $customer->name : $customer->phone ) : '',
		'heroImage'     => array(
			'sourceUri' => array( 'uri' => ls_stamp_img_url( $stamps, $req ) ),
			'contentDescription' => array( 'defaultValue' => array( 'language' => $lang, 'value' => $stamps . '/' . $req ) ),
		),
		'barcode'       => array(
			'type'  => 'QR_CODE',
			'value' => $customer ? ls_card_url( $customer->token ) : home_url( '/' ),
		),
		'loyaltyPoints' => array(
			'label'   => ls_wallet_label( 'stamps', $lang ),
			'balance' => array( 'string' => $stamps . '/' . $req ),
		),
		'textModulesData' => array(
			array(
				'header' => ls_wallet_label( 'next_reward', $lang ),
				'body'   => $program->reward_title !== '' ? $program->reward_title : '—',
			),
		),
		'linksModuleData' => $customer ? array(
			'uris' => array(
				array(
					'uri'         => ls_card_url( $customer->token ),
					'description' => ls_wallet_label( 'loyalty', $lang ),
				),
			),
		) : array(),
	);
}

/**
 * "Add to Google Wallet" save URL (signed JWT). Creates the class first.
 * Returns URL string or WP_Error.
 */
function ls_google_save_url( $customer_id ) {
	$conf = ls_google_configured();
	if ( true !== $conf ) {
		return new WP_Error( 'ls_not_configured', $conf );
	}
	$sa = ls_google_sa();
	if ( ! $sa ) {
		return new WP_Error( 'ls_sa', __( 'Invalid service account.', 'loyalty-stamps' ) );
	}
	$cls = ls_google_ensure_class();
	if ( is_wp_error( $cls ) ) {
		return $cls;
	}
	$now    = time();
	$header = ls_b64url_encode( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = ls_b64url_encode(
		wp_json_encode(
			array(
				'iss'     => $sa['client_email'],
				'aud'     => 'google',
				'typ'     => 'savetowallet',
				'iat'     => $now,
				'payload' => array(
					'loyaltyObjects' => array( ls_google_object_payload( $customer_id ) ),
				),
			)
		)
	);
	$key = openssl_pkey_get_private( $sa['private_key'] );
	if ( ! $key || ! openssl_sign( $header . '.' . $claims, $sig, $key, OPENSSL_ALGO_SHA256 ) ) {
		return new WP_Error( 'ls_sign', __( 'Could not sign the Wallet link.', 'loyalty-stamps' ) );
	}
	return 'https://pay.google.com/gp/v/save/' . $header . '.' . $claims . '.' . ls_b64url_encode( $sig );
}

/**
 * Push updated stamp progress to an existing Google Wallet object.
 * Called after every stamp change. Silent when unconfigured.
 */
function ls_google_push_update( $customer_id ) {
	if ( true !== ls_google_configured() ) {
		return;
	}
	$token = ls_google_access_token();
	if ( is_wp_error( $token ) ) {
		return;
	}
	$payload = ls_google_object_payload( $customer_id );
	// Only updatable fields.
	$body = array(
		'loyaltyPoints'   => $payload['loyaltyPoints'],
		'textModulesData' => $payload['textModulesData'],
		'heroImage'       => $payload['heroImage'],
	);
	$res = wp_remote_request(
		'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/' . $payload['id'],
		array(
			'method'  => 'PATCH',
			'timeout' => 20,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		)
	);
	// 404 = object not saved by the customer yet; nothing to do.
	if ( is_wp_error( $res ) ) {
		error_log( '[ls-wallet] Google update failed: ' . $res->get_error_message() );
	}
}

/** Shows a message ("Stamp received!") inside the customer's Google Wallet pass. */
function ls_google_add_message( $customer_id, $header, $body = '' ) {
	if ( true !== ls_google_configured() ) {
		return;
	}
	$token = ls_google_access_token();
	if ( is_wp_error( $token ) ) {
		return;
	}
	wp_remote_post(
		'https://walletobjects.googleapis.com/walletobjects/v1/loyaltyObject/' . ls_google_object_id( $customer_id ) . '/addMessage',
		array(
			'timeout' => 15,
			'headers' => array( 'Authorization' => 'Bearer ' . $token, 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( array( 'message' => array( 'header' => $header, 'body' => $body !== '' ? $body : $header, 'messageType' => 'TEXT' ) ) ),
		)
	);
}
