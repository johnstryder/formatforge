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
   (Binary name is `<folder>-pb`, e.g. `formatforge-pb`. Older PB used `superuser upsert`; v0.23+ uses `admin create`.)
2. **Copy .env:** `cp .env.example .env` and fill in:
   - `ADMIN_EMAIL`, `ADMIN_PASSWORD`, `MIGRATE_SECRET`
   - `APP_VERSION` (e.g. `v1.0.27`)
   - `GARAGE_*` (S3-compatible storage)
   - `REPLICATE_API_TOKEN` (or `FAL_KEY` for fal.ai)
   - `FB_APP_ID`, `FB_APP_SECRET`, `INSTAGRAM_REDIRECT_URI` (for Instagram OAuth)
   - `ANTFLY_URL`, `ANTFLY_API_KEY` (optional, self-hosted Antfly for search/indexing)
   - **Antfly (optional):** The vendored **`antfly-src/`** tree has Docker, Kubernetes operator, and Minikube assets removed (native binaries only). Build/run from that README, or point `ANTFLY_URL` at the **Antfly metadata / store HTTP API** (host that serves `POST /api/v1/tables/{name}/batch`). `./scripts/init-antfly.sh` creates **`content`** (full-text + **semantic template** using **`remoteMedia`** on `media_url` so OpenRouter `EMBED_MODEL` can see image/video/audio URLs) and **`pipeline_refs`** (active pipeline `prompt_template` rows for semantic novelty). Vectors are computed **inside Antfly** (configure `OPENROUTER_API_KEY` on Antfly or pass `api_key` in table embedder JSON). If you already have an old `content` table (single-field `prompt` index only), **drop** `content` / `pipeline_refs` and re-run `init-antfly.sh`.
3. **Migrations:** Collections are created automatically from `pb_migrations/` when PocketBase starts. Restart the server to apply. The **`pipelines`** collection is listed and run from the dashboard; **create/update/delete** are **superuser-only** (Cursor agent / PocketBase admin), not app users.
   - If **`source_links`** rows have no `url` and the UI always shows **pending**, the collection likely existed before migrations ran (empty schema). Set `ADMIN_EMAIL` / `ADMIN_PASSWORD` in `.env`, then run **`php index.php repair-source-links-schema`**, delete broken rows via PocketBase Admin or **`php index.php delete-source-link`** (only when exactly one row exists; pass record id as second arg otherwise), and add links again.
4. **Create user:** PocketBase Admin → Collections → users → Create record (email + password).  
   For self-signup on the login page (when on Tailscale/internal network), set the users collection **Create** rule to empty or `@request.auth.id = ""` in PocketBase Admin. Or set `ALLOW_SIGNUP=1` in `.env` to always show the create-account form.

## Frontend

1. **Curate — Send links** — Paste URLs to queue as content sources
2. **Curate — Generated content** — View videos, approve or reject (with a dialog), publish to Instagram
3. **Pipelines** — Cursor **agent** (or admin) creates `pipelines` records; **Run** opens a dialog for optional extra instructions, then starts generation

## Integrations

- **PocketBase** — Auth, OAuth tokens, content state
- **Garage S3** — Store generated .mp4 files. Check signed uploads from the **same host as PHP**: `php index.php probe-garage`. Set **`GARAGE_ENDPOINT`** to a URL PHP can reach (often `http://127.0.0.1:3900` if Garage is on the same machine).
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
3. Spawns **`agent`** in the background ([Cursor CLI](https://cursor.com/cli)) with **`-p`**, **`--trust`**, **`-f`**, **`--model composer-2-fast`** by default ([Composer 2 / Cursor 2.0](https://cursor.com/blog/2-0))

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
# Cron: 0 */6 * * * cd /path/to/pipelines/pipeline-<id> && set -a && . .env && set +a && ./pipeline-generate
```

