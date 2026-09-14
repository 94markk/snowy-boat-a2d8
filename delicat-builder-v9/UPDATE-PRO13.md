> Historical PRO13 notes. Superseded by UPDATE-PRO14.md. The old add_option lock and output-buffer replay described below were replaced; do not use these notes as the current safety specification.

# PRO13 — Paiement Wallet dans la fenêtre produit

## Fonctionnement
Acheter maintenant ajoute la sélection via WooCommerce puis ouvre la fenêtre.
La fenêtre montre TOUS les articles du panier, y compris les précédents. Elle ne vide
pas le panier et ne remplace pas les autres articles. Payer maintenant soumet
le formulaire WooCommerce au transport protégé, qui appelle WC_Checkout.
WooCommerce et la passerelle wallet restent seuls responsables des commandes,
validation, stock, solde et débit. Le retour réussi ouvre la page de confirmation.

Disponible pour les clients connectés, produits sans expédition, passerelle WooCommerce
`wallet` disponible (Delicat Wallet/TeraWallet), et produits dont le paiement express
est déjà activé. Les autres passerelles et les visiteurs utilisent le checkout habituel.
Aucun numéro de carte ou secret de paiement n'est collecté par ce module.
HTTPS est obligatoire pour le paiement direct ; le module ne prétend pas chiffrer
lui-même la base de données WordPress ou remplacer le chiffrement du serveur.

## Protections
- Nonce WooCommerce et intention signée liée au client, au panier et à sa date.
- Consentement explicite obligatoire, validation serveur, total revérifié après validation.
- Verrou atomique par client et journal par intention (options non autoloadées).
- Réponse identique pour une intention déjà traitée, sans nouveau débit.
- Rejet d'une ancienne fenêtre après un autre paiement express réussi.
- Aucun renvoi automatique après interruption, erreur ou réponse inconnue.
- Le checkout classique est bloqué tant qu'un paiement express est en cours ou incertain.
- Les autres plugins/gateways et le checkout Blocks ne sont pas remplacés ; leurs
  propres garanties de concurrence et de transaction nécessitent un audit séparé.

Une réponse inconnue conserve la protection. L'administration affiche un avis avec
le client et, s'il est connu, le numéro de commande. Vérifier le résultat dans la
commande ET l'historique réel du wallet. Après au moins cinq minutes, la case de
vérification permet de libérer la protection. Ne jamais libérer un paiement encore actif.
La libération n'effectue aucun remboursement ni débit et ne rejoue pas l'intention.

## Présentation
Fenêtre plus compacte, libellé Delicat Wallet français sans solde répété, coordonnées
obligatoires modifiables, consentement visible, bouton fixe, détails défilants,
espaces adaptés aux petits écrans, animation réduite selon les réglages de l'appareil,
et navigation clavier contenue dans la fenêtre.

## Validation et limites
103 fichiers PHP analysés avec le parseur PHP 7.4 ; JavaScript vérifié par Node.
Tests DOM simulés : consentement obligatoire, double clic, réponse réussie, refus,
connexion interrompue, absence de répétition automatique et retour confirmation Woo.
Maquette rendue avec le CSS livré : 320×568, 393×852, 844×390 et 1440×900 ;
aucun débordement horizontal, bouton de paiement visible et corps défilant.
Il s'agit d'une maquette locale, pas d'un test visuel du site en production.
Les tests simulés ne prouvent pas l'atomicité de la passerelle wallet installée.
Aucun WordPress/WooCommerce ni compte de paiement réel n'est disponible dans cet
atelier : aucune commande réelle n'a été créée et aucun débit réel n'a été testé.

Avant production, tester sur une copie du site : montant faible, solde insuffisant,
coordonnées invalides, deux onglets, panier modifié, interruption réseau après envoi,
et correspondance exacte entre commande Woo et un seul débit wallet. Vérifier aussi
les champs Player ID et les extensions de livraison. Les champs produit existants
n'ont pas été changés.

## Installation
Sauvegarder le site et conserver PRO12 pour retour arrière. WordPress → Extensions →
Ajouter → Téléverser : choisir le ZIP PRO13 et remplacer la version existante.
Purger LiteSpeed et Cloudflare. Les réglages existants sont conservés.
En cas de paiement incertain, vérifier les commandes et débits avant un retour arrière,
car désactiver le plugin retire sa protection supplémentaire.
