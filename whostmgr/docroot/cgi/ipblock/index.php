<?php
/**
 * =============================================================================
 *  IP-Block.com — WHM Settings Interface (PHP CGI plugin)
 * =============================================================================
 *  Registered with cPanel AppConfig and reachable from WHM under:
 *      Home >> Plugins >> IP-Block Protection
 *
 *  Runs as root inside WHM (AppConfig user=root, acls=all). It reads and writes
 *  the shared config file /etc/ipblock/config.json which the auto_prepend guard
 *  (/opt/ipblock/ipblock-guard.php) consumes for every customer PHP request.
 *
 *  Because it is served through the WHM proxy at /cgi/, access is already
 *  gated by WHM authentication + ACLs and carries a WHM security token. We add
 *  a per-session form token as defence-in-depth against CSRF.
 * =============================================================================
 */

const IPBLOCK_CONFIG_DIR  = '/etc/ipblock';
const IPBLOCK_CONFIG_FILE = '/etc/ipblock/config.json';

/* ---------------------------------------------------------------------------
 * WHM chrome. Use cPanel's bundled WHM PHP helper when present so the page
 * gets the standard WHM header/footer; otherwise fall back to a standalone
 * styled page (keeps the plugin working across cPanel builds).
 * ------------------------------------------------------------------------- */
$whmHelper = '/usr/local/cpanel/php/WHM.php';
$haveWhm   = false;
if (is_readable($whmHelper)) {
    require_once $whmHelper;
    $haveWhm = class_exists('WHM');
}

/* ---------------------------------------------------------------------------
 * Defaults + load current config.
 * ------------------------------------------------------------------------- */
function ipblock_defaults()
{
    return array(
        'enabled'       => false,
        'site_id'       => '',
        'api_key'       => '',
        'api_url'       => 'https://api.ip-block.com/v1/check',
        'fail_open'     => true,
        'cache_ttl'     => 300,
        'behind_proxy'  => false,
        'real_ip_header' => 'X-Forwarded-For',
        'block_action'  => '403',
        'block_message' => 'Access denied.',
        'redirect_url'  => 'https://www.ip-block.com/blocked.php',
        'whitelist'     => array(),
    );
}

function ipblock_load()
{
    $cfg = ipblock_defaults();
    if (is_readable(IPBLOCK_CONFIG_FILE)) {
        $data = json_decode((string) file_get_contents(IPBLOCK_CONFIG_FILE), true);
        if (is_array($data)) {
            $cfg = array_merge($cfg, $data);
        }
    }
    if (!is_array($cfg['whitelist'])) {
        $cfg['whitelist'] = array();
    }
    return $cfg;
}

function ipblock_save($cfg)
{
    if (!is_dir(IPBLOCK_CONFIG_DIR)) {
        @mkdir(IPBLOCK_CONFIG_DIR, 0755, true);
    }
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $ok = @file_put_contents(IPBLOCK_CONFIG_FILE, $json, LOCK_EX);
    if ($ok !== false) {
        // World-readable so each customer's PHP worker can read it; root-writable.
        @chmod(IPBLOCK_CONFIG_FILE, 0644);
    }
    return $ok !== false;
}

/* ---------------------------------------------------------------------------
 * CSRF token (simple, file-backed nonce).
 * ------------------------------------------------------------------------- */
function ipblock_token()
{
    $f = IPBLOCK_CONFIG_DIR . '/.whm_token';
    if (!is_dir(IPBLOCK_CONFIG_DIR)) {
        @mkdir(IPBLOCK_CONFIG_DIR, 0755, true);
    }
    if (is_readable($f)) {
        $t = trim((string) file_get_contents($f));
        if ($t !== '') {
            return $t;
        }
    }
    $t = bin2hex(random_bytes(16));
    @file_put_contents($f, $t);
    @chmod($f, 0600);
    return $t;
}

/* ---------------------------------------------------------------------------
 * Handle POST (save settings).
 * ------------------------------------------------------------------------- */
$notice = '';
$noticeType = '';
$token = ipblock_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $posted = isset($_POST['ipblock_token']) ? $_POST['ipblock_token'] : '';
    if (!hash_equals($token, (string) $posted)) {
        $notice = 'Security token mismatch. Please reload the page and try again.';
        $noticeType = 'danger';
    } else {
        $cfg = ipblock_defaults();
        $cfg['enabled']       = isset($_POST['enabled']);
        $cfg['site_id']       = trim((string) ($_POST['site_id'] ?? ''));
        $cfg['api_key']       = trim((string) ($_POST['api_key'] ?? ''));
        $cfg['api_url']       = trim((string) ($_POST['api_url'] ?? '')) ?: 'https://api.ip-block.com/v1/check';
        $cfg['fail_open']     = isset($_POST['fail_open']);
        $cfg['cache_ttl']     = max(0, (int) ($_POST['cache_ttl'] ?? 300));
        $cfg['behind_proxy']  = isset($_POST['behind_proxy']);
        $cfg['real_ip_header'] = trim((string) ($_POST['real_ip_header'] ?? 'X-Forwarded-For')) ?: 'X-Forwarded-For';
        $cfg['block_action']  = ($_POST['block_action'] ?? '403') === 'redirect' ? 'redirect' : '403';
        $cfg['block_message'] = trim((string) ($_POST['block_message'] ?? 'Access denied.'));
        $cfg['redirect_url']  = trim((string) ($_POST['redirect_url'] ?? '')) ?: 'https://www.ip-block.com/blocked.php';

        $wl = array();
        foreach (preg_split('/[\r\n,]+/', (string) ($_POST['whitelist'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $wl[] = $line;
            }
        }
        $cfg['whitelist'] = $wl;

        // Validate the essentials before enabling.
        if ($cfg['enabled'] && ($cfg['site_id'] === '' || $cfg['api_key'] === '')) {
            $notice = 'Site ID and API Key are required to enable protection.';
            $noticeType = 'danger';
        } elseif (ipblock_save($cfg)) {
            $notice = 'Settings saved. Protection is now ' . ($cfg['enabled'] ? 'ENABLED' : 'disabled') . '.';
            $noticeType = 'success';
        } else {
            $notice = 'Could not write ' . IPBLOCK_CONFIG_FILE . '. Check filesystem permissions.';
            $noticeType = 'danger';
        }
    }
}

$cfg = ipblock_load();
$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };

/* ---------------------------------------------------------------------------
 * Render.
 * ------------------------------------------------------------------------- */
if ($haveWhm) {
    // WHM header. (Second arg is the page title in most builds.)
    print WHM::header('IP-Block Protection', 0, 0);
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!doctype html><html><head><meta charset='utf-8'><title>IP-Block Protection</title>"
       . "<meta name='viewport' content='width=device-width, initial-scale=1'>"
       . "<style>body{font-family:-apple-system,Segoe UI,Roboto,sans-serif;margin:0;background:#f4f6f9;color:#222}"
       . ".wrap{max-width:820px;margin:24px auto;background:#fff;border:1px solid #e2e6ea;border-radius:8px;padding:24px}"
       . "h1{font-size:22px;margin:0 0 4px}.sub{color:#777;margin:0 0 20px}"
       . "label{display:block;font-weight:600;margin:16px 0 4px}"
       . "input[type=text],input[type=number],textarea,select{width:100%;padding:8px;border:1px solid #ccd2d8;border-radius:5px;box-sizing:border-box;font-size:14px}"
       . "textarea{min-height:90px;font-family:monospace}.row{display:flex;gap:20px}.row>div{flex:1}"
       . ".chk{display:flex;align-items:center;gap:8px;font-weight:600;margin-top:16px}.chk input{width:auto}"
       . ".hint{color:#888;font-size:12px;margin-top:3px}"
       . ".btn{margin-top:24px;background:#0072c6;color:#fff;border:0;padding:11px 22px;border-radius:5px;font-size:15px;cursor:pointer}"
       . ".alert{padding:12px 14px;border-radius:5px;margin-bottom:16px}"
       . ".success{background:#e6f4ea;border:1px solid #b7dfc2;color:#1e6b32}"
       . ".danger{background:#fdecea;border:1px solid #f5c2c0;color:#a1281f}</style></head><body>";
}
?>
<div class="wrap">
  <h1>IP-Block Protection</h1>
  <p class="sub">Server-wide visitor screening for all hosted sites &middot; powered by ip-block.com</p>

  <?php if ($notice !== ''): ?>
    <div class="alert <?php echo $h($noticeType); ?>"><?php echo $h($notice); ?></div>
  <?php endif; ?>

  <form method="post" action="index.php">
    <input type="hidden" name="ipblock_token" value="<?php echo $h($token); ?>">

    <div class="chk">
      <input type="checkbox" id="enabled" name="enabled" value="1" <?php echo $cfg['enabled'] ? 'checked' : ''; ?>>
      <label for="enabled" style="margin:0">Enable protection (server-wide)</label>
    </div>

    <div class="row">
      <div>
        <label for="site_id">Site ID</label>
        <input type="text" id="site_id" name="site_id" value="<?php echo $h($cfg['site_id']); ?>" autocomplete="off">
      </div>
      <div>
        <label for="api_key">API Key</label>
        <input type="text" id="api_key" name="api_key" value="<?php echo $h($cfg['api_key']); ?>" autocomplete="off">
      </div>
    </div>

    <label for="api_url">API URL</label>
    <input type="text" id="api_url" name="api_url" value="<?php echo $h($cfg['api_url']); ?>">
    <div class="hint">Default: https://api.ip-block.com/v1/check</div>

    <div class="row">
      <div>
        <label for="cache_ttl">Cache TTL (seconds)</label>
        <input type="number" id="cache_ttl" name="cache_ttl" min="0" value="<?php echo $h($cfg['cache_ttl']); ?>">
        <div class="hint">Per-IP decision cache. Default 300.</div>
      </div>
      <div>
        <label for="block_action">Block action</label>
        <select id="block_action" name="block_action">
          <option value="403" <?php echo $cfg['block_action'] === '403' ? 'selected' : ''; ?>>Return HTTP 403</option>
          <option value="redirect" <?php echo $cfg['block_action'] === 'redirect' ? 'selected' : ''; ?>>Redirect</option>
        </select>
      </div>
    </div>

    <div class="chk">
      <input type="checkbox" id="fail_open" name="fail_open" value="1" <?php echo $cfg['fail_open'] ? 'checked' : ''; ?>>
      <label for="fail_open" style="margin:0">Fail open (allow visitors if the API is unreachable) &mdash; recommended</label>
    </div>

    <div class="chk">
      <input type="checkbox" id="behind_proxy" name="behind_proxy" value="1" <?php echo $cfg['behind_proxy'] ? 'checked' : ''; ?>>
      <label for="behind_proxy" style="margin:0">Server is behind a proxy / CDN (trust real-IP header)</label>
    </div>
    <label for="real_ip_header">Real-IP header</label>
    <input type="text" id="real_ip_header" name="real_ip_header" value="<?php echo $h($cfg['real_ip_header']); ?>">
    <div class="hint">Only used when "behind proxy" is checked. e.g. X-Forwarded-For, CF-Connecting-IP</div>

    <label for="block_message">Block message (403 mode)</label>
    <input type="text" id="block_message" name="block_message" value="<?php echo $h($cfg['block_message']); ?>">

    <label for="redirect_url">Redirect URL (redirect mode)</label>
    <input type="text" id="redirect_url" name="redirect_url" value="<?php echo $h($cfg['redirect_url']); ?>">

    <label for="whitelist">Whitelist (one IP or CIDR per line)</label>
    <textarea id="whitelist" name="whitelist"><?php echo $h(implode("\n", $cfg['whitelist'])); ?></textarea>
    <div class="hint">These are never sent to the API and are always allowed. IPv4 &amp; IPv6, CIDR supported.</div>

    <button type="submit" class="btn">Save settings</button>
  </form>

  <p class="hint" style="margin-top:20px">
    Config file: <?php echo $h(IPBLOCK_CONFIG_FILE); ?> &middot;
    Guard: /opt/ipblock/ipblock-guard.php (auto_prepend_file for all ea-php versions)
  </p>
</div>
<?php
if ($haveWhm) {
    print WHM::footer();
} else {
    echo "</body></html>";
}
