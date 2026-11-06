9.9.0 — Référencement Google : fausses adresses supprimées, catégories de tous les fournisseurs, doublons, fil d’Ariane (plan de confiance, SEO-02 à SEO-06)
Contrôle du site en ligne le 6 novembre 2026 : le plan du site (wp-sitemap.xml) proposait à Google 127 adresses, dont des
pages de test ou vides, les catégories techniques des fournisseurs et des catégories en double.
Chaque page et chaque catégorie a maintenant un des trois états suivants :
 - proposée à Google (comme avant) ;
 - cachée de Google : elle s’ouvre toujours, mais porte « noindex » et sort du plan du site ;
 - redirigée : ce n’est pas une vraie page. Visiteurs et Google arrivent directement (redirection 301) sur la vraie page, et
   l’adresse disparaît du plan du site, des rubriques de la boutique et des raccourcis de recherche. Dans le menu latéral,
   une entrée vers une page de test ou un nom de fournisseur disparaît ; une entrée vers une copie (catégorie qui répète
   un produit) mène directement au produit.
(1) Cachées de Google, toujours ouvertes : connexion, inscription, mot de passe oublié, maintenance, « mes-donnees »,
« delete-account » (demandée par Google Play), « free-fire-redeem », « adm-free-fire ».
(2) Redirigées vers l’accueil : « test », « test-2 », « accueil » (texte modèle du thème), « sample-page », l’article
d’exemple « Hello world! » de WordPress et les pages vraiment vides (« comment-ca-marche », « bon-kliyan » sur le site en
ligne). Une page n’est jamais jugée vide si elle a du texte, une image, un code court, un bloc, une mise en page
Elementor ou Delicat Builder, un modèle, une sous-page, ou si une extension l’affiche (portefeuille, application,
parrainage, revendeurs, bonus). « sondaj » reste proposée.
(3) Catégories créées par les fournisseurs — tous, pas seulement Delicat Digital : une catégorie dont l’adresse contient un
code machine (« delicat-topup-c9e2614d39 », « gp-9f3a7c21d0 »…) est redirigée vers son produit (ou vers sa vraie
catégorie parente, sinon la boutique). Les catégories au nom d’un fournisseur (« Delicat Digital », « Direct Topup »,
« EU Direct Topup », tout nom contenant « Direct Topup », « Topup API », « fournisseur » ou « supplier ») et celles qui
regroupent ces catégories techniques sont redirigées vers la boutique. Un fournisseur ajouté demain est traité sans rien
faire, dès que sa synchronisation crée ses catégories.
(4) Doublons : une catégorie qui ne contient qu’un produit du même nom (« eFOOTBALL », « ONE STATE RP », « Poppo Live »)
redirige vers ce produit ; deux catégories au même nom (« Réseaux sociaux » / « Reseaux Sociaux ») : celle qui a le plus
de produits reste, l’autre y redirige ; « Uncategorized » redirige vers la boutique ; l’étiquette « 🎮 JEUX » redirige
vers « JEUX ». Les pages d’étiquettes ne sont plus proposées (elles répètent les catégories ; les badges restent).
Important : les produits gardent toutes leurs catégories. La synchronisation des fournisseurs, les prix revendeurs,
l’assistant et les commandes ne changent pas ; seul ce que voient Google et les visiteurs change.
(5) « rewards-program » et « program-bon-kliyan » affichent le même écran : la première (utilisée par l’application)
reste ouverte et indique à Google que l’originale est « program-bon-kliyan ». La boîte « Page originale » de l’éditeur de
page permet de faire de même pour d’autres doublons (adresse complète ou « /ma-page/ »).
(6) Fil d’Ariane : il ne passe plus par les catégories des fournisseurs ni par les doublons (« Accueil › Delicat Digital
› Free Fire (LATAM) › Free Fire (LATAM) » devient « Accueil › Free Fire (LATAM) »). Un seul fil d’Ariane est envoyé à
Google sur les pages produits et catégories, sans « &amp; » parasite.
(7) Titres : un suffixe produit qui répète le nom du site (« Delicat Store ») n’est plus ajouté : fini « FREE FIRE PIN
Delicat Store – Delicat Store ».
(8) Données pour Google : pas de description vide pour un produit sans description ; liens vers vos profils (Facebook,
TikTok, Instagram) envoyés sans codes de suivi (?mibextid=, ?_r=, ?utm_source=…).
(9) Conditions d’utilisation : le lien « programme revendeur » menait à « /programme-revendeur/ », une page qui n’existe
pas sur le site en ligne (erreur 404). Il mène maintenant à la vraie page du programme revendeur (Delicat Resellers), ou à
« Mon compte » si elle n’existe pas. Le texte des conditions n’a pas changé, seulement l’adresse du lien.
(10) « sondaj » (page Elementor) affichait « [delicat_v8_hero] » en clair au-dessus du sondage : l’en-tête s’affiche de
nouveau correctement.
(11) Delicat Builder → SEO, partie « Ce que Google voit » : pour chaque page et catégorie, un choix « Recommandé » (la
règle ci-dessus, affichée), « Proposée à Google », « Cachée de Google » ou « Redirigée ». Vos choix passent avant les
règles ; « Recommandé » revient à la règle. Les catégories techniques des fournisseurs sont résumées sur une ligne. La
liste « Produits sans vraie catégorie » montre les produits rangés seulement dans une catégorie de fournisseur : cochez-
leur une vraie catégorie (Jeux, Gift Card, Abonnement…) pour qu’ils apparaissent dans les rayons et dans le fil
d’Ariane. L’accueil, la boutique, le panier, la caisse, « Mon compte » et les pages légales restent toujours proposés.
Vous (éditeurs) ouvrez toujours toutes les adresses, sans redirection, pour pouvoir les vérifier. Enregistrer vide les
caches (LiteSpeed et Delicat).
Déjà en place et vérifié sur le site en ligne : les adresses avec paramètres (tri, filtres, recherche) ne sont pas
indexées, les fiches produits ont leur adresse canonique propre, les anciennes adresses redirigent (301), les produits
et la pagination sont de vrais liens dans le HTML envoyé (pas de chargement uniquement en JavaScript).

9.8.1 — Avis : la même note partout
(1) Fiche produit et fenêtre « Voir tous les avis » : la note moyenne est maintenant affichée comme sur la page
d’accueil, avec une décimale arrondie vers le bas (une moyenne de 4,87 s’affiche 4,8 et non plus 4,9), comme le
promet la politique des avis (« sans arrondi favorable »). Les étoiles et les informations lues par Google gardent la
moyenne exacte.

9.8.0 — Avis clients : des chiffres réels et vérifiables (plan de confiance, TR-01)
(1) Page d’accueil, bloc « Ils nous font confiance » : la note et le nombre d’avis sont maintenant calculés à partir
des avis publiés, et ne peuvent plus être tapés à la main. Avant, la note (4.8) était saisie dans le Builder et le
nombre affiché additionnait un chiffre saisi à la main et le vrai nombre d’avis ; c’est pourquoi l’accueil pouvait
annoncer « 300 avis vérifiés » quand la page « Politique des avis clients » parlait de 81.
(2) Les avis sur la boutique (menu « Avis clients ») et les avis sur les produits (Produits → Avis) sont comptés à
part, chacun avec sa propre moyenne, notes basses comprises, affichée avec une décimale arrondie vers le bas (4,79
s’affiche 4,7, jamais 4,8). Les étoiles se remplissent selon la vraie moyenne (4,6 n’affiche plus cinq étoiles
pleines). Un lien « Comment nous traitons les avis » mène à la politique des avis. Un avis enregistré deux fois par
une importation ne compte qu’une fois, et un produit dépublié sort des chiffres tout de suite.
(3) La coche verte sur une carte d’avis veut dire « Achat vérifié » : elle n’apparaît plus que sur les avis d’un
client qui a une commande payée (ou d’un acheteur du produit, pour un avis produit). Les phrases tapées dans le
Builder s’affichent sans coche. Les deux exemples livrés avec l’extension (« Recharge Free Fire reçue en moins de
2 minutes… » de JEAN-MARC, « Mon abonnement a été livré… » de MIKAËLL) n’étaient pas de vrais avis : ils ne
s’affichent plus.
(4) Comme l’annonce la politique des avis (section 1), seul un client qui a déjà une commande payée sur son compte
peut envoyer un avis depuis la fenêtre « Laisser un avis ».
Les autres ne reçoivent plus l’invitation et, s’ils touchent le bouton, la fenêtre leur dit tout de suite que les
avis sont réservés aux clients, avant qu’ils écrivent. Un avis sans note n’est plus montré avec des étoiles
inventées. Pour que la même règle vaille aussi pour le formulaire d’avis de WooCommerce : WooCommerce → Réglages →
Produits → cocher l’option qui réserve les avis aux « propriétaires vérifiés ».
(5) « Politique des avis clients » : les chiffres « 4,8 sur 81 avis » écrits dans le texte sont retirés (ils ne
pouvaient que devenir faux) et la section 5 précise que boutique et produits sont comptés séparément. La page est
mise à jour automatiquement si elle n’a pas été modifiée à la main ; si vous l’avez modifiée, elle reste telle quelle
et il faut retirer ces deux chiffres vous-même.
(6) Delicat Builder → Homepage Studio → « 3. Layout health & repair » : nouvelle ligne « Avis affichés sur l’accueil » avec les chiffres
exacts (nombre, moyenne, achats vérifiés) pour les comparer aux listes d’avis.

9.7.9 — Nouvelles cartes « Avec Délicat vous pouvez ! »
(1) Le bandeau en cartes de la page d’accueil a trois nouveaux styles, au choix dans le Builder (section Bannière →
Style des cartes) : « Nuit » (cartes en dégradé profond de leur couleur, grand pictogramme en filigrane, bouton blanc
avec une flèche), « Épuré » (carte blanche, pastille de couleur avec l’icône, bouton pleine largeur) et « Vitrine »
(bandeau illustré ou photo de la carte en haut, icône posée sur le bord). Les trois suivent le mode clair et le mode
sombre du site.
(2) Les cartes Android et WhatsApp sont reconnues toutes seules (par le lien ou le titre) : icône Android en bleu
Délicat, logo WhatsApp en vert. Pour une autre carte, une icône peut être choisie (« Carte 1 — icône », ou 7e
colonne des lignes « Autres cartes », par exemple redeem, bolt, sports-esports ; « none » pour aucune).
(3) Une couleur de carte claire (jaune, par exemple) garde un texte de bouton lisible (texte foncé au lieu de blanc).
(4) Un bouton laissé avec le texte « button » ou « bouton » affiche « Rejoindre » (WhatsApp), « Télécharger »
(Android) ou « Découvrir ». Les liens vers un autre site (WhatsApp, Play Store) s’ouvrent dans un nouvel onglet.
(5) L’ancien style « teinté » s’affiche désormais en « Nuit » ; rien à refaire dans les réglages.

9.7.8 — Sécurité des paiements, boutique plus légère sur téléphone, référencement Google
(1) Paiement : un client dont le paiement avait réussi mais dont la page s’était arrêtée juste après (envoi d’e-mail,
livraison) restait bloqué sur « Un paiement est déjà en cours » à chaque commande, jusqu’à ce qu’un administrateur
clique « Libérer ». Le verrou se libère maintenant tout seul après 5 minutes quand la commande est payée (ou annulée,
remboursée) et appartient à ce client ; une commande « en attente » de confirmation MonCash/NatCash garde le verrou ; l’ancien écran de paiement est refusé, le même panier ne peut pas être payé deux fois par erreur.
(2) Recherche de l’en-tête : les suggestions (avec leurs prix) étaient gardées 30 minutes pour tout le monde selon le
seul mot cherché ; un revendeur qui cherchait le premier faisait voir ses prix à tous. Elles ne sont plus partagées
qu’entre visiteurs non connectés, séparées par devise et par vue application ; un client connecté reçoit toujours
ses propres prix.
(3) Téléphone et données mobiles : la navigation ne télécharge plus deux fois la page d’accueil (un lien
« #connexion » comptait comme une autre page), ne précharge plus les liens de connexion ni la page affichée, et
le préchargement en arrière-plan se fait seulement pour les visiteurs non connectés sur une bonne connexion 4G.
Le portefeuille et le compte ne sont plus préparés en arrière-plan à chaque visite : seulement quand le doigt
touche le lien. Le rappel « Laisser un avis » n’interroge plus le serveur à chaque page (une fois par demi-heure).
(4) Sans connexion : toucher un produit hors connexion affiche « Pa gen koneksyon · Réessayer » sur la même page au
lieu de l’écran d’erreur du navigateur, et une page de la boutique ouverte hors connexion affiche la page « Vous êtes
hors ligne » de Delicat. Avec un serveur lent, la navigation n’attend plus deux fois de suite (jusqu’à 42 s) : elle
passe directement au chargement normal.
(5) Application installée : elle ne télécharge plus à nouveau ~950 Ko à l’installation (les fichiers déjà en cache
sont réutilisés) et ne garde plus les scripts de la barre d’administration ; son « squelette » a son propre cache
qui n’est plus vidé par les images ; le serveur n’est plus réveillé à chaque vérification de mise à jour (Cloudflare
répond pendant 5 minutes, sauf pendant une maintenance).
(6) Fiches produit à options : les 30 Ko de styles des options sont un fichier mis en cache au lieu d’être répétés
dans chaque page. Mon compte : select2, les icônes de l’administration et un style du paiement en blocs ne sont plus
chargés là où rien ne les utilise.
(7) Confidentialité : la boutique ne demande plus la photo Gravatar du client (cela envoyait à un service extérieur
une empreinte de son e-mail à chaque page) ; une photo envoyée par le client reste affichée, sinon l’icône ou
l’initiale de la boutique.
(8) Lisibilité et doigts : textes plus contrastés (bandeau « Delicat en application », stock et total de la fiche
produit, boutons du panier), plus de texte de 10 px dans la barre d’achat et les pastilles de catégories, et
pastilles, pagination, tri, logo et bouton retour du panier d’au moins 44 px de haut.
(9) Google : le fil d’Ariane envoyé à Google affiche « Jeux & Recharges » (et non « Jeux &amp; Recharges ») et
« Boutique » au lieu de « Shop » ; la description de secours des catégories est plus courte (affichée en entier) ;
les pages panier, paiement et compte n’ont plus « You may be interested in… » comme description ; la boutique et
les catégories sans image ont l’image de leur premier produit pour les partages WhatsApp/Facebook. Les données
produit indiquent la marque (si une marque WooCommerce ou un attribut « Marque » existe) et la boutique comme
vendeur. Nouveau dans Delicat SEO : « Retours » et « Délai de livraison maximal » ; vides par défaut, rien n’est
déclaré tant que vous ne les remplissez pas (mettez seulement ce que disent vos conditions de vente).
(10) iPhone : le bandeau « Delicat en application » ne s’affiche plus sur la première page d’un visiteur venu de
Google ou d’un autre moteur de recherche (il apparaît à la page suivante).
(11) Fermeture pour maintenance : une page vue par le lien secret ou une adresse autorisée n’est plus gardée en
cache ; avant, LiteSpeed pouvait montrer la boutique ouverte à tout le monde.
(12) Produits calculateur : un prix qui tombe à 0,00 G après arrondi est refusé à l’ajout au panier (« Ce produit
n’a pas de prix valide pour le moment ») au lieu d’être vendu gratuitement ; l’erreur est notée dans les journaux
WooCommerce. Les pages qui affichent un message WooCommerce (« ajouté au panier »…) ne sont plus mises en cache.
(13) Sécurité : une erreur d’une autre extension (passerelle de paiement…) dont la trace passe par le Builder ne met
plus toute la boutique en mode sans échec ; seules les erreurs du Builder comptent. Le cache n’est plus vidé à
chaque fin automatique du mode sans échec (au plus une fois toutes les 6 heures). Les anciennes routes « appareil »
de l’application (clé intégrée à l’APK) acceptent au plus 30 nouveaux téléphones par heure et par connexion ; un
téléphone déjà connu reste toujours accepté. HSTS d’un an sur toutes les pages.
(14) Protection « mise à jour » de 9.7.7 corrigée (mon erreur) : elle ne compte plus que les fichiers de code, s’arrête
au bout de 10 minutes, et wp-admin affiche un avertissement rouge qui nomme les fichiers manquants avec la marche à
suivre. Avant, un seul fichier manquant (même un README) désactivait pour toujours le mode sans échec sans prévenir.
(15) Nouvelle petite extension « Delicat Guard » (dossier mu-plugins), installée automatiquement : elle garde
l’éditeur de fichiers fermé et XML-RPC coupé même si Delicat Builder est désactivé, et affiche alors « Delicat
Builder V9 ne fonctionne pas » dans wp-admin. Elle est supprimée si vous supprimez Delicat Builder.
(16) Nouveau dans Purchase Studio : « Panier et paiement légers ». Un bouton passe le panier et le paiement de la
version « blocs » de WooCommerce (environ 730 Ko de scripts, près de 2 s de blocage sur un téléphone d’entrée de
gamme) à la version classique, déjà mise en forme par le Builder. Le contenu actuel des deux pages est gardé et un
bouton permet de revenir en arrière. Rien ne change tant que vous ne cliquez pas : faites ensuite un achat test
(wallet, MonCash, NatCash).
(17) Technique : le moteur (sources lisibles et outil de construction) est maintenant dans le dépôt, dossier
tools/builder (hors du zip). Pour Cloudflare : ajoutez une règle de limite sur les adresses contenant
« wc-ajax=delicat_session » ou « dbp_state=1 » (par ex. 120 requêtes par minute et par adresse), le serveur n’a pas de cache objet pour
les limiter lui-même.
(18) Installer : Extensions → Ajouter → Téléverser le zip → « Remplacer la version actuelle » (pas besoin de
désactiver : 9.7.7 et 9.7.8 gèrent la mise à jour). Puis purger LiteSpeed et Cloudflare. Réglages et données
sont conservés.

9.7.7 — Mise à jour sans coupure de la boutique, images des cartes nettes
(1) Mise à jour : le Builder ne se désactive plus tout seul pendant une mise à jour. WordPress remplace les fichiers
un par un ; un visiteur arrivé à ce moment-là provoquait une erreur « fichier introuvable » et le Builder se retirait
des extensions actives : la boutique restait sur le thème de base (en-tête et pied de page simples, page vide)
jusqu’à ce qu’on le réactive. Désormais ces visites voient la page sans le Builder, sans rien enregistrer, et la
visite suivante charge la nouvelle copie.
(2) Coupure automatique : seule une erreur qui se répète (la même trois fois en dix minutes) peut encore désactiver
le Builder ; une erreur isolée met seulement le mode sans échec pendant dix minutes, comme avant.
(3) Cache : une page affichée sans le Builder (mise à jour en cours, mode sans échec) n’est plus gardée par LiteSpeed
ni Cloudflare. Quand le Builder redevient complet (fin du mode sans échec, réactivation, nouvelle version), les
caches sont vidés une fois, automatiquement.
(4) Boutique, catégories et accueil : les images des cartes produit ne restent plus floues, sombres ou « zoomées ».
Seule la première carte s’affichait nettement ; les autres gardaient l’image d’attente floue du chargement différé
de LiteSpeed. Les cartes sont maintenant exclues de ce chargement différé (le navigateur charge déjà les images au
bon moment). La correction de 9.7.6 n’avait aucun effet : LiteSpeed compare cette liste d’exclusion à l’adresse de
l’image, pas à sa classe ; les classes sont maintenant dans la bonne liste.
(5) Boutique et catégories : chaque image remplit exactement le carré de sa carte. Les images chargées au défilement
(toutes sauf la première) étaient dessinées sur 1 500 px de haut dans un carré d’environ 180 px : on n’en voyait
qu’une bande agrandie, d’où l’effet « zoom ». Une règle anti-décalage des images (hauteur automatique) l’emportait
sur la règle de la carte ; la carte impose maintenant sa hauteur.
(6) Installer cette version : dans Extensions, si « Delicat Builder V9 Pro » est actif, le désactiver d’abord
(l’ancienne version n’a pas encore la protection du point 1) ; téléverser le zip, « Remplacer la version actuelle »,
puis Activer. Purger ensuite LiteSpeed et Cloudflare.

9.7.6 — Téléphone : panier toujours à jour, bandeau de l’application sous l’en-tête, images du catalogue
(1) Panier de l’en-tête : il affiche maintenant les articles ajoutés sans recharger la page. À chaque ouverture (et
après un ajout pendant qu’il est ouvert), il demande le panier du client à WooCommerce ; avant, il gardait le
panier de la page affichée (vide sur une page en cache ou ouverte par la navigation de l’application).
(2) Panier de l’en-tête sur téléphone : il s’ouvre sous le bouton panier et reste entièrement dans l’écran (il
débordait à droite, la croix était coupée).
(3) Bandeau « Delicat en application » (iPhone) : il s’affiche sous l’en-tête au lieu de le recouvrir ; le logo, la
cloche et le panier restent visibles et utilisables, et le panier ouvert passe au-dessus du bandeau.
(4) Fenêtre « Installer Delicat Store » : elle tient dans l’écran (elle dépassait à droite et la croix était coupée).
(5) Boutique et catégories : les images des produits ne restent plus floues ou noires. Les cartes produit sont
exclues du chargement différé de LiteSpeed (le navigateur charge déjà les images au bon moment) et, après un
changement de page dans l’application, les images encore en attente de LiteSpeed sont chargées.
(6) Fiche produit : la barre d’achat affiche « Choisissez une option » en entier (sur deux lignes) au lieu de
« Choisissez u… ».
(7) Technique : nouvelle requête WooCommerce ?wc-ajax=delicat_builder_v9_header_cart (panier du visiteur, privée,
jamais en cache) ; moteur reconstruit. Installer puis purger LiteSpeed et Cloudflare.

9.7.5 — L’ancienne carte du catalogue est supprimée ; pages plus légères
(1) Boutique, catégories, étiquettes et recherche : la carte de l’accueil est maintenant la seule carte. L’ancienne
carte (« Disponible », bouton « Choisir → ») est supprimée du code : elle ne peut plus revenir, même si d’anciens
réglages enregistrés la demandaient (c’était le cas après la 9.7.4 tant qu’aucun administrateur n’avait ouvert
l’administration).
(2) Pages plus légères, sans changement visible : la feuille de style de l’en-tête n’est plus téléchargée deux fois
(elle était déjà dans le paquet principal ; 27 Ko et une requête de moins sur toutes les pages), et la feuille des
blocs WooCommerce (14 Ko) n’est plus chargée sur les pages du catalogue, qui n’en affichent aucun. Page Boutique :
CSS 301 Ko → 257 Ko, 7 → 5 fichiers.
(3) Catalogue › Archive Builder : les réglages qui ne servaient qu’à l’ancienne carte (Style des cartes, Disponibilité,
Nom du produit, Promotion, Texte du bouton, tailles du titre et du prix) ne sont plus affichés ; leurs valeurs
restent enregistrées.
(4) Technique : fichiers inutiles retirés de l’extension (design-system/ : notes et export de jetons jamais chargés) ;
règles CSS de l’ancienne carte retirées (native-archive.css, styles critiques du catalogue, app-polish, products,
design-studio). Installer puis purger LiteSpeed / Cloudflare.

9.7.4 — Boutique et catégories : les cartes produit de l’accueil
(1) Pages Boutique, catégories, étiquettes et recherche : chaque produit s’affiche avec la même carte que sur
l’accueil : l’image avec ses badges (#1, Promo, Top Vente…) et le nom du produit dessus, le cœur des favoris, et
le bas bleu nuit avec « À partir de G145 » et le bouton panier rond. Un produit épuisé affiche « Indisponible ».
(2) Catalogue › Style des cartes : « Comme l’accueil » (nouveau, par défaut) ou « Classique » (l’ancienne carte).
À la mise à jour, toutes les pages du catalogue passent à « Comme l’accueil » (préréglage archive-v5-home, appliqué
à la première visite d’un administrateur) et le cache des pages produits est vidé.
(3) Technique : la carte reprend les classes de la carte de l’accueil ; ses badges et son cœur viennent des mêmes
moteurs (chargés aussi sur ces pages) ; nouveau fichier assets/css/archive-cards.css dans les styles « shop ».
Les prix restent ceux de WooCommerce (prix revendeur compris). Installer puis purger LiteSpeed / Cloudflare.

9.7.3 — Mode sombre : un seul cadre, plus de « carte dans une carte »
(1) En mode sombre, les sections qui entouraient d'un second cadre des cartes déjà encadrées n'en dessinent
plus : la zone du compte (Portefeuille, Commandes, commandes récentes, favoris, informations), la photo et le
titre d'une fiche produit, le bloc des options d'achat autour de « Forfait / Package », et le tableau « Mes
commandes » sur téléphone (chaque commande reste une carte). Les cartes elles-mêmes ne changent pas.
(2) Le message WooCommerce « Confirmez votre adresse e-mail » au-dessus des commandes n'est plus blanc en mode sombre.
(3) En mode clair, rien ne change.
Installer avec Delicat Identity 6.17.3 (ligne grise restée après un toucher dans le compte), puis purger
LiteSpeed / Cloudflare.

9.7.2 — Pages de commande : retour à l’apparence de la 9.5.5
(1) Page « Merci / Commande livrée / Livraison en cours » et page « Détails de la commande » : les couleurs
propres à ces pages reprennent leurs valeurs de la 9.5.5 (vert des étapes terminées et de l’étiquette « Livrée »,
rouge, orange, gris, bordures, confettis de la livraison), en mode clair comme en mode sombre. La couleur d’accent
suit la couleur de la marque (bleu) au lieu de l’ancien violet ; le fond de « Détails de la commande » reste celui
de l’espace client.
(2) Mode sombre : le texte blanc des boutons et des étapes en cours reste lisible (contraste ≥ 4,5:1), comme en 9.7.1.
Installer avec Delicat Identity 6.17.2 et Delicat Recharge 6.6.3 (inchangés), puis purger LiteSpeed / Cloudflare.

9.7.1 — Correctif : page de confirmation de commande illisible et contrastes en mode sombre
(1) Page « Merci / Commande livrée / Livraison en cours » : le bandeau était vert ou bleu vif avec un texte
sombre presque invisible (depuis la 9.6.0). Il retrouve son fond clair (vert pâle, bleu pâle) avec un texte
lisible ; en mode sombre, fond sombre avec une teinte légère. Les pastilles des étapes et le lien « Actualiser »
sont de nouveau lisibles.
(2) Mode sombre : les boutons et pastilles colorés gardent un texte blanc lisible (contraste ≥ 4,5:1) : « Ajouter
au panier », « Acheter maintenant », « Passer la commande », « Voir » (commandes), « Partager le reçu », le bouton
Support du pied de page, le compteur de notifications, l’étiquette « Livrée » et les boutons du formulaire de
recharge. En mode clair, rien ne change (vos couleurs personnalisées restent appliquées).
(3) Les pages restées volontairement sombres ou claires retrouvent leurs couleurs : thèmes de la page de
maintenance « Midnight », « Spotlight » et « Flash » (texte blanc), paiement en mode sombre, préréglages de menu
sombres. Le total de la barre de paiement est lisible en mode sombre ; ses libellés gris sont plus foncés.
(4) Badge du panier en rouge plus foncé (lisible) ; le compteur de notifications et le bouton « Laisser un avis »
passent aux couleurs de la marque.
Installer avec Delicat Identity 6.17.2 et Delicat Recharge 6.6.3, puis purger LiteSpeed / Cloudflare.

9.7.0 — Navigation globale unifiée (phase 3 de la refonte)
(1) Un seul en-tête et un seul pied de page : avec un thème « blocs » (Twenty Twenty-Five et les autres thèmes
récents), l’en-tête et le pied de page du thème s’affichaient sous ceux du Builder sur l’accueil, le blog et la
boutique ; ils ne sont plus générés (page plus légère, plus de script de navigation du thème). Les shortcodes
[delicat_app_header] et [delicat_bottom_nav] laissés dans une page n’ajoutent plus un deuxième en-tête ni une
deuxième barre du bas (ils restent reconnus, rien ne s’affiche en clair).
(2) Icônes Material partout dans la navigation : en-tête, cloche des notifications, menu latéral (raccourcis et
entrées du menu), barre du bas et pied de page. Les logos WhatsApp, Telegram, Instagram, Facebook, TikTok et
YouTube restent ceux des marques. Les 100 icônes ont des tracés optimisés (−30 % d’octets, identiques à l’œil).
(3) Accessibilité : le mini-panier et le panneau de notifications prennent le focus à l’ouverture ; Échap les
ferme et rend le focus au bouton, comme le menu latéral.
(4) Nettoyage : l’ancienne barre mobile du « Shell » (mobile-dock.css et mobile-dock.js), inutilisée depuis
l’arrivée de la barre du bas native, est supprimée.
Poids des pages (HTML compressé, site de test) : accueil −6,9 Ko, boutique −4,1 Ko ; fiche produit et panier
+4,0 Ko (icônes Material du menu latéral).
Installer avec Delicat Identity 6.17.1 et Delicat Recharge 6.6.2 (inchangés), puis purger LiteSpeed / Cloudflare.

9.6.2 — Correctif : états et icônes perdus lors du retrait des emojis
(1) Notifications : chaque notification retrouve l’icône de sa catégorie (commande, portefeuille, message,
promotion, sécurité) au lieu de la même cloche pour toutes ; les notifications déjà enregistrées aussi.
(2) Menu : les raccourcis par défaut (Portefeuille, Commandes, Support, App) retrouvent chacun leur icône
au lieu de la même maison.
(3) Cloudflare › Dernière purge : « Réussie » ou « Échec » est de nouveau affiché (icône + mot).
(4) Aperçu de l’annonce (administration) : icônes Material à la place des derniers emojis.
Installer avec Delicat Identity 6.17.1 et Delicat Recharge 6.6.2, puis purger LiteSpeed / Cloudflare.

9.6.1 — Icônes Material, plus aucun emoji, sécurité renforcée
(1) Un seul jeu d’icônes : Material Symbols (Google), style arrondi, 96 icônes avec version pleine pour
l’onglet actif ; elles suivent la couleur du texte en clair et en sombre (delicat_ds_icon).
(2) Plus aucun emoji dans l’interface ni dans les messages (430 retirés dans les trois extensions) ; là où un
emoji servait d’icône (menu du compte, garanties des abonnements, bouton WhatsApp, promo du menu), il est
remplacé par une icône Material. Les emojis déjà saisis dans vos réglages restent convertis en icônes.
(3) Sécurité : la politique de sécurité complète (cadres, balise base, objets, HTTPS) est rétablie sur les
pages Mon compte, paiement et connexion, où WordPress la remplaçait ; nouvel en-tête
Cross-Origin-Opener-Policy (les fenêtres Google et de paiement continuent de fonctionner) ; page de connexion
protégée comme le reste du site (nosniff, HSTS, Permissions-Policy) ; option obsolète interest-cohort
remplacée (plus d’erreur dans la console du navigateur).
(4) Plus léger : les icônes du menu Mon compte ne sont envoyées que sur les pages du compte qui affichent ce
menu (et pas du tout quand Delicat Identity dessine le sien) ; les feuilles de style partagées des autres
pages sont légèrement plus petites qu’en 9.5.4.
(5) Fiabilité : une adresse avec plusieurs points dans le nom (par exemple un ancien fichier page.xxxx.css
supprimé) ne provoque plus d’erreur fatale au chargement de WordPress ; le mode sans échec du Builder ne se
déclenche plus à cause d’un script extérieur qui cherche une classe Builder absente.
Installer avec Delicat Identity 6.17.0 et Delicat Recharge 6.6.1, puis purger LiteSpeed / Cloudflare.

9.6.0 — Delicat Design System 2.0 : une seule identité visuelle pour tout le site (phase 2 de la refonte)
(1) Couleurs de la marque partout : bleu #2563EB pour les boutons et les liens, bleu nuit #00004F du logo pour
la marque. Le violet, le rose et l’orange qui variaient d’une page à l’autre (boutique, panier, menu, compte,
portefeuille) sont remplacés ; les anciens réglages de couleurs pointent tous vers les mêmes valeurs.
(2) Une seule source : les couleurs, la police, les arrondis, les espacements, les ombres et les durées
d’animation sont définis à un seul endroit (classe Design System) et chargés en tête de chaque page
(1,3 Ko compressés, sans fichier supplémentaire). Delicat Identity et Delicat Recharge les utilisent aussi.
(3) Mode sombre : les nouveaux visiteurs suivent le réglage de leur téléphone (clair par défaut, sombre si le
téléphone est en sombre) ; le choix fait avec le bouton reste prioritaire. Fond sombre neutre (#090F1D) au
lieu du violet. Correction : en mode sombre, le nom du site n’apparaît plus blanc sur blanc dans l’en-tête.
(4) Police Inter hébergée sur le site (48 Ko, mise en cache), sans décalage de mise en page au chargement ;
Impact, Playfair, Georgia et Poppins retirées des composants.
(5) Icônes : un seul jeu (Lucide, 71 icônes) disponible pour tous les modules via delicat_ds_icon().
(6) Contraste vérifié (WCAG 2.2 AA) : textes 4,5:1 minimum sur toutes les surfaces, contours des champs 3:1.
Documentation : design-system/DESIGN.md, AUDIT.md (audit complet), design-tokens.json.
Installer avec Delicat Identity 6.16.0 et Delicat Recharge 6.6.0, puis purger LiteSpeed / Cloudflare.

9.5.5 — Serveur plus léger à chaque page (revue de performance WordPress)
(1) Compte, panier et portefeuille : les appels que chaque page fait en arrière-plan (état de la
session, solde du portefeuille, état Pro) n’écrivent plus deux lignes dans la base à chaque appel
quand l’hébergement n’a pas de cache objet (Redis / Memcached) ; avec un cache objet, rien ne change.
Conseil : une règle de limitation de débit Cloudflare protège ces adresses mieux et sans coût.
(2) Recherche : les suggestions déjà en cache répondent sans compteur ; la limite par client ne
s’applique plus qu’aux recherches qui interrogent vraiment la base, avec un seul compteur partagé.
(3) Notifications : les avis de commande d’un client invité (jamais affichés ni envoyés, faute de
destinataire) ne remplissent plus l’historique partagé que chaque page lit ; ils passent toujours par
la file d’envoi pour les autres extensions.
(4) Tâches terminées qui coûtaient à chaque page : la migration de la clé Firebase ne tourne plus que
dans l’administration (deux lectures, un déchiffrement et un décodage JSON en moins par page) ; la
correction des mentions légales s’arrête quand le module est désactivé ou que la page a été modifiée
à la main ; deux réglages lus partout sont chargés avec les autres en une seule requête.
(5) Les appels « heartbeat » de l’administration ne chargent plus les 60 réglages du builder.
(6) Écrans de lancement de l’application : l’icône n’est plus recherchée deux fois par page, et la
recherche du logo dans la médiathèque est gardée un jour. Ventes en direct : une requête pour tous
les produits du fil au lieu d’une par vente.
(7) Accessibilité : le bouton de la lettre d’information garde un nom lisible par les lecteurs
d’écran quand son texte est vide.
Vérifié et laissé tel quel : le double passage sur la page avec LiteSpeed (le premier protège les
scripts de démarrage et les images de l’optimiseur, le second répare ce que l’optimiseur a changé).
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.5.4 — Audit sécurité et accessibilité (outils Claude Code : claude-security, Chrome DevTools, Lighthouse)
(1) Détail de la commande : textes et pastilles lisibles par tous (contraste 4,5:1 minimum, mesuré à
100/100 en accessibilité par Lighthouse sur mobile) : gris des dates et libellés, bleu des boutons et
liens, vert « Livrée », orange et rouge des états. Mode sombre inchangé et vérifié.
(2) Sécurité (revue indépendante du code 9.5.1 à 9.5.3, aucune faille trouvée) : renforcement — la
feuille « Partager le reçu » et les données du reçu passent avant les messages de la boutique et le
contenu des extensions, pour qu’aucun identifiant HTML dans ce contenu ne puisse les remplacer.
(3) Robustesse : l’adresse d’une commande avec le numéro d’un remboursement ne provoque plus d’erreur
fatale lors d’un changement de page sur place (contrôle WC_Order avant get_customer_id()).
Mesures (site de test, mobile, 4G lente et processeur ×4) : aucun décalage de mise en page (CLS 0,00) sur
l’accueil du compte, les commandes et le détail d’une commande.
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.5.3 — Tous les produits s’ouvrent instantanément, portefeuille et compte plus rapides
(1) Accueil et boutique : chaque produit s’ouvre de la même façon, tout de suite. Au toucher, l’écran
du produit apparaît aussitôt avec son image, son nom et son prix, puis la page se complète. Avant,
seuls un ou deux produits (ceux préparés à l’avance) s’ouvraient d’un coup et les autres attendaient
le réseau ; en 3G, aucun n’était préparé.
(2) Les produits que le client regarde (carte visible un court instant) sont préparés à l’avance, un à
la fois : jusqu’à 8 pour un visiteur, 5 pour un client connecté, 3 sur un téléphone d’entrée de
gamme, 2 en 3G ; jamais en mode économie de données, en 2G, hors ligne ou écran caché. Mesuré :
4G, produits regardés ouverts en moins de 0,06 s (avant : seulement le premier) ; tous les autres
affichent leur écran en 0,03 s au lieu de 0,16 s.
(3) Portefeuille et Compte (Chrome) : pour un client connecté, la page Portefeuille et l’accueil du
compte sont préparés en arrière-plan dès que le doigt touche leur lien (ou que la souris s’y pose) ;
ils s’affichent alors aussitôt au toucher. Panier et paiement restent seulement téléchargés à
l’avance (aucun script exécuté). Les visiteurs non connectés ne sont pas concernés.
(4) Plus stable : une page préparée en arrière-plan et jamais affichée ne télécharge plus rien
d’autre (préparation des produits et des onglets seulement une fois la page affichée).
Technique : moteur — Ge() remplacé par une préparation « au regard » (IntersectionObserver 50 %,
attente 450 à 700 ms, file d’un fetch à la fois, budgets selon réseau/appareil/connexion), écran
produit immédiat (C = 0 pour une URL produit sans fetch en cours depuis plus de 400 ms),
document.prerendering respecté (prerenderingchange). TurboNav : règle prerender « moderate » sur les
chemins exacts du portefeuille et de l’accueil du compte (connectés ; filtre
delicat_builder_v9_prerender_documents), règle prefetch inchangée pour le reste.
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.5.2 — Partager le reçu depuis le détail de la commande
(1) Dans Mon compte › Commandes › une commande payée, le client peut partager son reçu : bouton
« Partager le reçu » en bas de la page et raccourci « Reçu » dans « Résumé du paiement ». La feuille
de partage montre l’aperçu du reçu et propose Partager (WhatsApp, Messages… avec l’image), Télécharger
(PNG), Imprimer / PDF et Copier le lien de la commande. Même reçu que sur la page « Commande reçue » :
logo, numéro, date, paiement, articles, total et QR code ; jamais aucun code, jamais les coordonnées.
(2) Imprimer (ou Ctrl+P) sur une commande avec reçu imprime uniquement le reçu, jamais la page et ses
codes ; la carte « Vos codes » n’est jamais imprimée.
(3) Proposé seulement au client connecté propriétaire de la commande, une fois le paiement reçu
(payée, en livraison, livrée, en vérification) et si « Partage du reçu » est activé dans les réglages
du module Confirmation de commande.
Technique : Delicat_Builder_V9_Order_View::receipt() (Order_Status::receipt(), QR chargé sur le
compte), feuille dialog + #dlc-oc-receipt + gabarit d’impression dans un <template>, module
order-confirmation-receipt.js chargé à la première intention et rechargé pour une autre commande après
un changement sur place ; order-confirmation-print.css (media print) sur les rubriques du compte.
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.5.1 — Détails de la commande redessinés et compte instantané
(1) Page « Détails de la commande » (Mon compte › Commandes › une commande) entièrement redessinée
comme une application : carte « Commande #… » avec la date, l’état en pastille colorée et pictogramme
(style Material) et une illustration ; suivi en 4 étapes avec la date et l’heure de chaque étape ;
« Articles commandés » avec image, quantité, ID joueur et état de livraison de chaque article ;
« Vos codes » avec bouton « Copier le code » ; vos coordonnées ; mode de paiement avec pastille
« Payé » / « À payer » / « Remboursé » ; « Résumé du paiement » ; messages de la boutique ; boutons
« Payer maintenant » (si le paiement est dû), « Contacter le support » (WhatsApp) et « Commander à
nouveau ». Clair et sombre, téléphone et ordinateur.
(2) Une commande en cours se met à jour toute seule : dès que son état change, la page se recharge et
affiche les codes (vérification sécurisée, en pause quand l’écran est caché, arrêt automatique).
(3) Compte instantané : Accueil du compte, Mes commandes et le détail d’une commande s’ouvrent sans
recharger la page (comme une application), retour arrière compris. Les autres rubriques (profil,
sécurité, portefeuille, déconnexion) et une commande qui affiche des codes s’ouvrent toujours en page
complète, pour que leurs propres scripts démarrent normalement.
(4) Retour arrière corrigé : quand une page s’ouvrait en page complète alors que son squelette de
chargement était déjà affiché, Retour restait sur cette page (l’adresse changeait seule) ; la page
précédente revient maintenant normalement.
Technique : module Delicat_Builder_V9_Order_View (filtre wc_get_template sur myaccount/view-order.php,
modèle templates/order-confirmation/view-order.php, order-view.css/js) bâti sur
Delicat_Builder_V9_Order_Status ; WooCommerce vérifie l’accès et la page revérifie le propriétaire ;
page privée jamais mise en cache (DONOTCACHEPAGE, Referrer-Policy same-origin, noindex) ; codes
uniquement par les crochets WooCommerce (Digital Gateway, Shop2TopUp) ; zones d’extensions marquées
data-delicat-nav-full. Moteur : nav.account (base + chemins, filtre
delicat_builder_v9_account_soft_paths), changement sur place seulement d’une rubrique autorisée vers
une autre, connecté, sans nouveau script ; pages du compte jamais gardées en mémoire, téléchargées à
l’avance seulement à l’appui du doigt (réutilisées 3 s) ; règles de préchargement du navigateur sans
ces rubriques sur le compte ; une commande à page complète répond 204 + X-Delicat-Nav: full à la
demande du moteur (X-Delicat-Nav: 1), sans être construite deux fois ; repli en page complète après
squelette : history.back() puis chargement (plus de location.replace sur l’entrée du squelette).
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.5.0 — Comme une application : pages instantanées, application installée et nouveau pied de page
(1) Changer de page est instantané pour les clients connectés : les pages déjà préparées (au toucher
d’un lien, au survol, les onglets du bas) restent prêtes en mémoire 90 s au lieu d’être téléchargées
une seconde fois. Avant, un client connecté ne profitait jamais de la préparation.
(2) Panier, Compte et Wallet répondent tout de suite au toucher : l’onglet s’allume et l’écran passe
au squelette de la page pendant son chargement, puis la page arrive en fondu. Le chargement de ces
pages commence dès que le doigt touche le lien (Chrome). Retour arrière : la page précédente revient
intacte.
(3) Squelette de chargement affiché plus tôt (0,14 s au lieu de 0,3 s) quand une page n’est pas prête.
(4) Application installée : toucher l’icône alors que l’app est déjà ouverte la remet au premier plan
là où le client était, comme une application native, au lieu de la recharger.
(5) Pied de page reconstruit : plus court et plus clair sur téléphone (rubriques repliables, boutons
Support et WhatsApp côte à côte, trois garanties sur une ligne, moyens de paiement, documents
légaux), colonnes ouvertes sur ordinateur, mode sombre, contrastes et zones tactiles de 42 px et plus.
Technique : moteur (cache mémoire des documents « private » avec durée propre, jamais « no-store »,
vidé à la connexion/déconnexion, au panier, au portefeuille, à la devise et sur l’événement de
session d’Identity), retour immédiat des pages complètes par l’API Navigation (navigate,
navigateerror, pageshow), règles de préchargement « moderate » pour panier/compte/wallet/paiement
quand le moteur est actif (sans paramètres d’URL, déconnexion, paiement de commande),
launch_handler focus-existing dans le manifeste, pied de page en dbv9-ft__* (anciennes surcharges
!important retirées de surface-compatibility.css et dark-glass.css).
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.4.6 — Mentions légales
Informations de l’éditeur et hébergement corrigés selon les coordonnées fournies.
Mise à jour ciblée des deux sections existantes au premier chargement.
Les autres sections et le lien #autres-documents sont conservés.
Installer cette archive en remplacement du builder existant, puis purger LiteSpeed / Cloudflare.

9.4.5 : boutons de nouveau fonctionnels sur Android (régression de 9.4.3).
(1) Un bouton ou un lien placé dans le menu, le panier, la fenêtre d’achat ou le portefeuille qui
ferme la fenêtre puis ouvre une page complète (compte, portefeuille, panier, paiement) ne faisait plus
rien : la gestion du bouton Retour ajoutée en 9.4.3 annulait le chargement. Elle est réécrite : sur
Android (Chrome) le bouton Retour ferme la fenêtre ouverte sans toucher à l’historique ; dans l’app,
l’entrée d’historique n’est retirée qu’après une fermeture par la croix ou Échap, jamais après un lien
ou un envoi de formulaire ; ailleurs, Retour fonctionne comme avant 9.4.3.
(2) La barre du bas ne disparaît plus quand le clavier est fermé avec un champ encore sélectionné : elle
se masque seulement quand le clavier est vraiment ouvert.
(3) Calculateur de prix : les pas sont comptés comme le navigateur (depuis le minimum, sinon depuis la
valeur par défaut) et la page n’ajoute plus de min="0" ; « Ajouter au panier » n’est plus bloqué sur les
champs à pas. Les valeurs négatives restent refusées par le serveur.
(4) Cloche des notifications : un rechargement automatique seulement après un échec de téléchargement,
une fois par session.

9.4.4 : pied de page corrigé.
(1) Après la page « Commande reçue », le reçu imprimable (logo, date, paiement, articles, QR code)
restait affiché sous le pied de page de toutes les pages suivantes : quitter la page de commande
recharge maintenant la page entière, le reçu n’est plus jamais affiché à l’écran (seulement à
l’impression) et le suivi de la commande s’arrête avec la page.
(2) Les couleurs des liens du pied de page ne sont plus remplacées par celles du thème sur la boutique,
le panier, le paiement et les commandes (« Contacter le support » rouge sur fond violet).
(3) Sur téléphone, les derniers liens restent au-dessus de la barre flottante du navigateur ; le bouton
WhatsApp ouvre la conversation avec un premier message.

9.4.3 : audit complet (sécurité, stabilité, vitesse, sensation d’application) et corrections.
(1) Sécurité : le calculateur de prix des produits refuse les quantités négatives ou hors pas (un champ
sans minimum ne descend plus sous zéro) et un produit payant ne passe plus jamais à 0,00 (paniers déjà
ouverts compris) ; Web Push n’accepte que les vrais services de notification (Google, Mozilla, Apple,
Microsoft, Samsung) et limite les invités ; limiteurs et liste d’IP de maintenance basés sur l’adresse
réelle du visiteur derrière Cloudflare ; jeton de l’app comparé avant le blocage (plus de blocage d’un
réseau mobile entier) ; avis limités à 5 par heure ; cookies « Secure » derrière le proxy ; HSTS sans
includeSubDomains (comme prévu) ; JSON-LD protégé ; cache des fragments connectés : prénom et code client
reconnus comme personnels ; crochet mort retiré.
(2) Stabilité : un module qui n’a pas pu se télécharger (réseau coupé) ne laisse plus le bouton mort : la
navigation suivante recharge la page (une fois tout de suite pour l’achat) ; un script qui échoue après
le changement de page ne recharge plus tout ; plus d’écran squelette bloqué sur les anciens Android
(WebView < 98) ; un toucher pendant le chargement ne force plus un rechargement complet ; une page lente
reçoit 20 s pour finir de se télécharger ; le sélecteur de produit et la recherche mettent l’adresse à
jour ; le retour arrière retombe au bon endroit dans la boutique ; mode sombre : seule la nouvelle page
est analysée après une navigation ; classes de thème jamais en double ; caches limités sur téléphone
modeste ; tous les color-mix() ont un repli pour les anciens WebView, flous retirés, :has() séparés,
vh de secours pour dvh.
(3) Comme une application : le bouton Retour d’Android (et celui de l’app) ferme le menu, le panier ou la
fenêtre ouverte au lieu de quitter la page ; la barre du bas s’efface quand le clavier est ouvert et le
champ remonte au centre ; bandeau « Hors ligne » ; la barre du navigateur suit le thème choisi dans la
boutique ; color-scheme déclaré (pas d’assombrissement automatique du WebView) ; dans l’app : pas de
menu d’appui long sur les liens et boutons ; champs à 16 px (plus de zoom iOS) ; clavier « OK » et
clavier numérique seulement pour les ID numériques ; annonce vocale à l’ajout au panier ; page hors
ligne sombre, en créole aussi, « Réessayer » recharge la page voulue.
(4) Serveur : les pages des clients connectés restent dans le cache arrière du navigateur (en-tête privé
revalidé au lieu de no-store) ; clients en USD servis par la copie en cache de leur devise ; limiteurs
dans le cache objet quand Redis/Memcached existe ; moins d’écritures en base (diagnostic produit,
scripts produit, solde du portefeuille, mesure de vitesse à 10 %) ; recherche d’image du produit mémorisée ;
options lues en une requête ; tâche des taux de change retirée à la désactivation.

9.4.2 : page « Commande reçue » : WhatsApp Business, suivi animé, célébration ; recommandations vitesse.
(1) Support : bouton « Écrire sur WhatsApp » (+509 3311 1283 par défaut, réglage « WhatsApp Business du
support » dans Site Studio) avec un message prérempli citant le n° de commande ; utilisé quand aucune URL
de support n’est saisie.
(2) Suivi « Commande reçue → Paiement confirmé → Envoyée → Livrée » : une icône par étape (reçu, carte,
avion, cadeau), pastille de validation qui rebondit, arc qui tourne et icône qui flotte sur l’étape en
cours, reflet qui court sur la ligne vers l’étape suivante. Pause hors écran ; version calme sur téléphone
lent ; rien avec « réduire les animations ».
(3) Commande livrée : la coche se dessine, confettis, étoiles, deux ondes, reflet sur la pastille et petit
saut des étapes ; à l’ouverture d’une commande livrée (une fois par onglet), au passage en direct et après
rechargement. Message personnalisé (« « Crunchyroll » est livré. Merci pour votre confiance ! »).
(4) Mise en page : la carte blanche de la page de paiement n’entoure plus le reçu (plus de carte dans une
carte) ; la carte « Votre livraison » redondante disparaît quand tout est livré.
(5) Vitesse : un seul responsable du préchargement (règles de spéculation WordPress coupées quand le moteur
navigue) ; téléphone « modeste » décidé par la mémoire, Save-Data et la 2G (plus par le nombre de cœurs),
les téléphones de 4 Go mesurés une fois par session ; bascule de thème en cercle seulement sur téléphone
capable, fondu ailleurs ; cascade limitée aux 4 premières cartes et jamais au retour ; squelette après
300 ms ; page arrivée après plus d’1 s affichée sans glissement ; vibration courte à l’ajout au panier
(Android) ; tailles WebP des nouvelles photos JPEG (sauf si une autre extension s’en charge) ; page
« Vitesse réelle » : mesures prises chez les clients (échantillon, sans donnée personnelle), 75e
percentile par application / navigateur / Facebook-TikTok / téléphones modestes, et contrôles serveur.

9.4.1 : 9.4.0 + toutes les mises à jour 9.3.12 à 9.3.17, et un nouveau calque d’animations.
(1) Repris de 9.3.12/9.3.13 : recherche sans résultats périmés, résultats du héros lisibles, pied de page et
en-tête de l’app installée (zone d’état, encoches), feuilles des extensions compte/wallet conservées,
correctifs critiques imprimés dans l’en-tête (surface-compatibility). Corrigé au passage : shell.js lisait
une variable de recherche inexistante quand le panier échouait à charger.
(2) Repris de 9.3.14 à 9.3.17 : en-tête et navigation, mode sombre complet « verre » (dark-mode.css,
dark-glass.css, logo texte DELICAT / STORE HAITI), adaptateur sombre corrigé (dégradés transparents,
texte blanc des boutons, règles apprises remises à zéro), avis : « Voir tout » et votes, module SEO,
fichiers inutiles retirés (anciens bundles .min, notes d’APK).
(3) Animations (assets/css/motion.css + moteur) : changement de page selon la direction (avance : arrive
de la droite, retour : de la gauche, fiche produit : l’image se déplace), l’en-tête et la barre du bas
restent immobiles ; bascule clair/sombre en cercle depuis le bouton (View Transitions) ; appui sur les
cartes et tuiles (léger enfoncement, rebond au relâchement, jamais pendant un glissement) ; l’onglet actif
de la barre du bas rebondit ; les cartes d’un rail arrivent en cascade ; le badge du panier rebondit à
l’ajout ; squelettes animés par transform au lieu de background-position (plus de repeint à chaque image).
Seulement transform, opacity et clip-path ; rien avec « réduire les animations » ; versions légères ou
aucune sur téléphone lent. L’adaptateur sombre ne retarde plus le début de la bascule.
Tests : syntaxe PHP/JS, harnais Playwright (navigation, tiroir, mode sombre, animations, temps d’image).

9.4.0 : nouvelle page « Commande reçue » (module Confirmation de commande). Elle remplace l’extension
« Delicat Order Confirmation Pro » 2.3.1 : une seule extension, le Builder (spécification
DESIGN-ORDER-CONFIRMATION-9.4.md). Après l’installation de 9.4.0, l’ancienne extension peut être désactivée
puis supprimée (Extensions › Delicat Order Confirmation Pro › Désactiver, puis Supprimer).
(1) Un seul modèle de statut côté serveur (includes/class-delicat-builder-order-status.php) pour le héros, le
suivi en 4 étapes, la pastille, le reçu et le suivi en direct : une étape après l’étape en cours ne peut plus
paraître terminée (le défaut des captures : « Livré » coché pendant « Recharge en cours »). Un paiement reçu
décide du message, pas le seul statut WooCommerce : une commande payée en attente affiche « Commande en
vérification », une commande débitée puis échouée n’est jamais invitée à repayer. Lit le résumé du Digital
Gateway (contexte « summary », sans déchiffrement) et l’état des lignes Shop2TopUp.
(2) Un seul héros (celui du module Site est retiré tant que le module possède la page), carte « Vos codes »
(les crochets du Digital Gateway et de Shop2TopUp sont relancés : panneau de code, synchronisation, vouchers,
bouton « Copier »), récapitulatif avec remise, frais, taxe et remboursement, instructions de paiement,
coordonnées, assistance. Montants « 143 HTG » / « 1 250,50 HTG » partout, dans la devise de la commande, sans
retour à la ligne. Paiement wallet : « Crédit boutique Délicat » ; MonCash et Natcash gardent leur nom ; pages
vues depuis l’app : jamais wallet ni portefeuille.
(3) Suivi en direct : POST /wp-json/delicat-builder-v9/v1/order-status, jeton signé à durée limitée, client
connecté propriétaire (ou gérant) et nonce pour les commandes client, limite de débit, jamais en cache, même 404
si la commande manque ou si l’accès est refusé. Interrogation progressive, en pause onglet caché ou hors ligne,
arrêt au statut final ou après 30 minutes ; « vérifié il y a 3 s » ; changements annoncés aux lecteurs d’écran.
L’ancienne route GET /delicat-order/v1/status/{id} répond encore (dépréciée, retrait prévu en 9.6.0).
(4) Animations : transform et opacity seulement, sobres sur téléphone lent, aucune avec « réduire les
animations » ; aucun décalage de mise en page ; thèmes clair et sombre, dès 320 px.
(5) Reçu partageable comme dans l’app : dessiné à la main sur un canvas (3x, recu-delicat-<n>.png), « Partager »,
« Télécharger (PNG) », « Imprimer / PDF » (feuille A5, l’impression ne montre que le reçu), « Copier le lien ».
Jamais aucun code sur le reçu, jamais les coordonnées de facturation. QR du lien « Mon compte › Commande » par
un encodeur intégré (includes/lib/class-delicat-builder-qr.php, MIT, identique au paquet Dart qr 3.0.2 de
l’app) ; commande invitée : ni QR ni lien.
(6) L’ancienne extension, si elle est encore active, est neutralisée automatiquement (ses 8 crochets retirés)
et un avis propose de la désactiver ; réglage « Neutraliser l’ancienne extension » pour revenir en arrière.
delicat_topup_provider_update est gardé en version sûre : jamais « échouée », une erreur fournisseur met une
commande payée en attente pour vérification manuelle, aucun changement sur les lignes du Digital Gateway ou de
Shop2TopUp.
(7) Réglages dans « Site complet › Page Merci » : nouvelle page, suivi en direct et sa durée, partage du reçu,
animations, animation de réussite, coordonnées, lien du support, message pendant la livraison.
Tests : tests/order_confirmation_smoke.php ; aperçus : tests/order_confirmation_preview.php --out=<dossier>.

9.3.8 : fusion de 9.3.7-supplier-sync (propriétaire) et 9.3.7 (app : pages vues depuis l’app, politique de
confidentialité, interrupteurs Version Google Play). Du propriétaire : sélecteur de produits, services et
régions relié à API Switcher (plusieurs IDs WooCommerce par ligne, fournisseur actif suivi), PWA et
navigation qui ne gardent pas en cache les liens delicat_product, moteur dist reconstruit,
asset-versions.php, SUPPLIER-SYNC-9.3.7.txt. Tests : tests/app_view_smoke.php,
tests/integrity_manifest_smoke.php, tests/supplier_sync_smoke.php.

9.3.7 : pages du site ouvertes depuis l'application (Delicat App API 2.32.0, Google Play).
L'API marque ces pages (cookie dlc_app_view) et répond oui au filtre delicat_app_is_app_view ; elle
filtre déjà les menus WordPress, les listes de catégories, la boutique et les colonnes du pied de page.
Le Builder retire maintenant aussi les liens qu'il dessine lui-même, seulement quand ce filtre répond oui
(includes/class-delicat-builder-app-view.php) :
(1) Tout lien, entrée de menu, ligne d'éléments, bouton ou section dont la cible est une catégorie masquée
(Delicat_App_Catalog::hidden_term_ids() ; sans l'API : exchange, finance, exchange-usdt-usd et les noms
contenant usdt, crypto, bitcoin, exchange ou finance) ou un produit masqué, ou dont le libellé ou l'adresse
nomme exchange, finance, usdt, crypto, bitcoin, paypal, wise ou meru : « Finance » du menu à icônes de
l'en-tête, « Exchange USDT - USD » du menu (tiroir, ancien menu, raccourcis, promo, [delicat_bottom_nav]),
« Services financiers » et « Conditions Exchange et Wallet » du pied de page, la section d'accueil
« home-exchange-carousel », la puce « Finance », le « Voir tout » d'un carrousel, l'annonce et les
recherches populaires de la coquille. Les produits masqués quittent les carrousels et la recherche du hero.
Les avis de la section « testimonials » (lignes saisies et vrais avis WooCommerce) qui portent sur un produit
ou une catégorie masqués, ou qui nomment ces sujets, sont retirés ; le badge de sujet « Finance » n'est plus
dessiné sur les cartes produit (caches des cartes du carrousel séparés pour ces pages).
(2) Quand delicat_app_view_hides_credit_gifts répond oui aussi (application Google Play, cadeaux de crédit
désactivés) : liens vers les pages de crédit (« Mon portefeuille », /my-wallet/, « Envoyer de l'argent »)
et carte de solde du tiroir retirés ; la place « Wallet » de la barre du bas ouvre « Commandes » (cinq
places et bouton central conservés) ; la puce « Delicat Wallet » du pied de page devient « Crédit boutique
Délicat » ; la phrase d'accueil des invités dans le tiroir et la carte de connexion de « Mon compte » ne
parlent plus de wallet ni de portefeuille ; l'étape par défaut de « Comment ça marche » dit « paie avec ton
crédit boutique, MonCash ou Natcash ».
(3) Le cache de fragments du serveur n'est ni lu ni écrit pour ces pages.
(4) Sans ce filtre à oui, le site est identique octet pour octet à 9.3.6 : tests/app_view_smoke.php
(php tests/app_view_smoke.php depuis le dossier du plugin ; --dump=<fichier> écrit les rendus du site
pour comparer deux versions).

9.3.5 : annonce par canal (site, application ou les deux) et bloc « Annonce réservée à l'application »,
sections de la page d'accueil visibles ou non sur le site web et dans l'application (cases « Application » et
« Site web » dans l'éditeur ; une section masquée pour le site est ignorée entièrement par le rendu).

9.3.4 : en-tête épinglé sur iPhone, tirer pour actualiser, audit du design.
(1) En-tête qui défilait avec la page dans l'application installée sur iPhone : la racine et <body>
coupaient tous deux le débordement latéral avec overflow-x: clip. Chrome garde l'en-tête collant
épinglé ainsi, WebKit non (un élément collant dans une boîte overflow-x: clip tremble ou lâche, bogue
WebKit 247130). Seule la racine coupe désormais (html{overflow-x:hidden}, transmis à la fenêtre) et
<body> reste overflow: visible partout : le seul arrangement que tous les moteurs respectent.
(2) Page Panier dans l'application : plus de double chevron de retour (l'en-tête en porte déjà un),
plus d'écran vide sous un panier vide (le rembourrage de la page ne s'empile plus sur la bande de la
barre, hauteur minimale réduite sur téléphone), logo du pied de page fixé à 48 px quoi que dise le
thème des images.
(3) Tirer vers le bas pour actualiser, sur toutes les pages (appareils tactiles, activé par défaut) :
la page est rechargée depuis le réseau et remplacée sur place par le moteur, avec une coche à
l'arrivée ; panier, commande, compte et wallet se rechargent entièrement.
(4) Audit visuel de 13 pages (téléphone, sombre, application, bureau, menu, panneau panier, fiche
produit, feuille express) : page 404 (formulaire de recherche du thème avec libellé visible et bouton
débordant, boutons orange à texte violet) refaite avec le formulaire et les couleurs de la boutique ;
formulaire de connexion (case « Se souvenir de moi » étirée sur toute la ligne) ; sommaire des pages
légales qui numérotait des titres déjà numérotés (« 1. 1. … ») ; lien « Effacer la sélection » des
options produit, rouge et affiché avant toute sélection (masqué jusqu'à une sélection, en français,
discret) ; chevron de l'en-tête du panier aligné sur le titre.
(5) Audit de performance à CPU ×4 : défilement à 17 ms par image sur accueil, boutique et produit,
LCP 456–584 ms, CLS 0, navigations douces 365–975 ms en 4G lente ; tous les écouteurs de défilement
sont passifs et limités à une image ; aucun « transition: all », aucun flou sur le contenu défilant.

9.3.3 : barre sur toutes les pages, application installée, bannière en carrousel.
(1) Barre flottante disparue après une mise à jour (surtout dans l'application installée) : une page
rendue avant la mise à jour désigne encore les fichiers hachés de l'ancienne version (chrome.<hash>.css,
moteur), supprimés avec l'ancien dossier du plugin. Une copie gardée quelques minutes par Safari, par le
service worker de l'application ou par Cloudflare arrivait sans la feuille du chrome : en-tête et bulle
d'assistance stylés (CSS critique en ligne) mais pas de barre, pas de styles du menu, pas de moteur. Le
serveur répond désormais à un fichier haché manquant par la version courante du même paquet (uniquement
les noms listés dans le manifest de build ; tout le reste reste en 404).
(2) Barre sur la page Panier ; Commande et la page de remerciement gardent leur dock. La page Panier
laisse la place à la barre et l'onglet « Panier » s'y allume.
(3) Classes d'overlay résiduelles (feuille express, modale wallet, dock de commande, menu) vérifiées
contre leur élément après chaque navigation, retour arrière ou retour au premier plan, et retirées
quand rien n'est ouvert.
(4) Application installée : plus de barre d'administration WordPress (masquée avant le premier rendu,
et plus imprimée côté serveur dès que l'application s'est identifiée par son cookie) ; l'en-tête reste
sous la barre d'état.
(5) Invitation à installer : iPhone et iPad, à chaque visite, dès l'arrivée, jusqu'à fermeture ou
ouverture des étapes (silence pour la visite seulement) ; jamais dans l'application installée.
(6) Bannière : rail de cartes glissable façon application bancaire — titre de section avec les mots en
gras entre *étoiles*, cartes titre / texte / image / bouton, carte suivante visible sur téléphone,
trois par rangée sur grand écran, points de position. Les anciens champs forment la carte 1 ; les
cartes suivantes se saisissent une par ligne « Titre|Texte|Bouton|URL|#couleur|id image » ; trois
styles (clair, sombre, teinté) et mode sombre.
(7) Clavier iPhone : la barre reste derrière le clavier, comme dans les applications natives.

9.3.2 : corrections iPhone, barre flottante, téléphones lents.
(1) Barre de recherche du hero cassée sur le site (un libellé « Reche… » dans la ligne, la flèche
rejetée à la ligne) : le formulaire imprime un libellé pour lecteurs d'écran et comptait sur le thème
ou sur la feuille WooCommerce pour la règle .screen-reader-text qui le masque ; la feuille WooCommerce
étant retirée des documents natifs, un thème sans cette règle affichait le libellé. La règle voyage
désormais avec le chrome sur chaque page (livraison classique comprise).
(2) Barre flottante : l'« étagère » ajoutée à la troisième passe (le fond de page remontant autour de
la pilule) se lisait sur téléphone comme une bande bord à bord surmontée d'un trait. La pilule flotte à
nouveau librement avec sa propre ombre, conservée aussi sur les téléphones modestes ; les couleurs
forcées qui grisaient tous les onglets inactifs sont retirées et chaque onglet porte le violet de la
boutique comme sur l'écran de référence (l'onglet courant garde son libellé plus gras).
(3) Invitation à installer l'application : iPhone et iPad seulement (Safari ne propose jamais rien de
lui-même ; Android a l'APK et l'invite de Chrome), et elle descend depuis le haut de l'écran, sous la
barre WordPress pour l'équipe connectée.
(4) Vitesse, la vraie cause : dix sélecteurs [class*="…"] (copie du lanceur, widgets de chat, rails,
cibles tactiles, garde de l'en-tête natif) obligeaient le navigateur à recalculer toute la page à
chaque changement de classe sur <html>, <body> ou <main> : environ 15 ms sur ordinateur, 90 ms sur
un téléphone lent, plusieurs fois par navigation et à chaque mise à jour d'onglet ou apparition de
section. Les dix sont réécrits avec des classes nommées ; un changement de classe sur la racine ne
coûte plus rien. Les changements de racine propres à la navigation suivent le même principe : la page
estompée ne bascule plus pointer-events/cursor (le module de navigation refuse les touchers sur la
page sortante), les variables de mouvement ne sont écrites qu'une fois par profil, la classe du dock
produit arrive avec le document, et la bande du bas est une propriété non héritée (@property).
(5) Squelette sur téléphone lent : le squelette n'apparaît que si le document met plus de 160 ms à
arriver (260 ms sur téléphone modeste), jamais sur les plus lents ; une réponse rapide est peinte une
fois au lieu de deux ; son scintillement est fixe sur téléphone modeste. Les transitions de vue sont
sautées sur téléphone modeste. Les téléphones qui n'indiquent ni mémoire ni cœurs (Safari) sont
mesurés une fois par session par une courte boucle au repos.
(6) Premier produit ouvert : les scripts WordPress/WooCommerce de la route produit (jQuery, wp-util,
formulaire de variations…) sont enregistrés avec leur URL exacte quand une fiche les imprime et
pré-mis en cache par le service worker ; le script comment-reply n'est plus chargé sur les fiches.
(8) En-tête collant perdu, et sous la barre d'état dans l'application installée : la feuille critique
Pro posait overflow-x:hidden sur <body> alors que la racine coupe déjà le débordement latéral ; le body
devenait un conteneur de défilement, et un en-tête collant se colle à son conteneur de défilement le
plus proche : l'en-tête partait avec la page (dans l'application installée il glissait sous l'heure et
l'îlot, illisible et inatteignable). Le body utilise overflow-x:clip, qui coupe sans créer de conteneur
de défilement ; l'en-tête colle à nouveau et, dans l'application installée, porte la marge de la barre
d'état (env(safe-area-inset-top)) : sa ligne se place sous l'heure et son fond remplit la bande.
(7) Une seule passe de style par changement de page : la feuille de la route entrante est chargée en
avance avec une requête média qui ne correspond jamais puis activée dans la même tâche que le
remplacement du contenu (elle restylait la page sortante dès son arrivée, puis la nouvelle page) ; la
feuille sortante est désactivée au même instant ; la classe de thème du <body> est imprimée avec le
document. Mesures locales (Chromium, processeur ralenti 6×, 4G lente, connecté, avant → après) :
fiche ouverte depuis l'accueil 1688 → 1145 ms, retour 892 → 436 ms, onglets de la barre 747–1012 →
175–487 ms.
Purgez LiteSpeed et le CDN après la mise à jour.

9.3.1 : correctifs demandés par la boutique sur les avis et les animations.
(1) « Laisser un avis » (bloc Avis de la fiche produit, bouton de la section Témoignages) restait sans
effet dès que l'application avait été ouverte sur une page qui n'imprime pas la configuration des avis
(boutique, panier, catégorie) : la garde du module n'était posée que sur ces pages-là, et toute fiche
produit ouverte ensuite en navigation douce avait un bouton mort. La garde est posée sur toutes les
pages, une configuration minimale voyage avec le moteur (config.reviews), la fenêtre s'ouvre par l'API
du module au toucher qui l'a chargé, un fragment qui n'a pas pu être chargé est retenté au toucher
suivant, et une fenêtre retirée par un autre script ne bloque plus les ouvertures suivantes. Un client
connecté dont la requête échoue voit « Réessayer », pas un lien de connexion.
(2) Les deux rangées de témoignages ne défilaient plus : le défilement est lancé par JavaScript (les
cartes sont imprimées une fois, clonées quand la section approche, puis is-loop-ready démarre la boucle
CSS) ; ce code vivait dans l'ancien script core absorbé par le moteur et n'avait pas été porté. C'est
désormais un module du moteur (src/engine/modules/marquee.js), remonté à chaque navigation douce. La
boucle tourne aussi sur téléphone (la RC90 l'avait remplacée par des rangées à faire glisser sous 640 px) ;
elle se met en pause hors écran ou sous le doigt, laisse place aux rangées manuelles en mouvement réduit,
et le filtre delicat_builder_v9_testimonials_mobile_marquee (false) rétablit les rangées manuelles sur
téléphone. Le mode connexion lente ne fige plus ce défilement. Les feuilles de l'ancienne livraison
(all-components.min.css, storefront-chrome.min.css) sont régénérées par tools/build.mjs depuis les
sources : les deux livraisons sont identiques.
(3) Mode sans échec : une erreur fatale levée hors du plugin qui ne faisait que nommer une classe du
Builder (Class "Delicat_Builder_V9_…" not found depuis un extrait de thème, une autre extension ou un
script en ligne de commande) basculait toute la boutique en WordPress nu, ce qui ne corrige rien. Seule
une erreur dans un fichier du Builder, ou levée en exécutant son code, la déclenche désormais.
Les feuilles compilées des pages et tous les fragments en cache sont rafraîchis par le changement de
version. Purgez LiteSpeed et le CDN après la mise à jour.

9.3.0 : nouveau moteur de livraison du storefront (« App-Speed Kernel »). Tous les designs et toutes
les fonctions sont conservés ; ce qui change est la façon dont la boutique est servie et pilotée.
(1) Un seul module JavaScript (assets/dist/engine.<hash>.js, 76 Ko, ~25 Ko compressé) remplace les
quatorze scripts chargés auparavant sur chaque page ; le code propre à une route (produit, panier et
commande, widgets optionnels, panneau de notifications) arrive en fragment à la demande.
(2) Navigation douce partout : accueil, boutique, catégories, recherche, produits et pages de contenu
remplacent <main> sur place avec une transition de vue (l'image du produit touché glisse jusqu'à la
fiche). Panier, commande et compte restent des chargements complets. L'ordre des feuilles de style est
conservé à chaque changement de page ; la feuille de la route précédente est désactivée.
(3) Trois feuilles de style par page au lieu de 9 à 14, construites par tools/build.mjs à partir des
fichiers existants concaténés dans l'ordre exact d'impression WordPress : chrome, route, polish.
Aucune règle n'a été réécrite. Les blocs CSS imprimés par PHP (critique, swatches, autoritaire)
restent inchangés.
(4) Un seul magasin de session : ?wc-ajax=delicat_session porte aussi le nombre d'alertes, le total du
panier et des nonces frais (panier, ajout au panier, REST, push, portefeuille, mini-panier, shell,
express, avis, favoris) que le moteur réinjecte dans chaque consommateur ; le sondage ?dbp_state=1 et
le correcteur de badge du menu bas ne tournent plus.
(5) La livraison PHP est dans includes/class-delicat-builder-engine.php ; les couches concurrentes
(dbp-nav, dbp-state, shell-nav, prefetch, islands, règles de spéculation TurboNav, chargeur de la
cloche) s'effacent quand le moteur est actif. La livraison fichier par fichier reste complète et sert
de repli : filtre delicat_builder_v9_engine_active, ou ?dbv9_engine=0 pour un administrateur.
(6) Corrections : erreur fatale sur [delicat_native_terms]/[delicat_native_privacy] ; déclaration HPOS
faite depuis le mauvais fichier ; badge du panier qui affichait le drapeau « 1 » du cookie WooCommerce ;
calculateur : total négatif vendu à 0,00 et ligne invalide acceptée à la commande ; recompilation des
feuilles de page impossible depuis les écrans wp-admin ordinaires ; dock de commande qui interrogeait la
page indéfiniment ; Self-Test attendait le schéma 6 ; exigence PHP affichée 8.5 (8.3 requis) ; avis PHP
theme_default, attachment_id, $hook, effect, page_transition, max_width ; wp_targeted_link_rel ;
mode sombre (jetons dbp-type forcés en clair, champs du calculateur blancs, pages légales sans jetons
sombres, total du dock de commande blanc sur blanc) ; purge du cache de documents du service worker
au changement de devise ; noms de globales erronés dans les exclusions LiteSpeed.
(7) Stabilité, mise en page et sécurité (9.3, deuxième passe) : bande vide empilée en bas de chaque
page sur téléphone (contenu, pied de page et body réservaient chacun la hauteur du menu bas : une seule
réserve désormais) ; boutons gris de la lightbox PhotoSwipe sous le pied de page des fiches produit ;
sélecteur de tri de la boutique tronqué ; boutique native servie avec le paquet woo-ui au lieu de
native-archive ; variable $hook indéfinie dans la vue archives du Page Builder ; identifiants de sections stables ; signature des feuilles
compilées par contenu et non par date ; cache documents du service worker distinct par devise ; précache
du moteur sous les bonnes adresses ; délai des scripts tiers inactif en Safe Mode ; appels inter-modules
gardés. Sécurité : adresse client réelle derrière Cloudflare pour le limiteur et le frein de connexion
(plages Cloudflare publiées, filtre delicat_builder_v9_trusted_proxy_ranges), verrou après 20 jetons
d'application erronés, journal d'erreurs du moteur côté client. Lancement : en-tête Link (preload) pour
le shell, cache immuable d'un an sur assets/dist (.htaccess), passe de sortie allégée sans LiteSpeed
(~7 ms par page), tables pays/adresses WooCommerce (63 Ko, dépendances de wc-cart) retirées du panier
sans livraison ou sans calculateur.
(8) Menu et barre identiques sur toutes les pages (9.3, troisième passe) : sur l'accueil et les pages
d'information tous les liens du menu passaient en violet, le titre de la marque pouvait disparaître,
et sur la boutique et les fiches la carte wallet et les lignes du menu étaient plus hautes : les
feuilles de page atteignaient le chrome avec des sélecteurs d'élément. La règle du document natif est
limitée à son contenu et une feuille de garde (chrome-guard.css) fixe chaque couleur et chaque boîte
du tiroir, de la barre et de la marque. Barre : étagère sous la pilule (le contenu ne transparaît
plus), onglet actif recalculé depuis l'adresse, barre au-dessus de l'indicateur d'accueil dans
l'application installée. Navigation instantanée : l'écran suivant s'ouvre au toucher avec ce que
l'application sait déjà (image, titre et prix de la carte) et se remplit à l'arrivée du document ;
feuilles chargées en parallèle ; destinations de la barre préchargées ; pages visitées gardées cinq
minutes en mémoire ; deux mises en page forcées au démarrage supprimées. Octets : habillage
WooCommerce et feuille de compatibilité thème retirés des documents natifs (16 Ko, cinq requêtes).
Serveur : le fragment d'accueil est partagé avec les clients connectés (112 ms et 151 requêtes par
vue auparavant), sous une clé incluant connexion, rôles, devise et pays, et seulement s'il ne
contient ni nonce, ni nom, ni solde. iPhone : écrans de lancement générés depuis l'icône du site,
manifeste complété (id, display_override, raccourcis), barre d'état système, bouton retour dans
l'application installée, étapes « Sur l'écran d'accueil » au lieu de l'APK Android, invitation à la
deuxième visite. Téléphones modestes : ombres et transitions coûteuses retirées.
(9) Fiche de commande express : le bloc « Vos coordonnées » montrait l'adresse e-mail deux fois et
n'offrait aucun moyen de joindre le client. Chaque donnée n'apparaît qu'une fois (un champ en double
reçoit la même valeur) et le numéro WhatsApp est obligatoire, juste après l'e-mail, avec le clavier
téléphone, sur la fiche comme sur la page de paiement ; il est enregistré comme téléphone de
facturation de la commande. Le filtre delicat_builder_v9_whatsapp_required permet de l'assouplir.
(10) Les 52 jumeaux .min ont été retirés ; asset-min-map.php est vide par conception. Construction :
cd delicat-builder-v9 && npm install && npm run build (npm run zip pour l'archive installable).
Purgez LiteSpeed et le CDN après la mise à jour.

9.2.0-pro.54 : fusion des correctifs d'audit dans la pro.53. Cette version reprend l'intégralité
de la pro.53 (cartes produit compactes, moteur de badges, correctif de règlement express, module des
documents légaux) et y rejoue les correctifs de sécurité et de compatibilité application de la pro.15,
absents des mises en ligne précédentes.
(1) Maintenance : /delicat-app/v1/config reste joignable quand « Fermer aussi les API » est coché, afin
que l'application affiche l'écran de maintenance au lieu d'un contenu périmé ; la connexion depuis
l'application reste ouverte quand les callbacks de paiement le sont.
(2) Notifications : l'action delicat_builder_v9_announcement_published est rétablie en plus de la tâche
de livraison de la pro.53 ; l'App API dédoublonne par identifiant d'annonce, une seule notification part.
(3) Vente en direct : flux public pour l'application, retrait des commandes annulées ou remboursées,
exclusion des commandes de récompense, revendeur et personnel ainsi que des produits privés, verrou et ETag.
(4) Connexion : fenêtre fixe de 15 minutes, les refus du limiteur ne sont plus comptés, remise à zéro
après une connexion réussie, adresse client fournie par l'App API.
(5) Trousseau DELICAT_BUILDER_KEYS : les valeurs chiffrées survivent à un changement des clés (salts)
de wp-config.php ; le token Cloudflare et la clé Web Push sont rescellés à la lecture.
(6) Cache : invalidation distincte de la quantité et de l'état du stock, purges Cloudflare différées
vers une tâche de fond au lieu d'un appel bloquant.
(7) Panier : les recharges restent à une unité par ligne ; progression Delicat Rewards dans la carte
du portefeuille ; longueur maximale des champs de notification.
(8) Menu : « Meilleur client » pointe vers /program-bon-kliyan/ et le bouton par défaut de la section
Bon Kliyan également (les anciennes adresses renvoyaient une page 404 ou vide).
Le règlement express de la pro.53 est conservé et étendu : tout point d'entrée delicat_express_* règle
désormais en devise de base avant le test de politique, ce qui corrige aussi les boutiques qui facturent
dans la devise affichée. Purgez LiteSpeed et le CDN après la mise à jour.

9.2.0-pro.49: product archive v4-ultra. Rebuilt the archive again from the mobile screenshots: compact 16:10 cards, 12px mobile titles/prices, small pill CTA, one clickable target per card, eight-product modem budget, only the first image eager/high-priority, variable products render their synced starting price without building a full variation-price range, and native archives no longer load App Polish or Storefront Polish. Critical CSS now contains the final card geometry/typography so the page does not first paint oversized and then restyle. See ARCHIVE-V4-ULTRA-PRO49.md. Purge every active LiteSpeed/CDN/browser cache after updating.

9.2.0-pro.48: product archive v3-lite. Direct Woo-authoritative server renderer, 12-product modem budget, fixed early archive-setting cache, zero archive JS, smaller responsive cards/icons, content-visibility for offscreen cards, and route-level removal of Elementor/Woo classic catalog CSS on native archives. See ARCHIVE-V3-LITE-PRO48.md. Purge LiteSpeed/CDN after updating.

9.2.0-pro.46: four speed fixes found by reading what the storefront actually ships.
(1) Homepage card images: every card after the first three of each rail is built in the browser
from a JSON payload that carried one URL, the 768px `medium_large`, with `sizes` but no `srcset`.
`sizes` does nothing alone, so phones downloaded 768px art for a slot about 170px wide. The payload
now carries a width-capped srcset and carousel.js applies it (sizes, srcset, then src).
(2) Product cards were loaded one at a time against a cold object cache: post, terms, meta,
thumbnail post, thumbnail meta and the variation-price transient, per card, on every render
LiteSpeed does not answer (every signed-in customer, every guest miss). Query_Cache::prime_products()
fetches them in bulk; rails, related products and the product switcher use it. Output is unchanged.
(3) The plugin excludes its own files from LiteSpeed's optimizer, so its JavaScript shipped
unminified. tools/build-assets.mjs writes foo.min.js twins (whitespace and local names only: no
syntax rewriting, no transpiling) and asset-min-map.php records the source hash each was built
from. A twin is served only while that hash matches asset-versions.php, so a stale twin can never
be served -- the PRO37 drift cannot recur. SCRIPT_DEBUG or the delicat_builder_v9_use_min_assets
filter serves sources. 54 twins, 35% fewer bytes across them.
(4) storefront-chrome.min.css had drifted: it carried a drawer.css from before pro.32, so the
pro.32 drawer rules (full-height tablet drawer, compact phone layout, 44px targets) never reached
a storefront serving the bundle. Both bundles are now rebuilt from current sources by the same
tool. CHECK THE MENU DRAWER on a phone and a tablet after updating: it will look slightly different.
See PERFORMANCE-PRO46.md. Visit wp-admin once, then purge LiteSpeed and Cloudflare.

9.2.0-pro.45: skip all-device-hidden sections before rendering/query work and
asset discovery. Keep device-specific sections available for responsive layouts.
Choose lightweight CSS fallback files by byte budget (up to 6 requests) instead
of loading the full component bundle on every layout with more than 3 assets.
Preserve carousel support when a page contains both a shortcode and a layout.
See PERFORMANCE-PRO45.md and LITESPEED.md for validation and installation steps.

9.2.0-pro.44: non-base currencies are cacheable again. A shopper who picked USD, CAD or EUR
used to bypass the page cache entirely -- safe, but on a store with three foreign currencies
enabled that is a large share of traffic paying a full PHP render on every page. LiteSpeed can key
its cache on a cookie value (the plugin already did this for the cart cookie), so dmc_currency now
joins the vary set and each currency gets its own cached copy. The vary is only taken when the
BROWSER sent the cookie, it is valid and non-default, and it matches the currency actually being
rendered -- display_choice() resolves the Woo session before the cookie, and geolocation can pick a
currency with no cookie at all, so anything else still bypasses rather than risk serving one
currency's prices under another's key. Switch it off with the delicat_builder_v9_currency_cache_vary
filter. Matomo's mtm_/pk_ campaign parameters join the cache-safe query allowlist alongside utm_.
Purge LiteSpeed and Cloudflare after updating.

9.2.0-pro.43: the plugin is installable again. The header declared `Requires PHP: 8.5` to match
the host, and WordPress refuses an uploaded plugin whose requirement exceeds the server's --
decided with version_compare() on the REPORTED VERSION STRING, not PHP_VERSION_ID. A
release-candidate string like 8.5.0RC2 compares lower than a plain 8.5, and a control panel's
configured version is not always what the web SAPI reports, so the package stopped installing on
a server that genuinely runs 8.5. The requirement is now 8.3, which is what the code actually
needs -- one feature, the typed class constants in heart-engine. Nothing uses 8.4 or 8.5 syntax.
Running on 8.5 is unchanged. A new test asserts the header, all three runtime gates and the real
syntax floor stay in agreement, so this cannot recur.

9.2.0-pro.42: fixes a regression introduced in pro.40. Loading the Compiler on every admin
request meant the first request after an update ran a 250-page stylesheet compile -- and that is
very often the request installing, updating or deleting a plugin, while WordPress is moving files
on disk. The combination could exceed max_execution_time, so the plugin appeared unable to
install or uninstall itself. The repair now skips every plugin/theme management screen and every
non-idempotent request, and carries a wall-clock budget so it can never time out an admin page;
it resumes on the next ordinary admin page. Ordinary screens still repair the stylesheets, which
was the point of the pro.40 change.

9.2.0-pro.41: from the 60-agent adversarial audit. A declined card PERMANENTLY locked a
signed-in customer out of checkout: guard_classic() takes a mutex for every signed-in classic
checkout on every gateway, WooCommerce creates the order before charging, and reclaim_abandoned()
refused on order_id BEFORE its age check -- so the guard could never be reclaimed at any age and
only an admin could free them. Nothing had been charged. Every product view fired a second full
WordPress bootstrap (/?dbp_state=1) even for a guest with no cookies, roughly doubling origin PHP
on the most-visited route; it now fires only for visitors who actually have state. The cache-tier
gate disagreed with the render gate, so six request shapes -- site search with a basket being the
everyday one -- printed the shopper's real cart AND shipped with no cache headers at all. The Pro
state endpoint answered on init:5 and walked through maintenance mode's API lock. Purge LiteSpeed
and Cloudflare after updating.

9.2.0-pro.40: speed. pro.30 replaced "any query string is private" with an allowlist in
public_cache_allowed(), but THREE other gates kept the old rule and vetoed it -- including one
that defines DONOTCACHEPAGE, killing the page cache, the fragment cache and LiteSpeed for the
whole request. Every ad click (?fbclid, ?utm_*, ?gclid) and every ?paged/?orderby hit was a full
PHP render. On an ad-driven store that is most of the traffic. Instant navigation was aborting on
EVERY page: one <script type="module"> in the destination (WordPress 6.5+ emits them everywhere
via the Interactivity API) threw before checking whether that module was already running, so the
engine hard-navigated and the browser downloaded the document a second time. Back from a product
dumped shoppers at the top of the listing. Inline wp_localize_script data froze at the first
page's values across soft navigation. Compiled page stylesheets now rebuild on any admin page
instead of only Delicat Builder screens, so the homepage stops falling back to the 176 KB bundle
after an update. Purge LiteSpeed and Cloudflare after updating.

9.2.0-pro.39: two money/checkout bugs. Cart prices COMPOUNDED: apply_cart_prices()
recomputed from the product object it had already marked up, and WooCommerce fires
woocommerce_before_calculate_totals several times per request, so a 250 base with a 50
option charged 300, then 350, then 400 -- and a percentage or {base}*{qty} formula
compounded geometrically. Shoppers were overcharged silently. Express checkout was
UNUSABLE in any non-base currency: the review sheet issued its cart hash in the display
currency while pay() re-hashed in base currency, so every express payment failed with
"Le panier a change" and refreshing never helped. Two new regression tests, both
verified to fail on the unfixed code. Purge LiteSpeed and Cloudflare after updating.

9.2.0-pro.38: two formula bugs that could sell a product for 0.00 are fixed. An unknown
variable in a pricing formula resolved to 0 and still reported success; every arithmetic failure
(division by zero, modulo by zero, sqrt of a negative, an incomplete formula) returned 0.0, which
evaluate_checked() could not tell apart from a formula that legitimately equals zero, so
compute_total() took the success branch and priced the product free with nothing logged.
`{base} / {qty}` with an inactive qty field reached this on ordinary configuration. PHP
requirement restored to 8.5. New tests/test-formula-eval.php covers both. Purge LiteSpeed and
Cloudflare caches after updating.

9.2.0-pro.37: Pro Kernel boots again (a wp_rand() call on the boot path threw before
WordPress had loaded pluggable.php, which silently disabled instant navigation, route-scoped
assets, critical CSS and the shared state store). Asset pipeline switched from duplicate hashed
files to ?ver= content hashes: 121 duplicate files and 1.77 MB removed. PHP floor corrected from
8.5 to the real requirement, 8.3. Homepage critical-CSS budget raised to 9500 bytes so the product
grid stops being dropped. Purge LiteSpeed and Cloudflare caches after updating.

9.2.0-pro.36: like buttons now render on the homepage and signed-in favourites save (see RELEASE-PRO34.md). Purge LiteSpeed and Cloudflare caches after updating.

9.2.0-pro.33: like button rebuilt and PHP 8.5 required (see RELEASE-PRO33.md). Purge LiteSpeed and Cloudflare caches after updating.

9.2.0-pro.10: security fixes and validation notes are in SECURITY-AUDIT-PRO10.md. Purge existing page/CDN caches after updating.

RC88: see AUDIT-RC88.md for the full live-site repair follow-up, secure product-field handling, deterministic customer-facing icons, coordinated Tutoriels hardening and staging validation requirements.

RC86: see AUDIT-RC86.md for the continued stability/responsiveness audit and staging validation requirements.

RC85: see AUDIT-RC85.md for the stability/performance audit and staging validation requirements.

RC84: see AUDIT-RC84.md for the current update and staging validation requirements.

DELICAT BUILDER V9 — 9.1.0-rc.82
Assistant Position Recovery production package

What RC71.6 fixes
-----------------
1. Closed Assistant: the launcher now sits at the true bottom-right edge because the deleted standalone WhatsApp button no longer needs a reserved slot.
2. Open Assistant: the chat is a stable mobile sheet tied to the visible viewport, not an oversized panel offset from the launcher.
3. Correct vertical placement: the sheet clears the WordPress admin toolbar at the top and the Delicat Bottom Nav at the bottom.
4. Commerce-safe clearance: product purchase and checkout docks receive their own panel bottom insets.
5. Cache cleanup: stale PWA or page-cache copies of the retired `BXQXUCKDDI3GO1` floating button are removed without hiding normal WhatsApp support links.
6. Assistant content keeps its own internal scrolling, keyboard behavior and reduced-motion support.

What RC71.5 fixes
-----------------
1. Floating actions: Delicat Assistant and WhatsApp are two equal app-style buttons in one bottom-right row instead of overlapping.
2. Cleaner launcher: the promotional text is removed at every viewport size and an empty or zero unread badge is hidden.
3. Open chat state: WhatsApp automatically yields while the Assistant panel is open and returns when the panel closes.
4. Safe mobile placement: the contact row clears the Bottom Nav, product purchase dock, checkout dock and iPhone safe area.
5. Phone-sized panel: known Assistant panel containers are constrained to the visible viewport with compact app-style corners.
6. Smooth and efficient behavior: transitions respect reduced-motion, while a scoped observer tracks only the Assistant subtree after it appears.

What RC71.4 improves
---------------------
1. Faster first screen: Shop, Product and Account routes reuse one app-shell stylesheet instead of requesting Theme, Header, Drawer and Bottom Nav separately.
2. Lighter background work: product asset warming is delayed until idle and capped by device memory; speculative product-page warming is reduced to one or two documents.
3. Mobile app scale: compact 64px header, 44–48px controls, iOS-safe 16px inputs, readable badges, balanced headings and consistent page gutters.
4. App-like storefront: refined homepage hero/carousels, product cards, archive cards, product purchase panels, cart, checkout, account and search layouts.
5. Smoother scrolling: below-fold homepage sections defer rendering, low-power devices avoid expensive effects, and live-sale images reserve their final dimensions.
6. Existing cross-document page transitions, reduced-motion support, authentication recovery and all WooCommerce/security authority remain intact.

What RC71.3 fixes
-----------------
1. Google recovery contract: wp-login.php uses `dip_error`, while RC71.2 listened only for `dip_auth_error`. Both forms are now normalized back into the Delicat storefront modal with the original safe error code.
2. Reliable sign-out: logout taps first request a fresh live session payload and use its current WordPress nonce, preventing stale cached Android pages from submitting an expired logout link.
3. Post-login synchronization: successful Identity transitions force a live session check and clear stale service-worker documents before the account UI renders.
4. Android card layout: legacy “Comment ça marche” and “Pourquoi Delicat” cards use explicit mobile flow/grid containment so text cannot collapse into the icon column or inherit desktop height.
5. Cached header compatibility: old two-logo markup is normalized at runtime, showing exactly the active light/dark logo even in Android WebViews.
6. OAuth state, PKCE, nonce, browser binding, 2FA, account policy, checkout, payments, wallet and WooCommerce authority remain unchanged.

What RC71.2 fixes
-----------------
1. Sign-out / sign-in recovery: every Builder logout returns through a one-time live-session reset so signed-out state settles immediately.
2. Native login: when signed out, Header and Bottom Nav “Compte” open Delicat Identity directly instead of WooCommerce / wp-login.php.
3. Google recovery: Identity errors that accidentally land on wp-login.php are routed back to the Delicat Store login modal without bypassing OAuth state, PKCE, nonce, 2FA or account policy.
4. Stale PWA state: HTML document caching is disabled while Delicat Identity is the authentication authority. Static CSS, JS, fonts and images remain cacheable for speed.
5. Private cache defense: HTML carrying Cache-Control private, no-cache or no-store is never written into Builder's document cache.
6. Header duplicate logo: Header Studio now renders one logo element. Dark mode swaps that element's source instead of keeping two logo links in the DOM.
7. Assistant on mobile: the launcher itself is pinned above the Bottom Nav regardless of its wrapper state, and promotional copy is hidden on phones.
8. RC71/RC71.1 performance and UI fixes remain in place. No checkout, payment, order, wallet, Player ID, supplier or WooCommerce authority logic was bypassed.

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
