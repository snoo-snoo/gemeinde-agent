"""Perspective reports share one checked fact base.

Wording and emphasis change. Amounts are copied from findings and facts and are
not recalculated by a language model.
"""

from __future__ import annotations

from datetime import date
from decimal import Decimal

from app.finance.money import format_amount

LIMITATIONS = (
    "Ausgewertet werden nur die im jeweiligen Lauf tatsächlich gelesenen Veröffentlichungen. "
    "Bereichsbudgets werden nicht zur Gemeindesumme addiert. "
    "Prozentabweichungen werden nur bei streng positivem Basiswert berechnet. "
    "Vorjahreszahlen aus einem Nachweis sind die dort abgedruckten Vergleichswerte und ersetzen nicht den Originalabschluss, "
    "sofern dieser nicht selbst extrahiert wurde. "
    "Auffälligkeiten sind Prüfhinweise. Sie belegen weder eine Pflichtverletzung noch ein strafbares Verhalten. "
    "Ein Sprachmodell darf diese Kennzahlen nicht überschreiben."
)


def build_reports(context: dict) -> list[dict]:
    municipality = context.get("municipality") or {}
    facts = context.get("facts") or []
    findings = [item for item in context.get("findings") or [] if item.get("review_status") != "rejected"]
    questions = context.get("questions") or []
    generated_on = context.get("generated_on") or date.today().isoformat()
    sources = _sources(facts)
    years = sorted({int(fact["fiscal_year"]) for fact in facts if fact.get("fiscal_year")})
    period = {"from": years[0] if years else None, "to": years[-1] if years else None}
    common = {
        "generated_on": generated_on,
        "data_as_of": context.get("data_as_of") or generated_on,
        "period": period,
        "limitations": LIMITATIONS,
        "sources": sources,
        "municipality": municipality.get("name") or "Gemeinde",
    }
    builders = (
        ("executive_summary", "Executive Summary", _executive),
        ("audit_review", "Kritischer Finanzprüfungsbericht", _audit),
        ("citizen", "Bürgerbericht", _citizen),
        ("cfo", "CFO-Bericht", _cfo),
        ("questions", "Fragenkatalog", _question_report),
        ("multi_year", "Mehrjahresvergleich", _multi_year),
        ("data_quality", "Quellen- und Datenqualitätsbericht", _quality),
        ("gaps", "Fehlende Daten und offene Prüfungen", _gaps),
    )
    reports = []
    for report_type, title, builder in builders:
        body = builder(common, facts, findings, questions, context)
        reports.append({
            "report_type": report_type,
            "title": f"{title} – {common['municipality']}",
            "markdown": _wrap(common, title, body),
            "limitations": LIMITATIONS,
            "sources": sources,
            "period_start": period["from"],
            "period_end": period["to"],
            "status": "draft",
        })
    return reports


def _wrap(common: dict, title: str, body: str) -> str:
    source_lines = "\n".join(f"- {item['label']}: {item['url']}" for item in common["sources"]) or "- Keine Quelle im Lauf gespeichert."
    return "\n".join([
        f"# {title}",
        "",
        f"Gemeinde: {common['municipality']}",
        f"Erstellt am: {common['generated_on']}",
        f"Datenstand: {common['data_as_of']}",
        f"Analysezeitraum: {common['period']['from'] or '–'} bis {common['period']['to'] or '–'}",
        "Prüfstatus: Entwurf, nicht freigegeben.",
        "",
        body.strip(),
        "",
        "## Quellenverzeichnis",
        source_lines,
        "",
        "## Methodische Einschränkungen",
        common["limitations"],
        "",
    ])


def _headline(facts: list[dict], code: str, year: int | None, value_kind: str, scope: str = "bereinigt") -> dict | None:
    matches = [
        fact for fact in facts
        if fact.get("code") == code and fact.get("value_kind") == value_kind and fact.get("scope") == scope
        and (year is None or fact.get("fiscal_year") == year)
    ]
    return matches[-1] if matches else None


def _latest_actual_year(facts: list[dict]) -> int | None:
    years = [int(fact["fiscal_year"]) for fact in facts if fact.get("value_kind") == "ist" and fact.get("scope") == "bereinigt" and fact.get("fiscal_year")]
    return max(years) if years else None


def _euro(fact: dict | None) -> str:
    if fact is None:
        return "nicht extrahiert"
    return f"{fact.get('amount_original') or format_amount(Decimal(fact['amount']))} EUR"


def _executive(common, facts, findings, questions, context) -> str:
    year = _latest_actual_year(facts)
    netto = _headline(facts, "SA0", year, "ist")
    plan = _headline(facts, "SA0", year, "plan")
    return f"""
## Ergebnis

Für {year or "das letzte extrahierte Jahr"} ist ein Nettoergebnis von {_euro(netto)} ausgewiesen.
Der im selben Ausweis gegenübergestellte Planwert beträgt {_euro(plan)}.

Geprüft wurden {len(findings)} Hinweise. {len([item for item in findings if item.get('priority') == 1])} davon haben Priorität 1.
Offene Fragen: {len(questions)}.

Die Verwaltungssicht und die Prüfungssicht verwenden dieselben extrahierten Beträge.
"""


def _audit(common, facts, findings, questions, context) -> str:
    lines = ["## Offene Prüfungspunkte", ""]
    if not findings:
        lines.append("Aus den extrahierten Tabellen ergibt sich kein belastbarer priorisierter Hinweis. Das ist ein Datenbefund, keine Unbedenklichkeit.")
    for finding in findings:
        lines.append(f"### {finding.get('title')}")
        lines.append(finding.get("summary", ""))
        lines.append(f"Methode: {finding.get('method')}")
        lines.append(f"Mögliche Erklärung: {'; '.join(finding.get('possible_explanations') or [])}")
        lines.append(f"Gegenargument: {'; '.join(finding.get('counterarguments') or [])}")
        lines.append(finding.get("disclaimer", ""))
        lines.append("")
    return "\n".join(lines)


def _citizen(common, facts, findings, questions, context) -> str:
    year = _latest_actual_year(facts)
    revenue = _headline(facts, "21", year, "ist")
    expense = _headline(facts, "22", year, "ist")
    investment = _headline(facts, "34", year, "ist")
    return f"""
## In Kürze

Eine Gemeinde plant zu Jahresbeginn, wofür sie Geld ausgibt und einnimmt. Am Jahresende zeigt der Rechnungsabschluss, was daraus geworden ist.

Im Jahr {year or "–"} stehen in der bereinigten Ergebnisrechnung Erträge von {_euro(revenue)} und Aufwendungen von {_euro(expense)}.
Die investiven Auszahlungen betragen {_euro(investment)}. Das sind Zahlungen für länger nutzbare Vorhaben, nicht die laufenden Kosten.

Wenn eine Zahl hier fehlt, wurde sie in den gelesenen Unterlagen nicht als belastbare Tabellenzeile gefunden.
"""


def _cfo(common, facts, findings, questions, context) -> str:
    year = _latest_actual_year(facts)
    lines = ["## Kennzahlen der bereinigten Gesamtanlage", ""]
    for code, label in (
        ("SA0", "Nettoergebnis"),
        ("SA1", "Geldfluss operative Gebarung"),
        ("SA2", "Geldfluss investive Gebarung"),
        ("SA5", "Geldfluss voranschlagswirksame Gebarung"),
        ("21", "Summe Erträge"),
        ("22", "Summe Aufwendungen"),
        ("34", "Investive Auszahlungen"),
        ("35", "Einzahlungen Finanzierungstätigkeit"),
    ):
        actual = _headline(facts, code, year, "ist")
        plan = _headline(facts, code, year, "plan")
        lines.append(f"- {label} ({code}): Ist {_euro(actual)}, Plan im selben Ausweis {_euro(plan)}.")
    lines.extend(["", "## Szenarien", ""])
    lines.append(_scenarios(facts, context))
    return "\n".join(lines)


def _scenarios(facts: list[dict], context: dict) -> str:
    plans = [
        fact for fact in facts
        if fact.get("code") == "SA0" and fact.get("scope") == "nachweis" and fact.get("value_kind") in {"plan", "mittelfrist"}
    ]
    if not plans:
        return "Ein Best-, Basis- oder Stressszenario wird nicht gerechnet: im Nachweis fehlen dafür die mittelfristigen Nettoergebnisse."
    plans = sorted(plans, key=lambda fact: int(fact["fiscal_year"]))
    rows = "\n".join(
        f"- {fact['fiscal_year']} ({fact['value_kind']}): {fact.get('amount_original')} EUR, Quelle {fact.get('document_key')}"
        for fact in plans
    )
    base = next((fact for fact in facts if fact.get("code") == "2112" and fact.get("value_kind") == "plan" and fact.get("scope") == "bereinigt"), None)
    sensitivity = "Keine Sensitivität der Ertragsanteile: der Planwert der Gruppe 2112 wurde nicht extrahiert."
    if base is not None:
        amount = Decimal(base["amount"])
        down_five = (amount * Decimal("-0.05")).quantize(Decimal("0.01"))
        down_ten = (amount * Decimal("-0.10")).quantize(Decimal("0.01"))
        sensitivity = (
            f"Rechenvariante, kein Prognosemodell: 5 % der Ertragsanteile {base.get('amount_original')} EUR sind "
            f"{format_amount(abs(down_five))} EUR, 10 % sind {format_amount(abs(down_ten))} EUR. "
            "Wird nur dieser eine Ertrag geringer, verändert sich das Nettoergebnis um denselben Betrag nach unten. "
            "Wechselwirkungen, Förderungen und Ausgabenanpassungen sind nicht modelliert. Ein dokumentiertes Best-Case-Szenario liegt nicht vor und wird nicht erfunden."
        )
    return f"Basis sind die im Nachweis gedruckten Planwerte, nicht eine eigene Fortschreibung:\n{rows}\n\n{sensitivity}"


def _question_report(common, facts, findings, questions, context) -> str:
    if not questions:
        return "Aus den geprüften Hinweisen wurde keine Frage abgeleitet."
    lines = ["## Fragen für die Sitzung", ""]
    for index, question in enumerate(questions, start=1):
        lines.append(f"{index}. ({question.get('audience')}) {question.get('body')}")
    return "\n".join(lines)


def _multi_year(common, facts, findings, questions, context) -> str:
    comparisons = context.get("comparisons") or []
    interesting = [item for item in comparisons if item.get("code") in {"SA0", "SA5", "21", "34"} and item.get("comparable")]
    if not interesting:
        return "Ein belastbarer Mehrjahresvergleich ist mit den extrahierten, widerspruchsfreien Reihen nicht möglich."
    lines = ["## Veränderungen", ""]
    for item in interesting[:40]:
        relative = item.get("relative_change")
        relative_text = f"{relative} %" if relative is not None else "Prozent nicht ausgewiesen"
        lines.append(
            f"- {item.get('code')} {item.get('value_kind')} {item.get('scope')}: "
            f"{item.get('base_year')} {item.get('base_amount')} EUR → {item.get('comparison_year')} {item.get('comparison_amount')} EUR, "
            f"absolut {item.get('absolute_change')} EUR, relativ {relative_text}. {item.get('note') or ''}"
        )
    return "\n".join(lines)


def _quality(common, facts, findings, questions, context) -> str:
    validations = context.get("validations") or []
    counts: dict[str, int] = {}
    for validation in validations:
        counts[validation.get("status") or "unknown"] = counts.get(validation.get("status") or "unknown", 0) + 1
    counted = ", ".join(f"{status}: {count}" for status, count in sorted(counts.items())) or "keine"
    return f"Validierungen nach Status: {counted}. Extrahierte Fakten: {len(facts)}."


def _gaps(common, facts, findings, questions, context) -> str:
    gaps = context.get("data_gaps") or []
    if not gaps:
        gaps = ["Kein Schulden-Anfangsbestand mit Zu- und Abgängen extrahiert. Eine Schuldenfortschreibung wird deshalb nicht behauptet."]
    return "## Datenlücken\n\n" + "\n".join(f"- {gap}" for gap in gaps)


def _sources(facts: list[dict]) -> list[dict]:
    seen = set()
    sources = []
    for fact in facts:
        url = fact.get("source_url")
        key = fact.get("document_key")
        marker = (key, url)
        if marker in seen or not url:
            continue
        seen.add(marker)
        sources.append({"label": key or url, "url": url})
    return sources
