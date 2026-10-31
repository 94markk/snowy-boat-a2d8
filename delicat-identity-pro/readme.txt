=== Delicat Identity Pro ===
Contributors: delicatstore
Requires at least: 6.2
Requires PHP: 7.4
Stable tag: 6.15.2
License: GPLv2 or later

Authentification et gestion sécurisée des comptes WordPress / WooCommerce.

== Version 6.15.2 ==

* Accueil du compte comme une application : sous le profil, deux cartes Portefeuille (solde, « Recharger », œil pour masquer le solde) et Récompenses (programme de fidélité ou de parrainage, points WooCommerce Points and Rewards, sinon le nombre de commandes), puis les trois dernières commandes avec leur état, et « Mes favoris » (produits enregistrés avec le cœur). La page reste privée : jamais mise en cache, données du seul client connecté, préférence « Masquer le solde » respectée côté serveur.
* États de commande avec pictogrammes (style Material) et texte, jamais la couleur seule : paiement en attente, en vérification, en cours, livrée, annulée, remboursée, échouée. Mêmes libellés que la page de commande du Builder quand il est actif ; aussi dans la colonne « État » de la liste des commandes.
* Détail d’une commande : titre « Commande #… » et retour vers la liste des commandes.
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

Consulter SECURITY-REVIEW-6.15.0.md pour le périmètre, les validations et les limites.
