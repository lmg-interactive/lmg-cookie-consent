# CIPA Hard Script-Blocking Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Physically prevent hardcoded Google analytics/marketing loader scripts from fetching or executing until the visitor opts in, with zero per-site configuration, shipped via the existing GitHub auto-update.

**Architecture:** A PHP output buffer (`template_redirect`) rewrites the external Google loader `<script>` tags (`gtag/js`, `gtm.js`, `analytics.js`, `ga.js`) to `type="text/plain" data-lmg-consent="<category>"` before the page is sent — so the browser neither fetches nor runs them. `banner.js` reactivates a neutralized loader (by cloning it into a live `<script>`) only when the stored/elected consent grants its category. The blocked state is what gets cached; unblocking is per-visitor client-side, so full-page caching stays safe.

**Tech Stack:** PHP 8.5 (WordPress plugin), vanilla JS, standalone PHP test harness (no PHPUnit — `vendor/` ships to every site), Playwright MCP for integration verification.

## Global Constraints

- Plugin version target: **1.2.0** (bump `Version:` header and `LMG_CONSENT_VERSION` together).
- Preserve back-compat contracts verbatim: cookie/option/table key `m_consent`, events `m_consent:change` + `lmg_consent:change`, shortcodes `[m_cookie_preferences]` + `[lmg_cookie_preferences]`.
- `vendor/` is intentionally committed and shipped — do **not** add Composer dev dependencies there.
- Neutralizer must only **add** attributes / flip `type`; never reorder or strip markup.
- Override constants (front-end behavior needs none by default):
  - `LMG_CONSENT_BLOCKING` — `false` disables the buffer (kill switch). Default on.
  - `LMG_CONSENT_EXTRA_PATTERNS` — array of `[ 'regex' => string, 'category' => string ]` added to the matcher.
- Category mapping: `gtag/js`, `analytics.js`, `ga.js` → `analytics`; `gtm.js` → `marketing`.
- `data-lmg-consent` category values use the consent-state keys directly (`analytics`, `marketing`, `functional`, `necessary`).

---

### Task 1: Neutralizer core (pure PHP transform)

**Files:**
- Create: `includes/class-lmg-consent-blocker.php`
- Create: `tests/bootstrap.php`
- Create: `tests/test-neutralizer.php`

**Interfaces:**
- Produces:
  - `LMG_Consent_Blocker::neutralize_html( string $html, array $patterns ) : string`
  - `LMG_Consent_Blocker::is_html_document( string $html ) : bool`
  - `LMG_Consent_Blocker::default_patterns() : array` — list of `[ 'regex' => string, 'category' => string ]`
- Consumes: nothing (pure; no WordPress functions in these three methods).

- [ ] **Step 1: Write the failing test harness + tests**

Create `tests/bootstrap.php`:

```php
<?php
// Zero-dependency assertion harness (no PHPUnit — vendor/ ships to all sites).
$GLOBALS['__tests'] = 0;
$GLOBALS['__fails'] = 0;

function it( $name, callable $fn ) {
    try {
        $fn();
        echo "  ok  - $name\n";
    } catch ( \Throwable $e ) {
        $GLOBALS['__fails']++;
        echo "  FAIL- $name\n        " . $e->getMessage() . "\n";
    }
    $GLOBALS['__tests']++;
}

function assert_true( $cond, $msg = 'expected true' ) {
    if ( ! $cond ) { throw new \Exception( $msg ); }
}
function assert_contains( $needle, $haystack, $msg = '' ) {
    if ( strpos( $haystack, $needle ) === false ) {
        throw new \Exception( $msg ?: "expected to contain: $needle\n        got: $haystack" );
    }
}
function assert_not_contains( $needle, $haystack, $msg = '' ) {
    if ( strpos( $haystack, $needle ) !== false ) {
        throw new \Exception( $msg ?: "expected NOT to contain: $needle\n        got: $haystack" );
    }
}
function assert_eq( $expected, $actual, $msg = '' ) {
    if ( $expected !== $actual ) {
        throw new \Exception( $msg ?: "expected [$expected] got [$actual]" );
    }
}

function done() {
    echo "\n{$GLOBALS['__tests']} tests, {$GLOBALS['__fails']} failures\n";
    exit( $GLOBALS['__fails'] > 0 ? 1 : 0 );
}
```

Create `tests/test-neutralizer.php`:

```php
<?php
require __DIR__ . '/bootstrap.php';

// Load the class without WordPress. ABSPATH guard would exit; define it.
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../includes/class-lmg-consent-blocker.php';

$P = LMG_Consent_Blocker::default_patterns();
function neut( $html ) {
    return LMG_Consent_Blocker::neutralize_html( $html, LMG_Consent_Blocker::default_patterns() );
}

$doc = "<!doctype html><html><head>%s</head><body></body></html>";

it( 'neutralizes a gtag.js loader as analytics', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"></script>' );
    $out = neut( $in );
    assert_contains( 'type="text/plain"', $out );
    assert_contains( 'data-lmg-consent="analytics"', $out );
    assert_contains( 'src="https://www.googletagmanager.com/gtag/js?id=G-ABC123"', $out );
} );

it( 'neutralizes a gtm.js container as marketing', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-XYZ"></script>' );
    $out = neut( $in );
    assert_contains( 'data-lmg-consent="marketing"', $out );
} );

it( 'neutralizes legacy analytics.js as analytics', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script src="https://www.google-analytics.com/analytics.js"></script>' );
    assert_contains( 'data-lmg-consent="analytics"', neut( $in ) );
} );

it( 'replaces an existing type attribute (text/plain wins)', function () use ( $doc ) {
    $in  = sprintf( $doc, '<script type="text/javascript" src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' );
    $out = neut( $in );
    assert_contains( 'type="text/plain"', $out );
    assert_not_contains( 'type="text/javascript"', $out );
} );

it( 'is idempotent — does not double-process', function () use ( $doc ) {
    $in   = sprintf( $doc, '<script async src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' );
    $once = neut( $in );
    $twice= neut( $once );
    assert_eq( $once, $twice );
} );

it( 'leaves inline gtag config untouched', function () use ( $doc ) {
    $inline = '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)}gtag("config","G-1");</script>';
    $out = neut( sprintf( $doc, $inline ) );
    assert_not_contains( 'data-lmg-consent', $out );
    assert_contains( 'gtag("config","G-1")', $out );
} );

it( 'leaves unrelated external scripts untouched', function () use ( $doc ) {
    $out = neut( sprintf( $doc, '<script src="https://cdn.example.com/app.js"></script>' ) );
    assert_not_contains( 'data-lmg-consent', $out );
} );

it( 'neutralizes multiple loaders on one page', function () use ( $doc ) {
    $in  = sprintf( $doc,
        '<script src="https://www.googletagmanager.com/gtag/js?id=G-1"></script>' .
        '<script src="https://www.googletagmanager.com/gtm.js?id=GTM-2"></script>' );
    $out = neut( $in );
    assert_eq( 2, substr_count( $out, 'data-lmg-consent' ) );
} );

it( 'returns non-HTML input unchanged', function () {
    $json = '{"foo":"https://www.googletagmanager.com/gtag/js?id=G-1"}';
    assert_eq( $json, neut( $json ) );
} );

it( 'handles single-quoted src', function () use ( $doc ) {
    $in  = sprintf( $doc, "<script async src='https://www.googletagmanager.com/gtag/js?id=G-Q'></script>" );
    assert_contains( 'data-lmg-consent="analytics"', neut( $in ) );
} );

done();
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php tests/test-neutralizer.php`
Expected: FATAL — `require(): Failed opening required '.../includes/class-lmg-consent-blocker.php'` (implementation file not created yet), so the script aborts before any test runs.

- [ ] **Step 3: Write the minimal implementation**

Create `includes/class-lmg-consent-blocker.php`:

```php
<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * CIPA hard-blocking: neutralize external Google loader scripts before output
 * so the browser neither fetches nor executes them until the visitor opts in.
 * banner.js reactivates a neutralized loader when its category is granted.
 */
final class LMG_Consent_Blocker {

    /**
     * Category-mapped loader patterns. Each regex is tested against a <script src>.
     * @return array<int, array{regex:string, category:string}>
     */
    public static function default_patterns() {
        $patterns = [
            [ 'regex' => '~googletagmanager\.com/gtag/js~i',       'category' => 'analytics' ],
            [ 'regex' => '~googletagmanager\.com/gtm\.js~i',       'category' => 'marketing' ],
            [ 'regex' => '~google-analytics\.com/analytics\.js~i', 'category' => 'analytics' ],
            [ 'regex' => '~google-analytics\.com/ga\.js~i',        'category' => 'analytics' ],
        ];
        if ( defined( 'LMG_CONSENT_EXTRA_PATTERNS' ) && is_array( LMG_CONSENT_EXTRA_PATTERNS ) ) {
            foreach ( (array) LMG_CONSENT_EXTRA_PATTERNS as $p ) {
                if ( ! empty( $p['regex'] ) ) {
                    $patterns[] = [
                        'regex'    => $p['regex'],
                        'category' => isset( $p['category'] ) ? $p['category'] : 'marketing',
                    ];
                }
            }
        }
        return $patterns;
    }

    /** Cheap sniff so we never touch non-HTML responses. */
    public static function is_html_document( $html ) {
        return ( stripos( $html, '<!doctype html' ) !== false )
            || ( stripos( $html, '<html' ) !== false );
    }

    /**
     * Pure transform. Rewrites matching external loader <script> opening tags to
     * type="text/plain" + data-lmg-consent="<category>"; everything else passes
     * through untouched. Idempotent.
     */
    public static function neutralize_html( $html, array $patterns ) {
        if ( '' === $html || ! self::is_html_document( $html ) ) {
            return $html;
        }
        return preg_replace_callback(
            '~<script\b[^>]*>~i',
            function ( $m ) use ( $patterns ) {
                $tag = $m[0];

                // Idempotent: already neutralized.
                if ( stripos( $tag, 'data-lmg-consent' ) !== false ) {
                    return $tag;
                }
                // Needs an external src to be a loader.
                if ( ! preg_match( '~\ssrc\s*=\s*("|\')(.*?)\1~i', $tag, $src ) ) {
                    return $tag;
                }
                $url      = $src[2];
                $category = null;
                foreach ( $patterns as $p ) {
                    if ( preg_match( $p['regex'], $url ) ) {
                        $category = $p['category'];
                        break;
                    }
                }
                if ( null === $category ) {
                    return $tag;
                }

                // Strip any existing type, then inject ours first (first attr wins per HTML).
                $new = preg_replace( '~\stype\s*=\s*("|\').*?\1~i', '', $tag );
                $new = preg_replace(
                    '~<script\b~i',
                    '<script type="text/plain" data-lmg-consent="' . $category . '"',
                    $new,
                    1
                );
                return $new;
            },
            $html
        );
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php tests/test-neutralizer.php`
Expected: `11 tests, 0 failures`

- [ ] **Step 5: Commit**

```bash
git add includes/class-lmg-consent-blocker.php tests/bootstrap.php tests/test-neutralizer.php
git commit -m "feat: neutralizer core for CIPA script-blocking (Google loaders)"
```

---

### Task 2: WordPress wiring (output buffer + guards + plugin load)

**Files:**
- Modify: `includes/class-lmg-consent-blocker.php` (add hook/guard methods)
- Modify: `lmg-consent.php:22` (version constant) and `:5` (header), `:27-45` (require + init)
- Test: `tests/test-neutralizer.php` (add guard tests)

**Interfaces:**
- Consumes: `LMG_Consent_Blocker::neutralize_html()`, `default_patterns()` (Task 1).
- Produces:
  - `LMG_Consent_Blocker::instance() : LMG_Consent_Blocker`
  - `LMG_Consent_Blocker::blocking_enabled() : bool`
  - `LMG_Consent_Blocker::maybe_start_buffer() : void` (hooked to `template_redirect`)
  - `LMG_Consent_Blocker::ob_callback( string $buffer ) : string`

- [ ] **Step 1: Write the failing guard test**

Append to `tests/test-neutralizer.php` (before `done();`):

```php
it( 'blocking_enabled defaults true', function () {
    assert_true( LMG_Consent_Blocker::blocking_enabled() === true );
} );
```

- [ ] **Step 2: Run to verify it fails**

Run: `php tests/test-neutralizer.php`
Expected: the new test reports `FAIL- blocking_enabled defaults true` with `Call to undefined method LMG_Consent_Blocker::blocking_enabled()` (the `it()` harness catches the Error, so the run completes and exits 1 with 1 failure).

- [ ] **Step 3: Add the wiring methods**

Add to `class-lmg-consent-blocker.php` inside the class (after `neutralize_html`):

```php
    /* ------------------------------------------------------------------
     * WordPress wiring
     * ------------------------------------------------------------------ */

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'template_redirect', [ $this, 'maybe_start_buffer' ], 0 );
    }

    public static function blocking_enabled() {
        if ( defined( 'LMG_CONSENT_BLOCKING' ) && ! LMG_CONSENT_BLOCKING ) {
            return false;
        }
        return true;
    }

    /** Start a full-page buffer only on normal front-end HTML requests. */
    public function maybe_start_buffer() {
        if ( ! self::blocking_enabled() ) { return; }
        if ( is_admin() || is_feed() || is_embed() ) { return; }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) { return; }
        if ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) { return; }
        if ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() ) { return; }
        ob_start( [ __CLASS__, 'ob_callback' ] );
    }

    /** Buffer callback: skip non-HTML responses, else neutralize. */
    public static function ob_callback( $buffer ) {
        foreach ( headers_list() as $h ) {
            if ( stripos( $h, 'content-type:' ) === 0 && stripos( $h, 'text/html' ) === false ) {
                return $buffer; // e.g. application/json, image/*, xml
            }
        }
        return self::neutralize_html( $buffer, self::default_patterns() );
    }
```

> Note: `instance()`/`__construct()` add an `add_action` call, so the standalone
> test must stub it. Add this near the top of `tests/test-neutralizer.php`,
> immediately after `define( 'ABSPATH', __DIR__ );`:
>
> ```php
> if ( ! function_exists( 'add_action' ) ) { function add_action() {} }
> ```
>
> The guard test only calls the static `blocking_enabled()`, so no WP runtime is
> needed; the stub just lets the file load if a future test news up the class.

- [ ] **Step 4: Run to verify it passes**

Run: `php tests/test-neutralizer.php`
Expected: `12 tests, 0 failures`

- [ ] **Step 5: Wire into the plugin and bump version**

In `lmg-consent.php`, change the header version (line 5) `Version: 1.1.1` → `Version: 1.2.0`.

Change line 22:
```php
define( 'LMG_CONSENT_VERSION', '1.2.0' );
```

After line 28 (`require_once … class-lmg-consent-log.php;`) add:
```php
require_once LMG_CONSENT_PATH . 'includes/class-lmg-consent-blocker.php';
```

Inside the `plugins_loaded` closure (after `LMG_Consent::instance();`, around line 37) add:
```php
    LMG_Consent_Blocker::instance();
```

- [ ] **Step 6: Lint the changed PHP**

Run: `php -l includes/class-lmg-consent-blocker.php && php -l lmg-consent.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 7: Commit**

```bash
git add includes/class-lmg-consent-blocker.php lmg-consent.php tests/test-neutralizer.php
git commit -m "feat: wire output-buffer blocker into plugin (v1.2.0)"
```

---

### Task 3: Client-side reactivation after consent

**Files:**
- Modify: `assets/js/banner.js` (add `activateBlockedScripts`; call in `render()` + `save()`)

**Interfaces:**
- Consumes: neutralized DOM nodes `script[type="text/plain"][data-lmg-consent]` (Task 1/2 output); the consent-state `categories` map (existing).
- Produces: `activateBlockedScripts(categories)` — clones each granted, not-yet-activated neutralized loader into a live `<script>` so the browser executes it. Idempotent via `data-lmg-activated="1"`.

- [ ] **Step 1: Add the reactivation function**

In `assets/js/banner.js`, after the `pushConsent` function (ends at the line with `window.gtag('consent', 'update', update);` `}`), add:

```js
  /* ---------- reactivate CIPA-blocked loaders ---------- */
  // Server-side, LMG_Consent_Blocker rewrote Google loader <script> tags to
  // type="text/plain" so they neither fetched nor ran. Once the matching
  // category is granted, clone each into a live <script> to execute it.
  // Changing an existing script's type does NOT run it — a fresh node must be
  // inserted (HTML spec: "already started" scripts never re-execute).
  function activateBlockedScripts(categories) {
    if (!categories) return;
    var blocked = document.querySelectorAll('script[type="text/plain"][data-lmg-consent]');
    for (var i = 0; i < blocked.length; i++) {
      var old = blocked[i];
      var cat = old.getAttribute('data-lmg-consent');
      if (!categories[cat]) continue;                       // not granted
      if (old.getAttribute('data-lmg-activated') === '1') continue; // idempotent
      old.setAttribute('data-lmg-activated', '1');

      var s = document.createElement('script');
      for (var a = 0; a < old.attributes.length; a++) {
        var attr = old.attributes[a];
        if (attr.name === 'type' ||
            attr.name === 'data-lmg-consent' ||
            attr.name === 'data-lmg-activated') continue;
        s.setAttribute(attr.name, attr.value);
      }
      s.type = 'text/javascript';
      if (old.textContent) s.text = old.textContent;
      (old.parentNode || document.head || document.documentElement).insertBefore(s, old);
    }
  }
```

- [ ] **Step 2: Call it when stored consent already grants (page load)**

In `render()`, the existing-state branch currently reads:

```js
    } else {
      // Already decided — keep hidden but apply stored choices to gtag.
      pushConsent(state.categories);
    }
```

Change to:

```js
    } else {
      // Already decided — keep hidden but apply stored choices to gtag and
      // reactivate any server-blocked loaders the visitor previously allowed.
      pushConsent(state.categories);
      activateBlockedScripts(state.categories);
    }
```

- [ ] **Step 3: Call it on a fresh decision**

In `save()`, after the `pushConsent(categories);` line, add:

```js
    activateBlockedScripts(categories);
```

- [ ] **Step 4: Syntax-check the JS**

Run: `node --check assets/js/banner.js`
Expected: no output, exit 0.

- [ ] **Step 5: Commit**

```bash
git add assets/js/banner.js
git commit -m "feat: reactivate CIPA-blocked loaders once consent granted"
```

---

### Task 4: Integration verification + pre-release ship

**Files:**
- Create: `docs/superpowers/verification-cipa.md` (the repeatable checklist)

This task verifies the end-to-end behavior against a real pilot site, using the
Playwright MCP. It requires a **pilot site URL** running the updated plugin (one
Genesis-hooks site, one Elementor-custom-code site, ideally one with a page
cache). It produces no application code — it gates the stable release.

- [ ] **Step 1: Write the verification checklist doc**

Create `docs/superpowers/verification-cipa.md`:

```markdown
# CIPA Blocking — Per-Site Verification

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
1. Click "Accept all".
2. ASSERT a request to `googletagmanager.com/gtag/js` (and/or `/collect`) now fires.
3. Confirm a live `<script>` with the real `src` was inserted
   (`data-lmg-activated="1"` on the neutralized node).

## C. Reject path
1. Fresh context. Click "Reject non-essential".
2. ASSERT no Google loader request fires, before OR after the click.

## D. Returning visitor
1. With an "accept" cookie set, reload.
2. ASSERT the loader fires on load (reactivated from stored consent), banner hidden.
```

- [ ] **Step 2: Run the Playwright network assertion against a pilot (pre-consent)**

Using the Playwright MCP against the pilot URL:
- `browser_navigate` to the pilot page.
- `browser_network_requests` and filter for `googletagmanager.com` / `google-analytics.com`.

Expected: **no** matching requests before any banner interaction.

- [ ] **Step 3: Run the post-consent assertion**

- `browser_click` the "Accept all" control (`[data-m-consent-accept]`).
- `browser_network_requests` again.

Expected: a `googletagmanager.com/gtag/js` (or `/collect`) request now present.

- [ ] **Step 4: Run reject + returning-visitor checks (steps C & D above)**

Expected: reject → never fires; returning "accept" visitor → fires on load.

- [ ] **Step 5: Commit the checklist and ship a pre-release**

```bash
git add docs/superpowers/verification-cipa.md
git commit -m "docs: CIPA per-site verification checklist"
git push origin main
```

Then on GitHub: draft a **pre-release** tag `1.2.0-rc.1` for pilot sites (the
updater ignores pre-releases for the fleet). After pilots pass A–D, publish the
stable `1.2.0` release to roll out to all sites.

---

## Notes on test strategy

- The PHP neutralizer is the real risk surface (regex correctness, idempotency,
  edge cases) and is fully unit-tested standalone (`php tests/test-neutralizer.php`).
- The JS reactivation is inseparable from the DOM + network, so its meaningful
  test is the Playwright integration pass in Task 4 — which is also the spec's
  required per-site verification. There is no node DOM unit harness because
  `banner.js` is a browser IIFE that references `window` at load; faking that to
  unit-test the clone logic would test the fake, not the behavior that matters
  (does a real request fire pre/post consent).
```
