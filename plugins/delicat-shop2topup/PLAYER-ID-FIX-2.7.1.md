# Player ID verification update

Install this ZIP through WordPress > Plugins > Add New > Upload Plugin and replace the existing version. Install both updated plugins, then clear LiteSpeed/page caches and reload the product page or app.

Changes:
- Reject supplier responses that explicitly identify a different player ID. IDs are compared as strings, including IDs larger than 32 bits.
- Request uncached player lookup responses. This is a request to supplier caches, not a guarantee about their internal database.
- Invalidate older signed verification results on update.
- Clear verification when fields change; ignore replies from requests made before those changes.
- Preserve Unicode player names. The gateway now truncates long names by Unicode characters rather than bytes.

Important limitation: the plugin displays the name returned by the configured supplier. A successful response that echoes the correct UID but contains an outdated or incorrect nickname cannot be independently corrected from these files. No substitute name or hardcoded UID/name mapping was added. If UID 8593173765 still returns MERLINWzp, ask the supplier to refresh/correct its player lookup and provide the current in-game profile as evidence. A second website's screenshot alone cannot establish which lookup is current.

Validation uses local, mocked supplier responses. No live supplier request, recharge, wallet debit, or production-site test was performed.
