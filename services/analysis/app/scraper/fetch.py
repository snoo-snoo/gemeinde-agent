"""Download public municipal documents with redirect and size limits."""

from __future__ import annotations

import hashlib
from dataclasses import dataclass
from datetime import datetime, timezone
from urllib.parse import urljoin

import httpx

from app.scraper.ssrf import SsrfError, assert_public_url

DEFAULT_USER_AGENT = "KommunalAuditAI/0.1 (public municipal finance research)"
MAX_BYTES = 40_000_000
MAX_REDIRECTS = 3


@dataclass
class FetchResult:
    url: str
    final_url: str
    status_code: int
    content: bytes
    content_type: str
    sha256: str

    def as_dict(self) -> dict:
        return {
            "url": self.url,
            "final_url": self.final_url,
            "status_code": self.status_code,
            "content_type": self.content_type,
            "byte_size": len(self.content),
            "sha256": self.sha256,
            "fetched_at": datetime.now(timezone.utc).isoformat(),
        }


class PublicFetcher:
    def __init__(self, client: httpx.Client | None = None, resolver=None, user_agent: str = DEFAULT_USER_AGENT):
        self.client = client or httpx.Client(timeout=httpx.Timeout(60.0), follow_redirects=False)
        self.resolver = resolver
        self.user_agent = user_agent

    def get(self, url: str) -> FetchResult:
        current = url
        response = None
        for _ in range(MAX_REDIRECTS + 1):
            assert_public_url(current, self.resolver)
            response = self.client.get(current, headers={"User-Agent": self.user_agent, "Accept": "text/html,application/pdf,*/*"})
            if response.status_code not in {301, 302, 303, 307, 308}:
                break
            location = response.headers.get("location")
            if not location:
                break
            current = urljoin(current, location)
        else:
            raise SsrfError("too many redirects")
        if response is None:
            raise SsrfError("no response")
        content = response.content
        if len(content) > MAX_BYTES:
            raise ValueError("response exceeds the download limit")
        digest = hashlib.sha256(content).hexdigest()
        return FetchResult(
            url=url,
            final_url=str(response.url) if response.url else current,
            status_code=response.status_code,
            content=content,
            content_type=response.headers.get("content-type", ""),
            sha256=digest,
        )
