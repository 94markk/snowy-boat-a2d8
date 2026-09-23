# Delicat Identity Pro 6.9.14 — mobile Google authority

Identity Pro is the only WordPress component that may exchange a Google ID
token for a Delicat mobile session.

## Required settings

1. Enable Google and the secure mobile API in Identity Pro.
2. Set **Client ID Web / serveur** to the Web OAuth client used as Flutter's
   `serverClientId`.
3. Set **Android Client ID** to the Android OAuth client for
   `com.delicatstore.app` and the production/Play signing certificate.
4. Keep the Google client secret only in Identity Pro. It is never returned to
   the app or to the public capabilities endpoint.

The app reads public client identifiers from
`/wp-json/delicat-identity/v1/mobile/capabilities`, obtains a short-lived
Google ID token, then posts it to
`/wp-json/delicat-identity/v1/mobile/google` with stable device and installation
identifiers. Identity Pro verifies the Google signature and claims before
issuing a device-bound access/refresh session.

Delicat App API accepts that session only through the request-bound
`delicat_app_resolve_external_token` filter. Password authentication remains
available through Delicat App API, but no separate Google bridge should be
installed or activated.
