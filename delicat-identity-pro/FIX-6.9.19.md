# 6.9.19 — popup sans style et fluidité

Cette mise à jour répond aux captures montrant le formulaire affiché dans la page, sans sa présentation modale, et le menu au-dessus du formulaire. Elle inclut les corrections de session et de sécurité 6.9.18.

Corrections :

- Le CSS essentiel accompagne désormais le HTML du popup et ses fragments AJAX. L’ouverture ne dépend plus d’une requête séparée vers la feuille de style principale. Cela augmente légèrement le HTML mais supprime une dépendance réseau fragile.
- Le conteneur reste masqué sans CSS. Le script vérifie que le style est réellement appliqué avant d’afficher la fenêtre ; une erreur de chargement ne produit plus le formulaire brut montré dans la capture.
- Si un optimiseur retire le style intégré, un chargement de secours versionné est tenté. En cas d’échec, le popup reste masqué avec un message de nouvelle tentative.
- Le lazy loader ne considère plus une erreur ou expiration de chargement CSS comme un succès. Les URL des assets dynamiques portent la version pour éviter de réutiliser les fichiers d’une version antérieure.
- Le bouton `.dsb-account-login` est reconnu aussi par le moteur immédiat. Le signal de fermeture de menu `dsb:close-menu` est émis dans les deux modes.
- Le popup est remonté au niveau du body même lorsqu’il était imbriqué dans un parent transformé ou dans un menu. Le module Builder exact n’étant pas fourni dans ce tour, la réception effective du signal de fermeture par sa version installée reste à vérifier sur le site.
- Suppression du MutationObserver qui surveillait l’ensemble du document lorsqu’un ancien snippet était détecté. La suppression du modal ancien utilise les règles CSS ciblées et les vérifications ponctuelles à l’ouverture.
- Conservation du défilement interne, de l’adaptation clavier, des contrôles de requêtes et des protections d’authentification. Aucune réduction du coût de vérification des mots de passe ou désactivation de facteur de sécurité.

Vérification locale :

- Analyse syntaxique : 67 PHP, 13 JavaScript.
- Régression du popup à 320×568, 390×844 et 1280×800 ; double soumission, renouvellement de nonce, timeout/récupération, 2FA et cycle de déconnexion simulé : tests réussis.
- CSS externes bloqués, parent transformé, bouton Builder et CPU ralenti 6× : popup correctement présenté ; classe d’ouverture atteinte en environ 175 ms avant une réponse nonce simulée de 2 500 ms. Ce chiffre exclut la fin de l’animation et n’est pas une mesure sur un téléphone physique ni une garantie de vitesse du serveur.
- CSS intégré supprimé + externe bloqué : aucun formulaire brut affiché, message d’erreur et possibilité de réessayer.
- Réactivation de la feuille de style puis nouvelle tentative : popup restauré.
- Tests de routeur/OAuth : 42 assertions exécutées sous PHP 7.4 puis PHP 8.3 avec fonctions WordPress simulées ; réussis.

Installation : remplacer le plugin existant par ce ZIP, puis purger LiteSpeed, Cloudflare et les anciens documents PWA. Tester d’abord sur staging. Les caches ne doivent pas conserver l’ancien HTML du popup ni différer identity-modal-v4.js, identity-loader.js, session-router.js ou passkeys.js. Vérifier l’ouverture depuis le menu, la fermeture, le clavier et plusieurs cycles connexion/déconnexion sur les appareils concernés.

La connexion réelle dépend encore du réseau, du serveur WordPress, des autres extensions et des fournisseurs Google/Passkey. Aucun test connecté ni déploiement en production n’a été effectué. Les autres recommandations de l’audit 6.9.18 restent applicables ; ce correctif ne constitue pas une garantie que tous les bugs du site sont éliminés.
