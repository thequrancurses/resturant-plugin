<?php
/**
 * Settings: defaults, getters, seed (incl. staff PIN).
 */
defined( 'ABSPATH' ) || exit;

/** Default settings array. */
function ls_default_settings() {
	$defaults = array(
		// General.
		'business_name'        => 'Grill Hub',
		'theme'                => 'light', // light = like the menu, dark = like the home page
		// Staff panel PIN (hashed on seed).
		'staff_pin_hash'       => '',
		// Notifications: channels.
		'enable_inbox'         => 1,
		'enable_email'         => 1,
		'enable_push'          => 1,
		'enable_whatsapp'      => 0,
		// WhatsApp webhook: URL with {phone} and {message} placeholders, GET or POST.
		'whatsapp_webhook'     => '',
		'whatsapp_method'      => 'GET',
		// Web Push (VAPID).
		'vapid_public'         => '',
		'vapid_private'        => '',
		'vapid_subject'        => '',
		// Geofence.
		'geofence_cooldown_h'  => 24,      // min hours between two "nearby" promos per store
		'geofence_quiet_from'  => '22:00', // no promos during quiet hours
		'geofence_quiet_to'    => '09:00',
		'geofence_location_ttl_h' => 12,   // "broadcast to nearby" uses locations newer than this
		// Limits / anti-fraud.
		'max_stamps_per_day'   => 5,
		'stamp_cooldown_h'     => 12, // min hours between two stamps on the same card (0 = no cooldown)
		'reward_expiry_days'   => 60,
		// Referral & birthday.
		'referral_enabled'     => 1,
		'referral_bonus'       => 1, // stamps for referrer (and for the new customer as welcome)
		'birthday_enabled'     => 1,
		'birthday_reward'      => '',
		// Message templates. Placeholders: {name} {stamps} {required} {reward} {store} {code} {program}
		'tpl_stamp'            => __( 'Hi {name}! You earned a stamp at {store}. Progress: {stamps}/{required} on "{program}".', 'loyalty-stamps' ),
		'tpl_complete'         => __( 'Congratulations {name}! You completed "{program}" — your reward: {reward}. Code: {code}', 'loyalty-stamps' ),
		'tpl_reward_expiry'    => __( 'Hi {name}, your reward "{reward}" (code {code}) expires soon. Come claim it at {store}!', 'loyalty-stamps' ),
		'tpl_program_change'   => __( 'Update to "{program}": {change}', 'loyalty-stamps' ),
		'tpl_nearby'           => __( 'You are near {store}! Pass by today — your loyalty card is waiting for another stamp.', 'loyalty-stamps' ),
		'tpl_welcome'          => __( 'Welcome {name}! Your loyalty card is ready. Save your personal link to collect stamps.', 'loyalty-stamps' ),
		'tpl_birthday'         => __( 'Happy birthday {name}! A gift is waiting for you at {store}: {reward}. Code: {code}', 'loyalty-stamps' ),
		'tpl_referral'         => __( 'Thanks {name}! Your friend joined with your link — you earned a bonus stamp on "{program}".', 'loyalty-stamps' ),
	);
	if ( function_exists( 'ls_wallet_default_settings' ) ) {
		$defaults = array_merge( $defaults, ls_wallet_default_settings() );
	}
	return $defaults;
}

/** Seed settings on first install (random staff PIN). */
function ls_settings_seed() {
	$existing = get_option( 'ls_settings', null );
	if ( is_array( $existing ) ) {
		return;
	}
	$defaults = ls_default_settings();
	$pin      = (string) wp_rand( 100000, 999999 );
	$defaults['staff_pin_hash']  = password_hash( $pin, PASSWORD_DEFAULT );
	$defaults['birthday_reward'] = __( 'Birthday dessert', 'loyalty-stamps' );
	$defaults['vapid_subject']   = 'mailto:' . get_option( 'admin_email' );
	update_option( 'ls_settings', $defaults );
	// Keep the plain PIN retrievable once for the admin (shown on Settings page until regenerated).
	update_option( 'ls_staff_pin_plain', $pin, false );
}

/** Get one setting. */
function ls_setting( $key, $default = null ) {
	$all = get_option( 'ls_settings', array() );
	if ( ! is_array( $all ) ) {
		$all = array();
	}
	if ( array_key_exists( $key, $all ) ) {
		return $all[ $key ];
	}
	$defaults = ls_default_settings();
	return array_key_exists( $key, $defaults ) ? $defaults[ $key ] : $default;
}

/** Update settings (array merge). */
function ls_update_settings( $data ) {
	$all = get_option( 'ls_settings', array() );
	if ( ! is_array( $all ) ) {
		$all = array();
	}
	foreach ( $data as $k => $v ) {
		$all[ $k ] = $v;
	}
	update_option( 'ls_settings', $all );
}

/* -------------------------------------------------------------------------
 * Staff PIN
 * ---------------------------------------------------------------------- */

/** Verify staff PIN. */
function ls_verify_staff_pin( $pin ) {
	$hash = ls_setting( 'staff_pin_hash', '' );
	if ( ! $hash ) {
		return false;
	}
	return password_verify( (string) $pin, $hash );
}

/** Regenerate staff PIN, return the new plain PIN. */
function ls_regenerate_staff_pin() {
	$pin = (string) wp_rand( 100000, 999999 );
	ls_update_settings( array( 'staff_pin_hash' => password_hash( $pin, PASSWORD_DEFAULT ) ) );
	update_option( 'ls_staff_pin_plain', $pin, false );
	return $pin;
}

/** Set a custom staff PIN (4-8 digits). Returns true or WP_Error. */
function ls_set_staff_pin( $pin ) {
	$pin = preg_replace( '/\D/', '', (string) $pin );
	if ( strlen( $pin ) < 4 || strlen( $pin ) > 8 ) {
		return new WP_Error( 'ls_pin', __( 'The staff PIN must be 4 to 8 digits.', 'loyalty-stamps' ) );
	}
	ls_update_settings( array( 'staff_pin_hash' => password_hash( $pin, PASSWORD_DEFAULT ) ) );
	update_option( 'ls_staff_pin_plain', $pin, false );
	return true;
}
