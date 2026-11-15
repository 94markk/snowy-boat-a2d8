9.14.4 — Page encore trop large sur iPhone : correctif renforcé + diagnostic précis (15 novembre 2026)
(1) Le diagnostic de la 9.14.3 a montré la vraie cause : la page mesurait 1402 px sur un écran de 440 px, alors
qu’aucun élément visible ne dépassait. Ce qui dépasse est donc dessiné « contre la fenêtre » (un élément positionné
ou un décor ::before/::after d’une extension, par exemple la bulle d’assistance), hors de portée de la protection
de la 9.14.3. La 9.14.4 fait du corps de la page le cadre de ces éléments : ils sont coupés au bord et Safari ne
peut plus dézoomer la page. Pour les clients rien ne bouge ; pour l’équipe, la barre WordPress reste à sa place.
(2) Diagnostic (administrateurs, ?dbv9_turbo_diag=1) : il retire un instant la protection, mesure la largeur réelle
et suit pas à pas l’élément responsable (cadre, ::before/::after, texte, extension) ; la ligne « Cause » le nomme.
Technique : chrome-fixes.css, body position:relative (avec le clip de la 9.14.3) et #wpadminbar top:-46px sous
601 px. Seule la feuille « chrome » change. Après installation : Purge All (LiteSpeed) et CDN.

9.14.3 — Page Compte sur iPhone : plus de page rétrécie (15 novembre 2026)
(1) Sur iPhone, la page Compte pouvait s’afficher rétrécie : tout le contenu tassé dans le tiers gauche de l’écran,
le reste vide, et la barre du bas posée sur le pied de page. Cause : un élément qui dépasse le bord droit de l’écran
fait dézoomer Safari sur toute la page (la règle html{overflow-x:hidden} de la 9.14.2 cachait cet élément mais
Safari mesurait quand même la page avec). Désormais ce qui dépasse est coupé au bord sans compter dans la largeur :
la page reste à 100 %, la barre du bas reste en bas, au-dessus du pied de page. Vaut pour toutes les pages.
(2) Petits téléphones (320 px) : le logo de l’en-tête se réduit un peu pour que le panier ne sorte plus de l’écran.
(3) Le menu, les tiroirs, les fenêtres, l’en-tête collant et la barre WordPress (administrateurs) fonctionnent comme
avant. Mêmes écrans au pixel près sur iPhone (clair et sombre) et sur ordinateur.
(4) Pour l’administrateur : n’importe quelle page ouverte avec ?dbv9_turbo_diag=1 affiche « Page width » et, si un
élément dépasse encore, son nom (lecture seule, administrateurs seulement). En cas de souci, envoyer cette ligne.
Technique : chrome-fixes.css, overflow-x:clip (iOS 16+, Android récents) sur body (« html body » passe devant le
« visible » de storefront-polish), le pied de page et #wpadminbar ; min-width:0 sur le logo de l’en-tête. Seule la
feuille « chrome » change (règles ajoutées à la fin) ; les autres feuilles sont identiques octet pour octet.
Après installation : vider le cache LiteSpeed (Purge All) et le CDN.

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
