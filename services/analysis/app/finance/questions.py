"""Questions are filled only with amounts that already exist on the finding."""

from __future__ import annotations

AUDIENCE_BY_RULE = {
    "material_plan_actual": "finance",
    "investment_underspend": "mayor",
    "cross_document_mismatch": "audit_committee",
    "calculation_break": "finance",
    "net_liquidity_negative": "finance",
    "financing_inflow_gap": "council",
}


def questions_from_findings(findings: list[dict]) -> list[dict]:
    questions = []
    for finding in findings:
        if finding.get("review_status") == "rejected":
            continue
        audience = AUDIENCE_BY_RULE.get(finding.get("rule_code"), "council")
        body = _body(finding)
        if not body:
            continue
        questions.append({
            "audience": audience,
            "topic": finding.get("rule_code"),
            "body": body,
            "priority": finding.get("priority", 3),
            "status": "open",
            "finding_rule": finding.get("rule_code"),
            "fact_ids": finding.get("fact_ids") or [],
        })
    return questions


def _body(finding: dict) -> str | None:
    inputs = finding.get("inputs") or {}
    rule = finding.get("rule_code")
    if rule == "investment_underspend":
        return (
            f"Die tatsächlichen investiven Auszahlungen der Gruppe 34 betragen {inputs.get('actual')} EUR, "
            f"der herangezogene Planwert {inputs.get('plan')} EUR. Welche Projekte wurden nicht wie geplant umgesetzt, "
            "welche Beträge wurden in Folgejahre verschoben und welche Auswirkungen ergeben sich für die mittelfristige Finanzplanung?"
        )
    if rule == "cross_document_mismatch":
        amounts = inputs.get("amounts") or []
        shown = " und ".join(str(amount) for amount in amounts)
        return (
            f"Für dieselbe bezeichnete Position sind die Beträge {shown} EUR in unterschiedlichen Veröffentlichungen ausgewiesen. "
            "Welcher Wert ist der ursprüngliche Voranschlag, welcher der letzte Nachtragsvoranschlag, und welche Spalte des Rechnungsabschlusses ist damit gemeint?"
        )
    if rule == "net_liquidity_negative":
        return (
            f"Die liquiden Mittel sind mit {inputs.get('cash')} EUR und die kurzfristigen Finanzschulden aus überzogenen Konten "
            f"mit {inputs.get('overdraft')} EUR ausgewiesen. Wie ist der negative Nettosaldo von {inputs.get('net')} EUR entstanden, "
            "welche Konten sind betroffen und welcher Ausgleich ist im laufenden Haushaltsjahr vorgesehen?"
        )
    if rule == "financing_inflow_gap":
        return (
            f"Bei den Einzahlungen aus der Finanzierungstätigkeit stehen einem Plan von {inputs.get('plan')} EUR "
            f"Istwerte von {inputs.get('ist')} EUR gegenüber. Welche geplanten Darlehensaufnahmen sind nicht erfolgt, "
            "und wie wird der daraus folgende Zahlungsmittelbedarf gedeckt?"
        )
    if rule == "material_plan_actual":
        return (
            f"Bei Position {inputs.get('code')} im Jahr {inputs.get('year')} beträgt der Istwert {inputs.get('ist')} EUR "
            f"und der Planwert {inputs.get('plan')} EUR. Welche sachlichen Gründe tragen diese Abweichung, "
            "und welche davon waren zum Zeitpunkt des Voranschlags bereits absehbar?"
        )
    if rule == "calculation_break":
        return (
            "Eine rechnerische Identität der veröffentlichten Tabelle konnte mit den extrahierten Werten nicht nachvollzogen werden. "
            "Welche Fundstelle ist maßgeblich, und handelt es sich um einen Darstellungs-, Rundungs- oder Extraktionsunterschied?"
        )
    return None
