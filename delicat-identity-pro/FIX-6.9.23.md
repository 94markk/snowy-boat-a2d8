# 6.9.23 — Initialisation au premier affichage et taps Google

## Diagnostic

Le site public a été ouvert dans le navigateur de test : le conteneur servi est bien un DIALOG ouvert, et le centre du bouton Google correspond au bouton dans le hit-test desktop. Le défaut décrit sur iPhone (Inscription puis Connexion débloque le popup) n’a pas été reproduit sur cet appareil. Il ne faut donc pas considérer la cause exacte comme prouvée.

L’inspection du code montre que la taille du viewport était appliquée dans une frame après exposition du popup; le focus pouvait être déplacé plus tard vers un champ. Le changement d’onglet imposait un nouveau layout/focus. Le bouton Google dépendait uniquement de click, événement qui peut être supprimé par un gestionnaire de gestes tiers. Ces chemins ont été corrigés.

## Modifications

- Panneaux et dimensions initialisés avant showModal(); Connexion fonctionne sans passage par Inscription.
- Suppression du focus différé sur le champ email; focus initial sur la carte, sans déplacement demandé de la page.
- Suppression des transitions/transforms du conteneur et de la carte, des effets hover Google et de la propriété historique de scrolling accéléré WebKit dans ce popup.
- Un tap tactile complet sur Google est pris en charge en capture sur pointerup. Déplacement de plus de 10 pixels ou pointercancel annule ce chemin; click reste disponible au clavier et à la souris. Le verrou de navigation évite un double départ.
- Le bouton mot de passe reste réessayable pendant la requête de nonce; submit attend toujours la requête et exige le nonce valide.
- Nouveaux fichiers identity-modal-v5.js et identity-modal-v5.css. Le conteneur expose data-dip-engine="6.9.23" pour diagnostic. Les contrôles OAuth/nonce/2FA serveur restent inchangés.

## Vérification

24 tests modal et 7 tests asynchrones Node VM réussis. Les nouveaux tests couvrent initialisation avant showModal, nonce en attente, tap sans click, scroll, annulation du geste, premier affichage avec formulaire Inscription présent et ouverture directe d’Inscription. Syntaxe JS et ZIP contrôlés.

Limites : fixtures DOM simulées, pas de WebKit/iPhone local ni d’interpréteur PHP. Le suivi du bouton vers accounts.google.com a été bloqué par la revue automatique d’autorisation du navigateur (accès à un flux de compte Google); aucun identifiant n’a été entré et cette navigation n’a pas été contournée. Le parcours complet Google et la correction sur iPhone restent non vérifiés.

## Installation

Remplacer le plugin avec ce ZIP; purger le cache des pages LiteSpeed et du CDN utilisé; fermer l’ancien onglet puis ouvrir une fenêtre privée. Dès le premier affichage, essayer Google sans changer d’onglet. Vérifier aussi Inscription, Connexion, fermeture/réouverture et défilement. Les nouveaux noms d’assets évitent un ancien JS/CSS si les paramètres de version sont ignorés, mais ne remplacent pas la purge du HTML.

Si le même symptôme subsiste, relever une courte vidéo du premier affichage et le navigateur/version. Il faudra comparer le ciblage tactile et les scripts du Builder sur l’appareil affecté; une nouvelle hausse du z-index seule ne constituera pas un diagnostic.
