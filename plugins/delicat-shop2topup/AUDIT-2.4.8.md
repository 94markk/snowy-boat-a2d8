# Audit de correction — Delicat Shop2TopUp 2.4.8

## Objectif

Rendre WooCommerce seul responsable de l’acceptation du paiement client et empêcher toute panne du fournisseur de bloquer le panier ou le checkout. Le fournisseur ne doit recevoir une commande qu’après preuve de paiement WooCommerce.

## Changements principaux

1. **Autorité de paiement WooCommerce**
   - `woocommerce_payment_complete` enregistre un marqueur durable `_dst2t_wc_payment_approved=yes`.
   - Une simple modification du statut en `processing` ne suffit plus à envoyer une commande au fournisseur.
   - Les anciennes commandes réellement payées restent récupérables grâce à `date_paid`.
   - Les commandes `pending`, `failed`, `cancelled` ou `refunded` ne sont jamais soumises au fournisseur.

2. **Checkout découplé du fournisseur**
   - En mode par défaut `woocommerce_payment_gate=yes`, la validation add-to-cart n’effectue plus d’appel API fournisseur.
   - Les champs obligatoires sont validés localement à partir du schéma déjà importé.
   - Le bouton « Vérifier votre compte » reste disponible mais n’est plus un point de panne du checkout.

3. **Jeton de vérification joueur signé**
   - Une vérification réussie crée un jeton HMAC court terme lié au produit, item fournisseur et hash des exigences.
   - Le jeton est vérifié côté serveur avant d’accepter le nom joueur dans le panier.
   - Une modification du UID/serveur invalide automatiquement le jeton.

4. **Validation joueur après paiement**
   - Si aucun jeton valide n’existe, le worker vérifie le joueur après confirmation du paiement WooCommerce et avant l’achat fournisseur.
   - Une erreur temporaire est réessayée.
   - Une erreur définitive (ex. joueur invalide) place la commande payée en revue sécurisée sans créer de commande fournisseur.

5. **Récupération des réponses prix invalides**
   - Le prix live reste la première source.
   - Si le endpoint prix renvoie une erreur transitoire/malformée et qu’un coût fournisseur valide est déjà en cache, ce coût est utilisé uniquement comme `expected_unit_price`.
   - Le fournisseur refuse lui-même toute hausse au-dessus de ce plafond via `PRICE_INCREASED`, donc le fallback ne désactive pas la protection de prix.
   - Lors d’un `PRICE_INCREASED`, `current_unit_price` est mémorisé seulement après passage du garde de coût, puis la même UUID est réessayée.

6. **Erreurs retryables**
   - `OUT_OF_STOCK`, `PRODUCT_UNAVAILABLE`, `PRICING_UPDATING`, `RATE_LIMIT_EXCEEDED`, erreurs transport/5xx et toute erreur avec `retryable=true` restent dans la file de récupération.

## Validation locale

- 210 tests comportementaux isolés réussis.
- Vérification syntaxique de tous les fichiers PHP.
- Vérification syntaxique des fichiers JavaScript.
- Aucun appel réel au compte fournisseur n’a été effectué pendant les tests locaux.

## Contrat fournisseur vérifié

Documentation publique Shop2TopUp vérifiée le 20 septembre 2026 :
- `/orders/create` débite le wallet fournisseur immédiatement et utilise `order_id` comme clé d’idempotence.
- `expected_unit_price` protège contre les hausses de prix.
- `PRICE_INCREASED` retourne `current_unit_price`.
- Les statuts d’ordre documentés sont `pending`, `completed`, `refunded`, `partial`.
- Les webhooks signés accélèrent la synchronisation; les lectures de statut restent le filet de sécurité.
