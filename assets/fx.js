/* Grill Hub Menu – motion layer: Lenis (smooth scroll) + GSAP (reveals, sheets) + small effects ported from React Bits
   (ClickSpark, SpotlightCard) in plain JavaScript. Skipped automatically if the visitor prefers reduced motion. */
(function () {
  "use strict";
  var app = document.getElementById("app");
  if (!app || !window.gsap) return;
  if (window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches) return;
  var gsap = window.gsap, hasST = !!window.ScrollTrigger;
  app.classList.add("fx");
  var $ = function (id) { return document.getElementById(id); };

  /* ---------- Lenis smooth scrolling ---------- */
  var lenis = null;
  if (window.Lenis) {
    lenis = new window.Lenis({ duration: 1.1, smoothWheel: true, anchors: false });
    if (hasST) { gsap.registerPlugin(window.ScrollTrigger); lenis.on("scroll", window.ScrollTrigger.update); }
    gsap.ticker.add(function (t) { lenis.raf(t * 1000); });
    gsap.ticker.lagSmoothing(0);
    document.documentElement.classList.add("lenis");
    var nativeSIV = Element.prototype.scrollIntoView;
    Element.prototype.scrollIntoView = function () {
      if (lenis && this.classList && this.classList.contains("sec")) {
        lenis.scrollTo(this, { duration: 1.1 }); // Lenis honours the section's scroll-margin-top
      } else nativeSIV.apply(this, arguments);
    };
    ["sheet", "lines", "bld"].forEach(function (c) { var e = app.querySelector("." + c); if (e) e.setAttribute("data-lenis-prevent", ""); });
  }
  function overlayWatch() {
    var open = ["scrim", "bov", "okov"].some(function (id) { var e = $(id); return e && !e.hidden; });
    if (lenis) { if (open) lenis.stop(); else lenis.start(); }
  }

  /* ---------- Overlay entrances (GSAP) ---------- */
  function shown(id, fn) {
    var el = $(id); if (!el) return;
    new MutationObserver(function () { overlayWatch(); if (!el.hidden) fn(el); }).observe(el, { attributes: true, attributeFilter: ["hidden"] });
  }
  shown("scrim", function (el) { gsap.fromTo(el, { opacity: 0 }, { opacity: 1, duration: .25 }); gsap.fromTo(el.querySelector(".sheet"), { yPercent: 14, opacity: 0 }, { yPercent: 0, opacity: 1, duration: .45, ease: "power3.out" }); });
  shown("bov", function (el) { gsap.fromTo(el.querySelector(".bld"), { scale: .95, opacity: 0, y: 24 }, { scale: 1, opacity: 1, y: 0, duration: .45, ease: "power3.out" }); });
  shown("okov", function (el) { gsap.fromTo(el.querySelector(".okc"), { scale: .8, opacity: 0 }, { scale: 1, opacity: 1, duration: .5, ease: "back.out(1.7)" }); gsap.fromTo(el.querySelector(".okic"), { scale: 0, rotate: -90 }, { scale: 1, rotate: 0, duration: .6, delay: .15, ease: "back.out(2)" }); });

  /* ---------- Reveal on scroll (cards, section headings) ---------- */
  var seen = {}, io = null;
  if ("IntersectionObserver" in window) {
    io = new IntersectionObserver(function (es) {
      var batch = es.filter(function (e) { return e.isIntersecting; });
      if (!batch.length) return;
      batch.forEach(function (e) { io.unobserve(e.target); });
      gsap.to(batch.map(function (e) { return e.target; }), { opacity: 1, y: 0, filter: "blur(0px)", duration: .6, ease: "power3.out", stagger: .07, clearProps: "transform,filter,opacity" });
    }, { rootMargin: "0px 0px -6% 0px", threshold: .05 });
  }
  function prep(root) {
    if (!io) return;
    Array.prototype.forEach.call(root.querySelectorAll(".card, .bcard"), function (el) {
      var sec = el.closest(".sec"), t = el.querySelector("h3"), key = (sec ? sec.id : "") + ":" + (t ? t.textContent : "b");
      if (seen[key]) return; seen[key] = 1;
      gsap.set(el, { opacity: 0, y: 26, filter: "blur(4px)" }); io.observe(el);
    });
    Array.prototype.forEach.call(root.querySelectorAll(".sh2"), function (el) {
      var sec = el.closest(".sec"), key = "h:" + (sec ? sec.id : "") + (el.querySelector(".sht") || {}).textContent;
      if (seen[key]) return; seen[key] = 1;
      var lines = el.querySelectorAll(".shl"), ico = el.querySelector(".shi"), txt = el.querySelector(".sht");
      gsap.set(lines, { scaleX: 0 }); gsap.set(ico, { scale: 0, rotate: -60 }); gsap.set(txt, { opacity: 0, y: 10, filter: "blur(8px)" });
      var go = new IntersectionObserver(function (es) {
        if (!es[0].isIntersecting) return; go.disconnect();
        var tl = gsap.timeline();
        tl.to(ico, { scale: 1, rotate: 0, duration: .55, ease: "back.out(2)" }).to(txt, { opacity: 1, y: 0, filter: "blur(0px)", duration: .5, ease: "power2.out" }, "-=.35")
          .to(lines, { scaleX: 1, duration: .7, ease: "power3.out", transformOrigin: "center" }, "-=.5");
      }, { threshold: .6 });
      go.observe(el);
    });
  }
  var menu = $("menu");
  if (menu) {
    new MutationObserver(function () { prep(menu); }).observe(menu, { childList: true });
    prep(menu);
  }

  /* ---------- ClickSpark (React Bits ClickSpark, simplified) ---------- */
  var cv = document.createElement("canvas"), ctx = cv.getContext("2d");
  cv.style.cssText = "position:fixed;inset:0;width:100%;height:100%;pointer-events:none;z-index:90";
  document.body.appendChild(cv);
  function fit() { var r = window.devicePixelRatio || 1; cv.width = innerWidth * r; cv.height = innerHeight * r; ctx.setTransform(r, 0, 0, r, 0, 0); }
  fit(); window.addEventListener("resize", fit);
  var bursts = [], running = false;
  function draw() {
    ctx.clearRect(0, 0, innerWidth, innerHeight);
    bursts = bursts.filter(function (b) { return b.p.t < 1; });
    bursts.forEach(function (b) {
      var t = b.p.t, e = 1 - Math.pow(1 - t, 3);
      ctx.lineCap = "round"; ctx.lineWidth = 2.4 * (1 - t) + .4;
      b.s.forEach(function (s) {
        var r0 = 6 + e * s.d * .35, r1 = 6 + e * s.d;
        ctx.strokeStyle = s.c; ctx.globalAlpha = 1 - t;
        ctx.beginPath(); ctx.moveTo(b.x + Math.cos(s.a) * r0, b.y + Math.sin(s.a) * r0); ctx.lineTo(b.x + Math.cos(s.a) * r1, b.y + Math.sin(s.a) * r1); ctx.stroke();
      });
    });
    ctx.globalAlpha = 1;
    if (!bursts.length) { gsap.ticker.remove(draw); running = false; ctx.clearRect(0, 0, innerWidth, innerHeight); }
  }
  function spark(x, y) {
    var cols = [getComputedStyle(app).getPropertyValue("--red").trim() || "#e11e26", "#ffffff", "#ffffff"], s = [], n = 9, i, off = Math.random() * 6;
    for (i = 0; i < n; i++) s.push({ a: off + i * 6.2832 / n, d: 18 + Math.random() * 16, c: cols[i % cols.length] });
    var b = { x: x, y: y, s: s, p: { t: 0 } }; bursts.push(b);
    gsap.to(b.p, { t: 1, duration: .55, ease: "none" });
    if (!running) { running = true; gsap.ticker.add(draw); }
  }
  document.addEventListener("click", function (e) {
    var t = e.target.closest && e.target.closest(".add, .plus, .cb, .badd, .send");
    if (t && e.clientX) spark(e.clientX, e.clientY);
  }, true);

  /* ---------- SpotlightCard glow (React Bits SpotlightCard, simplified) ---------- */
  if (window.matchMedia && window.matchMedia("(hover: hover)").matches && menu) {
    menu.addEventListener("pointermove", function (e) {
      var c = e.target.closest(".card"); if (!c) return;
      var r = c.getBoundingClientRect(); c.style.setProperty("--mx", (e.clientX - r.left) + "px"); c.style.setProperty("--my", (e.clientY - r.top) + "px");
    }, { passive: true });
  }
})();
