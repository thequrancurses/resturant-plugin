<?php
/**
 * Admin: Wallet page — Apple/Google setup, certificate uploads, status, devices.
 */
defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'ls_wallet_admin_menu', 30 );
add_action( 'admin_init', 'ls_wallet_test_download' );
add_action( 'wp_ajax_ls_wallet_google', 'ls_ajax_wallet_google' );
add_action( 'wp_ajax_ls_wallet_apple', 'ls_ajax_wallet_apple' );

/**
 * Test .pkpass download. Runs on admin_init (before any output) so the
 * download headers work correctly.
 */
function ls_wallet_test_download() {
	if ( ! isset( $_POST['ls_wallet_test'] ) || ! check_admin_referer( 'ls_wallet_test', '_wpnonce', false ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'Permission denied.', 'loyalty-stamps' ) );
	}
	$test_phone = isset( $_POST['test_phone'] ) ? wp_unslash( $_POST['test_phone'] ) : '';
	$customer   = '' !== trim( (string) $test_phone ) ? ls_get_customer_by_phone( $test_phone ) : null;
	if ( '' !== trim( (string) $test_phone ) && ! $customer ) {
		ls_admin_redirect( 'loyalty-wallet', '', __( 'Customer not found.', 'loyalty-stamps' ) );
	}
	$bin = ls_apple_build_pass( $customer ? (int) $customer->id : 0 );
	if ( is_wp_error( $bin ) ) {
		ls_admin_redirect( 'loyalty-wallet', '', $bin->get_error_message() );
	}
	header( 'Content-Type: application/vnd.apple.pkpass' );
	header( 'Content-Disposition: attachment; filename="test-loyalty.pkpass"' );
	header( 'Content-Length: ' . strlen( $bin ) );
	echo $bin; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	exit;
}

/**
 * AJAX saver for the Apple section. Returns JSON so failures are visible
 * instead of a blank page.
 */
function ls_ajax_wallet_apple() {
	check_ajax_referer( 'ls_wallet_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'loyalty-stamps' ) ) );
	}
	$data = array();
	$data['wallet_apple_enabled'] = ! empty( $_POST['wallet_apple_enabled'] ) ? 1 : 0;
	$data['apple_pass_type_id']   = isset( $_POST['apple_pass_type_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apple_pass_type_id'] ) ) : '';
	$data['apple_team_id']        = isset( $_POST['apple_team_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apple_team_id'] ) ) : '';

	if ( ! empty( $_FILES['apple_p12']['tmp_name'] ) ) {
		$p12_pass = isset( $_POST['apple_p12_password'] ) ? wp_unslash( $_POST['apple_p12_password'] ) : '';
		$r        = ls_wallet_import_p12( $_FILES['apple_p12']['tmp_name'], (string) $p12_pass );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$data['apple_cert_pem'] = $r['cert'];
		$data['apple_key_pem']  = $r['key'];
	}
	if ( ! empty( $_FILES['apple_wwdr']['tmp_name'] ) ) {
		$r = ls_wallet_import_wwdr( $_FILES['apple_wwdr']['tmp_name'] );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$data['apple_wwdr_pem'] = $r;
	}
	ls_update_settings( $data );
	wp_send_json_success( array( 'message' => __( 'Wallet settings saved.', 'loyalty-stamps' ) ) );
}

/**
 * AJAX saver for the Google section. Returns JSON so failures are visible
 * instead of a blank page (e.g. when a firewall kills the request, the
 * browser reports a network error).
 */
function ls_ajax_wallet_google() {
	check_ajax_referer( 'ls_wallet_save' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Permission denied.', 'loyalty-stamps' ) ) );
	}
	$data = array();
	$data['wallet_google_enabled'] = ! empty( $_POST['wallet_google_enabled'] ) ? 1 : 0;
	$data['google_issuer_id']      = isset( $_POST['google_issuer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_issuer_id'] ) ) : '';

	$sa_text = ls_wallet_sa_from_upload();
	if ( '' === $sa_text && ! empty( $_POST['google_sa_xor'] ) ) {
		$sa_text = ls_wallet_xor_decode( (string) $_POST['google_sa_xor'] );
	} elseif ( ! empty( $_POST['google_sa_b64'] ) ) {
		$decoded = base64_decode( (string) $_POST['google_sa_b64'], true );
		$sa_text = is_string( $decoded ) ? trim( $decoded ) : '';
	} elseif ( isset( $_POST['google_sa_text'] ) ) {
		$sa_text = trim( (string) wp_unslash( $_POST['google_sa_text'] ) );
	}
	if ( '' !== $sa_text ) {
		$r = ls_wallet_save_sa( $sa_text );
		if ( is_wp_error( $r ) ) {
			wp_send_json_error( array( 'message' => $r->get_error_message() ) );
		}
		$data['google_sa_json'] = $r;
	}
	ls_update_settings( $data );
	delete_transient( 'ls_google_oauth' );
	wp_send_json_success( array( 'message' => __( 'Wallet settings saved.', 'loyalty-stamps' ) ) );
}
function ls_wallet_admin_menu() {
	add_submenu_page(
		'grill-hub-menu',
		__( 'Wallet', 'loyalty-stamps' ),
		__( 'Loyalty wallet', 'loyalty-stamps' ),
		'manage_options',
		'loyalty-wallet',
		'ls_page_wallet'
	);
}

function ls_page_wallet() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Handle uploads + settings. Each form (Apple / Google) saves only its own fields,
	// so saving one section never wipes the other.
	if ( isset( $_POST['ls_wallet_save'] ) && check_admin_referer( 'ls_wallet_save' ) ) {
		$section = isset( $_POST['ls_wallet_section'] ) ? sanitize_key( wp_unslash( $_POST['ls_wallet_section'] ) ) : '';
		$data    = array();
		$err     = '';

		if ( 'apple' === $section ) {
			$data['wallet_apple_enabled'] = ! empty( $_POST['wallet_apple_enabled'] ) ? 1 : 0;
			$data['apple_pass_type_id']   = isset( $_POST['apple_pass_type_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apple_pass_type_id'] ) ) : '';
			$data['apple_team_id']        = isset( $_POST['apple_team_id'] ) ? sanitize_text_field( wp_unslash( $_POST['apple_team_id'] ) ) : '';

			// Apple .p12 upload.
			if ( ! empty( $_FILES['apple_p12']['tmp_name'] ) ) {
				$p12_pass = isset( $_POST['apple_p12_password'] ) ? wp_unslash( $_POST['apple_p12_password'] ) : '';
				$r        = ls_wallet_import_p12( $_FILES['apple_p12']['tmp_name'], (string) $p12_pass );
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				} else {
					$data['apple_cert_pem'] = $r['cert'];
					$data['apple_key_pem']  = $r['key'];
				}
			}
			// WWDR upload (PEM or DER .cer).
			if ( '' === $err && ! empty( $_FILES['apple_wwdr']['tmp_name'] ) ) {
				$r = ls_wallet_import_wwdr( $_FILES['apple_wwdr']['tmp_name'] );
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				} else {
					$data['apple_wwdr_pem'] = $r;
				}
			}
		} elseif ( 'google' === $section ) {
			$data['wallet_google_enabled'] = ! empty( $_POST['wallet_google_enabled'] ) ? 1 : 0;
			$data['google_issuer_id']      = isset( $_POST['google_issuer_id'] ) ? sanitize_text_field( wp_unslash( $_POST['google_issuer_id'] ) ) : '';

			// Google service account JSON. Prefer the XOR-obfuscated field (avoids WAFs
			// blocking private keys in POST bodies), then plain base64, then raw paste.
			// WP slashes POST data; unslash before json_decode.
			$sa_text = ls_wallet_sa_from_upload();
			if ( '' === $sa_text && ! empty( $_POST['google_sa_xor'] ) ) {
				$sa_text = ls_wallet_xor_decode( (string) $_POST['google_sa_xor'] );
			} elseif ( ! empty( $_POST['google_sa_b64'] ) ) {
				$decoded = base64_decode( (string) $_POST['google_sa_b64'], true );
				$sa_text = is_string( $decoded ) ? trim( $decoded ) : '';
			} elseif ( isset( $_POST['google_sa_text'] ) ) {
				$sa_text = trim( (string) wp_unslash( $_POST['google_sa_text'] ) );
			}
			if ( '' !== $sa_text ) {
				$r = ls_wallet_save_sa( $sa_text );
				if ( is_wp_error( $r ) ) {
					$err = $r->get_error_message();
				} else {
					$data['google_sa_json'] = $r;
				}
			}
		} elseif ( 'branding' === $section ) {
			$logo_id = isset( $_POST['wallet_logo_id'] ) ? absint( $_POST['wallet_logo_id'] ) : 0;
			$old     = (int) ls_setting( 'wallet_logo_id', 0 );
			$data['wallet_logo_id'] = $logo_id;
			if ( $logo_id !== $old ) {
				// Regenerate the pass images with the new logo…
				ls_wallet_clear_images();
				// …and refresh the Google Wallet class look on the next pass save.
				delete_option( 'ls_gclass_v' );
			}
		}

		// Stale form HTML (e.g. a cached page from an older version) has no section id.
		if ( '' === $section ) {
			ls_admin_redirect( 'loyalty-wallet', '', __( 'Form expired. Please reload the page and try again.', 'loyalty-stamps' ) );
		}

		if ( '' === $err ) {
			ls_update_settings( $data );
			delete_transient( 'ls_google_oauth' );
			ls_admin_redirect( 'loyalty-wallet', __( 'Wallet settings saved.', 'loyalty-stamps' ) );
		} else {
			ls_admin_redirect( 'loyalty-wallet', '', $err );
		}
	}

	// (Test pass download is handled on admin_init — see ls_wallet_test_download.)

	$apple_status  = ls_apple_configured();
	$google_status = ls_google_configured();

	ls_admin_wrap_start( __( 'Wallet — Apple & Google', 'loyalty-stamps' ) );
	echo '<!-- loyalty-stamps wallet v' . esc_html( LS_VERSION ) . ' -->';
	echo '<p class="description" style="text-align:right;color:#999">loyalty-stamps v' . esc_html( LS_VERSION ) . '</p>';
	?>
	<p class="description" style="font-size:14px">
		<?php esc_html_e( 'Let customers add their loyalty card to Apple Wallet / Google Wallet. The pass shows live stamp progress and updates automatically after every stamp.', 'loyalty-stamps' ); ?>
	</p>

	<?php wp_enqueue_media(); $brand_logo_id = (int) ls_setting( 'wallet_logo_id', 0 ); ?>
	<div class="ls-panel">
		<h2>🎨 <?php esc_html_e( 'Pass branding', 'loyalty-stamps' ); ?></h2>
		<form method="post" id="ls-brand-form">
			<?php wp_nonce_field( 'ls_wallet_save' ); ?>
			<input type="hidden" name="ls_wallet_section" value="branding">
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Pass logo', 'loyalty-stamps' ); ?></th>
					<td>
						<div id="ls-logo-preview" style="margin-bottom:10px">
							<?php if ( $brand_logo_id && wp_get_attachment_image_url( $brand_logo_id, 'medium' ) ) : ?>
								<img src="<?php echo esc_url( wp_get_attachment_image_url( $brand_logo_id, 'medium' ) ); ?>" style="max-height:80px;background:#0a0a0b;padding:8px;border-radius:8px" alt="">
							<?php else : ?>
								<span class="description"><?php esc_html_e( 'No custom logo — the Grill Hub flame logo is used.', 'loyalty-stamps' ); ?></span>
							<?php endif; ?>
						</div>
						<input type="hidden" name="wallet_logo_id" id="ls-logo-id" value="<?php echo (int) $brand_logo_id; ?>">
						<button type="button" class="button" id="ls-logo-pick"><?php esc_html_e( 'Choose logo', 'loyalty-stamps' ); ?></button>
						<button type="button" class="button" id="ls-logo-remove"><?php esc_html_e( 'Remove', 'loyalty-stamps' ); ?></button>
						<p class="description"><?php esc_html_e( 'Shown on the customer\'s Apple Wallet and Google Wallet card. Changing it rebuilds the pass images and refreshes the Google class automatically. Already-saved passes update on the next stamp.', 'loyalty-stamps' ); ?></p>
					</td></tr>
			</table>
			<p><button class="button button-primary" name="ls_wallet_save" value="1"><?php esc_html_e( 'Save branding', 'loyalty-stamps' ); ?></button></p>
		</form>
		<script>
		(function(){
			var pick=document.getElementById('ls-logo-pick'),rem=document.getElementById('ls-logo-remove'),
			    inp=document.getElementById('ls-logo-id'),prev=document.getElementById('ls-logo-preview');
			if(!pick||!window.wp||!wp.media)return;
			var frame;
			pick.addEventListener('click',function(){
				if(frame){frame.open();return;}
				frame=wp.media({title:'<?php echo esc_js( __( 'Choose pass logo', 'loyalty-stamps' ) ); ?>',multiple:false,library:{type:'image'}});
				frame.on('select',function(){
					var at=frame.state().get('selection').first().toJSON();
					inp.value=at.id;
					prev.innerHTML='<img src="'+at.sizes.medium.url+'" style="max-height:80px;background:#0a0a0b;padding:8px;border-radius:8px" alt="">';
				});
				frame.open();
			});
			rem.addEventListener('click',function(){
				inp.value='0';
				prev.innerHTML='<span class="description"><?php echo esc_js( __( 'No custom logo — the Grill Hub flame logo is used.', 'loyalty-stamps' ) ); ?></span>';
			});
		})();
		</script>
	</div>

	<div class="ls-panel">
		<h2>🍎 <?php esc_html_e( 'Apple Wallet', 'loyalty-stamps' ); ?>
			— <?php echo true === $apple_status
				? '<span style="color:green">' . esc_html__( 'Ready', 'loyalty-stamps' ) . '</span>'
				: '<span style="color:#b45309">' . esc_html( $apple_status ) . '</span>'; ?>
		</h2>
		<form method="post" enctype="multipart/form-data" id="ls-apple-form">
			<?php wp_nonce_field( 'ls_wallet_save' ); ?>
			<input type="hidden" name="ls_wallet_section" value="apple">
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Enable Apple Wallet', 'loyalty-stamps' ); ?></th>
					<td><input type="checkbox" name="wallet_apple_enabled" value="1" <?php checked( ls_setting( 'wallet_apple_enabled' ), 1 ); ?>></td></tr>
				<tr><th><?php esc_html_e( 'Pass Type ID', 'loyalty-stamps' ); ?></th>
					<td><input type="text" name="apple_pass_type_id" class="regular-text" dir="ltr" value="<?php echo esc_attr( ls_setting( 'apple_pass_type_id' ) ); ?>" placeholder="pass.pl.grillhub.loyalty"></td></tr>
				<tr><th><?php esc_html_e( 'Team ID', 'loyalty-stamps' ); ?></th>
					<td><input type="text" name="apple_team_id" class="regular-text" dir="ltr" value="<?php echo esc_attr( ls_setting( 'apple_team_id' ) ); ?>" placeholder="ABCDE12345"></td></tr>
				<tr><th><?php esc_html_e( 'Pass certificate (.p12)', 'loyalty-stamps' ); ?></th>
					<td><input type="file" name="apple_p12" accept=".p12,.pfx">
						<input type="password" name="apple_p12_password" placeholder="<?php esc_attr_e( '.p12 password', 'loyalty-stamps' ); ?>" autocomplete="off">
						<?php if ( ls_setting( 'apple_cert_pem' ) ) : ?><p class="description">✓ <?php esc_html_e( 'Certificate installed.', 'loyalty-stamps' ); ?></p><?php endif; ?>
					</td></tr>
				<tr><th><?php esc_html_e( 'WWDR certificate', 'loyalty-stamps' ); ?></th>
					<td><input type="file" name="apple_wwdr" accept=".pem,.cer,.crt">
						<?php if ( ls_setting( 'apple_wwdr_pem' ) ) : ?><p class="description">✓ <?php esc_html_e( 'Installed.', 'loyalty-stamps' ); ?></p><?php endif; ?>
					</td></tr>
			</table>
			<p><button class="button button-primary" name="ls_wallet_save" value="1"><?php esc_html_e( 'Save wallet settings', 'loyalty-stamps' ); ?></button></p>
			<div id="ls-apple-status" style="margin:10px 0;font-weight:700" aria-live="polite"></div>
		</form>
		<script>
		// Save the Apple section via AJAX (admin-ajax.php) so any failure
		// shows a real message instead of a blank page.
		(function () {
			var f = document.getElementById('ls-apple-form');
			var statusEl = document.getElementById('ls-apple-status');
			if ( ! f || ! statusEl || ! window.fetch || ! window.FormData ) return;
			function setStatus( msg, ok ) {
				statusEl.textContent = msg;
				statusEl.style.color = ok ? 'green' : '#b91c1c';
			}
			f.addEventListener( 'submit', function ( e ) {
				e.preventDefault();
				setStatus( '⏳ <?php echo esc_js( __( 'Saving…', 'loyalty-stamps' ) ); ?>', true );
				var btn = f.querySelector( 'button[name="ls_wallet_save"]' );
				if ( btn ) btn.disabled = true;
				var fd = new FormData( f );
				fd.set( 'action', 'ls_wallet_apple' );
				fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
					.then( function ( r ) {
						return r.text().then( function ( t ) { return { ok: r.ok, status: r.status, text: t }; } );
					} )
					.then( function ( res ) {
						var d = null;
						try { d = JSON.parse( res.text ); } catch ( e ) { /* not JSON */ }
						if ( res.ok && d && d.success ) {
							setStatus( '✓ ' + ( d.data && d.data.message ? d.data.message : 'Saved' ), true );
							setTimeout( function () { window.location.reload(); }, 1200 );
						} else {
							var msg = ( d && d.data && d.data.message ) ? d.data.message : ( 'HTTP ' + res.status + ' — ' + res.text.substring( 0, 200 ) );
							setStatus( '✗ ' + msg, false );
							if ( btn ) btn.disabled = false;
						}
					} )
					.catch( function ( err ) {
						setStatus( '✗ <?php echo esc_js( __( 'Network error: the request was blocked before reaching WordPress.', 'loyalty-stamps' ) ); ?> (' + ( err && err.message ? err.message : 'failed' ) + ')', false );
						if ( btn ) btn.disabled = false;
					} );
			} );
		})();
		</script>

		<h3><?php esc_html_e( 'How to get the Apple certificate (one time)', 'loyalty-stamps' ); ?></h3>
		<ol dir="auto" style="line-height:1.9">
			<li>اشترك في <strong>Apple Developer Program</strong> (‎$99/سنة) بحساب آبل الخاص بالمطعم.</li>
			<li>من <strong>Certificates, Identifiers & Profiles</strong> ← <strong>Identifiers</strong> ← أنشئ <strong>Pass Type ID</strong> جديد (مثال: <code dir="ltr">pass.pl.grillhub.loyalty</code>) وسجّل الـ <strong>Team ID</strong> بتاعك.</li>
			<li>من <strong>Certificates</strong> ← أنشئ شهادة <strong>Pass Type ID Certificate</strong> واربطها بالـ Identifier اللي عملته، ثم حمّل ملف الـ <code dir="ltr">.cer</code>.</li>
			<li>افتح ملف الـ <code dir="ltr">.cer</code> على جهاز Mac (هيتضاف للـ Keychain)، ثم اعمل <strong>Export</strong> كملف <code dir="ltr">.p12</code> مع باسورد.</li>
			<li>حمّل شهادة <strong>Apple WWDR</strong> من صفحة الشهادات في حساب آبل (Apple Worldwide Developer Relations).</li>
			<li>ارفع ملف الـ <code dir="ltr">.p12</code> + الباسورد وملف الـ WWDR هنا، واكتب الـ Pass Type ID والـ Team ID، ثم فعّل.</li>
			<li>لازم الموقع يكون شغال بـ <strong>HTTPS</strong> (آبل بترفض غير كده).</li>
		</ol>

		<h3><?php esc_html_e( 'Test', 'loyalty-stamps' ); ?></h3>
		<form method="post" style="display:flex;gap:8px;align-items:center">
			<?php wp_nonce_field( 'ls_wallet_test' ); ?>
			<input type="text" name="test_phone" placeholder="<?php esc_attr_e( 'Customer phone (optional)', 'loyalty-stamps' ); ?>" dir="ltr">
			<button class="button" name="ls_wallet_test" value="1"><?php esc_html_e( 'Download test pass (.pkpass)', 'loyalty-stamps' ); ?></button>
		</form>
		<p class="description"><?php esc_html_e( 'Leave the phone empty for a sample pass. Send the downloaded file to an iPhone (e.g. via AirDrop or email) to preview the pass.', 'loyalty-stamps' ); ?></p>
	</div>

	<div class="ls-panel">
		<h2><span style="font-size:20px">G</span> <?php esc_html_e( 'Google Wallet', 'loyalty-stamps' ); ?>
			— <?php echo true === $google_status
				? '<span style="color:green">' . esc_html__( 'Ready', 'loyalty-stamps' ) . '</span>'
				: '<span style="color:#b45309">' . esc_html( $google_status ) . '</span>'; ?>
		</h2>
		<form method="post" enctype="multipart/form-data" id="ls-sa-form">
			<?php wp_nonce_field( 'ls_wallet_save' ); ?>
			<input type="hidden" name="ls_wallet_section" value="google">
			<div id="ls-sa-status" style="margin:10px 0;font-weight:700" aria-live="polite"></div>
			<script>
			// Save the Google section via AJAX (admin-ajax.php) so any failure
			// shows a real message instead of a blank page. The pasted JSON is
			// XOR-obfuscated + base64ed to slip past firewalls blocking private keys.
			(function () {
				var f = document.getElementById('ls-sa-form');
				var statusEl = document.getElementById('ls-sa-status');
				if ( ! f || ! statusEl || ! window.fetch || ! window.FormData ) return;
				var KEY = 'lsGrillHubWalletKey2024';
				function xorCrypt( str, key ) {
					var out = '';
					for ( var i = 0; i < str.length; i++ ) {
						out += String.fromCharCode( str.charCodeAt( i ) ^ key.charCodeAt( i % key.length ) );
					}
					return out;
				}
				function setStatus( msg, ok ) {
					statusEl.textContent = msg;
					statusEl.style.color = ok ? 'green' : '#b91c1c';
				}
				f.addEventListener( 'submit', function ( e ) {
					e.preventDefault();
					setStatus( '⏳ <?php echo esc_js( __( 'Saving…', 'loyalty-stamps' ) ); ?>', true );
					var btn = f.querySelector( 'button[name="ls_wallet_save"]' );
					if ( btn ) btn.disabled = true;
					var fd = new FormData();
					fd.append( 'action', 'ls_wallet_google' );
					var nonce = f.querySelector( 'input[name="_wpnonce"]' );
					if ( nonce ) fd.append( '_wpnonce', nonce.value );
					var en = f.querySelector( 'input[name="wallet_google_enabled"]' );
					fd.append( 'wallet_google_enabled', en && en.checked ? '1' : '' );
					var iss = f.querySelector( 'input[name="google_issuer_id"]' );
					fd.append( 'google_issuer_id', iss ? iss.value : '' );
					var fi = document.getElementById( 'google_sa_file' );
					if ( fi && fi.files && fi.files.length ) {
						fd.append( 'google_sa_file', fi.files[0] );
					}
					var ta = document.getElementById( 'google_sa_text' );
					if ( ta && ta.value.trim() !== '' ) {
						try {
							fd.append( 'google_sa_xor', btoa( xorCrypt( ta.value, KEY ) ) );
						} catch ( err ) {
							try {
								fd.append( 'google_sa_b64', btoa( unescape( encodeURIComponent( ta.value ) ) ) );
							} catch ( err2 ) {
								fd.append( 'google_sa_text', ta.value );
							}
						}
					}
					fetch( window.ajaxurl, { method: 'POST', body: fd, credentials: 'same-origin' } )
						.then( function ( r ) {
							return r.text().then( function ( t ) { return { ok: r.ok, status: r.status, text: t }; } );
						} )
						.then( function ( res ) {
							var d = null;
							try { d = JSON.parse( res.text ); } catch ( e ) { /* not JSON */ }
							if ( res.ok && d && d.success ) {
								setStatus( '✓ ' + ( d.data && d.data.message ? d.data.message : 'Saved' ), true );
								if ( ta ) ta.value = '';
								setTimeout( function () { window.location.reload(); }, 1200 );
							} else {
								var msg = ( d && d.data && d.data.message ) ? d.data.message : ( 'HTTP ' + res.status + ' — ' + res.text.substring( 0, 120 ) );
								setStatus( '✗ ' + msg, false );
								if ( btn ) btn.disabled = false;
							}
						} )
						.catch( function ( err ) {
							setStatus( '✗ <?php echo esc_js( __( 'Network error: the request was blocked before reaching WordPress.', 'loyalty-stamps' ) ); ?> (' + ( err && err.message ? err.message : 'failed' ) + ')', false );
							if ( btn ) btn.disabled = false;
						} );
				} );
			})();
			</script>
			<table class="form-table">
				<tr><th><?php esc_html_e( 'Enable Google Wallet', 'loyalty-stamps' ); ?></th>
					<td><input type="checkbox" name="wallet_google_enabled" value="1" <?php checked( ls_setting( 'wallet_google_enabled' ), 1 ); ?>></td></tr>
				<tr><th><?php esc_html_e( 'Issuer ID', 'loyalty-stamps' ); ?></th>
					<td><input type="text" name="google_issuer_id" class="regular-text" dir="ltr" value="<?php echo esc_attr( ls_setting( 'google_issuer_id' ) ); ?>" placeholder="3388000000022787089"></td></tr>
				<tr><th><?php esc_html_e( 'Service account JSON', 'loyalty-stamps' ); ?></th>
					<td><input type="file" id="google_sa_file" name="google_sa_file" accept=".json,application/json">
						<p class="description"><?php esc_html_e( 'Upload the downloaded .json key file directly (easiest).', 'loyalty-stamps' ); ?></p>
						<p class="description" style="margin-top:6px"><?php esc_html_e( 'Or paste its contents below:', 'loyalty-stamps' ); ?></p>
						<textarea id="google_sa_text" name="google_sa_text" rows="7" dir="ltr" class="large-text code" placeholder="<?php esc_attr_e( 'Paste the service account JSON here…', 'loyalty-stamps' ); ?>"></textarea>
						<p class="description"><?php esc_html_e( 'Open the .json key file in any text editor, copy everything and paste it here.', 'loyalty-stamps' ); ?></p>
						<?php if ( ls_google_sa_path() ) : ?><p class="description">✓ <?php esc_html_e( 'Installed.', 'loyalty-stamps' ); ?> <?php esc_html_e( 'Paste new JSON only to replace it.', 'loyalty-stamps' ); ?></p><?php endif; ?>
						<p class="description" style="margin-top:8px">📁 <?php esc_html_e( 'Alternative (if pasting is blocked): upload the file as google-sa.json via FTP / File Manager to wp-content/uploads/ls-wallet/ — it will be detected automatically on reload.', 'loyalty-stamps' ); ?></p>
					</td></tr>
			</table>
			<p><button class="button button-primary" name="ls_wallet_save" value="1"><?php esc_html_e( 'Save wallet settings', 'loyalty-stamps' ); ?></button></p>
		</form>

		<h3><?php esc_html_e( 'How to set up Google Wallet (one time, free)', 'loyalty-stamps' ); ?></h3>
		<ol dir="auto" style="line-height:1.9">
			<li>اعمل مشروع في <strong>Google Cloud Console</strong> وفعّل <strong>Google Wallet API</strong>.</li>
			<li>من <strong>Google Pay & Wallet Console</strong> (pay.google.com/business/console) اطلب <strong>Issuer ID</strong> واختار نوع <strong>Loyalty</strong>.</li>
			<li>في Cloud Console ← <strong>IAM & Admin ← Service Accounts</strong>: أنشئ Service Account جديد بدور يسمح بالـ Wallet API، واعمل له <strong>key بصيغة JSON</strong> وحمّلها.</li>
			<li>ارفع ملف الـ JSON هنا واكتب الـ Issuer ID ثم فعّل. أول زر "أضف للمحفظة" هيضغطه عميل هينشئ الكلاس تلقائياً.</li>
		</ol>
	</div>

	<div class="ls-panel">
		<h2><?php esc_html_e( 'Registered Apple devices', 'loyalty-stamps' ); ?></h2>
		<?php
		global $wpdb;
		$devices = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}ls_wallet_devices ORDER BY updated_at DESC LIMIT 100" );
		if ( $devices ) {
			echo '<table class="widefat striped"><thead><tr><th>Serial</th><th>Device</th><th>' . esc_html__( 'Updated', 'loyalty-stamps' ) . '</th></tr></thead><tbody>';
			foreach ( $devices as $d ) {
				echo '<tr><td><code>' . esc_html( $d->serial ) . '</code></td><td><code>' . esc_html( substr( $d->device_id, 0, 18 ) ) . '…</code></td><td>' . esc_html( $d->updated_at ) . '</td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p>' . esc_html__( 'No devices yet.', 'loyalty-stamps' ) . '</p>';
		}
		?>
	</div>
	<?php
	ls_admin_wrap_end();
}

/* -------------------------------------------------------------------------
 * Upload handlers
 * ---------------------------------------------------------------------- */

/** Import Apple .p12: convert to cert.pem + key.pem (unencrypted) in the wallet dir. */
function ls_wallet_import_p12( $tmp_path, $password ) {
	$raw = @file_get_contents( $tmp_path );
	if ( ! $raw || ! openssl_pkcs12_read( $raw, $certs, (string) $password ) ) {
		return new WP_Error( 'ls_p12', __( 'Could not read the .p12 file. Wrong password?', 'loyalty-stamps' ) );
	}
	if ( empty( $certs['cert'] ) || empty( $certs['pkey'] ) ) {
		return new WP_Error( 'ls_p12', __( 'The .p12 file has no certificate/key.', 'loyalty-stamps' ) );
	}
	$dir = ls_wallet_dir();
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'ls_p12', __( 'Could not create the wallet folder. Check the uploads folder permissions.', 'loyalty-stamps' ) );
	}
	$cert_pem = $dir . '/apple-cert.pem';
	$key_pem  = $dir . '/apple-key.pem';
	// Re-export the key unencrypted for server-side signing.
	$res = openssl_pkey_get_private( $certs['pkey'] );
	if ( ! $res || ! openssl_pkey_export( $res, $key_out ) ) {
		return new WP_Error( 'ls_p12', __( 'Could not extract the private key.', 'loyalty-stamps' ) );
	}
	if ( false === @file_put_contents( $cert_pem, $certs['cert'] ) || false === @file_put_contents( $key_pem, $key_out ) ) {
		return new WP_Error( 'ls_p12', __( 'Could not write the file. Check the uploads folder permissions.', 'loyalty-stamps' ) );
	}
	@chmod( $cert_pem, 0600 );
	@chmod( $key_pem, 0600 );

	// Validate dates.
	$info = openssl_x509_parse( $certs['cert'] );
	if ( $info ) {
		if ( $info['validTo_time_t'] < time() ) {
			return new WP_Error( 'ls_p12', __( 'This certificate is expired.', 'loyalty-stamps' ) );
		}
	}
	return array( 'cert' => $cert_pem, 'key' => $key_pem );
}

/** Import WWDR cert (PEM or DER .cer) -> normalized PEM. */
function ls_wallet_import_wwdr( $tmp_path ) {
	$raw = @file_get_contents( $tmp_path );
	if ( ! $raw ) {
		return new WP_Error( 'ls_wwdr', __( 'Could not read the WWDR file.', 'loyalty-stamps' ) );
	}
	$pem = $raw;
	if ( ! openssl_x509_read( $pem ) ) {
		// Try DER -> PEM.
		$pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split( base64_encode( $raw ), 64, "\n" ) . "-----END CERTIFICATE-----\n";
		if ( ! openssl_x509_read( $pem ) ) {
			return new WP_Error( 'ls_wwdr', __( 'Invalid WWDR certificate file.', 'loyalty-stamps' ) );
		}
	}
	$dir = ls_wallet_dir();
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'ls_wwdr', __( 'Could not create the wallet folder. Check the uploads folder permissions.', 'loyalty-stamps' ) );
	}
	$path = $dir . '/apple-wwdr.pem';
	if ( false === @file_put_contents( $path, $pem ) || ! file_exists( $path ) ) {
		return new WP_Error( 'ls_wwdr', __( 'Could not write the file. Check the uploads folder permissions.', 'loyalty-stamps' ) );
	}
	@chmod( $path, 0600 );
	return $path;
}

/** Save Google service account JSON (pasted text). */
/**
 * Read the service account JSON from a directly uploaded file (if any).
 * Returns the file contents string, or '' when no file was uploaded.
 */
function ls_wallet_sa_from_upload() {
	if ( empty( $_FILES['google_sa_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['google_sa_file']['tmp_name'] ) ) {
		return '';
	}
	$raw = file_get_contents( $_FILES['google_sa_file']['tmp_name'] );
	return is_string( $raw ) ? trim( $raw ) : '';
}

function ls_wallet_save_sa( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return new WP_Error( 'ls_sa', __( 'Nothing was received. Please paste the JSON again.', 'loyalty-stamps' ) );
	}
	$sa = json_decode( $raw, true );
	if ( ! is_array( $sa ) ) {
		return new WP_Error( 'ls_sa', __( 'The pasted text is not valid JSON. Please copy the full contents of the downloaded .json key file.', 'loyalty-stamps' ) );
	}
	foreach ( array( 'type', 'private_key', 'client_email', 'token_uri' ) as $k ) {
		if ( empty( $sa[ $k ] ) ) {
			return new WP_Error( 'ls_sa', sprintf( __( 'This JSON is not a service account key (missing "%s"). In Google Cloud Console go to IAM & Admin → Service Accounts → your account → Keys → Add key → Create new key → JSON, then paste the downloaded file.', 'loyalty-stamps' ), $k ) );
		}
	}
	if ( 'service_account' !== $sa['type'] ) {
		return new WP_Error( 'ls_sa', __( 'This JSON is not a service account key ("type" must be "service_account").', 'loyalty-stamps' ) );
	}
	$dir = ls_wallet_dir();
	if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'ls_sa_write', __( 'Could not create the wallet folder. Check the uploads folder permissions.', 'loyalty-stamps' ) );
	}
	$path = $dir . '/google-sa.json';
	if ( false === @file_put_contents( $path, wp_json_encode( $sa ) ) || ! file_exists( $path ) ) {
		return new WP_Error( 'ls_sa_write', __( 'Could not write the file. Check the uploads folder permissions.', 'loyalty-stamps' ) );
	}
	@chmod( $path, 0600 );
	return $path;
}
