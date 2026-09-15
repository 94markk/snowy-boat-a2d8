# Delicat Builder V9 Pro — 9.2.0-pro.9

Install this ZIP through WordPress > Plugins > Add New > Upload Plugin and replace the installed version. Back up the plugin and database first. Purge LiteSpeed Cache, including its optimized CSS/JS cache, and Cloudflare if used. Settings and customer data are preserved.

This release keeps the Pro.8 navigation corrections and adds the following live-audit fixes:

- Load the 130 KB wallet tutorial only on the wallet route instead of storefront and catalogue pages.
- Skip the expensive session-state probe for anonymous visitors whose pages have no private state. Cart mutations still refresh state; signed-in, product, cart, checkout, account and wallet routes still initialize it.
- Add an eight-second client timeout and abort for state requests.
- Reject explicitly cross-site state requests, use the shared privacy-aware rate limiter, prohibit caching, suppress LiteSpeed response comments and remove the PHP disclosure header.
- Preserve the host Content Security Policy while adding `frame-ancestors 'self'`.
- Avoid replaying already-loaded inline configuration while swapping catalogue pages.

Product links continue to use native document navigation so WooCommerce, supplier Player ID validation and variation scripts start through their supported page lifecycle. Catalogue links reuse a single bounded prefetch/navigation request.

The package excludes historical audit and changelog documents. Runtime files and the SHA-256 integrity manifest remain included.

Cold server response time still depends on the hosting stack and page cache. After installation, purge caches and test home, category, product variation, Player ID validation, cart, checkout, wallet and login flows.
