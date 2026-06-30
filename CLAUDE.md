# LMG Consent — project cheat sheet

WordPress cookie-consent plugin, reused across hundreds of sites. Ships updates
via GitHub (Plugin Update Checker in `vendor/`, committed — no Composer on sites).

## Layout
- `lmg-consent.php` — bootstrap: constants, requires, `plugins_loaded` init, `lmg_consent_given()`.
- `includes/class-lmg-consent.php` — main class: Consent Mode v2 defaults (`wp_head` pri 1), asset enqueue, banner render, REST log endpoint, purge cron, shortcodes.
- `includes/class-lmg-consent-blocker.php` — CIPA hard-blocking (see below).
- `includes/class-lmg-consent-log.php` / `-admin.php` / `-updater.php`.
- `assets/js/banner.js` — client consent logic + blocked-loader reactivation.
- `templates/banner.php` — banner/modal markup.

## Back-compat contracts (DO NOT rename)
Storage key/cookie/option/table `m_consent` / `m_consent_settings` / `{prefix}m_consent_log`;
events `m_consent:change` + `lmg_consent:change`; shortcodes `[m_cookie_preferences]` + `[lmg_cookie_preferences]`.

## CIPA hard-blocking (v1.2.0; pixels added v1.2.1)
Consent Mode v2 alone does NOT satisfy CIPA: GA4 still fires cookieless pings
(transmits IP/URL/UA) before opt-in. So `LMG_Consent_Blocker` output-buffers the
page (`template_redirect`) and rewrites the **external tracking loader** scripts
to `type="text/plain" data-lmg-consent="<category>"` — browser neither fetches
nor runs them. Inline init (`gtag('config',…)`, `fbq(…)`, etc.) is left inert
(no loader = no transmission). `banner.js` `activateBlockedScripts()` clones each
neutralized loader into a live `<script>` when its category is granted (on
decision + on load for returning visitors; idempotent via `data-lmg-activated`).
Blocked state is what gets cached → cache-safe; unblocking is per-visitor JS.
- Category map: gtag/analytics.js/ga.js → `analytics`; gtm.js + all marketing
  pixels → `marketing`.
- Marketing pixels matched (v1.2.1): Meta `fbevents.js`, LinkedIn
  `insight.min.js`, TikTok `events.js`, Bing UET `bat.js`, Pinterest `core.js`,
  X/Twitter `uwt.js`. GTM-loaded pixels are covered by the `gtm.js` block.
- Kill switch: `define('LMG_CONSENT_BLOCKING', false)`.
- Extra loaders: `define('LMG_CONSENT_EXTRA_PATTERNS', [['regex'=>'~…~i','category'=>'analytics']])`.

## Commands
- PHP unit tests (no PHPUnit; standalone harness): `php tests/test-neutralizer.php`
- Lint: `php -l <file>` ; JS syntax: `node --check assets/js/banner.js`
- Per-site browser verification checklist: `docs/superpowers/verification-cipa.md`

## Gotchas
- `vendor/` is intentionally committed and shipped — never add Composer dev deps there.
- `?>` closes PHP even inside a `//` comment (bit us building a test fixture).
- Changing a script's `type` does NOT execute it — must insert a fresh `<script>` node.
- Updater ignores GitHub pre-releases → use `x.y.z-rc.N` tags for pilots, then publish stable.

## Specs / plans
`docs/superpowers/specs/2026-06-30-cipa-script-blocking-design.md`,
`docs/superpowers/plans/2026-06-30-cipa-script-blocking.md`.
