DELICAT RESELLERS 1.0.0 — PROGRAMME REVENDEUR

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
   manuel). Seules les recharges faites après l'approbation, approuvées et créditées, comptent ;
   une recharge refusée ou remboursée ne compte pas. Plusieurs recharges s'additionnent.
4. Si le client reçoit de l'argent d'un autre portefeuille Delicat (Envoyer de l'argent) pendant cette
   étape, la demande est refusée automatiquement (e-mail au client et à l'équipe). Il peut refaire une
   demande plus tard.
5. Dès que le dépôt atteint le minimum : badge du niveau sur le compte, prix revendeur sur le site,
   e-mail de bienvenue. Délai dépassé sans dépôt complet : la demande expire (e-mail au client).
6. Chaque mois, le revendeur doit recharger au moins le minimum de son niveau. Au début du mois suivant :
   - minimum atteint : il garde son niveau ;
   - sinon il descend au niveau que ses recharges atteignent (ex. un Premium qui a rechargé 60 000 HTG
     devient Gold) ;
   - sous le minimum Silver : le badge et les prix revendeur sont retirés (il peut refaire une demande).
   Le mois de l'activation compte comme réussi (grâce au dépôt d'activation). À partir du 22 du mois
   (réglable), un e-mail rappelle ce qu'il manque. Un e-mail annonce chaque baisse ou retrait.
7. Pour monter de niveau, le revendeur fait une nouvelle demande depuis sa page (même étape de dépôt).

PRIX REVENDEUR
- Sur chaque produit (onglet Général) et chaque variation : un prix Premium, Gold et Silver.
- Un produit sans prix pour un niveau utilise la remise du niveau (Réglages, en %). 0 % = prix normal.
- Case « Pas de prix revendeur » sur un produit : prix normal pour tous.
- Le produit de recharge du portefeuille n'a jamais de prix revendeur.
- Si une promotion normale est plus basse que le prix revendeur, la promotion s'applique.
- Les options payantes du calculateur Delicat Builder gardent leur prix ; seul le prix de base change.
- Le revendeur connecté voit par exemple « 120 G  Prix Golden Reseller  au lieu de 150 G ».
- Les commandes des revendeurs portent leur niveau. Un revendeur ne participe pas aux bonus clients
  (Bonus Free Fire, Meilleur client, Parrainage — il faut Delicat Rewards 3.4.1) tant qu'il est dans le
  programme ; ses achats faits comme revendeur restent exclus même après. Les bonus déjà donnés restent.

ÉQUIPE (WooCommerce › Delicat Revendeurs)
- Demandes : Approuver / Refuser (avec note) ; Dépôt attendu (progression, Vérifier maintenant, Refuser).
- Revendeurs : niveau, dépôts du mois, changer de niveau, retirer, ajouter un revendeur déjà connu
  par son e-mail (sans dépôt de qualification ; le minimum s'applique dès le mois suivant).
- Historique : demandes, approbations, refus, activations, baisses de niveau, retraits.
- Réglages : ouvrir/fermer le programme, noms, minimums, remises, délai de dépôt, jour du rappel,
  pages, e-mail de l'équipe.
- Colonne « Revendeur » dans la liste des utilisateurs.

TECHNIQUE
- Tables : {préfixe}delicat_reseller_apps, {préfixe}delicat_reseller_log. Rôles : dlr_premium,
  dlr_gold, dlr_silver (ajoutés à côté de « client », pour que les caches par rôle séparent les prix).
- Lit les recharges de Delicat Recharge ({préfixe}delicat_recharges) et les envois entre portefeuilles
  ({préfixe}delicat_transfers). Ne modifie ni les recharges, ni les portefeuilles, ni les commandes passées.
- Tâche quotidienne dlr_daily (WP-Cron), plus une vérification quand le revendeur visite le site.
- La page revendeur est personnelle : jamais mise en cache pour d'autres visiteurs.
- Désactiver l'extension garde les données ; les rôles restent mais les prix revendeur s'arrêtent.
