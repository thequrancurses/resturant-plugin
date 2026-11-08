<?php
/**
 * Loyalty Stamps, built into Grill Hub Menu (section "Loyalty").
 * Programs, stamps, rewards, notifications (inbox / e-mail / WhatsApp / Web Push), nearby offers, wallet passes, staff panel.
 * Same tables (wp_ls_*) and option names as the stand-alone "Loyalty Stamps" plugin, so existing data keeps working.
 */
defined( 'ABSPATH' ) || exit;

if ( defined( 'LS_VERSION' ) ) {
	// the separate plugin is still active: let it keep working and ask to remove it
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p><b>Grill Hub:</b> the separate "Loyalty Stamps" plugin is still active. Loyalty is now built into Grill Hub Menu - please deactivate and delete the separate plugin (your data is kept).</p></div>';
	} );
	return;
}

define( 'LS_VERSION', '1.5.8' );
define( 'LS_PATH', GHM_DIR . 'includes/loyalty/' );
define( 'LS_URL', GHM_URL . 'includes/loyalty/' );
define( 'LS_BASENAME', plugin_basename( GHM_DIR . 'grill-hub-menu.php' ) );

/* Load order matters: db -> i18n -> settings -> core -> notify -> geofence -> wallet -> api -> cron -> admin -> shortcodes */
require_once LS_PATH . 'includes/db.php';
require_once LS_PATH . 'includes/i18n.php';
require_once LS_PATH . 'includes/settings.php';
require_once LS_PATH . 'includes/core.php';
require_once LS_PATH . 'includes/notify.php';
require_once LS_PATH . 'includes/geofence.php';
require_once LS_PATH . 'includes/wallet.php';
require_once LS_PATH . 'includes/wallet-apple.php';
require_once LS_PATH . 'includes/wallet-google.php';
require_once LS_PATH . 'includes/api.php';
require_once LS_PATH . 'includes/cron.php';
if ( is_admin() ) {
	require_once LS_PATH . 'includes/admin.php';
	require_once LS_PATH . 'includes/admin-wallet.php';
}
require_once LS_PATH . 'includes/shortcodes.php';

/** First run / update: tables, default settings (random staff PIN), cron, service-worker address. */
add_action( 'init', 'ls_maybe_upgrade_db', 5 );
function ls_maybe_upgrade_db() {
	if ( get_option( 'ls_db_version' ) !== ls_db_version() ) {
		ls_db_install();
	}
	if ( get_option( 'ghm_ls_boot' ) !== LS_VERSION ) {
		ls_settings_seed();
		// ONE VAPID key pair for order push and loyalty push (a browser allows a single push subscription per site)
		if ( ls_setting( 'vapid_public', '' ) && ls_setting( 'vapid_private', '' ) ) {
			update_option( 'ghm_vapid', array( 'priv' => ls_setting( 'vapid_private', '' ), 'pub' => ls_setting( 'vapid_public', '' ) ), false );
		} elseif ( function_exists( 'ghm_vapid' ) && ( $gk = ghm_vapid() ) ) {
			ls_update_settings( array( 'vapid_public' => $gk['pub'], 'vapid_private' => $gk['priv'] ) );
		}
		ls_cron_schedule();
		add_rewrite_rule( '^ls-sw\.js$', 'index.php?ls_sw=1', 'top' );
		flush_rewrite_rules();
		ls_wallet_clear_images(); // regenerate pass images (logo may have changed)
		update_option( 'ghm_ls_boot', LS_VERSION, false );
	}
}
// leaving the plugin deactivated must not leave the cron jobs running
register_deactivation_hook( GHM_DIR . 'grill-hub-menu.php', 'ls_cron_unschedule' );

add_action( 'init', 'ls_init' );
function ls_init() {
	ls_load_textdomain();
	add_rewrite_rule( '^ls-sw\.js$', 'index.php?ls_sw=1', 'top' );
	add_filter( 'query_vars', 'ls_query_vars' );
	add_action( 'template_redirect', 'ls_maybe_serve_sw' );
}
function ls_query_vars( $vars ) { $vars[] = 'ls_sw'; return $vars; }

/** Serve the push service worker from the site root so its scope can be "/". */
function ls_maybe_serve_sw() {
	if ( (int) get_query_var( 'ls_sw' ) !== 1 ) {
		return;
	}
	$file = GHM_DIR . 'assets/ghm-sw.js'; // one service worker for order push + loyalty push
	if ( ! file_exists( $file ) ) { status_header( 404 ); exit; }
	header( 'Content-Type: application/javascript; charset=utf-8' );
	header( 'Cache-Control: no-cache' );
	echo file_get_contents( $file ); // phpcs:ignore -- static file shipped with the plugin
	exit;
}

/** Data handed to assets/js/frontend.js. */
function ls_frontend_data() {
	return apply_filters( 'ls_frontend_data', array(
		'restUrl'   => esc_url_raw( rest_url( 'loyalty/v1/' ) ),
		'nonce'     => wp_create_nonce( 'wp_rest' ),
		'vapidKey'  => ls_setting( 'vapid_public', '' ),
		'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
		'staffNonce' => wp_create_nonce( 'ls_staff' ),
		'logo'       => GHM_URL . 'assets/images/logo-new.png',
		'brand'      => (string) ls_setting( 'business_name', ls_default_brand() ),
		// QR image provider. {data} is replaced with the encoded URL. Filter: ls_qr_image_url.
		'qrUrl'      => apply_filters( 'ls_qr_image_url', 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data={data}' ),
		'i18n'       => array(
		'noCards'         => __( 'No active cards yet — ask staff to add your first stamp!', 'loyalty-stamps' ),
		'showQr'          => __( 'Show this QR to staff to collect a stamp', 'loyalty-stamps' ),
		'noRewards'       => __( 'No rewards yet.', 'loyalty-stamps' ),
		'noInbox'         => __( 'No notifications yet.', 'loyalty-stamps' ),
		'notifEmail'      => __( 'Notifications by email', 'loyalty-stamps' ),
		'notifEmailSub'   => __( 'Requires an email on file', 'loyalty-stamps' ),
		'pushNotif'       => __( 'Push notifications', 'loyalty-stamps' ),
		'pushNotifSub'    => __( 'Works when you allow notifications in the browser', 'loyalty-stamps' ),
		'waNotif'         => __( 'WhatsApp notifications', 'loyalty-stamps' ),
		'nearbyOffers'    => __( 'Nearby offers', 'loyalty-stamps' ),
		'nearbyOffersSub' => __( 'Share location to get promos within 1 km', 'loyalty-stamps' ),
		'save'            => __( 'Save', 'loyalty-stamps' ),
		'inviteFriends'   => __( 'Invite friends', 'loyalty-stamps' ),
		'inviteFriendsSub' => __( 'Share your link — you both earn bonus stamps', 'loyalty-stamps' ),
		'confirmDelete'   => __( 'Delete all your loyalty data? This cannot be undone.', 'loyalty-stamps' ),
		'stamp'           => __( 'Stamp', 'loyalty-stamps' ),
		'enableLocation'  => __( 'Share my location for nearby offers', 'loyalty-stamps' ),
		'addApple'        => __( 'Add to Apple Wallet', 'loyalty-stamps' ),
		'addGoogle'       => __( 'Add to Google Wallet', 'loyalty-stamps' ),
		'scan'            => __( 'Scan', 'loyalty-stamps' ),
		'scanNotSupported' => __( 'QR scanning is not supported on this device or browser. Please enter the phone number manually.', 'loyalty-stamps' ),
		'scanCamError'    => __( 'Could not access the camera', 'loyalty-stamps' ),
		'walletTitle'     => __( 'Save to phone wallet', 'loyalty-stamps' ),
		'walletSub'       => __( 'Your stamps update automatically in the wallet.', 'loyalty-stamps' ),
		),
	) );
}

/** The three shortcodes of the section. */
function ls_page_shortcodes() { return array( 'loyalty_register', 'loyalty_card', 'loyalty_staff' ); }

/** URL of the page that holds a loyalty shortcode (or ''). */
function ls_url_of( $shortcode ) {
	$id = ls_find_shortcode_page( $shortcode );
	return $id ? (string) get_permalink( $id ) : '';
}

/**
 * Loyalty pages are rendered as full stand-alone pages in the Grill Hub look (like the menu and the tracking page):
 * the theme's header / footer are not used, so all pages share one design and the PL / EN / AR switch.
 */
add_action( 'template_redirect', 'ls_standalone', 20 );
function ls_standalone() {
	if ( is_admin() || ! is_singular() ) return;
	$post = get_post();
	if ( ! $post ) return;
	$has = false;
	foreach ( ls_page_shortcodes() as $sc ) { if ( has_shortcode( $post->post_content, $sc ) ) { $has = true; break; } }
	if ( ! $has ) return;
	if ( function_exists( 'ghm_is_amp_or_rest' ) && ghm_is_amp_or_rest() ) return;
	$lang  = ls_current_lang();
	$dir   = 'ar' === $lang ? 'rtl' : 'ltr';
	$theme = ls_theme();
	$title = trim( get_the_title( $post ) . ' – ' . ls_setting( 'business_name', get_bloginfo( 'name' ) ), ' –' );
	$body  = do_shortcode( $post->post_content ); // renders the header (ls_page_header_open) + content
	nocache_headers();
	header( 'Content-Type: text/html; charset=' . get_bloginfo( 'charset' ) );
	if ( ! has_shortcode( $post->post_content, 'loyalty_register' ) ) header( 'X-Robots-Tag: noindex, nofollow' );
	?><!doctype html>
<html lang="<?php echo esc_attr( $lang ); ?>" dir="<?php echo esc_attr( $dir ); ?>">
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="<?php echo 'dark' === $theme ? '#0a0a0b' : '#ffffff'; ?>">
<title><?php echo esc_html( $title ); ?></title>
<?php if ( ! has_shortcode( $post->post_content, 'loyalty_register' ) ) echo '<meta name="robots" content="noindex,nofollow">' . "\n"; ?>
<?php echo ghm_icons_html(); // escaped inside ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url( ghm_fonts_url() ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( GHM_URL . 'assets/hdr.css?ver=' . GHM_VER ); ?>">
<link rel="stylesheet" href="<?php echo esc_url( LS_URL . 'assets/css/frontend.css?ver=' . GHM_VER ); ?>">
</head>
<body class="ghm-standalone ls-body ls-body-<?php echo esc_attr( $theme ); ?>">
<?php echo $body; // phpcs:ignore -- built from escaped parts by the shortcodes ?>
<script>window.LS_DATA=<?php echo ghm_json( ls_frontend_data() ); ?>;<?php echo ghm_ord_js(); ?></script>
<script src="<?php echo esc_url( GHM_URL . 'assets/shared.js?ver=' . GHM_VER ); ?>"></script>
<script src="<?php echo esc_url( LS_URL . 'assets/js/frontend.js?ver=' . GHM_VER ); ?>"></script>
<script>
(function(){try{localStorage.setItem("gh-lang",<?php echo ghm_json( $lang ); ?>);}catch(e){}
document.addEventListener("click",function(e){var a=e.target.closest&&e.target.closest(".gh-lmenu a");if(a){try{localStorage.setItem("gh-lang",a.getAttribute("data-lang"));}catch(x){}}});})();
</script>
</body>
</html>
<?php
	exit;
}

/** Where the "home" link of the loyalty header leads (same target as the menu's logo). */
function ghm_home_url_for_loyalty() { return ghm_home_target( ghm_get_data() ); }
function ghm_menu_url_for_loyalty() { $p = ghm_find_page(); return $p ? (string) get_permalink( $p ) : ''; }

/** One click: create the three pages. */
function ls_create_pages() {
	$map = array(
		'loyalty_register' => array( 'Loyalty', 'loyalty' ),
		'loyalty_card'     => array( 'My loyalty card', 'loyalty-card' ),
		'loyalty_staff'    => array( 'Loyalty staff', 'loyalty-staff' ),
	);
	$out = array();
	foreach ( $map as $sc => $m ) {
		$id = ls_find_shortcode_page( $sc );
		if ( ! $id ) $id = wp_insert_post( array( 'post_title' => $m[0], 'post_name' => $m[1], 'post_content' => '[' . $sc . ']', 'post_status' => 'publish', 'post_type' => 'page' ) );
		$out[ $sc ] = $id ? (string) get_permalink( $id ) : '';
	}
	foreach ( ls_page_shortcodes() as $sc ) delete_transient( 'ls_page_' . md5( $sc ) );
	wp_cache_delete( 'ls_page_ids', 'ls' ); wp_cache_delete( 'ls_page_slugs', 'ls' );
	return $out;
}
