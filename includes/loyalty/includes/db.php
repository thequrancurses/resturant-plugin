<?php
/**
 * Database: custom tables, install/upgrade, seed data.
 */
defined( 'ABSPATH' ) || exit;

/** Current DB schema version. Bump when tables change. */
function ls_db_version() {
	return '1.5.7';
}

/** Create / upgrade all tables. */
function ls_db_install() {
	global $wpdb;
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';

	$charset = $wpdb->get_charset_collate();
	$p       = $wpdb->prefix;

	$sql = array();

	$sql[] = "CREATE TABLE {$p}ls_programs (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL,
		description TEXT NULL,
		stamps_required INT UNSIGNED NOT NULL DEFAULT 8,
		reward_title VARCHAR(191) NOT NULL DEFAULT '',
		reward_type VARCHAR(20) NOT NULL DEFAULT 'custom',
		reward_description TEXT NULL,
		color VARCHAR(7) NOT NULL DEFAULT '#d97706',
		active TINYINT(1) NOT NULL DEFAULT 1,
		card_expiry_days INT UNSIGNED NOT NULL DEFAULT 365,
		max_completions INT UNSIGNED NOT NULL DEFAULT 0,
		sort_order INT NOT NULL DEFAULT 0,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY active (active)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_customers (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL DEFAULT '',
		phone VARCHAR(40) NOT NULL,
		email VARCHAR(191) NULL,
		lang VARCHAR(10) NOT NULL DEFAULT 'ar',
		birthdate DATE NULL,
		token VARCHAR(64) NOT NULL,
		referral_code VARCHAR(16) NOT NULL,
		referred_by BIGINT UNSIGNED NULL,
		notify_email TINYINT(1) NOT NULL DEFAULT 1,
		notify_push TINYINT(1) NOT NULL DEFAULT 1,
		notify_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
		location_optout TINYINT(1) NOT NULL DEFAULT 0,
		last_lat DECIMAL(10,7) NULL,
		last_lng DECIMAL(10,7) NULL,
		last_location_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY phone (phone),
		UNIQUE KEY token (token),
		UNIQUE KEY referral_code (referral_code),
		KEY referred_by (referred_by)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_cards (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		customer_id BIGINT UNSIGNED NOT NULL,
		program_id BIGINT UNSIGNED NOT NULL,
		stamps INT UNSIGNED NOT NULL DEFAULT 0,
		status VARCHAR(20) NOT NULL DEFAULT 'active',
		expires_at DATETIME NULL,
		completed_at DATETIME NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY customer_id (customer_id),
		KEY program_id (program_id),
		KEY status (status)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_transactions (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		card_id BIGINT UNSIGNED NULL,
		customer_id BIGINT UNSIGNED NOT NULL,
		program_id BIGINT UNSIGNED NULL,
		type VARCHAR(20) NOT NULL DEFAULT 'stamp',
		delta INT NOT NULL DEFAULT 0,
		staff_note VARCHAR(191) NULL,
		created_by BIGINT UNSIGNED NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY card_id (card_id),
		KEY customer_id (customer_id),
		KEY program_id (program_id),
		KEY type (type),
		KEY created_at (created_at)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_rewards (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		customer_id BIGINT UNSIGNED NOT NULL,
		program_id BIGINT UNSIGNED NULL,
		card_id BIGINT UNSIGNED NULL,
		code VARCHAR(32) NOT NULL,
		title VARCHAR(191) NOT NULL DEFAULT '',
		status VARCHAR(20) NOT NULL DEFAULT 'issued',
		issued_at DATETIME NOT NULL,
		expires_at DATETIME NULL,
		redeemed_at DATETIME NULL,
		PRIMARY KEY (id),
		UNIQUE KEY code (code),
		KEY customer_id (customer_id),
		KEY status (status)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_notifications (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		customer_id BIGINT UNSIGNED NULL,
		channel VARCHAR(20) NOT NULL DEFAULT 'inbox',
		kind VARCHAR(60) NOT NULL DEFAULT '',
		title VARCHAR(191) NOT NULL DEFAULT '',
		message TEXT NOT NULL,
		payload TEXT NULL,
		status VARCHAR(20) NOT NULL DEFAULT 'queued',
		attempts INT UNSIGNED NOT NULL DEFAULT 0,
		scheduled_at DATETIME NULL,
		sent_at DATETIME NULL,
		error TEXT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY customer_id (customer_id),
		KEY status (status),
		KEY kind (kind),
		KEY scheduled_at (scheduled_at)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_stores (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		name VARCHAR(191) NOT NULL,
		address VARCHAR(255) NULL,
		lat DECIMAL(10,7) NULL,
		lng DECIMAL(10,7) NULL,
		radius_km DECIMAL(5,2) NOT NULL DEFAULT 1.00,
		promo_message TEXT NULL,
		active TINYINT(1) NOT NULL DEFAULT 1,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY active (active)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_program_log (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		program_id BIGINT UNSIGNED NOT NULL,
		changed_by BIGINT UNSIGNED NULL,
		summary TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY program_id (program_id)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_wallet_devices (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		serial VARCHAR(64) NOT NULL,
		device_id VARCHAR(191) NOT NULL,
		push_token TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		updated_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		KEY serial (serial),
		KEY device_id (device_id)
	) $charset;";

	$sql[] = "CREATE TABLE {$p}ls_push_subs (
		id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
		customer_id BIGINT UNSIGNED NOT NULL,
		endpoint TEXT NOT NULL,
		endpoint_hash VARCHAR(64) NOT NULL,
		p256dh TEXT NOT NULL,
		auth TEXT NOT NULL,
		created_at DATETIME NOT NULL,
		PRIMARY KEY (id),
		UNIQUE KEY endpoint_hash (endpoint_hash),
		KEY customer_id (customer_id)
	) $charset;";

	foreach ( $sql as $statement ) {
		dbDelta( $statement );
	}

	update_option( 'ls_db_version', ls_db_version() );
	ls_db_seed();
}

/** Seed default program, store and staff PIN on first install. */
function ls_db_seed() {
	global $wpdb;
	$p  = $wpdb->prefix;
	$now = current_time( 'mysql' );

	$programs = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_programs" );
	if ( 0 === $programs ) {
		$wpdb->insert(
			"{$p}ls_programs",
			array(
				'name'             => __( 'Grill Hub Loyalty Card', 'loyalty-stamps' ),
				'description'      => __( 'Collect stamps with every visit. Complete the card and get your reward!', 'loyalty-stamps' ),
				'stamps_required'  => 8,
				'reward_title'     => __( 'Free meal', 'loyalty-stamps' ),
				'reward_type'      => 'sandwich',
				'reward_description' => __( 'Show this reward code at the counter to claim it.', 'loyalty-stamps' ),
				'color'            => '#d97706',
				'active'           => 1,
				'card_expiry_days' => 365,
				'created_at'       => $now,
				'updated_at'       => $now,
			)
		);
	}

	$stores = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_stores" );
	if ( 0 === $stores ) {
		$wpdb->insert(
			"{$p}ls_stores",
			array(
				'name'       => 'Grill Hub',
				'address'    => 'Katowice, Poland',
				'radius_km'  => 1.00,
				'active'     => 1,
				'created_at' => $now,
			)
		);
	}
}
