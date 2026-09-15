# Delicat Builder 9.2.0-pro.15 — vitesse

Date : 15 septembre 2026. Cette version remplace PRO14 et conserve les réglages
Builder. Le site en production n'a pas été modifié depuis cet environnement.

Cette version ne corrige que des problèmes de vitesse. Aucune fonctionnalité n'est
retirée, aucune chaîne d'interface n'est modifiée, aucun réglage n'est réinitialisé.
La cible reste celle de la boutique : un téléphone sur un lien 3G, LiteSpeed Cache
et Cloudflare devant, sans cache objet persistant.

## 1. La passe de sortie ne s'exécutait qu'une fois — déjà livrée

Rappel de la correction précédente, conservée ici : quand LiteSpeed Cache est actif,
il ouvre son propre tampon de sortie sur `after_setup_theme` et enveloppe le nôtre.
La passe de réécriture s'exécutait donc deux fois sur chaque réponse non mise en
cache. Elle ne s'ouvre plus quand LiteSpeed détient le tampon, et le chemin courant
(page sans réécriture LiteSpeed) coûte désormais moins d'une milliseconde au lieu de
seize.

## 2. Règles de préchargement : le vrai coût sur 3G

Un `prerender` télécharge un second document complet — HTML, CSS, JS et images — sur
la connexion que le client utilise déjà pour la page devant lui. RC79 avait établi
que c'est une perte nette sur 3G et avait placé une garde côté navigateur : les
règles du Builder partaient inertes et n'étaient activées que sur un lien capable de
les porter.

Cette garde ne couvrait que notre copie. WordPress 6.8 imprime ses propres règles, et
le Builder les faisait passer en `prerender` / `moderate` pour tout le site. Le script
de WordPress est actif dès qu'il est analysé : il ne peut pas être retenu. En pratique
chaque visiteur non connecté préchargeait donc des documents entiers, garde comprise.

PRO15 :

- Quand la navigation Pro est active, les règles du cœur sont déclinées. `dbp-nav.js`
  précharge déjà le fragment de navigation du lien attendu ; précharger le document
  complet du même lien, sur la même connexion, pour le même appui, est un doublon.
- Quand le préchargement Builder est activé, les règles du cœur sont remplacées par
  la copie sous garde réseau, désormais imprimée pour les visiteurs non connectés
  aussi, pas seulement pour les clients connectés.
- Quand le préchargement Builder est désactivé, les règles du cœur sont laissées
  telles quelles : le `prefetch` conservateur par défaut est peu coûteux et sûr sur
  un lien lent.
- La garde ne dépend plus de la couche App Tuning. Elle relit elle-même le type de
  connexion effectif, `saveData`, le débit et la latence.

## 3. Service worker : le préchargement à l'installation ne servait à rien

Les pages demandent la copie à nom horodaté par empreinte
(`session.6a162f0efdc8.js`), sans chaîne de requête. Le service worker préchargeait
la forme `session.js?ver=9.2.0-pro.14`. L'entrée mise en cache à l'installation ne
correspondait donc à aucune URL demandée par le site : le téléchargement
d'installation était dépensé et chaque fichier manquait quand même à la première
visite.

De plus, un nom à empreinte est la forme la plus forte de version — le nom change
quand les octets changent — mais il ne porte pas `?ver=`. Le worker le traitait comme
non versionné et le retéléchargeait en arrière-plan à chaque page vue, sur un
forfait mobile.

PRO15 :

- La liste de préchargement passe par la table des empreintes, exactement comme les
  balises de la page.
- Un nom à empreinte est reconnu comme versionné : un succès de cache est renvoyé
  sans requête réseau.
- La liste suit la couche active : `dbp-nav.js`, `dbp-app.css` et `dbp-type.css`
  quand le noyau Pro les sert, `shell-nav.js` seulement quand il ne les sert pas.

## 4. Le nettoyage Front Slim était retiré sous le noyau Pro

Le noyau Pro retirait les deux passes de Front Slim. Une seule le gênait : la passe
de report générale en priorité 9999, qui réécrit la stratégie de chargement de tous
les identifiants `delicat` et `dsb`.

L'autre, `slim_scripts`, ne touche aucun identifiant Pro. Elle désenregistre le
sondage heartbeat, retire dashicons pour les visiteurs, retire la police Google du
thème sur un accueil géré, allège les liens classiques de l'en-tête et — la plus
coûteuse sur un téléphone — reporte `wc-cart-fragments` à l'inactivité ou à la
première interaction. La retirer rendait un aller-retour `get_refreshed_fragments`
immédiat à chaque page pour tout client connecté. Elle est conservée.

## 5. Le magasin d'état Pro interrogeait le serveur pour rien

Une requête d'état est un aller-retour complet vers l'origine plus un chargement de
session WooCommerce côté serveur. Elle partait au chargement de chaque page produit,
panier, commande, compte et portefeuille, et pour chaque client connecté.

Le client ne dépense plus cette requête que si ce document a quelque chose à mettre à
jour : une pastille, un emplacement portefeuille ou identité, un champ nonce, un
abonné, ou les paramètres d'ajout au panier WooCommerce dont le nonce mis en cache
expire au tick. La vérification est refaite à chaque déclencheur, donc une navigation
ou une fenêtre qui amène un consommateur dans le document le réactive.

Le point de terminaison serveur, la limite de débit et l'API `DBPState` ne changent
pas.

## 6. Cloche de notifications : sondage à 90 secondes

Le minuteur passe à 5 minutes pour un client connecté et 15 minutes pour un visiteur.
Le rafraîchissement au retour sur l'onglet et le chemin de notification poussée sont
inchangés, et le sondage reste suspendu quand l'onglet n'est pas visible.

## 7. Écritures en base sur le chemin public

- Le diagnostic « dernière tentative express » écrivait une transient — deux écritures
  d'option sans cache objet persistant — à chaque vue de fiche produit : `guest` pour
  chaque visiteur, `rendered` pour chaque client connecté. Les états de la marche
  normale ne sont plus enregistrés que pour un utilisateur qui peut ouvrir le panneau
  qui les lit. Un échec réel est toujours enregistré.
- La mise à niveau du compte de service Firebase déchiffrait le secret et analysait
  son JSON à chaque requête de la boutique, pour un secret que seules les pages
  d'administration et l'envoi de notifications écrivent. Elle ne s'exécute plus qu'en
  administration et en WP-CLI. Un secret non migré continue de fonctionner :
  l'envoi lit toujours l'ancienne option.
- `dsb_notification_privacy_migrated` est lu à chaque `init` et n'est pas autoloadé.
  Il rejoint la liste d'amorçage des options, donc sa lecture devient un succès de
  cache au lieu d'une requête.

## 8. Requêtes de pages dans le pied de page

Le pied de page résout sept documents légaux à chaque rendu de page, et chacun
demandait son article séparément — sept allers-retours MySQL avant qu'un seul lien
ne soit imprimé. Les identifiants configurés sont désormais récupérés en une requête,
et la résolution est mémorisée pour la durée de la requête côté public (le pied de
page demandait la même clé au moins deux fois par lien). Les trois pages natives
support, conditions et confidentialité sont récupérées de la même façon.

Le comportement en administration est inchangé : aucune mémorisation, donc une page
créée pendant la requête est vue immédiatement.

## 9. Invitation à laisser un avis

La vérification partait en admin-ajax — amorçage complet de WordPress et des
extensions — à chaque page ouverte par un client connecté, pour une réponse qui est
« non » presque à chaque visite.

Elle s'exécute maintenant hors du chemin critique, après `load` et en temps
d'inactivité, et un « non » est retenu six heures dans un cookie court. La page de
confirmation de commande ne consulte jamais ce cookie : le seul moment qui compte,
la page juste après un achat, exécute toujours la vérification.

Le stockage reste un cookie. Rien concernant un client n'est écrit dans le stockage
local de l'appareil.

## Ce qui n'a pas été touché

- La limite de débit reste exacte. Elle écrit une transient par appel autorisé, ce
  qui a un coût, mais c'est une barrière de sécurité : elle n'est pas affaiblie pour
  gagner une écriture.
- Les fragments de navigation et les pages personnalisées restent `no-store`, comme
  établi par les audits PRO10 et PRO12.
- Aucune chaîne d'interface n'est modifiée. L'interface reste en français.

## Vérification

- `php -l` sur les 106 fichiers PHP, `node --check` sur les 46 fichiers JavaScript
  source (les copies à empreinte sont identiques octet pour octet).
- La passe de sortie reste idempotente sur une page de 404 Ko réécrite par
  LiteSpeed : première passe 10,6 ms, seconde passe 0,5 ms, sortie identique.
- Les empreintes d'actifs et `integrity-manifest.json` sont régénérés depuis les
  fichiers livrés.
