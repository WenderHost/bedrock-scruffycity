# AGENTS.md

## What this site is

scruffycity.com, a WordPress site on [Bedrock](https://roots.io/bedrock/), built with Elementor + Elementor Pro on the Hello Elementor parent theme. Site-specific code is in the child theme, `web/app/themes/scruffycity-child-theme`, and in single-file mu-plugins such as `web/app/mu-plugins/gf-gibberish-name-spam.php`. Forms are Gravity Forms (with Turnstile).

- **Development home:** the Mac Mini, at `~/webdev/bedrock/scruffycity.com`, served by Valet as `https://scruffycity.test` (linked from `web/`, secured, `valet isolate php@8.4` to match production). Local DB `wp_scruffycity` (user `macmini`). Moved from the MacBook on 2026-11-17; the MacBook's `~/webdev/laravel-valet/bedrock/scruffycity.com` copy is unlinked from Valet and no longer serves the site, so don't re-link it. The MacBook reaches the Mini's copy over Tailscale through an `/etc/hosts` entry and the Mini's trusted Valet CA.
  - **Database:** refresh from production with `~/.bun/bin/spinuptui pull db scruffycity.com --yes` (SpinupWP site ID 218857 is linked in SpinupTUI's config). It backs up the local DB to `sql/` first and runs `bin/sync.d/post-import.sh`, which rewrites Elementor's URLs, flushes its CSS and deactivates the production-only plugins.
  - **Uploads aren't copied locally.** `web/app/mu-plugins/spinup-media-fallback.php` (gitignored, generated from SpinupTUI's source) serves any missing upload from production.
  - **Plugins that must not run locally** (SMTP2GO, SpinupWP, AnalyticsWP, Limit Login Attempts) are deactivated in the local database by `post-import.sh`, because this site doesn't have `lukasbesch/bedrock-plugin-disabler`. SMTP2GO matters most: it sends through its own API, bypassing Mailpit, so a form notification sent with it active would reach real people. Check it's still inactive after any manual database import.
  - `.env` and `auth.json` are gitignored and were copied from the MacBook by hand. `WP_DEBUG_LOG` points at `debug.log` in the repo root (gitignored by `*.log`).
- **Hosting:** SpinupWP. A push to `main` deploys to production, so work on a branch.
- **Repo:** `git@github.com:WenderHost/bedrock-scruffycity.git`

## Dependencies

Composer pulls from WPackagist (legacy: moving to WP Packages is pending as separate work), Wenmark's SatisPress repo (`packages.wenmarkdigital.com`, for Elementor Pro, Gravity Forms and its Turnstile add-on, and WPCodeBox 2) and ACF PRO's repo. `auth.json` holds the credentials for the private ones. WordPress core is `roots/wordpress` `^7`.
