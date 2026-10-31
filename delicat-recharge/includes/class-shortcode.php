<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Delicat_Shortcode {

    public function __construct() {
        add_shortcode( 'delicat_recharge', [ $this, 'render' ] );
        add_shortcode( 'delicat_wallet_balance', [ $this, 'render_balance_card' ] );
        add_shortcode( 'delicat_balance_card', [ $this, 'render_balance_card' ] );
        add_action( 'wp_enqueue_scripts', [ $this, 'register_assets' ] );
    }

    /**
     * Register CSS/JS on every front-end load so we can enqueue on demand.
     * Enqueuing happens in render(), which works even inside Elementor widgets
     * where has_shortcode() on post_content would fail.
     */
    public function register_assets(): void {
        wp_register_style(
            'delicat-recharge',
            DELICAT_URL . 'assets/css/form.css',
            [],
            defined( 'DELICAT_ASSET_VERSION' ) ? DELICAT_ASSET_VERSION : DELICAT_VERSION
        );

        wp_register_script(
            'delicat-recharge',
            DELICAT_URL . 'assets/js/form.js',
            [ 'jquery' ],
            defined( 'DELICAT_ASSET_VERSION' ) ? DELICAT_ASSET_VERSION : DELICAT_VERSION,
            true
        );

        wp_localize_script( 'delicat-recharge', 'delicatData', $this->data() );

        // v5.0.3: load the modern wallet design outside the recharge shortcode too.
        // This is required because the WooCommerce dashboard / Code Snippets wallet
        // uses .dmc/.dsm/.dpc classes and may not contain [delicat_recharge].
        if ( ! is_admin() && ( is_account_page() || is_page( 'my-wallet' ) || is_page( 'wallet' ) ) ) {
            wp_enqueue_style( 'delicat-recharge' );
            wp_enqueue_script( 'delicat-recharge' );
        }
    }

    private function data(): array {
        return [
            'ajax_url'    => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'delicat_nonce' ),
            'logged_in'   => is_user_logged_in() ? '1' : '0',
            'moncash_num' => esc_html( get_option( 'delicat_moncash_numero', '39421885' ) ),
            'moncash_nom' => esc_html( get_option( 'delicat_moncash_nom',    'LOUIS MARKEN' ) ),
            'natcash_num' => esc_html( get_option( 'delicat_natcash_numero', '33111283' ) ),
            'natcash_nom' => esc_html( get_option( 'delicat_natcash_nom',    'Damas Louis' ) ),
            'i18n' => [
                'required_amount' => 'Veuillez entrer un montant valide.',
                'required_trans'  => 'Veuillez entrer votre TransCode / Txn ID.',
                'required_proof'  => 'Veuillez joindre une preuve de paiement.',
                'required_email'  => 'Veuillez entrer votre email.',
                'sending'         => '⏳ Envoi en cours…',
                'send'            => 'Envoyer la demande',
                'network_error'   => 'Erreur réseau. Veuillez réessayer.',
            ],
        ];
    }


    /**
     * Premium Apple-glass wallet balance card.
     * Backend untouched: reads existing TeraWallet balance + existing Delicat user code only.
     * Shortcodes:
     * [delicat_wallet_balance]
     * [delicat_balance_card]
     */
    public function render_balance_card(): string {
        wp_enqueue_style( 'delicat-recharge' );
        wp_enqueue_script( 'delicat-recharge' );

        if ( ! is_user_logged_in() ) {
            return '<section class="dw12-shell"><div class="dw12-card dw12-guest"><h2>Ma balance 🌴</h2><p>Connectez-vous pour consulter votre wallet Delicat Store.</p><a class="dw12-action dw12-recharge" href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">Connexion</a></div></section>';
        }

        $uid  = get_current_user_id();
        $user = wp_get_current_user();
        $name = trim( $user->first_name . ' ' . $user->last_name );
        if ( $name === '' ) {
            $name = $user->display_name ?: $user->user_login;
        }

        $balance_raw = '—';
        if ( function_exists( 'woo_wallet' ) ) {
            $balance_raw = woo_wallet()->wallet->get_wallet_balance( $uid, 'edit' );
        }
        $balance_number = is_numeric( $balance_raw ) ? number_format_i18n( (float) $balance_raw, 0 ) : wp_strip_all_tags( (string) $balance_raw );
        // Raw numeric value for the live JS count-up (no thousands separators).
        $balance_float  = is_numeric( $balance_raw ) ? (float) $balance_raw : 0.0;
        $currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'HTG';

        $code = $this->delicat_user_code( $uid );

        $qr_data = rawurlencode( 'DELICAT:' . $code );

        ob_start();
        ?>
        <section class="dw12-shell" aria-label="Ma balance Delicat Store">
            <div class="dw12-card">
                <span class="dw12-bubble dw12-b1" aria-hidden="true"></span>
                <span class="dw12-bubble dw12-b2" aria-hidden="true"></span>
                <span class="dw12-bubble dw12-b3" aria-hidden="true"></span>

                <h2 class="dw12-title">MA <span>🌴</span> BALANCE</h2>

                <div class="dw12-topgrid">
                    <div class="dw12-balancebox">
                        <div class="dw12-label">Balance disponible</div>
                        <div class="dw12-amount" id="dw12-balance-amount" data-balance="<?php echo esc_attr( $balance_float ); ?>"><strong><?php echo esc_html( $balance_number ); ?></strong><em><?php echo esc_html( $currency ); ?></em></div>
                        <div class="dw12-verified"><i>✓</i><span>Compte vérifié</span></div>
                    </div>

                    <div class="dw12-clientbox">
                        <div class="dw12-label small">Nom du client</div>
                        <div class="dw12-name"><?php echo esc_html( $name ); ?></div>
                        <div class="dw12-sep"></div>
                        <div class="dw12-label purple">Mon code personnel</div>
                        <div class="dw12-code-line">
                            <strong><?php echo esc_html( $code ); ?></strong>
                            <button type="button" class="dw12-copy" data-dsm-copy="<?php echo esc_attr( $code ); ?>" aria-label="Copier le code"><span class="dsm-copytxt">⧉</span></button>
                        </div>
                        <p>Partagez-le pour recevoir de l'argent.</p>
                    </div>
                </div>

                <div class="dw12-actions">
                    <a href="#delicat-recharge-form" class="dw12-action dw12-recharge" data-delicat-scroll="recharge">
                        <span class="dw12-icon">↓</span>
                        <span class="dw12-text"><b>Recharger</b><small>Mon compte</small></span>
                        <span class="dw12-arrow">›</span>
                    </a>

                    <!-- POPUP (was an inline dropdown). The button no longer
                         expands a menu in place; it opens a centred modal, which
                         reads far better on a phone than a panel that pushes the
                         card around. -->
                    <button type="button" class="dw12-action dw12-send" data-delicat-send-menu aria-haspopup="dialog">
                        <span class="dw12-icon">➤</span>
                        <span class="dw12-text"><b>Envoyer</b><small>De l’argent</small></span>
                        <span class="dw12-arrow">⌄</span>
                    </button>
                </div>

                <div class="dw12-footer"><span>Power by<br>Delicat Store</span><strong>Delicat<br>Store Haiti</strong></div>

                <div class="dw12-qr-modal delicat-qr-modal" data-delicat-qr-modal aria-hidden="true">
                    <div class="dw12-qr-backdrop delicat-qr-backdrop" data-delicat-qr-close></div>
                    <div class="dw12-qr-card delicat-qr-card" role="dialog" aria-modal="true" aria-label="QR code Delicat">
                        <button type="button" class="dw12-qr-close delicat-qr-close" data-delicat-qr-close aria-label="Fermer">×</button>
                        <h3>Recevoir de l’argent</h3>
                        <p>Scannez ce QR code ou copiez votre code Delicat.</p>
                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=10&data=<?php echo esc_attr( $qr_data ); ?>" alt="QR code <?php echo esc_attr( $code ); ?>">
                        <div class="dw12-qr-code"><?php echo esc_html( $code ); ?></div>
                        <button type="button" class="dw12-qr-copy" data-dsm-copy="<?php echo esc_attr( $code ); ?>"><span class="dsm-copytxt">Copier mon code</span></button>
                    </div>
                </div>
            </div>
        </section>
        <?php
        return ob_get_clean() . ( class_exists( 'Delicat_Send_Money' ) ? ( new Delicat_Send_Money() )->render() : '' );
    }


    private function delicat_user_code( int $uid ): string {
        $code = (string) get_user_meta( $uid, 'delicat_user_code', true );
        if ( $code === '' ) {
            $code = 'DS' . strtoupper( wp_generate_password( 6, false, false ) );
            update_user_meta( $uid, 'delicat_user_code', $code );
        }
        return $code;
    }


    private function wallet_balance_edit( int $uid ): float {
        if ( function_exists( 'woo_wallet' ) ) {
            return (float) woo_wallet()->wallet->get_wallet_balance( $uid, 'edit' );
        }
        return 0.0;
    }

    private function wallet_price( float $amount ): string {
        return function_exists( 'wc_price' ) ? wc_price( $amount ) : esc_html( number_format_i18n( $amount, 2 ) . ' HTG' );
    }



    /**
     * Styles + behaviour for the "Envoyer de l'argent" modal.
     *
     * Printed once per page. Uses nowdoc so no PHP interpolation can corrupt
     * the CSS/JS braces.
     */
    /**
     * Styles + behaviour for the "Envoyer de l'argent" dialogs.
     *
     * Design notes (these matter — earlier versions broke on all three):
     *  1. Closed dialogs use display:none, NOT visibility/opacity. A
     *     full-viewport element that is merely transparent still swallows every
     *     click on the page, which looks exactly like the page has frozen.
     *  2. The dialogs are re-parented to <body>. .dw12-card uses
     *     backdrop-filter, and a filtered element becomes the containing block
     *     for position:fixed children — leaving them inside traps them in the
     *     card instead of covering the screen.
     *  3. The scroll lock is always recomputed from the DOM, never toggled
     *     blindly, and a watchdog clears it if nothing is actually open.
     *
     * Printed once per page. Nowdoc so no PHP interpolation can touch it.
     */

    public function render(): string {
        // Force-enqueue so assets load wherever the shortcode appears (incl. Elementor).
        wp_enqueue_style( 'delicat-recharge' );
        wp_enqueue_script( 'delicat-recharge' );

        // Inline fallback: guarantees delicatData exists even with aggressive caching
        // or if the registered handle's localized data didn't print.
        static $printed_data = false;
        $inline = '';
        if ( ! $printed_data ) {
            $printed_data = true;
            $inline = '<script>window.delicatData = window.delicatData || '
                . wp_json_encode( $this->data() ) . ';</script>';
        }

        ob_start();
        echo $inline;
        include DELICAT_PATH . 'templates/form.php';
        return ob_get_clean();
    }
}
