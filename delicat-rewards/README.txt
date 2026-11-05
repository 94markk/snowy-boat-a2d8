DELICAT REWARDS — ADMIN EDITION 3.4.2

NOUVEAUTÉS 3.4.2 — CORRECTIFS DE L'AUDIT DU 5 NOVEMBRE 2026 (RWD-01 À RWD-05)
(1) Parrainage : les récompenses restent raisonnables (RWD-01).
  - Plafond : ce qu'un parrain reçoit au total (crédit, ou diamants comptés à leur valeur en HTG) ne
    dépasse pas 15 % de ce que ses amis confirmés ont vraiment acheté depuis leur invitation (achats
    terminés, payés par MonCash, NatCash…, remboursements déduits ; pas le crédit boutique, pas les
    recharges de portefeuille). Un palier atteint au-delà du plafond s'affiche « Palier atteint. Il se
    débloque quand vos amis invités auront fait plus d'achats » ; il se débloque dès qu'ils achètent
    davantage. Avant, 100 faux amis qui achetaient 100 G chacun rapportaient environ 9 260 G.
  - Numéro de paiement : si l'achat d'un ami est payé avec le numéro du parrain, ou avec celui d'un autre
    ami du même parrain (téléphone de facturation, ou numéro MonCash/NatCash du SMS de paiement reçu par
    Delicat Recharge pour ce code de transaction), l'ami ne donne pas de point : l'invitation va dans
    « À vérifier » (raison « Achat payé avec le numéro du parrain ou d'un autre de ses amis »). Vous
    acceptez ou refusez, comme pour les autres vérifications (une famille partage parfois un téléphone).
  - Validation par l'équipe : un palier de 35 points ou plus n'est payé qu'après votre accord. Le client
    voit « En vérification par l'équipe » ; vous recevez un e-mail. Onglet Parrainage › « Récompenses à
    valider » : « Valider et payer » (le client reçoit un e-mail) ou « Refuser » (le client est prévenu ;
    « Libérer » lui permet de réclamer à nouveau). « Valider et payer » refuse de payer un client bloqué pour
    fraude entre-temps, ou un palier qui dépasse le plafond parce que des achats de filleuls ont été remboursés
    depuis la demande.
  - Les récompenses déjà données restent acquises ; elles comptent dans le plafond des suivantes.
(2) Meilleur client : un remboursement après la fin de la semaine est vérifié (RWD-02).
  - Quand une commande comptée dans une semaine terminée est remboursée ou annulée, le classement de cette
    semaine est recalculé (une minute plus tard). Si le podium change, les prix concernés sont marqués
    « à vérifier » dans Meilleur client › Historique et remises, et vous recevez un e-mail avec l'ancien
    et le nouveau podium. Rien n'est repris automatiquement : aucun portefeuille débité, aucune commande
    modifiée. Un prix pas encore livré attend votre décision (« Marquer vérifié » ou « Remise manuelle »).
(3) Meilleur client : le classement n'est plus recalculé pendant la visite d'un client (RWD-03).
  - Il est calculé en arrière-plan (actions planifiées de WooCommerce, sinon WP-Cron) environ 30 secondes
    après chaque commande modifiée, et juste après le changement de semaine ; la page lit toujours le
    classement enregistré. Même classement qu'avant ; une seule lecture rapide au lieu de recharger
    jusqu'à 5 000 commandes. Juste après la mise à jour (ou le changement de semaine), le classement peut
    rester vide environ une minute. Diagnostics affiche l'heure du dernier calcul.
(4) Meilleur client : nouveau réglage « Montant minimum d'une commande pour le classement » (RWD-05).
  - Une commande dont le montant éligible (après remboursement) est plus petit ne compte pas dans le
    classement. 0 = toutes les commandes, comme avant (valeur à l'installation). Un changement s'applique
    à partir de la semaine suivante, jamais à la semaine en cours : annoncez la règle aux clients avant.
(5) Serveur de licences (RWD-04) : la boutique ne s'en sert pas (cette édition ne vérifie aucune licence).
  Son adresse publique (/wp-json/delicat-license/v1/validate) et la page « Rewards Licences » n'existent
  plus tant qu'aucune licence n'a été créée. Si vous avez déjà vendu des licences, rien ne change pour vos
  clients. Pour en créer une nouvelle : ajoutez dans wp-config.php
  define( 'DELICAT_REWARDS_LICENSE_SERVER', true );
(6) Technique : l'onglet Diagnostics ne s'arrête plus avec une erreur quand un remboursement fait partie
  des 100 dernières commandes. Table {préfixe}delicat_ref_claims : colonne « value » ajoutée (valeur en
  HTG de chaque récompense, pour le plafond). Nouvelles actions planifiées : delicat_leaderboard_rebuild
  et delicat_podium_recheck (groupe « delicat-rewards »). Option delicat_leaderboard_board (classement
  enregistré). Filtre delicat_ref_order_payer_phones pour une extension de paiement qui connaît le numéro
  du payeur.
(7) Réglages modifiés à la mise à jour (vous pouvez revenir en arrière) :
  - Parrainage › « Plafond des récompenses (% des achats des filleuls) » : 15 % (0 = sans plafond).
  - Parrainage › « Validation par l'équipe » : à partir de 35 points (0 = jamais).
  - Meilleur client › « Montant minimum d'une commande pour le classement » : 0 (règle inchangée).
    Suggestion : 450 G (le prix du pack 341 diamants), à annoncer une semaine avant.
  - Serveur de licences : désactivé s'il n'a jamais servi (voir (5)).
(8) Installation : WordPress › Extensions › Ajouter › Téléverser : delicat-rewards-3.4.2.zip, remplacer
  la version existante. Les données et les réglages sont conservés. Ensuite : ouvrez Delicat Rewards ›
  Diagnostics et vérifiez, quelques minutes plus tard, l'heure du « Classement Meilleur client ». Si elle
  n'avance pas alors que des commandes arrivent, les tâches planifiées ne tournent pas : demandez à
  Hostinger une tâche cron sur wp-cron.php toutes les 5 minutes. Purgez LiteSpeed Cache et Cloudflare.

DELICAT REWARDS — 3.4.1

NOUVEAUTÉS 3.4.1
- Parrainage : le premier achat d'un ami doit être un achat client. Un achat à prix revendeur (extension
  Delicat Resellers) ou fait par un compte de l'équipe ne donne pas de point, comme pour les bonus et le
  classement.

DELICAT REWARDS — 3.4.0

NOUVEAUTÉS 3.4.0 — PARRAINAGE (« INVITER UN AMI »)
- Un troisième onglet « Parrainage » sur la page Bonus Free Fire (lien direct : …/votre-page/#parrainage).
  Le programme est DÉSACTIVÉ à l'installation : activez-le dans Delicat Rewards › onglet « Parrainage »
  après avoir vérifié les paliers et les montants.
- Chaque client reçoit un code personnel (DLC + 6 caractères, ex. DLCK7M2QX) et un lien personnel vers la
  page. Boutons : « Partager sur WhatsApp », Facebook, Telegram, SMS, « Plus… » (le menu de partage du
  téléphone : Instagram, Messenger, etc.), « Copier » le code et « Copier le lien ». Le message envoyé se
  règle dans le tableau de bord ({lien} = le lien du client, {code} = son code).
- 1 point par ami qui :
  1) crée son compte avec le lien (ou tape le code dans les 7 jours après son inscription, avant son
     premier achat) ;
  2) paie un premier achat d'au moins 100 HTG dans les 30 jours, par MonCash, NatCash… (pas avec le
     crédit boutique) ;
  3) le point est validé 7 jours après l'achat terminé, s'il n'est ni remboursé ni annulé.
  Maximum 100 points par client. Si une règle n'est pas respectée : aucun point.
- Paliers 5, 15, 35, 60, 85 et 100 points : chaque palier se réclame une seule fois, au choix du client,
  en diamants Free Fire (PIN, comme les bonus) ou en HTG (crédit ajouté au portefeuille TeraWallet).
  Montants proposés au départ, À VÉRIFIER avant d'activer :
     5 pts : 110 diamants ou 140 HTG      60 pts : 1 160 diamants ou 1 400 HTG
    15 pts : 341 diamants ou 420 HTG      85 pts : 2 180 diamants ou 2 600 HTG
    35 pts : 572 diamants ou 700 HTG     100 pts : 3 340 diamants ou 4 000 HTG
  À l'enregistrement, un montant en diamants qui ne peut pas être fait avec vos PIN est refusé (choix
  diamants retiré pour ce palier) ; le stock est vérifié au moment de la réclamation.
- Protection contre la fraude :
  · même téléphone que le parrain (ou téléphone déjà utilisé par un autre compte) : invitation refusée et
    les comptes concernés sont retirés du programme de récompenses (bonus et parrainage) jusqu'à votre
    vérification (bouton « Débloquer ») ; un e-mail vous prévient ;
  · même numéro de téléphone de paiement ou même adresse e-mail (variantes comme nom+promo@… ou
    n.o.m@gmail.com) qu'un autre compte : refusée ;
  · même réseau internet que le parrain dans les 3 derniers jours : mise « À vérifier » (pas refusée :
    Digicel et Natcom donnent la même adresse à beaucoup de clients, et des amis s'invitent souvent sur
    le même Wi-Fi) ; vous acceptez ou refusez dans l'onglet ;
  · VPN ou proxy : interdit (visite du lien, inscription, code, réclamation), avec une clé proxycheck.io ;
  · premier achat payé avec le crédit boutique, gratuit (coupon 100 %), trop petit ou remboursé : ne
    compte pas ; un remboursement après la validation retire le point (une récompense déjà donnée reste) ;
  · une récompense par palier, garantie par la base de données même si deux demandes arrivent ensemble.
  · plus de 5 nouveaux amis en 24 h pour un même parrain : les suivants sont mis « À vérifier »
    (réglable : « Invitations par jour ») ;
  · une recharge de portefeuille (TeraWallet) ne compte pas comme premier achat, ni une commande passée
    avant l'invitation ; un remboursement partiel qui laisse au moins le minimum garde le point ;
  · le premier lien d'invitation ouvert sur un téléphone reste : un autre lien (ou un autre site) ne peut
    pas le remplacer ;
  · si une erreur interrompt une réclamation, le palier reste réservé (jamais payé deux fois) : il apparaît
    dans « Réclamations à vérifier », où vous le libérez ou le marquez donné ;
  · un compte bloqué que vous supprimez garde ses empreintes : la même personne ne repart pas de zéro.
- Dans l'onglet, chaque invitation récente a un bouton : « Accepter » (une invitation refusée par erreur)
  ou « Retirer » (refuser une invitation, ou reprendre un point déjà donné).
- La page des liens se choisit dans l'onglet (« Page des invitations ») ; sinon c'est la page publiée qui
  contient [delicat_bonus_free_fire].
- Les limites de demandes (réclamations, chargement de la page) comptent désormais chaque visiteur
  séparément derrière Cloudflare, au lieu de mettre tous les visiteurs dans le même compteur.
  Le client voit seulement un message général (« Ne respecte pas les règles du programme ») : les
  détails de la détection restent dans le tableau de bord. Les données (téléphone, réseau, navigateur, e-mail) sont gardées
  uniquement sous forme d'empreintes chiffrées, jamais en clair, et supprimées avec le compte.
- Ce qu'un site web ne peut pas faire (à savoir) : lire l'IMEI ou le numéro du téléphone, ou voir la
  vraie adresse derrière un VPN. Le « même téléphone » est reconnu par l'identifiant que le site enregistre
  dans le navigateur : quelqu'un qui efface les données du navigateur ou change de navigateur n'est plus
  reconnu par ce moyen. Les autres contrôles (numéro de paiement, e-mail, réseau, VPN, vérification par
  l'équipe) restent actifs.
- Page : les boutons-liens (« Se connecter », « Utiliser mon PIN ») gardent leurs couleurs avec Delicat
  Builder ; le mode sombre de la page est dessiné par la page elle-même.

INSTALLATION 3.4.0
1. WordPress > Extensions > Ajouter > Téléverser : delicat-rewards-admin-v3.4.0.zip, remplacer l'extension
   existante. Les réglages, commandes, bonus et registres sont conservés ; trois tables sont ajoutées
   ({préfixe}delicat_referrals, delicat_ref_claims, delicat_ref_signals).
2. Détection VPN (recommandé) : créez un compte sur proxycheck.io, copiez votre clé, puis ajoutez dans
   wp-config.php (avant « That's all, stop editing ») :  define( 'DELICAT_PROXYCHECK_KEY', 'votre-clé' );
   La clé n'est jamais affichée ni envoyée au navigateur. Sans clé, les VPN ne sont pas détectés.
3. Delicat Rewards › onglet « Parrainage » : vérifiez les paliers et les montants, le message de partage,
   puis cochez « Activer le parrainage » et enregistrez.
4. Mettez à jour votre politique de confidentialité (à faire par vous) : le programme de parrainage
   enregistre, sous forme d'empreintes chiffrées, l'identifiant du navigateur, l'adresse réseau, un résumé
   du navigateur et l'e-mail, et vérifie les VPN auprès de proxycheck.io, pour empêcher les faux comptes.
5. Purgez LiteSpeed Cache et Cloudflare.
6. Testez avec deux téléphones différents : le lien d'un client, l'inscription de l'ami, son premier achat.

DELICAT REWARDS — 3.3.0

NOUVEAUTÉS 3.3.0 — PAGE « BONUS FREE FIRE » ET SÉCURITÉ
- Une seule page, deux onglets : « Classement » (Meilleur client de la semaine) et « Mes bonus »
  (progression, réclamation, Mes PINs récompenses). Shortcode : [delicat_bonus_free_fire].
  Les anciens shortcodes fonctionnent toujours et ouvrent la même page sur leur onglet :
  [delicat_meilleur_client] → Classement, [delicat_rewards] → Mes bonus.
  Option : [delicat_bonus_free_fire tab="bonus"] pour ouvrir sur Mes bonus, title="" pour cacher le titre.
  Lien direct vers un onglet : …/votre-page/#mes-bonus ou #classement.
- Nouveau design : carte dégradée avec compte à rebours et prix de la 1re place, podium (1er, 2e, 3e)
  avec les diamants de chaque place, classement avec barres, « Votre position » toujours visible en bas,
  cartes de bonus avec barre de progression, bouton « Réclamer » animé. Mode clair et sombre, icônes
  Material uniquement (les emoji des textes du tableau de bord ne sont plus affichés).
- Célébrations : confettis et « Bravo ! » à la réclamation d'une récompense, confettis quand un bonus
  devient disponible (une fois) et quand le client passe 1er de la semaine (une fois par semaine).
  Rien ne bouge pour les personnes qui ont réduit les animations sur leur téléphone.
- Plus rapide : la page ne contient plus aucune donnée personnelle (elle peut rester en cache) ; une
  seule demande apporte la session, les bonus et le classement des deux onglets ; elle part pendant que
  la page se charge encore. Fichiers de la page : 11 Ko (JS) + 5 Ko (CSS) compressés, chargés seulement
  sur cette page. Aucune police externe.
- Sécurité (revue complète du code 3.2.4, aucune faille critique ni clé secrète trouvée dans le code) :
  · une ligne de commande gratuite (coupon 100 %, commande à 0) ne fait plus progresser un bonus ;
  · une réclamation = un numéro unique enregistré par la base de données elle-même : même si deux
    demandes passent le verrou en même temps, une seule récompense est créée ;
  · les limites de réclamation sont par client : un compte qui envoie beaucoup de demandes ne bloque
    plus les réclamations des autres ;
  · le classement est recalculé une seule fois à la fois (avant, chaque visiteur relançait le calcul
    complet juste après le reset du dimanche) ;
  · l'application ne peut lire « Mes bonus » que du client connecté ;
  · licences : une clé tapée en minuscules est reconnue ; un client qui envoie un « nonce » le reçoit
    signé dans la réponse (une réponse enregistrée ne peut pas être rejouée) ; les clients actuels
    continuent de fonctionner ;
  · un numéro de remboursement demandé comme commande récompense répond « introuvable » (plus d'erreur) ;
  · un prénom qui contient l'identifiant ou l'adresse e-mail du compte n'est plus affiché sur le site ;
  · export CSV et motif de slug protégés contre des valeurs piégées.

INSTALLATION 3.3.0
1. WordPress > Extensions > Ajouter > Téléverser : delicat-rewards-admin-v3.3.0.zip, remplacer l'extension
   existante (les réglages, commandes et registres sont conservés).
2. Créez la page « Bonus Free Fire » (Pages > Ajouter, contenu : [delicat_bonus_free_fire]) et mettez-la
   dans le menu, ou gardez vos pages actuelles : elles affichent déjà la nouvelle page.
3. Purgez LiteSpeed Cache et Cloudflare.
4. Testez avec un compte client : les deux onglets, puis une réclamation si un bonus est disponible.

DELICAT REWARDS — 3.2.4

NOUVEAUTÉS 3.2.4 — FOURNISSEURS MULTIPLES ET ERREURS 403
- Les achats du même pack sont cumulés, quel que soit le fournisseur :
  10 achats de 110 diamants chez A + 15 chez B = 25 achats du pack Mini.
- Les anciennes associations de produits/variations et de PIN sont mémorisées
  séparément du stock de récompenses. Remplacer le fournisseur ne les efface plus.
- Les produits supprimés/non publiés restent reconnus dans les commandes existantes.
- Les anciennes lignes nommées explicitement Free Fire + montant de diamants sont
  récupérées même si leur ID n'est plus associé. Les noms ambigus et abonnements
  nécessitent une association manuelle; aucun montant n'est déduit du prix.
- Pour chaque pack, ajoutez les IDs produits/variations de tous les fournisseurs
  dans Récompenses > Tiers > Produits éligibles. Utilisez les IDs WooCommerce,
  pas les IDs de catalogue de l'API fournisseur. Pour un produit variable proposant
  plusieurs packs, associez chaque ID de variation au bon pack.
- Gardez la même clé de tier quand vous changez de fournisseur. Les objectifs,
  réclamations et réinitialisations mensuelles existants restent en place.
- Si une ancienne association avait déjà été remplacée avant cette mise à jour et
  que le nom de la commande ne précise pas le pack, ajoutez l'ancien ID au même
  tier : les commandes conservées dans WooCommerce seront recomptées. La version
  précédente ne conservait pas ces anciennes associations; il faut les rétablir.
- Un achat annulé, non payé ou remboursé ne gagne pas de progression; les commandes
  gratuites, invités, revendeurs et comptes staff gardent leurs exclusions.
- Correction des réponses 403 HTML : renouvellement de session et transport AJAX
  restreint pour les routes de récompenses bloquées. Même validation du compte,
  nonce CSRF, contrôle propriétaire, token signé, verrou et contrôle de stock.
- Le classement Meilleur Client renouvelle aussi sa session et affiche une erreur
  explicite au lieu d'un classement vide lorsque le serveur refuse l'accès.
- Les refus d'autorisation et exclusions restent actifs. Le plugin ne peut pas
  corriger une règle d'hébergement qui bloque aussi admin-ajax.php, ni modifier
  l'authentification du plugin API de l'application native.

INSTALLATION 3.2.4
1. WordPress > Extensions > Ajouter > Téléverser l'extension.
2. Choisissez delicat-rewards-admin-v3.2.4-multi-supplier-fix.zip et remplacez
   l'extension existante. Ne supprimez pas les options ou commandes du programme.
3. Purgez le cache LiteSpeed, puis rechargez la page des récompenses.
4. Vérifiez les associations de chaque fournisseur dans Tiers.
5. Testez avec un compte client (pas administrateur) : deux achats terminés du
   même pack chez deux fournisseurs, puis un remboursement.

VALIDATION
15 vérifications PHP avec doubles WordPress/WooCommerce : conservation des IDs,
cumul inter-fournisseurs, récupération historique, remboursement de ligne et de
montant, exclusions des commandes non payées/non terminées, noms ambigus, maintien
de la validation stricte du stock PIN. 6 vérifications JavaScript de transport :
REST, nonce expiré, 403 HTML, conservation du token, refus et changement de compte.
9 vérifications PHP du transport AJAX : invités, nonce, liste de routes, méthode,
compte imposé par le client, JSON invalide, taille et token conservé.
Syntaxe des 7 fichiers PHP et du JavaScript vérifiée. Pas de test sur votre site
WordPress en production : confirmer les associations et le comportement après pose.

Mise à jour du 24 septembre 2026

Cette version réunit trois séries de changements, toutes présentes dans ce ZIP :
- 3.2.3 : contrat pour l'application et deux correctifs du site (ci-dessous) ;
- 3.2.1 : correctifs de l'audit 4.1 ;
- 3.2.2 : synchronisation Secure Keys.
Elle remplace la 3.2.2 « secure-sync » installée sur le site.

NOUVEAUTÉS 3.2.3
Contrat pour l'application (fichier app-contract.php)
- Le plugin publie une petite interface stable (DELICAT_REWARDS_APP_CONTRACT = 1,
  fonctions delicat_rewards_app_*) que Delicat App API 2.9 utilise pour afficher
  « Mes bonus », « Mes PINs récompenses » et le classement Meilleur client
  directement dans l'application. Sans cette version, l'application continue
  d'ouvrir les pages du site.
- Une réclamation depuis l'application passe par le même code que le site :
  même verrou, mêmes limites, même registre signé, même jeton. Le même jeton
  envoyé une seconde fois renvoie la même commande récompense. Elle vaut
  toujours pour le client connecté, jamais pour un autre compte. Le jeton de
  réclamation n'est remis qu'à son propre client.
- Classement dans l'application : noms toujours abrégés (« Jean R. », ou
  « Client » suivi d'un alias), même si « Masquer les noms » est décoché pour le
  site. Jamais d'identifiant, d'e-mail, de montant dépensé ni de nombre de
  commandes d'un autre client : seulement le rang et un pourcentage du leader.
- Le texte « ce qui compte » vient des réglages (produits des paliers, produits
  supplémentaires, ligne des jeux) et non d'un « Free Fire » écrit en dur.
- Anciens gagnants : visibles dans l'application seulement si leur affichage
  est activé sur la page Meilleur client.
- Lien « Règlement » : la page publiée reglement-bon-kliyan (Delicat Builder),
  si elle existe.

Correctifs du site
- Pied de page du classement : les lignes 1 et 2 de Réglages s'affichent enfin
  sur la page [delicat_meilleur_client] ; elles restaient vides.
- Podium automatique : l'e-mail « Ou se Meilleur Client semèn nan » part
  seulement à la 1re place. Les 2e et 3e places le recevaient aussi ; elles
  gardent l'e-mail WooCommerce de commande terminée.

Recommandé : clé de signature dans wp-config.php
    define( 'DELICAT_REWARDS_HMAC_KEY', 'au moins 32 caractères aléatoires' );
  Voir les correctifs 3.2.1 ci-dessous. À l'ajout de la clé, les alias
  « Client xxxxxx » changent une fois, et un bouton Réclamer déjà affiché
  demande d'actualiser la page.

Tests (hors ZIP) : php tests/rewards_smoke.php

CORRECTIFS DE L'AUDIT 4.1 — 3.2.1 (septembre 2026)
- Commandes miroir de l'API revendeur et commandes des comptes du personnel
  (droit manage_woocommerce) : elles ne comptent plus ni pour les bonus ni pour
  Meilleur client. Filtres : delicat_rewards_excluded_sources,
  delicat_rewards_staff_cap, delicat_rewards_order_is_retail.
- Remboursement ou annulation après un bonus réclamé : le manque est reporté,
  signé, sur les mois suivants ; les prochains achats du client le couvrent
  d'abord. Note sur la commande et e-mail à l'administrateur. Aucun PIN n'est
  retiré automatiquement.
- Remboursement par montant (sans ligne) : la même part des unités de la
  commande est retirée, arrondie en défaveur du client. Un petit geste
  commercial ne fait plus disparaître toute la commande.
- Une commande terminée de nouveau plus tard compte à sa première date de fin.
- Semaine Meilleur client : frontière calculée dans le fuseau du site (vraie
  heure de reset). Les semaines, clôtures et gagnants déjà enregistrés sont
  convertis une seule fois à la mise à jour ; une semaine déjà payée n'est
  jamais payée deux fois. Le compte à rebours suit l'heure du site.
- Noms du classement : jamais d'identifiant, d'adresse e-mail ou de partie
  d'e-mail ; à défaut « Client » suivi d'un alias. Noms abrégés par défaut sur
  une nouvelle installation.
- Clé de signature des bonus : ajoutez dans wp-config.php
    define( 'DELICAT_REWARDS_HMAC_KEY', 'au moins 32 caractères aléatoires' );
  Un changement des salts WordPress ne gèle alors plus les bonus ; les
  registres existants restent valides et sont re-scellés à la lecture.
  Rotation : gardez l'ancienne valeur dans DELICAT_REWARDS_HMAC_PREVIOUS_KEYS.
  Sans cette clé, après un changement de salts : Diagnostics > « Re-sceller les
  registres du mois ». Aucune réclamation n'est baissée.
- PINs récompenses : lus aussi depuis Secure Keys sur la page du site, et
  affichés dans l'application quand le fournisseur est WC Key Manager.
- Classement : reconstruit au plus une fois par minute et une seule
  reconstruction à la fois ; la page [delicat_meilleur_client] est exclue des
  caches de page.
- Tests (hors ZIP) : php tests/rewards_smoke.php

SYNCHRONISATION SECURE KEYS — 3.2.2
- Intégration native avec Delicat Secure Keys v1.6.0.
- Après création de la commande récompense WooCommerce, un hook idempotent demande
  immédiatement au coffre Secure Keys d'attribuer le PIN.
- Le stock réellement disponible dans le coffre est vérifié avant émission.
- Pour un produit marqué Delicat Keys, le coffre est la source d'autorité : une
  ancienne métadonnée plaintext ne peut pas ressusciter un PIN annulé/remboursé.
- Rewards ne persiste aucune nouvelle copie plaintext du PIN. Il lit uniquement le
  code déjà attribué au client, à la commande et à la ligne exacts au moment de
  l'affichage privé.
- Le PIN reste absent des URLs, localStorage, analytics et historique navigateur.

CHIFFREMENT
- Secure Keys v1.6.0 chiffre les nouveaux imports avec AES-256-GCM + HKDF-SHA256.
- Les anciens enregistrements AES-GCM restent compatibles.
- Configurez et sauvegardez DELICAT_KEYS_SECRET dans wp-config.php.

INSTALLATION
1. WordPress > Extensions > Ajouter une extension > Téléverser.
2. Sélectionnez delicat-rewards-admin-v3.2.4-multi-supplier-fix.zip et remplacez la version existante.
   Gardez une seule version active. Le dossier reste delicat-rewards.
3. Purgez LiteSpeed / Cloudflare pour charger les nouveaux fichiers CSS et JS.
4. Le shortcode reste [delicat_rewards]. Les réglages, objectifs, mappings,
   commandes, historique signé et données Meilleur client sont conservés.
5. Vérifiez une récompense avec un compte client éligible et un PIN en stock.
   En mode test du plugin, aucune récompense réelle n'est attribuée.

PARCOURS CLIENT
- Cliquez sur Réclamer : la récompense est créée via WooCommerce, à total zéro.
- Le PIN réellement attribué apparaît sur la même page, dans une fenêtre centrée.
- Cliquez sur Copier, puis Utiliser mon PIN :
  https://delicastoreha.com/free-fire-redeem/
- Aucun passage par la page des commandes n'est nécessaire.
- Mes PINs récompenses retrouve les commandes récompenses existantes, avec pagination.
- Les PINs déjà attribués peuvent être consultés sans créer une autre commande.
- Une livraison manquante ou partielle reste en préparation ; actualisation sur place.
  Une commande enregistrée mais interrompue doit être vérifiée par l'administrateur.
  Le rafraîchissement n'attribue jamais un nouveau PIN et ne relance pas le fournisseur.

CORRECTIONS
- Remplacement de la fenêtre hors conteneur qui restait visible sous la page.
- Fenêtre native dialog, fermeture clavier, retour du focus, contraste et boutons lisibles.
- Cartes compactes, police système, styles isolés, espace de défilement sous la navigation.
- Suppression des animations permanentes et des dépendances aux polices externes.
- Montants au format français ; total des achats limité aux articles Free Fire éligibles.
- Chaque pack progresse indépendamment : suppression des faux états Verrouillé.
- Objectif affiché sur 25/25, par exemple ; le total réel des achats et les bonus
  déjà réclamés restent explicités. Les achats excédentaires ne sont pas effacés.
- Le prochain bonus privilégie un pack réellement réclamable.
- Messages distincts : plafond mensuel, limite du palier, mode test, blocage d'intégrité.
- Cas sans palier actif et réponses REST en erreur gérés sans crash.
- Réinitialisation du mois alignée sur le fuseau WordPress ; compteur client synchronisé.
- Blocage des doubles clics et récupération idempotente d'une même demande
  à partir de sa commande WooCommerce, y compris après perte de la réponse.
- Verrou de stock partagé entre réclamations et attributions hebdomadaires.
- Totaux, sous-totaux et taxes des lignes récompense enregistrés à zéro avant livraison.
- Une erreur d'un hook après sauvegarde ne doit pas attribuer une seconde récompense.
- Renouvellement du nonce REST si nécessaire ; changement de compte détecté.

LECTURE DES PINs
Intégration native WC Key Manager (fonctions wckm_get_order_keys, modèle Key) :
- lecture des codes déjà attribués à la commande ET à la ligne ET au client ;
- clés annulées, disponibles en stock ou expirées exclues ;
- quantité de livraison par unité respectée ;
- vérification du stock de clés preset avant émission. Les licences générées
  aléatoirement ne peuvent pas remplacer des PINs Free Fire valides.
Cette intégration a été vérifiée contre le code officiel de WC Key Manager :
https://plugins.svn.wordpress.org/wc-key-manager/trunk/includes/functions.php
https://plugins.svn.wordpress.org/wc-key-manager/trunk/includes/Models/Key.php

Autres intégrations : lecture des métadonnées de ligne déjà reconnues par le plugin
(_delicat_keys, _delicat_codes, delicat_keys, _license_keys, _wc_keys).
Formats acceptés : chaînes, listes, JSON et objets associatifs contenant un champ
code/pin/key/license_key/license ou codes/pins/keys. Aucun parcours aveugle des
métadonnées de commande, notes, identifiants, emails ou stocks de PINs.
Pour un fournisseur au stockage différent ou chiffré, le filtre PHP
  delicat_reward_item_codes( $codes, $item, $order )
doit retourner une liste de chaînes déjà attribuées, en lecture seule, via l'API
réelle du fournisseur. Le module ne devine pas comment déchiffrer un autre stockage.
L'installation effective du fournisseur du site n'était pas accessible dans ce test.

PROTECTION ET VÉRIFICATION
- Routes connectées, propriété des commandes vérifiée ; PINs jamais dans les URLs.
- Aucun stockage navigateur permanent des PINs. Les PINs quittent le DOM à la fermeture.
- Réponses privées no-store et page marquée non cachable.
- Commandes remboursées, annulées ou échouées : codes non exposés.
- Compteurs signés, jetons liés au client/palier/mois/compteur et limites conservés.
- Pas de nouvelle copie persistante des PINs ni changement du chiffrement du fournisseur.
- Vérifié : syntaxe PHP 8.3 et JavaScript ; 26 assertions backend et 6 assertions
  de création de commande ; 18 assertions DOM/interaction locales (services simulés).
- Ces tests ne sont pas un test WordPress/WooCommerce complet ni une livraison réelle.
- Le rendu visuel dans un navigateur et les interactions avec les autres plugins
  restent à vérifier sur le site : l'accès au navigateur de prévisualisation était bloqué.

HISTORIQUE ET DOCUMENTATION DES VERSIONS PRÉCÉDENTES
=================================================

DELICAT REWARDS ADMIN 3.2.0 — MEILLEUR CLIENT

NOUVEAUTÉS
- Onglet Meilleur client et entrée WooCommerce dédiés ; prix, calendrier,
  taille du classement, confidentialité et textes séparés des bonus mensuels.
- Clôturer et redémarrer : fige le podium et prépare les remises manuelles.
- Réinitialiser sans gagnant : abandonne seulement la période actuelle.
  Confirmation RECOMMENCER obligatoire ; une ancienne soumission est refusée.
- Préparer les résultats de la semaine précédente pour une remise manuelle.
- Enregistrer une récompense déjà remise, avec référence, date et administrateur.
  Cette action ne crée ni commande ni recharge et bloque la remise automatique
  de la même place. Vérifiez réellement la réception avant de confirmer.
- Historique conservé et paginé ; affichage des cinq derniers premiers gagnants
  récompensés dans [delicat_meilleur_client], avec nom abrégé et prix.
  Aucune référence privée, adresse email ou dépense n'est publiée.
- Contrôle de visibilité de l'historique et recommandations dans le dashboard.

UTILISATION
WooCommerce > Delicat Meilleur client.
Pour récompenser le leader actuel hors du plugin : clôturez la période, remettez
le prix, puis enregistrez la remise dans l'historique.
Pour un classement sans remise : réinitialisez sans gagnant.
Les nouvelles commandes comptent après le redémarrage ; la prochaine frontière
hebdomadaire reste celle configurée. Les bonus mensuels ne sont pas remis à zéro.
Une pause masque le programme et bloque ses livraisons automatiques ; elle ne
supprime pas les achats éligibles de la période.
Les règles de sécurité, le mode test, le stock/les produits et l'activation des
emails restent communs aux deux programmes et sont indiqués comme tels.
La visibilité sans connexion reste contrôlée par le réglage du classement.
Les périodes clôturées manuellement se règlent dans l'historique ; les clôtures
hebdomadaires automatiques conservent le fonctionnement cron existant.
Seuls les résultats déjà conservés par les versions précédentes sont disponibles
après mise à jour : aucun ancien gagnant n'est inventé ni reconstruit.

MISE À JOUR
1. Sauvegardez le site et la base de données.
2. Téléversez ce ZIP via Extensions > Ajouter > Téléverser une extension.
3. Remplacez la version installée, sans désinstaller le programme.
4. Vérifiez le menu Meilleur client, les prix, les produits et les deux shortcodes.
5. Testez sur une copie du site la clôture, le reset et la remise manuelle avant
   utilisation réelle. Purgez le cache des pages de classement après une clôture.

VALIDATION LOCALE
PHP : syntaxe des deux fichiers et chargement complet avec hooks WordPress simulés.
21 assertions de cycle de période, anti-duplication et confidentialité réussies.
12 fonctions existantes de bonus, livraison, classement et intégrité inchangées.
Aucun test de livraison fournisseur, email, cron réel ou base WooCommerce de
production n'a été effectué. Le ZIP ne contient pas le banc de test.

RECOMMANDATIONS SUPPLÉMENTAIRES
- Vérifier les remboursements et le podium avant chaque remise.
- Prévoir un budget et un stock PIN pour les trois places.
- Conserver la preuve de remise ; traiter les erreurs avant de payer manuellement.
- Stabiliser les règles pendant chaque période.
- Configurer une tâche cron serveur régulière pour la clôture hebdomadaire.
- À envisager ensuite : export CSV des gagnants, plafond de budget par période
  et approbation par un second administrateur avant toute livraison automatique.

------------------------------------------------------------
DOCUMENTATION HISTORIQUE DE LA VERSION PRÉCÉDENTE
------------------------------------------------------------

DELICAT REWARDS — ADMIN EDITION v3.1.1
OMNI FREE FIRE REWARDS + PIN-ONLY FULFILMENT + GUIDED SETUP

NEW IN 3.1.1 — CLIENT PRIVACY: PERCENTAGE ONLY
- The client-facing leaderboard no longer returns or displays exact order counts.
- The client-facing leaderboard never returns or displays exact eligible spend.
- Podium cards, ranking rows and the connected customer's position display only
  the privacy-safe relative score percentage.
- Exact weekly order count and eligible net spend remain available only inside
  WooCommerce > Delicat Rewards > Participants for authorized administrators.
- Ranking still securely uses 70% spend + 30% distinct completed orders.

NEW IN 3.1.0 — MEILLEUR CLIENT: MONEY + REAL ORDER FREQUENCY
- Weekly score is 70% eligible net spend + 30% distinct WooCommerce orders.
- Each paid + completed WooCommerce order counts exactly once, even if it has
  several eligible items or a quantity greater than one.
- Only eligible Free Fire line-item spend is included; refunds and free reward
  orders remain excluded.
- A client who both spends more and places more qualifying orders always ranks
  above a client with lower values for both metrics.
- The live board and final automatic podium use the same centralized engine.
- Ties are deterministic: score, net spend, orders, earliest final qualifying
  order, then customer ID.
- Client-facing metrics are percentage-only since v3.1.1. Exact spend and order
  counts remain restricted to the administrator dashboard.
- WooCommerce > Delicat Rewards > Participants now shows administrators the
  exact weekly order count and eligible net spend for every participant.

IMPORTANT — ARCHITECTURE v3
WooCommerce remains the only authority for customer, payment, order and order-status data.

A purchase earns progress only when:
- it belongs to a logged-in WooCommerce customer;
- WooCommerce marks it COMPLETED;
- WooCommerce considers it paid;
- it is not a free Delicat reward order;
- refunded quantities are subtracted;
- the purchased product/variation is mapped to the relevant Free Fire tier.

PURCHASE SOURCE != REWARD SOURCE
- Eligible purchase: FREE FIRE PIN or FREE FIRE DIRECT TOP-UP.
- Different Free Fire servers/regions are supported by mapping all matching WooCommerce product/variation IDs to the same tier.
- Reward fulfilment: ALWAYS the dedicated FREE FIRE PIN catalogue.
- The reward engine never sends a free reward through the direct-top-up supplier/API.

DEFAULT JOURNEY
- 110 💎: buy 25 eligible 110 purchases -> receive 110 💎 FREE as PIN.
- 341 💎: buy 20 eligible 341 purchases -> receive 341 💎 FREE as PIN.
- 572 💎: buy 15 eligible 572 purchases -> receive 572 💎 FREE as PIN.
- 1160 💎: buy 12 eligible 1160 purchases -> receive 1160 💎 FREE as PIN.
- 2180 💎: buy 10 eligible 2180 purchases -> receive 2180 💎 FREE as PIN.

These values remain editable. You can add up to 20 tiers and add new PIN denominations from WooCommerce > Delicat Rewards.

HOW TO MAP DIRECT TOP-UP + MULTIPLE SERVERS
1. Go to WooCommerce > Delicat Rewards > Produits.
2. For every reward denomination (110, 341, 572, etc.), map the WooCommerce product/variation that actually DELIVERS the PIN reward.
3. Go to WooCommerce > Delicat Rewards > Tiers.
4. Set “Pack 💎” to the denomination tracked by that tier.
5. In “Produits éligibles (direct/PIN/serveurs)”, add every other WooCommerce ID that represents the same diamond amount:
   - direct top-up product;
   - another Free Fire server/region;
   - an older product ID that should continue counting;
   - a specific variation ID.
6. The canonical PIN product from step 2 is included automatically; you do not need to repeat it.

VARIABLE PRODUCTS / SERVER VARIATIONS
- If EVERY variation of a parent product represents the same diamond amount, you may map the parent product ID.
- If the parent contains several amounts or several unrelated options, map only the exact variation IDs for that tier.
- Mapping one exact variation does NOT make its sibling variations count.

ADDING MORE REWARD OPTIONS
WooCommerce > Delicat Rewards > Tiers lets you create additional tiers with:
- display label/title;
- Pack 💎 denomination;
- unlimited direct/server product and variation IDs;
- purchase goal;
- PIN reward amount;
- per-month claim limit;
- active/inactive state and display order.

WooCommerce > Delicat Rewards > Produits lets you add more PIN denominations. Reward composition can combine multiple PIN denominations when necessary, but only if an exact amount can be produced safely.

SECURITY / INTEGRITY HARDENING IN v3
- Claim lock is per CUSTOMER + MONTH, so simultaneous claims in different tiers cannot overwrite each other or bypass the monthly cap.
- Claim ledger is HMAC-SHA256 signed and migration is one-way.
- Completed WooCommerce reward orders are reconciled back into the ledger.
- New reward orders store reward type, amount, tier/month or weekly placement metadata for stronger reconciliation.
- Tier history is retained so configuration changes do not silently erase old reward values from reports/cap checks.
- Claims are recomputed from real WooCommerce orders inside the lock; the browser cannot submit its own progress.
- Claim buttons use short-lived signed anti-replay tokens.
- Claim rate limits are guarded atomically.
- Refund quantities and refunded spend are removed from progress.
- Reward orders cannot themselves earn rewards.
- Public leaderboard automatically masks customer names.
- Admin writes require WooCommerce capability + WordPress nonce checks.
- CSV exports neutralize spreadsheet-formula injection.
- Claim failures are logged through WooCommerce Logs with source “delicat-rewards”.

PIN STOCK / KEY SAFETY
Before issuing a reward, v3 verifies that the required PIN product/variation is purchasable and in stock.
If Delicat Keys exposes delicat_keys_available(), key inventory is also treated as a hard delivery limit.
Variable PIN products no longer fall back to an unavailable variation.
The reward planner checks current inventory before creating the zero-total reward order.

WEEKLY “MEILLEUR CLIENT” HARDENING
- Weekly awarding has a database lock.
- The top-3 winner snapshot is frozen for retries.
- Each rank has an idempotent delivery state.
- Existing completed weekly reward orders are detected before another is issued.
- A failed prize remains retryable and does NOT mark the week completed.
- Admin email is sent when the meaningful podium delivery state changes, not every hourly retry.

PERFORMANCE CHANGES
- WooCommerce order reads are paginated instead of using unlimited order queries in the main reward/leaderboard/report paths.
- Diagnostics no longer scans the whole product catalogue merely to count configured Free Fire products.
- Google Fonts are disabled by default; the reward UI inherits site/system fonts. Developers can re-enable external fonts with:
  add_filter( 'delicat_rewards_load_external_fonts', '__return_true' );

SHORTCODES
[delicat_rewards]
Customer monthly Free Fire rewards journey and secure claim interface.

[delicat_meilleur_client]
Weekly Meilleur Client leaderboard/podium.

ADMIN PAGES
WooCommerce > Delicat Rewards
- Tiers: earning rules, direct/PIN/server mappings, goals, PIN rewards.
- Produits: PIN fulfilment catalogue and denominations.
- Réglages: program status, weekly prizes, caps, notifications, texts and privacy.
- Nom & Logo: Rewards card branding.
- Rapports: claims, reward orders, CSV and customer simulator.
- Diagnostics: mappings, PIN composition and inventory checks.
- Journal: configuration audit trail.
- Participants: leaderboard/rewards exclusions.
- Rewards Licences: Admin Edition license server (3.4.2: only while a licence exists, or with
  define( 'DELICAT_REWARDS_LICENSE_SERVER', true ) in wp-config.php).

UPDATE-SAFE INSTALLATION
This ZIP keeps the original plugin folder and main file:
  delicat-rewards/delicat-rewards.php

Before installing:
1. Back up WordPress/database.
2. Deactivate older Delicat Rewards copies.
3. Upload the v3 ZIP in WordPress > Plugins > Add New > Upload Plugin.
4. Choose “Replace current with uploaded” when WordPress asks.
5. Activate Delicat Rewards.
6. Open WooCommerce > Delicat Rewards > Produits and verify every PIN fulfilment ID.
7. Open Tiers and add all direct-top-up/server product or variation IDs to the correct diamond tier.
8. Open Diagnostics and resolve every red warning before enabling real claims.
9. Use Mode test first if you want to verify customer progress without issuing rewards.

Do not activate two Delicat Rewards editions/copies on the same site.

MIGRATION FROM 2.9.0
- Existing custom product IDs/tier mappings are preserved.
- Rewards are forced to PIN-order fulfilment.
- Eligible order status is forced to COMPLETED and paid/refund protection remains mandatory.
- Built-in reward amounts are corrected only when they still match the old default values; intentionally customized reward values are not overwritten.
- Existing signed/legacy claim data is migrated and reconciled without resetting customer progress.


NEW IN 3.0.1 — INSTALLATION ASSISTANT
- New WooCommerce > Delicat Rewards > Installation tab.
- Live readiness checks for WooCommerce, PIN mappings, active tiers, diagnostics and test/live mode.
- One-click safe test configuration preserves product IDs and customer progress while forcing completed + paid + refund-safe + PIN-only + dry-run behavior.
- Exact deployment checklist and shortcode instructions are now visible inside WordPress.

RECOMMENDED FIRST OPEN AFTER UPDATE
WooCommerce > Delicat Rewards > Installation
1. Click the safe test configuration button.
2. Configure Produits (reward PIN catalogue).
3. Configure Tiers (all direct/PIN/server purchase IDs).
4. Add [delicat_rewards] and [delicat_meilleur_client] to the desired WordPress pages.
5. Test completed orders in dry-run.
6. Resolve every Diagnostics warning.
7. Disable Mode test only after verification.
