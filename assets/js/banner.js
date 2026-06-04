/*
 * LMG Consent — client logic.
 *
 * Responsibilities:
 *   1. Read/write consent state (cookie + localStorage, versioned).
 *   2. Honor Global Privacy Control (GPC) as a browser-level opt-out (CPRA).
 *   3. Show the banner on first visit; hide once a decision has been made.
 *   4. Drive the customize modal (render categories, apply toggle state).
 *   5. Push Google Consent Mode v2 updates (gtag('consent','update',...)).
 *   6. Log each decision to the REST endpoint (if logging enabled).
 *   7. Dispatch `m_consent:change` (+ `lmg_consent:change`) for integrators.
 */
(function () {
  'use strict';

  if (typeof window.mConsent !== 'object') return;
  var CFG = window.mConsent;

  var GCM_MAP = {
    functional: ['functionality_storage', 'personalization_storage'],
    analytics:  ['analytics_storage'],
    marketing:  ['ad_storage', 'ad_user_data', 'ad_personalization']
  };

  /* ---------- Global Privacy Control ---------- */
  // CPRA treats a GPC signal as a valid opt-out of sale/sharing. We map it to
  // a reject of all non-essential categories.
  function gpcEnabled() {
    try {
      if (navigator && navigator.globalPrivacyControl === true) return true;
      if (window.globalPrivacyControl === true) return true;
    } catch (e) {}
    return false;
  }

  /* ---------- storage helpers ---------- */
  function readCookie(name) {
    var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/[.$?*|{}()[\]\\\/+^]/g, '\\$&') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
  }
  function writeCookie(name, value, maxAgeSeconds) {
    var parts = [
      name + '=' + encodeURIComponent(value),
      'path=/',
      'max-age=' + Math.floor(maxAgeSeconds),
      'SameSite=Lax'
    ];
    if (location.protocol === 'https:') parts.push('Secure');
    document.cookie = parts.join('; ');
  }

  function readState() {
    var raw = readCookie(CFG.cookie) || localStorage.getItem(CFG.cookie);
    if (!raw) return null;
    try {
      var s = JSON.parse(raw);
      if (!s || s.schema !== CFG.schema) return null;
      return s;
    } catch (e) { return null; }
  }
  function writeState(state) {
    var json = JSON.stringify(state);
    writeCookie(CFG.cookie, json, CFG.cookieTTL);
    try { localStorage.setItem(CFG.cookie, json); } catch (e) {}
  }

  /* ---------- id generation ---------- */
  function newConsentId() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    return 'mc-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
  }

  /* ---------- gtag bridge ---------- */
  function pushConsent(categories) {
    if (typeof window.gtag !== 'function') return;
    var update = {};
    Object.keys(GCM_MAP).forEach(function (cat) {
      var val = categories[cat] ? 'granted' : 'denied';
      GCM_MAP[cat].forEach(function (key) { update[key] = val; });
    });
    window.gtag('consent', 'update', update);
  }

  /* ---------- REST logging ---------- */
  function log(action, categories, consentId) {
    if (!CFG.logEnabled) return;
    try {
      fetch(CFG.restUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': CFG.restNonce
        },
        body: JSON.stringify({
          action: action,
          categories: categories,
          consent_id: consentId,
          schema: CFG.schema
        }),
        keepalive: true
      }).catch(function(){});
    } catch (e) {}
  }

  /* ---------- DOM ---------- */
  var root;

  function show(el)   { if (el) el.removeAttribute('hidden'); }
  function hide(el)   { if (el) el.setAttribute('hidden', ''); }

  function buildCategoryRow(cat) {
    var row = document.createElement('div');
    row.className = 'm-consent__cat';

    var main = document.createElement('div');
    main.className = 'm-consent__cat-main';

    var h = document.createElement('h3');
    h.className = 'm-consent__cat-title';
    h.textContent = cat.title;

    var p = document.createElement('p');
    p.className = 'm-consent__cat-desc';
    p.textContent = cat.desc;

    main.appendChild(h);
    main.appendChild(p);

    var label = document.createElement('label');
    label.className = 'm-consent__toggle';

    var input = document.createElement('input');
    input.type = 'checkbox';
    input.setAttribute('data-m-consent-cat', cat.key);
    if (cat.required) {
      input.checked = true;
      input.disabled = true;
    }

    var slider = document.createElement('span');
    slider.className = 'm-consent__toggle-slider';
    slider.setAttribute('aria-hidden', 'true');

    label.appendChild(input);
    label.appendChild(slider);

    row.appendChild(main);
    row.appendChild(label);
    return row;
  }

  function render() {
    root = document.getElementById('m-consent');
    if (!root) return;

    var body = root.querySelector('[data-m-consent-categories]');
    if (body) {
      while (body.firstChild) body.removeChild(body.firstChild);
      CFG.categories.forEach(function (cat) {
        if (!cat.enabled) return;
        body.appendChild(buildCategoryRow(cat));
      });
    }

    wireEvents();

    var state = readState();
    if (!state) {
      // No prior decision. If the browser sends a GPC opt-out, honor it
      // automatically and don't force interaction with the banner.
      if (gpcEnabled()) {
        save('gpc', rejectAll());
        return;
      }
      show(root);
      applyTogglesFromCategories(defaultCategories());
    } else {
      // Already decided — keep hidden but apply stored choices to gtag.
      pushConsent(state.categories);
    }
  }

  function defaultCategories() {
    var c = {};
    CFG.categories.forEach(function (cat) { c[cat.key] = cat.required === true; });
    return c;
  }
  function fullAccept() {
    var c = {};
    CFG.categories.forEach(function (cat) { c[cat.key] = true; });
    return c;
  }
  function rejectAll() {
    return defaultCategories();
  }
  function readToggles() {
    var c = defaultCategories();
    root.querySelectorAll('[data-m-consent-cat]').forEach(function (input) {
      c[input.getAttribute('data-m-consent-cat')] = !!input.checked;
    });
    return c;
  }
  function applyTogglesFromCategories(cats) {
    root.querySelectorAll('[data-m-consent-cat]').forEach(function (input) {
      var key = input.getAttribute('data-m-consent-cat');
      if (!input.disabled) input.checked = !!cats[key];
    });
  }

  function save(action, categories) {
    var prev = readState();
    var consentId = (prev && prev.consent_id) || newConsentId();
    var state = {
      schema: CFG.schema,
      consent_id: consentId,
      ts: new Date().toISOString(),
      action: action,
      categories: categories
    };
    writeState(state);
    pushConsent(categories);
    log(action, categories, consentId);
    document.dispatchEvent(new CustomEvent('m_consent:change', { detail: state }));
    document.dispatchEvent(new CustomEvent('lmg_consent:change', { detail: state }));
    closeBanner();
    closeModal();
  }

  function openModal() {
    var modal = root.querySelector('[data-m-consent-modal]');
    var state = readState();
    applyTogglesFromCategories(state ? state.categories : defaultCategories());
    show(root);
    show(modal);
    var first = modal.querySelector('input:not([disabled]), button');
    if (first) first.focus();
  }
  function closeModal() {
    var modal = root.querySelector('[data-m-consent-modal]');
    hide(modal);
    if (readState()) hide(root);
  }
  function closeBanner() {
    hide(root);
  }

  function wireEvents() {
    root.addEventListener('click', function (e) {
      var t = e.target.closest('[data-m-consent-accept], [data-m-consent-reject], [data-m-consent-customize], [data-m-consent-save], [data-m-consent-close]');
      if (!t) return;
      if (t.hasAttribute('data-m-consent-accept'))    return save('accept_all', fullAccept());
      if (t.hasAttribute('data-m-consent-reject'))    return save('reject_all', rejectAll());
      if (t.hasAttribute('data-m-consent-customize')) return openModal();
      if (t.hasAttribute('data-m-consent-save'))      return save('custom', readToggles());
      if (t.hasAttribute('data-m-consent-close'))     return closeModal();
    });

    document.addEventListener('click', function (e) {
      var t = e.target.closest('[data-m-consent-open]');
      if (t) { e.preventDefault(); openModal(); }
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeModal();
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', render);
  } else {
    render();
  }
})();
