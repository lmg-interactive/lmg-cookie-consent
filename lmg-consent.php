<?php
/**
 * Plugin Name: LMG Consent
 * Description: Lightweight, GDPR/CPRA-ready cookie consent banner with Google Consent Mode v2 and Global Privacy Control (GPC) support. Designed to be reused across multiple sites; default palette matches M Financial Group but all colors are themeable via CSS variables.
 * Version: 1.2.1
 * Author: LawtonMG
 * Text Domain: lmg-consent
 * Requires PHP: 7.4
 *
 * Rebranded from "M Consent". Internal storage contracts are intentionally
 * preserved for back-compat (no data loss / no re-prompting existing visitors):
 *   - consent cookie + localStorage key: m_consent
 *   - settings option key:               m_consent_settings
 *   - log table:                         {$wpdb->prefix}m_consent_log
 *   - content shortcode:                 [m_cookie_preferences]
 *   - JS integration event:              m_consent:change
 * New lmg_* aliases are provided alongside the originals.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

define( 'LMG_CONSENT_VERSION', '1.2.1' );
define( 'LMG_CONSENT_PATH',    plugin_dir_path( __FILE__ ) );
define( 'LMG_CONSENT_URL',     plugin_dir_url( __FILE__ ) );
define( 'LMG_CONSENT_FILE',    __FILE__ );

require_once LMG_CONSENT_PATH . 'includes/class-lmg-consent.php';
require_once LMG_CONSENT_PATH . 'includes/class-lmg-consent-log.php';
require_once LMG_CONSENT_PATH . 'includes/class-lmg-consent-blocker.php';
require_once LMG_CONSENT_PATH . 'includes/class-lmg-consent-admin.php';
require_once LMG_CONSENT_PATH . 'includes/class-lmg-consent-updater.php';

register_activation_hook( __FILE__, [ 'LMG_Consent_Log', 'install' ] );
register_activation_hook( __FILE__, [ 'LMG_Consent', 'schedule_purge' ] );
register_deactivation_hook( __FILE__, [ 'LMG_Consent', 'unschedule_purge' ] );

add_action( 'plugins_loaded', function () {
    LMG_Consent::instance();
    LMG_Consent_Blocker::instance();
    LMG_Consent_Admin::instance();

    // GitHub update checker — only where updates are actually evaluated
    // (admin UI, wp-cron background checks, WP-CLI); skip front-end pageloads.
    if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
        LMG_Consent_Updater::init();
    }
} );

/**
 * Server-side consent check.
 *
 * @param string $category One of 'necessary', 'functional', 'analytics', 'marketing'.
 * @return bool True when the visitor has granted consent for that category.
 *              Strictly-necessary cookies always return true.
 */
function lmg_consent_given( $category ) {
    if ( $category === 'necessary' ) {
        return true;
    }
    // Storage key retained as 'm_consent' for back-compat across the rebrand.
    if ( empty( $_COOKIE['m_consent'] ) ) {
        return false;
    }
    $raw  = wp_unslash( $_COOKIE['m_consent'] );
    $data = json_decode( $raw, true );
    if ( ! is_array( $data ) || empty( $data['categories'] ) ) {
        return false;
    }
    return ! empty( $data['categories'][ $category ] );
}

/**
 * Back-compat alias for the pre-rebrand helper name.
 *
 * @deprecated 1.1.0 Use lmg_consent_given() instead.
 */
if ( ! function_exists( 'm_consent_given' ) ) {
    function m_consent_given( $category ) {
        return lmg_consent_given( $category );
    }
}
