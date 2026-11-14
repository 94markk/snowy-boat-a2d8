DELICAT REWARDS — ADMIN EDITION 4.1.0

Programme de fidélité de Delicat Store (WooCommerce, devise HTG, portefeuille Delicat Wallet /
TeraWallet). Une page client, trois onglets : « Classement » (Meilleur Client de la semaine),
« Mes bonus » (bonus mensuels Free Fire) et « Parrainage » (inviter un ami).

============================================================
1. CE QUE FAIT L'EXTENSION
============================================================
- Bonus mensuels (« Mes bonus ») : chaque pack Free Fire acheté (PIN, top-up direct, ancien
  fournisseur) fait avancer son bonus. Objectif atteint : le client touche « Réclamer » et le
  montant HTG est ajouté à son Delicat Wallet. À réclamer avant 00:00 le 1er du mois suivant
  (fuseau de WordPress). Seules les commandes payées, terminées, non remboursées, d'un compte
  client (pas invité, revendeur ni équipe) comptent ; une ligne gratuite ne compte pas.
- Meilleur Client (« Classement ») : score de la semaine = 70 % montant net dépensé + 30 %
  commandes distinctes. Les clients voient seulement des pourcentages et des noms masqués.
  Le classement est recalculé en arrière-plan (actions planifiées WooCommerce, sinon WP-Cron).
  Prix des places 1 à 3 : à réclamer en HTG dans les 5 jours suivant leur mise à disposition.
- Parrainage : code personnel DLC…… et lien ; 1 point par ami qui crée son compte et paie un
  premier achat réel ; paliers à réclamer une fois (diamants PIN ou crédit HTG) ; contrôles
  anti-fraude (même téléphone, numéro de paiement, e-mail, réseau, VPN, plafond de 15 % des
  achats des filleuls, validation par l'équipe des gros paliers). Désactivé à l'installation.
- Anciennes récompenses PIN (avant 4.0.0) : toujours consultables dans « Mes récompenses ».

Shortcodes : [delicat_bonus_free_fire] (option tab="bonus" | "classement" | "parrainage",
title="" pour cacher le titre). [delicat_meilleur_client] et [delicat_rewards] ouvrent la même
page sur leur onglet. Liens directs : …/page/#mes-bonus, #classement, #parrainage.

Tableau de bord : WooCommerce › Delicat Rewards (Installation, Tiers, Meilleur client,
Parrainage, Produits, Réglages, Nom & Logo, Rapports, Diagnostics, Journal), Delicat
Participants, et « Rewards Licences » seulement si le serveur de licences sert déjà.

============================================================
2. SÉCURITÉ (RÉSUMÉ)
============================================================
- Le navigateur n'est jamais cru : progression et droits sont recalculés sur le serveur.
- Réclamation d'un bonus : compte connecté + nonce, limite de demandes, verrou MySQL par
  client et par mois, registre signé (HMAC), jeton signé de 30 minutes lié au nombre de
  réclamations, une case unique en base par numéro de réclamation, et répétition exacte
  d'une demande perdue = la même récompense (jamais une seconde).
- Crédit HTG : un marqueur unique en base (delicat_wallet_once_<commande>) est écrit AVANT
  l'appel à TeraWallet ; une réponse douteuse n'est jamais répétée (« Crédits à vérifier »).
- Formulaires du tableau de bord : droit manage_woocommerce + nonce + référent.
- Clé de signature : définir dans wp-config.php une clé dédiée d'au moins 32 caractères :
  define( 'DELICAT_REWARDS_HMAC_KEY', '…' ); — un changement des salts WordPress ne gèle
  alors plus les bonus. Ancienne clé gardée valable : DELICAT_REWARDS_HMAC_PREVIOUS_KEYS.
- VPN (parrainage) : define( 'DELICAT_PROXYCHECK_KEY', '…' ); dans wp-config.php.

============================================================
3. INSTALLATION / MISE À JOUR
============================================================
1) WordPress › Extensions › Ajouter › Téléverser le ZIP, « Remplacer la version installée ».
   Garder le dossier delicat-rewards. Réglages, registres, commandes et tables sont conservés.
2) TeraWallet / Delicat Wallet actif et WooCommerce en HTG.
3) Réglages › « Récompenses en argent — Delicat Wallet » : vérifier chaque montant HTG
   (vide = prix WooCommerce du produit de référence ; un prix absent bloque la réclamation).
4) Désactiver le Mode test pour les crédits réels. Vérifier le fuseau (Réglages › Général).
5) Purger LiteSpeed Cache et Cloudflare après chaque mise à jour.
6) Si l'heure du « Classement Meilleur client » (Diagnostics) n'avance pas alors que des
   commandes arrivent : demander à l'hébergeur une tâche cron sur wp-cron.php toutes les 5 min.

Fichiers de la page : assets/bonus.js et assets/bonus.css sont générés depuis assets/src/
(sources lisibles, à modifier en premier) :
  npx esbuild@0.21.5 --loader=js --minify --target=es2019 < assets/src/bonus.js > assets/bonus.js
  npx esbuild@0.21.5 --loader=css --minify --target=chrome58 < assets/src/bonus.css > assets/bonus.css

Contrat application (Delicat App API) : fonctions delicat_rewards_app_*() (app-contract.php),
dont delicat_rewards_app_winner_rewards() et delicat_rewards_app_winner_claim(order_id, token).
Routes du site : delicat/v1 /rewards, /claim, /reward-pins, /leaderboard, /winner-rewards,
/winner-claim, /referral, /referral/claim, /referral/code (connexion + nonce obligatoires).

============================================================
4. JOURNAL DES MODIFICATIONS
============================================================

4.1.0 — 14 novembre 2026 — AUDIT : SÉCURITÉ, VITESSE, CORRECTIFS
(1) Page Bonus Free Fire sur les téléphones modestes et les navigateurs de Facebook/TikTok.
  - Sur un Android dont le navigateur intégré (WebView) n'est plus mis à jour (avant Chrome 86),
    la page restait vide ou figée : elle s'affiche maintenant normalement.
  - Couleurs, bordures et badges gardent une couleur correcte sur les anciens navigateurs
    (avant, certaines bordures et fonds disparaissaient). Thème sombre appliqué partout.
  - Plus fluide : plus d'effet de flou derrière les cartes et la fenêtre de récompense (coûteux
    sur les petits téléphones) ; confettis plus légers, deux fois moins nombreux sur les
    téléphones de 2 Go de mémoire ou moins, aucun en mode économie d'énergie du site.
  - Après la fin du mois, les boutons « Bonus expiré » ne sont plus réécrits chaque seconde.
(2) Chargement plus rapide (serveur).
  - Un client qui n'a jamais été sur le podium n'entraîne plus de recherche de commandes à
    chaque ouverture de la page ; les noms du classement et des derniers gagnants sont lus en
    une fois ; le classement ne vérifie plus la file des tâches à chaque visite.
  - « Mes récompenses » : chaque commande de la liste n'est plus relue une seconde fois, et une
    récompense déjà créditée ne demande plus de lecture du registre des versements.
  - delicat-rewards.php passe de 270 Ko à 146 Ko : les écrans du tableau de bord ne sont plus
    chargés pour les visiteurs, l'application, le REST, l'AJAX ni les tâches planifiées.
(3) Corrections visibles.
  - Récompense Meilleur Client à réclamer : après l'ajout de DELICAT_REWARDS_HMAC_KEY (conseillé
    par Diagnostics), elle restait bloquée sur « portefeuille temporairement indisponible ».
    Toutes les clés acceptées sont maintenant vérifiées, comme pour les registres.
  - Une récompense créditée dont la commande n'avait pas été mise à jour (interruption) s'affiche
    « créditée » au lieu de « vérification en cours ».
  - E-mail « récompense créditée » : le retour à la ligne s'affichait « \n ».
  - Application : la réponse d'une réclamation en attente parle du crédit HTG (elle annonçait
    encore un PIN en diamants).
  - Rapports : du 29 au 31 du mois, la liste des mois sautait février et doublait le mois en
    cours ; l'export des commandes récompense suit le mois du site (fuseau), pas celui du serveur.
  - « Préparer les résultats de la semaine précédente » ne prépare jamais de prix pour une
    période réinitialisée sans gagnant.
(4) Technique.
  - Nouveaux fichiers : rewards-admin.php (tableau de bord, chargé seulement dans wp-admin, y
    compris admin-post.php, et WP-CLI) ; referral-admin.php suit la même règle ;
    rewards-legacy.php (CSS des pages d'avant 3.3, chargé seulement si un thème appelle encore
    delicat_print_css()).
  - Réponses REST personnelles : action litespeed_control_set_nocache + « no-store, private »
    (/rewards compris) : LiteSpeed Cache ne peut pas les garder.
  - Calcul du classement : comptes clients chargés par page de commandes (2 requêtes au lieu de
    2 par client) ; seules les commandes (pas les objets remboursement) sont lues.
  - Relance horaire de la remise hebdomadaire : ne relit plus les commandes de la semaine quand
    le podium est figé.
  - Nettoyage quotidien des cases de réclamation : seuls les mois passés sont lus (500 cases du
    mois en cours pouvaient cacher les anciennes).
  - Option chargée automatiquement delicat_rewards_upgraded : les vérifications de mise à jour
    déjà faites ne relisent plus deux options à chaque requête. Transient delicat_cfg_problems :
    l'avis du tableau de bord réutilise son contrôle 10 minutes (Diagnostics contrôle en direct).
  - CSS : color-mix() dans @supports avec couleur simple avant ; :is() écrit en entier ; inset
    écrit en top/right/bottom/left ; dvh et clamp() avec une valeur simple d'abord. JS : ES2019 ; replaceChildren() et
    Promise.finally() complétés seulement s'ils manquent ; plus besoin d'AbortController,
    isConnected ni padStart() (navigateurs Android anciens).
(5) Fichiers supprimés : assets/rewards.js et assets/rewards.css (ancienne page « Mes
  récompenses » d'avant 3.3, en PIN). delicat_rewards_view() affiche maintenant la page Bonus
  Free Fire sur « Mes bonus », comme [delicat_rewards] depuis 3.3.0. README raccourci.
(6) Compatibilité : options, métadonnées, tables, routes REST et leurs réponses, actions AJAX,
  shortcodes et hooks inchangés. Ajouts seulement : option delicat_rewards_upgraded, transient
  delicat_cfg_problems, champs reward_type / credit_amount / currency dans la réponse
  « en attente » de l'application.
(7) Validation : php -l sur tous les fichiers ; node --check sur les scripts ; tests en ligne de
  commande avec WordPress simulé (signature sous plusieurs clés, montant modifié refusé, client
  hors podium, historique crédité, nettoyage des cases, noms du classement, liste des mois,
  chargement front / admin / AJAX). Aucun test sur le serveur Delicat Store.

4.0.0 — 10 novembre 2026 — BONUS ET CLASSEMENT EN HTG
- Bonus mensuels et prix Meilleur Client crédités en HTG dans Delicat Wallet (TeraWallet) au clic
  « Réclamer » ; plus de PIN pour ces deux programmes. Montant = prix WooCommerce HTG du produit de
  référence, ou montant fixe saisi dans Réglages (ex. 110 diamants = 145 HTG).
- Bonus du mois à réclamer avant le 1er du mois suivant ; prix du podium dans les 5 jours.
- Un marqueur unique en base avant chaque crédit : jamais deux versements, même après une réponse
  perdue ; « Crédits à vérifier » recherche une transaction existante sans payer de nouveau.
- Un podium « à vérifier » après remboursement bloque le crédit jusqu'à la décision de l'équipe.
- Application : reward_type=wallet, credit_amount, currency=HTG, transaction_id, expires_at.

3.4.2 — 5 novembre 2026 — AUDIT RWD-01 À RWD-05
- Parrainage : plafond des récompenses (15 % des achats des filleuls), achat payé avec le numéro
  du parrain ou d'un autre filleul mis « À vérifier », paliers de 35 points ou plus payés après
  validation de l'équipe.
- Meilleur Client : remboursement après la fin de la semaine → semaine recalculée, prix marqués
  « à vérifier » et e-mail (rien n'est repris automatiquement) ; classement calculé en
  arrière-plan, plus pendant la visite d'un client ; montant minimum d'une commande (appliqué à
  partir de la semaine suivante).
- Serveur de licences inactif tant qu'aucune licence n'existe (DELICAT_REWARDS_LICENSE_SERVER).
- Diagnostics ne s'arrête plus sur un remboursement récent.

3.4.1 — Parrainage : le premier achat d'un ami doit être un achat client (pas revendeur, pas équipe).

3.4.0 — Parrainage « Inviter un ami » : code et lien personnels, partage WhatsApp / Facebook /
  Telegram / SMS, paliers, contrôles anti-fraude (empreintes chiffrées seulement), tables
  {préfixe}delicat_referrals, delicat_ref_claims, delicat_ref_signals. Limites de demandes par
  visiteur derrière Cloudflare.

3.3.0 — Page « Bonus Free Fire » (Classement + Mes bonus) en cache, une seule demande personnelle
  au chargement ; une ligne gratuite ne fait plus progresser ; une réclamation = un numéro unique
  en base ; licences : clé en minuscules et nonce signé ; noms jamais tirés de l'identifiant.

3.2.4 — Fournisseurs multiples : anciennes associations produits mémorisées ; transport AJAX
  restreint quand l'hébergeur bloque le REST (erreurs 403).
3.2.3 — Contrat application (delicat_rewards_app_*). 3.2.2 — Synchronisation Secure Keys.
3.2.1 — Semaines calculées en heure réelle (fuseau), registres re-scellables (Diagnostics).
3.2.0 — Onglet Meilleur client : clôture, réinitialisation, remise manuelle, historique.
3.1.x — Score 70 % dépense + 30 % commandes ; les clients ne voient que des pourcentages.
3.0.x — Achats PIN et top-up direct comptés ; registre signé, verrou par client et par mois ;
  assistant d'installation et configuration de test sécurisée.
2.x — Configuration dans le tableau de bord, e-mails, rapports CSV, mode test, compteurs signés
  HMAC, produits variables, podium 2e/3e place.
