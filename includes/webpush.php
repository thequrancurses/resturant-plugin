<?php
/**
 * Grill Hub - Web Push (no composer, no external library).
 *
 * Implements RFC 8291 (message encryption, aes128gcm) and RFC 8292 (VAPID) with PHP's openssl extension only.
 * Needs PHP >= 7.3 (openssl_pkey_derive) with the openssl extension.
 *
 * The first block (ghm_b64u ... ghm_wp_vapid_auth) is pure PHP and has no WordPress dependency, so it can be tested on its own.
 */
if (!defined('ABSPATH') && !defined('GHM_WP_TEST')) exit;

/* ------------------------------------------------------------------ helpers */
function ghm_b64u($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function ghm_b64u_d($s) { return base64_decode(strtr((string) $s, '-_', '+/') . str_repeat('=', (4 - strlen((string) $s) % 4) % 4)); }

// raw uncompressed P-256 public point (0x04 || X || Y) of an openssl EC key
function ghm_wp_pub_raw($key) {
    $d = openssl_pkey_get_details($key);
    if (!$d || empty($d['ec']['x']) || empty($d['ec']['y'])) return false;
    return "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
}

// openssl gives ECDSA signatures as DER (SEQUENCE { INTEGER r, INTEGER s }); JWT ES256 wants raw r||s (64 bytes)
function ghm_wp_der_to_raw($der) {
    $o = 2;
    if (ord($der[1]) & 0x80) $o += ord($der[1]) & 0x7F;          // long-form length
    $rl = ord($der[$o + 1]); $r = substr($der, $o + 2, $rl); $o += 2 + $rl;
    $sl = ord($der[$o + 1]); $s = substr($der, $o + 2, $sl);
    $r = ltrim($r, "\0"); $s = ltrim($s, "\0");
    return str_pad($r, 32, "\0", STR_PAD_LEFT) . str_pad($s, 32, "\0", STR_PAD_LEFT);
}

/* ------------------------------------------------------------------ RFC 8291 encryption */
// $p256dh / $auth = the subscription's keys (base64url). Returns the request body (binary string) or false.
function ghm_wp_encrypt($payload, $p256dh, $auth) {
    $ua_pub = ghm_b64u_d($p256dh);
    $auth = ghm_b64u_d($auth);
    if (strlen($ua_pub) !== 65 || $ua_pub[0] !== "\x04" || strlen($auth) < 16) return false;

    $eph = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
    if (!$eph) return false;
    $as_pub = ghm_wp_pub_raw($eph);
    if ($as_pub === false) return false;

    // the browser's public key as a PEM (fixed SubjectPublicKeyInfo header for P-256 + the 65 byte point)
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $ua_pub;
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $peer = openssl_pkey_get_public($pem);
    if (!$peer) return false;
    $secret = openssl_pkey_derive($peer, $eph, 32);
    if ($secret === false) return false;

    $salt = random_bytes(16);
    $ikm = hash_hkdf('sha256', $secret, 32, "WebPush: info\0" . $ua_pub . $as_pub, $auth);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

    $tag = '';
    $ct = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);   // 0x02 = last record
    if ($ct === false) return false;
    return $salt . pack('N', 4096) . chr(65) . $as_pub . $ct . $tag;
}

/* ------------------------------------------------------------------ RFC 8292 VAPID */
// Authorization header value for one push service endpoint
function ghm_wp_vapid_auth($endpoint, $priv_pem, $pub_b64u, $subject) {
    $u = parse_url($endpoint);
    if (empty($u['scheme']) || empty($u['host'])) return false;
    $aud = $u['scheme'] . '://' . $u['host'] . (isset($u['port']) ? ':' . $u['port'] : '');
    $h = ghm_b64u(json_encode(array('typ' => 'JWT', 'alg' => 'ES256')));
    $c = ghm_b64u(json_encode(array('aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $subject)));
    $key = openssl_pkey_get_private($priv_pem);
    if (!$key || !openssl_sign($h . '.' . $c, $der, $key, OPENSSL_ALGO_SHA256)) return false;
    return 'vapid t=' . $h . '.' . $c . '.' . ghm_b64u(ghm_wp_der_to_raw($der)) . ', k=' . $pub_b64u;
}

/* ================================================================== WordPress glue (needs WP functions) */
if (function_exists('get_option')) {

    // our own VAPID key pair, created once and kept in the options table
    function ghm_vapid() {
        $k = get_option('ghm_vapid');
        if (is_array($k) && !empty($k['priv']) && !empty($k['pub'])) return $k;
        if (!function_exists('openssl_pkey_new')) return false;
        $res = openssl_pkey_new(array('curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC));
        if (!$res || !openssl_pkey_export($res, $pem)) return false;
        $raw = ghm_wp_pub_raw($res);
        if ($raw === false) return false;
        $k = array('priv' => $pem, 'pub' => ghm_b64u($raw));
        update_option('ghm_vapid', $k, false);
        return $k;
    }

    // true when this server can do Web Push at all
    function ghm_push_ready() {
        return PHP_VERSION_ID >= 70300 && function_exists('openssl_pkey_derive') && function_exists('hash_hkdf') && ghm_vapid() !== false;
    }

    // only talk to the real browser push services (stops the endpoint field being abused to make the server call arbitrary URLs)
    function ghm_push_host_ok($endpoint) {
        $u = parse_url((string) $endpoint);
        if (empty($u['scheme']) || $u['scheme'] !== 'https' || empty($u['host'])) return false;
        $h = strtolower($u['host']);
        $allowed = apply_filters('ghm_push_hosts', array('fcm.googleapis.com', 'push.services.mozilla.com', 'push.apple.com', 'notify.windows.com', 'push.api.mozilla.com'));
        foreach ($allowed as $a) if ($h === $a || substr($h, -strlen('.' . $a)) === '.' . $a) return true;
        return false;
    }

    // sends one encrypted message. Returns the HTTP status (201 = queued) or 0 on a local error.
    function ghm_webpush_send($sub, $payload_json) {
        if (empty($sub['endpoint']) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth']) || !ghm_push_host_ok($sub['endpoint'])) return 0;
        $k = ghm_vapid();
        if (!$k) return 0;
        $body = ghm_wp_encrypt($payload_json, $sub['keys']['p256dh'], $sub['keys']['auth']);
        $auth = ghm_wp_vapid_auth($sub['endpoint'], $k['priv'], $k['pub'], 'mailto:' . get_option('admin_email'));
        if ($body === false || $auth === false) return 0;
        $r = wp_remote_post($sub['endpoint'], array(
            'timeout' => 8, 'redirection' => 0, 'body' => $body,
            'headers' => array('Content-Type' => 'application/octet-stream', 'Content-Encoding' => 'aes128gcm', 'TTL' => '3600', 'Urgency' => 'high', 'Authorization' => $auth),
        ));
        return is_wp_error($r) ? 0 : (int) wp_remote_retrieve_response_code($r);
    }

    /* ---------------------------------------------------------- order events -> customer notification */
    // $event: 'accepted' (kitchen took the order) or 'ready'
    function ghm_push_text($o, $event) {
        $lang = isset($o['lang']) && in_array($o['lang'], array('pl', 'en', 'ar'), true) ? $o['lang'] : 'pl';
        $pick = isset($o['mode']) && $o['mode'] === 'pick';
        $n = (int) (isset($o['num']) ? $o['num'] : 0);
        $T = array(
            'accepted' => array(
                'pl' => array('Zamówienie przyjęte ✓', 'Zamówienie #%d zostało przyjęte — już się robi.'),
                'en' => array('Order accepted ✓', 'Order #%d was accepted — we are preparing it.'),
                'ar' => array('تم قبول طلبك ✓', 'تم قبول طلبك #%d ونقوم بتحضيره الآن.'),
            ),
            'ready_pick' => array(
                'pl' => array('Twoje zamówienie jest gotowe! 🎉', 'Zamówienie #%d czeka na odbiór. Zapraszamy!'),
                'en' => array('Your order is ready! 🎉', 'Order #%d is ready for pickup. Come and get it!'),
                'ar' => array('طلبك جاهز! 🎉', 'طلبك #%d جاهز للاستلام. تفضّل!'),
            ),
            'ready_dine' => array(
                'pl' => array('Twoje zamówienie jest gotowe! 🎉', 'Zamówienie #%d — prosimy podejść do odbioru zamówienia.'),
                'en' => array('Your order is ready! 🎉', 'Order #%d — please come to collect your order.'),
                'ar' => array('طلبك جاهز! 🎉', 'طلبك #%d — لو سمحت توجه لاستلام طلبك.'),
            ),
        );
        $key = $event === 'accepted' ? 'accepted' : ($pick ? 'ready_pick' : 'ready_dine');
        $t = $T[$key][$lang];
        $body = sprintf($t[1], $n);
        if ($event === 'accepted' && !empty($o['ready_at']) && $o['ready_at'] > time()) {
            $m = (int) ceil(($o['ready_at'] - time()) / 60);
            $body .= ' ' . ($lang === 'pl' ? 'Gotowe za ok. ' . $m . ' min.' : ($lang === 'ar' ? 'جاهز خلال حوالي ' . $m . ' دقيقة.' : 'Ready in about ' . $m . ' min.'));
        }
        return array($t[0], $body);
    }

    // sends the push AFTER the HTTP response was returned to the kitchen tablet (so the tablet never waits for Apple/Google)
    function ghm_push_order($pid, $o, $event) {
        if (!ghm_push_ready()) return;
        $sub = json_decode((string) get_post_meta($pid, '_ghm_push', true), true);
        if (!is_array($sub) || empty($sub['endpoint'])) return;
        if ($event === 'ready' && get_post_meta($pid, '_ghm_push_ready', true)) return;      // never twice
        if ($event === 'ready') update_post_meta($pid, '_ghm_push_ready', 1);
        $txt = ghm_push_text($o, $event);
        $payload = json_encode(array(
            'title' => $txt[0], 'body' => $txt[1], 'event' => $event, 'tag' => 'ghm-order-' . $pid,
            'url' => !empty($sub['url']) ? $sub['url'] : home_url('/'), 'num' => (int) $o['num'], 'icon' => defined('GHM_URL') ? GHM_URL . 'assets/images/icon-192.png' : '',
        ));
        add_action('shutdown', function () use ($pid, $sub, $payload) {
            if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
            $code = ghm_webpush_send($sub, $payload);
            if ($code === 404 || $code === 410) delete_post_meta($pid, '_ghm_push');      // subscription expired / user revoked it
        });
    }
}
