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
        array('title'=>'FREE FIRE','subtitle'=>'Diamants instantanés','url'=>'/product-category/free-fire/','icon'=>'dashicons-games','image'=>'','color1'=>'#6366f1','color2'=>'#8b5cf6','badge'=>'GAME','group'=>'main','visibility'=>'all'),
        array('title'=>'Rewards Program','subtitle'=>'Points et cadeaux','url'=>'/rewards/','icon'=>'dashicons-awards','image'=>'','color1'=>'#a855f7','color2'=>'#f97316','badge'=>'NEW','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Meilleur client','subtitle'=>'Classement clients','url'=>'/meilleur-client/','icon'=>'dashicons-star-filled','image'=>'','color1'=>'#f59e0b','color2'=>'#ef4444','badge'=>'HOT','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Exchange USDT - USD','subtitle'=>'Services financiers','url'=>'/product-category/exchange/','icon'=>'dashicons-money-alt','image'=>'','color1'=>'#10b981','color2'=>'#06b6d4','badge'=>'','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Jeux Disponibles','subtitle'=>'Tous les jeux','url'=>'/product-category/jeux/','icon'=>'dashicons-games','image'=>'','color1'=>'#2563eb','color2'=>'#8b5cf6','badge'=>'','group'=>'bonus','visibility'=>'all'),
        array('title'=>'Gift Card','subtitle'=>'Apple, Google, PSN','url'=>'/product-category/gift-card/','icon'=>'dashicons-awards','image'=>'','color1'=>'#ec4899','color2'=>'#f43f5e','badge'=>'','group'=>'bonus','visibility'=>'all'),
    );
}

/**
 * Runtime-only normalization for previously saved/legacy menu options.
 * Admin saves are already sanitized, but frontend code must not trust a
 * corrupted option because malformed arrays/numbers can otherwise trigger
 * PHP 8 TypeErrors or restore the drawer overflow this release is fixing.
 */
function delicat_builder_v9_menu_runtime_scalar($value, $fallback = '') {
    return is_scalar($value) ? (string) $value : (string) $fallback;
}

function delicat_builder_v9_menu_normalize_runtime_settings($saved) {
    $d = delicat_builder_v9_menu_menu_builder_defaults();
    $s = wp_parse_args(is_array($saved) ? $saved : array(), $d);
    $yesno = array('enabled','custom_header_enabled','close_on_overlay','body_scroll_lock','swipe_close','show_profile','show_header_title','show_wallet','wallet_item_balance','promo_enabled','quick_actions','quick_wallet','quick_orders','quick_support','quick_app','bonus_enabled','social_enabled','whatsapp_enabled','telegram_enabled','instagram_enabled','facebook_enabled','tiktok_enabled','youtube_enabled','footer_toggle','wave_footer','bottom_nav','center_button','adaptive_blur');
    foreach ($yesno as $key) {
        $value = strtolower(trim(delicat_builder_v9_menu_runtime_scalar($s[$key] ?? $d[$key], $d[$key])));
        if (in_array($value, array('yes','1','true','on'), true)) $s[$key] = 'yes';
        elseif (in_array($value, array('no','0','false','off',''), true)) $s[$key] = 'no';
        else $s[$key] = $d[$key];
    }
    $enums = array(
        'menu_preset' => array('apple-glass','gaming-hub','material-you','neon-cyber','luxury-minimal','dashboard-cards','custom'),
        'layout' => array('drawer','inline','compact','fullscreen'),
        'position' => array('left','right','fullscreen'),
        'dark_model' => array('neo-glass','gradient-glow','minimal-dark','royal-glass','cyberpunk','amoled','gaming','playstation','steam'),
        'light_model' => array('soft-glass','gradient-light','minimal-light','clean-white','apple','material','modern-white'),
        'custom_header_style' => array('glass','solid','gradient','minimal'),
        'custom_header_align' => array('left','center'),
        'social_align' => array('left','center','right'),
        'profile_align' => array('left','center'),
        'animation' => array('none','fade','slide','stagger','elastic','glow'),
        'hover_effect' => array('glow','scale','magnetic','minimal'),
        'reduced_motion' => array('respect','ignore'),
        'performance_mode' => array('auto','balanced','ultra-lite'),
        'mobile_unit' => array('vw','%'),
    );
    foreach ($enums as $key => $allowed) {
        $value = delicat_builder_v9_menu_runtime_scalar($s[$key] ?? $d[$key], $d[$key]);
        $s[$key] = in_array($value, $allowed, true) ? $value : $d[$key];
    }
    $ranges = array(
        'desktop_width'=>array(280,720),'tablet_width'=>array(280,640),'mobile_width'=>array(55,96),
        'overlay_opacity'=>array(0,85),'blur_strength'=>array(0,24),'open_speed'=>array(120,700),
        'radius'=>array(0,40),'item_radius'=>array(0,32),'gap'=>array(4,24),
        'mobile_padding'=>array(6,28),'desktop_padding'=>array(8,40),
        'social_icon_size'=>array(14,48),'social_button_size'=>array(36,80),'social_gap'=>array(4,30),
    );
    foreach ($ranges as $key => $range) {
        $raw = $s[$key] ?? $d[$key];
        $value = is_numeric($raw) ? (int) $raw : (int) $d[$key];
        $s[$key] = max($range[0], min($range[1], $value));
    }
    $s['width'] = $s['desktop_width'];
    foreach (array('primary','secondary','accent') as $key) {
        $s[$key] = sanitize_hex_color(delicat_builder_v9_menu_runtime_scalar($s[$key] ?? $d[$key], $d[$key])) ?: $d[$key];
    }
    foreach (array('custom_header_title','custom_header_subtitle','header_title','header_subtitle','profile_name','profile_email','profile_badge','profile_initial','welcome_text','wallet_label','wallet_balance','loyalty_points','promo_title','promo_text','promo_button','promo_countdown','whatsapp_label','telegram_label','instagram_label','facebook_label','tiktok_label','youtube_label','bonus_title') as $key) {
        $s[$key] = sanitize_text_field(delicat_builder_v9_menu_runtime_scalar($s[$key] ?? $d[$key], $d[$key]));
    }
    foreach (array('custom_header_image','custom_header_url','profile_cover','wallet_url','recharge_url','promo_url','whatsapp_url','telegram_url','instagram_url','facebook_url','tiktok_url','youtube_url','whatsapp_icon','telegram_icon','instagram_icon','facebook_icon','tiktok_icon','youtube_icon') as $key) {
        $s[$key] = esc_url_raw(delicat_builder_v9_menu_runtime_scalar($s[$key] ?? $d[$key], $d[$key]));
    }
    $allowed_sections = array('custom_header','profile','wallet','quick','main','bonus','promo','social','footer');
    $section_order = delicat_builder_v9_menu_runtime_scalar($s['section_order'] ?? $d['section_order'], $d['section_order']);
    $requested = array_values(array_unique(array_intersect(array_filter(array_map('sanitize_key', explode(',', $section_order))), $allowed_sections)));
    foreach ($allowed_sections as $section) if (!in_array($section, $requested, true)) $requested[] = $section;
    $s['section_order'] = implode(',', $requested);
    return $s;
}

function delicat_builder_v9_menu_normalize_runtime_items($items) {
    $out = array();
    foreach ((array) $items as $item) {
        if (!is_array($item)) continue;
        $title = sanitize_text_field(delicat_builder_v9_menu_runtime_scalar($item['title'] ?? '', ''));
        if ($title === '') continue;
        $visibility = delicat_builder_v9_menu_runtime_scalar($item['visibility'] ?? 'all', 'all');
        $group = delicat_builder_v9_menu_runtime_scalar($item['group'] ?? 'main', 'main');
        $url = delicat_builder_v9_menu_runtime_scalar($item['url'] ?? '#', '#');
        /* RC87: heal routes saved by older Builder releases. These exact paths are
         * storefront-owned legacy aliases, never arbitrary external URLs. */
        $path = wp_parse_url($url, PHP_URL_PATH);
        if (in_array($path, array('/exchange','/exchange/'), true)) $url = '/product-category/exchange/';
        if (in_array($path, array('/product-category/streaming','/product-category/streaming/'), true)) $url = '/product-category/abonnement/';
        $out[] = array(
            'title' => $title,
            'subtitle' => sanitize_text_field(delicat_builder_v9_menu_runtime_scalar($item['subtitle'] ?? '', '')),
            'url' => esc_url_raw($url),
            'icon' => sanitize_html_class(delicat_builder_v9_menu_runtime_scalar($item['icon'] ?? 'dashicons-arrow-right-alt2', 'dashicons-arrow-right-alt2')),
            'image' => esc_url_raw(delicat_builder_v9_menu_runtime_scalar($item['image'] ?? '', '')),
            'attachment_id' => absint(is_numeric($item['attachment_id'] ?? 0) ? $item['attachment_id'] : 0),
            'color1' => sanitize_hex_color(delicat_builder_v9_menu_runtime_scalar($item['color1'] ?? '#8b5cf6', '#8b5cf6')) ?: '#8b5cf6',
            'color2' => sanitize_hex_color(delicat_builder_v9_menu_runtime_scalar($item['color2'] ?? '#ec4899', '#ec4899')) ?: '#ec4899',
            'badge' => sanitize_text_field(delicat_builder_v9_menu_runtime_scalar($item['badge'] ?? '', '')),
            'group' => in_array($group, array('main','bonus','footer','quick'), true) ? $group : 'main',
            'visibility' => in_array($visibility, array('all','logged_in','logged_out'), true) ? $visibility : 'all',
        );
    }
    return $out;
}

function delicat_builder_v9_menu_get_menu_builder_settings() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $saved = get_option('dsb_menu_builder_settings', array());
    $cache = delicat_builder_v9_menu_normalize_runtime_settings($saved);
    return $cache;
}
function delicat_builder_v9_menu_get_menu_builder_items() {
    static $cache = null;
    if ($cache !== null) return $cache;
    $items = get_option('dsb_menu_builder_items', array());
    $cache = delicat_builder_v9_menu_normalize_runtime_items(is_array($items) && $items ? $items : delicat_builder_v9_menu_menu_builder_default_items());
    if (!$cache) $cache = delicat_builder_v9_menu_menu_builder_default_items();
    return $cache;
}
function delicat_builder_v9_menu_register_assets() {
    if (function_exists('dsb_render_modern_menu')) return; // legacy Builder owns frontend until deactivated
    if (!wp_style_is('delicat-builder-v9-modern-menu', 'registered')) {
        wp_register_style('delicat-builder-v9-modern-menu', DELICAT_BUILDER_V9_URL . 'assets/dsb-modern-menu.css', array(), DELICAT_BUILDER_V9_VERSION);
        wp_register_style('delicat-builder-v9-menu-presets', DELICAT_BUILDER_V9_URL . 'assets/dsb-menu-presets.css', array('delicat-builder-v9-modern-menu'), DELICAT_BUILDER_V9_VERSION);
    }
    if (!wp_script_is('delicat-builder-v9-modern-menu', 'registered')) {
        wp_register_script('delicat-builder-v9-modern-menu', DELICAT_BUILDER_V9_URL . 'assets/dsb-modern-menu.js', array(), DELICAT_BUILDER_V9_VERSION, true);
        if (function_exists('wp_script_add_data')) wp_script_add_data('delicat-builder-v9-modern-menu','strategy','defer');
    }
}

function delicat_builder_v9_menu_menu_builder_style() {
    delicat_builder_v9_menu_register_assets();
    if (wp_style_is('delicat-builder-v9-modern-menu', 'registered')) wp_enqueue_style('delicat-builder-v9-modern-menu');
    if (function_exists('delicat_builder_v9_menu_get_menu_builder_settings')) {
        $dbv9_menu_s = delicat_builder_v9_menu_get_menu_builder_settings();
        if (is_array($dbv9_menu_s) && delicat_builder_v9_menu_needs_preset_css($dbv9_menu_s) && wp_style_is('delicat-builder-v9-menu-presets', 'registered')) {
            wp_enqueue_style('delicat-builder-v9-menu-presets');
        }
    }
}

function delicat_builder_v9_menu_menu_builder_assets() {
    delicat_builder_v9_menu_register_assets();
    if (wp_style_is('delicat-builder-v9-modern-menu', 'registered')) wp_enqueue_style('delicat-builder-v9-modern-menu');
    if (function_exists('delicat_builder_v9_menu_get_menu_builder_settings')) {
        $dbv9_menu_s = delicat_builder_v9_menu_get_menu_builder_settings();
        if (is_array($dbv9_menu_s) && delicat_builder_v9_menu_needs_preset_css($dbv9_menu_s) && wp_style_is('delicat-builder-v9-menu-presets', 'registered')) {
            wp_enqueue_style('delicat-builder-v9-menu-presets');
        }
    }
    if (wp_script_is('delicat-builder-v9-modern-menu', 'registered')) wp_enqueue_script('delicat-builder-v9-modern-menu');
}

function delicat_builder_v9_menu_maybe_enqueue_assets() {
    if (is_admin() || function_exists('dsb_render_modern_menu')) return;
    if (class_exists('Delicat_Builder_V9_Drawer', false) && Delicat_Builder_V9_Drawer::owns()) return; /* RC29: rebuilt drawer ships its own assets */
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
    $raw = trim((string)$icon);
    $clean = preg_replace('/[\x{FE0F}\x{FE0E}\x{20E3}]/u', '', $raw);
    $emoji = array(
        '🎮'=>'games','🕹'=>'games','🎁'=>'gift','💳'=>'card','📦'=>'package','💬'=>'chat','📲'=>'phone','📱'=>'phone',
        '🌙'=>'moon','🔔'=>'bell','🛒'=>'cart','💰'=>'money-alt','💵'=>'money-alt','⭐'=>'star-filled','🌟'=>'star-filled',
        '🏆'=>'awards','👤'=>'admin-users','🏠'=>'admin-home','⚡'=>'bolt','🔒'=>'lock','🔐'=>'lock','🛡'=>'shield'
    );
    $key = isset($emoji[$clean]) ? $emoji[$clean] : sanitize_key(str_replace('dashicons-', '', $raw));
    $paths = array(
        'admin-home' => '<path d="M3 11.5 12 4l9 7.5V21h-6v-6H9v6H3z"/>',
        'portfolio' => '<rect x="3" y="6" width="18" height="14" rx="3"/><path d="M8 6V4h8v2M3 11h18"/>',
        'money-alt' => '<circle cx="12" cy="12" r="9"/><path d="M15.5 8.5c-1-.8-2.1-1.1-3.4-1.1-1.8 0-3.1.8-3.1 2.1 0 3.3 6.5 1.4 6.5 4.9 0 1.4-1.4 2.3-3.4 2.3-1.5 0-2.8-.4-3.8-1.3M12 5.5v13"/>',
        'admin-network' => '<circle cx="6" cy="7" r="2"/><circle cx="18" cy="7" r="2"/><circle cx="12" cy="17" r="2"/><path d="M8 8.2l3 6.3M16 8.2l-3 6.3M8 7h8"/>',
        'games' => '<path d="M7 8h10c2 0 3.5 1.5 4 4.2l.7 3.6c.4 2.2-2.2 3.4-3.6 1.8L16 15H8l-2.1 2.6c-1.4 1.6-4 .4-3.6-1.8l.7-3.6C3.5 9.5 5 8 7 8z"/><path d="M7 11v4M5 13h4M16 12h.01M19 14h.01"/>',
        'awards' => '<path d="m12 3 2.1 4.3 4.8.7-3.5 3.4.8 4.8L12 14l-4.2 2.2.8-4.8L5.1 8l4.8-.7z"/><path d="M9 15.5 8 22l4-2 4 2-1-6.5"/>',
        'star-filled' => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1.1 6.2-5.6-2.9-5.6 2.9 1.1-6.2L3 9.6l6.2-.9z"/>',
        'admin-users' => '<circle cx="12" cy="8" r="4"/><path d="M4.5 21c.7-5 3.3-7.5 7.5-7.5s6.8 2.5 7.5 7.5V21"/>',
        'arrow-right-alt2' => '<path d="m9 5 7 7-7 7"/>',
        'card' => '<rect x="2.5" y="5" width="19" height="14" rx="2.5"/><path d="M2.5 10h19M6 15h4"/>',
        'package' => '<path d="m4 7 8-4 8 4-8 4-8-4Z"/><path d="M4 7v10l8 4 8-4V7M12 11v10"/>',
        'chat' => '<path d="M4 5h16v11H9l-5 4V5Z"/><path d="M8 10h8M8 13h5"/>',
        'phone' => '<rect x="7" y="2" width="10" height="20" rx="2"/><path d="M10 18h4"/>',
        'moon' => '<path d="M20 15.3A8.5 8.5 0 0 1 8.7 4 8.5 8.5 0 1 0 20 15.3Z"/>',
        'bell' => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9M10 21h4"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h3l3 12h11l2-8H7"/>',
        'gift' => '<rect x="3" y="8" width="18" height="13" rx="2"/><path d="M3 12h18M12 8v13M8 8a2.5 2.5 0 0 1 0-5c2 0 4 5 4 5s2-5 4-5a2.5 2.5 0 0 1 0 5"/>',
        'bolt' => '<path d="M13 2 5 13h6l-1 9 9-12h-6V2Z"/>',
        'lock' => '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
        'shield' => '<path d="M12 2l8 3v6c0 5-3 9-8 11-5-2-8-6-8-11V5z"/><path d="M9 12l2 2 4-4"/>'
    );
    $path = $paths[$key] ?? '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>';
    return '<svg class="dbv9-menu-svg" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">'.$path.'</svg>';
}

function delicat_builder_v9_menu_menu_item_is_current($url) {
    if (empty($url) || $url === '#') return false;
    $target = wp_parse_url($url, PHP_URL_PATH);
    if (!$target) return false;
    $target = '/' . trim((string)$target, '/') . '/';
    $current = isset($_SERVER['REQUEST_URI']) ? wp_parse_url(wp_unslash($_SERVER['REQUEST_URI']), PHP_URL_PATH) : '';
    $current = '/' . trim((string)$current, '/') . '/';
    return $target === $current;
}

/**
 * RC51.59: menu items saved before the permalink migration still carry
 * /categorie-produit/ URLs. The Storefront Fix 301 makes them land correctly,
 * but every tap paid a full redirect round trip on Haitian mobile RTTs.
 * Rewrite the href at render time so the link goes straight to the canonical
 * /product-category/ path; the stored option is left untouched.
 */

/* 9.1 RC3: the preset/skin theme rules live in their own sheet; only load it
 * when the configured preset, light model or dark model actually has rules
 * there. The stock apple-glass/soft-glass/neo-glass setup never pays. */
function delicat_builder_v9_menu_needs_preset_css(array $s): bool {
    $themed = array('dsb-menu-dark-amoled','dsb-menu-dark-cyberpunk','dsb-menu-dark-gaming','dsb-menu-dark-gradient-glow','dsb-menu-dark-playstation','dsb-menu-dark-royal-glass','dsb-menu-dark-steam','dsb-menu-light-apple','dsb-menu-light-clean-white','dsb-menu-light-gradient-light','dsb-menu-light-material','dsb-menu-light-minimal-light','dsb-menu-light-modern-white','dsb-menu-preset-custom','dsb-menu-preset-dashboard-cards','dsb-menu-preset-gaming-hub','dsb-menu-preset-luxury-minimal','dsb-menu-preset-material-you','dsb-menu-preset-neon-cyber');
    foreach (array('menu_preset' => 'dsb-menu-preset-', 'light_model' => 'dsb-menu-light-', 'dark_model' => 'dsb-menu-dark-') as $key => $prefix) {
        $value = sanitize_html_class((string)($s[$key] ?? ''));
        if ('' !== $value && in_array($prefix . $value, $themed, true)) {
            return true;
        }
    }
    return false;
}

function delicat_builder_v9_menu_normalize_item_url($url) {
    $url = (string) $url;
    if ('' === $url || false === strpos($url, '/categorie-produit/')) return $url;
    return str_replace('/categorie-produit/', '/product-category/', $url);
}

function delicat_builder_v9_menu_render_menu_items($group = '') {
    $items = delicat_builder_v9_menu_get_menu_builder_items(); $out = '';
    foreach ($items as $it) {
        if ($group && ($it['group'] ?? 'main') !== $group) continue;
        if (!delicat_builder_v9_menu_menu_item_visible($it)) continue;
        $badge = trim($it['badge'] ?? '');
        $item_url = delicat_builder_v9_menu_normalize_item_url($it['url'] ?? '');
        $current = delicat_builder_v9_menu_menu_item_is_current($item_url);
        $out .= '<a class="dsb-menu-item'.($current ? ' is-current' : '').'" href="'.esc_url($item_url).'" style="--i1:'.esc_attr($it['color1']).';--i2:'.esc_attr($it['color2']).'"'.($current ? ' aria-current="page"' : '').'>';
        $img = !empty($it['image']) ? esc_url($it['image']) : '';
        $icon_html = $img ? '<img src="'.$img.'" alt="" loading="lazy" decoding="async">' : delicat_builder_v9_menu_native_icon_svg($it['icon']);
        $out .= '<span class="dsb-menu-icon">'.$icon_html.'</span><span class="dsb-menu-label"><strong>'.delicat_builder_v9_menu_menu_apply_tokens(esc_html($it['title'])).'</strong>';

        $sub = (string) ($it['subtitle'] ?? '');
        $has_token = (strpos($sub, '{balance}') !== false);
        if (!$has_token && delicat_builder_v9_menu_menu_item_is_wallet($it)) {
            $bal = delicat_builder_v9_menu_menu_balance_html();
            if ($bal !== '') $sub = ($sub !== '') ? $sub . ' · {balance}' : '{balance}';
        }
        $sub_html = delicat_builder_v9_menu_menu_apply_tokens(esc_html($sub));
        if ($sub_html !== '') $out .= '<small>'.$sub_html.'</small>';
        if ($badge !== '') $out .= '<em>'.delicat_builder_v9_menu_menu_apply_tokens(esc_html($badge)).'</em>';
        $out .= '</span><span class="dsb-menu-arrow" aria-hidden="true">'.delicat_builder_v9_menu_native_icon_svg('arrow-right-alt2').'</span></a>';
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
        delicat_builder_v9_menu_mark_page_private();
        $u = wp_get_current_user();
        $full = trim($u->first_name . ' ' . $u->last_name);
        if ($full === '') $full = $u->display_name ? $u->display_name : $u->user_login;
        $initial = function_exists('mb_substr') ? mb_substr(trim($full), 0, 1, 'UTF-8') : substr(trim($full), 0, 1);
        $initials = function_exists('mb_strtoupper') ? mb_strtoupper($initial, 'UTF-8') : strtoupper($initial);
        $email = $u->user_email;
        $code = delicat_builder_v9_menu_get_delicat_user_code($u->ID);
        $avatar = get_avatar($u->ID, 96, '', $full, array('class'=>'dsb-profile-img', 'loading'=>'eager', 'decoding'=>'async'));
        ?>
        <section class="dsb-menu-profile dsb-account-card dsb-account-logged-in dsb-profile-align-<?php echo esc_attr($s['profile_align'] ?? 'center'); ?>"<?php echo $s['profile_cover'] ? ' style="--dsb-cover:url('.esc_url($s['profile_cover']).')"' : ''; ?> aria-label="Compte client">
            <div class="dsb-account-top">
                <div class="dsb-account-avatar-wrap">
                    <a class="dsb-account-avatar dsb-avatar-photo" href="<?php echo esc_url(delicat_builder_v9_menu_menu_account_url('edit-account')); ?>#avatar" aria-label="Modifier la photo">
                        <?php echo wp_kses_post($avatar); ?>
                        <span class="dsb-avatar-fallback"><?php echo esc_html($initials); ?></span>
                    </a>
                </div>
                <div class="dsb-account-identity">
                    <?php if (($s['show_header_title'] ?? 'yes') === 'yes'): ?>
                        <div class="dsb-account-title-row">
                            <h3 class="dsb-account-name"><?php echo esc_html(!empty($s['header_title']) ? delicat_builder_v9_menu_menu_dynamic_text($s['header_title'], $u) : $full); ?></h3>
                            <?php if (!empty($s['profile_badge'])): ?><span class="dsb-account-badge"><?php echo esc_html($s['profile_badge']); ?></span><?php endif; ?>
                        </div>
                        <?php if (!empty($s['header_subtitle'])): ?>
                            <p class="dsb-account-welcome"><?php echo esc_html(delicat_builder_v9_menu_menu_dynamic_text($s['header_subtitle'], $u)); ?></p>
                        <?php else: ?>
                            <p class="dsb-account-email"><?php echo esc_html($email); ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <a class="dsb-account-manage" href="<?php echo esc_url(delicat_builder_v9_menu_menu_account_url('edit-account')); ?>" aria-label="Gérer mon compte">
                    <?php echo delicat_builder_v9_menu_native_icon_svg('arrow-right-alt2'); ?>
                </a>
            </div>
            <div class="dsb-account-bottom">
                <div class="dsb-account-code-row">
                    <span class="dsb-account-code-label">Code client</span>
                    <code class="dsb-account-code" id="dsb-account-code-<?php echo esc_attr($u->ID); ?>"><?php echo esc_html($code); ?></code>
                    <button type="button" class="dsb-account-copy" data-dsb-copy-code="<?php echo esc_attr($code); ?>" aria-label="Copier le code client">
                        <span aria-hidden="true">⧉</span><b>Copier</b>
                    </button>
                </div>
                <a class="dsb-account-logout" href="<?php echo esc_url($logout_url); ?>">Déconnexion</a>
            </div>
        </section>
        <?php
    } else {
        ?>
        <section class="dsb-menu-profile dsb-account-card dsb-account-guest dsb-profile-align-<?php echo esc_attr($s['profile_align'] ?? 'center'); ?>"<?php echo $s['profile_cover'] ? ' style="--dsb-cover:url('.esc_url($s['profile_cover']).')"' : ''; ?> aria-label="Connexion">
            <div class="dsb-account-top">
                <div class="dsb-account-avatar-wrap">
                    <div class="dsb-account-avatar dsb-account-avatar-guest" aria-hidden="true">
                        <?php echo delicat_builder_v9_menu_native_icon_svg('admin-users'); ?>
                    </div>
                </div>
                <div class="dsb-account-identity">
                    <div class="dsb-account-title-row"><h3 class="dsb-account-name">Bienvenue</h3></div>
                    <p class="dsb-account-email">Connectez-vous pour retrouver portefeuille, commandes et récompenses.</p>
                </div>
            </div>
            <div class="dsb-account-bottom dsb-account-bottom-guest">
                <button type="button" class="dsb-account-login" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal">Se connecter</button>
            </div>
        </section>
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

    /* RC32: on public surfaces a signed-in customer's page goes to LiteSpeed's
     * private (per-user) cache instead of being uncacheable. */
    if (class_exists('Delicat_Builder_V9_Security', false) && is_callable(array('Delicat_Builder_V9_Security','private_cache_allowed')) && Delicat_Builder_V9_Security::private_cache_allowed()) {
        Delicat_Builder_V9_Security::hint_private_cache('Delicat V9 drawer account (signed-in)');
        return;
    }
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

    // Guests must never look like they own a G0 wallet. Keep the card useful,
    // but route it to the secure identity modal instead of the recharge page.
    if (!is_user_logged_in()) {
        return '<div class="dsb-wallet-card dsb-wallet-card-guest">'
            . delicat_builder_v9_menu_native_icon_svg('dashicons-money-alt')
            . '<div><small>' . esc_html($s['wallet_label']) . '</small>'
            . '<strong class="dsb-wallet-amount">—</strong>'
            . '<em>Connectez-vous pour voir votre solde</em></div>'
            . '<button type="button" class="dsb-wallet-login" data-dip-auth-open data-dl-open aria-haspopup="dialog" aria-controls="dip-identity-modal">Connexion</button>'
            . '</div>';
    }

    $auto = ($s['wallet_balance'] === 'Auto');
    $live = ($auto && function_exists('woo_wallet'));
    $bal  = $auto ? delicat_builder_v9_menu_wallet_balance_text() : esc_html($s['wallet_balance']);

    $attr = ' class="dsb-wallet-card"';
    if ($live) {
        delicat_builder_v9_menu_mark_page_private();
        // RC51.17: ship the tiny wallet poller only when a live card is actually
        // rendered. This removes it from unrelated storefront pages.
        delicat_builder_v9_menu_wallet_live_assets(true);
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
function delicat_builder_v9_menu_wallet_live_assets($from_render = false) {
    static $done = false;
    if ($done || is_admin() || !is_user_logged_in() || function_exists('dsb_render_modern_menu')) return;
    // This helper is intentionally render-driven. Calling it from a global
    // enqueue hook would load wallet JS on every signed-in storefront page.
    if (!$from_render) return;
    $done = true;
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
        $label = sanitize_text_field(delicat_builder_v9_menu_runtime_scalar($r['label'] ?? '', ''));
        $icon  = sanitize_text_field(delicat_builder_v9_menu_runtime_scalar($r['icon'] ?? '', ''));
        $image = esc_url_raw(delicat_builder_v9_menu_runtime_scalar($r['image'] ?? '', ''));
        // A tile with no label and no icon at all is an empty row: drop it.
        if ($label === '' && $icon === '' && $image === '') continue;
        $out[] = array(
            'icon'          => $icon,
            'image'         => $image,
            'attachment_id' => absint(is_numeric($r['attachment_id'] ?? 0) ? $r['attachment_id'] : 0),
            'label'         => $label,
            'url'           => esc_url_raw(delicat_builder_v9_menu_runtime_scalar($r['url'] ?? '#', '#')),
            'visibility'    => in_array(delicat_builder_v9_menu_runtime_scalar($r['visibility'] ?? 'all', 'all'), array('all','logged_in','logged_out'), true) ? delicat_builder_v9_menu_runtime_scalar($r['visibility'], 'all') : 'all',
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
            : '<span>'.delicat_builder_v9_menu_native_icon_svg($it['icon'] !== '' ? $it['icon'] : 'admin-home').'</span>';
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
    if ($mode !== 'inline' && class_exists('Delicat_Builder_V9_Drawer', false) && Delicat_Builder_V9_Drawer::owns()) return (string) Delicat_Builder_V9_Drawer::render(); /* RC29 */
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
        id="delicat-v9-modern-menu"
        style="<?php echo esc_attr(delicat_builder_v9_menu_menu_style_vars($s)); ?>"
        role="<?php echo $is_inline ? 'navigation' : 'dialog'; ?>"
        <?php if (!$is_inline): ?>aria-modal="true"<?php endif; ?>
        aria-label="Menu Delicat Store Haiti"
        aria-hidden="<?php echo $is_inline ? 'false' : 'true'; ?>"
        data-close-overlay="<?php echo esc_attr($s['close_on_overlay']); ?>"
        data-scroll-lock="<?php echo esc_attr($s['body_scroll_lock']); ?>"
        data-swipe-close="<?php echo esc_attr($s['swipe_close']); ?>"
        data-performance="<?php echo esc_attr($s['performance_mode'] ?? 'auto'); ?>"
        data-adaptive-blur="<?php echo esc_attr($s['adaptive_blur'] ?? 'yes'); ?>"
        data-reduced-motion="<?php echo esc_attr($s['reduced_motion'] ?? 'respect'); ?>">
        <?php if (!$is_inline): ?><div class="dsb-menu-toolbar"><span class="dsb-menu-toolbar-title">Menu</span><button type="button" class="dsb-menu-close" aria-label="Fermer le menu"><span aria-hidden="true">×</span></button></div><?php endif; ?>
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
                case 'footer': if ($s['footer_toggle'] === 'yes') echo '<div class="dsb-menu-footer-toggle"><span>'.delicat_builder_v9_menu_native_icon_svg('moon').' Mode sombre</span>'.do_shortcode('[delicat_theme_toggle label="no"]').'</div>'; break;
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
add_shortcode('delicat_menu_promo', function(){ $s = delicat_builder_v9_menu_get_menu_builder_settings(); delicat_builder_v9_menu_menu_builder_assets(); return '<div class="dsb-menu-promo"><div class="dsb-menu-gift">'.delicat_builder_v9_menu_native_icon_svg('gift').'</div><div><strong>'.esc_html($s['promo_title']).'</strong><p>'.esc_html($s['promo_text']).'</p>'.($s['promo_countdown']?'<small>'.esc_html($s['promo_countdown']).'</small>':'').'<a href="'.esc_url($s['promo_url']).'">'.esc_html($s['promo_button']).'</a></div></div>'; });
add_shortcode('delicat_profile_card', function(){ return delicat_builder_v9_menu_render_profile_card(); });
add_shortcode('delicat_wallet_card', function(){ return delicat_builder_v9_menu_render_wallet_card(); });
add_shortcode('delicat_quick_actions', function(){ return delicat_builder_v9_menu_render_quick_actions(); });
add_shortcode('delicat_bonus_menu', function(){ delicat_builder_v9_menu_menu_builder_assets(); return '<div class="dsb-menu-list dsb-menu-list-bonus">'.delicat_builder_v9_menu_render_menu_items('bonus').'</div>'; });
add_shortcode('delicat_social_row', function(){
    $s=delicat_builder_v9_menu_get_menu_builder_settings(); delicat_builder_v9_menu_menu_builder_assets();
    /* RC51.19: real brand marks instead of loose glyphs (clover/plane/circle).
       Inline SVG, currentColor, zero requests. Uploaded icons still win. */
    $svg_open = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false" style="width:var(--dsb-social-icon,24px);height:var(--dsb-social-icon,24px)">';
    $links=array(
        'whatsapp'=>$svg_open.'<path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm0 2a8 8 0 1 1-4.1 14.9l-.5-.3-2.9.8.8-2.8-.3-.5A8 8 0 0 1 12 4zm-3 3.6c-.2 0-.5.1-.7.3-.7.7-1 1.6-.8 2.6.3 1.3 1.1 2.6 2.3 3.8 1.2 1.2 2.5 2 3.8 2.3 1 .2 1.9-.1 2.6-.8.2-.2.3-.5.3-.8l-.1-.6c-.1-.2-.2-.3-.4-.4l-1.7-.8a.7.7 0 0 0-.8.1l-.6.6c-.1.1-.3.2-.5.1a6 6 0 0 1-2.6-2.6c-.1-.2 0-.4.1-.5l.6-.6c.2-.2.3-.5.1-.8l-.8-1.7c-.1-.2-.2-.3-.4-.4L9 7.6z"/></svg>',
        'telegram'=>$svg_open.'<path d="M21.6 4.3c.3-1.1-.8-2-1.8-1.6L2.9 9.2c-1.1.4-1.1 2 .1 2.3l4.3 1.2 1.6 5.2c.3 1 1.6 1.3 2.3.5l2.3-2.4 4.3 3.2c.9.6 2.1.2 2.3-.9l1.5-14zM8.5 12.5l9.5-5.9c.2-.1.4.1.2.3l-7.8 7.3-.3 3-1.6-4.7z"/></svg>',
        'instagram'=>$svg_open.'<path d="M12 2c2.7 0 3 0 4.1.1 1.1 0 1.8.2 2.4.5.7.2 1.2.6 1.7 1.1.5.5.9 1 1.1 1.7.3.6.4 1.3.5 2.4.1 1.1.1 1.4.1 4.2s0 3-.1 4.1c0 1.1-.2 1.8-.5 2.4a4.9 4.9 0 0 1-2.8 2.8c-.6.3-1.3.4-2.4.5-1.1.1-1.4.1-4.1.1s-3.1 0-4.2-.1c-1.1 0-1.8-.2-2.4-.5a4.9 4.9 0 0 1-2.8-2.8c-.3-.6-.4-1.3-.5-2.4C2 15 2 14.7 2 12s0-3.1.1-4.2c0-1.1.2-1.8.5-2.4.2-.7.6-1.2 1.1-1.7.5-.5 1-.9 1.7-1.1.6-.3 1.3-.4 2.4-.5C8.9 2 9.3 2 12 2zm0 2c-2.7 0-3 0-4.1.1-1 0-1.5.2-1.9.3-.4.2-.8.4-1.1.7-.3.3-.5.7-.7 1.1-.1.4-.3.9-.3 1.9C4 9 4 9.3 4 12s0 3 .1 4c0 1 .2 1.5.3 1.9.2.4.4.8.7 1.1.3.3.7.5 1.1.7.4.1.9.3 1.9.3 1 .1 1.4.1 4 .1s3 0 4-.1c1 0 1.5-.2 1.9-.3.4-.2.8-.4 1.1-.7.3-.3.5-.7.7-1.1.1-.4.3-.9.3-1.9.1-1 .1-1.4.1-4s0-3-.1-4c0-1-.2-1.5-.3-1.9a2.9 2.9 0 0 0-.7-1.1 2.9 2.9 0 0 0-1.1-.7c-.4-.1-.9-.3-1.9-.3-1.1-.1-1.4-.1-4.1-.1zm0 3.4a4.6 4.6 0 1 1 0 9.2 4.6 4.6 0 0 1 0-9.2zm0 2a2.6 2.6 0 1 0 0 5.2 2.6 2.6 0 0 0 0-5.2zm4.8-3.4a1.1 1.1 0 1 1 0 2.2 1.1 1.1 0 0 1 0-2.2z"/></svg>',
        'facebook'=>$svg_open.'<path d="M22 12a10 10 0 1 0-11.6 9.9v-7H7.9V12h2.5V9.8c0-2.5 1.5-3.9 3.8-3.9 1.1 0 2.2.2 2.2.2v2.5h-1.3c-1.2 0-1.6.8-1.6 1.6V12h2.8l-.4 2.9h-2.4v7A10 10 0 0 0 22 12z"/></svg>',
        'tiktok'=>$svg_open.'<path d="M16.6 3c.3 1.4 1.2 2.7 2.5 3.4.7.4 1.5.6 2.3.6v3.2c-1.7 0-3.4-.5-4.8-1.5v6.6a6.3 6.3 0 1 1-6.3-6.3l.9.1v3.3a3.1 3.1 0 1 0 2.2 3V2h3.2v1z"/></svg>',
        'youtube'=>$svg_open.'<path d="M21.6 7.2a2.5 2.5 0 0 0-1.8-1.8C18.2 5 12 5 12 5s-6.2 0-7.8.4A2.5 2.5 0 0 0 2.4 7.2 26 26 0 0 0 2 12c0 1.6.1 3.2.4 4.8.2.9.9 1.6 1.8 1.8C5.8 19 12 19 12 19s6.2 0 7.8-.4a2.5 2.5 0 0 0 1.8-1.8c.3-1.6.4-3.2.4-4.8s-.1-3.2-.4-4.8zM10 15.2V8.8L15.5 12 10 15.2z"/></svg>',
    );
    $style='--dsb-social-size:'.absint($s['social_button_size']).'px;--dsb-social-icon:'.absint($s['social_icon_size']).'px;--dsb-social-gap:'.absint($s['social_gap']).'px;--dsb-social-align:'.esc_attr($s['social_align']);
    $out='<div class="dsb-menu-social" style="'.$style.'">';
    foreach($links as $slug=>$fallback){
        if (($s[$slug.'_enabled'] ?? 'yes') !== 'yes') continue;
        $url=$s[$slug.'_url'] ?? '#'; if(empty($url)||$url==='#') continue;
        $icon_url=$s[$slug.'_icon'] ?? '';
        $label = $s[$slug.'_label'] ?? ucfirst($slug);
        $icon_html=$icon_url ? '<img src="'.esc_url($icon_url).'" alt="'.esc_attr($label).'" loading="lazy" decoding="async">' : $fallback;
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
    public static function admin_page(): void {}
    public static function settings(): array { return delicat_builder_v9_menu_get_menu_builder_settings(); }
    public static function items(): array { return delicat_builder_v9_menu_get_menu_builder_items(); }
    public static function render(string $mode = 'drawer'): string {
        if (function_exists('dsb_render_modern_menu')) return (string) dsb_render_modern_menu($mode);
        /* RC29: the rebuilt drawer owns the storefront menu. */
        if (class_exists('Delicat_Builder_V9_Drawer', false) && Delicat_Builder_V9_Drawer::owns()) return (string) Delicat_Builder_V9_Drawer::render();
        return (string) delicat_builder_v9_menu_render_modern_menu($mode);
    }
}

endif; /* RC18 single-declaration guard */
