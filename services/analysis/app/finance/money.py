"""Austrian municipal amount parsing and plan/actual arithmetic."""

from __future__ import annotations

from dataclasses import dataclass
from decimal import Decimal, ROUND_HALF_UP
import re

CENT = Decimal("0.01")
PERCENT_QUANT = Decimal("0.01")
# Spaces belong to the match only when they follow an explicit sign.
# An unbounded \s* would swallow the column gap and shift every amount left.
AMOUNT_RE = re.compile(r"(?<![\d.,])(?:[+\-]\s*)?(?:\d{1,3}(?:\.\d{3})+|\d+),\d{2}")


class MoneyError(ValueError):
    pass


def parse_amount(raw: str) -> Decimal:
    """Parse a German-formatted amount such as '-1.160.172,19' or '+ 4.878,43'."""
    text = raw.strip().replace(" ", "")
    if not text:
        raise MoneyError("empty amount")
    sign = 1
    if text[0] in "+-":
        sign = -1 if text[0] == "-" else 1
        text = text[1:]
    if not re.fullmatch(r"\d{1,3}(?:\.\d{3})*,\d{2}|\d+,\d{2}", text):
        raise MoneyError(f"not a German decimal amount: {raw}")
    normalized = text.replace(".", "").replace(",", ".")
    return (Decimal(normalized) * sign).quantize(CENT, rounding=ROUND_HALF_UP)


def format_amount(value: Decimal) -> str:
    quantized = value.quantize(CENT, rounding=ROUND_HALF_UP)
    sign = "-" if quantized < 0 else ""
    whole, cents = f"{abs(quantized):.2f}".split(".")
    groups = []
    while whole:
        groups.append(whole[-3:])
        whole = whole[:-3]
    return f"{sign}{'.'.join(reversed(groups))},{cents}"


@dataclass(frozen=True)
class Deviation:
    absolute: Decimal | None
    relative_percent: Decimal | None
    status: str
    reason: str | None
    formula: str

    def as_dict(self) -> dict:
        return {
            "absolute": None if self.absolute is None else format_amount(self.absolute),
            "absolute_decimal": None if self.absolute is None else str(self.absolute),
            "relative_percent": None if self.relative_percent is None else str(self.relative_percent),
            "status": self.status,
            "reason": self.reason,
            "formula": self.formula,
        }


def plan_actual_deviation(plan: Decimal | None, actual: Decimal | None) -> Deviation:
    """Absolute deviation is actual minus plan.

    The percentage uses the plan amount only when that amount is strictly positive.
    A zero or negative plan is not a meaningful percentage base for municipal
    appropriations, surpluses or cash balances.
    """
    formula = "absolute = ist - plan; relative = absolute / plan * 100 if plan > 0"
    if plan is None or actual is None:
        return Deviation(None, None, "missing", "plan_or_actual_missing", formula)
    absolute = (actual - plan).quantize(CENT, rounding=ROUND_HALF_UP)
    if plan <= 0:
        reason = "plan_is_zero" if plan == 0 else "plan_is_negative"
        return Deviation(absolute, None, "percent_not_meaningful", reason, formula)
    relative = (absolute / plan * Decimal(100)).quantize(PERCENT_QUANT, rounding=ROUND_HALF_UP)
    return Deviation(absolute, relative, "ok", None, formula)


def period_change(base: Decimal | None, comparison: Decimal | None) -> Deviation:
    """Change from an earlier base year to a later comparison year."""
    formula = "absolute = comparison_year - base_year; relative = absolute / base_year * 100 if base_year > 0"
    if base is None or comparison is None:
        return Deviation(None, None, "missing", "base_or_comparison_missing", formula)
    absolute = (comparison - base).quantize(CENT, rounding=ROUND_HALF_UP)
    if base <= 0:
        reason = "base_is_zero" if base == 0 else "base_is_negative"
        return Deviation(absolute, None, "percent_not_meaningful", reason, formula)
    relative = (absolute / base * Decimal(100)).quantize(PERCENT_QUANT, rounding=ROUND_HALF_UP)
    return Deviation(absolute, relative, "ok", None, formula)


def within_tolerance(left: Decimal, right: Decimal, tolerance: Decimal) -> bool:
    return abs(left - right) <= tolerance
