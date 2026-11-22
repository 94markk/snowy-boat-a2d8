# Publier Delicat Studio sur Google Play

Application : `ht.delicat.delicat_studio`, version 1.2.0+3 (versionName 1.2.0, versionCode 3).
Toutes les commandes se lancent depuis le dossier `delicat_studio/`.

## 1. Avant chaque version

- [ ] `pubspec.yaml` : augmenter le numéro après le `+` à chaque envoi sur Google Play (le versionCode doit toujours monter).
- [ ] `flutter analyze` et `flutter test` passent.
- [ ] Tester la version release sur un vrai téléphone, de préférence un modèle modeste : détourage, collage, éditeur photo, export vidéo, voix off, partage WhatsApp, enregistrement dans la galerie.
- [ ] Décider de la sauvegarde Android : sans `android:allowBackup="false"` (ou des règles d'exclusion) dans `AndroidManifest.xml`, Android peut copier les brouillons, le kit de marque et les réglages dans la sauvegarde Google de l'utilisateur. La politique de confidentialité dit que ces données restent sur le téléphone : les deux doivent rester d'accord.
- [ ] Mettre à jour `PRIVACY.md` et l'écran `PrivacyScreen` si une fonction touche aux données (même texte, même date).

## 2. Créer la clé d'importation (une seule fois)

```bash
tool/make_upload_key.sh            # keystore par défaut : $HOME/delicat-upload.jks
```

Le script demande un mot de passe (jamais affiché), crée le keystore hors du dépôt et écrit `android/key.properties`. Ce fichier et les keystores sont ignorés par git.

- Sauvegarder le keystore et son mot de passe à deux endroits sûrs (gestionnaire de mots de passe + copie hors ligne).
- En cas de perte : demander une réinitialisation de la clé d'importation dans la Play Console (Intégrité de l'application > Signature d'application). Google garde la clé de signature de l'application, mais la procédure prend du temps.
- Sans `android/key.properties`, les builds release sont signés avec la clé de débogage : pratique pour tester, refusé par la Play Console.

## 3. Construire l'app bundle

```bash
flutter build appbundle --release
```

Le fichier à envoyer : `build/app/outputs/bundle/release/app-release.aab`.

Vérifier qu'il est signé avec la clé d'importation (et non « Android Debug ») :

```bash
keytool -printcert -jarfile build/app/outputs/bundle/release/app-release.aab
```

APK pour tester à la main : `flutter build apk --release --split-per-abi`, fichiers dans `build/app/outputs/flutter-apk/` (`app-arm64-v8a-release.apk` pour la plupart des téléphones récents).

### Avec GitHub Actions

Le workflow `.github/workflows/delicat-studio.yml` construit les APK et l'AAB à chaque push qui touche `delicat_studio/`, et les garde 14 jours dans les artefacts. Pour qu'il signe avec la clé d'importation, ajouter ces secrets au dépôt :

| Secret | Valeur |
| --- | --- |
| `UPLOAD_KEYSTORE_BASE64` | `base64 -w0 ~/delicat-upload.jks` (macOS : `base64 -i ~/delicat-upload.jks`) |
| `UPLOAD_KEYSTORE_PASSWORD` | mot de passe du keystore |
| `UPLOAD_KEY_ALIAS` | `upload` (ou l'alias choisi) |
| `UPLOAD_KEY_PASSWORD` | même mot de passe que le keystore (format PKCS12) |

Sans ces secrets, la CI signe avec la clé de débogage : ses AAB ne sont pas publiables.

## 4. Play Console

### Créer l'application

- Nom : Delicat Studio. Langue par défaut : français. Type : application.
- Gratuite ou payante : à décider (une application gratuite ne peut plus devenir payante).
- Signature d'application par Google Play : garder l'option proposée (clé générée par Google). Notre clé ne sert qu'à l'importation.

### Contenu de l'application (Règles et programmes > Contenu de l'application)

- **Règles de confidentialité** : publier `PRIVACY.md` sur une page web publique et donner son adresse. Suggestion : `https://delicastoreha.com/confidentialite`.
- **Annonces** : non, l'application ne contient pas de publicité.
- **Accès à l'application** : toutes les fonctionnalités sont disponibles sans compte ni accès particulier.
- **Classification du contenu** : questionnaire IARC, catégorie « Utilitaires, productivité, communication ou autre ». Pas de violence, de contenu sexuel, de jurons, de drogue ni de jeux d'argent. Pas d'échange entre utilisateurs dans l'application (le partage passe par d'autres applications), pas de partage de position, pas d'achats numériques. Résultat attendu : tout public.
- **Public cible** : suggestion « 18 ans et plus », car c'est un outil pour une boutique. Choisir des tranches de moins de 13 ans ferait entrer l'application dans le programme Familles, avec des exigences en plus.
- **Identifiant publicitaire** : non utilisé. Vérifier dans l'explorateur d'app bundle que l'autorisation `com.google.android.gms.permission.AD_ID` n'apparaît pas.
- **Autres déclarations** (actualités, applications gouvernementales, fonctionnalités financières, santé) : aucune ne s'applique.

### Sécurité des données

Réponses déduites du fonctionnement de l'application :

| Question | Réponse |
| --- | --- |
| L'application collecte ou partage des données utilisateur ? | Non : photos, vidéos, voix off, brouillons et kit de marque sont traités et gardés sur le téléphone. L'application n'a pas de serveur, pas de compte, pas d'analytics, pas de publicité. |
| Photos et vidéos | Non collectées : choisies avec le sélecteur d'Android, traitées sur le téléphone. |
| Audio (voix off) | Non collecté : enregistré seulement quand l'utilisateur appuie sur enregistrer, gardé dans l'application. |
| Partage vers WhatsApp ou le menu de partage | Action lancée par l'utilisateur vers l'application de son choix. |
| Journal des problèmes | Gardé sur le téléphone. Il ne part que si l'utilisateur touche « Signaler un problème » et choisit lui-même où l'envoyer (modèle du téléphone, version d'Android, RAM, journal). Choix prudent si ces rapports arrivent à la boutique : déclarer « Infos et performances de l'appli > Journaux de plantage et Diagnostics », collectés, non partagés, facultatifs, finalité « Analyse ». |

À vérifier avant d'envoyer le formulaire : les services Google Play fournissent le modèle de détourage ML Kit. Lire la page de Google « ML Kit : Android data disclosure » (developers.google.com/ml-kit/android-data-disclosure) et ajouter au formulaire ce qu'elle demande de déclarer.

### Fiche Play Store

Voir la fiche plus bas. Coordonnées : adresse e-mail de contact (obligatoire), site `delicastoreha.com`.

### Tests fermés d'abord

1. Tests > Tests fermés : créer un canal, ajouter les testeurs (liste d'e-mails ou groupe Google).
2. Créer une version, envoyer `app-release.aab`, rédiger les notes de version, publier sur ce canal.
3. Envoyer le lien d'inscription aux testeurs ; ils installent depuis Google Play.
4. Compte développeur personnel récent : Google demande au moins 12 testeurs inscrits pendant 14 jours d'affilée avant de pouvoir demander l'accès à la production.
5. Corriger les retours, puis promouvoir la version en production (déploiement progressif possible).

## 5. Fiche Play Store (brouillon)

**Titre** (14 / 30 caractères)

> Delicat Studio

**Description courte** (77 / 80 caractères)

> Détourage, collages, affiches promo et vidéos, prêts à partager sur WhatsApp.

**Description complète** (environ 1 300 / 4 000 caractères)

> Delicat Studio est l'atelier visuel de Delicat Store Haiti. Préparez des photos, des affiches et des vidéos pour vos produits, directement sur votre téléphone.
>
> Ce que vous pouvez faire :
> • Détourer un produit : le fond est retiré sur le téléphone avec Google ML Kit.
> • Assembler plusieurs photos dans un collage.
> • Retoucher une photo et lui appliquer un style.
> • Créer une affiche promo à partir d'un modèle.
> • Enregistrer votre kit de marque (nom, logo, couleurs) une fois et le retrouver dans les modèles, les vidéos et les codes QR.
> • Créer des codes QR.
> • Monter une vidéo et l'exporter sur le téléphone.
> • Ajouter une voix off enregistrée au micro, ou une musique générée libre de droits.
> • Partager directement sur WhatsApp ou avec le menu de partage, ou enregistrer dans la galerie.
>
> Vos créations restent chez vous :
> • Pas de compte à créer.
> • Pas de publicité.
> • Pas de statistiques d'utilisation.
> • Vos photos ne sont pas envoyées sur un serveur. Le modèle de détourage est fourni par les services Google Play.
> • Le micro sert seulement à la voix off, quand vous appuyez sur enregistrer.
> • Vos brouillons sont gardés dans l'application.
>
> Un souci ? Touchez « Signaler un problème » dans l'application : vous choisissez à qui envoyer le rapport.
>
> Delicat Store Haiti : delicastoreha.com

## 6. Images à préparer

- **Icône** : 512 × 512 px, PNG 32 bits, 1 Mo maximum.
- **Image de présentation** : 1024 × 500 px, JPEG ou PNG 24 bits sans transparence.
- **Captures d'écran téléphone** : de 2 à 8, PNG ou JPEG, 8 Mo maximum chacune, format 9:16. Conseillé : 1080 × 1920 px (au moins 4 captures d'au moins 1080 px pour être mis en avant). Le grand côté ne doit pas dépasser le double du petit, donc pas de 1080 × 2400.
  1. Accueil avec les outils.
  2. Détourage : un produit avant et après.
  3. Collage de plusieurs photos.
  4. Éditeur photo avec un style appliqué.
  5. Affiche promo créée à partir d'un modèle, avec le kit de marque.
  6. Éditeur vidéo avec musique ou voix off.
  7. Kit de marque.
  8. Partage vers WhatsApp ou code QR.
- Utiliser de vrais produits de la boutique. Sur les affiches des captures, seulement des prix confirmés par la boutique, sinon aucun prix.
- Captures tablette 7 et 10 pouces : facultatives.
