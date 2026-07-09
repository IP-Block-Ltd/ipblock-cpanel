#!/bin/bash
# =============================================================================
#  IP-Block.com — cPanel/WHM plugin installer  (tested against cPanel & WHM v136)
# =============================================================================
#  Installs:
#    - WHM settings UI  -> /usr/local/cpanel/whostmgr/docroot/cgi/ipblock/
#    - AppConfig entry  -> WHM > Plugins > IP-Block Protection
#    - Enforcement guard-> /opt/ipblock/ipblock-guard.php
#    - Config file      -> /etc/ipblock/config.json
#    - auto_prepend_file INI drop-in for every EasyApache PHP version
#
#  Run as root on the WHM server:  bash install.sh
# =============================================================================
set -euo pipefail

if [[ $EUID -ne 0 ]]; then
    echo "ERROR: run this installer as root." >&2
    exit 1
fi

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CGI_DIR="/usr/local/cpanel/whostmgr/docroot/cgi/ipblock"
GUARD_DIR="/opt/ipblock"
CONF_DIR="/etc/ipblock"

echo "==> Installing enforcement guard"
mkdir -p "$GUARD_DIR"
install -m 0644 "$SRC/guard/ipblock-guard.php" "$GUARD_DIR/ipblock-guard.php"

echo "==> Preparing config directory"
mkdir -p "$CONF_DIR"
chmod 0755 "$CONF_DIR"
if [[ ! -f "$CONF_DIR/config.json" ]]; then
    cat > "$CONF_DIR/config.json" <<'JSON'
{
    "enabled": false,
    "site_id": "",
    "api_key": "",
    "api_url": "https://api.ip-block.com/v1/check",
    "fail_open": true,
    "cache_ttl": 300,
    "behind_proxy": false,
    "real_ip_header": "X-Forwarded-For",
    "block_action": "403",
    "block_message": "Access denied.",
    "redirect_url": "https://www.ip-block.com/blocked.php",
    "whitelist": []
}
JSON
    chmod 0644 "$CONF_DIR/config.json"
fi

echo "==> Installing WHM settings UI"
mkdir -p "$CGI_DIR"
install -m 0755 "$SRC/whostmgr/docroot/cgi/ipblock/index.php" "$CGI_DIR/index.php"
if [[ -f "$SRC/ipblock.png" ]]; then
    install -m 0644 "$SRC/ipblock.png" "$CGI_DIR/ipblock.png"
    install -m 0644 "$SRC/ipblock.png" "/usr/local/cpanel/whostmgr/docroot/cgi/ipblock.png" || true
fi

echo "==> Registering AppConfig plugin"
install -m 0644 "$SRC/ipblock.conf" "$CGI_DIR/ipblock.conf"
/usr/local/cpanel/bin/register_appconfig "$CGI_DIR/ipblock.conf"

echo "==> Enabling auto_prepend_file for all EasyApache PHP versions"
PREPEND="$GUARD_DIR/ipblock-guard.php"
shopt -s nullglob
FOUND=0
for phpd in /opt/cpanel/ea-php*/root/etc/php.d; do
    FOUND=1
    cat > "$phpd/zzz-ipblock.ini" <<INI
; Managed by the IP-Block.com cPanel plugin. Do not edit by hand.
auto_prepend_file = $PREPEND
INI
    echo "    - $phpd/zzz-ipblock.ini"
done
if [[ $FOUND -eq 0 ]]; then
    echo "    WARNING: no /opt/cpanel/ea-php*/root/etc/php.d directories found."
    echo "    Set auto_prepend_file = $PREPEND manually via MultiPHP INI Editor."
fi

echo "==> Restarting PHP-FPM and Apache"
/scripts/restartsrv_apache_php_fpm >/dev/null 2>&1 || true
/scripts/restartsrv_httpd >/dev/null 2>&1 || true

echo ""
echo "Done. Open WHM > Plugins > IP-Block Protection to enter your Site ID / API Key."
echo "Protection stays OFF until you enable it in that UI."
