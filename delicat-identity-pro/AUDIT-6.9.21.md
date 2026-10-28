# Delicat Identity Pro 6.9.21 — audit et correctifs

Base : ZIP 6.9.20 fourni. Livraison : correctif du plugin, non installé sur le site.

## Résultat

Le parcours du bouton « Continuer avec Google » est maintenant pris en charge directement par le moteur du popup : navigation complète dans le même onglet, message immédiat, protection contre les doubles clics, retour à un état réessayable si la navigation ne part pas. Le démarrage Google n’attend pas la requête de nonce du formulaire mot de passe.

La cause exacte des incidents signalés sur les appareils clients n’a pas été reproduite sur le site réel. Les défauts ci-dessous sont identifiés dans le code ou représentent des conflits d’intégration possibles; ils ne prouvent pas que chacun était actif sur votre site.

## Constats et corrections

| Zone | Constat dans 6.9.20 | Correctif 6.9.21 |
| --- | --- | --- |
| Bouton Google | Lien normal sans gestion dédiée face à un intercepteur de navigation SPA au niveau du document | Gestion en capture au niveau de window, navigation native, feedback français, anti-double clic. Un intercepteur antérieur au niveau de window peut toujours nécessiter un réglage dans l’autre plugin. |
| Attributs du lien | Les attributs de contournement n’étaient pas explicitement déclarés dans la liste locale de sanitization | Autorisation explicite des attributs connus, ajout des indications de non-préchargement. Leur respect dépend du routeur/préchargeur utilisé. |
| Attente Google | Aucun indicateur ni moyen local de sortir d’un état de navigation bloquée | Message immédiat et remise à disposition après 10 s. Pas de relance OAuth automatique. |
| Chargement différé JS | Aucun timeout; un élément script échoué restait présent et pouvait être considéré comme chargé lors d’un nouvel essai | Deadline 10 s, suppression sur erreur, partage de promesse pendant le chargement, état chargé explicite. |
| Requête du loader | Timeout dépendant d’AbortController | Deadline Promise.race couvrant aussi la lecture du corps, même sans AbortController. |
| Erreurs fetch synchrones | Certains chemins pouvaient quitter avant l’installation du catch et conserver busy | Exécution de fetch dans une promesse dans modal, loader, passkeys et déconnexion. |
| Réessai mot de passe | loading(false) pouvait désactiver à nouveau le bouton après un échec de renouvellement du nonce | Le bouton reste réessayable; le serveur continue d’exiger un nonce valide. |
| Navigation historique | État busy et réponses de l’ancienne instance de requête pouvaient survivre | Réinitialisation au retour BFCache et garde de génération pour les réponses du formulaire. Le routeur de session conserve sa politique existante de rechargement pour synchroniser l’authentification. |
| Ouvertures répétées | Une seconde demande d’ouverture effaçait un message déjà affiché | Une ouverture d’un popup déjà ouvert ne réinitialise plus son état. |
| Passkeys | Le délai indiqué au navigateur n’était pas une garantie; une promesse WebAuthn bloquée gardait Google indisponible | Deadline client de 60 s pour la cérémonie, tentative d’annulation, rejet des résultats tardifs. Les requêtes options/verify gardent chacune leur délai réseau. |
| Retour mot de passe réussi | Une navigation empêchée pouvait laisser le formulaire occupé | Message de réussite avec instruction d’actualisation et déverrouillage borné. |
| Profil Google | Requête UserInfo faite systématiquement après validation du jeton | Requête évitée si le jeton signé fournit déjà nom et image; repli limité à 3 s sinon. |
| Tactile | Les boutons Google occupés ignoraient les taps via pointer-events:none | Les taps peuvent maintenant expliquer l’attente; règles touch-action pour défilement vertical et boutons. |

## Sécurité conservée

Aucune modification des contrôles PKCE, état OAuth, liaison navigateur, signature Google, audience, émetteur, nonce, politiques de compte, verrouillage, 2FA ou propriété des comptes. Le contrôle cryptographique précède toujours l’utilisation des données Google. Aucun compte client, commande, portefeuille, secret OAuth ou réglage de production n’a été modifié par ce travail.

## Périmètre de l’audit

Inventaire du ZIP entier : 101 fichiers d’origine, dont 64 PHP et 12 JavaScript. Recherche transversale des points d’entrée REST/AJAX, appels réseau, stockage, envois d’e-mails et attentes bloquantes. Revue détaillée du moteur du popup, loader, passkeys côté client, routage de session, rendu/sanitization du bouton, démarrage/retour Google, nonces navigateur et stockage des flows.

Il s’agit d’une revue statique du paquet avec tests ciblés du frontend, pas d’une validation exhaustive de chaque module administratif, de l’application mobile ou de toutes les intégrations. Les anciennes affirmations de tests dans FIX-6.9.20.md appartiennent à cette ancienne livraison et n’ont pas été réexécutées ici.

## Vérifications effectuées

- Syntaxe Node vérifiée sur les 12 fichiers JavaScript applicatifs.
- 12 scénarios exécutés contre le vrai script du modal dans un environnement Node VM avec fixtures DOM : Google pendant nonce bloqué, double tap, timeout navigation, exception navigation, conflit passkey, échec nonce, exception fetch synchrone, corps de réponse bloqué, fermer/rouvrir avec réponse périmée, ouverture répétée, retour BFCache et initialisation normale.
- 7 tests unitaires asynchrones : timeout sans AbortController, lecture réseau bloquée, script bloqué supprimé, nouvel essai après erreur, chargements concurrents, cérémonie WebAuthn bloquée et résultat tardif ignoré.
- Tests reproductibles inclus sous tests/ : `node tests/regression.cjs` et `node tests/async-regression.cjs`, depuis le dossier du plugin.
- Structure du ZIP, version principale, références d’assets et fichiers modifiés contrôlés.

Non exécuté ici : PHP/WordPress/WooCommerce réels, `php -l` (interpréteur PHP indisponible), vrai Google OAuth, rendu et interaction dans Safari/Chrome, ancien téléphone, SMTP, LiteSpeed/Cloudflare et application mobile. Aucun gain chiffré de vitesse ne peut être annoncé sans mesures sur le site.

## Points restant à vérifier sur le serveur

- Les notifications de connexion/appareil et certains e-mails restent synchrones. Un SMTP lent peut encore allonger le retour après authentification; remplacer ce fonctionnement exige une file durable et des essais de délivrabilité.
- Le callback Google peut attendre le réseau pour échange de code/clés; les tentatives et protections existantes sont conservées.
- Les extensions de cache doivent laisser passer les URLs `dip_action` et les réponses d’authentification. Un cache qui répond avant WordPress ne peut pas être corrigé par ce ZIP seul.
- Un ancien snippet de connexion ou un routeur tiers peut toujours intervenir avant ce plugin. Vérifier l’avertissement existant dans l’administration.
- Les navigateurs intégrés Instagram/Facebook restent soumis aux restrictions Google; le parcours existant d’ouverture dans le navigateur externe est conservé.

## Installation et validation

1. Conserver une sauvegarde du plugin et de la base. Remplacer 6.9.20 par le ZIP 6.9.21 via Extensions → Ajouter → Téléverser → Remplacer la version actuelle, idéalement d’abord sur staging.
2. Purger LiteSpeed et le cache CDN/Cloudflare utilisé pour les pages. Fermer les anciens onglets puis rouvrir le site.
3. Tester « Continuer avec Google » en navigation privée sur iPhone Safari et Android Chrome : connexion réussie, annulation puis nouvel essai, double tap et retour arrière.
4. Tester aussi mot de passe incorrect/correct, inscription, vérification email, mot de passe oublié et 2FA/passkey si activés. Vérifier le compte WooCommerce et le portefeuille du même utilisateur.
5. Si la page reste bloquée, relever l’heure, l’appareil, le navigateur et le message affiché, puis les logs PHP/réseau correspondants sans partager de mot de passe ou jeton OAuth.

Références techniques consultées :
- https://developers.google.com/identity/openid-connect/openid-connect
- https://developer.mozilla.org/en-US/docs/Glossary/bfcache
- https://developer.wordpress.org/reference/functions/wp_enqueue_script/
