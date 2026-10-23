"""Evidence review checks findings against stored facts.

Agents are not allowed to mark their own output as independently verified.
This reviewer only sets `evidence_checked`, `rejected` or `needs_manual_review`.
Human approval stays `draft`.
"""

from __future__ import annotations

from decimal import Decimal, InvalidOperation


def review_findings(findings: list[dict], facts: list[dict]) -> list[dict]:
    by_id = {fact["local_id"]: fact for fact in facts if fact.get("local_id")}
    reviewed = []
    for finding in findings:
        updated = dict(finding)
        updated["approval_status"] = "draft"
        fact_ids = list(updated.get("fact_ids") or [])
        missing = [fact_id for fact_id in fact_ids if fact_id not in by_id]
        cited_amounts = {by_id[fact_id]["amount"] for fact_id in fact_ids if fact_id in by_id}
        claimed = _claimed_amounts(updated.get("inputs") or {})
        unknown_claims = sorted(amount for amount in claimed if amount not in cited_amounts)
        if not fact_ids or missing:
            updated["review_status"] = "needs_manual_review"
            updated["review_note"] = "Nicht jeder Beleg verweist auf einen extrahierten Fakt."
        elif unknown_claims:
            updated["review_status"] = "rejected"
            updated["review_note"] = "Die Eingabewerte kommen in den verknüpften Fakten nicht vor: " + ", ".join(unknown_claims)
        else:
            updated["review_status"] = "evidence_checked"
            updated["review_note"] = "Beträge stimmen mit den verknüpften extrahierten Fakten überein. Das ist keine inhaltliche Freigabe."
        updated["evidence"] = [
            {
                "local_fact_id": fact_id,
                "role": "supports" if updated["review_status"] == "evidence_checked" else "context",
                "excerpt": by_id[fact_id].get("excerpt"),
                "source_url": by_id[fact_id].get("source_url"),
                "page": by_id[fact_id].get("page"),
                "amount": by_id[fact_id].get("amount"),
            }
            for fact_id in fact_ids
            if fact_id in by_id
        ]
        reviewed.append(updated)
    return reviewed


def _claimed_amounts(inputs: dict) -> set[str]:
    found: set[str] = set()

    def walk(value) -> None:
        if isinstance(value, dict):
            for item in value.values():
                walk(item)
        elif isinstance(value, list):
            for item in value:
                walk(item)
        elif isinstance(value, str):
            try:
                amount = Decimal(value)
            except InvalidOperation:
                return
            if "." in value:
                found.add(str(amount))

    walk(inputs)
    return found
