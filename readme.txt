=== Restaurant Menu & Ordering ===
Contributors: grillhub
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 1.19.0
License: GPLv2 or later

QR menu with WhatsApp ordering, kitchen orders, receipt printing, loyalty stamps, wrap builder and Polish / English / Arabic - for any restaurant.

== Installation ==
1. Plugins > Add New > Upload Plugin > choose grill-hub-menu.zip > Install > Activate.
2. A page called "Menu" is created automatically. Open it to see the menu. (Or put the shortcode [grill_hub_menu] on any page.)
3. Edit everything in the admin sidebar: Grill Hub.
4. Make a QR code that points to the Menu page address.

== Editing ==
Dishes: names, descriptions (3 languages), prices, options, photos, hide/show, order.
Categories: add, rename, reorder, delete.
Wrap builder: base prices, free portions, extra prices.
Texts: every button/label in every language. Ingredient names are the keys that start with ing_.
Settings: WhatsApp number, name, main colour, logo, default language, standalone page.
Backup: download / load a .json file, reset to the empty starter menu, or load the ready-made sample data (Backup > Sample data > Grill Hub) to see a complete menu.

== Orders and receipt printing (v1.1) ==
1. Grill Hub > Settings: turn on "Receive orders on the website", set a Kitchen PIN, choose paper width (58/80 mm) and the receipt options.
2. On the tablet next to the printer open the Kitchen page address shown in Settings, enter the PIN and keep it open.
3. Printing: install the free RawBT app (Android), pair the thermal printer in RawBT, choose it as the default printer. New orders print automatically; every order also has a Print button.
   Other printers: choose "Browser print" in Settings.
4. The price is calculated on the server from your price list.

== Motion (v1.3) ==
Smooth scrolling (Lenis), GSAP reveals and sheet animations, plus click sparks, animated totals and card spotlight inspired by React Bits (re-written in plain JavaScript). Turn it off in Settings > Animations. Third-party licenses are in assets/vendor/.

== Changelog 1.19.0 ==
* New: online payment with Stripe (Checkout: cards, Apple Pay, Google Pay, BLIK), PayU and Przelewy24. Menu > Online payment.
* One switch with three states: Off / Test mode (only a logged-in admin sees online payment, test / sandbox keys are used) / Go live.
* An online order is held as "pending" and reaches the kitchen, the statistics and the alerts only after the gateway confirms the payment (webhook, and a status check while the customer waits on the tracking page). The kitchen ticket shows "PAID ONLINE".
* Keys are stored in their own option, never in the menu data and never on the public page. The cart offers "pay at the restaurant" or the enabled online methods; the tracking page shows "waiting for payment" with a "Pay now" button.

== Changelog 1.18.0 ==
* The plugin is now a generic restaurant plugin. A new install starts with a small neutral starter menu (no brand, no address, no phone, no social / delivery links) that the owner fills in from the admin.
* New: Backup > Sample data > "Load: Grill Hub". One click fills the whole menu (48 dishes with photos, prices, texts, opening hours, home page and settings) exactly as the original Grill Hub menu. The data lives in presets/grill-hub.json; delete that file to remove the option.
* Reset now restores the empty starter menu (use the sample data button to get the Grill Hub menu back).
* The one-time Grill Hub migrations (rotisserie chicken dishes, map position, ...) are skipped for menus that start from the starter data or a preset.
* Brand names, logo and addresses that were fixed in the code (loyalty cards, wallet passes, notifications, alert e-mails, admin menu name) now come from the business name set in the plugin.

== Changelog 1.17.40 ==
* Fixed: the WhatsApp order message arrived without the order details. The order is created on the server first and the cart was emptied before the message was written, so it went out with no dishes and a 0 zł total. The message is now written while the cart still holds the items, then the cart is emptied — the order number and tracking link are still there.
* The kitchen distance is now measured from the phone's real position instead of the internet address: the address lookup often lands on the mobile network's hub city (tens of kilometres off — a test from inside the restaurant showed 69 km). When the customer opens the cart the site asks once to use the phone's location; if they say no, or the phone cannot provide it, the old address lookup is used as before.
* The order-tracking page now shows the order itself: every dish with its quantity and price, plus the total (discount and packaging rows only when they apply). The "order something else" button now takes the customer back to the menu with their name, phone, order type and pickup time already filled in, so the extra order arrives as a new order with the same details.

== Changelog 1.17.39 ==
* The rotisserie chicken is live now — no more "coming soon". It is split into two dishes in the "Kurczak z rożna" section: "Kurczak z rożna bez dodatków" (half 30 zł / whole 50 zł) and "Kurczak z rożna z dodatkami" (half 40 zł / whole 70 zł, with rice or fries, salad, tahini, pickles and toum). Live menus get the split automatically on update; the dish photo is the new whole-chicken photo.

== Changelog 1.17.38 ==
* The header order-timer bar now follows the customer's current language: it was stuck on the language the order was placed in (usually Polish), so switching language after ordering never changed it. The bar, the kitchen-update toasts and the "order ready" notification now use the language the customer is browsing in (falling back to the order's language, then Polish).

== Changelog 1.17.37 ==
* The WhatsApp order message now carries the order number and the order-tracking link: the order is created on the server first, then WhatsApp opens with "Numer zamówienia: #N" and the /track/ link (same link the tracking page reads). Works for both the WhatsApp button and the app button; when WhatsApp ordering is off the customer still goes to the /track/ page as before.

== Changelog 1.17.36 ==
* Grilled chicken photo re-plated as requested: the same meal now on a square white plate with hummus, garlic sauce (toum), Arabic salad and pickles next to the chicken and yellow rice.

== Changelog 1.17.35 ==
* Grilled chicken photo replaced with a new photo: the same meal (half chicken, yellow rice, garlic sauce, carrots, pickles) re-plated on a clean white plate with a white background, like the other dish photos — the other restaurant's logo from the original photo is gone.

== Changelog 1.17.34 ==
* Photo for the grilled chicken dish: a grilled chicken + rice plate on a white background (images/grill-chicken.jpg, 360×360 like the other dish photos). Menus that got the dish from 1.17.33 without a photo get it filled in automatically (only when the photo is empty — a photo you set yourself is never overwritten).

== Changelog 1.17.33 ==
* New "Grilled Chicken" dish (Kurczak z grilla) in its own section right before the grill section: half (40 zł) or whole (70 zł), each with rice or fries + salad, tahini, pickles and garlic sauce (toum), plus a plain option with no extras (half 30 zł, whole 50 zł). It shows "Coming soon" (Wkrótce / Coming soon / قريبًا) instead of the order button until you launch it — untick "Coming soon" on the dish in the admin and the order button appears. The dish is added to live menus automatically on update (one-time migration); fresh installs get it from the default data.

== Changelog 1.17.32 ==
* New "Deals" section at the top of the menu (Oferty / Deals / عروض): it lists only the dishes covered by the "N for X zł" bundle, taken straight from the saved menu data — change the offer in the admin and the section follows automatically. The deal badge is now gold (not red, so it stands out next to the red Add button) and sits at the far right of the Add button, with red/white fireworks.

== Changelog 1.17.31 ==
* The empty white bar at the top of the admin is gone: it only appears when there is something in it (the Save button after you change something, or the home-page link). The "Saved ✓" confirmation still shows for a moment after saving.

== Changelog 1.17.30 ==
* The bundle deal badge moved next to the Add button, and little fireworks now burst out of it every couple of seconds.

== Changelog 1.17.29 ==
* Every dish covered by the "N for X zł" bundle now shows a small red badge ("2 for 30 zł" / "2 za 30 zł" / "2 بـ 30 zł") with a wiggling 🎉, so customers can see at a glance which items count toward the deal.

== Changelog 1.17.28 ==
* Orders now store how far the customer was from the restaurant (dist_km, from the order IP — city-level accuracy). The kitchen app shows it on the order card and the printed receipt. Restaurant position set to 3 Maja 21, Katowice; on existing sites that never set coordinates it fills in automatically once.

== Changelog 1.17.27 ==
* Loyalty admin UI redesigned: modern 2025 design system — new stat cards with hover effects, polished panels, modern tables, pills, buttons, tabs, animations.

== Changelog 1.17.26 ==
* Code cleanup: professional docblocks added to all 156 loyalty functions + organized section dividers across 12 files. No logic changes.

== Changelog 1.17.25 ==
* FIX: registration on iPhone went to a 404 page. After signup the customer is now sent straight to Apple/Google Wallet in one redirect instead of the fragile register -> card page -> wallet chain.

== Changelog 1.17.24 ==
* Menu: variant option buttons no longer repeat the price (the main price display already shows the selected variant's price).

== Changelog 1.17.23 ==
* Menu data: Pepsi Zero now also has the 0,5 l (9 zł) size variant.

== Changelog 1.17.22 ==
* Menu data: merged Pepsi/Mirinda/7UP 0.33l+0.5l pairs into single products with 0,33 l / 0,5 l size variants.

== Changelog 1.17.21 ==
* Menu data: merged the two waters into one "Kryształ Zdrój 0,5 l" product with Gazowana/Niegazowana variants, and the two ayrans into one "Ajran" product with Naturalny/Mango variants.

== Changelog 1.17.20 ==
* New Grill Hub logo (red flame GH) across the plugin: loyalty card page, Apple/Google Wallet passes (pass images regenerate automatically on update).

== Changelog 1.17.19 ==
* Loyalty card page redesigned to the light Google-Wallet style: centered logo, big title, QR in a white card with gradient border, stamp count, and a clean stamp grid.

== Changelog 1.17.18 ==
* After saving a program with "notify customers" ticked, the success message now shows how many customers were notified.

== Changelog 1.17.17 ==
* Deal banner mix updated: joker replaced with Kebab Mix (joker is now excluded from the bundle deal).

== Changelog 1.17.16 ==
* FIX: "Edit program" (and "Edit store") didn't save — the save handler ran after an early return. Save is now handled before the page routing.
* Deal banner: the 4 suggested sandwiches are now a fixed mix (beef, chicken, classic, joker) instead of the most expensive items.

== Changelog 1.17.15 ==
* Bundle deal deep-link: tapping the "N sandwiches for X zł" offer on the home page now opens the menu with ?deal=bundle and shows a deal banner suggesting the N best-value sandwiches with a one-tap "Add to cart" button.

== Changelog 1.17.14 ==
* Admin cleanup: removed the "Grill Hub Menu" page title, the sidebar brand header, and the "Preparation time" card from Offers (the app handles order timing now).

== Changelog 1.17.13 ==
* FIX: "Add to Apple Wallet" showed a 404 — the plugin now flushes WordPress rewrite rules automatically on every update (file replacement doesn't trigger activation), so /wp-json/ routes keep working. The custom 404 page also no longer interferes with REST API requests.

== Changelog 1.17.12 ==
* FIX: card URLs could point to a deleted page (stale cache) — the page lookup now verifies the page still exists before building the URL.

== Changelog 1.17.11 ==
* NEW REST endpoint for the staff app: POST /wp-json/loyalty/v1/staff/find (staff_pin + q = phone or QR token → customer + active cards + programs).

== Changelog 1.17.10 ==
* FIX: loyalty sub-pages showed "Sorry, you are not allowed to access this page" — the loyalty menu is now registered AFTER the Grill Hub parent menu (correct hook order), so WordPress always sees the parent first.
* Loyalty tabs moved to the very top of the Loyalty section (above the stats), with the page content below.

== Changelog 1.17.9 ==
* Admin top bar: "Save changes" button and "Unsaved changes" now appear ONLY when you actually edit something; "Open menu page" link removed.
* Party popup: the confetti popup option is now available on every offer section (wrap+drink, N sandwiches, gift, and per coupon) — one popup shows all active offers, once per 12h per device.
* Statistics: dish and button names are now shown in English (was Polish).
* Settings: removed the "Orders and kitchen printing" section (your app handles it).
* Loyalty admin: sub-pages now open inside the Grill Hub page (no more navigation away) — sub-menu tabs on top, page content below in an embedded view.
* FIX: "Grill Hub" admin menu was hijacked by the Loyalty submenu (now fixed — Grill Hub opens the control deck).

== Changelog 1.17.8 ==
* FIX: the "Grill Hub" admin menu was hijacked by the Loyalty submenu (WordPress replaces a top-level menu's link with its first submenu when they differ). The Grill Hub control deck is now the first submenu item, so "Grill Hub" opens the control deck again and Loyalty sits properly underneath it.
* Admin: Menu group moved to the top of the sidebar.
* Loyalty merged into the Grill Hub menu (no more separate "Loyalty" menu) + sub-navigation across loyalty sections.
* Wallet: new "Pass branding" section — choose the logo shown on the customer's Apple/Google Wallet card (rebuilds pass images + refreshes the Google class automatically).
* Programs: new "Reward type" — "Any sandwich of the customer's choice" (PL/EN/AR) or a custom title. Shown on the card, in the wallet pass, in notifications and on the staff redeem screen.

== Changelog 1.17.6 ==
* Admin panel: light theme (easy on the eyes), grouped sidebar navigation, new Dashboard with live stats, quick actions and staff PIN. Loyalty admin restyled to match.

== Changelog 1.17.5 ==
* Admin panel redesigned ("2050" control deck): dark futuristic theme, grouped sidebar navigation, new Dashboard with live stats, quick actions and staff PIN.

== Changelog 1.17.4 ==
* Staff PIN can now be changed to a custom PIN (4-8 digits), in Loyalty > Settings and in Grill Hub > Loyalty.
* Footer links are editable in Grill Hub > Settings > Footer links: change labels (PL/EN/AR), hide/show, reorder, add custom links. New "Staff" link in the footer.
* The 404 page now uses the home page style (dark, flame logo, header + footer).

== Changelog 1.17.3 ==
* Footer redesigned: readable on dark pages (home, dark loyalty theme) and light pages, flame logo, pill links.

== Changelog 1.17.2 ==
* "N for X zł" offer defaults to all wraps (Wrapy + Wrapy z Grilla); the custom wrap builder is not part of it.

== Changelog 1.17.1 ==
* "N for X zł" offer: tick single dishes to always include (e.g. Joker) or never include in the offer, on top of categories.

== Changelog 1.17.0 ==
* New offer "N sandwiches for X zł" (Menu → Offers): fixed price per group, time window, shown in the cart and as a badge on the home page. Never stacks with wrap + drink: the bigger discount wins.
* Loyalty card page: no "card not found" screen and no tabs; opening it goes straight to Google Wallet (Android) / Apple Wallet (iPhone) when configured. Unknown/missing card goes to the join page.
* One footer on every page (home, menu, track, loyalty): translated when the language changes, uses the page's own theme.

== Changelog 1.16.2 ==
* Wallet passes redesigned like a modern stamp card: light background, big stamp-grid picture (updates with every stamp), "Stamp received!" message inside Google Wallet, Apple Wallet strip image.

== Changelog 1.16.1 ==
* Loyalty card page: removed the tab bar (My card / Rewards / Notifications / Settings); cards and active reward codes on one page.
* Opening the card page now automatically jumps to Apple Wallet (iPhone) / Google Wallet (Android) once per session (add ?nw=1 to skip).
* New shared footer with links to all pages (home, menu, track order, loyalty, my card) on loyalty and track pages.

== Changelog 1.16.1 ==
* Footer with links to all pages (home, menu, track, loyalty) now also on the menu and home pages.
* Loyalty card: redirects to Apple/Google Wallet every time the card page opens (not only once per session).
* Loyalty card page: no tab bar.

== Changelog 1.16.0 ==
* Unified site header (logo, menu, home, language) on track, loyalty and all new pages.
* Loyalty: interactive 2026-style cards (flip to QR, live stamp updates, confetti), auto-redirect to Apple Wallet (iPhone) / Google Wallet (Android) when configured.
* Loyalty: minimal join form (name + phone) with instant card.
* Modern HTML customer email with QR code, stamp count and stamps remaining.

== Changelog 1.15.0 ==
* NEW SECTION "Loyalty" (the stamp-card plugin is now built in, with everything): programs with any number of stamps, customer card page + QR, staff page with PIN (search / scan QR / add stamp / redeem code), rewards with codes and expiry, statistics + CSV, notifications (inbox, e-mail, WhatsApp webhook, Web Push), nearby offers (1 km geofence), referral and birthday gift, Apple / Google Wallet, GDPR delete, REST API.
* Tab "Loyalty" in the Grill Hub settings: create the 3 pages with one click ([loyalty_register], [loyalty_card], [loyalty_staff]), staff PIN, statistics and shortcuts. The full admin is in the "Loyalty" menu on the left.
* All loyalty pages use the same header, fonts and PL / EN / AR switch as the menu (language is shared with the menu and home page; Polish by default). Design: light like the menu (default) or dark like the home page - Loyalty > Settings > Page design.
* The "Rewards" button on the home page and the waiting screen opens the built-in Loyalty sign-up page (when it exists and no other address was typed in).
* One service worker + one push key for order notifications and loyalty notifications.
* If the separate "Loyalty Stamps" plugin is still active, a notice asks to deactivate it (its data is kept - same tables).

== Changelog 1.14.2 ==
* The tracking page now looks like the menu (light background, red accents, white cards, same header).
* Track from another device: order number + last 4 digits of the phone.
* New shortcode [track] (or [grill_hub_track]): put it on any page and that page becomes the tracking page; the countdown bar links to it automatically. Without such a page the built-in /track/ address is used.

== Changelog 1.14.1 ==
* The order button now says "Confirm order" (Potwierdź zamówienie / تأكيد الطلب).

== Changelog 1.14.0 ==
* The waiting screen is now its own page: /track/ (big countdown, steps, offers, "ready" alarm, shareable link). It remembers the order, so closing and reopening the site still shows it.
* A bar at the very top of every page (menu, home, track) counts down until the order is ready: "Ready in ~25 min (about 21:45) - Tap here to track your order" (PL / EN / AR). It turns green and rings when ready.
* Kitchen: new endpoint POST /orders/{id}/accept; the customer sees "Accepted" and a "Kitchen update: ready in ~N min" toast when the time changes. Setting the time or pressing READY also counts as accepting.
* Push notifications (accepted / ready) even when the page is closed (Web Push, own PHP code, needs HTTPS + openssl). iPhone: only when the site is added to the Home Screen.
* The table-number field is removed: name and phone are required for dine-in orders too (so you can call if it is late).
* Ready text for dine-in: "Please come to collect your order" (PL / EN / AR).
* New admin tab "Customers": name, phone, approximate city from the IP at order time (IP stored masked), orders, spent. Download CSV, copy and open in Google Sheets, or a live Google Sheets link (IMPORTDATA). The list is kept after orders are deleted; delete one or all.

== Changelog 1.13.1 ==
* Dishes with options written "Meat · Size" show two short rows of buttons (beef / chicken / mix and small / large) instead of six. Klasyczny preselects chicken + large. New "Preselected option" per dish in the admin.

== Changelog 1.13.0 ==
* "Kebab Klasyczny": chicken / beef / mix (x size). "Joker": Mix option added (existing saved dishes are upgraded once if they still have the original options).
* Waiting screen: full-screen countdown (mm:ss) with progress ring, steps Received/Preparing/Ready, marketing cards (coupon for next order, follow us, Google review, stamps), "notify me" browser notification, screen kept awake; when the kitchen taps Gotowe: green screen, repeating sound + vibration until the customer taps.

== Changelog 1.12.0 ==
* Arabic wording: "كباب" is now "شاورما" and "جريل" is now "مشاوي" everywhere in Arabic (default texts and, once, the saved Arabic texts), with the following words adjusted to the new gender (e.g. الشاورما المقطّعة, مشاوي طازجة).

== Changelog 1.11.0 ==
* Party popup: confetti burst and "Congratulations, order for X zl and get a free dish" when a visitor opens the home or menu page (from the free-dish offer settings; once per 12 h per browser; off with the switch; ?nopopup / ?party in the URL).

== Changelog 1.10.2 ==
* Menu header on phones: "GRILL HUB" stays next to the logo (smaller); the search is a magnifier icon, and the name is hidden only while the search is open.

== Changelog 1.10.1 ==
* The logo in the menu header leads to the home page and a house button (switchable) gives an easy way back. The free-dish offer is shown on the home page like the combo badge.

== Changelog 1.10.0 ==
* Offer "spend X, get a dish free" (e.g. 50 zl -> baklava): up to 4 levels, optional time window; the cart shows what is missing and adds the free dish automatically (server-checked, printed as GRATIS).

== Changelog 1.9.0 ==
* Bill: drink deposit (default 0.50 zl per drink) and takeaway packaging (default 2 zl per pick-up order), both editable in Offers & alerts; shown in the cart, WhatsApp message, kitchen card and receipt.

== Changelog 1.8.2 ==
* Browser tab icon (favicon), iPhone home-screen icon and Android icon: the GH flame logo.

== Changelog 1.8.1 ==
* Rewards and Google review buttons open in the same tab (fixes iPhone, where the new-tab opening of these redirect links failed).

== Changelog 1.8.0 ==
* Order sources: statistics show visits, orders and order value per source (QR tables, Google, Instagram, ...). Tracking links with ?src= and ?t=<table>.
* Offers: "wrap + drink" combo (per-pair discount, optional time window / weekdays, gold badge on the home page) and coupon codes (percent or amount, minimum order, max uses, time window, ?code=XXX links). All discounts are calculated on the server.
* Preparation time: customers see "ready in about N minutes", a status bar on the menu turns green with a sound when the kitchen taps Gotowe. Kitchen can change the promise per order (5/10/15/20/30 min).
* Kitchen page: repeating alarm until acknowledged, offline-connection banner, source tag, discounts on card and receipt.
* Google reviews on the home page: manual rating + reviews, or automatic via Google Places (Place ID + API key, cached 12 h).
* Alerts when the kitchen page is not open: WhatsApp text (CallMeBot), ntfy phone push, e-mail. Test button in Offers & alerts.
* Settings are stored in the database and survive updates.

== Changelog 1.7.4 ==
* The sitemap is now at /sitemap.xml (the old /grill-hub-sitemap.xml still works).

== Changelog 1.7.3 ==
* Fix: the sitemap (/grill-hub-sitemap.xml) and the English page (/en/) now answer with HTTP 200 and are not redirected by WordPress (Search Console reported "Couldn't fetch").

== Changelog 1.7.2 ==
* Social and delivery buttons can use your own logo image (Home page > Social media and delivery links > Choose image).

== Changelog 1.7.1 ==
* Home page: Facebook, Instagram and TikTok icons in the footer, and delivery buttons (Wolt, Uber Eats, Pyszne.pl, Bolt Food, Glovo), each with its own link. Edit links in Home page > "Social media and delivery links". Taps are counted in Statistics; the social and delivery links are also sent to Google (sameAs).

== Changelog 1.7.0 ==
* Wrap builder: new "Mix" (chicken + beef, default 36 / 24 zl - edit in the Wrap builder tab) and a new "Extras" tab with Fries (7 zl) and Cheese (5 zl), prices editable.
* SEO for Katowice: English home page at /en/ (own title, description, hreflang pl/en), keyword titles ("Kebab i Grill Katowice" / "Kebab & Grill in Katowice"), visible About text, structured Menu data (all dishes and prices) on the menu page, crawlable dish list, sitemap at /grill-hub-sitemap.xml (also added to robots.txt), optional map coordinates.
* Google ranking also depends on things outside the website - see "Ranking checklist" below.

== Ranking checklist (outside the plugin) ==
1. Google Business Profile: claim and verify it. Main category "Kebab shop" (or Restaurant), extra: Grill, Middle Eastern restaurant. Same name, address and phone as on the website. Same opening hours (incl. Fri/Sat until 02:00). Add the website link, menu link, 20+ real photos, "Order online" link.
2. Reviews: ask every happy customer for a Google review (the "Rate us" button does this); reply to every review.
3. Google Search Console: add grillhub.pl, submit https://grillhub.pl/sitemap.xml, check Coverage after a few days.
4. Fill Map coordinates and Postal code in "Hours, address & SEO".
5. Get listed on local sites (Katowice directories, Pyszne/Wolt/Uber Eats profiles, Facebook, Instagram) linking to grillhub.pl.

== Changelog 1.6.0 ==
* Wrap builder: the meat now fills a much wider area of the flatbread (more pieces, bigger area) so the wrap looks full.
* Home page: two new buttons (4th and 5th): "Call us" (tel: link) and "Find us on the map" (opens Google Maps directly with the address). Existing installs get them added once automatically; they can be edited, hidden, reordered or deleted like any other button. Link shortcuts: {menu}, {tel}, {map}.
* New admin tab "Hours, address & SEO": address, phone, opening hours per day (Warsaw time; default 10:00-24:00 daily, Fri and Sat until 02:00), what happens when closed, Google description / title / sharing image.
* SEO for the home page (site root): meta description, Open Graph + Twitter card (default image assets/images/og-default.jpg, replaceable), favicon, and structured "Restaurant" data (address, phone, opening hours, menu, map) for Google Search and Google Maps. Note: Google Maps itself is fed by your Google Business Profile - keep it in sync with the same hours.
* Opening hours: "Open now / Closed" badge on the home page and a notice in the menu. When closed, ordering is blocked (or only warned, your choice); website orders are also refused by the server. Pick-up time is checked against the opening hours.
* Cart suggestions: before sending, the cart suggests fries and a drink (editable in the new tab).
* Statistics (admin tab "Statistics"): home page visits, taps on each home button, menu visits, orders and the most ordered dishes. Anonymous - no cookies, no personal data.

== Changelog 1.5.4 ==
* Polish is the default language on the home page and the menu (visitors can switch PL / EN / AR; the default can be changed in Settings).
* Wrap builder: flatbread enlarged to fill the whole plate circle.

== Changelog 1.5.3 ==
* Home page (3 buttons) now opens at the site root (grillhub.pl) automatically; switch in Grill Hub > Home page > "Use it as the website front page".
* Wrap builder: new blistered golden flatbread photo, centred on the plate (assets/ing/saj.webp).

== Changelog 1.5.2 ==
* Home: removed the repeated logo background; animated flames and rising sparks restored. Menu address setting (default https://grillhub.pl/order/).

== Changelog 1.5.1 ==
* Brand identity from the logo: signal red #E11E26, black and white, bold condensed capitals (Anton), flat flame shapes. Applied to the home page and the menu.

== Changelog 1.5.0 ==
* Wrap builder: real photographic ingredients (cut-outs in assets/ing/*.webp, replaceable) with a drop-in / fade-out animation.
* Added Rice / Fries options to "Kebab Kurczak" (Sliced Kebab Plates); existing sites are migrated automatically.
* Fixed jittering numbers: cards no longer re-render on each change, fixed-width controls and totals.
* Removed references to missing script files in the shortcode / standalone output.

== Changelog 1.5.2 ==
* Home: removed the repeated logo background; animated flames and rising sparks restored. Menu address setting (default https://grillhub.pl/order/).

== Changelog 1.5.1 ==
* Brand identity from the logo: signal red #E11E26, black and white, bold condensed capitals (Anton), flat flame shapes. Applied to the home page and the menu.

== Changelog 1.5.0 ==
* New home page shortcode [grill_hub_home]: three big fire-style buttons (Order now / Get Rewards / Rate us), PL / EN / AR.
* New admin tab "Home page": turn the page or any button on/off temporarily, edit texts in 3 languages, links, icons, look, order, temporary notice, footer.
