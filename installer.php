<?php
if (!defined('FAMTASK_URL')) define('FAMTASK_URL', 'https://github.com/nanoemilios/famtask/releases/latest/download/famtask.zip');
define('APP_VERSION', 'v1.4.0');

$step = $_GET['step'] ?? 'welcome';
$installType = $_GET['install_type'] ?? '';

$configFile = __DIR__ . '/../my/.famtask_install_config.php';

function getInstallConfig(): array {
    global $configFile;
    if (file_exists($configFile)) {
        $config = include $configFile;
        return is_array($config) ? $config : ['type' => 'folder'];
    }
    return ['type' => 'folder'];
}

function saveInstallConfig(array $config): void {
    global $configFile;
    $dir = dirname($configFile);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $content = "<?php\nreturn " . var_export($config, true) . ";\n";
    file_put_contents($configFile, $content);
    
    $jsConfigFile = $dir . '/install-config.js';
    $jsContent = "window.FAMTASK_INSTALL_CONFIG = " . json_encode($config) . ";\n";
    file_put_contents($jsConfigFile, $jsContent);
    
    $webRoot = __DIR__ . '/..';
    $jsConfigFileRoot = $webRoot . '/install-config.js';
    file_put_contents($jsConfigFileRoot, $jsContent);
}

function getTargetDir(string $installType): string {
    $baseDir = __DIR__ . '/..';
    return $installType === 'subdomain' ? $baseDir : $baseDir . '/my';
}

function getAppBaseUrl(string $installType): string {
    if ($installType === 'subdomain') {
        return 'https://my.' . ($_SERVER['HTTP_HOST'] ?? 'domain.com');
    }
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'domain.com';
    return $protocol . '://' . $host . '/my';
}

$installConfig = getInstallConfig();
$currentInstallType = $installConfig['type'] ?? 'folder';

$isUpdate = hasInstallation();
$localVer = $isUpdate ? getLocalVersion() : '';

if ($isUpdate) {
    $targetDir = getTargetDir($currentInstallType);
} else {
    $targetDir = getTargetDir($installType ?: $currentInstallType);
}

if (!defined('TARGET_DIR')) define('TARGET_DIR', $targetDir);
$appBaseUrl = getAppBaseUrl($currentInstallType);

function checkReqs(): array {
    $reqs = [];
    $reqs[] = [
        'label' => 'PHP '.phpversion(),
        'ok' => version_compare(PHP_VERSION,'8.0','>='),
        'detail' => 'mind. 8.0 erforderlich',
        'fixes' => [
            'simple' => [
                'PHP-Version im Hosting-Panel ändern (meist unter "PHP-Version" oder "PHP-Einstellungen")',
                'Bei Shared Hosting: Support kontaktieren und auf PHP 8.0+ upgraden lassen',
            ],
            'complex' => [
                ['title' => 'PHP selbst kompilieren (VPS/Root)', 'url' => 'https://www.php.net/manual/de/install.unix.php'],
                ['title' => 'Docker mit PHP 8.0+ nutzen', 'url' => 'https://hub.docker.com/_/php'],
            ],
        ],
    ];
    $reqs[] = [
        'label' => 'ZipArchive',
        'ok' => class_exists('ZipArchive'),
        'detail' => 'PHP-Extension zip',
        'fixes' => [
            'simple' => [
                'Im Hosting-Panel: PHP-Extensions → "zip" aktivieren',
                'Bei cPanel: "Select PHP Version" → Extensions → "zip" anhaken',
                'Bei Plesk: "PHP-Einstellungen" → "zip" aktivieren',
            ],
            'complex' => [
                ['title' => 'Extension manuell installieren (VPS)', 'url' => 'https://www.php.net/manual/de/zip.installation.php'],
                ['title' => 'PECL: pecl install zip', 'url' => 'https://pecl.php.net/package/zip'],
            ],
        ],
    ];
    $curlOk = function_exists('curl_init');
    $fopenOk = ini_get('allow_url_fopen');
    $reqs[] = [
        'label' => 'cURL / fopen',
        'ok' => $curlOk || $fopenOk,
        'detail' => $curlOk && $fopenOk ? 'beide verfügbar' : ($curlOk ? 'nur cURL' : ($fopenOk ? 'nur allow_url_fopen' : 'weder cURL noch allow_url_fopen aktiv')),
        'fixes' => [
            'simple' => [
                'cURL: Im Hosting-Panel PHP-Extensions → "curl" aktivieren',
                'allow_url_fopen: In php.ini → allow_url_fopen = On setzen',
                'Bei Shared Hosting: Support fragen, eine der Optionen zu aktivieren',
            ],
            'complex' => [
                ['title' => 'cURL Extension installieren (VPS)', 'url' => 'https://www.php.net/manual/de/curl.installation.php'],
                ['title' => 'php.ini bearbeiten (allow_url_fopen)', 'url' => 'https://www.php.net/manual/de/filesystem.configuration.php#ini.allow-url-fopen'],
            ],
        ],
    ];
    $writable = is_writable(__DIR__);
    $reqs[] = [
        'label' => 'Schreibrechte',
        'ok' => $writable,
        'detail' => $writable ? 'Ordner ist beschreibbar' : 'Ordner muss beschreibbar sein',
        'fixes' => [
            'simple' => [
                'Per FTP/Dateimanager: Ordner-Rechte auf 755 (Ordner) / 644 (Dateien) setzen',
                'Besitzer prüfen: FTP-User muss Besitzer sein (nicht root/www-data)',
                'Bei cPanel: "Dateimanager" → Rechte ändern → 755 für Ordner',
            ],
            'complex' => [
                ['title' => 'chmod/chown per SSH (VPS/Root)', 'url' => 'https://www.linux.com/training-tutorials/understanding-linux-file-permissions/'],
                ['title' => 'ACLs für feinere Rechte setzen', 'url' => 'https://wiki.ubuntuusers.de/ACL/'],
                ['title' => 'SELinux-Kontext prüfen (CentOS/RHEL)', 'url' => 'https://wiki.centos.org/HowTos/SELinux'],
            ],
        ],
    ];
    $allOk = !in_array(false, array_column($reqs, 'ok'));
    return [$reqs, $allOk];
}

function doDownload(): array {
    $zipFile = __DIR__ . '/famtask-temp.zip';
    if (function_exists('curl_init')) {
        $ch = curl_init(FAMTASK_URL);
        $fp = fopen($zipFile, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch); fclose($fp);
        if ($error) return [false, 'Download fehlgeschlagen: ' . $error];
        if ($httpCode !== 200) { unlink($zipFile); return [false, "HTTP $httpCode &ndash; ZIP nicht gefunden unter:<br><code>" . htmlspecialchars(FAMTASK_URL) . '</code>']; }
    } else {
        $data = file_get_contents(FAMTASK_URL);
        if ($data === false) return [false, 'Download fehlgeschlagen (file_get_contents).'];
        file_put_contents($zipFile, $data);
    }
    if (!file_exists($zipFile) || filesize($zipFile) < 100) return [false, 'Download fehlgeschlagen &ndash; Datei zu klein.'];
    return [true, $zipFile];
}

function doExtract(string $zipFile, bool $isUpdate): array {
    global $currentInstallType;
    if (!$isUpdate && file_exists(TARGET_DIR)) {
        $files = array_filter(scandir(TARGET_DIR), fn($f)=>$f!=='.'&&$f!=='..');
        if (count($files) > 0) return [false, 'Zielordner ' . ($currentInstallType === 'subdomain' ? '"web root"' : '"my/"') . ' ist nicht leer. Bitte leeren oder manuell installieren.'];
    }
    $zip = new ZipArchive;
    if ($zip->open($zipFile) !== true) return [false, 'ZIP kann nicht ge&ouml;ffnet werden.'];
    $names = [];
    $entryCount = $zip->count();
    for ($i = 0; $i < $entryCount; $i++) $names[] = $zip->getNameIndex($i);
    $nested = str_starts_with($names[0] ?? '', 'my/');
    @mkdir(TARGET_DIR, 0755, true);

    foreach ($names as $name) {
        if (substr($name, -1) === '/') continue;
        if (isSkipEntry($name)) continue;
        $target = $nested ? preg_replace('#^my/#', '', $name) : $name;
        if ($target === '' || $target === '.') continue;
        $fullPath = TARGET_DIR . '/' . $target;
        if ($isUpdate && ($target === '.famtask_cfg.php' || $target === 'audio' || str_starts_with($target, 'audio/'))) continue;
        @mkdir(dirname($fullPath), 0755, true);
        if (!@copy("zip://$zipFile#$name", $fullPath)) { $zip->close(); return [false, 'Fehler beim Extrahieren von ' . htmlspecialchars($name)]; }
    }
    $zip->close();
    unlink($zipFile);
    @mkdir(TARGET_DIR . '/audio', 0755, true);
    if (!file_exists(TARGET_DIR . '/api.php')) return [false, 'api.php nicht gefunden &ndash; ZIP enth&auml;lt nicht die erwarteten Dateien.'];
    return [true, ''];
}

function isSkipEntry(string $name): bool {
    if (str_contains($name, '__MACOSX') || str_contains($name, '.DS_Store')) return true;
    if (preg_match('#(^|/)\.famtask_cfg\.php$#', $name)) return true;
    return false;
}

function hasInstallation(): bool {
    global $currentInstallType;
    $checkDir = getTargetDir($currentInstallType);
    if (!file_exists($checkDir)) return false;
    $files = array_filter(scandir($checkDir), fn($f)=>$f!=='.'&&$f!=='..');
    return count($files) > 0;
}

function hasConfig(): bool {
    return file_exists(TARGET_DIR . '/.famtask_cfg.php');
}

function getLocalVersion(): string {
    $f = TARGET_DIR . '/api.php';
    if (!file_exists($f)) return 'unbekannt';
    $c = file_get_contents($f);
    if (preg_match("/APP_VERSION\s*=\s*'([^']+)'/", $c, $m)) return 'v' . $m[1];
    return 'unbekannt';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>FamTask <?= $isUpdate ? 'Update' : 'Web Installer' ?></title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',Arial,sans-serif;background:#0D0E1A;color:#f0f0f0;min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:20px}
.card{background:#161728;border:1.5px solid rgba(255,255,255,.08);border-radius:18px;padding:32px;width:100%;max-width:520px}
.logo{font-size:32px;font-weight:900;background:linear-gradient(135deg,#FF6B6B,#FFE66D);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;text-align:center;margin-bottom:4px}
.sub{text-align:center;color:rgba(255,255,255,.35);font-size:13px;margin-bottom:22px}
h3{font-size:15px;font-weight:800;color:rgba(255,255,255,.6);margin-bottom:14px}
p{font-size:13px;color:rgba(255,255,255,.45);line-height:1.7;margin-bottom:16px}
.req-item{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#0D0E1A;border-radius:10px;margin-bottom:8px;font-size:13px}
.req-label{color:rgba(255,255,255,.6);font-weight:700}
.req-badge{padding:3px 10px;border-radius:99px;font-size:11px;font-weight:800}
.req-badge.ok{background:rgba(107,203,119,.15);color:#6BCB77}
.req-badge.fail{background:rgba(255,107,107,.15);color:#FF6B6B}
.btn{width:100%;padding:13px;border:none;border-radius:11px;font-size:14px;font-weight:800;cursor:pointer;margin-top:18px;transition:all .15s;text-decoration:none;display:block;text-align:center}
.btn-pri{background:linear-gradient(135deg,#FF6B6B,#FF8E53);color:#fff}
.btn-sec{background:rgba(78,205,196,.12);border:1.5px solid rgba(78,205,196,.3);color:#4ECDC4}
.btn-danger{background:rgba(255,107,107,.12);border:1.5px solid rgba(255,107,107,.3);color:#FF6B6B}
.btn-disabled{opacity:.4;pointer-events:none}
.msg{padding:14px 18px;border-radius:11px;font-size:13px;font-weight:700;margin-top:18px}
.msg.ok{background:rgba(107,203,119,.12);border:1.5px solid rgba(107,203,119,.3);color:#6BCB77}
.msg.err{background:rgba(255,107,107,.12);border:1.5px solid rgba(255,107,107,.3);color:#FF6B6B}
.msg.info{background:rgba(78,205,196,.08);border:1.5px solid rgba(78,205,196,.2);color:#4ECDC4}
.msg.warn{background:rgba(255,179,71,.08);border:1.5px solid rgba(255,179,71,.25);color:#FFB347}
code{background:#0D0E1A;border:1px solid rgba(255,255,255,.1);border-radius:6px;padding:2px 7px;font-family:monospace;font-size:12px;color:#FFE66D;word-break:break-all}
.spinner{display:inline-block;width:18px;height:18px;border:2px solid rgba(255,255,255,.3);border-top-color:#fff;border-radius:50%;animation:spin .8s linear infinite;vertical-align:middle;margin-right:8px}
@keyframes spin{to{transform:rotate(360deg)}}
@keyframes slideDown{from{opacity:0;transform:translateY(-8px)}to{opacity:1;transform:translateY(0)}}
.req-help-toggle:hover{background:rgba(255,179,71,.2)!important;border-color:#FFB347!important}
.req-help ul li{margin-bottom:6px}
.req-help ul li:last-child{margin-bottom:0}
.info-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:16px}
.info-item{background:#0D0E1A;border-radius:10px;padding:12px;text-align:center;font-size:12px}
.info-item .val{font-size:20px;font-weight:900;color:#fff;margin-bottom:3px}
.info-item .lbl{color:rgba(255,255,255,.35);font-weight:700}
.preserve-list{background:#0D0E1A;border-radius:10px;padding:12px 16px;margin:12px 0;font-size:12px;color:rgba(255,255,255,.5)}
.preserve-list span{display:inline-block;background:rgba(107,203,119,.1);border:1px solid rgba(107,203,119,.2);border-radius:6px;padding:3px 9px;margin:3px;color:#6BCB77;font-weight:700;font-size:11px}
</style>
</head>
<body>
<div class="card">

<?php if ($step === 'welcome'): ?>

<div class="logo">FamTask</div>
<div class="sub"><?= $isUpdate ? 'Update' : 'Web Installer' ?> &middot; <?= APP_VERSION ?></div>

<?php if ($isUpdate): ?>
<div class="msg info">Bestehende Installation gefunden (<?= $localVer ?>). Der Updater aktualisiert die App und f&uuml;hrt DB-Migrationen aus.</div>
<div class="info-grid">
  <div class="info-item"><div class="val"><?= $localVer ?></div><div class="lbl">Aktuelle Version</div></div>
  <div class="info-item"><div class="val"><?= APP_VERSION ?></div><div class="lbl">Neue Version</div></div>
</div>
<div class="preserve-list">
  Folgende Daten bleiben erhalten:<br>
  <span>.famtask_cfg.php</span><span>audio/</span>
</div>
<?php if (!hasConfig()): ?>
<div class="msg warn">Keine .famtask_cfg.php gefunden. Nach dem Update musst du die Datenbankverbindung neu einrichten.</div>
<?php endif; ?>
<?php [$reqs, $allOk] = checkReqs(); ?>
<h3>Voraussetzungen pr&uuml;fen</h3>
<?php foreach ($reqs as $i => $r): ?>
<div class="req-item" style="flex-wrap:wrap;gap:8px">
  <span class="req-label"><?= htmlspecialchars($r['label']) ?></span>
  <span class="req-badge <?= $r['ok']?'ok':'fail' ?>"><?= $r['ok']?'OK':$r['detail'] ?></span>
  <?php if (!$r['ok'] && isset($r['fixes'])): ?>
  <button type="button" class="req-help-toggle" data-target="help-<?= $i ?>" style="padding:4px 10px;background:rgba(255,179,71,.12);border:1.5px solid rgba(255,179,71,.3);color:#FFB347;border-radius:99px;font-size:11px;font-weight:800;cursor:pointer;margin-left:auto">+ Hilfe</button>
  <?php endif; ?>
</div>
<?php if (!$r['ok'] && isset($r['fixes'])): ?>
<div id="help-<?= $i ?>" class="req-help" style="display:none;margin-top:8px;padding:14px;background:#0D0E1A;border:1px solid rgba(255,179,71,.2);border-radius:10px;animation:slideDown .2s ease">
  <?php if (!empty($r['fixes']['simple'])): ?>
  <div style="margin-bottom:12px">
    <div style="font-weight:700;color:#FFB347;font-size:12px;margin-bottom:8px">⚡ Einfache Lösungen</div>
    <ul style="margin:0;padding-left:18px;font-size:12px;color:rgba(255,255,255,.7);line-height:1.8">
      <?php foreach ($r['fixes']['simple'] as $fix): ?>
      <li><?= $fix ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if (!empty($r['fixes']['complex'])): ?>
  <div>
    <div style="font-weight:700;color:#FFB347;font-size:12px;margin-bottom:8px">🔧 Erweiterte Lösungen</div>
    <ul style="margin:0;padding-left:18px;font-size:12px;color:rgba(255,255,255,.7);line-height:1.8">
      <?php foreach ($r['fixes']['complex'] as $fix): ?>
      <li><a href="<?= $fix['url'] ?>" target="_blank" rel="noopener" style="color:#4ECDC4;text-decoration:none;border-bottom:1px dashed #4ECDC4"><?= $fix['title'] ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($allOk): ?>
<a href="?step=install" class="btn btn-pri"><?= $isUpdate ? 'Jetzt updaten' : 'Jetzt herunterladen & installieren' ?></a>
<?php if ($isUpdate): ?>
<a href="<?= $appBaseUrl ?>/api.php?action=update" class="btn btn-sec" style="font-size:12px;margin-top:8px">Nur DB-Migration ausf&uuml;hren</a>
<?php endif; ?>
<?php else: ?>
<div class="msg err">Nicht alle Voraussetzungen erf&uuml;llt. Bitte nachbessern und Seite neu laden.</div>
<?php endif; ?>

<?php else: ?>
<p>Dieser Installer l&auml;dt die aktuelle FamTask-Version von <code><?= htmlspecialchars(FAMTASK_URL) ?></code> herunter, entpackt sie und f&uuml;hrt dich durch die Einrichtung.</p>

<?php if (empty($installType)): ?>
<h3 style="margin-top:24px;margin-bottom:14px">Installationsart w&auml;hlen</h3>
<div class="req-item" style="flex-direction:column;align-items:flex-start;gap:12px">
  <label style="display:flex;align-items:center;gap:10px;cursor:pointer;width:100%;padding:14px;background:#0D0E1A;border-radius:10px;border:1.5px solid rgba(255,255,255,.08);transition:border-color .2s" onmouseover="this.style.borderColor='rgba(78,205,196,.5)'" onmouseout="this.style.borderColor='rgba(255,255,255,.08)'">
    <input type="radio" name="install_type" value="folder" checked style="width:18px;height:18px;accent-color:#4ECDC4">
    <div>
      <div style="font-weight:700;color:#fff">Ordner-Installation <code>/my/</code></div>
      <div style="font-size:12px;color:rgba(255,255,255,.45)">App erreichbar unter <code><?= htmlspecialchars(getAppBaseUrl('folder')) ?></code></div>
      <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:4px">Standard. Funktioniert auf jedem Webspace ohne Subdomain-Konfiguration.</div>
    </div>
  </label>
  <label style="display:flex;align-items:center;gap:10px;cursor:pointer;width:100%;padding:14px;background:#0D0E1A;border-radius:10px;border:1.5px solid rgba(255,255,255,.08);transition:border-color .2s" onmouseover="this.style.borderColor='rgba(78,205,196,.5)'" onmouseout="this.style.borderColor='rgba(255,255,255,.08)'">
    <input type="radio" name="install_type" value="subdomain" style="width:18px;height:18px;accent-color:#4ECDC4">
    <div>
      <div style="font-weight:700;color:#fff">Subdomain-Installation <code>my.domain.com</code></div>
      <div style="font-size:12px;color:rgba(255,255,255,.45)">App erreichbar unter <code><?= htmlspecialchars(getAppBaseUrl('subdomain')) ?></code></div>
      <div style="font-size:11px;color:rgba(255,255,255,.3);margin-top:4px">Erfordert DNS-Eintrag f&uuml;r <code>my.</code> Subdomain, die auf diesen Ordner zeigt.</div>
    </div>
  </label>
</div>
<a href="?step=install&install_type=folder" class="btn btn-pri" style="margin-top:18px">Jetzt herunterladen & installieren (Ordner)</a>
<a href="?step=install&install_type=subdomain" class="btn btn-sec" style="margin-top:8px">Jetzt herunterladen & installieren (Subdomain)</a>
<?php else: ?>
<div class="msg info">Installationsart: <strong><?= $installType === 'subdomain' ? 'Subdomain (my.domain.com)' : 'Ordner (/my/)' ?></strong></div>
<?php [$reqs, $allOk] = checkReqs(); ?>
<h3>Voraussetzungen pr&uuml;fen</h3>
<?php foreach ($reqs as $i => $r): ?>
<div class="req-item" style="flex-wrap:wrap;gap:8px">
  <span class="req-label"><?= htmlspecialchars($r['label']) ?></span>
  <span class="req-badge <?= $r['ok']?'ok':'fail' ?>"><?= $r['ok']?'OK':$r['detail'] ?></span>
  <?php if (!$r['ok'] && isset($r['fixes'])): ?>
  <button type="button" class="req-help-toggle" data-target="help2-<?= $i ?>" style="padding:4px 10px;background:rgba(255,179,71,.12);border:1.5px solid rgba(255,179,71,.3);color:#FFB347;border-radius:99px;font-size:11px;font-weight:800;cursor:pointer;margin-left:auto">+ Hilfe</button>
  <?php endif; ?>
</div>
<?php if (!$r['ok'] && isset($r['fixes'])): ?>
<div id="help2-<?= $i ?>" class="req-help" style="display:none;margin-top:8px;padding:14px;background:#0D0E1A;border:1px solid rgba(255,179,71,.2);border-radius:10px;animation:slideDown .2s ease">
  <?php if (!empty($r['fixes']['simple'])): ?>
  <div style="margin-bottom:12px">
    <div style="font-weight:700;color:#FFB347;font-size:12px;margin-bottom:8px">⚡ Einfache Lösungen</div>
    <ul style="margin:0;padding-left:18px;font-size:12px;color:rgba(255,255,255,.7);line-height:1.8">
      <?php foreach ($r['fixes']['simple'] as $fix): ?>
      <li><?= $fix ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
  <?php if (!empty($r['fixes']['complex'])): ?>
  <div>
    <div style="font-weight:700;color:#FFB347;font-size:12px;margin-bottom:8px">🔧 Erweiterte Lösungen</div>
    <ul style="margin:0;padding-left:18px;font-size:12px;color:rgba(255,255,255,.7);line-height:1.8">
      <?php foreach ($r['fixes']['complex'] as $fix): ?>
      <li><a href="<?= $fix['url'] ?>" target="_blank" rel="noopener" style="color:#4ECDC4;text-decoration:none;border-bottom:1px dashed #4ECDC4"><?= $fix['title'] ?></a></li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>
<?php endforeach; ?>
<?php if ($allOk): ?>
<a href="?step=install&install_type=<?= $installType ?>" class="btn btn-pri">Fortfahren zur Installation</a>
<?php else: ?>
<div class="msg err">Nicht alle Voraussetzungen erf&uuml;llt. Bitte nachbessern und Seite neu laden.</div>
<?php endif; ?>
<a href="?step=welcome" class="btn btn-sec" style="margin-top:8px">Zur&uuml;ck & &Auml;ndern</a>
<?php endif; ?>
<?php endif; ?>

<?php elseif ($step === 'install'): ?>
<?php
if (!empty($installType)) {
    saveInstallConfig(['type' => $installType]);
    $targetDir = getTargetDir($installType);
    if (!defined('TARGET_DIR')) define('TARGET_DIR', $targetDir);
}
?>

<div class="logo">FamTask</div>
<div class="sub"><?= $isUpdate ? 'Update l&auml;uft' : 'Installation l&auml;uft' ?> &hellip;</div>
<div style="text-align:center;padding:30px 0"><span class="spinner"></span><br><span style="font-size:13px;color:rgba(255,255,255,.45)">Download wird gestartet &hellip;</span></div>
<?php
flush();
$result = doDownload();
if (!$result[0]) {
    echo '<div class="msg err">' . $result[1] . '</div>';
    echo '<a href="?step=welcome' . ($installType ? '&install_type=' . $installType : '') . '" class="btn btn-sec" style="margin-top:14px">Zur&uuml;ck</a>';
} else {
    echo '<meta http-equiv="refresh" content="1;url=?step=extract' . ($installType ? '&install_type=' . $installType : '') . '">';
    exit;
}
?>

<?php elseif ($step === 'extract'): ?>
<?php
if (!empty($installType)) {
    saveInstallConfig(['type' => $installType]);
    $targetDir = getTargetDir($installType);
    if (!defined('TARGET_DIR')) define('TARGET_DIR', $targetDir);
}
?>

<div class="logo">FamTask</div>
<div class="sub">Extraktion l&auml;uft &hellip;</div>
<div style="text-align:center;padding:30px 0"><span class="spinner"></span><br><span style="font-size:13px;color:rgba(255,255,255,.45)">ZIP wird entpackt &hellip;</span></div>
<?php
flush();
$zipFile = __DIR__ . '/famtask-temp.zip';
if (!file_exists($zipFile)) {
    $result = doDownload();
    if (!$result[0]) {
        echo '<div class="msg err">' . $result[1] . '</div>';
        echo '<a href="?step=welcome' . ($installType ? '&install_type=' . $installType : '') . '" class="btn btn-sec" style="margin-top:14px">Zur&uuml;ck</a>';
        exit;
    }
    $zipFile = $result[1];
}
$result = doExtract($zipFile, $isUpdate);
if (!$result[0]) {
    echo '<div class="msg err">' . $result[1] . '</div>';
    echo '<a href="?step=welcome' . ($installType ? '&install_type=' . $installType : '') . '" class="btn btn-sec" style="margin-top:14px">Zur&uuml;ck</a>';
} else {
    echo '<div class="msg ok">Dateien erfolgreich ' . ($isUpdate ? 'aktualisiert' : 'extrahiert') . '.</div>';
    $cnt = count(array_filter(scandir(TARGET_DIR), fn($f)=>$f[0]!=='.'));
    echo '<div class="info-grid">';
    echo '<div class="info-item"><div class="val">' . $cnt . '</div><div class="lbl">Dateien</div></div>';
    echo '<div class="info-item"><div class="val">' . APP_VERSION . '</div><div class="lbl">Version</div></div>';
    echo '</div>';
    $appUrl = getAppBaseUrl($currentInstallType ?: $installType);
    $next = $isUpdate ? $appUrl . '/api.php?action=update' : $appUrl . '/api.php?action=admin';
    $label = $isUpdate ? 'DB-Migration ausf&uuml;hren' : 'Datenbank einrichten';
    echo '<a href="' . $next . '" class="btn btn-pri">' . $label . '</a>';
    if ($isUpdate) {
        echo '<a href="' . $appUrl . '/" class="btn btn-sec" style="font-size:12px;margin-top:8px">Zur App</a>';
    }
}
?>

<?php elseif ($step === 'done'): ?>

<div class="logo">FamTask</div>
<div class="sub"><?= $isUpdate ? 'Update abgeschlossen' : 'Installation abgeschlossen' ?></div>
<div class="info-grid">
  <div class="info-item"><div class="val">&#x2705;</div><div class="lbl"><?= $isUpdate ? 'Aktualisiert' : 'Installiert' ?></div></div>
  <div class="info-item"><div class="val"><?= APP_VERSION ?></div><div class="lbl">Version</div></div>
</div>
<p style="text-align:center">FamTask wurde erfolgreich <?= $isUpdate ? 'aktualisiert' : 'eingerichtet' ?>.</p>
<?php $appUrl = getAppBaseUrl($currentInstallType); ?>
<a href="<?= $appUrl ?>/" class="btn btn-pri">App &ouml;ffnen</a>
<a href="<?= $appUrl ?>/api.php?action=admin" class="btn btn-sec" style="font-size:12px;margin-top:8px">Zum Admin-Panel</a>

<?php endif; ?>

<script>
document.querySelectorAll('.req-help-toggle').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var targetId = this.getAttribute('data-target');
        var target = document.getElementById(targetId);
        if (target) {
            var isHidden = target.style.display === 'none';
            target.style.display = isHidden ? 'block' : 'none';
            this.textContent = isHidden ? '− Hilfe ausblenden' : '+ Hilfe';
        }
    });
});
</script>
</div>
</body>
</html>