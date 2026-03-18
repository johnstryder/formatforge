# FormatForge — Autonomous Content Pipeline

Single-file PHP dashboard for AI content generation, curation, and Instagram publishing. Built on tazzamphp (PocketBase + Alpine.js).

## Quick start

```bash
./scripts/download-pocketbase.sh   # one-time: get PocketBase binary
./scripts/start.sh                 # start PocketBase + PHP app
```

- **App:** http://127.0.0.1:8000
- **PocketBase Admin:** http://127.0.0.1:8090/_/

## Setup

1. **Create admin:** `./formatforge-pb admin create your@email.com yourpassword`
2. **Copy .env:** `cp .env.example .env` and fill in:
   - `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `MIGRATE_SECRET`
   - `GARAGE_*` (S3-compatible storage)
   - `REPLICATE_API_TOKEN`
   - `FB_APP_ID`, `FB_APP_SECRET`, `INSTAGRAM_REDIRECT_URI` (for Instagram OAuth)
   - `ANTFLY_URL`, `ANTFLY_API_KEY` (optional, self-hosted Antfly for search/indexing)
   - **Antfly (optional):** For local search indexing:
     - `./scripts/start.sh` auto-starts Antfly on a free port (8080, 8081, …) and writes to `.antfly-port`
     - Or manually: `ANTFLY_PORT=8080 docker compose -f docker-compose.antfly.yml up -d`
     - `./scripts/init-antfly.sh` creates the content table (reads port from `.antfly-port` or `.env`)
     - Or install via Homebrew (macOS): `brew install --cask antflydb/antfly/antfly`, then `antfly swarm`
3. **Migrations:** Collections are created automatically from `pb_migrations/` when PocketBase starts. Restart the server to apply.
4. **Create user:** PocketBase Admin → Collections → users → Create record (email + password)

## Frontend

1. **Send links** — Paste URLs to queue as content sources
2. **Curate** — View generated videos, approve or reject, publish to Instagram

## Integrations

- **PocketBase** — Auth, OAuth tokens, content state
- **Garage S3** — Store generated .mp4 files
- **Replicate** — Video generation (minimax/video-01)
- **Instagram Graph API** — OAuth + publish Reels
- **Antfly** — Self-hosted; index metadata for search (optional)
- **ffmpeg** — Video compositing (used by generation pipeline)

## pi-autoresearch

For the experimentation loop (A/B testing, winning templates), use [pi-autoresearch](https://github.com/davebcn87/pi-autoresearch):

```bash
pi install https://github.com/davebcn87/pi-autoresearch
/skill:autoresearch-create
```

Configure `autoresearch.md` with your content pipeline metrics (e.g. view-to-share ratio, engagement).
