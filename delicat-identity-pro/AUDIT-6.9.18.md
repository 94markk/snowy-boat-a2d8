# Delicat Identity Pro 6.9.18 — audit et corrections

Date : 15 septembre 2026. Base : fichier fourni 6.9.17-auth-session-recovery.

## Résultat et portée

Cette version corrige les défauts identifiés dans le parcours connexion/déconnexion, les destinations de redirection et le popup. L’interface conserve les onglets français, Google, Passkey, mot de passe, récupération, inscription et étape 2FA. WordPress reste responsable des mots de passe, cookies et sessions ; WooCommerce conserve ses hooks et son compte client.

Audit statique transversal des fichiers PHP/JavaScript et examen approfondi des chemins concernés : authentification native, redirections WordPress/WooCommerce/sociales, état OAuth, intégration Passkeys, contrôles 2FA, autorisations AJAX/REST et cache de transition. Ce travail ne constitue pas une certification de sécurité ni une garantie d’absence de tout défaut dans les autres extensions. Aucun accès au serveur, aux réglages de production, aux comptes clients ou aux journaux privés n’a été utilisé. Aucune mise à jour du site en production n’a été effectuée.

Les captures montrent une confirmation WordPress de déconnexion puis l’écran « Votre déconnexion a bien été effectuée ». Elles ne permettent pas d’identifier à elles seules l’origine exacte du lien périmé ou le message d’erreur de connexion distinct signalé. Les correctifs ciblent les défauts effectivement trouvés dans le code fourni.

## Défauts corrigés

| Priorité | Constat dans le code | Correction |
| --- | --- | --- |
| Haute | Formulaires du popup sans méthode explicite : le comportement HTML par défaut est GET si le JavaScript ne prend pas la main. | POST explicite vers l’action d’authentification, avec action cachée. Les identifiants ne sont plus placés dans la query string par ce formulaire. |
| Haute | Consommer l’état OAuth par lecture puis suppression d’un transient ne suffit pas contre deux workers ayant déjà lu le même état. | Marqueur de consommation exclusif en base conservé jusqu’à expiration ; contrôle de liaison navigateur maintenu ; nettoyage planifié. |
| Moyenne | Absence de routeur commun pour la déconnexion du storefront. | Interception des liens WordPress et de l’endpoint WooCommerce dans le navigateur ; demande d’un nonce frais puis POST ; appel à `wp_logout()` ; retour à l’accueil. |
| Moyenne | Liens de déconnexion expirés pouvant ouvrir la confirmation WordPress. | Récupération par nonce frais lors du clic. En accès direct sans nonce valide : confirmation Delicat dédiée, sans déconnexion automatique. Un nonce absent ou périmé n’autorise jamais une déconnexion. |
| Moyenne | Certaines destinations natives, WooCommerce ou issues d’un filtre social pouvaient rester des pages d’authentification/admin/déconnexion. | Validation finale commune : même origine, destination sûre, rejet des chemins d’authentification, des liens de déconnexion et des callbacks. Les accès dashboard intentionnels des membres du personnel restent disponibles. |
| Moyenne | Erreur de formulaire WordPress pouvant laisser un client sur wp-login.php. | Échec de connexion standard d’un client : retour à l’accueil avec ouverture du popup et message générique. Les erreurs des comptes du personnel et les protocoles spéciaux ne sont pas détournés. |
| Moyenne | Le fragment AJAX du popup était enregistré mais absent de l’allowlist du pare-feu. | Ajout de l’action exacte ; maintien de sa vérification de nonce lié au navigateur. Corrige le repli lazy lorsque ce mode est activé. |
| Moyenne | Soumissions concurrentes possibles, y compris lors du renouvellement des nonces. | Une requête de nonce à la fois, verrouillage des soumissions/onglets et coordination mot de passe–Passkey. Renouvellement après bad_nonce limité à une seule nouvelle tentative. |
| Moyenne | Timeout traité comme une annulation silencieuse ; formulaire bloqué après échec d’initialisation. | Message français et bouton de nouvelle tentative ; délai maximum de la requête incluant la lecture du corps ; aucune relance automatique d’un POST d’authentification ayant simplement expiré. |
| Moyenne | Retour à l’onglet Connexion après demande 2FA : champ requis pouvant rester caché. | Conservation de l’étape 2FA visible ; effacement et réinitialisation à la fermeture du popup. |
| Basse | Fermetures/réouvertures rapides et réponses tardives pouvant perturber les nonces. | Isolation des générations de requêtes ; annulation des requêtes de présentation ; fermeture différée pendant une vérification d’identité en cours. |
| Basse | UI haute, ombres multiples et animations de propriétés coûteuses. | Carte compacte, champs de 48px, contrôles tactiles, police système, suppression du flou, ombres simples, animation de 160ms. |
| Basse | Focus automatique sur mobile provoquant l’ouverture immédiate du clavier. | Focus initial sur le dialogue pour les écrans tactiles ; adaptation à VisualViewport ; défilement interne ; focus clavier et réduction des animations maintenus. |
| Basse | Pages restaurées depuis l’historique et autres onglets pouvant présenter l’ancien état de session. | Rechargement au retour bfcache, notification locale entre onglets et signal d’invalidation des documents au service worker Builder compatible. |
| Basse | Le mot de passe pouvait rester dans le DOM à la fermeture. | Effacement des champs mot de passe et 2FA, y compris lorsqu’un mot de passe avait été révélé. |
| Basse | Récupération de mot de passe depuis le popup pointait sur WordPress. | Utilisation de l’endpoint WooCommerce lost-password quand WooCommerce est disponible. |

## Protections conservées et renforcées

Les nouvelles actions de déconnexion exigent POST, refusent les origines explicitement étrangères et vérifient le nonce de session avant `wp_logout()`. Une demande déjà déconnectée est idempotente. Les réponses de session ne sont pas mises en cache et ne renvoient aucun profil client.

La déconnexion standard WordPress détruit la session courante et ses cookies tout en émettant le hook natif utilisé par les intégrations. Référence : [WordPress — wp_logout](https://developer.wordpress.org/reference/functions/wp_logout/). Les destinations de sortie utilisent aussi le filtre prévu par WordPress : [logout_redirect](https://developer.wordpress.org/reference/hooks/logout_redirect/).

La vérification Google signée, les contrôles de navigateur/nonce, la protection des associations de comptes privilégiés, les facteurs TOTP, les codes de récupération, la validation WebAuthn et les politiques de compte demeurent en place. Une erreur OAuth ne doit pas être « réparée » en désactivant ces contrôles.

L’encryption existante des secrets via sodium ou AES-GCM est conservée. Les mots de passe restent gérés par WordPress. L’encryption ne remplace ni les nonces, ni les contrôles d’accès, ni HTTPS. Les secrets et mots de passe ne sont pas stockés dans localStorage par les nouveaux scripts ; seul un horodatage de changement de session y est écrit.

## Vérifications réalisées

- Analyse syntaxique des 67 fichiers PHP avec grammaire PHP 7.4 ; contrôle syntaxique des 13 fichiers JavaScript.
- Exécution PHP 7.4 et 8.3 : 32 assertions sur le routeur de session/redirection et 10 assertions sur l’état OAuth pour chaque version. Fonctions WordPress simulées dans ce harnais, pas de base WordPress de production.
- Cas serveur : destinations externes, changement de protocole/port, identifiants dans l’URL, wp-admin/wp-login, logout, fallback dangereux, POST obligatoire, origine étrangère, absence de nonce, nonce périmé, nonce valide, déconnexion répétée et maintien de l’accès du personnel.
- Cas OAuth : format, cookie manquant, mauvais navigateur, consommation correcte, rejeu d’un payload précédemment lu et expiration du marqueur.
- Rendu de la vraie méthode PHP du popup avec fournisseurs simulés ; inspection visuelle en Chromium à 320×568, 390×844 et 1280×800.
- Tests navigateur avec réponses AJAX simulées : absence de débordement horizontal, double soumission, étape 2FA après changement d’onglet, effacement des secrets, renouvellement bad_nonce limité, timeout explicite puis récupération, fermeture/réouverture rapide, lien logout périmé et retour à l’accueil.
- Aucune erreur JavaScript dans les scénarios navigateur testés. Vérification de l’intégrité du ZIP et reconstruction du manifeste SHA-256.

Les essais navigateur ne mesurent pas les performances d’un téléphone physique peu puissant ni la durée de réponse de Hostinger. Les contrôles de mot de passe, Google, WebAuthn, emails et WooCommerce en production restent à valider avec une installation réelle et des comptes de test. Le signal d’invalidation du cache ne garantit pas que toutes les versions de service worker ou toutes les règles CDN le respectent.

## Installation et vérification sur votre site

1. Sauvegarder les fichiers du site et la base de données. Tester d’abord sur une copie de staging avec les mêmes extensions et règles de cache.
2. Dans Extensions → Ajouter → Téléverser, charger le ZIP 6.9.18 et remplacer Delicat Identity Pro. Il s’agit d’une mise à jour du même plugin, pas d’un second moteur à activer en parallèle. Conserver les réglages existants.
3. Purger les caches LiteSpeed et Cloudflare, puis recharger le site. Mettre à jour le cache PWA/Builder s’il conserve encore les anciens fichiers.
4. Dans une fenêtre privée, tester : ouvrir le popup, mauvais mot de passe, bon mot de passe, retour au wallet, déconnexion depuis le menu puis reconnexion. Faire au moins trois cycles. Tester également la déconnexion depuis Mon compte et un ancien lien de logout.
5. Tester Google, Passkey, compte protégé par 2FA, inscription avec vérification et récupération de mot de passe. Confirmer que les callbacks Google correspondent toujours au domaine canonique du site.
6. Vérifier le panier, le wallet, les informations de compte et un second onglet après chaque changement de session. Vérifier aussi Retour dans Safari/Android et l’interface lorsque le clavier est ouvert.
7. Si une régression apparaît, réinstaller la sauvegarde 6.9.17 et purger les caches. Les tables, clés d’identité et réglages existants ne sont pas migrés ou supprimés par cette mise à jour ; le nouveau marqueur OAuth est temporaire.

L’accès administratif direct à wp-login.php et les procédures natives de récupération ne sont pas supprimés. Ne pas installer de règle qui bloque indistinctement wp-admin/admin-ajax.php ou tous les callbacks d’authentification.

## Recommandations prioritaires

- Exclure du cache public les sessions connectées, les pages compte/wallet/checkout, les actions admin-ajax, les routes REST Identity et les réponses/callbacks d’authentification. Respecter `no-store` et les marqueurs `dip_auth_sync`, `dip_auth_error`, `dip_verified`, `dip_logout_confirm` et `dip_identity_*`. Une règle CDN « cache everything » ignorant ces exclusions ne peut pas être corrigée uniquement par ce ZIP.
- Exclure `identity-modal-v4.js`, `identity-loader.js`, `session-router.js` et `passkeys.js` du retardement JS/Rocket Loader. Les attributs de protection sont ajoutés, mais les réglages du cache doivent aussi les respecter.
- Désactiver les anciens snippets indépendants de connexion/inscription lorsque leur remplacement par Identity est confirmé. Un ancien moteur peut encore ajouter des liens, erreurs ou modals concurrents depuis Builder ou Code Snippets.
- Garder 2FA activé pour les comptes administratifs et conserver les codes de récupération. Ne pas désactiver les contrôles d’association Google pour contourner une erreur.
- Les compteurs de tentatives existants utilisent des transients avec lecture/incrémentation/écriture : ce n’est pas un compteur transactionnel strict face à des requêtes massivement parallèles. Compléter par une limitation de débit au niveau serveur/CDN ; un durcissement transactionnel global mérite des essais de charge distincts.
- Ne faire confiance aux en-têtes Cloudflare que si l’origine est réellement restreinte aux proxys de confiance. Les constantes de confiance existantes ne vérifient pas à elles seules le réseau source.
- Vérifier WP-Cron : il assure notamment le nettoyage des nouveaux marqueurs OAuth après expiration. Sur un site peu visité, un cron système fiable est préférable.
- Le défaut GET précédent justifie une vérification des journaux si des soumissions sans JavaScript ont réellement eu lieu. Si des mots de passe y apparaissent, restreindre/purger ces copies selon votre politique de conservation et réinitialiser les mots de passe concernés.

Ne pas annoncer « tous les bugs corrigés », « 100 % sécurisé » ou un pourcentage d’accélération sans essais de production complémentaires. Cette version apporte les corrections vérifiées ci-dessus et documente leurs limites.
