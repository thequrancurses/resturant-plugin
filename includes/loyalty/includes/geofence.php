<?php
/**
 * Geofence: customer location updates + "nearby within 1km" promos.
 */
defined( 'ABSPATH' ) || exit;

/** Haversine distance in km. */
function ls_haversine_km( $lat1, $lng1, $lat2, $lng2 ) {
	$R  = 6371.0;
	$dLat = deg2rad( (float) $lat2 - (float) $lat1 );
	$dLng = deg2rad( (float) $lng2 - (float) $lng1 );
	$a  = sin( $dLat / 2 ) * sin( $dLat / 2 )
		+ cos( deg2rad( (float) $lat1 ) ) * cos( deg2rad( (float) $lat2 ) )
		* sin( $dLng / 2 ) * sin( $dLng / 2 );
	return 2 * $R * asin( min( 1, sqrt( $a ) ) );
}

/** Are we inside quiet hours? Returns true when promos should NOT be sent. */
function ls_in_quiet_hours() {
	$from = (string) ls_setting( 'geofence_quiet_from', '22:00' );
	$to   = (string) ls_setting( 'geofence_quiet_to', '09:00' );
	if ( $from === $to ) {
		return false;
	}
	$now  = current_time( 'H:i' );
	if ( $from < $to ) {
		return $now >= $from && $now < $to;
	}
	// Overnight range, e.g. 22:00 -> 09:00.
	return $now >= $from || $now < $to;
}

/**
 * Handle a location update from a customer device.
 * Updates last known location and queues a "nearby" promo when inside a store radius.
 */
function ls_handle_location( $customer_id, $lat, $lng ) {
	global $wpdb;
	$customer_id = (int) $customer_id;
	$lat = (float) $lat;
	$lng = (float) $lng;

	if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
		return new WP_Error( 'ls_bad_location', __( 'Invalid coordinates.', 'loyalty-stamps' ) );
	}

	$customer = ls_get_customer( $customer_id );
	if ( ! $customer || (int) $customer->location_optout ) {
		return false;
	}

	$wpdb->update(
		"{$wpdb->prefix}ls_customers",
		array(
			'last_lat'         => $lat,
			'last_lng'         => $lng,
			'last_location_at' => ls_now(),
			'updated_at'       => ls_now(),
		),
		array( 'id' => $customer_id )
	);

	if ( ls_in_quiet_hours() ) {
		return array( 'nearby' => false, 'reason' => 'quiet_hours' );
	}

	$nearby = array();
	foreach ( ls_get_stores( true ) as $store ) {
		if ( null === $store->lat || null === $store->lng ) {
			continue;
		}
		$dist = ls_haversine_km( $lat, $lng, (float) $store->lat, (float) $store->lng );
		if ( $dist <= (float) $store->radius_km ) {
			if ( ls_queue_nearby( $customer_id, $store ) ) {
				$nearby[] = array( 'store' => $store->name, 'km' => round( $dist, 2 ) );
			}
		}
	}
	return array( 'nearby' => ! empty( $nearby ), 'stores' => $nearby );
}

/* -------------------------------------------------------------------------
 * Stores
 * ---------------------------------------------------------------------- */

/* -------------------------------------------------------------------------
 * Stores
 * ---------------------------------------------------------------------- */

/**
 * Get all stores.
 *
 * @param bool $active_only Only return active stores.
 * @return array List of store rows.
 */
function ls_get_stores( $active_only = false ) {
	global $wpdb;
	$sql = "SELECT * FROM {$wpdb->prefix}ls_stores";
	if ( $active_only ) {
		$sql .= ' WHERE active = 1';
	}
	$sql .= ' ORDER BY id ASC';
	return $wpdb->get_results( $sql );
}

/**
 * Get a store by ID.
 *
 * @param int $id Store ID.
 * @return object|null Store row or null.
 */
function ls_get_store( $id ) {
	global $wpdb;
	return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_stores WHERE id = %d", (int) $id ) );
}

/**
 * Create or update a store.
 *
 * @param array $data Store data (name, address, lat, lng, radius_km, promo_message, active).
 * @param int   $id   Store ID (0 = create new).
 * @return int Store ID.
 */
function ls_save_store( $data, $id = 0 ) {
	global $wpdb;
	$p  = $wpdb->prefix;
	$row = array(
		'name'          => sanitize_text_field( $data['name'] ?? '' ),
		'address'       => sanitize_text_field( $data['address'] ?? '' ),
		'lat'           => ( isset( $data['lat'] ) && '' !== $data['lat'] ) ? (float) $data['lat'] : null,
		'lng'           => ( isset( $data['lng'] ) && '' !== $data['lng'] ) ? (float) $data['lng'] : null,
		'radius_km'     => max( 0.1, (float) ( $data['radius_km'] ?? 1 ) ),
		'promo_message' => sanitize_textarea_field( $data['promo_message'] ?? '' ),
		'active'        => ! empty( $data['active'] ) ? 1 : 0,
	);
	if ( $id > 0 ) {
		$wpdb->update( "{$p}ls_stores", $row, array( 'id' => (int) $id ) );
		return (int) $id;
	}
	$row['created_at'] = ls_now();
	$wpdb->insert( "{$p}ls_stores", $row );
	return (int) $wpdb->insert_id;
}

/**
 * Delete a store.
 *
 * @param int $id Store ID.
 * @return bool True on success.
 */
function ls_delete_store( $id ) {
	global $wpdb;
	return false !== $wpdb->delete( "{$wpdb->prefix}ls_stores", array( 'id' => (int) $id ) );
}

/**
 * Admin broadcast: queue a promo to customers whose last known location is
 * within the store radius (and fresh enough).
 */
function ls_broadcast_nearby( $store_id, $message = '' ) {
	global $wpdb;
	$p     = $wpdb->prefix;
	$store = ls_get_store( (int) $store_id );
	if ( ! $store || null === $store->lat || null === $store->lng ) {
		return new WP_Error( 'ls_store', __( 'Store has no coordinates set.', 'loyalty-stamps' ) );
	}

	$ttl   = max( 1, (int) ls_setting( 'geofence_location_ttl_h', 12 ) );
	$since = date( 'Y-m-d H:i:s', strtotime( "-{$ttl} hours", current_time( 'timestamp' ) ) );

	$customers = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, last_lat, last_lng FROM {$p}ls_customers
			 WHERE location_optout = 0 AND last_lat IS NOT NULL
			 AND last_location_at >= %s",
			$since
		)
	);

	$n = 0;
	foreach ( $customers as $c ) {
		$dist = ls_haversine_km( (float) $c->last_lat, (float) $c->last_lng, (float) $store->lat, (float) $store->lng );
		if ( $dist > (float) $store->radius_km ) {
			continue;
		}
		$msg = '' !== $message ? $message : ( $store->promo_message ?: ls_tpl( 'tpl_nearby', array( 'store' => $store->name ), (int) $c->id ) );
		ls_notify( (int) $c->id, 'nearby', sprintf( __( 'Near %s', 'loyalty-stamps' ), $store->name ), $msg, array( 'store_id' => (int) $store->id ) );
		$n++;
	}
	return $n;
}
