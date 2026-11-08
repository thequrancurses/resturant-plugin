<?php
/**
 * Core logic: customers, programs, cards, stamps, rewards.
 */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Get the current time in MySQL format. */
function ls_now() {
	return current_time( 'mysql' );
}

/**
 * Generate a cryptographically secure random hex token.
 *
 * @param int $bytes Number of random bytes (default 16).
 * @return string Hex-encoded token.
 */
function ls_rand_token( $bytes = 16 ) {
	return bin2hex( random_bytes( $bytes ) );
}

/** Normalize phone: keep digits and leading +. */
function ls_normalize_phone( $phone ) {
	$phone = trim( (string) $phone );
	$plus  = ( strpos( $phone, '+' ) === 0 ) ? '+' : '';
	$phone = preg_replace( '/\D+/', '', $phone );
	return $plus . $phone;
}

/* -------------------------------------------------------------------------
 * Customers
 * ---------------------------------------------------------------------- */

/**
 * Create a new customer with a unique token and referral code.
 *
 * Handles referral bonuses and sends the welcome notification.
 *
 * @param array $data Customer data (name, phone, email, lang, birthdate, referral).
 * @return int|WP_Error Customer ID on success, WP_Error on failure.
 */
function ls_create_customer( $data ) {
	global $wpdb;
	$p = $wpdb->prefix;

	$phone = ls_normalize_phone( $data['phone'] ?? '' );
	if ( '' === $phone ) {
		return new WP_Error( 'ls_phone_required', __( 'Phone number is required.', 'loyalty-stamps' ) );
	}
	if ( ls_get_customer_by_phone( $phone ) ) {
		return new WP_Error( 'ls_phone_exists', __( 'This phone number is already registered.', 'loyalty-stamps' ) );
	}

	$referred_by = null;
	if ( ! empty( $data['referral'] ) ) {
		$ref = ls_get_customer_by_referral( sanitize_text_field( $data['referral'] ) );
		if ( $ref ) {
			$referred_by = (int) $ref->id;
		}
	}

	$birthdate = null;
	if ( ! empty( $data['birthdate'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['birthdate'] ) ) {
		$birthdate = $data['birthdate'];
	}

	// Unique referral code.
	do {
		$refcode = strtoupper( substr( ls_rand_token( 4 ), 0, 8 ) );
		$exists  = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ls_customers WHERE referral_code = %s", $refcode ) );
	} while ( $exists );

	$ok = $wpdb->insert(
		"{$p}ls_customers",
		array(
			'name'         => sanitize_text_field( $data['name'] ?? '' ),
			'phone'        => $phone,
			'email'        => ! empty( $data['email'] ) ? sanitize_email( $data['email'] ) : null,
			'lang'         => sanitize_text_field( $data['lang'] ?? 'ar' ),
			'birthdate'    => $birthdate,
			'token'        => ls_rand_token( 24 ),
			'referral_code' => $refcode,
			'referred_by'  => $referred_by,
			'created_at'   => ls_now(),
			'updated_at'   => ls_now(),
		)
	);
	if ( ! $ok ) {
		return new WP_Error( 'ls_db', __( 'Could not create customer.', 'loyalty-stamps' ) );
	}
	$id = (int) $wpdb->insert_id;

	// Referral bonus: referrer + new customer each get a bonus stamp on the default program.
	if ( $referred_by && ls_setting( 'referral_enabled', 1 ) ) {
		$bonus   = max( 1, (int) ls_setting( 'referral_bonus', 1 ) );
		$program = ls_default_program();
		if ( $program ) {
			for ( $i = 0; $i < $bonus; $i++ ) {
				ls_add_stamp( $referred_by, (int) $program->id, array( 'type' => 'bonus', 'note' => 'referral' ) );
				ls_add_stamp( $id, (int) $program->id, array( 'type' => 'bonus', 'note' => 'welcome' ) );
			}
			ls_notify(
				$referred_by,
				'referral',
				__( 'Referral bonus', 'loyalty-stamps' ),
				ls_tpl( 'tpl_referral', array( 'program' => $program->name ), $referred_by )
			);
		}
	}

	// Welcome notification.
	ls_notify(
		$id,
		'welcome',
		__( 'Welcome!', 'loyalty-stamps' ),
		ls_tpl( 'tpl_welcome', array(), $id )
	);

	return $id;
}

/**
 * Get a customer by ID.
 *
 * @param int $id Customer ID.
 * @return object|null Customer row or null.
 */
function ls_get_customer( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_customers WHERE id = %d", $id ) );
}

/**
 * Get a customer by phone number (normalized before lookup).
 *
 * @param string $phone Phone number.
 * @return object|null Customer row or null.
 */
function ls_get_customer_by_phone( $phone ) {
	global $wpdb;
	$phone = ls_normalize_phone( $phone );
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_customers WHERE phone = %s", $phone ) );
}

/**
 * Get a customer by card token (sanitized to hex characters).
 *
 * @param string $token Card token.
 * @return object|null Customer row or null.
 */
function ls_get_customer_by_token( $token ) {
	global $wpdb;
	$token = preg_replace( '/[^a-f0-9]/i', '', (string) $token );
	if ( '' === $token ) {
		return null;
	}
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_customers WHERE token = %s", $token ) );
}

/**
 * Get a customer by referral code.
 *
 * @param string $code Referral code.
 * @return object|null Customer row or null.
 */
function ls_get_customer_by_referral( $code ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_customers WHERE referral_code = %s", strtoupper( $code ) ) );
}

/**
 * Update allowed customer fields.
 *
 * @param int   $id   Customer ID.
 * @param array $data Fields to update (whitelisted).
 * @return bool True on success.
 */
function ls_update_customer( $id, $data ) {
	global $wpdb;
	$allowed = array( 'name', 'email', 'lang', 'birthdate', 'notify_email', 'notify_push', 'notify_whatsapp', 'location_optout' );
	$update  = array();
	foreach ( $allowed as $k ) {
		if ( array_key_exists( $k, $data ) ) {
			$update[ $k ] = $data[ $k ];
		}
	}
	if ( empty( $update ) ) {
		return true;
	}
	$update['updated_at'] = ls_now();
	return false !== $wpdb->update( "{$wpdb->prefix}ls_customers", $update, array( 'id' => (int) $id ) );
}

/** Delete customer and all related data (GDPR). */
function ls_delete_customer( $id ) {
	global $wpdb;
	$p  = $wpdb->prefix;
	$id = (int) $id;
	$wpdb->delete( "{$p}ls_push_subs", array( 'customer_id' => $id ) );
	$wpdb->delete( "{$p}ls_notifications", array( 'customer_id' => $id ) );
	$wpdb->delete( "{$p}ls_rewards", array( 'customer_id' => $id ) );
	$wpdb->delete( "{$p}ls_transactions", array( 'customer_id' => $id ) );
	$wpdb->delete( "{$p}ls_cards", array( 'customer_id' => $id ) );
	$wpdb->update( "{$p}ls_customers", array( 'referred_by' => null ), array( 'referred_by' => $id ) );
	return false !== $wpdb->delete( "{$p}ls_customers", array( 'id' => $id ) );
}

/* -------------------------------------------------------------------------
 * Programs
 * ---------------------------------------------------------------------- */

/** Translated "any sandwich of the customer's choice" reward title. */
function ls_sandwich_reward_title( $lang = '' ) {
	if ( ! in_array( $lang, array( 'pl', 'en', 'ar' ), true ) ) {
		$lang = function_exists( 'ls_default_lang' ) ? ls_default_lang() : 'pl';
	}
	$map = array(
		'pl' => 'Dowolna kanapka gratis',
		'en' => 'Any sandwich of your choice',
		'ar' => 'أي ساندوتش من اختيارك',
	);
	return $map[ $lang ];
}

/** Effective reward title for a program: the sandwich text when reward_type is 'sandwich', else the custom title. */
function ls_program_reward_title( $program, $lang = '' ) {
	if ( is_object( $program ) && 'sandwich' === ( $program->reward_type ?? '' ) ) {
		return ls_sandwich_reward_title( $lang );
	}
	$t = is_object( $program ) ? trim( (string) ( $program->reward_title ?? '' ) ) : '';
	return '' !== $t ? $t : __( 'Free reward', 'loyalty-stamps' );
}

/**
 * Get all loyalty programs.
 *
 * @param bool $active_only Only return active programs.
 * @return array List of program rows.
 */
function ls_get_programs( $active_only = false ) {
	global $wpdb;
	$sql = "SELECT * FROM {$wpdb->prefix}ls_programs";
	if ( $active_only ) {
		$sql .= ' WHERE active = 1';
	}
	$sql .= ' ORDER BY sort_order ASC, id ASC';
	return $wpdb->get_results( $sql );
}

/**
 * Get a program by ID.
 *
 * @param int $id Program ID.
 * @return object|null Program row or null.
 */
function ls_get_program( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_programs WHERE id = %d", $id ) );
}

/** First active program (used for referral/welcome bonuses). */
function ls_default_program() {
	$programs = ls_get_programs( true );
	return $programs ? $programs[0] : null;
}

/**
 * Create a new loyalty program.
 *
 * @param array $data Program data (name, description, stamps_required, reward_*, color, active, ...).
 * @return int|false New program ID on success, false on failure.
 */
function ls_create_program( $data ) {
	global $wpdb;
	$now = ls_now();
	$ok  = $wpdb->insert(
		"{$wpdb->prefix}ls_programs",
		array(
			'name'              => sanitize_text_field( $data['name'] ?? '' ),
			'description'       => sanitize_textarea_field( $data['description'] ?? '' ),
			'stamps_required'   => max( 1, (int) ( $data['stamps_required'] ?? 8 ) ),
			'reward_title'      => sanitize_text_field( $data['reward_title'] ?? '' ),
		'reward_type'       => ( ( $data['reward_type'] ?? '' ) === 'sandwich' ) ? 'sandwich' : 'custom',
			'reward_description' => sanitize_textarea_field( $data['reward_description'] ?? '' ),
			'color'             => sanitize_hex_color( $data['color'] ?? '#d97706' ) ?: '#d97706',
			'active'            => ! empty( $data['active'] ) ? 1 : 0,
			'card_expiry_days'  => max( 0, (int) ( $data['card_expiry_days'] ?? 365 ) ),
			'max_completions'   => max( 0, (int) ( $data['max_completions'] ?? 0 ) ),
			'created_at'        => $now,
			'updated_at'        => $now,
		)
	);
	return $ok ? (int) $wpdb->insert_id : false;
}

/**
 * Update program. If $notify is true, active card holders are notified and the
 * change is written to the program changelog.
 */
function ls_update_program( $id, $data, $notify = false, $change_summary = '' ) {
	global $wpdb;
	$p  = $wpdb->prefix;
	$id = (int) $id;

	$update = array(
		'name'               => sanitize_text_field( $data['name'] ?? '' ),
		'description'        => sanitize_textarea_field( $data['description'] ?? '' ),
		'stamps_required'    => max( 1, (int) ( $data['stamps_required'] ?? 8 ) ),
		'reward_title'       => sanitize_text_field( $data['reward_title'] ?? '' ),
		'reward_type'        => ( ( $data['reward_type'] ?? '' ) === 'sandwich' ) ? 'sandwich' : 'custom',
		'reward_description' => sanitize_textarea_field( $data['reward_description'] ?? '' ),
		'color'              => sanitize_hex_color( $data['color'] ?? '#d97706' ) ?: '#d97706',
		'active'             => ! empty( $data['active'] ) ? 1 : 0,
		'card_expiry_days'   => max( 0, (int) ( $data['card_expiry_days'] ?? 365 ) ),
		'max_completions'    => max( 0, (int) ( $data['max_completions'] ?? 0 ) ),
		'updated_at'         => ls_now(),
	);
	$ok = false !== $wpdb->update( "{$p}ls_programs", $update, array( 'id' => $id ) );

	$summary = trim( $change_summary );
	if ( '' === $summary ) {
		$summary = sprintf(
			/* translators: %s: program name */
			__( 'Program "%s" was updated.', 'loyalty-stamps' ),
			$update['name']
		);
	}
	ls_log_program_change( $id, $summary );

	if ( $notify ) {
		return ls_notify_program_change( $id, $summary ); // returns number of customers notified
	}
	return $ok;
}

/** Write an entry to the program changelog. */
function ls_log_program_change( $program_id, $summary ) {
	global $wpdb;
	$wpdb->insert(
		"{$wpdb->prefix}ls_program_log",
		array(
			'program_id' => (int) $program_id,
			'changed_by' => get_current_user_id() ?: null,
			'summary'    => $summary,
			'created_at' => ls_now(),
		)
	);
}

/** Notify all customers holding an active card of this program. */
function ls_notify_program_change( $program_id, $summary ) {
	global $wpdb;
	$p       = $wpdb->prefix;
	$program = ls_get_program( $program_id );
	if ( ! $program ) {
		return 0;
	}
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT customer_id FROM {$p}ls_cards WHERE program_id = %d AND status = 'active'",
			$program_id
		)
	);
	$n = 0;
	foreach ( $ids as $cid ) {
		ls_notify(
			(int) $cid,
			'program_change',
			sprintf( __( 'Update: %s', 'loyalty-stamps' ), $program->name ),
			ls_tpl( 'tpl_program_change', array( 'program' => $program->name, 'change' => $summary ), (int) $cid )
		);
		$n++;
	}
	return $n;
}

/* -------------------------------------------------------------------------
 * Cards & stamps
 * ---------------------------------------------------------------------- */

/** Get the customer's active card for a program, creating one if needed. */
function ls_get_or_create_card( $customer_id, $program_id ) {
	global $wpdb;
	$p           = $wpdb->prefix;
	$customer_id = (int) $customer_id;
	$program_id  = (int) $program_id;

	$program = ls_get_program( $program_id );
	if ( ! $program || ! $program->active ) {
		return new WP_Error( 'ls_program_inactive', __( 'This loyalty program is not active.', 'loyalty-stamps' ) );
	}

	$card = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM {$p}ls_cards WHERE customer_id = %d AND program_id = %d AND status = 'active' ORDER BY id DESC LIMIT 1",
			$customer_id,
			$program_id
		)
	);

	// Expired card? close it and start a fresh one.
	if ( $card && $card->expires_at && $card->expires_at < ls_now() ) {
		$wpdb->update( "{$p}ls_cards", array( 'status' => 'expired', 'updated_at' => ls_now() ), array( 'id' => $card->id ) );
		ls_log_transaction( $card->id, $customer_id, $program_id, 'expire', 0, '' );
		$card = null;
	}

	if ( ! $card ) {
		// Respect max completions per customer.
		if ( (int) $program->max_completions > 0 ) {
			$done = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}ls_cards WHERE customer_id = %d AND program_id = %d AND status = 'completed'",
					$customer_id,
					$program_id
				)
			);
			if ( $done >= (int) $program->max_completions ) {
				return new WP_Error( 'ls_max_reached', __( 'Maximum number of completed cards reached for this program.', 'loyalty-stamps' ) );
			}
		}
		$expiry_days = (int) $program->card_expiry_days;
		$expires_at  = $expiry_days > 0 ? date( 'Y-m-d H:i:s', strtotime( '+' . $expiry_days . ' days', current_time( 'timestamp' ) ) ) : null;
		$wpdb->insert(
			"{$p}ls_cards",
			array(
				'customer_id' => $customer_id,
				'program_id'  => $program_id,
				'stamps'      => 0,
				'status'      => 'active',
				'expires_at'  => $expires_at,
				'created_at'  => ls_now(),
				'updated_at'  => ls_now(),
			)
		);
		$card = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ls_cards WHERE id = %d", $wpdb->insert_id ) );
	}
	return $card;
}

/**
 * Get a customer's cards with program details.
 *
 * @param int    $customer_id Customer ID.
 * @param string $status      Optional card status filter.
 * @return array List of card rows with program fields.
 */
function ls_get_customer_cards( $customer_id, $status = '' ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$sql = "SELECT c.*, pr.name AS program_name, pr.stamps_required, pr.reward_title, pr.reward_type, pr.color
			FROM {$p}ls_cards c
			JOIN {$p}ls_programs pr ON pr.id = c.program_id
			WHERE c.customer_id = %d";
	$args = array( (int) $customer_id );
	if ( '' !== $status ) {
		$sql   .= ' AND c.status = %s';
		$args[] = $status;
	}
	$sql .= ' ORDER BY c.status = \'active\' DESC, c.updated_at DESC';
	return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

/**
 * Add a stamp (or bonus stamp) to a customer's card.
 *
 * $args: type ('stamp'|'bonus'), note, created_by
 * Returns array( card, completed(bool), reward(object|null) ) or WP_Error.
 */
function ls_add_stamp( $customer_id, $program_id, $args = array() ) {
	global $wpdb;
	$p = $wpdb->prefix;

	$type = ( $args['type'] ?? 'stamp' ) === 'bonus' ? 'bonus' : 'stamp';

	$card = ls_get_or_create_card( $customer_id, $program_id );
	if ( is_wp_error( $card ) ) {
		return $card;
	}
	$program = ls_get_program( $program_id );

	// Anti-fraud: max stamps per card per day.
	if ( 'stamp' === $type ) {
		$max = (int) ls_setting( 'max_stamps_per_day', 5 );
		if ( $max > 0 ) {
			$today = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}ls_transactions
					 WHERE card_id = %d AND type = 'stamp' AND created_at >= %s",
					$card->id,
					date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) )
				)
			);
			if ( $today >= $max ) {
				return new WP_Error( 'ls_daily_limit', __( 'Daily stamp limit reached for this card.', 'loyalty-stamps' ) );
			}
		}

		// Anti-abuse: minimum hours between two stamps on the same card.
		$cooldown_h = (int) ls_setting( 'stamp_cooldown_h', 12 );
		if ( $cooldown_h > 0 ) {
			$last = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT created_at FROM {$p}ls_transactions
					 WHERE card_id = %d AND type = 'stamp'
					 ORDER BY created_at DESC LIMIT 1",
					$card->id
				)
			);
			if ( $last ) {
				$elapsed = current_time( 'timestamp' ) - strtotime( $last );
				if ( $elapsed < $cooldown_h * HOUR_IN_SECONDS ) {
					$wait_h = ( $cooldown_h * HOUR_IN_SECONDS - $elapsed ) / 3600;
					if ( $wait_h < 1 ) {
						$msg = sprintf(
							/* translators: %d: minutes */
							__( 'A stamp was already added to this card recently. Next stamp in about %d minutes.', 'loyalty-stamps' ),
							(int) ceil( $wait_h * 60 )
						);
					} else {
						$msg = sprintf(
							/* translators: %d: hours */
							__( 'A stamp was already added to this card recently. Next stamp in about %d hours.', 'loyalty-stamps' ),
							(int) ceil( $wait_h )
						);
					}
					return new WP_Error( 'ls_stamp_cooldown', $msg );
				}
			}
		}
	}

	$new_stamps = (int) $card->stamps + 1;
	$wpdb->update(
		"{$p}ls_cards",
		array( 'stamps' => $new_stamps, 'updated_at' => ls_now() ),
		array( 'id' => $card->id )
	);
	ls_log_transaction( $card->id, $customer_id, $program_id, $type, 1, $args['note'] ?? '', $args['created_by'] ?? null );

	$completed = false;
	$reward    = null;

	if ( $new_stamps >= (int) $program->stamps_required ) {
		$completed = true;
		$wpdb->update(
			"{$p}ls_cards",
			array( 'status' => 'completed', 'completed_at' => ls_now(), 'updated_at' => ls_now() ),
			array( 'id' => $card->id )
		);
		$customer      = ls_get_customer( $customer_id );
		$customer_lang = $customer ? (string) $customer->lang : '';
		$reward_title  = ls_program_reward_title( $program, $customer_lang );
		$reward = ls_issue_reward( $customer_id, $program_id, $card->id, $reward_title );
		ls_notify(
			$customer_id,
			'card_complete',
			sprintf( __( 'Card completed: %s', 'loyalty-stamps' ), $program->name ),
			ls_tpl(
				'tpl_complete',
				array(
					'program' => $program->name,
					'reward'  => $reward_title,
					'code'    => $reward ? $reward->code : '',
				),
				$customer_id
			)
		);
	} else {
		ls_notify(
			$customer_id,
			'stamp',
			sprintf( __( 'Stamp added: %s', 'loyalty-stamps' ), $program->name ),
			ls_tpl(
				'tpl_stamp',
				array(
					'program'  => $program->name,
					'stamps'   => $new_stamps,
					'required' => $program->stamps_required,
				),
				$customer_id
			)
		);
	}

	$card->stamps = $new_stamps;
	$card->status = $completed ? 'completed' : 'active';

	// Push the new state to Apple / Google Wallets (silent when unconfigured).
	if ( function_exists( 'ls_wallet_on_card_change' ) ) {
		ls_wallet_on_card_change( (int) $customer_id );
	}

	return array( 'card' => $card, 'completed' => $completed, 'reward' => $reward );
}

/**
 * Write a transaction log entry (stamp, bonus, redeem, expire).
 *
 * @param int|null $card_id     Card ID.
 * @param int      $customer_id Customer ID.
 * @param int|null $program_id  Program ID.
 * @param string   $type        Transaction type.
 * @param int      $delta       Stamp delta.
 * @param string   $note        Optional staff note.
 * @param int|null $created_by  Optional staff user ID.
 */
function ls_log_transaction( $card_id, $customer_id, $program_id, $type, $delta, $note = '', $created_by = null ) {
	global $wpdb;
	$wpdb->insert(
		"{$wpdb->prefix}ls_transactions",
		array(
			'card_id'     => $card_id ? (int) $card_id : null,
			'customer_id' => (int) $customer_id,
			'program_id'  => $program_id ? (int) $program_id : null,
			'type'        => $type,
			'delta'       => (int) $delta,
			'staff_note'  => substr( (string) $note, 0, 191 ),
			'created_by'  => $created_by ? (int) $created_by : null,
			'created_at'  => ls_now(),
		)
	);
}

/* -------------------------------------------------------------------------
 * Rewards
 * ---------------------------------------------------------------------- */

/**
 * Issue a reward with a unique code to a customer.
 *
 * @param int      $customer_id Customer ID.
 * @param int|null $program_id  Program ID.
 * @param int|null $card_id     Card ID.
 * @param string   $title       Reward title.
 * @return object Reward row.
 */
function ls_issue_reward( $customer_id, $program_id, $card_id, $title ) {
	global $wpdb;
	$p = $wpdb->prefix;

	do {
		$code   = 'LS-' . strtoupper( bin2hex( random_bytes( 4 ) ) );
		$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}ls_rewards WHERE code = %s", $code ) );
	} while ( $exists );

	$expiry_days = (int) ls_setting( 'reward_expiry_days', 60 );
	$expires_at  = $expiry_days > 0 ? date( 'Y-m-d H:i:s', strtotime( '+' . $expiry_days . ' days', current_time( 'timestamp' ) ) ) : null;

	$wpdb->insert(
		"{$p}ls_rewards",
		array(
			'customer_id' => (int) $customer_id,
			'program_id'  => $program_id ? (int) $program_id : null,
			'card_id'     => $card_id ? (int) $card_id : null,
			'code'        => $code,
			'title'       => $title,
			'status'      => 'issued',
			'issued_at'   => ls_now(),
			'expires_at'  => $expires_at,
		)
	);
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ls_rewards WHERE id = %d", $wpdb->insert_id ) );
}

/** Redeem a reward by code. Returns reward object or WP_Error. */
function ls_redeem_reward( $code, $staff_note = '', $created_by = null ) {
	global $wpdb;
	$p    = $wpdb->prefix;
	$code = strtoupper( trim( $code ) );

	$reward = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$p}ls_rewards WHERE code = %s", $code ) );
	if ( ! $reward ) {
		return new WP_Error( 'ls_reward_not_found', __( 'Reward code not found.', 'loyalty-stamps' ) );
	}
	if ( 'issued' !== $reward->status ) {
		return new WP_Error( 'ls_reward_used', __( 'This reward has already been used or expired.', 'loyalty-stamps' ) );
	}
	if ( $reward->expires_at && $reward->expires_at < ls_now() ) {
		$wpdb->update( "{$p}ls_rewards", array( 'status' => 'expired' ), array( 'id' => $reward->id ) );
		return new WP_Error( 'ls_reward_expired', __( 'This reward has expired.', 'loyalty-stamps' ) );
	}

	$wpdb->update(
		"{$p}ls_rewards",
		array( 'status' => 'redeemed', 'redeemed_at' => ls_now() ),
		array( 'id' => $reward->id )
	);
	ls_log_transaction( $reward->card_id, $reward->customer_id, $reward->program_id, 'redeem', 0, $staff_note, $created_by );

	$reward->status      = 'redeemed';
	$reward->redeemed_at = ls_now();
	return $reward;
}

/**
 * Get a customer's rewards.
 *
 * @param int    $customer_id Customer ID.
 * @param string $status      Optional reward status filter.
 * @return array List of reward rows.
 */
function ls_get_customer_rewards( $customer_id, $status = '' ) {
	global $wpdb;
	$p    = $wpdb->prefix;
	$sql  = "SELECT * FROM {$p}ls_rewards WHERE customer_id = %d";
	$args = array( (int) $customer_id );
	if ( '' !== $status ) {
		$sql   .= ' AND status = %s';
		$args[] = $status;
	}
	$sql .= ' ORDER BY issued_at DESC';
	return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
}

/** Expire old rewards (cron). Returns count. */
function ls_expire_rewards() {
	global $wpdb;
	$p   = $wpdb->prefix;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT id FROM {$p}ls_rewards WHERE status = 'issued' AND expires_at IS NOT NULL AND expires_at < %s",
			ls_now()
		)
	);
	foreach ( $ids as $id ) {
		$wpdb->update( "{$p}ls_rewards", array( 'status' => 'expired' ), array( 'id' => (int) $id ) );
	}
	return count( $ids );
}
