Delicat Recharge Admin 6.6.4 — sécurité et argent (5 novembre 2026)
====================================================================

Corrige les points Recharge de l'audit du 5 novembre 2026 (RCH-01 à RCH-13 et le contrôle automatique).

Ce qui change pour les clients et l'équipe
(1) Faux SMS MonCash (RCH-01) : un SMS MonCash sans la ligne « new balance is » n'est plus crédité
    automatiquement. Il est retenu (« SUSPECT ») sur la page Natcash SMS, comme un faux SMS Natcash.
    Les vrais reçus MonCash ont toujours cette ligne : pour eux, rien ne change.
(2) Application de l'équipe (RCH-02) : « Approuver » depuis l'application passe maintenant par le même
    contrôle « un TransCode = un seul crédit » que le site. Si le code a déjà été crédité, l'application
    reçoit « Ce TransCode a déjà été crédité » et rien n'est crédité. Les saisies manuelles (application
    et console) inscrivent aussi le code dans ce contrôle.
(3) Remboursements (RCH-03) : un double clic, ou deux personnes en même temps, ne débitent plus le client
    deux fois. Le premier remboursement passe ; les autres reçoivent « déjà remboursée ». Si TeraWallet
    refuse le débit, la recharge reste « créditée », avec une note « Échec du remboursement ».
(4) Envoyer de l'argent (RCH-05) : l'historique du portefeuille affiche de nouveau le texte des transferts :
    « Envoyé à CODE : 100.00 HTG (frais 1.50 HTG) » et « Reçu de CODE : 100.00 HTG ». Ces lignes étaient
    vides depuis la refonte de l'envoi. Les anciennes lignes vides restent vides ; les montants étaient justes.
(5) Transmetteur SMS (RCH-04) : la page Natcash SMS n'affiche plus la clé secrète en entier (elle est
    masquée). Les boutons « Copier la clé » et « Copier l'adresse » la copient seulement au clic. La clé peut
    maintenant être envoyée dans un en-tête (X-Delicat-Key, ou Authorization: Bearer). L'ancienne adresse
    avec « ?key=… » marche toujours. Tant que votre transmetteur l'utilise, la page affiche « Votre
    transmetteur utilise encore la clé dans l'URL : passez à l'en-tête », avec les étapes.
(6) Secret du webhook (RCH-11, RCH-09) : le champ est masqué et n'est plus jamais pré-rempli. Laissé vide,
    il garde le secret actuel. Vous pouvez aussi générer un nouveau secret (affiché une seule fois) ou le
    retirer. Si un webhook est réglé sans secret, un avertissement s'affiche sur les pages Delicat.
(7) Parrainage (RCH-06, RCH-07) : il n'y a plus qu'un seul programme, celui de Delicat Rewards. Le
    parrainage « Croissance » de Recharge (désactivé par défaut) se met en pause quand Delicat Rewards est
    installé : il n'enregistre plus de parrain, ne verse plus de bonus de parrainage et n'affiche plus sa
    carte. Les données déjà enregistrées sont gardées ; les codes promo et la fidélité continuent. Sans
    Delicat Rewards, il marche comme avant, mais le parrain doit être un compte client existant (ni le
    nouveau compte lui-même, ni un membre de l'équipe) et le lien « ?ref= » doit être un numéro exact.
(8) Conservation (RCH-09) : chaque nuit, le texte brut des SMS de paiement de plus de 180 jours est effacé
    de la page Natcash SMS. Les recharges, les crédits du portefeuille et la liste des TransCode déjà
    utilisés ne sont jamais effacés.

Points techniques
(9)  Limite de débit du transmetteur : l'adresse du visiteur ne vient plus d'en-têtes que n'importe qui peut
     inventer (X-Forwarded-For, X-Real-IP, True-Client-IP). CF-Connecting-IP n'est cru que si la requête
     arrive d'un serveur Cloudflare (même règle que Delicat Builder, utilisée quand il est actif).
(10) Application, saisie « manual-code » (RCH-12) : nouvelle signature v2, facultative. L'application envoie
     sig_v=2 et signe « device_id|manual|id|ts|trans_code » (HMAC-SHA256 avec la clé de signature de
     l'appareil, en hexadécimal minuscule, trans_code exactement comme envoyé). L'ancienne signature reste
     acceptée ; dès qu'un appareil a envoyé une signature v2 valide, il doit toujours utiliser la v2.
(11) Étiquette « provider » du transmetteur (RCH-13) : gardée, car elle ne peut rien affaiblir. Un SMS
     traité comme Natcash doit toujours avoir sa ligne de solde et passer tous les contrôles de la Garde
     SMS, quelle que soit l'étiquette. Elle apparaît dans le journal (« label »).
(12) Bonus Croissance (RCH-08) : une nouvelle colonne « bonus_done » sur la table des recharges remplace
     l'option créée à chaque recharge approuvée, et la requête inutile est retirée. Les anciennes options
     « _delicat_bonus_done_<numéro> » sont recopiées dans cette colonne puis supprimées, par lots de 200,
     par une tâche quotidienne.
(13) Code inutilisé retiré (RCH-10) : vérification par l'API MonCash Business (class-moncash.php, jamais
     appelée), Natcash « API » vide (class-natcash.php), ancien formulaire Natcash à code PIN côté serveur
     (déjà refusé avant d'être atteint), générateur et lecteur de PIN, une fonction de l'application jamais
     appelée. La colonne nc_pin et les anciennes demandes restent dans la base.
(14) Codes promo et paliers de fidélité : le tout premier enregistrement de la page Paramètres ne les efface
     plus (WordPress contrôle deux fois une nouvelle option).
(15) Le contenu du webhook ne change pas. Il est signé avec l'en-tête X-Delicat-Signature (sha256=…) dès qu'un
     secret existe.

Réglages modifiés par la mise à jour (pour revenir en arrière)
- « MonCash : exiger le solde » (Garde SMS) est coché une fois sur tous les sites, même si la case avait été
  enregistrée décochée. Pour l'annuler : Delicat Console → Paramètres → Garde SMS → décocher → Enregistrer.
  Elle ne se recoche plus toute seule.
- Nouveau réglage « Effacer le texte des SMS après » : 180 jours (minimum 30 ; 0 = garder le texte).
- Les champs « MonCash API » (Client ID, Secret, Environnement) disparaissent de la page Paramètres : aucun
  code ne s'en servait. Les valeurs déjà enregistrées restent dans la base.
- Parrainage Croissance : en pause tant que Delicat Rewards est installé (voir (7)).

À faire dans wp-admin après la mise à jour
- Natcash SMS → « Adresse du forwarder » : suivez les 3 étapes pour mettre la clé dans un en-tête de votre
  application de transfert SMS. Quand « Dernier SMS reçu » indique « en-tête », cliquez « Créer une nouvelle
  clé » et collez-la dans l'application (l'ancienne clé a pu être vue dans des adresses et des journaux).
- Si vous utilisez un webhook (Make, Zapier, n8n) : Paramètres → Webhook → « Générer un nouveau secret »,
  enregistrez, puis collez ce secret chez le destinataire pour vérifier la signature.

Installation
Extensions → Ajouter → Téléverser une extension → choisir delicat-recharge-6.6.4.zip → « Remplacer la
version actuelle ». Les données et les réglages sont conservés. Installer avec Delicat Builder 9.7.x et
Delicat Identity 6.17.x. Retour en arrière : réinstaller 6.6.3 (la colonne ajoutée ne la gêne pas).
