Delicat Recharge Admin 6.5.2 — Envoi instantané et base plus rapide

(1) « Envoyer » répond tout de suite sur Hostinger : les e-mails, notifications Android et webhooks
partent après la réponse au client. Avant, sur un serveur LiteSpeed (Hostinger), la page attendait
leur envoi (souvent 1 à 3 secondes, parfois plus de 15) ; c’est maintenant le cas aussi pour les
alertes de sécurité, d’envoi d’argent, du SMS Guard, du forwarder et des validations en masse.
(2) Recharge validée tout de suite : quand la réponse contient déjà le nouveau solde, la page
n’interroge plus le serveur toutes les 2,5 s pendant 2 minutes (environ 48 requêtes en moins par
recharge). En attente : interrogation suspendue quand l’onglet est caché, une seule requête à la fois.
(3) Vérification « TransCode déjà utilisé » indexée : nouvelle colonne code_norm (copie normalisée du
TransCode, remplie automatiquement pour les demandes existantes) et index sur la date et l’état.
Chaque contrôle lit une seule ligne au lieu de toute la table : envoi d’une demande, SMS reçus,
application, liste de l’administration (200 contrôles par page). Même normalisation qu’avant
(espaces et tirets retirés, majuscules) ; le registre des TransCodes dépensés (clé unique) reste la
garantie « un code = un crédit ». Si la colonne ne peut pas être ajoutée, l’ancien contrôle est gardé.
(4) Alerte « Stock faible » (toutes les heures) : seuls les produits à 5 pièces ou moins sont chargés,
au lieu de tout le catalogue en stock.
(5) Moins de travail à chaque page du site : la clé du tableau de bord, le nettoyage des anciens
blocages et le dossier privé des preuves ne sont plus vérifiés sur chaque requête (seulement dans
l’administration ou au moment d’un envoi de preuve). L’application d’administration ne réécrit plus
la liste des appareils à chaque appel (au plus toutes les 5 minutes).
(6) Les secrets (compte de service Firebase, clé MonCash, secret du webhook) ne sont plus chargés en
mémoire sur chaque page ; ils sont lus seulement quand un paiement ou un envoi en a besoin.

Technique : delicat_recharge_finish_request() (fastcgi_finish_request ou litespeed_finish_request,
ignore_user_abort) en tête de chaque tâche « shutdown » ; Delicat_Database::code_sql() (colonne
code_norm quand delicat_code_norm_ready = 1, sinon l’expression REPLACE/UPPER/TRIM d’avant) ;
DELICAT_VERSION 6.5.2 déclenche une seule fois la mise à jour de la table (dbDelta), testée sur
MariaDB 10.11 et SQLite. Aucune modification des montants, des approbations ni des crédits.
Installer cette archive en remplacement de l’extension, puis purger LiteSpeed / Cloudflare.
