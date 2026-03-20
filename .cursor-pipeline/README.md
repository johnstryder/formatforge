# `.cursor-pipeline/` (FormatForge)

This directory is **inside the FormatForge repository** (next to `index.php`). PHP and the Cursor CLI use it for autonomous pipeline triggers.

- **`triggers/`** — JSON files written when fetch/reject rules fire
- **`prompts/`** — Markdown instructions passed to `php index.php cursor-agent-run …`
- **`cursor-agent.log`** — appended when the agent runner executes (created automatically)

Cursor is invoked with **`--workspace`** = the **repo root**, so the agent sees **`.cursor-pipeline/`** as normal project files — edit them like anything else under this tree.

Override the triggers location with **`CURSOR_PIPELINE_TRIGGER_DIR`** in `.env` (or legacy **`PI_TRIGGER_DIR`**).
