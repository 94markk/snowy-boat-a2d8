# Delicat Digital Gateway

Livraison des recharges de jeux, cartes cadeaux et clés de jeu de **delicastoreha.com**
par le fournisseur FazerCards, payées avec le portefeuille **TeraWallet**. Le plugin
contient aussi Catalog Studio (import et synchronisation du catalogue fournisseur), la
vérification du Player ID, la livraison chiffrée des codes, l'API revendeur financée par
le portefeuille et les routes REST utilisées par l'application Flutter.

Version actuelle : **4.24.0** — historique détaillé dans `CHANGELOG.md`.
Forfaits personnalisés (220 = 110 × 2…) : voir `CUSTOM-PACKAGES.md`.

## Prérequis

- WordPress 6.2+ (6.4+ recommandé : les options lues à chaque page sont chargées en une
  seule requête), WooCommerce 7.0+ (HPOS compatible), PHP 8.3, TeraWallet.
- Extension PHP `sodium` (ou OpenSSL avec AES-256-GCM).
- Site en HTTPS avec une URL publique (webhook signé, API revendeur).
- Recommandé : une tâche cron serveur toutes les minutes pour WP-Cron / Action Scheduler
  (`wp-cron.php`). Les livraisons n'en dépendent pas pour être rapides, mais c'est le
  filet de sécurité.

## Installation et mise à jour

1. Sauvegarder le site. Extensions → Ajouter → Téléverser le ZIP et **remplacer** la
   version installée. Ne pas désinstaller avant (la désinstallation supprime les tâches
   planifiées ; les données ne sont effacées que si `DELICAT_DIGITAL_PURGE_ON_UNINSTALL`
   vaut `true`).
2. Vider le cache LiteSpeed et le CDN, puis vérifier `/wp-json/delicat-app/v1/config`.
3. WooCommerce → FazerCards → **Security** : enregistrer la clé API si besoin, cliquer
   **Configure & test FazerCards webhook** et vérifier « Enabled & URL matched » et
   « Signed delivery test: Passed ».
4. Faire une petite commande test (recharge directe) : la ligne doit passer à
   « completed » et la commande à **Terminée** en quelques secondes après la
   confirmation du fournisseur.
5. Construire les versions suivantes à partir de celle-ci (les correctifs d'audit
   disparaissent sinon).

## Trousseau de clés `DELICAT_DFR_KEYS` (recommandé)

Les codes livrés et les secrets sont chiffrés (XChaCha20-Poly1305, sinon AES-256-GCM).
Sans trousseau, la clé dérive des « salts » WordPress et de l'adresse du site :
**changer les salts ou le domaine rend tous les codes illisibles**.

Ajouter dans `wp-config.php` (32 octets aléatoires en base64) :

    define( 'DELICAT_DFR_KEYS', 'k1:REMPLACEZ_PAR_32_OCTETS_EN_BASE64' );

Générer une clé : `php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"`

- Les nouvelles données sont écrites en v3 (`x3:k1:…`), indépendantes des salts et du
  domaine. Les données plus anciennes sont re-chiffrées **en arrière-plan** (lancé à la
  visite d'un administrateur ; options `dfr_keyring_migration_v1` et
  `dfr_reseller_keyring_migration_v1`). L'avis de la page FazerCards affiche le nombre de
  valeurs restantes : **ne changez ni les salts ni l'adresse du site avant qu'il affiche 0.**
- Les empreintes des clés API revendeur passent au trousseau à leur prochaine utilisation.
- Rotation : nouvelle clé en premier, ancienne ensuite : `'k2:NOUVELLE,k1:ANCIENNE'`.
- **Sauvegardez cette ligne hors du serveur** : sans elle, les données v3 sont perdues.
- Revenir à la 4.14.2 ou plus ancienne cache les valeurs v3 (réinstaller la version
  actuelle les rend de nouveau lisibles). Gardez la constante.

## Constantes `wp-config.php`

| Constante | Rôle |
| --- | --- |
| `DELICAT_DFR_KEYS` | Trousseau de clés (voir ci-dessus). |
| `DELICAT_FAZER_API_KEY` | Clé API FazerCards fournie par la configuration au lieu de la base. |
| `DELICAT_FAZER_WEBHOOK_SECRET` | Secret du webhook ; l'outil de configuration le vérifie sans le réécrire. |
| `DELICAT_DIGITAL_PURGE_ON_UNINSTALL` | `true` uniquement pour effacer réglages, tables et journaux à la désinstallation. |

## Filtres utiles

| Filtre | Défaut | Usage |
| --- | --- | --- |
| `dfr_uid_validation_budget_per_minute` | 60 | Vérifications Player ID par minute pour tout le site (site, application, API revendeur). |
| `dfr_allow_guest_email_delivery_access` | `false` | Autoriser l'affichage des codes d'une ancienne commande invitée par l'e-mail du compte (seulement avec une vérification d'e-mail fiable). |
| `dfr_allow_legacy_webhook` | `false` | Ancien format de webhook sans horodatage ni identifiant d'événement. À laisser désactivé. |
| `dfr_reseller_api_allow_insecure_http` | `false` | API revendeur sans HTTPS (tests locaux uniquement). |
| `dfr_supplier_stock_sync_enabled` | — | Exclure un produit de la synchronisation du stock fournisseur. |
| `dfr_delivery_failure_alert_email`, `dfr_margin_alert_email`, `dfr_reseller_unknown_alert_email` | e-mail admin | Destinataire des alertes. |

## Fonctionnement des commandes

1. **Paiement** : un panier qui contient un produit du fournisseur ne propose que le
   portefeuille. Au débit TeraWallet, une attestation signée lie le débit à la commande,
   au client, au montant et au contenu exact (sans Player ID en clair).
2. **Envoi au fournisseur** : la page de paiement ne fait que mettre la livraison en file.
   L'envoi part après la réponse au client (même processus PHP, LiteSpeed ou PHP-FPM),
   par une requête interne à usage unique, et par une tâche Action Scheduler de secours.
   Chaque envoi réutilise la même clé d'idempotence : jamais de double achat fournisseur.
3. **Confirmation** : le webhook signé du fournisseur n'est qu'un déclencheur ; l'état
   est toujours relu sur `GET /orders/{id}` avant de toucher à la commande. Viennent
   ensuite des relances internes pendant environ une minute, un plan de vérifications
   sur dix minutes, un veilleur toutes les minutes et la page de commande du client.
   Les tâches de commande passent avant la synchronisation du catalogue.
4. **Fin** : la commande passe à **Terminée** quand chaque ligne est confirmée et chaque
   code chiffré et enregistré. Codes visibles seulement par le propriétaire d'une ligne
   payée, non remboursée (site, Mes achats, application via `delicat_app_order_codes`).
5. **Échec définitif** : commande **En attente**, client et administrateur prévenus,
   boutons « Relancer la livraison », « Livré manuellement » et « Remboursé ».
   **Jamais de remboursement automatique** des commandes WooCommerce.

## Vérification du Player ID

- La page produit affiche le bouton « Vérifier votre compte » quand le fournisseur
  propose la vérification pour ce jeu. Elle utilise la dernière liste connue des jeux
  vérifiables et la rafraîchit en arrière-plan : la page n'attend jamais le fournisseur.
- Un jeton signé (10 minutes, lié à la session) autorise l'ajout au panier. Une page
  servie par le cache avec un jeton de sécurité expiré en obtient un neuf
  automatiquement (`dfr_uid_nonce`).
- Mauvais serveur ou région : message exact « Désolé, ce compte est enregistré sur un
  autre serveur… » avec le nom du serveur quand le fournisseur le donne.
- Limite connue : le pseudo affiché est celui que renvoie le fournisseur. Un pseudo
  périmé doit être corrigé chez le fournisseur ; aucune substitution n'est codée.
- Diagnostic : WooCommerce → FazerCards → Security (le journal WooCommerce indique
  `outcome=player_rejected|schema_rejected|config|rate_limited|unavailable`).

## API revendeur

`/wp-json/delicat-reseller/v1/` : clé `Authorization: Bearer dk_live_…` liée à un site
approuvé, chaque requête signée (horodatage, identifiant unique, HMAC du corps),
`Idempotency-Key` obligatoire sur `POST /orders`, limite par heure et plafond de dépense
par jour, verrou de portefeuille partagé avec le site et l'application, plan Gold
(essai de 6 jours). Une tentative au résultat inconnu reste `supplier_unknown` pour
vérification manuelle ; seuls un refus définitif dès la première tentative ou une
commande échouée/remboursée par le fournisseur re-créditent le portefeuille revendeur.

## Points d'intégration (ne pas renommer)

- REST : `delicat-gateway/v1/webhook`, `delicat-app/v1/catalog/product/{id}/player/verify`,
  `delicat-reseller/v1/*`.
- AJAX : `dfr_verify_player_uid`, `dfr_uid_nonce`, `dfr_clear_recent_player_ids`,
  `dfr_delica_reveal_codes`, `dfr_delica_delivery_status`, `dfr_delica_nonce`,
  `dfr_customer_order_sync_status`, `dfr_internal_reconcile`, `dfr_internal_fulfill`.
- Filtres et actions : `delicat_app_order_codes`, `delicat_app_order_delivery`,
  `delicat_app_order_status_label`, `delicat_app_order_timeline`,
  `delicat_app_product_fields`, `delicat_app_product_needs_player_id`,
  `delicat_dfr_cart_line_available`, `dfr_app_player_verify_delegate`, `delica_extra_rows`,
  `dfr_delivery_ready`, `dfr_delivery_failed`, `dfr_non_digital_line_fulfilled`.
- Tables : `{prefix}dfr_api_clients`, `{prefix}dfr_api_orders`, `{prefix}dfr_api_plan_events`.

## Tests (hors ligne, sans WordPress)

    for t in tests/*.php; do php "$t" || echo FAIL $t; done
    cd tests && node --input-type=commonjs -e "$(cat player_identity_browser.js)"

`delivery_smoke.php` dure environ 10 s (scénarios de latence). Les fichiers de `tests/`
répondent 404 s'ils sont appelés par le serveur web. Ces tests utilisent des doublures de
WordPress, WooCommerce et du fournisseur : ils ne remplacent pas un essai réel sur le site.

Références fournisseur : https://reseller.fazercards.com/en/docs ,
https://reseller.fazercards.com/en/docs/webhooks , https://api.fzr.cards/public/docs
