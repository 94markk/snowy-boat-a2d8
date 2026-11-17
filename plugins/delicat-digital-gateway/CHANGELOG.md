## 4.25.0 — Fiches produit plus légères (15 novembre 2026)

### 1. Pour les clients

1. Fiche produit d'une recharge : le style et le script des champs de compte (ID joueur, vérification, derniers UID) sont des fichiers mis en cache par le navigateur au lieu d'être réécrits dans chaque page (−12,5 Ko par fiche). Après une navigation instantanée du Builder, la vérification n'est plus envoyée deux fois.
2. Boutique et catégories : les forfaits personnalisés sont connus grâce à un index enregistré ; plus une requête par produit variable (24 produits : 24 → 0 requêtes).
3. Page Mon compte : le cache objet (Redis) reste actif. La page reste privée et non mise en cache ; les codes déchiffrés ne passent toujours que par la réponse privée « Afficher les codes ».

### 2. Technique

- Nouveaux fichiers assets/product-fields.css et assets/product-fields.js (poignée dfr-product-fields) ; transitions coupées avec « réduire les animations » et le mode économie.
- Nouvelle option delicat_package_index_v1 (construite une fois au premier chargement après la mise à jour, puis tenue à jour ; elle porte une marque de format et un index sans cette marque est reconstruit). Si un forfait y manquait, il serait refusé à la commande, jamais livré au mauvais prix.
- Nouveau test tests/package-index-smoke.php : l'index est construit comme WordPress répond réellement (« ids » et « id=>parent », y compris depuis le cache de requêtes) et chaque forfait garde sa liaison fournisseur.
- Module de forfaits partagé identique dans Shop2Topup 2.10.0.

## 4.24.0 — Confirmations en quelques secondes, pages plus légères

### 1. Pour les clients

1. Les commandes confirmées par le fournisseur passent à Terminée en quelques secondes au lieu d'une à plusieurs minutes : relances internes fiables sur LiteSpeed, webhooks signés réglés juste après la réponse, tâches de commande prioritaires sur la synchronisation du catalogue (désormais en arrière-plan à basse priorité), solde fournisseur rafraîchi toutes les 5 minutes au lieu de 30 secondes.
2. La fiche produit d'une recharge n'attend plus jamais le fournisseur : la liste des jeux vérifiables (rafraîchie toutes les 10 minutes) pouvait bloquer l'affichage jusqu'à 20 secondes. La page utilise la dernière liste connue et la rafraîchit en arrière-plan ; sans aucune liste enregistrée, l'attente est limitée à 5 secondes.
3. « Vérifier votre compte » fonctionne de nouveau sur une page servie par le cache LiteSpeed depuis plus d'un jour : le jeton de sécurité expiré est renouvelé automatiquement une fois (au lieu de « session expirée » à chaque essai, même après rechargement).
4. Pages plus rapides pour les clients connectés et l'application : les réglages et indicateurs lus à chaque page sont chargés en une seule requête au lieu d'une quinzaine (WordPress 6.4+), la file Action Scheduler n'est plus interrogée à chaque page vue et une suppression inutile en base à chaque requête est retirée.

### 2. Correctifs

1. Désactivation et désinstallation retirent réellement toutes les tâches planifiées : les événements WP-Cron avec arguments (veilleur toutes les minutes, relances par commande, lots du catalogue) et les tâches Action Scheduler avec arguments de l'API revendeur restaient en place et réveillaient WP-Cron sans rien à exécuter. La désinstallation couvre aussi les tâches ajoutées depuis la 4.15.
2. La purge volontaire à la désinstallation (`DELICAT_DIGITAL_PURGE_ON_UNINSTALL`) efface aussi le journal des modifications, la sentinelle de chiffrement, l'état de migration du trousseau et les verrous de commande de secours.
3. Les fichiers de `tests/` répondent 404 s'ils sont appelés par le serveur web.

### 3. Notes techniques

- Nouvelle action AJAX `dfr_uid_nonce` (connecté et visiteur, même origine, jamais mise en cache) : renvoie un jeton `dfr_uid_verify` neuf. Aucun droit nouveau : le même jeton est déjà dans la page produit ; la vérification garde ses limites par visiteur et pour tout le site.
- Nouveau transitoire `dfr_uid_validation_games_stale_v3` (dernière liste valide, 1 jour, affichage uniquement) et événement unique `dfr_uid_validation_games_refresh`. Vérification, panier, application, API revendeur et livraison lisent toujours une liste fraîche.
- `wp_prime_option_caches()` au démarrage (sans effet avant WordPress 6.4 ou avec un cache objet qui contient déjà ces options). Aucun nom d'option, de méta, de table, de route ni de hook n'a changé.
- Le contrôle Action Scheduler du veilleur s'exécute dans WP-Cron, l'administration et admin-ajax (dont le lanceur asynchrone d'Action Scheduler), et une fois après chaque mise à jour.
- `DFR_Plugin::clear_wp_cron_hook()` (via `wp_unschedule_hook()`) retire tous les événements d'un hook quels que soient leurs arguments.
- Documentation : README réduit à la documentation actuelle ; notes par version (PLAYER-ID-FIX-4.22.1, PLAYER-REGION-4.22.2, REGION-MESSAGE-4.22.3, CUSTOM-PACKAGES-VALIDATION) intégrées à ce journal puis supprimées.
- Tests : nouveaux scénarios de latence, veilleur hors des pages vues et désactivation dans `tests/delivery_smoke.php` ; « dernière liste valide » et désactivation du catalogue et de l'API revendeur dans `tests/catalog_spec_smoke.php` ; renouvellement du jeton dans `tests/player_identity_browser.js`. Lancer `for t in tests/*.php; do php "$t" || echo FAIL $t; done` et `cd tests && node --input-type=commonjs -e "$(cat player_identity_browser.js)"`.

## 4.23.2 — Custom package selection repair

- Fix custom packages present in variation data/cards but missing from the native WooCommerce attribute dropdown.
- Preserve exact local attribute labels (including spaces and plus signs), retain native options and keep WooCommerce availability checks.
- Restore custom labels on parent saves/import sync; invalidate variation caches after custom variation saves.
- Repair existing parents in bounded administrator-only batches. No supplier calls, no frontend writes, no forced variation IDs.
- Independent compatibility module works with an older shared package class and boots once across gateways.

## 4.23.1 — Package startup fix

- Fix PHP early class declaration causing the first-load class_exists guard to return before registering startup hooks.
- Register the shared bootstrap even when a legacy gateway owns the shared class; retain one-time boot protection.
- Add fresh-process regression tests covering normal loads, duplicate includes and legacy-first/legacy-last load order.

## 4.23.0 — Forfaits personnalisés

- Product admin editor for custom direct-top-up package recipes and manual retail prices.
- Split classic checkout packages into native delivery lines before payment, with individual tracking and existing payment/idempotency guards.
- Shared module boots once; virtual source mappings protect importer identity and custom prices; stock checks cover repeated components.
- Explicit safeguards for stale recipes and unsupported checkout paths. See CUSTOM-PACKAGES.md for compatibility and validation.
- Validated offline (PHP 8.3.6): package orchestration suite (110+110, 110+341, per-part mapping/recipient/context, amount/subtotal/tax preservation, stale mappings, cross-server recipes, stock aggregation, tampered and nested recipes, mapping write protection, unsupported checkout, 95 monetary allocation cases) plus every existing gateway suite. No live purchase.

## 4.22.3 — Message de serveur du compte

- Sur un refus confirmé de serveur, le client voit le serveur réel du compte et la rubrique Free Fire où acheter, par exemple : « Désolé, ce compte est enregistré sur un autre serveur : Amérique / LATAM (NA). Veuillez effectuer votre transaction dans la rubrique Free Fire du serveur Amérique / LATAM (NA). »
- Un serveur inconnu ou contradictoire n'est jamais inventé ; sans nom de serveur, le client est invité à vérifier le serveur dans le jeu.

## 4.22.2 — Contrôle de région du compte

- Refus de région/serveur confirmé : « Désolé, Ce compte est enregistré dans une autre région ou serveur. » La route de l'application répond `supported: true, valid: false`.
- Free Fire : la région renvoyée est comparée au champ de région explicite ou au titre du produit (Free Fire LATAM, Europe…). LATAM et NA comptent comme Amériques ; Brésil et Europe restent distincts. Une région inconnue ou absente n'est jamais un refus. Les anciens jetons de vérification exigent une nouvelle vérification.

## 4.22.1 — Vérification du Player ID

- Refus d'une réponse fournisseur qui désigne un autre Player ID (comparaison en texte, y compris au-delà de 32 bits) ; demande de réponses non mises en cache ; anciens jetons invalidés ; vérification effacée dès qu'un champ change et réponses obsolètes ignorées ; pseudos Unicode conservés et tronqués par caractère.
- Limite connue : le pseudo affiché est celui du fournisseur. Un pseudo périmé (ex. UID 8593173765 affiché « MERLINWzp ») doit être corrigé par le fournisseur ; aucune substitution UID → pseudo n'est codée et aucun contournement de CAPTCHA n'a été ajouté.
