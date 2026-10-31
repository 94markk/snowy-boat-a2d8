<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Secure proof-of-payment file handling.
 *
 * Files are stored in a PRIVATE directory outside the publicly browsable
 * uploads tree, protected by an .htaccess deny rule and an index.php guard.
 * They are NEVER served by direct URL. Instead, only logged-in administrators
 * can view them through a nonce-protected proxy endpoint that streams the file
 * after verifying the manage_options capability.
 *
 * The database stores only the relative filename (e.g. "a1b2c3.jpg"),
 * never a public URL.
 */
class Delicat_Files {

    const ALLOWED = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/gif'  => 'gif',
        'image/webp' => 'webp',
        'application/pdf' => 'pdf',
    ];

    const MAX_SIZE = 8388608; // 8 MB

    public function __construct() {
        add_action( 'init',            [ $this, 'ensure_dir' ] );
        add_action( 'admin_post_delicat_view_proof', [ $this, 'serve' ] );
    }

    /**
     * Absolute path to the private storage directory.
     */
    public static function dir(): string {
        $base = wp_upload_dir();
        return trailingslashit( $base['basedir'] ) . 'delicat-private';
    }

    /**
     * Create the private directory and lock it down on first run.
     */
    public function ensure_dir(): void {
        $dir = self::dir();
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        // Deny all direct web access (Apache).
        $htaccess = $dir . '/.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            file_put_contents( $htaccess,
                "Order Allow,Deny\nDeny from all\n"
                . "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
            );
        }
        // Prevent directory listing / PHP execution fallback.
        $index = $dir . '/index.php';
        if ( ! file_exists( $index ) ) {
            file_put_contents( $index, "<?php // Silence is golden.\n" );
        }
    }

    /**
     * Validate and store an uploaded proof file privately.
     *
     * @return string|WP_Error  The stored filename (not a URL) on success.
     */
    public function store( array $file ): string|WP_Error {
        if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'no_file', 'Aucun fichier valide reçu.' );
        }
        if ( $file['size'] > self::MAX_SIZE ) {
            return new WP_Error( 'too_large', 'Fichier trop grand (max 8 Mo).' );
        }

        // Verify the REAL file type from contents (not the spoofable client type).
        $real_mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $real_mime = finfo_file( $finfo, $file['tmp_name'] );
                finfo_close( $finfo );
            }
        }
        // Fallback: use WordPress's own filetype check based on the filename.
        if ( ! $real_mime || ! isset( self::ALLOWED[ $real_mime ] ) ) {
            $check = wp_check_filetype( $file['name'] );
            if ( $check['type'] && isset( self::ALLOWED[ $check['type'] ] ) ) {
                $real_mime = $check['type'];
            }
        }

        if ( ! isset( self::ALLOWED[ $real_mime ] ) ) {
            return new WP_Error( 'bad_file', 'Type de fichier non autorisé. JPG, PNG, GIF, WEBP ou PDF seulement.' );
        }
        $ext = self::ALLOWED[ $real_mime ];

        // Extra guard: reject files whose content sniffs as HTML/script.
        $head = file_get_contents( $file['tmp_name'], false, null, 0, 1024 );
        if ( $head !== false && preg_match( '/<\?php|<script|<html/i', $head ) ) {
            return new WP_Error( 'suspicious', 'Fichier rejeté pour raisons de sécurité.' );
        }

        // Store PRIVATELY: move the validated file into the delicat-private
        // directory (protected by .htaccess deny + index guard). We save ONLY the
        // random filename in the DB and stream it later through the
        // capability-checked proxy serve(). It is NEVER exposed as a public URL,
        // so payment screenshots (names, phone numbers, amounts) can't leak.
        $this->ensure_dir();

        // 32 hex chars + validated extension — matches the strict serve() regex.
        $name = bin2hex( random_bytes( 16 ) ) . '.' . $ext;
        $dest = self::dir() . '/' . $name;

        if ( ! @move_uploaded_file( $file['tmp_name'], $dest ) ) {
            return new WP_Error( 'move_failed', 'Impossible d’enregistrer le fichier.' );
        }
        @chmod( $dest, 0640 );

        // Return the private filename only (view_url() builds the admin proxy URL).
        return $name;
    }

    /**
     * Build the admin-only proxy URL to view a stored file.
     */
    public static function view_url( string $filename ): string {
        if ( empty( $filename ) ) return '';
        // If a legacy full URL was stored before this version, just return it.
        if ( filter_var( $filename, FILTER_VALIDATE_URL ) ) return $filename;

        return wp_nonce_url(
            admin_url( 'admin-post.php?action=delicat_view_proof&file=' . rawurlencode( $filename ) ),
            'delicat_view_proof'
        );
    }

    /**
     * Stream a private file to an authenticated administrator only.
     */
    public function serve(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Accès refusé.', 'Forbidden', [ 'response' => 403 ] );
        }
        check_admin_referer( 'delicat_view_proof' );

        $file = isset( $_GET['file'] ) ? wp_unslash( $_GET['file'] ) : '';
        // Strict whitelist: only hex name + known extension. Blocks path traversal.
        if ( ! preg_match( '/^[a-f0-9]{32}\.(jpg|png|gif|webp|pdf)$/', $file ) ) {
            wp_die( 'Fichier invalide.', 'Bad Request', [ 'response' => 400 ] );
        }

        $path = self::dir() . '/' . $file;
        if ( ! file_exists( $path ) ) {
            wp_die( 'Fichier introuvable.', 'Not Found', [ 'response' => 404 ] );
        }

        // Determine MIME type; fall back to extension map if finfo is unavailable.
        $mime = '';
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $mime = finfo_file( $finfo, $path );
                finfo_close( $finfo );
            }
        }
        if ( ! $mime ) {
            $ext_map = [ 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
                         'webp' => 'image/webp', 'pdf' => 'application/pdf' ];
            $ext  = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
            $mime = $ext_map[ $ext ] ?? 'application/octet-stream';
        }

        nocache_headers();
        header( 'Content-Type: ' . $mime );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: inline; filename="preuve-' . $file . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        readfile( $path );
        exit;
    }
}
