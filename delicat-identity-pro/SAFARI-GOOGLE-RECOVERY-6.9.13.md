# Delicat Identity Pro 6.9.13: Safari Google login recovery

This update fixes the storefront error:

> Cette connexion Google a expiré ou ne correspond pas à ce navigateur.

The existing plugin remains `delicat-google-login`; no additional Google
authentication plugin or new OAuth callback is introduced.

## Why the error occurred

The storefront handoff previously relied on a single WordPress-domain browser
cookie. Safari, embedded iOS browsers, and installations with a customized
`COOKIE_DOMAIN` or `COOKIEPATH` can drop or scope that cookie differently from
the site's public Home URL. The existing Google OAuth engine already protects
against this with a separate host-only cookie; the storefront handoff now uses
the same protection.

## Secure recovery

1. A state-specific primary browser cookie and an independent host-only cookie
   carry the same random secret. Both are Secure, HttpOnly, and SameSite=Lax.
2. Either cookie must match the flow's server-side HMAC.
3. If an embedded browser discards both cookies, continuation is allowed only
   when a valid authenticated WordPress customer session already exists and a
   distinct 256-bit same-origin continuation secret matches its server-side
   HMAC.
4. The original Google OAuth state, PKCE, nonce, signed identity, verified
   account ownership, TOTP policy, and customer-only checks remain mandatory.
5. The storefront credential is still atomic, single-use, state-bound, and
   expires after two minutes.
6. Invalid or expired attempts return safely to the storefront rather than
   leaving customers on a raw WordPress error page.

Upload the ZIP through WordPress Plugins → Add New Plugin → Upload Plugin,
choose to replace the installed Identity Pro version, then clear LiteSpeed and
Cloudflare page caches before testing Google sign-in again.
