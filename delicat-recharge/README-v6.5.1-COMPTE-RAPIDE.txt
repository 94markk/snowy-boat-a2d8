Delicat Recharge Admin 6.5.1 — Compte plus rapide et carte Récompenses

(1) Pages du compte plus rapides : la feuille de style du portefeuille (286 Ko), form.js (avec
jQuery) et le style « glass » ne sont plus chargés sur les rubriques qui n’affichent jamais le
portefeuille : détail de commande, liste des commandes, téléchargements, adresses, profil, mot
de passe, sécurité et sessions, ni sur l’accueil du compte quand Delicat Identity est actif.
Ils restent chargés sur la page Mon portefeuille (/my-wallet/), l’onglet portefeuille du compte
et partout où un shortcode Delicat Recharge est affiché.
(2) Accueil du compte (Delicat Identity 6.15.2) : carte « Fidélité » avec le bonus du client
(paliers configurés) et ce qu’il reste à recharger pour le palier suivant ; sans paliers, carte
« Parrainage » avec le bonus de parrainage. Lecture seule, données du seul client connecté,
une requête par affichage. Rien n’apparaît tant que les fonctions de croissance sont désactivées.

Technique : delicat_recharge_account_needs_wallet_ui() (filtre
delicat_recharge_wallet_ui_skip_endpoints pour ajuster la liste des rubriques), filtre
dip_account_rewards fourni par Delicat_Growth::account_rewards(). Aucune modification de la
base, des paiements, des approbations ni des crédits.
Installer cette archive en remplacement de l’extension, puis purger LiteSpeed / Cloudflare.
