<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
/** @var array $s Settings (from LMG_Consent::render_banner). */
?>
<div class="m-consent" id="m-consent" role="region" aria-label="<?php esc_attr_e( 'Cookie consent', 'lmg-consent' ); ?>" hidden>

    <div class="m-consent__banner" role="dialog" aria-modal="false" aria-labelledby="m-consent-title" data-m-consent-banner>
        <div class="m-consent__banner-inner">
            <div class="m-consent__body">
                <h2 id="m-consent-title" class="m-consent__title"><?php echo esc_html( $s['title'] ); ?></h2>
                <p class="m-consent__message"><?php echo wp_kses_post( $s['message'] ); ?></p>
                <?php if ( ! empty( $s['privacy_url'] ) ) : ?>
                    <p class="m-consent__privacy">
                        <a href="<?php echo esc_url( $s['privacy_url'] ); ?>"><?php echo esc_html( $s['privacy_label'] ); ?></a>
                    </p>
                <?php endif; ?>
            </div>
            <div class="m-consent__actions">
                <button type="button" class="m-consent__btn m-consent__btn--ghost" data-m-consent-customize><?php esc_html_e( 'Customize', 'lmg-consent' ); ?></button>
                <button type="button" class="m-consent__btn m-consent__btn--secondary" data-m-consent-reject><?php esc_html_e( 'Reject all', 'lmg-consent' ); ?></button>
                <button type="button" class="m-consent__btn m-consent__btn--primary" data-m-consent-accept><?php esc_html_e( 'Accept all', 'lmg-consent' ); ?></button>
            </div>
        </div>
    </div>

    <div class="m-consent__modal" role="dialog" aria-modal="true" aria-labelledby="m-consent-modal-title" data-m-consent-modal hidden>
        <div class="m-consent__modal-backdrop" data-m-consent-close></div>
        <div class="m-consent__modal-panel">
            <header class="m-consent__modal-head">
                <h2 id="m-consent-modal-title" class="m-consent__modal-title"><?php esc_html_e( 'Cookie Preferences', 'lmg-consent' ); ?></h2>
                <button type="button" class="m-consent__close" data-m-consent-close aria-label="<?php esc_attr_e( 'Close', 'lmg-consent' ); ?>">&times;</button>
            </header>
            <div class="m-consent__modal-body" data-m-consent-categories>
                <!-- JS renders category toggles here -->
            </div>
            <footer class="m-consent__modal-foot">
                <button type="button" class="m-consent__btn m-consent__btn--ghost" data-m-consent-reject><?php esc_html_e( 'Reject non-essential', 'lmg-consent' ); ?></button>
                <button type="button" class="m-consent__btn m-consent__btn--secondary" data-m-consent-save><?php esc_html_e( 'Save preferences', 'lmg-consent' ); ?></button>
                <button type="button" class="m-consent__btn m-consent__btn--primary" data-m-consent-accept><?php esc_html_e( 'Accept all', 'lmg-consent' ); ?></button>
            </footer>
        </div>
    </div>
</div>
