<?php
if (!defined('ABSPATH')) exit;

/* RC18: this file and its runtime/admin sibling declare the same Menu Builder
 * functions and class. Unconditional declarations are bound at compile time,
 * so parsing both in one request was an uncatchable "Cannot redeclare" fatal.
 * Declaring everything inside this block makes the second parse a no-op. */
if ( ! function_exists( 'delicat_builder_v9_menu_menu_builder_defaults' ) && ! class_exists( 'Delicat_Builder_V9_Menu_Builder', false ) ) :

/* ============================================================
 * DELICAT BUILDER V9 — NATIVE MENU BUILDER
 * Native V9 menu runtime + widget library with independent Light/Dark models.
 * Shortcodes:
 * [delicat_modern_menu] [delicat_menu_toggle] [delicat_bottom_nav]
 * [delicat_menu_promo] [delicat_profile_card] [delicat_bonus_menu]
 * [delicat_social_row] [delicat_wallet_card] [delicat_quick_actions]
 * ============================================================ */

function delicat_builder_v9_menu_menu_builder_defaults() {
    return array(
        'enabled' => 'yes', 'layout' => 'drawer', 'position' => 'left', 'width' => 390,
        'overlay_opacity' => 55, 'blur_strength' => 8, 'open_speed' => 280, 'close_on_overlay' => 'yes', 'body_scroll_lock' => 'yes', 'swipe_close' => 'yes',
        'menu_preset' => 'apple-glass', 'dark_model' => 'neo-glass', 'light_model' => 'soft-glass',
        'primary' => '#8b5cf6', 'secondary' => '#ec4899', 'accent' => '#2f75ff', 'radius' => 24, 'item_radius' => 16, 'gap' => 10,
        'custom_header_enabled' => 'yes', 'custom_header_title' => 'Delicat Store Haiti', 'custom_header_subtitle' => 'Recharge rapide, simple et sécurisée', 'custom_header_image' => '', 'custom_header_url' => '/', 'custom_header_style' => 'glass', 'custom_header_align' => 'left', 'section_order' => 'custom_header,profile,wallet,quick,main,bonus,promo,social,footer',
        'show_profile' => 'yes', 'show_header_title' => 'yes', 'header_title' => '', 'header_subtitle' => '', 'profile_name' => 'Delicat Store Haiti', 'profile_email' => 'info@delicastoreha.com', 'profile_badge' => 'Premium', 'profile_initial' => 'D', 'profile_cover' => '', 'welcome_text' => '',
        'show_wallet' => 'yes', 'wallet_label' => 'Mon portefeuille', 'wallet_balance' => 'Auto', 'wallet_item_balance' => 'yes', 'wallet_url' => '/my-wallet/', 'recharge_url' => '/my-wallet/', 'loyalty_points' => '0',
        'promo_enabled' => 'yes', 'promo_title' => 'Promos exclusives ! 🔥', 'promo_text' => 'Profitez de réductions incroyables sur vos top-ups préférés.', 'promo_button' => 'Voir les promos', 'promo_url' => '/promos/', 'promo_countdown' => '',
        'quick_actions' => 'yes', 'quick_wallet' => 'yes', 'quick_orders' => 'yes', 'quick_support' => 'yes', 'quick_app' => 'yes',
        'bonus_enabled' => 'yes', 'bonus_title' => 'Bonus Free Fire', 'social_enabled' => 'yes',
        'whatsapp_url' => 'https://wa.me/message/BXQXUCKDDI3GO1', 'telegram_url' => '#', 'instagram_url' => '#', 'facebook_url' => '#', 'tiktok_url' => '#', 'youtube_url' => '#',
        'whatsapp_icon' => '', 'telegram_icon' => '', 'instagram_icon' => '', 'facebook_icon' => '', 'tiktok_icon' => '', 'youtube_icon' => '',
        /* Delicat: per-network label, used for the aria-label, title and admin row. */
        'whatsapp_label' => 'WhatsApp', 'telegram_label' => 'Telegram', 'instagram_label' => 'Instagram', 'facebook_label' => 'Facebook', 'tiktok_label' => 'TikTok', 'youtube_label' => 'YouTube',
        'whatsapp_enabled' => 'yes', 'telegram_enabled' => 'yes', 'instagram_enabled' => 'yes', 'facebook_enabled' => 'yes', 'tiktok_enabled' => 'yes', 'youtube_enabled' => 'yes',
        'social_icon_size' => 24, 'social_button_size' => 52, 'social_gap' => 12, 'social_align' => 'center', 'profile_align' => 'center',
        'footer_toggle' => 'yes', 'wave_footer' => 'yes', 'bottom_nav' => 'yes', 'center_button' => 'yes',
        'animation' => 'stagger', 'hover_effect' => 'glow', 'reduced_motion' => 'respect',
        'performance_mode' => 'auto', 'adaptive_blur' => 'yes',
        'desktop_width' => 378, 'tablet_width' => 342, 'mobile_width' => 83, 'mobile_unit' => 'vw', 'mobile_padding' => 16, 'desktop_padding' => 24,
    );
}

function delicat_builder_v9_menu_menu_builder_default_items() {
    return array(
        array('title'=>'Accueil','subtitle'=>'Page principale','url'=>'/','icon'=>'dashicons-admin-home','image'=>'','color1'=>'#8b5cf6','color2'=>'#ec4899','badge'=>'','group'=>'main','visibility'=>'all'),
        array('title'=>'Mon Portefeuille','subtitle'=>'Solde et recharge','url'=>'/my-wallet/','icon'=>'dashicons-portfolio','image'=>'','color1'=>'#4f46e5','color2'=>'#06b6d4','badge'=>'','group'=>'main','visibility'=>'logged_in'),
        array('title'=>'Recharger mon compte','subtitle'=>'Moncash / Natcash','url'=>'/my-wallet/','icon'=>'dashicons-money-alt','image'=>'','color1'=>'#06b6d4','color2'=>'#22c55e','badge'=>'','group'=>'main','visibility'=>'all'),
        array('title'=>'Réclamer votre PIN Free Fire','subtitle'=>'Copier et réclamer','url'=>'/free-fire-redeem/','icon'=>'dashicons-admin-network','image'=>'','color1'=>'#f59e0b','color2'=>'#fb7185','badge'=>'HOT','group'=>'main','visibility'=>'all'),
        array('title'=>'FREE FIRE','subtitle'=>'Diamants instantanés','url'=>'/categorie-produit/free-fire/','icon'=>'dashicons-games','image'=>'','color1'=>'#6366f1','color2'=>'#8b5cf6','badge'=>'GAME','group'=>'main','visibility'=>'all'),
        array('title'=>'Rewards Program','subtitle'=>'Points et cadeaux','url'=>'/rewards/','icon'=>'dashicons-awards','image'=>'','color1'=>'#a855f7','color2'=>'#f97316','badge'=>'NEW','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Meilleur client','subtitle'=>'Classement clients','url'=>'/meilleur-client/','icon'=>'dashicons-star-filled','image'=>'','color1'=>'#f59e0b','color2'=>'#ef4444','badge'=>'HOT','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Exchange USDT - USD','subtitle'=>'Services financiers','url'=>'/product-category/exchange/','icon'=>'dashicons-money-alt','image'=>'','color1'=>'#10b981','color2'=>'#06b6d4','badge'=>'','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Jeux Disponibles','subtitle'=>'Tous les jeux','url'=>'/categorie-produit/jeux/','icon'=>'dashicons-games','image'=>'','color1'=>'#2563eb','color2'=>'#8b5cf6','badge'=>'','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Gift Card','subtitle'=>'Apple, Google, PSN','url'=>'/categorie-produit/gift-card/','icon'=>'dashicons-awards','image'=>'','color1'=>'#ec4899','color2'=>'#f43f5e','badge'=>'','group'=>'bonus','visibility'=>'all'),
    );
}

function delicat_builder_v9_menu_get_menu_builder_settings() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = get_option('dsb_menu_builder_settings', array());
    $cache = wp_parse_args(is_array($saved) ? $saved : array(), delicat_builder_v9_menu_menu_builder_defaults());
    return $cache;
}
function delicat_builder_v9_menu_get_menu_builder_items() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $items = get_option('dsb_menu_builder_items', array());
    $cache = is_array($items) && $items ? $items : delicat_builder_v9_menu_menu_builder_default_items();
    return $cache;
}
function delicat_builder_v9_menu_menu_bool($v){ return ($v === 'no') ? 'no' : 'yes'; }
function delicat_builder_v9_menu_sanitize_menu_builder_settings($raw) {
    $d = delicat_builder_v9_menu_menu_builder_defaults();
    $current = get_option('dsb_menu_builder_settings', array());
    $current = is_array($current) ? $current : array();
    $raw = wp_parse_args(is_array($raw) ? $raw : array(), $current);
    $models_dark = array('neo-glass','gradient-glow','minimal-dark','royal-glass','cyberpunk','amoled','gaming','playstation','steam');
    $models_light = array('soft-glass','gradient-light','minimal-light','clean-white','apple','material','modern-white');
    $menu_presets = array('apple-glass','gaming-hub','material-you','neon-cyber','luxury-minimal','dashboard-cards','custom');
    $out = array();
    foreach ($d as $k=>$v) { $out[$k] = $v; }
    $yesno = array('enabled','custom_header_enabled','close_on_overlay','body_scroll_lock','swipe_close','show_profile','show_header_title','show_wallet','wallet_item_balance','promo_enabled','quick_actions','quick_wallet','quick_orders','quick_support','quick_app','bonus_enabled','social_enabled','whatsapp_enabled','telegram_enabled','instagram_enabled','facebook_enabled','tiktok_enabled','youtube_enabled','footer_toggle','wave_footer','bottom_nav','center_button','adaptive_blur');
    foreach ($yesno as $k) $out[$k] = delicat_builder_v9_menu_menu_bool($raw[$k] ?? $d[$k]);
    $out['menu_preset'] = in_array(($raw['menu_preset'] ?? 'apple-glass'), $menu_presets, true) ? $raw['menu_preset'] : 'apple-glass';
    $out['layout'] = in_array(($raw['layout'] ?? 'drawer'), array('drawer','inline','compact','fullscreen'), true) ? $raw['layout'] : 'drawer';
    $out['position'] = in_array(($raw['position'] ?? 'left'), array('left','right','fullscreen'), true) ? $raw['position'] : 'left';
    $out['dark_model'] = in_array(($raw['dark_model'] ?? 'neo-glass'), $models_dark, true) ? $raw['dark_model'] : 'neo-glass';
    $out['light_model'] = in_array(($raw['light_model'] ?? 'soft-glass'), $models_light, true) ? $raw['light_model'] : 'soft-glass';
    $out['width'] = max(280, min(620, (int)($raw['width'] ?? $d['width'])));
    $out['desktop_width'] = max(300, min(760, (int)($raw['desktop_width'] ?? $d['desktop_width'])));
    $out['tablet_width'] = max(280, min(620, (int)($raw['tablet_width'] ?? $d['tablet_width'])));
    $out['mobile_width'] = max(80, min(100, (int)($raw['mobile_width'] ?? $d['mobile_width'])));
    $out['overlay_opacity'] = max(0, min(90, (int)($raw['overlay_opacity'] ?? $d['overlay_opacity'])));
    $out['blur_strength'] = max(0, min(30, (int)($raw['blur_strength'] ?? $d['blur_strength'])));
    $out['open_speed'] = max(100, min(1200, (int)($raw['open_speed'] ?? $d['open_speed'])));
    $out['radius'] = max(0, min(60, (int)($raw['radius'] ?? $d['radius'])));
    $out['item_radius'] = max(0, min(50, (int)($raw['item_radius'] ?? $d['item_radius'])));
    $out['gap'] = max(4, min(28, (int)($raw['gap'] ?? $d['gap'])));
    $out['mobile_padding'] = max(8, min(36, (int)($raw['mobile_padding'] ?? $d['mobile_padding'])));
    $out['desktop_padding'] = max(12, min(50, (int)($raw['desktop_padding'] ?? $d['desktop_padding'])));
    $out['social_icon_size'] = max(14, min(48, (int)($raw['social_icon_size'] ?? $d['social_icon_size'])));
    $out['social_button_size'] = max(36, min(80, (int)($raw['social_button_size'] ?? $d['social_button_size'])));
    $out['social_gap'] = max(4, min(30, (int)($raw['social_gap'] ?? $d['social_gap'])));
    $out['social_align'] = in_array(($raw['social_align'] ?? 'center'), array('left','center','right'), true) ? $raw['social_align'] : 'center';
    $out['profile_align'] = in_array(($raw['profile_align'] ?? 'center'), array('left','center'), true) ? $raw['profile_align'] : 'center';
    $out['custom_header_style'] = in_array(($raw['custom_header_style'] ?? 'glass'), array('glass','solid','gradient','minimal'), true) ? $raw['custom_header_style'] : 'glass';
    $out['custom_header_align'] = in_array(($raw['custom_header_align'] ?? 'left'), array('left','center'), true) ? $raw['custom_header_align'] : 'left';
    $out['mobile_unit'] = in_array(($raw['mobile_unit'] ?? 'vw'), array('vw','%'), true) ? $raw['mobile_unit'] : 'vw';
    $out['animation'] = in_array(($raw['animation'] ?? 'stagger'), array('none','fade','slide','stagger','elastic','glow'), true) ? $raw['animation'] : 'stagger';
    $out['hover_effect'] = in_array(($raw['hover_effect'] ?? 'glow'), array('glow','scale','magnetic','minimal'), true) ? $raw['hover_effect'] : 'glow';
    $out['reduced_motion'] = in_array(($raw['reduced_motion'] ?? 'respect'), array('respect','ignore'), true) ? $raw['reduced_motion'] : 'respect';
    $out['performance_mode'] = in_array(($raw['performance_mode'] ?? 'auto'), array('auto','balanced','ultra-lite'), true) ? $raw['performance_mode'] : 'auto';
    // Legacy width used to be independent but the frontend never consumed it. Keep it synced so no dead parameter survives.
    $out['width'] = $out['desktop_width'];
    foreach (array('primary','secondary','accent') as $c) $out[$c] = sanitize_hex_color($raw[$c] ?? $d[$c]) ?: $d[$c];
    foreach (array('custom_header_title','custom_header_subtitle','section_order','header_title','header_subtitle','profile_name','profile_badge','profile_initial','welcome_text','wallet_label','wallet_balance','loyalty_points','promo_title','promo_text','promo_button','promo_countdown','whatsapp_label','telegram_label','instagram_label','facebook_label','tiktok_label','youtube_label','bonus_title') as $t) $out[$t] = sanitize_text_field($raw[$t] ?? $d[$t]);
    $allowed_sections = array('custom_header','profile','wallet','quick','main','bonus','promo','social','footer');
    $requested_sections = array_filter(array_map('sanitize_key', explode(',', (string)($raw['section_order'] ?? $d['section_order']))));
    $requested_sections = array_values(array_unique(array_intersect($requested_sections, $allowed_sections)));
    foreach ($allowed_sections as $section) if (!in_array($section, $requested_sections, true)) $requested_sections[] = $section;
    $out['section_order'] = implode(',', $requested_sections);
    $out['profile_initial'] = strtoupper(substr($out['profile_initial'],0,2));
    $out['profile_email'] = sanitize_email($raw['profile_email'] ?? $d['profile_email']);
    foreach (array('custom_header_image','custom_header_url','profile_cover','wallet_url','recharge_url','promo_url','whatsapp_url','telegram_url','instagram_url','facebook_url','tiktok_url','youtube_url','whatsapp_icon','telegram_icon','instagram_icon','facebook_icon','tiktok_icon','youtube_icon') as $u) $out[$u] = esc_url_raw($raw[$u] ?? $d[$u]);
    return $out;
}
function delicat_builder_v9_menu_sanitize_menu_builder_items($items) {
    $out = array();
    foreach ((array)$items as $item) {
        $title = sanitize_text_field($item['title'] ?? '');
        if ($title === '') continue;
        $url = esc_url_raw($item['url'] ?? '#');
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (in_array($path, array('/exchange','/exchange/'), true)) $url = '/product-category/exchange/';
        if (in_array($path, array('/product-category/streaming','/product-category/streaming/'), true)) $url = '/product-category/abonnement/';
        $out[] = array(
            'title' => $title,
            'subtitle' => sanitize_text_field($item['subtitle'] ?? ''),
            'url' => $url,
            'icon' => sanitize_html_class($item['icon'] ?? 'dashicons-arrow-right-alt2'),
            'image' => esc_url_raw($item['image'] ?? ''),
            'attachment_id' => absint($item['attachment_id'] ?? 0),
            'color1' => sanitize_hex_color($item['color1'] ?? '#8b5cf6') ?: '#8b5cf6',
            'color2' => sanitize_hex_color($item['color2'] ?? '#ec4899') ?: '#ec4899',
            'badge' => sanitize_text_field($item['badge'] ?? ''),
            'group' => in_array(($item['group'] ?? 'main'), array('main','bonus','footer','quick'), true) ? $item['group'] : 'main',
            'visibility' => in_array(($item['visibility'] ?? 'all'), array('all','logged_in','logged_out'), true) ? $item['visibility'] : 'all',
        );
    }
    return $out ?: delicat_builder_v9_menu_menu_builder_default_items();
}

add_action('admin_init', function(){
    if (!isset($_POST['delicat_builder_v9_menu_action']) || (!current_user_can('manage_woocommerce') && !current_user_can('manage_options'))) return;
    if (!isset($_POST['delicat_builder_v9_menu_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['delicat_builder_v9_menu_nonce'])), 'delicat_builder_v9_menu_builder')) return;
    $settings = isset($_POST['dsb_menu_builder']) && is_array($_POST['dsb_menu_builder']) ? wp_unslash($_POST['dsb_menu_builder']) : array();
    $items = isset($_POST['dsb_menu_items']) && is_array($_POST['dsb_menu_items']) ? wp_unslash($_POST['dsb_menu_items']) : array();
    update_option('dsb_menu_builder_settings', delicat_builder_v9_menu_sanitize_menu_builder_settings($settings), false);
    update_option('dsb_menu_builder_items', delicat_builder_v9_menu_sanitize_menu_builder_items($items), false);
    // Absent key = every row was deleted, which is a valid choice; storing the
    // empty array is what stops the legacy four-slot row coming back.
    $quick = isset($_POST['dsb_menu_quick']) && is_array($_POST['dsb_menu_quick']) ? wp_unslash($_POST['dsb_menu_quick']) : array();
    update_option('dsb_menu_quick_items', delicat_builder_v9_menu_sanitize_menu_quick_items($quick), false);
    if (class_exists('Delicat_Builder_V9_Cache', false) && is_callable(array('Delicat_Builder_V9_Cache','bump_version'))) Delicat_Builder_V9_Cache::bump_version(); elseif (function_exists('dlc_bump_cache')) dlc_bump_cache();
    wp_safe_redirect(admin_url('admin.php?page=delicat-builder-v9-menu-builder&msg=saved'));
    exit;
});


function delicat_builder_v9_menu_register_assets() {
    if (function_exists('dsb_render_modern_menu')) return; // legacy Builder owns frontend until deactivated
    if (!wp_style_is('delicat-builder-v9-modern-menu', 'registered')) {
        wp_register_style('delicat-builder-v9-modern-menu', DELICAT_BUILDER_V9_URL . 'assets/dsb-modern-menu.css', array(), DELICAT_BUILDER_V9_VERSION);
    }
    if (!wp_script_is('delicat-builder-v9-modern-menu', 'registered')) {
        wp_register_script('delicat-builder-v9-modern-menu', DELICAT_BUILDER_V9_URL . 'assets/dsb-modern-menu.js', array(), DELICAT_BUILDER_V9_VERSION, true);
        if (function_exists('wp_script_add_data')) wp_script_add_data('delicat-builder-v9-modern-menu','strategy','defer');
    }
}

function delicat_builder_v9_menu_menu_builder_style() {
    delicat_builder_v9_menu_register_assets();
    if (wp_style_is('delicat-builder-v9-modern-menu', 'registered')) wp_enqueue_style('delicat-builder-v9-modern-menu');
}

function delicat_builder_v9_menu_menu_builder_assets() {
    delicat_builder_v9_menu_register_assets();
    if (wp_style_is('delicat-builder-v9-modern-menu', 'registered')) wp_enqueue_style('delicat-builder-v9-modern-menu');
    if (wp_script_is('delicat-builder-v9-modern-menu', 'registered')) wp_enqueue_script('delicat-builder-v9-modern-menu');
}

function delicat_builder_v9_menu_maybe_enqueue_assets() {
    if (is_admin() || function_exists('dsb_render_modern_menu')) return;
    if (class_exists('Delicat_Builder_V9_Drawer', false) && Delicat_Builder_V9_Drawer::owns()) return; /* RC74: rebuilt drawer ships its own assets */
    $settings = delicat_builder_v9_menu_get_menu_builder_settings();
    if (($settings['enabled'] ?? 'yes') !== 'yes') return;
    // Keep styling ready for Header/Menu shortcodes, but do not parse/execute the
    // 10 KB drawer runtime on every storefront request. Interactive shortcodes
    // enqueue the deferred JS only when they are actually rendered.
    delicat_builder_v9_menu_menu_builder_style();
}

add_action('wp_enqueue_scripts', 'delicat_builder_v9_menu_maybe_enqueue_assets', 7);

function delicat_builder_v9_menu_menu_style_vars($s) {
    $s = is_array($s) ? $s : array();
    $bounded = static function($value, $min, $max, $default) {
        if (!is_numeric($value)) return $default;
        return max($min, min($max, (int)$value));
    };
    $desktop = $bounded($s['desktop_width'] ?? 378, 280, 720, 378);
    $tablet = $bounded($s['tablet_width'] ?? 342, 280, 640, 342);
    $mobile = $bounded($s['mobile_width'] ?? 83, 55, 96, 83);
    $unit = (($s['mobile_unit'] ?? 'vw') === '%') ? '%' : 'vw';
    $overlay = $bounded($s['overlay_opacity'] ?? 55, 0, 85, 55) / 100;
    $blur = $bounded($s['blur_strength'] ?? 8, 0, 24, 8);
    $speed = $bounded($s['open_speed'] ?? 280, 120, 700, 280);
    $radius = $bounded($s['radius'] ?? 24, 0, 40, 24);
    $item_radius = $bounded($s['item_radius'] ?? 16, 0, 32, 16);
    $gap = $bounded($s['gap'] ?? 10, 4, 24, 10);
    $desktop_pad = $bounded($s['desktop_padding'] ?? 24, 8, 40, 24);
    $mobile_pad = $bounded($s['mobile_padding'] ?? 16, 6, 28, 16);
    $primary = sanitize_hex_color($s['primary'] ?? '#8b5cf6') ?: '#8b5cf6';
    $secondary = sanitize_hex_color($s['secondary'] ?? '#ec4899') ?: '#ec4899';
    $accent = sanitize_hex_color($s['accent'] ?? '#2f75ff') ?: '#2f75ff';
    $mw = $mobile . $unit;
    return '--dsb-menu-width:'.$desktop.'px;--dsb-menu-tablet-width:'.$tablet.'px;--dsb-menu-mobile-width:'.esc_attr($mw).';--dsb-overlay-opacity:'.$overlay.';--dsb-menu-blur:'.$blur.'px;--dsb-menu-speed:'.$speed.'ms;--dsb-primary:'.esc_attr($primary).';--dsb-secondary:'.esc_attr($secondary).';--dsb-accent:'.esc_attr($accent).';--dsb-radius:'.$radius.'px;--dsb-item-radius:'.$item_radius.'px;--dsb-menu-gap:'.$gap.'px;--dsb-desktop-pad:'.$desktop_pad.'px;--dsb-mobile-pad:'.$mobile_pad.'px;';
}

function delicat_builder_v9_menu_menu_builder_admin_page() {
    if (!current_user_can('manage_woocommerce') && !current_user_can('manage_options')) {
        wp_die(esc_html__('You cannot edit the Menu Builder.', 'delicat-builder-v9'), '', array('response'=>403));
    }
    wp_enqueue_media();
    $admin_css = DELICAT_BUILDER_V9_DIR . 'assets/css/menu-builder-v2.css';
    $admin_js  = DELICAT_BUILDER_V9_DIR . 'assets/js/menu-builder-v2.js';
    wp_enqueue_style('delicat-builder-v9-menu-builder-v2', DELICAT_BUILDER_V9_URL . 'assets/css/menu-builder-v2.css', array(), is_file($admin_css) ? filemtime($admin_css) : DELICAT_BUILDER_V9_VERSION);
    wp_enqueue_script('delicat-builder-v9-menu-builder-v2', DELICAT_BUILDER_V9_URL . 'assets/js/menu-builder-v2.js', array(), is_file($admin_js) ? filemtime($admin_js) : DELICAT_BUILDER_V9_VERSION, true);
    if (function_exists('wp_script_add_data')) wp_script_add_data('delicat-builder-v9-menu-builder-v2', 'strategy', 'defer');

    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    $items = delicat_builder_v9_menu_get_menu_builder_items();
    $quick = delicat_builder_v9_menu_get_menu_quick_items();
    $section_labels = array(
        'custom_header'=>'En-tête', 'profile'=>'Profil', 'wallet'=>'Portefeuille', 'quick'=>'Actions rapides',
        'main'=>'Navigation principale', 'bonus'=>'Bonus', 'promo'=>'Promotion', 'social'=>'Réseaux sociaux', 'footer'=>'Thème / Footer'
    );
    $socials = array('whatsapp'=>'WhatsApp','telegram'=>'Telegram','instagram'=>'Instagram','facebook'=>'Facebook','tiktok'=>'TikTok','youtube'=>'YouTube');
    ?>
    <div class="wrap dbv9-mb" id="dbv9MenuBuilder">
      <form method="post" id="dbv9MenuBuilderForm" autocomplete="off">
        <?php wp_nonce_field('delicat_builder_v9_menu_builder','delicat_builder_v9_menu_nonce'); ?>
        <input type="hidden" name="delicat_builder_v9_menu_action" value="save">

        <header class="dbv9-mb-topbar">
          <div class="dbv9-mb-brand">
            <span class="dbv9-mb-kicker">DELICAT BUILDER V9 · MENU ENGINE 2.0</span>
            <h1>Menu Builder</h1>
            <p>Navigation native, fluide et adaptative — optimisée pour les téléphones économiques.</p>
          </div>
          <div class="dbv9-mb-actions">
            <span class="dbv9-mb-state" id="dbv9MbState"><i></i><span><?php echo isset($_GET['msg']) ? 'Enregistré' : 'À jour'; ?></span></span>
            <button type="submit" class="button button-primary dbv9-mb-save">Enregistrer</button>
          </div>
        </header>

        <div class="dbv9-mb-layout">
          <aside class="dbv9-mb-nav" aria-label="Sections du Menu Builder">
            <button type="button" class="is-active" data-mb-tab="general"><span>01</span> Général</button>
            <button type="button" data-mb-tab="appearance"><span>02</span> Apparence</button>
            <button type="button" data-mb-tab="identity"><span>03</span> Profil & Wallet</button>
            <button type="button" data-mb-tab="widgets"><span>04</span> Widgets</button>
            <button type="button" data-mb-tab="navigation"><span>05</span> Navigation</button>
            <button type="button" data-mb-tab="social"><span>06</span> Réseaux sociaux</button>
            <button type="button" data-mb-tab="performance"><span>07</span> Performance</button>
          </aside>

          <main class="dbv9-mb-content">
            <section class="dbv9-mb-panel is-active" data-mb-panel="general">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Moteur</span><h2>Comportement général</h2><p>Contrôlez l’ouverture, la position et l’ordre des modules sans toucher au thème.</p></div></div>
              <div class="dbv9-mb-card-grid">
                <div class="dbv9-mb-card"><h3>Activation & disposition</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'enabled','Activer le menu',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'layout','Disposition',array('drawer'=>'Tiroir','inline'=>'Inline','compact'=>'Compact','fullscreen'=>'Plein écran')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'position','Ouverture',array('left'=>'Gauche','right'=>'Droite','fullscreen'=>'Plein écran')); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Ordre des sections</h3><p class="dbv9-mb-muted">Glissez sur ordinateur ou utilisez les flèches sur mobile.</p>
                  <input type="hidden" id="dsbSectionOrder" name="dsb_menu_builder[section_order]" value="<?php echo esc_attr($s['section_order']); ?>">
                  <div class="dbv9-sort-list" id="dsbSectionSorter">
                    <?php foreach(explode(',', $s['section_order']) as $section): if(!isset($section_labels[$section])) continue; ?>
                    <div class="dbv9-sort-item" draggable="true" data-section="<?php echo esc_attr($section); ?>"><button type="button" class="dbv9-drag" aria-label="Déplacer">⠿</button><strong><?php echo esc_html($section_labels[$section]); ?></strong><span class="dbv9-row-move"><button type="button" data-move="up" aria-label="Monter">↑</button><button type="button" data-move="down" aria-label="Descendre">↓</button></span></div>
                    <?php endforeach; ?>
                  </div>
                </div>
              </div>
            </section>

            <section class="dbv9-mb-panel" data-mb-panel="appearance">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Design system</span><h2>Apparence moderne</h2><p>Les effets sont rendus avec transform/opacity et se simplifient automatiquement sur les appareils plus faibles.</p></div></div>
              <div class="dbv9-mb-card-grid">
                <div class="dbv9-mb-card"><h3>Preset</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'menu_preset','Style du menu',array('apple-glass'=>'NeoGlass Premium','gaming-hub'=>'Gaming Hub','material-you'=>'Material Soft','neon-cyber'=>'Neon Cyber','luxury-minimal'=>'Luxury Minimal','dashboard-cards'=>'Dashboard Cards','custom'=>'Custom')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'light_model','Modèle clair',array('soft-glass'=>'Soft Glass','gradient-light'=>'Gradient Light','minimal-light'=>'Minimal','clean-white'=>'Clean White','apple'=>'Apple','material'=>'Material','modern-white'=>'Modern White')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'dark_model','Modèle sombre',array('neo-glass'=>'NeoGlass','gradient-glow'=>'Gradient Glow','minimal-dark'=>'Minimal Dark','royal-glass'=>'Royal Glass','cyberpunk'=>'Cyberpunk','amoled'=>'AMOLED','gaming'=>'Gaming','playstation'=>'PlayStation','steam'=>'Steam')); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Couleurs</h3><div class="dbv9-mb-fields dbv9-mb-colors">
                  <?php delicat_builder_v9_menu_menu_input($s,'primary','Primaire','color'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'secondary','Secondaire','color'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'accent','Accent','color'); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Forme & interaction</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_input($s,'radius','Rayon du panneau','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'item_radius','Rayon des items','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'gap','Espacement','number'); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'animation','Animation',array('none'=>'Aucune','fade'=>'Fondu','slide'=>'Glissé','stagger'=>'Cascade légère','elastic'=>'Élastique','glow'=>'Glow')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'hover_effect','Interaction',array('glow'=>'Glow léger','scale'=>'Scale','magnetic'=>'Magnetic','minimal'=>'Minimal')); ?>
                </div></div>
              </div>
            </section>

            <section class="dbv9-mb-panel" data-mb-panel="identity">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Compte client</span><h2>Profil & portefeuille</h2><p>Synchronisé avec WordPress, WooCommerce et TeraWallet lorsqu’ils sont disponibles.</p></div></div>
              <div class="dbv9-mb-card"><h3>En-tête personnalisé</h3><div class="dbv9-mb-fields">
                <?php delicat_builder_v9_menu_menu_select($s,'custom_header_enabled','Afficher l’en-tête',array('yes'=>'Oui','no'=>'Non')); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'custom_header_title','Titre'); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'custom_header_subtitle','Sous-titre'); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'custom_header_image','Logo / image','url'); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'custom_header_url','Lien','url'); ?>
                <?php delicat_builder_v9_menu_menu_select($s,'custom_header_style','Style',array('glass'=>'Glass','solid'=>'Solid','gradient'=>'Gradient','minimal'=>'Minimal')); ?>
                <?php delicat_builder_v9_menu_menu_select($s,'custom_header_align','Alignement',array('left'=>'Gauche','center'=>'Centre')); ?>
              </div></div>
              <div class="dbv9-mb-card-grid">
                <div class="dbv9-mb-card"><h3>Profil</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'show_profile','Afficher le profil',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'show_header_title','Afficher le texte',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'header_title','Titre dynamique'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'header_subtitle','Sous-titre dynamique'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'welcome_text','Texte de bienvenue invité'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'profile_name','Nom invité'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'profile_email','Email invité','email'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'profile_initial','Initiale'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'profile_badge','Badge'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'profile_cover','Image de couverture','url'); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'profile_align','Alignement',array('left'=>'Gauche','center'=>'Centre')); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Portefeuille</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'show_wallet','Afficher le wallet',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'wallet_label','Libellé'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'wallet_balance','Solde','text'); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'wallet_item_balance','Solde sur liens wallet',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'wallet_url','Lien wallet','url'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'recharge_url','Lien recharge','url'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'loyalty_points','Points'); ?>
                </div></div>
              </div>
            </section>

            <section class="dbv9-mb-panel" data-mb-panel="widgets">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Modules</span><h2>Widgets rapides</h2><p>Les widgets se chargent uniquement lorsqu’ils sont réellement rendus.</p></div></div>
              <div class="dbv9-mb-card-grid">
                <div class="dbv9-mb-card"><h3>Promotion</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'promo_enabled','Afficher',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'promo_title','Titre'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'promo_text','Texte'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'promo_button','Bouton'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'promo_url','Lien','url'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'promo_countdown','Compte à rebours'); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Bonus & footer</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'bonus_enabled','Section bonus',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'bonus_title','Titre bonus'); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'footer_toggle','Toggle thème',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'wave_footer','Signature visuelle',array('yes'=>'Oui','no'=>'Non')); ?>
                </div></div>
              </div>
              <div class="dbv9-mb-card"><div class="dbv9-mb-card-title-row"><div><h3>Actions rapides</h3><p class="dbv9-mb-muted">Ajoutez, réorganisez ou retirez les raccourcis.</p></div><?php delicat_builder_v9_menu_menu_select($s,'quick_actions','Activer',array('yes'=>'Oui','no'=>'Non')); ?></div>
                <div class="dbv9-repeat-list" id="dsbQuickItems">
                <?php foreach($quick as $i=>$it): ?>
                  <div class="dbv9-repeat-row" draggable="true" data-repeat="quick">
                    <div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button></div>
                    <div class="dbv9-repeat-grid quick-grid">
                      <label>Icône<input name="dsb_menu_quick[<?php echo (int)$i; ?>][icon]" value="<?php echo esc_attr($it['icon'] ?? ''); ?>"></label>
                      <label>Image/SVG<input class="dsb-menu-image-url" name="dsb_menu_quick[<?php echo (int)$i; ?>][image]" value="<?php echo esc_url($it['image'] ?? ''); ?>"><input type="hidden" class="dsb-menu-attachment-id" name="dsb_menu_quick[<?php echo (int)$i; ?>][attachment_id]" value="<?php echo absint($it['attachment_id'] ?? 0); ?>"><button type="button" class="button dsb-menu-upload">Média</button></label>
                      <label>Libellé<input name="dsb_menu_quick[<?php echo (int)$i; ?>][label]" value="<?php echo esc_attr($it['label'] ?? ''); ?>"></label>
                      <label>Lien<input name="dsb_menu_quick[<?php echo (int)$i; ?>][url]" value="<?php echo esc_attr($it['url'] ?? ''); ?>"></label>
                      <label>Visibilité<select name="dsb_menu_quick[<?php echo (int)$i; ?>][visibility]"><option value="all" <?php selected($it['visibility'] ?? 'all','all'); ?>>Tous</option><option value="logged_in" <?php selected($it['visibility'] ?? 'all','logged_in'); ?>>Connectés</option><option value="logged_out" <?php selected($it['visibility'] ?? 'all','logged_out'); ?>>Invités</option></select></label>
                    </div>
                    <div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove" aria-label="Supprimer">×</button></div>
                  </div>
                <?php endforeach; ?>
                </div>
                <button type="button" class="button dbv9-add-row" data-add="quick">+ Ajouter un raccourci</button>
              </div>
            </section>

            <section class="dbv9-mb-panel" data-mb-panel="navigation">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Structure</span><h2>Navigation</h2><p>Chaque entrée reste native, accessible et légère. Les images sont chargées en lazy-loading.</p></div><div class="dbv9-mb-search"><input type="search" id="dbv9MenuItemSearch" placeholder="Rechercher un item…"></div></div>
              <div class="dbv9-repeat-list" id="dsbMenuItems">
              <?php foreach($items as $i=>$it): ?>
                <div class="dbv9-repeat-row" draggable="true" data-repeat="menu" data-search="<?php echo esc_attr(strtolower(($it['title'] ?? '').' '.($it['subtitle'] ?? ''))); ?>">
                  <div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button><span class="dbv9-item-icon-dot" style="--c1:<?php echo esc_attr($it['color1']); ?>;--c2:<?php echo esc_attr($it['color2']); ?>"></span></div>
                  <div class="dbv9-repeat-grid menu-grid">
                    <label>Titre<input name="dsb_menu_items[<?php echo (int)$i; ?>][title]" value="<?php echo esc_attr($it['title']); ?>"></label>
                    <label>Sous-titre<input name="dsb_menu_items[<?php echo (int)$i; ?>][subtitle]" value="<?php echo esc_attr($it['subtitle'] ?? ''); ?>"></label>
                    <label>Lien<input name="dsb_menu_items[<?php echo (int)$i; ?>][url]" value="<?php echo esc_attr($it['url']); ?>"></label>
                    <label>Icône native<input name="dsb_menu_items[<?php echo (int)$i; ?>][icon]" value="<?php echo esc_attr($it['icon']); ?>"></label>
                    <label>Image/SVG<input class="dsb-menu-image-url" name="dsb_menu_items[<?php echo (int)$i; ?>][image]" value="<?php echo esc_url($it['image'] ?? ''); ?>"><input type="hidden" class="dsb-menu-attachment-id" name="dsb_menu_items[<?php echo (int)$i; ?>][attachment_id]" value="<?php echo absint($it['attachment_id'] ?? 0); ?>"><button type="button" class="button dsb-menu-upload">Média</button></label>
                    <label>Couleur 1<input type="color" name="dsb_menu_items[<?php echo (int)$i; ?>][color1]" value="<?php echo esc_attr($it['color1']); ?>"></label>
                    <label>Couleur 2<input type="color" name="dsb_menu_items[<?php echo (int)$i; ?>][color2]" value="<?php echo esc_attr($it['color2']); ?>"></label>
                    <label>Badge<input name="dsb_menu_items[<?php echo (int)$i; ?>][badge]" value="<?php echo esc_attr($it['badge']); ?>"></label>
                    <label>Groupe<select name="dsb_menu_items[<?php echo (int)$i; ?>][group]"><option value="main" <?php selected($it['group'],'main'); ?>>Principal</option><option value="bonus" <?php selected($it['group'],'bonus'); ?>>Bonus</option><option value="footer" <?php selected($it['group'],'footer'); ?>>Footer</option><option value="quick" <?php selected($it['group'],'quick'); ?>>Quick</option></select></label>
                    <label>Visibilité<select name="dsb_menu_items[<?php echo (int)$i; ?>][visibility]"><option value="all" <?php selected($it['visibility'] ?? 'all','all'); ?>>Tous</option><option value="logged_in" <?php selected($it['visibility'] ?? 'all','logged_in'); ?>>Connectés</option><option value="logged_out" <?php selected($it['visibility'] ?? 'all','logged_out'); ?>>Invités</option></select></label>
                  </div>
                  <div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove" aria-label="Supprimer">×</button></div>
                </div>
              <?php endforeach; ?>
              </div>
              <button type="button" class="button dbv9-add-row" data-add="menu">+ Ajouter un item</button>
            </section>

            <section class="dbv9-mb-panel" data-mb-panel="social">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Liens externes</span><h2>Réseaux sociaux</h2><p>Chaque réseau peut être activé séparément et utiliser une icône personnalisée.</p></div></div>
              <div class="dbv9-mb-card"><div class="dbv9-mb-fields">
                <?php delicat_builder_v9_menu_menu_select($s,'social_enabled','Afficher les réseaux',array('yes'=>'Oui','no'=>'Non')); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'social_icon_size','Taille icône','number'); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'social_button_size','Taille bouton','number'); ?>
                <?php delicat_builder_v9_menu_menu_input($s,'social_gap','Espacement','number'); ?>
                <?php delicat_builder_v9_menu_menu_select($s,'social_align','Alignement',array('left'=>'Gauche','center'=>'Centre','right'=>'Droite')); ?>
              </div></div>
              <div class="dbv9-social-list">
                <?php foreach($socials as $slug=>$label): ?>
                <div class="dbv9-social-row">
                  <label class="dbv9-switch"><input type="hidden" name="dsb_menu_builder[<?php echo esc_attr($slug); ?>_enabled]" value="no"><input type="checkbox" name="dsb_menu_builder[<?php echo esc_attr($slug); ?>_enabled]" value="yes" <?php checked($s[$slug.'_enabled'] ?? 'yes','yes'); ?>><span></span></label>
                  <strong><?php echo esc_html($label); ?></strong>
                  <label>Libellé<input name="dsb_menu_builder[<?php echo esc_attr($slug); ?>_label]" value="<?php echo esc_attr($s[$slug.'_label'] ?? $label); ?>"></label>
                  <label>URL<input type="url" name="dsb_menu_builder[<?php echo esc_attr($slug); ?>_url]" value="<?php echo esc_url($s[$slug.'_url'] ?? ''); ?>"></label>
                  <label>Icône<input type="url" class="dsb-social-icon-url" name="dsb_menu_builder[<?php echo esc_attr($slug); ?>_icon]" value="<?php echo esc_url($s[$slug.'_icon'] ?? ''); ?>"><button type="button" class="button dsb-social-upload">Média</button></label>
                </div>
                <?php endforeach; ?>
              </div>
            </section>

            <section class="dbv9-mb-panel" data-mb-panel="performance">
              <div class="dbv9-mb-panel-head"><div><span class="dbv9-mb-eyebrow">Low-end first</span><h2>Responsive & performance</h2><p>Le mode Auto détecte Data Saver, mémoire limitée, faible nombre de cœurs et Reduced Motion pour alléger les effets.</p></div></div>
              <div class="dbv9-mb-card-grid">
                <div class="dbv9-mb-card"><h3>Mode de performance</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'performance_mode','Profil',array('auto'=>'Auto adaptatif (recommandé)','balanced'=>'Qualité équilibrée','ultra-lite'=>'Ultra léger')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'adaptive_blur','Blur adaptatif',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'reduced_motion','Reduced Motion',array('respect'=>'Respecter','ignore'=>'Ignorer')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'open_speed','Vitesse ouverture (ms)','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'blur_strength','Blur maximum','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'overlay_opacity','Opacité overlay %','number'); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Largeurs</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_input($s,'desktop_width','Desktop (px)','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'tablet_width','Tablette (px)','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'mobile_width','Mobile','number'); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'mobile_unit','Unité mobile',array('vw'=>'vw','%'=>'%')); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'desktop_padding','Padding desktop','number'); ?>
                  <?php delicat_builder_v9_menu_menu_input($s,'mobile_padding','Padding mobile','number'); ?>
                </div></div>
                <div class="dbv9-mb-card"><h3>Gestes & navigation</h3><div class="dbv9-mb-fields">
                  <?php delicat_builder_v9_menu_menu_select($s,'close_on_overlay','Fermer via overlay',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'body_scroll_lock','Bloquer le scroll',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'swipe_close','Swipe pour fermer',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'bottom_nav','Bottom navigation',array('yes'=>'Oui','no'=>'Non')); ?>
                  <?php delicat_builder_v9_menu_menu_select($s,'center_button','Bouton central',array('yes'=>'Oui','no'=>'Non')); ?>
                </div></div>
              </div>
              <div class="dbv9-perf-note"><strong>Architecture RC51.17</strong><span>1 runtime différé · 1 scan combiné des widgets flottants · animations transform/opacity · zéro blur mobile · listes groupées sans ombres répétées · wallet live chargé uniquement si nécessaire.</span></div>
            </section>
          </main>

          <aside class="dbv9-mb-preview-wrap">
            <div class="dbv9-mb-preview-card">
              <div class="dbv9-preview-head"><div><span>Aperçu</span><strong>Mobile</strong></div><span class="dbv9-preview-dot"></span></div>
              <div class="dbv9-phone" id="dbv9MenuPreview" style="--p1:<?php echo esc_attr($s['primary']); ?>;--p2:<?php echo esc_attr($s['secondary']); ?>;--pa:<?php echo esc_attr($s['accent']); ?>;--pr:<?php echo (int)$s['radius']; ?>px;--pir:<?php echo (int)$s['item_radius']; ?>px;">
                <div class="dbv9-phone-top"><span class="dbv9-preview-logo">D</span><div><strong id="dbv9PreviewTitle"><?php echo esc_html($s['custom_header_title'] ?: 'Delicat Store Haiti'); ?></strong><small id="dbv9PreviewSubtitle"><?php echo esc_html($s['custom_header_subtitle']); ?></small></div><button type="button" tabindex="-1">×</button></div>
                <div class="dbv9-preview-profile"><span>👤</span><div><small>Bienvenue</small><strong><?php echo esc_html($s['profile_name'] ?: 'Client Delicat'); ?></strong></div></div>
                <div class="dbv9-preview-wallet"><div><small><?php echo esc_html($s['wallet_label']); ?></small><strong>G 1,250</strong></div><button type="button" tabindex="-1">Recharger</button></div>
                <div class="dbv9-preview-quick"><span>💳<small>Wallet</small></span><span>📦<small>Commandes</small></span><span>💬<small>Support</small></span></div>
                <div class="dbv9-preview-links" id="dbv9PreviewLinks">
                  <?php foreach(array_slice($items,0,4) as $it): ?><span><i style="--c1:<?php echo esc_attr($it['color1']); ?>;--c2:<?php echo esc_attr($it['color2']); ?>"></i><b><?php echo esc_html($it['title']); ?></b><em>›</em></span><?php endforeach; ?>
                </div>
              </div>
              <div class="dbv9-preview-foot"><span><i></i> Aperçu local instantané</span><small>Sans requête réseau</small></div>
            </div>
          </aside>
        </div>

        <template id="dbv9MenuRowTemplate"><div class="dbv9-repeat-row" draggable="true" data-repeat="menu"><div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button><span class="dbv9-item-icon-dot"></span></div><div class="dbv9-repeat-grid menu-grid"><label>Titre<input data-name="title" placeholder="Titre"></label><label>Sous-titre<input data-name="subtitle" placeholder="Sous-titre"></label><label>Lien<input data-name="url" placeholder="/lien/"></label><label>Icône native<input data-name="icon" value="dashicons-arrow-right-alt2"></label><label>Image/SVG<input class="dsb-menu-image-url" data-name="image"><input type="hidden" class="dsb-menu-attachment-id" data-name="attachment_id" value="0"><button type="button" class="button dsb-menu-upload">Média</button></label><label>Couleur 1<input type="color" data-name="color1" value="#8b5cf6"></label><label>Couleur 2<input type="color" data-name="color2" value="#ec4899"></label><label>Badge<input data-name="badge"></label><label>Groupe<select data-name="group"><option value="main">Principal</option><option value="bonus">Bonus</option><option value="footer">Footer</option><option value="quick">Quick</option></select></label><label>Visibilité<select data-name="visibility"><option value="all">Tous</option><option value="logged_in">Connectés</option><option value="logged_out">Invités</option></select></label></div><div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove">×</button></div></div></template>
        <template id="dbv9QuickRowTemplate"><div class="dbv9-repeat-row" draggable="true" data-repeat="quick"><div class="dbv9-repeat-handle"><button type="button" class="dbv9-drag">⠿</button></div><div class="dbv9-repeat-grid quick-grid"><label>Icône<input data-name="icon" placeholder="⭐"></label><label>Image/SVG<input class="dsb-menu-image-url" data-name="image"><input type="hidden" class="dsb-menu-attachment-id" data-name="attachment_id" value="0"><button type="button" class="button dsb-menu-upload">Média</button></label><label>Libellé<input data-name="label" placeholder="Raccourci"></label><label>Lien<input data-name="url" placeholder="/lien/"></label><label>Visibilité<select data-name="visibility"><option value="all">Tous</option><option value="logged_in">Connectés</option><option value="logged_out">Invités</option></select></label></div><div class="dbv9-repeat-actions"><button type="button" data-move="up">↑</button><button type="button" data-move="down">↓</button><button type="button" class="dbv9-remove">×</button></div></div></template>
      </form>
    </div>
    <?php
}
function delicat_builder_v9_menu_menu_input($s,$key,$label,$type='text'){ echo '<div class="dsb-menu-field"><label>'.esc_html($label).'</label><input data-setting="'.esc_attr($key).'" type="'.esc_attr($type).'" name="dsb_menu_builder['.esc_attr($key).']" value="'.esc_attr($s[$key] ?? '').'"></div>'; }
function delicat_builder_v9_menu_menu_select($s,$key,$label,$opts){ echo '<div class="dsb-menu-field"><label>'.esc_html($label).'</label><select data-setting="'.esc_attr($key).'" name="dsb_menu_builder['.esc_attr($key).']">'; foreach($opts as $v=>$l) echo '<option value="'.esc_attr($v).'" '.selected($s[$key] ?? '',$v,false).'>'.esc_html($l).'</option>'; echo '</select></div>'; }

function delicat_builder_v9_menu_menu_item_visible($it) { $v = $it['visibility'] ?? 'all'; if ($v === 'logged_in' && !is_user_logged_in()) return false; if ($v === 'logged_out' && is_user_logged_in()) return false; return true; }
/* ============================================================
 * v8.1.0-rc.4 — LIVE BALANCE ANYWHERE IN THE MENU
 * ------------------------------------------------------------
 * The wallet card was the first place to go live; the menu links below it
 * showed the same money and stayed frozen. Any menu item, quick action or
 * bottom-nav label can now carry {balance} (and {points}) and it refreshes
 * on exactly the same poll as the card — no extra requests.
 *
 * Items that point at the wallet or recharge URL pick this up automatically,
 * so an existing menu needs no editing. Set "Balance On Wallet Links" to Non
 * to turn that off and place {balance} by hand instead.
 * ============================================================ */

/** Markup the poller recognises: same contract as the wallet card. */
function delicat_builder_v9_menu_menu_balance_html() {
    static $cache = null;                       // one wallet read per request
    if ($cache !== null) return $cache;
    if (!is_user_logged_in() || !function_exists('woo_wallet')) return ($cache = '');
    delicat_builder_v9_menu_mark_page_private();
    $raw  = delicat_builder_v9_menu_wallet_balance_raw();
    $disp = delicat_builder_v9_menu_wallet_balance_text();
    $cache = '<span class="dsb-live-balance" data-dsb-wallet="1" data-balance-raw="' . esc_attr((string) $raw) . '">'
           . '<span class="dsb-wallet-amount">' . $disp . '</span></span>';
    return $cache;
}

/**
 * Swap {balance} / {points} inside text that has ALREADY been escaped.
 * esc_html() leaves braces untouched, so the tokens survive it intact and
 * the surrounding copy stays escaped.
 */
function delicat_builder_v9_menu_menu_apply_tokens($escaped_text) {
    $escaped_text = (string) $escaped_text;
    if (strpos($escaped_text, '{balance}') === false && strpos($escaped_text, '{points}') === false) {
        return $escaped_text;
    }
    $s   = delicat_builder_v9_menu_get_menu_builder_settings();
    $bal = delicat_builder_v9_menu_menu_balance_html();
    if ($bal === '') {
        // Guest, or TeraWallet inactive: drop the token and tidy any orphan separator.
        $escaped_text = str_replace('{balance}', '', $escaped_text);
        $escaped_text = trim(preg_replace('/[\s·•|:–-]+$/u', '', $escaped_text));
    } else {
        $escaped_text = str_replace('{balance}', $bal, $escaped_text);
    }
    return str_replace('{points}', esc_html($s['loyalty_points']), $escaped_text);
}

/** True when two links resolve to the same page path. */
function delicat_builder_v9_menu_menu_url_matches($a, $b) {
    if (empty($a) || empty($b)) return false;
    $pa = trim((string) parse_url($a, PHP_URL_PATH), '/');
    $pb = trim((string) parse_url($b, PHP_URL_PATH), '/');
    return ($pa !== '' && $pa === $pb);
}

/** Does this item lead to the wallet? Then it may show the balance. */
function delicat_builder_v9_menu_menu_item_is_wallet($it) {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    if (($s['wallet_item_balance'] ?? 'yes') !== 'yes') return false;
    $url = $it['url'] ?? '';
    return delicat_builder_v9_menu_menu_url_matches($url, $s['wallet_url'] ?? '') || delicat_builder_v9_menu_menu_url_matches($url, $s['recharge_url'] ?? '');
}


/** Lightweight native SVG replacement for frontend Dashicons. */
function delicat_builder_v9_menu_native_icon_svg($icon) {
    $key = sanitize_key(str_replace('dashicons-', '', (string)$icon));
    $paths = array(
        'admin-home' => '<path d="M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z"/>',
        'portfolio' => '<rect x="3" y="6" width="18" height="14" rx="3"/><path d="M8 6V4h8v2M3 11h18"/>',
        'money-alt' => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5c-1-.8-2.1-1.1-3.4-1.1-1.8 0-3.1.8-3.1 2.1 0 3.3 6.5 1.4 6.5 4.9 0 1.4-1.4 2.3-3.4 2.3-1.5 0-2.8-.4-3.8-1.3M12 5.5v13"/>',
        'admin-network' => '<circle cx="6" cy="7" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="12" cy="17" r="2"/><path d="M8 8.2l3 6.3M16 8.2l-3 6.3M8 7h8"/>',
        'games' => '<path d="M7 8h10c2 0 3.5 1.5 4 4.2l.7 3.6c.4 2.2-2.2 3.4-3.6 1.8L16 15H8l-2.1 2.6c-1.4 1.6-4 .4-3.6-1.8l.7-3.6C3.5 9.5 5 8 7 8z"/><path d="M7 11v4M5 13h4M16 12h.01M19 14h.01"/>',
        'awards' => '<path d="m12 3 2.1 4.3 4.8.7-3.5 3.4.8 4.8L12 14l-4.2 2.2.8-4.8L5.1 8l4.8-.7z"/><path d="M9 15.5 8 22l4-2 4 2-1-6.5"/>',
        'star-filled' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
        'admin-users' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-5 3.3-7.5 7.5-7.5s6.8 2.5 7.5 7.5"/>',
        'arrow-right-alt2' => '<path d="m9 5 7 7-7 7"/>',
    );
    $path = $paths[$key] ?? '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>';
    return '<svg class="dbv9-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

function delicat_builder_v9_menu_render_menu_items($group = '') {
    $items = delicat_builder_v9_menu_get_menu_builder_items(); $out = '';
    foreach ($items as $it) { if ($group && ($it['group'] ?? 'main') !== $group) continue; if (!delicat_builder_v9_menu_menu_item_visible($it)) continue; $badge = trim($it['badge'] ?? '');
        $out .= '<a class="dsb-menu-item" href="'.esc_url($it['url']).'" style="--i1:'.esc_attr($it['color1']).';--i2:'.esc_attr($it['color2']).'">';
        $img = !empty($it['image']) ? esc_url($it['image']) : '';
        $icon_html = $img ? '<img src="'.$img.'" alt="" loading="lazy" decoding="async">' : delicat_builder_v9_menu_native_icon_svg($it['icon']);
        $out .= '<span class="dsb-menu-icon">'.$icon_html.'</span><span class="dsb-menu-label"><strong>'.delicat_builder_v9_menu_menu_apply_tokens(esc_html($it['title'])).'</strong>';

        $sub = (string) ($it['subtitle'] ?? '');
        $has_token = (strpos($sub, '{balance}') !== false);
        // A wallet link with no explicit token gets the balance appended.
        if (!$has_token && delicat_builder_v9_menu_menu_item_is_wallet($it)) {
            $bal = delicat_builder_v9_menu_menu_balance_html();
            if ($bal !== '') $sub = ($sub !== '') ? $sub . ' · {balance}' : '{balance}';
        }
        $sub_html = delicat_builder_v9_menu_menu_apply_tokens(esc_html($sub));
        if ($sub_html !== '') $out .= '<small>'.$sub_html.'</small>';

        if ($badge !== '') $out .= '<em>'.delicat_builder_v9_menu_menu_apply_tokens(esc_html($badge)).'</em>';
        $out .= '</span><span class="dsb-menu-arrow">›</span></a>';
    }
    return $out;
}
function delicat_builder_v9_menu_wallet_balance_text() { if (function_exists('woo_wallet')) { try { return wp_kses_post(woo_wallet()->wallet->get_wallet_balance(get_current_user_id())); } catch (Throwable $e) {} } return '—'; }
function delicat_builder_v9_menu_menu_account_url($endpoint = '') {
    if (function_exists('wc_get_account_endpoint_url')) {
        return $endpoint ? wc_get_account_endpoint_url($endpoint) : wc_get_page_permalink('myaccount');
    }
    return $endpoint ? site_url('/my-account/'.$endpoint.'/') : site_url('/my-account/');
}

function delicat_builder_v9_menu_get_delicat_user_code($user_id) {
    $code = get_user_meta($user_id, 'delicat_user_code', true);
    if (!$code && function_exists('delicat_cs_get_code')) {
        $code = delicat_cs_get_code($user_id);
    }
    return $code ? $code : '—';
}

function delicat_builder_v9_menu_menu_dynamic_text($text, $user = null) {
    $text = (string) $text;
    if (!$user || !($user instanceof WP_User)) return $text;
    $full = trim($user->first_name . ' ' . $user->last_name);
    if ($full === '') $full = $user->display_name ?: $user->user_login;
    $first = $user->first_name ?: $full;
    return strtr($text, array('{name}'=>$full, '{first_name}'=>$first, '{email}'=>$user->user_email));
}

function delicat_builder_v9_menu_render_profile_card() {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    delicat_builder_v9_menu_menu_builder_assets();

    $logout_url = class_exists('Delicat_Builder_V9_Identity_Bridge', false) ? Delicat_Builder_V9_Identity_Bridge::logout_url() : wp_logout_url(home_url('/'));
    ob_start();

    if (is_user_logged_in()) {
        $u = wp_get_current_user();
        $full = trim($u->first_name . ' ' . $u->last_name);
        if ($full === '') $full = $u->display_name ? $u->display_name : $u->user_login;
        $initial = function_exists('mb_substr') ? mb_substr(trim($full), 0, 1, 'UTF-8') : substr(trim($full), 0, 1);
        $initials = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
        $email = $u->user_email;
        $code = delicat_builder_v9_menu_get_delicat_user_code($u->ID);
        $avatar = get_avatar($u->ID, 96, '', $full, array('class'=>'dsb-profile-img'));
        ?>
        <div class="dsb-menu-profile dsb-account-card dsb-account-logged-in dsb-profile-align-<?php echo esc_attr($s['profile_align'] ?? 'center'); ?>"<?php echo $s['profile_cover'] ? ' style="--dsb-cover:url('.esc_url($s['profile_cover']).')"' : ''; ?>>
            <div class="dsb-account-avatar-wrap">
                <a class="dsb-account-avatar dsb-avatar-photo" href="<?php echo esc_url(delicat_builder_v9_menu_menu_account_url('edit-account')); ?>#avatar" aria-label="Modifier la photo">
                    <?php echo wp_kses_post($avatar); ?>
                    <span class="dsb-avatar-fallback"><?php echo esc_html($initials); ?></span>
                </a>
            </div>

            <div class="dsb-account-content">
                <?php if (($s['show_header_title'] ?? 'yes') === 'yes'): ?>
                <?php if (!empty($s['header_subtitle'])): ?><p class="dsb-account-welcome"><?php echo esc_html(delicat_builder_v9_menu_menu_dynamic_text($s['header_subtitle'], $u)); ?></p><?php endif; ?>
                <h3 class="dsb-account-name"><?php echo esc_html(!empty($s['header_title']) ? delicat_builder_v9_menu_menu_dynamic_text($s['header_title'], $u) : $full); ?></h3>
                <?php if (!empty($s['profile_badge'])): ?><span class="dsb-account-badge"><?php echo esc_html($s['profile_badge']); ?></span><?php endif; ?>
                <?php if (empty($s['header_subtitle'])): ?><p class="dsb-account-email"><?php echo esc_html($email); ?></p><?php endif; ?>
                <?php endif; ?>

                <div class="dsb-account-code-row">
                    <span class="dsb-account-code-label">Code Client</span>
                    <code class="dsb-account-code" id="dsb-account-code-<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($code); ?></code>
                    <button type="button" class="dsb-account-copy" data-dsb-copy-code="<?php echo esc_attr($code); ?>" aria-label="Copier le code client">Copier</button>
                </div>

                <a class="dsb-account-logout" href="<?php echo esc_url($logout_url); ?>">Se déconnecter</a>
            </div>
        </div>
        <?php
    } else {
        ?>
        <div class="dsb-menu-profile dsb-account-card dsb-account-guest"<?php echo $s['profile_cover'] ? ' style="--dsb-cover:url('.esc_url($s['profile_cover']).')"' : ''; ?>>
            <div class="dsb-account-avatar-wrap">
                <div class="dsb-account-avatar dsb-account-avatar-guest" aria-hidden="true">
                    <?php if (!empty($s['profile_initial'])): ?><span class="dsb-account-initial"><?php echo esc_html($s['profile_initial']); ?></span><?php else: echo delicat_builder_v9_menu_native_icon_svg('dashicons-admin-users'); endif; ?>
                </div>
            </div>
            <div class="dsb-account-content">
                <?php if (($s['show_header_title'] ?? 'yes') === 'yes'): ?>
                <?php $guest_welcome = !empty($s['header_subtitle']) ? $s['header_subtitle'] : ($s['welcome_text'] ?? ''); ?>
                <?php if (!empty($guest_welcome)): ?><p class="dsb-account-welcome"><?php echo esc_html($guest_welcome); ?></p><?php endif; ?>
                <h3 class="dsb-account-name"><?php echo esc_html(!empty($s['header_title']) ? $s['header_title'] : (!empty($s['profile_name']) ? $s['profile_name'] : 'Connectez-vous')); ?></h3>
                <?php if (!empty($s['profile_badge'])): ?><span class="dsb-account-badge"><?php echo esc_html($s['profile_badge']); ?></span><?php endif; ?>
                <?php if (empty($s['header_subtitle']) && !empty($s['profile_email'])): ?><p class="dsb-account-email"><?php echo esc_html($s['profile_email']); ?></p><?php endif; ?>
                <?php endif; ?>
                <button type="button" class="dsb-account-login" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal">Connexion</button>
            </div>
        </div>
        <?php
    }

    return ob_get_clean();
}

/* ============================================================
 * v8.1.0-rc.3 — LIVE WALLET BALANCE ("Mon portefeuille")
 * ------------------------------------------------------------
 * The menu drawer is rendered once per page load and is then held by
 * LiteSpeed / Cloudflare, so the balance printed inside it went stale the
 * moment a client recharged. The card now carries the raw figure plus a
 * hook the front end can refresh in place against a read-only endpoint.
 *
 * A manually pinned balance (Wallet Balance != "Auto") is never touched:
 * the live attributes are only emitted for the automatic figure.
 * ============================================================ */
/**
 * A rendered balance makes the whole page personal. If LiteSpeed or Cloudflare
 * ever stores that HTML, the next signed-in visitor is served someone else's
 * money — the figure is baked into the markup, so no nonce or capability check
 * downstream can undo it. Mark the response private the moment we print one.
 *
 * Belt and braces: the standard DONOTCACHEPAGE flag, LiteSpeed's own control
 * hook, and an explicit private/no-store header for Cloudflare when headers
 * have not gone out yet.
 */
function delicat_builder_v9_menu_mark_page_private() {
    static $done = false;
    if ($done) return;
    $done = true;

    if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);
    if (!defined('DONOTCACHEOBJECT')) define('DONOTCACHEOBJECT', true);


    if (!headers_sent()) {
        header('Cache-Control: private, no-store, max-age=0');
    }
}

function delicat_builder_v9_menu_wallet_balance_raw($user_id = 0) {
    $user_id = $user_id ? (int) $user_id : get_current_user_id();
    if ($user_id > 0 && function_exists('woo_wallet')) {
        try { return (float) woo_wallet()->wallet->get_wallet_balance($user_id, 'edit'); } catch (Throwable $e) {}
    }
    return 0.0;
}

function delicat_builder_v9_menu_render_wallet_card() {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    delicat_builder_v9_menu_menu_builder_assets();

    $auto = ($s['wallet_balance'] === 'Auto');
    $live = ($auto && is_user_logged_in() && function_exists('woo_wallet'));
    $bal  = $auto ? delicat_builder_v9_menu_wallet_balance_text() : esc_html($s['wallet_balance']);

    $attr = ' class="dsb-wallet-card"';
    if ($live) {
        delicat_builder_v9_menu_mark_page_private();
        $attr .= ' data-dsb-wallet="1" data-balance-raw="' . esc_attr((string) delicat_builder_v9_menu_wallet_balance_raw()) . '"';
    }

    return '<div' . $attr . '>'
        . delicat_builder_v9_menu_native_icon_svg('dashicons-money-alt')
        . '<div><small>' . esc_html($s['wallet_label']) . '</small>'
        . '<strong class="dsb-wallet-amount">' . $bal . '</strong>'
        . '<em>⭐ ' . esc_html($s['loyalty_points']) . ' points</em></div>'
        . '<a href="' . esc_url($s['recharge_url']) . '">Recharger</a>'
        . '</div>';
}

/**
 * Read-only balance endpoint used by the drawer card.
 * Reads TeraWallet and nothing else — it never credits, debits or writes.
 */
add_action('wp_ajax_delicat_builder_v9_wallet_balance', 'delicat_builder_v9_menu_ajax_wallet_balance');
function delicat_builder_v9_menu_ajax_wallet_balance() {
    // Never let LiteSpeed / Cloudflare / the browser hand back a stale figure.
    nocache_headers();

    if (!check_ajax_referer('delicat_builder_v9_wallet_live', 'nonce', false)) {
        wp_send_json_error(array('message' => 'Requête non autorisée.'), 403);
    }
    if (!is_user_logged_in()) {
        wp_send_json_error(array('message' => 'Non connecté.'), 403);
    }

    $uid = get_current_user_id();

    // Fixed one-minute window. A sliding expiry (refreshing the TTL on every
    // hit) never actually resets for a client who keeps polling, so an open
    // drawer would creep to the ceiling and lock out a legitimate customer
    // after ~25 minutes. Bucketing by minute makes the window really reset.
    $bucket = 'dlcv9_wbl_' . $uid . '_' . (int) floor(time() / 60);
    $hits   = (int) get_transient($bucket);
    if ($hits >= 60) {
        wp_send_json_error(array('message' => 'Trop de requêtes.'), 429);
    }
    set_transient($bucket, $hits + 1, 120);

    $raw  = 0.0;
    $html = '';
    if (function_exists('woo_wallet')) {
        try {
            $raw  = (float) woo_wallet()->wallet->get_wallet_balance($uid, 'edit');
            $html = wp_kses_post(woo_wallet()->wallet->get_wallet_balance($uid));
        } catch (Throwable $e) {}
    }

    wp_send_json_success(array(
        'raw'      => $raw,
        'html'     => $html,
        'currency' => function_exists('get_woocommerce_currency') ? get_woocommerce_currency() : 'HTG',
    ));
}

/**
 * The poller only ships for signed-in visitors — a guest has no balance to
 * refresh, so guests pay nothing for this feature.
 */
add_action('wp_enqueue_scripts', 'delicat_builder_v9_menu_wallet_live_assets', 20);
function delicat_builder_v9_menu_wallet_live_assets() {
    if (is_admin() || !is_user_logged_in() || function_exists('dsb_render_modern_menu')) return;
    $settings = delicat_builder_v9_menu_get_menu_builder_settings();
    if (($settings['enabled'] ?? 'yes') !== 'yes' || ($settings['show_wallet'] ?? 'yes') !== 'yes') return;
    wp_enqueue_script('delicat-builder-v9-wallet-live', DELICAT_BUILDER_V9_URL . 'assets/dsb810-wallet-live.js', array(), DELICAT_BUILDER_V9_VERSION, true);
    wp_localize_script('delicat-builder-v9-wallet-live', 'delicatBuilderV9WalletLive', array(
        'url'   => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('delicat_builder_v9_wallet_live'),
        'idle'  => 45000,
        'watch' => 5000,
    ));
}
/* ============================================================
 * v8.1.0-rc.6 — QUICK ACTIONS BUILDER
 * ------------------------------------------------------------
 * The row under the wallet card used to be four fixed slots with hardcoded
 * emoji, hardcoded labels and two hardcoded links, each with only an on/off
 * switch. It is now an ordinary repeatable list: add, remove, reorder, and
 * set the label, link, icon (emoji OR uploaded image/SVG) and visibility of
 * every tile.
 *
 * Nothing changes on upgrade. Until the new table is saved for the first
 * time, the row is rebuilt from the old switches so it renders exactly as
 * it does today.
 * ============================================================ */

/** Rebuild the legacy four-slot row, so an un-migrated site looks identical. */
function delicat_builder_v9_menu_menu_quick_defaults() {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    $out = array();
    if (($s['quick_wallet']  ?? 'yes') === 'yes') $out[] = array('icon'=>'💳','image'=>'','attachment_id'=>0,'label'=>'Wallet','url'=>$s['wallet_url'],'visibility'=>'all');
    if (($s['quick_orders']  ?? 'yes') === 'yes') $out[] = array('icon'=>'📦','image'=>'','attachment_id'=>0,'label'=>'Commandes','url'=>delicat_builder_v9_menu_menu_account_url('orders'),'visibility'=>'all');
    if (($s['quick_support'] ?? 'yes') === 'yes') $out[] = array('icon'=>'💬','image'=>'','attachment_id'=>0,'label'=>'Support','url'=>$s['whatsapp_url'],'visibility'=>'all');
    if (($s['quick_app']     ?? 'yes') === 'yes') $out[] = array('icon'=>'📲','image'=>'','attachment_id'=>0,'label'=>'App','url'=>'#install-app','visibility'=>'all');
    return $out;
}

function delicat_builder_v9_menu_sanitize_menu_quick_items($raw) {
    $out = array();
    if (!is_array($raw)) return $out;
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        $label = sanitize_text_field($r['label'] ?? '');
        $icon  = sanitize_text_field($r['icon']  ?? '');
        $image = esc_url_raw($r['image'] ?? '');
        // A tile with no label and no icon at all is an empty row: drop it.
        if ($label === '' && $icon === '' && $image === '') continue;
        $out[] = array(
            'icon'          => $icon,
            'image'         => $image,
            'attachment_id' => absint($r['attachment_id'] ?? 0),
            'label'         => $label,
            'url'           => esc_url_raw($r['url'] ?? '#'),
            'visibility'    => in_array(($r['visibility'] ?? 'all'), array('all','logged_in','logged_out'), true) ? $r['visibility'] : 'all',
        );
    }
    return $out;
}

/**
 * Saved rows win. get_option() returns null only when the table has never
 * been saved, which is what separates "not migrated yet" from "the shop
 * owner deliberately emptied the row".
 */
function delicat_builder_v9_menu_get_menu_quick_items() {
    $saved = get_option('dsb_menu_quick_items', null);
    if (!is_array($saved)) return delicat_builder_v9_menu_menu_quick_defaults();
    return delicat_builder_v9_menu_sanitize_menu_quick_items($saved);
}

function delicat_builder_v9_menu_render_quick_actions() {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    if ($s['quick_actions'] !== 'yes') return '';
    delicat_builder_v9_menu_menu_builder_assets();

    $visible = array();
    foreach (delicat_builder_v9_menu_get_menu_quick_items() as $it) {
        if (delicat_builder_v9_menu_menu_item_visible($it)) $visible[] = $it;
    }
    if (!$visible) return '';

    // Up to four across; more than that wraps onto a second row.
    $cols = min(4, max(1, count($visible)));
    $out  = '<div class="dsb-quick-actions" style="--dsb-quick-cols:'.(int)$cols.'">';
    foreach ($visible as $it) {
        $icon = !empty($it['image'])
            ? '<img src="'.esc_url($it['image']).'" alt="" loading="lazy" decoding="async">'
            : '<span>'.esc_html($it['icon'] !== '' ? $it['icon'] : '•').'</span>';
        $out .= '<a href="'.esc_url($it['url']).'">'.$icon
              . '<small>'.delicat_builder_v9_menu_menu_apply_tokens(esc_html($it['label'])).'</small></a>';
    }
    return $out.'</div>';
}

function delicat_builder_v9_menu_render_custom_menu_header() {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    if (($s['custom_header_enabled'] ?? 'yes') !== 'yes') return '';
    $img = !empty($s['custom_header_image']) ? '<img src="'.esc_url($s['custom_header_image']).'" alt="" loading="eager" decoding="async">' : '<span class="dsb-custom-menu-header-mark">S</span>';
    return '<a class="dsb-custom-menu-header dsb-header-style-'.esc_attr($s['custom_header_style']).' dsb-header-align-'.esc_attr($s['custom_header_align']).'" href="'.esc_url($s['custom_header_url'] ?: home_url('/')).'">'.$img.'<span><strong>'.esc_html($s['custom_header_title']).'</strong><small>'.esc_html($s['custom_header_subtitle']).'</small></span></a>';
}

function delicat_builder_v9_menu_render_modern_menu($mode = 'drawer') {
    $s = delicat_builder_v9_menu_get_menu_builder_settings();
    if (($s['enabled'] ?? 'yes') === 'no') return '';
    delicat_builder_v9_menu_menu_builder_assets();
    $resolved_mode = $mode ?: ($s['layout'] ?? 'drawer');
    $classes = 'dsb-modern-menu dsb-menu-preset-'.sanitize_html_class($s['menu_preset'] ?? 'apple-glass')
        .' dsb-menu-layout-'.sanitize_html_class($resolved_mode)
        .' dsb-menu-pos-'.sanitize_html_class($s['position'])
        .' dsb-menu-dark-'.sanitize_html_class($s['dark_model'])
        .' dsb-menu-light-'.sanitize_html_class($s['light_model'])
        .' dsb-anim-'.sanitize_html_class($s['animation'])
        .' dsb-hover-'.sanitize_html_class($s['hover_effect'])
        .' dsb-scroll-lock-'.sanitize_html_class($s['body_scroll_lock']);
    $is_inline = ($resolved_mode === 'inline');
    ob_start(); ?>
    <aside class="<?php echo esc_attr($classes); ?>"
        style="<?php echo esc_attr(delicat_builder_v9_menu_menu_style_vars($s)); ?>"
        aria-label="Menu Delicat Store Haiti"
        aria-hidden="<?php echo $is_inline ? 'false' : 'true'; ?>"
        data-close-overlay="<?php echo esc_attr($s['close_on_overlay']); ?>"
        data-scroll-lock="<?php echo esc_attr($s['body_scroll_lock']); ?>"
        data-swipe-close="<?php echo esc_attr($s['swipe_close']); ?>"
        data-performance="<?php echo esc_attr($s['performance_mode'] ?? 'auto'); ?>"
        data-adaptive-blur="<?php echo esc_attr($s['adaptive_blur'] ?? 'yes'); ?>"
        data-reduced-motion="<?php echo esc_attr($s['reduced_motion'] ?? 'respect'); ?>">
        <?php if (!$is_inline): ?><button type="button" class="dsb-menu-close" aria-label="Fermer le menu">×</button><?php endif; ?>
        <?php foreach (explode(',', $s['section_order']) as $section) {
            switch ($section) {
                case 'custom_header': echo delicat_builder_v9_menu_render_custom_menu_header(); break;
                case 'profile': if ($s['show_profile'] === 'yes') echo delicat_builder_v9_menu_render_profile_card(); break;
                case 'wallet': if ($s['show_wallet'] === 'yes') echo delicat_builder_v9_menu_render_wallet_card(); break;
                case 'quick': echo delicat_builder_v9_menu_render_quick_actions(); break;
                case 'main': echo '<div class="dsb-menu-list">'.delicat_builder_v9_menu_render_menu_items('main').'</div>'; break;
                case 'bonus': if ($s['bonus_enabled']==='yes') echo '<div class="dsb-menu-divider"><span>'.esc_html($s['bonus_title']).'</span></div><div class="dsb-menu-list dsb-menu-list-bonus">'.delicat_builder_v9_menu_render_menu_items('bonus').'</div>'; break;
                case 'promo': if ($s['promo_enabled'] === 'yes') echo do_shortcode('[delicat_menu_promo]'); break;
                case 'social': if ($s['social_enabled'] === 'yes') echo do_shortcode('[delicat_social_row]'); break;
                case 'footer': if ($s['footer_toggle'] === 'yes') echo '<div class="dsb-menu-footer-toggle"><span>🌙 Mode Sombre</span>'.do_shortcode('[delicat_theme_toggle label="no"]').'</div>'; break;
            }
        } ?>
        <?php if ($s['wave_footer'] === 'yes'): ?><div class="dsb-menu-wave" aria-hidden="true"><span>◇</span></div><?php endif; ?>
    </aside><?php if (!$is_inline): ?><div class="dsb-menu-backdrop" style="<?php echo esc_attr(delicat_builder_v9_menu_menu_style_vars($s)); ?>" aria-hidden="true"></div><?php endif; ?>
    <?php return ob_get_clean();
}

function delicat_builder_v9_menu_register_shortcodes() {
    if (function_exists('dsb_render_modern_menu')) return;
    if (!shortcode_exists('delicat_theme_toggle')) {
        add_shortcode('delicat_theme_toggle', function($atts){
            $atts = shortcode_atts(array('label'=>'no'), $atts, 'delicat_theme_toggle');
            $label = ($atts['label'] === 'yes') ? '<span>Thème</span>' : '';
            return '<button type="button" class="dsb-menu-theme-toggle" data-delicat-theme-toggle aria-label="Changer le thème" aria-pressed="false"><span aria-hidden="true">◐</span>'.$label.'</button>';
        });
    }
add_shortcode('delicat_modern_menu', function($atts){ $atts = shortcode_atts(array('layout'=>''), $atts, 'delicat_modern_menu'); return delicat_builder_v9_menu_render_modern_menu($atts['layout']); });
add_shortcode('delicat_menu_toggle', function($atts){ delicat_builder_v9_menu_menu_builder_assets(); $atts = shortcode_atts(array('label'=>'no'), $atts, 'delicat_menu_toggle'); return '<button type="button" class="dsb-menu-toggle" aria-label="Ouvrir le menu"><span></span><span></span><span></span>'.(($atts['label']==='yes')?'<em>Menu</em>':'').'</button>'; });
add_shortcode('delicat_menu_promo', function(){ $s = delicat_builder_v9_menu_get_menu_builder_settings(); delicat_builder_v9_menu_menu_builder_assets(); return '<div class="dsb-menu-promo"><div class="dsb-menu-gift">🎁</div><div><strong>'.esc_html($s['promo_title']).'</strong><p>'.esc_html($s['promo_text']).'</p>'.($s['promo_countdown']?'<small>'.esc_html($s['promo_countdown']).'</small>':'').'<a href="'.esc_url($s['promo_url']).'">'.esc_html($s['promo_button']).'</a></div></div>'; });
add_shortcode('delicat_profile_card', function(){ return delicat_builder_v9_menu_render_profile_card(); });
add_shortcode('delicat_wallet_card', function(){ return delicat_builder_v9_menu_render_wallet_card(); });
add_shortcode('delicat_quick_actions', function(){ return delicat_builder_v9_menu_render_quick_actions(); });
add_shortcode('delicat_bonus_menu', function(){ delicat_builder_v9_menu_menu_builder_assets(); return '<div class="dsb-menu-list dsb-menu-list-bonus">'.delicat_builder_v9_menu_render_menu_items('bonus').'</div>'; });
add_shortcode('delicat_social_row', function(){
    $s=delicat_builder_v9_menu_get_menu_builder_settings(); delicat_builder_v9_menu_menu_builder_assets();
    $links=array('whatsapp'=>'☘','telegram'=>'✈','instagram'=>'◎','facebook'=>'f','tiktok'=>'♪','youtube'=>'▶');
    $style='--dsb-social-size:'.absint($s['social_button_size']).'px;--dsb-social-icon:'.absint($s['social_icon_size']).'px;--dsb-social-gap:'.absint($s['social_gap']).'px;--dsb-social-align:'.esc_attr($s['social_align']);
    $out='<div class="dsb-menu-social" style="'.$style.'">';
    foreach($links as $slug=>$fallback){
        if (($s[$slug.'_enabled'] ?? 'yes') !== 'yes') continue;
        $url=$s[$slug.'_url'] ?? '#'; if(empty($url)||$url==='#') continue;
        $icon_url=$s[$slug.'_icon'] ?? '';
        $label = $s[$slug.'_label'] ?? ucfirst($slug);
        $icon_html=$icon_url ? '<img src="'.esc_url($icon_url).'" alt="'.esc_attr($label).'" loading="lazy" decoding="async">' : '<span aria-hidden="true">'.$fallback.'</span>';
        $out.='<a href="'.esc_url($url).'" target="_blank" rel="noopener noreferrer" aria-label="'.esc_attr($label).'" title="'.esc_attr($label).'">'.$icon_html.'</a>';
    }
    return $out.'</div>';
});
add_shortcode('delicat_bottom_nav', function(){ $s=delicat_builder_v9_menu_get_menu_builder_settings(); if($s['bottom_nav']!=='yes') return ''; if(class_exists('Delicat_Builder_V9_Shell', false) && is_callable(array('Delicat_Builder_V9_Shell','mobile_nav_expected')) && Delicat_Builder_V9_Shell::mobile_nav_expected()) return ''; delicat_builder_v9_menu_menu_builder_style(); $items = array_slice(delicat_builder_v9_menu_get_menu_builder_items(), 0, 5); $out = '<nav class="dsb-bottom-nav '.($s['center_button']==='yes'?'dsb-bottom-center':'').'" aria-label="Navigation mobile" style="'.esc_attr(delicat_builder_v9_menu_menu_style_vars($s)).'">'; foreach ($items as $idx=>$it) { if(!delicat_builder_v9_menu_menu_item_visible($it)) continue; $nav_icon = !empty($it['image']) ? '<img src="'.esc_url($it['image']).'" alt="" loading="lazy" decoding="async">' : delicat_builder_v9_menu_native_icon_svg($it['icon']); $out .= '<a class="'.($idx===2?'is-center':'').'" href="'.esc_url($it['url']).'" style="--i1:'.esc_attr($it['color1']).';--i2:'.esc_attr($it['color2']).'">'.$nav_icon.'<small>'.delicat_builder_v9_menu_menu_apply_tokens(esc_html($it['title'])).'</small></a>'; } return $out.'</nav>'; });

}
add_action('init', 'delicat_builder_v9_menu_register_shortcodes', 99);


/** Native V9 adapter for the migrated Menu Builder. */
final class Delicat_Builder_V9_Menu_Builder {
    public const SETTINGS_OPTION = 'dsb_menu_builder_settings';
    public const ITEMS_OPTION = 'dsb_menu_builder_items';
    public const QUICK_OPTION = 'dsb_menu_quick_items';
    public static function boot(): void {}
    public static function admin_page(): void { delicat_builder_v9_menu_menu_builder_admin_page(); }
    public static function settings(): array { return delicat_builder_v9_menu_get_menu_builder_settings(); }
    public static function items(): array { return delicat_builder_v9_menu_get_menu_builder_items(); }
    public static function render(string $mode = 'drawer'): string {
        if (function_exists('dsb_render_modern_menu')) return (string) dsb_render_modern_menu($mode);
        return (string) delicat_builder_v9_menu_render_modern_menu($mode);
    }
}

endif; /* RC18 single-declaration guard */
