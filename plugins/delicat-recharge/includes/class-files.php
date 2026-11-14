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
        // 6.5.2: admin only (store() also calls it before every upload) -
        // it cost wp_upload_dir() and three file checks on every request.
        add_action( 'admin_init',      [ $this, 'ensure_dir' ] );
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
        if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            return new WP_Error( 'no_file', 'Aucun fichier valide reçu.' );
        }
        if ( (int) ( $file['size'] ?? 0 ) > self::MAX_SIZE || (int) filesize( $file['tmp_name'] ) > self::MAX_SIZE ) {
            return new WP_Error( 'too_large', 'Fichier trop grand (max 8 Mo).' );
        }

        // 6.7.0: the type comes ONLY from the file's own first bytes (magic number).
        // Before, a file the server could not recognise as an image fell back to its
        // NAME (« preuve.jpg ») and was accepted: an HTML page named .jpg was stored,
        // then served back to the administrator with the type the server sniffed
        // (text/html), i.e. a script running inside wp-admin. Never again: no image
        // or PDF signature, no upload. finfo, when present, must not disagree.
        $real_mime = self::sniff_mime( $file['tmp_name'] );
        if ( '' !== $real_mime && function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            if ( $finfo ) {
                $seen = (string) finfo_file( $finfo, $file['tmp_name'] );
                finfo_close( $finfo );
                // finfo says "application/octet-stream" for a few valid WEBP/PDF
                // variants; anything else must be the same family of type.
                if ( '' !== $seen && 'application/octet-stream' !== $seen && $seen !== $real_mime
                    && ! ( 0 === strpos( $seen, 'image/' ) && 0 === strpos( $real_mime, 'image/' ) ) ) {
                    $real_mime = '';
                }
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
     * 6.7.0: the type of a proof file from its first bytes only ('' when it is not
     * a JPEG, PNG, GIF, WEBP or PDF). Independent of the file name and of finfo.
     */
    public static function sniff_mime( string $path ): string {
        $head = @file_get_contents( $path, false, null, 0, 16 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- unreadable = rejected.
        if ( ! is_string( $head ) || strlen( $head ) < 12 ) {
            return '';
        }
        if ( 0 === strncmp( $head, "\xFF\xD8\xFF", 3 ) ) return 'image/jpeg';
        if ( 0 === strncmp( $head, "\x89PNG\r\n\x1A\n", 8 ) ) return 'image/png';
        if ( 0 === strncmp( $head, 'GIF87a', 6 ) || 0 === strncmp( $head, 'GIF89a', 6 ) ) return 'image/gif';
        if ( 0 === strncmp( $head, 'RIFF', 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) return 'image/webp';
        if ( 0 === strncmp( $head, '%PDF-', 5 ) ) return 'application/pdf';
        return '';
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

        // 6.7.0: the type is the one of the whitelisted extension, never what the
        // server sniffs from the content: a file stored by an older version that is
        // really HTML is then served as an image (inert), not as a page that runs
        // inside wp-admin. A file whose bytes are not that type is offered as a
        // download instead of being displayed.
        $ext_map = [ 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif',
                     'webp' => 'image/webp', 'pdf' => 'application/pdf' ];
        $ext     = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
        $mime    = $ext_map[ $ext ] ?? 'application/octet-stream';
        $inline  = ( self::sniff_mime( $path ) === $mime );

        nocache_headers();
        header( 'Content-Type: ' . ( $inline ? $mime : 'application/octet-stream' ) );
        header( 'Content-Length: ' . filesize( $path ) );
        header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="preuve-' . $file . '"' );
        header( 'X-Content-Type-Options: nosniff' );
        if ( 'application/pdf' !== $mime ) {
            // An image never needs scripts (the PDF viewer would refuse a sandbox).
            header( "Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox" );
        }
        readfile( $path );
        exit;
    }
}
