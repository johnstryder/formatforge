#!/usr/bin/env python3
"""Emit JSON body for POST /api/v1/tables/content (FormatForge + OpenRouter embeddings)."""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path


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


def main() -> None:
    root = Path(__file__).resolve().parent.parent
    env = load_env(root / ".env")
    embedder: dict[str, str] = {
        "provider": "openrouter",
        "model": env.get("EMBED_MODEL") or "google/gemini-embedding-001",
    }
    if env.get("OPENROUTER_API_KEY"):
        embedder["api_key"] = env["OPENROUTER_API_KEY"]
    body = {
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
                        },
                        "x-antfly-include-in-all": ["prompt"],
                    }
                }
            },
            "default_type": "content",
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
    sys.stdout.write(json.dumps(body, separators=(",", ":")))


if __name__ == "__main__":
    main()
