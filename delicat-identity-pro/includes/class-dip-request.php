<?php
defined('ABSPATH') || exit;

/**
 * What is actually true about THIS request.
 *
 * Two questions the rest of the plugin kept answering with the wrong tool.
 *
 * 1. Is the customer's connection encrypted?
 *
 *    is_ssl() answers something narrower: whether PHP itself was reached over
 *    TLS. On this storefront TLS terminates at Cloudflare and at LiteSpeed, and
 *    the hop from there to PHP can be plain http - so is_ssl() can be false on a
 *    site that is https end to end for every customer alive.
 *
 *    The plugin already knew. DIP_Plugin::proxy_https_detected() was written for
 *    exactly this, and then deliberately confined to the admin diagnostic:
 *    "the OAuth gate itself still requires is_ssl()". That left every gate
 *    answering the wrong question, and each one fails differently:
 *
 *      - Google login reports itself unconfigured and the button disappears
 *        from the modal, from My Account and from checkout;
 *      - the OAuth redirect_uri is built as http://, which Google refuses;
 *      - two-factor verification refuses outright, so a customer with 2FA on
 *        cannot finish signing in at all;
 *      - the mobile API answers 403 to everything;
 *      - and wp_set_auth_cookie() is told the request is insecure, so the
 *        session cookie is issued WITHOUT the Secure flag on an https site.
 *
 *    The forwarded headers are read only when the site's own home URL is https.
 *    A plain-http install cannot be talked into believing otherwise by a header,
 *    and on an https install a forged header buys an attacker nothing they could
 *    not have by simply using https themselves. A chain of proxies is read from
 *    the client-facing end, because that is the hop the customer actually used.
 *
 * 2. Did this request come from our own pages?
 *
 *    Sec-Fetch-Site is set by the browser and cannot be forged from script. It
 *    is what lets a logout survive a nonce that a cache outlived, without
 *    opening the door to a cross-site logout.
 */
final class DIP_Request {

    /** Memoised per request; nothing here changes mid-request. */
    private static $secure = null;

    /** Is the site itself served over https, whatever this hop looks like? */
    public static function site_is_https() {
        $home = function_exists('home_url') ? (string) home_url() : '';
        return 'https' === strtolower((string) wp_parse_url($home, PHP_URL_SCHEME));
    }

    /**
     * Is the CUSTOMER's connection encrypted?
     *
     * @return bool
     */
    public static function is_secure() {
        if (self::$secure !== null) return self::$secure;

        if (is_ssl()) return self::$secure = true;

        if (!self::site_is_https()) {
            /** @param bool $secure */
            return self::$secure = (bool) apply_filters('dip_request_is_secure', false);
        }

        $secure = false;
        foreach (['HTTP_X_FORWARDED_PROTO', 'HTTP_X_FORWARDED_SCHEME'] as $header) {
            if (!isset($_SERVER[$header])) continue;
            /* A chain appends; the first entry is the hop facing the customer. */
            $parts = explode(',', (string) $_SERVER[$header]);
            if ('https' === strtolower(trim((string) $parts[0]))) { $secure = true; break; }
        }
        if (!$secure && isset($_SERVER['HTTP_X_FORWARDED_SSL']) && 'on' === strtolower((string) $_SERVER['HTTP_X_FORWARDED_SSL'])) {
            $secure = true;
        }
        /* Cloudflare states the customer's scheme here. */
        if (!$secure && isset($_SERVER['HTTP_CF_VISITOR']) && false !== strpos((string) $_SERVER['HTTP_CF_VISITOR'], '"scheme":"https"')) {
            $secure = true;
        }

        /** @param bool $secure */
        return self::$secure = (bool) apply_filters('dip_request_is_secure', $secure);
    }

    /**
     * True only when TLS ends before PHP, so a diagnostic can say so rather
     * than reporting a correctly-configured site as insecure.
     */
    public static function tls_terminated_upstream() {
        return !is_ssl() && self::is_secure();
    }

    /**
     * This request's URL, with the scheme the SITE is published under.
     *
     * The old current_url() took its scheme from is_ssl(), so behind a proxy it
     * produced http://... - which then became the OAuth redirect_uri, which
     * Google refuses because it does not match the registered one. The host is
     * taken from home_url(), never from the Host header, so a forged Host cannot
     * redirect an OAuth code anywhere.
     */
    public static function current_url() {
        $parts  = wp_parse_url(home_url('/'));
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host   = (string) ($parts['host'] ?? '');
        if ($host === '') return home_url('/');
        if (!empty($parts['port'])) {
            $port = absint($parts['port']);
            if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) $host .= ':' . $port;
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) wp_unslash($_SERVER['REQUEST_URI']) : '/';
        /* Only a site-relative path may be carried over; anything else is '/'. */
        if (!preg_match('#^/(?!/)#', $uri) || preg_match('/[\\\\\x00-\x20\x7f]/', $uri)) $uri = '/';
        return $scheme . '://' . $host . $uri;
    }

    /**
     * A top-level navigation the customer started from one of our own pages.
     *
     * Sec-Fetch-* is set by the browser itself and is unreachable from script,
     * so a cross-site page cannot produce `same-origin` and an <img> or <iframe>
     * cannot produce `document`/`navigate`. Where those headers are absent (an
     * older browser) a same-origin Referer is required instead. A request with
     * neither is refused: this is only ever used to RESCUE an action that a
     * nonce should have authorised, never to replace one.
     */
    public static function same_origin_navigation() {
        $site = isset($_SERVER['HTTP_SEC_FETCH_SITE']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_SITE'])) : '';
        if ($site !== '') {
            if ($site !== 'same-origin' && $site !== 'none') return false;
            $dest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_DEST'])) : '';
            $mode = isset($_SERVER['HTTP_SEC_FETCH_MODE']) ? strtolower(trim((string) $_SERVER['HTTP_SEC_FETCH_MODE'])) : '';
            /* 'none' is the user typing the URL or opening a bookmark - a real
               person, not a page. Anything else must be a document navigation. */
            if ($site === 'none') return true;
            return ($dest === '' || $dest === 'document') && ($mode === '' || $mode === 'navigate');
        }

        $referer = isset($_SERVER['HTTP_REFERER']) ? (string) wp_unslash($_SERVER['HTTP_REFERER']) : '';
        if ($referer === '') return false;
        $from = strtolower((string) wp_parse_url($referer, PHP_URL_HOST));
        $home = strtolower((string) wp_parse_url(home_url('/'), PHP_URL_HOST));
        return $from !== '' && $home !== '' && hash_equals($home, $from);
    }
}
