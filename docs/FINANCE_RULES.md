# Finanzregeln

Alle Formeln liegen in `services/analysis/app/finance/` und werden von pytest geprüft. Laravel speichert das Ergebnis und rechnet es nicht neu.

## Plan-Ist

Absolute Abweichung = Ist − Plan.

Relative Abweichung = absolute Abweichung / Plan × 100, nur wenn der Plan strikt positiv ist.

Plan null: Status `percent_not_meaningful`, Grund `plan_is_zero`.

Plan negativ: derselbe Status, Grund `plan_is_negative`. Eine Prozentzahl wird nicht ausgegeben.

## Mehrjahresentwicklung

Absolute und relative Veränderung zwischen einem bezeichneten Ausgangsjahr und einem Vergleichsjahr. Widersprechen sich zwei Dokumente zur selben Position, wird kein Mittelwert gebildet. Die Reihe gilt als nicht vergleichbar.

## Summen und Salden

Gruppen 21, 22, 31, 32, 33 und 34 werden gegen ihre gedruckten Untergruppen geprüft. Fehlt eine Untergruppe, ist der Status `incomplete`, nicht `failed`.

Salden: SA0 = 21 − 22, SA1 = 31 − 32, SA2 = 33 − 34, SA3 = SA1 + SA2, SA4 = 35 − 36, SA5 = SA3 + SA4, SA6 = 41 − 42, SA7 = SA5 + SA6, SA01 = 230 − 240, SA00 = SA0 + SA01.

Toleranz 0,05 EUR für Summen und 0,02 EUR für Identitäten.

## Liquidität

Die Verprobung verwendet die Zeilen A bis D, wenn sie vollständig vorliegen: (Endbestand Zahlungsmittel − Endbestand Kontokorrent) − (Anfangsbestand Zahlungsmittel − Anfangsbestand Kontokorrent) = SA7. Der Endbestand liquider Mittel im Nachweis ist der Nettobetrag, nicht der Bruttobestand.

## Investitionen

Code 34 vergleicht geplante und tatsächliche investive Auszahlungen. Der Plan aus demselben Dokument und der Plan aus einem anderen Dokument bleiben getrennte Vergleiche.

## Schuldenfortschreibung

Anfangsbestand + Zugänge − Abgänge = Endbestand, nur wenn alle vier Werte vorliegen. Fehlen sie, bleibt eine Datenlücke im Bericht. Es wird kein Bestand geschätzt.

## Wiederkehrende Abweichungen

Dieselbe Position mit derselben Abweichungsrichtung über mehrere Jahre wird markiert. Einzeljahre ohne Prozentbasis bleiben gekennzeichnet.

## Befunde

Wesentliche Plan-Ist-Hinweise verlangen einen Absolutbetrag von mindestens 50.000 EUR und, falls ein Prozentwert existiert, mindestens 10 Prozent. Code 34 mit negativer Abweichung ab 100.000 EUR wird als nicht ausgeschöpfte Investition geführt und nicht zusätzlich als allgemeine Planabweichung.

Quervergleiche zwischen Dokumenten gelten für die Überschriftenpositionen. Ein Widerspruch ist ein Prüfhinweis, kein Rechenfehler und kein Fehlverhalten.

## Rechtlicher Rahmen

Die gelesenen Unterlagen verwenden die Gliederung der VRV 2015 und verweisen im Rechnungsabschluss selbst auf § 47 der Oberösterreichischen Gemeindehaushaltsordnung. Maßgeblich bleiben die amtlichen Kundmachungen im Rechtsinformationssystem des Bundes. Diese Software bildet die in den veröffentlichten Anlagen gedruckten Bezeichnungen und Summen ab und ersetzt keine Rechtsauskunft.
