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
        add_action('wp_head', array($this, 'theme_boot'), 1);
        add_action('wp_body_open', array($this, 'auto_render_header'), 1);
        add_action('wp_enqueue_scripts', array($this, 'dequeue_legacy_assets'), 99);
        add_filter('body_class', array($this, 'body_class'), 5);
        add_filter('woocommerce_add_to_cart_fragments', array($this, 'cart_fragments'), 99);
        add_action('wp_ajax_delicat_builder_v9_header_remove_cart_item', array($this, 'ajax_remove_cart_item'));
        add_action('wp_ajax_nopriv_delicat_builder_v9_header_remove_cart_item', array($this, 'ajax_remove_cart_item'));
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

        // Frontend self-healing: old/corrupted options must never be able to
        // reintroduce header overflow or PHP 8 array/string TypeErrors.
        $ranges = array(
            'logo_width'=>array(60,320),'logo_offset'=>array(-60,120),'mobile_logo_offset'=>array(-40,80),
            'header_height'=>array(54,140),'mobile_height'=>array(52,110),'content_width'=>array(640,1920),
            'side_padding'=>array(0,80),'mobile_padding'=>array(0,40),'item_gap'=>array(0,60),'mobile_gap'=>array(0,36),
            'action_size'=>array(32,76),'icon_size'=>array(18,46),'avatar_size'=>array(26,64),
            'border_radius'=>array(0,40),'floating_margin'=>array(0,40),'shadow_strength'=>array(0,30),
        );
        foreach ($ranges as $key=>$range) {
            $raw = $settings[$key] ?? $defaults[$key];
            $value = is_numeric($raw) ? (int)$raw : (int)$defaults[$key];
            $settings[$key] = max($range[0], min($range[1], $value));
        }
        foreach (array('sticky','compact','show_search','show_bell','show_theme','show_cart','show_account','dynamic_avatar','show_avatar_ring') as $key) {
            $raw = $settings[$key] ?? $defaults[$key];
            if (is_bool($raw)) $settings[$key] = $raw ? 1 : 0;
            elseif (is_numeric($raw)) $settings[$key] = ((int)$raw) ? 1 : 0;
            else {
                $value = is_scalar($raw) ? strtolower(trim((string)$raw)) : '';
                if (in_array($value, array('yes','true','on'), true)) $settings[$key] = 1;
                elseif (in_array($value, array('no','false','off',''), true)) $settings[$key] = 0;
                else $settings[$key] = empty($defaults[$key]) ? 0 : 1;
            }
        }
        $settings['style'] = in_array(($settings['style'] ?? ''), array('clean','glass','floating','dark'), true) ? $settings['style'] : $defaults['style'];
        $settings['dark_logo_id'] = is_numeric($settings['dark_logo_id'] ?? 0) ? absint($settings['dark_logo_id']) : 0;
        foreach (array('accent','background','text_color','border_color') as $key) {
            $raw = is_scalar($settings[$key] ?? null) ? (string)$settings[$key] : '';
            $settings[$key] = sanitize_hex_color($raw) ?: $defaults[$key];
        }
        $settings['search_placeholder'] = sanitize_text_field(is_scalar($settings['search_placeholder'] ?? null) ? (string)$settings['search_placeholder'] : $defaults['search_placeholder']);
        $valid = array('menu','logo','spacer','bell','theme','account','cart');
        $raw_order = is_array($settings['item_order'] ?? null) ? $settings['item_order'] : $defaults['item_order'];
        $order = array();
        foreach ($raw_order as $candidate) {
            if (!is_scalar($candidate)) continue;
            $candidate = sanitize_key((string)$candidate);
            if (in_array($candidate, $valid, true)) $order[] = $candidate;
        }
        $order = array_values(array_unique($order));
        foreach ($valid as $item) if (!in_array($item, $order, true)) $order[] = $item;
        $settings['item_order'] = $order;
        $raw_spacing = is_array($settings['item_spacing'] ?? null) ? $settings['item_spacing'] : array();
        $settings['item_spacing'] = array();
        foreach ($valid as $item) {
            $raw = $raw_spacing[$item] ?? 0;
            $settings['item_spacing'][$item] = max(-100, min(100, is_numeric($raw) ? (int)$raw : 0));
        }
        $menu_items = array();
        foreach ((array)($settings['menu_items'] ?? array()) as $item) {
            if (!is_array($item)) continue;
            $label = sanitize_text_field(is_scalar($item['label'] ?? null) ? (string)$item['label'] : '');
            $url = esc_url_raw(is_scalar($item['url'] ?? null) ? (string)$item['url'] : '');
            $path = wp_parse_url($url, PHP_URL_PATH);
            if (in_array($path, array('/exchange','/exchange/'), true)) $url = '/product-category/exchange/';
            if (in_array($path, array('/product-category/streaming','/product-category/streaming/'), true)) $url = '/product-category/abonnement/';
            $icon = sanitize_key(is_scalar($item['icon'] ?? null) ? (string)$item['icon'] : 'grid');
            if ($label !== '' && $url !== '') $menu_items[] = array('label'=>$label,'url'=>$url,'icon'=>$icon ?: 'grid');
            if (count($menu_items) >= 8) break;
        }
        $settings['menu_items'] = $menu_items ?: $defaults['menu_items'];
        if (!empty($settings['show_theme']) && !in_array('theme', $settings['item_order'], true)) {
            $bell = array_search('bell', $settings['item_order'], true);
            $at = ($bell === false) ? count($settings['item_order']) : $bell + 1;
            array_splice($settings['item_order'], $at, 0, array('theme'));
        }
        self::$settings_cache = $settings;
        return self::$settings_cache;
    }

    public function register_assets() {
        if (is_admin()) return;
        $base = plugin_dir_url(dirname(__FILE__));
        $chrome = plugin_dir_path(dirname(__FILE__)) . 'assets/css/storefront-chrome.min.css';
        if (is_file($chrome) && !wp_style_is('delicat-builder-v9-storefront-chrome', 'registered')) {
            wp_register_style('delicat-builder-v9-storefront-chrome', $base . 'assets/css/storefront-chrome.min.css', array(), DELICAT_BUILDER_V9_VERSION);
        }
        wp_register_style('delicat-builder-v9-header-studio-8', $base . 'assets/dsb8-beta2-header.css', array(), DELICAT_BUILDER_V9_VERSION);
        wp_register_script('delicat-builder-v9-header-studio-8', $base . 'assets/dsb8-beta2-header.js', array(), DELICAT_BUILDER_V9_VERSION, true);
        if (!wp_style_is('delicat-builder-v9-theme-system', 'registered')) {
            wp_register_style('delicat-builder-v9-theme-system', $base . 'assets/css/theme-system.css', array(), DELICAT_BUILDER_V9_VERSION);
        }
        if (!wp_script_is('delicat-builder-v9-theme', 'registered')) {
            wp_register_script('delicat-builder-v9-theme', $base . 'assets/js/theme.js', array(), DELICAT_BUILDER_V9_VERSION, true);
            if (function_exists('wp_script_add_data')) wp_script_add_data('delicat-builder-v9-theme', 'strategy', 'defer');
        }
    }

    public function theme_boot() {
        if (is_admin() || !self::should_auto_render()) return;
        $shell = get_option('delicat_builder_v9_shell', array());
        $default = is_array($shell) && in_array(($shell['theme_default'] ?? 'light'), array('light','dark','system'), true) ? $shell['theme_default'] : 'light';
        ?>
        <script id="delicat-builder-v9-global-theme-boot">
        (function(){var r=document.documentElement,n=navigator||{},c=n.connection||n.mozConnection||n.webkitConnection||null,m=Number(n.deviceMemory||0),h=Number(n.hardwareConcurrency||0),sd=!!(c&&c.saveData),lp=(m>0&&m<=4)||(h>0&&h<=4),rm=window.matchMedia&&window.matchMedia('(prefers-reduced-motion:reduce)').matches,k='dbv9_theme',d=<?php echo wp_json_encode($default); ?>,p=d,t='light';if(sd)r.classList.add('delicat-save-data');if(sd||lp)r.classList.add('delicat-low-power','dsb8-low-power','dsb-cheap-device');else if(rm)r.classList.add('dsb-cheap-device');try{var s=decodeURIComponent((document.cookie.match(new RegExp('(?:^|;\\s*)'+k+'=([^;]*)'))||[])[1]||'');if(s==='light'||s==='dark'||s==='system'){p=s}if(p==='dark'){t='dark'}else if(p==='system'){t=window.matchMedia&&window.matchMedia('(prefers-color-scheme:dark)').matches?'dark':'light'}}catch(e){p=d;t=d==='dark'?'dark':'light'}r.dataset.delicatThemePreference=p;r.dataset.delicatTheme=t;r.classList.toggle('dlc-theme-dark',t==='dark');r.classList.toggle('dlc-theme-light',t==='light');r.style.colorScheme=t})();
        </script>
        <?php
    }

    private function page_has_component() {
        if (is_admin()) return false; global $post;
        if (!$post || !is_string($post->post_content)) return false;
        return function_exists('dsb8_page_has_shortcode') ? dsb8_page_has_shortcode(array('delicat_v8_header','delicat_v8_icon_menu')) : (has_shortcode($post->post_content, 'delicat_v8_header') || has_shortcode($post->post_content, 'delicat_v8_icon_menu'));
    }
    public function conditional_assets() {
        if (is_admin() || (!self::should_auto_render() && !$this->page_has_component())) return;
        if (wp_style_is('delicat-builder-v9-storefront-chrome', 'registered')) {
            /* RC71.4: one cacheable app-shell sheet replaces Theme + Header +
             * Drawer + Bottom Nav on every storefront route. Previously only
             * managed Builder pages used the bundle, while Shop/Product/Account
             * paid four render-blocking CSS requests for the same bytes. */
            wp_enqueue_style('delicat-builder-v9-storefront-chrome');
        } else {
            wp_enqueue_style('delicat-builder-v9-theme-system');
        }
        wp_enqueue_script('delicat-builder-v9-theme');
        $this->enqueue_assets();
    }
    public function dequeue_legacy_assets() {
        if (class_exists('DSB8_Beta2_Header', false)) {
            wp_dequeue_style('dsb8-beta2-header');
            wp_dequeue_script('dsb8-beta2-header');
        }
    }
    private function enqueue_assets() {
        if (!wp_style_is('delicat-builder-v9-storefront-chrome', 'enqueued')) wp_enqueue_style('delicat-builder-v9-header-studio-8');
        wp_enqueue_script('delicat-builder-v9-header-studio-8');
        if (function_exists('wp_script_add_data')) wp_script_add_data('delicat-builder-v9-header-studio-8', 'strategy', 'defer');
    }
    public function shortcodes() { add_shortcode('delicat_v8_header', array($this, 'render_header')); add_shortcode('delicat_v8_icon_menu', array($this, 'render_icon_menu')); }

    /**
     * Header Studio is the native storefront header owner. Safe Mode may
     * quarantine optional motion/search/commerce enhancements, but it must not
     * hand the document back to the old theme header.
     */
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

    /**
     * Whether this document is headed for the shared page cache, in which case
     * nothing specific to the current visitor may be rendered into it.
     */
    private function shared_document() {
        return class_exists('Delicat_Builder_V9_Security', false)
            && is_callable(array('Delicat_Builder_V9_Security', 'shared_document'))
            && Delicat_Builder_V9_Security::shared_document();
    }
    /**
     * The real count for a private response; zero for a cacheable one.
     *
     * A cacheable document is shared verbatim with every other guest, so a real
     * count here would show one shopper's basket size to everyone who got that
     * copy. The client restores the true count from `woocommerce_items_in_cart`
     * before paint, so the neutral value is never what the shopper sees.
     */
    private function cart_count() {
        if ($this->shared_document()) { return 0; }
        return function_exists('WC') && WC()->cart ? (int) WC()->cart->get_cart_contents_count() : 0;
    }
    private function cart_fragment_markup() {
        $count = $this->cart_count();
        return '<span class="dsb8-woo-cart-count-fragment" hidden data-count="'.esc_attr($count).'">'.esc_html($count).'</span>';
    }
    private function cart_panel_markup() {
        /* A cacheable document carries no basket: neither the count, nor the
         * line items, nor the subtotal. WooCommerce's cart fragments and
         * session.js fill all three in on the client. */
        $shared = $this->shared_document();
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
            <?php if(!$shared && function_exists('WC') && WC()->cart && !WC()->cart->is_empty()): foreach(WC()->cart->get_cart() as $key=>$line):
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

    /** RC71.2: render exactly one logo element. Theme JS swaps its source when a
     * dedicated dark logo is configured. Keeping two independent logo links in
     * the DOM made third-party/late CSS capable of exposing both at once. */
    private function logo_html() {
        $light_id = absint(get_theme_mod('custom_logo', 0));
        if ($light_id <= 0) {
            $legacy = get_custom_logo();
            return $legacy ? $legacy : '<a class="dsb8-header__brand" href="'.esc_url(home_url('/')).'">Delicat Top Up</a>';
        }
        $s = $this->settings();
        $dark_id = absint($s['dark_logo_id'] ?? 0);
        $light_url = wp_get_attachment_image_url($light_id, 'medium_large');
        $dark_url = $dark_id > 0 ? wp_get_attachment_image_url($dark_id, 'medium_large') : '';
        $attrs = array(
            'class' => 'custom-logo dsb8-header__logo-image',
            'loading' => 'eager',
            'decoding' => 'async',
            'alt' => get_bloginfo('name'),
        );
        if ($light_url) $attrs['data-dsb8-logo-light'] = esc_url_raw($light_url);
        if ($dark_url && $dark_id !== $light_id) $attrs['data-dsb8-logo-dark'] = esc_url_raw($dark_url);
        $img = wp_get_attachment_image($light_id, 'medium_large', false, $attrs);
        if (!is_string($img) || '' === $img) {
            return '<a class="dsb8-header__brand" href="'.esc_url(home_url('/')).'">Delicat Top Up</a>';
        }
        return '<a class="custom-logo-link dsb8-header__single-logo" href="'.esc_url(home_url('/')).'" rel="home">'.$img.'</a>';
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
        if ($item === 'logo') return '<div class="dsb8-header__logo" style="'.$style.'">'.$logo.'</div>';
        if ($item === 'spacer') return '<div class="dsb8-header__spacer" style="'.$style.'" aria-hidden="true"></div>';
        if ($item === 'bell' && $s['show_bell']) return '<div class="dsb8-header__slot dsb8-header__slot--bell" style="'.$style.'">'.do_shortcode('[delicat_header_notifications]').'</div>';
        if ($item === 'theme' && !empty($s['show_theme'])) return '<button class="dsb8-header__action dsb8-header__theme" style="'.$style.'" type="button" data-delicat-theme-toggle aria-label="Activer le mode sombre" aria-pressed="false">'.$this->icon('theme').'</button>';
        if ($item === 'account' && $s['show_account']) {
            $class = 'dsb8-header__action dsb8-header__account'.(!empty($s['show_avatar_ring'])?' has-ring':'');
            if (!is_user_logged_in() && class_exists('Delicat_Builder_V9_Identity_Bridge', false) && Delicat_Builder_V9_Identity_Bridge::frontend_login_available()) {
                return '<a class="'.$class.'" style="'.$style.'" href="#delicat-login" data-dip-auth-open data-dl-open data-delicat-no-app="1" aria-haspopup="dialog" aria-controls="dip-identity-modal" aria-label="Connexion">'.$this->account_content($s).'</a>';
            }
            return '<a class="'.$class.'" style="'.$style.'" href="'.esc_url($this->account_url()).'" aria-label="Mon compte">'.$this->account_content($s).'</a>';
        }
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
        $logo=$this->logo_html();
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
        if (!self::$embedded_menu_rendered) {
            self::$embedded_menu_rendered = true;
            if (class_exists('Delicat_Builder_V9_Menu_Builder', false)) {
                echo Delicat_Builder_V9_Menu_Builder::render('drawer');
            } elseif (function_exists('dsb_render_modern_menu')) {
                echo dsb_render_modern_menu('drawer');
            }
        }
return ob_get_clean();
    }

    public function render_icon_menu($atts=array()) {
        $this->enqueue_assets(); $s=$this->settings(); $atts=shortcode_atts(array('limit'=>'6','class'=>''),$atts,'delicat_v8_icon_menu'); $items=array_slice($s['menu_items'],0,min(8,max(1,absint($atts['limit']))));
        ob_start(); ?><nav class="dsb8-icon-menu <?php echo esc_attr(sanitize_html_class($atts['class'])); ?>" aria-label="Catégories rapides" data-dsb8-icon-menu style="--dsb8-header-accent:<?php echo esc_attr($s['accent']); ?>"><?php foreach($items as $item): ?><a class="dsb8-icon-menu__item" href="<?php echo esc_url($item['url']); ?>"><span class="dsb8-icon-menu__icon"><?php echo $this->icon($item['icon']); ?></span><span class="dsb8-icon-menu__label"><?php echo esc_html($item['label']); ?></span></a><?php endforeach; ?></nav><?php return ob_get_clean();
    }

}
}
Delicat_Builder_V9_Header_Studio_8::instance();
