# Installation et vérification — 2.4.8

1. Sauvegardez le plugin et la base de données. Dans WordPress → Extensions → Ajouter une extension → Téléverser, installez le ZIP et remplacez la version existante. Ne désinstallez pas l’ancienne version et ne recréez pas les commandes clients.
2. Ouvrez le tableau de bord Delic. Gardez l’URL privée actuelle : cette mise à jour ne change ni cette URL, ni vos clés, ni les références de commande.
3. Cliquez sur « Test this URL from here ». Le contrôle exige maintenant la réponse JSON du plugin : une page de maintenance avec HTTP 200 ne suffit plus. Un contrôle local réussi ne prouve pas encore que le fournisseur peut accéder au site.
4. Dans le panneau fournisseur, copiez l’URL complète avec le bouton Copy du plugin, puis utilisez « Update Webhook ». Les sondes POST de validation sans signature sont désormais acceptées sur l’URL privée, même si le secret HMAC existe déjà. Les événements de commande sans signature restent refusés.
5. Utilisez ensuite « Send Test » dans le panneau fournisseur. Vérifiez que « Dernier test signé du fournisseur » affiche une nouvelle date. Le contrôle local possède une date distincte. Si la signature reste invalide, comparez le secret HMAC exact des deux côtés ; la clé API et le jeton de l’URL ne remplacent pas ce secret. Ne publiez pas ces valeurs.
6. Pour les anciennes commandes, utilisez d’abord Sync. Pour une commande payée encore bloquée, utilisez Retry une fois : le plugin conserve la référence UUID et recherche la commande existante. Une commande impayée, terminée ou remboursée ne sera pas relancée comme un nouvel achat. Si WooCommerce ne conserve aucune preuve du paiement d’une ancienne commande en attente, confirmez le paiement avant de modifier son état.
7. Gardez le tableau de bord ouvert pendant la récupération. Il peut traiter au maximum une commande échue par minute, même si le déclenchement automatique est en retard. Actualisez la liste pour lire les nouveaux états. Ce secours cesse quand la page est fermée ou masquée.

## Si le fournisseur refuse encore l’URL

- Si aucune nouvelle « Dernière validation URL reçue » n’apparaît, le POST peut être bloqué avant d’atteindre le plugin, envoyé à une mauvaise URL, ou envoyé avec une signature invalide. Vérifiez le résultat exact du contrôle local et la nouvelle date du diagnostic.
- Dans Delicat Builder, l’option autorisant les callbacks de paiement doit rester activée pour que l’exception de maintenance s’applique. La mise à jour utilise le filtre prévu par ce module ; elle n’ouvre pas les autres routes REST.
- Un HTTP 403/503, un défi anti-bot, une redirection ou une erreur TLS peut venir du serveur, du pare-feu ou d’une autre extension. Autorisez seulement le POST vers la route exacte du callback ; ne désactivez pas globalement les protections.
- Si le POST signé atteint le plugin mais reçoit 401, vérifiez le secret et la transmission de l’en-tête X-Shop2Topup-Signature. Le plugin ne neutralise pas cette vérification.

## Planificateur

Si « Next reconcile » reste dans le passé, consultez WooCommerce → État → Actions planifiées. La présence d’Action Scheduler ne garantit pas que ses tâches s’exécutent. Avec DISABLE_WP_CRON, un cron serveur ou un worker équivalent doit déclencher les tâches régulièrement. La réparation du déclencheur dépend de votre hébergement ; elle n’a pas été réalisée sur votre serveur pendant cet audit.

## INVALID_RESPONSE

Cette erreur ne prouve pas à elle seule que le fournisseur a reçu ou refusé un achat. La mise à jour valide strictement les objets et références renvoyés, conserve les références d’origine et expose un diagnostic sans corps de réponse sensible. Si l’erreur persiste, relevez le chemin d’API masqué, le code HTTP, le type d’erreur et la date affichés. Une réponse HTML, un blocage réseau ou un payload fournisseur incorrect nécessite cette preuve pour être résolu. Aucun achat réel n’a été effectué pendant les tests.
