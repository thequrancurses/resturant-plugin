/* Grill Hub – shared helpers: opening hours (always Warsaw time, whatever the visitor's device says) and anonymous click / view counters. */
(function () {
  "use strict";
  var TZ = "Europe/Warsaw", WEEK = 10080, DAY = 1440;
  var DN = { // short day names, Monday first
    pl: ["Pn", "Wt", "Śr", "Cz", "Pt", "So", "Nd"],
    en: ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"],
    ar: ["الإثنين", "الثلاثاء", "الأربعاء", "الخميس", "الجمعة", "السبت", "الأحد"]
  };
  var DP = { // "on Friday"
    pl: ["w poniedziałek", "we wtorek", "w środę", "w czwartek", "w piątek", "w sobotę", "w niedzielę"],
    en: ["on Monday", "on Tuesday", "on Wednesday", "on Thursday", "on Friday", "on Saturday", "on Sunday"],
    ar: ["يوم الإثنين", "يوم الثلاثاء", "يوم الأربعاء", "يوم الخميس", "يوم الجمعة", "يوم السبت", "يوم الأحد"]
  };
  var WH = { pl: ["dziś", "jutro"], en: ["today", "tomorrow"], ar: ["اليوم", "غدًا"] };
  var T = { // texts for the home page badge and info block
    pl: { open: "Otwarte teraz", until: "do {t}", closed: "Zamknięte", soon: "Zaraz zamykamy", opens: "Otwieramy {w} o {t}", hours: "Godziny pracy", addr: "Adres", call: "Telefon", deliv: "Zamów z dostawą", social: "Znajdź nas w social media" },
    en: { open: "Open now", until: "until {t}", closed: "Closed", soon: "Closing soon", opens: "We open {w} at {t}", hours: "Opening hours", addr: "Address", call: "Phone", deliv: "Order for delivery", social: "Follow us" },
    ar: { open: "مفتوح الآن", until: "حتى {t}", closed: "مقفول", soon: "على وشك الإغلاق", opens: "نفتح {w} الساعة {t}", hours: "ساعات العمل", addr: "العنوان", call: "الهاتف", deliv: "اطلب توصيل", social: "تابعنا" }
  };

  function toMin(s) { var m = /^(\d{1,2}):(\d{2})$/.exec(String(s || "")); return m ? (+m[1] % 24) * 60 + (+m[2]) : null; }
  function clock(min) { min = ((min % DAY) + DAY) % DAY; var h = Math.floor(min / 60), m = min % 60; return (h < 10 ? "0" : "") + h + ":" + (m < 10 ? "0" : "") + m; }
  function show(s) { return s === "00:00" ? "24:00" : s; } // midnight reads better as 24:00

  /* wall-clock minutes since Monday 00:00 in Warsaw */
  function warsawNow(ts) {
    var d = new Date(ts || Date.now());
    try {
      var parts = new Intl.DateTimeFormat("en-GB", { timeZone: TZ, weekday: "short", hour: "2-digit", minute: "2-digit", hourCycle: "h23" }).formatToParts(d), o = {};
      parts.forEach(function (p) { o[p.type] = p.value; });
      var dow = { Mon: 0, Tue: 1, Wed: 2, Thu: 3, Fri: 4, Sat: 5, Sun: 6 }[o.weekday];
      if (dow == null) throw 0;
      return { dow: dow, min: (+o.hour % 24) * 60 + (+o.minute) };
    } catch (e) { var g = d.getDay(); return { dow: (g + 6) % 7, min: d.getHours() * 60 + d.getMinutes() }; }
  }
  /* opening windows as [start, end) in minutes since Monday 00:00; end may run past midnight */
  function windows(hours) {
    var w = [];
    (hours || []).forEach(function (h, d) {
      if (!h || !h.on) return;
      var o = toMin(h.open), c = toMin(h.close); if (o == null || c == null) return;
      w.push({ d: d, s: d * DAY + o, e: d * DAY + (c <= o ? c + DAY : c), oc: o, cc: c });
    });
    return w;
  }
  function inWin(W, x) { return W.some(function (k) { return (x >= k.s && x < k.e) || (x + WEEK >= k.s && x + WEEK < k.e); }); }
  function isOpenAt(hours, dow, min) { return inWin(windows(hours), dow * DAY + min); }

  /* { open, until:"HH:MM", left:minutes, next:{ day:0|1|2.., dow, at:"HH:MM" } } */
  function status(hours, ts) {
    var n = warsawNow(ts), x = n.dow * DAY + n.min, W = windows(hours), i, k;
    if (!W.length) return { open: true, always: true }; // no hours entered = never claim "closed"
    for (i = 0; i < W.length; i++) {
      k = W[i];
      var xs = x >= k.s && x < k.e ? x : (x + WEEK >= k.s && x + WEEK < k.e ? x + WEEK : -1);
      if (xs >= 0) return { open: true, until: clock(k.cc), left: k.e - xs };
    }
    var best = null, bd = 1e9;
    W.forEach(function (w) { var dl = ((w.s - x) % WEEK + WEEK) % WEEK; if (dl < bd) { bd = dl; best = w; } });
    var tdow = Math.floor((((x + bd) % WEEK)) / DAY), diff = (tdow - n.dow + 7) % 7;
    return { open: false, next: { day: diff, dow: tdow, at: clock(best.oc) } };
  }
  /* order for a given time of day: today if it is still ahead (or just passed), otherwise tomorrow */
  function etaOpen(hours, hhmm, ts) {
    var t = toMin(hhmm); if (t == null || !windows(hours).length) return true;
    var n = warsawNow(ts), dow = t + 5 >= n.min ? n.dow : (n.dow + 1) % 7;
    return isOpenAt(hours, dow, t);
  }
  function when(st, lang) {
    var nx = st.next; if (!nx) return "";
    return nx.day === 0 ? WH[lang][0] : nx.day === 1 ? WH[lang][1] : DP[lang][nx.dow];
  }
  /* "Pn–Cz, Nd 10:00–24:00 · Pt–So 10:00–02:00" */
  function summary(hours, lang) {
    lang = DN[lang] ? lang : "pl";
    var groups = [], idx = {};
    (hours || []).forEach(function (h, d) {
      var key = h && h.on ? show(h.open) + "–" + show(h.close) : "x";
      if (!(key in idx)) { idx[key] = groups.length; groups.push({ k: key, days: [] }); }
      groups[idx[key]].days.push(d);
    });
    return groups.filter(function (g) { return g.k !== "x"; }).map(function (g) {
      var runs = [], a = g.days[0], b = a, i;
      for (i = 1; i <= g.days.length; i++) {
        if (g.days[i] === b + 1) { b = g.days[i]; continue; }
        runs.push(a === b ? DN[lang][a] : (b - a === 1 ? DN[lang][a] + ", " + DN[lang][b] : DN[lang][a] + "–" + DN[lang][b])); a = g.days[i]; b = a;
      }
      var all = g.days.length === 7;
      return (all ? ({ pl: "Codziennie", en: "Daily", ar: "يوميًا" })[lang] : runs.join(lang === "ar" ? "، " : ", ")) + " \u2066" + g.k + "\u2069"; // the time range stays left-to-right inside Arabic text
    });
  }

  /* anonymous counters (no cookies, no personal data) */
  var BOT = /bot|crawl|spider|headless|lighthouse|preview/i;
  function hit(url, body, once) {
    if (!url || BOT.test(navigator.userAgent || "")) return;
    try { if (once) { var k = "gh-hit-" + once; if (sessionStorage.getItem(k)) return; sessionStorage.setItem(k, "1"); } } catch (e) {}
    try {
      var json = JSON.stringify(body);
      if (navigator.sendBeacon && navigator.sendBeacon(url, new Blob([json], { type: "text/plain;charset=UTF-8" }))) return;
      fetch(url, { method: "POST", headers: { "Content-Type": "text/plain;charset=UTF-8" }, body: json, keepalive: true }).catch(function () {});
    } catch (e) {}
  }

  /* where the visitor came from: ?src= (QR codes, bio links) > utm_source > referrer; kept for 6 hours so the home page -> menu -> order path keeps the first source */
  var REFS = [[/google\./, "google"], [/instagram\./, "instagram"], [/facebook\.|fb\.com|fb\.me/, "facebook"], [/tiktok\./, "tiktok"], [/wolt\./, "wolt"], [/ubereats\./, "ubereats"], [/pyszne\./, "pyszne"], [/bolt\.eu/, "bolt"], [/glovoapp\./, "glovo"], [/bing\.|duckduckgo\.|yahoo\.|ecosia\./, "search"], [/whatsapp\./, "whatsapp"], [/(^|\.)t\.co$|twitter\.|(^|\.)x\.com$/, "x"]];
  function cleanSrc(s) { return String(s || "").toLowerCase().replace(/[^a-z0-9_-]/g, "").slice(0, 20); }
  var _src = null;
  function source() {
    if (_src) return _src.s;
    var KEY = "gh-src", now = Date.now(), sv = null, q = null;
    try { sv = JSON.parse(localStorage.getItem(KEY) || "null"); } catch (e) {}
    try { q = new URLSearchParams(location.search); } catch (e) {}
    var p = q ? cleanSrc(q.get("src") || q.get("utm_source")) : "", tb = q ? String(q.get("t") || "").replace(/[^0-9A-Za-z]/g, "").slice(0, 6) : "";
    var rec = null;
    if (p) rec = { s: p, t: tb, ts: now };
    else if (sv && sv.s && now - sv.ts < 6 * 3600 * 1000) rec = sv;
    else {
      var s = "direct", host = "";
      try { host = document.referrer ? new URL(document.referrer).hostname : ""; } catch (e) {}
      if (host && host !== location.hostname) { s = "referral"; REFS.some(function (r) { if (r[0].test(host)) { s = r[1]; return true; } }); }
      rec = { s: s, t: "", ts: now };
    }
    _src = rec; try { localStorage.setItem(KEY, JSON.stringify(rec)); } catch (e) {}
    return rec.s;
  }
  function table() { source(); return _src && _src.s === "qr" ? _src.t : ""; }

  /* offer window {from:"HH:MM",to:"HH:MM",days:[0..6 Mon..Sun]} in Warsaw time; empty from/to = all day */
  function inWin(w) {
    if (!w) return true;
    var n = warsawNow(), days = w.days && w.days.length ? w.days : [0, 1, 2, 3, 4, 5, 6];
    if (days.indexOf(n.dow) < 0) return false;
    if (!w.from || !w.to) return true;
    var a = +w.from.slice(0, 2) * 60 + +w.from.slice(3), b = +w.to.slice(0, 2) * 60 + +w.to.slice(3);
    return b > a ? (n.min >= a && n.min < b) : (n.min >= a || n.min < b);
  }

  /* party popper: confetti burst + a "congratulations, you can win a free dish" card. Shown once per 12 h per browser. */
  var PT = { pl: { t: "Gratulacje!", b: "Zamów za min. {min} zł i odbierz gratis:", go: "Zamawiam!", x: "Zamknij" }, en: { t: "Congratulations!", b: "Order for {min} zł or more and get a free dish:", go: "Order now!", x: "Close" }, ar: { t: "مبروك!", b: "اطلب بـ {min} zł أو أكثر واحصل مجانًا على:", go: "اطلب الآن!", x: "إغلاق" } };
  var PO = { pl: { offers: "Oferty!", coupon: "Kody rabatowe" }, en: { offers: "Offers!", coupon: "Discount codes" }, ar: { offers: "عروض!", coupon: "أكواد الخصم" } };
  function partyCSS() {
    if (document.getElementById("ghp-css")) return;
    var st = document.createElement("style"); st.id = "ghp-css";
    st.textContent = ".ghp{position:fixed;inset:0;z-index:99990;display:grid;place-items:center;padding:18px;background:rgba(10,6,5,.72);animation:ghpf .3s ease both}@keyframes ghpf{from{opacity:0}}" +
      ".ghp-c{position:relative;z-index:2;width:100%;max-width:360px;text-align:center;background:#fff;color:#141416;border-radius:26px;padding:26px 22px 22px;box-shadow:0 30px 80px rgba(0,0,0,.5);animation:ghpp .7s cubic-bezier(.2,1.4,.4,1) both;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif}" +
      "@keyframes ghpp{from{transform:scale(.5) rotate(-4deg);opacity:0}}" +
      ".ghp-e{font-size:60px;line-height:1;display:block;animation:ghpw 1.2s ease-in-out infinite}@keyframes ghpw{50%{transform:rotate(-12deg) scale(1.12)}}" +
      ".ghp-c h2{margin:6px 0 4px;font-size:30px;font-weight:900;color:#e11e26}.ghp-c p{margin:0 0 10px;color:#555;font-size:16px;line-height:1.4}.ghp-c h3{margin:14px 0 4px;font-size:19px;font-weight:800;color:#141416}.ghp-c .ghp-l{margin-bottom:6px;max-height:38vh;overflow:auto}" +
      ".ghp-l{display:grid;gap:8px;margin:6px 0 16px}.ghp-l div{background:#fff3d6;border:2px dashed #e0a21a;border-radius:14px;padding:10px 12px;font-weight:800;font-size:17px}.ghp-l small{display:block;font-weight:600;color:#7a5a00;font-size:13px}" +
      ".ghp-go{display:block;width:100%;border:0;border-radius:16px;padding:15px;background:#e11e26;color:#fff;font:inherit;font-weight:800;font-size:18px;cursor:pointer;box-shadow:0 10px 24px rgba(225,30,38,.4)}" +
      ".ghp-x{margin-top:10px;border:0;background:none;color:#777;font:inherit;font-size:14px;cursor:pointer;padding:6px 12px}" +
      ".ghp-cv{position:fixed;inset:0;z-index:99991;pointer-events:none;width:100%;height:100%}" +
      "@media (prefers-reduced-motion:reduce){.ghp-c,.ghp-e{animation:none}}";
    document.head.appendChild(st);
  }
  function confetti(cv) {
    var ctx = cv.getContext("2d"), dpr = Math.min(window.devicePixelRatio || 1, 2), W = 0, H = 0, ps = [], t0 = Date.now(), raf = 0, COL = ["#e11e26", "#ffc43c", "#ffffff", "#2ecc71", "#ff5fa2", "#3aa0ff"];
    function fit() { W = window.innerWidth; H = window.innerHeight; cv.width = W * dpr; cv.height = H * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); }
    function burst(side, n) {
      for (var i = 0; i < n; i++) {
        var a = (side < 0 ? -62 : -118) * Math.PI / 180 + (Math.random() - .5) * 0.9, v = (11 + Math.random() * 11) * (H > 700 ? 1 : .85);
        ps.push({ x: side < 0 ? 8 : W - 8, y: H - 8, vx: Math.cos(a) * v, vy: Math.sin(a) * v, w: 6 + Math.random() * 7, h: 3 + Math.random() * 6, r: Math.random() * 6.28, vr: (Math.random() - .5) * .5, c: COL[(Math.random() * COL.length) | 0], life: 0, max: 150 + Math.random() * 90 });
      }
    }
    fit(); burst(-1, 90); burst(1, 90); setTimeout(function () { burst(-1, 60); burst(1, 60); }, 650); setTimeout(function () { burst(-1, 40); burst(1, 40); }, 1400);
    function tick() {
      ctx.clearRect(0, 0, W, H);
      ps = ps.filter(function (p) { return p.life < p.max && p.y < H + 30; });
      ps.forEach(function (p) {
        p.life++; p.vy += .32; p.vx *= .985; p.vy *= .985; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save(); ctx.globalAlpha = Math.min(1, (p.max - p.life) / 40); ctx.translate(p.x, p.y); ctx.rotate(p.r); ctx.scale(1, Math.cos(p.r * 1.7)); ctx.fillStyle = p.c; ctx.fillRect(-p.w / 2, -p.h / 2, p.w, p.h); ctx.restore();
      });
      if (ps.length || Date.now() - t0 < 1700) raf = requestAnimationFrame(tick);
    }
    tick(); return function () { cancelAnimationFrame(raf); };
  }
  /* Collect party-popup sections from every offer type.
     d = { gift: {tiers:[{min,name}], win, popup}, combo: {label, off, win, popup},
           bundle: {label, n, price, win, popup}, coupons: [{code, kind, value, min}] }
     Labels are already localized strings. Returns {title, sections} or null. */
  function partySections(lang, d) {
    var L = PT[lang] ? lang : "pl", tx = PT[L], po = PO[L] || PO.pl, secs = [];
    function money(n) { return String(n).replace(".", ","); }
    if (d.gift && d.gift.popup && inWin(d.gift.win) && d.gift.tiers && d.gift.tiers.length) {
      var first = d.gift.tiers[0];
      secs.push({ h: "", b: tx.b.replace("{min}", money(first.min)),
        rows: d.gift.tiers.map(function (t) { return { t: "🎁 " + t.name, s: "\u2265 " + money(t.min) + " z\u0142" }; }) });
    }
    if (d.combo && d.combo.popup && inWin(d.combo.win) && +d.combo.off > 0 && d.combo.label) {
      secs.push({ h: d.combo.label, b: "\u2212" + money(d.combo.off) + " z\u0142", rows: [] });
    }
    if (d.bundle && d.bundle.popup && inWin(d.bundle.win) && +d.bundle.n >= 2 && d.bundle.label) {
      secs.push({ h: d.bundle.label, b: d.bundle.n + " \u00D7 " + money(d.bundle.price) + " z\u0142", rows: [] });
    }
    if (d.coupons && d.coupons.length) {
      secs.push({ h: "🎟\uFE0F " + po.coupon, b: "",
        rows: d.coupons.map(function (c) {
          var v = c.kind === "amount" ? "\u2212" + money(c.value) + " z\u0142" : "\u2212" + money(c.value) + "%";
          return { t: c.code, s: v + (+c.min > 0 ? " \u00B7 \u2265 " + money(c.min) + " z\u0142" : "") };
        }) });
    }
    if (!secs.length) return null;
    return { title: secs.length > 1 ? po.offers : tx.t, sections: secs };
  }

  /* Party popup: confetti + one card with every offer section.
     o = { lang, sections: [{h (title), b (body), rows: [{t, s}]}], goText?, onGo? }
     Back-compat: o.tiers:[{min, name}] becomes a single gift section. */
  function celebrate(o) {
    try {
      if (!o || /[?&]nopopup/.test(location.search)) return;
      var K = "gh-party", now = Date.now(), last = 0;
      try { last = +localStorage.getItem(K) || 0; } catch (e) {}
      if (now - last < 12 * 3600 * 1000 && !/[?&]party/.test(location.search)) return;
      var lang = PT[o.lang] ? o.lang : "pl", tx = PT[lang];
      var secs = o.sections || [];
      if (!secs.length && o.tiers && o.tiers.length) {
        var first = o.tiers[0];
        secs = [{ h: "", b: tx.b.replace("{min}", String(first.min).replace(".", ",")), rows: o.tiers.map(function (t) { return { t: t.name, s: "\u2265 " + String(t.min).replace(".", ",") + " z\u0142" }; }) }];
      }
      if (!secs.length) return;
      try { localStorage.setItem(K, String(now)); } catch (e) {}
      var reduce = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      partyCSS();
      function esc(v) { return String(v).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
      var el = document.createElement("div"); el.className = "ghp"; el.setAttribute("role", "dialog"); el.setAttribute("aria-modal", "true"); el.dir = lang === "ar" ? "rtl" : "ltr";
      var body = secs.map(function (sc) {
        var rows = (sc.rows || []).map(function (r) { return "<div>" + esc(r.t) + (r.s ? "<small>" + esc(r.s) + "</small>" : "") + "</div>"; }).join("");
        return (sc.h ? "<h3>" + esc(sc.h) + "</h3>" : "") + (sc.b ? "<p>" + esc(sc.b) + "</p>" : "") + (rows ? '<div class="ghp-l">' + rows + "</div>" : "");
      }).join("");
      el.innerHTML = '<div class="ghp-c"><span class="ghp-e" aria-hidden="true">🎉</span><h2>' + esc(o.title || tx.t) + "</h2>" + body + '<button type="button" class="ghp-go">' + esc(o.goText || tx.go) + '</button><button type="button" class="ghp-x">' + esc(tx.x) + "</button></div>";
      var cv = null, stop = null;
      if (!reduce) { cv = document.createElement("canvas"); cv.className = "ghp-cv"; cv.setAttribute("aria-hidden", "true"); document.body.appendChild(cv); stop = confetti(cv); }
      document.body.appendChild(el);
      function close() { if (stop) stop(); if (cv) cv.remove(); el.remove(); }
      el.querySelector(".ghp-go").addEventListener("click", function () { close(); if (o.onGo) o.onGo(); });
      el.querySelector(".ghp-x").addEventListener("click", close);
      el.addEventListener("click", function (e) { if (e.target === el) close(); });
      document.addEventListener("keydown", function esc2(e) { if (e.key === "Escape") { close(); document.removeEventListener("keydown", esc2); } });
      if (cv) setTimeout(function () { if (cv) cv.remove(); }, 6500);
    } catch (e) {}
  }

  /* language dropdown of the shared header (.gh-hdr) */
  document.addEventListener("click", function (e) {
    var b = e.target.closest && e.target.closest(".gh-lang > button"), open = document.querySelectorAll(".gh-lmenu:not([hidden])");
    Array.prototype.forEach.call(open, function (m) { if (!b || m.parentNode !== b.parentNode) { m.hidden = true; m.previousSibling && m.previousSibling.setAttribute && m.previousSibling.setAttribute("aria-expanded", "false"); } });
    if (b) { var m = b.parentNode.querySelector(".gh-lmenu"); m.hidden = !m.hidden; b.setAttribute("aria-expanded", String(!m.hidden)); }
  });
  /* the language chosen on the menu / home page also applies to the loyalty pages (server side reads this cookie) */
  /* footer: translate when the language changes (html[lang] / gh-lang) */
  function ftLang() { var l = ""; try { l = localStorage.getItem("gh-lang") || ""; } catch (e) {} if (!/^(pl|en|ar)$/.test(l)) l = document.documentElement.lang; return /^(pl|en|ar)$/.test(l) ? l : ""; }
  function ftApply() {
    var T = window.GHM_FT, f = document.querySelector(".gh-ftr"), l = ftLang(); if (!T || !f || !l) return;
    f.setAttribute("dir", l === "ar" ? "rtl" : "ltr");
    f.querySelectorAll("[data-ft]").forEach(function (el) { var t = T[el.getAttribute("data-ft")]; if (t && t[l]) el.textContent = t[l]; });
  }
  document.addEventListener("click", function (e) { if (e.target.closest && e.target.closest("[data-lang]")) setTimeout(ftApply, 80); }, true);
  window.addEventListener("storage", ftApply);
  try { new MutationObserver(ftApply).observe(document.documentElement, { attributes: true, attributeFilter: ["lang"] }); } catch (e) {}
  if (document.readyState === "loading") document.addEventListener("DOMContentLoaded", ftApply); else ftApply();
  function syncLangCookie(l) { try { if (!l) l = localStorage.getItem("gh-lang"); if (/^(pl|en|ar)$/.test(l)) document.cookie = "ls_lang=" + l + ";path=/;max-age=31536000;SameSite=Lax"; } catch (e) {} }
  syncLangCookie();

  /* ---------- order tracking: state kept in localStorage ("gh-ord"), header countdown bar on every page, alarm helpers for /track/ ---------- */
  var OT = {
    pl: { bar: "Kliknij tutaj, aby śledzić zamówienie", rdy: "Zamówienie gotowe! Kliknij tutaj", soon: "Zaraz gotowe – kliknij tutaj", lbl: "Zamówienie", in: "Gotowe za ~{m} min (ok. {t})", acc: "Przyjęte ✓", upd: "Aktualizacja kuchni: gotowe za ~{m} min", okt: "Zamówienie przyjęte ✓" },
    en: { bar: "Tap here to track your order", rdy: "Your order is ready! Tap here", soon: "Almost ready – tap here", lbl: "Order", in: "Ready in ~{m} min (about {t})", acc: "Accepted ✓", upd: "Kitchen update: ready in ~{m} min", okt: "Order accepted ✓" },
    ar: { bar: "اضغط هنا لمتابعة الأوردر", rdy: "الأوردر جاهز! اضغط هنا", soon: "قربنا نخلص – اضغط هنا", lbl: "أوردر", in: "جاهز خلال ~{m} دقيقة (حوالي {t})", acc: "تم القبول ✓", upd: "تحديث من المطبخ: جاهز خلال ~{m} دقيقة", okt: "تم قبول الأوردر ✓" }
  };
  /* The language the customer is browsing in right now — the header timer follows language switches instead of staying frozen at the language the order was placed in. Falls back to the order's language, then Polish. */
  function ordLang(o) {
    var l = ""; try { l = localStorage.getItem("gh-lang") || ""; } catch (e) {}
    if (!OT[l]) l = (document.documentElement && document.documentElement.lang) || "";
    if (!OT[l] && o) l = o.lang || "";
    return OT[l] ? l : "pl";
  }
  var ORDK = "gh-ord", ordCb = [], ordTm = null, ordPl = null, ordCfg = null, actx = null, wlock = null, alarmT = null, barEl = null;
  function ordLoad() {
    try { var o = JSON.parse(localStorage.getItem(ORDK) || "null"); if (o && o.id && o.tok && Date.now() - o.ts < 4 * 3600 * 1000 && !o.fin) return o; } catch (e) {}
    return null;
  }
  function ordSave(o) { try { localStorage.setItem(ORDK, JSON.stringify(o)); } catch (e) {} }
  function ordSecs(o) { return o && o.ready_at ? Math.max(0, Math.round((o.ready_at * 1000 - (Date.now() + (o.off || 0))) / 1000)) : null; }
  function mmss(s) { return (Math.floor(s / 60) < 10 ? "0" : "") + Math.floor(s / 60) + ":" + (s % 60 < 10 ? "0" : "") + (s % 60); }
  function ordNotify(o) { ordCb.forEach(function (f) { try { f(o); } catch (e) {} }); }
  function unlockAudio() { try { actx = actx || new (window.AudioContext || window.webkitAudioContext)(); if (actx.state === "suspended") actx.resume(); } catch (e) {} }
  function beep() {
    try { if (navigator.vibrate) navigator.vibrate([400, 150, 400, 150, 600]); } catch (e) {}
    try { unlockAudio(); if (!actx) return; [0, 0.28, 0.56, 0.9, 1.18].forEach(function (t, i) { var o = actx.createOscillator(), g = actx.createGain(); o.type = "sine"; o.frequency.value = i < 3 ? 880 : 1175; g.gain.setValueAtTime(0.0001, actx.currentTime + t); g.gain.exponentialRampToValueAtTime(0.5, actx.currentTime + t + 0.03); g.gain.exponentialRampToValueAtTime(0.0001, actx.currentTime + t + 0.24); o.connect(g); g.connect(actx.destination); o.start(actx.currentTime + t); o.stop(actx.currentTime + t + 0.26); }); } catch (e) {}
  }
  function startAlarm() {
    if (alarmT) return; beep();
    alarmT = setInterval(function () { beep(); try { document.title = (/^🔔 /.test(document.title) ? "" : "🔔 ") + document.title.replace(/^🔔 /, ""); } catch (e) {} }, 4000);
  }
  function stopAlarm() { clearInterval(alarmT); alarmT = null; try { document.title = document.title.replace(/^🔔 /, ""); if (navigator.vibrate) navigator.vibrate(0); } catch (e) {} }
  function keepAwake() { try { if (navigator.wakeLock && !wlock) navigator.wakeLock.request("screen").then(function (l) { wlock = l; l.addEventListener("release", function () { wlock = null; }); }).catch(function () {}); } catch (e) {} }
  function release() { try { if (wlock) wlock.release(); } catch (e) {} }
  document.addEventListener("visibilitychange", function () { if (!document.hidden && ordCfg && ordCfg.keepAwake && ordLoad()) keepAwake(); });
  function ordPoll() {
    var o = ordLoad(); if (!o || o.ready || !ordCfg || !ordCfg.status) return;
    fetch(ordCfg.status + (ordCfg.status.indexOf("?") > -1 ? "&" : "?") + "id=" + o.id + "&t=" + encodeURIComponent(o.tok), { cache: "no-store" })
      .then(function (r) { return r.json().then(function (j) { if (r.status === 404 && j && j.ok === false) { o.fin = true; ordSave(o); ordNotify(null); return null; } return j; }, function () { return null; }); })
      .then(function (j) {
        if (!j || !j.ok) return;
        var cur = ordLoad(); if (cur && cur.ready) { ordNotify(cur); return; } // another tab already saw "ready"
        var oldRa = o.ready_at || 0, wasAcc = !!o.acc, l0 = OT[ordLang(o)] || OT.pl;
        o.off = j.now * 1000 - Date.now(); if (j.ready_at && !o.eta) o.ready_at = j.ready_at; o.seen = !!(j.seen || j.accepted); o.acc = !!j.accepted; o.pay = j.pay || "";
        if (j.lines) o.lines = j.lines; if (typeof j.total === "number") o.sum = j.total; if (typeof j.sub === "number") o.sub = j.sub; if (typeof j.combo === "number") o.combo = j.combo; if (typeof j.coupon === "number") o.coupon = j.coupon; if (typeof j.dep === "number") o.dep = j.dep; if (typeof j.pack === "number") o.pack = j.pack; if (j.name) o.name = j.name; if (j.phone) o.phone = j.phone; if (j.mode) o.mode = j.mode;
        if (!j.ready) {
          if (j.ready_at && !o.eta && oldRa && Math.abs(j.ready_at - oldRa) > 30) { o.total = Math.max(1, j.ready_at - j.now); o.ts = Date.now(); flash(l0.upd.replace("{m}", Math.max(1, Math.ceil((j.ready_at - j.now) / 60)))); }
          else if (o.acc && !wasAcc) flash(l0.okt);
        }
        if (j.ready) { o.ready = true; try { if ("Notification" in window && Notification.permission === "granted") { var l = OT[ordLang(o)] || OT.pl; new Notification(l.lbl + " " + o.num + " – " + l.rdy.replace(/[!].*$/, "!"), { tag: "gh-ready", requireInteraction: true }); } } catch (e) {} }
        ordSave(o); ordNotify(o);
      }).catch(function () {});
  }
  function ordStart(j, lang, mode) { // after a successful order POST
    if (!j || !j.id || !j.tok) return null;
    var ra = j.prep ? j.ready_at : 0, off = (j.now || 0) * 1000 - Date.now();
    if (!ra && j.mode === "pick" && j.eta) { // later pick-up: count down to the chosen time
      var n = warsawNow(), mm = /^(\d{1,2}):(\d{2})/.exec(j.eta); if (mm) { var diff = (+mm[1] * 60 + +mm[2]) - n.min; if (diff < -30) diff += 1440; if (diff > 0) ra = Math.round((Date.now() + off) / 1000) + diff * 60; }
    }
    var o = { id: j.id, tok: j.tok, num: j.number, ready_at: ra, off: off, mode: j.mode, eta: (!j.prep && j.eta) ? j.eta : "", ts: Date.now(), lang: lang || "pl", total: ra ? Math.max(1, ra - Math.round((Date.now() + off) / 1000)) : 0, pay: j.pay_url ? "pending" : "" };
    ordSave(o); unlockAudio(); return o;
  }
  function flash(t) { // small toast ("Kitchen update: ready in ~25 min")
    try { var e = document.createElement("div"); e.className = "ghs-toast"; e.textContent = t; document.body.appendChild(e); setTimeout(function () { e.remove(); }, 4500); if (navigator.vibrate) navigator.vibrate(120); } catch (x) {}
  }
  function clockAt(sec) { try { return new Date(sec * 1000).toLocaleTimeString("pl-PL", { hour: "2-digit", minute: "2-digit", hour12: false, timeZone: TZ }); } catch (e) { return ""; } }
  function barCSS() {
    var st = document.createElement("style");
    st.textContent = ".ghs-ord{position:fixed;z-index:90;top:0;inset-inline:0;display:flex;align-items:center;justify-content:center;gap:10px;min-height:46px;padding:6px 12px;padding-top:calc(6px + env(safe-area-inset-top,0px));background:#1d1d20;color:#fff;font:700 14px/1.2 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;text-decoration:none;cursor:pointer;box-shadow:0 2px 10px rgba(0,0,0,.3)}" +
      ".ghs-ord b{font:800 18px/1 ui-monospace,SFMono-Regular,Menlo,monospace;background:#e63a1c;border-radius:8px;padding:5px 9px;direction:ltr;min-width:62px;text-align:center;letter-spacing:.02em}" +
      ".ghs-ord span{flex:0 1 auto;text-align:center;display:grid;gap:2px}.ghs-ord span em{font-style:normal;font-weight:800}.ghs-ord span u{text-underline-offset:3px;font-size:12px;font-weight:600;opacity:.9}.ghs-toast{position:fixed;z-index:95;top:calc(56px + env(safe-area-inset-top,0px));inset-inline:12px;margin:auto;width:max-content;max-width:calc(100vw - 24px);background:#fff;color:#111;border-radius:14px;padding:10px 16px;font:700 14px system-ui,sans-serif;box-shadow:0 8px 24px rgba(0,0,0,.3)}" +
      ".ghs-ord.rdy{background:#127a3a;animation:ghsrdy 1.2s ease-in-out infinite}.ghs-ord.rdy b{background:#fff;color:#127a3a}" +
      "@keyframes ghsrdy{50%{filter:brightness(1.25)}}" +
      "html{--ordh:0px}html.has-ordbar{--ordh:calc(46px + env(safe-area-inset-top,0px))}html.has-ordbar body{padding-top:var(--ordh)}" +
      "html.has-ordbar .bar{top:var(--ordh)!important}";
    document.head.appendChild(st);
  }
  function renderBar() {
    var o = ordLoad(), de = document.documentElement;
    if (!o || (ordCfg && ordCfg.noBar)) { if (barEl) { barEl.remove(); barEl = null; } de.classList.remove("has-ordbar"); return; }
    if (!barEl) { barCSS(); barEl = document.createElement("a"); barEl.className = "ghs-ord"; barEl.setAttribute("role", "status"); document.body.insertBefore(barEl, document.body.firstChild); }
    var ol = ordLang(o), l = OT[ol] || OT.pl, s = ordSecs(o), rdy = !!o.ready;
    barEl.href = ordCfg.track; barEl.className = "ghs-ord" + (rdy ? " rdy" : ""); barEl.dir = ol === "ar" ? "rtl" : "ltr";
    var t = rdy ? "✓" : (s == null ? "…" : mmss(s));
    var head = "";
    if (!rdy && s != null && s > 0) head = l.in.replace("{m}", Math.max(1, Math.ceil(s / 60))).replace("{t}", clockAt(o.ready_at));
    else if (!rdy && o.acc) head = l.acc;
    var txt = rdy ? l.rdy : (s != null && s <= 0 ? l.soon : l.bar);
    barEl.innerHTML = "<b>" + t + "</b><span>" + (head && !rdy ? "<em>" + head + "</em><u>" + txt + "</u>" : "<em>" + txt + "</em>") + "</span>";
    de.classList.add("has-ordbar");
    if (rdy) startAlarm();
  }
  function ordInit(cfg) { // cfg: {status, track, noBar, keepAwake}
    ordCfg = cfg; if (!cfg || !cfg.track) return;
    var go = function () {
      renderBar(); clearInterval(ordTm); ordTm = setInterval(renderBar, 1000);
      clearInterval(ordPl); ordPl = setInterval(ordPoll, 8000); setTimeout(ordPoll, 1200);
      ordCb.push(renderBar);
    };
    if (document.body) go(); else document.addEventListener("DOMContentLoaded", go);
  }
  window.addEventListener("storage", function (e) { if (e.key === ORDK) { renderBar(); ordNotify(ordLoad()); } });
  document.addEventListener("click", unlockAudio, true);
  document.addEventListener("click", function (e) { if (e.target.closest && e.target.closest("a")) syncLangCookie(); }, true);
  var ORDAPI = { syncLang: syncLangCookie, txt: function (l) { return OT[l] || OT.pl; }, clock: clockAt, flash: flash, unlock: unlockAudio, init: ordInit, load: ordLoad, save: ordSave, start: ordStart, secs: ordSecs, mmss: mmss, poll: ordPoll, beep: beep, alarm: startAlarm, stop: stopAlarm, awake: keepAwake, release: release, on: function (f) { ordCb.push(f); }, bar: renderBar };
  if (window.GHM_ORD) ordInit(window.GHM_ORD);

  window.GHS = { ord: ORDAPI, celebrate: celebrate, partySections: partySections, inWin: inWin, source: source, table: table,  T: T, status: status, etaOpen: etaOpen, when: when, summary: summary, show: show, hit: hit, warsawNow: warsawNow, isOpenAt: isOpenAt, windows: windows };
})();
