=== Delicat Identity Pro ===
Contributors: delicatstore
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 6.18.0
License: GPLv2 or later

Authentification et gestion sécurisée des comptes WordPress / WooCommerce.

== Version 6.18.0 — Audit sécurité, vitesse et nettoyage ==
Audit complet du 14 novembre 2026. Aucune donnée, aucun réglage, aucune route de l’application ni aucun
nom d’action ne change ; les comptes, commandes et portefeuilles sont conservés.

(1) Clients — confirmation des retraits et échanges sur les anciens téléphones Android
* Sur un téléphone dont le navigateur intégré (Facebook, TikTok, application Delicat) date d’avant 2020,
  le lien « Confirmer mon identité et la transaction » n’apparaissait pas (une boîte blanche vide s’affichait) :
  le formulaire de retrait ou d’échange restait bloqué sans explication. Le lien s’affiche maintenant sur tous les téléphones.
* Ce script n’est plus chargé en priorité : il ne retarde plus l’affichage des pages du client connecté.

(2) Clients — pages du compte plus fluides
* Le suivi du thème clair/sombre ne recalcule plus la page à chaque petit changement fait par le site
  (menu, défilement bloqué, carrousel) : au plus une fois par image affichée. Défilement plus régulier sur
  les téléphones modestes.
* Écran de connexion du Visual Builder ([delicat_auth_panel]) : quand le flou de la carte est réglé sur 0,
  le navigateur ne prépare plus d’effet de flou invisible (coûteux pour la carte graphique des petits
  téléphones). Avec un flou choisi, rien ne change.
* Anciens navigateurs Android (avant 2021) : les icônes des statistiques du compte, l’anneau du score de
  sécurité, les icônes des Passkeys et le contour de la carte Portefeuille gardent leur taille et leur place
  (ils disparaissaient ou se déformaient). Rendu identique sur les navigateurs récents.

(3) Sécurité
* Connexion Google / Microsoft : deux retours simultanés pour la même identité (double toucher, site et
  application en même temps) ne peuvent plus être traités ensemble. Le verrou passe d’un transient
  (lecture puis écriture, contournable avec un cache objet) au verrou atomique de la base déjà utilisé par la
  2FA et les codes e-mail (DIP_Lockout::mutex_acquire, libéré automatiquement après 30 secondes).
* Audit des routes REST publiques (appairage, Google mobile, renouvellement, connexion rapide, Passkeys),
  des actions AJAX et admin-post (nonce, droits, méthode POST), des redirections, des requêtes SQL et des
  sorties HTML : aucune autre faille exploitable trouvée. Points restants listés plus bas (Exploitation).

(4) Technique
* DIP_Account_Control::upgrade() enregistre les réglages principaux (dglp_settings) en autoload, comme les
  autres modules ; il les écrivait sans autoload (réparé à la requête suivante, au prix de requêtes en plus).
* assets/financial-confirmation.js : plus d’Element.replaceChildren() (Chrome/WebView < 86) ; chargé en
  « defer ».
* assets/account-theme.js : MutationObserver regroupé par requestAnimationFrame.
* CSS (customer-dashboard, my-account-modern, passkeys, visual-builder-front, style de la fenêtre de
  connexion dans auth-shell.js) : « inset » remplacé par top/right/bottom/left (WebView < 87), sans
  changement visuel.
* Fichiers de notes par version retirés (CONNEXION-6.17.7-9.12.3.txt, SECURITY-REVIEW-6.15.0.md) : ce qui
  sert encore est repris dans la section « Exploitation » ci-dessous.
* Version 6.18.0 (en-tête, DIP_VERSION, marqueur data-dip-engine). Vérification : php -l sur tous les
  fichiers PHP, node --check sur tous les scripts.

(5) Installation
* Extensions → Ajouter → Téléverser une extension → delicat-identity-pro-6.18.0.zip → « Remplacer la
  version actuelle ». Puis LiteSpeed Cache → Boîte à outils → Purger tout, et purger Cloudflare.

== Version 6.17.7 — Fiabilité de la connexion ==
* Réessai immédiat après un chargement interrompu ; abandon des requêtes bloquées et des réponses trop tardives.
* Réutilisation des nonces sécurisés du premier chargement ; récupération après retour arrière/avant.
* Configuration tardive et liens de connexion absolus pris en charge ; solution de repli vers la page Compte.
* Étape 2FA visible et focalisée ; formulaires réactivés après une erreur réseau.
* Protection des dépendances de connexion contre le retard JavaScript des optimisateurs.
* À installer avec Delicat Builder V9 9.12.3 pour la correction des gestes tactiles du menu.

== Version 6.17.6 ==
Audit de sécurité du 7 novembre 2026 : le formulaire d’inscription est vérifié sur le serveur.

* Formulaire [delicat_registration_form] : chaque champ facultatif est contrôlé par le serveur avant la création
  du compte, et plus seulement par la page. Prénom et nom : 60 caractères au plus, balises retirées. Téléphone :
  chiffres avec un « + » au début possible, 8 à 15 chiffres (les espaces, points, tirets et parenthèses sont
  retirés). Pays : 2 lettres connues de WooCommerce (vide = pays par défaut). Code de parrainage : 3 à 20 lettres
  ou chiffres (espaces et tirets retirés). Offres : oui ou non. Conditions : la case doit être cochée.
* Un champ refusé affiche le message habituel « Vérifiez les champs… » (ou « Vous devez accepter les
  conditions »), et aucun compte n’est créé. La réponse est la même que l’adresse ait déjà un compte ou non.
* Les champs du formulaire indiquent leur longueur maximale (présentation seulement ; la règle du serveur compte).
* Installation : Extensions → Ajouter → Téléverser une extension → choisir delicat-identity-pro-6.17.6.zip →
  « Remplacer la version actuelle ». Les données, comptes et réglages sont conservés.

== Version 6.17.5 ==
Corrections de l’audit du 5 novembre 2026 (sécurité d’abord). Références IDN-01 à IDN-15.

(1) Clients — inscription et confirmation de l’adresse e-mail (IDN-01, IDN-13)
* Une personne ne peut plus créer un compte avec l’adresse d’un client puis garder son mot de passe quand le vrai
  propriétaire confirme l’adresse. Le lien de confirmation ouvert dans le navigateur qui a créé le compte garde le
  mot de passe choisi (rien ne change pour le client). Ouvert sur un autre appareil ou navigateur, il confirme
  l’adresse, l’ancien mot de passe ne fonctionne plus, et la page affiche « Adresse confirmée. Pour vous connecter,
  recevez un lien de connexion par e-mail » : le client reçoit le lien dans sa boîte, se connecte, puis la page
  « Mot de passe » lui demande d’en choisir un. Le lien de confirmation n’ouvre jamais de session à lui seul (un
  escroc ne peut pas connecter quelqu’un à son propre compte). Exemple : inscription dans l’application, lien ouvert
  dans Chrome → lien de connexion, nouveau mot de passe, utilisé ensuite partout.
* Une seule règle « adresse prouvée » pour le code e-mail, le lien magique, le lien de confirmation et Google.

(2) Clients — connexion depuis les réseaux Digicel / Natcom (IDN-02, IDN-11, IDN-12)
* Les erreurs de mot de passe sont comptées par adresse Internet + téléphone : les fautes d’un client ne bloquent
  plus tous les clients du même réseau. Fenêtres d’une heure au lieu de 24 heures. Un plafond plus large par
  adresse reste en place contre les robots.
* Quelqu’un qui connaît l’e-mail d’un client ne peut plus le bloquer : le téléphone habituel du client (déjà
  connecté une fois) n’est pas concerné par le blocage du compte.
* Lien de connexion ou code demandé trop tôt : message visible « Patientez X min avant de redemander un lien »
  au lieu de « vérifiez votre boîte » sans e-mail.
* Une même adresse e-mail reçoit au plus 6 codes ou liens par heure, quelle que soit la connexion qui les demande.
  L’équipe reçoit un seul e-mail récapitulatif (toutes les 12 heures) au lieu d’un e-mail par demande refusée.

(3) Clients — mot de passe oublié et déconnexion des autres appareils (IDN-07, IDN-10)
* Après « Mot de passe oublié » ou « Déconnecter les autres sessions », les passkeys et les comptes Google ou
  Microsoft ajoutés ces 7 derniers jours sont retirés (les plus anciens restent), et le client reçoit un e-mail qui
  les liste avec un bouton « Vérifier mes accès ». Seule la passkey ou le compte Google/Microsoft qui a ouvert (ou
  reconfirmé) la session en cours est gardé, même s’il est récent ; chaque session note désormais ce moyen.
* « Mot de passe oublié » répond toujours « Si un compte existe, un e-mail a été envoyé », et une inscription avec
  une adresse déjà utilisée reçoit la même réponse qu’une nouvelle inscription (le titulaire de l’adresse reçoit un
  e-mail « Vous avez déjà un compte »). Le formulaire ne révèle plus qui a un compte.

(4) Équipe — vérification en deux étapes (IDN-04)
* La 2FA est obligatoire pour les administrateurs, les gestionnaires de boutique et tout compte qui peut modifier
  des commandes ou créditer les portefeuilles TeraWallet. À la prochaine visite de l’administration, ces comptes
  arrivent sur la page « Protection Identity » pour la configurer (aucun compte n’est bloqué hors de cette page).
* Première activation de la 2FA (équipe et clients) : un code est d’abord envoyé à l’adresse e-mail du compte. La
  clé Authenticator n’apparaît qu’après ce code, pour qu’un voleur de mot de passe ne puisse pas activer sa propre 2FA.
* Attention : l’application Android du personnel de Delicat Assistant se connecte par mot de passe seul ; un compte
  avec 2FA ne pourra plus s’y connecter tant que l’Assistant ne demande pas le code.

(5) Application Delicat (IDN-05, IDN-14)
* Connexion d’un navigateur par QR code : la session du navigateur dure 24 heures au lieu de 14 jours, l’application
  reçoit un avertissement quand le navigateur n’est pas sur le même réseau que le téléphone, et le client reçoit un
  e-mail avec un bouton « Ce n’est pas moi » qui déconnecte ce navigateur.
* Un ancien jeton de session de l’application réutilisé (copié) ferme la session de l’application concernée.
* Connexion Google de l’application : un « nonce » est vérifié quand l’application en envoie un (les versions
  actuelles continuent de fonctionner sans).
* Faire taper le code dans l’application (au lieu de comparer deux codes) demande une mise à jour de l’application.

(6) Boutique séparée « delicat-store-ultra-fast » (IDN-06)
* Plus aucune adresse n’est autorisée par défaut pour la connexion Google de la boutique séparée, et la protection
  PKCE est obligatoire. Une notice d’administration indique l’adresse active ou que la connexion est désactivée.

(7) Pages plus légères (IDN-09)
* La fenêtre de connexion (environ 31 Ko) n’est plus incluse dans chaque page : elle se charge au premier toucher du
  bouton Connexion. Accueil : 143,6 Ko → 112,3 Ko de HTML ; fiche produit : 166,9 Ko → 135,6 Ko. login.css n’est
  plus chargé sur les pages catalogue et account-theme.js ne bloque plus l’affichage. Si le script n’est pas encore
  chargé, un toucher sur Connexion ouvre la page Mon compte.

(8) Confidentialité et Centre de sécurité (IDN-08, IDN-15)
* Outils › Exporter les données personnelles : Delicat Identity exporte compte, déclaration d’âge, comptes reliés,
  passkeys, appareils, sessions de l’application et historique de sécurité. Outils › Effacer : sessions de
  l’application, connexion rapide (empreinte/visage), historique de sécurité, appareils et champs du profil
  (déclaration d’âge incluse). Les moyens de connexion restent pour que le compte fonctionne.
* « Appareil de confiance » ne rapporte plus de points au score de sécurité : c’est un repère qui ne change rien à
  la connexion (le texte le dit). Les points vont à la Passkey, à la 2FA et à l’e-mail vérifié (toujours /100).

(9) Technique
* L’en-tête Cloudflare (CF-Connecting-IP, CF-IPCountry) n’est cru que si la connexion vient réellement de
  Cloudflare, avec ou sans les constantes DIP_TRUST_CLOUDFLARE_* (IDN-03). DIP_TRUST_CLOUDFLARE_HEADERS = false
  le désactive toujours ; le filtre dip_native_auth_client_ip reste prioritaire.
  Site derrière un tunnel ou un proxy local (cloudflared, nginx sur 127.0.0.1) : l’adresse vue est celle du
  proxy pour tout le monde ; indiquez la vraie adresse avec le filtre dip_native_auth_client_ip, sinon les
  limites de connexion s’appliquent à tous les clients à la fois.
* Revue 6.17.5 : un code ou un lien de connexion par e-mail réinitialise les accès d’un compte qui attend sa
  confirmation, ou d’un compte non confirmé créé depuis moins de 30 jours ; jamais le mot de passe d’un ancien
  client ; le plafond de codes par adresse e-mail est
  séparé pour un téléphone déjà connu du compte, pour qu’un inconnu ne puisse pas bloquer son propriétaire ;
  l’effacement des données personnelles garde l’historique de sécurité des comptes du personnel.
* Nouvelles classes : DIP_Email_Proof, DIP_Recent_Credentials, DIP_Privacy. Nouvelle route publique
  POST delicat-identity/v1/mobile/google/nonce. Filtres : dip_two_factor_required_capabilities,
  dip_native_modal_inline.
* Base de données : deux colonnes ajoutées à {préfixe}dip_mobile_tokens (previous_refresh_hash, rotated_at),
  une seule fois, sans rien supprimer.

(10) Réglages modifiés par la mise à jour
* Connexion Google de la boutique séparée : désactivée tant que la page Réglages › Identity Providers n’a pas été
  enregistrée. Pour la rétablir : cochez « Activer la connexion Google de la boutique », saisissez
  https://delicat-store-ultra-fast.louismarkenricky.chatgpt.site dans « Origines autorisées », puis enregistrez.
* « Inscriptions maximum par heure » (3 par défaut) s’applique désormais à chaque téléphone ; une même adresse
  Internet est limitée à 10 fois ce nombre (au moins 30 par heure).
* 2FA obligatoire étendue aux gestionnaires de boutique et aux comptes commandes / portefeuilles (filtre
  dip_two_factor_required_capabilities pour ajuster la liste).

(11) Installation
* Extensions → Ajouter → Téléverser une extension → choisir delicat-identity-pro-6.17.5.zip → « Remplacer la
  version actuelle ». Les données, comptes et réglages sont conservés.

== Version 6.17.4 ==
* Accueil du compte : « Programme Revendeur » apparaît dans « Mes services » avec une icône de boutique
  (page Mon compte › Programme Revendeur de Delicat Resellers 1.1).
* Les pages ajoutées par d'autres extensions reçoivent une vraie icône au lieu d'un point, d'après leur nom :
  « Mes échanges » (flèches d'échange), « API Revendeur » (code), « Mes données » (bouclier), etc.

== Version 6.17.3 ==
* Mode sombre, espace client : une ligne touchée sur iPhone ou Android (« Commandes récentes », menus du compte)
  ne reste plus grise après le toucher ; le survol ne s'applique qu'avec une souris. Couleur de survol en mode
  sombre alignée sur le bleu nuit du site au lieu du gris.
* À installer avec Delicat Builder 9.7.3 (qui retire les cadres en double du mode sombre).

== Version 6.17.2 ==
* Mode sombre : les boutons de l’espace client (« Voir » dans les commandes, actions du compte, menu actif) gardent
  un texte blanc lisible ; ils utilisaient le bleu clair du mode sombre (contraste 2,5:1). Mode clair inchangé.
* Écran de connexion (Visual Builder) : fond uni bleu nuit d’origine rétabli.

== Version 6.17.1 ==
* Correctif : les contrôles de santé, de sécurité et des fournisseurs (administration) et le résumé de
  sécurité du client distinguent de nouveau « OK », « À vérifier » et « Échec » ; la 6.17.0 avait retiré
  les emojis et laissé certains états vides. Icônes Material de forme et de couleur différentes, avec le
  mot lu par les lecteurs d’écran (DIP_Status_Mark).

== Version 6.17.0 ==

* Déclaration d’âge : juste après la création du compte (et une seule fois pour chaque client existant à sa prochaine visite), une fenêtre demande « Avez-vous 18 ans ou plus ? ». Seule la réponse et sa date sont enregistrées (aucune date de naissance). Une personne de moins de 18 ans voit que ses achats doivent se faire avec l’accord d’un parent ou d’un tuteur légal, avec le lien vers la politique Protection des mineurs, puis continue. Jamais pendant le paiement ; jamais pour l’équipe.
* Fiche utilisateur (administration) : réponse et date visibles ; case « Redemander au client à sa prochaine visite ». Chaque réponse et chaque remise à zéro sont inscrites au journal d’audit.
* Sécurité : l’enregistrement passe par le pare-feu AJAX d’Identity (client connecté, jeton nonce, réponse unique).
* Plus aucun emoji dans les écrans et messages ; icônes Material du Builder.

== Version 6.16.0 ==

* Delicat Design System 2.0 : les couleurs du compte, de la connexion, des Passkeys et du tableau de bord suivent les jetons de design du Builder 9.6.0 (bleu #2563EB, bleu nuit du logo, mêmes textes et contours en clair et en sombre). Sans le Builder, les anciennes couleurs restent en secours.
* Plus de violet ni de rose dans les écrans du compte ; police unique Inter.

== Version 6.15.4 ==

* Fenêtre de connexion plus rapide pour les visiteurs : les méthodes de connexion (code e-mail, lien magique, inscription) sont chargées avec les autres réglages de démarrage au lieu d’une requête à part sur chaque page non mise en cache.
* Application Delicat : l’usage d’une session mobile (« vu pour la dernière fois ») n’est plus écrit dans la base à chaque appel de l’application, au plus une fois par minute.
* Sessions et appareils : le lien vers Google Play est vérifié par sa forme (https, domaine) au lieu d’une résolution DNS à chaque affichage de la page.
* Aucun changement de sécurité, de connexion, de 2FA ni de Passkeys.

== Version 6.15.3 ==

* Lisibilité (audit Lighthouse) : textes gris, liens bleus et pastilles d’état du compte assez contrastés pour tous (4,5:1 minimum ; accueil du compte et liste des commandes à 100/100 en accessibilité). Bleu des boutons légèrement plus soutenu (#0b6bdc), sombre inchangé.
* Lien du profil : nom lu par les lecteurs d’écran identique au texte affiché.
* Pages du compte plus rapides à s’afficher : scripts de session, centre de sécurité, Passkeys et compte chargés en « defer » (ils ne retardent plus le premier affichage).
* Robustesse : une adresse /my-account/view-order/ avec le numéro d’un remboursement n’entraîne plus d’erreur fatale (contrôle WC_Order).

== Version 6.15.2 ==

* Accueil du compte comme une application : sous le profil, deux cartes Portefeuille (solde, « Recharger », œil pour masquer le solde) et Récompenses (programme de fidélité ou de parrainage, points WooCommerce Points and Rewards, sinon le nombre de commandes), puis les trois dernières commandes avec leur état, et « Mes favoris » (produits enregistrés avec le cœur). La page reste privée : jamais mise en cache, données du seul client connecté, préférence « Masquer le solde » respectée côté serveur.
* États de commande avec pictogrammes (style Material) et texte, jamais la couleur seule : paiement en attente, en vérification, en cours, livrée, annulée, remboursée, échouée. Mêmes libellés que la page de commande du Builder quand il est actif ; aussi dans la colonne « État » de la liste des commandes.
* Détail d’une commande : barre « Détails de la commande » (avec la page de commande du Builder 9.5.1, dont la première carte affiche « Commande #… »), sinon « Commande #… » ; retour vers la liste des commandes.
* Navigation instantanée entre les rubriques du compte (Builder 9.5.1) : le masquage du solde et la recherche de commandes fonctionnent aussi après un changement de page sans rechargement.
* Fournisseurs de récompenses : filtre dip_account_rewards (label, value, hint, url) ; favoris : filtre dip_account_favorites.

== Version 6.15.1 ==

* Pictogrammes Face ID et empreinte conservés dans le bouton compact. Bouclier « Sécurisé par Delicat Identity » dans le pied de formulaire.

== Protections et interface 6.15 ==

* Connexion compacte : suppression du grand bloc d’introduction Passkey, bouton direct et explication de confidentialité à la demande. Choix e-mail plus courts et panneaux alternatifs exclusifs. Clair/sombre, cibles tactiles de 44 px minimum, champs de 16 px, adaptation à la hauteur disponible et au clavier. Pas de flou ni d’animation de mise en page.
* 2FA obligatoire pour les administrateurs ayant manage_options. Administration et API authentifiées bloquées avant l’enrôlement ; la page de configuration reste accessible. Conservation des codes de récupération hors ligne indispensable. La désactivation personnelle de la 2FA administrateur est interdite.
* Nouveau menu WordPress Protection Identity : configuration 2FA, confirmation d’identité, demandes de changement d’e-mail, test réel de réception par code et registre des vérifications de mise en service/sauvegardes.
* Changement d’e-mail client réservé au support administrateur : identité documentée, code envoyé à la nouvelle adresse, validation avec 2FA et confirmation récente, journal d’audit, avertissement à l’ancienne adresse et révocation des sessions. Les modifications directes via profil et API sont bloquées. Les demandes sont chiffrées au repos.
* Protection des demandes des formulaires Delicat Exchange Studio 2.0.0 : identité à confirmer à nouveau, affichage du montant et du bénéficiaire, approbation valable 10 minutes pour cette session et ces données uniquement, usage unique. Toute modification de montant, taux, frais ou bénéficiaire exige une nouvelle validation.
* Page client Sessions et connexions : navigateurs actifs, date de connexion et d’expiration, appareil courant, révocation Web/App. Alerte après révocation et activation initiale des alertes de nouvel appareil.
* Préservation des ajouts 6.14 : lien magique, Google et Passkey ; bannissement permanent/temporisé ; photo sûre avec suppression ; rubrique Mot de passe séparée ; e-mail client en lecture seule ; conteneur décoratif secondaire retiré du tableau de bord.

== Installation ==

1. Sauvegarder les fichiers, la base et les clés/sels WordPress ; installer d’abord sur une copie de test.
2. Téléverser ce ZIP et remplacer l’extension existante, sans supprimer les données.
3. Ouvrir Protection Identity, configurer Authenticator et conserver les codes de récupération hors ligne. Chaque administrateur doit faire cette étape.
4. Purger LiteSpeed et Cloudflare. Les nouveaux endpoints sont enregistrés à la mise à jour ; si 404, enregistrer Réglages > Permaliens.
5. Tester connexion, Google, lien magique, Passkey, profils, bannissements, sessions et transactions sur les appareils réels.

Pour un retrait/échange : envoyer le formulaire, ouvrir le lien de confirmation, confirmer son identité et les détails, puis revenir au formulaire d’origine et l’envoyer à nouveau sans modification. Aucune opération financière n’est envoyée automatiquement.

Pour modifier l’e-mail d’un client : Protection Identity > Demandes de changement d’e-mail. Ouvrir la demande avec le dossier d’identité du support, puis valider avec le code reçu à la nouvelle adresse. Le code de changement d’adresse est distinct de tout code de connexion : le support ne demande jamais un mot de passe, un code 2FA ni un code de connexion.

Pour suspendre un client : Utilisateurs > Modifier > Delicat — accès du client. Choisir Autorisé, Suspendu jusqu’à une date ou Banni définitivement. L’heure suit le fuseau WordPress.

HTTPS, OpenSSL, API REST et envoi d’e-mails fonctionnels requis pour les méthodes concernées. Les photos nécessitent GD ou Imagick. La liste des sessions Web utilise le gestionnaire WordPress par défaut. Les liens magiques et OAuth exigent un test de réception/retour sur votre domaine.

La sauvegarde externe et sa restauration réelle se configurent chez l’hébergeur ; le registre du plugin enregistre des déclarations d’administrateur. Aucun déploiement, essai sur iPhone/Android réel ni restauration Hostinger n’a été exécuté dans la préparation de ce ZIP.

== Exploitation ==

Après chaque mise à jour
* Garder une seule copie active de l’extension. Purger LiteSpeed Cache (Boîte à outils → Purger tout) et
  Cloudflare, fermer puis rouvrir l’application / la PWA, vérifier le numéro de version dans Extensions.
* Tester déconnecté et en navigation privée, sur iPhone (Safari) et Android (Chrome) : ouvrir le menu,
  toucher « Se connecter », fermer et rouvrir ; e-mail/mot de passe (une erreur puis la réussite, avec le
  bon portefeuille) ; Google (annuler puis réussir) ; Passkey (annuler puis autre méthode) ; compte avec
  2FA (code refusé puis code valide) ; retour arrière/avant après une connexion ; réseau lent ou coupé
  (le bouton « Réessayer » doit apparaître).
* Delicat Builder V9 9.12.3 ou plus récent corrige les gestes tactiles du menu qui ouvre la connexion.

Urgence et support
* Administrateur sans téléphone Authenticator : utiliser un code de récupération hors ligne. Sinon, depuis
  un accès serveur de confiance : wp delicat identity-2fa-reset <id-utilisateur> (sessions révoquées,
  nouvel enrôlement obligatoire). Passkeys : wp delicat identity-passkeys-reset <id-utilisateur>. Aucun
  paramètre public de contournement n’existe.
* Changement d’e-mail d’un client : le code envoyé à la nouvelle adresse expire après 24 heures, 5 essais
  au plus, 3 demandes par heure et par client. Il prouve seulement la possession de la nouvelle adresse :
  le dossier d’identité du support reste obligatoire.
* Le succès de wp_mail() veut dire que le transport a accepté le message, pas qu’il est arrivé : seul le
  code reçu lors du test de réception (Protection Identity) le prouve. SPF, DKIM et DMARC se vérifient chez
  le fournisseur d’envoi.
* Sauvegardes : fichiers, base de données ET clés/sels de wp-config.php. Sans les mêmes sels, les secrets
  chiffrés (OAuth, demandes d’e-mail, transactions en attente) ne peuvent pas être restaurés.
* Trustpilot n’est pas raccordé : les avis affichés par la boutique ne sont pas des avis Trustpilot.

Points connus, non modifiés (6.18.0)
* wp-login.php : après un mauvais mot de passe, un compte client est renvoyé vers la boutique alors qu’un
  compte de l’équipe reste sur wp-login.php. Cela révèle qu’un identifiant appartient à l’équipe ; WordPress
  révèle déjà, sur ce même écran, si un identifiant existe. Masquer la différence changerait le parcours de
  l’équipe : à décider, avec un renommage ou une protection de wp-login.php côté hébergeur.
* Quelques règles d’habillage du compte (titres, mode sombre) utilisent :is() et :has(), ignorés par les
  navigateurs Android d’avant 2021 : la page reste utilisable, avec les couleurs par défaut.
* La connexion Google de l’application accepte encore un jeton sans « nonce » (applications publiées
  avant 6.17.5). Rendre le nonce obligatoire demande que toutes les versions en circulation l’envoient.
