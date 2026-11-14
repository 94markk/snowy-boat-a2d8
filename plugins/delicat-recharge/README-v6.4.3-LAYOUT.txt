Delicat Recharge 6.4.3 — mobile layout refinement

Corrects inherited center alignment that shrank instruction and recipient cards.
Cards now fill the available content width. Removes remaining decorative panel
pseudo-elements. Compacts the wallet title, balance, client information, actions
and footer; retains wrapping values and 44px copy/reveal controls.
Compacts recharge steps, tabs and introductory text without hiding instructions.
Restores a distinct disabled appearance for the submission button.

Backend and templates are unchanged. Since 6.4.2 only CSS and frontend version
metadata changed. Payment JavaScript remains unchanged.

Checks: original backend/template byte comparison, JS syntax, CSS delimiters,
ZIP integrity. Browser rendering and live transactions were not tested.
Header, floating navigation and chat are controlled by other components.

Install over the existing plugin, do not uninstall first. Purge page/CDN caches
and reload the wallet page to load asset version 6.4.3.
