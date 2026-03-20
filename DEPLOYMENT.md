# Deploy FormatForge to formatforgeplus.com (host only — no Docker)

Stack: **nginx**, **PHP-FPM** (8.2+), **PocketBase** binary, optional **certbot**, all on the **bare-metal or VM**. Your app code and `pb_data/` stay on disk. This project does **not** ship or target a containerized app runtime.

## Before you start

Ensure **ports 80 and 8090** (or whatever you map for HTTP and PocketBase) are free on the host. Keep **`pb_data/`**, **`.env`**, and the project tree when redeploying.

**nginx without PHP-FPM** still yields **502** on every PHP request — the stack needs both. See **§2. Packages** (`php-fpm` and extensions).

## Prerequisites

- VPS (Ubuntu 22.04+ recommended) with nginx and PHP-FPM installed
- Domain `formatforgeplus.com` pointed at the server (A record)

## 1. DNS

| Type | Name | Value   | TTL |
|------|------|---------|-----|
| A    | @    | YOUR_IP | 300 |
| A    | www  | YOUR_IP | 300 |

**Cloudflare:** Use **DNS only** (grey cloud) while issuing Let's Encrypt certificates; you can proxy again after.

## 2. Packages

```bash
sudo apt update
sudo apt install -y nginx php-fpm php-cli php-curl php-mbstring php-xml php-zip certbot
```

After packages install, **start** PHP-FPM (version varies by Ubuntu):

```bash
sudo systemctl enable --now php8.3-fpm   # or php8.2-fpm / php8.4-fpm
ls /run/php/*.sock
```

**Match nginx to the real socket** (avoids **502** from Cloudflare):

```bash
cd /var/www/formatforge
chmod +x scripts/*.sh
./scripts/align-php-fpm-socket.sh
sudo nginx -t && sudo systemctl reload nginx
```

That rewrites `nginx/fastcgi-pass.conf`, which `formatforgeplus.conf` includes.

## 3. App tree and PocketBase

```bash
sudo mkdir -p /var/www/formatforge
# Copy or clone the repo here; owner readable by www-data
sudo chown -R www-data:www-data /var/www/formatforge
```

As `www-data` (or deploy user + fix permissions):

```bash
cd /var/www/formatforge
cp .env.example .env   # if needed
./scripts/download-pocketbase.sh
```

The binary is named like `formatforge-pb` (parent folder name + `-pb`). Migrations in the repo must be visible to PocketBase:

```bash
mkdir -p pb_data
ln -sfn "$(pwd)/pb_migrations" pb_data/pb_migrations
```

Create the superuser if this is a fresh PocketBase data dir:

```bash
./formatforge-pb superuser upsert your@email.com 'your-secure-password'
```

(Or use `ADMIN_EMAIL` / `ADMIN_PASSWORD` from your existing `.env` and run `superuser upsert` once.)

### systemd (PocketBase on `127.0.0.1:8090`)

The PocketBase **binary** in this repo is **`formatforge-pb`** (parent folder name + `-pb`; see `./scripts/download-pocketbase.sh` / `./scripts/start.sh`). It is **not** a separate product called “formatforge-pocketbase” — that was only an old, confusing **systemd unit filename** in older docs.

Copy **`scripts/formatforge-pb.service.example`** to **`/etc/systemd/system/formatforge-pb.service`**, edit `User=`, `WorkingDirectory=`, and `ExecStart=` if your tree isn’t `/var/www/formatforge`, then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now formatforge-pb
```

You may name the unit file however you like; if yours differs, substitute that name in `systemctl` / `journalctl` commands below.

## 4. Configure `.env` (production)

```env
APP_URL=https://formatforgeplus.com
POCKETBASE_URL=http://127.0.0.1:8090
```

Use **`http://127.0.0.1:8090`** (or the host/port where PocketBase actually listens). Do not point PHP at a hostname that only resolves inside a container network.

Fill secrets as in `.env.example` (`MIGRATE_SECRET`, Garage, Replicate/fal, Instagram, etc.).

**Curate → Fetch:** install tools on the host and point `.env` if they are not on `PATH`:

```bash
pip install --user gallery-dl yt-dlp
# then e.g. GALLERY_DL_PATH=/home/deploy/.local/bin/gallery-dl
```

Instagram cookies: `storage/cookies/instagram_cookies.txt` or `cookies.txt` (see `storage/cookies/README.md`).

**Instagram Insights → `content_metrics`:** After publish, rows have `instagram_media_id`. From the app directory (with `.env` loaded), run **`php index.php sync-instagram-insights`** periodically (e.g. cron daily) so PocketBase gets likes, comments, impressions/views, shares. The long-lived Page/User token on each **`instagram_accounts`** record must include Meta **insights** permissions (e.g. `instagram_manage_insights` — check current Graph API docs). Some insight values appear after ~24–48h.

**Cursor Agent as `www-data`:** default **`HOME=/var/www`** is usually not writable, so **`agent login`** and the CLI fail with token/storage/`mkdir …/.cursor` errors. Create **`CURSOR_AGENT_HOME`** (e.g. `/var/lib/formatforge-cursor`), `chown` to **`www-data`**, run **`agent login`** with **`HOME`** and **`XDG_*`** set to paths under that dir (see **`.env.example`** / **README**), then set **`CURSOR_AGENT_HOME`** in **`.env`**. The **`agent`** file is a launcher; the real CLI lives in the **same directory** as **`index.js`** and **`node_modules`** — copy that **whole tree** (or run [cursor.com/install](https://cursor.com/install) on the server). It also needs **Node** on the host (`apt install nodejs` / NodeSource; symlink **`/usr/local/bin/node`** if needed — see **README**).

**Auto-sync CLI → `/opt/cursor-agent`:** when Cursor updates **`~/.local/share/cursor-agent/versions/`** on the deploy account, run **`./scripts/sync-cursor-agent-to-opt.sh`** (uses **`sudo`** for **`/opt`** unless you are root). **Cron (daily as root — no `sudo` password prompt):** copy **`scripts/cursor-agent-sync.cron.example`** to **`/etc/cron.d/formatforge-cursor-agent-sync`** (**`chmod 644`**). **`/etc/cron.d` job lines must be one physical line** — if an editor soft-wraps, vars like **`CURSOR_AGENT_SYNC_RELOAD_PHP`** and **`>>/var/log/...`** break; use the recommended **wrapper** **`scripts/cursor-agent-sync-root-invoke.sh.example`** → **`/usr/local/sbin/formatforge-cursor-agent-sync`** so the crontab stays short. **systemd** alternative: **`scripts/cursor-agent-sync.service.example`** + **`scripts/cursor-agent-sync.timer.example`**. Dry run: **`./scripts/sync-cursor-agent-to-opt.sh --dry-run`**.

## 5. nginx

The public site must be served by **this host’s nginx** (PHP-FPM + optional `/pb/` proxy). If DNS points here but something else answers, or PHP-FPM is down / socket mismatched, you’ll see **502** (from Cloudflare or from nginx directly).

### One-shot: enable `formatforgeplus` (recommended)

From your clone (e.g. `~/formatforge`):

```bash
sudo ./scripts/install-formatforge-nginx-site.sh
```

This symlinks the clone to **`/var/www/formatforge`**, copies **`nginx/formatforgeplus.conf`** into **`sites-available`**, links **`sites-enabled`**, removes **`default`**, installs **`/etc/nginx/conf.d/formatforge_pb_map.conf`** (PocketBase **`Connection`** / upgrade map — **required** for `/api/` and `/_/`), runs **`align-php-fpm-socket.sh`** when possible, **`nginx -t`s**, and **starts or reloads nginx**.

### **`/_/` (PocketBase admin) or `/api/` returns 502** but the PHP app works

The old vhost set **`Connection: upgrade`** on every proxied request. PocketBase’s own [production guide](https://pocketbase.io/docs/going-to-production/) expects an **empty** `Connection` for normal traffic; forcing **upgrade** commonly breaks the admin/API and nginx may report **502**.

1. Ensure the **map** exists and nginx was reloaded after **`formatforgeplus.conf`** started including **`pocketbase-proxy-common.conf`**:
   ```bash
   sudo cp /var/www/formatforge/nginx/snippets/formatforge_pb_map.conf /etc/nginx/conf.d/
   sudo nginx -t && sudo systemctl reload nginx
   ```
   If **`nginx -t`** errors on **`$formatforge_pb_connection`**, the map file is missing or not in **`http { }`** (Debian/Ubuntu: **`/etc/nginx/conf.d/*.conf`** is fine).
2. Confirm PocketBase is up: **`curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8090/api/health`**
3. If admin still misbehaves (redirects, assets), PocketBase **recommends a subdomain** instead of a path — see **`nginx/formatforge-pb-subdomain.conf.example`** and set **`POCKETBASE_PUBLIC_URL`** to that host.

**Paste trap:** If you copy `sudo cp /path/to/formatforgeplus.conf` and hit Enter before the destination on the same line, `cp` fails with *missing destination* and nothing is installed — use the script above or keep **source and dest on one line**.

If **`curl` to `127.0.0.1:80` fails** / `ss` shows no `:80` listener, nginx is stopped or crashed: `sudo systemctl status nginx` and `sudo journalctl -u nginx -n 40 --no-pager`.

**`curl: (2) no URL specified`:** the URL or `-H` value was split across lines (your terminal may also wrap **`formatforgeplus.com`** in the middle — the shell then sees two broken tokens). Use a short helper: **`./scripts/curl-local-formatforge.sh`** (no long line to paste), or type the URL on the **same line** as `curl`.

**PHP-FPM responds with `File not found.`** (plain text): nginx reached PHP, but **`www-data` cannot read `index.php`** through `/var/www/formatforge` when the real tree lives under **`/home/you/...`** and your home dir is **`700`/`750`**. Fix traverse bits, then reload nginx if needed:

```bash
sudo chmod o+x /home/youruser
sudo -u www-data test -r /var/www/formatforge/index.php && echo OK
```

### You see Apache’s “It works!” default page instead of FormatForge

That page is **`/var/www/html/index.html`** served by **Apache**, not nginx. Only one service should own **80** (and **443**). If Apache is installed, it often binds port 80 first.

On the server:

```bash
sudo ss -tlnp | grep -E ':80 |:443 '   # who is listening?
```

If **`apache2`** is on `:80` and you want nginx only:

```bash
sudo systemctl stop apache2
sudo systemctl disable apache2
sudo systemctl enable --now nginx
```

Then install your FormatForge site config (below), run `sudo nginx -t && sudo systemctl reload nginx`, and try again. If you must keep Apache for something else, move it off 80/443 and leave those ports for nginx.

### Apache is gone but you still see a “default” welcome page

Usually **nginx is running** and still serving **`/var/www/html`** from the **stock `default` site**:

- You open the site **by IP** or a hostname that does **not** match `server_name` in `formatforgeplus.conf` → nginx uses the **`default_server`** for port 80, which is often **`/etc/nginx/sites-enabled/default`**.
- Or **`formatforgeplus`** was never symlinked into **`sites-enabled/`**.

**Fix**

```bash
sudo rm -f /etc/nginx/sites-enabled/default
sudo cp /var/www/formatforge/nginx/formatforgeplus.conf /etc/nginx/sites-available/formatforgeplus
sudo ln -sf /etc/nginx/sites-available/formatforgeplus /etc/nginx/sites-enabled/formatforgeplus
sudo nginx -t && sudo systemctl reload nginx
```

Browse with your real domain (same as `server_name` in that file), or temporarily add `default_server` to the first `listen` lines in `formatforgeplus.conf` if you must test by IP.

**Still see the old Apache wording?** That is almost always **CDN or browser cache** (or DNS pointing at a **different** host). Try an incognito window, pause Cloudflare proxy (grey cloud) to bypass cache, and run on the box:

```bash
cd /path/to/formatforge && sudo ./scripts/diagnose-web-front.sh formatforgeplus.com
```

Use **`sudo ./scripts/...`** so `ss`, `nginx -T`, and `/etc/nginx/sites-enabled` are reliable (password once). If **`formatforgeplus` is still missing** from `sites-enabled`, the script prints exact **`sudo cp` / `ln -sf`** lines using your clone path.

If you have not yet installed the site config (same commands as **Fix** above):

```bash
sudo cp /var/www/formatforge/nginx/formatforgeplus.conf /etc/nginx/sites-available/formatforgeplus
sudo ln -sf /etc/nginx/sites-available/formatforgeplus /etc/nginx/sites-enabled/formatforgeplus
sudo rm -f /etc/nginx/sites-enabled/default
```

The shipped `root` is `/var/www/formatforge`. If your tree is elsewhere, change every `root` and the `include /var/www/formatforge/nginx/fastcgi-pass.conf` path in the active site config (or symlink `/var/www/formatforge` → your clone).

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### Cloudflare shows **502 Bad Gateway**

Cloudflare reached your origin, but **nginx returned 502** (or an empty/broken response). Most often:

1. **PHP-FPM is stopped** or **restarted with a new socket** while nginx still points at the old `unix:/run/php/...sock` → run `align-php-fpm-socket.sh` and reload nginx.
2. **`fastcgi_pass` path is wrong** — nginx `include` must point at the same clone as the file `align-php-fpm-socket.sh` just updated (often **`/var/www/formatforge/nginx/fastcgi-pass.conf`**).
3. **Only `/pb/` (e.g. **`/pb/_/`** admin) returns 502** — nginx’s **`proxy_pass`** to PocketBase failed. The main PHP app can be fine.

   **`curl: (7) Failed to connect to 127.0.0.1 port 8090`** means **nothing is listening** — usually **`formatforge-pb` is not running** or it **exits immediately** (check migrations). Start it:

   ```bash
   cd /var/www/formatforge   # or your clone
   ./formatforge-pb serve --http=127.0.0.1:8090 --dir=./pb_data --migrationsDir=./pb_migrations
   # or: sudo systemctl enable --now formatforge-pb
   ```

   If serve prints **`Dao is not defined`** or **`failed to apply migration`**, update **`pb_migrations/`** from this repo (FormatForge **v0.23+** PocketBase requires **`migrate((app) =>`**, not `Dao`), then try again. **`journalctl -u formatforge-pb -n 80`** shows the same errors under systemd.

   When PocketBase is healthy:

   ```bash
   curl -sS -o /dev/null -w '%{http_code}\n' http://127.0.0.1:8090/api/health   # expect 200
   ```

The origin nginx is up but **PHP-FPM is not connected** (wrong socket), **not running**, or **crashing**. On the server:

```bash
cd /path/to/your/formatforge/clone   # e.g. ~/formatforge or /var/www/formatforge
./scripts/diagnose-502.sh
sudo tail -40 /var/log/nginx/error.log
```

Look for `connect() to unix:/run/php/php8.x-fpm.sock failed`. Fix with:

```bash
sudo systemctl start php8.3-fpm   # use the version you installed — must match nginx/fastcgi-pass.conf
cd /path/to/your/formatforge/clone
./scripts/align-php-fpm-socket.sh
sudo nginx -t && sudo systemctl reload nginx
```

Run **`align-php-fpm-socket.sh` from the same tree** your nginx `include …/nginx/fastcgi-pass.conf` points at. If nginx still references `/var/www/formatforge` but you only keep the app in `~/formatforge`, either **`sudo ln -sfn ~/formatforge /var/www/formatforge`** or edit the site config so `root` and every `include .../nginx/` path use your real directory.

If errors mention **PocketBase** or **8090** on `/api/` or `/_/` only, run: `sudo systemctl status formatforge-pb` (or whatever you named the unit that runs **`formatforge-pb`**).

## 6. SSL (Let's Encrypt)

HTTP must serve the app first. Then:

```bash
sudo certbot certonly --webroot -w /var/www/formatforge -d formatforgeplus.com -d www.formatforgeplus.com
```

Uncomment the **HTTPS** `server` block in `nginx/formatforgeplus.conf`, set `ssl_certificate` paths, add `listen 443 ssl http2;` and the same `location` blocks as HTTP (see comments in that file). Reload nginx.

Optional HTTP → HTTPS: uncomment `return 301` in the `:80` server block after HTTPS works.

## 7. PocketBase admin (remote)

PocketBase should listen only on `127.0.0.1:8090`. **Through nginx** (`nginx/formatforgeplus.conf`), the API is at **`/api/`** and the admin UI at **`/_/`** (stryder.tech pattern).

- **In the browser (same host as the app):** open **`https://formatforgeplus.com/_/`**

**Optional:** SSH tunnel if you prefer localhost or `/api/`/`/_/` is blocked by a proxy:

```bash
ssh -L 8090:127.0.0.1:8090 user@formatforgeplus.com
# Open http://localhost:8090/_/
```

## 8. Renew certs

```bash
sudo certbot renew --dry-run
# crontab example: 0 0 1 * * certbot renew --quiet
```

---

**App URL:** https://formatforgeplus.com
