Historical notes for 2.4.6. Superseded by INSTALLATION-2.4.8.md and AUDIT-2.4.8.md; the PHP tests have now been executed.

# Delicat 2.4.6

This update fixes identifiable recovery and mobile layout defects. The screenshot alone does not establish why the supplier returned INVALID_RESPONSE. Live server response diagnostics are required to confirm that cause.

Changes:
- Accept named price/account/order objects inside data, result or payload after validating HTTP status and success=true. Tolerate a UTF-8 BOM; HTML is never accepted as success.
- Handle malformed decimal price values as recoverable API failures, without purchasing.
- Always look up unknown/submitting orders, even with zero recorded attempts. Count failed recovery lookups and stop for review after the retry limit.
- Route due unknown/submitting records to the recovery worker, avoiding an endless batch lookup loop.
- Preserve accepted states when batch lookup reports an order missing or an operator retries it. Keep the same order UUID.
- Show safe metadata for non-JSON/missing-object responses. No raw body, keys, vouchers or player data is saved in this diagnostic.
- Allow horizontal scrolling of the order table on small screens.
- Explain callback authentication failure without claiming fallback synchronization is guaranteed.

Installation and recovery:
1. Back up the existing plugin and database, then upload this ZIP in WordPress > Plugins > Add New > Upload Plugin and replace the existing version.
2. In integration Settings, compare the webhook signing secret with the provider panel. This is distinct from the API key and the private URL token. Check that the signature header reaches WordPress unchanged. Keep secrets private.
3. Send a signed callback test from the provider panel. A local URL test alone does not prove that external callbacks work. Invalid signatures remain rejected.
4. Use Sync on the existing affected orders first. For unresolved queued/checking orders, use Retry once; it reuses the existing UUID and checks the provider before submission. Do not create replacement customer orders.
5. If INVALID_RESPONSE persists, capture the new timestamped diagnostic and the relevant WooCommerce log entry with debug logging enabled. Provider HTML responses, proxy blocks or malformed payloads require upstream/server investigation.

Validation: source reviewed and ZIP integrity checked. Regression cases added for nested envelopes, BOM, HTML access denial, sensitive-body exclusion, zero-attempt recovery, bounded lookup retries and invalid prices. They were NOT executed: PHP is unavailable in this environment. No live WordPress or supplier transaction was performed. Validate on staging before production.

Contract references checked:
https://shop2topup.com/en/reseller-api/docs
https://shop2topup.com/en/reseller-api/docs/catalog
https://shop2topup.com/en/reseller-api/docs/orders
