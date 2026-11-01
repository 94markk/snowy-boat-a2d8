<?php
if ( ! defined( 'ABSPATH' ) ) exit;
class DSA2_Frontend {
    private static $fragment_rendering = false;
    private static $page_context_override = null;

    public static function init() {
        add_shortcode( 'delicat_assistant', array( __CLASS__, 'shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
        add_action( 'wp_footer', array( __CLASS__, 'floating_widget' ) );
        add_action( 'wp_ajax_dsa2_lazy_shell', array( __CLASS__, 'ajax_lazy_shell' ) );
        add_action( 'wp_ajax_nopriv_dsa2_lazy_shell', array( __CLASS__, 'ajax_lazy_shell' ) );
        add_filter( 'style_loader_tag', array( __CLASS__, 'asset_tag' ), 10, 2 );
        add_filter( 'script_loader_tag', array( __CLASS__, 'asset_tag' ), 10, 2 );
        // The Builder ships an older assistant. Removing its hook by name only worked when it
        // registered at priority 10; this sweeps whatever priority it actually used.
        add_action( 'template_redirect', array( __CLASS__, 'suppress_legacy_widget' ), 1 );
    }

    /**
     * Removes every wp_footer callback owned by the Builder's legacy assistant, at any
     * priority and under any method name, so two widgets can never render at once.
     */
    public static function suppress_legacy_widget() {
        if ( ! class_exists( 'Delicat_Assistant_Pro', false ) ) return;
        if ( empty( $GLOBALS['wp_filter']['wp_footer'] ) ) return;
        $hook = $GLOBALS['wp_filter']['wp_footer'];
        if ( ! isset( $hook->callbacks ) || ! is_array( $hook->callbacks ) ) return;
        foreach ( $hook->callbacks as $priority => $callbacks ) {
            foreach ( (array) $callbacks as $cb ) {
                $fn = isset( $cb['function'] ) ? $cb['function'] : null;
                if ( is_array( $fn ) && isset( $fn[0] ) && is_object( $fn[0] ) && $fn[0] instanceof Delicat_Assistant_Pro ) {
                    remove_action( 'wp_footer', $fn, $priority );
                }
            }
        }
    }

    /** Version strings follow the file itself, so an edited asset always gets a new URL. */
    private static function asset_version( $relative ) {
        $path = DSA2_DIR . $relative;
        return file_exists( $path ) ? DSA2_VERSION . '.' . filemtime( $path ) : DSA2_VERSION;
    }

    /**
     * How the ~126 KB raw runtime (stylesheet + script) reaches the page.
     *
     *  eager   — enqueued in the head on every page, as before 2.3.0. The safety valve:
     *            if a cache layer ever misbehaves with the boot loader, this is the
     *            known-good behaviour.
     *  auto    — inline boot loader; the runtime is fetched when the browser goes idle,
     *            and immediately on a tap. Skipped on save-data, 2G and low-memory
     *            phones, which then load it only when the customer opens the chat.
     *  demand  — inline boot loader; the runtime is fetched on the first tap, never before.
     */
    public static function runtime_mode() {
        $s = get_option( 'dap_settings', array() );
        $mode = isset( $s['runtime_load'] ) ? sanitize_key( $s['runtime_load'] ) : 'demand';
        return in_array( $mode, array( 'auto', 'demand', 'eager' ), true ) ? $mode : 'demand';
    }

    public static function register_assets() {
        wp_register_style('dap-front', DSA2_URL.'assets/css/frontend.css', array(), self::asset_version('assets/css/frontend.css'));
        wp_register_script('dap-front', DSA2_URL.'assets/js/frontend.js', array(), self::asset_version('assets/js/frontend.js'), true);

        // Enqueue during the head pass rather than from wp_footer. A stylesheet enqueued
        // inside wp_footer is a "late style", and page optimisers (LiteSpeed critical CSS /
        // "load CSS asynchronously", Autoptimize) routinely drop or defer late styles on
        // cached pages. Logged-in users bypass the cache and saw a perfect widget; logged-out
        // visitors got the same markup with half its CSS missing.
        if ( is_admin() || 'eager' !== self::runtime_mode() ) return;
        $s = get_option( 'dap_settings', array() );
        if ( ! empty( $s['enabled'] ) || self::page_has_shortcode() ) self::enqueue();
    }

    private static function page_has_shortcode() {
        if ( ! is_singular() ) return false;
        $content = (string) get_post_field( 'post_content', (int) get_queried_object_id() );
        return $content && has_shortcode( $content, 'delicat_assistant' );
    }

    /**
     * Marks both files as off-limits to JS/CSS combining, deferring and Rocket Loader.
     * Combining the widget with the rest of the page is what produced a stale, half-working
     * assistant for guests while the logged-in view stayed correct.
     */
    public static function asset_tag( $tag, $handle ) {
        if ( 'dap-front' !== $handle || false !== strpos( $tag, 'data-no-optimize' ) ) return $tag;
        return preg_replace( '/<(script|link)\s/', '<$1 data-no-optimize="1" data-no-defer="1" data-cfasync="false" ', $tag, 1 );
    }

    public static function enqueue() {
        static $done = false;
        if ( $done ) return; // register_assets(), the shortcode and wp_footer can all ask.
        $done = true;
        wp_enqueue_style('dap-front');
        wp_enqueue_script('dap-front');
        wp_localize_script( 'dap-front', 'DSA2_DATA', self::config( true ) );
    }

    /** Everything the widget needs to know, printed once per page. */
    public static function config( $floating = true ) {
        $s = wp_parse_args(get_option('dap_settings',array()), array('agent_status'=>'online','auto_open'=>0,'whatsapp'=>'','show_whatsapp'=>1));
        $s['agent_status'] = DSA2_Data::effective_status(); // respects opening hours
        $wa = preg_replace('/\D+/', '', (string) $s['whatsapp']);
        return array(
            'ajax'=>admin_url('admin-ajax.php'),
            'ajaxPath'=>wp_parse_url( admin_url('admin-ajax.php'), PHP_URL_PATH ),
            'nonce'=>wp_create_nonce('dsa2_frontend'),
            'shellAction'=>'dsa2_lazy_shell',
            // Only what the JS needs — the full option (FAQ rules) is not printed into pages.
            'settings'=>array(
                'agent_status'=>$s['agent_status'],'auto_open'=>(int)$s['auto_open'],
                'whatsapp_url'=>$wa ? 'https://wa.me/'.$wa : '',
                'show_whatsapp'=>(int) ( ! empty( $s['show_whatsapp'] ) && $wa ),
                'launcher_side'=>( isset( $s['launcher_side'] ) && 'left' === $s['launcher_side'] ) ? 'left' : 'right',
                'launcher_bottom'=>isset( $s['launcher_bottom'] ) ? max( 0, min( 400, (int) $s['launcher_bottom'] ) ) : 16,
                'launcher_popup_delay_seconds'=>isset($s['launcher_popup_delay']) ? max(3,min(120,(int)$s['launcher_popup_delay'])) : 10,
                'launcher_popup_seconds'=>isset($s['launcher_popup_interval']) ? max(30,min(600,(int)$s['launcher_popup_interval'])) : 75,
                'launcher_popup_visible_seconds'=>isset($s['launcher_popup_visible']) ? max(3,min(15,(int)$s['launcher_popup_visible'])) : 6,
                'launcher_popup_max'=>isset($s['launcher_popup_max']) ? max(0,min(10,(int)$s['launcher_popup_max'])) : 3,
                'launcher_suppress_checkout'=>!empty($s['launcher_suppress_checkout']) ? 1 : 0,
                'product_context_enabled'=>!isset($s['product_context_enabled']) || !empty($s['product_context_enabled']) ? 1 : 0,
                'order_support_enabled'=>!isset($s['order_support_enabled']) || !empty($s['order_support_enabled']) ? 1 : 0,
                'font_scale'=>isset($s['font_scale']) ? max(90,min(125,(int)$s['font_scale'])) : 100,
                'force_reduced_motion'=>!empty($s['force_reduced_motion']) ? 1 : 0,
                'agent_eta'=>DSA2_Data::agent_eta($s['agent_status']),
                // Targets for imported FAQ buttons (action "url" with a named value).
                'urls'=>array(
                    'wallet'=>DSA2_Data::store_url('wallet'),
                    'redeem'=>DSA2_Data::store_url('redeem'),
                    'rewards'=>DSA2_Data::store_url('rewards'),
                    'shop'=>DSA2_Data::store_url('shop'),
                    'account'=>DSA2_Data::account_url(),
                ),
            ),
            'loggedIn'=>is_user_logged_in(),
            'userId'=>get_current_user_id(),
            // Recovery handle: the widget re-requests this if it detects its CSS never applied.
            'css'=>DSA2_URL.'assets/css/frontend.css?ver='.self::asset_version('assets/css/frontend.css'),
            // The stylesheet stamps its own version into --dap-css-ok. A cache layer that hands
            // a guest last release's file now fails the check instead of silently passing it.
            'cssVersion'=>DSA2_VERSION,
            'js'=>DSA2_URL.'assets/js/frontend.js?ver='.self::asset_version('assets/js/frontend.js'),
            'floating'=>(bool) $floating,
            'prefetch'=>'auto' === self::runtime_mode(),
            'page'=>self::page_context(),
        );
    }
    /** Safe page/product context shared with the website and native assistant. */
    private static function page_context() {
        if ( is_array( self::$page_context_override ) ) return self::$page_context_override;
        $pid = 0; $title = ''; $type = 'page';
        if ( function_exists( 'is_product' ) && is_product() ) {
            $type = 'product';
            $pid = (int) get_queried_object_id();
            $title = (string) get_the_title( $pid );
        } elseif ( function_exists('is_checkout') && is_checkout() ) {
            $type = 'checkout'; $title = (string) get_the_title( (int) get_queried_object_id() );
        } elseif ( function_exists('is_cart') && is_cart() ) {
            $type = 'cart'; $title = (string) get_the_title( (int) get_queried_object_id() );
        } elseif ( function_exists('is_account_page') && is_account_page() ) {
            $type = 'account'; $title = (string) get_the_title( (int) get_queried_object_id() );
        } elseif ( is_singular() ) {
            $title = (string) get_the_title( (int) get_queried_object_id() );
        }
        $product = array();
        if ( $pid && function_exists('wc_get_product') ) {
            $wc = wc_get_product( $pid );
            if ( $wc && 'publish' === get_post_status($pid) ) {
                $cats = wp_get_post_terms( $pid, 'product_cat', array('fields'=>'names') );
                $haystack = strtolower( $title . ' ' . implode(' ', is_wp_error($cats)?array():$cats) );
                $product = array(
                    'id'=>$pid,
                    'name'=>sanitize_text_field($title),
                    'url'=>get_permalink($pid),
                    'price'=>wp_strip_all_tags($wc->get_price_html()),
                    'type'=>sanitize_key($wc->get_type()),
                    'stock_status'=>sanitize_key($wc->get_stock_status()),
                    'categories'=>array_slice(array_map('sanitize_text_field',is_wp_error($cats)?array():$cats),0,6),
                    'is_pin'=>(bool)(false!==strpos($haystack,'pin') || get_post_meta($pid,'_delicat_pin_product',true) || get_post_meta($pid,'_is_pin_product',true)),
                );
            }
        }
        // ?dap_guide=natcash_auto — a guide shared to WhatsApp opens straight on that guide.
        $guide = isset( $_GET['dap_guide'] ) ? sanitize_key( wp_unslash( $_GET['dap_guide'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $guide && ! DSA2_Guides::exists( $guide ) ) $guide = '';
        $lang = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( ! in_array( $lang, array( 'fr', 'en', 'ht' ), true ) ) $lang = '';
        return array(
            'page_type'=>$type,
            'product_id'=>$pid,
            'title'=>sanitize_text_field($title),
            'url'=>is_singular()?get_permalink((int)get_queried_object_id()):home_url(add_query_arg(array(),$GLOBALS['wp']->request??'')),
            'product'=>$product,
            'guide'=>$guide,
            'lang'=>$lang,
        );
    }
    private static $shortcode_rendered = false;
    public static function shortcode() {
        // Two shortcode blocks on one page previously created two pollers writing to the
        // same conversation and duplicated every accessibility id. Render one workspace.
        if ( self::$shortcode_rendered ) return '';
        self::$shortcode_rendered = true;
        if ( 'eager' === self::runtime_mode() ) self::enqueue();
        return self::markup(false);
    }
    public static function floating_widget() {
        if ( self::$shortcode_rendered ) return; // Avoid two live widgets (double polling) on the dedicated assistant page.
        $s = get_option('dap_settings',array());
        if ( empty($s['enabled']) || is_admin() ) return;
        if ( 'eager' === self::runtime_mode() ) {
            self::enqueue();
            echo self::markup(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            return;
        }
        // Performance mode: only the tiny launcher exists in the initial document. The large
        // panel DOM + runtime are requested after the first interaction (or intentional idle
        // prefetch in Auto mode). This keeps product/archive pages small and cheap to parse.
        echo self::launcher_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    /** Public, read-only lazy shell endpoint. The nonce prevents cross-site request abuse. */
    public static function ajax_lazy_shell() {
        if ( false === check_ajax_referer( 'dsa2_frontend', 'nonce', false ) ) {
            wp_send_json_error( array('code'=>'nonce_expired'), 403 );
        }
        $type = isset($_POST['page_type']) ? sanitize_key(wp_unslash($_POST['page_type'])) : 'page';
        if ( ! in_array( $type, array('page','product','checkout','cart','account'), true ) ) $type = 'page';
        $pid = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
        $title = isset($_POST['title']) ? sanitize_text_field(wp_unslash($_POST['title'])) : '';
        $guide = isset($_POST['guide']) ? sanitize_key(wp_unslash($_POST['guide'])) : '';
        if ( $guide && ! DSA2_Guides::exists($guide) ) $guide = '';
        $lang = isset($_POST['lang']) ? sanitize_key(wp_unslash($_POST['lang'])) : '';
        if ( ! in_array($lang, array('fr','en','ht'), true) ) $lang = '';

        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $site_host = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        $url_host  = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        if ( ! $url || ( $url_host && $site_host && $url_host !== $site_host ) ) $url = home_url('/');

        $product = array();
        if ( $pid && function_exists('wc_get_product') && 'publish' === get_post_status($pid) ) {
            $wc = wc_get_product($pid);
            if ( $wc ) {
                $safe_title = $title ?: (string) get_the_title($pid);
                $cats = wp_get_post_terms($pid, 'product_cat', array('fields'=>'names'));
                $cat_names = is_wp_error($cats) ? array() : $cats;
                $haystack = strtolower($safe_title . ' ' . implode(' ', $cat_names));
                $product = array(
                    'id'=>$pid,
                    'name'=>sanitize_text_field($safe_title),
                    'url'=>get_permalink($pid),
                    'price'=>wp_strip_all_tags($wc->get_price_html()),
                    'type'=>sanitize_key($wc->get_type()),
                    'stock_status'=>sanitize_key($wc->get_stock_status()),
                    'categories'=>array_slice(array_map('sanitize_text_field',$cat_names),0,6),
                    'is_pin'=>(bool)(false!==strpos($haystack,'pin') || get_post_meta($pid,'_delicat_pin_product',true) || get_post_meta($pid,'_is_pin_product',true)),
                );
                $type = 'product';
                $title = $safe_title;
            } else {
                $pid = 0;
            }
        } else {
            $pid = 0;
        }

        self::$page_context_override = array(
            'page_type'=>$type,
            'product_id'=>$pid,
            'title'=>$title,
            'url'=>$url,
            'product'=>$product,
            'guide'=>$guide,
            'lang'=>$lang,
        );
        self::$fragment_rendering = true;
        $html = self::markup(true);
        self::$fragment_rendering = false;
        self::$page_context_override = null;
        wp_send_json_success( array('html'=>$html) );
    }

    /** Tiny initial floating shell: launcher only, no hidden support-center DOM. */
    private static function launcher_markup() {
        $settings = wp_parse_args(get_option('dap_settings',array()), array(
            'primary_color'=>'#ff5a2b','launcher_side'=>'right','launcher_bottom'=>16,
            'launcher_label_enabled'=>1,'launcher_kicker'=>'Besoin d’aide ?','launcher_message'=>'Discutez avec nous !'
        ));
        $status = DSA2_Data::effective_status();
        $accent = sanitize_hex_color($settings['primary_color']) ?: '#ff5a2b';
        $bottom = max(0,min(400,(int)$settings['launcher_bottom']));
        $left = isset($settings['launcher_side']) && 'left' === $settings['launcher_side'];
        ob_start();
        echo self::launcher_style_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo self::boot_tag(true); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        ?>
        <div class="dap-shell dap-floating dap-lazy-shell<?php echo $left?' dap-launcher-left':''; ?>" data-dap-root data-dap-lazy="1" style="--dap-accent:<?php echo esc_attr($accent); ?>;--dap-launcher-base-bottom:<?php echo esc_attr($bottom); ?>px">
            <button class="dap-launcher" type="button" aria-label="Ouvrir Delicat Assistant — <?php echo esc_attr($settings['launcher_message']); ?>" aria-haspopup="dialog" aria-expanded="false"><span class="dap-launcher-orb"><?php echo self::bot_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><i class="dap-live-dot" data-status="<?php echo esc_attr($status); ?>" aria-hidden="true"></i><b class="dap-unread" data-agent-unread hidden>0</b></span><?php if(!empty($settings['launcher_label_enabled'])):?><span class="dap-launcher-copy" role="status" aria-live="polite"><small><?php echo esc_html($settings['launcher_kicker']); ?></small><strong><?php echo esc_html($settings['launcher_message']); ?></strong></span><?php endif;?></button>
        </div><?php
        return ob_get_clean();
    }
    /**
     * The widget's layout, printed inline in the same document as the markup.
     *
     * Why inline: the panel used to get its geometry from assets/css/frontend.css alone. The
     * page HTML and that stylesheet are two separate artefacts, and the cache layers in front
     * of this site version them independently — so a logged-out visitor could be served last
     * release's HTML with this release's CSS (or the reverse), while a logged-in admin, who
     * bypasses the page cache entirely, always got a matched pair and saw nothing wrong. That
     * mismatch is what produced the full-bleed search box, the giant WhatsApp glyph and the
     * unstyled buttons. Markup and layout now travel in one document and cannot disagree.
     *
     * Colours stay as var(--dap-*) so the dark theme in the stylesheet keeps overriding them.
     * Nowdoc, not heredoc: nothing here may be interpolated.
     */
    private static function critical_css() {
        return <<<'DAPCSS'
.dap-shell{--dap-auto-dock-height:0px;--dap-accent:#ff5a2b;--dap-accent-2:#ff7a27;--dap-bg:#f5f6fb;--dap-surface:#fff;--dap-text:#10152e;--dap-muted:#6f778b;--dap-line:#e5e8f0;--dap-shadow:0 24px 70px rgba(12,18,55,.24);--dap-safe-bottom:env(safe-area-inset-bottom,0px);font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif!important;color:var(--dap-text)!important;font-size:16px!important;line-height:1.4!important;isolation:isolate!important}
.dap-shell,.dap-shell *,.dap-shell *::before,.dap-shell *::after{box-sizing:border-box!important}
.dap-shell svg{max-width:100%!important;max-height:100%!important}
.dap-shell .dap-floating,.dap-floating{position:fixed!important;z-index:2147483000!important;right:18px!important;bottom:18px!important;width:auto!important;height:auto!important;pointer-events:none!important}
.dap-floating::before{content:""!important;position:fixed!important;inset:0!important;background:rgba(8,12,31,.52)!important;opacity:0;visibility:hidden;transition:opacity .2s ease,visibility .2s ease;pointer-events:none}
.dap-floating.is-open::before{opacity:1;visibility:visible;pointer-events:auto}
.dap-shell .dap-launcher{all:unset!important;box-sizing:border-box!important;pointer-events:auto!important;position:relative!important;display:flex!important;flex-direction:column!important;align-items:center!important;gap:7px!important;min-width:136px!important;color:#fff!important;cursor:pointer!important}
.dap-shell .dap-launcher-orb{position:relative!important;width:64px!important;height:64px!important;border-radius:50%!important;background:linear-gradient(145deg,#775cff,#4d2fd2)!important;border:3px solid rgba(255,255,255,.96)!important;box-shadow:0 14px 34px rgba(76,47,210,.34),0 4px 12px rgba(16,22,95,.18)!important;display:grid!important;place-items:center!important;transition:transform .18s ease,box-shadow .18s ease!important}
.dap-shell .dap-launcher-orb svg{width:37px!important;height:37px!important;display:block!important;overflow:visible!important}
.dap-shell .dap-launcher:hover .dap-launcher-orb{transform:translateY(-2px)!important;box-shadow:0 18px 38px rgba(76,47,210,.38),0 5px 14px rgba(16,22,95,.2)!important}
.dap-shell .dap-launcher:active .dap-launcher-orb{transform:scale(.95)!important}
.dap-shell .dap-launcher-copy{min-width:136px!important;padding:6px 11px 7px!important;border-radius:15px!important;background:rgba(255,255,255,.97)!important;border:1px solid rgba(31,40,103,.08)!important;box-shadow:0 8px 24px rgba(16,22,95,.16)!important;color:#171c50!important;text-align:center!important;display:flex!important;flex-direction:column!important;line-height:1.12!important;white-space:nowrap!important}
.dap-shell .dap-launcher-copy small{font-size:9px!important;font-weight:700!important;color:#7b8198!important;line-height:1.15!important}
.dap-shell .dap-launcher-copy strong{font-size:11.5px!important;font-weight:850!important;color:#171c50!important;line-height:1.2!important}
@keyframes dap-live-pulse{0%,100%{transform:scale(1)}50%{transform:scale(.82);box-shadow:0 0 0 6px rgba(255,36,85,.09)}}
.dap-floating .dap-panel{position:absolute!important;right:0!important;bottom:126px!important;visibility:hidden;opacity:0!important;transform:translate3d(0,14px,0) scale(.985)!important;pointer-events:none!important;transition:opacity .2s ease,transform .22s cubic-bezier(.22,.8,.24,1),visibility .2s!important}
.dap-floating.is-open .dap-panel{visibility:visible!important;opacity:1!important;transform:translate3d(0,0,0) scale(1)!important;pointer-events:auto!important}
.dap-floating.is-open .dap-launcher{visibility:hidden!important;pointer-events:none!important}
.dap-shell .dap-sound-toggle[aria-pressed="false"]{opacity:.55!important}
.dap-shell.dap-booting .dap-launcher{animation:dap-boot-pulse 1s ease-in-out infinite!important}
@keyframes dap-boot-pulse{0%,100%{transform:scale(1)}50%{transform:scale(.92)}}
@media(prefers-reduced-motion:reduce){.dap-shell.dap-booting .dap-launcher{animation:none!important;opacity:.72!important}.dap-shell .dap-live-dot{animation:none!important}.dap-shell .dap-launcher-orb{transition:none!important}}
.dap-shell .dap-nav>button{all:unset!important;box-sizing:border-box!important;position:relative!important;display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;width:100%!important;max-width:none!important;min-width:0!important;height:56px!important;min-height:56px!important;margin:0!important;padding:3px 1px!important;border:0!important;border-radius:12px!important;background:transparent!important;color:var(--dap-muted)!important;font-family:inherit!important;font-size:20px!important;line-height:1!important;text-align:center!important;box-shadow:none!important;animation:none!important;transform:none!important;overflow:hidden!important;cursor:pointer!important}
.dap-shell .dap-nav>button>span{display:block!important;width:100%!important;max-width:100%!important;margin:5px 0 0!important;padding:0!important;font-size:9px!important;line-height:1.1!important;font-weight:750!important;text-align:center!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important;color:inherit!important}
.dap-shell .dap-nav>button>.dap-nav-icon{width:22px!important;height:22px!important;max-width:22px!important;margin:0!important;font-size:19px!important;line-height:22px!important;overflow:hidden!important}
.dap-shell .dap-nav svg{display:none!important;width:0!important;height:0!important;max-width:0!important;max-height:0!important;overflow:hidden!important}
.dap-shell .dap-nav>button.is-active{color:var(--dap-accent)!important;background:transparent!important}
.dap-shell .dap-nav>button.is-active::after{content:""!important;display:block!important;position:absolute!important;left:50%!important;bottom:0!important;transform:translateX(-50%)!important;width:22px!important;height:3px!important;border-radius:999px!important;background:var(--dap-accent)!important}
@media(max-width:520px){
 .dap-floating{right:14px!important;bottom:88px!important}
 .dap-floating .dap-panel{position:fixed!important;left:0!important;right:0!important;bottom:0!important;top:auto!important;width:100%!important;height:min(94dvh,840px)!important;border-radius:28px 28px 0 0!important;transform:translate3d(0,105%,0)!important;opacity:1!important;visibility:hidden!important;transition:transform .28s cubic-bezier(.22,.8,.24,1),visibility .28s!important}
 .dap-floating.is-open .dap-panel{visibility:visible!important;transform:translate3d(0,0,0)!important}
 .dap-floating .dap-launcher{position:fixed!important;right:16px!important;bottom:calc(16px + var(--dap-safe-bottom))!important}
 .dap-shell .dap-header{padding:20px 18px 17px!important;min-height:104px!important;border-radius:28px 28px 0 0!important}
 .dap-shell .dap-handle{display:block!important;position:absolute!important;top:8px!important;left:50%!important;width:36px!important;height:5px!important;border-radius:999px!important;background:rgba(255,255,255,.42)!important;transform:translateX(-50%)!important}
 .dap-shell .dap-languages button{font-size:12px!important;min-height:42px!important}
 .dap-shell .dap-greeting{padding:23px 28px 10px!important}
}
@media(max-width:380px){
 .dap-shell .dap-nav{padding-left:3px!important;padding-right:3px!important}
 .dap-shell .dap-nav>button{font-size:18px!important}
 .dap-shell .dap-nav>button>span{font-size:8px!important}
 .dap-shell .dap-search{margin-left:18px!important;margin-right:18px!important}
 .dap-shell .dap-actions{padding-left:14px!important;padding-right:14px!important;gap:8px!important}
 .dap-shell .dap-title strong{font-size:18px!important}
}
.dap-shell .dap-agent-back{all:unset!important;box-sizing:border-box!important;width:36px!important;height:36px!important;flex:0 0 36px!important;border:1px solid var(--dap-line)!important;border-radius:12px!important;display:grid!important;place-items:center!important;font-size:28px!important;line-height:1!important;color:var(--dap-text)!important;background:var(--dap-bg)!important;cursor:pointer!important}
.dap-shell .dap-agent-avatar{width:38px!important;height:38px!important;flex:0 0 38px!important;border-radius:13px!important;display:grid!important;place-items:center!important;background:linear-gradient(145deg,var(--dap-accent-2),var(--dap-accent))!important;color:#fff!important;font-weight:900!important;box-shadow:0 7px 18px rgba(0,0,0,.12)!important}
.dap-shell .dap-agent-thread-title{min-width:0!important;display:flex!important;flex-direction:column!important;gap:3px!important}
.dap-shell .dap-agent-thread-title strong{font-size:14px!important;line-height:1.2!important;color:var(--dap-text)!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
.dap-shell .dap-agent-thread-title>span{display:flex!important;align-items:center!important;gap:6px!important;font-size:10px!important;line-height:1.2!important;color:var(--dap-muted)!important}
.dap-shell .dap-agent-thread-title i{width:7px!important;height:7px!important;border-radius:50%!important;background:#23bd69!important;box-shadow:0 0 0 3px rgba(35,189,105,.13)!important}
.dap-shell .dap-agent-empty{margin:auto!important;padding:30px 26px!important;max-width:310px!important;text-align:center!important;color:var(--dap-muted)!important}
.dap-shell .dap-agent-empty>span{display:grid!important;place-items:center!important;width:62px!important;height:62px!important;margin:0 auto 14px!important;border-radius:22px!important;background:var(--dap-surface)!important;border:1px solid var(--dap-line)!important;font-size:28px!important}
.dap-shell .dap-agent-empty strong{display:block!important;margin-bottom:8px!important;font-size:16px!important;color:var(--dap-text)!important}
.dap-shell .dap-agent-empty p{margin:0!important;font-size:12px!important;line-height:1.55!important}
.dap-shell.dap-agent-view .dap-languages,.dap-shell.dap-agent-view .dap-nav{display:none!important}
.dap-shell.dap-agent-view .dap-stage{min-height:0!important}
.dap-shell.dap-agent-view .dap-msg.customer{margin-left:auto!important;border-bottom-right-radius:6px!important}
.dap-shell.dap-agent-view .dap-msg.agent{margin-right:auto!important;border-bottom-left-radius:6px!important}
.dap-shell.dap-agent-view .dap-msg.dap-history-hidden,.dap-shell.dap-agent-view .dap-card.dap-history-hidden{display:none!important}
.dap-shell.dap-agent-view .dap-msg.dap-group-middle{border-radius:16px!important}
.dap-shell.dap-agent-view .dap-msg.customer.dap-group-middle,.dap-shell.dap-agent-view .dap-msg.customer.dap-group-last{border-top-right-radius:7px!important}
.dap-shell.dap-agent-view .dap-msg.agent.dap-group-middle,.dap-shell.dap-agent-view .dap-msg.agent.dap-group-last{border-top-left-radius:7px!important}
.dap-shell.dap-agent-view .dap-msg time{display:block!important;margin-top:4px!important;font-size:8px!important;line-height:1!important;opacity:.58!important;text-align:right!important}
.dap-shell.dap-agent-view .dap-composer-wrap{box-shadow:0 -8px 24px rgba(15,23,42,.05)!important}
.dap-shell.dap-agent-view .dap-chat-tools{padding:5px 10px 0!important}
.dap-shell.dap-agent-view .dap-chat-tools button{font-size:10px!important;min-height:30px!important;padding:5px 10px!important}
@media(max-width:520px){.dap-shell .dap-agent-thread-head{padding:10px 12px!important}.dap-shell.dap-agent-view .dap-header{min-height:82px!important;padding-top:18px!important;padding-bottom:13px!important}.dap-shell.dap-agent-view .dap-composer{padding-left:9px!important;padding-right:9px!important}.dap-shell.dap-agent-view .dap-chat-tools{padding-top:4px!important}}
/* v5.0.2 mobile viewport repair — kept inline with markup to survive cache mismatch. */
.dap-shell{--dap-vv-height:100dvh;--dap-vv-top:0px}
@media(max-width:520px){
 .dap-shell.dap-floating .dap-panel{position:fixed!important;left:0!important;right:0!important;top:var(--dap-vv-top,0px)!important;bottom:auto!important;width:100vw!important;max-width:100vw!important;height:var(--dap-vv-height,100dvh)!important;max-height:var(--dap-vv-height,100dvh)!important;min-height:0!important;border-radius:0!important;border:0!important}
 .dap-shell.dap-shell .dap-header{min-height:78px!important;padding:12px 11px!important;gap:10px!important;border-radius:0!important}
 .dap-shell .dap-handle{display:none!important}
 .dap-shell.dap-shell .dap-logo{width:44px!important;height:44px!important;flex-basis:44px!important;border-radius:14px!important;font-size:21px!important}
 .dap-shell.dap-shell .dap-title strong{font-size:17px!important}
 .dap-shell.dap-shell .dap-title>span{font-size:10px!important;line-height:1.25!important}
 .dap-shell .dap-hours{font-size:8px!important}
 .dap-shell .dap-theme-toggle,.dap-shell .dap-sound-toggle,.dap-shell .dap-close{width:36px!important;height:36px!important;flex-basis:36px!important;border-radius:12px!important;margin:0!important}
 .dap-shell .dap-stage{flex:1 1 0!important;min-height:0!important}
 .dap-shell.dap-shell .dap-agent-thread-head{position:relative!important;top:auto!important;min-height:54px!important;padding:7px 10px!important}
 .dap-shell.dap-shell.dap-bot-view .dap-messages,.dap-shell.dap-shell.dap-agent-view .dap-messages{padding:9px 9px 12px!important;gap:6px!important}
 .dap-shell.dap-shell .dap-nav{min-height:56px!important;padding:3px 4px calc(3px + var(--dap-safe-bottom))!important}
 .dap-shell.dap-shell .dap-nav>button{height:46px!important;min-height:46px!important}
 .dap-shell.dap-shell .dap-composer{gap:6px!important;padding:7px 8px 3px!important}
 .dap-shell.dap-shell .dap-composer textarea{min-height:40px!important;max-height:88px!important;padding:9px 10px!important;font-size:16px!important}
 .dap-shell.dap-shell .dap-composer .dap-attach{width:36px!important;height:36px!important;flex-basis:36px!important}
 .dap-shell.dap-shell .dap-send{width:40px!important;height:40px!important;flex-basis:40px!important}
 .dap-shell.dap-keyboard-open .dap-nav,.dap-shell.dap-keyboard-open .dap-secure,.dap-shell.dap-keyboard-open .dap-chat-tools{display:none!important}
}
@media(max-width:520px){
 .dap-shell .dap-launcher{min-width:104px!important;gap:4px!important}
 .dap-shell .dap-launcher-orb{width:52px!important;height:52px!important}
 .dap-shell .dap-launcher-orb svg{width:28px!important;height:28px!important}
 .dap-shell .dap-live-dot{width:12px!important;height:12px!important;border-width:2px!important}
 .dap-shell .dap-unread{min-width:20px!important;height:20px!important;font-size:10px!important;left:-6px!important;top:-6px!important}
 .dap-shell .dap-launcher-copy{min-width:104px!important;padding:5px 9px 6px!important;border-radius:13px!important}
 .dap-shell .dap-launcher-copy small{display:none!important}
 .dap-shell .dap-launcher-copy strong{font-size:10px!important}
}
@media(max-width:370px){
 .dap-shell .dap-launcher-copy{min-width:96px!important}
 .dap-shell .dap-launcher{min-width:96px!important}
}
/* v5.1.1 — collision-safe launcher, pulse animation and timed help popup. */
.dap-shell .dap-launcher{width:64px!important;min-width:64px!important;height:64px!important;gap:0!important;overflow:visible!important}
.dap-shell .dap-launcher-orb::before{content:""!important;position:absolute!important;inset:-8px!important;border:2px solid rgba(119,92,255,.38)!important;border-radius:50%!important;pointer-events:none!important;animation:dap-launcher-ring 2.8s cubic-bezier(.2,.65,.35,1) infinite!important}
.dap-shell .dap-launcher-copy{position:absolute!important;right:74px!important;left:auto!important;bottom:3px!important;min-width:142px!important;max-width:min(190px,calc(100vw - 102px))!important;padding:7px 12px 8px!important;opacity:0!important;visibility:hidden!important;pointer-events:none!important;transform:translate3d(10px,0,0) scale(.96)!important;transform-origin:right center!important;transition:opacity .22s ease,transform .26s cubic-bezier(.2,.8,.2,1),visibility .22s!important}
.dap-shell .dap-launcher-copy::after{content:""!important;position:absolute!important;right:-6px!important;top:50%!important;width:12px!important;height:12px!important;background:rgba(255,255,255,.97)!important;border-top:1px solid rgba(31,40,103,.08)!important;border-right:1px solid rgba(31,40,103,.08)!important;transform:translateY(-50%) rotate(45deg)!important}
.dap-shell .dap-launcher.is-nudge .dap-launcher-copy{opacity:1!important;visibility:visible!important;pointer-events:auto!important;transform:translate3d(0,0,0) scale(1)!important}
.dap-shell.dap-launcher-left .dap-launcher-copy{left:74px!important;right:auto!important;transform:translate3d(-10px,0,0) scale(.96)!important;transform-origin:left center!important}
.dap-shell.dap-launcher-left .dap-launcher-copy::after{left:-6px!important;right:auto!important;border:0!important;border-bottom:1px solid rgba(31,40,103,.08)!important;border-left:1px solid rgba(31,40,103,.08)!important}
.dap-shell.dap-launcher-left .dap-launcher.is-nudge .dap-launcher-copy{transform:translate3d(0,0,0) scale(1)!important}
@keyframes dap-launcher-ring{0%{opacity:.62;transform:scale(.82)}72%,100%{opacity:0;transform:scale(1.22)}}
@media(max-width:520px){
 .dap-shell .dap-launcher{width:52px!important;min-width:52px!important;height:52px!important}
 .dap-shell .dap-launcher-copy{right:60px!important;bottom:0!important;min-width:104px!important;max-width:calc(100vw - 82px)!important}
 .dap-shell.dap-launcher-left .dap-launcher-copy{left:60px!important;right:auto!important}
}
@media(prefers-reduced-motion:reduce){.dap-shell .dap-launcher-orb::before{animation:none!important}.dap-shell .dap-launcher-copy{transition:none!important}}
.dap-shell [hidden],.dap-shell [hidden][hidden]{display:none!important}
/* v5.2.0 — product-aware support, real availability state and accessibility. */
.dap-shell{font-size:calc(16px * var(--dap-font-scale,1))!important}
@keyframes dap-unread-pop{0%,100%{transform:scale(1)}45%{transform:scale(1.24)}}
.dap-shell .dap-launcher,.dap-shell .dap-close,.dap-shell .dap-theme-toggle,.dap-shell .dap-send,.dap-shell .dap-attach{min-width:44px;min-height:44px}
@media(max-width:520px){.dap-shell .dap-page-context{grid-template-columns:38px minmax(0,1fr)!important;margin:0 10px 10px!important;padding:9px!important}.dap-shell .dap-page-context-icon{width:38px!important;height:38px!important}.dap-shell .dap-page-context button{grid-column:1/-1!important;width:100%!important;max-width:none!important;min-height:36px!important}.dap-shell .dap-agent-eta{font-size:8px!important}}
@media(prefers-reduced-motion:reduce){.dap-shell *,.dap-shell *::before,.dap-shell *::after{animation-duration:.001ms!important;animation-iteration-count:1!important;transition-duration:.001ms!important;scroll-behavior:auto!important}}
/* v5.4.0 — conversation UI rebuild + mobile usability hardening.
 * Final high-specificity layer intentionally comes last so old cached/theme rules cannot
 * stack the back button, avatar and title or stretch action buttons unpredictably. */
@media(max-width:520px){
 .dap-shell.dap-shell .dap-panel{width:100vw!important;max-width:100vw!important;height:var(--dap-vv-height,100dvh)!important;max-height:var(--dap-vv-height,100dvh)!important;border-radius:0!important}
 .dap-shell.dap-shell .dap-header{min-height:74px!important;padding:10px 10px!important;gap:9px!important}
 .dap-shell.dap-shell .dap-logo{width:42px!important;height:42px!important;min-width:42px!important;min-height:42px!important;flex-basis:42px!important;border-radius:13px!important}
 .dap-shell.dap-shell .dap-title{gap:1px!important;overflow:hidden!important}
 .dap-shell.dap-shell .dap-title>strong{font-size:16px!important;line-height:1.18!important}
 .dap-shell.dap-shell .dap-title>span{font-size:9px!important;line-height:1.2!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
 .dap-shell.dap-shell .dap-hours,.dap-shell.dap-shell .dap-agent-eta{font-size:8px!important;line-height:1.15!important;white-space:nowrap!important;overflow:hidden!important;text-overflow:ellipsis!important}
 .dap-shell.dap-shell .dap-theme-toggle,.dap-shell.dap-shell .dap-close{width:40px!important;height:40px!important;min-width:40px!important;min-height:40px!important;flex-basis:40px!important;border-radius:13px!important}
 .dap-shell.dap-shell .dap-agent-thread-head{grid-template-columns:44px 38px minmax(0,1fr) auto!important;min-height:58px!important;padding:7px 10px!important;column-gap:8px!important}
 .dap-shell.dap-shell .dap-agent-avatar{width:38px!important;height:38px!important;min-width:38px!important;min-height:38px!important;border-radius:12px!important;font-size:16px!important}
 .dap-shell.dap-shell .dap-agent-thread-title>strong{font-size:13px!important}
 .dap-shell.dap-shell .dap-agent-thread-title>span{font-size:9px!important}
 .dap-shell.dap-shell.dap-bot-view .dap-messages,.dap-shell.dap-shell.dap-agent-view .dap-messages{padding:10px 10px 14px!important;gap:7px!important;scroll-padding-bottom:16px!important}
 .dap-shell.dap-shell .dap-msg{max-width:88%!important;font-size:13px!important;line-height:1.45!important}
 .dap-shell.dap-shell .dap-card{padding:12px!important;border-radius:16px!important}
 .dap-shell.dap-shell .dap-card h4{font-size:14px!important}
 .dap-shell.dap-shell .dap-card p{font-size:12px!important}
 .dap-shell.dap-shell .dap-card .dap-steps li{font-size:12.5px!important;line-height:1.5!important}
 .dap-shell.dap-shell .dap-card-actions{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:7px!important}
 .dap-shell.dap-shell .dap-card-actions>a,.dap-shell.dap-shell .dap-card-actions>button{width:100%!important;min-width:0!important;min-height:44px!important;font-size:11px!important}
 .dap-shell.dap-shell .dap-card-actions>*:last-child:nth-child(odd){grid-column:1/-1!important}
 .dap-shell.dap-shell .dap-nav{min-height:58px!important;padding:4px 4px calc(4px + var(--dap-safe-bottom))!important}
 .dap-shell.dap-shell .dap-nav>button{height:48px!important;min-height:48px!important;font-size:17px!important}
 .dap-shell.dap-shell .dap-nav>button>span{font-size:8.5px!important;margin-top:3px!important}
 .dap-shell.dap-shell .dap-composer{gap:7px!important;padding:8px 9px 4px!important}
 .dap-shell.dap-shell .dap-composer textarea{min-height:44px!important;max-height:96px!important;padding:10px 11px!important;border-radius:14px!important;font-size:16px!important}
 .dap-shell.dap-shell .dap-composer .dap-attach{width:42px!important;height:42px!important;min-width:42px!important;min-height:42px!important;flex-basis:42px!important}
 .dap-shell.dap-shell .dap-send{width:44px!important;height:44px!important;min-width:44px!important;min-height:44px!important;flex-basis:44px!important;border-radius:14px!important}
 .dap-shell.dap-shell .dap-secure{padding:3px 8px calc(5px + var(--dap-safe-bottom))!important;font-size:8.5px!important}
 .dap-shell.dap-keyboard-open .dap-agent-thread-head{min-height:52px!important}
}
@media(max-width:360px){
 .dap-shell.dap-shell .dap-header{gap:7px!important;padding-left:8px!important;padding-right:8px!important}
 .dap-shell.dap-shell .dap-title>strong{font-size:15px!important}
 .dap-shell.dap-shell .dap-agent-thread-head{grid-template-columns:42px 36px minmax(0,1fr)!important}
 .dap-shell.dap-shell .dap-thread-count{display:none!important}
 .dap-shell.dap-shell .dap-agent-avatar{width:36px!important;height:36px!important;min-width:36px!important;min-height:36px!important}
 .dap-shell.dap-shell .dap-card-actions{grid-template-columns:1fr!important}
 .dap-shell.dap-shell .dap-card-actions>*{grid-column:1!important}
}
/* Persistent composer: reserve real flex space in every assistant section. */
.dap-shell.dap-shell.dap-home-view .dap-composer-wrap{border-top:1px solid var(--dap-line)!important}
@media(max-width:520px){.dap-shell.dap-shell .dap-nav{padding-bottom:4px!important}.dap-shell.dap-shell .dap-secure{padding-bottom:calc(6px + var(--dap-safe-bottom))!important}}

DAPCSS;
    }

    /**
     * Printed once per page, immediately before the widget. data-no-optimize / data-noptimize /
     * data-cfasync keep LiteSpeed, Autoptimize and Rocket Loader from moving or merging it.
     */
    private static function critical_style_tag() {
        static $printed = false;
        if ( $printed ) return '';
        $printed = true;
        return '<style id="dap-critical" data-no-optimize="1" data-noptimize="1" data-cfasync="false">' . self::critical_css() . self::placement_css() . '</style>';
    }

    /**
     * Where the floating button sits. Stores commonly run a second floating widget in the
     * same corner — on the Delicat wallet page the launcher lands directly under an
     * "Ask AI" pill — so the side and the height above the bottom edge are settings.
     * Defaults reproduce the previous fixed position exactly.
     */
    private static function launcher_style_tag() {
        static $printed = false;
        if ( $printed ) return '';
        $printed = true;
        $css = '.dap-lazy-shell{font-family:Inter,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif!important;position:fixed!important;z-index:2147483000!important;right:18px!important;left:auto!important;bottom:calc(var(--dap-launcher-base-bottom,16px) + var(--delicat-dock-height,0px) + env(safe-area-inset-bottom,0px))!important;width:auto!important;height:auto!important;pointer-events:none!important;isolation:isolate!important}'
             . '.dap-lazy-shell.dap-launcher-left{left:18px!important;right:auto!important}.dap-lazy-shell *{box-sizing:border-box!important}.dap-lazy-shell [hidden]{display:none!important}'
             . '.dap-lazy-shell .dap-launcher{all:unset!important;pointer-events:auto!important;display:flex!important;flex-direction:column!important;align-items:center!important;gap:7px!important;min-width:136px!important;position:relative!important;color:#fff!important;cursor:pointer!important;-webkit-tap-highlight-color:transparent!important}'
             . '.dap-lazy-shell .dap-launcher-orb{position:relative!important;width:64px!important;height:64px!important;border-radius:50%!important;background:linear-gradient(145deg,#775cff,#4d2fd2)!important;border:3px solid rgba(255,255,255,.96)!important;box-shadow:0 14px 34px rgba(76,47,210,.34),0 4px 12px rgba(16,22,95,.18)!important;display:grid!important;place-items:center!important;transition:transform .18s ease!important}'
             . '.dap-lazy-shell .dap-launcher-orb svg{width:37px!important;height:37px!important;display:block!important}.dap-lazy-shell .dap-launcher:active .dap-launcher-orb{transform:scale(.95)!important}'
             . '.dap-lazy-shell .dap-live-dot{position:absolute!important;right:-1px!important;top:-1px!important;width:15px!important;height:15px!important;background:#ff2455!important;border:3px solid #fff!important;border-radius:999px!important}'
             . '.dap-lazy-shell .dap-launcher-copy{min-width:136px!important;padding:6px 11px 7px!important;border-radius:15px!important;background:rgba(255,255,255,.97)!important;border:1px solid rgba(31,40,103,.08)!important;box-shadow:0 8px 24px rgba(16,22,95,.16)!important;color:#171c50!important;text-align:center!important;display:flex!important;flex-direction:column!important;line-height:1.12!important;white-space:nowrap!important}'
             . '.dap-lazy-shell .dap-launcher-copy small{font-size:9px!important;font-weight:700!important;color:#7b8198!important}.dap-lazy-shell .dap-launcher-copy strong{font-size:11.5px!important;font-weight:850!important;color:#171c50!important}'
             . '.dap-lazy-shell .dap-unread{position:absolute!important;left:-8px!important;top:-8px!important;min-width:24px!important;height:24px!important;padding:0 6px!important;border-radius:999px!important;background:#d92f61!important;color:#fff!important;border:2px solid #fff!important;display:grid!important;place-items:center!important;font:800 11px/1 Arial,sans-serif!important;z-index:2!important}'
             . '.dap-lazy-shell.dap-booting .dap-launcher-orb{opacity:.78!important}@media(max-width:520px){.dap-lazy-shell{right:10px!important;bottom:calc(min(var(--dap-launcher-base-bottom,16px),8px) + max(var(--delicat-dock-height,0px),82px) + env(safe-area-inset-bottom,0px))!important}.dap-lazy-shell.dap-launcher-left{left:10px!important;right:auto!important}.dap-lazy-shell .dap-launcher{min-width:0!important;gap:0!important;bottom:auto!important;right:auto!important;left:auto!important}.dap-lazy-shell .dap-launcher-orb{width:46px!important;height:46px!important;border-width:2px!important}.dap-lazy-shell .dap-launcher-orb svg{width:24px!important;height:24px!important}.dap-lazy-shell .dap-live-dot{width:11px!important;height:11px!important;border-width:2px!important}.dap-lazy-shell .dap-launcher-copy{display:none!important}.dap-lazy-shell .dap-unread{min-width:20px!important;height:20px!important;left:-6px!important;top:-6px!important;font-size:10px!important}}'
             /* v6.2.1 — while the page scrolls the orb slides off the edge (see boot_tag). */
             . '.dap-lazy-shell .dap-launcher{transition:transform .22s cubic-bezier(.2,.7,.2,1),opacity .18s ease!important}.dap-lazy-shell.dap-launcher-away .dap-launcher{transform:translate3d(calc(100% + 28px),0,0)!important;opacity:0!important;pointer-events:none!important}.dap-lazy-shell.dap-launcher-left.dap-launcher-away .dap-launcher{transform:translate3d(calc(-100% - 28px),0,0)!important}@media(prefers-reduced-motion:reduce){.dap-lazy-shell .dap-launcher{transition:none!important}}';
        return '<style id="dap-launcher-critical" data-no-optimize="1" data-noptimize="1" data-cfasync="false">' . $css . '</style>';
    }

    private static function placement_css() {
        $s = get_option( 'dap_settings', array() );
        $left   = isset( $s['launcher_side'] ) && 'left' === $s['launcher_side'];
        $bottom = isset( $s['launcher_bottom'] ) ? max( 0, min( 400, (int) $s['launcher_bottom'] ) ) : 16;
        $css = '.dap-shell.dap-floating{--dap-launcher-base-bottom:' . $bottom . 'px;bottom:calc(var(--dap-launcher-base-bottom) + var(--delicat-dock-height,0px))!important}';
        if ( $left ) {
            $css .= '.dap-shell.dap-floating{left:18px!important;right:auto!important}';
            $css .= '.dap-shell.dap-floating .dap-panel{left:0!important;right:auto!important}';
        }
        // v6.2.1 — measured on delicastoreha.com at 375×812: these two launcher rules also
        // matched the pre-runtime shell, whose launcher is position:relative inside a root
        // that already carries the same bottom offset. The orb ended up lifted twice
        // (y 566–618, 116px above the dock, in the middle of the product cards) until
        // the runtime replaced the shell. Only the hydrated shell gets them now.
        $css .= '.dap-shell.dap-floating:not(.dap-lazy-shell) .dap-launcher{bottom:calc(var(--dap-launcher-base-bottom) + var(--delicat-dock-height,0px) + var(--dap-safe-bottom))!important}';
        $css .= '@media(max-width:520px){';
        if ( $left ) $css .= '.dap-shell.dap-floating{left:14px!important;right:auto!important}';
        $css .= '.dap-shell.dap-floating:not(.dap-lazy-shell) .dap-launcher{'
             . ( $left ? 'left:10px!important;right:auto!important;' : 'right:10px!important;' )
             // v6.2.1: 8px above the dock on phones — every pixel the orb climbs is content it covers.
             . 'bottom:calc(min(var(--dap-launcher-base-bottom),8px) + max(var(--delicat-dock-height,0px),var(--dap-auto-dock-height,0px),82px) + var(--dap-safe-bottom))!important}';
        $css .= '}';
        return $css;
    }

    /** Delicat bot mark shared by the web launcher, header and Android UI tokens. */
    public static function bot_icon() {
        return '<svg viewBox="0 0 64 64" aria-hidden="true" focusable="false"><path fill="#fff" d="M27 11.5a5 5 0 0 1 10 0v2.7c7.7 2.1 13.3 9.1 13.3 17.4v10.7c0 6.1-4.9 11-11 11H24.7c-6.1 0-11-4.9-11-11V31.6c0-8.3 5.6-15.3 13.3-17.4v-2.7Zm-4.9 20.4a4.5 4.5 0 1 0 9 0 4.5 4.5 0 0 0-9 0Zm15.8 0a4.5 4.5 0 1 0 9 0 4.5 4.5 0 0 0-9 0ZM24 42.5c2.1 2.2 4.8 3.3 8 3.3s5.9-1.1 8-3.3a2 2 0 1 0-2.9-2.7c-1.3 1.3-3 2-5.1 2s-3.8-.7-5.1-2a2 2 0 1 0-2.9 2.7Z"/><path fill="#cbbfff" d="M32 6.5a2.7 2.7 0 1 1 0 5.4 2.7 2.7 0 0 1 0-5.4Z"/></svg>';
    }

    /** Official WhatsApp glyph (Font Awesome path, brand-safe inline SVG). */
    private static function wa_icon() {
        return '<svg viewBox="0 0 448 512" aria-hidden="true" focusable="false"><path d="M380.9 97.1C339 55.1 283.2 32 223.9 32c-122.4 0-222 99.6-222 222 0 39.1 10.2 77.3 29.6 111L0 480l117.7-30.9c32.4 17.7 68.9 27 106.1 27h.1c122.3 0 224.1-99.6 224.1-222 0-59.3-25.2-115-67.1-157zm-157 341.6c-33.2 0-65.7-8.9-94-25.7l-6.7-4-69.8 18.3L72 359.2l-4.4-7c-18.5-29.4-28.2-63.3-28.2-98.2 0-101.7 82.8-184.5 184.6-184.5 49.3 0 95.6 19.2 130.4 54.1 34.8 34.9 56.2 81.2 56.1 130.5 0 101.8-84.9 184.6-186.6 184.6zm101.2-138.2c-5.5-2.8-32.8-16.2-37.9-18-5.1-1.9-8.8-2.8-12.5 2.8-3.7 5.6-14.3 18-17.6 21.8-3.2 3.7-6.5 4.2-12 1.4-32.6-16.3-54-29.1-75.5-66-5.7-9.8 5.7-9.1 16.3-30.3 1.8-3.7.9-6.9-.5-9.7-1.4-2.8-12.5-30.1-17.1-41.2-4.5-10.8-9.1-9.3-12.5-9.5-3.2-.2-6.9-.2-10.6-.2-3.7 0-9.7 1.4-14.8 6.9-5.1 5.6-19.4 19-19.4 46.3 0 27.3 19.9 53.7 22.6 57.4 2.8 3.7 39.1 59.7 94.8 83.8 35.2 15.2 49 16.5 66.6 13.9 10.7-1.6 32.8-13.4 37.4-26.4 4.6-13 4.6-24.1 3.2-26.4-1.3-2.5-5-3.9-10.5-6.6z"/></svg>';
    }
    /**
     * The boot loader: about 1.5 KB inline, printed in the same document as the markup.
     *
     * It owns three things and nothing else — the launcher tap, the unread badge, and
     * deciding when to fetch the real runtime. That means a visitor who never opens the
     * chat downloads and parses no stylesheet and no script for it at all, instead of
     * the full runtime the widget used to cost every page, on every page of the store.
     *
     * Written in ES5 on purpose: this is the gate. If it fails, the chat is unreachable,
     * so it must run on the oldest Android WebView that reaches the store.
     */
    private static function boot_tag( $floating ) {
        static $printed = false;
        if ( $printed ) return '';
        $printed = true;
        $config = wp_json_encode( self::config( $floating ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE );
        $boot = <<<'DAPBOOT'
(function(w,d){
var D=w.DSA2_DATA||{},loading=false,retried=false,hydrating=false;
w.DSA2_BOOT={open:false,unread:-1};
function pick(s){return d.querySelector(s)}
function closest(n,s){while(n&&n.nodeType===1){if(n.matches&&n.matches(s))return n;n=n.parentElement}return null}
function enc(v){return encodeURIComponent(v==null?'':String(v))}
function shellBody(){var p=D.page||{};return 'action='+enc(D.shellAction||'dsa2_lazy_shell')+'&nonce='+enc(D.nonce||'')+'&page_type='+enc(p.page_type||'page')+'&product_id='+enc(p.product_id||0)+'&title='+enc(p.title||'')+'&url='+enc(p.url||'')+'&guide='+enc(p.guide||'')+'&lang='+enc(p.lang||'')}
function refreshNonce(done){
 if(!w.fetch){done(false);return}
 try{w.fetch(D.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:'action=dsa2_get_nonce'}).then(function(x){return x.json()}).then(function(j){var n=j&&j.success&&j.data&&j.data.nonce?String(j.data.nonce):'';if(n){D.nonce=n;w.DSA2_DATA.nonce=n;done(true)}else done(false)})['catch'](function(){done(false)})}catch(e){done(false)}
}
function hydrate(done,retriedNonce){
 var r=pick('[data-dap-root]');
 if(!r||r.getAttribute('data-dap-lazy')!=='1'){done(true);return}
 if(hydrating){setTimeout(function(){hydrate(done,retriedNonce)},80);return}hydrating=true;
 if(!w.fetch){hydrating=false;done(false);return}
 try{w.fetch(D.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:shellBody()})
 .then(function(x){return x.json()}).then(function(j){hydrating=false;if(j&&j.success&&j.data&&j.data.html){r.outerHTML=j.data.html;done(true);return}if(!retriedNonce&&j&&j.data&&j.data.code==='nonce_expired'){refreshNonce(function(ok){if(ok)hydrate(done,true);else done(false)});return}done(false)})['catch'](function(){hydrating=false;done(false)})}catch(e){hydrating=false;done(false)}
}
function inject(bust){
 if(D.css&&!d.querySelector('link[data-dap-css="boot"]')){var l=d.createElement('link');l.rel='stylesheet';
  l.href=D.css+(bust?(D.css.indexOf('?')>-1?'&':'?')+'dapr='+(+new Date()):'');
  l.setAttribute('data-dap-css','boot');l.setAttribute('data-no-optimize','1');l.setAttribute('data-noptimize','1');l.setAttribute('data-cfasync','false');d.head.appendChild(l)}
 if(!D.js){loading=false;return}
 var s=d.createElement('script');s.src=D.js+(bust?(D.js.indexOf('?')>-1?'&':'?')+'dapr='+(+new Date()):'');s.async=true;
 s.setAttribute('data-no-optimize','1');s.setAttribute('data-noptimize','1');s.setAttribute('data-cfasync','false');
 s.onerror=function(){if(!retried){retried=true;inject(true)}else{loading=false}};d.head.appendChild(s)
}
function start(open){
 if(open){w.DSA2_BOOT.open=true;var r=pick('[data-dap-root]');if(r&&r.className.indexOf('dap-booting')<0){r.className+=' dap-booting';setTimeout(function(){var x=pick('[data-dap-root]');if(x)x.className=x.className.replace(/ ?dap-booting/,'')},10000)}if(w.DSA2_OPEN){w.DSA2_OPEN();return}}
 if(loading)return;loading=true;hydrate(function(ok){if(!ok){loading=false;return}inject(false)})
}
w.DSA2_START=start;
/* v6.2.0 — the launcher used to open on pointerdown. On a phone the orb sits under the
   thumb in the scroll zone, so every flick that began on it opened the assistant. A
   pointerdown now only warms the runtime; the panel opens on a real tap: finger down and
   up in the same place (<12px) and under 700ms. A scroll never satisfies both. */
var gx=0,gy=0,gt=0,gmoved=false,gtracking=false;
function onDown(e){
 if(!closest(e.target,'.dap-launcher,[data-dap-open]')){gtracking=false;return}
 var p=(e.touches&&e.touches[0])||e;
 gx=p.clientX||0;gy=p.clientY||0;gt=+new Date();gmoved=false;gtracking=true;
 start(false); // fetch the runtime now so the tap that follows is instant
}
function onMove(e){
 if(!gtracking||gmoved)return;
 var p=(e.touches&&e.touches[0])||e;
 if(Math.abs((p.clientX||0)-gx)>12||Math.abs((p.clientY||0)-gy)>12)gmoved=true;
}
function onUp(){gtracking=false}
function onClick(e){
 if(!closest(e.target,'.dap-launcher,[data-dap-open]'))return;
 // gt===0 is a keyboard or assistive activation: no pointer gesture preceded it, so allow it.
 if(gt&&(gmoved||(+new Date())-gt>700)){gmoved=false;gt=0;return}
 gmoved=false;gt=0;start(true);
}
d.addEventListener('pointerdown',onDown,true);d.addEventListener('pointermove',onMove,true);
d.addEventListener('pointerup',onUp,true);d.addEventListener('pointercancel',onUp,true);
d.addEventListener('touchstart',onDown,true);d.addEventListener('touchmove',onMove,true);
d.addEventListener('touchend',onUp,true);d.addEventListener('touchcancel',onUp,true);
d.addEventListener('click',onClick,true);
/* v6.2.1 — the orb steps aside while the page scrolls, even before the runtime exists.
   The runtime takes this over (DSA2_LAUNCHER_SCROLL) once it has loaded. */
var away=false,settle=0,lastY=w.pageYOffset||0,phone=w.matchMedia?w.matchMedia('(max-width:520px)'):{matches:false};
function setAway(on){var r=pick('[data-dap-root]');if(!r||on===away)return;away=on;var c=r.className.replace(/ ?dap-launcher-away/g,'');r.className=on?c+' dap-launcher-away':c}
function onScroll(){if(w.DSA2_LAUNCHER_SCROLL||!D.floating||w.DSA2_BOOT.open)return;if(!phone.matches){if(away)setAway(false);return}var y=w.pageYOffset||0,delta=Math.abs(y-lastY);lastY=y;if(delta<2)return;setAway(true);clearTimeout(settle);settle=setTimeout(function(){setAway(false)},650)}
w.addEventListener('scroll',onScroll,{passive:true});
function unread(){if(!w.fetch||!(D.loggedIn||/(^|; *)dap_has_chat=/.test(d.cookie)))return;try{w.fetch(D.ajax,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:'action=dsa2_unread&nonce='+enc(D.nonce||'')}).then(function(r){return r.json()}).then(function(j){if(!j||!j.success)return;var n=+((j.data||{}).unread_count)||0;w.DSA2_BOOT.unread=n;var b=pick('.dap-unread');if(b&&n>0){b.hidden=false;b.textContent=n>9?'9+':String(n)}})['catch'](function(){})}catch(e){}}
if(w.requestIdleCallback)w.requestIdleCallback(unread,{timeout:4500});else setTimeout(unread,3200);
if(!D.floating)start(false);if(D.settings&&D.settings.auto_open)start(false);if(D.page&&D.page.guide)start(false);
if(D.prefetch&&!loading){var c=navigator.connection||{},thin=c.saveData||/(^|-)2g$/.test(c.effectiveType||'')||(navigator.deviceMemory||4)<2;if(!thin){if(w.requestIdleCallback)w.requestIdleCallback(function(){start(false)},{timeout:5500});else setTimeout(function(){start(false)},3000)}}
})(window,document);
DAPBOOT;
        return '<script id="dap-boot" data-no-optimize="1" data-noptimize="1" data-cfasync="false">window.DSA2_DATA=' . $config . ';' . $boot . '</script>';
    }

    private static function markup($floating=true) {
        $settings = wp_parse_args(get_option('dap_settings',array()), array('brand_name'=>'Delicat Assistant','agent_status'=>'online','primary_color'=>'#ff5a2b','business_hours'=>'','whatsapp'=>'','show_whatsapp'=>1,'launcher_label_enabled'=>1,'launcher_kicker'=>'Besoin d’aide ?','launcher_message'=>'Discutez avec nous !'));
        $status = DSA2_Data::effective_status(); // manual status, downgraded outside opening hours
        $status_text = $status === 'online' ? 'Un agent est en ligne' : ($status === 'away' ? 'Un agent répondra bientôt' : 'Laissez-nous un message');
        $accent = sanitize_hex_color($settings['primary_color']) ?: '#ff5a2b';
        // wp_parse_args only fills keys that are absent. Saving the settings form with the name
        // field emptied stored "" — a present-but-blank value — and the header lost its title.
        $brand = trim( (string) $settings['brand_name'] );
        if ( '' === $brand ) $brand = 'Delicat Assistant';
        $whatsapp_number = preg_replace( '/\D+/', '', (string) $settings['whatsapp'] );
        $show_whatsapp = ! empty( $settings['show_whatsapp'] ) && '' !== $whatsapp_number;
        $page_context = self::page_context();
        $font_scale = isset($settings['font_scale']) ? max(90,min(125,(int)$settings['font_scale'])) : 100;
        $reduced_motion = !empty($settings['force_reduced_motion']);
        ob_start();
        if ( ! self::$fragment_rendering ) {
            echo self::critical_style_tag(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            if ( 'eager' !== self::runtime_mode() ) echo self::boot_tag( $floating ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        }
        ?>
        <div class="dap-shell <?php echo $floating?'dap-floating':'dap-embedded'; ?><?php echo ( $floating && isset( $settings['launcher_side'] ) && 'left' === $settings['launcher_side'] ) ? ' dap-launcher-left' : ''; ?><?php echo $reduced_motion?' dap-reduced-motion':''; ?>" data-dap-root data-page-type="<?php echo esc_attr($page_context['page_type']); ?>" style="--dap-accent:<?php echo esc_attr($accent); ?>;--dap-font-scale:<?php echo esc_attr($font_scale/100); ?>">
            <?php if($floating): ?><button class="dap-launcher" type="button" aria-label="Ouvrir Delicat Assistant — <?php echo esc_attr($settings['launcher_message']); ?>" aria-haspopup="dialog" aria-expanded="false"><span class="dap-launcher-orb"><?php echo self::bot_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><i class="dap-live-dot" data-status="<?php echo esc_attr($status); ?>" aria-hidden="true"></i><b class="dap-unread" data-agent-unread hidden>0</b></span><?php if(!empty($settings['launcher_label_enabled'])):?><span class="dap-launcher-copy" role="status" aria-live="polite"><small><?php echo esc_html($settings['launcher_kicker']); ?></small><strong><?php echo esc_html($settings['launcher_message']); ?></strong></span><?php endif;?></button><?php endif; ?>
            <section class="dap-panel" role="dialog" aria-modal="<?php echo $floating?'true':'false'; ?>" aria-label="Delicat Assistant" aria-hidden="<?php echo $floating?'true':'false'; ?>">
                <header class="dap-header"><span class="dap-handle" aria-hidden="true"></span>
                    <div class="dap-logo"><?php echo self::bot_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></div><div class="dap-title"><strong><?php echo esc_html($brand); ?></strong><span class="dap-agent-status" data-status="<?php echo esc_attr($status); ?>"><i></i><span class="dap-status-text"><?php echo esc_html($status_text); ?></span></span><?php if(!empty($settings['business_hours'])):?><small class="dap-hours">🕘 <?php echo esc_html($settings['business_hours']); ?></small><?php endif;?><small class="dap-agent-eta" data-agent-eta><?php echo esc_html(DSA2_Data::agent_eta($status)); ?></small></div>
                    <button class="dap-sound-toggle" type="button" aria-pressed="true" aria-label="Couper le son des notifications">🔔</button><button class="dap-theme-toggle" type="button" aria-label="Changer de thème">🌙</button><button class="dap-close" type="button" aria-label="Fermer">×</button>
                </header>
                <div class="dap-network-status" role="status" aria-live="polite" hidden><span aria-hidden="true">⚠️</span><span data-network-text>Connexion internet indisponible. Vos messages seront envoyés quand le réseau revient.</span></div>
                <div class="dap-languages" role="group" aria-label="Langue"><button type="button" data-lang="fr" class="is-active">🌐 Français</button><button type="button" data-lang="en">🌐 English</button><button type="button" data-lang="ht">🌐 Kreyòl</button></div>
                <div class="dap-stage">
                    <div class="dap-home">
                        <div class="dap-greeting"><small data-i18n="eyebrow">CENTRE D’AIDE DELICAT</small><h3 data-i18n="hello">Comment pouvons-nous vous aider ?</h3><p data-i18n="sub">Choisissez une réponse instantanée ou une vraie conversation avec notre équipe.</p></div>
                        <?php if ( !empty($settings['product_context_enabled']) && !empty($page_context['product']) ) : $pc=$page_context['product']; ?>
                        <section class="dap-page-context" aria-label="Aide sur le produit consulté"><span class="dap-page-context-icon">🛍️</span><div><small>Vous consultez</small><strong data-context-product><?php echo esc_html($pc['name']); ?></strong><em data-context-variation><?php echo esc_html($pc['price']); ?></em></div><button type="button" data-action="product_context" data-action-i18n="productQuestion">Question sur ce produit</button></section>
                        <?php endif; ?>
                        <section class="dap-channel-section" aria-labelledby="dap-channel-label">
                            <p class="dap-section-label" id="dap-channel-label" data-i18n="chooseChannel">Comment souhaitez-vous être aidé ?</p>
                            <div class="dap-channel-grid<?php echo $show_whatsapp ? ' has-whatsapp' : ''; ?>">
                                <button type="button" class="dap-channel-card dap-channel-bot" data-tab="bot"><span class="dap-channel-icon">✨</span><span><strong data-action-i18n="startBot">Réponse instantanée</strong><small data-i18n="botSubtitle">Automatique · disponible 24 h/24</small></span><i aria-hidden="true">›</i></button>
                                <button type="button" class="dap-channel-card dap-channel-agent" data-tab="agent"><span class="dap-channel-icon">💬</span><span><strong data-action-i18n="startChat">Parler à l’équipe</strong><small data-i18n="agentSubtitle">Un agent vous répond dans cette conversation</small></span><b class="dap-channel-badge" data-agent-unread hidden>0</b><i aria-hidden="true">›</i></button>
                                <?php if ( $show_whatsapp ) : ?><button type="button" class="dap-channel-card dap-channel-wa" data-action="whatsapp"><span class="dap-channel-icon"><?php echo self::wa_icon(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span><span><strong>WhatsApp</strong><small data-i18n="contactWhatsapp">Continuer sur WhatsApp</small></span><i aria-hidden="true">↗</i></button><?php endif; ?>
                            </div>
                        </section>
                        <form class="dap-search" role="search"><span aria-hidden="true">🔎</span><input type="search" maxlength="60" autocomplete="off" enterkeyhint="search" placeholder="Rechercher un produit..." aria-label="Rechercher un produit"><button type="submit" data-i18n="searchBtn">Chercher</button></form>
                        <p class="dap-section-label dap-tools-label" data-i18n="quickTools">Raccourcis utiles</p>
                        <div class="dap-actions">
                            <button type="button" data-action="balance"><span>💰</span><b data-action-i18n="balance">Mon solde</b></button><button type="button" data-action="order_center"><span>🧾</span><b data-action-i18n="orderHelp">Aide commande</b></button>
                            <button type="button" data-action="guide_recharge"><span>💳</span><b data-action-i18n="howRecharge">Comment recharger</b></button><button type="button" data-action="natcash"><span>⚡</span><b>Natcash Auto</b></button>
                            <button type="button" data-action="products"><span>🎮</span><b data-action-i18n="gamesProducts">Jeux & produits</b></button><button type="button" data-action="recommend"><span>⭐</span><b data-action-i18n="forYou">Pour vous</b></button>
                            <button type="button" data-action="faq"><span>❓</span><b data-action-i18n="faq">Questions fréquentes</b></button><button type="button" data-action="guides"><span>📚</span><b data-action-i18n="allGuides">Tous les guides</b></button>
                        </div>
                    </div>
                    <div class="dap-chat" hidden>
                        <div class="dap-agent-thread-head">
                            <button type="button" class="dap-agent-back" data-tab="home" aria-label="Retour">‹</button>
                            <div class="dap-agent-avatar" aria-hidden="true"><span data-thread-icon>✨</span></div>
                            <div class="dap-agent-thread-title"><strong data-i18n="conversationTitle">Assistant Delicat</strong><span><i></i><span data-i18n="conversationSubtitle">Réponses automatiques et support humain</span></span></div>
                            <span class="dap-thread-count" aria-live="polite" hidden>0</span>
                        </div>
                        <div class="dap-messages" aria-live="polite" aria-relevant="additions"></div>
                        <div class="dap-agent-empty" hidden><span>💬</span><strong data-i18n="emptyConversation">Aucune conversation avec un agent</strong><p data-i18n="emptyConversationSub">Envoyez un message ci-dessous. Un agent Delicat vous répondra ici.</p></div>
                        <button type="button" class="dap-jump-latest" aria-label="Voir les messages récents" hidden>↓</button>
                    </div>
                </div>
                <nav class="dap-nav <?php echo $show_whatsapp ? 'dap-nav-four' : 'dap-nav-three'; ?>" aria-label="Navigation assistant"><button type="button" class="is-active" data-tab="home">🏠<span data-nav-i18n="home">Accueil</span></button><button type="button" data-tab="bot">✨<span data-nav-i18n="bot">Assistant</span></button><button type="button" class="dap-nav-agent" data-tab="agent" aria-label="Équipe support">💬<span data-nav-i18n="agent">Équipe</span><b class="dap-nav-unread" data-agent-unread hidden>0</b></button><?php if ( $show_whatsapp ) : ?><button type="button" class="dap-nav-wa" data-action="whatsapp"><span class="dap-nav-icon" aria-hidden="true">📱</span><span>WhatsApp</span></button><?php endif; ?></nav>
                <div class="dap-composer-wrap" data-dap-persistent-composer><div class="dap-chat-tools" hidden><button type="button" data-end-chat>✅ <span data-i18n="endChat">Terminer la conversation</span></button></div><form class="dap-composer"><button type="button" class="dap-attach" aria-label="Envoyer une image">＋</button><input type="file" class="dap-file" accept="image/jpeg,image/png,image/gif,image/webp" hidden><textarea rows="1" maxlength="1500" enterkeyhint="send" autocomplete="off" placeholder="Écrivez votre message..." aria-label="Message"></textarea><button class="dap-send" type="submit" aria-label="Envoyer">➤</button></form><div class="dap-secure">🔒 <span data-i18n="secure">Sécurisé et protégé par Delicat Store</span> <b>✓</b></div></div>
            </section>
        </div><?php return ob_get_clean();
    }
}
