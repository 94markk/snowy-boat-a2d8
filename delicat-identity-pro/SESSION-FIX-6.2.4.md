# Delicat Identity Pro 6.2.4 — Secure Session / Legacy Conflict Fix

## Root cause
The live site can render both the unified Identity modal and the former Code Snippets modal. Both previously used `delicat_fresh_nonces`, `delicat_do_login`, and `delicat_do_register`. The legacy nonce endpoint returns the old payload and can terminate `admin-ajax.php` before the unified handler runs, so the 6.2.3 frontend sees an incomplete secure-session response.

## Fixes
- New namespaced internal AJAX actions for nonce/login/register/resend.
- Legacy public aliases are only registered when the old snippet is absent.
- Named legacy AJAX callbacks are detached when detected.
- Native modal gets an isolated id while the legacy snippet exists.
- Duplicate legacy modal is visually suppressed and trigger clicks are intercepted in capture phase.
- admin-ajax URL is same-origin/relative.
- Reopen resets modal scroll and moves the auth overlay to the end of body for correct stacking.
- Database schema stays at 6.2.2; only plugin/assets version changes to 6.2.4.
