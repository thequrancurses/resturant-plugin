(function ($) {
  "use strict";
  var CFG = window.GHM_ADMIN, STRD = window.GHM_STR || { pl: {}, en: {}, ar: {} };
  var root = document.getElementById("ghm-admin");
  if (!CFG || !root) return;
  var DATA = JSON.parse(JSON.stringify(CFG.data));
  var LG = ["pl", "en", "ar"], LGN = { pl: "Polski", en: "English", ar: "العربية" };
  var ST = null, stLoading = false, CU = null, cuLoading = false, LY = null, lyLoading = false;
  var st = { tab: "dash", cat: "", q: "", open: {}, dirty: false, tq: "", loyTab: "loyalty" };

  function h(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
  function url(u) { if (!u) return ""; return /^(https?:)?\/\/|^\//.test(u) ? u : CFG.base + u; }
  function getP(path) { var o = DATA; path.split(".").forEach(function (k) { o = o == null ? o : o[k]; }); return o; }
  function setP(path, v) {
    var ks = path.split("."), o = DATA;
    for (var i = 0; i < ks.length - 1; i++) { if (o[ks[i]] == null) o[ks[i]] = {}; o = o[ks[i]]; }
    o[ks[ks.length - 1]] = v;
  }
  function dirty() { st.dirty = true; $("#ghm-bar").show(); $("#ghm-save").show(); $("#ghm-state").text("Unsaved changes").attr("class", "ghm-state warn"); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  /* ---- field helpers ---- */
  function inp(path, opt) {
    opt = opt || {}; var v = getP(path);
    var attrs = ' data-p="' + path + '"' + (opt.num ? ' data-t="num"' : "") + (opt.ph ? ' placeholder="' + h(opt.ph) + '"' : "") + (opt.dir ? ' dir="' + opt.dir + '"' : "");
    if (opt.area) return '<textarea rows="2"' + attrs + ">" + h(v) + "</textarea>";
    return '<input type="' + (opt.type || "text") + '"' + (opt.num ? ' step="any" inputmode="decimal"' : "") + attrs + ' value="' + h(v) + '">';
  }
  function l3(path, label, area) {
    return '<div class="l3"><span class="lb">' + h(label) + "</span>" + LG.map(function (l) {
      return '<label><i>' + l.toUpperCase() + "</i>" + inp(path + "." + l, { area: area, dir: l === "ar" ? "rtl" : "ltr" }) + "</label>";
    }).join("") + "</div>";
  }
  function imgField(path, label) {
    var v = getP(path);
    return '<div class="imgf"><span class="th" style="background-image:url(\'' + h(url(v)) + '\')"></span><div><span class="lb">' + h(label) + '</span><button type="button" class="button" data-pick="' + path + '">Choose image</button> ' +
      (v ? '<button type="button" class="button-link" data-clr="' + path + '">Remove</button>' : "") + "</div></div>";
  }

  /* ---- footer links editor ---- */
  var GHM_FTR_BUILTIN = ["home", "menu", "track", "loy", "card", "staff"];
  function footerLinksHtml() {
    var L = DATA.settings.footer_links || [];
    return L.map(function (x, i) {
      var p = "settings.footer_links." + i + ".", builtin = GHM_FTR_BUILTIN.indexOf(x.id) > -1;
      return '<details class="ghm-item" ' + (st.open["fl" + i] ? "open" : "") + '><summary><b>' + h((x.label && x.label.pl) || x.id || "(no label)") + '</b>' +
        (x.on === false ? '<em class="hid">hidden</em>' : "") + (builtin ? "" : ' <em class="hid">custom</em>') + "</summary>" +
        '<div class="tools"><label class="ck"><input type="checkbox" data-p="' + p + 'on" data-t="bool"' + (x.on !== false ? " checked" : "") + "> <b>Show this link</b></label></div>" +
        l3(p + "label", "Label") +
        (builtin ? '<p class="description">Built-in link — its address is found automatically.</p>'
          : '<p><span class="lb">Address (URL)</span>' + inp(p + "url", { ph: "https://…" }) + "</p>") +
        '<div class="tools"><button class="button" data-flup="' + i + '">↑</button><button class="button" data-fldn="' + i + '">↓</button>' +
        (builtin ? "" : ' <button class="button button-link-delete" data-fldel="' + i + '">Delete</button>') + "</div></details>";
    }).join("");
  }

  /* ---- tabs ---- */
  /* 2050 nav: grouped sidebar. Groups map to the panes above. */
  var NAV = [
    { group: 1, title: "Menu" },
    { id: "items", label: "Dishes", group: 1 },
    { id: "cats", label: "Categories", group: 1 },
    { id: "builder", label: "Wrap builder", group: 1 },
    { id: "texts", label: "Texts", group: 1 },
    { id: "dash", label: "Dashboard", group: 0 },
    { group: 1, title: "Marketing" },
    { id: "home", label: "Home page", group: 1 },
    { id: "offers", label: "Offers & alerts", group: 1 },
    { group: 1, title: "Business" },
    { id: "biz", label: "Hours, address & SEO", group: 1 },
    { id: "settings", label: "Settings", group: 1 },
    { group: 1, title: "Insights" },
    { id: "stats", label: "Statistics", group: 1 },
    { id: "cust", label: "Customers", group: 1 },
    { id: "loy", label: "Loyalty", group: 1 },
    { group: 1, title: "System" },
    { id: "tools", label: "Backup", group: 1 }
  ];
  function navHtml() {
    var s = '';
    NAV.forEach(function (n) {
      if (n.title) { s += '<div class="ghm-ng">' + h(n.title) + '</div>'; return; }
      s += '<button type="button" class="ghm-ni' + (st.tab === n.id ? " on" : "") + '" data-tab="' + n.id + '"><span class="dot"></span>' + h(n.label) + '</button>';
    });
    return s;
  }
  function render() {
    var c = (DATA.cats || []);
    if (!st.cat && c.length) st.cat = c[0].id;
    if (!PANES[st.tab]) st.tab = "dash";
    root.innerHTML = '<div class="ghm-bar" id="ghm-bar"' + ((st.dirty || CFG.homeUrl) ? "" : ' style="display:none"') + '>' +
      '<button class="button button-primary button-large" id="ghm-save"' + (st.dirty ? "" : ' style="display:none"') + '>Save changes</button>' +
      '<span id="ghm-state" class="ghm-state">' + (st.dirty ? "Unsaved changes" : "") + "</span>" +
      (CFG.homeUrl ? '<a class="button" target="_blank" href="' + h(CFG.homeUrl) + '">Open home page ↗</a>' : "") + "</div>" +
      '<div class="ghm-shell"><nav class="ghm-side">' + navHtml() + '</nav>' +
      '<div class="ghm-main"><div class="ghm-pane">' + PANES[st.tab]() + "</div></div></div>";
    if (st.dirty) $("#ghm-state").attr("class", "ghm-state warn");
  }


  function loyFrameSrc() {
    var A = (LY && LY.admin) || {}, k = st.loyTab || "loyalty";
    var u = A[k] || A.loyalty || "";
    return u + (u.indexOf("?") > -1 ? "&" : "?") + "ls_embed=1";
  }
  var PANES = {
    dash: function () {
      if (!LY && !lyLoading) loadLoy();
      var S = DATA.settings || {}, nDishes = (DATA.items || []).length, nCats = (DATA.cats || []).length;
      var L = LY || {}, LS = L.st || {};
      var open = !!S.orders;
      function stat(n, l) { return '<div class="ghm-stat"><b>' + h(n == null ? "–" : n) + "</b><span>" + h(l) + "</span></div>"; }
      return '<div class="ghm-dash-hero"><h2><span class="fl">🔥</span> ' + h(S.brand || "Grill Hub") + '</h2>' +
        '<p>Control deck — everything for your restaurant in one place.</p>' +
        '<span class="ghm-open-pill ' + (open ? "open" : "closed") + '"><i></i>' + (open ? "Website ordering ON" : "Website ordering OFF") + "</span></div>" +
        '<div class="ghm-stats">' +
        stat(nDishes, "dishes") + stat(nCats, "categories") +
        stat(LS.customers, "loyalty customers") + stat(LS.active_cards, "active cards") +
        stat(LS.stamps_today, "stamps today") + stat(LS.rewards_issued, "rewards issued") +
        "</div>" +
        '<div class="card ghm-card"><h3>Quick actions</h3><div class="ghm-qa">' +
        (CFG.kitchenUrl ? '<a target="_blank" href="' + h(CFG.kitchenUrl) + '"><span class="ic">🍳</span><span>Kitchen page<small>Open on the tablet</small></span></a>' : "") +
        (CFG.pageUrl ? '<a target="_blank" href="' + h(CFG.pageUrl) + '"><span class="ic">📋</span><span>View menu<small>What customers see</small></span></a>' : "") +
        (CFG.homeUrl ? '<a target="_blank" href="' + h(CFG.homeUrl) + '"><span class="ic">🏠</span><span>View home page<small>Site front</small></span></a>' : "") +
        '<a href="#" data-tab="items"><span class="ic">➕</span><span>Dishes<small>Add or edit</small></span></a>' +
        '<a href="#" data-tab="loy"><span class="ic">🎫</span><span>Loyalty<small>Stamps & rewards</small></span></a>' +
        '<a href="#" data-tab="offers"><span class="ic">🏷️</span><span>Offers<small>Deals & alerts</small></span></a>' +
        "</div></div>" +
        '<div class="card ghm-card"><h3>Staff PIN</h3><div class="ghm-pinbox"><code>' + h((L.pin) || "–") + "</code>" +
        '<input type="text" id="ghm-dashpin-new" inputmode="numeric" maxlength="8" size="8" placeholder="New PIN" style="max-width:120px"> ' +
        '<button class="button" type="button" id="ghm-dashpin-set">Set PIN</button> ' +
        '<button class="button" type="button" id="ghm-dashpin">New random PIN</button></div>' +
        '<p class="description">The staff page asks for it. Give it to your team only.</p></div>';
    },
    loy: function () {
      if (!LY) { loadLoy(); return '<p class="description">Loading…</p>'; }
      if (LY.err) return '<div class="card ghm-card"><p style="color:#b32d2e"><b>' + h(LY.err) + "</b></p></div>";
      var P = LY.pages || {}, A = LY.admin || {}, S = LY.st || {}, NM = { loyalty_register: ["Sign-up page", "Customers join here (share it / QR on the tables)"], loyalty_card: ["Customer card page", "Each customer opens it with a personal link"], loyalty_staff: ["Staff page", "Add stamps / redeem rewards (PIN)"] };
      var missing = Object.keys(NM).some(function (k) { return !P[k]; });
      var rows = Object.keys(NM).map(function (k) {
        return "<tr><th>" + h(NM[k][0]) + '<p class="description">' + h(NM[k][1]) + "</p></th><td>" + (P[k] ? '<input type="text" readonly class="large-text" value="' + h(P[k]) + '" onclick="this.select()"> <a class="button" target="_blank" href="' + h(P[k]) + '">Open ↗</a>' : '<em>no page yet</em>') + "</td></tr>";
      }).join("");
      function stat(n, l) { return '<div style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:12px 16px;min-width:130px"><div style="font-size:26px;font-weight:800;color:#e11e26">' + (n == null ? "–" : n) + "</div><div>" + l + "</div></div>"; }
      var lk = [["Dashboard", "loyalty"], ["Programs", "programs"], ["Customers", "customers"], ["Cards", "cards"], ["Transactions", "transactions"], ["Rewards", "rewards"], ["Stores", "stores"], ["Notifications", "notifications"], ["Wallet", "wallet"], ["Settings", "settings"]];
      return '<div class="ghm-loytabs">' + lk.map(function (x) { return '<button type="button" class="button' + (st.loyTab === x[1] ? " button-primary" : "") + '" data-loytab="' + x[1] + '">' + h(x[0]) + "</button>"; }).join(" ") + "</div>" +
        '<iframe id="ghm-loyframe" src="' + h(loyFrameSrc()) + '"></iframe>' +
        '<div class="card ghm-card"><h3>Loyalty stamp cards</h3>' +
        '<p class="description">Customers collect stamps and win rewards. Everything (programs, stamps, rewards, notifications, nearby offers, wallet) is managed here and in the “Loyalty” menu on the left. The pages use the same look and the PL / EN / AR switch as the menu.</p>' +
        '<div style="display:flex;gap:10px;flex-wrap:wrap;margin:10px 0">' + stat(S.customers, "customers") + stat(S.active_cards, "active cards") + stat(S.stamps_today, "stamps today") + stat(S.rewards_issued, "rewards issued") + "</div>" +
        '<table class="ghm-t"><tbody>' + rows + "</tbody></table>" +
        (missing ? '<p><button class="button button-primary" type="button" id="ghm-lypages">Create the missing pages</button></p>' : "") +
        '<table class="ghm-t"><tbody><tr><th>Staff PIN<p class="description">The staff page asks for it. Give it to your team only.</p></th><td><code style="font-size:18px">' + h(LY.pin || "–") + '</code> <input type="text" id="ghm-lypin-new" inputmode="numeric" maxlength="8" size="8" placeholder="New PIN" style="margin-left:8px"> <button class="button" type="button" id="ghm-lypin-set">Set PIN</button> <button class="button" type="button" id="ghm-lypin">New random PIN</button></td></tr>' +
        '<tr><th>Page design</th><td>' + h(LY.theme === "dark" ? "Dark (like the home page)" : "Light (like the menu)") + ' – change it in <a href="' + h(A.settings) + '">Loyalty → Settings</a></td></tr></tbody></table>' +
'</div>';
    },
    cust: function () {
      if (!CU) { loadCust(); return '<p class="description">Loading…</p>'; }
      var rows = CU.rows || [], sum = 0; rows.forEach(function (r) { sum += r.sum; });
      var f = '=IMPORTDATA("' + CU.csv + '")';
      return '<div class="card ghm-card"><h3>Customers (' + rows.length + ')</h3>' +
        '<p class="description">Everyone who ordered through the website: name, phone and the approximate place (city) found from their internet address at the time of the order. The address itself is not kept – only its masked form. The list is kept even after the orders themselves are deleted (30 days). Tell customers about this in your privacy policy (GDPR/RODO).</p>' +
        '<p><input type="search" id="ghm-cuq" class="regular-text" placeholder="Search name / phone / city…"> ' +
        '<a class="button button-primary" href="' + h(CU.csv + "&dl=1") + '">⬇ Download CSV (opens in Excel / Sheets)</a> ' +
        '<button class="button" type="button" id="ghm-cusheet">📋 Copy &amp; open Google Sheets</button> ' +
        '<button class="button" type="button" id="ghm-curef">Refresh</button> <button class="button" type="button" id="ghm-cuall" style="color:#b32d2e">Delete all</button></p>' +
        '<p id="ghm-cumsg" class="description"></p>' +
        '<p class="description"><b>Live Google Sheet (always up to date):</b> in a new Google Sheet, click cell A1 and paste this formula – the list refreshes by itself. Keep the link secret.</p>' +
        '<input type="text" readonly class="large-text" value="' + h(f) + '" onclick="this.select()">' +
        '<table class="ghm-t" id="ghm-cut" style="margin-top:12px"><thead><tr><th>Name</th><th>Phone</th><th>Location (from IP)</th><th>Orders</th><th>Spent</th><th>Last order</th><th>Source</th><th></th></tr></thead><tbody>' +
        (rows.length ? rows.map(function (r) {
          return '<tr data-q="' + h((r.name + " " + r.phone + " " + r.geo).toLowerCase()) + '"><td>' + h(r.name) + '</td><td><a href="tel:' + h(r.phone) + '">' + h(r.phone) + '</a></td><td>' + h(r.geo || "–") + '<br><small>' + h(r.ip) + '</small></td><td>' + r.n + '</td><td>' + r.sum.toFixed(2) + ' zł</td><td>' + h(r.last) + '</td><td>' + h(r.src) + '</td><td><button class="button-link" type="button" data-cudel="' + h(r.k) + '" style="color:#b32d2e">✕</button></td></tr>';
        }).join("") : '<tr><td colspan="8">No customers yet.</td></tr>') + '</tbody></table><p class="description">Total spent: ' + sum.toFixed(2) + ' zł</p></div>';
    },
    home: function () {
      var H = DATA.home || (DATA.home = {}), B = H.buttons || (H.buttons = []);
      function ck(path, label, desc) { return '<label class="ck"><input type="checkbox" data-p="' + path + '" data-t="bool"' + (getP(path) ? " checked" : "") + "> " + label + "</label>" + (desc ? '<p class="description">' + h(desc) + "</p>" : ""); }
      function sel(path, opts) { var v = getP(path); return '<select data-p="' + path + '">' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (v === o[0] ? " selected" : "") + ">" + o[1] + "</option>"; }).join("") + "</select>"; }
      var status = CFG.homeUrl
        ? '<p>Home page address: <code>' + h(CFG.homeUrl) + '</code> <a class="button" target="_blank" href="' + h(CFG.homeUrl) + '">Open ↗</a></p><p class="description">With “Use it as the website front page” ticked below, the site address itself (<code>https://grillhub.pl/</code>) shows this page. You can also put the shortcode <code>[grill_hub_home]</code> on any page.</p>'
        : '<p>No page with the shortcode <code>[grill_hub_home]</code> exists yet. <button class="button button-primary" id="ghm-mkhome" type="button">Create the Home page</button></p>';
      var SN = { facebook: "Facebook", instagram: "Instagram", tiktok: "TikTok" }, DN = { wolt: "Wolt", ubereats: "Uber Eats", pyszne: "Pyszne.pl", bolt: "Bolt Food", glovo: "Glovo" };
      function linkRows(key, title, names) {
        return "<h4>" + title + '</h4><table class="ghm-t"><tbody>' + (H[key] || []).map(function (x, i) {
          return "<tr><th>" + h(names[x.id] || x.id) + "</th><td>" + ck("home." + key + "." + i + ".show", "Show") + '</td><td style="width:45%"><input type="text" class="large-text" data-p="home.' + key + "." + i + '.url" value="' + h(x.url) + '" placeholder="https://…"></td><td>' + imgField("home." + key + "." + i + ".img", "Logo / icon image (optional)") + "</td></tr>";
        }).join("") + "</tbody></table>";
      }
      var btns = B.map(function (b, i) {
        var p = "home.buttons." + i + ".";
        return '<details class="ghm-item' + (b.show ? "" : " is-hidden") + '" data-id="hb' + i + '"' + (st.open["hb" + i] ? " open" : "") + "><summary><b>" + h(b.label && b.label.pl || "Button") + "</b>" + (b.show ? "" : ' <em class="hid">hidden</em>') + '<span class="pr">' + h(b.url) + "</span></summary>" +
          '<div class="body">' + '<p>' + ck(p + "show", "<b>Visible</b> on the home page", "Untick to hide this button temporarily — nothing is deleted.") + "</p>" +
          l3(p + "label", "Button title") + l3(p + "sub", "Small text under the title", true) +
          '<div class="tools"><label>Link <input type="text" class="large-text" style="min-width:320px" data-p="' + p + 'url" value="' + h(b.url) + '" placeholder="https://… or {menu}"></label></div>' +
          '<p class="description">Special links: <code>{menu}</code> = your menu page, <code>{tel}</code> = call the phone number from “Hours, address &amp; SEO”, <code>{map}</code> = Google Maps with your address. Also allowed: <code>tel:+48…</code>, <code>mailto:…</code>.</p>' +
          '<div class="tools"><label>Icon ' + sel(p + "icon", [["bag", "Bag / order"], ["gift", "Gift / rewards"], ["star", "Star / review"], ["phone", "Phone"], ["pin", "Location"], ["whatsapp", "WhatsApp"], ["instagram", "Instagram"], ["facebook", "Facebook"], ["link", "Link"]]) + "</label>" +
          "<label>Look " + sel(p + "style", [["fire", "Red (solid, brand red)"], ["gold", "White"], ["glass", "Black with red outline"]]) + "</label>" +
          ck(p + "new_tab", "Open in a new tab") + '<span class="sp"></span><button class="button" data-hup="' + i + '">↑</button><button class="button" data-hdn="' + i + '">↓</button><button class="button button-link-delete" data-hdel="' + i + '">Delete</button></div></div></details>';
      }).join("");
      return '<div class="card ghm-card"><h3>Home page</h3>' + status +
        "<p>" + ck("home.enabled", "<b>Home page is on</b>", "Untick to switch the home page off (the shortcode then shows nothing).") + ck("home.root", "<b>Use it as the website front page</b> (grillhub.pl)", "On: opening the site address itself shows this page with the 3 buttons, no matter what is set in Settings → Reading.") + ck("home.standalone", "Show it without the theme header and footer (recommended)") + ck("home.embers", "Rising sparks animation") + ck("home.show_lang", "Show the language switcher (PL / EN / AR)") + '</p><p><span class="lb">Menu page address</span><input type="text" class="large-text" data-p="home.menu_url" value="' + h(H.menu_url || "") + '" placeholder="https://grillhub.pl/order/"></p><p class="description">Where the <code>{menu}</code> link of the “Order now” button goes. The menu itself is the page that contains <code>[grill_hub_menu]</code> (your <b>/order/</b> page).</p></div>' +
        '<div class="card ghm-card"><h3>Heading</h3>' + l3("home.badge", "Small badge above the title") + l3("home.title", "Main title") + l3("home.tagline", "Text under the title", true) + "</div>" +
        '<div class="card ghm-card"><h3>About text (for Google)</h3><p class="description">A short paragraph shown at the bottom of the home page. Keep the words people search for: kebab, grill, shawarma, wraps and your city.</p>' + l3("home.about_title", "Heading") + l3("home.about", "Paragraph", true) + "</div>" +
        '<div class="card ghm-card"><h3>Temporary notice</h3><p class="description">A highlighted line above the buttons — e.g. holiday hours, “kitchen closes at 21:00”.</p><p>' + ck("home.notice_show", "<b>Show the notice</b>") + "</p>" + l3("home.notice", "Notice text", true) + "</div>" +
        '<div class="card ghm-card"><h3>Buttons</h3><p class="description">Shown in this order. Open a button to change its text, link, icon or look; use ↑ ↓ to reorder.</p>' + (btns || '<p class="ghm-empty">No buttons.</p>') +
        '<p><button class="button button-primary" id="ghm-addhb" type="button">+ Add button</button></p></div>' +
        '<div class="card ghm-card"><h3>Social media and delivery links</h3><p class="description">Icons in the footer and the delivery buttons above the address. Untick to hide one; empty link = hidden. Choose an image to show a logo instead of the text or drawn icon (a square PNG works best for social icons; a wide PNG with a transparent background for delivery apps).</p>' + linkRows("social", "Social media", SN) + linkRows("delivery", "Delivery apps", DN) + "</div>" +
        '<div class="card ghm-card"><h3>Footer</h3>' + l3("home.footer", "Footer line") + "</div>";
    },
    settings: function () {
      return '<div class="card ghm-card"><h3>General</h3><table class="form-table"><tbody>' +
        row("WhatsApp number (with country code, digits only)", inp("settings.whatsapp"), "Orders are sent to this number. Example: 48500002249") +
        row("Name in the order message", inp("settings.restaurant"), "Shown at the top of the WhatsApp message") +
        row("Name shown in the header", inp("settings.brand")) +
        row("Main colour", inp("settings.color", { type: "color" })) +
        row("Logo", imgField("settings.logo", "Header logo (PNG with transparent background). Leave empty for the default flame")) +
        row("Default language", '<select data-p="settings.default_lang">' + LG.map(function (l) { return '<option value="' + l + '"' + (DATA.settings.default_lang === l ? " selected" : "") + ">" + LGN[l] + "</option>"; }).join("") + "</select>", "Customers can change it with the language button") +
        row("Animations", '<label class="ck"><input type="checkbox" data-p="settings.animations" data-t="bool"' + (DATA.settings.animations ? " checked" : "") + "> Smooth scrolling and motion effects (GSAP, Lenis, React Bits)</label>", "Turned off automatically for visitors who prefer reduced motion.") +
        row("Back to home", '<label class="ck"><input type="checkbox" data-p="settings.home_btn" data-t="bool"' + (DATA.settings.home_btn !== false ? " checked" : "") + "> Show a home button in the menu header</label>", "The logo in the menu header always leads to the home page.") +
        row("Standalone page", '<label><input type="checkbox" data-p="settings.standalone" data-t="bool"' + (DATA.settings.standalone ? " checked" : "") + "> Show the menu page without the theme header and footer</label>", "Recommended for the QR code. Turn off to embed it inside your theme layout.") +
        "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>How to show the menu</h3><p>Put the shortcode <code>[grill_hub_menu]</code> on any page. A page called <b>Menu</b> was created for you when the plugin was activated. Print a QR code that points to its address.</p></div>' +
        '<div class="card ghm-card"><h3>Footer links</h3><p class="description">The links in the footer on every page (home, menu, track order, loyalty). Untick to hide one. The built-in links find their page automatically; custom links open the address you type.</p>' + footerLinksHtml() +
        '<p><button class="button button-primary" id="ghm-addfl" type="button">+ Add custom link</button></p></div>';
    },
    biz: function () {
      var S = DATA.settings, DAYS = ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday", "Sunday"];
      function ck(path, label) { return '<label class="ck"><input type="checkbox" data-p="' + path + '" data-t="bool"' + (getP(path) ? " checked" : "") + "> " + label + "</label>"; }
      var hrs = (S.hours || []).map(function (x, i) {
        var p = "settings.hours." + i + ".";
        return "<tr><th>" + DAYS[i] + "</th><td>" + ck(p + "on", "Open") + "</td><td>" + inp(p + "open", { ph: "10:00" }) + "</td><td>" + inp(p + "close", { ph: "00:00" }) + "</td></tr>";
      }).join("");
      var mode = S.closed_mode || "block";
      var dishes = function (n) {
        var cur = String((S.upsell_ids || [])[n] || "");
        return '<select data-p="settings.upsell_ids.' + n + '"><option value="">— none —</option>' + DATA.items.map(function (it) { return '<option value="' + it.id + '"' + (String(it.id) === cur ? " selected" : "") + ">" + h(it.n.pl || "(no name)") + " · " + h(it.v[0] ? it.v[0].p : "") + " zł</option>"; }).join("") + "</select>";
      };
      return '<div class="card ghm-card"><h3>Restaurant details</h3><p class="description">Used for the address and the map button on the home page, and for Google (search results and Google Maps).</p><table class="form-table"><tbody>' +
        row("Street and number", inp("settings.address", { ph: "3 Maja 21" })) + row("Postal code (optional)", inp("settings.postal", { ph: "40-096" }), "Helps Google match the address. Leave empty if unsure.") + row("City", inp("settings.city")) +
        row("Phone number", inp("settings.phone", { ph: "+48 500 002 249" }), "Used by the “Call us” button. Leave empty to use the WhatsApp number from Settings.") +
        row("Google Maps link (optional)", inp("settings.maps_url", { ph: "https://maps.app.goo.gl/…" }), "Leave empty and the button opens Google Maps with your address. Paste the “Share” link of your Google Maps listing here if you want it to open that exact listing.") +
        "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Opening hours</h3><p class="description">Warsaw time. A closing time earlier than the opening time means after midnight: <b>02:00</b> = 2 a.m. the next day, <b>00:00</b> = midnight. Write times as 24-hour <b>HH:MM</b>. Untick “Open” for a day off.</p>' +
        '<table class="ghm-t hrs"><thead><tr><th></th><th></th><th>Opens</th><th>Closes</th></tr></thead><tbody>' + hrs + "</tbody></table>" +
        '<table class="form-table"><tbody>' +
        row("When the restaurant is closed", '<select data-p="settings.closed_mode"><option value="block"' + (mode === "block" ? " selected" : "") + '>Block ordering (customers can still browse the menu)</option><option value="warn"' + (mode === "warn" ? " selected" : "") + '>Only warn the customer (they can still order)</option><option value="off"' + (mode === "off" ? " selected" : "") + ">Do nothing</option></select>", "Blocking is enforced on the server for website orders. Orders sent only through WhatsApp are blocked on the page, but WhatsApp itself cannot be stopped.") +
        row("Home page badge", ck("settings.show_status", "Show “Open now / Closed” on the home page"), "Always uses the hours above, whatever the time on the visitor’s phone.") +
        "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Google &amp; sharing (SEO)</h3><p class="description">Description, preview image and restaurant data for the home page. Address, phone and hours above are also sent to Google as structured “Restaurant” data.</p><table class="form-table"><tbody>' +
        row("Page title", inp("settings.seo_title", { ph: "Grill Hub Katowice – Kebab, Shawarma & Grill · 3 Maja 21" }), "Leave empty for the automatic title (shown above).") +
        row("Description", inp("settings.seo_desc", { area: true }), "About 150 characters show in Google. Leave empty for an automatic description built from your details.") +
        row("English page title (/en/)", inp("settings.seo_title_en", { ph: "Kebab & Grill in Katowice – Grill Hub | 3 Maja 21" }), "The English home page is at your-site/en/. Leave empty for the automatic title.") +
        row("English description (/en/)", inp("settings.seo_desc_en", { area: true })) +
        row("Map coordinates (optional)", "Latitude " + inp("settings.lat", { ph: "50.2600" }) + " Longitude " + inp("settings.lng", { ph: "19.0200" }), "Right-click your restaurant on Google Maps and copy the two numbers. Helps Google place the restaurant exactly.") +
        row("Sharing image", imgField("settings.og_image", "Shown when the link is shared (WhatsApp, Facebook…). 1200×630 px works best. Leave empty for the default."), "") +
        "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Cart suggestions</h3><p class="description">Before sending, the cart suggests these dishes (skipped if already in the cart; only one drink is suggested if the cart already has a drink).</p><p>' + ck("settings.upsell_on", "<b>Suggest extras in the cart</b>") + "</p><table class=\"form-table\"><tbody>" +
        row("Suggestion 1", dishes(0)) + row("Suggestion 2", dishes(1)) + row("Suggestion 3", dishes(2)) + "</tbody></table></div>";
    },
    offers: function () {
      var S = DATA.settings, DN = ["Mon", "Tue", "Wed", "Thu", "Fri", "Sat", "Sun"];
      function ck(path, label) { return '<label class="ck"><input type="checkbox" data-p="' + path + '" data-t="bool"' + (getP(path) ? " checked" : "") + "> " + label + "</label>"; }
      function win(path) {
        var w = getP(path) || {}, d = w.days && w.days.length ? w.days : [0, 1, 2, 3, 4, 5, 6];
        return 'From ' + inp(path + ".from", { ph: "14:00" }) + " to " + inp(path + ".to", { ph: "17:00" }) + '<br>' + DN.map(function (n, i) { return '<label class="ck" style="margin-inline-end:8px"><input type="checkbox" data-wday="' + path + "|" + i + '"' + (d.indexOf(i) > -1 ? " checked" : "") + "> " + n + "</label>"; }).join("") + '<p class="description">Leave the times empty = all day. Times are Warsaw time (24h). Example 14:00–17:00 for the quiet afternoon.</p>';
      }
      function cats(path, withBuild) {
        var cur = getP(path) || [], l = (withBuild ? [{ id: "build", name: { pl: "Custom wrap (builder)" } }] : []).concat(DATA.cats);
        return l.map(function (c) { return '<label class="ck" style="margin-inline-end:10px"><input type="checkbox" data-ctog="' + path + "|" + h(c.id) + '"' + (cur.indexOf(c.id) > -1 ? " checked" : "") + "> " + h(c.name.pl || c.id) + "</label>"; }).join("");
      }
      function itemTog(path) {
        var cur = (getP(path) || []).map(String), by = {}; DATA.cats.forEach(function (c) { by[c.id] = c.name.pl || c.id; });
        return '<details><summary>' + (cur.length ? cur.length + " selected" : "none selected") + '</summary><div style="max-height:260px;overflow:auto;margin-top:6px">' + DATA.items.map(function (it) {
          return '<label class="ck" style="display:block"><input type="checkbox" data-ctog="' + path + "|" + it.id + '"' + (cur.indexOf(String(it.id)) > -1 ? " checked" : "") + "> " + h(it.n.pl || "#" + it.id) + ' <small style="color:#888">' + h(by[it.cat] || it.cat || "") + "</small></label>";
        }).join("") + "</div></details>";
      }
      var GF = S.gift || (S.gift = { on: false, tiers: [] }), opts = [];
      DATA.items.forEach(function (it) { it.v.forEach(function (v, vi) { opts.push([it.id + "|" + vi, (it.n.pl || "#" + it.id) + (v.l && v.l.pl ? " (" + v.l.pl + ")" : "") + " · " + v.p + " zł"]); }); });
      var trs = (GF.tiers || []).map(function (t, i) {
        var cur = t.id + "|" + (t.vi || 0), p = "settings.gift.tiers." + i + ".";
        return "<tr><td>" + inp(p + "min", { num: true }) + ' zł</td><td><select data-gsel="' + i + '"><option value="">— choose dish —</option>' + opts.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === cur ? " selected" : "") + ">" + h(o[1]) + "</option>"; }).join("") + '</select></td><td><button class="button button-link-delete" data-gdel="' + i + '">✕</button></td></tr>';
      }).join("");
      var gift = '<div class="card ghm-card"><h3>Offer: spend X, get a dish free</h3><p class="description">When the cart total (after discounts, before deposit and packaging) reaches the amount, the chosen dish is added <b>free</b> automatically. Below the amount the cart tells the customer how much is missing. If several levels are reached, only the highest gift is given.</p>' +
        '<p>' + ck("settings.gift.on", "<b>Offer is on</b>") + ck("settings.gift.popup", "Party popup with confetti when a visitor opens the home or menu page (once every 12 hours per phone)") + '</p><table class="ghm-t"><thead><tr><th>Spend at least</th><th>Free dish</th><th></th></tr></thead><tbody>' + (trs || '<tr><td colspan="3" class="ghm-empty">No levels yet.</td></tr>') + '</tbody></table><p><button class="button" id="ghm-addgift" type="button">+ Add level</button></p>' +
        '<table class="form-table"><tbody>' + row("Only at these times", win("settings.gift.win")) + "</tbody></table></div>";
      var cps = (S.coupons || []).map(function (c, i) {
        var p = "settings.coupons." + i + ".", used = (CFG.cpUsed || {})[c.code] || 0;
        return '<details class="ghm-item" data-id="cp' + i + '"' + (st.open["cp" + i] ? " open" : "") + "><summary><b>" + h(c.code || "NEW") + "</b>" + (c.on ? "" : ' <em class="hid">off</em>') + '<span class="pr">' + (c.kind === "amount" ? c.value + " zł" : c.value + "%") + " · used " + used + (c.max_uses ? "/" + c.max_uses : "") + "</span></summary><div class=\"body\"><table class=\"form-table\"><tbody>" +
          row("Active", ck(p + "on", "Coupon is active") + " " + ck(p + "popup", "Party popup with confetti")) + row("Code", inp(p + "code", { ph: "WELCOME10" }), "Letters and numbers (min 3). Customers type it in the cart. A link like " + (CFG.pageUrl || "/order/") + "?code=WELCOME10 fills it in automatically.") +
          row("Discount", '<select data-p="' + p + 'kind"><option value="percent"' + (c.kind !== "amount" ? " selected" : "") + '>Percent (%)</option><option value="amount"' + (c.kind === "amount" ? " selected" : "") + ">Amount (zł)</option></select> " + inp(p + "value", { num: true })) +
          row("Minimum order (zł)", inp(p + "min", { num: true }), "0 = no minimum.") + row("Maximum uses", inp(p + "max_uses", { num: true }), "0 = unlimited. 1 = a single customer can use it once (good for a first-order or a printed voucher).") +
          row("Only at these times", win(p + "win")) + row("Note (only you see it)", inp(p + "note")) + '</tbody></table><button class="button button-link-delete" data-cpdel="' + i + '">Delete coupon</button></div></details>';
      }).join("");
      var rvs = ((S.reviews || {}).items || []).map(function (x, i) {
        var p = "settings.reviews.items." + i + ".";
        return "<tr><td>" + inp(p + "name", { ph: "Name" }) + '</td><td style="width:70px">' + inp(p + "stars", { num: true }) + "</td><td>" + inp(p + "text", { area: true }) + '</td><td><button class="button button-link-delete" data-rvdel="' + i + '">✕</button></td></tr>';
      }).join("");
      var A = S.alert || {};
      var fcats = (DATA.cats || []).map(function (c) { return '<label class="ck" style="margin-inline-end:10px"><input type="checkbox" data-ctog="settings.fees.dep_cats|' + h(c.id) + '"' + (((S.fees || {}).dep_cats || []).indexOf(c.id) > -1 ? " checked" : "") + "> " + h(c.name.pl || c.id) + "</label>"; }).join("");
      return '<div class="card ghm-card"><h3>Deposit and packaging</h3><p class="description">Added at the end of the bill (after any discount) and printed on the kitchen receipt. The server calculates them, so the customer cannot change them.</p><table class="form-table"><tbody>' +
        row("Drink deposit (kaucja)", ck("settings.fees.dep_on", "Charge a deposit for drinks") + " " + inp("settings.fees.dep", { num: true }) + " zł per drink", "Example: 0.50 for every bottle / can.") + row("Deposit applies to", fcats) +
        row("Takeaway packaging (opakowanie)", ck("settings.fees.pack_on", "Charge for packaging on takeaway orders") + " " + inp("settings.fees.pack", { num: true }) + " zł per order", "Added once when the customer chooses “Odbiór osobisty” (pick-up).") + "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Waiting screen (after the customer orders)</h3><p class="description">After ordering the customer sees a big full-screen countdown with the steps Received → Preparing → Ready, and a loud sound + vibration when the kitchen taps <b>Gotowe</b>. While waiting you can show these cards:</p><table class="form-table"><tbody>' +
        row("Coupon for the next order", ck("settings.wait.next_on", "Show a coupon code") + " Code " + inp("settings.wait.next_code", { ph: "NEXT10" }), "Create the same code under “Discount coupons” below, otherwise it will not work. Good: 10% with 1 use per customer, valid only on weekdays.") + "</tbody></table>" + l3("settings.wait.next_text", "Text above the code") +
        '<table class="form-table"><tbody>' + row("Other cards", ck("settings.wait.follow_on", "Follow us (Instagram / Facebook / TikTok from the Home page)") + ck("settings.wait.review_on", "Rate us on Google (the Review button link)") + ck("settings.wait.rewards_on", "Collect stamps (the Rewards button link)")) + "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Offer: wrap + drink</h3><p class="description">Every wrap/box in the cart that is paired with a drink gets the discount below, automatically. Set a time window (e.g. 14:00–17:00 on weekdays) to fill the quiet hours; the home page then shows a gold “offer” badge only during that time.</p><table class="form-table"><tbody>' +
        row("Offer is on", ck("settings.combo.on", "Active") + " " + ck("settings.combo.popup", "Party popup with confetti")) + row("Discount per pair (zł)", inp("settings.combo.off", { num: true }), "Example: 3 = every wrap + drink pair costs 3 zł less.") +
        row("“Main” categories", cats("settings.combo.main_cats", true)) + row("“Drink” categories", cats("settings.combo.drink_cats", false)) +
        row("Only at these times", win("settings.combo.win")) + "</tbody></table>" + l3("settings.combo.label", "Offer name (shown in the cart and on the home page)") + "</div>" +
        '<div class="card ghm-card"><h3>Offer: N sandwiches for X zł</h3><p class="description">Example: 2 sandwiches for 30 zł. Every full group of N items from the chosen categories costs the fixed price (the most expensive items are grouped first). If the wrap + drink offer above also applies, the customer gets whichever discount is bigger, never both.</p><table class="form-table"><tbody>' +
        row("Offer is on", ck("settings.bundle.on", "Active") + " " + ck("settings.bundle.popup", "Party popup with confetti")) + row("Number of sandwiches (N)", inp("settings.bundle.n", { num: true })) + row("Price for the group (zł)", inp("settings.bundle.price", { num: true })) +
        row("Categories that count", cats("settings.bundle.cats", true)) +
        row("Always include these dishes", itemTog("settings.bundle.inc"), "Tick any dish (e.g. Joker) to make it count even if its category is not selected above.") +
        row("Never include these dishes", itemTog("settings.bundle.exc"), "Tick a dish to take it out of the offer even if its category counts.") +
        row("Only at these times", win("settings.bundle.win")) + "</tbody></table>" + l3("settings.bundle.label", "Offer name (shown in the cart and on the home page)") + "</div>" +
        gift +
        '<div class="card ghm-card"><h3>Discount coupons</h3><p class="description">Codes customers type in the cart. Use them in flyers, Instagram, a “first order” message, or as a voucher for a complaint. The discount is calculated on the server.</p>' + (cps || '<p class="ghm-empty">No coupons yet.</p>') + '<p><button class="button button-primary" id="ghm-addcp" type="button">+ Add coupon</button></p></div>' +
        '<div class="card ghm-card"><h3>Google reviews on the home page</h3><p class="description">Builds trust. Two ways: <b>(1) manual</b> — type your rating and paste your best reviews (copy them from Google Maps; only reviews you are allowed to show). <b>(2) automatic</b> — enter your Google Place ID and a Places API key and the plugin refreshes rating, count and the latest 5 reviews every 12 hours (Google Cloud account needed; if it fails, the manual values are shown).</p><table class="form-table"><tbody>' +
        row("Show on the home page", ck("settings.reviews.show", "Show the reviews section")) + row("Rating (1–5)", inp("settings.reviews.rating", { ph: "4.8" })) + row("Number of reviews", inp("settings.reviews.count", { ph: "230" })) +
        row("Link to your Google reviews", inp("settings.reviews.url", { ph: "https://search.google.com/local/reviews?placeid=…" }), "Opens when the customer taps “See all reviews”.") + "</tbody></table>" +
        '<h4>Reviews to show</h4><table class="ghm-t"><thead><tr><th>Name</th><th>Stars</th><th>Text</th><th></th></tr></thead><tbody>' + (rvs || '<tr><td colspan="4" class="ghm-empty">No reviews added.</td></tr>') + '</tbody></table><p><button class="button" id="ghm-addrv" type="button">+ Add review</button></p>' +
        '<h4>Automatic (optional)</h4><table class="form-table"><tbody>' + row("Google Place ID", inp("settings.reviews.place_id", { ph: "ChIJ…" })) + row("Places API key", inp("settings.reviews.api_key"), "Stays on the server; never shown to visitors.") + "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Alert when the kitchen page is not open</h3><p class="description">The kitchen page reports it is alive every few seconds. When an order arrives and the kitchen page has been silent (tablet off / page closed / no internet), the plugin sends an alert to your phone. You can use one or more channels.</p><table class="form-table"><tbody>' +
        row("When to alert", '<select data-p="settings.alert.mode"><option value="offline"' + (A.mode === "offline" ? " selected" : "") + '>Only when the kitchen page is offline (recommended)</option><option value="always"' + (A.mode === "always" ? " selected" : "") + '>Every order</option><option value="off"' + (A.mode === "off" ? " selected" : "") + ">Never</option></select>") +
        row("Offline after (seconds)", inp("settings.alert.offline_s", { num: true }), "Also used as a re-check: an order nobody opened within this time triggers the alert. 30–900.") +
        row("WhatsApp: your number", inp("settings.alert.wa_phone", { ph: "48500002249" }), "Free via CallMeBot: send “I allow callmebot to send me messages” to +34 644 51 95 23 on WhatsApp; it replies with your API key.") + row("WhatsApp: API key", inp("settings.alert.wa_key")) +
        row("Phone push (ntfy) topic", inp("settings.alert.ntfy_topic", { ph: "grillhub-kitchen-xyz123" }), "Free app “ntfy” (Android / iPhone): subscribe to this topic name (choose a long random one). Alerts arrive as urgent notifications that can ring loudly.") + row("ntfy server", inp("settings.alert.ntfy_server")) +
        row("E-mail", inp("settings.alert.email", { ph: "you@example.com" })) + "</tbody></table><p><button class=\"button\" id=\"ghm-alerttest\" type=\"button\">Send a test alert</button> <span id=\"ghm-alertres\" class=\"description\">Save first, then test.</span></p>" +
        '<p class="description"><b>Please note:</b> a normal WhatsApp message cannot ring like a phone call, and WhatsApp alerts depend on the free CallMeBot service. For the loudest alarm install ntfy on the owner’s phone. The tablet itself should stay on the kitchen page with the screen awake.</p></div>' +
        '<div class="card ghm-card"><h3>Ideas to fill the quiet hours</h3><ul style="list-style:disc;margin:0 18px"><li><b>Afternoon combo</b>: wrap + drink −3 zł, only 14:00–17:00 (set above). Post it on Instagram and the home page badge shows it automatically.</li><li><b>First order</b>: coupon <code>WELCOME10</code> (10%, 1 use) on flyers and in the Instagram bio link <code>?code=WELCOME10</code>.</li><li><b>QR on the table</b>: “Order from your seat” plus a free upgrade (fries) when ordering by QR in the afternoon.</li><li><b>Late-night</b> (Fri/Sat after midnight): a small combo discount for students and night workers.</li><li><b>Delivery apps → direct</b>: put a flyer in bags: “Order directly: code <code>DIRECT5</code> = −5 zł”. You save the app commission.</li><li><b>Review boost</b>: after a happy order, tap the “Review” button on the home page; fresh Google reviews raise your ranking.</li><li><b>Group deal</b>: 3 wraps + 3 drinks code <code>TEAM</code> for offices around 3 Maja during lunch.</li></ul></div>';
    },
    stats: function () {
      if (!ST) { loadStats(); return '<div class="card ghm-card"><p>Loading statistics…</p></div>'; }
      var R = st.range || "7", d = ST.d || {}, t = ST.t || {};
      function dates(n) { var a = [], b = new Date(ST.today + "T00:00:00Z"), i; for (i = 0; i < n; i++) { a.push(new Date(b.getTime() - i * 864e5).toISOString().slice(0, 10)); } return a; }
      var ds = R === "all" ? null : dates(+R);
      function val(k) { if (!ds) return +(t[k] || 0); var s = 0; ds.forEach(function (x) { s += +((d[x] || {})[k] || 0); }); return s; }
      function keys(pre) { var o = {}; Object.keys(t).forEach(function (k) { if (k.indexOf(pre) === 0) o[k] = 1; }); Object.keys(d).forEach(function (x) { Object.keys(d[x] || {}).forEach(function (k) { if (k.indexOf(pre) === 0) o[k] = 1; }); }); return Object.keys(o); }
      var vh = val("v:home"), vm = val("v:menu"), orders = val("o");
      function pct(n, of) { return of ? Math.round(n / of * 100) + "%" : "–"; }
      var btn = keys("b:").map(function (k) { return { id: k.slice(2), n: val(k) }; }).sort(function (a, b) { return b.n - a.n; });
      var dish = keys("i:").map(function (k) { var id = k.slice(2); return { name: id === "custom" ? "Custom wrap (builder)" : (ST.items[id] || "Dish #" + id + " (removed)"), n: val(k) }; }).filter(function (x) { return x.n > 0; }).sort(function (a, b) { return b.n - a.n; }).slice(0, 15);
      var up = 0; keys("u:").forEach(function (k) { up += val(k); });
      var rb = [["1", "Today"], ["7", "Last 7 days"], ["30", "Last 30 days"], ["all", "All time"]].map(function (r) { return '<button type="button" class="button' + (R === r[0] ? " button-primary" : "") + '" data-range="' + r[0] + '">' + r[1] + "</button>"; }).join(" ");
      var maxD = dish.length ? dish[0].n : 1;
      return '<div class="card ghm-card"><p>' + rb + ' <button type="button" class="button" id="ghm-stref">Refresh</button></p><p class="description">Anonymous counters: no cookies, no names, no phone numbers. Days follow Warsaw time. Numbers are approximate (bots and repeated taps are filtered only roughly).</p></div>' +
        '<div class="card ghm-card"><h3>Home page</h3><p><b>' + vh + "</b> visits</p>" +
        '<table class="ghm-t"><thead><tr><th>Button</th><th>Taps</th><th>Of visits</th></tr></thead><tbody>' + (btn.map(function (b) { return "<tr><td>" + h(ST.btns[b.id] || b.id) + "</td><td><b>" + b.n + "</b></td><td>" + pct(b.n, vh) + "</td></tr>"; }).join("") || '<tr><td colspan="3" class="ghm-empty">No taps yet.</td></tr>') + "</tbody></table></div>" +
        '<div class="card ghm-card"><h3>Menu and orders</h3><p><b>' + vm + "</b> menu visits · <b>" + orders + "</b> orders sent · <b>" + up + "</b> cart suggestions added</p>" +
        '<h4>Most ordered dishes (portions)</h4><table class="ghm-t"><tbody>' + (dish.map(function (x, i) { return "<tr><td>" + (i + 1) + ".</td><td>" + h(x.name) + '</td><td style="width:45%"><span class="bar" style="display:block;height:10px;border-radius:5px;background:#e11e26;width:' + Math.max(3, Math.round(x.n / maxD * 100)) + '%"></span></td><td><b>' + x.n + "</b></td></tr>"; }).join("") || '<tr><td class="ghm-empty">No orders counted yet.</td></tr>') + "</tbody></table>" +
        '<p class="description">Counts every order sent from the menu (to the kitchen page or opened in WhatsApp). The customer sending it on in WhatsApp is not confirmed, so a repeated tap can count twice.</p></div>' +
        srcCard(keys, val, pct) + offCard(keys, val) + linksCard() +
        '<div class="card ghm-card"><p><button type="button" class="button button-link-delete" id="ghm-streset">Reset all statistics</button></p></div>';
    },
    cats: function () {
      var rows = DATA.cats.map(function (c, i) {
        var n = DATA.items.filter(function (x) { return x.cat === c.id; }).length;
        return '<div class="ghm-row"><div class="ctl"><button class="button" data-cup="' + i + '" title="Move up">↑</button><button class="button" data-cdn="' + i + '" title="Move down">↓</button></div>' +
          '<div class="grow"><code class="cid">' + h(c.id) + " · " + n + " dishes</code>" + l3("cats." + i + ".name", "Category name") + "</div>" +
          '<button class="button button-link-delete" data-cdel="' + i + '">Delete</button></div>';
      }).join("");
      return '<div class="card ghm-card"><p>The wrap builder card always appears first. Categories listed here appear in this order in the menu.</p>' + rows +
        '<p><button class="button button-primary" id="ghm-addcat">+ Add category</button></p></div>';
    },
    items: function () {
      var cats = DATA.cats;
      var opts = '<option value="__all"' + (st.cat === "__all" ? " selected" : "") + ">All categories</option>" + cats.map(function (c) { return '<option value="' + h(c.id) + '"' + (st.cat === c.id ? " selected" : "") + ">" + h(c.name.pl) + "</option>"; }).join("");
      var q = st.q.toLowerCase();
      var list = DATA.items.map(function (it, i) { return { it: it, i: i }; }).filter(function (o) {
        if (st.cat !== "__all" && o.it.cat !== st.cat) return false;
        if (!q) return true;
        return LG.map(function (l) { return (o.it.n[l] || "") + " " + (o.it.d[l] || ""); }).join(" ").toLowerCase().indexOf(q) > -1;
      });
      return '<div class="ghm-filter"><select id="ghm-catsel">' + opts + '</select><input type="search" id="ghm-q" placeholder="Search dishes…" value="' + h(st.q) + '"><button class="button button-primary" id="ghm-additem">+ Add dish</button></div>' +
        (list.length ? list.map(function (o) { return itemCard(o.it, o.i); }).join("") : '<p class="ghm-empty">No dishes here yet.</p>');
    },
    builder: function () {
      var b = "builder.";
      function pr(label, path) { return '<tr><th>' + h(label) + "</th><td>" + inp(path, { num: true }) + " zł</td></tr>"; }
      return '<div class="card ghm-card"><h3>Wrap builder</h3><p><label><input type="checkbox" data-p="builder.enabled" data-t="bool"' + (DATA.builder.enabled ? " checked" : "") + "> Show the “Build your wrap” option in the menu</label></p>" +
        '<h4>Base prices</h4><table class="ghm-t"><tbody>' + pr("Chicken, large", b + "base.kurczak.Duży") + pr("Chicken, small", b + "base.kurczak.Mały") + pr("Beef, large", b + "base.wolowina.Duży") + pr("Beef, small", b + "base.wolowina.Mały") + pr("Mix (chicken + beef), large", b + "base.mix.Duży") + pr("Mix (chicken + beef), small", b + "base.mix.Mały") + "</tbody></table>" +
        '<h4>Included portions and price of every extra portion</h4><table class="ghm-t"><thead><tr><th></th><th>Included (free)</th><th>Each extra</th></tr></thead><tbody>' +
        '<tr><th>Sauces</th><td>' + inp(b + "sauceFree", { num: true }) + "</td><td>" + inp(b + "sauce", { num: true }) + " zł</td></tr>" +
        '<tr><th>Salads / vegetables</th><td>' + inp(b + "vegFree", { num: true }) + "</td><td>" + inp(b + "veg", { num: true }) + " zł</td></tr>" +
        '<tr><th>Pickles</th><td>' + inp(b + "pickleFree", { num: true }) + "</td><td>" + inp(b + "pickle", { num: true }) + " zł</td></tr></tbody></table>" +
        '<h4>Paid extras (price per portion, max 2 portions)</h4><table class="ghm-t"><tbody>' + pr("Fries", b + "fries") + pr("Cheese", b + "cheese") + "</tbody></table>" +
        '<p class="description">Names of the sauces, salads and pickles can be edited in the <b>Texts</b> tab (search “ing_”).</p></div>';
    },
    texts: function () {
      var q = st.tq.toLowerCase(), keys = Object.keys(STRD.pl).filter(function (k) { return k.indexOf("t_") !== 0 || true; });
      keys = keys.filter(function (k) { return !q || (k + " " + LG.map(function (l) { return STRD[l][k] || ""; }).join(" ")).toLowerCase().indexOf(q) > -1; });
      return '<div class="card ghm-card"><p>Every text shown to customers. Leave a box empty to keep the default text.</p><p><input type="search" id="ghm-tq" placeholder="Search texts…" value="' + h(st.tq) + '"></p>' +
        '<table class="ghm-t texts"><thead><tr><th>Key</th>' + LG.map(function (l) { return "<th>" + LGN[l] + "</th>"; }).join("") + "</tr></thead><tbody>" +
        keys.map(function (k) {
          return "<tr><th><code>" + h(k) + "</code></th>" + LG.map(function (l) {
            var def = STRD[l][k] || "", p = "strings." + l + "." + k, long = def.length > 60;
            return "<td>" + (long ? '<textarea rows="3"' : '<input type="text"') + ' data-p="' + p + '" data-t="str" placeholder="' + h(def) + '"' + (l === "ar" ? ' dir="rtl"' : "") + (long ? ">" + h(getP(p) || "") + "</textarea>" : ' value="' + h(getP(p) || "") + '">') + "</td>";
          }).join("") + "</tr>";
        }).join("") + "</tbody></table></div>";
    },
    tools: function () {
      return '<div class="card ghm-card"><h3>Backup</h3><p>Download all menu data as a file, or load a file you saved before. After loading, press <b>Save changes</b>.</p>' +
        '<p><button class="button" id="ghm-export">Download backup (.json)</button> <label class="button">Load backup… <input type="file" id="ghm-import" accept=".json,application/json" hidden></label></p>' +
        '<h3>Reset</h3><p>Restore the original menu (dishes, prices, texts and settings). This cannot be undone.</p><p><button class="button button-link-delete" id="ghm-reset">Reset everything to defaults</button></p></div>';
    }
  };
  function row(label, field, desc) { return "<tr><th scope=\"row\">" + h(label) + "</th><td>" + field + (desc ? '<p class="description">' + h(desc) + "</p>" : "") + "</td></tr>"; }

  function itemCard(it, i) {
    var p = "items." + i + ".", prices = it.v.map(function (v) { return v.p; }), open = st.open[it.id];
    var vrows = it.v.map(function (v, vi) {
      return '<div class="vrow"><div class="grow">' + l3(p + "v." + vi + ".l", "Option name (optional, e.g. Large / Small / Chicken)") + '</div><div class="vp"><span class="lb">Price (zł)</span>' + inp(p + "v." + vi + ".p", { num: true }) + "</div>" +
        imgField(p + "v." + vi + ".img", "Photo for this option (optional)") + (it.v.length > 1 ? '<button class="button button-link-delete" data-vdel="' + i + "." + vi + '">Remove option</button>' : "") + "</div>";
    }).join("");
    return '<details class="ghm-item' + (it.hidden ? " is-hidden" : "") + '" data-id="' + it.id + '"' + (open ? " open" : "") + "><summary><span class=\"th\" style=\"background-image:url('" + h(url(it.img || (it.v[0] && it.v[0].img))) + "')\"></span><b>" + h(it.n.pl || "(no name)") + "</b><span class=\"pr\">" + prices.map(function (x) { return h(x); }).join(" / ") + " zł</span>" + (it.hidden ? '<em class="hid">hidden</em>' : "") + (it.soon ? '<em class="hid">coming soon</em>' : "") + "</summary>" +
      '<div class="body">' + l3(p + "n", "Name") + l3(p + "d", "Description", true) + imgField(p + "img", "Main photo") +
      '<div class="vbox"><span class="lb">Prices / options</span>' + vrows + '<button class="button" data-vadd="' + i + '">+ Add option</button></div>' +
      '<div class="tools"><label>Category <select data-p="' + p + 'cat">' + DATA.cats.map(function (c) { return '<option value="' + h(c.id) + '"' + (it.cat === c.id ? " selected" : "") + ">" + h(c.name.pl) + "</option>"; }).join("") + "</select></label>" +
      (it.v.length > 1 ? '<label>Preselected option <select data-p="' + p + 'dv">' + it.v.map(function (v, vi) { return '<option value="' + vi + '"' + ((+it.dv || 0) === vi ? " selected" : "") + ">" + h(v.l.pl || "#" + (vi + 1)) + "</option>"; }).join("") + "</select></label> " : "") +
      '<label><input type="checkbox" data-p="' + p + 'hidden" data-t="bool"' + (it.hidden ? " checked" : "") + "> Hide from menu</label>" +
      '<label><input type="checkbox" data-p="' + p + 'soon" data-t="bool"' + (it.soon ? " checked" : "") + "> Coming soon (shown, but no order button)</label>" +
      '<span class="sp"></span><button class="button" data-iup="' + i + '">↑</button><button class="button" data-idn="' + i + '">↓</button><button class="button" data-idup="' + i + '">Duplicate</button><button class="button button-link-delete" data-idel="' + i + '">Delete</button></div></div></details>';
  }

  var SRCN = { qr: "QR code (tables)", direct: "Direct / unknown", google: "Google search", gbp: "Google Business Profile", gbporder: "Google Maps “Order” button", instagram: "Instagram", facebook: "Facebook", tiktok: "TikTok", wolt: "Wolt", ubereats: "Uber Eats", pyszne: "Pyszne.pl", bolt: "Bolt Food", glovo: "Glovo", whatsapp: "WhatsApp", search: "Other search engines", referral: "Other websites", flyer: "Flyer / print", other: "Other" };
  function srcCard(keys, val, pct) {
    var seen = {}; keys("s:").concat(keys("os:")).forEach(function (k) { seen[k.slice(k.indexOf(":") + 1)] = 1; });
    var rows = Object.keys(seen).map(function (s) { return { s: s, v: val("s:" + s), o: val("os:" + s), r: val("rs:" + s) }; }).sort(function (a, b) { return (b.o - a.o) || (b.v - a.v); });
    var tv = 0, to = 0, tr = 0; rows.forEach(function (x) { tv += x.v; to += x.o; tr += x.r; });
    return '<div class="card ghm-card"><h3>Where customers come from</h3><table class="ghm-t"><thead><tr><th>Source</th><th>Visits</th><th>Orders</th><th>Orders / visits</th><th>Order value</th></tr></thead><tbody>' +
      (rows.map(function (x) { return "<tr><td>" + h(SRCN[x.s] || x.s) + "</td><td>" + x.v + "</td><td><b>" + x.o + "</b></td><td>" + pct(x.o, x.v) + "</td><td>" + x.r + " zł</td></tr>"; }).join("") || '<tr><td colspan="5" class="ghm-empty">Nothing counted yet.</td></tr>') +
      "</tbody>" + (rows.length ? "<tfoot><tr><th>Total</th><th>" + tv + "</th><th>" + to + "</th><th>" + pct(to, tv) + "</th><th>" + tr + " zł</th></tr></tfoot>" : "") + '</table><p class="description">A visit is counted once per browser session and keeps the <b>first</b> source (home page → menu → order). Order value is the price of the orders sent, not payments. Visitors without a tracking link are guessed from the site they came from; Instagram, WhatsApp and Maps often hide it, so give each place its own link below.</p></div>';
  }
  function offCard(keys, val) {
    var cps = keys("cp:").map(function (k) { return { c: k.slice(3), n: val(k) }; }).filter(function (x) { return x.n > 0; }).sort(function (a, b) { return b.n - a.n; });
    return '<div class="card ghm-card"><h3>Offers and coupons</h3><p><b>' + val("cb") + "</b> orders with the wrap + drink offer · <b>" + val("ds") + " zł</b> given as discounts</p>" +
      '<table class="ghm-t"><tbody>' + (cps.map(function (x) { return "<tr><td><code>" + h(x.c) + "</code></td><td><b>" + x.n + "</b> orders</td></tr>"; }).join("") || '<tr><td class="ghm-empty">No coupon used yet.</td></tr>') + "</tbody></table></div>";
  }
  function linksCard() {
    var site = CFG.siteUrl || "https://grillhub.pl/", menu = CFG.pageUrl || site + "order/";
    function u(base, src, extra) { return base + (base.indexOf("?") > -1 ? "&" : "?") + "src=" + src + (extra || ""); }
    var L = [["QR code on the tables (open the menu)", u(menu, "qr"), "Add &t=7 for table 7: the table number is filled in for the customer. Make one QR per table, or one for all."], ["Instagram bio link", u(site, "instagram")], ["Facebook page “website” / posts", u(site, "facebook")], ["TikTok bio link", u(site, "tiktok")],
      ["Google Business Profile – website", u(site, "gbp")], ["Google Business Profile – “Order” / menu link", u(menu, "gbporder")], ["WhatsApp status / broadcast", u(site, "whatsapp")], ["Flyers and print", u(site, "flyer")]];
    return '<div class="card ghm-card"><h3>Tracking links</h3><p class="description">Use these links in each place. Any word works: add <code>?src=yourword</code> to your address and it shows up in the table above.</p><table class="ghm-t"><tbody>' +
      L.map(function (x) { return "<tr><th>" + h(x[0]) + '</th><td><input type="text" readonly class="large-text" value="' + h(x[1]) + '" onclick="this.select()">' + (x[2] ? '<p class="description">' + h(x[2]) + "</p>" : "") + "</td></tr>"; }).join("") + "</tbody></table></div>";
  }
  function loadCust(extra) {
    if (cuLoading) return; cuLoading = true;
    $.post(CFG.ajax, $.extend({ action: "ghm_customers", nonce: CFG.nonce }, extra || {})).done(function (r) { cuLoading = false; if (r && r.success) { CU = r.data; if (st.tab === "cust") render(); } }).fail(function () { cuLoading = false; });
  }
  $(root).on("input", "#ghm-cuq", function () { var q = this.value.toLowerCase(); $("#ghm-cut tbody tr").each(function () { this.style.display = !q || (this.getAttribute("data-q") || "").indexOf(q) > -1 ? "" : "none"; }); });
  $(root).on("click", "#ghm-curef", function () { CU = null; render(); });
  $(root).on("click", "[data-cudel]", function () { if (confirm("Remove this customer from the list?")) loadCust({ del: this.getAttribute("data-cudel") }); });
  $(root).on("click", "#ghm-cuall", function () { if (confirm("Delete the WHOLE customer list? This cannot be undone.")) loadCust({ delall: 1 }); });
  $(root).on("click", "#ghm-cusheet", function () {
    var q = function (v) { return String(v == null ? "" : v).replace(/[\t\r\n]+/g, " "); };
    var tsv = ["Name", "Phone", "Location (from IP)", "Orders", "Spent (zl)", "Last order", "Source"].join("\t") + "\n" + (CU.rows || []).map(function (r) { return [r.name, r.phone, r.geo, r.n, r.sum, r.last, r.src].map(q).join("\t"); }).join("\n");
    var done = function (ok) { $("#ghm-cumsg").text(ok ? "Copied ✓ – in the new Google Sheet click cell A1 and press Ctrl+V (Cmd+V on Mac)." : "Could not copy automatically – use the Download CSV button, then File → Import in Google Sheets."); window.open("https://sheets.new", "_blank"); };
    try { navigator.clipboard.writeText(tsv).then(function () { done(true); }, function () { done(false); }); } catch (e) { done(false); }
  });
  function loadLoy(extra) {
    if (lyLoading) return; lyLoading = true;
    $.post(CFG.ajax, $.extend({ action: "ghm_ls", nonce: CFG.nonce }, extra || {})).done(function (r) { lyLoading = false; LY = r && r.success ? r.data : { err: (r && r.data) || "Error" }; if (st.tab === "loy" || st.tab === "dash") render(); }).fail(function () { lyLoading = false; LY = { err: "Request failed" }; if (st.tab === "loy" || st.tab === "dash") render(); });
  }
  $(root).on("click", "#ghm-lypages", function () { $(this).prop("disabled", true); loadLoy({ "do": "pages" }); });
  $(root).on("click", "#ghm-lypin", function () { if (confirm("Generate a new staff PIN? The old one stops working.")) loadLoy({ "do": "pin" }); });
  $(root).on("click", "#ghm-lypin-set", function () {
    var v = ($("#ghm-lypin-new").val() || "").replace(/\D/g, "");
    if (v.length < 4 || v.length > 8) { alert("PIN must be 4-8 digits."); return; }
    if (confirm("Change the staff PIN to " + v + "? The old one stops working.")) loadLoy({ "do": "setpin", "pin": v });
  });
  $(root).on("click", "#ghm-dashpin", function () { if (confirm("Generate a new staff PIN? The old one stops working.")) loadLoy({ "do": "pin" }); });
  $(root).on("click", "#ghm-dashpin-set", function () {
    var v = ($("#ghm-dashpin-new").val() || "").replace(/\D/g, "");
    if (v.length < 4 || v.length > 8) { alert("PIN must be 4-8 digits."); return; }
    if (confirm("Change the staff PIN to " + v + "? The old one stops working.")) loadLoy({ "do": "setpin", "pin": v });
  });
  /* ---- footer links ---- */
  $(root).on("click", "#ghm-addfl", function () {
    var L = DATA.settings.footer_links = DATA.settings.footer_links || [], n = 1;
    L.forEach(function (x) { var m = /^c(\d+)$/.exec(x.id || ""); if (m) n = Math.max(n, +m[1] + 1); });
    L.push({ id: "c" + n, on: true, url: "https://", label: { pl: "", en: "", ar: "" } });
    st.open["fl" + (L.length - 1)] = true; dirty(); render();
  });
  $(root).on("click", "[data-flup],[data-fldn]", function (e) { e.preventDefault(); var i = +(this.getAttribute("data-flup") || this.getAttribute("data-fldn")); if (move(DATA.settings.footer_links, i, this.hasAttribute("data-flup") ? -1 : 1)) { dirty(); render(); } });
  $(root).on("click", "[data-fldel]", function (e) { e.preventDefault(); var i = +this.getAttribute("data-fldel"); if (!confirm("Delete this link?")) return; DATA.settings.footer_links.splice(i, 1); dirty(); render(); });
  function loadStats() {
    if (stLoading) return; stLoading = true;
    $.post(CFG.ajax, { action: "ghm_stats", nonce: CFG.nonce }).done(function (r) { stLoading = false; if (r && r.success) { ST = r.data; if (st.tab === "stats") render(); } }).fail(function () { stLoading = false; });
  }
  $(root).on("click", "[data-range]", function () { st.range = this.getAttribute("data-range"); render(); });
  $(root).on("click", "#ghm-stref", function () { ST = null; render(); });
  $(root).on("click", "#ghm-streset", function () {
    if (!confirm("Delete all statistics? This cannot be undone.")) return;
    $.post(CFG.ajax, { action: "ghm_stats_reset", nonce: CFG.nonce }).done(function () { ST = null; render(); });
  });

  /* ---- offers & alerts ---- */
  $(root).on("change", "[data-wday]", function () {
    var a = this.getAttribute("data-wday").split("|"), w = getP(a[0]) || {}, d = (w.days && w.days.length ? w.days : [0, 1, 2, 3, 4, 5, 6]).slice(), i = +a[1], k = d.indexOf(i);
    if (this.checked && k < 0) d.push(i); if (!this.checked && k > -1) d.splice(k, 1);
    setP(a[0] + ".days", d.sort()); dirty();
  });
  $(root).on("change", "[data-ctog]", function () {
    var a = this.getAttribute("data-ctog").split("|"), d = (getP(a[0]) || []).slice(), k = d.indexOf(a[1]);
    if (this.checked && k < 0) d.push(a[1]); if (!this.checked && k > -1) d.splice(k, 1);
    setP(a[0], d); dirty();
  });
  $(root).on("change", "[data-gsel]", function () { var t = DATA.settings.gift.tiers[+this.getAttribute("data-gsel")], v = this.value.split("|"); t.id = +v[0] || 0; t.vi = +v[1] || 0; dirty(); });
  $(root).on("click", "#ghm-addgift", function () { var G = DATA.settings.gift || (DATA.settings.gift = { on: false, tiers: [], win: { from: "", to: "", days: [0, 1, 2, 3, 4, 5, 6] } }); if (G.tiers.length >= 4) return alert("Maximum 4 levels."); G.tiers.push({ min: G.tiers.length ? +G.tiers[G.tiers.length - 1].min + 30 : 50, id: 0, vi: 0 }); dirty(); render(); });
  $(root).on("click", "[data-gdel]", function () { DATA.settings.gift.tiers.splice(+this.getAttribute("data-gdel"), 1); dirty(); render(); });
  $(root).on("click", "#ghm-addcp", function () {
    var S = DATA.settings; S.coupons = S.coupons || [];
    S.coupons.push({ code: "NEW" + (S.coupons.length + 1) + "0", on: true, kind: "percent", value: 10, min: 0, max_uses: 0, win: { from: "", to: "", days: [0, 1, 2, 3, 4, 5, 6] }, note: "" });
    st.open["cp" + (S.coupons.length - 1)] = true; dirty(); render();
  });
  $(root).on("click", "[data-cpdel]", function () { if (!confirm("Delete this coupon?")) return; DATA.settings.coupons.splice(+this.getAttribute("data-cpdel"), 1); dirty(); render(); });
  $(root).on("click", "#ghm-addrv", function () { var R = DATA.settings.reviews; R.items = R.items || []; if (R.items.length >= 8) return alert("Maximum 8 reviews."); R.items.push({ name: "", text: "", stars: 5 }); dirty(); render(); });
  $(root).on("click", "[data-rvdel]", function () { DATA.settings.reviews.items.splice(+this.getAttribute("data-rvdel"), 1); dirty(); render(); });
  $(root).on("click", "#ghm-alerttest", function () {
    var out = $("#ghm-alertres").text("Sending…");
    $.post(CFG.ajax, { action: "ghm_alert_test", nonce: CFG.nonce }).done(function (r) { out.text(r && r.success ? r.data.join("  ·  ") : "Error: " + (r && r.data || "failed")); }).fail(function () { out.text("Request failed"); });
  });

  /* ---- events ---- */
  $(root).on("input change", "[data-p]", function () {
    var t = this.getAttribute("data-t"), v;
    if (t === "bool") v = this.checked; else v = this.value;
    if (t === "str") { setP(this.getAttribute("data-p"), v); }
    else setP(this.getAttribute("data-p"), v);
    dirty();
    if (this.tagName === "SELECT" && /\.cat$/.test(this.getAttribute("data-p"))) { render(); }
  });
  root.addEventListener("toggle", function (e) { if (e.target.classList && e.target.classList.contains("ghm-item")) st.open[e.target.getAttribute("data-id")] = e.target.open; }, true);
  $(root).on("click", "[data-tab]", function (e) { e.preventDefault(); st.tab = this.getAttribute("data-tab"); render(); });
  $(root).on("change", "#ghm-catsel", function () { st.cat = this.value; render(); });
  $(root).on("input", "#ghm-q", function () { st.q = this.value; var pos = this.selectionStart; render(); var el = document.getElementById("ghm-q"); el.focus(); el.setSelectionRange(pos, pos); });
  $(root).on("input", "#ghm-tq", function () { st.tq = this.value; var pos = this.selectionStart; render(); var el = document.getElementById("ghm-tq"); el.focus(); el.setSelectionRange(pos, pos); });

  function move(arr, i, d) { var j = i + d; if (j < 0 || j >= arr.length) return false; var t = arr[i]; arr[i] = arr[j]; arr[j] = t; return true; }
  function nextId() { var m = 0; DATA.items.forEach(function (x) { m = Math.max(m, x.id); }); return m + 1; }
  function blank(cat) { return { id: nextId(), cat: cat, hidden: false, n: { pl: "", en: "", ar: "" }, d: { pl: "", en: "", ar: "" }, img: "", v: [{ l: { pl: "", en: "", ar: "" }, p: 0, img: "" }] }; }

  $(root).on("click", "button[data-iup],button[data-idn]", function () {
    var i = +(this.getAttribute("data-iup") || this.getAttribute("data-idn")), d = this.hasAttribute("data-iup") ? -1 : 1, it = DATA.items[i];
    var j = i + d; while (j >= 0 && j < DATA.items.length && DATA.items[j].cat !== it.cat) j += d;
    if (j < 0 || j >= DATA.items.length) return;
    DATA.items[i] = DATA.items[j]; DATA.items[j] = it; dirty(); st.open[it.id] = true; render();
  });
  $(root).on("click", "[data-idup]", function () { var i = +this.getAttribute("data-idup"), c = clone(DATA.items[i]); c.id = nextId(); c.n.pl = (c.n.pl || "") + " (copy)"; DATA.items.splice(i + 1, 0, c); st.open[c.id] = true; dirty(); render(); });
  $(root).on("click", "[data-idel]", function () { var i = +this.getAttribute("data-idel"); if (!confirm("Delete “" + (DATA.items[i].n.pl || "this dish") + "”?")) return; DATA.items.splice(i, 1); dirty(); render(); });
  $(root).on("click", "#ghm-additem", function () { var cat = st.cat === "__all" ? (DATA.cats[0] || {}).id : st.cat; if (!cat) return alert("Create a category first."); var it = blank(cat); DATA.items.push(it); st.open[it.id] = true; st.q = ""; dirty(); render(); setTimeout(function () { var el = document.querySelector('details[data-id="' + it.id + '"]'); if (el) el.scrollIntoView({ block: "center" }); }, 30); });
  $(root).on("click", "[data-vadd]", function () { var it = DATA.items[+this.getAttribute("data-vadd")]; it.v.push({ l: { pl: "", en: "", ar: "" }, p: 0, img: "" }); st.open[it.id] = true; dirty(); render(); });
  $(root).on("click", "[data-vdel]", function () { var p = this.getAttribute("data-vdel").split("."), it = DATA.items[+p[0]]; it.v.splice(+p[1], 1); st.open[it.id] = true; dirty(); render(); });

  $(root).on("click", "[data-cup],[data-cdn]", function () { var i = +(this.getAttribute("data-cup") || this.getAttribute("data-cdn")); if (move(DATA.cats, i, this.hasAttribute("data-cup") ? -1 : 1)) { dirty(); render(); } });
  $(root).on("click", "[data-cdel]", function () {
    var i = +this.getAttribute("data-cdel"), c = DATA.cats[i], n = DATA.items.filter(function (x) { return x.cat === c.id; }).length;
    if (!confirm("Delete category “" + c.name.pl + "”" + (n ? " and its " + n + " dishes" : "") + "?")) return;
    DATA.items = DATA.items.filter(function (x) { return x.cat !== c.id; }); DATA.cats.splice(i, 1); st.cat = ""; dirty(); render();
  });
  $(root).on("click", "#ghm-addcat", function () {
    var name = prompt("Category name (Polish):"); if (!name) return;
    var id = name.toLowerCase().replace(/[^a-z0-9]/g, "").slice(0, 20) || "cat", base = id, n = 2;
    while (id === "build" || DATA.cats.some(function (c) { return c.id === id; })) id = base + n++;
    DATA.cats.push({ id: id, name: { pl: name, en: name, ar: name } }); st.cat = id; dirty(); render();
  });

  $(root).on("click", "[data-hup],[data-hdn]", function (e) { e.preventDefault(); var i = +(this.getAttribute("data-hup") || this.getAttribute("data-hdn")); if (move(DATA.home.buttons, i, this.hasAttribute("data-hup") ? -1 : 1)) { st.open = {}; dirty(); render(); } });
  $(root).on("click", "[data-hdel]", function (e) { e.preventDefault(); var i = +this.getAttribute("data-hdel"); if (!confirm("Delete this button?")) return; DATA.home.buttons.splice(i, 1); st.open = {}; dirty(); render(); });
  $(root).on("click", "#ghm-addhb", function () { var B = DATA.home.buttons; if (B.length >= 8) return alert("Maximum 8 buttons."); B.push({ id: "b" + (B.length + 1), show: true, icon: "link", style: "glass", new_tab: true, url: "https://", label: { pl: "", en: "", ar: "" }, sub: { pl: "", en: "", ar: "" } }); st.open["hb" + (B.length - 1)] = true; dirty(); render(); });
  $(root).on("click", "#ghm-mkhome", function () { var b = $(this).prop("disabled", true); $.post(CFG.ajax, { action: "ghm_mkhome", nonce: CFG.nonce }).done(function (r) { if (r && r.success) { CFG.homeUrl = r.data.url; render(); } else b.prop("disabled", false); }); });

  var frame;
  $(root).on("click", "[data-pick]", function () {
    var path = this.getAttribute("data-pick");
    frame = wp.media({ title: "Choose image", multiple: false, library: { type: "image" }, button: { text: "Use this image" } });
    frame.on("select", function () {
      var a = frame.state().get("selection").first().toJSON(), u = (a.sizes && (a.sizes.large || a.sizes.medium_large) || a).url;
      setP(path, u); dirty(); render();
    });
    frame.open();
  });
  $(root).on("click", "[data-clr]", function () { setP(this.getAttribute("data-clr"), ""); dirty(); render(); });

  function save() {
    var b = $("#ghm-save").prop("disabled", true); $("#ghm-state").text("Saving…").attr("class", "ghm-state");
    $.post(CFG.ajax, { action: "ghm_save", nonce: CFG.nonce, data: JSON.stringify(DATA) }).done(function (r) {
      if (r && r.success) { DATA = r.data; st.dirty = false; render(); $("#ghm-bar").show(); $("#ghm-state").text("Saved ✓").attr("class", "ghm-state ok"); setTimeout(function(){ if (!st.dirty) { $("#ghm-state").text(""); if (!CFG.homeUrl) $("#ghm-bar").hide(); } }, 2500); }
      else { b.prop("disabled", false); $("#ghm-state").text("Could not save").attr("class", "ghm-state err"); }
    }).fail(function () { b.prop("disabled", false); $("#ghm-state").text("Could not save (network or permission error)").attr("class", "ghm-state err"); });
  }
  $(root).on("click", "#ghm-save", save);
  $(root).on("click", "[data-loytab]", function () { st.loyTab = this.getAttribute("data-loytab"); render(); });
  $(root).on("click", "#ghm-export", function () {
    var blob = new Blob([JSON.stringify(DATA, null, 1)], { type: "application/json" }), a = document.createElement("a");
    a.href = URL.createObjectURL(blob); a.download = "grill-hub-menu-backup.json"; document.body.appendChild(a); a.click(); a.remove();
  });
  $(root).on("change", "#ghm-import", function () {
    var f = this.files[0]; if (!f) return; var r = new FileReader();
    r.onload = function () { try { var d = JSON.parse(r.result); if (!d.items || !d.cats) throw 0; DATA = d; dirty(); render(); alert("Backup loaded. Press “Save changes” to apply it."); } catch (e) { alert("This is not a valid backup file."); } };
    r.readAsText(f);
  });
  $(root).on("click", "#ghm-reset", function () {
    if (!confirm("Reset the whole menu to the original version?")) return;
    $.post(CFG.ajax, { action: "ghm_reset", nonce: CFG.nonce }).done(function (r) { if (r && r.success) { DATA = r.data; st.dirty = false; st.cat = ""; render(); } });
  });
  window.addEventListener("beforeunload", function (e) { if (st.dirty) { e.preventDefault(); e.returnValue = ""; } });
  render();
})(jQuery);
