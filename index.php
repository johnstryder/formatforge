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
    'app_version' => getenv('APP_VERSION') ?: 'v1.1.230',
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
    /** PocketBase collection for prompts + Gemini embeddings (pb_migrations/1774800000_prompts_collection.js, 1774900000_prompts_gemini_schema_merge.js). */
    'prompts_collection' => getenv('PROMPTS_COLLECTION') ?: 'prompts',
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

/** @param array<string, string> $textFields name => scalar string for multipart */
function ff_pb_multipart_escape_name(string $name): string {
    return str_replace(["\r", "\n", '"'], '', $name);
}

/**
 * Create record with one file via raw multipart (avoids PHP CURL quirks with JSON+PATCH).
 *
 * @param array<string, string> $textFields
 * @return array{code: int, body: array, raw: string}
 */
function ff_pb_multipart_create_record_with_file(string $token, string $col, array $textFields, string $fileField, string $absPath, string $mimeType, string $filename): array {
    $base = rtrim((string) ($GLOBALS['CONFIG']['pocketbase_url'] ?? ''), '/');
    $url = $base . '/api/collections/' . rawurlencode($col) . '/records';
    $bin = @file_get_contents($absPath);
    if ($bin === false) {
        return ['code' => 0, 'body' => ['message' => 'Could not read file for upload.'], 'raw' => ''];
    }
    $b = '----FFetch' . bin2hex(random_bytes(16));
    $fn = str_replace(["\r", "\n", '"'], '', $filename);
    $mimeType = str_replace(["\r", "\n"], '', $mimeType);
    $fk = ff_pb_multipart_escape_name($fileField);
    $out = '';
    foreach ($textFields as $k => $v) {
        $kn = ff_pb_multipart_escape_name((string) $k);
        $out .= '--' . $b . "\r\nContent-Disposition: form-data; name=\"{$kn}\"\r\n\r\n" . $v . "\r\n";
    }
    $out .= '--' . $b . "\r\nContent-Disposition: form-data; name=\"{$fk}\"; filename=\"{$fn}\"\r\nContent-Type: {$mimeType}\r\n\r\n";
    $out .= $bin;
    $out .= "\r\n--" . $b . "--\r\n";
    $body = $out;
    $t = trim($token);
    if (stripos($t, 'Bearer ') !== 0) {
        $t = 'Bearer ' . $t;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . $t,
            'Content-Type: multipart/form-data; boundary=' . $b,
        ],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $parsed = json_decode($res ?: '{}', true) ?? [];

    return ['code' => $code, 'body' => $parsed, 'raw' => $res ?: ''];
}

/**
 * Unwrap PocketBase-style or proxy-wrapped JSON so id / fetched_files are on one object.
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function ff_pb_normalize_api_record(array $body): array {
    if (isset($body['data']) && is_array($body['data'])) {
        return $body['data'];
    }
    if (isset($body['record']) && is_array($body['record'])) {
        return $body['record'];
    }

    return $body;
}

/** True only if the API body explicitly lists at least one stored name in fetched_files (maxSelect>1 → array). */
function ff_pb_fetched_files_nonempty_strict(array $rec): bool {
    $rec = ff_pb_normalize_api_record($rec);
    if (!array_key_exists('fetched_files', $rec)) {
        return false;
    }
    $ff = $rec['fetched_files'];
    if ($ff === null) {
        return false;
    }
    if (is_string($ff)) {
        return trim($ff) !== '';
    }
    if (is_array($ff)) {
        if ($ff === []) {
            return false;
        }
        foreach ($ff as $item) {
            if (is_string($item) && trim($item) !== '') {
                return true;
            }
            if (is_array($item) && trim((string) ($item['name'] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    return false;
}

/**
 * PocketBase record id from API JSON (string ids; tolerate int in older responses).
 */
function ff_pb_body_record_id(array $rec): string {
    $rec = ff_pb_normalize_api_record($rec);
    if (!array_key_exists('id', $rec)) {
        return '';
    }
    $v = $rec['id'];
    if ($v === null) {
        return '';
    }
    if (is_string($v) || is_int($v) || is_float($v)) {
        return trim((string) $v);
    }

    return '';
}

/**
 * Ensure body used by callers always includes id when we already know it (multipart/PATCH sometimes omit id).
 *
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function ff_pb_merge_record_id_into_body(array $body, string $recordId): array {
    $body = ff_pb_normalize_api_record($body);
    $recordId = trim($recordId);
    if ($recordId === '') {
        return $body;
    }
    if (ff_pb_body_record_id($body) === '') {
        $body['id'] = $recordId;
    }

    return $body;
}

/**
 * PocketBase validation / API error body (message + optional data); no record id.
 */
function ff_pb_extract_error_message(array $body): string {
    $m = $body['message'] ?? null;
    if (!is_string($m) || trim($m) === '') {
        return '';
    }
    $m = trim($m);
    if (isset($body['data']) && is_array($body['data']) && $body['data'] !== []) {
        $enc = json_encode($body['data'], JSON_UNESCAPED_UNICODE);
        if ($enc !== false && $enc !== '{}' && $enc !== '[]') {
            $m .= ' ' . (strlen($enc) > 800 ? substr($enc, 0, 800) . '…' : $enc);
        }
    }

    return $m;
}

/**
 * After create/PATCH, require a real row with files (same instance as POCKETBASE_URL) or fail closed.
 *
 * @return array{ok: bool, body: array, message: string}
 */
function ff_pb_finalize_fetch_upload(string $token, string $col, array $body): array {
    $rid = ff_pb_body_record_id($body);
    $body = ff_pb_merge_record_id_into_body(ff_pb_normalize_api_record($body), $rid);
    if ($rid === '') {
        return ['ok' => false, 'body' => $body, 'message' => 'PocketBase response did not include a record id after upload.'];
    }
    $g = pb_request('GET', '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($rid), null, $token);
    if ($g['code'] < 200 || $g['code'] >= 300) {
        return [
            'ok' => false,
            'body' => $body,
            'message' => 'PocketBase did not return the new record (HTTP ' . $g['code'] . '). Confirm POCKETBASE_URL matches the admin UI database and that input_media list/view rules allow this user.',
        ];
    }
    $gBody = ff_pb_normalize_api_record($g['body']);
    if (!ff_pb_fetched_files_nonempty_strict($gBody)) {
        return [
            'ok' => false,
            'body' => $body,
            'message' => 'Record exists but fetched_files is empty after save. Check input_media schema (fetched_files field) and PocketBase file limits.',
        ];
    }

    return ['ok' => true, 'body' => ff_pb_merge_record_id_into_body($gBody, $rid), 'message' => ''];
}

/**
 * Create response may omit file fields; confirm with GET so we never return "ok" with an empty file slot.
 *
 * @return array{ok: bool, body: array, reason: string}
 */
function ff_pb_verify_fetched_files_on_record(string $token, string $col, string $recordId, array $respBody): array {
    if (ff_pb_fetched_files_nonempty_strict($respBody)) {
        return ['ok' => true, 'body' => ff_pb_merge_record_id_into_body($respBody, $recordId), 'reason' => ''];
    }
    $g = pb_request('GET', '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($recordId), null, $token);
    $gBody = is_array($g['body'] ?? null) ? $g['body'] : [];
    if ($g['code'] >= 200 && $g['code'] < 300 && ff_pb_fetched_files_nonempty_strict($gBody)) {
        return ['ok' => true, 'body' => ff_pb_merge_record_id_into_body($gBody, $recordId), 'reason' => ''];
    }
    $reason = 'PATCH response had no usable fetched_files; ';
    if ($g['code'] < 200 || $g['code'] >= 300) {
        $reason .= 'GET /records/' . $recordId . ' returned HTTP ' . $g['code'] . '. ' . ff_pb_extract_error_message($gBody);
        if ($g['code'] === 403 || $g['code'] === 401) {
            $reason .= ' (check input_media viewRule — must allow this user to read the record after upload).';
        }
    } else {
        $norm = ff_pb_normalize_api_record($gBody);
        if (!array_key_exists('fetched_files', $norm)) {
            $reason .= 'GET succeeded but the record has no fetched_files field. The input_media collection in PocketBase is missing that File field (PATCH uploads are ignored). In Admin → Collections → input_media, add a File field named fetched_files (max files ≥ 1), or apply migration pb_migrations/1774700000_input_media_fetched_files.js and restart PocketBase.';
        } else {
            $reason .= 'GET succeeded but fetched_files is empty. Check PocketBase file size limits and MIME settings for that field.';
        }
    }

    return ['ok' => false, 'body' => ($g['code'] ?? 0) >= 200 && ($g['code'] ?? 0) < 300 ? $gBody : $respBody, 'reason' => $reason];
}

/**
 * PATCH one file part using raw multipart (more reliable than CURLFile+PATCH on some PHP/cURL builds).
 *
 * @return array{code: int, body: array, raw: string}
 */
function ff_pb_multipart_patch_record_with_file(string $token, string $col, string $recordId, string $fileField, string $absPath, string $mimeType, string $filename): array {
    $base = rtrim((string) ($GLOBALS['CONFIG']['pocketbase_url'] ?? ''), '/');
    $url = $base . '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($recordId);
    $bin = @file_get_contents($absPath);
    if ($bin === false) {
        return ['code' => 0, 'body' => ['message' => 'Could not read file for upload.'], 'raw' => ''];
    }
    $b = '----FFPatch' . bin2hex(random_bytes(16));
    $fn = str_replace(["\r", "\n", '"'], '', $filename);
    $mimeType = str_replace(["\r", "\n"], '', $mimeType);
    $fk = ff_pb_multipart_escape_name($fileField);
    $out = '--' . $b . "\r\nContent-Disposition: form-data; name=\"{$fk}\"; filename=\"{$fn}\"\r\nContent-Type: {$mimeType}\r\n\r\n";
    $out .= $bin;
    $out .= "\r\n--" . $b . "--\r\n";
    $t = trim($token);
    if (stripos($t, 'Bearer ') !== 0) {
        $t = 'Bearer ' . $t;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PATCH',
        CURLOPT_POSTFIELDS => $out,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Authorization: ' . $t,
            'Content-Type: multipart/form-data; boundary=' . $b,
        ],
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $parsed = json_decode($res ?: '{}', true) ?? [];

    return ['code' => $code, 'body' => $parsed, 'raw' => $res ?: ''];
}

/**
 * Fallback: libcurl multipart (CURLFile). Kept after raw multipart in case a host behaves differently.
 *
 * @return array{code: int, body: array, raw: string}
 */
function ff_pb_patch_record_file(string $token, string $col, string $recordId, string $formField, string $absPath, string $mimeType, string $basename): array {
    $file = new CURLFile($absPath, $mimeType, $basename);
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
 * Tries raw multipart create first; falls back to JSON create + PATCH file (fresh CURLFile each try).
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
    $metaStr = json_encode($meta, JSON_UNESCAPED_UNICODE);
    if ($metaStr === false) {
        $metaStr = '{}';
    }
    $mimeType = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $mt = @mime_content_type($absPath);
        if (is_string($mt) && $mt !== '') {
            $mimeType = $mt;
        }
    }
    $baseName = basename($absPath);

    // Multipart form values are strings; do not send bool as the string "true" — PocketBase rejects it
    // (validation error: { message, data }). Set is_active via JSON PATCH after create (see below).
    $textFields = [
        'role' => 'fetched',
        'status' => 'fetched',
        'url' => $sourceUrl,
        'input_url' => $sourceUrl,
        'title' => $title,
        'metadata' => $metaStr,
    ];
    $mp = ff_pb_multipart_create_record_with_file($token, $col, $textFields, 'fetched_files', $absPath, $mimeType, $baseName);
    if ($mp['code'] >= 200 && $mp['code'] < 300) {
        if (ff_pb_body_record_id($mp['body']) === '' && ff_pb_extract_error_message($mp['body']) !== '') {
            return ['code' => 400, 'body' => $mp['body'], 'raw' => $mp['raw']];
        }
        $mid = ff_pb_body_record_id($mp['body']);
        if ($mid !== '') {
            $mv = ff_pb_verify_fetched_files_on_record($token, $col, $mid, $mp['body']);
            if ($mv['ok']) {
                $body = ff_pb_merge_record_id_into_body($mv['body'], $mid);
                $pact = pb_request('PATCH', '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($mid), ['is_active' => true], $token);
                if ($pact['code'] >= 200 && $pact['code'] < 300 && is_array($pact['body'])) {
                    $body = ff_pb_merge_record_id_into_body(ff_pb_normalize_api_record($pact['body']), $mid);
                }
                $fin = ff_pb_finalize_fetch_upload($token, $col, $body);
                if (!$fin['ok']) {
                    ff_pb_delete_input_media_record($token, $mid);

                    return ['code' => 0, 'body' => ['message' => $fin['message']], 'raw' => $mp['raw']];
                }

                return ['code' => $mp['code'], 'body' => $fin['body'], 'raw' => $mp['raw']];
            }
            ff_pb_delete_input_media_record($token, $mid);
        }
    }

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
    $id = ff_pb_body_record_id($create['body']);
    if ($id === '') {
        return ['code' => 0, 'body' => ['message' => 'PocketBase create returned no id.'], 'raw' => $create['raw'] ?? ''];
    }

    // First file: prefer plain field name, then +append (see PocketBase files docs). CURLFile first — some PHP/cURL builds handle it better than raw multipart.
    $patchAttempts = [
        ['curlfile fetched_files', fn () => ff_pb_patch_record_file($token, $col, $id, 'fetched_files', $absPath, $mimeType, $baseName)],
        ['curlfile fetched_files+', fn () => ff_pb_patch_record_file($token, $col, $id, 'fetched_files+', $absPath, $mimeType, $baseName)],
        ['multipart fetched_files', fn () => ff_pb_multipart_patch_record_with_file($token, $col, $id, 'fetched_files', $absPath, $mimeType, $baseName)],
        ['multipart fetched_files+', fn () => ff_pb_multipart_patch_record_with_file($token, $col, $id, 'fetched_files+', $absPath, $mimeType, $baseName)],
    ];
    $lastPatch = ['code' => 0, 'body' => [], 'raw' => ''];
    $failures = [];
    foreach ($patchAttempts as [$label, $fn]) {
        $patch = $fn();
        $lastPatch = $patch;
        if ($patch['code'] < 200 || $patch['code'] >= 300) {
            $failures[] = $label . ': HTTP ' . $patch['code'] . ' ' . ff_pb_extract_error_message(is_array($patch['body'] ?? null) ? $patch['body'] : []);

            continue;
        }
        $pb = is_array($patch['body'] ?? null) ? $patch['body'] : [];
        if (ff_pb_body_record_id($pb) === '' && ff_pb_extract_error_message($pb) !== '') {
            $failures[] = $label . ': ' . ff_pb_extract_error_message($pb);

            continue;
        }
        $pv = ff_pb_verify_fetched_files_on_record($token, $col, $id, $pb);
        if ($pv['ok']) {
            $body = ff_pb_merge_record_id_into_body($pv['body'], $id);
            $fin = ff_pb_finalize_fetch_upload($token, $col, $body);
            if (!$fin['ok']) {
                ff_pb_delete_input_media_record($token, $id);

                return ['code' => 0, 'body' => ['message' => $fin['message']], 'raw' => $patch['raw']];
            }

            return ['code' => $patch['code'], 'body' => $fin['body'], 'raw' => $patch['raw']];
        }
        $failures[] = $label . ': ' . ($pv['reason'] ?? 'verify failed');
    }

    ff_pb_delete_input_media_record($token, $id);
    $detail = $failures !== [] ? implode(' | ', $failures) : '';
    $fallback = ff_pb_extract_error_message(is_array($lastPatch['body'] ?? null) ? $lastPatch['body'] : []);
    $msg = $detail !== '' ? $detail : ($fallback !== '' ? $fallback : 'File upload failed after create (multipart PATCH + fallback).');

    return ['code' => $lastPatch['code'] ?: 400, 'body' => ['message' => $msg, 'data' => $lastPatch['body']['data'] ?? null], 'raw' => $lastPatch['raw']];
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

/**
 * @return array<string, string|bool|int>
 */
function ff_debug_cookie_meta(string $label, string $path): array {
    $path = trim($path);
    if ($path === '') {
        return ['label' => $label, 'path' => '', 'exists' => false, 'readable' => false, 'bytes' => 0];
    }
    $exists = is_file($path);
    $readable = $exists && is_readable($path);

    return [
        'label' => $label,
        'path' => $path,
        'exists' => $exists,
        'readable' => $readable,
        'bytes' => $exists ? (int) @filesize($path) : 0,
    ];
}

/**
 * Config snapshot for support: scalars only; secrets redacted (no cookie contents).
 *
 * @return array<string, mixed>
 */
function ff_debug_redact_config(array $cfg): array {
    $out = [];
    $secretKeys = ['fb_app_secret'];
    foreach ($cfg as $k => $v) {
        $ks = (string) $k;
        if (in_array($ks, $secretKeys, true)) {
            $out[$ks] = is_string($v) && trim($v) !== '' ? '[redacted]' : '';

            continue;
        }
        if (is_bool($v) || is_int($v) || is_float($v) || $v === null) {
            $out[$ks] = $v;
        } elseif (is_string($v)) {
            $out[$ks] = $v;
        } else {
            $out[$ks] = '[non-scalar]';
        }
    }

    return $out;
}

function ff_debug_shell_version_line(string $bin): string {
    $bin = trim($bin);
    if ($bin === '') {
        return '';
    }
    if (str_starts_with($bin, '/') && (!is_file($bin) || !is_executable($bin))) {
        return '';
    }
    $prefix = ff_fetch_env_prefix();
    $log = sys_get_temp_dir() . '/ff_dbg_' . bin2hex(random_bytes(8)) . '.txt';
    $cmd = $prefix . escapeshellarg($bin) . ' --version > ' . escapeshellarg($log) . ' 2>&1';
    exec($cmd, $void, $code);
    $tail = ff_tail_log_file($log, 4000);
    @unlink($log);
    $tail = trim($tail);
    if ($tail !== '') {
        return strlen($tail) > 2000 ? substr($tail, 0, 2000) . "\n…" : $tail;
    }

    return 'exit ' . $code;
}

/**
 * Safe JSON for support (no secrets, no Netscape cookie file contents).
 *
 * @return array<string, mixed>
 */
function ff_debug_collect_safe(): array {
    $cfg = $GLOBALS['CONFIG'];
    $col = trim((string) ($cfg['input_media_collection'] ?? 'input_media'));
    if ($col === '') {
        $col = 'input_media';
    }

    $cookiePick = ff_pick_storage_cookie_file();
    $gdCookieCfg = trim((string) ($cfg['gallery_dl_cookies'] ?? ''));
    $ydCookieCfg = trim((string) ($cfg['yt_dlp_cookies'] ?? ''));
    $envGd = trim((string) (getenv('GALLERY_DL_COOKIES') ?: ''));
    $envYd = trim((string) (getenv('YT_DLP_COOKIES') ?: ''));
    $cookieOptGallery = ff_shell_cookie_opt((string) ($cfg['gallery_dl_cookies'] ?? ''));

    $gBin = ff_fetch_executable((string) ($cfg['gallery_dl_path'] ?? ''), 'gallery-dl');
    $yBin = ff_fetch_executable((string) ($cfg['yt_dlp_path'] ?? ''), 'yt-dlp');

    $gOk = str_starts_with($gBin, '/') ? (is_file($gBin) && is_executable($gBin)) : ($gBin !== '');
    $yOk = str_starts_with($yBin, '/') ? (is_file($yBin) && is_executable($yBin)) : ($yBin !== '');

    $pbHealth = pb_request('GET', '/api/health', null, null);

    $lastFetch = null;
    if (!empty($_SESSION['ff_debug_last_fetch']) && is_array($_SESSION['ff_debug_last_fetch'])) {
        $lastFetch = $_SESSION['ff_debug_last_fetch'];
    }
    $lastFetchPb = null;
    if (!empty($_SESSION['ff_debug_last_fetch_pb']) && is_array($_SESSION['ff_debug_last_fetch_pb'])) {
        $lastFetchPb = $_SESSION['ff_debug_last_fetch_pb'];
    }

    return [
        'generated_at' => gmdate('c'),
        'app' => [
            'version' => (string) ($cfg['app_version'] ?? ''),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
        ],
        'process' => [
            'user' => function_exists('posix_getpwuid') && function_exists('posix_geteuid')
                ? (string) (posix_getpwuid(posix_geteuid())['name'] ?? '')
                : '',
            'uid' => function_exists('posix_geteuid') ? posix_geteuid() : null,
            'home_env' => getenv('HOME') !== false ? (string) getenv('HOME') : '',
        ],
        'paths' => [
            'app_root' => __DIR__,
            'sys_temp_dir' => sys_get_temp_dir(),
        ],
        'config_redacted' => ff_debug_redact_config($cfg),
        'pocketbase' => [
            'url_resolution' => (string) ($cfg['pocketbase_url_resolution'] ?? ''),
            'public_url' => (string) ($cfg['pocketbase_public_url'] ?? ''),
            'api_health' => [
                'http_code' => $pbHealth['code'],
                'ok' => $pbHealth['code'] >= 200 && $pbHealth['code'] < 300,
            ],
        ],
        'fetch_tools' => [
            'gallery_dl' => [
                'configured_path' => (string) ($cfg['gallery_dl_path'] ?? ''),
                'resolved_executable' => $gBin,
                'resolved_is_file' => str_starts_with($gBin, '/') && is_file($gBin),
                'callable' => $gOk,
                '--version' => $gOk ? ff_debug_shell_version_line($gBin) : '',
            ],
            'yt_dlp' => [
                'configured_path' => (string) ($cfg['yt_dlp_path'] ?? ''),
                'resolved_executable' => $yBin,
                'resolved_is_file' => str_starts_with($yBin, '/') && is_file($yBin),
                'callable' => $yOk,
                '--version' => $yOk ? ff_debug_shell_version_line($yBin) : '',
            ],
            'fetch_env_path_prefix' => ff_fetch_path_env_prefix(),
        ],
        'cookies' => [
            'storage_pick' => ff_debug_cookie_meta('storage pick', $cookiePick),
            'env_GALLERY_DL_COOKIES' => ff_debug_cookie_meta('GALLERY_DL_COOKIES', $envGd),
            'env_YT_DLP_COOKIES' => ff_debug_cookie_meta('YT_DLP_COOKIES', $envYd),
            'config_gallery_dl_cookies' => ff_debug_cookie_meta('config gallery_dl_cookies', $gdCookieCfg),
            'config_yt_dlp_cookies' => ff_debug_cookie_meta('config yt_dlp_cookies', $ydCookieCfg),
            'gallery_dl_would_pass_cookies_flag' => $cookieOptGallery !== '',
        ],
        'input_media_collection' => $col,
        'last_fetch' => $lastFetch,
        'last_fetch_pb' => $lastFetchPb,
        'openrouter_configured' => trim((string) (getenv('OPENROUTER_API_KEY') ?: '')) !== '',
        'gemini_embed_configured' => trim((string) (getenv('GEMINI_API_KEY') ?: '')) !== '',
        'prompts_collection' => trim((string) ($GLOBALS['CONFIG']['prompts_collection'] ?? 'prompts')),
    ];
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

/** User + model instruction for OpenRouter vision after each fetched image (also shown in Fetch success UI). */
define('FF_OPENROUTER_IMAGE_RECREATION_INSTRUCTION', 'Fully describe this image for me but do it like you are creating it to recreate the image with an image prompt.');

/**
 * Merge keys into PocketBase record JSON metadata (GET → merge → PATCH).
 *
 * @param array<string, mixed> $merge
 * @param array<int, string> $removeKeys
 */
function ff_pb_patch_merge_record_metadata(string $token, string $col, string $recordId, array $merge, array $removeKeys = []): bool {
    $recordId = trim($recordId);
    if ($recordId === '') {
        return false;
    }
    $g = pb_request('GET', '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($recordId), null, $token);
    if ($g['code'] < 200 || $g['code'] >= 300) {
        return false;
    }
    $body = is_array($g['body'] ?? null) ? $g['body'] : [];
    $meta = $body['metadata'] ?? [];
    if (is_string($meta)) {
        $meta = json_decode($meta, true) ?? [];
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    foreach ($removeKeys as $rk) {
        unset($meta[(string) $rk]);
    }
    foreach ($merge as $k => $v) {
        $meta[(string) $k] = $v;
    }
    $p = pb_request('PATCH', '/api/collections/' . rawurlencode($col) . '/records/' . rawurlencode($recordId), ['metadata' => $meta], $token);

    return $p['code'] >= 200 && $p['code'] < 300;
}

/**
 * OpenRouter vision: image → text suitable as an image-generation recreation prompt.
 *
 * @return array{ok: bool, text: string, error: string}
 */
function ff_openrouter_image_recreation_prompt(string $apiKey, string $model, string $absPath, string $httpReferer): array {
    $out = ['ok' => false, 'text' => '', 'error' => ''];
    $apiKey = trim($apiKey);
    if ($apiKey === '') {
        $out['error'] = 'OPENROUTER_API_KEY not set';

        return $out;
    }
    if (!is_file($absPath) || !is_readable($absPath)) {
        $out['error'] = 'Image path not readable';

        return $out;
    }
    $mime = 'image/jpeg';
    if (function_exists('mime_content_type')) {
        $mt = @mime_content_type($absPath);
        if (is_string($mt) && $mt !== '') {
            $mime = strtolower($mt);
        }
    }
    $mimeNorm = str_replace('image/jpg', 'image/jpeg', $mime);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeNorm, $allowed, true)) {
        $out['error'] = 'OpenRouter vision skipped (not a raster image): ' . $mime;

        return $out;
    }
    $bin = @file_get_contents($absPath);
    if ($bin === false || $bin === '') {
        $out['error'] = 'Could not read image bytes';

        return $out;
    }
    $maxBytes = 4 * 1024 * 1024;
    if (strlen($bin) > $maxBytes) {
        $out['error'] = 'Image too large for OpenRouter (' . strlen($bin) . ' bytes)';

        return $out;
    }
    $b64 = base64_encode($bin);
    $prompt = FF_OPENROUTER_IMAGE_RECREATION_INSTRUCTION;
    $payload = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $prompt],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeNorm . ';base64,' . $b64]],
                ],
            ],
        ],
        'max_tokens' => 4096,
    ];
    $referer = trim($httpReferer);
    if ($referer === '') {
        $referer = 'http://localhost';
    }
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: ' . str_replace(["\r", "\n"], '', $referer),
            'X-Title: FormatForge',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 180,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) {
        $out['error'] = 'OpenRouter request failed (curl)';

        return $out;
    }
    $body = json_decode($res, true) ?? [];
    if ($code < 200 || $code >= 300) {
        $out['error'] = ff_pb_extract_error_message(is_array($body) ? $body : []) ?: ('HTTP ' . $code);

        return $out;
    }
    $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
    if ($text === '') {
        $out['error'] = 'OpenRouter returned empty assistant content';

        return $out;
    }
    $out['ok'] = true;
    $out['text'] = $text;

    return $out;
}

/**
 * Raster image bytes for ML (same MIME/size rules as OpenRouter vision).
 *
 * @return array{ok: bool, mime: string, b64: string, error: string}
 */
function ff_fetch_raster_image_for_ml(string $absPath): array {
    $out = ['ok' => false, 'mime' => '', 'b64' => '', 'error' => ''];
    if (!is_file($absPath) || !is_readable($absPath)) {
        $out['error'] = 'File not readable';

        return $out;
    }
    $mime = 'image/jpeg';
    if (function_exists('mime_content_type')) {
        $mt = @mime_content_type($absPath);
        if (is_string($mt) && $mt !== '') {
            $mime = strtolower($mt);
        }
    }
    $mimeNorm = str_replace('image/jpg', 'image/jpeg', $mime);
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mimeNorm, $allowed, true)) {
        $out['error'] = 'Not a raster image: ' . $mime;

        return $out;
    }
    $bin = @file_get_contents($absPath);
    if ($bin === false || $bin === '') {
        $out['error'] = 'Could not read image bytes';

        return $out;
    }
    $maxBytes = 4 * 1024 * 1024;
    if (strlen($bin) > $maxBytes) {
        $out['error'] = 'Image too large for embedding API (' . strlen($bin) . ' bytes)';

        return $out;
    }
    $out['ok'] = true;
    $out['mime'] = $mimeNorm;
    $out['b64'] = base64_encode($bin);

    return $out;
}

/** Short human-readable float preview for UI (not full vector). */
function ff_embedding_preview_string(array $vec, int $head = 8): string {
    $n = count($vec);
    if ($n === 0) {
        return '(empty vector)';
    }
    $take = min($head, $n);
    $parts = [];
    for ($i = 0; $i < $take; $i++) {
        $x = $vec[$i];
        $parts[] = sprintf('%+.5f', is_numeric($x) ? (float) $x : 0.0);
    }
    $s = '[' . implode(', ', $parts);
    if ($n > $take) {
        $s .= ' … ';
        if ($n > $take + 2) {
            $t0 = sprintf('%+.5f', (float) $vec[$n - 2]);
            $t1 = sprintf('%+.5f', (float) $vec[$n - 1]);
            $s .= $t0 . ', ' . $t1 . ' ';
        }
        $s .= '(' . $n . ' dimensions)]';
    } else {
        $s .= ']';
    }

    return $s;
}

function ff_fetch_pipeline_truncate_detail(string $s, int $max = 420): string {
    if (strlen($s) <= $max) {
        return $s;
    }

    return substr($s, 0, $max) . '…';
}

/**
 * Google Gemini multimodal embedding (image) via embedContent.
 *
 * @return array{ok: bool, vector: array<int, float>, error: string}
 */
function ff_gemini_embed_image_b64(string $apiKey, string $modelId, string $mimeNorm, string $b64): array {
    $out = ['ok' => false, 'vector' => [], 'error' => ''];
    $modelId = trim($modelId);
    if ($modelId === '') {
        $modelId = 'gemini-embedding-2-preview';
    }
    $modelResource = str_starts_with($modelId, 'models/') ? $modelId : ('models/' . $modelId);
    $shortId = str_starts_with($modelId, 'models/') ? substr($modelId, 7) : $modelId;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($shortId) . ':embedContent';
    $reqBody = [
        'model' => $modelResource,
        'content' => [
            'parts' => [
                ['inlineData' => ['mimeType' => $mimeNorm, 'data' => $b64]],
            ],
        ],
    ];
    $payload = json_encode($reqBody, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $out['error'] = 'Could not JSON-encode image embedding request';

        return $out;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) {
        $out['error'] = 'Gemini image embedding request failed (curl)';

        return $out;
    }
    $body = json_decode($res, true) ?? [];
    if ($code < 200 || $code >= 300) {
        $msg = is_array($body) ? (string) ($body['error']['message'] ?? '') : '';
        $out['error'] = $msg !== '' ? $msg : ('HTTP ' . $code);

        return $out;
    }
    $vec = $body['embedding']['values'] ?? null;
    if (!is_array($vec) || $vec === []) {
        $out['error'] = 'Gemini response missing embedding.values';

        return $out;
    }
    $nums = [];
    foreach ($vec as $x) {
        if (is_float($x) || is_int($x)) {
            $nums[] = (float) $x;
        } else {
            $out['error'] = 'Invalid embedding value type';

            return $out;
        }
    }
    $out['ok'] = true;
    $out['vector'] = $nums;

    return $out;
}

/**
 * Google Gemini embeddings API (GEMINI_API_KEY). Model default: gemini-embedding-2-preview (~3072 dims).
 *
 * @return array{ok: bool, vector: array<int, float>, error: string}
 */
function ff_gemini_embed_text(string $apiKey, string $modelId, string $text): array {
    $out = ['ok' => false, 'vector' => [], 'error' => ''];
    $text = trim($text);
    if ($text === '') {
        $out['error'] = 'Empty text for embedding';

        return $out;
    }
    if (strlen($text) > 32000) {
        $text = substr($text, 0, 32000);
    }
    $modelId = trim($modelId);
    if ($modelId === '') {
        $modelId = 'gemini-embedding-2-preview';
    }
    $modelResource = str_starts_with($modelId, 'models/') ? $modelId : ('models/' . $modelId);
    $shortId = str_starts_with($modelId, 'models/') ? substr($modelId, 7) : $modelId;
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode($shortId) . ':embedContent';
    $reqBody = [
        'model' => $modelResource,
        'content' => ['parts' => [['text' => $text]]],
    ];
    $payload = json_encode($reqBody, JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $out['error'] = 'Could not JSON-encode embedding input';

        return $out;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-goog-api-key: ' . $apiKey,
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($res === false) {
        $out['error'] = 'Gemini embeddings request failed (curl)';

        return $out;
    }
    $body = json_decode($res, true) ?? [];
    if ($code < 200 || $code >= 300) {
        $msg = '';
        if (is_array($body)) {
            $msg = (string) ($body['error']['message'] ?? '');
        }
        $out['error'] = $msg !== '' ? $msg : ('HTTP ' . $code);

        return $out;
    }
    $vec = $body['embedding']['values'] ?? null;
    if (!is_array($vec) || $vec === []) {
        $out['error'] = 'Gemini embeddings response missing embedding.values';

        return $out;
    }
    $nums = [];
    foreach ($vec as $x) {
        if (is_float($x) || is_int($x)) {
            $nums[] = (float) $x;
        } else {
            $out['error'] = 'Invalid embedding value type';

            return $out;
        }
    }
    $out['ok'] = true;
    $out['vector'] = $nums;

    return $out;
}

/**
 * Create prompts row (Gemini embedding + prompt_original_media); links back via input_media.metadata.prompts_record_id.
 *
 * @return array{skipped: bool, skip_reason: string, record_id: string, embedding_dims: int, embedding_stored: bool, embedding_preview: string, gemini_error: string, pb_error: string}
 */
function ff_pb_create_prompt_after_fetch(
    string $token,
    string $promptsCol,
    string $inputMediaCol,
    string $inputMediaId,
    string $promptText
): array {
    $out = [
        'skipped' => true,
        'skip_reason' => '',
        'record_id' => '',
        'embedding_dims' => 0,
        'embedding_stored' => false,
        'embedding_preview' => '',
        'gemini_error' => '',
        'pb_error' => '',
    ];
    $promptsCol = trim($promptsCol);
    $inputMediaId = trim($inputMediaId);
    $promptText = trim($promptText);
    if ($promptsCol === '' || $inputMediaId === '' || $promptText === '') {
        $out['skip_reason'] = 'Missing collection id, input media id, or prompt text.';

        return $out;
    }
    $gemKey = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
    if ($gemKey === '') {
        $out['skip_reason'] = 'GEMINI_API_KEY not set (prompts row + prompt embedding skipped).';

        return $out;
    }
    $out['skipped'] = false;
    $embModel = trim((string) (getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview'));
    if ($embModel === '') {
        $embModel = 'gemini-embedding-2-preview';
    }
    $emb = ff_gemini_embed_text($gemKey, $embModel, $promptText);
    if ($emb['ok'] && $emb['vector'] !== []) {
        $out['embedding_dims'] = count($emb['vector']);
        $out['embedding_preview'] = ff_embedding_preview_string($emb['vector']);
    } else {
        $out['gemini_error'] = $emb['error'];
    }
    $payload = [
        'prompt_text' => $promptText,
        'prompt_original_media' => $inputMediaId,
    ];
    if ($emb['ok'] && $emb['vector'] !== []) {
        $payload['prompt_embedding'] = $emb['vector'];
    }
    $r = pb_request('POST', '/api/collections/' . rawurlencode($promptsCol) . '/records', $payload, $token);
    if ($r['code'] >= 200 && $r['code'] < 300) {
        $pid = ff_pb_body_record_id(is_array($r['body'] ?? null) ? $r['body'] : []);
        if ($pid !== '') {
            ff_pb_patch_merge_record_metadata($token, $inputMediaCol, $inputMediaId, ['prompts_record_id' => $pid], []);
        }
        $out['record_id'] = $pid;
        $out['embedding_stored'] = !empty($payload['prompt_embedding']);

        return $out;
    }
    $out['pb_error'] = ff_pb_extract_error_message(is_array($r['body'] ?? null) ? $r['body'] : []) ?: ('HTTP ' . $r['code']);
    if (!empty($payload['prompt_embedding'])) {
        unset($payload['prompt_embedding']);
        $r2 = pb_request('POST', '/api/collections/' . rawurlencode($promptsCol) . '/records', $payload, $token);
        if ($r2['code'] >= 200 && $r2['code'] < 300) {
            $pid = ff_pb_body_record_id(is_array($r2['body'] ?? null) ? $r2['body'] : []);
            if ($pid !== '') {
                ff_pb_patch_merge_record_metadata($token, $inputMediaCol, $inputMediaId, ['prompts_record_id' => $pid], []);
            }
            $out['record_id'] = $pid;
            $out['embedding_stored'] = false;
            $out['pb_error'] = $out['pb_error'] . ' (saved prompts row without vector — check prompt_embedding dimensions vs PocketBase.)';

            return $out;
        }
        $out['pb_error'] .= ' | retry: ' . (ff_pb_extract_error_message(is_array($r2['body'] ?? null) ? $r2['body'] : []) ?: ('HTTP ' . $r2['code']));
    }

    return $out;
}

/**
 * Download to system temp, then upload to PocketBase: one input_media row per file (carousel = N rows).
 *
 * @return array{ok: bool, record_id: string, record_rows: array<int, array{id: string, file: string, label: string, image_prompt?: string, image_prompt_error?: string}>, pb_files: array<int, string>, n: int, error: string, via: string}
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
    $imCol = trim((string) ($GLOBALS['CONFIG']['input_media_collection'] ?? 'input_media'));
    if ($imCol === '') {
        $imCol = 'input_media';
    }
    $orKey = trim((string) (getenv('OPENROUTER_API_KEY') ?: ''));
    $orModel = trim((string) (getenv('OPENROUTER_MODEL') ?: 'google/gemini-3.1-pro-preview'));
    if ($orModel === '') {
        $orModel = 'google/gemini-3.1-pro-preview';
    }
    $orReferer = rtrim((string) ($GLOBALS['CONFIG']['site_url'] ?? ''), '/');
    if ($orReferer === '') {
        $orReferer = 'http://localhost';
    }

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
            $em = ff_pb_extract_error_message(is_array($up['body'] ?? null) ? $up['body'] : []);
            $msg = $em !== '' ? $em : (string) (($up['body']['message'] ?? '') ?: $up['raw'] ?: 'PocketBase upload failed.');
            $empty['error'] = 'PocketBase (item ' . $idx . '/' . $total . '): ' . $msg;
            return $empty;
        }
        $rec = is_array($up['body'] ?? null) ? $up['body'] : [];
        $norm = ff_pb_normalize_api_record($rec);
        $id = ff_pb_body_record_id($rec);
        $names = $norm['fetched_files'] ?? [];
        if (!is_array($names)) {
            $names = $names !== null && $names !== '' ? [(string) $names] : [];
        }
        $names = array_values(array_filter(array_map('strval', $names)));
        $storedName = $names[0] ?? $fn;
        if ($id === '') {
            foreach (array_reverse($createdIds) as $badId) {
                ff_pb_delete_input_media_record((string) $pbToken, $badId);
            }
            ff_fetch_rmdir_recursive($destDir);
            $em = ff_pb_extract_error_message($rec);
            $empty['error'] = $em !== ''
                ? ('PocketBase (item ' . $idx . '/' . $total . '): ' . $em)
                : ('PocketBase (item ' . $idx . '/' . $total . '): HTTP ' . $up['code'] . ' but no record id (keys: ' . implode(', ', array_keys($rec)) . ').');
            return $empty;
        }
        $_SESSION['ff_debug_last_fetch_pb'] = [
            'pb_http_code' => $up['code'],
            'body_top_level_keys' => array_keys($rec),
            'normalized_keys' => array_keys($norm),
            'record_id_parsed' => $id,
        ];
        $createdIds[] = $id;
        $raster = ff_fetch_raster_image_for_ml($pth);
        $pipeline = [];
        $pipeline[] = [
            'key' => 'pocketbase',
            'title' => 'Save file to PocketBase',
            'state' => 'ok',
            'detail' => ff_fetch_pipeline_truncate_detail('Collection `' . $imCol . '` · record `' . $id . '` · stored file `' . $storedName . '`'),
        ];

        $gemKey = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
        $embModel = trim((string) (getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview'));
        if ($embModel === '') {
            $embModel = 'gemini-embedding-2-preview';
        }
        $inputEmbDims = 0;
        $inputEmbPreview = '';
        $inputEmbErr = '';
        $inputEmbStored = false;
        if ($gemKey === '') {
            $pipeline[] = [
                'key' => 'input_embed',
                'title' => 'Embed source image (Gemini → input_media.embedding)',
                'state' => 'skip',
                'detail' => 'Set GEMINI_API_KEY and GEMINI_EMBED_MODEL to vectorize the downloaded raster on the input row.',
            ];
        } elseif (!$raster['ok']) {
            $pipeline[] = [
                'key' => 'input_embed',
                'title' => 'Embed source image (Gemini → input_media.embedding)',
                'state' => 'skip',
                'detail' => ff_fetch_pipeline_truncate_detail($raster['error']),
            ];
        } else {
            $gemImg = ff_gemini_embed_image_b64($gemKey, $embModel, $raster['mime'], $raster['b64']);
            if ($gemImg['ok'] && $gemImg['vector'] !== []) {
                $inputEmbDims = count($gemImg['vector']);
                $inputEmbPreview = ff_embedding_preview_string($gemImg['vector']);
                $patchEmb = pb_request('PATCH', '/api/collections/' . rawurlencode($imCol) . '/records/' . rawurlencode($id), [
                    'embedding' => $gemImg['vector'],
                    'embedding_model' => $embModel,
                ], $pbToken);
                if ($patchEmb['code'] >= 200 && $patchEmb['code'] < 300) {
                    $inputEmbStored = true;
                    $pipeline[] = [
                        'key' => 'input_embed',
                        'title' => 'Embed source image (Gemini → input_media.embedding)',
                        'state' => 'ok',
                        'detail' => ff_fetch_pipeline_truncate_detail($embModel . ' · ' . $inputEmbDims . '-dim vector · preview ' . $inputEmbPreview),
                    ];
                } else {
                    $inputEmbErr = ff_pb_extract_error_message(is_array($patchEmb['body'] ?? null) ? $patchEmb['body'] : []) ?: ('HTTP ' . $patchEmb['code']);
                    $pipeline[] = [
                        'key' => 'input_embed',
                        'title' => 'Embed source image (Gemini → input_media.embedding)',
                        'state' => 'err',
                        'detail' => ff_fetch_pipeline_truncate_detail('Gemini returned a vector but PocketBase PATCH failed: ' . $inputEmbErr),
                    ];
                    ff_pb_patch_merge_record_metadata((string) $pbToken, $imCol, $id, [
                        'input_embedding_patch_error' => $inputEmbErr,
                        'input_embedding_patch_at' => gmdate('c'),
                    ], []);
                }
            } else {
                $inputEmbErr = $gemImg['error'];
                $pipeline[] = [
                    'key' => 'input_embed',
                    'title' => 'Embed source image (Gemini → input_media.embedding)',
                    'state' => 'err',
                    'detail' => ff_fetch_pipeline_truncate_detail($inputEmbErr),
                ];
            }
        }

        $vr = ['ok' => false, 'text' => '', 'error' => ''];
        $promptRec = '';
        $promptEmbDims = 0;
        $promptEmbPreview = '';
        $promptEmbStored = false;
        $promptPipelineErr = '';
        $pcol = trim((string) ($GLOBALS['CONFIG']['prompts_collection'] ?? 'prompts'));
        if ($orKey !== '') {
            $vr = ff_openrouter_image_recreation_prompt($orKey, $orModel, $pth, $orReferer);
            $merge = [
                'image_recreation_prompt_at' => gmdate('c'),
                'image_recreation_prompt_model' => $orModel,
            ];
            $remove = [];
            if ($vr['ok']) {
                $merge['image_recreation_prompt'] = $vr['text'];
                $remove[] = 'image_recreation_prompt_error';
            } else {
                $merge['image_recreation_prompt_error'] = $vr['error'];
            }
            ff_pb_patch_merge_record_metadata((string) $pbToken, $imCol, $id, $merge, $remove);
            if ($vr['ok']) {
                $pipeline[] = [
                    'key' => 'vision',
                    'title' => 'Describe image (OpenRouter vision)',
                    'state' => 'ok',
                    'detail' => ff_fetch_pipeline_truncate_detail('Model `' . $orModel . '` · text stored in metadata.image_recreation_prompt'),
                ];
                if ($pcol !== '') {
                    $pr = ff_pb_create_prompt_after_fetch((string) $pbToken, $pcol, $imCol, $id, $vr['text']);
                    $promptRec = (string) $pr['record_id'];
                    $promptEmbDims = (int) $pr['embedding_dims'];
                    $promptEmbPreview = (string) $pr['embedding_preview'];
                    $promptEmbStored = !empty($pr['embedding_stored']);
                    if ($pr['skipped']) {
                        $promptPipelineErr = (string) $pr['skip_reason'];
                        $pipeline[] = [
                            'key' => 'prompt_row',
                            'title' => 'Embed prompt + create prompts row',
                            'state' => 'skip',
                            'detail' => ff_fetch_pipeline_truncate_detail($promptPipelineErr),
                        ];
                    } elseif ($promptRec !== '') {
                        $warn = $pr['pb_error'] !== '' || $pr['gemini_error'] !== '' || !$promptEmbStored;
                        $pipeline[] = [
                            'key' => 'prompt_row',
                            'title' => 'Embed prompt + create prompts row',
                            'state' => $warn ? 'warn' : 'ok',
                            'detail' => ff_fetch_pipeline_truncate_detail(
                                'Collection `' . $pcol . '` · record `' . $promptRec . '` · model `' . $embModel . '` · '
                                . ($promptEmbStored
                                    ? ($promptEmbDims . '-dim prompt_embedding on row · preview ' . $promptEmbPreview)
                                    : 'prompt row created; vector not stored (Gemini or PocketBase dimension mismatch).')
                                . ($pr['pb_error'] !== '' ? ' · PB: ' . $pr['pb_error'] : '')
                                . ($pr['gemini_error'] !== '' ? ' · Gemini: ' . $pr['gemini_error'] : '')
                            ),
                        ];
                    } else {
                        $promptPipelineErr = $pr['pb_error'] !== '' ? $pr['pb_error'] : ($pr['gemini_error'] !== '' ? $pr['gemini_error'] : 'Prompt row failed');
                        $pipeline[] = [
                            'key' => 'prompt_row',
                            'title' => 'Embed prompt + create prompts row',
                            'state' => 'err',
                            'detail' => ff_fetch_pipeline_truncate_detail($promptPipelineErr),
                        ];
                    }
                } else {
                    $pipeline[] = [
                        'key' => 'prompt_row',
                        'title' => 'Embed prompt + create prompts row',
                        'state' => 'skip',
                        'detail' => 'PROMPTS_COLLECTION / prompts_collection is empty.',
                    ];
                }
            } else {
                $pipeline[] = [
                    'key' => 'vision',
                    'title' => 'Describe image (OpenRouter vision)',
                    'state' => 'err',
                    'detail' => ff_fetch_pipeline_truncate_detail($vr['error']),
                ];
                $pipeline[] = [
                    'key' => 'prompt_row',
                    'title' => 'Embed prompt + create prompts row',
                    'state' => 'skip',
                    'detail' => 'Skipped because vision did not return prompt text.',
                ];
            }
        } else {
            $pipeline[] = [
                'key' => 'vision',
                'title' => 'Describe image (OpenRouter vision)',
                'state' => 'skip',
                'detail' => 'OPENROUTER_API_KEY not set.',
            ];
            $pipeline[] = [
                'key' => 'prompt_row',
                'title' => 'Embed prompt + create prompts row',
                'state' => 'skip',
                'detail' => 'Requires vision text (set OPENROUTER_API_KEY) and GEMINI_API_KEY for embeddings.',
            ];
        }

        $row = [
            'id' => $id,
            'file' => $storedName,
            'label' => $label,
            'is_raster' => $raster['ok'],
            'mime' => $raster['ok'] ? (string) $raster['mime'] : '',
            'pipeline' => $pipeline,
            'input_embedding_dims' => $inputEmbDims,
            'input_embedding_preview' => $inputEmbPreview,
            'input_embedding_stored' => $inputEmbStored,
            'input_embedding_error' => $inputEmbErr,
            'prompt_record_id' => $promptRec,
            'prompt_embedding_dims' => $promptEmbDims,
            'prompt_embedding_preview' => $promptEmbPreview,
            'prompt_embedding_stored' => $promptEmbStored,
            'prompt_pipeline_error' => $promptPipelineErr,
            'embed_model' => $embModel,
        ];
        if ($orKey !== '') {
            $row['image_prompt'] = $vr['ok'] ? $vr['text'] : '';
            $row['image_prompt_error'] = $vr['ok'] ? '' : $vr['error'];
        }
        $recordRows[] = $row;
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ff_debug_json'])) {
    header('Cache-Control: no-store');
    if (!$user || !$authHeader) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Sign in required.'], JSON_UNESCAPED_UNICODE);

        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(ff_debug_collect_safe(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'fetch_media' && $user && $authHeader) {
    $url = (string) ($_POST['url'] ?? '');
    $tool = (string) ($_POST['tool'] ?? 'auto');
    $res = ff_fetch_save_media($url, $tool, $authHeader);
    if (!empty($res['ok'])) {
        $_SESSION['ff_debug_last_fetch'] = [
            'at' => gmdate('c'),
            'ok' => true,
            'via' => (string) ($res['via'] ?? ''),
            'n' => (int) ($res['n'] ?? 0),
            'error' => '',
            'record_id' => (string) ($res['record_id'] ?? ''),
            'record_rows_count' => is_array($res['record_rows'] ?? null) ? count($res['record_rows']) : 0,
        ];
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
    $errTrim = strlen($err) > 2000 ? substr($err, 0, 2000) . '…' : $err;
    $_SESSION['ff_debug_last_fetch'] = [
        'at' => gmdate('c'),
        'ok' => false,
        'via' => (string) ($res['via'] ?? ''),
        'n' => 0,
        'error' => $errTrim,
        'record_id' => '',
        'record_rows_count' => 0,
    ];
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
$pbPublicRaw = rtrim((string) ($CONFIG['pocketbase_public_url'] ?? ''), '/');
$fetchImCol = (string) ($CONFIG['input_media_collection'] ?? 'input_media');
$fetchPrCol = (string) ($CONFIG['prompts_collection'] ?? 'prompts');
$fetchGeminiConfigured = trim((string) (getenv('GEMINI_API_KEY') ?: '')) !== '';
$fetchOpenRouterConfigured = trim((string) (getenv('OPENROUTER_API_KEY') ?: '')) !== '';
$fetchGeminiModelUi = htmlspecialchars(trim((string) (getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
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
        .wrap { max-width: 36rem; margin: 0 auto; display: flex; flex-direction: column; gap: 1rem; }
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
        ul.fetch-files { list-style: none; padding: 0; margin: .5rem 0 0; max-height: min(78vh, 44rem); overflow: auto; font-size: .8125rem; }
        ul.fetch-files li { padding: .25rem 0; border-bottom: 1px solid #21262d; word-break: break-all; }
        ul.fetch-files li:last-child { border-bottom: none; }
        pre.fetch-err { margin: .5rem 0 0; padding: .65rem; background: #0d1117; border: 1px solid #f8514955; border-radius: 8px; color: #f85149; font-size: .75rem; white-space: pre-wrap; word-break: break-word; max-height: 16rem; overflow: auto; }
        .fetch-prompt-wrap { margin: .5rem 0 0; font-size: .75rem; }
        .fetch-prompt-wrap summary { cursor: pointer; color: #58a6ff; font-weight: 600; }
        .fetch-prompt-instruction { margin: .35rem 0 0; color: #8b949e; line-height: 1.45; }
        pre.fetch-prompt { margin: .4rem 0 0; padding: .65rem; background: #0d1117; border: 1px solid #30363d; border-radius: 8px; color: #c9d1d9; font-size: .75rem; white-space: pre-wrap; word-break: break-word; max-height: 18rem; overflow: auto; }
        .fetch-prompt-err { margin: .35rem 0 0; font-size: .75rem; color: #f85149; }
        .fetch-flow-intro { margin: .5rem 0 .75rem; padding: .65rem .75rem; background: #0d1117; border: 1px solid #30363d; border-radius: 8px; font-size: .75rem; color: #8b949e; line-height: 1.55; }
        .fetch-flow-intro ol { margin: .35rem 0 0 1.1rem; padding: 0; }
        .fetch-flow-intro li { margin: .2rem 0; }
        .fetch-flow-intro code { color: #79c0ff; font-size: .72rem; }
        ul.fetch-files li.fetch-row-li { padding: .65rem 0; }
        .fetch-row-head { display: flex; align-items: flex-start; gap: .75rem; flex-wrap: wrap; }
        .fetch-thumb { width: 120px; height: 120px; object-fit: cover; border-radius: 8px; border: 1px solid #30363d; flex-shrink: 0; background: #161b22; }
        .fetch-row-main { flex: 1; min-width: 0; }
        .fetch-links { margin: .35rem 0 0; font-size: .72rem; color: #8b949e; line-height: 1.5; word-break: break-all; }
        .fetch-links a { color: #58a6ff; }
        .fetch-api-line { display: block; margin-top: .2rem; font-family: ui-monospace, monospace; font-size: .68rem; color: #6e7681; }
        .fetch-pipeline-wrap { margin: .5rem 0 0; font-size: .75rem; }
        .fetch-pipeline-wrap > summary { cursor: pointer; color: #58a6ff; font-weight: 600; }
        .fetch-pipeline-ol { margin: .4rem 0 0; padding-left: 1.15rem; }
        .fetch-step-li { margin: .45rem 0; padding-bottom: .45rem; border-bottom: 1px solid #21262d; list-style: decimal; }
        .fetch-step-li:last-child { border-bottom: none; }
        .fetch-step-head { display: flex; align-items: baseline; gap: .5rem; flex-wrap: wrap; margin-bottom: .2rem; }
        .fetch-step-title { font-weight: 600; color: #c9d1d9; }
        .fetch-step-badge { font-size: .65rem; text-transform: uppercase; letter-spacing: .04em; padding: .1rem .35rem; border-radius: 4px; background: #21262d; color: #8b949e; }
        .fetch-step-ok .fetch-step-badge { background: #23863633; color: #3fb950; }
        .fetch-step-warn .fetch-step-badge { background: #9e6a0333; color: #d29922; }
        .fetch-step-err .fetch-step-badge { background: #f8514933; color: #f85149; }
        .fetch-step-skip .fetch-step-badge { background: #30363d; color: #6e7681; }
        .fetch-step-detail { color: #8b949e; line-height: 1.45; font-size: .72rem; }
        .fetch-emb-block { margin: .5rem 0 0; padding: .5rem; background: #010409; border: 1px solid #21262d; border-radius: 6px; }
        .fetch-emb-label { margin: 0 0 .25rem; font-size: .68rem; font-weight: 600; color: #c9d1d9; }
        pre.fetch-emb-mono { margin: 0; padding: 0; background: transparent; border: none; font-size: .68rem; color: #8b949e; white-space: pre-wrap; word-break: break-word; max-height: 10rem; overflow: auto; line-height: 1.4; }
        textarea.debug-console { width: 100%; box-sizing: border-box; margin-top: .75rem; padding: .65rem; border-radius: 8px; border: 1px solid #30363d; background: #0d1117; color: #8b949e; font-size: .75rem; font-family: ui-monospace, monospace; line-height: 1.45; resize: vertical; min-height: 12rem; }
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
            <div class="fetch-flow-intro">
                <strong style="color:#c9d1d9;">What happens per file (in order)</strong>
                <ol>
                    <li><strong>Download</strong> — <code>gallery-dl</code> or <code>yt-dlp</code> saves a file on this server.</li>
                    <li><strong>PocketBase upload</strong> — one <code><?php echo htmlspecialchars($fetchImCol, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code> row per file (<code>fetched_files</code>).</li>
                    <li><strong>Image embedding (Gemini)</strong> — if <code>GEMINI_API_KEY</code> is set, the raster is sent to <code><?php echo $fetchGeminiModelUi; ?></code>; the vector is written to <code>input_media.embedding</code> and model name to <code>embedding_model</code>.<?php if (!$fetchGeminiConfigured): ?> <em>Currently off (no API key).</em><?php endif; ?></li>
                    <li><strong>Vision prompt (OpenRouter)</strong> — if <code>OPENROUTER_API_KEY</code> is set, the same file is described; text goes to <code>metadata.image_recreation_prompt</code>.<?php if (!$fetchOpenRouterConfigured): ?> <em>Currently off.</em><?php endif; ?></li>
                    <li><strong>Prompt embedding + prompts row</strong> — after a successful vision prompt, if <code>GEMINI_API_KEY</code> is set, the prompt text is embedded and a <code><?php echo htmlspecialchars($fetchPrCol, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code> record is created (<code>prompt_text</code>, <code>prompt_embedding</code>, <code>prompt_original_media</code>); <code>metadata.prompts_record_id</code> is set on the input row.</li>
                </ol>
                <p style="margin:.5rem 0 0;">PocketBase admin: <a href="<?php echo htmlspecialchars($pbAdmin, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>" target="_blank" rel="noopener">open dashboard</a><?php if ($pbPublicRaw !== ''): ?> · public API base <code style="color:#8b949e;"><?php echo htmlspecialchars($pbPublicRaw, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code><?php endif; ?></p>
            </div>
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
                    <p style="font-size:.8125rem;margin:.25rem 0 0;">Saved rows (thumbnail, pipeline, embeddings, links):</p>
                    <ul class="fetch-files">
                        <?php
                        $fetchPipelineDetailsOpen = count($fetchRecordRows) === 1 ? ' open' : '';
                        foreach ($fetchRecordRows as $row):
                            $rid = (string) ($row['id'] ?? '');
                            $pfn = (string) ($row['file'] ?? '');
                            $lbl = (string) ($row['label'] ?? $pfn);
                            if ($rid === '' || $pfn === '') {
                                continue;
                            }
                            $fileHref = '/?ff_pb_file=1&amp;c=' . rawurlencode($fetchImCol) . '&amp;id=' . rawurlencode($rid) . '&amp;n=' . rawurlencode($pfn);
                            $isRaster = !empty($row['is_raster']);
                            $mimeR = (string) ($row['mime'] ?? '');
                            $pipeline = is_array($row['pipeline'] ?? null) ? $row['pipeline'] : [];
                            $inPrev = (string) ($row['input_embedding_preview'] ?? '');
                            $inDims = (int) ($row['input_embedding_dims'] ?? 0);
                            $inStored = !empty($row['input_embedding_stored']);
                            $inErr = (string) ($row['input_embedding_error'] ?? '');
                            $prid = (string) ($row['prompt_record_id'] ?? '');
                            $pprev = (string) ($row['prompt_embedding_preview'] ?? '');
                            $pdims = (int) ($row['prompt_embedding_dims'] ?? 0);
                            $pStored = !empty($row['prompt_embedding_stored']);
                            $embMod = (string) ($row['embed_model'] ?? '');
                            $apiInput = $pbPublicRaw !== '' ? ($pbPublicRaw . '/api/collections/' . rawurlencode($fetchImCol) . '/records/' . rawurlencode($rid)) : '';
                            $apiPrompt = ($pbPublicRaw !== '' && $prid !== '') ? ($pbPublicRaw . '/api/collections/' . rawurlencode($fetchPrCol) . '/records/' . rawurlencode($prid)) : '';
                            $ip = (string) ($row['image_prompt'] ?? '');
                            $ipe = (string) ($row['image_prompt_error'] ?? '');
                            ?>
                            <li class="fetch-row-li">
                                <div class="fetch-row-head">
                                    <?php if ($isRaster && str_starts_with($mimeR, 'image/')): ?>
                                        <img class="fetch-thumb" src="<?php echo htmlspecialchars($fileHref, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>" alt="" width="120" height="120" loading="lazy" decoding="async">
                                    <?php endif; ?>
                                    <div class="fetch-row-main">
                                        <a href="<?php echo htmlspecialchars($fileHref, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>"><strong><?php echo htmlspecialchars($lbl, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></strong></a>
                                        <span style="font-size:.72rem;color:#6e7681;margin-left:.35rem;">(<?php echo htmlspecialchars($pfn, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>)</span>
                                        <div class="fetch-links">
                                            <span><strong>input_media</strong> id <code style="color:#79c0ff;"><?php echo htmlspecialchars($rid, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code></span>
                                            <?php if ($prid !== ''): ?>
                                                · <strong>prompts</strong> id <code style="color:#79c0ff;"><?php echo htmlspecialchars($prid, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code>
                                            <?php endif; ?>
                                            <br>
                                            <a href="<?php echo htmlspecialchars($pbAdmin, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>" target="_blank" rel="noopener">PocketBase admin</a>
                                            <?php if ($apiInput !== ''): ?>
                                                <span class="fetch-api-line">GET <span style="color:#8b949e;"><?php echo htmlspecialchars($apiInput, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span> <span style="color:#6e7681;">(with auth)</span></span>
                                            <?php endif; ?>
                                            <?php if ($apiPrompt !== ''): ?>
                                                <span class="fetch-api-line">GET <span style="color:#8b949e;"><?php echo htmlspecialchars($apiPrompt, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span></span>
                                            <?php endif; ?>
                                            <span class="fetch-api-line">Proxy file URL (this app, your session): <span style="color:#8b949e;"><?php echo htmlspecialchars($fileHref, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span></span>
                                        </div>
                                    </div>
                                </div>
                                <?php if ($pipeline !== []): ?>
                                    <details class="fetch-pipeline-wrap"<?php echo $fetchPipelineDetailsOpen; ?>>
                                        <summary>Step-by-step pipeline &amp; status</summary>
                                        <ol class="fetch-pipeline-ol">
                                            <?php foreach ($pipeline as $step): ?>
                                                <?php
                                                $st = (string) ($step['state'] ?? 'skip');
                                                $liClass = 'fetch-step-li fetch-step-skip';
                                                if ($st === 'ok') {
                                                    $liClass = 'fetch-step-li fetch-step-ok';
                                                } elseif ($st === 'warn') {
                                                    $liClass = 'fetch-step-li fetch-step-warn';
                                                } elseif ($st === 'err') {
                                                    $liClass = 'fetch-step-li fetch-step-err';
                                                }
                                                ?>
                                                <li class="<?php echo htmlspecialchars($liClass, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>">
                                                    <div class="fetch-step-head">
                                                        <span class="fetch-step-title"><?php echo htmlspecialchars((string) ($step['title'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span>
                                                        <span class="fetch-step-badge"><?php echo htmlspecialchars($st, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></span>
                                                    </div>
                                                    <div class="fetch-step-detail"><?php echo nl2br(htmlspecialchars((string) ($step['detail'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8')); ?></div>
                                                </li>
                                            <?php endforeach; ?>
                                        </ol>
                                        <?php if ($inPrev !== '' || $inDims > 0 || $inErr !== ''): ?>
                                            <div class="fetch-emb-block">
                                                <p class="fetch-emb-label">input_media.embedding<?php if ($embMod !== ''): ?> <span style="font-weight:400;color:#6e7681;">(<?php echo htmlspecialchars($embMod, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>)</span><?php endif; ?></p>
                                                <?php if ($inErr !== ''): ?>
                                                    <p class="fetch-prompt-err" style="margin:0 0 .35rem;"><?php echo htmlspecialchars($inErr, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
                                                <?php endif; ?>
                                                <?php if ($inPrev !== ''): ?>
                                                    <pre class="fetch-emb-mono"><?php echo htmlspecialchars($inPrev, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></pre>
                                                    <p style="margin:.35rem 0 0;font-size:.65rem;color:#6e7681;">Stored on row: <?php echo $inStored ? 'yes' : 'no'; ?><?php if ($inDims > 0): ?> · <?php echo (int) $inDims; ?> dimensions<?php endif; ?></p>
                                                <?php elseif ($inDims === 0 && $inErr === ''): ?>
                                                    <p style="margin:0;font-size:.7rem;color:#6e7681;">No preview (step skipped).</p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if ($pprev !== '' || $pdims > 0 || $prid !== ''): ?>
                                            <div class="fetch-emb-block">
                                                <p class="fetch-emb-label">prompts.prompt_embedding</p>
                                                <?php if ($pprev !== ''): ?>
                                                    <pre class="fetch-emb-mono"><?php echo htmlspecialchars($pprev, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></pre>
                                                <?php endif; ?>
                                                <p style="margin:.35rem 0 0;font-size:.65rem;color:#6e7681;">Record: <?php echo $prid !== '' ? htmlspecialchars($prid, ENT_QUOTES | ENT_HTML5, 'UTF-8') : '—'; ?> · vector on row: <?php echo $pStored ? 'yes' : 'no'; ?><?php if ($pdims > 0): ?> · <?php echo (int) $pdims; ?> dimensions<?php endif; ?></p>
                                            </div>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                                <?php if ($ip !== '' || $ipe !== ''): ?>
                                    <details class="fetch-prompt-wrap"<?php echo $fetchPipelineDetailsOpen; ?>>
                                        <summary>Vision output (OpenRouter) — full prompt text</summary>
                                        <p class="fetch-prompt-instruction"><strong>Instruction sent:</strong> <?php echo htmlspecialchars(FF_OPENROUTER_IMAGE_RECREATION_INSTRUCTION, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
                                        <?php if ($ipe !== ''): ?>
                                            <p class="fetch-prompt-err"><?php echo htmlspecialchars($ipe, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></p>
                                        <?php endif; ?>
                                        <?php if ($ip !== ''): ?>
                                            <pre class="fetch-prompt"><?php echo htmlspecialchars($ip, ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></pre>
                                        <?php endif; ?>
                                    </details>
                                <?php endif; ?>
                            </li>
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

        <?php if ($user): ?>
        <div class="card" x-data="ffDebug()">
            <h2>Debug console</h2>
            <p style="font-size:.8125rem;color:#8b949e;">Server-side diagnostics (no secrets, no cookie file contents). Load, then copy and paste into support.</p>
            <div class="row" style="margin-top:0;">
                <button type="button" class="btn btn-primary" @click="load()" :disabled="loading">Load</button>
                <button type="button" class="btn" @click="copy()" x-show="text">Copy</button>
            </div>
            <p x-show="loading" style="margin:.5rem 0 0;font-size:.8125rem;">Loading…</p>
            <p x-show="err" class="bad" style="margin:.5rem 0 0;font-size:.8125rem;" x-text="err"></p>
            <textarea class="debug-console" x-show="text" x-model="text" readonly rows="14" placeholder="Click Load…"></textarea>
        </div>
        <?php endif; ?>
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
        function ffDebug() {
            return {
                loading: false,
                text: '',
                err: '',
                async load() {
                    this.loading = true;
                    this.err = '';
                    try {
                        const r = await fetch('/?ff_debug_json=1', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                        const ct = r.headers.get('content-type') || '';
                        if (!r.ok) {
                            const t = await r.text();
                            this.err = 'HTTP ' + r.status + (t ? ': ' + t.slice(0, 240) : '');
                            this.text = '';
                            this.loading = false;
                            return;
                        }
                        if (!ct.includes('application/json')) {
                            this.err = 'Unexpected response (not JSON).';
                            this.text = '';
                            this.loading = false;
                            return;
                        }
                        const j = await r.json();
                        this.text = JSON.stringify(j, null, 2);
                    } catch (e) {
                        this.err = String(e);
                        this.text = '';
                    }
                    this.loading = false;
                },
                async copy() {
                    try {
                        await navigator.clipboard.writeText(this.text);
                    } catch (e) {
                        this.err = 'Copy failed: ' + e;
                    }
                }
            };
        }
    </script>
</body>
</html>
