#!/usr/bin/env python3
"""Emit JSON for POST /api/v1/tables/content or pipeline_refs (FormatForge + OpenRouter via Antfly)."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

CONTENT_SEMANTIC_TEMPLATE = (
    "{{#if media_url}}{{remoteMedia url=media_url}}{{/if}}\n"
    "{{prompt}}\n{{title}}\n{{source_url}}\n{{mime}}"
)


def load_env(path: Path) -> dict[str, str]:
    env: dict[str, str] = {}
    if not path.is_file():
        return env
    for line in path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        m = re.match(r"^([A-Za-z_][A-Za-z0-9_]*)=(.*)$", line)
        if m:
            env[m.group(1)] = m.group(2).strip().strip('"').strip("'")
    return env


def embedder_from_env(env: dict[str, str]) -> dict[str, str]:
    emb: dict[str, str] = {
        "provider": "openrouter",
        "model": env.get("EMBED_MODEL") or "google/gemini-embedding-001",
    }
    if env.get("OPENROUTER_API_KEY"):
        emb["api_key"] = env["OPENROUTER_API_KEY"]
    return emb


def body_content(embedder: dict[str, str]) -> dict:
    return {
        "num_shards": 1,
        "schema": {
            "document_schemas": {
                "content": {
                    "schema": {
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "x-antfly-types": ["keyword"]},
                            "prompt": {"type": "string", "x-antfly-types": ["text"]},
                            "type": {"type": "string", "x-antfly-types": ["keyword"]},
                            "status": {"type": "string", "x-antfly-types": ["keyword"]},
                            "title": {"type": "string", "x-antfly-types": ["text"]},
                            "source_url": {"type": "string", "x-antfly-types": ["text"]},
                            "mime": {"type": "string", "x-antfly-types": ["keyword"]},
                            "media_url": {"type": "string", "x-antfly-types": ["text"]},
                        },
                        "x-antfly-include-in-all": ["prompt", "title", "source_url"],
                    }
                }
            },
            "default_type": "content",
        },
        "indexes": {
            "search_idx": {"type": "full_text"},
            "semantic_idx": {
                "type": "embeddings",
                "template": CONTENT_SEMANTIC_TEMPLATE,
                "embedder": embedder,
            },
        },
    }


def body_pipeline_refs(embedder: dict[str, str]) -> dict:
    return {
        "num_shards": 1,
        "schema": {
            "document_schemas": {
                "pipeline_ref": {
                    "schema": {
                        "type": "object",
                        "properties": {
                            "id": {"type": "string", "x-antfly-types": ["keyword"]},
                            "prompt": {"type": "string", "x-antfly-types": ["text"]},
                            "name": {"type": "string", "x-antfly-types": ["text"]},
                        },
                        "x-antfly-include-in-all": ["prompt"],
                    }
                }
            },
            "default_type": "pipeline_ref",
        },
        "indexes": {
            "search_idx": {"type": "full_text"},
            "semantic_idx": {
                "type": "embeddings",
                "field": "prompt",
                "embedder": embedder,
            },
        },
    }


def main() -> None:
    root = Path(__file__).resolve().parent.parent
    env = load_env(root / ".env")
    embedder = embedder_from_env(env)
    which = (sys.argv[1] if len(sys.argv) > 1 else "content").strip().lower()
    if which in ("pipeline_refs", "pipelines", "refs"):
        body = body_pipeline_refs(embedder)
    else:
        body = body_content(embedder)
    sys.stdout.write(json.dumps(body, separators=(",", ":")))


if __name__ == "__main__":
    main()
