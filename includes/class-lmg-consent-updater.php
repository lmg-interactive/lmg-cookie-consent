<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * GitHub-based plugin updates via the vendored Plugin Update Checker (PUC).
 *
 * This makes every site running LMG Consent see updates under
 * Plugins → Updates whenever a newer GitHub Release is published — no manual
 * file copying per site.
 *
 * ── Per-site configuration (optional; add to wp-config.php) ───────────────
 *   define( 'LMG_CONSENT_GH_REPO',  'OWNER/REPO' ); // override the default repo
 *   define( 'LMG_CONSENT_GH_TOKEN', 'ghp_xxx' );    // REQUIRED for a PRIVATE repo
 *
 * If the repo is public you can skip the token (a token is still recommended
 * to avoid GitHub's low anonymous API rate limit on busy hosts).
 *
 * ── Release flow (do this once per new version) ───────────────────────────
 *   1. Bump the "Version:" header in lmg-consent.php.
 *   2. Commit + push to the default branch.
 *   3. Publish a GitHub Release whose tag matches that version
 *      (e.g. 1.1.2 or v1.1.2). The auto-generated source zip is fine.
 *   Sites pick it up within ~12h, or immediately via "Check for updates".
 */
final class LMG_Consent_Updater {

    /** Default repository, overridable via the LMG_CONSENT_GH_REPO constant. */
    const DEFAULT_REPO = 'lmg-interactive/lmg-cookie-consent';

    public static function init() {
        $entry = LMG_CONSENT_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
        if ( ! file_exists( $entry ) ) {
            return;
        }
        require_once $entry;

        // PUC v5 factory (fully-qualified so no `use` import is needed here).
        $factory = 'YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory';
        if ( ! class_exists( $factory ) ) {
            return;
        }

        $repo = ( defined( 'LMG_CONSENT_GH_REPO' ) && LMG_CONSENT_GH_REPO )
            ? LMG_CONSENT_GH_REPO
            : self::DEFAULT_REPO;

        $checker = call_user_func(
            [ $factory, 'buildUpdateChecker' ],
            'https://github.com/' . trim( (string) $repo, '/' ) . '/',
            LMG_CONSENT_FILE,
            'lmg-consent' // slug — also forces the installed folder name on update
        );

        // Private repos (and to avoid GitHub's anonymous rate limit): token.
        if ( defined( 'LMG_CONSENT_GH_TOKEN' ) && LMG_CONSENT_GH_TOKEN ) {
            $checker->setAuthentication( LMG_CONSENT_GH_TOKEN );
        }

        // No setBranch() on purpose: with releases present, PUC tracks the
        // latest stable GitHub Release (ignoring pre-releases), so in-progress
        // commits on the default branch never ship as updates.
    }
}
