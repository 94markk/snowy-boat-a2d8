# Delicat Identity Pro 6.15.1

## Mise à jour 6.15.1 et état des recommandations

Face ID et empreinte sont représentés par deux SVG locaux dans le bouton Passkey compact. Un bouclier avec coche accompagne « Sécurisé par Delicat Identity ». Le détail de confidentialité reste accessible sans agrandir la vue initiale. Aucune dépendance ou requête réseau ajoutée pour ces pictogrammes.

Intégré au code : Passkey/WebAuthn, lien magique, boutons de fournisseurs configurés, sessions serveur WordPress, obligation de 2FA administrateur, contrôle des changements d’e-mail clients, photo avec suppression, bannissement permanent/temporisé, mot de passe séparé, confirmations de transactions Exchange, alertes et registre de mise en service. Le compte sombre conserve la suppression du conteneur décoratif secondaire.

À configurer ou vérifier sur l’installation réelle : identifiants et retours OAuth, réception des e-mails et configuration du transport/domaine, essais Safari/iPhone/Android, activation effective des protections après remplacement du plugin, sauvegarde externe et restauration. Trustpilot n’est pas connecté par cette mise à jour ; un compte professionnel et un raccordement dédié restent nécessaires. Les avis déjà affichés par la boutique ne deviennent pas des avis Trustpilot par l’installation d’Identity.

## Interface compacte

Le grand pictogramme, le titre et le paragraphe d’introduction Passkey ont été retirés. Le bouton natif WebAuthn reste visible. La confidentialité est expliquée dans un panneau à la demande en pied de formulaire. Google, lien magique et mot de passe restent disponibles ; ouvrir une alternative e-mail ferme l’autre. Les boutons conservent une cible tactile de 44 px minimum. Les champs gardent une taille de texte de 16 px pour éviter le zoom automatique de Safari. Le conteneur suit la hauteur de visualViewport et laisse défiler les formulaires ouverts quand le clavier réduit l’espace.

Mesure Chromium à largeur 320 px, mêmes données simulées : bloc Passkey 345,5 px avant, 68 px après (environ 80 % de hauteur en moins). Fenêtre initiale complète : 446 px. Cette mesure décrit la mise en page ; ce n’est pas une mesure de vitesse réseau ou un benchmark sur téléphone réel. Aucun flou, animation de hauteur, police distante ou bibliothèque supplémentaire ajouté.

## Administration et identité

- `manage_options` implique une 2FA configurée avant l’accès à l’administration, aux actions AJAX et aux API authentifiées. Les actions nécessaires à l’enrôlement et la confirmation d’identité restent accessibles.
- Le réglage optionnel de la 2FA client ne désactive pas l’enrôlement obligatoire administrateur. La désactivation personnelle de la 2FA administrateur est refusée côté serveur.
- Les méthodes de connexion existantes gardent leurs contrôles de 2FA, de passkey avec vérification d’utilisateur, d’approbation du compte et de bannissement. Aucune fausse interface Face ID/PIN n’est utilisée ; le système de l’appareil gère le dialogue biométrique.
- La page Protection Identity permet d’enrôler Authenticator et de conserver les codes de récupération. Les mutations de cette page exigent POST, nonce, droits d’administration, 2FA configurée et identité récemment confirmée.
- Urgence : utiliser un code de récupération hors ligne. Un opérateur disposant d’un accès serveur de confiance peut employer la commande existante `wp delicat identity-2fa-reset <user-id>` ; cette remise à zéro révoque les sessions et impose ensuite un nouvel enrôlement. Aucun paramètre public de contournement ajouté.

## E-mail client réservé au support

La lecture seule de l’e-mail dans le profil est doublée d’un verrou serveur sur les modifications directes WordPress/WooCommerce/REST. Même un administrateur passe par la demande contrôlée pour changer l’e-mail d’un client.

Le support documente une vérification indépendante de l’identité, crée une demande et fait envoyer un code à la nouvelle adresse. L’ancienne adresse reçoit un avertissement. Le code à six chiffres expire après 24 heures, avec cinq tentatives de validation maximum et trois créations par heure/client. Il prouve seulement la possession de la nouvelle adresse : l’attestation du dossier d’identité reste obligatoire. Le support ne demande jamais un code de connexion ou un code 2FA.

La validation exige un administrateur avec droits sur le client, un nonce, une confirmation récente, la 2FA, la bonne demande et une ancienne adresse inchangée. Une réservation atomique à valeur constante empêche la double validation du même identifiant. Le code et le dossier sont chiffrés avec les clés dérivées des sels WordPress ; après validation le code est retiré. Le journal conserve l’identifiant de demande, les acteurs et le statut du transport, sans adresse cible ni code brut. La dernière demande de chaque client est conservée dans ses métadonnées protégées ; l’audit conserve l’historique selon la rétention existante du site.

Les sessions Web/App sont révoquées et l’ancienne adresse est avertie du changement terminé. La politique existante d’Account Sync reste applicable, notamment le lien de vérification de la nouvelle adresse lorsqu’il est requis. Les droits de changement d’e-mail pour les comptes administratifs eux-mêmes restent ceux de WordPress ; ce verrou porte sur les clients.

## Retraits et échanges

Adaptateur pour les deux actions AJAX des formulaires **Delicat Exchange Studio 2.0.0** : `des_submit` et `des_withdraw_submit`. Le contrôle serveur est exécuté avant leur gestionnaire, même en cas de contournement du JavaScript. Il vérifie la connexion autorisée, le nonce du formulaire, la politique de compte et la 2FA administrateur si applicable.

Le devis est recalculé côté serveur. L’autorisation est liée par HMAC au corps du formulaire, aux montants/taux/frais/devise de ce devis, au bénéficiaire, au client et à la session. Le corps et le devis sont chiffrés temporairement. Après création de la demande, une **nouvelle** preuve d’identité est nécessaire ; une seule preuve peut approuver une seule transaction. Le client voit les détails et confirme explicitement leur exactitude. L’autorisation expire après dix minutes et ne peut servir qu’une fois. Un changement exige une nouvelle confirmation.

Le lien ouvre un onglet séparé pour préserver le formulaire et ses fichiers. Le client revient et soumet de nouveau ; aucune transaction n’est déclenchée automatiquement. La validité et le débit final restent gérés par Exchange Studio, avec ses propres contrôles de fonds, d’idempotence et de pièces jointes. Les pièces jointes de justificatif ne servent pas de preuve d’identité dans cet adaptateur. Aucun endpoint de bénéficiaires enregistrés distinct n’a été identifié dans le module fourni. D’autres modules financiers ou de nouvelles API nécessitent leur propre raccordement.

Les marqueurs atomiques sont conservés jusqu’à expiration et nettoyés par une tâche WP-Cron horaire bornée. L’expiration est également vérifiée dans chaque requête ; la sécurité ne dépend pas de la ponctualité de Cron. L’adaptateur navigateur ne stocke ni autorisation ni données de compte dans localStorage/sessionStorage.

## Sessions, alertes et exploitation

Le nouvel écran Sessions et connexions affiche les navigateurs actifs du gestionnaire WordPress standard stocké en base : appareil, début, expiration et appareil courant. Les empreintes et cookies ne sont jamais affichés. Le panneau App existant complète cette vue. Un gestionnaire de sessions WordPress personnalisé peut nécessiter un adaptateur supplémentaire ; l’état technique indique si le gestionnaire par défaut est utilisé.

La mise à niveau active une fois les alertes de nouvel appareil et le suivi d’appareils, sans réinitialiser les autres réglages. Le module existant envoie ces alertes ; aucun deuxième gestionnaire de connexion dupliqué n’est ajouté. La révocation déclenche un avertissement limité en fréquence. Les alertes existantes de mot de passe, passkey, 2FA et fournisseur lié sont conservées.

Le test de réception envoie un code à l’administrateur. Le succès de `wp_mail()` signifie uniquement que le transport a accepté le message. Seul le code reçu confirme la réception de ce test. Cela ne remplace pas les contrôles SPF/DKIM/DMARC et la surveillance du fournisseur d’envoi.

Le registre de mise en service distingue explicitement les contrôles **déclarés** par un administrateur des contrôles automatiques. La sauvegarde hors site et la restauration réelle doivent être réalisées depuis l’hébergement. Sauvegarder fichiers, base de données et sels/clés WordPress ; sans les mêmes clés, les secrets chiffrés ne sont pas restaurables.

## Validation locale

- 47 contrôles PHP de sécurité passkey, dont signatures EC/OpenSSL réelles, compteurs, origine et preuves liées au navigateur.
- 54 contrôles de politique de compte, bannissement, photos, mot de passe séparé et activation du lien magique.
- 6 contrôles de réponses génériques de lien magique.
- 41 nouveaux contrôles de preuve de transaction, changement de bénéficiaire/devis/session, rejeu, chiffrement, approbation d’e-mail et sessions.
- 15 contrôles supplémentaires avec la vraie classe 2FA : obligation administrateur, accès à l’enrôlement, blocage AJAX et interdiction de désactivation.
- 32 contrôles Chromium des connexions, avec authentificateur WebAuthn virtuel et vérification PHP réelle des signatures.
- 32 contrôles Chromium du compte clair/sombre, du profil et du mot de passe à trois largeurs.
- 9 contrôles Chromium de l’adaptateur financier : lien, cible d’onglet, transmission/effacement de l’autorisation et refus d’URL étrangère.
- 68 contrôles Chromium du formulaire compact : 320×568, 360×640, 390×664 et 568×320, en clair/sombre, alternatives, confidentialité, débordement et hauteur.

Analyse syntaxique réussie sur les 71 fichiers PHP et les 15 fichiers JavaScript du paquet.

Ces essais utilisent des fonctions WordPress et des données simulées. Ils ne constituent pas un essai de bout en bout sur Hostinger avec la base réelle, GD/Imagick, les callbacks Google, l’envoi de messages et de vrais débits Wallet. Un test sur une copie de la boutique puis sur iPhone/Android réels reste nécessaire. Aucun déploiement sur le site public n’a été effectué. Ce travail ne garantit pas l’absence de toute vulnérabilité ou de tout bug dans le site et les autres extensions.
