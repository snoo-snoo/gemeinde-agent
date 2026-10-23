"""Deterministic consistency, plan/actual and multi-year rules.

Every result keeps the inputs, the formula and a status. A failed sum is a
calculation finding, not an allegation about conduct. Missing components stay
`incomplete` instead of being filled in.
"""

from __future__ import annotations

from decimal import Decimal

from app.finance.money import format_amount, period_change, plan_actual_deviation, within_tolerance

SUM_TOLERANCE = Decimal("0.05")
IDENTITY_TOLERANCE = Decimal("0.02")

SALDO_FORMULAS = (
    ("SA0", ("21", "22"), "subtract", "SA0 = Summe Erträge (21) - Summe Aufwendungen (22)"),
    ("SA1", ("31", "32"), "subtract", "SA1 = Summe operative Einzahlungen (31) - Summe operative Auszahlungen (32)"),
    ("SA2", ("33", "34"), "subtract", "SA2 = Summe investive Einzahlungen (33) - Summe investive Auszahlungen (34)"),
    ("SA3", ("SA1", "SA2"), "add", "SA3 = SA1 + SA2"),
    ("SA4", ("35", "36"), "subtract", "SA4 = Summe Finanzierungseinzahlungen (35) - Summe Finanzierungsauszahlungen (36)"),
    ("SA5", ("SA3", "SA4"), "add", "SA5 = SA3 + SA4"),
    ("SA6", ("41", "42"), "subtract", "SA6 = nicht voranschlagswirksame Einzahlungen (41) - Auszahlungen (42)"),
    ("SA7", ("SA5", "SA6"), "add", "SA7 = SA5 + SA6"),
    ("SA01", ("230", "240"), "subtract", "SA01 = Entnahmen Haushaltsrücklagen (230) - Zuweisungen (240)"),
    ("SA00", ("SA0", "SA01"), "add", "SA00 = SA0 + SA01"),
)

PARENTS = {
    "21": ("211", "212", "213"),
    "22": ("221", "222", "223", "224"),
    "31": ("311", "312", "313"),
    "32": ("321", "322", "323", "324"),
    "33": ("331", "332", "333", "334"),
    "34": ("341", "342", "343", "344", "345"),
}


def _decimal(fact: dict) -> Decimal:
    return Decimal(fact["amount"])


def _key(fact: dict) -> tuple:
    return (
        fact.get("document_key"),
        fact.get("statement"),
        fact.get("scope"),
        fact.get("fiscal_year"),
        fact.get("value_kind"),
        fact.get("code"),
    )


def _index(facts: list[dict]) -> dict[tuple, dict]:
    indexed: dict[tuple, dict] = {}
    for fact in facts:
        if fact.get("code") and fact.get("value_kind") != "reported_deviation":
            indexed.setdefault(_key(fact), fact)
    return indexed


def _lookup(indexed: dict, fact: dict, code: str) -> dict | None:
    return indexed.get((
        fact.get("document_key"),
        fact.get("statement"),
        fact.get("scope"),
        fact.get("fiscal_year"),
        fact.get("value_kind"),
        code,
    ))


def validate_facts(facts: list[dict]) -> list[dict]:
    results: list[dict] = []
    indexed = _index(facts)
    seen_parents: set[tuple] = set()
    seen_saldos: set[tuple] = set()
    for fact in facts:
        if fact.get("value_kind") not in {"ist", "plan", "mittelfrist"}:
            continue
        if fact.get("statement") not in {"ergebnis", "finanzierung"}:
            continue
        code = fact.get("code")
        if code in PARENTS:
            marker = _key(fact)
            if marker not in seen_parents:
                seen_parents.add(marker)
                results.append(_validate_parent(fact, indexed))
        if code in {item[0] for item in SALDO_FORMULAS}:
            marker = _key(fact)
            if marker not in seen_saldos:
                seen_saldos.add(marker)
                results.append(_validate_saldo(fact, indexed))
        if fact.get("value_kind") == "ist":
            results.append(_validate_reported_deviation(fact, facts))
    results.extend(_validate_plan_actual(facts))
    results.extend(_validate_liquidity(facts))
    results.extend(_validate_cross_document(facts))
    results.extend(_validate_nachweis_bridge(facts))
    return [item for item in results if item is not None]


def _base_result(rule: str, formula: str, status: str, message: str, inputs: dict, result: dict, facts: list[dict]) -> dict:
    return {
        "rule_code": rule,
        "formula": formula,
        "status": status,
        "message": message,
        "inputs": inputs,
        "result": result,
        "tolerance": str(SUM_TOLERANCE if "sum" in rule else IDENTITY_TOLERANCE),
        "fact_ids": [fact["local_id"] for fact in facts if fact.get("local_id")],
        "document_key": facts[0].get("document_key") if facts else None,
        "page": facts[0].get("page") if facts else None,
    }


def _validate_parent(parent: dict, indexed: dict) -> dict | None:
    expected = PARENTS.get(parent.get("code") or "")
    if not expected:
        return None
    children = []
    missing = []
    for code in expected:
        child = _lookup(indexed, parent, code)
        if child is None:
            missing.append(code)
        else:
            children.append(child)
    if not children:
        return None
    total = sum((_decimal(child) for child in children), Decimal("0.00"))
    inputs = {
        "parent": parent.get("code"),
        "parent_amount": parent.get("amount"),
        "children": {child["code"]: child["amount"] for child in children},
        "missing_children": missing,
        "value_kind": parent.get("value_kind"),
        "fiscal_year": parent.get("fiscal_year"),
        "scope": parent.get("scope"),
    }
    if missing:
        return _base_result(
            "group_sum",
            f"{parent.get('code')} = {' + '.join(expected)}",
            "incomplete",
            f"Gruppe {parent.get('code')}: nicht alle Untergruppen im Extrakt ({', '.join(missing)} fehlen).",
            inputs,
            {"computed_sum": str(total)},
            [parent, *children],
        )
    matches = within_tolerance(total, _decimal(parent), SUM_TOLERANCE)
    return _base_result(
        "group_sum",
        f"{parent.get('code')} = {' + '.join(expected)}",
        "passed" if matches else "failed",
        f"Gruppe {parent.get('code')} {'stimmt' if matches else 'stimmt nicht'} mit der Summe der Untergruppen überein.",
        inputs,
        {"computed_sum": str(total), "difference": str(total - _decimal(parent))},
        [parent, *children],
    )


def _validate_saldo(saldo: dict, indexed: dict) -> dict | None:
    formula = next(item for item in SALDO_FORMULAS if item[0] == saldo.get("code"))
    left = _lookup(indexed, saldo, formula[1][0])
    right = _lookup(indexed, saldo, formula[1][1])
    if left is None or right is None:
        return _base_result(
            "saldo_identity",
            formula[3],
            "incomplete",
            f"{formula[0]} kann nicht geprüft werden, weil eine Eingabe fehlt.",
            {"missing": [code for code, fact in zip(formula[1], (left, right)) if fact is None]},
            {},
            [saldo],
        )
    computed = _decimal(left) - _decimal(right) if formula[2] == "subtract" else _decimal(left) + _decimal(right)
    matches = within_tolerance(computed, _decimal(saldo), IDENTITY_TOLERANCE)
    return _base_result(
        "saldo_identity",
        formula[3],
        "passed" if matches else "failed",
        f"{formula[0]} {'entspricht' if matches else 'entspricht nicht'} der ausgewiesenen Formel.",
        {"left": left["amount"], "right": right["amount"], "reported": saldo["amount"]},
        {"computed": str(computed), "difference": str(computed - _decimal(saldo))},
        [saldo, left, right],
    )


def _validate_reported_deviation(actual: dict, facts: list[dict]) -> dict | None:
    plan = _match(facts, actual, value_kind="plan")
    reported = _match(facts, actual, value_kind="reported_deviation")
    if plan is None or reported is None:
        return None
    deviation = plan_actual_deviation(_decimal(plan), _decimal(actual))
    if deviation.absolute is None:
        return None
    matches = within_tolerance(deviation.absolute, _decimal(reported), IDENTITY_TOLERANCE)
    return _base_result(
        "reported_deviation",
        "ausgewiesene Differenz RA-VA = Ist - Plan",
        "passed" if matches else "failed",
        f"Ausgewiesene Differenz bei {actual.get('code')} {'entspricht' if matches else 'entspricht nicht'} Ist minus Plan.",
        {"ist": actual["amount"], "plan": plan["amount"], "reported": reported["amount"]},
        deviation.as_dict(),
        [actual, plan, reported],
    )


def _validate_plan_actual(facts: list[dict]) -> list[dict]:
    results = []
    seen = set()
    for actual in facts:
        if actual.get("value_kind") != "ist" or actual.get("statement") not in {"ergebnis", "finanzierung", "nachweis"}:
            continue
        plan = _match(facts, actual, value_kind="plan", same_document=True)
        if plan is None:
            continue
        marker = (actual.get("document_key"), actual.get("scope"), actual.get("code"), actual.get("fiscal_year"), actual.get("statement"))
        if marker in seen:
            continue
        seen.add(marker)
        deviation = plan_actual_deviation(_decimal(plan), _decimal(actual))
        results.append(_base_result(
            "plan_actual",
            deviation.formula,
            deviation.status if deviation.status != "ok" else "passed",
            _plan_actual_message(actual, deviation),
            {"ist": actual["amount"], "plan": plan["amount"], "code": actual.get("code"), "year": actual.get("fiscal_year"), "scope": actual.get("scope")},
            deviation.as_dict(),
            [actual, plan],
        ))
    return results


def _plan_actual_message(actual: dict, deviation) -> str:
    label = actual.get("original_label") or actual.get("code")
    if deviation.status == "percent_not_meaningful":
        return (
            f"{label} ({actual.get('fiscal_year')}): absolute Abweichung {deviation.absolute} EUR. "
            "Eine Prozentabweichung wird nicht ausgewiesen, weil der Planwert null oder negativ ist."
        )
    return (
        f"{label} ({actual.get('fiscal_year')}): Ist {actual.get('amount_original')} gegenüber Plan, "
        f"absolute Abweichung {format_amount(deviation.absolute)} EUR"
        + (f", relative Abweichung {deviation.relative_percent} %." if deviation.relative_percent is not None else ".")
    )


def _validate_liquidity(facts: list[dict]) -> list[dict]:
    opening = _single(facts, code="A", statement="liquiditaet")
    opening_debt = _single(facts, code="B", statement="liquiditaet")
    closing = _single(facts, code="C", statement="liquiditaet")
    closing_debt = _single(facts, code="D", statement="liquiditaet")
    if not all([opening, closing, closing_debt]):
        return [_base_result(
            "liquidity_identity",
            "(Endbestand liquide Mittel - Endbestand Kontokorrent) - (Anfangsbestand liquide Mittel - Anfangsbestand Kontokorrent) = SA7",
            "incomplete",
            "Die Verprobung der liquiden Mittel ist unvollständig.",
            {},
            {},
            [item for item in (opening, opening_debt, closing, closing_debt) if item],
        )]
    opening_debt_amount = _decimal(opening_debt) if opening_debt else None
    if opening_debt_amount is None:
        return []
    computed = (_decimal(closing) - _decimal(closing_debt)) - (_decimal(opening) - opening_debt_amount)
    sa7_candidates = [
        fact for fact in facts
        if fact.get("code") == "SA7" and fact.get("value_kind") == "ist" and fact.get("fiscal_year") == closing.get("fiscal_year")
        and fact.get("scope") in {"bereinigt", "nachweis"}
    ]
    # Prefer the financing-statement saldo over the nachweis if both exist.
    sa7 = next((fact for fact in sa7_candidates if fact.get("statement") == "finanzierung"), None)
    if sa7 is None and sa7_candidates:
        sa7 = sa7_candidates[0]
    if sa7 is None:
        return [_base_result(
            "liquidity_identity",
            "(C - D) - (A - B) = SA7",
            "incomplete",
            "SA7 für das Endjahr der Liquiditätsverprobung fehlt.",
            {"computed": str(computed)},
            {},
            [opening, opening_debt, closing, closing_debt],
        )]
    matches = within_tolerance(computed, _decimal(sa7), IDENTITY_TOLERANCE)
    return [_base_result(
        "liquidity_identity",
        "(C - D) - (A - B) = SA7",
        "passed" if matches else "failed",
        "Die Veränderung der liquiden Mittel " + ("stimmt mit SA7 überein." if matches else "weicht von SA7 ab."),
        {
            "anfangsbestand": opening["amount"],
            "anfangsbestand_kontokorrent": opening_debt["amount"],
            "endbestand": closing["amount"],
            "endbestand_kontokorrent": closing_debt["amount"],
            "sa7": sa7["amount"],
        },
        {"computed": str(computed), "difference": str(computed - _decimal(sa7))},
        [opening, opening_debt, closing, closing_debt, sa7],
    )]


def _validate_cross_document(facts: list[dict]) -> list[dict]:
    grouped: dict[tuple, list[dict]] = {}
    for fact in facts:
        if fact.get("value_kind") not in {"ist", "plan"}:
            continue
        if fact.get("scope") not in {"bereinigt", "nachweis"}:
            continue
        if not fact.get("code") or fact.get("fiscal_year") is None:
            continue
        grouped.setdefault((fact["code"], fact["scope"], fact["fiscal_year"], fact["value_kind"], fact.get("statement")), []).append(fact)
    results = []
    for key, group in grouped.items():
        documents = {fact.get("document_key") for fact in group}
        if len(documents) < 2:
            continue
        amounts = {Decimal(fact["amount"]) for fact in group}
        status = "passed" if len(amounts) == 1 else "failed"
        results.append(_base_result(
            "cross_document_consistency",
            "Gleicher Code, gleiches Jahr, gleiche Wertart und gleicher Ausweisumfang müssen zwischen Dokumenten übereinstimmen.",
            status,
            f"{key[0]} {key[3]} {key[2]} ({key[1]}) ist zwischen den Dokumenten {'gleich' if status == 'passed' else 'nicht gleich'}.",
            {
                "code": key[0],
                "scope": key[1],
                "fiscal_year": key[2],
                "value_kind": key[3],
                "statement": key[4],
                "amounts": [fact["amount"] for fact in group],
                "documents": sorted(str(item) for item in documents),
                "labels": [fact["original_label"] for fact in group],
            },
            {"distinct_amounts": [str(amount) for amount in sorted(amounts)]},
            group,
        ))
    return results


def _validate_nachweis_bridge(facts: list[dict]) -> list[dict]:
    """Compare headline saldos in the Nachweis with the same saldo in the Gesamtanlage."""
    results = []
    for code in ("SA0", "SA5"):
        for year in sorted({fact.get("fiscal_year") for fact in facts if fact.get("code") == code and fact.get("fiscal_year") is not None}):
            gesamt = [
                fact for fact in facts
                if fact.get("code") == code and fact.get("fiscal_year") == year and fact.get("value_kind") == "ist"
                and fact.get("scope") == "bereinigt" and fact.get("statement") in {"ergebnis", "finanzierung"}
            ]
            nachweis = [
                fact for fact in facts
                if fact.get("code") == code and fact.get("fiscal_year") == year and fact.get("value_kind") == "ist"
                and fact.get("scope") == "nachweis"
            ]
            if not gesamt or not nachweis:
                continue
            left, right = gesamt[0], nachweis[0]
            matches = within_tolerance(_decimal(left), _decimal(right), IDENTITY_TOLERANCE)
            results.append(_base_result(
                "nachweis_bridge",
                f"Nachweis {code} = bereinigte Gesamtanlage {code}",
                "passed" if matches else "failed",
                f"{code} {year} im Nachweis {'entspricht' if matches else 'entspricht nicht'} der bereinigten Gesamtanlage.",
                {"gesamt": left["amount"], "nachweis": right["amount"]},
                {"difference": str(_decimal(left) - _decimal(right))},
                [left, right],
            ))
    return results


def _match(facts: list[dict], actual: dict, value_kind: str, same_document: bool = True) -> dict | None:
    for fact in facts:
        if fact.get("value_kind") != value_kind:
            continue
        if fact.get("code") != actual.get("code"):
            continue
        if fact.get("scope") != actual.get("scope"):
            continue
        if fact.get("statement") != actual.get("statement"):
            continue
        if fact.get("fiscal_year") != actual.get("fiscal_year"):
            continue
        if same_document and fact.get("document_key") != actual.get("document_key"):
            continue
        return fact
    return None


def _single(facts: list[dict], **expected) -> dict | None:
    matches = [fact for fact in facts if all(fact.get(key) == value for key, value in expected.items())]
    return matches[0] if matches else None


def debt_rollforward(opening: Decimal | None, increases: Decimal | None, decreases: Decimal | None, closing: Decimal | None) -> dict:
    """Endbestand = Anfangsbestand + Zugänge - Abgänge, when every component is present."""
    formula = "Endbestand = Anfangsbestand + Zugänge - Abgänge"
    components = {"opening": opening, "increases": increases, "decreases": decreases, "closing": closing}
    if any(value is None for value in components.values()):
        return {"status": "incomplete", "formula": formula, "message": "Mindestens ein Schuldenbestand fehlt. Es wird kein Bestand fortgeschrieben.", "inputs": {key: None if value is None else str(value) for key, value in components.items()}}
    computed = opening + increases - decreases
    matches = within_tolerance(computed, closing, SUM_TOLERANCE)
    return {
        "status": "passed" if matches else "failed",
        "formula": formula,
        "computed": str(computed),
        "difference": str(computed - closing),
        "inputs": {key: str(value) for key, value in components.items()},
        "message": "Schuldenfortschreibung " + ("stimmt." if matches else "weicht ab."),
    }


def recurring_deviations(pairs: list[dict]) -> dict:
    """pairs: [{year, plan, actual}] for one account. At least two signed deviations are required."""
    usable = []
    for pair in pairs:
        if pair.get("plan") is None or pair.get("actual") is None:
            continue
        deviation = plan_actual_deviation(Decimal(pair["plan"]), Decimal(pair["actual"]))
        if deviation.absolute is None:
            continue
        usable.append({"year": pair.get("year"), "absolute": deviation.absolute, "status": deviation.status})
    if len(usable) < 2:
        return {"status": "insufficient_years", "message": "Für eine wiederkehrende Planabweichung liegen weniger als zwei vergleichbare Jahre vor.", "years": [item["year"] for item in usable]}
    signs = {1 if item["absolute"] > 0 else -1 if item["absolute"] < 0 else 0 for item in usable}
    signs.discard(0)
    recurring = len(signs) == 1
    return {
        "status": "recurring" if recurring else "not_recurring",
        "direction": "ueber_plan" if recurring and next(iter(signs)) > 0 else "unter_plan" if recurring else "gemischt",
        "years": [item["year"] for item in usable],
        "absolute": [str(item["absolute"]) for item in usable],
        "message": "Die Abweichung hat über die vorliegenden Jahre dasselbe Vorzeichen." if recurring else "Die Abweichungen wechseln die Richtung oder sind nicht durchgängig.",
    }


def historical_comparisons(facts: list[dict]) -> list[dict]:
    grouped: dict[tuple, list[dict]] = {}
    for fact in facts:
        if fact.get("value_kind") not in {"ist", "plan"} or not fact.get("code"):
            continue
        if fact.get("scope") not in {"bereinigt", "nachweis"}:
            continue
        grouped.setdefault((fact["code"], fact["scope"], fact["value_kind"], fact.get("statement")), []).append(fact)
    comparisons = []
    for key, group in grouped.items():
        by_year: dict[int, list[dict]] = {}
        for fact in group:
            if fact.get("fiscal_year") is None:
                continue
            by_year.setdefault(int(fact["fiscal_year"]), []).append(fact)
        usable = {}
        for year, year_facts in by_year.items():
            amounts = {fact["amount"] for fact in year_facts}
            if len(amounts) > 1:
                comparisons.append({
                    "code": key[0],
                    "scope": key[1],
                    "value_kind": key[2],
                    "statement": key[3],
                    "base_year": year,
                    "comparison_year": year,
                    "comparable": False,
                    "status": "not_comparable",
                    "note": "Für dieses Jahr liegen widersprüchliche Beträge aus mehreren Dokumenten vor. Es wird kein Mittelwert gebildet.",
                    "fact_ids": [fact["local_id"] for fact in year_facts],
                })
                continue
            usable[year] = year_facts[0]
        years = sorted(usable)
        for base_year, comparison_year in zip(years, years[1:]):
            base = usable[base_year]
            comparison = usable[comparison_year]
            change = period_change(_decimal(base), _decimal(comparison))
            same_document = base.get("document_key") == comparison.get("document_key")
            comparisons.append({
                "code": key[0],
                "label": comparison.get("original_label"),
                "scope": key[1],
                "value_kind": key[2],
                "statement": key[3],
                "base_year": base_year,
                "comparison_year": comparison_year,
                "base_amount": base["amount"],
                "comparison_amount": comparison["amount"],
                "absolute_change": None if change.absolute is None else str(change.absolute),
                "relative_change": None if change.relative_percent is None else str(change.relative_percent),
                "status": change.status,
                "reason": change.reason,
                "formula": change.formula,
                "comparable": True,
                "same_document": same_document,
                "note": None if same_document else "Die beiden Jahreswerte stammen aus unterschiedlichen Dokumenten und sind nur bei gleicher Kontenabgrenzung vergleichbar.",
                "fact_ids": [base["local_id"], comparison["local_id"]],
            })
    return comparisons


def investment_comparisons(facts: list[dict]) -> list[dict]:
    """Compare investment outflows (MVAG 34) for one year.

    Original budget and the budget column printed in the annual statement are
    kept apart. A supplementary budget can explain the difference; this rule
    does not decide that question.
    """
    results = []
    actuals = [fact for fact in facts if fact.get("code") == "34" and fact.get("value_kind") == "ist" and fact.get("scope") == "bereinigt"]
    for actual in actuals:
        plans = [
            fact for fact in facts
            if fact.get("code") == "34" and fact.get("value_kind") == "plan" and fact.get("scope") == "bereinigt"
            and fact.get("fiscal_year") == actual.get("fiscal_year")
        ]
        for plan in plans:
            deviation = plan_actual_deviation(_decimal(plan), _decimal(actual))
            same_document = plan.get("document_key") == actual.get("document_key")
            results.append({
                "code": "34",
                "fiscal_year": actual.get("fiscal_year"),
                "actual": actual["amount"],
                "plan": plan["amount"],
                "plan_origin": "same_document" if same_document else "other_document",
                "plan_document": plan.get("document_key"),
                "actual_document": actual.get("document_key"),
                "deviation": deviation.as_dict(),
                "boundary_note": "Projektverschiebungen und Nachtragsbudgets sind hier nicht aufgelöst. Der Vergleich betrifft die Gruppe 34 der bereinigten Gesamtanlage.",
                "fact_ids": [actual["local_id"], plan["local_id"]],
            })
    return results
