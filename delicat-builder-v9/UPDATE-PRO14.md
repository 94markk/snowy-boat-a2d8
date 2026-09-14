# Delicat Builder 9.2.0-pro.14 — corrections progressives

Date : 10 septembre 2026. Cette version remplace PRO13 et conserve les réglages Builder.
Le site en production n'a pas été modifié depuis cet environnement.

## 1. Paiement WooCommerce

Acheter maintenant ajoute la sélection avec le formulaire produit WooCommerce, puis
ouvre le récapitulatif de **tout le panier**, anciens articles compris. Pour un client
connecté avec une commande sans livraison et la passerelle `wallet` disponible,
Payer maintenant soumet le formulaire à WC_Checkout depuis la fenêtre. WooCommerce
et la passerelle wallet créent la commande, valident les informations et exécutent
le débit. Le succès ouvre la confirmation de commande, pas la page checkout.
Les autres passerelles et les champs qui nécessitent le cycle complet WooCommerce
(pays/état notamment) restent dirigés vers le paiement complet.

Corrections PRO14 :
- Le mutex utilise maintenant INSERT IGNORE sur la clé unique option_name. L'ancien
  add_option n'était pas une garantie atomique suffisante : il pouvait faire un upsert.
- Checkout classique connecté et transport express acquièrent le même verrou par
  client. Les écritures et libérations vérifient leur propriétaire en base.
- Une intention utilisée et une vérification antérieure à un autre paiement réussi
  ne peuvent pas déclencher un nouveau paiement via ces deux transports.
- La commande est enregistrée dans le journal avant le traitement de la passerelle.
  Le résultat utilise les hooks WooCommerce, sans dépendre de l'analyse du buffer JSON.
- Après succès, le verrou reste détenu jusqu'à l'arrêt PHP, après la sauvegarde de
  session WooCommerce. Une création dont le résultat est inconnu reste bloquée.
- Délai d'attente interface de 20 secondes. Ce délai ne signifie pas que le serveur
  a annulé le paiement. Aucun renvoi automatique de la transaction.
- Vérifier ce paiement est une requête authentifiée en lecture seule. Une commande
  payée ouvre sa confirmation ; un refus avant création permet une nouvelle revue.
- Les erreurs de validation demandent le rafraîchissement du récapitulatif classique.
- HTTPS, nonce WooCommerce, intention signée et consentement explicite sont requis
  pour le paiement direct. Aucun secret de carte n'est collecté par Builder.

Il ne s'agit pas d'un chiffrement supplémentaire de la base WordPress. La sécurité
transactionnelle interne du wallet reste celle du plugin wallet. Checkout Blocks,
Store API, pay-for-order et les autres plugins qui débitent le wallet ne participent
pas encore à ce mutex : leurs chemins doivent être vérifiés avant de promettre une
protection globale contre les doubles débits.

Un résultat incertain conserve le verrou. L'avis administrateur exige une vérification
manuelle de la commande ET de l'historique réel du wallet, puis au moins cinq minutes,
avant libération. Ne jamais libérer un traitement encore actif. Les anciens verrous
PRO13 ne sont pas effacés automatiquement. La libération ne rembourse ni ne débite.

## 2. Session et cache

- Session démarre même si LiteSpeed retarde DelicatSessionConfig. L'URL de secours
  wc-ajax respecte la route WordPress et les installations en sous-répertoire.
- Les rafraîchissements simultanés de session sont regroupés ; une visite invitée
  vide ne crée pas de requête de démarrage inutile.
- Les fichiers JS/CSS Builder utilisent des noms contenant leur empreinte de contenu.
  Ils restent renouvelables même lorsque l'optimiseur supprime les paramètres ver.
  Les chemins d'origine restent disponibles pour les références existantes.
- Exclusions LiteSpeed pour les scripts Builder et les configurations de démarrage ;
  restauration ciblée des configurations si leur type a été réécrit par LiteSpeed.
- Protection des images prioritaires contre les placeholders lazy-load ; tailles de
  logo adaptées et source medium_large. Les miniatures doivent exister dans WordPress.

## 3. Poids et présentation

- Le script/style directement imprimé du tutoriel wallet est retiré hors des routes
  my-wallet, wallet et de l'endpoint Woo Wallet. Il est conservé sur ces routes.
- Le CSS statique du footer devient un fichier mis en cache au lieu d'être réimprimé.
- Les scripts checkout/pays/adresses sont retirés du panier vide.
- Le titre de page en double est retiré uniquement dans le document panier natif,
  lorsqu'un en-tête panier Builder existe déjà.
- Le compteur de résultats boutique utilise un modèle français.
- La fenêtre conserve l'action fixe, le défilement des détails, les espaces iPhone,
  le consentement visible et le nom Delicat Wallet sans répétition du solde.
- Champs obligatoires supplémentaires projetés dans la fenêtre ; contrôles natifs
  select/textarea/checkbox lisibles. Les champs complexes utilisent le checkout complet.

## 4. Vérifications réalisées ici

- Analyse syntaxique PHP 7.4 : 106 fichiers, sans erreur.
- Exécution PHP 8.5 WASM avec adaptateur SQLite pour les requêtes MySQL : concurrence
  séquentielle sur la clé unique, propriétaire incorrect, libération tardive, CAS,
  panne base simulée, mutex classique, journal avant débit, verrou jusqu'au shutdown,
  intention déjà utilisée, seconde fenêtre ancienne et création incertaine : OK.
  Ce test ne remplace pas un test concurrent sous MySQL avec WooCommerce réel.
- Tests DOM simulés : consentement, double clic, succès WooCommerce, refus,
  réponse perdue, absence de renvoi, récupération par statut et coalescence : OK.
- Test session : configuration absente, visite vide, initialisation répétée,
  requêtes simultanées et URL sous-répertoire : OK.
- Transformations sur les réponses HTML auditées avec WP_HTML_Tag_Processor de
  WordPress 6.4 : tour conservé sur wallet, retiré ailleurs, un H1 panier, images
  restaurées, configuration réactivée, scripts littéraux et JSON intacts,
  idempotence et URL d'asset renouvelée : OK.
- Plus de 120 Ko de HTML non compressé retirés dans la fixture homepage auditée.
  Ce chiffre n'est pas un gain de temps de chargement mesuré en production.
- Intégrité du ZIP, noms d'assets et syntaxe JavaScript vérifiés avant livraison.

Le rendu mobile/laptop réel, Safari avec clavier, les débits et les conflits réels
MySQL/TeraWallet n'ont pas été testés sur votre serveur. La maquette responsive
validée dans PRO13 ne vaut pas un test de toute la boutique en production.

## 5. Installation, étape par étape

1. Sauvegarder fichiers et base. Créer une copie de staging avec les mêmes versions
   WordPress, WooCommerce, wallet et LiteSpeed. Désactiver les livraisons fournisseur
   et autres effets externes sur cette copie avant les essais de commande.
2. Dans Extensions > Ajouter > Téléverser, envoyer le ZIP PRO14 et choisir le
   remplacement de Builder existant. **Ne pas désinstaller Builder** : la
   désinstallation peut supprimer ses réglages. Ne pas activer deux copies.
3. Purger LiteSpeed (pages et fichiers optimisés), puis Cloudflare si utilisé.
   Fermer les anciens onglets de checkout et recharger la boutique.
4. Sur staging, vérifier l'accueil, la boutique, Prime Video, Free Fire, le panier
   vide/rempli et le wallet en mobile et laptop. Vérifier menu, connexion/déconnexion,
   logo clair/sombre, absence de chevauchement avec la barre basse et le clavier.
5. Avec un compte de test et le wallet de staging, vérifier un achat simple :
   sélection affichée, ancien panier visible, total WooCommerce exact, consentement,
   une commande et un seul débit. Tester un solde insuffisant et une coordonnée invalide.
6. Tester double clic, deux onglets express, express contre checkout classique et
   réponse réseau perdue après envoi. Vérifier en base et dans le wallet qu'il n'y
   a qu'un débit ; vérifier qu'un résultat inconnu reste bloqué sans renvoi.
7. Vérifier les autres gateways, Store API/Blocks et pay-for-order utilisés par la
   boutique. Ces parcours ne doivent pas être considérés couverts par le mutex PRO14.
8. Une fois ces essais réussis, remplacer la même extension en production pendant
   une période calme, purger les caches et contrôler une commande autorisée de bout
   en bout. En cas de régression, restaurer les fichiers précédents sans supprimer
   les commandes ni les journaux de paiement ; réconcilier les paiements en cours.

## 6. Points d'audit encore ouverts

- Cache/versionnement propre de Delicat Identity Pro : ses sources n'étaient pas
  dans l'archive Builder. Les exclusions ne renouvellent pas son ancien fichier cache.
- Politique CSP serveur stricte : nécessite inventaire et essai en Report-Only avec
  les domaines des gateways et connexions Google avant activation.
- Lien Voir tout de la section sociale et contenu des tutoriels : données de la base
  non fournies. Corriger la destination dans Builder une fois la catégorie confirmée.
- Délais/conditions Prime Video et fournisseurs : informations commerciales à valider.
- Swatches : CSS configurable toujours en partie inline. Son extraction complète
  nécessite des essais sur les presets et réglages stockés dans votre base.
- Navigation produit reste un document WooCommerce natif. Aucun basculement SPA
  risqué n'a été imposé aux formulaires produit. Mesurer TTFB/LCP et cache serveur
  après installation pour poursuivre le travail sur les temps de navigation.

Références d'implémentation vérifiées : source officielle WordPress HTML API
https://github.com/WordPress/WordPress/tree/6.4-branch/wp-includes/html-api et hooks
LiteSpeed officiels https://github.com/litespeedtech/lscache_wp/tree/master/src.
