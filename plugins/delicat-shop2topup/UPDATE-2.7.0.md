# 2.7.0 — Product saving and Player ID card

Fixed hidden supplier numeric fields rejecting legacy zero IDs and blocking the WooCommerce form. Missing integration-panel submissions now preserve mappings. Unrelated blank products no longer acquire supplier metadata. Supplier mapping refresh stays asynchronous; fulfillment still requires positive mapped IDs.

Updated compact Material-style SVG card, smaller glyphs, responsive spacing, clear-history action beside the heading, visible keyboard focus, and 16px input text to avoid iOS focus zoom. No external font download.

Signed-in customers retain five encrypted recent account tuples per supplier category, newest first and deduplicated. Saving now occurs after successful supplier verification as well as add-to-cart. Server/zone context stays with the ID to avoid reusing an account in the wrong context. History remains private authenticated AJAX, not cached page HTML. Existing history and the history opt-out setting are preserved. Guests do not have persistent history in this release.

Install over the existing plugin without uninstalling. Clear page caches. Test updating an unrelated simple product, a variable product, and a mapped variation; confirm prices and mappings remain correct. Verify six accounts and confirm only the last five remain; repeat one account to check ordering, then test clearing history. No live supplier purchase is required for product-save testing.

PHP runtime and live WordPress validation were unavailable during packaging. JavaScript syntax and archive integrity were checked. Test on staging before production. If Update remains blocked, provide the browser console error or WooCommerce fatal-error log; other plugins may also affect saving.
