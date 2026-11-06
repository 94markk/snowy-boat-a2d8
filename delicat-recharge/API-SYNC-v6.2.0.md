# Delicat Recharge 6.2.0 — synchronisation API uniquement

Cette mise à jour conserve intégralement les méthodes MonCash, Natcash
TransCode, les validations manuelles, les règles de sécurité et les crédits
TeraWallet existants.

Elle ajoute uniquement des événements privés après les opérations déjà réalisées :

- création, validation et refus des demandes de recharge ;
- mouvements du portefeuille et transferts entre utilisateurs ;
- activation ou désactivation des moyens de paiement côté administration ;
- notification personnelle lorsqu’une demande est refusée.

Les événements ne publient jamais les codes de transaction, les montants, les
adresses email, les identifiants fournisseur ni les clés de paiement.

Le numéro interne `DELICAT_VERSION` reste volontairement à `6.0.5` et le numéro
des assets reste à `6.1.3` : aucune migration de base de données et aucune
modification des interfaces de recharge existantes ne sont déclenchées.

Installer également Delicat App API 2.11.0 pour activer la synchronisation
complète du site, du portefeuille, des commandes et des notifications.
