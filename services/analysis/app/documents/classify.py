"""Rule-based classification of Austrian municipal finance publications."""

from __future__ import annotations

import re
from urllib.parse import urlparse

DOCUMENT_RULES: tuple[tuple[str, re.Pattern[str]], ...] = (
    ("nachtragsvoranschlag", re.compile(r"nachtragsvoranschl[aä]g", re.I)),
    ("rechnungsabschluss", re.compile(r"rechnungsabschluss", re.I)),
    ("finanzplan", re.compile(r"mittelfristiger\s+finanzplan|finanzplan", re.I)),
    ("voranschlag", re.compile(r"voranschl[aä]g", re.I)),
    ("vermoegen_schulden", re.compile(r"verm[oö]gens|schulden[uü]bersicht", re.I)),
    ("pruefbericht", re.compile(r"pr[uü]fbericht", re.I)),
    ("protokoll", re.compile(r"protokoll|gemeinderatsbeschluss", re.I)),
    ("beteiligungsbericht", re.compile(r"beteiligung", re.I)),
    ("investition", re.compile(r"investition|beschaffung", re.I)),
    ("gebuehren", re.compile(r"geb[uü]hr", re.I)),
)

YEAR_RE = re.compile(r"(20\d{2})")
ORDINAL_RE = re.compile(r"(\d+)\.\s*nachtragsvoranschlag", re.I)


def classify_title(title: str, url: str = "") -> dict:
    """Classify from the visible link title. Page instructions are not rules."""
    clean = " ".join((title or "").split())
    document_type = "unbekannt"
    for name, pattern in DOCUMENT_RULES:
        if pattern.search(clean):
            document_type = name
            break
    year_match = YEAR_RE.search(clean)
    ordinal_match = ORDINAL_RE.search(clean)
    path = urlparse(url).path.lower()
    relevant = document_type != "unbekannt" or "getdocument" in path
    return {
        "title": clean,
        "document_type": document_type,
        "fiscal_year": int(year_match.group(1)) if year_match else None,
        "supplement_ordinal": int(ordinal_match.group(1)) if ordinal_match else None,
        "relevant": relevant,
    }
