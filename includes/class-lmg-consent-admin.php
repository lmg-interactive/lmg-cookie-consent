<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

/**
 * Settings page — Settings → LMG Consent.
 */
final class LMG_Consent_Admin {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', [ $this, 'menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
    }

    public function menu() {
        add_options_page(
            __( 'LMG Consent', 'lmg-consent' ),
            __( 'LMG Consent', 'lmg-consent' ),
            'manage_options',
            'lmg-consent',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        register_setting( 'm_consent_group', 'm_consent_settings', [
            'type'              => 'array',
            'sanitize_callback' => [ $this, 'sanitize' ],
            'default'           => LMG_Consent::defaults(),
        ] );
    }

    public function sanitize( $input ) {
        $out = LMG_Consent::defaults();
        if ( ! is_array( $input ) ) { return $out; }

        $out['title']         = sanitize_text_field( $input['title']         ?? $out['title'] );
        $out['message']       = wp_kses_post( $input['message']              ?? $out['message'] );
        $out['privacy_url']   = esc_url_raw( $input['privacy_url']           ?? '' );
        $out['privacy_label'] = sanitize_text_field( $input['privacy_label'] ?? $out['privacy_label'] );
        $out['log_enabled']   = ! empty( $input['log_enabled'] );

        $days = (int) ( $input['log_retention_days'] ?? $out['log_retention_days'] );
        $out['log_retention_days'] = min( 3650, max( 30, $days ) ); // clamp 30d–10y

        foreach ( [ 'necessary', 'functional', 'analytics', 'marketing' ] as $c ) {
            $out['enabled'][ $c ] = $c === 'necessary' ? true : ! empty( $input['enabled'][ $c ] );
        }
        return $out;
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $s = LMG_Consent::settings();

        // Transparency nudge: warn if there's no banner privacy link at all.
        $has_privacy_link = ! empty( $s['privacy_url'] ) || get_privacy_policy_url();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'LMG Consent', 'lmg-consent' ); ?></h1>

            <?php if ( ! $has_privacy_link ) : ?>
                <div class="notice notice-warning"><p>
                    <?php
                    printf(
                        /* translators: %s: link to WordPress privacy settings */
                        wp_kses_post( __( 'No privacy policy link is set. For transparency the banner should link to your privacy/cookie policy — add a URL below, or set the site %s.', 'lmg-consent' ) ),
                        '<a href="' . esc_url( admin_url( 'options-privacy.php' ) ) . '">' . esc_html__( 'Privacy Policy page', 'lmg-consent' ) . '</a>'
                    );
                    ?>
                </p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields( 'm_consent_group' ); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="mc-title"><?php esc_html_e( 'Banner title', 'lmg-consent' ); ?></label></th>
                        <td><input id="mc-title" type="text" class="regular-text" name="m_consent_settings[title]" value="<?php echo esc_attr( $s['title'] ); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-message"><?php esc_html_e( 'Banner body', 'lmg-consent' ); ?></label></th>
                        <td><textarea id="mc-message" rows="4" class="large-text" name="m_consent_settings[message]"><?php echo esc_textarea( $s['message'] ); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-privacy"><?php esc_html_e( 'Privacy Policy URL', 'lmg-consent' ); ?></label></th>
                        <td>
                            <input id="mc-privacy" type="url" class="regular-text" name="m_consent_settings[privacy_url]" value="<?php echo esc_attr( $s['privacy_url'] ); ?>" placeholder="https://example.com/privacy">
                            <p class="description"><?php esc_html_e( 'Recommended. If left blank, the banner links to your WordPress Privacy Policy page when one is set.', 'lmg-consent' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Categories', 'lmg-consent' ); ?></th>
                        <td>
                            <label><input type="checkbox" checked disabled> <?php esc_html_e( 'Strictly Necessary (always on)', 'lmg-consent' ); ?></label><br>
                            <label><input type="checkbox" name="m_consent_settings[enabled][functional]" <?php checked( ! empty( $s['enabled']['functional'] ) ); ?>> <?php esc_html_e( 'Functional', 'lmg-consent' ); ?></label><br>
                            <label><input type="checkbox" name="m_consent_settings[enabled][analytics]"  <?php checked( ! empty( $s['enabled']['analytics'] ) ); ?>> <?php esc_html_e( 'Analytics', 'lmg-consent' ); ?></label><br>
                            <label><input type="checkbox" name="m_consent_settings[enabled][marketing]"  <?php checked( ! empty( $s['enabled']['marketing'] ) ); ?>> <?php esc_html_e( 'Marketing', 'lmg-consent' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><?php esc_html_e( 'Consent log', 'lmg-consent' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="m_consent_settings[log_enabled]" <?php checked( ! empty( $s['log_enabled'] ) ); ?>> <?php esc_html_e( 'Log consent events (IP / UA hashed) to demonstrate compliance', 'lmg-consent' ); ?></label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="mc-retention"><?php esc_html_e( 'Log retention (days)', 'lmg-consent' ); ?></label></th>
                        <td>
                            <input id="mc-retention" type="number" min="30" max="3650" step="1" class="small-text" name="m_consent_settings[log_retention_days]" value="<?php echo esc_attr( $s['log_retention_days'] ); ?>">
                            <p class="description"><?php esc_html_e( 'Consent records older than this are deleted automatically by a daily job. Default 730 (≈24 months). Range 30–3650.', 'lmg-consent' ); ?></p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php esc_html_e( 'Usage', 'lmg-consent' ); ?></h2>
            <p><code>[m_cookie_preferences]</code> <?php esc_html_e( '(alias', 'lmg-consent' ); ?> <code>[lmg_cookie_preferences]</code>) — <?php esc_html_e( 'renders a button that reopens the preferences modal. Place it in your footer so consent can always be withdrawn.', 'lmg-consent' ); ?></p>
            <p><code>lmg_consent_given( 'analytics' )</code> — <?php esc_html_e( 'PHP helper to gate a category server-side (old', 'lmg-consent' ); ?> <code>m_consent_given()</code> <?php esc_html_e( 'still works).', 'lmg-consent' ); ?></p>
            <p><code>document.addEventListener('m_consent:change', e =&gt; {...})</code> — <?php esc_html_e( 'JS event on every consent update (also fires', 'lmg-consent' ); ?> <code>lmg_consent:change</code>).</p>
            <p class="description"><?php esc_html_e( 'Global Privacy Control (GPC) is honored automatically: visitors whose browser sends a GPC signal are opted out of non-essential cookies without needing to interact with the banner.', 'lmg-consent' ); ?></p>
        </div>
        <?php
    }
}
