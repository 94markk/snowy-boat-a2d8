# Delicat Store — thème WooCommerce pour delicastoreha.com

Boutique mobile-first de produits numériques (recharges de jeux, gift cards,
abonnements, échanges MonCash/USDT, recharges mobiles) pour Haïti, construite
comme un **thème WordPress** à déployer sur Hostinger avec WooCommerce.

C'est une reconstruction complète : rien n'est repris du code de l'ancien
Delicat Builder V9, mais ce qui faisait le site est là — et les produits
existants gardent leurs champs Player ID.

```
delicat-store/          le thème (à zipper : scripts/package-theme.sh)
├── inc/                un fichier par fonctionnalité
├── template-parts/     header, tiroir, barre d'onglets, sections d'accueil
├── page-templates/     Support, Comment ça marche, Bon Kliyan, Page légale
├── woocommerce/        carte produit
├── legal/              les 10 documents légaux (CGU, confidentialité, …)
├── sample-data/        catalogue d'exemple (20 produits, CSV WooCommerce)
└── assets/             theme.css (cascade layers), theme.js, icônes PWA
tests/theme/run.php     268 tests unitaires, sans WordPress
scripts/check-theme.sh  lint + tests + ZIP
DEPLOY-HOSTINGER.md     le guide de déploiement pas à pas
```

## Ce que fait le thème

**Storefront façon application.** En-tête collant avec recherche, panier et
compte ; barre d'onglets en bas (Accueil, Boutique, Wallet ou Recherche,
Panier, Compte) ; tiroir de menu ; mode sombre (bouton + préférence système) ;
transitions de page natives et préchargement au toucher ; manifeste PWA
(installable sur l'écran d'accueil) ; bouton WhatsApp flottant.

**Accueil composable.** Bandeau avec recherche, pastilles de catégories,
bande de confiance, « Les plus achetés », 4 rayons par catégorie, Promos,
« Comment ça marche », « Pourquoi Delicat », classement Bon Kliyan,
témoignages (avis produits 5★ réels, sinon 3 de secours), FAQ (avec données
structurées), appel WhatsApp. Chaque section se coche dans le Customizer.

**Produits numériques.** Champs client par produit (Player ID, e-mail du
compte, numéro MonCash, listes, options payantes…) avec bouton d'aide,
validation serveur, valeurs visibles dans le panier, la commande, les
e-mails et l'admin. Même clé de stockage que l'ancien site (`_dmc_calc`).
Quantité masquée et badge « Livraison instantanée » sur les produits
virtuels. Bouton « Acheter maintenant » (direct à la commande) et barre
d'achat fixe sur mobile. Formulaire de commande allégé quand rien n'est à
expédier ; téléphone/WhatsApp obligatoire.

**Badges automatiques.** Un badge thème (Jeux, Finance, Gift Card, Réseaux
sociaux, Streaming, Mobile) déduit des étiquettes et catégories — accents,
majuscules et la faute d'orthographe « Resaux-Sociaux » compris — et un
badge statut : #1 (meilleure vente), Promo, Top Vente. Jamais plus de deux.
Icônes dessinées, pas d'emoji.

**Bon Kliyan.** Classement hebdomadaire (lundi 00h00 → dimanche 23h59, heure
d'Haïti) sur les commandes terminées, noms masqués (« Jean D. »), lot de la
semaine, page dédiée et règlement.

**Pages légales.** Dix documents rédigés pour ce commerce, installés comme
pages et remplis avec vos informations (raison sociale, adresse, hébergeur…)
depuis le Customizer. Un champ manquant est surligné « à compléter ».

**Wallet.** Avec le plugin TeraWallet : solde dans l'en-tête, onglet Wallet,
lien « Recharger » à la commande. Sans lui, rien ne casse.

**Mise en route.** Apparence › Delicat Store : liste de contrôle et actions
en un clic (créer les pages, régler WooCommerce pour Haïti, passer les pages
en version classique, importer le catalogue d'exemple).

## Développer

```sh
php tests/theme/run.php        # tests unitaires
scripts/check-theme.sh         # lint + tests + dist/delicat-store.zip
```

Le thème n'a aucune dépendance : pas de build, pas de Node. Le CSS est écrit à
la main en cascade layers (`wc` pour la feuille de WooCommerce, importée dans
une couche inférieure, puis `reset, base, layout, components, woo, state`),
les couleurs viennent du Customizer sous forme de variables `--ds-*`, et le
mode sombre est une seule redéclaration de ces variables.

Ce dépôt contient aussi, à sa racine, le starter Astro d'origine
(`src/`, `astro.config.mjs`, `package.json`). Il n'est pas utilisé par le
thème et peut être supprimé.

## Déployer

Voir **[DEPLOY-HOSTINGER.md](DEPLOY-HOSTINGER.md)**.
