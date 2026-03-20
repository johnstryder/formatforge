# Deploy FormatForge to formatforgeplus.com (no Docker)

Stack: **nginx**, **PHP-FPM** (8.2+), **PocketBase** binary, optional **certbot**. Your app code and `pb_data/` stay on disk.

## Before you start

Ensure **ports 80 and 8090** (or whatever you map for HTTP and PocketBase) are free on the host. Keep **`pb_data/`**, **`.env`**, and the project tree when redeploying.

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

Copy `scripts/formatforge-pocketbase.service.example` to `/etc/systemd/system/formatforge-pocketbase.service`, edit `User=`, `WorkingDirectory=`, and `ExecStart=` paths, then:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now formatforge-pocketbase
```

## 4. Configure `.env` (production)

```env
APP_URL=https://formatforgeplus.com
POCKETBASE_URL=http://127.0.0.1:8090
```

Do **not** use `http://pocketbase:8090` — that hostname only existed inside Docker.

Fill secrets as in `.env.example` (`MIGRATE_SECRET`, Garage, Replicate/fal, Instagram, etc.).

**Curate → Fetch:** install tools on the host and point `.env` if they are not on `PATH`:

```bash
pip install --user gallery-dl yt-dlp
# then e.g. GALLERY_DL_PATH=/home/deploy/.local/bin/gallery-dl
```

Instagram cookies: `storage/cookies/instagram_cookies.txt` or `cookies.txt` (see `storage/cookies/README.md`).

## 5. nginx

```bash
sudo cp /var/www/formatforge/nginx/formatforgeplus.conf /etc/nginx/sites-available/formatforgeplus
sudo ln -sf /etc/nginx/sites-available/formatforgeplus /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
```

The shipped `root` is `/var/www/formatforge`. If your tree is elsewhere, change every `root` and the `include /var/www/formatforge/nginx/fastcgi-pass.conf` path in the active site config (or symlink `/var/www/formatforge` → your clone).

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### Cloudflare shows **502 Bad Gateway**

The origin nginx is up but **PHP-FPM is not connected** (wrong socket), **not running**, or **crashing**. On the server:

```bash
cd /var/www/formatforge && ./scripts/prod-check.sh
sudo tail -40 /var/log/nginx/error.log
```

Look for `connect() to unix:/run/php/php8.x-fpm.sock failed`. Fix with:

```bash
sudo systemctl start php8.3-fpm   # use the version you installed
./scripts/align-php-fpm-socket.sh
sudo nginx -t && sudo systemctl reload nginx
```

If errors mention **PocketBase** or **8090** on `/pb/` only, run: `sudo systemctl status formatforge-pocketbase`.

## 6. SSL (Let's Encrypt)

HTTP must serve the app first. Then:

```bash
sudo certbot certonly --webroot -w /var/www/formatforge -d formatforgeplus.com -d www.formatforgeplus.com
```

Uncomment the **HTTPS** `server` block in `nginx/formatforgeplus.conf`, set `ssl_certificate` paths, add `listen 443 ssl http2;` and the same `location` blocks as HTTP (see comments in that file). Reload nginx.

Optional HTTP → HTTPS: uncomment `return 301` in the `:80` server block after HTTPS works.

## 7. PocketBase admin (remote)

PocketBase should listen only on `127.0.0.1:8090`. Access:

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
