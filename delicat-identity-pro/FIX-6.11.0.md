# 6.11.0 — Clés d’accès et connexion rapide dans l’application

## Demande (2026-10-24)

Le propriétaire veut les clés d’accès (passkeys) dans l’application et la connexion par empreinte ou par visage. La conception est dans `DESIGN-5.6.md` (§3.3 et §3.4). Cette version porte la partie Identity Pro. L’App API 2.33.0 et l’application 5.6.0 s’appuient dessus.

## 1. Clés d’accès dans l’application (`DIP_Mobile_Passkeys`, nouveau)

L’application utilise le même RP ID que le site (`delicastoreha.com`) et le même stockage (méta `_dip_passkeys_v1`). Une clé créée dans l’application marche donc aussi sur le site, et l’inverse.

Les contrôles WebAuthn sont ceux du site. Les aides de `DIP_Passkeys` (décodeur CBOR, COSE → PEM, attestation, données d’authentificateur, vérification d’assertion) passent simplement de `private` à `public` et sont marquées `@internal`. **Aucun corps de fonction n’est modifié.** Le test le prouve : une fois les 21 changements de visibilité et leurs commentaires retirés, `class-dip-passkeys.php` est identique octet pour octet à celui de la 6.10.2.

| Route | Accès | Détail |
|---|---|---|
| `POST /mobile/passkeys/login/options` | public | Connexion **découvrable**, sans e-mail à taper : `{ceremony_id, publicKey:{challenge, rpId, timeout:120000, userVerification:'required', allowCredentials:[]}}`. |
| `POST /mobile/passkeys/login/verify` | public | `{ceremony_id, credential}`. Le compte est trouvé par `userHandle`, puis la clé doit appartenir à ce compte. Ensuite viennent l’assertion, le compteur et l’UV, puis une session Identity (`method: passkey`). **Tout échec** répond le même 401 `authentication_failed`. |
| `POST /mobile/passkeys/register/options` | session + preuve récente | `{label}`. La réponse contient `rp`, `user` (poignée), ES256 et RS256, `attestation:'none'`, ainsi que `platform`, clé résidente et UV obligatoires. Au-delà du maximum (8 par défaut) : 409 `passkey_limit`. |
| `POST /mobile/passkeys/register/verify` | session | **Une seule fenêtre système.** On contrôle `fmt:none`, AT, UP, UV, le hachage du RP ID, l’origine de l’application et la cérémonie, liée à la session **et** à l’installation. Suivent l’e-mail de sécurité, l’action `dip_mobile_credential_changed` et l’audit `mobile_passkey_registered`. |
| `GET /mobile/passkeys` | session | `{passkeys:[{id,label,created,last_used,transports}], max}` |
| `DELETE /mobile/passkeys/{id}` | session + preuve récente | Garde « dernier moyen » : 409 `passkey_last_method`. Suivent l’e-mail, l’action et l’audit `mobile_passkey_removed`. |
| `POST /mobile/reauth/passkey/options` puis `/verify` | session | Preuve récente par une clé de **ce** compte. La réponse est `{proven_until, expires_in}`. |

**Cérémonies mobiles.** Elles portent `channel: 'mobile'` et sont liées à l’installation et à l’appareil (empreintes HMAC, comme les sessions) au lieu du cookie de navigateur. Elles sont consommées une seule fois : verrou `add_option` comme sur le site, prise unique sur la table de compteurs atomiques, puis lecture et suppression. Une cérémonie du site ne peut pas être terminée par l’application, ni l’inverse.

**Origines.** Pour l’application, **seules** les valeurs `android:apk-key-hash:<base64url des 32 octets>` tirées des empreintes de la carte « Application Android » sont acceptées. Avec les valeurs par défaut :
- `android:apk-key-hash:ghMFcMyGMaXfhsajoYdBf-WXeOLSEDh_RyI6Sr5vAAo` : clé d’envoi (APK du site, builds locaux) ;
- `android:apk-key-hash:HMlYex3rfibj3_NB7l6PNn7lQEwuVhO4mxQ6eTYoNxA` : clé de signature Google Play.

Une origine `https://…` est refusée sur les routes mobiles. Le site garde `https://<rpId>` seul et ne change pas.

**Format.** Les routes acceptent le JSON WebAuthn standard que renvoie Credential Manager (`{id, rawId, type, response:{…}}`, sous forme d’objet ou de chaîne). Le serveur l’aplatit lui-même.

**Limites.** Règle du propriétaire (2026-10-24) : « I don't want a client to have a limit using the application ». Aucune limite d’utilisation par client : seuls les échecs (secret refusé) sont comptés, plus un garde contre l’inondation très au-dessus d’un usage humain.
- options de connexion : 10 **échecs** par installation ; chaque demande ne compte que dans la garde d’inondation de l’installation (200 sur 10 min, `DIP_Mobile_API::SESSION_FLOOD_LIMIT` ; `throttle(…, $failures_only = true)`) ;
- vérification : 10 **échecs** par installation, et **18 assertions refusées par compte** sur 10 min. Une connexion réussie n’est jamais comptée, ni dans le seau du compte ni dans celui de l’installation (garde d’inondation seulement) ;
- l’adresse ne compte que les échecs, avec un plafond de 100 (même mécanisme que la 6.10.2) ;
- cérémonies d’enregistrement et de réauthentification (`register/options`, `reauth/passkey/options`, et côté empreinte `biometric/enroll`, `biometric/register`) : **garde d’inondation seulement**, 200 appels par session sur 10 min (`DIP_Mobile_API::SESSION_FLOOD_LIMIT`). Chaque appel enregistre une cérémonie, une ligne ou un e-mail : seul un script atteint ce plafond, jamais une personne. Il est compté **après** le contrôle de preuve récente : un 403 `reauth_required` ne compte pas.

Avant cette correction, ces quatre routes s’arrêtaient à 10 appels par session (le 403 compris), et le seau du compte comptait aussi les connexions réussies.

Si `passkeys_available` est éteint, ou s’il n’y a aucune empreinte valide : 403 `passkeys_disabled`.

## 2. `/.well-known/assetlinks.json` (`DIP_Android_App`, nouveau)

Le fichier est servi sur `init`, priorité 0, avant les redirections, les pages de maintenance et le thème.
- Chemin **exact** `wp_parse_url(home_url('/.well-known/assetlinks.json'), PHP_URL_PATH)`. Une chaîne de requête est acceptée : `…json?x=1` est servi, `…jsonx` ne l’est pas.
- GET ou HEAD seulement.
- En-têtes : `200`, `Content-Type: application/json`, `Cache-Control: public, max-age=3600`, `X-Content-Type-Options: nosniff`.

Le corps est exactement :

```json
[{"relation":["delegate_permission/common.get_login_creds"],"target":{"namespace":"android_app","package_name":"com.delicatstore.app","sha256_cert_fingerprints":["82:13:05:70:CC:86:31:A5:DF:86:C6:A3:A1:87:41:7F:E5:97:78:E2:D2:10:38:7F:47:22:3A:4A:BE:6F:00:0A","1C:C9:58:7B:1D:EB:7E:26:E3:DF:F3:41:EE:5E:8F:36:7E:E5:40:4C:2E:56:13:B8:9B:14:3A:79:36:28:37:10"]}}]
```

**Réglages.** Une nouvelle carte « Application Android » se trouve sous « Activer la connexion biométrique liée à l’appareil » :
- le paquet ;
- les empreintes, une par ligne, avec les **deux** remplies par défaut ;
- une empreinte de build de test et sa case « Autoriser temporairement » (éteinte par défaut) ;
- la case « Publier assetlinks.json » ;
- les origines acceptées, affichées ;
- les boutons « Tester » et « Ouvrir le fichier ».

**Normalisation.** Les empreintes sont mises en hexadécimal majuscule, 32 octets séparés par « : ». Une note après un espace est tolérée. Une ligne invalide est ignorée et signalée par une notice. **La même liste** produit le fichier et les origines du §1 : les deux ne peuvent pas diverger. Sans empreinte valide, rien n’est publié et les clés d’accès de l’application sont désactivées.

**Notices.** Une notice signale un **fichier physique** `ABSPATH/.well-known/assetlinks.json`, qui passerait avant WordPress. D’autres signalent les lignes invalides, un paquet invalide ou l’absence d’empreinte.

**« Tester ».** Ce bouton est réservé aux administrateurs (nonce, `manage_options`, action classée dans `DIP_Access_Guard`). Il lit le fichier servi par le site, puis interroge `https://digitalassetlinks.googleapis.com/v1/statements:list?source.web.site=<site>&relation=delegate_permission/common.get_login_creds`. Il affiche les empreintes trouvées de chaque côté, avec « OK » ou « À vérifier ». Un défi Cloudflare (403 HTML) apparaît donc tout de suite.

## 3. Connexion rapide par empreinte ou visage : `/mobile/biometric/*` réparé

**Problème en 6.10.** La clé vivait sur la ligne de session. Elle mourait donc à la déconnexion et à l’expiration du renouvellement. Il n’y avait ni preuve de possession, ni liaison de contexte, ni consommation atomique, ni moyen de la retirer. L’application ne s’en servait pas.

**Nouveau contrat :**
- **Table.** La nouvelle table `{prefix}dip_mobile_biometric_keys` est créée par le schéma d’exécution 6.11.0, au premier chargement après la mise à jour, sans réactivation. Elle contient une clé active par installation, en EC P-256 (ES256), stockée en PEM. Les colonnes `biometric_*` de la table des sessions restent mais ne sont plus lues.
- **Message signé.** Le texte est fourni par le serveur et gardé dans la cérémonie :
  - enrôlement : `DLC-BIO1|enroll|<challenge>|<installation_id>|<rp_id>|<user_id>` ;
  - connexion : `DLC-BIO1|login|<challenge>|<installation_id>|<rp_id>`.
- **Durée.** La clé dure **90 jours glissants** : chaque connexion repousse l’échéance.

| Route | Accès | Détail |
|---|---|---|
| `POST /mobile/biometric/enroll` (nouveau) | session + preuve récente | `{challenge_id, challenge, message, expires_in:300}` |
| `POST /mobile/biometric/register` | session | `{challenge_id, public_key (base64 DER SPKI), signature, attestation?, label?}`. EC prime256v1 uniquement : une clé RSA donne 400 `invalid_public_key`. La cérémonie est consommée, puis la signature est vérifiée sur le message stocké. L’ancien corps (un PEM seul) donne 400 `biometric_proof_required`. L’attestation est **seulement journalisée** (`leaf_match` ou `none`, 5 × 8 Ko au plus). L’ancienne clé de l’installation est révoquée (`replaced`). Suivent l’e-mail « Connexion rapide activée » et l’action `dip_mobile_credential_changed`. Renvoie `{registered, key_id, expires_at}`. |
| `POST /mobile/biometric/challenge` | public | Limites : 10 **échecs** par installation lue dans le corps (6.10.2 : 10 demandes), 100 échecs par adresse, en-tête contradictoire refusé et compté comme échec ; chaque défi ne compte que dans la garde d’inondation de l’installation (200 sur 10 min) : annuler l’empreinte et réessayer n’est jamais refusé. Le serveur cherche une clé active de l’installation, **sans session vivante**. Sans clé : 404 `biometric_not_enrolled`. |
| `POST /mobile/biometric/verify` | public | `{challenge_id, signature}`. Consommation atomique ; l’installation **et** l’appareil doivent correspondre à la clé ; la signature est vérifiée. Ensuite viennent la garde d’inondation par clé (200 sur 10 min ; 10 essais dans le brouillon), une **nouvelle** session (`method: biometric`), puis la mise à jour de `last_used_at` et `expires_at`. |
| `GET /mobile/biometric` (nouveau) | session | `{enabled, this_device:{enrolled,key_id,created_at,last_used_at,expires_at} \| null, devices:[…]}` |
| `DELETE /mobile/biometric` (nouveau) | session | Révoque la clé de cette installation, sans preuve. |
| `DELETE /mobile/biometric/{id}` (nouveau) | session + preuve récente | Révoque la clé d’un autre téléphone. |

**Interrupteur.** C’est « Activer la connexion biométrique liée à l’appareil » (`mobile_biometric_enabled`, **éteint par défaut**). Éteint, enrôlement et connexion répondent 403 `quick_sign_in_disabled`. Si la table manque, la réponse est 503 `quick_sign_in_unavailable`.

**Révocation :**
- `revoke_all_for_user()` révoque aussi les clés. Elle est appelée lors d’un changement ou d’une réinitialisation du mot de passe, d’un changement d’e-mail, de l’activation ou de la désactivation de la double authentification, d’un refus de politique, de « déconnecter les autres » et de la réinitialisation par la ligne de commande. Elle annonce `dip_mobile_sessions_revoked($uid, '', 'all', $reason)`, que l’App API écoute déjà.
- Terminer la session **d’un autre** téléphone depuis un téléphone identifié révoque la clé de ce téléphone (`remote_revoke`) et annonce `dip_mobile_sessions_revoked($uid, $installation_hash, 'session', 'user_revoke')`.
- Une **déconnexion** (`/mobile/logout`) garde la clé : se déconnecter n’est pas oublier le téléphone.
- Identity Pro écoute aussi deux événements de l’App API :
  - `delicat_app_revoke_all_external_sessions` révoque sessions et clés ;
  - `delicat_app_erase_customer_data` efface les clés d’accès et les clés de connexion rapide.

## 4. Émission commune des sessions et preuve récente

`DIP_Mobile_API::issue_for_user($uid, $request, $method, $interactive = true)` gère `google`, `passkey`, `biometric` et `password_upgrade` (l’App API, `/auth/identity-session`). Elle :
1. insère une **nouvelle** ligne et révoque les autres lignes vivantes du même compte sur la même installation (`replaced`) ;
2. pose la preuve récente (10 min) quand l’émission est interactive ;
3. écrit l’audit `mobile_session_issued` avec la méthode ;
4. déclenche `do_action('dip_mobile_session_issued', $uid, $row_id, $method, $installation_hash, $is_new_installation)` ;
5. renvoie la charge utile habituelle, plus `method`.

`/mobile/google` passe par elle. `/mobile/refresh` garde `issue()` : aucune alerte, aucune preuve.

**Invariant, écrit dans le code.** `issue_for_user` n’est appelée qu’une fois la double authentification satisfaite :
- Google : le code est exigé ;
- clé d’accès : l’UV est obligatoire ;
- empreinte : la clé n’a été enrôlée que sous une preuve qui satisfaisait la double authentification ;
- `password_upgrade` : mot de passe et code.

**Preuve récente.** C’est le transient `dip_mproof_<id de session>`, pendant 600 s. Une route qui l’exige répond 403 `reauth_required`, avec `data.methods` : `password` toujours, `google` si un compte Google est lié et que Google mobile est configuré, `passkey` si le compte a une clé.

**Routes de réauthentification.** Limite : **10 échecs par installation** sur 10 min (mot de passe, code, jeton Google ou assertion refusés : c’est le garde contre la devinette). Par session, seulement le garde d’inondation de 200 appels sur 10 min : une confirmation réussie ne rapproche jamais un client d’une limite (règle du propriétaire, 2026-10-24).
- `POST /mobile/reauth/password` `{password, two_factor_code?}` ;
- `POST /mobile/reauth/google` `{id_token, two_factor_code?}` : ce doit être le **même** compte Google (sub lié). Une panne des clés Google répond 503 et n’est pas comptée.
- la variante par clé d’accès, au §1.

**Méthodes publiques pour l’App API.** `issue_for_user`, `recently_proven`, `biometric_installations`, `revoke_installation_keys`, `credential_counts` (`{passkeys, biometric}`) et `erase_credentials`.

**Capacités.** `/mobile/capabilities` ajoute :
- `mobile_quick_sign_in: 1` : l’application n’affiche rien sans cette valeur ;
- `passkeys_enabled` ;
- `rp_id`.

`biometric_enabled` vaut maintenant « interrupteur allumé **et** table présente ».

**Pare-feu REST strict.** Les 14 nouvelles routes y sont classées : la connexion par clé d’accès est publique, le reste demande la session mobile liée à l’appareil. « Tester » est classé administrateur. Le Centre de sécurité affiche les nouveaux événements en français.

**Choix assumé.** Une connexion par clé d’accès ou par empreinte dans l’application ne déclenche **pas** `dip_login_success`. Ce hook enregistre un « nouvel appareil » par cookie, que l’application ne garde pas : il aurait envoyé un e-mail « Nouvelle connexion détectée » à **chaque** connexion rapide. L’alerte de nouvel appareil passe par `dip_mobile_session_issued` (App API, première installation seulement). Les audits `login_success` (`mobile_passkey`, `mobile_biometric`) restent écrits.

## 5. Aucune limite d’utilisation : `/mobile/refresh`, `/mobile/google` et les restes du site (2026-10-27)

Règle du propriétaire du 2026-10-24 : « une utilisation réussie ne compte jamais ». Avec la 5.6, les clients « mot de passe » qui activent une clé d’accès ou l’empreinte passent en session Identity : ils renouvellent tous leur session par `/mobile/refresh`.

- `/mobile/refresh` : `throttle(…, $failures_only = true)`. Seuls les **échecs** comptent : 20 par installation, 200 par adresse. Chaque demande ne compte que dans la garde d’inondation de l’installation (200 sur 10 min). Un jeton valide ne compte que dans la garde de sa session, relevée de 20 à `SESSION_FLOOD_LIMIT` (200).
- `/mobile/google` : même chose, 10 échecs par installation et 100 par adresse. Le plafond de 500 essais par adresse et par 10 min reste : un jeton Google vérifié peut créer un compte, c’est la garde contre le remplissage du disque (50 fois le chiffre de l’installation).
- `/pairing/*` (site, éteint par défaut) : le compteur par adresse passe d’un transient réécrit avec un TTL complet à chaque appel (le schéma de l’incident 2.32.2) à `DIP_Lockout::counter_hit()` à fenêtre fixe, sur l’adresse du visiteur derrière Cloudflare (`throttle_ip()`), à 20 fois l’ancien chiffre.
- Connexion par clé d’accès **sur le site** : les seaux par adresse passent dans la table des compteurs, sur `throttle_ip()` (avant : `client_ip()`, c’est-à-dire l’adresse du relais Cloudflare sans l’option de confiance). `login/options` garde un plafond d’inondation de 600 par 10 min (avant : 30) ; `login/verify` ne compte plus que les assertions refusées (100 par adresse, 18 par compte et adresse), lues avant la vérification.
- Inchangé, à décider par le propriétaire : l’inscription sur le site (`native_reg_max_per_hour`, 3 par adresse et par heure par défaut, 20 au plus) compte par `client_ip()`.

Tests : `mobile_throttle_smoke.php` gardes 1, 2, 4, 5 réécrites exprès (500 installations derrière une adresse, 60 renouvellements de suite sur une installation, 200 puis la garde d’inondation, 20 échecs par installation, 30 connexions Google de suite) ; `mobile_passkeys_smoke.php` §9 (la comparaison octet par octet retire aussi ces seaux) ; nouveau `pairing_throttle_smoke.php`.

## Fichiers

Nouveaux :
- `includes/class-dip-mobile-passkeys.php`
- `includes/class-dip-android-app.php`
- `tests/harness/identity_wp.php`
- `tests/mobile_passkeys_smoke.php`
- `tests/mobile_biometric_smoke.php`
- `tests/assetlinks_smoke.php`

Modifiés :
- `includes/class-dip-mobile-api.php` : émission commune, preuve récente, réauthentification, table et routes de connexion rapide, révocations, capacités, `sessions()`, `revoke_session()`, nettoyage. Les limiteurs 6.10.2 sont inchangés ; seule leur visibilité passe à `public` (`@internal`) pour les clés d’accès mobiles.
- `includes/class-dip-passkeys.php` : visibilité seulement.
- `includes/class-dip-plugin.php` : réglages par défaut, formulaire, carte, `RUNTIME_SCHEMA_VERSION` 6.11.0.
- `includes/class-dip-security-architecture.php` : routes classées.
- `includes/class-dip-access-guard.php` : `dip_assetlinks_test`.
- `includes/class-dip-security-center.php` : libellés.
- `delicat-google-login.php` : 6.11.0 et chargement des deux classes.
- `readme.txt`
- `tests/mobile_throttle_smoke.php` : garde 3.

## Vérification

```
php -n -d extension_dir="<php>\ext" -d extension=pdo_sqlite -d extension=openssl -d extension=mbstring tests/<test>.php
```

| Test | Résultat | Ce qu’il couvre |
|---|---|---|
| `mobile_throttle_smoke.php` | 65 contrôles verts ; 62 avec `DIP_COUNTERS=transients` | Garde 3 réécrite **exprès** pour le nouveau contrat : clé dans la nouvelle table, `challenge_id`, message `DLC-BIO1`, clé EC. **Seuils inchangés** : 10 par installation, plafond de 100 échecs, 10 par clé. Le nombre d’audits est inchangé. |
| `mobile_passkeys_smoke.php` | 78 contrôles verts | Authentificateur logiciel en vraies clés P-256, avec objets d’attestation CBOR et `clientDataJSON` aux origines `android:apk-key-hash` : capacités et origines ; enregistrement sans preuve (403 et méthodes) ; preuve par mot de passe ; enregistrement en une étape avec la clé d’envoi **et** la clé Play ; refus (autre signature d’application, origine web, autre installation, rejeu, `packed`, sans UV, autre RP ID) ; limite de 8 ; connexion découvrable (session, `is_new_installation`, session remplacée) ; 10 refus en 401 générique comptés contre l’adresse (autre signature, origine web, rejeu, autre installation, compteur en arrière, poignée inconnue, mauvaise signature, sans UV, poignée d’un autre compte) ; 18 assertions refusées par compte ; une cérémonie du site refusée ; interrupteurs (empreinte de test cochée ou non) ; preuve jamais posée par un renouvellement ; réauthentification par clé ; **aucune limite d’utilisation** (403 avant preuve jamais compté, 40 confirmations, 60 `register/options`, 30 `reauth/passkey`, 30 connexions réussies d’un même compte d’affilée tous servis, seau du compte vide ; seul le garde d’inondation répond 429 au 201e appel) ; liste, suppression, dernier moyen ; **flux du site inchangé** (fichier identique à la 6.10.2 hors visibilité, enregistrement web en deux étapes, connexion web, origine d’application refusée par le site) ; routes enregistrées et classées par le pare-feu. |
| `mobile_biometric_smoke.php` | 61 contrôles verts | Versions et schéma (SQL de la table et ses index) ; preuve par mot de passe, par Google (autre compte refusé) et avec code de double authentification (428, 401, puis succès) ; message d’enrôlement exact ; ancien corps refusé (400) ; clé RSA refusée ; mauvaise signature ; cérémonie d’un autre téléphone ; attestation `leaf_match`, `none` ou trop longue ; une clé par installation ; libellé ; **connexion après déconnexion** ; message de connexion exact ; nouvelle session ; rejeu, autre appareil, autre installation, autre clé et compte bloqué refusés ; expiration glissante (jour 60 utilisé, jour 120 actif, jour 211 expiré) ; `revoke_all_for_user` qui tue la clé et annonce `dip_mobile_sessions_revoked` ; `GET`, `DELETE` et `DELETE {id}` ; révocation à distance ou de sa propre session ; `/mobile/sessions` ; interrupteur et table absente ; API pour l’App API et ses deux événements ; `issue_for_user` (`password_upgrade`, non interactive, méthode inconnue, API éteinte) ; **aucune limite d’utilisation** (403 avant preuve jamais compté, 40 activations `enroll` + `register` d’affilée sur une session toutes servies, 201e `enroll` en 429 par le seul garde d’inondation). |
| `assetlinks_smoke.php` | 40 contrôles verts | JSON exact, en-têtes, HEAD ; chemin exact (`?x=1` servi ; `…jsonx`, barre finale, majuscule, sous-dossier, `%61` et vide refusés) ; GET et HEAD seulement ; interrupteur ; normalisation ; lignes invalides ; doublons ; empreinte de test ; maximum de 10 ; paquet ; **les deux origines calculées égales aux chaînes du §3.3** et dérivées du fichier publié ; valeurs par défaut ; vraie requête par `maybe_serve()` dans un processus fils (le JSON puis l’arrêt) ; formulaire ; notices ; « Tester » (site, Google, défi Cloudflare, nonce, droits). |

Tous les fichiers modifiés passent `php -l`, sans syntaxe au-delà de PHP 7.4. Le site exige PHP 8.3 ou plus.

## Non vérifié (site réel)

- MySQL ou MariaDB réel : `dbDelta` de la nouvelle table, requêtes `expires_at > %s`.
- Credential Manager réel :
  - format exact de `clientDataJSON` et de l’attestation du Gestionnaire de mots de passe Google (attendu : `fmt: none`, compteur 0) ;
  - clés créées sur le site puis utilisées dans l’application, si elles sont synchronisées.
- Cloudflare devant `/.well-known/assetlinks.json` (règle « Skip » à créer) et le cache de Google.
- E-mails réels.

## Pour l’intégration (autres couloirs)

- **App API 2.33.0 (partie 2), à faire :**
  - `/auth/identity-session` appelle `DIP_Mobile_API::issue_for_user($uid, $request, 'password_upgrade')` ;
  - écouter `dip_mobile_session_issued` et `dip_mobile_credential_changed($uid, $kind, $event, $label)` ;
  - pour « Mes données », utiliser `credential_counts()` et `erase_credentials()` ;
  - lors de la révocation d’une session depuis l’application, appeler `revoke_installation_keys($uid, $installation_hash, 'remote_revoke')`. Son propre « déconnecter les autres » met à jour la table par SQL et ne touche pas aux clés : appeler aussi `revoke_installation_keys` pour chaque installation fermée si les clés doivent tomber.
- **Application :**
  - `POST /mobile/reauth/google` peut répondre 428 `two_factor_required` quand le compte a la double authentification. Il faut alors renvoyer `id_token` avec `two_factor_code`, comme pour `/mobile/google`.
  - `register` accepte un `label` facultatif (nom du téléphone). Sinon, c’est `X-Device-Name`, que l’application envoie aujourd’hui sous la forme « Delicat Android ».

## Installation (propriétaire)

1. Vérifier que l’adresse `home` du site est `https://delicastoreha.com`, sans `www` : c’est le RP ID.
2. Remplacer le plugin par le ZIP 6.11.0, sans le désinstaller. Le premier chargement crée la table des clés de connexion rapide.
3. Dans les réglages :
   - cocher « Activer la connexion biométrique liée à l’appareil » ;
   - laisser les Passkeys autorisées ;
   - vérifier la carte « Application Android », où les deux empreintes sont déjà remplies ;
   - cliquer « Tester ».
4. **Cloudflare :**
   - créer une règle WAF « Skip » pour `/.well-known/assetlinks.json` (Bot Fight Mode, défis gérés, Under Attack) ;
   - ne mettre aucune redirection sur ce chemin ;
   - purger ce chemin dans LiteSpeed et dans Cloudflare.
5. Supprimer un éventuel fichier physique `.well-known/assetlinks.json` (une notice le signale).
6. `curl -sI https://delicastoreha.com/.well-known/assetlinks.json` doit répondre 200 avec `application/json`. Google met la réponse en cache : prévoir quelques heures avant l’essai sur téléphone.
7. Ensuite seulement : App API 2.33.0, puis l’application 5.6.0.
