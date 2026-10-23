"""Text extraction from public PDFs.

Layout-preserving pdftotext is the primary method because VRV columns are
positional. OCR is only attempted when the text layer is effectively empty,
and the result is marked as partial.
"""

from __future__ import annotations

import shutil
import subprocess
from pathlib import Path

MIN_TEXT_CHARS = 200


def needs_ocr(text: str) -> bool:
    return len((text or "").strip()) < MIN_TEXT_CHARS


def extract_pdf_text(path: Path, allow_ocr: bool = False) -> dict:
    binary = shutil.which("pdftotext")
    if not binary:
        return {"status": "failed", "method": None, "text": "", "error": "pdftotext ist nicht installiert."}
    completed = subprocess.run(
        [binary, "-layout", "-enc", "UTF-8", str(path), "-"],
        check=False,
        capture_output=True,
    )
    if completed.returncode != 0:
        error = completed.stderr.decode("utf-8", errors="replace")[:500]
        return {"status": "failed", "method": "pdftotext-layout", "text": "", "error": error}
    text = completed.stdout.decode("utf-8", errors="replace")
    if not needs_ocr(text):
        return {"status": "completed", "method": "pdftotext-layout", "text": text, "error": None}
    if not allow_ocr:
        return {
            "status": "data_gap",
            "method": "pdftotext-layout",
            "text": text,
            "error": "Die Datei hat keine ausreichende Textebene. OCR wurde nicht ausgeführt.",
        }
    return ocr_first_pages(path, text)


def ocr_first_pages(path: Path, existing_text: str, pages: int = 3) -> dict:
    if not shutil.which("pdftoppm") or not shutil.which("tesseract"):
        return {
            "status": "data_gap",
            "method": "ocr-unavailable",
            "text": existing_text,
            "error": "OCR-Werkzeuge sind nicht installiert.",
        }
    work = path.parent / f".ocr-{path.stem}"
    work.mkdir(exist_ok=True)
    rendered = subprocess.run(
        ["pdftoppm", "-f", "1", "-l", str(pages), "-png", str(path), str(work / "page")],
        check=False,
        capture_output=True,
    )
    if rendered.returncode != 0:
        return {"status": "failed", "method": "ocr", "text": existing_text, "error": rendered.stderr.decode()[:500]}
    chunks = []
    for image in sorted(work.glob("page*.png")):
        recognized = subprocess.run(
            ["tesseract", str(image), "stdout", "-l", "deu"],
            check=False,
            capture_output=True,
        )
        if recognized.returncode == 0:
            chunks.append(recognized.stdout.decode("utf-8", errors="replace"))
    return {
        "status": "completed" if chunks else "data_gap",
        "method": "tesseract-deu-partial",
        "text": "\n".join(chunks),
        "error": None if chunks else "OCR lieferte keinen Text.",
        "note": f"Nur die ersten {pages} Seiten wurden per OCR gelesen.",
    }
