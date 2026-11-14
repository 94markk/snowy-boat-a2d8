# Installation 2.4.8

1. Sauvegarder le site et la base WooCommerce.
2. Remplacer l’ancienne version du plugin par 2.4.8.
3. Ouvrir **Delic → Réglages → Fulfillment safety** et laisser **WooCommerce controls payment approval** activé.
4. Conserver **Validate UID / Player ID** si vous voulez le bouton de vérification et la validation post-paiement.
5. Tester d’abord un petit produit :
   - ajouter au panier;
   - arriver au checkout sans erreur fournisseur;
   - effectuer un paiement réel/test validé par votre passerelle WooCommerce;
   - vérifier que la commande passe en `processing`;
   - vérifier ensuite le fulfillment dans **Delic → Orders/Tableau de bord**.
6. Si le fournisseur est temporairement indisponible, la commande WooCommerce doit rester payée et le fulfillment doit passer en retry/review au lieu d’annuler le paiement.
7. Ne jamais utiliser `processing` manuel comme substitut à un paiement. La version 2.4.8 refuse de libérer une commande fournisseur sans preuve de paiement WooCommerce.
