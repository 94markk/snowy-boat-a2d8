DELICAT RESELLERS 1.1.1 — PROGRAMME REVENDEUR

NOUVEAUTÉS 1.1.1 — CORRECTIFS DE L'AUDIT DU 5 NOVEMBRE 2026 (RSL-02 À RSL-04)
(1) Remise en % seulement dans les catégories choisies (RSL-02). La remise d'un niveau (Réglages, en %)
  ne s'applique plus qu'aux produits rangés dans une catégorie cochée dans Réglages › « Catégories avec
  remise revendeur ». Avant, elle s'appliquait à tout produit sans prix revendeur, y compris USDT,
  PayPal, Zelle, Wise… (5 % de 100 000 HTG = 5 000 HTG perdus). Un prix revendeur saisi sur un produit
  s'applique toujours, dans toutes les catégories ; « Pas de prix revendeur » l'emporte toujours.
(2) Recharge annulée (RSL-03). Si une recharge qui a qualifié un revendeur (dépôt de la demande) ou qui
  lui a fait garder son niveau le mois dernier est annulée ou remboursée dans Delicat Recharge, le niveau
  est revu tout de suite : il baisse au niveau que les recharges restantes atteignent (jamais sous le
  niveau qu'il avait avant de monter), ou le revendeur sort du programme. E-mail au revendeur et à
  l'équipe, ligne « Niveau revu : recharge annulée » dans l'Historique. Delicat Revendeurs ne touche
  jamais aux portefeuilles. Un niveau changé à la main par l'équipe n'est pas revu automatiquement.
(3) Outils › Exporter / Effacer les données personnelles (RSL-04). L'export contient le niveau, les
  demandes (activité, téléphone WhatsApp, ville, comment le client vend, volume, note de l'équipe) et
  l'historique. L'effacement vide ces informations des demandes (une demande en cours est annulée) et
  les motifs de refus ; les lignes restent (niveau, dates, montants), car l'historique et les commandes
  à prix revendeur y renvoient. Supprimer le compte supprime toujours tout.
(4) Technique : options dlr_cats_migrated et dlr_cats_review ; réglage discount_cats dans dlr_config ;
  événements « reversed » et « erased » dans l'historique. Aucune table modifiée. Les prix revendeur ne
  sont jamais gardés dans un cache partagé par l'extension elle-même (seulement le cache des prix des
  variations de WooCommerce, séparé par niveau).
(5) Réglage modifié à la mise à jour : « Catégories avec remise revendeur » est rempli une fois, à la
  première visite après la mise à jour : toutes vos catégories sont cochées, sauf celles dont le nom
  parle d'échange ou de finance (USDT, crypto, PayPal, Zelle, Wise, Payoneer, Skrill, Cash App, échange,
  transfert, Binance, Perfect Money, finance, MonCash, NatCash). Un message en haut de l'administration
  liste les catégories laissées de côté et les produits au nom d'échange rangés ailleurs : vérifiez,
  cochez ou décochez dans Réglages, puis « J'ai vérifié ». Une nouvelle catégorie n'est jamais cochée
  toute seule.
(6) Installation : WordPress › Extensions › Ajouter › Téléverser : delicat-resellers-1.1.1.zip, remplacer
  la version existante. Les données et les réglages sont conservés. Ouvrez ensuite WooCommerce › Delicat
  Revendeurs › Réglages pour vérifier les catégories cochées.

NOUVEAUTÉS 1.1.0
- Mon compte › Programme Revendeur : la demande, son avancement, le niveau et les dépôts du mois sont
  maintenant dans l'espace client (adresse …/mon-compte/revendeur/). La ligne apparaît dans « Mes services »
  avec une icône de boutique (Delicat Identity 6.17.4) tant que le programme est ouvert, et toujours pour un
  revendeur. Les formulaires ramènent le client sur cette page.
- La carte du badge sur l'accueil du compte mène à cette page.
- La page avec le shortcode [delicat_revendeur] reste possible ; sans elle, les liens (e-mails, assistant)
  mènent à la page du compte.
- À l'installation, les adresses de WordPress sont reconstruites une fois (aucun réglage à faire).


Trois niveaux de revendeur, chacun avec un dépôt minimum par mois :
  Premium Reseller   100 000 HTG
  Golden Reseller     50 000 HTG
  Silver Reseller     25 000 HTG
(noms, minimums et remises modifiables dans WooCommerce › Delicat Revendeurs › Réglages)

COMMENT ÇA MARCHE
1. Le client remplit la demande sur la page Programme Revendeur (shortcode [delicat_revendeur]) :
   niveau souhaité, nom ou activité, téléphone WhatsApp, ville, comment il vend, ventes par mois.
   Un visiteur non connecté voit les niveaux et un bouton pour se connecter.
2. L'équipe examine la demande (WooCommerce › Delicat Revendeurs › Demandes) : Approuver ou Refuser.
   Pour aider : âge du compte, commandes, recharges des 90 derniers jours, envois reçus d'autres
   portefeuilles sur 30 jours (en rouge).
3. Approuvée : le client reçoit un e-mail et a 7 jours (réglable) pour recharger au moins le minimum
   du niveau, UNIQUEMENT par le système de recharge du site (Delicat Recharge : MonCash, NatCash,
   manuel). Comptent les recharges envoyées après l'approbation et avant la date limite, une fois
   approuvées et créditées ; plusieurs recharges s'additionnent. Une recharge envoyée à temps mais
   encore en vérification compte quand l'équipe la valide : la demande l'attend (10 jours au plus).
   Ne comptent pas : une recharge refusée ou remboursée, une recharge envoyée après la date limite,
   une entrée « bloquer le code » faite par l'équipe (argent reçu hors du site, rien mis dans le
   portefeuille), une ligne sans compte client.
4. Si le client reçoit de l'argent d'un autre portefeuille pendant cette étape (Envoyer de l'argent de
   Delicat, ou le « Transfert » de TeraWallet), la demande est refusée automatiquement (e-mail au
   client et à l'équipe). Il peut refaire une demande plus tard.
5. Dès que le dépôt atteint le minimum : badge du niveau sur le compte, prix revendeur sur le site,
   e-mail de bienvenue. Délai dépassé sans dépôt complet : la demande expire (e-mail au client).
6. Chaque mois, le revendeur doit recharger au moins le minimum de son niveau. Le mois est compté le 3
   du mois suivant (le temps que l'équipe valide les recharges du dernier soir), ou plus tard si une
   recharge envoyée pendant ce mois est encore en vérification (jusqu'au 10 au plus tard). Alors :
   - minimum atteint : il garde son niveau ;
   - sinon il descend au niveau que ses recharges atteignent (ex. un Premium qui a rechargé 60 000 HTG
     devient Gold) ;
   - sous le minimum Silver : le badge et les prix revendeur sont retirés (il peut refaire une demande).
   Le mois de l'activation compte comme réussi (grâce au dépôt d'activation). Une recharge annulée ou
   remboursée après coup fait revoir le niveau aussitôt (1.1.1). À partir du 22 du mois
   (réglable), un e-mail rappelle ce qu'il manque. Un e-mail annonce chaque baisse ou retrait.
7. Pour monter de niveau, le revendeur fait une nouvelle demande depuis sa page (même étape de dépôt).
   Le mois précédent est d'abord compté au niveau qu'il avait, puis le nouveau niveau commence.
8. Programme fermé (Réglages) : pas de prix revendeur ni de nouvelle demande. Les mois où il est resté
   fermé ne sont pas dus quand il rouvre.

PRIX REVENDEUR
- Sur chaque produit (onglet Général) et chaque variation : un prix Premium, Gold et Silver.
- Un produit sans prix pour un niveau utilise la remise du niveau (Réglages, en %), seulement s'il est
  rangé dans une « catégorie avec remise revendeur » (Réglages). 0 % = prix normal.
- Case « Pas de prix revendeur » sur un produit : prix normal pour tous.
- Le produit de recharge du portefeuille n'a jamais de prix revendeur.
- Si une promotion normale est plus basse que le prix revendeur, la promotion s'applique.
- Calculateur Delicat Builder : les options payantes gardent leur prix, seul le prix de base change.
  Si la formule ne part pas du prix de base, le total reçoit la même réduction en pourcentage que le
  produit (jamais plus) ; une ligne payante ne devient jamais gratuite.
- Prix tapé à la main pour une autre devise (sélecteur de devise de Delicat Builder) : même réduction
  en proportion. Par défaut, Builder fait payer le panier et le portefeuille en HTG.
- Le revendeur connecté voit par exemple « 120 G  Prix Golden Reseller  au lieu de 150 G ».
- Les commandes passées comme revendeur portent son niveau et ne comptent pas pour les bonus clients
  (Bonus Free Fire, Meilleur client, Parrainage — il faut Delicat Rewards 3.4.1). Les commandes d'avant
  l'activation ou d'après la sortie du programme restent des commandes clients normales.

ÉQUIPE (WooCommerce › Delicat Revendeurs)
- Demandes : Approuver / Refuser (avec note) ; Dépôt attendu (progression, Vérifier maintenant, Refuser).
- Revendeurs : niveau, dépôts du mois, changer de niveau, retirer, ajouter un revendeur déjà connu
  par son e-mail (sans dépôt de qualification ; le minimum s'applique dès le mois suivant).
- Historique : demandes, approbations, refus, activations, baisses de niveau, retraits, mois non dus
  (programme fermé).
- Réglages : ouvrir/fermer le programme, noms, minimums, remises, délai de dépôt, jour du rappel,
  pages, e-mail de l'équipe.
- Colonne « Revendeur » dans la liste des utilisateurs.

TECHNIQUE
- Tables : {préfixe}delicat_reseller_apps, {préfixe}delicat_reseller_log. Rôles : dlr_premium,
  dlr_gold, dlr_silver (ajoutés à côté de « client », pour que les caches par rôle séparent les prix).
- Lit les recharges de Delicat Recharge ({préfixe}delicat_recharges) et les envois entre portefeuilles
  ({préfixe}delicat_transfers, et l'action TeraWallet woo_wallet_transfer_amount_credited). Ne modifie
  ni les recharges, ni les portefeuilles, ni les commandes passées.
- Tâche quotidienne dlr_daily (WP-Cron, remise en place si elle disparaît), plus une vérification quand
  le revendeur visite le site. Délais réglables par filtre : dlr_grace_days (3), dlr_review_days (10).
- La page revendeur est personnelle : jamais mise en cache pour d'autres visiteurs.
- Désactiver l'extension garde les données ; les rôles restent mais les prix revendeur s'arrêtent.
