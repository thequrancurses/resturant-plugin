<?php
/**
 * Online payments: Stripe Checkout, PayU and Przelewy24.
 *
 * - Keys live in their own option (ghm_pay) and are never put in the menu data, so they can not leak to the public page.
 * - Mode: off (customers never see online payment) / test (only a logged-in admin sees it, sandbox / test keys) / live.
 * - An online order is stored as "pending". It reaches the kitchen, the statistics and the alerts only once the gateway confirms the payment
 *   (webhook, or the status check that runs while the customer waits on the tracking page).
 */
if (!defined('ABSPATH')) exit;

define('GHM_PAY_OPT', 'ghm_pay');

function ghm_pay_providers() { return array('stripe', 'payu', 'p24'); }

function ghm_pay_blank_keys() {
    return array(
        'stripe' => array('sk' => '', 'whsec' => ''),
        'payu'   => array('pos' => '', 'cid' => '', 'secret' => '', 'key2' => ''),
        'p24'    => array('merchant' => '', 'pos' => '', 'key' => '', 'crc' => ''),
    );
}
// which fields are secret (never sent back to the admin page in full)
function ghm_pay_secret_fields() {
    return array('stripe' => array('sk', 'whsec'), 'payu' => array('secret', 'key2'), 'p24' => array('key', 'crc'));
}
function ghm_pay_get() {
    $c = get_option(GHM_PAY_OPT, array());
    if (!is_array($c)) $c = array();
    $out = array('mode' => 'off', 'on' => array('stripe' => false, 'payu' => false, 'p24' => false), 'test' => ghm_pay_blank_keys(), 'live' => ghm_pay_blank_keys());
    if (isset($c['mode']) && in_array($c['mode'], array('off', 'test', 'live'), true)) $out['mode'] = $c['mode'];
    foreach (ghm_pay_providers() as $p) {
        if (!empty($c['on'][$p])) $out['on'][$p] = true;
        foreach (array('test', 'live') as $e) foreach ($out[$e][$p] as $f => $_) if (isset($c[$e][$p][$f]) && is_string($c[$e][$p][$f])) $out[$e][$p][$f] = trim($c[$e][$p][$f]);
    }
    return $out;
}
function ghm_pay_creds_ok($c, $p, $test) {
    $k = $c[$test ? 'test' : 'live'][$p];
    if ($p === 'stripe') return preg_match($test ? '/^(sk|rk)_test_\w+$/' : '/^(sk|rk)_live_\w+$/', $k['sk']) === 1;
    foreach ($k as $v) if ($v === '') return false;
    return true;
}
function ghm_pay_log($msg) {
    $l = get_option('ghm_pay_log', array()); if (!is_array($l)) $l = array();
    array_unshift($l, array('t' => time(), 'm' => substr((string) $msg, 0, 300)));
    update_option('ghm_pay_log', array_slice($l, 0, 20), false);
}

/* ------------------------------------------------------------ who may pay online */
function ghm_pay_tt($back = 0) { return substr(hash_hmac('sha256', 'ghm-pay-test|' . (floor(time() / 43200) - $back), wp_salt('auth')), 0, 32); }
function ghm_pay_tt_ok($t) { $t = (string) $t; return $t !== '' && (hash_equals(ghm_pay_tt(0), $t) || hash_equals(ghm_pay_tt(1), $t)); }

// what the menu page gets: the enabled methods only, never a key. In test mode only a logged-in admin gets anything.
function ghm_pay_public() {
    $c = ghm_pay_get();
    if ($c['mode'] === 'off') return null;
    $test = $c['mode'] === 'test';
    if ($test && !current_user_can('manage_options')) return null;
    $m = array();
    foreach (ghm_pay_providers() as $p) if (!empty($c['on'][$p]) && ghm_pay_creds_ok($c, $p, $test)) $m[] = $p;
    if (!$m) return null;
    return array('methods' => $m, 'test' => $test, 'tt' => $test ? ghm_pay_tt() : '', 'retry' => rest_url('ghm/v1/pay/retry'));
}
// checks an incoming order: returns array('test' => bool) or an error code string
function ghm_pay_order_check($method, $in, $total) {
    $c = ghm_pay_get();
    if ($c['mode'] === 'off' || !in_array($method, ghm_pay_providers(), true) || empty($c['on'][$method])) return 'pay_off';
    $test = $c['mode'] === 'test';
    if ($test && !ghm_pay_tt_ok(isset($in['tt']) ? $in['tt'] : '')) return 'pay_off';
    if (!ghm_pay_creds_ok($c, $method, $test)) return 'pay_off';
    if ($total < 2) return 'pay_min';
    if ($method === 'p24' && !is_email(isset($in['email']) ? (string) $in['email'] : '')) return 'pay_email';
    return array('test' => $test);
}
function ghm_pay_grosz($v) { return (int) round(((float) $v) * 100); }
function ghm_pay_return_url($pid, $tok, $what = 'back') {
    $u = ghm_track_url();
    return $u . (strpos($u, '?') === false ? '?' : '&') . 'id=' . (int) $pid . '&t=' . rawurlencode($tok) . '&pay=' . $what;
}

/* ------------------------------------------------------------ http */
function ghm_pay_http($method, $url, $args = array()) {
    $args = array_merge(array('method' => $method, 'timeout' => 20, 'redirection' => 0), $args);
    $r = wp_remote_request($url, $args);
    if (is_wp_error($r)) return array(0, null, $r->get_error_message());
    $body = (string) wp_remote_retrieve_body($r);
    return array((int) wp_remote_retrieve_response_code($r), json_decode($body, true), $body);
}
function ghm_pay_json($a) { return json_encode($a, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }

/* ------------------------------------------------------------ Stripe */
function ghm_pay_stripe_call($k, $method, $path, $body = null, $extra = array()) {
    $args = array('headers' => array_merge(array('Authorization' => 'Bearer ' . $k['sk']), $extra));
    if ($body !== null) $args['body'] = http_build_query($body, '', '&');
    return ghm_pay_http($method, 'https://api.stripe.com' . $path, $args);
}
function ghm_pay_stripe_start($pid, &$o, $k) {
    $pay = &$o['pay'];
    $body = array(
        'mode' => 'payment',
        'success_url' => ghm_pay_return_url($pid, $o['tok'], 'back'),
        'cancel_url' => ghm_pay_return_url($pid, $o['tok'], 'cancel'),
        'client_reference_id' => (string) $pid,
        'metadata' => array('order_id' => (string) $pid, 'num' => (string) $o['num']),
        'line_items' => array(array('quantity' => 1, 'price_data' => array('currency' => 'pln', 'unit_amount' => $pay['amt'], 'product_data' => array('name' => ghm_brand() . ' #' . $o['num'])))),
        'expires_at' => time() + 3600,
        'locale' => in_array($o['lang'], array('pl', 'en'), true) ? $o['lang'] : 'auto',
    );
    list($code, $j, $raw) = ghm_pay_stripe_call($k, 'POST', '/v1/checkout/sessions', $body, array('Idempotency-Key' => 'ghm-' . $pid . '-' . $pay['try']));
    if ($code !== 200 || empty($j['url']) || empty($j['id'])) { ghm_pay_log('Stripe: could not start the payment for order #' . $o['num'] . ' (' . $code . ') ' . (isset($j['error']['message']) ? $j['error']['message'] : $raw)); return new WP_Error('pay', 'stripe'); }
    $pay['ref'] = $j['id'];
    return $j['url'];
}
function ghm_pay_stripe_paid($o, $k) {
    list($code, $j) = ghm_pay_stripe_call($k, 'GET', '/v1/checkout/sessions/' . rawurlencode($o['pay']['ref']));
    return $code === 200 && is_array($j) && isset($j['payment_status']) && $j['payment_status'] === 'paid' && (int) $j['amount_total'] === (int) $o['pay']['amt'] && strtolower((string) $j['currency']) === 'pln';
}
function ghm_pay_stripe_verify_sig($raw, $hdr, $secret) {
    $t = 0; $sigs = array();
    foreach (explode(',', (string) $hdr) as $part) {
        $kv = explode('=', trim($part), 2); if (count($kv) < 2) continue;
        if ($kv[0] === 't') $t = (int) $kv[1]; elseif ($kv[0] === 'v1') $sigs[] = $kv[1];
    }
    if (!$t || !$sigs || abs(time() - $t) > 300 || $secret === '') return false;
    $exp = hash_hmac('sha256', $t . '.' . $raw, $secret);
    foreach ($sigs as $s) if (hash_equals($exp, $s)) return true;
    return false;
}
function ghm_pay_rest_stripe($req) {
    $raw = $req->get_body(); $c = ghm_pay_get(); $ok = false;
    foreach (array('test', 'live') as $e) if ($c[$e]['stripe']['whsec'] !== '' && ghm_pay_stripe_verify_sig($raw, $req->get_header('stripe_signature'), $c[$e]['stripe']['whsec'])) $ok = true;
    if (!$ok) return new WP_REST_Response(array('ok' => false), 400);
    $ev = json_decode($raw, true);
    if (!is_array($ev) || empty($ev['type']) || empty($ev['data']['object'])) return new WP_REST_Response(array('received' => true), 200);
    $s = $ev['data']['object'];
    $pid = isset($s['client_reference_id']) ? (int) $s['client_reference_id'] : 0;
    $o = $pid ? ghm_pay_order($pid) : null;
    if ($o && !empty($o['pay']) && $o['pay']['m'] === 'stripe' && !empty($ev['livemode']) !== !empty($o['pay']['test'])) {   // test event <-> test order only
        $t = $ev['type'];
        if (($t === 'checkout.session.completed' || $t === 'checkout.session.async_payment_succeeded') && isset($s['payment_status']) && $s['payment_status'] === 'paid') {
            if ((int) $s['amount_total'] === (int) $o['pay']['amt'] && strtolower((string) $s['currency']) === 'pln') ghm_pay_mark_paid($pid, 'stripe');
            else ghm_pay_log('Stripe: amount mismatch on order #' . $o['num']);
        } elseif (($t === 'checkout.session.expired' || $t === 'checkout.session.async_payment_failed') && isset($s['id']) && $s['id'] === $o['pay']['ref']) {
            ghm_pay_mark_failed($pid);
        }
    }
    return new WP_REST_Response(array('received' => true), 200);
}

/* ------------------------------------------------------------ PayU */
function ghm_pay_payu_base($test) { return $test ? 'https://secure.snd.payu.com' : 'https://secure.payu.com'; }
function ghm_pay_payu_token($test, $k) {
    $ck = 'ghm_payu_tok_' . ($test ? 't' : 'l') . md5($k['cid'] . $k['secret']);
    $t = get_transient($ck); if ($t) return $t;
    list($code, $j) = ghm_pay_http('POST', ghm_pay_payu_base($test) . '/pl/standard/user/oauth/authorize', array('body' => array('grant_type' => 'client_credentials', 'client_id' => $k['cid'], 'client_secret' => $k['secret'])));
    if ($code !== 200 || empty($j['access_token'])) { ghm_pay_log('PayU: login failed (' . $code . ') - check Client ID / Client secret and the test / live mode'); return false; }
    set_transient($ck, $j['access_token'], max(60, (int) (isset($j['expires_in']) ? $j['expires_in'] : 600) - 120));
    return $j['access_token'];
}
function ghm_pay_payu_start($pid, &$o, $k) {
    $pay = &$o['pay']; $test = !empty($pay['test']);
    $tok = ghm_pay_payu_token($test, $k); if (!$tok) return new WP_Error('pay', 'payu');
    $body = array(
        'notifyUrl' => rest_url('ghm/v1/pay/payu'),
        'continueUrl' => ghm_pay_return_url($pid, $o['tok'], 'back'),
        'customerIp' => $pay['ip'] !== '' ? $pay['ip'] : '127.0.0.1',
        'merchantPosId' => $k['pos'],
        'description' => ghm_brand() . ' #' . $o['num'],
        'currencyCode' => 'PLN',
        'totalAmount' => (string) $pay['amt'],
        'extOrderId' => 'ghm-' . $pid . '-' . $pay['try'],
        'buyer' => array('language' => $o['lang'] === 'pl' ? 'pl' : 'en'),
        'products' => array(array('name' => '#' . $o['num'], 'unitPrice' => (string) $pay['amt'], 'quantity' => '1')),
    );
    list($code, $j, $raw) = ghm_pay_http('POST', ghm_pay_payu_base($test) . '/api/v2_1/orders', array('headers' => array('Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $tok), 'body' => ghm_pay_json($body)));
    if (!in_array($code, array(200, 201, 302), true) || empty($j['redirectUri']) || empty($j['orderId'])) { ghm_pay_log('PayU: could not start the payment for order #' . $o['num'] . ' (' . $code . ') ' . substr($raw, 0, 200)); return new WP_Error('pay', 'payu'); }
    $pay['ref'] = (string) $j['orderId'];
    return $j['redirectUri'];
}
function ghm_pay_payu_paid($o, $k) {
    $test = !empty($o['pay']['test']); $tok = ghm_pay_payu_token($test, $k); if (!$tok) return false;
    list($code, $j) = ghm_pay_http('GET', ghm_pay_payu_base($test) . '/api/v2_1/orders/' . rawurlencode($o['pay']['ref']), array('headers' => array('Authorization' => 'Bearer ' . $tok)));
    return $code === 200 && !empty($j['orders'][0]['status']) && $j['orders'][0]['status'] === 'COMPLETED' && (string) $j['orders'][0]['totalAmount'] === (string) $o['pay']['amt'];
}
function ghm_pay_payu_sig_ok($raw, $hdr, $key2) {
    if ($key2 === '' || !preg_match('/signature=([^;]+)/i', (string) $hdr, $s)) return false;
    $algo = preg_match('/algorithm=([^;]+)/i', (string) $hdr, $a) ? strtoupper(trim($a[1])) : 'MD5';
    $map = array('MD5' => 'md5', 'SHA' => 'sha1', 'SHA-1' => 'sha1', 'SHA-256' => 'sha256', 'SHA-384' => 'sha384', 'SHA-512' => 'sha512');
    if (!isset($map[$algo])) return false;
    return hash_equals(hash($map[$algo], $raw . $key2), strtolower(trim($s[1])));
}
function ghm_pay_rest_payu($req) {
    $raw = $req->get_body(); $c = ghm_pay_get(); $ok = false; $hdr = $req->get_header('openpayu_signature');
    foreach (array('test', 'live') as $e) if (ghm_pay_payu_sig_ok($raw, $hdr, $c[$e]['payu']['key2'])) $ok = true;
    if (!$ok) return new WP_REST_Response(array('ok' => false), 400);
    $n = json_decode($raw, true);
    if (is_array($n) && !empty($n['order']['extOrderId']) && preg_match('/^ghm-(\d+)-/', (string) $n['order']['extOrderId'], $m)) {
        $pid = (int) $m[1]; $o = ghm_pay_order($pid); $so = $n['order'];
        if ($o && !empty($o['pay']) && $o['pay']['m'] === 'payu') {
            if ($so['status'] === 'COMPLETED') {
                if ((string) $so['totalAmount'] === (string) $o['pay']['amt'] && (!isset($so['currencyCode']) || $so['currencyCode'] === 'PLN')) ghm_pay_mark_paid($pid, 'payu');
                else ghm_pay_log('PayU: amount mismatch on order #' . $o['num']);
            } elseif ($so['status'] === 'CANCELED' && (string) $so['orderId'] === (string) $o['pay']['ref']) ghm_pay_mark_failed($pid);
        }
    }
    return new WP_REST_Response(array('status' => 'OK'), 200);
}

/* ------------------------------------------------------------ Przelewy24 */
function ghm_pay_p24_base($test) { return $test ? 'https://sandbox.przelewy24.pl' : 'https://secure.przelewy24.pl'; }
function ghm_pay_p24_sign($arr) { return hash('sha384', ghm_pay_json($arr)); }
function ghm_pay_p24_call($test, $k, $method, $path, $body = null) {
    $args = array('headers' => array('Authorization' => 'Basic ' . base64_encode($k['pos'] . ':' . $k['key']), 'Content-Type' => 'application/json'));
    if ($body !== null) $args['body'] = ghm_pay_json($body);
    return ghm_pay_http($method, ghm_pay_p24_base($test) . $path, $args);
}
function ghm_pay_p24_start($pid, &$o, $k) {
    $pay = &$o['pay']; $test = !empty($pay['test']);
    $sid = 'ghm' . $pid . '-' . substr(md5(uniqid('', true)), 0, 10);
    $mid = (int) $k['merchant']; $amt = (int) $pay['amt'];
    $body = array(
        'merchantId' => $mid, 'posId' => (int) $k['pos'], 'sessionId' => $sid, 'amount' => $amt, 'currency' => 'PLN',
        'description' => ghm_brand() . ' #' . $o['num'], 'email' => $pay['email'], 'country' => 'PL', 'language' => $o['lang'] === 'pl' ? 'pl' : 'en',
        'urlReturn' => ghm_pay_return_url($pid, $o['tok'], 'back'), 'urlStatus' => rest_url('ghm/v1/pay/p24'), 'timeLimit' => 30, 'encoding' => 'UTF-8',
        'sign' => ghm_pay_p24_sign(array('sessionId' => $sid, 'merchantId' => $mid, 'amount' => $amt, 'currency' => 'PLN', 'crc' => $k['crc'])),
    );
    list($code, $j, $raw) = ghm_pay_p24_call($test, $k, 'POST', '/api/v1/transaction/register', $body);
    if ($code !== 200 || empty($j['data']['token'])) { ghm_pay_log('Przelewy24: could not start the payment for order #' . $o['num'] . ' (' . $code . ') ' . substr($raw, 0, 200)); return new WP_Error('pay', 'p24'); }
    $pay['ref'] = $sid;
    return ghm_pay_p24_base($test) . '/trnRequest/' . $j['data']['token'];
}
// the transaction must be verified with Przelewy24 after it is paid, otherwise the money goes back to the customer
function ghm_pay_p24_confirm($o, $k, $orderId, $amount, $currency) {
    $test = !empty($o['pay']['test']); $mid = (int) $k['merchant']; $sid = $o['pay']['ref'];
    $body = array('merchantId' => $mid, 'posId' => (int) $k['pos'], 'sessionId' => $sid, 'amount' => (int) $amount, 'currency' => $currency, 'orderId' => (int) $orderId,
        'sign' => ghm_pay_p24_sign(array('sessionId' => $sid, 'orderId' => (int) $orderId, 'amount' => (int) $amount, 'currency' => $currency, 'crc' => $k['crc'])));
    list($code, $j) = ghm_pay_p24_call($test, $k, 'PUT', '/api/v1/transaction/verify', $body);
    return $code === 200 && isset($j['data']['status']) && $j['data']['status'] === 'success';
}
function ghm_pay_p24_paid($o, $k) {
    $test = !empty($o['pay']['test']);
    list($code, $j) = ghm_pay_p24_call($test, $k, 'GET', '/api/v1/transaction/by/sessionId/' . rawurlencode($o['pay']['ref']));
    if ($code !== 200 || empty($j['data']['orderId']) || !isset($j['data']['status'])) return false;
    $d = $j['data']; if ((int) $d['amount'] !== (int) $o['pay']['amt']) return false;
    if ((int) $d['status'] === 2) return true;
    return (int) $d['status'] === 1 && ghm_pay_p24_confirm($o, $k, $d['orderId'], $d['amount'], 'PLN');
}
function ghm_pay_rest_p24($req) {
    $raw = $req->get_body(); $in = json_decode($raw, true); $c = ghm_pay_get();
    if (!is_array($in) || empty($in['sessionId']) || empty($in['sign']) || !preg_match('/^ghm(\d+)-/', (string) $in['sessionId'], $m)) return new WP_REST_Response(array('ok' => false), 400);
    $ok = false; $kk = null;
    foreach (array('test', 'live') as $e) {
        $k = $c[$e]['p24']; if ($k['crc'] === '') continue;
        $arr = array('merchantId' => (int) $in['merchantId'], 'posId' => (int) $in['posId'], 'sessionId' => (string) $in['sessionId'], 'amount' => (int) $in['amount'], 'originAmount' => (int) $in['originAmount'],
            'currency' => (string) $in['currency'], 'orderId' => (int) $in['orderId'], 'methodId' => (int) $in['methodId'], 'statement' => (string) $in['statement'], 'crc' => $k['crc']);
        if (hash_equals(ghm_pay_p24_sign($arr), (string) $in['sign']) && (int) $k['merchant'] === (int) $in['merchantId']) { $ok = true; $kk = $k; break; }
    }
    if (!$ok) return new WP_REST_Response(array('ok' => false), 400);
    $pid = (int) $m[1]; $o = ghm_pay_order($pid);
    if ($o && !empty($o['pay']) && $o['pay']['m'] === 'p24' && $o['pay']['st'] !== 'paid') {
        if ((int) $in['amount'] === (int) $o['pay']['amt'] && $in['currency'] === 'PLN') {
            $o['pay']['ref'] = (string) $in['sessionId'];   // the attempt that was actually paid
            if (ghm_pay_p24_confirm($o, $kk, $in['orderId'], $in['amount'], $in['currency'])) ghm_pay_mark_paid($pid, 'p24');
            else ghm_pay_log('Przelewy24: verification failed for order #' . $o['num']);
        } else ghm_pay_log('Przelewy24: amount mismatch on order #' . $o['num']);
    }
    return new WP_REST_Response(array('status' => 'OK'), 200);
}

/* ------------------------------------------------------------ order state */
function ghm_pay_order($pid) {
    $p = get_post((int) $pid);
    if (!$p || $p->post_type !== 'ghm_order') return null;
    $o = json_decode($p->post_content, true);
    return is_array($o) ? $o : null;
}
function ghm_pay_save($pid, $o) { wp_update_post(array('ID' => (int) $pid, 'post_content' => wp_slash(ghm_json($o)))); }
function ghm_pay_label($m) { $a = array('stripe' => 'Stripe', 'payu' => 'PayU', 'p24' => 'Przelewy24'); return isset($a[$m]) ? $a[$m] : $m; }

// the order becomes a real order: kitchen, statistics, customer list and alerts. Safe to call more than once (webhook + status check).
function ghm_pay_mark_paid($pid, $by = '') {
    $lock = 'ghm_paylock_' . (int) $pid;
    if (!add_option($lock, time(), '', false)) {
        if (time() - (int) get_option($lock, 0) < 60) return false;
        update_option($lock, time(), false);
    }
    $done = false;
    $o = ghm_pay_order($pid);
    if ($o && !empty($o['pay']) && $o['pay']['st'] !== 'paid') {
        $o['pay']['st'] = 'paid'; $o['pay']['paid_at'] = time();
        $prep = !empty($o['prep']) ? (int) $o['prep'] : 0;
        $o['ts'] = time(); $o['time'] = current_time('mysql'); $o['ready_at'] = $prep ? time() + $prep * 60 : 0;
        $o['notes'] = '*** PAID ONLINE (' . ghm_pay_label($o['pay']['m']) . ($o['pay']['test'] ? ' TEST' : '') . ') ***' . ($o['notes'] !== '' ? "\n" . $o['notes'] : '');
        $cnt = isset($o['cnt']) && is_array($o['cnt']) ? $o['cnt'] : array(); $ip = isset($o['pay']['ip']) ? $o['pay']['ip'] : null;
        unset($o['cnt'], $o['pay']['ip'], $o['pay']['email']);
        ghm_pay_save($pid, $o);
        ghm_stats_add($cnt);
        ghm_customer_add($pid, $o, $ip);
        ghm_alert_new_order($pid, $o);
        ghm_pay_log('Order #' . $o['num'] . ' paid via ' . ghm_pay_label($o['pay']['m']) . ($o['pay']['test'] ? ' (test)' : '') . ' - ' . number_format($o['pay']['amt'] / 100, 2) . ' PLN');
        $done = true;
    }
    delete_option($lock);
    return $done;
}
function ghm_pay_mark_failed($pid) {
    $o = ghm_pay_order($pid);
    if ($o && !empty($o['pay']) && $o['pay']['st'] === 'pending') { $o['pay']['st'] = 'failed'; ghm_pay_save($pid, $o); }
}
function ghm_pay_start(&$o, $pid) {
    $c = ghm_pay_get(); $m = $o['pay']['m']; $k = $c[!empty($o['pay']['test']) ? 'test' : 'live'][$m];
    $o['pay']['try'] = (int) $o['pay']['try'] + 1;
    if ($m === 'stripe') return ghm_pay_stripe_start($pid, $o, $k);
    if ($m === 'payu') return ghm_pay_payu_start($pid, $o, $k);
    if ($m === 'p24') return ghm_pay_p24_start($pid, $o, $k);
    return new WP_Error('pay', 'method');
}
// asks the gateway whether a pending order was paid (a safety net next to the webhook; also what makes the test run work without a webhook set up)
function ghm_pay_pull($pid) {
    $o = ghm_pay_order($pid);
    if (!$o || empty($o['pay']) || $o['pay']['st'] === 'paid' || empty($o['pay']['ref'])) return $o;
    $key = 'ghm_paypull_' . (int) $pid;
    if (get_transient($key)) return $o;
    set_transient($key, 1, 4);
    $c = ghm_pay_get(); $m = $o['pay']['m']; $k = $c[!empty($o['pay']['test']) ? 'test' : 'live'][$m]; $paid = false;
    if ($m === 'stripe') $paid = ghm_pay_stripe_paid($o, $k);
    elseif ($m === 'payu') $paid = ghm_pay_payu_paid($o, $k);
    elseif ($m === 'p24') $paid = ghm_pay_p24_paid($o, $k);
    if ($paid) { ghm_pay_mark_paid($pid, $m); return ghm_pay_order($pid); }
    return $o;
}
function ghm_pay_state($o) { return !empty($o['pay']['st']) ? $o['pay']['st'] : ''; }

// customer pressed "pay again" on the tracking page
function ghm_pay_rest_retry($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'; $rk = 'ghm_payr_' . md5($ip); $n = (int) get_transient($rk);
    if ($n >= 10) return new WP_REST_Response(array('ok' => false, 'error' => 'rate'), 429);
    set_transient($rk, $n + 1, 60);
    $in = $req->get_json_params(); $pid = isset($in['id']) ? (int) $in['id'] : 0;
    $o = $pid ? ghm_pay_order($pid) : null;
    if (!$o || empty($o['pay']) || empty($o['tok']) || !hash_equals((string) $o['tok'], (string) (isset($in['t']) ? $in['t'] : ''))) return new WP_REST_Response(array('ok' => false), 404);
    delete_transient('ghm_paypull_' . $pid);
    $o = ghm_pay_pull($pid);
    if ($o['pay']['st'] === 'paid') return new WP_REST_Response(array('ok' => true, 'paid' => true), 200);
    $c = ghm_pay_get();
    if (empty($o['pay']['test']) ? $c['mode'] !== 'live' : $c['mode'] !== 'test') return new WP_REST_Response(array('ok' => false, 'error' => 'pay_off'), 403);
    $o['pay']['ip'] = ghm_client_ip();
    $url = ghm_pay_start($o, $pid);
    if (is_wp_error($url)) return new WP_REST_Response(array('ok' => false, 'error' => 'pay_init'), 502);
    $o['pay']['st'] = 'pending';
    ghm_pay_save($pid, $o);
    return new WP_REST_Response(array('ok' => true, 'pay_url' => $url), 200);
}

add_action('rest_api_init', function () {
    register_rest_route('ghm/v1', '/pay/stripe', array('methods' => 'POST', 'callback' => 'ghm_pay_rest_stripe', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/pay/payu', array('methods' => 'POST', 'callback' => 'ghm_pay_rest_payu', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/pay/p24', array('methods' => 'POST', 'callback' => 'ghm_pay_rest_p24', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/pay/retry', array('methods' => 'POST', 'callback' => 'ghm_pay_rest_retry', 'permission_callback' => '__return_true'));
});

/* ------------------------------------------------------------ admin (own page data, own save: keys never travel with the menu data) */
function ghm_pay_admin_view() {
    $c = ghm_pay_get(); $sec = ghm_pay_secret_fields(); $out = array('mode' => $c['mode'], 'on' => $c['on'], 'test' => array(), 'live' => array(), 'ok' => array('test' => array(), 'live' => array()));
    foreach (array('test', 'live') as $e) foreach (ghm_pay_providers() as $p) {
        $out['ok'][$e][$p] = ghm_pay_creds_ok($c, $p, $e === 'test');
        foreach ($c[$e][$p] as $f => $v) $out[$e][$p][$f] = in_array($f, $sec[$p], true) ? array('set' => $v !== '', 'hint' => $v !== '' ? '••••' . substr($v, -4) : '') : $v;
    }
    $out['urls'] = array('stripe' => rest_url('ghm/v1/pay/stripe'), 'payu' => rest_url('ghm/v1/pay/payu'), 'p24' => rest_url('ghm/v1/pay/p24'));
    $out['log'] = array_map(function ($x) { return array('t' => date_i18n('Y-m-d H:i', $x['t']), 'm' => $x['m']); }, (array) get_option('ghm_pay_log', array()));
    $out['https'] = is_ssl() || strpos(home_url(), 'https://') === 0;
    return $out;
}
function ghm_ajax_pay_get() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    wp_send_json_success(ghm_pay_admin_view());
}
function ghm_ajax_pay_save() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $in = json_decode(isset($_POST['cfg']) ? wp_unslash($_POST['cfg']) : '', true);
    if (!is_array($in)) wp_send_json_error('Bad data', 400);
    $c = ghm_pay_get(); $sec = ghm_pay_secret_fields();
    foreach (ghm_pay_providers() as $p) {
        $c['on'][$p] = !empty($in['on'][$p]);
        foreach (array('test', 'live') as $e) foreach ($c[$e][$p] as $f => $old) {
            if (!isset($in[$e][$p][$f])) continue;
            $v = trim((string) $in[$e][$p][$f]);
            if (in_array($f, $sec[$p], true)) { if ($v === '__clear__') $c[$e][$p][$f] = ''; elseif ($v !== '') $c[$e][$p][$f] = $v; }
            else $c[$e][$p][$f] = $v;
        }
        if ($c['test']['stripe']['sk'] !== '' && $p === 'stripe' && !preg_match('/^(sk|rk)_test_/', $c['test']['stripe']['sk'])) wp_send_json_error('The Stripe TEST key must start with sk_test_ (or rk_test_).');
        if ($c['live']['stripe']['sk'] !== '' && $p === 'stripe' && !preg_match('/^(sk|rk)_live_/', $c['live']['stripe']['sk'])) wp_send_json_error('The Stripe LIVE key must start with sk_live_ (or rk_live_).');
    }
    $mode = isset($in['mode']) ? $in['mode'] : $c['mode'];
    if (!in_array($mode, array('off', 'test', 'live'), true)) $mode = 'off';
    if ($mode !== 'off') {
        $any = false; foreach (ghm_pay_providers() as $p) if ($c['on'][$p] && ghm_pay_creds_ok($c, $p, $mode === 'test')) $any = true;
        if (!$any) wp_send_json_error($mode === 'live' ? 'Cannot go live: turn on at least one payment method and fill in its LIVE keys.' : 'Cannot start test mode: turn on at least one payment method and fill in its TEST keys.');
    }
    if ($mode === 'live' && $c['mode'] !== 'live') ghm_pay_log('Online payment switched to LIVE');
    $c['mode'] = $mode;
    update_option(GHM_PAY_OPT, $c, false);
    wp_send_json_success(ghm_pay_admin_view());
}
// harmless call per gateway: are the saved keys accepted?
function ghm_ajax_pay_check() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $p = isset($_POST['p']) ? sanitize_key(wp_unslash($_POST['p'])) : ''; $e = (isset($_POST['e']) && $_POST['e'] === 'live') ? 'live' : 'test';
    if (!in_array($p, ghm_pay_providers(), true)) wp_send_json_error('Unknown');
    $c = ghm_pay_get(); $k = $c[$e][$p];
    if (!ghm_pay_creds_ok($c, $p, $e === 'test')) wp_send_json_error('Fill in and save the ' . strtoupper($e) . ' keys first.');
    if ($p === 'stripe') { list($code, $j) = ghm_pay_stripe_call($k, 'GET', '/v1/balance'); $ok = $code === 200; $msg = $ok ? '' : (isset($j['error']['message']) ? $j['error']['message'] : 'HTTP ' . $code); }
    elseif ($p === 'payu') { delete_transient('ghm_payu_tok_' . ($e === 'test' ? 't' : 'l') . md5($k['cid'] . $k['secret'])); $ok = (bool) ghm_pay_payu_token($e === 'test', $k); $msg = $ok ? '' : 'PayU refused the Client ID / Client secret (is this the ' . $e . ' POS?)'; }
    else { list($code, $j) = ghm_pay_p24_call($e === 'test', $k, 'GET', '/api/v1/testAccess'); $ok = $code === 200 && !empty($j['data']); $msg = $ok ? '' : 'Przelewy24 refused the POS ID / API key (HTTP ' . $code . ')'; }
    if ($ok) wp_send_json_success('Connected - the keys are accepted.'); else wp_send_json_error($msg);
}
add_action('wp_ajax_ghm_pay_get', 'ghm_ajax_pay_get');
add_action('wp_ajax_ghm_pay_save', 'ghm_ajax_pay_save');
add_action('wp_ajax_ghm_pay_check', 'ghm_ajax_pay_check');
