Delicat Recharge v5.4.1 — Natcash field visibility fix

- Hides “Votre numéro Natcash (celui qui envoie)” by default.
- Shows it only when Natcash Auto is the active tab.
- Sets the correct initial field visibility from PHP, even if JavaScript is delayed or blocked.
- Re-synchronizes the active tab after Safari back/forward cache restoration.
- Scopes tab behavior to the recharge form to prevent conflicts.
- Backend, payment processing, wallet logic, and database code were not changed.
