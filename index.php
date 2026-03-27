<?php
/**
 * FormatForge — PocketBase + Alpine.js (single file).
 */

session_start();

if (is_file(__DIR__ . '/.env')) {
    foreach (file(__DIR__ . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#')) {
            continue;
        }
        if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
            putenv(trim($m[1]) . '=' . trim($m[2], " \t\"'"));
        }
    }
}

/** First readable Netscape cookies file under storage/cookies (see also GALLERY_DL_COOKIES / YT_DLP_COOKIES in .env). */
function ff_pick_storage_cookie_file(): string {
    $dir = __DIR__ . '/storage/cookies';
    foreach (['instagram_cookies.txt', 'cookies.txt'] as $name) {
        $p = $dir . '/' . $name;
        if (is_file($p) && is_readable($p) && (int) @filesize($p) > 32) {
            return $p;
        }
    }
    return '';
}

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

function ff_fetch_env_prefix(): string {
    $out = ff_fetch_path_env_prefix();
    $sites = @glob('/opt/ff-fetch/lib/python3.*/site-packages', GLOB_ONLYDIR) ?: [];
    if ($sites !== [] && is_dir($sites[0])) {
        $out = 'PYTHONPATH=' . escapeshellarg($sites[0]) . ' ' . $out;
    }
    return $out;
}

function ff_resolve_fetch_bin(string $envVar, string $fallbackName): string {
    $raw = getenv($envVar);
    $path = ($raw !== false && trim((string) $raw) !== '') ? trim((string) $raw) : $fallbackName;
    if (str_starts_with($path, '/')) {
        return (is_file($path) && is_executable($path)) ? $path : ff_fetch_executable($fallbackName, $fallbackName);
    }
    return ff_fetch_executable($path, $fallbackName);
}

/**
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

$_ffPbMeta = ff_resolve_pocketbase_url_meta();
$pbUrl = $_ffPbMeta['url'];

$siteUrl = getenv('APP_URL');
if (!$siteUrl) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $proto = 'https';
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) {
        $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
    }
    $siteUrl = $proto . '://' . preg_replace('/:\d+$/', '', $host);
}
$pbPublicUrl = getenv('POCKETBASE_PUBLIC_URL') ?: rtrim((string) $siteUrl, '/');

$CONFIG = [
    'pocketbase_url' => $pbUrl,
    'pocketbase_url_resolution' => $_ffPbMeta['source'],
    'pocketbase_public_url' => $pbPublicUrl,
    'pocketbase_admin_url' => rtrim($pbPublicUrl, '/') . '/_/',
    'site_url' => $siteUrl,
    'site_name' => getenv('SITE_NAME') ?: 'FormatForge',
    'app_version' => getenv('APP_VERSION') ?: 'v1.1.215',
    'gallery_dl_path' => ff_resolve_fetch_bin('GALLERY_DL_PATH', 'gallery-dl'),
    'yt_dlp_path' => ff_resolve_fetch_bin('YT_DLP_PATH', 'yt-dlp'),
    'users_collection' => 'users',
    'fb_app_id' => getenv('FB_APP_ID') ?: '',
    'fb_app_secret' => getenv('FB_APP_SECRET') ?: '',
    'instagram_redirect' => getenv('INSTAGRAM_REDIRECT_URI') ?: '',
    /** Instagram / Facebook Login: shown in UI and sent to Meta OAuth dialog. */
    'instagram_oauth_scope' => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management',
    /** PocketBase collection for Fetch uploads (fetched_files field; see pb_migrations). */
    'input_media_collection' => getenv('INPUT_MEDIA_COLLECTION') ?: 'input_media',
];

if (is_file(__DIR__ . '/config.php')) {
    $CONFIG = array_merge($CONFIG, require __DIR__ . '/config.php');
}

$cookieDefault = ff_pick_storage_cookie_file();
if (trim((string) ($CONFIG['gallery_dl_cookies'] ?? '')) === '') {
    $CONFIG['gallery_dl_cookies'] = trim((string) (getenv('GALLERY_DL_COOKIES') ?: '')) ?: $cookieDefault;
}
if (trim((string) ($CONFIG['yt_dlp_cookies'] ?? '')) === '') {
    $CONFIG['yt_dlp_cookies'] = trim((string) (getenv('YT_DLP_COOKIES') ?: '')) ?: (string) ($CONFIG['gallery_dl_cookies'] ?? '');
}

/** @var array<string, mixed> $CONFIG */
$GLOBALS['CONFIG'] = $CONFIG;

/**
 * @return array{code: int, body: array, raw: string, curl_errno: int}
 */
function pb_request(string $method, string $path, $data = null, ?string $token = null): array {
    $ch = curl_init($GLOBALS['CONFIG']['pocketbase_url'] . $path);
    $headers = ['Accept: application/json'];
    $methodUpper = strtoupper($method);
    $sendsBody = $data !== null && in_array($methodUpper, ['POST', 'PATCH', 'PUT'], true);
    if ($sendsBody) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($token) {
        $t = trim((string) $token);
        if (stripos($t, 'Bearer ') !== 0) {
            $t = 'Bearer ' . $t;
        }
        $headers[] = 'Authorization: ' . $t;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($data !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    return ['code' => $code, 'body' => $body, 'raw' => $res ?: '', 'curl_errno' => $errNo];
}

/**
 * PATCH multipart file onto an existing record (PocketBase multi file fields use "name+" to append).
 *
 * @return array{code: int, body: array, raw: string}
 */
function ff_pb_patch_record_file(string $token, string $col, string $recordId, string $formField, CURLFile $file): array {
    $base = rtrim((string) ($GLOBALS['CONFIG']['pocketbase_url'] ?? ''), '/');
    $url = $base . '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($recordId);
    $t = trim($token);
    if (stripos($t, 'Bearer ') !== 0) {
        $t = 'Bearer ' . $t;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => [$formField => $file],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: ' . $t],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];

    return ['code' => $code, 'body' => $body, 'raw' => $res ?: ''];
}

/**
 * One input_media row with a single fetched_files file (carousels / albums → one PocketBase record per slide).
 * JSON-create then PATCH file: single multipart create often drops file parts for multi-select file fields.
 *
 * @param array<string, mixed> $metaJson merged into metadata (e.g. carousel_index, fetch_batch_id)
 * @return array{code: int, body: array, raw: string}
 */
function ff_pb_input_media_create_fetch_one(string $token, string $sourceUrl, string $title, string $via, string $absPath, array $metaJson = []): array {
    $cfg = $GLOBALS['CONFIG'];
    $col = trim((string) ($cfg['input_media_collection'] ?? 'input_media'));
    if ($col === '') {
        $col = 'input_media';
    }
    if (!is_file($absPath) || !is_readable($absPath)) {
        return ['code' => 0, 'body' => ['message' => 'Missing or unreadable file for upload.'], 'raw' => ''];
    }
    if (!class_exists(CURLFile::class)) {
        return ['code' => 0, 'body' => ['message' => 'PHP CURLFile unavailable (install/enable php-curl).'], 'raw' => ''];
    }
    if (strlen($title) > 200) {
        $title = substr($title, 0, 200);
    }
    $meta = array_merge(['via' => $via], $metaJson, ['fetched_at' => gmdate('c')]);
    $mimeType = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $mt = @mime_content_type($absPath);
        if (is_string($mt) && $mt !== '') {
            $mimeType = $mt;
        }
    }
    $fileField = new CURLFile($absPath, $mimeType, basename($absPath));

    $payload = [
        'role' => 'fetched',
        'status' => 'fetched',
        'url' => $sourceUrl,
        'input_url' => $sourceUrl,
        'title' => $title,
        'is_active' => true,
        'metadata' => $meta,
    ];
    $create = pb_request('POST', '/api/collections/' . rawurlencode($col) . '/records', $payload, $token);
    if ($create['code'] < 200 || $create['code'] >= 300) {
        return $create;
    }
    $id = trim((string) ($create['body']['id'] ?? ''));
    if ($id === '') {
        return ['code' => 0, 'body' => ['message' => 'PocketBase create returned no id.'], 'raw' => $create['raw'] ?? ''];
    }

    $patch = ff_pb_patch_record_file($token, $col, $id, 'fetched_files+', $fileField);
    if ($patch['code'] < 200 || $patch['code'] >= 300) {
        $patch2 = ff_pb_patch_record_file($token, $col, $id, 'fetched_files', $fileField);
        if ($patch2['code'] < 200 || $patch2['code'] >= 300) {
            ff_pb_delete_input_media_record($token, $id);
            $msg = (string) (($patch['body']['message'] ?? '') ?: ($patch2['body']['message'] ?? '') ?: 'File upload failed.');
            return ['code' => $patch2['code'], 'body' => ['message' => $msg, 'data' => $patch2['body']['data'] ?? $patch['body']['data'] ?? null], 'raw' => $patch2['raw'] ?: $patch['raw']];
        }
        $patch = $patch2;
    }

    if (empty($patch['body']['id'])) {
        $patch['body']['id'] = $id;
    }

    return $patch;
}

function ff_pb_delete_input_media_record(string $token, string $recordId): void {
    $recordId = trim($recordId);
    if ($recordId === '') {
        return;
    }
    $col = trim((string) ($GLOBALS['CONFIG']['input_media_collection'] ?? 'input_media'));
    if ($col === '') {
        $col = 'input_media';
    }
    pb_request('DELETE', '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($recordId), null, $token);
}

function ff_pb_proxy_file_download(string $collection, string $recordId, string $filename, string $token): void {
    $fn = basename(str_replace(["\0"], '', $filename));
    if ($fn === '' || str_contains($fn, '..')) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad filename';
        return;
    }
    $base = rtrim((string) ($GLOBALS['CONFIG']['pocketbase_url'] ?? ''), '/');
    $url = $base . '/api/files/' . rawurlencode($collection) . '/' . rawurlencode($recordId) . '/' . rawurlencode($fn);
    $t = trim($token);
    if (stripos($t, 'Bearer ') !== 0) {
        $t = 'Bearer ' . $t;
    }
    $httpStatus = 0;
    $headersOut = false;
    $errBuf = '';
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, $headerLine) use (&$httpStatus): int {
        $len = strlen($headerLine);
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $headerLine, $m)) {
            $httpStatus = (int) $m[1];
        }
        return $len;
    });
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($ch, $chunk) use (&$httpStatus, &$headersOut, &$errBuf, $fn): int {
        if ($httpStatus !== 200) {
            $errBuf .= $chunk;
            return strlen($chunk);
        }
        if (!$headersOut) {
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . str_replace(["\r", "\n", '"'], '', $fn) . '"');
            $headersOut = true;
        }
        echo $chunk;
        return strlen($chunk);
    });
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_HTTPHEADER => ['Authorization: ' . $t],
        CURLOPT_RETURNTRANSFER => false,
    ]);
    curl_exec($ch);
    curl_close($ch);
    if ($httpStatus !== 200 && !$headersOut) {
        http_response_code($httpStatus >= 400 && $httpStatus < 600 ? $httpStatus : 502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'File not available';
    }
}

function normalize_instagram_username(?string $username): ?string {
    if ($username === null) {
        return null;
    }
    $u = trim(ltrim((string) $username, '@'));
    if ($u === '') {
        return null;
    }
    $lower = strtolower($u);
    if (in_array($lower, ['undefined', 'null', 'account', 'active', 'inactive', 'n/a', 'na'], true)) {
        return null;
    }
    return $u;
}

function fetch_instagram_username(string $igUserId, array $tokens): ?string {
    $igUserId = trim($igUserId);
    if ($igUserId === '') {
        return null;
    }
    $seenTokens = [];
    foreach ($tokens as $token) {
        $tok = trim((string) $token);
        if ($tok === '' || isset($seenTokens[$tok])) {
            continue;
        }
        $seenTokens[$tok] = true;
        foreach (['https://graph.instagram.com', 'https://graph.facebook.com'] as $host) {
            $ch = curl_init("{$host}/v18.0/{$igUserId}?fields=username&access_token=" . urlencode($tok));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $res = curl_exec($ch);
            curl_close($ch);
            $body = json_decode($res ?: '{}', true) ?? [];
            if (!empty($body['error'])) {
                continue;
            }
            $username = normalize_instagram_username($body['username'] ?? null);
            if ($username) {
                return $username;
            }
        }
    }
    return null;
}

function pb_find_instagram_account_by_user_id(string $igUserId, ?string $authHeader): ?array {
    $igUserId = trim($igUserId);
    if ($igUserId === '' || !$authHeader) {
        return null;
    }
    $safeId = str_replace(['\\', '"'], ['\\\\', '\"'], $igUserId);
    $query = http_build_query(['filter' => 'instagram_user_id="' . $safeId . '"', 'perPage' => 1]);
    $resp = pb_request('GET', '/api/collections/social_accounts/records?' . $query, null, $authHeader);
    if ($resp['code'] !== 200) {
        return null;
    }
    return $resp['body']['items'][0] ?? null;
}

function ff_redirect_url(string $pathWithQuery): void {
    $cfg = $GLOBALS['CONFIG'];
    $base = rtrim((string) ($cfg['site_url'] ?? ''), '/');
    header('Location: ' . $base . $pathWithQuery);
    exit;
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
    return "…(truncated)\n" . substr($raw, -$maxBytes);
}

function ff_fetch_rmdir_recursive(string $dir): void {
    if (!is_dir($dir)) {
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $p = $f->getPathname();
        $f->isDir() ? @rmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function ff_fetch_collect_files(string $dir): array {
    if (!is_dir($dir)) {
        return [];
    }
    $out = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            $out[] = $f->getPathname();
        }
    }
    return $out;
}

/** If the pasted line has no scheme, assume https (browser type=url often blocks these). */
function ff_fetch_normalize_url_input(string $url): string {
    $url = trim($url);
    if ($url === '') {
        return $url;
    }
    if (!preg_match('#^https?://#i', $url)) {
        return 'https://' . ltrim($url, '/');
    }
    return $url;
}

function ff_fetch_validate_http_url(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
        return 'Enter a URL.';
    }
    if (strlen($url) > 4096) {
        return 'URL is too long.';
    }
    if (!preg_match('#^https?://#i', $url)) {
        return 'URL must start with http:// or https://';
    }
    if (parse_url($url, PHP_URL_HOST) === null || parse_url($url, PHP_URL_HOST) === '') {
        return 'Invalid URL.';
    }
    return null;
}

/**
 * @return array{ok: bool, files: array<int, string>, error: string, exit_code: ?int, output_tail: string, cookies_file_used: bool}
 */
function ff_fetch_run_tool(string $url, string $tool, string $destDir): array {
    $cfg = $GLOBALS['CONFIG'];
    $diag = ['ok' => false, 'files' => [], 'error' => '', 'exit_code' => null, 'output_tail' => '', 'cookies_file_used' => false];
    $timeout = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 300 ' : '';
    $prefix = ff_fetch_env_prefix();
    $logFile = sys_get_temp_dir() . '/ff_fetch_' . bin2hex(random_bytes(8)) . '.log';

    if ($tool === 'gallery-dl') {
        $cookieOpt = ff_shell_cookie_opt((string) ($cfg['gallery_dl_cookies'] ?? ''));
        if ($cookieOpt !== '') {
            $diag['cookies_file_used'] = true;
        }
        $bin = ff_fetch_executable((string) ($cfg['gallery_dl_path'] ?? ''), 'gallery-dl');
        $cmd = $prefix . $timeout . escapeshellarg($bin) . $cookieOpt . ' -d ' . escapeshellarg($destDir) . ' ' . escapeshellarg($url)
            . ' > ' . escapeshellarg($logFile) . ' 2>&1';
        exec($cmd, $void, $code);
        $diag['exit_code'] = $code;
        $diag['output_tail'] = ff_tail_log_file($logFile, 8000);
        @unlink($logFile);
        $files = ff_fetch_collect_files($destDir);
        if ($code !== 0) {
            $diag['error'] = 'gallery-dl exited with code ' . $code . ($diag['output_tail'] !== '' ? (". Log:\n" . $diag['output_tail']) : '');
            return $diag;
        }
        if ($files === []) {
            $diag['error'] = 'gallery-dl reported success but wrote no files.';
            return $diag;
        }
        $diag['ok'] = true;
        $diag['files'] = $files;
        return $diag;
    }

    if ($tool === 'yt-dlp') {
        $cookieOpt = ff_shell_cookie_opt((string) ($cfg['yt_dlp_cookies'] ?? ''));
        if ($cookieOpt === '') {
            $cookieOpt = ff_shell_cookie_opt((string) ($cfg['gallery_dl_cookies'] ?? ''));
        }
        if ($cookieOpt !== '') {
            $diag['cookies_file_used'] = true;
        }
        $bin = ff_fetch_executable((string) ($cfg['yt_dlp_path'] ?? ''), 'yt-dlp');
        $outTpl = $destDir . DIRECTORY_SEPARATOR . '%(id)s.%(ext)s';
        $cmd = $prefix . $timeout . escapeshellarg($bin) . $cookieOpt . ' -o ' . escapeshellarg($outTpl) . ' ' . escapeshellarg($url)
            . ' > ' . escapeshellarg($logFile) . ' 2>&1';
        exec($cmd, $void, $code);
        $diag['exit_code'] = $code;
        $diag['output_tail'] = ff_tail_log_file($logFile, 8000);
        @unlink($logFile);
        $files = ff_fetch_collect_files($destDir);
        if ($code !== 0) {
            $diag['error'] = 'yt-dlp exited with code ' . $code . ($diag['output_tail'] !== '' ? (". Log:\n" . $diag['output_tail']) : '');
            return $diag;
        }
        if ($files === []) {
            $diag['error'] = 'yt-dlp reported success but wrote no files.';
            return $diag;
        }
        $diag['ok'] = true;
        $diag['files'] = $files;
        return $diag;
    }

    $diag['error'] = 'Unknown tool.';
    return $diag;
}

/**
 * Download to system temp, then upload to PocketBase: one input_media row per file (carousel = N rows).
 *
 * @return array{ok: bool, record_id: string, record_rows: array<int, array{id: string, file: string, label: string}>, pb_files: array<int, string>, n: int, error: string, via: string}
 */
function ff_fetch_save_media(string $url, string $toolChoice, ?string $pbToken): array {
    $empty = ['ok' => false, 'record_id' => '', 'record_rows' => [], 'pb_files' => [], 'n' => 0, 'error' => '', 'via' => ''];
    $url = ff_fetch_normalize_url_input($url);
    $err = ff_fetch_validate_http_url($url);
    if ($err !== null) {
        $empty['error'] = $err;
        return $empty;
    }
    if (!$pbToken || trim((string) $pbToken) === '') {
        $empty['error'] = 'Not signed in.';
        return $empty;
    }
    $destDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ffetch_' . bin2hex(random_bytes(8));
    if (!@mkdir($destDir, 0700, true)) {
        $empty['error'] = 'Cannot create temp directory for download.';
        return $empty;
    }

    $toolChoice = strtolower(trim($toolChoice));
    if (!in_array($toolChoice, ['auto', 'gallery-dl', 'yt-dlp'], true)) {
        $toolChoice = 'auto';
    }

    $run = static function (string $tool, string $urlIn, string $dir) {
        return ff_fetch_run_tool($urlIn, $tool, $dir);
    };

    $r = null;
    $via = '';
    if ($toolChoice === 'gallery-dl') {
        $r = $run('gallery-dl', $url, $destDir);
        $via = 'gallery-dl';
    } elseif ($toolChoice === 'yt-dlp') {
        $r = $run('yt-dlp', $url, $destDir);
        $via = 'yt-dlp';
    } else {
        $r1 = $run('gallery-dl', $url, $destDir);
        if ($r1['ok'] && $r1['files'] !== []) {
            $r = $r1;
            $via = 'gallery-dl';
        } else {
            ff_fetch_rmdir_recursive($destDir);
            @mkdir($destDir, 0700, true);
            $r2 = $run('yt-dlp', $url, $destDir);
            $r = $r2;
            $via = 'yt-dlp';
        }
    }

    if (!$r || empty($r['ok']) || ($r['files'] ?? []) === []) {
        $e = $r['error'] ?? 'Download failed.';
        ff_fetch_rmdir_recursive($destDir);
        $empty['error'] = $e;
        return $empty;
    }

    $paths = $r['files'];
    sort($paths);
    $max = 20;
    if (count($paths) > $max) {
        $paths = array_slice($paths, 0, $max);
    }

    $host = parse_url($url, PHP_URL_HOST);
    $baseTitle = $host ? ('Fetch: ' . $host) : 'Fetched media';
    $total = count($paths);
    $batchId = bin2hex(random_bytes(8));
    $createdIds = [];
    $recordRows = [];
    $pbFiles = [];

    foreach ($paths as $i => $pth) {
        $idx = $i + 1;
        $fn = basename($pth);
        $title = $baseTitle;
        $label = $fn;
        if ($total > 1) {
            $title = $baseTitle . ' (' . $idx . '/' . $total . ')';
            $label = '(' . $idx . '/' . $total . ') ' . $fn;
            if (strlen($title) > 200) {
                $title = substr($title, 0, 200);
            }
        }
        $meta = [
            'fetch_batch_id' => $batchId,
            'carousel_index' => $idx,
            'carousel_total' => $total,
            'is_carousel' => $total > 1,
            'source_filename' => $fn,
        ];
        $up = ff_pb_input_media_create_fetch_one((string) $pbToken, $url, $title, $via, $pth, $meta);
        if ($up['code'] < 200 || $up['code'] >= 300) {
            foreach (array_reverse($createdIds) as $badId) {
                ff_pb_delete_input_media_record((string) $pbToken, $badId);
            }
            ff_fetch_rmdir_recursive($destDir);
            $msg = (string) (($up['body']['message'] ?? '') ?: ($up['body']['data'] ?? '') ?: $up['raw'] ?: 'PocketBase upload failed.');
            if (is_array($up['body']['data'] ?? null)) {
                $msg = json_encode($up['body']['data'], JSON_UNESCAPED_UNICODE) ?: $msg;
            }
            $empty['error'] = 'PocketBase (item ' . $idx . '/' . $total . '): ' . $msg;
            return $empty;
        }
        $rec = $up['body'];
        $id = (string) ($rec['id'] ?? '');
        $names = $rec['fetched_files'] ?? [];
        if (!is_array($names)) {
            $names = $names !== null && $names !== '' ? [(string) $names] : [];
        }
        $names = array_values(array_filter(array_map('strval', $names)));
        $storedName = $names[0] ?? $fn;
        if ($id !== '') {
            $createdIds[] = $id;
        }
        $recordRows[] = ['id' => $id, 'file' => $storedName, 'label' => $label];
        $pbFiles[] = $storedName;
    }

    ff_fetch_rmdir_recursive($destDir);

    return [
        'ok' => true,
        'record_id' => (string) ($recordRows[0]['id'] ?? ''),
        'record_rows' => $recordRows,
        'pb_files' => $pbFiles,
        'n' => count($recordRows),
        'error' => '',
        'via' => $via,
    ];
}

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "FormatForge: no CLI commands — use the web UI.\n");
    exit(1);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    if ($email && $password) {
        $usersCol = $GLOBALS['CONFIG']['users_collection'] ?? 'users';
        $auth = pb_request('POST', '/api/collections/' . $usersCol . '/auth-with-password', [
            'identity' => $email,
            'password' => $password,
        ], null);
        if ($auth['code'] === 200 && !empty($auth['body']['token'])) {
            $_SESSION['pb_token'] = $auth['body']['token'];
            $_SESSION['pb_user'] = $auth['body']['record'] ?? [];
            ff_redirect_url('/');
        }
    }
    ff_redirect_url('/?login_error=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    unset($_SESSION['pb_user'], $_SESSION['pb_token']);
    ff_redirect_url('/');
}

$user = $_SESSION['pb_user'] ?? null;
$token = $_SESSION['pb_token'] ?? null;
$authHeader = $token ?: null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_media' && $user && $authHeader) {
    $url = (string) ($_POST['url'] ?? '');
    $tool = (string) ($_POST['tool'] ?? 'auto');
    $res = ff_fetch_save_media($url, $tool, $authHeader);
    if (!empty($res['ok'])) {
        $_SESSION['fetch_flash'] = [
            'ok' => true,
            'record_id' => (string) ($res['record_id'] ?? ''),
            'record_rows' => is_array($res['record_rows'] ?? null) ? $res['record_rows'] : [],
            'pb_files' => is_array($res['pb_files'] ?? null) ? $res['pb_files'] : [],
            'n' => (int) ($res['n'] ?? 0),
            'via' => (string) ($res['via'] ?? ''),
        ];
        ff_redirect_url('/?fetch_ok=1');
    }
    $err = (string) ($res['error'] ?? 'Failed');
    if (strlen($err) > 6000) {
        $err = substr($err, 0, 6000) . "\n…";
    }
    $_SESSION['fetch_flash'] = ['ok' => false, 'error' => $err];
    ff_redirect_url('/?fetch_err=1');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ff_pb_file']) && $user && $authHeader) {
    $col = (string) ($_GET['c'] ?? '');
    $cfgCol = trim((string) ($GLOBALS['CONFIG']['input_media_collection'] ?? 'input_media'));
    if ($cfgCol === '') {
        $cfgCol = 'input_media';
    }
    if ($col !== $cfgCol) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad collection';
        exit;
    }
    $rid = (string) ($_GET['id'] ?? '');
    if ($rid === '' || !preg_match('/^[a-zA-Z0-9_-]{1,128}$/', $rid)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad record id';
        exit;
    }
    $fn = rawurldecode((string) ($_GET['n'] ?? ''));
    ff_pb_proxy_file_download($col, $rid, $fn, (string) $authHeader);
    exit;
}

$reqUri = $_SERVER['REQUEST_URI'] ?? '';
$isInstagramCallback = isset($_GET['instagram_callback']) || str_contains($reqUri, '/instagram/callback');

$hubMode = $_GET['hub_mode'] ?? $_GET['hub.mode'] ?? '';
$hubVerify = $_GET['hub_verify_token'] ?? $_GET['hub.verify_token'] ?? '';
$hubChallenge = $_GET['hub_challenge'] ?? $_GET['hub.challenge'] ?? '';
if ($hubMode === 'subscribe' && $hubVerify !== '' && $hubChallenge !== '') {
    $verifyToken = getenv('META_WEBHOOK_VERIFY_TOKEN') ?: '';
    if ($verifyToken !== '' && $hubVerify === $verifyToken) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $hubChallenge;
        exit;
    }
}

if (isset($_GET['instagram_oauth']) && $user) {
    $cfg = $GLOBALS['CONFIG'];
    $appId = trim((string) ($cfg['fb_app_id'] ?? ''));
    if ($appId === '') {
        ff_redirect_url('/?ig_error=1');
    }
    $redirect = trim((string) ($cfg['instagram_redirect'] ?? ''));
    if ($redirect === '') {
        $redirect = rtrim((string) ($cfg['site_url'] ?? ''), '/') . '/instagram/callback';
    }
    try {
        $stateNonce = bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        $stateNonce = sha1(uniqid('', true));
    }
    $oauthState = ['user_id' => $user['id'] ?? '', 'nonce' => $stateNonce];
    $_SESSION['instagram_oauth_state'] = $oauthState;
    $scope = (string) ($cfg['instagram_oauth_scope'] ?? 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management');
    $params = [
        'client_id' => $appId,
        'redirect_uri' => $redirect,
        'scope' => $scope,
        'response_type' => 'code',
        'state' => base64_encode(json_encode($oauthState)),
    ];
    $query = http_build_query($params);
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $isMobile = (bool) preg_match('/Mobile|Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i', $ua);
    $host = $isMobile ? 'm.facebook.com' : 'www.facebook.com';
    header('Location: https://' . $host . '/v18.0/dialog/oauth?' . $query);
    exit;
}

if ($isInstagramCallback && $user && !empty($_GET['error'])) {
    ff_redirect_url('/?ig_error=1');
}

if ($isInstagramCallback && isset($_GET['code']) && $user) {
    $cfg = $GLOBALS['CONFIG'];
    $redirect = trim((string) ($cfg['instagram_redirect'] ?? ''));
    if ($redirect === '') {
        $redirect = rtrim((string) ($cfg['site_url'] ?? ''), '/') . '/instagram/callback';
    }
    $stateRaw = (string) ($_GET['state'] ?? '');
    $statePayload = json_decode(base64_decode($stateRaw, true) ?: '{}', true) ?? [];
    $expectedState = $_SESSION['instagram_oauth_state'] ?? [];
    unset($_SESSION['instagram_oauth_state']);
    if (
        !is_array($statePayload) || !is_array($expectedState)
        || empty($statePayload['nonce']) || empty($expectedState['nonce'])
        || !hash_equals((string) $expectedState['nonce'], (string) $statePayload['nonce'])
        || (($statePayload['user_id'] ?? '') !== ($user['id'] ?? ''))
    ) {
        ff_redirect_url('/?ig_error=1');
    }

    $ch = curl_init('https://graph.facebook.com/v18.0/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $cfg['fb_app_id'],
            'client_secret' => $cfg['fb_app_secret'],
            'redirect_uri' => $redirect,
            'code' => preg_replace('/#_.*$/', '', (string) ($_GET['code'] ?? '')),
        ]),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res ?: '{}', true) ?? [];
    $fbToken = $data['access_token'] ?? null;
    if (!$fbToken) {
        $err = $data['error']['message'] ?? json_encode($data);
        ff_redirect_url('/?ig_error=1');
    }

    $ch2 = curl_init('https://graph.facebook.com/v18.0/me/accounts?fields=id,name,access_token,tasks,instagram_business_account{id,username}&access_token=' . urlencode((string) $fbToken));
    curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
    $r2 = curl_exec($ch2);
    curl_close($ch2);
    $accounts = json_decode($r2 ?: '{}', true) ?? [];
    if (!empty($accounts['error']['message'])) {
        ff_redirect_url('/?ig_error=1');
    }
    $pages = $accounts['data'] ?? [];
    $saved = 0;
    $seenIgUsers = [];
    foreach ($pages as $page) {
        $pageId = $page['id'] ?? '';
        $pageName = $page['name'] ?? '';
        $pageToken = trim((string) ($page['access_token'] ?? ''));
        if ($pageToken === '') {
            $pageToken = (string) $fbToken;
        }
        if (!$pageId) {
            continue;
        }
        $igBiz = $page['instagram_business_account'] ?? null;
        $igSource = 'expanded';
        if (!$igBiz || empty($igBiz['id'])) {
            $igSource = 'page_lookup';
            foreach ([$fbToken, $pageToken] as $tryToken) {
                $ch3 = curl_init("https://graph.facebook.com/v18.0/{$pageId}?fields=instagram_business_account{id,username}&access_token=" . urlencode((string) $tryToken));
                curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
                $r3 = curl_exec($ch3);
                curl_close($ch3);
                $pageData = json_decode($r3 ?: '{}', true) ?? [];
                $igBiz = $pageData['instagram_business_account'] ?? null;
                if ($igBiz && !empty($igBiz['id'])) {
                    break;
                }
            }
        }
        if (!$igBiz || empty($igBiz['id'])) {
            continue;
        }
        $igUserId = trim((string) ($igBiz['id'] ?? ''));
        if ($igUserId === '' || isset($seenIgUsers[$igUserId])) {
            continue;
        }
        $seenIgUsers[$igUserId] = true;

        $username = normalize_instagram_username($igBiz['username'] ?? null);
        if (!$username) {
            $username = fetch_instagram_username($igUserId, [$fbToken, $pageToken]);
        }
        if (!$username) {
            $username = 'ig_' . $igUserId;
        }

        $payload = [
            'platform' => 'instagram',
            'instagram_user_id' => $igUserId,
            'username' => $username,
            'access_token' => $pageToken,
            'is_active' => true,
        ];
        $existing = pb_find_instagram_account_by_user_id($igUserId, $authHeader);
        $path = ($existing && !empty($existing['id']))
            ? '/api/collections/social_accounts/records/' . rawurlencode((string) $existing['id'])
            : '/api/collections/social_accounts/records';
        $method = ($existing && !empty($existing['id'])) ? 'PATCH' : 'POST';
        $rec = pb_request($method, $path, $payload, $authHeader);
        if (($rec['code'] ?? 0) >= 200 && ($rec['code'] ?? 0) < 300) {
            $saved++;
        }
    }
    if ($saved > 0) {
        ff_redirect_url('/?ig_ok=1');
    }
    if (!empty($pages)) {
        ff_redirect_url('/?ig_error=1');
    }
    ff_redirect_url('/?ig_error=1');
}

$siteName = htmlspecialchars((string) ($CONFIG['site_name'] ?? 'FormatForge'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$appVersion = htmlspecialchars((string) ($CONFIG['app_version'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$pbAdmin = htmlspecialchars((string) ($CONFIG['pocketbase_admin_url'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$fbConfigured = trim((string) ($CONFIG['fb_app_id'] ?? '')) !== '' && trim((string) ($CONFIG['fb_app_secret'] ?? '')) !== '';

$igAccountsList = [];
if ($authHeader) {
    $qAccounts = http_build_query([
        'filter' => 'platform="instagram"',
        'sort' => '-@rowid',
        'perPage' => 30,
    ]);
    $lr = pb_request('GET', '/api/collections/social_accounts/records?' . $qAccounts, null, $authHeader);
    if (($lr['code'] ?? 0) === 200 && isset($lr['body']['items']) && is_array($lr['body']['items'])) {
        foreach ($lr['body']['items'] as $it) {
            if (!is_array($it)) {
                continue;
            }
            $igAccountsList[] = [
                'id' => $it['id'] ?? '',
                'username' => $it['username'] ?? '',
                'instagram_user_id' => $it['instagram_user_id'] ?? '',
                'is_active' => !empty($it['is_active']),
            ];
        }
    }
}

$userEmail = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$flashIgOk = isset($_GET['ig_ok']);
$flashIgErr = isset($_GET['ig_error']);
$flashLoginErr = isset($_GET['login_error']);
$flashFetchOk = isset($_GET['fetch_ok']);
$flashFetchErr = isset($_GET['fetch_err']);
$fetchFlash = $_SESSION['fetch_flash'] ?? null;
if (is_array($fetchFlash)) {
    unset($_SESSION['fetch_flash']);
} else {
    $fetchFlash = null;
}
$fetchFlashRecord = is_array($fetchFlash) && !empty($fetchFlash['ok']) && !empty($fetchFlash['record_id'])
    ? (string) $fetchFlash['record_id'] : '';
$fetchRecordRows = ($flashFetchOk && is_array($fetchFlash) && !empty($fetchFlash['record_rows']) && is_array($fetchFlash['record_rows']))
    ? $fetchFlash['record_rows'] : [];
$fetchPbFiles = ($flashFetchOk && is_array($fetchFlash) && !empty($fetchFlash['pb_files']) && is_array($fetchFlash['pb_files']))
    ? $fetchFlash['pb_files'] : [];

if (isset($_GET['privacy'])) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Privacy</title></head><body><h1>Privacy</h1><p>Add your policy text here.</p><p><a href="/">Home</a></p></body></html>';
    exit;
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $siteName; ?></title>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.8/dist/cdn.min.js"></script>
    <style>
        :root { font-family: system-ui, sans-serif; background: #0f1419; color: #e6edf3; }
        body { margin: 0; min-height: 100vh; padding: 1.5rem; box-sizing: border-box; }
        .wrap { max-width: 28rem; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem; }
        .card { width: 100%; background: #161b22; border: 1px solid #30363d; border-radius: 12px; padding: 1.5rem; box-shadow: 0 8px 24px rgba(0,0,0,.35); }
        h1 { margin: 0 0 .5rem; font-size: 1.25rem; font-weight: 600; }
        h2 { margin: 0 0 .75rem; font-size: 1rem; font-weight: 600; color: #c9d1d9; }
        p { margin: .5rem 0; color: #8b949e; font-size: .875rem; line-height: 1.5; }
        .row { display: flex; align-items: center; justify-content: space-between; gap: .75rem; flex-wrap: wrap; margin-top: 1rem; }
        .pill { font-size: .75rem; padding: .2rem .5rem; border-radius: 999px; background: #21262d; color: #8b949e; display: inline-block; }
        .ok { color: #3fb950; }
        .bad { color: #f85149; }
        a { color: #58a6ff; text-decoration: none; }
        a:hover { text-decoration: underline; }
        button, .btn { font: inherit; cursor: pointer; border: 1px solid #30363d; background: #21262d; color: #e6edf3; padding: .4rem .75rem; border-radius: 8px; display: inline-block; text-align: center; }
        button:hover, .btn:hover { background: #30363d; }
        .btn-primary { background: #238636; border-color: #238636; }
        .btn-primary:hover { background: #2ea043; }
        input { width: 100%; box-sizing: border-box; padding: .5rem .65rem; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: #e6edf3; margin: .35rem 0 .75rem; font: inherit; }
        label { font-size: .8rem; color: #8b949e; }
        ul.ig { list-style: none; padding: 0; margin: .5rem 0 0; }
        ul.ig li { padding: .35rem 0; border-bottom: 1px solid #21262d; font-size: .875rem; }
        ul.ig li:last-child { border-bottom: none; }
        .flash { font-size: .85rem; margin: .5rem 0; padding: .5rem .65rem; border-radius: 8px; }
        .flash.ok { background: #23863622; color: #3fb950; border: 1px solid #23863655; }
        .flash.bad { background: #f8514922; color: #f85149; border: 1px solid #f8514955; }
        .ig-head { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin: 0.75rem 0 0; }
        .ig-head span { font-size: 0.875rem; font-weight: 600; color: #c9d1d9; }
        .btn:disabled { opacity: 0.45; cursor: not-allowed; }
        .account-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; flex-wrap: wrap; margin-top: 0.5rem; }
        select.fetch-tool { width: 100%; box-sizing: border-box; padding: .5rem .65rem; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: #e6edf3; margin: .35rem 0 .75rem; font: inherit; }
        ul.fetch-files { list-style: none; padding: 0; margin: .5rem 0 0; max-height: 14rem; overflow: auto; font-size: .8125rem; }
        ul.fetch-files li { padding: .25rem 0; border-bottom: 1px solid #21262d; word-break: break-all; }
        ul.fetch-files li:last-child { border-bottom: none; }
        pre.fetch-err { margin: .5rem 0 0; padding: .65rem; background: #0d1117; border: 1px solid #f8514955; border-radius: 8px; color: #f85149; font-size: .75rem; white-space: pre-wrap; word-break: break-word; max-height: 16rem; overflow: auto; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="card" x-data="ffHealth()" x-init="checkHealth()">
            <h1><?php echo $siteName; ?></h1>
            <p class="pill"><?php echo $appVersion; ?></p>
            <p>
                <span x-show="loading">…</span>
                <span x-show="!loading && healthOk" class="ok" x-text="healthMsg"></span>
                <span x-show="!loading && !healthOk" class="bad" x-text="healthMsg"></span>
            </p>
            <div class="row">
                <a href="<?php echo $pbAdmin; ?>" target="_blank" rel="noopener">Admin</a>
                <button type="button" @click="checkHealth()">Retry</button>
            </div>
        </div>

        <div class="card">
            <h2>Account</h2>
            <?php if ($flashLoginErr): ?>
                <p class="flash bad">Failed</p>
            <?php endif; ?>
            <?php if ($user): ?>
                <div class="account-bar">
                    <p style="color:#c9d1d9;margin:0;flex:1;min-width:0;"><strong><?php echo $userEmail !== '' ? $userEmail : 'user'; ?></strong></p>
                    <form method="post" action="/" style="margin:0;">
                        <input type="hidden" name="action" value="logout">
                        <button type="submit" class="btn">Log out</button>
                    </form>
                </div>
            <?php else: ?>
                <form method="post" action="/">
                    <input type="hidden" name="action" value="login">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" autocomplete="username" required>
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" autocomplete="current-password" required>
                    <button type="submit" class="btn btn-primary" style="width:100%;margin-top:.25rem;">Log in</button>
                </form>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Instagram</h2>
            <?php if ($flashIgOk): ?>
                <p class="flash ok">Connected</p>
            <?php endif; ?>
            <?php if ($flashIgErr): ?>
                <p class="flash bad">Failed</p>
            <?php endif; ?>
            <?php if (!$fbConfigured): ?>
                <p class="bad" style="font-size:.85rem;">Not configured</p>
            <?php endif; ?>
            <?php if (!$user): ?>
                <p>Sign in</p>
            <?php else: ?>
                <div>
                    <div class="ig-head">
                        <span>Accounts</span>
                        <?php if ($fbConfigured): ?>
                            <a class="btn btn-primary" href="/?instagram_oauth=1"><?php echo count($igAccountsList) > 0 ? 'Add' : 'Connect'; ?></a>
                        <?php else: ?>
                            <button type="button" class="btn" disabled>Connect</button>
                        <?php endif; ?>
                    </div>
                    <?php if ($igAccountsList !== []): ?>
                        <ul class="ig">
                            <?php foreach ($igAccountsList as $a): ?>
                                <li>
                                    @<?php echo htmlspecialchars((string) ($a['username'] ?: $a['instagram_user_id']), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                    <span class="pill" style="margin-left:.5rem;"><?php echo !empty($a['is_active']) ? 'on' : 'off'; ?></span>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="card">
            <h2>Fetch</h2>
            <p style="font-size:.8125rem;">Runs gallery-dl / yt-dlp on this server, uploads into PocketBase <code style="color:#8b949e;">input_media</code>. Each file is its own record; carousels / multi-image posts become one row per slide.</p>
            <?php if ($flashFetchOk): ?>
                <p class="flash ok"><?php
                    if (is_array($fetchFlash) && !empty($fetchFlash['ok'])) {
                        echo 'Saved ' . (int) ($fetchFlash['n'] ?? 0) . ' file(s)';
                        if (!empty($fetchFlash['via'])) {
                            echo ' via ' . htmlspecialchars((string) $fetchFlash['via'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        }
                        echo '.';
                    } else {
                        echo 'Saved.';
                    }
                ?></p>
                <?php if ($fetchRecordRows !== []): ?>
                    <p style="font-size:.8125rem;margin:.25rem 0 0;">Download (from PocketBase, one record per row):</p>
                    <ul class="fetch-files">
                        <?php foreach ($fetchRecordRows as $row): ?>
                            <?php
                            $rid = (string) ($row['id'] ?? '');
                            $pfn = (string) ($row['file'] ?? '');
                            $lbl = (string) ($row['label'] ?? $pfn);
                            ?>
                            <?php if ($rid !== '' && $pfn !== ''): ?>
                                <li>
                                    <a href="/?ff_pb_file=1&amp;c=<?php echo rawurlencode((string) ($CONFIG['input_media_collection'] ?? 'input_media')); ?>&amp;id=<?php echo rawurlencode($rid); ?>&amp;n=<?php echo rawurlencode($pfn); ?>"><?php echo htmlspecialchars($lbl, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></a>
                                </li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php elseif ($fetchPbFiles !== [] && $fetchFlashRecord !== ''): ?>
                    <p style="font-size:.8125rem;margin:.25rem 0 0;">Download (from PocketBase):</p>
                    <ul class="fetch-files">
                        <?php foreach ($fetchPbFiles as $pfn): ?>
                            <li>
                                <a href="/?ff_pb_file=1&amp;c=<?php echo rawurlencode((string) ($CONFIG['input_media_collection'] ?? 'input_media')); ?>&amp;id=<?php echo rawurlencode($fetchFlashRecord); ?>&amp;n=<?php echo rawurlencode((string) $pfn); ?>"><?php echo htmlspecialchars((string) $pfn, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($flashFetchErr): ?>
                <p class="flash bad">Failed</p>
                <?php if (is_array($fetchFlash) && empty($fetchFlash['ok']) && !empty($fetchFlash['error'])): ?>
                    <pre class="fetch-err"><?php echo htmlspecialchars((string) $fetchFlash['error'], ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></pre>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (!$user): ?>
                <p>Sign in</p>
            <?php else: ?>
                <form method="post" action="/">
                    <input type="hidden" name="action" value="fetch_media">
                    <label for="fetch_url">URL</label>
                    <input id="fetch_url" name="url" type="text" inputmode="url" required autocomplete="off" placeholder="https://…">
                    <label for="fetch_tool">Tool</label>
                    <select class="fetch-tool" id="fetch_tool" name="tool">
                        <option value="auto" selected>Auto</option>
                        <option value="gallery-dl">gallery-dl</option>
                        <option value="yt-dlp">yt-dlp</option>
                    </select>
                    <button type="submit" class="btn btn-primary" style="width:100%;">Fetch</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
    <script>
        function ffHealth() {
            return {
                loading: true,
                healthOk: false,
                healthMsg: '',
                async checkHealth() {
                    this.loading = true;
                    try {
                        const r = await fetch('/api/health', { headers: { 'Accept': 'application/json' } });
                        this.healthOk = r.ok;
                        this.healthMsg = r.ok ? 'OK' : ('HTTP ' + r.status);
                    } catch (e) {
                        this.healthOk = false;
                        this.healthMsg = 'Down';
                    }
                    this.loading = false;
                }
            };
        }
    </script>
</body>
</html>
