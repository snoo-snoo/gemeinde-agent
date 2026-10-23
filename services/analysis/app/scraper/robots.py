"""Minimal robots.txt reader for crawl-delay and disallow rules."""

from __future__ import annotations

from urllib.parse import urlparse


class RobotsPolicy:
    def __init__(self, crawl_delay: float | None = None, disallow: list[str] | None = None, raw: str = ""):
        self.crawl_delay = crawl_delay
        self.disallow = disallow or []
        self.raw = raw

    def path_allowed(self, url: str) -> bool:
        path = urlparse(url).path or "/"
        for rule in self.disallow:
            if rule == "/":
                return False
            if rule and path.startswith(rule):
                return False
        return True


def parse_robots(text: str, user_agent: str = "*") -> RobotsPolicy:
    """Apply the first matching user-agent group, otherwise the '*' group.

    The municipal sites used here publish a single global group. Group matching
    is case-insensitive and does not implement the full longest-match algorithm
    of every crawler; it does honor Disallow and Crawl-delay.
    """
    groups: list[tuple[list[str], list[str], float | None]] = []
    agents: list[str] = []
    rules: list[str] = []
    delay: float | None = None

    def flush() -> None:
        nonlocal agents, rules, delay
        if agents:
            groups.append((agents, rules, delay))
        agents, rules, delay = [], [], None

    for raw_line in text.splitlines():
        line = raw_line.split("#", 1)[0].strip()
        if not line or ":" not in line:
            continue
        key, value = line.split(":", 1)
        key = key.strip().lower()
        value = value.strip()
        if key == "user-agent":
            if rules or delay is not None:
                flush()
            agents.append(value.lower())
        elif key == "disallow":
            rules.append(value)
        elif key == "crawl-delay":
            try:
                delay = float(value.replace(",", "."))
            except ValueError:
                delay = None
    flush()

    wanted = user_agent.lower()
    selected = None
    wildcard = None
    for group_agents, group_rules, group_delay in groups:
        if "*" in group_agents:
            wildcard = (group_rules, group_delay)
        if wanted in group_agents:
            selected = (group_rules, group_delay)
            break
    if selected is None:
        selected = wildcard or ([], None)
    return RobotsPolicy(crawl_delay=selected[1], disallow=selected[0], raw=text)
