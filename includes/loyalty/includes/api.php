<?php
/**
 * REST API: loyalty/v1 — for the customer pages, staff panel and future apps.
 */
defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Route registration
 * ---------------------------------------------------------------------- */

add_action( 'rest_api_init', 'ls_register_routes' );

/** Register all loyalty/v1 REST API routes. */
function ls_register_routes() {
	$ns = 'loyalty/v1';

	register_rest_route(
		$ns,
		'/register',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_register',
			'permission_callback' => '__return_true',
			'args'                => array(
				'name'      => array( 'required' => false, 'type' => 'string' ),
				'phone'     => array( 'required' => true, 'type' => 'string' ),
				'email'     => array( 'required' => false, 'type' => 'string' ),
				'birthdate' => array( 'required' => false, 'type' => 'string' ),
				'lang'      => array( 'required' => false, 'type' => 'string' ),
				'referral'  => array( 'required' => false, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/card',
		array(
			'methods'             => 'GET',
			'callback'            => 'ls_api_card',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/stamp',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_stamp',
			'permission_callback' => '__return_true',
			'args'                => array(
				'staff_pin'  => array( 'required' => true, 'type' => 'string' ),
				'phone'      => array( 'required' => false, 'type' => 'string' ),
				'token'      => array( 'required' => false, 'type' => 'string' ),
				'program_id' => array( 'required' => true, 'type' => 'integer' ),
				'note'       => array( 'required' => false, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/redeem',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_redeem',
			'permission_callback' => '__return_true',
			'args'                => array(
				'staff_pin' => array( 'required' => true, 'type' => 'string' ),
				'code'      => array( 'required' => true, 'type' => 'string' ),
				'note'      => array( 'required' => false, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/staff/find',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_staff_find',
			'permission_callback' => '__return_true',
			'args'                => array(
				'staff_pin' => array( 'required' => true, 'type' => 'string' ),
				'q'         => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/location',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_location',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true, 'type' => 'string' ),
				'lat'   => array( 'required' => true, 'type' => 'number' ),
				'lng'   => array( 'required' => true, 'type' => 'number' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/push-subscribe',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_push_subscribe',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token'        => array( 'required' => true, 'type' => 'string' ),
				'subscription' => array( 'required' => true ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/prefs',
		array(
			'methods'             => 'POST',
			'callback'            => 'ls_api_prefs',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/programs',
		array(
			'methods'             => 'GET',
			'callback'            => 'ls_api_programs',
			'permission_callback' => '__return_true',
		)
	);

	register_rest_route(
		$ns,
		'/customer',
		array(
			'methods'             => 'DELETE',
			'callback'            => 'ls_api_delete_customer',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/wallet/apple',
		array(
			'methods'             => 'GET',
			'callback'            => 'ls_api_wallet_apple',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);

	register_rest_route(
		$ns,
		'/wallet/google',
		array(
			'methods'             => 'GET',
			'callback'            => 'ls_api_wallet_google',
			'permission_callback' => '__return_true',
			'args'                => array(
				'token' => array( 'required' => true, 'type' => 'string' ),
			),
		)
	);
}

/* -------------------------------------------------------------------------
 * Wallet endpoints
 * ---------------------------------------------------------------------- */

/** Download the Apple Wallet .pkpass for a customer. */
function ls_api_wallet_apple( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$bin = ls_apple_build_pass( (int) $customer->id );
	if ( is_wp_error( $bin ) ) {
		return $bin;
	}
	header( 'Content-Type: application/vnd.apple.pkpass' );
	header( 'Content-Disposition: attachment; filename="grill-hub-loyalty.pkpass"' );
	header( 'Content-Length: ' . strlen( $bin ) );
	header( 'Cache-Control: no-store' );
	echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/** Get the "Add to Google Wallet" save URL for a customer. */
function ls_api_wallet_google( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$url = ls_google_save_url( (int) $customer->id );
	if ( is_wp_error( $url ) ) {
		return $url;
	}
	return rest_ensure_response( array( 'url' => $url ) );
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

/** Find a page containing a shortcode; cached. */
function ls_find_shortcode_page( $shortcode ) {
	$key = 'ls_page_' . md5( $shortcode );
	$id  = get_transient( $key );
	if ( $id ) {
		// Verify the cached page still exists and is published (it may have been deleted).
		$post = get_post( (int) $id );
		if ( $post && 'publish' === $post->post_status && has_shortcode( $post->post_content, $shortcode ) ) {
			return (int) $id;
		}
		delete_transient( $key );
	}
	$q = new WP_Query(
		array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 10,
			's'              => '[' . $shortcode,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);
	foreach ( $q->posts as $pid ) {
		$post = get_post( $pid );
		if ( $post && has_shortcode( $post->post_content, $shortcode ) ) {
			set_transient( $key, $pid, DAY_IN_SECONDS );
			return (int) $pid;
		}
	}
	return 0;
}

/** Public URL of a customer's card page. */
function ls_card_url( $token ) {
	$page = ls_find_shortcode_page( 'loyalty_card' );
	$url  = $page ? get_permalink( $page ) : false;
	if ( ! $url ) {
		$url = home_url( '/' );
	}
	return add_query_arg( 'card', $token, $url );
}

/**
 * Get the customer from the request token parameter.
 *
 * @param WP_REST_Request $req Request object.
 * @return object|WP_Error Customer row or WP_Error (404).
 */
function ls_api_customer_from_token( $req ) {
	$customer = ls_get_customer_by_token( $req->get_param( 'token' ) );
	if ( ! $customer ) {
		return new WP_Error( 'ls_no_customer', __( 'Customer not found.', 'loyalty-stamps' ), array( 'status' => 404 ) );
	}
	return $customer;
}

/* -------------------------------------------------------------------------
 * Customer endpoints
 * ---------------------------------------------------------------------- */

/**
 * POST /register — register a new customer.
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Customer data or error.
 */
function ls_api_register( $req ) {
	$id = ls_create_customer(
		array(
			'name'      => $req->get_param( 'name' ),
			'phone'     => $req->get_param( 'phone' ),
			'email'     => $req->get_param( 'email' ),
			'birthdate' => $req->get_param( 'birthdate' ),
			'lang'      => $req->get_param( 'lang' ),
			'referral'  => $req->get_param( 'referral' ),
		)
	);
	if ( is_wp_error( $id ) ) {
		return $id;
	}
	$customer = ls_get_customer( $id );
	return rest_ensure_response(
		array(
			'customer_id'   => $id,
			'token'         => $customer->token,
			'card_url'      => ls_card_url( $customer->token ),
			'referral_code' => $customer->referral_code,
		)
	);
}

/**
 * GET /card — get the customer's cards, rewards, inbox and preferences.
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Card data or error.
 */
function ls_api_card( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$cards = ls_get_customer_cards( (int) $customer->id );
	$out   = array();
	foreach ( $cards as $c ) {
		$out[] = array(
			'id'              => (int) $c->id,
			'program_id'      => (int) $c->program_id,
			'program_name'    => $c->program_name,
			'stamps'          => (int) $c->stamps,
			'stamps_required' => (int) $c->stamps_required,
			'reward_title'    => ls_program_reward_title( $c, (string) $customer->lang ),
			'color'           => $c->color,
			'status'          => $c->status,
			'expires_at'      => $c->expires_at,
		);
	}
	$rewards = array();
	foreach ( ls_get_customer_rewards( (int) $customer->id ) as $r ) {
		$rewards[] = array(
			'code'       => $r->code,
			'title'      => $r->title,
			'status'     => $r->status,
			'issued_at'  => $r->issued_at,
			'expires_at' => $r->expires_at,
		);
	}
	return rest_ensure_response(
		array(
			'name'          => $customer->name,
			'phone'         => $customer->phone,
			'referral_code' => $customer->referral_code,
			'unread'        => ls_unread_count( (int) $customer->id ),
			'cards'         => $out,
			'rewards'       => $rewards,
			'inbox'         => ls_get_inbox( (int) $customer->id, 20 ),
			'prefs'         => array(
				'notify_email'    => (int) $customer->notify_email,
				'notify_push'     => (int) $customer->notify_push,
				'notify_whatsapp' => (int) $customer->notify_whatsapp,
				'location_optout' => (int) $customer->location_optout,
			),
			'wallet'        => array(
				'apple'  => true === ls_apple_configured(),
				'google' => true === ls_google_configured(),
			),
		)
	);
}

/* -------------------------------------------------------------------------
 * Staff endpoints
 * ---------------------------------------------------------------------- */

/**
 * Verify the staff PIN from the request.
 *
 * @param WP_REST_Request $req Request object.
 * @return true|WP_Error True on success, WP_Error (403) on failure.
 */
function ls_api_require_staff( $req ) {
	$pin = (string) $req->get_param( 'staff_pin' );
	if ( ! ls_verify_staff_pin( $pin ) ) {
		return new WP_Error( 'ls_bad_pin', __( 'Invalid staff PIN.', 'loyalty-stamps' ), array( 'status' => 403 ) );
	}
	return true;
}

/**
 * POST /staff/find — find a customer by phone or QR token (staff).
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Customer + cards or error.
 */
function ls_api_staff_find( $req ) {
	$ok = ls_api_require_staff( $req );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$q        = (string) $req->get_param( 'q' );
	$customer = false !== strpos( $q, '+' ) || ctype_digit( str_replace( array( ' ', '-' ), '', $q ) )
		? ls_get_customer_by_phone( $q )
		: ls_get_customer_by_token( $q );
	if ( ! $customer ) {
		return new WP_Error( 'ls_no_customer', __( 'Customer not found.', 'loyalty-stamps' ), array( 'status' => 404 ) );
	}
	$cards = array();
	foreach ( ls_get_customer_cards( (int) $customer->id, 'active' ) as $c ) {
		$cards[] = array(
			'program_id'      => (int) $c->program_id,
			'program_name'    => $c->program_name,
			'stamps'          => (int) $c->stamps,
			'stamps_required' => (int) $c->stamps_required,
			'reward_title'    => ls_program_reward_title( $c, (string) $customer->lang ),
		);
	}
	$programs = array();
	foreach ( ls_get_programs( true ) as $pr ) {
		$programs[] = array( 'id' => (int) $pr->id, 'name' => $pr->name );
	}
	return rest_ensure_response(
		array(
			'id'       => (int) $customer->id,
			'name'     => $customer->name,
			'phone'    => $customer->phone,
			'cards'    => $cards,
			'programs' => $programs,
		)
	);
}

/**
 * POST /stamp — add a stamp to a customer's card (staff).
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Stamp result or error.
 */
function ls_api_stamp( $req ) {
	$ok = ls_api_require_staff( $req );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$customer = null;
	if ( $req->get_param( 'phone' ) ) {
		$customer = ls_get_customer_by_phone( $req->get_param( 'phone' ) );
	} elseif ( $req->get_param( 'token' ) ) {
		$customer = ls_get_customer_by_token( $req->get_param( 'token' ) );
	}
	if ( ! $customer ) {
		return new WP_Error( 'ls_no_customer', __( 'Customer not found.', 'loyalty-stamps' ), array( 'status' => 404 ) );
	}
	$result = ls_add_stamp(
		(int) $customer->id,
		(int) $req->get_param( 'program_id' ),
		array(
			'note'       => $req->get_param( 'note' ),
			'created_by' => get_current_user_id() ?: null,
		)
	);
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response(
		array(
			'customer'  => $customer->name,
			'stamps'    => (int) $result['card']->stamps,
			'completed' => $result['completed'],
			'reward'    => $result['reward'] ? array(
				'code'  => $result['reward']->code,
				'title' => $result['reward']->title,
			) : null,
		)
	);
}

/**
 * POST /redeem — redeem a reward by code (staff).
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Reward data or error.
 */
function ls_api_redeem( $req ) {
	$ok = ls_api_require_staff( $req );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$reward = ls_redeem_reward(
		$req->get_param( 'code' ),
		$req->get_param( 'note' ),
		get_current_user_id() ?: null
	);
	if ( is_wp_error( $reward ) ) {
		return $reward;
	}
	$customer = ls_get_customer( (int) $reward->customer_id );
	return rest_ensure_response(
		array(
			'code'     => $reward->code,
			'title'    => $reward->title,
			'customer' => $customer ? $customer->name : '',
			'status'   => $reward->status,
		)
	);
}

/**
 * POST /location — report customer location for geofence promos.
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Result or error.
 */
function ls_api_location( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$result = ls_handle_location( (int) $customer->id, $req->get_param( 'lat' ), $req->get_param( 'lng' ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	return rest_ensure_response( $result );
}

/**
 * POST /push-subscribe — save a Web Push subscription.
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Confirmation or error.
 */
function ls_api_push_subscribe( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$sub = $req->get_param( 'subscription' );
	if ( ! is_array( $sub ) ) {
		$sub = json_decode( wp_json_encode( $sub ), true );
	}
	$ok = ls_save_push_sub( (int) $customer->id, (array) $sub );
	if ( ! $ok ) {
		return new WP_Error( 'ls_bad_sub', __( 'Invalid push subscription.', 'loyalty-stamps' ), array( 'status' => 400 ) );
	}
	return rest_ensure_response( array( 'saved' => true ) );
}

/**
 * POST /prefs — update customer notification preferences.
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Confirmation or error.
 */
function ls_api_prefs( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$data = array();
	foreach ( array( 'notify_email', 'notify_push', 'notify_whatsapp', 'location_optout' ) as $k ) {
		$v = $req->get_param( $k );
		if ( null !== $v ) {
			$data[ $k ] = $v ? 1 : 0;
		}
	}
	if ( null !== $req->get_param( 'name' ) ) {
		$data['name'] = sanitize_text_field( $req->get_param( 'name' ) );
	}
	ls_update_customer( (int) $customer->id, $data );
	return rest_ensure_response( array( 'saved' => true ) );
}

/**
 * GET /programs — list active loyalty programs.
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response List of programs.
 */
function ls_api_programs( $req ) {
	$out = array();
	foreach ( ls_get_programs( true ) as $pr ) {
		$out[] = array(
			'id'              => (int) $pr->id,
			'name'            => $pr->name,
			'description'     => $pr->description,
			'stamps_required' => (int) $pr->stamps_required,
			'reward_title'    => ls_program_reward_title( $pr ),
			'color'           => $pr->color,
		);
	}
	return rest_ensure_response( $out );
}

/**
 * DELETE /customer — delete the customer and all related data (GDPR).
 *
 * @param WP_REST_Request $req Request object.
 * @return WP_REST_Response|WP_Error Confirmation or error.
 */
function ls_api_delete_customer( $req ) {
	$customer = ls_api_customer_from_token( $req );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	ls_delete_customer( (int) $customer->id );
	return rest_ensure_response( array( 'deleted' => true ) );
}
