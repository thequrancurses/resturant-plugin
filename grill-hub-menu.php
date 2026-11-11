<?php
/**
 * Plugin Name: Restaurant Menu & Ordering
 * Description: Interactive QR menu and WhatsApp ordering for restaurants (Polish / English / Arabic) with a "build your own wrap" builder, kitchen orders and loyalty stamps. Edit categories, dishes, prices, photos, texts and settings from the admin. Use the shortcode [grill_hub_menu] on a page, and [grill_hub_home] for the 3-button home page.
 * Version: 1.19.0
 * Author: Your Name
 * License: GPLv2 or later
 * Text Domain: grill-hub-menu
 */
if (!defined('ABSPATH')) exit;

define('GHM_VER', '1.19.0');
define('GHM_DIR', plugin_dir_path(__FILE__));
define('GHM_URL', plugin_dir_url(__FILE__));
define('GHM_OPT', 'ghm_data');
define('GHM_STATS', 'ghm_stats');
define('GHM_TZ', 'Europe/Warsaw');
require_once GHM_DIR . 'includes/payments.php';   // online payments (Stripe, PayU, Przelewy24)
require_once GHM_DIR . 'includes/webpush.php';   // customer push notifications (order accepted / ready)
require_once GHM_DIR . 'includes/loyalty/bootstrap.php';   // Loyalty section (stamp cards)

/* ---------------------------------------------------------------- data */
function ghm_defaults() {
    $f = GHM_DIR . 'default-data.json';
    $d = is_readable($f) ? json_decode(file_get_contents($f), true) : array();
    if (!is_array($d)) $d = array();
    $d['settings'] = array_merge(ghm_biz_defaults(), isset($d['settings']) && is_array($d['settings']) ? $d['settings'] : array());
    // 1.7.0: wrap builder gets a chicken + beef "mix" and paid extras (fries, cheese)
    $d['builder']['base']['mix'] = array('Duży' => 36, 'Mały' => 24);
    $d['builder']['fries'] = 7; $d['builder']['cheese'] = 5;
    return $d;
}
/* ----------------------------------------------- business info, hours, SEO */
function ghm_biz_defaults() {
    $h = array();
    for ($d = 0; $d < 7; $d++) $h[] = array('on' => true, 'open' => '10:00', 'close' => '22:00'); // Mon..Sun (edit in Settings > Opening hours)
    return array(
        'address' => '', 'city' => '', 'postal' => '', 'phone' => '', 'maps_url' => '',
        'hours' => $h, 'closed_mode' => 'block', 'show_status' => true,
        'seo_title' => '', 'seo_desc' => '', 'seo_title_en' => '', 'seo_desc_en' => '', 'og_image' => '', 'lat' => '', 'lng' => '',
        'upsell_on' => true, 'upsell_ids' => array(),
        'prep_on' => true, 'prep_min' => 20, 'home_btn' => true,
        'combo' => array(
            'on' => false, 'main_cats' => array(), 'drink_cats' => array(), 'off' => 3,
            'win' => array('from' => '', 'to' => '', 'days' => array(0, 1, 2, 3, 4, 5, 6)),
            'label' => array('pl' => 'Zestaw: danie + napój', 'en' => 'Combo: main + drink', 'ar' => 'عرض: وجبة + مشروب'),
        ),
        'bundle' => array(
            'on' => false, 'n' => 2, 'price' => 30, 'cats' => array(), 'inc' => array(), 'exc' => array(),
            'win' => array('from' => '', 'to' => '', 'days' => array(0, 1, 2, 3, 4, 5, 6)),
            'label' => array('pl' => 'Oferta: 2 dania za 30 zł', 'en' => 'Deal: 2 dishes for 30 zł', 'ar' => 'عرض: 2 طبق بـ 30 زلوتي'),
        ),
        'fees' => array('dep_on' => false, 'dep' => 0.5, 'dep_cats' => array(), 'pack_on' => false, 'pack' => 2),
        'gift' => array('on' => false, 'popup' => true, 'tiers' => array(array('min' => 50, 'id' => 0, 'vi' => 0)), 'win' => array('from' => '', 'to' => '', 'days' => array(0, 1, 2, 3, 4, 5, 6))),
        'wait' => array('next_on' => false, 'next_code' => '', 'next_text' => array('pl' => '10% zniżki na następne zamówienie', 'en' => '10% off your next order', 'ar' => 'خصم 10% على طلبك القادم'), 'follow_on' => true, 'review_on' => true, 'rewards_on' => true),
        'coupons' => array(),
        'footer_links' => array(
            array( 'id' => 'home', 'on' => true, 'label' => array( 'pl' => 'Strona główna', 'en' => 'Home', 'ar' => 'الرئيسية' ) ),
            array( 'id' => 'menu', 'on' => true, 'label' => array( 'pl' => 'Menu', 'en' => 'Menu', 'ar' => 'المنيو' ) ),
            array( 'id' => 'track', 'on' => true, 'label' => array( 'pl' => 'Śledź zamówienie', 'en' => 'Track order', 'ar' => 'تتبع الطلب' ) ),
            array( 'id' => 'loy', 'on' => true, 'label' => array( 'pl' => 'Program lojalnościowy', 'en' => 'Loyalty program', 'ar' => 'برنامج الولاء' ) ),
            array( 'id' => 'card', 'on' => true, 'label' => array( 'pl' => 'Moja karta', 'en' => 'My card', 'ar' => 'كارتي' ) ),
            array( 'id' => 'staff', 'on' => true, 'label' => array( 'pl' => 'Personel', 'en' => 'Staff', 'ar' => 'الاستاف' ) ),
        ),
        'alert' => array('mode' => 'offline', 'offline_s' => 90, 'wa_phone' => '', 'wa_key' => '', 'ntfy_topic' => '', 'ntfy_server' => 'https://ntfy.sh', 'email' => ''),
        'reviews' => array('show' => false, 'rating' => '', 'count' => '', 'url' => '', 'items' => array(), 'place_id' => '', 'api_key' => ''),
    );
}
function ghm_clean_win($in) {
    $in = is_array($in) ? $in : array();
    $f = function ($v) { $v = trim((string) $v); if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $v, $m)) $v = sprintf('%02d:%s', (int) $m[1], $m[2]); return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : ''; };
    $from = $f(isset($in['from']) ? $in['from'] : ''); $to = $f(isset($in['to']) ? $in['to'] : '');
    if ($from === '' || $to === '') { $from = ''; $to = ''; }
    $days = array();
    foreach ((isset($in['days']) && is_array($in['days']) ? $in['days'] : array()) as $d) { $d = (int) $d; if ($d >= 0 && $d <= 6) $days[$d] = $d; }
    return array('from' => $from, 'to' => $to, 'days' => array_values($days ? $days : array(0, 1, 2, 3, 4, 5, 6)));
}
// is a time window (days + optional HH:MM range, Warsaw time) active now?
function ghm_in_win($w, $ts = null) {
    $dt = new DateTime('@' . ($ts ? (int) $ts : time())); $dt->setTimezone(new DateTimeZone(GHM_TZ));
    $day = (int) $dt->format('N') - 1; $x = (int) $dt->format('G') * 60 + (int) $dt->format('i');
    $w = ghm_clean_win($w);
    if (!in_array($day, $w['days'], true)) return false;
    if ($w['from'] === '') return true;
    $a = ghm_tomin($w['from']); $b = ghm_tomin($w['to']);
    return $b > $a ? ($x >= $a && $x < $b) : ($x >= $a || $x < $b);
}
function ghm_tomin($s) {
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', (string) $s, $m)) return null;
    return ((int) $m[1] % 24) * 60 + (int) $m[2];
}
function ghm_clock($min) { $min = (($min % 1440) + 1440) % 1440; return sprintf('%02d:%02d', intdiv($min, 60), $min % 60); }
function ghm_windows($hours) {
    $w = array();
    foreach ((array) $hours as $d => $h) {
        if (!is_array($h) || empty($h['on'])) continue;
        $o = ghm_tomin(isset($h['open']) ? $h['open'] : ''); $c = ghm_tomin(isset($h['close']) ? $h['close'] : '');
        if ($o === null || $c === null) continue;
        $w[] = array((int) $d * 1440 + $o, (int) $d * 1440 + ($c <= $o ? $c + 1440 : $c));
    }
    return $w;
}
// true when open right now in Warsaw (no hours entered = always open)
function ghm_is_open($hours, $ts = null) {
    $W = ghm_windows($hours);
    if (!$W) return true;
    $dt = new DateTime('@' . ($ts ? (int) $ts : time()));
    $dt->setTimezone(new DateTimeZone(GHM_TZ));
    $x = ((int) $dt->format('N') - 1) * 1440 + (int) $dt->format('G') * 60 + (int) $dt->format('i');
    foreach ($W as $k) foreach (array(0, 10080) as $sh) if ($x + $sh >= $k[0] && $x + $sh < $k[1]) return true;
    return false;
}
function ghm_hours_groups($hours) { // [['days'=>[0,1,2,3,6],'open'=>'10:00','close'=>'00:00'], ...]
    $g = array();
    foreach ((array) $hours as $d => $h) {
        if (!is_array($h) || empty($h['on'])) continue;
        $k = $h['open'] . '-' . $h['close'];
        if (!isset($g[$k])) $g[$k] = array('days' => array(), 'open' => $h['open'], 'close' => $h['close']);
        $g[$k]['days'][] = (int) $d;
    }
    return array_values($g);
}
function ghm_hours_summary($hours, $lang = 'pl') { // e.g. "Pn–Cz, Nd 10:00–24:00; Pt, So 10:00–02:00"
    $dn = $lang === 'en' ? array('Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun') : array('Pn', 'Wt', 'Śr', 'Cz', 'Pt', 'So', 'Nd'); $out = array();
    foreach (ghm_hours_groups($hours) as $g) {
        $days = $g['days']; $runs = array(); $a = $days[0]; $b = $a;
        for ($i = 1; $i <= count($days); $i++) {
            if (isset($days[$i]) && $days[$i] === $b + 1) { $b = $days[$i]; continue; }
            $runs[] = $a === $b ? $dn[$a] : ($b - $a === 1 ? $dn[$a] . ', ' . $dn[$b] : $dn[$a] . '–' . $dn[$b]);
            if (isset($days[$i])) { $a = $days[$i]; $b = $a; }
        }
        $cl = $g['close'] === '00:00' ? '24:00' : $g['close'];
        $out[] = (count($days) === 7 ? ($lang === 'en' ? 'Daily' : 'Codziennie') : implode(', ', $runs)) . ' ' . $g['open'] . '–' . $cl;
    }
    return implode('; ', $out);
}
function ghm_phone($s) {
    $p = trim((string) (isset($s['phone']) ? $s['phone'] : ''));
    return $p !== '' ? $p : '+' . preg_replace('/\D/', '', (string) $s['whatsapp']);
}
function ghm_phone_tel($p) { return '+' . preg_replace('/\D/', '', (string) $p); }
function ghm_phone_disp($p) {
    $d = preg_replace('/\D/', '', (string) $p);
    return (strlen($d) === 11 && substr($d, 0, 2) === '48') ? '+48 ' . substr($d, 2, 3) . ' ' . substr($d, 5, 3) . ' ' . substr($d, 8) : (string) $p;
}
function ghm_maps_url($s) {
    if (!empty($s['maps_url'])) return $s['maps_url'];
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($s['brand'] . ', ' . $s['address'] . ', ' . $s['city'] . ', Polska');
}
function ghm_asset_url($v, $def = '') {
    $v = $v !== '' ? $v : $def;
    return preg_match('~^(https?:)?//~i', $v) ? $v : GHM_URL . 'assets/' . ltrim($v, '/');
}
function ghm_cut($s, $n) { return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
function ghm_clean_hours($in) {
    $def = ghm_biz_defaults(); $out = array(); $in = is_array($in) ? $in : array();
    for ($d = 0; $d < 7; $d++) {
        $h = isset($in[$d]) && is_array($in[$d]) ? $in[$d] : array();
        $f = function ($v, $fb) {
            $v = trim((string) $v);
            if (preg_match('/^(\d{1,2})[:.](\d{2})$/', $v, $mm)) $v = sprintf('%02d:%s', (int) $mm[1], $mm[2]); // 2:00 -> 02:00
            if ($v === '24:00') $v = '00:00';
            return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v) ? $v : $fb;
        };
        $out[] = array('on' => !empty($h['on']), 'open' => $f(isset($h['open']) ? $h['open'] : '', $def['hours'][$d]['open']), 'close' => $f(isset($h['close']) ? $h['close'] : '', $def['hours'][$d]['close']));
    }
    return $out;
}
function ghm_home_defaults() {
    $l = function ($pl, $en, $ar) { return array('pl' => $pl, 'en' => $en, 'ar' => $ar); };
    return array(
        'enabled'    => true,
        'standalone' => true,
        'root'       => false,
        'embers'     => true,
        'show_lang'  => true,
        'menu_url'   => '',
        'badge'      => $l('Restauracja', 'Restaurant', 'مطعم'),
        'title'      => $l('Witamy', 'Welcome', 'أهلاً بكم'),
        'tagline'    => $l('Świeże jedzenie, prawdziwy smak. Wybierz, co chcesz zrobić.', 'Fresh food, real flavour. Choose what you want to do.', 'طعام طازج وطعم حقيقي. اختر ما تريد.'),
        'notice_show' => false,
        'notice'     => $l('Dziś zamykamy wcześniej.', 'We close earlier today.', 'نُغلق مبكرًا اليوم.'),
        'footer'     => $l('Moja restauracja', 'My Restaurant', 'مطعمي'),
        'social'     => array(
            array('id' => 'facebook', 'show' => false, 'url' => ''),
            array('id' => 'instagram', 'show' => false, 'url' => ''),
            array('id' => 'tiktok', 'show' => false, 'url' => ''),
        ),
        'delivery'   => array(
            array('id' => 'wolt', 'show' => false, 'url' => ''),
            array('id' => 'ubereats', 'show' => false, 'url' => ''),
            array('id' => 'pyszne', 'show' => false, 'url' => ''),
            array('id' => 'bolt', 'show' => false, 'url' => ''),
            array('id' => 'glovo', 'show' => false, 'url' => ''),
        ),
        'about_title' => $l('O nas', 'About us', 'من نحن'),
        'about'      => $l('Napisz tutaj kilka zdań o swojej restauracji (Ustawienia strony głównej).', 'Write a few sentences about your restaurant here (Home page settings).', 'اكتب هنا بضع جمل عن مطعمك (إعدادات الصفحة الرئيسية).'),
        'buttons'    => array(
            array('id' => 'order', 'show' => true, 'icon' => 'bag', 'style' => 'fire', 'new_tab' => false, 'url' => '{menu}',
                'label' => $l('Zamów teraz', 'Order now', 'اطلب الآن'),
                'sub'   => $l('Otwórz menu i złóż zamówienie', 'Open the menu and place your order', 'افتح المنيو وابدأ طلبك')),
            array('id' => 'rewards', 'show' => false, 'icon' => 'gift', 'style' => 'gold', 'new_tab' => false, 'url' => '',
                'label' => $l('Odbierz nagrody', 'Get Rewards', 'احصل على المكافآت'),
                'sub'   => $l('Zbieraj pieczątki i odbieraj nagrody', 'Collect stamps and earn rewards', 'اجمع الأختام واحصل على مكافآت')),
            array('id' => 'review', 'show' => false, 'icon' => 'star', 'style' => 'glass', 'new_tab' => false, 'url' => '',
                'label' => $l('Oceń nas', 'Rate us', 'قيّمنا'),
                'sub'   => $l('Zostaw opinię w Google', 'Leave a review on Google', 'شاركنا رأيك على جوجل')),
            array('id' => 'call', 'show' => true, 'icon' => 'phone', 'style' => 'gold', 'new_tab' => false, 'url' => '{tel}',
                'label' => $l('Zadzwoń do nas', 'Call us', 'اتصل بنا'),
                'sub'   => $l('Zadzwoń: {phone}', 'Call: {phone}', 'اتصل: {phone}')),
            array('id' => 'map', 'show' => true, 'icon' => 'pin', 'style' => 'glass', 'new_tab' => true, 'url' => '{map}',
                'label' => $l('Znajdź nas na mapie', 'Find us on the map', 'الموقع على الخريطة'),
                'sub'   => $l('{address} · otwórz w Google Maps', '{address} · open in Google Maps', '{address} · افتح في خرائط جوجل')),
        ),
    );
}
function ghm_ar_fix($t) {
    $rules = array('الكباب المقطّع' => 'الشاورما المقطّعة', 'كباب ميكس كبير' => 'شاورما ميكس كبيرة', 'جريل طازج' => 'مشاوي طازجة', 'كباب وشاورما' => 'شاورما', 'كباب وجريل' => 'شاورما ومشاوي', 'جريل هاب' => 'مشاوي هاب', 'غريل هَب' => 'مشاوي هَب', 'غريل هاب' => 'مشاوي هاب');
    $t = strtr($t, $rules);
    return str_replace(array('كباب', 'جريل', 'غريل'), array('شاورما', 'مشاوي', 'مشاوي'), $t);
}
function ghm_ar_walk($o) {
    if (!is_array($o)) return $o;
    foreach ($o as $k => $v) {
        if ($k === 'ar' && is_string($v)) $o[$k] = ghm_ar_fix($v);
        elseif ($k === 'ar' && is_array($v)) { foreach ($v as $kk => $vv) if (is_string($vv)) $v[$kk] = ghm_ar_fix($vv); $o[$k] = $v; } // saved text overrides: strings => ar => key => text
        else $o[$k] = ghm_ar_walk($v);
    }
    return $o;
}
function ghm_get_data() {
    $def = ghm_defaults();
    $def['home'] = ghm_home_defaults();
    $raw = get_option(GHM_OPT, '');
    $cur = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
    if (!is_array($cur)) { ghm_mark_migrations(); return $def; }
    $cur['settings'] = array_merge($def['settings'], isset($cur['settings']) && is_array($cur['settings']) ? $cur['settings'] : array());
    $cur['settings']['receipt'] = array_merge($def['settings']['receipt'], is_array($cur['settings']['receipt']) ? $cur['settings']['receipt'] : array());
    $b = isset($cur['builder']) && is_array($cur['builder']) ? $cur['builder'] : array();
    $cur['builder'] = array_merge($def['builder'], $b);
    $cur['builder']['base'] = array_merge($def['builder']['base'], isset($b['base']) && is_array($b['base']) ? $b['base'] : array());
    if (!isset($cur['cats']) || !is_array($cur['cats'])) $cur['cats'] = $def['cats'];
    if (!isset($cur['items']) || !is_array($cur['items'])) $cur['items'] = $def['items'];
    if (!isset($cur['strings']) || !is_array($cur['strings'])) $cur['strings'] = array();
    // 1.5.4: Polish is the default language (visitors can still switch; their choice is remembered in their browser)
    if (!get_option('ghm_pl_default')) {
        $cur['settings']['default_lang'] = 'pl';
        update_option('ghm_pl_default', 1, false);
        update_option(GHM_OPT, ghm_json($cur), false);
    }
    $dh = ghm_home_defaults();
    $ch = isset($cur['home']) && is_array($cur['home']) ? $cur['home'] : array();
    $cur['home'] = array_merge($dh, $ch);
    if (empty($cur['home']['buttons']) || !is_array($cur['home']['buttons'])) $cur['home']['buttons'] = $dh['buttons'];
    // 1.12.0: Arabic wording - "كباب" -> "شاورما", "جريل" -> "مشاوي" in the saved Arabic texts (once)
    if (!get_option('ghm_arwords')) {
        $cur = ghm_ar_walk($cur);
        update_option('ghm_arwords', 1, false);
        update_option(GHM_OPT, ghm_json($cur), false);
    }
    // 1.13.0: "Kebab Klasyczny" gets chicken / beef / mix x size, "Joker" gets a Mix option (once, only if the dish still has its original options)
    if (!get_option('ghm_mix130')) {
        foreach ($cur['items'] as $k => $it) {
            if (!is_array($it) || !isset($it['id'], $it['v']) || !is_array($it['v'])) continue;
            $lab = array(); foreach ($it['v'] as $v) $lab[] = isset($v['l']['pl']) ? $v['l']['pl'] : '';
            if ((int) $it['id'] === 3 && $lab === array('Duży', 'Mały')) {
                $nv = array();
                foreach (array(array('Kurczak', 'Chicken', 'دجاج'), array('Wołowina', 'Beef', 'لحم بقري'), array('Mix', 'Mix', 'ميكس')) as $m) foreach ($it['v'] as $v) {
                    $big = $v['l']['pl'] === 'Duży';
                    $nv[] = array('l' => array('pl' => $m[0] . ' · ' . $v['l']['pl'], 'en' => $m[1] . ' · ' . ($big ? 'Large' : 'Small'), 'ar' => $m[2] . ' · ' . ($big ? 'كبير' : 'صغير')), 'p' => $v['p'], 'img' => isset($v['img']) ? $v['img'] : '');
                }
                $cur['items'][$k]['v'] = $nv;
            } elseif ((int) $it['id'] === 4 && $lab === array('Kurczak', 'Wołowina')) {
                $cur['items'][$k]['v'][] = array('l' => array('pl' => 'Mix', 'en' => 'Mix', 'ar' => 'ميكس'), 'p' => max((float) $it['v'][0]['p'], (float) $it['v'][1]['p']), 'img' => '');
            }
        }
        update_option('ghm_mix130', 1, false);
        update_option(GHM_OPT, ghm_json($cur), false);
    }
    // 1.13.1: Klasyczny options in the order meat (beef, chicken, mix) x size (small, large); preselect chicken + large (once)
    if (!get_option('ghm_mix131')) {
        $want = array('Wołowina · Mały', 'Wołowina · Duży', 'Kurczak · Mały', 'Kurczak · Duży', 'Mix · Mały', 'Mix · Duży');
        foreach ($cur['items'] as $k => $it) {
            if (!is_array($it) || !isset($it['id']) || (int) $it['id'] !== 3 || empty($it['v'])) continue;
            $by = array(); foreach ($it['v'] as $v) $by[isset($v['l']['pl']) ? $v['l']['pl'] : ''] = $v;
            if (count($by) === 6 && !array_diff($want, array_keys($by))) { $nv = array(); foreach ($want as $w) $nv[] = $by[$w]; $cur['items'][$k]['v'] = $nv; if (!isset($it['dv'])) $cur['items'][$k]['dv'] = 3; }
        }
        update_option('ghm_mix131', 1, false);
        update_option(GHM_OPT, ghm_json($cur), false);
    }
    // 1.8.1: iPhone (Safari and in-app browsers) fails to open some short / redirecting links in a new tab -> open rewards + review in the same tab, once
    if (!get_option('ghm_btn181')) {
        foreach ($cur['home']['buttons'] as $k => $hb) if (is_array($hb) && isset($hb['id']) && in_array($hb['id'], array('rewards', 'review'), true)) $cur['home']['buttons'][$k]['new_tab'] = false;
        update_option('ghm_btn181', 1, false);
        update_option(GHM_OPT, ghm_json($cur), false);
    }
    // 1.6.0: add the "Call us" and "Map" buttons (4th and 5th) once to a home page saved before this version
    if (!get_option('ghm_btn16')) {
        $have = array();
        foreach ($cur['home']['buttons'] as $hb) if (is_array($hb) && isset($hb['id'])) $have[] = $hb['id'];
        foreach ($dh['buttons'] as $hb) if (in_array($hb['id'], array('call', 'map'), true) && !in_array($hb['id'], $have, true)) $cur['home']['buttons'][] = $hb;
        update_option('ghm_btn16', 1, false);
        update_option(GHM_OPT, ghm_json($cur), false);
    }
    // 1.17.28: restaurant position for the order distance (once, only if never set — the admin form can still change it later)
    if (!get_option('ghm_lat1728')) {
        if (empty($cur['settings']['lat']) && empty($cur['settings']['lng'])) {
            $cur['settings']['lat'] = '50.25975'; $cur['settings']['lng'] = '19.01687'; // Grill Hub, 3 Maja 21, Katowice
            update_option(GHM_OPT, ghm_json($cur), false);
        }
        update_option('ghm_lat1728', 1, false);
    }
    // 1.17.33: grilled chicken dish (half / whole, with rice or fries, or plain) in a new "Kurczak z grilla" section before the grill section (once)
    if (!get_option('ghm_grillchicken1733')) {
        $hasCat = false; foreach ($cur['cats'] as $c) if (is_array($c) && isset($c['id']) && $c['id'] === 'chicken') $hasCat = true;
        if (!$hasCat) {
            $pos = count($cur['cats']); foreach ($cur['cats'] as $i => $c) if (is_array($c) && isset($c['id']) && $c['id'] === 'grill') { $pos = $i; break; }
            array_splice($cur['cats'], $pos, 0, array(array('id' => 'chicken', 'name' => array('pl' => 'Kurczak z grilla', 'en' => 'Grilled Chicken', 'ar' => 'فراخ مشوية'))));
        }
        $hasDish = false; foreach ($cur['items'] as $it) if (is_array($it) && isset($it['id']) && (int) $it['id'] === 52) $hasDish = true;
        if (!$hasDish) {
            $cur['items'][] = array(
                'id' => 52, 'cat' => 'chicken', 'hidden' => false, 'soon' => true,
                'n' => array('pl' => 'Kurczak z grilla', 'en' => 'Grilled Chicken', 'ar' => 'فرخة مشوية'),
                'd' => array(
                    'pl' => 'Soczysty kurczak z grilla. Podawany z: ryżem lub frytkami, surówką, tahini, kiszonymi warzywami i sosem czosnkowym (toum). Opcja bez dodatków to sam kurczak.',
                    'en' => 'Juicy grilled chicken. Served with: rice or fries, salad, tahini, pickles and garlic sauce (toum). The plain option is just the chicken.',
                    'ar' => 'فرخة مشوية طرية. تُقدَّم مع: أرز أو بطاطس، سلطة، طحينة، مخلل وثومية. أوبشن بدون إضافات يعني الفرخة لوحدها.',
                ),
                'img' => 'images/grill-chicken.jpg',
                'v' => array(
                    array('l' => array('pl' => '½ · z ryżem', 'en' => '½ · with rice', 'ar' => 'نص · مع رز'), 'p' => 40, 'img' => ''),
                    array('l' => array('pl' => '½ · z frytkami', 'en' => '½ · with fries', 'ar' => 'نص · مع بطاطس'), 'p' => 40, 'img' => ''),
                    array('l' => array('pl' => '½ · bez dodatków', 'en' => '½ · plain', 'ar' => 'نص · بدون إضافات'), 'p' => 30, 'img' => ''),
                    array('l' => array('pl' => 'Cały · z ryżem', 'en' => 'Whole · with rice', 'ar' => 'كاملة · مع رز'), 'p' => 70, 'img' => ''),
                    array('l' => array('pl' => 'Cały · z frytkami', 'en' => 'Whole · with fries', 'ar' => 'كاملة · مع بطاطس'), 'p' => 70, 'img' => ''),
                    array('l' => array('pl' => 'Cały · bez dodatków', 'en' => 'Whole · plain', 'ar' => 'كاملة · بدون إضافات'), 'p' => 50, 'img' => ''),
                ),
            );
            if (!isset($cur['next_id']) || (int) $cur['next_id'] <= 52) $cur['next_id'] = 53;
        }
        update_option(GHM_OPT, ghm_json($cur), false);
        update_option('ghm_grillchicken1733', 1, false);
    }
    // 1.17.34: photo for the grilled chicken dish, for menus that got the dish from 1.17.33 without one (once; only fills an empty photo, never overwrites one)
    if (!get_option('ghm_grillchicken1734')) {
        foreach ($cur['items'] as $k => $it) {
            if (is_array($it) && isset($it['id']) && (int) $it['id'] === 52 && empty($it['img'])) $cur['items'][$k]['img'] = 'images/grill-chicken.jpg';
        }
        update_option(GHM_OPT, ghm_json($cur), false);
        update_option('ghm_grillchicken1734', 1, false);
    }
    // 1.17.39: rotisserie chicken goes live as TWO dishes in the "Kurczak z rożna" section — plain (half/whole) and with sides (half/whole, rice or fries) — and "coming soon" is gone (once)
    if (!get_option('ghm_rozna1739')) {
        foreach ($cur['cats'] as $k => $c) {
            if (is_array($c) && isset($c['id']) && $c['id'] === 'chicken' && isset($c['name']) && is_array($c['name'])) {
                $cur['cats'][$k]['name'] = array('pl' => 'Kurczak z rożna', 'en' => 'Rotisserie Chicken', 'ar' => 'فراخ مشوية');
            }
        }
        $has52 = false; $has53 = false; $pos52 = -1;
        foreach ($cur['items'] as $k => $it) {
            if (!is_array($it) || !isset($it['id'])) continue;
            if ((int) $it['id'] === 52) { $has52 = true; $pos52 = $k; }
            if ((int) $it['id'] === 53) $has53 = true;
        }
        if (!$has53) {
            $plainV = array(
                array('l' => array('pl' => '½', 'en' => '½', 'ar' => 'نص'), 'p' => 30, 'img' => ''),
                array('l' => array('pl' => 'Cały', 'en' => 'Whole', 'ar' => 'كاملة'), 'p' => 50, 'img' => ''),
            );
            $plainDish = array(
                'id' => 52, 'cat' => 'chicken', 'hidden' => false, 'soon' => false,
                'n' => array('pl' => 'Kurczak z rożna bez dodatków', 'en' => 'Rotisserie Chicken, plain', 'ar' => 'فرخة مشوية بدون إضافات'),
                'd' => array(
                    'pl' => 'Sam kurczak z rożna, bez dodatków.',
                    'en' => 'Rotisserie chicken on its own, no sides.',
                    'ar' => 'فرخة مشوية على السيخ لوحدها، بدون إضافات.',
                ),
                'img' => 'images/grill-chicken.jpg',
                'v' => $plainV,
            );
            $sidesDish = array(
                'id' => 53, 'cat' => 'chicken', 'hidden' => false, 'soon' => false,
                'n' => array('pl' => 'Kurczak z rożna z dodatkami', 'en' => 'Rotisserie Chicken with Sides', 'ar' => 'فرخة مشوية بالإضافات'),
                'd' => array(
                    'pl' => 'Soczysty kurczak z rożna, podawany z ryżem lub frytkami, surówką, tahini, kiszonymi warzywami i sosem czosnkowym (toum).',
                    'en' => 'Juicy rotisserie chicken, served with rice or fries, salad, tahini, pickles and garlic sauce (toum).',
                    'ar' => 'فرخة مشوية طرية على السيخ، تُقدَّم مع أرز أو بطاطس، سلطة، طحينة، مخلل وثومية.',
                ),
                'img' => 'images/grill-chicken.jpg',
                'v' => array(
                    array('l' => array('pl' => '½ · z ryżem', 'en' => '½ · with rice', 'ar' => 'نص · مع رز'), 'p' => 40, 'img' => ''),
                    array('l' => array('pl' => '½ · z frytkami', 'en' => '½ · with fries', 'ar' => 'نص · مع بطاطس'), 'p' => 40, 'img' => ''),
                    array('l' => array('pl' => 'Cały · z ryżem', 'en' => 'Whole · with rice', 'ar' => 'كاملة · مع رز'), 'p' => 70, 'img' => ''),
                    array('l' => array('pl' => 'Cały · z frytkami', 'en' => 'Whole · with fries', 'ar' => 'كاملة · مع بطاطس'), 'p' => 70, 'img' => ''),
                ),
            );
            if ($has52) {
                // restructure the old single dish (id 52) into the plain one, keeping its position
                $cur['items'][$pos52]['n'] = $plainDish['n'];
                $cur['items'][$pos52]['d'] = $plainDish['d'];
                $cur['items'][$pos52]['soon'] = false;
                $cur['items'][$pos52]['v'] = $plainV;
                if (empty($cur['items'][$pos52]['img'])) $cur['items'][$pos52]['img'] = 'images/grill-chicken.jpg';
                array_splice($cur['items'], $pos52 + 1, 0, array($sidesDish));
            } else {
                // menu never got the 1.17.33 dish: add both fresh
                $cur['items'][] = $plainDish;
                $cur['items'][] = $sidesDish;
            }
            if (!isset($cur['next_id']) || (int) $cur['next_id'] <= 53) $cur['next_id'] = 54;
        }
        update_option(GHM_OPT, ghm_json($cur), false);
        update_option('ghm_rozna1739', 1, false);
    }
    // 1.4.1 migration: "Kebab Kurczak" plate (id 8) now offers Rice / Fries like the other plates
    foreach ($cur['items'] as $k => $it) {
        if ((int) $it['id'] !== 8 || $it['cat'] !== 'shaw' || !isset($it['v']) || count($it['v']) !== 1) continue;
        if (trim((string) $it['v'][0]['l']['pl']) !== '') continue;
        foreach ($def['items'] as $di) {
            if ((int) $di['id'] !== 8) continue;
            $p = $it['v'][0]['p'];
            $nv = $di['v'];
            foreach ($nv as $i => $v) $nv[$i]['p'] = $p;
            $cur['items'][$k]['v'] = $nv;
            if (isset($it['d']['pl']) && strpos($it['d']['pl'], 'frytki, krem czosnkowy, mix') !== false && strpos($it['d']['pl'], 'ryż') === false) $cur['items'][$k]['d'] = $di['d'];
        }
    }
    return $cur;
}
// The one-time migrations below were written for the original Grill Hub menu (they add / reshape specific dishes and set its map position).
// A menu that starts from the neutral defaults (fresh install, reset, or a loaded preset) must never run them, so they are marked as done.
function ghm_mark_migrations() {
    if (get_option('ghm_gen_init')) return;
    foreach (array('ghm_pl_default', 'ghm_arwords', 'ghm_mix130', 'ghm_mix131', 'ghm_btn181', 'ghm_btn16', 'ghm_lat1728', 'ghm_grillchicken1733', 'ghm_grillchicken1734', 'ghm_rozna1739') as $f) add_option($f, 1, '', false);
    update_option('ghm_gen_init', 1, false);
}
/* ------------------------------------------------------------ sample-data presets */
// Ready-made menus that can be loaded from Restaurant Menu > Backup. Each one is a full snapshot (menu, prices, photos, settings, home page) in presets/<slug>.json.
function ghm_presets() {
    $all = array('grill-hub' => 'Grill Hub (Katowice kebab & grill) - the original menu with all dishes, photos and settings');
    $out = array();
    foreach ($all as $slug => $label) if (is_readable(GHM_DIR . 'presets/' . $slug . '.json')) $out[$slug] = $label;
    return $out;
}
function ghm_ajax_load_preset() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $slug = isset($_POST['preset']) ? sanitize_key(wp_unslash($_POST['preset'])) : '';
    $list = ghm_presets();
    if (!isset($list[$slug])) wp_send_json_error('unknown preset', 400);
    $d = json_decode((string) file_get_contents(GHM_DIR . 'presets/' . $slug . '.json'), true);
    if (!is_array($d) || empty($d['items']) || empty($d['cats'])) wp_send_json_error('bad preset', 500);
    ghm_mark_migrations();
    update_option(GHM_OPT, ghm_json($d), false);
    wp_send_json_success(ghm_get_data());
}
add_action('wp_ajax_ghm_load_preset', 'ghm_ajax_load_preset');
// the business name used when a section has no name of its own (Loyalty cards, notifications, e-mails)
function ghm_brand() {
    $d = ghm_get_data();
    return !empty($d['settings']['brand']) ? $d['settings']['brand'] : 'Restaurant';
}
function ghm_json($v) {
    return wp_json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
}

/* ------------------------------------------------------------ sanitize */
function ghm_l3($a, $area = false) {
    $o = array();
    foreach (array('pl', 'en', 'ar') as $l) {
        $v = (is_array($a) && isset($a[$l])) ? (string) $a[$l] : '';
        $o[$l] = $area ? sanitize_textarea_field($v) : sanitize_text_field($v);
    }
    return $o;
}
function ghm_img($v) {
    $v = trim((string) $v);
    if ($v === '') return '';
    if (preg_match('~^(https?:)?//~i', $v)) return esc_url_raw($v);
    $v = str_replace('..', '', $v);
    return ltrim(preg_replace('~[^A-Za-z0-9_\-/\.]~', '', $v), '/');
}
function ghm_num($v, $def = 0) {
    $v = str_replace(',', '.', (string) $v);
    return is_numeric($v) ? round((float) $v, 2) : $def;
}
function ghm_clean_receipt($r) {
    $r = is_array($r) ? $r : array();
    $m = isset($r['method']) ? $r['method'] : 'rawbt';
    return array(
        'paper'       => (isset($r['paper']) && (string) $r['paper'] === '58') ? '58' : '80',
        'header'      => substr(sanitize_text_field(isset($r['header']) ? $r['header'] : ''), 0, 40),
        'footer'      => substr(sanitize_text_field(isset($r['footer']) ? $r['footer'] : ''), 0, 80),
        'copies'      => max(1, min(5, (int) (isset($r['copies']) ? $r['copies'] : 1))),
        'cut'         => !empty($r['cut']),
        'method'      => in_array($m, array('rawbt', 'browser'), true) ? $m : 'rawbt',
        'auto'        => !empty($r['auto']),
        'show_prices' => !empty($r['show_prices']),
        'show_phone'  => !empty($r['show_phone']),
        'big_number'  => !empty($r['big_number']),
        'sound'       => !empty($r['sound']),
    );
}
function ghm_clean_combo($c) {
    $d = ghm_biz_defaults(); $d = $d['combo']; $c = is_array($c) ? $c : array();
    $ids = function ($a) { $o = array(); foreach ((is_array($a) ? $a : array()) as $x) { $x = sanitize_key($x); if ($x !== '') $o[$x] = $x; } return array_values($o); };
    return array('on' => !empty($c['on']), 'popup' => !empty($c['popup']), 'main_cats' => $ids(isset($c['main_cats']) ? $c['main_cats'] : array()), 'drink_cats' => $ids(isset($c['drink_cats']) ? $c['drink_cats'] : array()),
        'off' => max(0, min(100, ghm_num(isset($c['off']) ? $c['off'] : null, $d['off']))), 'win' => ghm_clean_win(isset($c['win']) ? $c['win'] : array()),
        'label' => ghm_l3(isset($c['label']) ? $c['label'] : array()));
}
function ghm_ids($a) { $o = array(); foreach ((is_array($a) ? $a : array()) as $x) { $x = (int) $x; if ($x > 0) $o[$x] = (string) $x; } return array_values($o); }
function ghm_clean_bundle($c) {
    $d = ghm_biz_defaults(); $d = $d['bundle']; $c = is_array($c) ? $c : array(); $o = array();
    foreach ((isset($c['cats']) && is_array($c['cats']) ? $c['cats'] : array()) as $x) { $x = sanitize_key($x); if ($x !== '') $o[$x] = $x; }
    return array('on' => !empty($c['on']), 'popup' => !empty($c['popup']), 'n' => max(2, min(20, (int) ghm_num(isset($c['n']) ? $c['n'] : null, $d['n']))),
        'price' => max(0, min(1000, ghm_num(isset($c['price']) ? $c['price'] : null, $d['price']))), 'cats' => array_values($o),
        'inc' => ghm_ids(isset($c['inc']) ? $c['inc'] : array()), 'exc' => ghm_ids(isset($c['exc']) ? $c['exc'] : array()),
        'win' => ghm_clean_win(isset($c['win']) ? $c['win'] : array()), 'label' => ghm_l3(isset($c['label']) ? $c['label'] : array()));
}
function ghm_clean_fees($f) {
    $d = ghm_biz_defaults(); $d = $d['fees']; $f = is_array($f) ? $f : array(); $cats = array();
    foreach ((isset($f['dep_cats']) && is_array($f['dep_cats']) ? $f['dep_cats'] : array()) as $x) { $x = sanitize_key($x); if ($x !== '') $cats[$x] = $x; }
    return array('dep_on' => !empty($f['dep_on']), 'dep' => max(0, min(20, ghm_num(isset($f['dep']) ? $f['dep'] : null, $d['dep']))), 'dep_cats' => array_values($cats),
        'pack_on' => !empty($f['pack_on']), 'pack' => max(0, min(50, ghm_num(isset($f['pack']) ? $f['pack'] : null, $d['pack']))));
}
// deposit (kaucja) per drink + packaging (opakowanie) for take-away. Added after discounts. Returns array(deposit, packaging)
function ghm_fees_calc($data, $parts, $mode) {
    $F = $data['settings']['fees']; $dep = 0.0; $pack = 0.0;
    if (!empty($F['dep_on'])) { $n = 0; foreach ($parts as $p) if (in_array($p['cat'], $F['dep_cats'], true)) $n += $p['qty']; $dep = round($n * (float) $F['dep'], 2); }
    if (!empty($F['pack_on']) && $mode === 'pick') $pack = round((float) $F['pack'], 2);
    return array($dep, $pack);
}
function ghm_clean_gift($g) {
    $g = is_array($g) ? $g : array(); $t = array();
    foreach ((isset($g['tiers']) && is_array($g['tiers']) ? $g['tiers'] : array()) as $x) {
        if (!is_array($x)) continue;
        $min = ghm_num(isset($x['min']) ? $x['min'] : null, 0); $id = (int) (isset($x['id']) ? $x['id'] : 0);
        if ($min <= 0 || $id < 1) continue;
        $t[] = array('min' => $min, 'id' => $id, 'vi' => max(0, min(20, (int) (isset($x['vi']) ? $x['vi'] : 0))));
        if (count($t) >= 4) break;
    }
    usort($t, function ($a, $b) { return $a['min'] <=> $b['min']; });
    return array('on' => !empty($g['on']), 'popup' => !array_key_exists('popup', $g) || !empty($g['popup']), 'tiers' => $t, 'win' => ghm_clean_win(isset($g['win']) ? $g['win'] : array()));
}
// "spend X, get a dish free": the highest tier reached (after discounts, before fees). Returns array(name, id, cat) or null
function ghm_gift_pick($data, $base, $ts = null) {
    $G = $data['settings']['gift'];
    if (empty($G['on']) || !ghm_in_win($G['win'], $ts)) return null;
    $map = array(); foreach ($data['items'] as $it) $map[(int) $it['id']] = $it;
    $best = null;
    foreach ($G['tiers'] as $t) {
        if ($base + 0.005 < $t['min'] || !isset($map[$t['id']]) || !empty($map[$t['id']]['hidden']) || !isset($map[$t['id']]['v'][$t['vi']])) continue;
        $it = $map[$t['id']]; $vl = $it['v'][$t['vi']]['l']['pl'];
        $best = array('name' => $it['n']['pl'] . ($vl !== '' ? ' (' . $vl . ')' : ''), 'id' => $t['id'], 'cat' => $it['cat']);
    }
    return $best;
}
function ghm_clean_wait($w) {
    $d = ghm_biz_defaults(); $d = $d['wait']; $w = is_array($w) ? $w : array();
    $has = function ($k) use ($w, $d) { return array_key_exists($k, $w) ? !empty($w[$k]) : $d[$k]; };
    return array('next_on' => !empty($w['next_on']), 'next_code' => substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', isset($w['next_code']) ? (string) $w['next_code'] : '')), 0, 20),
        'next_text' => ghm_l3(isset($w['next_text']) ? $w['next_text'] : $d['next_text']), 'follow_on' => $has('follow_on'), 'review_on' => $has('review_on'), 'rewards_on' => $has('rewards_on'));
}
function ghm_clean_footer_links($in) {
    $out = array(); $seen = array();
    foreach ((is_array($in) ? $in : array()) as $l) {
        if (!is_array($l)) continue;
        $id = sanitize_key(isset($l['id']) ? (string) $l['id'] : '');
        if ($id === '' || isset($seen[$id])) continue;
        $seen[$id] = 1;
        $builtin = in_array($id, ghm_ftr_builtin_ids(), true);
        $out[] = array(
            'id'    => $id,
            'on'    => !empty($l['on']),
            'url'   => $builtin ? '' : esc_url_raw(trim(isset($l['url']) ? (string) $l['url'] : ''), array('http', 'https')),
            'label' => ghm_l3(isset($l['label']) ? $l['label'] : array()),
        );
        if (count($out) >= 20) break;
    }
    return $out;
}
function ghm_clean_coupons($in) {
    $out = array(); $seen = array();
    foreach ((is_array($in) ? $in : array()) as $c) {
        if (!is_array($c)) continue;
        $code = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', isset($c['code']) ? (string) $c['code'] : '')), 0, 20);
        if (strlen($code) < 3 || isset($seen[$code])) continue;
        $seen[$code] = 1;
        $kind = isset($c['kind']) && $c['kind'] === 'amount' ? 'amount' : 'percent';
        $v = max(0, ghm_num(isset($c['value']) ? $c['value'] : null, 0)); if ($kind === 'percent') $v = min(100, $v);
        $out[] = array('code' => $code, 'on' => !empty($c['on']), 'popup' => !empty($c['popup']), 'kind' => $kind, 'value' => $v, 'min' => max(0, ghm_num(isset($c['min']) ? $c['min'] : null, 0)),
            'max_uses' => max(0, (int) (isset($c['max_uses']) ? $c['max_uses'] : 0)), 'win' => ghm_clean_win(isset($c['win']) ? $c['win'] : array()),
            'note' => ghm_cut(sanitize_text_field(isset($c['note']) ? $c['note'] : ''), 80));
        if (count($out) >= 30) break;
    }
    return $out;
}
function ghm_clean_alert($a) {
    $d = ghm_biz_defaults(); $d = $d['alert']; $a = is_array($a) ? $a : array();
    $srv = esc_url_raw(trim(isset($a['ntfy_server']) ? $a['ntfy_server'] : ''), array('https', 'http'));
    return array('mode' => in_array(isset($a['mode']) ? $a['mode'] : '', array('off', 'offline', 'always'), true) ? $a['mode'] : 'offline',
        'offline_s' => max(30, min(900, (int) (isset($a['offline_s']) ? $a['offline_s'] : 90))),
        'wa_phone' => substr(preg_replace('/\D/', '', isset($a['wa_phone']) ? $a['wa_phone'] : ''), 0, 16), 'wa_key' => substr(preg_replace('/[^A-Za-z0-9]/', '', isset($a['wa_key']) ? $a['wa_key'] : ''), 0, 20),
        'ntfy_topic' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', isset($a['ntfy_topic']) ? $a['ntfy_topic'] : ''), 0, 64), 'ntfy_server' => $srv ? rtrim($srv, '/') : $d['ntfy_server'],
        'email' => is_email(isset($a['email']) ? trim($a['email']) : '') ? trim($a['email']) : '');
}
function ghm_clean_reviews($r) {
    $r = is_array($r) ? $r : array(); $items = array();
    foreach ((isset($r['items']) && is_array($r['items']) ? $r['items'] : array()) as $x) {
        if (!is_array($x)) continue;
        $t = ghm_cut(sanitize_textarea_field(isset($x['text']) ? $x['text'] : ''), 400); $n = ghm_cut(sanitize_text_field(isset($x['name']) ? $x['name'] : ''), 40);
        if ($t === '' || $n === '') continue;
        $items[] = array('name' => $n, 'text' => $t, 'stars' => max(1, min(5, (int) (isset($x['stars']) ? $x['stars'] : 5))));
        if (count($items) >= 8) break;
    }
    $rt = isset($r['rating']) ? str_replace(',', '.', (string) $r['rating']) : '';
    return array('show' => !empty($r['show']), 'rating' => is_numeric($rt) && $rt >= 1 && $rt <= 5 ? (string) round((float) $rt, 1) : '',
        'count' => isset($r['count']) && (int) $r['count'] > 0 ? (string) (int) $r['count'] : '', 'url' => esc_url_raw(trim(isset($r['url']) ? $r['url'] : ''), array('http', 'https')),
        'items' => $items, 'place_id' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', isset($r['place_id']) ? $r['place_id'] : ''), 0, 120), 'api_key' => substr(preg_replace('/[^A-Za-z0-9_\-]/', '', isset($r['api_key']) ? $r['api_key'] : ''), 0, 80));
}
function ghm_clean($in) {
    $def = ghm_defaults();
    $out = array();
    $s = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : array();
    $lang = isset($s['default_lang']) ? $s['default_lang'] : 'pl';
    $out['settings'] = array(
        'whatsapp'     => preg_replace('/\D/', '', isset($s['whatsapp']) ? $s['whatsapp'] : '') ?: $def['settings']['whatsapp'],
        'restaurant'   => sanitize_text_field(isset($s['restaurant']) ? $s['restaurant'] : '') ?: $def['settings']['restaurant'],
        'brand'        => sanitize_text_field(isset($s['brand']) ? $s['brand'] : '') ?: $def['settings']['brand'],
        'color'        => sanitize_hex_color(isset($s['color']) ? $s['color'] : '') ?: '#e11e26',
        'logo'         => ghm_img(isset($s['logo']) ? $s['logo'] : ''),
        'default_lang' => in_array($lang, array('pl', 'en', 'ar'), true) ? $lang : 'pl',
        'standalone'   => !empty($s['standalone']),
        'animations'   => !empty($s['animations']),
        'orders'       => !empty($s['orders']),
        'orders_wa'    => !empty($s['orders_wa']),
        'kitchen_pin'  => substr(preg_replace('/[^A-Za-z0-9]/', '', isset($s['kitchen_pin']) ? $s['kitchen_pin'] : ''), 0, 20),
        'receipt'      => ghm_clean_receipt(isset($s['receipt']) ? $s['receipt'] : array()),
        'address'      => ghm_cut(sanitize_text_field(isset($s['address']) ? $s['address'] : ''), 80) ?: $def['settings']['address'],
        'city'         => ghm_cut(sanitize_text_field(isset($s['city']) ? $s['city'] : ''), 60) ?: $def['settings']['city'],
        'postal'       => substr(preg_replace('/[^0-9A-Za-z\- ]/', '', isset($s['postal']) ? $s['postal'] : ''), 0, 10),
        'phone'        => substr(preg_replace('/[^0-9+ ]/', '', isset($s['phone']) ? $s['phone'] : ''), 0, 25),
        'maps_url'     => esc_url_raw(trim(isset($s['maps_url']) ? $s['maps_url'] : ''), array('http', 'https')),
        'hours'        => ghm_clean_hours(isset($s['hours']) ? $s['hours'] : array()),
        'closed_mode'  => in_array(isset($s['closed_mode']) ? $s['closed_mode'] : '', array('block', 'warn', 'off'), true) ? $s['closed_mode'] : 'block',
        'show_status'  => !empty($s['show_status']),
        'seo_title'    => ghm_cut(sanitize_text_field(isset($s['seo_title']) ? $s['seo_title'] : ''), 120),
        'seo_desc'     => ghm_cut(sanitize_textarea_field(isset($s['seo_desc']) ? $s['seo_desc'] : ''), 320),
        'seo_title_en' => ghm_cut(sanitize_text_field(isset($s['seo_title_en']) ? $s['seo_title_en'] : ''), 120),
        'seo_desc_en'  => ghm_cut(sanitize_textarea_field(isset($s['seo_desc_en']) ? $s['seo_desc_en'] : ''), 320),
        'lat'          => isset($s['lat']) && is_numeric(str_replace(',', '.', (string) $s['lat'])) ? (string) round((float) str_replace(',', '.', (string) $s['lat']), 6) : '',
        'lng'          => isset($s['lng']) && is_numeric(str_replace(',', '.', (string) $s['lng'])) ? (string) round((float) str_replace(',', '.', (string) $s['lng']), 6) : '',
        'og_image'     => ghm_img(isset($s['og_image']) ? $s['og_image'] : ''),
        'upsell_on'    => !empty($s['upsell_on']),
        'upsell_ids'   => array_slice(array_values(array_unique(array_filter(array_map('intval', isset($s['upsell_ids']) && is_array($s['upsell_ids']) ? $s['upsell_ids'] : array()), function ($v) { return $v > 0; }))), 0, 4),
        'prep_on'      => !empty($s['prep_on']),
        'home_btn'     => !array_key_exists('home_btn', $s) || !empty($s['home_btn']),
        'prep_min'     => max(5, min(120, (int) (isset($s['prep_min']) ? $s['prep_min'] : 20))),
        'combo'        => ghm_clean_combo(isset($s['combo']) ? $s['combo'] : array()),
        'bundle'       => ghm_clean_bundle(isset($s['bundle']) ? $s['bundle'] : array()),
        'fees'         => ghm_clean_fees(isset($s['fees']) ? $s['fees'] : array()),
        'gift'         => ghm_clean_gift(isset($s['gift']) ? $s['gift'] : array()),
        'wait'         => ghm_clean_wait(isset($s['wait']) ? $s['wait'] : array()),
        'coupons'      => ghm_clean_coupons(isset($s['coupons']) ? $s['coupons'] : array()),
        'alert'        => ghm_clean_alert(isset($s['alert']) ? $s['alert'] : array()),
        'reviews'      => ghm_clean_reviews(isset($s['reviews']) ? $s['reviews'] : array()),
        'footer_links' => ghm_clean_footer_links(isset($s['footer_links']) ? $s['footer_links'] : array()),
    );
    $b = isset($in['builder']) && is_array($in['builder']) ? $in['builder'] : array();
    $db = $def['builder'];
    $base = array();
    foreach (array('kurczak', 'wolowina', 'mix') as $m) {
        foreach (array('Duży', 'Mały') as $z) {
            $base[$m][$z] = ghm_num(isset($b['base'][$m][$z]) ? $b['base'][$m][$z] : null, $db['base'][$m][$z]);
        }
    }
    $out['builder'] = array('enabled' => !empty($b['enabled']), 'base' => $base);
    foreach (array('sauceFree', 'sauce', 'vegFree', 'veg', 'pickleFree', 'pickle', 'fries', 'cheese') as $k) {
        $out['builder'][$k] = max(0, ghm_num(isset($b[$k]) ? $b[$k] : null, $db[$k]));
    }
    $cats = array(); $seen = array();
    foreach ((isset($in['cats']) && is_array($in['cats']) ? $in['cats'] : array()) as $c) {
        $id = sanitize_key(isset($c['id']) ? $c['id'] : '');
        if ($id === '' || $id === 'build' || isset($seen[$id])) continue;
        $seen[$id] = 1;
        $cats[] = array('id' => $id, 'name' => ghm_l3(isset($c['name']) ? $c['name'] : array()));
        if (count($cats) >= 60) break;
    }
    $out['cats'] = $cats;
    $items = array(); $ids = array(); $next = 1;
    foreach ((isset($in['items']) && is_array($in['items']) ? $in['items'] : array()) as $it) {
        $id = isset($it['id']) ? (int) $it['id'] : 0;
        if ($id < 1 || $id >= 1000 || isset($ids[$id])) continue;
        $cat = sanitize_key(isset($it['cat']) ? $it['cat'] : '');
        if (!isset($seen[$cat])) continue;
        $ids[$id] = 1;
        $vs = array();
        foreach ((isset($it['v']) && is_array($it['v']) ? $it['v'] : array()) as $v) {
            $vs[] = array('l' => ghm_l3(isset($v['l']) ? $v['l'] : array()), 'p' => ghm_num(isset($v['p']) ? $v['p'] : 0), 'img' => ghm_img(isset($v['img']) ? $v['img'] : ''));
            if (count($vs) >= 20) break;
        }
        if (!$vs) $vs[] = array('l' => ghm_l3(array()), 'p' => 0, 'img' => '');
        $items[] = array(
            'id' => $id, 'cat' => $cat, 'hidden' => !empty($it['hidden']),
            'n' => ghm_l3(isset($it['n']) ? $it['n'] : array()),
            'd' => ghm_l3(isset($it['d']) ? $it['d'] : array(), true),
            'img' => ghm_img(isset($it['img']) ? $it['img'] : ''),
            'v' => $vs,
            'dv' => max(0, min(count($vs) - 1, (int) (isset($it['dv']) ? $it['dv'] : 0))),
        );
        if (count($items) >= 600) break;
    }
    $out['items'] = $items;
    $allowed = array('b' => array('class' => true), 'em' => array());
    $str = array();
    foreach (array('pl', 'en', 'ar') as $l) {
        if (empty($in['strings'][$l]) || !is_array($in['strings'][$l])) continue;
        foreach ($in['strings'][$l] as $k => $v) {
            $k = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $k));
            $v = trim(wp_kses((string) $v, $allowed));
            if ($k !== '' && $v !== '') $str[$l][$k] = $v;
        }
    }
    $out['strings'] = $str;
    $out['home'] = ghm_clean_home(isset($in['home']) ? $in['home'] : array());
    return $out;
}
function ghm_home_url($u) {
    $u = trim((string) $u);
    if (in_array($u, array('{menu}', '{tel}', '{map}'), true)) return $u;
    if (preg_match('~^(tel:|mailto:)[\w+@.\-]+$~i', $u)) return $u;
    return esc_url_raw($u, array('http', 'https'));
}
// fixed list of ids (facebook, wolt…); only the link and the visible switch are editable
function ghm_clean_links($in, $def) {
    $by = array();
    foreach ((is_array($in) ? $in : array()) as $x) if (is_array($x) && isset($x['id'])) $by[sanitize_key($x['id'])] = $x;
    $out = array();
    foreach ($def as $d) {
        $x = isset($by[$d['id']]) ? $by[$d['id']] : array();
        $out[] = array('id' => $d['id'], 'show' => !empty($x['show']), 'url' => isset($x['url']) ? esc_url_raw(trim($x['url']), array('http', 'https')) : '', 'img' => ghm_img(isset($x['img']) ? $x['img'] : ''));
    }
    return $out;
}
function ghm_clean_home($h) {
    $d = ghm_home_defaults();
    $h = is_array($h) ? $h : array();
    $o = array(
        'enabled' => !empty($h['enabled']), 'standalone' => !empty($h['standalone']), 'root' => !empty($h['root']), 'embers' => !empty($h['embers']),
        'show_lang' => !empty($h['show_lang']), 'menu_url' => ghm_home_url(isset($h['menu_url']) ? $h['menu_url'] : '') ?: $d['menu_url'], 'notice_show' => !empty($h['notice_show']),
        'badge' => ghm_l3(isset($h['badge']) ? $h['badge'] : array()), 'title' => ghm_l3(isset($h['title']) ? $h['title'] : array()),
        'tagline' => ghm_l3(isset($h['tagline']) ? $h['tagline'] : array(), true), 'notice' => ghm_l3(isset($h['notice']) ? $h['notice'] : array(), true),
        'footer' => ghm_l3(isset($h['footer']) ? $h['footer'] : array()),
        'about_title' => ghm_l3(isset($h['about_title']) ? $h['about_title'] : array()), 'about' => ghm_l3(isset($h['about']) ? $h['about'] : array(), true),
        'buttons' => array(),
        'social' => ghm_clean_links(isset($h['social']) ? $h['social'] : array(), $d['social']),
        'delivery' => ghm_clean_links(isset($h['delivery']) ? $h['delivery'] : array(), $d['delivery']),
    );
    $icons = array('bag', 'gift', 'star', 'phone', 'pin', 'whatsapp', 'instagram', 'facebook', 'link');
    $styles = array('fire', 'gold', 'glass');
    foreach ((isset($h['buttons']) && is_array($h['buttons']) ? $h['buttons'] : array()) as $b) {
        if (!is_array($b)) continue;
        $ic = isset($b['icon']) ? $b['icon'] : 'link'; $st = isset($b['style']) ? $b['style'] : 'glass';
        $o['buttons'][] = array(
            'id' => sanitize_key(isset($b['id']) ? $b['id'] : '') ?: 'b' . (count($o['buttons']) + 1),
            'show' => !empty($b['show']), 'new_tab' => !empty($b['new_tab']),
            'icon' => in_array($ic, $icons, true) ? $ic : 'link', 'style' => in_array($st, $styles, true) ? $st : 'glass',
            'url' => ghm_home_url(isset($b['url']) ? $b['url'] : ''),
            'label' => ghm_l3(isset($b['label']) ? $b['label'] : array()), 'sub' => ghm_l3(isset($b['sub']) ? $b['sub'] : array(), true),
        );
        if (count($o['buttons']) >= 8) break;
    }
    return $o;
}

/* ------------------------------------------------------------ frontend */
function ghm_shade($hex, $f) { // f<0 darker, f>0 mixes with white
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    $c = array(hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2)));
    foreach ($c as &$x) { $x = $f < 0 ? round($x * (1 + $f)) : round($x + (255 - $x) * $f); $x = max(0, min(255, $x)); }
    return sprintf('#%02x%02x%02x', $c[0], $c[1], $c[2]);
}
function ghm_vars_css($data) {
    $s = $data['settings'];
    $logo = $s['logo'] ? $s['logo'] : GHM_URL . 'assets/images/logo-flame.png';
    $logo = preg_match('~^(https?:)?//~i', $logo) ? $logo : GHM_URL . 'assets/' . ltrim($logo, '/');
    return '#app{--red:' . $s['color'] . ';--red-dark:' . ghm_shade($s['color'], -0.22) . ';--red-soft:' . ghm_shade($s['color'], 0.9) . ';--flame:url("' . esc_url_raw($logo) . '")}';
}
function ghm_fonts_url() {
    return 'https://fonts.googleapis.com/css2?family=Anton&family=Bricolage+Grotesque:opsz,wght@12..96,500..800&family=DM+Sans:wght@400;500;600;700&family=Fraunces:ital,opsz,wght@0,9..144,500;1,9..144,500&family=Cairo:wght@500;600;700;800&display=swap';
}
function ghm_markup($data) {
    $html = file_get_contents(GHM_DIR . 'templates/app.html');
    $html = str_replace('<main id="menu"></main>', '<main id="menu">' . ghm_menu_seo_list($data) . '</main>', $html);
    return str_replace(array('{{BASE}}', '{{BRAND}}'), array(esc_url(GHM_URL . 'assets/'), esc_html($data['settings']['brand'])), $html);
}
function ghm_public($data) {
    $cb = $data['settings']['combo']; $hasCp = false;
    foreach ($data['settings']['coupons'] as $c) if (!empty($c['on'])) $hasCp = true;
    unset($data['settings']['kitchen_pin'], $data['settings']['receipt'], $data['settings']['alert'], $data['settings']['coupons'], $data['settings']['reviews']);
    $data['settings']['coupons_on'] = $hasCp;
    $data['settings']['home_url'] = ghm_home_target($data);
    $W = $data['settings']['wait']; $H = $data['home'];
    $wait = array('next' => null, 'social' => array(), 'review' => '', 'rewards' => '');
    if (!empty($W['next_on']) && $W['next_code'] !== '') $wait['next'] = array('code' => $W['next_code'], 'text' => $W['next_text']);
    if (!empty($W['follow_on'])) foreach ((array) $H['social'] as $x) if (!empty($x['show']) && !empty($x['url'])) $wait['social'][] = array('id' => $x['id'], 'url' => $x['url']);
    foreach ((array) $H['buttons'] as $b) {
        if (empty($b['show']) || empty($b['url'])) continue;
        if ($b['id'] === 'review' && !empty($W['review_on'])) $wait['review'] = $b['url'];
        if ($b['id'] === 'rewards' && !empty($W['rewards_on'])) $wait['rewards'] = ghm_rewards_url($b['url']);
    }
    if ($wait['review'] === '' && !empty($W['review_on']) && !empty($data['settings']['reviews']['url'])) $wait['review'] = $data['settings']['reviews']['url'];
    $data['settings']['wait'] = $wait;
    $data['settings']['coupon_url'] = rest_url('ghm/v1/coupon');
    $data['settings']['status_url'] = rest_url('ghm/v1/status');
    $data['settings']['order_url'] = rest_url('ghm/v1/order');
    $data['settings']['hit_url'] = rest_url('ghm/v1/hit');
    $vk = function_exists('ghm_push_ready') && ghm_push_ready() ? ghm_vapid() : false;       // push needs PHP >= 7.3 + openssl
    if ($vk) { $data['settings']['push_key'] = $vk['pub']; $data['settings']['push_url'] = rest_url('ghm/v1/push/subscribe'); $data['settings']['sw_url'] = home_url('/?ghm-sw=1'); }
    $data['settings']['track_url'] = ghm_track_url();
    $data['settings']['pay'] = !empty($data['settings']['orders']) ? ghm_pay_public() : null;   // enabled methods only, never a key
    return $data;
}
// where the logo / home button of the menu page leads: the front page when the home page is on, otherwise the site root
function ghm_home_target($data) {
    if (!empty($data['home']['enabled'])) {
        if (!empty($data['home']['root'])) return home_url('/');
        $h = ghm_find_home();
        if ($h) { $u = get_permalink($h); if ($u) return $u; }
    }
    return home_url('/');
}
function ghm_ord_js() {
    return 'window.GHM_ORD=' . ghm_json(array('status' => rest_url('ghm/v1/status'), 'track' => ghm_track_url())) . ';';
}
function ghm_data_script($data) {
    $data = ghm_public($data);
    return '<script>window.GHM_BASE=' . ghm_json(GHM_URL . 'assets/') . ';window.GHM_DATA=' . ghm_json($data) . ';' . ghm_ord_js() . '</script>';
}

function ghm_shortcode() {
    $data = ghm_get_data();
    $pub = ghm_public($data);
    wp_enqueue_style('ghm-fonts', ghm_fonts_url(), array(), null);
    wp_enqueue_style('ghm-menu', GHM_URL . 'assets/menu.css', array(), GHM_VER);
    wp_enqueue_script('ghm-shared', GHM_URL . 'assets/shared.js', array(), GHM_VER, true);
    wp_enqueue_script('ghm-strings', GHM_URL . 'assets/strings.js', array('ghm-shared'), GHM_VER, true);
    wp_enqueue_script('ghm-menu', GHM_URL . 'assets/menu.js', array('ghm-strings'), GHM_VER, true);
    if (!empty($data['settings']['animations'])) {
        wp_enqueue_script('ghm-gsap', GHM_URL . 'assets/vendor/gsap.min.js', array(), '3.15.0', true);
        wp_enqueue_script('ghm-st', GHM_URL . 'assets/vendor/ScrollTrigger.min.js', array('ghm-gsap'), '3.15.0', true);
        wp_enqueue_script('ghm-lenis', GHM_URL . 'assets/vendor/lenis.min.js', array(), GHM_VER, true);
        wp_enqueue_script('ghm-fx', GHM_URL . 'assets/fx.js', array('ghm-menu', 'ghm-st', 'ghm-lenis'), GHM_VER, true);
    }
    wp_add_inline_script('ghm-strings', 'window.GHM_BASE=' . ghm_json(GHM_URL . 'assets/') . ';window.GHM_DATA=' . ghm_json($pub) . ';' . ghm_ord_js(), 'before');
    return '<style>' . ghm_vars_css($data) . '</style>' . ghm_markup($data);
}
add_shortcode('grill_hub_menu', 'ghm_shortcode');
// [track] / [grill_hub_track]: the order tracking page (rendered as a full standalone page, like the menu)
add_shortcode('track', function () { return ''; });
add_shortcode('grill_hub_track', function () { return ''; });

function ghm_home_public($data, $lang = null, $fixed = false) {
    $h = $data['home'];
    $menu = !empty($h['menu_url']) ? $h['menu_url'] : (get_permalink(ghm_find_page()) ?: home_url('/'));
    $s = $data['settings'];
    $phone = ghm_phone($s); $map = ghm_maps_url($s);
    $urls = array('{menu}' => $menu, '{tel}' => 'tel:' . ghm_phone_tel($phone), '{map}' => $map);
    $addr = $s['address'] . ', ' . $s['city'];
    foreach ($h['buttons'] as &$b) {
        if (isset($urls[$b['url']])) $b['url'] = $urls[$b['url']];
        if (isset($b['id']) && $b['id'] === 'rewards') $b['url'] = ghm_rewards_url($b['url']);
        foreach (array('label', 'sub') as $f) foreach ($b[$f] as $lg => $v) $b[$f][$lg] = str_replace(array('{phone}', '{address}'), array("\xE2\x81\xA6" . ghm_phone_disp($phone) . "\xE2\x81\xA9", "\xE2\x81\xA6" . $addr . "\xE2\x81\xA9"), $v); // isolates keep digits and street names in order inside Arabic text
    }
    unset($b);
    $logo = ghm_asset_url($s['logo'], 'images/logo-flame.png');
    $root = !empty($data['home']['root']) && !empty($data['home']['enabled']);
    foreach (array('social', 'delivery') as $g) $h[$g] = array_values(array_filter(isset($h[$g]) ? $h[$g] : array(), function ($x) { return !empty($x['show']) && !empty($x['url']); }));
    foreach (array('social', 'delivery') as $g) foreach ($h[$g] as $i => $x) $h[$g][$i]['img'] = !empty($x['img']) ? ghm_asset_url($x['img']) : '';
    $h['reviews'] = ghm_reviews_public($s);
    $h['gift'] = null;
    if (!empty($s['gift']['on'])) {
        $map = array(); foreach ($data['items'] as $it) $map[(int) $it['id']] = $it; $tiers = array();
        foreach ($s['gift']['tiers'] as $t) if (isset($map[$t['id']]) && empty($map[$t['id']]['hidden'])) $tiers[] = array('min' => $t['min'], 'name' => $map[$t['id']]['n']);
        if ($tiers) $h['gift'] = array('tiers' => $tiers, 'win' => $s['gift']['win'], 'popup' => !isset($s['gift']['popup']) || !empty($s['gift']['popup']));
    }
    $h['combo'] = !empty($s['combo']['on']) ? array('label' => $s['combo']['label'], 'off' => $s['combo']['off'], 'win' => $s['combo']['win'], 'popup' => !empty($s['combo']['popup'])) : null;
    $h['bundle'] = !empty($s['bundle']['on']) ? array('label' => $s['bundle']['label'], 'n' => $s['bundle']['n'], 'price' => $s['bundle']['price'], 'win' => $s['bundle']['win'], 'popup' => !empty($s['bundle']['popup'])) : null;
    $h['coupons'] = array();
    foreach ((isset($s['coupons']) && is_array($s['coupons']) ? $s['coupons'] : array()) as $cp) {
        if (!empty($cp['on']) && !empty($cp['popup']) && ghm_in_win(isset($cp['win']) ? $cp['win'] : array())) {
            $h['coupons'][] = array('code' => $cp['code'], 'kind' => $cp['kind'], 'value' => $cp['value'], 'min' => $cp['min']);
        }
    }
    return array('home' => $h, 'lang' => $lang ? $lang : $s['default_lang'], 'fixed' => $fixed, 'urls' => $root ? array('pl' => home_url('/'), 'en' => home_url('/en/')) : new stdClass(), 'brand' => $s['brand'], 'logo' => $logo, 'hit' => rest_url('ghm/v1/hit'),
        'biz' => array('hours' => $s['hours'], 'address' => $s['address'], 'city' => $s['city'], 'phone' => ghm_phone_disp($phone), 'tel' => 'tel:' . ghm_phone_tel($phone), 'map' => $map, 'status' => !empty($s['show_status'])));
}
function ghm_home_markup($data, $lang = 'pl') {
    $s = $data['settings']; $hp = ghm_home_public($data, $lang); $H = $data['home'];
    $L = function ($o) use ($lang) { return isset($o[$lang]) && $o[$lang] !== '' ? $o[$lang] : $o['pl']; };
    $b = esc_html($s['brand']);
    $first = '';
    foreach ($hp['home']['buttons'] as $x) { if (!empty($x['show'])) { $first .= '<a href="' . esc_url($x['url'], array('http', 'https', 'tel')) . '">' . esc_html($L($x['label'])) . '</a> '; } }
    // text for search engines and no-JS visitors; the script replaces it with the full page
    $seo = '<div class="ghh-seo"><h1>' . esc_html($s['brand'] . ' ' . $s['city'] . ' – ' . $L($H['title'])) . '</h1><p>' . esc_html($L($H['tagline'])) . '</p>' .
        '<h2>' . esc_html($L($H['about_title'])) . '</h2><p>' . esc_html($L($H['about'])) . '</p>' .
        '<p>' . esc_html($s['address'] . ', ' . ($s['postal'] !== '' ? $s['postal'] . ' ' : '') . $s['city']) . '</p>' .
        '<p><a href="' . esc_url($hp['biz']['tel'], array('tel')) . '">' . esc_html($hp['biz']['phone']) . '</a></p>' .
        '<p>' . esc_html(ghm_hours_summary($s['hours'], $lang)) . '</p></div>';
    return '<div id="ghh" class="ghh" data-lang="' . esc_attr($lang) . '">' . $seo . '<noscript><div class="ghh-ns"><b>' . $b . '</b><br>' . $first . '</div></noscript></div>';
}
function ghm_home_script($data, $lang = null, $fixed = false) {
    return '<script>window.GHM_BASE=' . ghm_json(GHM_URL . 'assets/') . ';window.GHM_HOME=' . ghm_json(ghm_home_public($data, $lang, $fixed)) . ';' . ghm_ord_js() . '</script>';
}
function ghm_home_shortcode() {
    $data = ghm_get_data();
    if (empty($data['home']['enabled'])) return '';
    wp_enqueue_style('ghm-fonts', ghm_fonts_url(), array(), null);
    wp_enqueue_style('ghm-home', GHM_URL . 'assets/home.css', array(), GHM_VER);
    wp_enqueue_script('ghm-shared', GHM_URL . 'assets/shared.js', array(), GHM_VER, true);
    wp_enqueue_script('ghm-home', GHM_URL . 'assets/home.js', array('ghm-shared'), GHM_VER, true);
    wp_add_inline_script('ghm-home', 'window.GHM_BASE=' . ghm_json(GHM_URL . 'assets/') . ';window.GHM_HOME=' . ghm_json(ghm_home_public($data)) . ';' . ghm_ord_js(), 'before');
    return ghm_home_markup($data);
}
add_shortcode('grill_hub_home', 'ghm_home_shortcode');

// browser tab / home-screen / Google search-result icon (the flame logo)
function ghm_icons_html() {
    $u = GHM_URL . 'assets/images/';
    return '<link rel="icon" href="' . esc_url($u . 'favicon.ico') . '" sizes="any">' . "\n" .
        '<link rel="icon" type="image/png" sizes="32x32" href="' . esc_url($u . 'favicon-32.png') . '">' . "\n" .
        '<link rel="icon" type="image/png" sizes="48x48" href="' . esc_url($u . 'favicon-48.png') . '">' . "\n" .
        '<link rel="icon" type="image/png" sizes="192x192" href="' . esc_url($u . 'icon-192.png') . '">' . "\n" .
        '<link rel="apple-touch-icon" sizes="180x180" href="' . esc_url($u . 'apple-touch-icon.png') . '">' . "\n";
}
// themed (non-standalone) pages: add the icon unless the site already has its own WordPress site icon
add_action('wp_head', function () {
    if (function_exists('has_site_icon') && has_site_icon()) return;
    echo ghm_icons_html(); // phpcs:ignore -- escaped above
}, 5);

/* SEO: description, Open Graph, Twitter card, hreflang and Restaurant structured data (for Google Search and Google Maps) */
function ghm_seo_meta($title, $desc, $url, $img, $icon, $lang, $s) {
    $m = function ($k, $v, $attr = 'name') { return '<meta ' . $attr . '="' . esc_attr($k) . '" content="' . esc_attr($v) . '">' . "\n"; };
    return $m('description', $desc) . $m('robots', 'index,follow,max-image-preview:large') .
        $m('og:type', 'website', 'property') . $m('og:site_name', $s['brand'], 'property') . $m('og:title', $title, 'property') . $m('og:description', $desc, 'property') . $m('og:url', $url, 'property') .
        $m('og:locale', $lang === 'en' ? 'en_GB' : 'pl_PL', 'property') . $m('og:image', $img, 'property') . $m('og:image:alt', $s['brand'] . ' – ' . $s['city'], 'property') .
        ($s['og_image'] === '' ? $m('og:image:width', '1200', 'property') . $m('og:image:height', '630', 'property') : '') .
        $m('twitter:card', 'summary_large_image') . $m('twitter:title', $title) . $m('twitter:description', $desc) . $m('twitter:image', $img) .
        $m('business:contact_data:street_address', $s['address'], 'property') . $m('business:contact_data:locality', $s['city'], 'property') . $m('business:contact_data:country_name', 'Polska', 'property') . $m('business:contact_data:phone_number', ghm_phone_tel(ghm_phone($s)), 'property') ;

}
function ghm_jsonld($ld) {
    return '<script type="application/ld+json">' . wp_json_encode($ld, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "</script>\n";
}
function ghm_seo($data, $url, $lang = 'pl', $canon = '', $alts = array()) {
    $s = $data['settings'];
    $en = $lang === 'en';
    $addr = $s['address'] . ', ' . ($s['postal'] !== '' ? $s['postal'] . ' ' : '') . $s['city'];
    $phone = ghm_phone($s); $tel = ghm_phone_tel($phone); $sum = ghm_hours_summary($s['hours'], $lang);
    $custom_t = $en ? $s['seo_title_en'] : $s['seo_title']; $custom_d = $en ? $s['seo_desc_en'] : $s['seo_desc'];
    $title = $custom_t !== '' ? $custom_t : ($en ? 'Kebab & Grill in ' . $s['city'] . ' – ' . $s['brand'] . ' | ' . $s['address'] : 'Kebab i Grill ' . $s['city'] . ' – ' . $s['brand'] . ' | ' . $s['address']);
    $desc = $custom_d !== '' ? $custom_d : ($en
        ? 'Kebab, shawarma, wraps and grill in ' . $s['city'] . '. ' . $s['brand'] . ', ' . $addr . '. Order online or pick up. Hours: ' . $sum . '. Tel. ' . ghm_phone_disp($phone) . '.'
        : 'Kebab, shawarma, wrapy i grill w ' . $s['city'] . '. ' . $s['brand'] . ', ' . $addr . '. Zamów online lub odbierz na miejscu. Godziny: ' . $sum . '. Tel. ' . ghm_phone_disp($phone) . '.');
    $img = ghm_asset_url($s['og_image'], 'images/og-default.jpg');
    $icon = ghm_asset_url($s['logo'], 'images/logo-flame.png');
    $menu = !empty($data['home']['menu_url']) ? $data['home']['menu_url'] : $url;
    $days = array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday');
    $spec = array();
    foreach (ghm_hours_groups($s['hours']) as $g) {
        $spec[] = array('@type' => 'OpeningHoursSpecification', 'dayOfWeek' => array_map(function ($d) use ($days) { return $days[$d]; }, $g['days']), 'opens' => $g['open'], 'closes' => $g['close']);
    }
    $ld = array('@context' => 'https://schema.org', '@type' => 'Restaurant', '@id' => home_url('/') . '#restaurant', 'name' => $s['brand'], 'url' => $url, 'image' => array($img), 'logo' => $icon,
        'description' => $desc, 'telephone' => $tel, 'servesCuisine' => array('Kebab', 'Shawarma', 'Grill', 'Middle Eastern'), 'hasMenu' => $menu, 'hasMap' => ghm_maps_url($s), 'areaServed' => array('@type' => 'City', 'name' => $s['city']),
        'address' => array_filter(array('@type' => 'PostalAddress', 'streetAddress' => $s['address'], 'addressLocality' => $s['city'], 'postalCode' => $s['postal'], 'addressRegion' => 'śląskie', 'addressCountry' => 'PL')),
        'openingHoursSpecification' => $spec,
        'potentialAction' => array('@type' => 'OrderAction', 'target' => array('@type' => 'EntryPoint', 'urlTemplate' => $menu, 'actionPlatform' => array('http://schema.org/DesktopWebPlatform', 'http://schema.org/MobileWebPlatform')), 'deliveryMethod' => 'http://purl.org/goodrelations/v1#DeliveryModePickUp'));
    $same = array();
    foreach (array('social', 'delivery') as $g) foreach ($data['home'][$g] as $x) if (!empty($x['show']) && !empty($x['url'])) $same[] = $x['url'];
    if ($same) $ld['sameAs'] = $same;
    if ($s['lat'] !== '' && $s['lng'] !== '') $ld['geo'] = array('@type' => 'GeoCoordinates', 'latitude' => (float) $s['lat'], 'longitude' => (float) $s['lng']);
    $h = '';
    if ($canon !== '') $h .= '<link rel="canonical" href="' . esc_url($canon) . '">' . "\n";
    foreach ($alts as $hl => $u) $h .= '<link rel="alternate" hreflang="' . esc_attr($hl) . '" href="' . esc_url($u) . '">' . "\n";
    $h .= ghm_seo_meta($title, $desc, $canon !== '' ? $canon : $url, $img, $icon, $lang, $s) . ghm_jsonld($ld);
    return array('title' => $title, 'head' => $h);
}
// menu page: title, description and a schema.org Menu with every dish and price
function ghm_seo_menu($data, $url) {
    $s = $data['settings'];
    $title = 'Menu i zamówienia online – ' . $s['brand'] . ' ' . $s['city'] . ' | Kebab, Shawarma, Grill, Wrapy';
    $desc = 'Menu ' . $s['brand'] . ' w ' . $s['city'] . ': kebab, shawarma, wrapy, talerze z grilla, hummus i desery. Zbuduj własny wrap i zamów online – ' . $s['address'] . '.';
    $img = ghm_asset_url($s['og_image'], 'images/og-default.jpg'); $icon = ghm_asset_url($s['logo'], 'images/logo-flame.png');
    $secs = array();
    foreach ($data['cats'] as $c) {
        $its = array();
        foreach ($data['items'] as $it) {
            if ($it['cat'] !== $c['id'] || !empty($it['hidden'])) continue;
            $offers = array();
            foreach ($it['v'] as $v) { $o = array('@type' => 'Offer', 'price' => number_format((float) $v['p'], 2, '.', ''), 'priceCurrency' => 'PLN'); if ($v['l']['pl'] !== '') $o['name'] = $v['l']['pl']; $offers[] = $o; }
            $mi = array('@type' => 'MenuItem', 'name' => $it['n']['pl'], 'offers' => count($offers) === 1 ? $offers[0] : $offers);
            if ($it['d']['pl'] !== '') $mi['description'] = $it['d']['pl'];
            $its[] = $mi;
        }
        if ($its) $secs[] = array('@type' => 'MenuSection', 'name' => $c['name']['pl'], 'hasMenuItem' => $its);
    }
    $ld = array('@context' => 'https://schema.org', '@type' => 'Menu', 'name' => $s['brand'] . ' – menu', 'inLanguage' => 'pl', 'url' => $url, 'hasMenuSection' => $secs,
        'provider' => array('@type' => 'Restaurant', '@id' => home_url('/') . '#restaurant', 'name' => $s['brand']));
    return array('title' => $title, 'head' => '<link rel="canonical" href="' . esc_url($url) . '">' . "\n" . ghm_seo_meta($title, $desc, $url, $img, $icon, 'pl', $s) . ghm_jsonld($ld));
}
// readable list of the menu for crawlers; menu.js replaces it with the interactive menu
function ghm_menu_seo_list($data) {
    $o = '<div class="ghm-seo">';
    foreach ($data['cats'] as $c) {
        $li = '';
        foreach ($data['items'] as $it) {
            if ($it['cat'] !== $c['id'] || !empty($it['hidden'])) continue;
            $pr = array();
            foreach ($it['v'] as $v) $pr[] = ($v['l']['pl'] !== '' ? $v['l']['pl'] . ' ' : '') . $v['p'] . ' zł';
            $li .= '<li><b>' . esc_html($it['n']['pl']) . '</b> – ' . esc_html($it['d']['pl']) . ' ' . esc_html(implode(' / ', $pr)) . '</li>';
        }
        if ($li) $o .= '<h2>' . esc_html($c['name']['pl']) . '</h2><ul>' . $li . '</ul>';
    }
    return $o . '</div>';
}
function ghm_sitemap_xml() {
    $data = ghm_get_data(); $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";
    $u = array();
    if (!empty($data['home']['enabled']) && !empty($data['home']['root'])) {
        $pl = home_url('/'); $en = home_url('/en/');
        foreach (array($pl, $en) as $loc) $u[] = '<url><loc>' . esc_url($loc) . '</loc><xhtml:link rel="alternate" hreflang="pl" href="' . esc_url($pl) . '"/><xhtml:link rel="alternate" hreflang="en" href="' . esc_url($en) . '"/><changefreq>weekly</changefreq><priority>1.0</priority></url>';
    }
    $menu = ghm_find_page(); if ($menu) $u[] = '<url><loc>' . esc_url(get_permalink($menu)) . '</loc><changefreq>weekly</changefreq><priority>0.9</priority></url>';
    return $x . implode("\n", $u) . "\n</urlset>\n";
}
add_filter('robots_txt', function ($out) { return $out . "\nSitemap: " . home_url('/sitemap.xml') . "\n"; }, 20);

// Standalone mode: a page that contains the shortcode is shown without the theme header/footer (ideal for the QR code)
// The one header used by the tracking and loyalty pages (same look as the menu header).
// $items: array(code => url or '') - an empty url makes the item a button for the page's own script (data-lang).
function ghm_hdr_html($lang, $items) {
    $data = ghm_get_data(); $st = $data['settings'];
    $logo = ghm_asset_url($st['logo'], 'images/logo-flame.png');
    $home = ghm_home_target($data);
    $mp = ghm_find_page(); $menu = $mp ? get_permalink($mp) : '';
    $names = array('pl' => 'Polski', 'en' => 'English', 'ar' => 'العربية');
    $aria = array('pl' => array('Strona główna', 'Menu', 'Język'), 'en' => array('Home', 'Menu', 'Language'), 'ar' => array('الرئيسية', 'المنيو', 'اللغة'));
    $a = isset($aria[$lang]) ? $aria[$lang] : $aria['pl'];
    $li = '';
    foreach ($names as $c => $n) {
        $cur = $c === $lang ? ' aria-current="true"' : '';
        $li .= '<li>' . (!empty($items[$c]) ? '<a href="' . esc_url($items[$c]) . '" data-lang="' . esc_attr($c) . '" hreflang="' . esc_attr($c) . '"' . $cur . '>' . esc_html($n) . '</a>' : '<button type="button" data-lang="' . esc_attr($c) . '"' . $cur . '>' . esc_html($n) . '</button>') . '</li>';
    }
    $h = '<header class="gh-hdr" dir="' . ($lang === 'ar' ? 'rtl' : 'ltr') . '" style="--flame:url(&quot;' . esc_url($logo) . '&quot;)"><div class="gh-hdr-in">';
    $h .= '<a class="gh-brand" href="' . esc_url($home) . '" aria-label="' . esc_attr($st['brand']) . '"><span class="gh-flame" role="img" aria-label="' . esc_attr($st['brand']) . '"></span><b>' . esc_html($st['brand']) . '</b></a>';
    if ($menu) $h .= '<a class="gh-ib" href="' . esc_url($menu) . '" aria-label="' . esc_attr($a[1]) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M7 3v8a2 2 0 0 0 2 2v8M11 3v8a2 2 0 0 1-2 2M9 3v6M17 21V3c-2.2 1.2-3.5 4-3.5 7.5 0 1.7.9 2.5 2.2 2.5H17"/></svg></a>';
    $h .= '<a class="gh-ib red" href="' . esc_url($home) . '" aria-label="' . esc_attr($a[0]) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 11l8-7 8 7"/><path d="M6 10v10h4v-6h4v6h4V10"/></svg></a>';
    $h .= '<div class="gh-lang"><button type="button" aria-haspopup="true" aria-expanded="false" aria-label="' . esc_attr($a[2]) . '"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c3 3.2 3 14.8 0 18M12 3c-3 3.2-3 14.8 0 18"/></svg><span class="gh-lcode">' . esc_html(strtoupper($lang)) . '</span></button><ul class="gh-lmenu" hidden>' . $li . '</ul></div>';
    return $h . '</div></header>';
}
// Shared footer: one footer for every public page (home, menu, order tracking, loyalty). Translated live by shared.js.
// Links are editable in Grill Hub > Settings > Footer links. Built-in ids resolve their URL automatically;
// custom links (any other id) use their stored URL.
function ghm_ftr_builtin_ids() { return array( 'home', 'menu', 'track', 'loy', 'card', 'staff' ); }
function ghm_ftr_link_url( $id, $custom_url = '' ) {
    switch ( $id ) {
        case 'home': $d = ghm_get_data(); return ghm_home_target( $d );
        case 'menu': $mp = ghm_find_page(); return $mp ? (string) get_permalink( $mp ) : '';
        case 'track': return ghm_track_url();
        case 'loy': return function_exists( 'ls_url_of' ) ? (string) ls_url_of( 'loyalty_register' ) : '';
        case 'card': return function_exists( 'ls_url_of' ) ? (string) ls_url_of( 'loyalty_card' ) : '';
        case 'staff': return function_exists( 'ls_url_of' ) ? (string) ls_url_of( 'loyalty_staff' ) : '';
        default: return (string) $custom_url;
    }
}
function ghm_ftr_html($lang, $dark = false) {
    $data = ghm_get_data(); $st = $data['settings'];
    $links_cfg = isset( $st['footer_links'] ) && is_array( $st['footer_links'] ) ? $st['footer_links'] : array();
    if ( ! $links_cfg ) { // fall back to the built-in set
        foreach ( ghm_ftr_builtin_ids() as $bid ) $links_cfg[] = array( 'id' => $bid, 'on' => true, 'label' => array() );
    }
    $T = array( 'rights' => array( 'pl' => 'Wszelkie prawa zastrzeżone', 'en' => 'All rights reserved', 'ar' => 'جميع الحقوق محفوظة' ) );
    $links = array();
    foreach ( $links_cfg as $lc ) {
        if ( ! is_array( $lc ) || empty( $lc['on'] ) || ! isset( $lc['id'] ) ) continue;
        $id = sanitize_key( (string) $lc['id'] );
        if ( $id === '' ) continue;
        $url = ghm_ftr_link_url( $id, isset( $lc['url'] ) ? $lc['url'] : '' );
        if ( $url === '' ) continue;
        $lbl = isset( $lc['label'] ) && is_array( $lc['label'] ) ? $lc['label'] : array();
        $T[ $id ] = array(
            'pl' => isset( $lbl['pl'] ) && $lbl['pl'] !== '' ? $lbl['pl'] : $id,
            'en' => isset( $lbl['en'] ) && $lbl['en'] !== '' ? $lbl['en'] : $id,
            'ar' => isset( $lbl['ar'] ) && $lbl['ar'] !== '' ? $lbl['ar'] : $id,
        );
        $links[] = array( $id, $url );
    }
    if ( ! isset( $T['home'][ $lang ] ) ) $lang = 'pl';
    $logo = ghm_asset_url( $st['logo'], 'images/logo-flame.png' );
    $h = '<footer class="gh-ftr' . ( $dark ? ' gh-ftr-dark' : '' ) . '" dir="' . ( $lang === 'ar' ? 'rtl' : 'ltr' ) . '" style="--flame:url(&quot;' . esc_url( $logo ) . '&quot;)"><span class="gh-ftf" aria-hidden="true"></span><nav>';
    foreach ( $links as $l ) $h .= '<a href="' . esc_url( $l[1] ) . '" data-ft="' . esc_attr( $l[0] ) . '">' . esc_html( $T[ $l[0] ][ $lang ] ) . '</a>';
    $h .= '</nav><p><span class="gh-ftc">&copy; ' . esc_html( date( 'Y' ) ) . ' ' . esc_html( $st['brand'] ) . '</span> &middot; <span data-ft="rights">' . esc_html( $T['rights'][ $lang ] ) . '</span></p></footer>';
    return $h . '<script>window.GHM_FT=' . wp_json_encode( $T ) . ';</script>';
}
function ghm_track_render($data) {
        if (function_exists('status_header')) status_header(200);
        nocache_headers();
        header('Content-Type: text/html; charset=' . get_bloginfo('charset')); header('X-Robots-Tag: noindex, nofollow');
        $pub = ghm_public($data);
        $cfg = array('brand' => $data['settings']['brand'], 'logo' => isset($pub['settings']['logo']) ? $pub['settings']['logo'] : '', 'lang' => $data['settings']['default_lang'],
            'status' => rest_url('ghm/v1/status'), 'lookup' => rest_url('ghm/v1/lookup'), 'pay_retry' => rest_url('ghm/v1/pay/retry'), 'menu' => get_permalink(ghm_find_page()) ?: home_url('/'), 'home' => ghm_home_target($data), 'wait' => $pub['settings']['wait'],
            'push_key' => isset($pub['settings']['push_key']) ? $pub['settings']['push_key'] : '', 'push_url' => isset($pub['settings']['push_url']) ? $pub['settings']['push_url'] : '', 'sw_url' => isset($pub['settings']['sw_url']) ? $pub['settings']['sw_url'] : '',
            'phone' => isset($pub['settings']['phone']) ? $pub['settings']['phone'] : '');
        ?><!doctype html>
<html lang="<?php echo esc_attr($data['settings']['default_lang']); ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#ffffff">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html($data['settings']['brand']); ?> – <?php echo 'Zamówienie'; ?></title>
<?php echo ghm_icons_html(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url(ghm_fonts_url()); ?>">
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/hdr.css?ver=' . GHM_VER); ?>">
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/track.css?ver=' . GHM_VER); ?>">
</head>
<body class="ghm-track">
<?php echo ghm_hdr_html($data['settings']['default_lang'], array()); ?>
<div id="tr"></div>
<?php echo ghm_ftr_html($data['settings']['default_lang']); ?>
<script>window.GHM_BASE=<?php echo ghm_json(GHM_URL . 'assets/'); ?>;window.GHM_TRACK=<?php echo ghm_json($cfg); ?>;window.GHM_ORD={status:<?php echo ghm_json(rest_url('ghm/v1/status')); ?>,track:<?php echo ghm_json(ghm_track_url()); ?>,noBar:true,keepAwake:true};</script>
<script src="<?php echo esc_url(GHM_URL . 'assets/shared.js?ver=' . GHM_VER); ?>"></script>
<script src="<?php echo esc_url(GHM_URL . 'assets/strings.js?ver=' . GHM_VER); ?>"></script>
<script src="<?php echo esc_url(GHM_URL . 'assets/track.js?ver=' . GHM_VER); ?>"></script>
</body>
</html>
<?php
        exit;
}
/* ------------------------------------------------ 404 page (home-page style) */
function ghm_404_render($data) {
    if (function_exists('status_header')) status_header(404);
    nocache_headers();
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    $lang = $data['settings']['default_lang'];
    if (!in_array($lang, array('pl', 'en', 'ar'), true)) $lang = 'pl';
    $T = array(
        't' => array('pl' => 'Nie znaleziono strony', 'en' => 'Page not found', 'ar' => 'الصفحة غير موجودة'),
        's' => array('pl' => 'Strona, której szukasz, nie istnieje lub została przeniesiona.', 'en' => 'The page you are looking for does not exist or has been moved.', 'ar' => 'الصفحة اللي بتدور عليها مش موجودة أو اتنقلت.'),
        'home' => array('pl' => 'Strona główna', 'en' => 'Home', 'ar' => 'الرئيسية'),
        'menu' => array('pl' => 'Zobacz menu', 'en' => 'See the menu', 'ar' => 'شوف المنيو'),
    );
    $home = ghm_home_target($data);
    $mp = ghm_find_page(); $menu = $mp ? get_permalink($mp) : $home;
    $logo = ghm_asset_url($data['settings']['logo'], 'images/logo-flame.png');
    ?><!doctype html>
<html lang="<?php echo esc_attr($lang); ?>" dir="<?php echo $lang === 'ar' ? 'rtl' : 'ltr'; ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0a0a0a">
<meta name="robots" content="noindex,nofollow">
<title>404 – <?php echo esc_html($data['settings']['brand']); ?></title>
<?php echo ghm_icons_html(); ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url(ghm_fonts_url()); ?>">
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/home.css?ver=' . GHM_VER); ?>">
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/hdr.css?ver=' . GHM_VER); ?>">
<style>
.ghh-err{min-height:70vh;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;padding:40px 20px;position:relative;z-index:1}
.ghh-err .ghh-flame{width:74px;height:98px;object-fit:contain;margin-bottom:14px;filter:drop-shadow(0 0 26px rgba(225,30,38,.8))}
.ghh-err h1{margin:0;font-family:"Anton","Bebas Neue","Arial Narrow",Impact,sans-serif;font-size:clamp(72px,20vw,140px);line-height:1;color:#fff;letter-spacing:.02em}
.ghh-err h1::after{content:"";display:block;width:72px;height:6px;margin:14px auto 0;background:#e11e26;border-radius:3px}
.ghh-err h2{margin:18px 0 8px;font-size:clamp(22px,6vw,34px);color:#fff;text-transform:uppercase;letter-spacing:.04em}
.ghh-err p{margin:0 0 26px;color:rgba(255,255,255,.75);font-size:16px;max-width:44ch}
.ghh-err .row{display:flex;gap:12px;flex-wrap:wrap;justify-content:center}
.ghh-err .row a{display:inline-block;padding:14px 28px;border-radius:999px;font-weight:800;text-decoration:none;letter-spacing:.04em}
.ghh-err .row a.r{background:#e11e26;color:#fff}
.ghh-err .row a.w{background:#fff;color:#0a0a0a}
</style>
</head>
<body class="ghm-standalone ghh-body">
<div class="ghh" dir="<?php echo $lang === 'ar' ? 'rtl' : 'ltr'; ?>">
<?php echo ghm_hdr_html($lang, array()); ?>
<div class="ghh-err">
<img class="ghh-flame" src="<?php echo esc_url($logo); ?>" alt="<?php echo esc_attr($data['settings']['brand']); ?>">
<h1>404</h1>
<h2><?php echo esc_html($T['t'][$lang]); ?></h2>
<p><?php echo esc_html($T['s'][$lang]); ?></p>
<div class="row"><a class="r" href="<?php echo esc_url($home); ?>"><?php echo esc_html($T['home'][$lang]); ?></a><a class="w" href="<?php echo esc_url($menu); ?>"><?php echo esc_html($T['menu'][$lang]); ?></a></div>
</div>
</div>
<?php echo ghm_ftr_html($lang, true); ?>
<script src="<?php echo esc_url(GHM_URL . 'assets/shared.js?ver=' . GHM_VER); ?>"></script>
</body>
</html>
<?php
    exit;
}
function ghm_standalone() {
    if (is_admin() || is_feed() || is_robots() || is_trackback() || (function_exists('is_customize_preview') && is_customize_preview())) return;
    // Never interfere with REST API requests.
    if (defined('REST_REQUEST') && REST_REQUEST) return;
    $data = ghm_get_data();
    $post = is_singular() ? get_post() : null;
    // own URLs: /en/ (English home page, indexable) and the sitemap
    $path = trim((string) parse_url(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '', PHP_URL_PATH), '/');
    $base = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');
    $rel = ($base !== '' && strpos($path, $base) === 0) ? trim(substr($path, strlen($base)), '/') : $path;
    $homeOn = !empty($data['home']['enabled']) && !empty($data['home']['root']);
    if ($rel === 'sitemap.xml' || $rel === 'grill-hub-sitemap.xml') {
        // WordPress has already marked this unknown address as a 404 by now: force 200, or Google reports "Couldn't fetch"
        if (function_exists('status_header')) status_header(200);
        header('Content-Type: application/xml; charset=UTF-8'); header('X-Robots-Tag: noindex'); header('Cache-Control: public, max-age=3600');
        echo ghm_sitemap_xml(); exit;
    }
    if ($rel === 'track') ghm_track_render($data);
    if ($post && (has_shortcode($post->post_content, 'track') || has_shortcode($post->post_content, 'grill_hub_track'))) ghm_track_render($data);
    // 404 page in the home-page style (dark, flame, red) with the shared header + footer.
    if (is_404()) ghm_404_render($data);
    $isEn = $homeOn && $rel === 'en';
    $lang = $isEn ? 'en' : 'pl';
    // Front page takeover: the site root (e.g. https://grillhub.pl/) always shows the 3-button home page
    $isRoot = $homeOn && is_front_page() && !is_paged();
    if ($isEn) {
        $isHome = true; $isMenu = false; $isRoot = false;
        if (function_exists('status_header')) status_header(200);
    } elseif ($isRoot) {
        $isHome = true; $isMenu = false;
    } else {
        if (!$post) return;
        $isHome = has_shortcode($post->post_content, 'grill_hub_home');
        $isMenu = has_shortcode($post->post_content, 'grill_hub_menu');
        if ($isHome) { if (empty($data['home']['standalone']) || empty($data['home']['enabled'])) return; }
        elseif ($isMenu) { if (empty($data['settings']['standalone'])) return; }
        else return;
    }
    $pageTitle = $isRoot ? ($data['settings']['brand'] . ' – ' . get_bloginfo('name')) : get_the_title($post);
    if ($isRoot && trim((string) get_bloginfo('name')) === '') $pageTitle = $data['settings']['brand'];
    $seo = null;
    if ($isHome) {
        $url = $isEn ? home_url('/en/') : ($isRoot ? home_url('/') : get_permalink($post));
        $alts = $homeOn ? array('pl' => home_url('/'), 'en' => home_url('/en/'), 'x-default' => home_url('/')) : array();
        $seo = ghm_seo($data, $url, $lang, $isEn ? home_url('/en/') : ($homeOn ? home_url('/') : ''), $alts);
    } elseif ($isMenu) {
        $seo = ghm_seo_menu($data, get_permalink($post));
    }
    if ($seo) $pageTitle = $seo['title'];
    nocache_headers();
    header('Content-Type: text/html; charset=' . get_bloginfo('charset'));
    if ($isEn && function_exists('status_header')) status_header(200); // nocache_headers() can reset it; /en/ must answer 200
    ?><!doctype html>
<html lang="<?php echo esc_attr($isEn ? 'en' : $data['settings']['default_lang']); ?>">
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0605">
<title><?php echo esc_html($pageTitle); ?></title>
<?php echo ghm_icons_html(); // escaped inside ?>
<?php if ($seo) echo $seo['head']; // escaped inside ghm_seo() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url(ghm_fonts_url()); ?>">
<?php if ($isHome) : ?>
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/home.css?ver=' . GHM_VER); ?>">
</head>
<body class="ghm-standalone ghh-body">
<?php echo ghm_home_markup($data, $lang); ?>
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/hdr.css?ver=' . GHM_VER); ?>">
<?php echo ghm_ftr_html($lang, true); ?>
<?php echo ghm_home_script($data, $lang, $isEn); ?>
<script src="<?php echo esc_url(GHM_URL . 'assets/shared.js?ver=' . GHM_VER); ?>"></script>
<script src="<?php echo esc_url(GHM_URL . 'assets/home.js?ver=' . GHM_VER); ?>"></script>
<?php else : ?>
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/menu.css?ver=' . GHM_VER); ?>">
<style><?php echo ghm_vars_css($data); ?></style>
</head>
<body class="ghm-standalone">
<?php echo ghm_markup($data); ?>
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/hdr.css?ver=' . GHM_VER); ?>">
<?php echo ghm_ftr_html($lang); ?>
<?php echo ghm_data_script($data); ?>
<script src="<?php echo esc_url(GHM_URL . 'assets/shared.js?ver=' . GHM_VER); ?>"></script>
<script src="<?php echo esc_url(GHM_URL . 'assets/strings.js?ver=' . GHM_VER); ?>"></script>
<script src="<?php echo esc_url(GHM_URL . 'assets/menu.js?ver=' . GHM_VER); ?>"></script>
<?php if (!empty($data['settings']['animations'])) : foreach (array('vendor/gsap.min.js', 'vendor/ScrollTrigger.min.js', 'vendor/lenis.min.js', 'fx.js') as $f) : ?>
<script src="<?php echo esc_url(GHM_URL . 'assets/' . $f . '?ver=' . GHM_VER); ?>"></script>
<?php endforeach; endif; ?>
<?php endif; ?>
</body>
</html>
<?php
    exit;
}
add_action('template_redirect', 'ghm_standalone', 0); // before WordPress' own 404 redirects (redirect_canonical would send /en/ and the sitemap elsewhere)

/* --------------------------------------------------------------- admin */
function ghm_admin_menu() {
    add_menu_page('Restaurant Menu', 'Restaurant Menu', 'manage_options', 'grill-hub-menu', 'ghm_admin_page', 'dashicons-food', 58);
}
add_action('admin_menu', 'ghm_admin_menu');

function ghm_admin_assets($hook) {
    if ($hook !== 'toplevel_page_grill-hub-menu') return;
    wp_enqueue_media();
    wp_enqueue_style('ghm-admin', GHM_URL . 'assets/admin.css', array(), GHM_VER);
    wp_enqueue_script('ghm-strings', GHM_URL . 'assets/strings.js', array(), GHM_VER, true);
    wp_enqueue_script('ghm-admin', GHM_URL . 'assets/admin.js', array('ghm-strings', 'jquery'), GHM_VER, true);
    $cfg = array(
        'ajax' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('ghm_admin'),
        'base' => GHM_URL . 'assets/',
        'data' => ghm_get_data(),
        'pageUrl' => get_permalink(ghm_find_page()) ?: '',
        'homeUrl' => ghm_find_home() ? get_permalink(ghm_find_home()) : '',
        'kitchenUrl' => home_url('/?ghm-kitchen=1'),
        'siteUrl' => home_url('/'),
        'presets' => (object) ghm_presets(),
        'cpUsed' => (object) (is_array(get_option('ghm_cp_used', array())) ? get_option('ghm_cp_used', array()) : array()),
    );
    wp_add_inline_script('ghm-admin', 'window.GHM_ADMIN=' . ghm_json($cfg) . ';', 'before');
}
add_action('admin_enqueue_scripts', 'ghm_admin_assets');

function ghm_find_page() {
    $q = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 's' => '[grill_hub_menu', 'numberposts' => 1));
    return $q ? $q[0]->ID : 0;
}
// the "Rewards" button: the built-in Loyalty sign-up page when it exists (unless another address was typed in)
function ghm_rewards_url($url) {
    if (!function_exists('ls_url_of')) return $url;
    if ($url !== '' && strpos($url, 'stampstamp.pl') === false) return $url;
    $u = ls_url_of('loyalty_register');
    return $u !== '' ? $u : $url;
}
function ghm_track_url() {
    static $u = null; if ($u !== null) return $u;
    $u = home_url('/track/');
    $q = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 's' => '[track', 'numberposts' => 5));
    foreach ((array) $q as $p) { if (has_shortcode($p->post_content, 'track') || has_shortcode($p->post_content, 'grill_hub_track')) { $x = get_permalink($p->ID); if ($x) { $u = $x; break; } } }
    return $u;
}
function ghm_find_home() {
    $q = get_posts(array('post_type' => 'page', 'post_status' => 'publish', 's' => '[grill_hub_home', 'numberposts' => 1));
    return $q ? $q[0]->ID : 0;
}
function ghm_ajax_mkhome() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $id = ghm_find_home();
    if (!$id) $id = wp_insert_post(array('post_title' => 'Home', 'post_name' => 'home', 'post_content' => '[grill_hub_home]', 'post_status' => 'publish', 'post_type' => 'page'));
    wp_send_json_success(array('url' => $id ? get_permalink($id) : ''));
}
add_action('wp_ajax_ghm_mkhome', 'ghm_ajax_mkhome');
function ghm_admin_page() {
    if (!current_user_can('manage_options')) return;
    echo '<div class="wrap"><div id="ghm-admin"><p>Loading…</p></div></div>';
}

function ghm_ajax_save() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $raw = isset($_POST['data']) ? json_decode(wp_unslash($_POST['data']), true) : null;
    if (!is_array($raw)) wp_send_json_error('bad data', 400);
    $clean = ghm_clean($raw);
    update_option(GHM_OPT, ghm_json($clean), false);
    wp_send_json_success(ghm_get_data());
}
add_action('wp_ajax_ghm_save', 'ghm_ajax_save');

function ghm_ajax_reset() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    delete_option(GHM_OPT);
    wp_send_json_success(ghm_get_data());
}
add_action('wp_ajax_ghm_reset', 'ghm_ajax_reset');

// Create a "Menu" page with the shortcode on activation (only if none exists)
function ghm_activate() {
    if (!ghm_find_page()) {
        wp_insert_post(array('post_title' => 'Order', 'post_name' => 'order', 'post_content' => '[grill_hub_menu]', 'post_status' => 'publish', 'post_type' => 'page'));
    }
    if (!ghm_find_home()) {
        wp_insert_post(array('post_title' => 'Home', 'post_name' => 'home', 'post_content' => '[grill_hub_home]', 'post_status' => 'publish', 'post_type' => 'page'));
    }
}
register_activation_hook(__FILE__, 'ghm_activate');


/* ------------------------------------------------------------- orders */
add_action('init', function () {
    register_post_type('ghm_order', array('public' => false, 'show_ui' => false, 'supports' => array('title'), 'label' => 'Restaurant orders'));
    // Flush rewrite rules on plugin update (file replacement doesn't trigger activation).
    // This keeps /wp-json/ and custom routes working after an update.
    if (get_option('ghm_ver') !== GHM_VER) {
        flush_rewrite_rules();
        update_option('ghm_ver', GHM_VER, false);
    }
});
add_action('rest_api_init', function () {
    register_rest_route('ghm/v1', '/order', array('methods' => 'POST', 'callback' => 'ghm_rest_order', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/hit', array('methods' => 'POST', 'callback' => 'ghm_rest_hit', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/coupon', array('methods' => 'POST', 'callback' => 'ghm_rest_coupon', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/status', array('methods' => 'GET', 'callback' => 'ghm_rest_status', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/orders/(?P<id>\d+)/accept', array('methods' => 'POST', 'callback' => 'ghm_rest_accept', 'permission_callback' => 'ghm_kitchen_auth'));
    register_rest_route('ghm/v1', '/lookup', array('methods' => 'POST', 'callback' => 'ghm_rest_lookup', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/push/subscribe', array('methods' => 'POST', 'callback' => 'ghm_rest_push_sub', 'permission_callback' => '__return_true'));
    register_rest_route('ghm/v1', '/orders/(?P<id>\d+)/prep', array('methods' => 'POST', 'callback' => 'ghm_rest_prep', 'permission_callback' => 'ghm_kitchen_auth'));
    register_rest_route('ghm/v1', '/orders', array('methods' => 'GET', 'callback' => 'ghm_rest_orders', 'permission_callback' => 'ghm_kitchen_auth'));
    register_rest_route('ghm/v1', '/orders/(?P<id>\d+)/done', array('methods' => 'POST', 'callback' => 'ghm_rest_done', 'permission_callback' => 'ghm_kitchen_auth'));
});
function ghm_kitchen_auth($req) {
    $d = ghm_get_data();
    $pin = (string) $d['settings']['kitchen_pin'];
    $got = (string) $req->get_header('x_ghm_pin');
    return $pin !== '' && hash_equals($pin, $got);
}
function ghm_custom_price($cfg, $b) {
    $meat = isset($cfg['meat']) && in_array($cfg['meat'], array('wolowina', 'mix'), true) ? $cfg['meat'] : 'kurczak';
    $size = isset($cfg['size']) && $cfg['size'] === 'Mały' ? 'Mały' : 'Duży';
    $groups = array(
        array(array('czosnek', 'lagodny', 'tahini', 'ostry', 'serowy', 'granat'), $b['sauceFree'], $b['sauce']),
        array(array('pomidor', 'pietruszka', 'cebula', 'salata', 'arab'), $b['vegFree'], $b['veg']),
        array(array('ogorek', 'rzepa'), $b['pickleFree'], $b['pickle']),
    );
    $p = (float) $b['base'][$meat][$size];
    $q = isset($cfg['q']) && is_array($cfg['q']) ? $cfg['q'] : array();
    foreach ($groups as $g) {
        $n = 0;
        foreach ($g[0] as $id) $n += max(0, min(2, (int) (isset($q[$id]) ? $q[$id] : 0)));
        $p += max(0, $n - $g[1]) * $g[2];
    }
    foreach (array('frytki' => 'fries', 'ser' => 'cheese') as $id => $key) $p += max(0, min(2, (int) (isset($q[$id]) ? $q[$id] : 0))) * (float) $b[$key];
    return array($p, $meat, $size);
}
// turns the posted cart lines into priced lines (server prices only). Returns array(lines, subtotal, stats counters, parts for offers)
function ghm_parse_lines($data, $in_lines) {
    $map = array();
    foreach ($data['items'] as $it) $map[(int) $it['id']] = $it;
    $lines = array(); $total = 0; $cnt = array(); $parts = array();
    foreach (array_slice((array) $in_lines, 0, 60) as $l) {
        if (!is_array($l)) continue;
        $qty = max(1, min(50, (int) (isset($l['qty']) ? $l['qty'] : 1)));
        if (!empty($l['custom'])) {
            $c = ghm_custom_price(isset($l['cfg']) && is_array($l['cfg']) ? $l['cfg'] : array(), $data['builder']);
            $lines[] = array('name' => 'Wlasny wrap (' . $c[2] . ', ' . ($c[1] === 'kurczak' ? 'Kurczak' : ($c[1] === 'mix' ? 'Mix' : 'Wolowina')) . ')', 'qty' => $qty, 'price' => $c[0], 'desc' => sanitize_text_field(isset($l['desc']) ? $l['desc'] : ''));
            $total += $c[0] * $qty; $parts[] = array('cat' => 'build', 'qty' => $qty, 'price' => (float) $c[0]);
            $cnt['i:custom'] = (isset($cnt['i:custom']) ? $cnt['i:custom'] : 0) + $qty;
        } else {
            $id = (int) (isset($l['id']) ? $l['id'] : 0); $vi = (int) (isset($l['vi']) ? $l['vi'] : 0);
            if (!isset($map[$id]) || !isset($map[$id]['v'][$vi]) || !empty($map[$id]['hidden'])) continue;
            $it = $map[$id]; $v = $it['v'][$vi]; $vl = $v['l']['pl'];
            $lines[] = array('name' => $it['n']['pl'] . ($vl !== '' ? ' (' . $vl . ')' : ''), 'qty' => $qty, 'price' => (float) $v['p'], 'desc' => '');
            $total += (float) $v['p'] * $qty; $parts[] = array('cat' => $it['cat'], 'qty' => $qty, 'price' => (float) $v['p'], 'id' => $id);
            $cnt['i:' . $id] = (isset($cnt['i:' . $id]) ? $cnt['i:' . $id] : 0) + $qty;
        }
    }
    return array($lines, round($total, 2), $cnt, $parts);
}
function ghm_cp_used($code) { $u = get_option('ghm_cp_used', array()); return is_array($u) && isset($u[$code]) ? (int) $u[$code] : 0; }
function ghm_find_coupon($data, $code) {
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $code));
    if ($code === '') return null;
    foreach ($data['settings']['coupons'] as $c) if ($c['code'] === $code) return $c;
    return null;
}
// combo (wrap + drink) and coupon discounts. Returns array(combo, coupon, code, error)
function ghm_offer_calc($data, $parts, $subtotal, $code, $ts = null) {
    $S = $data['settings']; $combo = 0.0; $coupon = 0.0; $err = ''; $cc = '';
    $cb = $S['combo'];
    if (!empty($cb['on']) && $cb['off'] > 0 && ghm_in_win($cb['win'], $ts)) {
        $m = 0; $d = 0;
        foreach ($parts as $p) { if (in_array($p['cat'], $cb['main_cats'], true)) $m += $p['qty']; if (in_array($p['cat'], $cb['drink_cats'], true)) $d += $p['qty']; }
        $combo = min($subtotal, min($m, $d) * (float) $cb['off']);
    }
    $bd = isset($S['bundle']) ? $S['bundle'] : array(); // "N sandwiches for X zł": every full group of N (most expensive first) costs X; the better of the two offers applies
    if (!empty($bd['on']) && $bd['n'] >= 2 && ghm_in_win($bd['win'], $ts)) {
        $pr = array();
        foreach ($parts as $p) if (isset($p['price']) && (in_array($p['cat'], $bd['cats'], true) || (isset($p['id']) && in_array((string) $p['id'], $bd['inc'], true))) && !(isset($p['id']) && in_array((string) $p['id'], $bd['exc'], true))) for ($i = 0; $i < $p['qty']; $i++) $pr[] = (float) $p['price'];
        rsort($pr); $bo = 0.0;
        for ($i = 0; $i + $bd['n'] <= count($pr); $i += $bd['n']) $bo += max(0, array_sum(array_slice($pr, $i, $bd['n'])) - (float) $bd['price']);
        if ($bo > $combo) $combo = min($subtotal, $bo);
    }
    if ((string) $code !== '') {
        $c = ghm_find_coupon($data, $code);
        $base = $subtotal - $combo;
        if (!$c) $err = 'invalid';
        elseif (empty($c['on']) || !ghm_in_win($c['win'], $ts)) $err = 'expired';
        elseif ($c['max_uses'] > 0 && ghm_cp_used($c['code']) >= $c['max_uses']) $err = 'used';
        elseif ($base < $c['min']) $err = 'min';
        else {
            $coupon = round($c['kind'] === 'percent' ? $base * $c['value'] / 100 : min($base, $c['value']), 2); $cc = $c['code'];
        }
    }
    return array(round($combo, 2), $coupon, $cc, $err);
}
function ghm_rest_coupon($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'; $k = 'ghm_cpl_' . md5($ip); $n = (int) get_transient($k);
    if ($n >= 20) return new WP_REST_Response(array('ok' => false, 'error' => 'rate'), 429);
    set_transient($k, $n + 1, 60);
    $data = ghm_get_data(); $in = $req->get_json_params();
    $c = ghm_find_coupon($data, is_array($in) && isset($in['code']) ? $in['code'] : '');
    if (!$c) return new WP_REST_Response(array('ok' => false, 'error' => 'invalid'), 200);
    if (empty($c['on']) || !ghm_in_win($c['win'])) return new WP_REST_Response(array('ok' => false, 'error' => 'expired'), 200);
    if ($c['max_uses'] > 0 && ghm_cp_used($c['code']) >= $c['max_uses']) return new WP_REST_Response(array('ok' => false, 'error' => 'used'), 200);
    return new WP_REST_Response(array('ok' => true, 'code' => $c['code'], 'kind' => $c['kind'], 'value' => $c['value'], 'min' => $c['min']), 200);
}
// customer distance from the restaurant (1.17.28): great-circle distance in km between two lat/lng points
function ghm_haversine($la1, $ln1, $la2, $ln2) {
    $r = 6371.0088; $p1 = deg2rad($la1); $p2 = deg2rad($la2); $dp = deg2rad($la2 - $la1); $dl = deg2rad($ln2 - $ln1);
    $a = sin($dp / 2) * sin($dp / 2) + cos($p1) * cos($p2) * sin($dl / 2) * sin($dl / 2);
    return 2 * $r * asin(min(1, sqrt($a)));
}

// approximate position of an IP address (city level). Cached a day per IP; never blocks the order if the lookup fails.
function ghm_ip_point($ip) {
    if ($ip === '') return null;
    $key = 'ghm_ip_' . md5($ip);
    $c = get_transient($key);
    if (is_array($c)) return $c ?: null;
    $r = wp_remote_get('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,latitude,longitude', array('timeout' => 3));
    $pt = array();
    if (!is_wp_error($r) && wp_remote_retrieve_response_code($r) === 200) {
        $j = json_decode(wp_remote_retrieve_body($r), true);
        if (is_array($j) && !empty($j['success']) && isset($j['latitude'], $j['longitude'])) $pt = array((float) $j['latitude'], (float) $j['longitude']);
    }
    set_transient($key, $pt, DAY_IN_SECONDS);
    return $pt ?: null;
}
function ghm_rest_order($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
    $k = 'ghm_rl_' . md5($ip);
    $n = (int) get_transient($k);
    if ($n >= 8) return new WP_REST_Response(array('ok' => false, 'error' => 'rate'), 429);
    set_transient($k, $n + 1, 60);
    $data = ghm_get_data();
    if (empty($data['settings']['orders'])) return new WP_REST_Response(array('ok' => false, 'error' => 'off'), 403);
    // outside opening hours (Warsaw time) the server refuses the order too, not only the page
    if ($data['settings']['closed_mode'] === 'block' && !ghm_is_open($data['settings']['hours'])) return new WP_REST_Response(array('ok' => false, 'error' => 'closed'), 403);
    $in = $req->get_json_params();
    if (!is_array($in) || empty($in['lines']) || !is_array($in['lines'])) return new WP_REST_Response(array('ok' => false, 'error' => 'empty'), 400);
    list($lines, $sub, $cnt, $parts) = ghm_parse_lines($data, $in['lines']);
    $cnt['o'] = 1;
    if (!$lines) return new WP_REST_Response(array('ok' => false, 'error' => 'empty'), 400);
    $off = ghm_offer_calc($data, $parts, $sub, isset($in['code']) ? $in['code'] : '');
    if ($off[3] !== '') $off = ghm_offer_calc($data, $parts, $sub, ''); // a bad / used-up code never blocks the order, it just gives no discount
    $mode = (isset($in['mode']) && $in['mode'] === 'pick') ? 'pick' : 'dine';
    $gift = ghm_gift_pick($data, max(0, $sub - $off[0] - $off[1]));
    $fparts = $parts;
    if ($gift) { $lines[] = array('name' => 'GRATIS: ' . $gift['name'], 'qty' => 1, 'price' => 0, 'desc' => ''); $fparts[] = array('cat' => $gift['cat'], 'qty' => 1); $cnt['gf:' . $gift['id']] = 1; }
    $fee = ghm_fees_calc($data, $fparts, $mode);
    $total = max(0, $sub - $off[0] - $off[1]) + $fee[0] + $fee[1];
    // online payment: checked before anything is counted (coupon use etc.)
    $pm = isset($in['pay']) ? sanitize_key($in['pay']) : 'cash';
    $online = in_array($pm, ghm_pay_providers(), true);
    if ($online) {
        $pc = ghm_pay_order_check($pm, $in, $total);
        if (is_string($pc)) return new WP_REST_Response(array('ok' => false, 'error' => $pc), 400);
    }
    if ($off[0] > 0) $cnt['cb'] = 1;
    if ($off[2] !== '') { $cnt['cp:' . $off[2]] = 1; $u = (array) get_option('ghm_cp_used', array()); $u[$off[2]] = (isset($u[$off[2]]) ? (int) $u[$off[2]] : 0) + 1; update_option('ghm_cp_used', $u, false); }
    if ($off[0] + $off[1] > 0) $cnt['ds'] = (int) round($off[0] + $off[1]);
    $seq = (int) get_option('ghm_seq', 0) + 1;
    update_option('ghm_seq', $seq, false);
    $prep = !empty($data['settings']['prep_on']) ? (int) $data['settings']['prep_min'] : 0;
    $order = array(
        'num' => $seq, 'time' => current_time('mysql'), 'ts' => time(), 'mode' => $mode,
        'table' => substr(sanitize_text_field(isset($in['table']) ? $in['table'] : ''), 0, 20),
        'name' => substr(sanitize_text_field(isset($in['name']) ? $in['name'] : ''), 0, 60),
        'phone' => substr(sanitize_text_field(isset($in['phone']) ? $in['phone'] : ''), 0, 25),
        'eta' => substr(sanitize_text_field(isset($in['eta']) ? $in['eta'] : ''), 0, 8),
        'notes' => substr(sanitize_textarea_field(isset($in['notes']) ? $in['notes'] : ''), 0, 300),
        'lines' => $lines, 'sub' => $sub, 'combo' => $off[0], 'coupon' => $off[1], 'code' => $off[2], 'deposit' => $fee[0], 'pack' => $fee[1], 'total' => round($total, 2), 'done' => false,
        'src' => ghm_src_clean(isset($in['src']) ? $in['src'] : ''),
        'lang' => (isset($in['lang']) && in_array($in['lang'], array('pl', 'en', 'ar'), true)) ? $in['lang'] : 'pl',
        'prep' => $prep, 'ready_at' => $prep ? time() + $prep * 60 : 0, 'tok' => wp_generate_password(12, false, false), 'seen' => 0, 'alerted' => 0,
    );
    // how far the customer was from the restaurant: the phone's GPS when the order carries it, else their IP (carrier IPs often resolve to a hub city, tens of km off). Only the km figure is stored.
    $gs = $data['settings'];
    if ($gs['lat'] !== '' && $gs['lng'] !== '') {
        $pt = null; $src = '';
        if (isset($in['lat'], $in['lng'])) {
            $la = (float) $in['lat']; $lo = (float) $in['lng'];
            if ($la >= -90 && $la <= 90 && $lo >= -180 && $lo <= 180) { $pt = array($la, $lo); $src = 'gps'; }
        }
        if (!$pt) { $pt = ghm_ip_point(ghm_client_ip()); $src = 'ip'; }
        if ($pt) { $order['dist_km'] = round(ghm_haversine((float) $gs['lat'], (float) $gs['lng'], $pt[0], $pt[1]), 1); $order['dist_src'] = $src; }
    }
    $cnt['os:' . $order['src']] = 1; $cnt['rs:' . $order['src']] = (int) round($total);
    if ($online) {   // held until the gateway confirms the payment: no kitchen, no statistics, no alerts yet
        $order['pay'] = array('m' => $pm, 'st' => 'pending', 'amt' => ghm_pay_grosz($order['total']), 'test' => $pc['test'], 'ip' => ghm_client_ip(), 'try' => 0, 'ref' => '',
            'email' => $pm === 'p24' ? sanitize_email((string) $in['email']) : '');
        $order['cnt'] = $cnt; $order['ready_at'] = 0;
    }
    $pid = wp_insert_post(array('post_type' => 'ghm_order', 'post_status' => 'publish', 'post_title' => '#' . $seq, 'post_content' => wp_slash(ghm_json($order))));
    if (!$pid) return new WP_REST_Response(array('ok' => false, 'error' => 'db'), 500);
    // keep the last 30 days only
    foreach (get_posts(array('post_type' => 'ghm_order', 'numberposts' => 20, 'date_query' => array(array('before' => '30 days ago')), 'fields' => 'ids')) as $old) wp_delete_post($old, true);
    if ($online) {
        $url = ghm_pay_start($order, $pid);
        if (is_wp_error($url)) { wp_delete_post($pid, true); return new WP_REST_Response(array('ok' => false, 'error' => 'pay_init'), 502); }
        ghm_pay_save($pid, $order);
        return new WP_REST_Response(array('ok' => true, 'number' => $seq, 'total' => $order['total'], 'discount' => round($off[0] + $off[1], 2), 'id' => $pid, 'tok' => $order['tok'],
            'prep' => 0, 'ready_at' => 0, 'now' => time(), 'mode' => $mode, 'eta' => $order['eta'], 'pay_url' => $url), 200);
    }
    ghm_stats_add($cnt);
    ghm_customer_add($pid, $order);
    ghm_alert_new_order($pid, $order);
    return new WP_REST_Response(array('ok' => true, 'number' => $seq, 'total' => $order['total'], 'discount' => round($off[0] + $off[1], 2), 'id' => $pid, 'tok' => $order['tok'],
        'prep' => ($prep && !($mode === 'pick' && $order['eta'] !== '')) ? $prep : 0, 'ready_at' => $order['ready_at'], 'now' => time(), 'mode' => $mode, 'eta' => $order['eta']), 200);
}
// customer polls this with the order id + secret token to see "ready"
// order summary for the customer's own tracking page (order id + secret token required): what they ordered, the total, and their details so "add more" can prefill the menu form
function ghm_order_summary($o) {
    $ls = array();
    foreach (isset($o['lines']) && is_array($o['lines']) ? $o['lines'] : array() as $l) {
        $ls[] = array('n' => isset($l['name']) ? (string) $l['name'] : '', 'q' => (int) (isset($l['qty']) ? $l['qty'] : 1), 'p' => (float) (isset($l['price']) ? $l['price'] : 0), 'd' => isset($l['desc']) ? (string) $l['desc'] : '');
    }
    return array('lines' => $ls, 'total' => (float) (isset($o['total']) ? $o['total'] : 0), 'sub' => (float) (isset($o['sub']) ? $o['sub'] : 0), 'combo' => (float) (isset($o['combo']) ? $o['combo'] : 0), 'coupon' => (float) (isset($o['coupon']) ? $o['coupon'] : 0), 'dep' => (float) (isset($o['deposit']) ? $o['deposit'] : 0), 'pack' => (float) (isset($o['pack']) ? $o['pack'] : 0),
        'name' => isset($o['name']) ? (string) $o['name'] : '', 'phone' => isset($o['phone']) ? (string) $o['phone'] : '', 'mode' => isset($o['mode']) ? $o['mode'] : 'dine', 'eta' => isset($o['eta']) ? (string) $o['eta'] : '');
}
function ghm_rest_status($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'; $k = 'ghm_stl_' . md5($ip); $n = (int) get_transient($k);
    if ($n >= 60) return new WP_REST_Response(array('ok' => false), 429);
    set_transient($k, $n + 1, 60);
    $p = get_post((int) $req->get_param('id'));
    if (!$p || $p->post_type !== 'ghm_order') return new WP_REST_Response(array('ok' => false), 404);
    $o = json_decode($p->post_content, true);
    if (!is_array($o) || empty($o['tok']) || !hash_equals((string) $o['tok'], (string) $req->get_param('t'))) return new WP_REST_Response(array('ok' => false), 404);
    if (ghm_pay_state($o) === 'pending' || ghm_pay_state($o) === 'failed') { $o2 = ghm_pay_pull($p->ID); if (is_array($o2)) $o = $o2; }
    return new WP_REST_Response(array_merge(array('ok' => true, 'pay' => ghm_pay_state($o), 'ready' => !empty($o['done']), 'accepted' => !empty($o['accepted']) || !empty($o['done']), 'accepted_at' => (int) (isset($o['accepted_at']) ? $o['accepted_at'] : 0), 'ready_at' => (int) (isset($o['ready_at']) ? $o['ready_at'] : 0), 'now' => time(), 'num' => $o['num'], 'seen' => !empty($o['seen'])), ghm_order_summary($o)), 200);
}
/* ------------------------------------------------------------- statistics
   Anonymous counters only (no cookies, no IP, no personal data). t = all-time totals, d = per day (Warsaw date), last 120 days.
   Keys: v:home / v:menu = page views · b:<button id> = home button clicks · i:<dish id> = portions ordered · o = orders · u:<dish id> = "add" taps on cart suggestions */
function ghm_stats_add($keys) {
    $st = get_option(GHM_STATS, array());
    if (!is_array($st)) $st = array();
    $day = wp_date('Y-m-d', null, new DateTimeZone(GHM_TZ));
    foreach ($keys as $k => $n) {
        $n = (int) $n;
        if ($n < 1 || strlen($k) > 40) continue;
        $st['t'][$k] = (isset($st['t'][$k]) ? $st['t'][$k] : 0) + $n;
        $st['d'][$day][$k] = (isset($st['d'][$day][$k]) ? $st['d'][$day][$k] : 0) + $n;
    }
    if (!empty($st['d']) && count($st['d']) > 120) { ksort($st['d']); $st['d'] = array_slice($st['d'], -120, null, true); }
    update_option(GHM_STATS, $st, false);
}
// traffic source label: lowercase letters, digits, - _ (max 20); unknown/new labels beyond 40 distinct ones fall into "other"
function ghm_src_clean($s) {
    $s = substr(preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $s)), 0, 20);
    if ($s === '') return 'direct';
    $st = get_option(GHM_STATS, array());
    if (!isset($st['t']['s:' . $s]) && !isset($st['t']['os:' . $s])) {
        $n = 0; foreach (isset($st['t']) ? array_keys($st['t']) : array() as $k) if (strpos($k, 's:') === 0) $n++;
        if ($n >= 40) return 'other';
    }
    return $s;
}
function ghm_rest_hit($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x';
    $rk = 'ghm_hit_' . md5($ip);
    $n = (int) get_transient($rk);
    if ($n >= 90) return new WP_REST_Response(array('ok' => false), 429);
    set_transient($rk, $n + 1, 60);
    $in = json_decode((string) $req->get_body(), true);
    if (!is_array($in) || empty($in['k'])) return new WP_REST_Response(array('ok' => false), 400);
    $data = ghm_get_data(); $keys = array();
    $k = (string) $in['k']; $id = isset($in['id']) ? sanitize_key((string) $in['id']) : '';
    if ($k === 'view' && in_array($id, array('home', 'menu'), true)) {
        $keys['v:' . $id] = 1;
    } elseif ($k === 'visit') { // once per visit, whichever page the visitor lands on
        $keys['s:' . ghm_src_clean(isset($in['src']) ? $in['src'] : '')] = 1;
    } elseif ($k === 'btn') {
        $ids = array('reviews');
        foreach ($data['home']['buttons'] as $b) $ids[] = $b['id'];
        foreach (array('social', 'delivery') as $g) foreach ($data['home'][$g] as $b) $ids[] = $b['id'];
        if (in_array($id, $ids, true)) $keys['b:' . $id] = 1;
    } elseif ($k === 'up' || $k === 'order') {
        $map = array();
        foreach ($data['items'] as $it) $map[(int) $it['id']] = 1;
        if ($k === 'up' && isset($map[(int) $id])) $keys['u:' . (int) $id] = 1;
        if ($k === 'order' && !empty($in['lines']) && is_array($in['lines'])) {
            $keys['o'] = 1; $total = 0;
            $byid = array(); foreach ($data['items'] as $it) $byid[(int) $it['id']] = $it;
            foreach (array_slice($in['lines'], 0, 60) as $l) {
                $q = max(1, min(50, (int) (isset($l['qty']) ? $l['qty'] : 1)));
                if (!empty($l['custom'])) {
                    $keys['i:custom'] = (isset($keys['i:custom']) ? $keys['i:custom'] : 0) + $q;
                    $c = ghm_custom_price(isset($l['cfg']) && is_array($l['cfg']) ? $l['cfg'] : array(), $data['builder']); $total += $c[0] * $q;
                    continue;
                }
                $i = (int) (isset($l['id']) ? $l['id'] : 0);
                if (!isset($byid[$i])) continue;
                $keys['i:' . $i] = (isset($keys['i:' . $i]) ? $keys['i:' . $i] : 0) + $q;
                $vi = (int) (isset($l['vi']) ? $l['vi'] : 0); if (!isset($byid[$i]['v'][$vi])) $vi = 0;
                $total += (float) $byid[$i]['v'][$vi]['p'] * $q;
            }
            $src = ghm_src_clean(isset($in['src']) ? $in['src'] : '');
            $keys['os:' . $src] = 1; $keys['rs:' . $src] = (int) round($total);
        }
    }
    if ($keys) ghm_stats_add($keys);
    return new WP_REST_Response(array('ok' => true), 200);
}
function ghm_ajax_stats() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $d = ghm_get_data(); $st = get_option(GHM_STATS, array());
    $items = array(); foreach ($d['items'] as $it) $items[(string) $it['id']] = (isset($it['n']['en']) && $it['n']['en'] !== '' ? $it['n']['en'] : $it['n']['pl']);
    $btns = array(); foreach ($d['home']['buttons'] as $b) $btns[$b['id']] = (isset($b['label']['en']) && $b['label']['en'] !== '' ? $b['label']['en'] : $b['label']['pl']);
    $btns['reviews'] = 'Google reviews link';
    $nm = array('facebook' => 'Facebook', 'instagram' => 'Instagram', 'tiktok' => 'TikTok', 'wolt' => 'Wolt', 'ubereats' => 'Uber Eats', 'pyszne' => 'Pyszne.pl', 'bolt' => 'Bolt Food', 'glovo' => 'Glovo');
    foreach (array('social', 'delivery') as $g) foreach ($d['home'][$g] as $b) $btns[$b['id']] = $nm[$b['id']] . ' (' . $g . ')';
    wp_send_json_success(array('t' => isset($st['t']) ? $st['t'] : new stdClass(), 'd' => isset($st['d']) ? $st['d'] : new stdClass(), 'items' => $items, 'btns' => $btns, 'today' => wp_date('Y-m-d', null, new DateTimeZone(GHM_TZ))));
}
add_action('wp_ajax_ghm_stats', 'ghm_ajax_stats');
function ghm_ajax_stats_reset() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    delete_option(GHM_STATS);
    wp_send_json_success();
}
add_action('wp_ajax_ghm_stats_reset', 'ghm_ajax_stats_reset');

function ghm_order_out($p) {
    $o = json_decode($p->post_content, true);
    if (!is_array($o)) return null;
    $o['id'] = $p->ID;
    return $o;
}
function ghm_rest_orders($req) {
    $after = (int) $req->get_param('after');
    if (time() - (int) get_option('ghm_k_seen', 0) >= 8) update_option('ghm_k_seen', time(), false); // heartbeat: the kitchen page is open
    $out = array();
    foreach (get_posts(array('post_type' => 'ghm_order', 'numberposts' => 200, 'orderby' => 'ID', 'order' => 'DESC')) as $p) {
        $o = ghm_order_out($p);
        if ($o && !empty($o['pay']) && $o['pay']['st'] !== 'paid') continue;   // online order still waiting for its payment
        $fresh = $o && !empty($o['pay']['paid_at']) && time() - (int) $o['pay']['paid_at'] <= 40;   // paid just now: an older id, but new for the kitchen
        if ($p->ID <= $after && !$fresh) continue;
        if ($o) {
            if (empty($o['seen']) && ($p->ID > $after || $fresh)) { $c = json_decode($p->post_content, true); $c['seen'] = time(); wp_update_post(array('ID' => $p->ID, 'post_content' => wp_slash(ghm_json($c)))); $o['seen'] = $c['seen']; }
            unset($o['tok']);
            $out[] = $o;
        }
    }
    return new WP_REST_Response(array('orders' => array_reverse($out), 'now' => time()), 200);
}
function ghm_rest_done($req) {
    $p = get_post((int) $req['id']);
    if (!$p || $p->post_type !== 'ghm_order') return new WP_REST_Response(array('ok' => false), 404);
    $o = json_decode($p->post_content, true); $o['done'] = !$req->get_param('undo');
    if ($o['done']) { $o['done_at'] = time(); ghm_mark_accepted($o); }
    wp_update_post(array('ID' => $p->ID, 'post_content' => wp_slash(ghm_json($o))));
    if ($o['done']) ghm_push_order($p->ID, $o, 'ready');          // "your order is ready" push (once)
    return new WP_REST_Response(array('ok' => true), 200);
}
// kitchen changes the promised time: "+10 min" => ready_at = now + minutes
function ghm_rest_prep($req) {
    $p = get_post((int) $req['id']);
    if (!$p || $p->post_type !== 'ghm_order') return new WP_REST_Response(array('ok' => false), 404);
    $m = max(1, min(180, (int) $req->get_param('min')));
    $o = json_decode($p->post_content, true); $o['prep'] = $m; $o['ready_at'] = time() + $m * 60;
    $first = ghm_mark_accepted($o);
    wp_update_post(array('ID' => $p->ID, 'post_content' => wp_slash(ghm_json($o))));
    if ($first) ghm_push_order($p->ID, $o, 'accepted');
    return new WP_REST_Response(array('ok' => true, 'ready_at' => $o['ready_at']), 200);
}

/* ---------------------------------------------------- "order accepted" + customer push */
// sets accepted / accepted_at once. Returns true only the first time.
function ghm_mark_accepted(&$o) {
    if (!empty($o['accepted'])) return false;
    $o['accepted'] = true; $o['accepted_at'] = time();
    return true;
}
// the kitchen app / page calls this when staff presses "accept" (stops the alarm there, tells the customer here)
function ghm_rest_accept($req) {
    $p = get_post((int) $req['id']);
    if (!$p || $p->post_type !== 'ghm_order') return new WP_REST_Response(array('ok' => false), 404);
    $o = json_decode($p->post_content, true);
    if (!is_array($o)) return new WP_REST_Response(array('ok' => false), 404);
    $first = ghm_mark_accepted($o);
    if ($first) {
        $o['seen'] = 1;
        wp_update_post(array('ID' => $p->ID, 'post_content' => wp_slash(ghm_json($o))));
        ghm_push_order($p->ID, $o, 'accepted');
    }
    return new WP_REST_Response(array('ok' => true, 'accepted_at' => (int) $o['accepted_at']), 200);
}
// track an order from another device: order number + last 4 digits of the phone given with the order
function ghm_rest_lookup($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'; $k = 'ghm_lkp_' . md5($ip); $n = (int) get_transient($k);
    if ($n >= 10) return new WP_REST_Response(array('ok' => false, 'error' => 'rate'), 429);
    set_transient($k, $n + 1, 60);
    $num = (int) preg_replace('/\D/', '', (string) $req->get_param('num'));
    $ph = substr(preg_replace('/\D/', '', (string) $req->get_param('phone')), -4);
    if ($num < 1 || strlen($ph) < 4) return new WP_REST_Response(array('ok' => false, 'error' => 'input'), 400);
    foreach (get_posts(array('post_type' => 'ghm_order', 'numberposts' => 300, 'orderby' => 'ID', 'order' => 'DESC')) as $p) {
        $o = json_decode($p->post_content, true);
        if (!is_array($o) || (int) $o['num'] !== $num) continue;
        if (substr(preg_replace('/\D/', '', isset($o['phone']) ? $o['phone'] : ''), -4) !== $ph) continue;
        return new WP_REST_Response(array_merge(array('ok' => true, 'pay' => ghm_pay_state($o), 'id' => $p->ID, 'tok' => $o['tok'], 'num' => $o['num'], 'ready' => !empty($o['done']), 'ready_at' => (int) (isset($o['ready_at']) ? $o['ready_at'] : 0),
            'now' => time(), 'mode' => $o['mode'], 'eta' => !empty($o['eta']) && empty($o['prep']) ? $o['eta'] : '', 'lang' => isset($o['lang']) ? $o['lang'] : 'pl', 'seen' => !empty($o['seen']) || !empty($o['accepted']), 'accepted' => !empty($o['accepted'])), ghm_order_summary($o)), 200);
    }
    return new WP_REST_Response(array('ok' => false, 'error' => 'none'), 404);
}
// the customer's browser registers its push subscription for ONE order (order id + secret token, same as /status)
function ghm_rest_push_sub($req) {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'x'; $k = 'ghm_psl_' . md5($ip); $n = (int) get_transient($k);
    if ($n >= 20) return new WP_REST_Response(array('ok' => false), 429);
    set_transient($k, $n + 1, 60);
    $p = get_post((int) $req->get_param('id'));
    if (!$p || $p->post_type !== 'ghm_order') return new WP_REST_Response(array('ok' => false), 404);
    $o = json_decode($p->post_content, true);
    if (!is_array($o) || empty($o['tok']) || !hash_equals((string) $o['tok'], (string) $req->get_param('t'))) return new WP_REST_Response(array('ok' => false), 404);
    $sub = $req->get_param('sub');
    if (!is_array($sub) || empty($sub['endpoint']) || !is_string($sub['endpoint']) || strlen($sub['endpoint']) > 1000 || !ghm_push_host_ok($sub['endpoint'])) return new WP_REST_Response(array('ok' => false, 'error' => 'endpoint'), 400);
    $k1 = isset($sub['keys']['p256dh']) && is_string($sub['keys']['p256dh']) ? $sub['keys']['p256dh'] : '';
    $k2 = isset($sub['keys']['auth']) && is_string($sub['keys']['auth']) ? $sub['keys']['auth'] : '';
    if (strlen(ghm_b64u_d($k1)) !== 65 || strlen(ghm_b64u_d($k2)) < 16) return new WP_REST_Response(array('ok' => false, 'error' => 'keys'), 400);
    $url = esc_url_raw((string) $req->get_param('url'));       // where the notification takes the customer (same site only)
    if ($url !== '' && wp_parse_url($url, PHP_URL_HOST) !== wp_parse_url(home_url(), PHP_URL_HOST)) $url = '';
    update_post_meta($p->ID, '_ghm_push', wp_slash(wp_json_encode(array('endpoint' => $sub['endpoint'], 'keys' => array('p256dh' => $k1, 'auth' => $k2), 'url' => $url))));
    return new WP_REST_Response(array('ok' => true), 200);
}
// the service worker must be served from the site root to be allowed to control it: /?ghm-sw=1
add_action('init', function () {
    if (!isset($_GET['ghm-sw'])) return;
    nocache_headers();
    header('Content-Type: application/javascript; charset=utf-8');
    header('Service-Worker-Allowed: /');
    readfile(GHM_DIR . 'assets/ghm-sw.js');
    exit;
}, 1);

/* ------------------------------------------------------- customers (name, phone, approximate location)
   Orders are deleted after 30 days, so the customer list is kept separately in option ghm_customers (key = phone digits).
   Location = city/region/country looked up from the visitor's IP address AFTER the order was answered (never slows the order);
   the IP itself is not stored, only its masked form (last part removed). */
function ghm_client_ip() {
    foreach (array('HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR') as $h) {
        if (empty($_SERVER[$h])) continue;
        $ip = trim(explode(',', (string) $_SERVER[$h])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '';
}
function ghm_ip_mask($ip) {
    if (strpos($ip, ':') !== false) { $p = explode(':', $ip); return implode(':', array_slice($p, 0, 3)) . ':*'; }
    $p = explode('.', $ip); if (count($p) !== 4) return '';
    return $p[0] . '.' . $p[1] . '.' . $p[2] . '.*';
}
function ghm_geo_lookup($ip) {
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return '';
    $r = wp_remote_get('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,city,region,country_code', array('timeout' => 4));
    if (is_wp_error($r)) return '';
    $j = json_decode(wp_remote_retrieve_body($r), true);
    if (!is_array($j) || empty($j['success'])) return '';
    $parts = array_filter(array(isset($j['city']) ? $j['city'] : '', isset($j['region']) ? $j['region'] : '', isset($j['country_code']) ? $j['country_code'] : ''));
    return substr(sanitize_text_field(implode(', ', $parts)), 0, 80);
}
function ghm_customer_add($pid, $o, $ipOverride = null) {
    $digits = preg_replace('/\D/', '', isset($o['phone']) ? $o['phone'] : '');
    if (strlen($digits) < 7 && trim((string) $o['name']) === '') return;
    $key = strlen($digits) >= 7 ? $digits : 'n' . md5(strtolower($o['name']));
    $list = (array) get_option('ghm_customers', array());
    $c = isset($list[$key]) ? $list[$key] : array('name' => '', 'phone' => '', 'first' => time(), 'n' => 0, 'sum' => 0, 'geo' => '', 'ip' => '', 'src' => '');
    $c['name'] = $o['name'] !== '' ? $o['name'] : $c['name']; $c['phone'] = $o['phone'] !== '' ? $o['phone'] : $c['phone'];
    $c['n'] = (int) $c['n'] + 1; $c['sum'] = round((float) $c['sum'] + (float) $o['total'], 2); $c['last'] = time(); $c['mode'] = $o['mode'];
    $c['src'] = !empty($o['src']) ? $o['src'] : $c['src'];
    $ip = $ipOverride !== null ? $ipOverride : ghm_client_ip(); $c['ip'] = ghm_ip_mask($ip);
    $list[$key] = $c;
    if (count($list) > 5000) { uasort($list, function ($a, $b) { return (isset($b['last']) ? $b['last'] : 0) - (isset($a['last']) ? $a['last'] : 0); }); $list = array_slice($list, 0, 5000, true); }
    update_option('ghm_customers', $list, false);
    add_action('shutdown', function () use ($key, $ip) {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        $g = ghm_geo_lookup($ip); if ($g === '') return;
        $l = (array) get_option('ghm_customers', array());
        if (isset($l[$key])) { $l[$key]['geo'] = $g; update_option('ghm_customers', $l, false); }
    });
}
function ghm_cust_key() {
    $k = get_option('ghm_cust_key', '');
    if (!$k) { $k = wp_generate_password(24, false, false); update_option('ghm_cust_key', $k, false); }
    return $k;
}
function ghm_cust_rows() {
    $l = (array) get_option('ghm_customers', array());
    uasort($l, function ($a, $b) { return (isset($b['last']) ? $b['last'] : 0) - (isset($a['last']) ? $a['last'] : 0); });
    $rows = array();
    foreach ($l as $k => $c) $rows[] = array('k' => (string) $k, 'name' => $c['name'], 'phone' => $c['phone'], 'geo' => isset($c['geo']) ? $c['geo'] : '', 'ip' => isset($c['ip']) ? $c['ip'] : '', 'n' => (int) $c['n'], 'sum' => (float) $c['sum'],
        'first' => wp_date('Y-m-d H:i', (int) $c['first'], new DateTimeZone(GHM_TZ)), 'last' => wp_date('Y-m-d H:i', (int) (isset($c['last']) ? $c['last'] : $c['first']), new DateTimeZone(GHM_TZ)), 'src' => isset($c['src']) ? $c['src'] : '');
    return $rows;
}
function ghm_cust_csv() {
    $f = fopen('php://temp', 'r+');
    fputcsv($f, array('Name', 'Phone', 'Location (from IP)', 'IP (masked)', 'Orders', 'Spent (zl)', 'First order', 'Last order', 'Source'));
    foreach (ghm_cust_rows() as $r) fputcsv($f, array($r['name'], $r['phone'], $r['geo'], $r['ip'], $r['n'], $r['sum'], $r['first'], $r['last'], $r['src']));
    rewind($f); $out = stream_get_contents($f); fclose($f);
    return "\xEF\xBB\xBF" . $out;
}
function ghm_ajax_customers() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    if (!empty($_POST['del'])) { $l = (array) get_option('ghm_customers', array()); unset($l[sanitize_text_field(wp_unslash($_POST['del']))]); update_option('ghm_customers', $l, false); }
    if (!empty($_POST['delall'])) delete_option('ghm_customers');
    wp_send_json_success(array('rows' => ghm_cust_rows(), 'csv' => home_url('/?ghm-customers=' . ghm_cust_key())));
}
add_action('wp_ajax_ghm_customers', 'ghm_ajax_customers');
// secret CSV link (also usable in Google Sheets: =IMPORTDATA("link"))
add_action('init', function () {
    if (!isset($_GET['ghm-customers'])) return;
    if (!hash_equals((string) ghm_cust_key(), (string) $_GET['ghm-customers'])) { status_header(403); exit; }
    nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('X-Robots-Tag: noindex');
    if (isset($_GET['dl'])) header('Content-Disposition: attachment; filename="grill-hub-customers-' . gmdate('Y-m-d') . '.csv"');
    echo ghm_cust_csv(); exit;
}, 1);

/* ------------------------------------------------- alerts when the kitchen page is not open
   WhatsApp text (CallMeBot, free), ntfy push (rings loudly on the phone) and e-mail. Sent when an order arrives and the kitchen page
   has not polled for N seconds, or when an order is still unseen after N seconds (checked by WP-Cron). */
function ghm_alert_text($o) {
    $where = ($o['mode'] === 'dine' ? 'Na miejscu: ' : 'Odbior: ') . $o['name'] . ' ' . $o['phone'] . (!empty($o['eta']) ? ' ' . $o['eta'] : '');
    return "NOWE ZAMOWIENIE #" . $o['num'] . " - " . $o['total'] . " zl\n" . $where . "\nKuchnia nie odpowiada - otworz: " . home_url('/?ghm-kitchen=1');
}
// returns array of "channel: result" strings. $block=true waits for the answers (admin test button)
function ghm_alert_send($text, $a, $block = false) {
    $res = array(); $args = array('timeout' => $block ? 12 : 4, 'blocking' => $block);
    $chk = function ($r) { if (is_wp_error($r)) return 'error: ' . $r->get_error_message(); $c = (int) wp_remote_retrieve_response_code($r); return $c >= 200 && $c < 300 ? 'OK' : 'error ' . $c . ' ' . substr(wp_strip_all_tags((string) wp_remote_retrieve_body($r)), 0, 120); };
    if ($a['wa_phone'] !== '' && $a['wa_key'] !== '') {
        $r = wp_remote_get('https://api.callmebot.com/whatsapp.php?phone=' . rawurlencode($a['wa_phone']) . '&text=' . rawurlencode($text) . '&apikey=' . rawurlencode($a['wa_key']), $args);
        $res[] = 'WhatsApp: ' . ($block ? $chk($r) : 'sent');
    }
    if ($a['ntfy_topic'] !== '') {
        $r = wp_remote_post(rtrim($a['ntfy_server'], '/') . '/' . rawurlencode($a['ntfy_topic']), array_merge($args, array('body' => $text, 'headers' => array('Title' => ghm_brand() . ' - new order', 'Priority' => 'urgent', 'Tags' => 'rotating_light', 'Click' => home_url('/?ghm-kitchen=1')))));
        $res[] = 'ntfy: ' . ($block ? $chk($r) : 'sent');
    }
    if ($a['email'] !== '') {
        $ok = wp_mail($a['email'], ghm_brand() . ' - nowe zamowienie (kuchnia offline)', $text);
        $res[] = 'E-mail: ' . ($ok ? 'OK' : 'error');
    }
    return $res;
}
function ghm_alert_new_order($pid, $o) {
    $d = ghm_get_data(); $a = $d['settings']['alert'];
    if ($a['mode'] === 'off' || ($a['wa_phone'] === '' && $a['ntfy_topic'] === '' && $a['email'] === '')) return;
    $offline = time() - (int) get_option('ghm_k_seen', 0) > (int) $a['offline_s'];
    if ($a['mode'] === 'always' || $offline) ghm_alert_mark($pid, $o, $a);
    elseif (function_exists('wp_schedule_single_event')) wp_schedule_single_event(time() + max(60, (int) $a['offline_s']), 'ghm_alert_check', array($pid)); // kitchen looked alive: re-check later
}
function ghm_alert_mark($pid, $o, $a) {
    $o['alerted'] = 1;
    wp_update_post(array('ID' => $pid, 'post_content' => wp_slash(ghm_json($o))));
    ghm_alert_send(ghm_alert_text($o), $a, false);
}
add_action('ghm_alert_check', function ($pid) {
    $p = get_post((int) $pid); if (!$p || $p->post_type !== 'ghm_order') return;
    $o = json_decode($p->post_content, true); if (!is_array($o) || !empty($o['seen']) || !empty($o['alerted']) || !empty($o['done'])) return;
    $d = ghm_get_data(); ghm_alert_mark($p->ID, $o, $d['settings']['alert']);
});
function ghm_ajax_alert_test() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    $d = ghm_get_data(); $a = $d['settings']['alert'];
    if ($a['wa_phone'] === '' && $a['ntfy_topic'] === '' && $a['email'] === '') wp_send_json_error('Nothing to test: save a WhatsApp / ntfy / e-mail setting first.');
    wp_send_json_success(ghm_alert_send('TEST - ' . ghm_brand() . ': alerts work. ' . home_url('/?ghm-kitchen=1'), $a, true));
}
add_action('wp_ajax_ghm_alert_test', 'ghm_ajax_alert_test');

/* ------------------------------------------------------- Loyalty tab (admin) */
function ghm_ajax_ls() {
    if (!current_user_can('manage_options')) wp_send_json_error('forbidden', 403);
    check_ajax_referer('ghm_admin', 'nonce');
    if (!function_exists('ls_setting')) wp_send_json_error('The separate "Loyalty Stamps" plugin is active - deactivate it first.');
    $do = isset($_POST['do']) ? sanitize_key($_POST['do']) : '';
    if ($do === 'pages') ls_create_pages();
    if ($do === 'pin') ls_regenerate_staff_pin();
    if ($do === 'setpin') {
        $r = ls_set_staff_pin(isset($_POST['pin']) ? wp_unslash($_POST['pin']) : '');
        if (is_wp_error($r)) wp_send_json_error($r->get_error_message());
    }
    $pages = array();
    foreach (ls_page_shortcodes() as $sc) { delete_transient('ls_page_' . md5($sc)); $pages[$sc] = ls_url_of($sc); }
    $st = function_exists('ls_stats_overview') ? ls_stats_overview() : array();
    if (!$st) { global $wpdb; $p = $wpdb->prefix; $st = array('customers' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ls_customers"), 'active_cards' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ls_cards WHERE status = 'active'"), 'stamps_today' => 0, 'rewards_issued' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$p}ls_rewards")); }
    wp_send_json_success(array('pages' => $pages, 'pin' => (string) get_option('ls_staff_pin_plain', ''), 'st' => $st,
        'admin' => array('loyalty' => admin_url('admin.php?page=loyalty'), 'programs' => admin_url('admin.php?page=loyalty-programs'), 'customers' => admin_url('admin.php?page=loyalty-customers'), 'cards' => admin_url('admin.php?page=loyalty-cards'), 'transactions' => admin_url('admin.php?page=loyalty-transactions'), 'rewards' => admin_url('admin.php?page=loyalty-rewards'),
            'stores' => admin_url('admin.php?page=loyalty-stores'), 'notifications' => admin_url('admin.php?page=loyalty-notifications'), 'wallet' => admin_url('admin.php?page=loyalty-wallet'), 'settings' => admin_url('admin.php?page=loyalty-settings')),
        'theme' => ls_setting('theme', 'light')));
}
add_action('wp_ajax_ghm_ls', 'ghm_ajax_ls');

/* ------------------------------------------------------- Google reviews (home page) */
function ghm_reviews_public($s) {
    $r = $s['reviews'];
    if (empty($r['show'])) return null;
    $out = array('rating' => $r['rating'], 'count' => $r['count'], 'url' => $r['url'], 'items' => $r['items']);
    if ($r['place_id'] !== '' && $r['api_key'] !== '') { // optional automatic copy from Google Places, cached 12 h
        $g = get_transient('ghm_greviews');
        if (!is_array($g) || (isset($g['pid']) && $g['pid'] !== $r['place_id'])) {
            $g = array('pid' => $r['place_id'], 'ok' => false);
            $resp = wp_remote_get('https://places.googleapis.com/v1/places/' . rawurlencode($r['place_id']), array('timeout' => 4, 'headers' => array('X-Goog-Api-Key' => $r['api_key'], 'X-Goog-FieldMask' => 'rating,userRatingCount,reviews', 'Accept-Language' => 'pl')));
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $j = json_decode(wp_remote_retrieve_body($resp), true);
                if (is_array($j)) {
                    $g['ok'] = true; $g['rating'] = isset($j['rating']) ? (string) round((float) $j['rating'], 1) : ''; $g['count'] = isset($j['userRatingCount']) ? (string) (int) $j['userRatingCount'] : ''; $g['items'] = array();
                    foreach ((isset($j['reviews']) && is_array($j['reviews']) ? $j['reviews'] : array()) as $x) {
                        $t = isset($x['text']['text']) ? $x['text']['text'] : (isset($x['originalText']['text']) ? $x['originalText']['text'] : '');
                        $n = isset($x['authorAttribution']['displayName']) ? $x['authorAttribution']['displayName'] : '';
                        if ($t === '' || $n === '') continue;
                        $g['items'][] = array('name' => ghm_cut(sanitize_text_field($n), 40), 'text' => ghm_cut(sanitize_textarea_field($t), 400), 'stars' => max(1, min(5, (int) (isset($x['rating']) ? $x['rating'] : 5))));
                    }
                }
            }
            set_transient('ghm_greviews', $g, $g['ok'] ? 12 * 3600 : 3600);
        }
        if (!empty($g['ok'])) { if ($g['rating'] !== '') $out['rating'] = $g['rating']; if ($g['count'] !== '') $out['count'] = $g['count']; if (!empty($g['items'])) $out['items'] = $g['items']; }
    }
    if ($out['rating'] === '' && !$out['items']) return null;
    return $out;
}

/* ------------------------------------------------------- kitchen page */
add_action('init', function () {
    if (!isset($_GET['ghm-kitchen']) || is_admin()) return;
    $d = ghm_get_data();
    nocache_headers();
    header('Content-Type: text/html; charset=utf-8');
    $cfg = array('rest' => esc_url_raw(rest_url('ghm/v1/')), 'receipt' => $d['settings']['receipt'], 'brand' => $d['settings']['brand'], 'hasPin' => $d['settings']['kitchen_pin'] !== '', 'on' => !empty($d['settings']['orders']));
    ?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Kitchen</title>
<?php echo ghm_icons_html(); ?>
<link rel="stylesheet" href="<?php echo esc_url(GHM_URL . 'assets/kitchen.css?ver=' . GHM_VER); ?>"></head>
<body><div id="k"></div>
<script>window.GHM_K=<?php echo ghm_json($cfg); ?>;</script>
<script src="<?php echo esc_url(GHM_URL . 'assets/kitchen.js?ver=' . GHM_VER); ?>"></script>
</body></html>
<?php
    exit;
});
