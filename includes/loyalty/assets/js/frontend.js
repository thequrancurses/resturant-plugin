/* Loyalty Stamps — frontend */
(function () {
	'use strict';

	var D = window.LS_DATA || {};
	var restBase = (D.restUrl || '/wp-json/loyalty/v1/').replace(/\/$/, '/');

	function rest(path, opts) {
		opts = opts || {};
		var headers = { 'Content-Type': 'application/json' };
		if (D.nonce) headers['X-WP-Nonce'] = D.nonce;
		return fetch(restBase + path.replace(/^\//, ''), {
			method: opts.method || 'GET',
			headers: headers,
			body: opts.body ? JSON.stringify(opts.body) : undefined
		}).then(function (r) {
			return r.json().then(function (j) {
				if (!r.ok) throw new Error((j && j.message) || ('HTTP ' + r.status));
				return j;
			});
		});
	}

	function esc(s) {
		return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	/* Translated string helper (strings come from PHP via LS_DATA.i18n). */
	function T(key) {
		return (D.i18n && D.i18n[key]) || key;
	}

	/* ---------- QR codes ----------
	 * Rendered via a QR image provider configured in PHP (LS_DATA.qrUrl,
	 * filterable via 'ls_qr_image_url'). The QR only encodes the public
	 * card URL — the same link the customer already holds.
	 */
	function qrImageUrl(data) {
		var tpl = D.qrUrl || 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=10&data={data}';
		return tpl.split('{data}').join(encodeURIComponent(data));
	}

	function renderQRCodes() {
		document.querySelectorAll('.ls-qr[data-qr]').forEach(function (el) {
			if (el.getAttribute('data-done')) return;
			el.setAttribute('data-done', '1');
			var img = document.createElement('img');
			img.alt = 'QR';
			img.loading = 'lazy';
			img.src = qrImageUrl(el.getAttribute('data-qr'));
			el.appendChild(img);
		});
	}

	/* ---------- Card page ---------- */
	var cardApp = document.getElementById('ls-card-app');
	var token = cardApp ? cardApp.getAttribute('data-token') : '';

	function initTabs() {
		document.querySelectorAll('#ls-card-app .ls-tab').forEach(function (btn) {
			btn.addEventListener('click', function () {
				document.querySelectorAll('#ls-card-app .ls-tab').forEach(function (b) { b.classList.remove('active'); });
				document.querySelectorAll('#ls-card-app .ls-tabpane').forEach(function (p) { p.classList.remove('active'); });
				btn.classList.add('active');
				document.getElementById('ls-tab-' + btn.getAttribute('data-tab')).classList.add('active');
			});
		});
	}

	function stampDots(stamps, required, color) {
		var max = Math.min(required, 12), html = '<div class="ls-stamps-row">';
		for (var i = 0; i < max; i++) {
			html += '<span class="ls-stamp' + (i < stamps ? ' on' : '') + '">' + (i < stamps ? '★' : (i + 1)) + '</span>';
		}
		html += '</div>';
		return html;
	}


	/* ---------- own small dictionary for the 2026 card UI (pl / en / ar) ---------- */
	var UI = {
		pl: { left: 'Jeszcze {n} do nagrody', done: 'Nagroda czeka!', qr: 'Pokaż kod', back: 'Wróć', scan: 'Pokaż ten kod obsłudze, aby dostać pieczątkę', stamps: 'pieczątek', prep: 'Przygotowujemy Twoją kartę Wallet…', prep2: 'Za chwilę dodasz ją do portfela', skip: 'Pokaż kartę w przeglądarce', newStamp: 'Nowa pieczątka! 🎉', reward: 'Gratulacje! Nagroda: {r}', hello: 'Cześć', expires: 'Ważna do' },
		en: { left: '{n} more to your reward', done: 'Your reward is waiting!', qr: 'Show code', back: 'Back', scan: 'Show this code to staff to collect a stamp', stamps: 'stamps', prep: 'Preparing your Wallet card…', prep2: 'You will add it to your wallet in a moment', skip: 'Show the card in the browser', newStamp: 'New stamp! 🎉', reward: 'Congratulations! Reward: {r}', hello: 'Hi', expires: 'Valid until' },
		ar: { left: 'باقي {n} للمكافأة', done: 'مكافأتك في انتظارك!', qr: 'اعرض الكود', back: 'رجوع', scan: 'اعرض هذا الكود للموظف ليختم كارتك', stamps: 'ختم', prep: 'جاري تجهيز كارت المحفظة…', prep2: 'هتضيفه لمحفظتك حالاً', skip: 'اعرض الكارت في المتصفح', newStamp: 'ختم جديد! 🎉', reward: 'مبروك! مكافأتك: {r}', hello: 'أهلاً', expires: 'صالح حتى' }
	};
	function U(k, v) {
		var l = (document.documentElement.lang || 'pl').slice(0, 2), t = (UI[l] || UI.pl)[k] || UI.pl[k] || k;
		if (v) Object.keys(v).forEach(function (x) { t = t.replace('{' + x + '}', v[x]); });
		return t;
	}
	var IS_IOS = /iPad|iPhone|iPod/.test(navigator.userAgent || '') || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
	var IS_ANDROID = /Android/.test(navigator.userAgent || '');
	var FLAME = '<svg viewBox="0 0 24 28" aria-hidden="true"><path fill="currentColor" d="M12 1c.6 4.200-4.200 6.600-4.200 12.200a4.200 4.200 0 0 0 8.400 0c0-2.200-1-3.400-2-4.800-.4 1.600-1.200 2.800-2.200 3.200C12.600 8.600 13.200 4.400 12 1zM7 20.500a5 5 0 0 0 10 0c0 3-2 5.500-5 5.500s-5-2.500-5-5.500z"/></svg>';
	var passState = { open: {}, last: {}, started: false };

	function passHTML(c, isNew) {
		var req = Math.max(1, c.stamps_required), n = Math.min(req, 20), left = Math.max(0, req - c.stamps), full = left === 0;
		var grid = '';
		for (var i = 0; i < n; i++) {
			var on = i < c.stamps, nw = on && isNew && i >= (passState.last[c.id] || 0);
			grid += '<span class="ls-pl-st' + (on ? ' on' : '') + (nw ? ' new' : '') + '">' + (on ? '<i>🔥</i>' : '<em>' + (i + 1) + '</em>') + '</span>';
		}
		var url = location.origin + location.pathname + '?card=' + token;
		var logo = (D && D.logo) || '';
		return '<div class="ls-pl" data-id="' + c.id + '">'
			+ '<div class="ls-pl-logo"><img src="' + esc(logo) + '" alt="' + esc((D && D.brand) || '') + '"></div>'
			+ '<div class="ls-pl-brand">' + esc((D && D.brand) || '') + '</div>'
			+ '<h2 class="ls-pl-title">' + esc(c.program_name) + '</h2>'
			+ '<div class="ls-pl-qrwrap"><div class="ls-qr ls-qr-big" data-qr="' + esc(url) + '"></div></div>'
			+ '<p class="ls-pl-scan">' + esc(U('scan')) + '</p>'
			+ '<div class="ls-pl-count"><span>' + esc(U('stamps')) + '</span><b>' + c.stamps + ' / ' + req + '</b></div>'
			+ (full && c.reward_title ? '<div class="ls-pl-reward">🎁 ' + esc(c.reward_title) + '</div>' : (!full ? '<div class="ls-pl-left">' + esc(U('left', { n: left })) + '</div>' : ''))
			+ '<div class="ls-pl-grid">' + grid + '</div>'
			+ (c.expires_at ? '<div class="ls-pl-exp">' + esc(U('expires')) + ' ' + esc(String(c.expires_at).slice(0, 10)) + '</div>' : '')
			+ '</div>';
	}

	function confetti(host) {
		try {
			var cv = document.createElement('canvas'); cv.className = 'ls-confetti'; host.appendChild(cv);
			var r = host.getBoundingClientRect(), W = cv.width = r.width, H = cv.height = r.height, x = cv.getContext('2d'), P = [], cols = ['#ffd23f', '#ffffff', '#ff5a36', '#22c55e', '#38bdf8'], t0 = Date.now();
			for (var i = 0; i < 90; i++) P.push({ x: W / 2, y: H * 0.45, vx: (Math.random() - 0.5) * 9, vy: -Math.random() * 9 - 2, s: 4 + Math.random() * 5, c: cols[i % 5], r: Math.random() * 6 });
			(function f() {
				x.clearRect(0, 0, W, H); var live = false;
				P.forEach(function (p) { p.vy += 0.28; p.x += p.vx; p.y += p.vy; p.r += 0.2; if (p.y < H + 20) live = true; x.save(); x.translate(p.x, p.y); x.rotate(p.r); x.fillStyle = p.c; x.fillRect(-p.s / 2, -p.s / 3, p.s, p.s * 0.6); x.restore(); });
				if (live && Date.now() - t0 < 3500) requestAnimationFrame(f); else cv.remove();
			})();
		} catch (e) {}
	}

	function renderPasses(data, isPoll) {
		var host = document.getElementById('ls-tab-cards'), cards = data.cards || [], html = '<h3 class="ls-title ls-hello">' + esc(U('hello')) + (data.name ? ', ' + esc(data.name) : '') + '</h3>';
		if (!cards.length) html += '<p>' + esc(T('noCards')) + '</p>';
		var grew = null;
		cards.forEach(function (c) { if (isPoll && passState.last[c.id] != null && c.stamps > passState.last[c.id]) grew = c; });
		cards.forEach(function (c) { html += passHTML(c, isPoll); });
		html += renderWalletButtons(data);
		host.innerHTML = html;
		cards.forEach(function (c) { passState.last[c.id] = c.stamps; });
		initWalletButtons(); initPassFx(host); renderQRCodes();
		if (grew) {
			try { if (navigator.vibrate) navigator.vibrate([60, 40, 120]); } catch (e) {}
			var el = host.querySelector('.ls-pass[data-id="' + grew.id + '"]');
			if (el) { toastMsg(grew.stamps >= grew.stamps_required ? U('reward', { r: grew.reward_title || '' }) : U('newStamp')); if (grew.stamps >= grew.stamps_required) confetti(el.parentNode); }
		}
	}

	function toastMsg(t) {
		var e = document.createElement('div'); e.className = 'ls-toast'; e.textContent = t; document.body.appendChild(e); setTimeout(function () { e.remove(); }, 3800);
	}

	function initPassFx(host) {
		host.querySelectorAll('[data-flip]').forEach(function (b) {
			b.addEventListener('click', function () {
				var id = b.getAttribute('data-flip'), el = host.querySelector('.ls-pass[data-id="' + id + '"]');
				passState.open[id] = !passState.open[id]; el.classList.toggle('flip', !!passState.open[id]);
			});
		});
		host.querySelectorAll('.ls-pass').forEach(function (el) {
			if (!window.matchMedia || window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
			function mv(e) {
				var r = el.getBoundingClientRect(), p = e.touches ? e.touches[0] : e, px = (p.clientX - r.left) / r.width - 0.5, py = (p.clientY - r.top) / r.height - 0.5;
				el.style.setProperty('--rx', (-py * 10).toFixed(2) + 'deg'); el.style.setProperty('--ry', (px * 12).toFixed(2) + 'deg');
				el.style.setProperty('--gx', ((px + 0.5) * 100).toFixed(0) + '%'); el.style.setProperty('--gy', ((py + 0.5) * 100).toFixed(0) + '%');
			}
			function rs() { el.style.setProperty('--rx', '0deg'); el.style.setProperty('--ry', '0deg'); }
			el.addEventListener('pointermove', mv); el.addEventListener('pointerleave', rs); el.addEventListener('pointerup', rs);
		});
	}

	/* ---------- right after joining: go straight to the phone wallet (Apple on iPhone, Google on Android) ---------- */
	function handleAutoWallet(data) {
		var q = new URLSearchParams(location.search);
		if (q.get('nw') === '1' || q.get('w') !== '1') return; // the server already redirects on a plain card visit
		// every time the card page opens, but not twice within 90 s, so "back" from the wallet does not loop
		try { var lw = +sessionStorage.getItem('ls-w-' + token) || 0; if (Date.now() - lw < 90000) return; sessionStorage.setItem('ls-w-' + token, String(Date.now())); } catch (e) {}
		try { history.replaceState(null, '', location.pathname + '?card=' + encodeURIComponent(token)); } catch (e) {}
		var w = data.wallet || {}, go = null;
		if (IS_IOS && w.apple) go = function () { location.href = restBase + 'wallet/apple?token=' + encodeURIComponent(token); };
		else if (IS_ANDROID && w.google) go = function () { return rest('wallet/google?token=' + encodeURIComponent(token)).then(function (d) { location.href = d.url; }); };
		if (!go) return;
		var ov = document.createElement('div'); ov.className = 'ls-prep';
		ov.innerHTML = '<div class="ls-prep-in"><div class="ls-spin"></div><h2>' + esc(U('prep')) + '</h2><p>' + esc(U('prep2')) + '</p><button type="button" class="ls-prep-skip">' + esc(U('skip')) + '</button></div>';
		document.body.appendChild(ov);
		var close = function () { ov.remove(); };
		ov.querySelector('.ls-prep-skip').addEventListener('click', close);
		setTimeout(function () { try { var r = go(); if (r && r.catch) r.catch(close); } catch (e) { close(); } }, 1200);
		setTimeout(close, 9000);
	}

	/* live updates: when staff adds a stamp the card changes on the customer's phone (animation + vibration) */
	function startPassPoll() {
		if (passState.started || !token) return; passState.started = true;
		setInterval(function () {
			if (document.hidden) return;
			rest('card?token=' + encodeURIComponent(token)).then(function (d) {
				var changed = (d.cards || []).some(function (c) { return passState.last[c.id] != null && c.stamps !== passState.last[c.id]; });
				if (changed) renderPasses(d, true);
			}).catch(function () {});
		}, 7000);
	}

	function loadCard() {
		if (!token) return;
		rest('card?token=' + encodeURIComponent(token)).then(function (data) {
			var i, html;

			// Cards tab: interactive wallet-style cards (tilt, flip to QR, live stamp animation).
			renderPasses(data);
			handleAutoWallet(data);

			// Rewards tab.
			html = '';
			var activeRewards = 0;
			(data.rewards || []).forEach(function (r) {
				var used = r.status !== 'issued';
				if (!used) activeRewards++;
				html += '<div class="ls-reward ls-reveal' + (used ? ' used' : '') + '">'
					+ '<strong>' + esc(r.title) + '</strong><br><code>' + esc(r.code) + '</code><br>'
					+ '<span class="ls-expiry">' + esc(r.status) + (r.expires_at ? ' — expires ' + esc(r.expires_at) : '') + '</span></div>';
			});
			document.getElementById('ls-tab-rewards').innerHTML = html;
			var rc = document.getElementById('ls-rewards-count'); if (rc) rc.textContent = activeRewards || '';

			// Inbox tab.
			html = '';
			(data.inbox || []).forEach(function (m) {
				html += '<div class="ls-msg ls-reveal"><strong>' + esc(m.title) + '</strong>' + esc(m.message) + '<time>' + esc(m.created_at) + '</time></div>';
			});
			if (!html) html = '<p>' + esc(T('noInbox')) + '</p>';
			document.getElementById('ls-tab-inbox').innerHTML = html;
			var ic = document.getElementById('ls-inbox-count'); if (ic) ic.textContent = data.unread || '';

			// Settings tab.
			var p = data.prefs || {};
			html = '<div class="ls-setting-row"><div>' + esc(T('notifEmail')) + '<small>' + esc(T('notifEmailSub')) + '</small></div>'
				+ '<input type="checkbox" id="ls-p-email" ' + (p.notify_email ? 'checked' : '') + '></div>'
				+ '<div class="ls-setting-row"><div>' + esc(T('pushNotif')) + '<small>' + esc(T('pushNotifSub')) + '</small></div>'
				+ '<input type="checkbox" id="ls-p-push" ' + (p.notify_push ? 'checked' : '') + '></div>'
				+ '<div class="ls-setting-row"><div>' + esc(T('waNotif')) + '</div>'
				+ '<input type="checkbox" id="ls-p-wa" ' + (p.notify_whatsapp ? 'checked' : '') + '></div>'
				+ '<div class="ls-setting-row"><div>' + esc(T('nearbyOffers')) + '<small>' + esc(T('nearbyOffersSub')) + '</small></div>'
				+ '<input type="checkbox" id="ls-p-loc" ' + (p.location_optout ? '' : 'checked') + '></div>'
				+ '<button class="ls-btn" id="ls-p-save" style="margin-top:12px">' + esc(T('save')) + '</button> <span id="ls-p-msg"></span>'
				+ '<div class="ls-setting-row" style="margin-top:16px"><div>' + esc(T('inviteFriends')) + '<small>' + esc(T('inviteFriendsSub')) + '</small></div></div>'
				+ '<p><input type="text" readonly class="ls-link-input" value="' + esc(location.origin + location.pathname.replace(/[^/]*$/, '') + '?ref=' + (data.referral_code || '')) + '" onclick="this.select()"></p>'
				+ '<button class="ls-danger" id="ls-delete-data">Delete my data</button>';
			document.getElementById('ls-tab-settings').innerHTML = html;

			document.getElementById('ls-p-save').addEventListener('click', function () {
				var btn = this;
				btn.disabled = true;
				rest('prefs', {
					method: 'POST',
					body: {
						token: token,
						notify_email: document.getElementById('ls-p-email').checked ? 1 : 0,
						notify_push: document.getElementById('ls-p-push').checked ? 1 : 0,
						notify_whatsapp: document.getElementById('ls-p-wa').checked ? 1 : 0,
						location_optout: document.getElementById('ls-p-loc').checked ? 0 : 1
					}
				}).then(function () {
					document.getElementById('ls-p-msg').textContent = '✓';
					if (document.getElementById('ls-p-push').checked) enablePush();
					if (document.getElementById('ls-p-loc').checked) startLocation();
				}).catch(function (e) {
					document.getElementById('ls-p-msg').textContent = e.message;
				}).finally(function () { btn.disabled = false; });
			});
			document.getElementById('ls-delete-data').addEventListener('click', function () {
				if (!confirm(T('confirmDelete'))) return;
				fetch(restBase + 'customer?token=' + encodeURIComponent(token), {
					method: 'DELETE',
					headers: { 'X-WP-Nonce': D.nonce || '' }
				}).then(function () { location.href = '/'; });
			});

			renderQRCodes();
			refreshReveal();
			startPassPoll();
			if (p.notify_push) enablePush();
			if (!p.location_optout) startLocation();
		}).catch(function (e) {
			document.getElementById('ls-tab-cards').innerHTML = '<div class="ls-error">' + esc(e.message) + '</div>';
		});
	}

	/* ---------- phone wallets (Apple / Google) ---------- */
	function renderWalletButtons(data) {
		var w = data.wallet || {};
		if (!w.apple && !w.google) return '';
		var ua = navigator.userAgent || '';
		var isIOS = /iPad|iPhone|iPod/.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
		var isAndroid = /Android/.test(ua);
		var html = '<div class="ls-wallet ls-reveal in">'
			+ '<div class="ls-wallet-title">' + esc(T('walletTitle')) + '</div>'
			+ '<div class="ls-wallet-sub">' + esc(T('walletSub')) + '</div>'
			+ '<div class="ls-wallet-row">';
		// Show the device-relevant button first.
		var btns = [];
		if (w.apple) {
			btns.push({ k: 'apple', ios: true, html: '<a class="ls-wallet-btn ls-w-apple" href="' + esc(restBase + 'wallet/apple?token=' + encodeURIComponent(token)) + '">'
				+ esc(T('addApple')) + '</a>' });
		}
		if (w.google) {
			btns.push({ k: 'google', ios: false, html: '<button class="ls-wallet-btn ls-w-google" id="ls-w-google-btn" type="button">'
				+ esc(T('addGoogle')) + '</button>' });
		}
		btns.sort(function (a, b) {
			if (isIOS) return (b.ios ? 1 : 0) - (a.ios ? 1 : 0);
			if (isAndroid) return (a.ios ? 1 : 0) - (b.ios ? 1 : 0);
			return 0;
		});
		btns.forEach(function (b) { html += b.html; });
		html += '</div></div>';
		return html;
	}

	function initWalletButtons() {
		var btn = document.getElementById('ls-w-google-btn');
		if (!btn) return;
		btn.addEventListener('click', function () {
			btn.disabled = true;
			rest('wallet/google?token=' + encodeURIComponent(token)).then(function (d) {
				window.location.href = d.url;
			}).catch(function (e) {
				alert(e.message);
				btn.disabled = false;
			});
		});
	}

	/* ---------- Web Push ---------- */
	function urlB64ToUint8(b64) {
		var raw = atob(b64.replace(/-/g, '+').replace(/_/g, '/'));
		var arr = new Uint8Array(raw.length);
		for (var i = 0; i < raw.length; i++) arr[i] = raw.charCodeAt(i);
		return arr;
	}

	function enablePush() {
		if (!('serviceWorker' in navigator) || !('PushManager' in window) || !D.vapidKey) return;
		if (Notification.permission === 'denied') return;
		navigator.serviceWorker.register('/ls-sw.js', { scope: '/' }).then(function (reg) {
			return reg.pushManager.getSubscription().then(function (sub) {
				if (sub) return sub;
				return Notification.requestPermission().then(function (perm) {
					if (perm !== 'granted') throw new Error('denied');
					return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64ToUint8(D.vapidKey) });
				});
			});
		}).then(function (sub) {
			if (!sub) return;
			rest('push-subscribe', { method: 'POST', body: { token: token, subscription: sub.toJSON() } }).catch(function () {});
		}).catch(function () { /* push optional */ });
	}

	/* ---------- Geolocation (1km promos) ---------- */
	var lastLocSent = 0;
	function startLocation() {
		if (!('geolocation' in navigator) || !token) return;
		navigator.geolocation.watchPosition(function (pos) {
			var now = Date.now();
			if (now - lastLocSent < 5 * 60 * 1000) return; // throttle: max every 5 min
			lastLocSent = now;
			rest('location', {
				method: 'POST',
				body: { token: token, lat: pos.coords.latitude, lng: pos.coords.longitude }
			}).catch(function () {});
		}, function () {}, { enableHighAccuracy: false, maximumAge: 600000, timeout: 15000 });
	}

	/* ---------- Staff panel ---------- */
	function staffPost(action, data) {
		var fd = new FormData();
		fd.append('action', action);
		fd.append('nonce', D.staffNonce || '');
		Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
		return fetch(D.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
			.then(function (r) { return r.json(); })
			.then(function (j) {
				if (!j.success) throw new Error((j.data && j.data.message) || 'Error');
				return j.data;
			});
	}

	window.lsStaffFind = function (q) {
		var box = document.getElementById('ls-staff-result');
		if (!q) return;
		box.innerHTML = '…';
		staffPost('ls_staff_find', { q: q }).then(function (d) {
			var html = '<div class="ls-cust ls-reveal in"><h4>' + esc(d.name || '—') + '</h4><span class="ls-phone">' + esc(d.phone) + '</span>';
			d.cards.forEach(function (c) {
				html += '<div class="ls-cardrow"><div><strong>' + esc(c.program_name) + '</strong><br><span class="ls-progress-label">' + c.stamps + ' / ' + c.stamps_required + ' — ' + esc(c.reward_title || '') + '</span></div>'
					+ '<button class="ls-stamp-btn" data-cid="' + d.id + '" data-pid="' + c.program_id + '">+ ' + esc(T('stamp')) + '</button></div>';
			});
			if (!d.cards.length) {
				html += '<p>No active cards. Choose a program to start one:</p><div class="ls-cardrow"><select id="ls-newprog">'
					+ d.programs.map(function (p) { return '<option value="' + p.id + '">' + esc(p.name) + '</option>'; }).join('')
					+ '</select><button class="ls-stamp-btn" data-cid="' + d.id + '" data-newprog="1">+ Stamp</button></div>';
			}
			html += '<div class="ls-ok" id="ls-staff-msg"></div></div>';
			box.innerHTML = html;
			box.querySelectorAll('.ls-stamp-btn').forEach(function (btn) {
				btn.addEventListener('click', function () {
					var pid = btn.getAttribute('data-pid');
					if (btn.getAttribute('data-newprog')) {
						pid = document.getElementById('ls-newprog').value;
					}
					btn.disabled = true;
					staffPost('ls_staff_stamp', { customer_id: btn.getAttribute('data-cid'), program_id: pid })
						.then(function (r) {
							var msg = '✓ ' + r.stamps + ' stamps' + (r.completed ? ' — CARD COMPLETED! Reward: ' + r.reward : '');
							document.getElementById('ls-staff-msg').textContent = msg;
							window.lsStaffFind(q); // refresh
						})
						.catch(function (e) {
							document.getElementById('ls-staff-msg').textContent = e.message;
							btn.disabled = false;
						});
				});
			});
		}).catch(function (e) { box.innerHTML = '<div class="ls-error">' + esc(e.message) + '</div>'; });
	};

	window.lsStaffRedeem = function (code) {
		var box = document.getElementById('ls-redeem-result');
		if (!code) return;
		box.textContent = '…';
		staffPost('ls_staff_redeem', { code: code }).then(function (r) {
			box.innerHTML = '<span class="ls-ok">✓ ' + esc(r.title) + ' — ' + esc(r.customer) + ' (' + esc(r.code) + ')</span>';
		}).catch(function (e) { box.textContent = e.message; });
	};

	/* Staff QR scanner — scans the customer's wallet/card QR with the device camera. */
	window.lsStaffScan = function () {
		var overlay = document.getElementById('ls-scan-overlay');
		var video = document.getElementById('ls-scan-video');
		if (!overlay || !video) return;
		if (!('BarcodeDetector' in window)) {
			alert(T('scanNotSupported'));
			return;
		}
		if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
			alert(T('scanNotSupported'));
			return;
		}
		var stream = null, raf = null, done = false;
		function stop() {
			done = true;
			if (raf) cancelAnimationFrame(raf);
			if (stream) stream.getTracks().forEach(function (t) { t.stop(); });
			overlay.style.display = 'none';
		}
		document.getElementById('ls-scan-close').onclick = stop;
		overlay.style.display = 'flex';
		navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false })
			.then(function (s) {
				stream = s;
				video.srcObject = s;
				return video.play();
			})
			.then(function () {
				var detector = new BarcodeDetector({ formats: ['qr_code'] });
				var tick = function () {
					if (done) return;
					detector.detect(video).then(function (codes) {
						if (done) return;
						if (codes && codes.length && codes[0].rawValue) {
							var raw = codes[0].rawValue, token = raw;
							try {
								var u = new URL(raw);
								token = u.searchParams.get('card') || raw;
							} catch (e) { /* not a URL — use raw value */ }
							done = true;
							stop();
							var q = document.getElementById('ls-staff-q');
							if (q) q.value = token;
							window.lsStaffFind(token);
						} else {
							raf = requestAnimationFrame(tick);
						}
					}).catch(function () {
						if (!done) raf = requestAnimationFrame(tick);
					});
				};
				tick();
			})
			.catch(function (e) {
				stop();
				alert(T('scanCamError') + (e && e.message ? ': ' + e.message : ''));
			});
	};

	/* ---------- scroll reveal animations ---------- */
	function initReveal() {
		var els = document.querySelectorAll('.ls-reveal');
		if (!('IntersectionObserver' in window) || !els.length) {
			els.forEach(function (el) { el.classList.add('in'); });
			return;
		}
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (e) {
				if (e.isIntersecting) {
					e.target.classList.add('in');
					io.unobserve(e.target);
				}
			});
		}, { threshold: 0.12 });
		els.forEach(function (el) { io.observe(el); });
	}

	/* Re-run reveal for dynamically injected content. */
	function refreshReveal() {
		document.querySelectorAll('.ls-reveal:not(.in)').forEach(function (el) {
			el.classList.add('in');
		});
		initReveal();
	}

	/* ---------- ambient ember particles (like grillhub.pl) ---------- */
	function initEmbers() {
		var page = document.querySelector('.ls-page');
		if (!page || !('requestAnimationFrame' in window) || page.classList.contains('ls-light')) return;
		if (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
		var canvas = document.createElement('canvas');
		canvas.className = 'ls-embers';
		canvas.setAttribute('aria-hidden', 'true');
		page.insertBefore(canvas, page.firstChild);
		var ctx = canvas.getContext('2d');
		var W, H, parts = [];

		function resize() {
			W = canvas.width = page.offsetWidth;
			H = canvas.height = page.offsetHeight;
		}
		function spawn(anywhere) {
			return {
				x: Math.random() * W,
				y: anywhere ? Math.random() * H : H + 10,
				r: 0.8 + Math.random() * 2.4,
				vy: 0.25 + Math.random() * 0.85,
				vx: (Math.random() - 0.5) * 0.4,
				a: 0.25 + Math.random() * 0.55,
				hue: 8 + Math.random() * 26, // red -> ember orange
				ph: Math.random() * Math.PI * 2
			};
		}
		function reset() {
			parts = [];
			var n = Math.min(46, Math.floor(W / 22));
			for (var i = 0; i < n; i++) parts.push(spawn(true));
		}
		resize();
		reset();
		window.addEventListener('resize', function () { resize(); reset(); });

		var t = 0;
		(function tick() {
			t += 0.016;
			ctx.clearRect(0, 0, W, H);
			for (var i = 0; i < parts.length; i++) {
				var p = parts[i];
				p.y -= p.vy;
				p.x += p.vx + Math.sin(t * 1.4 + p.ph) * 0.3;
				if (p.y < -12 || p.x < -12 || p.x > W + 12) parts[i] = spawn(false);
				var flicker = 0.75 + 0.25 * Math.sin(t * 6 + p.ph);
				ctx.beginPath();
				ctx.arc(p.x, p.y, p.r, 0, Math.PI * 2);
				ctx.fillStyle = 'hsla(' + p.hue + ', 100%, 58%, ' + (p.a * flicker).toFixed(3) + ')';
				ctx.shadowColor = 'hsla(' + p.hue + ', 100%, 50%, .8)';
				ctx.shadowBlur = 8;
				ctx.fill();
				ctx.shadowBlur = 0;
			}
			requestAnimationFrame(tick);
		})();
	}

	/* ---------- boot ---------- */
	document.addEventListener('DOMContentLoaded', function () {
		renderQRCodes();
		initReveal();
		initEmbers();
		if (cardApp && token) {
			initTabs();
			loadCard();
		}
		// Staff result + tab panes get reveal too.
		document.addEventListener('click', function () {
			setTimeout(refreshReveal, 60);
		});
	});
})();
