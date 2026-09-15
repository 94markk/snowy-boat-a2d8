<?php
/**
 * Delicat Builder v8 Header Studio — fully customizable modern header.
 */
if (!defined('ABSPATH')) exit;

if (!class_exists('Delicat_Builder_V9_Header_Studio_8')) {
final class Delicat_Builder_V9_Header_Studio_8 {
    const VERSION = '8.1.0-v9-native';
    const OPTION  = 'dsb8_beta2_header';
    private static $instance;
    private static $embedded_menu_rendered = false;
    private static $header_rendered = false;
    private static $settings_cache = null;

    public static function instance() {
        if (!self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', array($this, 'shortcodes'), 99);
        add_action('wp_enqueue_scripts', array($this, 'register_assets'), 1);
        add_action('wp_enqueue_scripts', array($this, 'conditional_assets'), 8);
        add_action('wp_body_open', array($this, 'auto_render_header'), 1);
        add_action('wp_enqueue_scripts', array($this, 'dequeue_legacy_assets'), 99);
        add_filter('body_class', array($this, 'body_class'), 5);
        add_action('admin_menu', array($this, 'admin_page'), 96);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'admin_assets'));
                add_filter('woocommerce_add_to_cart_fragments', array($this, 'cart_fragments'), 99);
        /* 9.1 RC1: the Header Runtime owns this endpoint when present; the
         * legacy Studio-8 copy registers only as a fallback. */
        if ( ! class_exists( 'Delicat_Builder_V9_Header_Runtime', false ) ) {
            add_action('wp_ajax_delicat_builder_v9_header_remove_cart_item', array($this, 'ajax_remove_cart_item'));
            add_action('wp_ajax_nopriv_delicat_builder_v9_header_remove_cart_item', array($this, 'ajax_remove_cart_item'));
        }
    }

    public function register_component($components) {
        $components['header-beta2'] = array(
            'selector' => '.dsb8-header,.dsb8-icon-menu',
            'style' => 'delicat-builder-v9-header-studio-8',
            'script' => 'delicat-builder-v9-header-studio-8',
            'priority' => 3,
        );
        return $components;
    }

    public function defaults() {
        return array(
            'style' => 'clean', 'sticky' => 1, 'compact' => 1,
            'show_search' => 0, 'show_bell' => 1, 'show_theme' => 1, 'show_cart' => 1, 'show_account' => 1,
            'dynamic_avatar' => 1, 'show_avatar_ring' => 1,
            'logo_width' => 154, 'logo_offset' => -10, 'mobile_logo_offset' => -8, 'header_height' => 76, 'mobile_height' => 72,
            'content_width' => 1440, 'side_padding' => 24, 'mobile_padding' => 12,
            'item_gap' => 12, 'mobile_gap' => 6, 'action_size' => 44, 'icon_size' => 27,
            'avatar_size' => 38, 'border_radius' => 0, 'floating_margin' => 12,
            'accent' => '#c72e69', 'background' => '#ffffff', 'text_color' => '#111827',
            'shadow_strength' => 9, 'border_color' => '#e8ebf0', 'dark_logo_id' => 0,
            'search_placeholder' => 'Rechercher un produit, un jeu…',
            'item_order' => array('menu','logo','spacer','bell','theme','account','cart'),
            'item_spacing' => array('menu'=>0,'logo'=>0,'spacer'=>0,'bell'=>0,'theme'=>0,'account'=>0,'cart'=>0),
            'menu_items' => array(
                array('label'=>'Jeux','icon'=>'game','url'=>'/product-category/jeux/'),
                array('label'=>'Gift Cards','icon'=>'gift','url'=>'/product-category/gift-card/'),
                array('label'=>'Abonnements','icon'=>'screen','url'=>'/product-category/abonnement/'),
                array('label'=>'Finance','icon'=>'card','url'=>'/product-category/exchange/'),
                array('label'=>'Shop','icon'=>'shop','url'=>'/shop/'),
                array('label'=>'Support','icon'=>'chat','url'=>'/support/'),
            ),
        );
    }

    public function settings() {
        if (is_array(self::$settings_cache)) return self::$settings_cache;
        $defaults = $this->defaults();
        $saved = get_option(self::OPTION, array());
        $settings = wp_parse_args(is_array($saved) ? $saved : array(), $defaults);
        $settings['item_spacing'] = wp_parse_args(isset($settings['item_spacing']) && is_array($settings['item_spacing']) ? $settings['item_spacing'] : array(), $defaults['item_spacing']);
        // RC51.9 upgrade path: existing installs saved before the theme action
        // existed should receive it automatically without resetting Header V8.
        $order = is_array($settings['item_order'] ?? null) ? $settings['item_order'] : $defaults['item_order'];
        $settings['item_order'] = $order;
        if (!empty($settings['show_theme']) && !in_array('theme', $settings['item_order'], true)) {
            $bell = array_search('bell', $settings['item_order'], true);
            $at = ($bell === false) ? count($settings['item_order']) : $bell + 1;
            array_splice($settings['item_order'], $at, 0, array('theme'));
        }
        self::$settings_cache = $settings;
        return self::$settings_cache;
    }

    public function register_settings() {
        register_setting('dsb8_beta2', self::OPTION, array(
            'type' => 'array', 'sanitize_callback' => array($this, 'sanitize_settings'), 'default' => $this->defaults(),
        ));
    }

    private function clamp($value, $min, $max, $fallback) {
        $value = is_numeric($value) ? (int)$value : (int)$fallback;
        return min($max, max($min, $value));
    }

    public function sanitize_settings($raw) {
        $raw = is_array($raw) ? $raw : array();
        $d = $this->defaults(); $out = $d;
        $out['style'] = in_array(($raw['style'] ?? ''), array('clean','glass','floating','dark'), true) ? $raw['style'] : 'clean';
        foreach (array('sticky','compact','show_search','show_bell','show_theme','show_cart','show_account','dynamic_avatar','show_avatar_ring') as $key) $out[$key] = empty($raw[$key]) ? 0 : 1;
        $ranges = array(
            'logo_width'=>array(60,320),'logo_offset'=>array(-60,120),'mobile_logo_offset'=>array(-40,80),'header_height'=>array(54,140),'mobile_height'=>array(52,110),
            'content_width'=>array(640,1920),'side_padding'=>array(0,80),'mobile_padding'=>array(0,40),
            'item_gap'=>array(0,60),'mobile_gap'=>array(0,36),'action_size'=>array(32,76),
            'icon_size'=>array(18,46),'avatar_size'=>array(26,64),'border_radius'=>array(0,40),
            'floating_margin'=>array(0,40),'shadow_strength'=>array(0,30),
        );
        foreach ($ranges as $key=>$range) $out[$key] = $this->clamp($raw[$key] ?? $d[$key], $range[0], $range[1], $d[$key]);
        foreach (array('accent','background','text_color','border_color') as $key) {
            $color = sanitize_hex_color($raw[$key] ?? ''); $out[$key] = $color ?: $d[$key];
        }
        $out['search_placeholder'] = sanitize_text_field($raw['search_placeholder'] ?? $d['search_placeholder']);
        /* RC55: dark-mode logo — an image attachment id or 0. */
        $out['dark_logo_id'] = absint($raw['dark_logo_id'] ?? 0);
        if ($out['dark_logo_id'] > 0 && !wp_attachment_is_image($out['dark_logo_id'])) $out['dark_logo_id'] = 0;
        $valid = array('menu','logo','spacer','bell','theme','account','cart');
        $order = isset($raw['item_order']) ? explode(',', sanitize_text_field($raw['item_order'])) : $d['item_order'];
        $order = array_values(array_unique(array_intersect(array_map('sanitize_key', $order), $valid)));
        foreach ($valid as $item) if (!in_array($item, $order, true)) $order[] = $item;
        $out['item_order'] = $order;
        $out['item_spacing'] = array();
        foreach ($valid as $item) $out['item_spacing'][$item] = $this->clamp($raw['item_spacing'][$item] ?? 0, -100, 100, 0);
        $items = array();
        for ($i=0; $i<8; $i++) {
            $item = isset($raw['menu_items'][$i]) && is_array($raw['menu_items'][$i]) ? $raw['menu_items'][$i] : array();
            $label = sanitize_text_field($item['label'] ?? ''); $url = esc_url_raw($item['url'] ?? ''); $icon = sanitize_key($item['icon'] ?? 'grid');
            $path = wp_parse_url($url, PHP_URL_PATH);
            if (in_array($path, array('/exchange','/exchange/'), true)) $url = '/product-category/exchange/';
            if (in_array($path, array('/product-category/streaming','/product-category/streaming/'), true)) $url = '/product-category/abonnement/';
            if ($label && $url) $items[] = array('label'=>$label,'url'=>$url,'icon'=>$icon ?: 'grid');
        }
        $out['menu_items'] = $items ?: $d['menu_items'];
        return $out;
    }

    public function register_assets() {
        if (is_admin()) return;
        $base = plugin_dir_url(dirname(__FILE__));
        wp_register_style('delicat-builder-v9-header-studio-8', $base . 'assets/dsb8-beta2-header.css', array(), DELICAT_BUILDER_V9_VERSION);
        wp_register_script('delicat-builder-v9-header-studio-8', $base . 'assets/dsb8-beta2-header.js', array(), DELICAT_BUILDER_V9_VERSION, true);
    }

    private function page_has_component() {
        if (is_admin()) return false; global $post;
        if (!$post || !is_string($post->post_content)) return false;
        return function_exists('dsb8_page_has_shortcode') ? dsb8_page_has_shortcode(array('delicat_v8_header','delicat_v8_icon_menu')) : (has_shortcode($post->post_content, 'delicat_v8_header') || has_shortcode($post->post_content, 'delicat_v8_icon_menu'));
    }
    public function conditional_assets() { if (!is_admin() && (self::should_auto_render() || $this->page_has_component())) $this->enqueue_assets(); }
    public function dequeue_legacy_assets() {
        if (class_exists('DSB8_Beta2_Header', false)) {
            wp_dequeue_style('dsb8-beta2-header');
            wp_dequeue_script('dsb8-beta2-header');
        }
    }
    private function enqueue_assets() { wp_enqueue_style('delicat-builder-v9-header-studio-8'); wp_enqueue_script('delicat-builder-v9-header-studio-8'); if (function_exists('wp_script_add_data')) wp_script_add_data('delicat-builder-v9-header-studio-8', 'strategy', 'defer'); }
    public function shortcodes() { add_shortcode('delicat_v8_header', array($this, 'render_header')); add_shortcode('delicat_v8_icon_menu', array($this, 'render_icon_menu')); }

    public static function should_auto_render() {
        if (is_admin() || wp_doing_ajax() || is_feed() || is_embed()) return false;
        if (defined('REST_REQUEST') && REST_REQUEST) return false;
        if (function_exists('is_customize_preview') && is_customize_preview()) return false;
        if (class_exists('Delicat_Builder_V9_Core', false) && is_callable(array('Delicat_Builder_V9_Core', 'is_enabled')) && !Delicat_Builder_V9_Core::is_enabled()) return false;
        return (bool)apply_filters('delicat_builder_v9_native_header_should_render', true);
    }

    public static function has_rendered() { return (bool)self::$header_rendered; }

    public function auto_render_header() {
        if (!self::should_auto_render() || self::$header_rendered) return;
        echo $this->render_header(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    public function body_class($classes) {
        if (self::should_auto_render()) $classes[] = 'delicat-native-header-active';
        return array_values(array_unique((array)$classes));
    }

    private function icon($name) {
        $icons = array(
            'menu'=>'<path d="M4 7h16M4 12h16M4 17h16"/>','search'=>'<circle cx="11" cy="11" r="6"/><path d="m16 16 4 4"/>',
            'cart'=>'<path d="M3 4h2l2.1 10.2a2 2 0 0 0 2 1.6h7.8a2 2 0 0 0 2-1.6L20 8H7"/><circle cx="10" cy="20" r="1"/><circle cx="17" cy="20" r="1"/>',
            'user'=>'<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'theme'=>'<path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5a8.5 8.5 0 1 0 10.7 10.7Z"/>',
            'game'=>'<path d="M8 8h8a5 5 0 0 1 4.6 7l-1 2.4a2 2 0 0 1-3.2.7L14 16h-4l-2.4 2.1a2 2 0 0 1-3.2-.7L3.4 15A5 5 0 0 1 8 8Z"/><path d="M7 12h4M9 10v4M16 12h.01M18 14h.01"/>',
            'gift'=>'<path d="M4 10h16v10H4zM3 6h18v4H3zM12 6v14"/><path d="M12 6H8.5A2.5 2.5 0 1 1 12 2.5V6Zm0 0h3.5A2.5 2.5 0 1 0 12 2.5V6Z"/>',
            'screen'=>'<rect x="3" y="4" width="18" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>','card'=>'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3 10h18M7 15h3"/>',
            'shop'=>'<path d="M4 10h16l-1 11H5L4 10Zm2 0 1-5h10l1 5M9 14v2M15 14v2"/>','chat'=>'<path d="M21 12a8 8 0 0 1-8 8H5l-3 2 1-4a8 8 0 1 1 18-6Z"/>',
            'grid'=>'<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>'
        );
        return '<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'.($icons[$name] ?? $icons['grid']).'</svg>';
    }

    private function cart_count() { return function_exists('WC') && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0; }
    private function cart_fragment_markup() {
        $count = $this->cart_count();
        return '<span class="dsb8-woo-cart-count-fragment" hidden data-count="'.esc_attr($count).'">'.esc_html($count).'</span>';
    }
    private function cart_panel_markup() {
        $count = $this->cart_count();
        $ajax = admin_url('admin-ajax.php');
        $nonce = wp_create_nonce('delicat_builder_v9_header_cart_remove');
        ob_start(); ?>
        <div class="dsb8-cart-fragment" data-dsb8-cart-root data-ajax="<?php echo esc_url($ajax); ?>" data-nonce="<?php echo esc_attr($nonce); ?>">
          <button class="dsb8-header__action dsb8-header__cart" type="button" aria-label="Ouvrir le panier" aria-expanded="false" data-dsb8-cart-trigger>
            <?php echo $this->icon('cart'); ?><span class="dsb8-header__badge" data-dsb8-cart-count<?php echo $count?'':' hidden'; ?>><?php echo (int)$count; ?></span>
          </button>
          <div class="dsb8-cart-panel" role="dialog" aria-label="Panier">
            <div class="dsb8-cart-panel__head"><strong>Votre panier</strong><button type="button" class="dsb8-cart-panel__close" aria-label="Fermer">×</button></div>
            <div class="dsb8-cart-list">
            <?php if(function_exists('WC') && WC()->cart && !WC()->cart->is_empty()): foreach(WC()->cart->get_cart() as $key=>$line):
              $product = isset($line['data']) ? $line['data'] : null; if(!$product || !$product->exists()) continue;
              $name = $product->get_name(); $qty = isset($line['quantity']) ? (int)$line['quantity'] : 1;
              $image = $product->get_image('woocommerce_thumbnail', array('loading'=>'lazy','decoding'=>'async'));
              $price = WC()->cart->get_product_subtotal($product,$qty);
            ?>
              <div class="dsb8-cart-swipe" data-cart-key="<?php echo esc_attr($key); ?>">
                <span class="dsb8-cart-swipe__bg" aria-hidden="true">Supprimer</span>
                <div class="dsb8-cart-item">
                  <a class="dsb8-cart-item__image" href="<?php echo esc_url($product->is_visible()?$product->get_permalink($line):'#'); ?>"><?php echo $image; ?></a>
                  <div class="dsb8-cart-item__copy"><a href="<?php echo esc_url($product->is_visible()?$product->get_permalink($line):'#'); ?>"><?php echo esc_html($name); ?></a><small>Qté : <?php echo $qty; ?></small><b><?php echo wp_kses_post($price); ?></b></div>
                  <button type="button" class="dsb8-cart-item__remove" aria-label="Supprimer <?php echo esc_attr($name); ?>">×</button>
                </div>
              </div>
            <?php endforeach; else: ?>
              <div class="dsb8-cart-empty">Votre panier est vide.</div>
            <?php endif; ?>
            </div>
            <?php if($count): ?><div class="dsb8-cart-panel__foot"><div><span>Sous-total</span><strong><?php echo wp_kses_post(WC()->cart->get_cart_subtotal()); ?></strong></div><a href="<?php echo esc_url(wc_get_cart_url()); ?>">Voir le panier</a><a class="is-checkout" href="<?php echo esc_url(wc_get_checkout_url()); ?>">Commander</a></div><?php endif; ?>
          </div>
        </div>
        <?php return ob_get_clean();
    }
    public function cart_fragments($fragments) {
        if (!is_array($fragments)) $fragments = array();
        $fragments['.dsb8-woo-cart-count-fragment'] = $this->cart_fragment_markup();
        $fragments['.dsb8-cart-fragment'] = $this->cart_panel_markup();
        return $fragments;
    }
    public function ajax_remove_cart_item() {
        if ( 'POST' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) ) {
            wp_send_json_error( array( 'code' => 'invalid_method' ), 405 );
        }
        // WooCommerce expects a string cart key, never an array/object.
        if ( ! isset( $_POST['nonce'], $_POST['cart_item_key'] ) || ! is_string( $_POST['nonce'] ) || ! is_string( $_POST['cart_item_key'] ) ) {
            wp_send_json_error( array( 'code' => 'invalid_input' ), 400 );
        }
        /*
         * v8.7.1 — le nonce est verifie mais l'echec n'est plus fatal en silence :
         * sur une page servie par le cache il est perime et la suppression
         * echouait sans rien dire. On renvoie un code que le JS sait traiter
         * (rafraichir le panier puis reessayer une seule fois).
         */
        $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'delicat_builder_v9_header_cart_remove')) {
            wp_send_json_error(array('code'=>'stale_nonce','message'=>'Session expirée.'), 403);
        }

		if (
			! class_exists( 'Delicat_Builder_V9_Security', false )
			|| ! Delicat_Builder_V9_Security::rate_limit_allowed( 'header_cart_remove', 30, 60 )
		) {
			wp_send_json_error( array( 'code' => 'rate_limited', 'message' => 'Trop de requêtes. Réessayez dans un instant.' ), 429 );
		}

        $raw_key = isset($_POST['cart_item_key']) ? wp_unslash($_POST['cart_item_key']) : '';
        $key = function_exists('wc_clean') ? wc_clean($raw_key) : sanitize_text_field($raw_key);
        if (!$key || !function_exists('WC') || !WC()->cart) {
            wp_send_json_error(array('code'=>'no_cart','message'=>'Panier indisponible.'), 400);
        }
        if (!WC()->cart->remove_cart_item($key)) {
            wp_send_json_error(array('code'=>'not_found','message'=>'Impossible de supprimer cet article.'), 400);
        }

        WC()->cart->calculate_totals();
        $count = $this->cart_count();

        /*
         * On renvoie de quoi mettre a jour l'interface sur place. Avant, le JS
         * declenchait un rechargement complet des fragments Woo : le panneau
         * etait remplace, il se fermait, et l'etat "occupe" restait colle.
         */
        wp_send_json_success(array(
            'count'    => $count,
            'empty'    => 0 === $count,
            'subtotal' => $count ? wp_kses_post(WC()->cart->get_cart_subtotal()) : '',
            'nonce'    => wp_create_nonce('delicat_builder_v9_header_cart_remove'),
        ));
    }
    private function account_url() { return function_exists('wc_get_page_permalink') ? wc_get_page_permalink('myaccount') : wp_login_url(); }
    private function account_content($s) {
        if (!empty($s['dynamic_avatar']) && is_user_logged_in()) {
            $user = wp_get_current_user();
            $url = get_avatar_url($user->ID, array('size'=>128,'default'=>'mystery'));
            if ($url) return '<img class="dsb8-header__avatar" src="'.esc_url($url).'" alt="'.esc_attr($user->display_name).'" loading="eager" decoding="async">';
        }
        return $this->icon('user');
    }

    /** RC55: the dark-mode logo chosen in Header Studio v8 (attachment id), or '' when none is set. */
    public function dark_logo_html() {
        $s = $this->settings();
        $id = absint($s['dark_logo_id'] ?? 0);
        if ($id <= 0) return '';
        $img = wp_get_attachment_image($id, 'medium_large', false, array('class'=>'custom-logo dsb8-header__logo-dark-img','loading'=>'eager','decoding'=>'async','alt'=>get_bloginfo('name')));
        if (!is_string($img) || '' === $img) return '';
        return '<a class="custom-logo-link dsb8-header__logo-dark" href="'.esc_url(home_url('/')).'" rel="home" aria-hidden="true" tabindex="-1">'.$img.'</a>';
    }
    private function render_item($item, $s, $logo) {
        $space = isset($s['item_spacing'][$item]) ? (int)$s['item_spacing'][$item] : 0;
        $style = '--dsb8-item-space:'.$space.'px';
        if ($item === 'menu') {
            $menu_enabled = true;
            if (class_exists('Delicat_Builder_V9_Menu_Builder', false)) {
                $menu_settings = Delicat_Builder_V9_Menu_Builder::settings();
                $menu_enabled = (($menu_settings['enabled'] ?? 'yes') === 'yes');
            } elseif (function_exists('dsb_get_menu_builder_settings')) {
                $menu_settings = dsb_get_menu_builder_settings();
                $menu_enabled = (($menu_settings['enabled'] ?? 'yes') === 'yes');
            }
            if (!$menu_enabled) return '';
            return '<button class="dsb8-header__action dsb8-header__menu dsb-menu-toggle" style="'.$style.'" type="button" aria-label="Ouvrir le menu" data-dsb8-menu-trigger>'.$this->icon('menu').'</button>';
        }
        if ($item === 'logo') return '<div class="dsb8-header__logo'.(false!==strpos($logo,'dsb8-header__logo-dark')?' has-dark-logo':'').'" style="'.$style.'">'.$logo.'</div>';
        if ($item === 'spacer') return '<div class="dsb8-header__spacer" style="'.$style.'" aria-hidden="true"></div>';
        if ($item === 'bell' && $s['show_bell']) return '<div class="dsb8-header__slot dsb8-header__slot--bell" style="'.$style.'">'.do_shortcode('[delicat_header_notifications]').'</div>';
        if ($item === 'theme' && !empty($s['show_theme'])) return '<button class="dsb8-header__action dsb8-header__theme" style="'.$style.'" type="button" data-delicat-theme-toggle aria-label="Activer le mode sombre" aria-pressed="false">'.$this->icon('theme').'</button>';
        if ($item === 'account' && $s['show_account']) return '<a class="dsb8-header__action dsb8-header__account'.(!empty($s['show_avatar_ring'])?' has-ring':'').'" style="'.$style.'" href="'.esc_url($this->account_url()).'" aria-label="Mon compte">'.$this->account_content($s).'</a>';
        if ($item === 'cart' && $s['show_cart']) return '<div class="dsb8-header__slot dsb8-header__slot--cart" style="'.$style.'">'.$this->cart_panel_markup().'</div>';
        return '';
    }

    public function render_header($atts=array()) {
        if (self::$header_rendered) return '';
        self::$header_rendered = true;
        $this->enqueue_assets(); $s=$this->settings();
        $atts=shortcode_atts(array('class'=>'','search'=>null),$atts,'delicat_v8_header');
        if (null!==$atts['search']) $s['show_search']=('yes'===$atts['search']||'1'===$atts['search']);
        $classes=array('dsb8-header','dsb8-header--'.$s['style']); if($s['sticky'])$classes[]='is-sticky'; if($s['compact'])$classes[]='is-compact'; if($atts['class'])$classes[]=sanitize_html_class($atts['class']);
        $logo=get_custom_logo(); if(!$logo)$logo='<a class="dsb8-header__brand" href="'.esc_url(home_url('/')).'">Delicat Top Up</a>';
        /* RC55: optional dark-mode logo. Both images are in the HTML (identical for every
         * visitor, so the cached document is unchanged) and the <head> theme boot script
         * has already set html.dlc-theme-dark / data-delicat-theme before first paint, so
         * CSS picks the right one with no flash. The dark <a> is aria-hidden: one logo
         * link is announced, never two. */
        $dark_logo=$this->dark_logo_html();
        if($dark_logo!=='')$logo='<span class="dsb8-header__logo-light">'.$logo.'</span>'.$dark_logo;
        $vars='--dsb8-header-accent:'.$s['accent'].';--dsb8-logo-width:'.$s['logo_width'].'px;--dsb8-logo-offset:'.$s['logo_offset'].'px;--dsb8-mobile-logo-offset:'.$s['mobile_logo_offset'].'px;--dsb8-header-height:'.$s['header_height'].'px;--dsb8-mobile-height:'.$s['mobile_height'].'px;--dsb8-content-width:'.$s['content_width'].'px;--dsb8-side-padding:'.$s['side_padding'].'px;--dsb8-mobile-padding:'.$s['mobile_padding'].'px;--dsb8-item-gap:'.$s['item_gap'].'px;--dsb8-mobile-gap:'.$s['mobile_gap'].'px;--dsb8-action-size:'.$s['action_size'].'px;--dsb8-icon-size:'.$s['icon_size'].'px;--dsb8-avatar-size:'.$s['avatar_size'].'px;--dsb8-header-radius:'.$s['border_radius'].'px;--dsb8-floating-margin:'.$s['floating_margin'].'px;--dsb8-header-bg:'.$s['background'].';--dsb8-header-text:'.$s['text_color'].';--dsb8-border-color:'.$s['border_color'].';--dsb8-shadow-alpha:'.($s['shadow_strength']/100).';';
        ob_start(); ?>
        <header class="<?php echo esc_attr(implode(' ',$classes)); ?>" data-dsb8-header style="<?php echo esc_attr($vars); ?>">
          <div class="dsb8-header__row">
            <?php foreach($s['item_order'] as $item) echo $this->render_item($item,$s,$logo); ?>
          </div>
          <?php if($s['show_search']): ?><form class="dsb8-header__search" role="search" method="get" action="<?php echo esc_url(home_url('/')); ?>"><label class="screen-reader-text" for="dsb8-header-search">Rechercher</label><?php echo $this->icon('search'); ?><input id="dsb8-header-search" type="search" name="s" placeholder="<?php echo esc_attr($s['search_placeholder']); ?>"><input type="hidden" name="post_type" value="product"></form><?php endif; ?>
        </header>
        <?php echo $this->cart_fragment_markup(); ?>
        <?php
        /* Menu Engine 3.0 prints the menu itself, at the end of the document.
         * The header used to embed it here, which put the whole panel between
         * the header and the page's first content — markup the shopper waits
         * on for a menu most visits never open. All the header ships now is
         * the three-bar button, which the engine binds by attribute.
         *
         * A legacy standalone Builder still on the site keeps its old
         * behaviour: it has no footer renderer of its own. */
        if (!self::$embedded_menu_rendered && function_exists('dsb_render_modern_menu')) {
            self::$embedded_menu_rendered = true;
            echo dsb_render_modern_menu('drawer');
        }
return ob_get_clean();
    }

    public function render_icon_menu($atts=array()) {
        $this->enqueue_assets(); $s=$this->settings(); $atts=shortcode_atts(array('limit'=>'6','class'=>''),$atts,'delicat_v8_icon_menu'); $items=array_slice($s['menu_items'],0,min(8,max(1,absint($atts['limit']))));
        ob_start(); ?><nav class="dsb8-icon-menu <?php echo esc_attr(sanitize_html_class($atts['class'])); ?>" aria-label="Catégories rapides" data-dsb8-icon-menu style="--dsb8-header-accent:<?php echo esc_attr($s['accent']); ?>"><?php foreach($items as $item): ?><a class="dsb8-icon-menu__item" href="<?php echo esc_url($item['url']); ?>"><span class="dsb8-icon-menu__icon"><?php echo $this->icon($item['icon']); ?></span><span class="dsb8-icon-menu__label"><?php echo esc_html($item['label']); ?></span></a><?php endforeach; ?></nav><?php return ob_get_clean();
    }

    public function admin_page() { add_submenu_page('delicat-builder-v9','Header Studio v8','Header Studio v8','manage_options','delicat-builder-v9-header-studio-8',array($this,'render_admin')); }
    public function admin_assets($hook) {
        if (strpos((string)$hook,'delicat-builder-v9-header-studio-8')===false) return;
        wp_enqueue_script('jquery-ui-sortable');
        wp_enqueue_media(); /* RC55: dark-mode logo picker */
        wp_add_inline_style('wp-admin', $this->admin_css());
        wp_add_inline_script('jquery-ui-sortable', $this->admin_js(), 'after');
    }
    private function admin_js() { return <<<'JS'
(function($){
  'use strict';
  function initHeaderStudio(){
    var $preview=$('#dsb8hs-preview'), $sort=$('#dsb8hs-sort');
    if(!$preview.length || !$sort.length) return;
    var labels={menu:'☰',logo:'DELICAT TOP UP',spacer:'',bell:'🔔',theme:'◐',account:'●',cart:'🛒'};
    function numberValue(key,fallback){var n=parseInt($('[data-preview="'+key+'"]').val(),10);return isNaN(n)?fallback:n;}
    function draw(){
      var order=[]; $preview.empty();
      $sort.children('li').each(function(){
        var $row=$(this), k=String($row.data('item')||'');
        if(!k)return; order.push(k);
        var $el=$('<div/>',{'data-k':k});
        if(k==='spacer') $el.addClass('dsb8hs-pspacer');
        else if(k==='logo') $el.addClass('dsb8hs-plogo').text(labels[k]);
        else if(k==='menu') $el.addClass('dsb8hs-pmenu').text(labels[k]);
        else $el.addClass('dsb8hs-picon'+(k==='account'?' dsb8hs-pavatar':'')).text(labels[k]);
        $el.css('margin-left',(parseInt($row.find('input[type=number]').first().val(),10)||0)+'px');
        $preview.append($el);
      });
      $('#dsb8hs-order').val(order.join(','));
      var accent=$('[data-preview="accent"]').val()||'#c72e69';
      var bg=$('[data-preview="background"]').val()||'#fff';
      var text=$('[data-preview="text_color"]').val()||'#111827';
      var border=$('[data-preview="border_color"]').val()||'#e8ebf0';
      $preview.css({background:bg,color:text,border:'1px solid '+border,gap:numberValue('item_gap',12)+'px',height:numberValue('header_height',76)+'px',borderRadius:numberValue('border_radius',0)+'px'});
      $preview.find('.dsb8hs-pmenu').css('color',accent);
      $preview.find('.dsb8hs-picon').css({width:numberValue('action_size',44)+'px',height:numberValue('action_size',44)+'px'});
      $preview.find('.dsb8hs-plogo').css('width',Math.min(170,numberValue('logo_width',110))+'px');
    }
    if($.fn.sortable){$sort.sortable({axis:'y',handle:'.dashicons-move',update:draw});}
    $('#dsb8hs-form').on('input change','input,select',draw);
    draw();
  }
  /* RC55: dark-mode logo picker (WordPress media library). */
  function initDarkLogo(){
    var $id=$('#dsb8hs-dark-logo-id'), $img=$('#dsb8hs-dark-logo-preview'), $empty=$('#dsb8hs-dark-logo-empty'), $clear=$('#dsb8hs-dark-logo-clear');
    if(!$id.length) return;
    function paint(url){ if(url){$img.attr('src',url).show();$empty.hide();$clear.show();} else {$img.removeAttr('src').hide();$empty.show();$clear.hide();} }
    $('#dsb8hs-dark-logo-choose').on('click',function(e){
      e.preventDefault();
      if(!window.wp||!wp.media){ return; }
      var frame=wp.media({title:'Logo mode sombre',library:{type:'image'},multiple:false,button:{text:'Utiliser ce logo'}});
      frame.on('select',function(){
        var a=frame.state().get('selection').first(); if(!a) return; a=a.toJSON();
        var url=(a.sizes&&a.sizes.medium&&a.sizes.medium.url)||a.url||'';
        $id.val(a.id||0); paint(url);
      });
      frame.open();
    });
    $clear.on('click',function(e){ e.preventDefault(); $id.val(0); paint(''); });
  }
  $(function(){ initHeaderStudio(); initDarkLogo(); });
})(jQuery);
JS;
    }

    private function admin_css() { return '.dsb8hs{max-width:1320px;margin:22px 20px 40px 0;color:#172033}.dsb8hs *{box-sizing:border-box}.dsb8hs-hero{display:flex;justify-content:space-between;gap:20px;align-items:center;padding:28px;border-radius:24px;background:linear-gradient(135deg,#172033,#273b67);color:#fff;box-shadow:0 18px 45px rgba(15,23,42,.2)}.dsb8hs-hero h1{color:#fff;margin:0 0 7px;font-size:30px}.dsb8hs-hero p{margin:0;color:#dbe5ff}.dsb8hs-badge{padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.13);font-weight:700}.dsb8hs-grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(330px,.85fr);gap:20px;margin-top:20px}.dsb8hs-card{background:#fff;border:1px solid #e7ebf2;border-radius:22px;padding:22px;box-shadow:0 10px 30px rgba(15,23,42,.06);margin-bottom:20px}.dsb8hs-card h2{margin:0 0 16px;font-size:18px}.dsb8hs-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.dsb8hs-field{display:flex;flex-direction:column;gap:7px}.dsb8hs-field label{font-weight:650}.dsb8hs-field input[type=number],.dsb8hs-field input[type=text],.dsb8hs-field input[type=url],.dsb8hs-field select{width:100%;min-height:42px;border-radius:11px;border-color:#d8dee9}.dsb8hs-switches{display:flex;flex-wrap:wrap;gap:10px}.dsb8hs-switch{display:flex;align-items:center;gap:8px;padding:10px 13px;border:1px solid #e0e5ee;border-radius:12px;background:#f8fafc}.dsb8hs-sort{display:flex;flex-direction:column;gap:9px;margin:0}.dsb8hs-sort li{display:grid;grid-template-columns:28px 1fr 110px;align-items:center;gap:10px;padding:12px;border:1px solid #e1e6ef;border-radius:13px;background:#f8fafc;cursor:grab}.dsb8hs-sort .dashicons{color:#7c8aa5}.dsb8hs-preview{position:sticky;top:48px}.dsb8hs-preview-shell{overflow:hidden;border:1px solid #e2e7ef;border-radius:18px;background:#eef2f7;padding:14px}.dsb8hs-preview-bar{height:86px;display:flex;align-items:center;gap:12px;padding:12px 18px;background:#fff;border-radius:12px;box-shadow:0 8px 25px rgba(15,23,42,.1)}.dsb8hs-pmenu{font-size:28px;color:#c72e69}.dsb8hs-plogo{width:110px;height:52px;background:linear-gradient(135deg,#111827,#64748b);border-radius:8px;color:#fff;display:grid;place-items:center;font-weight:800;font-size:12px;text-align:center}.dsb8hs-pspacer{flex:1}.dsb8hs-picon{width:42px;height:42px;border-radius:13px;display:grid;place-items:center;font-size:22px}.dsb8hs-pavatar{border-radius:50%;background:linear-gradient(135deg,#f8c6d9,#c72e69);color:#fff}.dsb8hs-menu-table{width:100%;border-collapse:separate;border-spacing:0 8px}.dsb8hs-menu-table td{padding:5px}.dsb8hs-menu-table input,.dsb8hs-menu-table select{width:100%}.dsb8hs-actions{position:sticky;bottom:0;padding:14px 0;background:linear-gradient(transparent,#f0f0f1 30%)}.dsb8hs-actions .button-primary{min-height:46px;padding:0 25px;border-radius:12px;font-weight:700}@media(max-width:1050px){.dsb8hs-grid{grid-template-columns:1fr}.dsb8hs-preview{position:static}}@media(max-width:700px){.dsb8hs-fields{grid-template-columns:1fr}.dsb8hs-hero{align-items:flex-start;flex-direction:column}.dsb8hs-sort li{grid-template-columns:28px 1fr 80px}}'; }

    public function render_admin() {
        if(!current_user_can('manage_options'))return; $s=$this->settings(); $opt=esc_attr(self::OPTION); $labels=array('menu'=>'Menu','logo'=>'Logo','spacer'=>'Flexible space','bell'=>'Notifications','theme'=>'Light / Dark','account'=>'Account / Avatar','cart'=>'Cart'); $icons=array('game'=>'Game','gift'=>'Gift','screen'=>'Screen','card'=>'Card','shop'=>'Shop','chat'=>'Chat','grid'=>'Grid'); ?>
        <div class="dsb8hs"><div class="dsb8hs-hero"><div><h1>Header Studio v8</h1><p>Native V9 header runtime. Customize, rearrange and preview every header element without changing WooCommerce or WordPress authority.</p></div><span class="dsb8hs-badge">V9 Native • Header Studio v8</span></div>
        <form method="post" action="options.php" id="dsb8hs-form"><?php settings_fields('dsb8_beta2'); ?><input type="hidden" id="dsb8hs-order" name="<?php echo $opt; ?>[item_order]" value="<?php echo esc_attr(implode(',',$s['item_order'])); ?>">
        <div class="dsb8hs-grid"><main>
          <section class="dsb8hs-card"><h2>Style & behavior</h2><div class="dsb8hs-fields"><div class="dsb8hs-field"><label>Header style</label><select name="<?php echo $opt; ?>[style]" data-preview="style"><?php foreach(array('clean'=>'Clean','glass'=>'Glass','floating'=>'Floating','dark'=>'Dark') as $k=>$v): ?><option value="<?php echo esc_attr($k); ?>" <?php selected($s['style'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach; ?></select></div><div class="dsb8hs-field"><label>Search placeholder</label><input type="text" name="<?php echo $opt; ?>[search_placeholder]" value="<?php echo esc_attr($s['search_placeholder']); ?>"></div></div><div class="dsb8hs-switches" style="margin-top:15px"><?php foreach(array('sticky'=>'Sticky','compact'=>'Compact','show_search'=>'Search','show_bell'=>'Bell','show_theme'=>'Light / Dark','show_cart'=>'Cart','show_account'=>'Account','dynamic_avatar'=>'Dynamic client photo','show_avatar_ring'=>'Avatar ring') as $k=>$v): ?><label class="dsb8hs-switch"><input type="checkbox" name="<?php echo $opt; ?>[<?php echo esc_attr($k); ?>]" value="1" <?php checked($s[$k]); ?>><?php echo esc_html($v); ?></label><?php endforeach; ?></div></section>
          <section class="dsb8hs-card"><h2>Drag to rearrange header elements</h2><ul class="dsb8hs-sort" id="dsb8hs-sort"><?php foreach($s['item_order'] as $item): ?><li data-item="<?php echo esc_attr($item); ?>"><span class="dashicons dashicons-move"></span><strong><?php echo esc_html($labels[$item]); ?></strong><label>Extra gap <input type="number" min="-100" max="100" step="1" title="Use a negative value to move this element left" name="<?php echo $opt; ?>[item_spacing][<?php echo esc_attr($item); ?>]" value="<?php echo (int)$s['item_spacing'][$item]; ?>" style="width:64px"></label></li><?php endforeach; ?></ul></section>
          <section class="dsb8hs-card"><h2>Logo</h2><p style="margin:0 0 12px;color:#667085">Le logo clair est celui de <em>Apparence → Personnaliser → Identité du site</em>. Choisissez ici la version affichée quand le client est en <strong>mode sombre</strong> (et avec le style d’en-tête « Dark »). Laissez vide pour garder un seul logo.</p><?php $dark_id=absint($s['dark_logo_id'] ?? 0); $dark_url=$dark_id?wp_get_attachment_image_url($dark_id,'medium'):''; ?><div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap"><div style="display:grid;place-items:center;min-width:180px;min-height:72px;padding:10px;border:1px solid #e1e6ef;border-radius:14px;background:#0f1424"><img id="dsb8hs-dark-logo-preview" src="<?php echo esc_url((string)$dark_url); ?>" alt="" style="max-width:220px;max-height:64px;<?php echo $dark_url?'':'display:none'; ?>"><span id="dsb8hs-dark-logo-empty" style="color:#8a94ad;font-size:12px;<?php echo $dark_url?'display:none':''; ?>">Aucun logo sombre</span></div><input type="hidden" id="dsb8hs-dark-logo-id" name="<?php echo $opt; ?>[dark_logo_id]" value="<?php echo (int)$dark_id; ?>"><button type="button" class="button" id="dsb8hs-dark-logo-choose">Choisir le logo sombre</button><button type="button" class="button-link-delete" id="dsb8hs-dark-logo-clear" style="<?php echo $dark_url?'':'display:none'; ?>">Retirer</button></div></section>
          <section class="dsb8hs-card"><h2>Dimensions & spacing</h2><div class="dsb8hs-fields"><?php $fields=array('logo_width'=>'Logo width','logo_offset'=>'Logo position (desktop)','mobile_logo_offset'=>'Logo position (mobile)','header_height'=>'Desktop height','mobile_height'=>'Mobile height','content_width'=>'Content max width','side_padding'=>'Desktop side padding','mobile_padding'=>'Mobile side padding','item_gap'=>'Desktop item gap','mobile_gap'=>'Mobile item gap','action_size'=>'Button size','icon_size'=>'Icon size','avatar_size'=>'Avatar size','border_radius'=>'Header radius','floating_margin'=>'Floating margin','shadow_strength'=>'Shadow strength'); foreach($fields as $k=>$v): ?><div class="dsb8hs-field"><label><?php echo esc_html($v); ?></label><input type="number" name="<?php echo $opt; ?>[<?php echo esc_attr($k); ?>]" value="<?php echo (int)$s[$k]; ?>" data-preview="<?php echo esc_attr($k); ?>"></div><?php endforeach; ?></div></section>
          <section class="dsb8hs-card"><h2>Colors</h2><div class="dsb8hs-fields"><?php foreach(array('accent'=>'Accent','background'=>'Background','text_color'=>'Icons & text','border_color'=>'Border') as $k=>$v): ?><div class="dsb8hs-field"><label><?php echo esc_html($v); ?></label><input type="color" name="<?php echo $opt; ?>[<?php echo esc_attr($k); ?>]" value="<?php echo esc_attr($s[$k]); ?>" data-preview="<?php echo esc_attr($k); ?>"></div><?php endforeach; ?></div></section>
          <section class="dsb8hs-card"><h2>Quick icon menu</h2><table class="dsb8hs-menu-table"><thead><tr><th>Label</th><th>Icon</th><th>URL</th></tr></thead><tbody><?php for($i=0;$i<8;$i++):$item=$s['menu_items'][$i]??array('label'=>'','icon'=>'grid','url'=>'');?><tr><td><input name="<?php echo $opt; ?>[menu_items][<?php echo $i; ?>][label]" value="<?php echo esc_attr($item['label']); ?>"></td><td><select name="<?php echo $opt; ?>[menu_items][<?php echo $i; ?>][icon]"><?php foreach($icons as $k=>$v):?><option value="<?php echo esc_attr($k); ?>" <?php selected($item['icon'],$k); ?>><?php echo esc_html($v); ?></option><?php endforeach;?></select></td><td><input type="url" name="<?php echo $opt; ?>[menu_items][<?php echo $i; ?>][url]" value="<?php echo esc_attr($item['url']); ?>"></td></tr><?php endfor;?></tbody></table></section>
        </main><aside class="dsb8hs-preview"><section class="dsb8hs-card"><h2>Live preview</h2><div class="dsb8hs-preview-shell"><div class="dsb8hs-preview-bar" id="dsb8hs-preview"></div></div><p style="color:#667085">The real header uses your WordPress logo, notification system, WooCommerce cart and each signed-in client's profile photo.</p><p><code>[delicat_v8_header]</code></p></section></aside></div><div class="dsb8hs-actions"><?php submit_button('Save Header Studio','primary','submit',false); ?></div></form></div>
        <?php
    }
}
}
Delicat_Builder_V9_Header_Studio_8::instance();
