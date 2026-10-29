# 6.12.0 — Audit complet : sécurité, Passkeys fluides et nouvel espace client

## Demande (2026-10-28)

Le propriétaire demande une revue complète : corriger tous les bugs et toutes les failles, faire fonctionner sans accroc les Passkeys et les protections de sécurité, rendre l’espace client (« Mon compte ») plus solide, très sûr, moderne et plus rapide.

## Méthode

- Audit de tout le code (13 sous-systèmes, plus une revue transversale de chaque point d’entrée REST/AJAX/admin-post et une suite de tests Passkeys de bout en bout). Environ 170 constats bruts, dédoublonnés, chacun vérifié dans le code avant correction ; les constats refusés sont listés plus bas.
- Corrections par propriétaire de fichiers, puis relecture indépendante de chaque diff (avec réparations). Toutes les corrections ont été testées sur un WordPress 6.6 de test (SQLite, PHP 8.3) ; le code reste compatible PHP 7.4 et WordPress 6.2.
- Suite Passkeys de bout en bout (Chromium + authentificateur virtuel WebAuthn) : **49 / 49** scénarios réussis, contre 22 / 40 sur la 6.11.0. Activation sur une base vierge, mise à jour depuis la 6.11.0, toutes les pages d’administration, toutes les shortcodes et les routes REST : aucune notice PHP de l’extension.

## 1. Failles corrigées (par gravité)

### Critique
- **Jeton de l’application détourné vers l’API WordPress.** Un jeton mobile valide (avec les en-têtes de l’appareil) pouvait appeler n’importe quelle route WordPress en plaçant `rest_route=/wp/v2/users/me` dans le corps d’une requête vers `/delicat-identity/v1/…` : lecture du profil complet, **changement du mot de passe ou de l’e-mail** sans preuve récente. La route est maintenant celle que WordPress va réellement servir (refus par défaut), plus une seconde barrière au moment du dispatch (variante `batch/v1` comprise). Tentative bloquée et journalisée (`mobile_identity_route_refused`). Reproduction de l’audit rejouée sur la 6.12.0 : refusée.

### Élevée
- **Pré-appropriation de compte.** Un compte créé avec l’adresse d’une autre personne (jamais vérifié) devenait le compte vérifié de la vraie propriétaire dès sa première connexion Google, code e-mail ou lien magique, **en gardant le mot de passe et les sessions du créateur**. Désormais : mot de passe aléatoire, sessions Web et App fermées, e-mail d’information ; fusion refusée si le compte porte déjà une Passkey, une 2FA ou un autre fournisseur.
- **Inscription Microsoft (et Google hors Gmail/Workspace).** Le fournisseur n’est relié qu’après confirmation de l’adresse depuis le **même navigateur** (lien en attente, `_dip_pending_provider_link`).
- **Code de connexion par e-mail : limite de 5 essais contournable** par requêtes simultanées. Comptage atomique, garde côté vérification, seuls les échecs comptent.
- **Connexion par code/lien e-mail réservée aux clients** : un compte d’équipe (administrateur, gestionnaire) ne peut plus s’ouvrir par simple accès à la boîte mail.
- **« Mot de passe oublié » WooCommerce** ne fermait ni les sessions de l’application ni la connexion rapide, et n’envoyait pas l’alerte « mot de passe modifié ». Tout passe maintenant par `wp_set_password` (WordPress 6.2+), une seule fois par changement.
- **Mon compte › Détails du compte** : la confirmation d’identité avant un changement d’e-mail ne s’appliquait jamais (WooCommerce transmet un `stdClass`). Elle s’applique.
- **Mode Administrateur sécurisé Google** : « Retirer l’autorisation Google Admin », la désactivation du TOTP et la déconnexion de Google laissaient la continuité Nextend ouvrir une session Administrateur. Continuité supprimée, et une identité Google déconnectée ne peut plus être reliée en silence par l’ancien lien Nextend.
- **Renouvellement mobile** : un `/mobile/refresh` en cours pouvait faire revivre une session fermée par un changement de mot de passe ou « déconnecter les autres ». La rotation exige maintenant une ligne encore active (même garantie pour l’empreinte).

### Moyenne (sélection)
- 2FA : anti-rejeu et codes de récupération à usage unique garantis même en parallèle (verrou exclusif, cache méta invalidé) ; le code d’activation ne sert plus à se connecter ; plafond journalier des codes faux (50, échecs seulement) avec alerte au client à 10 et à 50.
- Comptes 2FA : le mot de passe seul ne confirme plus une action sensible (code 2FA, code de récupération ou Passkey).
- Relier Google/Microsoft à un compte exige une confirmation d’identité récente ; la liaison est refusée si la session n’appartient plus au compte qui l’a lancée ; un fournisseur tiers ne peut plus se présenter comme « google ».
- Pare-feu REST : les variantes de casse du namespace contournaient les règles et les en-têtes anti-cache ; toutes les réponses Identity désactivent le cache LiteSpeed ; journal protégé contre l’inondation.
- CSS personnalisé des e-mails : sortie du bloc `<style>`, `@import`, `expression()` et `javascript:` neutralisés (y compris valeurs déjà enregistrées) ; aperçu isolé (CSP, sans script) et envoyé en POST.
- Boutique headless : une session déjà ouverte n’est plus convertie en session boutique sans action du client (page de confirmation) ; plus de liaison Google implicite ; PKCE S256 facultatif.
- Appairage navigateur ↔ app : approbation avec preuve récente de l’app, contexte du demandeur (`POST /pairing/preview`), protection contre les sites tiers.
- Vérification d’e-mail désactivée : une adresse non prouvée ne rattache plus les commandes invité d’une autre personne.
- Liste publique des clés Passkey : ne révèle plus l’existence d’un compte ni le nombre de Passkeys.

## 2. Passkeys : fluides partout

- **Connexion sans saisir l’e-mail** (clé découvrable) sur wp-login.php, les formulaires WooCommerce, la fenêtre de connexion et `[delicat_auth_panel]` ; avec un e-mail, rien ne change.
- **Remplissage automatique** (suggestion de la Passkey dans le champ e-mail) quand le navigateur le permet, annulé proprement au clic ou à la connexion par mot de passe.
- **Ajout en une seule fenêtre** Face ID / empreinte (au lieu de deux) ; plus de Passkey « fantôme » dans le trousseau.
- **Plus de 428 sans issue** : une connexion récente (Google, code e-mail, lien magique, mot de passe, Passkey) compte comme confirmation ; les clients sans mot de passe ont une **confirmation par code e-mail** (6 chiffres, haché, 10 min, 5 essais) ; la carte Passkeys propose la confirmation sur place puis termine l’action.
- Boutons actifs sur `[delicat_security_center]`, `[delicat_identity_dashboard]`, `[delicat_auth_panel]` et `wp_login_form()` (chargement à la demande) ; les pages publiques ne chargent plus de fichiers Passkey inutiles ; configuration protégée de Rocket Loader / LiteSpeed.
- Messages d’erreur en français dans tous les cas ; « Se souvenir de moi » et `redirect_to` respectés ; l’équipe arrive dans l’administration.
- Carte modernisée : nom selon l’appareil (« iPhone · Safari »), « Ajoutée le » / « Dernière utilisation », badge « Synchronisée », contrastes AA, thème sombre ; Signal API (nettoyage du trousseau iCloud/Google) ; délai porté à 2 minutes comme dans l’app.
- Abaisser « Passkeys par compte » ne supprime plus jamais de Passkeys ; identifiants longs supprimables (site, app, pare-feu : jusqu’à 1366 caractères) ; seuls les échecs sont limités.
- Application : blocage après 18 refus par compte **et** par adresse ; plafond anti-inondation de `login/options` à 1000 / 10 min par adresse ; identifiants de cérémonie inventés refusés sans écriture.

## 3. Espace client (Mon compte, Identité et sécurité)

- Nouveau design unifié (jetons de couleur), **mode sombre automatique** (préférence du système ou thème sombre du site ; filtre `dip_account_color_scheme` = `auto` | `light` | `dark`), contrastes WCAG AA, textes ≥ 12 px, focus clavier visible, animations réduites sur demande, mise en page de 320 px à l’ordinateur, navigation complète sur mobile.
- Plus rapide : compteurs, barre de protection et solde rendus côté serveur (pas de saut à l’affichage), feuille CSS allégée, une seule lecture des appareils par page, une feuille de style en moins.
- Sécurité : états indiqués par icône + texte, pages client jamais mises en cache, avatar sans Gravatar par défaut (filtre `dip_account_use_gravatar`), commandes récentes via la requête WooCommerce (pas de brouillons), « Masquer le solde » mémorisé (cookie fonctionnel `dipma_balance_hidden`).
- Centre de sécurité : historique en français (Info / À noter / Attention / Critique), événements techniques masqués et regroupés, appareil courant marqué « Cet appareil » et pouvant être approuvé, confirmations intégrées, listes compactes, recommandations cliquables.
- Onglet Application : téléphones connectés (dernière activité, déconnexion d’un ou de tous), affiché seulement si l’API mobile est active ; QR code pour connecter un ordinateur.
- 2FA : QR code généré sur le serveur (aucun service externe), page de vérification modernisée (clavier numérique, code de récupération, « Annuler »).

## 4. Autres corrections

- Fenêtre de connexion : retour à la page prévue après connexion par mot de passe ; destination explicite (`?redirect_to=`), panier, paiement et « Payer pour cette commande » respectés ; mots de passe contenant `'` `"` `\` acceptés partout ; préparation au premier geste seulement (plus rapide) ; wp-login.php demande le code 2FA au lieu de « identifiants incorrects ».
- Verrouillage par mot de passe compté de façon atomique ; l’allongement progressif (15, 30, 60 puis 120 min) s’applique.
- Email Studio : styles intégrés aux balises pour les e-mails Identity et WordPress, version texte, lien de réinitialisation toujours présent, objets/titres WooCommerce du Studio appliqués, preheader conservé, lien « Payer ma commande », données fictives dans les aperçus, plus de parcours du dossier des extensions à chaque requête.
- Visual Builder : réglages enfin appliqués sur le site, options désactivées qui ne se réactivent plus, import robuste ; `[delicat_auth_panel]` avec Passkey et étape 2FA.
- Administration : les anciennes adresses `options-general.php?page=…` mènent à la bonne page avec un titre (plus d’avertissement PHP) ; chaque action affiche son résultat ; « Réparer automatiquement » recrée toutes les tables.
- Performance : réglages lus une fois par requête et chargés automatiquement ; plus de requête DNS par avatar Google ; migration jamais exécutée pendant une page boutique ; tâches planifiées retirées à la désactivation.
- Appareils : une mise à jour du navigateur ne crée plus de « nouvel appareil » ; les connexions App ne créent plus d’appareil fantôme ; appareils et appairages supprimés avec le compte et par l’outil d’effacement des données.
- Analytics : plus de double comptage (renouvellements App), totaux historiques préservés après nettoyage du journal.

## 5. Changements de comportement à connaître

- **Application (App API / app)** : `POST /pairing/approve` répond `403 reauth_required {methods}` sans preuve récente de l’app (comme `biometric/enroll`) ; l’app doit confirmer puis réessayer, et peut afficher `POST /pairing/preview` avant d’approuver (appairage désactivé par défaut). Une identité mobile n’est honorée que sous les préfixes du filtre `dip_mobile_bearer_auto_auth_prefixes` (par défaut `/delicat-identity/v1/`). Inscriptions App avec Google hors Gmail/Workspace ou Microsoft : pas de liaison en attente (pas de navigateur) ; le fournisseur se relie depuis le compte une fois l’e-mail vérifié (`email_exists` d’ici là).
- **Delicat Builder V9** : les pages publiques ne chargent plus `passkeys.js`. Un bouton `[data-dip-passkey-login]` imprimé par le thème doit passer par `DIP_Passkeys::render_login_button()` ou activer `add_filter('dip_passkeys_enqueue_storefront', '__return_true')`.
- **Limites de la fenêtre de connexion** : les seaux mot de passe, inscription et renvoi comptent par visiteur (`throttle_ip()`, adresse Cloudflare vérifiée, IPv6 par /64) au lieu de `client_ip()` ; seuils inchangés, fenêtres fixes de 24 h dans `dip_rate_counters`. Cela remplace la note 6.9.20 et tranche la question ouverte de la 6.11.0.
- `[delicat_auth_panel]` : après connexion, redirection vers la destination de connexion (par défaut `/my-wallet/`), un `?redirect_to=` du même site, ou le panier / paiement (avant : la page du panneau).
- Plafond journalier 2FA (50 codes faux) : il bloque aussi les codes de récupération jusqu’à une connexion Passkey ou une réinitialisation du mot de passe.
- Lien de vérification d’e-mail du compte (`email_link`) : il garde le mot de passe du titulaire du compte (choix assumé : c’est la preuve prévue pour ce compte).
- Nouveaux points techniques : actions admin-post `dip_reauth_email_code` (client) et `dip_2fa_admin_reset` (administrateur, comptes clients seulement) ; action `dip_identity_resolved($user_id, $profile, $link_user)` ; 3ᵉ argument `$sub` sur `dip_account_disconnected` ; méta `_dip_pending_provider_link`, `_dip_disconnected_provider_subjects` ; cookie `dip_ppl_*` ; schéma des réglages Email Studio 8 (migré en mémoire, enregistré à la prochaine sauvegarde).
- Documents antérieurs : PASSKEYS-SECURITY-6.8.0.md décrit encore l’enrôlement en deux étapes, le remplissage aléatoire et les seaux d’options ; la présente note les remplace. L’affirmation de FIX-6.11.0 (class-dip-passkeys.php identique à la 6.10.2 hors visibilité) ne tient plus.

## 6. Constats examinés et non retenus

- Compteur de signature : ne pas rejeter les clés synchronisées dont le compteur ne croît pas (BE/BS) — refusé : c’est la détection de clone prévue (PASSKEYS-SECURITY-6.8.0), et iCloud / Google Password Manager renvoient 0, accepté.
- Délai WebAuthn de 300 s — refusé (la limite cliente de 6.9.21 est volontaire) ; aligné sur 120 s comme l’app.
- Liste des appareils/sessions liée aux jetons de session WordPress (« Supprimer cet appareil » déconnecterait ce navigateur) — reporté.
- Reconfirmation Google depuis la carte de confirmation (prompt=login) — non fait : le code e-mail, la Passkey et la reconnexion couvrent les clients sans mot de passe.

## 7. À vérifier sur la préproduction (impossible ici)

- WooCommerce 9.8+ réel et TeraWallet : titres d’onglets, `identity-center`, panneau du paiement en blocs, liste des commandes, e-mails WooCommerce réels (inliner, objets, réinitialisation, facture).
- Appareils réels : Safari iOS/macOS (remplissage automatique puis bouton Passkey, enrôlement en une fenêtre), Android Chrome QR multi-appareil, Credential Manager de l’app.
- MySQL/MariaDB réel (verrous et compteurs en parallèle), LiteSpeed/LSCWP, Cloudflare, envoi SMTP (alignement DMARC de l’expéditeur forcé par Email Studio).
- Le compte d’un client resté connecté depuis la 6.11.0 n’a le badge « Cet appareil » qu’après sa prochaine connexion (migration du hachage d’appareil).

## Tests

- `tests/identity-pro/passkeys-e2e.mjs` (dépôt, hors ZIP) : `node passkeys-e2e.mjs http://<site> <dossier du site> --parallel-port=<port>` — 49 scénarios (enrôlement, toutes les surfaces de connexion, sans e-mail, remplissage automatique, confirmation, suppression, 2FA, rejeu, liaison au navigateur, erreurs, limites, concurrence, chargement à la demande). Fixtures : `tests/identity-pro/fixtures.php`.
