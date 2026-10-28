# 6.10.0 — Reconstruction du popup

## Ce qui a été remplacé

L’ancien moteur du popup, ses correctifs tactiles et son loader différé ont été supprimés du paquet : identity-modal-v5.js, identity-modal-v5.css et identity-loader.js. Les anciennes fixtures de tests de ce moteur ont aussi été remplacées.

Le nouveau moteur comprend :
- auth-shell.js : ouverture/fermeture du dialog, isolation du fond, chargement du formulaire, restauration des interactions.
- auth-frame.js : onglets et formulaires dans un document distinct de la boutique.
- auth-frame.css : styles du formulaire, sans dépendance au thème ou aux styles du menu.

Le formulaire reste dans un iframe de même origine, créé en mémoire dans la page. Aucun service externe ni hébergement supplémentaire. Le balisage original est livré dans un template inerte; ses styles ne peuvent pas affecter le site avant montage.

Cette séparation empêche les événements du formulaire de remonter aux écouteurs du menu dans le document parent. Elle isole aussi les styles des deux interfaces. Ce n’est pas une frontière de sécurité entre scripts de même origine : elle sert à éviter les conflits d’interface.

## Parcours

- Google : lien navigateur standard target=_top, vers le même démarrage OAuth serveur. Aucun pointerup spécial, aucun clic synthétique, aucune attente de nonce mot de passe.
- Connexion/inscription : mêmes actions AJAX WordPress, nonces liés au navigateur, renouvellement et un seul réessai en cas de nonce expiré.
- 2FA, vérification email et renvoi : conservés.
- Passkeys : script existant chargé dans le formulaire; succès redirigé vers le site parent. Bouton indisponible tant que le script n’est pas prêt.
- Ouverture : dialog natif; fond inert; nouveaux widgets ajoutés au body isolés. Fermeture : restauration des états antérieurs et du défilement.
- Les scripts du formulaire sont préparés dès le chargement du shell. Un message apparaît si l’utilisateur ouvre plus vite que le chargement; une erreur bornée à 12 s permet un nouvel essai.

Les comptes, mots de passe, réglages OAuth, commandes et soldes ne sont pas modifiés par cette reconstruction. La validation serveur OAuth/PKCE/nonce/2FA est conservée.

## Vérification exécutée

- 13 nouveaux tests du formulaire : premier affichage, Google natif pendant nonce bloqué, onglets, nonce/redirect de connexion, nonce expiré, identifiants incorrects, 2FA, vérification d’inscription, concurrence, timeout, réponse périmée après fermeture, redirection externe refusée, passkey occupée.
- 9 nouveaux tests du shell : template isolé, dialog, ouverture pendant chargement, inert/pointer restoration, état inert préexistant, nouveau widget, fermeture pendant authentification, timeout et réessai, fermer/rouvrir.
- Syntaxe Node sur les 12 JS applicatifs, références d’assets et structure ZIP.

Commandes : node tests/frame.cjs et node tests/shell.cjs depuis le dossier du plugin.

Ces tests utilisent des fixtures DOM Node VM; ils ne valident pas le rendu/hit-testing sur iPhone. PHP/WordPress/WooCommerce réels, contraintes CSP/iframes du site et Google OAuth réel n’ont pas été testés dans cette reconstruction. L’environnement n’a pas d’interpréteur PHP. Aucun test de la précédente livraison n’est compté comme un test de ce nouveau moteur. La cause exacte de l’incident sur le téléphone n’a pas été reproduite; l’isolation remplace les précédentes hypothèses de superposition.

## Mise à jour

1. Conserver une sauvegarde et remplacer le plugin existant par delicat-identity-pro-v6.10.0.zip (ne pas désinstaller pour éviter les routines de désinstallation).
2. Purger les pages LiteSpeed et le CDN utilisé, puis fermer les anciens onglets. Un ancien HTML en cache peut encore référencer les scripts supprimés.
3. En fenêtre privée : ouvrir le popup et toucher Google directement sans passer par Inscription. Tester aussi Connexion, Inscription, fermeture/réouverture, clavier affiché et défilement.
4. Tester 2FA/passkeys si activés, et confirmer la conservation du même compte WooCommerce/portefeuille après connexion.

En cas de refus de chargement du frame/script par CSP, le shell présente une erreur; il faut inspecter la règle serveur correspondante, pas réduire la sécurité d’authentification. Le Google login complet et le correctif sur l’iPhone concerné restent à valider après installation.
