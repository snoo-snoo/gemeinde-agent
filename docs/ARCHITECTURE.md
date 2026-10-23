# Architektur

Laravel ist die einzige öffentliche API. React authentifiziert sich mit einem Sanctum-Token und ruft ausschließlich `/api` auf. Der Python-Dienst hört intern und verlangt den Header `X-Internal-Token`.

```
Browser → Laravel → Queue/Pipeline → FastAPI
                              ↘ PostgreSQL
                              ↘ Datei-Disk documents
```

## Ablauf eines Prüflaufs

1. Gemeinde und Quelle sind registriert.
2. Source Discovery liest die konfigurierte Seite und `robots.txt`.
3. Scraper lädt bis zum Quellenlimit öffentliche Dateien, nach Bevorzugung von Rechnungsabschluss und Voranschlag 2024.
4. Die Originalbytes werden versioniert (SHA-256, URL, Abrufzeit).
5. Financial Extraction liest den Layouttext und speichert Originalbetrag getrennt vom normalisierten Betrag.
6. Accounting Validation und Historical Comparison rechnen mit festen Formeln.
7. Anomaly Detection erzeugt Prüfhinweise. Evidence Review kann sie zurückweisen oder zur manuellen Prüfung geben. Kein Agent setzt den Status `approved`.
8. Reporting schreibt acht Berichtstypen aus derselben Faktenliste.
9. Der Lauf endet mit `awaiting_approval`.

Lange Schritte laufen in der Queue oder über `kommunal:run --sync`. Die HTTP-Antwort auf `POST /api/analyses` ist 202.

## Agenten

Die 13 Agenten stehen in `shared/agent-catalog.json` und als Zeilen in `agent_configs`. Der Orchestrator in `App\Services\Analysis\Pipeline` ruft jeden Agenten als eigenen HTTP-Auftrag auf und speichert einen `agent_run`. Ein bereits abgeschlossener Schritt desselben Laufs wird nicht wiederholt. Extraktion und Download sind zusätzlich über die Dokumentadresse abgegrenzt, damit das zweite Dokument nicht vom ersten Lauf blockiert wird.

Deterministisch und ohne Sprachmodell: Discovery, Acquisition, Klassifikation, Extraktion, Validierung, Vergleich, Anomalie, Evidence. CFO, Verwaltungsperspektive, Opposition, Bürger und Reporting haben einen deterministischen Kern. Ein Sprachmodell wird nur über das Laravel-Gateway benutzt, und nur wenn Provider, Schlüssel und Modell konfiguriert sind.

## Rollen

`viewer` liest. `analyst` legt Gemeinden, Quellen und Läufe an und beantwortet Fragen. `reviewer` und `admin` geben Befunde und Berichte frei. `admin` verwaltet Provider, Schlüssel, Modelle, Agenten, Benutzer und Zeitpläne.

## Speicher

Der Disk `documents` ist lokal unter `storage/app/documents`. `DOCUMENT_DISK=s3` schaltet den Treiber auf S3. Der aktuelle Erwerb übergibt dem Analysedienst einen Dateipfad. Dafür müssen Laravel und Python denselben Pfad sehen, im Compose-Setup über das Volume `documents`. Ein reiner Objektspeicher ohne gemeinsame Einbindung ist in dieser Version nicht der Erwerbspfad.
