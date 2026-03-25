# FormatForge — Autonomous Content Pipeline

Single-file PHP dashboard for AI content generation, curation, and Instagram publishing (PHP + PocketBase + Alpine.js).

## Quick start

```bash
./scripts/download-pocketbase.sh   # one-time: latest PocketBase from GitHub (or: ./scripts/download-pocketbase.sh 0.36.7)
./scripts/start.sh                 # start PocketBase + PHP built-in server
```

**Production (formatforgeplus.com):** [DEPLOYMENT.md](DEPLOYMENT.md) — nginx + PHP-FPM + PocketBase **on the host** (this repo does not ship or require Docker for the app).

**Optional nginx:** `nginx/formatforge.conf` is a **host** sample (`127.0.0.1:8090` + PHP-FPM socket). For the public domain, prefer `nginx/formatforgeplus.conf`.

- **App:** http://127.0.0.1:8000 (`start.sh`) or your nginx `server_name`
- **PocketBase Admin:** With **`start.sh`**, http://127.0.0.1:8090/_/ (or whatever `.pb-port` says). **With nginx** (`formatforgeplus.conf`), the dashboard is at **`https://<your-domain>/_/`** — API at `/api/`, admin at `/_/` (stryder.tech pattern).

## Setup

1. **Create admin (PocketBase dashboard login):**  
   `./formatforge-pb migrate up` (first run / new `pb_data`), then  
   `./formatforge-pb admin create your@email.com 'yourpassword'`  
   (The PocketBase binary is named `<folder>-pb`, e.g. `formatforge-pb`.)
2. **Copy .env:** `cp .env.example .env` and fill in:
   - `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `MIGRATE_SECRET`
   - `APP_VERSION` (e.g. `v1.0.27`)
   - `GARAGE_*` (S3-compatible storage)
   - `REPLICATE_API_TOKEN` (or `FAL_KEY` for fal.ai)
   - `FB_APP_ID`, `FB_APP_SECRET`, `INSTAGRAM_REDIRECT_URI` (for Instagram OAuth)
   - `ANTFLY_URL`, `ANTFLY_API_KEY` (optional, self-hosted Antfly for search/indexing). **`ANTFLY_URL` in `.env` overrides a repo `.antfly-port` file** so PHP hits the same local Termite API you configured (stale port files used to win and break indexing).
   - **Antfly (optional):** The vendored **`antfly-src/`** tree has Docker, Kubernetes operator, and Minikube assets removed (native binaries only). Build/run from that README, or point `ANTFLY_URL` at the **Antfly metadata / store HTTP API** (host that serves `POST /api/v1/tables/{name}/batch`). `./scripts/init-antfly.sh` creates **`content`** (full-text + **semantic template** using **`remoteMedia`** on `media_url` so OpenRouter `EMBED_MODEL` can see image/video/audio URLs) and **`pipeline_refs`** (active pipeline `prompt_template` rows for semantic novelty). Vectors are computed **inside Antfly** (configure `OPENROUTER_API_KEY` on Antfly or pass `api_key` in table embedder JSON). If you already have an old `content` table (single-field `prompt` index only), **drop** `content` / `pipeline_refs` and re-run `init-antfly.sh`.
3. **Migrations:** Collections are created automatically from `pb_migrations/` when PocketBase starts. Restart the server to apply. The **`pipelines`** collection is listed and run from the dashboard; **create/update/delete** are **superuser-only** (Cursor agent / PocketBase admin), not app users.
   - If **`source_links`** rows have no `url` and the UI always shows **pending**, the collection likely existed before migrations ran (empty schema). Set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `.env`, then run **`php index.php repair-source-links-schema`**, delete broken rows via PocketBase Admin or **`php index.php delete-source-link`** (only when exactly one row exists; pass record id as second arg otherwise), and add links again.
4. **Create user:** PocketBase Admin → Collections → users → Create record (email + password).  
   For self-signup on the login page (when on Tailscale/internal network), set the users collection **Create** rule to empty or `@request.auth.id = ""` in PocketBase Admin. Or set `ALLOW_SIGNUP=1` in `.env` to always show the create-account form.

## Maintenance (CLI)

- **Stack connectivity:** `php index.php probe-stack` — from **this PHP process**, checks **`GET POCKETBASE_URL/api/health`**, **`GET POCKETBASE_PUBLIC_URL/api/health`** (when it differs from internal PB), **`GET GARAGE_ENDPOINT/`** (403/400 is OK without auth), **`GET GARAGE_PUBLIC_URL/`** when set, **`GET ANTFLY_URL/api/v1/tables`**, and (if Garage keys are set) a **SigV4 PUT** like **`probe-garage`**. Exit **0** only when required services respond. Use after deploy or when Antfly/embeddings/Garage “can’t connect”. **`POCKETBASE_URL`** in `.env` overrides **`.pb-port`** (same precedence pattern as **`ANTFLY_URL`** vs **`.antfly-port`**).
- **Antfly process:** the metadata API must be running for semantic novelty (`pipeline_refs`) and **`content`** indexing. Build once: **`cd antfly-src && go build -o ../bin/antfly ./cmd/antfly`**, then install **systemd**: **`sudo install -d -o www-data -g www-data /var/lib/formatforge-antfly`**, **`sudo cp scripts/formatforge-antfly.service /etc/systemd/system/`**, **`sudo systemctl daemon-reload && sudo systemctl enable --now formatforge-antfly`**. Manual run: **`./scripts/start-antfly.sh`**. Ensure **`.env.antfly`** is writable by **`www-data`** (e.g. **`sudo -u www-data python3 scripts/sync_antfly_env.py`**). Then **`./scripts/init-antfly.sh`** creates tables if missing.
- **Antfly wiring:** `php index.php antfly-status` — prints the resolved **`antfly_url`**, how it was chosen (`ANTFLY_URL` vs `.antfly-port` vs default), and whether tables **`content`** and **`pipeline_refs`** exist (run `./scripts/init-antfly.sh` after Antfly is up). Each successful **Fetch** logs **`antfly_content_index_ok`** in **`pipeline-trace.jsonl`** (`fetch_link_pipeline_summary`).
- **Clear all Curate data** (queued links + content items): `php index.php clear-curate-data` (dry-run: lists IDs) or `php index.php clear-curate-data --apply` — requires **`ADMIN_EMAIL`** / **`ADMIN_PASSWORD`** in `.env`. Does **not** delete `instagram_accounts`, `pipelines`, or `content_metrics`. Per collection: `delete-all-source-links` / `delete-all-content-items`.
- **PocketBase file field (`content_items.media_file`):** New fetches and pipeline-generated videos upload bytes to PocketBase as well as Garage; the app prefers **`https://<your-site>/api/files/<collectionId>/<recordId>/<filename>`** (nginx must proxy **`/api/`** to PocketBase). If an older database has no `media_file` field, run **`php index.php repair-content-items-media-schema`** (superuser) or restart PocketBase so `pb_migrations/` applies. Set **`POCKETBASE_CONTENT_ITEMS_COLLECTION_ID`** in `.env` if the UI still showed Garage **`*.sslip.io`** URLs (list API often omits `collectionId` per row). After upgrading, **`php index.php sync-pb-garage-urls --dry-run`** then **`--apply`** rewrites **`garage_url`** to the PocketBase file URL for rows that already have **`media_file`**.

## Frontend

1. **Curate — Send links** — Paste URLs to queue as content sources
2. **Curate — Generated content** — View videos, approve or reject (with a dialog), publish to Instagram
3. **Pipelines** — Cursor **agent** (or admin) creates `pipelines` records; **Run** opens a dialog for optional **source link** alignment + extra instructions, then starts generation

## Source alignment (all generation agents)

Text-to-video only sees the **prompt string**. One worker — **`formatforge_generate_content_finish`** in **`index.php`** (used by the web UI and the **`complete-generate`** CLI) — **merges** fetched Curate context **before** calling Replicate/fal when it can resolve a backing link:

- **`content_items.source_link_id`**, or
- **`pipelines.metadata.backing_source_link_id`**, **`default_source_link_id`**, or **`source_link_id`** (if the generating row has **`metadata.pipeline_id`** but no `source_link_id` on the item)

Any **agent** that creates a **`content_items`** row with **`status=generating`** and a resolvable link (UI, Go cron binary posting to PocketBase, future automations) gets the **same** alignment behavior — you do not rely on each pipeline’s prose alone. The merged prompt is **PATCH**ed onto the row and logged to **`pipeline-trace.jsonl`** as **`generate_content_backing_merged`**.

## Integrations

- **PocketBase** — Auth, OAuth tokens, content state; **`content_items.media_file`** stores a copy of media for browser-safe URLs via **`/api/files/…`** (same host as the app when proxied).
- **Garage S3** — Store generated .mp4 files. Check signed uploads from the **same host as PHP**: `php index.php probe-garage`. Set **`GARAGE_ENDPOINT`** to the S3 API URL PHP can reach (often `http://127.0.0.1:3900`). Set **`GARAGE_PUBLIC_URL`** *or* **`GARAGE_PUBLIC_ROOT_DOMAIN`** (app builds `https://{GARAGE_BUCKET}.web.{ROOT}`) so browsers never get `127.0.0.1` URLs. After fixing `.env`, run **`php index.php rewrite-garage-urls --dry-run`** then without `--dry-run` to PATCH existing **`content_items.garage_url`** from **`garage_key`**. To remove rows that still point at loopback (e.g. `http://127.0.0.1/...`): **`php index.php delete-bad-garage-urls`** (dry-run) then **`php index.php delete-bad-garage-urls --apply`**. That command also removes **`source_links`** when every **`content_items`** row for that link is loopback-only (so fetched-queue rows don’t linger). If you already deleted bad **`content_items`** without links, run **`php index.php delete-orphan-source-links`** then **`--apply`** (removes non-**pending** links with no **`content_items`**). If **FormatForge is HTTPS** but **`garage_url` values are `http://`**, browsers **block** embedded media (mixed content). Instagram **`video_url`** generally needs **HTTPS** and a URL Meta can fetch.
- **Replicate** — Video generation (minimax/video-01)
- **fal.ai** — Alternative video generation (Kling, LTX, etc.)
- **Instagram Graph API** — OAuth + publish Reels; **`php index.php sync-instagram-insights`** (or web `action=sync_instagram_insights` while logged in) PATCHes **`content_metrics`** with likes, comments, impressions/views, shares when the token has **insights** scopes. Some metrics lag ~24–48h per Meta.
- **Antfly** — Self-hosted search + **semantic index**: `content` docs carry **`media_url`** (Garage/public URL) plus text fields; Antfly’s embedding template calls **`remoteMedia`** then concatenates text (see `antfly_create_content_table` / `init-antfly.sh`). **Pipeline novelty** (whether to spawn Cursor to create a pipeline) uses **Antfly semantic query** on table **`pipeline_refs`** (synced from active PocketBase pipelines), not `embed_text()` in PHP.
- **ffmpeg** — Video compositing (used by generation pipeline)
- **Fetch (Curate)** — One **Fetch** button, no choosers: tries **direct HTTP** when the URL looks like a file, then **gallery-dl**, then **yt-dlp**.
- **gallery-dl / yt-dlp** — Install on the server (e.g. `pip install --user gallery-dl yt-dlp`) and set **`GALLERY_DL_PATH`** / **`YT_DLP_PATH`** in `.env` if they are not on `PATH`. For **Instagram**, add Netscape cookies as **`storage/cookies/instagram_cookies.txt`** or **`storage/cookies/cookies.txt`** (see `storage/cookies/README.md`).
- **Direct URLs** — `.png`, `.jpg`, `.mp4`, etc. (e.g. Garage/S3) use HTTP from the app server first

**Upgrade (old `.pi/`):** If you already have data under **`.pi/triggers`** or **`.pi/prompts`**, move it to **`.cursor-pipeline/triggers`** and **`.cursor-pipeline/prompts`**, or set **`PI_TRIGGER_DIR`** / **`CURSOR_PIPELINE_TRIGGER_DIR`** to your old path until you migrate.

## Autonomous pipeline triggers (Cursor Agent CLI)

After **Fetch**, if Antfly reports the item as **novel** vs synced **`pipeline_refs`** (semantic distance above **`NOVEL_DISTANCE_THRESHOLD`**), or there are **no active pipelines**, **PHP** (same request as the Curate POST) queues Cursor to **create** a pipeline. After **three consecutive rejects** for the same `metadata.pipeline_id`, **PHP** queues Cursor to **edit** that pipeline. No separate scheduler is required for this kick-off.

1. Writes a trigger file to **`.cursor-pipeline/triggers/`** (or `CURSOR_PIPELINE_TRIGGER_DIR`)
2. Runs `setup_pipeline_from_trigger()` (in `index.php`) which:
   - Creates `pipelines/pipeline-<id>/` from the template
   - Copies `.env` (Replicate, PocketBase, Garage, login) into the pipeline dir
   - Writes **`.cursor-pipeline/prompts/pipeline-<id>.md`** — task prompt for the agent (same repo; Cursor `--workspace` is the project root)
3. Spawns **`agent`** in the background ([Cursor CLI](https://cursor.com/cli)) with **`-p`**, **`--trust`**, **`-f`**, **`--model composer-2`** by default ([Composer 2](https://cursor.com/docs/models/cursor-composer-2))

The Markdown **`## Context`** block adds **`operating_context`**: latest **`pipelines`** rows, **`target_posts_per_day`** (from **`TARGET_POSTS_PER_DAY`**) vs **`published_count_last_24h`**, recent published items plus **`content_metrics_by_item_id`** when those columns are populated (run **`sync-instagram-insights`** or the web sync so likes/impressions/etc. are filled after publish), and small samples of fetched vs pipeline-generated **`content_items`**. For **novel** fetches it also adds **`semantic_nearest_pipeline_to_this_fetch`** and a **`semantic_novelty_explainer`**.

**Setup:**
1. Install the **full** Cursor CLI on the server — not just a single file. Official one-liner: [cursor.com/install](https://cursor.com/install) (`curl … | bash`). In **`.env`**, set **`CURSOR_AGENT_BIN`** to the **`agent` launcher inside that install** (whatever path the installer prints). If you **copy** from another machine, copy the **entire directory** that contains **`index.js`** next to **`agent`** (see **`Cannot find module … index.js`** below). Avoid **`sudo cp …/agent /usr/local/bin`** alone — that leaves **`index.js`** behind.
2. **Auth:** `agent login` is enough (no `CURSOR_API_KEY` required). Run login **as `www-data`**, with an explicit binary path.

**`/var/www` not writable (`EACCES` on `.cursor`, “Failed to store authentication tokens”):** `www-data` often has **`HOME=/var/www`**, but **`/var/www` is root-owned (755)** — the CLI cannot create **`~/.cursor`** or write tokens. Create a dedicated directory and use it for login **and** in `.env` as **`CURSOR_AGENT_HOME`** (FormatForge exports `HOME` + `XDG_*` for the spawned agent):

```bash
sudo install -d -o www-data -g www-data /var/lib/formatforge-cursor
sudo -u www-data env HOME=/var/lib/formatforge-cursor \
  XDG_CONFIG_HOME=/var/lib/formatforge-cursor/.config \
  XDG_DATA_HOME=/var/lib/formatforge-cursor/.local/share \
  XDG_STATE_HOME=/var/lib/formatforge-cursor/.local/state \
  XDG_CACHE_HOME=/var/lib/formatforge-cursor/.cache \
  /usr/local/bin/agent login   # or: CURSOR_AGENT_BIN path
```

Then set **`CURSOR_AGENT_HOME=/var/lib/formatforge-cursor`** in **`.env`**.

**`Cannot find module '/usr/local/bin/index.js'`:** the **`agent`** file is a thin launcher; Node loads **`index.js`** from the **same directory** (plus **`node_modules`**, native `.node` addons, etc.). Copying only **`agent`** to **`/usr/local/bin`** omits the rest. **Fix:** (a) Run the [official installer](https://cursor.com/install) **on the server** and use the **`agent`** path it installs. (b) Or on a machine where **`agent` works**, find the install root (it must contain **`index.js`**):

```bash
AGENT_DIR=$(dirname "$(readlink -f "$(command -v agent)")")
echo "$AGENT_DIR"
ls "$AGENT_DIR"   # expect index.js, node_modules, …
```

**Rsync/scp that whole directory** to e.g. **`/opt/cursor-agent`** on the server, then **`sudo chmod -R a+rX /opt/cursor-agent`**, set **`CURSOR_AGENT_BIN=/opt/cursor-agent/cursor-agent`** (or the real launcher name in that folder), and run **`agent login`** as **`www-data`** with **`CURSOR_AGENT_HOME`** / **`XDG_*`** as above. To refresh **`/opt/cursor-agent`** whenever Cursor ships a new local version, run **`./scripts/sync-cursor-agent-to-opt.sh`**, or install **cron** (**`scripts/cursor-agent-sync.cron.example`** + **`cursor-agent-sync-root-invoke.sh.example`**) / **systemd** (**`scripts/cursor-agent-sync.*.example`**) — see **`DEPLOYMENT.md`** (cron lines must not wrap).

**`/usr/local/bin/node: No such file or directory`:** the launcher runs **Node**. Install Node on the server (**`sudo apt install -y nodejs`** or [NodeSource](https://github.com/nodesource/distributions) LTS), check **`head -25 "$(command -v agent)"`**, and if it expects **`/usr/local/bin/node`** but **`node`** is **`/usr/bin/node`**, run **`sudo ln -sf "$(command -v node)" /usr/local/bin/node`**.

**`sudo: agent: command not found`:** `sudo -u www-data` uses a **short `PATH`** and often **cannot see** `agent` in your home directory. Use a **full path** in **`CURSOR_AGENT_BIN`**. If **`agent`** lives under **`/home/you/`**, either **`chmod o+x /home/you`** so **`www-data`** can traverse (less ideal) or install under **`/opt/…`** as above. Optional: **`CURSOR_API_KEY`** in **`.env`** for non-interactive setups.
3. **`CURSOR_PIPELINE_TRIGGER_DIR`** in `.env` if you want a non-default path (default: **`.cursor-pipeline/triggers`**; legacy **`PI_TRIGGER_DIR`** still works)
4. **`ANTFLY_URL`** + run `./scripts/init-antfly.sh` (tables **`content`** + **`pipeline_refs`**). Antfly needs OpenRouter for the table embedder (`EMBED_MODEL`, API key in Antfly env or embedder JSON). PHP **`OPENROUTER_API_KEY`** is still used for `embed_text()` in **`php index.php test-embed`** only.
5. Optional: `CURSOR_AGENT_MODEL`, `CURSOR_AGENT_ENABLED=0` to disable auto-spawn

**Manual prep (no agent):** `php index.php setup-pipeline [trigger_file]` (uses latest trigger if no arg)

**Manual agent:** `php index.php cursor-agent-run .cursor-pipeline/prompts/pipeline-<id>.md`

**Reset PocketBase (dev):** Stop `formatforge-pb`, remove `pb_data/*.db` (and `types.d.ts` if present), keep `pb_data/pb_migrations` → `../pb_migrations`, then start PocketBase and create a new superuser + app user.

**Antfly env from FormatForge:** `python3 scripts/sync_antfly_env.py` writes **`.env.antfly`** (repo root, gitignored). Point **systemd** `EnvironmentFile=` at it, or run `set -a && source .env.antfly && set +a` before starting Termite. Trims stray trailing spaces on secret lines in the root **`.env`**.

**PocketBase `pipelines` collection:** The agent (or PocketBase Admin as superuser) should **create/update** those records. The web dashboard only **lists and runs** them.

**Go pipeline:** Each pipeline dir has its own `.env`. Build and cron:

```bash
cd pipelines/pipeline-<id> && go build -o pipeline-generate .
# Cron: 0 * * * * cd /path/to/pipelines/pipeline-<id> && set -a && . .env && set +a && ./pipeline-generate
```

