Delicat Recharge Admin 6.4.2 — Presentation update

Changes
- Responsive balance card: single column on mobile, wrapping amounts and names.
- Recharge instructions use readable numbered rows on phones.
- Recipient number and name use separate rows with 44px copy/reveal controls.
- Larger labels, 16px inputs, integrated HTG suffix, clearer submit button.
- Static blue/violet design replaces recharge blur and continuous decoration.
- Bottom clearance accounts for the mobile navigation and device safe area.
- Removed decoration-only bubble injection and CSS comments.

Backend preservation
All includes/ files and templates/form.php are byte-for-byte unchanged.
Submission, AJAX, validation, nonce, SMS guard, wallet credit, database and API
logic are unchanged. Main PHP changes only the visible plugin version and
frontend asset cache version. Database and integration versions are unchanged.

Verification
JavaScript syntax check passed. CSS delimiters validated. Original archive
comparison confirmed only CSS, decorative JS and version metadata changed.
Browser rendering could not be checked: browser installation was blocked by
network timeout/502. No live payment or speed benchmark was performed.
Header, floating menu and assistant belong to other components; this update
adds recharge-page clearance but does not change those components.

Installation
WordPress > Extensions > Ajouter > Televerser une extension.
Upload this ZIP and choose to replace the existing Delicat Recharge plugin.
Do not uninstall the existing plugin first. Clear LiteSpeed/Cloudflare caches
and reload the wallet page to receive asset version 6.4.2.
Check Natcash/MonCash switching, copy/reveal, keyboard entry and bottom-button
visibility on the live theme. Retain the previous ZIP for rollback.
