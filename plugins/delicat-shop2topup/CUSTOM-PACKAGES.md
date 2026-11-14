# Correction de sélection — 2.8.3

La variante composée peut être visible dans les cartes sans être présente dans le menu Package utilisé réellement par WooCommerce. La sélection reste alors vide. Cette version rétablit les valeurs des forfaits dans ce menu et les conserve lors des sauvegardes et synchronisations.

1. Remplacez le plugin par ce ZIP et vérifiez la version dans Extensions.
2. Ouvrez une page d’administration WordPress pour déclencher la réparation automatique des forfaits existants. Les lots traitent jusqu’à 25 variations personnalisées par requête ; pour un grand catalogue, les pages d’administration suivantes continuent la réparation.
3. Ouvrez le produit Free Fire LATAM puis cliquez une fois sur Mettre à jour pour enregistrer explicitement ses options réparées.
4. Purgez le cache LiteSpeed de la fiche produit (et Cloudflare si la page y est mise en cache), puis rechargez la fiche publique.
5. Choisissez 200 + 20 Diamonds : WooCommerce doit reconnaître cette variation et afficher son prix. Les autres conditions, notamment la vérification de l’ID joueur et les règles du panier, restent nécessaires pour acheter.

Ne recréez pas les forfaits existants et ne changez pas les identifiants fournisseur. La réparation ne modifie ni leurs prix ni leurs compositions. Les produits en rupture restent bloqués par les contrôles habituels. Les limites de compatibilité du paiement classique ci-dessous restent applicables.

# Forfaits personnalisés — Delicat

## Installation

1. Sauvegardez votre site et testez les mises à jour sur une copie de préproduction.
2. Dans Extensions → Ajouter une extension → Téléverser, remplacez la version correspondante par le nouveau ZIP :
   - Delicat Top-Up Fulfillment : **2.8.3**.
   - Delicat Digital Gateway : **4.23.2**.
3. Si vous utilisez les deux passerelles, mettez les deux à jour. Le module commun se charge une seule fois.
4. Videz le cache WooCommerce/LiteSpeed après la mise à jour.

## Accès direct corrigé — version 2.8.2

Les versions 2.8.0 et 2.8.1 pouvaient déclarer leurs classes sans enregistrer leurs actions de démarrage. Ce défaut PHP est corrigé en 2.8.2. Il ne s’agissait pas simplement d’un onglet masqué sur téléphone.

1. Remplacez le plugin installé par ce ZIP. Vérifiez **version 2.8.2** dans Extensions.
2. Ouvrez **Produits → Free Fire LATAM → Modifier**.
3. Sous le titre du produit, un encadré **Forfaits personnalisés** affiche **Module chargé · Shop2Topup 2.8.2** et le bouton **Configurer les forfaits**. Ce bouton ouvre directement le gestionnaire du produit, sans ID à saisir.
4. La fiche comporte aussi un bloc indépendant **Forfaits personnalisés** avec le formulaire complet. Il reste accessible sans les petits onglets mobiles de Données produit.
5. Le produit doit être un produit variable, déjà enregistré avec ses options fournisseur originales. Pour 220, choisissez 110 × 2. Pour 451, choisissez 110 × 1 et 341 × 1.
6. Dans le formulaire de la fiche produit, saisissez le nom et le prix, cochez **Créer ce forfait lors de la mise à jour du produit**, puis cliquez sur **Mettre à jour**. Le forfait apparaît après rechargement.
7. Dans le gestionnaire séparé, utilisez plutôt le bouton **Créer le forfait** de cette page.

Le dernier onglet avec une clé anglaise sur vos captures correspondait aux paramètres fournisseur, pas aux forfaits. Aucun identifiant fournisseur du produit parent ne doit être modifié pour ajouter une composition.

Le module est également testé avec Digital Gateway 4.23.0 chargé avant ou après Shop2Topup. La version corrigée de Digital Gateway est 4.23.1 ; mettez les deux passerelles à jour si vous les utilisez. La livraison et les limites de paiement restent celles décrites ci-dessous.

## Créer 220 Diamants et 451 Diamants

Le produit Free Fire LATAM doit déjà être importé en produit variable avec ses options fournisseur originales.

1. Ouvrez **Produits → Forfaits personnalisés**.
2. Entrez l’ID du produit parent Free Fire LATAM et cliquez sur **Charger les options**. L’ID est visible dans la liste des produits WooCommerce.
3. Pour **220 Diamants**, choisissez l’option originale **110** et indiquez **2** recharges. Entrez votre prix de vente, puis créez le forfait.
4. Pour **451 Diamants**, choisissez **110 × 1** et **341 × 1**, puis entrez votre prix.
5. Les nouvelles options apparaissent avec les autres variations dans le sélecteur existant de votre boutique. Cette mise à jour ajoute la composition des forfaits, elle conserve le design de votre thème et de vos swatches.
6. Modifiez le prix, le prix promotionnel, l’ordre ou l’activation de la nouvelle option dans **Modifier le produit → Variations**, comme pour une variation WooCommerce normale. Pour changer sa composition, créez une nouvelle option et désactivez l’ancienne.

| Option client | Composition fournisseur |
| --- | --- |
| 110 Diamants | 110 — option originale |
| 220 Diamants | 110 × 2 |
| 341 Diamants | 341 — option originale |
| 451 Diamants | 110 + 341 |
| 572 Diamants | 572 — option originale |
| 682 Diamants | 572 + 110 |
| 792 Diamants | 572 + 110 × 2 |
| 913 Diamants | 572 + 341 |
| 1023 Diamants | 572 + 341 + 110 |
| 1166 Diamants | Option fournisseur originale 1060 + 106, si disponible |

Attention : **1166** correspond à une option originale **1060 + 106**, si votre fournisseur la propose. Utilisez cette option originale. Les trois options 110, 341 et 572 ne permettent pas de fabriquer 1166. Le nom commercial est manuel : vérifiez toujours le total réel des diamants avant publication.

## Commande et livraison

- Le client choisit un forfait dans le panier au prix que vous avez fixé.
- Au paiement classique WooCommerce, le forfait est préparé en plusieurs lignes de livraison, chacune portant le nom du forfait et son numéro de partie (1/2, 2/2…). Elles sont visibles dans la commande. Les montants et les taxes sont répartis entre les parties sans augmenter le total payé.
- **220** crée deux recharges **110**, avec des contextes de commande distincts, au même ID joueur. **451** crée une recharge **110** et une recharge **341**.
- Toutes les parties sont créées avant les attestations de paiement existantes. Les passerelles conservent leurs contrôles de paiement, leur protection contre les doublons, leurs reprises et leurs règles de synchronisation.
- Les références des parties sont enregistrées dans les commandes : changer ou désactiver une option après l’achat ne transforme pas la commande déjà créée.
- Une partie en échec suit la politique de la passerelle : vérification manuelle / mise en attente. Aucun remboursement automatique n’a été ajouté. Avant toute relance manuelle, contrôlez quelles parties ont réellement été livrées.
- Les prix sont manuels. Ils ne sont pas recalculés à partir de la somme des coûts fournisseur. Vérifiez votre marge.

## Compatibilité et limites

- Recharge directe seulement : pas de combinaison de PIN, cartes cadeaux, clés ou codes.
- Même produit parent, même fournisseur et même catégorie/serveur pour toutes les parties.
- Un seul attribut de variation local, comme Package, sur le produit importé.
- De 2 à 20 recharges par forfait ; jusqu’à 6 lignes de composition dans le formulaire.
- La passerelle Digital Gateway conserve la limite d’une unité de forfait par ligne de panier. Les répétitions du forfait sont des livraisons séparées en interne.
- Les forfaits n’ont pas de copie physique des identifiants fournisseur. La synchronisation des options originales continue, sans convertir les nouvelles options en doublons importés.
- Si le fournisseur change la référence ou le schéma d’un composant, le forfait est bloqué ; recréez-le avec les nouvelles options. Les anciens paniers doivent être recréés.
- Le stock est vérifié pour toutes les parties, y compris les répétitions. Une rupture connue bloque le forfait. Cela ne réserve pas le solde ou le stock distant avant paiement.
- Les forfaits sont exclus du catalogue et des commandes de votre API revendeur : cette API ne gère pas les compositions dans cette version.
- **Paiement WooCommerce classique uniquement** pour les forfaits. Le paiement par blocs / Store API est bloqué. Une application ou une intégration qui ne déclenche pas `woocommerce_checkout_create_order_line_item` n’est pas compatible avec ces forfaits ; la passerelle refuse de livrer une variation non décomposée. Les produits ordinaires gardent leur comportement existant.
- Les systèmes de fidélité, statistiques ou extensions externes qui comptent les lignes ou variations voient les composants de la commande. Vérifiez leur comptage sur préproduction avant activation ; ce ZIP ne modifie pas votre extension de récompenses.

## Validation avant utilisation réelle

Les tests livrés sont hors ligne : simulation de création des parties et régressions existantes des passerelles. Aucune transaction fournisseur réelle et aucun paiement de votre site n’ont été exécutés.

Sur préproduction, vérifiez : affichage des nouvelles options sur votre page produit et dans votre application ; ID joueur et serveur ; achat de 220 ; achat de 451 ; coût fournisseur cumulé ; deux livraisons distinctes au même compte ; finalisation seulement après livraison des deux parties ; rafraîchissement de la page sans renvoi ; une partie en échec ; rupture du second composant ; affichage dans Mes achats et calcul des récompenses.
