<?php
/**
 * Frontend shortcodes: [loyalty_register] [loyalty_card] [loyalty_staff]
 */
defined( 'ABSPATH' ) || exit;

add_shortcode( 'loyalty_register', 'ls_sc_register' );
add_shortcode( 'loyalty_card', 'ls_sc_card' );
add_shortcode( 'loyalty_staff', 'ls_sc_staff' );

/* -------------------------------------------------------------------------
 * [loyalty_register]
 * ---------------------------------------------------------------------- */

/** Small PL / EN / AR dictionary for the join page (no .mo needed). */
function ls_s( $k, $v = array() ) {
	static $T = null;
	if ( null === $T ) {
		$T = array(
			'pl' => array(
				'title' => 'Dołącz i zbieraj nagrody', 'rule' => '{n} pieczątek → {r}', 'name' => 'Imię', 'phone' => 'Numer telefonu', 'more' => 'Więcej (opcjonalnie)', 'email' => 'E-mail', 'birth' => 'Data urodzin (prezent urodzinowy)',
				'cta_apple' => 'Dodaj kartę do Apple Wallet', 'cta_google' => 'Dodaj kartę do Google Wallet', 'cta' => 'Dołącz i pobierz kartę', 'note' => 'Bez aplikacji – karta trafia prosto do portfela w telefonie. Dane tylko na potrzeby programu lojalnościowego.',
				'exists' => 'Ten numer ma już kartę. Użyj swojego linku z wiadomości lub zapytaj obsługę.', 'sub' => 'Pokaż telefon przy kasie – pieczątki dodajemy w sekundę.',
			),
			'en' => array(
				'title' => 'Join and earn rewards', 'rule' => '{n} stamps → {r}', 'name' => 'Name', 'phone' => 'Phone number', 'more' => 'More (optional)', 'email' => 'E-mail', 'birth' => 'Birthday (for a birthday gift)',
				'cta_apple' => 'Add card to Apple Wallet', 'cta_google' => 'Add card to Google Wallet', 'cta' => 'Join and get my card', 'note' => 'No app needed - the card goes straight into your phone wallet. Your data is used only for the loyalty programme.',
				'exists' => 'This number already has a card. Use your personal link or ask the staff.', 'sub' => 'Show your phone at the till - we add stamps in a second.',
			),
			'ar' => array(
				'title' => 'انضم واكسب مكافآت', 'rule' => '{n} أختام ← {r}', 'name' => 'الاسم', 'phone' => 'رقم الموبايل', 'more' => 'المزيد (اختياري)', 'email' => 'الإيميل', 'birth' => 'تاريخ الميلاد (هدية عيد ميلاد)',
				'cta_apple' => 'أضف الكارت إلى Apple Wallet', 'cta_google' => 'أضف الكارت إلى Google Wallet', 'cta' => 'انضم واحصل على كارتي', 'note' => 'من غير تطبيق – الكارت بيروح مباشرة لمحفظة موبايلك. بياناتك لبرنامج الولاء بس.',
				'exists' => 'الرقم ده عنده كارت بالفعل. استخدم رابطك الشخصي أو اسأل الموظف.', 'sub' => 'اعرض موبايلك عند الكاشير – بنضيف الأختام في ثانية.',
			),
		);
	}
	$l = ls_current_lang();
	$t = isset( $T[ $l ][ $k ] ) ? $T[ $l ][ $k ] : $T['pl'][ $k ];
	foreach ( $v as $x => $y ) {
		$t = str_replace( '{' . $x . '}', $y, $t );
	}
	return $t;
}

/** Card page URL for a customer token (+ optional "go to wallet now"). */
function ls_card_link( $token, $wallet = false ) {
	$u = add_query_arg( 'card', $token, ls_url_of( 'loyalty_card' ) ?: home_url( '/' ) );
	return $wallet ? add_query_arg( 'w', '1', $u ) : $u;
}

/**
 * Joining: BEFORE any output (needs redirect + cookie). A returning customer goes straight to the card,
 * a new one is created and sent to the card page with ?w=1 which pushes the card into Apple / Google Wallet.
 */
add_action( 'template_redirect', 'ls_register_handler', 9 );
/**
 * Card page: no "card not found" screen and no tabs. Opening it sends the customer straight to the phone wallet
 * (Google Wallet on Android, Apple Wallet on iPhone) when it is configured; otherwise the web card is shown.
 */
add_action( 'template_redirect', 'ls_card_handler', 9 );
/**
 * Handle card page visits: send the customer straight to their phone wallet
 * (Google Wallet on Android, Apple Wallet on iPhone) when configured.
 *
 * Runs on template_redirect.
 */
function ls_card_handler() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! has_shortcode( $post->post_content, 'loyalty_card' ) ) {
		return;
	}
	$token = isset( $_GET['card'] ) ? sanitize_text_field( wp_unslash( $_GET['card'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( '' === $token && ! empty( $_COOKIE['ls_card'] ) ) {
		$token = sanitize_text_field( wp_unslash( $_COOKIE['ls_card'] ) );
		if ( ls_get_customer_by_token( $token ) ) {
			wp_safe_redirect( ls_card_link( $token ) );
			exit;
		}
	}
	$customer = $token ? ls_get_customer_by_token( $token ) : null;
	if ( ! $customer ) {
		$reg = ls_url_of( 'loyalty_register' );
		if ( $reg ) {
			wp_safe_redirect( add_query_arg( 'new', '1', $reg ) );
			exit;
		}
		return;
	}
	if ( empty( $_COOKIE['ls_card'] ) || $_COOKIE['ls_card'] !== $token ) {
		setcookie( 'ls_card', $token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	}
	if ( isset( $_GET['nw'] ) || ! empty( $_COOKIE['ls_wgo'] ) ) { // phpcs:ignore
		return; // user came back from the wallet (or opted out): show the web card
	}
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	$to = '';
	if ( preg_match( '/Android/i', $ua ) && function_exists( 'ls_google_save_url' ) && true === ls_google_configured() ) {
		$u = ls_google_save_url( (int) $customer->id );
		if ( ! is_wp_error( $u ) ) {
			$to = $u;
		}
	} elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) && function_exists( 'ls_apple_configured' ) && true === ls_apple_configured() ) {
		$to = rest_url( 'loyalty/v1/wallet/apple' ) . '?token=' . rawurlencode( $token );
	}
	if ( $to ) {
		setcookie( 'ls_wgo', '1', time() + 90, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true ); // do not loop when coming back
		nocache_headers();
		wp_redirect( $to ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
}

/**
 * Handle the registration form POST: create the customer and redirect
 * straight to their phone wallet when possible.
 *
 * Runs on template_redirect, before headers are sent.
 */
function ls_register_handler() {
	if ( ! is_singular() ) {
		return;
	}
	$post = get_post();
	if ( ! $post || ! has_shortcode( $post->post_content, 'loyalty_register' ) || ! ls_url_of( 'loyalty_card' ) ) {
		return;
	}
	if ( empty( $_POST['ls_register'] ) ) {
		if ( ! empty( $_COOKIE['ls_card'] ) && ! isset( $_GET['new'] ) && ls_get_customer_by_token( sanitize_text_field( $_COOKIE['ls_card'] ) ) ) {
			wp_safe_redirect( ls_card_link( sanitize_text_field( $_COOKIE['ls_card'] ) ) );
			exit;
		}
		return;
	}
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : 'x';
	$k  = 'ls_reg_' . md5( $ip );
	$n  = (int) get_transient( $k );
	if ( $n >= 10 ) {
		$GLOBALS['ls_reg_error'] = 'Too many attempts, try again later.';
		return;
	}
	set_transient( $k, $n + 1, HOUR_IN_SECONDS );
	if ( ! wp_verify_nonce( isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '', 'ls_register' ) ) {
		$GLOBALS['ls_reg_error'] = 'Session expired - reload the page.';
		return;
	}
	$id = ls_create_customer(
		array(
			'name'      => sanitize_text_field( wp_unslash( $_POST['ls_name'] ?? '' ) ),
			'phone'     => sanitize_text_field( wp_unslash( $_POST['ls_phone'] ?? '' ) ),
			'email'     => sanitize_email( wp_unslash( $_POST['ls_email'] ?? '' ) ),
			'birthdate' => sanitize_text_field( wp_unslash( $_POST['ls_birthdate'] ?? '' ) ),
			'lang'      => ls_current_lang(),
			'referral'  => sanitize_text_field( wp_unslash( $_POST['ls_referral'] ?? '' ) ),
		)
	);
	if ( is_wp_error( $id ) ) {
		$GLOBALS['ls_reg_error'] = 'ls_phone_exists' === $id->get_error_code() ? ls_s( 'exists' ) : $id->get_error_message();
		return;
	}
	$customer = ls_get_customer( $id );
	$program  = ls_default_program();
	if ( $program ) {
		ls_get_or_create_card( (int) $id, (int) $program->id ); // empty card right away, so it can go into the wallet
	}
	setcookie( 'ls_card', $customer->token, time() + YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
	// Go straight to the phone wallet when possible (one redirect instead of
	// register -> card page -> wallet, which proved fragile on some setups).
	$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
	$to = '';
	if ( preg_match( '/Android/i', $ua ) && function_exists( 'ls_google_save_url' ) && true === ls_google_configured() ) {
		$u = ls_google_save_url( (int) $id );
		if ( ! is_wp_error( $u ) ) {
			$to = $u;
		}
	} elseif ( preg_match( '/iPhone|iPad|iPod/i', $ua ) && function_exists( 'ls_apple_configured' ) && true === ls_apple_configured() ) {
		$to = rest_url( 'loyalty/v1/wallet/apple' ) . '?token=' . rawurlencode( $customer->token );
	}
	if ( $to ) {
		setcookie( 'ls_wgo', '1', time() + 90, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true ); // do not loop when coming back
		wp_redirect( $to ); // phpcs:ignore WordPress.Security.SafeRedirect
		exit;
	}
	wp_safe_redirect( ls_card_link( $customer->token, true ) );
	exit;
}

/**
 * [loyalty_register] shortcode — customer signup form.
 *
 * @param array $atts Shortcode attributes.
 * @return string Form HTML.
 */
function ls_sc_register( $atts ) {
	$referral = isset( $_GET['ref'] ) ? sanitize_text_field( wp_unslash( $_GET['ref'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$error    = isset( $GLOBALS['ls_reg_error'] ) ? (string) $GLOBALS['ls_reg_error'] : '';
	$prog     = function_exists( 'ls_default_program' ) ? ls_default_program() : null;
	$req      = $prog ? (int) $prog->stamps_required : 8;
	$reward   = $prog ? ls_program_reward_title( $prog, ls_current_lang() ) : '';
	ob_start();
	?>
	<div class="ls-wrap">
		<div class="ls-join-hero">
			<h2><?php echo esc_html( ls_s( 'title' ) ); ?></h2>
			<p><?php echo esc_html( ls_s( 'sub' ) ); ?></p>
			<span class="ls-join-rule">★ <?php echo esc_html( ls_s( 'rule', array( 'n' => $req, 'r' => $reward ) ) ); ?></span>
			<div class="ls-join-dots"><?php for ( $i = 0; $i < min( 10, $req ); $i++ ) { echo '<i></i>'; } ?></div>
		</div>
		<?php if ( $error ) : ?><div class="ls-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
		<form method="post" class="ls-form" id="ls-join-form">
			<?php wp_nonce_field( 'ls_register' ); ?>
			<input type="hidden" name="ls_referral" value="<?php echo esc_attr( $referral ); ?>">
			<label><?php echo esc_html( ls_s( 'name' ) ); ?><input type="text" name="ls_name" required autocomplete="name"></label>
			<label><?php echo esc_html( ls_s( 'phone' ) ); ?><input type="tel" name="ls_phone" required dir="ltr" autocomplete="tel" inputmode="tel" placeholder="+48 ..."></label>
			<details class="ls-more"><summary><?php echo esc_html( ls_s( 'more' ) ); ?></summary>
				<label><?php echo esc_html( ls_s( 'email' ) ); ?><input type="email" name="ls_email" dir="ltr" autocomplete="email"></label>
				<label><?php echo esc_html( ls_s( 'birth' ) ); ?><input type="date" name="ls_birthdate"></label>
			</details>
			<button class="ls-btn ls-join-cta" name="ls_register" value="1" id="ls-join-cta"
				data-apple="<?php echo esc_attr( ls_s( 'cta_apple' ) ); ?>" data-google="<?php echo esc_attr( ls_s( 'cta_google' ) ); ?>"><?php echo esc_html( ls_s( 'cta' ) ); ?></button>
			<p class="ls-small"><?php echo esc_html( ls_s( 'note' ) ); ?></p>
		</form>
	</div>
	<script>
	(function () {
		var b = document.getElementById('ls-join-cta'), ua = navigator.userAgent || '';
		if (/iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1)) b.textContent = b.getAttribute('data-apple');
		else if (/Android/.test(ua)) b.textContent = b.getAttribute('data-google');
	})();
	</script>
	<?php
	$content = ob_get_clean();
	return ls_page_header_open() . $content . ls_page_footer_close();
}

/* -------------------------------------------------------------------------
 * [loyalty_card]
 * ---------------------------------------------------------------------- */

/**
 * [loyalty_card] shortcode — customer loyalty card page.
 *
 * @param array $atts Shortcode attributes.
 * @return string Card page HTML.
 */
function ls_sc_card( $atts ) {
	$token    = isset( $_GET['card'] ) ? sanitize_text_field( wp_unslash( $_GET['card'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$customer = $token ? ls_get_customer_by_token( $token ) : null;

	ob_start();
	?>
	<div class="ls-wrap" id="ls-card-app" data-token="<?php echo esc_attr( $token ); ?>">
		<?php if ( ! $customer ) : ?>
			<div class="ls-error">
				<h3><?php esc_html_e( 'Card not found', 'loyalty-stamps' ); ?></h3>
				<p><?php esc_html_e( 'Use your personal card link, or register below.', 'loyalty-stamps' ); ?></p>
				<?php
				$reg_page = ls_find_shortcode_page( 'loyalty_register' );
				if ( $reg_page ) {
					echo '<p><a class="ls-btn" href="' . esc_url( get_permalink( $reg_page ) ) . '">' . esc_html__( 'Register', 'loyalty-stamps' ) . '</a></p>';
				}
				?>
			</div>
		<?php else : ?>
			<div class="ls-tabs" hidden></div>
			<div id="ls-tab-cards" class="ls-tabpane active"></div>
			<div id="ls-tab-rewards" class="ls-tabpane"></div>
			<div id="ls-tab-inbox" class="ls-tabpane"></div>
			<div id="ls-tab-settings" class="ls-tabpane"></div>
		<?php endif; ?>
	</div>
	<?php
	$content = ob_get_clean();
	return ls_page_header_open() . $content . ls_page_footer_close();
}

/* -------------------------------------------------------------------------
 * [loyalty_staff] — PIN protected staff panel
 * ---------------------------------------------------------------------- */

/**
 * Check whether the staff panel auth cookie is valid.
 *
 * @return bool True when the staff session is authenticated.
 */
function ls_staff_authed() {
	if ( ! empty( $_COOKIE['ls_staff'] ) ) {
		$expect = hash_hmac( 'sha256', 'ls_staff', wp_salt( 'auth' ) . ls_setting( 'staff_pin_hash', '' ) );
		return hash_equals( $expect, $_COOKIE['ls_staff'] );
	}
	return false;
}

/**
 * Handle staff login/logout BEFORE headers are sent (shortcodes run too late
 * for setcookie()). Runs on template_redirect.
 */
add_action( 'template_redirect', 'ls_staff_auth_handler' );
/**
 * Handle staff login/logout before headers are sent (shortcodes run too late for setcookie()).
 *
 * Runs on template_redirect.
 */
function ls_staff_auth_handler() {
	// Logout.
	if ( isset( $_GET['ls_logout'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		setcookie( 'ls_staff', '', time() - 3600, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
		unset( $_COOKIE['ls_staff'] );
		wp_safe_redirect( remove_query_arg( 'ls_logout' ) );
		exit;
	}
	// Login.
	if ( isset( $_POST['ls_staff_login'] ) ) {
		if ( check_admin_referer( 'ls_staff_login', '_wpnonce', false )
			&& ls_verify_staff_pin( $_POST['ls_pin'] ?? '' ) ) {
			$val = hash_hmac( 'sha256', 'ls_staff', wp_salt( 'auth' ) . ls_setting( 'staff_pin_hash', '' ) );
			setcookie( 'ls_staff', $val, time() + 8 * HOUR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['ls_staff'] = $val;
			// PRG: reload as GET to avoid resubmission.
			$redirect = isset( $_SERVER['REQUEST_URI'] )
				? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) )
				: home_url( '/' );
			wp_safe_redirect( $redirect );
			exit;
		}
		// Wrong PIN: keep the error for the shortcode and render the page normally.
		$GLOBALS['ls_staff_error'] = __( 'Wrong PIN.', 'loyalty-stamps' );
	}
}

/**
 * [loyalty_staff] shortcode — PIN-protected staff panel (find, stamp, redeem).
 *
 * @param array $atts Shortcode attributes.
 * @return string Staff panel HTML.
 */
function ls_sc_staff( $atts ) {
	$error = $GLOBALS['ls_staff_error'] ?? '';

	ob_start();
	?>
	<div class="ls-wrap ls-staff">
		<?php if ( ! ls_staff_authed() ) : ?>
			<h3 class="ls-title"><?php esc_html_e( 'Staff login', 'loyalty-stamps' ); ?></h3>
			<?php if ( $error ) : ?><div class="ls-error"><?php echo esc_html( $error ); ?></div><?php endif; ?>
			<form method="post" class="ls-form">
				<?php wp_nonce_field( 'ls_staff_login' ); ?>
				<label><?php esc_html_e( 'Staff PIN', 'loyalty-stamps' ); ?><input type="password" name="ls_pin" inputmode="numeric" autocomplete="off" required></label>
				<button class="ls-btn" name="ls_staff_login" value="1"><?php esc_html_e( 'Login', 'loyalty-stamps' ); ?></button>
			</form>
		<?php else : ?>
			<div class="ls-staff-head">
				<h3 class="ls-title"><?php esc_html_e( 'Staff panel', 'loyalty-stamps' ); ?></h3>
				<a href="<?php echo esc_url( add_query_arg( 'ls_logout', 1 ) ); ?>"><?php esc_html_e( 'Logout', 'loyalty-stamps' ); ?></a>
			</div>
			<div class="ls-staff-search">
				<input type="text" id="ls-staff-q" placeholder="<?php esc_attr_e( 'Phone number or card token…', 'loyalty-stamps' ); ?>" dir="ltr">
				<button class="ls-btn" id="ls-staff-find"><?php esc_html_e( 'Find', 'loyalty-stamps' ); ?></button>
				<button class="ls-btn ls-btn-scan" id="ls-staff-scan">📷 <?php esc_html_e( 'Scan', 'loyalty-stamps' ); ?></button>
			</div>
			<div id="ls-scan-overlay" class="ls-scan-overlay" style="display:none">
				<div class="ls-scan-box">
					<video id="ls-scan-video" playsinline muted></video>
					<div class="ls-scan-frame"></div>
					<p class="ls-scan-hint"><?php esc_html_e( 'Point the camera at the customer\'s QR code', 'loyalty-stamps' ); ?></p>
					<button class="ls-btn" id="ls-scan-close"><?php esc_html_e( 'Close', 'loyalty-stamps' ); ?></button>
				</div>
			</div>
			<div id="ls-staff-result"></div>
			<div class="ls-panel">
				<h4><?php esc_html_e( 'Redeem reward', 'loyalty-stamps' ); ?></h4>
				<input type="text" id="ls-redeem-code" placeholder="LS-XXXXXXXX" dir="ltr">
				<button class="ls-btn" id="ls-redeem-btn"><?php esc_html_e( 'Redeem', 'loyalty-stamps' ); ?></button>
				<div id="ls-redeem-result"></div>
			</div>
			<script>
				window.LS_STAFF_PIN = '';
				document.getElementById('ls-staff-find').addEventListener('click', function () {
					lsStaffFind(document.getElementById('ls-staff-q').value.trim());
				});
				document.getElementById('ls-staff-scan').addEventListener('click', function () {
					lsStaffScan();
				});
				document.getElementById('ls-redeem-btn').addEventListener('click', function () {
					lsStaffRedeem(document.getElementById('ls-redeem-code').value.trim());
				});
			</script>
		<?php endif; ?>
	</div>
	<?php
	$content = ob_get_clean();
	return ls_page_header_open() . $content . ls_page_footer_close();
}

/**
 * Staff ajax actions. Auth is the signed staff cookie + ajax nonce;
 * the PIN itself never leaves the server.
 */
add_action( 'wp_ajax_ls_staff_stamp', 'ls_ajax_staff_stamp' );
add_action( 'wp_ajax_nopriv_ls_staff_stamp', 'ls_ajax_staff_stamp' );
add_action( 'wp_ajax_ls_staff_redeem', 'ls_ajax_staff_redeem' );
add_action( 'wp_ajax_nopriv_ls_staff_redeem', 'ls_ajax_staff_redeem' );
add_action( 'wp_ajax_ls_staff_find', 'ls_ajax_staff_find' );
add_action( 'wp_ajax_nopriv_ls_staff_find', 'ls_ajax_staff_find' );

/**
 * Verify staff authentication for AJAX staff actions. Dies with 403 on failure.
 */
function ls_ajax_staff_guard() {
	if ( ! ls_staff_authed() ) {
		wp_send_json_error( array( 'message' => __( 'Not authorized.', 'loyalty-stamps' ) ), 403 );
	}
	check_ajax_referer( 'ls_staff', 'nonce' );
}

/** AJAX: find a customer by phone or QR token (staff). */
function ls_ajax_staff_find() {
	ls_ajax_staff_guard();
	$q = sanitize_text_field( $_POST['q'] ?? '' );
	$customer = false !== strpos( $q, '+' ) || ctype_digit( str_replace( array( ' ', '-' ), '', $q ) )
		? ls_get_customer_by_phone( $q )
		: ls_get_customer_by_token( $q );
	if ( ! $customer ) {
		wp_send_json_error( array( 'message' => __( 'Customer not found.', 'loyalty-stamps' ) ), 404 );
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
	wp_send_json_success(
		array(
			'id'       => (int) $customer->id,
			'name'     => $customer->name,
			'phone'    => $customer->phone,
			'cards'    => $cards,
			'programs' => $programs,
		)
	);
}

/** AJAX: add a stamp to a customer's card (staff). */
function ls_ajax_staff_stamp() {
	ls_ajax_staff_guard();
	$customer = ls_get_customer( (int) ( $_POST['customer_id'] ?? 0 ) );
	if ( ! $customer ) {
		wp_send_json_error( array( 'message' => __( 'Customer not found.', 'loyalty-stamps' ) ), 404 );
	}
	$result = ls_add_stamp(
		(int) $customer->id,
		(int) ( $_POST['program_id'] ?? 0 ),
		array( 'note' => 'staff panel', 'created_by' => get_current_user_id() ?: null )
	);
	if ( is_wp_error( $result ) ) {
		wp_send_json_error( array( 'message' => $result->get_error_message() ) );
	}
	wp_send_json_success(
		array(
			'stamps'    => (int) $result['card']->stamps,
			'completed' => $result['completed'],
			'reward'    => $result['reward'] ? $result['reward']->code : null,
		)
	);
}

/** AJAX: redeem a reward by code (staff). */
function ls_ajax_staff_redeem() {
	ls_ajax_staff_guard();
	$reward = ls_redeem_reward( sanitize_text_field( $_POST['code'] ?? '' ), 'staff panel', get_current_user_id() ?: null );
	if ( is_wp_error( $reward ) ) {
		wp_send_json_error( array( 'message' => $reward->get_error_message() ) );
	}
	$customer = ls_get_customer( (int) $reward->customer_id );
	wp_send_json_success(
		array(
			'code'     => $reward->code,
			'title'    => $reward->title,
			'customer' => $customer ? ( $customer->name ?: $customer->phone ) : '',
		)
	);
}
