<?php
defined( 'ABSPATH' ) || exit;

add_action( 'admin_enqueue_scripts', static function ( $hook ) {
    if ( false === strpos( (string) $hook, 'delicat-identity-emails' ) ) { return; }
    wp_enqueue_media();
    wp_enqueue_style( 'wp-color-picker' );
    wp_enqueue_style( 'desp-admin', DIPES_URL . 'assets/admin.css', array( 'wp-color-picker' ), DIPES_VERSION );
    wp_enqueue_script( 'jquery-ui-sortable' );
    wp_enqueue_script( 'desp-admin', DIPES_URL . 'assets/admin.js', array( 'jquery', 'wp-color-picker', 'jquery-ui-sortable' ), DIPES_VERSION, true );
    wp_localize_script( 'desp-admin', 'DIPES_ADMIN', array(
        'previewUrl' => admin_url( 'admin-post.php' ),
        'previewNonce' => wp_create_nonce( 'dipes_email_preview' ),
        'presets' => dipes_presets(),
    ) );
} );

function dipes_field_name( $key ) { return DIPES_OPTION . '[' . $key . ']'; }
function dipes_email_field_name( $id, $key ) { return DIPES_OPTION . '[emails][' . $id . '][' . $key . ']'; }

function dipes_toggle( $key, $label, $desc = '' ) {
    $value = dipes_get( $key );
    ?>
    <label class="desp-toggle-row">
        <span><strong><?php echo esc_html( $label ); ?></strong><?php if ( $desc ) : ?><small><?php echo esc_html( $desc ); ?></small><?php endif; ?></span>
        <span class="desp-switch"><input type="checkbox" name="<?php echo esc_attr( dipes_field_name( $key ) ); ?>" value="yes" <?php checked( $value, 'yes' ); ?> data-desp-global="<?php echo esc_attr( $key ); ?>"><i></i></span>
    </label>
    <?php
}

function dipes_color_field( $key, $label ) {
    ?><label class="desp-field"><span><?php echo esc_html( $label ); ?></span><input class="desp-color" type="text" name="<?php echo esc_attr( dipes_field_name( $key ) ); ?>" value="<?php echo esc_attr( dipes_get( $key ) ); ?>" data-desp-global="<?php echo esc_attr( $key ); ?>"></label><?php
}

function dipes_email_toggle_select( $id, $key, $label ) {
    $v = dipes_email_setting( $id, $key, 'inherit' );
    ?>
    <label class="desp-field"><span><?php echo esc_html( $label ); ?></span>
        <select name="<?php echo esc_attr( dipes_email_field_name( $id, $key ) ); ?>" data-desp-email="<?php echo esc_attr( $key ); ?>">
            <option value="inherit" <?php selected( $v, 'inherit' ); ?>>Selon le réglage global</option>
            <option value="yes" <?php selected( $v, 'yes' ); ?>>Afficher</option>
            <option value="no" <?php selected( $v, 'no' ); ?>>Masquer</option>
        </select>
    </label>
    <?php
}

function dipes_admin_page() {
    if ( ! dipes_admin_capable() ) { return; }
    $settings = dipes_settings();
    $emails = dipes_email_types();
    $presets = dipes_presets();
    $test = isset( $_GET['dipes_test'] ) ? sanitize_key( wp_unslash( $_GET['dipes_test'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    ?>
    <div class="wrap desp-wrap">
        <div class="desp-topbar">
            <div>
                <h1>Delicat Identity — Email Studio <span>v<?php echo esc_html( DIPES_VERSION ); ?></span></h1>
                <p>Un seul système synchronisé pour WooCommerce, WordPress et les notifications de sécurité Delicat Identity, sans modifier les déclencheurs ni les destinataires.</p>
            </div>
            <div class="desp-top-actions">
                <a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=dipes_export_settings' ), 'dipes_export_settings' ) ); ?>">Exporter la configuration</a>
                <button form="desp-main-form" class="button button-primary button-hero" type="submit">Enregistrer</button>
            </div>
        </div>

        <?php if ( 'ok' === $test ) : ?><div class="notice notice-success is-dismissible"><p>E-mail de test envoyé avec succès.</p></div><?php endif; ?>
        <?php if ( 'fail' === $test ) : ?><div class="notice notice-error is-dismissible"><p>L’e-mail de test n’a pas pu être envoyé. Vérifiez votre SMTP / wp_mail.</p></div><?php endif; ?>

        <?php
        $block_editor = false;
        if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
            $block_editor = \Automattic\WooCommerce\Utilities\FeaturesUtil::feature_is_enabled( 'block_email_editor' );
        }
        if ( $block_editor ) : ?>
            <div class="notice notice-warning"><p><strong>Éditeur d’e-mails WooCommerce expérimental détecté.</strong> Pour un rendu 100% déterministe avec Delicat Email Studio, désactivez le « Block Email Editor » dans WooCommerce → Réglages → Avancé → Fonctionnalités.</p></div>
        <?php endif; ?>

        <div class="desp-workspace">
            <form method="post" action="options.php" id="desp-main-form" class="desp-sidebar">
                <?php settings_fields( 'dipes_settings_group' ); ?>

                <section class="desp-card desp-card-email-picker">
                    <div class="desp-card-head"><div><b>E-mail à personnaliser</b><small>Chaque type peut avoir son propre texte, couleur et blocs.</small></div></div>
                    <select id="desp-email-picker" class="desp-email-picker">
                        <?php foreach ( $emails as $id => $label ) : ?><option value="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                    </select>
                </section>

                <div class="desp-tabs" role="tablist">
                    <button type="button" class="is-active" data-desp-tab="design">Design</button>
                    <button type="button" data-desp-tab="branding">Marque</button>
                    <button type="button" data-desp-tab="content">Contenu</button>
                    <button type="button" data-desp-tab="advanced">Avancé</button>
                </div>

                <div class="desp-tab-panel is-active" data-desp-panel="design">
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Styles prêts à l’emploi</b><small>Choisissez une base puis modifiez chaque détail.</small></div></div>
                        <div class="desp-presets">
                            <?php foreach ( $presets as $key => $preset ) : ?>
                                <label class="desp-preset <?php echo $settings['preset'] === $key ? 'is-active' : ''; ?>" data-preset="<?php echo esc_attr( $key ); ?>">
                                    <input type="radio" name="<?php echo esc_attr( dipes_field_name( 'preset' ) ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $settings['preset'], $key ); ?> data-desp-global="preset">
                                    <span class="desp-swatch"><?php foreach ( $preset['swatch'] as $c ) : ?><i style="background:<?php echo esc_attr( $c ); ?>"></i><?php endforeach; ?></span>
                                    <strong><?php echo esc_html( $preset['label'] ); ?></strong><small><?php echo esc_html( $preset['desc'] ); ?></small>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </section>

                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Couleurs</b><small>Couleurs compatibles avec l’inlining WooCommerce.</small></div></div>
                        <div class="desp-grid-2">
                            <?php dipes_color_field( 'page_bg', 'Fond extérieur' ); dipes_color_field( 'container_bg', 'Fond de l’e-mail' ); ?>
                            <?php dipes_color_field( 'header_bg', 'Fond en-tête' ); dipes_color_field( 'header_text', 'Texte en-tête' ); ?>
                            <?php dipes_color_field( 'heading', 'Titres' ); dipes_color_field( 'text', 'Texte principal' ); ?>
                            <?php dipes_color_field( 'muted', 'Texte secondaire' ); dipes_color_field( 'border', 'Bordures' ); ?>
                            <?php dipes_color_field( 'accent', 'Accent global' ); dipes_color_field( 'accent_soft', 'Accent léger' ); ?>
                            <?php dipes_color_field( 'button_bg', 'Boutons' ); dipes_color_field( 'button_text', 'Texte bouton' ); ?>
                            <?php dipes_color_field( 'card_bg', 'Fond des cartes' ); dipes_color_field( 'card_border', 'Bordure des cartes' ); ?>
                            <?php dipes_color_field( 'table_header_bg', 'En-tête tableau' ); dipes_color_field( 'table_header_text', 'Texte tableau' ); ?>
                            <?php dipes_color_field( 'footer_bg', 'Fond pied de page' ); dipes_color_field( 'footer_text_color', 'Texte pied de page' ); ?>
                        </div>
                    </section>

                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Mise en page et typographie</b><small>Contrôle complet des proportions.</small></div></div>
                        <div class="desp-grid-2">
                            <label class="desp-field"><span>Disposition en-tête</span><select name="<?php echo esc_attr( dipes_field_name( 'header_layout' ) ); ?>" data-desp-global="header_layout"><option value="banner" <?php selected( $settings['header_layout'], 'banner' ); ?>>Bannière</option><option value="centered" <?php selected( $settings['header_layout'], 'centered' ); ?>>Centré</option><option value="minimal" <?php selected( $settings['header_layout'], 'minimal' ); ?>>Minimal</option></select></label>
                            <label class="desp-field"><span>Largeur de l’e-mail</span><input type="number" min="480" max="760" name="<?php echo esc_attr( dipes_field_name( 'container_width' ) ); ?>" value="<?php echo esc_attr( $settings['container_width'] ); ?>" data-desp-global="container_width"><em>px</em></label>
                            <label class="desp-field"><span>Police du texte</span><select name="<?php echo esc_attr( dipes_field_name( 'font' ) ); ?>" data-desp-global="font"><?php foreach ( array('system'=>'Système','arial'=>'Arial','georgia'=>'Georgia','trebuchet'=>'Trebuchet','verdana'=>'Verdana','mono'=>'Monospace') as $k=>$v ) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($settings['font'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
                            <label class="desp-field"><span>Police des titres</span><select name="<?php echo esc_attr( dipes_field_name( 'heading_font' ) ); ?>" data-desp-global="heading_font"><?php foreach ( array('system'=>'Système','arial'=>'Arial','georgia'=>'Georgia','trebuchet'=>'Trebuchet','verdana'=>'Verdana','mono'=>'Monospace') as $k=>$v ) : ?><option value="<?php echo esc_attr($k); ?>" <?php selected($settings['heading_font'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></label>
                            <?php foreach ( array('body_size'=>'Taille du texte','heading_size'=>'Taille du titre','body_padding'=>'Espacement intérieur','radius'=>'Arrondi principal','card_radius'=>'Arrondi des cartes','button_radius'=>'Arrondi des boutons') as $key=>$label ) : ?><label class="desp-field"><span><?php echo esc_html($label); ?></span><input type="number" name="<?php echo esc_attr( dipes_field_name($key) ); ?>" value="<?php echo esc_attr($settings[$key]); ?>" data-desp-global="<?php echo esc_attr($key); ?>"><em>px</em></label><?php endforeach; ?>
                        </div>
                    </section>

                    <section class="desp-card desp-visual-lab">
                        <div class="desp-card-head"><div><b>Visual Lab — verre & néomorphisme</b><small>Contrôlez la profondeur, le relief et le comportement clair/sombre sans toucher au HTML.</small></div><span class="desp-pro-badge">PREMIUM</span></div>
                        <div class="desp-mode-quick">
                            <button type="button" class="desp-quick-theme" data-apply-preset="glass_light"><span class="desp-mode-dot is-light"></span><b>Glass Light</b><small>Clair premium</small></button>
                            <button type="button" class="desp-quick-theme" data-apply-preset="glass_dark"><span class="desp-mode-dot is-dark"></span><b>Glass Dark</b><small>Sombre premium</small></button>
                        </div>
                        <div class="desp-grid-2">
                            <label class="desp-field"><span>Mode d’apparence</span><select name="<?php echo esc_attr( dipes_field_name( 'appearance_mode' ) ); ?>" data-desp-global="appearance_mode"><option value="auto" <?php selected( $settings['appearance_mode'], 'auto' ); ?>>Auto — suit l’appareil</option><option value="light" <?php selected( $settings['appearance_mode'], 'light' ); ?>>Toujours clair</option><option value="dark" <?php selected( $settings['appearance_mode'], 'dark' ); ?>>Toujours sombre</option></select></label>
                            <label class="desp-field"><span>Effet visuel</span><select name="<?php echo esc_attr( dipes_field_name( 'visual_effect' ) ); ?>" data-desp-global="visual_effect"><option value="glass_neomorph" <?php selected( $settings['visual_effect'], 'glass_neomorph' ); ?>>Glass + Néomorphisme</option><option value="glass" <?php selected( $settings['visual_effect'], 'glass' ); ?>>Glass uniquement</option><option value="neomorph" <?php selected( $settings['visual_effect'], 'neomorph' ); ?>>Néomorphisme uniquement</option><option value="flat" <?php selected( $settings['visual_effect'], 'flat' ); ?>>Flat / aucun effet</option></select></label>
                        </div>
                        <div class="desp-range-grid">
                            <?php foreach ( array( 'effect_strength' => array( 'Intensité générale', 0, 100 ), 'shadow_depth' => array( 'Profondeur des ombres', 0, 100 ), 'glass_highlight' => array( 'Reflet verre', 0, 100 ) ) as $key => $meta ) : ?>
                                <label class="desp-range-field"><span><b><?php echo esc_html( $meta[0] ); ?></b><output data-range-output="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $settings[ $key ] ); ?></output></span><input type="range" min="<?php echo esc_attr( $meta[1] ); ?>" max="<?php echo esc_attr( $meta[2] ); ?>" value="<?php echo esc_attr( $settings[ $key ] ); ?>" name="<?php echo esc_attr( dipes_field_name( $key ) ); ?>" data-desp-global="<?php echo esc_attr( $key ); ?>" data-range-key="<?php echo esc_attr( $key ); ?>"></label>
                            <?php endforeach; ?>
                        </div>
                        <div class="desp-compat-note"><span class="dashicons dashicons-shield-alt"></span><div><b>Compatible e-mail</b><small>Les effets utilisent des dégradés et ombres avec repli sûr. Les clients e-mail qui ignorent le verre affichent toujours des cartes lisibles.</small></div></div>
                    </section>
                </div>

                <div class="desp-tab-panel" data-desp-panel="branding">
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Logo et identité</b><small>Utilisez un logo dédié aux e-mails ou laissez vide pour reprendre celui de WooCommerce / du site.</small></div></div>
                        <label class="desp-field"><span>Nom de marque</span><input type="text" name="<?php echo esc_attr( dipes_field_name('brand_name') ); ?>" value="<?php echo esc_attr($settings['brand_name']); ?>" placeholder="<?php echo esc_attr(dipes_brand_name()); ?>" data-desp-global="brand_name"></label>
                        <label class="desp-field"><span>URL du logo</span><div class="desp-media-row"><input id="desp-logo-url" type="url" name="<?php echo esc_attr( dipes_field_name('logo_url') ); ?>" value="<?php echo esc_attr($settings['logo_url']); ?>" data-desp-global="logo_url"><button type="button" class="button" id="desp-logo-pick">Choisir</button></div></label>
                        <div class="desp-grid-2">
                            <label class="desp-field"><span>Largeur logo</span><input type="number" min="40" max="320" name="<?php echo esc_attr( dipes_field_name('logo_width') ); ?>" value="<?php echo esc_attr($settings['logo_width']); ?>" data-desp-global="logo_width"><em>px</em></label>
                            <label class="desp-field"><span>Alignement logo</span><select name="<?php echo esc_attr( dipes_field_name('logo_align') ); ?>" data-desp-global="logo_align"><option value="left" <?php selected($settings['logo_align'],'left'); ?>>Gauche</option><option value="center" <?php selected($settings['logo_align'],'center'); ?>>Centre</option><option value="right" <?php selected($settings['logo_align'],'right'); ?>>Droite</option></select></label>
                        </div>
                    </section>
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Support client</b><small>Bloc de contact optionnel dans le pied de page.</small></div></div>
                        <?php dipes_toggle('support_enabled','Afficher le bloc support'); ?>
                        <label class="desp-field"><span>Titre</span><input type="text" name="<?php echo esc_attr(dipes_field_name('support_title')); ?>" value="<?php echo esc_attr($settings['support_title']); ?>" data-desp-global="support_title"></label>
                        <label class="desp-field"><span>Description</span><textarea name="<?php echo esc_attr(dipes_field_name('support_text')); ?>" data-desp-global="support_text"><?php echo esc_textarea($settings['support_text']); ?></textarea></label>
                        <div class="desp-grid-2">
                            <label class="desp-field"><span>URL support</span><input type="url" name="<?php echo esc_attr(dipes_field_name('support_url')); ?>" value="<?php echo esc_attr($settings['support_url']); ?>" data-desp-global="support_url"></label>
                            <label class="desp-field"><span>Libellé bouton</span><input type="text" name="<?php echo esc_attr(dipes_field_name('support_label')); ?>" value="<?php echo esc_attr($settings['support_label']); ?>" data-desp-global="support_label"></label>
                            <label class="desp-field"><span>WhatsApp</span><input type="text" name="<?php echo esc_attr(dipes_field_name('whatsapp')); ?>" value="<?php echo esc_attr($settings['whatsapp']); ?>" placeholder="+50933111283" data-desp-global="whatsapp"></label>
                            <label class="desp-field"><span>E-mail support</span><input type="email" name="<?php echo esc_attr(dipes_field_name('support_email')); ?>" value="<?php echo esc_attr($settings['support_email']); ?>" data-desp-global="support_email"></label>
                        </div>
                    </section>
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Pied de page et réseaux</b></div></div>
                        <label class="desp-field"><span>Texte personnalisé</span><textarea name="<?php echo esc_attr(dipes_field_name('footer_custom_text')); ?>" data-desp-global="footer_custom_text"><?php echo esc_textarea($settings['footer_custom_text']); ?></textarea></label>
                        <label class="desp-field"><span>Note courte</span><input type="text" name="<?php echo esc_attr(dipes_field_name('footer_note')); ?>" value="<?php echo esc_attr($settings['footer_note']); ?>" data-desp-global="footer_note"></label>
                        <div class="desp-grid-2">
                            <?php foreach ( array('social_facebook'=>'Facebook URL','social_instagram'=>'Instagram URL','social_tiktok'=>'TikTok URL') as $key=>$label ) : ?><label class="desp-field"><span><?php echo esc_html($label); ?></span><input type="url" name="<?php echo esc_attr(dipes_field_name($key)); ?>" value="<?php echo esc_attr($settings[$key]); ?>" data-desp-global="<?php echo esc_attr($key); ?>"></label><?php endforeach; ?>
                        </div>
                    </section>
                </div>

                <div class="desp-tab-panel" data-desp-panel="content">
                    <?php foreach ( $emails as $id => $label ) : $e = $settings['emails'][$id]; ?>
                    <div class="desp-email-panel <?php echo 'customer_processing_order' === $id ? 'is-active' : ''; ?>" data-email-panel="<?php echo esc_attr($id); ?>">
                        <section class="desp-card">
                            <div class="desp-card-head"><div><b><?php echo esc_html($label); ?></b><small>Variables dynamiques disponibles : {first_name}, {order_number}, {order_total}, {payment_method}, {order_url}, {site_name}…</small></div>
                                <label class="desp-mini-toggle"><input type="checkbox" name="<?php echo esc_attr(dipes_email_field_name($id,'enabled')); ?>" value="yes" <?php checked($e['enabled'],'yes'); ?> data-desp-email="enabled"> Personnalisation active</label>
                            </div>
                            <label class="desp-field"><span>Objet</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'subject')); ?>" value="<?php echo esc_attr($e['subject']); ?>" data-desp-email="subject"></label>
                            <label class="desp-field"><span>Titre principal</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'heading')); ?>" value="<?php echo esc_attr($e['heading']); ?>" data-desp-email="heading"></label>
                            <label class="desp-field"><span>Preheader invisible</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'preheader')); ?>" value="<?php echo esc_attr($e['preheader']); ?>" data-desp-email="preheader"></label>
                            <div class="desp-grid-2">
                                <label class="desp-field"><span>Badge</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'badge')); ?>" value="<?php echo esc_attr($e['badge']); ?>" data-desp-email="badge"></label>
                                <label class="desp-field"><span>Accent de cet e-mail</span><input class="desp-color" type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'accent')); ?>" value="<?php echo esc_attr($e['accent']); ?>" data-desp-email="accent"></label>
                            </div>
                            <label class="desp-field"><span>Titre d’introduction</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'intro_title')); ?>" value="<?php echo esc_attr($e['intro_title']); ?>" data-desp-email="intro_title"></label>
                            <label class="desp-field"><span>Texte d’introduction</span><textarea rows="4" name="<?php echo esc_attr(dipes_email_field_name($id,'intro_text')); ?>" data-desp-email="intro_text"><?php echo esc_textarea($e['intro_text']); ?></textarea></label>
                            <div class="desp-grid-2">
                                <label class="desp-field"><span>Texte du bouton</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'cta_label')); ?>" value="<?php echo esc_attr($e['cta_label']); ?>" data-desp-email="cta_label"></label>
                                <label class="desp-field"><span>URL du bouton</span><input type="text" name="<?php echo esc_attr(dipes_email_field_name($id,'cta_url')); ?>" value="<?php echo esc_attr($e['cta_url']); ?>" data-desp-email="cta_url"></label>
                            </div>
                            <label class="desp-field"><span>Bloc HTML personnalisé</span><textarea rows="5" name="<?php echo esc_attr(dipes_email_field_name($id,'custom_html')); ?>" placeholder="<p>Votre contenu personnalisé...</p>" data-desp-email="custom_html"><?php echo esc_textarea($e['custom_html']); ?></textarea></label>
                        </section>
                        <section class="desp-card">
                            <div class="desp-card-head"><div><b>Visibilité des blocs</b><small>Masquez les sections inutiles pour ce type d’e-mail.</small></div></div>
                            <div class="desp-grid-2">
                                <?php dipes_email_toggle_select($id,'show_summary','Carte récapitulative'); dipes_email_toggle_select($id,'show_progress','Suivi de commande'); dipes_email_toggle_select($id,'show_cta','Bouton principal'); ?>
                                <?php foreach ( array('show_badge'=>'Badge de statut','show_order_details'=>'Détails de commande','show_customer_details'=>'Coordonnées client','show_additional'=>'Contenu additionnel WooCommerce') as $key=>$label2 ) : ?>
                                    <label class="desp-check"><input type="checkbox" name="<?php echo esc_attr(dipes_email_field_name($id,$key)); ?>" value="yes" <?php checked($e[$key],'yes'); ?> data-desp-email="<?php echo esc_attr($key); ?>"> <span><?php echo esc_html($label2); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <?php if ( dipes_is_order_email( $id ) ) : ?>
                        <section class="desp-card">
                            <div class="desp-card-head"><div><b>Ordre des blocs</b><small>Glissez-déposez. Les hooks WooCommerce restent attachés aux blocs correspondants.</small></div></div>
                            <div class="desp-layout-presets" data-layout-email="<?php echo esc_attr($id); ?>"><button type="button" data-block-layout="premium">Premium</button><button type="button" data-block-layout="compact">Compact</button><button type="button" data-block-layout="details_first">Détails d’abord</button></div>
                            <ul class="desp-sortable" data-email="<?php echo esc_attr($id); ?>">
                                <?php $labels = array('intro'=>'Introduction','notice'=>'Notification / note','summary'=>'Récapitulatif','progress'=>'Suivi','cta'=>'Bouton','order_details'=>'Détails commande','order_meta'=>'Métadonnées commande','customer_details'=>'Coordonnées client','additional'=>'Contenu additionnel','custom'=>'Bloc personnalisé'); foreach ( $e['block_order'] as $block ) : ?><li data-block="<?php echo esc_attr($block); ?>"><span class="dashicons dashicons-menu"></span><?php echo esc_html($labels[$block]); ?></li><?php endforeach; ?>
                            </ul>
                            <input class="desp-order-input" type="hidden" name="<?php echo esc_attr(dipes_email_field_name($id,'block_order')); ?>" value="<?php echo esc_attr(implode(',',$e['block_order'])); ?>" data-desp-email="block_order">
                        </section>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="desp-tab-panel" data-desp-panel="advanced">
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Contenu des produits</b><small>Options globales du tableau WooCommerce.</small></div></div>
                        <?php dipes_toggle('show_item_images','Images produits','Affiche la miniature dans les lignes de commande.'); ?>
                        <?php dipes_toggle('show_sku','SKU produits'); ?>
                        <?php dipes_toggle('show_item_meta','Métadonnées produit','Conserve Player ID, variation, champs de recharge et métadonnées ajoutées par les extensions.'); ?>
                        <?php dipes_toggle('product_links','Liens vers les produits'); ?>
                        <label class="desp-field"><span>Taille des images</span><input type="number" min="36" max="96" name="<?php echo esc_attr(dipes_field_name('image_size')); ?>" value="<?php echo esc_attr($settings['image_size']); ?>" data-desp-global="image_size"><em>px</em></label>
                    </section>
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>Comportement</b></div></div>
                        <?php dipes_toggle('show_status_colors','Couleur dynamique par statut'); ?>
                        <?php dipes_toggle('show_summary','Récapitulatif global'); ?>
                        <?php dipes_toggle('show_progress','Suivi global'); ?>
                        <?php dipes_toggle('show_cta','Bouton global'); ?>
                        <?php dipes_toggle('dark_mode','Déclarer la compatibilité mode sombre'); ?>
                    </section>
                    <section class="desp-card">
                        <div class="desp-card-head"><div><b>CSS e-mail personnalisé</b><small>Pour les réglages avancés. Évitez JavaScript, @import et polices distantes.</small></div></div>
                        <label class="desp-field"><textarea class="desp-code" rows="10" name="<?php echo esc_attr(dipes_field_name('custom_css')); ?>" data-desp-global="custom_css"><?php echo esc_textarea($settings['custom_css']); ?></textarea></label>
                    </section>
                </div>
            </form>

            <aside class="desp-preview-column">
                <div class="desp-preview-toolbar">
                    <div class="desp-device-buttons" aria-label="Appareil"><button type="button" class="is-active" data-device="desktop" title="Desktop"><span class="dashicons dashicons-desktop"></span></button><button type="button" data-device="mobile" title="Mobile"><span class="dashicons dashicons-smartphone"></span></button></div>
                    <div class="desp-preview-theme-buttons" aria-label="Mode aperçu"><button type="button" class="is-active" data-preview-theme="current">Auto</button><button type="button" data-preview-theme="light">Light</button><button type="button" data-preview-theme="dark">Dark</button></div>
                    <span id="desp-preview-status">Aperçu en direct</span>
                    <button type="button" class="button" id="desp-refresh-preview">Actualiser</button>
                </div>
                <div class="desp-preview-stage"><iframe id="desp-preview-frame" title="Aperçu e-mail"></iframe></div>
                <form class="desp-test-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <input type="hidden" name="action" value="dipes_email_test"><?php wp_nonce_field('dipes_email_test'); ?>
                    <input id="desp-test-type" type="hidden" name="type" value="customer_processing_order">
                    <label><span>Envoyer un test</span><input type="email" name="to" value="<?php echo esc_attr(get_option('admin_email')); ?>" required><button class="button" type="submit">Envoyer</button></label>
                </form>
            </aside>
        </div>
    </div>
    <?php
}

add_action( 'admin_post_dipes_export_settings', static function () {
    if ( ! dipes_admin_capable() ) { wp_die( 'Accès refusé.' ); }
    check_admin_referer( 'dipes_export_settings' );
    nocache_headers();
    header( 'Content-Type: application/json; charset=utf-8' );
    header( 'Content-Disposition: attachment; filename="delicat-email-studio-settings-' . gmdate('Y-m-d') . '.json"' );
    echo wp_json_encode( dipes_settings(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
} );
