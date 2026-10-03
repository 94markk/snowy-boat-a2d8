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
