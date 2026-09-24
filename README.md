# FamTask Installer - Web-Installer & Updater

Web-basiertes Installations- und Update-Tool für FamTask. Lädt die neueste Version von GitHub Releases herunter, prüft Voraussetzungen und führt durch die Einrichtung.

## Features

- **Automatischer Download** der neuesten FamTask-Version von GitHub Releases
- **Voraussetzungs-Prüfung**: PHP-Version, Extensions (zip, cURL), Schreibrechte
- **Zwei Installationsarten**: Ordner-Installation (`/my/`) oder Subdomain (`my.domain.com`)
- **Update-Funktion**: Erhältliche Updates erkennen, Dateien aktualisieren, DB-Migrationen ausführen
- **Daten-Schutz**: `.famtask_cfg.php` und `audio/` Ordner werden bei Updates beibehalten
- **Hilfe-System**: Kontext-sensitive Lösungsvorschläge bei fehlenden Voraussetzungen

## Voraussetzungen

- PHP 8.0+
- ZipArchive Extension
- cURL oder `allow_url_fopen`
- Schreibrechte im Installationsverzeichnis

## Installation

### Auf Webspace hochladen

```bash
git clone https://github.com/nanoemilios/famtask_installer.git
cd famtask_installer
# Lade den Ordnerinhalt per FTP/SFTP in dein Webverzeichnis hoch
# Rufe im Browser auf: https://deine-domain.com/installer.php
```

### GitHub Release erstellen (für Download)

Der Installer lädt die App von GitHub Releases herunter. Erstelle ein Release:

1. Gehe zu https://github.com/nanoemilios/famtask/releases
2. "Create a new release"
3. Tag: `v1.4.0` (entspricht APP_VERSION in api.php)
4. Title: `FamTask v1.4.0`
5. **Asset hochladen**: `famtask.zip` (Inhalt des `famtask` Repos als ZIP)
6. Publish release

Die ZIP-Struktur muss sein:
```
famtask.zip
├── api.php
├── index.html
├── jukebox.php
├── styles.css
├── sw.js
├── manifest.json
├── i18n.js
├── lang/
├── vendor/
├── audio/
└── .htaccess
```

Erstellen:
```bash
cd famtask
zip -r ../famtask.zip . -x "audio/*" ".famtask_cfg.php" "*.git*"
```

## Konfiguration

In `installer.php` anpassen:

```php
if (!defined('FAMTASK_URL')) define('FAMTASK_URL', 'https://github.com/nanoemilios/famtask/releases/latest/download/famtask.zip');
define('APP_VERSION', 'v1.4.0'); // Muss mit Release-Tag übereinstimmen
```

## Ablauf

1. **Welcome** – Installationsart wählen (Ordner/Subdomain)
2. **Prerequisites** – System prüfen
3. **Download** – ZIP von GitHub laden
4. **Extract** – Entpacken in Zielverzeichnis
5. **Done** – Weiterleitung zu Admin-Panel (`api.php?action=admin`) für DB-Setup

## Update

Bei neuer Version:
1. Neues Release auf famtask Repo erstellen
2. Installer aufrufen → erkennt Update automatisch
3. "Jetzt updaten" klicken
4. DB-Migration ausführen (`api.php?action=update`)

## Dateien

- `installer.php` – Haupt-Installer (PHP + HTML + CSS + JS)
- `build.php` – Hilfsscript zum Erstellen der Release-ZIP
- `famtask.sql` – SQL-Dump für Neuinstallation (optional)

## Verwandte Repositories

- **famtask** – Hauptanwendung
- **famtask_docker** – Docker Compose Setup + Proxmox Installer