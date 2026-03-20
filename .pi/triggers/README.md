# Pipeline trigger directory

When FormatForge detects **content rejected** or **novel** content, it:

1. Writes a `trigger_*.json` file here
2. Runs `setup_pipeline_from_trigger()` in `index.php`, which creates:
   - `pipelines/pipeline-<id>/` — copy of template with `.env` from project root
   - `.pi/prompts/pipeline-<id>.md` — task prompt for the **Cursor Agent** CLI

3. Spawns (background) **`php index.php cursor-agent-run <prompt.md>`**, which runs:

   `agent -p --trust -f --model composer-2-fast --workspace <project> "<task>"`

   Configure **`CURSOR_AGENT_MODEL`** (default `composer-2-fast`), **`CURSOR_AGENT_BIN`**. Auth: **`agent login`** as the php-fpm user (often `www-data`); **`CURSOR_API_KEY`** in `.env` is optional. Logs: **`.pi/cursor-agent.log`**.

**Manual:** `php index.php setup-pipeline [trigger_file]` — prepares files **without** auto-spawning the agent.
