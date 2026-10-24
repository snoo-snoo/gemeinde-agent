# Technische Entscheidungen

- Laravel 13 und PHP 8.3, Sanctum für Token, PostgreSQL für alle Fachdaten, Redis für Queue und Cache.
- React 19, Vite 8, Tailwind 4, TanStack Query 5, React Router 7, Recharts 3. Die Oberfläche ist deutsch und verwendet Source Sans 3 und Source Serif 4.
- FastAPI ohne OpenAPI-Oberfläche. Interne Routen verlangen ein gemeinsames Token. Ein leeres Token sperrt sie.
- VRV-Spalten werden über die horizontale Position im `pdftotext -layout`-Text gelesen. Ein links-nach-rechts-Zip würde leere Zellen verschieben.
- Bereichsbudgets werden nicht in die Gemeindesumme übernommen. Der Parser wechselt den Modus nur, wenn die Zeile mit der Abschnittsüberschrift beginnt. Fußnoten, die das Wort enthalten, bleiben im Gesamtteil.
- Relative Abweichung nur bei Planwert strikt größer als null. Null und negative Pläne bleiben ohne Prozent.
- Der Rechnungsabschluss druckt eine Spalte „VA“. Stimmt sie nicht mit dem Originalvoranschlag überein, entsteht ein Prüfhinweis. Die Spalte wird nicht stillschweigend in einen Nachtrag umbenannt.
- Kosten werden nur ausgewiesen, wenn der Anbieter Tokenzahlen liefert und am Modell ein Preis mit Herkunft `manual` oder `provider` hinterlegt ist. Sonst bleibt das Kostenfeld leer.
- Der erste Pilotlauf lädt höchstens `KOMMUNAL_MAX_DOCUMENTS` Dateien, Standard 2, und bevorzugt RA 2024 vor VA 2024. Die übrigen entdeckten Links bleiben im Status `discovered`.
- Zwischen Downloads gilt die Crawl-Delay aus `robots.txt`. Die Entdeckung selbst liest die Startseite und danach `robots.txt` ohne die volle Wartezeit davor.
- SSRF-Schutz erlaubt nur `http` und `https`, verbietet Benutzerinfo, lokale Namen und nicht globale IP-Adressen, folgt höchstens drei Redirects und prüft jede Zieladresse neu. Ein DNS-Rebinding zwischen Prüfung und Verbindung bleibt ein Restrisiko.
- Dokumente und Webseiten sind Daten. Eine Zeile, die wie eine Anweisung aussieht, ändert weder Prompt noch Formeln.
- Docker Compose ist beschrieben. In der Umgebung, in der die erste Version gebaut wurde, war keine Docker-Engine verfügbar. Der Nachweis lief über PostgreSQL, Redis, PHP, Python und Vite auf dem Host.
