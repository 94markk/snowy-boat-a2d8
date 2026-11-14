Delicat Recharge Admin 6.6.5 — sécurité (7 novembre 2026)
=========================================================

Corrige les points Recharge de l'audit de sécurité du 7 novembre 2026 (1 point élevé, 4 moyens, 1 faible), et
applique la demande du propriétaire du 7 novembre 2026 : garder chaque SMS au moins 24 h, puis l'effacer, avec une
sécurité renforcée (points 8 à 12).

Ce qui change pour les clients et l'équipe
(1) Envoyer de l'argent (point élevé) : deux envois préparés dans deux onglets et confirmés au même instant ne
    peuvent plus mettre le solde de l'expéditeur en négatif. La confirmation prend un verrou sur le portefeuille
    de l'expéditeur (le même que l'application Délicat Store pour ses transferts et ses paiements par
    portefeuille), relit le solde dans la base, vérifie, débite, puis crédite. Le deuxième envoi attend le premier
    et voit son débit : « Solde insuffisant pour le montant + les frais. » Si le verrou est occupé plus de
    5 secondes : « Une autre opération est en cours sur votre portefeuille. Patientez quelques secondes, puis
    réessayez. » (rien n'est débité ; le client recommence l'envoi). Si un débit laisse quand même le
    solde sous zéro, il est annulé, le destinataire n'est pas crédité et la ligne du transfert est notée
    « failed / overdraft_reverted ». Les reçus, la clé unique de chaque envoi et la limite par heure ne changent pas.
(2) Formulaire de recharge : le serveur vérifie lui-même chaque champ, plus seulement la page.
    - Montant : chiffres, 2 décimales au plus (« 500 », « 500.50 » ; « 500,50 » est lu 500.50), entre 0 et
      100 000 HTG. Sinon : « Montant invalide : chiffres uniquement, 2 décimales au plus (ex. 500 ou 500.50). »
    - TransCode / Txn ID : lettres et chiffres (espaces et tirets acceptés), 4 à 40 caractères pour MonCash et
      Natcash ; l'onglet « Manuel » accepte aussi . _ / # (références bancaires), jusqu'à 64 caractères.
    - Méthode : une des 4 du menu « Manuel » (MonCash, Natcash, Virement bancaire, Autre), seulement sur cet
      onglet ; les autres onglets enregistrent leur propre nom, comme la page l'envoie déjà.
    - Code promo : 2 à 32 caractères A-Z, 0-9, _ et - ; un autre texte est ignoré.
(3) Paramètres → « Service Account JSON (Firebase) » : la clé enregistrée n'est plus jamais réaffichée dans la
    page (elle contient la clé privée Google). La page affiche « Clé configurée (masquée) — projet … ». Laisser le
    champ vide garde la clé ; coller un nouveau JSON la remplace ; la case « Retirer la clé » l'efface. Un JSON
    invalide garde l'ancienne clé. Le stockage ne change pas (option non chargée automatiquement) : Recharge n'a pas
    de coffre de chiffrement.
(8) SMS de paiement reçus (MonCash, Natcash) : chaque SMS est gardé au moins 24 h après sa réception, pour qu'une
    recharge soumise par le client dans la journée soit encore validée. Ensuite il est effacé pour de bon (la ligne,
    le texte, les numéros) : une vérification toutes les heures (WP-Cron), plus une petite purge à chaque SMS reçu si
    la dernière date de plus de 10 minutes (si WP-Cron est en retard). Un SMS expiré n'est plus jamais lu, même avant
    la purge. Avant, seuls les 40 derniers SMS étaient gardés (un jour chargé en perdait), et leur texte en clair
    pendant 180 jours.
    - Réglage Paramètres → « Conservation des SMS de paiement » → « Garder chaque SMS reçu » : 24 h par défaut,
      24 h au minimum, 7 jours (168 h) au maximum. Une valeur hors de ces limites est ramenée dedans, aussi par le code.
    - Page Natcash SMS : chaque SMS indique quand il sera effacé ; en rouge « bientôt effacé » quand il reste moins de
      2 h, ou quand il a plus de 24 h. Un SMS effacé ne peut plus valider une recharge : traitez-le avant (bouton
      « Authentique » pour un SMS retenu). « Vider la liste » masque seulement les SMS de la page : ils valident encore
      les recharges jusqu'à leur expiration.
(9) Recharge validée par un SMS : la recharge garde dans sa note ce qu'il faut pour son contrôle, une seule fois :
    « [SMS-AUDIT MonCash reçu 2026-11-07 14:55 — expéditeur +509 ••••••09 — TransCode … — 1600.00 HTG] ». Le SMS
    lui-même est effacé après le délai, comme les autres. Le registre « un TransCode = un seul crédit » n'est jamais
    effacé (il ne contient pas de SMS).

Points techniques
(4)  Application de l'équipe, « manual-entry » : nouvelle signature v2, facultative. L'application envoie sig_v=2
     et signe « device_id|mentry|trans_code|ts|montant|email|mode|tab » (HMAC-SHA256 avec la clé de signature de
     l'appareil, hexadécimal minuscule ; montant avec 2 décimales et un point, « 500.00 » ; e-mail en minuscules ;
     mode et tab tels qu'envoyés, vides s'ils ne sont pas envoyés). Le montant, le client, le mode et la méthode ne
     peuvent plus être changés sous une signature valide. L'ancienne signature reste acceptée, car l'application
     Delicat Notification actuelle signe encore « device_id|mentry|trans_code|ts » ; dès qu'un appareil a envoyé
     une signature v2 valide, il doit toujours utiliser la v2 (même règle que « manual-code » en 6.6.4).
(5)  Statistiques (/wp-json/delicat/v1/stats) : la clé n'est plus acceptée dans l'adresse (« ?key=… »), seulement
     dans l'en-tête X-Delicat-Stats-Key. Aucune application actuelle ne l'envoie dans l'adresse. La page
     Paramètres n'affiche plus l'adresse complète avec la clé.
(6)  Transmetteur SMS (/natcash/forward) : la clé dans l'adresse (« ?key=… ») ou dans le corps reste acceptée
     jusqu'au premier SMS transmis avec l'en-tête X-Delicat-Key (ou Authorization: Bearer) ; ensuite elle est
     refusée (« header_required ») et l'ancienne variante GET disparaît. L'application Delicat SMS Forwarder
     actuelle n'envoie qu'une adresse (clé dans « ?key=… ») : elle continue de marcher tant que le transmetteur
     n'a jamais utilisé l'en-tête. La page Natcash SMS montre l'état (« Clé dans l'adresse : acceptée / refusée »),
     signale un SMS refusé pour cette raison (sans le compter comme une mauvaise clé), et propose « Refuser la clé
     dans l'adresse maintenant » ou, en dépannage, « Réaccepter la clé dans l'adresse ».
(7)  Ancien code PIN Natcash : les valeurs « kontni » encore enregistrées en clair dans la colonne nc_pin des
     anciennes demandes sont effacées une fois à la mise à jour (la colonne reste ; plus rien ne les lisait).
(10) Stockage des SMS : nouvelle table {préfixe}delicat_sms, une ligne par SMS. Le texte est chiffré (sodium
     crypto_secretbox, nonce aléatoire par SMS). Clé : la constante DELICAT_RECHARGE_SMS_KEY de wp-config.php si
     elle existe (32 octets en base64, ou 64 caractères hexadécimaux, ou une phrase longue), sinon une clé dérivée
     des sels WordPress (hash_hkdf). Chaque valeur garde l'identifiant de sa clé : ajouter la constante plus tard ne
     rend pas illisibles les SMS déjà stockés. Les numéros de l'expéditeur et du payeur sont gardés masqués
     (« +509 ••••••09 ») avec une empreinte HMAC pour les comparer ; le TransCode et le montant restent lisibles
     (nécessaires pour valider). Le texte n'apparaît jamais en clair dans les journaux, les avis, les réponses de
     l'API, les e-mails ni les écrans : la page Natcash SMS et la recherche de TransCode le montrent masqué (numéros
     et noms cachés). La réponse au transmetteur pour un SMS MonCash sans demande masque aussi le numéro du payeur.
(11) Réception des SMS (/natcash/forward et /natcash/sms) : HTTPS obligatoire (sinon 403 « https_required »),
     y compris derrière Cloudflare ou LiteSpeed (en-tête X-Forwarded-Proto). Interrupteur de secours, à n'utiliser
     qu'en cas de panne : add_filter( 'delicat_recharge_sms_require_https', '__return_false' ). Le même SMS
     (même expéditeur, même texte, même heure du téléphone) n'est enregistré et traité qu'une fois : un renvoi reçoit
     « SMS déjà reçu (doublon ignoré) » (réponse 200, le transmetteur arrête de réessayer). Une validation par
     l'administrateur (« Authentique ») ou la reprise automatique d'un SMS retenu met à jour la ligne du SMS au lieu
     d'en créer une deuxième.
(12) Mise à jour : les SMS de l'ancien journal (option delicat_natcash_log) reçus dans le délai passent dans la table,
     chiffrés et masqués ; les plus anciens sont effacés ; l'option et l'ancien réglage « Effacer le texte des SMS
     après » (jours) sont supprimés.

Réglages modifiés par la mise à jour
- « Effacer le texte des SMS après » (jours, 180 par défaut) est remplacé par « Garder chaque SMS reçu » (heures,
  24 par défaut, de 24 à 168).
- Nouvelles options internes : « delicat_forward_header_only » (posée au premier SMS reçu avec l'en-tête),
  « delicat_sms_retention_hours », « delicat_sms_last_purge ». Nouvelle tâche WP-Cron horaire
  « delicat_recharge_sms_purge ».

À faire dans wp-admin après la mise à jour
- Paramètres → Service Account JSON : vérifiez que la page affiche « Clé configurée (masquée) ». Si la clé a pu
  être vue (elle était réaffichée dans la page), créez-en une nouvelle dans Firebase, collez-la, enregistrez, puis
  supprimez l'ancienne dans Firebase.
- Natcash SMS → « Adresse du forwarder » : quand votre transmetteur sait envoyer un en-tête, mettez-y la clé
  (X-Delicat-Key) ; la clé dans l'adresse sera ensuite refusée automatiquement. Puis « Créer une nouvelle clé ».
- Si un outil lit les statistiques avec « ?key=… », passez la clé dans l'en-tête X-Delicat-Stats-Key, puis
  « Réinitialiser la clé » (Paramètres) si l'ancienne adresse a pu être vue.

- Recommandé : ajoutez dans wp-config.php une clé propre aux SMS, gardée dans le gestionnaire de mots de passe et
  jamais retirée ensuite : define( 'DELICAT_RECHARGE_SMS_KEY', '<32 octets aléatoires en base64>' ); Sans elle, la
  clé est dérivée des sels WordPress : changer les sels rendrait illisibles les SMS du moment (au plus 7 jours).

Installation
Extensions → Ajouter → Téléverser une extension → choisir delicat-recharge-6.6.5.zip → « Remplacer la
version actuelle ». Les données et les réglages sont conservés. Retour en arrière : réinstaller 6.6.4 (les PIN
effacés ne reviennent pas ; rien ne les utilisait). La 6.6.4 ne lit pas la nouvelle table : son journal repart vide
et elle ne peut plus valider les SMS reçus avant le retour en arrière.
