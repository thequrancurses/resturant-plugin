<?php
/**
 * Multilingual support (PL / EN / AR) for loyalty pages.
 *
 * Language is chosen via ?ls_lang=pl|en|ar and remembered in a cookie.
 * Applied through the determine_locale filter, but ONLY on loyalty pages,
 * so the rest of the site keeps working exactly as before.
 */
defined( 'ABSPATH' ) || exit;

/** Short code => WP locale map. */
function ls_lang_locales() {
	return array(
		'pl' => 'pl_PL',
		'en' => 'en_US',
		'ar' => 'ar',
	);
}

/** Current loyalty language short code, derived from the active locale. */
function ls_current_lang() {
	$loc = get_locale();
	if ( 0 === strpos( $loc, 'ar' ) ) {
		return 'ar';
	}
	if ( 0 === strpos( $loc, 'pl' ) ) {
		return 'pl';
	}
	return 'en';
}

/** Requested language code from ?ls_lang= or cookie, or ''. */
function ls_requested_lang_code() {
	$map = ls_lang_locales();
	if ( isset( $_GET['ls_lang'] ) && is_string( $_GET['ls_lang'] ) ) {
		$l = sanitize_key( wp_unslash( $_GET['ls_lang'] ) );
		if ( isset( $map[ $l ] ) ) {
			return $l;
		}
	}
	if ( isset( $_COOKIE['ls_lang'] ) && is_string( $_COOKIE['ls_lang'] ) ) {
		$l = sanitize_key( wp_unslash( $_COOKIE['ls_lang'] ) );
		if ( isset( $map[ $l ] ) ) {
			return $l;
		}
	}
	return ls_default_lang();
}

/** Default language of the pages = the Grill Hub default (Polish unless changed in Settings). */
function ls_default_lang() {
	$d = json_decode( (string) get_option( 'ghm_data', '' ), true );
	$l = is_array( $d ) && isset( $d['settings']['default_lang'] ) ? (string) $d['settings']['default_lang'] : 'pl';
	return isset( ls_lang_locales()[ $l ] ) ? $l : 'pl';
}

/** Load the plugin's translations (files are pl.mo / ar.mo; English is the source language). */
function ls_load_textdomain() {
	$lang = ls_requested_lang_code();
	if ( ! ls_should_switch_locale() ) {
		return;
	}
	if ( in_array( $lang, array( 'pl', 'ar' ), true ) && file_exists( LS_PATH . 'languages/' . $lang . '.mo' ) ) {
		unload_textdomain( 'loyalty-stamps' );
		load_textdomain( 'loyalty-stamps', LS_PATH . 'languages/' . $lang . '.mo' );
	}
}

/** Should the locale be switched for this request? Loyalty pages + staff ajax only. */
function ls_should_switch_locale() {
	if ( '' === ls_requested_lang_code() ) {
		return false;
	}
	if ( ls_is_loyalty_request() ) {
		return true;
	}
	if ( defined( 'DOING_AJAX' ) && DOING_AJAX
		&& isset( $_REQUEST['action'] )
		&& 0 === strpos( (string) $_REQUEST['action'], 'ls_staff' ) ) {
		return true;
	}
	return false;
}

/**
 * Switch the locale for loyalty requests (determine_locale / locale filters).
 *
 * @param string $locale Current locale.
 * @return string Switched locale or the original.
 */
function ls_switched_locale( $locale ) {
	if ( ! ls_should_switch_locale() ) {
		return $locale;
	}
	$map = ls_lang_locales();
	return $map[ ls_requested_lang_code() ];
}

// determine_locale: affects the default textdomain loaded in wp-settings.
add_filter( 'determine_locale', 'ls_switched_locale' );
// locale: affects get_locale() -> every load_*_textdomain() call, incl. ours on init.
add_filter( 'locale', 'ls_switched_locale' );

/** Remember the choice in a cookie (init is early enough for setcookie). */
add_action( 'init', 'ls_lang_remember' );
/**
 * Remember the ?ls_lang= choice in a cookie.
 *
 * Runs on init, which is early enough for setcookie().
 */
function ls_lang_remember() {
	if ( is_admin() ) {
		return;
	}
	if ( isset( $_GET['ls_lang'] ) && is_string( $_GET['ls_lang'] ) ) {
		$l = sanitize_key( wp_unslash( $_GET['ls_lang'] ) );
		if ( isset( ls_lang_locales()[ $l ] ) ) {
			setcookie( 'ls_lang', $l, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['ls_lang'] = $l;
		}
	}
}

/**
 * Is the current request one of the loyalty pages?
 * Safe to call very early (determine_locale): direct $wpdb query only.
 */
function ls_is_loyalty_request() {
	$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = trim( (string) parse_url( $uri, PHP_URL_PATH ), '/' );

	// Plain permalinks (?page_id=N).
	if ( isset( $_GET['page_id'] ) ) {
		if ( in_array( (int) $_GET['page_id'], ls_loyalty_page_ids(), true ) ) {
			return true;
		}
	}
	if ( '' === $path ) {
		return false;
	}
	foreach ( ls_loyalty_page_slugs() as $slug ) {
		if ( '' === $slug ) {
			continue;
		}
		if ( $path === $slug || substr( $path, -strlen( '/' . $slug ) ) === '/' . $slug ) {
			return true;
		}
	}
	return false;
}

/** IDs of published pages containing a loyalty shortcode (cached). */
function ls_loyalty_page_ids() {
	global $wpdb;
	$ids = wp_cache_get( 'ls_page_ids', 'ls' );
	if ( false === $ids ) {
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'page' AND post_status = 'publish'
			 AND (post_content LIKE '%[loyalty_register%' OR post_content LIKE '%[loyalty_card%' OR post_content LIKE '%[loyalty_staff%')"
		);
		$ids = array_map( 'intval', (array) $ids );
		wp_cache_set( 'ls_page_ids', $ids, 'ls', HOUR_IN_SECONDS );
	}
	return $ids;
}

/** Slugs of published pages containing a loyalty shortcode (cached). */
function ls_loyalty_page_slugs() {
	global $wpdb;
	$slugs = wp_cache_get( 'ls_page_slugs', 'ls' );
	if ( false === $slugs ) {
		$slugs = $wpdb->get_col(
			"SELECT post_name FROM {$wpdb->posts}
			 WHERE post_type = 'page' AND post_status = 'publish'
			 AND (post_content LIKE '%[loyalty_register%' OR post_content LIKE '%[loyalty_card%' OR post_content LIKE '%[loyalty_staff%')"
		);
		wp_cache_set( 'ls_page_slugs', (array) $slugs, 'ls', HOUR_IN_SECONDS );
	}
	return (array) $slugs;
}

/* -------------------------------------------------------------------------
 * Branded page header: logo + home + language switcher
 * ---------------------------------------------------------------------- */

/** Logo of the restaurant: Grill Hub setting first, then the theme logo. */
function ls_logo_url() {
	$d = json_decode( (string) get_option( 'ghm_data', '' ), true );
	$u = is_array( $d ) && ! empty( $d['settings']['logo'] ) ? (string) $d['settings']['logo'] : '';
	if ( '' !== $u ) {
		return preg_match( '~^(https?:)?//~i', $u ) ? $u : GHM_URL . 'assets/' . ltrim( $u, '/' );
	}
	$logo_id = get_theme_mod( 'custom_logo' );
	if ( $logo_id ) {
		$src = wp_get_attachment_image_src( $logo_id, 'full' );
		if ( $src ) {
			return $src[0];
		}
	}
	return GHM_URL . 'assets/images/logo-flame.png';
}

/** Page theme: "light" (same as the menu / tracking page) or "dark" (same as the home page). */
function ls_theme() {
	return 'dark' === ls_setting( 'theme', 'light' ) ? 'dark' : 'light';
}

/** Opening markup for loyalty pages: the shared Grill Hub header (same as the menu) + the page wrapper. */
function ls_page_header_open() {
	$cur   = ls_current_lang();
	$items = array();
	foreach ( array( 'pl', 'en', 'ar' ) as $c ) {
		$items[ $c ] = add_query_arg( 'ls_lang', $c );
	}
	return ghm_hdr_html( $cur, $items ) . '<div class="ls-page ls-' . esc_attr( ls_theme() ) . '" dir="' . ( 'ar' === $cur ? 'rtl' : 'ltr' ) . '">';
}

/** "Menu" label in the three languages (kept here so the .mo files need no change). */
function ls_t_menu() {
	$m = array( 'pl' => 'Menu', 'en' => 'Menu', 'ar' => 'المنيو' );
	return $m[ ls_current_lang() ];
}

/** Closing markup for the .ls-page wrapper. */
function ls_page_footer_close() {
	return '</div><!-- .ls-page -->' . ghm_ftr_html( ls_current_lang(), 'dark' === ls_theme() );
}
