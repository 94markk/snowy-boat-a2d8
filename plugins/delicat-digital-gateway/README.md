# Delicat Digital Gateway v4.20.0 — Fusion : la 4.19.0 de la boutique + les correctifs d'audit 4.15.0

Cette version **réunit deux travaux** : la 4.19.0 installée sur le site (carte Player ID
moderne, identifiants récents, synchronisation rapide du stock fournisseur, interrupteur
« Supplier stock sync », solde en direct) **et** les correctifs de sécurité et de
compatibilité avec l'application mobile livrés dans notre 4.15.0. Rien n'a été retiré
de la 4.19.0.

## Ce que la 4.19.0 apporte et qui est conservé

- Carte Player ID repensée, glyphes SVG intégrés, 5 derniers identifiants mémorisés
  (compte WordPress pour les clients connectés, navigateur pour les visiteurs), effacement
  des identifiants enregistrés par une action authentifiée.
- Synchronisation rapide du stock fournisseur (planification `dfr_30_seconds`, lots
  enchaînés toutes les 5 s) et interrupteur **Supplier stock sync** par produit et par
  variation.
- Une réponse fournisseur incomplète ou inconnue **ne remet jamais un produit en stock**
  et ne marque « manquant » qu'une liste complète.
- Solde fournisseur en direct dans Catalog Studio, rafraîchi après un événement de
  commande signé.
- Webhooks : l'événement `order.created` est accepté et une reprise durable est planifiée
  tant que la commande locale n'existe pas ou que le statut canonique n'a pas suivi.
- Vérification Player ID : une réponse définitive du fournisseur reste une réponse
  (HTTP 422 `dapi_uid_invalid` côté application), jamais une panne déguisée.

## Ce que l'audit 4.15.0 remet en place

- **Application mobile** : les filtres `delicat_app_order_codes` et
  `delicat_app_order_delivery` et les actions `dfr_delivery_ready` /
  `dfr_delivery_failed` sont de nouveau enregistrés. Sans eux le client ne reçoit
  aucune notification « Votre code est prêt » et ne voit jamais son code livré dans
  l'application. `dfr_non_digital_line_fulfilled` et le filtre
  `delicat_dfr_cart_line_available` sont également rétablis.
- **Compte introuvable** : la route de l'application répond de nouveau clairement
  qu'un compte est introuvable (`valid: false`, message en français), en plus du
  422 `dapi_uid_invalid` de la 4.19.0 que l'application 4.2 comprend aussi.
- **Vérification manuelle en cas d'échec définitif du fournisseur** (règle de la
  boutique) : commande **En attente**, client **et** administrateur prévenus, bouton
  « Relancer la livraison ». **Jamais de remboursement automatique.**
- **API revendeur** : aucun remboursement automatique après une tentative au résultat
  inconnu (c'était le cas du double paiement) ; verrou de portefeuille partagé avec le
  site et l'application.
- **Trousseau de clés `DELICAT_DFR_KEYS`** et sa migration en arrière-plan : les codes
  et secrets ne dépendent plus des « salts » WordPress ni de l'adresse du site.
- **Route webhook de maintenance** et correction de la désinstallation.
- Disponibilité fournisseur vérifiée à l'achat, synchronisation par lots, suivi des
  champs de recharge, surveillance de la marge, réglages sensibles réservés aux
  administrateurs avec journal.

## À faire après la mise à jour

1. Ajouter `DELICAT_DFR_KEYS` dans `wp-config.php` (voir la section 4.15.0 plus bas) et
   attendre que l'avis de la page FazerCards affiche 0 avant de changer les salts ou le
   domaine.
2. Vider le cache LiteSpeed et le CDN, puis vérifier
   `/wp-json/delicat-app/v1/config`.
3. Construire les versions suivantes **à partir de cette 4.20.0**, sinon les correctifs
   ci-dessus disparaîtront de nouveau.

Tests : `php tests/sync-regression.php`, `php tests/delivery_smoke.php`,
`php tests/crypto_smoke.php`, `php tests/catalog_spec_smoke.php`.

---

# Version 4.19.0 — Modern Player ID experience

## What changed in 4.19.0

- Rebuilt the native Player ID card with a responsive, touch-friendly layout.
- Replaced emoji controls with embedded Material-style SVG glyphs; no external icon font or render-blocking request is added.
- Remembers the five most recently used Player IDs, newest first and without duplicates.
- Signed-in customers keep their history securely in their WordPress account; guests receive a same-device browser fallback.
- Recent-ID selection fills the Player ID field but deliberately clears the previous verification token, requiring verification for the selected product/region.
- Customers can clear their saved IDs. The server action is authenticated and nonce-protected.
- Only the Player ID is remembered; server IDs, emails, API credentials, and supplier payloads are excluded.

## Design recommendations

- Keep this card directly below variation/package selection so the price choice remains the first decision.
- Avoid a second Player ID-history component in the theme/builder; this gateway is now the authoritative recent-ID UI.
- Keep the purple gradient as the primary action color and reserve red for validation failures only.
- Do not load Google Material Symbols globally: the embedded glyphs are faster on low-end phones and avoid layout shifts.
- Keep one primary action, **Vérifier votre compte**, before Add to cart/Buy now becomes visually prominent.

Built from the uploaded 4.17.0 fast supplier synchronization release. The player-ID verification implementation is preserved.

## What changed in 4.18.0

- An authenticated supplier order event immediately invalidates and refreshes the displayed supplier balance.
- Only imported products and variations with a complete supplier mapping can receive supplier stock changes.
- Every imported product/variation now has a **Supplier stock sync** switch. Turn it off for products whose stock must remain manually controlled by WooCommerce.
- An unknown or incomplete availability response preserves the current WooCommerce stock state; it can no longer falsely restock a product.
- A missing offer is marked out of stock only when the supplier response is complete and the product's stock-sync switch is enabled.
- Supplier completion still updates WooCommerce immediately through the signed webhook plus a canonical server-to-server verification; webhook data alone never validates an order.

## Installation

1. Back up the installed plugin and database. In WordPress > Plugins > Add New > Upload Plugin, upload this ZIP and replace the existing Delicat Digital Gateway plugin. Do not uninstall first.
2. This update enables automatic supplier stock sync, the 30-second schedule, missing-offer protection and WooCommerce automatic completion once. It does not enable live purchasing or automatic fulfillment if those were disabled. Subsequent setting changes are respected.
3. Open the plugin's Credentials & webhook security panel. Click **Configure & test FazerCards webhook** (or **Test existing webhook** when already configured). Confirm **Enabled & URL matched** and **Signed delivery test: Passed**.
4. Keep a reliable server cron runner for WordPress. Ordinary WP-Cron depends on incoming uncached requests; it cannot guarantee a 30-second run on an idle or fully cached store. A hosting cron every minute gives approximately minute-level background dispatch, not a 30-second guarantee. The immediate verified webhook order path does not wait for cron.
5. Check one top-up without stock quantity and one tracked gift card in the storefront. Verify a paid test order reaches Completed after supplier completion, and verify its delivery codes if applicable.

## Behavior and timing

- Balance refresh: opens automatically in Catalog Studio; polls every 10 seconds while visible. The shared server cache prevents duplicate account reads across tabs. Background balance refresh is scheduled every 30 seconds. The last checked time is displayed; failed refreshes keep the last known balance and label it delayed. Supplier balance is read-only and does not alter customer wallets.
- Catalog: the default schedule is 30 seconds, with bounded continuation batches scheduled 5 seconds apart. Each batch reads up to 100 imported products and at most 10 supplier categories, with a soft 12-second processing budget between categories. Large catalogs require several batches; the full catalog does not necessarily refresh in 30 seconds. API latency, rate limits and cron dispatch add delay.
- Stock counts are used only when numeric and non-negative. Missing, null, invalid or negative sentinel quantities are not treated as zero. Unknown availability preserves the current state. Explicit true/false availability can restock/block an untracked offer. Explicit zero quantity blocks sales and disables backorders.
- Only imported products are scanned. Manual selling prices and publication/draft state are preserved. Variable parent stock is synchronized after child updates.
- Missing offers are blocked only after a valid complete category response. Partial, malformed, mismatched-category responses, transport failures and account errors do not mass-disable products. Supplier Retry-After is honored for balance and catalog reads.
- Orders: verified order-created and status-change callbacks immediately attempt a canonical supplier GET and local reconciliation. Callbacks arriving before the local mapping exists, or before canonical status catches up, now retain a durable retry. Payment validation, encrypted delivery, order binding, idempotency and existing fallback reconciliation remain in place. Multi-item orders complete only when existing fulfillment conditions are satisfied; code products still require delivery codes.

The supplier documents order events, not stock/balance push events. Therefore this release uses webhooks for orders and bounded polling for stock/balance. It cannot promise zero-delay synchronization or speed up the supplier's own fulfillment. External page/CDN caches can continue showing an old product page; configure those caches to purge product pages on WooCommerce product updates.

## Validation

- 40 offline PHP regression assertions passed, covering stock types, availability overrides and recovery, malformed/partial catalogs, all 625 mock imports across page/category boundaries, protected manual prices, API outages, rate limits, zero/stale balance and early webhook retries.
- All 10 PHP files passed PHP 8.3 syntax checks. Admin JavaScript passed Node syntax checking.
- Run the included CLI-only harness with: `php tests/sync-regression.php`.
- No live WordPress/WooCommerce instance or authenticated supplier account was available. No real purchase, webhook delivery, host-cron execution or live cache invalidation was tested.

Supplier references checked on 2026-09-22:
- https://reseller.fazercards.com/en/docs
- https://reseller.fazercards.com/en/docs/webhooks
- https://github.com/FZR-cards/fazercards-python

---

# Delicat Digital Gateway v4.15.0 — Livraisons fiables, codes dans l'application, clés dédiées

## Ce qui change pour la boutique

- **Plus de commandes bloquées « En cours ».** La livraison est relancée après le paiement (portefeuille, passerelles, page de remerciement), les reprises ne sont plus perdues (Action Scheduler dédoublonnait sans regarder la commande), un veilleur relance les commandes payées non envoyées, et une commande mixte (carte cadeau + autre produit) peut se terminer.
- **Échec définitif chez le fournisseur = vérification manuelle** (choix de la boutique) : la commande passe **En attente**, le client est prévenu (« aucun nouveau paiement n'est nécessaire »), l'administrateur reçoit un e-mail et un bouton « Relancer la livraison ». Aucun remboursement automatique.
- **Codes cartes cadeaux dans l'application** : l'App API 2.8.0 reçoit les codes livrés (filtre `delicat_app_order_codes`) et l'état de livraison (`delicat_app_order_delivery`), limités à 60 lectures / 10 min par client.
- **Réglages sensibles réservés aux administrateurs** (`manage_options`) avec un journal des modifications (les secrets n'y apparaissent jamais).

## Catalogue fournisseur, marge et API revendeur

- **Disponibilité vérifiée à l'achat.** À l'ajout au panier et au paiement (site, application, API revendeur), l'offre est comparée à la liste en direct du fournisseur (cache 60 s, délai 5 s). Offre retirée ou stock épuisé : l'achat est refusé avec un message clair (`delicat_item_unavailable` dans l'application). Si le fournisseur ne répond pas, la vente continue : un échec de livraison passe en vérification manuelle.
- **Synchronisation par lots.** Chaque catégorie importée est synchronisée à tour de rôle (5 par lot en arrière-plan, 20 s par lot, Action Scheduler ou WP-Cron). Un produit n'est enregistré que s'il a changé et l'application ne reçoit qu'une mise à jour de catalogue par lot. Une erreur fournisseur ou une liste vide ne désactive rien ; retirer plus de la moitié d'une catégorie demande deux réponses identiques de suite. Un avis signale une synchronisation en retard.
- **Champs des recharges.** La synchronisation suit les champs demandés par le fournisseur : un nouveau champ est ajouté à la correspondance, un champ retiré devient facultatif (un avis liste les produits concernés). Les options des listes sont enregistrées avec leur vraie valeur (`os_usa`, pas « America »).
- **Imports.** Les catégories ne sont attribuées qu'à la création d'un produit, et les options existantes d'un produit variable gardent leur nom : les paniers en cours ne perdent plus leurs lignes. En tarification automatique, la devise et les décimales sont celles de la boutique, et un prix fournisseur absent ou nul ne donne jamais un prix de vente.
- **Surveillance de la marge** (Catalog Studio → Pricing & Sync, administrateurs uniquement, chaque modification est journalisée) : un taux de surveillance (HTG pour 1 USD) et une marge minimale, comparés au dernier coût fournisseur connu. Alerte par e-mail et avis d'abord ; le **blocage** des ventes sous la marge est facultatif (`delicat_item_on_hold`). La synchronisation automatique doit être active pour que les coûts soient à jour.
- **Player ID.** Un compte introuvable est refusé dans l'application (réponse `valid: false`) ; cette réponse est retenue 60 s ; une vérification réussie dans l'application sert à l'ajout au panier qui suit ; un budget commun de 60 vérifications par minute (filtre `dfr_uid_validation_budget_per_minute`) protège le quota fournisseur des commandes payées. Le jeton du site n'est plus lié à l'adresse IP ni au navigateur.
- **API revendeur.** Même verrou de portefeuille que l'application et le site. Aucun remboursement automatique après une tentative au résultat inconnu : la commande reste « supplier_unknown » et l'administrateur reçoit un e-mail après 30 minutes sans commande fournisseur. Les autres remboursements automatiques du portefeuille revendeur (refus définitif dès la première tentative, commande fournisseur échouée ou remboursée) sont conservés en attendant la décision de la boutique.

## Trousseau de clés (recommandé)

Les codes et secrets sont chiffrés (XChaCha20-Poly1305 / AES-256-GCM). Jusqu'ici la clé dérivait des « salts » WordPress et de l'adresse du site : **changer les salts ou le domaine rendait tous les codes illisibles**.

Ajoutez dans `wp-config.php` (32 octets aléatoires, encodés base64) :

    define( 'DELICAT_DFR_KEYS', 'k1:REMPLACEZ_PAR_32_OCTETS_EN_BASE64' );

Générer une clé : `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`

- Seules les **nouvelles** données sont écrites en v3 (`x3:k1:…`), indépendantes des salts et du domaine.
- Les données écrites **avant** l'ajout de la constante (codes livrés, instantanés de livraison, clé API et secret webhook fournisseur, commandes et secrets de l'API revendeur) dépendent encore des salts. Elles sont re-chiffrées **en arrière-plan** après l'ajout de `DELICAT_DFR_KEYS` (migration lancée à la visite d'un administrateur, option `dfr_keyring_migration_v1`). L'avis de la page FazerCards affiche le nombre de valeurs restantes.
- **Ne changez pas les salts et ne changez pas l'adresse du site tant que cet avis n'affiche pas 0.**
- API revendeur : ses enveloppes suivent la même migration (option `dfr_reseller_keyring_migration_v1`). Les empreintes des clés API et des secrets d'installation (« peppers ») restent liées aux salts jusqu'à la prochaine utilisation de chaque clé par son revendeur ; la page Reseller API affiche ce qui reste.
- Rotation : mettez la nouvelle clé en premier et gardez l'ancienne ensuite, `'k2:NOUVELLE,k1:ANCIENNE'`.
- **Sauvegardez cette ligne hors du serveur** : sans elle, les données v3 sont perdues.
- **Retour à une version antérieure** : les versions 4.14.2 et plus anciennes ne lisent pas le v3. Revenir à l'une d'elles après l'ajout du trousseau cache tous les codes, instantanés et secrets écrits depuis ; réinstaller la version actuelle les rend de nouveau lisibles. Gardez la constante dans tous les cas.
- La page FazerCards affiche un avertissement tant qu'aucun trousseau n'est configuré, et une alerte rouge si les salts ont changé.

Tests : `php tests/delivery_smoke.php`, `php tests/crypto_smoke.php` et `php tests/catalog_spec_smoke.php`.

---

## Earlier release notes (historical)

# Delicat Digital Gateway v4.14.3 — Player ID Verification Contract Alignment

This release re-reads the official FazerCards documentation
(https://reseller.fazercards.com/en/docs, the OpenAPI spec and the official Node SDK)
and fixes the storefront error **“Impossible de vérifier ce Player ID pour le moment”**
that appeared even when the supplier was healthy.

## Root causes found against the documented contract

1. **HTTP 400 is a business-rule answer, not a stale schema.** FazerCards documents
   `400` as “validation or business rule; body explains the problem” and errors as
   `{ok:false, error, code?}`. For `POST /topups/validate-id` that means *this Player
   ID was not accepted* (wrong ID or wrong region). The plugin treated every
   `400/404/409/422` as a stale schema: it silently refreshed the validation catalog,
   re-sent the request, then told the customer the *service* was unavailable and to
   retry. A rejected ID therefore looked exactly like an outage, and every click cost
   up to three supplier calls against the shared 120/min “other routes” quota.
2. **Same-game regional validation categories were resolved arbitrarily.** The SDK
   documents that validation ids (`free_fire`, `pubg_mobile`, …) are a separate
   namespace from sellable catalog ids. When FazerCards advertises several Free Fire
   validation entries, all of them scored 100 in the game-family matcher and the
   winner depended on API order (and `uasort()` is not stable on PHP 7.4). A LATAM
   product could be validated against another region’s validator and answer
   “not found”, while the admin diagnostic — which always prefers `free_fire` —
   reported healthy.
3. **Incoherent time budget.** An 8-second supplier timeout plus an 8-second retry
   could never finish before the 12-second browser abort, so the recovery answer was
   discarded and customers clicked again. The official SDKs default to 30 seconds and
   FazerCards notes that publisher-side ID checks can add delay.

## v4.14.3 changes

- Classifies every failed validation into one documented outcome —
  `player_rejected`, `schema_rejected`, `config` (401/403 key or plan), `rate_limited`,
  `unavailable` — using the HTTP status, the machine `code` and a digit-stripped keyword
  hint from the human `error` text. The raw provider message is never stored or logged.
- Storefront messages now match the outcome: a rejected ID shows
  “Player ID introuvable ou non reconnu…”, a schema/plan problem shows a
  “vérification indisponible pour ce produit” notice, and the “réessayez” wording is
  reserved for genuine transport/5xx failures. The Delicat App API returns
  `dapi_uid_invalid` (422) for rejections instead of a 503 outage.
- Never retries a definitive rejection; retries once only for transient failures
  (or one schema refresh) and only while enough of the budget remains.
- One coherent budget: 18 s server-side (12 s per attempt, recovery only if ≥ 6 s
  remain), 22 s browser abort, 20 s for background revalidation before a purchase.
- Region-aware, deterministic validation-category ranking: exact id → explicit
  override → region-matched family entry → region-neutral (global) entry → canonical
  id; a different-region validator is never chosen for a region-pinned product.
- Diagnostic (WooCommerce → FazerCards → Security) now reports `ok:true, valid:false`
  as “server online, ID rejected”, shows the provider hint, and lists which validation
  category every imported Free Fire product resolves to on the storefront so a mapping
  mismatch is visible next to the server test.
- Refreshes the cached validation catalog once on upgrade. No Player IDs, API keys or
  secrets are persisted; verification still never creates an order or debits balance.

### How to read the WooCommerce log after upgrade

Entries `Player ID validation request failed` now carry `outcome=`:
`player_rejected` (customer input), `schema_rejected` (mapping/category — check the
Storefront mapping step of the diagnostic or set a per-product validation override),
`config` (API key/plan), `rate_limited`, `unavailable` (FazerCards or its upstream).

---

# Delicat Digital Gateway v4.14.2 — Player ID Server Diagnostic

This update adds a secure, read-only diagnostic inside **WooCommerce → FazerCards → Security** so the store can distinguish a Delicat mapping problem from a FazerCards/Free Fire validation outage without exposing the supplier API key.

## v4.14.2 changes

- Adds **Test FazerCards Player ID server** with a temporary Free Fire Player ID field.
- Runs four bounded server-to-server checks in order: authenticated `GET /me`, live `GET /topups/validate-id`, Free Fire capability/schema discovery, then read-only `POST /topups/validate-id`.
- Uses the validation category and Player ID field advertised by FazerCards' current live validation catalog instead of hardcoding the storefront product mapping.
- Classifies failures as credential/plan access, rate limiting, Player ID rejection, schema rejection, upstream/network unavailable, missing Free Fire capability, or healthy.
- Retries one transient Player ID read once; it never retries authentication/plan errors, throttling, or a normal invalid-player rejection.
- Never creates an upstream order, never debits FazerCards balance, and never writes the entered Player ID to options, transients, logs, order metadata, or the displayed diagnostic report.
- Keeps the v4.14.1 storefront recovery path unchanged.

### How to use

1. Open **WooCommerce → FazerCards → Security**.
2. Enter the same Free Fire Player ID that fails on the product page.
3. Click **Test FazerCards Player ID server**.
4. Read the four-stage result. `healthy` confirms the supplier validator works and the remaining issue is local mapping/UI; `upstream_unavailable`, persistent `5xx`, or transport failure points to FazerCards or its upstream Free Fire provider; `credentials_or_plan` points to API-account access; `free_fire_not_listed` means FazerCards' live validation catalog is currently not advertising Free Fire.

---

# Delicat Digital Gateway v4.14.1 — Player ID Validation Recovery

This hotfix targets the storefront error **“Impossible de vérifier ce Player ID pour le moment”** when the supplier validator is reachable only intermittently or its dynamic validation category/schema changed while WordPress still had a cached copy.

## v4.14.1 changes

- Keeps the official FazerCards `POST /topups/validate-id` request format and server-side API-key boundary unchanged.
- Refreshes the dynamic validation category/field catalog and retries once when the supplier rejects a stale category/schema.
- Retries once for bounded transient connection/5xx failures, but never amplifies API-key/plan errors (`401/403`) or supplier throttling (`429`).
- Shortens the interactive supplier timeout to 8 seconds and adds a 12-second browser abort so the product page cannot remain stuck indefinitely.
- Adds safe WooCommerce log diagnostics (`HTTP`, internal error code and provider machine code) without logging Player IDs, field values, API keys, tokens or secrets.
- Clears legacy UID validation caches once on upgrade.
- Applies the same recovery path to storefront verification and server-side reseller/cart revalidation; verification never charges a wallet or creates an upstream order.

If the error remains and the WooCommerce log reports HTTP `401` or `403`, the code path is working but the FazerCards API key/subscription does not currently have access to the validation route. If it reports `429`, wait for the supplier quota window. Persistent `5xx`/transport errors are upstream/network health issues.

---

# Delicat Digital Gateway v4.14.0 — Redundant Canonical Synchronization

This deep-audit rebuild addresses the confirmed failure mode where FazerCards
finishes and delivers a direct top-up but WooCommerce retains the earlier
`processing` snapshot. The supplier purchase is not repeated: every recovery
path is GET-only and is bound to the existing `ord-*` id, paid WooCommerce order,
and exact line item.

## Fault finding

The supplied evidence shows FazerCards completed and delivered `ord-848712`,
while the WooCommerce line item still stored `_dfr_remote_status=processing` and
had no later canonical observation. That places the demonstrated fault in the
WordPress/WooCommerce observation pipeline (missed/blocked webhook or delayed
background execution), not in FazerCards fulfillment. Production webhook
delivery history is still the final way to distinguish a blocked callback from
a stalled host runner.

## v4.14.0 changes

- Pre-arms a deterministic ten-minute plan of independent canonical
  `GET /orders/{id}` checkpoints before low-latency workers begin. A running job
  can no longer suppress or become its own successor.
- Adds two recurring, rate-bounded watchdog triggers (Action Scheduler and
  WP-Cron) that repair recent `Processing`/`On-hold` orders and completed code
  orders whose delivery material has not arrived; the same watchdog also repairs
  in-flight orders created through the optional reseller API bridge.
- Adds an owner-only storefront heartbeat for direct top-ups, gift cards, and
  game keys. It uses an order-bound nonce, strict account ownership, wallet
  proof, exact remote-item binding, and per-account/per-remote rate controls.
- Opening the exact order as an authorized WooCommerce administrator performs
  one canonical recovery read, with the same anti-amplification controls.
- Consolidates legacy, loopback, post-response, webhook, queue, plan, watchdog,
  customer, and administrator reads into one serialized canonical apply path.
- Aligns active polling to the official five-second wait cadence and enforces a
  shared 100-read/minute reserve below the supplier status endpoint ceiling.
- Records which path observed the terminal state and why WooCommerce completion
  was blocked, without storing supplier secrets or customer delivery payloads.
- Strengthens webhook verification with raw-body HMAC-SHA256, constant-time
  comparison, timestamp/replay limits, optional header/body identity matching,
  and a fresh canonical API read before any state mutation.
- Keeps credentials and delivered codes encrypted at rest with contextual AEAD
  (XChaCha20-Poly1305, or AES-256-GCM fallback) and requires HTTPS/TLS
  certificate verification for supplier requests.
- Runs a bounded upgrade repair pass for recent in-flight orders. It can never
  submit or charge a second supplier order.

## Required production check after upgrade

1. Open **WooCommerce → FazerCards → Security**.
2. Click **Configure & test FazerCards webhook**.
3. Confirm the provider URL matches the displayed HTTPS endpoint, the webhook is
   enabled, and the signed test passes.
4. Place one low-value direct top-up test and confirm its line item records a
   terminal reconciliation source and WooCommerce changes to `Completed`.

---

# Delicat Digital Gateway v4.13.1 — Durable Supplier Synchronization Hotfix

This hotfix fixes the exact failure observed on direct top-up order `ord-848712`:
the first fast status check saw `processing`, but the currently running Action
Scheduler action was mistaken for its own future successor. No later canonical
check remained queued, so WooCommerce could stay `Processing` after FazerCards
had completed the top-up.

## v4.13.1 changes

- Always schedules a durable canonical poll after a non-terminal fast check.
- Adds the retry attempt to each scheduled action's uniqueness identity, so the
  running action cannot suppress the next required poll.
- Applies the same lost-successor protection to storefront supplier-create
  retries and local WooCommerce completion retries.
- Runs a read-only upgrade repair sweep for recent in-flight WooCommerce orders,
  including orders stranded by v4.13.0. The sweep only reads known supplier IDs
  and cannot create or charge another supplier order.

The v4.13.0 webhook and security improvements remain included below.

---

# Delicat Digital Gateway v4.13.0 — Real-Time Supplier Synchronization

This release closes the synchronization gap where FazerCards could finish an
order while WooCommerce remained `Processing` until Action Scheduler ran, or
remained stuck when the queue runner did not run at all.

## v4.13.0 changes

- Adds official FazerCards webhook-account integration for
  `GET/PUT /account/webhook` and `POST /account/webhook/test`.
- Adds **Configure & test FazerCards webhook** to Catalog Studio → Security.
  The provider signing secret is read back server-to-server, authenticated-
  encrypted locally, and never displayed in HTML or JavaScript.
- Shows whether the provider webhook is enabled, matches this store's exact URL,
  passed a signed delivery test, and has consecutive delivery failures.
- Keeps the webhook fast path authoritative: the HMAC-signed event is a trigger,
  then the plugin re-reads `GET /orders/{id}` before changing delivery or Woo status.
- Extends the cron-independent, single-use-token watcher beyond the old two-second
  window. Chained canonical reads now cover supplier work completing during the
  critical first ~20 seconds without waiting for Action Scheduler or WP-Cron.
- Retains Action Scheduler/WP-Cron as a durable fallback for longer-running orders,
  transport errors, rate limits, or hosts that reject self-loopback requests.
- Adds a v4.13 read-only repair sweep for recent secure `Processing`/`On-hold`
  orders. It cannot submit or charge a second supplier order.
- Rebuilds a missing remote-order lookup from canonical WooCommerce line-item
  metadata only when exactly one item owns that supplier ID; duplicates fail closed.
- Accepts the documented empty successful response from FazerCards' webhook PUT
  endpoint and preserves the webhook-test endpoint's HTTP-200 `{ok:false}` result
  so receiver failures are reported accurately.

## Required one-time setup after upgrade

1. Open **WooCommerce → FazerCards → Security**.
2. Save the FazerCards API key if it is not already configured.
3. Click **Configure & test FazerCards webhook**.
4. Confirm **Provider webhook: Enabled & URL matched** and
   **Signed delivery test: Passed**.

Production webhook setup requires a public HTTPS WordPress Site URL. If
`DELICAT_FAZER_WEBHOOK_SECRET` is defined in `wp-config.php`, the configure tool
will verify it against the provider but will never rewrite the constant.

## Synchronization contract

The same terminal-state pipeline applies to direct top-ups, gift cards, and game
keys. WooCommerce completes only after the wallet debit is re-verified, every
tracked supplier order is canonically `completed`, and every required gift-card
or game-key code has been authenticated-encrypted locally.

Official references:

- https://api.fzr.cards/public/docs
- https://reseller.fazercards.com/en/docs/webhooks
- https://github.com/FZR-cards/fazercards-node

---

# Delicat Digital Gateway v4.12.0 — Secure Headless Storefront Bridge

This release connects the existing Digital Gateway product-field and Player ID
engine to Delicat App API 2.9.0 without moving pricing, verification, payment or
fulfillment authority into the frontend.

## v4.12.0 changes

- Exposes each imported product's sanitized DFR field schema through the
  existing `delicat_app_product_fields` catalog filter.
- Marks products that require a player/account identifier through
  `delicat_app_product_needs_player_id`.
- Adds authenticated
  `POST /wp-json/delicat-app/v1/catalog/product/{id}/player/verify` and reuses
  the existing App API HTTPS, version, kill-switch, token and rate guards.
- Reuses the existing supplier-backed Player ID validator. No upstream category,
  field mapping, API credential or client-supplied “verified” flag is trusted.
- Carries only declared, sanitized fields from authenticated App API cart calls
  into the existing WooCommerce add-to-cart validation and fulfillment metadata.
- Revalidates supported Player IDs server-side when the authoritative cart line
  is created whenever verification is required.
- Uses stable field hashes for App API cart retries while preserving unique
  website-form submissions.
- Avoids duplicate cart display rows when the App API and DFR both describe the
  same native field.

## v4.12.0 security boundary

- WooCommerce remains authoritative for product, variation, stock and cart.
- The App API token must authenticate the customer before verification or field
  bridging can run.
- The frontend cannot choose the supplier validation category or field mapping.
- Player ID verification failure prevents a protected line from entering the
  cart; no wallet debit or supplier purchase is attempted.
- Existing v4.11 wallet attestation, supplier idempotency, encrypted delivery,
  webhook replay protection and completion gates remain unchanged.

---

# Delicat Digital Gateway v4.10.0 — Cronless Instant Reconciliation

This release fixes the intermittent state shown by orders such as supplier `completed` while WooCommerce remains `Processing`. The primary cause observed in v4.9.2 timing metadata is an accepted fast action that never starts (`_dfr_timing_fast_queued` exists but `_dfr_timing_fast_started` does not).

## v4.10.0 changes

- First reconciliation no longer depends on Action Scheduler/WP-Cron.
- Adds a single-use 256-bit authenticated server loopback to reconcile supplier state immediately.
- Adds a PHP-FPM post-response fallback: the customer response is flushed first, then a bounded supplier reconciliation runs in the same request worker when `fastcgi_finish_request()` is available.
- Keeps Action Scheduler/WP-Cron only as a durable safety net.
- Action Scheduler fallback workers no longer sleep inside the queue.
- Serializes canonical supplier GETs so loopback, webhook, customer refresh and queue workers do not hammer the same remote order concurrently.
- Signed terminal webhook status is treated as a monotonic floor when the canonical GET replica still reports `processing`; gift-card/game-key delivery still requires the actual encrypted code before WooCommerce can complete.
- Adds a one-time non-purchasing repair sweep for recent stuck Processing/On-hold orders.
- Opening a WooCommerce admin order can trigger a nonblocking recovery check, but normal automation does not depend on an admin visit.
- No repair/reconciliation path can create a second supplier order; supplier creation remains idempotent and isolated to the original fulfillment path.

## Security

- Wallet ledger proof and cryptographic payment attestation remain mandatory before supplier fulfillment or WooCommerce completion.
- Internal loopback accepts no caller-supplied order identifiers: it receives only a random single-use token whose server-side transient binds the exact order/item/remote id.
- Tokens expire in 60 seconds and are consumed before supplier I/O.
- Supplier order IDs remain bound to the exact WooCommerce line item.
- Gift-card/game-key codes remain encrypted at rest and are never placed in cron/action arguments.
- Signed webhook verification, replay protection, HTTPS verification, rate limits, idempotency and redacted logs remain enabled.

---

# Delicat Digital Gateway v4.9.2 — Verified Woo Completion Finalizer

This hotfix targets the case where the supplier has already returned `completed` but WooCommerce remains `Processing`.

## Completion fixes

- Reloads the WC order and line-item metadata before the final completion decision, avoiding stale nested-hook/item-meta state.
- Verifies that `WC_Order::update_status('completed')` actually persisted in WooCommerce/HPOS instead of assuming the call succeeded.
- Adds a last-stage `woocommerce_payment_complete` finalizer at maximum priority.
- Adds a same-request `shutdown` finalizer. This is local database/security work only; it never calls the supplier.
- Adds a bounded immediate local retry if a third-party status hook leaves the order in `Processing`.
- Adds `_dfr_completion_block_reason` diagnostics for any completion gate that refuses the transition.
- Adds a one-time v4.9.2 repair scan for recent `Processing`/`On-hold` orders that are already securely fulfilled.
- Corrects `_dfr_completed_at`: it is now written only after WooCommerce storage confirms the order is truly `completed`.

## Security rules retained

WooCommerce is completed only when:

- the order was paid using TeraWallet (`wallet`);
- the wallet ledger debit still matches the customer/order/amount/currency;
- the cryptographic wallet attestation is valid for protected orders;
- every line item is a tracked Delicat digital line;
- every line has a valid supplier `ord-...` binding;
- every supplier state is `completed`;
- gift-card/game-key lines have their delivery code encrypted locally before completion.

No supplier purchase is repeated by the completion repair/finalizer.

# Delicat Digital Gateway v4.9.1 — Woo Completion Hotfix

- Fixes supplier `completed` orders remaining WooCommerce `Processing`.
- Auto-completion is enforced whenever Live orders + Auto fulfillment are enabled.
- Adds a secure one-time repair pass for recent stuck Processing/On-hold orders.
- Fast reconciler now re-runs Woo completion when it sees an already-terminal top-up.
- No supplier request is required for the repair; wallet ledger, cryptographic attestation, tracked-item status, and encrypted-code rules remain mandatory.

# Delicat Digital Gateway v4.9.1 — Latency Audit + Adaptive Instant Reconciliation

v4.9.1 keeps the v4.8 cryptographic wallet/payment protections and targets the remaining intermittent fulfillment delays found during a deep timing audit.

## Verified causes fixed in v4.9.1

1. **Queue starvation under concurrent orders**
   - v4.8's first Action Scheduler worker intentionally slept for ~7.6 seconds across its observation window, before supplier HTTP time.
   - Because background actions may share a constrained queue, later customer orders could wait behind a slow earlier order.
   - v4.9 splits the fast window into three short/fair queue rounds so one supplier order cannot monopolize the queue.

2. **Transient poll failures could add 120 seconds**
   - A temporary supplier/network failure in durable reconciliation used a fixed 120-second delay.
   - v4.9 uses short adaptive retries: 3, 5, 8, 12, 20, 30... seconds.
   - Real supplier `429 Retry-After` values are still respected rather than bypassed.

3. **Transient supplier-create failures could add 60+ seconds**
   - Gift-card/game-key/top-up creation previously used a 60-second retry floor after retryable failures.
   - v4.9 retries the same immutable request with the same idempotency key after 3, 6, 10, 20... seconds.
   - Transactional supplier POST calls are bounded to 10 seconds so a stalled upstream connection does not hold the checkout path for 20 seconds.

4. **Blocking second GET after completed-without-code**
   - The reseller path could issue another long supplier GET when status was already completed but the code had not yet appeared.
   - v4.9 never blocks the request on that second lookup; short background code reconciliation takes over.

5. **Artificial WP-Cron fallback floors**
   - Remaining 30-second fallback floors were removed. Requested retry delays are now preserved.

6. **Stale local fallback lock**
   - The fallback local mutation lock stale threshold was reduced from 120 seconds to 30 seconds. The protected section contains no supplier network operation.

## New latency diagnostics

Slow completed orders receive one internal WooCommerce order note (not customer-visible) when the delay is material. It may report:

- payment → complete duration
- supplier/code duration
- Action Scheduler queue wait
- supplier-create HTTP duration
- completion-hook duration
- last safe delay classification, such as:
  - `supplier_processing`
  - `supplier_code_not_ready`
  - `supplier_transport`
  - `supplier_rate_limit`
  - `action_scheduler_queue_delay`
  - `order_lock_contention`

Non-sensitive timing metadata is also retained with `_dfr_timing_*` keys. No gift-card code, Player ID, API key, wallet secret, or webhook secret is included in these diagnostics.

## Security retained

v4.9 preserves the v4.8 security boundary:

- TeraWallet-only mapped digital checkout
- local TeraWallet ledger verification
- direct reseller/Gold TeraWallet debits are independently re-verified against the durable wallet ledger
- cryptographic wallet-payment attestation
- attestation binds order/customer/amount/currency/transaction and exact purchased line items
- payment proof rechecked before supplier spend and before WooCommerce completion
- immutable encrypted fulfillment snapshots
- supplier idempotency keys
- current-schema HMAC webhook verification; legacy webhook mode is opt-in only
- atomic webhook replay protection
- canonical supplier reread before webhook-driven mutation
- local remote-order/item binding
- authenticated encryption for delivered codes
- owner-only, nonce-protected, rate-limited secret reveal
- API TLS verification and response-size limits
- reseller bearer + site-bound HMAC authorization for every API request

## Expected order flow

Wallet debit succeeds → WooCommerce payment accepted → wallet attestation verified → supplier request → immediate/fair reconciliation + signed webhook → delivery encrypted locally → WooCommerce Completed.

WooCommerce is never auto-completed before every tracked digital line item is supplier-completed and every gift-card/game-key code required by that line is securely stored.


## v4.11.0 security hardening

- wallet attestation v2 binds the encrypted supplier fulfillment intent, including offer and account/player fields
- reseller API requires site-bound HMAC proof on every endpoint, including reads that may expose balances/order delivery
- supplier webhooks are trigger-only; canonical supplier API state is required before terminal mutation
- canonical order locking no longer falls through to an independent fallback when the MySQL lock is merely busy
- live TeraWallet ledger is re-read at each financial authorization/completion gate
- reseller REST payloads are bounded to 128 KiB
- reseller product pricing/exposure saves require a plugin-specific CSRF nonce
- public validation, secret reveal and internal reconciliation rate limits fail closed if limiter storage fails
- internal loopback rate limiting is serialized to resist parallel-counter bypasses
- reseller terminal delivery and refunds re-verify the original wallet debit before exposing codes or creating wallet credit
