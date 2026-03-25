# FormatForge Pipeline Template

Go binary for cron-based content generation. Fetches pending source links from FormatForge, generates videos via Replicate or fal.ai, and submits them back.

## Media generation (defaults for pipeline agents)

- **Default to AI:** Use **Replicate** (or fal.ai per `.env`) for synthetic **image/video** and related model steps. Browse **https://replicate.com**, open **model pages**, and prefer models with **higher run counts** for your modality (more runs → more battle-tested). Record **exact owner/name + version id** in **`pipeline_architecture.json`** and PocketBase **`prompt_template`**.
- **Optional local glue:** **`ffmpeg`** (mux, captions, trim), **TTS**, or small stdlib **`os/exec`** helpers when they clearly improve the deliverable.
- **Offline / deterministic rendering** (headless browser, canvas libs, diagram code): only when an AI model is a poor fit; **do not** reach for ImageMagick, `imaging`, or heavy pixel compositing stacks by default.

## Build

```bash
go build -o pipeline-generate .
# or: make build
# refresh module graph after adding rendering route code:
# make deps
```

## Config (env)

| Variable | Required | Description |
|----------|----------|-------------|
| `REPLICATE_API_TOKEN` | yes* | Replicate API token (when VIDEO_PROVIDER=replicate) |
| `FAL_KEY` | yes* | fal.ai API key (when VIDEO_PROVIDER=fal) |
| `VIDEO_PROVIDER` | no | `replicate` or `fal` (default: replicate if token set, else fal if FAL_KEY set) |
| `FAL_VIDEO_MODEL` | no | fal.ai model (default: fal-ai/kling-video/v2.5-turbo/pro/text-to-video) |
| `POCKETBASE_URL` | yes | PocketBase API URL (e.g. http://localhost:8090) |
| `FORMATFORGE_EMAIL` | yes | Login email |
| `FORMATFORGE_PASSWORD` | yes | Login password |
| `PROMPT` | no | Default video prompt |
| `PROMPT_TEMPLATE` | no | Template with `{{.SourceURL}}`, `{{.SourceTitle}}` |

\* At least one of REPLICATE_API_TOKEN or FAL_KEY must be set. Cursor (or whoever authors the pipeline) can choose which provider each pipeline uses.

## Cron

```bash
# Every hour (at minute 0)
0 * * * * cd /path/to/pipelines/template && ./pipeline-generate
```

## Pipeline creation (Cursor Agent)

Each pipeline follows the **3-step chain** (see `.cursor-pipeline/README.md`): produce **`source_analysis.md`**, then **`pipeline_architecture.json`**, then implement. **FormatForge `index.php` injects one step per Cursor prompt** from **`agent_state.execution_step`** — not all three steps at once. **Non-compliant:** a **`prompt_template`** that is only a vague creative line **without** those artifacts and **without** pinned **Replicate/fal model versions**. **`verify-pipeline-generation`** is a **Replicate/fal smoke test** only — run it **after** the architecture file exists when PHP’s video path applies, or document a different **README** check.

**Carousel / multi-slide fetches:** When the novelty trigger context includes **`suggested_pipelines_output_type: carousel`** (multi-item fetch), set **`pipelines.output_type`** to **`carousel`** so the UI matches the batch workflow; **`content_items`** may still be **`reel`** per slide.

When the Cursor **`agent`** (Composer 2, etc.) creates a new pipeline from this template, it should:

1. Copy this directory to a new path (e.g. `pipelines/pipeline-<id>/`)
2. Write a `.env` or config with the above variables
3. Add a crontab entry or systemd timer
4. If content is rejected, the agent may edit the prompt template or schedule, and upsert PocketBase **`pipelines`** records
5. **Backing cardinality:** If the fetched backing from a source link is a **carousel** with N items (e.g. 7 slides), generate **N** outputs in order (one per slide). If it is a **single video**, generate **one** video. Stay close to the backing subject and structure — do not collapse a multi-item carousel into a single unrelated clip unless the product owner explicitly asks.
6. **Modality:** **Default** to **Replicate/fal** with models chosen by **run count** on replicate.com; add offline or **ffmpeg** steps only when they clearly help. Document choices in **`prompt_template`** and this binary.
7. **Curate vs cron:** The **Pipelines → Run** path in the PHP app uses PocketBase **`pipelines.prompt_template`** and **`metadata.rejection_log`** (feedback from rejects). This **cron binary** uses **`PROMPT` / `PROMPT_TEMPLATE` in `.env` only** — it does **not** read that rejection log. Keep `.env` in sync with the pipeline row if you rely on cron, or disable cron until you’re happy with the template.
