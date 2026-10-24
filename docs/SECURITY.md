# Sicherheit

- Anmeldung über E-Mail und Passwort, Sanctum-Bearer-Token, Rollenmiddleware.
- Login ist auf 10 Versuche je Minute begrenzt, die übrige API auf 120.
- API-Fehler unter `/api` werden als JSON ausgegeben.
- Interne Python-Routen ohne gültiges Token antworten mit 401. Die API-Dokumentation des Dienstes ist abgeschaltet.
- Downloads folgen Redirects von Hand, höchstens drei, und prüfen jede Adresse gegen öffentliche HTTP-Ziele. Dateien über 40 MB werden abgelehnt.
- Dateinamen aus dem Auftrag dürfen keine Pfadtrenner und keinen führenden Punkt enthalten. Exporte müssen innerhalb von `ANALYSIS_STORAGE_ROOT` liegen.
- Originaldateien und extrahierte Fakten sind getrennte Tabellen. Die Oberfläche lädt Dateien nur mit gültigem Token.
- Befunde starten als Entwurf. Evidence Review kann `evidence_checked`, `rejected` oder `needs_manual_review` setzen, nicht `approved`.
- Webseiten und PDFs werden als unvertrauenswürdige Daten behandelt. Eine darin stehende Anweisung ersetzt keine Systemregel.
- Erzählungen, die einen deutschen Betrag enthalten, der nicht in der erlaubten Liste steht, werden verworfen. Der deterministische Text bleibt.
- Personenbezogene Konten oder Bürgerdaten sind nicht Teil des Datenmodells. Verarbeitet werden veröffentlichte Haushaltsunterlagen und die Benutzer der Anwendung.

Vor der Weitergabe eines kritischen Berichts ist die Freigabe in der Oberfläche vorgesehen. Ein exportierter Entwurf trägt den Prüfstatus Entwurf.
