"""Internal FastAPI service. It is not exposed to browsers."""

from __future__ import annotations

import os
from pathlib import Path

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from app.agents.runner import run_agent
from app.exporting import assert_inside, write_docx, write_markdown, write_pdf
from app.scraper.fetch import PublicFetcher

app = FastAPI(title="KommunalAudit Analysis", docs_url=None, redoc_url=None, openapi_url=None)


def require_token(x_internal_token: str | None = Header(default=None)) -> None:
    expected = os.environ.get("ANALYSIS_INTERNAL_TOKEN", "")
    if not expected or x_internal_token != expected:
        raise HTTPException(status_code=401, detail="unauthorized")


class AgentRequest(BaseModel):
    context: dict = Field(default_factory=dict)


class RenderRequest(BaseModel):
    markdown: str
    export_format: str
    dest_path: str


@app.get("/health")
def health() -> dict:
    return {"status": "ok", "service": "analysis"}


@app.post("/internal/agents/{agent_key}")
def agent(agent_key: str, body: AgentRequest, _: None = Depends(require_token)) -> dict:
    fetcher = PublicFetcher()
    if agent_key == "scraper_acquisition":
        return _acquire(body.context, fetcher)
    return run_agent(agent_key, body.context, fetcher)


@app.post("/internal/render")
def render(body: RenderRequest, _: None = Depends(require_token)) -> dict:
    root = Path(os.environ.get("ANALYSIS_STORAGE_ROOT", "/tmp/kommunalaudit"))
    destination = assert_inside(Path(body.dest_path), root)
    if body.export_format == "md":
        write_markdown(body.markdown, destination)
    elif body.export_format == "docx":
        write_docx(body.markdown, destination)
    elif body.export_format == "pdf":
        write_pdf(body.markdown, destination)
    else:
        raise HTTPException(status_code=422, detail="unsupported format")
    return {"path": str(destination), "bytes": destination.stat().st_size}


def _acquire(context: dict, fetcher: PublicFetcher) -> dict:
    root = Path(os.environ.get("ANALYSIS_STORAGE_ROOT", "/tmp/kommunalaudit"))
    directory = assert_inside(Path(context["dest_dir"]), root)
    directory.mkdir(parents=True, exist_ok=True)
    fetched = fetcher.get(context["url"])
    if fetched.status_code >= 400:
        return {"agent_key": "scraper_acquisition", "status": "failed", "output": fetched.as_dict(), "warnings": [f"HTTP {fetched.status_code}"]}
    name = context.get("filename") or f"{fetched.sha256}.bin"
    if "/" in name or "\\" in name or name.startswith("."):
        raise HTTPException(status_code=422, detail="invalid filename")
    target = assert_inside(directory / name, root)
    target.write_bytes(fetched.content)
    output = fetched.as_dict()
    output["path"] = str(target)
    output["content"] = None
    return {"agent_key": "scraper_acquisition", "status": "completed", "warnings": [], "output": output}
