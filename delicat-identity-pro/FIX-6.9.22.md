# 6.9.22 — Popup devant le menu et protection des taps

Le signalement « le menu derrière répond au clic Google » indique un problème possible de superposition ou de ciblage des événements. Il n’a pas été reproduit sur le site réel dans cet environnement.

## Correction

- Le conteneur devient un dialog natif ouvert avec showModal(), dans la top layer du navigateur. Le z-index maximal seul ne suffisait pas à isoler le popup.
- Le contenu derrière devient inert et ne reçoit plus les interactions utilisateur. Un garde en capture bloque également les événements ciblant le fond pour les anciens navigateurs.
- Les nouveaux widgets ajoutés directement au body pendant l’ouverture sont isolés aussi. L’observation s’arrête à la fermeture.
- Fond du popup et carte ont des niveaux explicites. Le popup devient interactif immédiatement, sans attendre deux frames.
- La fermeture restaure les états inert préexistants; protection maintenue pendant la courte animation. Une réouverture rapide annule l’ancienne fermeture.
- Une ancienne version HTML en cache est convertie en dialog avant le branchement des événements.
- Les correctifs Google, timeout et réessai de 6.9.21 sont inclus.

## Vérification

24 checks Node VM réussis : les 19 tests précédents et 5 tests du cycle modal (showModal/close, inert/restauration, blocage pendant fermeture, réouverture rapide et Escape). Syntaxe vérifiée sur tous les JavaScript du paquet. Ces fixtures ne sont pas un navigateur réel : le rendu, le hit-testing Safari/Chrome, PHP/WordPress et un vrai compte Google restent à tester sur le site. PHP n’est pas disponible ici.

## Installation

Remplacer le plugin par 6.9.22, purger LiteSpeed et le CDN/Cloudflare, fermer les anciens onglets, puis tester en navigation privée. Vérifier : popup → Google; aucun menu du fond ne répond; fermeture → le menu redevient utilisable; réouverture; petit écran et clavier affiché.

Documentation technique : https://developer.mozilla.org/en-US/docs/Web/API/HTMLDialogElement/showModal
