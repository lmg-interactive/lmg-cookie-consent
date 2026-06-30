# CIPA Blocking — Per-Site Verification

The neutralizer + reactivation were verified end-to-end in a real browser
(Playwright) against a constructed fixture before release: pre-consent the Google
loader stays `type="text/plain"` and never executes; on Accept it reactivates
exactly once; Reject never activates it; a returning "accept" visitor reactivates
it on load. Use the checklist below to confirm the same on each live pilot site,
where the real loaders and any page cache are in play.

For each pilot site URL, with a FRESH browser context (no consent cookie):

## A. Pre-consent: no transmission
1. Navigate to the page.
2. Capture network requests.
3. ASSERT zero requests to:
   - `www.googletagmanager.com/gtag/js`
   - `www.googletagmanager.com/gtm.js`
   - `www.google-analytics.com/` (collect / g/collect / analytics.js / ga.js)
4. Confirm in DOM: the loader `<script>` is present but `type="text/plain"`
   with `data-lmg-consent`.

## B. Post-consent: transmission resumes
1. Click "Accept all" (`[data-m-consent-accept]`).
2. ASSERT a request to `googletagmanager.com/gtag/js` (and/or `/collect`) now fires.
3. Confirm a live `<script>` with the real `src` was inserted
   (`data-lmg-activated="1"` on the neutralized node).

## C. Reject path
1. Fresh context. Click "Reject non-essential" (`[data-m-consent-reject]`).
2. ASSERT no Google loader request fires, before OR after the click.

## D. Returning visitor
1. With an "accept" cookie set, reload.
2. ASSERT the loader fires on load (reactivated from stored consent), banner hidden.

## Pilot mix
Run A–D against, at minimum:
- one site that injects GA via **Genesis Simple Hooks**,
- one site that injects GA via the **Elementor custom code block**,
- one site behind a **page cache** (WP Rocket / host cache / Cloudflare).

## Rollout
- Tag a **pre-release** (e.g. `1.2.0-rc.1`) for pilots; the updater ignores
  pre-releases for the fleet.
- After A–D pass on the pilot mix, publish the stable **1.2.0** release to roll
  out to all sites.
- Per-site escape hatch if a site misbehaves: `define( 'LMG_CONSENT_BLOCKING', false );`
  in `wp-config.php`.
- Non-standard loader on a site: add it via
  `define( 'LMG_CONSENT_EXTRA_PATTERNS', [ [ 'regex' => '~…~i', 'category' => 'analytics' ] ] );`.
