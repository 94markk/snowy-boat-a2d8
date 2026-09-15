<?php
/**
 * Delicat Identity Pro — Visual Authentication Builder.
 */
defined('ABSPATH') || exit;

final class DIP_Visual_Builder_Pro {
    const OPTION = 'dip_visual_builder_pro';

    public static function init() {
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_init', [__CLASS__, 'register']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);
        add_action('wp_enqueue_scripts', [__CLASS__, 'maybe_front_assets']);
        add_action('login_enqueue_scripts', [__CLASS__, 'front_assets']);
        add_shortcode('delicat_auth_panel', [__CLASS__, 'shortcode']);
        add_action('admin_post_dip_builder_export', [__CLASS__, 'export']);
        add_action('admin_post_dip_builder_import', [__CLASS__, 'import']);
        add_action('admin_post_dip_builder_reset', [__CLASS__, 'reset']);
    }

    public static function defaults() {
        return [
            'preset' => 'delicat-glass',
            'layout' => 'centered',
            'title' => 'Bienvenue chez Delicat',
            'subtitle' => 'Connectez-vous rapidement et en toute sécurité.',
            'background' => 'gradient',
            'background_color' => '#07142b',
            'gradient_start' => '#07142b',
            'gradient_end' => '#123f7a',
            'gradient_angle' => 145,
            'background_image' => '',
            'background_position' => 'center',
            'overlay_color' => '#07142b',
            'overlay_opacity' => 18,
            'card_color' => '#ffffff',
            'card_opacity' => 100,
            'text_color' => '#10213b',
            'muted_color' => '#667085',
            'accent_color' => '#155eef',
            'accent_hover' => '#0f4fcf',
            'border_color' => '#dfe7f2',
            'input_background' => '#ffffff',
            'input_border' => '#d8dee9',
            'input_text' => '#10213b',
            'provider_background' => '#ffffff',
            'provider_text' => '#172033',
            'radius' => 26,
            'input_radius' => 14,
            'button_radius' => 14,
            'max_width' => 460,
            'padding' => 32,
            'card_border_width' => 1,
            'card_blur' => 0,
            'shadow' => 'soft',
            'motion' => 'subtle',
            'heading_size' => 28,
            'body_size' => 15,
            'font_weight' => 800,
            'text_align' => 'left',
            'logo_size' => 52,
            'logo_url' => '',
            'button_height' => 50,
            'field_gap' => 10,
            'shell_min_height' => 620,
            'card_section_gap' => 18,
            'provider_gap' => 10,
            'divider_gap' => 18,
            'input_height' => 50,
            'provider_border_width' => 1,
            'provider_icon_size' => 18,
            'button_font_size' => 15,
            'link_font_size' => 14,
            'show_native' => 'yes',
            'show_register' => 'yes',
            'show_lost_password' => 'yes',
            'show_remember' => 'yes',
            'mobile_bottom_sheet' => 'no',
        ];
    }

    public static function settings() {
        return wp_parse_args((array) get_option(self::OPTION, []), self::defaults());
    }

    public static function register() {
        register_setting('dip_builder_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [__CLASS__, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    private static function clamp($value, $min, $max, $fallback) {
        $value = is_numeric($value) ? (int) $value : $fallback;
        return min($max, max($min, $value));
    }

    public static function sanitize($input) {
        $old = self::settings();
        $in  = is_array($input) ? $input : [];
        $out = $old;

        $allowed = [
            'preset' => ['delicat-glass','delicat-dark','minimal','corporate','gaming','premium','soft-light','midnight','aurora','commerce'],
            'layout' => ['centered','split','side-panel','fullscreen','floating'],
            'background' => ['solid','gradient','image'],
            'background_position' => ['center','top','bottom','left','right'],
            'shadow' => ['none','soft','strong','glow'],
            'motion' => ['none','subtle','float','scale'],
            'text_align' => ['left','center'],
        ];
        foreach ($allowed as $key => $values) {
            $out[$key] = in_array($in[$key] ?? '', $values, true) ? $in[$key] : $old[$key];
        }

        foreach (['title','subtitle'] as $key) {
            $out[$key] = sanitize_text_field($in[$key] ?? $old[$key]);
        }
        foreach (['background_color','gradient_start','gradient_end','overlay_color','card_color','text_color','muted_color','accent_color','accent_hover','border_color','input_background','input_border','input_text','provider_background','provider_text'] as $key) {
            $out[$key] = sanitize_hex_color($in[$key] ?? '') ?: $old[$key];
        }

        $out['background_image'] = esc_url_raw($in['background_image'] ?? '');
        $out['logo_url'] = esc_url_raw($in['logo_url'] ?? '');
        $ranges = [
            'gradient_angle' => [0,360,145], 'overlay_opacity' => [0,90,18], 'card_opacity' => [55,100,100],
            'radius' => [0,60,26], 'input_radius' => [0,40,14], 'button_radius' => [0,40,14],
            'max_width' => [320,760,460], 'padding' => [16,72,32], 'card_border_width' => [0,4,1],
            'card_blur' => [0,30,0], 'heading_size' => [20,46,28], 'body_size' => [12,20,15],
            'font_weight' => [500,900,800], 'logo_size' => [36,90,52], 'button_height' => [42,64,50],
            'field_gap' => [4,24,10],
            'shell_min_height' => [480,980,620], 'card_section_gap' => [8,36,18],
            'provider_gap' => [4,24,10], 'divider_gap' => [8,36,18], 'input_height' => [42,68,50],
            'provider_border_width' => [0,4,1], 'provider_icon_size' => [14,30,18],
            'button_font_size' => [12,22,15], 'link_font_size' => [11,20,14],
        ];
        foreach ($ranges as $key => $range) {
            $out[$key] = self::clamp($in[$key] ?? $old[$key], $range[0], $range[1], $range[2]);
        }
        foreach (['show_native','show_register','show_lost_password','show_remember','mobile_bottom_sheet'] as $key) {
            $out[$key] = !empty($in[$key]) ? 'yes' : 'no';
        }
        return $out;
    }

    public static function menu() {
        add_options_page('Identity Visual Builder', 'Identity Builder', 'manage_options', 'delicat-identity-builder', [__CLASS__, 'page']);
    }

    public static function admin_assets($hook = '') {
        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if ($page !== 'delicat-identity-builder') return;
        wp_enqueue_media();
        wp_enqueue_style('dip-builder-front', DIP_URL . 'assets/visual-builder-front.css', [], DIP_VERSION);
        wp_enqueue_style('dip-builder-pro', DIP_URL . 'assets/visual-builder-pro.css', ['dip-builder-front'], DIP_VERSION);
        wp_enqueue_script('dip-builder-pro', DIP_URL . 'assets/visual-builder-pro.js', ['jquery'], DIP_VERSION, true);
        wp_localize_script('dip-builder-pro', 'dipBuilderPro', [
            'option' => self::OPTION,
            'chooseImage' => __('Choisir une image', 'delicat-google-login'),
            'useImage' => __('Utiliser cette image', 'delicat-google-login'),
        ]);
    }

    public static function maybe_front_assets() {
        if (is_admin()) return;
        global $post;
        if ($post instanceof WP_Post && has_shortcode((string) $post->post_content, 'delicat_auth_panel')) {
            self::front_assets();
        }
    }

    public static function front_assets() {
        if (is_admin()) return;
        wp_enqueue_style('dip-builder-front', DIP_URL . 'assets/visual-builder-front.css', [], DIP_VERSION);
        self::inline_css();
    }

    private static function hex_rgba($hex, $opacity) {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) !== 6) return 'rgba(255,255,255,1)';
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        return sprintf('rgba(%d,%d,%d,%.2F)', $r, $g, $b, max(0, min(100, (int) $opacity)) / 100);
    }

    private static function css_vars($s) {
        return implode('', [
            '--dipb-bg:' . esc_attr($s['background_color']) . ';',
            '--dipb-g1:' . esc_attr($s['gradient_start']) . ';',
            '--dipb-g2:' . esc_attr($s['gradient_end']) . ';',
            '--dipb-angle:' . absint($s['gradient_angle']) . 'deg;',
            '--dipb-bg-image:url("' . esc_url($s['background_image']) . '");',
            '--dipb-overlay:' . esc_attr(self::hex_rgba($s['overlay_color'], $s['overlay_opacity'])) . ';',
            '--dipb-card:' . esc_attr(self::hex_rgba($s['card_color'], $s['card_opacity'])) . ';',
            '--dipb-text:' . esc_attr($s['text_color']) . ';',
            '--dipb-muted:' . esc_attr($s['muted_color']) . ';',
            '--dipb-accent:' . esc_attr($s['accent_color']) . ';',
            '--dipb-accent-hover:' . esc_attr($s['accent_hover']) . ';',
            '--dipb-border:' . esc_attr($s['border_color']) . ';',
            '--dipb-input-bg:' . esc_attr($s['input_background']) . ';',
            '--dipb-input-border:' . esc_attr($s['input_border']) . ';',
            '--dipb-input-text:' . esc_attr($s['input_text']) . ';',
            '--dipb-provider-bg:' . esc_attr($s['provider_background']) . ';',
            '--dipb-provider-text:' . esc_attr($s['provider_text']) . ';',
            '--dipb-radius:' . absint($s['radius']) . 'px;',
            '--dipb-input-radius:' . absint($s['input_radius']) . 'px;',
            '--dipb-button-radius:' . absint($s['button_radius']) . 'px;',
            '--dipb-width:' . absint($s['max_width']) . 'px;',
            '--dipb-padding:' . absint($s['padding']) . 'px;',
            '--dipb-border-width:' . absint($s['card_border_width']) . 'px;',
            '--dipb-blur:' . absint($s['card_blur']) . 'px;',
            '--dipb-heading:' . absint($s['heading_size']) . 'px;',
            '--dipb-body:' . absint($s['body_size']) . 'px;',
            '--dipb-weight:' . absint($s['font_weight']) . ';',
            '--dipb-logo-size:' . absint($s['logo_size']) . 'px;',
            '--dipb-button-height:' . absint($s['button_height']) . 'px;',
            '--dipb-field-gap:' . absint($s['field_gap']) . 'px;',
            '--dipb-shell-height:' . absint($s['shell_min_height']) . 'px;',
            '--dipb-section-gap:' . absint($s['card_section_gap']) . 'px;',
            '--dipb-provider-gap:' . absint($s['provider_gap']) . 'px;',
            '--dipb-divider-gap:' . absint($s['divider_gap']) . 'px;',
            '--dipb-input-height:' . absint($s['input_height']) . 'px;',
            '--dipb-provider-border-width:' . absint($s['provider_border_width']) . 'px;',
            '--dipb-provider-icon-size:' . absint($s['provider_icon_size']) . 'px;',
            '--dipb-button-font-size:' . absint($s['button_font_size']) . 'px;',
            '--dipb-link-font-size:' . absint($s['link_font_size']) . 'px;',
        ]);
    }

    private static function inline_css() {
        wp_add_inline_style('dip-builder-front', '.dip-auth-shell{' . self::css_vars(self::settings()) . '}');
    }

    private static function range_field($s, $key, $label, $min, $max, $unit = 'px') {
        printf(
            '<label class="dipb-field"><span>%1$s <output data-output-for="%2$s">%3$s%4$s</output></span><input data-dipb type="range" min="%5$d" max="%6$d" name="%7$s[%2$s]" value="%3$d"></label>',
            esc_html($label), esc_attr($key), absint($s[$key]), esc_html($unit), absint($min), absint($max), esc_attr(self::OPTION)
        );
    }

    private static function color_field($s, $key, $label) {
        printf('<label class="dipb-color"><span>%1$s</span><span class="dipb-color-control"><input data-dipb type="color" name="%2$s[%3$s]" value="%4$s"><code data-color-code>%4$s</code></span></label>', esc_html($label), esc_attr(self::OPTION), esc_attr($key), esc_attr($s[$key]));
    }

    private static function registration_available() {
        $identity = (array) get_option(DIP_Plugin::OPTION, []);
        if (($identity['allow_registration'] ?? 'yes') !== 'yes') return false;
        if (class_exists('DIP_Account_Sync')) return DIP_Account_Sync::storefront_registration_enabled();
        return (bool) get_option('users_can_register')
            || 'yes' === get_option('woocommerce_enable_myaccount_registration')
            || 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout');
    }

    private static function registration_destination() {
        if (!self::registration_available()) return ['', false];
        $identity = (array) get_option(DIP_Plugin::OPTION, []);
        if (($identity['native_modal_enabled'] ?? 'yes') === 'yes') return ['#delicat-login', true];
        if (function_exists('wc_get_page_permalink') && 'yes' === get_option('woocommerce_enable_myaccount_registration')) {
            $url = wc_get_page_permalink('myaccount');
            if ($url) return [$url, false];
        }
        if (get_option('users_can_register')) return [wp_registration_url(), false];
        if (function_exists('wc_get_checkout_url') && 'yes' === get_option('woocommerce_enable_signup_and_login_from_checkout')) return [wc_get_checkout_url(), false];
        return ['', false];
    }

    public static function page() {
        if (!current_user_can('manage_options')) return;
        $s = self::settings();
        $registration_available = self::registration_available();
        $presets = [
            'delicat-glass'=>'Delicat Glass','delicat-dark'=>'Delicat Dark','minimal'=>'Minimal','corporate'=>'Corporate','gaming'=>'Gaming','premium'=>'Premium','soft-light'=>'Soft Light','midnight'=>'Midnight','aurora'=>'Aurora','commerce'=>'Commerce Pro'
        ];
        ?>
        <div class="wrap dipb-admin">
            <section class="dipb-hero">
                <div><span>VISUAL BUILDER 2.0</span><h1>Designer l’expérience de connexion</h1><p>Personnalisez chaque détail avec un aperçu instantané, sans toucher au moteur OAuth.</p></div>
                <div class="dipb-hero-actions"><button type="button" class="button" id="dipb-reset-preview">Annuler les changements</button><button type="submit" form="dipb-form" class="button button-primary">Enregistrer le design</button></div>
            </section>
            <?php settings_errors(); ?>
            <form method="post" action="options.php" id="dipb-form">
                <?php settings_fields('dip_builder_group'); ?>
                <div class="dipb-workspace">
                    <aside class="dipb-controls">
                        <div class="dipb-control-tabs" role="tablist">
                            <button type="button" class="is-active" data-builder-tab="presets">Modèle</button>
                            <button type="button" data-builder-tab="colors">Couleurs</button>
                            <button type="button" data-builder-tab="shape">Forme</button>
                            <button type="button" data-builder-tab="content">Contenu</button>
                        </div>

                        <div class="dipb-tab-panel is-active" data-builder-panel="presets">
                            <h2>Style général</h2>
                            <label class="dipb-field"><span>Preset</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[preset]"><?php foreach ($presets as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s['preset'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
                            <label class="dipb-field"><span>Disposition</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[layout]"><?php foreach(['centered'=>'Carte centrée','split'=>'Écran partagé','side-panel'=>'Panneau latéral','fullscreen'=>'Plein écran','floating'=>'Carte flottante'] as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s['layout'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
                            <label class="dipb-field"><span>Type de fond</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[background]"><?php foreach(['gradient'=>'Dégradé','solid'=>'Couleur unie','image'=>'Image'] as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s['background'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
                            <label class="dipb-field"><span>Ombre</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[shadow]"><?php foreach(['none'=>'Aucune','soft'=>'Douce','strong'=>'Forte','glow'=>'Glow'] as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s['shadow'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
                            <label class="dipb-field"><span>Animation</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[motion]"><?php foreach(['none'=>'Aucune','subtle'=>'Entrée douce','float'=>'Flottement','scale'=>'Zoom léger'] as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s['motion'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
                            <label class="dipb-field"><span>Image de fond</span><div class="dipb-media-row"><input data-dipb name="<?php echo esc_attr(self::OPTION); ?>[background_image]" value="<?php echo esc_attr($s['background_image']); ?>" placeholder="https://"><button type="button" class="button dipb-media-select" data-target="background_image">Choisir</button></div></label>
                            <label class="dipb-field"><span>Position de l’image</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[background_position]"><?php foreach(['center'=>'Centre','top'=>'Haut','bottom'=>'Bas','left'=>'Gauche','right'=>'Droite'] as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s['background_position'],$v,false).'>'.esc_html($l).'</option>'; ?></select></label>
                        </div>

                        <div class="dipb-tab-panel" data-builder-panel="colors">
                            <h2>Palette complète</h2>
                            <div class="dipb-color-grid">
                                <?php foreach(['background_color'=>'Fond uni','gradient_start'=>'Dégradé 1','gradient_end'=>'Dégradé 2','overlay_color'=>'Overlay','card_color'=>'Carte','text_color'=>'Titre','muted_color'=>'Texte secondaire','accent_color'=>'Bouton','accent_hover'=>'Bouton hover','border_color'=>'Bordure carte','input_background'=>'Fond champs','input_border'=>'Bordure champs','input_text'=>'Texte champs','provider_background'=>'Boutons sociaux','provider_text'=>'Texte social'] as $k=>$l) self::color_field($s,$k,$l); ?>
                            </div>
                            <?php self::range_field($s,'gradient_angle','Angle du dégradé',0,360,'°'); ?>
                            <?php self::range_field($s,'overlay_opacity','Opacité overlay',0,90,'%'); ?>
                            <?php self::range_field($s,'card_opacity','Opacité carte',55,100,'%'); ?>
                        </div>

                        <div class="dipb-tab-panel" data-builder-panel="shape">
                            <h2>Dimensions et typographie</h2>
                            <?php self::range_field($s,'max_width','Largeur de carte',320,760); ?>
                            <?php self::range_field($s,'padding','Espacement intérieur',16,72); ?>
                            <?php self::range_field($s,'radius','Rayon carte',0,60); ?>
                            <?php self::range_field($s,'input_radius','Rayon champs',0,40); ?>
                            <?php self::range_field($s,'button_radius','Rayon boutons',0,40); ?>
                            <?php self::range_field($s,'card_border_width','Épaisseur bordure',0,4); ?>
                            <?php self::range_field($s,'card_blur','Flou glassmorphism',0,30); ?>
                            <?php self::range_field($s,'heading_size','Taille du titre',20,46); ?>
                            <?php self::range_field($s,'body_size','Taille du texte',12,20); ?>
                            <?php self::range_field($s,'font_weight','Graisse du titre',500,900,''); ?>
                            <?php self::range_field($s,'logo_size','Taille du logo',36,90); ?>
                            <?php self::range_field($s,'button_height','Hauteur des boutons',42,64); ?>
                            <?php self::range_field($s,'field_gap','Espacement des champs',4,24); ?>
                            <?php self::range_field($s,'shell_min_height','Hauteur minimale du panneau',480,980); ?>
                            <?php self::range_field($s,'card_section_gap','Espacement entre sections',8,36); ?>
                            <?php self::range_field($s,'provider_gap','Espace entre boutons sociaux',4,24); ?>
                            <?php self::range_field($s,'divider_gap','Marge du séparateur',8,36); ?>
                            <?php self::range_field($s,'input_height','Hauteur des champs',42,68); ?>
                            <?php self::range_field($s,'provider_border_width','Bordure boutons sociaux',0,4); ?>
                            <?php self::range_field($s,'provider_icon_size','Taille icônes sociales',14,30); ?>
                            <?php self::range_field($s,'button_font_size','Taille texte boutons',12,22); ?>
                            <?php self::range_field($s,'link_font_size','Taille liens',11,20); ?>
                            <label class="dipb-field"><span>Alignement du texte</span><select data-dipb name="<?php echo esc_attr(self::OPTION); ?>[text_align]"><option value="left" <?php selected($s['text_align'],'left'); ?>>Gauche</option><option value="center" <?php selected($s['text_align'],'center'); ?>>Centré</option></select></label>
                        </div>

                        <div class="dipb-tab-panel" data-builder-panel="content">
                            <h2>Contenu et options</h2>
                            <label class="dipb-field"><span>Titre</span><input data-dipb name="<?php echo esc_attr(self::OPTION); ?>[title]" value="<?php echo esc_attr($s['title']); ?>"></label>
                            <label class="dipb-field"><span>Sous-titre</span><textarea data-dipb rows="3" name="<?php echo esc_attr(self::OPTION); ?>[subtitle]"><?php echo esc_textarea($s['subtitle']); ?></textarea></label>
                            <label class="dipb-field"><span>Logo</span><div class="dipb-media-row"><input data-dipb name="<?php echo esc_attr(self::OPTION); ?>[logo_url]" value="<?php echo esc_attr($s['logo_url']); ?>" placeholder="https://"><button type="button" class="button dipb-media-select" data-target="logo_url">Choisir</button></div></label>
                            <?php foreach(['show_native'=>'Formulaire e-mail et mot de passe','show_remember'=>'Case “Se souvenir de moi”','show_register'=>'Lien de création de compte','show_lost_password'=>'Lien mot de passe oublié','mobile_bottom_sheet'=>'Bottom sheet sur mobile'] as $k=>$l): ?>
                                <label class="dipb-switch"><input data-dipb type="checkbox" name="<?php echo esc_attr(self::OPTION); ?>[<?php echo esc_attr($k); ?>]" <?php checked($s[$k],'yes'); ?>><span></span><b><?php echo esc_html($l); ?></b></label>
                            <?php endforeach; ?>
                        </div>
                    </aside>

                    <section class="dipb-preview">
                        <div class="dipb-preview-toolbar"><div><strong>Aperçu en direct</strong><small>Desktop, tablette et mobile</small></div><div class="dipb-device-switch"><button type="button" class="is-active" data-device="desktop"><span class="dashicons dashicons-desktop"></span></button><button type="button" data-device="tablet"><span class="dashicons dashicons-tablet"></span></button><button type="button" data-device="mobile"><span class="dashicons dashicons-smartphone"></span></button></div></div>
                        <div class="dipb-stage" data-preview-device="desktop"><div class="dipb-device"><div id="dipb-preview-shell" style="<?php echo esc_attr(self::css_vars($s)); ?>" class="dip-auth-shell dipb-bg-<?php echo esc_attr($s['background']); ?> dipb-layout-<?php echo esc_attr($s['layout']); ?> dipb-preset-<?php echo esc_attr($s['preset']); ?> dipb-motion-<?php echo esc_attr($s['motion']); ?> dipb-align-<?php echo esc_attr($s['text_align']); ?>" data-bg-position="<?php echo esc_attr($s['background_position']); ?>" data-registration-available="<?php echo $registration_available ? '1' : '0'; ?>"><div class="dip-auth-card dipb-shadow-<?php echo esc_attr($s['shadow']); ?>"><div class="dip-auth-brand" id="dipb-brand">D</div><h2 id="dipb-title"><?php echo esc_html($s['title']); ?></h2><p id="dipb-subtitle"><?php echo esc_html($s['subtitle']); ?></p><button type="button" class="dip-auth-provider"><b class="dipb-google">G</b><span>Continuer avec Google</span></button><button type="button" class="dip-auth-provider"><b class="dipb-microsoft">M</b><span>Continuer avec Microsoft</span></button><div class="dip-auth-divider"><span>ou</span></div><input placeholder="Adresse e-mail" type="email"><input placeholder="Mot de passe" type="password"><label class="dipb-preview-remember"><input type="checkbox"> Se souvenir de moi</label><button type="button" class="dip-auth-primary">Se connecter</button><div class="dip-auth-links"><a data-preview-link="register"<?php echo $registration_available ? '' : ' hidden'; ?>>Créer un compte</a><a data-preview-link="lost">Mot de passe oublié ?</a></div></div></div></div></div>
                        <div class="dipb-preview-footer"><code>[delicat_auth_panel]</code><button type="button" class="button dipb-copy-shortcode">Copier</button></div>
                    </section>
                </div>
            </form>

            <section class="dipb-tools"><div><h2>Bibliothèque et sauvegarde</h2><p>Exportez votre design ou restaurez la configuration par défaut.</p></div><div class="dipb-tool-actions"><a class="button" href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=dip_builder_export'),'dip_builder_export')); ?>">Exporter JSON</a><form method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="dip_builder_import"><?php wp_nonce_field('dip_builder_import'); ?><input type="file" name="builder_file" accept="application/json" required><button class="button">Importer</button></form><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="dipb-inline-reset" onsubmit="return confirm('Restaurer le design par défaut ?');"><input type="hidden" name="action" value="dip_builder_reset"><?php wp_nonce_field('dip_builder_reset'); ?><button type="submit" class="button dipb-danger">Réinitialiser</button></form></div></section>
        </div>
        <?php
    }

    public static function shortcode($atts = []) {
        if (is_user_logged_in()) return '<div class="dip-auth-logged">' . esc_html__('Vous êtes déjà connecté.', 'delicat-google-login') . '</div>';
        self::front_assets();
        $s = self::settings();
        list($registration_url, $registration_modal) = self::registration_destination();
        $social = do_shortcode('[delicat_social_login]');
        $classes = ['dip-auth-shell','dipb-bg-'.$s['background'],'dipb-layout-'.$s['layout'],'dipb-preset-'.$s['preset'],'dipb-motion-'.$s['motion'],'dipb-align-'.$s['text_align']];
        if ($s['mobile_bottom_sheet'] === 'yes') $classes[] = 'dipb-mobile-sheet';
        ob_start(); ?>
        <div class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-bg-position="<?php echo esc_attr($s['background_position']); ?>">
            <div class="dip-auth-card dipb-shadow-<?php echo esc_attr($s['shadow']); ?>">
                <?php if ($s['logo_url']): ?><img class="dip-auth-logo" src="<?php echo esc_url($s['logo_url']); ?>" alt=""><?php else: ?><div class="dip-auth-brand">D</div><?php endif; ?>
                <h2><?php echo esc_html($s['title']); ?></h2><p><?php echo esc_html($s['subtitle']); ?></p>
                <?php echo $social; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                <?php if ($s['show_native'] === 'yes'): ?><div class="dip-auth-divider"><span><?php esc_html_e('ou', 'delicat-google-login'); ?></span></div><?php wp_login_form(['echo'=>true,'remember'=>$s['show_remember']==='yes']); ?><?php endif; ?>
                <div class="dip-auth-links"><?php if ($s['show_register']==='yes' && $registration_url): ?><a href="<?php echo esc_url($registration_url); ?>"<?php if ($registration_modal): ?> data-dl-open data-dl-open-register<?php endif; ?>><?php esc_html_e('Créer un compte','delicat-google-login'); ?></a><?php endif; ?><?php if ($s['show_lost_password']==='yes'): ?><a href="<?php echo esc_url(wp_lostpassword_url()); ?>"><?php esc_html_e('Mot de passe oublié ?','delicat-google-login'); ?></a><?php endif; ?></div>
            </div>
        </div><?php
        return ob_get_clean();
    }

    public static function export() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_builder_export');
        nocache_headers();
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="delicat-identity-design.json"');
        echo wp_json_encode(self::settings(), JSON_PRETTY_PRINT);
        exit;
    }

    public static function import() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_builder_import');
        if (empty($_FILES['builder_file']['tmp_name']) || !is_uploaded_file($_FILES['builder_file']['tmp_name'])) wp_die('Fichier invalide.');
        $size = isset($_FILES['builder_file']['size']) ? absint($_FILES['builder_file']['size']) : 0;
        if ($size < 1 || $size > 1048576) wp_die('Le fichier JSON doit faire moins de 1 Mo.');
        $raw = file_get_contents($_FILES['builder_file']['tmp_name'], false, null, 0, 1048577);
        if ($raw === false || strlen($raw) > 1048576) wp_die('Fichier JSON trop volumineux.');
        $data = json_decode((string) $raw, true, 64);
        if (!is_array($data)) wp_die('JSON invalide.');
        update_option(self::OPTION, self::sanitize($data), false);
        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=delicat-identity-builder')));
        exit;
    }

    public static function reset() {
        if (!current_user_can('manage_options')) wp_die('Forbidden');
        check_admin_referer('dip_builder_reset');
        update_option(self::OPTION, self::defaults(), false);
        wp_safe_redirect(add_query_arg('settings-updated', 'true', admin_url('admin.php?page=delicat-identity-builder')));
        exit;
    }
}
