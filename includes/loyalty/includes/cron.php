<?php
/**
 * Cron: notification queue processing + daily maintenance.
 */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Schedules
 * ---------------------------------------------------------------------- */

add_filter( 'cron_schedules', 'ls_cron_schedules' );
/**
 * Register the custom "every 5 minutes" cron schedule.
 *
 * @param array $schedules Registered schedules.
 * @return array Modified schedules.
 */
function ls_cron_schedules( $schedules ) {
	$schedules['ls_every_5min'] = array(
		'interval' => 5 * MINUTE_IN_SECONDS,
		'display'  => __( 'Every 5 minutes', 'loyalty-stamps' ),
	);
	return $schedules;
}

/** Schedule the queue (every 5 min) and daily maintenance cron events. */
function ls_cron_schedule() {
	if ( ! wp_next_scheduled( 'ls_queue_tick' ) ) {
		wp_schedule_event( time(), 'ls_every_5min', 'ls_queue_tick' );
	}
	if ( ! wp_next_scheduled( 'ls_daily_tick' ) ) {
		wp_schedule_event( strtotime( 'tomorrow 03:00' ), 'daily', 'ls_daily_tick' );
	}
}

/** Remove all scheduled loyalty cron events (runs on deactivation). */
function ls_cron_unschedule() {
	foreach ( array( 'ls_queue_tick', 'ls_daily_tick' ) as $hook ) {
		$ts = wp_next_scheduled( $hook );
		while ( $ts ) {
			wp_unschedule_event( $ts, $hook );
			$ts = wp_next_scheduled( $hook );
		}
	}
}

/* -------------------------------------------------------------------------
 * Tick callbacks
 * ---------------------------------------------------------------------- */

add_action( 'ls_queue_tick', 'ls_cron_queue_tick' );
/** Cron callback: process the notification queue. */
function ls_cron_queue_tick() {
	ls_process_queue( 50 );
}

add_action( 'ls_daily_tick', 'ls_cron_daily_tick' );
/**
 * Cron callback: daily maintenance — expire rewards/cards, send expiry
 * reminders and birthday rewards, clean up old notification logs.
 */
function ls_cron_daily_tick() {
	global $wpdb;
	$p = $wpdb->prefix;

	// Expire old rewards and cards.
	ls_expire_rewards();
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$p}ls_cards SET status = 'expired', updated_at = %s
			 WHERE status = 'active' AND expires_at IS NOT NULL AND expires_at < %s",
			ls_now(),
			ls_now()
		)
	);

	// Reward expiry reminders (3 days before, once per reward).
	$soon = date( 'Y-m-d H:i:s', strtotime( '+3 days', current_time( 'timestamp' ) ) );
	$rewards = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$p}ls_rewards
			 WHERE status = 'issued' AND expires_at IS NOT NULL AND expires_at <= %s",
			$soon
		)
	);
	foreach ( $rewards as $r ) {
		$already = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}ls_notifications
				 WHERE kind = 'reward_expiry' AND payload LIKE %s AND created_at >= %s",
				'%"reward_id":' . (int) $r->id . '%',
				date( 'Y-m-d H:i:s', strtotime( '-7 days', current_time( 'timestamp' ) ) )
			)
		);
		if ( $already ) {
			continue;
		}
		ls_notify(
			(int) $r->customer_id,
			'reward_expiry',
			__( 'Reward expiring soon', 'loyalty-stamps' ),
			ls_tpl(
				'tpl_reward_expiry',
				array( 'reward' => $r->title, 'code' => $r->code ),
				(int) $r->customer_id
			),
			array( 'reward_id' => (int) $r->id )
		);
	}

	// Birthday rewards.
	if ( ls_setting( 'birthday_enabled', 1 ) && '' !== trim( (string) ls_setting( 'birthday_reward', '' ) ) ) {
		$today = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$p}ls_customers
				 WHERE birthdate IS NOT NULL
				 AND MONTH(birthdate) = %d AND DAY(birthdate) = %d",
				(int) current_time( 'm' ),
				(int) current_time( 'd' )
			)
		);
		foreach ( $today as $row ) {
			// Avoid double-issuing: same reward title issued today.
			$done = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$p}ls_rewards
					 WHERE customer_id = %d AND title = %s AND issued_at >= %s",
					(int) $row->id,
					ls_setting( 'birthday_reward', '' ),
					date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) )
				)
			);
			if ( $done ) {
				continue;
			}
			$reward = ls_issue_reward( (int) $row->id, null, null, ls_setting( 'birthday_reward', '' ) );
			if ( $reward ) {
				ls_notify(
					(int) $row->id,
					'birthday',
					__( 'Happy birthday!', 'loyalty-stamps' ),
					ls_tpl(
						'tpl_birthday',
						array( 'reward' => $reward->title, 'code' => $reward->code ),
						(int) $row->id
					),
					array( 'reward_id' => (int) $reward->id )
				);
			}
		}
	}

	// Cleanup: old queued/failed/sent notification logs (> 90 days), stale locations (> 30 days).
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$p}ls_notifications WHERE created_at < %s",
			date( 'Y-m-d H:i:s', strtotime( '-90 days', current_time( 'timestamp' ) ) )
		)
	);
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$p}ls_customers SET last_lat = NULL, last_lng = NULL
			 WHERE last_location_at < %s",
			date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) )
		)
	);
}
