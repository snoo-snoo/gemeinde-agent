"""Review hints derived from deterministic results.

A hint is a reason to ask a question. It is not evidence of misconduct,
personal enrichment or a criminal offence.
"""

from __future__ import annotations

from decimal import Decimal

MATERIAL_ABS = Decimal("50000.00")
MATERIAL_PCT = Decimal("10.00")
INVESTMENT_ABS = Decimal("100000.00")
HEADLINE_CODES = {"SA0", "SA00", "SA1", "SA5", "SA7", "21", "22", "31", "34", "35"}

EXPLANATIONS = [
    "Nachtragsvoranschläge oder geänderte Projektabgrenzungen können den ausgewiesenen Plan verändert haben.",
    "Periodenverschiebungen verschieben Auszahlungen in ein anderes Haushaltsjahr, ohne dass die Gesamtkosten entfallen.",
    "Einmalige Vorgänge, Förderbedingungen oder gesetzliche Verpflichtungen können die Abweichung erklären.",
]


def build_findings(facts: list[dict], validations: list[dict], investments: list[dict]) -> list[dict]:
    findings: list[dict] = []
    findings.extend(_plan_actual_findings(validations))
    findings.extend(_consistency_findings(validations))
    findings.extend(_calculation_findings(validations))
    findings.extend(_investment_findings(investments))
    findings.extend(_liquidity_findings(facts, validations))
    findings.extend(_financing_gap_findings(facts))
    return findings


def _plan_actual_findings(validations: list[dict]) -> list[dict]:
    findings = []
    for validation in validations:
        if validation.get("rule_code") != "plan_actual" or validation.get("status") not in {"passed", "percent_not_meaningful"}:
            continue
        result = validation.get("result") or {}
        absolute = _as_decimal(result.get("absolute_decimal"))
        relative = _as_decimal(result.get("relative_percent"))
        inputs = validation.get("inputs") or {}
        code = str(inputs.get("code") or "")
        if code not in HEADLINE_CODES and not code.startswith("SA"):
            continue
        if code == "34" and absolute is not None and absolute < 0:
            continue
        if absolute is None or abs(absolute) < MATERIAL_ABS:
            continue
        if relative is not None and abs(relative) < MATERIAL_PCT:
            continue
        findings.append(_finding(
            rule_code="material_plan_actual",
            title=f"Wesentliche Plan-Ist-Abweichung bei {inputs.get('code')} im Jahr {inputs.get('year')}",
            summary=validation.get("message", ""),
            years=[inputs.get("year")],
            positions=[{"code": inputs.get("code"), "scope": inputs.get("scope")}],
            inputs=inputs,
            absolute=absolute,
            relative=relative,
            method=validation.get("formula", ""),
            fact_ids=validation.get("fact_ids") or [],
            relevance="hoch" if abs(absolute) >= INVESTMENT_ABS else "mittel",
            priority=2 if abs(absolute) >= INVESTMENT_ABS else 3,
            explanations=EXPLANATIONS,
            counterarguments=[
                "Die Abweichung kann aus dem genehmigten Nachtragsvoranschlag stammen und ist dann kein Vollzugsrückstand gegenüber dem letzten Beschluss.",
            ],
        ))
    return findings


def _consistency_findings(validations: list[dict]) -> list[dict]:
    findings = []
    for validation in validations:
        if validation.get("rule_code") != "cross_document_consistency" or validation.get("status") != "failed":
            continue
        inputs = validation.get("inputs") or {}
        amounts = inputs.get("amounts") or []
        if inputs.get("code") not in HEADLINE_CODES:
            continue
        if len(amounts) < 2:
            continue
        first, second = Decimal(amounts[0]), Decimal(amounts[1])
        findings.append(_finding(
            rule_code="cross_document_mismatch",
            title="Gleich bezeichnete Haushaltszahl unterscheidet sich zwischen zwei Veröffentlichungen",
            summary=validation.get("message", ""),
            years=[],
            positions=[],
            inputs=inputs,
            absolute=second - first,
            relative=None,
            method=validation.get("formula", ""),
            fact_ids=validation.get("fact_ids") or [],
            relevance="hoch",
            priority=1,
            explanations=[
                "Der Rechnungsabschluss kann den Voranschlag einschließlich Nachträgen ausweisen, während das Voranschlagsdokument den ursprünglichen Beschluss zeigt.",
                "Die Spaltenüberschrift kann ein anderes Vergleichsjahr meinen als der Dateiname.",
            ],
            counterarguments=[
                "Solange der Nachtragsvoranschlag nicht gegenübergestellt ist, lässt sich nicht sagen, welche Zahl der letzte gültige Planwert ist.",
            ],
            data_quality="widerspruechliche_quellen",
        ))
    return findings


def _calculation_findings(validations: list[dict]) -> list[dict]:
    findings = []
    for validation in validations:
        if validation.get("rule_code") not in {"group_sum", "saldo_identity", "reported_deviation", "liquidity_identity", "nachweis_bridge"}:
            continue
        if validation.get("status") != "failed":
            continue
        findings.append(_finding(
            rule_code="calculation_break",
            title=f"Rechnerische Prüfung nicht bestanden: {validation.get('rule_code')}",
            summary=validation.get("message", ""),
            years=[],
            positions=[],
            inputs=validation.get("inputs") or {},
            absolute=None,
            relative=None,
            method=validation.get("formula", ""),
            fact_ids=validation.get("fact_ids") or [],
            relevance="hoch",
            priority=1,
            explanations=["Ein Extraktionsfehler kann eine scheinbare Differenz erzeugen. Die Fundstelle ist vor einer fachlichen Wertung erneut zu lesen."],
            counterarguments=["Rundungsdifferenzen unter der Toleranz werden nicht als Bruch gewertet. Diese Differenz liegt darüber."],
            data_quality="rechnerisch_auffaellig",
        ))
    return findings


def _investment_findings(investments: list[dict]) -> list[dict]:
    findings = []
    for item in investments:
        deviation = item.get("deviation") or {}
        absolute = _as_decimal(deviation.get("absolute_decimal"))
        if absolute is None or abs(absolute) < INVESTMENT_ABS:
            continue
        if absolute >= 0:
            continue
        findings.append(_finding(
            rule_code="investment_underspend",
            title=f"Investive Auszahlungen {item.get('fiscal_year')} liegen unter dem herangezogenen Planwert",
            summary=(
                f"Die bereinigten investiven Auszahlungen (MVAG 34) betragen {item.get('actual')} EUR, "
                f"der herangezogene Planwert {item.get('plan')} EUR. Herkunft des Planwerts: {item.get('plan_origin')}."
            ),
            years=[item.get("fiscal_year")],
            positions=[{"code": "34", "label": "Summe Auszahlungen investive Gebarung"}],
            inputs={"actual": item.get("actual"), "plan": item.get("plan"), "origin": item.get("plan_origin")},
            absolute=absolute,
            relative=_as_decimal(deviation.get("relative_percent")),
            method=deviation.get("formula", ""),
            fact_ids=item.get("fact_ids") or [],
            relevance="hoch",
            priority=2,
            explanations=EXPLANATIONS,
            counterarguments=[item.get("boundary_note", "")],
        ))
    return findings


def _liquidity_findings(facts: list[dict], validations: list[dict]) -> list[dict]:
    closing_debt = next((fact for fact in facts if fact.get("code") == "D" and fact.get("statement") == "liquiditaet"), None)
    closing_cash = next((fact for fact in facts if fact.get("code") == "C" and fact.get("statement") == "liquiditaet"), None)
    if closing_debt is None or closing_cash is None:
        return []
    net = Decimal(closing_cash["amount"]) - Decimal(closing_debt["amount"])
    if net >= 0:
        return []
    identity = next((item for item in validations if item.get("rule_code") == "liquidity_identity"), None)
    return [_finding(
        rule_code="net_liquidity_negative",
        title="Liquide Mittel abzüglich kontokorrentartiger Finanzschulden sind negativ",
        summary=(
            f"Ausgewiesen sind liquide Mittel von {closing_cash.get('amount_original')} EUR und kurzfristige "
            f"Finanzschulden aus überzogenen Konten von {closing_debt.get('amount_original')} EUR. "
            f"Der Saldo beträgt {net} EUR. Das ist ein Liquiditätshinweis und keine Aussage über die Ursache."
        ),
        years=[closing_cash.get("fiscal_year"), closing_debt.get("fiscal_year")],
        positions=[{"code": "C"}, {"code": "D"}],
        inputs={"cash": closing_cash["amount"], "overdraft": closing_debt["amount"], "net": str(net)},
        absolute=net,
        relative=None,
        method="net = Endbestand liquide Mittel (C) - Endbestand kurzfristige Finanzschulden (D)",
        fact_ids=[closing_cash["local_id"], closing_debt["local_id"]] + ((identity or {}).get("fact_ids") or []),
        relevance="hoch",
        priority=1,
        explanations=["Ein geplanter, noch nicht aufgenommener Schuldeneingang kann den Zahlungsmittelbestand vorübergehend negativ erscheinen lassen."],
        counterarguments=["Der Nachweis kann den Nettobestand an liquiden Mitteln anders abgrenzen als die Bruttobestände C und D."],
    )]


def _financing_gap_findings(facts: list[dict]) -> list[dict]:
    actual = _one(facts, code="35", value_kind="ist", scope="bereinigt")
    plan = _one(facts, code="35", value_kind="plan", scope="bereinigt", document_key=None if actual is None else actual.get("document_key"), fiscal_year=None if actual is None else actual.get("fiscal_year"))
    if actual is None or plan is None:
        return []
    deviation = Decimal(actual["amount"]) - Decimal(plan["amount"])
    if abs(deviation) < INVESTMENT_ABS:
        return []
    return [_finding(
        rule_code="financing_inflow_gap",
        title=f"Einzahlungen aus der Finanzierungstätigkeit {actual.get('fiscal_year')} weichen stark vom Plan ab",
        summary=(
            f"Geplant waren {plan.get('amount_original')} EUR, ausgewiesen sind {actual.get('amount_original')} EUR. "
            "Das kann bedeuten, dass eine veranschlagte Darlehensaufnahme nicht erfolgt ist. "
            "Ohne den Beschluss und den Nachtragsvoranschlag ist das nur ein Prüfhinweis."
        ),
        years=[actual.get("fiscal_year")],
        positions=[{"code": "35", "label": actual.get("original_label")}],
        inputs={"ist": actual["amount"], "plan": plan["amount"]},
        absolute=deviation,
        relative=None,
        method="absolute = Ist (35) - Plan (35) im selben Dokument",
        fact_ids=[actual["local_id"], plan["local_id"]],
        relevance="hoch",
        priority=2,
        explanations=["Die Aufnahme kann in ein späteres Jahr verschoben oder durch eine andere Finanzierung ersetzt worden sein."],
        counterarguments=["Der Planwert im Rechnungsabschluss muss nicht dem ersten Voranschlag entsprechen."],
    )]


def _one(facts: list[dict], **expected) -> dict | None:
    for fact in facts:
        if all(fact.get(key) == value for key, value in expected.items()):
            return fact
    return None


def _as_decimal(value) -> Decimal | None:
    if value is None or value == "":
        return None
    return Decimal(str(value))


def _finding(**kwargs) -> dict:
    absolute = kwargs.pop("absolute")
    relative = kwargs.pop("relative")
    return {
        "rule_code": kwargs["rule_code"],
        "title": kwargs["title"],
        "summary": kwargs["summary"],
        "years": [year for year in kwargs["years"] if year],
        "positions": kwargs["positions"],
        "inputs": kwargs["inputs"],
        "absolute_deviation": None if absolute is None else str(absolute),
        "relative_deviation": None if relative is None else str(relative),
        "method": kwargs["method"],
        "possible_explanations": kwargs["explanations"],
        "counterarguments": [item for item in kwargs["counterarguments"] if item],
        "relevance": kwargs["relevance"],
        "priority": kwargs["priority"],
        "data_quality": kwargs.get("data_quality", "aus_veroeffentlichten_tabellen"),
        "confidence": "mittel",
        "review_status": "unreviewed",
        "approval_status": "draft",
        "fact_ids": kwargs["fact_ids"],
        "disclaimer": "Prüfhinweis auf Grundlage veröffentlichter Zahlen. Kein Nachweis von Fehlverhalten.",
    }
