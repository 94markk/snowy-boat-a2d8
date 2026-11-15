# Delicat Recharge Admin — journal des versions

Ce fichier remplace les notes par version (`README-v*.txt`, `API-SYNC-v6.2.0.md`) et le fichier
`MANIFEST-SHA256.txt`, supprimés en 6.7.0. Les consignes encore utiles sont regroupées dans
« Mise en place et réglages » plus bas.

---

## 6.8.0 — 15 novembre 2026 — pages plus légères, QR sans service extérieur

### Ce qui change pour les clients et l'équipe

(1) **QR codes dessinés sur le site** (carte de solde, « Envoyer de l'argent ») : plus aucune requête vers
    api.qrserver.com, donc le code du client n'est plus envoyé à un tiers et le QR s'affiche même si ce service
    est lent ou bloqué. Image SVG gardée un jour dans le cache objet.
(2) **Pages du compte plus légères** : les styles et scripts du portefeuille ne sont chargés que sur l'onglet
    portefeuille, les pages portefeuille et les pages avec un code court (plus sur « Comptes connectés » ni
    « API revendeur »). Le jeton de sécurité et les réglages du portefeuille ne sont calculés que là où le script
    est chargé.
(3) **Serveur** : les 27 classes sont chargées à la demande ; une page de la boutique compile 76 Ko au lieu de
    578 Ko. L'administration, admin-ajax, le cron et WP-CLI construisent tout comme avant (même ordre).

### Technique

- Nouvelle classe Delicat_QR (includes/class-qr.php), encodeur QR écrit pour l'extension (vérifié module par
  module contre une bibliothèque de référence et décodé par un lecteur QR).
- Nouveau filtre delicat_recharge_wallet_ui_endpoints pour ajouter un onglet du compte qui doit afficher le
  portefeuille.
- Les vérifications des tâches planifiées se font dans l'administration, admin-ajax et le cron, plus sur chaque
  page de la boutique.

## 6.7.0 — 14 novembre 2026 — sécurité, rapidité, correctifs

### Ce qui change pour les clients et l'équipe

(1) **Page portefeuille plus rapide**, surtout sur les petits téléphones Android et dans les navigateurs
    intégrés (Facebook, TikTok, application Délicat Store) :
    - feuille de style chargée : 292 Ko → 238 Ko (41,8 Ko → 33,0 Ko compressée) ; 98 règles qui ne
      s'appliquaient plus à rien sont retirées. L'affichage est identique (comparé dans Chrome : formulaire,
      carte du solde, envoi d'argent, anciens blocs du portefeuille, clair/sombre, 3 largeurs d'écran,
      survol/focus) ;
    - script : 35 Ko → 16 Ko (9,8 Ko → 5,3 Ko compressé), chargé en différé : il ne retarde plus
      l'affichage de la page ;
    - l'image QR cachée de la carte du solde n'est plus téléchargée à chaque visite (elle ne s'affichait
      jamais) ;
    - le message d'une demande défile avec le défilement natif du téléphone, plus fluide ;
    - « Envoyer de l'argent » sur téléphone : le fond derrière la fenêtre est assombri sans flou (le flou
      plein écran, recalculé à chaque image de l'ouverture, faisait saccader les petits Android).
(2) **TransCode tapé avec des espaces ou des tirets** (« 2606 2687-398118 ») : la recharge est vérifiée et
    créditée tout de suite quand le SMS est déjà arrivé. Avant, ce code n'était jamais reconnu à l'envoi ;
    si le SMS était arrivé avant la demande, la recharge restait « en attente » jusqu'à une validation à la main.
(3) **Paiement qui ne pouvait plus être crédité** : si le crédit du portefeuille échouait (TeraWallet
    momentanément indisponible, compte introuvable), le TransCode restait quand même marqué « déjà
    utilisé ». Ensuite le SMS, l'application et la console refusaient tous ce paiement. Le code est
    maintenant libéré dès que rien n'a été crédité (vérification à l'envoi et SMS MonCash), comme le faisait
    déjà le chemin Natcash. La règle « un TransCode = un seul crédit » ne change pas.
(4) **Message clair quand la page a expiré** : au lieu de « Erreur réseau » à chaque essai, le client lit
    « Votre page a expiré… Rechargez la page » (lien), ou « Connectez-vous » avec le lien de connexion, ou le
    message « Trop de demandes ».
(5) **Bouton « Copier » de la carte du solde** : l'icône ne disparaît plus après le premier clic ; le bouton
    « Copier » de la fenêtre QR affiche « Copié ».
(6) **Envoyer de l'argent** : la limite de 10 envois par heure s'applique de nouveau. L'heure des envois
    (heure d'Haïti) était comparée à l'horloge de la base (UTC) : la limite ne se déclenchait jamais.
(7) **Console et application de l'équipe** : une recharge déjà créditée ne peut plus être « rejetée » (l'argent
    restait sur le portefeuille, la ligne affichait « Rejeté » et le bouton « Rembourser » disparaissait).
    Message : « Cette demande a déjà été créditée : utilisez « Rembourser » ». Le bouton « Rejeter » n'est plus
    affiché sur ces lignes. Le rejet groupé appliquait déjà cette règle.
(8) **Notification « Recharges en attente »** : elle partait toutes les 15 minutes, jour et nuit, tant qu'une
    vieille demande restait en attente. Maintenant : une notification quand la liste des demandes en retard
    change, puis un rappel au plus toutes les 2 heures.
(9) **Résumé du jour** : envoyé à 20 h heure du site (Haïti) et compté depuis minuit heure d'Haïti. Il partait
    à 20 h UTC (15 h ou 16 h en Haïti) et comptait depuis minuit UTC.
(10) **Historique du portefeuille et ajustements** : la date écrite dans la ligne « Recharge #… » et
    « Ajustement admin » est l'heure du site (elle était en UTC).
(11) **Exports CSV** (clients, transactions) : une cellule qui commence par = + - @ est écrite comme du texte.
    Un nom ou un TransCode piégé ne peut plus lancer une formule dans Excel / LibreOffice / Sheets.

### Points techniques

(12) **Preuves de paiement — faille corrigée (élevée).** Un fichier que le serveur ne reconnaissait pas
     comme image était accepté d'après son seul nom (« preuve.jpg »), puis renvoyé à l'administrateur avec le
     type deviné par le serveur : une page HTML déguisée en .jpg s'exécutait dans wp-admin quand on cliquait
     « Voir » (vol de session administrateur possible par n'importe quel client connecté, via l'onglet
     « Manuel »). Maintenant :
     - le type vient uniquement des premiers octets du fichier (JPEG, PNG, GIF, WEBP, PDF) ; finfo, s'il est
       présent, doit être d'accord ; sinon : « Type de fichier non autorisé » ;
     - l'affichage (`admin-post.php?action=delicat_view_proof`) envoie le type de l'extension autorisée, jamais
       le type deviné ; un fichier dont les octets ne correspondent pas est proposé en téléchargement ; les
       images reçoivent `Content-Security-Policy: default-src 'none'; … sandbox` (en plus de `nosniff`).
       Les fichiers déjà stockés sont donc aussi neutralisés.
(13) **Garde SMS** : une ligne de la table des SMS sans verdict de la Garde (traitement interrompu, licence
     expirée) n'est plus une preuve pour la vérification instantanée ni pour l'application ; seul un SMS
     « Authentique » (verdict ok) l'est. L'ancien contrôle partiel (sans la chaîne de solde) ne s'applique plus
     qu'aux entrées d'avant la table. Le bouton « Authentique » reste disponible.
(14) **Application, /app/manual-code** : la copie indexée du TransCode (`code_norm`) suit le code saisi ;
     avant, les contrôles « déjà utilisé » voyaient encore l'ancien code. La signature et la réponse ne changent pas.
(15) **Application, /app/action « reject »** sur une demande créditée : réponse 409 `already_credited`
     (rien n'est modifié). Seul changement visible pour l'application.
(16) **Codes personnels** (« DS… ») créés par la carte du solde : jamais deux fois le même (l'envoi d'argent
     trouve le destinataire par ce code). Même format qu'avant.
(17) **Sites clients sous licence** : si le serveur HQ ne répond pas, la réponse de secours est gardée 5 minutes
     au lieu de refaire un appel de 8 s à chaque requête de l'application et à chaque SMS.
(18) Divers : `wpdb::prepare()` sans paramètre retiré (avertissement dans le journal à chaque SMS Natcash sans
     demande) ; la tâche horaire du watchdog est retirée à la désactivation ; le logo de la console est recadré
     et réduit (478 Ko, 3375 × 6000 px → 13 Ko, 440 × 210 px, affiché à 220 px) ; `wp_date()` pour les dates
     des descriptions de portefeuille.
(19) **Fichiers minifiés** : le site charge `assets/css/form.min.css` et `assets/js/form.min.js` (fonction
     `delicat_recharge_asset_url()`). `form.css` et `form.js` restent les sources lisibles : après une
     modification, lancer `sh tools/build-assets.sh` (Node.js 18+, csso-cli 4.0.2 et esbuild 0.28.2 via npx).
     Avec `SCRIPT_DEBUG` à `true`, ou si un fichier .min manque, les sources sont chargées. Le script est
     enregistré avec `strategy => defer` (WordPress 6.3+ ; avant, simplement en pied de page).
(20) **Ménage** : 44 fichiers retirés — les 42 `README-*.txt`, `API-SYNC-v6.2.0.md` et `MANIFEST-SHA256.txt`
     (aucun code ne lisait ce manifeste ; il listait des fichiers qui changent à chaque version).
(21) En-tête « Requires PHP » : 8.0 (le code utilise des types union depuis plusieurs versions ; en PHP 7.4 il
     ne se chargeait pas).

Inchangés : routes REST, actions AJAX, options, tables et colonnes, contenus des réponses (sauf (15)),
signatures de l'application, contrat du transmetteur SMS, crochets (`delicat_recharge_*`, `delicat_transfer_completed`).
`DELICAT_VERSION` et `DELICAT_ASSET_VERSION` passent à 6.7.0 (pas de changement de base : la vérification
des tables tourne une fois). `DELICAT_SMS_GUARD_VERSION` (6.6.4) et `DELICAT_API_SYNC_VERSION` (6.2.0) ne changent pas.

**Après la mise à jour** : purger LiteSpeed et le CDN (nouvelle version des fichiers 6.7.0).
Retour en arrière : réinstaller 6.6.5 (aucune donnée à reprendre).

---

## Mise en place et réglages (toujours valables)

### Installation et mise à jour
- Extensions → Ajouter → Téléverser une extension → choisir le ZIP → « Remplacer la version actuelle ».
  Ne pas désinstaller avant. Les données et réglages sont conservés. Garder le ZIP précédent pour un retour
  en arrière.
- Ensuite : LiteSpeed → Purger tout, CDN/Cloudflare → tout purger.
- À installer avec les versions à jour de Delicat Builder (icônes et couleurs du Design System ; sans lui les
  icônes sont simplement absentes) et de Delicat Identity.

### Page portefeuille
- Shortcodes : `[delicat_wallet_balance]` (ou `[delicat_balance_card]`, carte du solde avec l'envoi d'argent
  intégré) en haut, puis `[delicat_recharge]` (formulaire). Aussi : `[delicat_envoyer_argent]` (envoi seul),
  `[delicat_statut]` (« Mes recharges », reçus).
- Les styles se chargent sur les pages `my-wallet` / `wallet`, l'onglet portefeuille du compte et partout où
  un shortcode est affiché. Rubriques du compte exclues : filtre `delicat_recharge_wallet_ui_skip_endpoints`.
- Services : Console → Recharges → « Services de recharge » (Natcash Auto (TC) recommandé, MonCash).
  L'ancien flux Natcash à code PIN est retiré (le formulaire le refuse).

### Garde SMS (obligatoire)
- Console → Natcash SMS → « Définir le solde de référence » : entrer le solde Natcash (et MonCash) actuel.
  Sans lui, les crédits automatiques sont retenus. À refaire après une sortie d'argent non vue par le
  transmetteur (ou faire transférer aussi les SMS sortants : ils mettent la référence à jour seuls).
- Réglages WordPress → fuseau horaire `America/Port-au-Prince`.
- Réglages : Console → Paramètres → Garde SMS (mode « Retenir », tolérances, plafonds 25 000 HTG / SMS et
  100 000 HTG / heure, verrouillage 30 min après un SMS suspect, « MonCash : exiger le solde » coché,
  centres SMS autorisés).
- Un SMS retenu se valide d'un clic (« Authentique → créditer & re-sync solde ») s'il existe vraiment dans
  l'application Natcash / MonCash.

### Transmetteur SMS (forwarder)
- Adresse : `POST /wp-json/delicat/v1/natcash/forward`, HTTPS obligatoire.
- Clé dans l'en-tête `X-Delicat-Key` (ou `Authorization: Bearer <clé>`). La clé dans l'adresse (`?key=`) ou le
  corps n'est acceptée que tant que le transmetteur n'a jamais utilisé l'en-tête ; ensuite elle est refusée
  (`header_required`). Console → Natcash SMS → « Adresse du forwarder » : copier la clé, créer une nouvelle clé,
  refuser / réaccepter la clé dans l'adresse.
- Champs : `sms`/`msg`/`message`/`text`, `from`/`sender`, `provider` (MonCash/Natcash), recommandés : `smsc`
  (centre SMS) et `ts` (horodatage en ms) — sur Android, `serviceCenterAddress` et `timestampMillis` du
  message reçu.
- Un même SMS (expéditeur + texte + heure) n'est traité qu'une fois (« SMS déjà reçu »).
- Le watchdog alerte (e-mail + push) quand aucun SMS n'est reçu pendant 6 h.

### Conservation des SMS
- Chaque SMS est gardé au moins 24 h (réglage 24 à 168 h), texte chiffré, numéros masqués, puis effacé.
- Recommandé dans `wp-config.php`, à garder ensuite pour toujours :
  `define( 'DELICAT_RECHARGE_SMS_KEY', '<32 octets aléatoires en base64>' );` (sinon clé dérivée des sels
  WordPress : changer les sels rend illisibles les SMS du moment).
- Une recharge validée par SMS garde sa ligne « [SMS-AUDIT …] » ; le registre des TransCodes dépensés n'est
  jamais effacé.

### Application de l'équipe et statistiques
- Paramètres → Appareils : un appareil par téléphone (Device ID, jeton, clé de signature affichés une fois).
- Signatures v2 (facultatives, puis obligatoires pour l'appareil dès la première v2 valide) :
  `/app/manual-code` : `device_id|manual|id|ts|trans_code` ;
  `/app/manual-entry` : `device_id|mentry|trans_code|ts|montant|email|mode|tab` (montant « 500.00 »,
  e-mail en minuscules). HMAC-SHA256 avec la clé de signature, hexadécimal minuscule, fenêtre 120 s.
- Statistiques : `GET /wp-json/delicat/v1/stats`, clé uniquement dans l'en-tête `X-Delicat-Stats-Key`
  (Paramètres → réinitialiser si elle a pu être vue).
- Re-synchroniser le solde de référence depuis l'app : `POST /wp-json/delicat/v1/sms/balance`
  (en-têtes `x-delicat-device` / `x-delicat-token`, `provider`, `balance`).

### Secrets
- Webhook (Make / Zapier / n8n) : Paramètres → Webhook → « Générer un nouveau secret », puis vérifier
  `X-Delicat-Signature: sha256=…` (HMAC-SHA256 du corps JSON) chez le destinataire.
- Firebase (notifications Android) : la clé n'est jamais réaffichée. Si elle a pu être vue, en créer une
  nouvelle dans Firebase, la coller, enregistrer, puis supprimer l'ancienne.

### Parrainage, promos, fidélité
- Désactivés par défaut (Paramètres → Croissance). Le parrainage Recharge se met en pause quand Delicat
  Rewards est installé (un seul programme) ; codes promo et paliers de fidélité continuent.

---

## Historique résumé

- **6.6.5** (7 nov. 2026) — Envoi d'argent : verrou sur le portefeuille de l'expéditeur (plus de solde négatif
  avec deux onglets). Formulaire vérifié côté serveur (montant, TransCode, méthode, code promo). Clé Firebase
  masquée. Signature v2 de `/app/manual-entry`. Statistiques : clé en en-tête seulement. Transmetteur : clé
  dans l'adresse refusée après le premier envoi avec l'en-tête. SMS : table chiffrée, 24 h minimum puis
  effacement, anti-rejeu, HTTPS obligatoire. Anciens PIN Natcash effacés.
- **6.6.4** (5 nov. 2026) — Audit RCH-01 à RCH-13 : MonCash sans solde retenu, approbation par l'app via le
  registre des TransCodes, remboursement unique, textes des transferts dans l'historique, clé du transmetteur
  masquée et acceptée en en-tête, secret du webhook masqué, un seul parrainage avec Delicat Rewards, colonne
  `bonus_done`, code mort retiré (MonCash API, Natcash API, PIN), limite du transmetteur par adresse fiable.
- **6.6.3 / 6.6.2** (1er nov. 2026) — Mode sombre : texte blanc lisible sur les boutons colorés ; compteur
  « VALIDÉ » de la page Natcash SMS corrigé ; états Actif/Désactivé et « Crédité » réaffichés.
- **6.6.1 / 6.6.0** — Icônes Material du Design System (plus d'emoji) ; couleurs du Delicat Design System 2.0
  (variables internes renommées `--drs-*`).
- **6.5.2** — Réponse immédiate (e-mails, push, webhooks après la réponse ; `litespeed_finish_request`),
  colonne indexée `code_norm` pour « TransCode déjà utilisé », secrets non chargés automatiquement.
- **6.5.1** — Styles du portefeuille chargés seulement sur les pages qui l'affichent ; carte « Fidélité » de
  l'accueil du compte (Delicat Identity).
- **6.5.0** — Recherche de TransCode (Console → Recharges → Recherche code, lecture seule) ; mode sombre qui
  suit le thème du site (`html.dlc-theme-dark`).
- **6.4.x** — Mise en page mobile, « Comment ça marche ? » repliable ; statistiques déplacées vers Delicat
  Sales Analytics (6.4.1).
- **6.3.0** — Garde SMS (chaîne de solde, date du TransCode, heure, centre SMS, plafonds, verrouillage) ;
  `parse_moncash()` restaurée (les SMS MonCash provoquaient une erreur fatale).
- **6.2.0** — Événements de synchronisation pour Delicat App API (création, validation, refus, mouvements du
  portefeuille, transferts, services), sans code de transaction, montant ni e-mail.
- **6.1.x / 6.0.x** — Formulaire de recharge « premium » responsive, fenêtre de succès après un vrai crédit,
  Natcash Auto (TC) par défaut et recommandé.
- **5.4.x** — Solde mis à jour en direct après une recharge (`delicat_balance`, 40 requêtes / min).
- **5.0.x – 5.3.x** — Carte du solde, menu « Envoyer de l'argent », réception par QR code, envoi d'argent
  sécurisé `[delicat_envoyer_argent]` intégré (5.1.4), correctifs mobile et performances (animations retirées
  derrière le verre, 5.1.8).
