# Trigger directory (`.cursor-pipeline/triggers/`)

When FormatForge detects **novel** fetched content or a **reject streak**, it:

1. Writes a `trigger_*.json` file **here**
2. Runs `setup_pipeline_from_trigger()` in `index.php`, which creates:
   - `pipelines/pipeline-<id>/` — copy of `pipelines/template` with `.env` from the project root
   - **`.cursor-pipeline/prompts/pipeline-<id>.md`** — task prompt for the Cursor CLI

3. Spawns (background) **`php index.php cursor-agent-run <that .md>`**, which runs **`agent`** with **`--workspace`** = repo root (so `.cursor-pipeline/` is part of the project).

Configure **`CURSOR_AGENT_MODEL`**, **`CURSOR_AGENT_BIN`**. Auth: **`agent login`** as the php-fpm user; **`CURSOR_API_KEY`** in `.env` is optional. Logs: **`.cursor-pipeline/cursor-agent.log`**.

**Manual:** `php index.php setup-pipeline [trigger_file]` — prepares dirs **without** auto-spawning the agent.

Trigger JSON includes **`backing_source_link_id`** (novel fetch) or reject-side **`content_item_source_link_id`** / **`backing_source_link_id_on_pipeline`** so the generated task tells the agent to PATCH **`pipelines.metadata.backing_source_link_id`** when creating or fixing a pipeline.
