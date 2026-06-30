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
            // Google analytics loaders.
            [ 'regex' => '~googletagmanager\.com/gtag/js~i',       'category' => 'analytics' ],
            [ 'regex' => '~google-analytics\.com/analytics\.js~i', 'category' => 'analytics' ],
            [ 'regex' => '~google-analytics\.com/ga\.js~i',        'category' => 'analytics' ],
            // Google Tag Manager container — gates everything it can load.
            [ 'regex' => '~googletagmanager\.com/gtm\.js~i',       'category' => 'marketing' ],
            // Marketing / advertising pixels.
            [ 'regex' => '~connect\.facebook\.net/[^"\']*fbevents\.js~i',     'category' => 'marketing' ], // Meta / Facebook
            [ 'regex' => '~snap\.licdn\.com/li\.lms-analytics/insight~i',     'category' => 'marketing' ], // LinkedIn Insight
            [ 'regex' => '~analytics\.tiktok\.com/i18n/pixel/events~i',       'category' => 'marketing' ], // TikTok
            [ 'regex' => '~bat\.bing\.com/bat\.js~i',                         'category' => 'marketing' ], // Microsoft / Bing UET
            [ 'regex' => '~s\.pinimg\.com/ct/core~i',                         'category' => 'marketing' ], // Pinterest Tag
            [ 'regex' => '~static\.ads-twitter\.com/uwt~i',                   'category' => 'marketing' ], // X / Twitter
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
}
