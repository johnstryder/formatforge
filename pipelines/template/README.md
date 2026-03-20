# FormatForge Pipeline Template

Go binary for cron-based content generation. Fetches pending source links from FormatForge, generates videos via Replicate or fal.ai, and submits them back.

## Build

```bash
go build -o pipeline-generate .
# or: make build
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

\* At least one of REPLICATE_API_TOKEN or FAL_KEY must be set. Pi coding agents can choose which provider each pipeline uses.

## Cron

```bash
# Every 6 hours
0 */6 * * * cd /path/to/pipelines/template && ./pipeline-generate
```

## Pipeline creation (Cursor Agent)

When the Cursor **`agent`** (Composer 2, etc.) creates a new pipeline from this template, it should:

1. Copy this directory to a new path (e.g. `pipelines/pipeline-<id>/`)
2. Write a `.env` or config with the above variables
3. Add a crontab entry or systemd timer
4. If content is rejected, the agent may edit the prompt template or schedule, and upsert PocketBase **`pipelines`** records
