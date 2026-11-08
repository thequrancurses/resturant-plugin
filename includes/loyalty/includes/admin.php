<?php
/**
 * Admin: menu, dashboard stats, programs, customers, cards, transactions,
 * rewards, stores, notifications, settings.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'ls_admin_menu', 20 );
function ls_admin_menu() {
	// The Grill Hub control deck MUST be the first submenu item. WordPress
	// replaces a top-level menu's link with its first submenu when they
	// differ — without this, clicking "Grill Hub" would open Loyalty instead.
	add_submenu_page(
		'grill-hub-menu',
		__( 'Grill Hub Menu', 'grill-hub-menu' ),
		__( 'Grill Hub', 'grill-hub-menu' ),
		'manage_options',
		'grill-hub-menu',
		'ghm_admin_page'
	);
	// Loyalty is merged into the Grill Hub menu (one control deck, one place).
	add_submenu_page(
		'grill-hub-menu',
		__( 'Loyalty', 'loyalty-stamps' ),
		__( 'Loyalty', 'loyalty-stamps' ),
		'manage_options',
		'loyalty',
		'ls_page_dashboard'
	);
	$pages = array(
		array( __( 'Programs', 'loyalty-stamps' ), __( 'Programs', 'loyalty-stamps' ), 'loyalty-programs', 'ls_page_programs' ),
		array( __( 'Customers', 'loyalty-stamps' ), __( 'Customers', 'loyalty-stamps' ), 'loyalty-customers', 'ls_page_customers' ),
		array( __( 'Cards', 'loyalty-stamps' ), __( 'Cards', 'loyalty-stamps' ), 'loyalty-cards', 'ls_page_cards' ),
		array( __( 'Transactions', 'loyalty-stamps' ), __( 'Transactions', 'loyalty-stamps' ), 'loyalty-transactions', 'ls_page_transactions' ),
		array( __( 'Rewards', 'loyalty-stamps' ), __( 'Rewards', 'loyalty-stamps' ), 'loyalty-rewards', 'ls_page_rewards' ),
		array( __( 'Stores', 'loyalty-stamps' ), __( 'Stores', 'loyalty-stamps' ), 'loyalty-stores', 'ls_page_stores' ),
		array( __( 'Notifications', 'loyalty-stamps' ), __( 'Notifications', 'loyalty-stamps' ), 'loyalty-notifications', 'ls_page_notifications' ),
		array( __( 'Settings', 'loyalty-stamps' ), __( 'Loyalty settings', 'loyalty-stamps' ), 'loyalty-settings', 'ls_page_settings' ),
	);
	foreach ( $pages as $pg ) {
		add_submenu_page( 'grill-hub-menu', $pg[0], $pg[1], 'manage_options', $pg[2], $pg[3] );
	}
}

add_action( 'admin_enqueue_scripts', 'ls_admin_assets' );
function ls_admin_assets( $hook ) {
	if ( strpos( (string) $hook, 'loyalty' ) === false ) {
		return;
	}
	wp_enqueue_style( 'ls-admin', LS_URL . 'assets/css/admin.css', array(), LS_VERSION );
	wp_enqueue_script( 'ls-admin', LS_URL . 'assets/js/admin.js', array(), LS_VERSION, true );
	// Embed mode: hide the WP admin chrome so the page can live inside an
	// iframe on the Grill Hub control deck.
	if ( ! empty( $_GET['ls_embed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		wp_add_inline_style( 'ls-admin', '#adminmenumain,#wpadminbar,#wpfooter,#screen-meta,#contextual-help-link-wrap{display:none!important}#wpcontent,#wpfooter{margin-left:0!important}#wpbody-content{padding-bottom:20px}.ls-admin{max-width:none}' );
		// Keep embed mode sticky across internal links and form posts.
		wp_add_inline_script(
			'ls-admin',
			"(function(){function fix(a){try{var u=new URL(a.href,location.href);if(u.pathname.indexOf('admin.php')>-1&&/(^|&)page=loyalty/.test(u.search)&&u.search.indexOf('ls_embed=1')<0){u.search+=(u.search?'&':'?')+'ls_embed=1';a.href=u.toString();}}catch(e){}}\n" .
			"document.querySelectorAll('a[href*=\"admin.php\"]').forEach(fix);\n" .
			"document.querySelectorAll('form[method=\"post\"],form:not([method])').forEach(function(f){var i=document.createElement('input');i.type='hidden';i.name='ls_embed';i.value='1';f.appendChild(i);});})();"
		);
	}
}

/* -------------------------------------------------------------------------
 * Helpers
 * ---------------------------------------------------------------------- */

function ls_admin_wrap_start( $title ) {
	echo '<div class="wrap ls-admin"><h1>' . esc_html( $title ) . '</h1>';
	ls_admin_notices();
	ls_admin_subnav();
}

/** Sub-navigation across the loyalty sections (all merged under the Grill Hub menu). */
function ls_admin_subnav() {
	// In embed mode the host page provides the tabs.
	if ( ! empty( $_GET['ls_embed'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	$pages = array(
		'loyalty'               => __( 'Dashboard', 'loyalty-stamps' ),
		'loyalty-programs'      => __( 'Programs', 'loyalty-stamps' ),
		'loyalty-customers'     => __( 'Customers', 'loyalty-stamps' ),
		'loyalty-cards'         => __( 'Cards', 'loyalty-stamps' ),
		'loyalty-transactions'  => __( 'Transactions', 'loyalty-stamps' ),
		'loyalty-rewards'       => __( 'Rewards', 'loyalty-stamps' ),
		'loyalty-stores'        => __( 'Stores', 'loyalty-stamps' ),
		'loyalty-notifications' => __( 'Notifications', 'loyalty-stamps' ),
		'loyalty-wallet'        => __( 'Wallet', 'loyalty-stamps' ),
		'loyalty-settings'      => __( 'Settings', 'loyalty-stamps' ),
	);
	$cur = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( ! isset( $pages[ $cur ] ) ) {
		return;
	}
	echo '<h2 class="nav-tab-wrapper ls-subnav" style="margin:12px 0 20px">';
	foreach ( $pages as $slug => $label ) {
		$cls = $slug === $cur ? ' nav-tab-active' : '';
		echo '<a class="nav-tab' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
	}
	echo '</h2>';
}

function ls_admin_wrap_end() {
	echo '</div>';
}

function ls_admin_notices() {
	if ( ! empty( $_GET['ls_msg'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( wp_unslash( $_GET['ls_msg'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
	if ( ! empty( $_GET['ls_err'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( wp_unslash( $_GET['ls_err'] ) ) . '</p></div>'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}
}

function ls_admin_redirect( $page, $msg = '', $err = '' ) {
	$url = admin_url( 'admin.php?page=' . $page );
	if ( ! empty( $_REQUEST['ls_embed'] ) ) { // keep iframe embed mode across redirects
		$url = add_query_arg( 'ls_embed', '1', $url );
	}
	if ( $msg ) {
		$url = add_query_arg( 'ls_msg', rawurlencode( $msg ), $url );
	}
	if ( $err ) {
		$url = add_query_arg( 'ls_err', rawurlencode( $err ), $url );
	}
	wp_safe_redirect( $url );
	exit;
}

function ls_paginate( $total, $per_page, $page, $base_url ) {
	$pages = max( 1, (int) ceil( $total / $per_page ) );
	if ( $pages <= 1 ) {
		return;
	}
	echo '<div class="tablenav"><div class="tablenav-pages">';
	for ( $i = 1; $i <= $pages; $i++ ) {
		if ( $i === (int) $page ) {
			echo '<span class="tablenav-pages-navspan button disabled">' . (int) $i . '</span> ';
		} else {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'paged', $i, $base_url ) ) . '">' . (int) $i . '</a> ';
		}
	}
	echo '</div></div>';
}

/** Simple SVG bar chart (no external dependencies). */
function ls_svg_bars( $values, $labels, $height = 160 ) {
	$max = max( 1, max( $values ) );
	$n   = count( $values );
	$w   = max( 320, $n * 44 );
	$bw  = 26;
	$gap = ( $w - 20 - $n * $bw ) / max( 1, $n );
	$svg = '<svg class="ls-chart" viewBox="0 0 ' . $w . ' ' . ( $height + 34 ) . '" role="img">';
	$x   = 10;
	foreach ( $values as $i => $v ) {
		$bh = round( ( $v / $max ) * $height );
		$y  = $height - $bh;
		$svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $bw . '" height="' . $bh . '" rx="3" fill="#d97706"><title>' . esc_attr( $labels[ $i ] . ': ' . $v ) . '</title></rect>';
		$svg .= '<text x="' . ( $x + $bw / 2 ) . '" y="' . ( $height + 16 ) . '" text-anchor="middle" font-size="10" fill="#666">' . esc_html( $labels[ $i ] ) . '</text>';
		if ( $v > 0 ) {
			$svg .= '<text x="' . ( $x + $bw / 2 ) . '" y="' . ( $y - 5 ) . '" text-anchor="middle" font-size="10" fill="#333">' . (int) $v . '</text>';
		}
		$x += $bw + $gap;
	}
	$svg .= '</svg>';
	return $svg;
}

/* -------------------------------------------------------------------------
 * Dashboard / statistics
 * ---------------------------------------------------------------------- */

function ls_stats_overview() {
	global $wpdb;
	$p = $wpdb->prefix;
	$week_ago  = date( 'Y-m-d H:i:s', strtotime( '-7 days', current_time( 'timestamp' ) ) );
	$month_ago = date( 'Y-m-d H:i:s', strtotime( '-30 days', current_time( 'timestamp' ) ) );
	$today     = date( 'Y-m-d 00:00:00', current_time( 'timestamp' ) );

	return array(
		'customers'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_customers" ),
		'customers_week'   => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_customers WHERE created_at >= %s", $week_ago ) ),
		'active_cards'     => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_cards WHERE status = 'active'" ),
		'completed_cards'  => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_cards WHERE status = 'completed'" ),
		'stamps_today'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_transactions WHERE type IN ('stamp','bonus') AND created_at >= %s", $today ) ),
		'stamps_week'      => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_transactions WHERE type IN ('stamp','bonus') AND created_at >= %s", $week_ago ) ),
		'stamps_month'     => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_transactions WHERE type IN ('stamp','bonus') AND created_at >= %s", $month_ago ) ),
		'rewards_issued'   => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_rewards" ),
		'rewards_redeemed' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_rewards WHERE status = 'redeemed'" ),
		'push_subs'        => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_push_subs" ),
		'queued'           => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}ls_notifications WHERE status = 'queued'" ),
	);
}

function ls_stats_daily_stamps( $days = 14 ) {
	global $wpdb;
	$p = $wpdb->prefix;
	$values = array();
	$labels = array();
	for ( $i = $days - 1; $i >= 0; $i-- ) {
		$date   = date( 'Y-m-d', strtotime( "-{$i} days", current_time( 'timestamp' ) ) );
		$values[] = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}ls_transactions WHERE type IN ('stamp','bonus') AND DATE(created_at) = %s",
				$date
			)
		);
		$labels[] = date( 'd/m', strtotime( $date ) );
	}
	return array( $values, $labels );
}

function ls_page_dashboard() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$s = ls_stats_overview();
	ls_admin_wrap_start( __( 'Loyalty Dashboard', 'loyalty-stamps' ) );

	echo '<div class="ls-cards">';
	$cards = array(
		array( __( 'Customers', 'loyalty-stamps' ), $s['customers'], sprintf( __( '+%d this week', 'loyalty-stamps' ), $s['customers_week'] ) ),
		array( __( 'Active cards', 'loyalty-stamps' ), $s['active_cards'], sprintf( __( '%d completed', 'loyalty-stamps' ), $s['completed_cards'] ) ),
		array( __( 'Stamps today', 'loyalty-stamps' ), $s['stamps_today'], sprintf( __( '%d this week / %d this month', 'loyalty-stamps' ), $s['stamps_week'], $s['stamps_month'] ) ),
		array( __( 'Rewards', 'loyalty-stamps' ), $s['rewards_issued'], sprintf( __( '%d redeemed', 'loyalty-stamps' ), $s['rewards_redeemed'] ) ),
		array( __( 'Push subscribers', 'loyalty-stamps' ), $s['push_subs'], '' ),
		array( __( 'Queued notifications', 'loyalty-stamps' ), $s['queued'], '' ),
	);
	foreach ( $cards as $c ) {
		echo '<div class="ls-card"><div class="ls-card-num">' . (int) $c[1] . '</div><div class="ls-card-label">' . esc_html( $c[0] ) . '</div><div class="ls-card-sub">' . esc_html( $c[2] ) . '</div></div>';
	}
	echo '</div>';

	list( $values, $labels ) = ls_stats_daily_stamps( 14 );
	echo '<div class="ls-panel"><h2>' . esc_html__( 'Stamps per day (last 14 days)', 'loyalty-stamps' ) . '</h2>';
	echo ls_svg_bars( $values, $labels ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo '</div>';

	// Stamps per program.
	global $wpdb;
	$p = $wpdb->prefix;
	$per_program = $wpdb->get_results(
		"SELECT pr.name, COUNT(t.id) AS stamps
		 FROM {$p}ls_transactions t
		 JOIN {$p}ls_programs pr ON pr.id = t.program_id
		 WHERE t.type IN ('stamp','bonus')
		 GROUP BY pr.id ORDER BY stamps DESC"
	);
	if ( $per_program ) {
		$v = array();
		$l = array();
		foreach ( $per_program as $row ) {
			$v[] = (int) $row->stamps;
			$l[] = mb_substr( $row->name, 0, 12 );
		}
		echo '<div class="ls-panel"><h2>' . esc_html__( 'Stamps per program', 'loyalty-stamps' ) . '</h2>';
		echo ls_svg_bars( $v, $l ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '</div>';
	}

	// Top customers.
	$top = $wpdb->get_results(
		"SELECT c.id, c.name, c.phone, COUNT(t.id) AS stamps
		 FROM {$p}ls_transactions t
		 JOIN {$p}ls_customers c ON c.id = t.customer_id
		 WHERE t.type IN ('stamp','bonus')
		 GROUP BY c.id ORDER BY stamps DESC LIMIT 8"
	);
	echo '<div class="ls-panel"><h2>' . esc_html__( 'Top customers', 'loyalty-stamps' ) . '</h2>';
	if ( $top ) {
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Phone', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Stamps', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
		foreach ( $top as $t ) {
			echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=loyalty-customers&action=view&id=' . (int) $t->id ) ) . '">' . esc_html( $t->name ?: '—' ) . '</a></td><td>' . esc_html( $t->phone ) . '</td><td>' . (int) $t->stamps . '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		echo '<p>' . esc_html__( 'No data yet.', 'loyalty-stamps' ) . '</p>';
	}
	echo '</div>';

	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Programs
 * ---------------------------------------------------------------------- */

function ls_page_programs() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle save / delete / toggle FIRST (before the action routing below,
	// otherwise POSTs from the edit form return early and never save).
	if ( isset( $_POST['ls_program_save'] ) && check_admin_referer( 'ls_program_save' ) ) {
		$id   = (int) ( $_POST['id'] ?? 0 );
		$data = array(
			'name'               => $_POST['name'] ?? '',
			'description'        => $_POST['description'] ?? '',
			'stamps_required'    => $_POST['stamps_required'] ?? 8,
			'reward_title'       => $_POST['reward_title'] ?? '',
			'reward_type'        => $_POST['reward_type'] ?? 'custom',
			'reward_description' => $_POST['reward_description'] ?? '',
			'color'              => $_POST['color'] ?? '#d97706',
			'active'             => $_POST['active'] ?? 0,
			'card_expiry_days'   => $_POST['card_expiry_days'] ?? 365,
			'max_completions'    => $_POST['max_completions'] ?? 0,
		);
		if ( $id > 0 ) {
			$res = ls_update_program( $id, $data, ! empty( $_POST['notify_customers'] ), $_POST['change_summary'] ?? '' );
			$msg = __( 'Program updated.', 'loyalty-stamps' );
			if ( ! empty( $_POST['notify_customers'] ) ) {
				$msg .= ' ' . sprintf( __( 'Notification sent to %d customer(s).', 'loyalty-stamps' ), (int) $res );
			}
			ls_admin_redirect( 'loyalty-programs', $msg );
		} else {
			$new_id = ls_create_program( $data );
			if ( $new_id ) {
				ls_log_program_change( $new_id, __( 'Program created.', 'loyalty-stamps' ) );
				ls_admin_redirect( 'loyalty-programs', __( 'Program created.', 'loyalty-stamps' ) );
			}
			ls_admin_redirect( 'loyalty-programs', '', __( 'Could not create program.', 'loyalty-stamps' ) );
		}
	}
	if ( isset( $_GET['do'] ) && check_admin_referer( 'ls_program_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$do = $_GET['do']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		global $wpdb;
		$p = $wpdb->prefix;
		if ( 'toggle' === $do && $id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$p}ls_programs SET active = 1 - active, updated_at = %s WHERE id = %d", ls_now(), $id ) );
			ls_log_program_change( $id, __( 'Program activation toggled.', 'loyalty-stamps' ) );
		} elseif ( 'delete' === $do && $id ) {
			$used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_cards WHERE program_id = %d", $id ) );
			if ( $used > 0 ) {
				ls_admin_redirect( 'loyalty-programs', '', __( 'Cannot delete a program that has cards. Deactivate it instead.', 'loyalty-stamps' ) );
			}
			$wpdb->delete( "{$p}ls_programs", array( 'id' => $id ) );
		}
		ls_admin_redirect( 'loyalty-programs', __( 'Done.', 'loyalty-stamps' ) );
	}

	$action = $_GET['action'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'edit' === $action || 'new' === $action ) {
		ls_page_program_edit( 'edit' === $action ? (int) ( $_GET['id'] ?? 0 ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( 'log' === $action ) {
		ls_page_program_log( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	ls_admin_wrap_start( __( 'Loyalty Programs', 'loyalty-stamps' ) );
	echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=loyalty-programs&action=new' ) ) . '">' . esc_html__( 'Add program', 'loyalty-stamps' ) . '</a><br><br>';

	global $wpdb;
	$p        = $wpdb->prefix;
	$programs = $wpdb->get_results( "SELECT * FROM {$p}ls_programs ORDER BY sort_order ASC, id ASC" );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Program', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Stamps required', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Reward', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Active cards', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Completed', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $programs as $pr ) {
		$active_c = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_cards WHERE program_id = %d AND status = 'active'", $pr->id ) );
		$done_c   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_cards WHERE program_id = %d AND status = 'completed'", $pr->id ) );
		$base     = admin_url( 'admin.php?page=loyalty-programs&id=' . (int) $pr->id );
		echo '<tr>';
		echo '<td><span class="ls-dot" style="background:' . esc_attr( $pr->color ) . '"></span> <strong>' . esc_html( $pr->name ) . '</strong></td>';
		echo '<td>' . (int) $pr->stamps_required . '</td>';
		echo '<td>' . esc_html( ls_program_reward_title( $pr ) ) . '</td>';
		echo '<td>' . (int) $active_c . '</td>';
		echo '<td>' . (int) $done_c . '</td>';
		echo '<td>' . ( $pr->active ? esc_html__( 'Active', 'loyalty-stamps' ) : esc_html__( 'Inactive', 'loyalty-stamps' ) ) . '</td>';
		echo '<td><a href="' . esc_url( $base . '&action=edit' ) . '">' . esc_html__( 'Edit', 'loyalty-stamps' ) . '</a> | ';
		echo '<a href="' . esc_url( wp_nonce_url( $base . '&do=toggle', 'ls_program_action' ) ) . '">' . esc_html__( 'Toggle', 'loyalty-stamps' ) . '</a> | ';
		echo '<a href="' . esc_url( $base . '&action=log' ) . '">' . esc_html__( 'Changelog', 'loyalty-stamps' ) . '</a> | ';
		echo '<a href="' . esc_url( wp_nonce_url( $base . '&do=delete', 'ls_program_action' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this program?', 'loyalty-stamps' ) ) . '\')">' . esc_html__( 'Delete', 'loyalty-stamps' ) . '</a></td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	ls_admin_wrap_end();
}

function ls_page_program_edit( $id ) {
	$pr = $id ? ls_get_program( $id ) : null;
	ls_admin_wrap_start( $id ? __( 'Edit program', 'loyalty-stamps' ) : __( 'New program', 'loyalty-stamps' ) );
	$v = function ( $k, $d = '' ) use ( $pr ) {
		return $pr ? $pr->$k : $d;
	};
	?>
	<form method="post" class="ls-form">
		<?php wp_nonce_field( 'ls_program_save' ); ?>
		<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Name', 'loyalty-stamps' ); ?></th><td><input type="text" name="name" required class="regular-text" value="<?php echo esc_attr( $v( 'name' ) ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Description', 'loyalty-stamps' ); ?></th><td><textarea name="description" rows="3" class="large-text"><?php echo esc_textarea( $v( 'description' ) ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Stamps required (unlimited programs supported)', 'loyalty-stamps' ); ?></th><td><input type="number" name="stamps_required" min="1" value="<?php echo (int) $v( 'stamps_required', 8 ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Reward type', 'loyalty-stamps' ); ?></th><td>
				<select name="reward_type" id="ls-reward-type">
					<option value="custom"<?php selected( $v( 'reward_type', 'custom' ), 'custom' ); ?>><?php esc_html_e( 'Custom reward (title below)', 'loyalty-stamps' ); ?></option>
					<option value="sandwich"<?php selected( $v( 'reward_type', 'custom' ), 'sandwich' ); ?>><?php esc_html_e( 'Any sandwich of the customer\'s choice', 'loyalty-stamps' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'PL: "Dowolna kanapka gratis" · EN: "Any sandwich of your choice" · AR: "أي ساندوتش من اختيارك"', 'loyalty-stamps' ); ?></p>
			</td></tr>
			<tr id="ls-reward-title-row"><th><?php esc_html_e( 'Reward title', 'loyalty-stamps' ); ?></th><td><input type="text" name="reward_title" class="regular-text" value="<?php echo esc_attr( $v( 'reward_title' ) ); ?>"></td></tr>
			<script>document.getElementById('ls-reward-type').addEventListener('change',function(){document.getElementById('ls-reward-title-row').style.display=this.value==='sandwich'?'none':'';});document.getElementById('ls-reward-title-row').style.display=document.getElementById('ls-reward-type').value==='sandwich'?'none':'';</script>
			<tr><th><?php esc_html_e( 'Reward description', 'loyalty-stamps' ); ?></th><td><textarea name="reward_description" rows="3" class="large-text"><?php echo esc_textarea( $v( 'reward_description' ) ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Card color', 'loyalty-stamps' ); ?></th><td><input type="color" name="color" value="<?php echo esc_attr( $v( 'color', '#d97706' ) ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Card expiry (days, 0 = never)', 'loyalty-stamps' ); ?></th><td><input type="number" name="card_expiry_days" min="0" value="<?php echo (int) $v( 'card_expiry_days', 365 ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Max completions per customer (0 = unlimited)', 'loyalty-stamps' ); ?></th><td><input type="number" name="max_completions" min="0" value="<?php echo (int) $v( 'max_completions', 0 ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Active', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="active" value="1" <?php checked( (int) $v( 'active', 1 ), 1 ); ?>></td></tr>
			<?php if ( $id ) : ?>
			<tr><th><?php esc_html_e( 'What changed? (changelog)', 'loyalty-stamps' ); ?></th><td><textarea name="change_summary" rows="2" class="large-text" placeholder="<?php esc_attr_e( 'e.g. Stamps required changed from 8 to 10', 'loyalty-stamps' ); ?>"></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Notify customers', 'loyalty-stamps' ); ?></th><td><label><input type="checkbox" name="notify_customers" value="1"> <?php esc_html_e( 'Send a notification to all customers holding an active card of this program', 'loyalty-stamps' ); ?></label></td></tr>
			<?php endif; ?>
		</table>
		<p><button class="button button-primary" name="ls_program_save" value="1"><?php esc_html_e( 'Save program', 'loyalty-stamps' ); ?></button>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=loyalty-programs' ) ); ?>"><?php esc_html_e( 'Back', 'loyalty-stamps' ); ?></a></p>
	</form>
	<?php
	ls_admin_wrap_end();
}

function ls_page_program_log( $id ) {
	$pr = ls_get_program( $id );
	ls_admin_wrap_start( sprintf( __( 'Changelog: %s', 'loyalty-stamps' ), $pr ? $pr->name : '' ) );
	global $wpdb;
	$logs = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ls_program_log WHERE program_id = %d ORDER BY id DESC LIMIT 100", $id ) );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Change', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
	foreach ( $logs as $l ) {
		echo '<tr><td>' . esc_html( $l->created_at ) . '</td><td>' . esc_html( $l->summary ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=loyalty-programs' ) ) . '">' . esc_html__( 'Back', 'loyalty-stamps' ) . '</a></p>';
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Customers
 * ---------------------------------------------------------------------- */

function ls_page_customers() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$action = $_GET['action'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'view' === $action ) {
		ls_page_customer_view( (int) ( $_GET['id'] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}
	if ( isset( $_GET['do'] ) && check_admin_referer( 'ls_customer_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'delete' === $_GET['do'] && $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			ls_delete_customer( $id );
			ls_admin_redirect( 'loyalty-customers', __( 'Customer deleted.', 'loyalty-stamps' ) );
		}
	}

	global $wpdb;
	$p      = $wpdb->prefix;
	$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per    = 25;
	$where  = '1=1';
	$args   = array();
	if ( '' !== $search ) {
		$where  .= ' AND (name LIKE %s OR phone LIKE %s OR email LIKE %s)';
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$args    = array( $like, $like, $like );
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_customers WHERE {$where}", ...$args ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$p}ls_customers WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d",
			...array_merge( $args, array( $per, ( $page - 1 ) * $per ) )
		)
	);

	ls_admin_wrap_start( __( 'Customers', 'loyalty-stamps' ) );
	echo '<form method="get"><input type="hidden" name="page" value="loyalty-customers">';
	echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Search name / phone / email', 'loyalty-stamps' ) . '"> ';
	echo '<button class="button">' . esc_html__( 'Search', 'loyalty-stamps' ) . '</button> ';
	echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ls_export_customers' ), 'ls_export' ) ) . '">' . esc_html__( 'Export CSV', 'loyalty-stamps' ) . '</a>';
	echo '</form><br>';

	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Name', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Phone', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Email', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Cards', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Stamps', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Joined', 'loyalty-stamps' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $c ) {
		$cards  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_cards WHERE customer_id = %d", $c->id ) );
		$stamps = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_transactions WHERE customer_id = %d AND type IN ('stamp','bonus')", $c->id ) );
		echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=loyalty-customers&action=view&id=' . (int) $c->id ) ) . '">' . esc_html( $c->name ?: '—' ) . '</a></td>';
		echo '<td>' . esc_html( $c->phone ) . '</td><td>' . esc_html( $c->email ?: '—' ) . '</td>';
		echo '<td>' . (int) $cards . '</td><td>' . (int) $stamps . '</td><td>' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $c->created_at ) ) ) . '</td>';
		echo '<td><a href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=loyalty-customers&do=delete&id=' . (int) $c->id ), 'ls_customer_action' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this customer and all their data?', 'loyalty-stamps' ) ) . '\')">' . esc_html__( 'Delete', 'loyalty-stamps' ) . '</a></td></tr>';
	}
	echo '</tbody></table>';
	ls_paginate( $total, $per, $page, admin_url( 'admin.php?page=loyalty-customers&s=' . rawurlencode( $search ) ) );
	ls_admin_wrap_end();
}

function ls_page_customer_view( $id ) {
	$c = ls_get_customer( $id );
	if ( ! $c ) {
		ls_admin_wrap_start( __( 'Customer', 'loyalty-stamps' ) );
		echo '<p>' . esc_html__( 'Not found.', 'loyalty-stamps' ) . '</p>';
		ls_admin_wrap_end();
		return;
	}
	ls_admin_wrap_start( sprintf( __( 'Customer: %s', 'loyalty-stamps' ), $c->name ?: $c->phone ) );
	echo '<p><strong>' . esc_html__( 'Phone', 'loyalty-stamps' ) . ':</strong> ' . esc_html( $c->phone ) . ' &nbsp; <strong>' . esc_html__( 'Email', 'loyalty-stamps' ) . ':</strong> ' . esc_html( $c->email ?: '—' ) . ' &nbsp; <strong>' . esc_html__( 'Referral code', 'loyalty-stamps' ) . ':</strong> ' . esc_html( $c->referral_code ) . '</p>';
	echo '<p><strong>' . esc_html__( 'Card link', 'loyalty-stamps' ) . ':</strong> <a href="' . esc_url( ls_card_url( $c->token ) ) . '" target="_blank">' . esc_html( ls_card_url( $c->token ) ) . '</a></p>';

	echo '<h2>' . esc_html__( 'Cards', 'loyalty-stamps' ) . '</h2>';
	$cards = ls_get_customer_cards( $id );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Program', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Progress', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Expires', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
	foreach ( $cards as $card ) {
		echo '<tr><td>' . esc_html( $card->program_name ) . '</td><td>' . (int) $card->stamps . ' / ' . (int) $card->stamps_required . '</td><td>' . esc_html( $card->status ) . '</td><td>' . esc_html( $card->expires_at ?: '—' ) . '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<h2>' . esc_html__( 'Rewards', 'loyalty-stamps' ) . '</h2>';
	$rewards = ls_get_customer_rewards( $id );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Title', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Issued', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
	foreach ( $rewards as $r ) {
		echo '<tr><td><code>' . esc_html( $r->code ) . '</code></td><td>' . esc_html( $r->title ) . '</td><td>' . esc_html( $r->status ) . '</td><td>' . esc_html( $r->issued_at ) . '</td></tr>';
	}
	echo '</tbody></table>';

	echo '<h2>' . esc_html__( 'Recent activity', 'loyalty-stamps' ) . '</h2>';
	global $wpdb;
	$txs = $wpdb->get_results( $wpdb->prepare( "SELECT t.*, pr.name AS program_name FROM {$wpdb->prefix}ls_transactions t LEFT JOIN {$wpdb->prefix}ls_programs pr ON pr.id = t.program_id WHERE t.customer_id = %d ORDER BY t.id DESC LIMIT 20", $id ) );
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Type', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Program', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Note', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
	foreach ( $txs as $t ) {
		echo '<tr><td>' . esc_html( $t->created_at ) . '</td><td>' . esc_html( $t->type ) . '</td><td>' . esc_html( $t->program_name ?: '—' ) . '</td><td>' . esc_html( $t->staff_note ?: '—' ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=loyalty-customers' ) ) . '">' . esc_html__( 'Back', 'loyalty-stamps' ) . '</a></p>';
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Cards
 * ---------------------------------------------------------------------- */

function ls_page_cards() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$p      = $wpdb->prefix;
	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page   = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per    = 25;
	$where  = '1=1';
	$args   = array();
	if ( '' !== $status ) {
		$where .= ' AND c.status = %s';
		$args[] = $status;
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_cards c WHERE {$where}", ...$args ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.*, cu.name AS customer_name, cu.phone, pr.name AS program_name, pr.stamps_required
			 FROM {$p}ls_cards c
			 JOIN {$p}ls_customers cu ON cu.id = c.customer_id
			 JOIN {$p}ls_programs pr ON pr.id = c.program_id
			 WHERE {$where} ORDER BY c.updated_at DESC LIMIT %d OFFSET %d",
			...array_merge( $args, array( $per, ( $page - 1 ) * $per ) )
		)
	);

	ls_admin_wrap_start( __( 'Cards', 'loyalty-stamps' ) );
	echo '<div class="ls-filters">';
	foreach ( array( '' => __( 'All', 'loyalty-stamps' ), 'active' => __( 'Active', 'loyalty-stamps' ), 'completed' => __( 'Completed', 'loyalty-stamps' ), 'expired' => __( 'Expired', 'loyalty-stamps' ) ) as $k => $label ) {
		$cls = ( $status === $k ) ? 'button-primary' : '';
		echo '<a class="button ' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=loyalty-cards&status=' . $k ) ) . '">' . esc_html( $label ) . '</a> ';
	}
	echo '</div><br>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Program', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Progress', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Updated', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $c ) {
		echo '<tr><td><a href="' . esc_url( admin_url( 'admin.php?page=loyalty-customers&action=view&id=' . (int) $c->customer_id ) ) . '">' . esc_html( $c->customer_name ?: $c->phone ) . '</a></td>';
		echo '<td>' . esc_html( $c->program_name ) . '</td><td>' . (int) $c->stamps . ' / ' . (int) $c->stamps_required . '</td><td>' . esc_html( $c->status ) . '</td><td>' . esc_html( $c->updated_at ) . '</td></tr>';
	}
	echo '</tbody></table>';
	ls_paginate( $total, $per, $page, admin_url( 'admin.php?page=loyalty-cards&status=' . $status ) );
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Transactions
 * ---------------------------------------------------------------------- */

function ls_page_transactions() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	global $wpdb;
	$p    = $wpdb->prefix;
	$type = isset( $_GET['type'] ) ? sanitize_key( $_GET['type'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$page = max( 1, (int) ( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$per  = 30;
	$where = '1=1';
	$args  = array();
	if ( '' !== $type ) {
		$where .= ' AND t.type = %s';
		$args[] = $type;
	}
	$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}ls_transactions t WHERE {$where}", ...$args ) );
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT t.*, cu.name AS customer_name, cu.phone, pr.name AS program_name
			 FROM {$p}ls_transactions t
			 LEFT JOIN {$p}ls_customers cu ON cu.id = t.customer_id
			 LEFT JOIN {$p}ls_programs pr ON pr.id = t.program_id
			 WHERE {$where} ORDER BY t.id DESC LIMIT %d OFFSET %d",
			...array_merge( $args, array( $per, ( $page - 1 ) * $per ) )
		)
	);

	ls_admin_wrap_start( __( 'Transactions', 'loyalty-stamps' ) );
	echo '<div class="ls-filters">';
	foreach ( array( '' => __( 'All', 'loyalty-stamps' ), 'stamp' => __( 'Stamps', 'loyalty-stamps' ), 'bonus' => __( 'Bonus', 'loyalty-stamps' ), 'redeem' => __( 'Redemptions', 'loyalty-stamps' ), 'expire' => __( 'Expiries', 'loyalty-stamps' ) ) as $k => $label ) {
		$cls = ( $type === $k ) ? 'button-primary' : '';
		echo '<a class="button ' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=loyalty-transactions&type=' . $k ) ) . '">' . esc_html( $label ) . '</a> ';
	}
	echo '<a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ls_export_transactions' ), 'ls_export' ) ) . '">' . esc_html__( 'Export CSV', 'loyalty-stamps' ) . '</a>';
	echo '</div><br>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Customer', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Type', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Program', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Note', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
	foreach ( $rows as $t ) {
		echo '<tr><td>' . esc_html( $t->created_at ) . '</td><td>' . esc_html( $t->customer_name ?: $t->phone ) . '</td><td>' . esc_html( $t->type ) . '</td><td>' . esc_html( $t->program_name ?: '—' ) . '</td><td>' . esc_html( $t->staff_note ?: '—' ) . '</td></tr>';
	}
	echo '</tbody></table>';
	ls_paginate( $total, $per, $page, admin_url( 'admin.php?page=loyalty-transactions&type=' . $type ) );
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Rewards
 * ---------------------------------------------------------------------- */

function ls_page_rewards() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ls_reward_issue'] ) && check_admin_referer( 'ls_reward_issue' ) ) {
		$customer = ls_get_customer_by_phone( $_POST['phone'] ?? '' );
		if ( ! $customer ) {
			ls_admin_redirect( 'loyalty-rewards', '', __( 'Customer not found.', 'loyalty-stamps' ) );
		}
		$reward = ls_issue_reward( (int) $customer->id, null, null, sanitize_text_field( $_POST['title'] ?? '' ) );
		if ( $reward ) {
			ls_notify( (int) $customer->id, 'reward_manual', __( 'You received a reward!', 'loyalty-stamps' ), sprintf( __( 'Your reward: %s. Code: %s', 'loyalty-stamps' ), $reward->title, $reward->code ) );
			ls_admin_redirect( 'loyalty-rewards', sprintf( __( 'Reward issued: %s', 'loyalty-stamps' ), $reward->code ) );
		}
		ls_admin_redirect( 'loyalty-rewards', '', __( 'Could not issue reward.', 'loyalty-stamps' ) );
	}
	if ( isset( $_GET['do'] ) && check_admin_referer( 'ls_reward_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$code = sanitize_text_field( wp_unslash( $_GET['code'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'redeem' === $_GET['do'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$r = ls_redeem_reward( $code, 'admin', get_current_user_id() );
			if ( is_wp_error( $r ) ) {
				ls_admin_redirect( 'loyalty-rewards', '', $r->get_error_message() );
			}
			ls_admin_redirect( 'loyalty-rewards', __( 'Reward redeemed.', 'loyalty-stamps' ) );
		}
	}

	global $wpdb;
	$p      = $wpdb->prefix;
	$status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$where  = '1=1';
	$args   = array();
	if ( '' !== $status ) {
		$where .= ' AND r.status = %s';
		$args[] = $status;
	}
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT r.*, cu.name AS customer_name, cu.phone
			 FROM {$p}ls_rewards r
			 JOIN {$p}ls_customers cu ON cu.id = r.customer_id
			 WHERE {$where} ORDER BY r.issued_at DESC LIMIT 100",
			...$args
		)
	);

	ls_admin_wrap_start( __( 'Rewards', 'loyalty-stamps' ) );
	?>
	<div class="ls-panel">
		<h2><?php esc_html_e( 'Issue manual reward', 'loyalty-stamps' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( 'ls_reward_issue' ); ?>
			<input type="text" name="phone" required placeholder="<?php esc_attr_e( 'Customer phone', 'loyalty-stamps' ); ?>">
			<input type="text" name="title" required placeholder="<?php esc_attr_e( 'Reward title', 'loyalty-stamps' ); ?>" class="regular-text">
			<button class="button button-primary" name="ls_reward_issue" value="1"><?php esc_html_e( 'Issue', 'loyalty-stamps' ); ?></button>
		</form>
	</div>
	<?php
	echo '<div class="ls-filters">';
	foreach ( array( '' => __( 'All', 'loyalty-stamps' ), 'issued' => __( 'Issued', 'loyalty-stamps' ), 'redeemed' => __( 'Redeemed', 'loyalty-stamps' ), 'expired' => __( 'Expired', 'loyalty-stamps' ) ) as $k => $label ) {
		$cls = ( $status === $k ) ? 'button-primary' : '';
		echo '<a class="button ' . esc_attr( $cls ) . '" href="' . esc_url( admin_url( 'admin.php?page=loyalty-rewards&status=' . $k ) ) . '">' . esc_html( $label ) . '</a> ';
	}
	echo '</div><br>';
	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Code', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Customer', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Title', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Issued', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Expires', 'loyalty-stamps' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( $rows as $r ) {
		echo '<tr><td><code>' . esc_html( $r->code ) . '</code></td><td>' . esc_html( $r->customer_name ?: $r->phone ) . '</td><td>' . esc_html( $r->title ) . '</td><td>' . esc_html( $r->status ) . '</td><td>' . esc_html( $r->issued_at ) . '</td><td>' . esc_html( $r->expires_at ?: '—' ) . '</td><td>';
		if ( 'issued' === $r->status ) {
			echo '<a class="button button-small" href="' . esc_url( wp_nonce_url( admin_url( 'admin.php?page=loyalty-rewards&do=redeem&code=' . rawurlencode( $r->code ) ), 'ls_reward_action' ) ) . '">' . esc_html__( 'Redeem', 'loyalty-stamps' ) . '</a>';
		}
		echo '</td></tr>';
	}
	echo '</tbody></table>';
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Stores (geofence)
 * ---------------------------------------------------------------------- */

function ls_page_stores() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	// Handle save BEFORE action routing (otherwise POSTs from the edit form return early).
	if ( isset( $_POST['ls_store_save'] ) && check_admin_referer( 'ls_store_save' ) ) {
		$id = (int) ( $_POST['id'] ?? 0 );
		ls_save_store(
			array(
				'name'          => $_POST['name'] ?? '',
				'address'       => $_POST['address'] ?? '',
				'lat'           => $_POST['lat'] ?? '',
				'lng'           => $_POST['lng'] ?? '',
				'radius_km'     => $_POST['radius_km'] ?? 1,
				'promo_message' => $_POST['promo_message'] ?? '',
				'active'        => $_POST['active'] ?? 0,
			),
			$id
		);
		ls_admin_redirect( 'loyalty-stores', __( 'Store saved.', 'loyalty-stamps' ) );
	}
	if ( isset( $_GET['do'] ) && check_admin_referer( 'ls_store_action' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id = (int) ( $_GET['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'delete' === $_GET['do'] && $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			ls_delete_store( $id );
		}
		ls_admin_redirect( 'loyalty-stores', __( 'Done.', 'loyalty-stamps' ) );
	}
	if ( isset( $_POST['ls_broadcast'] ) && check_admin_referer( 'ls_broadcast' ) ) {
		$n = ls_broadcast_nearby( (int) ( $_POST['store_id'] ?? 0 ), sanitize_textarea_field( $_POST['message'] ?? '' ) );
		if ( is_wp_error( $n ) ) {
			ls_admin_redirect( 'loyalty-stores', '', $n->get_error_message() );
		}
		ls_admin_redirect( 'loyalty-stores', sprintf( __( 'Promo queued for %d nearby customers.', 'loyalty-stamps' ), (int) $n ) );
	}

	$action = $_GET['action'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( 'edit' === $action || 'new' === $action ) {
		ls_page_store_edit( 'edit' === $action ? (int) ( $_GET['id'] ?? 0 ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	ls_admin_wrap_start( __( 'Stores & Geofence', 'loyalty-stamps' ) );
	echo '<p>' . esc_html__( 'When a customer shares their location and is within the radius of a store, they automatically receive a promo (with cooldown and quiet hours applied). Set coordinates to enable.', 'loyalty-stamps' ) . '</p>';
	echo '<a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=loyalty-stores&action=new' ) ) . '">' . esc_html__( 'Add store', 'loyalty-stamps' ) . '</a><br><br>';

	echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Store', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Coordinates', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Radius', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th></th></tr></thead><tbody>';
	foreach ( ls_get_stores() as $st ) {
		$base = admin_url( 'admin.php?page=loyalty-stores&id=' . (int) $st->id );
		echo '<tr><td><strong>' . esc_html( $st->name ) . '</strong><br><span class="description">' . esc_html( $st->address ?: '' ) . '</span></td>';
		echo '<td>' . ( null !== $st->lat ? esc_html( $st->lat . ', ' . $st->lng ) : '<em>' . esc_html__( 'not set', 'loyalty-stamps' ) . '</em>' ) . '</td>';
		echo '<td>' . esc_html( $st->radius_km ) . ' km</td>';
		echo '<td>' . ( $st->active ? esc_html__( 'Active', 'loyalty-stamps' ) : esc_html__( 'Inactive', 'loyalty-stamps' ) ) . '</td>';
		echo '<td><a href="' . esc_url( $base . '&action=edit' ) . '">' . esc_html__( 'Edit', 'loyalty-stamps' ) . '</a> | ';
		echo '<a href="' . esc_url( wp_nonce_url( $base . '&do=delete', 'ls_store_action' ) ) . '" onclick="return confirm(\'' . esc_js( __( 'Delete this store?', 'loyalty-stamps' ) ) . '\')">' . esc_html__( 'Delete', 'loyalty-stamps' ) . '</a></td></tr>';
	}
	echo '</tbody></table>';

	?>
	<div class="ls-panel">
		<h2><?php esc_html_e( 'Broadcast promo to nearby customers', 'loyalty-stamps' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Sends a promo to customers whose last known location is inside a store radius.', 'loyalty-stamps' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'ls_broadcast' ); ?>
			<select name="store_id" required>
				<?php foreach ( ls_get_stores( true ) as $st ) : ?>
					<option value="<?php echo (int) $st->id; ?>"><?php echo esc_html( $st->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="text" name="message" class="regular-text" placeholder="<?php esc_attr_e( 'Custom message (optional)', 'loyalty-stamps' ); ?>">
			<button class="button button-primary" name="ls_broadcast" value="1"><?php esc_html_e( 'Send', 'loyalty-stamps' ); ?></button>
		</form>
	</div>
	<?php
	ls_admin_wrap_end();
}

function ls_page_store_edit( $id ) {
	$st = $id ? ls_get_store( $id ) : null;
	ls_admin_wrap_start( $id ? __( 'Edit store', 'loyalty-stamps' ) : __( 'New store', 'loyalty-stamps' ) );
	$v = function ( $k, $d = '' ) use ( $st ) {
		return $st ? $st->$k : $d;
	};
	?>
	<form method="post" class="ls-form">
		<?php wp_nonce_field( 'ls_store_save' ); ?>
		<input type="hidden" name="id" value="<?php echo (int) $id; ?>">
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Name', 'loyalty-stamps' ); ?></th><td><input type="text" name="name" required class="regular-text" value="<?php echo esc_attr( $v( 'name' ) ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Address', 'loyalty-stamps' ); ?></th><td><input type="text" name="address" class="regular-text" value="<?php echo esc_attr( $v( 'address' ) ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Latitude', 'loyalty-stamps' ); ?></th><td><input type="text" name="lat" value="<?php echo esc_attr( $v( 'lat' ) ); ?>" placeholder="50.2649"></td></tr>
			<tr><th><?php esc_html_e( 'Longitude', 'loyalty-stamps' ); ?></th><td><input type="text" name="lng" value="<?php echo esc_attr( $v( 'lng' ) ); ?>" placeholder="19.0238"></td></tr>
			<tr><th><?php esc_html_e( 'Radius (km)', 'loyalty-stamps' ); ?></th><td><input type="number" name="radius_km" step="0.1" min="0.1" value="<?php echo esc_attr( $v( 'radius_km', 1 ) ); ?>"></td></tr>
			<tr><th><?php esc_html_e( 'Promo message for nearby customers', 'loyalty-stamps' ); ?></th><td><textarea name="promo_message" rows="3" class="large-text" placeholder="<?php esc_attr_e( 'Leave empty to use the default template', 'loyalty-stamps' ); ?>"><?php echo esc_textarea( $v( 'promo_message' ) ); ?></textarea></td></tr>
			<tr><th><?php esc_html_e( 'Active', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="active" value="1" <?php checked( (int) $v( 'active', 1 ), 1 ); ?>></td></tr>
		</table>
		<p><button class="button button-primary" name="ls_store_save" value="1"><?php esc_html_e( 'Save store', 'loyalty-stamps' ); ?></button>
		<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=loyalty-stores' ) ); ?>"><?php esc_html_e( 'Back', 'loyalty-stamps' ); ?></a></p>
	</form>
	<?php
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Notifications
 * ---------------------------------------------------------------------- */

function ls_page_notifications() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ls_process_now'] ) && check_admin_referer( 'ls_process_now' ) ) {
		$n = ls_process_queue( 50 );
		ls_admin_redirect( 'loyalty-notifications', sprintf( __( 'Processed %d notifications.', 'loyalty-stamps' ), (int) $n ) );
	}
	if ( isset( $_POST['ls_test_send'] ) && check_admin_referer( 'ls_test_send' ) ) {
		$customer = ls_get_customer_by_phone( $_POST['phone'] ?? '' );
		if ( ! $customer ) {
			ls_admin_redirect( 'loyalty-notifications&tab=log', '', __( 'Customer not found.', 'loyalty-stamps' ) );
		}
		ls_notify( (int) $customer->id, 'test', __( 'Test notification', 'loyalty-stamps' ), sanitize_textarea_field( $_POST['message'] ?? '' ) );
		ls_admin_redirect( 'loyalty-notifications&tab=log', __( 'Test notification queued.', 'loyalty-stamps' ) );
	}

	$tab = $_GET['tab'] ?? 'queue'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	ls_admin_wrap_start( __( 'Notifications', 'loyalty-stamps' ) );
	echo '<h2 class="nav-tab-wrapper"><a class="nav-tab' . ( 'queue' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=loyalty-notifications&tab=queue' ) ) . '">' . esc_html__( 'Queue', 'loyalty-stamps' ) . '</a>';
	echo '<a class="nav-tab' . ( 'log' === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=loyalty-notifications&tab=log' ) ) . '">' . esc_html__( 'Log', 'loyalty-stamps' ) . '</a></h2>';

	global $wpdb;
	$p = $wpdb->prefix;
	if ( 'queue' === $tab ) {
		echo '<form method="post" style="margin:10px 0;">' . wp_nonce_field( 'ls_process_now', '_wpnonce', true, false );
		echo '<button class="button button-primary" name="ls_process_now" value="1">' . esc_html__( 'Process queue now', 'loyalty-stamps' ) . '</button></form>';
		$rows = $wpdb->get_results( "SELECT n.*, cu.phone FROM {$p}ls_notifications n LEFT JOIN {$p}ls_customers cu ON cu.id = n.customer_id WHERE n.status = 'queued' ORDER BY n.id ASC LIMIT 100" );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Customer', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Channel', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Title', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Attempts', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Created', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( $r->phone ?: '—' ) . '</td><td>' . esc_html( $r->channel ) . '</td><td>' . esc_html( $r->title ) . '</td><td>' . (int) $r->attempts . '</td><td>' . esc_html( $r->created_at ) . '</td></tr>';
		}
		echo '</tbody></table>';
	} else {
		?>
		<div class="ls-panel">
			<h2><?php esc_html_e( 'Send test notification', 'loyalty-stamps' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'ls_test_send' ); ?>
				<input type="text" name="phone" required placeholder="<?php esc_attr_e( 'Customer phone', 'loyalty-stamps' ); ?>">
				<input type="text" name="message" class="regular-text" placeholder="<?php esc_attr_e( 'Message', 'loyalty-stamps' ); ?>">
				<button class="button button-primary" name="ls_test_send" value="1"><?php esc_html_e( 'Send', 'loyalty-stamps' ); ?></button>
			</form>
		</div>
		<?php
		$rows = $wpdb->get_results( "SELECT n.*, cu.phone FROM {$p}ls_notifications n LEFT JOIN {$p}ls_customers cu ON cu.id = n.customer_id WHERE n.status IN ('sent','failed') ORDER BY n.id DESC LIMIT 100" );
		echo '<table class="widefat striped"><thead><tr><th>ID</th><th>' . esc_html__( 'Customer', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Channel', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Kind', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Status', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Error', 'loyalty-stamps' ) . '</th><th>' . esc_html__( 'Sent', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( $r->phone ?: '—' ) . '</td><td>' . esc_html( $r->channel ) . '</td><td>' . esc_html( $r->kind ) . '</td><td>' . esc_html( $r->status ) . '</td><td>' . esc_html( $r->error ? mb_substr( $r->error, 0, 80 ) : '—' ) . '</td><td>' . esc_html( $r->sent_at ?: '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function ls_page_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	if ( isset( $_POST['ls_settings_save'] ) && check_admin_referer( 'ls_settings_save' ) ) {
		$tpl_keys = array( 'tpl_stamp', 'tpl_complete', 'tpl_reward_expiry', 'tpl_program_change', 'tpl_nearby', 'tpl_welcome', 'tpl_birthday', 'tpl_referral' );
		$data     = array(
			'business_name'           => sanitize_text_field( $_POST['business_name'] ?? '' ),
			'theme'                   => 'dark' === ( $_POST['theme'] ?? '' ) ? 'dark' : 'light',
			'enable_inbox'            => ! empty( $_POST['enable_inbox'] ) ? 1 : 0,
			'enable_email'            => ! empty( $_POST['enable_email'] ) ? 1 : 0,
			'enable_push'             => ! empty( $_POST['enable_push'] ) ? 1 : 0,
			'enable_whatsapp'         => ! empty( $_POST['enable_whatsapp'] ) ? 1 : 0,
			'whatsapp_webhook'        => esc_url_raw( $_POST['whatsapp_webhook'] ?? '' ),
			'whatsapp_method'         => 'POST' === ( $_POST['whatsapp_method'] ?? '' ) ? 'POST' : 'GET',
			'vapid_subject'           => sanitize_text_field( $_POST['vapid_subject'] ?? '' ),
			'geofence_cooldown_h'     => max( 1, (int) ( $_POST['geofence_cooldown_h'] ?? 24 ) ),
			'geofence_quiet_from'     => sanitize_text_field( $_POST['geofence_quiet_from'] ?? '22:00' ),
			'geofence_quiet_to'       => sanitize_text_field( $_POST['geofence_quiet_to'] ?? '09:00' ),
			'geofence_location_ttl_h' => max( 1, (int) ( $_POST['geofence_location_ttl_h'] ?? 12 ) ),
			'max_stamps_per_day'      => max( 0, (int) ( $_POST['max_stamps_per_day'] ?? 5 ) ),
			'stamp_cooldown_h'        => max( 0, (int) ( $_POST['stamp_cooldown_h'] ?? 12 ) ),
			'reward_expiry_days'      => max( 0, (int) ( $_POST['reward_expiry_days'] ?? 60 ) ),
			'referral_enabled'        => ! empty( $_POST['referral_enabled'] ) ? 1 : 0,
			'referral_bonus'          => max( 1, (int) ( $_POST['referral_bonus'] ?? 1 ) ),
			'birthday_enabled'        => ! empty( $_POST['birthday_enabled'] ) ? 1 : 0,
			'birthday_reward'         => sanitize_text_field( $_POST['birthday_reward'] ?? '' ),
		);
		foreach ( $tpl_keys as $k ) {
			if ( isset( $_POST[ $k ] ) ) {
				$data[ $k ] = sanitize_textarea_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		ls_update_settings( $data );
		ls_admin_redirect( 'loyalty-settings', __( 'Settings saved.', 'loyalty-stamps' ) );
	}
	if ( isset( $_POST['ls_pin_regen'] ) && check_admin_referer( 'ls_pin_regen' ) ) {
		ls_regenerate_staff_pin();
		ls_admin_redirect( 'loyalty-settings', __( 'New staff PIN generated.', 'loyalty-stamps' ) );
	}
	if ( isset( $_POST['ls_pin_set'] ) && check_admin_referer( 'ls_pin_set' ) ) {
		$r = ls_set_staff_pin( isset( $_POST['ls_new_pin'] ) ? wp_unslash( $_POST['ls_new_pin'] ) : '' );
		if ( is_wp_error( $r ) ) {
			ls_admin_redirect( 'loyalty-settings', '', $r->get_error_message() );
		}
		ls_admin_redirect( 'loyalty-settings', __( 'Staff PIN changed.', 'loyalty-stamps' ) );
	}
	if ( isset( $_POST['ls_vapid_gen'] ) && check_admin_referer( 'ls_vapid_gen' ) ) {
		$r = ls_vapid_generate();
		if ( is_wp_error( $r ) ) {
			ls_admin_redirect( 'loyalty-settings', '', $r->get_error_message() );
		}
		ls_admin_redirect( 'loyalty-settings', __( 'VAPID keys generated.', 'loyalty-stamps' ) );
	}

	$plain_pin = get_option( 'ls_staff_pin_plain', '' );

	ls_admin_wrap_start( __( 'Loyalty Settings', 'loyalty-stamps' ) );
	?>
	<form method="post">
		<?php wp_nonce_field( 'ls_settings_save' ); ?>

		<div class="ls-panel"><h2><?php esc_html_e( 'General', 'loyalty-stamps' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Business name', 'loyalty-stamps' ); ?></th><td><input type="text" name="business_name" class="regular-text" value="<?php echo esc_attr( ls_setting( 'business_name' ) ); ?>"></td></tr>
				<tr><th>Page design</th><td><select name="theme"><option value="light"<?php selected( ls_setting( 'theme', 'light' ), 'light' ); ?>>Light - same as the menu</option><option value="dark"<?php selected( ls_setting( 'theme', 'light' ), 'dark' ); ?>>Dark - same as the home page</option></select></td></tr>
				<tr><th><?php esc_html_e( 'Staff PIN', 'loyalty-stamps' ); ?></th><td>
					<?php if ( $plain_pin ) : ?>
						<code style="font-size:18px"><?php echo esc_html( $plain_pin ); ?></code>
						<p class="description"><?php esc_html_e( 'Staff use this PIN in the staff panel to add stamps and redeem rewards. Keep it secret.', 'loyalty-stamps' ); ?></p>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'PIN is set. Regenerate to get a new one.', 'loyalty-stamps' ); ?></p>
					<?php endif; ?>
				</td></tr>
			</table>
		</div>

		<div class="ls-panel"><h2><?php esc_html_e( 'Notification channels', 'loyalty-stamps' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'In-app inbox', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="enable_inbox" value="1" <?php checked( ls_setting( 'enable_inbox' ), 1 ); ?>></td></tr>
				<tr><th><?php esc_html_e( 'Email', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="enable_email" value="1" <?php checked( ls_setting( 'enable_email' ), 1 ); ?>></td></tr>
				<tr><th><?php esc_html_e( 'Web Push', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="enable_push" value="1" <?php checked( ls_setting( 'enable_push' ), 1 ); ?>> <span class="description"><?php esc_html_e( 'Requires VAPID keys below + HTTPS.', 'loyalty-stamps' ); ?></span></td></tr>
				<tr><th><?php esc_html_e( 'WhatsApp', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="enable_whatsapp" value="1" <?php checked( ls_setting( 'enable_whatsapp' ), 1 ); ?>></td></tr>
				<tr><th><?php esc_html_e( 'WhatsApp webhook URL', 'loyalty-stamps' ); ?></th><td><input type="url" name="whatsapp_webhook" class="large-text" value="<?php echo esc_attr( ls_setting( 'whatsapp_webhook' ) ); ?>" placeholder="https://api.ultramsg.com/instance.../messages/chat?token=...&to={phone}&body={message}">
					<p class="description"><?php esc_html_e( 'Use {phone} and {message} placeholders. Works with Ultramsg, WhatsApp Cloud API wrappers, etc.', 'loyalty-stamps' ); ?></p></td></tr>
				<tr><th><?php esc_html_e( 'Webhook method', 'loyalty-stamps' ); ?></th><td><select name="whatsapp_method"><option value="GET" <?php selected( ls_setting( 'whatsapp_method' ), 'GET' ); ?>>GET</option><option value="POST" <?php selected( ls_setting( 'whatsapp_method' ), 'POST' ); ?>>POST</option></select></td></tr>
			</table>
		</div>

		<div class="ls-panel"><h2><?php esc_html_e( 'Web Push (VAPID)', 'loyalty-stamps' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Public key', 'loyalty-stamps' ); ?></th><td><code><?php echo esc_html( ls_setting( 'vapid_public' ) ?: __( 'not generated', 'loyalty-stamps' ) ); ?></code></td></tr>
				<tr><th><?php esc_html_e( 'Subject', 'loyalty-stamps' ); ?></th><td><input type="text" name="vapid_subject" class="regular-text" value="<?php echo esc_attr( ls_setting( 'vapid_subject' ) ); ?>" placeholder="mailto:you@example.com"></td></tr>
			</table>
		</div>

		<div class="ls-panel"><h2><?php esc_html_e( 'Geofence (nearby promos)', 'loyalty-stamps' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Cooldown between promos (hours)', 'loyalty-stamps' ); ?></th><td><input type="number" name="geofence_cooldown_h" min="1" value="<?php echo (int) ls_setting( 'geofence_cooldown_h' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Quiet hours (no promos)', 'loyalty-stamps' ); ?></th><td><input type="time" name="geofence_quiet_from" value="<?php echo esc_attr( ls_setting( 'geofence_quiet_from' ) ); ?>"> — <input type="time" name="geofence_quiet_to" value="<?php echo esc_attr( ls_setting( 'geofence_quiet_to' ) ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Location freshness for broadcast (hours)', 'loyalty-stamps' ); ?></th><td><input type="number" name="geofence_location_ttl_h" min="1" value="<?php echo (int) ls_setting( 'geofence_location_ttl_h' ); ?>"></td></tr>
			</table>
		</div>

		<div class="ls-panel"><h2><?php esc_html_e( 'Limits & extras', 'loyalty-stamps' ); ?></h2>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Max stamps per card per day (0 = unlimited)', 'loyalty-stamps' ); ?></th><td><input type="number" name="max_stamps_per_day" min="0" value="<?php echo (int) ls_setting( 'max_stamps_per_day' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Hours between stamps on the same card (0 = no limit)', 'loyalty-stamps' ); ?></th><td><input type="number" name="stamp_cooldown_h" min="0" value="<?php echo (int) ls_setting( 'stamp_cooldown_h' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Reward expiry (days, 0 = never)', 'loyalty-stamps' ); ?></th><td><input type="number" name="reward_expiry_days" min="0" value="<?php echo (int) ls_setting( 'reward_expiry_days' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Referral program', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="referral_enabled" value="1" <?php checked( ls_setting( 'referral_enabled' ), 1 ); ?>> <?php esc_html_e( 'Bonus stamps', 'loyalty-stamps' ); ?> <input type="number" name="referral_bonus" min="1" value="<?php echo (int) ls_setting( 'referral_bonus' ); ?>"></td></tr>
				<tr><th><?php esc_html_e( 'Birthday rewards', 'loyalty-stamps' ); ?></th><td><input type="checkbox" name="birthday_enabled" value="1" <?php checked( ls_setting( 'birthday_enabled' ), 1 ); ?>> <input type="text" name="birthday_reward" class="regular-text" value="<?php echo esc_attr( ls_setting( 'birthday_reward' ) ); ?>" placeholder="<?php esc_attr_e( 'Reward title', 'loyalty-stamps' ); ?>"></td></tr>
			</table>
		</div>

		<div class="ls-panel"><h2><?php esc_html_e( 'Message templates', 'loyalty-stamps' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Placeholders: {name} {store} {program} {stamps} {required} {reward} {code} {change}', 'loyalty-stamps' ); ?></p>
			<table class="form-table">
				<?php
				$tpls = array(
					'tpl_stamp'          => __( 'Stamp earned', 'loyalty-stamps' ),
					'tpl_complete'       => __( 'Card completed', 'loyalty-stamps' ),
					'tpl_reward_expiry'  => __( 'Reward expiring', 'loyalty-stamps' ),
					'tpl_program_change' => __( 'Program changed', 'loyalty-stamps' ),
					'tpl_nearby'         => __( 'Nearby promo', 'loyalty-stamps' ),
					'tpl_welcome'        => __( 'Welcome', 'loyalty-stamps' ),
					'tpl_birthday'       => __( 'Birthday', 'loyalty-stamps' ),
					'tpl_referral'       => __( 'Referral bonus', 'loyalty-stamps' ),
				);
				foreach ( $tpls as $k => $label ) :
					?>
					<tr><th><?php echo esc_html( $label ); ?></th><td><textarea name="<?php echo esc_attr( $k ); ?>" rows="2" class="large-text"><?php echo esc_textarea( ls_setting( $k ) ); ?></textarea></td></tr>
				<?php endforeach; ?>
			</table>
		</div>

		<p><button class="button button-primary button-large" name="ls_settings_save" value="1"><?php esc_html_e( 'Save settings', 'loyalty-stamps' ); ?></button></p>
	</form>

	<div class="ls-panel"><h2><?php esc_html_e( 'Danger zone', 'loyalty-stamps' ); ?></h2>
		<form method="post" style="display:inline-block;margin-right:10px;">
			<?php wp_nonce_field( 'ls_pin_set' ); ?>
			<input type="text" name="ls_new_pin" inputmode="numeric" pattern="[0-9]*" maxlength="8" size="8" placeholder="<?php esc_attr_e( 'New PIN (4-8 digits)', 'loyalty-stamps' ); ?>">
			<button class="button" name="ls_pin_set" value="1" onclick="return confirm('<?php echo esc_js( __( 'Change the staff PIN? The old one will stop working.', 'loyalty-stamps' ) ); ?>')"><?php esc_html_e( 'Set staff PIN', 'loyalty-stamps' ); ?></button>
		</form>
		<form method="post" style="display:inline-block;margin-right:10px;">
			<?php wp_nonce_field( 'ls_pin_regen' ); ?>
			<button class="button" name="ls_pin_regen" value="1" onclick="return confirm('<?php echo esc_js( __( 'Generate a new staff PIN? The old one will stop working.', 'loyalty-stamps' ) ); ?>')"><?php esc_html_e( 'Regenerate staff PIN', 'loyalty-stamps' ); ?></button>
		</form>
		<form method="post" style="display:inline-block;">
			<?php wp_nonce_field( 'ls_vapid_gen' ); ?>
			<button class="button" name="ls_vapid_gen" value="1" onclick="return confirm('<?php echo esc_js( __( 'Generate new VAPID keys? Existing push subscriptions will stop working.', 'loyalty-stamps' ) ); ?>')"><?php esc_html_e( 'Generate VAPID keys', 'loyalty-stamps' ); ?></button>
		</form>
	</div>
	<?php
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * CSV exports
 * ---------------------------------------------------------------------- */

add_action( 'admin_post_ls_export_customers', 'ls_export_customers' );
function ls_export_customers() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ls_export' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'loyalty-stamps' ) );
	}
	global $wpdb;
	$p    = $wpdb->prefix;
	$rows = $wpdb->get_results( "SELECT id, name, phone, email, lang, referral_code, created_at FROM {$p}ls_customers ORDER BY id ASC", ARRAY_A );
	ls_csv_download( 'customers.csv', array( 'id', 'name', 'phone', 'email', 'lang', 'referral_code', 'created_at' ), $rows );
}

add_action( 'admin_post_ls_export_transactions', 'ls_export_transactions' );
function ls_export_transactions() {
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'ls_export' ) ) {
		wp_die( esc_html__( 'Not allowed.', 'loyalty-stamps' ) );
	}
	global $wpdb;
	$p    = $wpdb->prefix;
	$rows = $wpdb->get_results(
		"SELECT t.id, t.created_at, cu.name AS customer, cu.phone, pr.name AS program, t.type, t.delta, t.staff_note
		 FROM {$p}ls_transactions t
		 LEFT JOIN {$p}ls_customers cu ON cu.id = t.customer_id
		 LEFT JOIN {$p}ls_programs pr ON pr.id = t.program_id
		 ORDER BY t.id ASC",
		ARRAY_A
	);
	ls_csv_download( 'transactions.csv', array( 'id', 'created_at', 'customer', 'phone', 'program', 'type', 'delta', 'staff_note' ), $rows );
}

function ls_csv_download( $filename, $headers, $rows ) {
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	echo "\xEF\xBB\xBF"; // BOM for Excel
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, $headers );
	foreach ( $rows as $row ) {
		$line = array();
		foreach ( $headers as $h ) {
			$line[] = $row[ $h ] ?? '';
		}
		fputcsv( $out, $line );
	}
	fclose( $out );
	exit;
}
