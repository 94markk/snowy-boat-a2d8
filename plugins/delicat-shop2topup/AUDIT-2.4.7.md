# Audit de correction — Delicat 2.4.8

## Résultat et périmètre

Audit du code de l’intégration fournie, de sa configuration de callback, du traitement des commandes, des protections contre les achats répétés, du planificateur, des limites de paiement, des réponses API, du catalogue, de la confidentialité et des actions administrateur. Les captures montrent un échec de validation de l’URL chez le fournisseur et une tâche de rapprochement en retard. Elles ne contiennent pas la réponse brute expliquant toutes les erreurs INVALID_RESPONSE.

Les défauts ci-dessous ont été corrigés et testés localement. Ce travail ne constitue pas une certification d’absence de tout bug. Le serveur WordPress actif, son pare-feu, ses identifiants fournisseur, sa configuration cron et une transaction réelle n’étaient pas accessibles à cette validation.

## Défauts corrigés

| Défaut constaté dans le code | Correction |
|---|---|
| La sonde d’enregistrement privée était rejetée dès qu’un secret HMAC existait. | Séparer la validation de disponibilité de l’authentification des événements. Les sondes sans événement de commande reçoivent 200 et processed=false ; les événements sans signature et les signatures fausses restent refusés. |
| Aucun raccordement au filtre d’exception REST du module de maintenance Delicat. | Exception limitée à la route exacte activée. Une option explicite bloquant les callbacks est respectée. Les autres routes restent verrouillées. |
| Le worker courant était considéré comme un successeur déjà planifié. | Rechercher seulement les tâches pending, puis planifier sans le verrou d’unicité qui inclut les tâches en cours. Même correction pour les lots d’import. |
| Une erreur d’insertion Action Scheduler pouvait perdre un réveil. | Repli sur WP-Cron ; le callback signale 503 si aucune file ne peut conserver le travail. |
| Le callback pouvait déclencher des appels API et des emails avant sa réponse. | Authentifier puis mettre le rapprochement en file. La réponse ne dépend plus de l’appel fournisseur ou de l’envoi d’email. |
| Le test local pouvait réussir sur une page HTML HTTP 200 et apparaître comme un test fournisseur. | Exiger le JSON attendu, séparer les dates du test local, de la validation URL et du test fournisseur. Un test local n’efface plus l’alerte de signature distante. |
| Une commande payée placée en attente par le plugin perdait son éligibilité à la complétion automatique. | Conserver la preuve de l’état payé avant la mise en attente et reconnaître la date de paiement des anciennes commandes. Les commandes impayées ne sont pas complétées. |
| Une relance admin pouvait forcer une commande en attente non payée à processing et concurrencer un worker. | Vérifier le paiement et relire la ligne sous le verrou de commande utilisé pour les achats et les règlements. |
| Une livraison déjà acceptée pouvait perdre cette information lors d’une erreur de récupération. | Marqueur persistant d’acceptation ; les récupérations ultérieures restent en lecture seule. Une réponse batch not_found ne réécrit plus un état qui a pu avancer en parallèle. |
| Les réponses de commande ou de lot étaient trop peu validées. | Vérifier la référence demandée, l’état reconnu, la complétude du lot et les contradictions entre orders et not_found. |
| Un catalogue malformé pouvait être interprété comme un catalogue vide. | Rejeter les listes manquantes et les articles sans identité ; interrompre le lot de synchronisation sur INVALID_RESPONSE. Une liste explicitement vide reste valide. |
| Des produits WooCommerce distincts pouvaient fusionner dans le panier à cause d’un même produit fournisseur. | Exiger aussi les mêmes identifiants WooCommerce de produit et de variation avant la fusion. |
| La protection du chemin des ressources utilisait un simple préfixe. | Vérifier la limite de répertoire avec le séparateur. |
| L’alerte cron excluait les sites disposant d’Action Scheduler. | Afficher le besoin d’un déclencheur même avec Action Scheduler ; ajouter un secours admin authentifié, limité à un passage par minute. |

Le rapprochement batch conserve un délai minimal entre appels et respecte Retry-After. Les contrôles de signature HMAC sur le corps brut, HTTPS avec vérification TLS, références UUID existantes, garde de coût, chiffrement des secrets et vouchers, droits administrateur et nonces restent actifs. Aucun nouveau remboursement client automatique n’a été ajouté.

## Vérification exécutée

- PHP 8.3.6 : **198 tests comportementaux isolés réussis**, avec doubles WordPress/WooCommerce et fournisseur ; aucune requête fournisseur réelle.
- **3 contrôles supplémentaires réussis** contre le code du module de maintenance Delicat Builder pro.37 disponible localement : callback exact autorisé, route WooCommerce toujours bloquée, option explicite de blocage respectée. Cela ne confirme pas la version actuellement active sur le serveur.
- **27 fichiers PHP** : analyse syntaxique réussie.
- **2 fichiers JavaScript** : analyse syntaxique Node réussie.
- Aucun avertissement ou erreur PHP dans les exécutions enregistrées.
- Structure du ZIP et intégrité contrôlées après génération.

Le fichier tests/regression.php et le journal VALIDATION-2.4.8.txt permettent de revoir les cas testés. Commande : `php tests/regression.php` depuis le dossier du plugin. Les doubles de test ne remplacent pas une validation sur un vrai WordPress avec les extensions actives.

## Vérification restant sur le site

Suivre INSTALLATION-2.4.8.md : enregistrer l’URL, envoyer le test signé depuis le fournisseur, puis synchroniser les anciennes commandes en conservant leurs références. Confirmer le fonctionnement du cron quand le tableau de bord est fermé. Si une réponse API reste invalide, son diagnostic exact reste nécessaire ; aucune enveloppe inconnue n’est assimilée à une livraison réussie.

## Références techniques consultées

- https://shop2topup.com/en/reseller-api — corps brut signé, délai de réponse et événements.
- https://shop2topup.com/en/reseller-api/docs/orders — identifiant idempotent et contrat des réponses.
- https://shop2topup.com/en/reseller-api/docs/catalog — prix et listes du catalogue.
- https://actionscheduler.org/api/ — as_has_scheduled_action inclut pending et in-progress ; as_get_scheduled_actions permet le filtre pending.
