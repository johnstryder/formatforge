<?php
/**
 * FormatForge - Autonomous Content Generation & Curation Pipeline
 * Single-file: PocketBase + Alpine.js + Replicate + Garage S3 + Instagram + Antfly
 * Frontend: (1) Send links for content sources, (2) Curate generated content
 */

session_start();

if (file_exists(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            putenv(trim($m[1]) . '=' . trim($m[2], " \t\"'"));
        }
    }
}

/**
 * PocketBase API URL for PHP (pb_request). Prefer POCKETBASE_URL from .env; else .pb-port; else 127.0.0.1:8090.
 *
 * @return array{url: string, source: string}
 */
function ff_resolve_pocketbase_url_meta(): array {
    $env = trim((string) (getenv('POCKETBASE_URL') ?: ''));
    if ($env !== '') {
        return ['url' => rtrim($env, '/'), 'source' => 'POCKETBASE_URL'];
    }
    $portFile = __DIR__ . '/.pb-port';
    if (is_file($portFile)) {
        $port = trim((string) (@file_get_contents($portFile) ?: ''));
        if ($port !== '' && preg_match('/^\d{1,5}$/', $port)) {
            $p = (int) $port;
            if ($p > 0 && $p <= 65535) {
                return ['url' => 'http://127.0.0.1:' . $port, 'source' => '.pb-port'];
            }
        }
    }
    return ['url' => 'http://127.0.0.1:8090', 'source' => 'default'];
}

/**
 * Low-level HTTP reachability (TCP + HTTP response). Used by probe-stack and diagnostics.
 *
 * @return array{reachable: bool, http_code: int, curl_errno: int, curl_error: string}
 */
function ff_probe_http(string $url, array $headers = [], int $timeoutSec = 10, bool $headOnly = false): array {
    $url = trim($url);
    if ($url === '') {
        return ['reachable' => false, 'http_code' => 0, 'curl_errno' => -1, 'curl_error' => 'empty url'];
    }
    $ch = curl_init($url);
    $h = $headers;
    if ($headOnly) {
        $h[] = 'Accept: */*';
    } else {
        $h[] = 'Accept: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSec),
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_NOBODY => $headOnly,
    ]);
    curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return [
        'reachable' => $errno === 0,
        'http_code' => $code,
        'curl_errno' => $errno,
        'curl_error' => $err,
    ];
}

/**
 * CLI: verify PHP can reach PocketBase, Garage (S3 + optional public), and Antfly — same paths as runtime + Antfly remoteMedia.
 *
 * @return int exit code (0 = all required checks passed)
 */
function ff_cli_probe_stack_connectivity(): int {
    $cfg = $GLOBALS['CONFIG'];
    $pb = rtrim((string) ($cfg['pocketbase_url'] ?? ''), '/');
    $pbPub = rtrim((string) ($cfg['pocketbase_public_url'] ?? ''), '/');
    $garEp = rtrim((string) ($cfg['garage_endpoint'] ?? ''), '/');
    $garPub = rtrim((string) ($cfg['garage_public_url'] ?? ''), '/');
    $af = rtrim((string) ($cfg['antfly_url'] ?? ''), '/');
    $pbSrc = (string) ($cfg['pocketbase_url_resolution'] ?? '');
    $afSrc = (string) ($cfg['antfly_url_resolution'] ?? '');

    echo "=== FormatForge stack connectivity (this PHP process) ===\n\n";
    $failed = 0;

    $line = function (string $name, string $detail, bool $ok) use (&$failed): void {
        $st = $ok ? 'OK' : 'FAIL';
        echo "[{$st}] {$name}{$detail}\n";
        if (!$ok) {
            $failed++;
        }
    };

    // PocketBase (internal API URL)
    $pbHealth = $pb !== '' ? $pb . '/api/health' : '';
    if ($pbHealth === '') {
        $line('pocketbase', ' — no pocketbase_url', false);
    } else {
        $r = ff_probe_http($pbHealth, [], 12, false);
        $ok = $r['reachable'] && $r['http_code'] === 200;
        $line('pocketbase', " {$pb} (source: {$pbSrc})\n       GET /api/health → HTTP {$r['http_code']}" . ($r['curl_error'] !== '' ? " curl: {$r['curl_error']}" : ''), $ok);
    }

    // PocketBase public (browser / nginx proxy — Antfly remoteMedia may use /api/files/… on this host)
    if ($pbPub !== '' && $pbPub !== $pb) {
        $u = $pbPub . '/api/health';
        $r = ff_probe_http($u, [], 12, false);
        $ok = $r['reachable'] && $r['http_code'] === 200;
        $line('pocketbase_public', " {$pbPub}\n       GET /api/health → HTTP {$r['http_code']}" . ($r['curl_error'] !== '' ? " curl: {$r['curl_error']}" : ''), $ok);
    } elseif ($pbPub !== '') {
        echo "[skip] pocketbase_public — same base as pocketbase ({$pbPub})\n";
    }

    // Garage S3 API (SigV4 uploads)
    if ($garEp === '') {
        $line('garage_s3', ' — GARAGE_ENDPOINT empty', false);
    } else {
        $r = ff_probe_http($garEp . '/', [], 10, false);
        $code = $r['http_code'];
        // Unauthenticated S3 often returns 403/400; any HTTP response means TCP + TLS OK.
        $ok = $r['reachable'] && $code >= 200 && $code < 600;
        $line('garage_s3', " {$garEp}\n       GET / → HTTP {$code} (403/400 is normal without auth)" . ($r['curl_error'] !== '' ? " curl: {$r['curl_error']}" : ''), $ok);
    }

    // Garage public web (browser / Instagram / optional Antfly fetch of public object URLs)
    if ($garPub !== '') {
        $r = ff_probe_http($garPub . '/', [], 12, false);
        $code = $r['http_code'];
        $ok = $r['reachable'] && $code >= 200 && $code < 600;
        $line('garage_public', " {$garPub}\n       GET / → HTTP {$code}" . ($r['curl_error'] !== '' ? " curl: {$r['curl_error']}" : ''), $ok);
    } else {
        echo "[skip] garage_public — set GARAGE_PUBLIC_URL or GARAGE_PUBLIC_ROOT_DOMAIN for public object URLs\n";
    }

    // Antfly Termite API
    if ($af === '') {
        $line('antfly', ' — antfly_url empty', false);
    } else {
        $headers = ['Accept: application/json'];
        if (!empty($cfg['antfly_key'])) {
            $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
        }
        $r = ff_probe_http($af . '/api/v1/tables', $headers, 15, false);
        $ok = $r['reachable'] && $r['http_code'] >= 200 && $r['http_code'] < 300;
        $line('antfly', " {$af} (source: {$afSrc})\n       GET /api/v1/tables → HTTP {$r['http_code']}" . ($r['curl_error'] !== '' ? " curl: {$r['curl_error']}" : ''), $ok);
    }

    // Optional: signed S3 PUT (same as probe-garage)
    if (!empty($cfg['garage_key']) && !empty($cfg['garage_secret'])) {
        $key = '_formatforge_stack_probe_' . gmdate('Ymd\THis\Z') . '.txt';
        $putUrl = s3_upload($key, 'stack probe ' . gmdate('c'), 'text/plain');
        if ($putUrl) {
            echo "[OK] garage_signed_put — SigV4 PUT test object (same as probe-garage)\n       key: {$key}\n";
        } else {
            echo "[FAIL] garage_signed_put — s3_upload() returned null (check bucket/keys/region)\n";
            $failed++;
        }
    } else {
        echo "[skip] garage_signed_put — set GARAGE_ACCESS_KEY / GARAGE_SECRET_KEY for full S3 write test\n";
    }

    echo "\n--- Antfly ↔ media URLs (embeddings / remoteMedia) ---\n";
    echo "Antfly fetches `media_url` you index (often PocketBase /api/files/… or Garage public). ";
    echo "It runs on the same host as PHP in typical installs — if this probe passed, Termite can usually reach the same loopback URLs. ";
    echo "If `media_url` uses a public hostname, ensure nothing blocks Antfly from that host (firewall/DNS).\n";

    echo "\n=== " . ($failed === 0 ? 'All required checks passed.' : "Done with {$failed} failure(s).") . " ===\n";
    return $failed === 0 ? 0 : 1;
}

/**
 * Resolve gallery-dl / yt-dlp executable. php-fpm often has a minimal PATH — bare names get 127.
 * Tries: env (if absolute & valid), then /usr/bin, /usr/local/bin, ~/.local/bin, else bare name for PATH.
 */
function ff_resolve_fetch_bin(string $envVar, string $fallbackName): string {
    $raw = getenv($envVar);
    $path = ($raw !== false && trim((string)$raw) !== '') ? trim((string)$raw) : $fallbackName;
    if (str_starts_with($path, '/')) {
        return (is_file($path) && is_executable($path)) ? $path : ff_resolve_fetch_bin_expand_bare($fallbackName);
    }
    return ff_resolve_fetch_bin_expand_bare($path);
}

function ff_resolve_fetch_bin_expand_bare(string $name): string {
    $resolved = ff_fetch_executable($name, $name);
    return $resolved !== '' ? $resolved : $name;
}

/** PATH= prefix so sh finds /usr/bin even when php-fpm clears PATH (fixes exit 127). */
function ff_fetch_path_env_prefix(): string {
    $parts = ['/opt/ff-fetch/bin', '/usr/bin', '/usr/local/bin', '/bin', '/sbin'];
    $home = getenv('HOME');
    if (is_string($home) && $home !== '') {
        array_unshift($parts, rtrim($home, '/') . '/.local/bin');
    }
    $existing = getenv('PATH');
    if (is_string($existing) && $existing !== '') {
        $parts[] = $existing;
    }
    return 'PATH=' . escapeshellarg(implode(':', $parts)) . ' ';
}

/** PATH + PYTHONPATH for pip --prefix installs (php-fpm clears env; entrypoint exports do not reach workers). */
function ff_fetch_env_prefix(): string {
    $out = ff_fetch_path_env_prefix();
    $sites = @glob('/opt/ff-fetch/lib/python3.*/site-packages', GLOB_ONLYDIR) ?: [];
    if ($sites !== [] && is_dir($sites[0])) {
        $out = 'PYTHONPATH=' . escapeshellarg($sites[0]) . ' ' . $out;
    }
    return $out;
}

/**
 * Executable path for gallery-dl / yt-dlp at run time (open_basedir may hide /usr/bin at CONFIG time).
 * @param string $configured value from CONFIG / env
 * @param string $fallbackName e.g. gallery-dl
 */
function ff_fetch_executable(string $configured, string $fallbackName): string {
    $configured = trim($configured);
    $fallbackName = trim($fallbackName) ?: 'gallery-dl';
    $base = $fallbackName;
    if ($configured !== '') {
        $base = str_starts_with($configured, '/') ? basename($configured) : $configured;
    }
    if ($base === '' || $base === '.' || $base === '..') {
        $base = $fallbackName;
    }
    $candidates = [];
    if ($configured !== '' && str_starts_with($configured, '/')) {
        $candidates[] = $configured;
    }
    $candidates[] = '/opt/ff-fetch/bin/' . $base;
    $candidates[] = '/usr/bin/' . $base;
    $candidates[] = '/usr/local/bin/' . $base;
    $home = getenv('HOME');
    if (is_string($home) && $home !== '') {
        $candidates[] = rtrim($home, '/') . '/.local/bin/' . $base;
    }
    $seen = [];
    foreach ($candidates as $c) {
        if ($c === '' || isset($seen[$c])) {
            continue;
        }
        $seen[$c] = true;
        if (@is_file($c) && @is_executable($c)) {
            return $c;
        }
    }
    return $base;
}

/** First readable cookies file in storage/cookies (uploads often named cookies.txt). */
function ff_pick_storage_cookie_file(): string {
    $dir = __DIR__ . '/storage/cookies';
    foreach (['instagram_cookies.txt', 'cookies.txt'] as $name) {
        $p = $dir . '/' . $name;
        if (is_file($p) && is_readable($p) && filesize($p) > 32) {
            return $p;
        }
    }
    return '';
}

/**
 * Non-secret diagnostics for Curate → Fetch (why gallery-dl/yt-dlp had no --cookies).
 * Key names must not contain "cookie" (ff_debug_sanitize redacts those keys).
 */
function ff_fetch_auth_file_status(): array {
    $cfg = $GLOBALS['CONFIG'];
    $gd = trim((string) ($cfg['gallery_dl_cookies'] ?? ''));
    $yt = trim((string) ($cfg['yt_dlp_cookies'] ?? ''));
    $dir = __DIR__ . '/storage/cookies';
    $storage = [];
    foreach (['instagram_cookies.txt', 'cookies.txt'] as $name) {
        $p = $dir . '/' . $name;
        $sz = (is_file($p) && is_readable($p)) ? @filesize($p) : null;
        $storage[] = [
            'filename' => $name,
            'path' => $p,
            'exists' => is_file($p),
            'readable' => is_file($p) && is_readable($p),
            'size_bytes' => $sz,
            'large_enough' => $sz !== null && $sz > 32,
        ];
    }
    $usable = $gd !== '' && is_file($gd) && is_readable($gd) && (int) @filesize($gd) > 32;
    $who = null;
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $pw = @posix_getpwuid(posix_geteuid());
        $who = is_array($pw) ? ($pw['name'] ?? null) : null;
    }
    return [
        'gallery_dl_netscape_path' => $gd === '' ? null : $gd,
        'yt_dlp_netscape_path' => $yt === '' ? null : $yt,
        'usable_netscape_for_fetch' => $usable,
        'storage_netscape_files' => $storage,
        'php_effective_user' => $who,
    ];
}

/**
 * Writable tree for Cursor triggers, prompts, trace JSONL, agent log.
 * Prefers `.cursor-pipeline/`; if PHP cannot write there (common under www-data), falls back to `storage/cursor-pipeline/`.
 * Override with CURSOR_PIPELINE_RUNTIME_DIR (must stay under the app root).
 */
function ff_cursor_pipeline_runtime_base(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $root = __DIR__;
    $rootReal = realpath($root);
    $rootPrefix = ($rootReal !== false) ? $rootReal : $root;
    $candidates = [];
    $envRun = getenv('CURSOR_PIPELINE_RUNTIME_DIR');
    if (is_string($envRun) && trim($envRun) !== '') {
        $norm = trim($envRun);
        if ($norm[0] !== '/' && strpos($norm, ':\\') === false) {
            $norm = $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($norm, '/\\'));
        }
        $ancestor = $norm;
        while ($ancestor !== $root && $ancestor !== dirname($ancestor) && !is_dir($ancestor)) {
            $ancestor = dirname($ancestor);
        }
        $ar = realpath($ancestor);
        if ($ar !== false && str_starts_with($ar, $rootPrefix)) {
            $candidates[] = $norm;
        }
    }
    $candidates[] = $root . DIRECTORY_SEPARATOR . '.cursor-pipeline';
    $candidates[] = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cursor-pipeline';

    foreach ($candidates as $base) {
        if (!is_dir($base)) {
            @mkdir($base, 0775, true);
        }
        $tr = $base . DIRECTORY_SEPARATOR . 'triggers';
        $pr = $base . DIRECTORY_SEPARATOR . 'prompts';
        if (!is_dir($tr)) {
            @mkdir($tr, 0775, true);
        }
        if (!is_dir($pr)) {
            @mkdir($pr, 0775, true);
        }
        if (is_dir($tr) && is_writable($tr) && is_dir($pr) && is_writable($pr)) {
            $cached = $base;
            return $cached;
        }
    }
    $cached = $candidates[0];
    return $cached;
}

/** Repo-local Cursor automation dir (triggers, prompts, agent log) — same tree as runtime base. */
function ff_cursor_pipeline_dir(): string {
    return ff_cursor_pipeline_runtime_base();
}

function ff_cursor_agent_runs_dir(): string {
    return ff_cursor_pipeline_dir() . DIRECTORY_SEPARATOR . 'runs';
}

/** Absolute path prefix for prompt `.md` files (must stay under ff_cursor_pipeline_dir()). */
function ff_cursor_pipeline_prompts_dir(): string {
    return ff_cursor_pipeline_dir() . DIRECTORY_SEPARATOR . 'prompts';
}

/** Default Cursor CLI `--model` for pipeline agents when `CURSOR_AGENT_MODEL` is unset. */
function ff_cursor_agent_model_default(): string {
    return 'google/nano-banana-pro';
}

/** Resolved model id from CONFIG (always non-empty). */
function ff_cursor_agent_model_from_cfg(array $cfg): string {
    $m = trim((string)($cfg['cursor_agent_model'] ?? ''));
    return $m !== '' ? $m : ff_cursor_agent_model_default();
}

/**
 * Markdown snippet placed after `--model …` in pipeline Cursor prompts (docs link or capability note).
 */
function ff_cursor_agent_model_prompt_parenthetical(string $model): string {
    $m = trim((string)$model) ?: ff_cursor_agent_model_default();
    $lc = strtolower($m);
    if ($lc === 'composer-2') {
        return '([Composer 2](https://cursor.com/docs/models/cursor-composer-2))';
    }
    if ($lc === 'google/nano-banana-pro') {
        return '(**`google/nano-banana-pro`** — Cursor passes this as **`--model`**; it is **image-capable**. You **may edit, composite, regenerate, and analyze images** in this session when that clarifies pipeline work or fixes visuals, alongside editing Go and locking **Replicate/fal** version ids for production generation.)';
    }
    $safe = str_replace(["`", "\n", "\r"], '', $m);
    return '(**`' . $safe . '**)';
}

/** Append-only JSONL for pipeline / Cursor agent decisions (readable from Pipelines tab). */
function ff_pipeline_trace_path(): string {
    return ff_cursor_pipeline_dir() . DIRECTORY_SEPARATOR . 'pipeline-trace.jsonl';
}

function ff_pipeline_trace_log(string $event, array $context = []): void {
    $path = ff_pipeline_trace_path();
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return;
    }
    $line = json_encode([
        'ts' => date('c'),
        'event' => $event,
        'context' => ff_debug_sanitize($context),
    ], JSON_UNESCAPED_SLASHES) . "\n";
    @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    $sz = @filesize($path);
    if ($sz !== false && $sz > 600000) {
        $data = @file_get_contents($path);
        if ($data !== false && strlen($data) > 400000) {
            @file_put_contents($path, substr($data, -400000), LOCK_EX);
        }
    }
}

function ff_read_tail_bytes(string $path, int $maxBytes): string {
    if (!is_readable($path)) {
        return '';
    }
    $sz = @filesize($path);
    if ($sz === false || $sz <= $maxBytes) {
        return (string) @file_get_contents($path);
    }
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return '';
    }
    fseek($fp, -$maxBytes, SEEK_END);
    $out = fread($fp, $maxBytes);
    fclose($fp);
    return is_string($out) ? $out : '';
}

/** Last $maxBytes of file (always from EOF). Unlike ff_read_tail_bytes, never returns the whole file when small — avoids stale leading noise in “tail” UIs. */
function ff_read_tail_bytes_seek_end(string $path, int $maxBytes): string {
    if (!is_readable($path) || $maxBytes < 1) {
        return '';
    }
    $sz = @filesize($path);
    if ($sz === false || $sz < 1) {
        return '';
    }
    $n = (int) min($maxBytes, $sz);
    $fp = @fopen($path, 'rb');
    if (!$fp) {
        return '';
    }
    fseek($fp, -$n, SEEK_END);
    $out = fread($fp, $n);
    fclose($fp);
    return is_string($out) ? $out : '';
}

/**
 * Strip trailing php-fpm/php-cgi "Usage:" dumps from agent log excerpts (wrong binary invoked as CLI appends noise).
 *
 * @return array{text: string, note: ?string}
 */
function ff_sanitize_cursor_agent_log_excerpt(string $raw): array {
    $raw = (string) $raw;
    if ($raw === '') {
        return ['text' => '', 'note' => null];
    }
    $marker = 'Usage: php-fpm';
    $pos = strripos($raw, $marker);
    if ($pos !== false) {
        $tailLen = strlen($raw) - $pos;
        if ($tailLen > 0 && $tailLen < 14000) {
            $before = substr($raw, 0, $pos);
            if (trim($before) !== '') {
                return ['text' => rtrim($before), 'note' => 'Trailing php-fpm usage text was removed from this excerpt (wrong CLI binary or stale log). Set FORMATFORGE_PHP_CLI or PHP_CLI_BINARY=/usr/bin/php for FPM workers.'];
            }
            return [
                'text' => "(Log tail was only php-fpm usage text — set FORMATFORGE_PHP_CLI or PHP_CLI_BINARY to /usr/bin/php. See DEPLOYMENT.md.)\n",
                'note' => 'Log tail was replaced; configure PHP CLI for spawned workers.',
            ];
        }
    }
    return ['text' => $raw, 'note' => null];
}

/** Recent files in a directory (newest mtime first). */
function ff_list_dir_files_recent(string $dir, int $limit = 25): array {
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }
    $pairs = [];
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $pairs[] = ['name' => basename($f), 'mtime' => @filemtime($f) ?: 0, 'size' => @filesize($f) ?: 0];
    }
    usort($pairs, fn ($a, $b) => ($b['mtime'] <=> $a['mtime']));
    return array_slice($pairs, 0, $limit);
}

/** Trigger JSON files only (excludes README.md etc.). */
function ff_list_trigger_json_files_recent(string $dir, int $limit = 30): array {
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }
    $pairs = [];
    foreach (glob($dir . '/trigger_*.json') ?: [] as $f) {
        if (!is_file($f)) {
            continue;
        }
        $pairs[] = ['name' => basename($f), 'mtime' => @filemtime($f) ?: 0, 'size' => @filesize($f) ?: 0];
    }
    usort($pairs, fn ($a, $b) => ($b['mtime'] <=> $a['mtime']));
    return array_slice($pairs, 0, $limit);
}

function ff_php_effective_user(): string {
    if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if (is_array($u) && !empty($u['name'])) {
            return (string) $u['name'];
        }
    }
    $u = getenv('USER') ?: getenv('LOGNAME');
    return is_string($u) && $u !== '' ? $u : '(unknown)';
}

/**
 * Path to the PHP CLI binary for subprocesses (nohup cursor-agent-run). Under PHP-FPM, PHP_BINARY
 * is often php-fpm; invoking that with script args prints FPM help instead of running index.php.
 */
function ff_php_cli_binary(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    foreach (['PHP_CLI_BINARY', 'FORMATFORGE_PHP_CLI'] as $envKey) {
        $v = getenv($envKey);
        if (is_string($v) && $v !== '') {
            $v = trim($v);
            if ($v !== '' && is_executable($v)) {
                $cached = $v;
                return $cached;
            }
        }
    }
    $pb = (string) PHP_BINARY;
    if ($pb !== '' && is_executable($pb) && stripos(basename($pb), 'fpm') === false) {
        $cached = $pb;
        return $cached;
    }
    foreach (['/usr/bin/php', '/usr/local/bin/php', '/bin/php'] as $try) {
        if (is_executable($try)) {
            $cached = $try;
            return $cached;
        }
    }
    $cached = 'php';
    return $cached;
}

/**
 * Try to create .cursor-pipeline layout (no-op if already present). Does not fix ownership.
 */
function ff_ensure_cursor_pipeline_dirs(): void {
    $cd = ff_cursor_pipeline_dir();
    foreach ([$cd, $cd . '/triggers', ff_cursor_pipeline_prompts_dir(), ff_cursor_agent_runs_dir()] as $d) {
        if (!is_dir($d)) {
            @mkdir($d, 0775, true);
        }
    }
}

function ff_cursor_agent_run_state_path(string $promptReal): string {
    return ff_cursor_agent_runs_dir() . DIRECTORY_SEPARATOR . basename($promptReal, '.md') . '.json';
}

function ff_cursor_agent_run_state_write(string $promptReal, array $patch): void {
    ff_ensure_cursor_pipeline_dirs();
    $path = ff_cursor_agent_run_state_path($promptReal);
    $base = [];
    if (is_file($path)) {
        $raw = json_decode((string) @file_get_contents($path), true);
        if (is_array($raw)) {
            $base = $raw;
        }
    }
    $next = array_merge($base, $patch);
    if (!isset($next['prompt']) || trim((string) $next['prompt']) === '') {
        $next['prompt'] = basename($promptReal);
    }
    $next['updated_at'] = date('c');
    @file_put_contents($path, json_encode($next, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

function ff_cursor_agent_active_runs(int $max = 20): array {
    $dir = ff_cursor_agent_runs_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/*.json') ?: [];
    usort($files, static fn($a, $b) => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));
    $out = [];
    foreach ($files as $f) {
        $raw = json_decode((string) @file_get_contents($f), true);
        if (!is_array($raw)) {
            continue;
        }
        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (!in_array($status, ['queued', 'running'], true)) {
            continue;
        }
        $out[] = [
            'prompt' => (string) ($raw['prompt'] ?? basename($f)),
            'status' => $status,
            'queued_at' => $raw['queued_at'] ?? null,
            'started_at' => $raw['started_at'] ?? null,
            'updated_at' => $raw['updated_at'] ?? null,
            'model' => $raw['model'] ?? null,
        ];
        if (count($out) >= $max) {
            break;
        }
    }
    return $out;
}

function ff_prompt_trigger_reason_from_markdown(string $promptMd): string {
    if (preg_match('/^\*\*Trigger:\*\*\s*(.+)$/m', $promptMd, $m)) {
        return trim((string)($m[1] ?? ''));
    }
    return '';
}

function ff_prompt_context_json_from_markdown(string $promptMd): array {
    if (!preg_match('/## Context\s+```json\s*(\{.*?\})\s*```/s', $promptMd, $m)) {
        return [];
    }
    $raw = trim((string)($m[1] ?? ''));
    if ($raw === '') {
        return [];
    }
    $dec = json_decode($raw, true);
    return is_array($dec) ? $dec : [];
}

function ff_guess_pipeline_name_from_novel_context(array $ctx, string $subdir): string {
    $arr = is_array($ctx['fetched_index_prompts'] ?? null) ? $ctx['fetched_index_prompts'] : [];
    $first = trim((string)($arr[0] ?? ''));
    if ($first !== '') {
        $lines = preg_split("/\r\n|\n|\r/", $first) ?: [];
        foreach ($lines as $ln) {
            if (preg_match('/^\s*Title:\s*(.+)\s*$/i', (string)$ln, $m)) {
                $title = trim((string)($m[1] ?? ''));
                if ($title !== '') {
                    return $title . ' (novel)';
                }
            }
        }
    }
    return $subdir . ' (novel)';
}

function ff_find_pipeline_record_by_subdir(string $token, string $subdir): ?array {
    $safe = str_replace(['\\', '"'], ['\\\\', '\\"'], $subdir);
    $qs = http_build_query([
        'filter' => 'metadata.pipeline_subdir="' . $safe . '"',
        'perPage' => 1,
        'sort' => '-@rowid',
    ]);
    $r = pb_request('GET', '/api/collections/pipelines/records?' . $qs, null, $token);
    if (($r['code'] ?? 0) !== 200) {
        return null;
    }
    $items = is_array($r['body']['items'] ?? null) ? $r['body']['items'] : [];
    return is_array($items[0] ?? null) ? $items[0] : null;
}

function ff_autocreate_pipeline_record_after_agent_success(string $promptReal): void {
    $subdir = basename($promptReal, '.md');
    if (!str_starts_with($subdir, 'pipeline-')) {
        return;
    }
    $promptMd = (string)@file_get_contents($promptReal);
    if ($promptMd === '') {
        ff_pipeline_trace_log('pipeline_record_autocreate_skip', ['reason' => 'empty_prompt_md', 'pipeline_subdir' => $subdir]);
        return;
    }
    $triggerReason = ff_prompt_trigger_reason_from_markdown($promptMd);
    if (!in_array($triggerReason, ['novel_fetched_content', 'novel_content'], true)) {
        ff_pipeline_trace_log('pipeline_record_autocreate_skip', ['reason' => 'non_novel_trigger', 'trigger' => $triggerReason, 'pipeline_subdir' => $subdir]);
        return;
    }
    $auth = pb_superuser_auth_token();
    if (empty($auth['ok']) || empty($auth['token'])) {
        ff_pipeline_trace_log('pipeline_record_autocreate_skip', ['reason' => 'superuser_auth_failed', 'pipeline_subdir' => $subdir]);
        return;
    }
    $tok = (string)$auth['token'];
    $existing = ff_find_pipeline_record_by_subdir($tok, $subdir);
    if (is_array($existing) && trim((string)($existing['id'] ?? '')) !== '') {
        ff_pipeline_trace_log('pipeline_record_autocreate_skip', ['reason' => 'already_exists', 'pipeline_subdir' => $subdir, 'pipeline_id' => (string)($existing['id'] ?? '')]);
        return;
    }

    $ctx = ff_prompt_context_json_from_markdown($promptMd);
    $pipelineDir = __DIR__ . '/pipelines/' . $subdir;
    $statePath = $pipelineDir . '/agent_state.json';
    $state = is_file($statePath) ? (json_decode((string)@file_get_contents($statePath), true) ?: []) : [];
    $agentUuid = ff_validate_pipeline_agent_uuid((string)($state['agent_uuid'] ?? $ctx['agent_uuid'] ?? ''));
    $novelTrigger = str_starts_with($subdir, 'pipeline-') ? substr($subdir, strlen('pipeline-')) : $subdir;
    $sourceLinkId = trim((string)($ctx['backing_input_media_id'] ?? ''));

    $sigFromCtx = is_array($ctx['fetched_shape_signature'] ?? null) ? array_values(array_map(
        fn($v) => ff_shape_kind_for_content_type((string)$v),
        (array)$ctx['fetched_shape_signature']
    )) : [];
    $outputType = strtolower(trim((string)($ctx['suggested_pipelines_output_type'] ?? '')));
    if ($outputType === '' && $sigFromCtx !== []) {
        $outputType = count($sigFromCtx) > 1 ? 'carousel' : (($sigFromCtx[0] ?? 'video') === 'image' ? 'image' : 'reel');
    }
    if ($outputType === '') {
        $fetchedCount = (int)($ctx['fetched_content_items_count'] ?? 0);
        $outputType = $fetchedCount > 1 ? 'carousel' : 'reel';
    }
    if (!in_array($outputType, ['reel', 'carousel', 'video', 'image'], true)) {
        $outputType = 'reel';
    }

    $name = ff_guess_pipeline_name_from_novel_context($ctx, $subdir);
    $sourceUrl = trim((string)($ctx['source_link_url'] ?? ''));
    $description = $sourceUrl !== ''
        ? ('Auto-created after Cursor novel run. Backing source: ' . $sourceUrl)
        : 'Auto-created after Cursor novel run.';

    $promptTemplate = '';
    $tmplPath = $pipelineDir . '/prompt_template.txt';
    if (is_file($tmplPath)) {
        $promptTemplate = trim((string)@file_get_contents($tmplPath));
    }
    if ($promptTemplate === '') {
        $promptTemplate = "Composed pipeline workflow for {$subdir}: respect backing source order/cardinality (carousel N=>N, single video=>1), generate original output, and include explicit per-slot instructions from source context.";
    }

    $metadata = [
        'pipeline_subdir' => $subdir,
        'novel_trigger' => $novelTrigger,
    ];
    $ctxAccountId = trim((string)($ctx['social_account_id'] ?? ''));
    if ($ctxAccountId !== '') {
        $metadata['social_account_id'] = $ctxAccountId;
    }
    if ($sigFromCtx !== []) {
        $metadata['backing_shape_signature'] = $sigFromCtx;
    }
    if ($agentUuid !== null) {
        $metadata['agent_uuid'] = $agentUuid;
    }
    if ($sourceLinkId !== '') {
        $metadata['backing_input_media_id'] = $sourceLinkId;
        $sr = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($sourceLinkId), null, $tok);
        if (($sr['code'] ?? 0) === 200) {
            $sm = is_array($sr['body']['metadata'] ?? null) ? $sr['body']['metadata'] : [];
            $accId = trim((string)($sm['social_account_id'] ?? ($sr['body']['social_account_id'] ?? '')));
            if ($accId !== '') {
                $metadata['social_account_id'] = $accId;
            }
        }
    }

    $payload = [
        'name' => $name,
        'description' => $description,
        'prompt_template' => $promptTemplate,
        'output_type' => $outputType,
        'is_active' => true,
        'metadata' => $metadata,
    ];
    $cr = pb_request('POST', '/api/collections/pipelines/records', $payload, $tok);
    if (($cr['code'] ?? 0) >= 200 && ($cr['code'] ?? 0) < 300) {
        ff_pipeline_trace_log('pipeline_record_autocreated_after_agent', [
            'pipeline_subdir' => $subdir,
            'pipeline_id' => (string)($cr['body']['id'] ?? ''),
            'output_type' => $outputType,
        ]);
        return;
    }
    ff_pipeline_trace_log('pipeline_record_autocreate_failed', [
        'pipeline_subdir' => $subdir,
        'http_code' => (int)($cr['code'] ?? 0),
        'error' => (string)($cr['body']['message'] ?? 'request_failed'),
    ]);
}

function ff_cursor_pipeline_permissions_hint(string $triggerDir, bool $triggerWritable): ?string {
    if ($triggerWritable) {
        return null;
    }
    $user = ff_php_effective_user();
    return "trigger_dir_not_writable: PHP-FPM runs as `{$user}` but cannot write trigger JSON under `{$triggerDir}`. "
        . 'Run from repo root: `sudo ./scripts/ensure-cursor-pipeline-perms.sh` (or `sudo chown -R www-data:www-data .cursor-pipeline storage/cursor-pipeline` if your pool user is www-data). '
        . 'If `.cursor-pipeline/` cannot be made writable, FormatForge falls back to `storage/cursor-pipeline/` (created automatically when writable). '
        . 'Then reload php-fpm. See DEPLOYMENT.md §4.';
}

function ff_pipeline_trace_tail(int $maxLines = 120): array {
    $path = ff_pipeline_trace_path();
    if (!is_readable($path)) {
        return [];
    }
    $raw = ff_read_tail_bytes($path, 512000);
    if ($raw === '') {
        return [];
    }
    $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
    $lines = array_values(array_filter($lines, fn ($l) => trim((string) $l) !== ''));
    $out = [];
    foreach (array_slice($lines, -$maxLines) as $ln) {
        $dec = json_decode((string) $ln, true);
        $out[] = is_array($dec) ? $dec : ['ts' => null, 'event' => 'trace_line_parse_error', 'raw' => substr((string) $ln, 0, 240)];
    }
    return $out;
}

/**
 * Server-side snapshot for the Pipelines tab: env flags, trace tail, cursor-agent.log tail, trigger/prompt files.
 */
function ff_pipeline_diagnostics_bundle(?string $authHeader): array {
    ff_ensure_cursor_pipeline_dirs();
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $triggerDir = (string) ($cfg['cursor_pipeline_trigger_dir'] ?? '');
    $cdir = ff_cursor_pipeline_dir();
    $promptsDir = ff_cursor_pipeline_prompts_dir();
    $agentLog = $cdir . '/cursor-agent.log';
    $tracePath = ff_pipeline_trace_path();
    $antflyUrl = trim((string) ($cfg['antfly_url'] ?? ''));
    $antflyRes = trim((string) ($cfg['antfly_url_resolution'] ?? ''));
    $pbRes = trim((string) ($cfg['pocketbase_url_resolution'] ?? ''));
    $pbUrlDisp = trim((string) ($cfg['pocketbase_url'] ?? ''));
    $triggerWritable = $triggerDir !== '' && is_dir($triggerDir) && is_writable($triggerDir);
    $promptsExist = is_dir($promptsDir);
    $promptsWritable = $promptsExist && is_writable($promptsDir);
    $cdWritable = is_dir($cdir) && is_writable($cdir);
    $runtimeBase = ff_cursor_pipeline_runtime_base();
    $usingStorageFallback = str_replace('\\', '/', $runtimeBase) === str_replace('\\', '/', __DIR__ . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'cursor-pipeline');
    $pipelinesParent = __DIR__ . '/pipelines';
    $pipelinesWritable = is_dir($pipelinesParent) && is_writable($pipelinesParent);
    $permHint = ff_cursor_pipeline_permissions_hint($triggerDir, $triggerWritable);
    if (!$pipelinesWritable) {
        $pipelinesHint = 'pipelines_dir_not_writable: PHP-FPM cannot create `pipelines/pipeline-*` (needed when applying triggers). '
            . 'From repo root: `sudo chgrp www-data pipelines && sudo chmod g+w pipelines` (adjust pool user if not www-data), or add ACL for the FPM user. '
            . 'Then `sudo systemctl reload php8.3-fpm`. See DEPLOYMENT.md.';
        $permHint = $permHint !== null && $permHint !== '' ? ($permHint . "\n\n" . $pipelinesHint) : $pipelinesHint;
    }
    $logTailRaw = ff_read_tail_bytes_seek_end($agentLog, 96000);
    $logSan = ff_sanitize_cursor_agent_log_excerpt($logTailRaw);
    $activeRuns = ff_cursor_agent_active_runs(30);
    $out = [
        'env' => [
            'php_effective_user' => ff_php_effective_user(),
            'php_cli_binary' => ff_php_cli_binary(),
            'antfly_url_configured' => $antflyUrl !== '',
            'antfly_url' => $antflyUrl !== '' ? $antflyUrl : null,
            'antfly_url_resolution' => $antflyRes !== '' ? $antflyRes : null,
            'pocketbase_url' => $pbUrlDisp !== '' ? $pbUrlDisp : null,
            'pocketbase_url_resolution' => $pbRes !== '' ? $pbRes : null,
            'cursor_agent_enabled' => !empty($cfg['cursor_agent_enabled']),
            'cursor_agent_on_pipeline_generation_failure' => ff_cursor_agent_on_pipeline_generation_failure_enabled(),
            'cursor_agent_on_verify_pipeline_failure' => ff_cursor_agent_on_verify_pipeline_failure_enabled(),
            'cursor_agent_sudo_user' => trim((string)($cfg['cursor_agent_sudo_user'] ?? '')) ?: null,
            'cursor_agent_output_format' => (string)($cfg['cursor_agent_output_format'] ?? 'text'),
            'cursor_agent_stream_partial_output' => !empty($cfg['cursor_agent_stream_partial_output']),
            'cursor_agent_run_wrapper' => trim((string)($cfg['cursor_agent_run_wrapper'] ?? '')) ?: null,
            'cursor_agent_sudo_wrapper_executable' => (static function () use ($cfg): bool {
                $w = trim((string)($cfg['cursor_agent_run_wrapper'] ?? ''));
                return $w !== '' && is_file($w) && is_executable($w);
            })(),
            'novel_threshold' => (float) ($cfg['novel_threshold'] ?? 0.35),
            'cursor_pipeline_runtime_base' => $runtimeBase,
            'cursor_pipeline_using_storage_fallback' => $usingStorageFallback,
            'cursor_pipeline_trigger_dir' => $triggerDir,
            'trigger_dir_exists' => $triggerDir !== '' && is_dir($triggerDir),
            'trigger_dir_writable' => $triggerWritable,
            'cursor_pipeline_dir' => $cdir,
            'cursor_pipeline_dir_writable' => $cdWritable,
            'prompts_dir_exists' => $promptsExist,
            'prompts_dir_writable' => $promptsWritable,
            'pipelines_dir_writable' => $pipelinesWritable,
            'agent_web_tty_url' => trim((string)($cfg['agent_web_tty_url'] ?? '')) !== '' ? trim((string)($cfg['agent_web_tty_url'] ?? '')) : null,
            'agent_web_tty_link_visible' => ff_agent_web_tty_link_visible(),
        ],
        'permissions_hint' => $permHint,
        'cursor_agent_log_path' => $agentLog,
        'cursor_agent_log_bytes' => is_readable($agentLog) ? (@filesize($agentLog) ?: 0) : null,
        'cursor_agent_log_tail' => $logSan['text'],
        'cursor_agent_log_tail_note' => $logSan['note'],
        'pipeline_trace_path' => $tracePath,
        'pipeline_trace_tail' => ff_pipeline_trace_tail(140),
        'active_agent_count' => count($activeRuns),
        'active_agent_runs' => $activeRuns,
        'recent_triggers' => $triggerDir !== '' ? ff_list_trigger_json_files_recent($triggerDir, 30) : [],
        'recent_prompts' => is_dir($promptsDir) ? ff_list_dir_files_recent($promptsDir, 30) : [],
    ];
    if ($authHeader) {
        $out['env']['active_pipeline_count'] = fetch_active_pipeline_row_count($authHeader);
    } else {
        $out['env']['active_pipeline_count'] = null;
    }
    return $out;
}

/**
 * Max age (seconds) for queued/running Cursor agent run-state files to block pipeline-cron / feed-refresh generation.
 * Crashed or detached agents can leave status stuck; without expiry, pipeline runs never start. 0 = never expire (legacy).
 */
function ff_pipeline_cron_cursor_agent_busy_max_age_sec(): int {
    $v = getenv('PIPELINE_CRON_CURSOR_AGENT_BUSY_MAX_AGE_SEC');
    if ($v === false || trim((string) $v) === '') {
        return 3600;
    }

    return max(0, (int) $v);
}

/**
 * True when run-state timestamps are older than maxAgeSec (pipeline cron may treat as not busy).
 */
function ff_cursor_agent_run_state_too_old_for_busy_block(array $raw, int $maxAgeSec): bool {
    if ($maxAgeSec <= 0) {
        return false;
    }
    $newest = 0;
    foreach (['updated_at', 'started_at', 'queued_at'] as $k) {
        $t = trim((string) ($raw[$k] ?? ''));
        if ($t === '') {
            continue;
        }
        $u = strtotime($t);
        if (is_int($u) && $u > $newest) {
            $newest = $u;
        }
    }
    if ($newest < 1) {
        return false;
    }

    return (time() - $newest) > $maxAgeSec;
}

/**
 * Cursor agent run state files (queued/running) map to pipeline subdirs via prompt basename (pipeline-*.md).
 * Used to avoid spawning pipeline-generate while the same pipeline’s Cursor agent may edit files; stale states expire (see ff_pipeline_cron_cursor_agent_busy_max_age_sec).
 */
function ff_cursor_agent_busy_pipeline_subdirs(): array {
    $dir = ff_cursor_agent_runs_dir();
    if (!is_dir($dir)) {
        return [];
    }
    $maxAge = ff_pipeline_cron_cursor_agent_busy_max_age_sec();
    $files = glob($dir . '/*.json') ?: [];
    $seen = [];
    foreach ($files as $f) {
        $raw = json_decode((string) @file_get_contents($f), true);
        if (!is_array($raw)) {
            continue;
        }
        $status = strtolower(trim((string) ($raw['status'] ?? '')));
        if (!in_array($status, ['queued', 'running'], true)) {
            continue;
        }
        if (ff_cursor_agent_run_state_too_old_for_busy_block($raw, $maxAge)) {
            continue;
        }
        $stem = pathinfo((string) ($raw['prompt'] ?? ''), PATHINFO_FILENAME);
        if ($stem !== '' && str_starts_with($stem, 'pipeline-')) {
            $seen[$stem] = true;
        }
    }
    return array_keys($seen);
}

/**
 * When enabled (default), new Cursor agent triggers for a pipeline are FIFO-queued if that prompt is already queued/running.
 * Queue files: .cursor-pipeline/agent_trigger_queue/<prompt_stem>.queue.json
 */
function ff_cursor_agent_queue_enabled(): bool {
    $e = getenv('CURSOR_AGENT_QUEUE_ENABLED');
    if ($e === false || trim((string) $e) === '') {
        return true;
    }
    return !in_array(strtolower(trim((string) $e)), ['0', 'false', 'no', 'off'], true);
}

function ff_cursor_agent_queue_max(): int {
    return max(1, min(500, (int) (getenv('CURSOR_AGENT_QUEUE_MAX') ?: '50')));
}

function ff_pipeline_agent_cursor_queue_dir(): string {
    return ff_cursor_pipeline_dir() . DIRECTORY_SEPARATOR . 'agent_trigger_queue';
}

/**
 * Same resolution as setup_pipeline_from_trigger() — prompt stem = runs/*.json basename.
 */
function ff_pipeline_agent_resolve_prompt_stem_from_trigger_payload(array $trigger, string $triggerFile): string {
    $reason = trim((string) ($trigger['reason'] ?? ''));
    if ($reason === '') {
        $reason = 'unknown';
    }
    $context = is_array($trigger['context'] ?? null) ? $trigger['context'] : [];
    $triggerBase = basename($triggerFile, '.json');
    $pipelineIdFromTrigger = str_replace('trigger_', '', $triggerBase);
    $reasonUsesResolvedSubdir = in_array($reason, ['pipeline_content_rejected', 'pipeline_edit_streak', 'content_rejected', 'pipeline_deleted', 'pipeline_generation_failed'], true);
    $resolvedSubdir = '';
    if ($reasonUsesResolvedSubdir) {
        $resolvedSubdir = ff_normalize_pipeline_subdir(trim((string) ($context['pipeline_subdir'] ?? '')));
    }
    if ($reasonUsesResolvedSubdir && $resolvedSubdir === '') {
        $resolvedSubdir = ff_normalize_pipeline_subdir($pipelineIdFromTrigger);
    }
    if ($reasonUsesResolvedSubdir && $resolvedSubdir !== '') {
        return $resolvedSubdir;
    }
    return 'pipeline-' . $pipelineIdFromTrigger;
}

function ff_pipeline_agent_queue_safe_stem(string $stem): string {
    $stem = trim($stem);
    if ($stem === '') {
        return '_empty';
    }
    if (!preg_match('/^pipeline-[a-zA-Z0-9._-]+$/', $stem)) {
        return 'hash_' . substr(md5($stem), 0, 24);
    }
    return $stem;
}

function ff_pipeline_agent_is_busy_for_prompt_stem(string $stem): bool {
    $stem = trim($stem);
    if ($stem === '') {
        return false;
    }
    $path = ff_cursor_agent_runs_dir() . DIRECTORY_SEPARATOR . $stem . '.json';
    if (!is_file($path)) {
        return false;
    }
    $raw = json_decode((string) @file_get_contents($path), true);
    if (!is_array($raw)) {
        return false;
    }
    $status = strtolower(trim((string) ($raw['status'] ?? '')));
    return in_array($status, ['queued', 'running'], true);
}

function ff_pipeline_agent_queue_data_path(string $stem): string {
    return ff_pipeline_agent_cursor_queue_dir() . DIRECTORY_SEPARATOR . ff_pipeline_agent_queue_safe_stem($stem) . '.queue.json';
}

function ff_pipeline_agent_queue_append(string $stem, string $triggerFile, string $reason): void {
    $dir = ff_pipeline_agent_cursor_queue_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        ff_pipeline_trace_log('cursor_agent_queue_append_failed', ['reason' => 'mkdir', 'stem' => $stem]);
        return;
    }
    $qf = ff_pipeline_agent_queue_data_path($stem);
    $max = ff_cursor_agent_queue_max();
    $lockFp = @fopen($qf . '.lock', 'c+');
    if (!$lockFp) {
        ff_pipeline_trace_log('cursor_agent_queue_append_failed', ['reason' => 'lock_open', 'stem' => $stem]);
        return;
    }
    if (!flock($lockFp, LOCK_EX)) {
        fclose($lockFp);
        return;
    }
    $list = [];
    if (is_file($qf)) {
        $list = json_decode((string) file_get_contents($qf), true);
        if (!is_array($list)) {
            $list = [];
        }
    }
    $list[] = [
        'path' => $triggerFile,
        'enqueued_at' => date('c'),
        'reason' => $reason,
    ];
    while (count($list) > $max) {
        array_shift($list);
    }
    file_put_contents($qf, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    ff_pipeline_trace_log('cursor_agent_trigger_queued', ['stem' => $stem, 'trigger' => basename($triggerFile), 'queue_depth' => count($list)]);
}

/**
 * After an agent run finishes (or fails to start), start the next queued trigger for the same prompt stem (FIFO).
 */
function ff_pipeline_agent_drain_queue_after_prompt_stem(string $promptStem): void {
    if (!ff_cursor_agent_queue_enabled()) {
        return;
    }
    $stem = trim($promptStem);
    if ($stem === '') {
        return;
    }
    $qf = ff_pipeline_agent_queue_data_path($stem);
    $lockFp = @fopen($qf . '.lock', 'c+');
    if (!$lockFp) {
        return;
    }
    if (!flock($lockFp, LOCK_EX)) {
        fclose($lockFp);
        return;
    }
    $list = [];
    if (is_file($qf)) {
        $list = json_decode((string) file_get_contents($qf), true);
        if (!is_array($list)) {
            $list = [];
        }
    }
    $nextPath = '';
    while ($list !== []) {
        $candidate = array_shift($list);
        $tp = trim((string) ($candidate['path'] ?? ''));
        if ($tp !== '' && is_file($tp)) {
            $nextPath = $tp;
            break;
        }
    }
    file_put_contents($qf, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    if ($nextPath === '') {
        return;
    }
    ff_pipeline_trace_log('cursor_agent_queue_drain', ['stem' => $stem, 'next' => basename($nextPath)]);
    setup_pipeline_from_trigger($nextPath, true);
}

function ff_pipeline_agent_with_stem_lock(string $stem, callable $fn): void {
    $dir = ff_pipeline_agent_cursor_queue_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        $fn();
        return;
    }
    $safe = ff_pipeline_agent_queue_safe_stem($stem);
    $lp = $dir . DIRECTORY_SEPARATOR . '.stem_' . $safe . '.lock';
    $fp = @fopen($lp, 'c+');
    if (!$fp) {
        $fn();
        return;
    }
    flock($fp, LOCK_EX);
    try {
        $fn();
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

function ff_pipeline_cron_agent_busy_for_pipeline(array $pipelineRecord, array $busySubdirs): bool {
    $sd = ff_pipeline_subdir_from_pipeline_record($pipelineRecord);
    if ($sd === '') {
        return false;
    }
    foreach ($busySubdirs as $b) {
        if ($b === $sd || str_starts_with($b, $sd . '_')) {
            return true;
        }
    }
    return false;
}

function ff_pipeline_cron_generating_count(string $pipelineId, string $authHeader): int {
    $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $pipelineId);
    $q = http_build_query([
        'filter' => 'status = "generating" && metadata.pipeline_id = "' . $esc . '"',
        'perPage' => 200,
    ]);
    $r = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    if (($r['code'] ?? 0) !== 200) {
        return 999;
    }
    return count($r['body']['items'] ?? []);
}

/**
 * PocketBase-backed pipeline auto-run for cron: scales attempts with publish gap vs TARGET_POSTS_PER_DAY.
 *
 * @param array{force?: bool} $opts When **`force`** is true (CLI `--force`): ignore **`PIPELINE_CRON_ENABLED`**, skip cadence (plan **`PIPELINE_CRON_MAX_PER_TICK`** runs), and skip auto-post kind saturation filtering.
 *
 * @return array{ok: bool, skipped?: string, runs_planned?: int, runs_started?: int, forced?: bool, detail?: array<int, array<string, mixed>>}
 */
function ff_pipeline_cron_tick(string $authHeader, array $opts = []): array {
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $force = !empty($opts['force']);
    if (!$force && strtolower(trim((string) (getenv('PIPELINE_CRON_ENABLED') ?: '0'))) !== '1') {
        return ['ok' => true, 'skipped' => 'PIPELINE_CRON_ENABLED not 1'];
    }
    $maxPerTick = max(1, (int) (getenv('PIPELINE_CRON_MAX_PER_TICK') ?: '2'));
    $maxQueue = max(0, (int) (getenv('PIPELINE_CRON_MAX_QUEUE_PER_PIPELINE') ?: '2'));
    $idsEnv = trim((string) (getenv('PIPELINE_CRON_PIPELINE_IDS') ?: ''));
    $accountOverride = trim((string) (getenv('PIPELINE_CRON_ACCOUNT_ID') ?: ''));

    $ctx = ff_cursor_agent_operating_context($authHeader);
    $target = (int) ($cfg['target_posts_per_day'] ?? 60);
    $published = isset($ctx['published_count_last_24h']) ? (int) $ctx['published_count_last_24h'] : null;
    $runs = 0;
    if ($force) {
        $runs = $maxPerTick;
    } elseif ($target > 0 && $published !== null) {
        $gap = max(0, $target - $published);
        $pressure = min(1.0, $gap / max(1, $target));
        $runs = (int) ceil($pressure * $maxPerTick);
        if ($published === 0) {
            $runs = max($runs, min(2, $maxPerTick));
        }
    } elseif ($target <= 0) {
        $runs = min(1, $maxPerTick);
    } else {
        $runs = min(1, $maxPerTick);
    }
    if ($runs < 1) {
        return ['ok' => true, 'skipped' => 'cadence satisfied (no runs this tick)', 'runs_planned' => 0, 'forced' => $force];
    }

    $busy = ff_cursor_agent_busy_pipeline_subdirs();

    $qP = http_build_query(['filter' => 'is_active != false', 'perPage' => 200, 'sort' => '-@rowid']);
    $pr = pb_request('GET', '/api/collections/pipelines/records?' . $qP, null, $authHeader);
    if (($pr['code'] ?? 0) !== 200) {
        return ['ok' => false, 'error' => 'Could not list pipelines', 'detail' => $pr['body'] ?? []];
    }
    $allowIds = [];
    if ($idsEnv !== '') {
        foreach (preg_split('/\s*,\s*/', $idsEnv) ?: [] as $p) {
            $p = trim($p);
            if ($p !== '') {
                $allowIds[$p] = true;
            }
        }
    }
    $candidates = [];
    foreach ($pr['body']['items'] ?? [] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $pid = trim((string) ($p['id'] ?? ''));
        if ($pid === '') {
            continue;
        }
        if ($allowIds !== [] && empty($allowIds[$pid])) {
            continue;
        }
        $tmpl = trim((string) ($p['prompt_template'] ?? ''));
        if ($tmpl === '') {
            continue;
        }
        if (array_key_exists('is_active', $p) && $p['is_active'] === false) {
            continue;
        }
        $candidates[] = $p;
    }
    if ($candidates === []) {
        return ['ok' => true, 'skipped' => 'no eligible pipelines (need prompt_template + active)', 'runs_planned' => $runs];
    }

    $acctList = pb_request('GET', '/api/collections/social_accounts/records?sort=-%40rowid&perPage=50', null, $authHeader);
    $accounts = ($acctList['code'] === 200) ? ($acctList['body']['items'] ?? []) : [];
    $defaultAccount = '';
    if ($accountOverride !== '') {
        $defaultAccount = $accountOverride;
    } elseif ($accounts !== []) {
        foreach ($accounts as $a) {
            if (!empty($a['is_active']) && !empty($a['id'])) {
                $defaultAccount = (string) $a['id'];
                break;
            }
        }
        if ($defaultAccount === '' && !empty($accounts[0]['id'])) {
            $defaultAccount = (string) $accounts[0]['id'];
        }
    }
    if ($defaultAccount === '') {
        return ['ok' => false, 'error' => 'No Instagram account for cron — set PIPELINE_CRON_ACCOUNT_ID or connect an account'];
    }

    $saturationFilter = !$force && ff_auto_post_pipeline_cron_saturation_enabled();
    $targetKindCache = [];
    $started = 0;
    $detail = [];
    $round = 0;
    while ($started < $runs && $round < $runs * max(3, count($candidates))) {
        $round++;
        $picked = null;
        foreach ($candidates as $p) {
            $pid = (string) ($p['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            if (ff_pipeline_cron_agent_busy_for_pipeline($p, $busy)) {
                continue;
            }
            if ($maxQueue > 0 && ff_pipeline_cron_generating_count($pid, $authHeader) >= $maxQueue) {
                continue;
            }
            $pm = is_array($p['metadata'] ?? null) ? $p['metadata'] : [];
            $scope = trim((string) ($pm['social_account_id'] ?? ''));
            $accountId = $scope !== '' ? $scope : $defaultAccount;
            if ($saturationFilter) {
                if ($accountId === '') {
                    continue;
                }
                if (!isset($targetKindCache[$accountId])) {
                    $targetKindCache[$accountId] = ff_auto_post_pipeline_target_kind($accountId, $authHeader);
                }
                $tk = $targetKindCache[$accountId];
                if ($tk === null) {
                    continue;
                }
                if (ff_pipeline_instagram_kind_from_pipeline($p) !== $tk) {
                    continue;
                }
            }
            $picked = ['pipeline' => $p, 'account_id' => $accountId];
            break;
        }
        if ($picked === null) {
            break;
        }
        $pipelineIdRun = (string) ($picked['pipeline']['id'] ?? '');
        $accountIdRun = $picked['account_id'];
        $php = ff_php_cli_binary();
        $script = __DIR__ . '/index.php';
        $cmd = $php . ' ' . escapeshellarg($script) . ' pipeline-generate-once ' . escapeshellarg($pipelineIdRun) . ' ' . escapeshellarg($accountIdRun) . ' 2>&1';
        $out = shell_exec($cmd);
        $started++;
        $detail[] = [
            'pipeline_id' => $pipelineIdRun,
            'account_id' => $accountIdRun,
            'cli_output' => substr((string) $out, 0, 4000),
        ];
    }

    if ($started < $runs) {
        $skip = [];
        foreach ($candidates as $p) {
            $pid = (string) ($p['id'] ?? '');
            if ($pid === '') {
                continue;
            }
            if (ff_pipeline_cron_agent_busy_for_pipeline($p, $busy)) {
                $skip[$pid] = 'agent_busy';
                continue;
            }
            if ($maxQueue > 0 && ff_pipeline_cron_generating_count($pid, $authHeader) >= $maxQueue) {
                $skip[$pid] = 'generating_queue_full';
                continue;
            }
            $pm = is_array($p['metadata'] ?? null) ? $p['metadata'] : [];
            $scope = trim((string) ($pm['social_account_id'] ?? ''));
            $acct = $scope !== '' ? $scope : $defaultAccount;
            if ($saturationFilter) {
                if ($acct === '') {
                    $skip[$pid] = 'no_instagram_account';
                    continue;
                }
                if (!isset($targetKindCache[$acct])) {
                    $targetKindCache[$acct] = ff_auto_post_pipeline_target_kind($acct, $authHeader);
                }
                $tk = $targetKindCache[$acct];
                if ($tk === null) {
                    $skip[$pid] = 'auto_post_all_kinds_saturated';
                    continue;
                }
                if (ff_pipeline_instagram_kind_from_pipeline($p) !== $tk) {
                    $skip[$pid] = 'auto_post_kind_mismatch';
                    continue;
                }
            }
            $skip[$pid] = 'eligible_not_picked';
        }
        ff_pipeline_trace_log('pipeline_cron_tick_unfilled', [
            'planned' => $runs,
            'started' => $started,
            'pipeline_skip' => $skip,
            'auto_post_target_kind' => $saturationFilter && $defaultAccount !== ''
                ? ($targetKindCache[$defaultAccount] ?? ff_auto_post_pipeline_target_kind($defaultAccount, $authHeader))
                : null,
        ]);
    }

    ff_pipeline_trace_log('pipeline_cron_tick', [
        'runs_planned' => $runs,
        'runs_started' => $started,
        'published_24h' => $published,
        'target_posts_per_day' => $target,
        'busy_subdirs' => $busy,
        'busy_ttl_sec' => ff_pipeline_cron_cursor_agent_busy_max_age_sec(),
        'forced' => $force,
    ]);

    $out = ['ok' => true, 'runs_planned' => $runs, 'runs_started' => $started, 'detail' => $detail];
    if ($force) {
        $out['forced'] = true;
    }

    return $out;
}

// --- Auto-post queue (approved → scheduled → published) + pipeline-cron saturation priority ---

function ff_auto_post_env_int(string $key, int $default): int {
    $v = getenv($key);
    if ($v === false || trim((string) $v) === '') {
        return $default;
    }

    return max(0, (int) $v);
}

function ff_auto_post_enabled(): bool {
    return strtolower(trim((string) (getenv('AUTO_POST_ENABLED') ?: '0'))) === '1';
}

function ff_auto_post_pipeline_cron_saturation_enabled(): bool {
    if (!ff_auto_post_enabled()) {
        return false;
    }

    return strtolower(trim((string) (getenv('AUTO_POST_PIPELINE_CRON_SATURATION') ?: '1'))) === '1';
}

function ff_normalize_instagram_queue_kind(string $k): string {
    $k = strtolower(trim($k));
    if ($k === 'reel' || $k === 'reels') {
        return 'video';
    }
    if (in_array($k, ['image', 'video', 'carousel'], true)) {
        return $k;
    }

    return 'video';
}

/**
 * Scheduling / saturation kind for a content_items row (image | video | carousel).
 */
function ff_content_item_instagram_kind(array $item): string {
    $t = strtolower(trim((string) ($item['type'] ?? '')));
    $meta = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
    $sig = $meta['source_shape_signature'] ?? [];
    $runId = trim((string) ($meta['source_shape_run_id'] ?? ''));
    if ($t === 'image') {
        return 'image';
    }
    if ($t === 'carousel') {
        return 'carousel';
    }
    if ($runId !== '' && is_array($sig) && count($sig) > 1) {
        return 'carousel';
    }
    if ($t === 'video' || $t === 'reel' || $t === '') {
        return 'video';
    }

    return 'video';
}

/**
 * Pipeline output mapped to the same kind axis as content_items (reel → video).
 */
function ff_pipeline_instagram_kind_from_pipeline(array $p): string {
    $t = strtolower(trim(ff_pipeline_effective_output_type($p)));

    return ff_normalize_instagram_queue_kind($t === 'reel' ? 'video' : $t);
}

/**
 * Per-kind daily targets from weights (sum = totalPerDay).
 *
 * @return array{image: int, video: int, carousel: int}
 */
function ff_auto_post_kind_targets_per_day(array $weights, int $totalPerDay): array {
    $wi = max(0.0, (float) ($weights['image'] ?? 1));
    $wv = max(0.0, (float) ($weights['video'] ?? 1));
    $wc = max(0.0, (float) ($weights['carousel'] ?? 1));
    $s = $wi + $wv + $wc;
    if ($s <= 0.0 || $totalPerDay <= 0) {
        $n = max(0, intdiv($totalPerDay, 3));
        $r = $totalPerDay - 3 * $n;

        return [
            'image' => $n + ($r > 0 ? 1 : 0),
            'video' => $n + ($r > 1 ? 1 : 0),
            'carousel' => $n + ($r > 2 ? 1 : 0),
        ];
    }
    $ni = (int) round($totalPerDay * $wi / $s);
    $nv = (int) round($totalPerDay * $wv / $s);
    $nc = $totalPerDay - $ni - $nv;
    if ($nc < 0) {
        $nc = 0;
        $nv = max(0, $totalPerDay - $ni);
    }
    $nc = max(0, $totalPerDay - $ni - $nv);

    return ['image' => $ni, 'video' => $nv, 'carousel' => $nc];
}

/**
 * One day’s slot kinds in round-robin order (image → video → carousel until quotas).
 *
 * @return list<string>
 */
function ff_auto_post_day_kind_pattern(array $targetsPerDay): array {
    $ni = max(0, (int) ($targetsPerDay['image'] ?? 0));
    $nv = max(0, (int) ($targetsPerDay['video'] ?? 0));
    $nc = max(0, (int) ($targetsPerDay['carousel'] ?? 0));
    $out = [];
    while ($ni > 0 || $nv > 0 || $nc > 0) {
        if ($ni > 0) {
            $out[] = 'image';
            $ni--;
        }
        if ($nv > 0) {
            $out[] = 'video';
            $nv--;
        }
        if ($nc > 0) {
            $out[] = 'carousel';
            $nc--;
        }
    }

    return $out;
}

/**
 * Impression-weighted relative weights per kind (falls back to equal).
 *
 * @return array{image: float, video: float, carousel: float}
 */
function ff_auto_post_impression_weights_for_account(string $accountId, string $authHeader): array {
    $base = ['image' => 1.0, 'video' => 1.0, 'carousel' => 1.0];
    if (strtolower(trim((string) (getenv('AUTO_POST_IMPRESSION_WEIGHTS') ?: '1'))) !== '1') {
        return $base;
    }
    $accountId = trim($accountId);
    if ($accountId === '') {
        return $base;
    }
    $esc = ff_pb_filter_string($accountId);
    $q = http_build_query([
        'filter' => 'social_account_id = "' . $esc . '" && status = "published"',
        'perPage' => 200,
        'sort' => '-@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        return $base;
    }
    $sums = ['image' => 0.0, 'video' => 0.0, 'carousel' => 0.0];
    foreach ($lr['body']['items'] ?? [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        $cid = trim((string) ($it['id'] ?? ''));
        if ($cid === '') {
            continue;
        }
        $m = is_array($it['metrics'] ?? null) ? $it['metrics'] : [];
        $imp = (float) ($m['impressions'] ?? 0);
        if ($imp < 1.0) {
            $imp = 1.0;
        }
        $kind = ff_content_item_instagram_kind($it);
        if (!isset($sums[$kind])) {
            $kind = 'video';
        }
        $sums[$kind] += $imp;
    }
    $t = $sums['image'] + $sums['video'] + $sums['carousel'];
    if ($t < 1.0) {
        return $base;
    }

    return [
        'image' => $sums['image'] / $t * 3.0,
        'video' => $sums['video'] / $t * 3.0,
        'carousel' => $sums['carousel'] / $t * 3.0,
    ];
}

/**
 * @return array{image: bool, video: bool, carousel: bool} true = saturated (queue full for that kind).
 */
function ff_auto_post_kind_saturation_map(string $accountId, string $authHeader): array {
    $days = max(1, ff_auto_post_env_int('AUTO_POST_SATURATION_DAYS', 7));
    $total = max(1, ff_auto_post_env_int('AUTO_POST_TOTAL_PER_DAY', 30));
    $weights = ff_auto_post_impression_weights_for_account($accountId, $authHeader);
    $targets = ff_auto_post_kind_targets_per_day($weights, $total);
    $need = [
        'image' => $targets['image'] * $days,
        'video' => $targets['video'] * $days,
        'carousel' => $targets['carousel'] * $days,
    ];
    $out = ['image' => false, 'video' => false, 'carousel' => false];
    foreach (['image', 'video', 'carousel'] as $k) {
        $have = ff_auto_post_count_scheduled_in_window($accountId, $k, $authHeader, $days);
        $out[$k] = $have >= $need[$k];
    }

    return $out;
}

function ff_auto_post_count_scheduled_in_window(string $accountId, string $kind, string $authHeader, int $saturationDays): int {
    $accountId = trim($accountId);
    if ($accountId === '') {
        return 0;
    }
    $now = time();
    $end = $now + max(1, $saturationDays) * 86400;
    $esc = ff_pb_filter_string($accountId);
    $nowIso = gmdate('Y-m-d\TH:i:s.v\Z', $now);
    $endIso = gmdate('Y-m-d\TH:i:s.v\Z', $end);
    $q = http_build_query([
        'filter' => 'social_account_id = "' . $esc . '" && status = "scheduled" && scheduled_publish_at >= "' . $nowIso . '" && scheduled_publish_at <= "' . $endIso . '"',
        'perPage' => 500,
        'sort' => '@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        return 0;
    }
    $n = 0;
    foreach ($lr['body']['items'] ?? [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        if (ff_content_item_instagram_kind($it) === $kind) {
            $n++;
        }
    }

    return $n;
}

/**
 * First kind in image → video → carousel order that is not yet saturated; null = all saturated.
 */
function ff_auto_post_pipeline_target_kind(string $accountId, string $authHeader): ?string {
    $map = ff_auto_post_kind_saturation_map($accountId, $authHeader);
    foreach (['image', 'video', 'carousel'] as $k) {
        if (empty($map[$k])) {
            return $k;
        }
    }

    return null;
}

/**
 * Sibling media URLs for a carousel run (source_shape_run_id), ordered by source_shape_index.
 *
 * @return list<string>
 */
function ff_content_item_carousel_media_urls(array $item, string $authHeader): array {
    $meta = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
    $runId = trim((string) ($meta['source_shape_run_id'] ?? ''));
    $pid = trim((string) ($meta['pipeline_id'] ?? ''));
    if ($runId === '' || $pid === '') {
        $u = ff_content_item_effective_media_url($item);

        return $u !== '' ? [$u] : [];
    }
    $escRun = ff_pb_filter_string($runId);
    $escPid = ff_pb_filter_string($pid);
    $qs = http_build_query([
        'filter' => 'metadata.source_shape_run_id = "' . $escRun . '" && metadata.pipeline_id = "' . $escPid . '"',
        'perPage' => 200,
        'sort' => '@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        $u = ff_content_item_effective_media_url($item);

        return $u !== '' ? [$u] : [];
    }
    $items = $lr['body']['items'] ?? [];
    if ($items === []) {
        $u = ff_content_item_effective_media_url($item);

        return $u !== '' ? [$u] : [];
    }
    usort($items, function ($a, $b) {
        $ia = is_array($a['metadata'] ?? null) ? (int) ($a['metadata']['source_shape_index'] ?? 0) : 0;
        $ib = is_array($b['metadata'] ?? null) ? (int) ($b['metadata']['source_shape_index'] ?? 0) : 0;
        if ($ia === $ib) {
            return 0;
        }

        return $ia <=> $ib;
    });
    $urls = [];
    foreach ($items as $row) {
        if (!is_array($row)) {
            continue;
        }
        $u = ff_content_item_effective_media_url($row);
        if ($u !== '') {
            $urls[] = $u;
        }
    }

    return $urls;
}

function ff_instagram_graph_post(string $path, array $fields): array {
    $ch = curl_init('https://graph.instagram.com/v18.0/' . ltrim($path, '/'));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);

    return json_decode($res ?: '{}', true) ?? [];
}

function ff_instagram_publish_creation_id(string $igUserId, string $accessToken, string $creationId): array {
    sleep(2);

    return ff_instagram_graph_post($igUserId . '/media_publish', [
        'creation_id' => $creationId,
        'access_token' => $accessToken,
    ]);
}

/**
 * @return array{ok: bool, media_id?: string, error?: string, detail?: mixed}
 */
function ff_instagram_publish_single_image(string $igUserId, string $accessToken, string $imageUrl): array {
    $create = ff_instagram_graph_post($igUserId . '/media', [
        'image_url' => $imageUrl,
        'access_token' => $accessToken,
    ]);
    $containerId = $create['id'] ?? null;
    if (!$containerId) {
        return ['ok' => false, 'error' => $create['error']['message'] ?? 'Create image container failed', 'detail' => $create];
    }
    $pub = ff_instagram_publish_creation_id($igUserId, $accessToken, (string) $containerId);
    $mediaId = $pub['id'] ?? null;
    if (!$mediaId) {
        return ['ok' => false, 'error' => $pub['error']['message'] ?? 'media_publish failed', 'detail' => $pub];
    }

    return ['ok' => true, 'media_id' => (string) $mediaId];
}

/**
 * @return array{ok: bool, media_id?: string, error?: string, detail?: mixed}
 */
function ff_instagram_publish_reels(string $igUserId, string $accessToken, string $videoUrl): array {
    $create = ff_instagram_graph_post($igUserId . '/media', [
        'media_type' => 'REELS',
        'video_url' => $videoUrl,
        'access_token' => $accessToken,
    ]);
    $containerId = $create['id'] ?? null;
    if (!$containerId) {
        return ['ok' => false, 'error' => $create['error']['message'] ?? 'Create Reels container failed', 'detail' => $create];
    }
    $pub = ff_instagram_publish_creation_id($igUserId, $accessToken, (string) $containerId);
    $mediaId = $pub['id'] ?? null;
    if (!$mediaId) {
        return ['ok' => false, 'error' => $pub['error']['message'] ?? 'media_publish failed', 'detail' => $pub];
    }

    return ['ok' => true, 'media_id' => (string) $mediaId];
}

/**
 * @param list<string> $urls
 *
 * @return array{ok: bool, media_id?: string, error?: string, detail?: mixed}
 */
function ff_instagram_publish_carousel(string $igUserId, string $accessToken, array $urls): array {
    $urls = array_values(array_filter($urls, fn ($u) => is_string($u) && trim($u) !== ''));
    if (count($urls) < 2) {
        return ['ok' => false, 'error' => 'Carousel requires at least 2 media URLs'];
    }
    $childIds = [];
    foreach ($urls as $u) {
        $isVid = ff_alignment_guess_video_url_from_string($u);
        $fields = [
            'access_token' => $accessToken,
            'is_carousel_item' => 'true',
        ];
        if ($isVid) {
            $fields['media_type'] = 'VIDEO';
            $fields['video_url'] = $u;
        } else {
            $fields['image_url'] = $u;
        }
        $child = ff_instagram_graph_post($igUserId . '/media', $fields);
        $cid = $child['id'] ?? null;
        if (!$cid) {
            return ['ok' => false, 'error' => $child['error']['message'] ?? 'Carousel child container failed', 'detail' => $child];
        }
        $childIds[] = (string) $cid;
        sleep(1);
    }
    $parent = ff_instagram_graph_post($igUserId . '/media', [
        'media_type' => 'CAROUSEL',
        'children' => implode(',', $childIds),
        'access_token' => $accessToken,
    ]);
    $containerId = $parent['id'] ?? null;
    if (!$containerId) {
        return ['ok' => false, 'error' => $parent['error']['message'] ?? 'Carousel parent container failed', 'detail' => $parent];
    }
    $pub = ff_instagram_publish_creation_id($igUserId, $accessToken, (string) $containerId);
    $mediaId = $pub['id'] ?? null;
    if (!$mediaId) {
        return ['ok' => false, 'error' => $pub['error']['message'] ?? 'media_publish failed', 'detail' => $pub];
    }

    return ['ok' => true, 'media_id' => (string) $mediaId];
}

/**
 * Publish one content item to Instagram (image, Reels, or carousel). Used by UI and auto-post cron.
 *
 * @return array{ok: bool, media_id?: string, error?: string}
 */
function formatforge_publish_to_instagram(array $item, array $acc, string $authHeader, string $accountId): array {
    if (ff_content_item_is_fetched_for_snapshot($item)) {
        return ['ok' => false, 'error' => 'Fetched reference media cannot be published.'];
    }
    $igToken = trim((string) ($acc['access_token'] ?? ''));
    $igUserId = trim((string) ($acc['instagram_user_id'] ?? ''));
    if ($igToken === '' || $igUserId === '') {
        return ['ok' => false, 'error' => 'Instagram account missing token or user id'];
    }
    $kind = ff_content_item_instagram_kind($item);
    if ($kind === 'carousel') {
        $urls = ff_content_item_carousel_media_urls($item, $authHeader);
        if (count($urls) >= 2) {
            return ff_instagram_publish_carousel($igUserId, $igToken, $urls);
        }
        $single = $urls[0] ?? ff_content_item_effective_media_url($item);
        if ($single === '') {
            return ['ok' => false, 'error' => 'No media URL'];
        }
        $kind = ff_alignment_guess_video_url_from_string($single) ? 'video' : 'image';
    }
    $mediaUrl = ff_content_item_effective_media_url($item);
    if ($mediaUrl === '') {
        return ['ok' => false, 'error' => 'No media URL'];
    }
    if ($kind === 'image') {
        return ff_instagram_publish_single_image($igUserId, $igToken, $mediaUrl);
    }

    return ff_instagram_publish_reels($igUserId, $igToken, $mediaUrl);
}

/**
 * @return array{ok: bool, scheduled?: int, error?: string, note?: string}
 */
function ff_auto_post_schedule_fill(string $authHeader): array {
    if (!ff_auto_post_enabled()) {
        return ['ok' => true, 'skipped' => 'AUTO_POST_ENABLED not 1'];
    }
    $days = max(1, ff_auto_post_env_int('AUTO_POST_SATURATION_DAYS', 7));
    $totalPerDay = max(1, ff_auto_post_env_int('AUTO_POST_TOTAL_PER_DAY', 30));
    $defAcct = trim((string) (getenv('AUTO_POST_DEFAULT_ACCOUNT_ID') ?: ''));
    $now = time();
    $windowEnd = $now + $days * 86400;
    $scheduled = 0;
    $esc = ff_pb_filter_string('approved');
    $q = http_build_query([
        'filter' => 'status = "' . $esc . '"',
        'perPage' => 500,
        'sort' => '@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        return ['ok' => false, 'error' => $lr['body']['message'] ?? 'List approved content failed'];
    }
    $byAccount = [];
    foreach ($lr['body']['items'] ?? [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        if (ff_content_item_is_fetched_for_snapshot($it)) {
            continue;
        }
        $st = strtolower(trim((string) ($it['status'] ?? '')));
        if ($st !== 'approved') {
            continue;
        }
        if (!empty($it['scheduled_publish_at'])) {
            continue;
        }
        $accId = trim((string) ($it['social_account_id'] ?? ''));
        if ($accId === '') {
            $accId = $defAcct;
        }
        if ($accId === '') {
            continue;
        }
        if (ff_content_item_effective_media_url($it) === '') {
            continue;
        }
        if (ff_content_item_instagram_kind($it) === 'carousel') {
            $meta = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
            $runId = trim((string) ($meta['source_shape_run_id'] ?? ''));
            $idx = (int) ($meta['source_shape_index'] ?? 0);
            if ($runId !== '' && $idx > 1) {
                continue;
            }
        }
        $byAccount[$accId][] = $it;
    }
    foreach ($byAccount as $accId => &$items) {
        $weights = ff_auto_post_impression_weights_for_account($accId, $authHeader);
        $targets = ff_auto_post_kind_targets_per_day($weights, $totalPerDay);
        $pattern = ff_auto_post_day_kind_pattern($targets);
        if ($pattern === []) {
            continue;
        }
        $slotSpan = 86400 / max(1, count($pattern));
        $takenTs = ff_auto_post_taken_slot_timestamps($accId, $authHeader, $now, $windowEnd);
        $slots = [];
        for ($d = 0; $d < $days; $d++) {
            $dayStart = strtotime('today UTC') + $d * 86400;
            for ($s = 0; $s < count($pattern); $s++) {
                $ts = (int) ($dayStart + $s * $slotSpan);
                if ($ts < $now) {
                    continue;
                }
                $slots[] = ['ts' => $ts, 'kind' => $pattern[$s]];
            }
        }
        foreach ($slots as $slot) {
            $ts = (int) ($slot['ts'] ?? 0);
            $kind = (string) ($slot['kind'] ?? '');
            $key = (string) $ts;
            if ($ts < 1 || $kind === '' || !empty($takenTs[$key])) {
                continue;
            }
            foreach ($items as $idx => $cand) {
                if (!is_array($cand)) {
                    unset($items[$idx]);
                    continue;
                }
                if (ff_content_item_instagram_kind($cand) !== $kind) {
                    continue;
                }
                $id = trim((string) ($cand['id'] ?? ''));
                if ($id === '') {
                    unset($items[$idx]);
                    continue;
                }
                $iso = gmdate('Y-m-d\TH:i:s.v\Z', $ts);
                $up = pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($id), [
                    'status' => 'scheduled',
                    'scheduled_publish_at' => $iso,
                ], $authHeader);
                if (($up['code'] ?? 0) >= 200 && ($up['code'] ?? 0) < 300) {
                    $takenTs[$key] = true;
                    $scheduled++;
                    unset($items[$idx]);
                    break;
                }
            }
        }
    }
    unset($items);

    return ['ok' => true, 'scheduled' => $scheduled];
}

/**
 * @return array<string, bool> keyed by stringified unix second
 */
function ff_auto_post_taken_slot_timestamps(string $accountId, string $authHeader, int $fromTs, int $toTs): array {
    $esc = ff_pb_filter_string($accountId);
    $fromIso = gmdate('Y-m-d\TH:i:s.v\Z', $fromTs);
    $toIso = gmdate('Y-m-d\TH:i:s.v\Z', $toTs);
    $q = http_build_query([
        'filter' => 'social_account_id = "' . $esc . '" && status = "scheduled" && scheduled_publish_at >= "' . $fromIso . '" && scheduled_publish_at <= "' . $toIso . '"',
        'perPage' => 500,
        'sort' => '@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    $out = [];
    if (($lr['code'] ?? 0) !== 200) {
        return $out;
    }
    foreach ($lr['body']['items'] ?? [] as $it) {
        if (!is_array($it) || empty($it['scheduled_publish_at'])) {
            continue;
        }
        $raw = (string) $it['scheduled_publish_at'];
        $t = strtotime($raw . ' UTC');
        if ($t === false) {
            $t = strtotime($raw);
        }
        if ($t === false) {
            continue;
        }
        $out[(string) $t] = true;
    }

    return $out;
}

/**
 * @return array{ok: bool, published?: int, detail?: list<array<string, mixed>>}
 */
function ff_auto_post_publish_due(string $authHeader): array {
    if (!ff_auto_post_enabled()) {
        return ['ok' => true, 'skipped' => 'AUTO_POST_ENABLED not 1'];
    }
    $max = max(1, ff_auto_post_env_int('AUTO_POST_MAX_PUBLISH_PER_TICK', 15));
    $nowIso = gmdate('Y-m-d\TH:i:s.v\Z', time());
    $esc = ff_pb_filter_string('scheduled');
    $q = http_build_query([
        'filter' => 'status = "' . $esc . '" && scheduled_publish_at <= "' . $nowIso . '"',
        'perPage' => $max,
        'sort' => 'scheduled_publish_at',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        return ['ok' => false, 'error' => $lr['body']['message'] ?? 'List scheduled content failed'];
    }
    $published = 0;
    $detail = [];
    foreach ($lr['body']['items'] ?? [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = trim((string) ($it['id'] ?? ''));
        $accId = trim((string) ($it['social_account_id'] ?? ''));
        if ($id === '' || $accId === '') {
            $msg = 'Missing social_account_id on content item';
            if ($id !== '') {
                ff_auto_post_record_publish_failure($authHeader, $it, $msg);
            }
            $detail[] = ['content_item_id' => $id, 'skip' => 'missing_account'];
            continue;
        }
        $acc = pb_request('GET', '/api/collections/social_accounts/records/' . rawurlencode($accId), null, $authHeader);
        if (($acc['code'] ?? 0) !== 200 || !is_array($acc['body'] ?? null)) {
            ff_auto_post_record_publish_failure($authHeader, $it, 'Instagram account record not found');
            $detail[] = ['content_item_id' => $id, 'error' => 'instagram_account_not_found'];
            continue;
        }
        $pub = formatforge_publish_to_instagram($it, $acc['body'], $authHeader, $accId);
        if (!($pub['ok'] ?? false)) {
            $err = (string) ($pub['error'] ?? 'publish_failed');
            ff_auto_post_record_publish_failure($authHeader, $it, $err);
            $detail[] = ['content_item_id' => $id, 'error' => $err];
            continue;
        }
        $mediaId = (string) ($pub['media_id'] ?? '');
        $prevMetrics = is_array($it['metrics'] ?? null) ? $it['metrics'] : [];
        $prevMetrics['instagram_media_id'] = $mediaId;
        $prevMetrics['fetched_at'] = date('c');
        pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($id), [
            'status' => 'published',
            'published_at' => date('c'),
            'social_account_id' => $accId,
            'scheduled_publish_at' => null,
            'metrics' => $prevMetrics,
        ], $authHeader);
        $published++;
        $detail[] = ['content_item_id' => $id, 'media_id' => $mediaId];
    }

    return ['ok' => true, 'published' => $published, 'detail' => $detail];
}

/**
 * Mark a row after a failed Instagram auto-post so the UI can show history (Activity tab).
 */
function ff_auto_post_record_publish_failure(string $authHeader, array $item, string $message): void {
    $id = trim((string) ($item['id'] ?? ''));
    if ($id === '') {
        return;
    }
    $meta = is_array($item['metadata'] ?? null) ? $item['metadata'] : [];
    $meta['auto_post_failure'] = [
        'message' => $message,
        'at' => date('c'),
    ];
    pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($id), [
        'status' => 'publish_failed',
        'scheduled_publish_at' => null,
        'metadata' => $meta,
    ], $authHeader);
}

/**
 * @return array{ok: bool, schedule?: array<string, mixed>, publish?: array<string, mixed>, skipped?: string}
 */
function ff_auto_post_tick(string $authHeader): array {
    if (!ff_auto_post_enabled()) {
        return ['ok' => true, 'skipped' => 'AUTO_POST_ENABLED not 1'];
    }
    $sched = ff_auto_post_schedule_fill($authHeader);
    $pub = ff_auto_post_publish_due($authHeader);
    ff_pipeline_trace_log('auto_post_tick', [
        'schedule' => $sched,
        'publish' => $pub,
    ]);

    return ['ok' => true, 'schedule' => $sched, 'publish' => $pub];
}

/**
 * Pipelines eligible for feed refresh batch: active + prompt_template;
 * account must match metadata.social_account_id when set, or empty (run as this account).
 *
 * @return list<array<string, mixed>>
 */
function ff_pipelines_eligible_for_account_scope(string $accountId, string $authHeader): array {
    $accountId = trim($accountId);
    if ($accountId === '') {
        return [];
    }
    $qP = http_build_query(['filter' => 'is_active != false', 'perPage' => 200, 'sort' => '-@rowid']);
    $pr = pb_request('GET', '/api/collections/pipelines/records?' . $qP, null, $authHeader);
    if (($pr['code'] ?? 0) !== 200) {
        return [];
    }
    $out = [];
    foreach ($pr['body']['items'] ?? [] as $p) {
        if (!is_array($p)) {
            continue;
        }
        $tmpl = trim((string) ($p['prompt_template'] ?? ''));
        if ($tmpl === '') {
            continue;
        }
        if (array_key_exists('is_active', $p) && $p['is_active'] === false) {
            continue;
        }
        $pm = is_array($p['metadata'] ?? null) ? $p['metadata'] : [];
        $scope = trim((string) ($pm['social_account_id'] ?? ''));
        if ($scope !== '' && $scope !== $accountId) {
            continue;
        }
        $out[] = $p;
    }
    return $out;
}

/**
 * After feed content reload: spawn pipeline-generate-once for scoped pipelines (same guards as cron).
 *
 * @return array{ok: bool, started?: int, candidates?: int, skipped?: string, error?: string, detail?: list<array<string, mixed>>}
 */
function ff_feed_refresh_generate_for_account(string $accountId, string $authHeader): array {
    $accountId = trim($accountId);
    if ($accountId === '') {
        return ['ok' => false, 'error' => 'Missing account_id'];
    }
    ff_scan_shape_mismatch_gates_for_account($accountId, $authHeader);

    if (strtolower(trim((string) (getenv('FEED_REFRESH_GENERATE_ENABLED') ?: '1'))) === '0') {
        return ['ok' => true, 'skipped' => 'disabled', 'started' => 0];
    }
    $max = max(0, min(50, (int) (getenv('FEED_REFRESH_GENERATE_MAX') ?: '20')));
    if ($max < 1) {
        return ['ok' => true, 'skipped' => 'max_zero', 'started' => 0];
    }
    $maxQueue = max(0, (int) (getenv('FEED_REFRESH_GENERATE_MAX_QUEUE_PER_PIPELINE') ?: (getenv('PIPELINE_CRON_MAX_QUEUE_PER_PIPELINE') ?: '2')));
    $candidates = ff_pipelines_eligible_for_account_scope($accountId, $authHeader);
    if ($candidates === []) {
        return ['ok' => true, 'skipped' => 'no_eligible_pipelines', 'started' => 0, 'candidates' => 0, 'detail' => []];
    }
    $busy = ff_cursor_agent_busy_pipeline_subdirs();
    $started = 0;
    $detail = [];
    $php = ff_php_cli_binary();
    $script = __DIR__ . '/index.php';
    foreach ($candidates as $p) {
        if ($started >= $max) {
            break;
        }
        $pid = trim((string) ($p['id'] ?? ''));
        if ($pid === '') {
            continue;
        }
        if (ff_pipeline_cron_agent_busy_for_pipeline($p, $busy)) {
            $detail[] = ['pipeline_id' => $pid, 'skipped' => 'agent_busy'];
            continue;
        }
        if ($maxQueue > 0 && ff_pipeline_cron_generating_count($pid, $authHeader) >= $maxQueue) {
            $detail[] = ['pipeline_id' => $pid, 'skipped' => 'generating_queue_full'];
            continue;
        }
        $cmd = $php . ' ' . escapeshellarg($script) . ' pipeline-generate-once ' . escapeshellarg($pid) . ' ' . escapeshellarg($accountId) . ' 2>&1';
        $out = shell_exec($cmd);
        $started++;
        $detail[] = [
            'pipeline_id' => $pid,
            'account_id' => $accountId,
            'cli_output' => substr((string) $out, 0, 2000),
        ];
    }
    ff_pipeline_trace_log('feed_refresh_generate', [
        'account_id' => $accountId,
        'started' => $started,
        'candidates' => count($candidates),
    ]);

    return [
        'ok' => true,
        'started' => $started,
        'candidates' => count($candidates),
        'detail' => $detail,
    ];
}

function formatforge_generate_content_action(array $post, string $authHeader): void {
        $pipelineId = trim($post['pipeline_id'] ?? '');
        $userPrompt = trim($post['prompt'] ?? '');
        $sourceId = trim($post['source_id'] ?? '');
        $accountScopeId = trim((string)($post['account_id'] ?? ''));
        $pipelineRecord = null;
        $pipelineBackingSourceId = '';
        if ($pipelineId !== '') {
            $pRes = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $authHeader);
            if ($pRes['code'] !== 200) {
                echo json_encode(['ok' => false, 'error' => 'Pipeline not found']);
                exit;
            }
            $pipelineRecord = $pRes['body'];
            if (array_key_exists('is_active', $pipelineRecord) && $pipelineRecord['is_active'] === false) {
                echo json_encode(['ok' => false, 'error' => 'Pipeline is inactive']);
                exit;
            }
            if ($accountScopeId === '') {
                echo json_encode(['ok' => false, 'error' => 'Select an Instagram account in the top-right scope selector first.']);
                exit;
            }
            $pMeta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
            $pipelineBackingSourceId = trim((string)($pMeta['backing_input_media_id'] ?? ''));
            $existingScope = trim((string)($pMeta['social_account_id'] ?? ''));
            if ($existingScope !== '' && $existingScope !== $accountScopeId) {
                echo json_encode(['ok' => false, 'error' => 'This pipeline is scoped to a different Instagram account.']);
                exit;
            }
            if ($existingScope === '') {
                $pMeta['social_account_id'] = $accountScopeId;
                $upP = pb_request('PATCH', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), ['metadata' => $pMeta], $authHeader);
                if (($upP['code'] ?? 0) >= 200 && ($upP['code'] ?? 0) < 300 && is_array($upP['body'] ?? null)) {
                    $pipelineRecord = $upP['body'];
                }
            }
            $gate = ff_pipeline_quality_gate_check($pipelineRecord);
            if (!$gate['ok']) {
                $why = implode(' ', $gate['reasons']);
                trigger_pipeline_edit_loop_for_gate_violation($pipelineRecord, $why, $authHeader);
                echo json_encode([
                    'ok' => false,
                    'error' => 'Pipeline quality gate failed: ' . $why . ' Edit loop has been triggered.',
                ]);
                exit;
            }
        }
        if ($pipelineRecord !== null && $pipelineBackingSourceId !== '') {
            $pMeta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
            $backingSig = ff_shape_signature_normalize($pMeta['backing_shape_signature'] ?? []);
            $fmtNow = ff_pipeline_default_format($authHeader, $pipelineId);
            $fmtSig = is_array($fmtNow['signature'] ?? null) ? $fmtNow['signature'] : [];
            $hasShapeOverride = ($backingSig !== [] && $fmtSig !== [] && ff_slot_signature_to_string($backingSig) !== ff_slot_signature_to_string($fmtSig));
            if ($sourceId === '') {
                // Enforce backing-shape runs by default unless pipeline format has been shape-overridden after rejects.
                if (!$hasShapeOverride) {
                    $sourceId = $pipelineBackingSourceId;
                }
            } elseif ($sourceId !== $pipelineBackingSourceId && !$hasShapeOverride) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'This pipeline is source-backed and must run against its backing source shape.',
                ]);
                exit;
            }
        }
        // Runtime generation uses operator-provided run instructions when present (optional).
        $prompt = $userPrompt;
        if ($pipelineRecord !== null) {
            $rejAdd = ff_pipeline_rejection_prompt_addendum($pipelineRecord);
            if ($rejAdd !== '') {
                $prompt .= "\n\n" . $rejAdd;
            }
        }
        $metaBase = [];
        if ($pipelineId !== '') {
            $metaBase['pipeline_id'] = $pipelineId;
            if (!empty($pipelineRecord['name'])) {
                $metaBase['pipeline_name'] = $pipelineRecord['name'];
            }
            $plOut = trim((string)($pipelineRecord['output_type'] ?? ''));
            if ($plOut !== '') {
                $metaBase['output_type'] = $plOut;
            }
            $effOut = ff_pipeline_effective_output_type($pipelineRecord);
            if ($effOut !== '') {
                $metaBase['pipeline_output_type'] = $effOut;
            }
        }
        if ($accountScopeId !== '') {
            $metaBase['social_account_id'] = $accountScopeId;
        }
        $metaBase['origin'] = 'generate';

        // Pipeline runs require a source; create generating rows first, then let the pipeline binary complete them.
        $pipelineRunSourceId = '';
        if ($pipelineRecord !== null) {
            $pipelineRunSourceId = trim($sourceId);
            if ($pipelineRunSourceId === '') {
                $pipelineRunSourceId = trim($pipelineBackingSourceId);
            }
            if ($pipelineRunSourceId !== '') {
                $slGet = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($pipelineRunSourceId), null, $authHeader);
                if ($slGet['code'] !== 200) {
                    echo json_encode(['ok' => false, 'error' => 'Source link not found for this run.']);
                    exit;
                }
                $slMeta = is_array($slGet['body']['metadata'] ?? null) ? $slGet['body']['metadata'] : [];
                $slMeta['manual_pipeline_run_requested_at'] = date('c');
                $slMeta['manual_pipeline_run_pipeline_id'] = $pipelineId !== '' ? $pipelineId : null;
                $slMeta['manual_pipeline_run_prompt_excerpt'] = substr((string)$prompt, 0, 180);
                pb_request('PATCH', '/api/collections/input_media/records/' . rawurlencode($pipelineRunSourceId), [
                    'status' => 'pending',
                    'metadata' => $slMeta,
                ], $authHeader);
                ff_pipeline_trace_log('pipeline_binary_source_queued', [
                    'pipeline_id' => $pipelineId !== '' ? $pipelineId : null,
                    'input_media_id' => $pipelineRunSourceId,
                ]);
                // Keep downstream shape/slot generation aligned to the resolved source.
                $sourceId = $pipelineRunSourceId;
            }
        }

        $jobs = [];
        if ($sourceId !== '' && $pipelineRecord !== null) {
            $sourceItems = ff_pb_content_items_for_source_link($authHeader, $sourceId);
            if ($sourceItems === []) {
                echo json_encode([
                    'ok' => false,
                    'error' => 'Backed source shape is missing fetched media rows. Re-fetch/review source media before running this pipeline.',
                ]);
                exit;
            }
            if ($sourceItems !== []) {
                $shapeSignature = array_values(array_map(
                    fn($it) => ff_shape_kind_for_content_type((string)($it['type'] ?? '')),
                    $sourceItems
                ));
                $runId = 'shape-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                $total = count($sourceItems);
                $i = 0;
                foreach ($sourceItems as $src) {
                    $i++;
                    $srcType = strtolower(trim((string)($src['type'] ?? '')));
                    $srcId = trim((string)($src['id'] ?? ''));
                    $srcTitle = trim((string)($src['title'] ?? ''));
                    $slotPrompt = $prompt
                        . "\n\n--- Source shape slot {$i}/{$total} ---\n"
                        . 'Expected slot media kind: ' . ff_shape_kind_for_content_type($srcType) . "\n"
                        . 'Source slot type: ' . ($srcType !== '' ? $srcType : 'unknown')
                        . ($srcTitle !== '' ? "\nSource slot title: {$srcTitle}" : '');
                    $meta = $metaBase;
                    $meta['source_shape_run_id'] = $runId;
                    $meta['source_shape_index'] = $i;
                    $meta['source_shape_total'] = $total;
                    $meta['source_shape_signature'] = $shapeSignature;
                    $meta['source_shape_kind'] = ff_shape_kind_for_content_type($srcType);
                    $meta['source_slide_item_id'] = $srcId !== '' ? $srcId : null;
                    $meta['source_slide_type'] = $srcType !== '' ? $srcType : null;
                    $jobs[] = [
                        'type' => ff_target_generated_type_for_source_type($srcType),
                        'prompt' => $slotPrompt,
                        'title' => substr(($srcTitle !== '' ? $srcTitle : $prompt), 0, 80),
                        'metadata' => $meta,
                    ];
                }
            }
        } elseif ($pipelineRecord !== null) {
            $fmt = ff_pipeline_default_format($authHeader, $pipelineId);
            $sig = $fmt['signature'];
            $ingredients = ff_pipeline_ingredients_list($authHeader, $pipelineId, true);
            if ($sig !== [] && $ingredients !== []) {
                $total = count($sig);
                $runId = 'ing-' . date('YmdHis') . '-' . bin2hex(random_bytes(4));
                $byIndex = [];
                foreach ($ingredients as $ing) {
                    $idx = (int)($ing['slot_index'] ?? 0);
                    if ($idx > 0) {
                        $byIndex[$idx][] = $ing;
                    }
                }
                for ($i = 1; $i <= $total; $i++) {
                    $slotKind = ff_shape_kind_for_content_type((string)$sig[$i - 1]);
                    $pick = null;
                    if (!empty($byIndex[$i])) {
                        foreach ($byIndex[$i] as $cand) {
                            if (ff_shape_kind_for_content_type((string)($cand['slot_kind'] ?? '')) === $slotKind) {
                                $pick = $cand;
                                break;
                            }
                        }
                        if ($pick === null) {
                            $pick = $byIndex[$i][0];
                        }
                    }
                    if ($pick === null) {
                        foreach ($ingredients as $cand) {
                            if (ff_shape_kind_for_content_type((string)($cand['slot_kind'] ?? '')) === $slotKind) {
                                $pick = $cand;
                                break;
                            }
                        }
                    }
                    if ($pick === null) {
                        $pick = $ingredients[0];
                    }
                    $topic = trim((string)($pick['topic'] ?? ''));
                    $titleSeed = trim((string)($pick['title_seed'] ?? ''));
                    $inputUrl = trim((string)($pick['input_url'] ?? ''));
                    $instruction = trim((string)($pick['instruction'] ?? ''));
                    $slotPrompt = $prompt
                        . "\n\n--- Ingredient slot {$i}/{$total} ---\n"
                        . 'Expected slot media kind: ' . $slotKind
                        . ($topic !== '' ? "\nTopic: {$topic}" : '')
                        . ($titleSeed !== '' ? "\nTitle seed: {$titleSeed}" : '')
                        . ($inputUrl !== '' ? "\nInput reference URL: {$inputUrl}" : '')
                        . ($instruction !== '' ? "\nSlot instruction: {$instruction}" : '');
                    $meta = $metaBase;
                    $meta['ingredient_run_id'] = $runId;
                    $meta['ingredient_index'] = $i;
                    $meta['ingredient_total'] = $total;
                    $meta['ingredient_signature'] = $sig;
                    $meta['ingredient_slot_kind'] = $slotKind;
                    $meta['ingredient_id'] = (string)($pick['id'] ?? '');
                    $meta['ingredient_topic'] = $topic !== '' ? $topic : null;
                    $jobs[] = [
                        'type' => ff_target_generated_type_for_source_type($slotKind),
                        'prompt' => $slotPrompt,
                        'title' => substr(($titleSeed !== '' ? $titleSeed : ($topic !== '' ? $topic : $prompt)), 0, 80),
                        'metadata' => $meta,
                    ];
                }
            }
        }
        if ($jobs === []) {
            $jobs[] = [
                'type' => $pipelineRecord !== null ? ff_pipeline_content_item_type($pipelineRecord) : 'reel',
                'prompt' => $prompt,
                'title' => substr($prompt, 0, 80),
                'metadata' => $metaBase,
            ];
        }

        $createdIds = [];
        foreach ($jobs as $job) {
            $rec = pb_request('POST', '/api/collections/output_media/records', [
                'type' => $job['type'],
                'prompt' => $job['prompt'],
                'title' => $job['title'],
                'input_media_id' => $sourceId !== '' ? $sourceId : null,
                'social_account_id' => $accountScopeId !== '' ? $accountScopeId : null,
                'status' => 'generating',
                'metadata' => $job['metadata'] === [] ? (object)[] : $job['metadata'],
            ], $authHeader);
            if ($rec['code'] < 200 || $rec['code'] >= 300) {
                continue;
            }
            $itemId = $rec['body']['id'] ?? null;
            if (!$itemId) {
                continue;
            }
            $createdIds[] = (string)$itemId;
        }
        if ($createdIds === []) {
            echo json_encode(['ok' => false, 'error' => 'Failed to create generation row(s).']);
            exit;
        }

        // Respond immediately: video APIs can take minutes; browsers/proxies time out long requests.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        echo json_encode([
            'ok' => true,
            'id' => $createdIds[0],
            'ids' => $createdIds,
            'batch_count' => count($createdIds),
            'pending' => true,
            'message' => count($createdIds) > 1
                ? ('Shape run started for ' . count($createdIds) . ' slot(s). Open Curate to review grouped output.')
                : 'Generation is running on the server. Open Curate in a minute to review.',
        ]);
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }

        // Detached workers:
        // - pipeline runs: Go binary consumes generating rows by metadata.pipeline_id
        // - direct runs: PHP CLI worker completes each row
        if ($pipelineRecord !== null) {
            $spawn = ff_spawn_pipeline_binary_run($pipelineRecord, $prompt, $sourceId, $accountScopeId, $authHeader);
            if (!($spawn['ok'] ?? false)) {
                $err = (string)($spawn['error'] ?? 'Could not launch pipeline binary.');
                foreach ($createdIds as $cid) {
                    pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode((string)$cid), [
                        'status' => 'failed',
                        'rejected_reason' => '[system] ' . substr($err, 0, 420),
                    ], $authHeader);
                }
                ff_pipeline_trace_log('pipeline_binary_spawn_failed_after_row_create', [
                    'pipeline_id' => $pipelineId !== '' ? $pipelineId : null,
                    'input_media_id' => $sourceId !== '' ? $sourceId : null,
                    'created_ids_count' => count($createdIds),
                    'error' => $err,
                ]);
                ff_trigger_pipeline_agent_after_generation_failure(
                    $pipelineRecord,
                    'binary_spawn',
                    $err,
                    $createdIds,
                    $authHeader
                );
            } else {
                ff_pipeline_trace_log('pipeline_binary_rows_created', [
                    'pipeline_id' => $pipelineId !== '' ? $pipelineId : null,
                    'input_media_id' => $sourceId !== '' ? $sourceId : null,
                    'created_ids_count' => count($createdIds),
                ]);
            }
        } else {
            foreach ($createdIds as $cid) {
                if (!formatforge_spawn_generate_worker($cid)) {
                    formatforge_generate_content_finish($cid, $authHeader);
                }
            }
        }
        exit;
}

$_ffPbMeta = ff_resolve_pocketbase_url_meta();
$pbUrl = $_ffPbMeta['url'];
$siteUrl = getenv('APP_URL');
if (!$siteUrl) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $proto = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
    $siteUrl = $proto . '://' . preg_replace('/:\d+$/', '', $host);
}
$pbPublicUrl = getenv('POCKETBASE_PUBLIC_URL') ?: rtrim($siteUrl, '/');
$galleryCookiesEnv = getenv('GALLERY_DL_COOKIES');
$galleryCookiesPath = ($galleryCookiesEnv !== false && trim((string)$galleryCookiesEnv) !== '')
    ? trim((string)$galleryCookiesEnv)
    : ff_pick_storage_cookie_file();
$ytCookiesEnv = getenv('YT_DLP_COOKIES');
$ytCookiesPath = ($ytCookiesEnv !== false && trim((string)$ytCookiesEnv) !== '')
    ? trim((string)$ytCookiesEnv)
    : $galleryCookiesPath;
$cursorPipelineTriggerDir = getenv('CURSOR_PIPELINE_TRIGGER_DIR');
if (!is_string($cursorPipelineTriggerDir) || trim($cursorPipelineTriggerDir) === '') {
    $legacyPiTrigger = getenv('PI_TRIGGER_DIR');
    $cursorPipelineTriggerDir = (is_string($legacyPiTrigger) && trim($legacyPiTrigger) !== '')
        ? trim($legacyPiTrigger)
        : (ff_cursor_pipeline_runtime_base() . '/triggers');
} else {
    $cursorPipelineTriggerDir = trim($cursorPipelineTriggerDir);
}

$caeEnv = getenv('CURSOR_AGENT_ENABLED');
if ($caeEnv === false || trim((string) $caeEnv) === '') {
    $caeEnv = '1';
}
$cursorAgentEnabled = !in_array(strtolower(trim((string) $caeEnv)), ['0', 'false', 'no', 'off'], true);

$cursorAgentSudoUserEnv = getenv('CURSOR_AGENT_SUDO_USER');
$cursorAgentSudoUser = '';
if (is_string($cursorAgentSudoUserEnv)) {
    $cursorAgentSudoUserEnv = trim($cursorAgentSudoUserEnv);
    if ($cursorAgentSudoUserEnv !== '' && preg_match('/^[a-z_][a-z0-9_-]{0,31}$/i', $cursorAgentSudoUserEnv)) {
        $cursorAgentSudoUser = $cursorAgentSudoUserEnv;
    }
}
$cursorAgentRunWrapperEnv = getenv('CURSOR_AGENT_RUN_WRAPPER');
$cursorAgentRunWrapper = (is_string($cursorAgentRunWrapperEnv) && trim($cursorAgentRunWrapperEnv) !== '')
    ? trim($cursorAgentRunWrapperEnv)
    : '/usr/local/sbin/formatforge-cursor-agent-run';

/**
 * Browser-facing Garage base URL. Prefer GARAGE_PUBLIC_URL; else build virtual-hosted
 * {scheme}://{GARAGE_BUCKET}.web.{GARAGE_PUBLIC_ROOT_DOMAIN} when root domain is set.
 */
function ff_resolve_garage_public_url(): string {
    $explicit = trim((string) (getenv('GARAGE_PUBLIC_URL') ?: ''));
    if ($explicit !== '') {
        $out = rtrim($explicit, '/');
        return ff_upgrade_http_media_url_if_app_https($out);
    }
    $root = trim((string) (getenv('GARAGE_PUBLIC_ROOT_DOMAIN') ?: getenv('GARAGE_WEB_HOST') ?: ''));
    if ($root === '') {
        return '';
    }
    $root = trim($root, '/');
    $bucket = trim((string) (getenv('GARAGE_BUCKET') ?: 'formatforge'));
    $scheme = 'https';
    $schemeEnv = strtolower(trim((string) (getenv('GARAGE_PUBLIC_SCHEME') ?: '')));
    if ($schemeEnv === 'http' || $schemeEnv === 'https') {
        $scheme = $schemeEnv;
    } elseif (getenv('APP_URL')) {
        $u = parse_url((string) getenv('APP_URL'));
        if (!empty($u['scheme']) && $u['scheme'] === 'http') {
            $scheme = 'http';
        }
    }
    return "{$scheme}://{$bucket}.web.{$root}";
}

$_ffGeminiEmbedDimEnv = getenv('GEMINI_EMBED_OUTPUT_DIMENSIONALITY');
$_ffGeminiEmbedDim = ($_ffGeminiEmbedDimEnv !== false && trim((string)$_ffGeminiEmbedDimEnv) !== '') ? max(1, (int)$_ffGeminiEmbedDimEnv) : null;

$CONFIG = [
    'pocketbase_url'   => $pbUrl,
    /** How pocketbase_url was chosen: POCKETBASE_URL | .pb-port | default */
    'pocketbase_url_resolution' => $_ffPbMeta['source'],
    'pocketbase_public_url' => $pbPublicUrl,
    /** Admin UI: under nginx, PB is proxied at /_/ so this is usually https://site/_/ */
    'pocketbase_admin_url' => rtrim($pbPublicUrl, '/') . '/_/',
    'site_url'         => $siteUrl,
    'site_name'        => getenv('SITE_NAME') ?: 'FormatForge',
    'app_version'      => getenv('APP_VERSION') ?: 'v1.1.154',
    'users_collection' => 'users',
    'garage_endpoint'  => getenv('GARAGE_ENDPOINT') ?: 'http://127.0.0.1:3900',
    'garage_key'       => getenv('GARAGE_ACCESS_KEY') ?: '',
    'garage_secret'    => getenv('GARAGE_SECRET_KEY') ?: '',
    'garage_bucket'    => getenv('GARAGE_BUCKET') ?: 'formatforge',
    'garage_region'    => getenv('GARAGE_REGION') ?: 'garage',
    /** Browser / Instagram: set GARAGE_PUBLIC_URL or GARAGE_PUBLIC_ROOT_DOMAIN (+ GARAGE_BUCKET); S3 API stays on GARAGE_ENDPOINT */
    'garage_public_url' => ff_resolve_garage_public_url(),
    'replicate_token' => getenv('REPLICATE_API_TOKEN') ?: '',
    'fal_key'         => getenv('FAL_KEY') ?: '',
    'video_provider'   => getenv('VIDEO_PROVIDER') ?: '',  // replicate|fal; auto if empty
    'fb_app_id'       => getenv('FB_APP_ID') ?: '',
    'fb_app_secret'    => getenv('FB_APP_SECRET') ?: '',
    'instagram_redirect' => getenv('INSTAGRAM_REDIRECT_URI') ?: '',
    'winning_threshold' => (float)(getenv('WINNING_TEMPLATE_VIEW_SHARE_RATIO') ?: '0.05'),
    'ffmpeg_path'      => getenv('FFMPEG_PATH') ?: '/usr/bin/ffmpeg',
    'gallery_dl_path'   => ff_fetch_executable(ff_resolve_fetch_bin('GALLERY_DL_PATH', 'gallery-dl'), 'gallery-dl'),
    'gallery_dl_cookies' => $galleryCookiesPath,
    'yt_dlp_path'      => ff_fetch_executable(ff_resolve_fetch_bin('YT_DLP_PATH', 'yt-dlp'), 'yt-dlp'),
    'yt_dlp_cookies'   => $ytCookiesPath,
    'embed_url'        => getenv('EMBED_URL') ?: '',  // Ollama-compatible: /api/embed
    'openai_key'       => getenv('OPENAI_API_KEY') ?: '',
    'openrouter_key'   => getenv('OPENROUTER_API_KEY') ?: '',
    'embed_model'      => getenv('EMBED_MODEL') ?: 'google/gemini-embedding-001',  // OpenRouter embeddings id (optional PHP fallback)
    /** Google AI Gemini API (AI Studio) — same REST as `google.genai` SDK; preferred for PHP `embed_text` + generation alignment when set. */
    'gemini_api_key'   => getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_GENAI_API_KEY') ?: '',
    'gemini_embed_model' => getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview',
    'gemini_embed_output_dimensionality' => $_ffGeminiEmbedDim,
    'gemini_embed_media_max_bytes' => max(1048576, (int)(getenv('GEMINI_EMBED_MEDIA_MAX_BYTES') ?: '26214400')),
    'cursor_pipeline_trigger_dir' => $cursorPipelineTriggerDir,
    'novel_threshold'  => (float)(getenv('NOVEL_DISTANCE_THRESHOLD') ?: '0.35'),  // cosine distance above = novel
    /** When true and OPENROUTER_API_KEY is set, measure OpenRouter embedding cosine(input_ref, output) on generated rows; compare to previous same-pipeline generation. Set GENERATION_ALIGNMENT_ENABLED=0 to disable. */
    'generation_alignment_enabled' => strtolower(trim((string)(getenv('GENERATION_ALIGNMENT_ENABLED') ?: '1'))) !== '0',
    'target_posts_per_day' => max(0, (int)(getenv('TARGET_POSTS_PER_DAY') ?: '60')),  // Cursor prompt + operating_context (0 = omit cadence hints)
    'pipeline_reject_streak' => max(0, (int)(getenv('PIPELINE_REJECT_STREAK') ?: '1')),  // 0 = off; N = after N consecutive rejects for same pipeline_id
    'cursor_agent_bin' => getenv('CURSOR_AGENT_BIN') ?: 'agent',
    'cursor_agent_model' => (static function (): string {
        $e = getenv('CURSOR_AGENT_MODEL');
        if (is_string($e) && trim($e) !== '') {
            return trim($e);
        }
        return ff_cursor_agent_model_default();
    })(),
    /**
     * Cursor CLI --output-format when running cursor-agent-run: text | json | stream-json.
     * stream-json logs NDJSON (user / assistant segments + tool_call started/completed) to cursor-agent.log — closest to a chat transcript.
     * Note: Cursor suppresses "thinking" events in print mode for all formats (docs).
     */
    'cursor_agent_output_format' => (static function (): string {
        $f = strtolower(trim((string)(getenv('CURSOR_AGENT_OUTPUT_FORMAT') ?: 'text')));
        return in_array($f, ['text', 'json', 'stream-json'], true) ? $f : 'text';
    })(),
    /** Set CURSOR_AGENT_STREAM_PARTIAL_OUTPUT=1 with stream-json for character-level assistant deltas (noisier log). */
    'cursor_agent_stream_partial_output' => strtolower(trim((string)(getenv('CURSOR_AGENT_STREAM_PARTIAL_OUTPUT') ?: '0'))) === '1',
    'cursor_agent_home' => (static function (): string {
        $h = getenv('CURSOR_AGENT_HOME');
        return (is_string($h) && trim($h) !== '') ? trim($h) : '';
    })(),
    /** Pipeline triggers always invoke the Cursor CLI when possible; set CURSOR_AGENT_ENABLED=0 only for emergency debugging. */
    'cursor_agent_enabled' => $cursorAgentEnabled,
    /**
     * When non-empty (e.g. jhs), spawn uses: sudo -n -u USER -- CURSOR_AGENT_RUN_WRAPPER prompt.md
     * (e.g. FPM cannot write pipelines/ or needs deploy-user PATH for agent/Go). Requires sudoers + wrapper.
     */
    'cursor_agent_sudo_user' => $cursorAgentSudoUser,
    'cursor_agent_run_wrapper' => $cursorAgentRunWrapper,
    /**
     * Optional URL to a browser TTY (e.g. ttyd reverse-proxied on HTTPS). PHP-FPM cannot host an interactive PTY; use a WebSocket terminal service. See DEPLOYMENT.md § Agent web terminal.
     */
    'agent_web_tty_url' => trim((string)(getenv('AGENT_WEB_TTY_URL') ?: '')),
];
if (file_exists(__DIR__ . '/config.php')) {
    $CONFIG = array_merge($CONFIG, require __DIR__ . '/config.php');
}

function ff_debug_sanitize($value, string $key = '') {
    $k = strtolower($key);
    // Booleans like "were cookies configured" — not secret contents
    if (in_array($k, ['cookies_file_used', 'using_cookies_file'], true)) {
        return $value;
    }
    foreach (['token', 'secret', 'password', 'authorization', 'cookie'] as $sensitive) {
        if (str_contains($k, $sensitive)) return '[redacted]';
    }
    if (is_array($value)) {
        $out = [];
        foreach ($value as $kk => $vv) $out[$kk] = ff_debug_sanitize($vv, (string)$kk);
        return $out;
    }
    if (is_object($value)) return ff_debug_sanitize((array)$value, $key);
    if (is_string($value)) {
        if (strlen($value) > 500) return substr($value, 0, 500) . '…(' . strlen($value) . ' chars)';
        return $value;
    }
    return $value;
}

function ff_debug_log(string $event, array $context = []): void {
    if (!isset($_SESSION['ff_debug_logs']) || !is_array($_SESSION['ff_debug_logs'])) $_SESSION['ff_debug_logs'] = [];
    $entry = [
        'ts' => date('c'),
        'event' => $event,
        'context' => ff_debug_sanitize($context),
    ];
    $_SESSION['ff_debug_logs'][] = $entry;
    if (count($_SESSION['ff_debug_logs']) > 200) {
        $_SESSION['ff_debug_logs'] = array_slice($_SESSION['ff_debug_logs'], -200);
    }
}

function ff_debug_logs_get(): array {
    $logs = $_SESSION['ff_debug_logs'] ?? [];
    return is_array($logs) ? $logs : [];
}

function ff_debug_logs_clear(): void {
    $_SESSION['ff_debug_logs'] = [];
}

/** Non-secret snapshot for in-app debug export (support / troubleshooting). */
function ff_ui_debug_public_snapshot(): array {
    $c = $GLOBALS['CONFIG'] ?? [];
    return [
        'app_version' => $c['app_version'] ?? '',
        'site_name' => $c['site_name'] ?? '',
        'site_url' => $c['site_url'] ?? '',
        'pocketbase_public_url' => $c['pocketbase_public_url'] ?? '',
        'php_version' => PHP_VERSION,
        'server_time' => date('c'),
    ];
}

/** Collections the logged-in dashboard may access via pb_proxy (browser → PHP → POCKETBASE_URL). */
function ff_pb_proxy_collections(): array {
    return ['social_accounts', 'input_media', 'output_media', 'pipelines'];
}

function pb_superuser_auth_token(): array {
    $email = getenv('ADMIN_EMAIL') ?: '';
    $pass = getenv('ADMIN_PASSWORD') ?: '';
    if ($email === '' || $pass === '') {
        return ['ok' => false, 'error' => 'Missing ADMIN_EMAIL/ADMIN_PASSWORD'];
    }
    $last = null;
    foreach (['/api/admins/auth-with-password', '/api/collections/_superusers/auth-with-password'] as $path) {
        $res = pb_request('POST', $path, ['identity' => $email, 'password' => $pass], null);
        $last = $res;
        if ($res['code'] >= 200 && $res['code'] < 300 && !empty($res['body']['token'])) {
            return ['ok' => true, 'token' => $res['body']['token'], 'path' => $path];
        }
    }
    return ['ok' => false, 'error' => $last['body']['message'] ?? 'Superuser auth failed', 'details' => $last['body'] ?? []];
}

function pb_fetch_collection(string $name, string $token): array {
    $res = pb_request('GET', '/api/collections/' . rawurlencode($name), null, $token);
    if ($res['code'] >= 200 && $res['code'] < 300 && !empty($res['body']['name'])) {
        return ['ok' => true, 'collection' => $res['body']];
    }
    $safeName = str_replace(['\\', '"'], ['\\\\', '\"'], $name);
    $query = http_build_query(['filter' => 'name="' . $safeName . '"', 'perPage' => 1]);
    $res2 = pb_request('GET', '/api/collections?' . $query, null, $token);
    $item = $res2['body']['items'][0] ?? null;
    if ($res2['code'] >= 200 && $res2['code'] < 300 && is_array($item)) {
        return ['ok' => true, 'collection' => $item];
    }
    return ['ok' => false, 'error' => $res2['body']['message'] ?? ($res['body']['message'] ?? 'Collection not found')];
}

function repair_instagram_accounts_schema(): array {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) return ['ok' => false, 'error' => $auth['error'] ?? 'Superuser auth failed'];
    $token = $auth['token'];
    $fetched = pb_fetch_collection('social_accounts', $token);
    if (!$fetched['ok']) return ['ok' => false, 'error' => $fetched['error'] ?? 'Collection fetch failed'];
    $collection = $fetched['collection'];
    $fields = is_array($collection['fields'] ?? null) ? $collection['fields'] : [];
    $required = [
        ['name' => 'platform', 'type' => 'text'],
        ['name' => 'instagram_user_id', 'type' => 'text'],
        ['name' => 'username', 'type' => 'text'],
        ['name' => 'access_token', 'type' => 'text'],
        ['name' => 'token_expires_at', 'type' => 'date'],
        ['name' => 'is_active', 'type' => 'bool'],
        ['name' => 'metadata', 'type' => 'json'],
    ];
    $changed = false;

    foreach ($required as $req) {
        $idx = null;
        foreach ($fields as $i => $f) {
            if (($f['name'] ?? '') === $req['name']) { $idx = $i; break; }
        }
        if ($idx === null) {
            $fields[] = [
                'name' => $req['name'],
                'type' => $req['type'],
                'required' => false,
                'hidden' => false,
            ];
            $changed = true;
            continue;
        }
        $field = $fields[$idx];
        if (($field['type'] ?? '') !== $req['type']) {
            ff_debug_log('repair_schema_type_mismatch', ['field' => $req['name'], 'current_type' => $field['type'] ?? null, 'expected_type' => $req['type']]);
        }
        if (!empty($field['hidden'])) {
            $fields[$idx]['hidden'] = false;
            $changed = true;
        }
    }

    if (!$changed) {
        return ['ok' => true, 'changed' => false, 'message' => 'Schema already compatible'];
    }

    $payload = [
        'name' => $collection['name'] ?? 'social_accounts',
        'type' => $collection['type'] ?? 'base',
        'listRule' => $collection['listRule'] ?? '@request.auth.id != ""',
        'viewRule' => $collection['viewRule'] ?? '@request.auth.id != ""',
        'createRule' => $collection['createRule'] ?? '@request.auth.id != ""',
        'updateRule' => $collection['updateRule'] ?? '@request.auth.id != ""',
        'deleteRule' => $collection['deleteRule'] ?? '@request.auth.id != ""',
        'fields' => $fields,
    ];
    if (isset($collection['indexes'])) $payload['indexes'] = $collection['indexes'];

    $collectionId = $collection['id'] ?? 'social_accounts';
    $up = pb_request('PATCH', '/api/collections/' . rawurlencode($collectionId), $payload, $token);
    if ($up['code'] >= 200 && $up['code'] < 300) {
        return ['ok' => true, 'changed' => true, 'collection_id' => $collectionId];
    }
    return ['ok' => false, 'error' => $up['body']['message'] ?? ('HTTP ' . $up['code']), 'details' => $up['body'] ?? []];
}

function repair_source_links_schema(): array {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        return ['ok' => false, 'error' => $auth['error'] ?? 'Superuser auth failed'];
    }
    $token = $auth['token'];
    $fetched = pb_fetch_collection('input_media', $token);
    if (!$fetched['ok']) {
        return ['ok' => false, 'error' => $fetched['error'] ?? 'Collection fetch failed'];
    }
    $collection = $fetched['collection'];
    $fields = is_array($collection['fields'] ?? null) ? $collection['fields'] : [];
    $required = [
        ['name' => 'role', 'type' => 'text'],
        ['name' => 'url', 'type' => 'text'],
        ['name' => 'title', 'type' => 'text'],
        ['name' => 'status', 'type' => 'text'],
        ['name' => 'metadata', 'type' => 'json'],
    ];
    $changed = false;
    foreach ($required as $req) {
        $idx = null;
        foreach ($fields as $i => $f) {
            if (($f['name'] ?? '') === $req['name']) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            $fields[] = [
                'name' => $req['name'],
                'type' => $req['type'],
                'required' => false,
                'hidden' => false,
            ];
            $changed = true;
            continue;
        }
        $field = $fields[$idx];
        if (($field['type'] ?? '') !== $req['type']) {
            ff_debug_log('repair_source_links_type_mismatch', ['field' => $req['name'], 'current_type' => $field['type'] ?? null, 'expected_type' => $req['type']]);
        }
        if (!empty($field['hidden'])) {
            $fields[$idx]['hidden'] = false;
            $changed = true;
        }
    }
    if (!$changed) {
        return ['ok' => true, 'changed' => false, 'message' => 'Schema already compatible'];
    }
    $payload = [
        'name' => $collection['name'] ?? 'input_media',
        'type' => $collection['type'] ?? 'base',
        'listRule' => $collection['listRule'] ?? '@request.auth.id != ""',
        'viewRule' => $collection['viewRule'] ?? '@request.auth.id != ""',
        'createRule' => $collection['createRule'] ?? '@request.auth.id != ""',
        'updateRule' => $collection['updateRule'] ?? '@request.auth.id != ""',
        'deleteRule' => $collection['deleteRule'] ?? '@request.auth.id != ""',
        'fields' => $fields,
    ];
    if (isset($collection['indexes'])) {
        $payload['indexes'] = $collection['indexes'];
    }
    $collectionId = $collection['id'] ?? 'input_media';
    $up = pb_request('PATCH', '/api/collections/' . rawurlencode($collectionId), $payload, $token);
    if ($up['code'] >= 200 && $up['code'] < 300) {
        return ['ok' => true, 'changed' => true, 'collection_id' => $collectionId];
    }
    return ['ok' => false, 'error' => $up['body']['message'] ?? ('HTTP ' . $up['code']), 'details' => $up['body'] ?? []];
}

function is_internal_network(): bool {
    if (getenv('ALLOW_SIGNUP') === '1' || getenv('ALLOW_SIGNUP') === 'true') {
        return true;
    }
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $ip = trim(explode(',', $ip)[0] ?? '');
    if (!$ip || $ip === '::1') return true;
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return str_starts_with($ip, '100.') || str_starts_with($ip, '10.') || str_starts_with($ip, '192.168.')
            || preg_match('/^172\.(1[6-9]|2\d|3[01])\./', $ip);
    }
    return false;
}

/**
 * Whether to show the optional AGENT_WEB_TTY_URL link in the UI (Curate → Pipelines). Default: only on RFC1918/ULA client IPs.
 */
function ff_agent_web_tty_link_visible(): bool {
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $url = trim((string)($cfg['agent_web_tty_url'] ?? ''));
    if ($url === '') {
        return false;
    }
    $internalOnly = strtolower(trim((string)(getenv('AGENT_WEB_TTY_INTERNAL_ONLY') ?: '1'))) !== '0';

    return !$internalOnly || is_internal_network();
}

function pb_request(string $method, string $path, $data = null, ?string $token = null): array {
    $ch = curl_init($GLOBALS['CONFIG']['pocketbase_url'] . $path);
    $headers = ['Accept: application/json'];
    $methodUpper = strtoupper($method);
    $sendsBody = $data !== null && in_array($methodUpper, ['POST', 'PATCH', 'PUT'], true);
    if ($sendsBody) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token) {
        $headers[] = 'Authorization: ' . $token;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
    }
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    return ['code' => $code, 'body' => $body, 'raw' => $res ?: '', 'curl_errno' => $errNo];
}

/** Multipart create/update for PocketBase file fields (multipart/form-data, not JSON). */
function pb_request_multipart(string $method, string $path, array $postData, ?string $token): array {
    $url = $GLOBALS['CONFIG']['pocketbase_url'] . $path;
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => $postData,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    return ['code' => $code, 'body' => $body, 'raw' => $res ?: '', 'curl_errno' => $errNo];
}

/**
 * Normalizes a PocketBase auth value for pb_request() (header value after "Authorization: ").
 */
function pb_normalize_bearer_token(?string $token): ?string {
    if ($token === null || $token === '') {
        return null;
    }
    $t = trim($token);
    if (stripos($t, 'Bearer ') === 0) {
        return $t;
    }
    return 'Bearer ' . $t;
}

/**
 * Vectorbase (libSQL fork) optional API: vector-search demo status. Superuser only (pb_superuser_auth_token()).
 * Returns 404 from stock PocketBase; use with the vectorbase binary built from examples/base.
 *
 * @return array{code: int, body: array, raw: string, curl_errno: int}
 */
function pb_vector_search_status(?string $superuserToken): array {
    return pb_request('GET', '/api/vector-search/status', null, pb_normalize_bearer_token($superuserToken));
}

/**
 * Vectorbase optional API: k-NN style query over the demo table. Superuser only.
 *
 * @param array<int, float> $queryVector Same dimensionality as seeded demo rows (4 floats in default seed).
 * @return array{code: int, body: array, raw: string, curl_errno: int}
 */
function pb_vector_search_query(array $queryVector, int $limit = 5, string $metric = 'cos', ?string $superuserToken = null): array {
    $limit = max(1, min(100, $limit));
    return pb_request('POST', '/api/vector-search/query', [
        'queryVector' => array_values($queryVector),
        'limit' => $limit,
        'metric' => $metric,
    ], pb_normalize_bearer_token($superuserToken));
}

function ff_curl_file(string $path, string $mime, string $postname): CURLFile {
    if (function_exists('curl_file_create')) {
        return curl_file_create($path, $mime, $postname);
    }
    return new CURLFile($path, $mime, $postname);
}

/** Public URL for a PocketBase file field (uses site-facing proxy /api/files/...). */
function ff_pb_public_file_url(string $collectionId, string $recordId, string $filename): string {
    $collectionId = trim($collectionId);
    $recordId = trim($recordId);
    $filename = trim($filename);
    if ($collectionId === '' || $recordId === '' || $filename === '') {
        return '';
    }
    $base = rtrim((string) ($GLOBALS['CONFIG']['pocketbase_public_url'] ?? ''), '/');
    if ($base === '') {
        return '';
    }
    return $base . '/api/files/' . rawurlencode($collectionId) . '/' . rawurlencode($recordId) . '/' . rawurlencode($filename);
}

/**
 * PocketBase internal id for the output_media collection. List responses often omit `collectionId` per row;
 * set POCKETBASE_OUTPUT_MEDIA_COLLECTION_ID (or legacy POCKETBASE_CONTENT_ITEMS_COLLECTION_ID) in .env to skip lookup.
 */
function ff_content_items_collection_id(): string {
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $env = trim((string) (getenv('POCKETBASE_OUTPUT_MEDIA_COLLECTION_ID') ?: getenv('POCKETBASE_CONTENT_ITEMS_COLLECTION_ID') ?: ''));
    if ($env !== '') {
        $cached = $env;
        return $cached;
    }
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        $cached = '';
        return $cached;
    }
    $f = pb_fetch_collection('output_media', $auth['token']);
    $cached = ($f['ok'] ?? false) ? trim((string) ($f['collection']['id'] ?? '')) : '';
    return $cached;
}

/** Prefer media_file on PocketBase; else garage_url / thumbnail. */
function ff_content_item_effective_media_url(array $item): string {
    $fn = trim((string) ($item['media_file'] ?? ''));
    $cid = trim((string) ($item['collectionId'] ?? ''));
    $rid = trim((string) ($item['id'] ?? ''));
    if ($cid === '') {
        $cid = ff_content_items_collection_id();
    }
    if ($fn !== '' && $cid !== '' && $rid !== '') {
        $u = ff_pb_public_file_url($cid, $rid, $fn);
        if ($u !== '') {
            return $u;
        }
    }
    $g = trim((string) ($item['garage_url'] ?? ''));
    if ($g !== '') {
        return ff_upgrade_http_media_url_if_app_https($g);
    }
    return ff_upgrade_http_media_url_if_app_https(trim((string) ($item['thumbnail_url'] ?? '')));
}

/**
 * Injects collectionId when missing and ff_display_media_url (PocketBase /api/files/… first) for dashboard clients.
 */
function ff_pb_enrich_content_items_response(array $body): array {
    if (isset($body['items']) && is_array($body['items'])) {
        $collId = ff_content_items_collection_id();
        foreach ($body['items'] as $i => $it) {
            if (!is_array($it)) {
                continue;
            }
            if ($collId !== '' && empty($it['collectionId'])) {
                $it['collectionId'] = $collId;
            }
            $url = ff_content_item_effective_media_url($it);
            if ($url !== '') {
                $it['ff_display_media_url'] = $url;
            }
            $body['items'][$i] = $it;
        }
        return $body;
    }
    if (isset($body['id'])) {
        $collId = ff_content_items_collection_id();
        if ($collId !== '' && empty($body['collectionId'])) {
            $body['collectionId'] = $collId;
        }
        $url = ff_content_item_effective_media_url($body);
        if ($url !== '') {
            $body['ff_display_media_url'] = $url;
        }
    }
    return $body;
}

function repair_content_items_media_schema(): array {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        return ['ok' => false, 'error' => $auth['error'] ?? 'Superuser auth failed'];
    }
    $token = $auth['token'];
    $fetched = pb_fetch_collection('output_media', $token);
    if (!$fetched['ok']) {
        return ['ok' => false, 'error' => $fetched['error'] ?? 'Collection fetch failed'];
    }
    $collection = $fetched['collection'];
    $fields = is_array($collection['fields'] ?? null) ? $collection['fields'] : [];
    $has = false;
    foreach ($fields as $f) {
        if (($f['name'] ?? '') === 'media_file') {
            $has = true;
            break;
        }
    }
    if ($has) {
        return ['ok' => true, 'changed' => false, 'message' => 'media_file already present'];
    }
    $fields[] = [
        'name' => 'media_file',
        'type' => 'file',
        'maxSelect' => 1,
        'maxSize' => 1073741824,
        'mimeTypes' => [],
        'required' => false,
        'hidden' => false,
    ];
    $payload = [
        'name' => $collection['name'] ?? 'output_media',
        'type' => $collection['type'] ?? 'base',
        'listRule' => $collection['listRule'] ?? '@request.auth.id != ""',
        'viewRule' => $collection['viewRule'] ?? '@request.auth.id != ""',
        'createRule' => $collection['createRule'] ?? '@request.auth.id != ""',
        'updateRule' => $collection['updateRule'] ?? '@request.auth.id != ""',
        'deleteRule' => $collection['deleteRule'] ?? '@request.auth.id != ""',
        'fields' => $fields,
    ];
    if (isset($collection['indexes'])) {
        $payload['indexes'] = $collection['indexes'];
    }
    $collectionId = $collection['id'] ?? 'output_media';
    $up = pb_request('PATCH', '/api/collections/' . rawurlencode($collectionId), $payload, $token);
    if ($up['code'] >= 200 && $up['code'] < 300) {
        return ['ok' => true, 'changed' => true, 'collection_id' => $collectionId];
    }
    return ['ok' => false, 'error' => $up['body']['message'] ?? ('HTTP ' . $up['code']), 'details' => $up['body'] ?? []];
}

/** PocketBase API: map field name -> validation message from `data` (no secrets). */
function pb_record_validation_messages(?array $body): array {
    if (!is_array($body)) {
        return [];
    }
    $data = $body['data'] ?? null;
    if (!is_array($data)) {
        return [];
    }
    $out = [];
    foreach ($data as $field => $info) {
        if (is_array($info)) {
            $msg = isset($info['message']) ? (string) $info['message'] : '';
            $code = isset($info['code']) ? (string) $info['code'] : '';
            $out[$field] = trim($msg . ($code !== '' && $code !== $msg ? ' (' . $code . ')' : ''));
        } else {
            $out[$field] = (string) $info;
        }
    }
    return array_filter($out, static fn (string $v): bool => $v !== '');
}

function normalize_instagram_username(?string $username): ?string {
    if ($username === null) return null;
    $u = trim(ltrim((string)$username, '@'));
    if ($u === '') return null;
    $lower = strtolower($u);
    if (in_array($lower, ['undefined', 'null', 'account', 'active', 'inactive', 'n/a', 'na'], true)) return null;
    return $u;
}

function fetch_instagram_username(string $igUserId, array $tokens): ?string {
    $igUserId = trim($igUserId);
    if ($igUserId === '') return null;
    $seenTokens = [];
    foreach ($tokens as $token) {
        $tok = trim((string)$token);
        if ($tok === '' || isset($seenTokens[$tok])) continue;
        $seenTokens[$tok] = true;
        foreach (['https://graph.instagram.com', 'https://graph.facebook.com'] as $host) {
            $ch = curl_init("{$host}/v18.0/{$igUserId}?fields=username&access_token=" . urlencode($tok));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch);
            curl_close($ch);
            $body = json_decode($res ?: '{}', true) ?? [];
            if (!empty($body['error'])) continue;
            $username = normalize_instagram_username($body['username'] ?? null);
            if ($username) return $username;
        }
    }
    return null;
}

function pb_find_instagram_account_by_user_id(string $igUserId, ?string $authHeader): ?array {
    $igUserId = trim($igUserId);
    if ($igUserId === '' || !$authHeader) return null;
    $safeId = str_replace(['\\', '"'], ['\\\\', '\"'], $igUserId);
    $query = http_build_query(['filter' => 'instagram_user_id="' . $safeId . '"', 'perPage' => 1]);
    $resp = pb_request('GET', '/api/collections/social_accounts/records?' . $query, null, $authHeader);
    if ($resp['code'] !== 200) return null;
    return $resp['body']['items'][0] ?? null;
}

/** IG Graph hosts (Instagram Login tokens often work on graph.instagram.com; some setups need graph.facebook.com). */
function ff_instagram_graph_hosts(): array {
    return ['https://graph.instagram.com/v18.0', 'https://graph.facebook.com/v18.0'];
}

/**
 * @return array|null decoded JSON body or null on failure
 */
function ff_ig_graph_get_json(string $pathQuery, string $accessToken): ?array {
    $pathQuery = ltrim($pathQuery, '/');
    $sep = str_contains($pathQuery, '?') ? '&' : '?';
    $tail = $pathQuery . $sep . 'access_token=' . rawurlencode($accessToken);
    foreach (ff_instagram_graph_hosts() as $base) {
        $ch = curl_init($base . '/' . $tail);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        $body = json_decode($raw ?: '{}', true);
        if (!is_array($body)) {
            continue;
        }
        if (!empty($body['error'])) {
            continue;
        }
        return $body;
    }
    return null;
}

/**
 * @return array{like_count?:int,comments_count?:int,media_product_type?:string,media_type?:string}|null
 */
function ff_instagram_ig_media_node(string $igMediaId, string $accessToken): ?array {
    $igMediaId = trim($igMediaId);
    if ($igMediaId === '') {
        return null;
    }
    $q = rawurlencode($igMediaId) . '?fields=like_count,comments_count,media_product_type,media_type';
    return ff_ig_graph_get_json($q, $accessToken);
}

/**
 * Parse /insights response `data` array into metric name => int value.
 */
function ff_instagram_parse_insights_body(?array $body): array {
    if (!is_array($body)) {
        return [];
    }
    $out = [];
    foreach ($body['data'] ?? [] as $block) {
        if (!is_array($block)) {
            continue;
        }
        $name = (string) ($block['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $v = null;
        if (isset($block['values'][0]['value']) && is_numeric($block['values'][0]['value'])) {
            $v = (int) $block['values'][0]['value'];
        } elseif (isset($block['total_value']['value']) && is_numeric($block['total_value']['value'])) {
            $v = (int) $block['total_value']['value'];
        }
        if ($v !== null) {
            $out[$name] = $v;
        }
    }
    return $out;
}

/**
 * Request IG media insights; tries metric bundles (newer API deprecates `impressions` / `plays` for some media).
 *
 * @return array<string,int>
 */
function ff_instagram_ig_media_insights(string $igMediaId, string $accessToken): array {
    $igMediaId = trim($igMediaId);
    if ($igMediaId === '') {
        return [];
    }
    $bundles = [
        'reach,saved,shares,total_interactions,views,likes,comments',
        'reach,saved,shares,total_interactions,views',
        'reach,saved,shares,comments,likes',
        'impressions,reach,saved,shares',
    ];
    foreach ($bundles as $metric) {
        $path = rawurlencode($igMediaId) . '/insights?metric=' . rawurlencode($metric);
        $body = ff_ig_graph_get_json($path, $accessToken);
        if ($body === null) {
            continue;
        }
        if (!empty($body['error'])) {
            continue;
        }
        $parsed = ff_instagram_parse_insights_body($body);
        if ($parsed !== []) {
            return $parsed;
        }
    }
    return [];
}

/**
 * Pull Instagram Insights for published output_media rows (metrics.instagram_media_id) and PATCH `metrics` JSON.
 *
 * @return array{ok:bool, updated:int, skipped:int, failed:int, detail: list<array<string,mixed>>}
 */
function formatforge_sync_content_metrics_insights(?string $authHeader, int $maxRecords = 80): array {
    $out = ['ok' => true, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'detail' => []];
    if (!$authHeader || trim($authHeader) === '') {
        $out['ok'] = false;
        $out['detail'][] = ['error' => 'missing_auth'];
        return $out;
    }
    $maxRecords = max(1, min(250, $maxRecords));
    $qs = http_build_query(['filter' => 'status = "published"', 'perPage' => $maxRecords, 'sort' => '-published_at']);
    $list = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
    if ($list['code'] !== 200) {
        $out['ok'] = false;
        $out['detail'][] = ['error' => 'list_output_media', 'code' => $list['code']];
        return $out;
    }
    $rows = $list['body']['items'] ?? [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $cid = trim((string) ($row['id'] ?? ''));
        $metrics = is_array($row['metrics'] ?? null) ? $row['metrics'] : [];
        $igMid = trim((string) ($metrics['instagram_media_id'] ?? ''));
        if ($cid === '' || $igMid === '') {
            $out['skipped']++;
            continue;
        }
        $accId = trim((string) ($row['social_account_id'] ?? ''));
        if ($accId === '') {
            $out['skipped']++;
            $out['detail'][] = ['output_media_id' => $cid, 'skip' => 'no_social_account_id'];
            continue;
        }
        $accResp = pb_request('GET', '/api/collections/social_accounts/records/' . rawurlencode($accId), null, $authHeader);
        if ($accResp['code'] !== 200) {
            $out['failed']++;
            $out['detail'][] = ['instagram_media_id' => $igMid, 'error' => 'account_not_found'];
            continue;
        }
        $acc = $accResp['body'] ?? [];
        $token = trim((string) ($acc['access_token'] ?? ''));
        if ($token === '') {
            $out['failed']++;
            $out['detail'][] = ['instagram_media_id' => $igMid, 'error' => 'missing_access_token'];
            continue;
        }
        $node = ff_instagram_ig_media_node($igMid, $token);
        if ($node === null) {
            $out['failed']++;
            $out['detail'][] = ['instagram_media_id' => $igMid, 'error' => 'ig_media_node_failed'];
            continue;
        }
        $insights = ff_instagram_ig_media_insights($igMid, $token);
        $likes = (int) ($node['like_count'] ?? 0);
        if (isset($insights['likes'])) {
            $likes = max($likes, (int) $insights['likes']);
        }
        $comments = (int) ($node['comments_count'] ?? 0);
        if (isset($insights['comments'])) {
            $comments = max($comments, (int) $insights['comments']);
        }
        $impressions = null;
        if (isset($insights['impressions'])) {
            $impressions = (int) $insights['impressions'];
        } elseif (isset($insights['views'])) {
            $impressions = (int) $insights['views'];
        } elseif (isset($insights['reach'])) {
            $impressions = (int) $insights['reach'];
        }
        $views = isset($insights['views']) ? (int) $insights['views'] : null;
        $shares = isset($insights['shares']) ? (int) $insights['shares'] : null;
        $patchMetrics = $metrics;
        $patchMetrics['likes'] = $likes;
        $patchMetrics['comments'] = $comments;
        $patchMetrics['fetched_at'] = date('c');
        if ($impressions !== null) {
            $patchMetrics['impressions'] = $impressions;
        }
        if ($views !== null) {
            $patchMetrics['views'] = $views;
        }
        if ($shares !== null) {
            $patchMetrics['shares'] = $shares;
        }
        $up = pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($cid), ['metrics' => $patchMetrics], $authHeader);
        if ($up['code'] >= 200 && $up['code'] < 300) {
            $out['updated']++;
            $out['detail'][] = [
                'instagram_media_id' => $igMid,
                'output_media_id' => $cid,
                'patched' => ['likes', 'comments', 'fetched_at', 'impressions', 'views', 'shares'],
            ];
        } else {
            $out['failed']++;
            $out['detail'][] = ['instagram_media_id' => $igMid, 'error' => $up['body']['message'] ?? 'patch_failed', 'code' => $up['code']];
        }
        usleep(200000);
    }
    return $out;
}

function garage_encode_object_key(string $key): string {
    $key = trim(str_replace('\\', '/', $key), '/');
    if ($key === '') {
        return '';
    }
    return implode('/', array_map('rawurlencode', explode('/', $key)));
}

/**
 * URL for clients (browser, Meta video_url). Uses GARAGE_PUBLIC_URL when set; otherwise path-style on GARAGE_ENDPOINT.
 * Virtual-hosted Garage: set GARAGE_PUBLIC_URL to http(s)://{GARAGE_BUCKET}.web.{node-host}/ (host must start with "{bucket}.web.").
 */
function garage_public_url_for_key(string $key): string {
    $cfg = $GLOBALS['CONFIG'];
    $keyPart = garage_encode_object_key($key);
    $ep = rtrim((string) $cfg['garage_endpoint'], '/');
    $bucket = (string) $cfg['garage_bucket'];
    $public = trim((string) ($cfg['garage_public_url'] ?? ''));
    if ($public === '') {
        return $keyPart === '' ? "{$ep}/{$bucket}" : "{$ep}/{$bucket}/{$keyPart}";
    }
    $public = rtrim($public, '/');
    $host = strtolower((string) (parse_url($public, PHP_URL_HOST) ?? ''));
    $vhPrefix = strtolower($bucket) . '.web.';
    if ($host !== '' && str_starts_with($host, $vhPrefix)) {
        return $keyPart === '' ? $public : "{$public}/{$keyPart}";
    }
    return $keyPart === '' ? "{$public}/{$bucket}" : "{$public}/{$bucket}/{$keyPart}";
}

/**
 * When the app is served over HTTPS, rewrite http:// media URLs to https:// so browsers load images (mixed content).
 * Set GARAGE_PUBLIC_SCHEME=http to keep http:// explicitly (e.g. dev-only HTTP Garage).
 */
function ff_upgrade_http_media_url_if_app_https(string $url): string {
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'http://')) {
        return $url;
    }
    $app = getenv('APP_URL');
    if (!is_string($app) || !str_starts_with(trim($app), 'https://')) {
        return $url;
    }
    $forceHttp = strtolower(trim((string) (getenv('GARAGE_PUBLIC_SCHEME') ?: '')));
    if ($forceHttp === 'http') {
        return $url;
    }
    return 'https://' . substr($url, 7);
}

/** True when garage_url is http(s) with host 127.0.0.1, localhost, or ::1 (bad for browsers / Meta). */
function ff_garage_url_uses_loopback_host(string $url): bool {
    $url = trim($url);
    if ($url === '' || !preg_match('~^https?://~i', $url)) {
        return false;
    }
    $h = strtolower((string) (parse_url($url, PHP_URL_HOST) ?? ''));
    return $h === '127.0.0.1' || $h === 'localhost' || $h === '[::1]' || $h === '::1';
}

/**
 * Public Garage object URL from garage_key (preferred) or non-loopback garage_url; empty if neither applies.
 */
function ff_content_item_garage_public_media_url(array $item): string {
    $gk = trim((string) ($item['garage_key'] ?? ''));
    if ($gk !== '') {
        return ff_upgrade_http_media_url_if_app_https(garage_public_url_for_key($gk));
    }
    $gu = trim((string) ($item['garage_url'] ?? ''));
    if ($gu !== '' && !ff_garage_url_uses_loopback_host($gu)) {
        return ff_upgrade_http_media_url_if_app_https($gu);
    }

    return '';
}

/**
 * PocketBase /api/files/… URL when media_file is set (may need auth — not ideal for headless agents).
 */
function ff_content_item_pocketbase_files_api_url(array $item): string {
    $fn = trim((string) ($item['media_file'] ?? ''));
    $cid = trim((string) ($item['collectionId'] ?? ''));
    $rid = trim((string) ($item['id'] ?? ''));
    if ($cid === '') {
        $cid = ff_content_items_collection_id();
    }
    if ($fn === '' || $cid === '' || $rid === '') {
        return '';
    }
    $u = ff_pb_public_file_url($cid, $rid, $fn);

    return $u !== '' ? ff_upgrade_http_media_url_if_app_https($u) : '';
}

/**
 * URL for external fetch (Cursor agent, embeddings): prefer public Garage; else same order as ff_content_item_effective_media_url.
 */
function ff_content_item_prefer_garage_public_media_url(array $item): string {
    $g = ff_content_item_garage_public_media_url($item);
    if ($g !== '') {
        return $g;
    }

    return ff_content_item_effective_media_url($item);
}

/**
 * All content_items ids with the given input_media_id (paginated). False on list failure.
 *
 * @return list<string>|false
 */
function ff_pb_content_item_ids_for_source_link(string $authHeader, string $sourceLinkId): array|false {
    $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $sourceLinkId);
    $ids = [];
    $page = 1;
    $perPage = 100;
    while (true) {
        $qs = http_build_query([
            'filter' => 'input_media_id = "' . $esc . '"',
            'perPage' => $perPage,
            'page' => $page,
            'sort' => '-@rowid',
        ]);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            return false;
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string) ($it['id'] ?? '');
            if ($id !== '') {
                $ids[] = $id;
            }
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    return $ids;
}

/**
 * Fetched content_items for a source_link, oldest-first (@rowid) so carousel order matches download order.
 *
 * @return list<array<string,mixed>>
 */
function ff_pb_content_items_for_source_link(string $authHeader, string $sourceLinkId): array {
    $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $sourceLinkId);
    $out = [];
    $page = 1;
    $perPage = 100;
    while (true) {
        $qs = http_build_query([
            'filter' => 'input_media_id = "' . $esc . '"',
            'perPage' => $perPage,
            'page' => $page,
            'sort' => '@rowid',
        ]);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            return [];
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            if (is_array($it) && ff_content_item_is_fetched_for_snapshot($it)) {
                $out[] = $it;
            }
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    return $out;
}

/** Normalize any content type into a shape kind for signature checks. */
function ff_shape_kind_for_content_type(string $type): string {
    $t = strtolower(trim($type));
    if ($t === 'image' || $t === 'carousel') {
        return 'image';
    }
    return 'video';
}

/** Target generated `content_items.type` for a source slot type. */
function ff_target_generated_type_for_source_type(string $sourceType): string {
    $t = strtolower(trim($sourceType));
    if ($t === 'image' || $t === 'carousel') {
        return 'image';
    }
    if ($t === 'video') {
        return 'video';
    }
    return 'reel';
}

/** Parse slot signature like "video,image,video" into normalized kinds. */
function ff_parse_slot_signature(string $raw): array {
    $parts = preg_split('/[\s,|>\/\\\\;-]+/', strtolower(trim($raw))) ?: [];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p === '') {
            continue;
        }
        $out[] = ($p === 'image' || $p === 'img' || $p === 'carousel') ? 'image' : 'video';
    }
    return $out;
}

function ff_slot_signature_to_string(array $sig): string {
    return implode(',', array_values(array_map(fn($v) => ff_shape_kind_for_content_type((string)$v), $sig)));
}

/** Normalize shape signature from mixed metadata forms (array or comma-delimited string). */
function ff_shape_signature_normalize($raw): array {
    if (is_array($raw)) {
        return array_values(array_map(
            fn($v) => ff_shape_kind_for_content_type((string)$v),
            array_filter($raw, fn($v) => trim((string)$v) !== '')
        ));
    }
    return ff_parse_slot_signature((string)$raw);
}

/** Suggest a different shape signature after rejection (must not equal current signature). */
function ff_suggest_changed_shape_signature(array $sig): array {
    $sig = ff_shape_signature_normalize($sig);
    if ($sig === []) {
        return ['video'];
    }
    if (count($sig) === 1) {
        return [$sig[0] === 'image' ? 'video' : 'image'];
    }
    $out = array_reverse($sig);
    if (ff_slot_signature_to_string($out) === ff_slot_signature_to_string($sig)) {
        $out[0] = $out[0] === 'image' ? 'video' : 'image';
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function ff_pipeline_formats_array_from_pipeline_row(array $pipelineRow): array {
    $f = $pipelineRow['formats'] ?? null;
    if (!is_array($f)) {
        return [];
    }
    $out = [];
    foreach ($f as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function ff_pipeline_ingredients_list(string $authHeader, string $pipelineId, bool $activeOnly = true): array {
    $pid = trim($pipelineId);
    if ($pid === '') {
        return [];
    }
    $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $pid);
    $filter = 'role = "pipeline_slot" && pipeline_id = "' . $esc . '"';
    if ($activeOnly) {
        $filter .= ' && (is_active = true || is_active = null)';
    }
    $qs = http_build_query([
        'filter' => $filter,
        'perPage' => 300,
        'sort' => 'slot_index,@rowid',
    ]);
    $r = pb_request('GET', '/api/collections/input_media/records?' . $qs, null, $authHeader);
    if (($r['code'] ?? 0) !== 200) {
        return [];
    }
    $items = $r['body']['items'] ?? [];
    return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
}

/** @return array{record:?array,signature:list<string>} */
function ff_pipeline_default_format(string $authHeader, string $pipelineId): array {
    $pid = trim($pipelineId);
    if ($pid === '') {
        return ['record' => null, 'signature' => []];
    }
    $r = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pid), null, $authHeader);
    if (($r['code'] ?? 0) !== 200 || !is_array($r['body'] ?? null)) {
        return ['record' => null, 'signature' => []];
    }
    $formats = ff_pipeline_formats_array_from_pipeline_row($r['body']);
    $row = null;
    foreach ($formats as $f) {
        if (!empty($f['is_default'])) {
            $row = $f;
            break;
        }
    }
    if ($row === null && $formats !== []) {
        $row = $formats[0];
    }
    if ($row === null) {
        return ['record' => null, 'signature' => []];
    }
    $sig = ff_parse_slot_signature((string)($row['slot_signature'] ?? ''));
    return ['record' => $row, 'signature' => $sig];
}

/** Upsert default pipeline format signature (stored in pipelines.formats JSON). */
function ff_pipeline_set_default_format_signature(string $authHeader, string $pipelineId, array $signature, string $name = 'Default format'): array {
    $pid = trim($pipelineId);
    $sig = ff_shape_signature_normalize($signature);
    if ($pid === '' || $sig === []) {
        return ['ok' => false, 'error' => 'invalid_pipeline_or_signature'];
    }
    $slotSignature = ff_slot_signature_to_string($sig);
    $gr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pid), null, $authHeader);
    if (($gr['code'] ?? 0) !== 200 || !is_array($gr['body'] ?? null)) {
        return ['ok' => false, 'error' => 'pipeline_not_found'];
    }
    $formats = ff_pipeline_formats_array_from_pipeline_row($gr['body']);
    $newRow = [
        'pipeline_id' => $pid,
        'name' => trim($name) !== '' ? trim($name) : 'Default format',
        'slot_signature' => $slotSignature,
        'is_default' => true,
        'is_active' => true,
    ];
    $out = [];
    $replaced = false;
    foreach ($formats as $f) {
        if (!is_array($f)) {
            continue;
        }
        if (!empty($f['is_default'])) {
            $out[] = array_merge($f, $newRow);
            $replaced = true;
        } else {
            $row = $f;
            $row['is_default'] = false;
            $out[] = $row;
        }
    }
    if (!$replaced) {
        $out[] = $newRow;
    }
    $sv = pb_request('PATCH', '/api/collections/pipelines/records/' . rawurlencode($pid), ['formats' => $out], $authHeader);
    return ['ok' => (($sv['code'] ?? 0) >= 200 && ($sv['code'] ?? 0) < 300), 'record' => $sv['body'] ?? null];
}

/** Remove rejected slot from signature when possible; fallback to generic changed signature. */
function ff_suggest_changed_shape_after_reject(array $sig, int $rejectedIndexOneBased = 0): array {
    $sig = ff_shape_signature_normalize($sig);
    if ($sig === []) {
        return ['video'];
    }
    if (count($sig) <= 1) {
        return ff_suggest_changed_shape_signature($sig);
    }
    $idx = $rejectedIndexOneBased - 1;
    if ($idx >= 0 && $idx < count($sig)) {
        $out = $sig;
        array_splice($out, $idx, 1);
        if ($out !== []) {
            return array_values($out);
        }
    }
    return array_values(array_slice($sig, 0, max(1, count($sig) - 1)));
}

/**
 * Text appended to pipeline prompts so T2V aligns with Curate-fetched rows (no pixels — titles/URLs/order only).
 */
function ff_format_source_link_backing_prompt(string $authHeader, string $sourceLinkId): string {
    $sourceLinkId = trim($sourceLinkId);
    if ($sourceLinkId === '') {
        return '';
    }
    $lr = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($sourceLinkId), null, $authHeader);
    if ($lr['code'] !== 200) {
        return '';
    }
    $link = $lr['body'];
    $url = trim((string) ($link['url'] ?? ''));
    $title = trim((string) ($link['title'] ?? ''));
    $items = ff_pb_content_items_for_source_link($authHeader, $sourceLinkId);
    $lines = [];
    $lines[] = '**Paired source link (ground truth — match subject, energy, and structure to this fetch; text-to-video does not see pixels):**';
    if ($url !== '') {
        $lines[] = '- URL: ' . $url;
    }
    if ($title !== '') {
        $lines[] = '- Link title: ' . $title;
    }
    if ($items === []) {
        $lines[] = '- (No fetched media rows yet for this link — run Fetch on Curate or choose another link.)';
        return implode("\n", $lines);
    }
    $lines[] = '';
    $lines[] = '**Fetched media for this link (' . count($items) . ' item(s), order preserved). For a carousel, treat each row as one slide in sequence (one generated clip per row unless your workflow merges). For a single reel/video row, one clip aligned to that motion:**';
    $n = 0;
    foreach ($items as $it) {
        $n++;
        $type = trim((string) ($it['type'] ?? ''));
        $ct = trim((string) ($it['title'] ?? ''));
        $pr = trim((string) ($it['prompt'] ?? ''));
        $prShort = $pr;
        if (strlen($prShort) > 320) {
            $prShort = substr($prShort, 0, 317) . '…';
        }
        $line = $n . '. [' . ($type !== '' ? $type : 'media') . '] ';
        $line .= $ct !== '' ? $ct : '(no title)';
        if ($prShort !== '' && $prShort !== $url) {
            $line .= ' — context: ' . $prShort;
        }
        $lines[] = $line;
    }
    return implode("\n", $lines);
}

/**
 * Platform-wide: which source_links id to use for fetched backing text (all generation agents).
 * Order: content_items.input_media_id, then pipelines.metadata.backing_input_media_id | default_input_media_id | input_media_id.
 */
function ff_resolve_backing_input_media_id(array $contentRow, string $authHeader): string {
    $slid = trim((string) ($contentRow['input_media_id'] ?? ''));
    if ($slid !== '') {
        return $slid;
    }
    $meta = is_array($contentRow['metadata'] ?? null) ? $contentRow['metadata'] : [];
    $pid = trim((string) ($meta['pipeline_id'] ?? ''));
    if ($pid === '') {
        return '';
    }
    $pr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pid), null, $authHeader);
    if ($pr['code'] !== 200) {
        return '';
    }
    $pm = is_array($pr['body']['metadata'] ?? null) ? $pr['body']['metadata'] : [];
    foreach (['backing_input_media_id', 'default_input_media_id', 'input_media_id'] as $k) {
        if (!empty($pm[$k]) && is_string($pm[$k])) {
            $t = trim($pm[$k]);
            if ($t !== '') {
                return $t;
            }
        }
    }
    return '';
}

/**
 * Append backing block once (idempotent — skips if marker already present).
 */
function ff_merge_prompt_with_source_backing(string $authHeader, string $prompt, string $sourceLinkId): string {
    $sourceLinkId = trim($sourceLinkId);
    if ($sourceLinkId === '') {
        return $prompt;
    }
    if (strpos($prompt, '**Paired source link (ground truth') !== false) {
        return $prompt;
    }
    $backing = ff_format_source_link_backing_prompt($authHeader, $sourceLinkId);
    if ($backing === '') {
        return $prompt;
    }
    return rtrim($prompt) . "\n\n" . $backing;
}

/**
 * Resolve the most relevant fetched source media URL for an image slot run.
 * Preference: explicit source_slide_item_id, then source_shape_index within backing source rows.
 */
function ff_resolve_source_slot_media_url(array $contentRow, string $authHeader, string $backingSourceLinkId): string {
    $meta = is_array($contentRow['metadata'] ?? null) ? $contentRow['metadata'] : [];
    $srcItemId = trim((string)($meta['source_slide_item_id'] ?? ''));
    if ($srcItemId !== '') {
        $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($srcItemId), null, $authHeader);
        if ($gr['code'] === 200 && is_array($gr['body'] ?? null)) {
            $u = ff_content_item_prefer_garage_public_media_url($gr['body']);
            if ($u !== '') {
                return $u;
            }
        }
    }
    if ($backingSourceLinkId === '') {
        return '';
    }
    $rows = ff_pb_content_items_for_source_link($authHeader, $backingSourceLinkId);
    if ($rows === []) {
        return '';
    }
    $pick = 0;
    $shapeIdx = (int)($meta['source_shape_index'] ?? 0);
    if ($shapeIdx > 0 && $shapeIdx <= count($rows)) {
        $pick = $shapeIdx - 1;
    }
    $row = is_array($rows[$pick] ?? null) ? $rows[$pick] : [];
    if ($row === []) {
        return '';
    }
    return ff_content_item_prefer_garage_public_media_url($row);
}

/** Parse KEY=VALUE lines from a .env file (simple, shell-like). */
function ff_parse_env_file_simple(string $path): array {
    if (!is_file($path) || !is_readable($path)) {
        return [];
    }
    $out = [];
    $lines = @file($path, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            continue;
        }
        $k = $m[1];
        $v = trim((string)$m[2]);
        if ($v !== '' && (($v[0] === '"' && str_ends_with($v, '"')) || ($v[0] === "'" && str_ends_with($v, "'")))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Extract first JSON object from model text (handles ```json fences).
 *
 * @return array<string, mixed>|null
 */
function ff_json_extract_first_object(string $text): ?array {
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    if (preg_match('/^```(?:json)?\s*(\{[\s\S]*\})\s*```/m', $text, $m)) {
        $text = $m[1];
    }
    $d = json_decode($text, true);
    if (is_array($d)) {
        return $d;
    }
    if (preg_match('/\{[\s\S]*\}/s', $text, $m)) {
        $d = json_decode($m[0], true);

        return is_array($d) ? $d : null;
    }

    return null;
}

/**
 * POST OpenRouter chat/completions. Returns decoded JSON on HTTP 2xx; otherwise null.
 *
 * @param array<string,mixed> $payload
 */
function ff_openrouter_chat_completions_execute(array $payload, int $timeoutSec, string $xTitle): ?array {
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $key = trim((string) ($cfg['openrouter_key'] ?? ''));
    if ($key === '') {
        return null;
    }
    $site = trim((string) ($cfg['site_url'] ?? ''));
    if ($site === '') {
        $site = 'https://formatforge.local';
    }
    $timeoutSec = max(30, min(600, $timeoutSec));
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: ' . $site,
            'X-Title: ' . $xTitle,
        ],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_TIMEOUT => $timeoutSec,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($res ?: '{}', true) ?? [];
    if ($code < 200 || $code >= 300) {
        ff_debug_log('openrouter_chat_failed', ['code' => $code, 'body' => substr((string) $res, 0, 500)]);

        return null;
    }

    return is_array($j) ? $j : null;
}

/**
 * OpenRouter Chat Completions (used to build `pipeline_input.json` for Go pipelines).
 */
function ff_openrouter_chat_completion(array $messages, string $model): ?string {
    $temp = (float) (getenv('PIPELINE_INPUT_LLM_TEMPERATURE') ?: '0.88');
    $maxTok = (int) (getenv('PIPELINE_INPUT_LLM_MAX_TOKENS') ?: '1400');
    $maxTok = min(4096, max(256, $maxTok));
    $j = ff_openrouter_chat_completions_execute([
        'model' => $model,
        'messages' => $messages,
        'temperature' => $temp,
        'max_tokens' => $maxTok,
    ], 120, 'FormatForge pipeline_input');
    if (!is_array($j)) {
        return null;
    }
    $txt = $j['choices'][0]['message']['content'] ?? null;

    return is_string($txt) ? $txt : null;
}

function ff_pipeline_agent_essence_enabled(): bool {
    if (strtolower(trim((string) (getenv('PIPELINE_AGENT_ESSENCE_ENABLED') ?: '1'))) !== '1') {
        return false;
    }
    $key = trim((string) (($GLOBALS['CONFIG']['openrouter_key'] ?? '') ?: ''));

    return $key !== '';
}

function ff_pipeline_agent_essence_model(): string {
    $m = trim((string) (getenv('PIPELINE_AGENT_ESSENCE_MODEL') ?: ''));

    return $m !== '' ? $m : 'google/gemini-3-flash-preview';
}

function ff_pipeline_agent_essence_video_max_bytes(): int {
    $raw = trim((string) (getenv('PIPELINE_AGENT_ESSENCE_VIDEO_MAX_BYTES') ?: ''));
    if ($raw !== '' && ctype_digit($raw)) {
        return max(1048576, min(52428800, (int) $raw));
    }

    return 20971520;
}

function ff_pipeline_agent_essence_image_max_bytes(): int {
    $raw = trim((string) (getenv('PIPELINE_AGENT_ESSENCE_IMAGE_MAX_BYTES') ?: ''));
    if ($raw !== '' && ctype_digit($raw)) {
        return max(262144, min(52428800, (int) $raw));
    }

    return 12582912;
}

function ff_pipeline_agent_essence_max_media_parts(): int {
    $raw = trim((string) (getenv('PIPELINE_AGENT_ESSENCE_MAX_MEDIA_PARTS') ?: ''));
    if ($raw !== '' && ctype_digit($raw)) {
        return max(1, min(24, (int) $raw));
    }

    return 10;
}

function ff_pipeline_agent_essence_timeout_sec(): int {
    $raw = trim((string) (getenv('PIPELINE_AGENT_ESSENCE_TIMEOUT_SEC') ?: ''));
    if ($raw !== '' && ctype_digit($raw)) {
        return max(60, min(600, (int) $raw));
    }

    return 240;
}

function ff_pipeline_agent_essence_max_tokens(): int {
    $raw = trim((string) (getenv('PIPELINE_AGENT_ESSENCE_MAX_TOKENS') ?: ''));
    if ($raw !== '' && ctype_digit($raw)) {
        return max(512, min(8192, (int) $raw));
    }

    return 4096;
}

function ff_pipeline_row_media_kind_for_essence(array $row): string {
    $t = strtolower(trim((string) ($row['type'] ?? '')));
    if ($t === 'video' || $t === 'reel') {
        return 'video';
    }
    $u = ff_content_item_prefer_garage_public_media_url($row);
    if ($u === '') {
        $u = ff_content_item_effective_media_url($row);
    }
    $path = (string) (parse_url($u, PHP_URL_PATH) ?? '');
    if (preg_match('/\.(mp4|webm|mov|mpe?g)(\?|$)/i', $path)) {
        return 'video';
    }

    return 'image';
}

/**
 * Map file extension to a video MIME type OpenRouter documents for Gemini (mp4/mpeg/mov/webm).
 */
function ff_pipeline_agent_video_mime_for_openrouter(string $ext): ?string {
    $e = strtolower(trim($ext));
    return match ($e) {
        'mp4', 'm4v' => 'video/mp4',
        'webm' => 'video/webm',
        'mov' => 'video/quicktime',
        'mpeg', 'mpg' => 'video/mpeg',
        default => null,
    };
}

function ff_pipeline_agent_guess_image_mime_from_bytes(string $bytes): string {
    if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
        return 'image/jpeg';
    }
    if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
        return 'image/png';
    }
    if (str_starts_with($bytes, 'GIF8')) {
        return 'image/gif';
    }
    if (strlen($bytes) > 12 && str_starts_with($bytes, 'RIFF') && str_contains(substr($bytes, 0, 16), 'WEBP')) {
        return 'image/webp';
    }

    return 'image/jpeg';
}

/**
 * Build user message `content` parts for OpenRouter (text + image_url / video_url).
 *
 * @param list<array<string,mixed>> $fetchedRows
 *
 * @return array{0: list<array<string,mixed>>, 1: list<string>}
 */
function ff_pipeline_build_essence_openrouter_user_content(string $sourceUrl, array $fetchedRows, ?string $pbToken): array {
    $notes = [];
    $lines = [
        'Source page URL (context only): ' . ($sourceUrl !== '' ? $sourceUrl : '(unknown)'),
        '',
        'Below are the fetched reference slot(s) in carousel order. Attached after this text are the corresponding image(s) and/or video(s) where we could load them.',
        '',
        '| Slot | type | title | prompt/source |',
        '| --- | --- | --- | --- |',
    ];
    foreach ($fetchedRows as $i => $row) {
        if (!is_array($row)) {
            continue;
        }
        $slot = $i + 1;
        $type = trim((string) ($row['type'] ?? ''));
        $title = trim((string) ($row['title'] ?? ''));
        $pr = trim((string) ($row['prompt'] ?? ''));
        $lines[] = '| ' . $slot . ' | ' . ff_pipeline_agent_escape_md_cell($type) . ' | ' . ff_pipeline_agent_escape_md_cell($title) . ' | ' . ff_pipeline_agent_escape_md_cell($pr) . ' |';
    }
    $lines[] = '';
    $lines[] = 'Apply your **Essence Extractor** role and system instructions. The reference image(s) and/or video(s) for these slots are attached after this text. Output **exactly** the four required sections (no extra sections).';
    $userText = implode("\n", $lines);
    $parts = [['type' => 'text', 'text' => $userText]];
    $maxMedia = ff_pipeline_agent_essence_max_media_parts();
    $vMax = ff_pipeline_agent_essence_video_max_bytes();
    $iMax = ff_pipeline_agent_essence_image_max_bytes();
    $timeout = 90;
    $attached = 0;
    foreach ($fetchedRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if ($attached >= $maxMedia) {
            $notes[] = 'trimmed: max media parts (' . $maxMedia . ')';
            break;
        }
        $kind = ff_pipeline_row_media_kind_for_essence($row);
        $url = ff_content_item_prefer_garage_public_media_url($row);
        if ($url === '') {
            $url = ff_content_item_effective_media_url($row);
        }
        $url = ff_upgrade_http_media_url_if_app_https($url);
        if ($url === '') {
            $notes[] = 'skip slot: no media URL';

            continue;
        }
        $candidates = array_values(array_unique(array_filter([$url, ff_content_item_effective_media_url($row)])));
        if ($kind === 'video') {
            if (str_starts_with($url, 'https://')) {
                $parts[] = ['type' => 'video_url', 'video_url' => ['url' => $url]];
                $attached++;

                continue;
            }
            $bytes = ff_pipeline_agent_try_fetch_media_bytes($candidates, $pbToken, $vMax, $timeout);
            if ($bytes === null || $bytes === '') {
                $notes[] = 'skip video: download failed or exceeds PIPELINE_AGENT_ESSENCE_VIDEO_MAX_BYTES';

                continue;
            }
            $ext = ff_pipeline_agent_guess_media_ext($url, $bytes);
            $mime = ff_pipeline_agent_video_mime_for_openrouter($ext);
            if ($mime === null) {
                $notes[] = 'skip video: unsupported extension .' . $ext;

                continue;
            }
            $dataUri = 'data:' . $mime . ';base64,' . base64_encode($bytes);
            $parts[] = ['type' => 'video_url', 'video_url' => ['url' => $dataUri]];
            $attached++;
        } else {
            if (str_starts_with($url, 'https://')) {
                $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
                $attached++;

                continue;
            }
            $bytes = ff_pipeline_agent_try_fetch_media_bytes($candidates, $pbToken, $iMax, $timeout);
            if ($bytes === null || $bytes === '') {
                $notes[] = 'skip image: download failed';

                continue;
            }
            $mime = ff_pipeline_agent_guess_image_mime_from_bytes($bytes);
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes)]];
            $attached++;
        }
    }

    return [$parts, $notes];
}

/**
 * Multimodal OpenRouter pass (Gemini 3 Flash Preview by default) before the pipeline Cursor agent runs.
 *
 * @param list<array<string,mixed>> $fetchedRows
 *
 * @return array<string, mixed>|null
 */
function ff_pipeline_backing_essence_openrouter(string $sourceLinkId, string $sourceUrl, array $fetchedRows, string $authHeader): ?array {
    if (!ff_pipeline_agent_essence_enabled()) {
        return null;
    }
    $sourceLinkId = trim($sourceLinkId);
    if ($sourceLinkId === '' || $fetchedRows === []) {
        return null;
    }
    $su = pb_superuser_auth_token();
    $pbTok = ($su['ok'] && !empty($su['token'])) ? (string) $su['token'] : null;
    [$userParts, $notes] = ff_pipeline_build_essence_openrouter_user_content($sourceUrl, $fetchedRows, $pbTok);
    $model = ff_pipeline_agent_essence_model();
    $sys = 'Role: You are an Essence Extractor. Your job is to analyze a piece of reference content and strip away its literal subject matter to reveal its underlying conceptual, structural, and emotional DNA. '
        . 'Task: Do not describe what the content is about. Describe how and why it works. Extract the blueprint so a generative system can recreate its impact using an entirely new subject. '
        . 'Output exactly these four sections (use these headings verbatim, Markdown): '
        . '## The Core Dynamic (The "Why") '
        . 'What is the fundamental psychological or educational mechanism at play? (e.g., "Contrasting two opposing methodologies to show evolution," "Breaking down a complex system into digestible, sequential steps.") '
        . '## The Structural Metaphor (The "How") '
        . 'How is the information visually and conceptually organized? (e.g., "A branching tree dichotomy," "A centralized hub with radiating spokes," "A descending funnel.") '
        . '## The Aesthetic & Tone (The "Vibe") '
        . 'What is the mood, pacing, and visual energy? (e.g., "High-contrast, clinical, and authoritative," "Playful, bright, and fast-paced.") '
        . '## The Transmutation Prompt '
        . 'Write a prompt for a downstream generative model (e.g. Flux or an LLM) instructing it to use this exact Core Dynamic, Structural Metaphor, and Tone to create a completely new piece of content about [Insert New Topic Here]. '
        . 'Explicitly forbid the use of any literal text, specific imagery, or exact layouts from the original reference. '
        . 'Do not add a fifth section or preamble; start with the first heading.';
    $messages = [
        ['role' => 'system', 'content' => $sys],
        ['role' => 'user', 'content' => $userParts],
    ];
    $j = ff_openrouter_chat_completions_execute([
        'model' => $model,
        'messages' => $messages,
        'temperature' => (float) (getenv('PIPELINE_AGENT_ESSENCE_TEMPERATURE') ?: '0.35'),
        'max_tokens' => ff_pipeline_agent_essence_max_tokens(),
    ], ff_pipeline_agent_essence_timeout_sec(), 'FormatForge pipeline_agent_essence');
    if (!is_array($j)) {
        ff_pipeline_trace_log('pipeline_agent_essence_openrouter_failed', [
            'input_media_id' => $sourceLinkId,
            'model' => $model,
        ]);

        return [
            'schema_version' => 1,
            'ok' => false,
            'model' => $model,
            'generated_at' => gmdate('c'),
            'error' => 'openrouter_request_failed',
            'essence_markdown' => '',
            'media_notes' => $notes,
        ];
    }
    $msg = $j['choices'][0]['message'] ?? null;
    $txt = '';
    if (is_array($msg)) {
        $c = $msg['content'] ?? null;
        if (is_string($c)) {
            $txt = $c;
        } elseif (is_array($c)) {
            foreach ($c as $block) {
                if (is_array($block) && ($block['type'] ?? '') === 'text' && isset($block['text'])) {
                    $txt .= (string) $block['text'];
                }
            }
        }
    }
    $txt = trim($txt);
    ff_pipeline_trace_log('pipeline_agent_essence_openrouter_ok', [
        'input_media_id' => $sourceLinkId,
        'model' => $model,
        'chars' => strlen($txt),
        'media_notes' => $notes,
    ]);

    return [
        'schema_version' => 1,
        'ok' => $txt !== '',
        'model' => $model,
        'generated_at' => gmdate('c'),
        'essence_markdown' => $txt,
        'error' => $txt === '' ? 'empty_model_response' : null,
        'media_notes' => $notes,
        'instruction' => 'Use `essence_markdown` (four sections: Core Dynamic, Structural Metaphor, Aesthetic & Tone, Transmutation Prompt) as the primary brief. Substitute a real new topic where the extractor wrote [Insert New Topic Here]. Ground `source_analysis.md` and `prompt_template` in it; still inspect raw media in `pipeline_agent_fetched_slots_catalog_v1` / `agent_media/` when present.',
    ];
}

/**
 * Public image URLs from fetched backing rows for this source link (png/jpg/webp + video thumbnails).
 *
 * @return list<string>
 */
function ff_pipeline_collect_reference_media_urls_for_run(string $sourceId, string $authHeader): array {
    $sourceId = trim($sourceId);
    if ($sourceId === '') {
        return [];
    }
    $rows = ff_pb_content_items_for_source_link($authHeader, $sourceId);
    $urls = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $kind = ff_shape_kind_for_content_type((string) ($row['type'] ?? ''));
        $u = ff_content_item_prefer_garage_public_media_url($row);
        if ($u === '') {
            $u = ff_content_item_effective_media_url($row);
        }
        if ($u === '') {
            continue;
        }
        if ($kind === 'video') {
            $t = trim((string) ($row['thumbnail_url'] ?? ''));
            if ($t !== '') {
                $u = ff_upgrade_http_media_url_if_app_https($t);
            }
        }
        if ($kind === 'image' || preg_match('/\.(png|jpe?g|gif|webp|bmp)(\?|$)/i', $u)) {
            $urls[] = ff_upgrade_http_media_url_if_app_https($u);
        }
    }

    return array_values(array_unique(array_filter($urls)));
}

/**
 * @return list<string>
 */
function ff_pipeline_collect_ingredient_input_urls(string $pipelineId, string $authHeader): array {
    $pipelineId = trim($pipelineId);
    if ($pipelineId === '') {
        return [];
    }
    $list = ff_pipeline_ingredients_list($authHeader, $pipelineId, true);
    $urls = [];
    foreach ($list as $ing) {
        if (!is_array($ing)) {
            continue;
        }
        $u = trim((string) ($ing['input_url'] ?? ''));
        if ($u !== '') {
            $urls[] = ff_upgrade_http_media_url_if_app_https($u);
        }
    }

    return array_values(array_unique(array_filter($urls)));
}

/**
 * Build payload for `pipeline_input.json` (LLM + reference URLs).
 *
 * @param list<string> $urls
 *
 * @return array<string, mixed>
 */
function ff_pipeline_build_pipeline_input_payload(array $urls, string $runPrompt, array $pipelineRecord): array {
    $base = [
        'generated_at' => gmdate('c'),
        'pipeline_id' => (string) ($pipelineRecord['id'] ?? ''),
        'pipeline_name' => (string) ($pipelineRecord['name'] ?? ''),
        'run_prompt' => $runPrompt,
        'reference_image_urls' => array_values($urls),
    ];
    $cfg = $GLOBALS['CONFIG'] ?? [];
    if (empty($cfg['openrouter_key'])) {
        $base['llm'] = ['ok' => false, 'reason' => 'OPENROUTER_API_KEY not set'];
        $base['variation'] = [
            'seed' => bin2hex(random_bytes(8)),
            'hints' => ['Set OPENROUTER_API_KEY for LLM-generated variation blocks.'],
        ];

        return $base;
    }
    $model = trim((string) (getenv('PIPELINE_INPUT_LLM_MODEL') ?: 'openai/gpt-4o-mini'));
    $sys = 'You are a creative director for short-form social pipelines. Reply with ONE JSON object only (no markdown fences). '
        . 'Include keys: variation_axes (object: style, mood, layout, color_direction, typography), '
        . 'per_image_cues (string array, same length as the number of reference images provided, or empty if none), '
        . 'combined_scene_prompt (string), negative_hints (string array), palette_suggestions (string array). '
        . 'Maximize variety between runs; avoid repeating stock phrases.';
    $userText = 'Pipeline: ' . ($base['pipeline_name'] !== '' ? $base['pipeline_name'] : 'unnamed') . "\n\nRun prompt:\n" . $runPrompt
        . "\n\nProduce diverse remix directions; treat references as mood boards, not assets to copy.";
    $messages = [];
    if ($urls !== []) {
        $content = [['type' => 'text', 'text' => $userText]];
        foreach (array_slice($urls, 0, 8) as $u) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $u]];
        }
        $messages = [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $content],
        ];
    } else {
        $messages = [
            ['role' => 'system', 'content' => $sys],
            ['role' => 'user', 'content' => $userText . "\n\n(No reference images — still output full JSON.)"],
        ];
    }
    $raw = ff_openrouter_chat_completion($messages, $model);
    $parsed = null;
    if (is_string($raw) && $raw !== '') {
        $parsed = ff_json_extract_first_object($raw);
    }
    if (!is_array($parsed)) {
        $base['llm'] = ['ok' => false, 'reason' => 'parse_or_empty_response', 'model' => $model, 'raw_excerpt' => is_string($raw) ? substr($raw, 0, 420) : null];
        $base['variation'] = [
            'seed' => bin2hex(random_bytes(8)),
            'hints' => ['OpenRouter returned non-JSON — check PIPELINE_INPUT_LLM_MODEL and logs.'],
        ];

        return $base;
    }
    $base['llm'] = ['ok' => true, 'model' => $model];
    $base['variation'] = $parsed;

    return $base;
}

/**
 * Write `pipelines/<subdir>/pipeline_input.json` before `pipeline-generate` runs (OpenRouter + backing URLs).
 */
function ff_pipeline_prepare_pipeline_input_json(string $pipelineDir, ?array $pipelineRecord, string $runPrompt, string $sourceId, string $authHeader): void {
    if ($pipelineRecord === null || !is_dir($pipelineDir)) {
        return;
    }
    if (strtolower(trim((string) (getenv('PIPELINE_INPUT_JSON_ENABLED') ?: '1'))) !== '1') {
        return;
    }
    $pid = trim((string) ($pipelineRecord['id'] ?? ''));
    $urls = ff_pipeline_collect_reference_media_urls_for_run($sourceId, $authHeader);
    if ($pid !== '') {
        $urls = array_values(array_unique(array_merge($urls, ff_pipeline_collect_ingredient_input_urls($pid, $authHeader))));
    }
    $payload = ff_pipeline_build_pipeline_input_payload($urls, $runPrompt, $pipelineRecord);
    $path = rtrim($pipelineDir, '/\\') . '/pipeline_input.json';
    @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT), LOCK_EX);
    ff_pipeline_trace_log('pipeline_input_json_written', [
        'path' => $path,
        'reference_count' => count($urls),
        'llm_ok' => (bool) ($payload['llm']['ok'] ?? false),
    ]);
}

/**
 * Strict pipeline path: run pipeline Go binary (no PHP generation fallback).
 * Returns immediately after spawning; pipeline binary is responsible for creating/updating content rows.
 *
 * @param string|null $authHeader When set, writes `pipeline_input.json` in the pipeline dir before spawn.
 */
function ff_spawn_pipeline_binary_run(array $pipelineRecord, string $prompt, string $sourceId, string $accountId, ?string $authHeader = null): array {
    $subdir = ff_pipeline_subdir_from_pipeline_record($pipelineRecord);
    if ($subdir === '') {
        return ['ok' => false, 'error' => 'Pipeline missing metadata.pipeline_subdir.'];
    }
    $pipelineDir = __DIR__ . '/pipelines/' . $subdir;
    if (!is_dir($pipelineDir)) {
        return ['ok' => false, 'error' => "Pipeline directory missing: {$subdir}"];
    }
    $bin = $pipelineDir . '/pipeline-generate';
    if (!is_file($bin) || !is_executable($bin)) {
        return ['ok' => false, 'error' => "Pipeline binary missing or not executable: {$subdir}/pipeline-generate"];
    }
    if (!function_exists('exec')) {
        return ['ok' => false, 'error' => 'exec() unavailable; cannot launch pipeline binary.'];
    }

    $logDir = '/tmp/formatforge';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }
    $log = $logDir . '/pipeline-binary.log';

    $pid = trim((string)($pipelineRecord['id'] ?? ''));
    $outType = strtolower(trim((string)($pipelineRecord['output_type'] ?? '')));
    if ($outType === 'carousel') {
        $outType = 'image';
    } elseif ($outType === '') {
        $outType = 'image';
    }
    $runPrompt = trim($prompt) !== '' ? trim($prompt) : 'Create a new composition that captures the source essence without duplicating the backing media.';
    if ($authHeader !== null && trim($authHeader) !== '') {
        ff_pipeline_prepare_pipeline_input_json($pipelineDir, $pipelineRecord, $runPrompt, $sourceId, $authHeader);
    }
    $runEnv = [
        'FORMATFORGE_RUN_ORIGIN' => 'pipelines_run_modal',
        'FORMATFORGE_RUN_PIPELINE_ID' => $pid,
        'PIPELINE_PB_ID' => $pid,
        'FORMATFORGE_RUN_PROMPT' => $runPrompt,
        'PROMPT' => $runPrompt,
        'FORMATFORGE_RUN_SOURCE_LINK_ID' => $sourceId,
        'FORMATFORGE_RUN_ACCOUNT_ID' => $accountId,
        'OUTPUT_MODE' => $outType,
    ];
    $envFile = ff_parse_env_file_simple($pipelineDir . '/.env');
    $merged = array_merge($envFile, $runEnv);
    $exports = [];
    foreach ($merged as $k => $v) {
        $exports[] = 'export ' . $k . '=' . escapeshellarg((string)$v);
    }
    $inner = 'cd ' . escapeshellarg($pipelineDir) . ' && '
        . implode(' && ', $exports) . ' && '
        . escapeshellarg($bin);
    $cmd = 'nohup /bin/bash -lc ' . escapeshellarg($inner)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    @exec($cmd, $void, $code);
    if ((int)$code !== 0) {
        return ['ok' => false, 'error' => "Failed to launch pipeline binary (exit {$code})."];
    }
    ff_pipeline_trace_log('pipeline_binary_spawned', [
        'pipeline_id' => $pid !== '' ? $pid : null,
        'pipeline_subdir' => $subdir,
        'log' => $log,
    ]);
    return ['ok' => true, 'log' => $log];
}

/**
 * Markdown dropped into each pipelines/<subdir>/ — documents env `index.php` sets when spawning `pipeline-generate`.
 */
function ff_pipeline_index_spawn_contract_markdown(): string {
    return <<<'MD'
# FormatForge: how `index.php` runs this pipeline

## Binary

From the Pipelines UI, **`index.php`** executes:

- **Path:** `./pipeline-generate` (in this directory)
- **Working directory:** this directory (`pipelines/<subdir>/`)
- **Host log:** `/tmp/formatforge/pipeline-binary.log`

## Environment

Lines from **`.env`** in this directory are loaded first. The app then **exports** these (overriding `.env`):

| Variable | Role |
|----------|------|
| `FORMATFORGE_RUN_ORIGIN` | e.g. `pipelines_run_modal` |
| `FORMATFORGE_RUN_PIPELINE_ID` | PocketBase `pipelines` record id |
| `PIPELINE_PB_ID` | Same id (Go reads either this or `FORMATFORGE_RUN_PIPELINE_ID`) |
| `FORMATFORGE_RUN_PROMPT` | Run prompt |
| `PROMPT` | Same as run prompt |
| `FORMATFORGE_RUN_SOURCE_LINK_ID` | `source_links` id |
| `FORMATFORGE_RUN_ACCOUNT_ID` | Instagram account scope |
| `OUTPUT_MODE` | `image`, `video`, or `reel`. PocketBase **`output_type` `carousel`** is passed as **`image`** here. |

## `pipeline_input.json` (written by `index.php` before spawn)

On each **`generate_content`** run, PHP writes **`./pipeline_input.json`** in this directory (if **`PIPELINE_INPUT_JSON_ENABLED=1`**, default). It contains:

- **`reference_image_urls`**: public HTTP(S) URLs from the backing **`source_links`** fetch (images + video thumbnails) and from **`pipeline_ingredients.input_url`** when set.
- **`variation`**: JSON object from **OpenRouter** (`OPENROUTER_API_KEY`, model **`PIPELINE_INPUT_LLM_MODEL`** default `openai/gpt-4o-mini`) — creative axes, per-image cues, combined scene prompt, etc., so each run differs.
- **`llm`**: `{ ok, model?, reason? }` — whether the LLM call succeeded.

**Go:** read **`pipeline_input.json`** (if present) at startup and merge `variation` / `combined_scene_prompt` (inside `variation`) into your compositor instead of static-only prompts.

Worker behavior: poll PocketBase **`content_items`** with `status=generating` and `metadata.pipeline_id` matching — see **`main.go`**.
MD;
}

function ff_write_pipeline_index_spawn_contract(string $pipelineDir): void {
    $path = rtrim($pipelineDir, '/\\') . '/FORMATFORGE_INDEX_SPAWN.md';
    if (is_file($path)) {
        return;
    }
    @file_put_contents($path, ff_pipeline_index_spawn_contract_markdown());
}

/**
 * Cursor CLI `--workspace`: only `pipelines/<subdir>/` so `index.php` is not visible to the agent.
 */
function ff_cursor_agent_pipeline_workspace_from_prompt(string $promptReal): string {
    $root = __DIR__;
    $base = basename($promptReal, '.md');
    $dir = $root . '/pipelines/' . $base;
    $rp = realpath($dir);
    return ($rp !== false && is_dir($rp)) ? $rp : '';
}

function s3_upload(string $key, string $content, string $contentType = 'video/mp4'): ?string {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['garage_key']) || empty($cfg['garage_secret'])) return null;
    $host = parse_url($cfg['garage_endpoint'], PHP_URL_HOST);
    $path = '/' . $cfg['garage_bucket'] . '/' . $key;
    $url = $cfg['garage_endpoint'] . $path;
    $date = gmdate('Ymd\THis\Z');
    $dateShort = gmdate('Ymd');
    $payloadHash = hash('sha256', $content);
    $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$date}\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "PUT\n{$path}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";
    $credentialScope = "{$dateShort}/{$cfg['garage_region']}/s3/aws4_request";
    $stringToSign = "AWS4-HMAC-SHA256\n{$date}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $dateShort, 'AWS4' . $cfg['garage_secret'], true);
    $kRegion = hash_hmac('sha256', $cfg['garage_region'], $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $auth = "AWS4-HMAC-SHA256 Credential={$cfg['garage_key']}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $content,
        CURLOPT_HTTPHEADER     => [
            "Content-Type: {$contentType}",
            "Host: {$host}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$date}",
            "Authorization: {$auth}",
        ],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? garage_public_url_for_key($key) : null;
}

/** Replicate expects `version` = model version id (hex), not `owner/model:hash` (normalize when possible). */
function replicate_normalize_version(string $ref): string {
    $ref = trim($ref);
    if (preg_match('/^([a-f0-9]{32}|[a-f0-9]{64})$/i', $ref)) {
        return $ref;
    }
    if (preg_match('/:([a-f0-9]{32}|[a-f0-9]{64})$/i', $ref, $m)) {
        return $m[1];
    }
    return $ref;
}

/** Video URL from prediction output (string, array of URLs, or object with url/video). */
function replicate_extract_video_url(?array $pred): ?string {
    if (!$pred || !array_key_exists('output', $pred)) {
        return null;
    }
    $out = $pred['output'];
    if (is_string($out) && filter_var($out, FILTER_VALIDATE_URL)) {
        return $out;
    }
    if (is_array($out)) {
        $first = $out[0] ?? null;
        if (is_string($first) && filter_var($first, FILTER_VALIDATE_URL)) {
            return $first;
        }
        foreach (['url', 'video', 'video_url'] as $k) {
            if (!empty($out[$k]) && is_string($out[$k]) && filter_var($out[$k], FILTER_VALIDATE_URL)) {
                return $out[$k];
            }
        }
        if (!empty($out['video']) && is_array($out['video']) && !empty($out['video']['url']) && is_string($out['video']['url'])) {
            return $out['video']['url'];
        }
    }
    return null;
}

function replicate_run(string $model, array $input, int $waitSec = 60): ?array {
    $token = $GLOBALS['CONFIG']['replicate_token'];
    if (!$token) {
        return null;
    }
    $version = replicate_normalize_version($model);
    $waitPref = min(60, max(1, $waitSec));
    // Match pipelines/template/main.go: ~90 × 2s polling; video often exceeds 60s.
    $pollMax = min(200, max(90, (int)ceil($waitSec / 2)));

    $ch = curl_init('https://api.replicate.com/v1/predictions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => $waitPref + 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Prefer: wait=' . $waitPref,
        ],
        CURLOPT_POSTFIELDS => json_encode(['version' => $version, 'input' => $input]),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    if ($code < 200 || $code >= 300) {
        ff_debug_log('replicate_create_http', ['code' => $code, 'detail' => $body['detail'] ?? $body['message'] ?? substr((string) $res, 0, 400)]);
        return null;
    }
    if (($body['status'] ?? '') === 'succeeded') {
        return $body;
    }
    if (in_array($body['status'] ?? '', ['failed', 'canceled'], true)) {
        ff_debug_log('replicate_prediction_terminal', ['phase' => 'create', 'status' => $body['status'] ?? '', 'error' => $body['error'] ?? null]);
        return null;
    }
    $id = $body['id'] ?? null;
    if (!$id || !in_array($body['status'] ?? '', ['starting', 'processing', 'queued'], true)) {
        ff_debug_log('replicate_unexpected_create', ['status' => $body['status'] ?? '', 'has_id' => $id !== null]);
        return null;
    }
    for ($i = 0; $i < $pollMax; $i++) {
        sleep(2);
        $get = curl_init("https://api.replicate.com/v1/predictions/{$id}");
        curl_setopt_array($get, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);
        $r2 = curl_exec($get);
        curl_close($get);
        $b2 = json_decode($r2 ?: '{}', true) ?? [];
        if (($b2['status'] ?? '') === 'succeeded') {
            return $b2;
        }
        if (in_array($b2['status'] ?? '', ['failed', 'canceled'], true)) {
            ff_debug_log('replicate_prediction_terminal', ['phase' => 'poll', 'status' => $b2['status'] ?? '', 'error' => $b2['error'] ?? null]);
            return null;
        }
    }
    ff_debug_log('replicate_prediction_timeout', ['prediction_id' => $id, 'polls' => $pollMax]);
    return null;
}

/**
 * Run a fal.ai model (text-to-video). Uses queue.fal.run.
 * @param string $model e.g. fal-ai/kling-video/v2.5-turbo/pro/text-to-video
 * @param array $input e.g. ['prompt' => '...', 'aspect_ratio' => '9:16']
 * @param int $waitSec max seconds to poll (fal runs synchronously by default)
 * @return array|null response with video.url, or null on failure
 */
function fal_run(string $model, array $input, int $waitSec = 120): ?array {
    $key = $GLOBALS['CONFIG']['fal_key'];
    if (!$key) return null;
    $url = 'https://queue.fal.run/' . ltrim($model, '/');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => min(300, max(60, $waitSec)),
        CURLOPT_HTTPHEADER => [
            'Authorization: Key ' . $key,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode($input),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    if ($code >= 200 && $code < 300 && isset($body['video'])) return $body;
    return null;
}

/**
 * Run video generation + upload for a content_items row (status must be `generating`).
 * Used by web request (fallback) and CLI worker so PHP-FPM request_terminate_timeout does not kill long jobs.
 */
function formatforge_generate_content_finish(string $itemId, string $authHeader): void {
    $rec = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($itemId), null, $authHeader);
    if ($rec['code'] !== 200) {
        ff_pipeline_trace_log('generate_content_worker_skip', ['reason' => 'not_found', 'content_item_id' => $itemId]);
        return;
    }
    $row = $rec['body'];
    if (trim((string)($row['status'] ?? '')) !== 'generating') {
        ff_pipeline_trace_log('generate_content_worker_skip', ['reason' => 'not_generating', 'content_item_id' => $itemId, 'status' => $row['status'] ?? '']);
        return;
    }
    $prompt = trim((string)($row['prompt'] ?? ''));
    $backingSlid = ff_resolve_backing_input_media_id($row, $authHeader);
    $merged = ff_merge_prompt_with_source_backing($authHeader, $prompt, $backingSlid);
    if ($merged !== $prompt) {
        pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), ['prompt' => $merged], $authHeader);
        ff_pipeline_trace_log('generate_content_backing_merged', [
            'content_item_id' => $itemId,
            'input_media_id' => $backingSlid !== '' ? $backingSlid : null,
        ]);
        $prompt = $merged;
    }
    if ($prompt === '') {
        pb_request('PATCH', "/api/collections/output_media/records/{$itemId}", ['status' => 'failed'], $authHeader);
        return;
    }
    $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    $pipelineId = trim((string)($meta['pipeline_id'] ?? ''));

    // Hard stop: pipeline rows must be produced by pipeline binaries, not PHP fallback generation.
    if ($pipelineId !== '') {
        pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode((string)$itemId), [
            'status' => 'failed',
            'rejected_reason' => '[system] PHP fallback generation disabled for pipeline runs. Run pipeline-generate binary.',
        ], $authHeader);
        ff_pipeline_trace_log('generate_content_failed', [
            'content_item_id' => $itemId,
            'pipeline_id' => $pipelineId,
            'provider' => 'php_fallback_disabled',
            'error' => 'Pipeline runs must execute pipeline-generate.',
        ]);
        ff_trigger_pipeline_agent_after_generation_failure(
            null,
            'php_pipeline_blocked',
            'PHP fallback generation is disabled for pipeline runs; use the Go pipeline-generate binary or fix spawn.',
            [$itemId],
            $authHeader,
            $pipelineId
        );
        return;
    }

    $pipelineRow = null;
    if ($pipelineId !== '') {
        $pRes = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $authHeader);
        if ($pRes['code'] === 200 && is_array($pRes['body'] ?? null)) {
            $pipelineRow = $pRes['body'];
        }
    }

    $cfg = $GLOBALS['CONFIG'];
    $provider = $cfg['video_provider'] ?: ($cfg['replicate_token'] ? 'replicate' : ($cfg['fal_key'] ? 'fal' : 'replicate'));
    $videoUrl = null;
    $errMsg = 'Video generation failed';
    $targetType = strtolower(trim((string)($row['type'] ?? 'reel')));
    $wantImageOutput = ($targetType === 'image' || $targetType === 'carousel');

    // For source-backed image slots, create a fresh variant from source media (not a duplicate URL reuse).
    if ($wantImageOutput && $backingSlid !== '') {
        $srcMediaUrl = ff_resolve_source_slot_media_url($row, $authHeader, $backingSlid);
        if ($srcMediaUrl !== '') {
            $variantBytes = ff_render_image_variant_from_source_url($srcMediaUrl);
            if ($variantBytes !== null) {
                $key = 'content/' . $itemId . '/' . date('YmdHis') . '.jpg';
                $tmp = tempnam(sys_get_temp_dir(), 'ffgen_');
                if ($tmp !== false) {
                    file_put_contents($tmp, $variantBytes);
                    $patchData = [
                        'status' => 'pending',
                        'garage_key' => $key,
                        'garage_url' => '',
                        'media_file' => ff_curl_file($tmp, 'image/jpeg', 'image.jpg'),
                    ];
                    $mg = pb_request_multipart('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), $patchData, $authHeader);
                    @unlink($tmp);
                    if ($mg['code'] >= 200 && $mg['code'] < 300) {
                        $body = $mg['body'];
                        $collId = trim((string) ($body['collectionId'] ?? ''));
                        $mediaName = trim((string) ($body['media_file'] ?? ''));
                        if ($collId === '' || $mediaName === '') {
                            $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), null, $authHeader);
                            if ($gr['code'] === 200) {
                                $collId = trim((string) ($gr['body']['collectionId'] ?? $collId));
                                $mediaName = trim((string) ($gr['body']['media_file'] ?? $mediaName));
                            }
                        }
                        $pbUrl = ($collId !== '' && $mediaName !== '') ? ff_pb_public_file_url($collId, (string) $itemId, $mediaName) : '';
                        if ($pbUrl !== '') {
                            pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), ['garage_url' => $pbUrl], $authHeader);
                        }
                        $semanticBlob = formatforge_antfly_content_semantic_text('(pipeline)', '', trim($prompt));
                        $antflyType = ff_antfly_type_for_content_row($row, $pipelineRow);
                        formatforge_index_content_in_antfly((string) $itemId, $semanticBlob, $antflyType, 'pending', $pbUrl !== '' ? $pbUrl : null, 'image/jpeg', '', '(pipeline)');
                        ff_verify_shape_bundle_after_item_finish($row, $pipelineRow, $authHeader);
                        ff_pipeline_trace_log('generate_content_ok', [
                            'content_item_id' => $itemId,
                            'pipeline_id' => $pipelineId !== '' ? $pipelineId : null,
                            'provider' => 'source_backing_variant',
                            'shape_kind' => 'image',
                        ]);
                        return;
                    }
                }
            }
        }
    }

    if ($provider === 'fal' && $cfg['fal_key']) {
        $falModel = getenv('FAL_VIDEO_MODEL') ?: 'fal-ai/kling-video/v2.5-turbo/pro/text-to-video';
        $pred = fal_run($falModel, ['prompt' => $prompt, 'aspect_ratio' => '9:16'], 120);
        if ($pred && !empty($pred['video']['url'])) {
            $videoUrl = $pred['video']['url'];
        } else {
            $errMsg = 'fal.ai generation failed';
        }
    }

    if (!$videoUrl && ($provider !== 'fal' || !$cfg['fal_key']) && $cfg['replicate_token']) {
        $model = 'minimax/video-01:5aa835260ff7f40f4069c41185f72036accf99e29957bb4a3b3a911f3b6c1912';
        $pred = replicate_run($model, ['prompt' => $prompt], 300);
        $videoUrl = replicate_extract_video_url($pred);
        if (!$videoUrl) {
            $errMsg = 'Replicate generation failed';
        }
    }

    if (!$videoUrl) {
        pb_request('PATCH', "/api/collections/output_media/records/{$itemId}", ['status' => 'failed'], $authHeader);
        ff_pipeline_trace_log('generate_content_failed', [
            'content_item_id' => $itemId,
            'pipeline_id' => $pipelineId !== '' ? $pipelineId : null,
            'provider' => $provider,
            'error' => $errMsg,
        ]);
        return;
    }
    $videoData = @file_get_contents($videoUrl) ?: '';
    if (!$videoData) {
        $ch = curl_init($videoUrl);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]);
        $videoData = curl_exec($ch);
        curl_close($ch);
    }
    $uploadBytes = $videoData ?: '';
    $uploadMime = 'video/mp4';
    $uploadName = 'video.mp4';
    $uploadExt = 'mp4';
    if ($wantImageOutput) {
        $img = ff_extract_jpeg_frame_from_video_bytes($videoData ?: '');
        if ($img !== null) {
            $uploadBytes = $img;
            $uploadMime = 'image/jpeg';
            $uploadName = 'image.jpg';
            $uploadExt = 'jpg';
        }
    }
    $key = 'content/' . $itemId . '/' . date('YmdHis') . '.' . $uploadExt;
    $garageUrl = s3_upload($key, $uploadBytes, $uploadMime);
    if (!$garageUrl && $videoData) {
        $garageUrl = garage_public_url_for_key($key);
    }
    $fallbackGarage = $garageUrl ?: $videoUrl;
    $gUrl = '';
    $tmp = tempnam(sys_get_temp_dir(), 'ffgen_');
    if ($tmp === false) {
        pb_request('PATCH', "/api/collections/output_media/records/{$itemId}", [
            'status' => 'pending',
            'garage_key' => $key,
            'garage_url' => $fallbackGarage,
        ], $authHeader);
        $gUrl = $fallbackGarage;
    } else {
        file_put_contents($tmp, $uploadBytes);
        $patchData = [
            'status' => 'pending',
            'garage_key' => $key,
            'garage_url' => '',
            'media_file' => ff_curl_file($tmp, $uploadMime, $uploadName),
        ];
        $mg = pb_request_multipart('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), $patchData, $authHeader);
        @unlink($tmp);
        if ($mg['code'] < 200 || $mg['code'] >= 300) {
            repair_content_items_media_schema();
            $tmp2 = tempnam(sys_get_temp_dir(), 'ffgen_');
            if ($tmp2 !== false) {
                file_put_contents($tmp2, $uploadBytes);
                $patchData['media_file'] = ff_curl_file($tmp2, $uploadMime, $uploadName);
                $mg = pb_request_multipart('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), $patchData, $authHeader);
                @unlink($tmp2);
            }
        }
        if ($mg['code'] >= 200 && $mg['code'] < 300) {
            $body = $mg['body'];
            $collId = trim((string) ($body['collectionId'] ?? ''));
            $mediaName = trim((string) ($body['media_file'] ?? ''));
            if ($collId === '' || $mediaName === '') {
                $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), null, $authHeader);
                if ($gr['code'] === 200) {
                    $collId = trim((string) ($gr['body']['collectionId'] ?? $collId));
                    $mediaName = trim((string) ($gr['body']['media_file'] ?? $mediaName));
                }
            }
            $pbUrl = ($collId !== '' && $mediaName !== '') ? ff_pb_public_file_url($collId, (string) $itemId, $mediaName) : '';
            if ($pbUrl !== '') {
                pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), ['garage_url' => $pbUrl], $authHeader);
                $gUrl = $pbUrl;
            } else {
                pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode((string) $itemId), ['garage_url' => $fallbackGarage], $authHeader);
                $gUrl = $fallbackGarage;
            }
        } else {
            pb_request('PATCH', "/api/collections/output_media/records/{$itemId}", [
                'status' => 'pending',
                'garage_key' => $key,
                'garage_url' => $fallbackGarage,
            ], $authHeader);
            $gUrl = $fallbackGarage;
        }
    }
    $semanticBlob = formatforge_antfly_content_semantic_text('(pipeline)', '', trim($prompt));
    $antflyType = ff_antfly_type_for_content_row($row, $pipelineRow);
    formatforge_index_content_in_antfly((string) $itemId, $semanticBlob, $antflyType, 'pending', $gUrl ?: null, $uploadMime, '', '(pipeline)');
    ff_verify_shape_bundle_after_item_finish($row, $pipelineRow, $authHeader);
    ff_pipeline_trace_log('generate_content_ok', [
        'content_item_id' => $itemId,
        'pipeline_id' => $pipelineId !== '' ? $pipelineId : null,
        'provider' => $provider,
        'shape_kind' => $wantImageOutput ? 'image' : 'video',
    ]);
}

/** Detach CLI worker so PHP-FPM does not terminate the job (request_terminate_timeout). */
function formatforge_spawn_generate_worker(string $itemId): bool {
    $php = ff_php_cli_binary();
    if (!is_executable($php)) {
        ff_debug_log('generate_worker_spawn_fail', ['reason' => 'no_php_binary']);
        return false;
    }
    if (!function_exists('exec')) {
        return false;
    }
    $script = __DIR__ . '/index.php';
    $log = '/dev/null';
    foreach ([__DIR__ . '/storage', rtrim(sys_get_temp_dir(), '/\\') . '/formatforge'] as $cand) {
        if ($cand === '') {
            continue;
        }
        if (!is_dir($cand)) {
            @mkdir($cand, 0775, true);
        }
        if (is_dir($cand) && is_writable($cand)) {
            $log = rtrim($cand, '/\\') . '/generate-worker.log';
            break;
        }
    }
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' complete-generate ' . escapeshellarg($itemId)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 &';
    @exec($cmd);
    ff_pipeline_trace_log('generate_content_spawned', ['content_item_id' => $itemId, 'worker_log' => $log]);
    return true;
}

function formatforge_complete_generate_cli(string $itemId): void {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $tok = $auth['token'];
    $rec = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($itemId), null, $tok);
    if ($rec['code'] === 200 && is_array($rec['body'] ?? null)) {
        $meta = is_array($rec['body']['metadata'] ?? null) ? $rec['body']['metadata'] : [];
        if (trim((string)($meta['pipeline_id'] ?? '')) !== '') {
            fwrite(STDERR, "complete-generate: skipped {$itemId} — this row is a pipeline run (metadata.pipeline_id). "
                . "Only the Go `pipeline-generate` binary should complete it; calling PHP complete-generate would mark it failed.\n"
                . "Fix: build/run `pipelines/<subdir>/pipeline-generate`, check `/tmp/formatforge/pipeline-binary.log`, and PocketBase `rejected_reason` if already failed.\n");
            exit(0);
        }
    }
    // Same as pb_fetch_collection / repair_* : pb_request() sets `Authorization: <token>`
    formatforge_generate_content_finish($itemId, $tok);
}

/**
 * Create one test content_items row for a pipeline and run generation end-to-end (CLI smoke test).
 * Exit 0 when status is pending with media; leaves the row for Curate (title prefixed with [verify]).
 */
function formatforge_verify_pipeline_generation_cli(string $pipelinePbId): int {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        return 1;
    }
    $token = $auth['token'];
    $pRes = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelinePbId), null, $token);
    if ($pRes['code'] !== 200) {
        fwrite(STDERR, "Pipeline not found.\n");
        return 1;
    }
    $pipelineRecord = $pRes['body'];
    if (array_key_exists('is_active', $pipelineRecord) && $pipelineRecord['is_active'] === false) {
        fwrite(STDERR, "Pipeline is inactive.\n");
        return 1;
    }
    $gate = ff_pipeline_quality_gate_check($pipelineRecord);
    if (!$gate['ok']) {
        $why = implode(' ', $gate['reasons']);
        fwrite(STDERR, "Pipeline quality gate failed: {$why}\n");
        trigger_pipeline_edit_loop_for_gate_violation($pipelineRecord, $why, $token);
        return 1;
    }
    $template = trim((string)($pipelineRecord['prompt_template'] ?? ''));
    if ($template === '') {
        fwrite(STDERR, "Pipeline has empty prompt_template.\n");
        return 1;
    }
    $promptForRun = $template;
    $rejAdd = ff_pipeline_rejection_prompt_addendum($pipelineRecord);
    if ($rejAdd !== '') {
        $promptForRun .= "\n\n" . $rejAdd;
    }
    $meta = [
        'pipeline_id' => $pipelinePbId,
        'origin' => 'verify_pipeline_run',
    ];
    if (!empty($pipelineRecord['name'])) {
        $meta['pipeline_name'] = $pipelineRecord['name'];
    }
    $plOut = trim((string)($pipelineRecord['output_type'] ?? ''));
    if ($plOut !== '') {
        $meta['output_type'] = $plOut;
    }
    $effOut = ff_pipeline_effective_output_type($pipelineRecord);
    if ($effOut !== '') {
        $meta['pipeline_output_type'] = $effOut;
    }
    $rec = pb_request('POST', '/api/collections/output_media/records', [
        'type' => ff_pipeline_content_item_type($pipelineRecord),
        'prompt' => $promptForRun,
        'title' => '[verify] ' . substr($promptForRun, 0, 72),
        'status' => 'generating',
        'metadata' => $meta,
    ], $token);
    if ($rec['code'] < 200 || $rec['code'] >= 300) {
        fwrite(STDERR, 'Create content_items failed: ' . ($rec['body']['message'] ?? json_encode($rec['body'])) . "\n");
        return 1;
    }
    $itemId = trim((string)($rec['body']['id'] ?? ''));
    if ($itemId === '') {
        fwrite(STDERR, "Missing new content item id.\n");
        return 1;
    }
    fwrite(STDOUT, "verify: created content_items {$itemId}, running generation...\n");
    formatforge_generate_content_finish($itemId, $token);
    $check = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($itemId), null, $token);
    if ($check['code'] !== 200) {
        fwrite(STDERR, "Failed to read item after generation.\n");
        return 1;
    }
    $row = $check['body'];
    $st = trim((string)($row['status'] ?? ''));
    if ($st === 'failed') {
        fwrite(STDERR, "Generation failed (status=failed).\n");
        return 1;
    }
    if ($st !== 'pending') {
        fwrite(STDERR, "Unexpected status after generation: {$st}\n");
        return 1;
    }
    $gu = trim((string)($row['garage_url'] ?? ''));
    $mf = trim((string)($row['media_file'] ?? ''));
    if ($gu === '' && $mf === '') {
        fwrite(STDERR, "No media URL or media_file on pending item.\n");
        return 1;
    }
    fwrite(STDOUT, "verify: OK (content_items id={$itemId}, pending with media)\n");
    $al = ff_measure_generation_input_alignment($itemId, $token, false);
    if (!empty($al['ok']) && ($al['skipped'] ?? '') === '' && isset($al['alignment']['cosine_distance'])) {
        fwrite(STDOUT, 'verify: input_alignment cosine_distance=' . $al['alignment']['cosine_distance'] . ' (lower is closer to input ref)' . "\n");
    } elseif (($al['skipped'] ?? '') !== '') {
        fwrite(STDOUT, 'verify: input_alignment skipped (' . $al['skipped'] . ")\n");
    }
    return 0;
}

/**
 * List content_items stuck in `generating` (worker never finished or was killed).
 *
 * @return array{ok: bool, items: array<int, array{id: string, updated: string, title: string, pipeline_id: ?string}>, error?: string}
 */
function formatforge_list_generating_content_items(string $authToken): array {
    $qs = http_build_query(['filter' => 'status = "generating"', 'sort' => '-@rowid', 'perPage' => 200]);
    $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authToken);
    if ($r['code'] !== 200) {
        return ['ok' => false, 'items' => [], 'error' => $r['body']['message'] ?? ('HTTP ' . $r['code'])];
    }
    $items = $r['body']['items'] ?? [];
    $out = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $meta = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
        $pid = isset($meta['pipeline_id']) ? trim((string) $meta['pipeline_id']) : '';
        $out[] = [
            'id' => (string)($it['id'] ?? ''),
            'updated' => (string)($it['updated'] ?? ''),
            'title' => (string)($it['title'] ?? ''),
            'pipeline_id' => $pid !== '' ? $pid : null,
        ];
    }
    return ['ok' => true, 'items' => $out];
}

function ffmpeg_compose(array $inputs, string $outputPath, array $options = []): bool {
    $ff = $GLOBALS['CONFIG']['ffmpeg_path'];
    $filter = $options['filter'] ?? '';
    $duration = $options['duration'] ?? '';
    $cmd = escapeshellcmd($ff) . ' -y';
    foreach ($inputs as $in) $cmd .= ' -i ' . escapeshellarg($in);
    if ($filter) $cmd .= ' -filter_complex ' . escapeshellarg($filter);
    if ($duration) $cmd .= ' -t ' . escapeshellarg($duration);
    $cmd .= ' ' . escapeshellarg($outputPath) . ' 2>/dev/null';
    exec($cmd, $out, $code);
    return $code === 0;
}

/** Extract one JPEG frame from video bytes (for image-shape outputs). */
function ff_extract_jpeg_frame_from_video_bytes(string $videoData): ?string {
    if ($videoData === '') {
        return null;
    }
    $tmpVideo = tempnam(sys_get_temp_dir(), 'ffv_');
    if ($tmpVideo === false) {
        return null;
    }
    $tmpImageBase = tempnam(sys_get_temp_dir(), 'ffi_');
    if ($tmpImageBase === false) {
        @unlink($tmpVideo);
        return null;
    }
    @unlink($tmpImageBase);
    $tmpImage = $tmpImageBase . '.jpg';
    file_put_contents($tmpVideo, $videoData);
    $ff = $GLOBALS['CONFIG']['ffmpeg_path'] ?? 'ffmpeg';
    $cmd = escapeshellcmd($ff)
        . ' -y -i ' . escapeshellarg($tmpVideo)
        . ' -frames:v 1 -q:v 2 ' . escapeshellarg($tmpImage)
        . ' 2>/dev/null';
    exec($cmd, $out, $code);
    @unlink($tmpVideo);
    if ($code !== 0 || !is_file($tmpImage) || filesize($tmpImage) <= 0) {
        @unlink($tmpImage);
        return null;
    }
    $bytes = @file_get_contents($tmpImage);
    @unlink($tmpImage);
    return ($bytes !== false && $bytes !== '') ? $bytes : null;
}

/**
 * Build a fresh image variant from a source media URL (no direct reuse).
 * Keeps structure while applying normalization + subtle randomized tone shifts.
 */
function ff_render_image_variant_from_source_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $src = @file_get_contents($url) ?: '';
    if ($src === '') {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 45]);
        $src = curl_exec($ch) ?: '';
        curl_close($ch);
    }
    if ($src === '') {
        return null;
    }

    $tmpIn = tempnam(sys_get_temp_dir(), 'ffsrc_');
    if ($tmpIn === false) {
        return null;
    }
    $tmpOutBase = tempnam(sys_get_temp_dir(), 'ffdst_');
    if ($tmpOutBase === false) {
        @unlink($tmpIn);
        return null;
    }
    @unlink($tmpOutBase);
    $tmpOut = $tmpOutBase . '.jpg';
    file_put_contents($tmpIn, $src);

    $contrast = number_format(1 + (random_int(3, 14) / 100), 2, '.', '');
    $saturation = number_format(1 + (random_int(2, 18) / 100), 2, '.', '');
    $brightness = number_format(random_int(-6, 6) / 100, 2, '.', '');
    $gamma = number_format(1 + (random_int(-8, 8) / 100), 2, '.', '');
    $hue = (string) random_int(-14, 14);
    $zoom = 1 + (random_int(8, 35) / 100);
    $sw = (int)round(1080 * $zoom);
    $sh = (int)round(1350 * $zoom);
    $cropX = (string) random_int(0, max(0, $sw - 1080));
    $cropY = (string) random_int(0, max(0, $sh - 1350));
    $flip = (random_int(0, 9) < 3) ? ',hflip' : '';
    $vf = "scale={$sw}:{$sh}:force_original_aspect_ratio=increase,"
        . "crop=1080:1350:{$cropX}:{$cropY},"
        . "eq=contrast={$contrast}:brightness={$brightness}:saturation={$saturation}:gamma={$gamma},"
        . "hue=h={$hue},"
        . 'drawbox=x=0:y=0:w=iw:h=22:color=black@0.30:t=fill,'
        . 'drawbox=x=0:y=ih-22:w=iw:h=22:color=black@0.30:t=fill,'
        . "noise=alls=6:allf=t{$flip}";
    $ff = $GLOBALS['CONFIG']['ffmpeg_path'] ?? 'ffmpeg';
    $cmd = escapeshellcmd($ff)
        . ' -y -i ' . escapeshellarg($tmpIn)
        . ' -vf ' . escapeshellarg($vf)
        . ' -q:v 2 ' . escapeshellarg($tmpOut)
        . ' 2>/dev/null';
    exec($cmd, $out, $code);
    @unlink($tmpIn);
    if ($code !== 0 || !is_file($tmpOut) || filesize($tmpOut) <= 0) {
        @unlink($tmpOut);
        return null;
    }
    $bytes = @file_get_contents($tmpOut);
    @unlink($tmpOut);
    return ($bytes !== false && $bytes !== '') ? $bytes : null;
}

/**
 * HTTP GET with byte cap (for lightweight image analysis).
 *
 * @return string|null raw body or null on failure / over cap
 */
function ff_http_get_bytes_capped(string $url, int $timeoutSec = 20, int $maxBytes = 12582912): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(5, $timeoutSec),
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno !== 0 || !is_string($body) || $body === '') {
        return null;
    }
    return $body;
}

/**
 * Measurable composition hints from a raster backing image (for pipeline Step 1 / source_analysis.md).
 * Uses GD when available for palette + luminance + edge energy; otherwise dimensions + mime only.
 *
 * @return array<string,mixed>|null
 */
function ff_image_composition_technical_snapshot(string $mediaUrl): ?array {
    $mediaUrl = trim($mediaUrl);
    if ($mediaUrl === '') {
        return null;
    }
    $bytes = ff_http_get_bytes_capped($mediaUrl, 22, 12582912);
    if ($bytes === null || $bytes === '') {
        return null;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'ffcmp_');
    if ($tmp === false) {
        return null;
    }
    file_put_contents($tmp, $bytes);
    $info = @getimagesize($tmp);
    if ($info === false || ($info[0] ?? 0) < 2 || ($info[1] ?? 0) < 2) {
        @unlink($tmp);
        return null;
    }
    $w = (int) $info[0];
    $h = (int) $info[1];
    $mime = (string) ($info['mime'] ?? '');
    @unlink($tmp);

    $aspect = $h > 0 ? round($w / $h, 4) : 0.0;
    $orientation = 'square';
    if ($aspect > 1.05) {
        $orientation = 'landscape';
    } elseif ($aspect < 0.95) {
        $orientation = 'portrait';
    }

    $out = [
        'width_px' => $w,
        'height_px' => $h,
        'aspect_ratio_wh' => $aspect,
        'orientation' => $orientation,
        'mime' => $mime,
        'analysis_method' => 'dimensions_only',
    ];

    if (!function_exists('imagecreatefromstring')) {
        return $out;
    }
    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        return $out;
    }
    $iw = imagesx($im);
    $ih = imagesy($im);
    $tw = 48;
    $th = (int) max(1, round($ih * ($tw / max(1, $iw))));
    $thumb = imagecreatetruecolor($tw, $th);
    if ($thumb === false) {
        imagedestroy($im);
        return $out;
    }
    imagecopyresampled($thumb, $im, 0, 0, 0, 0, $tw, $th, $iw, $ih);

    $bucket = [];
    $lumSum = 0.0;
    $nPix = 0;
    for ($y = 0; $y < $th; $y++) {
        for ($x = 0; $x < $tw; $x++) {
            $rgb = imagecolorat($thumb, $x, $y);
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $lum = 0.299 * $r + 0.587 * $g + 0.114 * $b;
            $lumSum += $lum;
            $nPix++;
            $qr = (int) (floor($r / 32) * 32);
            $qg = (int) (floor($g / 32) * 32);
            $qb = (int) (floor($b / 32) * 32);
            $key = sprintf('%02x%02x%02x', min(255, $qr), min(255, $qg), min(255, $qb));
            $bucket[$key] = ($bucket[$key] ?? 0) + 1;
        }
    }
    arsort($bucket);
    $palette = [];
    $i = 0;
    foreach ($bucket as $hex => $cnt) {
        $palette[] = '#' . $hex;
        $i++;
        if ($i >= 6) {
            break;
        }
    }

    $region = function ($resource, int $x0, int $y0, int $x1, int $y1): array {
        $rs = 0;
        $gs = 0;
        $bs = 0;
        $c = 0;
        for ($yy = $y0; $yy < $y1; $yy++) {
            for ($xx = $x0; $xx < $x1; $xx++) {
                $rgb = imagecolorat($resource, $xx, $yy);
                $rs += ($rgb >> 16) & 0xFF;
                $gs += ($rgb >> 8) & 0xFF;
                $bs += $rgb & 0xFF;
                $c++;
            }
        }
        if ($c === 0) {
            return ['hex' => '#000000', 'mean_luminance_0_1' => 0.0];
        }
        $r = (int) round($rs / $c);
        $g = (int) round($gs / $c);
        $b = (int) round($bs / $c);
        $lum = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255.0;

        return [
            'hex' => sprintf('#%02x%02x%02x', $r, $g, $b),
            'mean_luminance_0_1' => round($lum, 4),
        ];
    };

    $yThird = (int) max(1, floor($th / 3));
    $regions = [
        'top_band' => $region($thumb, 0, 0, $tw, $yThird),
        'middle_band' => $region($thumb, 0, $yThird, $tw, 2 * $yThird),
        'bottom_band' => $region($thumb, 0, 2 * $yThird, $tw, $th),
    ];

    $edgeSum = 0.0;
    $edgeN = 0;
    for ($y = 1; $y < $th; $y++) {
        for ($x = 1; $x < $tw; $x++) {
            $a = imagecolorat($thumb, $x, $y);
            $l = imagecolorat($thumb, $x - 1, $y);
            $u = imagecolorat($thumb, $x, $y - 1);
            $la = ((($a >> 16) & 0xFF) * 0.299 + ((($a >> 8) & 0xFF) * 0.587) + (($a & 0xFF) * 0.114));
            $ll = ((($l >> 16) & 0xFF) * 0.299 + ((($l >> 8) & 0xFF) * 0.587) + (($l & 0xFF) * 0.114));
            $lu = ((($u >> 16) & 0xFF) * 0.299 + ((($u >> 8) & 0xFF) * 0.587) + (($u & 0xFF) * 0.114));
            $edgeSum += abs($la - $ll) + abs($la - $lu);
            $edgeN += 2;
        }
    }
    $edgeNorm = $edgeN > 0 ? round(min(1.0, ($edgeSum / $edgeN) / 255.0), 4) : 0.0;
    $meanLum = $nPix > 0 ? round(($lumSum / $nPix) / 255.0, 4) : 0.0;

    imagedestroy($thumb);
    imagedestroy($im);

    $out['analysis_method'] = 'gd_thumbnail_48px';
    $out['dominant_palette_hex'] = $palette;
    $out['vertical_thirds_mean_color'] = $regions;
    $out['mean_luminance_0_1'] = $meanLum;
    $out['edge_activity_0_1'] = $edgeNorm;
    $out['source_analysis_prompt'] = 'In source_analysis.md, add a **Technical composition** subsection: reference width_px/height_px, orientation, mean_luminance_0_1 (light vs dark overall), edge_activity_0_1 (busier vs calmer layout), dominant_palette_hex, and vertical_thirds_mean_color (top/middle/bottom bands). Translate metrics into foreground/background hierarchy, safe margins, contrast risks, and how the *new* composite should differ structurally while staying on-brand for the caption’s intent.';

    return $out;
}

/**
 * True when URL path looks like a direct file (S3, Garage, CDN) rather than a gallery page.
 */
function is_direct_media_url(string $url): bool {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'mp4', 'webm', 'm4v', 'mov', 'mkv', 'mp3', 'm4a', 'wav'], true);
}

/** Instagram / Threads hosts that usually require cookies for extractors. */
function ff_url_needs_ig_cookies(string $url): bool {
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?? ''));
    if ($host === '') return false;
    return str_ends_with($host, '.instagram.com')
        || $host === 'instagram.com'
        || str_ends_with($host, '.threads.net')
        || $host === 'threads.net'
        || str_ends_with($host, '.threads.com')
        || $host === 'threads.com'
        || $host === 'instagr.am';
}

function ff_shell_cookie_opt(string $path): string {
    $path = trim($path);
    if ($path === '' || !is_file($path) || !is_readable($path)) {
        return '';
    }
    $real = realpath($path);
    if ($real === false) {
        return '';
    }
    return ' --cookies ' . escapeshellarg($real);
}

/** Last $maxBytes of a log file (for fetch extractor output). */
function ff_tail_log_file(string $path, int $maxBytes = 6000): string {
    if ($maxBytes < 64) {
        $maxBytes = 64;
    }
    if (!is_file($path)) {
        return '';
    }
    $raw = @file_get_contents($path);
    if ($raw === false || $raw === '') {
        return '';
    }
    if (strlen($raw) <= $maxBytes) {
        return $raw;
    }
    return "…(truncated, showing last {$maxBytes} bytes)\n" . substr($raw, -$maxBytes);
}

function ff_fetch_failure_hint(string $url): string {
    if (!ff_url_needs_ig_cookies($url)) {
        return '';
    }
    $cfg = $GLOBALS['CONFIG'];
    $hasGd = ($cfg['gallery_dl_cookies'] ?? '') !== '' && is_file((string)$cfg['gallery_dl_cookies']);
    $ytPath = ($cfg['yt_dlp_cookies'] ?? '') !== '' ? (string)$cfg['yt_dlp_cookies'] : (string)($cfg['gallery_dl_cookies'] ?? '');
    $hasYt = $ytPath !== '' && is_file($ytPath);
    if ($hasGd || $hasYt) {
        return ' Cookies file is set but may be expired — re-export from your browser (instagram.com logged in) and update GALLERY_DL_COOKIES.';
    }
    return ' Instagram (and similar) now block anonymous downloads. Export a Netscape cookies.txt while logged in at instagram.com, place it on the server, and set GALLERY_DL_COOKIES=/absolute/path/to/cookies.txt in .env (optional YT_DLP_COOKIES for yt-dlp).';
}

/** Strip tracking query (e.g. ?img_index=1) so IG post URLs dedupe and match the public share link. */
function ff_canonical_fetch_url(string $url): string {
    $url = trim($url);
    $p = parse_url($url);
    if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
        return $url;
    }
    $host = strtolower((string) $p['host']);
    if ($host === 'instagram.com' || $host === 'www.instagram.com') {
        $path = $p['path'] ?? '/';
        return 'https://www.instagram.com' . rtrim($path, '/');
    }
    return $url;
}

/** Human-readable title for fetched media (shortcode for Instagram, else basename / “Media”). */
function ff_short_title_for_fetch_url(string $url, string $fallbackBasename): string {
    $url = trim($url);
    if (preg_match('~instagram\.com/(?:p|reel|tv)/([^/?#]+)~i', $url, $m)) {
        return 'IG ' . $m[1];
    }
    if (preg_match('~(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]{6,})~i', $url, $m)) {
        return 'YouTube ' . $m[1];
    }
    if (preg_match('~tiktok\.com/.*/video/(\d+)~i', $url, $m)) {
        return 'TikTok ' . $m[1];
    }
    $fb = trim($fallbackBasename);
    if ($fb !== '' && $fb !== '.' && $fb !== '..') {
        return $fb;
    }
    return 'Fetched media';
}

/**
 * Download a single HTTP(S) URL to a file inside $tmpDir (curl, follows redirects).
 */
function fetch_direct_http_file(string $url, string $tmpDir): ?string {
    $path = (string)(parse_url($url, PHP_URL_PATH) ?? '');
    $base = basename($path) ?: 'download.bin';
    $base = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $base) ?: 'download.bin';
    $dest = $tmpDir . '/' . $base;
    $fp = @fopen($dest, 'wb');
    if (!$fp) {
        return null;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_USERAGENT => 'FormatForge/1.0',
    ]);
    $ok = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);
    if (!$ok || $code < 200 || $code >= 300) {
        @unlink($dest);
        return null;
    }
    if (!is_file($dest) || filesize($dest) === 0) {
        @unlink($dest);
        return null;
    }
    return $dest;
}

/**
 * Download media from URL using gallery-dl (images) or yt-dlp (video/audio).
 * @param string $url Source URL
 * @param string $downloader 'gallery-dl' or 'yt-dlp'
 * @return array{0: array, 1: ?string, 2: array} [file paths, temp dir, diagnostic]
 */
function fetch_media_from_url(string $url, string $downloader): array {
    $cfg = $GLOBALS['CONFIG'];
    $diag = [
        'tool' => $downloader,
        'direct_url_candidate' => is_direct_media_url($url),
        'direct_ok' => false,
        'cookies_file_used' => false,
        'exit_code' => null,
        'output_tail' => '',
    ];
    $tmpDir = sys_get_temp_dir() . '/ff_fetch_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpDir, 0755, true)) {
        $diag['error'] = 'temp_dir_mkdir_failed';
        return [[], null, $diag];
    }
    if (is_direct_media_url($url)) {
        $direct = fetch_direct_http_file($url, $tmpDir);
        if ($direct) {
            $diag['direct_ok'] = true;
            return [[$direct], $tmpDir, $diag];
        }
    }
    $files = [];
    if ($downloader === 'gallery-dl') {
        $cookieOpt = ff_shell_cookie_opt((string)($cfg['gallery_dl_cookies'] ?? ''));
        if ($cookieOpt !== '') {
            $diag['cookies_file_used'] = true;
        }
        $bin = ff_fetch_executable((string)($cfg['gallery_dl_path'] ?? ''), 'gallery-dl');
        $diag['resolved_bin'] = $bin;
        $logFile = sys_get_temp_dir() . '/ff_gdl_' . bin2hex(random_bytes(8)) . '.log';
        $cmd = ff_fetch_env_prefix() . escapeshellcmd($bin) . $cookieOpt . ' -d ' . escapeshellarg($tmpDir) . ' ' . escapeshellarg($url);
        $cmd .= ' > ' . escapeshellarg($logFile) . ' 2>&1';
        exec($cmd, $void, $code);
        $diag['exit_code'] = $code;
        $diag['output_tail'] = ff_tail_log_file($logFile, 8000);
        @unlink($logFile);
        if ($code !== 0) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($tmpDir);
            return [[], null, $diag];
        }
        $all = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) $all[] = $f->getPathname(); }
        $files = $all;
        if (count($files) === 0) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($tmpDir);
            $diag['note'] = 'extractor_exit_zero_but_no_files';
            return [[], null, $diag];
        }
    } elseif ($downloader === 'yt-dlp') {
        $cookieOpt = ff_shell_cookie_opt((string)($cfg['yt_dlp_cookies'] ?? ''));
        if ($cookieOpt === '') {
            $cookieOpt = ff_shell_cookie_opt((string)($cfg['gallery_dl_cookies'] ?? ''));
        }
        if ($cookieOpt !== '') {
            $diag['cookies_file_used'] = true;
        }
        $bin = ff_fetch_executable((string)($cfg['yt_dlp_path'] ?? ''), 'yt-dlp');
        $diag['resolved_bin'] = $bin;
        $outFile = $tmpDir . '/%(id)s.%(ext)s';
        $logFile = sys_get_temp_dir() . '/ff_ytdl_' . bin2hex(random_bytes(8)) . '.log';
        $cmd = ff_fetch_env_prefix() . escapeshellcmd($bin) . $cookieOpt . ' -o ' . escapeshellarg($outFile) . ' ' . escapeshellarg($url);
        $cmd .= ' > ' . escapeshellarg($logFile) . ' 2>&1';
        exec($cmd, $void, $code);
        $diag['exit_code'] = $code;
        $diag['output_tail'] = ff_tail_log_file($logFile, 8000);
        @unlink($logFile);
        if ($code !== 0) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($tmpDir);
            return [[], null, $diag];
        }
        $files = glob($tmpDir . '/*') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        if (count($files) === 0) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($tmpDir);
            $diag['note'] = 'extractor_exit_zero_but_no_files';
            return [[], null, $diag];
        }
    }
    $files = array_values(array_filter($files, 'is_file'));
    return [$files, $tmpDir, $diag];
}

/**
 * Try direct HTTP (when URL looks like a file), then gallery-dl, then yt-dlp.
 *
 * @return array{0: array, 1: ?string, 2: string, 3: array} paths, tmpDir, via label, meta (attempts)
 */
function fetch_media_from_url_auto(string $url): array {
    [$files, $tmpDir, $dGd] = fetch_media_from_url($url, 'gallery-dl');
    if (count($files) > 0) {
        $via = !empty($dGd['direct_ok']) ? 'direct-http' : 'gallery-dl';
        return [$files, $tmpDir, $via, ['attempts' => [$dGd]]];
    }
    [$files, $tmpDir, $dYt] = fetch_media_from_url($url, 'yt-dlp');
    if (count($files) > 0) {
        return [$files, $tmpDir, 'yt-dlp', ['attempts' => [$dGd, $dYt]]];
    }
    return [[], null, '', ['attempts' => [$dGd, $dYt]]];
}

/** OpenRouter embedder JSON shared by Antfly tables (API key is optional if Antfly has env). PHP `embed_text` / alignment use GEMINI_API_KEY + Google embedContent when set — Antfly is unchanged unless you reconfigure it separately. */
function antfly_openrouter_embedder_config(): array {
    $cfg = $GLOBALS['CONFIG'];
    $embedModel = trim((string)($cfg['embed_model'] ?? '')) ?: 'google/gemini-embedding-001';
    $embedder = [
        'provider' => 'openrouter',
        'model' => $embedModel,
    ];
    if (!empty($cfg['openrouter_key'])) {
        $embedder['api_key'] = $cfg['openrouter_key'];
    }
    return $embedder;
}

/**
 * Multimodal-friendly index string: Antfly renders `remoteMedia` from `media_url` when set, then text fields.
 * Same textual block is used for pipeline novelty queries (semantic_search) so it stays comparable to templates.
 */
function formatforge_antfly_content_semantic_text(string $title, string $sourceUrl, string $caption): string {
    $title = trim($title);
    $sourceUrl = trim($sourceUrl);
    $caption = trim($caption);
    $lines = [];
    if ($title !== '') {
        $lines[] = 'Title: ' . $title;
    }
    if ($sourceUrl !== '') {
        $lines[] = 'Source: ' . $sourceUrl;
    }
    if ($caption !== '') {
        $lines[] = $caption;
    }
    return trim(implode("\n", $lines));
}

/**
 * Upsert a PocketBase content_items row into Antfly. Embeddings run inside Antfly (template + remoteMedia when `media_url` is set).
 */
function formatforge_index_content_in_antfly(
    string $pbRecordId,
    string $prompt,
    string $type,
    string $status = 'pending',
    ?string $mediaUrl = null,
    ?string $mime = null,
    ?string $sourceUrl = null,
    ?string $title = null
): bool {
    if (!formatforge_antfly_novelty_configured()) {
        return false;
    }
    $pbRecordId = trim($pbRecordId);
    $prompt = trim($prompt);
    if ($pbRecordId === '' || $prompt === '') {
        return false;
    }
    $mediaUrl = $mediaUrl !== null ? trim($mediaUrl) : '';
    $mime = $mime !== null ? trim($mime) : '';
    $sourceUrl = $sourceUrl !== null ? trim($sourceUrl) : '';
    $title = $title !== null ? trim($title) : '';
    $doc = [
        'id' => $pbRecordId,
        'prompt' => $prompt,
        'type' => $type,
        'status' => $status,
        'title' => $title,
        'source_url' => $sourceUrl,
        'mime' => $mime,
        'media_url' => $mediaUrl,
    ];
    if (!antfly_index('content', $doc)) {
        ff_debug_log('antfly_index_failed', ['id' => $pbRecordId, 'type' => $type]);
        return false;
    }
    return true;
}

function antfly_index(string $table, array $doc): bool {
    if (!formatforge_antfly_novelty_configured()) {
        return false;
    }
    $cfg = $GLOBALS['CONFIG'];
    $base = rtrim((string)($cfg['antfly_url'] ?? ''), '/');
    if ($base === '') {
        return false;
    }
    $id = trim((string)($doc['id'] ?? ''));
    if ($id === '') {
        return false;
    }
    $url = $base . '/api/v1/tables/' . urlencode($table) . '/batch';
    $key = $table . ':' . $id;
    $inserts = [$key => $doc];
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['antfly_key'])) {
        $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['inserts' => $inserts]),
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404 && antfly_ensure_table($table)) {
        return antfly_index($table, $doc);
    }
    return $code >= 200 && $code < 300;
}

function antfly_ensure_table(string $table): bool {
    if ($table === 'content') {
        return antfly_create_content_table();
    }
    if ($table === 'pipeline_refs') {
        return antfly_create_pipeline_refs_table();
    }
    return false;
}

/** Handlebars template: Antfly fetches `media_url` for vision/video/audio when the URL is reachable from Antfly. */
function antfly_content_semantic_template(): string {
    return "{{#if media_url}}{{remoteMedia url=media_url}}{{/if}}\n{{prompt}}\n{{title}}\n{{source_url}}\n{{mime}}";
}

function antfly_create_content_table(): bool {
    $cfg = $GLOBALS['CONFIG'];
    $base = rtrim((string)($cfg['antfly_url'] ?? ''), '/');
    if ($base === '') {
        return false;
    }
    $url = $base . '/api/v1/tables/content';
    $embedder = antfly_openrouter_embedder_config();
    $body = [
        'num_shards' => 1,
        'schema' => [
            'document_schemas' => [
                'content' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'x-antfly-types' => ['keyword']],
                            'prompt' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'type' => ['type' => 'string', 'x-antfly-types' => ['keyword']],
                            'status' => ['type' => 'string', 'x-antfly-types' => ['keyword']],
                            'title' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'source_url' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'mime' => ['type' => 'string', 'x-antfly-types' => ['keyword']],
                            'media_url' => ['type' => 'string', 'x-antfly-types' => ['text']],
                        ],
                        'x-antfly-include-in-all' => ['prompt', 'title', 'source_url'],
                    ],
                ],
            ],
            'default_type' => 'content',
        ],
        'indexes' => [
            'search_idx' => ['type' => 'full_text'],
            'semantic_idx' => [
                'type' => 'embeddings',
                'template' => antfly_content_semantic_template(),
                'embedder' => $embedder,
            ],
        ],
    ];
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['antfly_key'])) {
        $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function antfly_create_pipeline_refs_table(): bool {
    $cfg = $GLOBALS['CONFIG'];
    $base = rtrim((string)($cfg['antfly_url'] ?? ''), '/');
    if ($base === '') {
        return false;
    }
    $url = $base . '/api/v1/tables/pipeline_refs';
    $embedder = antfly_openrouter_embedder_config();
    // Same multimodal embedding template as `content` (gemini-embedding-001 / OpenRouter): remoteMedia + text.
    // Existing Antfly installs created with the old text-only `field: prompt` index must **drop/recreate** `pipeline_refs`
    // (or recreate the table from Antfly admin) once to pick up this schema — otherwise queries stay text-only.
    $body = [
        'num_shards' => 1,
        'schema' => [
            'document_schemas' => [
                'pipeline_ref' => [
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string', 'x-antfly-types' => ['keyword']],
                            'prompt' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'name' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'title' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'source_url' => ['type' => 'string', 'x-antfly-types' => ['text']],
                            'mime' => ['type' => 'string', 'x-antfly-types' => ['keyword']],
                            'media_url' => ['type' => 'string', 'x-antfly-types' => ['text']],
                        ],
                        'x-antfly-include-in-all' => ['prompt', 'name', 'title', 'source_url'],
                    ],
                ],
            ],
            'default_type' => 'pipeline_ref',
        ],
        'indexes' => [
            'search_idx' => ['type' => 'full_text'],
            'semantic_idx' => [
                'type' => 'embeddings',
                'template' => antfly_content_semantic_template(),
                'embedder' => $embedder,
            ],
        ],
    ];
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['antfly_key'])) {
        $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => 60,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function antfly_api_post_json(string $path, array $body, int $timeoutSec = 60): array {
    $cfg = $GLOBALS['CONFIG'];
    $base = rtrim((string)($cfg['antfly_url'] ?? ''), '/');
    $ch = curl_init($base . $path);
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['antfly_key'])) {
        $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_TIMEOUT => $timeoutSec,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $code, 'body' => json_decode($raw ?: '{}', true) ?? [], 'raw' => $raw ?: ''];
}

/**
 * Build Antfly `semantic_search` payload: plain string (text-only) or OpenRouter-style multimodal block (text + image URL).
 *
 * @return string|array<string,mixed>
 */
function antfly_semantic_search_payload(string $textBlob, ?string $imageUrl) {
    $textBlob = trim($textBlob);
    $imageUrl = $imageUrl !== null ? trim($imageUrl) : '';
    if ($imageUrl === '') {
        return $textBlob;
    }
    $parts = [];
    if ($textBlob !== '') {
        $parts[] = ['type' => 'text', 'text' => $textBlob];
    }
    $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $imageUrl]];

    return [['content' => $parts]];
}

/**
 * @param string|array<string,mixed> $semanticSearch
 */
function antfly_pipeline_refs_semantic_query($semanticSearch, int $limit, ?float $distanceUnder, int $timeoutSec = 90): array {
    $body = [
        'table' => 'pipeline_refs',
        'indexes' => ['semantic_idx'],
        'semantic_search' => $semanticSearch,
        'limit' => max(1, $limit),
    ];
    if ($distanceUnder !== null) {
        $body['distance_under'] = $distanceUnder;
    }

    return antfly_api_post_json('/api/v1/tables/pipeline_refs/query', $body, $timeoutSec);
}

/**
 * @return list<array<string,mixed>>
 */
function antfly_pipeline_refs_extract_hits(array $apiBody): array {
    $responses = $apiBody['responses'] ?? [];
    $first = $responses[0] ?? [];
    $hits = $first['hits']['hits'] ?? [];

    return is_array($hits) ? $hits : [];
}

/**
 * True if any active pipeline template is within semantic distance (Antfly cosine / embedding space; query may be multimodal).
 */
function antfly_any_pipeline_within_semantic_distance(string $textBlob, float $maxDistance, ?string $imageMediaUrl = null): bool {
    $textBlob = trim($textBlob);
    if ($textBlob === '' && trim((string)$imageMediaUrl) === '') {
        return false;
    }
    $payload = antfly_semantic_search_payload($textBlob, $imageMediaUrl);
    $res = antfly_pipeline_refs_semantic_query($payload, 1, $maxDistance, 90);
    if (($res['code'] < 200 || $res['code'] >= 300) && is_array($payload)) {
        $res = antfly_pipeline_refs_semantic_query($textBlob, 1, $maxDistance, 90);
    }
    if ($res['code'] < 200 || $res['code'] >= 300) {
        ff_debug_log('antfly_pipeline_query_failed', ['code' => $res['code']]);

        return false;
    }
    $hits = antfly_pipeline_refs_extract_hits($res['body']);

    return $hits !== [];
}

/**
 * Ranked pipeline templates vs a probe (text ± backing image) for agent iteration toward/away from the “mold”.
 *
 * @return array<string,mixed>|null
 */
function ff_antfly_mold_fit_alignment_report(string $textBlob, ?string $primaryImageMediaUrl, int $topK = 8): ?array {
    if (!formatforge_antfly_novelty_configured()) {
        return null;
    }
    $textBlob = trim($textBlob);
    $img = $primaryImageMediaUrl !== null ? trim($primaryImageMediaUrl) : '';
    if ($textBlob === '' && $img === '') {
        return null;
    }
    $payload = antfly_semantic_search_payload($textBlob, $img !== '' ? $img : null);
    $res = antfly_pipeline_refs_semantic_query($payload, max(1, $topK), null, 90);
    $queryMode = is_array($payload) ? 'multimodal_text_plus_image_url' : 'text_only';
    if (($res['code'] < 200 || $res['code'] >= 300) && is_array($payload) && $textBlob !== '') {
        $res = antfly_pipeline_refs_semantic_query($textBlob, max(1, $topK), null, 90);
        $queryMode = 'text_only_fallback';
    }
    if ($res['code'] < 200 || $res['code'] >= 300) {
        return [
            'ok' => false,
            'http_code' => $res['code'],
            'query_mode' => $queryMode,
            'matches' => [],
            'note' => 'Antfly pipeline_refs query failed; check ANTFLY_URL, API key, and that pipeline_refs uses the multimodal embedding template (recreate table if upgraded from legacy text-only index).',
        ];
    }
    $hits = antfly_pipeline_refs_extract_hits($res['body']);
    $rows = [];
    foreach ($hits as $top) {
        if (!is_array($top)) {
            continue;
        }
        $src = [];
        if (isset($top['_source']) && is_array($top['_source'])) {
            $src = $top['_source'];
        } elseif (isset($top['source']) && is_array($top['source'])) {
            $src = $top['source'];
        }
        $prompt = isset($src['prompt']) ? (string) $src['prompt'] : '';
        $rows[] = [
            'pipeline_pb_id' => $src['id'] ?? $top['_id'] ?? null,
            'name' => $src['name'] ?? null,
            'semantic_score' => $top['_score'] ?? $top['score'] ?? null,
            'sort' => $top['sort'] ?? null,
            'prompt_template_excerpt' => $prompt !== '' ? (strlen($prompt) > 200 ? substr($prompt, 0, 200) . '…' : $prompt) : null,
        ];
    }

    return [
        'ok' => true,
        'query_mode' => $queryMode,
        'matches' => $rows,
        'interpretation' => 'Antfly compares this probe to each synced pipeline row in the **same embedding space** as fetched `content` (multimodal template + google/gemini-embedding-001 via OpenRouter when configured). **Stronger match / lower distance** (see `semantic_score` / `sort` as returned by Antfly) means the pipeline “mold” is closer to this probe. Iterate `prompt_template` + `pipeline_architecture.json` + Go so the next run moves embeddings where you want, then `formatforge_sync_pipeline_refs_to_antfly` (or wait for automatic sync) updates the index.',
        'agent_loop_hint' => 'Try concrete variants (layout density, color temperature, motion vs static, caption structure) and re-measure; reject feedback + this report together show whether you are converging on the intended cluster.',
    ];
}

/**
 * Resolve optional backing image + URLs for indexing a pipeline template next to `content` rows (same embedding template).
 *
 * @return array{media_url:string,mime:string,title:string,source_url:string}
 */
function ff_pipeline_ref_antfly_backing_bundle_from_pb(array $pipelineRow, string $authHeader): array {
    $empty = ['media_url' => '', 'mime' => '', 'title' => '', 'source_url' => ''];
    $pmeta = is_array($pipelineRow['metadata'] ?? null) ? $pipelineRow['metadata'] : [];
    $slid = trim((string)($pmeta['backing_input_media_id'] ?? $pmeta['default_input_media_id'] ?? $pmeta['input_media_id'] ?? ''));
    $pipeName = trim((string)($pipelineRow['name'] ?? ''));
    if ($slid === '') {
        return $empty;
    }
    $rows = ff_pb_content_items_for_source_link($authHeader, $slid);
    $linkUrl = '';
    $slr = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($slid), null, $authHeader);
    if (($slr['code'] ?? 0) === 200 && is_array($slr['body'] ?? null)) {
        $linkUrl = trim((string)($slr['body']['url'] ?? ''));
    }
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        if (ff_shape_kind_for_content_type((string)($row['type'] ?? '')) !== 'image') {
            continue;
        }
        $u = ff_content_item_effective_media_url($row);
        if ($u === '') {
            continue;
        }
        $itMeta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        $mime = trim((string)($itMeta['mime'] ?? $row['mime'] ?? ''));
        if ($mime === '') {
            $mime = 'image/jpeg';
        }
        $tit = trim((string)($row['title'] ?? ''));

        return [
            'media_url' => $u,
            'mime' => $mime,
            'title' => $tit !== '' ? $tit : $pipeName,
            'source_url' => $linkUrl !== '' ? $linkUrl : trim((string)($row['prompt'] ?? '')),
        ];
    }

    return array_merge($empty, [
        'title' => $pipeName,
        'source_url' => $linkUrl,
    ]);
}

/**
 * First fetched backing image URL for a pipeline row (metadata source link → content_items), for Antfly multimodal `pipeline_refs`.
 */
function ff_pipeline_reference_media_url_for_antfly(array $pipelineRow, string $authHeader): string {
    $b = ff_pipeline_ref_antfly_backing_bundle_from_pb($pipelineRow, $authHeader);

    return $b['media_url'];
}

/** Sync active PocketBase pipelines (non-empty prompt_template) into Antfly `pipeline_refs` for semantic novelty. */
function formatforge_sync_pipeline_refs_to_antfly(?string $authHeader): void {
    if (!formatforge_antfly_novelty_configured() || !$authHeader) {
        return;
    }
    $r = pb_request('GET', '/api/collections/pipelines/records?perPage=200&sort=-%40rowid', null, $authHeader);
    if ($r['code'] !== 200) {
        return;
    }
    foreach ($r['body']['items'] ?? [] as $p) {
        if (!pipeline_record_is_active_for_feed($p)) {
            continue;
        }
        $t = trim((string)($p['prompt_template'] ?? ''));
        if ($t === '') {
            continue;
        }
        $id = trim((string)($p['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $bundle = $authHeader ? ff_pipeline_ref_antfly_backing_bundle_from_pb($p, $authHeader) : ['media_url' => '', 'mime' => '', 'title' => '', 'source_url' => ''];
        $doc = [
            'id' => $id,
            'prompt' => $t,
            'name' => trim((string)($p['name'] ?? '')),
            'title' => $bundle['title'] !== '' ? $bundle['title'] : trim((string)($p['name'] ?? '')),
            'source_url' => $bundle['source_url'],
            'mime' => $bundle['mime'],
            'media_url' => $bundle['media_url'],
        ];
        antfly_index('pipeline_refs', $doc);
    }
}

function embed_text(string $text): ?array {
    $cfg = $GLOBALS['CONFIG'];
    $text = trim($text);
    if ($text === '') {
        return null;
    }
    // Google AI Gemini embedContent (e.g. gemini-embedding-2-preview) — preferred when GEMINI_API_KEY is set
    if (trim((string)($cfg['gemini_api_key'] ?? '')) !== '') {
        $g = ff_gemini_embed_from_text($text);
        if ($g !== null) {
            return $g;
        }
    }
    // OpenRouter embeddings (e.g. google/gemini-embedding-001) — if key set
    if (!empty($cfg['openrouter_key'])) {
        $ch = curl_init('https://openrouter.ai/api/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $cfg['openrouter_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $cfg['embed_model'],
                'input' => $text,
                'encoding_format' => 'float',
            ]),
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $body = json_decode($res ?: '{}', true);
            if (!empty($body['error'])) {
                return null;
            }

            return $body['data'][0]['embedding'] ?? null;
        }
        return null;
    }
    // OpenAI direct
    if (!empty($cfg['openai_key'])) {
        $ch = curl_init('https://api.openai.com/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $cfg['openai_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode(['model' => 'text-embedding-3-small', 'input' => $text]),
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $body = json_decode($res ?: '{}', true);
            return $body['data'][0]['embedding'] ?? null;
        }
        return null;
    }
    // Ollama / local embed URL
    if ($cfg['embed_url']) {
        $url = rtrim($cfg['embed_url'], '/');
        $body = json_encode(['model' => 'nomic-embed-text', 'input' => [$text]]);
        $ch = curl_init($url . (str_contains($url, '/embed') ? '' : '/api/embed'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $res = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code === 200) {
            $out = json_decode($res ?: '{}', true);
            $emb = $out['embeddings'][0] ?? $out['data'][0]['embedding'] ?? null;
            return is_array($emb) ? $emb : null;
        }
        return null;
    }
    return null;
}

function ff_gemini_embed_model_id(): string {
    $m = trim((string)($GLOBALS['CONFIG']['gemini_embed_model'] ?? ''));

    return $m !== '' ? $m : 'gemini-embedding-2-preview';
}

/**
 * Gemini API embedContent (REST: same service as `google.genai` / AI Studio). Multimodal: text + inline_data bytes (image / video / audio).
 *
 * @param list<array{text?:string,inline_mime?:string,inline_bytes?:string}> $logicalParts
 * @return list<float>|null
 */
function ff_gemini_embed_from_logical_parts(array $logicalParts, ?string $taskType = 'SEMANTIC_SIMILARITY'): ?array {
    $key = trim((string)($GLOBALS['CONFIG']['gemini_api_key'] ?? ''));
    if ($key === '') {
        return null;
    }
    $bodyParts = [];
    foreach ($logicalParts as $p) {
        if (!is_array($p)) {
            continue;
        }
        if (isset($p['text']) && trim((string)$p['text']) !== '') {
            $bodyParts[] = ['text' => trim((string)$p['text'])];
        }
        if (!empty($p['inline_bytes']) && !empty($p['inline_mime'])) {
            $bodyParts[] = [
                'inline_data' => [
                    'mime_type' => (string)$p['inline_mime'],
                    'data' => base64_encode((string)$p['inline_bytes']),
                ],
            ];
        }
    }
    if ($bodyParts === []) {
        return null;
    }
    $model = ff_gemini_embed_model_id();
    $payload = [
        'model' => 'models/' . $model,
        'content' => ['parts' => $bodyParts],
    ];
    if ($taskType !== null && $taskType !== '') {
        $payload['taskType'] = $taskType;
    }
    $od = $GLOBALS['CONFIG']['gemini_embed_output_dimensionality'] ?? null;
    if ($od !== null && (int)$od > 0) {
        $payload['outputDimensionality'] = (int)$od;
    }
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($model) . ':embedContent';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $key,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 180,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        ff_debug_log('gemini_embed_failed', ['code' => $code, 'model' => $model]);

        return null;
    }
    $body = json_decode($res ?: '{}', true);
    $vals = $body['embedding']['values'] ?? null;

    return is_array($vals) ? $vals : null;
}

/** @return list<float>|null */
function ff_gemini_embed_from_text(string $text): ?array {
    $text = trim($text);
    if ($text === '') {
        return null;
    }

    return ff_gemini_embed_from_logical_parts([['text' => $text]]);
}

function ff_gemini_mime_for_alignment_url(string $mediaUrl, string $mediaKind): string {
    $u = strtolower($mediaUrl);
    $map = [
        '.png' => 'image/png', '.jpg' => 'image/jpeg', '.jpeg' => 'image/jpeg',
        '.webp' => 'image/webp', '.gif' => 'image/gif',
        '.mp4' => 'video/mp4', '.webm' => 'video/webm', '.mov' => 'video/quicktime',
        '.m4v' => 'video/x-m4v', '.mpeg' => 'video/mpeg', '.mpe' => 'video/mpeg', '.ogv' => 'video/ogg',
        '.mp3' => 'audio/mpeg', '.m4a' => 'audio/mp4', '.wav' => 'audio/wav',
    ];
    foreach ($map as $ext => $mime) {
        if (str_contains($u, $ext)) {
            return $mime;
        }
    }
    $mediaKind = strtolower(trim($mediaKind));
    if ($mediaKind === 'video') {
        return 'video/mp4';
    }
    if ($mediaKind === 'audio') {
        return 'audio/mpeg';
    }

    return 'image/jpeg';
}

/**
 * Download media from URL and embed via Gemini (inline_data), matching google.genai multimodal embed_content.
 *
 * @param 'image'|'video'|'audio' $mediaKind
 * @return list<float>|null
 */
function ff_gemini_alignment_multimodal(?string $text, ?string $mediaUrl, string $mediaKind = 'image'): ?array {
    $text = trim((string)$text);
    $mediaUrl = $mediaUrl !== null ? trim($mediaUrl) : '';
    if ($text === '' && $mediaUrl === '') {
        return null;
    }
    $parts = [];
    if ($text !== '') {
        $parts[] = ['text' => $text];
    }
    if ($mediaUrl !== '') {
        $maxB = (int)($GLOBALS['CONFIG']['gemini_embed_media_max_bytes'] ?? 26214400);
        $bytes = ff_http_get_bytes_capped($mediaUrl, 120, $maxB);
        if ($bytes === null || $bytes === '') {
            return null;
        }
        $mime = ff_gemini_mime_for_alignment_url($mediaUrl, $mediaKind);
        $parts[] = ['inline_mime' => $mime, 'inline_bytes' => $bytes];
    }

    return ff_gemini_embed_from_logical_parts($parts);
}

function cosine_distance(array $a, array $b): float {
    $n = min(count($a), count($b));
    if ($n === 0) return 1.0;
    $dot = $normA = $normB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    $denom = sqrt($normA) * sqrt($normB);
    if ($denom < 1e-10) return 1.0;
    $sim = $dot / $denom;
    return 1.0 - max(-1, min(1, $sim));
}

/**
 * Clamped cosine similarity in [-1,1] from two embedding vectors (same length).
 */
function ff_cosine_similarity_from_embeddings(array $a, array $b): ?float {
    $n = min(count($a), count($b));
    if ($n === 0) {
        return null;
    }
    $dot = $normA = $normB = 0.0;
    for ($i = 0; $i < $n; $i++) {
        $dot += $a[$i] * $b[$i];
        $normA += $a[$i] * $a[$i];
        $normB += $b[$i] * $b[$i];
    }
    $denom = sqrt($normA) * sqrt($normB);
    if ($denom < 1e-10) {
        return null;
    }

    return max(-1.0, min(1.0, $dot / $denom));
}

/**
 * @param string|array<string,mixed> $input OpenRouter embeddings `input` (string, or multimodal array-of-content-blocks).
 * @return list<float>|null
 */
function ff_openrouter_embeddings_request($input): ?array {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['openrouter_key'])) {
        return null;
    }
    $model = trim((string)($cfg['embed_model'] ?? '')) ?: 'google/gemini-embedding-001';
    $ch = curl_init('https://openrouter.ai/api/v1/embeddings');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $cfg['openrouter_key'],
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'model' => $model,
            'input' => $input,
            'encoding_format' => 'float',
        ]),
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code !== 200) {
        ff_debug_log('openrouter_embeddings_failed', ['code' => $code]);

        return null;
    }
    $body = json_decode($res ?: '{}', true);
    if (!empty($body['error'])) {
        ff_debug_log('openrouter_embeddings_failed', ['code' => $code, 'error' => $body['error']]);

        return null;
    }
    $emb = $body['data'][0]['embedding'] ?? null;

    return is_array($emb) ? $emb : null;
}

/**
 * Heuristic: treat URL path as video for OpenRouter `video_url` blocks (embeddings + gemini-embedding-001).
 */
function ff_alignment_guess_video_url_from_string(string $url): bool {
    $u = strtolower($url);
    foreach (['.mp4', '.webm', '.mov', '.m4v', '.mpeg', '.mpe', '.ogv'] as $ext) {
        if (str_contains($u, $ext)) {
            return true;
        }
    }
    if (str_contains($u, 'type=video') || str_contains($u, '/video/')) {
        return true;
    }

    return false;
}

/** @return 'image'|'video' */
function ff_alignment_media_kind_from_content_row(?array $row): string {
    if (!is_array($row) || $row === []) {
        return 'image';
    }
    $t = strtolower(trim((string)($row['type'] ?? '')));
    if (in_array($t, ['video', 'reel'], true)) {
        return 'video';
    }

    return 'image';
}

/**
 * One embedding via OpenRouter: plain string or multimodal (text + image_url or video_url) for google/gemini-embedding-001.
 *
 * @param 'image'|'video' $mediaKind which OpenRouter content part to use when $mediaUrl is non-empty
 * @return list<float>|null
 */
function ff_openrouter_embed_alignment_probe(?string $text, ?string $mediaUrl, string $mediaKind = 'image'): ?array {
    $text = trim((string)$text);
    $mediaUrl = $mediaUrl !== null ? trim($mediaUrl) : '';
    if ($text === '' && $mediaUrl === '') {
        return null;
    }
    if ($mediaUrl === '') {
        return ff_openrouter_embeddings_request($text);
    }
    $mediaKind = strtolower(trim($mediaKind)) === 'video' ? 'video' : 'image';
    $parts = [];
    if ($text !== '') {
        $parts[] = ['type' => 'text', 'text' => $text];
    }
    if ($mediaKind === 'video') {
        $parts[] = ['type' => 'video_url', 'video_url' => ['url' => $mediaUrl]];
    } else {
        $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $mediaUrl]];
    }

    return ff_openrouter_embeddings_request([['content' => $parts]]);
}

/**
 * @param array{input_text?: string, input_media_url?: string, input_media_kind?: string} $in
 */
function ff_alignment_embed_input_with_provider(array $in, string $provider): ?array {
    $provider = strtolower(trim($provider));
    $text = isset($in['input_text']) ? trim((string)$in['input_text']) : '';
    $url = isset($in['input_media_url']) ? trim((string)$in['input_media_url']) : '';
    $inKind = (($in['input_media_kind'] ?? 'image') === 'video') ? 'video' : 'image';

    if ($provider === 'gemini') {
        if (trim((string)($GLOBALS['CONFIG']['gemini_api_key'] ?? '')) === '') {
            return null;
        }
        $v = ff_gemini_alignment_multimodal($text !== '' ? $text : '', $url !== '' ? $url : null, $inKind);
        if ($v === null && $url !== '' && $inKind === 'video') {
            $v = ff_gemini_alignment_multimodal($text !== '' ? $text : '', $url, 'image');
        }
        if ($v === null && $text !== '') {
            return ff_gemini_embed_from_text($text);
        }

        return $v;
    }
    if ($provider === 'openrouter') {
        if (empty($GLOBALS['CONFIG']['openrouter_key'])) {
            return null;
        }
        $v = ff_openrouter_embed_alignment_probe($text, $url !== '' ? $url : null, $inKind);
        if ($v === null && $url !== '' && $inKind === 'video') {
            $v = ff_openrouter_embed_alignment_probe($text, $url, 'image');
        }
        if ($v === null && $text !== '') {
            return ff_openrouter_embeddings_request($text);
        }

        return $v;
    }

    return null;
}

/**
 * @return array{mode: string, vec: list<float>|null}
 */
function ff_alignment_output_embedding_for_row_provider(array $genRow, string $provider): array {
    $provider = strtolower(trim($provider));
    $url = ff_content_item_effective_media_url($genRow);
    $type = strtolower(trim((string)($genRow['type'] ?? '')));
    $shapeKind = ff_shape_kind_for_content_type($type);
    $t = trim((string)($genRow['title'] ?? ''));
    $p = trim((string)($genRow['prompt'] ?? ''));
    $text = formatforge_antfly_content_semantic_text($t, $p, trim($t . "\n" . $p));
    if ($text === '') {
        $text = trim($t . "\n" . $p);
    }

    if ($provider === 'gemini') {
        if (trim((string)($GLOBALS['CONFIG']['gemini_api_key'] ?? '')) === '') {
            return ['mode' => 'text_output', 'vec' => null];
        }
        if ($url !== '') {
            $outMediaKind = ($shapeKind === 'video' || ff_alignment_media_kind_from_content_row($genRow) === 'video') ? 'video' : 'image';
            if ($outMediaKind === 'image' && ff_alignment_guess_video_url_from_string($url)) {
                $outMediaKind = 'video';
            }
            $vec = ff_gemini_alignment_multimodal($text, $url, $outMediaKind);
            if ($vec !== null) {
                return [
                    'mode' => $outMediaKind === 'video' ? 'gemini_multimodal_video' : 'gemini_multimodal_image',
                    'vec' => $vec,
                ];
            }
            $altKind = $outMediaKind === 'video' ? 'image' : 'video';
            $vec = ff_gemini_alignment_multimodal($text, $url, $altKind);
            if ($vec !== null) {
                return ['mode' => 'gemini_multimodal_' . $altKind . '_fallback', 'vec' => $vec];
            }
        }
        if ($text === '') {
            return ['mode' => 'text_output', 'vec' => null];
        }
        $vec = ff_gemini_embed_from_text($text);

        return ['mode' => 'gemini_text', 'vec' => $vec];
    }

    if ($provider !== 'openrouter' || empty($GLOBALS['CONFIG']['openrouter_key'])) {
        return ['mode' => 'text_output', 'vec' => null];
    }
    if ($url !== '') {
        $outMediaKind = ($shapeKind === 'video' || ff_alignment_media_kind_from_content_row($genRow) === 'video') ? 'video' : 'image';
        if ($outMediaKind === 'image' && ff_alignment_guess_video_url_from_string($url)) {
            $outMediaKind = 'video';
        }
        $vec = ff_openrouter_embed_alignment_probe($text, $url, $outMediaKind);
        if ($vec !== null) {
            return [
                'mode' => $outMediaKind === 'video' ? 'openrouter_multimodal_video' : 'openrouter_multimodal_image',
                'vec' => $vec,
            ];
        }
        $altKind = $outMediaKind === 'video' ? 'image' : 'video';
        $vec = ff_openrouter_embed_alignment_probe($text, $url, $altKind);
        if ($vec !== null) {
            return ['mode' => 'openrouter_multimodal_' . $altKind . '_fallback', 'vec' => $vec];
        }
    }
    if ($text === '') {
        return ['mode' => 'text_output', 'vec' => null];
    }
    $vec = ff_openrouter_embeddings_request($text);

    return ['mode' => 'openrouter_text', 'vec' => $vec];
}

function ff_alignment_semantic_caption_from_content_row(array $row): string {
    $t = trim((string)($row['title'] ?? ''));
    $p = trim((string)($row['prompt'] ?? ''));

    return formatforge_antfly_content_semantic_text($t, $p, trim($t . "\n" . $p));
}

/**
 * @return array{input_media_url: string, input_text: string, input_media_kind: string}
 */
function ff_alignment_input_bundle_for_generated_row(array $genRow, string $authHeader): array {
    $meta = is_array($genRow['metadata'] ?? null) ? $genRow['metadata'] : [];
    $backingSlid = ff_resolve_backing_input_media_id($genRow, $authHeader);
    $inputMedia = ff_resolve_source_slot_media_url($genRow, $authHeader, $backingSlid);
    $inputText = '';
    $refRow = null;

    $srcItemId = trim((string)($meta['source_slide_item_id'] ?? ''));
    if ($srcItemId !== '') {
        $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($srcItemId), null, $authHeader);
        if ($gr['code'] === 200 && is_array($gr['body'] ?? null)) {
            $refRow = $gr['body'];
            $inputText = ff_alignment_semantic_caption_from_content_row($gr['body']);
        }
    }
    if ($inputText === '' && $backingSlid !== '') {
        $rows = ff_pb_content_items_for_source_link($authHeader, $backingSlid);
        if ($rows !== []) {
            $pick = 0;
            $shapeIdx = (int)($meta['source_shape_index'] ?? 0);
            if ($shapeIdx > 0 && $shapeIdx <= count($rows)) {
                $pick = $shapeIdx - 1;
            }
            $pickRow = is_array($rows[$pick] ?? null) ? $rows[$pick] : [];
            if ($pickRow !== []) {
                $refRow = $pickRow;
                $inputText = ff_alignment_semantic_caption_from_content_row($pickRow);
            }
        }
    }
    $ingId = trim((string)($meta['ingredient_id'] ?? ''));
    if (($inputMedia === '' || $inputText === '') && $ingId !== '') {
        $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($ingId), null, $authHeader);
        if ($gr['code'] === 200 && is_array($gr['body'] ?? null)) {
            $refRow = $gr['body'];
            if ($inputMedia === '') {
                $inputMedia = ff_content_item_effective_media_url($gr['body']);
            }
            if ($inputText === '') {
                $inputText = ff_alignment_semantic_caption_from_content_row($gr['body']);
            }
        }
    }
    if ($inputText === '' && $inputMedia === '') {
        $inputText = trim((string)($genRow['prompt'] ?? ''));
    }

    $inputKind = 'image';
    if ($refRow !== null) {
        $inputKind = ff_alignment_media_kind_from_content_row($refRow);
    } elseif ($inputMedia !== '' && ff_alignment_guess_video_url_from_string($inputMedia)) {
        $inputKind = 'video';
    }

    return ['input_media_url' => $inputMedia, 'input_text' => $inputText, 'input_media_kind' => $inputKind];
}

function ff_pipeline_agent_escape_md_cell(?string $s): string {
    $s = str_replace(["\r\n", "\n", "\r"], ' ', (string)$s);
    $s = str_replace('|', '\\|', $s);

    return trim($s);
}

/**
 * Resolve backing reference for a pipeline-generated content_items row (slide / ingredient / slot order).
 *
 * @return array{row: ?array, media_url: string, source_shape_index: int}
 */
function ff_pipeline_agent_resolve_input_reference_row(array $genRow, string $authHeader): array {
    $meta = is_array($genRow['metadata'] ?? null) ? $genRow['metadata'] : [];
    $backingSlid = ff_resolve_backing_input_media_id($genRow, $authHeader);
    $mediaUrl = ff_resolve_source_slot_media_url($genRow, $authHeader, $backingSlid);
    $refRow = null;
    $shapeIdx = (int)($meta['source_shape_index'] ?? 0);

    $srcItemId = trim((string)($meta['source_slide_item_id'] ?? ''));
    if ($srcItemId !== '') {
        $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($srcItemId), null, $authHeader);
        if ($gr['code'] === 200 && is_array($gr['body'] ?? null)) {
            $refRow = $gr['body'];
            if ($mediaUrl === '') {
                $mediaUrl = ff_content_item_prefer_garage_public_media_url($refRow);
            }
        }
    }
    if ($refRow === null && $backingSlid !== '') {
        $rows = ff_pb_content_items_for_source_link($authHeader, $backingSlid);
        if ($rows !== []) {
            $pick = 0;
            if ($shapeIdx > 0 && $shapeIdx <= count($rows)) {
                $pick = $shapeIdx - 1;
            }
            $pickRow = is_array($rows[$pick] ?? null) ? $rows[$pick] : null;
            if ($pickRow !== null) {
                $refRow = $pickRow;
                if ($mediaUrl === '') {
                    $mediaUrl = ff_content_item_prefer_garage_public_media_url($pickRow);
                }
            }
        }
    }
    $ingId = trim((string)($meta['ingredient_id'] ?? ''));
    if ($refRow === null && $ingId !== '') {
        $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($ingId), null, $authHeader);
        if ($gr['code'] === 200 && is_array($gr['body'] ?? null)) {
            $refRow = $gr['body'];
            if ($mediaUrl === '') {
                $mediaUrl = ff_content_item_prefer_garage_public_media_url($refRow);
            }
        }
    }

    return ['row' => $refRow, 'media_url' => $mediaUrl, 'source_shape_index' => $shapeIdx];
}

/**
 * Stable snapshot for JSON context (pipeline agent can scan keys reliably).
 *
 * @return array{role: string, content_item_id: ?string, type: ?string, title: ?string, prompt_or_source_url: ?string, media_url: ?string, garage_key?: string, pocketbase_files_url?: string, input_media_id: ?string, source_shape_index?: int|null, carousel_slot_index?: int, carousel_slot_count?: int}
 */
function ff_pipeline_agent_content_snapshot_from_row(?array $row, string $role): array {
    if ($row === null || $row === []) {
        return [
            'role' => $role,
            'content_item_id' => null,
            'type' => null,
            'title' => null,
            'prompt_or_source_url' => null,
            'media_url' => null,
            'input_media_id' => null,
        ];
    }
    $m = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    $u = ff_content_item_prefer_garage_public_media_url($row);
    $pbFiles = ff_content_item_pocketbase_files_api_url($row);
    $gk = trim((string)($row['garage_key'] ?? ''));
    $out = [
        'role' => $role,
        'content_item_id' => trim((string)($row['id'] ?? '')) !== '' ? trim((string)$row['id']) : null,
        'type' => trim((string)($row['type'] ?? '')) !== '' ? trim((string)$row['type']) : null,
        'title' => trim((string)($row['title'] ?? '')) !== '' ? trim((string)$row['title']) : null,
        'prompt_or_source_url' => trim((string)($row['prompt'] ?? '')) !== '' ? trim((string)$row['prompt']) : null,
        'media_url' => $u !== '' ? $u : null,
        'input_media_id' => trim((string)($row['input_media_id'] ?? '')) !== '' ? trim((string)$row['input_media_id']) : null,
        'source_shape_index' => isset($m['source_shape_index']) ? (int)$m['source_shape_index'] : null,
    ];
    if ($gk !== '') {
        $out['garage_key'] = $gk;
    }
    if ($pbFiles !== '' && $pbFiles !== $u) {
        $out['pocketbase_files_url'] = $pbFiles;
    }

    return $out;
}

/**
 * Machine + markdown dual format: INPUT_REFERENCE vs OUTPUT_GENERATED for reject/edit triggers.
 *
 * @return array<string, mixed>
 */
function ff_pipeline_agent_side_by_side_v1_for_reject(array $itemBody, string $authHeader): array {
    $resolved = ff_pipeline_agent_resolve_input_reference_row($itemBody, $authHeader);
    $inSnap = ff_pipeline_agent_content_snapshot_from_row($resolved['row'], 'INPUT_REFERENCE_BACKING');
    if ($inSnap['media_url'] === null && $resolved['media_url'] !== '') {
        $inSnap['media_url'] = $resolved['media_url'];
    }
    if ($resolved['source_shape_index'] > 0) {
        $inSnap['resolved_source_shape_index'] = $resolved['source_shape_index'];
    }
    $outSnap = ff_pipeline_agent_content_snapshot_from_row($itemBody, 'OUTPUT_REJECTED_PIPELINE_ROW');

    $rows = [
        ['field' => 'content_item_id', 'INPUT_REFERENCE' => $inSnap['content_item_id'], 'OUTPUT_GENERATED' => $outSnap['content_item_id']],
        ['field' => 'type', 'INPUT_REFERENCE' => $inSnap['type'], 'OUTPUT_GENERATED' => $outSnap['type']],
        ['field' => 'title', 'INPUT_REFERENCE' => $inSnap['title'], 'OUTPUT_GENERATED' => $outSnap['title']],
        ['field' => 'media_url', 'INPUT_REFERENCE' => $inSnap['media_url'], 'OUTPUT_GENERATED' => $outSnap['media_url']],
        ['field' => 'prompt_or_source_url', 'INPUT_REFERENCE' => $inSnap['prompt_or_source_url'], 'OUTPUT_GENERATED' => $outSnap['prompt_or_source_url']],
        ['field' => 'input_media_id', 'INPUT_REFERENCE' => $inSnap['input_media_id'], 'OUTPUT_GENERATED' => $outSnap['input_media_id']],
    ];

    $md = "### pipeline_agent_side_by_side_v1 (reject) — use JSON `comparison_table_rows`; this table is the same data\n\n";
    $md .= "| field | INPUT_REFERENCE (backing / source slot) | OUTPUT_GENERATED (rejected row) |\n| --- | --- | --- |\n";
    foreach ($rows as $r) {
        $md .= '| ' . ff_pipeline_agent_escape_md_cell($r['field'])
            . ' | ' . ff_pipeline_agent_escape_md_cell($r['INPUT_REFERENCE'] === null ? '' : (string)$r['INPUT_REFERENCE'])
            . ' | ' . ff_pipeline_agent_escape_md_cell($r['OUTPUT_GENERATED'] === null ? '' : (string)$r['OUTPUT_GENERATED'])
            . " |\n";
    }
    $md .= "\n**Visual review (mandatory):** First open **`agent_media/reject_input_*`** and **`agent_media/reject_output_*`** in this pipeline workspace (see **`pipeline_agent_workspace_media_v1`**) and **look at both images/videos** before changing templates or code. Then cross-check: `media_url` is **public Garage** when `garage_key` is set (see `GARAGE_PUBLIC_URL` / bucket.web.* host) — curl or open in Cursor; use `pocketbase_files_url` only if Garage is absent (may require PocketBase auth). Compare subject, crop, overlays, typography, palette, and motion (if video).\n";

    return [
        'schema_version' => 1,
        'kind' => 'reject_input_vs_output',
        'instruction' => 'Parse `comparison_table_rows` (array of {field, INPUT_REFERENCE, OUTPUT_GENERATED}). **Mandatory:** open **`pipeline_agent_workspace_media_v1.files`** and visually inspect **`reject_input_*` (backing)** vs **`reject_output_*` (generated)** under `agent_media/` when `ok: true` — look at actual pixels/frames, not only this table. `media_url` prefers **public Garage** (`garage_key` → virtual-host URL); falls back to PocketBase /api/files when no Garage field. If workspace copies failed, use those URLs with curl or a viewer. INPUT_REFERENCE = backing; OUTPUT_GENERATED = rejected row.',
        'INPUT_REFERENCE' => $inSnap,
        'OUTPUT_GENERATED' => $outSnap,
        'comparison_table_rows' => $rows,
        'markdown_side_by_side' => $md,
        'review_checklist' => [
            'subject_and_scene_alignment',
            'framing_and_safe_zones_vs_composition_metrics_if_any',
            'text_overlay_readability_and_brand_voice',
            'color_lighting_mood_vs_backing',
            'carousel_slot_index_match_when_metadata_source_shape_index_set',
        ],
    ];
}

/**
 * Ordered catalog of fetched backing slots for create-pipeline triggers (no generated output yet).
 *
 * @return array<string, mixed>|null
 */
function ff_pipeline_agent_fetched_slots_catalog_v1(?string $authHeader, string $sourceLinkId): ?array {
    $sourceLinkId = trim($sourceLinkId);
    if ($sourceLinkId === '' || !$authHeader) {
        return null;
    }
    $items = ff_pb_content_items_for_source_link($authHeader, $sourceLinkId);
    if ($items === []) {
        return null;
    }
    $slots = [];
    $n = count($items);
    foreach ($items as $i => $it) {
        if (!is_array($it)) {
            continue;
        }
        $snap = ff_pipeline_agent_content_snapshot_from_row($it, 'FETCHED_BACKING_SLOT');
        $snap['carousel_slot_index'] = $i + 1;
        $snap['carousel_slot_count'] = $n;
        $slots[] = $snap;
    }
    $md = "### pipeline_agent_fetched_slots_catalog_v1 (create) — ordered backing media\n\n";
    $md .= "| slot | content_item_id | type | title | media_url |\n| --- | --- | --- | --- | --- |\n";
    foreach ($slots as $s) {
        $md .= '| ' . (string)($s['carousel_slot_index'] ?? '')
            . ' | ' . ff_pipeline_agent_escape_md_cell($s['content_item_id'] === null ? '' : (string)$s['content_item_id'])
            . ' | ' . ff_pipeline_agent_escape_md_cell((string)($s['type'] ?? ''))
            . ' | ' . ff_pipeline_agent_escape_md_cell((string)($s['title'] ?? ''))
            . ' | ' . ff_pipeline_agent_escape_md_cell((string)($s['media_url'] ?? ''))
            . " |\n";
    }

    return [
        'schema_version' => 1,
        'kind' => 'create_pipeline_backing_only',
        'backing_input_media_id' => $sourceLinkId,
        'slot_count' => count($slots),
        'slots' => $slots,
        'markdown_slots_table' => $md,
        'instruction' => 'Each `slots[]` entry is one fetched backing asset in carousel order. `media_url` prefers public Garage when `garage_key` is set. Design the pipeline so generated outputs map 1:1 to these slots when output_type is carousel. After the trigger runs, **`pipeline_agent_workspace_media_v1`** materializes `agent_media/source_slot_*` copies when possible — **open each `relative_path` and visually inspect** the backing (do not rely on this table text alone).',
    ];
}

/**
 * Max bytes for each file copied into pipelines/.../agent_media/ (override via PIPELINE_AGENT_MEDIA_MAX_BYTES).
 */
function ff_pipeline_agent_workspace_media_max_bytes(): int {
    $raw = trim((string) (getenv('PIPELINE_AGENT_MEDIA_MAX_BYTES') ?: ''));
    if ($raw !== '' && ctype_digit($raw)) {
        return max(1048576, min(524288000, (int) $raw));
    }
    return 104857600;
}

/**
 * Safe fragment for agent_media filenames (content id prefix, etc.).
 */
function ff_pipeline_agent_safe_filename_fragment(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
    if (strlen($s) > 20) {
        $s = substr($s, 0, 20);
    }
    return $s !== '' ? $s : 'asset';
}

/**
 * Guess extension from URL path or magic bytes.
 */
function ff_pipeline_agent_guess_media_ext(string $url, string $bytes): string {
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp4', 'webm', 'mov', 'm4v', 'mp3', 'm4a'], true)) {
        return $ext === 'jpeg' ? 'jpg' : $ext;
    }
    if (strlen($bytes) >= 12) {
        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return 'jpg';
        }
        if (str_starts_with($bytes, "\x89PNG\r\n\x1A\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, 'GIF8')) {
            return 'gif';
        }
        if (str_starts_with($bytes, 'RIFF') && strlen($bytes) > 12 && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        if (str_starts_with($bytes, "\x00\x00\x00") && strlen($bytes) > 12 && substr($bytes, 4, 4) === 'ftyp') {
            return 'mp4';
        }
    }

    return 'bin';
}

/**
 * HTTP GET for media bytes; optional PocketBase bearer for /api/files/ when rule requires auth.
 *
 * @return string|null
 */
function ff_pipeline_agent_media_fetch_url(string $url, ?string $pbToken, int $maxBytes, int $timeoutSec): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    $headers = ['Accept: */*'];
    if ($pbToken !== null && $pbToken !== '') {
        $headers[] = 'Authorization: Bearer ' . $pbToken;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => max(5, $timeoutSec),
        CURLOPT_CONNECTTIMEOUT => min(30, $timeoutSec),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_MAXFILESIZE => $maxBytes,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($errno !== 0 || !is_string($body) || $body === '') {
        return null;
    }
    if ($code < 200 || $code >= 300) {
        return null;
    }

    return $body;
}

/**
 * Try several URLs (Garage/public first, then PocketBase /api/files/ with superuser if needed).
 *
 * @param list<string> $urls
 * @return string|null
 */
function ff_pipeline_agent_try_fetch_media_bytes(array $urls, ?string $pbBearerToken, int $maxBytes, int $timeoutSec): ?string {
    $uniq = [];
    foreach ($urls as $u) {
        $u = trim((string) $u);
        if ($u !== '') {
            $uniq[$u] = true;
        }
    }
    $list = array_keys($uniq);
    foreach ($list as $u) {
        $b = ff_pipeline_agent_media_fetch_url($u, null, $maxBytes, $timeoutSec);
        if ($b !== null && $b !== '') {
            return $b;
        }
    }
    if ($pbBearerToken === null || $pbBearerToken === '') {
        return null;
    }
    foreach ($list as $u) {
        $path = (string) (parse_url($u, PHP_URL_PATH) ?? '');
        if (!str_contains($path, '/api/files/')) {
            continue;
        }
        $b = ff_pipeline_agent_media_fetch_url($u, $pbBearerToken, $maxBytes, $timeoutSec);
        if ($b !== null && $b !== '') {
            return $b;
        }
    }

    return null;
}

/**
 * Empty agent_media/ then download backing + generated assets from trigger context into the pipeline workspace
 * so Cursor --workspace can open binaries without relying on Garage or PocketBase URLs alone.
 *
 * @param array<string, mixed> $context
 */
function ff_pipeline_agent_materialize_workspace_media(string $pipelineDir, array &$context): void {
    $dir = rtrim($pipelineDir, '/\\') . DIRECTORY_SEPARATOR . 'agent_media';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $context['pipeline_agent_workspace_media_v1'] = [
            'schema_version' => 1,
            'ok' => false,
            'error' => 'could_not_create_agent_media_dir',
            'directory' => 'agent_media',
            'files' => [],
        ];
        ff_pipeline_trace_log('pipeline_agent_workspace_media_skip', ['reason' => 'mkdir_failed', 'dir' => $dir]);

        return;
    }
    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }

    $maxB = ff_pipeline_agent_workspace_media_max_bytes();
    $timeout = 120;
    $su = pb_superuser_auth_token();
    $pbTok = ($su['ok'] && !empty($su['token'])) ? (string) $su['token'] : null;

    /** @var array<string, string> $urlToRelative */
    $urlToRelative = [];
    /** @var list<array<string, mixed>> $filesOut */
    $filesOut = [];

    $commitBytes = function (string $role, string $basename, array $urls, array $extra) use ($dir, $pipelineDir, $maxB, $timeout, $pbTok, &$urlToRelative, &$filesOut): void {
        $candidates = [];
        foreach ($urls as $u) {
            $u = trim((string) $u);
            if ($u !== '') {
                $candidates[] = $u;
            }
        }
        $candidates = array_values(array_unique($candidates));
        if ($candidates === []) {
            $filesOut[] = array_merge(['role' => $role, 'ok' => false, 'error' => 'no_urls'], $extra);

            return;
        }
        $existingRel = null;
        foreach ($candidates as $u) {
            $k = strtolower($u);
            if (isset($urlToRelative[$k])) {
                $existingRel = $urlToRelative[$k];
                break;
            }
        }
        if ($existingRel !== null) {
            $filesOut[] = array_merge([
                'role' => $role,
                'ok' => true,
                'relative_path' => $existingRel,
                'deduped' => true,
            ], $extra);

            return;
        }
        $bytes = ff_pipeline_agent_try_fetch_media_bytes($candidates, $pbTok, $maxB, $timeout);
        if ($bytes === null || $bytes === '') {
            $filesOut[] = array_merge([
                'role' => $role,
                'ok' => false,
                'error' => 'fetch_failed',
                'urls_tried' => $candidates,
            ], $extra);

            return;
        }
        $ext = ff_pipeline_agent_guess_media_ext($candidates[0], $bytes);
        $safeBase = ff_pipeline_agent_safe_filename_fragment($basename);
        $rel = 'agent_media/' . $safeBase . '.' . $ext;
        $full = $pipelineDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (@file_put_contents($full, $bytes) === false) {
            $filesOut[] = array_merge([
                'role' => $role,
                'ok' => false,
                'error' => 'write_failed',
                'urls_tried' => $candidates,
            ], $extra);

            return;
        }
        foreach ($candidates as $u) {
            $urlToRelative[strtolower($u)] = $rel;
        }
        $filesOut[] = array_merge([
            'role' => $role,
            'ok' => true,
            'relative_path' => $rel,
            'bytes' => strlen($bytes),
            'urls_tried' => $candidates,
        ], $extra);
    };

    $primary = trim((string) ($context['primary_backing_media_url'] ?? ''));
    if ($primary !== '') {
        $commitBytes('primary_backing', 'primary_backing', [$primary], []);
    }

    $slotsCat = $context['pipeline_agent_fetched_slots_catalog_v1'] ?? null;
    if (is_array($slotsCat) && isset($slotsCat['slots']) && is_array($slotsCat['slots'])) {
        foreach ($slotsCat['slots'] as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $idx = (int) ($slot['carousel_slot_index'] ?? 0);
            $cid = trim((string) ($slot['content_item_id'] ?? ''));
            $urls = [
                (string) ($slot['media_url'] ?? ''),
                (string) ($slot['pocketbase_files_url'] ?? ''),
            ];
            $base = 'source_slot_' . str_pad((string) max(1, $idx), 2, '0', STR_PAD_LEFT) . '_' . ff_pipeline_agent_safe_filename_fragment($cid);
            $commitBytes('fetched_backing_slot', $base, $urls, [
                'carousel_slot_index' => $idx,
                'content_item_id' => $cid !== '' ? $cid : null,
            ]);
        }
    }

    $sbs = $context['pipeline_agent_side_by_side_v1'] ?? null;
    if (is_array($sbs)) {
        $in = $sbs['INPUT_REFERENCE'] ?? null;
        if (is_array($in)) {
            $cid = trim((string) ($in['content_item_id'] ?? 'input'));
            $urls = [(string) ($in['media_url'] ?? ''), (string) ($in['pocketbase_files_url'] ?? '')];
            $commitBytes('reject_input_reference', 'reject_input_' . ff_pipeline_agent_safe_filename_fragment($cid), $urls, [
                'side' => 'INPUT_REFERENCE',
                'content_item_id' => $cid !== '' ? $cid : null,
            ]);
        }
        $out = $sbs['OUTPUT_GENERATED'] ?? null;
        if (is_array($out)) {
            $cid = trim((string) ($out['content_item_id'] ?? 'output'));
            $urls = [(string) ($out['media_url'] ?? ''), (string) ($out['pocketbase_files_url'] ?? '')];
            $commitBytes('reject_output_generated', 'reject_output_' . ff_pipeline_agent_safe_filename_fragment($cid), $urls, [
                'side' => 'OUTPUT_GENERATED',
                'content_item_id' => $cid !== '' ? $cid : null,
            ]);
        }
    }

    $okCount = 0;
    foreach ($filesOut as $f) {
        if (!empty($f['ok'])) {
            $okCount++;
        }
    }
    $context['pipeline_agent_workspace_media_v1'] = [
        'schema_version' => 1,
        'ok' => $okCount > 0 || $filesOut === [],
        'directory' => 'agent_media',
        'instruction' => '**Mandatory:** For every `files[]` row with `ok: true`, open `relative_path` in this workspace and **visually inspect** the media (images: look at layout/palette/type; video: watch or skim frames). Use `role` / `side` to map to reject vs create context. If `ok: false`, use `urls_tried` or the JSON tables — do not skip visual review. Copies are from trigger time; remote URLs are for fallback/cross-check.',
        'max_bytes_per_file' => $maxB,
        'files' => $filesOut,
    ];
    ff_pipeline_trace_log('pipeline_agent_workspace_media', [
        'file_count' => count($filesOut),
        'ok_count' => $okCount,
        'pipeline_subdir' => basename($pipelineDir),
    ]);
}

function ff_find_previous_pipeline_alignment_distance(string $pipelineId, array $genMeta, string $excludeItemId, string $authHeader): ?float {
    $pipelineId = trim($pipelineId);
    if ($pipelineId === '') {
        return null;
    }
    $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $pipelineId);
    $qs = http_build_query([
        'filter' => 'metadata.pipeline_id = "' . $esc . '"',
        'sort' => '-updated',
        'perPage' => 80,
    ]);
    $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
    if ($r['code'] !== 200) {
        return null;
    }
    $wantIdx = isset($genMeta['source_shape_index']) ? (int)$genMeta['source_shape_index'] : 0;
    $wantIng = trim((string)($genMeta['ingredient_id'] ?? ''));

    foreach ($r['body']['items'] ?? [] as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = trim((string)($it['id'] ?? ''));
        if ($id === '' || $id === $excludeItemId) {
            continue;
        }
        if (!ff_content_item_is_pipeline_generated_snapshot($it)) {
            continue;
        }
        $m = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
        $prevD = $m['input_alignment']['cosine_distance'] ?? null;
        if ($prevD === null || !is_numeric($prevD)) {
            continue;
        }
        if ($wantIdx > 0) {
            $oIdx = (int)($m['source_shape_index'] ?? $m['ingredient_index'] ?? 0);
            if ($oIdx !== $wantIdx) {
                continue;
            }
        }
        if ($wantIng !== '') {
            $oIng = trim((string)($m['ingredient_id'] ?? ''));
            if ($oIng !== '' && $oIng !== $wantIng) {
                continue;
            }
        }

        return (float)$prevD;
    }

    return null;
}

/**
 * Embed fetched reference vs generated output (Gemini embedContent when GEMINI_API_KEY is set, else OpenRouter); store cosine distance on metadata.input_alignment.
 *
 * @return array{ok: bool, skipped?: string, alignment?: array<string, mixed>, error?: string}
 */
function ff_measure_generation_input_alignment(string $itemId, string $authHeader, bool $force = false): array {
    $cfg = $GLOBALS['CONFIG'];
    $gemKey = trim((string)($cfg['gemini_api_key'] ?? ''));
    $providers = [];
    if ($gemKey !== '') {
        $providers[] = 'gemini';
    }
    if (!empty($cfg['openrouter_key'])) {
        $providers[] = 'openrouter';
    }
    if ($providers === []) {
        return ['ok' => false, 'skipped' => 'no_embedding_provider'];
    }
    if (empty($cfg['generation_alignment_enabled'])) {
        return ['ok' => false, 'skipped' => 'disabled'];
    }
    $itemId = trim($itemId);
    if ($itemId === '') {
        return ['ok' => false, 'skipped' => 'bad_id'];
    }
    $rec = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($itemId), null, $authHeader);
    if ($rec['code'] !== 200 || !is_array($rec['body'] ?? null)) {
        return ['ok' => false, 'skipped' => 'not_found'];
    }
    $row = $rec['body'];
    if (!ff_content_item_is_pipeline_generated_snapshot($row)) {
        return ['ok' => false, 'skipped' => 'not_pipeline_generated'];
    }
    $st = strtolower(trim((string)($row['status'] ?? '')));
    if (!in_array($st, ['pending', 'approved', 'rejected'], true)) {
        return ['ok' => false, 'skipped' => 'status_not_ready'];
    }
    $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    if (!$force && isset($meta['input_alignment']['cosine_distance']) && is_numeric($meta['input_alignment']['cosine_distance'])) {
        return ['ok' => true, 'skipped' => 'already_measured', 'alignment' => $meta['input_alignment']];
    }
    $outUrl = ff_content_item_effective_media_url($row);
    $outShape = ff_shape_kind_for_content_type((string)($row['type'] ?? ''));
    if ($outUrl === '' && $outShape === 'image') {
        return ['ok' => false, 'skipped' => 'no_output_media_url'];
    }

    $in = ff_alignment_input_bundle_for_generated_row($row, $authHeader);
    if ($in['input_text'] === '' && $in['input_media_url'] === '') {
        return ['ok' => false, 'skipped' => 'no_input_reference'];
    }

    $inVec = null;
    $outVec = null;
    $outPack = ['mode' => 'text_output', 'vec' => null];
    $usedProvider = '';
    foreach ($providers as $prov) {
        $iv = ff_alignment_embed_input_with_provider($in, $prov);
        if ($iv === null) {
            continue;
        }
        $op = ff_alignment_output_embedding_for_row_provider($row, $prov);
        if ($op['vec'] === null) {
            continue;
        }
        $inVec = $iv;
        $outVec = $op['vec'];
        $outPack = $op;
        $usedProvider = $prov;
        break;
    }
    if ($inVec === null || $outVec === null) {
        return ['ok' => false, 'error' => 'embedding_failed'];
    }

    $dist = cosine_distance($inVec, $outVec);
    $cosSim = ff_cosine_similarity_from_embeddings($inVec, $outVec);
    if ($cosSim === null) {
        $cosSim = 1.0 - $dist;
    }

    $pid = trim((string)($meta['pipeline_id'] ?? ''));
    $prev = $pid !== '' ? ff_find_previous_pipeline_alignment_distance($pid, $meta, $itemId, $authHeader) : null;
    $improving = $prev !== null ? ($dist < $prev) : null;

    $modelLabel = $usedProvider === 'gemini'
        ? ff_gemini_embed_model_id()
        : (trim((string)($cfg['embed_model'] ?? '')) ?: 'google/gemini-embedding-001');
    $inputProbeStr = 'text_only';
    if (trim((string)($in['input_media_url'] ?? '')) !== '') {
        $isVidIn = (($in['input_media_kind'] ?? 'image') === 'video');
        if ($usedProvider === 'gemini') {
            $inputProbeStr = $isVidIn ? 'text_plus_input_video_inline' : 'text_plus_input_image_inline';
        } else {
            $inputProbeStr = $isVidIn ? 'text_plus_input_video_url' : 'text_plus_input_image_url';
        }
    }
    $block = [
        'version' => 1,
        'measured_at' => date('c'),
        'embedding_provider' => $usedProvider,
        'embed_model' => $modelLabel,
        'cosine_distance' => $dist,
        'cosine_similarity' => $cosSim,
        'input_probe' => $inputProbeStr,
        'output_probe' => $outPack['mode'],
        'previous_same_pipeline_cosine_distance' => $prev,
        'improving_vs_previous_generation' => $improving,
        'note' => 'cosine_distance is 1 minus cosine similarity of embedding vectors from a single provider for both sides (lower = closer to input reference). Gemini: Google AI embedContent with inline_data (image/video/audio bytes) per GEMINI_EMBED_MODEL (default gemini-embedding-2-preview). OpenRouter: image_url/video_url multimodal. improving_vs_previous_generation is only meaningful vs prior rows that used the same embedding_provider/model space.',
    ];
    $meta['input_alignment'] = $block;
    pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($itemId), ['metadata' => $meta], $authHeader);
    ff_pipeline_trace_log('generation_input_alignment_measured', [
        'content_item_id' => $itemId,
        'pipeline_id' => $pid !== '' ? $pid : null,
        'cosine_distance' => $dist,
        'improving_vs_previous' => $improving,
    ]);
    if ($improving === true) {
        ff_pipeline_trace_log('generation_input_alignment_improving', [
            'content_item_id' => $itemId,
            'pipeline_id' => $pid !== '' ? $pid : null,
            'previous_cosine_distance' => $prev,
            'current_cosine_distance' => $dist,
        ]);
    } elseif ($improving === false && $prev !== null) {
        ff_pipeline_trace_log('generation_input_alignment_regressed', [
            'content_item_id' => $itemId,
            'pipeline_id' => $pid !== '' ? $pid : null,
            'previous_cosine_distance' => $prev,
            'current_cosine_distance' => $dist,
        ]);
    }

    return ['ok' => true, 'alignment' => $block];
}

/**
 * @return array{ok: bool, processed: int, measured: int, errors: list<string>}
 */
function ff_sweep_generation_input_alignment(string $authHeader, int $limit, bool $force): array {
    $out = ['ok' => true, 'processed' => 0, 'measured' => 0, 'errors' => []];
    $limit = max(1, min(200, $limit));
    $page = 1;
    $perPage = min(80, max($limit, 20));
    while ($out['measured'] < $limit && $page <= 10) {
        $qs = http_build_query([
            'filter' => 'status = "pending"',
            'sort' => '-updated',
            'perPage' => $perPage,
            'page' => $page,
        ]);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            $out['ok'] = false;
            $out['errors'][] = (string)($r['body']['message'] ?? 'list_failed');
            break;
        }
        $items = $r['body']['items'] ?? [];
        if ($items === []) {
            break;
        }
        foreach ($items as $it) {
            if (!is_array($it)) {
                continue;
            }
            $m = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
            if (trim((string)($m['pipeline_id'] ?? '')) === '') {
                continue;
            }
            $id = trim((string)($it['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $out['processed']++;
            $mr = ff_measure_generation_input_alignment($id, $authHeader, $force);
            if (!empty($mr['ok']) && ($mr['skipped'] ?? '') === '' && isset($mr['alignment'])) {
                $out['measured']++;
            } elseif (($mr['error'] ?? '') !== '') {
                $out['errors'][] = $id . ':' . $mr['error'];
            }
            if ($out['measured'] >= $limit) {
                break 2;
            }
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }

    return $out;
}

/** Legacy semantic novelty hook is removed. */
function formatforge_antfly_novelty_configured(): bool {
    return false;
}

/** Instagram-bound pipelines: active unless `is_active` is explicitly false (missing/null treated as active). */
function pipeline_record_is_active_for_feed(array $p): bool {
    return ($p['is_active'] ?? true) !== false;
}

/**
 * Count active pipeline rows (Instagram-bound). Novelty vs templates is done in Antfly after syncing `pipeline_refs`.
 */
function fetch_active_pipeline_row_count(?string $authHeader): int {
    $r = pb_request('GET', '/api/collections/pipelines/records?perPage=200&sort=-%40rowid', null, $authHeader);
    if ($r['code'] !== 200) {
        return 0;
    }
    $n = 0;
    foreach ($r['body']['items'] ?? [] as $p) {
        if (pipeline_record_is_active_for_feed($p)) {
            $n++;
        }
    }
    return $n;
}

/**
 * True when fetched content’s semantic blob (text ± optional backing image URL) is far from all synced pipeline templates (Antfly query).
 * Indexed `content` and `pipeline_refs` share the same multimodal template (`remoteMedia` + text) when `media_url` is set.
 */
function fetched_text_is_novel_vs_pipelines(string $semanticTextBlob, int $activePipelineRowCount, ?string $primaryImageMediaUrl = null): bool {
    $semanticTextBlob = trim($semanticTextBlob);
    $img = $primaryImageMediaUrl !== null ? trim($primaryImageMediaUrl) : '';
    if (($semanticTextBlob === '' && $img === '') || !formatforge_antfly_novelty_configured()) {
        return false;
    }
    if ($activePipelineRowCount === 0) {
        return true;
    }
    $maxD = (float)($GLOBALS['CONFIG']['novel_threshold'] ?? 0.35);

    return !antfly_any_pipeline_within_semantic_distance($semanticTextBlob, $maxD, $img !== '' ? $img : null);
}

/**
 * Best semantic match in Antfly `pipeline_refs` for a probe (text ± image URL; same index as novelty checks).
 */
function antfly_closest_pipeline_ref(string $textBlob, ?string $imageMediaUrl = null): ?array {
    $textBlob = trim($textBlob);
    $img = $imageMediaUrl !== null ? trim($imageMediaUrl) : '';
    if (($textBlob === '' && $img === '') || !formatforge_antfly_novelty_configured()) {
        return null;
    }
    $payload = antfly_semantic_search_payload($textBlob, $img !== '' ? $img : null);
    $res = antfly_pipeline_refs_semantic_query($payload, 3, null, 90);
    $queryMode = is_array($payload) ? 'multimodal_text_plus_image_url' : 'text_only';
    if (($res['code'] < 200 || $res['code'] >= 300) && is_array($payload) && $textBlob !== '') {
        $res = antfly_pipeline_refs_semantic_query($textBlob, 3, null, 90);
        $queryMode = 'text_only_fallback';
    }
    if ($res['code'] < 200 || $res['code'] >= 300) {
        return null;
    }
    $hits = antfly_pipeline_refs_extract_hits($res['body']);
    if ($hits === []) {
        return ['note' => 'No hits in pipeline_refs semantic index (templates may not be synced yet).', 'query_mode' => $queryMode];
    }
    $top = $hits[0];
    if (!is_array($top)) {
        return null;
    }
    $src = [];
    if (isset($top['_source']) && is_array($top['_source'])) {
        $src = $top['_source'];
    } elseif (isset($top['source']) && is_array($top['source'])) {
        $src = $top['source'];
    }
    $prompt = isset($src['prompt']) ? (string) $src['prompt'] : '';

    return [
        'pipeline_pb_id' => $src['id'] ?? $top['_id'] ?? null,
        'name' => $src['name'] ?? null,
        'prompt_template_excerpt' => $prompt !== '' ? (strlen($prompt) > 220 ? substr($prompt, 0, 220) . '…' : $prompt) : null,
        'hit_score' => $top['_score'] ?? $top['score'] ?? null,
        'sort' => $top['sort'] ?? null,
        'query_mode' => $queryMode,
    ];
}

function ff_content_item_is_fetched_for_snapshot(array $it): bool {
    $m = $it['metadata'] ?? [];
    if (!is_array($m)) {
        $m = [];
    }
    if (($m['origin'] ?? '') === 'fetch') {
        return true;
    }
    if (($m['pipeline_id'] ?? '') !== '' || ($m['origin'] ?? '') === 'generate') {
        return false;
    }
    // Legacy fetch rows may lack origin=fetch but carry source_url; do not treat “same input_media_id, no pipeline_id”
    // as fetched — that mis-classifies pipeline outputs missing metadata and inflates carousel shape counts.
    if (!empty($m['source_url']) && empty($m['pipeline_id'])) {
        return true;
    }

    return false;
}

function ff_content_item_is_pipeline_generated_snapshot(array $it): bool {
    $m = $it['metadata'] ?? [];
    if (!is_array($m)) {
        return false;
    }
    return ($m['pipeline_id'] ?? '') !== '' || ($m['origin'] ?? '') === 'generate';
}

/**
 * PocketBase operating snapshot for Cursor agent prompts (cadence, pipelines, metrics samples).
 */
function ff_cursor_agent_operating_context(?string $authHeader): array {
    if (!$authHeader || trim($authHeader) === '') {
        return [];
    }
    $cfg = $GLOBALS['CONFIG'];
    $target = (int) ($cfg['target_posts_per_day'] ?? 60);
    $noveltyMeaning = formatforge_antfly_novelty_configured()
        ? 'Fetched copy is treated as novel vs existing pipelines when it is NOT within novel_distance_threshold (cosine) of any active pipeline template in Antfly pipeline_refs.'
        : 'Semantic novelty checks are disabled (ANTFLY_ENABLED is off). Novel pipeline creation should rely on manual curation and visual review context.';
    $out = [
        'novel_distance_threshold' => (float) ($cfg['novel_threshold'] ?? 0.35),
        'novelty_meaning' => $noveltyMeaning,
        'metrics_note' => 'Run `php index.php sync-instagram-insights` (cron) or POST action=sync_instagram_insights after publish. Requires Instagram insights permissions on the connected token; metrics can lag up to ~48h per Meta.',
        'generation_alignment_note' => 'Pipeline-generated `content_items` may include `metadata.input_alignment`: cosine_distance between backing reference and output using one provider for both vectors — **Gemini** (`GEMINI_API_KEY`, `GEMINI_EMBED_MODEL` default gemini-embedding-2-preview) via Google AI `embedContent` + inline_data bytes, or **OpenRouter** (`OPENROUTER_API_KEY` / `EMBED_MODEL`) via image_url/video_url. Lower is closer. improving_vs_previous_generation compares to the prior measured row for the same pipeline_id (and slot index when set). Cron: `php index.php sweep-generation-alignment` or POST action=sweep_generation_alignment.',
    ];
    if ($target > 0) {
        $out['target_posts_per_day'] = $target;
        $out['cadence_note'] = "Product goal: about {$target} publishes per day across the active account set — tune pipeline templates, prompts, and cron so generation + review can sustain that without quality collapse.";
    }
    $out['active_pipelines_count'] = fetch_active_pipeline_row_count($authHeader);

    $pr = pb_request('GET', '/api/collections/pipelines/records?perPage=5&sort=-%40rowid', null, $authHeader);
    $pipelines = [];
    if ($pr['code'] === 200) {
        foreach ($pr['body']['items'] ?? [] as $p) {
            if (!is_array($p)) {
                continue;
            }
            $tmpl = trim((string) ($p['prompt_template'] ?? ''));
            $pipelines[] = [
                'id' => $p['id'] ?? '',
                'name' => $p['name'] ?? '',
                'updated' => $p['updated'] ?? '',
                'is_active' => $p['is_active'] ?? null,
                'prompt_template_excerpt' => $tmpl !== '' ? (strlen($tmpl) > 240 ? substr($tmpl, 0, 240) . '…' : $tmpl) : '',
            ];
        }
    }
    $out['pipelines_most_recent'] = $pipelines;

    $since = date('c', time() - 86400);
    $sinceEsc = str_replace(['\\', '"'], ['\\\\', '\\"'], $since);
    $filter24 = 'published_at > "' . $sinceEsc . '" && status = "published"';
    $qs24 = http_build_query(['filter' => $filter24, 'perPage' => 500]);
    $pub = pb_request('GET', '/api/collections/output_media/records?' . $qs24, null, $authHeader);
    $out['published_count_last_24h'] = ($pub['code'] === 200) ? count($pub['body']['items'] ?? []) : null;
    if ($target > 0 && $out['published_count_last_24h'] !== null) {
        $out['gap_to_target_last_24h'] = max(0, $target - (int) $out['published_count_last_24h']);
    }

    $qsPub = http_build_query(['filter' => 'status = "published"', 'sort' => '-published_at', 'perPage' => 12]);
    $list = pb_request('GET', '/api/collections/output_media/records?' . $qsPub, null, $authHeader);
    $items = ($list['code'] === 200) ? ($list['body']['items'] ?? []) : [];
    $summ = [];
    foreach (array_slice($items, 0, 10) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (string) ($it['id'] ?? '');
        $meta = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
        $summ[] = [
            'output_media_id' => $id,
            'title' => $it['title'] ?? '',
            'type' => $it['type'] ?? '',
            'published_at' => $it['published_at'] ?? '',
            'pipeline_id' => $meta['pipeline_id'] ?? null,
            'origin' => $meta['origin'] ?? null,
        ];
    }
    $out['recent_published'] = $summ;

    $metricsById = [];
    foreach (array_slice($items, 0, 10) as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = (string) ($it['id'] ?? '');
        if ($id === '') {
            continue;
        }
        $m = is_array($it['metrics'] ?? null) ? $it['metrics'] : [];
        if ($m !== []) {
            $metricsById[$id] = array_intersect_key($m, array_flip(['impressions', 'likes', 'views', 'shares', 'comments', 'fetched_at', 'instagram_media_id']));
        }
    }
    $out['metrics_by_output_media_id'] = $metricsById;

    $qAll = http_build_query(['sort' => '-@rowid', 'perPage' => 48]);
    $all = pb_request('GET', '/api/collections/output_media/records?' . $qAll, null, $authHeader);
    $fetched = [];
    $generated = [];
    if ($all['code'] === 200) {
        foreach ($all['body']['items'] ?? [] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $row = ['id' => $it['id'] ?? '', 'title' => $it['title'] ?? '', 'type' => $it['type'] ?? '', 'status' => $it['status'] ?? ''];
            if (ff_content_item_is_fetched_for_snapshot($it) && count($fetched) < 8) {
                $fetched[] = $row;
            } elseif (ff_content_item_is_pipeline_generated_snapshot($it) && count($generated) < 8) {
                $generated[] = $row;
            }
            if (count($fetched) >= 8 && count($generated) >= 8) {
                break;
            }
        }
    }
    $out['recent_fetched_items_sample'] = $fetched;
    $out['recent_pipeline_generated_items_sample'] = $generated;

    return $out;
}

/**
 * Statuses that sit “above” a reject in the list but are not a curate decision — skip when counting streaks.
 */
function ff_status_skipped_for_reject_streak(string $status): bool {
    return in_array($status, ['generating', 'failed'], true);
}

/**
 * Sort pipeline-scoped rows by recency of change (reject bumps `updated` to now; beats older generating rows by @rowid).
 *
 * @param array<int, array<string, mixed>> $items
 * @return array<int, array<string, mixed>>
 */
function ff_sort_content_items_by_updated_desc(array $items): array {
    usort($items, function ($a, $b) {
        $ua = (string)($a['updated'] ?? $a['created'] ?? '');
        $ub = (string)($b['updated'] ?? $b['created'] ?? '');
        $c = strcmp($ub, $ua);
        if ($c !== 0) {
            return $c;
        }
        return strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
    });
    return $items;
}

/**
 * Rejects for this pipeline only: sort by **updated** (not @rowid), skip in-flight rows, then count leading rejected.
 *
 * Using only `-@rowid` broke streaks whenever a **newer** row existed (e.g. `generating`) — the list led with a
 * non-rejected row and returned 0, so `maybe_trigger_cursor_edit_pipeline_after_reject` never fired.
 */
function count_consecutive_rejects_for_pipeline(string $pipelineId, ?string $authHeader): int {
    $pipelineId = trim($pipelineId);
    if ($pipelineId === '' || !$authHeader) return 0;
    $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $pipelineId);
    $filter = 'metadata.pipeline_id = "' . $escaped . '"';
    $qs = http_build_query(['filter' => $filter, 'perPage' => 120]);
    $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
    $items = ($r['code'] === 200) ? ($r['body']['items'] ?? []) : [];
    if ($items === []) {
        $qs2 = http_build_query(['sort' => '-@rowid', 'perPage' => 200]);
        $r2 = pb_request('GET', '/api/collections/output_media/records?' . $qs2, null, $authHeader);
        if ($r2['code'] !== 200) return 0;
        foreach ($r2['body']['items'] ?? [] as $it) {
            $m = $it['metadata'] ?? [];
            if (is_array($m) && trim((string)($m['pipeline_id'] ?? '')) === $pipelineId) {
                $items[] = $it;
            }
        }
    }
    $items = ff_sort_content_items_by_updated_desc($items);
    $i = 0;
    foreach ($items as $it) {
        $st = (string)($it['status'] ?? '');
        if (ff_status_skipped_for_reject_streak($st)) {
            $i++;
            continue;
        }
        break;
    }
    $items = array_slice($items, $i);
    $n = 0;
    foreach ($items as $it) {
        if (($it['status'] ?? '') === 'rejected') {
            $n++;
        } else {
            break;
        }
    }
    return $n;
}

/**
 * After Antfly indexing: if fetched text is novel vs existing pipeline templates (or there are none), spawn Cursor to create a pipeline.
 */
function maybe_trigger_cursor_create_pipeline_after_fetch(
    array $novelIndexPrompts,
    ?string $authHeader,
    string $sourceLinkId = '',
    string $sourceUrl = '',
    int $fetchedContentItemsCount = 0,
    string $instagramAccountId = '',
    array $fetchedShapeSignature = []
): void {
    $cfg = $GLOBALS['CONFIG'];
    $primaryBackingImageUrl = '';
    if (empty($cfg['cursor_pipeline_trigger_dir'])) {
        ff_pipeline_trace_log('create_pipeline_after_fetch_skip', ['reason' => 'empty_trigger_dir_config']);
        return;
    }
    if ($novelIndexPrompts === []) {
        ff_pipeline_trace_log('create_pipeline_after_fetch_skip', ['reason' => 'no_novel_semantic_blobs']);
        return;
    }
    if (!formatforge_antfly_novelty_configured()) {
        ff_pipeline_trace_log('create_pipeline_after_fetch_skip', ['reason' => 'antfly_url_not_configured']);
        return;
    }
    $novelIndexPrompts = array_values(array_unique(array_filter(array_map('trim', $novelIndexPrompts))));
    if ($novelIndexPrompts === []) {
        ff_pipeline_trace_log('create_pipeline_after_fetch_skip', ['reason' => 'novel_prompts_empty_after_dedupe']);
        return;
    }
    $sourceLinkId = trim($sourceLinkId);
    $sourceUrl = trim($sourceUrl);
    if ($sourceLinkId !== '' && $authHeader) {
        $slr = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($sourceLinkId), null, $authHeader);
        $sl = ($slr['code'] === 200 && is_array($slr['body'] ?? null)) ? $slr['body'] : null;
        if ($sl !== null) {
            $slMeta = is_array($sl['metadata'] ?? null) ? $sl['metadata'] : [];
            if (trim((string)($slMeta['pipeline_create_triggered_at'] ?? '')) !== '') {
                ff_pipeline_trace_log('create_pipeline_after_fetch_skip', [
                    'reason' => 'already_triggered_for_source_link',
                    'input_media_id' => $sourceLinkId,
                ]);
                return;
            }
            $fetchedRows = array_values(array_filter(
                ff_pb_content_items_for_source_link($authHeader, $sourceLinkId),
                fn($it) => is_array($it) && ff_content_item_is_fetched_for_snapshot($it)
            ));
            if ($fetchedRows !== []) {
                $pending = array_values(array_filter($fetchedRows, function ($it) {
                    $st = strtolower(trim((string)($it['status'] ?? '')));
                    return !in_array($st, ['approved', 'rejected'], true);
                }));
                if ($pending !== []) {
                    ff_pipeline_trace_log('create_pipeline_after_fetch_skip', [
                        'reason' => 'awaiting_full_review',
                        'input_media_id' => $sourceLinkId,
                        'fetched_count' => count($fetchedRows),
                        'pending_count' => count($pending),
                    ]);
                    return;
                }
                if ($fetchedContentItemsCount < 1) {
                    $fetchedContentItemsCount = count($fetchedRows);
                }
                if ($fetchedShapeSignature === []) {
                    $fetchedShapeSignature = array_values(array_map(
                        fn($it) => ff_shape_kind_for_content_type((string)($it['type'] ?? '')),
                        $fetchedRows
                    ));
                }
                if ($instagramAccountId === '') {
                    $instagramAccountId = trim((string)($slMeta['social_account_id'] ?? ($sl['social_account_id'] ?? '')));
                }
                if ($sourceUrl === '') {
                    $sourceUrl = trim((string)($sl['url'] ?? ''));
                }
                foreach ($fetchedRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    if (ff_shape_kind_for_content_type((string)($row['type'] ?? '')) !== 'image') {
                        continue;
                    }
                    $u = ff_content_item_prefer_garage_public_media_url($row);
                    if ($u !== '') {
                        $primaryBackingImageUrl = $u;
                        break;
                    }
                }
            }
        }
    }
    ff_pipeline_trace_log('create_pipeline_after_fetch_trigger', [
        'prompt_count' => count($novelIndexPrompts),
        'first_excerpt' => substr($novelIndexPrompts[0] ?? '', 0, 280),
        'backing_input_media_id' => $sourceLinkId !== '' ? $sourceLinkId : null,
        'fetched_content_items_count' => $fetchedContentItemsCount,
    ]);
    $ctx = [
        'intent' => 'create_pipeline',
        'fetched_index_prompts' => $novelIndexPrompts,
        'novel_threshold' => $cfg['novel_threshold'],
    ];
    if ($sourceLinkId !== '') {
        $ctx['backing_input_media_id'] = $sourceLinkId;
    }
    if ($sourceUrl !== '') {
        $ctx['source_link_url'] = $sourceUrl;
    }
    $instagramAccountId = trim($instagramAccountId);
    if ($instagramAccountId !== '') {
        $ctx['social_account_id'] = $instagramAccountId;
    }
    if ($fetchedContentItemsCount > 0) {
        $ctx['fetched_content_items_count'] = $fetchedContentItemsCount;
    }
    if ($fetchedShapeSignature !== []) {
        $ctx['fetched_shape_signature'] = array_values(array_map(
            fn($v) => ff_shape_kind_for_content_type((string)$v),
            $fetchedShapeSignature
        ));
    }
    if ($fetchedContentItemsCount > 1) {
        $ctx['suggested_pipelines_output_type'] = 'carousel';
    } elseif (($ctx['fetched_shape_signature'][0] ?? '') === 'image') {
        $ctx['suggested_pipelines_output_type'] = 'image';
    }
    if ($primaryBackingImageUrl !== '') {
        $ctx['primary_backing_media_url'] = $primaryBackingImageUrl;
        $compSnap = ff_image_composition_technical_snapshot($primaryBackingImageUrl);
        if ($compSnap !== null) {
            $ctx['primary_backing_image_composition'] = $compSnap;
        }
    }
    $mold = ff_antfly_mold_fit_alignment_report($novelIndexPrompts[0] ?? '', $primaryBackingImageUrl !== '' ? $primaryBackingImageUrl : null);
    if ($mold !== null) {
        $ctx['antfly_mold_fit_report'] = $mold;
    }
    if ($sourceLinkId !== '' && $authHeader) {
        $slotsCat = ff_pipeline_agent_fetched_slots_catalog_v1($authHeader, $sourceLinkId);
        if ($slotsCat !== null) {
            $ctx['pipeline_agent_fetched_slots_catalog_v1'] = $slotsCat;
        }
    }
    if ($sourceLinkId !== '' && is_string($authHeader) && $authHeader !== '') {
        $essRows = ff_pb_content_items_for_source_link($authHeader, $sourceLinkId);
        if ($essRows !== []) {
            $essence = ff_pipeline_backing_essence_openrouter($sourceLinkId, $sourceUrl, $essRows, $authHeader);
            if ($essence !== null) {
                $ctx['backing_content_essence_v1'] = $essence;
            }
        }
    }
    trigger_pipeline_cursor_agent('novel_fetched_content', $ctx, $authHeader);
    if ($sourceLinkId !== '' && $authHeader) {
        $gr = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($sourceLinkId), null, $authHeader);
        if (($gr['code'] ?? 0) === 200) {
            $slMeta = is_array($gr['body']['metadata'] ?? null) ? $gr['body']['metadata'] : [];
            $slMeta['pipeline_create_triggered_at'] = date('c');
            $slMeta['pipeline_create_triggered_reason'] = 'all_fetched_content_reviewed';
            $slMeta['pipeline_create_prompt_count'] = count($novelIndexPrompts);
            pb_request('PATCH', '/api/collections/input_media/records/' . rawurlencode($sourceLinkId), ['metadata' => $slMeta], $authHeader);
        }
    }
}

/**
 * Normalize folder name under `pipelines/` (e.g. `pipeline-20260321060557_afca5455`).
 */
function ff_normalize_pipeline_subdir(string $s): string {
    $s = trim(str_replace(['..', '\\'], '', $s));
    if ($s === '') {
        return '';
    }
    $s = basename($s);
    if (!str_starts_with($s, 'pipeline-')) {
        $s = 'pipeline-' . $s;
    }
    return $s;
}

/** RFC 4122 UUID v4 (random). */
function ff_generate_uuid_v4(): string {
    $b = random_bytes(16);
    $b[6] = chr(ord($b[6]) & 0x0f | 0x40);
    $b[8] = chr(ord($b[8]) & 0x3f | 0x80);
    $h = bin2hex($b);
    return substr($h, 0, 8) . '-' . substr($h, 8, 4) . '-' . substr($h, 12, 4) . '-' . substr($h, 16, 4) . '-' . substr($h, 20, 12);
}

/**
 * Normalize a pipeline agent UUID (RFC 4122 string) or return null if invalid.
 */
function ff_validate_pipeline_agent_uuid(?string $v): ?string {
    $v = trim((string) $v);
    if ($v === '') {
        return null;
    }
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
        return null;
    }
    return strtolower($v);
}

/**
 * First Cursor pipeline setup for this directory: create agent_state.json with a stable agent_uuid.
 * Used for `/resume-agent <uuid>` and orchestration continuity; idempotent if file already valid.
 * execution_step (1|2|3): Pipeline Agent chain step; Orchestrator injects next step after each halt.
 *
 * When editing a pipeline, pass **`$preferredAgentUuid`** from PocketBase **`metadata.agent_uuid`** so the same Cursor agent chat can resume; if **`agent_state.json`** already exists with a valid UUID, that value is kept (disk wins).
 *
 * @return array{schema_version?:int,agent_uuid:string,pipeline_subdir:string,execution_step?:int,created_at?:string}
 */
function ff_ensure_pipeline_agent_state(string $pipelineDir, ?string $preferredAgentUuid = null): array {
    $subdir = basename($pipelineDir);
    $path = $pipelineDir . DIRECTORY_SEPARATOR . 'agent_state.json';
    $preferred = ff_validate_pipeline_agent_uuid($preferredAgentUuid);
    if (is_file($path)) {
        $raw = json_decode((string) file_get_contents($path), true);
        $diskUuid = is_array($raw) ? ff_validate_pipeline_agent_uuid(trim((string)($raw['agent_uuid'] ?? ''))) : null;
        if ($diskUuid !== null) {
            $raw['agent_uuid'] = $diskUuid;
            $raw['pipeline_subdir'] = is_string($raw['pipeline_subdir'] ?? null) && trim((string) $raw['pipeline_subdir']) !== ''
                ? trim((string) $raw['pipeline_subdir'])
                : $subdir;
            $step = isset($raw['execution_step']) ? (int) $raw['execution_step'] : 1;
            $raw['execution_step'] = $step >= 1 && $step <= 3 ? $step : 1;
            return $raw;
        }
        if ($preferred !== null) {
            $state = [
                'schema_version' => 1,
                'agent_uuid' => $preferred,
                'pipeline_subdir' => $subdir,
                'execution_step' => 1,
                'created_at' => date('c'),
                'restored_from' => 'preferred_uuid_invalid_file',
            ];
            if (@file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
                ff_pipeline_trace_log('pipeline_agent_state_write_failed', ['path' => $path]);
            }
            return $state;
        }
    }
    $uuid = $preferred ?? ff_generate_uuid_v4();
    $state = [
        'schema_version' => 1,
        'agent_uuid' => $uuid,
        'pipeline_subdir' => $subdir,
        'execution_step' => 1,
        'created_at' => date('c'),
    ];
    if (@file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
        ff_pipeline_trace_log('pipeline_agent_state_write_failed', ['path' => $path]);
    }
    return $state;
}

/**
 * Ensure PocketBase **`pipelines.metadata.agent_uuid`** matches **`agent_state.json`** so `/resume-agent` resolves the same Cursor chat on edits.
 */
function formatforge_pipeline_record_sync_agent_uuid(string $pipelineId, string $agentUuid): void {
    $pipelineId = trim($pipelineId);
    $canonical = ff_validate_pipeline_agent_uuid($agentUuid);
    if ($pipelineId === '' || $canonical === null) {
        return;
    }
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        ff_pipeline_trace_log('pipeline_agent_uuid_sync_skip', ['reason' => 'no_superuser', 'pipeline_id' => $pipelineId]);
        return;
    }
    $tok = $auth['token'];
    $gr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $tok);
    if ($gr['code'] !== 200) {
        ff_pipeline_trace_log('pipeline_agent_uuid_sync_skip', ['reason' => 'pipeline_get_failed', 'pipeline_id' => $pipelineId]);
        return;
    }
    $row = $gr['body'];
    $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    $existing = ff_validate_pipeline_agent_uuid(trim((string)($meta['agent_uuid'] ?? '')));
    if ($existing === $canonical) {
        return;
    }
    $meta['agent_uuid'] = $canonical;
    $patch = pb_request('PATCH', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), ['metadata' => $meta], $tok);
    $ok = $patch['code'] >= 200 && $patch['code'] < 300;
    ff_pipeline_trace_log('pipeline_agent_uuid_sync', ['pipeline_id' => $pipelineId, 'agent_uuid' => $canonical, 'ok' => $ok]);
}

/**
 * Effective declared output for a pipeline row (PocketBase `output_type` or inferred from carousel backing).
 */
function ff_pipeline_effective_output_type(?array $pipelineRecord): string {
    if ($pipelineRecord === null) {
        return '';
    }
    $t = trim((string)($pipelineRecord['output_type'] ?? ''));
    if ($t !== '') {
        return $t;
    }
    $meta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
    if (empty($meta['backing_input_media_id'])) {
        return '';
    }
    $sig = ff_shape_signature_normalize($meta['backing_shape_signature'] ?? []);
    if (count($sig) > 1) {
        return 'carousel';
    }
    if (count($sig) === 1) {
        return $sig[0] === 'image' ? 'image' : 'reel';
    }
    // Backing link only — many generations on the same link are not a carousel; default to image until shape is known.
    return 'image';
}

/**
 * PocketBase **`content_items.type`** for new rows created from this pipeline.
 * Carousel-source workflows still emit **one video per slide** → use **`reel`** (or **`video`**) for Curate + Instagram Reels; **`image`** only when the pipeline is image-only.
 */
function ff_pipeline_content_item_type(?array $pipelineRecord): string {
    if ($pipelineRecord === null) {
        return 'reel';
    }
    $t = strtolower(trim((string)($pipelineRecord['output_type'] ?? '')));
    if ($t === 'image') {
        return 'image';
    }
    if ($t === 'video') {
        return 'video';
    }
    if ($t === 'carousel') {
        return 'reel';
    }
    if ($t === 'reel' || $t === '') {
        return 'reel';
    }
    return 'reel';
}

/**
 * Type string for Antfly indexing (must match **`content_items.type`** semantics).
 */
function ff_antfly_type_for_content_row(array $contentRow, ?array $pipelineRecord): string {
    $ct = strtolower(trim((string)($contentRow['type'] ?? '')));
    if ($ct !== '') {
        return $ct;
    }
    return ff_pipeline_content_item_type($pipelineRecord);
}

/**
 * Wrap orchestrator-injected markdown so `index.php` can replace it when `execution_step` changes (e.g. cursor-agent-advance-step).
 */
function ff_pipeline_orchestrator_wrap_injection(string $innerMarkdown): string {
    return "<!-- FF_ORCHESTRATOR_INJECTION -->\n" . $innerMarkdown . "\n<!-- /FF_ORCHESTRATOR_INJECTION -->";
}

/** Longest run of backticks in a string (for safe nested markdown fences). */
function ff_markdown_longest_backtick_run(string $s): int {
    $max = 0;
    if (preg_match_all('/`+/', $s, $m)) {
        foreach ($m[0] as $run) {
            $max = max($max, strlen($run));
        }
    }
    return $max;
}

/**
 * Fence arbitrary text so it can be embedded in a Markdown prompt (handles ``` inside README).
 */
function ff_fence_embedded_text(string $body, string $info = 'markdown'): string {
    $n = max(3, ff_markdown_longest_backtick_run($body) + 1);
    $fence = str_repeat('`', $n);
    $info = trim($info);
    return $fence . ($info !== '' ? $info : '') . "\n" . $body . "\n" . $fence;
}

/**
 * Full README body for Cursor prompts: prefer pipeline dir, else template baseline.
 *
 * @return array{text: string, label: string}|null
 */
function ff_pipeline_readme_for_prompt(string $templatePath, string $pipelineDir): ?array {
    $candidates = [
        [$pipelineDir . '/README.md', 'pipelines/' . basename($pipelineDir) . '/README.md'],
        [rtrim($templatePath, '/\\') . '/README.md', 'pipelines/template/README.md (baseline)'],
    ];
    foreach ($candidates as [$path, $label]) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $raw = (string) @file_get_contents($path);
        if (trim($raw) !== '') {
            return ['text' => $raw, 'label' => $label];
        }
    }
    return null;
}

/**
 * Markdown section embedding README into the pipeline Cursor prompt (so the agent need not open the file only to read it).
 */
function ff_pipeline_readme_injection_section(string $templatePath, string $pipelineDir): string {
    $got = ff_pipeline_readme_for_prompt($templatePath, $pipelineDir);
    if ($got === null) {
        return '';
    }
    $fenced = ff_fence_embedded_text($got['text'], 'markdown');
    $label = $got['label'];
    return "\n## Pipeline README (injected — full text)\n\n"
        . "The following is the current **`README.md`** from **`$label`**. It is embedded here so you **do not need to open that file only to read this baseline**; keep **`README.md`** on disk updated when you change pipeline docs.\n\n"
        . $fenced . "\n";
}

/**
 * **Orchestrator (system):** one chain step only — injected by FormatForge `index.php` from `pipelines/.../agent_state.json` → `execution_step`.
 *
 * @param int    $step               1|2|3
 * @param string $pipelineSubdirName e.g. pipeline-20260321060557_afca5455
 */
function ff_pipeline_orchestrator_injection_block(int $step, string $pipelineSubdirName): string {
    $step = max(1, min(3, $step));
    $afterStep = $step >= 3
        ? 'When this step is done, leave **`execution_step`** at **3** (or record completion as your process requires).'
        : 'Update **`pipelines/' . $pipelineSubdirName . '/agent_state.json`**: set **`execution_step`** to **' . ($step + 1) . '** and **stop**. The next FormatForge trigger injects Step ' . ($step + 1) . '. Or run `php index.php cursor-agent-advance-step ' . $pipelineSubdirName . '` when the expected artifact exists; that bumps **`execution_step`** and refreshes the injection block in this prompt file.';
    $cfgOrch = $GLOBALS['CONFIG'] ?? [];
    $orchModelLc = strtolower(ff_cursor_agent_model_from_cfg(is_array($cfgOrch) ? $cfgOrch : []));
    $orchNanoBanana = ($orchModelLc === 'google/nano-banana-pro')
        ? "> **Cursor `google/nano-banana-pro`:** This run uses an **image-editing-capable** model — you **may** create or revise images here (mocks, composites, references) when useful; shipped pipeline media still goes through **Replicate/fal** per **`pipeline_architecture.json`** with pinned version ids.\n>\n"
        : '';
    $base = "\n\n## Orchestrator injection (system — `index.php`)\n\n"
        . "**The Orchestrator is this application (PHP).** It injects **only Step {$step} of 3** into this prompt. "
        . "Other steps are **not** included here; they are injected on other runs according to **`execution_step`**.\n\n"
        . "**State machine:** Treat this run as exactly **one** orchestrator step. Do not implement later steps early, and do not create a PocketBase **`pipelines`** row **unless** this injection is Step 3 and the task requires it. Obey this step’s **Forbidden** / **Action** lists.\n\n"
        . "**After this step:** $afterStep\n\n"
        . "### Pipeline Agent Directive (strict)\n\n"
        . "> **SCOPE:** Your **writes** belong **only** under **`pipelines/" . $pipelineSubdirName . "/`** (that pipeline’s Go, docs, `.env`, etc.). Do **not** edit **`index.php`**, **`config.php`**, or anything outside that directory.\n>\n"
        . "> **PIPELINE AGENT DIRECTIVE: AI-FIRST (REPLICATE)**\n"
        . "> **Default** to **AI APIs** for generated imagery/video — **prefer [Replicate](https://replicate.com)** when tokens allow. Do **not** default to offline image-manipulation stacks (ImageMagick, Go `imaging` / heavy `image/draw` compositing, rasterizer CLIs, etc.) unless the niche **clearly** needs deterministic pixel code rather than a model.\n>\n"
        . "> **Pick models by usage:** On Replicate, **open model pages and compare run counts** (higher → more widely exercised). Prefer those defaults for your modality (image, video, upscale, etc.), then lock **exact owner/name + version id** in **`pipeline_architecture.json`** and **`prompt_template`**.\n>\n"
        . "> **Non-compliant:** A PocketBase **`pipelines.prompt_template`** that is only a vague creative brief **without** **`source_analysis.md`** and **`pipeline_architecture.json`** in this pipeline directory describing the real provider/step graph.\n>\n"
        . $orchNanoBanana;

    if ($step === 1) {
        return $base
            . "### INJECTED: Step 1 — Context Gathering & Template Initialization\n\n"
            . "- **Forbidden this run:** Writing or editing **Go** source (`*.go`), **`go.mod` / `go.sum`**, or running **`go build`** / **`go mod tidy`** for the pipeline binary. Step 1 is analysis and **`source_analysis.md`** only.\n"
            . "- **Baseline README:** If **`## Pipeline README (injected — full text)`** appears **above** in this prompt, use it as the template baseline (build, env, cron hints). **Do not** open **`README.md`** only to read that baseline — it is duplicated there; still edit **`README.md`** on disk when you change docs.\n"
            . "- Ingest source information: raw source link, Antfly embeddings/JSON, transcript, extracted metadata (timestamps, hooks, visual pacing).\n"
            . "- **Technical composition (required):** When JSON context includes **`primary_backing_image_composition`**, you must fold those **measured** fields into **`source_analysis.md`** (not optional fluff). Add a dedicated subsection **“Technical composition”** that cites **`width_px` / `height_px` / `orientation` / `aspect_ratio_wh`**, **`mean_luminance_0_1`** (overall light vs dark), **`edge_activity_0_1`** (layout busyness), **`dominant_palette_hex`**, and **`vertical_thirds_mean_color`** (top/middle/bottom bands). Explain **foreground vs background** hierarchy, **where headline/body/CTA would likely live** relative to those bands, **contrast risks** (e.g. light-on-light), **safe margins / safe zones** for text overlays, and **color relationships** (dominant vs accent). If metrics are missing, state what you could not measure and what you infer only from OG/caption — and what a **new** composite must change structurally vs the backing image.\n"
            . "- **Action:** Write **`pipelines/" . $pipelineSubdirName . "/source_analysis.md`** describing what makes the source successful (**pacing, visual style, audio cues**) **and** the **technical composition** requirements above. **Stop** after this file is written.\n"
            . "- **Mandatory halt:** Once **`source_analysis.md`** is written, your **final** action must be to print exactly this line to the terminal (stdout) as the last line of your run: `Artifact generated. Halting for next injection.` Do not start Step 2, do not write **`pipeline_architecture.json`**, do not touch Go.\n";
    }
    if ($step === 2) {
        return $base
            . "### INJECTED: Step 2 — Deconstruction & Compositing Strategy\n\n"
            . "- **Forbidden this run:** Writing or editing **Go** source (`*.go`), **`go.mod` / `go.sum`**, or running **`go build`** / **`go mod tidy`** for the pipeline binary. Step 2 is architecture JSON only.\n"
            . "- **Requires:** **`source_analysis.md`** in **`pipelines/" . $pipelineSubdirName . "/`**.\n"
            . "- Propose a technical strategy to rebuild the format; **default** to **Replicate (AI)** steps where they fit, with models chosen per **run count** on replicate.com as above.\n"
            . "- Define: which **Replicate/fal (or other AI) models and inputs** produce the main assets; optional **TTS**, **ffmpeg** (mux/caption/assemble), or other local steps **only when** they add clear value — **not** ImageMagick / imaging-library pipelines by default.\n"
            . "- **Action:** Write **`pipelines/" . $pipelineSubdirName . "/pipeline_architecture.json`** — exact sequence of **API predictions** and any **minimal** local runs. **Stop** after this file is written.\n"
            . "- **Mandatory halt:** Once **`pipeline_architecture.json`** is written, your **final** action must be to print exactly this line to the terminal (stdout) as the last line of your run: `Artifact generated. Halting for next injection.` Do not start Step 3, do not implement Go, do not run **`go build`**.\n";
    }

    return $base
        . "### INJECTED: Step 3 — Implementation, Build & Validation\n\n"
        . "- **Requires:** **`pipeline_architecture.json`** (and prior artifacts) under **`pipelines/" . $pipelineSubdirName . "/`**.\n"
        . "- Implement executable Go/scripts per that JSON; handle errors and rate limits.\n"
        . "- **Compilation gate (mandatory):** From **`pipelines/" . $pipelineSubdirName . "/`**, run **`go mod tidy`**, then **`go build -o pipeline-generate .`** (or the build command your architecture JSON specifies). If **`stderr`** shows **any** error, read it, fix the Go code or modules, and run **`go mod tidy`** and **`go build`** again. **You cannot treat this step as complete until `go build` exits with code 0.** Repeat the fix-compile loop until clean.\n"
        . "- Run validation to produce the final **`.mp4`** or image carousel; debug until a valid media file exists.\n";
}

/**
 * Replace the orchestrator injection region in a Cursor prompt `.md` (markers from `ff_pipeline_orchestrator_wrap_injection`).
 */
function ff_pipeline_orchestrator_prompt_apply_injection(string $projectRoot, string $pipelineDir): bool {
    $sub = basename($pipelineDir);
    $promptMd = $projectRoot . '/.cursor-pipeline/prompts/' . $sub . '.md';
    if (!is_file($promptMd)) {
        return false;
    }
    $state = ff_ensure_pipeline_agent_state($pipelineDir);
    $step = max(1, min(3, (int)($state['execution_step'] ?? 1)));
    $wrapped = ff_pipeline_orchestrator_wrap_injection(ff_pipeline_orchestrator_injection_block($step, $sub));
    $content = (string) file_get_contents($promptMd);
    $pattern = '/<!-- FF_ORCHESTRATOR_INJECTION -->.*?<!-- \/FF_ORCHESTRATOR_INJECTION -->/s';
    if (!preg_match($pattern, $content)) {
        return false;
    }
    $content = preg_replace($pattern, $wrapped, $content, 1);
    return @file_put_contents($promptMd, $content) !== false;
}

/**
 * Resolve `pipelines/pipeline-*` / agent prompt basename from a PocketBase pipelines record.
 */
function ff_pipeline_subdir_from_pipeline_record(array $pipeline): string {
    $meta = is_array($pipeline['metadata'] ?? null) ? $pipeline['metadata'] : [];
    $subdir = trim((string)($meta['pipeline_subdir'] ?? ''));
    if ($subdir !== '') {
        return ff_normalize_pipeline_subdir($subdir);
    }
    $nt = trim((string)($meta['novel_trigger'] ?? ''));
    if ($nt === '') {
        return '';
    }
    $nt = preg_replace('/[^a-zA-Z0-9_-]/', '', $nt) ?: '';
    if ($nt === '') {
        return '';
    }
    return ff_normalize_pipeline_subdir($nt);
}

/**
 * Rejection-log reasons that are ops / infra / tooling — not creative direction for image or video models.
 * They stay in `metadata.rejection_log` for the pipeline agent but must not be concatenated into run prompts.
 */
function ff_pipeline_rejection_reason_is_ops_noise_for_run_prompt(string $reason): bool {
    $r = trim($reason);
    if ($r === '') {
        return true;
    }
    if (preg_match('/^\[generation_failure:/i', $r)) {
        return true;
    }
    if (preg_match('/^\[quality gate\]/i', $r)) {
        return true;
    }
    if (stripos($r, 'Pipeline binary missing or not executable') !== false) {
        return true;
    }
    if (stripos($r, 'Failed to launch pipeline binary') !== false) {
        return true;
    }
    if (stripos($r, 'OPENROUTER_API_KEY') !== false && stripos($r, 'not set') !== false) {
        return true;
    }
    if (stripos($r, 'OpenRouter returned non-JSON') !== false) {
        return true;
    }
    if (stripos($r, 'PIPELINE_INPUT_LLM_MODEL') !== false) {
        return true;
    }
    if (preg_match('/\buse (a )?better model\b/i', $r)) {
        return true;
    }

    return false;
}

/**
 * Text appended to the next `generate_content` / verify run from `pipelines.metadata.rejection_log` (no Cursor required).
 */
function ff_pipeline_rejection_prompt_addendum(array $pipelineRecord): string {
    $meta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
    $log = $meta['rejection_log'] ?? null;
    if (!is_array($log) || $log === []) {
        return '';
    }
    $lines = [];
    $seen = [];
    foreach (array_slice($log, -12) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $r = trim((string)($entry['reason'] ?? ''));
        if (ff_pipeline_rejection_reason_is_ops_noise_for_run_prompt($r)) {
            continue;
        }
        $ann = trim((string)($entry['annotation_url'] ?? ''));
        if ($r === '' && $ann === '') {
            continue;
        }
        $line = $r;
        if ($ann !== '') {
            $line = ($line !== '' ? $line . ' ' : '') . '[Annotation image: ' . $ann . ']';
        }
        if (strlen($line) > 420) {
            $line = substr($line, 0, 420) . '…';
        }
        $bullet = '- ' . $line;
        $key = strtolower($bullet);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $lines[] = $bullet;
        if (count($lines) >= 6) {
            break;
        }
    }
    if ($lines === []) {
        return '';
    }
    return "--- Curator feedback (visual / composition only — do not paste this block verbatim as the sole image prompt; compare to backing in Curate) — **compare visually** to the backing/generated pair in the Curate UI (or source link); text alone is not enough ---\n" . implode("\n", $lines);
}

/**
 * Hard quality gate for pipeline rows before generation.
 *
 * @return array{ok: bool, reasons: list<string>}
 */
function ff_pipeline_quality_gate_check(?array $pipelineRecord): array {
    if ($pipelineRecord === null) {
        return ['ok' => true, 'reasons' => []];
    }
    $reasons = [];
    $template = trim((string)($pipelineRecord['prompt_template'] ?? ''));
    if ($template === '') {
        $reasons[] = 'Empty prompt_template.';
    }

    $meta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
    $outputType = strtolower(trim((string)($pipelineRecord['output_type'] ?? '')));
    $hasBacking = trim((string)($meta['backing_input_media_id'] ?? '')) !== '';
    $backingSig = ff_shape_signature_normalize($meta['backing_shape_signature'] ?? []);
    $backingSigCount = count($backingSig);
    // Do not tie backing_input_media_id to output_type for single-slot / unknown shapes: reel, video, image, and '' are all valid.
    // Only when metadata explicitly records 2+ backing slots must the pipeline be labeled carousel (N-for-N batch semantics).
    if ($hasBacking && $backingSigCount > 1 && $outputType !== 'carousel') {
        $reasons[] = 'Pipeline has metadata.backing_shape_signature with ' . $backingSigCount . ' slots; set output_type to carousel (or clear/trim backing_shape_signature if that shape is stale).';
    }

    $templateLc = strtolower($template);
    $mentionsVideoProvider = preg_match('/\b(replicate|fal\.?ai|text-to-video|minimax|kling)\b/i', $template) === 1;
    $hasComposedSignal = false;
    foreach ([
        'ffmpeg',
        'caption',
        'tts',
        'voiceover',
        'storyboard',
        'scene',
        'composite',
        'sequence',
        'per-slide',
        'slide',
        'asset',
        'architecture',
        'replicate.com',
        'pipeline_architecture',
        'source_analysis',
        'script',
        'batch',
    ] as $needle) {
        if (str_contains($templateLc, $needle)) {
            $hasComposedSignal = true;
            break;
        }
    }
    if ($mentionsVideoProvider && !$hasComposedSignal) {
        $reasons[] = 'Prompt template looks like bare text-to-video (Replicate/fal) without architecture cues (e.g. steps, model/version ids, replicate.com, source_analysis, ffmpeg).';
    }

    $subdir = ff_pipeline_subdir_from_pipeline_record($pipelineRecord);
    if ($subdir !== '') {
        $pipelineDir = __DIR__ . '/pipelines/' . $subdir;
        if (is_dir($pipelineDir)) {
            $missing = [];
            if (!is_file($pipelineDir . '/source_analysis.md')) {
                $missing[] = 'source_analysis.md';
            }
            if (!is_file($pipelineDir . '/pipeline_architecture.json')) {
                $missing[] = 'pipeline_architecture.json';
            }
            if ($missing !== []) {
                // Do not hard-block generation for missing artifacts on existing pipelines.
                // Keep the warning visible in diagnostics and let agent edit loops handle remediation.
                ff_pipeline_trace_log('pipeline_quality_gate_artifact_warning', [
                    'pipeline_id' => (string)($pipelineRecord['id'] ?? ''),
                    'pipeline_subdir' => $subdir,
                    'missing' => $missing,
                ]);
            }
        }
    }

    return ['ok' => $reasons === [], 'reasons' => $reasons];
}

/**
 * Force-run the pipeline edit loop when quality gate fails.
 */
function trigger_pipeline_edit_loop_for_gate_violation(array $pipelineRecord, string $reason, ?string $authHeader): void {
    if (!$authHeader) {
        ff_pipeline_trace_log('pipeline_quality_gate_edit_loop_skip', ['reason' => 'no_auth']);
        return;
    }
    $pipelineId = trim((string)($pipelineRecord['id'] ?? ''));
    if ($pipelineId === '') {
        ff_pipeline_trace_log('pipeline_quality_gate_edit_loop_skip', ['reason' => 'missing_pipeline_id']);
        return;
    }
    $meta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
    $subdir = ff_pipeline_subdir_from_pipeline_record($pipelineRecord);
    $backingOnPipeline = trim((string)($meta['backing_input_media_id'] ?? $meta['default_input_media_id'] ?? $meta['input_media_id'] ?? ''));
    $agentUuidPb = trim((string)($meta['agent_uuid'] ?? ''));
    $why = trim($reason) !== '' ? trim($reason) : 'Pipeline quality gate failed.';
    formatforge_pipeline_record_rejection_feedback($pipelineId, '[quality gate] ' . $why, 'gate-' . date('YmdHis'));
    trigger_pipeline_cursor_agent('pipeline_content_rejected', [
        'intent' => 'edit_pipeline',
        'content_item_id' => 'gate-' . date('YmdHis'),
        'pipeline_id' => $pipelineId,
        'pipeline_name' => (string)($pipelineRecord['name'] ?? ''),
        'pipeline_subdir' => $subdir,
        'agent_uuid' => $agentUuidPb !== '' ? $agentUuidPb : null,
        'prompt_template' => (string)($pipelineRecord['prompt_template'] ?? ''),
        'rejected_reason' => '[quality gate] ' . $why,
        'consecutive_rejects' => max(1, (int)($GLOBALS['CONFIG']['pipeline_reject_streak'] ?? 1)),
        'content_prompt' => (string)($pipelineRecord['prompt_template'] ?? ''),
        'backing_input_media_id_on_pipeline' => $backingOnPipeline !== '' ? $backingOnPipeline : null,
        'content_item_source_link_id' => null,
    ], $authHeader);
    ff_pipeline_trace_log('pipeline_quality_gate_edit_loop_triggered', [
        'pipeline_id' => $pipelineId,
        'pipeline_subdir' => $subdir !== '' ? $subdir : null,
        'reason' => $why,
    ]);
}

/**
 * Compare expected vs actual slot kinds for one pipeline run (source-backed or ingredient batch).
 *
 * @param list<array<string,mixed>> $items Rows sharing the same pipeline run id
 * @return null|array{ok: bool, expected: list<string>, actual: list<string>} null if still generating or no signature
 */
function ff_shape_bundle_compare_run_items(array $items): ?array {
    if ($items === []) {
        return null;
    }
    foreach ($items as $it) {
        if (trim((string)($it['status'] ?? '')) === 'generating') {
            return null;
        }
    }
    $first = $items[0];
    $fm = is_array($first['metadata'] ?? null) ? $first['metadata'] : [];
    if (trim((string)($fm['source_shape_run_id'] ?? '')) !== '') {
        $expected = $fm['source_shape_signature'] ?? [];
        $mode = 'source';
    } elseif (trim((string)($fm['ingredient_run_id'] ?? '')) !== '') {
        $expected = $fm['ingredient_signature'] ?? [];
        $mode = 'ingredient';
    } else {
        return null;
    }
    if (!is_array($expected) || $expected === []) {
        return null;
    }
    $indexed = [];
    foreach ($items as $it) {
        $m = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
        if ($mode === 'source') {
            $idx = isset($m['source_shape_index']) ? (int)$m['source_shape_index'] : 0;
        } else {
            $idx = isset($m['ingredient_index']) ? (int)$m['ingredient_index'] : 0;
        }
        if ($idx <= 0) {
            continue;
        }
        $indexed[$idx] = ff_shape_kind_for_content_type((string)($it['type'] ?? ''));
    }
    ksort($indexed, SORT_NUMERIC);
    $actual = array_values($indexed);
    $expectedNorm = array_values(array_map(fn($v) => ff_shape_kind_for_content_type((string)$v), $expected));

    return ['ok' => $actual === $expectedNorm, 'expected' => $expectedNorm, 'actual' => $actual];
}

function ff_pipeline_shape_gate_already_triggered(array $pipelineRecord, string $runKey): bool {
    $runKey = trim($runKey);
    if ($runKey === '') {
        return false;
    }
    $meta = is_array($pipelineRecord['metadata'] ?? null) ? $pipelineRecord['metadata'] : [];
    $ids = $meta['shape_gate_triggered_run_ids'] ?? [];
    if (!is_array($ids)) {
        return false;
    }
    foreach ($ids as $id) {
        if (trim((string)$id) === $runKey) {
            return true;
        }
    }

    return false;
}

function ff_pipeline_mark_shape_gate_triggered(string $pipelineId, string $runKey): void {
    $pipelineId = trim($pipelineId);
    $runKey = trim($runKey);
    if ($pipelineId === '' || $runKey === '') {
        return;
    }
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        return;
    }
    $tok = $auth['token'];
    $gr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $tok);
    if ($gr['code'] !== 200) {
        return;
    }
    $row = $gr['body'];
    $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    $ids = $meta['shape_gate_triggered_run_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = [];
    }
    $strIds = array_map('strval', $ids);
    if (!in_array($runKey, $strIds, true)) {
        $ids[] = $runKey;
    }
    if (count($ids) > 50) {
        $ids = array_slice($ids, -50);
    }
    $meta['shape_gate_triggered_run_ids'] = $ids;
    pb_request('PATCH', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), ['metadata' => $meta], $tok);
}

/**
 * Mark pipeline-generated rows rejected immediately when slot output kinds do not match the run signature.
 * Does not call maybe_trigger_cursor_edit_pipeline_after_reject (quality-gate / shape handler triggers the pipeline agent).
 *
 * @param list<array<string,mixed>> $items Rows in one shape run (same source_shape_run_id or ingredient_run_id)
 * @return int Number of rows successfully PATCHed to rejected
 */
function ff_reject_content_items_for_shape_mismatch(array $items, string $reasonLine, string $authHeader): int {
    $reasonLine = trim($reasonLine);
    if ($reasonLine === '') {
        $reasonLine = 'Shape mismatch (automatic).';
    }
    $reasonFinal = '[auto] ' . $reasonLine;
    $n = 0;
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $id = trim((string)($it['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $st = strtolower(trim((string)($it['status'] ?? '')));
        if (in_array($st, ['approved', 'rejected', 'published'], true)) {
            continue;
        }
        if ($st === 'generating') {
            continue;
        }
        $pmBase = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
        unset($pmBase['auto_post_failure']);
        $pmBase['shape_mismatch_auto_reject'] = true;
        $patchReject = [
            'status' => 'rejected',
            'rejected_reason' => $reasonFinal,
            'scheduled_publish_at' => null,
            'metadata' => $pmBase,
        ];
        $up = pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($id), $patchReject, $authHeader);
        if ($up['code'] >= 200 && $up['code'] < 300) {
            $n++;
            $merged = is_array($up['body'] ?? null) ? $up['body'] : null;
            if (is_array($merged) && ff_content_item_is_pipeline_generated_snapshot($merged)) {
                ff_measure_generation_input_alignment($id, $authHeader, false);
            }
        }
    }
    if ($n > 0) {
        ff_pipeline_trace_log('shape_mismatch_auto_reject', ['count' => $n]);
    }

    return $n;
}

/**
 * Reject all rows in the run, then one pipeline-agent edit per distinct run when output kinds do not match the expected shape signature.
 *
 * @param list<array<string,mixed>> $items Same-run content_items (pass from verify/scan so rejects are instant)
 * @return bool True when the edit loop was triggered (not skipped by dedupe or missing auth)
 */
function ff_trigger_shape_mismatch_pipeline_edit(array $pipelineRow, string $runKey, string $reason, ?string $authHeader, array $items = []): bool {
    if (!$authHeader) {
        ff_pipeline_trace_log('shape_mismatch_gate_skip', ['reason' => 'no_auth', 'run_key' => $runKey]);

        return false;
    }
    $pipelineId = trim((string)($pipelineRow['id'] ?? ''));
    if ($pipelineId === '') {
        return false;
    }
    if ($items !== []) {
        ff_reject_content_items_for_shape_mismatch($items, $reason, $authHeader);
    }
    $pRes = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $authHeader);
    $fresh = ($pRes['code'] === 200 && is_array($pRes['body'] ?? null)) ? $pRes['body'] : $pipelineRow;
    if (ff_pipeline_shape_gate_already_triggered($fresh, $runKey)) {
        ff_pipeline_trace_log('shape_mismatch_gate_skip', ['reason' => 'already_triggered', 'run_key' => $runKey, 'pipeline_id' => $pipelineId]);

        return false;
    }
    trigger_pipeline_edit_loop_for_gate_violation($fresh, $reason, $authHeader);
    ff_pipeline_mark_shape_gate_triggered($pipelineId, $runKey);
    ff_pipeline_trace_log('shape_mismatch_gate_triggered', ['pipeline_id' => $pipelineId, 'run_key' => $runKey]);

    return true;
}

/**
 * Scan recent pipeline-generated rows (e.g. after Go pipeline-generate completes outside PHP) and trigger edit on mismatch.
 *
 * @return int Number of runs that triggered the agent (0 if none or all deduped)
 */
function ff_scan_shape_mismatch_gates_for_account(string $accountId, string $authHeader): int {
    $accountId = trim($accountId);
    if ($accountId === '') {
        return 0;
    }
    $esc = str_replace(['\\', '"'], ['\\\\', '\\"'], $accountId);
    $q = http_build_query([
        'filter' => 'social_account_id = "' . $esc . '" && metadata.pipeline_id != ""',
        'perPage' => 500,
        'sort' => '-@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $q, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        return 0;
    }
    $items = is_array($lr['body']['items'] ?? null) ? $lr['body']['items'] : [];
    $groups = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $m = is_array($it['metadata'] ?? null) ? $it['metadata'] : [];
        $pid = trim((string)($m['pipeline_id'] ?? ''));
        if ($pid === '') {
            continue;
        }
        $srcRun = trim((string)($m['source_shape_run_id'] ?? ''));
        $ingRun = trim((string)($m['ingredient_run_id'] ?? ''));
        if ($srcRun !== '') {
            $key = $pid . "\0" . 'src:' . $srcRun;
        } elseif ($ingRun !== '') {
            $key = $pid . "\0" . 'ing:' . $ingRun;
        } else {
            continue;
        }
        $groups[$key][] = $it;
    }
    $triggered = 0;
    $pipelines = [];
    foreach ($groups as $key => $groupItems) {
        $cmp = ff_shape_bundle_compare_run_items($groupItems);
        if ($cmp === null || ($cmp['ok'] ?? false)) {
            continue;
        }
        $parts = explode("\0", $key, 2);
        $pipelineId = $parts[0] ?? '';
        $runKey = $parts[1] ?? '';
        if ($pipelineId === '' || $runKey === '') {
            continue;
        }
        if (!isset($pipelines[$pipelineId])) {
            $pRes = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $authHeader);
            $pipelines[$pipelineId] = ($pRes['code'] === 200 && is_array($pRes['body'] ?? null)) ? $pRes['body'] : null;
        }
        $prow = $pipelines[$pipelineId];
        if ($prow === null) {
            continue;
        }
        $reason = 'Shape mismatch. expected=' . json_encode($cmp['expected']) . ' actual=' . json_encode($cmp['actual']);
        if (ff_trigger_shape_mismatch_pipeline_edit($prow, $runKey, $reason, $authHeader, $groupItems)) {
            $triggered++;
        }
    }
    if ($triggered > 0) {
        ff_pipeline_trace_log('shape_mismatch_gate_scan', ['account_id' => $accountId, 'runs_triggered' => $triggered]);
    }

    return $triggered;
}

/**
 * After each generated item completes, verify run-bundle signature (video/image order) and trigger edit loop on mismatch.
 */
function ff_verify_shape_bundle_after_item_finish(array $contentRow, ?array $pipelineRow, string $authHeader): void {
    $meta = is_array($contentRow['metadata'] ?? null) ? $contentRow['metadata'] : [];
    $runId = trim((string)($meta['source_shape_run_id'] ?? ''));
    $filterField = 'source_shape_run_id';
    if ($runId === '') {
        $runId = trim((string)($meta['ingredient_run_id'] ?? ''));
        $filterField = 'ingredient_run_id';
    }
    if ($runId === '') {
        return;
    }
    $runKey = ($filterField === 'ingredient_run_id') ? ('ing:' . $runId) : ('src:' . $runId);
    $pipelineId = trim((string)($meta['pipeline_id'] ?? ''));
    if ($pipelineId === '' || $pipelineRow === null) {
        return;
    }
    $escRun = str_replace(['\\', '"'], ['\\\\', '\\"'], $runId);
    $escPid = str_replace(['\\', '"'], ['\\\\', '\\"'], $pipelineId);
    $qs = http_build_query([
        'filter' => 'metadata.' . $filterField . ' = "' . $escRun . '" && metadata.pipeline_id = "' . $escPid . '"',
        'perPage' => 200,
        'sort' => '@rowid',
    ]);
    $lr = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
    if (($lr['code'] ?? 0) !== 200) {
        return;
    }
    $items = is_array($lr['body']['items'] ?? null) ? $lr['body']['items'] : [];
    if ($items === []) {
        return;
    }
    $cmp = ff_shape_bundle_compare_run_items($items);
    if ($cmp === null || ($cmp['ok'] ?? false)) {
        return;
    }
    $reason = 'Shape mismatch. expected=' . json_encode($cmp['expected']) . ' actual=' . json_encode($cmp['actual']);
    ff_trigger_shape_mismatch_pipeline_edit($pipelineRow, $runKey, $reason, $authHeader, $items);
}

/**
 * Persist reject reason on the PocketBase **`pipelines`** row (`metadata.rejection_log`) so the next run can improve without the Cursor agent.
 */
function formatforge_pipeline_record_rejection_feedback(string $pipelineId, string $reason, string $contentItemId, string $annotationUrl = ''): void {
    $pipelineId = trim($pipelineId);
    $contentItemId = trim($contentItemId);
    if ($pipelineId === '' || $contentItemId === '') {
        return;
    }
    $reason = trim($reason);
    $annotationUrl = trim($annotationUrl);
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        ff_pipeline_trace_log('pipeline_rejection_feedback_skip', ['reason' => 'no_superuser', 'pipeline_id' => $pipelineId]);
        return;
    }
    $tok = $auth['token'];
    $gr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $tok);
    if ($gr['code'] !== 200) {
        ff_pipeline_trace_log('pipeline_rejection_feedback_skip', ['reason' => 'pipeline_get_failed', 'pipeline_id' => $pipelineId]);
        return;
    }
    $row = $gr['body'];
    $meta = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
    $log = $meta['rejection_log'] ?? [];
    if (!is_array($log)) {
        $log = [];
    }
    $entry = [
        'at' => date('c'),
        'content_item_id' => $contentItemId,
        'reason' => $reason !== '' ? $reason : ($annotationUrl !== '' ? '(markup image)' : '(no reason given)'),
    ];
    if ($annotationUrl !== '') {
        $entry['annotation_url'] = $annotationUrl;
    }
    $log[] = $entry;
    if (count($log) > 30) {
        $log = array_slice($log, -30);
    }
    $meta['rejection_log'] = $log;
    $patch = pb_request('PATCH', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), ['metadata' => $meta], $tok);
    $ok = $patch['code'] >= 200 && $patch['code'] < 300;
    ff_pipeline_trace_log('pipeline_rejection_feedback_recorded', [
        'pipeline_id' => $pipelineId,
        'content_item_id' => $contentItemId,
        'ok' => $ok,
    ]);
}

/**
 * After rejecting pipeline-generated content: spawn Cursor to edit that pipeline (and agent prompt file) after N consecutive rejects.
 */
function maybe_trigger_cursor_edit_pipeline_after_reject(array $itemBody, string $contentItemId, string $reason, ?string $authHeader): void {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['cursor_pipeline_trigger_dir'])) {
        ff_pipeline_trace_log('edit_pipeline_after_reject_skip', ['reason' => 'empty_trigger_dir_config', 'content_item_id' => $contentItemId]);
        return;
    }
    if (!$authHeader) {
        ff_pipeline_trace_log('edit_pipeline_after_reject_skip', ['reason' => 'no_auth', 'content_item_id' => $contentItemId]);
        return;
    }
    $meta = $itemBody['metadata'] ?? [];
    if (!is_array($meta)) {
        ff_pipeline_trace_log('edit_pipeline_after_reject_skip', ['reason' => 'bad_metadata', 'content_item_id' => $contentItemId]);
        return;
    }
    $pipelineId = trim((string)($meta['pipeline_id'] ?? ''));
    if ($pipelineId === '') {
        ff_pipeline_trace_log('edit_pipeline_after_reject_skip', ['reason' => 'no_pipeline_id_on_item', 'content_item_id' => $contentItemId]);
        return;
    }
    $need = (int)($cfg['pipeline_reject_streak'] ?? 1);
    if ($need < 1) {
        ff_pipeline_trace_log('edit_pipeline_after_reject_skip', ['reason' => 'cursor_agent_rejects_disabled', 'content_item_id' => $contentItemId]);
        return;
    }
    $streak = count_consecutive_rejects_for_pipeline($pipelineId, $authHeader);
    if ($streak < $need) {
        ff_pipeline_trace_log('edit_pipeline_after_reject_skip', [
            'reason' => 'streak_below_threshold',
            'pipeline_id' => $pipelineId,
            'consecutive_rejects' => $streak,
            'need' => $need,
        ]);
        return;
    }
    $pRes = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $authHeader);
    $pipeline = ($pRes['code'] === 200) ? ($pRes['body'] ?? []) : [];
    $subdir = ff_pipeline_subdir_from_pipeline_record($pipeline);
    ff_pipeline_trace_log('edit_pipeline_after_reject_trigger', [
        'pipeline_id' => $pipelineId,
        'pipeline_subdir' => $subdir,
        'consecutive_rejects' => $streak,
        'content_item_id' => $contentItemId,
    ]);
    $pmeta = is_array($pipeline['metadata'] ?? null) ? $pipeline['metadata'] : [];
    $backingOnPipeline = trim((string) ($pmeta['backing_input_media_id'] ?? $pmeta['default_input_media_id'] ?? $pmeta['input_media_id'] ?? ''));
    $contentSlid = trim((string) ($itemBody['input_media_id'] ?? ''));
    $agentUuidPb = trim((string)($pmeta['agent_uuid'] ?? ''));
    $rejectedShapeSig = ff_shape_signature_normalize($meta['source_shape_signature'] ?? []);
    if ($rejectedShapeSig === []) {
        $rejectedShapeSig = [ff_shape_kind_for_content_type((string)($itemBody['type'] ?? 'video'))];
    }
    $suggestedNextShape = ff_suggest_changed_shape_signature($rejectedShapeSig);
    $probeTitle = trim((string)($itemBody['title'] ?? ''));
    $probePrompt = trim((string)($itemBody['prompt'] ?? ''));
    $probeText = formatforge_antfly_content_semantic_text($probeTitle, $probePrompt, trim($probeTitle . "\n" . $probePrompt));
    $probeImg = null;
    if (ff_shape_kind_for_content_type((string)($itemBody['type'] ?? '')) === 'image') {
        $ru = ff_content_item_effective_media_url($itemBody);
        $probeImg = $ru !== '' ? $ru : null;
    }
    $rejectExtra = [];
    if (formatforge_antfly_novelty_configured()) {
        $moldRep = ff_antfly_mold_fit_alignment_report($probeText, $probeImg);
        if ($moldRep !== null) {
            $rejectExtra['antfly_mold_fit_report'] = $moldRep;
        }
        $nearRej = antfly_closest_pipeline_ref($probeText, $probeImg);
        if ($nearRej !== null) {
            $rejectExtra['semantic_nearest_pipeline_to_rejected_output'] = $nearRej;
        }
    }
    $rejectExtra['pipeline_agent_side_by_side_v1'] = ff_pipeline_agent_side_by_side_v1_for_reject($itemBody, $authHeader);
    trigger_pipeline_cursor_agent('pipeline_content_rejected', array_merge([
        'intent' => 'edit_pipeline',
        'content_item_id' => $contentItemId,
        'pipeline_id' => $pipelineId,
        'pipeline_name' => $pipeline['name'] ?? '',
        'pipeline_subdir' => $subdir,
        'agent_uuid' => $agentUuidPb !== '' ? $agentUuidPb : null,
        'prompt_template' => $pipeline['prompt_template'] ?? '',
        'rejected_reason' => $reason,
        'consecutive_rejects' => $streak,
        'content_prompt' => $itemBody['prompt'] ?? '',
        'backing_input_media_id_on_pipeline' => $backingOnPipeline !== '' ? $backingOnPipeline : null,
        'content_item_source_link_id' => $contentSlid !== '' ? $contentSlid : null,
        'shape_change_required' => true,
        'rejected_shape_signature' => $rejectedShapeSig,
        'rejected_shape_signature_text' => ff_slot_signature_to_string($rejectedShapeSig),
        'suggested_new_shape_signature' => $suggestedNextShape,
        'suggested_new_shape_signature_text' => ff_slot_signature_to_string($suggestedNextShape),
    ], $rejectExtra), $authHeader);
}

/**
 * When true (default), queue the pipeline Cursor agent after system-detected pipeline generation failures
 * (e.g. binary failed to spawn, PHP tried to complete a pipeline row). Set CURSOR_AGENT_ON_PIPELINE_GENERATION_FAILURE=0 to disable.
 */
function ff_cursor_agent_on_pipeline_generation_failure_enabled(): bool {
    $e = getenv('CURSOR_AGENT_ON_PIPELINE_GENERATION_FAILURE');
    if ($e === false || trim((string) $e) === '') {
        return true;
    }
    return !in_array(strtolower(trim((string) $e)), ['0', 'false', 'no', 'off'], true);
}

/**
 * When true, also trigger the agent when `php index.php verify-pipeline-generation` leaves status=failed.
 * Default off so verify CLI noise does not spawn agents.
 */
function ff_cursor_agent_on_verify_pipeline_failure_enabled(): bool {
    $e = getenv('CURSOR_AGENT_ON_VERIFY_PIPELINE_FAILURE');
    return in_array(strtolower(trim((string) ($e ?: ''))), ['1', 'true', 'yes', 'on'], true);
}

/**
 * After a pipeline run fails in a path PHP controls, queue Cursor to fix the Go pipeline (same edit scope as reject flow).
 *
 * @param array|null $pipelineRecord PocketBase pipelines row when already loaded
 * @param list<string> $contentItemIds Affected content_items ids (may be empty)
 * @param string       $pipelineIdFallback Used to load the pipeline row via superuser when $pipelineRecord is null
 */
function ff_trigger_pipeline_agent_after_generation_failure(
    ?array $pipelineRecord,
    string $failurePhase,
    string $detail,
    array $contentItemIds,
    ?string $authHeader,
    string $pipelineIdFallback = ''
): void {
    if (!ff_cursor_agent_on_pipeline_generation_failure_enabled()) {
        ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'disabled', 'phase' => $failurePhase]);
        return;
    }
    if (empty($GLOBALS['CONFIG']['cursor_agent_enabled'])) {
        ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'cursor_agent_disabled', 'phase' => $failurePhase]);
        return;
    }
    if (!$authHeader) {
        ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'no_auth', 'phase' => $failurePhase]);
        return;
    }
    $detail = trim($detail);
    if ($detail === '') {
        $detail = 'Pipeline generation failed (no detail).';
    }
    $ids = [];
    foreach ($contentItemIds as $cid) {
        $t = trim((string) $cid);
        if ($t !== '') {
            $ids[] = $t;
        }
    }
    $firstId = $ids[0] ?? '';
    $logRef = $firstId !== '' ? $firstId : ('genfail-' . date('YmdHis'));

    $rec = $pipelineRecord;
    if (!is_array($rec) || trim((string) ($rec['id'] ?? '')) === '') {
        $pid = trim($pipelineIdFallback);
        if ($pid === '') {
            ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'no_pipeline_record', 'phase' => $failurePhase]);
            return;
        }
        $su = pb_superuser_auth_token();
        if (!$su['ok']) {
            ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'superuser_for_pipeline_fetch', 'phase' => $failurePhase]);
            return;
        }
        $gr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pid), null, $su['token']);
        if (($gr['code'] ?? 0) !== 200 || !is_array($gr['body'] ?? null)) {
            ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'pipeline_get_failed', 'phase' => $failurePhase, 'pipeline_id' => $pid]);
            return;
        }
        $rec = $gr['body'];
    }

    $pipelineId = trim((string) ($rec['id'] ?? ''));
    if ($pipelineId === '') {
        ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'empty_pipeline_id', 'phase' => $failurePhase]);
        return;
    }

    $subdir = ff_pipeline_subdir_from_pipeline_record($rec);
    $pmeta = is_array($rec['metadata'] ?? null) ? $rec['metadata'] : [];
    $agentUuidPb = trim((string) ($pmeta['agent_uuid'] ?? ''));
    $backingOnPipeline = trim((string) ($pmeta['backing_input_media_id'] ?? $pmeta['default_input_media_id'] ?? $pmeta['input_media_id'] ?? ''));

    $itemBody = [];
    if ($firstId !== '') {
        $gIt = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($firstId), null, $authHeader);
        if (($gIt['code'] ?? 0) === 200 && is_array($gIt['body'] ?? null)) {
            $itemBody = $gIt['body'];
            $im = is_array($itemBody['metadata'] ?? null) ? $itemBody['metadata'] : [];
            if (trim((string) ($im['origin'] ?? '')) === 'verify_pipeline_run' && !ff_cursor_agent_on_verify_pipeline_failure_enabled()) {
                ff_pipeline_trace_log('pipeline_generation_failure_agent_skip', ['reason' => 'verify_pipeline_run', 'phase' => $failurePhase]);
                return;
            }
        }
    }

    $reasonLine = '[generation_failure:' . $failurePhase . '] ' . $detail;
    formatforge_pipeline_record_rejection_feedback($pipelineId, $reasonLine, $logRef);

    $ctx = [
        'intent' => 'fix_generation_failure',
        'pipeline_id' => $pipelineId,
        'pipeline_name' => (string) ($rec['name'] ?? ''),
        'pipeline_subdir' => $subdir,
        'agent_uuid' => $agentUuidPb !== '' ? $agentUuidPb : null,
        'prompt_template' => (string) ($rec['prompt_template'] ?? ''),
        'rejected_reason' => $reasonLine,
        'consecutive_rejects' => 1,
        'content_prompt' => $itemBody !== [] ? (string) ($itemBody['prompt'] ?? '') : '',
        'backing_input_media_id_on_pipeline' => $backingOnPipeline !== '' ? $backingOnPipeline : null,
        'content_item_source_link_id' => $itemBody !== [] ? (trim((string) ($itemBody['input_media_id'] ?? '')) ?: null) : null,
        'generation_failure' => true,
        'failure_phase' => $failurePhase,
        'failure_detail' => $detail,
        'affected_content_item_ids' => $ids,
        'content_item_id' => $firstId !== '' ? $firstId : $logRef,
    ];
    if ($itemBody !== []) {
        $ctx['pipeline_agent_side_by_side_v1'] = ff_pipeline_agent_side_by_side_v1_for_reject($itemBody, $authHeader);
    }

    trigger_pipeline_cursor_agent('pipeline_generation_failed', $ctx, $authHeader);
    ff_pipeline_trace_log('pipeline_generation_failure_agent_triggered', [
        'pipeline_id' => $pipelineId,
        'phase' => $failurePhase,
        'content_item_ids' => $ids,
    ]);
}

/**
 * Normalize PATH for Cursor CLI subprocesses (php-fpm often has a tiny PATH).
 */
function ff_cursor_agent_path_env(): string {
    $path = getenv('PATH');
    if (!is_string($path) || trim($path) === '') {
        $path = '/usr/local/bin:/usr/bin:/bin:/sbin';
    } else {
        foreach (['/usr/local/bin', '/usr/bin', '/bin'] as $seg) {
            if (!str_contains($path, $seg)) {
                $path = $seg . ':' . $path;
            }
        }
    }
    return $path;
}

/**
 * HOME + XDG dirs for Cursor CLI when php-fpm user cannot write to default HOME (e.g. www-data: /var/www is root-owned).
 */
function ff_cursor_agent_home_env_vars(): array {
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $agentHome = isset($cfg['cursor_agent_home']) ? trim((string)$cfg['cursor_agent_home']) : '';
    if ($agentHome === '') {
        return [];
    }
    return [
        'HOME' => $agentHome,
        'XDG_CONFIG_HOME' => $agentHome . '/.config',
        'XDG_DATA_HOME' => $agentHome . '/.local/share',
        'XDG_STATE_HOME' => $agentHome . '/.local/state',
        'XDG_CACHE_HOME' => $agentHome . '/.cache',
    ];
}

/**
 * Minimal env for `agent` / `cursor-agent-run`: PATH, HOME/XDG (if CURSOR_AGENT_HOME), USER, optional API key.
 */
function ff_cursor_agent_env_minimal(): array {
    $out = ['PATH' => ff_cursor_agent_path_env()];
    foreach (ff_cursor_agent_home_env_vars() as $k => $v) {
        $out[$k] = $v;
    }
    if (!isset($out['HOME'])) {
        $home = getenv('HOME');
        if (is_string($home) && $home !== '') {
            $out['HOME'] = $home;
        }
    }
    foreach (['USER', 'LOGNAME', 'LANG', 'LC_ALL'] as $k) {
        $v = getenv($k);
        if (is_string($v) && $v !== '') {
            $out[$k] = $v;
        }
    }
    $key = getenv('CURSOR_API_KEY');
    if (is_string($key) && $key !== '') {
        $out['CURSOR_API_KEY'] = $key;
    }
    return $out;
}

/**
 * Full environment for proc_open (merges getenv() snapshot with minimal overrides).
 */
function ff_cursor_agent_env_for_proc_open(): array {
    $full = [];
    $snap = getenv();
    if (is_array($snap)) {
        foreach ($snap as $k => $v) {
            if (!is_string($k) || $v === false) {
                continue;
            }
            $full[$k] = is_scalar($v) ? (string)$v : '';
        }
    }
    foreach (ff_cursor_agent_env_minimal() as $k => $v) {
        $full[$k] = $v;
    }
    return $full;
}

/**
 * PATH/HOME/API key for the shell that starts `php … cursor-agent-run` (php-fpm often has a tiny PATH).
 */
function ff_shell_env_prefix_for_cursor_agent(): string {
    $parts = [];
    foreach (ff_cursor_agent_env_minimal() as $k => $v) {
        $parts[] = $k . '=' . escapeshellarg($v);
    }
    return implode(' ', $parts) . ' ';
}

/**
 * When set in CONFIG, FPM spawns the agent via sudo so it runs as another Unix user (e.g. deploy owner).
 */
function ff_cursor_agent_spawn_command(array $cfg, string $promptReal): ?string {
    $sudoUser = trim((string)($cfg['cursor_agent_sudo_user'] ?? ''));
    if ($sudoUser === '') {
        $php = ff_php_cli_binary();
        $self = __FILE__;
        return implode(' ', array_map('escapeshellarg', [$php, $self, 'cursor-agent-run', $promptReal]));
    }
    $wrapper = trim((string)($cfg['cursor_agent_run_wrapper'] ?? ''));
    if ($wrapper === '') {
        return null;
    }
    if (!is_file($wrapper) || !is_executable($wrapper)) {
        return null;
    }
    return 'sudo -n -u ' . escapeshellarg($sudoUser) . ' -- '
        . implode(' ', array_map('escapeshellarg', [$wrapper, $promptReal]));
}

/**
 * Queue a headless Cursor Agent run (`CURSOR_AGENT_MODEL`, default `google/nano-banana-pro`) after pipeline files exist.
 * Called from PHP web requests (POST fetch_link / reject_content) and CLI alike: exec + nohup so FPM can exit without killing the agent.
 */
function spawn_cursor_agent_background(string $promptFile): void {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['cursor_agent_enabled'])) {
        ff_pipeline_trace_log('cursor_agent_spawn_skip', ['reason' => 'cursor_agent_disabled']);
        return;
    }
    if (!function_exists('exec')) {
        ff_debug_log('cursor_agent_spawn_failed', ['reason' => 'exec_unavailable']);
        ff_pipeline_trace_log('cursor_agent_spawn_skip', ['reason' => 'exec_unavailable']);
        return;
    }
    $root = __DIR__;
    $promptReal = realpath($promptFile);
    if (!$promptReal || !is_file($promptReal) || strncmp($promptReal, $root, strlen($root)) !== 0) {
        ff_pipeline_trace_log('cursor_agent_spawn_skip', ['reason' => 'bad_prompt_path', 'given' => $promptFile]);
        return;
    }
    $prefix = ff_cursor_pipeline_prompts_dir() . DIRECTORY_SEPARATOR;
    if (strncmp($promptReal, $prefix, strlen($prefix)) !== 0) {
        ff_pipeline_trace_log('cursor_agent_spawn_skip', ['reason' => 'prompt_not_under_prompts_dir', 'basename' => basename($promptReal)]);
        return;
    }
    $php = ff_php_cli_binary();
    $cmd = ff_cursor_agent_spawn_command($cfg, $promptReal);
    if ($cmd === null) {
        $sudoUser = trim((string)($cfg['cursor_agent_sudo_user'] ?? ''));
        $wrapper = trim((string)($cfg['cursor_agent_run_wrapper'] ?? ''));
        ff_pipeline_trace_log('cursor_agent_spawn_skip', [
            'reason' => 'sudo_spawn_misconfigured',
            'cursor_agent_sudo_user' => $sudoUser,
            'cursor_agent_run_wrapper' => $wrapper,
            'wrapper_executable' => ($wrapper !== '' && is_file($wrapper) && is_executable($wrapper)),
        ]);
        return;
    }
    ff_cursor_agent_run_state_write($promptReal, [
        'status' => 'queued',
        'queued_at' => date('c'),
        'model' => ff_cursor_agent_model_from_cfg($cfg),
    ]);
    $logDir = ff_cursor_pipeline_dir();
    if (!is_dir($logDir) && !@mkdir($logDir, 0755, true)) {
        $logDir = sys_get_temp_dir();
    }
    $log = $logDir . '/cursor-agent.log';
    $envP = ff_shell_env_prefix_for_cursor_agent();
    if (PHP_OS_FAMILY === 'Windows') {
        @exec($envP . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1');
    } else {
        @exec($envP . 'nohup ' . $cmd . ' >> ' . escapeshellarg($log) . ' 2>&1 &');
    }
    $trace = [
        'prompt' => basename($promptReal),
        'log' => $log,
        'php_cli' => $php,
        'cursor_agent_output_format' => (string)($cfg['cursor_agent_output_format'] ?? 'text'),
        'cursor_agent_stream_partial_output' => !empty($cfg['cursor_agent_stream_partial_output']),
    ];
    $su = trim((string)($cfg['cursor_agent_sudo_user'] ?? ''));
    if ($su !== '') {
        $trace['cursor_agent_sudo_user'] = $su;
        $trace['cursor_agent_run_wrapper'] = (string)($cfg['cursor_agent_run_wrapper'] ?? '');
    }
    ff_debug_log('cursor_agent_spawn', $trace);
    ff_pipeline_trace_log('cursor_agent_spawn', $trace);
}

function trigger_pipeline_cursor_agent(string $reason, array $context, ?string $authHeader = null): void {
    $cfg = $GLOBALS['CONFIG'];
    if ($authHeader) {
        $extra = ff_cursor_agent_operating_context($authHeader);
        if ($extra !== []) {
            $context['operating_context'] = $extra;
        }
        if ($reason === 'novel_fetched_content' || $reason === 'novel_content') {
            $prompts = $context['fetched_index_prompts'] ?? [];
            if (is_array($prompts) && isset($prompts[0])) {
                $blob = trim((string) $prompts[0]);
                if ($blob !== '') {
                    $imgNear = trim((string)($context['primary_backing_media_url'] ?? ''));
                    $near = antfly_closest_pipeline_ref($blob, $imgNear !== '' ? $imgNear : null);
                    if ($near !== null) {
                        $context['semantic_nearest_pipeline_to_this_fetch'] = $near;
                    }
                }
            }
            $context['semantic_novelty_explainer'] = [
                'novel_distance_threshold' => (float) ($cfg['novel_threshold'] ?? 0.35),
                'meaning' => 'This task ran because the fetched item’s Antfly embedding (text plus optional primary_backing_media_url in the same multimodal space as pipeline_refs) was farther than novel_distance_threshold from every active pipeline template (or there are no active templates). Compare semantic_nearest_pipeline_to_this_fetch and antfly_mold_fit_report to see nearest templates and ranked mold alignment.',
            ];
        }
    }
    $dir = $cfg['cursor_pipeline_trigger_dir'] ?? '';
    if (!$dir) {
        ff_pipeline_trace_log('trigger_agent_skip', ['reason' => 'no_trigger_dir', 'agent_reason' => $reason]);
        return;
    }
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        ff_pipeline_trace_log('trigger_agent_skip', ['reason' => 'mkdir_trigger_dir_failed', 'dir' => $dir, 'agent_reason' => $reason]);
        return;
    }
    if (!is_writable($dir)) {
        ff_pipeline_trace_log('trigger_agent_skip', ['reason' => 'trigger_dir_not_writable', 'dir' => $dir, 'agent_reason' => $reason]);
        return;
    }
    $file = $dir . '/trigger_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.json';
    $payload = [
        'reason' => $reason,
        'context' => $context,
        'created' => date('c'),
        'template_path' => __DIR__ . '/pipelines/template',
    ];
    if (@file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT))) {
        ff_pipeline_trace_log('trigger_agent_written', ['trigger_file' => basename($file), 'agent_reason' => $reason]);
        $stem = ff_pipeline_agent_resolve_prompt_stem_from_trigger_payload($payload, $file);
        if (!ff_cursor_agent_queue_enabled()) {
            setup_pipeline_from_trigger($file, true);
        } else {
            ff_pipeline_agent_with_stem_lock($stem, function () use ($file, $stem, $reason) {
                if (ff_pipeline_agent_is_busy_for_prompt_stem($stem)) {
                    ff_pipeline_agent_queue_append($stem, $file, $reason);
                } else {
                    setup_pipeline_from_trigger($file, true);
                }
            });
        }
    } else {
        ff_pipeline_trace_log('trigger_agent_skip', ['reason' => 'trigger_file_write_failed', 'dir' => $dir, 'agent_reason' => $reason]);
    }
}

function setup_pipeline_from_trigger(string $triggerFile, bool $spawnCursorAgent = true): void {
    $projectRoot = __DIR__;
    $cfg = $GLOBALS['CONFIG'];
    $trigger = json_decode((string) file_get_contents($triggerFile), true) ?: [];
    $reason = $trigger['reason'] ?? 'unknown';
    $context = $trigger['context'] ?? [];
    $templatePath = $trigger['template_path'] ?? $projectRoot . '/pipelines/template';
    $created = $trigger['created'] ?? date('c');
    if (!is_dir($templatePath)) {
        ff_pipeline_trace_log('setup_pipeline_from_trigger_skip', ['reason' => 'template_dir_missing', 'template_path' => $templatePath, 'trigger' => basename($triggerFile)]);
        return;
    }
    $triggerBase = basename($triggerFile, '.json');
    $pipelineIdFromTrigger = str_replace('trigger_', '', $triggerBase);

    $cursorBase = ff_cursor_pipeline_dir();
    if (!is_dir($cursorBase)) {
        mkdir($cursorBase, 0755, true);
    }
    $promptsDir = ff_cursor_pipeline_prompts_dir();
    if (!is_dir($promptsDir)) {
        mkdir($promptsDir, 0755, true);
    }

    $reasonUsesResolvedSubdir = in_array($reason, ['pipeline_content_rejected', 'pipeline_edit_streak', 'content_rejected', 'pipeline_deleted', 'pipeline_generation_failed'], true);
    $resolvedSubdir = '';
    if ($reasonUsesResolvedSubdir) {
        $resolvedSubdir = ff_normalize_pipeline_subdir(trim((string)($context['pipeline_subdir'] ?? '')));
    }
    if ($reasonUsesResolvedSubdir && $resolvedSubdir === '') {
        $resolvedSubdir = ff_normalize_pipeline_subdir($pipelineIdFromTrigger);
        ff_pipeline_trace_log('setup_pipeline_from_trigger_subdir_fallback', [
            'reason' => $reason,
            'fallback' => $resolvedSubdir,
        ]);
    }

    if ($reasonUsesResolvedSubdir && $resolvedSubdir !== '') {
        $pipelineDir = $projectRoot . '/pipelines/' . $resolvedSubdir;
        $promptFile = $promptsDir . DIRECTORY_SEPARATOR . $resolvedSubdir . '.md';
    } else {
        $pipelineId = $pipelineIdFromTrigger;
        $pipelineDir = $projectRoot . '/pipelines/pipeline-' . $pipelineId;
        $promptFile = $promptsDir . DIRECTORY_SEPARATOR . 'pipeline-' . $pipelineId . '.md';
    }

    if (!is_dir($pipelineDir)) {
        mkdir($pipelineDir, 0755, true);
    }

    $pipelineSubdirName = basename($pipelineDir);
    $pipelineAgentScopeRule = "**Pipeline agent edit scope (strict):** Cursor **`--workspace`** is **only** **`pipelines/" . $pipelineSubdirName . "/`** — **`index.php`** and **`config.php`** are **not** in the workspace. Change files **only** under **`pipelines/" . $pipelineSubdirName . "/`** (Go sources, `go.mod` / `go.sum`, `source_analysis.md`, `pipeline_architecture.json`, `prompt_template.txt`, `README.md`, `FORMATFORGE_INDEX_SPAWN.md`, this pipeline’s `.env`, and optional helper scripts **kept in that same directory**). "
        . "Do **not** edit **`index.php`**, **`config.php`**, or **any path outside** **`pipelines/" . $pipelineSubdirName . "/`**. Do **not** edit **`.cursor-pipeline/prompts/*.md`** (FormatForge regenerates them on each trigger). "
        . "Update PocketBase **`pipelines`** (and related collections) via **Admin UI** or **HTTP API** (`curl` with admin credentials); do **not** add or change PHP in the main app to add maintainers. "
        . "You may **run** existing **`php index.php …`** commands **from the shell** when this task lists them; that does **not** require editing PHP sources.\n\n";

    $preferredAgentUuid = null;
    if ($reasonUsesResolvedSubdir) {
        $preferredAgentUuid = ff_validate_pipeline_agent_uuid(trim((string)($context['agent_uuid'] ?? '')));
        if ($preferredAgentUuid === null) {
            $pidCtx = trim((string)($context['pipeline_id'] ?? ''));
            if ($pidCtx !== '') {
                $su = pb_superuser_auth_token();
                if (!empty($su['ok']) && !empty($su['token'])) {
                    $grP = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pidCtx), null, $su['token']);
                    if (($grP['code'] ?? 0) === 200) {
                        $pmetaCtx = is_array($grP['body']['metadata'] ?? null) ? $grP['body']['metadata'] : [];
                        $preferredAgentUuid = ff_validate_pipeline_agent_uuid(trim((string)($pmetaCtx['agent_uuid'] ?? '')));
                    }
                }
            }
        }
    }
    $agentState = ff_ensure_pipeline_agent_state($pipelineDir, $preferredAgentUuid);
    $context['pipeline_agent'] = $agentState;
    $context['agent_uuid'] = $agentState['agent_uuid'] ?? '';
    if ($reasonUsesResolvedSubdir) {
        $pidSync = trim((string)($context['pipeline_id'] ?? ''));
        if ($pidSync !== '') {
            formatforge_pipeline_record_sync_agent_uuid($pidSync, (string)($agentState['agent_uuid'] ?? ''));
        }
    }

    $orchStep = max(1, min(3, (int)($agentState['execution_step'] ?? 1)));
    $context['orchestrator'] = [
        'system' => 'formatforge_index_php',
        'role' => 'The Orchestrator is this app; prompt chain steps are injected only by index.php from agent_state.execution_step.',
        'injected_step' => $orchStep,
        'total_steps' => 3,
        'pipeline_subdir' => basename($pipelineDir),
    ];

    $copyTemplateFiles = true;
    if ($reasonUsesResolvedSubdir && is_dir($pipelineDir)) {
        $entries = glob($pipelineDir . '/*') ?: [];
        if (count($entries) > 0) {
            $copyTemplateFiles = false;
        }
    }

    if ($copyTemplateFiles) {
        foreach (glob($templatePath . '/*') ?: [] as $f) {
            if (is_file($f)) {
                copy($f, $pipelineDir . '/' . basename($f));
            }
        }
        if (is_file($templatePath . '/.env.example')) {
            copy($templatePath . '/.env.example', $pipelineDir . '/.env.example');
        }
    }

    $envVars = ['REPLICATE_API_TOKEN', 'FAL_KEY', 'FAL_VIDEO_MODEL', 'VIDEO_PROVIDER', 'POCKETBASE_URL', 'GARAGE_ENDPOINT', 'GARAGE_PUBLIC_URL', 'GARAGE_PUBLIC_ROOT_DOMAIN', 'GARAGE_PUBLIC_SCHEME', 'GARAGE_ACCESS_KEY', 'GARAGE_SECRET_KEY', 'GARAGE_BUCKET', 'GARAGE_REGION'];
    $projectEnv = $projectRoot . '/.env';
    $pipelineEnvLines = [];
    if (is_file($projectEnv)) {
        $lines = file($projectEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, '#') === 0) continue;
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m) && in_array(trim($m[1]), $envVars)) $pipelineEnvLines[] = $line;
            if (preg_match('/^(FORMATFORGE_EMAIL|FORMATFORGE_PASSWORD)=(.*)$/', $line, $m)) $pipelineEnvLines[] = $m[1] . '=' . trim($m[2], " \t\"'");
            if (preg_match('/^(ADMIN_EMAIL|ADMIN_PASSWORD)=(.*)$/', $line, $m)) $pipelineEnvLines[] = ($m[1] === 'ADMIN_EMAIL' ? 'FORMATFORGE_EMAIL' : 'FORMATFORGE_PASSWORD') . '=' . trim($m[2], " \t\"'");
        }
    }
    file_put_contents($pipelineDir . '/.env', implode("\n", array_unique($pipelineEnvLines)) . "\n");
    ff_write_pipeline_index_spawn_contract($pipelineDir);

    $backingCardinalityRule = "**Backing content cardinality (required):** For pipeline runs tied to the **source list** / fetched **`content_items`**, the backing row(s) define **how many** outputs to produce and **what** to follow. "
        . "If the backing is a **carousel** with **N** distinct media items (e.g. 7 slides), the pipeline **must** generate **N** corresponding pieces in the **same order** (one generated item per backing slide). "
        . "If the backing is a **single video**, generate **one** video. "
        . "Stay **close** to the backing in subject, layout, and pacing — not a loosely related one-off.\n\n";
    $essenceRule = "**Essence over copy (required):** Capture the source content's **core idea / vibe / message**, but do **not** output near-duplicates of the fetched media. "
        . "Do not simply reuse the same backing image/video URL, single-frame copy, or tiny-filter clone. "
        . "Each generated piece must be a **new composition** (new layout/crop/scene treatment/typography/structure) while remaining recognizably aligned to the source intent.\n\n";
    $outputSophisticationRule = "**Output quality & modality (required):** **Default to AI** — use **Replicate** (or fal when configured) as the **primary** path for synthetic media; **choose models on [replicate.com](https://replicate.com)** using **run counts** on model pages (higher → safer default), then pin **version ids** in **`pipeline_architecture.json`** and code. "
        . "Add **ffmpeg**, TTS, or **offline render** (browser/canvas/diagram code) **only** when they clearly beat a model for that niche. "
        . "The bar is **intentional and polished**, not a vague single prompt with no architecture. "
        . "Document in **`prompt_template`** and pipeline code **what modality** you chose and why. "
        . "**`verify-pipeline-generation`** is **PHP + Replicate/fal** **smoke** only — run it **after** **`pipeline_architecture.json`** exists when your delivery path uses those APIs; otherwise document **README / CLI** checks.\n\n";
    $decomposedWorkflowRule = "**3-step workflow (required for novel / greenfield pipelines):** Follow the **injected orchestrator step** (`execution_step` 1→3). "
        . "**Do not** skip to a finished PocketBase **`pipelines`** row whose only purpose is **`verify-pipeline-generation`**. Order: (1) **`source_analysis.md`** → advance step → (2) **`pipeline_architecture.json`** (AI model steps first, optional local assembly) → advance step → (3) **implementation** + honest **`prompt_template`**. "
        . "Use **`php index.php cursor-agent-advance-step " . basename($pipelineDir) . "`** (or edit **`agent_state.json`**) when each artifact exists so **`index.php`** refreshes the injected step in **`.cursor-pipeline/prompts/`**.\n\n";
    $infographicRegenerationFlowRule = "**Infographic & static layout regeneration — default to simple (production default):** The most effective pattern is **not** an elaborate stack. Do this first: (1) Use the **public HTTPS URL** of the backing image from context (Garage / object-store **`media_url`**, often `https://*.sslip.io/*.png` or similar — same idea as a shareable object URL). (2) **Download or open that URL and look at the image** before changing **`prompt_template`** or Go (equivalent to a human writing the URL and “download this image and look at it”). (3) Pass that URL into the **image model** as **reference / `image_url` input**, plus a **short, clean** text brief (new topic, aspect ratio, layout contract). **Do not** add extra LLM preprocessing chains, enormous run prompts, ffmpeg/canvas pipelines, or heavy **`pipeline_architecture.json`** layering **unless** this minimal path fails. For **single-slide** infographic-style backing, **`backing_content_essence_v1`** and long **`source_analysis.md`** treatises are **optional** — prefer **one good URL + you actually saw the pixels + one clear brief**.\n\n";
    $auForTask = (string)($agentState['agent_uuid'] ?? '');
    $subLabelForTask = basename($pipelineDir);
    if ($reason === 'pipeline_deleted') {
        $pid = (string)($context['pipeline_id'] ?? '');
        $pname = (string)($context['pipeline_name'] ?? '');
        $deletedCount = (int)($context['deleted_content_count'] ?? 0);
        $task = $pipelineAgentScopeRule . "**DELETE / CLEAN UP a pipeline implementation** — this pipeline row was deleted from the frontend.\n\n"
            . "1. Target former pipeline id: `$pid`" . ($pname !== '' ? " (name: $pname)" : '') . ".\n"
            . "2. Pipeline directory to remove if present: `$pipelineDir` (delete remaining files, scripts, and local build artifacts).\n"
            . "3. Remove any cron/system task references for this pipeline directory if present in project-owned files (do not edit unrelated host config blindly).\n"
            . "4. Remove pipeline-local docs under **`$pipelineDir`** (e.g. **`rejection_notes.md`**) if they only applied to this pipeline. Do **not** edit **`.cursor-pipeline/prompts/*.md`**. Keep other pipelines untouched.\n"
            . "5. Do **not** recreate the `pipelines` row. This is cleanup only.\n"
            . "6. Keep the same agent identity for traceability (`agent_uuid`), but finish with no active files left under this pipeline subdir.\n";
    } elseif ($reasonUsesResolvedSubdir) {
        $pid = (string)($context['pipeline_id'] ?? '');
        $pname = (string)($context['pipeline_name'] ?? '');
        $streak = (int)($context['consecutive_rejects'] ?? 0);
        $genFail = ($reason === 'pipeline_generation_failed');
        $editHead = $genFail
            ? "**FIX after generation failure** — a pipeline run did not finish successfully (binary did not start, worker error, or PHP path blocked). Use JSON **`failure_phase`**, **`failure_detail`**, **`affected_content_item_ids`**, and PocketBase **`rejected_reason`** on the **`content_items`** row(s). Check **`garage_url`** / **`garage_key`** / **`media_file`** vs public Garage (e.g. broken **`…sslip.io/….png`**). **`pipeline_agent_side_by_side_v1`** compares backing vs output when a failed row was loaded. **If `pipeline_agent_workspace_media_v1` lists files, visually inspect each `relative_path` in `agent_media/`** (do not rely on error text alone).\n\n"
            : "**EDIT an existing pipeline** — user rejected output ({$streak} consecutive reject(s) for this pipeline in context).\n\n";
        $task = $pipelineAgentScopeRule . $editHead
            . $infographicRegenerationFlowRule
            . $backingCardinalityRule
            . $essenceRule
            . $outputSophisticationRule
            . "**Context:** Use JSON **`operating_context`** when present for **`target_posts_per_day` / `published_count_last_24h`**, **`recent_published`**, samples, and **`rejected_reason`** / **`content_prompt`**. **`pipeline_agent_workspace_media_v1` (required when present):** **You must visually inspect** every `files[]` entry with `ok: true` — open `relative_path` under **`agent_media/`** and look at the actual image or video (pixels/frames), **before** editing **`prompt_template`** or code. **Do not** treat curator text as a substitute for seeing the media. **Always read `pipeline_agent_side_by_side_v1`:** it has **`comparison_table_rows`** (machine-parseable) plus **`markdown_side_by_side`** and parallel **`INPUT_REFERENCE` / `OUTPUT_GENERATED`** snapshots (titles, prompts, **`media_url`**). If workspace copies failed, use **`media_url`** / **`pocketbase_files_url`** from the table and open or download so you still **see** both sides. **`media_url` prefers public Garage** (from **`garage_key`** / **`GARAGE_PUBLIC_*`**) so you can **`curl` or preview** without PocketBase auth; **`pocketbase_files_url`** is only present when the PB file URL differs. When present, use **`semantic_nearest_pipeline_to_rejected_output`** as optional context only; visual comparison remains the primary signal.\n\n"
            . "**Clean model prompts (required):** PHP stores one string on **`content_items.prompt`** and passes it as **`FORMATFORGE_RUN_PROMPT`** / **`pipeline_input.json` → `run_prompt`** to your **`pipeline-generate`** binary. Anything in that string is treated as input to **image/video APIs** and to the variation LLM. Keep that path **clean**: natural-language scene, layout, and style only (from **`prompt_template`** plus structured slot/variation data). **Do not** forward **`[generation_failure:…]`**, missing-binary messages, OpenRouter parse errors, env keys, or “switch to model X” ops notes into the API prompt — fix **Go**, **`.env`**, or **build** instead. **`content_prompt`** in the JSON below may include legacy noise; use it as diagnosis, not as text to echo into providers.\n\n"
            . "1. PocketBase **`pipelines`** record id: `$pid`" . ($pname !== '' ? " (name: $pname)" : '') . " — update **`prompt_template`** and related fields to address the rejection.\n"
            . "   **Metadata `backing_input_media_id`:** If context has **`content_item_source_link_id`** but **`backing_input_media_id_on_pipeline`** is empty, PATCH **`metadata`** (merge JSON) to set **`backing_input_media_id`** = **`content_item_source_link_id`** so **`formatforge_generate_content_finish`** can merge fetched **`source_links`** backing into every **`generating`** run. Preserve existing **`metadata`** keys.\n"
            . "   **Shape change after reject (required):** If context has **`shape_change_required=true`**, you must update the pipeline’s default shape (for example `pipeline_formats.slot_signature`) to a signature that is **different** from **`rejected_shape_signature_text`**. Keep backing cardinality constraints, but do not keep the same rejected slot pattern.\n"
            . "2. **Rejection notes (in pipeline dir only):** Add or update **`pipelines/$subLabelForTask/rejection_notes.md`** (or a **Lessons** subsection in **`README.md`**) with short standing constraints — do **not** edit **`.cursor-pipeline/prompts/*.md`** (regenerated by the app).\n"
            . "3. Pipeline dir: `$pipelineDir` — align `.env` / **Go**; build when you change code: `cd $pipelineDir && go build -o pipeline-generate .`\n"
            . "4. **Verification** — if the pipeline is still **Replicate/fal video–driven**, do **not** report finished until **`php index.php verify-pipeline-generation $pid`** exits **0** (smoke test: one `[verify]` **content_items** row with media). "
            . "If you moved to a **composed / non–text-to-video** architecture, document the check in **`README.md`** instead of forcing a misleading verify.\n"
            . "5. Do **not** create a duplicate **`pipelines`** row unless clearly required.\n"
            . "6. **Same Cursor agent chat:** Keep **`agent_uuid`** in **`pipelines/$subLabelForTask/agent_state.json`** and PocketBase **`metadata.agent_uuid`** **identical**—do **not** rotate UUIDs. In Cursor use **`/resume-agent $auForTask`** (or **`/resume-agent $subLabelForTask`**) to resume the **same** agent thread. When PATCHing **`pipelines`**, merge **`metadata`** and preserve **`metadata.agent_uuid`** = **`$auForTask`** unless you are fixing a sync drift.";
    } elseif ($reason === 'novel_fetched_content' || $reason === 'novel_content') {
        $task = $pipelineAgentScopeRule . "**CREATE a new pipeline** — fetched item was flagged as requiring a new pipeline by the app context and curator flow.\n\n"
            . $decomposedWorkflowRule
            . $infographicRegenerationFlowRule
            . $backingCardinalityRule
            . $essenceRule
            . $outputSophisticationRule
            . "**Context:** When **`backing_content_essence_v1`** is present, FormatForge already ran **OpenRouter → Gemini** (Essence Extractor) on the fetched **images/videos** (before this agent). If **`ok`** is true, treat **`essence_markdown`** as the primary brief — it must follow four sections: **The Core Dynamic (The \"Why\")**, **The Structural Metaphor (The \"How\")**, **The Aesthetic & Tone (The \"Vibe\")**, **The Transmutation Prompt** (downstream prompt with **`[Insert New Topic Here]`** — substitute a real new topic in your pipeline work). Align **`source_analysis.md`** and **`prompt_template`** with it and follow **`instruction`** inside that object. If missing or **`ok`** is false, rely on slots and pixels only. Read **`semantic_novelty_explainer`** and **`semantic_nearest_pipeline_to_this_fetch`** when present. When **`pipeline_agent_fetched_slots_catalog_v1`** is present, use **`slots`** (ordered) and **`markdown_slots_table`** to see every backing **`media_url`** / title / type before writing **`source_analysis.md`**. **`pipeline_agent_workspace_media_v1`** lists matching **`agent_media/`** files when copies succeeded — **open each `relative_path` and visually inspect** backing images/video **while** drafting **`source_analysis.md`** (not only titles/URLs). Use **`operating_context`** for **`pipelines_most_recent`**, **`target_posts_per_day`** vs **`published_count_last_24h`**, **`recent_published`** + metrics, and **`recent_fetched_items_sample`** vs **`recent_pipeline_generated_items_sample`** to align style and posting cadence. "
            . "If **`suggested_pipelines_output_type`** is **`carousel`** or **`fetched_content_items_count`** > **1**, set PocketBase **`pipelines.output_type`** to **`carousel`** (batch / multi-slide workflow). Individual **`content_items`** may still use **`reel`** per slide per app rules — **carousel** here labels the **pipeline**, not each file’s MIME type.\n\n"
            . "1. New pipeline dir: `$pipelineDir`\n"
            . "2. .env copied from template. Build: `cd $pipelineDir && go build -o pipeline-generate .`\n"
            . "3. Crontab (optional until the binary matches your architecture): `0 * * * * cd $pipelineDir && set -a && . .env && set +a && ./pipeline-generate`\n"
            . "4. Add a **`pipelines`** row (superuser/admin API) **after** you have **`source_analysis.md`** + **`pipeline_architecture.json`** on disk (or are actively iterating with those files committed in the same dir). **`prompt_template`** must describe the **real pipeline** (Replicate/fal models **with version ids**, optional **ffmpeg**/TTS, etc.), **not** a lone vague creative line. The template and any Go/cron logic must encode the **N-for-N carousel / 1-for-1 video** rule above. **When context includes **`backing_input_media_id`**, set **`metadata.backing_input_media_id`** to that value** (PocketBase **`source_links`** id for this fetch) so **`formatforge_generate_content_finish`** auto-merges fetched titles/order into every **`generating`** run without duplicating URLs in **`prompt_template`**. Preserve **`metadata.novel_trigger`** / **`pipeline_subdir`** if you set them. Set **`metadata.agent_uuid`** to **`pipeline_agent.agent_uuid`** from this prompt’s JSON context (same value as **`pipelines/.../agent_state.json`**) so **`/resume-agent`** can resolve the pipeline in PocketBase.\n"
            . "5. **Verify** only when honest: run **`cd $projectRoot && php index.php verify-pipeline-generation <new_pipelines_record_id>`** only if your **`pipeline_architecture.json`** expects **PHP + Replicate/fal** as the delivery path — exit **0** is a **smoke test**, not proof of a good pipeline. Otherwise document **`README.md`** verification for your composed workflow.\n";
    } else {
        $task = $pipelineAgentScopeRule . "Pipeline maintenance.\n\n"
            . "**Visual:** If **`pipeline_agent_workspace_media_v1`** lists any **`files`** under **`agent_media/`**, **open each successful `relative_path` and inspect the media** before changing **`prompt_template`** or code.\n\n"
            . $infographicRegenerationFlowRule
            . $backingCardinalityRule . $essenceRule . $outputSophisticationRule
            . "1. Pipeline dir: `$pipelineDir`\n2. Build: `cd $pipelineDir && go build -o pipeline-generate .`\n3. Update PocketBase **`pipelines`** as needed.";
    }
    ff_pipeline_agent_materialize_workspace_media($pipelineDir, $context);
    $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $agentModel = ff_cursor_agent_model_from_cfg($cfg);
    $agentModelNote = ff_cursor_agent_model_prompt_parenthetical($agentModel);
    $agentUuid = (string) ($agentState['agent_uuid'] ?? '');
    $directiveBlock = "\n" . ff_pipeline_orchestrator_wrap_injection(ff_pipeline_orchestrator_injection_block($orchStep, $pipelineSubdirName));
    $readmeSection = ff_pipeline_readme_injection_section($templatePath, $pipelineDir);
    $agentIntroSuffix = $reasonUsesResolvedSubdir
        ? " (stable across **edit** triggers; loaded from PocketBase **`metadata.agent_uuid`** when present, else kept from disk). "
        : " (generated on first setup). ";
    $promptContent = "# FormatForge pipeline setup\n\n**Workspace:** Cursor **`--workspace`** is **`$pipelineDir`** only — **`index.php`** is **not** visible. Orchestrator prompt files and logs live under **`.cursor-pipeline/`** on the server (outside this workspace). Read **`FORMATFORGE_INDEX_SPAWN.md`** here for how **`index.php`** invokes **`pipeline-generate`**. Local copies of backing and generated media (when available) are under **`agent_media/`** — see **`pipeline_agent_workspace_media_v1`** in the JSON below.\n\n**Mandatory visual review:** Whenever **`pipeline_agent_workspace_media_v1.files`** includes rows with **`ok: true`**, you **must** open each **`relative_path`** in this workspace and **inspect the actual media** (look at images; watch or skim video). Applies to **create**, **edit**, **reject**, **generation-failure**, and **maintenance** triggers. **Do not** rely on JSON, URLs, or markdown tables alone when on-disk copies exist. If copies failed, use **`urls_tried`** / **`media_url`** fields and still **view** the assets in a browser or via **`curl` → file**.\n\n**Infographic / layout regeneration:** Prefer the **simple path** from **Task** — public backing **`media_url`** → **download or open and look** → **short brief** + that URL to the image API. Avoid unnecessary complexity unless that path fails.\n\n**Trigger:** $reason\n**Created:** $created\n\n**Pipeline agent:** UUID **`$agentUuid`** — persisted in **`pipelines/$pipelineSubdirName/agent_state.json`**" . $agentIntroSuffix . "Use **`/resume-agent $agentUuid`** or **`/resume-agent $pipelineSubdirName`** to reopen the **same** Cursor agent chat (UUID is the stable thread key).\n"
        . $readmeSection
        . $directiveBlock
        . "\n## Context\n\n```json\n$contextJson\n```\n\n## Task\n\n$task\n\n## Cursor Agent (headless)\n\nFormatForge spawns the [Cursor CLI](https://cursor.com/cli) **`agent`** with **`-p`** (print), **`--trust`**, **`-f`** (force), **`--model $agentModel`** $agentModelNote, **`--workspace`** = **`$pipelineDir`** (this pipeline only — **`index.php`** is not in the workspace).\n\nManual re-run (full paths; orchestrator prompt may live under **`.cursor-pipeline/prompts/`**):\n\n```bash\nagent -p --trust -f --model $agentModel --workspace \"$pipelineDir\" \"Execute every step in: $promptFile\"\n```\n\n**Orchestrator (system):** The **`<!-- FF_ORCHESTRATOR_INJECTION -->`** region is written only by **`index.php`** from **`pipelines/$pipelineSubdirName/agent_state.json`** → **`execution_step`** (this run: **Step $orchStep** of 3). Re-sync that block without a new trigger: **`php index.php pipeline-orchestrator-refresh-prompt $pipelineSubdirName`** (run from repo root on the host).\n\nAuth: usually **`agent login`** as the PHP-FPM user (no API key). Optional **`CURSOR_API_KEY`** in `.env`. Logs: **`.cursor-pipeline/cursor-agent.log`**.\n";
    file_put_contents($promptFile, $promptContent);
    if ($spawnCursorAgent) {
        ff_pipeline_trace_log('setup_pipeline_from_trigger_prompt_ready', [
            'prompt_basename' => basename($promptFile),
            'pipeline_subdir' => basename($pipelineDir),
            'cursor_agent_workspace' => $pipelineDir,
            'agent_uuid' => $agentState['agent_uuid'] ?? '',
            'execution_step' => $orchStep,
            'orchestrator_injection' => 'index_php',
            'trigger_reason' => $reason,
        ]);
        spawn_cursor_agent_background($promptFile);
    } else {
        ff_pipeline_trace_log('setup_pipeline_from_trigger_no_spawn', ['prompt_basename' => basename($promptFile), 'trigger_reason' => $reason]);
    }
}

/**
 * Re-check create-pipeline trigger once all fetched media for a source_link are reviewed.
 * Called on approve/reject of fetched content rows.
 */
function ff_maybe_trigger_create_pipeline_after_review(string $sourceLinkId, ?string $authHeader): void {
    $sourceLinkId = trim($sourceLinkId);
    if ($sourceLinkId === '' || !$authHeader) {
        return;
    }
    $slr = pb_request('GET', '/api/collections/input_media/records/' . rawurlencode($sourceLinkId), null, $authHeader);
    if (($slr['code'] ?? 0) !== 200) {
        return;
    }
    $sl = is_array($slr['body'] ?? null) ? $slr['body'] : [];
    $slMeta = is_array($sl['metadata'] ?? null) ? $sl['metadata'] : [];
    if (trim((string)($slMeta['pipeline_create_triggered_at'] ?? '')) !== '') {
        return;
    }
    $rows = array_values(array_filter(
        ff_pb_content_items_for_source_link($authHeader, $sourceLinkId),
        fn($it) => is_array($it) && ff_content_item_is_fetched_for_snapshot($it)
    ));
    if ($rows === []) {
        return;
    }
    $reviewPending = array_values(array_filter($rows, function ($it) {
        $st = strtolower(trim((string)($it['status'] ?? '')));
        return !in_array($st, ['approved', 'rejected'], true);
    }));
    if ($reviewPending !== []) {
        return;
    }
    $shapeSig = array_values(array_map(
        fn($it) => ff_shape_kind_for_content_type((string)($it['type'] ?? '')),
        $rows
    ));
    $prompts = is_array($slMeta['pending_novel_prompts'] ?? null)
        ? array_values(array_filter(array_map('strval', $slMeta['pending_novel_prompts']), fn($v) => trim($v) !== ''))
        : [];
    if ($prompts === []) {
        $active = fetch_active_pipeline_row_count($authHeader);
        foreach ($rows as $it) {
            $title = trim((string)($it['title'] ?? ''));
            $srcUrl = trim((string)($sl['url'] ?? $it['prompt'] ?? ''));
            $blob = formatforge_antfly_content_semantic_text($title, $srcUrl, trim($title . "\n" . $srcUrl));
            $imgUrl = '';
            if (ff_shape_kind_for_content_type((string)($it['type'] ?? '')) === 'image') {
                $imgUrl = ff_content_item_effective_media_url($it);
            }
            if ($blob !== '' && fetched_text_is_novel_vs_pipelines($blob, $active, $imgUrl !== '' ? $imgUrl : null)) {
                $prompts[] = $blob;
            }
        }
    }
    if ($prompts === []) {
        ff_pipeline_trace_log('create_pipeline_after_fetch_skip', [
            'reason' => 'no_novel_prompts_after_full_review',
            'input_media_id' => $sourceLinkId,
        ]);
        return;
    }
    maybe_trigger_cursor_create_pipeline_after_fetch(
        $prompts,
        $authHeader,
        $sourceLinkId,
        trim((string)($sl['url'] ?? '')),
        count($rows),
        trim((string)($slMeta['social_account_id'] ?? ($sl['social_account_id'] ?? ''))),
        $shapeSig
    );
}

// CLI: php index.php check-instagram
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'check-instagram') {
    $email = getenv('ADMIN_EMAIL') ?: getenv('FORMATFORGE_EMAIL');
    $pass = getenv('ADMIN_PASSWORD') ?: getenv('FORMATFORGE_PASSWORD');
    if (!$email || !$pass) {
        fwrite(STDERR, "Set ADMIN_EMAIL/ADMIN_PASSWORD or FORMATFORGE_EMAIL/FORMATFORGE_PASSWORD in .env\n");
        exit(1);
    }
    $auth = pb_request('POST', '/api/collections/' . $GLOBALS['CONFIG']['users_collection'] . '/auth-with-password', [
        'identity' => $email,
        'password' => $pass,
    ]);
    if ($auth['code'] < 200 || $auth['code'] >= 300) {
        fwrite(STDERR, "Auth failed: " . ($auth['body']['message'] ?? json_encode($auth['body'])) . "\n");
        exit(1);
    }
    $token = $auth['body']['token'] ?? '';
    if (!$token) {
        fwrite(STDERR, "No token in auth response\n");
        exit(1);
    }
    $list = pb_request('GET', '/api/collections/social_accounts/records?perPage=50', null, $token);
    if ($list['code'] < 200 || $list['code'] >= 300) {
        fwrite(STDERR, "List failed: " . ($list['body']['message'] ?? json_encode($list['body'])) . "\n");
        exit(1);
    }
    $items = $list['body']['items'] ?? [];
    echo "Instagram accounts: " . count($items) . "\n";
    foreach ($items as $a) {
        $username = $a['username'] ?? '(no username)';
        $id = $a['id'] ?? '';
        $active = ($a['is_active'] ?? true) ? 'active' : 'inactive';
        echo "  - @$username (id: $id, $active)\n";
    }
    exit(0);
}

// CLI: php index.php pipeline-generate-once <pipeline_id> <social_account_id>
// Used by pipeline-cron-tick (subprocess) and manual runs; uses superuser auth (ADMIN_EMAIL / ADMIN_PASSWORD).
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'pipeline-generate-once') {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $authHeader = 'Bearer ' . $auth['token'];
    $pipeId = trim((string) ($argv[2] ?? ''));
    $accId = trim((string) ($argv[3] ?? ''));
    if ($pipeId === '' || $accId === '') {
        fwrite(STDERR, "Usage: php index.php pipeline-generate-once <pipeline_id> <social_account_id>\n");
        exit(1);
    }
    $post = [
        'action' => 'generate_content',
        'pipeline_id' => $pipeId,
        'prompt' => '',
        'source_id' => '',
        'account_id' => $accId,
        'type' => 'reel',
    ];
    formatforge_generate_content_action($post, $authHeader);
    exit(0);
}

// CLI: php index.php pipeline-cron-tick [--force] — one cadence tick (PIPELINE_CRON_* env, superuser auth). --force: run even if PIPELINE_CRON_ENABLED is off, skip cadence + auto-post saturation filter.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'pipeline-cron-tick') {
    $forceCron = false;
    foreach (array_slice($argv, 2) as $a) {
        if ($a === '--force' || $a === '-f') {
            $forceCron = true;
        }
    }
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $authHeader = 'Bearer ' . $auth['token'];
    $r = ff_pipeline_cron_tick($authHeader, ['force' => $forceCron]);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php auto-post-tick — fill schedule + publish due rows (AUTO_POST_* env, superuser auth)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'auto-post-tick') {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $authHeader = 'Bearer ' . $auth['token'];
    $r = ff_auto_post_tick($authHeader);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php pipeline-agent-drain-queue <prompt_stem> — run next queued trigger (e.g. after a stuck agent); prompt_stem = basename of .cursor-pipeline/prompts/*.md without .md
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'pipeline-agent-drain-queue') {
    $stem = ff_normalize_pipeline_subdir(trim((string) ($argv[2] ?? '')));
    if ($stem === '') {
        fwrite(STDERR, "Usage: php index.php pipeline-agent-drain-queue <prompt_stem>\nExample: pipeline-20260321060557_afca5455\n");
        exit(1);
    }
    ff_pipeline_agent_drain_queue_after_prompt_stem($stem);
    echo "drain invoked for stem: {$stem}\n";
    exit(0);
}

// CLI: php index.php repair-source-links-schema
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'repair-source-links-schema') {
    $r = repair_source_links_schema();
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php repair-content-items-media-schema — add media_file (file) to content_items for /api/files/… URLs
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'repair-content-items-media-schema') {
    $r = repair_content_items_media_schema();
    echo json_encode($r, JSON_PRETTY_PRINT) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php probe-garage — signed PUT using GARAGE_* from .env (same code path as uploads)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'probe-garage') {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['garage_key']) || empty($cfg['garage_secret'])) {
        fwrite(STDERR, "Missing GARAGE_ACCESS_KEY / GARAGE_SECRET_KEY in environment.\n");
        exit(1);
    }
    $key = '_formatforge_probe_' . gmdate('Ymd\THis\Z') . '.txt';
    $url = s3_upload($key, "garage probe " . gmdate('c'), 'text/plain');
    if ($url) {
        $pubHint = trim((string) ($cfg['garage_public_url'] ?? ''));
        echo "OK signed PUT\nendpoint: {$cfg['garage_endpoint']}\nbucket: {$cfg['garage_bucket']}\nkey: $key\npublic_url: $url\n";
        if ($pubHint === '') {
            echo "(Tip: set GARAGE_PUBLIC_URL or GARAGE_PUBLIC_ROOT_DOMAIN — e.g. GARAGE_PUBLIC_ROOT_DOMAIN=100.x.x.sslip.io builds https://{$cfg['garage_bucket']}.web.100.x.x.sslip.io)\n";
        }
        exit(0);
    }
    fwrite(STDERR, "FAIL: s3_upload() returned null. PHP cannot complete SigV4 PUT to GARAGE_ENDPOINT — check GARAGE_ENDPOINT is reachable from this host (e.g. http://127.0.0.1:3900), keys/bucket, and firewall.\n");
    exit(1);
}

// CLI: php index.php rewrite-garage-urls [--dry-run] — PATCH content_items.garage_url from garage_key using current GARAGE_PUBLIC_* config
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'rewrite-garage-urls') {
    $dry = in_array('--dry-run', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Need ADMIN_EMAIL / ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $cfg = $GLOBALS['CONFIG'];
    if (trim((string) ($cfg['garage_public_url'] ?? '')) === '') {
        fwrite(STDERR, "Set GARAGE_PUBLIC_URL or GARAGE_PUBLIC_ROOT_DOMAIN (and GARAGE_BUCKET) in .env so public URLs can be computed.\n");
        exit(1);
    }
    $auth = 'Bearer ' . $token;
    $page = 1;
    $perPage = 100;
    $changed = 0;
    $unchanged = 0;
    $noKey = 0;
    while (true) {
        $qs = http_build_query(['perPage' => $perPage, 'page' => $page, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $auth);
        if ($r['code'] !== 200) {
            fwrite(STDERR, "List failed: " . ($r['body']['message'] ?? 'HTTP ' . $r['code']) . "\n");
            exit(1);
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string) ($it['id'] ?? '');
            $gk = trim((string) ($it['garage_key'] ?? ''));
            $old = trim((string) ($it['garage_url'] ?? ''));
            if ($id === '') {
                continue;
            }
            if (trim((string) ($it['media_file'] ?? '')) !== '') {
                continue;
            }
            if ($gk === '') {
                $noKey++;
                continue;
            }
            $new = garage_public_url_for_key($gk);
            if ($new === $old) {
                $unchanged++;
                continue;
            }
            echo ($dry ? '[dry-run] ' : '') . "id={$id}\n  old: {$old}\n  new: {$new}\n";
            if (!$dry) {
                $patch = pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($id), ['garage_url' => $new], $auth);
                if ($patch['code'] < 200 || $patch['code'] >= 300) {
                    fwrite(STDERR, "PATCH failed: " . ($patch['body']['message'] ?? json_encode($patch['body'])) . "\n");
                    exit(1);
                }
            }
            $changed++;
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    echo 'done: ' . ($dry ? 'would update ' : 'updated ') . "{$changed}, unchanged {$unchanged}, no garage_key {$noKey}\n";
    exit(0);
}

// CLI: php index.php sync-pb-garage-urls [--dry-run] — PATCH garage_url to PocketBase /api/files/… when media_file is set (fixes sslip.io Garage URLs in DB)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'sync-pb-garage-urls') {
    $dry = in_array('--dry-run', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Need ADMIN_EMAIL / ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $auth = 'Bearer ' . $token;
    $collId = ff_content_items_collection_id();
    if ($collId === '') {
        fwrite(STDERR, "Could not resolve output_media collection id. Set POCKETBASE_OUTPUT_MEDIA_COLLECTION_ID (or legacy POCKETBASE_CONTENT_ITEMS_COLLECTION_ID) in .env (PocketBase Admin → Collections → output_media → collection id).\n");
        exit(1);
    }
    $page = 1;
    $perPage = 100;
    $changed = 0;
    $skipped = 0;
    while (true) {
        $qs = http_build_query(['perPage' => $perPage, 'page' => $page, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $auth);
        if ($r['code'] !== 200) {
            fwrite(STDERR, "List failed: " . ($r['body']['message'] ?? 'HTTP ' . $r['code']) . "\n");
            exit(1);
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string) ($it['id'] ?? '');
            $fn = trim((string) ($it['media_file'] ?? ''));
            if ($id === '' || $fn === '') {
                $skipped++;
                continue;
            }
            $new = ff_pb_public_file_url($collId, $id, $fn);
            if ($new === '') {
                $skipped++;
                continue;
            }
            $old = trim((string) ($it['garage_url'] ?? ''));
            if ($old === $new) {
                $skipped++;
                continue;
            }
            echo ($dry ? '[dry-run] ' : '') . "id={$id}\n  old: {$old}\n  new: {$new}\n";
            if (!$dry) {
                $patch = pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($id), ['garage_url' => $new], $auth);
                if ($patch['code'] < 200 || $patch['code'] >= 300) {
                    fwrite(STDERR, "PATCH failed: " . ($patch['body']['message'] ?? json_encode($patch['body'])) . "\n");
                    exit(1);
                }
            }
            $changed++;
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    echo 'done: ' . ($dry ? 'would update ' : 'updated ') . "{$changed} garage_url(s) (skipped {$skipped} rows without media_file or already matching).\n";
    exit(0);
}

// CLI: php index.php delete-bad-garage-urls [--apply] — DELETE content_items whose garage_url uses loopback; also source_links when no sibling content_items remain
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'delete-bad-garage-urls') {
    $apply = in_array('--apply', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Need ADMIN_EMAIL / ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $auth = 'Bearer ' . $token;
    $page = 1;
    $perPage = 100;
    $bad = [];
    while (true) {
        $qs = http_build_query(['perPage' => $perPage, 'page' => $page, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $auth);
        if ($r['code'] !== 200) {
            fwrite(STDERR, "List failed: " . ($r['body']['message'] ?? 'HTTP ' . $r['code']) . "\n");
            exit(1);
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string) ($it['id'] ?? '');
            $u = trim((string) ($it['garage_url'] ?? ''));
            if ($id === '' || !ff_garage_url_uses_loopback_host($u)) {
                continue;
            }
            $bad[] = [
                'id' => $id,
                'garage_url' => $u,
                'input_media_id' => trim((string) ($it['input_media_id'] ?? '')),
            ];
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    $badIds = [];
    foreach ($bad as $b) {
        $badIds[$b['id']] = true;
    }
    $sourceLinkIdsToRemove = [];
    $seenSl = [];
    foreach ($bad as $b) {
        $slid = $b['input_media_id'];
        if ($slid === '' || isset($seenSl[$slid])) {
            continue;
        }
        $seenSl[$slid] = true;
        $allForLink = ff_pb_content_item_ids_for_source_link($auth, $slid);
        if ($allForLink === false) {
            fwrite(STDERR, "List content_items by input_media_id failed: {$slid}\n");
            exit(1);
        }
        if ($allForLink === []) {
            continue;
        }
        $allBad = true;
        foreach ($allForLink as $cid) {
            if (!isset($badIds[$cid])) {
                $allBad = false;
                break;
            }
        }
        if ($allBad) {
            $sourceLinkIdsToRemove[] = $slid;
        }
    }
    if ($apply) {
        foreach ($bad as $row) {
            $id = $row['id'];
            $del = pb_request('DELETE', '/api/collections/output_media/records/' . rawurlencode($id), null, $auth);
            if ($del['code'] < 200 || $del['code'] >= 300) {
                fwrite(STDERR, "DELETE content_items failed id={$id}: " . ($del['body']['message'] ?? json_encode($del['body'])) . "\n");
                exit(1);
            }
        }
        foreach ($sourceLinkIdsToRemove as $lid) {
            $delL = pb_request('DELETE', '/api/collections/input_media/records/' . rawurlencode($lid), null, $auth);
            if ($delL['code'] < 200 || $delL['code'] >= 300) {
                fwrite(STDERR, "DELETE source_links failed id={$lid}: " . ($delL['body']['message'] ?? json_encode($delL['body'])) . "\n");
                exit(1);
            }
        }
    }
    $n = count($bad);
    foreach ($bad as $row) {
        $sl = $row['input_media_id'] !== '' ? "  input_media_id={$row['input_media_id']}\n" : '';
        echo ($apply ? 'deleted ' : 'would delete ') . "content_items id={$row['id']}\n  garage_url={$row['garage_url']}\n{$sl}";
    }
    $nl = count($sourceLinkIdsToRemove);
    foreach ($sourceLinkIdsToRemove as $lid) {
        echo ($apply ? 'deleted ' : 'would delete ') . "source_links id={$lid}\n";
    }
    echo 'done: ' . ($apply ? 'deleted ' : 'would delete ') . "{$n} content_item(s)";
    if ($nl > 0) {
        echo ', ' . ($apply ? 'deleted ' : 'would delete ') . "{$nl} source_link(s)";
    }
    echo '.';
    echo (!$apply && ($n > 0 || $nl > 0) ? " Use --apply to delete.\n" : "\n");
    exit(0);
}

// CLI: php index.php delete-orphan-source-links [--apply] — DELETE source_links with no content_items (skips status=pending queued URLs)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'delete-orphan-source-links') {
    $apply = in_array('--apply', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Need ADMIN_EMAIL / ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $auth = 'Bearer ' . $token;
    $page = 1;
    $perPage = 100;
    $orphans = [];
    while (true) {
        $qs = http_build_query([
            'filter' => 'role = "queued_source"',
            'perPage' => $perPage,
            'page' => $page,
            'sort' => '-@rowid',
        ]);
        $r = pb_request('GET', '/api/collections/input_media/records?' . $qs, null, $auth);
        if ($r['code'] !== 200) {
            fwrite(STDERR, "List failed: " . ($r['body']['message'] ?? 'HTTP ' . $r['code']) . "\n");
            exit(1);
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string) ($it['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if (trim((string) ($it['status'] ?? '')) === 'pending') {
                continue;
            }
            $cids = ff_pb_content_item_ids_for_source_link($auth, $id);
            if ($cids === false) {
                fwrite(STDERR, "List content_items by input_media_id failed: {$id}\n");
                exit(1);
            }
            if ($cids === []) {
                $orphans[] = $id;
            }
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    if ($apply) {
        foreach ($orphans as $oid) {
            $del = pb_request('DELETE', '/api/collections/input_media/records/' . rawurlencode($oid), null, $auth);
            if ($del['code'] < 200 || $del['code'] >= 300) {
                fwrite(STDERR, "DELETE source_links failed id={$oid}: " . ($del['body']['message'] ?? json_encode($del['body'])) . "\n");
                exit(1);
            }
        }
    }
    foreach ($orphans as $oid) {
        echo ($apply ? 'deleted ' : 'would delete ') . "source_links id={$oid} (no content_items, not pending)\n";
    }
    $no = count($orphans);
    echo 'done: ' . ($apply ? 'deleted ' : 'would delete ') . "{$no} orphan source_link(s).";
    echo (!$apply && $no > 0 ? " Use --apply to delete.\n" : "\n");
    exit(0);
}

// CLI: php index.php ensure-cursor-pipeline-dirs — create dirs and report writability (fix perms on server if needed)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'ensure-cursor-pipeline-dirs') {
    ff_ensure_cursor_pipeline_dirs();
    $cfg = $GLOBALS['CONFIG'] ?? [];
    $triggerDir = (string) ($cfg['cursor_pipeline_trigger_dir'] ?? '');
    $cdir = ff_cursor_pipeline_dir();
    $promptsDir = ff_cursor_pipeline_prompts_dir();
    echo 'PHP effective user: ' . ff_php_effective_user() . "\n";
    foreach ([
        'cursor_pipeline' => $cdir,
        'triggers' => $triggerDir,
        'prompts' => $promptsDir,
    ] as $label => $d) {
        $ex = is_dir($d);
        $w = $ex && is_writable($d);
        echo "{$label}: {$d}\n  exists=" . ($ex ? 'yes' : 'no') . ' writable=' . ($w ? 'yes' : 'no') . "\n";
    }
    $tw = $triggerDir !== '' && is_dir($triggerDir) && is_writable($triggerDir);
    $hint = ff_cursor_pipeline_permissions_hint($triggerDir, $tw);
    if ($hint) {
        fwrite(STDERR, "\n{$hint}\n\nRun on the host: sudo ./scripts/ensure-cursor-pipeline-perms.sh\n");
        exit(1);
    }
    echo "OK: trigger directory is writable.\n";
    exit(0);
}

// CLI: php index.php setup-pipeline [trigger_file]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'setup-pipeline') {
    $triggerFile = $argv[2] ?? null;
    $triggerDir = $GLOBALS['CONFIG']['cursor_pipeline_trigger_dir'] ?? (__DIR__ . '/.cursor-pipeline/triggers');
    if (!$triggerFile) {
        $files = glob($triggerDir . '/trigger_*.json');
        if (empty($files)) { fwrite(STDERR, "No trigger. Usage: php index.php setup-pipeline [trigger_file]\n"); exit(1); }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $triggerFile = $files[0];
    }
    if (!is_file($triggerFile)) { fwrite(STDERR, "Not found: $triggerFile\n"); exit(1); }
    setup_pipeline_from_trigger($triggerFile, false);
    exit(0);
}

// CLI: php index.php complete-generate <content_item_id> — background worker (escapes PHP-FPM request limits)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'complete-generate') {
    $itemId = trim((string)($argv[2] ?? ''));
    if ($itemId === '') {
        fwrite(STDERR, "Usage: php index.php complete-generate <content_item_id>\n");
        exit(1);
    }
    formatforge_complete_generate_cli($itemId);
    exit(0);
}

// CLI: php index.php verify-pipeline-generation <pipelines_record_id> — one-shot generate + media check
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'verify-pipeline-generation') {
    $pid = trim((string)($argv[2] ?? ''));
    if ($pid === '') {
        fwrite(STDERR, "Usage: php index.php verify-pipeline-generation <pipelines_record_id>\n");
        exit(1);
    }
    exit(formatforge_verify_pipeline_generation_cli($pid));
}

// CLI: php index.php patch-pipelines-record <pipelines_record_id> <merge.json> — superuser; merges metadata with existing row
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'patch-pipelines-record') {
    $id = trim((string)($argv[2] ?? ''));
    $path = trim((string)($argv[3] ?? ''));
    if ($id === '' || $path === '' || !is_file($path)) {
        fwrite(STDERR, "Usage: php index.php patch-pipelines-record <pipelines_record_id> <merge.json>\n");
        exit(1);
    }
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $tok = $auth['token'];
    $raw = file_get_contents($path);
    if ($raw === false) {
        fwrite(STDERR, "Cannot read JSON file.\n");
        exit(1);
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fwrite(STDERR, "merge.json must be a JSON object.\n");
        exit(1);
    }
    $gr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($id), null, $tok);
    if (($gr['code'] ?? 0) !== 200 || !is_array($gr['body'] ?? null)) {
        fwrite(STDERR, "Pipeline record not found or unreadable.\n");
        exit(1);
    }
    $existingMeta = is_array($gr['body']['metadata'] ?? null) ? $gr['body']['metadata'] : [];
    if (isset($data['metadata']) && is_array($data['metadata'])) {
        $data['metadata'] = array_merge($existingMeta, $data['metadata']);
    }
    $pr = pb_request('PATCH', '/api/collections/pipelines/records/' . rawurlencode($id), $data, $tok);
    if (($pr['code'] ?? 0) < 200 || ($pr['code'] ?? 0) >= 300) {
        fwrite(STDERR, 'PATCH failed: ' . ($pr['body']['message'] ?? json_encode($pr['body'] ?? [])) . "\n");
        exit(1);
    }
    fwrite(STDOUT, "OK: patched pipelines/{$id}\n");
    exit(0);
}

// CLI: php index.php set-pipeline-default-format <pipelines_record_id> <slot_signature> — e.g. video or image,video
// Uses dashboard user token: patches `pipelines.formats` JSON (same ACL as pipelines list for authenticated users).
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'set-pipeline-default-format') {
    $id = trim((string)($argv[2] ?? ''));
    $sigRaw = trim((string)($argv[3] ?? ''));
    if ($id === '' || $sigRaw === '') {
        fwrite(STDERR, "Usage: php index.php set-pipeline-default-format <pipelines_record_id> <slot_signature>\n");
        exit(1);
    }
    $tok = ff_pb_cli_token();
    if (!$tok) {
        fwrite(STDERR, "Auth failed: set FORMATFORGE_EMAIL/FORMATFORGE_PASSWORD (or ADMIN_*) for a PocketBase users row.\n");
        exit(1);
    }
    $sig = ff_parse_slot_signature($sigRaw);
    $res = ff_pipeline_set_default_format_signature(pb_normalize_bearer_token($tok), $id, $sig, 'Default format');
    if (empty($res['ok'])) {
        $err = is_array($res['record'] ?? null) ? json_encode($res['record'], JSON_UNESCAPED_SLASHES) : 'unknown';
        fwrite(STDERR, "Failed to upsert default format on pipeline: {$err}\n");
        exit(1);
    }
    fwrite(STDOUT, 'OK: default format slot_signature=' . ff_slot_signature_to_string($sig) . "\n");
    exit(0);
}

// CLI: php index.php measure-generation-alignment <content_item_id>  [--force]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'measure-generation-alignment') {
    $itemId = trim((string)($argv[2] ?? ''));
    if ($itemId === '') {
        fwrite(STDERR, "Usage: php index.php measure-generation-alignment <content_item_id> [--force]\n");
        exit(1);
    }
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $force = in_array('--force', $argv, true);
    $r = ff_measure_generation_input_alignment($itemId, $auth['token'], $force);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(!empty($r['ok']) ? 0 : 1);
}

// CLI: php index.php sweep-generation-alignment  [--limit=40] [--force]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'sweep-generation-alignment') {
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $lim = 40;
    foreach ($argv as $a) {
        if (is_string($a) && preg_match('/^--limit=(\d+)$/', $a, $m)) {
            $lim = max(1, min(200, (int)$m[1]));
        }
    }
    $force = in_array('--force', $argv, true);
    $sw = ff_sweep_generation_input_alignment($auth['token'], $lim, $force);
    echo json_encode($sw, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($sw['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php stuck-generating  [--resume]  — list rows stuck in generating; --resume runs complete-generate for each
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'stuck-generating') {
    $resume = in_array('--resume', $argv, true);
    $auth = pb_superuser_auth_token();
    if (!$auth['ok']) {
        fwrite(STDERR, ($auth['error'] ?? 'Superuser auth failed') . "\n");
        exit(1);
    }
    $tok = $auth['token'];
    $list = formatforge_list_generating_content_items($tok);
    if (!$list['ok']) {
        fwrite(STDERR, ($list['error'] ?? 'List failed') . "\n");
        exit(1);
    }
    $items = $list['items'];
    fwrite(STDOUT, 'content_items with status=generating: ' . count($items) . "\n");
    foreach ($items as $row) {
        $pid = $row['pipeline_id'] ?? '';
        fwrite(STDOUT, ($row['id'] ?? '') . "\t" . ($row['updated'] ?? '') . "\t" . ($pid !== '' ? $pid : '-') . "\t" . substr($row['title'] ?? '', 0, 80) . "\n");
    }
    if ($resume && $items !== []) {
        fwrite(STDOUT, "\n--resume: running php index.php complete-generate for each id (see storage/generate-worker.log if spawned from web)...\n");
        foreach ($items as $row) {
            $id = trim((string)($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            fwrite(STDOUT, "complete-generate {$id} ...\n");
            formatforge_complete_generate_cli($id);
            $chk = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($id), null, $tok);
            $st = is_array($chk['body'] ?? null) ? trim((string)($chk['body']['status'] ?? '')) : '';
            fwrite(STDOUT, "  -> status: {$st}\n");
        }
    } elseif (!$resume && $items !== []) {
        fwrite(STDOUT, "\nTo run PHP generation for **non-pipeline** rows only, use: php index.php stuck-generating --resume\n");
        fwrite(STDOUT, "(Rows with metadata.pipeline_id are skipped — they must be completed by pipeline-generate, not complete-generate.)\n");
    }
    exit(0);
}

// CLI: php index.php cursor-agent-run /abs/path/to/.cursor-pipeline/prompts/pipeline-….md
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'cursor-agent-run') {
    $pf = $argv[2] ?? '';
    $root = __DIR__;
    $real = $pf !== '' ? realpath($pf) : false;
    $prefix = ff_cursor_pipeline_prompts_dir() . DIRECTORY_SEPARATOR;
    if (!$real || !is_file($real) || strncmp($real, $prefix, strlen($prefix)) !== 0) {
        fwrite(STDERR, "cursor-agent-run: need a file under .cursor-pipeline/prompts/\n");
        exit(1);
    }
    $cfg = $GLOBALS['CONFIG'];
    $bin = $cfg['cursor_agent_bin'] ?: 'agent';
    $model = ff_cursor_agent_model_from_cfg($cfg);
    ff_cursor_agent_run_state_write($real, [
        'status' => 'running',
        'started_at' => date('c'),
        'model' => $model,
    ]);
    $promptBasename = basename($real, '.md');
    $agentStatePath = $root . '/pipelines/' . $promptBasename . '/agent_state.json';
    $workspaceDir = ff_cursor_agent_pipeline_workspace_from_prompt($real);
    if ($workspaceDir === '') {
        fwrite(STDERR, "cursor-agent-run: pipeline workspace missing or unreadable: pipelines/" . $promptBasename . "\n");
        exit(1);
    }
    $orchStepForCli = 1;
    if (is_file($agentStatePath)) {
        $orchRaw = json_decode((string) file_get_contents($agentStatePath), true);
        if (is_array($orchRaw)) {
            $orchStepForCli = max(1, min(3, (int) ($orchRaw['execution_step'] ?? 1)));
        }
    }
    $task = 'FormatForge pipeline agent: You are a state machine. Open and read the ENTIRE Markdown file at '
        . $real
        . ' from top to bottom before taking any action (do not skim). Then execute only what that file and the orchestrator injection require for this run. ';
    if ($orchStepForCli <= 2) {
        $task .= 'You are on orchestrator Step ' . $orchStepForCli . ' of 3: you are FORBIDDEN from writing or editing Go code (*.go, go.mod, go.sum) or running go build / go mod tidy for the pipeline in this run. ';
    }
    $task .= 'Your workspace is only pipelines/' . $promptBasename . '/ (read FORMATFORGE_INDEX_SPAWN.md for how the app invokes pipeline-generate). Related: PocketBase pipelines collection, crontab as instructed in the file.';
    $fmt = (string)($cfg['cursor_agent_output_format'] ?? 'text');
    $cmdline = [$bin, '-p', '--trust', '-f', '--model', $model, '--workspace', $workspaceDir];
    if ($fmt !== '' && $fmt !== 'text') {
        $cmdline[] = '--output-format';
        $cmdline[] = $fmt;
    }
    if (!empty($cfg['cursor_agent_stream_partial_output']) && $fmt === 'stream-json') {
        $cmdline[] = '--stream-partial-output';
    }
    $cmdline[] = $task;
    $nullDevice = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = @proc_open(
        $cmdline,
        [
            0 => ['file', $nullDevice, 'r'],
            1 => STDOUT,
            2 => STDERR,
        ],
        $pipes,
        $workspaceDir,
        ff_cursor_agent_env_for_proc_open(),
        ['bypass_shell' => true]
    );
    if (!is_resource($proc)) {
        ff_cursor_agent_run_state_write($real, [
            'status' => 'failed_start',
            'finished_at' => date('c'),
        ]);
        ff_pipeline_agent_drain_queue_after_prompt_stem($promptBasename);
        fwrite(STDERR, "cursor-agent-run: could not start " . $bin . " (install Cursor CLI / set CURSOR_AGENT_BIN)\n");
        exit(1);
    }
    $exit = proc_close($proc);
    ff_cursor_agent_run_state_write($real, [
        'status' => $exit === 0 ? 'completed' : 'failed',
        'finished_at' => date('c'),
        'exit_code' => $exit,
    ]);
    if ($exit === 0) {
        ff_autocreate_pipeline_record_after_agent_success($real);
    }
    ff_pipeline_agent_drain_queue_after_prompt_stem($promptBasename);
    exit(($exit === 0) ? 0 : 1);
}

// CLI: php index.php cursor-agent-advance-step <pipeline_subdir|agent_uuid>
// Checks for step artifacts (source_analysis.md, pipeline_architecture.json) and bumps execution_step.
// Use after agent exits or when resuming manually. Supports pipeline-20260321060557_afca5455 or UUID.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'cursor-agent-advance-step') {
    $arg = trim((string)($argv[2] ?? ''));
    if ($arg === '') {
        fwrite(STDERR, "cursor-agent-advance-step: pass pipeline subdir (e.g. pipeline-20260321060557_afca5455) or agent_uuid\n");
        exit(1);
    }
    $projectRoot = __DIR__;
    $pipelinesDir = $projectRoot . '/pipelines';
    $pipelineDir = null;
    $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $arg);
    if (!$isUuid) {
        $subdir = ff_normalize_pipeline_subdir($arg);
        if ($subdir !== '' && is_dir($pipelinesDir . '/' . $subdir)) {
            $pipelineDir = $pipelinesDir . '/' . $subdir;
        }
    }
    if (!$pipelineDir) {
        foreach (glob($pipelinesDir . '/pipeline-*', GLOB_ONLYDIR) ?: [] as $d) {
            $statePath = $d . '/agent_state.json';
            if (is_file($statePath)) {
                $raw = json_decode((string) file_get_contents($statePath), true);
                if (is_array($raw) && trim((string)($raw['agent_uuid'] ?? '')) === $arg) {
                    $pipelineDir = $d;
                    break;
                }
            }
        }
    }
    if (!$pipelineDir || !is_dir($pipelineDir)) {
        fwrite(STDERR, "cursor-agent-advance-step: pipeline not found for $arg\n");
        exit(1);
    }
    $statePath = $pipelineDir . '/agent_state.json';
    ff_ensure_pipeline_agent_state($pipelineDir);
    $state = json_decode((string) file_get_contents($statePath), true) ?: [];
    $step = (int)($state['execution_step'] ?? 1);
    $step = $step >= 1 && $step <= 3 ? $step : 1;
    if ($step === 1 && is_file($pipelineDir . '/source_analysis.md')) {
        $state['execution_step'] = 2;
        $state['step_advanced_at'] = date('c');
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if (ff_pipeline_orchestrator_prompt_apply_injection($projectRoot, $pipelineDir)) {
            fwrite(STDOUT, basename($pipelineDir) . ": refreshed orchestrator injection in .cursor-pipeline/prompts/\n");
        }
        fwrite(STDOUT, basename($pipelineDir) . ": execution_step 1 → 2 (source_analysis.md found)\n");
        exit(0);
    }
    if ($step === 2 && is_file($pipelineDir . '/pipeline_architecture.json')) {
        $state['execution_step'] = 3;
        $state['step_advanced_at'] = date('c');
        file_put_contents($statePath, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        if (ff_pipeline_orchestrator_prompt_apply_injection($projectRoot, $pipelineDir)) {
            fwrite(STDOUT, basename($pipelineDir) . ": refreshed orchestrator injection in .cursor-pipeline/prompts/\n");
        }
        fwrite(STDOUT, basename($pipelineDir) . ": execution_step 2 → 3 (pipeline_architecture.json found)\n");
        exit(0);
    }
    fwrite(STDOUT, basename($pipelineDir) . ": execution_step=$step, no advance (artifact for next step missing)\n");
    exit(0);
}

// CLI: php index.php pipeline-orchestrator-refresh-prompt <pipeline_subdir|agent_uuid>
// Re-writes the <!-- FF_ORCHESTRATOR_INJECTION --> region in .cursor-pipeline/prompts/<subdir>.md from agent_state.execution_step.
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'pipeline-orchestrator-refresh-prompt') {
    $arg = trim((string)($argv[2] ?? ''));
    if ($arg === '') {
        fwrite(STDERR, "pipeline-orchestrator-refresh-prompt: pass pipeline subdir or agent_uuid\n");
        exit(1);
    }
    $projectRoot = __DIR__;
    $pipelinesDir = $projectRoot . '/pipelines';
    $pipelineDir = null;
    $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $arg);
    if (!$isUuid) {
        $subdir = ff_normalize_pipeline_subdir($arg);
        if ($subdir !== '' && is_dir($pipelinesDir . '/' . $subdir)) {
            $pipelineDir = $pipelinesDir . '/' . $subdir;
        }
    }
    if (!$pipelineDir) {
        foreach (glob($pipelinesDir . '/pipeline-*', GLOB_ONLYDIR) ?: [] as $d) {
            $statePath = $d . '/agent_state.json';
            if (is_file($statePath)) {
                $raw = json_decode((string) file_get_contents($statePath), true);
                if (is_array($raw) && trim((string)($raw['agent_uuid'] ?? '')) === $arg) {
                    $pipelineDir = $d;
                    break;
                }
            }
        }
    }
    if (!$pipelineDir || !is_dir($pipelineDir)) {
        fwrite(STDERR, "pipeline-orchestrator-refresh-prompt: pipeline not found for $arg\n");
        exit(1);
    }
    if (ff_pipeline_orchestrator_prompt_apply_injection($projectRoot, $pipelineDir)) {
        fwrite(STDOUT, basename($pipelineDir) . ": orchestrator injection refreshed in .cursor-pipeline/prompts/\n");
        exit(0);
    }
    fwrite(STDERR, "pipeline-orchestrator-refresh-prompt: no injection markers in prompt file or write failed\n");
    exit(1);
}

// CLI: php index.php sync-instagram-insights [max_records]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'sync-instagram-insights') {
    $pbTok = ff_pb_cli_token();
    if (!$pbTok) {
        fwrite(STDERR, "Set ADMIN_EMAIL/ADMIN_PASSWORD or FORMATFORGE_EMAIL/FORMATFORGE_PASSWORD in .env\n");
        exit(1);
    }
    $lim = (int) ($argv[2] ?? 80);
    $r = formatforge_sync_content_metrics_insights($pbTok, $lim);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(!empty($r['ok']) ? 0 : 1);
}

// CLI: php index.php delete-source-link [record_id]
// Without id: deletes the only source_links row when count === 1 (safety check).
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'delete-source-link') {
    $email = getenv('ADMIN_EMAIL') ?: getenv('FORMATFORGE_EMAIL');
    $pass = getenv('ADMIN_PASSWORD') ?: getenv('FORMATFORGE_PASSWORD');
    if (!$email || !$pass) {
        fwrite(STDERR, "Set ADMIN_EMAIL/ADMIN_PASSWORD or FORMATFORGE_EMAIL/FORMATFORGE_PASSWORD in .env\n");
        exit(1);
    }
    $auth = pb_request('POST', '/api/collections/' . $GLOBALS['CONFIG']['users_collection'] . '/auth-with-password', [
        'identity' => $email,
        'password' => $pass,
    ]);
    if ($auth['code'] < 200 || $auth['code'] >= 300) {
        fwrite(STDERR, 'Auth failed: ' . ($auth['body']['message'] ?? json_encode($auth['body'])) . "\n");
        exit(1);
    }
    $token = $auth['body']['token'] ?? '';
    if (!$token) {
        fwrite(STDERR, "No token in auth response\n");
        exit(1);
    }
    $wantId = trim($argv[2] ?? '');
    $list = pb_request('GET', '/api/collections/input_media/records?' . http_build_query([
        'filter' => 'role = "queued_source"',
        'perPage' => 500,
        'sort' => '-@rowid',
    ]), null, $token);
    if ($list['code'] < 200 || $list['code'] >= 300) {
        fwrite(STDERR, 'List failed: ' . ($list['body']['message'] ?? json_encode($list['body'])) . "\n");
        exit(1);
    }
    $items = $list['body']['items'] ?? [];
    if ($wantId === '') {
        if (count($items) !== 1) {
            fwrite(STDERR, 'Expected exactly 1 source_links record (pass record id as 2nd arg to delete a specific row). Found: ' . count($items) . "\n");
            foreach ($items as $it) {
                $id = $it['id'] ?? '';
                $url = $it['url'] ?? '';
                fwrite(STDERR, "  - {$id}  {$url}\n");
            }
            exit(1);
        }
        $wantId = (string)($items[0]['id'] ?? '');
    }
    if ($wantId === '') {
        fwrite(STDERR, "No record id to delete.\n");
        exit(1);
    }
    $del = pb_request('DELETE', '/api/collections/input_media/records/' . rawurlencode($wantId), null, $token);
    if ($del['code'] < 200 || $del['code'] >= 300) {
        fwrite(STDERR, 'Delete failed: ' . ($del['body']['message'] ?? json_encode($del['body'])) . "\n");
        exit(1);
    }
    echo "Deleted source_links/{$wantId}\n";
    exit(0);
}

/** PocketBase auth token for CLI maintenance (ADMIN_EMAIL / ADMIN_PASSWORD). */
function ff_pb_cli_token(): ?string {
    $email = getenv('ADMIN_EMAIL') ?: getenv('FORMATFORGE_EMAIL');
    $pass = getenv('ADMIN_PASSWORD') ?: getenv('FORMATFORGE_PASSWORD');
    if (!$email || !$pass) {
        return null;
    }
    $auth = pb_request('POST', '/api/collections/' . $GLOBALS['CONFIG']['users_collection'] . '/auth-with-password', [
        'identity' => $email,
        'password' => $pass,
    ]);
    if ($auth['code'] < 200 || $auth['code'] >= 300) {
        return null;
    }
    $t = $auth['body']['token'] ?? '';
    return $t !== '' ? $t : null;
}

/**
 * Delete content_items with no title, no prompt, and no media URLs (corrupt / accidental rows).
 * @return array{ok: bool, dry_run: bool, deleted: string[], error?: string}
 */
function cleanup_bad_content_items(string $token, bool $apply): array {
    $out = ['ok' => true, 'dry_run' => !$apply, 'deleted' => []];
    $page = 1;
    $perPage = 100;
    $authHeader = 'Bearer ' . $token;
    while (true) {
        $qs = http_build_query(['perPage' => $perPage, 'page' => $page, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            $out['ok'] = false;
            $out['error'] = $r['body']['message'] ?? 'List content_items failed';
            return $out;
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string)($it['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $title = trim((string)($it['title'] ?? ''));
            $prompt = trim((string)($it['prompt'] ?? ''));
            $gurl = trim((string)($it['garage_url'] ?? ''));
            $thumb = trim((string)($it['thumbnail_url'] ?? ''));
            $gkey = trim((string)($it['garage_key'] ?? ''));
            if ($title === '' && $prompt === '' && $gurl === '' && $thumb === '' && $gkey === '') {
                if ($apply) {
                    $del = pb_request('DELETE', '/api/collections/output_media/records/' . rawurlencode($id), null, $authHeader);
                    if ($del['code'] < 200 || $del['code'] >= 300) {
                        $out['ok'] = false;
                        $out['error'] = $del['body']['message'] ?? "Delete failed for {$id}";
                        return $out;
                    }
                }
                $out['deleted'][] = $id;
            }
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    return $out;
}

// CLI: php index.php cleanup-bad-content-items  [--apply]
// Removes content_items with empty title, prompt, and no storage URLs (orphan rows).
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'cleanup-bad-content-items') {
    $apply = in_array('--apply', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Auth failed or missing ADMIN_EMAIL/ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $r = cleanup_bad_content_items($token, $apply);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

/**
 * Delete all source_links records (queued / fetched URLs on the Curate tab).
 * @return array{ok: bool, dry_run: bool, deleted: string[], error?: string}
 */
function delete_all_source_links(string $token, bool $apply): array {
    $out = ['ok' => true, 'dry_run' => !$apply, 'deleted' => []];
    $page = 1;
    $perPage = 100;
    $authHeader = 'Bearer ' . $token;
    while (true) {
        $qs = http_build_query([
            'filter' => 'role = "queued_source"',
            'perPage' => $perPage,
            'page' => $page,
            'sort' => '-@rowid',
        ]);
        $r = pb_request('GET', '/api/collections/input_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            $out['ok'] = false;
            $out['error'] = $r['body']['message'] ?? 'List input_media (queued_source) failed';
            return $out;
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string)($it['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($apply) {
                $del = pb_request('DELETE', '/api/collections/input_media/records/' . rawurlencode($id), null, $authHeader);
                if ($del['code'] < 200 || $del['code'] >= 300) {
                    $out['ok'] = false;
                    $out['error'] = $del['body']['message'] ?? "Delete failed for {$id}";
                    return $out;
                }
            }
            $out['deleted'][] = $id;
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    return $out;
}

/**
 * Delete all content_items records (Curate media + pipeline-generated rows).
 * @return array{ok: bool, dry_run: bool, deleted: string[], error?: string}
 */
function delete_all_content_items(string $token, bool $apply): array {
    $out = ['ok' => true, 'dry_run' => !$apply, 'deleted' => []];
    $page = 1;
    $perPage = 100;
    $authHeader = 'Bearer ' . $token;
    while (true) {
        $qs = http_build_query(['perPage' => $perPage, 'page' => $page, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            $out['ok'] = false;
            $out['error'] = $r['body']['message'] ?? 'List content_items failed';
            return $out;
        }
        $items = $r['body']['items'] ?? [];
        foreach ($items as $it) {
            $id = (string) ($it['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($apply) {
                $del = pb_request('DELETE', '/api/collections/output_media/records/' . rawurlencode($id), null, $authHeader);
                if ($del['code'] < 200 || $del['code'] >= 300) {
                    $out['ok'] = false;
                    $out['error'] = $del['body']['message'] ?? "Delete failed for {$id}";
                    return $out;
                }
            }
            $out['deleted'][] = $id;
        }
        if (count($items) < $perPage) {
            break;
        }
        $page++;
    }
    return $out;
}

/** Escape a string for PocketBase `filter` comparisons (double-quoted). */
function ff_pb_filter_string(string $s): string {
    return str_replace(['\\', '"'], ['\\\\', '\"'], $s);
}

/**
 * Clear embedded `metrics` JSON on an output_media row (insights / IG ids).
 * @return array{ok: bool, error?: string}
 */
function formatforge_delete_metrics_for_content_item(string $authHeader, string $contentItemId): array {
    $contentItemId = trim($contentItemId);
    if ($contentItemId === '') {
        return ['ok' => false, 'error' => 'Missing content id'];
    }
    $up = pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($contentItemId), ['metrics' => (object)[]], $authHeader);
    if ($up['code'] < 200 || $up['code'] >= 300) {
        return ['ok' => false, 'error' => $up['body']['message'] ?? 'Clear metrics failed'];
    }
    return ['ok' => true];
}

/**
 * Delete one content item and its metrics rows.
 * @return array{ok: bool, error?: string}
 */
function formatforge_delete_content_item_record(string $authHeader, string $contentItemId): array {
    $contentItemId = trim($contentItemId);
    if ($contentItemId === '') {
        return ['ok' => false, 'error' => 'Missing content id'];
    }
    $m = formatforge_delete_metrics_for_content_item($authHeader, $contentItemId);
    if (!$m['ok']) {
        return $m;
    }
    $d = pb_request('DELETE', '/api/collections/output_media/records/' . rawurlencode($contentItemId), null, $authHeader);
    if ($d['code'] < 200 || $d['code'] >= 300) {
        return ['ok' => false, 'error' => $d['body']['message'] ?? 'Delete content_items failed'];
    }
    return ['ok' => true];
}

/**
 * Delete all content_items whose metadata.pipeline_id matches (paginated loop).
 * @return array{ok: bool, deleted_ids: string[], error?: string, failed_id?: string}
 */
function formatforge_delete_content_items_for_pipeline(string $authHeader, string $pipelineId): array {
    $pipelineId = trim($pipelineId);
    if ($pipelineId === '') {
        return ['ok' => false, 'deleted_ids' => [], 'error' => 'Missing pipeline id'];
    }
    $escaped = ff_pb_filter_string($pipelineId);
    $filter = 'metadata.pipeline_id = "' . $escaped . '"';
    $deleted = [];
    $perPage = 100;
    while (true) {
        $qs = http_build_query(['filter' => $filter, 'perPage' => $perPage, 'page' => 1, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'deleted_ids' => $deleted, 'error' => $r['body']['message'] ?? 'List content_items failed'];
        }
        $items = $r['body']['items'] ?? [];
        if (count($items) === 0) {
            break;
        }
        foreach ($items as $it) {
            $cid = (string)($it['id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $dr = formatforge_delete_content_item_record($authHeader, $cid);
            if (!$dr['ok']) {
                return ['ok' => false, 'deleted_ids' => $deleted, 'error' => $dr['error'] ?? 'Delete failed', 'failed_id' => $cid];
            }
            $deleted[] = $cid;
        }
    }
    return ['ok' => true, 'deleted_ids' => $deleted];
}

/**
 * Delete all content_items with this input_media_id, then caller deletes the source_links row.
 * @return array{ok: bool, deleted_ids: string[], error?: string, failed_id?: string}
 */
function formatforge_delete_content_items_for_source_link(string $authHeader, string $sourceLinkId): array {
    $sourceLinkId = trim($sourceLinkId);
    if ($sourceLinkId === '') {
        return ['ok' => false, 'deleted_ids' => [], 'error' => 'Missing source link id'];
    }
    $escaped = ff_pb_filter_string($sourceLinkId);
    $filter = 'input_media_id = "' . $escaped . '"';
    $deleted = [];
    $perPage = 100;
    while (true) {
        $qs = http_build_query(['filter' => $filter, 'perPage' => $perPage, 'page' => 1, 'sort' => '-@rowid']);
        $r = pb_request('GET', '/api/collections/output_media/records?' . $qs, null, $authHeader);
        if ($r['code'] !== 200) {
            return ['ok' => false, 'deleted_ids' => $deleted, 'error' => $r['body']['message'] ?? 'List content_items failed'];
        }
        $items = $r['body']['items'] ?? [];
        if (count($items) === 0) {
            break;
        }
        foreach ($items as $it) {
            $cid = (string)($it['id'] ?? '');
            if ($cid === '') {
                continue;
            }
            $dr = formatforge_delete_content_item_record($authHeader, $cid);
            if (!$dr['ok']) {
                return ['ok' => false, 'deleted_ids' => $deleted, 'error' => $dr['error'] ?? 'Delete failed', 'failed_id' => $cid];
            }
            $deleted[] = $cid;
        }
    }
    return ['ok' => true, 'deleted_ids' => $deleted];
}

/**
 * Remove pipeline-generated content, then the pipelines row (superuser — collection deleteRule is null).
 * @return array{ok: bool, deleted_content_ids?: string[], deleted_content_count?: int, error?: string, partial?: bool}
 */
function formatforge_delete_pipeline_cascade(string $authHeader, string $pipelineId): array {
    $pipelineId = trim($pipelineId);
    if ($pipelineId === '') {
        return ['ok' => false, 'error' => 'Missing pipeline id'];
    }
    $pipelineRow = null;
    $pr = pb_request('GET', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $authHeader);
    if (($pr['code'] ?? 0) === 200 && is_array($pr['body'] ?? null)) {
        $pipelineRow = $pr['body'];
    }
    $cr = formatforge_delete_content_items_for_pipeline($authHeader, $pipelineId);
    if (!$cr['ok']) {
        return [
            'ok' => false,
            'error' => $cr['error'] ?? 'Could not delete all content for this pipeline',
            'deleted_content_ids' => $cr['deleted_ids'] ?? [],
            'partial' => true,
        ];
    }
    $su = pb_superuser_auth_token();
    if (!$su['ok']) {
        return [
            'ok' => false,
            'error' => 'Content removed, but pipeline row needs superuser: ' . ($su['error'] ?? 'configure ADMIN_EMAIL / ADMIN_PASSWORD on the server'),
            'deleted_content_ids' => $cr['deleted_ids'] ?? [],
            'partial' => true,
        ];
    }
    $suHeader = 'Bearer ' . $su['token'];
    $pd = pb_request('DELETE', '/api/collections/pipelines/records/' . rawurlencode($pipelineId), null, $suHeader);
    if ($pd['code'] < 200 || $pd['code'] >= 300) {
        return [
            'ok' => false,
            'error' => $pd['body']['message'] ?? 'Pipeline delete failed',
            'deleted_content_ids' => $cr['deleted_ids'] ?? [],
            'partial' => true,
        ];
    }
    if ($pipelineRow !== null) {
        $meta = is_array($pipelineRow['metadata'] ?? null) ? $pipelineRow['metadata'] : [];
        $agentUuid = trim((string)($meta['agent_uuid'] ?? ''));
        $subdir = ff_pipeline_subdir_from_pipeline_record($pipelineRow);
        trigger_pipeline_cursor_agent('pipeline_deleted', [
            'intent' => 'delete_pipeline_cleanup',
            'pipeline_id' => $pipelineId,
            'pipeline_name' => (string)($pipelineRow['name'] ?? ''),
            'pipeline_subdir' => $subdir,
            'agent_uuid' => $agentUuid !== '' ? $agentUuid : null,
            'deleted_content_count' => count($cr['deleted_ids'] ?? []),
            'deleted_via' => 'frontend_delete_pipeline',
        ], $authHeader);
    }
    return [
        'ok' => true,
        'deleted_content_ids' => $cr['deleted_ids'] ?? [],
        'deleted_content_count' => count($cr['deleted_ids'] ?? []),
    ];
}

// CLI: php index.php delete-all-source-links  [--apply]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'delete-all-source-links') {
    $apply = in_array('--apply', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Auth failed or missing ADMIN_EMAIL/ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $r = delete_all_source_links($token, $apply);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php delete-all-content-items  [--apply]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'delete-all-content-items') {
    $apply = in_array('--apply', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Auth failed or missing ADMIN_EMAIL/ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $r = delete_all_content_items($token, $apply);
    echo json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($r['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php clear-curate-data  [--apply] — all source_links + all content_items
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'clear-curate-data') {
    $apply = in_array('--apply', $argv, true);
    $token = ff_pb_cli_token();
    if (!$token) {
        fwrite(STDERR, "Auth failed or missing ADMIN_EMAIL/ADMIN_PASSWORD (or FORMATFORGE_*)\n");
        exit(1);
    }
    $links = delete_all_source_links($token, $apply);
    $content = delete_all_content_items($token, $apply);
    echo json_encode(['source_links' => $links, 'content_items' => $content], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit((($links['ok'] ?? false) && ($content['ok'] ?? false)) ? 0 : 1);
}

// CLI: php index.php test-embed  ["optional phrase"]  [--sample]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'test-embed') {
    $cfg = $GLOBALS['CONFIG'];
    $wantSample = in_array('--sample', $argv, true);
    $phraseArg = '';
    foreach (array_slice($argv, 2) as $a) {
        if ($a === '--sample') {
            continue;
        }
        if ($phraseArg === '') {
            $phraseArg = (string)$a;
        }
    }
    $phrase = $phraseArg !== '' ? $phraseArg : 'FormatForge embedding self-test.';
    $vec = embed_text($phrase);
    if (!$vec) {
        fwrite(STDERR, "FAIL: embed_text() returned null — set GEMINI_API_KEY (preferred), or OPENROUTER_API_KEY, OPENAI_API_KEY, or EMBED_URL\n");
        exit(1);
    }
    $n = count($vec);
    $d = cosine_distance($vec, $vec);
    echo "OK: embed_text() — Gemini (if GEMINI_API_KEY) else OpenRouter/OpenAI/Ollama\n";
    echo '  phrase: ' . $phrase . "\n";
    echo "  dimensions: {$n}\n";
    echo "  cosine_distance(self,self): {$d} (expect 0)\n";
    if ($wantSample) {
        $head = array_slice($vec, 0, 8);
        $tail = array_slice($vec, -4);
        $mn = min($vec);
        $mx = max($vec);
        echo '  vector_head[0..7]: ' . json_encode($head, JSON_UNESCAPED_SLASHES) . "\n";
        echo '  vector_tail[last4]: ' . json_encode($tail, JSON_UNESCAPED_SLASHES) . "\n";
        echo '  min: ' . $mn . '  max: ' . $mx . "\n";
    }
    exit(0);
}

// CLI: php index.php probe-vector-search — VectorBase GET /api/vector-search/status + POST /query (cosine k-NN demo; superuser)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'probe-vector-search') {
    $su = pb_superuser_auth_token();
    if (!($su['ok'] ?? false) || empty($su['token'])) {
        fwrite(STDERR, 'Superuser auth failed: ' . ($su['error'] ?? 'unknown') . " — check ADMIN_EMAIL / ADMIN_PASSWORD\n");
        exit(1);
    }
    $tok = (string) $su['token'];
    $st = pb_vector_search_status($tok);
    $out = [
        'superuser_auth_path' => $su['path'] ?? null,
        'status' => ['http' => $st['code'], 'body' => $st['body']],
    ];
    if ($st['code'] !== 200) {
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    $queries = [
        'near_ml_seed' => [0.12, -0.34, 0.56, 0.78],
        'near_sql_seed' => [0.75, 0.22, -0.38, 0.19],
        'orthogonal' => [1.0, 0.0, 0.0, 0.0],
    ];
    $out['queries'] = [];
    foreach ($queries as $label => $vec) {
        $q = pb_vector_search_query($vec, 5, 'cos', $tok);
        $out['queries'][$label] = ['http' => $q['code'], 'body' => $q['body']];
    }
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    $bad = false;
    foreach ($out['queries'] as $v) {
        if (($v['http'] ?? 0) !== 200) {
            $bad = true;
        }
    }
    exit($bad ? 1 : 0);
}

// CLI: php index.php test-openrouter-embed-media — OpenRouter: EMBED_MODEL text batch + VL image batch; cosine checks (HTTP = libcurl, same as curl)
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'test-openrouter-embed-media') {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['openrouter_key'])) {
        fwrite(STDERR, "Set OPENROUTER_API_KEY in .env\n");
        exit(1);
    }
    $key = $cfg['openrouter_key'];
    $gemModel = trim((string)($cfg['embed_model'] ?? '')) ?: 'google/gemini-embedding-001';
    $catUrl = 'https://upload.wikimedia.org/wikipedia/commons/thumb/3/3a/Cat03.jpg/320px-Cat03.jpg';
    $dogUrl = 'https://upload.wikimedia.org/wikipedia/commons/thumb/2/26/YellowLabradorLooking_new.jpg/320px-YellowLabradorLooking_new.jpg';
    $vlModel = trim((string)(getenv('OPENROUTER_VL_EMBED_MODEL') ?: '')) ?: 'nvidia/llama-nemotron-embed-vl-1b-v2:free';

    $post = function (array $json) use ($key): array {
        $ch = curl_init('https://openrouter.ai/api/v1/embeddings');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($json),
            CURLOPT_TIMEOUT => 120,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $body = json_decode($raw ?: '{}', true) ?? [];

        return ['http' => $code, 'body' => $body];
    };

    $textStrings = [
        'a photo of a tabby cat sitting indoors',
        'a yellow labrador dog playing outside',
        'quantum chromodynamics and lattice gauge theory',
    ];
    $gem = $post([
        'model' => $gemModel,
        'encoding_format' => 'float',
        'input' => $textStrings,
    ]);
    $gemVecs = [];
    if ($gem['http'] === 200 && empty($gem['body']['error'])) {
        foreach ($gem['body']['data'] ?? [] as $row) {
            if (isset($row['embedding']) && is_array($row['embedding'])) {
                $gemVecs[] = $row['embedding'];
            }
        }
    }

    $vlPayload = [
        'model' => $vlModel,
        'encoding_format' => 'float',
        'input' => [
            [
                'content' => [
                    ['type' => 'text', 'text' => 'a small domestic cat'],
                    ['type' => 'image_url', 'image_url' => ['url' => $catUrl]],
                ],
            ],
            [
                'content' => [
                    ['type' => 'text', 'text' => 'a yellow labrador dog'],
                    ['type' => 'image_url', 'image_url' => ['url' => $dogUrl]],
                ],
            ],
            [
                'content' => [
                    ['type' => 'text', 'text' => 'a small domestic cat'],
                    ['type' => 'image_url', 'image_url' => ['url' => $catUrl]],
                ],
            ],
        ],
    ];
    $vl = $post($vlPayload);
    $vlVecs = [];
    if ($vl['http'] === 200 && empty($vl['body']['error'])) {
        foreach ($vl['body']['data'] ?? [] as $row) {
            if (isset($row['embedding']) && is_array($row['embedding'])) {
                $vlVecs[] = $row['embedding'];
            }
        }
    }

    $out = [
        'ok' => count($gemVecs) === 3 && count($vlVecs) === 3,
        'endpoint' => 'https://openrouter.ai/api/v1/embeddings',
        'text_batch' => [
            'model' => $gemModel,
            'http' => $gem['http'],
            'error' => $gem['body']['error'] ?? null,
            'labels' => ['cat_text', 'dog_text', 'physics_text'],
            'dimensions' => isset($gemVecs[0]) ? count($gemVecs[0]) : null,
            'cosine_distance_cat_vs_dog' => (count($gemVecs) >= 2) ? cosine_distance($gemVecs[0], $gemVecs[1]) : null,
            'cosine_distance_cat_vs_physics' => (count($gemVecs) >= 3) ? cosine_distance($gemVecs[0], $gemVecs[2]) : null,
            'cosine_similarity_cat_vs_dog' => (count($gemVecs) >= 2) ? ff_cosine_similarity_from_embeddings($gemVecs[0], $gemVecs[1]) : null,
        ],
        'image_batch' => [
            'model' => $vlModel,
            'http' => $vl['http'],
            'error' => $vl['body']['error'] ?? null,
            'media' => ['cat' => $catUrl, 'dog' => $dogUrl],
            'dimensions' => isset($vlVecs[0]) ? count($vlVecs[0]) : null,
            'cosine_distance_cat_vs_dog' => (count($vlVecs) >= 2) ? cosine_distance($vlVecs[0], $vlVecs[1]) : null,
            'cosine_distance_cat_image_duplicate' => (count($vlVecs) >= 3) ? cosine_distance($vlVecs[0], $vlVecs[2]) : null,
            'cosine_similarity_cat_vs_dog' => (count($vlVecs) >= 2) ? ff_cosine_similarity_from_embeddings($vlVecs[0], $vlVecs[1]) : null,
        ],
        'notes' => [
            'google/gemini-embedding-001 on OpenRouter is used for the text batch (same as EMBED_MODEL).',
            'OpenRouter currently returns "OpenAI embeddings do not support image inputs" for image_url with that model; use OPENROUTER_VL_EMBED_MODEL for image+text (default nvidia/llama-nemotron-embed-vl-1b-v2:free).',
            'cosine_distance here matches index.php embed alignment: 1 minus clamped cosine similarity.',
        ],
    ];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(($out['ok'] ?? false) ? 0 : 1);
}

// CLI: php index.php probe-stack  (alias: probe-stack-connectivity) — PHP → PocketBase, Garage, Antfly; optional SigV4 PUT
if (PHP_SAPI === 'cli' && (($argv[1] ?? '') === 'probe-stack' || ($argv[1] ?? '') === 'probe-stack-connectivity')) {
    exit(ff_cli_probe_stack_connectivity());
}

// Auth
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'auth_callback') {
    header('Content-Type: application/json');
    $token = $_POST['token'] ?? '';
    $user = $_POST['user'] ?? null;
    if ($token && $user) {
        $_SESSION['pb_token'] = $token;
        $_SESSION['pb_user'] = is_string($user) ? json_decode($user, true) : $user;
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    if ($email && $password) {
        $auth = pb_request('POST', '/api/collections/' . $GLOBALS['CONFIG']['users_collection'] . '/auth-with-password', [
            'identity' => $email,
            'password' => $password,
        ], null);
        if ($auth['code'] === 200 && !empty($auth['body']['token'])) {
            $_SESSION['pb_token'] = $auth['body']['token'];
            $_SESSION['pb_user'] = $auth['body']['record'] ?? [];
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/'));
            exit;
        }
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/') . '?login_error=1');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'register') {
    if (!is_internal_network()) {
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/') . '?register_error=not_allowed');
        exit;
    }
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    if (!$email || !$password || $password !== $passwordConfirm) {
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/') . '?register_error=validation');
        exit;
    }
    if (strlen($password) < 8) {
        header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/') . '?register_error=password_short');
        exit;
    }
    $create = pb_request('POST', '/api/collections/' . $GLOBALS['CONFIG']['users_collection'] . '/records', [
        'email' => $email,
        'password' => $password,
        'passwordConfirm' => $passwordConfirm,
    ], null);
    if ($create['code'] >= 200 && $create['code'] < 300) {
        $auth = pb_request('POST', '/api/collections/' . $GLOBALS['CONFIG']['users_collection'] . '/auth-with-password', [
            'identity' => $email,
            'password' => $password,
        ], null);
        if ($auth['code'] === 200 && !empty($auth['body']['token'])) {
            $_SESSION['pb_token'] = $auth['body']['token'];
            $_SESSION['pb_user'] = $auth['body']['record'] ?? [];
            header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/'));
            exit;
        }
    }
    $err = $create['body']['message'] ?? $create['body']['msg'] ?? null;
    if ($err === null && isset($create['body']['data'])) {
        $d = $create['body']['data'];
        if (is_array($d)) {
            $parts = [];
            foreach ($d as $field => $v) {
                $msg = is_array($v) ? ($v['message'] ?? $v['code'] ?? json_encode($v)) : (string) $v;
                $parts[] = $field . ': ' . $msg;
            }
            $err = implode('; ', $parts) ?: json_encode($d);
        } else {
            $err = (string) $d;
        }
    }
    if (!$err) {
        $code = $create['code'];
        $raw = $create['raw'] ?? '';
        if ($code === 0 || ($create['curl_errno'] ?? 0) !== 0) {
            $err = 'Cannot reach PocketBase. Check POCKETBASE_URL and that PocketBase is running.';
        } elseif ($code >= 400) {
            $err = 'PocketBase returned HTTP ' . $code . (strlen($raw) > 0 && !str_starts_with(trim($raw), '{') ? '. Response was not JSON.' : '.');
        } else {
            $err = 'Unexpected response (HTTP ' . $code . ').';
        }
    }
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/') . '?register_error=' . urlencode(is_string($err) ? $err : json_encode($err)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    unset($_SESSION['pb_user'], $_SESSION['pb_token']);
    header('Location: ' . ($_SERVER['REQUEST_URI'] ?: '/'));
    exit;
}

// Migrations: now handled by PocketBase pb_migrations (run automatically on serve)
if (($_GET['migrate'] ?? '') === getenv('MIGRATE_SECRET') && getenv('MIGRATE_SECRET')) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true, 'message' => 'Collections are created via pb_migrations. Restart PocketBase to apply.']);
    exit;
}

$user = $_SESSION['pb_user'] ?? null;
$token = $_SESSION['pb_token'] ?? null;
$authHeader = $token ?: null;

// Meta/Facebook webhook verification (must respond to GET with hub.challenge)
$hubMode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
$hubVerify = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
$hubChallenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
if ($hubMode === 'subscribe' && $hubVerify !== '' && $hubChallenge !== '') {
    $verifyToken = getenv('META_WEBHOOK_VERIFY_TOKEN') ?: '';
    if ($verifyToken !== '' && $hubVerify === $verifyToken) {
        header('Content-Type: text/plain');
        echo $hubChallenge;
        exit;
    }
}
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$isInstagramCallback = isset($_GET['instagram_callback']) || str_contains($reqUri, '/instagram/callback');

// Instagram OAuth (uses Facebook Login; opens in browser — mobile web, not Facebook app)
if (isset($_GET['instagram_oauth']) && $user) {
    $cfg = $GLOBALS['CONFIG'];
    $redirect = $cfg['instagram_redirect'] ?: ($cfg['site_url'] . '/instagram/callback');
    try { $stateNonce = bin2hex(random_bytes(16)); } catch (Throwable $e) { $stateNonce = sha1(uniqid('', true)); }
    $oauthState = ['user_id' => $user['id'] ?? '', 'nonce' => $stateNonce];
    $_SESSION['instagram_oauth_state'] = $oauthState;
    $params = [
        'client_id' => $cfg['fb_app_id'],
        'redirect_uri' => $redirect,
        'scope' => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management',
        'response_type' => 'code',
        'state' => base64_encode(json_encode($oauthState)),
    ];
    $query = http_build_query($params);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobile = (bool) preg_match('/Mobile|Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua);
    $host = $isMobile ? 'm.facebook.com' : 'www.facebook.com';
    $webUrl = "https://{$host}/v18.0/dialog/oauth?{$query}";
    ff_debug_log('instagram_oauth_start', [
        'user_id' => $user['id'] ?? null,
        'redirect' => $redirect,
        'is_mobile' => $isMobile,
        'oauth_host' => $host,
    ]);
    header('Location: ' . $webUrl);
    exit;
}

if ($isInstagramCallback && $user && !empty($_GET['error'])) {
    $cfg = $GLOBALS['CONFIG'];
    $baseUrl = $cfg['site_url'] . $_SERVER['SCRIPT_NAME'];
    ff_debug_log('instagram_callback_cancelled', [
        'error' => $_GET['error'] ?? null,
        'error_reason' => $_GET['error_reason'] ?? null,
        'error_description' => $_GET['error_description'] ?? null,
    ]);
    header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode('Instagram connection was cancelled.') . '&msgError=1');
    exit;
}
if ($isInstagramCallback && isset($_GET['code']) && $user) {
    $cfg = $GLOBALS['CONFIG'];
    $redirect = $cfg['instagram_redirect'] ?: ($cfg['site_url'] . '/instagram/callback');
    $baseUrl = $cfg['site_url'] . $_SERVER['SCRIPT_NAME'];
    $stateRaw = (string) ($_GET['state'] ?? '');
    $statePayload = json_decode(base64_decode($stateRaw, true) ?: '{}', true) ?? [];
    $expectedState = $_SESSION['instagram_oauth_state'] ?? [];
    ff_debug_log('instagram_callback_received', [
        'request_uri' => $reqUri,
        'has_code' => !empty($_GET['code']),
        'state_present' => $stateRaw !== '',
    ]);
    unset($_SESSION['instagram_oauth_state']);
    if (
        !is_array($statePayload) || !is_array($expectedState) ||
        empty($statePayload['nonce']) || empty($expectedState['nonce']) ||
        !hash_equals((string)$expectedState['nonce'], (string)$statePayload['nonce']) ||
        (($statePayload['user_id'] ?? '') !== ($user['id'] ?? ''))
    ) {
        ff_debug_log('instagram_callback_state_invalid', [
            'state_payload' => $statePayload,
            'expected_user_id' => $expectedState['user_id'] ?? null,
            'expected_nonce_present' => !empty($expectedState['nonce']),
        ]);
        header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode('Instagram connection failed: invalid OAuth state. Please try again.') . '&msgError=1');
        exit;
    }

    // Exchange code at Facebook (not api.instagram.com — we use Facebook Login)
    $ch = curl_init('https://graph.facebook.com/v18.0/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $cfg['fb_app_id'],
            'client_secret' => $cfg['fb_app_secret'],
            'redirect_uri' => $redirect,
            'code' => preg_replace('/#_.*$/', '', $_GET['code']),
        ]),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res ?: '{}', true) ?? [];
    $fbToken = $data['access_token'] ?? null;
    if (!$fbToken) {
        $err = $data['error']['message'] ?? json_encode($data);
        ff_debug_log('facebook_token_exchange_failed', [
            'error' => $data['error'] ?? $data,
        ]);
        header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode('Instagram connection failed: ' . $err) . '&msgError=1');
        exit;
    }
    ff_debug_log('facebook_token_exchange_ok', ['has_token' => true]);

    // Get user's Pages and their connected Instagram accounts in one request (fallback below if needed)
    $ch2 = curl_init('https://graph.facebook.com/v18.0/me/accounts?fields=id,name,access_token,tasks,instagram_business_account{id,username}&access_token=' . urlencode($fbToken));
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    $r2 = curl_exec($ch2);
    curl_close($ch2);
    $accounts = json_decode($r2 ?: '{}', true) ?? [];
    if (!empty($accounts['error']['message'])) {
        ff_debug_log('facebook_pages_lookup_failed', [
            'error' => $accounts['error'] ?? null,
            'response_preview' => substr($r2 ?: '', 0, 300),
        ]);
        header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode('Facebook Pages lookup failed: ' . $accounts['error']['message']) . '&msgError=1');
        exit;
    }
    $pages = $accounts['data'] ?? [];
    ff_debug_log('facebook_pages_lookup_ok', ['pages_count' => count($pages)]);
    $saved = 0;
    $schemaIssue = null;
    $seenIgUsers = [];
    $pagesAudit = [];
    foreach ($pages as $page) {
        $pageId = $page['id'] ?? '';
        $pageName = $page['name'] ?? '';
        $pageToken = trim((string) ($page['access_token'] ?? ''));
        if ($pageToken === '') $pageToken = $fbToken;
        if (!$pageId) {
            $pagesAudit[] = [
                'page_id' => null,
                'page_name' => $pageName ?: '(unknown)',
                'instagram_business_linked' => false,
                'note' => 'skipped_missing_page_id',
            ];
            continue;
        }
        ff_debug_log('facebook_page_scan_start', [
            'page_id' => $pageId,
            'page_name' => $pageName,
            'has_expanded_ig' => !empty($page['instagram_business_account']['id']),
        ]);

        // Prefer ig account returned directly from /me/accounts, fallback to page lookup.
        $igBiz = $page['instagram_business_account'] ?? null;
        $igSource = 'expanded';
        if (!$igBiz || empty($igBiz['id'])) {
            $igSource = 'page_lookup';
            foreach ([$fbToken, $pageToken] as $tryToken) {
                $ch3 = curl_init("https://graph.facebook.com/v18.0/{$pageId}?fields=instagram_business_account{id,username}&access_token=" . urlencode($tryToken));
                curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
                $r3 = curl_exec($ch3);
                curl_close($ch3);
                $pageData = json_decode($r3 ?: '{}', true) ?? [];
                $igBiz = $pageData['instagram_business_account'] ?? null;
                if ($igBiz && !empty($igBiz['id'])) break;
            }
        }
        if (!$igBiz || empty($igBiz['id'])) {
            ff_debug_log('facebook_page_scan_no_instagram', [
                'page_id' => $pageId,
                'page_name' => $pageName,
            ]);
            $pagesAudit[] = [
                'page_id' => $pageId,
                'page_name' => $pageName,
                'instagram_business_linked' => false,
                'ig_resolved_via' => $igSource,
            ];
            continue;
        }

        $igUserId = trim((string) ($igBiz['id'] ?? ''));
        if ($igUserId === '') {
            $pagesAudit[] = [
                'page_id' => $pageId,
                'page_name' => $pageName,
                'instagram_business_linked' => true,
                'ig_resolved_via' => $igSource,
                'note' => 'empty_ig_user_id_after_link',
            ];
            continue;
        }
        if (isset($seenIgUsers[$igUserId])) {
            $pagesAudit[] = [
                'page_id' => $pageId,
                'page_name' => $pageName,
                'instagram_business_linked' => true,
                'ig_user_id' => $igUserId,
                'ig_resolved_via' => $igSource,
                'note' => 'duplicate_ig_already_saved_this_callback',
            ];
            continue;
        }
        $seenIgUsers[$igUserId] = true;

        $username = normalize_instagram_username($igBiz['username'] ?? null);
        $usernameSource = $username ? ($igSource . '_username') : 'fetch_api';
        if (!$username) $username = fetch_instagram_username($igUserId, [$fbToken, $pageToken]);
        if (!$username) {
            $username = 'ig_' . $igUserId;
            $usernameSource = 'fallback_ig_id';
        }

        $payload = [
            'platform' => 'instagram',
            'instagram_user_id' => $igUserId,
            'username' => $username,
            'access_token' => $pageToken,
            'is_active' => true,
        ];
        $existing = pb_find_instagram_account_by_user_id($igUserId, $authHeader);
        $upsertMode = ($existing && !empty($existing['id'])) ? 'update' : 'create';
        $rec = null;
        $pbFieldErrors = [];
        for ($upsertAttempt = 0; $upsertAttempt < 2; $upsertAttempt++) {
            if ($upsertMode === 'update') {
                $rec = pb_request('PATCH', "/api/collections/social_accounts/records/{$existing['id']}", $payload, $authHeader);
            } else {
                $rec = pb_request('POST', '/api/collections/social_accounts/records', $payload, $authHeader);
            }
            $code = (int) ($rec['code'] ?? 0);
            if ($code >= 200 && $code < 300) {
                break;
            }
            $pbFieldErrors = pb_record_validation_messages($rec['body'] ?? null);
            ff_debug_log('instagram_account_upsert_failed', [
                'attempt' => $upsertAttempt + 1,
                'upsert_mode' => $upsertMode,
                'pb_code' => $code,
                'pb_message' => $rec['body']['message'] ?? null,
                'pb_field_errors' => $pbFieldErrors,
                'access_token_length' => strlen((string) $pageToken),
            ]);
            if ($upsertAttempt === 0 && $code >= 400 && $code < 500) {
                $repairUpsert = repair_instagram_accounts_schema();
                ff_debug_log('instagram_upsert_repair_before_retry', ['repair' => $repairUpsert]);
                if ($repairUpsert['ok'] ?? false) {
                    continue;
                }
            }
            break;
        }
        $recordBody = is_array($rec['body'] ?? null) ? $rec['body'] : [];
        $visibleFieldSuspect = (
            $rec['code'] >= 200 && $rec['code'] < 300 &&
            (
                empty($recordBody['instagram_user_id']) ||
                !array_key_exists('access_token', $recordBody)
            )
        );
        if ($visibleFieldSuspect) {
            ff_debug_log('instagram_upsert_visibility_suspect', [
                'record_id' => $recordBody['id'] ?? ($existing['id'] ?? null),
                'visible_keys' => array_keys($recordBody),
            ]);
            $repair = repair_instagram_accounts_schema();
            ff_debug_log('instagram_schema_repair_attempt', $repair);
            if (($repair['ok'] ?? false)) {
                $targetId = $recordBody['id'] ?? ($existing['id'] ?? null);
                if ($targetId) {
                    $retry = pb_request('PATCH', "/api/collections/social_accounts/records/{$targetId}", $payload, $authHeader);
                    $rec = $retry;
                    ff_debug_log('instagram_account_repatch_after_repair', [
                        'record_id' => $targetId,
                        'pb_code' => $retry['code'] ?? 0,
                        'pb_message' => $retry['body']['message'] ?? null,
                    ]);
                }
            } else {
                $schemaIssue = 'Instagram account schema is incompatible (missing/hidden fields). Please reconnect once more. If it still fails, contact support/admin.';
            }
        }
        ff_debug_log('instagram_account_upsert', [
            'page_id' => $pageId,
            'page_name' => $pageName,
            'ig_user_id' => $igUserId,
            'username' => $username,
            'username_source' => $usernameSource,
            'ig_source' => $igSource,
            'upsert_mode' => $upsertMode,
            'pb_code' => $rec['code'] ?? 0,
            'pb_message' => $rec['body']['message'] ?? null,
            'pb_record_id' => $rec['body']['id'] ?? ($existing['id'] ?? null),
        ]);
        $pbOk = ($rec['code'] ?? 0) >= 200 && ($rec['code'] ?? 0) < 300;
        $pagesAudit[] = [
            'page_id' => $pageId,
            'page_name' => $pageName,
            'instagram_business_linked' => true,
            'ig_user_id' => $igUserId,
            'ig_username_resolved' => $username,
            'ig_resolved_via' => $igSource,
            'upsert_mode' => $upsertMode,
            'pocketbase_saved' => $pbOk,
            'pb_http_code' => $rec['code'] ?? null,
            'pb_message' => $rec['body']['message'] ?? null,
            'pb_field_errors' => $pbOk ? (object)[] : $pbFieldErrors,
            'access_token_length' => $pbOk ? null : strlen((string) $pageToken),
            'pb_record_id' => $rec['body']['id'] ?? ($existing['id'] ?? null),
        ];
        if ($pbOk) {
            $saved++;
        }
    }
    $graphIgRowCount = count(array_filter(
        $pagesAudit,
        static fn (array $r): bool => !empty($r['instagram_business_linked'])
    ));
    ff_debug_log('instagram_callback_pages_audit', [
        'saved' => $saved,
        'pages_count' => count($pages),
        'graph_ig_row_count' => $graphIgRowCount,
        'pages_rows' => $pagesAudit,
    ]);
    ff_debug_log('instagram_callback_complete', ['saved' => $saved, 'pages_count' => count($pages)]);

    $outcome = 'no_pages';
    if ($schemaIssue) {
        $outcome = 'schema_issue';
    } elseif ($saved > 0) {
        $outcome = 'success';
    } elseif (!empty($pages)) {
        $outcome = 'pages_but_no_usable_ig';
    }
    if ($schemaIssue) {
        header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode($schemaIssue) . '&msgError=1');
    } elseif ($saved > 0) {
        header('Location: ' . $baseUrl . '?tab=accounts&msg=connected');
    } elseif (!empty($pages)) {
        header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode('No Instagram Business account linked to your Facebook Page. Connect a Page to Instagram first.') . '&msgError=1');
    } else {
        header('Location: ' . $baseUrl . '?tab=accounts&msg=' . rawurlencode('No Facebook Page with Instagram found. Ensure your Page is connected to Instagram (Settings > Instagram), and that you granted business_management when connecting. Try removing the app in Facebook Settings > Apps and reconnecting.') . '&msgError=1');
    }
    exit;
}

// API: add link, generate, approve, reject, publish (require PB token only — empty `pb_user` [] is falsy in PHP and must not skip this block)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $authHeader) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'get_debug_logs') {
        echo json_encode(['ok' => true, 'logs' => ff_debug_logs_get()]);
        exit;
    }

    if ($action === 'clear_debug_logs') {
        ff_debug_logs_clear();
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'pipeline_diagnostics') {
        echo json_encode(['ok' => true] + ff_pipeline_diagnostics_bundle($authHeader), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'pipeline_ingredients_list') {
        $pipelineId = trim((string)($_POST['pipeline_id'] ?? ''));
        if ($pipelineId === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing pipeline_id']);
            exit;
        }
        $items = ff_pipeline_ingredients_list($authHeader, $pipelineId, false);
        $fmt = ff_pipeline_default_format($authHeader, $pipelineId);
        echo json_encode([
            'ok' => true,
            'items' => $items,
            'format' => $fmt['record'],
            'signature' => $fmt['signature'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'set_pipeline_format') {
        $pipelineId = trim((string)($_POST['pipeline_id'] ?? ''));
        $name = trim((string)($_POST['name'] ?? 'Default format'));
        $slotSignatureRaw = trim((string)($_POST['slot_signature'] ?? ''));
        if ($pipelineId === '' || $slotSignatureRaw === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing pipeline_id or slot_signature']);
            exit;
        }
        $sig = ff_parse_slot_signature($slotSignatureRaw);
        if ($sig === []) {
            echo json_encode(['ok' => false, 'error' => 'slot_signature must contain at least one slot']);
            exit;
        }
        $res = ff_pipeline_set_default_format_signature($authHeader, $pipelineId, $sig, $name !== '' ? $name : 'Default format');
        echo json_encode(['ok' => $res['ok'] ?? false, 'record' => $res['record'] ?? null, 'error' => $res['error'] ?? null]);
        exit;
    }

    if ($action === 'add_pipeline_ingredient') {
        $pipelineId = trim((string)($_POST['pipeline_id'] ?? ''));
        $slotKind = ff_shape_kind_for_content_type((string)($_POST['slot_kind'] ?? 'video'));
        $slotIndex = (int)($_POST['slot_index'] ?? 1);
        if ($slotIndex < 1) {
            $slotIndex = 1;
        }
        $topic = trim((string)($_POST['topic'] ?? ''));
        $titleSeed = trim((string)($_POST['title_seed'] ?? ''));
        $inputUrl = trim((string)($_POST['input_url'] ?? ''));
        $instruction = trim((string)($_POST['instruction'] ?? ''));
        if ($pipelineId === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing pipeline_id']);
            exit;
        }
        if ($topic === '' && $titleSeed === '' && $inputUrl === '' && $instruction === '') {
            echo json_encode(['ok' => false, 'error' => 'Add at least one ingredient field (topic, title seed, input URL, instruction).']);
            exit;
        }
        $payload = [
            'role' => 'pipeline_slot',
            'status' => '',
            'url' => '',
            'title' => '',
            'pipeline_id' => $pipelineId,
            'slot_kind' => $slotKind,
            'slot_index' => $slotIndex,
            'topic' => $topic,
            'title_seed' => $titleSeed,
            'input_url' => $inputUrl,
            'instruction' => $instruction,
            'is_active' => true,
            'metadata' => (object)[],
        ];
        $sv = pb_request('POST', '/api/collections/input_media/records', $payload, $authHeader);
        echo json_encode(['ok' => $sv['code'] >= 200 && $sv['code'] < 300, 'record' => $sv['body'] ?? null, 'error' => ($sv['body']['message'] ?? null)]);
        exit;
    }

    if ($action === 'delete_pipeline_ingredient') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        $del = pb_request('DELETE', '/api/collections/input_media/records/' . rawurlencode($id), null, $authHeader);
        echo json_encode(['ok' => $del['code'] >= 200 && $del['code'] < 300, 'error' => ($del['body']['message'] ?? null)]);
        exit;
    }

    if ($action === 'ui_debug_bundle') {
        echo json_encode([
            'ok' => true,
            'server' => ff_ui_debug_public_snapshot(),
            'session' => [
                'id_prefix' => substr((string) session_id(), 0, 8),
            ],
            'request' => [
                'method' => $_SERVER['REQUEST_METHOD'] ?? '',
                'uri' => $_SERVER['REQUEST_URI'] ?? '',
                'query' => ff_debug_sanitize($_GET),
            ],
            'logs' => ff_debug_logs_get(),
            'pipeline' => ff_pipeline_diagnostics_bundle($authHeader),
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'pb_proxy') {
        $cols = ff_pb_proxy_collections();
        $coll = (string) ($_POST['collection'] ?? '');
        if (!in_array($coll, $cols, true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid collection', 'pb_http_status' => 0]);
            exit;
        }
        $method = strtoupper(trim((string) ($_POST['http_method'] ?? 'GET')));
        if (!in_array($method, ['GET', 'PATCH', 'DELETE'], true)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid method', 'pb_http_status' => 0]);
            exit;
        }
        $recordId = trim((string) ($_POST['record_id'] ?? ''));
        $query = (string) ($_POST['query'] ?? '');
        if (strlen($query) > 900) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Query too long', 'pb_http_status' => 0]);
            exit;
        }
        if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\\\\]/', $query)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid query', 'pb_http_status' => 0]);
            exit;
        }
        if ($recordId !== '' && !preg_match('/^[a-z0-9]+$/', $recordId)) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid record id', 'pb_http_status' => 0]);
            exit;
        }
        $path = '/api/collections/' . rawurlencode($coll) . '/records';
        if ($recordId !== '') {
            $path .= '/' . rawurlencode($recordId);
        } elseif ($method !== 'GET') {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'record_id required', 'pb_http_status' => 0]);
            exit;
        }
        if ($method === 'GET' && $query !== '') {
            $path .= '?' . $query;
        }
        $body = null;
        if ($method === 'PATCH') {
            $raw = (string) ($_POST['json_body'] ?? '');
            if (strlen($raw) > 120000) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Body too large', 'pb_http_status' => 0]);
                exit;
            }
            if ($raw === '') {
                $body = [];
            } else {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    http_response_code(400);
                    echo json_encode(['ok' => false, 'error' => 'Invalid json_body', 'pb_http_status' => 0]);
                    exit;
                }
                $body = $decoded;
            }
        }
        $res = pb_request($method, $path, $body, $authHeader);
        $code = (int) ($res['code'] ?? 0);
        if ($method === 'GET' && $coll === 'output_media' && $code >= 200 && $code < 300 && !empty($res['body']) && is_array($res['body'])) {
            $res['body'] = ff_pb_enrich_content_items_response($res['body']);
        }
        if ($code >= 400) {
            http_response_code($code > 0 ? $code : 502);
        }
        echo json_encode([
            'ok' => $code >= 200 && $code < 300,
            'pb_http_status' => $code,
            'body' => $res['body'],
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'debug_account_snapshot') {
        $list = pb_request('GET', '/api/collections/social_accounts/records?sort=-%40rowid&perPage=50', null, $authHeader);
        if ($list['code'] !== 200) {
            echo json_encode(['ok' => false, 'error' => $list['body']['message'] ?? ('HTTP ' . $list['code'])]);
            exit;
        }
        $items = $list['body']['items'] ?? [];
        $summary = [];
        foreach ($items as $it) {
            $summary[] = [
                'id' => $it['id'] ?? null,
                'username' => $it['username'] ?? null,
                'instagram_user_id' => $it['instagram_user_id'] ?? null,
                'is_active' => $it['is_active'] ?? null,
                'has_access_token_field' => array_key_exists('access_token', $it),
                'has_access_token_value' => !empty($it['access_token']),
                'created' => $it['created'] ?? null,
                'updated' => $it['updated'] ?? null,
            ];
        }
        $schemaInfo = null;
        $adminAuth = pb_superuser_auth_token();
        if ($adminAuth['ok']) {
            $coll = pb_fetch_collection('social_accounts', $adminAuth['token']);
            if ($coll['ok']) {
                $schemaInfo = [
                    'id' => $coll['collection']['id'] ?? null,
                    'name' => $coll['collection']['name'] ?? null,
                    'fields' => array_map(function ($f) {
                        return [
                            'name' => $f['name'] ?? null,
                            'type' => $f['type'] ?? null,
                            'hidden' => $f['hidden'] ?? false,
                            'required' => $f['required'] ?? false,
                        ];
                    }, $coll['collection']['fields'] ?? []),
                ];
            } else {
                $schemaInfo = ['error' => $coll['error'] ?? 'Could not read collection schema'];
            }
        } else {
            $schemaInfo = ['error' => $adminAuth['error'] ?? 'Admin auth unavailable'];
        }
        echo json_encode([
            'ok' => true,
            'server' => [
                'pocketbase_url' => $GLOBALS['CONFIG']['pocketbase_url'] ?? null,
                'pocketbase_public_url' => $GLOBALS['CONFIG']['pocketbase_public_url'] ?? null,
            ],
            'count' => count($summary),
            'accounts' => $summary,
            'schema' => $schemaInfo,
        ]);
        exit;
    }

    if ($action === 'repair_instagram_accounts_schema') {
        $repair = repair_instagram_accounts_schema();
        ff_debug_log('manual_schema_repair', $repair);
        echo json_encode($repair);
        exit;
    }

    if ($action === 'repair_source_links_schema') {
        $repair = repair_source_links_schema();
        ff_debug_log('repair_source_links_schema', $repair);
        echo json_encode($repair);
        exit;
    }

    if ($action === 'repair_content_items_media_schema') {
        $repair = repair_content_items_media_schema();
        ff_debug_log('repair_content_items_media_schema', $repair);
        echo json_encode($repair);
        exit;
    }

    if ($action === 'refresh_instagram_username') {
        $accountId = trim($_POST['account_id'] ?? '');
        ff_debug_log('refresh_username_start', ['account_id' => $accountId ?: null]);
        if (!$accountId) {
            ff_debug_log('refresh_username_failed', ['reason' => 'missing_account_id']);
            echo json_encode(['ok' => false, 'error' => 'Missing account_id']);
            exit;
        }
        $accResp = pb_request('GET', "/api/collections/social_accounts/records/{$accountId}", null, $authHeader);
        if ($accResp['code'] !== 200) {
            ff_debug_log('refresh_username_failed', ['reason' => 'account_not_found', 'pb_code' => $accResp['code']]);
            echo json_encode(['ok' => false, 'error' => 'Account not found']);
            exit;
        }
        $acc = $accResp['body'];
        $igUserId = $acc['instagram_user_id'] ?? '';
        $token = $acc['access_token'] ?? '';
        if (!$igUserId) {
            ff_debug_log('refresh_username_failed', ['reason' => 'missing_ig_user_id', 'account_id' => $accountId]);
            echo json_encode(['ok' => false, 'error' => 'This account has no Instagram ID stored. Disconnect and reconnect to fix.']);
            exit;
        }
        if (!$token) {
            ff_debug_log('refresh_username_failed', ['reason' => 'missing_access_token', 'account_id' => $accountId, 'ig_user_id' => $igUserId]);
            echo json_encode(['ok' => false, 'error' => 'Token missing or expired. Disconnect and reconnect this account to fix.']);
            exit;
        }
        $username = fetch_instagram_username($igUserId, [$token]);
        if (!$username) {
            ff_debug_log('refresh_username_failed', ['reason' => 'username_not_found', 'account_id' => $accountId, 'ig_user_id' => $igUserId]);
            echo json_encode(['ok' => false, 'error' => 'Could not fetch username from Instagram API']);
            exit;
        }
        $up = pb_request('PATCH', "/api/collections/social_accounts/records/{$accountId}", ['username' => $username, 'is_active' => true], $authHeader);
        $ok = $up['code'] >= 200 && $up['code'] < 300;
        if (!$ok) {
            ff_debug_log('refresh_username_failed', ['reason' => 'pb_update_failed', 'account_id' => $accountId, 'ig_user_id' => $igUserId, 'pb_code' => $up['code'], 'pb_message' => $up['body']['message'] ?? null]);
            echo json_encode(['ok' => false, 'error' => $up['body']['message'] ?? 'Failed to update account']);
            exit;
        }
        ff_debug_log('refresh_username_ok', ['account_id' => $accountId, 'ig_user_id' => $igUserId, 'username' => $username]);
        echo json_encode(['ok' => true, 'username' => $username]);
        exit;
    }

    if ($action === 'add_link') {
        $url = ff_canonical_fetch_url(trim($_POST['url'] ?? ''));
        $accountId = trim($_POST['account_id'] ?? '') ?: null;
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
            exit;
        }
        $rec = pb_request('POST', '/api/collections/input_media/records', [
            'role' => 'queued_source',
            'url' => $url,
            'title' => '',
            'status' => 'pending',
            'metadata' => $accountId ? ['social_account_id' => $accountId] : (object)[],
        ], $authHeader);
        if ($rec['code'] >= 200 && $rec['code'] < 300) {
            $body = $rec['body'];
            if (empty($body['url'])) {
                $repair = repair_source_links_schema();
                if (!($repair['ok'] ?? false)) {
                    echo json_encode([
                        'ok' => false,
                        'error' => 'input_media collection is missing required fields (schema never migrated). Configure ADMIN_EMAIL/ADMIN_PASSWORD for PocketBase, then POST action=repair_source_links_schema or run: php index.php repair-source-links-schema',
                        'repair_error' => $repair['error'] ?? null,
                    ]);
                    exit;
                }
                $id = $body['id'] ?? '';
                if ($id !== '') {
                    $patch = pb_request('PATCH', '/api/collections/input_media/records/' . rawurlencode($id), [
                        'role' => 'queued_source',
                        'url' => $url,
                        'title' => '',
                        'status' => 'pending',
                        'metadata' => $accountId ? ['social_account_id' => $accountId] : (object)[],
                    ], $authHeader);
                    if ($patch['code'] < 200 || $patch['code'] >= 300) {
                        echo json_encode(['ok' => false, 'error' => $patch['body']['message'] ?? 'Schema repaired but saving the link failed']);
                        exit;
                    }
                    $body = $patch['body'];
                }
            }
            echo json_encode(['ok' => true, 'id' => $body['id'] ?? null]);
        } else {
            echo json_encode(['ok' => false, 'error' => $rec['body']['message'] ?? 'Failed']);
        }
        exit;
    }

    if ($action === 'fetch_link') {
        $linkId = trim($_POST['link_id'] ?? '');
        if (!$linkId) {
            echo json_encode(['ok' => false, 'error' => 'Missing link_id']);
            exit;
        }
        $link = pb_request('GET', "/api/collections/input_media/records/{$linkId}", null, $authHeader);
        if ($link['code'] !== 200 || empty($link['body']['url'])) {
            echo json_encode(['ok' => false, 'error' => 'Link not found']);
            exit;
        }
        $url = ff_canonical_fetch_url((string) $link['body']['url']);
        $linkAccountId = $link['body']['metadata']['social_account_id'] ?? null;
        [$files, $tmpDir, $viaUsed, $fetchMeta] = fetch_media_from_url_auto($url);
        $created = 0;
        $cfg = $GLOBALS['CONFIG'];
        $activePipelineRows = fetch_active_pipeline_row_count($authHeader);
        if (formatforge_antfly_novelty_configured() && $activePipelineRows > 0) {
            formatforge_sync_pipeline_refs_to_antfly($authHeader);
        }
        $novelFetchedPrompts = [];
        $fetchedShapeSignature = [];
        $antflyContentIndexOk = 0;
        $fetchPaths = array_values($files);
        $fetchAssetCount = 0;
        foreach ($fetchPaths as $p) {
            if (is_string($p) && is_readable($p)) {
                $sz = @filesize($p);
                if ($sz !== false && $sz > 0) {
                    $fetchAssetCount++;
                }
            }
        }
        // If sizes are unavailable, fall back to path count so singleton images still become `image`.
        $imageKindSlots = $fetchAssetCount > 0 ? $fetchAssetCount : count($fetchPaths);
        $mediaRepairAttempted = false;
        foreach ($fetchPaths as $fileIndex => $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png' => 'image/png',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'mp4', 'm4v' => 'video/mp4',
                'webm' => 'video/webm',
                'mp3' => 'audio/mpeg',
                'm4a' => 'audio/mp4',
                default => 'application/octet-stream',
            };
            $content = file_get_contents($path);
            if (!$content) continue;
            $itemId = bin2hex(random_bytes(8));
            $baseName = basename($path);
            if ($baseName === '' || $baseName === '.' || $baseName === '..') {
                $baseName = 'fetched-media-' . ($fileIndex + 1);
            }
            $displayTitle = ff_short_title_for_fetch_url($url, $baseName);
            if ($fileIndex > 0) {
                $displayTitle .= ' (' . ($fileIndex + 1) . ')';
            }
            $key = 'content/' . $itemId . '/' . $baseName;
            $garageUrl = s3_upload($key, $content, $mime);
            if (!$garageUrl && $content) $garageUrl = garage_public_url_for_key($key);
            if (str_starts_with($mime, 'video/')) {
                $type = 'video';
            } elseif (str_starts_with($mime, 'image/')) {
                // Exactly one image asset in this fetch → image; albums / multi-file → carousel.
                $type = ($imageKindSlots === 1) ? 'image' : 'carousel';
            } else {
                $type = 'reel';
            }
            $metaArr = [
                'origin' => 'fetch',
                'fetched_via' => $viaUsed,
                'source_url' => $url,
            ];
            $postFields = [
                'type' => $type,
                'title' => $displayTitle,
                'prompt' => $url,
                'input_media_id' => $linkId,
                'status' => 'pending',
                'garage_key' => $key,
                'garage_url' => '',
                'metadata' => json_encode($metaArr, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ];
            if ($linkAccountId !== null && trim((string) $linkAccountId) !== '') {
                $postFields['social_account_id'] = trim((string) $linkAccountId);
            }
            $postFields['media_file'] = ff_curl_file($path, $mime, $baseName);
            $rec = pb_request_multipart('POST', '/api/collections/output_media/records', $postFields, $authHeader);
            if ($rec['code'] < 200 || $rec['code'] >= 300) {
                if (!$mediaRepairAttempted) {
                    $mediaRepairAttempted = true;
                    repair_content_items_media_schema();
                    $rec = pb_request_multipart('POST', '/api/collections/output_media/records', $postFields, $authHeader);
                }
            }
            if ($rec['code'] < 200 || $rec['code'] >= 300) {
                $rec = pb_request('POST', '/api/collections/output_media/records', [
                    'type' => $type,
                    'title' => $displayTitle,
                    'prompt' => $url,
                    'input_media_id' => $linkId,
                    'status' => 'pending',
                    'garage_key' => $key,
                    'garage_url' => $garageUrl ?: '',
                    'social_account_id' => $linkAccountId,
                    'metadata' => $metaArr,
                ], $authHeader);
            }
            if ($rec['code'] >= 200 && $rec['code'] < 300) {
                $created++;
                $fetchedShapeSignature[] = ff_shape_kind_for_content_type($type);
                $pbRowId = (string) ($rec['body']['id'] ?? '');
                if ($pbRowId !== '') {
                    $collId = trim((string) ($rec['body']['collectionId'] ?? ''));
                    $mediaName = trim((string) ($rec['body']['media_file'] ?? ''));
                    if ($collId === '' || $mediaName === '') {
                        $gr = pb_request('GET', '/api/collections/output_media/records/' . rawurlencode($pbRowId), null, $authHeader);
                        if ($gr['code'] === 200) {
                            $collId = trim((string) ($gr['body']['collectionId'] ?? $collId));
                            $mediaName = trim((string) ($gr['body']['media_file'] ?? $mediaName));
                        }
                    }
                    $displayUrl = '';
                    if ($collId !== '' && $mediaName !== '') {
                        $pbUrl = ff_pb_public_file_url($collId, $pbRowId, $mediaName);
                        if ($pbUrl !== '') {
                            pb_request('PATCH', '/api/collections/output_media/records/' . rawurlencode($pbRowId), ['garage_url' => $pbUrl], $authHeader);
                            $displayUrl = $pbUrl;
                        }
                    }
                    if ($displayUrl === '') {
                        $displayUrl = $garageUrl ?: '';
                    }
                    $caption = trim($displayTitle . "\n" . $url);
                    $semanticBlob = formatforge_antfly_content_semantic_text($displayTitle, $url, $caption);
                    if (formatforge_index_content_in_antfly($pbRowId, $semanticBlob, $type, 'pending', $displayUrl !== '' ? $displayUrl : null, $mime, $url, $displayTitle)) {
                        $antflyContentIndexOk++;
                    }
                    $novelImg = (ff_shape_kind_for_content_type($type) === 'image' && $displayUrl !== '') ? $displayUrl : null;
                    if (fetched_text_is_novel_vs_pipelines($semanticBlob, $activePipelineRows, $novelImg)) {
                        $novelFetchedPrompts[] = $semanticBlob;
                    }
                }
            }
        }
        maybe_trigger_cursor_create_pipeline_after_fetch(
            $novelFetchedPrompts,
            $authHeader,
            $linkId,
            $url,
            $created,
            (string)($linkAccountId ?? ''),
            $fetchedShapeSignature
        );
        $slMeta = is_array($link['body']['metadata'] ?? null) ? $link['body']['metadata'] : [];
        $slMeta['pending_novel_prompts'] = $novelFetchedPrompts;
        $slMeta['pending_fetched_shape_signature'] = $fetchedShapeSignature;
        $slMeta['pending_fetched_count'] = $created;
        if ($linkAccountId !== null && trim((string)$linkAccountId) !== '') {
            $slMeta['social_account_id'] = trim((string)$linkAccountId);
        }
        $slMeta['pipeline_create_last_checked_at'] = date('c');
        pb_request('PATCH', '/api/collections/input_media/records/' . rawurlencode($linkId), ['metadata' => $slMeta], $authHeader);
        ff_pipeline_trace_log('fetch_link_pipeline_summary', [
            'link_id' => $linkId,
            'created_content_items' => $created,
            'active_pipeline_rows' => $activePipelineRows,
            'antfly_configured' => formatforge_antfly_novelty_configured(),
            'antfly_url_resolution' => (string) ($GLOBALS['CONFIG']['antfly_url_resolution'] ?? ''),
            'antfly_content_index_ok' => $antflyContentIndexOk,
            'novel_semantic_blob_count' => count($novelFetchedPrompts),
        ]);
        foreach ($files as $path) @unlink($path);
        if ($tmpDir && is_dir($tmpDir)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($tmpDir);
        }
        if ($created === 0) {
            $attempts = $fetchMeta['attempts'] ?? [];
            $all127 = $attempts !== [] && !array_filter($attempts, fn ($a) => (int)($a['exit_code'] ?? 0) !== 127);
            $report = [
                'link_id' => $linkId,
                'url' => $url,
                'direct_candidate' => is_direct_media_url($url),
                'attempts' => $attempts,
                'hint' => ff_fetch_failure_hint($url),
                'gallery_dl_path' => $GLOBALS['CONFIG']['gallery_dl_path'] ?? '',
                'yt_dlp_path' => $GLOBALS['CONFIG']['yt_dlp_path'] ?? '',
                'fetch_auth_file_status' => ff_fetch_auth_file_status(),
            ];
            if ($all127) {
                $report['exit_127_note'] = 'gallery-dl and yt-dlp both exited 127 (command not found). On the host: `pip install --user gallery-dl yt-dlp` (or apt install), set GALLERY_DL_PATH / YT_DLP_PATH in .env to absolute paths, and ensure php-fpm sees a PATH that includes those dirs (see DEPLOYMENT.md §2).';
            }
            ff_debug_log('fetch_link_zero_files', $report);
            ff_pipeline_trace_log('fetch_link_zero_files', $report);
            $copyPayload = ff_debug_sanitize($report);
            $errExtra = $all127 ? ' Install gallery-dl / yt-dlp on the server (pip or apt), set GALLERY_DL_PATH / YT_DLP_PATH if needed, reload php-fpm.' : '';
            echo json_encode([
                'ok' => false,
                'error' => 'No files were downloaded. The server tries direct HTTP (file-like URLs), then gallery-dl, then yt-dlp.' . ff_fetch_failure_hint($url) . $errExtra,
                'debug_copy' => json_encode($copyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
            ]);
            exit;
        }
        pb_request('PATCH', "/api/collections/input_media/records/{$linkId}", ['status' => 'fetched'], $authHeader);
        echo json_encode(['ok' => true, 'created' => $created, 'via' => $viaUsed]);
        exit;
    }

    if ($action === 'generate_content') {
        formatforge_generate_content_action($_POST, $authHeader);
    }

    if ($action === 'feed_refresh_generate') {
        $accountId = trim((string) ($_POST['account_id'] ?? ''));
        $r = ff_feed_refresh_generate_for_account($accountId, $authHeader);
        if (!($r['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'error' => $r['error'] ?? 'feed_refresh_generate failed']);
            exit;
        }
        $msg = '';
        if (($r['started'] ?? 0) > 0) {
            $msg = 'Started ' . (int) $r['started'] . ' pipeline run(s) for this account.';
        } elseif (($r['skipped'] ?? '') === 'no_eligible_pipelines') {
            $msg = 'No pipelines with backing + template are scoped to this account.';
        } elseif (($r['skipped'] ?? '') === 'disabled') {
            $msg = '';
        } elseif (($r['skipped'] ?? '') === 'max_zero') {
            $msg = '';
        }
        echo json_encode(array_merge(['ok' => true, 'message' => $msg], $r));
        exit;
    }

    if ($action === 'verify_shape_gates') {
        $accountId = trim((string) ($_POST['account_id'] ?? ''));
        $n = ff_scan_shape_mismatch_gates_for_account($accountId, $authHeader);
        echo json_encode(['ok' => true, 'shape_mismatch_triggers' => $n]);
        exit;
    }

    if ($action === 'approve_content') {
        $id = $_POST['id'] ?? '';
        $accountId = trim((string) ($_POST['account_id'] ?? ''));
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); exit; }
        $item = pb_request('GET', "/api/collections/output_media/records/{$id}", null, $authHeader);
        if ($item['code'] !== 200) {
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            exit;
        }
        $isFetched = ff_content_item_is_fetched_for_snapshot($item['body']);
        if ($isFetched) {
            $up = pb_request('PATCH', "/api/collections/output_media/records/{$id}", [
                'status' => 'approved',
                'rejected_reason' => '',
            ], $authHeader);
        } else {
            if ($accountId === '') {
                echo json_encode(['ok' => false, 'error' => 'Missing account_id']);
                exit;
            }
            $up = pb_request('PATCH', "/api/collections/output_media/records/{$id}", [
                'status' => 'approved',
                'social_account_id' => $accountId,
            ], $authHeader);
        }
        // Cursor agent for pipelines is driven by fetch novelty + reject streak (see maybe_trigger_cursor_*).
        if ($up['code'] >= 200 && $up['code'] < 300 && $item['code'] === 200 && ff_content_item_is_fetched_for_snapshot($item['body'])) {
            $slid = trim((string)($item['body']['input_media_id'] ?? ''));
            if ($slid !== '') {
                ff_maybe_trigger_create_pipeline_after_review($slid, $authHeader);
            }
        }
        if ($up['code'] >= 200 && $up['code'] < 300 && $item['code'] === 200 && ff_content_item_is_pipeline_generated_snapshot($item['body'])) {
            ff_measure_generation_input_alignment((string)$id, $authHeader, false);
        }
        echo json_encode(['ok' => $up['code'] >= 200 && $up['code'] < 300]);
        exit;
    }

    if ($action === 'reject_content') {
        $id = $_POST['id'] ?? '';
        $reason = $_POST['reason'] ?? '';
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); exit; }
        $item = pb_request('GET', "/api/collections/output_media/records/{$id}", null, $authHeader);
        $annotationUrl = '';
        if (!empty($_FILES['annotation_png']['tmp_name']) && is_uploaded_file($_FILES['annotation_png']['tmp_name'])) {
            $raw = (string) file_get_contents($_FILES['annotation_png']['tmp_name']);
            $maxB = 5 * 1024 * 1024;
            if ($raw !== '' && strlen($raw) < $maxB && strlen($raw) > 80) {
                $ct = strtolower((string) ($_FILES['annotation_png']['type'] ?? ''));
                $okMime = (str_starts_with($ct, 'image/') || $ct === 'application/octet-stream');
                if ($okMime) {
                    $key = 'content/reject-annotate/' . preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $id) . '/' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(3)) . '.png';
                    $upUrl = s3_upload($key, $raw, 'image/png');
                    if ($upUrl !== null && $upUrl !== '') {
                        $annotationUrl = ff_upgrade_http_media_url_if_app_https($upUrl);
                    }
                }
            }
        }
        $reasonText = trim((string) $reason);
        $reasonFinal = $reasonText;
        if ($annotationUrl !== '') {
            $reasonFinal .= ($reasonFinal !== '' ? "\n\n" : '') . 'Annotation image: ' . $annotationUrl;
        }
        $pmBase = is_array($item['body']['metadata'] ?? null) ? $item['body']['metadata'] : [];
        unset($pmBase['auto_post_failure']);
        if ($annotationUrl !== '') {
            $pmBase['rejection_annotation_url'] = $annotationUrl;
        }
        $patchReject = [
            'status' => 'rejected',
            'rejected_reason' => $reasonFinal,
            'scheduled_publish_at' => null,
            'metadata' => $pmBase,
        ];
        $up = pb_request('PATCH', "/api/collections/output_media/records/{$id}", $patchReject, $authHeader);
        if ($up['code'] >= 200 && $up['code'] < 300 && $item['code'] === 200) {
            $body = $item['body'];
            $pmeta = is_array($body['metadata'] ?? null) ? $body['metadata'] : [];
            $pId = trim((string)($pmeta['pipeline_id'] ?? ''));
            if ($pId !== '') {
                formatforge_pipeline_record_rejection_feedback($pId, $reasonText, (string) $id, $annotationUrl);
                // Apply immediate default-shape reduction so next run is congruent (e.g. 7 -> 6 after one slot reject).
                $rejSig = ff_shape_signature_normalize($pmeta['source_shape_signature'] ?? $pmeta['ingredient_signature'] ?? []);
                $rejIdx = (int)($pmeta['source_shape_index'] ?? $pmeta['ingredient_index'] ?? 0);
                if ($rejSig !== []) {
                    $newSig = ff_suggest_changed_shape_after_reject($rejSig, $rejIdx);
                    $sv = ff_pipeline_set_default_format_signature($authHeader, $pId, $newSig, 'Adaptive format');
                    if ($sv['ok']) {
                        ff_pipeline_trace_log('pipeline_shape_changed_after_reject', [
                            'pipeline_id' => $pId,
                            'rejected_signature' => $rejSig,
                            'rejected_index' => $rejIdx > 0 ? $rejIdx : null,
                            'new_signature' => $newSig,
                        ]);
                    }
                }
            }
            maybe_trigger_cursor_edit_pipeline_after_reject($body, (string)$id, (string)$reason, $authHeader);
            if (ff_content_item_is_fetched_for_snapshot($body)) {
                $slid = trim((string)($body['input_media_id'] ?? ''));
                if ($slid !== '') {
                    ff_maybe_trigger_create_pipeline_after_review($slid, $authHeader);
                }
            }
            if (ff_content_item_is_pipeline_generated_snapshot($body)) {
                ff_measure_generation_input_alignment((string)$id, $authHeader, false);
            }
        }
        echo json_encode(['ok' => $up['code'] >= 200 && $up['code'] < 300]);
        exit;
    }

    if ($action === 'sweep_generation_alignment') {
        $lim = max(1, min(200, (int)($_POST['limit'] ?? 40)));
        $force = !empty($_POST['force']);
        $sw = ff_sweep_generation_input_alignment($authHeader, $lim, $force);
        echo json_encode($sw, JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'delete_content_item') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        $r = formatforge_delete_content_item_record($authHeader, $id);
        echo json_encode(['ok' => $r['ok'], 'error' => $r['error'] ?? null]);
        exit;
    }

    if ($action === 'delete_source_link') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        $cr = formatforge_delete_content_items_for_source_link($authHeader, $id);
        if (!$cr['ok']) {
            echo json_encode([
                'ok' => false,
                'error' => $cr['error'] ?? 'Could not delete fetched media for this link',
                'deleted_content_ids' => $cr['deleted_ids'] ?? [],
            ]);
            exit;
        }
        $del = pb_request('DELETE', '/api/collections/input_media/records/' . rawurlencode($id), null, $authHeader);
        $ok = $del['code'] >= 200 && $del['code'] < 300;
        echo json_encode([
            'ok' => $ok,
            'error' => $ok ? null : ($del['body']['message'] ?? 'Delete source_links failed'),
            'deleted_content_ids' => $cr['deleted_ids'] ?? [],
        ]);
        exit;
    }

    if ($action === 'delete_pipeline') {
        $id = trim((string)($_POST['id'] ?? ''));
        if ($id === '') {
            echo json_encode(['ok' => false, 'error' => 'Missing id']);
            exit;
        }
        echo json_encode(formatforge_delete_pipeline_cascade($authHeader, $id), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'sync_instagram_insights') {
        $lim = min(250, max(1, (int) ($_POST['limit'] ?? 80)));
        echo json_encode(formatforge_sync_content_metrics_insights($authHeader, $lim), JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'publish_content') {
        $id = $_POST['id'] ?? '';
        $accountId = $_POST['account_id'] ?? '';
        if (!$id || !$accountId) { echo json_encode(['ok' => false, 'error' => 'Missing params']); exit; }
        $item = pb_request('GET', "/api/collections/output_media/records/{$id}", null, $authHeader);
        $acc = pb_request('GET', "/api/collections/social_accounts/records/{$accountId}", null, $authHeader);
        if ($item['code'] !== 200 || $acc['code'] !== 200) {
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            exit;
        }
        $item = $item['body'];
        if (ff_content_item_is_fetched_for_snapshot($item)) {
            echo json_encode(['ok' => false, 'error' => 'Fetched reference media cannot be published. Publish from pipeline-generated content (Generated content section).']);
            exit;
        }
        $acc = $acc['body'];
        $pub = formatforge_publish_to_instagram($item, $acc, $authHeader, (string) $accountId);
        if (!($pub['ok'] ?? false)) {
            echo json_encode(['ok' => false, 'error' => $pub['error'] ?? 'Publish failed']);
            exit;
        }
        $mediaId = $pub['media_id'] ?? null;
        if ($mediaId) {
            $prevMetrics = is_array($item['metrics'] ?? null) ? $item['metrics'] : [];
            $prevMetrics['instagram_media_id'] = $mediaId;
            $prevMetrics['fetched_at'] = date('c');
            pb_request('PATCH', "/api/collections/output_media/records/{$id}", [
                'status' => 'published',
                'published_at' => date('c'),
                'social_account_id' => $accountId,
                'scheduled_publish_at' => null,
                'metrics' => $prevMetrics,
            ], $authHeader);
        }
        echo json_encode(['ok' => true, 'media_id' => $mediaId]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Unknown or missing action']);
    exit;
}
// Privacy policy page (standalone, no auth required)
$reqUri = $_SERVER['REQUEST_URI'] ?? '';
if (!empty($_GET['privacy']) || strpos($reqUri, '/privacy') !== false) {
    $siteName = htmlspecialchars($CONFIG['site_name']);
    $siteUrl = htmlspecialchars($CONFIG['site_url']);
    $scriptName = htmlspecialchars($_SERVER['SCRIPT_NAME']);
    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Privacy Policy — <?= $siteName ?></title>
    <style>
        :root { --bg: #0a0a0f; --surface: #12121a; --text: #e4e4e7; --muted: #71717a; --accent: #8b5cf6; --border: #2a2a36; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; line-height: 1.6; }
        .app { max-width: 720px; margin: 0 auto; padding: 2rem 1.5rem; }
        h1 { font-size: 1.5rem; margin-bottom: 0.5rem; }
        h2 { font-size: 1.1rem; margin: 1.5rem 0 0.5rem; color: var(--accent); }
        p, li { margin-bottom: 0.75rem; color: var(--muted); }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        .back { display: inline-block; margin-bottom: 1.5rem; font-size: 0.9rem; }
        ul { padding-left: 1.5rem; margin-bottom: 1rem; }
    </style>
</head>
<body>
    <div class="app">
        <a href="<?= $scriptName ?>" class="back">← Back to <?= $siteName ?></a>
        <h1>Privacy Policy</h1>
        <p style="color: var(--muted); font-size: 0.9rem;">Last updated: <?= date('F j, Y') ?></p>

        <h2>1. Overview</h2>
        <p><?= $siteName ?> is a content pipeline tool for AI-generated content curation and Instagram publishing. This policy describes how we collect, use, and protect your information.</p>

        <h2>2. Information We Collect</h2>
        <ul>
            <li><strong>Account data:</strong> Email address and password (hashed) when you create an account.</li>
            <li><strong>Content data:</strong> URLs you submit as content sources, prompts, generated videos, and curation decisions (approve/reject).</li>
            <li><strong>Instagram data:</strong> When you connect an Instagram account via OAuth, we store your Instagram user ID, username, and access token to publish content on your behalf.</li>
            <li><strong>Usage data:</strong> Session cookies for authentication; server logs (IP, timestamps) for operational purposes.</li>
        </ul>

        <h2>3. How We Use Your Information</h2>
        <ul>
            <li>To provide the service: content generation, curation, and publishing to your connected Instagram accounts.</li>
            <li>To authenticate you and manage your account.</li>
            <li>To improve content quality (e.g., novelty detection via embeddings).</li>
            <li>To troubleshoot and maintain the service.</li>
        </ul>

        <h2>4. Third-Party Services</h2>
        <p>We use the following third-party services. Each has its own privacy policy:</p>
        <ul>
            <li><strong>PocketBase:</strong> Authentication and database storage.</li>
            <li><strong>Replicate / fal.ai:</strong> AI video generation.</li>
            <li><strong>Meta (Instagram):</strong> OAuth and content publishing via the Instagram Graph API.</li>
            <li><strong>Garage S3 (or compatible storage):</strong> Storage of generated media files.</li>
            <li><strong>Cloudflare:</strong> CDN and security (if applicable).</li>
        </ul>

        <h2>5. Data Storage and Security</h2>
        <p>Your data is stored on our servers. We use industry-standard practices to protect your information. Passwords are hashed; OAuth tokens are stored securely.</p>

        <h2>6. Your Rights</h2>
        <p>You may:</p>
        <ul>
            <li>Access and export your data.</li>
            <li>Disconnect Instagram accounts at any time.</li>
            <li>Delete your account and associated data by contacting us.</li>
        </ul>

        <h2>7. Cookies</h2>
        <p>We use session cookies for authentication. No third-party tracking cookies are used.</p>

        <h2>8. Changes</h2>
        <p>We may update this policy from time to time. The "Last updated" date at the top reflects the most recent version.</p>

        <h2>9. Contact</h2>
        <p>For questions about this privacy policy or your data, contact us at the email configured for this instance.</p>

        <p style="margin-top: 2rem;"><a href="<?= $scriptName ?>">← Back to <?= $siteName ?></a></p>
    </div>
</body>
</html>
<?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($CONFIG['site_name']) ?></title>
    <script defer src="https://unpkg.com/alpinejs@3.13.3/dist/cdn.min.js"></script>
    <script src="https://unpkg.com/pocketbase@0.26.8/dist/pocketbase.umd.js"></script>
    <style>
        :root { --bg: #0a0a0f; --surface: #12121a; --surface2: #1a1a24; --border: #2a2a36; --text: #e4e4e7; --muted: #71717a; --accent: #8b5cf6; --success: #22c55e; --danger: #ef4444; --warning: #f59e0b; }
        [x-cloak] { display: none !important; }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: system-ui, sans-serif; background: var(--bg); color: var(--text); min-height: 100vh; line-height: 1.5; }
        .app { max-width: 1200px; margin: 0 auto; padding: 1.5rem; }
        .header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .user-info { font-size: 0.875rem; color: var(--muted); }
        .btn { padding: 0.5rem 1rem; border-radius: 8px; font-size: 0.9rem; font-weight: 500; cursor: pointer; border: none; transition: all 0.2s; }
        .btn-primary { background: var(--accent); color: white; }
        .btn-primary:hover { background: #a78bfa; }
        .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
        .btn-success { background: var(--success); color: white; }
        .btn-danger { background: var(--danger); color: white; }
        .btn:disabled { opacity: 0.6; cursor: not-allowed; }
        .btn-busy { opacity: 0.6; pointer-events: none; }
        .card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; }
        .link-input { display: flex; gap: 0.5rem; margin-bottom: 1.5rem; flex-wrap: wrap; }
        .link-input input { flex: 1; min-width: 200px; padding: 0.75rem 1rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); font-size: 1rem; }
        .link-input input::placeholder { color: var(--muted); }
        .content-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 1rem; }
        .content-card { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
        .content-card video, .content-card img { width: 100%; aspect-ratio: 9/16; object-fit: cover; }
        .content-card .body { padding: 1rem; }
        .content-card .body h4 { font-size: 0.95rem; margin-bottom: 0.25rem; }
        .content-card .body p { font-size: 0.8rem; color: var(--muted); }
        .content-card .actions { display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 4px; font-size: 0.75rem; }
        .badge-pending { background: rgba(245,158,11,0.2); color: var(--warning); }
        .badge-approved { background: rgba(34,197,94,0.2); color: var(--success); }
        .badge-rejected { background: rgba(239,68,68,0.2); color: var(--danger); }
        .badge-published { background: rgba(139,92,246,0.2); color: var(--accent); }
        .badge-scheduled { background: rgba(56,189,248,0.18); color: #38bdf8; }
        .badge-generating { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .badge-failed { background: rgba(239,68,68,0.2); color: var(--danger); }
        .badge-publish_failed { background: rgba(251,146,60,0.2); color: #fb923c; }
        .msg { padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .msg.success { background: rgba(34,197,94,0.15); color: #86efac; }
        .msg.error { background: rgba(239,68,68,0.15); color: #fca5a5; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.875rem; margin-bottom: 0.25rem; color: var(--muted); }
        .form-group input { width: 100%; padding: 0.6rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); }
        .ff-nav-backdrop { position: fixed; inset: 0; z-index: 90; background: rgba(0,0,0,0.42); }
        .header { position: relative; z-index: 100; }
        .ff-nav-menu-wrap { position: relative; }
        .ff-nav-dropdown { position: absolute; right: 0; top: calc(100% + 6px); min-width: 12rem; padding: 0.35rem 0; background: var(--surface); border: 1px solid var(--border); border-radius: 10px; box-shadow: 0 14px 44px rgba(0,0,0,0.38); z-index: 101; }
        .ff-nav-dropdown a { display: block; padding: 0.55rem 1rem; color: var(--text); text-decoration: none; font-size: 0.9rem; border: none; background: none; width: 100%; text-align: left; cursor: pointer; font-family: inherit; }
        .ff-nav-dropdown a:hover, .ff-nav-dropdown a:focus { background: var(--surface2); outline: none; }
        .ff-nav-dropdown a.active { color: var(--accent); font-weight: 600; }
        .ff-nav-dropdown .ff-nav-dropdown-logout { display: block; width: 100%; padding: 0.55rem 1rem; text-align: left; font-size: 0.9rem; border: none; background: none; color: var(--text); cursor: pointer; font-family: inherit; border-radius: 0; }
        .ff-nav-dropdown .ff-nav-dropdown-logout:hover { background: var(--surface2); }
        .ff-hamburger { padding: 0.45rem 0.65rem; min-width: 2.5rem; display: inline-flex; align-items: center; justify-content: center; }
        @media (max-width: 768px) {
            .mobile-only { display: inline !important; }
            /* Curate: one card per row; avoids 280px min columns feeling cramped / clipped */
            .content-grid {
                grid-template-columns: minmax(0, 1fr);
                gap: 0.75rem;
            }
            .ff-fetched-summary-thumbs {
                grid-template-columns: repeat(auto-fill, minmax(min(15vw, 48px), 1fr));
                gap: 4px;
            }
            .ff-generated-group-preview-grid {
                grid-template-columns: repeat(auto-fill, minmax(min(15vw, 48px), 1fr));
                gap: 4px;
                max-height: min(30vh, 200px);
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
            .ff-fetched-summary-thumb-wrap,
            .ff-generated-group-preview-cell {
                max-height: 56px;
            }
            .ff-fetched-gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(min(38vw, 118px), 1fr));
                gap: 0.5rem;
                max-height: min(65vh, 640px);
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
        }
        .modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 200; display: flex; align-items: center; justify-content: center; padding: 1rem; }
        .modal-panel { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; max-width: 560px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.25rem; box-shadow: 0 20px 50px rgba(0,0,0,0.4); }
        .modal-panel.modal-panel-wide { max-width: 720px; }
        .modal-panel.modal-panel-xl { max-width: min(92vw, 960px); }
        .ff-reject-annotate-wrap {
            position: relative;
            display: inline-block;
            max-width: 100%;
            margin: 0.5rem 0 0.75rem 0;
            border-radius: 8px;
            overflow: hidden;
            border: 1px solid var(--border);
            background: rgba(0,0,0,0.2);
        }
        .ff-reject-annotate-wrap img {
            display: block;
            max-width: 100%;
            height: auto;
            vertical-align: top;
        }
        .ff-reject-annotate-wrap canvas {
            position: absolute;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            cursor: crosshair;
            touch-action: none;
        }
        .ff-reject-annotate-toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.35rem;
            font-size: 0.8rem;
        }
        .ff-fetched-summary-card { cursor: pointer; transition: border-color 0.15s ease, box-shadow 0.15s ease; }
        .ff-fetched-summary-card:hover { border-color: rgba(139,92,246,0.35); box-shadow: 0 8px 28px rgba(0,0,0,0.2); }
        .ff-fetched-summary-thumbs { display: grid; grid-template-columns: repeat(auto-fill, minmax(56px, 1fr)); gap: 5px; align-items: stretch; margin-top: 0.85rem; }
        .ff-fetched-summary-thumb-wrap { width: 100%; aspect-ratio: 1; max-height: 72px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border); background: #000; }
        .ff-fetched-summary-thumb-wrap video, .ff-fetched-summary-thumb-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ff-generated-group-card { border: 1px solid var(--border); }
        .ff-generated-group-preview-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(56px, 1fr)); gap: 5px; align-items: stretch; margin-top: 0.65rem; max-height: min(22vh, 168px); overflow-y: auto; padding: 2px; -webkit-overflow-scrolling: touch; overscroll-behavior: contain; }
        .ff-generated-group-preview-cell { width: 100%; aspect-ratio: 1; max-height: 72px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border); background: #000; }
        .ff-generated-group-preview-cell video, .ff-generated-group-preview-cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ff-fetched-gallery-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(132px, 1fr)); gap: 0.75rem; margin-top: 1rem; max-height: min(68vh, 620px); overflow-y: auto; padding: 2px 4px 4px 2px; }
        .ff-fetched-gallery-cell { border-radius: 10px; overflow: hidden; border: 1px solid var(--border); background: var(--surface2); cursor: pointer; transition: transform 0.12s ease, box-shadow 0.12s ease; }
        .ff-fetched-gallery-cell:hover { transform: translateY(-2px); box-shadow: 0 10px 28px rgba(0,0,0,0.28); }
        .ff-fetched-gallery-cell video, .ff-fetched-gallery-cell img { width: 100%; aspect-ratio: 9/16; object-fit: cover; display: block; }
        .ff-fetched-gallery-caption { padding: 0.4rem 0.45rem; font-size: 0.7rem; color: var(--muted); line-height: 1.3; max-height: 2.6em; overflow: hidden; }
        .content-card.ff-curate-card-click { cursor: pointer; }
        .ff-curate-modal-media { border-radius: 10px; overflow: hidden; background: #000; max-height: min(52vh, 420px); display: flex; align-items: center; justify-content: center; margin-bottom: 1rem; }
        .ff-curate-modal-media video, .ff-curate-modal-media img { max-width: 100%; max-height: min(52vh, 420px); width: auto; height: auto; display: block; object-fit: contain; }
        .ff-curate-icon-row { display: flex; justify-content: center; align-items: center; gap: 1.25rem; flex-wrap: wrap; margin-top: 1rem; }
        .ff-curate-icon-btn { width: 3.5rem; height: 3.5rem; border-radius: 50%; border: 2px solid var(--border); display: inline-flex; align-items: center; justify-content: center; font-size: 1.5rem; line-height: 1; cursor: pointer; transition: transform 0.12s ease, background 0.12s ease, border-color 0.12s ease; background: var(--surface2); color: var(--text); }
        .ff-curate-icon-btn:hover:not(:disabled) { transform: scale(1.06); }
        .ff-curate-icon-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .ff-curate-icon-btn.ff-approve { border-color: rgba(34,197,94,0.55); color: var(--success); background: rgba(34,197,94,0.12); }
        .ff-curate-icon-btn.ff-reject { border-color: rgba(239,68,68,0.55); color: var(--danger); background: rgba(239,68,68,0.12); }
        .ff-curate-icon-btn.ff-publish { border-color: rgba(139,92,246,0.55); color: var(--accent); background: rgba(139,92,246,0.12); font-size: 1rem; font-weight: 600; }
        .modal-panel h3 { margin-bottom: 0.75rem; font-size: 1.1rem; }
        .modal-actions { display: flex; gap: 0.5rem; justify-content: flex-end; flex-wrap: wrap; margin-top: 1.25rem; }
        .form-group textarea { width: 100%; padding: 0.6rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); font-family: inherit; font-size: 0.9rem; min-height: 120px; resize: vertical; }
        .form-group select.w-full { width: 100%; padding: 0.6rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); }
        .pipeline-preview { font-size: 0.8rem; color: var(--muted); max-height: 6rem; overflow-y: auto; padding: 0.5rem; background: var(--surface2); border-radius: 8px; margin-bottom: 0.75rem; white-space: pre-wrap; word-break: break-word; }
        .ff-debug-panel { border: 1px dashed rgba(245, 158, 11, 0.45); background: rgba(245, 158, 11, 0.04); }
        .ff-debug-panel textarea { width: 100%; box-sizing: border-box; font-family: ui-monospace, monospace; font-size: 0.68rem; line-height: 1.35; background: var(--surface2); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem; resize: vertical; min-height: 14rem; }
        .ff-app-feed { padding: 0 !important; max-width: none !important; }
        .ff-header-feed { display: flex; justify-content: flex-end; align-items: center; padding: 0.65rem 0.85rem; min-height: 3.25rem; margin-bottom: 0 !important; border-bottom: 1px solid var(--border); background: rgba(10,10,15,0.92); position: sticky; top: 0; z-index: 120; }
        .ff-header-full { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem; }
        .ff-nav-menu-scope { padding: 0.5rem 1rem 0.35rem 1rem; border-bottom: 1px solid var(--border); }
        .ff-nav-menu-scope label { display: block; font-size: 0.72rem; color: var(--muted); margin-bottom: 0.25rem; }
        .ff-nav-menu-scope select { width: 100%; padding: 0.4rem 0.5rem; font-size: 0.82rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 8px; }
        .ff-nav-menu-divider { height: 1px; background: var(--border); margin: 0.25rem 0; }
        .ff-feed-view { min-height: calc(100vh - 3.5rem); background: #000; position: relative; }
        .ff-feed-stage { display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: calc(100vh - 3.5rem); padding: 0.5rem 0 5rem 0; padding-bottom: max(5rem, env(safe-area-inset-bottom)); }
        .ff-feed-status { color: var(--muted); font-size: 0.95rem; }
        .ff-feed-empty { text-align: center; padding: 2rem 1.5rem; max-width: 22rem; color: var(--text); }
        .ff-feed-empty .muted { color: var(--muted); font-size: 0.88rem; margin: 0.5rem 0 1rem 0; }
        .ff-feed-meta { width: 100%; max-width: min(92vw, 520px); padding: 0 0.75rem 0.5rem 0.75rem; text-align: center; font-size: 0.82rem; color: var(--muted); }
        .ff-feed-slide-nav { display: flex; align-items: center; justify-content: center; gap: 0.75rem; margin-top: 0.5rem; flex-wrap: wrap; }
        .ff-feed-slide-nav button { font-size: 1.1rem; padding: 0.35rem 0.85rem; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; border-radius: 8px; border: 1px solid var(--border); background: var(--surface2); color: var(--text); }
        .ff-feed-slide-nav button:disabled { opacity: 0.35; cursor: not-allowed; }
        .ff-feed-card { width: 100%; max-width: min(92vw, 520px); touch-action: none; border-radius: 12px; overflow: hidden; border: 1px solid rgba(255,255,255,0.08); background: #0a0a0a; cursor: grab; will-change: transform; }
        .ff-feed-card:active { cursor: grabbing; }
        .ff-feed-media { width: 100%; aspect-ratio: 9/16; max-height: min(72vh, 640px); background: #000; display: flex; align-items: center; justify-content: center; }
        .ff-feed-media video, .ff-feed-media img { width: 100%; height: 100%; object-fit: contain; display: block; }
        .ff-feed-media-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(42vw, 160px), 1fr)); gap: 0.5rem; width: 100%; max-width: min(92vw, 520px); aspect-ratio: unset; max-height: min(68vh, 720px); overflow-y: auto; padding: 0.25rem; box-sizing: border-box; align-content: start; }
        .ff-feed-media-cell { position: relative; aspect-ratio: 9/16; max-height: min(32vh, 280px); background: #0a0a0a; border-radius: 8px; overflow: hidden; border: 2px solid rgba(255,255,255,0.12); cursor: pointer; display: flex; align-items: center; justify-content: center; }
        .ff-feed-media-cell.ff-feed-media-cell-active { border-color: rgba(139,92,246,0.85); box-shadow: 0 0 0 1px rgba(139,92,246,0.35); }
        .ff-feed-media-cell video, .ff-feed-media-cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ff-feed-media-cell .ff-feed-cell-status { position: absolute; left: 0; right: 0; bottom: 0; padding: 0.2rem 0.35rem; font-size: 0.65rem; text-align: center; background: rgba(0,0,0,0.65); color: #fff; text-transform: uppercase; letter-spacing: 0.03em; }
        .ff-curate-modal-media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(38vw, 160px), 1fr)); gap: 0.5rem; width: 100%; max-height: min(58vh, 560px); overflow-y: auto; margin-bottom: 1rem; padding: 2px; }
        .ff-curate-modal-media-grid .ff-curate-modal-cell { position: relative; aspect-ratio: 9/16; max-height: 200px; border-radius: 8px; overflow: hidden; border: 2px solid var(--border); cursor: pointer; background: #000; display: flex; align-items: center; justify-content: center; }
        .ff-curate-modal-media-grid .ff-curate-modal-cell.ff-curate-modal-cell-active { border-color: rgba(139,92,246,0.85); }
        .ff-curate-modal-media-grid .ff-curate-modal-cell video, .ff-curate-modal-media-grid .ff-curate-modal-cell img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .ff-feed-hints { display: flex; justify-content: space-between; align-items: center; width: 100%; max-width: min(92vw, 520px); padding: 0.5rem 0.75rem 0 0.75rem; font-size: 0.72rem; color: rgba(255,255,255,0.45); text-transform: uppercase; letter-spacing: 0.04em; }
        .ff-feed-actions { display: flex; gap: 1rem; justify-content: center; margin-top: 0.75rem; flex-wrap: wrap; }
        .ff-feed-btn { min-width: 8rem; padding: 0.65rem 1.25rem; font-size: 1rem; }
        .ff-feed-progress { margin-top: 0.5rem; font-size: 0.8rem; color: var(--muted); text-align: center; }
        .ff-feed-card-nav { display: flex; gap: 0.75rem; justify-content: center; margin-top: 0.75rem; flex-wrap: wrap; }
        .ff-feed-card-nav button { font-size: 0.85rem; padding: 0.4rem 0.9rem; border-radius: 8px; border: 1px solid var(--border); background: var(--surface2); color: var(--text); cursor: pointer; }
        .ff-feed-card-nav button:disabled { opacity: 0.35; cursor: not-allowed; }
        .ff-msg-feed { position: fixed; left: 50%; transform: translateX(-50%); top: 3.5rem; z-index: 130; max-width: min(92vw, 420px); margin: 0.5rem 0 0 0; font-size: 0.85rem; }
    </style>
</head>
<body x-data="pipelineApp()" x-init="init()" @keydown.escape.window="navMenuOpen = false">
<?php if (!$user): ?>
    <div class="app">
        <h1><?= htmlspecialchars($CONFIG['site_name']) ?></h1>
        <p style="margin: 1rem 0; color: var(--muted);">Log in to manage your content pipeline.</p>
        <?php if (!empty($_GET['login_error'])): ?><div class="msg error">Invalid email or password.</div><?php endif; ?>
        <?php if (!empty($_GET['register_error'])): ?>
            <div class="msg error">
                <?php
                $re = $_GET['register_error'];
                echo htmlspecialchars($re === 'not_allowed' ? 'Account creation is only allowed from the internal network.' : ($re === 'validation' ? 'Passwords must match.' : ($re === 'password_short' ? 'Password must be at least 8 characters.' : $re)));
                ?>
            </div>
        <?php endif; ?>
        <div style="display: flex; gap: 1.5rem; flex-wrap: wrap; align-items: flex-start;">
            <div>
                <h3 style="margin-bottom: 0.75rem; font-size: 1rem;">Log in</h3>
                <form method="post" action="<?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?>">
                    <input type="hidden" name="action" value="login">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required>
                    </div>
                    <button type="submit" class="btn btn-primary">Log in</button>
                </form>
            </div>
            <?php if (is_internal_network()): ?>
            <div style="border-left: 1px solid var(--border); padding-left: 1.5rem;">
                <h3 style="margin-bottom: 0.75rem; font-size: 1rem;">Create account</h3>
                <p style="font-size: 0.8rem; color: var(--muted); margin-bottom: 0.75rem;">You're on the internal network. Create a new account.</p>
                <form method="post" action="<?= htmlspecialchars($_SERVER['SCRIPT_NAME']) ?>">
                    <input type="hidden" name="action" value="register">
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" required>
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="password" name="password" required minlength="8">
                    </div>
                    <div class="form-group">
                        <label>Confirm password</label>
                        <input type="password" name="password_confirm" required minlength="8">
                    </div>
                    <button type="submit" class="btn btn-secondary">Create account</button>
                </form>
            </div>
            <?php else: ?>
            <p style="font-size: 0.875rem; color: var(--muted);">
                Create an account via PocketBase Admin:
                <a href="<?= htmlspecialchars($CONFIG['pocketbase_admin_url']) ?>" target="_blank" rel="noopener" style="color: var(--accent);">open dashboard</a>
                <span style="display: block; margin-top: 0.35rem; font-size: 0.8rem;">PocketBase admin at <code style="font-size:0.9em;">/_/</code>, API at <code style="font-size:0.9em;">/api/</code>. PHP talks to PocketBase at <code style="font-size:0.9em;"><?= htmlspecialchars($CONFIG['pocketbase_url']) ?></code>.</span>
            </p>
            <?php endif; ?>
        </div>
        <p style="margin-top: 2rem; font-size: 0.8rem;"><a href="<?= htmlspecialchars($CONFIG['site_url'] . $_SERVER['SCRIPT_NAME']) ?>?privacy=1" style="color: var(--muted);">Privacy Policy</a></p>
    </div>
<?php else: ?>
    <div class="app" :class="{ 'ff-app-feed': tab === 'feed' }">
        <div x-show="navMenuOpen" x-cloak class="ff-nav-backdrop" @click="navMenuOpen = false"></div>
        <div class="header" :class="tab === 'feed' ? 'ff-header-feed' : 'ff-header-full'">
            <div x-show="tab !== 'feed'" style="min-width: 0;">
                <h1 role="button" tabindex="0" @click="goFeedHome()" @keydown.enter.prevent="goFeedHome()" style="cursor: pointer; user-select: none;" title="Back to Feed"><?= htmlspecialchars($CONFIG['site_name']) ?> <span style="font-size: 0.75rem; color: var(--muted); font-weight: 500;"><?= htmlspecialchars($CONFIG['app_version']) ?></span></h1>
                <span class="user-info" x-text="'Logged in as ' + (userEmail || '')"></span>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem; margin-left: auto;">
                <div style="display:flex; align-items:center; gap:0.45rem;" x-show="tab !== 'feed' && connectedAccounts().length">
                    <span style="font-size:0.78rem; color:var(--muted);">Scope:</span>
                    <select x-model="selectedScopeAccountId" @change="onScopeAccountChanged()" style="padding: 0.35rem 0.55rem; font-size: 0.82rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 8px; max-width: 220px;">
                        <template x-for="a in connectedAccounts()" :key="a.id">
                            <option :value="a.id" x-text="accountHandle(a)"></option>
                        </template>
                    </select>
                </div>
                <div class="ff-nav-menu-wrap">
                    <button type="button" class="btn btn-secondary ff-hamburger" id="ff-nav-menu-trigger" :aria-expanded="navMenuOpen" aria-controls="ff-nav-menu-panel" aria-label="Menu" @click="navMenuOpen = !navMenuOpen">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                            <line x1="3" y1="6" x2="21" y2="6"></line>
                            <line x1="3" y1="12" x2="21" y2="12"></line>
                            <line x1="3" y1="18" x2="21" y2="18"></line>
                        </svg>
                    </button>
                    <nav id="ff-nav-menu-panel" x-show="navMenuOpen" x-transition x-cloak class="ff-nav-dropdown" @click.stop role="menu" aria-labelledby="ff-nav-menu-trigger">
                        <div class="ff-nav-menu-scope" x-show="connectedAccounts().length" @click.stop>
                            <label for="ff-nav-scope-select">Instagram scope</label>
                            <select id="ff-nav-scope-select" x-model="selectedScopeAccountId" @change="onScopeAccountChanged(); navMenuOpen = false">
                                <template x-for="a in connectedAccounts()" :key="a.id">
                                    <option :value="a.id" x-text="accountHandle(a)"></option>
                                </template>
                            </select>
                        </div>
                        <div class="ff-nav-menu-divider" x-show="connectedAccounts().length"></div>
                        <a href="#" role="menuitem" :class="{ active: tab === 'feed' }" @click.prevent="goFeedHome(); navMenuOpen = false">Feed</a>
                        <a href="#" role="menuitem" :class="{ active: tab === 'curate' }" @click.prevent="openAdminView(); navMenuOpen = false">Admin</a>
                        <a href="#" role="menuitem" :class="{ active: tab === 'accounts' }" @click.prevent="tab = 'accounts'; loadAccounts(); navMenuOpen = false">Accounts</a>
                        <a href="#" role="menuitem" :class="{ active: tab === 'activity' }" @click.prevent="tab = 'activity'; loadContent(); navMenuOpen = false">Activity</a>
                        <div class="ff-nav-menu-divider" x-show="tab === 'feed'"></div>
                        <a href="<?= htmlspecialchars($CONFIG['site_url'] . $_SERVER['SCRIPT_NAME']) ?>?privacy=1" role="menuitem" x-show="tab === 'feed'" @click="navMenuOpen = false">Privacy</a>
                        <form method="post" role="none" style="margin:0;" @click.stop>
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="ff-nav-dropdown-logout" role="menuitem">Log out</button>
                        </form>
                    </nav>
                </div>
                <template x-if="tab !== 'feed'">
                    <span style="display: contents;">
                        <a href="<?= htmlspecialchars($CONFIG['site_url'] . $_SERVER['SCRIPT_NAME']) ?>?privacy=1" style="font-size: 0.875rem; color: var(--muted);">Privacy</a>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="btn btn-secondary">Log out</button>
                        </form>
                    </span>
                </template>
            </div>
        </div>

        <div x-show="msg" x-transition class="msg" :class="[msgError ? 'error' : 'success', tab === 'feed' ? 'ff-msg-feed' : '']" x-text="msg"></div>

        <!-- Feed: swipe / tap review (default home) -->
        <div x-show="tab === 'feed'" x-cloak class="ff-feed-view">
            <div class="ff-feed-stage">
                <template x-if="contentLoading">
                    <p class="ff-feed-status">Loading…</p>
                </template>
                <template x-if="!contentLoading && feedQueue.length === 0">
                    <div class="ff-feed-empty">
                        <p>You're all caught up.</p>
                        <p class="muted">No pending items for review. Pipeline output and other pending posts appear here.</p>
                        <button type="button" class="btn btn-secondary" @click="loadContent()">Refresh</button>
                    </div>
                </template>
                <template x-if="!contentLoading && feedQueue.length > 0">
                    <div style="width: 100%; display: flex; flex-direction: column; align-items: center;">
                        <div class="ff-feed-meta" x-show="feedShowShapeGrid">
                            <div x-text="generatedGroupLabel(feedShapeGroupForFeed)"></div>
                            <p style="margin: 0.35rem 0 0 0; font-size: 0.8rem; color: var(--muted); line-height: 1.35;">Tap a tile to choose which slide approve/reject applies to.</p>
                            <p style="margin: 0.25rem 0 0 0; font-size: 0.78rem; color: rgba(255,255,255,0.5);" x-text="'Selected: ' + (feedSlideIndex + 1) + ' / ' + feedShapeCellsForFeed.length"></p>
                        </div>
                        <div class="ff-feed-card" :style="feedCardTransform()"
                            @pointerdown="feedPointerDown($event)"
                            @pointermove="feedPointerMove($event)"
                            @pointerup="feedPointerUp($event)"
                            @pointercancel="feedPointerCancel($event)">
                            <template x-if="feedShowShapeGrid">
                                <div class="ff-feed-media ff-feed-media-grid">
                                    <template x-for="(c, idx) in feedShapeCellsForFeed" :key="c.id + '-feedgrid-' + idx + '-' + feedIndex">
                                        <div class="ff-feed-media-cell" :class="{ 'ff-feed-media-cell-active': feedSlideIndex === idx }" @click="feedSlideIndex = idx">
                                            <template x-if="c.type === 'reel' || c.type === 'video'">
                                                <video :src="effectiveMediaUrl(c)" muted loop playsinline autoplay></video>
                                            </template>
                                            <template x-if="c.type === 'carousel' || c.type === 'image'">
                                                <img :src="(c.thumbnail_url || effectiveMediaUrl(c) || '')" :alt="contentItemTitle(c)" decoding="async" loading="lazy">
                                            </template>
                                            <span class="ff-feed-cell-status" x-text="String(c.status || '').toLowerCase() === 'pending' ? 'pending' : (c.status || '—')"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="feedActiveUnit() && !feedShowShapeGrid">
                                <template x-for="c in (feedCurrentItem() ? [feedCurrentItem()] : [])" :key="c.id + '-' + feedSlideIndex + '-' + feedIndex">
                                    <div class="ff-feed-media">
                                        <template x-if="c.type === 'reel' || c.type === 'video'">
                                            <video :src="effectiveMediaUrl(c)" muted loop playsinline autoplay></video>
                                        </template>
                                        <template x-if="c.type === 'carousel' || c.type === 'image'">
                                            <img :src="(c.thumbnail_url || effectiveMediaUrl(c) || '')" :alt="contentItemTitle(c)" decoding="async">
                                        </template>
                                    </div>
                                </template>
                            </template>
                        </div>
                        <div class="ff-feed-hints">
                            <span>Reject ←</span>
                            <span style="opacity:0.5;">Swipe</span>
                            <span>→ Approve</span>
                        </div>
                        <div class="ff-feed-actions">
                            <button type="button" class="btn btn-danger ff-feed-btn" @click="feedRejectTap()" :disabled="curating || !feedCurrentPending()">Reject</button>
                            <button type="button" class="btn btn-success ff-feed-btn" @click="feedApproveTap()" :disabled="curating || !feedCurrentPending()">Approve</button>
                        </div>
                        <div class="ff-feed-card-nav">
                            <button type="button" @click="feedIndex = Math.max(0, feedIndex - 1)" :disabled="feedIndex === 0">Previous post</button>
                            <button type="button" @click="feedIndex = Math.min(feedQueue.length - 1, feedIndex + 1)" :disabled="feedIndex >= feedQueue.length - 1">Next post</button>
                        </div>
                        <p class="ff-feed-progress" x-text="'Post ' + (feedIndex + 1) + ' of ' + feedQueue.length"></p>
                    </div>
                </template>
            </div>
        </div>

        <div x-show="garageUrlLocalhostWarning" x-cloak class="msg error" style="font-size: 0.88rem;">
            Some <code>garage_url</code> values point at <strong>127.0.0.1</strong> or <strong>localhost</strong> (internal Garage S3). That only works from the server — browsers will not load media. Set <code>GARAGE_PUBLIC_URL</code> to your public Garage web base (e.g. <code>https://your-bucket.web.your-node.example/</code>), reload php-fpm, then re-fetch or PATCH records so <code>garage_url</code> is publicly reachable.
        </div>

        <div x-show="tab === 'curate' && fetchDebugText" x-transition class="card" style="margin-top: 0.75rem;">
            <p style="font-size: 0.85rem; color: var(--muted); margin: 0 0 0.5rem 0;">Fetch debug — copy the JSON below if you need to share what the server saw:</p>
            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.5rem;">
                <button type="button" class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;" @click="copyFetchDebug()">Copy to clipboard</button>
            </div>
            <textarea readonly x-ref="fetchDebugTa" :value="fetchDebugText" @focus="$el.select()" rows="14" style="width: 100%; box-sizing: border-box; font-family: ui-monospace, monospace; font-size: 0.72rem; line-height: 1.35; background: var(--surface2); color: var(--text); border: 1px solid var(--border); border-radius: 8px; padding: 0.5rem; resize: vertical;"></textarea>
        </div>

        <!-- Curate: Link input + Curate feed -->
        <div x-show="tab === 'curate'" x-transition>
            <div class="card">
                <h3 style="margin-bottom: 0.75rem;">Send links</h3>
                <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">Paste URLs to inspire content generation. Each link is queued for processing.</p>
                <div class="link-input">
                    <input type="url" x-model="linkUrl" placeholder="https://example.com/article-or-video..." @keydown.enter.prevent="addLink()">
                    <span class="badge badge-approved" x-show="scopeAccount()" x-text="'Scoped: ' + accountHandle(scopeAccount())"></span>
                    <button class="btn btn-primary" @click="addLink()" :disabled="!linkUrl.trim() || addingLink">Add link</button>
                </div>
                <div x-show="links.length" style="margin-top: 1rem;">
                    <p style="font-size: 0.875rem; color: var(--muted);">Queued links — <strong style="color: var(--text); font-weight: 600;">Fetch</strong> runs direct HTTP (for file URLs), then gallery-dl, then yt-dlp automatically:</p>
                    <ul style="list-style: none; margin-top: 0.5rem;">
                        <template x-for="l in links" :key="l.id">
                            <li style="padding: 0.5rem 0; font-size: 0.9rem; word-break: break-all; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <span x-text="l.url" style="flex: 1; min-width: 0;"></span>
                                <span x-show="l.metadata?.social_account_id && accounts.length" style="font-size: 0.8rem; color: var(--accent);" x-text="(function(){ const acc = accounts.find(x => x.id === l.metadata?.social_account_id); return acc ? 'for ' + accountHandle(acc) : ''; })()"></span>
                                <span class="badge" :class="queuedLinkBadgeClass(l)" x-text="queuedLinkBadgeText(l)" style="flex-shrink: 0;"></span>
                                <span x-show="l.fetching" style="font-size: 0.8rem; color: var(--muted);">Fetching...</span>
                                <template x-if="queuedLinkNeedsFetchButton(l)">
                                    <button class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" @click="fetchLink(l)">Fetch</button>
                                </template>
                                <button type="button" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" @click.stop="deleteSourceLink(l)">Delete</button>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div style="margin-top: 1.5rem;">
                <h3 style="margin-bottom: 0.75rem;">Media from your links</h3>
                <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">Reference material from <strong style="color: var(--text); font-weight: 600;">Fetch</strong> (gallery-dl / yt-dlp). Use it to inspire new pipelines — do <strong style="color: var(--text);">not</strong> post these clips directly. Instagram posts come from <strong style="color: var(--text);">Generated content</strong> (pipeline output). <?php
$c = $GLOBALS['CONFIG'] ?? [];
$agentOn = !empty($c['cursor_agent_enabled']);
$antfly = formatforge_antfly_novelty_configured();
if ($agentOn && $antfly) {
    echo 'When a fetch is <strong>semantically novel</strong> vs your active pipelines (Antfly), the app writes a trigger under <code style="font-size:0.85em;">.cursor-pipeline/triggers/</code> and spawns the Cursor CLI — see <code style="font-size:0.85em;">.cursor-pipeline/cursor-agent.log</code>.';
} elseif (!$antfly) {
    echo '<strong style="color:var(--warning);">Agent novelty:</strong> set <code style="font-size:0.85em;">ANTFLY_URL</code> (Antfly running) so fetched copy can be compared to pipeline templates; otherwise the create-pipeline agent will not run.';
} else {
    echo '<strong style="color:var(--warning);">Cursor agent disabled</strong> (<code style="font-size:0.85em;">CURSOR_AGENT_ENABLED=0</code>).';
}
?></p>
                <template x-if="contentLoading">
                    <p class="msg">Loading...</p>
                </template>
                <div class="content-grid" x-show="!contentLoading && fetchedLinkGroups.length">
                    <template x-for="g in fetchedLinkGroups" :key="g.key">
                        <div class="content-card ff-fetched-summary-card" @click="openFetchedGalleryModal(g)">
                            <div style="padding: 1rem;">
                                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem;">
                                    <div style="min-width: 0;">
                                        <h4 style="margin: 0 0 0.35rem 0; font-size: 0.95rem; word-break: break-all; line-height: 1.35;" x-text="fetchedGroupLabel(g)"></h4>
                                        <p style="margin: 0; font-size: 0.85rem; color: var(--muted);"><strong style="color: var(--text); font-weight: 600;" x-text="g.items.length"></strong> <span x-text="g.items.length === 1 ? 'file' : 'files'"></span> — open gallery to review (✓ / ✕ per file).</p>
                                    </div>
                                    <span class="badge" style="flex-shrink: 0;" :class="fetchedGroupBadgeClass(g)" x-text="fetchedGroupBadge(g)"></span>
                                    <button type="button" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem; flex-shrink: 0;" @click.stop="deleteFetchedGroup(g)">Delete</button>
                                </div>
                                <div class="ff-fetched-summary-thumbs">
                                    <template x-for="c in fetchedGroupPreviewThumbs(g)" :key="'p-' + c.id">
                                        <div class="ff-fetched-summary-thumb-wrap">
                                            <template x-if="c.type === 'reel' || c.type === 'video'">
                                                <video :src="effectiveMediaUrl(c)" muted loop playsinline preload="metadata"></video>
                                            </template>
                                            <template x-if="c.type === 'carousel' || c.type === 'image'">
                                                <img :src="c.thumbnail_url || effectiveMediaUrl(c) || ''" :alt="contentItemTitle(c)" decoding="async">
                                            </template>
                                        </div>
                                    </template>
                                    <span class="badge badge-pending" style="align-self: center;" x-show="fetchedGroupExtraCount(g) > 0" x-text="'+' + fetchedGroupExtraCount(g)"></span>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <p x-show="!contentLoading && !fetchedFromLinksList.length" class="msg">No fetched media yet. Add a link above and click <strong style="color: var(--text);">Fetch</strong>.</p>
            </div>

            <!-- Pipelines + generated output: single card; Run pipelines first; generated slot groups collapsed (<details>) -->
            <div id="ff-pipelines-section" class="card" style="margin-top: 1.5rem;">
                <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 1rem; margin-bottom: 1rem;">
                    <div>
                        <h2 style="margin-bottom: 0.35rem;">Pipelines</h2>
                        <p style="color: var(--muted); font-size: 0.9rem; max-width: 42rem;">Pipelines are created with <strong style="color: var(--text);">Cursor</strong> (CLI <code style="font-size:0.85em;">agent</code> / IDE) or PocketBase <strong style="color: var(--text);">superuser</strong> on the <code style="font-size:0.85em;">pipelines</code> collection. Here you <strong style="color: var(--text);">run</strong> them (instructions from the Run modal only). Each card shows <strong style="color: var(--text);">when the row was last saved</strong> and <strong style="color: var(--text);">curator reject feedback</strong> (merged into the next Run).</p>
                    </div>
                    <button type="button" class="btn btn-secondary" @click="loadPipelines()">Refresh</button>
                </div>
                <template x-if="pipelinesLoading">
                    <p class="msg">Loading pipelines…</p>
                </template>
                <div class="content-grid" x-show="!pipelinesLoading && pipelines.length">
                    <template x-for="p in pipelines" :key="p.id">
                        <div class="card" style="display: flex; flex-direction: column; gap: 0.5rem;">
                            <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.5rem;">
                                <h3 style="margin: 0; font-size: 1rem;" x-text="pipelineLabel(p)"></h3>
                                <span class="badge" :class="p.is_active !== false ? 'badge-approved' : 'badge-pending'" x-text="p.is_active !== false ? 'Active' : 'Inactive'"></span>
                            </div>
                            <p style="font-size: 0.8rem; color: var(--muted); margin: 0; flex: 1;" x-text="(p.description || '').trim() || (p.prompt_template || '').slice(0, 120) + ((p.prompt_template || '').length > 120 ? '…' : '') || 'No description'"></p>
                            <p style="font-size: 0.72rem; color: var(--muted); margin: 0; line-height: 1.35;" x-text="pipelineEditSummaryLine(p)"></p>
                            <div x-show="pipelineRejectionLog(p).length > 0" style="margin-top: 0.35rem; padding: 0.45rem 0.55rem; background: rgba(255,255,255,0.03); border-radius: 6px; border: 1px solid var(--border);">
                                <div style="display: flex; align-items: center; gap: 0.35rem; flex-wrap: wrap; margin-bottom: 0.3rem;">
                                    <span class="badge badge-pending" style="font-size: 0.65rem;">Curator feedback</span>
                                    <span style="font-size: 0.65rem; color: var(--muted);" x-text="pipelineRejectionLog(p).length + ' note(s) · merged into next Run'"></span>
                                </div>
                                <ul style="margin: 0; padding-left: 1.1rem; font-size: 0.72rem; color: var(--text); line-height: 1.45;">
                                    <template x-for="(entry, idx) in pipelineFeedbackPreview(p, 4)" :key="idx">
                                        <li style="margin-bottom: 0.4rem;">
                                            <span style="color: var(--muted); font-size: 0.68rem;" x-text="pipelineFormatWhen(entry && entry.at)"></span>
                                            <span style="color: var(--muted); font-size: 0.65rem;" x-show="entry && entry.content_item_id"> · <span x-text="String(entry.content_item_id || '').slice(0, 12)"></span></span><br/>
                                            <span x-text="pipelineTruncate(entry && entry.reason, 240)"></span>
                                            <template x-if="entry && entry.annotation_url">
                                                <div style="margin-top: 0.35rem;">
                                                    <a :href="entry.annotation_url" target="_blank" rel="noopener noreferrer" style="font-size: 0.65rem; color: var(--accent);">Open curator markup image</a>
                                                    <img :src="entry.annotation_url" alt="Curator markup" loading="lazy" decoding="async" style="display: block; max-width: 100%; max-height: 140px; margin-top: 0.25rem; border-radius: 6px; border: 1px solid var(--border); object-fit: contain; background: rgba(0,0,0,0.25);">
                                                </div>
                                            </template>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <p style="font-size: 0.75rem; color: var(--muted); margin: 0;">Output: <span x-text="pipelineDisplayOutputType(p)"></span></p>
                            <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-top: 0.25rem;">
                                <button type="button" class="btn btn-success" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;" @click="openRunPipeline(p)" :disabled="p.is_active === false || generating">Run</button>
                                <button type="button" class="btn btn-danger" style="font-size: 0.8rem; padding: 0.35rem 0.75rem;" @click.stop="deletePipeline(p)" :disabled="generating">Delete</button>
                            </div>
                        </div>
                    </template>
                </div>
                <p x-show="!pipelinesLoading && !pipelines.length" class="msg">No pipelines yet. When the Cursor <code style="font-size:0.85em;">agent</code> run finishes it should create <code style="font-size:0.85em;">pipelines</code> records (PocketBase admin API). You can also add rows in PocketBase Admin as superuser.</p>

                <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem; margin-bottom: 0.5rem;">
                        <h3 style="margin: 0;">Generated content</h3>
                        <button type="button" class="btn btn-secondary" @click="loadContent()" :disabled="contentLoading">Refresh</button>
                    </div>
                    <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">Pipeline output from the runs above — each card shows a <strong style="color: var(--text);">mini thumbnail grid</strong> (scroll if there are many). Click the card to open the full gallery for <strong style="color: var(--text);">approve</strong> / <strong style="color: var(--text);">publish</strong> / reject.</p>
                    <div class="content-grid" x-show="!contentLoading && pipelineGeneratedShapeGroups.length">
                        <template x-for="g in pipelineGeneratedShapeGroups" :key="g.key">
                            <div class="content-card ff-fetched-summary-card ff-generated-group-card" @click="openGeneratedGalleryModal(g)">
                                <div style="padding: 1rem;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem;">
                                        <div style="min-width: 0;">
                                            <h4 style="margin: 0 0 0.35rem 0; font-size: 0.95rem; word-break: break-all; line-height: 1.35;" x-text="generatedGroupLabel(g)"></h4>
                                            <p style="margin: 0; font-size: 0.8rem; color: var(--muted); line-height: 1.4;">
                                                <strong style="color: var(--text); font-weight: 600;" x-text="g.items.length"></strong>
                                                <span x-text="g.items.length === 1 ? ' slot' : ' slots'"></span>
                                                <span> — expected </span><span style="color: var(--text);" x-text="shapeSignatureText(g.expected) || 'n/a'"></span>
                                                <span> · actual </span><span style="color: var(--text);" x-text="shapeSignatureText(g.actual) || 'n/a'"></span>
                                            </p>
                                        </div>
                                        <div style="display: flex; align-items: center; gap: 0.5rem; flex-shrink: 0; flex-wrap: wrap; justify-content: flex-end;">
                                            <span class="badge" :class="generatedGroupBadgeClass(g)" x-text="generatedGroupBadge(g)"></span>
                                            <button type="button" class="btn btn-danger" style="padding: 0.25rem 0.5rem; font-size: 0.75rem;" @click.stop="deleteGeneratedGroup(g)" :disabled="curating">Delete</button>
                                        </div>
                                    </div>
                                    <div class="ff-generated-group-preview-grid">
                                        <template x-for="c in g.items" :key="'gp-' + g.key + '-' + c.id">
                                            <div class="ff-generated-group-preview-cell">
                                                <template x-if="c.type === 'reel' || c.type === 'video'">
                                                    <video :src="effectiveMediaUrl(c)" muted loop playsinline preload="metadata"></video>
                                                </template>
                                                <template x-if="c.type === 'carousel' || c.type === 'image'">
                                                    <img :src="c.thumbnail_url || effectiveMediaUrl(c) || ''" :alt="contentItemTitle(c)" decoding="async" loading="lazy">
                                                </template>
                                            </div>
                                        </template>
                                    </div>
                                    <p style="margin: 0.65rem 0 0 0; font-size: 0.78rem; color: var(--muted);">Click card to open full gallery.</p>
                                </div>
                            </div>
                        </template>
                    </div>
                    <p x-show="!contentLoading && !pipelineGeneratedShapeGroups.length" class="msg" style="margin-bottom: 0;">No pipeline-generated content yet. Use <strong style="color: var(--text);">Run</strong> on a pipeline above when ready.</p>
                </div>

                <div style="margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid var(--border);">
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 0.75rem;">
                        <h3 style="margin: 0; font-size: 0.95rem; color: var(--accent);">Pipeline &amp; agent diagnostics</h3>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center;">
                            <span x-show="pipelineDiagnosticsLoading" style="font-size: 0.72rem; color: var(--muted);">Loading…</span>
                            <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="loadPipelineDiagnostics()" :disabled="pipelineDiagnosticsLoading">Refresh diagnostics</button>
                        </div>
                    </div>
                    <div x-show="pipelineDiagnostics" style="margin: 0.65rem 0 0.75rem 0; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                        <span class="badge" x-show="pipelineDiagnostics && Number(pipelineDiagnostics.active_agent_count || 0) > 0" style="background: rgba(245,158,11,0.16); border: 1px solid rgba(245,158,11,0.45); color: #fcd34d;" x-text="'Agents busy: ' + Number(pipelineDiagnostics.active_agent_count || 0)"></span>
                        <span class="badge badge-approved" x-show="pipelineDiagnostics && Number(pipelineDiagnostics.active_agent_count || 0) === 0">No pipeline agents currently busy</span>
                        <span style="font-size: 0.75rem; color: var(--muted);" x-show="pipelineDiagnostics && Array.isArray(pipelineDiagnostics.active_agent_runs) && pipelineDiagnostics.active_agent_runs.length" x-text="pipelineDiagnostics.active_agent_runs.map(r => (r.prompt || '').replace('.md','')).join(', ')"></span>
                    </div>
                    <p style="font-size: 0.72rem; color: var(--muted); margin: 0 0 0.75rem 0;">Live snapshot: Antfly / agent env flags, active pipeline count, recent trigger &amp; prompt files under <code style="font-size:0.85em;">.cursor-pipeline/</code>, JSONL trace (<code style="font-size:0.85em;">pipeline-trace.jsonl</code>), and tail of <code style="font-size:0.85em;">cursor-agent.log</code>. After <strong style="color: var(--text);">Fetch</strong>, use <strong style="color: var(--text);">Refresh diagnostics</strong> to see whether the create-pipeline agent ran or skipped. Thumbnails and Instagram need <strong style="color: var(--text);">HTTPS</strong> public Garage URLs (e.g. <code style="font-size:0.85em;">https://my-bucket.web.&lt;host&gt;/…</code>) — avoid <code style="font-size:0.85em;">http://</code> object URLs on an HTTPS site (mixed content).</p>
                    <?php
                    $ffAgentTtyUrl = trim((string)($CONFIG['agent_web_tty_url'] ?? ''));
                    if (ff_agent_web_tty_link_visible() && $ffAgentTtyUrl !== ''):
                    ?>
                    <div style="margin: 0 0 0.85rem 0; padding: 0.65rem 0.85rem; border: 1px solid var(--border); border-radius: 8px; background: rgba(139,92,246,0.08);">
                        <strong style="font-size: 0.85rem; display: block; margin-bottom: 0.35rem;">Interactive terminal (talk to the agent)</strong>
                        <p style="font-size: 0.75rem; color: var(--muted); margin: 0 0 0.55rem 0; line-height: 1.45;">FormatForge’s PHP app cannot host a <strong>real TTY</strong> in the browser (that needs WebSockets + a persistent shell). Run <a href="https://github.com/tsl0922/ttyd" target="_blank" rel="noopener noreferrer" style="color: var(--accent);">ttyd</a> (or similar) on the server, put it behind nginx with TLS and auth, then set <code style="font-size:0.85em;">AGENT_WEB_TTY_URL</code> in <code style="font-size:0.85em;">.env</code>. Open the link below and run <code style="font-size:0.85em;">agent</code> from your clone, or use Cursor Desktop with SSH.</p>
                        <a href="<?= htmlspecialchars($ffAgentTtyUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-secondary" style="font-size: 0.8rem;">Open browser terminal</a>
                    </div>
                    <?php endif; ?>
                    <textarea readonly rows="22" style="width: 100%; font-family: ui-monospace, monospace; font-size: 0.72rem; line-height: 1.35; box-sizing: border-box;" :value="tab === 'curate' ? pipelineDiagnosticsDisplay() : ''" @focus="$el.select()" spellcheck="false"></textarea>
                </div>
            </div>

            <div style="margin-top: 1.5rem;" x-show="!contentLoading && curateOrphanList.length">
                <h3 style="margin-bottom: 0.75rem;">Other records</h3>
                <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">These items are not classified as fetched media or pipeline output (often legacy data). You can reject them or clean them up in PocketBase Admin.</p>
                <div class="content-grid">
                    <template x-for="c in curateOrphanList" :key="c.id">
                        <div class="content-card ff-curate-card-click" @click="openCurateModal(c)">
                            <template x-if="c.type === 'reel' || c.type === 'video'">
                                <video :src="effectiveMediaUrl(c)" controls muted loop @click.stop></video>
                            </template>
                            <template x-if="c.type === 'carousel' || c.type === 'image'">
                                <img :src="c.thumbnail_url || effectiveMediaUrl(c) || ''" alt="" @click.stop>
                            </template>
                            <div class="body">
                                <h4 x-text="contentItemTitle(c)"></h4>
                                <p x-text="c.prompt?.slice(0,80) || ''"></p>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.25rem; align-items: center;">
                                    <span class="badge" :class="'badge-' + (c.status || 'pending')" x-text="c.status"></span>
                                </div>
                                <div class="actions" @click.stop>
                                    <template x-if="c.status === 'pending' || c.status === 'approved' || c.status === 'scheduled' || c.status === 'publish_failed'">
                                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; width: 100%;">
                                            <span style="font-size: 0.72rem; color: var(--muted);" x-show="c.status !== 'scheduled' && c.status !== 'publish_failed'" x-text="scopeAccount() ? ('Scoped to ' + accountHandle(scopeAccount())) : 'No scope selected'"></span>
                                            <span style="font-size: 0.72rem; color: var(--muted);" x-show="c.status === 'scheduled'" x-text="c.scheduled_publish_at ? ('Auto-post ' + new Date(c.scheduled_publish_at).toLocaleString()) : 'Queued for auto-post'"></span>
                                            <span style="font-size: 0.72rem; color: #fb923c;" x-show="c.status === 'publish_failed'" x-text="contentItemAutoPostError(c) || 'Auto-post failed'"></span>
                                            <button class="btn btn-success" x-show="c.status === 'pending'" @click="approveContent(c)" :disabled="!selectedScopeAccountId">Approve</button>
                                            <button class="btn btn-primary" x-show="c.status === 'approved'" @click="publishContent(c)" :disabled="!selectedScopeAccountId || publishing">Publish</button>
                                            <button class="btn btn-danger" x-show="c.status === 'pending' || c.status === 'approved' || c.status === 'scheduled' || c.status === 'publish_failed'" @click="openRejectContent(c)">Reject</button>
                                        </div>
                                    </template>
                                    <button type="button" class="btn btn-secondary" style="font-size: 0.8rem;" @click="deleteContentItem(c)">Delete</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <div class="card ff-debug-panel" style="margin-top: 1.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <h3 style="margin: 0; font-size: 0.95rem; color: var(--warning);">Debug · Curate</h3>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="refreshServerDebug()">Refresh server bundle</button>
                        <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="clearClientDebugLog()">Clear client log</button>
                        <button type="button" class="btn btn-primary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="copyTabDebugReport('curate')">Copy JSON</button>
                    </div>
                </div>
                <p style="font-size: 0.72rem; color: var(--muted); margin: 0.5rem 0 0.5rem 0;">Client trace for this tab (links, fetch, generated content, pipelines). Use <strong style="color: var(--text);">Refresh server bundle</strong> for session PHP logs. <strong style="color: var(--text);">Copy JSON</strong> loads pipeline diagnostics first and includes them in the export.</p>
                <textarea readonly rows="16" :value="tab === 'curate' ? tabDebugText('curate') : ''" @focus="$el.select()"></textarea>
            </div>
        </div>

        <!-- Accounts -->
        <div x-show="tab === 'accounts'" x-transition>
            <h2>Instagram Accounts</h2>
            <p style="margin-bottom: 1rem; color: var(--muted);">Connect multiple Instagram Business/Creator accounts to publish content.</p>
            <a :href="'?instagram_oauth=1'" class="btn btn-primary" style="margin-bottom: 1.5rem; display: inline-block;" x-text="accounts.length ? 'Connect another account' : 'Connect Instagram Account'"></a>
            <div class="content-grid" x-show="accounts.length">
                <template x-for="a in accounts" :key="a.id">
                    <div class="card" style="display: flex; flex-direction: column; gap: 0.5rem;">
                        <h3 style="margin: 0;"><a :href="'https://instagram.com/' + (a.username || '')" target="_blank" rel="noopener" style="color: var(--accent); text-decoration: none;" x-text="accountHandle(a)"></a></h3>
                        <span class="badge" :class="a.is_active && hasInstagramIdentity(a) ? 'badge-approved' : 'badge-pending'" x-text="a.is_active && hasInstagramIdentity(a) ? 'Active' : (hasInstagramIdentity(a) ? 'Inactive' : 'Reconnect needed')"></span>
                        <a x-show="a.username && !a.username.startsWith('ig_')" :href="'https://instagram.com/' + a.username" target="_blank" rel="noopener" style="font-size: 0.8rem; color: var(--muted);">View on Instagram →</a>
                        <p x-show="!hasInstagramIdentity(a)" style="margin: 0; font-size: 0.8rem; color: var(--warning);">This account is missing Instagram ID. Disconnect and reconnect to fetch the real username.</p>
                        <div style="display: flex; gap: 0.5rem; margin-top: 0.5rem; flex-wrap: wrap;">
                            <a x-show="!a.is_active" href="#" class="btn btn-success" role="button" style="font-size: 0.8rem; padding: 0.35rem 0.75rem; text-decoration: none;" :class="activatingId === a.id ? 'btn-busy' : ''" @click.prevent.stop="activateAccount(a)" x-text="activatingId === a.id ? 'Activating…' : 'Activate'"></a>
                            <a x-show="!hasInstagramIdentity(a)" href="#" class="btn btn-primary" role="button" style="font-size: 0.8rem; padding: 0.35rem 0.75rem; text-decoration: none;" :class="disconnectingId === a.id ? 'btn-busy' : ''" @click.prevent.stop="reconnectAccount(a)" x-text="disconnectingId === a.id ? 'Reconnecting…' : 'Reconnect'"></a>
                            <a x-show="shouldShowRefresh(a)" href="#" class="btn btn-secondary" role="button" style="font-size: 0.8rem; padding: 0.35rem 0.75rem; text-decoration: none;" :class="refreshingId === a.id ? 'btn-busy' : ''" @click.prevent.stop="refreshUsername(a)" x-text="refreshingId === a.id ? 'Refreshing…' : 'Refresh username'"></a>
                            <a href="#" class="btn btn-secondary" role="button" style="font-size: 0.8rem; padding: 0.35rem 0.75rem; text-decoration: none;" @click.prevent.stop="disconnectAccount(a)" :class="disconnectingId === a.id ? 'btn-busy' : ''" x-text="disconnectingId === a.id ? 'Disconnecting…' : 'Disconnect'"></a>
                        </div>
                    </div>
                </template>
            </div>
            <p x-show="!accounts.length" style="color: var(--muted); font-size: 0.9rem;">No accounts connected yet. Click the button above to connect your first Instagram account.</p>

            <div class="card ff-debug-panel" style="margin-top: 1.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <h3 style="margin: 0; font-size: 0.95rem; color: var(--warning);">Debug · Accounts</h3>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="refreshServerDebug()">Refresh server bundle</button>
                        <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="clearClientDebugLog()">Clear client log</button>
                        <button type="button" class="btn btn-primary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="copyTabDebugReport('accounts')">Copy JSON</button>
                    </div>
                </div>
                <p style="font-size: 0.72rem; color: var(--muted); margin: 0.5rem 0 0.5rem 0;">Activate / refresh / disconnect / reconnect flows log PocketBase and PHP actions here.</p>
                <textarea readonly rows="16" :value="tab === 'accounts' ? tabDebugText('accounts') : ''" @focus="$el.select()"></textarea>
            </div>
        </div>

        <!-- Activity: content_items log + Instagram queue / history -->
        <div x-show="tab === 'activity'" x-transition>
            <h2>Activity</h2>
            <p style="margin-bottom: 0.75rem; color: var(--muted); font-size: 0.9rem;">Recent <code style="font-size:0.85em;">content_items</code> for the scoped Instagram account (if any). Includes <strong style="color: var(--text);">scheduled</strong> auto-posts, <strong style="color: var(--text);">published</strong> history, and <strong style="color: var(--text);">publish_failed</strong> when auto-post could not post to Instagram.</p>
            <p x-show="!contentLoading && content.length" style="margin-bottom: 1rem; font-size: 0.82rem; color: var(--muted);">
                <span>Scheduled: <strong style="color: var(--text);" x-text="activityIgCounts().scheduled"></strong></span>
                <span style="margin-left: 1rem;">Published in list: <strong style="color: var(--text);" x-text="activityIgCounts().published"></strong></span>
                <span style="margin-left: 1rem;">Auto-post failed: <strong style="color: var(--text);" x-text="activityIgCounts().failed"></strong></span>
            </p>
            <button class="btn btn-secondary" @click="loadContent()" style="margin-bottom: 1rem;">Refresh</button>
            <template x-if="contentLoading">
                <p class="msg">Loading...</p>
            </template>
            <div x-show="!contentLoading" class="card" style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.82rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Created</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Status</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Account</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Schedule / posted</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Auto-post error</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Type</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Storage key</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Media</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in content" :key="c.id">
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 0.5rem 0.75rem; color: var(--muted);" x-text="c.created ? new Date(c.created).toLocaleString() : '-'"></td>
                                <td style="padding: 0.5rem 0.75rem;"><span class="badge" :class="'badge-' + (c.status || 'pending')" x-text="(c.status || '-').replace(/_/g, ' ')"></span></td>
                                <td style="padding: 0.5rem 0.75rem; color: var(--muted);" x-text="accountHandleById(c.social_account_id)"></td>
                                <td style="padding: 0.5rem 0.75rem; color: var(--muted); max-width: 11rem;" x-text="contentItemIgScheduleOrPosted(c)"></td>
                                <td style="padding: 0.5rem 0.75rem; color: var(--muted); max-width: 14rem; word-break: break-word;" x-text="contentItemAutoPostError(c)"></td>
                                <td style="padding: 0.5rem 0.75rem;" x-text="c.type || '-'"></td>
                                <td style="padding: 0.5rem 0.75rem; font-family: monospace; font-size: 0.75rem; word-break: break-all; color: var(--muted);" x-text="c.garage_key || '(none)'"></td>
                                <td style="padding: 0.5rem 0.75rem;">
                                    <a x-show="effectiveMediaUrl(c)" :href="effectiveMediaUrl(c)" target="_blank" rel="noopener" style="color: var(--accent); font-size: 0.8rem;">Open</a>
                                    <span x-show="!effectiveMediaUrl(c)" style="color: var(--muted);">—</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="!contentLoading && !content.length" class="msg" style="margin-top: 1rem;">No content rows for this scope yet. Run a pipeline or add a link from <strong style="color: var(--text);">Admin</strong> in the menu.</p>

            <div class="card ff-debug-panel" style="margin-top: 1.75rem;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                    <h3 style="margin: 0; font-size: 0.95rem; color: var(--warning);">Debug · Activity</h3>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="refreshServerDebug()">Refresh server bundle</button>
                        <button type="button" class="btn btn-secondary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="clearClientDebugLog()">Clear client log</button>
                        <button type="button" class="btn btn-primary" style="font-size: 0.72rem; padding: 0.3rem 0.55rem;" @click="copyTabDebugReport('activity')">Copy JSON</button>
                    </div>
                </div>
                <p style="font-size: 0.72rem; color: var(--muted); margin: 0.5rem 0 0.5rem 0;">Content list reloads and related API calls are traced here.</p>
                <textarea readonly rows="16" :value="tab === 'activity' ? tabDebugText('activity') : ''" @focus="$el.select()"></textarea>
            </div>
        </div>

        <!-- Run pipeline -->
        <div class="modal-backdrop" x-show="runModal.open" x-cloak x-transition @click.self="runModal.open = false" role="presentation">
            <div class="modal-panel modal-panel-xl" role="dialog" aria-modal="true" aria-labelledby="run-pipeline-title" @click.stop>
                <h3 id="run-pipeline-title">Run pipeline</h3>
                <p style="font-size: 0.9rem; color: var(--accent); margin-bottom: 0.5rem;" x-text="runModal.pipeline ? pipelineLabel(runModal.pipeline) : ''"></p>
                <div class="form-group">
                    <label for="run-source-link">Align to fetched source link (optional)</label>
                    <select id="run-source-link" x-model="runModal.sourceLinkId" style="width: 100%; padding: 0.5rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text);">
                        <option value="">— None (instructions only) —</option>
                        <template x-for="l in links" :key="l.id">
                            <option :value="l.id" x-text="(l.url || l.id).slice(0, 72) + ((l.url || '').length > 72 ? '…' : '') + (l.status === 'fetched' ? ' ✓' : '')"></option>
                        </template>
                    </select>
                    <p style="font-size: 0.72rem; color: var(--muted); margin: 0.35rem 0 0 0;">When set, slot shape comes from fetched source media order/type. Pipeline creation and runs stay aligned to that shape.</p>
                    <p x-show="runModal.sourceLinkId" style="font-size: 0.72rem; color: var(--accent); margin: 0.35rem 0 0 0;" x-text="runSourceShapeSignature().length ? ('Detected source shape: ' + shapeSignatureText(runSourceShapeSignature())) : 'No fetched media rows found for this link yet.'"></p>
                </div>
                <div class="form-group">
                    <label for="run-extra-prompt">Instructions</label>
                    <textarea id="run-extra-prompt" x-model="runModal.extraPrompt" placeholder="e.g. slower pacing, golden hour, no text overlay…"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="runModal.open = false">Cancel</button>
                    <button type="button" class="btn btn-primary" @click="submitRunPipeline()" :disabled="generating || !canRunPipeline()">Run</button>
                </div>
            </div>
        </div>

        <!-- Fetched media: one gallery per source link (click a tile to approve/reject) -->
        <div class="modal-backdrop" x-show="fetchedGalleryModal.open && fetchedGalleryModal.group" x-cloak x-transition @click.self="closeFetchedGalleryModal()" role="presentation" style="z-index: 205;">
            <div class="modal-panel modal-panel-xl" role="dialog" aria-modal="true" aria-labelledby="fetched-gallery-title" @click.stop>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.5rem;">
                    <div style="min-width: 0;">
                        <h3 id="fetched-gallery-title" style="margin: 0 0 0.35rem 0; font-size: 1.05rem; word-break: break-all; line-height: 1.35;" x-text="fetchedGalleryModal.group ? fetchedGroupLabel(fetchedGalleryModal.group) : ''"></h3>
                        <p style="margin: 0; font-size: 0.82rem; color: var(--muted);">Click a tile to open review (✓ approve / ✕ reject). Not for Instagram posting.</p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-shrink: 0; align-items: center;">
                        <button type="button" class="btn btn-danger" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" @click="deleteFetchedGroup(fetchedGalleryModal.group)" :disabled="curating">Delete all</button>
                        <button type="button" class="btn btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" @click="closeFetchedGalleryModal()">Close</button>
                    </div>
                </div>
                <template x-if="fetchedGalleryModal.group">
                    <div class="ff-fetched-gallery-grid">
                        <template x-for="c in fetchedGalleryModal.group.items" :key="c.id">
                            <div class="ff-fetched-gallery-cell" @click="openCurateModal(c)">
                                <template x-if="c.type === 'reel' || c.type === 'video'">
                                    <video :src="effectiveMediaUrl(c)" muted loop playsinline preload="metadata"></video>
                                </template>
                                <template x-if="c.type === 'carousel' || c.type === 'image'">
                                    <img :src="c.thumbnail_url || effectiveMediaUrl(c) || ''" :alt="contentItemTitle(c)" decoding="async">
                                </template>
                                <div class="ff-fetched-gallery-caption" x-text="contentItemTitle(c)"></div>
                                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; padding: 0 0.45rem 0.45rem 0.45rem;">
                                    <span class="badge" style="font-size: 0.65rem; padding: 0.1rem 0.35rem;" :class="'badge-' + (c.status || 'pending')" x-text="c.status"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <!-- Generated media: one gallery per shape run/group -->
        <div class="modal-backdrop" x-show="generatedGalleryModal.open && generatedGalleryModal.group" x-cloak x-transition @click.self="closeGeneratedGalleryModal()" role="presentation" style="z-index: 207;">
            <div class="modal-panel modal-panel-xl" role="dialog" aria-modal="true" aria-labelledby="generated-gallery-title" @click.stop>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.5rem;">
                    <div style="min-width: 0;">
                        <h3 id="generated-gallery-title" style="margin: 0 0 0.35rem 0; font-size: 1.05rem; word-break: break-all; line-height: 1.35;" x-text="generatedGalleryModal.group ? generatedGroupLabel(generatedGalleryModal.group) : ''"></h3>
                        <p style="margin: 0; font-size: 0.82rem; color: var(--muted);">
                            Expected:
                            <strong style="color: var(--text);" x-text="generatedGalleryModal.group ? (shapeSignatureText(generatedGalleryModal.group.expected) || 'n/a') : ''"></strong>
                            —
                            Actual:
                            <strong style="color: var(--text);" x-text="generatedGalleryModal.group ? (shapeSignatureText(generatedGalleryModal.group.actual) || 'n/a') : ''"></strong>
                        </p>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-shrink: 0; align-items: center;">
                        <span class="badge" :class="generatedGalleryModal.group ? generatedGroupBadgeClass(generatedGalleryModal.group) : 'badge-pending'" x-text="generatedGalleryModal.group ? generatedGroupBadge(generatedGalleryModal.group) : ''"></span>
                        <button type="button" class="btn btn-danger" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" @click="deleteGeneratedGroup(generatedGalleryModal.group)" :disabled="curating">Delete all</button>
                        <button type="button" class="btn btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" @click="closeGeneratedGalleryModal()">Close</button>
                    </div>
                </div>
                <template x-if="generatedGalleryModal.group">
                    <div class="ff-fetched-gallery-grid">
                        <template x-for="c in generatedGalleryModal.group.items" :key="c.id">
                            <div class="ff-fetched-gallery-cell" @click="openCurateModal(c)">
                                <template x-if="c.type === 'reel' || c.type === 'video'">
                                    <video :src="effectiveMediaUrl(c)" muted loop playsinline preload="metadata"></video>
                                </template>
                                <template x-if="c.type === 'carousel' || c.type === 'image'">
                                    <img :src="c.thumbnail_url || effectiveMediaUrl(c) || ''" :alt="contentItemTitle(c)" decoding="async">
                                </template>
                                <div class="ff-fetched-gallery-caption" x-text="contentItemTitle(c)"></div>
                                <div style="display: flex; gap: 0.35rem; flex-wrap: wrap; padding: 0 0.45rem 0.45rem 0.45rem;">
                                    <span class="badge" style="font-size: 0.65rem; padding: 0.1rem 0.35rem;" :class="'badge-' + (c.status || 'pending')" x-text="c.status"></span>
                                    <span style="font-size: 0.68rem; color: var(--muted);" x-text="contentShapeKind(c)"></span>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <!-- Curate: click card to review — approve (✓) / reject (✕) -->
        <div class="modal-backdrop" x-show="curateModal.open" x-cloak x-transition @click.self="closeCurateModal()" role="presentation" style="z-index: 210;">
            <div class="modal-panel modal-panel-wide" role="dialog" aria-modal="true" aria-labelledby="curate-modal-title" @click.stop>
                <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 0.75rem; margin-bottom: 0.75rem;">
                    <h3 id="curate-modal-title" style="margin: 0; font-size: 1.05rem; line-height: 1.35;" x-text="curateModal.item ? contentItemTitle(curateModal.item) : 'Review'"></h3>
                    <div style="display: flex; gap: 0.5rem; flex-shrink: 0; align-items: center;">
                        <button type="button" class="btn btn-danger" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" x-show="curateModal.item" @click="deleteContentItem(curateModal.item)" :disabled="curating || publishing">Delete</button>
                        <button type="button" class="btn btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.8rem;" @click="closeCurateModal()">Close</button>
                    </div>
                </div>
                <template x-if="curateModal.item">
                    <div>
                        <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; align-items: center; margin-bottom: 0.5rem;">
                            <span class="badge" :class="'badge-' + (curateModal.item.status || 'pending')" x-text="curateModal.item.status"></span>
                            <span x-show="isFetchedMedia(curateModal.item)" style="font-size: 0.78rem; color: var(--muted);">Fetched reference</span>
                            <span x-show="isPipelineGenerated(curateModal.item)" style="font-size: 0.78rem; color: var(--accent);" x-text="curateModal.item.metadata?.pipeline_name ? ('Pipeline: ' + curateModal.item.metadata.pipeline_name) : 'Pipeline output'"></span>
                        </div>
                        <p x-show="isFetchedMedia(curateModal.item) && curateModal.item.status === 'pending'" style="font-size: 0.8rem; color: var(--muted); margin: 0 0 0.75rem 0;">Approve marks this clip as reviewed (not an Instagram post).</p>
                        <template x-if="curateModalShapeGroupItems().length > 1">
                            <p style="font-size: 0.8rem; color: var(--muted); margin: 0 0 0.5rem 0;">Multi-slot pipeline run — tap a tile to choose which slide approve/reject applies to.</p>
                            <div class="ff-curate-modal-media-grid">
                                <template x-for="(c, idx) in curateModalShapeGroupItems()" :key="'cm-' + c.id + '-' + idx">
                                    <div class="ff-curate-modal-cell" :class="{ 'ff-curate-modal-cell-active': curateModal.item && curateModal.item.id === c.id }" @click="selectCurateGridSlide(c)">
                                        <template x-if="c.type === 'reel' || c.type === 'video'">
                                            <video :src="effectiveMediaUrl(c)" muted loop playsinline preload="metadata"></video>
                                        </template>
                                        <template x-if="c.type === 'carousel' || c.type === 'image'">
                                            <img :src="c.thumbnail_url || effectiveMediaUrl(c) || ''" :alt="contentItemTitle(c)" decoding="async" loading="lazy">
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="curateModalShapeGroupItems().length <= 1">
                            <div class="ff-curate-modal-media">
                                <template x-if="curateModal.item.type === 'reel' || curateModal.item.type === 'video'">
                                    <video :src="effectiveMediaUrl(curateModal.item)" controls playsinline></video>
                                </template>
                                <template x-if="curateModal.item.type === 'carousel' || curateModal.item.type === 'image'">
                                    <img :src="curateModal.item.thumbnail_url || effectiveMediaUrl(curateModal.item) || ''" :alt="contentItemTitle(curateModal.item)" decoding="async">
                                </template>
                            </div>
                        </template>
                        <p style="font-size: 0.85rem; color: var(--muted); margin: 0 0 0.75rem 0; max-height: 4.5rem; overflow-y: auto;" x-text="(curateModal.item.prompt || '').trim() || '—'"></p>
                        <div class="form-group" x-show="!isFetchedMedia(curateModal.item) && (curateModal.item.status === 'pending' || curateModal.item.status === 'approved' || curateModal.item.status === 'scheduled' || curateModal.item.status === 'publish_failed')">
                            <label>Instagram account scope</label>
                            <div style="padding: 0.6rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); width: 100%;">
                                <span x-show="scopeAccount()" x-text="accountHandle(scopeAccount())"></span>
                                <span x-show="!scopeAccount()" style="color: var(--warning);">Select scope in top-right header first.</span>
                            </div>
                        </div>
                        <div class="ff-curate-icon-row" x-show="curateModalShowIconActions()">
                            <button type="button" class="ff-curate-icon-btn ff-approve" x-show="curateModal.item && curateModal.item.status === 'pending'" @click="approveFromCurateModal()" :disabled="!canCurateModalApprove() || curating || publishing" title="Approve" aria-label="Approve">✓</button>
                            <button type="button" class="ff-curate-icon-btn ff-publish" x-show="curateModalShowPublishBtn()" @click="publishFromCurateModal()" :disabled="!canCurateModalPublish() || publishing" title="Publish to Instagram" aria-label="Publish to Instagram">▶</button>
                            <button type="button" class="ff-curate-icon-btn ff-reject" x-show="canCurateModalReject()" @click="rejectFromCurateModal()" :disabled="!canCurateModalReject() || curating || publishing" title="Reject" aria-label="Reject">✕</button>
                        </div>
                        <p x-show="!curateModalShowIconActions()" style="font-size: 0.85rem; color: var(--muted); text-align: center; margin: 1rem 0 0 0;">No approve/reject actions for this state. Use Close or manage in PocketBase Admin.</p>
                    </div>
                </template>
            </div>
        </div>

        <!-- Reject content -->
        <div class="modal-backdrop" x-show="rejectModal.open" x-cloak x-transition @click.self="closeRejectModal()" role="presentation" style="z-index: 220;">
            <div class="modal-panel modal-panel-xl" role="dialog" aria-modal="true" aria-labelledby="reject-title" @click.stop>
                <h3 id="reject-title">Reject content</h3>
                <p style="font-size: 0.85rem; color: var(--muted); margin-bottom: 0.75rem;">Optional note (stored on the item and on the pipeline’s feedback log). For <strong>pipeline-generated images</strong>, draw on the preview to mark what’s wrong — the marked image is uploaded and linked for the next run / agent.</p>
                <template x-if="rejectModalShowsAnnotator()">
                    <div>
                        <div class="ff-reject-annotate-toolbar">
                            <label style="display: flex; align-items: center; gap: 0.35rem; margin: 0;">
                                <span style="color: var(--muted);">Ink</span>
                                <input type="color" x-model="rejectModal.drawColor" style="width: 2rem; height: 1.5rem; padding: 0; border: none; background: transparent; cursor: pointer;" title="Stroke color" />
                            </label>
                            <button type="button" class="btn btn-secondary" style="font-size: 0.78rem; padding: 0.25rem 0.55rem;" @click="clearRejectAnnotation()">Clear drawing</button>
                        </div>
                        <div class="ff-reject-annotate-wrap">
                            <img x-ref="rejectAnnotImg" :src="rejectAnnotImgSrc()" alt="" crossorigin="anonymous" decoding="async" @load="onRejectAnnotImgLoad()">
                            <canvas x-ref="rejectAnnotCanvas"
                                @pointerdown.prevent="rejectAnnotPointerDown($event)"
                                @pointermove.prevent="rejectAnnotPointerMove($event)"
                                @pointerup.prevent="rejectAnnotPointerUp($event)"
                                @pointercancel.prevent="rejectAnnotPointerUp($event)"></canvas>
                        </div>
                    </div>
                </template>
                <p x-show="rejectModal.target && isPipelineGenerated(rejectModal.target) && !rejectModalShowsAnnotator()" style="font-size: 0.8rem; color: var(--muted); margin-bottom: 0.5rem;">Markup is available for <strong>image</strong> / <strong>carousel</strong> outputs. For video, describe the issue in text.</p>
                <div class="form-group">
                    <label for="reject-reason">Reason</label>
                    <textarea id="reject-reason" x-model="rejectModal.reason" placeholder="Why reject?"></textarea>
                </div>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeRejectModal()">Cancel</button>
                    <button type="button" class="btn btn-danger" @click="confirmRejectContent()">Reject</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pipelineApp', () => ({
                PB_URL: '<?= addslashes($CONFIG['pocketbase_public_url']) ?>',
                /** Same as GARAGE_PUBLIC_URL / built virtual-host base — used when garage_url is missing but garage_key is set. */
                garagePublicBase: '<?= addslashes(rtrim((string) ($CONFIG['garage_public_url'] ?? ''), '/')) ?>',
                garageBucket: '<?= addslashes((string) ($CONFIG['garage_bucket'] ?? 'formatforge')) ?>',
                contentItemsCollectionId: '<?= htmlspecialchars(ff_content_items_collection_id(), ENT_QUOTES | ENT_HTML5, 'UTF-8') ?>',
                token: '<?= addslashes($token ?? '') ?>',
                userEmail: '<?= addslashes($user['email'] ?? '') ?>',
                tab: '<?= htmlspecialchars($_GET['tab'] ?? 'feed') ?>',
                navMenuOpen: false,
                feedIndex: 0,
                feedSlideIndex: 0,
                feedSwipe: { active: false, startX: 0, dx: 0, pointerId: null },
                msg: '<?= htmlspecialchars($_GET['msg'] ?? '') ?>',
                msgError: <?= !empty($_GET['msgError']) ? 'true' : 'false' ?>,
                linkUrl: '',
                linkAccountId: '',
                links: [],
                addingLink: false,
                content: [],
                contentLoading: false,
                accounts: [],
                selectedScopeAccountId: '',
                generating: false,
                publishing: false,
                disconnectingId: '',
                refreshingId: '',
                activatingId: '',
                pipelines: [],
                pipelinesLoading: false,
                runModal: {
                    open: false,
                    pipeline: null,
                    extraPrompt: '',
                    sourceLinkId: '',
                },
                curateModal: { open: false, item: null },
                fetchedGalleryModal: { open: false, group: null },
                generatedGalleryModal: { open: false, group: null },
                rejectModal: { open: false, target: null, reason: '', drawColor: '#ef4444', annotDirty: false },
                rejectAnnotDrawing: false,
                rejectAnnotLast: { x: 0, y: 0 },
                curating: false,
                fetchDebugText: '',
                uiDebugLog: [],
                serverDebugBundle: null,
                pipelineDiagnostics: null,
                pipelineDiagnosticsLoading: false,
                garageUrlLocalhostWarning: false,

                isFetchedMedia(c) {
                    if (!c) return false;
                    if (c.metadata && c.metadata.origin === 'fetch') return true;
                    if (c.metadata && (c.metadata.pipeline_id || c.metadata.origin === 'generate')) return false;
                    if (c.metadata && c.metadata.source_url && !c.metadata.pipeline_id) return true;
                    return false;
                },

                isPipelineGenerated(c) {
                    if (!c) return false;
                    return !!(c.metadata && (c.metadata.pipeline_id || c.metadata.origin === 'generate'));
                },

                get fetchedFromLinksList() {
                    return this.content.filter(c => this.isFetchedMedia(c));
                },

                get fetchedLinkGroups() {
                    const fetched = this.fetchedFromLinksList;
                    const byLink = new Map();
                    for (const c of fetched) {
                        const lid = String(c.input_media_id ?? '').trim();
                        if (!byLink.has(lid)) byLink.set(lid, []);
                        byLink.get(lid).push(c);
                    }
                    const groups = [];
                    const consumed = new Set();
                    for (const l of this.links) {
                        const items = byLink.get(l.id);
                        if (items && items.length) {
                            groups.push({
                                key: 'l-' + l.id,
                                linkId: l.id,
                                url: (l.url || '').trim(),
                                linkStatus: l.status || '',
                                items,
                            });
                            consumed.add(l.id);
                        }
                    }
                    for (const [lid, items] of byLink) {
                        if (!items.length) continue;
                        if (lid === '') {
                            groups.push({
                                key: 'nolink',
                                linkId: null,
                                url: '',
                                linkStatus: '',
                                items,
                                noLink: true,
                            });
                            continue;
                        }
                        if (!consumed.has(lid)) {
                            const first = items[0];
                            const metaUrl = String(first?.metadata?.source_url || '').trim();
                            groups.push({
                                key: 'o-' + lid,
                                linkId: lid,
                                url: metaUrl,
                                linkStatus: '',
                                items,
                                orphaned: true,
                            });
                        }
                    }
                    return groups;
                },

                fetchedGroupLabel(g) {
                    if (!g) return '';
                    if (g.noLink) return 'Media (no source link)';
                    const u = (g.url || '').trim();
                    if (u) return u.length > 88 ? u.slice(0, 88) + '…' : u;
                    return 'Fetched media';
                },

                fetchedGroupPreviewThumbs(g) {
                    return (g && g.items) ? g.items.slice(0, 4) : [];
                },

                fetchedGroupExtraCount(g) {
                    if (!g || !g.items) return 0;
                    const n = g.items.length;
                    return n > 4 ? n - 4 : 0;
                },

                /** True when the row has something the UI can show (PB file, Garage URL/key, or thumbnail). */
                contentItemHasDisplayableMedia(c) {
                    if (!c) return false;
                    if (String(c.media_file || '').trim() !== '') return true;
                    if (String(c.garage_key || '').trim() !== '') return true;
                    if (String(c.thumbnail_url || '').trim() !== '') return true;
                    const u = String(this.effectiveMediaUrl(c) || '').trim();
                    return u !== '';
                },

                fetchedGroupReviewState(g) {
                    if (!g || !g.items || !g.items.length) {
                        return { allReviewed: false, hasMedia: false };
                    }
                    const items = g.items;
                    const hasMedia = items.some(c => this.contentItemHasDisplayableMedia(c));
                    const allReviewed = items.every(c => {
                        const s = String(c.status || '').toLowerCase();
                        return s === 'approved' || s === 'rejected';
                    });
                    return { allReviewed, hasMedia };
                },

                queuedLinkHasFetchedMedia(linkId) {
                    const lid = String(linkId || '').trim();
                    if (!lid) return false;
                    return this.fetchedFromLinksList.some(c => String(c.input_media_id || '').trim() === lid && this.contentItemHasDisplayableMedia(c));
                },

                queuedLinkBadgeText(l) {
                    if (!l) return 'pending';
                    if (l.status === 'fetched' || this.queuedLinkHasFetchedMedia(l.id)) return 'fetched';
                    const s = String(l.status || '').trim();
                    return s !== '' ? s : 'pending';
                },

                queuedLinkBadgeClass(l) {
                    if (!l) return 'badge-pending';
                    return (l.status === 'fetched' || this.queuedLinkHasFetchedMedia(l.id)) ? 'badge-approved' : 'badge-pending';
                },

                queuedLinkNeedsFetchButton(l) {
                    if (!l || l.fetching) return false;
                    return l.status !== 'fetched' && !this.queuedLinkHasFetchedMedia(l.id);
                },

                fetchedGroupBadge(g) {
                    if (!g) return '';
                    const st = this.fetchedGroupReviewState(g);
                    if (st.allReviewed) return 'Reviewed';
                    if (g.noLink) return st.hasMedia ? 'Fetched' : 'Unlinked';
                    if (g.orphaned) return st.hasMedia ? 'Fetched' : 'Orphan link';
                    if (st.hasMedia || g.linkStatus === 'fetched') return 'Fetched';
                    return g.linkStatus || 'pending';
                },

                fetchedGroupBadgeClass(g) {
                    if (!g) return 'badge-pending';
                    const st = this.fetchedGroupReviewState(g);
                    if (st.allReviewed) return 'badge-approved';
                    if (g.noLink || g.orphaned) return st.hasMedia ? 'badge-approved' : 'badge-pending';
                    if (st.hasMedia || g.linkStatus === 'fetched') return 'badge-approved';
                    return 'badge-pending';
                },

                get pipelineGeneratedList() {
                    return this.content.filter(c => this.isPipelineGenerated(c));
                },

                contentShapeKind(c) {
                    const t = String(c?.type || '').trim().toLowerCase();
                    return (t === 'image' || t === 'carousel') ? 'image' : 'video';
                },

                shapeSignatureText(sig) {
                    if (!Array.isArray(sig) || !sig.length) return '';
                    return sig.map(x => String(x || '').trim().toLowerCase() === 'image' ? 'img' : 'vid').join(' · ');
                },

                /**
                 * Bucket key for grouping pipeline outputs into one shape run.
                 * Prefer slotbatch (pipeline + link + slot count + minute) when metadata says multi-slot,
                 * so rows still group if each slot got a different source_shape_run_id or run id was stripped.
                 */
                ffShapeGroupBucketKey(c) {
                    const m = (c && typeof c === 'object' && c.metadata && typeof c.metadata === 'object') ? c.metadata : {};
                    const pid = String(m.pipeline_id || '').trim();
                    const sid = String(c.input_media_id || '').trim();
                    const total = parseInt(m.source_shape_total ?? m.ingredient_total ?? 0, 10) || 0;
                    const idx = parseInt(m.source_shape_index ?? m.ingredient_index ?? 0, 10) || 0;
                    if (total > 1 && idx > 0 && pid) {
                        const created = String(c.created || '').trim();
                        const tmin = created.length >= 16 ? created.slice(0, 16) : created;
                        return `slotbatch:${pid}|${sid}|${total}|${tmin}`;
                    }
                    const runId = String(m.source_shape_run_id || m.ingredient_run_id || '').trim();
                    if (runId) return `run:${runId}`;
                    return `p:${pid || '-'}|s:${sid || '-'}`;
                },

                /** Split a slotbatch bucket into separate runs when the same slot index repeats (two runs in the same minute). */
                ffSplitSlotBatchByDuplicateIndex(itemsRaw) {
                    const items = itemsRaw.slice().sort((a, b) => {
                        const ca = String(a?.created || '');
                        const cb = String(b?.created || '');
                        return ca.localeCompare(cb);
                    });
                    const runs = [];
                    let cur = [];
                    const used = new Set();
                    for (const c of items) {
                        const m = (c && c.metadata && typeof c.metadata === 'object') ? c.metadata : {};
                        const idx = parseInt(m.source_shape_index ?? m.ingredient_index ?? 0, 10) || 0;
                        if (idx > 0 && used.has(idx)) {
                            runs.push(cur);
                            cur = [];
                            used.clear();
                        }
                        cur.push(c);
                        if (idx > 0) used.add(idx);
                    }
                    if (cur.length) runs.push(cur);
                    return runs.length ? runs : [itemsRaw];
                },

                get pipelineGeneratedShapeGroups() {
                    const rows = this.pipelineGeneratedList;
                    const byKey = new Map();
                    for (const c of rows) {
                        const key = this.ffShapeGroupBucketKey(c);
                        if (!byKey.has(key)) byKey.set(key, []);
                        byKey.get(key).push(c);
                    }
                    const groups = [];
                    for (const [key, itemsRaw] of byKey.entries()) {
                        const runs = key.startsWith('slotbatch:')
                            ? this.ffSplitSlotBatchByDuplicateIndex(itemsRaw)
                            : [itemsRaw];
                        let runIdx = 0;
                        for (const itemsUnsorted of runs) {
                            const items = itemsUnsorted.slice().sort((a, b) => {
                                const ia = parseInt(a?.metadata?.source_shape_index ?? a?.metadata?.ingredient_index ?? 0, 10) || 0;
                                const ib = parseInt(b?.metadata?.source_shape_index ?? b?.metadata?.ingredient_index ?? 0, 10) || 0;
                                if (ia && ib && ia !== ib) return ia - ib;
                                const ca = String(a?.created || '');
                                const cb = String(b?.created || '');
                                return ca.localeCompare(cb);
                            });
                            const gkey = runs.length > 1 ? `${key}#${runIdx}` : key;
                            runIdx++;
                            const first = items[0] || {};
                            const m = (first.metadata && typeof first.metadata === 'object') ? first.metadata : {};
                            const expected = Array.isArray(m.source_shape_signature)
                                ? m.source_shape_signature.map(x => String(x || '').toLowerCase())
                                : (Array.isArray(m.ingredient_signature) ? m.ingredient_signature.map(x => String(x || '').toLowerCase()) : []);
                            const actual = items.map(x => this.contentShapeKind(x));
                            const allDone = items.every(x => String(x?.status || '') !== 'generating');
                            const match = expected.length ? (expected.join('|') === actual.join('|')) : true;
                            groups.push({
                                key: gkey,
                                items,
                                expected,
                                actual,
                                match,
                                allDone,
                                sourceLinkId: String(first.input_media_id || '').trim(),
                                pipelineName: String(m.pipeline_name || '').trim(),
                                pipelineId: String(m.pipeline_id || '').trim(),
                                ingredientTopic: String(m.ingredient_topic || '').trim(),
                            });
                        }
                    }
                    return groups.sort((a, b) => {
                        const ac = String(a.items?.[0]?.created || '');
                        const bc = String(b.items?.[0]?.created || '');
                        return bc.localeCompare(ac);
                    });
                },

                generatedGroupLabel(g) {
                    if (!g) return '';
                    const p = String(g.pipelineName || '').trim();
                    const t = String(g.ingredientTopic || '').trim();
                    const link = this.links.find(l => String(l?.id || '') === String(g.sourceLinkId || ''));
                    const u = String(link?.url || '').trim();
                    if (p && t) return `${p} — ${t}`;
                    if (t) return t;
                    if (p && u) return `${p} — ${u.length > 64 ? (u.slice(0, 64) + '…') : u}`;
                    if (p) return p;
                    if (u) return u.length > 88 ? (u.slice(0, 88) + '…') : u;
                    return 'Generated batch';
                },

                generatedGroupBadge(g) {
                    if (!g) return '';
                    if (!g.allDone) return 'Generating';
                    return g.match ? 'Shape OK' : 'Shape mismatch';
                },

                generatedGroupBadgeClass(g) {
                    if (!g) return 'badge-pending';
                    if (!g.allDone) return 'badge-pending';
                    return g.match ? 'badge-approved' : 'badge-rejected';
                },

                get curateOrphanList() {
                    return this.content.filter(c => !this.isFetchedMedia(c) && !this.isPipelineGenerated(c));
                },

                get feedQueue() {
                    const out = [];
                    const groupedIds = new Set();
                    for (const g of this.pipelineGeneratedShapeGroups) {
                        if (!g.allDone) continue;
                        const pend = g.items.filter(c => String(c.status || '').toLowerCase() === 'pending');
                        if (!pend.length) continue;
                        if (g.items.length > 1) {
                            out.push({ mode: 'group', group: g, items: pend });
                            pend.forEach(c => groupedIds.add(c.id));
                        }
                    }
                    for (const c of this.content) {
                        if (String(c.status || '').toLowerCase() !== 'pending') continue;
                        if (!this.contentItemHasDisplayableMedia(c)) continue;
                        if (this.isPipelineGenerated(c) && groupedIds.has(c.id)) continue;
                        if (!this.isFetchedMedia(c) && !this.selectedScopeAccountId) continue;
                        out.push({ mode: 'item', item: c });
                    }
                    return out;
                },

                feedActiveUnit() {
                    const q = this.feedQueue;
                    return q[this.feedIndex] || null;
                },

                /** Full shape group for the current feed card (grid when 2+ slots), including when the queue row is still mode === 'item'. */
                get feedShapeGroupForFeed() {
                    const u = this.feedActiveUnit();
                    if (!u) return null;
                    if (u.mode === 'group') return u.group || null;
                    if (u.mode === 'item' && u.item) {
                        const g = this.shapeGroupForItem(u.item);
                        return g && g.items.length > 1 ? g : null;
                    }
                    return null;
                },

                get feedShapeCellsForFeed() {
                    const g = this.feedShapeGroupForFeed;
                    return g && Array.isArray(g.items) ? g.items : [];
                },

                get feedShowShapeGrid() {
                    return this.feedShapeCellsForFeed.length > 1;
                },

                feedCurrentItem() {
                    const u = this.feedActiveUnit();
                    if (!u) return null;
                    if (u.mode === 'group') {
                        const items = (u.group && u.group.items) ? u.group.items : [];
                        if (!items.length) return null;
                        return items[this.feedSlideIndex] || items[0];
                    }
                    if (u.mode === 'item' && u.item) {
                        const g = this.shapeGroupForItem(u.item);
                        if (g && g.items.length > 1) {
                            const items = g.items;
                            return items[this.feedSlideIndex] || items[0];
                        }
                        return u.item;
                    }
                    return null;
                },

                feedCurrentPending() {
                    const c = this.feedCurrentItem();
                    return !!(c && String(c.status || '').toLowerCase() === 'pending');
                },

                shapeGroupForItem(c) {
                    if (!c) return null;
                    const id = String(c.id || '');
                    for (const g of this.pipelineGeneratedShapeGroups) {
                        if (g.items.some(x => String(x.id) === id)) {
                            return g;
                        }
                    }
                    return null;
                },

                curateModalShapeGroupItems() {
                    const c = this.curateModal.item;
                    if (!c) return [];
                    const g = this.shapeGroupForItem(c);
                    return g && g.items.length > 1 ? g.items : [];
                },

                selectCurateGridSlide(c) {
                    this.curateModal.item = c;
                },

                feedCardTransform() {
                    if (!this.feedSwipe.active) return '';
                    return 'transform: translateX(' + this.feedSwipe.dx + 'px); transition: none;';
                },

                pipelineLabel(p) {
                    const n = String(p?.name || '').trim();
                    if (n) return n;
                    const tmpl = String(p?.prompt_template || '').trim().replace(/\s+/g, ' ');
                    if (tmpl) return tmpl.length > 52 ? tmpl.slice(0, 52) + '…' : tmpl;
                    const id = String(p?.id || '');
                    return id ? ('Pipeline · ' + id.slice(0, 10)) : 'Pipeline';
                },

                pipelineRejectionLog(p) {
                    const m = p && p.metadata;
                    const log = m && m.rejection_log;
                    return Array.isArray(log) ? log : [];
                },

                pipelineFeedbackPreview(p, max) {
                    const log = this.pipelineRejectionLog(p);
                    if (!log.length) return [];
                    const n = Math.max(1, parseInt(max, 10) || 4);
                    return log.slice(-n).reverse();
                },

                pipelineFormatWhen(iso) {
                    if (!iso || typeof iso !== 'string') return '—';
                    try {
                        const d = new Date(iso);
                        if (Number.isNaN(d.getTime())) return iso;
                        return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
                    } catch (e) {
                        return iso;
                    }
                },

                pipelineRowWasSavedAfterCreate(p) {
                    const c = String(p?.created || '').trim();
                    const u = String(p?.updated || '').trim();
                    if (!c || !u) return false;
                    return u !== c;
                },

                pipelineEditSummaryLine(p) {
                    const u = this.pipelineFormatWhen(p?.updated);
                    const c = this.pipelineFormatWhen(p?.created);
                    if (this.pipelineRowWasSavedAfterCreate(p)) {
                        return `Row last saved ${u} · originally created ${c}`;
                    }
                    return `Row created ${c} (no PocketBase edits yet — timestamps match)`;
                },

                pipelineTruncate(s, maxLen) {
                    const t = String(s == null ? '' : s);
                    const n = Math.max(8, parseInt(maxLen, 10) || 200);
                    if (t.length <= n) return t;
                    return t.slice(0, n) + '…';
                },

                /** Mirrors PHP `ff_pipeline_effective_output_type` (carousel only when backing_shape_signature has 2+ slots). */
                pipelineDisplayOutputType(p) {
                    if (!p || typeof p !== 'object') return 'reel';
                    const t = String(p.output_type || '').trim();
                    if (t !== '') return t;
                    const m = p.metadata;
                    if (!m || typeof m !== 'object' || !m.backing_input_media_id) return 'reel';
                    const sig = Array.isArray(m.backing_shape_signature) ? m.backing_shape_signature.filter(x => String(x || '').trim() !== '') : [];
                    if (sig.length > 1) return 'carousel';
                    if (sig.length === 1) {
                        const k = String(sig[0] || '').toLowerCase();
                        return (k === 'image' || k === 'carousel') ? 'image' : 'reel';
                    }
                    return 'image';
                },

                runSourceShapeSignature() {
                    const sid = String(this.runModal?.sourceLinkId || '').trim();
                    if (!sid) return [];
                    return (this.fetchedFromLinksList || [])
                        .filter(c => String(c?.input_media_id || '') === sid)
                        .slice()
                        .sort((a, b) => String(a?.created || '').localeCompare(String(b?.created || '')))
                        .map(c => this.contentShapeKind(c));
                },

                contentItemTitle(c) {
                    const t = String(c?.title || '').trim();
                    if (t) return t;
                    const pr = String(c?.prompt || '').trim();
                    if (pr) return pr.length > 80 ? pr.slice(0, 80) + '…' : pr;
                    const su = String(c?.metadata?.source_url || '').trim();
                    if (su) {
                        const ig = su.match(/instagram\.com\/(?:p|reel|tv)\/([^/?#]+)/i);
                        if (ig) return 'IG ' + ig[1];
                        return su.length > 72 ? su.slice(0, 72) + '…' : su;
                    }
                    const gk = String(c?.garage_key || '');
                    const leaf = gk.includes('/') ? gk.split('/').pop() : gk;
                    if (leaf && leaf !== 'content') return leaf;
                    if (this.isFetchedMedia(c)) return 'Fetched media';
                    const id = String(c?.id || '');
                    return id ? ('Item · ' + id.slice(0, 8)) : 'Untitled';
                },

                /** On HTTPS pages, upgrade http:// media URLs to https:// (mixed content). */
                maybeHttpsMediaUrl(u) {
                    if (!u || typeof u !== 'string') return u;
                    if (typeof location === 'undefined' || location.protocol !== 'https:') return u;
                    if (u.startsWith('http://')) return 'https://' + u.slice(7);
                    return u;
                },

                encodeGarageKeyPath(key) {
                    if (!key) return '';
                    return String(key).replace(/^\/+/, '').split('/').filter(Boolean).map((s) => encodeURIComponent(s)).join('/');
                },

                /** Prefer server-injected ff_display_media_url (PocketBase /api/files/… when media_file exists), then PB URL, then Garage. */
                effectiveMediaUrl(c) {
                    if (!c) return '';
                    const pbDirect = (c.ff_display_media_url || '').trim();
                    if (pbDirect) return this.maybeHttpsMediaUrl(pbDirect);
                    const fn = c.media_file;
                    const base = (this.PB_URL || '').replace(/\/$/, '');
                    const cid = c.collectionId || this.contentItemsCollectionId;
                    if (fn && cid && c.id && base) {
                        return this.maybeHttpsMediaUrl(`${base}/api/files/${encodeURIComponent(cid)}/${encodeURIComponent(c.id)}/${encodeURIComponent(fn)}`);
                    }
                    let u = (c.garage_url || '') || (c.thumbnail_url || '');
                    if (!u && this.garagePublicBase && c.garage_key) {
                        const path = this.encodeGarageKeyPath(c.garage_key);
                        if (path) {
                            const pb = this.garagePublicBase.replace(/\/$/, '');
                            try {
                                const loc = new URL(pb.startsWith('http') ? pb : `https://${pb}`);
                                const host = loc.hostname.toLowerCase();
                                const b = String(this.garageBucket || 'formatforge').toLowerCase();
                                const vh = b !== '' && host.startsWith(`${b}.web.`);
                                u = vh ? `${pb}/${path}` : `${pb}/${encodeURIComponent(this.garageBucket || 'formatforge')}/${path}`;
                            } catch (e) {
                                u = `${pb}/${path}`;
                            }
                        }
                    }
                    return this.maybeHttpsMediaUrl(u);
                },

                _now() {
                    return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
                },

                pushDebug(kind, detail = {}) {
                    const cap = 500;
                    const row = { ts: new Date().toISOString(), tab: this.tab, kind, detail };
                    this.uiDebugLog.push(row);
                    if (this.uiDebugLog.length > cap) {
                        this.uiDebugLog.splice(0, this.uiDebugLog.length - cap);
                    }
                },

                clearClientDebugLog() {
                    this.uiDebugLog = [];
                    this.pushDebug('client_log_cleared', {});
                },

                cloneForDebug(obj, depth = 0) {
                    if (depth > 10) return '[max depth]';
                    if (obj === null || typeof obj !== 'object') {
                        if (typeof obj === 'string' && obj.length > 4000) {
                            return obj.slice(0, 4000) + '…(' + obj.length + ' chars)';
                        }
                        return obj;
                    }
                    if (Array.isArray(obj)) {
                        if (obj.length > 100) {
                            const head = obj.slice(0, 50).map(x => this.cloneForDebug(x, depth + 1));
                            return head.concat([{ _omitted: (obj.length - 50) + ' more elements' }]);
                        }
                        return obj.map(x => this.cloneForDebug(x, depth + 1));
                    }
                    const out = {};
                    let n = 0;
                    for (const [k, v] of Object.entries(obj)) {
                        if (n++ >= 100) {
                            out._truncated_keys = true;
                            break;
                        }
                        const lk = k.toLowerCase();
                        if (/token|secret|password|authorization|cookie|access_token|fb_app_secret|replicate|garage_secret|openai|openrouter|fal_key|antfly_key/.test(lk)) {
                            out[k] = '[redacted]';
                            continue;
                        }
                        if (k === 'logs' && Array.isArray(v)) {
                            const slice = v.slice(-60);
                            out[k] = slice.map(x => this.cloneForDebug(x, depth + 1));
                            if (v.length > 60) out._logs_total = v.length;
                            continue;
                        }
                        out[k] = this.cloneForDebug(v, depth + 1);
                    }
                    return out;
                },

                async postToApp(fd) {
                    const action = String(fd.get('action') || '');
                    const fields = {};
                    for (const [k, v] of fd.entries()) {
                        const s = typeof v === 'string' ? v : String(v);
                        const lk = k.toLowerCase();
                        if (/password|token|secret|authorization|cookie|access_token/.test(lk)) {
                            fields[k] = '[redacted]';
                        } else {
                            fields[k] = s.length > 6000 ? (s.slice(0, 6000) + '…(' + s.length + ' chars)') : s;
                        }
                    }
                    this.pushDebug('app_post_start', { action, fields });
                    const t0 = this._now();
                    try {
                        const r = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
                        const text = await r.text();
                        let json = {};
                        try {
                            json = text ? JSON.parse(text) : {};
                        } catch (e) {
                            json = { _parseError: String(e), _rawPreview: text.slice(0, 5000), _rawLen: text.length };
                        }
                        const doneDetail = {
                            action,
                            httpStatus: r.status,
                            ok: r.ok,
                            ms: Math.round(this._now() - t0),
                            response: this.cloneForDebug(json),
                        };
                        if (typeof json.pb_http_status === 'number') {
                            doneDetail.pocketbaseHttp = json.pb_http_status;
                        }
                        this.pushDebug('app_post_done', doneDetail);
                        return json;
                    } catch (e) {
                        this.pushDebug('app_post_error', { action, error: String(e) });
                        throw e;
                    }
                },

                async pbGet(pathSuffix, label) {
                    const m = pathSuffix.match(/^\/api\/collections\/([^/]+)\/records(?:\?(.*))?$/);
                    this.pushDebug('pb_get_start', { label, path: pathSuffix, via: 'php_proxy' });
                    const t0 = this._now();
                    try {
                        if (!m) {
                            this.pushDebug('pb_get_bad_path', { label, path: pathSuffix });
                            throw new Error('Unsupported PocketBase path');
                        }
                        const fd = new FormData();
                        fd.append('action', 'pb_proxy');
                        fd.append('collection', m[1]);
                        fd.append('http_method', 'GET');
                        if (m[2]) fd.append('query', m[2]);
                        const json = await this.postToApp(fd);
                        const code = typeof json.pb_http_status === 'number' ? json.pb_http_status : 0;
                        const d = (json.body && typeof json.body === 'object') ? json.body : {};
                        const r = { status: code, ok: code >= 200 && code < 300 };
                        const items = d.items;
                        this.pushDebug('pb_get_done', {
                            label,
                            httpStatus: code,
                            ok: r.ok && json.ok !== false,
                            ms: Math.round(this._now() - t0),
                            itemCount: Array.isArray(items) ? items.length : null,
                            message: d.message || null,
                            proxy: true,
                        });
                        return { r, d };
                    } catch (e) {
                        this.pushDebug('pb_get_error', { label, error: String(e) });
                        throw e;
                    }
                },

                async pbSend(method, pathSuffix, label, init = {}) {
                    const m = pathSuffix.match(/^\/api\/collections\/([^/]+)\/records\/([^/?]+)$/);
                    this.pushDebug('pb_send_start', { label, method, path: pathSuffix, via: 'php_proxy' });
                    const t0 = this._now();
                    try {
                        if (!m || (method !== 'PATCH' && method !== 'DELETE')) {
                            this.pushDebug('pb_send_bad_path', { label, method, path: pathSuffix });
                            throw new Error('Unsupported PocketBase mutation');
                        }
                        const fd = new FormData();
                        fd.append('action', 'pb_proxy');
                        fd.append('collection', m[1]);
                        fd.append('http_method', method);
                        fd.append('record_id', m[2]);
                        if (method === 'PATCH' && init.body !== undefined && init.body !== null) {
                            fd.append('json_body', typeof init.body === 'string' ? init.body : JSON.stringify(init.body));
                        }
                        const json = await this.postToApp(fd);
                        const code = typeof json.pb_http_status === 'number' ? json.pb_http_status : 0;
                        const d = (json.body && typeof json.body === 'object') ? json.body : {};
                        const r = { status: code, ok: code >= 200 && code < 300 };
                        this.pushDebug('pb_send_done', {
                            label,
                            method,
                            httpStatus: code,
                            ok: r.ok && json.ok !== false,
                            ms: Math.round(this._now() - t0),
                            response: this.cloneForDebug(d),
                            proxy: true,
                        });
                        return { r, d };
                    } catch (e) {
                        this.pushDebug('pb_send_error', { label, method, error: String(e) });
                        throw e;
                    }
                },

                uiStateSnapshot() {
                    return {
                        tab: this.tab,
                        linksCount: this.links.length,
                        contentCount: this.content.length,
                        accountsCount: this.accounts.length,
                        pipelinesCount: this.pipelines.length,
                        pbAuthPresent: !!this.pbToken(),
                        userEmail: this.userEmail || null,
                        msg: this.msg || '',
                        msgError: !!this.msgError,
                        contentLoading: !!this.contentLoading,
                        pipelinesLoading: !!this.pipelinesLoading,
                        addingLink: !!this.addingLink,
                        publishing: !!this.publishing,
                        generating: !!this.generating,
                        fetchDebugBytes: (this.fetchDebugText || '').length,
                        viewport: (typeof window !== 'undefined') ? { w: window.innerWidth, h: window.innerHeight } : null,
                        href: (typeof location !== 'undefined') ? location.href : null,
                    };
                },

                tabDebugReport(tabId) {
                    const forTab = this.uiDebugLog.filter(e => {
                        if (tabId === 'pipelines') {
                            return e.tab === 'pipelines' || e.tab === 'curate';
                        }
                        if (tabId === 'feed') {
                            return e.tab === 'feed';
                        }
                        return e.tab === tabId;
                    });
                    const tailAll = this.uiDebugLog.slice(-180);
                    const serverBundle = this.serverDebugBundle
                        ? this.serverDebugBundle
                        : {
                            note: 'Click “Refresh server bundle” on any tab to load PHP session logs + public config.',
                            loaded: false,
                        };
                    const out = {
                        formatforge_ui_debug: '1',
                        reportTab: tabId,
                        exportedAt: new Date().toISOString(),
                        client: {
                            eventsWhileOnThisTab: forTab,
                            recentEventsAllTabs: tailAll,
                            totalClientEvents: this.uiDebugLog.length,
                        },
                        serverBundle,
                        uiSnapshot: this.uiStateSnapshot(),
                    };
                    if (tabId === 'pipelines' || tabId === 'curate') {
                        out.pipelineDiagnostics = this.pipelineDiagnostics ?? null;
                    }
                    return out;
                },

                pipelineDiagnosticsDisplay() {
                    const p = this.pipelineDiagnostics;
                    if (!p) {
                        return '(Click “Refresh diagnostics” to load Antfly/agent flags, pipeline-trace.jsonl tail, and cursor-agent.log tail.)';
                    }
                    try {
                        return JSON.stringify(p, null, 2);
                    } catch (e) {
                        return String(e);
                    }
                },

                tabDebugText(tabId) {
                    try {
                        return JSON.stringify(this.tabDebugReport(tabId), null, 2);
                    } catch (e) {
                        return String(e);
                    }
                },

                async refreshServerDebug() {
                    const fd = new FormData();
                    fd.append('action', 'ui_debug_bundle');
                    try {
                        const d = await this.postToApp(fd);
                        if (d && d.ok) {
                            this.serverDebugBundle = d;
                            if (d.pipeline && this.tab === 'curate') {
                                this.pipelineDiagnostics = { ok: true, ...d.pipeline };
                            }
                            this.msg = 'Server debug bundle refreshed.';
                            this.msgError = false;
                        } else {
                            this.serverDebugBundle = { ok: false, error: (d && d.error) ? d.error : 'bundle_failed', response: this.cloneForDebug(d || {}) };
                            this.msg = 'Server debug bundle failed.';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.serverDebugBundle = { ok: false, error: String(e) };
                        this.msg = 'Server debug request failed.';
                        this.msgError = true;
                    }
                },

                async loadPipelineDiagnostics() {
                    const fd = new FormData();
                    fd.append('action', 'pipeline_diagnostics');
                    this.pipelineDiagnosticsLoading = true;
                    try {
                        const d = await this.postToApp(fd);
                        if (d && d.ok) {
                            this.pipelineDiagnostics = d;
                        } else {
                            this.pipelineDiagnostics = { ok: false, error: (d && d.error) || 'pipeline_diagnostics_failed', detail: this.cloneForDebug(d || {}) };
                        }
                    } catch (e) {
                        this.pipelineDiagnostics = { ok: false, error: String(e) };
                    } finally {
                        this.pipelineDiagnosticsLoading = false;
                    }
                },

                async copyTabDebugReport(tabId) {
                    if (tabId === 'pipelines' || tabId === 'curate') {
                        await this.loadPipelineDiagnostics();
                    }
                    const text = this.tabDebugText(tabId);
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        try {
                            await navigator.clipboard.writeText(text);
                            this.msg = (tabId === 'pipelines' || tabId === 'curate')
                                ? 'Debug JSON copied (includes pipeline diagnostics).'
                                : ('Debug JSON copied (' + tabId + ').');
                            this.msgError = false;
                        } catch (e) {
                            this.msg = 'Clipboard failed.';
                            this.msgError = true;
                        }
                    }
                },

                goFeedHome() {
                    this.navMenuOpen = false;
                    this.tab = 'feed';
                    this.feedIndex = 0;
                    this.feedSlideIndex = 0;
                    this.loadContent();
                },

                openAdminView() {
                    this.navMenuOpen = false;
                    this.tab = 'curate';
                    this.loadLinks();
                    this.loadContent();
                    this.loadPipelines();
                },

                feedClampAfterLoad() {
                    if (this.tab !== 'feed') return;
                    const q = this.feedQueue;
                    if (this.feedIndex >= q.length) this.feedIndex = Math.max(0, q.length - 1);
                    const cells = this.feedShapeCellsForFeed;
                    if (cells.length > 1) {
                        const max = Math.max(0, cells.length - 1);
                        if (this.feedSlideIndex > max) this.feedSlideIndex = max;
                        const cur = cells[this.feedSlideIndex];
                        if (!cur || String(cur.status || '').toLowerCase() !== 'pending') {
                            const ix = cells.findIndex(x => String(x.status || '').toLowerCase() === 'pending');
                            if (ix >= 0) this.feedSlideIndex = ix;
                        }
                    } else {
                        this.feedSlideIndex = 0;
                    }
                },

                feedPointerDown(e) {
                    if (this.rejectModal.open || this.curateModal.open) return;
                    if (e.target && e.target.closest && e.target.closest('button')) return;
                    this.feedSwipe.active = true;
                    this.feedSwipe.startX = e.clientX;
                    this.feedSwipe.dx = 0;
                    this.feedSwipe.pointerId = e.pointerId;
                    try { e.currentTarget.setPointerCapture(e.pointerId); } catch (err) {}
                },

                feedPointerMove(e) {
                    if (!this.feedSwipe.active) return;
                    this.feedSwipe.dx = e.clientX - this.feedSwipe.startX;
                },

                feedPointerUp(e) {
                    if (!this.feedSwipe.active) return;
                    const dx = this.feedSwipe.dx;
                    const pid = this.feedSwipe.pointerId;
                    this.feedSwipe.active = false;
                    this.feedSwipe.dx = 0;
                    this.feedSwipe.pointerId = null;
                    try { if (pid != null) e.currentTarget.releasePointerCapture(pid); } catch (err) {}
                    if (Math.abs(dx) < 80) return;
                    if (!this.feedCurrentPending()) {
                        this.msg = 'Tap a tile that is still pending (see label on each tile).';
                        this.msgError = true;
                        return;
                    }
                    if (dx > 0) this.feedApproveTap();
                    else this.feedRejectTap();
                },

                feedPointerCancel(e) {
                    const pid = this.feedSwipe.pointerId;
                    this.feedSwipe.active = false;
                    this.feedSwipe.dx = 0;
                    this.feedSwipe.pointerId = null;
                    try { if (pid != null && e.currentTarget) e.currentTarget.releasePointerCapture(pid); } catch (err) {}
                },

                async feedApproveTap() {
                    const c = this.feedCurrentItem();
                    if (!c) return;
                    if (String(c.status || '').toLowerCase() !== 'pending') {
                        this.msg = 'Select a pending slide (tap its tile).';
                        this.msgError = true;
                        return;
                    }
                    if (!this.isFetchedMedia(c) && !this.selectedScopeAccountId) {
                        this.msg = 'Choose an Instagram scope in the menu first.';
                        this.msgError = true;
                        return;
                    }
                    await this.approveContent(c);
                    await this.loadContent();
                    this.feedClampAfterLoad();
                },

                feedRejectTap() {
                    const c = this.feedCurrentItem();
                    if (!c) return;
                    if (String(c.status || '').toLowerCase() !== 'pending') {
                        this.msg = 'Select a pending slide (tap its tile).';
                        this.msgError = true;
                        return;
                    }
                    if (!this.isFetchedMedia(c) && !this.selectedScopeAccountId) {
                        this.msg = 'Choose an Instagram scope in the menu first.';
                        this.msgError = true;
                        return;
                    }
                    this.openRejectContent(c);
                },

                init() {
                    this.pushDebug('session_boot', {
                        initialTab: this.tab,
                        href: typeof location !== 'undefined' ? location.href : '',
                        pbPublic: this.PB_URL,
                        userAgent: typeof navigator !== 'undefined' ? navigator.userAgent : '',
                    });
                    if (typeof this.$watch === 'function') {
                        this.$watch('tab', (v, prev) => {
                            if (v === prev) return;
                            this.pushDebug('tab_change', { from: prev, to: v });
                            // pipeline_diagnostics: do not call here — loadPipelines() finally refreshes after list load (avoids duplicate POSTs).
                        });
                        this.$watch('feedIndex', () => {
                            this.feedSlideIndex = 0;
                        });
                    }
                    this.loadAccounts().then(() => {
                        if (this.tab === 'feed') { this.loadContent(); }
                        if (this.tab === 'curate') { this.loadLinks(); this.loadContent(); this.loadPipelines(); }
                        if (this.tab === 'activity') this.loadContent();
                    });
                    if (this.msg === 'connected') this.msg = 'Instagram account connected.';
                    if (this.tab === 'pipelines') {
                        this.tab = 'curate';
                        this.$nextTick(() => {
                            const el = document.getElementById('ff-pipelines-section');
                            if (el) el.scrollIntoView({ behavior: 'smooth' });
                        });
                    }
                    this._syncGarageDisplayWarnings();
                },

                pbToken() {
                    const raw = (this.token || '').trim();
                    return raw.startsWith('Bearer ') ? raw.slice(7).trim() : raw;
                },

                pbHeaders(extra = {}) {
                    const token = this.pbToken();
                    return token ? { ...extra, 'Authorization': token } : { ...extra };
                },

                normalizeUsername(username, igUserId = '') {
                    const cleaned = String(username || '').trim().replace(/^@+/, '');
                    const lowered = cleaned.toLowerCase();
                    if (!cleaned || lowered === 'undefined' || lowered === 'null' || lowered === 'account' || lowered === 'active' || lowered === 'inactive') {
                        return igUserId ? ('ig_' + igUserId) : '';
                    }
                    return cleaned;
                },

                accountHandle(a) {
                    const username = this.normalizeUsername(a?.username, a?.instagram_user_id || '');
                    return username ? ('@' + username) : '@account';
                },

                accountHandleById(accountId) {
                    const id = String(accountId || '').trim();
                    if (!id) return '—';
                    const a = this.accounts.find(x => String(x.id || '') === id);
                    return a ? this.accountHandle(a) : ('…' + id.slice(0, 8));
                },

                activityIgCounts() {
                    const rows = this.content || [];
                    let scheduled = 0;
                    let published = 0;
                    let failed = 0;
                    for (const c of rows) {
                        const s = String(c.status || '').toLowerCase();
                        if (s === 'scheduled') scheduled++;
                        else if (s === 'published') published++;
                        else if (s === 'publish_failed') failed++;
                    }
                    return { scheduled, published, failed };
                },

                contentItemIgScheduleOrPosted(c) {
                    if (!c) return '—';
                    const st = String(c.status || '').toLowerCase();
                    if (st === 'scheduled' && c.scheduled_publish_at) {
                        return 'Due ' + new Date(c.scheduled_publish_at).toLocaleString();
                    }
                    if (st === 'published' && c.published_at) {
                        return 'Posted ' + new Date(c.published_at).toLocaleString();
                    }
                    if (st === 'publish_failed' && c.metadata && c.metadata.auto_post_failure && c.metadata.auto_post_failure.at) {
                        return 'Failed ' + new Date(c.metadata.auto_post_failure.at).toLocaleString();
                    }
                    return '—';
                },

                contentItemAutoPostError(c) {
                    if (!c || String(c.status || '').toLowerCase() !== 'publish_failed') return '';
                    const m = c.metadata && typeof c.metadata === 'object' ? c.metadata.auto_post_failure : null;
                    if (m && m.message) return String(m.message);
                    return '(see PocketBase metadata.auto_post_failure)';
                },

                hasInstagramIdentity(a) {
                    return !!(a?.instagram_user_id);
                },

                connectedAccounts() {
                    return this.accounts.filter(a => this.hasInstagramIdentity(a) && a.is_active);
                },

                shouldShowRefresh(a) {
                    const username = this.normalizeUsername(a?.username, a?.instagram_user_id || '');
                    const isPlaceholder = !username || username.startsWith('ig_');
                    return !!(isPlaceholder && this.hasInstagramIdentity(a));
                },

                normalizeAccount(a) {
                    const igUserId = String(a.instagram_user_id || '').trim();
                    const hasIdentity = this.hasInstagramIdentity(a);
                    return {
                        ...a,
                        username: this.normalizeUsername(a.username, igUserId),
                        is_active: hasIdentity ? ((typeof a.is_active === 'boolean') ? a.is_active : true) : false,
                    };
                },

                scopeStorageKey() {
                    return 'formatforge_scope_account_id';
                },

                scopeAccount() {
                    const id = String(this.selectedScopeAccountId || '').trim();
                    if (!id) return null;
                    return this.accounts.find(a => a.id === id) || null;
                },

                applyPersistedScopeAccount() {
                    const active = this.connectedAccounts();
                    if (!active.length) {
                        this.selectedScopeAccountId = '';
                        return;
                    }
                    let pick = '';
                    try {
                        pick = String(localStorage.getItem(this.scopeStorageKey()) || '').trim();
                    } catch (e) {}
                    if (!pick || !active.some(a => a.id === pick)) {
                        pick = String(active[0].id || '').trim();
                    }
                    this.selectedScopeAccountId = pick;
                    this.linkAccountId = pick;
                },

                onScopeAccountChanged() {
                    const id = String(this.selectedScopeAccountId || '').trim();
                    this.selectedScopeAccountId = id;
                    this.linkAccountId = id;
                    try {
                        if (id) localStorage.setItem(this.scopeStorageKey(), id);
                    } catch (e) {}
                    this.loadLinks();
                    this.loadContent();
                    this.loadPipelines();
                },

                pocketBaseErrorHint(r, d) {
                    const code = r.status || 0;
                    const msg = (d && d.message) ? String(d.message) : '';
                    if (code === 401 || code === 403) {
                        return (msg || 'Not authorized') + ' Try logging out and logging back in to refresh your PocketBase session.';
                    }
                    if (code === 0) return 'Could not reach PocketBase.';
                    return msg || ('HTTP ' + code);
                },

                async loadLinks() {
                    try {
                        const scoped = String(this.selectedScopeAccountId || '').trim();
                        const q = new URLSearchParams();
                        q.set('sort', '-@rowid');
                        const roleF = 'role = "queued_source"';
                        q.set('filter', scoped ? `${roleF} && metadata.social_account_id="${scoped}"` : roleF);
                        const { r, d } = await this.pbGet('/api/collections/input_media/records?' + q.toString(), 'loadLinks');
                        if (r.status !== 200) {
                            this.links = [];
                            return;
                        }
                        this.links = (d.items || []).map(l => ({ ...l, fetching: false }));
                    } catch (e) { this.links = []; }
                },

                copyFetchDebug() {
                    const t = this.fetchDebugText || '';
                    if (!t) return;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(t).then(() => {
                            this.msg = 'Fetch debug copied to clipboard.';
                            this.msgError = false;
                        }).catch(() => {});
                    }
                },

                async fetchLink(l) {
                    if (l.fetching) return;
                    l.fetching = true;
                    this.msg = '';
                    this.fetchDebugText = '';
                    this.pushDebug('fetch_link_click', { link_id: l.id, url: (l.url || '').slice(0, 500) });
                    const fd = new FormData();
                    fd.append('action', 'fetch_link');
                    fd.append('link_id', l.id);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            this.msg = `Fetched ${d.created} file(s)${d.via ? ' via ' + d.via : ''}.`;
                            this.fetchDebugText = '';
                            this.loadLinks();
                            this.loadContent();
                        } else {
                            this.msg = d.error || 'Fetch failed';
                            this.msgError = true;
                            this.fetchDebugText = (typeof d.debug_copy === 'string') ? d.debug_copy : '';
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                        this.fetchDebugText = '';
                    } finally {
                        l.fetching = false;
                    }
                },

                async addLink() {
                    if (!this.linkUrl.trim()) return;
                    if (!this.selectedScopeAccountId) {
                        this.msg = 'Select an Instagram account in the top-right scope selector first.';
                        this.msgError = true;
                        return;
                    }
                    this.addingLink = true;
                    this.msg = '';
                    this.pushDebug('add_link_click', { url: this.linkUrl.trim().slice(0, 500), account_id: this.selectedScopeAccountId || null });
                    const fd = new FormData();
                    fd.append('action', 'add_link');
                    fd.append('url', this.linkUrl.trim());
                    fd.append('account_id', this.selectedScopeAccountId);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            this.msg = 'Link added.';
                            this.linkUrl = '';
                            this.loadLinks();
                        } else {
                            this.msg = d.error || 'Failed';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.addingLink = false;
                    }
                },

                async loadContent() {
                    this.contentLoading = true;
                    this.pushDebug('load_content_start', {});
                    try {
                        const scoped = String(this.selectedScopeAccountId || '').trim();
                        const q = new URLSearchParams();
                        q.set('sort', '-@rowid');
                        q.set('perPage', '200');
                        if (scoped) {
                            q.set('filter', `social_account_id="${scoped}"`);
                        }
                        const { r, d } = await this.pbGet('/api/collections/output_media/records?' + q.toString(), 'loadContent');
                        if (r.status !== 200) {
                            this.content = [];
                            this._syncGarageDisplayWarnings();
                            this.msg = 'Content: ' + this.pocketBaseErrorHint(r, d);
                            this.msgError = true;
                            return;
                        }
                        this.content = (d.items || []).map(c => ({ ...c, selectedAccount: this.selectedScopeAccountId || c.social_account_id || '' }));
                        this._syncGarageDisplayWarnings();
                    } catch (e) { this.msg = ''; this.msgError = false; }
                    finally {
                        this.contentLoading = false;
                        if (this.tab === 'feed') this.feedClampAfterLoad();
                        if (this.tab === 'feed') this.feedRefreshGenerateAfterLoad();
                        this.verifyShapeGatesAfterLoad();
                    }
                },

                /**
                 * Detect shape mismatches for pipeline runs (including rows completed by pipeline-generate outside PHP) and queue a pipeline-agent edit (debounced).
                 */
                async verifyShapeGatesAfterLoad() {
                    const scoped = String(this.selectedScopeAccountId || '').trim();
                    if (!scoped) return;
                    const debounceMs = 45000;
                    try {
                        const k = 'ff_verify_shape_gates_ts_' + scoped;
                        const now = Date.now();
                        const last = parseInt(sessionStorage.getItem(k) || '0', 10);
                        if (now - last < debounceMs) return;
                        sessionStorage.setItem(k, String(now));
                    } catch (e) { /* ignore */ }
                    const fd = new FormData();
                    fd.append('action', 'verify_shape_gates');
                    fd.append('account_id', scoped);
                    try {
                        await this.postToApp(fd);
                    } catch (e) { /* ignore */ }
                },

                /**
                 * After feed content loads, request server-side runs for all pipelines scoped to the selected account (debounced).
                 */
                async feedRefreshGenerateAfterLoad() {
                    if (this.tab !== 'feed') return;
                    const scoped = String(this.selectedScopeAccountId || '').trim();
                    if (!scoped) return;
                    const debounceMs = 45000;
                    try {
                        const k = 'ff_feed_refresh_gen_ts_' + scoped;
                        const now = Date.now();
                        const last = parseInt(sessionStorage.getItem(k) || '0', 10);
                        if (now - last < debounceMs) return;
                        sessionStorage.setItem(k, String(now));
                    } catch (e) { /* ignore */ }
                    const fd = new FormData();
                    fd.append('action', 'feed_refresh_generate');
                    fd.append('account_id', scoped);
                    try {
                        const d = await this.postToApp(fd);
                        if (d && d.ok && (d.started > 0) && d.message) {
                            this.msg = d.message;
                            this.msgError = false;
                        }
                    } catch (e) { /* ignore */ }
                },

                _syncGarageDisplayWarnings() {
                    let localhost = false;
                    const items = this.content || [];
                    for (const c of items) {
                        const u = String(this.effectiveMediaUrl(c) || '').trim();
                        if (!u) continue;
                        try {
                            const h = new URL(u).hostname.toLowerCase();
                            if (h === '127.0.0.1' || h === 'localhost' || h === '[::1]' || h === '::1') {
                                localhost = true;
                            }
                        } catch (e) {}
                    }
                    this.garageUrlLocalhostWarning = localhost;
                },

                async loadAccounts() {
                    this.pushDebug('load_accounts_start', {});
                    try {
                        const { r, d } = await this.pbGet('/api/collections/social_accounts/records?sort=-%40rowid', 'loadAccounts');
                        if (r.status !== 200) {
                            this.accounts = [];
                            this.msg = 'Instagram accounts: ' + this.pocketBaseErrorHint(r, d);
                            this.msgError = true;
                            return;
                        }
                        const items = Array.isArray(d.items) ? d.items : [];
                        const total = (typeof d.totalItems === 'number') ? d.totalItems : null;
                        if (items.length === 0 && total !== null && total > 0) {
                            this.pushDebug('pb_list_rule_mismatch', { collection: 'social_accounts', totalItems: total });
                            this.accounts = [];
                            this.msg = 'PocketBase reports ' + total + ' Instagram account(s), but this login cannot list them. In PocketBase Admin, open the social_accounts collection and set List/View rules so your user can read their rows (or widen rules for testing). Superuser Admin always sees every row.';
                            this.msgError = true;
                            return;
                        }
                        this.accounts = items.map(a => this.normalizeAccount(a));
                        this.applyPersistedScopeAccount();
                        const stale = this.accounts.find(a => this.shouldShowRefresh(a));
                        if (stale) this.refreshUsername(stale, { silent: true });
                    } catch (e) { this.accounts = []; }
                },

                async activateAccount(a) {
                    if (!a.id || this.activatingId) return;
                    if (!this.hasInstagramIdentity(a)) {
                        this.msg = 'This account needs reconnect before it can be activated.';
                        this.msgError = true;
                        return;
                    }
                    this.activatingId = a.id;
                    this.msg = '';
                    this.msgError = false;
                    try {
                        const { r, d } = await this.pbSend('PATCH', '/api/collections/social_accounts/records/' + a.id, 'activateAccount', {
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ is_active: true })
                        });
                        if (r.status >= 200 && r.status < 300) {
                            a.is_active = true;
                            if (!this.selectedScopeAccountId) {
                                this.selectedScopeAccountId = a.id;
                                this.onScopeAccountChanged();
                            }
                            this.msg = 'Account activated.';
                            this.msgError = false;
                        } else {
                            this.msg = d.message || d.error || 'Failed to activate';
                            this.msgError = true;
                        }
                    } catch (e) { this.msg = 'Request failed'; this.msgError = true; }
                    finally { this.activatingId = ''; }
                },

                async refreshUsername(a, opts = {}) {
                    const silent = !!opts.silent;
                    if (this.refreshingId) return;
                    if (!a?.instagram_user_id || !a?.access_token) {
                        if (!silent) {
                            this.msg = 'This account is missing Instagram ID/token. Disconnect and reconnect it first.';
                            this.msgError = true;
                        }
                        return;
                    }
                    this.refreshingId = a.id;
                    if (!silent) {
                        this.msg = '';
                        this.msgError = false;
                    }
                    try {
                        const fd = new FormData();
                        fd.append('action', 'refresh_instagram_username');
                        fd.append('account_id', a.id);
                        const d = await this.postToApp(fd);
                        if (d.ok && d.username) {
                            a.username = this.normalizeUsername(d.username, a.instagram_user_id || '');
                            a.is_active = true;
                            if (!silent) {
                                this.msg = 'Username updated to @' + a.username;
                                this.msgError = false;
                            }
                        } else {
                            if (!silent) {
                                this.msg = d.error || 'Could not refresh';
                                this.msgError = true;
                            }
                        }
                    } catch (e) {
                        if (!silent) {
                            this.msg = 'Request failed';
                            this.msgError = true;
                        }
                    } finally {
                        this.refreshingId = '';
                    }
                },

                async disconnectAccount(a) {
                    if (this.disconnectingId) return;
                    if (!confirm('Disconnect ' + this.accountHandle(a) + '? You can reconnect later.')) return;
                    this.disconnectingId = a.id;
                    this.msgError = false;
                    try {
                        const { r, d } = await this.pbSend('DELETE', '/api/collections/social_accounts/records/' + a.id, 'disconnectAccount');
                        if (r.status >= 200 && r.status < 300) {
                            this.accounts = this.accounts.filter(x => x.id !== a.id);
                            if (this.linkAccountId === a.id) this.linkAccountId = '';
                            if (this.selectedScopeAccountId === a.id) {
                                this.applyPersistedScopeAccount();
                                this.onScopeAccountChanged();
                            }
                            this.msg = 'Account disconnected.';
                            this.msgError = false;
                        } else {
                            this.msg = d.message || d.error || 'Failed to disconnect';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.disconnectingId = '';
                    }
                },

                async reconnectAccount(a) {
                    if (this.disconnectingId) return;
                    if (!confirm('Reconnect ' + this.accountHandle(a) + '? This will remove this stale record and open Instagram connect.')) return;
                    this.disconnectingId = a.id;
                    this.msg = '';
                    this.msgError = false;
                    try {
                        const { r, d } = await this.pbSend('DELETE', '/api/collections/social_accounts/records/' + a.id, 'reconnectAccount_delete');
                        if (r.status >= 200 && r.status < 300) {
                            this.accounts = this.accounts.filter(x => x.id !== a.id);
                            if (this.linkAccountId === a.id) this.linkAccountId = '';
                            if (this.selectedScopeAccountId === a.id) {
                                this.selectedScopeAccountId = '';
                                try { localStorage.removeItem(this.scopeStorageKey()); } catch (e) {}
                            }
                            window.location.href = '?instagram_oauth=1';
                            return;
                        }
                        this.msg = d.message || d.error || 'Failed to reconnect';
                        this.msgError = true;
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.disconnectingId = '';
                    }
                },

                curateModalShowPublishBtn() {
                    const c = this.curateModal.item;
                    if (!c || c.status !== 'approved' || this.isFetchedMedia(c)) return false;
                    return true;
                },

                curateModalShowIconActions() {
                    const c = this.curateModal.item;
                    if (!c) return false;
                    if (c.status === 'pending') return true;
                    if (c.status === 'approved') return true;
                    if (c.status === 'scheduled') return true;
                    if (c.status === 'publish_failed') return true;
                    return false;
                },

                canCurateModalApprove() {
                    const c = this.curateModal.item;
                    if (!c || c.status !== 'pending') return false;
                    if (this.isFetchedMedia(c)) return true;
                    return !!this.selectedScopeAccountId;
                },

                canCurateModalPublish() {
                    const c = this.curateModal.item;
                    if (!c || c.status !== 'approved' || this.isFetchedMedia(c)) return false;
                    return !!this.selectedScopeAccountId;
                },

                canCurateModalReject() {
                    const c = this.curateModal.item;
                    if (!c) return false;
                    return c.status === 'pending' || c.status === 'approved' || c.status === 'scheduled' || c.status === 'publish_failed';
                },

                openFetchedGalleryModal(g) {
                    if (!g || !g.items || !g.items.length) return;
                    this.fetchedGalleryModal.group = g;
                    this.fetchedGalleryModal.open = true;
                },

                closeFetchedGalleryModal() {
                    this.fetchedGalleryModal.open = false;
                    this.fetchedGalleryModal.group = null;
                },

                openGeneratedGalleryModal(g) {
                    if (!g || !g.items || !g.items.length) return;
                    this.generatedGalleryModal.group = g;
                    this.generatedGalleryModal.open = true;
                },

                closeGeneratedGalleryModal() {
                    this.generatedGalleryModal.open = false;
                    this.generatedGalleryModal.group = null;
                },

                openCurateModal(c) {
                    this.curateModal.item = c;
                    this.curateModal.open = true;
                },

                closeCurateModal() {
                    this.curateModal.open = false;
                    this.curateModal.item = null;
                },

                async approveFromCurateModal() {
                    const c = this.curateModal.item;
                    if (!c || !this.canCurateModalApprove()) return;
                    await this.approveContent(c);
                },

                async publishFromCurateModal() {
                    const c = this.curateModal.item;
                    if (!c || !this.canCurateModalPublish()) return;
                    await this.publishContent(c);
                    if (!this.msgError) this.closeCurateModal();
                },

                rejectFromCurateModal() {
                    const c = this.curateModal.item;
                    if (!c || !this.canCurateModalReject()) return;
                    this.curateModal.open = false;
                    this.curateModal.item = null;
                    this.openRejectContent(c);
                },

                async approveContent(c) {
                    if (!this.isFetchedMedia(c) && !this.selectedScopeAccountId) return;
                    this.curating = true;
                    this.pushDebug('approve_content', { content_id: c.id, account_id: this.selectedScopeAccountId || null, fetched: this.isFetchedMedia(c) });
                    const fd = new FormData();
                    fd.append('action', 'approve_content');
                    fd.append('id', c.id);
                    fd.append('account_id', this.isFetchedMedia(c) ? '' : this.selectedScopeAccountId);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) { c.status = 'approved'; this.msg = this.isFetchedMedia(c) ? 'Marked as reviewed.' : 'Approved.'; }
                        else { this.msg = d.error || 'Failed'; this.msgError = true; }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.curating = false;
                    }
                },

                rejectAnnotImgSrc() {
                    const c = this.rejectModal.target;
                    if (!c) return '';
                    return (this.effectiveMediaUrl(c) || '').trim() || (c.thumbnail_url || '').trim() || '';
                },

                rejectModalShowsAnnotator() {
                    const c = this.rejectModal.target;
                    if (!c) return false;
                    if (!this.isPipelineGenerated(c)) return false;
                    const t = String(c.type || '').toLowerCase();
                    return t === 'image' || t === 'carousel';
                },

                closeRejectModal() {
                    this.rejectModal.open = false;
                    this.rejectModal.target = null;
                    this.rejectModal.reason = '';
                    this.rejectModal.annotDirty = false;
                    this.rejectAnnotDrawing = false;
                },

                onRejectAnnotImgLoad() {
                    this.$nextTick(() => {
                        const img = this.$refs.rejectAnnotImg;
                        const canvas = this.$refs.rejectAnnotCanvas;
                        if (!img || !canvas) return;
                        const w = img.clientWidth;
                        const h = img.clientHeight;
                        if (w < 8 || h < 8) return;
                        if (canvas.width !== w || canvas.height !== h) {
                            canvas.width = w;
                            canvas.height = h;
                            this.rejectModal.annotDirty = false;
                        }
                    });
                },

                rejectAnnotCoords(e, canvas) {
                    const r = canvas.getBoundingClientRect();
                    const sx = canvas.width / Math.max(r.width, 1);
                    const sy = canvas.height / Math.max(r.height, 1);
                    return {
                        x: (e.clientX - r.left) * sx,
                        y: (e.clientY - r.top) * sy,
                    };
                },

                rejectAnnotPointerDown(e) {
                    if (!this.rejectModalShowsAnnotator()) return;
                    const canvas = this.$refs.rejectAnnotCanvas;
                    if (!canvas || !canvas.width) return;
                    this.rejectAnnotDrawing = true;
                    this.rejectAnnotLast = this.rejectAnnotCoords(e, canvas);
                    const ctx = canvas.getContext('2d');
                    ctx.fillStyle = this.rejectModal.drawColor || '#ef4444';
                    ctx.beginPath();
                    ctx.arc(this.rejectAnnotLast.x, this.rejectAnnotLast.y, 1.5, 0, Math.PI * 2);
                    ctx.fill();
                    this.rejectModal.annotDirty = true;
                    try { canvas.setPointerCapture(e.pointerId); } catch (err) {}
                },

                rejectAnnotPointerMove(e) {
                    if (!this.rejectAnnotDrawing) return;
                    const canvas = this.$refs.rejectAnnotCanvas;
                    if (!canvas || !canvas.width) return;
                    const ctx = canvas.getContext('2d');
                    const p = this.rejectAnnotCoords(e, canvas);
                    ctx.strokeStyle = this.rejectModal.drawColor || '#ef4444';
                    ctx.lineWidth = 3;
                    ctx.lineCap = 'round';
                    ctx.lineJoin = 'round';
                    ctx.beginPath();
                    ctx.moveTo(this.rejectAnnotLast.x, this.rejectAnnotLast.y);
                    ctx.lineTo(p.x, p.y);
                    ctx.stroke();
                    this.rejectAnnotLast = p;
                    this.rejectModal.annotDirty = true;
                },

                rejectAnnotPointerUp() {
                    this.rejectAnnotDrawing = false;
                },

                clearRejectAnnotation() {
                    const canvas = this.$refs.rejectAnnotCanvas;
                    if (!canvas || !canvas.getContext) return;
                    canvas.getContext('2d').clearRect(0, 0, canvas.width, canvas.height);
                    this.rejectModal.annotDirty = false;
                },

                async composeRejectAnnotationBlob() {
                    const img = this.$refs.rejectAnnotImg;
                    const canvas = this.$refs.rejectAnnotCanvas;
                    if (!img || !canvas || !canvas.width) return null;
                    return new Promise((resolve) => {
                        try {
                            const tmp = document.createElement('canvas');
                            tmp.width = canvas.width;
                            tmp.height = canvas.height;
                            const ctx = tmp.getContext('2d');
                            ctx.drawImage(img, 0, 0, tmp.width, tmp.height);
                            ctx.drawImage(canvas, 0, 0);
                            tmp.toBlob((b) => resolve(b), 'image/png', 0.92);
                        } catch (e) {
                            this.pushDebug('reject_annotation_export_error', { err: String(e) });
                            resolve(null);
                        }
                    });
                },

                openRejectContent(c) {
                    this.rejectModal.target = c;
                    this.rejectModal.reason = '';
                    this.rejectModal.drawColor = '#ef4444';
                    this.rejectModal.annotDirty = false;
                    this.rejectAnnotDrawing = false;
                    this.rejectModal.open = true;
                    this.$nextTick(() => this.onRejectAnnotImgLoad());
                },

                async confirmRejectContent() {
                    const c = this.rejectModal.target;
                    if (!c) return;
                    const reason = (this.rejectModal.reason || '').trim();
                    const fd = new FormData();
                    fd.append('action', 'reject_content');
                    fd.append('id', c.id);
                    fd.append('reason', reason);
                    if (this.rejectModalShowsAnnotator() && this.rejectModal.annotDirty) {
                        const blob = await this.composeRejectAnnotationBlob();
                        if (blob && blob.size > 80) {
                            fd.append('annotation_png', blob, 'reject-annotate.png');
                        }
                    }
                    try {
                        this.pushDebug('reject_content', { content_id: c.id, reason_len: reason.length, annotation: !!(this.rejectModal.annotDirty && this.rejectModalShowsAnnotator()) });
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            c.status = 'rejected';
                            this.msg = 'Rejected.';
                            this.closeRejectModal();
                            this.loadContent();
                        } else {
                            this.msg = d.error || 'Failed';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    }
                },

                async publishContent(c) {
                    if (!this.selectedScopeAccountId) return;
                    this.publishing = true;
                    this.pushDebug('publish_content', { content_id: c.id, account_id: this.selectedScopeAccountId });
                    const fd = new FormData();
                    fd.append('action', 'publish_content');
                    fd.append('id', c.id);
                    fd.append('account_id', this.selectedScopeAccountId);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) { c.status = 'published'; this.msg = 'Published to Instagram!'; this.loadContent(); }
                        else { this.msg = d.error || 'Publish failed'; this.msgError = true; }
                    } finally { this.publishing = false; }
                },

                async loadPipelines() {
                    this.pipelinesLoading = true;
                    this.pushDebug('load_pipelines_start', {});
                    try {
                        const scoped = String(this.selectedScopeAccountId || '').trim();
                        const q = new URLSearchParams();
                        q.set('sort', 'name');
                        if (scoped) {
                            q.set('filter', `metadata.social_account_id="${scoped}"`);
                        }
                        const { r, d } = await this.pbGet('/api/collections/pipelines/records?' + q.toString(), 'loadPipelines');
                        if (r.status !== 200) {
                            this.pipelines = [];
                            this.msg = 'Pipelines: ' + this.pocketBaseErrorHint(r, d);
                            this.msgError = true;
                            return;
                        }
                        this.pipelines = (d.items || []).map(p => ({
                            ...p,
                            is_active: p.is_active !== false,
                        }));
                    } catch (e) {
                        this.pipelines = [];
                        this.msg = 'Could not load pipelines. Apply PocketBase migrations and reload.';
                        this.msgError = true;
                    } finally {
                        this.pipelinesLoading = false;
                        if (this.tab === 'curate') {
                            this.loadPipelineDiagnostics();
                        }
                    }
                },

                async openRunPipeline(p) {
                    this.runModal.pipeline = p;
                    this.runModal.extraPrompt = '';
                    this.runModal.sourceLinkId = '';
                    if (!this.links || this.links.length === 0) {
                        await this.loadLinks();
                    }
                    this.runModal.open = true;
                },

                canRunPipeline() {
                    const p = this.runModal.pipeline;
                    if (!p) return false;
                    if (!this.selectedScopeAccountId) return false;
                    return true;
                },

                async submitRunPipeline() {
                    const p = this.runModal.pipeline;
                    if (!p || !this.canRunPipeline()) return;
                    const ok = await this.runVideoGeneration(p.id, (this.runModal.extraPrompt || '').trim(), (this.runModal.sourceLinkId || '').trim());
                    if (ok) {
                        this.runModal.open = false;
                        this.runModal.extraPrompt = '';
                        this.runModal.sourceLinkId = '';
                    }
                },

                async runVideoGeneration(pipelineId, userPrompt, sourceLinkId) {
                    this.generating = true;
                    this.msg = '';
                    this.msgError = false;
                    if (!this.selectedScopeAccountId) {
                        this.msg = 'Select an Instagram account in the top-right scope selector first.';
                        this.msgError = true;
                        this.generating = false;
                        return false;
                    }
                    this.pushDebug('generate_content', {
                        pipeline_id: pipelineId || null,
                        prompt_len: (userPrompt || '').length,
                        source_id: sourceLinkId || null,
                    });
                    const fd = new FormData();
                    fd.append('action', 'generate_content');
                    fd.append('pipeline_id', pipelineId || '');
                    fd.append('prompt', userPrompt || '');
                    fd.append('account_id', this.selectedScopeAccountId);
                    if (sourceLinkId) {
                        fd.append('source_id', sourceLinkId);
                    }
                    fd.append('type', 'reel');
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            this.msg = d.pending
                                ? (d.message || 'Generation is running on the server. Check the Feed in a minute.')
                                : 'Generation finished. Open the Feed to review the new item.';
                            setTimeout(() => this.loadContent(), d.pending ? 8000 : 3000);
                            return true;
                        }
                        this.msg = d.error || 'Generation failed';
                        this.msgError = true;
                        return false;
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                        return false;
                    } finally {
                        this.generating = false;
                        if (this.tab === 'curate') {
                            this.loadPipelineDiagnostics();
                        }
                    }
                },

                async deleteContentItem(c) {
                    if (!c || !c.id) return;
                    if (!confirm('Delete this content permanently? This cannot be undone.')) return;
                    this.curating = true;
                    this.pushDebug('delete_content_item', { content_id: c.id });
                    const fd = new FormData();
                    fd.append('action', 'delete_content_item');
                    fd.append('id', c.id);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            this.msg = 'Content deleted.';
                            this.msgError = false;
                            this.closeRejectModal();
                            this.closeCurateModal();
                            if (this.fetchedGalleryModal.open && this.fetchedGalleryModal.group) {
                                this.fetchedGalleryModal.group.items = (this.fetchedGalleryModal.group.items || []).filter(x => x.id !== c.id);
                                if (!this.fetchedGalleryModal.group.items.length) this.closeFetchedGalleryModal();
                            }
                            if (this.generatedGalleryModal.open && this.generatedGalleryModal.group) {
                                this.generatedGalleryModal.group.items = (this.generatedGalleryModal.group.items || []).filter(x => x.id !== c.id);
                                if (!this.generatedGalleryModal.group.items.length) this.closeGeneratedGalleryModal();
                            }
                            await this.loadContent();
                        } else {
                            this.msg = d.error || 'Delete failed';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.curating = false;
                    }
                },

                async deleteSourceLink(l) {
                    if (!l || !l.id) return;
                    if (!confirm('Delete this link and all fetched media tied to it? This cannot be undone.')) return;
                    this.pushDebug('delete_source_link', { link_id: l.id });
                    const fd = new FormData();
                    fd.append('action', 'delete_source_link');
                    fd.append('id', l.id);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            this.msg = 'Link deleted.';
                            this.msgError = false;
                            await this.loadLinks();
                            await this.loadContent();
                        } else {
                            this.msg = d.error || 'Delete failed';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    }
                },

                async deleteFetchedGroup(g) {
                    if (!g || !g.items || !g.items.length) return;
                    const n = g.items.length;
                    const msg = g.linkId
                        ? 'Delete this link and all ' + n + ' fetched file(s)? This cannot be undone.'
                        : 'Delete all ' + n + ' fetched file(s) in this group? This cannot be undone.';
                    if (!confirm(msg)) return;
                    this.pushDebug('delete_fetched_group', { link_id: g.linkId || null, item_count: n });
                    if (g.linkId) {
                        await this.deleteSourceLink({ id: g.linkId });
                        if (!this.msgError) this.closeFetchedGalleryModal();
                        return;
                    }
                    this.curating = true;
                    try {
                        for (const c of g.items) {
                            const fd = new FormData();
                            fd.append('action', 'delete_content_item');
                            fd.append('id', c.id);
                            const d = await this.postToApp(fd);
                            if (!d.ok) {
                                this.msg = d.error || 'Delete failed';
                                this.msgError = true;
                                return;
                            }
                        }
                        this.msg = 'Deleted.';
                        this.msgError = false;
                        this.closeFetchedGalleryModal();
                        await this.loadContent();
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.curating = false;
                    }
                },

                async deleteGeneratedGroup(g) {
                    if (!g || !g.items || !g.items.length) return;
                    const n = g.items.length;
                    if (!confirm('Delete this generated stack and all ' + n + ' item(s)? This cannot be undone.')) return;
                    this.pushDebug('delete_generated_group', { group_key: g.key || null, item_count: n });
                    this.curating = true;
                    try {
                        for (const c of g.items) {
                            const fd = new FormData();
                            fd.append('action', 'delete_content_item');
                            fd.append('id', c.id);
                            const d = await this.postToApp(fd);
                            if (!d.ok) {
                                this.msg = d.error || 'Delete failed';
                                this.msgError = true;
                                return;
                            }
                        }
                        this.msg = 'Generated stack deleted.';
                        this.msgError = false;
                        this.closeGeneratedGalleryModal();
                        await this.loadContent();
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.curating = false;
                    }
                },

                async deletePipeline(p) {
                    if (!p || !p.id) return;
                    const label = this.pipelineLabel(p);
                    if (!confirm('Delete pipeline "' + label + '" and ALL content generated by it? This cannot be undone.')) return;
                    this.pushDebug('delete_pipeline', { pipeline_id: p.id });
                    const fd = new FormData();
                    fd.append('action', 'delete_pipeline');
                    fd.append('id', p.id);
                    try {
                        const d = await this.postToApp(fd);
                        if (d.ok) {
                            const cnt = d.deleted_content_count;
                            this.msg = 'Pipeline deleted' + (cnt != null && cnt > 0 ? ' (' + cnt + ' content item(s) removed).' : '.');
                            this.msgError = false;
                            this.runModal.open = false;
                            this.runModal.pipeline = null;
                            await this.loadPipelines();
                            await this.loadContent();
                        } else {
                            this.msg = d.error || 'Delete failed';
                            this.msgError = true;
                            if (d.partial) await this.loadContent();
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    }
                },

            }));
        });
    </script>
<?php endif; ?>
</body>
</html>
