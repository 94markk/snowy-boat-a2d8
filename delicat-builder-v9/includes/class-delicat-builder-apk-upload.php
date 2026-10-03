<?php
/** Administrator-only APK ingestion; no global MIME or unfiltered-upload exceptions. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-delicat-builder-apk-manifest.php';
final class Delicat_Builder_V9_APK_Upload {
    const META = 'delicat_builder_v9_android_release';
    const LOCK = 'delicat_builder_v9_android_upload_lock';
    const ACTION = 'delicat_builder_upload_apk';

    public static function boot(): void {
        add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'upload' ) );
        add_action( 'admin_post_delicat_apk_unlock', array( __CLASS__, 'unlock' ) );
        add_action( 'delicat_android_upload_panel', array( __CLASS__, 'panel' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }
    public static function assets(): void {
        if ( ! isset( $_GET['page'] ) || 'delicat-builder-app-download' !== $_GET['page'] || ! current_user_can( 'manage_options' ) ) { return; }
        wp_enqueue_script( 'delicat-apk-upload-admin', DELICAT_BUILDER_V9_URL . 'assets/js/apk-upload-admin.js', array(), DELICAT_BUILDER_V9_VERSION, true );
    }
    public static function limit(): int {
        return max( 0, min( 200 * 1024 * 1024, (int) wp_max_upload_size() ) );
    }
    private static function secure(): bool {
        if ( is_ssl() ) { return true; }
        // Only explicitly trusted proxy deployments may use forwarded TLS state.
        if ( ! defined( 'DELICAT_APP_TRUST_PROXY_HEADERS' ) || ! DELICAT_APP_TRUST_PROXY_HEADERS ) { return false; }
        foreach ( array( 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME' ) as $key ) {
            if ( isset( $_SERVER[$key] ) && is_string( $_SERVER[$key] ) && '' !== trim( $_SERVER[$key] ) ) {
                return 'https' === strtolower( trim( explode( ',', $_SERVER[$key] )[0] ) );
            }
        }
        if ( isset( $_SERVER['HTTP_X_FORWARDED_SSL'] ) && is_string( $_SERVER['HTTP_X_FORWARDED_SSL'] ) ) {
            return 'on' === strtolower( trim( $_SERVER['HTTP_X_FORWARDED_SSL'] ) );
        }
        if ( isset( $_SERVER['HTTP_CF_VISITOR'] ) && is_string( $_SERVER['HTTP_CF_VISITOR'] ) ) {
            $data = json_decode( wp_unslash( $_SERVER['HTTP_CF_VISITOR'] ), true );
            return is_array( $data ) && isset( $data['scheme'] ) && is_string( $data['scheme'] ) && 'https' === strtolower( $data['scheme'] );
        }
        return false;
    }
    /** Bounded structural validation; never extract ZIP entries or execute APK contents. */
    public static function validate( string $path, string $name, int $limit ) {
        if ( ! preg_match( '/\.apk$/iD', $name ) || false !== strpos( $name, "\0" ) ) {
            return new WP_Error( 'apk_extension', 'Choisissez un fichier .apk (pas un ZIP ou un AAB).' );
        }
        $bytes = is_file( $path ) ? filesize( $path ) : false;
        if ( false === $bytes || $bytes < 64 || $bytes > $limit ) {
            return new WP_Error( 'apk_size', 'Fichier vide, incomplet ou trop volumineux pour ce serveur.' );
        }
        if ( ! class_exists( 'ZipArchive' ) ) {
            return new WP_Error( 'apk_zip', 'Activez l’extension PHP ZIP dans Hostinger pour valider les APK.' );
        }
        $stream = fopen( $path, 'rb' );
        if ( false === $stream ) { return new WP_Error( 'apk_read', 'Impossible de lire le fichier envoyé.' ); }
        $magic = fread( $stream, 4 ); fclose( $stream );
        if ( "PK\x03\x04" !== $magic ) { return new WP_Error( 'apk_format', 'Ce fichier n’est pas une archive APK valide.' ); }
        $zip = new ZipArchive();
        if ( true !== $zip->open( $path, ZipArchive::RDONLY ) ) { return new WP_Error( 'apk_format', 'L’archive APK est endommagée.' ); }
        try {
            if ( $zip->numFiles < 2 || $zip->numFiles > 30000 ) { return new WP_Error( 'apk_entries', 'Structure APK non prise en charge.' ); }
            $seen = array(); $payload = false;
            for ( $i = 0; $i < $zip->numFiles; $i++ ) {
                $entry = $zip->statIndex( $i );
                if ( ! is_array( $entry ) || ! isset( $entry['name'] ) ) { return new WP_Error( 'apk_entry', 'Archive APK illisible.' ); }
                $entry_name = $entry['name'];
                if ( strlen( $entry_name ) > 1024 || preg_match( '~(^/|\\\\|\x00|(?:^|/)\.\.(?:/|$))~', $entry_name ) || isset( $seen[$entry_name] ) || ! empty( $entry['encryption_method'] ) ) {
                    return new WP_Error( 'apk_entry', 'L’archive contient un chemin, un doublon ou un chiffrement non autorisé.' );
                }
                $seen[$entry_name] = true;
                if ( preg_match( '~^classes(?:[0-9]+)?\.dex$~', $entry_name ) || 'resources.arsc' === $entry_name || 0 === strpos( $entry_name, 'lib/' ) ) { $payload = true; }
            }
            $manifest = $zip->statName( 'AndroidManifest.xml' );
            if ( ! $payload || ! is_array( $manifest ) || $manifest['size'] < 8 || $manifest['size'] > 8 * 1024 * 1024 ) {
                return new WP_Error( 'apk_manifest', 'Le manifeste Android ou le contenu de l’application manque.' );
            }
            $manifest_stream = $zip->getStream( 'AndroidManifest.xml' );
            if ( false === $manifest_stream ) { return new WP_Error( 'apk_manifest', 'Le manifeste Android est illisible.' ); }
            $manifest_data = stream_get_contents( $manifest_stream, (int) $manifest['size'] ); fclose( $manifest_stream );
            if ( ! is_string( $manifest_data ) || strlen( $manifest_data ) !== (int) $manifest['size'] ) { return new WP_Error( 'apk_manifest', 'Le manifeste Android est incomplet.' ); }
            $declared = unpack( 'Vsize', substr( $manifest_data, 4, 4 ) );
            if ( chr(3) . chr(0) . chr(8) . chr(0) !== substr( $manifest_data, 0, 4 ) || (int) $declared['size'] !== (int) $manifest['size'] ) {
                return new WP_Error( 'apk_manifest', 'Le manifeste n’est pas au format binaire Android attendu.' );
            }
        } finally { $zip->close(); }
        // pro.59: package, version and build are read from the manifest itself, never typed.
        $identity = Delicat_Builder_V9_APK_Manifest::parse( $manifest_data );
        if ( is_wp_error( $identity ) ) { return $identity; }
        $expected = (string) apply_filters( 'delicat_builder_v9_apk_package', self::expected_package() );
        if ( '' !== $expected && $identity['package'] !== $expected ) {
            return new WP_Error( 'apk_package', sprintf( 'Cet APK est « %s », pas l’application Delicat Store (« %s »). Aucun fichier n’a été publié.', $identity['package'], $expected ) );
        }
        if ( $identity['version_code'] < 1 ) { return new WP_Error( 'apk_version', 'L’APK ne déclare pas de versionCode.' ); }
        $sha = hash_file( 'sha256', $path );
        if ( ! is_string( $sha ) ) { return new WP_Error( 'apk_hash', 'Impossible de vérifier l’intégrité du fichier.' ); }
        return array( 'bytes' => (int) $bytes, 'sha256' => $sha, 'build' => self::build_number( (int) $identity['version_code'] ) ) + $identity;
    }
    /** The app's package: the Delicat App API declares it; otherwise the first upload fixes it. */
    public static function expected_package(): string {
        if ( class_exists( 'Delicat_App_Integrity', false ) && defined( 'Delicat_App_Integrity::PACKAGE' ) ) { return (string) Delicat_App_Integrity::PACKAGE; }
        $meta = get_option( self::META, array() );
        return is_array( $meta ) && ! empty( $meta['package'] ) ? (string) $meta['package'] : '';
    }
    /** Flutter's --split-per-abi adds 1000 per ABI to the versionCode; the pubspec build is what the app compares. */
    public static function build_number( int $version_code ): int {
        return $version_code >= 1000 ? $version_code % 1000 : $version_code;
    }
    private static function storage() {
        $uploads = wp_upload_dir();
        if ( ! empty( $uploads['error'] ) ) { return new WP_Error( 'apk_storage', 'Le dossier de téléchargement WordPress est indisponible.' ); }
        $base = realpath( $uploads['basedir'] );
        if ( false === $base ) { return new WP_Error( 'apk_storage', 'Le dossier de téléchargement WordPress est introuvable.' ); }
        $dir = $base . '/delicat-android-releases';
        if ( is_link( $dir ) || ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) || ! is_writable( $dir ) ) {
            return new WP_Error( 'apk_storage', 'Impossible de préparer le dossier APK sécurisé.' );
        }
        if ( realpath( $dir ) !== $dir ) { return new WP_Error( 'apk_storage', 'Chemin de stockage APK non autorisé.' ); }
        // Apache/LiteSpeed: serve only generated APK filenames as downloads.
        $rules = <<<'RULES'
Options -Indexes -ExecCGI
<IfModule mod_authz_core.c>
    Require all denied
    <FilesMatch "^delicat-[a-f0-9]{32}\.apk$">
        Require all granted
    </FilesMatch>
</IfModule>
<IfModule !mod_authz_core.c>
    Order Allow,Deny
    Deny from all
    <FilesMatch "^delicat-[a-f0-9]{32}\.apk$">
        Order Deny,Allow
        Allow from all
    </FilesMatch>
</IfModule>
<FilesMatch "^delicat-[a-f0-9]{32}\.apk$">
    SetHandler default-handler
    ForceType application/vnd.android.package-archive
    <IfModule mod_headers.c>
        Header set Content-Disposition "attachment"
        Header set X-Content-Type-Options "nosniff"
        Header set X-Robots-Tag "noindex, nofollow"
    </IfModule>
</FilesMatch>
RULES;
        foreach ( array( '.htaccess' => $rules . "\n", 'index.html' => '' ) as $name => $content ) {
            $file = $dir . '/' . $name;
            if ( is_link( $file ) || ( file_exists( $file ) && ! is_file( $file ) ) ) { return new WP_Error( 'apk_storage', 'Fichier de protection non autorisé.' ); }
            if ( false === file_put_contents( $file, $content, LOCK_EX ) ) { return new WP_Error( 'apk_storage', 'Impossible de protéger le dossier APK.' ); }
            chmod( $file, 0644 );
        }
        $url = set_url_scheme( trailingslashit( $uploads['baseurl'] ) . 'delicat-android-releases/', 'https' );
        if ( ! wp_parse_url( $url, PHP_URL_HOST ) ) { return new WP_Error( 'apk_url', 'URL de téléchargement WordPress invalide.' ); }
        return array( 'dir' => $dir, 'url' => $url );
    }
    private static function fail( $error ): void {
        wp_die( esc_html( is_wp_error( $error ) ? $error->get_error_message() : $error ), 'Téléversement APK', array( 'response' => 400, 'back_link' => true ) );
    }
    public static function upload(): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ) { wp_die( 'Méthode non autorisée.', '', array( 'response' => 405 ) ); }
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) || ( is_multisite() && ! is_super_admin() ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        if ( empty( $_POST ) && empty( $_FILES ) && (int) ( $_SERVER['CONTENT_LENGTH'] ?? 0 ) > 0 ) { self::fail( 'Le fichier dépasse probablement post_max_size. Augmentez les limites PHP dans Hostinger, puis réessayez.' ); }
        check_admin_referer( self::ACTION );
        if ( ! self::secure() ) { self::fail( 'Le téléversement exige une connexion HTTPS reconnue par WordPress. Vérifiez le HTTPS du serveur ou la configuration de votre proxy de confiance.' ); }
        if ( ! class_exists( 'Delicat_Builder_V9_App_Download', false ) ) { self::fail( 'Module Application mobile indisponible.' ); }
        $file = $_FILES['delicat_apk'] ?? null;
        if ( ! is_array( $file ) || ! isset( $file['error'], $file['name'], $file['tmp_name'] ) || ! is_int( $file['error'] ) || ! is_string( $file['name'] ) || ! is_string( $file['tmp_name'] ) ) { self::fail( 'Choisissez un APK. Si le fichier dépasse la limite PHP, augmentez upload_max_filesize et post_max_size dans Hostinger.' ); }
        if ( UPLOAD_ERR_OK !== $file['error'] ) { self::fail( 'Le transfert a échoué ou dépasse la limite du serveur. Réessayez avec un fichier complet.' ); }
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) { self::fail( 'Source de téléversement non autorisée.' ); }
        $expected = isset( $_POST['apk_sha256'] ) && is_string( $_POST['apk_sha256'] ) ? strtolower( trim( wp_unslash( $_POST['apk_sha256'] ) ) ) : '';
        if ( '' !== $expected && ! preg_match( '/^[a-f0-9]{64}$/D', $expected ) ) { self::fail( 'L’empreinte SHA-256 doit contenir 64 caractères hexadécimaux.' ); }
        // Atomic DB lock: never delete another request's live lock.
        $lock = time() . '|' . bin2hex( random_bytes( 16 ) );
        if ( ! add_option( self::LOCK, $lock, '', false ) ) { self::fail( 'Un téléversement est déjà en cours. Si un transfert a été interrompu, utilisez le bouton de réinitialisation après 15 minutes.' ); }
        $result = null; $destination = ''; $committed = false;
        try {
            $result = self::validate( $file['tmp_name'], $file['name'], self::limit() );
            if ( ! is_wp_error( $result ) && '' !== $expected && ! hash_equals( $expected, $result['sha256'] ) ) { $result = new WP_Error( 'apk_checksum', 'L’empreinte SHA-256 ne correspond pas au fichier attendu. Aucun fichier n’a été publié.' ); }
            if ( ! is_wp_error( $result ) && is_multisite() && function_exists( 'get_upload_space_available' ) && $result['bytes'] > get_upload_space_available() ) { $result = new WP_Error( 'apk_quota', 'Espace de stockage réseau insuffisant.' ); }
            $previous = get_option( self::META, array() );
            if ( ! is_wp_error( $result ) && is_array( $previous ) && ! empty( $previous['version_code'] ) && (int) $result['version_code'] < (int) $previous['version_code'] ) {
                $result = new WP_Error( 'apk_downgrade', sprintf( 'Cet APK (versionCode %d) est plus ancien que celui déjà publié (versionCode %d). Les téléphones ne reviennent pas en arrière ; publiez un build plus récent.', $result['version_code'], $previous['version_code'] ) );
            }
            $storage = ! is_wp_error( $result ) ? self::storage() : $result;
            if ( is_wp_error( $storage ) ) { $result = $storage; }
            if ( ! is_wp_error( $result ) ) {
                $name = 'delicat-' . bin2hex( random_bytes( 16 ) ) . '.apk';
                $target = $storage['dir'] . '/' . $name;
                if ( file_exists( $target ) || ! move_uploaded_file( $file['tmp_name'], $target ) ) { $result = new WP_Error( 'apk_move', 'Impossible d’enregistrer le fichier APK.' ); }
                elseif ( ! chmod( $destination = $target, 0644 ) || ! hash_equals( $result['sha256'], (string) hash_file( 'sha256', $destination ) ) ) { $result = new WP_Error( 'apk_integrity', 'Échec de vérification du fichier enregistré.' ); }
                else {
                    $url = $storage['url'] . $name;
                    $settings = Delicat_Builder_V9_App_Download::settings();
                    $settings['url'] = $url; $settings['type'] = 'apk'; $settings['size'] = size_format( $result['bytes'], 1 );
                    // pro.59: identity from the manifest; the typed version only overrides the label.
                    $settings['version'] = (string) $result['version_name']; $settings['build'] = (int) $result['build'];
                    $settings['sha256'] = (string) $result['sha256']; $settings['package'] = (string) $result['package'];
                    if ( isset( $_POST['apk_version'] ) && is_string( $_POST['apk_version'] ) && '' !== trim( $_POST['apk_version'] ) ) { $settings['version'] = sanitize_text_field( substr( wp_unslash( $_POST['apk_version'] ), 0, 40 ) ); }
                    wp_cache_delete( self::LOCK, 'options' );
                    if ( get_option( self::LOCK, '' ) !== $lock ) { $result = new WP_Error( 'apk_lock', 'Ce transfert a été réinitialisé. Réessayez.' ); }
                    elseif ( ! update_option( Delicat_Builder_V9_App_Download::OPTION, $settings, false ) ) { $result = new WP_Error( 'apk_save', 'Impossible de sauvegarder le lien. Le fichier n’a pas été publié sur la page.' ); }
                    else {
                        $committed = true;
                        update_option( self::META, array( 'url' => $url, 'sha256' => $result['sha256'], 'bytes' => $result['bytes'], 'uploaded_at' => gmdate( 'c' ), 'uploaded_by' => get_current_user_id(), 'package' => $result['package'], 'version_code' => (int) $result['version_code'], 'version_name' => (string) $result['version_name'], 'build' => (int) $result['build'], 'min_sdk' => (int) $result['min_sdk'] ), false );
                    }
                }
            }
        } catch ( Throwable $e ) { $result = new WP_Error( 'apk_failure', 'Téléversement interrompu. Vérifiez l’espace disque et les limites PHP, puis réessayez.' ); }
        finally {
            if ( ! $committed && $destination && is_file( $destination ) ) { wp_delete_file( $destination ); }
            self::release_lock( $lock );
        }
        if ( is_wp_error( $result ) ) { self::fail( $result ); }
        wp_safe_redirect( add_query_arg( array( 'page' => 'delicat-builder-app-download', 'apk_uploaded' => '1' ), admin_url( 'admin.php' ) ) ); exit;
    }
    /** Compare-and-delete ensures an old request cannot release a newer upload's lock. */
    private static function release_lock( string $lock ): void {
        global $wpdb;
        $wpdb->delete( $wpdb->options, array( 'option_name' => self::LOCK, 'option_value' => $lock ), array( '%s', '%s' ) );
        wp_cache_delete( self::LOCK, 'options' );
        wp_cache_delete( 'notoptions', 'options' );
    }
    public static function unlock(): void {
        if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) || ( is_multisite() && ! is_super_admin() ) ) { wp_die( 'Accès refusé.', '', array( 'response' => 403 ) ); }
        check_admin_referer( 'delicat_apk_unlock' );
        if ( ! self::secure() ) { self::fail( 'Une connexion HTTPS est obligatoire.' ); }
        $lock = get_option( self::LOCK, '' );
        if ( ! is_string( $lock ) || ! $lock || (int) explode( '|', $lock )[0] > time() - 15 * MINUTE_IN_SECONDS ) { self::fail( 'Aucun verrou interrompu de plus de 15 minutes à réinitialiser.' ); }
        self::release_lock( $lock );
        wp_safe_redirect( add_query_arg( 'page', 'delicat-builder-app-download', admin_url( 'admin.php' ) ) ); exit;
    }
    public static function panel(): void {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) || ( is_multisite() && ! is_super_admin() ) ) { return; }
        if ( isset( $_GET['apk_uploaded'] ) && '1' === $_GET['apk_uploaded'] ) { echo '<div class="notice notice-success"><p>APK enregistré. Le lien de téléchargement a été généré et activé sur la page Application.</p></div>'; }
        echo '<div style="max-width:850px;background:#fff;border:1px solid #dcdcde;border-radius:12px;padding:24px;margin:20px 0"><h2>Téléverser votre application Android</h2><p>Choisissez votre APK de production signé. Le lien HTTPS et la taille du fichier seront enregistrés automatiquement.</p>';
        if ( ! class_exists( 'ZipArchive' ) ) { echo '<div class="notice notice-error inline"><p>L’extension PHP ZIP doit être activée pour téléverser un APK.</p></div>'; }
        echo '<form id="delicat-apk-upload-form" method="post" enctype="multipart/form-data" action="' . esc_url( add_query_arg( 'action', self::ACTION, admin_url( 'admin-post.php' ) ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
        wp_nonce_field( self::ACTION );
        echo '<input type="hidden" name="MAX_FILE_SIZE" value="' . esc_attr( (string) self::limit() ) . '"><p><label for="delicat-apk"><strong>Fichier APK</strong></label><br><input id="delicat-apk" type="file" name="delicat_apk" aria-describedby="delicat-apk-help delicat-apk-status" required></p><p id="delicat-apk-help">Le sélecteur affiche tous les fichiers pour éviter de masquer les APK sur certains appareils. Choisissez uniquement un fichier dont le nom se termine par .apk. Sur iPhone/iPad, enregistrez-le d’abord dans Fichiers ; sur Android, cherchez dans Téléchargements. Si nécessaire, choisissez « Tous les fichiers » dans la fenêtre.</p><p id="delicat-apk-status" role="status" aria-live="polite" style="overflow-wrap:anywhere">Aucun fichier sélectionné.</p><p>Limite effective : <strong>' . esc_html( size_format( self::limit() ) ) . '</strong> (PHP et WordPress). Les archives ZIP et AAB ne sont pas acceptées.</p><p><label for="apk-version">Version (facultatif)</label><br><input id="apk-version" name="apk_version" type="text" maxlength="40" placeholder="lue dans l’APK"><br><span class="description">La version, le numéro de build et le paquet sont lus dans l’APK lui-même ; remplissez seulement pour afficher un autre libellé. Un APK d’une autre application, ou plus ancien que le build publié, est refusé.</span></p><p><label for="apk-sha256">SHA-256 attendu (facultatif)</label><br><input id="apk-sha256" name="apk_sha256" class="large-text code" type="text" maxlength="64" pattern="[A-Fa-f0-9]{64}" placeholder="Empreinte du fichier de production"></p>';
        submit_button( 'Téléverser et publier le lien', 'primary', 'submit', true, class_exists( 'ZipArchive' ) ? array() : array( 'disabled' => 'disabled' ) );
        echo '<p>HTTPS chiffre le transfert. L’APK reste public pour permettre son installation. La validation du format et le SHA-256 ne remplacent pas la vérification de signature Android ni une analyse antivirus.</p></form>';
        echo '<details><summary><strong>Recommandations avant publication</strong></summary><ul><li>Utilisez un APK universel de production signé, pour les appareils Android que vous prenez en charge ; un AAB ou un fichier APK fractionné ne convient pas à cette installation directe.</li><li>Conservez le même identifiant d’application et la même clé de signature pour les mises à jour, et augmentez le versionCode. Sauvegardez votre clé de signature hors du site.</li><li>Vérifiez la signature et analysez le fichier avant publication ; testez ensuite le lien et la mise à jour sur un téléphone Android.</li><li>Comparez la taille du fichier à la limite effective ci-dessus. Si nécessaire, ajustez upload_max_filesize et post_max_size dans les paramètres PHP de votre hébergeur ; gardez post_max_size supérieur à la taille de l’APK et des données du formulaire.</li><li>Si le fichier est dans le cloud, téléchargez-le entièrement sur votre appareil avant de le sélectionner. Ne renommez pas un ZIP ou un AAB en APK.</li></ul></details>';
        $meta = get_option( self::META, array() );
        if ( is_array( $meta ) && ! empty( $meta['url'] ) && ! empty( $meta['sha256'] ) ) {
            echo '<h3>Dernier APK téléversé</h3>';
            if ( ! empty( $meta['package'] ) ) { echo '<p>' . esc_html( $meta['package'] ) . ' · version <strong>' . esc_html( (string) ( $meta['version_name'] ?? '' ) ) . '</strong> · build <strong>' . esc_html( (string) ( $meta['build'] ?? '' ) ) . '</strong> (versionCode ' . esc_html( (string) ( $meta['version_code'] ?? '' ) ) . ')' . ( ! empty( $meta['uploaded_at'] ) ? ' · ' . esc_html( $meta['uploaded_at'] ) : '' ) . '</p>'; }
            echo '<p><label for="apk-generated-url">Lien généré — sélectionnez et copiez</label><input id="apk-generated-url" class="large-text code" type="url" readonly value="' . esc_attr( $meta['url'] ) . '" onclick="this.select()"></p><p><a href="' . esc_url( $meta['url'] ) . '" rel="noopener" target="_blank">Télécharger cet APK</a></p><p style="overflow-wrap:anywhere">SHA-256 : <code>' . esc_html( $meta['sha256'] ) . '</code></p>';
        }
        $lock = get_option( self::LOCK, '' );
        if ( is_string( $lock ) && $lock && (int) explode( '|', $lock )[0] <= time() - 15 * MINUTE_IN_SECONDS ) {
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="delicat_apk_unlock">';
            wp_nonce_field( 'delicat_apk_unlock' );
            submit_button( 'Réinitialiser le transfert interrompu', 'secondary' );
            echo '</form>';
        }
        echo '</div>';
    }
}
Delicat_Builder_V9_APK_Upload::boot();
