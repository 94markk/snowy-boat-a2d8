# Delicat Identity Pro — passkey end-to-end tests

Test-only tooling. Not part of the plugin ZIP. Never install the mu-plugin on a production site.

Requirements: a disposable WordPress (6.2+) test site with the plugin active, PHP CLI, Node 18+, and Playwright with Chromium (`npm i playwright && npx playwright install chromium`).

1. Copy `mu-plugin-dip-test-env.php` into the test site's `wp-content/mu-plugins/`. It allows WebAuthn on `http://localhost:<port>` (filter `dip_passkeys_allow_insecure_localhost`) and captures outgoing mail as JSON in `wp-content/test-mail/`.
2. Serve the site, for example `php -S localhost:8190 -t <site>` with a router for pretty permalinks.
3. Run `node passkeys-e2e.mjs http://localhost:8190 <site-dir> --parallel-port=8191`.

The suite creates its own fixtures through `fixtures.php`: users, pages and settings. It prints one PASS/FAIL line per scenario and exits non-zero on any failure, on browser errors from the plugin, or on new PHP notices in `<site>/debug.log`.

Useful options: `--list`, `--only=B*,C01`, `--skip=I02` (I02 waits for the WebAuthn timeout), `--verbose`, `--headed`.

On 6.12.0 all 49 scenarios pass; on 6.11.0, 22 of 40 passed.
