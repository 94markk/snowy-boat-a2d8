# Déployer Delicat Store sur Hostinger (WordPress + WooCommerce)

Ce guide part d'un hébergement Hostinger vierge (ou d'un site existant que vous
voulez remplacer) et arrive à une boutique en ligne prête à vendre sur
**delicastoreha.com**. Comptez 30 à 45 minutes.

Le thème est le fichier `dist/delicat-store.zip` (construit par
`scripts/package-theme.sh`). Tout ce qui est décrit ici se fait dans le
navigateur : hPanel de Hostinger, puis l'administration WordPress.

---

## 0. Avant de commencer — sauvegardez

Si delicastoreha.com tourne déjà :

1. hPanel › Sites web › Gérer › **Sauvegardes** › « Générer une nouvelle
   sauvegarde », puis téléchargez-la (fichiers **et** base de données).
2. Dans WordPress, **Outils › Exporter** (tout le contenu) et
   **WooCommerce › Produits › Exporter** (CSV) : vous gardez vos produits.
3. Notez les plugins actifs (Extensions › Extensions installées) et faites une
   capture d'écran de WooCommerce › Réglages › Paiements.

**Conseil fort : testez sur le staging d'abord.** hPanel › Sites web › Gérer ›
**Staging** › « Créer un environnement de staging ». Vous obtenez une copie du
site sur une adresse temporaire ; tout ce qui suit s'y fait à l'identique, et
« Publier » bascule en production quand vous êtes satisfait.

## 1. Préparer l'hébergement

Dans hPanel › Sites web › Gérer :

| Réglage | Où | Valeur |
|---|---|---|
| Version PHP | Avancé › Configuration PHP | **8.2** ou 8.3 (minimum 7.4) |
| Limite mémoire | Avancé › Configuration PHP › Options PHP | `memory_limit` 256M, `upload_max_filesize` 64M, `post_max_size` 64M |
| SSL | Sécurité › SSL | Actif, forcer HTTPS |
| Cache | Avancé › Cache | Activé (LiteSpeed) |
| Cache objet | Avancé › Cache | Redis/Object cache activé si proposé |

## 2. Installer WordPress (site neuf uniquement)

hPanel › Sites web › **Ajouter un site web** › WordPress › suivez l'assistant
(choisissez la langue **Français**). Si WordPress est déjà installé, passez.

Puis dans WordPress › **Réglages › Général** :

- Titre du site : `Delicat Store`
- Slogan : `Jeux, gift cards, streaming et services numériques en Haïti`
- Langue du site : **Français**
- Fuseau horaire : `Port-au-Prince`

**Réglages › Permaliens** : « Titre de la publication » (`/%postname%/`).

## 3. Installer WooCommerce

Extensions › Ajouter › cherchez **WooCommerce** › Installer › Activer.
L'assistant WooCommerce peut être **ignoré** (« Passer la configuration
guidée ») : le thème règle l'essentiel en un clic à l'étape 5.

## 4. Installer le thème

1. Apparence › Thèmes › **Ajouter** › **Téléverser un thème**.
2. Choisissez `delicat-store.zip` › Installer maintenant › **Activer**.
3. Vous êtes redirigé vers **Apparence › Delicat Store** (la mise en route).

À l'activation le thème a déjà créé : la page d'accueil, Support, Comment ça
marche, Bon Kliyan, les 10 pages légales, et les deux menus.

## 5. La mise en route (Apparence › Delicat Store)

Cliquez, dans l'ordre :

1. **Régler WooCommerce pour Haïti** — devise HTG, pays Haïti, livraison
   désactivée (produits numériques), commande sans compte, et les pages
   WooCommerce passent en français : `/boutique/`, `/panier/`, `/commander/`,
   `/mon-compte/`.
2. **Panier / commande / compte en version classique** — les pages utilisent
   les shortcodes WooCommerce, compatibles avec tous les plugins de paiement
   et entièrement stylés par le thème.
3. *(Pour tester)* **Importer le catalogue d'exemple** — 20 produits avec
   catégories, badges et champs Player ID. Supprimez-les avant l'ouverture
   (Produits › cochez tout › Corbeille) ou remplacez-les par les vôtres.

La liste de contrôle en haut de l'écran vous dit ce qu'il reste à faire.

## 6. Personnaliser (Apparence › Personnaliser › Delicat Store)

| Section | À renseigner |
|---|---|
| Identité & contact | **Numéro WhatsApp** (`50933111283`), e-mail support, liens Instagram/TikTok/Facebook/Telegram |
| Couleurs & forme | Vos 5 couleurs et le rayon des coins (le mode sombre est dérivé) |
| Page d'accueil | Textes du bandeau, sections à afficher, les 4 rayons par catégorie (slugs `jeux`, `abonnement`, `echanges`, `gift-card`) |
| Boutique & produits | Seuil du badge « Top Vente », bouton d'ajout rapide, téléphone obligatoire |
| Programme Bon Kliyan | Le lot de la semaine, nombre de clients affichés |
| FAQ / Témoignages | 6 questions, 3 avis de secours |
| **Informations légales** | Raison sociale, forme juridique, adresse, numéro fiscal, directeur de la publication — ils s'insèrent dans les pages légales. Un champ vide apparaît surligné sur la page. |

Puis Apparence › Personnaliser › **Identité du site** : logo (PNG ou SVG,
fond transparent) et **icône du site** (carré 512×512, sert de favicon et
d'icône quand un client installe la boutique sur son téléphone).

## 7. Vos produits

WooCommerce › Produits › Ajouter. Pour un produit numérique :

- Cochez **Virtuel** (le thème masque alors la quantité et affiche le badge
  « Livraison instantanée »).
- Rangez-le dans une catégorie : `Jeux`, `Abonnement`, `Gift Card`,
  `Échanges`, `Mobile`, `Réseaux sociaux`. Les rayons de l'accueil et les
  badges (icône Jeux, Streaming, Finance…) les reconnaissent, ainsi que les
  étiquettes `free-fire`, `netflix`, `moncash`, `digicel`…
- Dans la boîte **Champs client** sous la description : cochez « Demander des
  informations » et cliquez **Préréglage : Player ID** (ou E-mail du compte,
  ou Numéro MonCash). Le bouton « Aide » sur la boutique affiche le texte et
  l'image d'aide que vous renseignez.
- Un **prix promo** donne automatiquement le badge « Promo ». Le produit le
  plus vendu reçoit « #1 », ceux au-dessus du seuil « Top Vente ».

Les produits importés depuis votre ancien site gardent leurs champs Player
ID : le thème lit la même configuration (`_dmc_calc`).

## 8. Paiements

WooCommerce › Réglages › Paiements :

- **MonCash** : installez le plugin de passerelle MonCash que vous utilisiez
  (ou, en attendant, activez « Virement bancaire » renommé « MonCash » avec
  les instructions : numéro MonCash + preuve sur WhatsApp).
- **Carte bancaire** : Stripe ou PayPal, selon votre compte.
- **Wallet** : installez le plugin **TeraWallet** (« WooCommerce Wallet »).
  Le thème détecte le plugin : onglet « Wallet » dans la barre du bas, solde
  dans l'en-tête, bouton « Recharger » à la commande.

Faites une **commande test** de bout en bout (produit avec Player ID →
panier → commande → paiement → e-mail → WooCommerce › Commandes : le Player
ID apparaît sur la ligne de commande).

## 9. Cache et performance (Hostinger)

- LiteSpeed Cache : gardez le cache de page **activé** pour les pages
  publiques. Le thème envoie déjà les en-têtes « privé » sur le panier, la
  commande et le compte.
- **Désactivez « Combiner JS »** dans LiteSpeed › Optimisation de page ;
  laissez « Minifier CSS/JS » si vous voulez, mais testez le paiement
  ensuite.
- Après chaque mise à jour du thème : LiteSpeed Cache › **Purger tout**.
- Polices : le thème charge Poppins depuis Google Fonts. Pour un site encore
  plus léger en 3G, décochez « Charger la police Poppins » dans
  Personnaliser › Identité & contact (police système).

## 10. Mettre à jour le thème plus tard

Apparence › Thèmes › Ajouter › Téléverser le nouveau `delicat-store.zip` ›
WordPress propose « Remplacer le thème actif par la version téléversée ».
Vos réglages (Personnaliser), pages, menus et produits sont conservés :
tout est stocké dans la base de données, pas dans le thème.

Si vous modifiez le thème vous-même, faites-le dans un **thème enfant** pour
ne pas perdre vos changements à la mise à jour.

## En cas de problème

| Symptôme | Cause probable | Solution |
|---|---|---|
| Page blanche après activation | PHP < 7.4 | hPanel › Configuration PHP › 8.2 |
| Les pages `/boutique/`, `/panier/` donnent 404 | Permaliens non rafraîchis | Réglages › Permaliens › Enregistrer |
| Le style n'a pas changé après une mise à jour | Cache | LiteSpeed › Purger tout, puis Ctrl+F5 |
| Le Player ID n'apparaît pas sur la commande | Champ non coché « Obligatoire » ou boîte « Champs client » désactivée | Produit › Champs client › cocher « Demander des informations » |
| Textes WooCommerce en anglais | Traduction non téléchargée | Réglages › Général › Langue : Français, puis Tableau de bord › Mises à jour › « Mettre à jour les traductions » |
| Onglet « Wallet » absent | TeraWallet non actif | Extensions › activer TeraWallet |
