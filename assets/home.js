/* Grill Hub – home page: renders the big buttons from the admin settings (PL / EN / AR), fire embers, tilt + spotlight. */
(function () {
  "use strict";
  var root = document.getElementById("ghh"), D = window.GHM_HOME;
  if (!root || !D || !D.home) return;
  var H = D.home, LANGS = ["pl", "en", "ar"], lang = D.lang || "pl";
  if (!D.fixed) { try { var sv = localStorage.getItem("gh-lang"); if (sv && LANGS.indexOf(sv) > -1) lang = sv; } catch (e) {} } // /en/ always opens in English
  var reduce = !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);

  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function T(o) { return o ? (o[lang] || o.pl || o.en || "") : ""; }
  var ICONS = {
    bag: '<path d="M6 8h12l-1 11a2 2 0 0 1-2 1.8H9A2 2 0 0 1 7 19L6 8z"/><path d="M9 8V6.5a3 3 0 0 1 6 0V8"/><path class="fill" d="M12 12.2c1.6 1.5 2.3 2.4 2.3 3.4a2.3 2.3 0 0 1-4.6 0c0-.8.5-1.4 1-2 .2.5.5.7.9.8-.2-.8-.1-1.600.4-2.200z"/>',
    gift: '<rect x="3.5" y="8.5" width="17" height="4" rx="1.2"/><path d="M5 12.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-7.500M12 8.500V21"/><path d="M12 8.500C10.500 8.500 8 8 8 6a2 2 0 0 1 2-2c1.700 0 2 2.500 2 4.500zM12 8.500C13.500 8.500 16 8 16 6a2 2 0 0 0-2-2c-1.700 0-2 2.500-2 4.500z"/>',
    star: '<path d="M12 3.500l2.600 5.300 5.800.8-4.200 4.100 1 5.800L12 16.800 6.800 19.500l1-5.800L3.600 9.600l5.800-.8L12 3.500z"/>',
    phone: '<path d="M6.500 3.500h3l1.500 4-2 1.300a11 11 0 0 0 5.200 5.200l1.300-2 4 1.500v3a2 2 0 0 1-2.200 2A15.500 15.500 0 0 1 4.500 5.700 2 2 0 0 1 6.500 3.500z"/>',
    pin: '<path d="M12 21s-6.500-5.700-6.500-11a6.500 6.500 0 0 1 13 0c0 5.300-6.500 11-6.500 11z"/><circle cx="12" cy="10" r="2.400"/>',
    whatsapp: '<path d="M4 20l1.200-4.100A8 8 0 1 1 8.300 19L4 20z"/><path d="M9 8.800c.3 2.600 2.700 5 5.300 5.500l1.200-1.300-1.800-.9-.8.700c-1-.4-1.900-1.300-2.300-2.300l.7-.8L10.400 7.800 9 8.800z"/>',
    instagram: '<rect x="4" y="4" width="16" height="16" rx="4.500"/><circle cx="12" cy="12" r="3.600"/><circle class="fill" cx="16.600" cy="7.400" r="1"/>',
    facebook: '<path d="M14 8.500h2.500V5.200H14a3.500 3.500 0 0 0-3.500 3.500V11H8v3.200h2.500V21h3.300v-6.800h2.500l.5-3.200h-3V8.700c0-.1 0-.2.200-.2z"/>',
    tiktok: '<path d="M14 3v11.2a3.2 3.2 0 1 1-3.2-3.2"/><path d="M14 3c.4 2.6 2 4.200 4.600 4.500"/>',
    link: '<path d="M10 14a4 4 0 0 0 5.700 0l3-3a4 4 0 0 0-5.700-5.700l-1 1"/><path d="M14 10a4 4 0 0 0-5.700 0l-3 3a4 4 0 0 0 5.700 5.700l1-1"/>'
  };
  var G = window.GHS, BZ = D.biz || {};
  /* open / closed pill (Warsaw time, refreshed every 30 s without redrawing the page) */
  function statusInfo() {
    var tx = G.T[lang] || G.T.pl, st = G.status(BZ.hours || []);
    if (st.always) return null;
    if (st.open) {
      var soon = st.left <= 30;
      return { cls: soon ? "is-soon" : "is-open", main: soon ? tx.soon : tx.open, sub: tx.until.replace("{t}", G.show(st.until)) };
    }
    return { cls: "is-closed", main: tx.closed, sub: tx.opens.replace("{w}", G.when(st, lang)).replace("{t}", st.next.at) };
  }
  function statusHTML() {
    var s = G && BZ.status && statusInfo(); if (!s) return "";
    return '<span class="ghh-open ghh-in ' + s.cls + '" style="--i:3" role="status"><i aria-hidden="true"></i><b>' + esc(s.main) + "</b><small>" + esc(s.sub) + "</small></span>";
  }
  function refreshStatus() {
    var el = root.querySelector(".ghh-open"); if (!el || !G) return;
    var s = statusInfo(); if (!s) return;
    el.className = "ghh-open " + s.cls; el.querySelector("b").textContent = s.main; el.querySelector("small").textContent = s.sub;
  }
  function infoHTML() {
    if (!BZ.address || !G) return "";
    var tx = G.T[lang] || G.T.pl, rows = G.summary(BZ.hours || [], lang).map(function (r) { return "<li>" + esc(r) + "</li>"; }).join("");
    return '<section class="ghh-info ghh-in" style="--i:' + ((H.buttons || []).length + 5) + '" aria-label="' + esc(tx.hours) + '"><div><b>' + esc(tx.addr) + '</b><a href="' + esc(BZ.map) + '" target="_blank" rel="noopener noreferrer">' + "<bdi>" + esc(BZ.address + ", " + BZ.city) + '</bdi></a><b class="gap">' + esc(tx.call) + '</b><a href="' + esc(BZ.tel) + '" dir="ltr">' + esc(BZ.phone) + '</a></div>' +
      (rows ? '<div><b>' + esc(tx.hours) + "</b><ul>" + rows + "</ul></div>" : "") + "</section>";
  }
  var DNAME = { wolt: "Wolt", ubereats: "Uber Eats", pyszne: "Pyszne.pl", bolt: "Bolt Food", glovo: "Glovo" }, SNAME = { facebook: "Facebook", instagram: "Instagram", tiktok: "TikTok" };
  function deliveryHTML() {
    var l = H.delivery || []; if (!l.length) return "";
    return '<section class="ghh-deliv ghh-in" style="--i:' + ((H.buttons || []).length + 4) + '" aria-label="' + esc((G.T[lang] || G.T.pl).deliv) + '"><h2>' + esc((G.T[lang] || G.T.pl).deliv) + "</h2><div>" + l.map(function (x) {
      var nm = DNAME[x.id] || x.id;
      return '<a class="dl dl-' + esc(x.id) + (x.img ? " has-img" : "") + '" data-bid="' + esc(x.id) + '" href="' + esc(x.url) + '" target="_blank" rel="noopener noreferrer"' + (x.img ? ' aria-label="' + esc(nm) + '"' : "") + ">" + (x.img ? '<img src="' + esc(x.img) + '" alt="' + esc(nm) + '" loading="lazy">' : esc(nm)) + "</a>";
    }).join("") + "</div></section>";
  }
  function socialHTML() {
    var l = H.social || []; if (!l.length) return "";
    return '<nav class="ghh-soc" aria-label="' + esc((G.T[lang] || G.T.pl).social) + '">' + l.map(function (x) {
      return '<a data-bid="' + esc(x.id) + '" href="' + esc(x.url) + '" target="_blank" rel="noopener noreferrer me" aria-label="' + esc(SNAME[x.id] || x.id) + '" title="' + esc(SNAME[x.id] || x.id) + '"' + (x.img ? ' class="has-img"' : "") + ">" + (x.img ? '<img src="' + esc(x.img) + '" alt="" loading="lazy">' : '<svg viewBox="0 0 24 24" aria-hidden="true">' + (ICONS[x.id] || ICONS.link) + "</svg>") + "</a>";
    }).join("") + "</nav>";
  }
  var RV = { pl: { t: "Opinie z Google", n: "{n} opinii", all: "Zobacz wszystkie opinie", off: "Oferta" }, en: { t: "Google reviews", n: "{n} reviews", all: "See all reviews", off: "Offer" }, ar: { t: "تقييمات جوجل", n: "{n} تقييم", all: "عرض كل التقييمات", off: "عرض" } };
  function stars(n) { var o = ""; for (var i = 1; i <= 5; i++) o += i <= n ? "★" : "☆"; return o; }
  function reviewsHTML() {
    var R = H.reviews; if (!R || (!R.rating && !(R.items || []).length)) return "";
    var tx = RV[lang] || RV.pl, rt = R.rating ? parseFloat(R.rating) : 0;
    var head = '<div class="rv-h"><b>' + esc(tx.t) + "</b>" + (rt ? '<span class="rv-s"><span class="rv-st" aria-hidden="true">' + stars(Math.round(rt)) + '</span><b dir="ltr">' + esc(rt.toFixed(1)) + "</b>" + (R.count ? "<small>(" + esc(tx.n.replace("{n}", R.count)) + ")</small>" : "") + "</span>" : "") + "</div>";
    var items = (R.items || []).map(function (x) { return '<figure class="rv-c"><div class="rv-st" aria-label="' + x.stars + '/5">' + stars(x.stars) + "</div><blockquote>" + esc(x.text) + "</blockquote><figcaption>" + esc(x.name) + "</figcaption></figure>"; }).join("");
    return '<section class="ghh-rev ghh-in" style="--i:' + ((H.buttons || []).length + 3) + '" aria-label="' + esc(tx.t) + '">' + head + (items ? '<div class="rv-l">' + items + "</div>" : "") +
      (R.url ? '<a class="rv-all" data-bid="reviews" href="' + esc(R.url) + '" target="_blank" rel="noopener noreferrer">' + esc(tx.all) + " →</a>" : "") + "</section>";
  }
  var GFT = { pl: "Zamówienie od {min} zł → gratis: {n}", en: "Order {min} zł+ → free {n}", ar: "اطلب بـ {min} zł أو أكثر واحصل مجانًا على {n}" };
  function promoHTML() {
    var out = "", GI = H.gift;
    if (GI && G && G.inWin(GI.win)) GI.tiers.forEach(function (t) { out += '<span class="ghh-promo ghh-in" style="--i:3">🎁 ' + esc((GFT[lang] || GFT.pl).replace("{min}", String(t.min).replace(".", ",")).replace("{n}", T(t.name))) + "</span> "; });
    return out + bundlePromo() + comboPromo();
  }
  function bundlePromo() {
    var C = H.bundle; if (!C || !G || !G.inWin(C.win)) return "";
    var menu = ((H.buttons || []).filter(function (b) { return b.id === "order"; })[0] || {}).url;
    var href = menu ? menu + (menu.indexOf("?") > -1 ? "&" : "?") + "deal=bundle" : "#";
    return '<a class="ghh-promo ghh-in" style="--i:3" href="' + esc(href) + '">🥙 <b>' + esc(T(C.label)) + "</b></a> ";
  }
  function comboPromo() {
    var C = H.combo; if (!C || !G || !G.inWin(C.win)) return "";
    var tx = RV[lang] || RV.pl;
    return '<span class="ghh-promo ghh-in" style="--i:3">🎁 <b>' + esc(T(C.label)) + "</b> · −" + esc(String(C.off).replace(".", ",")) + " zł</span>";
  }
  function aboutHTML() {
    var x = T(H.about); if (!x) return "";
    return '<section class="ghh-about ghh-in" style="--i:' + ((H.buttons || []).length + 6) + '"><h2>' + esc(T(H.about_title)) + "</h2><p>" + esc(x) + "</p></section>";
  }
  function langLinks() { // real links to the Polish and English pages (crawlable)
    var u = D.urls || {}; if (!u.pl || !u.en) return "";
    return '<nav class="ghh-langs" aria-label="Language"><a href="' + esc(u.pl) + '" hreflang="pl" lang="pl" data-nav="pl">Polski</a><a href="' + esc(u.en) + '" hreflang="en" lang="en" data-nav="en">English</a></nav>';
  }
  var ARROW = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';

  function render() {
    root.setAttribute("dir", lang === "ar" ? "rtl" : "ltr"); root.setAttribute("lang", lang);
    var btns = (H.buttons || []).filter(function (b) { return b.show && b.url; });
    var h = '<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs><filter id="ghh-heat" x="-5%" y="-5%" width="110%" height="110%"><feTurbulence type="fractalNoise" baseFrequency="0.012 0.03" numOctaves="2" seed="3" result="t"><animate attributeName="baseFrequency" dur="7s" values="0.012 0.03;0.016 0.045;0.012 0.03" repeatCount="indefinite"/></feTurbulence><feDisplacementMap in="SourceGraphic" in2="t" scale="38" xChannelSelector="R" yChannelSelector="G"/></filter></defs></svg>';
    h += '<div class="ghh-bg" aria-hidden="true"><div class="ghh-fire"><i></i><i></i><i></i><i></i><i></i></div>' + (H.embers && !reduce ? '<canvas class="ghh-em"></canvas>' : "") + '<div class="ghh-vig"></div><div class="ghh-grain"></div></div>';
    h += '<main class="ghh-main"><div class="ghh-top">' + (H.show_lang ? '<div class="ghh-lang" role="group" aria-label="Language">' + LANGS.map(function (l) { return '<button type="button" data-l="' + l + '" aria-pressed="' + (l === lang) + '"' + (l === "ar" ? ' lang="ar"' : "") + ">" + l.toUpperCase() + "</button>"; }).join("") + "</div>" : "") + "</div>";
    h += '<header class="ghh-hero"><img class="ghh-flame ghh-in" style="--i:0" src="' + esc(D.logo) + '" alt="' + esc(D.brand) + '">';
    if (T(H.badge)) h += '<span class="ghh-badge ghh-in" style="--i:1">' + esc(T(H.badge)) + "</span>";
    h += '<h1 class="ghh-title ghh-in" style="--i:2">' + esc(T(H.title) || D.brand) + "</h1>";
    if (T(H.tagline)) h += '<p class="ghh-tag ghh-in" style="--i:3">' + esc(T(H.tagline)) + "</p>";
    h += statusHTML() + promoHTML();
    h += "</header>";
    if (H.notice_show && T(H.notice)) h += '<div class="ghh-notice ghh-in" style="--i:3" role="status">' + esc(T(H.notice)) + "</div>";
    h += '<nav class="ghh-btns" data-n="' + btns.length + '" style="--n:' + Math.max(1, btns.length) + '">' + (btns.length ? btns.map(function (b, i) {
      var st = b.style === "fire" || b.style === "gold" ? "is-" + b.style : "is-glass";
      return '<a class="ghh-btn ' + st + ' ghh-in" style="--i:' + (i + 4) + '" data-bid="' + esc(b.id) + '" href="' + esc(b.url) + '"' + (b.new_tab ? ' target="_blank" rel="noopener noreferrer"' : "") + ">" +
        (b.style === "fire" ? '<span class="shine" aria-hidden="true"></span>' : "") +
        '<span class="ico" aria-hidden="true"><svg viewBox="0 0 24 24">' + (ICONS[b.icon] || ICONS.link) + '</svg></span><span class="tx"><b>' + esc(T(b.label)) + "</b>" + (T(b.sub) ? "<small>" + esc(T(b.sub)) + "</small>" : "") + '</span><span class="arr" aria-hidden="true">' + ARROW + "</span></a>";
    }).join("") : '<p class="ghh-empty">—</p>') + "</nav>";
    h += reviewsHTML() + deliveryHTML() + infoHTML() + aboutHTML();
    h += socialHTML() + langLinks();
    h += "</main>";
    root.innerHTML = h;
    startEmbers();
  }

  /* anonymous counters: page view once per visit, every click on a home button */
  if (G) {
    G.hit(D.hit, { k: "visit", src: G.source() }, "visit");
    G.hit(D.hit, { k: "view", id: "home" }, "home");
    root.addEventListener("click", function (e) { var a = e.target.closest && e.target.closest("[data-bid]"); if (a) G.hit(D.hit, { k: "btn", id: a.getAttribute("data-bid") }); });
    setInterval(refreshStatus, 30000);
  }

  root.addEventListener("click", function (e) { var a = e.target.closest && e.target.closest("[data-nav]"); if (a) { try { localStorage.setItem("gh-lang", a.getAttribute("data-nav")); } catch (x) {} } });

  /* language: shared with the menu page (same localStorage key) */
  root.addEventListener("click", function (e) {
    var b = e.target.closest && e.target.closest("[data-l]"); if (!b) return;
    lang = b.getAttribute("data-l"); try { localStorage.setItem("gh-lang", lang); } catch (x) {}
    root.classList.add("ghh-re"); render();
  });

  /* tilt + spotlight on the buttons */
  root.addEventListener("pointermove", function (e) {
    if (e.pointerType === "touch") return;
    var a = e.target.closest && e.target.closest(".ghh-btn"); if (!a) return;
    var r = a.getBoundingClientRect(), x = (e.clientX - r.left) / r.width, y = (e.clientY - r.top) / r.height;
    a.style.setProperty("--mx", (x * 100) + "%"); a.style.setProperty("--my", (y * 100) + "%");
    if (!reduce) { a.style.setProperty("--ry", ((x - .5) * 9).toFixed(2) + "deg"); a.style.setProperty("--rx", ((.5 - y) * 7).toFixed(2) + "deg"); }
  }, { passive: true });
  root.addEventListener("pointerout", function (e) { var a = e.target.closest && e.target.closest(".ghh-btn"); if (a) { a.style.removeProperty("--rx"); a.style.removeProperty("--ry"); } });

  /* embers */
  var raf = 0, stopEm = null;
  function startEmbers() {
    if (stopEm) stopEm();
    var cv = root.querySelector(".ghh-em"); if (!cv) return;
    var ctx = cv.getContext("2d"), W = 0, Hh = 0, dpr = Math.min(window.devicePixelRatio || 1, 2), ps = [], run = true;
    var spr = document.createElement("canvas"); spr.width = spr.height = 32; var sc = spr.getContext("2d"), g = sc.createRadialGradient(16, 16, 0, 16, 16, 16);
    g.addColorStop(0, "rgba(255,240,190,1)"); g.addColorStop(.25, "rgba(255,150,50,.9)"); g.addColorStop(.6, "rgba(235,50,20,.38)"); g.addColorStop(1, "rgba(225,30,38,0)"); sc.fillStyle = g; sc.fillRect(0, 0, 32, 32);
    function fit() { var r = cv.getBoundingClientRect(); W = r.width; Hh = r.height; cv.width = W * dpr; cv.height = Hh * dpr; ctx.setTransform(dpr, 0, 0, dpr, 0, 0); }
    function mk(first) { return { x: Math.random() * W, y: first ? Math.random() * Hh : Hh + 10, s: 3 + Math.random() * 9, v: .35 + Math.random() * 1.1, sw: Math.random() * 6.28, sa: .3 + Math.random() * .8, l: 0, life: 260 + Math.random() * 260, a: .5 + Math.random() * .5 }; }
    function tick() {
      if (!run) return; ctx.clearRect(0, 0, W, Hh); ctx.globalCompositeOperation = "lighter";
      ps.forEach(function (p, i) {
        p.l++; p.y -= p.v; p.sw += .02; p.x += Math.sin(p.sw) * p.sa;
        var k = p.l / p.life, al = Math.sin(Math.min(1, k) * Math.PI) * p.a;
        if (p.l > p.life || p.y < -20) { ps[i] = mk(false); return; }
        ctx.globalAlpha = Math.max(0, al); var s = p.s * (1 - k * .4); ctx.drawImage(spr, p.x - s, p.y - s, s * 2, s * 2);
      });
      raf = requestAnimationFrame(tick);
    }
    fit(); var n = Math.round(Math.max(26, Math.min(64, W / 14))); for (var i = 0; i < n; i++) ps.push(mk(true));
    var onr = function () { fit(); }, onv = function () { if (document.hidden) { run = false; cancelAnimationFrame(raf); } else if (!run) { run = true; tick(); } };
    window.addEventListener("resize", onr); document.addEventListener("visibilitychange", onv); tick();
    stopEm = function () { run = false; cancelAnimationFrame(raf); window.removeEventListener("resize", onr); document.removeEventListener("visibilitychange", onv); stopEm = null; };
  }
  render();
  if (G && G.partySections) setTimeout(function () {
    var d = { gift: null, combo: null, bundle: null, coupons: H.coupons || [] };
    if (H.gift) d.gift = { popup: H.gift.popup, win: H.gift.win, tiers: (H.gift.tiers || []).map(function (t) { return { min: t.min, name: T(t.name) }; }) };
    if (H.combo) d.combo = { popup: H.combo.popup, win: H.combo.win, label: T(H.combo.label), off: H.combo.off };
    if (H.bundle) d.bundle = { popup: H.bundle.popup, win: H.bundle.win, label: T(H.bundle.label), n: H.bundle.n, price: H.bundle.price };
    var pd = G.partySections(lang, d);
    if (!pd) return;
    var menu = ((H.buttons || []).filter(function (b) { return b.id === "order"; })[0] || {}).url;
    G.celebrate({ lang: lang, title: pd.title, sections: pd.sections, onGo: menu ? function () { location.href = menu; } : null });
  }, 900);
})();
