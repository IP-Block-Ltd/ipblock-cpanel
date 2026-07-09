> ⚠️ **Status: untested.** This extension is provided as-is and has **not been tested in production**. Please feel free to fork, modify, improve, and open pull requests.
>
> Licensed under **GNU GPLv3** (see [LICENSE](LICENSE)).

# IP-Block.com for cPanel / WHM

Server-wide visitor screening for **every site hosted on a cPanel/WHM server**.
A hosting provider installs this once; the check then runs for all customer PHP
sites via a global `auto_prepend_file`, with settings managed from WHM.

- **Panel:** cPanel & WHM
- **Version targeted:** v136 (RELEASE tier, 2026). Works on any modern
  EasyApache 4 / MultiPHP server (v110+).
- **Plugin type:** AppConfig-registered WHM plugin (PHP CGI UI) + drop-in
  PHP enforcement guard.

## What it does

Each incoming visitor's IP is checked against the IP-Block.com API before the
customer's application runs:

```
POST https://api.ip-block.com/v1/check
Content-Type: application/json
{"api_key","site_id","ip","user_agent","referrer"}   ->   {"action":"allow|block"}
```

A visitor is blocked **only** when `action === "block"`. The API call has a hard
**1 second** timeout and **fails open** (visitor allowed) on any error, timeout,
non-2xx response or missing `action` — configurable. Decisions are cached per IP
(APCu or temp file) for `cache_ttl` seconds. CLI and cron are never screened.

## Files

| File | Installed to | Purpose |
|------|--------------|---------|
| `guard/ipblock-guard.php` | `/opt/ipblock/ipblock-guard.php` | Shared enforcement guard (auto_prepend_file) |
| `whostmgr/docroot/cgi/ipblock/index.php` | `/usr/local/cpanel/whostmgr/docroot/cgi/ipblock/` | WHM settings UI (runs as root) |
| `ipblock.conf` | (registered) | AppConfig registration |
| `install.sh` / `uninstall.sh` | — | Installer / uninstaller |
| config | `/etc/ipblock/config.json` | Effective settings written by the UI |

## Install

Copy this folder to the WHM server and run as **root**:

```bash
bash install.sh
```

The installer:

1. Installs the guard to `/opt/ipblock/ipblock-guard.php`.
2. Creates `/etc/ipblock/config.json` (protection **disabled** by default).
3. Installs the WHM UI and registers it with AppConfig.
4. Writes `zzz-ipblock.ini` with
   `auto_prepend_file = /opt/ipblock/ipblock-guard.php` into every
   `/opt/cpanel/ea-php*/root/etc/php.d/` directory.
5. Restarts PHP-FPM and Apache.

Then open **WHM > Plugins > IP-Block Protection**, enter your **Site ID** and
**API Key**, tick **Enable protection**, and Save.

## Configuration (WHM UI)

| Setting | Default | Notes |
|---------|---------|-------|
| Enable protection | off | Master switch (server-wide) |
| Site ID / API Key | — | From your ip-block.com account |
| API URL | `https://api.ip-block.com/v1/check` | |
| Fail open | on | Allow visitors when API is unreachable (recommended) |
| Cache TTL | 300 | Seconds to cache a per-IP decision |
| Behind proxy / Real-IP header | off / `X-Forwarded-For` | Trust a proxy/CDN header for the real client IP |
| Block action | 403 | `403` page or `redirect` |
| Block message | `Access denied.` | Shown on the 403 page |
| Redirect URL | `https://www.ip-block.com/blocked.php` | Used in redirect mode |
| Whitelist | empty | One IP or CIDR per line; never checked |

Settings are stored in `/etc/ipblock/config.json` (0644 so each customer's PHP
worker can read it; only root can write it).

## Uninstall

```bash
bash uninstall.sh
```

## Notes for hosts

- The guard is dependency-free, safe to include twice, and wrapped in a
  catch-all so a guard error can never take a customer site down.
- APCu is used for the per-IP cache automatically when the PHP build has it;
  otherwise a per-site temp file under the system temp dir is used.
- To exempt a single account, add its IPs to the whitelist, or scope
  `auto_prepend_file` per-vhost via MultiPHP INI Editor.
