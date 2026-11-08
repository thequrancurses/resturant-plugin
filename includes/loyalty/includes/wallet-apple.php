<?php
/**
 * Apple Wallet: .pkpass building + signing, web service (pass updates),
 * and APNs push notifications.
 *
 * Requires (admin uploads in Loyalty > Wallet):
 *  - Pass Type ID certificate (.p12) from Apple Developer + password
 *  - Apple WWDR intermediate certificate (.pem)
 *  - Pass Type ID (e.g. pass.pl.grillhub.loyalty) + Team ID
 *  - Site must run on HTTPS.
 */
defined( 'ABSPATH' ) || exit;

/** Is Apple Wallet fully configured? Returns true or a reason string. */
function ls_apple_configured() {
	if ( ! ls_setting( 'wallet_apple_enabled', 0 ) ) {
		return __( 'Apple Wallet is disabled.', 'loyalty-stamps' );
	}
	if ( ! is_ssl() && ! defined( 'LS_APPLE_ALLOW_HTTP' ) ) {
		return __( 'HTTPS is required for Apple Wallet.', 'loyalty-stamps' );
	}
	foreach ( array( 'apple_pass_type_id', 'apple_team_id' ) as $k ) {
		if ( '' === trim( (string) ls_setting( $k, '' ) ) ) {
			return __( 'Pass Type ID / Team ID missing.', 'loyalty-stamps' );
		}
	}
	foreach ( array( 'apple_cert_pem', 'apple_key_pem', 'apple_wwdr_pem' ) as $k ) {
		$f = ls_setting( $k, '' );
		if ( '' === $f || ! file_exists( $f ) ) {
			return __( 'Certificates not uploaded.', 'loyalty-stamps' );
		}
	}
	return true;
}

/** Web service base URL (https required by Apple). */
function ls_apple_webservice_url() {
	$url = home_url( '/ls-apple/v1/' );
	if ( 0 !== strpos( $url, 'https://' ) ) {
		return new WP_Error( 'ls_no_https', __( 'HTTPS is required for Apple Wallet.', 'loyalty-stamps' ) );
	}
	return $url;
}

/**
 * Build a .pkpass binary for a customer. Returns binary string or WP_Error.
 * Pass $customer_id = 0 for a sample pass (no real customer needed).
 */
function ls_apple_build_pass( $customer_id ) {
	$customer_id = (int) $customer_id;
	$sample      = $customer_id <= 0;
	$customer    = $sample ? null : ls_get_customer( $customer_id );
	if ( ! $sample && ! $customer ) {
		return new WP_Error( 'ls_no_customer', __( 'Customer not found.', 'loyalty-stamps' ) );
	}
	$conf = ls_apple_configured();
	if ( true !== $conf ) {
		return new WP_Error( 'ls_not_configured', $conf );
	}
	$ws = ls_apple_webservice_url();
	if ( is_wp_error( $ws ) ) {
		return $ws;
	}

	$state = $sample ? null : ls_wallet_card_state( $customer_id );
	$lang  = ( ! $sample && $customer->lang && in_array( $customer->lang, array( 'pl', 'en', 'ar' ), true ) ) ? $customer->lang : 'en';
	$serial = $sample ? 'LS-SAMPLE' : ls_wallet_serial( $customer_id );

	if ( $sample ) {
		$program      = ls_get_programs( true );
		$program      = $program ? $program[0] : null;
		$program_name = $program ? $program->name : __( 'Loyalty Program', 'loyalty-stamps' );
		$req          = $program ? (int) $program->stamps_required : 10;
		$reward_title = $program ? ls_program_reward_title( $program, $lang ) : __( 'Free reward', 'loyalty-stamps' );
		$stamps       = 3;
		$member_name  = __( 'Sample Customer', 'loyalty-stamps' );
		$card_link    = home_url( '/' );
	} else {
		$program      = $state['program'];
		$card         = $state['card'];
		$stamps       = (int) $card->stamps;
		$program_name = $program->name;
		$req          = (int) $program->stamps_required;
		$reward_title = ls_program_reward_title( $program, $lang );
		$member_name  = $customer->name !== '' ? $customer->name : $customer->phone;
		$card_link    = ls_card_url( $customer->token );
	}

	$pass = array(
		'formatVersion'       => 1,
		'passTypeIdentifier'  => trim( (string) ls_setting( 'apple_pass_type_id' ) ),
		'serialNumber'        => $serial,
		'teamIdentifier'      => trim( (string) ls_setting( 'apple_team_id' ) ),
		'organizationName'    => ls_setting( 'business_name', 'Grill Hub' ),
		'description'         => ls_wallet_label( 'loyalty', $lang ) . ' — ' . ls_setting( 'business_name', 'Grill Hub' ),
		'logoText'            => ls_setting( 'business_name', 'Grill Hub' ),
		'foregroundColor'     => 'rgb(20, 20, 22)',
		'backgroundColor'     => 'rgb(243, 241, 238)',
		'labelColor'          => 'rgb(225, 30, 38)',
		'storeCard'           => array(
			'headerFields'    => array(
				array(
					'key'           => 'stamps',
					'label'         => ls_wallet_label( 'stamps', $lang ),
					'value'         => $stamps . ' / ' . $req,
					'textAlignment' => 'PKTextAlignmentRight',
				),
			),
			'primaryFields'   => array(
				array(
					'key'   => 'member',
					'label' => ls_wallet_label( 'member', $lang ),
					'value' => $member_name,
				),
			),
			'secondaryFields' => array(
				array(
					'key'   => 'reward',
					'label' => ls_wallet_label( 'next_reward', $lang ),
					'value' => $reward_title,
				),
			),
			'backFields'      => array(
				array(
					'key'   => 'program',
					'label' => ls_wallet_label( 'loyalty', $lang ),
					'value' => $program_name,
				),
				array(
					'key'   => 'howto',
					'label' => $lang === 'ar' ? 'كيف يعمل' : ( $lang === 'pl' ? 'JAK TO DZIAŁA' : 'HOW IT WORKS' ),
					'value' => $lang === 'ar'
						? 'اعرض هذا الكارت عند كل زيارة لجمع الأختام. عند اكتمال الكارت تحصل على مكافأتك تلقائياً.'
						: ( $lang === 'pl'
							? 'Pokaż tę kartę przy każdej wizycie, aby zbierać pieczątki. Po uzupełnieniu karty nagroda pojawi się automatycznie.'
							: 'Show this card on every visit to collect stamps. When the card is complete, your reward appears automatically.' ),
				),
				array(
					'key'   => 'link',
					'label' => $lang === 'ar' ? 'رابط الكارت' : ( $lang === 'pl' ? 'LINK DO KARTY' : 'CARD LINK' ),
					'value' => $card_link,
				),
			),
		),
		'barcodes'            => array(
			array(
				'format'          => 'PKBarcodeFormatQR',
				'message'         => $card_link,
				'messageEncoding' => 'iso-8859-1',
			),
		),
		'barcode'             => array(
			'format'          => 'PKBarcodeFormatQR',
			'message'         => $card_link,
			'messageEncoding' => 'iso-8859-1',
		),
		'authenticationToken' => ls_wallet_auth_token( $serial ),
		'webServiceURL'       => $ws,
	);

	// Build the pass directory.
	$tmp = sys_get_temp_dir() . '/ls-pass-' . $serial . '-' . wp_generate_password( 8, false );
	wp_mkdir_p( $tmp );
	file_put_contents( $tmp . '/pass.json', wp_json_encode( $pass, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	foreach ( ls_wallet_images() as $k => $src ) {
		$name = array(
			'icon'   => 'icon.png',
			'icon2x' => 'icon@2x.png',
			'logo'   => 'logo.png',
			'logo2x' => 'logo@2x.png',
		)[ $k ];
		copy( $src, $tmp . '/' . $name );
	}

	// Stamp-grid strip image (changes with every stamp).
	foreach ( array( 'strip.png' => array( 375, 123 ), 'strip@2x.png' => array( 750, 246 ) ) as $sn => $sz ) {
		$png = ls_stamp_png( $stamps, $req, $sz[0], $sz[1], 'light' );
		if ( '' !== $png ) {
			file_put_contents( $tmp . '/' . $sn, $png );
		}
	}

	// Manifest.
	$manifest = array();
	foreach ( glob( $tmp . '/*' ) as $f ) {
		$manifest[ basename( $f ) ] = sha1_file( $f );
	}
	file_put_contents( $tmp . '/manifest.json', wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES ) );

	// Sign.
	$sig = ls_apple_sign( $tmp . '/manifest.json' );
	if ( is_wp_error( $sig ) ) {
		ls_apple_rrmdir( $tmp );
		return $sig;
	}
	file_put_contents( $tmp . '/signature', $sig );

	// Zip as .pkpass.
	$zip_path = $tmp . '.pkpass';
	$zip      = new ZipArchive();
	if ( true !== $zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
		ls_apple_rrmdir( $tmp );
		return new WP_Error( 'ls_zip', __( 'Could not create pass archive.', 'loyalty-stamps' ) );
	}
	foreach ( glob( $tmp . '/*' ) as $f ) {
		$zip->addFile( $f, basename( $f ) );
	}
	$zip->close();
	$bin = file_get_contents( $zip_path );
	@unlink( $zip_path );
	ls_apple_rrmdir( $tmp );
	return $bin;
}

/** Sign manifest.json with the Pass Type ID certificate. Returns DER binary or WP_Error. */
function ls_apple_sign( $manifest_path ) {
	$cert_pem = @file_get_contents( ls_setting( 'apple_cert_pem', '' ) );
	$key_pem  = @file_get_contents( ls_setting( 'apple_key_pem', '' ) );
	$wwdr_pem = ls_setting( 'apple_wwdr_pem', '' );
	if ( ! $cert_pem || ! $key_pem || ! file_exists( $wwdr_pem ) ) {
		return new WP_Error( 'ls_cert', __( 'Wallet certificates are missing.', 'loyalty-stamps' ) );
	}
	$cert = openssl_x509_read( $cert_pem );
	$key  = openssl_pkey_get_private( $key_pem );
	if ( ! $cert || ! $key ) {
		return new WP_Error( 'ls_cert', __( 'Invalid wallet certificate or key.', 'loyalty-stamps' ) );
	}
	$out = $manifest_path . '.sig.pem';
	$ok  = openssl_pkcs7_sign(
		$manifest_path,
		$out,
		$cert,
		$key,
		array(),
		PKCS7_BINARY | PKCS7_DETACHED,
		$wwdr_pem
	);
	if ( ! $ok || ! file_exists( $out ) ) {
		return new WP_Error( 'ls_sign', __( 'Could not sign the pass.', 'loyalty-stamps' ) );
	}
	// S/MIME output -> extract DER. Handles both multipart/signed (detached)
	// and simple base64 bodies. Validates the result is real PKCS7.
	$smime = file_get_contents( $out );
	@unlink( $out );

	$valid_der = function ( $der ) {
		return is_string( $der ) && strlen( $der ) > 100 && substr( $der, 0, 2 ) === "\x30\x82";
	};
	$b64_clean = function ( $b64 ) {
		// Cut anything from a trailing MIME boundary line onwards.
		$b64 = preg_replace( '/\R--.*$/s', '', (string) $b64 );
		$b64 = preg_replace( '/\s+/', '', $b64 );
		$b64 = rtrim( $b64, '-' );
		return base64_decode( $b64 );
	};

	// 1. Multipart: find the pkcs7-signature part.
	if ( preg_match( '/boundary="([^"]+)"/', $smime, $bm ) ) {
		foreach ( explode( '--' . $bm[1], $smime ) as $part ) {
			if ( stripos( $part, 'pkcs7-signature' ) !== false && preg_match( '/\R\R(.*)/s', $part, $m ) ) {
				$der = $b64_clean( $m[1] );
				if ( $valid_der( $der ) ) {
					return $der;
				}
			}
		}
	}
	// 2. Simple S/MIME: headers, blank line, base64 body.
	$body = $smime;
	if ( preg_match( '/\R\R(.*)/s', $smime, $m ) ) {
		$body = $m[1];
	}
	$body = str_replace( array( '-----BEGIN PKCS7-----', '-----END PKCS7-----' ), '', $body );
	$der  = $b64_clean( $body );
	if ( $valid_der( $der ) ) {
		return $der;
	}
	// 3. Raw file is already DER.
	if ( $valid_der( $smime ) ) {
		return $smime;
	}
	return new WP_Error( 'ls_sign', __( 'Could not parse the signature.', 'loyalty-stamps' ) );
}

/**
 * Remove a directory and the files directly inside it.
 *
 * @param string $dir Directory path.
 */
function ls_apple_rrmdir( $dir ) {
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( glob( $dir . '/*' ) as $f ) {
		if ( is_file( $f ) ) {
			@unlink( $f );
		}
	}
	@rmdir( $dir );
}

/* -------------------------------------------------------------------------
 * Apple web service (pass update registrations)
 * ---------------------------------------------------------------------- */

add_action( 'init', 'ls_apple_rewrites' );
/** Register rewrite rules for the Apple Wallet web service endpoints. */
function ls_apple_rewrites() {
	add_rewrite_rule( '^ls-apple/v1/devices/([^/]+)/registrations/([^/]+)/([^/]+)/?$', 'index.php?ls_apple=devreg&ls_a1=$matches[1]&ls_a2=$matches[2]&ls_a3=$matches[3]', 'top' );
	add_rewrite_rule( '^ls-apple/v1/devices/([^/]+)/registrations/([^/]+)/?$', 'index.php?ls_apple=devlist&ls_a1=$matches[1]&ls_a2=$matches[2]', 'top' );
	add_rewrite_rule( '^ls-apple/v1/passes/([^/]+)/([^/]+)/?$', 'index.php?ls_apple=pass&ls_a1=$matches[1]&ls_a2=$matches[2]', 'top' );
	add_rewrite_rule( '^ls-apple/v1/log/?$', 'index.php?ls_apple=log', 'top' );
}

add_filter( 'query_vars', 'ls_apple_query_vars' );
/**
 * Register custom query vars for the Apple Wallet web service.
 *
 * @param array $vars Query vars.
 * @return array Modified query vars.
 */
function ls_apple_query_vars( $vars ) {
	$vars[] = 'ls_apple';
	$vars[] = 'ls_a1';
	$vars[] = 'ls_a2';
	$vars[] = 'ls_a3';
	return $vars;
}

add_action( 'template_redirect', 'ls_apple_router' );
/**
 * Route Apple Wallet web service requests (device registration, pass updates, logging).
 *
 * Runs on template_redirect.
 */
function ls_apple_router() {
	$route = get_query_var( 'ls_apple' );
	if ( ! $route ) {
		return;
	}
	$a1 = get_query_var( 'ls_a1' );
	$a2 = get_query_var( 'ls_a2' );
	$a3 = get_query_var( 'ls_a3' );
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : 'GET';

	switch ( $route ) {
		case 'devreg': // /devices/{deviceId}/registrations/{passTypeId}/{serial}
			ls_apple_require_auth( $a3 );
			if ( 'POST' === $method ) {
				$body = json_decode( file_get_contents( 'php://input' ), true );
				$push = is_array( $body ) ? ( $body['pushToken'] ?? '' ) : '';
				if ( '' === $push ) {
					status_header( 400 );
					exit;
				}
				ls_apple_device_register( $a3, $a1, $push );
				status_header( 201 );
				exit;
			}
			if ( 'DELETE' === $method ) {
				ls_apple_device_unregister( $a3, $a1 );
				status_header( 200 );
				exit;
			}
			status_header( 405 );
			exit;

		case 'devlist': // /devices/{deviceId}/registrations/{passTypeId}?passesUpdatedSince=
			$since = isset( $_GET['passesUpdatedSince'] ) ? (int) $_GET['passesUpdatedSince'] : 0;
			$serials = ls_apple_updated_serials( $a1, $since );
			wp_send_json(
				array(
					'lastUpdated'   => (string) time(),
					'serialNumbers' => $serials,
				)
			);

		case 'pass': // /passes/{passTypeId}/{serial}
			ls_apple_require_auth( $a2 );
			$customer_id = (int) preg_replace( '/\D/', '', (string) $a2 );
			$bin = ls_apple_build_pass( $customer_id );
			if ( is_wp_error( $bin ) ) {
				status_header( 500 );
				exit;
			}
			header( 'Content-Type: application/vnd.apple.pkpass' );
			header( 'Content-Length: ' . strlen( $bin ) );
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s' ) . ' GMT' );
			echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;

		case 'log':
			$body = file_get_contents( 'php://input' );
			error_log( '[ls-apple] ' . substr( (string) $body, 0, 500 ) );
			status_header( 200 );
			exit;
	}
	status_header( 404 );
	exit;
}

/** Verify the ApplePass authentication token for a serial. Dies 401 on failure. */
function ls_apple_require_auth( $serial ) {
	$auth = '';
	if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
		$auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
	} elseif ( function_exists( 'apache_request_headers' ) ) {
		$h = apache_request_headers();
		$auth = $h['Authorization'] ?? ( $h['authorization'] ?? '' );
	}
	$token = preg_replace( '/^ApplePass\s+/i', '', trim( $auth ) );
	if ( '' === $token || ! hash_equals( ls_wallet_auth_token( (string) $serial ), $token ) ) {
		status_header( 401 );
		exit;
	}
}

/**
 * Register (or update) a device for push updates of a pass serial.
 *
 * @param string $serial     Pass serial.
 * @param string $device_id  Apple device library identifier.
 * @param string $push_token APNs push token.
 */
function ls_apple_device_register( $serial, $device_id, $push_token ) {
	global $wpdb;
	$p  = $wpdb->prefix;
	$now = ls_now();
	$existing = $wpdb->get_var(
		$wpdb->prepare(
			"SELECT id FROM {$p}ls_wallet_devices WHERE serial = %s AND device_id = %s",
			$serial,
			$device_id
		)
	);
	if ( $existing ) {
		$wpdb->update(
			"{$p}ls_wallet_devices",
			array( 'push_token' => $push_token, 'updated_at' => $now ),
			array( 'id' => (int) $existing )
		);
	} else {
		$wpdb->insert(
			"{$p}ls_wallet_devices",
			array(
				'serial'     => $serial,
				'device_id'  => $device_id,
				'push_token' => $push_token,
				'created_at' => $now,
				'updated_at' => $now,
			)
		);
	}
}

/**
 * Remove a device registration for a pass serial.
 *
 * @param string $serial    Pass serial.
 * @param string $device_id Apple device library identifier.
 */
function ls_apple_device_unregister( $serial, $device_id ) {
	global $wpdb;
	$wpdb->delete(
		"{$wpdb->prefix}ls_wallet_devices",
		array( 'serial' => $serial, 'device_id' => $device_id )
	);
}

/** Serials for a device updated since $tag (unix time). */
function ls_apple_updated_serials( $device_id, $since ) {
	global $wpdb;
	$since_dt = gmdate( 'Y-m-d H:i:s', max( 0, (int) $since ) );
	return $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT serial FROM {$wpdb->prefix}ls_wallet_devices
			 WHERE device_id = %s AND updated_at >= %s",
			$device_id,
			$since_dt
		)
	);
}

/**
 * Push an update to all devices holding this customer's pass.
 * Called after every stamp change.
 */
function ls_apple_push_update( $customer_id ) {
	if ( true !== ls_apple_configured() ) {
		return;
	}
	global $wpdb;
	$p      = $wpdb->prefix;
	$serial = ls_wallet_serial( $customer_id );
	$devices = $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$p}ls_wallet_devices WHERE serial = %s", $serial )
	);
	if ( ! $devices ) {
		return;
	}
	// Bump updated_at so the serials list reports it.
	$wpdb->update(
		"{$p}ls_wallet_devices",
		array( 'updated_at' => ls_now() ),
		array( 'serial' => $serial )
	);
	$pass_type = trim( (string) ls_setting( 'apple_pass_type_id' ) );
	foreach ( $devices as $d ) {
		$code = ls_apple_apns_push( $d->push_token, $pass_type );
		if ( 410 === $code ) {
			$wpdb->delete( "{$p}ls_wallet_devices", array( 'id' => (int) $d->id ) );
		}
	}
}

/** Send an empty background push via APNs (HTTP/2, certificate auth). Returns HTTP code or 0. */
function ls_apple_apns_push( $push_token, $pass_type_id ) {
	if ( ! function_exists( 'curl_init' ) ) {
		return 0;
	}
	$cert = @file_get_contents( ls_setting( 'apple_cert_pem', '' ) );
	$key  = @file_get_contents( ls_setting( 'apple_key_pem', '' ) );
	if ( ! $cert || ! $key ) {
		return 0;
	}
	$combined = tempnam( sys_get_temp_dir(), 'lsapns' );
	file_put_contents( $combined, $cert . "\n" . $key );

	$ch = curl_init( 'https://api.push.apple.com/3/device/' . $push_token );
	curl_setopt_array(
		$ch,
		array(
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => '{}',
			CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_2_0,
			CURLOPT_SSLCERT        => $combined,
			CURLOPT_HTTPHEADER     => array(
				'apns-topic: ' . $pass_type_id,
				'apns-push-type: background',
				'apns-priority: 5',
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_TIMEOUT        => 10,
		)
	);
	curl_exec( $ch );
	$code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
	curl_close( $ch );
	@unlink( $combined );
	return $code;
}
