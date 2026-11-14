# Confirmed player region checks

Upload this ZIP in WordPress > Plugins > Add New > Upload Plugin and replace the installed plugin. Update both integrations. Clear LiteSpeed/page caches and reload the website/PWA product page.

On confirmed region/server rejection the customer message is exactly:
Désolé, Ce compte est enregistré dans une autre région ou serveur.

Free Fire checks compare a recognized returned account region against an explicit region field or the configured supplier/product title (for example Free Fire LATAM or Free Fire Europe). LATAM and NA labels are treated as Americas for this Free Fire flow; Brazil and Europe remain distinct. Unknown/ambiguous product regions or missing response regions are not proof of a mismatch. Explicit provider region/server mismatch codes are preserved. The gateway app route returns supported:true, valid:false so supported clients can block the incorrect account. Old verification tokens/cache entries require a new lookup.

Nickname issue remains unresolved: the configured suppliers still supply the displayed nickname. A direct request to the official Garena player lookup during this update received a CAPTCHA challenge. No CAPTCHA bypass or automatic scraping integration was added. The plugins cannot establish the current nickname from a stale supplier response. The supplier needs to correct its lookup, or an authorized account-lookup API must be configured in a subsequent integration. No UID-to-name substitution was hardcoded.

Tests: isolated mocked supplier responses, wrong-region message, matching regions, LATAM/NA compatibility, unknown regions, app response classification, existing regression checks and stale browser response tests. The updated plugins were not installed or tested on the production site.
