(function () {
  "use strict";
  var K = window.GHM_K, R = Object.assign({}, K.receipt), root = document.getElementById("k");
  var LS = { get: function (k, d) { try { var v = localStorage.getItem(k); return v == null ? d : v; } catch (e) { return d; } }, set: function (k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
  var pin = LS.get("ghk-pin", ""), orders = [], lastId = 0, queue = [], printing = false, audio = null, polling = null, fresh = {}, ring = {}, ringT = null, fails = 0, offline = false, srvOff = 0;
  var opt = { auto: true, sound: true, method: "rawbt" };
  function h(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function money(n) { n = Math.round(n * 100) / 100; return (n % 1 === 0 ? String(n) : n.toFixed(2)); }

  /* ---------- API ---------- */
  function api(path, method) {
    return fetch(K.rest + path, { method: method || "GET", headers: { "X-GHM-PIN": pin, "Content-Type": "application/json" }, cache: "no-store" }).then(function (r) { if (r.status === 401 || r.status === 403) throw new Error("auth"); return r.json(); });
  }

  /* ---------- Sound / wake lock ---------- */
  function beep() {
    if (!opt.sound) return;
    try {
      audio = audio || new (window.AudioContext || window.webkitAudioContext)();
      [0, 0.25, 0.5].forEach(function (t) {
        var o = audio.createOscillator(), g = audio.createGain(); o.type = "square"; o.frequency.value = 880;
        g.gain.setValueAtTime(0.0001, audio.currentTime + t); g.gain.exponentialRampToValueAtTime(0.4, audio.currentTime + t + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, audio.currentTime + t + 0.2);
        o.connect(g); g.connect(audio.destination); o.start(audio.currentTime + t); o.stop(audio.currentTime + t + 0.22);
      });
    } catch (e) {}
    try { if (navigator.vibrate) navigator.vibrate([200, 100, 200]); } catch (e) {}
  }
  function keepAwake() { try { if (navigator.wakeLock) navigator.wakeLock.request("screen").catch(function () {}); } catch (e) {} }
  document.addEventListener("visibilitychange", function () { if (!document.hidden) keepAwake(); });

  /* ---------- ESC/POS receipt ---------- */
  var MAP = { "ą": "a", "ć": "c", "ę": "e", "ł": "l", "ń": "n", "ó": "o", "ś": "s", "ź": "z", "ż": "z", "Ą": "A", "Ć": "C", "Ę": "E", "Ł": "L", "Ń": "N", "Ó": "O", "Ś": "S", "Ź": "Z", "Ż": "Z", "×": "x", "—": "-", "–": "-", "·": "-", "↳": ">", "’": "'", "„": '"', "”": '"' };
  function ascii(s) { return String(s).replace(/[^\x20-\x7E\n]/g, function (c) { return MAP[c] || "?"; }); }
  var SAVED = {}; try { SAVED = JSON.parse(localStorage.getItem("ghk-set") || "{}"); } catch (e) {}
  Object.keys(SAVED).forEach(function (k) { R[k] = SAVED[k]; });
  var COLS = 48;
  function applyCfg() { COLS = String(R.paper) === "58" ? 32 : 48; opt.method = R.method; opt.auto = !!R.auto; opt.sound = !!R.sound; }
  function saveCfg(k, v) { R[k] = v; SAVED[k] = v; LS.set("ghk-set", JSON.stringify(SAVED)); applyCfg(); }
  function wrap(s, w) {
    var out = [], line = "";
    ascii(s).split(" ").forEach(function (word) {
      while (word.length > w) { if (line) { out.push(line); line = ""; } out.push(word.slice(0, w)); word = word.slice(w); }
      if ((line ? line.length + 1 : 0) + word.length > w) { out.push(line); line = word; } else line = line ? line + " " + word : word;
    });
    if (line) out.push(line);
    return out;
  }
  function two(l, r, w) { l = ascii(l); r = ascii(r); var sp = w - l.length - r.length; return sp >= 1 ? l + new Array(sp + 1).join(" ") + r : l + "\n" + new Array(w - r.length + 1).join(" ") + r; }
  function dt(o) { var d = new Date((o.ts || 0) * 1000); function p(n) { return (n < 10 ? "0" : "") + n; } return p(d.getDate()) + "." + p(d.getMonth() + 1) + "." + d.getFullYear() + " " + p(d.getHours()) + ":" + p(d.getMinutes()); }

  // Returns the receipt as a list of {t:text, a:align, b:bold, s:size} blocks (shared by ESC/POS and browser print)
  function receiptBlocks(o) {
    var B = [], dash = new Array(COLS + 1).join("-");
    function add(t, a, b, s) { B.push({ t: t, a: a || "l", b: !!b, s: s || 1 }); }
    if (R.header) add(R.header, "c", true, 2);
    add(dt(o), "c");
    add(dash);
    add(R.big_number ? "ZAMOWIENIE #" + o.num : "Zamowienie #" + o.num, "c", true, R.big_number ? 2 : 1);
    {
      add(o.mode === "dine" ? "NA MIEJSCU" : "ODBIOR OSOBISTY", "c", true, 2);
      if (o.name) add("Imie: " + o.name, "l", true);
      if (o.phone && R.show_phone) add("Tel: " + o.phone);
      if (o.eta) add("Godzina odbioru: " + o.eta, "l", true);
    }
    add(dash);
    o.lines.forEach(function (l) {
      var left = l.qty + " x " + l.name, right = R.show_prices ? money(l.price * l.qty) : "";
      if (right) add(two(left.length > COLS - right.length - 1 ? left.slice(0, COLS - right.length - 1) : left, right, COLS), "l", true);
      else wrap(left, COLS).forEach(function (x) { add(x, "l", true); });
      if (right && left.length > COLS - right.length - 1) wrap("  " + left.slice(COLS - right.length - 1), COLS).forEach(function (x) { add(x); });
      if (l.desc) wrap("  > " + l.desc, COLS).forEach(function (x) { add(x); });
    });
    add(dash);
    if (R.show_prices && (o.deposit || o.pack)) {
      if (o.deposit) add(two("Kaucja za napoje", "+" + money(o.deposit), COLS));
      if (o.pack) add(two("Opakowanie na wynos", "+" + money(o.pack), COLS));
    }
    if (R.show_prices && (o.combo || o.coupon)) {
      if (o.combo) add(two("Zestaw wrap+napoj", "-" + money(o.combo), COLS));
      if (o.coupon) add(two("Kod " + (o.code || ""), "-" + money(o.coupon), COLS));
    }
    if (R.show_prices) add(two("RAZEM:", money(o.total) + " zl", COLS / 2) , "r", true, 2);
    if (o.notes) { add(""); add("UWAGI:", "l", true); wrap(o.notes, COLS).forEach(function (x) { add(x); }); }
    if (R.footer) { add(""); add(R.footer, "c"); }
    return B;
  }
  function escpos(o) {
    var b = [], enc = function (s) { for (var i = 0; i < s.length; i++) b.push(s.charCodeAt(i) & 0xFF); };
    for (var c = 0; c < (R.copies || 1); c++) {
      b.push(0x1B, 0x40);
      receiptBlocks(o).forEach(function (x) {
        b.push(0x1B, 0x61, x.a === "c" ? 1 : x.a === "r" ? 2 : 0);
        b.push(0x1B, 0x45, x.b ? 1 : 0);
        b.push(0x1D, 0x21, x.s === 2 ? 0x11 : 0);
        var w = x.s === 2 ? Math.floor(COLS / 2) : COLS;
        var t = ascii(x.t);
        if (x.s === 2 && t.length > w && t.indexOf("\n") < 0) { wrap(t, w).forEach(function (ln) { enc(ln); b.push(0x0A); }); return; }
        enc(t); b.push(0x0A);
      });
      b.push(0x1B, 0x45, 0, 0x1D, 0x21, 0, 0x1B, 0x61, 0);
      b.push(0x0A, 0x0A, 0x0A, 0x0A);
      if (R.cut) b.push(0x1D, 0x56, 0x42, 0x00);
    }
    return b;
  }
  function b64(bytes) { var s = ""; for (var i = 0; i < bytes.length; i += 8192) s += String.fromCharCode.apply(null, bytes.slice(i, i + 8192)); return btoa(s); }
  function browserPrint(o) {
    var f = document.createElement("iframe"); f.style.cssText = "position:fixed;right:0;bottom:0;width:0;height:0;border:0";
    document.body.appendChild(f);
    var d = f.contentDocument, w = R.paper === "58" ? "58mm" : "80mm", out = "";
    for (var c = 0; c < (R.copies || 1); c++) out += '<div style="page-break-after:always">' + receiptBlocks(o).map(function (x) { return '<div style="white-space:pre-wrap;text-align:' + (x.a === "c" ? "center" : x.a === "r" ? "right" : "left") + ";font-weight:" + (x.b ? 800 : 400) + ";font-size:" + (x.s === 2 ? "18px" : "12px") + '">' + h(ascii(x.t)) + "</div>"; }).join("") + "</div>";
    d.open(); d.write('<!doctype html><meta charset="utf-8"><style>@page{size:' + w + ' auto;margin:0}body{margin:0;width:' + w + ';font-family:monospace;padding:2mm}</style>' + out); d.close();
    setTimeout(function () { try { f.contentWindow.focus(); f.contentWindow.print(); } catch (e) {} setTimeout(function () { f.remove(); }, 4000); }, 150);
  }
  function printOne(o) {
    if (opt.method === "browser") browserPrint(o);
    else location.href = "rawbt:base64," + b64(escpos(o));
  }
  function pump() {
    if (printing || !queue.length) return;
    printing = true; printOne(queue.shift());
    setTimeout(function () { printing = false; pump(); }, 2500);
  }
  function enqueue(o) { queue.push(o); pump(); }

  /* ---------- UI ---------- */
  var tab = LS.get("ghk-tab", "orders"), q = "";
  function ts(o) { return (o.ts || 0) * 1000; }
  function etaMs(o) { if (o.mode !== "pick" || !o.eta) return 0; var m = /^(\d{1,2}):(\d{2})/.exec(o.eta); if (!m) return 0; var d = new Date(ts(o)); d.setHours(+m[1], +m[2], 0, 0); if (d.getTime() < ts(o) - 3600000) d.setDate(d.getDate() + 1); return d.getTime(); }
  function isSched(o) { var e = etaMs(o); return !o.done && e && e - Date.now() > 30 * 60000; }
  function inMin(ms) { var m = Math.round((ms - Date.now()) / 60000); if (m < 60) return m + " min"; return Math.floor(m / 60) + " h " + (m % 60) + " min"; }
  function sets() {
    var act = orders.filter(function (o) { return !o.done && !isSched(o); }), sch = orders.filter(isSched).sort(function (a, b) { return etaMs(a) - etaMs(b); }),
      his = orders.filter(function (o) { return o.done; }).reverse();
    act.reverse(); return { act: act, sch: sch, his: his };
  }
  function prepRow(o) {
    if (o.done || !o.ready_at || (o.mode === "pick" && o.eta)) return "";
    var m = Math.ceil((o.ready_at * 1000 - (Date.now() + srvOff)) / 60000);
    return '<div class="prep"><span>' + (m > 0 ? "Obiecane: za " + m + " min" : "Obiecany czas minął") + "</span>" + [5, 10, 15, 20, 30].map(function (x) { return '<button data-prep="' + o.id + "|" + x + '">' + x + "′</button>"; }).join("") + "</div>";
  }
  function card(o, kind) {
    var when = kind === "sch" ? '<div class="when">Odbiór za ' + inMin(etaMs(o)) + " (" + h(o.eta) + ")</div>" : "";
    return '<div class="o' + (fresh[o.id] ? " new" : "") + (o.done ? " done" : "") + '" data-id="' + o.id + '"><div class="n">#' + o.num + (o.src && o.src !== "direct" ? ' <span class="src">' + h(o.src) + "</span>" : "") + '</div>' + when + prepRow(o) + '<div class="m"><span>' + h(dt(o)) + '</span><span>' + (o.mode === "dine" ? "Na miejscu" : "Odbiór") + "</span></div>" +
      '<div class="t">' + ((o.mode === "dine" ? "🍽 " : "") + h(o.name) + " · " + h(o.phone) + (o.eta ? " · " + h(o.eta) : "")) + "</div><ul>" +
      o.lines.map(function (l) { return "<li><b>" + l.qty + "×</b>" + h(l.name) + (l.desc ? "<small>" + h(l.desc) + "</small>" : "") + "</li>"; }).join("") + "</ul>" +
      (o.notes ? '<div class="nt">' + h(o.notes) + "</div>" : "") + ((o.deposit || o.pack) ? '<div class="fee">' + (o.deposit ? "Kaucja +" + money(o.deposit) + " zł " : "") + (o.pack ? "Opakowanie +" + money(o.pack) + " zł" : "") + "</div>" : "") + ((o.combo || o.coupon) ? '<div class="dsc">' + (o.combo ? "Zestaw −" + money(o.combo) + " zł " : "") + (o.coupon ? "Kod " + h(o.code) + " −" + money(o.coupon) + " zł" : "") + "</div>" : "") + '<div class="tot">' + money(o.total) + " zł</div>" +
      '<div class="act"><button class="p" data-print="' + o.id + '">🖨 Drukuj</button>' + (o.done ? '<button class="u" data-undone="' + o.id + '">↩ Przywróć</button>' : '<button class="d" data-done="' + o.id + '">✓ Gotowe</button>') + "</div></div>";
  }
  function ck(k, label) { return '<label class="row"><input type="checkbox" data-set="' + k + '"' + (R[k] ? " checked" : "") + "> " + label + "</label>"; }
  function settingsView() {
    return '<div class="set"><h3>Drukowanie paragonów</h3>' +
      '<label class="row">Szerokość papieru <select data-set="paper"><option value="80"' + (String(R.paper) === "80" ? " selected" : "") + '>80 mm</option><option value="58"' + (String(R.paper) === "58" ? " selected" : "") + ">58 mm</option></select></label>" +
      '<label class="row">Metoda druku <select data-set="method"><option value="rawbt"' + (R.method === "rawbt" ? " selected" : "") + '>RawBT (Bluetooth / USB, Android)</option><option value="browser"' + (R.method === "browser" ? " selected" : "") + ">Drukarka przeglądarki</option></select></label>" +
      '<label class="row">Nagłówek <input type="text" data-set="header" value="' + h(R.header) + '"></label><label class="row">Stopka <input type="text" data-set="footer" value="' + h(R.footer) + '"></label>' +
      '<label class="row">Liczba kopii <input type="number" min="1" max="5" data-set="copies" value="' + h(R.copies) + '"></label>' +
      ck("auto", "Drukuj automatycznie nowe zamówienia") + ck("cut", "Obetnij papier po wydruku") + ck("sound", "Dźwięk nowego zamówienia") + ck("show_prices", "Pokaż ceny i sumę") + ck("show_phone", "Pokaż telefon klienta") + ck("big_number", "Duży numer zamówienia") +
      '<div class="act2"><button id="ot">🖨 Test wydruku</button><button id="oreset">Przywróć ustawienia serwera</button></div>' +
      '<h3>Urządzenie</h3><p class="hint">Te ustawienia są zapamiętane tylko na tym urządzeniu. Ustawienia domyślne zmieniasz we wtyczce (Grill Hub → Settings).</p><div class="act2"><button id="ox">Wyloguj</button></div></div>';
  }
  function view(status) {
    var S = sets(), body;
    if (tab === "settings") body = settingsView();
    else {
      var list = tab === "orders" ? S.act : tab === "sched" ? S.sch : S.his, kind = tab === "sched" ? "sch" : "";
      var extra = "";
      if (tab === "history") {
        var now = new Date(), t0 = new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime(), cnt = 0, sum = 0;
        orders.forEach(function (o) { if (ts(o) >= t0) { cnt++; sum += o.total; } });
        extra = '<div class="sum">Dzisiaj: <b>' + cnt + "</b> zamówień · <b>" + money(sum) + " zł</b></div>" + '<input id="oq" type="search" placeholder="Szukaj (numer, imię, stolik…)" value="' + h(q) + '">';
        if (q) list = list.filter(function (o) { return (o.num + " " + o.name + " " + o.table + " " + o.phone).toLowerCase().indexOf(q.toLowerCase()) > -1; });
      }
      body = extra + '<div class="list">' + (list.length ? list.map(function (o) { return card(o, kind); }).join("") : '<div class="empty" style="grid-column:1/-1">' + (tab === "orders" ? "Czekamy na zamówienia…" : tab === "sched" ? "Brak zamówień zaplanowanych." : "Brak historii.") + "</div>") + "</div>";
    }
    var T = [["orders", "Orders", S.act.length], ["sched", "Scheduled orders", S.sch.length], ["history", "Order history", 0], ["settings", "Settings", 0]];
    var qs = document.getElementById("oq"), keep = qs && document.activeElement === qs;
    var rn = Object.keys(ring).length, nums = orders.filter(function (x) { return ring[x.id]; }).map(function (x) { return "#" + x.num; }).join(", ");
    root.innerHTML = (offline ? '<div class="offb">⚠ Brak połączenia z serwerem — nowe zamówienia mogą nie dochodzić!</div>' : "") + (rn ? '<button class="ringb" data-ack="1">🔔 NOWE ZAMÓWIENIE ' + h(nums) + ' — dotknij, aby wyciszyć</button>' : "") + '<div class="kb"><b>' + h(K.brand) + ' · Kuchnia</b><span><i class="dot ' + status + '"></i></span></div><div class="tabs">' +
      T.map(function (t) { return '<button class="tb' + (tab === t[0] ? " on" : "") + '" data-tab="' + t[0] + '">' + t[1] + (t[2] ? " <i>" + t[2] + "</i>" : "") + "</button>"; }).join("") + "</div><main>" + body + "</main>";
    if (keep) { var n = document.getElementById("oq"); n.focus(); n.setSelectionRange(q.length, q.length); }
  }
  function gate(err) {
    root.innerHTML = '<div class="gate"><div><b style="font-size:22px">' + h(K.brand) + ' · Kuchnia</b>' + (K.hasPin ? '<input id="pin" type="password" inputmode="text" autocomplete="off" placeholder="PIN"><button id="go">Wejdź</button>' : '<span class="e">Ustaw PIN kuchni w ustawieniach wtyczki.</span>') + '<span class="e">' + h(err || "") + "</span></div></div>";
  }
  var status = "ok", pollN = 0;
  function merge(list, initial) {
    var changed = false;
    list.forEach(function (o) {
      var ex = orders.filter(function (x) { return x.id === o.id; })[0];
      if (ex) { if (ex.done !== o.done) { ex.done = o.done; changed = true; delete ring[o.id]; } if (ex.ready_at !== o.ready_at) { ex.ready_at = o.ready_at; changed = true; } return; }
      orders.push(o); changed = true; lastId = Math.max(lastId, o.id);
      if (!initial) { fresh[o.id] = 1; ring[o.id] = 1; startRing(); if (opt.auto) enqueue(o); }
    });
    orders.sort(function (a, b) { return a.id - b.id; });
    return changed;
  }
  function poll(initial) {
    var full = initial || (++pollN % 5 === 0);
    return api("orders?after=" + (full ? 0 : lastId)).then(function (j) {
      status = "ok"; fails = 0; if (j.now) srvOff = j.now * 1000 - Date.now(); var ch = merge(j.orders || [], initial), wasOff = offline; offline = false;
      if (initial || ch || wasOff) view(status); else { var d = root.querySelector(".dot"); if (d) d.className = "dot ok"; }
    }).catch(function (e) {
      if (e.message === "auth") { clearInterval(polling); LS.set("ghk-pin", ""); pin = ""; gate("Zły PIN."); }
      else { status = "bad"; fails++; var d = root.querySelector(".dot"); if (d) d.className = "dot bad"; if (fails >= 3 && !offline) { offline = true; view(status); beep(); } }
    });
  }
  function startRing() {
    beep(); clearInterval(ringT);
    ringT = setInterval(function () {
      orders.forEach(function (o) { if (o.done) delete ring[o.id]; });
      if (!Object.keys(ring).length) { clearInterval(ringT); return; }
      beep(); try { document.title = (document.title.indexOf("🔔") === 0 ? "" : "🔔 ") + "Kitchen"; } catch (e) {}
    }, 6000);
    view(status);
  }
  function start() { orders = []; lastId = 0; poll(true).then(function () { if (pin) { keepAwake(); clearInterval(polling); polling = setInterval(function () { poll(false); }, 4000); } }); }
  setInterval(function () { if (pin && tab !== "settings" && document.activeElement && document.activeElement.id !== "oq") view(status); }, 30000);

  function testOrder() { return { num: 0, ts: Date.now() / 1000, mode: "dine", name: "Test", phone: "+48 500 000 000", lines: [{ name: "Test wydruku ąęść", qty: 1, price: 10, desc: "" }], total: 10, notes: "Test" }; }
  root.addEventListener("click", function (e) {
    var t = e.target.closest("button"); if (!t) return;
    if (t.dataset.ack) { ring = {}; clearInterval(ringT); try { document.title = "Kitchen"; } catch (x) {} return view(status); }
    if (t.dataset.prep) { var pp = t.dataset.prep.split("|"); api("orders/" + pp[0] + "/prep?min=" + pp[1], "POST").then(function (r) { orders.forEach(function (x) { if (x.id == pp[0] && r.ready_at) x.ready_at = r.ready_at; }); view(status); }); return; }
    if (t.id === "go") { pin = document.getElementById("pin").value.trim(); LS.set("ghk-pin", pin); try { audio = audio || new (window.AudioContext || window.webkitAudioContext)(); } catch (x) {} return start(); }
    if (t.id === "ox") { LS.set("ghk-pin", ""); pin = ""; clearInterval(polling); return gate(""); }
    if (t.id === "ot") return printOne(testOrder());
    if (t.id === "oreset") { SAVED = {}; LS.set("ghk-set", "{}"); R = Object.assign({}, K.receipt); applyCfg(); return view(status); }
    if (t.dataset.tab) { tab = t.dataset.tab; LS.set("ghk-tab", tab); return view(status); }
    if (t.dataset.print) { var o = orders.filter(function (x) { return x.id == t.dataset.print; })[0]; if (o) printOne(o); return; }
    if (t.dataset.done) { var id = t.dataset.done; delete ring[id]; api("orders/" + id + "/done", "POST").then(function () { orders.forEach(function (x) { if (x.id == id) x.done = true; }); view(status); }); }
    if (t.dataset.undone) { var id2 = t.dataset.undone; api("orders/" + id2 + "/done?undo=1", "POST").then(function () { orders.forEach(function (x) { if (x.id == id2) x.done = false; }); view(status); }); }
  });
  root.addEventListener("change", function (e) {
    var t = e.target, k = t.getAttribute("data-set"); if (!k) return;
    var v = t.type === "checkbox" ? t.checked : t.type === "number" ? Math.max(1, Math.min(5, +t.value || 1)) : t.value;
    saveCfg(k, v); if (k === "sound" && v) beep(); if (k === "paper" || k === "method") view(status);
  });
  root.addEventListener("input", function (e) {
    var t = e.target;
    if (t.id === "oq") { q = t.value; view(status); }
    else if (t.getAttribute("data-set") && t.type === "text") saveCfg(t.getAttribute("data-set"), t.value);
  });
  root.addEventListener("keydown", function (e) { if (e.key === "Enter" && e.target.id === "pin") document.getElementById("go").click(); });
  applyCfg();
  window.__ghk = { escpos: escpos, blocks: receiptBlocks };
  if (pin) start(); else gate("");
})();
