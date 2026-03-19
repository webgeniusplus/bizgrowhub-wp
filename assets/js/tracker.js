/**
 * MarketPulse Tracker v2 — Full visitor analytics
 * Competes with GA4 + Hotjar/Clarity + Mixpanel
 * Config: window.__MP = { k: LICENSE_KEY, api: EVENTS_ENDPOINT }
 * No cookies, privacy-respecting, lightweight (~10KB)
 */
(function(){
  'use strict';
  var cfg = window.__MP || {};
  if (!cfg.k || !cfg.api) return;

  var KEY = cfg.k;
  var API = cfg.api;
  var DOMAIN = location.hostname.replace(/^www\./, '');
  var queue = [];
  var scrollHit = {};
  var t0 = performance.now();
  var doc = document, win = window, loc = location;

  // ── Session (sessionStorage) ──
  var SID_KEY = 'mp_sid';
  var VISIT_KEY = 'mp_visited'; // localStorage — persists across sessions
  var SSTART_KEY = 'mp_sstart';

  function sid() {
    var s = sessionStorage.getItem(SID_KEY);
    if (!s) { s = uuid(); sessionStorage.setItem(SID_KEY, s); }
    return s;
  }
  function uuid() {
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
      var r = Math.random() * 16 | 0;
      return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16);
    });
  }

  // ── Queue & Flush ──
  function push(name, cat, data) {
    queue.push({
      event_name: name, category: cat || 'custom', data: data || {},
      timestamp: new Date().toISOString(), page_url: loc.href, session_id: sid()
    });
  }

  function flush() {
    if (!queue.length) return;
    var batch = queue.splice(0, 30);
    var body = JSON.stringify({ license_key: KEY, domain: DOMAIN, events: batch });
    if (win.fetch) {
      try {
        fetch(API, {
          method: 'POST', headers: { 'Content-Type': 'application/json' },
          body: body, keepalive: true, mode: 'cors'
        }).catch(function(){});
      } catch(e) {
        if (navigator.sendBeacon) navigator.sendBeacon(API, new Blob([body], { type: 'text/plain' }));
      }
    } else if (navigator.sendBeacon) {
      navigator.sendBeacon(API, new Blob([body], { type: 'text/plain' }));
    } else {
      var x = new XMLHttpRequest();
      x.open('POST', API); x.setRequestHeader('Content-Type', 'application/json'); x.send(body);
    }
    if (queue.length) flush();
  }

  // ════════════════════════════════════════
  //  CORE EVENTS
  // ════════════════════════════════════════

  // ── 1. Session Start ──
  if (!sessionStorage.getItem(SSTART_KEY)) {
    sessionStorage.setItem(SSTART_KEY, '1');
    push('session_start', 'session', {
      url: loc.href, referrer: doc.referrer || '', landing_page: loc.pathname
    });
  }

  // ── 2. First Visit (new vs returning) ──
  var isFirstVisit = false;
  try {
    if (!localStorage.getItem(VISIT_KEY)) {
      isFirstVisit = true;
      localStorage.setItem(VISIT_KEY, new Date().toISOString());
      push('first_visit', 'session', { url: loc.href, referrer: doc.referrer || '' });
    }
  } catch(e) {}

  // ── 3. Page View + UTM + Referrer Classification ──
  var utmParams = {};
  ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function(p) {
    var v = getParam(p);
    if (v) utmParams[p] = v;
  });

  var referrerType = classifyReferrer(doc.referrer);

  push('page_view', 'pageview', {
    url: loc.href, referrer: doc.referrer || '', title: doc.title || '',
    path: loc.pathname, hash: loc.hash || '',
    screen_w: win.innerWidth, screen_h: win.innerHeight,
    language: navigator.language || '', platform: navigator.platform || '',
    is_first_visit: isFirstVisit,
    referrer_type: referrerType,
    utm: Object.keys(utmParams).length ? utmParams : undefined,
    connection: navigator.connection ? {
      type: navigator.connection.effectiveType || '',
      downlink: navigator.connection.downlink || 0
    } : undefined
  });

  // ── 4. Scroll Depth (25/50/75/100%) ──
  var scrollTick = false;
  win.addEventListener('scroll', function() {
    if (scrollTick) return; scrollTick = true;
    requestAnimationFrame(function() {
      scrollTick = false;
      var st = win.pageYOffset || doc.documentElement.scrollTop;
      var dh = Math.max(doc.body.scrollHeight, doc.documentElement.scrollHeight);
      var wh = win.innerHeight;
      if (dh <= wh) return;
      var pct = Math.round((st / (dh - wh)) * 100);
      [25, 50, 75, 100].forEach(function(m) {
        if (pct >= m && !scrollHit[m]) { scrollHit[m] = true; push('scroll_depth', 'engagement', { depth: m, page_h: dh, url: loc.href }); }
      });
    });
  }, { passive: true });

  // ── 5. Time on Page ──
  var timeFired = false;
  function fireTime() {
    if (timeFired) return; timeFired = true;
    var sec = Math.round((performance.now() - t0) / 1000);
    if (sec < 1) return;
    push('time_on_page', 'engagement', { seconds: sec, url: loc.href });
    flush();
  }
  win.addEventListener('beforeunload', fireTime);
  win.addEventListener('pagehide', fireTime);
  setTimeout(function() { if (!timeFired) push('time_on_page', 'engagement', { seconds: Math.round((performance.now() - t0) / 1000), url: loc.href }); }, 300000);

  // ── 6. Quick Back / Bounce Detection ──
  setTimeout(function() {
    win.addEventListener('beforeunload', function onBounce() {
      var sec = Math.round((performance.now() - t0) / 1000);
      if (sec <= 5) {
        push('quick_back', 'engagement', { seconds: sec, url: loc.href, referrer: doc.referrer || '' });
      }
      win.removeEventListener('beforeunload', onBounce);
    });
  }, 100);

  // ════════════════════════════════════════
  //  INTERACTION EVENTS
  // ════════════════════════════════════════

  // ── 7. Click Tracking (links, buttons, data-mp-track) ──
  doc.addEventListener('click', function(e) {
    var el = e.target, found = null, i = 0;
    while (el && i < 5) {
      if (el.tagName === 'A' || el.tagName === 'BUTTON' || (el.hasAttribute && el.hasAttribute('data-mp-track'))) { found = el; break; }
      el = el.parentElement; i++;
    }
    if (!found) return;

    // Custom data-mp-track
    if (found.hasAttribute('data-mp-track')) {
      var cn = found.getAttribute('data-mp-track');
      var cc = found.getAttribute('data-mp-category') || 'custom';
      var cd = {}; try { cd = JSON.parse(found.getAttribute('data-mp-data') || '{}'); } catch(x) {}
      push(cn, cc, cd);
      if (found.tagName !== 'A' && found.tagName !== 'BUTTON') return;
    }

    // File download
    if (found.tagName === 'A' && found.href) {
      var ext = found.href.split('?')[0].split('.').pop().toLowerCase();
      if (['pdf','zip','doc','docx','xls','xlsx','csv','ppt','pptx','mp3','mp4','avi','mov','rar','7z','gz','tar'].indexOf(ext) !== -1) {
        push('file_download', 'interaction', {
          file_url: found.href, file_name: found.href.split('/').pop().split('?')[0],
          file_ext: ext, text: (found.textContent || '').trim().substring(0, 50), url: loc.href
        });
        return;
      }
    }

    // Outbound
    if (found.tagName === 'A' && found.hostname && found.hostname !== loc.hostname) {
      push('outbound_click', 'interaction', { href: found.href || '', text: (found.textContent || '').trim().substring(0, 50), url: loc.href });
      return;
    }

    // Regular click
    push('click', 'interaction', {
      tag: found.tagName.toLowerCase(),
      text: (found.textContent || '').trim().substring(0, 50),
      href: found.href || '',
      id: found.id || '',
      classes: found.className ? String(found.className).substring(0, 100) : '',
      url: loc.href
    });
  }, true);

  // ── 8. Dead Click (click on non-interactive element) ──
  doc.addEventListener('click', function(e) {
    var el = e.target;
    if (!el || !el.tagName) return;
    var tag = el.tagName;
    // Skip interactive elements
    if (['A', 'BUTTON', 'INPUT', 'SELECT', 'TEXTAREA', 'LABEL', 'VIDEO', 'AUDIO'].indexOf(tag) !== -1) return;
    if (el.closest && (el.closest('a') || el.closest('button') || el.closest('[role="button"]') || el.closest('[data-mp-track]') || el.closest('input'))) return;
    // Only fire if element has no click handler (heuristic: check style cursor)
    var cs = win.getComputedStyle(el);
    if (cs.cursor === 'pointer') return; // likely interactive
    push('dead_click', 'ux', {
      tag: tag.toLowerCase(), text: (el.textContent || '').trim().substring(0, 50),
      id: el.id || '', classes: el.className ? String(el.className).substring(0, 80) : '',
      x: e.clientX, y: e.clientY, url: loc.href
    });
  });

  // ── 9. Form Events ──
  // Form Start (focus on first field)
  var formStarted = {};
  doc.addEventListener('focus', function(e) {
    var el = e.target;
    if (!el || !el.form) return;
    var formId = el.form.id || el.form.action || 'form_' + Array.prototype.indexOf.call(doc.forms, el.form);
    if (formStarted[formId]) return;
    formStarted[formId] = true;
    push('form_start', 'interaction', { form_id: el.form.id || '', action: el.form.action || '', field: el.name || el.type || '', url: loc.href });
  }, true);

  // Form Submit
  doc.addEventListener('submit', function(e) {
    var f = e.target; if (!f || f.tagName !== 'FORM') return;
    push('form_submit', 'interaction', { form_id: f.id || '', action: f.action || '', method: (f.method || 'get').toUpperCase(), url: loc.href });
  }, true);

  // ── 10. Text Copy ──
  doc.addEventListener('copy', function() {
    var sel = win.getSelection();
    push('text_copy', 'interaction', { text: (sel ? sel.toString() : '').substring(0, 100), url: loc.href });
  });

  // ════════════════════════════════════════
  //  ENGAGEMENT EVENTS
  // ════════════════════════════════════════

  // ── 11. Rage Click (5+ clicks in 2s) ──
  var clickTimes = [];
  doc.addEventListener('click', function(e) {
    var now = Date.now();
    clickTimes.push(now);
    clickTimes = clickTimes.filter(function(t) { return now - t < 2000; });
    if (clickTimes.length >= 5) {
      push('rage_click', 'ux', { clicks: clickTimes.length, x: e.clientX, y: e.clientY, url: loc.href });
      clickTimes = [];
    }
  });

  // ── 12. Tab Visibility ──
  doc.addEventListener('visibilitychange', function() {
    push('visibility_change', 'engagement', { state: doc.visibilityState, seconds: Math.round((performance.now() - t0) / 1000), url: loc.href });
  });

  // ── 13. JS Errors ──
  win.addEventListener('error', function(e) {
    push('js_error', 'system', { message: e.message || '', filename: (e.filename || '').split('/').pop(), line: e.lineno || 0, col: e.colno || 0, url: loc.href });
  });
  win.addEventListener('unhandledrejection', function(e) {
    push('js_promise_error', 'system', { message: String(e.reason || '').substring(0, 200), url: loc.href });
  });

  // ── 14. View Search Results (WordPress ?s= param) ──
  var searchQuery = getParam('s');
  if (searchQuery) {
    push('view_search_results', 'engagement', { query: searchQuery, url: loc.href });
  }

  // ════════════════════════════════════════
  //  PERFORMANCE (Core Web Vitals)
  // ════════════════════════════════════════

  // ── 15. Core Web Vitals — LCP, CLS, INP ──
  if (win.PerformanceObserver) {
    // LCP (Largest Contentful Paint)
    try {
      var lcpObs = new PerformanceObserver(function(list) {
        var entries = list.getEntries();
        var last = entries[entries.length - 1];
        if (last) push('web_vital_lcp', 'performance', { value: Math.round(last.startTime), rating: last.startTime < 2500 ? 'good' : last.startTime < 4000 ? 'needs_improvement' : 'poor', url: loc.href });
      });
      lcpObs.observe({ type: 'largest-contentful-paint', buffered: true });
    } catch(e) {}

    // CLS (Cumulative Layout Shift)
    try {
      var clsValue = 0;
      var clsObs = new PerformanceObserver(function(list) {
        list.getEntries().forEach(function(entry) {
          if (!entry.hadRecentInput) clsValue += entry.value;
        });
      });
      clsObs.observe({ type: 'layout-shift', buffered: true });
      // Report CLS on page leave
      win.addEventListener('beforeunload', function() {
        push('web_vital_cls', 'performance', { value: Math.round(clsValue * 1000) / 1000, rating: clsValue < 0.1 ? 'good' : clsValue < 0.25 ? 'needs_improvement' : 'poor', url: loc.href });
      });
    } catch(e) {}

    // FID / INP (First Input Delay / Interaction to Next Paint)
    try {
      var fidObs = new PerformanceObserver(function(list) {
        var entry = list.getEntries()[0];
        if (entry) push('web_vital_fid', 'performance', { value: Math.round(entry.processingStart - entry.startTime), rating: (entry.processingStart - entry.startTime) < 100 ? 'good' : (entry.processingStart - entry.startTime) < 300 ? 'needs_improvement' : 'poor', url: loc.href });
      });
      fidObs.observe({ type: 'first-input', buffered: true });
    } catch(e) {}

    // Navigation timing (TTFB, DOM load)
    try {
      win.addEventListener('load', function() {
        setTimeout(function() {
          var nav = performance.getEntriesByType('navigation')[0];
          if (nav) {
            push('page_performance', 'performance', {
              ttfb: Math.round(nav.responseStart - nav.requestStart),
              dom_interactive: Math.round(nav.domInteractive),
              dom_complete: Math.round(nav.domComplete),
              load_time: Math.round(nav.loadEventEnd - nav.startTime),
              transfer_size: nav.transferSize || 0,
              url: loc.href
            });
          }
        }, 0);
      });
    } catch(e) {}
  }

  // ════════════════════════════════════════
  //  ELEMENT VISIBILITY (Impressions)
  // ════════════════════════════════════════

  // ── 16. Element Impressions via IntersectionObserver ──
  function trackImpressions() {
    if (!win.IntersectionObserver) return;
    var tracked = {};
    var observer = new IntersectionObserver(function(entries) {
      entries.forEach(function(entry) {
        if (!entry.isIntersecting) return;
        var el = entry.target;
        var impId = el.getAttribute('data-mp-impression') || el.id || '';
        if (!impId || tracked[impId]) return;
        tracked[impId] = true;
        push('element_impression', 'engagement', {
          element_id: impId, tag: el.tagName.toLowerCase(),
          text: (el.textContent || '').trim().substring(0, 50), url: loc.href
        });
      });
    }, { threshold: 0.5 }); // 50% visible

    // Auto-track elements with data-mp-impression attribute
    doc.querySelectorAll('[data-mp-impression]').forEach(function(el) { observer.observe(el); });

    // Auto-track common important elements
    var selectors = ['.hero', '.cta', '.banner', '.popup', '.modal', '[data-mp-impression]',
      '.woocommerce-products-header', '.site-header', '.newsletter', '.contact-form'];
    selectors.forEach(function(sel) {
      doc.querySelectorAll(sel).forEach(function(el) {
        if (!el.id && !el.getAttribute('data-mp-impression')) el.setAttribute('data-mp-impression', sel.replace(/[.#\[\]]/g, ''));
        observer.observe(el);
      });
    });
  }

  // ════════════════════════════════════════
  //  WOOCOMMERCE ENHANCED ECOMMERCE
  // ════════════════════════════════════════

  function initWoo() {
    var bc = doc.body.className || '';

    // ── Product View (single product page) ──
    if (bc.indexOf('single-product') !== -1) {
      var pt = doc.querySelector('.product_title');
      var pa = doc.querySelector('[name="add-to-cart"]');
      var pp = doc.querySelector('.price .amount, .price ins .amount');
      var pcat = doc.querySelector('.posted_in a');
      var psku = doc.querySelector('.sku');
      push('product_view', 'ecommerce', {
        product_name: pt ? pt.textContent.trim() : '', product_id: pa ? pa.value : '',
        price: pp ? pp.textContent.trim() : '', category: pcat ? pcat.textContent.trim() : '',
        sku: psku ? psku.textContent.trim() : '', url: loc.href
      });
    }

    // ── View Item List (shop/category page) ──
    if (bc.indexOf('woocommerce-shop') !== -1 || bc.indexOf('tax-product_cat') !== -1 || bc.indexOf('post-type-archive-product') !== -1) {
      var products = doc.querySelectorAll('li.product, .product');
      var items = [];
      products.forEach(function(p, idx) {
        var name = p.querySelector('.woocommerce-loop-product__title, .product_title, h2');
        var price = p.querySelector('.price .amount, .price ins .amount');
        var link = p.querySelector('a.woocommerce-LoopProduct-link, a');
        items.push({
          name: name ? name.textContent.trim() : '',
          price: price ? price.textContent.trim() : '',
          position: idx + 1,
          url: link ? link.href : ''
        });
      });
      var catTitle = doc.querySelector('.woocommerce-products-header__title, .page-title');
      push('view_item_list', 'ecommerce', {
        list_name: catTitle ? catTitle.textContent.trim() : 'Shop',
        items_count: items.length, items: items.slice(0, 20), url: loc.href
      });

      // ── Select Item (click on product from listing) ──
      products.forEach(function(p) {
        p.addEventListener('click', function(e) {
          var link = p.querySelector('a');
          var name = p.querySelector('.woocommerce-loop-product__title, h2');
          var price = p.querySelector('.price .amount');
          push('select_item', 'ecommerce', {
            product_name: name ? name.textContent.trim() : '',
            price: price ? price.textContent.trim() : '',
            from_list: catTitle ? catTitle.textContent.trim() : 'Shop',
            url: link ? link.href : loc.href
          });
        });
      });
    }

    // ── View Cart ──
    if (bc.indexOf('woocommerce-cart') !== -1) {
      var cartItems = doc.querySelectorAll('.woocommerce-cart-form .cart_item');
      var cartTotal = doc.querySelector('.order-total .amount, .cart-subtotal .amount');
      push('view_cart', 'ecommerce', {
        items_count: cartItems.length,
        cart_total: cartTotal ? cartTotal.textContent.trim() : '',
        url: loc.href
      });

      // ── Remove from Cart ──
      doc.querySelectorAll('.remove, .product-remove a').forEach(function(btn) {
        btn.addEventListener('click', function() {
          var row = btn.closest('tr, .cart_item');
          var name = row ? row.querySelector('.product-name a') : null;
          push('remove_from_cart', 'ecommerce', {
            product_name: name ? name.textContent.trim() : '',
            url: loc.href
          });
        });
      });
    }

    // ── Begin Checkout ──
    if (bc.indexOf('woocommerce-checkout') !== -1) {
      var ct = doc.querySelector('.order-total .amount');
      push('begin_checkout', 'ecommerce', { url: loc.href, cart_total: ct ? ct.textContent.trim() : '' });

      // ── Add Shipping Info (blur on shipping fields) ──
      var shippingFired = false;
      var shipFields = doc.querySelectorAll('#shipping_first_name, #shipping_address_1, #shipping_city, [name="shipping_method[0]"]');
      shipFields.forEach(function(f) {
        f.addEventListener('change', function() {
          if (shippingFired) return; shippingFired = true;
          var method = doc.querySelector('[name="shipping_method[0]"]:checked, [name="shipping_method[0]"]');
          push('add_shipping_info', 'ecommerce', {
            shipping_method: method ? method.value : '',
            url: loc.href
          });
        });
      });

      // ── Add Payment Info (payment method selection) ──
      var paymentFired = false;
      doc.querySelectorAll('[name="payment_method"]').forEach(function(radio) {
        radio.addEventListener('change', function() {
          if (paymentFired) return; paymentFired = true;
          push('add_payment_info', 'ecommerce', {
            payment_method: radio.value || '', url: loc.href
          });
        });
      });

      // ── Coupon Applied ──
      var couponForm = doc.querySelector('.checkout_coupon, .woocommerce-form-coupon');
      if (couponForm) {
        couponForm.addEventListener('submit', function() {
          var input = couponForm.querySelector('[name="coupon_code"]');
          push('coupon_applied', 'ecommerce', { coupon_code: input ? input.value.trim() : '', url: loc.href });
        });
      }
    }

    // ── Purchase Complete (thank you page) ──
    if (bc.indexOf('woocommerce-order-received') !== -1) {
      var oe = doc.querySelector('.woocommerce-order-overview__order strong');
      var oid = oe ? oe.textContent.trim() : '';
      if (!oid) { var m = loc.href.match(/order-received\/(\d+)/); if (m) oid = m[1]; }
      var total = doc.querySelector('.woocommerce-order-overview__total .amount, .order-total .amount');
      var method = doc.querySelector('.woocommerce-order-overview__payment-method strong');
      push('purchase', 'ecommerce', {
        order_id: oid, total: total ? total.textContent.trim() : '',
        payment_method: method ? method.textContent.trim() : '', url: loc.href
      });
    }

    // ── Add to Cart — single product button ──
    doc.addEventListener('click', function(e) {
      var btn = e.target, cur = btn, j = 0;
      while (cur && j < 3) {
        if (cur.classList && cur.classList.contains('single_add_to_cart_button')) {
          var t = doc.querySelector('.product_title');
          var p = doc.querySelector('[name="add-to-cart"]');
          var q = doc.querySelector('[name="quantity"]');
          var pr = doc.querySelector('.price .amount, .price ins .amount');
          push('add_to_cart', 'ecommerce', {
            product_name: t ? t.textContent.trim() : '', product_id: p ? p.value : '',
            quantity: q ? q.value : '1', price: pr ? pr.textContent.trim() : '', url: loc.href
          });
          return;
        }
        cur = cur.parentElement; j++;
      }
    }, true);

    // ── Add to Cart — AJAX (archive pages) ──
    if (win.jQuery) {
      jQuery(doc.body).on('added_to_cart', function(ev, frag, hash, btn) {
        var nm = '', pid = '', pr = '';
        if (btn && btn.length) {
          var li = btn.closest('li.product,.product');
          if (li.length) {
            var te = li.find('.woocommerce-loop-product__title,.product_title,h2');
            nm = te.length ? te.text().trim() : '';
            var pe = li.find('.price .amount');
            pr = pe.length ? pe.first().text().trim() : '';
          }
          pid = btn.data('product_id') || '';
        }
        push('add_to_cart', 'ecommerce', { product_name: nm, product_id: String(pid), quantity: '1', price: pr, url: loc.href });
      });

      // ── Remove from Cart — AJAX ──
      jQuery(doc.body).on('removed_from_cart', function() {
        push('remove_from_cart', 'ecommerce', { url: loc.href, method: 'ajax' });
      });
    }
  }

  // ════════════════════════════════════════
  //  VIDEO TRACKING
  // ════════════════════════════════════════

  function initVideoTracking() {
    // HTML5 video
    doc.querySelectorAll('video').forEach(function(v) {
      var src = v.currentSrc || v.src || '';
      var videoId = v.id || src.split('/').pop() || 'unknown';
      v.addEventListener('play', function() { push('video_start', 'media', { video_id: videoId, src: src.substring(0, 200), url: loc.href }); });
      v.addEventListener('ended', function() { push('video_complete', 'media', { video_id: videoId, duration: Math.round(v.duration || 0), url: loc.href }); });
      var progressFired = {};
      v.addEventListener('timeupdate', function() {
        if (!v.duration) return;
        var pct = Math.round((v.currentTime / v.duration) * 100);
        [25, 50, 75].forEach(function(m) {
          if (pct >= m && !progressFired[m]) { progressFired[m] = true; push('video_progress', 'media', { video_id: videoId, percent: m, url: loc.href }); }
        });
      });
    });

    // YouTube iframes
    doc.querySelectorAll('iframe[src*="youtube.com"], iframe[src*="youtu.be"]').forEach(function(iframe) {
      var src = iframe.src || '';
      var match = src.match(/(?:embed\/|v=)([a-zA-Z0-9_-]+)/);
      var videoId = match ? match[1] : 'unknown';
      // Can't deep-track YouTube without API, but track impression
      push('youtube_embed_view', 'media', { video_id: videoId, url: loc.href });
    });
  }

  // ════════════════════════════════════════
  //  HELPERS
  // ════════════════════════════════════════

  function getParam(name) {
    var match = loc.search.match(new RegExp('[?&]' + name + '=([^&]*)'));
    return match ? decodeURIComponent(match[1]) : '';
  }

  function classifyReferrer(ref) {
    if (!ref) return 'direct';
    try {
      var h = new URL(ref).hostname.toLowerCase();
      if (h === DOMAIN) return 'internal';
      if (/google\.|bing\.|yahoo\.|baidu\.|duckduckgo\.|yandex\./i.test(h)) return 'organic_search';
      if (/facebook\.|fb\.|instagram\.|twitter\.|x\.com|linkedin\.|pinterest\.|tiktok\.|reddit\.|youtube\./i.test(h)) return 'social';
      if (/mail\.|gmail\.|outlook\.|yahoo\.com\/mail/i.test(h)) return 'email';
      return 'referral';
    } catch(e) { return 'referral'; }
  }

  // ════════════════════════════════════════
  //  INIT
  // ════════════════════════════════════════

  function init() {
    initWoo();
    trackImpressions();
    initVideoTracking();
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', init); } else { init(); }

  setInterval(flush, 5000);
  win.addEventListener('beforeunload', flush);
  win.addEventListener('pagehide', flush);

  // Public API
  win.MarketPulse = {
    track: function(n, c, d) { push(n, c || 'custom', d || {}); },
    flush: flush,
    identify: function(userId, props) { push('user_identify', 'user', { user_id: userId, properties: props || {} }); }
  };
})();
