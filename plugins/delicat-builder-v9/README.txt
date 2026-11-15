9.14.2 — Téléphone plus réactif (12 novembre 2026)
(1) Toucher un produit répond tout de suite : quand la page suivante se charge en entier (réglage LiteSpeed
« JS Combine » activé), la ligne de chargement part et la page s’estompe dès le toucher, au lieu de rester
figée jusqu’à l’arrivée de la page suivante. Au retour (bouton Retour), la page revient normale.
(2) Le script des packs ne bloque plus la fin du chargement de la page produit (chargé en « defer »).
Mesure : la page produit n’est pas plus lente qu’en 9.13.0 (16 essais alternés, mêmes calculs de mise en page).
Pour des pages produit instantanées : LiteSpeed › Page Optimization › JS Combine sur OFF, puis Purge All.

9.14.1 — Corrections vues sur téléphone (12 novembre 2026)
(1) « Voir tout » est sur la ligne du titre de chaque rangée de produits (il flottait au-dessus) ; le petit titre
gris passe à 12 px.
(2) Plus de grand vide entre deux rangées de produits : « Voir tout » ouvrait une ligne vide au-dessus du titre.
(3) La barre « Delicat en application » (iPhone) ne reste plus sur la page : elle apparaît seulement en haut de la
page et s’en va dès que l’on fait défiler ; elle ne revient pas pendant la même visite.
(4) La bulle d’assistance s’efface pendant que l’on fait défiler vers le bas et revient dès que l’on remonte, en
haut ou en bas de la page, et quand l’assistance est ouverte : elle ne cache plus les produits.
Les cartes produit ne changent pas. À installer par-dessus la 9.14.0, puis vider le cache LiteSpeed (Purge All).

9.14.0 — Tablette et ordinateur, « Parcourir » à cinq tuiles, site plus rapide, sécurité, nettoyage
(1) Tablette et ordinateur : le site n’est plus la version téléphone étirée. Dès 1024 px, l’en-tête affiche Accueil,
Boutique, Wallet (ou Commandes) et Compte avec une vraie barre de recherche ; tout est aligné sur la même largeur.
Boutique : colonne des catégories à gauche, 3 ou 4 produits par ligne. Produit : photo à gauche, packs et ID joueur à
droite, « Ajouter au panier » et « Acheter » toujours visibles. Paiement : récapitulatif ouvert à droite. Flèches à la
souris sur les rangées de produits, FAQ plus lisible. Sur téléphone, rien ne change à part les corrections (5).
(2) Parcourir : Jeux, Gift Card, Abonnement, Réseaux sociaux, Tout. « Dream social » est retiré. Le changement se fait
une seule fois, à la première ouverture d’une page de l’administration après la mise à jour ; l’ancienne version reste
dans les révisions de la page d’accueil. Sur téléphone, une rangée qui glisse et « Abonnement » n’est plus coupé.
(3) Vitesse et cache : une synchronisation fournisseur ou une commande ne vide plus tout le cache, seulement la page du
produit ; l’accueil, la boutique et les catégories sont rafraîchis au plus toutes les 10 minutes, si un prix ou une
disponibilité change. Une page gardée en cache vit au plus 10 heures. Sur ordinateur, un lien commence à charger dès que
la souris s’y pose. Défilement plus fluide, logo plus léger, scripts des packs et des promotions en fichiers mis en
cache (promotions envoyées seulement quand un événement est prévu ou en cours). « Produits par page » : jusqu’à 24.
(4) Sécurité : la liste des adresses de l’API reste cachée aux visiteurs, même tapée en majuscules ; un lien mal saisi
dans le Builder n’est plus publié (avis « Lien invalide ») ; la vérification de l’ID joueur garde le bon jeton après une
connexion ou une déconnexion dans le même onglet.
(5) Corrections : suggestions de recherche sans prix en double, « Plus que 3 en stock » en français, réponses de la FAQ
lisibles en mode clair, case des conditions à côté de sa phrase, bord droit de la boutique aligné, textes lus par les
lecteurs d’écran en français.
(6) Nettoyage : code et styles qui ne servaient jamais retirés (branches vers des fichiers jamais livrés, anciennes
classes, doublon du garde de l’ancienne cloche) ; les anciennes notes de version (avant 9.12.4) sont retirées de ce
fichier, l’historique complet reste dans les fichiers CHANGESET livrés avec chaque version.
Après installation : vider le cache LiteSpeed (Purge All), puis Header Studio › largeur du contenu = 1248.

9.13.0 — Corrections de l’audit sécurité / vitesse du 12 novembre 2026 (partie Builder)
(1) Vérification du Player ID sur une page en cache : les jetons de sécurité des fournisseurs (Fazercard, Shop2TopUp,
Top-Up Connector) imprimés dans une page gardée en cache expiraient après 12 à 24 h et « Vérifier » répondait « -1 »
ou « Session expirée ». Au premier toucher d’un champ fournisseur, le Builder demande des jetons frais au serveur et
les met à jour (aussi après un changement de région) ; un « Vérifier » touché pendant cette demande attend 4 s au plus.
(2) Le Top-Up Connector ne charge plus son CSS/JS (≈ 15 Ko) sur les produits qu’il ne vend pas.
(3) Builder › Security Studio : « Masquer l’index de l’API aux visiteurs » (activé par défaut). Les visiteurs ne voient
plus la liste des extensions dans /wp-json/ ; les routes, l’application Android et les paiements fonctionnent comme
avant. Décochez si l’application WooCommerce, Jetpack ou Site Kit ne trouve plus le site.
(4) Petites corrections : clé d’application des notifications refusée quand l’API Délicat envoie les notifications ;
scripts toujours en https ; formulaire de lettre d’information en https ; redirection HTTPS permanente (301).
(5) Quand un groupe de l’API Switcher change de fournisseur, les pages des produits concernés sont aussi vidées du
cache LiteSpeed une par une.
(6) Plus rapide : 8 lectures de la base et 2 déchiffrements en moins à chaque page non mise en cache (réglages lus à
chaque visite rendus « autoload », clés Web Push et Cloudflare lus seulement quand ils servent).

9.12.5 — Changement de région rapide sur réseau lent
(1) Les régions (Amérique du Nord, Europe…) pointent vers la vraie page du produit au lieu du lien de redirection de
l’API Switcher (/?delicat_product=…) : sur un téléphone lent, la redirection seule coûtait ~1,9 s. Le lien est calculé
au chargement de la page à partir du réglage de l’API Switcher (aucun appel distant), gardé en cache et effacé dès
qu’un groupe, un produit ou les permaliens changent ; si la page ne peut pas être trouvée, l’ancien lien est gardé.
(2) Au toucher : la région choisie s’affiche tout de suite avec un petit indicateur, « n / N » suit, le bloc PACKAGE
s’estompe avec une fine barre en haut ; la page ne devient jamais blanche. Après 8 s, « Ouvrir la page » propose un
chargement normal.
(3) Sur réseau lent, la page de la région est demandée dès que le doigt se pose (une seule requête, dans le budget) ;
toujours aucun préchargement de fond sur réseau lent ; sur réseau rapide, les 2 régions voisines restent préparées.
Mesure (1,4 Mbit/s, 250 ms, processeur ×4) : Amérique du Nord → Europe 4,1 s → 2,2 s.

9.12.4 — Paiement express plus sûr, premier toucher jamais perdu, messages sous les champs, moins de requêtes
(1) Paiement Wallet : la demande attend jusqu’à 2 minutes (la livraison du fournisseur peut se faire pendant le
paiement) ; après 8 s « Paiement en cours… » et « La livraison peut prendre jusqu’à une minute, ne fermez pas cette
fenêtre ». La demande n’est jamais renvoyée. Délai dépassé, connexion coupée, page 403/502/503/504 de l’hébergeur ou
« demande déjà envoyée » : plus de message rouge, la fenêtre affiche « Nous vérifions votre paiement… » et vérifie le
résultat toutes les 3 s pendant 3 minutes (onglet visible) : confirmé → « Paiement confirmé » puis la page de la
commande ; reçu → « Commande reçue — paiement en cours de vérification » ; refusé → message clair et commande
rechargée ; toujours inconnu → « Consultez vos commandes ». Boutons « Vérifier mes commandes » et « Vérifier ce
paiement » séparés (44 px), avis orange pour une vérification, rouge seulement pour un vrai échec.
(2) Serveur : une même demande renvoyée répond « duplicate » (la fenêtre vérifie au lieu d’afficher une erreur) ; un
autre paiement en cours répond « busy ». Aucune protection n’est assouplie, aucune règle de débit ne change.
(3) Premier toucher avec LiteSpeed « Delay JS » : « Acheter » / « Ajouter au panier » répondent toujours. Le bouton
tourne tout de suite et l’action repart dès que la page est prête (10 s au plus, puis envoi classique du formulaire
avec le pack choisi).
(4) Champs obligatoires : sans pack « Choisissez un pack. » ; Player ID vide « Entrez votre ID joueur » ; e-mail
Netflix vide « Entrez votre adresse e-mail » (ou le message du navigateur) : le champ est amené à l’écran, entouré,
le message est juste dessous et disparaît dès qu’on écrit.
(5) Moins de requêtes (contre les 403 de l’hébergeur) : préchargement 3 au plus par page, un à la fois, 1,5 s
d’écart, seulement sur un vrai toucher ; régions : les 2 voisines après 3 s de calme ; règles de préchargement du
navigateur « conservative » ; session, ventes en direct (60 s), notifications (120 s) et promos espacées ; après un
403 / 429 / 503, plus aucune requête de fond pendant 10 minutes (tous les onglets).
(6) Reprend la 9.12.3 du propriétaire (connexion tactile, Identity Pro 6.17.7).

Install / acceptance test
-------------------------
1. Back up WordPress database + wp-content.
2. Upload this ZIP over the current Delicat Builder V9 and activate/replace the existing copy. Keep only ONE active Builder V9 copy.
3. Purge LiteSpeed Cache and Cloudflare/CDN once.
4. On the affected Android browser, first test in a Chrome Incognito tab. For an already-installed PWA/browser session that still shows the pre-update page for a few minutes, close/reopen the tab or clear that site's cached data once so the new service worker takes control.
5. Test: Google login -> sign out -> tap Compte -> Google login again. The customer must stay in the Delicat modal/storefront and must not land on wp-login.php.
6. Scroll the homepage. The Assistant must sit at the bottom-right edge above the Bottom Nav and must not show a “0” badge or promotional sentence.
7. Open the Assistant. The panel must stay inside the viewport, below the WordPress toolbar and above the Bottom Nav.
8. Reopen/close the menu and visit a product page. The Assistant panel must clear the Bottom Nav and purchase dock without covering controls.
9. Confirm the deleted standalone green WhatsApp button stays absent while WhatsApp links inside the Assistant, menu and footer still work.
10. Toggle light/dark mode. Only one Delicat header logo may be visible.
11. Finally test cart, wallet, checkout and an order flow as a production acceptance check.

Recommended production settings
-------------------------------
- Keep LiteSpeed page cache enabled for genuinely public GET pages.
- Keep JS Combine OFF for Builder/WooCommerce; Builder manages its own load order.
- Use persistent object cache when Hostinger provides Redis/Object Cache.
- Keep Cloudflare Brotli and HTTP/3 enabled; avoid Rocket Loader on WooCommerce/Builder/Identity scripts.
- Keep only one active Delicat Identity Pro and one active Delicat Builder V9 installation.

Authority and security
----------------------
WordPress/WooCommerce remain account, cart, checkout, payment and order authority. Delicat Identity Pro remains authentication/security authority when enabled. RC71.6 changes Assistant positioning and retired-button cache cleanup only; it does not accept an invalid Google callback or bypass any authentication or commerce check.
