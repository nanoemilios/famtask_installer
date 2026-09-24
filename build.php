<?php
/**
 * FamTask – Distribution-ZIP bauen
 * ─────────────────────────────────────────────────────────────────────────────
 * Erstellt aus dem `my/`-Ordner die ZIP, die der Web Installer (`installer.php`)
 * herunterlädt und auf den Ziel-Webspace entpackt.
 *
 * Bewusst AUSGESCHLOSSEN (Datenschutz / Nutzerdaten):
 *   • `.famtask_cfg.php`   – DB-Zugangsdaten, wird nie distribuiert
 *   • `audio/`             – persönliche Musik/Uploads der Familie
 *   • `.DS_Store`, `__MACOSX`, `node_modules`, Temp-Dateien
 *
 * MIT an Bord: `vendor/` (React/Babel/Fonts lokal), `lang/`, alle App-Dateien.
 *
 * Aufruf:
 *   php webinstaller/build.php [ziel.zip]
 *     (Ziel standardmäßig: famtask.zip im Projekt-Wurzelverzeichnis)
 * ─────────────────────────────────────────────────────────────────────────
 */
define('SRC_DIR', __DIR__ . '/..' . DIRECTORY_SEPARATOR . 'my');

$out = $argv[1] ?? (__DIR__ . '/../famtask.zip');

function ftSkip(string $rel): bool {
    if ($rel === '') return true;
    if (str_contains($rel, '__MACOSX') || str_contains($rel, '.DS_Store')) return true;
    if (preg_match('#(^|/)\.famtask_cfg\.php$#', $rel)) return true;
    if ($rel === 'audio' || str_starts_with($rel, 'audio/')) return true;
    if (str_contains($rel, '/node_modules/')) return true;
    return false;
}

function ftAddDir(ZipArchive $zip, string $dir, string $relPrefix): int {
    $added = 0;
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        $rel  = $relPrefix === '' ? $item : $relPrefix . '/' . $item;
        if (ftSkip($rel)) continue;
        if (is_dir($path)) $added += ftAddDir($zip, $path, $rel);
        else { $zip->addFile($path, $rel); $added++; }
    }
    return $added;
}

echo "FamTask-Build\n";
if (!is_dir(SRC_DIR)) { fwrite(STDERR, "Quellordner nicht gefunden: " . SRC_DIR . "\n"); exit(1); }

$zip = new ZipArchive;
if ($zip->open($out, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "ZIP kann nicht erstellt werden: $out\n"); exit(1);
}
$added = ftAddDir($zip, SRC_DIR, '');
$zip->close();

printf("Fertig: %d Eintraege nach %s (%d KB)\n", $added, $out, (int)ceil(filesize($out) / 1024));