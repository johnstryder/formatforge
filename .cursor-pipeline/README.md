# `.cursor-pipeline/` (FormatForge)

This directory is **inside the FormatForge repository** (next to `index.php`). PHP and the Cursor CLI use it for autonomous pipeline triggers.

- **`triggers/`** — JSON files written when fetch/reject rules fire
- **`prompts/`** — Markdown instructions passed to `php index.php cursor-agent-run …`
- **`cursor-agent.log`** — appended when the agent runner executes (created automatically)

Cursor is invoked with **`--workspace`** = the **repo root**, so the agent sees **`.cursor-pipeline/`** as normal project files — edit them like anything else under this tree.

Override the triggers location with **`CURSOR_PIPELINE_TRIGGER_DIR`** in `.env` (or legacy **`PI_TRIGGER_DIR`**).

## Orchestrator & 3-Step Pipeline Chain

The **Orchestrator is FormatForge** (`index.php`). It injects **one** chain step per generated prompt from **`pipelines/<subdir>/agent_state.json`** → **`execution_step`** (markers `<!-- FF_ORCHESTRATOR_INJECTION -->` in `.cursor-pipeline/prompts/<subdir>.md`). The agent does **not** receive all three steps in one prompt.

1. **Step 1:** Context gathering → **`pipelines/<subdir>/source_analysis.md`**
2. **Step 2:** Deconstruction & compositing → **`pipelines/<subdir>/pipeline_architecture.json`**
3. **Step 3:** Implementation, build & validation → final `.mp4` or carousel

**Resume:** `/resume-agent <agent_uuid>` or `/resume-agent <pipeline_subdir>` — same Cursor chat.

**Advance step + refresh injection block:** `php index.php cursor-agent-advance-step <pipeline_subdir>` — bumps `execution_step` when artifacts exist and refreshes the orchestrator block in the prompt file.

**Refresh injection only:** `php index.php pipeline-orchestrator-refresh-prompt <pipeline_subdir|uuid>` — rewrites the injection region from current `execution_step`.

**PocketBase checklist:** When the agent creates or edits a **`pipelines`** row, set **`metadata.backing_source_link_id`** to the **`source_links`** id from the trigger context when present (`backing_source_link_id` on novel fetch; **`content_item_source_link_id`** vs **`backing_source_link_id_on_pipeline`** on reject). That wires **`formatforge_generate_content_finish`** source backing without hand-editing metadata.

## Pipeline agent defaults (orchestrator policy)

- **AI-first:** Prefer **Replicate** (or fal) for synthetic media; choose models on **replicate.com** using **run counts** on model pages (higher → stronger default), then pin **version ids** in **`pipeline_architecture.json`** and **`prompt_template`**. Do **not** default to ImageMagick / Go imaging compositing unless deterministic pixels are clearly required. **ffmpeg**/TTS/local render are optional when they add value.
- **No near-copy:** Do not ship backing URLs or trivial filter-clones as “new” content; keep essence, change composition. See **`.cursor/rules/formatforge-orchestrator-agent.mdc`**.
