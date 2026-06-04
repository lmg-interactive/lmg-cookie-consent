# LMG Consent

Lightweight GDPR/CPRA cookie-consent banner with Google Consent Mode v2 and
Global Privacy Control (GPC) support. Designed to be reused across multiple
sites.

## GitHub-based updates

Every site running this plugin checks GitHub for new releases and shows
updates under **Plugins → Updates** (one-click update, or enable auto-update).
Powered by the vendored [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
(`vendor/plugin-update-checker/`, committed to the repo — no Composer needed).

### One-time GitHub setup

1. **Create the repo.** Put the plugin at the **repo root** (so `lmg-consent.php`
   is at the top level of the repo), and commit everything **including the
   `vendor/` folder**.
   - Repo the plugin expects (public): **`lmg-interactive/lmg-cookie-consent`**.
   - Using a different repo? Set it per site (see below) — no code change.

2. **(Private repo only) Create a token.** A GitHub Personal Access Token with
   read access to the repo (classic: `repo` scope; fine-grained: *Contents:
   Read*). Public repos need no token.

### Per-site configuration

Default public repo `lmg-interactive/lmg-cookie-consent`: **nothing to
configure** — it works out of the box.

Otherwise, add to that site's `wp-config.php` (above `/* That's all … */`):

```php
// Only if your repo differs from the default:
define( 'LMG_CONSENT_GH_REPO', 'YourOrg/your-repo' );

// Required for PRIVATE repos (also dodges GitHub's anonymous rate limit):
define( 'LMG_CONSENT_GH_TOKEN', 'ghp_xxxxxxxxxxxxxxxxxxxx' );
```

> The token lives in `wp-config.php`, **never** in the repo. Each site can use
> its own token.

### Shipping an update (do this for every new version)

1. Bump the `Version:` header in `lmg-consent.php` (e.g. `1.1.0` → `1.1.1`).
2. Commit and push to the default branch.
3. On GitHub: **Releases → Draft a new release**, create a tag that matches the
   new version (e.g. `1.1.1` or `v1.1.1`), publish it. The auto-generated
   source zip is sufficient — no need to attach a build.

Within ~12 hours every site shows the update. To pull it immediately on a site:
**Dashboard → Updates → Check again**, or **Plugins** → *Check for updates*
(link added by the update checker under the plugin row).

### Notes

- Updates track the latest **stable Release** (pre-releases are ignored), so
  in-progress commits on the branch never ship.
- The update zip lands in the correct `lmg-consent/` folder automatically even
  though GitHub's source zip uses a different internal folder name.
- Storage keys (`m_consent` cookie, `m_consent_settings` option,
  `m_consent_log` table), the `[m_cookie_preferences]` shortcode and the
  `m_consent:change` JS event are retained from the pre-rebrand "M Consent"
  for back-compat; `lmg_*` aliases exist for all of them.
