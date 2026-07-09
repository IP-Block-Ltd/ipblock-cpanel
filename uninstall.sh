#!/bin/bash
# =============================================================================
#  IP-Block.com — cPanel/WHM plugin uninstaller
# =============================================================================
set -euo pipefail
if [[ $EUID -ne 0 ]]; then echo "ERROR: run as root." >&2; exit 1; fi

echo "==> Unregistering AppConfig plugin"
/usr/local/cpanel/bin/unregister_appconfig ipblock || true

echo "==> Removing auto_prepend_file INI drop-ins"
shopt -s nullglob
for ini in /opt/cpanel/ea-php*/root/etc/php.d/zzz-ipblock.ini; do
    rm -f "$ini" && echo "    - removed $ini"
done

echo "==> Removing WHM UI and guard"
rm -rf /usr/local/cpanel/whostmgr/docroot/cgi/ipblock
rm -f  /usr/local/cpanel/whostmgr/docroot/cgi/ipblock.png
rm -rf /opt/ipblock

echo "==> Restarting PHP-FPM and Apache"
/scripts/restartsrv_apache_php_fpm >/dev/null 2>&1 || true
/scripts/restartsrv_httpd >/dev/null 2>&1 || true

echo ""
echo "Uninstalled. Config left at /etc/ipblock/config.json (delete manually if desired)."
