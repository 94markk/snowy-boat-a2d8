<?php
/**
 * Static check: every AJAX and admin-post endpoint proves who is calling and
 * that they meant to.
 *
 * A WordPress endpoint is a URL anyone can POST to. Two questions have to be
 * answered inside the handler, because nothing else answers them:
 *
 *   authorisation  is this caller allowed to do this?
 *   intent         did this caller mean to do it, or did another site make
 *                  their browser do it? (that is what a nonce is for)
 *
 * Miss the first and a subscriber can change your settings. Miss the second and
 * a page the customer merely visits can act as them.
 *
 * Every registration therefore needs both, or an entry in ALLOWED below stating
 * why it does not. The point of the allowlist is that adding to it is a decision
 * someone writes down, rather than something that slips in.
 *
 * Usage: php scripts/check-endpoint-auth.php [delicat-builder-v9]
 * Exit code 1 when an endpoint has neither the checks nor a stated reason.
 */
$root = $argv[1] ?? 'delicat-builder-v9';

/*
 * Endpoints that need no nonce, no capability, or neither — each with the
 * reason it is safe. Keyed by "action::callback".
 */
$ALLOWED = array(
    'wp_ajax_nopriv_delicat_builder_v9_review_prompt::ajax_prompt_guest' =>
        'Returns the constant {due:false} to logged-out callers. Reads nothing, writes nothing, and takes no input.',

    'wp_ajax_delicat_builder_v9_review_prompt::ajax_prompt' =>
        'Read-only, and reads only the caller\'s own session: get_current_user_id() with no id accepted from the request. A cross-site caller cannot read the reply.',

    'wp_ajax_nopriv_delicat_builder_v9_notifications_list::ajax_list' =>
        'Read-only list of the site-wide announcements the bell shows everyone. Per-user read state is added only for a logged-in session; a guest gets none.',

    'wp_ajax_delicat_builder_v9_notifications_list::ajax_list' =>
        'Same read-only list. Nothing in it is keyed by anything the request supplies.',

    /*
     * The three below act on the caller's OWN session and accept no id from the
     * request, so there is no second party to authorise against - the session
     * is the resource. All three are nonce-checked and rate-limited, which is
     * the part that actually matters for them.
     */
    'wp_ajax_delicat_builder_v9_cart_snapshot::ajax_cart_snapshot' =>
        'Reads WC()->cart for the caller\'s own session. No cart id is accepted from the request. Nonce-checked, 60/min.',
    'wp_ajax_nopriv_delicat_builder_v9_cart_snapshot::ajax_cart_snapshot' =>
        'Same handler, guest session. A guest has a cart too, and it is theirs.',

    'wp_ajax_delicat_builder_v9_clear_cart::clear_cart' =>
        'Empties WC()->cart for the caller\'s own session. POST only, nonce-checked, 10/min. No id is accepted.',
    'wp_ajax_nopriv_delicat_builder_v9_clear_cart::clear_cart' =>
        'Same handler, guest session.',

    'wp_ajax_delicat_builder_v9_product_search::ajax_product_search' =>
        'Searches the public catalogue: post_type = product AND post_status = publish, term bound with %s. Returns nothing a visitor cannot already see. Nonce-checked, 120/min.',
    'wp_ajax_nopriv_delicat_builder_v9_product_search::ajax_product_search' =>
        'Same handler. The shop is public, so its search is too.',
);

/* Authorisation, including this plugin's own stronger gates. */
$AUTH = array(
    'current_user_can', 'is_user_logged_in', 'manage_options',
    'can_manage_global_security', 'require_recent_for_security_change',
    'ajax_mutation_allowed', 'rest_auth',
);

/*
 * The other way this codebase says "you must be signed in": read the current
 * user id and refuse when there is not one. Matched as the pair, because
 * get_current_user_id() on its own reads an id - it does not gate on it.
 */
$AUTH_PATTERN = '/get_current_user_id\s*\(\s*\)/';
$AUTH_BAIL    = '/\$user_id\s*(?:<=\s*0|<\s*1|>\s*0)/';
$NONCE = array( 'check_ajax_referer', 'wp_verify_nonce', 'check_admin_referer' );

/* ---- collect the files ---------------------------------------------------- */
$files = array();
$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $it as $f ) {
    if ( $f->isFile() && 'php' === strtolower( $f->getExtension() ) ) {
        $files[ $f->getPathname() ] = (string) file_get_contents( $f->getPathname() );
    }
}

/** Body of a named method or function, by brace matching. */
function body_of( string $src, string $name ): string {
    if ( ! preg_match( '/function\s+' . preg_quote( $name, '/' ) . '\s*\(/', $src, $m, PREG_OFFSET_CAPTURE ) ) {
        return '';
    }
    $open = strpos( $src, '{', $m[0][1] + strlen( $m[0][0] ) );
    if ( false === $open ) { return ''; }
    $depth = 0;
    for ( $i = $open, $n = strlen( $src ); $i < $n; $i++ ) {
        if ( '{' === $src[ $i ] ) { $depth++; }
        elseif ( '}' === $src[ $i ] ) { $depth--; if ( 0 === $depth ) { return substr( $src, $open, $i - $open ); } }
    }
    return '';
}

function mentions( string $blob, array $needles ): bool {
    foreach ( $needles as $n ) {
        if ( false !== strpos( $blob, $n ) ) { return true; }
    }
    return false;
}

/* ---- check every registration -------------------------------------------- */
$checked = 0; $issues = 0; $stale = array_fill_keys( array_keys( $ALLOWED ), true );

$re = "/add_action\(\s*'((?:wp_ajax_(?:nopriv_)?|admin_post_(?:nopriv_)?)[a-z0-9_]+)'\s*,\s*"
    . "(?:array\(\s*(?:__CLASS__|self::class|'[A-Za-z_0-9]+')\s*,\s*'([a-zA-Z_0-9]+)'\s*\)|'([a-zA-Z_0-9]+)')/";

foreach ( $files as $path => $src ) {
    if ( ! preg_match_all( $re, $src, $all, PREG_SET_ORDER ) ) { continue; }

    foreach ( $all as $m ) {
        $action = $m[1];
        $cb     = '' !== ( $m[2] ?? '' ) ? $m[2] : ( $m[3] ?? '' );
        if ( '' === $cb ) { continue; }
        $checked++;

        $own  = body_of( $src, $cb );
        $blob = $own;
        if ( '' === $blob ) {
            printf( "%s\n  registers '%s' but %s() is not defined in this file\n", $path, $action, $cb );
            $issues++;
            continue;
        }

        /* One level of guard helper: a handler that opens by delegating its
           checks is doing the right thing, and the calls live in the helper. */
        if ( preg_match_all( '/self::([a-z_0-9]+)\(/', substr( $blob, 0, 600 ), $helpers ) ) {
            foreach ( $helpers[1] as $h ) { $blob .= body_of( $src, $h ); }
        }

        $has_nonce = mentions( $blob, $NONCE );

        /*
         * Named gates count wherever they appear, including inside a guard
         * helper the handler opens by calling. The get_current_user_id pattern
         * counts only in the handler's OWN body: the same two tokens turn up
         * inside a rate limiter, and a rate limiter authorises nothing.
         */
        $has_auth = mentions( $blob, $AUTH )
            || ( preg_match( $AUTH_PATTERN, $own ) && preg_match( $AUTH_BAIL, $own ) );
        $key       = $action . '::' . $cb;

        if ( isset( $ALLOWED[ $key ] ) ) {
            unset( $stale[ $key ] );
            if ( $has_nonce && $has_auth ) {
                printf( "%s\n  '%s' now checks both, so its allowlist entry is dead. Remove it.\n", $path, $action );
                $issues++;
            }
            continue;
        }

        if ( ! $has_nonce || ! $has_auth ) {
            $missing = array();
            if ( ! $has_nonce ) { $missing[] = 'no nonce check (CSRF)'; }
            if ( ! $has_auth )  { $missing[] = 'no authorisation check'; }
            printf(
                "%s\n  '%s' -> %s(): %s\n  Add the check, or add \"%s\" to ALLOWED in this script with the reason it is safe.\n",
                $path, $action, $cb, implode( ', ', $missing ), $key
            );
            $issues++;
        }
    }
}

foreach ( array_keys( $stale ) as $key ) {
    printf( "allowlist entry for an endpoint that no longer exists: %s\n", $key );
    $issues++;
}

printf( "\nendpoints checked: %d   allowlisted: %d   unprotected: %d\n", $checked, count( $ALLOWED ), $issues );
exit( $issues > 0 ? 1 : 0 );
