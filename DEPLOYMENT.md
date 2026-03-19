# Deploy FormatForge to formatforgeplus.com

## Prerequisites

- VPS or server with Docker (Ubuntu 22.04+ recommended)
- Domain formatforgeplus.com pointed to your server (A record)

## 1. DNS

Add an A record:

| Type | Name | Value      | TTL |
|------|------|------------|-----|
| A    | @    | YOUR_IP    | 300 |
| A    | www  | YOUR_IP    | 300 |

**If using Cloudflare:** Set both records to **DNS only** (grey cloud) before running certbot. Let's Encrypt must reach your server directly. After the cert is issued, you can re-enable the proxy (orange cloud).

## 2. Clone and configure

```bash
git clone <your-repo> formatforge
cd formatforge
cp .env.example .env
```

Edit `.env` for production:

```env
APP_URL=https://formatforgeplus.com
POCKETBASE_URL=http://pocketbase:8090

# Required
ADMIN_EMAIL=your@email.com
ADMIN_PASSWORD=secure-password
MIGRATE_SECRET=random-secret-string

# Instagram OAuth
FB_APP_ID=your-fb-app-id
FB_APP_SECRET=your-fb-app-secret
INSTAGRAM_REDIRECT_URI=https://formatforgeplus.com/instagram/callback

# Video generation (one of)
REPLICATE_API_TOKEN=...
# or
FAL_KEY=...

# Garage S3 (or MinIO, etc.)
GARAGE_ENDPOINT=http://garage:3900
GARAGE_ACCESS_KEY=...
GARAGE_SECRET_KEY=...
GARAGE_BUCKET=formatforge
GARAGE_REGION=garage
```

Add `formatforgeplus.com` and `www.formatforgeplus.com` to your Facebook app's **Valid OAuth Redirect URIs**.

## 3. Start with HTTP (for initial SSL)

```bash
docker compose -f docker-compose.production.yml up -d
```

## 4. Get SSL certificate (Let's Encrypt)

```bash
# Install certbot if needed
sudo apt install certbot

# Get cert (run from project root; -w must be the directory nginx serves)
sudo certbot certonly --webroot -w "$(pwd)" -d formatforgeplus.com -d www.formatforgeplus.com
```

Certs will be at `/etc/letsencrypt/live/formatforgeplus.com/`.

## 5. Enable HTTPS

1. Edit `nginx/formatforgeplus.conf`: uncomment the HTTPS `server` block.
2. Edit `docker-compose.production.yml`:
   - Uncomment `- "443:443"` under nginx ports
   - Uncomment `- /etc/letsencrypt:/etc/letsencrypt:ro` under nginx volumes
3. Restart:

```bash
docker compose -f docker-compose.production.yml up -d --force-recreate
```

## 6. Optional: HTTP → HTTPS redirect

Add to the HTTP server block in `nginx/formatforgeplus.conf` (after the acme-challenge location):

```nginx
location / {
    return 301 https://$host$request_uri;
}
```

(Remove or comment out the existing `try_files` in that block.)

## 7. PocketBase admin (remote)

PocketBase admin is bound to `127.0.0.1:8090` for security. To access remotely:

```bash
ssh -L 8090:127.0.0.1:8090 user@formatforgeplus.com
# Then open http://localhost:8090/_/
```

## 8. Auto-renew SSL

```bash
sudo certbot renew --dry-run   # Test
# Add to crontab: 0 0 1 * * certbot renew --quiet
```

---

**App URL:** https://formatforgeplus.com
