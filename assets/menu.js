
(function () {
  /* ---------- Data (comes from the WordPress admin) ---------- */
  var D = window.GHM_DATA || {}, S = D.settings || {}, BASE = window.GHM_BASE || "";
  var CASHIER = String(S.whatsapp || "").replace(/\D/g, "");
  var RESTAURANT = S.restaurant || "MY RESTAURANT";
  var PHONE = (CASHIER.length === 11 && CASHIER.indexOf("48") === 0) ? "+48 " + CASHIER.slice(2, 5) + " " + CASHIER.slice(5, 8) + " " + CASHIER.slice(8) : (CASHIER ? "+" + CASHIER : "");

  var LANGS = { ar: "العربية", pl: "Polski", en: "English" };
  var LANG = LANGS[S.default_lang] ? S.default_lang : "pl";
  try { var savedLang = localStorage.getItem("gh-lang"); if (LANGS[savedLang]) LANG = savedLang; } catch (e) {}

  var STR = window.GHM_STR || { pl: {}, en: {}, ar: {} };
  var OV = D.strings || {};
  Object.keys(OV).forEach(function (lg) { if (!STR[lg]) return; Object.keys(OV[lg] || {}).forEach(function (k) { if (OV[lg][k] !== "" && OV[lg][k] != null) STR[lg][k] = OV[lg][k]; }); });
  function L(k, lang) { var d = STR[lang || LANG]; return (d && d[k] != null) ? d[k] : (STR.pl[k] != null ? STR.pl[k] : k); }
  function fmt(s, o) { return s.replace(/\{(\w+)\}/g, function (m, k) { return o[k]; }); }
  function ingName(id, lang) { return L("ing_" + id, lang); }

  function url(u) { if (!u) return ""; return /^(https?:)?\/\/|^\/|^data:/.test(u) ? u : BASE + u; }
  var CATS = (D.builder && D.builder.enabled === false ? [] : [{ id: "build" }]).concat(D.cats || []);
  var ITEMS = [], IMAP = {};
  (D.items || []).forEach(function (r) {
    if (r.hidden) return;
    var n = r.n || {}, d = r.d || {}, vs = (r.v && r.v.length) ? r.v : [{ l: { pl: "" }, p: 0 }];
    var it = {
      id: +r.id, cat: r.cat, soon: !!r.soon, n: n, d: d, name: n.pl || "", desc: d.pl || "", img: r.img || "",
      v: vs.map(function (x) { return [(x.l && x.l.pl) || "", parseFloat(x.p) || 0]; }),
      vl: vs.map(function (x) { return x.l || {}; }),
      vimg: vs.map(function (x) { return x.img || ""; }), dv: Math.max(0, Math.min(vs.length - 1, parseInt(r.dv, 10) || 0))
    };
    ITEMS.push(it); IMAP[it.id] = it;
  });
  function imgUrl(it, vi) { return url(it.vimg[vi] || it.img); }

  function catName(c) { if (c.id === "build") return L("cat_build"); if (c.id === "deals") return L("cat_deals"); return (c.name && (c.name[LANG] || c.name.pl)) || c.id; }
  function iName(it) { if (it.custom) return L("custom_name"); return it.n[LANG] || it.name; }
  function iDesc(it) { if (it.custom) return it.cfg ? bDescCfg(it.cfg, LANG) : (it.desc || ""); return it.d[LANG] || it.desc; }
  function vLab(it, i) {
    var l = it.v[i][0];
    if (it.custom) return l === "Duży" ? L("size_Duzy") : l === "Mały" ? L("size_Maly") : l;
    return (it.vl[i] && it.vl[i][LANG]) || l;
  }
  function iAlias(it) { return /kebab/i.test(it.name) ? " shawarma shawerma شاورما كباب مشاوي" : ""; }

  var cart = {};   // key "id|vi" -> qty
  var sel = {};    // id -> selected variant index
  var mode = "dine";
  var query = "";
  var customs = { next: 1000, items: {}, sig: {} };
  function getItem(id) { return id >= 1000 ? customs.items[id] : IMAP[id]; }

  function $(id) { return document.getElementById(id); }
  function esc(s) { return String(s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function round2(n) { return Math.round(n * 100) / 100; }
  function money(n) { n = round2(n); return (n % 1 === 0 ? String(n) : n.toFixed(2).replace(".", ",")) + " zł"; }
  function unit(it, vi) { return it.v[vi][1]; }
  function vlabel(it, vi) { return it.v[vi][0]; }
  function lines() {
    var out = [];
    Object.keys(cart).forEach(function (k) {
      var p = k.split("|"), it = getItem(+p[0]), vi = +p[1];
      if (it && it.v[vi] && cart[k] > 0) out.push({ key: k, it: it, vi: vi, qty: cart[k] });
    });
    return out;
  }
  var CB = S.combo && S.combo.on && +S.combo.off > 0 ? S.combo : null, cpn = null, cpMsg = "", cpBusy = false;
  var BD = S.bundle && S.bundle.on && +S.bundle.n >= 2 ? S.bundle : null;
  // "N sandwiches for X zł": groups of N (most expensive first) cost X. Returns the discount
  function bundleOff() {
    if (!BD || !(G && G.inWin(BD.win))) return 0;
    var pr = []; lines().forEach(function (l) { var id = String(l.it.id); if ((BD.cats.indexOf(l.it.cat) > -1 || (BD.inc || []).indexOf(id) > -1) && (BD.exc || []).indexOf(id) < 0) for (var i = 0; i < l.qty; i++) pr.push(unit(l.it, l.vi)); });
    pr.sort(function (a, b) { return b - a; });
    var off = 0, n = +BD.n; for (var i = 0; i + n <= pr.length; i += n) off += Math.max(0, pr.slice(i, i + n).reduce(function (a, b) { return a + b; }, 0) - (+BD.price));
    return round2(off);
  }
  function comboOn() { return !!(CB && G && G.inWin(CB.win)); }
  // "spend X, get a dish free": returns {it, vi, next:{min, it, vi}} for the highest tier reached (after discounts) and the next one
  function giftFor(base) {
    var GF = S.gift, cur = null, next = null;
    if (!GF || !GF.on || !GF.tiers || !GF.tiers.length || !(G && G.inWin(GF.win))) return { cur: null, next: null };
    GF.tiers.forEach(function (t) { var it = IMAP[+t.id]; if (!it || !it.v[+t.vi]) return; if (base + 0.005 >= +t.min) cur = { it: it, vi: +t.vi }; else if (!next) next = { min: +t.min, it: it, vi: +t.vi }; });
    return { cur: cur, next: next };
  }
  function totals() {
    var c = 0, t = 0, m = 0, d = 0;
    lines().forEach(function (l) {
      c += l.qty; t += l.qty * unit(l.it, l.vi);
      if (CB && CB.main_cats.indexOf(l.it.cat) > -1) m += l.qty;
      if (CB && CB.drink_cats.indexOf(l.it.cat) > -1) d += l.qty;
    });
    t = round2(t);
    var F = S.fees || {}, dn = 0;
    var bo = c ? bundleOff() : 0, cb0 = c && comboOn() ? Math.min(t, Math.min(m, d) * (+CB.off)) : 0, cp0 = 0;
    if (bo > cb0) cb0 = Math.min(t, bo);
    if (cpn && c) { var b0 = t - cb0; if (b0 >= cpn.min) cp0 = round2(cpn.kind === "percent" ? b0 * cpn.value / 100 : Math.min(b0, cpn.value)); }
    var gf = c ? giftFor(t - cb0 - cp0) : { cur: null, next: null };
    if (F.dep_on) lines().forEach(function (l) { if ((F.dep_cats || []).indexOf(l.it.cat) > -1) dn += l.qty; });
    if (F.dep_on && gf.cur && (F.dep_cats || []).indexOf(gf.cur.it.cat) > -1) dn += 1;
    var dep = F.dep_on ? round2(dn * (+F.dep || 0)) : 0, pk = c && F.pack_on && mode === "pick" ? round2(+F.pack || 0) : 0;
    var cb = c && comboOn() ? Math.min(t, Math.min(m, d) * (+CB.off)) : 0, cp = 0, byBundle = bo > cb;
    if (byBundle) cb = Math.min(t, bo);
    if (cpn && c) { var base = t - cb; if (base >= cpn.min) cp = round2(cpn.kind === "percent" ? base * cpn.value / 100 : Math.min(base, cpn.value)); }
    return { count: c, sub: t, combo: round2(cb), bundle: byBundle, coupon: cp, dep: dep, pack: pk, gift: gf.cur, giftNext: gf.next, total: round2(t - cb - cp + dep + pk), m: m, d: d };
  }
  function save() { try { localStorage.setItem("gh-cart", JSON.stringify(cart)); localStorage.setItem("gh-custom", JSON.stringify(customs)); } catch (e) {} }
  function load() { try { var c = JSON.parse(localStorage.getItem("gh-cart") || "{}"); if (c && typeof c === "object") cart = c; var cu = JSON.parse(localStorage.getItem("gh-custom") || "null"); if (cu && cu.items) customs = cu; Object.keys(customs.items).forEach(function (k) { if (!customs.items[k].cfg) { delete customs.items[k]; Object.keys(cart).forEach(function (ck) { if (ck.split("|")[0] === k) delete cart[ck]; }); } }); } catch (e) {} }

  /* ---------- Menu ---------- */
  function renderChips() {
    $("chips").innerHTML = CATS.map(function (c) {
      return '<button type="button" class="chip' + (c.id === "build" ? " bchip" : "") + '" data-cat="' + c.id + '">' + esc(catName(c)) + "</button>";
    }).join("");
  }

  function ctlHTML(key, q) {
    return q > 0
      ? '<div class="step"><button type="button" data-dec="' + key + '" aria-label="' + L("rem1") + '">−</button><b class="num">' + q + '</b><button type="button" class="plus" data-inc="' + key + '" aria-label="' + L("add1") + '">+</button></div>'
      : '<button type="button" class="add" data-inc="' + key + '">' + L("add") + "</button>";
  }
  // Update only the +/− controls of the visible cards (no re-render, no layout shift)
  function patchCards() {
    Array.prototype.forEach.call($("menu").querySelectorAll(".card[data-id]"), function (el) {
      var it = getItem(+el.dataset.id); if (!it) return;
      var key = it.id + "|" + selOf(it), q = cart[key] || 0, c = el.querySelector(".ctl");
      if (c && c.getAttribute("data-q") !== String(q) + "|" + key) { c.setAttribute("data-q", q + "|" + key); c.innerHTML = ctlHTML(key, q); }
    });
  }
  function selOf(it) { return sel[it.id] != null ? sel[it.id] : (it.dv || 0); }
  // options written as "Meat · Size" for every combination are shown as two rows of buttons (meat, size) instead of one long list
  function gridOf(it) {
    if (it._g !== undefined) return it._g;
    var A = [], B = [], ok = it.v.length > 1;
    it.v.forEach(function (x) { var p = String(x[0]).split(" · "); if (p.length !== 2) { ok = false; return; } if (A.indexOf(p[0]) < 0) A.push(p[0]); if (B.indexOf(p[1]) < 0) B.push(p[1]); });
    if (ok && A.length * B.length !== it.v.length) ok = false;
    var g = null;
    if (ok) {
      var at = function (a, b) { for (var i = 0; i < it.v.length; i++) if (it.v[i][0] === a + " · " + b) return i; return -1; };
      ok = A.every(function (a) { return B.every(function (b) { return at(a, b) > -1; }); });
      if (ok) g = { A: A, B: B, at: at };
    }
    it._g = g; return g;
  }
  function partLab(it, idx, k) { var l = it.vl[idx] && (it.vl[idx][LANG] || it.vl[idx].pl); var p = String(l || it.v[idx][0]).split(" · "); return p[k] || p[0]; }
  function gridHTML(it, vi) {
    var g = gridOf(it), cur = it.v[vi][0].split(" · "), out = "";
    [[g.A, 0], [g.B, 1]].forEach(function (ax) {
      var list = ax[0], k = ax[1], prices = list.map(function (v) { var i = k === 0 ? g.at(v, cur[1]) : g.at(cur[0], v); return unit(it, i); }), differ = prices.some(function (p) { return p !== prices[0]; });
      out += '<div class="opts" role="group" aria-label="' + esc(L("opt_aria") + iName(it)) + '">' + list.map(function (v, n) {
        var i = k === 0 ? g.at(v, cur[1]) : g.at(cur[0], v);
        return '<button type="button" class="opt" data-opt="' + it.id + "|" + i + '" aria-pressed="' + (v === cur[k]) + '">' + esc(partLab(it, i, k)) + "</button>";
      }).join("") + "</div>";
    });
    return out;
  }
  // Is this dish covered by the "N for X zł" bundle? Same rule as the server discount (cats or inc-list, minus exc-list)
  function inDeal(it) {
    if (!BD || !(G && G.inWin(BD.win))) return false;
    var id = String(it.id);
    return ((BD.cats || []).indexOf(it.cat) > -1 || (BD.inc || []).indexOf(id) > -1) && (BD.exc || []).indexOf(id) < 0;
  }
  // Bundle deal badge (1.17.32): a small pill next to the Add button, with fireworks bursting out of it
  function dealBadge(it) {
    if (!inDeal(it)) return "";
    var p = +BD.price, ps = p % 1 === 0 ? String(p) : String(p).replace(".", ",");
    var fw = [[-15, -13, "#e11e26"], [0, -17, "#fff"], [15, -13, "#ff5a00"], [-17, 2, "#fff"], [17, 2, "#e11e26"], [0, 9, "#ff5a00"]].map(function (s, i) {
      return '<b class="fw" style="--tx:' + s[0] + 'px;--ty:' + s[1] + 'px;--c:' + s[2] + ';animation-delay:' + (i * 0.07) + 's"></b>';
    }).join("");
    return '<span class="deal-badge"><i aria-hidden="true">🎉</i>' + esc(fmt(L("deal_badge"), { n: +BD.n, p: ps })) + fw + "</span>";
  }
  function cardHTML(it) {
    var vi = selOf(it);
    var key = it.id + "|" + vi;
    var q = cart[key] || 0;
    var prices = it.v.map(function (x) { return x[1]; });
    var differ = prices.some(function (p) { return p !== prices[0]; });
    var opts = "";
    if (it.v.length > 1 && gridOf(it)) opts = gridHTML(it, vi);
    else if (it.v.length > 1) {
      opts = '<div class="opts" role="group" aria-label="' + esc(L("opt_aria") + iName(it)) + '">' + it.v.map(function (x, i) {
        return '<button type="button" class="opt" data-opt="' + it.id + "|" + i + '" aria-pressed="' + (i === vi) + '">' + esc(vLab(it, i)) + "</button>";
      }).join("") + "</div>";
    }
    var ctl = ctlHTML(key, q);
    var im = imgUrl(it, vi);
    var p = unit(it, vi);
    // Coming soon (1.17.33): the dish is shown with its prices, but there is no order button yet
    var buyCtl = it.soon
      ? '<span class="soon-pill">' + esc(L("coming_soon")) + "</span>"
      : '<span class="ctl" data-q="' + q + "|" + key + '">' + ctl + "</span>" + dealBadge(it);
    return '<article class="card" data-id="' + it.id + '"><div class="body"><h3>' + esc(iName(it)) + "</h3>" +
      (iDesc(it) ? "<p>" + esc(iDesc(it)) + "</p>" : "") + opts +
      '<div class="buy"><span class="price num">' + (p % 1 === 0 ? p : String(p).replace(".", ",")) + "<small>zł</small></span><span class=\"buy-ctl\">" + buyCtl + "</span></div></div>" +
      (im ? '<div class="pic" style="background-image:url(\'' + esc(im) + '\')" role="img" aria-label="' + esc(iName(it)) + '"></div>' : "") + "</article>";
  }

  function renderMenu() {
    var q = query.trim().toLowerCase();
    var html = "";
    CATS.forEach(function (c) {
      if (c.id === "build") { if (!q || ("zbuduj własny wrap kreator build builder " + L("build_title").replace(/<[^>]+>/g, "")).toLowerCase().indexOf(q) > -1) html += '<section class="sec" id="s-build" data-sec="build">' + secHead(c) + buildCardHTML() + "</section>"; return; }
      var list = ITEMS.filter(function (it) {
        if (c.id === "deals" ? !inDeal(it) : it.cat !== c.id) return false;
        if (!q) return true;
        return (iName(it) + " " + iDesc(it) + " " + catName(c) + " " + it.name + " " + it.desc + iAlias(it) + " " + it.v.map(function (x, i) { return x[0] + " " + vLab(it, i); }).join(" ")).toLowerCase().indexOf(q) > -1;
      });
      if (!list.length) return;
      html += '<section class="sec" id="s-' + c.id + '" data-sec="' + c.id + '">' + secHead(c, list.length) + '<div class="cards">' + list.map(cardHTML).join("") + "</div></section>";
    });
    $("menu").innerHTML = html || '<div class="empty"><b>' + esc(L("none_t")) + "</b>" + esc(L("none_s")) + "</div>";
    var shown = {};
    (html.match(/data-sec="(\w+)"/g) || []).forEach(function (m) { shown[m.slice(10, -1)] = 1; });
    Array.prototype.forEach.call($("chips").children, function (b) { b.hidden = !shown[b.dataset.cat]; });
    var mini = $("menu").querySelector("[data-mini]"); if (mini) { var ms = buildStage(mini, PRESET, true); paintStage(ms, PRESET, true); }
    spy();
  }

  function renderBar() {
    var t = totals();
    $("fab").hidden = t.count === 0;
    $("fabCnt").textContent = t.count;
    $("fabTot").textContent = money(t.total);
  }

  function changed(bump) {
    save(); patchCards(); renderBar();
    if (!$("scrim").hidden) renderCart();
  }
  function inc(key) { cart[key] = (cart[key] || 0) + 1; changed(true); }
  function dec(key) { if (!cart[key]) return; cart[key]--; if (cart[key] <= 0) delete cart[key]; changed(false); }

  /* ---------- Scroll spy ---------- */
  var spyQueued = false;
  function spy() {
    if (spyQueued) return; spyQueued = true;
    requestAnimationFrame(function () {
      spyQueued = false;
      var secs = document.querySelectorAll("[data-sec]"), cur = null, edge = $("bar").getBoundingClientRect().bottom + 20;
      secs.forEach(function (s) { if (s.getBoundingClientRect().top <= edge) cur = s.dataset.sec; });
      if (!cur && secs.length) cur = secs[0].dataset.sec;
      Array.prototype.forEach.call($("chips").children, function (b) { b.setAttribute("aria-current", b.dataset.cat === cur ? "true" : "false"); });
      var act = $("chips").querySelector('[aria-current="true"]');
      if (act) { var box = $("chips"), r = act.getBoundingClientRect(), b = box.getBoundingClientRect(); if (r.left < b.left || r.right > b.right) box.scrollBy({ left: (r.left + r.right) / 2 - (b.left + b.right) / 2 }); }
    });
  }
  function measure() { $("app").style.setProperty("--hh", $("bar").offsetHeight + "px"); }

  /* ---------- Opening hours (Warsaw time) ---------- */
  var G = window.GHS, HRS = S.hours || [], MODE = S.closed_mode || "off", forceClosed = false;
  // Deals section (1.17.32): a virtual first category with only the bundle-covered dishes, straight from the saved menu data
  if (ITEMS.some(function (it) { return inDeal(it); })) CATS.unshift({ id: "deals" });
  function closedState() {
    if (!G || MODE === "off" || !HRS.length) return null;
    var st = G.status(HRS);
    if (st.open) return st.left <= 30 ? { soon: st.left } : null;
    return { st: st };
  }
  function blocked() { var c = closedState(); return MODE === "block" && (forceClosed || !!(c && c.st)); }
  function closedHTML(c) {
    if (c.soon) return "<b>" + esc(fmt(L("closing_soon"), { n: c.soon })) + "</b>";
    return "<b>" + esc(L("closed_t")) + "</b><span>" + esc(fmt(L("closed_open"), { w: G.when(c.st, LANG), t: c.st.next.at })) + "</span><span>" + esc(MODE === "block" ? L("closed_block") : L("closed_warn")) + "</span><em>" + esc(L("hours_lbl")) + " " + esc(G.summary(HRS, LANG).join(" · ")) + "</em>";
  }
  function renderClosed() {
    var c = closedState();
    ["closedBar", "closedCart"].forEach(function (id) {
      var el = $(id); if (!el) return;
      if (!c) { el.hidden = true; return; }
      el.hidden = false; el.className = "cbar" + (c.soon ? " soon" : ""); el.innerHTML = closedHTML(c);
    });
  }
  var wasBlocked = null;
  function hoursTick() { renderClosed(); var b = blocked(); if (b !== wasBlocked) { wasBlocked = b; if (!$("scrim").hidden) renderCart(); } }
  function canSend() { return totals().count > 0 && !blocked(); }

  /* ---------- Cart suggestions: fries + a drink ---------- */
  var DRINKCATS = { drink: 1, juice: 1 };
  function upsellList() {
    var ls = lines(); if (!S.upsell_on || !ls.length) return [];
    var hasDrink = ls.some(function (l) { return DRINKCATS[l.it.cat]; }), out = [];
    (S.upsell_ids || []).forEach(function (id) {
      var it = IMAP[+id]; if (!it) return;
      if (ls.some(function (l) { return l.it.id === it.id; })) return;
      if (DRINKCATS[it.cat] && hasDrink) return;
      out.push(it);
    });
    return out.slice(0, 3);
  }
  function renderUpsell() {
    var el = $("upsell"), list = upsellList();
    if (!list.length) { el.hidden = true; el.innerHTML = ""; return; }
    el.hidden = false;
    el.innerHTML = '<div class="up-h"><b>' + esc(L("up_title")) + "</b><span>" + esc(L("up_sub")) + '</span></div><div class="up-l">' + list.map(function (it) {
      var im = imgUrl(it, 0);
      return '<button type="button" class="up" data-inc="' + it.id + '|0" data-up="' + it.id + '"><i' + (im ? ' style="background-image:url(\'' + esc(im) + '\')"' : "") + '></i><span class="n">' + esc(iName(it)) + (vLab(it, 0) ? " · " + esc(vLab(it, 0)) : "") + '</span><span class="p num">+' + money(unit(it, 0)) + "</span></button>";
    }).join("") + "</div>";
  }
  function trackOrder() {
    if (!G) return;
    G.hit(S.hit_url, { k: "order", src: G.source(), lines: lines().map(function (l) { return l.it.custom ? { custom: true, cfg: l.it.cfg, qty: l.qty } : { id: l.it.id, vi: l.vi, qty: l.qty }; }) });
  }

  /* ---------- Cart sheet ---------- */
  function comboLabel(isB) { var l = isB ? BD && BD.label : CB && CB.label; return (l && (l[LANG] || l.pl)) || L("disc_combo"); }
  function renderOffers(t) {
    var el = $("disc"), h = "";
    if (t.count && (t.combo || t.coupon || t.dep || t.pack || t.gift)) h += '<div class="dl"><span>' + esc(L("sub_lbl")) + '</span><b class="num">' + money(t.sub) + "</b></div>";
    if (t.combo) h += '<div class="dl ok"><span>' + esc(comboLabel(t.bundle)) + '</span><b class="num">−' + money(t.combo) + "</b></div>";
    if (t.coupon) h += '<div class="dl ok"><span>' + esc(fmt(L("disc_code"), { code: cpn.code })) + '</span><b class="num">−' + money(t.coupon) + "</b></div>";
    if (t.count && t.gift) h += '<div class="dl ok"><span>🎁 ' + esc(fmt(L("gift_line"), { n: iName(t.gift.it) + (vLab(t.gift.it, t.gift.vi) ? " (" + vLab(t.gift.it, t.gift.vi) + ")" : "") })) + '</span><b class="num">0 zł</b></div>';
    if (t.count && t.giftNext) h += '<div class="chint">🎁 ' + esc(fmt(L("gift_hint"), { x: money(round2(t.giftNext.min - (t.sub - t.combo - t.coupon))), n: iName(t.giftNext.it) })) + "</div>";
    if (t.count && t.dep) h += '<div class="dl"><span>' + esc(L("fee_dep")) + '</span><b class="num">+' + money(t.dep) + "</b></div>";
    if (t.count && t.pack) h += '<div class="dl"><span>' + esc(L("fee_pack")) + '</span><b class="num">+' + money(t.pack) + "</b></div>";
    if (t.count && comboOn() && !t.combo && (t.m || t.d) && t.m !== t.d) h += '<div class="chint">' + esc(fmt(L(t.m > t.d ? "combo_hint" : "combo_hint2"), { x: money(+CB.off) })) + "</div>";
    el.innerHTML = h; el.hidden = !h;
    var box = $("cpBox"); box.hidden = !S.coupons_on || !t.count;
    if (!box.hidden) {
      $("cpIn").hidden = !!cpn; $("cpGo").hidden = !!cpn; $("cpDel").hidden = !cpn;
      $("cpL").textContent = L("cp_label"); $("cpIn").placeholder = L("cp_ph"); $("cpGo").textContent = L("cp_apply"); $("cpDel").textContent = cpn ? cpn.code + " ✕" : "";
      var msg = cpMsg;
      if (cpn && t.count && t.sub - t.combo < cpn.min) msg = fmt(L("cp_min"), { x: money(cpn.min) });
      else if (cpn && !msg) msg = fmt(L("cp_ok"), { code: cpn.code });
      $("cpMsg").textContent = msg; $("cpMsg").className = "cpm" + (cpn && !cpMsg && t.coupon ? " ok" : (msg ? " bad" : ""));
    }
  }
  function applyCoupon() {
    var code = $("cpIn").value.trim(); if (!code || cpBusy) return;
    cpBusy = true; $("cpGo").setAttribute("aria-disabled", "true");
    fetch(S.coupon_url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ code: code }) })
      .then(function (r) { return r.json(); })
      .then(function (j) { cpBusy = false; if (j && j.ok) { cpn = { code: j.code, kind: j.kind, value: +j.value, min: +j.min }; cpMsg = ""; try { sessionStorage.setItem("gh-cp", code); } catch (e) {} } else { cpn = null; cpMsg = L("cp_" + (j && j.error === "expired" ? "expired" : j && j.error === "used" ? "used" : "invalid")); } changed(true); renderCart(); })
      .catch(function () { cpBusy = false; cpMsg = L("cp_net"); renderCart(); });
  }
  function renderCart() {
    var ls = lines(), t = totals();
    $("lines").innerHTML = ls.length ? ls.map(function (l) {
      return '<div class="line"><div><b>' + esc(iName(l.it)) + "</b>" + (l.it.custom ? "<em>" + esc(iDesc(l.it)) + "</em>" : "") + "<em>" + (vLab(l.it, l.vi) ? esc(vLab(l.it, l.vi)) + " · " : "") + '<span class="num">' + money(unit(l.it, l.vi)) + "</span> " + esc(L("per_unit")) + '</em></div><span class="sum num">' + money(l.qty * unit(l.it, l.vi)) +
        '</span><div class="step"><button type="button" data-dec="' + l.key + '" aria-label="' + L("rem1") + '">−</button><b class="num">' + l.qty + '</b><button type="button" class="plus" data-inc="' + l.key + '" aria-label="' + L("add1") + '">+</button></div></div>';
    }).join("") : '<div class="empty"><b>' + esc(L("cart_empty_t")) + "</b>" + esc(L("cart_empty_s")) + "</div>";
    $("sheetTot").textContent = money(t.total);
    renderOffers(t);
    $("send").setAttribute("aria-disabled", canSend() ? "false" : "true");
    $("sendApp").setAttribute("aria-disabled", canSend() ? "false" : "true");
    renderUpsell(); renderClosed();
    $("mDine").setAttribute("aria-pressed", mode === "dine");
    $("mPick").setAttribute("aria-pressed", mode === "pick");
    $("gEta").hidden = mode !== "pick";
  }
  function openCart() { $("scrim").hidden = false; document.body.style.overflow = "hidden"; askGeo(); renderCart(); }
  function closeCart() { $("scrim").hidden = true; document.body.style.overflow = ""; }

  function fieldErr(g, e, msg) { $(g).classList.toggle("bad", !!msg); $(e).textContent = msg || ""; return !msg; }
  function validate() {
    var ok = true;
    if (!totals().count) return false;
    ok = fieldErr("gName", "eName", $("fName").value.trim() ? "" : L("e_name")) && ok;
    ok = fieldErr("gPhone", "ePhone", $("fPhone").value.replace(/\D/g, "").length >= 7 ? "" : L("e_phone")) && ok;
    if (mode !== "dine") {
      var eta = $("fEta").value, etaMsg = !eta ? L("e_eta") : (MODE !== "off" && G && HRS.length && !G.etaOpen(HRS, eta) ? fmt(L("e_eta_closed"), { h: G.summary(HRS, LANG).join(" · ") }) : "");
      ok = fieldErr("gEta", "eEta", etaMsg) && ok;
    }
    return ok;
  }
  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function em(cp) { return String.fromCodePoint(cp); }
  /* Tracking link for an order the server just created: /track/?id=<order id>&t=<secret token> — the same link the /track/ page reads back. */
  function trackLink(j) {
    if (!j || !j.id || !j.tok || !S.track_url) return "";
    return S.track_url + (S.track_url.indexOf("?") > -1 ? "&" : "?") + "id=" + j.id + "&t=" + encodeURIComponent(j.tok);
  }
  function buildMessage(ord) {
    var d = new Date(), ls = lines(), t = totals(), bar = "━━━━━━━━━━━━━━";
    var m = [em(0x1F525) + " *" + RESTAURANT + " – NOWE ZAMÓWIENIE*", pad(d.getDate()) + "." + pad(d.getMonth() + 1) + "." + d.getFullYear() + " · " + pad(d.getHours()) + ":" + pad(d.getMinutes()), bar];
    if (mode === "dine") {
      m.push(em(0x1F37D) + " *Na miejscu*", em(0x1F464) + " Imię: *" + $("fName").value.trim() + "*", em(0x1F4DE) + " Tel.: " + $("fPhone").value.trim());
    } else {
      m.push(em(0x1F6CD) + " *Odbiór osobisty*", em(0x1F464) + " Imię: *" + $("fName").value.trim() + "*", em(0x1F4DE) + " Tel.: " + $("fPhone").value.trim(), em(0x23F0) + " Godzina odbioru: *" + $("fEta").value + "*");
    }
    m.push(bar, "*POZYCJE*");
    ls.forEach(function (l, i) {
      var v = vlabel(l.it, l.vi);
      m.push((i + 1) + ". " + l.qty + " × " + l.it.name + (v ? " (" + v + ")" : "") + " — " + money(l.qty * unit(l.it, l.vi)));
      if (l.it.custom) m.push("      ↳ " + (l.it.cfg ? bDescCfg(l.it.cfg, "pl") : l.it.desc));
    });
    if (t.combo) m.push(bar, em(0x1F381) + " " + L("disc_combo", "pl") + ": −" + money(t.combo));
    if (t.coupon) m.push(em(0x1F3F7) + " " + fmt(L("disc_code", "pl"), { code: cpn.code }) + ": −" + money(t.coupon));
    if (t.gift) m.push(bar, em(0x1F381) + " GRATIS: " + t.gift.it.name + (vlabel(t.gift.it, t.gift.vi) ? " (" + vlabel(t.gift.it, t.gift.vi) + ")" : ""));
    if (t.dep) m.push(bar, em(0x1F9F4) + " " + L("fee_dep", "pl") + ": +" + money(t.dep));
    if (t.pack) m.push(em(0x1F961) + " " + L("fee_pack", "pl") + ": +" + money(t.pack));
    m.push(bar, em(0x1F4B0) + " *RAZEM: " + money(t.total) + "*  (szt.: " + t.count + ")");
    if (ord && ord.number) {
      m.push(bar, em(0x1F522) + " *Numer zamówienia: #" + ord.number + "*");
      var tl = trackLink(ord);
      if (tl) m.push(em(0x1F50D) + " *Śledzenie zamówienia:* " + tl);
    }
    var n = $("fNotes").value.trim();
    if (n) m.push(bar, em(0x1F4DD) + " *Uwagi:* " + n);
    return m.join("\n");
  }
  function waHref(j) { return "https://wa.me/" + CASHIER + "?text=" + encodeURIComponent(buildMessage(j)); }
  function waAppHref(j) { return "whatsapp://send?phone=" + CASHIER + "&text=" + encodeURIComponent(buildMessage(j)); }

  var toastT;
  function toast(msg) { var t = $("toast"); t.textContent = msg; t.hidden = false; clearTimeout(toastT); toastT = setTimeout(function () { t.hidden = true; }, 2600); }

  /* ---------- Events ---------- */
  document.addEventListener("click", function (e) {
    var t = e.target.closest("button, a");
    if (!t) return;
    if (t.dataset.up && G) G.hit(S.hit_url, { k: "up", id: t.dataset.up });
    if (t.dataset.inc) return inc(t.dataset.inc);
    if (t.dataset.dec) return dec(t.dataset.dec);
    if (t.dataset.build) return openBuilder();
    if (t.dataset.opt) { var p = t.dataset.opt.split("|"); sel[+p[0]] = +p[1]; return renderMenu(); }
    if (t.dataset.cat) {
      query = ""; if ($("q").value) { $("q").value = ""; $("qx").hidden = true; renderMenu(); }
      var s = $("s-" + t.dataset.cat); if (s) s.scrollIntoView({ behavior: "smooth", block: "start" });
      return;
    }
  });
  $("q").addEventListener("input", function () { query = this.value; $("qx").hidden = !query; renderMenu(); });
  $("qx").addEventListener("click", function () { $("q").value = ""; query = ""; this.hidden = true; renderMenu(); $("q").focus(); });
  // phones: the search is a small magnifier; tapping it opens the field and hides the restaurant name text (it comes back when the search is closed)
  (function () {
    var row = document.querySelector(".row1"), q = $("q");
    function sync() { row.classList.toggle("srch", document.activeElement === q || !!q.value); }
    q.addEventListener("focus", sync); q.addEventListener("input", sync);
    q.addEventListener("blur", function () { setTimeout(sync, 120); });
    $("qx").addEventListener("click", function () { setTimeout(sync, 0); });
  })();
  $("openCart").addEventListener("click", openCart);
  $("closeCart").addEventListener("click", closeCart);
  $("scrim").addEventListener("click", function (e) { if (e.target === this) closeCart(); });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape") { if (!$("bov").hidden) closeBuilder(); else if (!$("scrim").hidden) closeCart(); } });
  $("mDine").addEventListener("click", function () { mode = "dine"; renderCart(); renderBar(); });
  $("mPick").addEventListener("click", function () { mode = "pick"; renderCart(); renderBar(); });
  $("form").addEventListener("submit", function (e) { e.preventDefault(); });
  function guardSend(build, app) {
    return function (e) {
      if (!totals().count) { e.preventDefault(); toast(L("t_empty")); return; }
      if (blocked()) { e.preventDefault(); renderClosed(); toast(L("closed_toast")); return; }
      if (!validate()) {
        e.preventDefault();
        var bad = document.querySelector(".fld.bad:not([hidden]) input"); if (bad) bad.focus();
        return;
      }
      if (app || !DIRECT || failed) trackOrder(); // orders sent to the kitchen page are counted by the server
      /* In DIRECT mode the order is created on the server first; the WhatsApp message (with its number + tracking link) opens once the POST answers — the submitOrder listener below does that. */
      if (!DIRECT || failed) this.href = build();
    };
  }
  $("send").addEventListener("click", guardSend(waHref, false));
  $("sendApp").addEventListener("click", guardSend(waAppHref, true));
  $("copyNum").addEventListener("click", function () {
    function fb() { toast(L("t_num_fail")); }
    try { navigator.clipboard.writeText("+" + CASHIER).then(function () { toast(L("t_num_ok")); }, fb); } catch (err) { fb(); }
  });
  $("copy").addEventListener("click", function () {
    if (!totals().count) return toast(L("t_empty"));
    if (blocked()) { renderClosed(); return toast(L("closed_toast")); }
    if (!validate()) return;
    var txt = buildMessage();
    function fallback() { toast(L("t_copy_fail")); }
    try { navigator.clipboard.writeText(txt).then(function () { toast(L("t_order_ok")); }, fallback); } catch (err) { fallback(); }
  });
  $("clear").addEventListener("click", function () { cart = {}; changed(false); closeCart(); });
  window.addEventListener("scroll", spy, { passive: true });
  window.addEventListener("resize", function () { measure(); spy(); });

  /* ---------- Section icons ---------- */
  function ICO(d) { return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + d + "</svg>"; }
  var SKEWER = [[8, 16], [12, 12], [16, 8]].map(function (p) { return '<g transform="translate(' + p[0] + " " + p[1] + ') rotate(-45)"><rect x="-2.6" y="-2.6" width="5.2" height="5.2" rx="1.2"/></g>'; }).join("");
  var ICONS = {
    build: ICO('<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/><path d="M19 16v4M17 18h4"/>'),
    deals: ICO('<path d="M19 5 5 19"/><circle cx="7.5" cy="7.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>'),
    wraps: ICO('<rect x="2.5" y="8" width="19" height="8.5" rx="4.2" transform="rotate(-28 12 12.2)"/><path d="M14.8 7.2c1.3.1 2.6 1 3.2 2.3M7.2 16.4l5-7.3"/>'),
    box: ICO('<path d="M3 8.5l1.7-4.5h14.6L21 8.5"/><path d="M4 8.5h16l-1.4 10.7a1.6 1.6 0 0 1-1.6 1.4H7a1.6 1.6 0 0 1-1.6-1.4L4 8.5z"/><path d="M9.5 12.5h5"/>'),
    shaw: ICO('<path d="M12 2.5V5M12 19.5v2"/><path d="M7 7.2C7 6 9.2 5 12 5s5 1 5 2.2l-1.4 11.3c-.1 1-1.9 1.8-3.6 1.8s-3.5-.8-3.6-1.8L7 7.2z"/><path d="M7.4 10.8h9.2M8 14.6h8"/>'),
    grill: ICO('<path d="M12 2.8c.6 3.2 4.3 5 4.3 9.2a4.3 4.3 0 1 1-8.6 0c0-1.7.7-2.9 1.6-3.9.3 1.1 1 1.8 2 2C10.9 8.2 11 5.6 12 2.8z"/>'),
    chicken: ICO('<circle cx="10" cy="10" r="6.5"/><path d="M14.5 14.5 20 20"/><circle cx="20.5" cy="20.5" r="1.6"/>'),
    gwraps: ICO('<path d="M3.5 20.5L20.5 3.5"/>' + SKEWER),
    veg: ICO('<path d="M5 19.5C4.5 10 10 4.5 20 4.5c.5 10-5 15.5-15 15z"/><path d="M5 19.5l9-9"/>'),
    hum: ICO('<path d="M3 11h18c0 5-4 8.5-9 8.5S3 16 3 11z"/><path d="M8 8.2c1.4-1.5 2.9-1.5 4 0s2.6 1.5 4 0M9 21.5h6"/>'),
    side: ICO('<path d="M6 10.5h12l-1.4 9.5H7.4L6 10.5z"/><path d="M8.2 10.5V4.5M11 10.5V3M13.8 10.5V4.2M16.2 10.5V6"/>'),
    dess: ICO('<path d="M12 3l9 6.2L12 21 3 9.2 12 3z"/><path d="M3 9.2h18M9 9.2L12 3l3 6.2M9 9.2l3 11.8 3-11.8"/>'),
    drink: ICO('<path d="M6 8.5h12l-1.3 11.2a1.6 1.6 0 0 1-1.6 1.4H8.9a1.6 1.6 0 0 1-1.6-1.4L6 8.5z"/><path d="M5 8.5h14M13 8.5l1.6-5.5H18"/>'),
    _d: ICO('<path d="M6 3v8a3 3 0 0 0 3 3v7M9 3v6M12 3v8a3 3 0 0 1-3 3M17 3c-2 1.5-3 4-3 7 0 2 1 3 3 3v8"/>'),
    juice: ICO('<path d="M7 6h10l-1 13a2 2 0 0 1-2 1.8h-4a2 2 0 0 1-2-1.8L7 6z"/><path d="M7.4 11h9.2"/><circle cx="17" cy="5.2" r="2.6"/>')
  };
  function secHead(c, n) {
    return '<div class="sh2"><span class="shl"></span><h2 class="shc"><span class="shi">' + (ICONS[c.id] || ICONS._d) + '</span><span class="sht">' + esc(catName(c)) + "</span>" + (n ? '<span class="shn">' + n + "</span>" : "") + '</h2><span class="shl"></span></div>';
  }

  /* ---------- Build your wrap ---------- */
  // Prices are easy to change here. Base prices follow the printed menu (Kebab wraps).
  var DB = D.builder || {};
  var BASEP = DB.base || { kurczak: { "Duży": 32, "Mały": 19 }, wolowina: { "Duży": 34, "Mały": 21 } };
  if (!BASEP.mix) BASEP.mix = { "Duży": 36, "Mały": 24 }; // chicken + beef
  // Included portions per group, then the price of every extra portion (zł)
  function nv(v, d) { v = parseFloat(v); return isNaN(v) ? d : v; }
  var BP = { sauceFree: nv(DB.sauceFree, 2), sauce: nv(DB.sauce, 2), vegFree: nv(DB.vegFree, 3), veg: nv(DB.veg, 3), pickleFree: nv(DB.pickleFree, 1), pickle: nv(DB.pickle, 3), fries: nv(DB.fries, 7), cheese: nv(DB.cheese, 5) };
  var SAUCES = [
    { id: "czosnek", color: "#f6efdc" },
    { id: "lagodny", color: "#ee8a45" },
    { id: "tahini", color: "#d8b88a" },
    { id: "ostry", color: "#d3241b" },
    { id: "serowy", color: "#f6c230" },
    { id: "granat", color: "#5e1428" }
  ];
  var VEGS = [{ id: "pomidor" }, { id: "pietruszka" }, { id: "cebula" }, { id: "salata" }, { id: "arab" }];
  var PICKLES = [{ id: "ogorek" }, { id: "rzepa" }];
  var EXTRAS = [{ id: "frytki" }, { id: "ser" }]; // paid extras, each with its own price per portion
  var TABS = [
    { id: "sosy", list: SAUCES, free: BP.sauceFree, extra: BP.sauce },
    { id: "salatki", list: VEGS, free: BP.vegFree, extra: BP.veg },
    { id: "kiszonki", list: PICKLES, free: BP.pickleFree, extra: BP.pickle },
    { id: "dodatki", list: EXTRAS, free: 0, extra: 0, prices: { frytki: BP.fries, ser: BP.cheese } }
  ];
  var ALLING = SAUCES.concat(VEGS, PICKLES, EXTRAS);

  /* ---- Photographic stage: real cut-out photos dropped onto a saj flatbread (assets/ing/*.webp) ---- */
  // pre = file prefix, nv = number of photo variants, per = pieces per portion, sz = piece width (% of the plate), z = stacking order
  var MEATS = { kurczak: { pre: ["chicken", "chickens"], nv: [10, 6] }, wolowina: { pre: ["beef", "beefs"], nv: [10, 6] } };
  var ING_URL = function (n) { return BASE + "ing/" + n + ".webp" + (n === "saj" ? "?v=4" : ""); };
  function rng(seed) {
    return function () {
      seed |= 0; seed = (seed + 0x6D2B79F5) | 0;
      var t = Math.imul(seed ^ (seed >>> 15), 1 | seed);
      t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
      return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
  }
  // pieces land inside an elliptical "filling zone" (fractions of the plate), best-candidate spacing
  function zonePts(seed, n, rx, ry, cy, minD) {
    var r = rng(seed), pts = [], i, t;
    for (i = 0; i < n; i++) {
      var best = null, bd = -1;
      for (t = 0; t < 24; t++) {
        var a = r() * 6.2832, d = Math.sqrt(r()), x = .5 + Math.cos(a) * d * rx, y = cy + Math.sin(a) * d * ry, m = 9;
        pts.forEach(function (p) { m = Math.min(m, Math.hypot(p.x - x, p.y - y)); });
        if (m > bd) { bd = m; best = { x: x, y: y }; }
      }
      pts.push(best);
    }
    return pts;
  }
  function slotsFor(key, n, o) {
    var r = rng(o.seed + 7), pts = zonePts(o.seed, n, o.rx, o.ry, .47, 0), arr = [];
    pts.forEach(function (p, i) {
      arr.push({ x: p.x * 100, y: p.y * 100, rot: o.sauce ? (r() * 50 - 25) : r() * 360, v: Math.floor(r() * 997), sz: o.sz * (.85 + r() * .3), idx: i, key: key });
    });
    if (!o.sauce) arr.sort(function (a, b) { return a.y - b.y; });
    arr.forEach(function (s, i) { s.ord = i; });
    return arr;
  }
  // pieces drawn on the wrap: n per portion, min distance between pieces
  var PIECE = {
    salata: { sym: ["s-let"], n: 9, min: 21 }, pomidor: { sym: ["s-tom"], n: 11, min: 15 }, cebula: { sym: ["s-oni"], n: 10, min: 17 },
    pietruszka: { sym: ["s-par"], n: 14, min: 12 }, arab: { sym: ["s-tom2", "s-cuc2", "s-par"], n: 20, min: 12 },
    ogorek: { sym: ["s-pic"], n: 7, min: 17 }, rzepa: { sym: ["s-tur"], n: 8, min: 18 },
    frytki: { sym: ["s-fry"], n: 12, min: 12 }, ser: { sym: ["s-chz"], n: 9, min: 15 }
  };
  var ZORDER = ["salata", "pomidor", "cebula", "pietruszka", "arab", "ogorek", "rzepa", "frytki", "ser"];

  function scatter(seed, n, minD, rx, ry) {
    var r = rng(seed), pts = [];
    for (var i = 0; i < n; i++) {
      var best = null, bestD = -1;
      for (var t = 0; t < 30; t++) {
        var a = r() * 6.2832, d = Math.sqrt(r());
        var x = 200 + Math.cos(a) * d * rx, y = 150 + Math.sin(a) * d * ry, md = 1e9;
        for (var j = 0; j < pts.length; j++) { var dx = pts[j][0] - x, dy = pts[j][1] - y; md = Math.min(md, dx * dx + dy * dy); }
        if (md > bestD) { bestD = md; best = [x, y, r() * 360]; }
        if (md >= minD * minD) break;
      }
      pts.push(best);
    }
    return pts;
  }
  function wave(y0, amp, flip) {
    var x0 = 104, n = 9, step = 192 / n, d = "M" + x0 + " " + y0 + " Q" + (x0 + step / 2) + " " + (y0 + (flip ? amp : -amp) * 2) + " " + (x0 + step) + " " + y0;
    for (var i = 2; i <= n; i++) d += " T" + (x0 + step * i) + " " + y0;
    return d;
  }
  function pieceHTML(p, sym, on) {
    return '<g transform="translate(' + p[0].toFixed(1) + " " + p[1].toFixed(1) + ") rotate(" + p[2].toFixed(0) + ') scale(1.25)"><g class="vpc' + (on ? " on" : "") + '"><use href="#' + sym + '"/></g></g>';
  }
  var CI = {
    pomidor: '<circle cx="24" cy="27" r="14" fill="#e23b2e"/><ellipse cx="19" cy="21" rx="5" ry="3" fill="#f58a77" opacity=".6"/><path d="M24 14l-4 4-5-1 4 4zM24 14l4 4 5-1-4 4z" fill="#3f9a3d"/>',
    pietruszka: '<path d="M24 41V23" stroke="#2b7a35" stroke-width="2.5" stroke-linecap="round"/><path d="M24 25c-8 1-12-4-10-9 5-1 9 2 10 9zM24 25c8 1 12-4 10-9-5-1-9 2-10 9zM24 23c-5-3-4-9 0-12 4 3 5 9 0 12z" fill="#3aa04a"/>',
    cebula: '<circle cx="24" cy="25" r="15" fill="#f3e3f2" stroke="#c07aae" stroke-width="2"/><circle cx="24" cy="25" r="10" fill="none" stroke="#c07aae" stroke-width="1.6"/><circle cx="24" cy="25" r="5" fill="none" stroke="#c07aae" stroke-width="1.6"/>',
    salata: '<path d="M9 28c-2-8 4-16 12-16 3-4 11-3 12 3 6 1 9 8 5 14-3 5-10 7-17 6-7 0-11-3-12-7z" fill="#7ccf4a"/><path d="M12 30c6-3 11-8 13-15M22 34c5-3 9-7 11-13" stroke="#4f9e2c" stroke-width="2" fill="none" stroke-linecap="round"/>',
    arab: '<path d="M7 24h34c0 10-7 17-17 17S7 34 7 24z" fill="#f5f2ee"/><rect x="11" y="13" width="9" height="9" rx="2.2" fill="#e23b2e"/><rect x="21" y="9" width="9" height="9" rx="2.2" fill="#7fbf57"/><rect x="30" y="14" width="8" height="8" rx="2.2" fill="#e23b2e"/><path d="M19 22c1-4 6-4 7 0z" fill="#3aa04a"/>',
    ogorek: '<circle cx="24" cy="25" r="15" fill="#4e8f3a"/><circle cx="24" cy="25" r="11" fill="#a6d16a"/><g fill="#e8f4bb"><circle cx="20" cy="22" r="1.7"/><circle cx="28" cy="23" r="1.7"/><circle cx="24" cy="29" r="1.7"/><circle cx="19" cy="29" r="1.3"/><circle cx="29" cy="30" r="1.3"/></g>',
    frytki: '<path d="M11 24h26l-3 18H14z" fill="#d9302c"/><g fill="#f2bf3a"><rect x="14" y="7" width="5.5" height="19" rx="2.2" transform="rotate(-9 16 17)"/><rect x="21.3" y="4" width="5.5" height="22" rx="2.2"/><rect x="28.5" y="7" width="5.5" height="19" rx="2.2" transform="rotate(9 31 17)"/></g><path d="M11 24h26" stroke="#a81f1b" stroke-width="2"/>',
    ser: '<path d="M5 33L42 13v20z" fill="#f6c230"/><path d="M5 33h37v7H5z" fill="#e0a21a"/><circle cx="25" cy="29" r="2.6" fill="#e0a21a"/><circle cx="33" cy="23" r="2" fill="#e0a21a"/><circle cx="17" cy="35.5" r="1.5" fill="#c98a12"/>',
    rzepa: '<g transform="rotate(-28 24 25)"><rect x="5" y="13" width="31" height="9" rx="4.5" fill="#c9267a"/><rect x="9" y="15" width="14" height="3" rx="1.5" fill="#f08cc0" opacity=".7"/><rect x="12" y="27" width="31" height="9" rx="4.5" fill="#b01e6b"/><rect x="16" y="29" width="14" height="3" rx="1.5" fill="#e67db4" opacity=".6"/></g>'
  };
  function chipIco(it) {
    if (it.color) return '<path d="M7 27h34c0 9-7 15-17 15S7 36 7 27z" fill="#f5f2ee"/><ellipse cx="24" cy="27" rx="17" ry="7" fill="' + it.color + '"/><path d="M15 27c3-3 7-3 9 0s6 2 9-1" fill="none" stroke="rgba(255,255,255,.5)" stroke-width="1.8" stroke-linecap="round"/><ellipse cx="24" cy="27" rx="17" ry="7" fill="none" stroke="#dcd6cd"/>';
    return CI[it.id];
  }

  function vecMarkup() {
    var o = "";
    ZORDER.forEach(function (id, zi) {
      var P = PIECE[id], pts = scatter(40 + zi * 13, P.n * 2, P.min, 80, 42);
      o += '<g data-ing="' + id + '">' + pts.map(function (p, i) { return pieceHTML(p, P.sym[i % P.sym.length], false); }).join("") + "</g>";
    });
    SAUCES.forEach(function (sa, si) {
      var y0 = 150 + (si - 2.5) * 11;
      o += '<g data-ing="' + sa.id + '">';
      [0, 1].forEach(function (k) {
        var d = wave(y0 + k * 5, 7, (si + k) % 2);
        o += '<g class="vsp"><path pathLength="1" d="' + d + '" fill="none" stroke="rgba(40,20,5,.38)" stroke-width="8" stroke-linecap="round"/><path pathLength="1" d="' + d + '" fill="none" stroke="' + sa.color + '" stroke-width="5.2" stroke-linecap="round"/><path pathLength="1" d="' + d + '" fill="none" stroke="rgba(255,255,255,.38)" stroke-width="1.3" stroke-linecap="round" transform="translate(0 -1.2)"/></g>';
      });
      o += "</g>";
    });
    return o;
  }
  var STAGE_N = { meat: { "Duży": 36, "Mały": 26 } };
  function buildStage(host, st, mini) {
    host.innerHTML = '<div class="stg' + (mini ? " mini-stg" : "") + '" role="img" aria-label="' + esc(L("stage_aria")) + '"><div class="plate"><i class="ring"></i><img class="saj" alt="" draggable="false" src="' + ING_URL("saj") + '"><div class="pile"></div><svg class="vec" viewBox="79 29 242 242" aria-hidden="true"></svg></div></div>';
    var S = { host: host, pile: host.querySelector(".pile"), plate: host.querySelector(".plate"), groups: {}, meat: null, mini: !!mini };
    S.vec = host.querySelector(".vec"); S.vec.innerHTML = vecMarkup();
    Object.keys(MEATS).forEach(function (m, mi) {
      S.groups["m:" + m] = { cfg: { meat: m }, slots: slotsFor("m:" + m, 40, { seed: 11 + mi * 5, rx: .40, ry: .34, sz: 18 }), z: 1, meat: true };
    });
    S.host._stage = S; return S;
  }
  function spriteSrc(g, s) {
    if (g.meat) { var M = MEATS[g.cfg.meat], k = s.idx % 3 === 2 ? 1 : 0; return ING_URL(M.pre[k] + (s.v % M.nv[k])); }
    return ING_URL(g.cfg.pre + (s.v % g.cfg.nv));
  }
  var RM = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var DROP = "cubic-bezier(.22,.9,.3,1)";
  function spOn(g, s, delay, instant) {
    instant = instant || RM; s.want = true; if (s.on) return; s.on = true;
    if (!s.el) {
      var im = document.createElement("img"); im.className = "sp" + (g.cfg.sauce ? " sauce" : ""); im.alt = ""; im.draggable = false; im.decoding = "async"; im.src = spriteSrc(g, s);
      im.style.left = s.x + "%"; im.style.top = s.y + "%"; im.style.width = s.sz + "%"; im.style.zIndex = g.z * 100 + s.ord;
      s.base = "translate(-50%,-50%) rotate(" + s.rot.toFixed(1) + "deg)"; im.style.transform = s.base; s.el = im; g.parent.appendChild(im);
    }
    var e = s.el; e.getAnimations().forEach(function (a) { a.cancel(); }); e.style.display = ""; e.style.opacity = 1;
    if (instant || !e.animate) return;
    if (g.cfg.sauce) {
      e.animate([{ clipPath: "inset(0 100% 0 0)", opacity: 0 }, { clipPath: "inset(0 0% 0 0)", opacity: 1 }], { duration: 520, delay: delay, easing: "cubic-bezier(.4,0,.2,1)", fill: "backwards" });
    } else {
      var dir = (s.rot % 2 < 1 ? 1 : -1), from = "translate(" + (-50 + dir * 4) + "%," + (-50 - 85) + "%) rotate(" + (s.rot + dir * 38).toFixed(1) + "deg) scale(1.35)";
      e.animate([{ transform: from, opacity: 0 }, { opacity: 1, offset: .35 }, { transform: s.base + " scale(1)", opacity: 1 }], { duration: 520, delay: delay, easing: DROP, fill: "backwards" });
    }
  }
  function spOff(g, s, delay, instant) {
    instant = instant || RM; s.want = false; if (!s.on) return; s.on = false;
    var e = s.el; if (!e) return;
    if (instant || !e.animate) { e.style.display = "none"; return; }
    e.getAnimations().forEach(function (a) { a.cancel(); });
    var an = e.animate([{ opacity: 1, transform: s.base + " scale(1)" }, { opacity: 0, transform: s.base + " scale(.55)" }], { duration: 230, delay: delay, easing: "ease-in", fill: "forwards" });
    an.onfinish = function () { if (!s.on) { e.style.display = "none"; } an.cancel(); };
  }
  function paintStage(S, st, instant) {
    if (!S) return;
    var small = st.size === "Mały", cnt = STAGE_N.meat[st.size];
    S.plate.style.transform = small ? "scale(.86)" : "scale(1)";
    function sync(key, want) {
      var g = S.groups[key]; g.parent = S.pile; var add = 0, rem = 0, step = Math.min(34, 520 / Math.max(1, want));
      g.slots.forEach(function (s, i) {
        var on = i < want;
        if (on && !s.on) spOn(g, s, (add++) * step, instant); else if (!on && s.on) spOff(g, s, (rem++) * 14, instant);
      });
    }
    // mix = half chicken, half beef, scattered together
    Object.keys(MEATS).forEach(function (m) { sync("m:" + m, st.meat === m ? cnt : (st.meat === "mix" ? Math.ceil(cnt / 2) : 0)); });
    ALLING.forEach(function (it) {
      var g = S.vec.querySelector('[data-ing="' + it.id + '"]'), q = st.q[it.id] || 0, i; if (!g) return;
      if (it.color) { var sp = g.querySelectorAll(".vsp"); for (i = 0; i < sp.length; i++) sp[i].classList.toggle("on", i < q); }
      else { var n = PIECE[it.id].n * q; for (i = 0; i < g.children.length; i++) g.children[i].firstChild.classList.toggle("on", i < n); }
    });
  }
  var preloaded = false;
  function preloadStage() {
    if (preloaded) return; preloaded = true;
    var names = ["saj"];
    Object.keys(MEATS).forEach(function (m) { for (var k = 0; k < 2; k++) for (var i = 0; i < MEATS[m].nv[k]; i++) names.push(MEATS[m].pre[k] + i); });
    (window.requestIdleCallback || function (f) { setTimeout(f, 200); })(function () { names.forEach(function (n) { new Image().src = ING_URL(n); }); });
  }

  var B = null, PRESET = { meat: "kurczak", size: "Duży", q: { czosnek: 1, ostry: 1, pomidor: 1, pietruszka: 1, cebula: 1, ogorek: 1, salata: 1 } };
  var bToastT, bDeltaT;
  function bFresh() { return { meat: "kurczak", size: "Duży", q: {}, tab: "sosy", undo: null }; }
  function cntList(q, list) { var s = 0; list.forEach(function (x) { s += q[x.id] || 0; }); return s; }
  function priceCfg(c) {
    var p = BASEP[c.meat][c.size];
    TABS.forEach(function (t) {
      if (t.prices) t.list.forEach(function (i) { p += Math.min(2, c.q[i.id] || 0) * t.prices[i.id]; });
      else p += Math.max(0, cntList(c.q, t.list) - t.free) * t.extra;
    });
    return p;
  }
  function bPrice() { return priceCfg(B); }
  function bDescCfg(c, lang) {
    var parts = [L("meat_" + c.meat, lang)];
    TABS.forEach(function (t) {
      var l = t.list.filter(function (i) { return c.q[i.id] > 0; }).map(function (i) { return ingName(i.id, lang) + (c.q[i.id] > 1 ? " ×" + c.q[i.id] : ""); });
      if (l.length) parts.push(L("tab_" + t.id, lang) + ": " + l.join(", "));
    });
    if (parts.length === 1) parts.push(L("no_extras", lang));
    return parts.join(" · ");
  }
  var gridTab = null;
  function bChips() {
    var tab = TABS.filter(function (t) { return t.id === B.tab; })[0];
    if (gridTab !== B.tab + "|" + LANG) {
      gridTab = B.tab + "|" + LANG;
      $("bGrid").innerHTML = tab.list.map(function (it) {
        return '<div class="ch" data-id="' + it.id + '"><button type="button" class="cb" data-add="' + it.id + '"><svg viewBox="0 0 48 48" aria-hidden="true">' + chipIco(it) + '</svg></button><button type="button" class="mn" data-sub="' + it.id + '" hidden>−</button><span class="bd" aria-hidden="true" hidden></span><span class="cl">' + esc(ingName(it.id)) + "</span></div>";
      }).join("");
    }
    Array.prototype.forEach.call($("bGrid").children, function (el) {
      var id = el.dataset.id, q = B.q[id] || 0, nm = ingName(id), bd = el.querySelector(".bd"), was = el.classList.contains("on");
      el.classList.toggle("on", q > 0);
      el.querySelector(".mn").hidden = !q; bd.hidden = !q; bd.textContent = q > 1 ? "×" + q : "✓";
      el.querySelector(".mn").setAttribute("aria-label", L("rm_ing") + nm);
      el.querySelector(".cb").setAttribute("aria-label", nm + (q ? ", " + L("picked") + q : ""));
      if (q && !was && el.animate) bd.animate([{ transform: "scale(.2)" }, { transform: "scale(1.18)" }, { transform: "scale(1)" }], { duration: 320, easing: "ease-out" });
    });
  }
  function bRender(instant, nopaint) {
    $("bSize").innerHTML = ["Duży", "Mały"].map(function (v) { return '<button type="button" data-size="' + v + '" aria-pressed="' + (B.size === v) + '">' + L(v === "Duży" ? "size_Duzy" : "size_Maly") + "</button>"; }).join("");
    $("bMeat").innerHTML = ["kurczak", "wolowina", "mix"].map(function (v) { return '<button type="button" data-meat="' + v + '" aria-pressed="' + (B.meat === v) + '">' + L("meat_" + v) + "</button>"; }).join("");
    $("bTabs").innerHTML = TABS.map(function (t) {
      var n = t.list.filter(function (i) { return B.q[i.id]; }).length;
      return '<button type="button" role="tab" class="tab" data-tab="' + t.id + '" aria-selected="' + (B.tab === t.id) + '">' + L("tab_" + t.id) + (n ? "<i>" + n + "</i>" : "") + "</button>";
    }).join("");
    var tab = TABS.filter(function (t) { return t.id === B.tab; })[0];
    $("bInfo").textContent = tab.prices
      ? fmt(L("info_extras"), { l: tab.list.map(function (i) { return ingName(i.id) + " ⁦+" + tab.prices[i.id] + " zł⁩"; }).join(" · ") })
      : fmt(L("info"), { u: Math.min(cntList(B.q, tab.list), tab.free), f: tab.free, p: "⁦+" + tab.extra + " zł⁩" });
    bChips();
    $("bTot").textContent = money(bPrice());
    if (!hintLock) setHint();
    if (!nopaint) paintStage($("bStage")._stage, B, instant);
  }
  var hintLock = false, hintT, tipT;
  function setHint() {
    var h = $("bHint"); h.classList.remove("msg");
    h.textContent = ALLING.some(function (i) { return B.q[i.id]; }) ? "" : L("hint");
  }
  // "Jalapeño taken off · Undo" sits under the plate, exactly where the hint is
  function bToast(msg, undo) {
    var h = $("bHint"); hintLock = true; h.classList.add("msg");
    h.innerHTML = "<span>" + esc(msg) + "</span>" + (undo ? ' <button type="button" data-undo="1">' + esc(L("undo")) + "</button>" : "");
    clearTimeout(hintT); hintT = setTimeout(function () { hintLock = false; setHint(); }, undo ? 4500 : 2200);
  }
  function bTip(id) {
    var ch = $("bGrid").querySelector('[data-id="' + id + '"]'); if (!ch) return;
    var old = $("bGrid").querySelector(".tip"); if (old) old.remove();
    var t = document.createElement("span"); t.className = "tip"; t.textContent = L("max2"); ch.appendChild(t);
    if (t.animate) t.animate([{ opacity: 0, transform: "translate(-50%,6px)" }, { opacity: 1, transform: "translate(-50%,0)" }], { duration: 160, fill: "backwards" });
    clearTimeout(tipT); tipT = setTimeout(function () { t.remove(); }, 1700);
  }
  function bChange(fn) {
    var p0 = bPrice(); fn(); bRender();
    var d = round2(bPrice() - p0), dl = $("bDl");
    if (d) { dl.textContent = (d > 0 ? "+" : "−") + money(Math.abs(d)); dl.classList.add("show"); clearTimeout(bDeltaT); bDeltaT = setTimeout(function () { dl.classList.remove("show"); }, 1500); }
  }
  function openBuilder() {
    B = bFresh(); gridTab = null; hintLock = false;
    var host = $("bStage"); buildStage(host, B);
    $("bDl").classList.remove("show");
    $("bov").hidden = false; document.body.style.overflow = "hidden";
    bRender(true, true);
    setTimeout(function () { if (!$("bov").hidden && B) paintStage(host._stage, B, false); }, 360); // meat drops in after the sheet has opened
    preloadStage(); $("bBack").focus();
  }
  function closeBuilder() { $("bov").hidden = true; document.body.style.overflow = ""; }
  function bAddToCart() {
    var cfg = { meat: B.meat, size: B.size, q: {} };
    ALLING.forEach(function (i) { if (B.q[i.id]) cfg.q[i.id] = B.q[i.id]; });
    var price = priceCfg(cfg);
    var sig = "v2|" + cfg.meat + "|" + cfg.size + "|" + ALLING.map(function (i) { return i.id + ":" + (cfg.q[i.id] || 0); }).join(",");
    var id = customs.sig[sig];
    if (!id || !customs.items[id]) { id = customs.next++; customs.sig[sig] = id; customs.items[id] = { id: id, cat: "build", custom: true, name: "Własny wrap", cfg: cfg, desc: bDescCfg(cfg, "pl"), v: [[cfg.size, price]] }; }
    var key = id + "|0"; cart[key] = (cart[key] || 0) + 1;
    closeBuilder(); changed(true); toast(L("t_added") + L("custom_name"));
  }
  function buildCardHTML() {
    return '<button type="button" class="bcard" data-build="1" aria-label="' + esc(L("build_title").replace(/<[^>]+>/g, "")) + '"><span class="mini" data-mini aria-hidden="true"></span><span class="bc-t"><h3>' + L("build_title") + "</h3><p>" + esc(L("build_sub")) + '</p><span class="go">' + esc(L("build_go")) + '<span class="from">' + esc(L("from")) + " " + money(Math.min(BASEP.kurczak["Mały"], BASEP.wolowina["Mały"])) + "</span></span></span></button>";
  }

  $("bov").addEventListener("click", function (e) {
    if (e.target === this) return closeBuilder();
    var t = e.target.closest("button"); if (!t) return;
    var d = t.dataset;
    if (t.id === "bBack") return closeBuilder();
    if (t.id === "bReset") { var keep = { meat: B.meat, size: B.size }; B = bFresh(); B.meat = keep.meat; B.size = keep.size; hintLock = false; gridTab = null; return bRender(); }
    if (t.id === "bAdd") return bAddToCart();
    if (d.size) return bChange(function () { B.size = d.size; });
    if (d.meat) return bChange(function () { B.meat = d.meat; });
    if (d.tab) { B.tab = d.tab; return bRender(); }
    if (d.add) {
      if ((B.q[d.add] || 0) >= 2) return bTip(d.add);
      return bChange(function () { B.q[d.add] = (B.q[d.add] || 0) + 1; });
    }
    if (d.sub) {
      var prev = B.q[d.sub];
      bChange(function () { B.q[d.sub] = prev - 1; if (!B.q[d.sub]) delete B.q[d.sub]; B.undo = { id: d.sub, q: prev }; });
      return bToast(L("removed") + ingName(d.sub), true);
    }
    if (d.undo && B.undo) { var u = B.undo; B.undo = null; hintLock = false; return bChange(function () { B.q[u.id] = u.q; }); }
  });

  /* ---------- Language switcher ---------- */
  var STATIC = [
    ["#q", "placeholder", "search_ph"], ["#q", "aria-label", "search_ph"], ["#qx", "aria-label", "search_clear"], ["#chips", "aria-label", "cats_aria"], ["#langBtn", "aria-label", "lang_aria"],
    ["#openCart", "aria-label", "fab_aria"], ["#openCart .lbl", "text", "fab_label"], ["#cartTitle", "text", "cart_title"], ["#closeCart", "aria-label", "cart_close"],
    [".total span", "text", "total"], [".seg", "aria-label", "type_aria"], ["#mDine", "text", "dine"], ["#mPick", "text", "pick"],
    ["label[for=fName]", "text", "f_name"], ["#fName", "placeholder", "ph_name"],
    ["label[for=fPhone]", "text", "f_phone"], ["label[for=fEta]", "text", "f_eta"], ["label[for=fNotes]", "text", "f_notes"], ["#fNotes", "placeholder", "ph_notes"],
    ["#send", "text", "send_wa"], ["#sendApp", "text", "send_app"], ["#copy", "text", "copy_order"], ["#clear", "text", "clear_cart"], [".note", "text", "wa_note"],
    ["#cashT", "html", "cash_help"], ["#copyNum", "text", "copy_num"], ["#footT", "text", "foot"],
    ["#bBack", "aria-label", "b_back"], ["#bReset", "aria-label", "b_reset"], ["#bTitle", "html", "build_title"], ["#bSize", "aria-label", "size_aria"],
    ["#okT", "text", "order_ok_t"], ["#okClose", "text", "order_ok_btn"], ["#okNoL", "text", "order_no"],
    ["#bMeat", "aria-label", "meat_aria"], ["#bTabs", "aria-label", "tabs_aria"], ["#bAdd .lbl", "text", "b_add"]
  ];
  function applyStatic() {
    STATIC.forEach(function (r) {
      var el = document.querySelector(r[0]); if (!el) return;
      var v = L(r[2]); if (r[2] === "cash_help") v = v.replace("+48 500 002 249", PHONE);
      if (r[1] === "text") el.textContent = v; else if (r[1] === "html") el.innerHTML = v; else el.setAttribute(r[1], v);
    });
  }
  function setLang(l, noSave) {
    if (!LANGS[l]) l = "pl";
    LANG = l;
    if (!noSave) { try { localStorage.setItem("gh-lang", l); } catch (e) {} if (G && G.ord) G.ord.syncLang(l); }
    var app = $("app"); app.setAttribute("lang", l); app.setAttribute("dir", l === "ar" ? "rtl" : "ltr");
    try { document.documentElement.lang = l; } catch (e) {}
    applyStatic();
    if (DIRECT_ONLY && !failed) $("send").textContent = L("send_order");
    $("homeBtn").setAttribute("aria-label", L("nav_home")); $("homeBtn").title = L("nav_home");
    $("langCode").textContent = l === "ar" ? "ع" : l.toUpperCase();
    Array.prototype.forEach.call($("langMenu").querySelectorAll("button"), function (b) { b.setAttribute("aria-current", b.dataset.lang === l ? "true" : "false"); });
    renderChips(); renderMenu(); renderBar();
    if (!$("scrim").hidden) renderCart();
    if (!$("bov").hidden && B) { $("bStage").firstChild.setAttribute("aria-label", L("stage_aria")); bRender(); }
    measure(); spy(); hoursTick();
  }
  function closeLangMenu() { $("langMenu").hidden = true; $("langBtn").setAttribute("aria-expanded", "false"); }
  $("langMenu").innerHTML = ["pl", "en", "ar"].map(function (k) { return '<li><button type="button" data-lang="' + k + '"' + (k === "ar" ? ' lang="ar"' : "") + ">" + LANGS[k] + "</button></li>"; }).join("");
  $("langBtn").addEventListener("click", function (e) { e.stopPropagation(); var open = $("langMenu").hidden; $("langMenu").hidden = !open; this.setAttribute("aria-expanded", String(open)); });
  $("langMenu").addEventListener("click", function (e) { var b = e.target.closest("button"); if (!b) return; closeLangMenu(); setLang(b.dataset.lang); });
  document.addEventListener("click", function (e) { if (!e.target.closest("#lang")) closeLangMenu(); });

  /* ---------- Direct order to the restaurant (kitchen page + printer) ---------- */
  var DIRECT = !!(S.orders && S.order_url), DIRECT_ONLY = DIRECT && !S.orders_wa, sending = false, failed = false;
  if (DIRECT_ONLY) $("app").classList.add("ghm-direct");
  /* The phone's real position, for the kitchen's distance: IP lookups often land on the carrier's hub city (tens of km off), GPS does not. Asked once, when the cart opens; refused or unavailable means the server falls back to the IP lookup. */
  var geoPos = null, geoTried = false;
  function askGeo() {
    if (geoTried || !("geolocation" in navigator)) return; geoTried = true;
    try { navigator.geolocation.getCurrentPosition(function (p) { if (p && p.coords) geoPos = { lat: Math.round(p.coords.latitude * 10000) / 10000, lng: Math.round(p.coords.longitude * 10000) / 10000, t: Date.now() }; }, function () {}, { enableHighAccuracy: false, timeout: 5000, maximumAge: 60000 }); } catch (e) {}
  }
  function orderPayload() {
    var p = {
      src: G ? G.source() : "", lang: LANG, code: cpn ? cpn.code : "", mode: mode, table: (G && G.table ? G.table() : ""), name: $("fName").value.trim(), phone: $("fPhone").value.trim(), eta: $("fEta").value, notes: $("fNotes").value.trim(), lang: LANG,
      lines: lines().map(function (l) {
        return l.it.custom ? { custom: true, cfg: l.it.cfg, desc: l.it.cfg ? bDescCfg(l.it.cfg, "pl") : l.it.desc, qty: l.qty } : { id: l.it.id, vi: l.vi, qty: l.qty };
      })
    };
    if (geoPos && Date.now() - geoPos.t < 5 * 60 * 1000) { p.lat = geoPos.lat; p.lng = geoPos.lng; }
    return p;
  }
  $("cpGo").addEventListener("click", applyCoupon);
  $("cpIn").addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); applyCoupon(); } });
  $("cpIn").addEventListener("input", function () { cpMsg = ""; });
  $("cpDel").addEventListener("click", function () { cpn = null; cpMsg = ""; $("cpIn").value = ""; try { sessionStorage.removeItem("gh-cp"); } catch (e) {} changed(false); renderCart(); });
  /* Create the order on the server first, then open WhatsApp with the message carrying the order number + tracking link (or go to the /track/ page when WhatsApp ordering is off). */
  function submitOrder(openWa) {
    if (sending) return;
    if (G && G.ord) G.ord.unlock();
    sending = true;
    fetch(S.order_url, { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(orderPayload()), keepalive: true })
      .then(function (r) { return r.json().catch(function () { return null; }); })
      .then(function (j) {
        if (j && j.error === "closed") { sending = false; forceClosed = true; toast(L("closed_toast")); renderCart(); return; }
        if (!j || !j.ok) throw new Error("fail");
        sending = false;
        var wa = openWa ? openWa(j) : null; // build the WhatsApp text while the cart still holds the items (clearing first sent an empty order)
        cart = {}; changed(false); closeCart();
        var to = G && G.ord ? G.ord.start(j, LANG) : null;
        if (wa) { location.href = wa; }
        else if (to && S.track_url) { location.href = S.track_url; }
        else { toast(L("order_ok_t")); }
      })
      .catch(function () {
        sending = false; failed = true;
        $("app").classList.remove("ghm-direct"); $("send").textContent = L("send_wa");
        if (!S.orders_wa) toast(L("order_fail"));
      });
  }
  $("send").addEventListener("click", function (e) {
    if (!DIRECT || e.defaultPrevented || failed) return;
    e.preventDefault();
    submitOrder(S.orders_wa ? waHref : null);
  });
  $("sendApp").addEventListener("click", function (e) {
    if (!DIRECT || e.defaultPrevented || failed) return;
    e.preventDefault();
    submitOrder(waAppHref);
  });

  /* ---------- Init ---------- */
  if (S.home_url) { $("brandLink").href = S.home_url; if (S.home_btn !== false) { $("homeBtn").href = S.home_url; $("homeBtn").hidden = false; } }
  load();
  // back from the tracking page to add more: prefill the customer details so the extra order goes in with the same data (it still lands as a new order)
  try { var ad = JSON.parse(localStorage.getItem("gh-add") || "null"); if (ad && typeof ad === "object") { localStorage.removeItem("gh-add"); if (ad.name) $("fName").value = ad.name; if (ad.phone) $("fPhone").value = ad.phone; if (ad.mode === "pick" || ad.mode === "dine") mode = ad.mode; if (ad.eta) $("fEta").value = ad.eta; } } catch (e) {}
  try { var qc = new URLSearchParams(location.search).get("code") || sessionStorage.getItem("gh-cp"); if (qc && S.coupons_on) { $("cpIn").value = qc; setTimeout(applyCoupon, 300); } } catch (e) {}

  /* Deal banner: ?deal=bundle → suggest N sandwiches for the bundle price */
  (function () {
    var deal = null;
    try { deal = new URLSearchParams(location.search).get("deal"); } catch (e) {}
    if (deal !== "bundle" || !BD || !(G && G.inWin(BD.win))) return;
    var n = +BD.n || 4;
    // Suggested mix: beef (2), chicken (1), classic (3), mix (10) — in this order.
    var MIX_IDS = [2, 1, 3, 10]; // beef, chicken, classic, mix (joker excluded from the deal)
    var elig = [];
    MIX_IDS.forEach(function (id) {
      var it = IMAP[id];
      if (it && !it.hidden) elig.push({ it: it, price: unit(it, 0) });
    });
    // Fallback: if a mix item is missing, fill with other bundle-eligible items.
    if (elig.length < n) {
      var have = {};
      elig.forEach(function (x) { have[x.it.id] = 1; });
      ITEMS.forEach(function (it) {
        if (elig.length >= n || have[it.id] || it.hidden) return;
        var id = String(it.id);
        if (((BD.cats || []).indexOf(it.cat) > -1 || (BD.inc || []).indexOf(id) > -1) && (BD.exc || []).indexOf(id) < 0) {
          elig.push({ it: it, price: unit(it, 0) });
        }
      });
    }
    if (!elig.length) return;
    var TX = {
      pl: { t: "Oferta", s: "sandwiche za", add: "Dodaj do koszyka", added: "Dodano!" },
      en: { t: "Deal", s: "sandwiches for", add: "Add to cart", added: "Added!" },
      ar: { t: "عرض", s: "ساندوتش بـ", add: "أضف للسلة", added: "تمت الإضافة!" }
    }[LANG] || {};
    function money2(v) { return String(v).replace(".", ",") + " zł"; }
    var rows = elig.map(function (x) {
      return '<div class="gh-deal-it"><b>' + esc(iName(x.it)) + '</b><span>' + esc(money2(x.price)) + "</span></div>";
    }).join("");
    var html = '<div class="gh-deal" id="ghDeal"><div class="gh-deal-h">🥙 <b>' + esc(TX.t) + ": " + n + " " + esc(TX.s) + " " + esc(money2(BD.price)) + '</b><button type="button" class="gh-deal-x" aria-label="×">×</button></div>' +
      '<div class="gh-deal-list">' + rows + "</div>" +
      '<button type="button" class="gh-deal-go" id="ghDealGo">' + esc(TX.add) + " (" + n + " × " + esc(money2(BD.price)) + ")</button></div>";
    var menu = $("menu");
    if (menu) menu.insertAdjacentHTML("afterbegin", html);
    // Clean the URL so refresh doesn't re-trigger
    try { history.replaceState(null, "", location.pathname + location.hash); } catch (e) {}
    document.addEventListener("click", function (e) {
      if (e.target.closest && e.target.closest("#ghDealGo")) {
        elig.forEach(function (x) { inc(x.it.id + "|0"); });
        var b = $("ghDealGo");
        if (b) { b.textContent = TX.added; b.disabled = true; }
        toast(TX.added);
      }
      if (e.target.closest && e.target.closest(".gh-deal-x")) {
        var d = $("ghDeal"); if (d) d.remove();
      }
    });
  })();
  setLang(LANG, true);
  if (G) {
    G.hit(S.hit_url, { k: "visit", src: G.source() }, "visit");
    G.hit(S.hit_url, { k: "view", id: "menu" }, "menu"); setInterval(hoursTick, 30000);
  }
  if (G && G.partySections) setTimeout(function () {
    function TL(o) { return o ? (o[LANG] || o.pl || o.en || "") : ""; }
    var d = { gift: null, combo: null, bundle: null, coupons: [] };
    if (S.gift && S.gift.on) d.gift = { popup: S.gift.popup !== false, win: S.gift.win,
      tiers: (S.gift.tiers || []).map(function (t) { var it = IMAP[+t.id]; return it && it.v[+t.vi] ? { min: +t.min, name: iName(it) + (vLab(it, +t.vi) ? " (" + vLab(it, +t.vi) + ")" : "") } : null; }).filter(Boolean) };
    if (S.combo && S.combo.on) d.combo = { popup: !!S.combo.popup, win: S.combo.win, label: TL(S.combo.label), off: S.combo.off };
    if (S.bundle && S.bundle.on) d.bundle = { popup: !!S.bundle.popup, win: S.bundle.win, label: TL(S.bundle.label), n: S.bundle.n, price: S.bundle.price };
    (S.coupons || []).forEach(function (c) { if (c.on && c.popup && G.inWin(c.win)) d.coupons.push({ code: c.code, kind: c.kind, value: c.value, min: c.min }); });
    var pd = G.partySections(LANG, d);
    if (pd) G.celebrate({ lang: LANG, title: pd.title, sections: pd.sections });
  }, 900);
  if (document.fonts && document.fonts.ready) document.fonts.ready.then(measure);
})();
