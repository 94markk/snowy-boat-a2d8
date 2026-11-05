# Outils de construction de Delicat Builder V9

Ce dossier n'est pas dans le zip du plugin. Il contient ce qu'il faut pour modifier le
« moteur » de la boutique (navigation, panier de l'en-tête, avis, notifications…) et
reconstruire les fichiers livrés dans `delicat-builder-v9/assets/dist/`.

## Contenu

| Élément | Rôle |
|---|---|
| `engine-src/` | Sources lisibles du moteur : `engine.js` et `chunks/*.js`, un fichier par fichier livré, sous un nom stable. Les imports entre eux utilisent ces noms stables. |
| `modules.json` | Correspondance nom stable → fichier livré (nommé par l'empreinte de son contenu), et empreinte de la source dont il a été construit. Tenu à jour par `build-engine.mjs`. |
| `build-engine.mjs` | Reconstruit le moteur depuis `engine-src/`. |
| `build.mjs` | Reconstruit les feuilles de style de `assets/dist/`, `asset-versions.php` et `integrity-manifest.json`. |
| `package.py` | Fabrique le zip installable `delicat-builder-v9-X.Y.Z.zip`. |
| `package.json` | Version figée d'esbuild (0.24.2). |

## Faire une nouvelle version

```sh
cd tools/builder && npm ci
# 1. modifier les sources (PHP, CSS dans assets/css, moteur dans engine-src/)
# 2. moteur : seuls les fichiers dont la source a changé sont reminifiés ; tous les fichiers
#    dont le contenu change sont renommés par empreinte, dépendances d'abord.
node build-engine.mjs ../../delicat-builder-v9
# 3. styles, versions des fichiers et manifeste d'intégrité (deux dossiers vides en arguments)
E=$(mktemp -d); (cd ../../delicat-builder-v9 && ESBUILD_DIR="$PWD/../tools/builder/" node ../tools/builder/build.mjs . "$E" "$E")
# 4. zip
python3 package.py ../../delicat-builder-v9-X.Y.Z.zip ../../delicat-builder-v9
```

Changer la version (en-tête `Version:` et `DELICAT_BUILDER_V9_VERSION`) et ajouter la note en
français en tête de `README.txt` **avant** l'étape 3 : tout fichier modifié doit figurer dans le
manifeste d'intégrité.

## Règles du moteur

- JavaScript ES2019 (vieux téléphones Android) : pas de `?.` ni `??` dans le code écrit à la main.
- Les noms courts (`n`, `c`, `W`…) viennent du code minifié d'origine : avant d'ajouter une variable
  au niveau du module, vérifier que le nom n'existe pas déjà dans le fichier.
- Un import circulaire entre modules est refusé par `build-engine.mjs`.

## Tests (staging)

`tests/redesign/` : `phone-bugs.cjs`, `update-safety.cjs` et `builder-978.cjs` se lancent contre le
site de test (voir l'en-tête de chaque fichier).
