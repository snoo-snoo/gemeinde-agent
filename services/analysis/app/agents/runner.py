"""One agent, one input, one output.

The runner does not pretend that a role prompt is a separate agent. Each key
calls its own function. Narrative agents return deterministic text; a language
model may later rephrase it in Laravel, but it cannot replace the figures.
"""

from __future__ import annotations

from app.documents.narrative import documented_statements
from app.documents.pdf_text import extract_pdf_text
from app.documents.vrv import parse_vrv_text
from app.finance.anomalies import build_findings
from app.finance.evidence import review_findings
from app.finance.questions import questions_from_findings
from app.finance.reports import build_reports
from app.finance.rules import historical_comparisons, investment_comparisons, validate_facts
from app.scraper.discover import discover_from_html, discover_url
from app.scraper.fetch import PublicFetcher

DATA_GAPS = [
    "Schuldenanfangsbestand, Zugänge, Abgänge und Endbestand wurden nicht gemeinsam als geprüfte Tabelle extrahiert.",
    "Nicht heruntergeladene, nur entdeckte Dokumente bleiben eine Datenlücke.",
]


def run_agent(agent_key: str, context: dict, fetcher: PublicFetcher | None = None) -> dict:
    handler = HANDLERS.get(agent_key)
    if handler is None:
        return {"agent_key": agent_key, "status": "failed", "warnings": ["unknown agent"], "output": {}}
    try:
        output = handler(context, fetcher)
    except Exception as exc:  # noqa: BLE001 - returned to the orchestrator, not swallowed silently
        return {"agent_key": agent_key, "status": "failed", "warnings": [type(exc).__name__], "output": {}, "error": str(exc)[:500]}
    return {"agent_key": agent_key, "status": "completed", "warnings": output.pop("warnings", []), "output": output}


def _discovery(context: dict, fetcher: PublicFetcher | None) -> dict:
    if context.get("html") is not None:
        return discover_from_html(
            context["url"],
            context.get("html") or "",
            context.get("robots_text") or "",
            context.get("allowed_hosts"),
        )
    if fetcher is None:
        raise RuntimeError("discovery requires html or a fetcher")
    return discover_url(context["url"], fetcher, context.get("allowed_hosts"))


def _classify(context: dict, fetcher: PublicFetcher | None) -> dict:
    from app.documents.classify import classify_title

    return {"classification": classify_title(context.get("title") or "", context.get("url") or "")}


def _extract(context: dict, fetcher: PublicFetcher | None) -> dict:
    text = context.get("text")
    method = "supplied-text"
    warnings = []
    if not text and context.get("path"):
        extracted = extract_pdf_text(context["path"] if hasattr(context["path"], "exists") else __import__("pathlib").Path(context["path"]), allow_ocr=bool(context.get("allow_ocr")))
        text = extracted.get("text") or ""
        method = extracted.get("method") or method
        if extracted.get("status") != "completed":
            warnings.append(extracted.get("error") or "Extraktion unvollständig")
            return {"facts": [], "warnings": warnings, "method": method, "fact_count": 0, "data_gaps": [extracted.get("error") or "kein Text"]}
    parsed = parse_vrv_text(
        text or "",
        document_key=context.get("document_key") or "document",
        source_url=context.get("source_url"),
        method=method if method != "supplied-text" else "vrv2015-layout-v1",
    )
    if not parsed["facts"]:
        warnings.append("Keine VRV-Gesamttabelle erkannt.")
    parsed["statements"] = documented_statements(text or "", context.get("document_key") or "document", context.get("source_url"))
    parsed["warnings"] = warnings + parsed.get("warnings", [])
    parsed["data_gaps"] = [] if parsed["facts"] else ["Keine Finanzzeilen im erwarteten VRV-2015-Gesamtlayout gefunden."]
    return parsed


def _validate(context: dict, fetcher: PublicFetcher | None) -> dict:
    facts = context.get("facts") or []
    validations = validate_facts(facts)
    return {"validations": validations, "warnings": []}


def _compare(context: dict, fetcher: PublicFetcher | None) -> dict:
    facts = context.get("facts") or []
    return {
        "comparisons": historical_comparisons(facts),
        "investments": investment_comparisons(facts),
        "warnings": [],
    }


def _anomalies(context: dict, fetcher: PublicFetcher | None) -> dict:
    facts = context.get("facts") or []
    validations = context.get("validations") or validate_facts(facts)
    investments = context.get("investments") or investment_comparisons(facts)
    findings = build_findings(facts, validations, investments)
    return {"findings": findings, "warnings": []}


def _cfo(context: dict, fetcher: PublicFetcher | None) -> dict:
    reports = build_reports({**context, "findings": context.get("findings") or [], "questions": context.get("questions") or []})
    report = next(item for item in reports if item["report_type"] == "cfo")
    return {"report": report, "scenarios_are_mechanical": True, "warnings": []}


def _administration(context: dict, fetcher: PublicFetcher | None) -> dict:
    if context.get("statements"):
        return {"statements": context["statements"], "warnings": []}
    statements = []
    for item in context.get("texts") or []:
        statements.extend(documented_statements(item.get("text") or "", item.get("document_key") or "document", item.get("source_url")))
    if not statements:
        return {"statements": [], "data_gaps": ["Im gelesenen Text wurde kein nummerierter Beschreibungsabschnitt mit Beträgen gefunden."], "warnings": []}
    return {"statements": statements, "warnings": []}


def _opposition(context: dict, fetcher: PublicFetcher | None) -> dict:
    findings = context.get("findings") or []
    return {"questions": questions_from_findings(findings), "warnings": []}


def _citizen(context: dict, fetcher: PublicFetcher | None) -> dict:
    reports = build_reports(context)
    report = next(item for item in reports if item["report_type"] == "citizen")
    return {"report": report, "warnings": []}


def _evidence(context: dict, fetcher: PublicFetcher | None) -> dict:
    reviewed = review_findings(context.get("findings") or [], context.get("facts") or [])
    return {"findings": reviewed, "warnings": []}


def _reporting(context: dict, fetcher: PublicFetcher | None) -> dict:
    payload = dict(context)
    payload.setdefault("data_gaps", DATA_GAPS)
    reports = build_reports(payload)
    return {"reports": reports, "warnings": []}


HANDLERS = {
    "source_discovery": _discovery,
    "document_classification": _classify,
    "financial_extraction": _extract,
    "accounting_validation": _validate,
    "historical_comparison": _compare,
    "anomaly_detection": _anomalies,
    "cfo_sustainability": _cfo,
    "administration_context": _administration,
    "opposition_accountability": _opposition,
    "citizen": _citizen,
    "evidence_review": _evidence,
    "reporting": _reporting,
}
