# FormatForge — Autonomous Content Pipeline

Single-file PHP dashboard for AI content generation, curation, and Instagram publishing. Built on tazzamphp (PocketBase + Alpine.js).

## Quick start

```bash
./scripts/download-pocketbase.sh   # one-time: get PocketBase binary
./scripts/start.sh                 # start PocketBase + PHP built-in server
```

**Or with nginx + PHP-FPM + PocketBase (Docker):**
```bash
docker compose -f docker-compose.nginx.yml up -d
# App: http://127.0.0.1:8010 (or APP_PORT=8010)
# PocketBase Admin: http://127.0.0.1:8090/_/
```

- **App:** http://127.0.0.1:8000 (start.sh) or http://127.0.0.1:8010 (nginx)
- **PocketBase Admin:** http://127.0.0.1:8090/_/

## Setup

1. **Create admin:**
   - **Local (start.sh):** `./formatforge-pb admin create your@email.com yourpassword`
   - **Docker:** Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `.env` before `docker compose up` (auto-creates on first run), or run: `docker compose -f docker-compose.nginx.yml exec pocketbase pocketbase superuser create --dir /pb_data`
2. **Copy .env:** `cp .env.example .env` and fill in:
   - `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `MIGRATE_SECRET`
   - `GARAGE_*` (S3-compatible storage)
   - `REPLICATE_API_TOKEN` (or `FAL_KEY` for fal.ai)
   - `FB_APP_ID`, `FB_APP_SECRET`, `INSTAGRAM_REDIRECT_URI` (for Instagram OAuth)
   - `ANTFLY_URL`, `ANTFLY_API_KEY` (optional, self-hosted Antfly for search/indexing)
   - **Antfly (optional):** For search indexing with external embeddings:
     - **Minimal build** (no AVX2, for older CPUs): `docker compose -f docker-compose.antfly-minimal.yml up -d --build`
     - Runs `swarm --termite=false` — use OpenAI, Ollama, etc. for embeddings
     - Or full build: `ANTFLY_PORT=8080 docker compose -f docker-compose.antfly.yml up -d`
     - `./scripts/init-antfly.sh` creates the content table (if needed)
3. **Migrations:** Collections are created automatically from `pb_migrations/` when PocketBase starts. Restart the server to apply.
4. **Create user:** PocketBase Admin → Collections → users → Create record (email + password).  
   For self-signup on the login page (when on Tailscale/internal network), set the users collection **Create** rule to empty or `@request.auth.id = ""` in PocketBase Admin. Or set `ALLOW_SIGNUP=1` in `.env` to always show the create-account form.

## Frontend

1. **Send links** — Paste URLs to queue as content sources
2. **Curate** — View generated videos, approve or reject, publish to Instagram

## Integrations

- **PocketBase** — Auth, OAuth tokens, content state
- **Garage S3** — Store generated .mp4 files
- **Replicate** — Video generation (minimax/video-01)
- **fal.ai** — Alternative video generation (Kling, LTX, etc.)
- **Instagram Graph API** — OAuth + publish Reels
- **Antfly** — Self-hosted; index metadata for search (optional)
- **ffmpeg** — Video compositing (used by generation pipeline)

## Autonomous pipeline triggers (pi coding agent)

When a user **rejects** content or adds/approves content that is **novel** (embedding distance above threshold), FormatForge:

1. Writes a trigger file to `PI_TRIGGER_DIR`
2. Runs `setup_pipeline_from_trigger()` (in index.php) which:
   - Creates `pipelines/pipeline-<id>/` from the template
   - Copies `.env` (Replicate, PocketBase, Garage, login) into the pipeline dir
   - Creates `.pi/pipeline-<id>.env` with OpenRouter/API credentials for pi
   - Creates `.pi/prompts/pipeline-<id>.md` — the prompt for pi to run

**Setup:**
1. Set `PI_TRIGGER_DIR` in `.env` (default: `.pi/triggers`)
2. Set `EMBED_URL` (Ollama) or `OPENAI_API_KEY` for novelty detection
3. Set `OPENROUTER_API_KEY` (or `ANTHROPIC_API_KEY` / `OPENAI_API_KEY`) and `PI_MODEL` for pi

**Manual run:** `php index.php setup-pipeline [trigger_file]` (uses latest trigger if no arg)

**Pi runs:** `source .pi/pipeline-<id>.env && pi` (with the prompt file as task)

**Go pipeline:** Each pipeline dir has its own `.env`. Build and cron:

```bash
cd pipelines/pipeline-<id> && go build -o pipeline-generate .
# Cron: 0 */6 * * * cd /path/to/pipelines/pipeline-<id> && set -a && . .env && set +a && ./pipeline-generate
```

## pi-autoresearch

For the experimentation loop (A/B testing, winning templates), use [pi-autoresearch](https://github.com/davebcn87/pi-autoresearch):

```bash
pi install https://github.com/davebcn87/pi-autoresearch
/skill:autoresearch-create
```

Configure `autoresearch.md` with your content pipeline metrics (e.g. view-to-share ratio, engagement).
