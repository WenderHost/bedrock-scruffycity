#!/usr/bin/env bash
set -euo pipefail

cd "$WEB_DIR"

echo "👉 [Elementor] Replacing https://$SYNC_REMOTE_HOST with https://$SYNC_LOCAL_HOST..."
wp elementor replace_urls "https://$SYNC_REMOTE_HOST" "https://$SYNC_LOCAL_HOST"

echo "👉 [Elementor] Flushing CSS..."
wp elementor flush_css

# Plugins that must not run locally. SMTP2GO sends through its own API,
# bypassing Mailpit, so a form notification would reach real people.
echo "👉 Deactivating production-only plugins..."
wp plugin deactivate smtp2go spinupwp analyticswp limit-login-attempts-reloaded || true
