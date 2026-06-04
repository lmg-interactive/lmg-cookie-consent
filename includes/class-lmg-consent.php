<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Main plugin class — boots Consent Mode v2 defaults, enqueues assets,
 * renders the banner + modal, registers the REST log endpoint, the consent
 * log purge cron, and the preferences shortcode(s).
 */
final class LMG_Consent {

    const COOKIE      = 'm_consent';        // storage key retained across rebrand
    const CONSENT_TTL = YEAR_IN_SECONDS;    // re-prompt after 1 year
    const SCHEMA_VER  = 1;
    const PURGE_HOOK  = 'lmg_consent_purge';

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'wp_head',            [ $this, 'print_consent_defaults' ], 1 );
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_action( 'wp_footer',          [ $this, 'render_banner' ], 99 );
        add_action( 'rest_api_init',      [ $this, 'register_rest' ] );
        add_action( self::PURGE_HOOK,     [ $this, 'run_purge' ] );

        // Preferences modal opener — keep the original tag plus an lmg_ alias.
        add_shortcode( 'm_cookie_preferences',   [ $this, 'shortcode_preferences' ] );
        add_shortcode( 'lmg_cookie_preferences', [ $this, 'shortcode_preferences' ] );

        // Self-heal: ensure the purge cron exists for installs upgraded in place.
        if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
            self::schedule_purge();
        }
    }

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    public static function defaults() {
        return [
            'title'             => __( 'We value your privacy', 'lmg-consent' ),
            'message'           => __( 'We use cookies to keep this site reliable, understand how it’s used, and — with your permission — to personalize content. You can accept all, reject non-essential, or choose which categories to allow.', 'lmg-consent' ),
            'privacy_url'       => '',
            'privacy_label'     => __( 'Privacy Policy', 'lmg-consent' ),
            'enabled'           => [
                'necessary'  => true,
                'functional' => true,
                'analytics'  => true,
                'marketing'  => true,
            ],
            'log_enabled'       => true,
            'log_retention_days'=> 730, // ~24 months; storage-limitation purge
        ];
    }

    public static function settings() {
        $saved = get_option( 'm_consent_settings', [] );
        return wp_parse_args( is_array( $saved ) ? $saved : [], self::defaults() );
    }

    /**
     * Effective privacy policy URL: the configured one, else WordPress core's
     * Privacy Policy page. Keeps a transparency link in the banner by default.
     */
    public static function privacy_url() {
        $s = self::settings();
        $url = ! empty( $s['privacy_url'] ) ? $s['privacy_url'] : get_privacy_policy_url();
        return $url ? $url : '';
    }

    /* ------------------------------------------------------------------
     * Consent log retention (cron)
     * ------------------------------------------------------------------ */

    public static function schedule_purge() {
        if ( ! wp_next_scheduled( self::PURGE_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::PURGE_HOOK );
        }
    }

    public static function unschedule_purge() {
        $ts = wp_next_scheduled( self::PURGE_HOOK );
        if ( $ts ) {
            wp_unschedule_event( $ts, self::PURGE_HOOK );
        }
    }

    public function run_purge() {
        $s    = self::settings();
        $days = (int) ( $s['log_retention_days'] ?? 730 );
        if ( $days > 0 ) {
            LMG_Consent_Log::purge( $days );
        }
    }

    /* ------------------------------------------------------------------
     * Google Consent Mode v2 — print defaults BEFORE any tag loads
     * ------------------------------------------------------------------ */

    public function print_consent_defaults() {
        ?>
<script id="lmg-consent-gcm-defaults">
(function(){
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  window.gtag = window.gtag || gtag;
  // GDPR-safe: deny everything non-essential by default until the visitor
  // tells us otherwise. functionality_storage is denied too (it maps to our
  // consent-gated "Functional" category); only security_storage is granted.
  gtag('consent', 'default', {
    'ad_storage':              'denied',
    'ad_user_data':            'denied',
    'ad_personalization':      'denied',
    'analytics_storage':       'denied',
    'functionality_storage':   'denied',
    'security_storage':        'granted',
    'personalization_storage': 'denied',
    'wait_for_update': 500
  });
  gtag('set', 'ads_data_redaction', true);
  gtag('set', 'url_passthrough', true);
})();
</script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Asset enqueue
     * ------------------------------------------------------------------ */

    public function enqueue_assets() {
        wp_register_style(
            'lmg-consent',
            LMG_CONSENT_URL . 'assets/css/banner.css',
            [],
            LMG_CONSENT_VERSION
        );
        wp_enqueue_style( 'lmg-consent' );

        wp_register_script(
            'lmg-consent',
            LMG_CONSENT_URL . 'assets/js/banner.js',
            [],
            LMG_CONSENT_VERSION,
            true
        );
        wp_enqueue_script( 'lmg-consent' );

        $settings = self::settings();

        // JS global kept as `mConsent` (internal contract between this enqueue
        // and banner.js; not persisted, not user-facing).
        wp_localize_script( 'lmg-consent', 'mConsent', [
            'cookie'      => self::COOKIE,
            'cookieTTL'   => self::CONSENT_TTL,
            'schema'      => self::SCHEMA_VER,
            'restUrl'     => rest_url( 'lmg-consent/v1/log' ),
            'restNonce'   => wp_create_nonce( 'wp_rest' ),
            'logEnabled'  => ! empty( $settings['log_enabled'] ),
            'categories'  => [
                [ 'key'=>'necessary',  'enabled'=> ! empty($settings['enabled']['necessary']),  'required'=> true,
                  'title'=>__('Strictly Necessary','lmg-consent'),
                  'desc' =>__('Required for the site to function (session, security, form submission). Cannot be disabled.','lmg-consent') ],
                [ 'key'=>'functional', 'enabled'=> ! empty($settings['enabled']['functional']), 'required'=> false,
                  'title'=>__('Functional','lmg-consent'),
                  'desc' =>__('Remember your preferences (language, layout) for a better experience.','lmg-consent') ],
                [ 'key'=>'analytics',  'enabled'=> ! empty($settings['enabled']['analytics']),  'required'=> false,
                  'title'=>__('Analytics','lmg-consent'),
                  'desc' =>__('Help us understand which pages are useful so we can improve them. Data is aggregated.','lmg-consent') ],
                [ 'key'=>'marketing',  'enabled'=> ! empty($settings['enabled']['marketing']),  'required'=> false,
                  'title'=>__('Marketing','lmg-consent'),
                  'desc' =>__('Personalize content and measure campaign performance across sites.','lmg-consent') ],
            ],
        ] );
    }

    /* ------------------------------------------------------------------
     * Banner + modal markup (always present; JS decides visibility)
     * ------------------------------------------------------------------ */

    public function render_banner() {
        $s = self::settings();
        // Fall back to WordPress' Privacy Policy page so a transparency link is
        // present even when no custom URL is configured.
        if ( empty( $s['privacy_url'] ) ) {
            $s['privacy_url'] = get_privacy_policy_url();
        }
        include LMG_CONSENT_PATH . 'templates/banner.php';
    }

    public function shortcode_preferences( $atts ) {
        $atts = shortcode_atts( [
            'label' => __( 'Cookie Preferences', 'lmg-consent' ),
            'class' => '',
        ], $atts, 'm_cookie_preferences' );
        return sprintf(
            '<button type="button" class="m-consent-open %s" data-m-consent-open>%s</button>',
            esc_attr( $atts['class'] ),
            esc_html( $atts['label'] )
        );
    }

    /* ------------------------------------------------------------------
     * REST endpoint — accepts consent log writes from the banner
     * ------------------------------------------------------------------ */

    public function register_rest() {
        register_rest_route( 'lmg-consent/v1', '/log', [
            'methods'  => 'POST',
            'callback' => [ $this, 'rest_log' ],
            'permission_callback' => function ( WP_REST_Request $r ) {
                // Nonce restricts to same-origin requests from our own JS.
                return wp_verify_nonce( $r->get_header( 'X-WP-Nonce' ), 'wp_rest' ) !== false;
            },
            'args' => [
                'action'     => [ 'required' => true,  'type' => 'string' ],
                'categories' => [ 'required' => true,  'type' => 'object' ],
                'consent_id' => [ 'required' => true,  'type' => 'string' ],
                'schema'     => [ 'required' => false, 'type' => 'integer' ],
            ],
        ] );
    }

    public function rest_log( WP_REST_Request $r ) {
        $s = self::settings();
        if ( empty( $s['log_enabled'] ) ) {
            return new WP_REST_Response( [ 'ok' => true, 'logged' => false ], 200 );
        }

        $action     = sanitize_key( $r->get_param( 'action' ) );
        $consent_id = sanitize_text_field( $r->get_param( 'consent_id' ) );
        $schema     = (int) ( $r->get_param( 'schema' ) ?: self::SCHEMA_VER );
        $cats_raw   = $r->get_param( 'categories' );

        // 'gpc' = visitor opted out via the Global Privacy Control browser signal.
        $valid_actions = [ 'accept_all', 'reject_all', 'custom', 'withdraw', 'gpc' ];
        if ( ! in_array( $action, $valid_actions, true ) ) {
            return new WP_Error( 'lmg_consent_bad_action', 'Invalid action', [ 'status' => 400 ] );
        }
        if ( ! is_array( $cats_raw ) ) {
            return new WP_Error( 'lmg_consent_bad_categories', 'Invalid categories', [ 'status' => 400 ] );
        }
        $allowed = [ 'necessary', 'functional', 'analytics', 'marketing' ];
        $cats = [];
        foreach ( $allowed as $c ) {
            $cats[ $c ] = ! empty( $cats_raw[ $c ] );
        }

        $ip   = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( $_SERVER['REMOTE_ADDR'] ) : '';
        $ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $salt = wp_salt( 'nonce' );

        LMG_Consent_Log::record( [
            'consent_id'  => substr( $consent_id, 0, 64 ),
            'ip_hash'     => hash_hmac( 'sha256', $ip, $salt ),
            'ua_hash'     => hash_hmac( 'sha256', $ua, $salt ),
            'action'      => $action,
            'categories'  => wp_json_encode( $cats ),
            'schema_ver'  => $schema,
        ] );

        return new WP_REST_Response( [ 'ok' => true, 'logged' => true ], 200 );
    }
}
