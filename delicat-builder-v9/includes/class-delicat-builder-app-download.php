<?php
/** Native Android distribution page. Downloads are explicit HTTPS links, never a PHP file proxy. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Delicat_Builder_V9_App_Download {
    const OPTION = 'delicat_builder_v9_android_app';
    const PAGE_OPTION = 'delicat_builder_v9_android_page';
    public static function boot(): void {
        add_action( 'init', static function () { add_shortcode( 'delicat_android_app', array( __CLASS__, 'render' ) ); } );
        add_action( 'admin_init', array( __CLASS__, 'admin_init' ) );
        add_action( 'update_option_' . self::OPTION, static function () {
            if ( self::page_url() ) { do_action( 'litespeed_purge_url', self::page_url() ); }
            do_action( 'delicat_android_release_updated', self::release() ); // pro.59: the Delicat App API and others listen
        } );
        add_action( 'admin_menu', static function () { add_submenu_page( 'delicat-builder-v9', 'Application mobile', 'Application mobile', 'manage_options', 'delicat-builder-app-download', array( __CLASS__, 'admin_page' ) ); }, 30 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ), 40 );
        add_filter( 'template_include', array( __CLASS__, 'template' ), PHP_INT_MAX );
        add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'menu_links' ) );
    }
    public static function settings(): array {
        $value = get_option( self::OPTION, array() );
        return wp_parse_args( is_array( $value ) ? $value : array(), array( 'url' => '', 'type' => 'apk', 'version' => '', 'size' => '', 'build' => 0, 'sha256' => '', 'notes' => '', 'min_build' => 0, 'package' => '' ) );
    }
    /** The host the app trusts for an APK: this site (or a sub-domain). */
    public static function same_host( string $url ): bool {
        $host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
        $site = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
        return '' !== $host && '' !== $site && ( $host === $site || substr( $host, -strlen( '.' . $site ) ) === '.' . $site );
    }
    /** pro.59: the published release as the Delicat App API hands it to the phones. */
    public static function release(): array {
        $s    = self::settings();
        $meta = get_option( 'delicat_builder_v9_android_release', array() );
        $meta = is_array( $meta ) ? $meta : array();
        $same = '' !== $s['url'] && isset( $meta['url'] ) && $meta['url'] === $s['url'];
        $sha  = '' !== $s['sha256'] ? $s['sha256'] : ( $same ? (string) ( $meta['sha256'] ?? '' ) : '' );
        return array(
            'type'      => 'play' === $s['type'] ? 'play' : 'apk',
            'version'   => (string) $s['version'],
            'build'     => (int) $s['build'] > 0 ? (int) $s['build'] : ( $same ? (int) ( $meta['build'] ?? 0 ) : 0 ),
            'url'       => (string) $s['url'],
            'sha256'    => 'play' === $s['type'] ? '' : $sha,
            'notes'     => (string) $s['notes'],
            'min_build' => (int) $s['min_build'],
            'size'      => $same ? (int) ( $meta['bytes'] ?? 0 ) : 0,
            'published' => $same ? (string) ( $meta['uploaded_at'] ?? '' ) : '',
            'package'   => '' !== $s['package'] ? (string) $s['package'] : ( $same ? (string) ( $meta['package'] ?? '' ) : '' ),
        );
    }
    public static function sanitize( $input ): array {
        $input = is_array( $input ) ? $input : array();
        $out = array();
        foreach ( array( 'url', 'type', 'version', 'size', 'sha256', 'notes', 'package' ) as $key ) {
            $out[$key] = isset( $input[$key] ) && is_string( $input[$key] ) ? trim( $input[$key] ) : '';
        }
        foreach ( array( 'build', 'min_build' ) as $key ) {
            $out[$key] = isset( $input[$key] ) && is_scalar( $input[$key] ) ? absint( $input[$key] ) : 0;
        }
        $out['sha256']  = preg_match( '/^[A-Fa-f0-9]{64}$/', $out['sha256'] ) ? strtolower( $out['sha256'] ) : '';
        $out['notes']   = sanitize_textarea_field( substr( $out['notes'], 0, 2000 ) );
        $out['package'] = substr( preg_replace( '/[^A-Za-z0-9_.]/', '', $out['package'] ), 0, 120 );
        $out['url'] = esc_url_raw( $out['url'], array( 'https' ) );
        if ( '' !== $out['url'] && ( 'https' !== wp_parse_url( $out['url'], PHP_URL_SCHEME ) || ! wp_parse_url( $out['url'], PHP_URL_HOST ) || wp_parse_url( $out['url'], PHP_URL_USER ) || wp_parse_url( $out['url'], PHP_URL_PASS ) ) ) {
            $out['url'] = '';
        }
        if ( ! empty( $input['url'] ) && '' === $out['url'] ) {
            add_settings_error( self::OPTION, 'invalid_url', 'Indiquez une URL HTTPS valide pour le fichier APK ou Google Play.' );
        }
        $out['type'] = 'play' === $out['type'] ? 'play' : 'apk';
        // pro.59: the app downloads an APK only from this site, so a foreign APK link is not published.
        if ( 'apk' === $out['type'] && '' !== $out['url'] && ! self::same_host( $out['url'] ) ) {
            add_settings_error( self::OPTION, 'foreign_url', 'Pour un fichier APK, le lien doit être sur ce site (téléversez-le avec le formulaire APK ci-dessus) : l’application n’installe rien depuis un autre hôte.' );
            $out['url'] = '';
        }
        $out['version'] = sanitize_text_field( substr( $out['version'], 0, 40 ) );
        $out['size'] = sanitize_text_field( substr( $out['size'], 0, 40 ) );
        return $out;
    }
    public static function admin_init(): void {
        register_setting( 'delicat_android_app', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
        if ( ! current_user_can( 'manage_options' ) || get_option( self::PAGE_OPTION, 0 ) ) { return; }
        // Adopt only our own shortcode page; never overwrite an existing /application/ page.
        $existing = get_page_by_path( 'application' );
        if ( $existing && has_shortcode( $existing->post_content, 'delicat_android_app' ) ) {
            update_option( self::PAGE_OPTION, $existing->ID, false );
            return;
        }
        $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Application Delicat Store', 'post_name' => 'application', 'post_content' => '[delicat_android_app]', 'comment_status' => 'closed' ), true );
        if ( ! is_wp_error( $id ) && $id ) { update_option( self::PAGE_OPTION, $id, false ); }
    }
    public static function page_url(): string {
        $id = absint( get_option( self::PAGE_OPTION, 0 ) );
        return $id && 'publish' === get_post_status( $id ) ? (string) get_permalink( $id ) : '';
    }
    public static function menu_links( $items ) {
        $url = self::page_url();
        if ( $url ) { foreach ( $items as $item ) { if ( '#install-app' === $item->url ) { $item->url = $url; } } }
        return $items;
    }
    private static function is_page(): bool {
        return is_page( absint( get_option( self::PAGE_OPTION, 0 ) ) ) && absint( get_option( self::PAGE_OPTION, 0 ) ) > 0;
    }
    public static function template( $template ) {
        if ( class_exists( 'Delicat_Builder_V9_Core', false ) && is_callable( array( 'Delicat_Builder_V9_Core', 'is_enabled' ) ) && ! Delicat_Builder_V9_Core::is_enabled() ) {
            return $template;
        }
        if ( self::is_page() && class_exists( 'Delicat_Builder_V9_Header_Studio_8', false ) ) {
            return DELICAT_BUILDER_V9_DIR . 'templates/native-content-page.php';
        }
        return $template;
    }
    public static function assets(): void {
        $url = self::page_url();
        if ( $url ) {
            wp_enqueue_script( 'delicat-android-app-link', DELICAT_BUILDER_V9_URL . 'assets/js/android-app-link.js', array(), DELICAT_BUILDER_V9_VERSION, true );
            wp_add_inline_script( 'delicat-android-app-link', 'window.DelicatAndroidPage=' . wp_json_encode( $url ) . ';', 'before' );
        }
        $post = get_post();
        if ( self::is_page() || ( $post && has_shortcode( $post->post_content, 'delicat_android_app' ) ) ) {
            wp_enqueue_style( 'delicat-android-app-page', DELICAT_BUILDER_V9_URL . 'assets/css/android-app-page.css', array(), DELICAT_BUILDER_V9_VERSION );
        }
    }
    public static function admin_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $s = self::settings();
        echo '<div class="wrap"><h1>Application mobile Android</h1><p>Ajoutez le lien public de votre APK signé ou de votre application Google Play. Le bouton App du menu ouvre la page de téléchargement.</p>';
        settings_errors( self::OPTION );
        do_action( 'delicat_android_upload_panel' );
        if ( self::page_url() ) { echo '<p><a class="button" href="' . esc_url( self::page_url() ) . '" target="_blank" rel="noopener">Voir la page de téléchargement</a></p>'; }
        echo '<form method="post" action="options.php">'; settings_fields( 'delicat_android_app' );
        echo '<table class="form-table"><tr><th><label for="dapp-type">Distribution</label></th><td><select id="dapp-type" name="' . esc_attr( self::OPTION ) . '[type]"><option value="apk" ' . selected( $s['type'], 'apk', false ) . '>Fichier APK Android</option><option value="play" ' . selected( $s['type'], 'play', false ) . '>Google Play</option></select></td></tr>';
        foreach ( array( 'url' => 'Lien HTTPS de téléchargement', 'version' => 'Version (facultatif)', 'size' => 'Taille du fichier (ex. 35 Mo, facultatif)' ) as $key => $label ) {
            echo '<tr><th><label for="dapp-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="dapp-' . esc_attr( $key ) . '" type="' . ( 'url' === $key ? 'url' : 'text' ) . '" name="' . esc_attr( self::OPTION ) . '[' . esc_attr( $key ) . ']" value="' . esc_attr( $s[$key] ) . '"></td></tr>';
        }
        echo '<tr><th><label for="dapp-build">Numéro de build</label></th><td><input class="small-text" id="dapp-build" type="number" min="0" name="' . esc_attr( self::OPTION ) . '[build]" value="' . esc_attr( (string) (int) $s['build'] ) . '"> <span class="description">Lu dans l’APK au téléversement (5.0.0+59 → 59). L’app se met à jour quand ce nombre ou la version augmente.</span></td></tr>';
        echo '<tr><th><label for="dapp-sha256">Empreinte SHA-256</label></th><td><input class="large-text code" id="dapp-sha256" type="text" maxlength="64" name="' . esc_attr( self::OPTION ) . '[sha256]" value="' . esc_attr( $s['sha256'] ) . '"> <span class="description">Calculée au téléversement ; l’app refuse un fichier dont l’empreinte diffère.</span></td></tr>';
        echo '<tr><th><label for="dapp-min-build">Build minimum</label></th><td><input class="small-text" id="dapp-min-build" type="number" min="0" name="' . esc_attr( self::OPTION ) . '[min_build]" value="' . esc_attr( (string) (int) $s['min_build'] ) . '"> <span class="description">En dessous de ce build, l’app exige la mise à jour (0 = jamais obligatoire).</span></td></tr>';
        echo '<tr><th><label for="dapp-notes">Nouveautés (affichées dans l’app)</label></th><td><textarea class="large-text" id="dapp-notes" rows="4" maxlength="2000" name="' . esc_attr( self::OPTION ) . '[notes]">' . esc_textarea( $s['notes'] ) . '</textarea></td></tr>';
        echo '<input type="hidden" name="' . esc_attr( self::OPTION ) . '[package]" value="' . esc_attr( $s['package'] ) . '">';
        echo '</table><p>Le téléversement ci-dessus remplit automatiquement le lien, la taille, la version, le build et l’empreinte. Les téléphones qui ont installé l’APK depuis ce site reçoivent cette version dans l’app (Delicat App API) ; ceux installés depuis Google Play sont renvoyés vers Play.</p>';
        submit_button( 'Enregistrer' ); echo '</form></div>';
    }
    public static function render(): string {
        $s = self::settings();
        $ready = is_string( $s['url'] ) && 'https' === wp_parse_url( $s['url'], PHP_URL_SCHEME ) && wp_parse_url( $s['url'], PHP_URL_HOST );
        $play = 'play' === $s['type'];
        $logo = get_site_icon_url( 192 );
        ob_start(); ?>
        <section class="dapp-page" aria-labelledby="dapp-title">
          <div class="dapp-hero"><div class="dapp-copy">
            <span class="dapp-eyebrow"><span></span> DELICAT STORE · ANDROID</span>
            <div class="dapp-brand"><?php if ( $logo ) : ?><img src="<?php echo esc_url( $logo ); ?>" width="72" height="72" alt="Delicat Store"><?php else : ?><span aria-hidden="true">D</span><?php endif; ?></div>
            <h1 id="dapp-title">Votre univers gaming.<br><em>Dans votre poche.</em></h1>
            <p class="dapp-lead">Retrouvez Delicat Store sur votre téléphone Android. Vos jeux, vos services et votre compte, à portée de main.</p>
            <?php if ( $ready ) : ?>
            <a class="dapp-download" href="<?php echo esc_url( $s['url'] ); ?>" data-no-instant data-no-swup data-dbp-skip data-no-turbo data-turbo="false" rel="external"><svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="M12 3v12m-5-5 5 5 5-5M4 16v5h16v-5"/></svg><?php echo $play ? 'Installer depuis Google Play' : 'Télécharger pour Android'; ?></a>
            <?php else : ?><p class="dapp-pending" role="status">Le téléchargement sera bientôt disponible.</p><?php endif; ?>
            <p class="dapp-meta">Android<?php if ( $s['version'] ) { echo ' · Version ' . esc_html( $s['version'] ); } if ( ! $play && $s['size'] ) { echo ' · ' . esc_html( $s['size'] ); } ?><?php echo $play ? ' · Google Play' : ' · Fichier APK'; ?></p>
            <?php if ( $ready && ! $play && $s['sha256'] ) : ?><p class="dapp-meta" style="font-size:11px;overflow-wrap:anywhere;opacity:.75">SHA-256 <?php echo esc_html( $s['sha256'] ); ?></p><?php endif; ?>
            <a class="dapp-text-link" href="<?php echo esc_url( home_url( '/' ) ); ?>">Continuer sur le site <span aria-hidden="true">↗</span></a>
          </div><div class="dapp-art" aria-hidden="true"><div class="dapp-orbit"></div><div class="dapp-phone"><div class="dapp-camera"></div><span class="dapp-phone-label">DELICAT STORE</span><div class="dapp-phone-mark">D<span>↗</span></div><strong>Play. Top up.<br>Enjoy.</strong><div class="dapp-phone-grid"><span>JEUX</span><span>WALLET</span><span>CADEAUX</span><span>SUPPORT</span></div><div class="dapp-phone-bar"></div></div><span class="dapp-art-tag">Votre prochain niveau commence ici.</span></div></div>
          <div class="dapp-features"><div><b>01</b><h2>Vos jeux préférés</h2><p>Gardez votre boutique gaming à portée de main.</p></div><div><b>02</b><h2>Votre compte Delicat</h2><p>Utilisez vos identifiants habituels pour vous connecter.</p></div><div><b>03</b><h2>Un accès direct</h2><p>Ouvrez Delicat Store depuis votre écran d’accueil.</p></div></div>
          <section class="dapp-guide" aria-labelledby="dapp-guide-title"><div><span class="dapp-eyebrow">PRÊT À COMMENCER ?</span><h2 id="dapp-guide-title">Installez. Connectez-vous.<br>Profitez.</h2><p>L’installation est confirmée par Android. Aucun achat n’est effectué depuis cette page.</p></div><ol>
          <?php if ( $play ) : ?><li><strong>Ouvrez Google Play</strong><span>Touchez le bouton d’installation ci-dessus sur votre téléphone Android.</span></li><li><strong>Installez l’application</strong><span>Vérifiez la fiche Delicat Store puis touchez « Installer ».</span></li>
          <?php else : ?><li><strong>Téléchargez le fichier APK</strong><span>Touchez « Télécharger pour Android », puis ouvrez le fichier téléchargé.</span></li><li><strong>Confirmez l’installation</strong><span>Si Android le demande, autorisez temporairement votre navigateur à installer cette application. Vous pouvez retirer cette autorisation après l’installation.</span></li><?php endif; ?>
          <li><strong>Bienvenue chez vous</strong><span>Ouvrez Delicat Store et connectez-vous à votre compte.</span></li></ol></section>
          <details class="dapp-faq"><summary>Vous utilisez un iPhone ou un ordinateur ?</summary><p>Le fichier Android ne s’installe pas sur iPhone. Vous pouvez continuer à utiliser Delicat Store dans votre navigateur, ou ouvrir cette page sur votre téléphone Android.</p></details>
        </section>
        <?php return (string) ob_get_clean();
    }
}
Delicat_Builder_V9_App_Download::boot();

if ( is_admin() ) { require_once __DIR__ . '/class-delicat-builder-apk-upload.php'; }
