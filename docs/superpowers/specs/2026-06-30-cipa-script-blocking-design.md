# LMG Consent — CIPA Hard Script-Blocking

**Date:** 2026-06-30
**Status:** Approved design — ready for implementation plan
**Plugin version target:** 1.2.0

## Problem

LMG Consent is expected to align with CIPA (California Invasion of Privacy
Act) standards, but GA4 tags fire and transmit visitor data **before** the
visitor consents.

### Root cause

The plugin's only data-gating mechanism is **Google Consent Mode v2**:

- `LMG_Consent::print_consent_defaults()` (`includes/class-lmg-consent.php`)
  emits `gtag('consent','default', { … 'denied' })` in `wp_head` at priority 1.
- `banner.js` `pushConsent()` emits `gtag('consent','update', …)` after a
  decision.

Consent Mode v2 is **not** a blocking mechanism. With `analytics_storage`
denied, GA4 / `gtag.js` still loads and fires on page load — it just switches
to **cookieless pings**, which still transmit the visitor's IP, page URL,
referrer, user agent, and screen data to Google's collect endpoint before the
visitor touches the banner.

That model is broadly accepted for GDPR. CIPA's wiretapping theory treats *any*
transmission of a visitor's data to a third party without **prior** consent as
an unlawful interception, so a cookieless ping is still exposure. **Consent
Mode v2 alone cannot satisfy a "no transmission until opt-in" standard.**

Two compounding facts in the current codebase:

1. The plugin never touches the actual GA4 tag — no `script_loader_tag` filter,
   no `type="text/plain"` rewriting, no `gtag.js` loader. It only emits consent
   *signals*.
2. Even if it could reach the tag, Consent Mode's `denied` state would not stop
   the network ping.

So "GA4 fires before the plugin blocks transmission" is expected behavior of the
current (GDPR/Consent-Mode) architecture, not a code bug.

## Constraints

- **Scale:** hundreds of sites. Any per-site manual migration step is off the
  table. The fix must be **zero-touch** and deploy via the existing GitHub
  auto-update channel.
- **Tag injection methods in the field:**
  - Hardcoded `gtag.js` snippets via **Genesis Simple Hooks**.
  - Hardcoded `gtag.js` snippets via **Elementor custom code block**.
  - Both render as raw `<script>` tags in the final page HTML; neither is
    `wp_enqueue`'d.
- **Page caching** (WP Rocket / host cache / Cloudflare) is present on some
  sites and must not be broken.
- Back-compat contracts (`m_consent` cookie/option/table, `m_consent:change`
  event, `[m_cookie_preferences]` shortcode) must be preserved.

## Key insight

GA4 transmits nothing until the **external Google loader** executes
(`googletagmanager.com/gtag/js?id=…`, `gtm.js`, `google-analytics.com/...`).
The inline `gtag('config', …)` / `dataLayer.push` calls in a snippet are inert
on their own — they push onto `dataLayer`, which is harmless until the library
loads and reads it. The plugin already defines `gtag`/`dataLayer` early, so
queued calls simply wait.

Therefore the entire CIPA exposure reduces to **one small, well-defined
target**: the external Google loader `<script src>`. We neutralize that, not
arbitrary inline code — which is what makes this far less fragile than a
generic page-wide pattern blocker.

## Architecture

### 1. Server side (PHP) — neutralize before output

- Start a full-page output buffer early (`template_redirect`); process the
  complete HTML on flush.
- In the buffer, find **Google loader scripts only**:
  - `googletagmanager.com/gtag/js`
  - `googletagmanager.com/gtm.js`
  - `google-analytics.com/analytics.js`
  - `google-analytics.com/ga.js`
- Rewrite each matched `<script>`:
  - `type="…"` → `type="text/plain"` (add `type` if absent)
  - add `data-lmg-consent="analytics"` (or `marketing` for ad / GTM container
    tags — see Category mapping)
  - preserve the original `src` in `data-lmg-src` so JS can restore it
- Leave inline `gtag('config', …)` / `dataLayer.push` snippets as-is — inert
  without the loader, and absorbed by the existing early `gtag`/`dataLayer`
  stub.

### 2. Client side (banner.js) — reactivate after opt-in

- On a granted decision, **and** on page load when the stored cookie already
  grants the category, find each
  `script[type="text/plain"][data-lmg-consent]` whose category is granted,
  clone it as a live `<script>` with the real `type` + `src` (from
  `data-lmg-src`), and insert it so the browser executes it. Queued `dataLayer`
  calls then process normally.
- If the category is denied, the loader is never reactivated → no transmission.

**Net effect:** the request to Google cannot fire until the visitor opts in, on
every site, with zero per-site work, riding the existing GitHub auto-update.

### Category mapping

- `gtag/js` and `analytics.js` / `ga.js` → `analytics`.
- `gtm.js` (GTM container) → `marketing` by default, because a container can
  load ad/marketing tags; blocking until the broadest consent category is the
  CIPA-safe choice. (Revisit per-site via override if a container is
  analytics-only.)

### Cache safety

The *blocked* state is what renders and therefore what gets cached. Reactivation
is per-visitor client-side JS keyed off the consent cookie. Full-page caching is
safe by default: every cached page ships neutralized; each browser unblocks only
if its cookie grants the category.

## Safety provisions

### Override constants (`wp-config.php`; defaults need none)

- `LMG_CONSENT_BLOCKING` — set `false` to disable the buffer entirely on a
  problem site (kill switch). Default: on.
- `LMG_CONSENT_EXTRA_PATTERNS` — additional loader hosts/paths to neutralize on
  a site with a non-standard tag.

### Buffer guards

- Run only on normal front-end HTML pages. Skip: admin, AJAX, REST, cron,
  feeds, `wp-login`, sitemaps, and any response whose `Content-Type` is not
  `text/html`.
- Only ever *add* attributes and flip `type`; never reorder or strip markup, so
  coexistence with other output optimizers stays safe.
- If the matcher finds nothing, the page passes through untouched (cost is one
  `preg_match`).

### Failure mode

Matching targets the external loader URL, not inline code. The only failure mode
is an unrecognized loader variant — which fails **visible** (a tag still fires
and is caught by the verification pass) rather than **silent breakage**.

## Rollout

1. **Pilot:** ship to 2–3 representative sites first — one Genesis-hooks site,
   one Elementor-custom-code site, one with a page cache. Verify, then widen.
2. **Staged release:** all sites pull from the same GitHub release, so staging =
   tag a **pre-release** for pilots, publish the **stable** release once
   verified. The updater already ignores pre-releases (README).
3. **Verification pass:** per site, load a fresh (no-cookie) page and confirm
   **no** request to `google-analytics.com` / `googletagmanager.com/gtag/js`
   fires before clicking Accept; then confirm it *does* fire after. Automated
   with the Playwright MCP across a sample.

## Testing

- **PHP (string-in/string-out, no WordPress needed):** feed sample HTML blobs
  through the neutralizer and assert correct rewrite / no-op:
  - `gtag.js` async snippet (typical Genesis / Elementor output)
  - `gtm.js` container snippet
  - `analytics.js` / `ga.js` legacy
  - already-`text/plain` script (idempotent — no double-processing)
  - page with no tracking tag (untouched)
  - non-HTML response (skipped)
  - multiple loaders on one page (all neutralized)
- **JS:** simulate granted / denied cookie states; assert the loader is
  (re)injected only when granted, and exactly once.
- **Integration:** Playwright network assertions as in the verification pass.

## Out of scope (v1)

- Non-Google vendor pixels (Meta, LinkedIn, TikTok). The `data-lmg-consent`
  attribute mechanism is extensible to them later; v1 targets the Google loaders
  that are the actual deployed stack.
- A `data-lmg-consent` manual-tagging escape hatch for admins is a possible
  follow-up but not required for the zero-touch Google-loader fix.
- Server-side GA (Measurement Protocol) — not present in the deployed stack.

## Files affected

- `includes/class-lmg-consent.php` — add output-buffer hook + neutralizer, or a
  new dedicated `includes/class-lmg-consent-blocker.php` for isolation.
- `assets/js/banner.js` — add reactivation of neutralized loaders on
  grant / on-load.
- `lmg-consent.php` — version bump to 1.2.0; wire the blocker class if split out.
- Tests under a new `tests/` directory.
