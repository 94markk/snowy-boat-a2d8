Delicat Recharge Admin 6.6.2 — correctif (1er novembre 2026)
=========================================================

(1) Natcash SMS (diagnostic) : la 6.6.1 classait toutes les lignes du journal comme « VALIDÉ », y compris
    les SMS suspects, retenus ou sans demande correspondante, et le compteur de réussites était faux.
    Seules les approbations réelles (lignes « APPROUVÉ ») sont de nouveau comptées comme validées, y compris
    celles enregistrées avant la mise à jour. Le crédit des portefeuilles n'était pas concerné : il dépend
    de la vérification du SMS, pas de cet affichage.
(2) Appareils de l'application : « Actif » ou « Désactivé » est de nouveau affiché pour chaque appareil.
(3) Listes des recharges et fiche client : la colonne portefeuille affiche « Crédité » (ou « — »).
(4) Boutons « Vérifier via SMS & créditer » et « Rembourser » : les deux derniers emojis sont retirés.

Installer avec Delicat Builder 9.6.2 et Delicat Identity 6.17.1. Retour en arrière : réinstaller 6.6.1.
