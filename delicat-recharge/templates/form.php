<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Front-end presentation template for Delicat Recharge.
 * Payment, validation, AJAX, nonce, wallet and database logic live elsewhere
 * and are intentionally not changed by this UI refresh.
 */
if ( ! function_exists( 'delicat_mask_name' ) ) {
    function delicat_mask_name( string $name ): string {
        $parts = preg_split( '/\s+/', trim( $name ) );
        $out = [];
        foreach ( $parts as $w ) {
            if ( $w === '' ) continue;
            if ( function_exists( 'mb_substr' ) && function_exists( 'mb_strlen' ) ) {
                $keep = mb_substr( $w, 0, 2 );
                $len  = mb_strlen( $w );
            } else {
                $keep = substr( $w, 0, 2 );
                $len  = strlen( $w );
            }
            $out[] = ( $len > 2 ) ? $keep . 'XXX' : $w;
        }
        return implode( ' ', $out );
    }
}

$dr_mc_num = esc_html( get_option( 'delicat_moncash_numero', '39421885' ) );
$dr_mc_nom_full   = get_option( 'delicat_moncash_nom', 'LOUIS MARKEN' );
$dr_mc_nom        = esc_html( $dr_mc_nom_full );
$dr_mc_nom_masked = esc_html( delicat_mask_name( $dr_mc_nom_full ) );
$dr_nc_num = esc_html( get_option( 'delicat_natcash_numero', '33111283' ) );
$dr_nc_nom_full   = get_option( 'delicat_natcash_nom', 'Damas Louis' );
$dr_nc_nom        = esc_html( $dr_nc_nom_full );
$dr_nc_nom_masked = esc_html( delicat_mask_name( $dr_nc_nom_full ) );

$dr_enabled = [
    'natcash_tc' => Delicat_Database::service_on( 'natcash_tc' ),
    'moncash'    => Delicat_Database::service_on( 'moncash' ),
    'natcash'    => false,
];
$dr_first = '';
foreach ( $dr_enabled as $dr_k => $dr_v ) {
    if ( $dr_v ) {
        $dr_first = $dr_k;
        break;
    }
}

if ( $dr_first === '' ) {
    echo '<div class="dr-wrap dr-modern"><div class="dr-message dr-error" style="display:block;">⛔ Les recharges sont temporairement indisponibles. Merci de réessayer plus tard.</div></div>';
    return;
}
?>

<div class="dr-wrap dr-modern" id="delicat-recharge-form" data-active-tab="<?php echo esc_attr( $dr_first ); ?>">
    <span class="dr-aurora dr-aurora-a" aria-hidden="true"></span>
    <span class="dr-aurora dr-aurora-b" aria-hidden="true"></span>

    <header class="dr-head">
        <div class="dr-head-top">
            <span class="dr-kicker">
                <span class="dr-kicker-dot" aria-hidden="true"></span>
                Wallet Delicat Store
            </span>
            <span class="dr-secure">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8 20 6v5.6c0 4.8-3.1 8.4-8 9.8-4.9-1.4-8-5-8-9.8V6l8-3.2Zm-1.1 12.5 5.2-5.2-1.4-1.4-3.8 3.8-1.8-1.8-1.4 1.4 3.2 3.2Z"/></svg>
                100% Sécurisé
            </span>
        </div>
        <div class="dr-head-copy">
            <span class="dr-head-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M13.3 2 5 13h6l-.3 9L19 11h-6l.3-9Z"/></svg>
            </span>
            <div>
                <h2>Recharger votre compte</h2>
                <p>Choisissez votre méthode, suivez les étapes et envoyez votre demande en toute sécurité.</p>
            </div>
        </div>
    </header>

    <section class="dr-method-selector" aria-label="Choisir une méthode de recharge">
        <div class="dr-tabs dr-tabs-<?php echo count( array_filter( $dr_enabled ) ); ?>" role="tablist" aria-label="Méthode de recharge">
            <?php foreach ( [ 'natcash_tc' => 'Natcash Auto (TC)', 'moncash' => 'MonCash' ] as $dr_tk => $dr_tl ) :
                if ( empty( $dr_enabled[ $dr_tk ] ) ) continue;
                $dr_active = ( $dr_tk === $dr_first ); ?>
                <button type="button" class="dr-tab<?php echo $dr_active ? ' active' : ''; ?><?php echo $dr_tk === 'natcash_tc' ? ' dr-tab-recommended' : ''; ?>" data-tab="<?php echo esc_attr( $dr_tk ); ?>" role="tab" aria-selected="<?php echo $dr_active ? 'true' : 'false'; ?>">
                    <span class="dr-tab-icon" aria-hidden="true">
                        <?php if ( $dr_tk === 'natcash_tc' ) : ?>
                            <svg viewBox="0 0 24 24"><path d="M13.3 2 5 13h6l-.3 9L19 11h-6l.3-9Z"/></svg>
                        <?php else : ?>
                            <svg viewBox="0 0 24 24"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H18a2 2 0 0 1 2 2v1H7.5A3.5 3.5 0 0 0 4 10.5v-4Zm0 4A2.5 2.5 0 0 1 6.5 8H20v10a2 2 0 0 1-2 2H6.5A2.5 2.5 0 0 1 4 17.5v-7ZM15 12h4v4h-4a2 2 0 1 1 0-4Z"/></svg>
                        <?php endif; ?>
                    </span>
                    <span class="dr-tab-copy">
                        <strong><?php echo esc_html( $dr_tl ); ?></strong>
                        <?php if ( $dr_tk === 'natcash_tc' ) : ?><small class="dr-tab-badge">Recommandé</small><?php else : ?><small>Recharge classique</small><?php endif; ?>
                    </span>
                    <span class="dr-tab-check" aria-hidden="true">✓</span>
                </button>
            <?php endforeach; ?>
        </div>

        <?php if ( ! empty( $dr_enabled['natcash_tc'] ) ) : ?>
            <p class="dr-default-method-note">
                <span aria-hidden="true">⚡</span>
                <span class="dr-method-note-copy"><strong>Natcash Auto (TC)</strong> • validation automatique • rapide &amp; sécurisée</span>
            </p>
        <?php endif; ?>
    </section>

    <div class="dr-message" style="display:none;" role="alert" aria-live="polite"></div>
    <input type="hidden" id="dr-tab" value="<?php echo esc_attr( $dr_first ); ?>">

    <div class="dr-content-grid">
        <div class="dr-method-stage">
            <div class="dr-info-box dr-info-moncash" style="<?php echo $dr_first === 'moncash' ? '' : 'display:none;'; ?>">
                <div class="dr-info-main">
                    <div class="dr-method-intro">
                        <span class="dr-method-icon" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M4 6.5A2.5 2.5 0 0 1 6.5 4H18a2 2 0 0 1 2 2v1H7.5A3.5 3.5 0 0 0 4 10.5v-4Zm0 4A2.5 2.5 0 0 1 6.5 8H20v10a2 2 0 0 1-2 2H6.5A2.5 2.5 0 0 1 4 17.5v-7ZM15 12h4v4h-4a2 2 0 1 1 0-4Z"/></svg>
                        </span>
                        <div>
                            <p class="dr-info-eyebrow">Méthode sélectionnée</p>
                            <p class="dr-info-title">MonCash</p>
                            <p>Envoyez le montant exact au numéro ci-dessous, puis saisissez votre TransCode pour transmettre la demande.</p>
                        </div>
                    </div>

                    <details class="dr-how-card">
                        <summary class="dr-section-title"><span>Comment ça marche ?</span><span class="dr-how-chevron" aria-hidden="true">⌄</span></summary>
                        <div class="dr-steps">
                            <div class="dr-step"><span class="dr-step-num">1</span><span class="dr-step-icon">↗</span><strong>Transférez</strong><small>Envoyez le montant exact.</small></div>
                            <div class="dr-step-line" aria-hidden="true"></div>
                            <div class="dr-step"><span class="dr-step-num">2</span><span class="dr-step-icon">•••</span><strong>Entrez le code</strong><small>Saisissez le TransCode reçu.</small></div>
                            <div class="dr-step-line" aria-hidden="true"></div>
                            <div class="dr-step"><span class="dr-step-num">3</span><span class="dr-step-icon">✓</span><strong>Vérification</strong><small>Votre demande est contrôlée.</small></div>
                        </div>
                    </details>

                    <div class="dr-pay-card dr-account-card">
                        <div class="dr-account-row">
                            <span class="dr-account-icon dr-account-emoji" aria-hidden="true">📞</span>
                            <div class="dr-account-copy"><small>Numéro MonCash</small><strong id="dr-mc-num"><?php echo $dr_mc_num; ?></strong></div>
                            <button type="button" class="dr-copy-btn" data-dr-copy="<?php echo esc_attr( $dr_mc_num ); ?>" aria-label="Copier le numéro MonCash"><span aria-hidden="true">⧉</span><span class="dr-copy-label">Copier</span></button>
                        </div>
                        <div class="dr-account-divider"></div>
                        <div class="dr-account-row">
                            <span class="dr-account-icon dr-account-emoji" aria-hidden="true">👤</span>
                            <div class="dr-account-copy"><small>Nom enregistré</small><strong id="dr-mc-nom" class="dr-name-mask" data-full="<?php echo esc_attr( $dr_mc_nom_full ); ?>" data-masked="<?php echo esc_attr( delicat_mask_name( $dr_mc_nom_full ) ); ?>"><?php echo $dr_mc_nom_masked; ?></strong></div>
                            <button type="button" class="dr-eye" data-target="dr-mc-nom" title="Afficher / masquer le nom" aria-label="Afficher ou masquer le nom"><span aria-hidden="true">👁</span></button>
                        </div>
                    </div>
                </div>
                <p class="dr-manual-badge"><span aria-hidden="true">⏳</span> Traitement manuel — recharge après vérification.</p>
            </div>

            <div class="dr-info-box dr-info-natcash" style="<?php echo $dr_first === 'natcash' ? '' : 'display:none;'; ?>">
                <div class="dr-info-main">
                    <div class="dr-method-intro">
                        <span class="dr-method-icon" aria-hidden="true">⚡</span>
                        <div>
                            <p class="dr-info-eyebrow">Méthode sélectionnée</p>
                            <p class="dr-info-title">Natcash Automatique</p>
                            <p>Entrez le montant et votre numéro, puis envoyez la demande. Vous recevrez un code à 6 chiffres pour terminer le transfert.</p>
                        </div>
                    </div>
                    <div class="dr-pay-card dr-account-card">
                        <div class="dr-account-row">
                            <span class="dr-account-icon dr-account-emoji" aria-hidden="true">📞</span>
                            <div class="dr-account-copy"><small>Numéro Natcash</small><strong id="dr-nc-num"><?php echo $dr_nc_num; ?></strong></div>
                        </div>
                        <div class="dr-account-divider"></div>
                        <div class="dr-account-row">
                            <span class="dr-account-icon dr-account-emoji" aria-hidden="true">👤</span>
                            <div class="dr-account-copy"><small>Nom enregistré</small><strong id="dr-nc-nom" class="dr-name-mask" data-full="<?php echo esc_attr( $dr_nc_nom_full ); ?>" data-masked="<?php echo esc_attr( delicat_mask_name( $dr_nc_nom_full ) ); ?>"><?php echo $dr_nc_nom_masked; ?></strong></div>
                            <button type="button" class="dr-eye" data-target="dr-nc-nom" title="Afficher / masquer le nom" aria-label="Afficher ou masquer le nom"><span aria-hidden="true">👁</span></button>
                        </div>
                    </div>
                </div>
                <p class="dr-manual-badge"><span aria-hidden="true">⏱</span> Validation automatique en quelques secondes après réception du SMS.</p>
            </div>

            <div class="dr-info-box dr-info-natcash_tc" style="<?php echo $dr_first === 'natcash_tc' ? '' : 'display:none;'; ?>">
                <div class="dr-info-main">
                    <div class="dr-method-intro">
                        <span class="dr-method-icon dr-method-icon-auto" aria-hidden="true">
                            <svg viewBox="0 0 24 24"><path d="M13.3 2 5 13h6l-.3 9L19 11h-6l.3-9Z"/></svg>
                        </span>
                        <div>
                            <div class="dr-intro-topline"><p class="dr-info-eyebrow">Méthode sélectionnée</p><span class="dr-auto-chip">AUTO</span></div>
                            <p class="dr-info-title">Natcash Auto (TC)</p>
                            <p>Effectuez votre transfert Natcash, puis entrez le <strong>TransCode</strong> reçu par SMS. La vérification se fait automatiquement avec le montant exact.</p>
                        </div>
                    </div>

                    <details class="dr-how-card">
                        <summary class="dr-section-title"><span>Comment ça marche ?</span><span class="dr-how-chevron" aria-hidden="true">⌄</span></summary>
                        <div class="dr-steps">
                            <div class="dr-step"><span class="dr-step-num">1</span><span class="dr-step-icon" aria-hidden="true">↗</span><strong>Transférez</strong><small>Envoyez le montant au numéro Natcash.</small></div>
                            <div class="dr-step-line" aria-hidden="true"></div>
                            <div class="dr-step"><span class="dr-step-num">2</span><span class="dr-step-icon" aria-hidden="true">💬</span><strong>TransCode</strong><small>Entrez le code reçu par SMS.</small></div>
                            <div class="dr-step-line" aria-hidden="true"></div>
                            <div class="dr-step"><span class="dr-step-num">3</span><span class="dr-step-icon" aria-hidden="true">✓</span><strong>Recharge auto</strong><small>Validation automatique du compte.</small></div>
                        </div>
                    </details>

                    <div class="dr-pay-card dr-account-card">
                        <div class="dr-account-row">
                            <span class="dr-account-icon dr-account-emoji" aria-hidden="true">📞</span>
                            <div class="dr-account-copy"><small>Numéro Natcash (TC)</small><strong><?php echo $dr_nc_num; ?></strong></div>
                            <button type="button" class="dr-copy-btn" data-dr-copy="<?php echo esc_attr( $dr_nc_num ); ?>" aria-label="Copier le numéro Natcash"><span aria-hidden="true">⧉</span><span class="dr-copy-label">Copier</span></button>
                        </div>
                        <div class="dr-account-divider"></div>
                        <div class="dr-account-row">
                            <span class="dr-account-icon dr-account-emoji" aria-hidden="true">👤</span>
                            <div class="dr-account-copy"><small>Nom enregistré</small><strong id="dr-nc-nom-tc" class="dr-name-mask" data-full="<?php echo esc_attr( $dr_nc_nom_full ); ?>" data-masked="<?php echo esc_attr( delicat_mask_name( $dr_nc_nom_full ) ); ?>"><?php echo $dr_nc_nom_masked; ?></strong></div>
                            <button type="button" class="dr-eye" data-target="dr-nc-nom-tc" title="Afficher / masquer le nom" aria-label="Afficher ou masquer le nom"><span aria-hidden="true">👁</span></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <section class="dr-fields-card" aria-label="Informations de recharge">
            <div class="dr-fields-head">
                <div>
                    <span class="dr-fields-eyebrow">Dernière étape</span>
                    <h3>Informations de recharge</h3>
                </div>
                <div class="dr-live-method" aria-live="polite">
                    <span class="dr-live-method-tc">⚡ Natcash Auto</span>
                    <span class="dr-live-method-moncash">MonCash</span>
                    <span class="dr-live-method-natcash">Natcash</span>
                </div>
            </div>

            <div class="dr-field dr-field-methode" style="display:none;">
                <label for="dr-methode">Méthode de paiement</label>
                <select id="dr-methode">
                    <option value="moncash">Moncash</option>
                    <option value="natcash">Natcash</option>
                    <option value="virement">Virement bancaire</option>
                    <option value="autre">Autre</option>
                </select>
            </div>

            <?php if ( ! is_user_logged_in() ) : ?>
                <div class="dr-field">
                    <label for="dr-email">Votre email <span class="dr-req">*</span></label>
                    <div class="dr-control dr-control-email">
                        <span class="dr-control-icon" aria-hidden="true">@</span>
                        <input type="email" id="dr-email" placeholder="votre@email.com" autocomplete="email">
                    </div>
                </div>
            <?php endif; ?>

            <div class="dr-field">
                <label for="dr-montant">Montant à recharger <span class="dr-req">*</span></label>
                <div class="dr-input-group">
                    <input type="number" id="dr-montant" placeholder="Ex : 500" min="1" max="100000" step="any" inputmode="decimal">
                    <span class="dr-currency">HTG</span>
                </div>
            </div>

            <div class="dr-field dr-field-trans"<?php echo $dr_first === 'natcash' ? ' style="display:none;"' : ''; ?>>
                <label for="dr-trans">TransCode / Txn ID <span class="dr-req">*</span></label>
                <div class="dr-control dr-control-code">
                    <span class="dr-control-icon" aria-hidden="true">#</span>
                    <input type="text" id="dr-trans" placeholder="Ex : 1223445566777" autocomplete="off" inputmode="numeric">
                </div>
                <details class="dr-help">
                    <summary><span aria-hidden="true">?</span> Où trouver mon TransCode ?</summary>
                    <div class="dr-help-body">
                        <p class="dr-help-tc">Après votre transfert Natcash, ouvrez le SMS de confirmation et recopiez le TransCode affiché.</p>
                        <p class="dr-help-moncash">Utilisez le TransCode / identifiant de transaction affiché dans votre confirmation MonCash.</p>
                    </div>
                </details>
            </div>

            <div class="dr-field dr-field-natcash"<?php echo $dr_first === 'natcash' ? '' : ' style="display:none;"'; ?>>
                <label for="dr-nc-sender">Votre numéro Natcash (celui qui envoie) <span class="dr-req">*</span></label>
                <div class="dr-control dr-control-phone">
                    <span class="dr-control-icon" aria-hidden="true">☎</span>
                    <input type="tel" id="dr-nc-sender" placeholder="Ex : 43180354" inputmode="tel" autocomplete="tel">
                </div>
            </div>

            <button type="button" class="dr-submit" id="dr-submit">
                <span class="dr-btn-label">
                    <span class="dr-submit-icon" aria-hidden="true">↗</span>
                    <span class="dr-submit-copy"><strong>Envoyer la demande</strong><small>Validation sécurisée et rapide</small></span>
                </span>
                <span class="dr-btn-spin" style="display:none;">⏳ Envoi en cours…</span>
            </button>

            <div class="dr-form-security">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2.8 20 6v5.6c0 4.8-3.1 8.4-8 9.8-4.9-1.4-8-5-8-9.8V6l8-3.2Zm-1.1 12.5 5.2-5.2-1.4-1.4-3.8 3.8-1.8-1.8-1.4 1.4 3.2 3.2Z"/></svg>
                <span>Connexion sécurisée • Vos données sont protégées.</span>
            </div>
        </section>
    </div>
</div>

<!-- UI-only success confirmation. It is opened only after the existing backend
     confirms a real wallet credit (or when the existing live-balance watcher
     detects the credit). No payment / wallet logic is implemented here. -->
<div class="dr-success-modal" id="dr-success-modal" aria-hidden="true">
    <button type="button" class="dr-success-backdrop" data-dr-success-close aria-label="Fermer la confirmation"></button>
    <section class="dr-success-dialog" role="dialog" aria-modal="true" aria-labelledby="dr-success-title" aria-describedby="dr-success-text" tabindex="-1">
        <div class="dr-success-check" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="m9.2 16.6-4-4 1.8-1.8 2.2 2.2 7.8-7.8L18.8 7 9.2 16.6Z"/></svg>
        </div>
        <p class="dr-success-kicker">Wallet mis à jour</p>
        <h3 id="dr-success-title">Recharge réussie !</h3>
        <p id="dr-success-text" class="dr-success-text">Vous avez ajouté <strong><span id="dr-success-amount">0</span> <span id="dr-success-currency">HTG</span></strong> à votre compte.</p>
        <p class="dr-success-balance" id="dr-success-balance" hidden></p>
        <button type="button" class="dr-success-done" data-dr-success-close>Parfait</button>
    </section>
</div>

