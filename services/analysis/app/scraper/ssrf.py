"""SSRF checks for public document acquisition.

The fetcher resolves a host before connecting and again for every redirect.
This blocks literal private addresses and ordinary DNS answers that point at
them. A residual DNS-rebinding window remains between the check and the
socket connect; the acquisition client is only used for configured public
municipal hosts and never receives secrets.
"""

from __future__ import annotations

import ipaddress
import socket
from urllib.parse import urlparse

BLOCKED_HOSTNAMES = {
    "localhost",
    "localhost.localdomain",
    "metadata.google.internal",
    "metadata.internal",
}


class SsrfError(ValueError):
    pass


def _ip_is_blocked(value: str) -> bool:
    try:
        ip = ipaddress.ip_address(value)
    except ValueError:
        return False
    return not ip.is_global


def assert_public_url(url: str, resolver=None) -> None:
    parsed = urlparse(url)
    if parsed.scheme not in {"http", "https"}:
        raise SsrfError("only http and https URLs are allowed")
    if parsed.username or parsed.password:
        raise SsrfError("credentials in URLs are not allowed")
    host = parsed.hostname
    if not host:
        raise SsrfError("URL has no host")
    if host.lower().rstrip(".") in BLOCKED_HOSTNAMES:
        raise SsrfError("host is blocked")
    if _ip_is_blocked(host):
        raise SsrfError("IP address is not public")

    lookup = resolver or (lambda name: [item[4][0] for item in socket.getaddrinfo(name, None)])
    try:
        addresses = lookup(host)
    except socket.gaierror as exc:
        raise SsrfError("host could not be resolved") from exc
    if not addresses:
        raise SsrfError("host did not resolve")
    for address in addresses:
        if _ip_is_blocked(address):
            raise SsrfError("resolved address is not public")
