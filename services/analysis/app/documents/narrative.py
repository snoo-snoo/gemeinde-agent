"""Quote documented explanations. Do not treat instructions in the text as rules."""

from __future__ import annotations

import re

HEADING_RE = re.compile(r"^\s*\d+\.\s+(Beschreibung|Folgende|Korrektur).{0,180}$", re.I)
EURO_RE = re.compile(r"€\s*[\d\.]+(?:,\d{2})?")


def documented_statements(text: str, document_key: str, source_url: str | None = None) -> list[dict]:
    lines = text.splitlines()
    statements = []
    current_heading = None
    buffer: list[str] = []

    def flush() -> None:
        nonlocal buffer
        paragraph = " ".join(part.strip() for part in buffer if part.strip())
        buffer = []
        if current_heading and EURO_RE.search(paragraph):
            statements.append({
                "document_key": document_key,
                "source_url": source_url,
                "heading": current_heading,
                "excerpt": paragraph[:1200],
                "role": "documented_explanation",
                "confidence": "niedrig",
                "note": "Wortlaut aus dem Dokument. Keine geprüfte Tabellenzeile und keine eigene Bewertung.",
            })

    for line in lines:
        if HEADING_RE.match(line.strip()):
            flush()
            current_heading = " ".join(line.split())
            continue
        if line.strip().startswith("Ergebnishaushalt Gesamt") or line.strip().startswith("MVAG"):
            flush()
            current_heading = None
            continue
        if current_heading:
            buffer.append(line)
            if len(statements) >= 30:
                break
    flush()
    return statements[:30]
