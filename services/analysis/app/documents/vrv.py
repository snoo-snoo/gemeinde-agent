"""Parse VRV 2015 statement text produced by `pdftotext -layout`.

The parser maps amounts to columns by horizontal position. It does not trust a
left-to-right zip of numbers, because blank plan or actual cells are common in
the household-equilibrium statement (Nachweis).

Only the municipal Gesamtanlage and the Nachweis are parsed. Bereichsbudgets
and detail listings are skipped so that departmental figures are not added to
the municipal total a second time.
"""

from __future__ import annotations

import re
from decimal import Decimal

from app.finance.money import AMOUNT_RE, parse_amount

COLUMN_RE = re.compile(
    r"RA\s*-\s*VA|(?:RA|VA|Plan|Voranschlag)\s+20\d{2}",
    re.I,
)
CODE_RE = re.compile(
    r"^(?P<code>SA\d{1,2}|\d{2,4}|[ABCD])(?:\s+(?P<footnote>\d))?\s+(?P<rest>.+)$"
)
PAGE_RE = re.compile(r"Seite\s+(\d+)\s*$")
YEAR_IN_LABEL_RE = re.compile(r"31\.12\.(20\d{2})")

MAX_COLUMN_DISTANCE = 15


def _nature(code: str | None, statement: str) -> str:
    if statement == "liquiditaet":
        return "bestand"
    if not code:
        return "kennzahl"
    if code.startswith("SA"):
        return "saldo"
    if statement == "ergebnis":
        if code.startswith("21"):
            return "ertrag"
        if code.startswith("22"):
            return "aufwand"
        if code.startswith("23"):
            return "ruecklage_entnahme"
        if code.startswith("24"):
            return "ruecklage_zuweisung"
    if statement == "finanzierung":
        if code[:2] in {"31", "33", "35", "41"}:
            return "einzahlung"
        if code[:2] in {"32", "34", "36", "42"}:
            return "auszahlung"
    return "kennzahl"


def _column_kind(token: str, deviation_year: int | None) -> tuple[str, int | None]:
    compact = re.sub(r"\s+", "", token).upper().replace("–", "-")
    if compact == "RA-VA":
        return "reported_deviation", deviation_year
    match = re.match(r"(RA|VA|PLAN|VORANSCHLAG)\s*(20\d{2})", token.strip(), re.I)
    if not match:
        return "unbekannt", None
    kind_token, year_text = match.group(1).upper(), int(match.group(2))
    if kind_token == "RA":
        return "ist", year_text
    if kind_token in {"VA", "VORANSCHLAG"}:
        return "plan", year_text
    return "mittelfrist", year_text


def _columns_from_header(line: str) -> list[dict]:
    found = list(COLUMN_RE.finditer(line))
    if len(found) < 2:
        return []
    ra_years = [int(item) for item in re.findall(r"RA\s+(20\d{2})", line, re.I)]
    deviation_year = ra_years[-1] if ra_years else None
    columns = []
    for match in found:
        kind, year = _column_kind(match.group(0), deviation_year)
        columns.append({"token": match.group(0), "start": match.start(), "value_kind": kind, "year": year})
    return columns


def _assign(columns: list[dict], line: str) -> list[tuple[dict, str, int]]:
    assigned: list[tuple[dict, str, int]] = []
    used: set[int] = set()
    for match in AMOUNT_RE.finditer(line):
        best_index = None
        best_distance = MAX_COLUMN_DISTANCE + 1
        for index, column in enumerate(columns):
            distance = abs(match.start() - column["start"])
            if distance < best_distance and index not in used:
                best_distance = distance
                best_index = index
        if best_index is None or best_distance > MAX_COLUMN_DISTANCE:
            continue
        used.add(best_index)
        assigned.append((columns[best_index], match.group(0), best_distance))
    return assigned


def _clean_label(text: str) -> str:
    without_amounts = AMOUNT_RE.sub(" ", text)
    return " ".join(without_amounts.split())


class VrvParser:
    def __init__(self, document_key: str, source_url: str | None = None, method: str = "vrv2015-layout-v1"):
        self.document_key = document_key
        self.source_url = source_url
        self.method = method
        self.facts: list[dict] = []
        self.warnings: list[str] = []
        self._seq = 0
        self._page: int | None = None
        self._pending_page_facts: list[dict] = []

    def parse(self, text: str) -> dict:
        mode = None
        statement = None
        scope = None
        columns: list[dict] = []
        for raw_line in text.splitlines():
            page_match = PAGE_RE.search(raw_line.strip())
            if page_match and "Erstellt am" in raw_line:
                self._flush_page(int(page_match.group(1)))
                continue
            stripped = raw_line.strip()
            if not stripped or stripped.startswith("#"):
                continue
            if stripped.startswith("Ergebnishaushalt Bereichsbudget") or stripped.startswith("Finanzierungshaushalt Bereichsbudget"):
                mode = "skip"
                columns = []
                continue
            if stripped.startswith("Ergebnis- und Finanzierungsrechnung Detailnachweis"):
                mode = "skip"
                columns = []
                continue
            if "Nachweis über das nachhaltige Haushaltsgleichgewicht" in stripped and "Erstellt am" not in stripped:
                mode = "nachweis"
                statement = "nachweis"
                scope = "nachweis"
                columns = []
                continue
            if "Ergebnishaushalt Gesamt" in stripped:
                mode = "gesamt"
                statement = "ergebnis"
                scope = _scope_from_line(stripped, scope)
                continue
            if "Finanzierungshaushalt Gesamt" in stripped:
                mode = "gesamt"
                statement = "finanzierung"
                scope = _scope_from_line(stripped, scope)
                continue
            if stripped.startswith("- bereinigt"):
                scope = "bereinigt"
                continue
            if "interne Vergütungen enthalten" in stripped:
                scope = "inklusive_interne"
                continue
            if mode == "skip" or mode is None:
                continue
            header = _columns_from_header(raw_line)
            if header and not AMOUNT_RE.search(raw_line):
                columns = header
                continue
            if not columns:
                continue
            if mode == "gesamt":
                self._parse_gesamt_line(raw_line, statement, scope or "unbekannt", columns)
            elif mode == "nachweis":
                self._parse_nachweis_line(raw_line, columns)
        self._flush_page(self._page)
        return {
            "document_key": self.document_key,
            "source_url": self.source_url,
            "method": self.method,
            "fact_count": len(self.facts),
            "facts": self.facts,
            "warnings": self.warnings,
        }

    def _flush_page(self, page: int | None) -> None:
        if page is not None:
            for fact in self._pending_page_facts:
                fact["page"] = page
            self._page = page
        self._pending_page_facts = []

    def _add(self, **kwargs) -> None:
        self._seq += 1
        distance = kwargs.pop("distance")
        fact = {
            "local_id": f"{self.document_key}:{self._seq}",
            "document_key": self.document_key,
            "source_url": self.source_url,
            "currency": "EUR",
            "extraction_method": self.method,
            "confidence": 0.95 if distance <= 8 else 0.7,
            "quality_flags": ["column_alignment_loose"] if distance > 8 else [],
            "page": None,
            "comparable": True,
            **kwargs,
        }
        self.facts.append(fact)
        self._pending_page_facts.append(fact)

    def _parse_gesamt_line(self, line: str, statement: str | None, scope: str, columns: list[dict]) -> None:
        stripped = line.strip()
        if stripped.startswith("Verprobung Veränderung"):
            self._emit_amounts(
                code="VERPROBUNG",
                label=_clean_label(stripped),
                statement="liquiditaet",
                scope="liquiditaet",
                nature="bestand",
                line=line,
                columns=columns,
                value_kind_override="veraenderung",
            )
            return
        match = CODE_RE.match(stripped)
        if not match:
            return
        code = match.group("code")
        label = _clean_label(match.group("rest"))
        if code in {"A", "B", "C", "D"}:
            year_match = YEAR_IN_LABEL_RE.search(label)
            kind = "endbestand" if label.lower().startswith("endbestand") else "anfangsbestand"
            self._emit_amounts(
                code=code,
                label=label,
                statement="liquiditaet",
                scope="liquiditaet",
                nature="bestand",
                line=line,
                columns=columns,
                value_kind_override=kind,
                year_override=int(year_match.group(1)) if year_match else None,
            )
            return
        self._emit_amounts(
            code=code,
            label=label,
            statement=statement or "unbekannt",
            scope=scope,
            nature=_nature(code, statement or ""),
            line=line,
            columns=columns,
        )

    def _parse_nachweis_line(self, line: str, columns: list[dict]) -> None:
        if not AMOUNT_RE.search(line):
            return
        stripped = line.strip()
        if stripped.startswith(("Finanzierungshaushalt", "Ergebnishaushalt", "Vermögenshaushalt", "Position")):
            return
        code = None
        code_match = re.match(r"^(SA\d{1,2}|Saldo 0|C)\b", stripped)
        if code_match:
            code = "SA0" if code_match.group(1) == "Saldo 0" else code_match.group(1)
        label = _clean_label(stripped)
        self._emit_amounts(
            code=code,
            label=label,
            statement="nachweis",
            scope="nachweis",
            nature=_nature(code, "nachweis"),
            line=line,
            columns=columns,
        )

    def _emit_amounts(
        self,
        code: str | None,
        label: str,
        statement: str,
        scope: str,
        nature: str,
        line: str,
        columns: list[dict],
        value_kind_override: str | None = None,
        year_override: int | None = None,
    ) -> None:
        if not label:
            return
        for column, raw_amount, distance in _assign(columns, line):
            try:
                amount = parse_amount(raw_amount)
            except ValueError:
                self.warnings.append(f"unparsed amount {raw_amount!r} in {label}")
                continue
            year = year_override if year_override is not None else column["year"]
            kind = value_kind_override or column["value_kind"]
            if kind == "reported_deviation" and year is None:
                self.warnings.append(f"deviation without year skipped for {code or label}")
                continue
            self._add(
                code=code,
                original_label=label,
                statement=statement,
                scope=scope,
                fiscal_year=year,
                value_kind=kind,
                nature=nature,
                amount=str(amount),
                amount_original=raw_amount.strip(),
                excerpt=line.strip()[:500],
                distance=distance,
            )


def _scope_from_line(line: str, current: str | None) -> str | None:
    if "bereinigt" in line:
        return "bereinigt"
    if "interne Vergütungen enthalten" in line:
        return "inklusive_interne"
    return current


def parse_vrv_text(text: str, document_key: str, source_url: str | None = None, method: str = "vrv2015-layout-v1") -> dict:
    return VrvParser(document_key, source_url, method).parse(text)


def find_fact(facts: list[dict], **expected) -> dict | None:
    for fact in facts:
        if all(fact.get(key) == value for key, value in expected.items()):
            return fact
    return None


def amount_of(fact: dict | None) -> Decimal | None:
    if fact is None:
        return None
    return Decimal(fact["amount"])
