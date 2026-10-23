# KommunalAudit AI

Webanwendung zur nachvollziehbaren Prüfung veröffentlichter Gemeindefinanzen in Österreich. Laravel steuert Benutzer, Aufträge und Freigaben. Ein interner Python-Dienst lädt öffentliche Dokumente und rechnet Kennzahlen deterministisch. React spricht nur mit Laravel.

Die Pilotgemeinde ist die Marktgemeinde Aigen-Schlägl. Die Quelle ist die veröffentlichte Finanzseite `https://www.aigen-schlaegl.at/Verwaltung/Finanzen`. Es werden keine Dokumente erfunden. Was nicht heruntergeladen wurde, bleibt als Datenlücke sichtbar.

## Voraussetzungen ohne Docker

- PHP 8.3, Composer, PostgreSQL 16, Redis
- Node.js 22
- Python 3.12, `pdftotext` (poppler), optional Tesseract mit Sprachpaket `deu`
- Schriftarten DejaVu für PDF-Exporte

```bash
sudo service postgresql start
sudo service redis-server start
```

Datenbanken `kommunal_audit` und `kommunal_audit_test`, Benutzer `kommunal`.

## Backend

```bash
cd backend
cp .env.example .env
php artisan key:generate
composer install
php artisan migrate --force
php artisan db:seed --force
php artisan serve --host=127.0.0.1 --port=8000
```

In einem zweiten Terminal die Queue, wenn Läufe über die Oberfläche gestartet werden:

```bash
php artisan queue:work --timeout=3600
```

Zeitpläne, standardmäßig deaktiviert:

```bash
php artisan schedule:work
```

Ein Vordergrundlauf der Pilotgemeinde, unabhängig von der Queue:

```bash
php artisan kommunal:run aigen-schlaegl --sync
```

Wenn die Gemeinde-Website vorübergehend nicht antwortet, kann eine bereits heruntergeladene öffentliche PDF-Datei verarbeitet werden. Das Manifest nennt Pfad, Original-URL, Titel, Dokumenttyp und Haushaltsjahr. Mit `--gap` wird der Grund im Bericht als Datenlücke gespeichert. Der Befehl prüft, dass die Datei ein PDF ist und der Host zur Quelle gehört.

```bash
php artisan kommunal:import-captured aigen-schlaegl manifest.json --gap="Live-Abruf antwortete mit HTTP 503."
```

Der erste Seed legt die Verwaltung `admin@kommunalaudit.local` mit dem Passwort aus `ADMIN_PASSWORD` an. Der Standardwert `change-me-admin` ist nur für die lokale Einrichtung gedacht.

`KOMMUNAL_MAX_DOCUMENTS` begrenzt den ersten Lauf. Der Standard ist 2. Die Reihenfolge bevorzugt den Rechnungsabschluss 2024 und danach den Voranschlag 2024. `robots.txt` der Gemeinde nennt `Crawl-delay: 20`. Zwischen den Downloads wartet der Lauf diese Zeit.

## Analysedienst

Der Dienst ist nicht für Browser gedacht. Ohne `ANALYSIS_INTERNAL_TOKEN` antwortet jeder interne Endpunkt mit 401. `/health` bleibt offen.

```bash
cd services/analysis
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
ANALYSIS_INTERNAL_TOKEN=dev-internal-token-change-me \
ANALYSIS_STORAGE_ROOT=/absoluter/pfad/backend/storage/app/documents \
.venv/bin/uvicorn app.main:app --host 127.0.0.1 --port 8001
```

`ANALYSIS_STORAGE_ROOT` muss dem Laravel-Disk `documents` entsprechen, standardmäßig `backend/storage/app/documents`. Der Token muss mit `ANALYSIS_INTERNAL_TOKEN` im Laravel-`.env` übereinstimmen.

## Oberfläche

```bash
cd frontend
npm install
npm run dev
```

Vite leitet `/api` an `http://127.0.0.1:8000` weiter. Anmeldung unter `http://127.0.0.1:5173/anmelden`.

## Docker Compose

Docker ist eine zweite Installationsart. In dieser Entwicklungsumgebung stand die Docker-Engine nicht zur Verfügung, das Compose-File wurde deshalb nicht hier ausgeführt.

```bash
cp backend/.env.example /dev/null
export APP_KEY=base64:$(openssl rand -base64 32)
export ANALYSIS_INTERNAL_TOKEN=$(openssl rand -hex 24)
export ADMIN_PASSWORD='ein-langes-passwort'
docker compose up --build
```

Die Oberfläche liegt dann auf Port 5173, die API auf Port 8000. Der Analysedienst hat keinen veröffentlichten Port. Originaldateien liegen im Volume `documents`, das Backend und Analysedienst gemeinsam verwenden.

`php artisan serve` im Container ist für die lokale Compose-Umgebung. Für einen dauerhaften Betrieb ist PHP-FPM mit einem Webserver vorzusehen.

## Tests

```bash
cd services/analysis && .venv/bin/pytest
cd backend && php artisan test
cd frontend && npm run build
```

Die PHP-Tests verwenden PostgreSQL `kommunal_audit_test` und ersetzen den Analysedienst durch `Http::fake`. Sie laden die Gemeinde-Website nicht.

## Fachliche Grenze

Auffälligkeiten sind Prüfhinweise. Die Anwendung schließt aus einer Planabweichung nicht auf Fehlverhalten, persönliche Bereicherung oder eine Straftat. Kennzahlen werden im Python-Dienst berechnet. Ein Sprachmodell darf sie nicht überschreiben. Berichte bleiben Entwürfe, bis eine Person mit der Rolle Prüfung oder Verwaltung sie freigibt.

Weitere Entscheidungen stehen in `docs/`.
