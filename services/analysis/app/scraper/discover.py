"""Discover document links on one configured municipal page."""

from __future__ import annotations

from html.parser import HTMLParser
from urllib.parse import urljoin, urlparse

from app.documents.classify import classify_title
from app.scraper.fetch import PublicFetcher
from app.scraper.robots import parse_robots


class _AnchorParser(HTMLParser):
    def __init__(self):
        super().__init__()
        self.links: list[tuple[str, str]] = []
        self._href: str | None = None
        self._parts: list[str] = []

    def handle_starttag(self, tag, attrs):
        if tag.lower() != "a":
            return
        self._href = dict(attrs).get("href")
        self._parts = []

    def handle_data(self, data):
        if self._href is not None:
            self._parts.append(data)

    def handle_endtag(self, tag):
        if tag.lower() == "a" and self._href is not None:
            self.links.append((self._href, " ".join(self._parts)))
            self._href = None
            self._parts = []


def discover_from_html(page_url: str, html: str, robots_text: str = "", allowed_hosts: list[str] | None = None) -> dict:
    parser = _AnchorParser()
    parser.feed(html)
    policy = parse_robots(robots_text) if robots_text else None
    host = urlparse(page_url).hostname or ""
    allowed = {item.lower() for item in (allowed_hosts or [host])}
    documents = []
    skipped = []
    for href, title in parser.links:
        if not href or href.startswith(("#", "mailto:", "tel:", "javascript:")):
            continue
        absolute = urljoin(page_url, href)
        parsed = urlparse(absolute)
        if parsed.scheme not in {"http", "https"}:
            skipped.append({"url": absolute, "reason": "scheme"})
            continue
        if (parsed.hostname or "").lower() not in allowed:
            skipped.append({"url": absolute, "reason": "other_host"})
            continue
        if policy and not policy.path_allowed(absolute):
            skipped.append({"url": absolute, "reason": "robots_disallow"})
            continue
        classified = classify_title(title, absolute)
        if not classified["relevant"]:
            continue
        documents.append({**classified, "url": absolute, "fetch_status": "discovered"})
    return {
        "page_url": page_url,
        "robots": {
            "crawl_delay": None if policy is None else policy.crawl_delay,
            "disallow": [] if policy is None else policy.disallow,
        },
        "documents": documents,
        "skipped_count": len(skipped),
        "data_gaps": [] if documents else ["Auf der Seite wurden keine relevanten Dokumentlinks erkannt."],
    }


def discover_url(url: str, fetcher: PublicFetcher, allowed_hosts: list[str] | None = None) -> dict:
    page = fetcher.get(url)
    if page.status_code >= 400:
        return {"page_url": url, "documents": [], "data_gaps": [f"Seite antwortete mit HTTP {page.status_code}."], "robots": {}}
    origin = f"{urlparse(url).scheme}://{urlparse(url).hostname}"
    robots_text = ""
    try:
        robots = fetcher.get(origin + "/robots.txt")
        if robots.status_code < 400:
            robots_text = robots.content.decode("utf-8", errors="replace")
    except Exception:
        robots_text = ""
    discovered = discover_from_html(url, page.content.decode("utf-8", errors="replace"), robots_text, allowed_hosts)
    discovered["page_status"] = page.status_code
    return discovered
