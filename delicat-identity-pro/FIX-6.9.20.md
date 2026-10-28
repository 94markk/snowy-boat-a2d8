# 6.9.20 — fiabilité de la connexion Google et nettoyage

Cette version corrige les échecs intermittents de connexion Google (« ça échoue plusieurs fois, puis ça marche plus tard ») et supprime le code inutilisé. Elle inclut les corrections 6.9.19.

## Causes trouvées et corrigées

Chaque cause ci-dessous a été reproduite sur un WordPress 6.5 de test avec un Google simulé (jetons RS256 réellement signés), puis vérifiée après correction.

| Cause | Symptôme client | Correction |
| --- | --- | --- |
| Derrière Cloudflare, tous les clients d’un même point de présence partageaient une seule adresse (celle de Cloudflare) pour le limiteur et le verrouillage. | Après 10 connexions Google en 10 minutes sur ce point de présence : « Trop de tentatives », puis « Connexion temporairement bloquée » pendant 20 minutes pour tout le monde. | Pour les limiteurs Google/OAuth, boutique headless et API mobile, l’adresse du visiteur (`CF-Connecting-IP`) est utilisée quand la connexion provient réellement des plages publiées par Cloudflare (IPv6 regroupé par /64). Un en-tête envoyé directement à l’origine reste ignoré. Les protections des mots de passe gardent le comportement précédent (confiance seulement avec `DIP_TRUST_CLOUDFLARE_CONNECTING_IP`). Désactivable avec `define('DIP_TRUST_CLOUDFLARE_HEADERS', false);`. |
| Le limiteur comptait chaque clic et relançait sa durée à chaque essai ; un refus comptait lui-même comme un échec. | Plus le client réessayait, plus le blocage durait. | Limite par appareil (adresse + navigateur) avec un plafond dix fois plus élevé par adresse (NAT des opérateurs mobiles), fenêtres fixes. Seules les tentatives falsifiées (signature, audience, nonce, émetteur…) alimentent le verrouillage ; erreurs réseau, annulations, sessions expirées et refus de compte n’y comptent plus. |
| Un second retour Google pour la même tentative (double appui, préchargement, onglet personnalisé Android) consommait un état déjà utilisé. | Message « session expirée » alors que le client était en fait connecté. | Le second retour affiche le résultat du premier. Si le navigateur n’a jamais reçu la réponse, la session est redonnée une seule fois, au même navigateur uniquement (cookie de liaison). Le code Google n’est jamais échangé deux fois. |
| L’état OAuth vivait dans le cache objet (Redis/Memcached) pendant 10 minutes. | « Session expirée » après une purge du cache ou un passage long par la validation Google (2 étapes, SMS). | État enregistré en base (`wp_options`, sans autoload), durée 30 minutes, réservation atomique (`INSERT IGNORE`) et nettoyage automatique. |
| Une seule coupure réseau vers Google faisait échouer la connexion. | « Impossible de contacter Google » / « clés Google indisponibles ». | Une nouvelle tentative pour l’échange du code et les clés ; dernières clés Google connues utilisées si Google est injoignable (7 jours maximum). |
| Connexion commencée sur `www.` alors que le site est sur le domaine nu (ou l’inverse). | « Session Google plus liée à ce navigateur ». | Redirection vers l’hôte du site avant de créer la tentative. |
| Navigateurs intégrés d’Instagram, Facebook, TikTok, etc. | Page Google « Erreur 403 : disallowed_useragent ». | Page « Continuer avec Google dans votre navigateur » : Ouvrir dans Chrome (Android), copier le lien, ou essayer quand même. |
| Boutique headless : l’adresse de retour imbriquée n’était pas encodée. | Avec 2FA, ou si Safari perdait les cookies, retour « session expirée » ; `next` tronqué. | Paramètres imbriqués encodés ; durée alignée sur 30 minutes ; refus explicite si l’étape Google a échoué. |

## Défauts de réglages corrigés

- **Corruption des réglages** : le bouton « Réactiver Google », la page Fournisseurs et la restauration d’une sauvegarde repassaient les réglages enregistrés dans le filtre du formulaire, qui transformait chaque « non » en « oui ». Conséquences possibles : **maintenance Google activée**, **approbation obligatoire des nouveaux comptes**, blocage adaptatif, API mobile et Microsoft activés. Corrigé. Après la mise à jour, une notice liste les options sensibles actives (maintenance, approbation, blocage adaptatif, pays obligatoire, API mobile, notifications et biométrie mobiles, Microsoft, inscription par Google, liaison par e-mail, Google Secure Mode Administrateur) jusqu’au prochain enregistrement des réglages.
- Le bouton « Réactiver Google » était refusé par le pare-feu d’administration (« Action non classifiée »). Corrigé.
- Les trois cases « Performance » étaient hors du formulaire et repassaient à « non » à chaque enregistrement. Déplacées dans le formulaire.
- Un « Connecter Google » dont la session a expiré ne se transforme plus en connexion (risque de second compte).
- La page est purgée du cache quand un réglage qui affiche ou masque le bouton Google change.

## Autres corrections

- E-mail « Compte **Array** connecté » lors de la liaison d’un compte.
- Le retour Google est traité après l’initialisation de LiteSpeed Cache (cookie de variation correct) ; le marqueur `dip_auth_sync` est unique à chaque connexion pour qu’aucun cache ne partage une page de retour.
- Les retours en double pour la boutique headless sont enregistrés avant la redirection vers la boutique ; un retour rejoué n’attend plus (seul le navigateur d’origine peut attendre, 30 s maximum après la première tentative).
- Les échecs sans page d’origine renvoient vers le popup de la boutique plutôt que vers `wp-login.php`.
- Les scripts et notices de la page Réglages fonctionnent aussi depuis le menu Delicat Identity ; le contrôle de santé vérifie les bonnes tables ; le compteur « Nouveaux clients » compte le bon événement.
- Plus d’erreur fatale sur le tableau de bord client si WooCommerce est désactivé.

## Code supprimé

Chaque suppression a été vérifiée par une recherche de toutes les références (appels, callbacks, hooks, AJAX/REST, JS, CSS) puis contre-vérifiée indépendamment :

- Module « Santé système » (`DIP_Foundation`) : trois tables jamais lues ni écrites, une sauvegarde jamais relue, un bouton « Réparer » qui recréait seulement ces tables. Les tables existantes restent en base (désinstallation non destructive).
- Ancien moteur `assets/identity-modal-v3.js` (jamais chargé ; `identity-modal-v3.css` est conservé).
- Réglages sans effet (« architecture modulaire », « alerte e-mail santé »), carte « Apple / Discord / Steam — modules futurs », panneaux Email Studio ignorés à l’envoi, couleur « Accent léger », notice de nettoyage de l’ancien Email Studio.
- Chemins de repli inaccessibles, méthodes jamais appelées, doublons neutralisés (ancienne notice de paiement, widget tableau de bord, menu « Comptes connectés »), règles CSS sans balisage.
- `integrity-manifest.json` (non lu, périmé), squelette PHPUnit jamais exécuté (pas de configuration CI), 14 anciennes notes de version (la matrice de tests de staging est conservée dans DEVELOPER.md).

## Vérifications effectuées

- Syntaxe : tous les fichiers PHP (`php -l`) et JavaScript (`node --check`).
- WordPress 6.5 réel (SQLite, sans WooCommerce), Google simulé : 32 scénarios de bout en bout, dont Cloudflare partagé (25 clients), verrouillage, double retour, réponse perdue, rejeu depuis un autre navigateur (refusé), coupures réseau, cache vidé en cours de connexion, 2FA, boutique headless (normal, cookies perdus, 2FA, annulation, rejeu sans attente), navigateurs intégrés, regroupement IPv6 /64.
- Activation/réactivation, toutes les pages d’administration du plugin, pages boutique invité/client, routes REST et AJAX, actions d’administration : aucune erreur PHP du plugin.

Non testé : un vrai site avec WooCommerce, LiteSpeed et Cloudflare, de vrais comptes Google, l’application mobile, PHP 7.4 (aucune syntaxe PHP 8 n’a été ajoutée).

## Installation

1. Sauvegarder fichiers et base. Tester d’abord sur staging.
2. Remplacer le plugin par ce ZIP (Extensions → Ajouter → Téléverser → Remplacer).
3. Ouvrir le tableau de bord : si la notice « ces options sont activées » apparaît, vérifier chaque option listée dans Réglages → Delicat Identity, puis enregistrer.
4. Purger LiteSpeed et Cloudflare.
5. Tester Google en navigation privée, sur mobile, depuis Instagram/Facebook, puis la boutique headless.

## Recommandations non appliquées (choix de conception)

- **Email Studio** : les objets et titres personnalisés des e-mails WooCommerce ne sont jamais appliqués, car le filtre est enregistré sur `plugins_loaded` alors que ce fichier est chargé pendant `plugins_loaded` (priorité 28). Remplacer `'plugins_loaded'` par `'init'` dans `includes/email-studio/helpers.php` les activerait, mais appliquerait aussi les objets par défaut du Studio à tous les e-mails WooCommerce dont la personnalisation est active. Changement visible par les clients : à décider.

- Les comptes Google non Gmail et non Workspace doivent toujours prouver leur adresse locale : c’est une protection contre la prise de compte par adresse e-mail. Un parcours de preuve dédié pourrait remplacer l’erreur « email_exists ».
- L’identifiant d’appareil dépend du user-agent : une mise à jour du navigateur crée un « nouvel appareil ». Le changer enverrait un e-mail « nouvel appareil » à tous les clients une fois.
- Les e-mails envoyés pendant le retour Google restent synchrones : un serveur SMTP lent ralentit la connexion.
- API mobile : erreurs Google transitoires et jetons expirés renvoient un 401 générique ; la rotation des jetons de rafraîchissement n’a pas de délai de grâce concurrent. Ces points demandent une évolution coordonnée avec l’application.
