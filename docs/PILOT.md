# Pilot Aigen-Schlägl

Quelle: https://www.aigen-schlaegl.at/Verwaltung/Finanzen

`robots.txt` enthält eine Sitemap und `Crawl-delay: 20`. Es gibt keine `Disallow`-Regel. Die Finanzseite verlinkt Rechnungsabschlüsse, Voranschläge, Nachtragsvoranschläge und mittelfristige Finanzpläne als `getDocument.ashx`-Dateien. Die Zuordnung von Jahr und Dokumenttyp folgt dem sichtbaren Linktext.

Für die Parserprüfung wurden zwei veröffentlichte PDFs gelesen:

- Rechnungsabschluss 2024, `fileid=4017641`, 482 Seiten, SHA-256 `d08bbb37d6d84bb4c2cb4ec222070b0dff6431fbc0b26858c2960dae8742792f`
- Voranschlag 2024, `fileid=3590587`, 319 Seiten, SHA-256 `5d2d1a84e2d49a0ccb48023b8d4429171b4c598b2bdc35dce4f9b66b054cd501`

Beide haben eine Textebene. OCR war dafür nicht erforderlich.

Aus dem Layouttext des Rechnungsabschlusses, Umfang bereinigt, in EUR:

- SA0 Ist 60.736,62, gedruckter Plan VA 2024 = 82.000,00, Differenz −21.263,38
- Summe Erträge 21 Ist 9.496.440,72, Plan 9.670.800,00
- MVAG 34 investive Auszahlungen Ist 1.408.715,56, Plan 1.688.400,00
- Liquidität: Anfang Zahlungsmittel 519.919,42, Ende 37.680,76, Ende Kontokorrent 428.480,65. Die Verprobung trifft SA7.

Der Originalvoranschlag 2024 weist für SA0 den Plan −151.300,00 aus. Das ist nicht derselbe Betrag wie die Spalte VA 2024 im Rechnungsabschluss. Der Lauf behält beide Werte und macht daraus einen Prüfhinweis, keine Bewertung eines Fehlverhaltens.

Im Nachweis stimmt SA0 Ist 2022 mit 112.657,23 zwischen Voranschlag 2024 und Rechnungsabschluss 2024 überein.

Die Schuldenfortschreibung Anfang, Zugang, Abgang und Ende wurde in diesen beiden Gesamtteilen nicht gemeinsam als vier Werte extrahiert. Der Bericht nennt das als Datenlücke.

## Lauf am 23. Oktober 2026

Ein Live-Abruf der Finanzseite und der Dokumentadressen antwortete mit HTTP 503 und dem Text „No server is available to handle this request.“ Der Lauf speichert das als Datenlücke. Es wurden dabei keine Dokumente erfunden.

Dieselben zwei PDFs, die am selben Tag zuvor von diesen Adressen geladen worden waren, wurden danach mit `php artisan kommunal:import-captured` verarbeitet. Die archivierten SHA-256 stimmen mit dem früheren Abruf überein. In PostgreSQL stehen danach unter anderem:

- SA0 Ist 2024 bereinigt 60.736,62 auf Seite 66, gedruckter Plan 82.000,00
- Originalvoranschlag SA0 Plan 2024 −151.300,00 auf Seite 53
- Erträge 21 Ist 9.496.440,72, Plan im Rechnungsabschluss 9.670.800,00
- Investive Auszahlungen 34 Ist 1.408.715,56, Plan 1.688.400,00
- 2.226 Fakten, 32 Prüfhinweise im Entwurfsstatus, 31 Fragen
- acht Berichtstypen, darunter Executive Summary und Bürgerbericht
- zwölf abgeschlossene Agentenläufe in diesem Import und ein zuvor ausgeführter Source-Discovery-Lauf

Die Liquiditätsidentität ist als bestanden gespeichert. Die Schuldenfortschreibung bleibt eine Datenlücke. 90 Positionsvergleiche zwischen den beiden Dokumenten sind nicht gleich; 92 sind gleich. Das sind Prüfhinweise zur Quellenkonsistenz, keine festgestellten Pflichtverletzungen.
