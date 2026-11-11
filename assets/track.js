/* Grill Hub – standalone order tracking page (/track/): big countdown, steps, marketing cards, "ready" alarm. State lives in localStorage ("gh-ord"), shared with the header bar (GHS.ord). */
(function () {
  "use strict";
  var C = window.GHM_TRACK || {}, G = window.GHS, O = G.ord, STR = window.GHM_STR || {}, root = document.getElementById("tr");
  var SOCN = { facebook: "Facebook", instagram: "Instagram", tiktok: "TikTok" }, CIRC = 2 * Math.PI * 54, ord = null, fin = false, built = "", sumKey = "", LANG = "pl";
  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function L(k) { var d = STR[LANG] || STR.pl || {}; return d[k] != null ? d[k] : ((STR.pl || {})[k] || k); }
  function fmt(s, o) { return String(s).replace(/\{(\w+)\}/g, function (m, k) { return o[k] != null ? o[k] : m; }); }
  function pickLang() {
    var l = ""; try { l = localStorage.getItem("gh-lang"); } catch (e) {}
    if (!STR[l]) l = ord && STR[ord.lang] ? ord.lang : (STR[C.lang] ? C.lang : "pl");
    LANG = l; document.documentElement.lang = l; document.documentElement.dir = l === "ar" ? "rtl" : "ltr";
  }
  function toast(t) { var e = document.createElement("div"); e.className = "toast"; e.textContent = t; document.body.appendChild(e); setTimeout(function () { e.remove(); }, 2200); }
  function promos(finished) {
    var W = C.wait || {}, h = "";
    if (!finished && W.next && W.next.code) h += '<div class="wp wp-code"><b>' + esc(L("wait_next_t")) + "</b><p>" + esc((W.next.text && (W.next.text[LANG] || W.next.text.pl)) || "") + '</p><button type="button" class="wp-copy" data-copy="' + esc(W.next.code) + '"><span class="num">' + esc(W.next.code) + "</span> · " + esc(L("wait_copy")) + "</button></div>";
    if (W.rewards) h += '<a class="wp" href="' + esc(W.rewards) + '" target="_blank" rel="noopener"><b>🎁 ' + esc(L("wait_rewards")) + "</b><p>" + esc(L("wait_rewards_s")) + "</p></a>";
    if (W.review) h += '<a class="wp" href="' + esc(W.review) + '" target="_blank" rel="noopener"><b>⭐ ' + esc(L("wait_review")) + "</b></a>";
    if (W.social && W.social.length) h += '<div class="wp"><b>' + esc(L("wait_follow")) + '</b><div class="wp-soc">' + W.social.map(function (x) { return '<a href="' + esc(x.url) + '" target="_blank" rel="noopener">' + esc(SOCN[x.id] || x.id) + "</a>"; }).join("") + "</div></div>";
    return h;
  }
  function money(n) { n = Math.round(n * 100) / 100; return (n % 1 === 0 ? String(n) : n.toFixed(2).replace(".", ",")) + " zł"; }
  function sumHtml() { // the order itself, on the waiting page: lines + total, with discount/fees rows only when they apply
    var h = '<div class="ws-t">' + esc(L("tr_sum_t")) + "</div>";
    ord.lines.forEach(function (l) { h += '<div class="ws-l"><span><b class="num">' + l.q + " ×</b> " + esc(l.n) + (l.d ? "<em>" + esc(l.d) + "</em>" : "") + '</span><b class="num">' + money(l.q * l.p) + "</b></div>"; });
    var disc = (ord.combo || 0) + (ord.coupon || 0);
    if (disc > 0) h += '<div class="ws-l ws-d"><span>' + esc(L("tr_disc")) + '</span><b class="num">−' + money(disc) + "</b></div>";
    if (ord.dep > 0) h += '<div class="ws-l"><span>' + esc(L("fee_dep")) + '</span><b class="num">+' + money(ord.dep) + "</b></div>";
    if (ord.pack > 0) h += '<div class="ws-l"><span>' + esc(L("fee_pack")) + '</span><b class="num">+' + money(ord.pack) + "</b></div>";
    h += '<div class="ws-tot"><span>' + esc(L("total")) + '</span><b class="num">' + money(ord.sum || 0) + "</b></div>";
    return h;
  }
  function top() { // the shared header lives in the page itself (PHP); here we only keep it in the right language
    var h = document.querySelector(".gh-hdr"); if (!h) return "";
    h.setAttribute("dir", LANG === "ar" ? "rtl" : "ltr");
    var c = h.querySelector(".gh-lcode"); if (c) c.textContent = LANG.toUpperCase();
    Array.prototype.forEach.call(h.querySelectorAll("[data-lang]"), function (b) { b.setAttribute("aria-current", b.getAttribute("data-lang") === LANG ? "true" : "false"); });
    return "";
  }
  function findForm(open) {
    return '<details class="w-find"' + (open ? " open" : "") + "><summary>" + esc(L("tr_find_t")) + '</summary><form id="tFind" novalidate><p>' + esc(L("tr_find_s")) + '</p><label>' + esc(L("tr_find_num")) + '<input id="tNum" inputmode="numeric" autocomplete="off" placeholder="12"></label><label>' + esc(L("tr_find_ph")) + '<input id="tPh" inputmode="numeric" maxlength="4" autocomplete="off" placeholder="1234"></label><div class="w-err" id="tErr"></div><button type="submit" class="w-go">' + esc(L("tr_find_go")) + "</button></form></details>";
  }
  function build() { // skeleton: rebuilt only when the screen kind or language changes
    var kind = !ord ? "none" : (fin ? "fin" : (ord.ready ? "rdy" : "wait")), key = kind + LANG;
    if (key === built) return; built = key;
    var h = '<div class="wait ' + (kind === "rdy" ? "rdy" : kind === "fin" ? "fin" : "") + '"><div class="w-in">' + top();
    if (kind === "none") {
      h += '<h1 class="w-t">' + esc(L("tr_none_t")) + '</h1><p class="w-s">' + esc(L("tr_none_s")) + '</p><a class="w-btn w-more" href="' + esc(C.menu || "/") + '">' + esc(L("tr_menu")) + "</a>" + findForm(true);
    } else if (kind === "fin") {
      h += '<h1 class="w-t">' + esc(L("tr_done_t")) + '</h1><p class="w-s">' + esc(L("tr_done_s")) + '</p><div class="w-promos">' + promos(true) + '</div><a class="w-btn w-more" href="' + esc(C.menu || "/") + '">' + esc(L("tr_menu")) + "</a>";
    } else {
      h += '<div class="w-num num" id="wNum"></div><h1 class="w-t" id="wTitle"></h1>' +
        '<div class="w-ring"><svg viewBox="0 0 120 120" aria-hidden="true"><circle class="bg" cx="60" cy="60" r="54"/><circle class="fg" id="wArc" cx="60" cy="60" r="54"/></svg><div class="w-c"><div class="w-time num" id="wTime">--:--</div><div class="w-cap" id="wCap"></div></div></div>' +
        '<div class="w-steps" id="wSteps"></div>';
      if (kind === "wait") h += '<div class="w-sum" id="wSum" hidden></div><p class="w-keep">' + esc(L("wait_keep")) + '</p><button type="button" class="w-btn" id="wNotify" hidden>' + esc(L("wait_notify")) + '</button><div class="w-promos">' + promos(false) + '</div><button type="button" class="w-btn w-more" id="wAdd" hidden>' + esc(L("wait_more")) + '</button><p class="w-add-hint">' + esc(L("wait_more_t")) + "</p>" + findForm(false);
      else h += '<div class="w-sum" id="wSum" hidden></div><button type="button" class="w-ok" id="wOk">' + esc(L("wait_ok")) + "</button>";
    }
    root.innerHTML = h + "</div></div>";
    document.title = (C.brand || "Restaurant") + " – " + L("tr_title");
  }
  function tick() {
    build(); if (!ord || fin) return;
    var rdy = !!ord.ready, secs = O.secs(ord), $ = function (i) { return document.getElementById(i); };
    $("wNum").textContent = fmt(L("ord_bar"), { n: ord.num });
    $("wTitle").textContent = rdy ? L("ord_ready_t") : L("wait_ready_in");
    $("wTime").textContent = rdy ? "✓" : (secs == null ? "…" : O.mmss(secs));
    var total = Math.max(ord.total || 0, secs || 0, 1), p = rdy ? 1 : (secs == null ? 0 : 1 - secs / total);
    $("wArc").style.strokeDashoffset = String(CIRC * (1 - Math.max(0.02, Math.min(1, p))));
    $("wCap").textContent = rdy ? L(ord.mode === "pick" ? "ord_ready_pick" : "ord_ready_dine") : (secs != null && secs <= 0 ? L("wait_soon") : (secs != null && secs > 0 ? O.txt(LANG).in.replace("{m}", Math.max(1, Math.ceil(secs / 60))).replace("{t}", O.clock(ord.ready_at)) : (ord.eta ? fmt(L("prep_at"), { t: ord.eta }) : "")));
    var st = rdy ? 4 : (ord.seen || ord.acc ? 2 : 1);
    $("wSteps").innerHTML = ["wait_s1", "wait_s2", "wait_s3"].map(function (k, i) { return '<div class="ws' + (i + 1 < st ? " done" : i + 1 === st ? " on" : "") + '"><i>' + (i + 1 < st ? "✓" : i + 1) + "</i><span>" + esc(L(k)) + "</span></div>"; }).join("");
    var sm = $("wSum");
    if (sm) {
      var skey = ord && ord.lines && ord.lines.length ? JSON.stringify(ord.lines) + "|" + (ord.sum || 0) + "|" + (ord.combo || 0) + "|" + (ord.coupon || 0) + "|" + (ord.dep || 0) + "|" + (ord.pack || 0) : "";
      if (skey !== sumKey) { sumKey = skey; sm.hidden = !skey; if (skey) sm.innerHTML = sumHtml(); }
    }
    var ab = $("wAdd"); if (ab) ab.hidden = !(ord && ord.name && ord.phone);
    // online payment not confirmed yet: the order is not with the kitchen, so no countdown - just the state and a way to pay again
    var pb = $("wPay"), pend = ord.pay === "pending" || ord.pay === "failed";
    if (pend) {
      $("wTitle").textContent = L("pay_wait_t"); $("wTime").textContent = "…"; $("wArc").style.strokeDashoffset = String(CIRC);
      $("wCap").textContent = (ord.pay === "failed" || /[?&]pay=cancel/.test(location.search)) ? L("pay_failed") : L("pay_wait");
      if (!pb) { pb = document.createElement("button"); pb.type = "button"; pb.id = "wPay"; pb.className = "w-btn w-go"; $("wCap").parentNode.insertBefore(pb, $("wCap").nextSibling); }
      pb.textContent = L("pay_retry"); pb.hidden = false;
    } else if (pb) pb.hidden = true;
    var nb = $("wNotify"); if (nb) nb.hidden = !("Notification" in window) || Notification.permission === "granted" || Notification.permission === "denied";
  }
  function sync(o) {
    ord = o && !o.fin ? o : null; if (!ord) fin = fin || false;
    pickLang(); if (ord && ord.ready && !fin) { O.alarm(); O.awake(); } tick();
  }
  document.addEventListener("submit", function (e) {
    if (e.target.id !== "tFind") return; e.preventDefault();
    var num = document.getElementById("tNum").value, ph = document.getElementById("tPh").value, er = document.getElementById("tErr"); er.textContent = "";
    if (!C.lookup) return;
    fetch(C.lookup, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ num: num, phone: ph }) }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) { er.textContent = L("tr_find_err"); return; }
      var o = { id: j.id, tok: j.tok, num: j.num, ready_at: j.ready_at || 0, off: j.now * 1000 - Date.now(), mode: j.mode, eta: j.eta || "", ts: Date.now(), lang: LANG, seen: !!j.seen, acc: !!j.accepted, ready: !!j.ready, total: j.ready_at ? Math.max(1, j.ready_at - j.now) : 0, lines: j.lines || null, sum: j.total, sub: j.sub, combo: j.combo, coupon: j.coupon, dep: j.dep, pack: j.pack, name: j.name || "", phone: j.phone || "" };
      fin = false; built = ""; O.save(o); sync(o); O.poll(); try { history.replaceState(null, "", location.pathname); } catch (x) {}
    }).catch(function () { er.textContent = L("tr_find_err"); });
  });
  document.addEventListener("click", function (e) {
    var t = e.target.closest && e.target.closest("[data-lang], #wOk, #wNotify, #wAdd, #wPay, [data-copy]"); if (!t) return;
    if (t.dataset.lang) { try { localStorage.setItem("gh-lang", t.dataset.lang); } catch (x) {} built = ""; pickLang(); return tick(); }
    if (t.id === "wPay") {
      if (!C.pay_retry || !ord) return; t.disabled = true;
      fetch(C.pay_retry, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: ord.id, t: ord.tok }) }).then(function (r) { return r.json(); }).then(function (j) {
        t.disabled = false;
        if (j && j.pay_url) location.href = j.pay_url; else if (j && j.paid) { O.poll(); } else toast(L("pay_fail"));
      }).catch(function () { t.disabled = false; toast(L("pay_fail")); });
      return;
    }
    if (t.id === "wOk") { O.stop(); O.release(); ord.fin = true; O.save(ord); fin = true; built = ""; return tick(); }
    if (t.id === "wAdd") { try { localStorage.setItem("gh-add", JSON.stringify({ name: ord.name || "", phone: ord.phone || "", mode: ord.mode || "dine", eta: ord.eta || "" })); } catch (x) {} location.href = C.menu || "/"; return; }
    if (t.id === "wNotify") { try { Notification.requestPermission().then(function (p) { if (p === "granted") subscribe(); tick(); }); } catch (x) {} return; }
    if (t.dataset.copy) { var c = t.dataset.copy; try { navigator.clipboard.writeText(c).then(function () { toast(L("wait_copied")); }); } catch (x) {} }
  });
  // Web Push: the browser keeps a subscription for THIS order, so "accepted" / "ready" arrive even when the page is closed
  function b64(s) { var p = "=".repeat((4 - s.length % 4) % 4), r = atob((s + p).replace(/-/g, "+").replace(/_/g, "/")), a = new Uint8Array(r.length); for (var i = 0; i < r.length; i++) a[i] = r.charCodeAt(i); return a; }
  function subscribe() {
    if (!ord || !C.push_key || !C.push_url || !C.sw_url || !("serviceWorker" in navigator) || !("PushManager" in window) || Notification.permission !== "granted") return;
    var o = ord;
    navigator.serviceWorker.register(C.sw_url, { scope: "/" }).then(function () { return navigator.serviceWorker.ready; }).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (s) { return s || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64(C.push_key) }); });
    }).then(function (sub) {
      return fetch(C.push_url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ id: o.id, t: o.tok, sub: sub.toJSON(), url: location.origin + location.pathname }) });
    }).catch(function () {});
  }
  // shared link: /track/?id=12&t=TOKEN (e.g. forwarded to a friend) – builds the local state from the server
  function fromUrl() {
    var q = new URLSearchParams(location.search), id = q.get("id"), t = q.get("t"); if (!id || !t || !C.status) return;
    fetch(C.status + (C.status.indexOf("?") > -1 ? "&" : "?") + "id=" + encodeURIComponent(id) + "&t=" + encodeURIComponent(t), { cache: "no-store" }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j || !j.ok) return;
      var cur = O.load(); if (cur && String(cur.id) === String(id)) return;
      var o = { id: +id, tok: t, num: j.num, ready_at: j.ready_at || 0, off: j.now * 1000 - Date.now(), mode: j.mode || "dine", eta: j.eta || "", ts: Date.now(), lang: LANG, seen: !!j.seen, ready: !!j.ready, total: j.ready_at ? Math.max(1, j.ready_at - j.now) : 0, lines: j.lines || null, sum: j.total, sub: j.sub, combo: j.combo, coupon: j.coupon, dep: j.dep, pack: j.pack, name: j.name || "", phone: j.phone || "" };
      O.save(o); sync(o); O.poll();
    }).catch(function () {});
  }
  sync(O.load()); fromUrl(); subscribe();
  O.on(function (o) { if (!fin) sync(o); });
  setInterval(function () { if (!fin) { var o = O.load(); if (o) ord = o; else if (ord) ord = null; tick(); } }, 1000);
  O.poll();
  if (ord) O.awake();
})();
