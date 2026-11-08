<?php
/**
 * Wallet core: settings, pass serial/auth, logo handling, change trigger.
 * Apple + Google implementations live in wallet-apple.php / wallet-google.php.
 */
defined( 'ABSPATH' ) || exit;

/** Directory for wallet secrets (certs, service accounts). Protected via .htaccess + index.php. */
function ls_wallet_dir() {
	$up  = wp_upload_dir();
	$dir = trailingslashit( $up['basedir'] ) . 'ls-wallet';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
		// Block web access (Apache).
		file_put_contents( $dir . '/.htaccess', "Deny from all\n" );
		file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" );
	}
	return $dir;
}

/** Default wallet-related settings. Merged in ls_default_settings() below. */
function ls_wallet_default_settings() {
	return array(
		// Apple Wallet.
		'wallet_apple_enabled' => 0,
		'apple_pass_type_id'   => '', // e.g. pass.pl.grillhub.loyalty
		'apple_team_id'        => '', // 10-char Team ID
		'apple_cert_pem'       => '', // path to cert PEM (converted from .p12 at upload)
		'apple_key_pem'        => '', // path to private key PEM
		'apple_wwdr_pem'       => '', // path to Apple WWDR intermediate PEM
		// Google Wallet.
		'wallet_google_enabled' => 0,
		'google_issuer_id'      => '', // e.g. 3388000000022787089
		'google_sa_json'        => '', // path to service account JSON
	);
}

/** Pass serial for a customer. Stable: LS-<id>. */
function ls_wallet_serial( $customer_id ) {
	return 'LS-' . (int) $customer_id;
}

/** Per-pass authentication token (deterministic; verified on each Apple request). */
function ls_wallet_auth_token( $serial ) {
	return hash_hmac( 'sha256', (string) $serial, wp_salt( 'auth' ) . 'ls-wallet' );
}

/** The program/card shown on the wallet pass: first active program's active card. */
function ls_wallet_card_state( $customer_id ) {
	$customer_id = (int) $customer_id;
	$programs    = ls_get_programs( true );
	if ( ! $programs ) {
		return null;
	}
	global $wpdb;
	$p = $wpdb->prefix;
	foreach ( $programs as $pr ) {
		$card = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$p}ls_cards WHERE customer_id = %d AND program_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
				$customer_id,
				$pr->id
			)
		);
		if ( $card ) {
			return array( 'program' => $pr, 'card' => $card );
		}
	}
	// No active card: show the first program with 0 stamps.
	$pr = $programs[0];
	return array(
		'program' => $pr,
		'card'    => (object) array( 'stamps' => 0, 'expires_at' => null ),
	);
}

/**
 * Called whenever a customer's card changes (stamp added / completed).
 * Pushes updates to Apple + Google wallets. Safe to call when unconfigured.
 */
function ls_wallet_on_card_change( $customer_id ) {
	$customer_id = (int) $customer_id;
	if ( function_exists( 'ls_apple_push_update' ) ) {
		ls_apple_push_update( $customer_id );
	}
	if ( function_exists( 'ls_google_push_update' ) ) {
		ls_google_push_update( $customer_id );
		if ( function_exists( 'ls_google_add_message' ) ) {
			$cu = ls_get_customer( $customer_id );
			$lg = $cu && in_array( $cu->lang, array( 'pl', 'en', 'ar' ), true ) ? $cu->lang : ls_default_lang();
			$m  = array( 'pl' => 'Otrzymano pieczątkę!', 'en' => 'Stamp received!', 'ar' => 'تم إضافة ختم!' );
			ls_google_add_message( $customer_id, $m[ $lg ] );
		}
	}
}

/**
 * Logo handling: prefer the site custom logo, else the Grill Hub flame logo,
 * else a generated red badge. Returns array with paths for icon/logo images
 * sized for Apple Wallet, generating them once and caching in the wallet dir.
 */
function ls_wallet_images() {
	$dir = ls_wallet_dir() . '/img';
	if ( ! file_exists( $dir ) ) {
		wp_mkdir_p( $dir );
	}
	$files = array(
		'icon'     => $dir . '/icon.png',
		'icon2x'   => $dir . '/icon@2x.png',
		'logo'     => $dir . '/logo.png',
		'logo2x'   => $dir . '/logo@2x.png',
	);
	$done = true;
	foreach ( $files as $f ) {
		if ( ! file_exists( $f ) ) {
			$done = false;
			break;
		}
	}
	if ( $done ) {
		return $files;
	}

	$src = ls_wallet_logo_source();
	if ( ! $src || ! function_exists( 'wp_get_image_editor' ) ) {
		ls_wallet_draw_fallback_badge( $files );
		return $files;
	}
	$ed = wp_get_image_editor( $src );
	if ( is_wp_error( $ed ) ) {
		ls_wallet_draw_fallback_badge( $files );
		return $files;
	}
	// icon: square crops.
	foreach ( array( 'icon' => 87, 'icon2x' => 174 ) as $k => $size ) {
		$e2 = wp_get_image_editor( $src );
		if ( ! is_wp_error( $e2 ) ) {
			$e2->resize( $size, $size, true );
			$e2->save( $files[ $k ], 'image/png' );
		}
	}
	// logo: wide, max 160x50 pt.
	foreach ( array( 'logo' => 160, 'logo2x' => 320 ) as $k => $w ) {
		$e2 = wp_get_image_editor( $src );
		if ( ! is_wp_error( $e2 ) ) {
			$sz = $e2->get_size();
			if ( $sz ) {
				$h = (int) round( $sz['height'] * ( $w / max( 1, $sz['width'] ) ) );
				$h = min( $h, $k === 'logo' ? 50 : 100 );
				$e2->resize( $w, $h, false );
				$e2->save( $files[ $k ], 'image/png' );
			}
		}
	}
	return $files;
}

/** Public URL of the wallet pass logo (for the Google Wallet class). Falls back to the flame logo. */
function ls_wallet_logo_url() {
	$logo_id = (int) ls_setting( 'wallet_logo_id', 0 );
	if ( $logo_id ) {
		$url = wp_get_attachment_image_url( $logo_id, 'full' );
		if ( $url ) {
			return $url;
		}
	}
	return GHM_URL . 'assets/images/logo-flame.png';
}

/** Delete cached pass images so they regenerate (e.g. after a logo change). */
function ls_wallet_clear_images() {
	$dir = ls_wallet_dir() . '/img';
	foreach ( array( 'icon.png', 'icon@2x.png', 'logo.png', 'logo@2x.png' ) as $f ) {
		$p = $dir . '/' . $f;
		if ( file_exists( $p ) ) {
			@unlink( $p ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}
}

/** Find a logo source file: wallet logo setting, theme custom logo, else cached flame logo download. */
function ls_wallet_logo_source() {
	$logo_id = (int) ls_setting( 'wallet_logo_id', 0 );
	if ( $logo_id ) {
		$path = get_attached_file( $logo_id );
		if ( $path && file_exists( $path ) ) {
			return $path;
		}
	}
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$path = get_attached_file( $logo_id );
		if ( $path && file_exists( $path ) ) {
			return $path;
		}
	}
	$cached = ls_wallet_dir() . '/logo-new.png';
	if ( file_exists( $cached ) ) {
		return $cached;
	}
	// Try the plugin's default logo once and cache it.
	$res = wp_remote_get( GHM_URL . 'assets/images/logo-new.png', array( 'timeout' => 15 ) );
	if ( ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res ) ) {
		$body = wp_remote_retrieve_body( $res );
		if ( strlen( $body ) > 1000 ) {
			file_put_contents( $cached, $body );
			return $cached;
		}
	}
	return '';
}

/** Fallback: draw a simple red badge with white "GH" using GD. */
function ls_wallet_draw_fallback_badge( $files ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return;
	}
	foreach ( array( 'icon' => 87, 'icon2x' => 174, 'logo' => 160, 'logo2x' => 320 ) as $k => $size ) {
		$h   = ( $k === 'icon' || $k === 'icon2x' ) ? $size : (int) ( $size / 3.2 );
		$img = imagecreatetruecolor( $size, $h );
		$red = imagecolorallocate( $img, 236, 28, 36 );
		$wht = imagecolorallocate( $img, 255, 255, 255 );
		imagefilledrectangle( $img, 0, 0, $size, $h, $red );
		$fs  = (int) ( $h * 0.5 );
		imagestring( $img, 5, (int) ( $size / 2 - $fs * 0.7 ), (int) ( $h / 2 - $fs * 0.45 ), 'GH', $wht );
		imagepng( $img, $files[ $k ] );
		imagedestroy( $img );
	}
}

/**
 * Decode an XOR-obfuscated + base64ed string (see the wallet admin form JS).
 * Used to smuggle the service-account JSON past overzealous firewalls.
 * Returns the decoded string, or '' on failure.
 */
function ls_wallet_xor_decode( $b64 ) {
	$raw = base64_decode( $b64, true );
	if ( ! is_string( $raw ) || '' === $raw ) {
		return '';
	}
	$key = 'lsGrillHubWalletKey2024';
	$kl  = strlen( $key );
	$out = '';
	for ( $i = 0, $n = strlen( $raw ); $i < $n; $i++ ) {
		$out .= $raw[ $i ] ^ $key[ $i % $kl ];
	}
	return trim( $out );
}

/** Small label translations for passes. */
function ls_wallet_label( $key, $lang ) {
	$map = array(
		'stamps'      => array( 'pl' => 'PIECZĄTKI', 'en' => 'STAMPS', 'ar' => 'الأختام' ),
		'member'      => array( 'pl' => 'KLUBOWICZ', 'en' => 'MEMBER', 'ar' => 'العضو' ),
		'next_reward' => array( 'pl' => 'NASTĘPNA NAGRODA', 'en' => 'NEXT REWARD', 'ar' => 'المكافأة القادمة' ),
		'expires'     => array( 'pl' => 'WAŻNA DO', 'en' => 'EXPIRES', 'ar' => 'تنتهي في' ),
		'loyalty'     => array( 'pl' => 'Karta lojalnościowa', 'en' => 'Loyalty card', 'ar' => 'كارت الولاء' ),
	);
	return $map[ $key ][ $lang ] ?? $map[ $key ]['en'];
}

/* -------------------------------------------------------------------------
 * Stamp-grid picture (hero image on Google Wallet, strip on Apple Wallet)
 * ---------------------------------------------------------------------- */

/** Draws the stamp grid as PNG bytes (GD). $theme 'light' | 'dark'. */
function ls_stamp_png( $stamps, $req, $w = 1032, $h = 336, $theme = 'light' ) {
	if ( ! function_exists( 'imagecreatetruecolor' ) ) {
		return '';
	}
	$req    = max( 1, min( 12, (int) $req ) );
	$stamps = max( 0, min( $req, (int) $stamps ) );
	$S      = 2; // supersample for smooth edges
	$W      = $w * $S;
	$H      = $h * $S;
	$im     = imagecreatetruecolor( $W, $H );
	imagealphablending( $im, true );
	imageantialias( $im, true );
	$dark = 'dark' === $theme;
	$bg   = $dark ? array( 20, 20, 22 ) : array( 243, 241, 238 );
	$red  = array( 225, 30, 38 );
	$ring = $dark ? array( 70, 70, 76 ) : array( 205, 200, 194 );
	$c    = function ( $a ) use ( $im ) { return imagecolorallocate( $im, $a[0], $a[1], $a[2] ); };
	imagefilledrectangle( $im, 0, 0, $W, $H, $c( $bg ) );

	$rows = $req <= 6 ? 1 : 2;
	$per  = (int) ceil( $req / $rows );
	$pad  = 0.05 * $W;
	$cell = min( ( $W - 2 * $pad ) / $per, ( $H - 0.16 * $H ) / $rows );
	$d    = $cell * 0.78;
	$gx   = ( $W - $per * $cell ) / 2;
	$gy   = ( $H - $rows * $cell ) / 2;
	for ( $i = 0; $i < $req; $i++ ) {
		$r  = (int) floor( $i / $per );
		$k  = $i % $per;
		$n  = ( $r === $rows - 1 ) ? $req - $r * $per : $per; // centre the last row
		$ox = ( $W - $n * $cell ) / 2;
		$cx = (int) ( $ox + $k * $cell + $cell / 2 );
		$cy = (int) ( $gy + $r * $cell + $cell / 2 );
		$last = ( $i === $req - 1 );
		if ( $i < $stamps ) {
			imagefilledellipse( $im, $cx, $cy, (int) $d, (int) $d, $c( $red ) );
			$wh = imagecolorallocate( $im, 255, 255, 255 );
			$u  = $d / 2;
			$t  = $d * 0.055; // half stroke
			foreach ( array( array( -0.38, 0.0, -0.08, 0.3 ), array( -0.08, 0.3, 0.42, -0.3 ) ) as $ln ) {
				$x1 = $cx + $u * $ln[0]; $y1 = $cy + $u * $ln[1]; $x2 = $cx + $u * $ln[2]; $y2 = $cy + $u * $ln[3];
				$len = sqrt( ( $x2 - $x1 ) ** 2 + ( $y2 - $y1 ) ** 2 ); $nx = -( $y2 - $y1 ) / $len * $t; $ny = ( $x2 - $x1 ) / $len * $t;
				imagefilledpolygon( $im, array( (int) ( $x1 + $nx ), (int) ( $y1 + $ny ), (int) ( $x2 + $nx ), (int) ( $y2 + $ny ), (int) ( $x2 - $nx ), (int) ( $y2 - $ny ), (int) ( $x1 - $nx ), (int) ( $y1 - $ny ) ), 4, $wh );
				imagefilledellipse( $im, (int) $x1, (int) $y1, (int) ( 2 * $t ), (int) ( 2 * $t ), $wh );
				imagefilledellipse( $im, (int) $x2, (int) $y2, (int) ( 2 * $t ), (int) ( 2 * $t ), $wh );
			}
		} else {
			imagefilledellipse( $im, $cx, $cy, (int) $d, (int) $d, $c( $ring ) );
			imagefilledellipse( $im, $cx, $cy, (int) ( $d * 0.9 ), (int) ( $d * 0.9 ), $c( $bg ) );
			if ( $last ) { // reward slot: small gift-like star
				$col = $c( $red );
				$pts = array();
				for ( $j = 0; $j < 10; $j++ ) {
					$rad   = ( $j % 2 ? 0.16 : 0.34 ) * $d;
					$ang   = -M_PI / 2 + $j * M_PI / 5;
					$pts[] = (int) ( $cx + $rad * cos( $ang ) );
					$pts[] = (int) ( $cy + $rad * sin( $ang ) );
				}
				imagefilledpolygon( $im, $pts, 10, $col );
			} else {
				imagefilledellipse( $im, $cx, $cy, (int) ( $d * 0.12 ), (int) ( $d * 0.12 ), $c( $ring ) );
			}
		}
	}
	$out = imagecreatetruecolor( $w, $h );
	imagecopyresampled( $out, $im, 0, 0, 0, 0, $w, $h, $W, $H );
	ob_start();
	imagepng( $out, null, 6 );
	$png = ob_get_clean();
	imagedestroy( $im );
	imagedestroy( $out );
	return $png;
}

/** Public URL of the stamp picture (fetched by Google Wallet). */
function ls_stamp_img_url( $stamps, $req, $theme = 'light' ) {
	return add_query_arg( array( 'ls-stamps' => (int) $stamps . '-' . (int) $req . '-' . ( 'dark' === $theme ? 'd' : 'l' ) ), home_url( '/' ) );
}

add_action( 'init', function () {
	if ( empty( $_GET['ls-stamps'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( ! preg_match( '/^(\d{1,2})-(\d{1,2})-([ld])$/', (string) $_GET['ls-stamps'], $m ) ) { // phpcs:ignore
		return;
	}
	$png = ls_stamp_png( $m[1], $m[2], 1032, 336, 'd' === $m[3] ? 'dark' : 'light' );
	if ( '' === $png ) {
		status_header( 404 );
		exit;
	}
	nocache_headers();
	header( 'Content-Type: image/png' );
	echo $png; // phpcs:ignore
	exit;
}, 1 );
