Delicat Recharge Admin v6.3.0 — GARDE SMS (anti-spoofing Natcash / MonCash)

PROBLÈME
- Des escrocs envoient de faux SMS « Natcash » au téléphone forwarder (expéditeur
  alphanumérique via Twilio ou équivalent). Le faux arrive dans le MÊME fil que les
  vrais, donc la règle « l'expéditeur doit être Natcash » ne suffit plus.
- Exemple reçu le 02/09/2026 : « Ou resevwa 1,000,000 HTG … Balans ou: 25,131.4 HTG.
  Transcode: 26087333698476 ». Le solde ne bouge pas après 1 000 000 HTG et le
  TransCode commence par 260873 (le jour 73 n'existe pas).

CE QUE FAIT LA GARDE SMS (includes/class-sms-guard.php)
Chaque reçu transmis par le forwarder doit passer des preuves qu'un escroc ne peut
pas fabriquer :
  1. Chaîne de solde : « Balans ou » = dernier solde vérifié + montant reçu.
  2. TransCode Natcash = 14 chiffres et commence par la date du SMS (AAMMJJ)
     (vérifié sur tous les vrais reçus : 260626…, 260627…, 260724…).
  3. Heure écrite dans le SMS proche de la réception (tolérance 30 min).
  4. Optionnel : centre SMS (smsc) autorisé, quand le forwarder l'envoie.
Plus des limites de dégâts : plafond 25 000 HTG par SMS, 100 000 HTG par heure,
verrouillage 30 min après un SMS suspect (tout passe en approbation manuelle) avec
alerte push + email.

Un SMS qui échoue est RETENU : jamais crédité, jamais supprimé, visible sur
Délicat Console → 🔍 Natcash SMS avec les motifs exacts et un bouton
« ✅ Authentique → créditer & re-sync solde » si c'est un vrai.
Les SMS suspects (et ceux reçus avant cette version) ne servent plus de preuve
pour la vérification instantanée à la soumission ni pour l'app.

AUTRES CORRECTIONS
- parse_moncash() était référencée mais absente : tout SMS MonCash transmis
  provoquait une erreur fatale. Restaurée (avec extraction du solde).
- L'app passait « natcash_tc » comme fournisseur à verify_received() → jamais de
  correspondance avec un SMS Natcash. Corrigé.
- Journal SMS : 40 entrées au lieu de 15 (une rafale de faux ne chasse plus les vrais).

APRÈS INSTALLATION (obligatoire)
1. Délicat Console → 🔍 Natcash SMS → « Définir le solde de référence » : entrez
   votre solde Natcash actuel (app Natcash ou *144#). Tant qu'il n'est pas défini,
   les crédits automatiques Natcash sont retenus (approbation manuelle).
2. Réglages → WordPress → fuseau horaire = America/Port-au-Prince (la garde teste
   aussi ce fuseau automatiquement, mais autant être propre).
3. Vérifiez que les codes 26087333698476 et 26087333698450 n'ont crédité aucune
   demande (Recharges → recherche). Si oui : rembourser / bloquer le compte.
4. Purger LiteSpeed.
5. Faites transférer TOUS les SMS Natcash par le forwarder (pas seulement les
   reçus) : les SMS de transfert sortant mettent à jour le solde de référence
   automatiquement, sinon il faut le re-synchroniser à la main après chaque sortie
   d'argent non vue.

FORWARDER ANDROID (recommandé, rend la garde encore plus forte)
Dans le BroadcastReceiver SMS_RECEIVED, ajoutez deux champs au POST :
    val msgs = Telephony.Sms.Intents.getMessagesFromIntent(intent)
    val smsc = msgs.firstOrNull()?.serviceCenterAddress ?: ""
    val ts   = msgs.firstOrNull()?.timestampMillis ?: System.currentTimeMillis()
    // POST … "smsc" -> smsc, "ts" -> ts.toString()  (en plus de sms / from / provider)
Après quelques vrais reçus, la page Natcash SMS affiche les centres SMS vus :
copiez celui de Natcom dans Paramètres → Garde SMS → « Centres SMS autorisés ».
Un faux SMS transite par un centre étranger et sera refusé sur ce seul critère.

API POUR L'APP ADMIN
POST /wp-json/delicat/v1/sms/balance  (en-têtes x-delicat-device / x-delicat-token)
     provider=natcash|moncash, balance=80707.63  → re-synchronise le solde de référence.

RÉGLAGES : Délicat Console → ⚙️ Paramètres → 🛡️ Garde SMS.
DELICAT_VERSION reste 6.0.5 (aucune migration de base), assets 6.1.3.
