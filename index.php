<?php

declare(strict_types=1);

/**
 * Carousel Generator (tarball UI) + PocketBase auth, Meta/Instagram OAuth,
 * Gemini vector embeddings API, and optional prompts row creation.
 */

session_start();

$__ffRoot = __DIR__;
$envFile = $__ffRoot . DIRECTORY_SEPARATOR . '.env';
if (is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines !== false) {
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value, " \t\"'");
            $_ENV[$key] = $value;
            putenv($key . '=' . $value);
        }
    }
}

/**
 * UTF-8 safe length/slice without relying on global mb_* polyfills (avoids FPM/opcache oddities).
 * Uses ext-mbstring when loaded; otherwise UTF-8 code units via regex.
 */
function ff_mb_strlen(string $string): int
{
    if ($string === '') {
        return 0;
    }
    if (extension_loaded('mbstring')) {
        return mb_strlen($string);
    }
    if (preg_match_all('/./us', $string, $m) === false) {
        return strlen($string);
    }

    return count($m[0]);
}

function ff_mb_substr(string $string, int $start, ?int $length = null): string
{
    if ($string === '') {
        return '';
    }
    if (extension_loaded('mbstring')) {
        return $length === null ? mb_substr($string, $start) : mb_substr($string, $start, $length);
    }
    if (preg_match_all('/./us', $string, $m) === false) {
        return $length === null ? substr($string, $start) : substr($string, $start, (int) $length);
    }
    $chars = $m[0];
    $n = count($chars);
    if ($start < 0) {
        $start = max(0, $n + $start);
    }
    if ($start >= $n) {
        return '';
    }
    if ($length === null) {
        return implode('', array_slice($chars, $start));
    }

    return implode('', array_slice($chars, $start, $length));
}

/** Raise PHP’s per-request wall clock for Playwright + Instagram Graph polling (FPM defaults are often 30s). */
function ff_allow_long_request(int $seconds = 300): void
{
    if (function_exists('set_time_limit')) {
        @set_time_limit($seconds);
    }
    @ini_set('max_execution_time', (string) $seconds);
    $cur = (int) ini_get('max_execution_time');
    // php_admin_value[max_execution_time] in the FPM pool overrides ini_set — worker still dies at 30s → nginx/Cloudflare 502.
    if ($cur > 0 && $cur < min($seconds, 120)) {
        error_log('FormatForge: max_execution_time is ' . $cur . 's after ff_allow_long_request(' . $seconds . '); set php_admin_value[max_execution_time]=' . $seconds . ' in php-fpm [www] (and reload php8.3-fpm). Cloudflare proxy also times out ~100s.');
    }
}

/** @return string|false Value from env, or default when set and non-empty, or false when missing and no default. */
function cg_env(string $key, string $default = ''): string|false
{
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default !== '' ? $default : false;
    }
    $s = trim((string) $v);

    return $s !== '' ? $s : ($default !== '' ? $default : false);
}

/** @return array<int, mixed>|null */
function cg_parse_slides_json(string $content): ?array
{
    $content = trim($content);
    if ($content === '') {
        return null;
    }
    if (preg_match('/```(?:json)?\s*([\s\S]*?)\s*```/m', $content, $m)) {
        $content = trim($m[1]);
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded) || !isset($decoded['slides']) || !is_array($decoded['slides'])) {
        return null;
    }

    return $decoded['slides'];
}

/** Hard cap for AI-generated slide count (matches Instagram carousel maximum). */
const FF_CAROUSEL_AI_SLIDES_MAX = 10;

/**
 * Query action=… for API routes. Some stacks omit $_GET on POST; fall back to QUERY_STRING / REQUEST_URI.
 */
function ff_request_action(): string
{
    $a = $_GET['action'] ?? null;
    if (is_string($a) && $a !== '') {
        return $a;
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    if (is_string($qs) && $qs !== '' && preg_match('/(?:^|&)action=([^&]*)/', $qs, $m)) {
        return rawurldecode(str_replace('+', ' ', $m[1]));
    }
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (is_string($uri) && $uri !== '' && preg_match('/[?&]action=([^&]*)/', $uri, $m)) {
        return rawurldecode(str_replace('+', ' ', $m[1]));
    }

    return '';
}

/** Comma-separated override in FF_ALLOWED_EMAILS; default is the two operator accounts. */
function ff_gate_allowed_emails(): array
{
    $env = trim((string) (getenv('FF_ALLOWED_EMAILS') ?: ''));
    if ($env !== '') {
        $out = [];
        foreach (explode(',', $env) as $p) {
            $p = strtolower(trim($p));
            if ($p !== '') {
                $out[] = $p;
            }
        }
        if ($out !== []) {
            return $out;
        }
    }

    return ['jnstrdm05@gmail.com', 'dan@bbxp.app'];
}

function ff_gate_email_allowed(?string $email): bool
{
    $e = strtolower(trim((string) $email));

    return $e !== '' && in_array($e, ff_gate_allowed_emails(), true);
}

/** PocketBase session present and email on the allowlist. */
function ff_gate_session_ok(): bool
{
    $u = $_SESSION['pb_user'] ?? null;
    $tok = $_SESSION['pb_token'] ?? null;

    return is_array($u) && is_string($tok) && trim($tok) !== '' && ff_gate_email_allowed($u['email'] ?? null);
}

function ff_resolve_pocketbase_url_meta(): array
{
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

$defaultIgScope = 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement,business_management';
$igScopeEnv = trim((string) (getenv('INSTAGRAM_OAUTH_SCOPE') ?: ''));
$CONFIG = [
    'pocketbase_url' => $pbUrl,
    'pocketbase_url_resolution' => $_ffPbMeta['source'],
    'pocketbase_public_url' => $pbPublicUrl,
    'pocketbase_admin_url' => rtrim($pbPublicUrl, '/') . '/_/',
    'site_url' => $siteUrl,
    'site_name' => getenv('SITE_NAME') ?: 'FormatForge',
    'app_version' => getenv('APP_VERSION') ?: 'v1.1.295',
    'users_collection' => getenv('USERS_COLLECTION') ?: 'users',
    'fb_app_id' => getenv('FB_APP_ID') ?: '',
    'fb_app_secret' => getenv('FB_APP_SECRET') ?: '',
    'instagram_redirect' => getenv('INSTAGRAM_REDIRECT_URI') ?: '',
    'instagram_oauth_scope' => $igScopeEnv !== '' ? $igScopeEnv : $defaultIgScope,
    'input_media_collection' => getenv('INPUT_MEDIA_COLLECTION') ?: 'input_media',
    'output_media_collection' => getenv('OUTPUT_MEDIA_COLLECTION') ?: 'output_media',
    'prompts_collection' => getenv('PROMPTS_COLLECTION') ?: 'prompts',
    'garage_endpoint' => rtrim((string) (getenv('GARAGE_ENDPOINT') ?: ''), '/'),
    'garage_access_key' => getenv('GARAGE_ACCESS_KEY') ?: '',
    'garage_secret_key' => getenv('GARAGE_SECRET_KEY') ?: '',
    'garage_region' => getenv('GARAGE_REGION') ?: 'garage',
    'garage_bucket' => getenv('GARAGE_BUCKET') ?: '',
    'garage_social_content_bucket' => trim((string) (getenv('GARAGE_SOCIAL_CONTENT_BUCKET') ?: '')) !== ''
        ? trim((string) getenv('GARAGE_SOCIAL_CONTENT_BUCKET'))
        : (trim((string) (getenv('GARAGE_BUCKET') ?: '')) !== '' ? trim((string) getenv('GARAGE_BUCKET')) : 'formatforge-social'),
    // Public web endpoint for the social bucket (virtual-hosted style: {bucket}.web.{domain} or full base URL).
    'garage_public_url' => rtrim((string) (getenv('GARAGE_PUBLIC_URL') ?: ''), '/'),
    'garage_public_root_domain' => trim((string) (getenv('GARAGE_PUBLIC_ROOT_DOMAIN') ?: '')),
    'garage_public_scheme' => strtolower(trim((string) (getenv('GARAGE_PUBLIC_SCHEME') ?: 'https'))) ?: 'https',
];

if (is_file(__DIR__ . '/config.php')) {
    $CONFIG = array_merge($CONFIG, require __DIR__ . '/config.php');
}

$GLOBALS['CONFIG'] = $CONFIG;

/**
 * @return array{code: int, body: array, raw: string, curl_errno: int}
 */
function pb_request(string $method, string $path, $data = null, ?string $token = null): array
{
    $url = $GLOBALS['CONFIG']['pocketbase_url'] . $path;
    $ch = curl_init($url);
    if ($ch === false) {
        return ['code' => 0, 'body' => [], 'raw' => '', 'curl_errno' => CURLE_FAILED_INIT];
    }
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
        if (is_array($data)) {
            $payload = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($payload === false) {
                curl_close($ch);

                return ['code' => 0, 'body' => ['message' => 'Request JSON encode failed.'], 'raw' => '', 'curl_errno' => 0];
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    curl_close($ch);
    $decoded = json_decode($res ?: '{}', true);
    $body = is_array($decoded) ? $decoded : [];

    return ['code' => $code, 'body' => $body, 'raw' => $res ?: '', 'curl_errno' => $errNo];
}

/**
 * @param array<string, mixed> $body
 * @return array<string, mixed>
 */
function ff_pb_normalize_api_record(array $body): array
{
    if (isset($body['data']) && is_array($body['data'])) {
        return $body['data'];
    }
    if (isset($body['record']) && is_array($body['record'])) {
        return $body['record'];
    }

    return $body;
}

/**
 * Safe list of records from a PocketBase list response (avoids offset access on non-array body).
 *
 * @param array{code?: int, body?: mixed} $pbResponse
 * @return list<array<string, mixed>>
 */
function ff_pb_list_items(array $pbResponse): array
{
    if (($pbResponse['code'] ?? 0) !== 200) {
        return [];
    }
    $body = $pbResponse['body'] ?? null;
    if (!is_array($body)) {
        return [];
    }
    $items = $body['items'] ?? null;

    return is_array($items) ? $items : [];
}

function ff_pb_body_record_id(array $rec): string
{
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

function ff_pb_extract_error_message(array $body): string
{
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
 * Merge keys into PocketBase record JSON metadata (GET → merge → PATCH).
 *
 * @param array<string, mixed> $merge
 * @param array<int, string> $removeKeys
 */
function ff_pb_patch_merge_record_metadata(string $token, string $col, string $recordId, array $merge, array $removeKeys = []): bool
{
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

function normalize_instagram_username(?string $username): ?string
{
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

function fetch_instagram_username(string $igUserId, array $tokens): ?string
{
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

function pb_find_instagram_account_by_user_id(string $igUserId, ?string $authHeader): ?array
{
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

function ff_redirect_url(string $pathWithQuery): void
{
    $cfg = $GLOBALS['CONFIG'];
    $base = rtrim((string) ($cfg['site_url'] ?? ''), '/');
    header('Location: ' . $base . $pathWithQuery);
    exit;
}

/** Short human-readable float preview for UI (not full vector). */
function ff_embedding_preview_string(array $vec, int $head = 8): string
{
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

/**
 * @return array{ok: bool, vector: array<int, float>, error: string}
 */
function ff_gemini_embed_image_b64(string $apiKey, string $modelId, string $mimeNorm, string $b64): array
{
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
 * @return array{ok: bool, vector: array<int, float>, error: string}
 */
function ff_gemini_embed_text(string $apiKey, string $modelId, string $text): array
{
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
 * Create prompts row (Gemini embedding + prompt_original_media); links input_media.metadata.prompts_record_id.
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
 * @return array<string, mixed>
 */
function ff_debug_redact_config(array $cfg): array
{
    $out = [];
    $secretKeys = ['fb_app_secret', 'garage_secret_key'];
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

/**
 * S3 object prefix for one PocketBase social_accounts row (isolated from other linked accounts).
 */
function ff_garage_social_key_prefix(string $socialAccountId): string
{
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $socialAccountId);
    if ($id === '') {
        return '';
    }

    return 'social_accounts/' . $id . '/';
}

/**
 * Full object key under garage_social_content_bucket; empty string if ids/path are invalid.
 */
function ff_garage_social_object_key(string $socialAccountId, string $relativePath): string
{
    $prefix = ff_garage_social_key_prefix($socialAccountId);
    if ($prefix === '') {
        return '';
    }
    $rel = str_replace('\\', '/', $relativePath);
    $rel = trim($rel, '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return '';
    }
    $parts = explode('/', $rel);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return '';
        }
        $out[] = preg_replace('/[^a-zA-Z0-9._-]/', '_', $p);
    }

    return $prefix . implode('/', $out);
}

/** PocketBase users record id from the signed-in session (for Garage paths). */
function ff_session_pb_user_id(): string
{
    $u = $_SESSION['pb_user'] ?? null;
    if (!is_array($u)) {
        return '';
    }
    $id = isset($u['id']) ? (string) $u['id'] : '';
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);

    return $id;
}

function ff_garage_generated_user_prefix(string $pbUserId): string
{
    $id = preg_replace('/[^a-zA-Z0-9_-]/', '', $pbUserId);
    if ($id === '') {
        return '';
    }

    return 'generated/users/' . $id . '/';
}

/**
 * Full object key for app-generated files (slides JSON, AI images) under garage_social_content_bucket.
 */
function ff_garage_generated_object_key(string $pbUserId, string $relativePath): string
{
    $prefix = ff_garage_generated_user_prefix($pbUserId);
    if ($prefix === '') {
        return '';
    }
    $rel = str_replace('\\', '/', $relativePath);
    $rel = trim($rel, '/');
    if ($rel === '' || str_contains($rel, '..')) {
        return '';
    }
    $parts = explode('/', $rel);
    $out = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return '';
        }
        $out[] = preg_replace('/[^a-zA-Z0-9._-]/', '_', $p);
    }

    return $prefix . implode('/', $out);
}

function ff_should_save_generated_to_garage(): bool
{
    if (!ff_garage_ready()) {
        return false;
    }
    $v = strtolower(trim((string) (getenv('FF_SAVE_GENERATED_TO_GARAGE') ?: '1')));

    return !in_array($v, ['0', 'false', 'off', 'no'], true);
}

/** @return array{ok: bool, bytes: string, content_type: string, error: string} */
function ff_http_get_bytes(string $url, int $maxBytes, int $timeoutSec = 120): array
{
    $out = ['ok' => false, 'bytes' => '', 'content_type' => '', 'error' => ''];
    if (!preg_match('#^https://#i', $url)) {
        $out['error'] = 'URL must be HTTPS';

        return $out;
    }
    $buf = '';
    $ct = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$ct) {
            if (preg_match('/^content-type:\s*(.+)$/i', $header, $m)) {
                $ct = trim(explode(';', trim($m[1]))[0]);
            }

            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buf, $maxBytes, &$out) {
            $buf .= $chunk;
            if (strlen($buf) > $maxBytes) {
                $out['error'] = 'Response too large';

                return 0;
            }

            return strlen($chunk);
        },
    ]);
    $exec = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($exec === false) {
        $out['error'] = $out['error'] !== '' ? $out['error'] : ($cerr !== '' ? $cerr : 'Download failed');

        return $out;
    }
    if ($code < 200 || $code >= 300) {
        $out['error'] = $out['error'] !== '' ? $out['error'] : ('HTTP ' . $code);

        return $out;
    }
    if ($buf === '') {
        $out['error'] = 'Empty body';

        return $out;
    }
    $out['ok'] = true;
    $out['bytes'] = $buf;
    $out['content_type'] = $ct !== '' ? $ct : 'application/octet-stream';

    return $out;
}

function ff_mime_for_image_ext(string $ext): string
{
    $e = strtolower($ext);

    return match ($e) {
        'png' => 'image/png',
        'jpg', 'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
        default => 'image/png',
    };
}

function ff_ini_size_bytes(string $val): int
{
    $val = trim(strtolower($val));
    if ($val === '') {
        return 0;
    }
    $u = substr($val, -1);
    $n = $val;
    $mul = 1;
    if ($u === 'g') {
        $mul = 1073741824;
        $n = substr($val, 0, -1);
    } elseif ($u === 'm') {
        $mul = 1048576;
        $n = substr($val, 0, -1);
    } elseif ($u === 'k') {
        $mul = 1024;
        $n = substr($val, 0, -1);
    }
    if (!is_numeric($n)) {
        return 0;
    }

    return (int) round((float) $n * $mul);
}

function ff_garage_ready(): bool
{
    $c = $GLOBALS['CONFIG'];

    return trim((string) ($c['garage_endpoint'] ?? '')) !== ''
        && trim((string) ($c['garage_access_key'] ?? '')) !== ''
        && trim((string) ($c['garage_secret_key'] ?? '')) !== ''
        && trim((string) ($c['garage_social_content_bucket'] ?? '')) !== '';
}

/**
 * @return array<string, mixed>|null
 */
function ff_pb_owned_social_account(?string $authHeader, string $id): ?array
{
    if ($authHeader === null || trim((string) $authHeader) === '') {
        return null;
    }
    $clean = preg_replace('/[^a-zA-Z0-9_-]/', '', $id);
    if ($clean === '') {
        return null;
    }
    $r = pb_request('GET', '/api/collections/social_accounts/records/' . rawurlencode($clean), null, $authHeader);
    if (($r['code'] ?? 0) !== 200) {
        return null;
    }
    $rec = ff_pb_normalize_api_record($r['body']);

    return is_array($rec) ? $rec : null;
}

/**
 * Base URL for objects in GARAGE_SOCIAL_CONTENT_BUCKET as served by your public Garage/web gateway (no trailing slash).
 */
function ff_garage_public_base_url(): string
{
    $c = $GLOBALS['CONFIG'];
    $direct = trim((string) ($c['garage_public_url'] ?? ''));
    if ($direct !== '') {
        return rtrim($direct, '/');
    }
    $domain = trim((string) ($c['garage_public_root_domain'] ?? ''));
    if ($domain === '') {
        return '';
    }
    $bucket = trim((string) ($c['garage_social_content_bucket'] ?? ''));
    if ($bucket === '') {
        return '';
    }
    $scheme = strtolower(trim((string) ($c['garage_public_scheme'] ?? 'https')));
    if ($scheme !== 'http' && $scheme !== 'https') {
        $scheme = 'https';
    }
    $domain = ltrim($domain, '/');

    return $scheme . '://' . $bucket . '.web.' . $domain;
}

/**
 * Public HTTPS URL for one object key in the social bucket (path segments encoded).
 */
function ff_garage_public_https_url_for_object_key(string $objectKey): string
{
    $base = ff_garage_public_base_url();
    if ($base === '') {
        return '';
    }
    $objectKey = trim(str_replace('\\', '/', $objectKey), '/');
    if ($objectKey === '' || str_contains($objectKey, '..')) {
        return '';
    }
    $parts = explode('/', $objectKey);
    $enc = [];
    foreach ($parts as $p) {
        if ($p === '' || $p === '.' || $p === '..') {
            return '';
        }
        $enc[] = rawurlencode($p);
    }
    $path = implode('/', $enc);
    $wantHttps = strtolower(trim((string) ($GLOBALS['CONFIG']['garage_public_scheme'] ?? 'https'))) !== 'http';
    if (str_starts_with($base, 'http://') && $wantHttps) {
        $base = 'https://' . substr($base, 7);
    }

    return $base . '/' . $path;
}

/**
 * @return array{kind: string, social_account_id?: string, rel: string}|null
 */
function ff_parse_internal_garage_app_url(string $absoluteUrl): ?array
{
    $absoluteUrl = trim($absoluteUrl);
    if ($absoluteUrl === '') {
        return null;
    }
    $p = parse_url($absoluteUrl);
    if (!is_array($p)) {
        return null;
    }
    $query = $p['query'] ?? '';
    if (!is_string($query) || $query === '') {
        return null;
    }
    parse_str($query, $q);
    $action = (string) ($q['action'] ?? '');
    if ($action === 'garage_download') {
        $sid = trim((string) ($q['social_account_id'] ?? ''));
        $rel = trim((string) ($q['key'] ?? ''));
        if ($sid === '' || $rel === '') {
            return null;
        }

        return ['kind' => 'social', 'social_account_id' => $sid, 'rel' => $rel];
    }
    if ($action === 'garage_generated_download') {
        $rel = trim((string) ($q['key'] ?? ''));
        if ($rel === '') {
            return null;
        }

        return ['kind' => 'generated', 'rel' => $rel];
    }

    return null;
}

/**
 * Turn carousel image src into a public HTTPS URL Instagram can fetch (or '' if not resolvable).
 *
 * @param  ?string  $pbUserId  PocketBase user id for generated/… Garage paths when the URL is garage_generated_download.
 */
function ff_instagram_public_https_image_url(string $src, ?string $pbUserId): string
{
    $src = trim($src);
    if ($src === '') {
        return '';
    }
    $site = rtrim((string) ($GLOBALS['CONFIG']['site_url'] ?? ''), '/');
    if (!preg_match('#^https?://#i', $src)) {
        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        } elseif (str_starts_with($src, '/') && $site !== '') {
            $src = $site . $src;
        } else {
            return '';
        }
    }
    $publicBase = ff_garage_public_base_url();
    $parsed = ff_parse_internal_garage_app_url($src);
    if ($parsed !== null) {
        if ($publicBase !== '') {
            if ($parsed['kind'] === 'social') {
                $full = ff_garage_social_object_key($parsed['social_account_id'], $parsed['rel']);
                if ($full !== '') {
                    $u = ff_garage_public_https_url_for_object_key($full);
                    if ($u !== '') {
                        return $u;
                    }
                }
            }
            if ($parsed['kind'] === 'generated') {
                $uid = $pbUserId !== null ? preg_replace('/[^a-zA-Z0-9_-]/', '', $pbUserId) : '';
                if ($uid !== '') {
                    $full = ff_garage_generated_object_key($uid, $parsed['rel']);
                    if ($full !== '') {
                        $u = ff_garage_public_https_url_for_object_key($full);
                        if ($u !== '') {
                            return $u;
                        }
                    }
                }
            }
        }

        return '';
    }
    if (preg_match('#^https://#i', $src)) {
        return $src;
    }
    if (preg_match('#^http://#i', $src)) {
        $pub = parse_url($publicBase !== '' ? $publicBase : '');
        $cur = parse_url($src);
        if (is_array($pub) && is_array($cur) && ($cur['host'] ?? '') !== ''
            && ($cur['host'] ?? '') === ($pub['host'] ?? '')
            && (int) ($cur['port'] ?? 0) === (int) ($pub['port'] ?? 0)) {
            return 'https://' . substr($src, 7);
        }

        return 'https://' . substr($src, 7);
    }

    return '';
}

/**
 * @return list<string>
 */
function ff_carousel_doc_extract_https_image_urls(array $doc, ?string $pbUserId = null): array
{
    $raws = [];
    $slides = $doc['slides'] ?? null;
    if (!is_array($slides)) {
        return [];
    }
    foreach ($slides as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $els = $slide['elements'] ?? [];
        if (is_array($els)) {
            foreach ($els as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $t = $el['type'] ?? '';
                if ($t !== 'ContentImage' && $t !== 'Image') {
                    continue;
                }
                $src = $el['source']['src'] ?? '';
                if (is_string($src) && trim($src) !== '') {
                    $raws[] = trim($src);
                }
            }
        }
        $bg = $slide['backgroundImage'] ?? null;
        if (is_array($bg)) {
            $st = $bg['source'] ?? null;
            if (is_array($st)) {
                $src = $st['src'] ?? '';
                if (is_string($src) && trim($src) !== '') {
                    $raws[] = trim($src);
                }
            }
        }
    }
    $out = [];
    foreach ($raws as $raw) {
        $u = ff_instagram_public_https_image_url($raw, $pbUserId);
        if ($u !== '' && preg_match('#^https://#i', $u)) {
            $out[] = $u;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @return list<string>
 */
function ff_carousel_doc_collect_raw_image_srcs(array $doc): array
{
    $raws = [];
    $slides = $doc['slides'] ?? null;
    if (!is_array($slides)) {
        return [];
    }
    foreach ($slides as $slide) {
        if (!is_array($slide)) {
            continue;
        }
        $els = $slide['elements'] ?? [];
        if (is_array($els)) {
            foreach ($els as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $t = $el['type'] ?? '';
                if ($t !== 'ContentImage' && $t !== 'Image') {
                    continue;
                }
                $src = $el['source']['src'] ?? '';
                if (is_string($src) && trim($src) !== '') {
                    $raws[] = trim($src);
                }
            }
        }
        $bg = $slide['backgroundImage'] ?? null;
        if (is_array($bg)) {
            $st = $bg['source'] ?? null;
            if (is_array($st)) {
                $src = $st['src'] ?? '';
                if (is_string($src) && trim($src) !== '') {
                    $raws[] = trim($src);
                }
            }
        }
    }

    return array_values(array_unique($raws));
}

/**
 * Whether any slide is a video segment (not supported for JPEG carousel export).
 */
function ff_carousel_doc_has_video_slides(array $doc): bool
{
    $slides = $doc['slides'] ?? null;
    if (!is_array($slides)) {
        return false;
    }
    foreach ($slides as $s) {
        if (!is_array($s)) {
            continue;
        }
        if (($s['mediaKind'] ?? '') === 'video') {
            return true;
        }
    }

    return false;
}

/**
 * Resolve an image src for headless Chromium: keep public https URLs; inline others as data: URLs using the signed-in session.
 *
 * @return array{ok: bool, src: string, error: string}
 */
function ff_playwright_resolved_image_src(string $raw, string $authHeader, ?string $pbUserId, int $maxInlineBytes): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['ok' => true, 'src' => '', 'error' => ''];
    }
    if (str_starts_with($raw, 'data:')) {
        return ['ok' => true, 'src' => $raw, 'error' => ''];
    }
    $pub = ff_instagram_public_https_image_url($raw, $pbUserId);
    if ($pub !== '' && preg_match('#^https://#i', $pub)) {
        return ['ok' => true, 'src' => $pub, 'error' => ''];
    }
    $fb = ff_ig_fetch_carousel_image_bytes($raw, $authHeader, $pbUserId);
    if (!$fb['ok']) {
        return ['ok' => false, 'src' => '', 'error' => $fb['error'] !== '' ? $fb['error'] : 'Image fetch failed'];
    }
    if (strlen($fb['bytes']) > $maxInlineBytes) {
        return ['ok' => false, 'src' => '', 'error' => 'Image too large for slide render (' . (int) round(strlen($fb['bytes']) / 1048576) . ' MiB)'];
    }
    $mime = strtolower(trim($fb['content_type']));
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
        return ['ok' => false, 'src' => '', 'error' => 'Not an image (Content-Type: ' . $mime . ')'];
    }
    $mimeOut = $mime !== '' ? $mime : 'image/png';
    $b64 = base64_encode($fb['bytes']);

    return ['ok' => true, 'src' => 'data:' . $mimeOut . ';base64,' . $b64, 'error' => ''];
}

/**
 * Deep-copy doc and replace image sources with public URLs or data URLs so Playwright can render without app cookies.
 *
 * @return array{ok: bool, doc: array, error: string}
 */
function ff_carousel_doc_materialize_images_for_playwright(array $doc, string $authHeader, ?string $pbUserId): array
{
    $maxInline = 15 * 1024 * 1024;
    $json = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return ['ok' => false, 'doc' => [], 'error' => 'Could not encode carousel JSON'];
    }
    $out = json_decode($json, true);
    if (!is_array($out)) {
        return ['ok' => false, 'doc' => [], 'error' => 'Could not clone carousel JSON'];
    }
    $brand = &$out['config']['brand'];
    if (is_array($brand)) {
        $av = $brand['avatar']['source']['src'] ?? '';
        if (is_string($av) && trim($av) !== '') {
            $r = ff_playwright_resolved_image_src($av, $authHeader, $pbUserId, $maxInline);
            if (!$r['ok']) {
                return ['ok' => false, 'doc' => [], 'error' => 'Avatar image: ' . $r['error']];
            }
            if (!isset($brand['avatar']) || !is_array($brand['avatar'])) {
                $brand['avatar'] = ['type' => 'Image', 'source' => ['src' => '', 'type' => 'URL'], 'style' => ['opacity' => 100]];
            }
            if (!isset($brand['avatar']['source']) || !is_array($brand['avatar']['source'])) {
                $brand['avatar']['source'] = ['src' => '', 'type' => 'URL'];
            }
            $brand['avatar']['source']['src'] = $r['src'];
        }
    }
    $slides = &$out['slides'];
    if (!is_array($slides)) {
        return ['ok' => true, 'doc' => $out, 'error' => ''];
    }
    foreach ($slides as $si => $_) {
        if (!is_array($slides[$si])) {
            continue;
        }
        $bg = &$slides[$si]['backgroundImage'];
        if (is_array($bg)) {
            $bs = $bg['source']['src'] ?? '';
            if (is_string($bs) && trim($bs) !== '') {
                $r = ff_playwright_resolved_image_src($bs, $authHeader, $pbUserId, $maxInline);
                if (!$r['ok']) {
                    return ['ok' => false, 'doc' => [], 'error' => 'Background image on slide ' . ($si + 1) . ': ' . $r['error']];
                }
                if (!isset($bg['source']) || !is_array($bg['source'])) {
                    $bg['source'] = ['src' => '', 'type' => 'URL'];
                }
                $bg['source']['src'] = $r['src'];
            }
        }
        $els = &$slides[$si]['elements'];
        if (!is_array($els)) {
            continue;
        }
        foreach ($els as $ei => $_el) {
            if (!is_array($els[$ei])) {
                continue;
            }
            $t = $els[$ei]['type'] ?? '';
            if ($t !== 'ContentImage' && $t !== 'Image') {
                continue;
            }
            $cs = $els[$ei]['source']['src'] ?? '';
            if (!is_string($cs) || trim($cs) === '') {
                continue;
            }
            $r = ff_playwright_resolved_image_src($cs, $authHeader, $pbUserId, $maxInline);
            if (!$r['ok']) {
                return ['ok' => false, 'doc' => [], 'error' => 'Content image on slide ' . ($si + 1) . ': ' . $r['error']];
            }
            if (!isset($els[$ei]['source']) || !is_array($els[$ei]['source'])) {
                $els[$ei]['source'] = ['src' => '', 'type' => 'URL'];
            }
            $els[$ei]['source']['src'] = $r['src'];
        }
    }

    return ['ok' => true, 'doc' => $out, 'error' => ''];
}

/**
 * Run video-pipeline/render-ig-jpegs.mjs; returns sorted list of JPEG file paths.
 *
 * @return array{ok: bool, files: list<string>, error: string, detail: string}
 */
function ff_carousel_run_playwright_jpeg_render(array $docForPlaywright): array
{
    $base = ['ok' => false, 'files' => [], 'error' => '', 'detail' => ''];
    $vp = __DIR__ . DIRECTORY_SEPARATOR . 'video-pipeline';
    $script = $vp . DIRECTORY_SEPARATOR . 'render-ig-jpegs.mjs';
    if (!is_file($script)) {
        $base['error'] = 'Slide JPEG renderer missing. Deploy video-pipeline/render-ig-jpegs.mjs.';

        return $base;
    }
    $node = trim((string) (getenv('FF_NODE_BIN') ?: ''));
    if ($node === '') {
        $node = 'node';
    }
    $tmpRoot = sys_get_temp_dir();
    $uniq = 'ff_ig_jpg_' . bin2hex(random_bytes(8));
    $workDir = $tmpRoot . DIRECTORY_SEPARATOR . $uniq;
    if (!@mkdir($workDir, 0700, true) && !is_dir($workDir)) {
        $base['error'] = 'Could not create temp directory for slide render.';

        return $base;
    }
    $jsonPath = $workDir . DIRECTORY_SEPARATOR . 'carousel-doc.json';
    $outDir = $workDir . DIRECTORY_SEPARATOR . 'out';
    $enc = json_encode($docForPlaywright, JSON_UNESCAPED_UNICODE);
    if ($enc === false || $enc === '') {
        @unlink($jsonPath);
        @rmdir($workDir);
        $base['error'] = 'Could not serialize carousel for renderer.';

        return $base;
    }
    if (strlen($enc) > 12 * 1024 * 1024) {
        @rmdir($workDir);
        $base['error'] = 'Carousel JSON too large for slide render.';

        return $base;
    }
    file_put_contents($jsonPath, $enc);

    $cmd = [$node, $script, $jsonPath, '--out-dir', $outDir];
    $descriptorSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $pbBrowsers = trim((string) (getenv('FF_PLAYWRIGHT_BROWSERS_PATH') ?: getenv('PLAYWRIGHT_BROWSERS_PATH') ?: ''));
    if ($pbBrowsers === '') {
        $defBrowsers = __DIR__ . DIRECTORY_SEPARATOR . 'video-pipeline' . DIRECTORY_SEPARATOR . '.playwright-browsers';
        if (is_dir($defBrowsers)) {
            $pbBrowsers = $defBrowsers;
        }
    }
    $savedPb = getenv('PLAYWRIGHT_BROWSERS_PATH');
    if ($pbBrowsers !== '') {
        putenv('PLAYWRIGHT_BROWSERS_PATH=' . $pbBrowsers);
    }
    try {
        $proc = @proc_open($cmd, $descriptorSpec, $pipes, $vp, null, ['bypass_shell' => true]);
        if (!is_resource($proc)) {
            @unlink($jsonPath);
            ff_carousel_rrmdir($workDir);
            $base['error'] = 'Could not start Node/Playwright (is node on PATH?).';

            return $base;
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], true);
        stream_set_blocking($pipes[2], true);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        if ($code !== 0) {
            ff_carousel_rrmdir($workDir);
            $base['error'] = 'Playwright slide render failed (exit ' . $code . '). On the server run: cd video-pipeline && npm ci && PLAYWRIGHT_BROWSERS_PATH="$(pwd)/.playwright-browsers" npx playwright install chromium && chmod -R a+rX .playwright-browsers (PHP-FPM uses a different HOME than your shell unless you set FF_PLAYWRIGHT_BROWSERS_PATH).';
            $base['detail'] = trim($stderr !== '' ? $stderr : $stdout);

            return $base;
        }
        $line = trim($stdout);
        $decoded = json_decode($line, true);
        $files = [];
        if (is_array($decoded) && !empty($decoded['ok']) && isset($decoded['files']) && is_array($decoded['files'])) {
            foreach ($decoded['files'] as $p) {
                if (is_string($p) && $p !== '' && is_file($p)) {
                    $files[] = $p;
                }
            }
        }
        if ($files === []) {
            $jpgs = glob($outDir . DIRECTORY_SEPARATOR . 'slide-*.jpg', GLOB_NOSORT) ?: [];
            natsort($jpgs);
            $files = array_values($jpgs);
        }
        if ($files === []) {
            ff_carousel_rrmdir($workDir);
            $base['error'] = 'Slide render produced no JPEG files.';
            $base['detail'] = trim($stderr !== '' ? $stderr : $stdout);

            return $base;
        }
        natsort($files);
        $base['ok'] = true;
        $base['files'] = array_values($files);
        $base['detail'] = trim($stderr);

        return $base;
    } finally {
        if ($pbBrowsers !== '') {
            if ($savedPb !== false && $savedPb !== '') {
                putenv('PLAYWRIGHT_BROWSERS_PATH=' . $savedPb);
            } else {
                putenv('PLAYWRIGHT_BROWSERS_PATH');
            }
        }
    }
}

/** Best-effort recursive delete of a temp render directory. */
function ff_carousel_rrmdir(string $dir): void
{
    if ($dir === '' || !is_dir($dir)) {
        return;
    }
    $it = @scandir($dir);
    if (!is_array($it)) {
        return;
    }
    foreach ($it as $f) {
        if ($f === '.' || $f === '..') {
            continue;
        }
        $p = $dir . DIRECTORY_SEPARATOR . $f;
        if (is_dir($p)) {
            ff_carousel_rrmdir($p);
        } else {
            @unlink($p);
        }
    }
    @rmdir($dir);
}

/**
 * Legacy: one URL per unique raw image src (content + background). Does not flatten slides.
 *
 * @return array{ok: bool, urls: list<string>, error: string}
 */
function ff_carousel_doc_resolve_ig_image_urls_legacy(array $doc, string $authHeader, ?string $pbUserId): array
{
    $raws = ff_carousel_doc_collect_raw_image_srcs($doc);
    if ($raws === []) {
        return ['ok' => false, 'urls' => [], 'error' => 'No image URLs in this carousel. In the Slides tab, set a Content image or Background image URL on each slide (or add content-image blocks).'];
    }
    $needMat = false;
    foreach ($raws as $raw) {
        $u = ff_instagram_public_https_image_url($raw, $pbUserId);
        if ($u === '' || !preg_match('#^https://#i', $u)) {
            $needMat = true;
            break;
        }
    }
    if ($needMat && ff_ig_hmac_secret() === '') {
        return ['ok' => false, 'urls' => [], 'error' => 'Set CRON_SECRET or IG_PUBLIC_IMAGE_SECRET so images can be hosted as signed HTTPS URLs for Instagram.'];
    }
    $urls = [];
    foreach ($raws as $raw) {
        $u = ff_instagram_public_https_image_url($raw, $pbUserId);
        if ($u !== '' && preg_match('#^https://#i', $u)) {
            $urls[] = $u;

            continue;
        }
        $mat = ff_ig_materialize_signed_url_for_src($raw, $authHeader, $pbUserId);
        if (!$mat['ok']) {
            return ['ok' => false, 'urls' => [], 'error' => $mat['error'] !== '' ? $mat['error'] : 'Could not prepare image for Instagram.'];
        }
        $urls[] = $mat['url'];
    }

    return ['ok' => true, 'urls' => array_values(array_unique($urls)), 'error' => ''];
}

/**
 * Render each still slide to JPEG (1080×1350), upload to PocketBase, return signed https URLs for Meta.
 *
 * @return array{ok: bool, urls: list<string>, error: string}
 */
function ff_carousel_doc_resolve_ig_jpeg_urls_from_render(array $doc, string $authHeader, ?string $pbUserId): array
{
    $slides = $doc['slides'] ?? null;
    if (!is_array($slides) || $slides === []) {
        return ['ok' => false, 'urls' => [], 'error' => 'Carousel has no slides.'];
    }
    if (count($slides) > 10) {
        return ['ok' => false, 'urls' => [], 'error' => 'Instagram carousels allow at most 10 images; reduce slide count.'];
    }
    if (ff_carousel_doc_has_video_slides($doc)) {
        return ['ok' => false, 'urls' => [], 'error' => 'Instagram JPEG scheduling supports still slides only. Change video slides to still or remove them.'];
    }
    if (ff_ig_hmac_secret() === '') {
        return ['ok' => false, 'urls' => [], 'error' => 'Set CRON_SECRET or IG_PUBLIC_IMAGE_SECRET for hosted slide JPEG URLs.'];
    }
    $cronTok = trim((string) (getenv('FF_CRON_PB_TOKEN') ?: ''));
    if ($cronTok === '') {
        return ['ok' => false, 'urls' => [], 'error' => 'Set FF_CRON_PB_TOKEN so Instagram can fetch uploaded slide JPEGs.'];
    }
    if ($authHeader === null || trim((string) $authHeader) === '') {
        return ['ok' => false, 'urls' => [], 'error' => 'Session required for slide render.'];
    }
    $mat = ff_carousel_doc_materialize_images_for_playwright($doc, $authHeader, $pbUserId);
    if (!$mat['ok']) {
        return ['ok' => false, 'urls' => [], 'error' => $mat['error'] !== '' ? $mat['error'] : 'Could not prepare images for slide render.'];
    }
    $render = ff_carousel_run_playwright_jpeg_render($mat['doc']);
    if (!$render['ok']) {
        $msg = $render['error'];
        if ($render['detail'] !== '' && strlen($render['detail']) < 800) {
            $msg .= ' — ' . $render['detail'];
        }

        return ['ok' => false, 'urls' => [], 'error' => $msg];
    }
    $cfg = $GLOBALS['CONFIG'];
    $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
    $urls = [];
    $n = 0;
    foreach ($render['files'] as $path) {
        if (!is_readable($path)) {
            return ['ok' => false, 'urls' => [], 'error' => 'Rendered JPEG missing on disk.'];
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            return ['ok' => false, 'urls' => [], 'error' => 'Could not read rendered JPEG.'];
        }
        if (strlen($bytes) > 25 * 1024 * 1024) {
            return ['ok' => false, 'urls' => [], 'error' => 'Rendered slide file too large for upload.'];
        }
        $fname = 'ig_slide_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3)) . '_' . (++$n) . '.jpg';
        $cr = ff_ig_pb_create_input_media_from_bytes($bytes, 'image/jpeg', $fname, $authHeader);
        if (!$cr['ok']) {
            return ['ok' => false, 'urls' => [], 'error' => $cr['error'] !== '' ? $cr['error'] : 'PocketBase upload of slide JPEG failed.'];
        }
        $probe = ff_pb_file_get_bytes($inpCol, $cr['record_id'], $cr['stored_filename'], $cronTok);
        if (!$probe['ok']) {
            return ['ok' => false, 'urls' => [], 'error' => 'FF_CRON_PB_TOKEN cannot read uploaded slide JPEG (check PocketBase rules / token).'];
        }
        $exp = time() + (86400 * 400);
        $u = ff_ig_signed_public_image_url($inpCol, $cr['record_id'], $cr['stored_filename'], $exp);
        if ($u === '' || !preg_match('#^https://#i', $u)) {
            return ['ok' => false, 'urls' => [], 'error' => 'Could not build signed URL for slide JPEG (APP_URL must be https in production).'];
        }
        $urls[] = $u;
    }
    $firstPath = $render['files'][0] ?? '';
    if (is_string($firstPath) && $firstPath !== '') {
        $outDirReal = dirname($firstPath);
        $workReal = dirname($outDirReal);
        ff_carousel_rrmdir($workReal);
    }

    return ['ok' => true, 'urls' => $urls, 'error' => ''];
}

function ff_ig_hmac_secret(): string
{
    $s = trim((string) (getenv('IG_PUBLIC_IMAGE_SECRET') ?: ''));
    if ($s !== '') {
        return $s;
    }

    return trim((string) (getenv('CRON_SECRET') ?: ''));
}

/**
 * @return array{ok: bool, bytes: string, content_type: string, error: string}
 */
function ff_http_get_bytes_any(string $url, int $maxBytes, int $timeoutSec = 120): array
{
    $out = ['ok' => false, 'bytes' => '', 'content_type' => '', 'error' => ''];
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        $out['error'] = 'URL must be http(s)';

        return $out;
    }
    $buf = '';
    $ct = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_HTTPHEADER => ['Accept: */*'],
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$ct) {
            if (preg_match('/^content-type:\s*(.+)$/i', $header, $m)) {
                $ct = trim(explode(';', trim($m[1]))[0]);
            }

            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buf, $maxBytes, &$out) {
            $buf .= $chunk;
            if (strlen($buf) > $maxBytes) {
                $out['error'] = 'Response too large';

                return 0;
            }

            return strlen($chunk);
        },
    ]);
    $exec = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);
    if ($exec === false) {
        $out['error'] = $out['error'] !== '' ? $out['error'] : ($cerr !== '' ? $cerr : 'Download failed');

        return $out;
    }
    if ($code < 200 || $code >= 300) {
        $out['error'] = $out['error'] !== '' ? $out['error'] : ('HTTP ' . $code);

        return $out;
    }
    if ($buf === '') {
        $out['error'] = 'Empty body';

        return $out;
    }
    $out['ok'] = true;
    $out['bytes'] = $buf;
    $out['content_type'] = $ct !== '' ? $ct : 'application/octet-stream';

    return $out;
}

/**
 * @return array{ok: bool, bytes: string, content_type: string, error: string}
 */
function ff_pb_file_get_bytes(string $collection, string $recordId, string $filename, string $bearerToken): array
{
    $out = ['ok' => false, 'bytes' => '', 'content_type' => '', 'error' => ''];
    $base = rtrim((string) ($GLOBALS['CONFIG']['pocketbase_url'] ?? ''), '/');
    if ($base === '') {
        $out['error'] = 'PocketBase URL not configured';

        return $out;
    }
    $fn = basename(str_replace('\\', '/', $filename));
    if ($fn === '' || str_contains($fn, '..')) {
        $out['error'] = 'Bad filename';

        return $out;
    }
    $t = preg_replace('/^\s*Bearer\s+/i', '', trim($bearerToken));
    if ($t === '') {
        $out['error'] = 'Missing token';

        return $out;
    }
    $url = $base . '/api/files/' . rawurlencode($collection) . '/' . rawurlencode($recordId) . '/' . rawurlencode($fn);
    $ch = curl_init($url);
    $buf = '';
    $ct = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $t],
        CURLOPT_HEADERFUNCTION => static function ($ch, $header) use (&$ct) {
            if (preg_match('/^content-type:\s*(.+)$/i', $header, $m)) {
                $ct = trim(explode(';', trim($m[1]))[0]);
            }

            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($ch, $chunk) use (&$buf) {
            $buf .= $chunk;
            if (strlen($buf) > 40 * 1024 * 1024) {
                return 0;
            }

            return strlen($chunk);
        },
    ]);
    $exec = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($exec === false || $code < 200 || $code >= 300) {
        $out['error'] = 'HTTP ' . $code;

        return $out;
    }
    if ($buf === '') {
        $out['error'] = 'Empty file';

        return $out;
    }
    $out['ok'] = true;
    $out['bytes'] = $buf;
    $out['content_type'] = $ct !== '' ? $ct : 'application/octet-stream';

    return $out;
}

/**
 * @return array{ok: bool, bytes: string, content_type: string, error: string}
 */
function ff_ig_fetch_carousel_image_bytes(string $raw, ?string $authHeader, ?string $pbUserId): array
{
    $fail = ['ok' => false, 'bytes' => '', 'content_type' => '', 'error' => ''];
    $site = rtrim((string) ($GLOBALS['CONFIG']['site_url'] ?? ''), '/');
    $src = trim($raw);
    if ($src === '') {
        $fail['error'] = 'Empty URL';

        return $fail;
    }
    if (!preg_match('#^https?://#i', $src)) {
        if (str_starts_with($src, '//')) {
            $src = 'https:' . $src;
        } elseif (str_starts_with($src, '/') && $site !== '') {
            $src = $site . $src;
        } else {
            $fail['error'] = 'Unsupported URL';

            return $fail;
        }
    }
    $maxB = 40 * 1024 * 1024;
    $parsed = ff_parse_internal_garage_app_url($src);
    if ($parsed !== null) {
        if ($authHeader === null || trim($authHeader) === '') {
            $fail['error'] = 'Session required for Garage image';

            return $fail;
        }
        if ($parsed['kind'] === 'social') {
            $sid = (string) $parsed['social_account_id'];
            $rel = (string) $parsed['rel'];
            if (ff_pb_owned_social_account($authHeader, $sid) === null) {
                $fail['error'] = 'Garage image not accessible';

                return $fail;
            }
            $fullKey = ff_garage_social_object_key($sid, $rel);
            if ($fullKey === '') {
                $fail['error'] = 'Bad Garage key';

                return $fail;
            }
            $r = ff_garage_s3_request('GET', $fullKey, [], '');
            if (!$r['ok'] || strlen($r['body']) > $maxB) {
                $fail['error'] = $r['error'] !== '' ? $r['error'] : 'Garage fetch failed';

                return $fail;
            }

            return ['ok' => true, 'bytes' => $r['body'], 'content_type' => $r['content_type'] ?? 'application/octet-stream', 'error' => ''];
        }
        if ($parsed['kind'] === 'generated') {
            $uid = $pbUserId !== null ? preg_replace('/[^a-zA-Z0-9_-]/', '', $pbUserId) : '';
            if ($uid === '') {
                $fail['error'] = 'User id required for generated image';

                return $fail;
            }
            $fullKey = ff_garage_generated_object_key($uid, (string) $parsed['rel']);
            if ($fullKey === '') {
                $fail['error'] = 'Bad generated key';

                return $fail;
            }
            $r = ff_garage_s3_request('GET', $fullKey, [], '');
            if (!$r['ok'] || strlen($r['body']) > $maxB) {
                $fail['error'] = $r['error'] !== '' ? $r['error'] : 'Garage fetch failed';

                return $fail;
            }

            return ['ok' => true, 'bytes' => $r['body'], 'content_type' => $r['content_type'] ?? 'application/octet-stream', 'error' => ''];
        }
    }
    $pq = parse_url($src, PHP_URL_QUERY);
    if (is_string($pq) && $pq !== '') {
        parse_str($pq, $q);
        if (($q['action'] ?? '') === 'media_file') {
            $cfg = $GLOBALS['CONFIG'];
            $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
            $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
            $colWant = trim((string) ($q['collection'] ?? ''));
            $rid = trim((string) ($q['record_id'] ?? ''));
            $fn = basename(str_replace('\\', '/', (string) ($q['filename'] ?? '')));
            if ($colWant === '' || $rid === '' || $fn === '' || str_contains($fn, '..')) {
                $fail['error'] = 'Bad media_file URL';

                return $fail;
            }
            if ($colWant !== $inpCol && $colWant !== $outCol) {
                $fail['error'] = 'Unsupported collection';

                return $fail;
            }
            if ($authHeader === null || trim($authHeader) === '') {
                $fail['error'] = 'Session required for media file';

                return $fail;
            }
            $recR = pb_request('GET', '/api/collections/' . rawurlencode($colWant) . '/records/' . rawurlencode($rid), null, $authHeader);
            if (($recR['code'] ?? 0) !== 200) {
                $fail['error'] = 'Record not found';

                return $fail;
            }
            $rec = ff_pb_normalize_api_record($recR['body']);
            if (!is_array($rec) || !ff_pb_record_has_filename($rec, $fn)) {
                $fail['error'] = 'File not in record';

                return $fail;
            }
            $tok = preg_replace('/^\s*Bearer\s+/i', '', trim($authHeader));

            return ff_pb_file_get_bytes($colWant, $rid, $fn, $tok);
        }
    }
    if (preg_match('#^https://#i', $src)) {
        return ff_http_get_bytes($src, $maxB, 120);
    }
    if (preg_match('#^http://#i', $src)) {
        return ff_http_get_bytes_any($src, $maxB, 120);
    }
    $fail['error'] = 'Unsupported image URL';

    return $fail;
}

function ff_ig_image_bytes_looks_like_jpeg(string $b): bool
{
    return strlen($b) >= 3 && $b[0] === "\xFF" && $b[1] === "\xD8" && $b[2] === "\xFF";
}

/**
 * Instagram feed publishing expects JPEG (see Meta image specs). Pass-through JPEG or re-encode via GD.
 *
 * @return array{ok: bool, bytes: string, error: string}
 */
function ff_ig_normalize_image_bytes_to_jpeg_for_ig(string $bytes): array
{
    if ($bytes === '') {
        return ['ok' => false, 'bytes' => '', 'error' => 'Empty image data.'];
    }
    if (strlen($bytes) > 10 * 1024 * 1024) {
        return ['ok' => false, 'bytes' => '', 'error' => 'Image too large before conversion (max ~10MB).'];
    }
    if (ff_ig_image_bytes_looks_like_jpeg($bytes)) {
        return ['ok' => true, 'bytes' => $bytes, 'error' => ''];
    }
    if (!extension_loaded('gd')) {
        return ['ok' => false, 'bytes' => '', 'error' => 'Instagram expects JPEG. This image is not JPEG; install/enable php-gd on the server to convert PNG/WebP automatically.'];
    }
    $im = @imagecreatefromstring($bytes);
    if ($im === false) {
        return ['ok' => false, 'bytes' => '', 'error' => 'Could not decode image (corrupt or unsupported format).'];
    }
    if (function_exists('imagepalettetotruecolor') && !imageistruecolor($im)) {
        imagepalettetotruecolor($im);
    }
    imagealphablending($im, true);
    imagesavealpha($im, false);
    ob_start();
    imagejpeg($im, null, 92);
    $jpeg = (string) ob_get_clean();
    imagedestroy($im);
    if ($jpeg === '') {
        return ['ok' => false, 'bytes' => '', 'error' => 'JPEG encode failed.'];
    }
    if (strlen($jpeg) > 8 * 1024 * 1024) {
        return ['ok' => false, 'bytes' => '', 'error' => 'JPEG exceeds Instagram 8MB limit; simplify the image.'];
    }

    return ['ok' => true, 'bytes' => $jpeg, 'error' => ''];
}

/**
 * @return array{ok: bool, bytes: string, error: string}
 */
function ff_ig_curl_fetch_public_image_url(string $url, int $maxBytes = 10485760, int $timeoutSec = 90): array
{
    $fail = ['ok' => false, 'bytes' => '', 'error' => ''];
    $url = trim($url);
    if ($url === '' || !preg_match('#^https://#i', $url)) {
        $fail['error'] = 'Image URL must use HTTPS.';

        return $fail;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        $fail['error'] = 'Could not start download.';

        return $fail;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code < 200 || $code >= 300 || !is_string($body) || $body === '') {
        $fail['error'] = 'Could not download image (HTTP ' . $code . ').';

        return $fail;
    }
    if (strlen($body) > $maxBytes) {
        $fail['error'] = 'Image download exceeds ' . (int) round($maxBytes / 1048576) . 'MB.';

        return $fail;
    }

    return ['ok' => true, 'bytes' => $body, 'error' => ''];
}

/**
 * Non-JPEG files (WebP, PNG, …) are converted and re-hosted so Meta always fetches JPEG.
 *
 * @param  list<string>  $urls
 * @return array{ok: bool, urls: list<string>, error: string}
 */
function ff_ig_publish_prepare_urls_jpeg_for_meta(array $urls, string $pbBearer): array
{
    $out = ['ok' => false, 'urls' => [], 'error' => ''];
    $pbBearer = preg_replace('/^\s*Bearer\s+/i', '', trim($pbBearer));
    if ($pbBearer === '') {
        $out['error'] = 'Internal: PocketBase token missing for JPEG step.';

        return $out;
    }
    if (ff_ig_hmac_secret() === '') {
        $out['error'] = 'CRON_SECRET or IG_PUBLIC_IMAGE_SECRET required to sign re-hosted JPEG URLs.';

        return $out;
    }
    $inpCol = (string) ($GLOBALS['CONFIG']['input_media_collection'] ?? 'input_media');
    $exp = time() + (86400 * 400);
    $n = 0;
    foreach ($urls as $u) {
        if (!is_string($u)) {
            continue;
        }
        $u = trim($u);
        if ($u === '' || !preg_match('#^https://#i', $u)) {
            $out['error'] = 'Each carousel image must be a public HTTPS URL.';

            return $out;
        }
        $leaf = '';
        $qs = (string) (parse_url($u, PHP_URL_QUERY) ?? '');
        if ($qs !== '') {
            parse_str($qs, $qq);
            if (($qq['action'] ?? '') === 'ig_public_image' && !empty($qq['f'])) {
                $leaf = basename(str_replace('\\', '/', (string) $qq['f']));
            }
        }
        if ($leaf === '') {
            $path = (string) (parse_url($u, PHP_URL_PATH) ?? '');
            $leaf = $path !== '' ? basename($path) : '';
        }
        if ($leaf !== '' && preg_match('/\.jpe?g$/i', $leaf)) {
            $out['urls'][] = $u;

            continue;
        }
        $got = ff_ig_curl_fetch_public_image_url($u, 10 * 1024 * 1024, 90);
        if (!$got['ok']) {
            $out['error'] = $got['error'] !== '' ? $got['error'] : 'Image download failed.';

            return $out;
        }
        if (ff_ig_image_bytes_looks_like_jpeg($got['bytes'])) {
            $out['urls'][] = $u;

            continue;
        }
        $norm = ff_ig_normalize_image_bytes_to_jpeg_for_ig($got['bytes']);
        if (!$norm['ok']) {
            $out['error'] = $norm['error'] !== '' ? $norm['error'] : 'Could not convert image to JPEG for Instagram.';

            return $out;
        }
        $fname = 'ig_meta_jpeg_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3)) . '_' . (++$n) . '.jpg';
        $cr = ff_ig_pb_create_input_media_from_bytes($norm['bytes'], 'image/jpeg', $fname, $pbBearer);
        if (!$cr['ok']) {
            $out['error'] = $cr['error'] !== '' ? $cr['error'] : 'PocketBase upload failed after JPEG conversion.';

            return $out;
        }
        $probe = ff_pb_file_get_bytes($inpCol, $cr['record_id'], $cr['stored_filename'], $pbBearer);
        if (!$probe['ok']) {
            $out['error'] = 'PocketBase token cannot read re-hosted JPEG (check rules / FF_CRON_PB_TOKEN).';

            return $out;
        }
        $signed = ff_ig_signed_public_image_url($inpCol, $cr['record_id'], $cr['stored_filename'], $exp);
        if ($signed === '' || !preg_match('#^https://#i', $signed)) {
            $out['error'] = 'Could not build signed URL for converted JPEG (site_url must be https).';

            return $out;
        }
        $out['urls'][] = $signed;
    }
    $out['ok'] = true;

    return $out;
}

/**
 * @return array{ok: bool, error: string, record_id: string, stored_filename: string}
 */
function ff_ig_pb_create_input_media_from_bytes(string $bytes, string $mime, string $fname, string $authHeader): array
{
    $out = ['ok' => false, 'error' => '', 'record_id' => '', 'stored_filename' => ''];
    $cfg = $GLOBALS['CONFIG'];
    $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
    $base = rtrim((string) ($cfg['pocketbase_url'] ?? ''), '/');
    if ($base === '') {
        $out['error'] = 'PocketBase URL not configured';

        return $out;
    }
    $t = preg_replace('/^\s*Bearer\s+/i', '', trim($authHeader));
    if ($t === '') {
        $out['error'] = 'No auth token';

        return $out;
    }
    $tmp = tempnam(sys_get_temp_dir(), 'igup_');
    if ($tmp === false) {
        $out['error'] = 'Temp file failed';

        return $out;
    }
    file_put_contents($tmp, $bytes);
    $safeBase = basename($fname);
    if (!preg_match('/\.[a-z0-9]{2,8}$/i', $safeBase)) {
        $safeBase .= '.png';
    }
    $cf = new CURLFile($tmp, $mime !== '' ? $mime : 'image/png', $safeBase);
    $ch = curl_init($base . '/api/collections/' . rawurlencode($inpCol) . '/records');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $t,
        ],
        CURLOPT_POSTFIELDS => [
            'body' => json_encode([
                'title' => 'Instagram carousel',
                'role' => 'ig_carousel_cache',
            ], JSON_UNESCAPED_UNICODE),
            'fetched_files' => $cf,
        ],
        CURLOPT_TIMEOUT => 120,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $body = json_decode($raw ?: '{}', true);
    if ($code < 200 || $code >= 300 || !is_array($body)) {
        $out['error'] = is_array($body) ? (string) ($body['message'] ?? 'HTTP ' . $code) : 'HTTP ' . $code;

        return $out;
    }
    $rec = ff_pb_normalize_api_record($body);
    if (!is_array($rec)) {
        $out['error'] = 'Bad create response';

        return $out;
    }
    $rid = trim((string) ($rec['id'] ?? ''));
    $manifest = ff_pb_record_file_manifest($rec);
    $stored = $manifest[0] ?? '';
    if ($rid === '' || $stored === '') {
        $out['error'] = 'Record missing file';

        return $out;
    }
    $out['ok'] = true;
    $out['record_id'] = $rid;
    $out['stored_filename'] = $stored;

    return $out;
}

function ff_ig_signed_public_image_url(string $inpCol, string $rid, string $fname, int $exp): string
{
    $secret = ff_ig_hmac_secret();
    if ($secret === '') {
        return '';
    }
    $sig = hash_hmac('sha256', "ig_pub|$inpCol|$rid|$fname|$exp", $secret);
    $site = rtrim((string) ($GLOBALS['CONFIG']['site_url'] ?? ''), '/');
    $path = ff_app_script_path();
    $q = http_build_query([
        'action' => 'ig_public_image',
        'c' => $inpCol,
        'id' => $rid,
        'f' => $fname,
        'exp' => $exp,
        'sig' => $sig,
    ], '', '&', PHP_QUERY_RFC3986);

    return $site . $path . '?' . $q;
}

/**
 * @return array{ok: bool, url: string, error: string}
 */
function ff_ig_materialize_signed_url_for_src(string $raw, ?string $authHeader, ?string $pbUserId): array
{
    $out = ['ok' => false, 'url' => '', 'error' => ''];
    if (ff_ig_hmac_secret() === '') {
        $out['error'] = 'Set CRON_SECRET or IG_PUBLIC_IMAGE_SECRET for Instagram image URLs.';

        return $out;
    }
    $cronTok = trim((string) (getenv('FF_CRON_PB_TOKEN') ?: ''));
    if ($cronTok === '') {
        $out['error'] = 'Set FF_CRON_PB_TOKEN (PocketBase token that can read input_media files for publishing).';

        return $out;
    }
    if ($authHeader === null || trim($authHeader) === '') {
        $out['error'] = 'Session required';

        return $out;
    }
    $fb = ff_ig_fetch_carousel_image_bytes($raw, $authHeader, $pbUserId);
    if (!$fb['ok']) {
        $out['error'] = $fb['error'] !== '' ? $fb['error'] : 'Download failed';

        return $out;
    }
    $mime = strtolower(trim($fb['content_type']));
    if ($mime !== '' && !str_starts_with($mime, 'image/')) {
        $out['error'] = 'Not an image (Content-Type: ' . $mime . ')';

        return $out;
    }
    $normB = ff_ig_normalize_image_bytes_to_jpeg_for_ig($fb['bytes']);
    if (!$normB['ok']) {
        $out['error'] = $normB['error'] !== '' ? $normB['error'] : 'Could not convert image to JPEG for Instagram.';

        return $out;
    }
    $fname = 'ig_carousel_' . gmdate('Ymd\THis\Z') . '_' . bin2hex(random_bytes(3)) . '.jpg';
    $cr = ff_ig_pb_create_input_media_from_bytes($normB['bytes'], 'image/jpeg', $fname, $authHeader);
    if (!$cr['ok']) {
        $out['error'] = $cr['error'] !== '' ? $cr['error'] : 'PocketBase upload failed';

        return $out;
    }
    $cfg = $GLOBALS['CONFIG'];
    $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
    $probe = ff_pb_file_get_bytes($inpCol, $cr['record_id'], $cr['stored_filename'], $cronTok);
    if (!$probe['ok']) {
        $out['error'] = 'FF_CRON_PB_TOKEN cannot read the uploaded file (check PocketBase rules / use a superuser token).';

        return $out;
    }
    $exp = time() + (86400 * 400);
    $u = ff_ig_signed_public_image_url($inpCol, $cr['record_id'], $cr['stored_filename'], $exp);
    if ($u === '' || !preg_match('#^https://#i', $u)) {
        $out['error'] = 'Could not build signed URL (APP_URL must be https in production).';

        return $out;
    }
    $out['ok'] = true;
    $out['url'] = $u;

    return $out;
}

/**
 * @return array{ok: bool, urls: list<string>, error: string}
 */
function ff_carousel_doc_resolve_ig_image_urls_for_schedule(array $doc, string $authHeader, ?string $pbUserId): array
{
    if (trim((string) (getenv('FF_IG_USE_SLIDE_RENDER') ?: '')) === '0') {
        return ff_carousel_doc_resolve_ig_image_urls_legacy($doc, $authHeader, $pbUserId);
    }

    return ff_carousel_doc_resolve_ig_jpeg_urls_from_render($doc, $authHeader, $pbUserId);
}

/**
 * @return array{code: int, body: array<string, mixed>, raw: string}
 */
function ff_ig_graph_request(string $method, string $path, string $accessToken, array $params = []): array
{
    $path = ltrim($path, '/');
    $url = 'https://graph.facebook.com/v18.0/' . $path;
    $method = strtoupper($method);
    $params['access_token'] = $accessToken;
    $ch = curl_init();
    if ($method === 'GET') {
        $url .= '?' . http_build_query($params);
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
    } else {
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
        ]);
    }
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $decoded = json_decode($raw ?: '{}', true);
    $body = is_array($decoded) ? $decoded : [];

    return ['code' => $code, 'body' => $body, 'raw' => $raw ?: ''];
}

function ff_ig_wait_media_ready(string $creationId, string $accessToken, int $maxWaitSec = 90): bool
{
    $deadline = time() + $maxWaitSec;
    while (time() < $deadline) {
        $r = ff_ig_graph_request('GET', $creationId, $accessToken, ['fields' => 'status_code,status']);
        if (($r['code'] ?? 0) !== 200) {
            return false;
        }
        $st = (string) ($r['body']['status_code'] ?? $r['body']['status'] ?? '');
        if ($st === 'FINISHED' || $st === 'PUBLISHED') {
            return true;
        }
        if ($st === 'ERROR' || $st === 'EXPIRED') {
            return false;
        }
        usleep(500000);
    }

    return false;
}

/**
 * @param  list<string>  $imageUrls
 * @return list<string>
 */
function ff_ig_normalize_image_url_list(array $imageUrls, ?string $pbUserIdForGarage): array
{
    $out = [];
    foreach ($imageUrls as $u) {
        if (!is_string($u)) {
            continue;
        }
        $n = ff_instagram_public_https_image_url(trim($u), $pbUserIdForGarage);
        if ($n !== '' && preg_match('#^https://#i', $n)) {
            $out[] = $n;
        }
    }

    return array_values(array_unique($out));
}

/**
 * @return array{ok: bool, error: string, media_id: string}
 */
function ff_ig_publish_carousel_or_single(string $igUserId, string $accessToken, array $imageUrls, string $caption, ?string $pbUserIdForGarage = null, ?string $pbTokenForJpegNormalize = null): array
{
    $out = ['ok' => false, 'error' => '', 'media_id' => ''];
    $caption = trim($caption);
    if (ff_mb_strlen($caption) > 2200) {
        $caption = ff_mb_substr($caption, 0, 2200);
    }
    $imageUrls = ff_ig_normalize_image_url_list($imageUrls, $pbUserIdForGarage);
    if ($imageUrls === []) {
        $out['error'] = 'No public HTTPS image URLs for Instagram. Configure GARAGE_PUBLIC_URL / GARAGE_PUBLIC_ROOT_DOMAIN or use direct https:// image links.';

        return $out;
    }
    $jpegTok = $pbTokenForJpegNormalize !== null ? trim((string) $pbTokenForJpegNormalize) : '';
    if ($jpegTok !== '' && ff_ig_hmac_secret() !== '') {
        $prep = ff_ig_publish_prepare_urls_jpeg_for_meta($imageUrls, $jpegTok);
        if (!$prep['ok']) {
            $out['error'] = $prep['error'] !== '' ? $prep['error'] : 'Could not prepare JPEG URLs for Instagram.';

            return $out;
        }
        $imageUrls = $prep['urls'];
    }
    if (count($imageUrls) > 10) {
        $out['error'] = 'Instagram allows at most 10 images per post.';

        return $out;
    }

    if (count($imageUrls) === 1) {
        $r = ff_ig_graph_request('POST', $igUserId . '/media', $accessToken, [
            'image_url' => $imageUrls[0],
            'caption' => $caption,
        ]);
        if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 300) {
            $out['error'] = (string) ($r['body']['error']['message'] ?? $r['raw'] ?? 'Graph API error');

            return $out;
        }
        $cid = (string) ($r['body']['id'] ?? '');
        if ($cid === '') {
            $out['error'] = 'Graph API returned no media id';

            return $out;
        }
        if (!ff_ig_wait_media_ready($cid, $accessToken)) {
            $out['error'] = 'Image did not finish processing in time.';

            return $out;
        }
        $pub = ff_ig_graph_request('POST', $igUserId . '/media_publish', $accessToken, ['creation_id' => $cid]);
        if (($pub['code'] ?? 0) < 200 || ($pub['code'] ?? 0) >= 300) {
            $out['error'] = (string) ($pub['body']['error']['message'] ?? $pub['raw'] ?? 'Publish failed');

            return $out;
        }
        $out['ok'] = true;
        $out['media_id'] = (string) ($pub['body']['id'] ?? '');

        return $out;
    }

    $childIds = [];
    foreach ($imageUrls as $imgUrl) {
        $r = ff_ig_graph_request('POST', $igUserId . '/media', $accessToken, [
            'image_url' => $imgUrl,
            'is_carousel_item' => 'true',
        ]);
        if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 300) {
            $out['error'] = (string) ($r['body']['error']['message'] ?? $r['raw'] ?? 'Graph API error (carousel item)');

            return $out;
        }
        $cid = (string) ($r['body']['id'] ?? '');
        if ($cid === '') {
            $out['error'] = 'Graph API returned no child media id';

            return $out;
        }
        if (!ff_ig_wait_media_ready($cid, $accessToken)) {
            $out['error'] = 'A carousel image did not finish processing in time.';

            return $out;
        }
        $childIds[] = $cid;
    }

    $r = ff_ig_graph_request('POST', $igUserId . '/media', $accessToken, [
        'media_type' => 'CAROUSEL',
        'children' => implode(',', $childIds),
        'caption' => $caption,
    ]);
    if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 300) {
        $out['error'] = (string) ($r['body']['error']['message'] ?? $r['raw'] ?? 'Graph API error (carousel container)');

        return $out;
    }
    $carouselId = (string) ($r['body']['id'] ?? '');
    if ($carouselId === '') {
        $out['error'] = 'Graph API returned no carousel id';

        return $out;
    }
    if (!ff_ig_wait_media_ready($carouselId, $accessToken)) {
        $out['error'] = 'Carousel container did not finish processing in time.';

        return $out;
    }
    $pub = ff_ig_graph_request('POST', $igUserId . '/media_publish', $accessToken, ['creation_id' => $carouselId]);
    if (($pub['code'] ?? 0) < 200 || ($pub['code'] ?? 0) >= 300) {
        $out['error'] = (string) ($pub['body']['error']['message'] ?? $pub['raw'] ?? 'Publish failed');

        return $out;
    }
    $out['ok'] = true;
    $out['media_id'] = (string) ($pub['body']['id'] ?? '');

    return $out;
}

function ff_app_script_path(): string
{
    return $_SERVER['SCRIPT_NAME'] ?? '/index.php';
}

/** @return 'image'|'video'|'audio'|'file' */
function ff_media_kind_from_filename(string $name): string
{
    $ext = strtolower(pathinfo(str_replace('\\', '/', $name), PATHINFO_EXTENSION));
    $img = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'bmp', 'ico', 'heic', 'heif'];
    $vid = ['mp4', 'webm', 'mov', 'mkv', 'm4v', 'ogv', 'avi'];
    $aud = ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'opus', 'wma', 'aiff', 'aif'];

    if (in_array($ext, $img, true)) {
        return 'image';
    }
    if (in_array($ext, $vid, true)) {
        return 'video';
    }
    if (in_array($ext, $aud, true)) {
        return 'audio';
    }

    return 'file';
}

/**
 * @return list<string>
 */
function ff_pb_normalize_file_field($v): array
{
    if ($v === null || $v === '') {
        return [];
    }
    if (is_string($v)) {
        $decoded = json_decode($v, true);
        if (is_array($decoded)) {
            $v = $decoded;
        } else {
            return [$v];
        }
    }
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $x) {
        if (is_string($x) && $x !== '') {
            $out[] = $x;
        }
    }

    return $out;
}

/**
 * Filenames attached to a PocketBase record (fetched_files, media_file, …).
 *
 * @param array<string, mixed> $rec
 * @return list<string>
 */
function ff_pb_record_file_manifest(array $rec): array
{
    $names = [];
    foreach (['fetched_files', 'media_file'] as $f) {
        if (isset($rec[$f])) {
            $names = array_merge($names, ff_pb_normalize_file_field($rec[$f]));
        }
    }

    return array_values(array_unique(array_filter($names)));
}

/**
 * @param array<string, mixed> $rec
 */
function ff_pb_record_has_filename(array $rec, string $want): bool
{
    $wantBase = basename(str_replace('\\', '/', $want));
    foreach (ff_pb_record_file_manifest($rec) as $n) {
        if (basename(str_replace('\\', '/', $n)) === $wantBase) {
            return true;
        }
    }

    return false;
}

function ff_pb_files_base_url(): string
{
    $cfg = $GLOBALS['CONFIG'];
    $pub = rtrim((string) ($cfg['pocketbase_public_url'] ?? ''), '/');
    if ($pub !== '') {
        return $pub;
    }

    return rtrim((string) ($cfg['pocketbase_url'] ?? ''), '/');
}

function ff_pb_build_files_token_url(string $collectionName, string $recordId, string $filename, string $authHeader): string
{
    $base = ff_pb_files_base_url();
    if ($base === '') {
        return '';
    }
    $t = preg_replace('/^\s*Bearer\s+/i', '', trim($authHeader));
    if ($t === '') {
        return '';
    }
    $fn = basename(str_replace('\\', '/', $filename));
    if ($fn === '' || str_contains($fn, '..')) {
        return '';
    }
    $path = '/api/files/' . rawurlencode($collectionName) . '/' . rawurlencode($recordId) . '/' . rawurlencode($fn);

    return $base . $path . '?token=' . rawurlencode($t);
}

/**
 * Preview entries for one PocketBase record (file fields + thumbnail + garage URL).
 *
 * @param array<string, mixed> $rec
 * @return list<array{kind: string, label: string, url: string, external: bool}>
 */
function ff_media_entries_from_pb_record(array $rec, string $collectionName, string $script): array
{
    $entries = [];
    $rid = trim((string) ($rec['id'] ?? ''));
    if ($rid === '') {
        return [];
    }
    foreach (ff_pb_record_file_manifest($rec) as $fname) {
        $fname = (string) $fname;
        $entries[] = [
            'kind' => ff_media_kind_from_filename($fname),
            'label' => $fname,
            'url' => $script . '?action=media_file&collection=' . rawurlencode($collectionName)
                . '&record_id=' . rawurlencode($rid) . '&filename=' . rawurlencode($fname),
            'external' => false,
        ];
    }
    $tu = trim((string) ($rec['thumbnail_url'] ?? ''));
    if ($tu !== '' && (str_starts_with($tu, 'http://') || str_starts_with($tu, 'https://'))) {
        $entries[] = [
            'kind' => 'image',
            'label' => 'thumbnail',
            'url' => $tu,
            'external' => true,
        ];
    }
    $gu = trim((string) ($rec['garage_url'] ?? ''));
    if ($gu !== '' && (str_starts_with($gu, 'http://') || str_starts_with($gu, 'https://'))) {
        $path = (string) (parse_url($gu, PHP_URL_PATH) ?: '');
        $gk = ff_media_kind_from_filename($path !== '' ? $path : $gu);
        if ($gk === 'file') {
            $gk = 'video';
        }
        $entries[] = [
            'kind' => $gk,
            'label' => 'garage_url',
            'url' => $gu,
            'external' => true,
        ];
    }

    return $entries;
}

/**
 * Path-style canonical URI: /bucket or /bucket/key/with/slashes (each segment URI-encoded).
 */
function ff_garage_s3_uri_path(string $bucket, string $objectKey): string
{
    $segs = [$bucket];
    if ($objectKey !== '') {
        foreach (explode('/', $objectKey) as $p) {
            if ($p !== '') {
                $segs[] = rawurlencode($p);
            }
        }
    }

    return '/' . implode('/', $segs);
}

/**
 * @param array<string, string> $query
 */
function ff_garage_s3_canonical_query(array $query): string
{
    if ($query === []) {
        return '';
    }
    ksort($query, SORT_STRING);
    $pairs = [];
    foreach ($query as $k => $v) {
        $pairs[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
    }

    return implode('&', $pairs);
}

function ff_http_block_content_type(string $rawHeaders): string
{
    foreach (preg_split("/\r\n|\n|\r/", $rawHeaders) as $line) {
        if (stripos($line, 'Content-Type:') === 0) {
            return trim(substr($line, strlen('Content-Type:')));
        }
    }

    return 'application/octet-stream';
}

/**
 * @return array{ok: bool, code: int, body: string, error: string, content_type: string}
 */
function ff_garage_s3_request(string $method, string $objectKey, array $query, string $body): array
{
    $cfg = $GLOBALS['CONFIG'];
    $endpoint = rtrim((string) ($cfg['garage_endpoint'] ?? ''), '/');
    $bucket = (string) ($cfg['garage_social_content_bucket'] ?? '');
    $region = (string) ($cfg['garage_region'] ?? 'garage');
    $accessKey = (string) ($cfg['garage_access_key'] ?? '');
    $secretKey = (string) ($cfg['garage_secret_key'] ?? '');
    $pu = parse_url($endpoint);
    if (!is_array($pu) || empty($pu['host'])) {
        return ['ok' => false, 'code' => 0, 'body' => '', 'error' => 'Invalid GARAGE_ENDPOINT', 'content_type' => ''];
    }
    $scheme = $pu['scheme'] ?? 'http';
    $port = isset($pu['port']) ? (int) $pu['port'] : ($scheme === 'https' ? 443 : 80);
    $hostHeader = (string) $pu['host'];
    if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $hostHeader .= ':' . $port;
    }
    $canonicalUri = ff_garage_s3_uri_path($bucket, $objectKey);
    $canonicalQuery = ff_garage_s3_canonical_query($query);
    $payloadHash = hash('sha256', $body);
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $canonicalHeaders = 'host:' . $hostHeader . "\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n"
        . 'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = strtoupper($method) . "\n"
        . $canonicalUri . "\n"
        . $canonicalQuery . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = $algorithm . "\n"
        . $amzDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authHeader = $algorithm . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    $baseUrl = $scheme . '://' . (string) $pu['host'];
    if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $baseUrl .= ':' . $port;
    }
    $url = $baseUrl . $canonicalUri . ($canonicalQuery !== '' ? '?' . $canonicalQuery : '');
    $reqHeaders = [
        'Host: ' . $hostHeader,
        'X-Amz-Date: ' . $amzDate,
        'X-Amz-Content-Sha256: ' . $payloadHash,
        'Authorization: ' . $authHeader,
    ];
    $splitObjectHeaders = strtoupper($method) === 'GET' && $objectKey !== '' && $query === [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $reqHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => $splitObjectHeaders,
    ]);
    if ($body !== '' || strtoupper($method) === 'PUT') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $raw = curl_exec($ch);
    $outCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = $splitObjectHeaders ? (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE) : 0;
    curl_close($ch);
    $outBody = is_string($raw) ? $raw : '';
    $outCt = 'application/octet-stream';
    if ($splitObjectHeaders && $outBody !== '' && $headerSize > 0 && $headerSize < strlen($outBody)) {
        $head = substr($outBody, 0, $headerSize);
        $outBody = substr($outBody, $headerSize);
        $outCt = ff_http_block_content_type($head);
    }

    return ['ok' => $outCode >= 200 && $outCode < 300, 'code' => $outCode, 'body' => $outBody, 'error' => '', 'content_type' => $outCt];
}

function ff_s3_xml_text_decode(string $s): string
{
    return html_entity_decode(trim($s), ENT_XML1 | ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * S3 error body (Code / Message) without ext-simplexml.
 */
function ff_garage_s3_xml_error(string $xml): string
{
    if ($xml === '' || !str_contains($xml, '<')) {
        return '';
    }
    if (function_exists('simplexml_load_string')) {
        $x = @simplexml_load_string($xml);
        if ($x !== false) {
            $code = (string) ($x->Code ?? '');
            $msg = (string) ($x->Message ?? '');
            $err = trim($code . ($msg !== '' ? ': ' . $msg : ''));
            if ($err !== '') {
                return $err;
            }
        }
    }
    $code = '';
    $msg = '';
    if (preg_match('/<(?:[a-z0-9]+:)?Code>([^<]*)<\/(?:[a-z0-9]+:)?Code>/i', $xml, $c)) {
        $code = ff_s3_xml_text_decode($c[1]);
    }
    if (preg_match('/<(?:[a-z0-9]+:)?Message>([^<]*)<\/(?:[a-z0-9]+:)?Message>/is', $xml, $m)) {
        $msg = ff_s3_xml_text_decode($m[1]);
    }

    return trim($code . ($msg !== '' ? ': ' . $msg : ''));
}

/**
 * Regex parse for S3 ListBucket (ListBucketResult) when SimpleXML is unavailable.
 *
 * @return list<array{key: string, size: int, last_modified: string}>|null null = not a valid list response
 */
function ff_garage_parse_list_bucket_xml_regex(string $body): ?array
{
    if ($body === '' || !str_contains($body, '<')) {
        return null;
    }
    if (!preg_match('/<(?:[a-z0-9]+:)?ListBucketResult\b/i', $body)) {
        return null;
    }
    if (!preg_match_all('/<(?:[a-z0-9]+:)?Contents>([\s\S]*?)<\/(?:[a-z0-9]+:)?Contents>/i', $body, $blocks, PREG_SET_ORDER)) {
        return [];
    }
    $items = [];
    foreach ($blocks as $b) {
        $block = $b[1] ?? '';
        if (!preg_match('/<(?:[a-z0-9]+:)?Key>([^<]*)<\/(?:[a-z0-9]+:)?Key>/i', $block, $mk)) {
            continue;
        }
        $key = ff_s3_xml_text_decode($mk[1]);
        if ($key === '') {
            continue;
        }
        $size = 0;
        if (preg_match('/<(?:[a-z0-9]+:)?Size>([^<]*)<\/(?:[a-z0-9]+:)?Size>/i', $block, $ms)) {
            $size = (int) trim($ms[1]);
        }
        $lastMod = '';
        if (preg_match('/<(?:[a-z0-9]+:)?LastModified>([^<]*)<\/(?:[a-z0-9]+:)?LastModified>/i', $block, $ml)) {
            $lastMod = ff_s3_xml_text_decode($ml[1]);
        }
        $items[] = [
            'key' => $key,
            'size' => $size,
            'last_modified' => $lastMod,
        ];
    }

    return $items;
}

/**
 * @return list<array{key: string, size: int, last_modified: string}>|null
 */
function ff_garage_parse_list_bucket_xml(string $body): ?array
{
    if (function_exists('simplexml_load_string')) {
        $xml = @simplexml_load_string($body);
        if ($xml !== false) {
            $items = [];
            if (isset($xml->Contents)) {
                foreach ($xml->Contents as $c) {
                    $key = (string) ($c->Key ?? '');
                    if ($key === '') {
                        continue;
                    }
                    $items[] = [
                        'key' => $key,
                        'size' => (int) ($c->Size ?? 0),
                        'last_modified' => (string) ($c->LastModified ?? ''),
                    ];
                }
            }

            return $items;
        }
    }

    return ff_garage_parse_list_bucket_xml_regex($body);
}

/**
 * @return array{ok: bool, items: list<array{key: string, rel: string, size: int, last_modified: string}>, error: string}
 */
function ff_garage_list_under_prefix(string $prefix, int $maxKeys): array
{
    $q = [
        'list-type' => '2',
        'prefix' => $prefix,
        'max-keys' => (string) max(1, min(1000, $maxKeys)),
    ];
    $r = ff_garage_s3_request('GET', '', $q, '');
    if (!$r['ok']) {
        $err = ff_garage_s3_xml_error($r['body']) ?: ('HTTP ' . $r['code']);

        return ['ok' => false, 'items' => [], 'error' => $err];
    }
    $parsed = ff_garage_parse_list_bucket_xml($r['body']);
    if ($parsed === null) {
        return ['ok' => false, 'items' => [], 'error' => 'Invalid S3 list response'];
    }
    $items = [];
    foreach ($parsed as $row) {
        $key = $row['key'];
        if ($key === '' || str_ends_with($key, '/')) {
            continue;
        }
        $rel = $key;
        if (str_starts_with($rel, $prefix)) {
            $rel = substr($rel, strlen($prefix));
        }
        $items[] = [
            'key' => $key,
            'rel' => $rel,
            'size' => $row['size'],
            'last_modified' => $row['last_modified'],
            'kind' => ff_media_kind_from_filename($rel),
        ];
    }

    return ['ok' => true, 'items' => $items, 'error' => ''];
}

/**
 * @return array{ok: bool, error: string}
 */
function ff_garage_delete_object(string $objectKey): array
{
    $r = ff_garage_s3_request('DELETE', $objectKey, [], '');
    if ($r['ok'] || $r['code'] === 404) {
        return ['ok' => true, 'error' => ''];
    }

    return ['ok' => false, 'error' => ff_garage_s3_xml_error($r['body']) ?: ('HTTP ' . $r['code'])];
}

/**
 * @return array{ok: bool, error: string}
 */
function ff_garage_put_object(string $objectKey, string $bytes, string $contentType): array
{
    $cfg = $GLOBALS['CONFIG'];
    $endpoint = rtrim((string) ($cfg['garage_endpoint'] ?? ''), '/');
    $bucket = (string) ($cfg['garage_social_content_bucket'] ?? '');
    $region = (string) ($cfg['garage_region'] ?? 'garage');
    $accessKey = (string) ($cfg['garage_access_key'] ?? '');
    $secretKey = (string) ($cfg['garage_secret_key'] ?? '');
    $pu = parse_url($endpoint);
    if (!is_array($pu) || empty($pu['host'])) {
        return ['ok' => false, 'error' => 'Invalid GARAGE_ENDPOINT'];
    }
    $scheme = $pu['scheme'] ?? 'http';
    $port = isset($pu['port']) ? (int) $pu['port'] : ($scheme === 'https' ? 443 : 80);
    $hostHeader = (string) $pu['host'];
    if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $hostHeader .= ':' . $port;
    }
    $canonicalUri = ff_garage_s3_uri_path($bucket, $objectKey);
    $payloadHash = hash('sha256', $bytes);
    $amzDate = gmdate('Ymd\THis\Z');
    $dateStamp = gmdate('Ymd');
    $canonicalHeaders = 'content-type:' . $contentType . "\n"
        . 'host:' . $hostHeader . "\n"
        . 'x-amz-content-sha256:' . $payloadHash . "\n"
        . 'x-amz-date:' . $amzDate . "\n";
    $signedHeaders = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = "PUT\n"
        . $canonicalUri . "\n"
        . "\n"
        . $canonicalHeaders . "\n"
        . $signedHeaders . "\n"
        . $payloadHash;
    $algorithm = 'AWS4-HMAC-SHA256';
    $credentialScope = $dateStamp . '/' . $region . '/s3/aws4_request';
    $stringToSign = $algorithm . "\n"
        . $amzDate . "\n"
        . $credentialScope . "\n"
        . hash('sha256', $canonicalRequest);
    $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $secretKey, true);
    $kRegion = hash_hmac('sha256', $region, $kDate, true);
    $kService = hash_hmac('sha256', 's3', $kRegion, true);
    $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
    $signature = hash_hmac('sha256', $stringToSign, $kSigning);
    $authHeader = $algorithm . ' Credential=' . $accessKey . '/' . $credentialScope
        . ', SignedHeaders=' . $signedHeaders . ', Signature=' . $signature;
    $baseUrl = $scheme . '://' . (string) $pu['host'];
    if (!(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80))) {
        $baseUrl .= ':' . $port;
    }
    $url = $baseUrl . $canonicalUri;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'PUT',
        CURLOPT_POSTFIELDS => $bytes,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Host: ' . $hostHeader,
            'Content-Type: ' . $contentType,
            'X-Amz-Date: ' . $amzDate,
            'X-Amz-Content-Sha256: ' . $payloadHash,
            'Authorization: ' . $authHeader,
        ],
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        return ['ok' => true, 'error' => ''];
    }

    return ['ok' => false, 'error' => ff_garage_s3_xml_error(is_string($raw) ? $raw : '') ?: ('HTTP ' . $code)];
}

/**
 * @return array<string, mixed>
 */
function ff_debug_collect_safe(): array
{
    $cfg = $GLOBALS['CONFIG'];
    $pbHealth = pb_request('GET', '/api/health', null, null);

    return [
        'generated_at' => gmdate('c'),
        'app' => [
            'version' => (string) ($cfg['app_version'] ?? ''),
            'php_version' => PHP_VERSION,
            'php_sapi' => PHP_SAPI,
            'os' => PHP_OS_FAMILY,
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
        'ai_carousel' => [
            'slide_generator' => 'openrouter_only',
            'openrouter_configured' => trim((string) (getenv('OPENROUTER_API_KEY') ?: '')) !== '',
            'gemini' => trim((string) (getenv('GEMINI_API_KEY') ?: '')) !== '',
            'openai' => trim((string) (getenv('OPENAI_API_KEY') ?: '')) !== '',
        ],
        'gemini_embed' => [
            'api_key_set' => trim((string) (getenv('GEMINI_API_KEY') ?: '')) !== '',
            'model' => trim((string) (getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview')),
        ],
        'instagram_facebook' => [
            'app_configured' => trim((string) ($cfg['fb_app_id'] ?? '')) !== '' && trim((string) ($cfg['fb_app_secret'] ?? '')) !== '',
        ],
        'replicate' => [
            'token_set' => trim((string) (getenv('REPLICATE_API_TOKEN') ?: '')) !== '',
        ],
        'garage' => [
            'endpoint_configured' => trim((string) ($cfg['garage_endpoint'] ?? '')) !== '',
            'credentials_configured' => trim((string) ($cfg['garage_access_key'] ?? '')) !== ''
                && trim((string) ($cfg['garage_secret_key'] ?? '')) !== '',
            'social_content_bucket' => (string) ($cfg['garage_social_content_bucket'] ?? ''),
            'object_key_prefix' => 'social_accounts/{pocketbase_social_account_id}/',
            'generated_save_enabled' => ff_should_save_generated_to_garage(),
            'generated_key_prefix' => 'generated/users/{pocketbase_users_record_id}/slides|images/',
            's3_operations_ready' => ff_garage_ready(),
        ],
        'collections' => [
            'input_media' => (string) ($cfg['input_media_collection'] ?? 'input_media'),
            'prompts' => (string) ($cfg['prompts_collection'] ?? 'prompts'),
        ],
    ];
}

if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "Carousel app: use the web UI.\n");
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
            $rec = is_array($auth['body']['record'] ?? null) ? $auth['body']['record'] : [];
            $em = $rec['email'] ?? '';
            if (!ff_gate_email_allowed(is_string($em) ? $em : '')) {
                ff_redirect_url('/?login_error=2');
            }
            $_SESSION['pb_token'] = $auth['body']['token'];
            $_SESSION['pb_user'] = $rec;
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
$pbTok = $_SESSION['pb_token'] ?? null;
$token = is_string($pbTok) && trim($pbTok) !== '' ? $pbTok : null;
$authHeader = $token;

if ($user !== null && is_array($user) && !ff_gate_email_allowed($user['email'] ?? null)) {
    unset($_SESSION['pb_user'], $_SESSION['pb_token']);
    $user = null;
    $token = null;
    $authHeader = null;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['ff_debug_json'])) {
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Sign in required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(ff_debug_collect_safe(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/** JSON API: Gemini text embedding (requires sign-in). POST ?action=embed_text */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'embed_text') {
    header('Content-Type: application/json; charset=utf-8');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    $gemKey = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
    if ($gemKey === '') {
        http_response_code(503);
        echo json_encode(['error' => 'GEMINI_API_KEY not set']);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    $text = is_array($data) && isset($data['text']) ? trim((string) $data['text']) : '';
    if ($text === '') {
        http_response_code(400);
        echo json_encode(['error' => 'JSON body must include non-empty "text"']);
        exit;
    }
    $embModel = trim((string) (getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview'));
    if ($embModel === '') {
        $embModel = 'gemini-embedding-2-preview';
    }
    $emb = ff_gemini_embed_text($gemKey, $embModel, $text);
    if (!$emb['ok']) {
        http_response_code(502);
        echo json_encode(['error' => $emb['error']]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'model' => $embModel,
        'dims' => count($emb['vector']),
        'preview' => ff_embedding_preview_string($emb['vector']),
        'values' => $emb['vector'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON API: Gemini image embedding from base64 (requires sign-in). POST ?action=embed_image */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'embed_image') {
    header('Content-Type: application/json; charset=utf-8');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    $gemKey = trim((string) (getenv('GEMINI_API_KEY') ?: ''));
    if ($gemKey === '') {
        http_response_code(503);
        echo json_encode(['error' => 'GEMINI_API_KEY not set']);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['data'], $data['mime'])) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON must include "mime" and base64 "data"']);
        exit;
    }
    $mime = strtolower(str_replace('image/jpg', 'image/jpeg', trim((string) $data['mime'])));
    $allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    if (!in_array($mime, $allowed, true)) {
        http_response_code(400);
        echo json_encode(['error' => 'Unsupported mime type']);
        exit;
    }
    $b64 = preg_replace('/\s+/', '', (string) $data['data']);
    $bin = base64_decode($b64, true);
    if ($bin === false || $bin === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid base64 data']);
        exit;
    }
    if (strlen($bin) > 4 * 1024 * 1024) {
        http_response_code(400);
        echo json_encode(['error' => 'Image too large']);
        exit;
    }
    $embModel = trim((string) (getenv('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2-preview'));
    if ($embModel === '') {
        $embModel = 'gemini-embedding-2-preview';
    }
    $emb = ff_gemini_embed_image_b64($gemKey, $embModel, $mime, base64_encode($bin));
    if (!$emb['ok']) {
        http_response_code(502);
        echo json_encode(['error' => $emb['error']]);
        exit;
    }
    echo json_encode([
        'ok' => true,
        'model' => $embModel,
        'dims' => count($emb['vector']),
        'preview' => ff_embedding_preview_string($emb['vector']),
        'values' => $emb['vector'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON API: create prompts row + optional embedding (requires sign-in). POST ?action=prompt_embed */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'prompt_embed') {
    header('Content-Type: application/json; charset=utf-8');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    $promptText = is_array($data) && isset($data['prompt_text']) ? trim((string) $data['prompt_text']) : '';
    $inputMediaId = is_array($data) && isset($data['input_media_id']) ? trim((string) $data['input_media_id']) : '';
    if ($promptText === '' || $inputMediaId === '') {
        http_response_code(400);
        echo json_encode(['error' => 'JSON must include prompt_text and input_media_id']);
        exit;
    }
    $cfg = $GLOBALS['CONFIG'];
    $pr = ff_pb_create_prompt_after_fetch(
        (string) $authHeader,
        (string) ($cfg['prompts_collection'] ?? 'prompts'),
        (string) ($cfg['input_media_collection'] ?? 'input_media'),
        $inputMediaId,
        $promptText
    );
    if (!empty($pr['skipped'])) {
        http_response_code(503);
        echo json_encode(['error' => $pr['skip_reason'] ?: 'Skipped', 'detail' => $pr]);
        exit;
    }
    if ($pr['record_id'] === '') {
        http_response_code(502);
        echo json_encode(['error' => $pr['pb_error'] ?: $pr['gemini_error'] ?: 'Failed', 'detail' => $pr]);
        exit;
    }
    echo json_encode(['ok' => true, 'detail' => $pr], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Garage S3: list objects for one social_accounts row. GET ?action=garage_list&social_account_id=… */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'garage_list') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    if (!ff_garage_ready()) {
        http_response_code(503);
        echo json_encode(['error' => 'Garage S3 not configured (GARAGE_ENDPOINT, keys, bucket).']);
        exit;
    }
    $sid = trim((string) ($_GET['social_account_id'] ?? ''));
    if ($sid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing social_account_id']);
        exit;
    }
    if (ff_pb_owned_social_account($authHeader, $sid) === null) {
        http_response_code(403);
        echo json_encode(['error' => 'Unknown or inaccessible social account.']);
        exit;
    }
    $prefix = ff_garage_social_key_prefix($sid);
    $list = ff_garage_list_under_prefix($prefix, 200);
    if (!$list['ok']) {
        http_response_code(502);
        echo json_encode(['error' => $list['error']]);
        exit;
    }
    $script = ff_app_script_path();
    $itemsOut = [];
    foreach ($list['items'] as $it) {
        if (!is_array($it)) {
            continue;
        }
        $rel = (string) ($it['rel'] ?? '');
        $fullKeyG = ff_garage_social_object_key($sid, $rel);
        $pubG = $fullKeyG !== '' ? ff_garage_public_https_url_for_object_key($fullKeyG) : '';
        $itemsOut[] = array_merge($it, [
            'preview_url' => $script . '?action=garage_download&inline=1&social_account_id=' . rawurlencode($sid) . '&key=' . rawurlencode($rel),
            'download_url' => $script . '?action=garage_download&social_account_id=' . rawurlencode($sid) . '&key=' . rawurlencode($rel),
            'public_url' => $pubG,
        ]);
    }
    echo json_encode(['ok' => true, 'prefix' => $prefix, 'items' => $itemsOut], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Garage S3: download object (scoped). GET ?action=garage_download&social_account_id=…&key=relative/path */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'garage_download') {
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    if (!ff_garage_ready()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Garage not configured';
        exit;
    }
    $sid = trim((string) ($_GET['social_account_id'] ?? ''));
    $rel = trim((string) ($_GET['key'] ?? ''));
    if ($sid === '' || $rel === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        exit;
    }
    if (ff_pb_owned_social_account($authHeader, $sid) === null) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    $fullKey = ff_garage_social_object_key($sid, $rel);
    $pref = ff_garage_social_key_prefix($sid);
    if ($fullKey === '' || $pref === '' || !str_starts_with($fullKey, $pref)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad key';
        exit;
    }
    $r = ff_garage_s3_request('GET', $fullKey, [], '');
    if (!$r['ok']) {
        http_response_code($r['code'] >= 400 ? $r['code'] : 502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ff_garage_s3_xml_error($r['body']) ?: ('HTTP ' . $r['code'])]);
        exit;
    }
    $maxBytes = 40 * 1024 * 1024;
    if (strlen($r['body']) > $maxBytes) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Object too large for download (max 40 MiB).']);
        exit;
    }
    $fn = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename(str_replace('\\', '/', $rel))) ?: 'file';
    $inline = (string) ($_GET['inline'] ?? '') === '1';
    header('Content-Type: ' . ($r['content_type'] !== '' ? $r['content_type'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fn . '"');
    header('X-Content-Type-Options: nosniff');
    if ($inline) {
        header('Cache-Control: private, max-age=300');
    }
    echo $r['body'];
    exit;
}

/** Garage S3: download object created by this app under generated/users/{your_pb_user_id}/… */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'garage_generated_download') {
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    if (!ff_garage_ready()) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Garage not configured';
        exit;
    }
    $uid = ff_session_pb_user_id();
    if ($uid === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        exit;
    }
    $rel = trim((string) ($_GET['key'] ?? ''));
    if ($rel === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        exit;
    }
    $fullKey = ff_garage_generated_object_key($uid, $rel);
    $pref = ff_garage_generated_user_prefix($uid);
    if ($fullKey === '' || $pref === '' || !str_starts_with($fullKey, $pref)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad key';
        exit;
    }
    $r = ff_garage_s3_request('GET', $fullKey, [], '');
    if (!$r['ok']) {
        http_response_code($r['code'] >= 400 ? $r['code'] : 502);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => ff_garage_s3_xml_error($r['body']) ?: ('HTTP ' . $r['code'])]);
        exit;
    }
    $maxBytes = 40 * 1024 * 1024;
    if (strlen($r['body']) > $maxBytes) {
        http_response_code(413);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Object too large for download (max 40 MiB).']);
        exit;
    }
    $fn = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename(str_replace('\\', '/', $rel))) ?: 'file';
    $inline = (string) ($_GET['inline'] ?? '') === '1';
    header('Content-Type: ' . ($r['content_type'] !== '' ? $r['content_type'] : 'application/octet-stream'));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fn . '"');
    header('X-Content-Type-Options: nosniff');
    if ($inline) {
        header('Cache-Control: private, max-age=300');
    }
    echo $r['body'];
    exit;
}

/** Garage S3: upload file for one social_accounts row. POST multipart ?action=garage_upload (social_account_id, file) */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'garage_upload') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    if (!ff_garage_ready()) {
        http_response_code(503);
        echo json_encode(['error' => 'Garage S3 not configured.']);
        exit;
    }
    $sid = trim((string) ($_POST['social_account_id'] ?? ''));
    if ($sid === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Missing social_account_id']);
        exit;
    }
    if (ff_pb_owned_social_account($authHeader, $sid) === null) {
        http_response_code(403);
        echo json_encode(['error' => 'Unknown or inaccessible social account.']);
        exit;
    }
    if (empty($_FILES['file']['tmp_name']) || !is_uploaded_file((string) $_FILES['file']['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing file upload (field name: file)']);
        exit;
    }
    $tmp = (string) $_FILES['file']['tmp_name'];
    $orig = isset($_FILES['file']['name']) ? (string) $_FILES['file']['name'] : 'upload';
    $maxBytes = min(40 * 1024 * 1024, (int) (ff_ini_size_bytes((string) ini_get('upload_max_filesize')) ?: (40 * 1024 * 1024)));
    $sz = @filesize($tmp);
    if ($sz === false || $sz > $maxBytes) {
        http_response_code(400);
        echo json_encode(['error' => 'File too large']);
        exit;
    }
    $bytes = @file_get_contents($tmp);
    if ($bytes === false || $bytes === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Empty or unreadable file']);
        exit;
    }
    $rel = basename(str_replace('\\', '/', $orig));
    $rel = preg_replace('/[^a-zA-Z0-9._-]/', '_', $rel);
    if ($rel === '' || $rel === '.' || $rel === '..') {
        $rel = 'upload.bin';
    }
    $objectKey = ff_garage_social_object_key($sid, $rel);
    if ($objectKey === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid object path']);
        exit;
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($tmp);
    $ct = is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream';
    $put = ff_garage_put_object($objectKey, $bytes, $ct);
    if (!$put['ok']) {
        http_response_code(502);
        echo json_encode(['error' => $put['error']]);
        exit;
    }
    $pref = ff_garage_social_key_prefix($sid);
    $relOut = $pref !== '' && str_starts_with($objectKey, $pref) ? substr($objectKey, strlen($pref)) : $objectKey;
    echo json_encode([
        'ok' => true,
        'key' => $objectKey,
        'rel' => $relOut,
        'size' => strlen($bytes),
        'content_type' => $ct,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Garage S3: delete object. POST ?action=garage_delete JSON { social_account_id, key } — key is relative to the account prefix */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'garage_delete') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    if (!ff_garage_ready()) {
        http_response_code(503);
        echo json_encode(['error' => 'Garage S3 not configured.']);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    $sid = is_array($data) ? trim((string) ($data['social_account_id'] ?? '')) : '';
    $rel = is_array($data) ? trim((string) ($data['key'] ?? '')) : '';
    if ($sid === '' || $rel === '') {
        http_response_code(400);
        echo json_encode(['error' => 'JSON must include social_account_id and key (relative path)']);
        exit;
    }
    if (ff_pb_owned_social_account($authHeader, $sid) === null) {
        http_response_code(403);
        echo json_encode(['error' => 'Unknown or inaccessible social account.']);
        exit;
    }
    $fullKey = ff_garage_social_object_key($sid, $rel);
    $pref = ff_garage_social_key_prefix($sid);
    if ($fullKey === '' || $pref === '' || !str_starts_with($fullKey, $pref)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid key']);
        exit;
    }
    $del = ff_garage_delete_object($fullKey);
    if (!$del['ok']) {
        http_response_code(502);
        echo json_encode(['error' => $del['error']]);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Redirect to PocketBase file URL with auth token (for <img>/<video>/<audio> previews). GET ?action=media_file */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'media_file') {
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    $cfg = $GLOBALS['CONFIG'];
    $colWant = trim((string) ($_GET['collection'] ?? ''));
    $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
    $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
    if ($colWant === '' || ($colWant !== $inpCol && $colWant !== $outCol)) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad collection';
        exit;
    }
    $rid = trim((string) ($_GET['record_id'] ?? ''));
    $fn = basename(str_replace('\\', '/', (string) ($_GET['filename'] ?? '')));
    if ($rid === '' || $fn === '' || str_contains($fn, '..')) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        exit;
    }
    $recR = pb_request('GET', '/api/collections/' . rawurlencode($colWant) . '/records/' . rawurlencode($rid), null, $authHeader);
    if (($recR['code'] ?? 0) !== 200) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not found';
        exit;
    }
    $rec = ff_pb_normalize_api_record($recR['body']);
    if (!is_array($rec) || !ff_pb_record_has_filename($rec, $fn)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    $tok = (string) ($_SESSION['pb_token'] ?? '');
    $url = ff_pb_build_files_token_url($colWant, $rid, $fn, $tok);
    if ($url === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'PocketBase file URL not available';
        exit;
    }
    header('Referrer-Policy: no-referrer');
    header('Location: ' . $url, true, 302);
    exit;
}

/**
 * Public HTTPS image for Instagram (signed URL, no session). GET ?action=ig_public_image&c=input_media&id=…&f=…&exp=…&sig=…
 * Streams bytes from PocketBase using FF_CRON_PB_TOKEN (must be able to read the file).
 */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'ig_public_image') {
    $cfg = $GLOBALS['CONFIG'];
    $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
    $c = trim((string) ($_GET['c'] ?? ''));
    $rid = trim((string) ($_GET['id'] ?? ''));
    $fn = basename(str_replace('\\', '/', (string) ($_GET['f'] ?? '')));
    $exp = (int) ($_GET['exp'] ?? 0);
    $sig = trim((string) ($_GET['sig'] ?? ''));
    if ($c !== $inpCol || $rid === '' || $fn === '' || str_contains($fn, '..') || $exp < 1 || $sig === '') {
        http_response_code(400);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Bad request';
        exit;
    }
    if (time() > $exp) {
        http_response_code(410);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Expired';
        exit;
    }
    $secret = ff_ig_hmac_secret();
    if ($secret === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Signing not configured';
        exit;
    }
    $want = hash_hmac('sha256', "ig_pub|$c|$rid|$fn|$exp", $secret);
    if (!hash_equals($want, $sig)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Forbidden';
        exit;
    }
    $cronTok = trim((string) (getenv('FF_CRON_PB_TOKEN') ?: ''));
    if ($cronTok === '') {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Cron token not configured';
        exit;
    }
    $got = ff_pb_file_get_bytes($c, $rid, $fn, $cronTok);
    if (!$got['ok']) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Upstream error';
        exit;
    }
    $disp = preg_replace('/[^a-zA-Z0-9._-]/', '_', $fn) ?: 'image';
    header('Content-Type: ' . ($got['content_type'] !== '' ? $got['content_type'] : 'application/octet-stream'));
    header('Content-Disposition: inline; filename="' . $disp . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: public, max-age=3600');
    echo $got['bytes'];
    exit;
}

/** Combined PocketBase + Garage media for the Media tab. GET ?action=media_library&social_account_id=optional */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'media_library') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    try {
        $cfg = $GLOBALS['CONFIG'];
        $script = ff_app_script_path();
        $inpCol = (string) ($cfg['input_media_collection'] ?? 'input_media');
        $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
        $q = http_build_query(['sort' => '-@rowid', 'perPage' => 80]);
        $sid = trim((string) ($_GET['social_account_id'] ?? ''));
        $inputBlocks = [];
        $inputErr = '';
        $garagePayload = ['prefix' => '', 'items' => []];
        $socAcc = null;
        if (ff_garage_ready() && $sid !== '') {
            $socAcc = ff_pb_owned_social_account($authHeader, $sid);
        }
        $useGarageForInput = $socAcc !== null;
        if ($useGarageForInput) {
            try {
                $prefix = ff_garage_social_key_prefix($sid);
                if ($prefix === '') {
                    $msg = 'Invalid Garage scope for this account.';
                    $garagePayload = ['prefix' => '', 'items' => [], 'error' => $msg];
                    $inputErr = $msg;
                } else {
                    $list = ff_garage_list_under_prefix($prefix, 200);
                    if ($list['ok']) {
                        $itemsG = [];
                        $entries = [];
                        foreach ($list['items'] as $it) {
                            if (!is_array($it)) {
                                continue;
                            }
                            $rel = (string) ($it['rel'] ?? '');
                            $fullKey = ff_garage_social_object_key($sid, $rel);
                            $pubUrl = $fullKey !== '' ? ff_garage_public_https_url_for_object_key($fullKey) : '';
                            $row = array_merge($it, [
                                'preview_url' => $script . '?action=garage_download&inline=1&social_account_id=' . rawurlencode($sid) . '&key=' . rawurlencode($rel),
                                'download_url' => $script . '?action=garage_download&social_account_id=' . rawurlencode($sid) . '&key=' . rawurlencode($rel),
                                'public_url' => $pubUrl,
                            ]);
                            $itemsG[] = $row;
                            $entries[] = [
                                'kind' => (string) ($it['kind'] ?? 'file'),
                                'label' => $rel,
                                'url' => $row['preview_url'],
                                'external' => false,
                            ];
                        }
                        $garagePayload = ['prefix' => $prefix, 'items' => $itemsG];
                        if ($entries !== []) {
                            $uname = is_array($socAcc) ? trim((string) ($socAcc['username'] ?? '')) : '';
                            $inputTitle = $uname !== '' ? ('@' . $uname) : 'Input (Garage)';
                            $inputBlocks[] = [
                                'record_id' => 'garage:' . $sid,
                                'title' => $inputTitle,
                                'source_url' => '',
                                'entries' => $entries,
                                'source' => 'garage',
                            ];
                        }
                    } else {
                        $err = $list['error'];
                        $garagePayload = ['prefix' => $prefix, 'items' => [], 'error' => $err];
                        $inputErr = $err;
                    }
                }
            } catch (Throwable $e) {
                error_log('FormatForge media_library garage input: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $garagePayload = [
                    'prefix' => $sid !== '' ? ff_garage_social_key_prefix($sid) : '',
                    'items' => [],
                    'error' => $e->getMessage(),
                ];
                $inputErr = $e->getMessage();
            }
        } else {
            try {
                $ir = pb_request('GET', '/api/collections/' . rawurlencode($inpCol) . '/records?' . $q, null, $authHeader);
                foreach (ff_pb_list_items($ir) as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $entries = ff_media_entries_from_pb_record($it, $inpCol, $script);
                    if ($entries === []) {
                        continue;
                    }
                    $title = trim((string) ($it['title'] ?? ''));
                    $srcUrl = trim((string) ($it['url'] ?? $it['input_url'] ?? ''));
                    $inputBlocks[] = [
                        'record_id' => (string) ($it['id'] ?? ''),
                        'title' => $title !== '' ? $title : ($srcUrl !== '' ? $srcUrl : (string) ($it['id'] ?? '')),
                        'source_url' => $srcUrl,
                        'entries' => $entries,
                        'source' => 'pocketbase',
                    ];
                }
            } catch (Throwable $e) {
                error_log('FormatForge media_library input_media: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
                $inputErr = $e->getMessage();
            }
        }
        $outputBlocks = [];
        $outputErr = '';
        try {
            $or = pb_request('GET', '/api/collections/' . rawurlencode($outCol) . '/records?' . $q, null, $authHeader);
            foreach (ff_pb_list_items($or) as $it) {
                if (!is_array($it)) {
                    continue;
                }
                $entries = ff_media_entries_from_pb_record($it, $outCol, $script);
                if ($entries === []) {
                    continue;
                }
                $title = trim((string) ($it['title'] ?? ''));
                $outputBlocks[] = [
                    'record_id' => (string) ($it['id'] ?? ''),
                    'title' => $title !== '' ? $title : (string) ($it['id'] ?? ''),
                    'entries' => $entries,
                ];
            }
        } catch (Throwable $e) {
            error_log('FormatForge media_library output_media: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            $outputErr = $e->getMessage();
        }

        $errors = [];
        if ($inputErr !== '') {
            $errors['input_media'] = $inputErr;
        }
        if ($outputErr !== '') {
            $errors['output_media'] = $outputErr;
        }
        $payload = [
            'ok' => true,
            'input_media' => $inputBlocks,
            'output_media' => $outputBlocks,
            'garage' => $garagePayload,
        ];
        if ($errors !== []) {
            $payload['errors'] = $errors;
        }
        $jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        if (defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
            $jsonFlags |= JSON_PARTIAL_OUTPUT_ON_ERROR;
        }
        $out = json_encode($payload, $jsonFlags);
        if ($out === false) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Could not encode media_library JSON']);
            exit;
        }
        echo $out;
    } catch (Throwable $e) {
        error_log('FormatForge media_library uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        $err = [
            'ok' => false,
            'error' => 'Media library failed (see PHP / web server error log for details).',
        ];
        if (trim((string) (getenv('FF_DEBUG_MEDIA') ?: '')) === '1') {
            $err['detail'] = $e->getMessage();
        }
        echo json_encode($err, JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/** POST ?action=schedule_instagram_carousel — queue carousel for Instagram (public HTTPS image URLs per slide). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'schedule_instagram_carousel') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON body.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $sid = trim((string) ($data['social_account_id'] ?? ''));
    $caption = trim((string) ($data['caption'] ?? ''));
    $scheduledAt = trim((string) ($data['scheduled_at'] ?? ''));
    $doc = $data['doc'] ?? null;
    if ($sid === '' || $scheduledAt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'social_account_id and scheduled_at (ISO 8601) are required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_array($doc)) {
        http_response_code(400);
        echo json_encode(['error' => 'doc must be the carousel JSON (slides, config).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ts = strtotime($scheduledAt);
    if ($ts === false || $ts < time() + 60) {
        http_response_code(400);
        echo json_encode(['error' => 'scheduled_at must be at least ~1 minute in the future.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    global $authHeader, $user;
    if ($authHeader === null || trim((string) $authHeader) === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Session token missing.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (ff_pb_owned_social_account($authHeader, $sid) === null) {
        http_response_code(403);
        echo json_encode(['error' => 'Instagram account not found or not accessible.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (function_exists('session_write_close')) {
        session_write_close();
    }
    ff_allow_long_request(300);
    $uidSchedule = ff_session_pb_user_id();
    $res = ff_carousel_doc_resolve_ig_image_urls_for_schedule($doc, $authHeader, $uidSchedule !== '' ? $uidSchedule : null);
    if (!$res['ok']) {
        http_response_code(400);
        echo json_encode(['error' => $res['error'] !== '' ? $res['error'] : 'Could not resolve image URLs for Instagram.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $urls = $res['urls'];
    $cfg = $GLOBALS['CONFIG'];
    $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
    $userEmail = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
    $meta = [
        'user_email' => $userEmail,
        'caption' => $caption,
        'image_urls' => $urls,
        'pb_user_id' => $uidSchedule,
    ];
    $enc = json_encode($doc, JSON_UNESCAPED_UNICODE);
    if (is_string($enc) && strlen($enc) < 400000) {
        $meta['carousel_doc'] = $doc;
    }
    $scheduledIso = gmdate('Y-m-d\TH:i:s.000\Z', $ts);
    $payload = [
        'type' => 'ig_carousel_schedule',
        'title' => $caption !== '' ? ff_mb_substr($caption, 0, 200) : 'Instagram carousel',
        'prompt' => '',
        'status' => 'scheduled',
        'social_account_id' => $sid,
        'scheduled_publish_at' => $scheduledIso,
        'metadata' => $meta,
    ];
    $r = pb_request('POST', '/api/collections/' . rawurlencode($outCol) . '/records', $payload, $authHeader);
    if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 300) {
        $msg = ff_pb_extract_error_message($r['body'] ?? []) ?: ('HTTP ' . ($r['code'] ?? 0));
        http_response_code(502);
        echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rec = ff_pb_normalize_api_record($r['body']);
    echo json_encode(['ok' => true, 'id' => (string) ($rec['id'] ?? ''), 'image_count' => count($urls)], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Publish one output_media ig_carousel_schedule row to Instagram; PATCHes status/metadata on success or failure.
 *
 * @return array{ok: bool, media_id?: string, error?: string}
 */
function ff_instagram_publish_schedule_record(string $outCol, string $rid, array $it, string $pbToken): array
{
    $sid = trim((string) ($it['social_account_id'] ?? ''));
    if ($rid === '' || $sid === '') {
        return ['ok' => false, 'error' => 'bad_record'];
    }
    $meta = $it['metadata'] ?? [];
    if (is_string($meta)) {
        $meta = json_decode($meta, true) ?: [];
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $caption = (string) ($meta['caption'] ?? '');
    $urls = $meta['image_urls'] ?? [];
    if (!is_array($urls)) {
        $urls = [];
    }
    $soc = pb_request('GET', '/api/collections/social_accounts/records/' . rawurlencode($sid), null, $pbToken);
    if (($soc['code'] ?? 0) !== 200) {
        $failMeta = array_merge($meta, ['schedule_error' => 'social_accounts record missing']);
        pb_request('PATCH', '/api/collections/' . rawurlencode($outCol) . '/records/' . rawurlencode($rid), [
            'status' => 'failed',
            'metadata' => $failMeta,
        ], $pbToken);

        return ['ok' => false, 'error' => 'social_accounts record missing'];
    }
    $srec = ff_pb_normalize_api_record($soc['body']);
    $igUserId = trim((string) ($srec['instagram_user_id'] ?? ''));
    $token = trim((string) ($srec['access_token'] ?? ''));
    if ($igUserId === '' || $token === '') {
        $failMeta = array_merge($meta, ['schedule_error' => 'Missing instagram_user_id or access_token']);
        pb_request('PATCH', '/api/collections/' . rawurlencode($outCol) . '/records/' . rawurlencode($rid), [
            'status' => 'failed',
            'metadata' => $failMeta,
        ], $pbToken);

        return ['ok' => false, 'error' => 'Missing instagram_user_id or access_token'];
    }
    $pbUidCron = trim((string) ($meta['pb_user_id'] ?? ''));
    $pub = ff_ig_publish_carousel_or_single($igUserId, $token, $urls, $caption, $pbUidCron !== '' ? $pbUidCron : null, $pbToken);
    if (!$pub['ok']) {
        $schedErr = $pub['error'] ?? 'publish_failed';
        if (!is_string($schedErr)) {
            $schedErr = json_encode($schedErr, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: 'publish_failed';
        }
        $failMeta = array_merge($meta, ['schedule_error' => $schedErr]);
        pb_request('PATCH', '/api/collections/' . rawurlencode($outCol) . '/records/' . rawurlencode($rid), [
            'status' => 'failed',
            'metadata' => $failMeta,
        ], $pbToken);

        return ['ok' => false, 'error' => (string) ($pub['error'] ?? 'publish_failed')];
    }
    $okMeta = array_merge($meta, [
        'published_ig_media_id' => $pub['media_id'],
        'published_at' => gmdate('c'),
    ]);
    pb_request('PATCH', '/api/collections/' . rawurlencode($outCol) . '/records/' . rawurlencode($rid), [
        'status' => 'published',
        'published_at' => gmdate('Y-m-d\TH:i:s.000\Z'),
        'metadata' => $okMeta,
    ], $pbToken);

    return ['ok' => true, 'media_id' => (string) ($pub['media_id'] ?? '')];
}

/** POST ?action=post_instagram_carousel_now — same as schedule, but creates a due row and publishes immediately (session). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'post_instagram_carousel_now') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        ff_allow_long_request(300);
        if (!ff_gate_session_ok()) {
            http_response_code(403);
            echo json_encode(['error' => 'Sign in required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $raw = file_get_contents('php://input') ?: '';
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON body.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $sid = trim((string) ($data['social_account_id'] ?? ''));
        $caption = trim((string) ($data['caption'] ?? ''));
        $doc = $data['doc'] ?? null;
        if ($sid === '') {
            http_response_code(400);
            echo json_encode(['error' => 'social_account_id is required.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (!is_array($doc)) {
            http_response_code(400);
            echo json_encode(['error' => 'doc must be the carousel JSON (slides, config).'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        global $authHeader, $user;
        if ($authHeader === null || trim((string) $authHeader) === '') {
            http_response_code(403);
            echo json_encode(['error' => 'Session token missing.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (ff_pb_owned_social_account($authHeader, $sid) === null) {
            http_response_code(403);
            echo json_encode(['error' => 'Instagram account not found or not accessible.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        if (function_exists('session_write_close')) {
            session_write_close();
        }
        $uidSchedule = ff_session_pb_user_id();
        $res = ff_carousel_doc_resolve_ig_image_urls_for_schedule($doc, $authHeader, $uidSchedule !== '' ? $uidSchedule : null);
        if (!$res['ok']) {
            http_response_code(400);
            echo json_encode(['error' => $res['error'] !== '' ? $res['error'] : 'Could not resolve image URLs for Instagram.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $urls = $res['urls'];
        $cfg = $GLOBALS['CONFIG'];
        $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
        $userEmail = is_array($user) ? trim((string) ($user['email'] ?? '')) : '';
        $meta = [
            'user_email' => $userEmail,
            'caption' => $caption,
            'image_urls' => $urls,
            'pb_user_id' => $uidSchedule,
        ];
        $enc = json_encode($doc, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (is_string($enc) && strlen($enc) < 400000) {
            $meta['carousel_doc'] = $doc;
        }
        $scheduledIso = gmdate('Y-m-d\TH:i:s.000\Z');
        $payload = [
            'type' => 'ig_carousel_schedule',
            'title' => $caption !== '' ? ff_mb_substr($caption, 0, 200) : 'Instagram carousel',
            'prompt' => '',
            'status' => 'scheduled',
            'social_account_id' => $sid,
            'scheduled_publish_at' => $scheduledIso,
            'metadata' => $meta,
        ];
        $r = pb_request('POST', '/api/collections/' . rawurlencode($outCol) . '/records', $payload, $authHeader);
        if (($r['code'] ?? 0) < 200 || ($r['code'] ?? 0) >= 300) {
            $msg = ff_pb_extract_error_message($r['body'] ?? []) ?: ('HTTP ' . ($r['code'] ?? 0));
            http_response_code(502);
            echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $rec = ff_pb_normalize_api_record($r['body']);
        $rid = (string) ($rec['id'] ?? '');
        if ($rid === '') {
            http_response_code(502);
            echo json_encode(['error' => 'Create succeeded but record id missing.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // Create API response may omit metadata; publish must see image_urls we just stored.
        $rec['social_account_id'] = $sid;
        $rec['metadata'] = $meta;
        $nUrls = count($urls);
        // Default: finish Meta publish before closing the HTTP request. Background mode (FF_IG_POST_NOW_BACKGROUND=1)
        // returns early via fastcgi_finish_request(); on some FPM/nginx setups the worker exits and the row
        // stays stuck as status=scheduled with nothing posted (see post_instagram_carousel_now + list UI).
        $bgPublish = trim((string) (getenv('FF_IG_POST_NOW_BACKGROUND') ?: '')) === '1'
            && function_exists('fastcgi_finish_request');
        if ($bgPublish) {
            header('X-Accel-Buffering: no');
            $outEarly = [
                'ok' => true,
                'id' => $rid,
                'publishing' => true,
                'image_count' => $nUrls,
            ];
            $outJson = json_encode($outEarly, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($outJson === false) {
                http_response_code(500);
                echo json_encode(['error' => 'Could not encode response JSON.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            ignore_user_abort(true);
            echo $outJson;
            fastcgi_finish_request();
            try {
                $pubResult = ff_instagram_publish_schedule_record($outCol, $rid, $rec, $authHeader);
                if (!$pubResult['ok']) {
                    error_log('FormatForge post_instagram_carousel_now background publish failed id=' . $rid . ': ' . ((string) ($pubResult['error'] ?? '')));
                }
            } catch (Throwable $e2) {
                error_log('FormatForge post_instagram_carousel_now background: ' . $e2->getMessage() . ' @ ' . $e2->getFile() . ':' . $e2->getLine());
            }
            exit;
        }
        $pubResult = ff_instagram_publish_schedule_record($outCol, $rid, $rec, $authHeader);
        if (!$pubResult['ok']) {
            http_response_code(502);
            echo json_encode([
                'ok' => false,
                'error' => (string) ($pubResult['error'] ?? 'Publish failed.'),
                'id' => $rid,
                'image_count' => $nUrls,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            exit;
        }
        $outJson = json_encode([
            'ok' => true,
            'id' => $rid,
            'ig_media_id' => (string) ($pubResult['media_id'] ?? ''),
            'image_count' => $nUrls,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($outJson === false) {
            http_response_code(500);
            echo json_encode(['error' => 'Could not encode response JSON.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        echo $outJson;
        exit;
    } catch (Throwable $e) {
        error_log('FormatForge post_instagram_carousel_now: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        http_response_code(500);
        $err = ['error' => 'Server error while publishing (see server log).'];
        if (trim((string) (getenv('FF_DEBUG_MEDIA') ?: '')) === '1') {
            $err['detail'] = $e->getMessage();
        }
        echo json_encode($err, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
}

/** GET ?action=list_instagram_schedules — scheduled Instagram posts for the signed-in user. */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'list_instagram_schedules') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    global $authHeader, $user;
    if ($authHeader === null || trim((string) $authHeader) === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Session token missing.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfg = $GLOBALS['CONFIG'];
    $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
    $email = trim((string) ($user['email'] ?? ''));
    $q = http_build_query([
        'filter' => 'type = "ig_carousel_schedule" && status != "cancelled"',
        'perPage' => 60,
        // Use @rowid like other output_media lists — some PB/API setups reject sort on system updated.
        'sort' => '-@rowid',
    ]);
    $r = pb_request('GET', '/api/collections/' . rawurlencode($outCol) . '/records?' . $q, null, $authHeader);
    if (($r['code'] ?? 0) !== 200) {
        $pbMsg = is_array($r['body'] ?? null) ? trim((string) ($r['body']['message'] ?? '')) : '';
        error_log('FormatForge list_instagram_schedules: PocketBase HTTP ' . ($r['code'] ?? 0) . ($pbMsg !== '' ? (' — ' . $pbMsg) : ''));
        http_response_code(502);
        echo json_encode(['error' => 'Could not list schedules.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $items = ff_pb_list_items($r);
    $out = [];
    $publishedKept = 0;
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $meta = $it['metadata'] ?? [];
        if (is_string($meta)) {
            $meta = json_decode($meta, true) ?: [];
        }
        if (!is_array($meta)) {
            $meta = [];
        }
        if (($meta['user_email'] ?? '') !== $email) {
            continue;
        }
        $st = trim((string) ($it['status'] ?? ''));
        if ($st === 'published') {
            if ($publishedKept >= 12) {
                continue;
            }
            $publishedKept++;
        }
        $out[] = [
            'id' => (string) ($it['id'] ?? ''),
            'status' => $st !== '' ? $st : 'scheduled',
            'scheduled_publish_at' => (string) ($it['scheduled_publish_at'] ?? ''),
            'social_account_id' => (string) ($it['social_account_id'] ?? ''),
            'caption' => (string) ($meta['caption'] ?? ''),
            'image_count' => is_array($meta['image_urls'] ?? null) ? count($meta['image_urls']) : 0,
            'schedule_error' => (string) ($meta['schedule_error'] ?? ''),
            'ig_media_id' => (string) ($meta['published_ig_media_id'] ?? ''),
        ];
    }
    usort($out, static function (array $a, array $b): int {
        $sa = $a['status'] ?? '';
        $sb = $b['status'] ?? '';
        $rank = static function (string $s): int {
            return match ($s) {
                'scheduled' => 0,
                'failed' => 1,
                'published' => 2,
                default => 3,
            };
        };
        $ra = $rank($sa);
        $rb = $rank($sb);
        if ($ra !== $rb) {
            return $ra <=> $rb;
        }

        $ta = (string) ($a['scheduled_publish_at'] ?? '');
        $tb = (string) ($b['scheduled_publish_at'] ?? '');
        $tier = (string) ($a['status'] ?? '');
        if ($tier === 'scheduled' || $tier === '') {
            return strcmp($ta, $tb);
        }
        return strcmp($tb, $ta);
    });
    echo json_encode(['ok' => true, 'items' => $out], JSON_UNESCAPED_UNICODE);
    exit;
}

/** POST ?action=cancel_instagram_schedule — cancel a queued post (body: {"id":"…"}). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'cancel_instagram_schedule') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $data = json_decode($raw, true);
    $id = is_array($data) ? trim((string) ($data['id'] ?? '')) : '';
    if ($id === '') {
        http_response_code(400);
        echo json_encode(['error' => 'JSON must include id.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    global $authHeader, $user;
    if ($authHeader === null || trim((string) $authHeader) === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Session token missing.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfg = $GLOBALS['CONFIG'];
    $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
    $gr = pb_request('GET', '/api/collections/' . rawurlencode($outCol) . '/records/' . rawurlencode($id), null, $authHeader);
    if (($gr['code'] ?? 0) !== 200) {
        http_response_code(404);
        echo json_encode(['error' => 'Schedule not found.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rec = ff_pb_normalize_api_record($gr['body']);
    if (($rec['type'] ?? '') !== 'ig_carousel_schedule' || ($rec['status'] ?? '') !== 'scheduled') {
        http_response_code(400);
        echo json_encode(['error' => 'Not a cancellable scheduled post.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $meta = $rec['metadata'] ?? [];
    if (is_string($meta)) {
        $meta = json_decode($meta, true) ?: [];
    }
    if (!is_array($meta)) {
        $meta = [];
    }
    $email = trim((string) ($user['email'] ?? ''));
    if (($meta['user_email'] ?? '') !== $email) {
        http_response_code(403);
        echo json_encode(['error' => 'Not your schedule.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $patch = ['status' => 'cancelled'];
    $pr = pb_request('PATCH', '/api/collections/' . rawurlencode($outCol) . '/records/' . rawurlencode($id), $patch, $authHeader);
    if (($pr['code'] ?? 0) < 200 || ($pr['code'] ?? 0) >= 300) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not cancel.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

/** GET ?action=process_instagram_schedules&cron_secret=… — publish due posts (cron; requires FF_CRON_PB_TOKEN). */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'process_instagram_schedules') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $cronSecret = trim((string) (getenv('CRON_SECRET') ?: ''));
    $got = trim((string) ($_GET['cron_secret'] ?? ''));
    $pbCron = trim((string) (getenv('FF_CRON_PB_TOKEN') ?: ''));
    if ($cronSecret === '' || $got !== $cronSecret || $pbCron === '') {
        http_response_code(403);
        echo json_encode(['error' => 'Cron not configured (CRON_SECRET + FF_CRON_PB_TOKEN).'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ff_allow_long_request(300);
    $cfg = $GLOBALS['CONFIG'];
    $outCol = (string) ($cfg['output_media_collection'] ?? 'output_media');
    $q = http_build_query([
        'filter' => 'type = "ig_carousel_schedule" && status = "scheduled"',
        'perPage' => 30,
        'sort' => 'scheduled_publish_at',
    ]);
    $r = pb_request('GET', '/api/collections/' . rawurlencode($outCol) . '/records?' . $q, null, $pbCron);
    if (($r['code'] ?? 0) !== 200) {
        http_response_code(502);
        echo json_encode(['error' => 'Could not list schedules.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $items = ff_pb_list_items($r);
    $now = time();
    $processed = [];
    foreach ($items as $it) {
        if (!is_array($it)) {
            continue;
        }
        $rid = (string) ($it['id'] ?? '');
        if ($rid === '') {
            continue;
        }
        $when = $it['scheduled_publish_at'] ?? '';
        if (!is_string($when) || $when === '') {
            continue;
        }
        $wt = strtotime($when);
        if ($wt === false || $wt > $now) {
            continue;
        }
        $pubResult = ff_instagram_publish_schedule_record($outCol, $rid, $it, $pbCron);
        if ($pubResult['ok']) {
            $processed[] = ['id' => $rid, 'ok' => true, 'ig_media_id' => $pubResult['media_id'] ?? ''];
        } else {
            $processed[] = ['id' => $rid, 'ok' => false, 'error' => $pubResult['error'] ?? 'unknown'];
        }
    }
    echo json_encode(['ok' => true, 'processed' => $processed], JSON_UNESCAPED_UNICODE);
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
    $scope = (string) ($cfg['instagram_oauth_scope'] ?? $defaultIgScope);
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
        $pageToken = trim((string) ($page['access_token'] ?? ''));
        if ($pageToken === '') {
            $pageToken = (string) $fbToken;
        }
        if (!$pageId) {
            continue;
        }
        $igBiz = $page['instagram_business_account'] ?? null;
        if (!$igBiz || empty($igBiz['id'])) {
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
    ff_redirect_url('/?ig_error=1');
}

/** Replicate HTTP JSON helper. */
function ff_replicate_api_json(string $method, string $url, string $token, ?string $jsonBody, int $timeoutSec = 120): array
{
    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer ' . $token,
        'Accept: application/json',
    ];
    if ($jsonBody !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeoutSec,
    ]);
    if ($jsonBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    }
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => json_decode($res ?: '{}', true) ?? [], 'raw' => $res ?: ''];
}

/**
 * Upload bytes to Replicate Files API; returns HTTPS URL for image_input.
 *
 * @return array{ok: bool, url: string, error: string}
 */
function ff_replicate_upload_file(string $token, string $binary, string $basename, string $mime): array
{
    $out = ['ok' => false, 'url' => '', 'error' => ''];
    if (!class_exists(CURLFile::class)) {
        $out['error'] = 'PHP CURLFile not available';

        return $out;
    }
    $safeBase = preg_replace('/[^a-zA-Z0-9._-]/', '', $basename) ?: 'context.jpg';
    $tmp = sys_get_temp_dir() . '/ffrep_' . bin2hex(random_bytes(8)) . '_' . $safeBase;
    if (@file_put_contents($tmp, $binary) === false) {
        $out['error'] = 'Could not write temp file for upload';

        return $out;
    }
    $mime = $mime !== '' ? $mime : 'application/octet-stream';
    $cf = new CURLFile($tmp, $mime, $safeBase);
    $ch = curl_init('https://api.replicate.com/v1/files');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_POSTFIELDS => ['content' => $cf],
        CURLOPT_TIMEOUT => 120,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    @unlink($tmp);
    $body = json_decode($res ?: '{}', true) ?? [];
    if ($code < 200 || $code >= 300) {
        $out['error'] = is_array($body) ? (string) ($body['detail'] ?? $body['message'] ?? 'File upload failed') : 'File upload failed';

        return $out;
    }
    $u = '';
    if (isset($body['urls']['get']) && is_string($body['urls']['get'])) {
        $u = $body['urls']['get'];
    } elseif (isset($body['url']) && is_string($body['url'])) {
        $u = $body['url'];
    }
    $u = trim($u);
    if ($u === '' || !str_starts_with($u, 'http')) {
        $out['error'] = 'File upload response missing URL';

        return $out;
    }
    $out['ok'] = true;
    $out['url'] = $u;

    return $out;
}

/**
 * Run google/nano-banana-pro; polls until terminal state.
 *
 * @param list<string> $imageInput
 * @return array{ok: bool, output_url: string, error: string}
 */
function ff_replicate_nano_banana_pro(string $token, string $prompt, array $imageInput, string $resolution, string $aspectRatio, string $outputFormat, bool $allowFallback): array
{
    $out = ['ok' => false, 'output_url' => '', 'error' => ''];
    $input = [
        'prompt' => $prompt,
        'image_input' => array_values($imageInput),
        'resolution' => $resolution,
        'aspect_ratio' => $aspectRatio,
        'output_format' => $outputFormat,
        'safety_filter_level' => 'block_only_high',
        'allow_fallback_model' => $allowFallback,
    ];
    $payload = json_encode([
        'version' => 'google/nano-banana-pro',
        'input' => $input,
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $out['error'] = 'Could not encode Replicate request';

        return $out;
    }
    $ch = curl_init('https://api.replicate.com/v1/predictions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Accept: application/json',
            'Prefer: wait=60',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 130,
    ]);
    $res = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    if ($code < 200 || $code >= 300) {
        $out['error'] = is_array($body) ? (string) ($body['detail'] ?? $body['message'] ?? json_encode($body)) : 'Replicate create failed';

        return $out;
    }
    $getUrl = '';
    if (isset($body['urls']['get']) && is_string($body['urls']['get'])) {
        $getUrl = trim($body['urls']['get']);
    }
    if ($getUrl === '') {
        $out['error'] = 'Replicate response missing prediction URL';

        return $out;
    }
    $status = (string) ($body['status'] ?? '');
    $deadline = time() + 240;
    while (in_array($status, ['starting', 'processing'], true) && time() < $deadline) {
        usleep(600000);
        $g = ff_replicate_api_json('GET', $getUrl, $token, null, 60);
        $body = is_array($g['body']) ? $g['body'] : [];
        $status = (string) ($body['status'] ?? '');
    }
    if ($status !== 'succeeded') {
        $err = (string) ($body['error'] ?? '');
        $out['error'] = $err !== '' ? $err : ('Prediction status: ' . ($status !== '' ? $status : 'unknown'));

        return $out;
    }
    $output = $body['output'] ?? null;
    $urlOut = '';
    if (is_string($output)) {
        $urlOut = trim($output);
    }
    if ($urlOut === '' || !str_starts_with($urlOut, 'http')) {
        $out['error'] = 'Replicate output was not an image URL';

        return $out;
    }
    $out['ok'] = true;
    $out['output_url'] = $urlOut;

    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ff_request_action() === 'generate') {
    header('Content-Type: application/json; charset=utf-8');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    if (!is_array($data) || !isset($data['prompt']) || !is_string($data['prompt'])) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON body must include string "prompt"']);
        exit;
    }

    $prompt = trim($data['prompt']);
    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt is empty']);
        exit;
    }

    $openrouterKey = cg_env('OPENROUTER_API_KEY');
    if ($openrouterKey === false) {
        http_response_code(503);
        echo json_encode(['error' => 'Slide generation requires OPENROUTER_API_KEY in .env. Gemini and OpenAI are not used for slides.']);
        exit;
    }

    $system = <<<'SYS'
You output only valid JSON (no markdown). Shape: {"slides":[{"elements":[{"type":"Title"|"Subtitle"|"Description","text":"..."}]}]}.
Rules:
- At most 10 slides (never more); fewer is fine if the topic is narrow. Each slide has 2-3 elements mixing Title, Subtitle, Description.
- Text under ~70% of typical limits: title/subtitle max ~110 chars; descriptions shorter.
- Add tasteful emojis in text. No slide numbers in copy.
- Elements on a slide share one idea; adapt the user topic for LinkedIn-style carousels.
SYS;

    $orModel = (string) cg_env('OPENROUTER_MODEL', 'openai/gpt-4o-mini');
    $orModel = preg_replace('/[^a-zA-Z0-9._\-\/]/', '', $orModel) ?: 'openai/gpt-4o-mini';
    $payload = [
        'model' => $orModel,
        'temperature' => 0,
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $prompt],
        ],
        'response_format' => ['type' => 'json_object'],
    ];
    $body = json_encode($payload);
    if ($body === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not encode request']);
        exit;
    }
    $referer = (string) cg_env('OPENROUTER_HTTP_REFERER', 'https://127.0.0.1');
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $openrouterKey,
                'Referer: ' . $referer,
                'X-Title: Carousel Generator',
            ]),
            'content' => $body,
            'timeout' => 120,
            'ignore_errors' => true,
        ],
    ]);
    $rawResponse = @file_get_contents('https://openrouter.ai/api/v1/chat/completions', false, $ctx);
    $httpCode = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $httpCode = (int) $m[1];
    }
    if ($rawResponse === false || $rawResponse === '') {
        http_response_code(502);
        echo json_encode(['error' => 'OpenRouter request failed (no response)']);
        exit;
    }
    $decoded = json_decode($rawResponse, true);
    if (!is_array($decoded)) {
        http_response_code(502);
        echo json_encode(['error' => 'Invalid OpenRouter response']);
        exit;
    }
    if ($httpCode >= 400) {
        $msg = $decoded['error']['message'] ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : 'OpenRouter error');
        http_response_code(502);
        echo json_encode(['error' => $msg]);
        exit;
    }
    $modelText = (string) ($decoded['choices'][0]['message']['content'] ?? '');

    $slides = cg_parse_slides_json($modelText);
    if ($slides === null) {
        http_response_code(502);
        echo json_encode(['error' => 'Model did not return valid slides JSON']);
        exit;
    }
    $slides = array_slice($slides, 0, FF_CAROUSEL_AI_SLIDES_MAX);
    if ($slides === []) {
        http_response_code(502);
        echo json_encode(['error' => 'Model returned no slides']);
        exit;
    }

    $resp = ['slides' => $slides];
    if (ff_should_save_generated_to_garage()) {
        $uid = ff_session_pb_user_id();
        if ($uid !== '') {
            $relFile = 'openrouter-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.json';
            $relUser = 'slides/' . $relFile;
            $blob = json_encode([
                'kind' => 'slide_generation',
                'prompt' => $prompt,
                'slides' => $slides,
                'saved_at' => gmdate('c'),
                'model' => $orModel,
            ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($blob !== false) {
                $socialSid = trim((string) ($data['social_account_id'] ?? ''));
                $saveSoc = $socialSid !== '' && ff_pb_owned_social_account($authHeader, $socialSid) !== null;
                $relScoped = $saveSoc ? ('ai/slides/' . $relFile) : $relUser;
                $objKey = $saveSoc
                    ? ff_garage_social_object_key($socialSid, $relScoped)
                    : ff_garage_generated_object_key($uid, $relUser);
                if ($objKey !== '') {
                    $put = ff_garage_put_object($objKey, $blob, 'application/json; charset=utf-8');
                    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                    $g = ['saved' => $put['ok'], 'key' => $relScoped, 'scope' => $saveSoc ? 'social_account' : 'user'];
                    if ($saveSoc) {
                        $g['social_account_id'] = $socialSid;
                    }
                    if ($put['ok']) {
                        $g['download_url'] = $saveSoc
                            ? ($script . '?action=garage_download&key=' . rawurlencode($relScoped) . '&social_account_id=' . rawurlencode($socialSid))
                            : ($script . '?action=garage_generated_download&key=' . rawurlencode($relUser));
                        $pub = ff_garage_public_https_url_for_object_key($objKey);
                        if ($pub !== '') {
                            $g['public_url'] = $pub;
                        }
                    } else {
                        $g['error'] = $put['error'];
                    }
                    $resp['garage'] = $g;
                }
            }
        }
    }

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ff_request_action() === 'generate_image') {
    header('Content-Type: application/json; charset=utf-8');
    if (!ff_gate_session_ok()) {
        http_response_code(403);
        echo json_encode(['error' => 'Sign in required.']);
        exit;
    }
    $repTok = trim((string) (getenv('REPLICATE_API_TOKEN') ?: ''));
    if ($repTok === '') {
        http_response_code(503);
        echo json_encode(['error' => 'REPLICATE_API_TOKEN not set']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?? '', true);
    if (!is_array($data) || !isset($data['prompt']) || !is_string($data['prompt'])) {
        http_response_code(400);
        echo json_encode(['error' => 'JSON body must include string "prompt"']);
        exit;
    }
    $prompt = trim($data['prompt']);
    if ($prompt === '') {
        http_response_code(400);
        echo json_encode(['error' => 'Prompt is empty']);
        exit;
    }
    $imageInput = [];
    $imageUrl = trim((string) ($data['image_url'] ?? ''));
    if ($imageUrl !== '' && preg_match('#^https?://#i', $imageUrl)) {
        $imageInput[] = $imageUrl;
    }
    $b64raw = $data['image_base64'] ?? null;
    if (is_string($b64raw) && $b64raw !== '') {
        $b64clean = preg_replace('/\s+/', '', $b64raw);
        $bin = base64_decode($b64clean, true);
        if ($bin !== false && strlen($bin) > 16) {
            $maxBytes = 12 * 1024 * 1024;
            if (strlen($bin) > $maxBytes) {
                http_response_code(400);
                echo json_encode(['error' => 'Context image too large (max 12 MB)']);
                exit;
            }
            $mime = strtolower(trim((string) ($data['image_mime'] ?? 'image/jpeg')));
            $mime = preg_replace('/[^a-z0-9.\/+=-]/i', '', $mime) ?: 'image/jpeg';
            if (!str_starts_with($mime, 'image/')) {
                $mime = 'image/jpeg';
            }
            $ext = 'jpg';
            if (str_contains($mime, 'png')) {
                $ext = 'png';
            } elseif (str_contains($mime, 'webp')) {
                $ext = 'webp';
            } elseif (str_contains($mime, 'gif')) {
                $ext = 'gif';
            }
            if (strlen($bin) <= 245760) {
                $imageInput[] = 'data:' . $mime . ';base64,' . base64_encode($bin);
            } else {
                $up = ff_replicate_upload_file($repTok, $bin, 'context.' . $ext, $mime);
                if (!$up['ok']) {
                    http_response_code(502);
                    echo json_encode(['error' => 'Could not upload context image to Replicate: ' . $up['error']]);
                    exit;
                }
                $imageInput[] = $up['url'];
            }
        }
    }
    if (count($imageInput) > 14) {
        http_response_code(400);
        echo json_encode(['error' => 'Too many context images (max 14)']);
        exit;
    }
    $aspect = trim((string) ($data['aspect_ratio'] ?? ''));
    $allowedAspect = ['match_input_image', '1:1', '2:3', '3:2', '3:4', '4:3', '4:5', '5:4', '9:16', '16:9', '21:9'];
    if ($aspect === '' || !in_array($aspect, $allowedAspect, true)) {
        $aspect = count($imageInput) > 0 ? 'match_input_image' : '4:5';
    }
    if ($aspect === 'match_input_image' && $imageInput === []) {
        $aspect = '4:5';
    }
    $resolution = trim((string) ($data['resolution'] ?? ''));
    if ($resolution === '') {
        $resolution = trim((string) (getenv('REPLICATE_NANO_RESOLUTION') ?: '2K'));
    }
    if (!in_array($resolution, ['1K', '2K', '4K'], true)) {
        $resolution = '2K';
    }
    $outputFormat = strtolower(trim((string) ($data['output_format'] ?? '')));
    if ($outputFormat === '') {
        $outputFormat = strtolower(trim((string) (getenv('REPLICATE_NANO_OUTPUT_FORMAT') ?: 'png')));
    }
    if (!in_array($outputFormat, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $outputFormat = 'png';
    }
    if ($outputFormat === 'jpeg') {
        $outputFormat = 'jpg';
    }
    $allowFallback = filter_var($data['allow_fallback_model'] ?? true, FILTER_VALIDATE_BOOL);

    $run = ff_replicate_nano_banana_pro($repTok, $prompt, $imageInput, $resolution, $aspect, $outputFormat, $allowFallback);
    if (!$run['ok']) {
        http_response_code(502);
        echo json_encode(['error' => $run['error']]);
        exit;
    }
    $imageUrlOut = $run['output_url'];
    $resp = ['ok' => true, 'image_url' => $imageUrlOut];
    if (ff_should_save_generated_to_garage()) {
        $uid = ff_session_pb_user_id();
        if ($uid !== '') {
            $fetch = ff_http_get_bytes($run['output_url'], 40 * 1024 * 1024, 130);
            if ($fetch['ok']) {
                $ext = $outputFormat === 'jpeg' ? 'jpg' : $outputFormat;
                $relFile = 'replicate-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
                $relUser = 'images/' . $relFile;
                $mime = strtolower((string) $fetch['content_type']);
                if ($mime === '' || !str_starts_with($mime, 'image/')) {
                    $mime = ff_mime_for_image_ext($ext);
                }
                $socialSid = trim((string) ($data['social_account_id'] ?? ''));
                $saveSoc = $socialSid !== '' && ff_pb_owned_social_account($authHeader, $socialSid) !== null;
                $relScoped = $saveSoc ? ('ai/images/' . $relFile) : $relUser;
                $objKey = $saveSoc
                    ? ff_garage_social_object_key($socialSid, $relScoped)
                    : ff_garage_generated_object_key($uid, $relUser);
                if ($objKey !== '') {
                    $put = ff_garage_put_object($objKey, $fetch['bytes'], $mime);
                    $script = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
                    if ($put['ok']) {
                        $imageUrlOut = $saveSoc
                            ? ($script . '?action=garage_download&inline=1&key=' . rawurlencode($relScoped) . '&social_account_id=' . rawurlencode($socialSid))
                            : ($script . '?action=garage_generated_download&inline=1&key=' . rawurlencode($relUser));
                        $resp['image_url'] = $imageUrlOut;
                        $pubImg = ff_garage_public_https_url_for_object_key($objKey);
                        if ($pubImg !== '') {
                            $resp['public_image_url'] = $pubImg;
                        }
                        $g = ['saved' => true, 'key' => $relScoped, 'scope' => $saveSoc ? 'social_account' : 'user'];
                        if ($saveSoc) {
                            $g['social_account_id'] = $socialSid;
                        }
                        $resp['garage'] = $g;
                    } else {
                        $resp['garage'] = [
                            'saved' => false,
                            'key' => $relScoped,
                            'scope' => $saveSoc ? 'social_account' : 'user',
                            'error' => $put['error'],
                        ];
                    }
                }
            } else {
                $resp['garage'] = ['saved' => false, 'error' => $fetch['error'] !== '' ? $fetch['error'] : 'Could not fetch image from provider'];
            }
        }
    }
    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!ff_gate_session_ok()) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $cfg = $GLOBALS['CONFIG'];
    $gateTitle = htmlspecialchars((string) ($cfg['site_name'] ?? 'Sign in'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $gateLoginErr = isset($_GET['login_error']) ? (int) $_GET['login_error'] : 0;
    $gateFormAction = htmlspecialchars($_SERVER['SCRIPT_NAME'] ?? '/', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · <?php echo $gateTitle; ?></title>
    <style>
        :root { --bg: #0f1115; --panel: #181b21; --border: #2a2f3a; --text: #e8eaed; --muted: #8b929e; --accent: #3b82f6; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem;
            font-family: system-ui, -apple-system, sans-serif; background: var(--bg); color: var(--text); }
        .card { width: 100%; max-width: 22rem; background: var(--panel); border: 1px solid var(--border); border-radius: 12px; padding: 1.5rem; }
        h1 { margin: 0 0 0.35rem; font-size: 1.15rem; font-weight: 600; }
        p.sub { margin: 0 0 1rem; font-size: 0.8rem; color: var(--muted); line-height: 1.45; }
        label { display: block; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; color: var(--muted); margin-bottom: 0.25rem; }
        input { width: 100%; padding: 0.5rem 0.6rem; border-radius: 8px; border: 1px solid var(--border); background: #12151a; color: var(--text); font: inherit; margin-bottom: 0.75rem; }
        button { width: 100%; padding: 0.55rem; border-radius: 8px; border: none; background: var(--accent); color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
        button:hover { filter: brightness(1.06); }
        .flash { font-size: 0.85rem; padding: 0.5rem 0.65rem; border-radius: 8px; margin-bottom: 1rem; }
        .flash.bad { background: rgba(248, 113, 113, 0.12); color: #fca5a5; border: 1px solid rgba(248, 113, 113, 0.35); }
    </style>
</head>
<body>
    <div class="card">
        <h1><?php echo $gateTitle; ?></h1>
        <p class="sub">Sign in with your PocketBase account. Access is limited to approved email addresses.</p>
        <?php if ($gateLoginErr === 1): ?>
            <div class="flash bad">Invalid email or password.</div>
        <?php elseif ($gateLoginErr === 2): ?>
            <div class="flash bad">This account is not authorized to use this app.</div>
        <?php endif; ?>
        <form method="post" action="<?php echo $gateFormAction; ?>">
            <input type="hidden" name="action" value="login">
            <label for="gate-email">Email</label>
            <input id="gate-email" type="email" name="email" required autocomplete="username" autofocus>
            <label for="gate-pass">Password</label>
            <input id="gate-pass" type="password" name="password" required autocomplete="current-password">
            <button type="submit">Sign in</button>
        </form>
    </div>
</body>
</html>
    <?php
    exit;
}

$CONFIG = $GLOBALS['CONFIG'];
$htmlSiteName = htmlspecialchars((string) ($CONFIG['site_name'] ?? 'Carousel'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$htmlAppVersion = htmlspecialchars((string) ($CONFIG['app_version'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$userEmail = htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$flashIgOk = isset($_GET['ig_ok']);
$flashIgErr = isset($_GET['ig_error']);
$flashLoginErr = isset($_GET['login_error']);
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
$htmlPbAdmin = htmlspecialchars((string) ($CONFIG['pocketbase_admin_url'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$htmlInputMediaCol = htmlspecialchars((string) ($CONFIG['input_media_collection'] ?? 'input_media'), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$htmlIgCronProcessUrl = htmlspecialchars(
    rtrim((string) ($CONFIG['site_url'] ?? ''), '/') . '/index.php?action=process_instagram_schedules&cron_secret=',
    ENT_QUOTES | ENT_HTML5,
    'UTF-8'
);
$slideAiEnabled = cg_env('OPENROUTER_API_KEY') !== false;

$replicateImageEnabled = trim((string) (getenv('REPLICATE_API_TOKEN') ?: '')) !== '';
$aiTabHasAny = $slideAiEnabled || $replicateImageEnabled;

$generateUrl = ($_SERVER['SCRIPT_NAME'] ?? '/index.php') . '?action=generate';
$generateImageUrl = ($_SERVER['SCRIPT_NAME'] ?? '/index.php') . '?action=generate_image';
$garageReady = ff_garage_ready();
$jsonIgAccounts = json_encode($igAccountsList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$jsonFfScript = json_encode($_SERVER['SCRIPT_NAME'] ?? '/index.php', JSON_UNESCAPED_SLASHES);

$paletteNames = ['black', 'light', 'dracula', 'nord', 'night', 'forest'];
$paletteSwatches = [
    'black' => '#000000',
    'light' => '#ffffff',
    'dracula' => '#282a36',
    'nord' => '#eceff4',
    'night' => '#0f172a',
    'forest' => '#171212',
];
$fontPairsTitle = [
    ['DM_Serif_Display', 'DM Serif Display'],
    ['DM_Sans', 'DM Sans'],
    ['Inter', 'Inter'],
    ['Montserrat', 'Montserrat'],
    ['Roboto', 'Roboto'],
    ['Roboto_Condensed', 'Roboto Condensed'],
    ['PT_Serif', 'PT Serif'],
    ['Syne', 'Syne'],
    ['ArchivoBlack', 'Archivo Black'],
    ['Ultra', 'Ultra'],
];
$fontPairsBody = [
    ['DM_Sans', 'DM Sans'],
    ['DM_Serif_Display', 'DM Serif Display'],
    ['Inter', 'Inter'],
    ['Montserrat', 'Montserrat'],
    ['Roboto', 'Roboto'],
    ['Roboto_Condensed', 'Roboto Condensed'],
    ['PT_Serif', 'PT Serif'],
    ['Syne', 'Syne'],
    ['ArchivoBlack', 'Archivo Black'],
    ['Ultra', 'Ultra'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo $htmlSiteName; ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Archivo+Black&family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,600;1,9..40,400&family=DM+Serif+Display&family=Inter:wght@400;600&family=Montserrat:wght@400;600&family=PT+Serif&family=Roboto:wght@400;500&family=Roboto+Condensed:wght@400;700&family=Syne:wght@400;700&family=Ultra&display=swap" rel="stylesheet">
    <style>
:root {
  --cg-shell: #0f1115;
  --cg-panel: #181b21;
  --cg-border: #2a2f3a;
  --cg-muted: #8b929e;
  --cg-accent: #3b82f6;
  --cg-text: #e8eaed;
}
*, *::before, *::after { box-sizing: border-box; }
html {
  height: 100%;
}
body {
  margin: 0;
  height: 100%;
  min-height: 100dvh;
  max-height: 100dvh;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
  background: var(--cg-shell);
  color: var(--cg-text);
  line-height: 1.5;
}
a { color: var(--cg-accent); }
.app-header {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  gap: 1rem;
  padding: 0.75rem 1.25rem;
  border-bottom: 1px solid var(--cg-border);
  background: var(--cg-panel);
  flex-wrap: wrap;
  flex-shrink: 0;
}
.app-header h1 { margin: 0; font-size: 1.1rem; font-weight: 600; }
.app-header-left { display: flex; flex-direction: column; gap: 0.15rem; min-width: 0; }
.flash-banner {
  padding: 0.5rem 1.25rem;
  font-size: 0.85rem;
  border-bottom: 1px solid var(--cg-border);
  flex-shrink: 0;
}
.flash-banner.ok { background: rgba(34, 197, 94, 0.12); color: #86efac; }
.flash-banner.bad { background: rgba(248, 113, 113, 0.12); color: #fca5a5; }
.layout {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  display: grid;
  grid-template-columns: minmax(240px, 300px) minmax(0, 1fr) minmax(260px, 340px);
  grid-template-areas:
    "edit preview sources"
    "scrub scrub scrub";
  grid-template-rows: minmax(0, 1fr) auto;
  gap: 0;
}
.layout > .panel,
.layout > .main,
.layout > .sidebar-right {
  min-height: 0;
}
@media (max-width: 1180px) {
  .layout {
    grid-template-columns: 1fr;
    grid-template-areas:
      "edit"
      "preview"
      "sources"
      "scrub";
    /* Share viewport height between stacked columns; each scrolls inside (panel-body / main / sidebar-inner) */
    grid-template-rows: minmax(0, 1fr) minmax(0, 1fr) minmax(0, 1fr) auto;
  }
}
.panel {
  grid-area: edit;
  border-right: 1px solid var(--cg-border);
  background: #12151a;
  display: flex;
  flex-direction: column;
  max-height: none;
  min-height: 0;
  height: 100%;
  overflow: hidden;
  min-width: 0;
}
.panel > .tabs {
  flex-shrink: 0;
}
@media (max-width: 1180px) {
  .panel {
    border-right: none;
    border-bottom: 1px solid var(--cg-border);
    max-height: none;
    min-height: 0;
  }
}
.sidebar-right {
  grid-area: sources;
  border-left: 1px solid var(--cg-border);
  background: #12151a;
  display: flex;
  flex-direction: column;
  max-height: none;
  height: 100%;
  min-width: 0;
  overflow: hidden;
}
@media (max-width: 1180px) {
  .sidebar-right {
    border-left: none;
    border-top: 1px solid var(--cg-border);
    max-height: none;
    min-height: 0;
  }
}
.sidebar-right > .tabs {
  flex-shrink: 0;
}
.sidebar-right .tabs button {
  font-size: 0.7rem;
  padding: 0.55rem 0.28rem;
}
.sidebar-inner {
  padding: 0;
  overflow-y: auto;
  flex: 1;
  min-height: 0;
}
.sidebar-tab-panel {
  padding: 0.75rem 1rem 1rem;
}
.sidebar-section {
  padding: 0;
  margin: 0;
  border-bottom: none;
}
.sidebar-section + .sidebar-section {
  margin-top: 0.85rem;
  padding-top: 0.85rem;
  border-top: 1px solid var(--cg-border);
}
.sidebar-heading {
  margin: 0 0 0.5rem;
  font-size: 0.7rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.06em;
  color: var(--cg-muted);
}
.sidebar-section .hint { font-size: 0.78rem; line-height: 1.45; }
.sidebar-section .field { margin-bottom: 0.65rem; }
.sidebar-section .field:last-child { margin-bottom: 0; }
.sidebar-ig-list {
  list-style: none;
  padding: 0;
  margin: 0.35rem 0 0;
  font-size: 0.82rem;
}
.sidebar-ig-list li {
  padding: 0.35rem 0;
  border-bottom: 1px solid var(--cg-border);
}
.sidebar-ig-list li:last-child { border-bottom: none; }
.slide-kind-badge {
  opacity: 0.72;
  font-size: 0.72em;
  font-weight: 400;
}
.media-subhead {
  margin: 0.85rem 0 0.4rem;
  font-size: 0.68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--cg-muted);
}
.media-subhead:first-child { margin-top: 0.35rem; }
.media-record-card {
  border: 1px solid var(--cg-border);
  border-radius: 8px;
  padding: 0.45rem 0.5rem;
  margin-bottom: 0.5rem;
  background: rgba(0,0,0,0.15);
}
.media-record-title {
  margin: 0 0 0.35rem;
  font-size: 0.72rem;
  font-weight: 600;
  line-height: 1.35;
  word-break: break-word;
}
.media-entry {
  margin-top: 0.4rem;
}
.media-entry:first-of-type { margin-top: 0; }
.media-thumb {
  display: block;
  max-width: 100%;
  max-height: 140px;
  width: auto;
  height: auto;
  border-radius: 6px;
  object-fit: contain;
  background: #0a0c10;
}
.media-video {
  display: block;
  max-width: 100%;
  max-height: 160px;
  border-radius: 6px;
  background: #000;
}
.media-audio {
  display: block;
  width: 100%;
  margin: 0.15rem 0 0;
}
.media-file-row {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  flex-wrap: wrap;
  font-size: 0.72rem;
}
.tabs {
  display: flex;
  flex-wrap: wrap;
  gap: 0;
  border-bottom: 1px solid var(--cg-border);
}
.tabs button {
  flex: 1;
  min-width: 0;
  padding: 0.6rem 0.5rem;
  border: none;
  background: transparent;
  color: var(--cg-muted);
  font-size: 0.8rem;
  cursor: pointer;
}
.tabs button.active {
  color: var(--cg-text);
  box-shadow: inset 0 -2px 0 var(--cg-accent);
}
.panel-body {
  padding: 1rem;
  overflow-y: auto;
  overflow-x: hidden;
  flex: 1;
  min-height: 0;
  -webkit-overflow-scrolling: touch;
}
.field { margin-bottom: 0.85rem; }
.field label {
  display: block;
  font-size: 0.75rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--cg-muted);
  margin-bottom: 0.25rem;
}
input[type='text'], input[type='email'], input[type='password'], input[type='file'], select, textarea {
  width: 100%;
  padding: 0.45rem 0.55rem;
  border-radius: 6px;
  border: 1px solid var(--cg-border);
  background: var(--cg-panel);
  color: var(--cg-text);
  font-size: 0.9rem;
}
textarea { min-height: 72px; resize: vertical; }
.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.35rem;
  padding: 0.45rem 0.75rem;
  border-radius: 6px;
  border: 1px solid var(--cg-border);
  background: var(--cg-panel);
  color: var(--cg-text);
  font-size: 0.85rem;
  cursor: pointer;
}
.btn:hover { border-color: #3d4554; }
.btn-primary {
  background: var(--cg-accent);
  border-color: #2563eb;
  color: #fff;
}
.btn-primary:hover { filter: brightness(1.05); }
.btn-primary:disabled { opacity: 0.5; cursor: not-allowed; }
.btn-small { padding: 0.25rem 0.45rem; font-size: 0.75rem; }
.slide-list { list-style: none; margin: 0; padding: 0; }
.slide-list li {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.4rem 0;
  border-bottom: 1px solid var(--cg-border);
}
.slide-list li.active {
  background: rgba(59, 130, 246, 0.08);
  margin: 0 -1rem;
  padding-left: 1rem;
  padding-right: 1rem;
}
.slide-list button.ghost {
  border: none;
  background: none;
  color: var(--cg-text);
  cursor: pointer;
  text-align: left;
  flex: 1;
  font-size: 0.88rem;
}
.palette-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0.4rem;
}
.palette-grid button {
  height: 36px;
  border-radius: 6px;
  border: 2px solid transparent;
  cursor: pointer;
}
.palette-grid button.sel { border-color: var(--cg-accent); }
.add-row {
  display: flex;
  flex-wrap: wrap;
  gap: 0.35rem;
  margin-top: 0.75rem;
}
.main {
  grid-area: preview;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 1rem;
  overflow: auto;
  min-width: 0;
  min-height: 0;
  background: var(--cg-shell);
}
.pager {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  font-size: 0.9rem;
  color: var(--cg-muted);
}
.preview-frame {
  width: min(100%, 420px);
  aspect-ratio: 1080 / 1350;
  border-radius: 12px;
  overflow: hidden;
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.45);
  border: 1px solid var(--cg-border);
  position: relative;
}
.preview-inner {
  width: 100%;
  height: 100%;
  padding: 8%;
  display: flex;
  flex-direction: column;
}
.preview-brand {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
  font-size: 0.75rem;
  opacity: 0.85;
}
.preview-brand .avatar {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.15);
  object-fit: cover;
}
.preview-elements {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
  justify-content: center;
}
.preview-text-editable {
  cursor: text;
  outline: none;
  border-radius: 4px;
  min-height: 1.15em;
  word-wrap: break-word;
  transition: box-shadow 0.12s ease;
}
.preview-text-editable:focus {
  box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.45);
}
.preview-placeholder {
  padding: 1rem;
  border: 1px dashed rgba(0, 0, 0, 0.2);
  border-radius: 8px;
  font-size: 0.8rem;
  opacity: 0.7;
}
.page-num {
  position: absolute;
  bottom: 6%;
  right: 8%;
  font-size: 0.75rem;
  opacity: 0.6;
}
.carousel-scrubber-bar {
  grid-area: scrub;
  border-top: 1px solid var(--cg-border);
  background: #12151a;
  flex-shrink: 0;
}
.carousel-scrubber-inner {
  width: 100%;
  max-width: none;
  box-sizing: border-box;
  padding: 0.55rem 1rem 0.65rem;
}
@media (min-width: 1181px) {
  .carousel-scrubber-inner {
    padding-left: 1.25rem;
    padding-right: 1.25rem;
  }
}
.carousel-scrubber-wrap {
  width: 100%;
  margin: 0;
  flex-shrink: 0;
}
.carousel-scrubber-meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 0.5rem 0.75rem;
  margin-bottom: 0.45rem;
  font-size: 0.78rem;
  color: var(--cg-muted);
}
.carousel-scrubber-time {
  font-variant-numeric: tabular-nums;
  color: var(--cg-text, #e8eaed);
  font-weight: 500;
  min-width: 7.5rem;
  text-align: center;
}
.carousel-scrubber-track-wrap {
  position: relative;
  width: 100%;
  padding: 0.35rem 0 0.15rem;
}
.carousel-scrubber-ticks {
  position: absolute;
  left: 0;
  right: 0;
  top: 50%;
  height: 0;
  pointer-events: none;
  transform: translateY(-50%);
}
.carousel-scrub-tick {
  position: absolute;
  width: 1px;
  height: 10px;
  margin-top: -5px;
  background: rgba(139, 146, 158, 0.55);
  transform: translateX(-50%);
}
.carousel-scrubber-range {
  width: 100%;
  height: 8px;
  margin: 0;
  cursor: pointer;
  accent-color: var(--cg-accent, #3b82f6);
  -webkit-appearance: none;
  appearance: none;
  background: transparent;
}
.carousel-scrubber-range::-webkit-slider-runnable-track {
  height: 6px;
  border-radius: 3px;
  background: linear-gradient(
    90deg,
    var(--cg-accent, #3b82f6) 0%,
    var(--cg-accent, #3b82f6) var(--carousel-fill-pct, 0%),
    rgba(255, 255, 255, 0.12) var(--carousel-fill-pct, 0%),
    rgba(255, 255, 255, 0.12) 100%
  );
}
.carousel-scrubber-range::-webkit-slider-thumb {
  -webkit-appearance: none;
  appearance: none;
  width: 14px;
  height: 14px;
  margin-top: -4px;
  border-radius: 50%;
  background: #fff;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.45);
  border: 1px solid var(--cg-border);
}
.carousel-scrubber-range::-moz-range-track {
  height: 6px;
  border-radius: 3px;
  background: rgba(255, 255, 255, 0.12);
}
.carousel-scrubber-range::-moz-range-progress {
  height: 6px;
  border-radius: 3px;
  background: var(--cg-accent, #3b82f6);
}
.carousel-scrubber-range::-moz-range-thumb {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  background: #fff;
  border: 1px solid var(--cg-border);
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.45);
}
.carousel-scrubber-hint {
  width: 100%;
  text-align: center;
  font-size: 0.68rem;
  color: var(--cg-muted);
  margin: 0.35rem 0 0;
  line-height: 1.35;
}
.carousel-still-music {
  width: 100%;
}
.carousel-still-music-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem 0.75rem;
  justify-content: center;
}
.carousel-still-label {
  font-size: 0.72rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--cg-muted);
  margin: 0;
  flex: 0 0 auto;
}
.carousel-still-input {
  flex: 1 1 220px;
  min-width: 0;
  max-width: 36rem;
  padding: 0.45rem 0.55rem;
  border-radius: 6px;
  border: 1px solid var(--cg-border);
  background: var(--cg-panel);
  color: var(--cg-text);
  font: inherit;
}
.err { color: #f87171; font-size: 0.85rem; margin-top: 0.35rem; }
.hint { font-size: 0.8rem; color: var(--cg-muted); margin-top: 0.25rem; }
.pipeline-snippet {
  margin-top: 0.75rem;
  padding: 0.65rem;
  border-radius: 6px;
  border: 1px solid var(--cg-border);
  background: #0d0f14;
  color: #c9d1d9;
  font-size: 0.7rem;
  line-height: 1.45;
  overflow-x: auto;
  white-space: pre-wrap;
  word-break: break-word;
}
.pipeline-snippet code { font-family: ui-monospace, monospace; }
.ai-section-divider {
  margin: 1rem 0;
  border: none;
  border-top: 1px solid var(--cg-border);
}
.ai-subhead {
  margin: 0 0 0.5rem;
  font-size: 0.8rem;
  font-weight: 600;
  color: var(--cg-text);
}
.img-gen-preview {
  margin-top: 0.65rem;
  border-radius: 8px;
  border: 1px solid var(--cg-border);
  max-width: 100%;
  height: auto;
  display: block;
  cursor: grab;
}
.img-gen-preview:active {
  cursor: grabbing;
}
.preview-frame.preview-drop-active {
  box-shadow: 0 0 0 3px var(--cg-accent), 0 12px 40px rgba(0, 0, 0, 0.45);
}
/* Let drops hit the frame (not contenteditable / nested nodes) while dragging in from AI */
.preview-frame.preview-drop-active .preview-inner,
.preview-frame.preview-drop-active .preview-inner * {
  pointer-events: none;
}
.slide-template-drop {
  border-radius: 8px;
  transition: box-shadow 0.15s ease, background 0.15s ease;
}
.slide-template-drop.slide-template-drop--over {
  box-shadow: 0 0 0 2px var(--cg-accent);
  background: rgba(59, 130, 246, 0.08);
}
.slide-templates-visual {
  margin: 0 0 0.75rem;
}
.slide-template-thumb-row {
  display: flex;
  gap: 0.5rem;
  flex-wrap: wrap;
  align-items: flex-start;
}
.slide-template-thumb-cell {
  flex: 1 1 calc(50% - 0.35rem);
  min-width: 112px;
  max-width: 100%;
}
.slide-template-thumb-label {
  font-size: 0.65rem;
  font-weight: 600;
  color: var(--cg-muted);
  margin: 0 0 0.25rem;
  text-transform: uppercase;
  letter-spacing: 0.04em;
}
.slide-template-thumb {
  border-radius: 8px;
  border: 1px solid var(--cg-border);
  overflow: hidden;
  aspect-ratio: 4 / 5;
  max-height: 200px;
  display: flex;
  flex-direction: column;
  pointer-events: none;
  user-select: none;
}
.slide-template-thumb-inner {
  padding: 0.35rem 0.45rem 0.45rem;
  flex: 1;
  min-height: 0;
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
.slide-template-thumb .preview-brand {
  display: flex;
  align-items: center;
  gap: 0.25rem;
  margin-bottom: 0.2rem;
  font-size: 0.45rem;
  line-height: 1.15;
}
.slide-template-thumb .avatar {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  object-fit: cover;
  flex-shrink: 0;
  background: rgba(128, 128, 128, 0.25);
}
.slide-template-thumb .preview-elements {
  flex: 1;
  min-height: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  gap: 0.12rem;
}
.slide-template-thumb .thumb-text-readonly {
  overflow: hidden;
  text-overflow: ellipsis;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  word-break: break-word;
}
.slide-template-thumb .preview-content-img-outer {
  max-height: 42px;
  min-height: 0;
  margin: 0.1rem 0 0;
}
.slide-template-thumb .preview-content-img {
  max-height: 38px;
}
.slide-template-thumb-empty {
  border: 1px dashed var(--cg-border);
  border-radius: 8px;
  aspect-ratio: 4 / 5;
  max-height: 200px;
  display: flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  padding: 0.5rem;
  font-size: 0.65rem;
  color: var(--cg-muted);
  line-height: 1.3;
}
.preview-content-img-outer {
  overflow: hidden;
  border-radius: 8px;
  width: 100%;
  min-height: 56px;
  max-height: 42%;
  display: flex;
  align-items: center;
  justify-content: center;
  margin: 0.15rem 0;
}
.preview-content-img-inner {
  touch-action: none;
  cursor: grab;
}
.preview-content-img-inner:active {
  cursor: grabbing;
}
.preview-content-img {
  max-width: 100%;
  max-height: 140px;
  width: auto;
  height: auto;
  object-fit: cover;
  display: block;
  user-select: none;
  -webkit-user-drag: none;
}
.preview-content-img-block {
  width: 100%;
}
.preview-content-img-toolbar {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  flex-wrap: wrap;
  margin-top: 0.4rem;
  padding: 0.35rem 0.4rem;
  border-radius: 6px;
  background: rgba(0, 0, 0, 0.2);
  border: 1px solid var(--cg-border);
  pointer-events: auto;
}
.preview-frame.preview-drop-active .preview-inner .preview-content-img-toolbar {
  pointer-events: auto;
}
.preview-content-img-size-label {
  font-size: 0.65rem;
  font-weight: 600;
  color: var(--cg-muted);
  white-space: nowrap;
}
.preview-content-img-range {
  flex: 1 1 100px;
  min-width: 72px;
  accent-color: var(--cg-accent, #3b82f6);
}
.preview-content-img-size-val {
  font-size: 0.65rem;
  font-variant-numeric: tabular-nums;
  min-width: 2.5rem;
  color: var(--cg-text);
}
.preview-content-img-quick {
  display: flex;
  gap: 0.25rem;
}
.content-img-transform-fields {
  margin-top: 0.45rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
  font-size: 0.72rem;
  color: var(--cg-muted);
}
.content-img-transform-fields label {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
}
.content-img-transform-fields input[type="range"] {
  width: 100%;
}
[x-cloak] { display: none !important; }
    </style>
</head>
<body>
    <header class="app-header">
        <div class="app-header-left">
            <h1><?php echo $htmlSiteName; ?></h1>
            <span class="hint" style="margin:0;">Edit slides in the left column · sources &amp; account on the right<?php if ($htmlAppVersion !== ''): ?> · <?php echo $htmlAppVersion; ?><?php endif; ?></span>
        </div>
    </header>
    <?php if ($flashLoginErr): ?><div class="flash-banner bad">Sign-in failed.</div><?php endif; ?>
    <?php if ($flashIgOk): ?><div class="flash-banner ok">Instagram account linked.</div><?php endif; ?>
    <?php if ($flashIgErr): ?><div class="flash-banner bad">Instagram connection failed.</div><?php endif; ?>

    <div class="layout" x-data="carouselApp()">
        <aside class="panel">
            <div class="tabs">
                <button type="button" :class="{ active: tab === 'slides' }" @click="tab = 'slides'">Slides</button>
                <button type="button" :class="{ active: tab === 'settings' }" @click="tab = 'settings'">Settings</button>
                <button type="button" :class="{ active: tab === 'ai' }" @click="tab = 'ai'">AI</button>
                <button type="button" :class="{ active: tab === 'data' }" @click="tab = 'data'">Data</button>
                <button type="button" :class="{ active: tab === 'video' }" @click="tab = 'video'">Video</button>
            </div>

            <div class="panel-body" x-show="tab === 'slides'" x-cloak>
                <div class="field" x-show="igAccounts.length" style="margin-bottom:0.65rem;">
                    <label>Instagram scope (templates &amp; Garage)</label>
                    <select x-model="garageIgId" @change="onGarageIgScopeChange()" style="width:100%;">
                        <template x-for="a in igAccounts" :key="a.id">
                            <option :value="a.id" x-text="'@' + (a.username || a.instagram_user_id || a.id)"></option>
                        </template>
                    </select>
                    <p class="hint" style="margin:0.35rem 0 0;">Intro/outro templates are saved <strong>per account</strong>. Drag an image from <strong>Media</strong> (right) onto the Intro or Outro boxes below. Thumbnails use your current <strong>Settings</strong> theme.</p>
                </div>
                <p class="hint" x-show="!igAccounts.length" style="margin-bottom:0.65rem;">Link Instagram in <strong>Socials</strong> to scope intro/outro templates per account.</p>

                <div class="slide-templates-visual" x-show="garageIgId && igAccounts.length" x-cloak>
                    <p class="hint" style="margin:0 0 0.45rem;">Templates for <strong x-text="igAccountScopeLabel()"></strong> — drag an image from <strong>Media</strong> here.</p>
                    <div class="slide-template-thumb-row">
                        <div class="slide-template-thumb-cell slide-template-drop"
                            :class="{ 'slide-template-drop--over': draggingOverTpl === 'intro' }"
                            @dragenter.prevent="draggingOverTpl = 'intro'"
                            @dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
                            @drop.prevent="draggingOverTpl = ''; onTemplateImageDrop($event, 'intro')">
                            <div class="slide-template-thumb-label">Intro</div>
                            <template x-if="slideTemplates.intro">
                                <div class="slide-template-thumb" :style="previewStyle()">
                                    <div class="slide-template-thumb-inner">
                                        <div class="preview-brand" :style="{ color: doc.config.theme.primary }">
                                            <template x-if="doc.config.brand.avatar.source.src">
                                                <img class="avatar" :src="doc.config.brand.avatar.source.src" alt="">
                                            </template>
                                            <template x-if="!doc.config.brand.avatar.source.src">
                                                <div class="avatar"></div>
                                            </template>
                                            <div>
                                                <div x-text="doc.config.brand.name" style="font-weight:600;"></div>
                                                <div x-text="doc.config.brand.handle" style="opacity:0.75;font-size:0.85em;"></div>
                                            </div>
                                        </div>
                                        <div class="preview-elements" :style="{ color: doc.config.theme.primary }">
                                            <template x-for="(el, ei) in (slideTemplates.intro.elements || [])" :key="'intro-tpl-' + ei">
                                                <div>
                                                    <template x-if="el.type === 'Title' || el.type === 'Subtitle' || el.type === 'Description'">
                                                        <div class="thumb-text-readonly" :style="elementStyleThumb(el)" x-text="el.text"></div>
                                                    </template>
                                                    <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && el.source && el.source.src">
                                                        <div class="preview-content-img-outer" x-init="ensureContentImageStyle(el)">
                                                            <div class="preview-content-img-inner" :style="contentImageTransformStyle(el)">
                                                                <img class="preview-content-img" :src="el.source.src" alt="" draggable="false">
                                                            </div>
                                                        </div>
                                                    </template>
                                                    <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && (!el.source || !el.source.src)">
                                                        <div class="preview-placeholder" style="font-size:0.45rem;padding:0.2rem;">No image</div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!slideTemplates.intro">
                                <div class="slide-template-thumb-empty">Drop an image from Media (right)</div>
                            </template>
                        </div>
                        <div class="slide-template-thumb-cell slide-template-drop"
                            :class="{ 'slide-template-drop--over': draggingOverTpl === 'outro' }"
                            @dragenter.prevent="draggingOverTpl = 'outro'"
                            @dragover.prevent="$event.dataTransfer.dropEffect = 'copy'"
                            @drop.prevent="draggingOverTpl = ''; onTemplateImageDrop($event, 'outro')">
                            <div class="slide-template-thumb-label">Outro</div>
                            <template x-if="slideTemplates.outro">
                                <div class="slide-template-thumb" :style="previewStyle()">
                                    <div class="slide-template-thumb-inner">
                                        <div class="preview-brand" :style="{ color: doc.config.theme.primary }">
                                            <template x-if="doc.config.brand.avatar.source.src">
                                                <img class="avatar" :src="doc.config.brand.avatar.source.src" alt="">
                                            </template>
                                            <template x-if="!doc.config.brand.avatar.source.src">
                                                <div class="avatar"></div>
                                            </template>
                                            <div>
                                                <div x-text="doc.config.brand.name" style="font-weight:600;"></div>
                                                <div x-text="doc.config.brand.handle" style="opacity:0.75;font-size:0.85em;"></div>
                                            </div>
                                        </div>
                                        <div class="preview-elements" :style="{ color: doc.config.theme.primary }">
                                            <template x-for="(el, ei) in (slideTemplates.outro.elements || [])" :key="'outro-tpl-' + ei">
                                                <div>
                                                    <template x-if="el.type === 'Title' || el.type === 'Subtitle' || el.type === 'Description'">
                                                        <div class="thumb-text-readonly" :style="elementStyleThumb(el)" x-text="el.text"></div>
                                                    </template>
                                                    <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && el.source && el.source.src">
                                                        <div class="preview-content-img-outer" x-init="ensureContentImageStyle(el)">
                                                            <div class="preview-content-img-inner" :style="contentImageTransformStyle(el)">
                                                                <img class="preview-content-img" :src="el.source.src" alt="" draggable="false">
                                                            </div>
                                                        </div>
                                                    </template>
                                                    <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && (!el.source || !el.source.src)">
                                                        <div class="preview-placeholder" style="font-size:0.45rem;padding:0.2rem;">No image</div>
                                                    </template>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                            </template>
                            <template x-if="!slideTemplates.outro">
                                <div class="slide-template-thumb-empty">Drop an image from Media (right)</div>
                            </template>
                        </div>
                    </div>
                    <p class="hint" x-show="templateMsg" x-text="templateMsg" style="margin:0.45rem 0 0;color:#86efac;"></p>
                </div>

                <p class="hint">Select a slide, edit text, reorder or add.</p>
                <ul class="slide-list">
                    <template x-for="(s, i) in doc.slides" :key="i">
                        <li :class="{ active: currentIndex === i }">
                            <button type="button" class="ghost" @click="goToSlide(i)">
                                <span x-text="'Slide ' + (i + 1)"></span><span class="slide-kind-badge"
                                    x-text="s.mediaKind === 'video' ? ' · video' : ' · still'"></span>
                            </button>
                            <button type="button" class="btn btn-small" @click="moveSlide(i, -1)" title="Up">↑</button>
                            <button type="button" class="btn btn-small" @click="moveSlide(i, 1)" title="Down">↓</button>
                            <button type="button" class="btn btn-small" @click="removeSlide(i)" title="Remove">×</button>
                        </li>
                    </template>
                </ul>
                <div class="add-row">
                    <button type="button" class="btn btn-small" @click="addSlide('intro')">+ Intro</button>
                    <button type="button" class="btn btn-small" @click="addSlide('common')">+ Common</button>
                    <button type="button" class="btn btn-small" @click="addSlide('content')">+ Content</button>
                    <button type="button" class="btn btn-small" @click="addSlide('outro')">+ Outro</button>
                </div>
                <template x-if="slide">
                    <div style="margin-top:1rem;">
                        <div class="field">
                            <label>Slide type</label>
                            <select x-model="slide.mediaKind" @change="carouselStop()">
                                <option value="still">Still (image hold + optional music)</option>
                                <option value="video">Video segment (per-slide scrubber)</option>
                            </select>
                            <p class="hint" style="margin:0.35rem 0 0;">Stills use per-slide music; video slides use the bottom scrubber for <strong>this slide’s</strong> segment only.</p>
                        </div>
                        <div class="field" x-show="slide.mediaKind === 'still'">
                            <label>Music for this slide</label>
                            <input type="text" x-model="slide.musicPath" placeholder="./music/track.mp3 or https://…">
                        </div>
                        <div class="add-row" style="margin:0.35rem 0 0.5rem;align-items:center;">
                            <button type="button" class="btn btn-small" @click="addContentImageElement()" title="Append empty content image block">+ Content image</button>
                            <span class="hint" style="margin:0;">Adds an empty block — paste URL, or drop onto the center preview.</span>
                        </div>
                        <template x-for="(el, ei) in slide.elements" :key="ei">
                            <div class="field">
                                <template x-if="el.type === 'Title' || el.type === 'Subtitle' || el.type === 'Description'">
                                    <label x-text="el.type"></label>
                                </template>
                                <template x-if="el.type === 'ContentImage' || el.type === 'Image'">
                                    <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.25rem;">
                                        <label x-text="el.type" style="margin:0;"></label>
                                        <button type="button" class="btn btn-small" @click="removeContentImageElementAt(ei)" title="Remove this content image block">×</button>
                                    </div>
                                </template>
                                <template x-if="el.type === 'Title' || el.type === 'Subtitle' || el.type === 'Description'">
                                    <textarea x-model="el.text" rows="3"></textarea>
                                </template>
                                <template x-if="el.type === 'ContentImage' || el.type === 'Image'">
                                    <div>
                                        <div style="display:flex;gap:0.35rem;align-items:center;flex-wrap:wrap;">
                                            <input type="text" style="flex:1;min-width:8rem;" x-model="el.source.src" placeholder="Image URL (optional)">
                                            <button type="button" class="btn btn-small" x-show="el.source && el.source.src"
                                                @click="clearContentImage(el)">Clear URL</button>
                                        </div>
                                        <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && el.source && el.source.src">
                                            <div class="content-img-transform-fields" x-init="ensureContentImageStyle(el)">
                                                <label>Size (scale) <span x-text="Math.round(Number(el.style.imgScale) || 100) + '%'"></span>
                                                    <input type="range" min="25" max="400" step="1"
                                                        x-model.number="el.style.imgScale">
                                                </label>
                                                <label>Rotate <span x-text="(Number(el.style.imgRotateDeg) || 0) + '°'"></span>
                                                    <input type="range" min="-180" max="180" step="1"
                                                        x-model.number="el.style.imgRotateDeg">
                                                </label>
                                                <button type="button" class="btn btn-small" style="align-self:flex-start;margin-top:0.15rem;"
                                                    @click="resetContentImageTransform(el)">Reset placement</button>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <div class="panel-body" x-show="tab === 'settings'" x-cloak>
                <div class="field">
                    <label>File name</label>
                    <input type="text" x-model="doc.filename">
                </div>
                <div class="field">
                    <label>Brand name</label>
                    <input type="text" x-model="doc.config.brand.name">
                </div>
                <div class="field">
                    <label>Handle</label>
                    <input type="text" x-model="doc.config.brand.handle">
                </div>
                <div class="field">
                    <label>Avatar image URL</label>
                    <input type="text" x-model="doc.config.brand.avatar.source.src" placeholder="https://…">
                </div>
                <div class="field">
                    <label>Theme preset</label>
                    <div class="palette-grid">
                        <?php foreach ($paletteNames as $name): ?>
                        <button type="button"
                            :class="{ sel: doc.config.theme.pallette === '<?= htmlspecialchars($name, ENT_QUOTES) ?>' && !doc.config.theme.isCustom }"
                            @click="setPalette('<?= htmlspecialchars($name, ENT_QUOTES) ?>')"
                            title="<?= htmlspecialchars($name) ?>"
                            style="background: <?= htmlspecialchars($paletteSwatches[$name] ?? '#333', ENT_QUOTES) ?>"></button>
                        <?php endforeach; ?>
                    </div>
                    <button type="button" class="btn btn-small" style="margin-top:0.5rem;" @click="setCustomMode()">Custom colors</button>
                </div>
                <template x-if="doc.config.theme.isCustom">
                    <div>
                        <div class="field">
                            <label>Primary (text)</label>
                            <input type="text" x-model="doc.config.theme.primary">
                        </div>
                        <div class="field">
                            <label>Secondary</label>
                            <input type="text" x-model="doc.config.theme.secondary">
                        </div>
                        <div class="field">
                            <label>Background</label>
                            <input type="text" x-model="doc.config.theme.background">
                        </div>
                    </div>
                </template>
                <div class="field">
                    <label>Title font</label>
                    <select x-model="doc.config.fonts.font1">
                        <?php foreach ($fontPairsTitle as [$fid, $flabel]): ?>
                        <option value="<?= htmlspecialchars($fid, ENT_QUOTES) ?>"><?= htmlspecialchars($flabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>Body font</label>
                    <select x-model="doc.config.fonts.font2">
                        <?php foreach ($fontPairsBody as [$fid, $flabel]): ?>
                        <option value="<?= htmlspecialchars($fid, ENT_QUOTES) ?>"><?= htmlspecialchars($flabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label>
                        <input type="checkbox" x-model="doc.config.pageNumber.showNumbers">
                        Show slide numbers on preview
                    </label>
                </div>
            </div>

            <div class="panel-body" x-show="tab === 'ai'" x-cloak>
                <?php if (!$aiTabHasAny): ?>
                <p class="hint">Set <code>OPENROUTER_API_KEY</code> for slide text (OpenRouter only), and/or <code>REPLICATE_API_TOKEN</code> for images (Nano Banana Pro).</p>
                <?php else: ?>
                <?php if ($slideAiEnabled): ?>
                <p class="ai-subhead">Slide copy</p>
                <div class="field">
                    <label>Topic or outline</label>
                    <textarea x-model="aiPrompt" rows="5" placeholder="e.g. 5 tips for better LinkedIn posts…"></textarea>
                </div>
                <button type="button" class="btn btn-primary" @click="generateAi()" :disabled="aiLoading">
                    <span x-show="!aiLoading">Generate slides</span>
                    <span x-show="aiLoading">Working…</span>
                </button>
                <p class="err" x-show="aiError" x-text="aiError"></p>
                <p class="hint">Replaces all slides with AI output (at most 10 — Instagram carousel limit). You can edit after.</p>
                <?php endif; ?>

                <?php if ($replicateImageEnabled): ?>
                <?php if ($slideAiEnabled): ?><hr class="ai-section-divider"><?php endif; ?>
                <p class="ai-subhead">Image (Replicate · Nano Banana Pro)</p>
                <p class="hint" style="margin-top:0;">Describe the image; optionally add a reference photo or URL so the model can match or edit from it.</p>
                <div class="field">
                    <label>Image prompt</label>
                    <textarea x-model="imgGenPrompt" rows="4" placeholder="e.g. LinkedIn carousel slide with bold title…"></textarea>
                </div>
                <div class="field">
                    <label>Context image (optional)</label>
                    <input type="file" accept="image/jpeg,image/png,image/webp,image/gif" x-ref="imgGenFile" @change="onImgGenFile($event)">
                    <p class="hint" style="margin:0.35rem 0 0;">Or paste a public <code>https://</code> image URL:</p>
                    <input type="text" style="margin-top:0.35rem;" x-model="imgGenUrl" placeholder="https://…">
                </div>
                <div class="field">
                    <label>Aspect ratio</label>
                    <select x-model="imgGenAspect">
                        <option value="4:5">4:5 (carousel)</option>
                        <option value="1:1">1:1</option>
                        <option value="9:16">9:16</option>
                        <option value="16:9">16:9</option>
                        <option value="4:3">4:3</option>
                        <option value="3:4">3:4</option>
                        <option value="match_input_image">Match context image</option>
                    </select>
                </div>
                <div class="field">
                    <label>Resolution</label>
                    <select x-model="imgGenResolution">
                        <option value="1K">1K</option>
                        <option value="2K">2K</option>
                        <option value="4K">4K</option>
                    </select>
                </div>
                <button type="button" class="btn btn-primary" @click="generateImageAi()" :disabled="imgGenLoading">
                    <span x-show="!imgGenLoading">Generate image</span>
                    <span x-show="imgGenLoading">Generating…</span>
                </button>
                <p class="err" x-show="imgGenError" x-text="imgGenError"></p>
                <template x-if="imgGenResultUrl">
                    <div style="margin-top:0.65rem;">
                        <img class="img-gen-preview" :src="imgGenResultUrl" alt="Generated"
                            draggable="true"
                            @dragstart="onImgGenDragStart($event)"
                            @dragend="onImgGenDragEnd($event)">
                        <p class="hint" style="margin:0.35rem 0 0;">Drag onto the <strong>slide preview</strong> (center) or use the button.</p>
                        <div class="add-row" style="margin-top:0.5rem;">
                            <button type="button" class="btn btn-small" @click="applyImgGenToCurrentSlide()">Set as current slide content image</button>
                            <a class="btn btn-small" :href="imgGenResultUrl" target="_blank" rel="noopener noreferrer">Open</a>
                        </div>
                    </div>
                </template>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="panel-body" x-show="tab === 'data'" x-cloak>
                <button type="button" class="btn btn-primary" @click="exportJson()">Export JSON</button>
                <div class="field" style="margin-top:1rem;">
                    <label>Import JSON</label>
                    <input type="file" accept="application/json,.json" @change="onImportFile($event)">
                </div>
                <p class="err" x-show="importError" x-text="importError"></p>
                <p class="hint">Document is also saved automatically in your browser (localStorage).</p>
            </div>

            <div class="panel-body" x-show="tab === 'video'" x-cloak>
                <p class="hint"><strong>Video pipeline</strong> (local): <strong>Playwright</strong> screenshots each slide at 1080×1350, then <strong>FFmpeg</strong> builds an MP4. Optional music is muxed with <code>-shortest</code> (video or audio ends first).</p>
                <div class="field">
                    <label>Seconds per slide (hold duration)</label>
                    <input type="number" min="0.5" max="120" step="0.5" x-model.number="videoSeconds">
                    <p class="hint" style="margin:0.35rem 0 0;">Each slide occupies this long in the exported MP4. The preview scrubber (video slides) spans <strong>one slide at a time</strong> using this duration.</p>
                </div>
                <div class="field">
                    <label>Default music path (optional)</label>
                    <input type="text" x-model="videoMusicPath" placeholder="./music/your-track.mp3">
                    <p class="hint" style="margin:0.35rem 0 0;"><strong>Still</strong> slides can set their own music in the bottom bar or Slides tab (<code>musicPath</code> in exported JSON). This field is the pipeline <code>--music</code> default when you run the script below.</p>
                </div>
                <div class="field">
                    <label>Output video path</label>
                    <input type="text" x-model="videoOutPath" placeholder="../out/carousel.mp4">
                </div>
                <button type="button" class="btn btn-primary" @click="exportJson()">Export JSON</button>
                <p class="hint">Save the file as <code>carousel-doc.json</code> in the project root (next to <code>video-pipeline/</code>), or change the path in the script below.</p>
                <button type="button" class="btn btn-primary" style="margin-top:0.5rem" @click="copyPipelineCommand()">Copy terminal commands</button>
                <p class="hint" x-show="pipelineCopyOk" x-text="pipelineCopyOk"></p>
                <pre class="pipeline-snippet"><code x-text="pipelineShellScript()"></code></pre>
                <p class="hint">Requires Node.js, <code>npm</code>, <code>ffmpeg</code> on your PATH, and network access for Google Fonts while rendering.</p>
            </div>
        </aside>

        <main class="main">
            <div class="pager">
                <button type="button" class="btn btn-small" @click="carouselGoPrev()">Prev</button>
                <span x-text="(currentIndex + 1) + ' / ' + doc.slides.length"></span>
                <button type="button" class="btn btn-small" @click="carouselGoNext()">Next</button>
            </div>
            <div class="preview-frame"
                :class="{ 'preview-drop-active': previewDropActive }"
                :style="previewStyle()"
                @dragenter.prevent="onPreviewDragEnter($event)"
                @dragleave.prevent="onPreviewDragLeave($event)">
                <div class="preview-inner">
                    <div class="preview-brand" :style="{ color: doc.config.theme.primary }">
                        <template x-if="doc.config.brand.avatar.source.src">
                            <img class="avatar" :src="doc.config.brand.avatar.source.src" alt="">
                        </template>
                        <template x-if="!doc.config.brand.avatar.source.src">
                            <div class="avatar"></div>
                        </template>
                        <div>
                            <div x-text="doc.config.brand.name" style="font-weight:600;"></div>
                            <div x-text="doc.config.brand.handle" style="opacity:0.75;font-size:0.85em;"></div>
                        </div>
                    </div>
                    <div class="preview-elements" :style="{ color: doc.config.theme.primary }">
                        <template x-for="(el, ei) in slide.elements" :key="ei">
                            <div>
                                <template x-if="el.type === 'Title' || el.type === 'Subtitle' || el.type === 'Description'">
                                    <div
                                        class="preview-text-editable"
                                        contenteditable="true"
                                        spellcheck="true"
                                        role="textbox"
                                        :data-preview-type="el.type"
                                        :style="elementStyle(el)"
                                        :aria-label="'Edit ' + el.type"
                                        @input="el.text = $event.target.textContent"
                                        @blur="el.text = ($event.target.textContent || '').trim()"
                                        @keydown.escape.prevent="$event.target.blur()"
                                        @paste.prevent="(function(ev, el){ var t = (ev.clipboardData || window.clipboardData).getData('text/plain'); document.execCommand('insertText', false, t); el.text = ev.target.textContent; })($event, el)"
                                        x-effect="if (document.activeElement !== $el) { var s = el.text == null ? '' : String(el.text); if ($el.textContent !== s) { $el.textContent = s; } }"
                                    ></div>
                                </template>
                                <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && el.source && el.source.src">
                                    <div class="preview-content-img-block">
                                        <div class="preview-content-img-outer"
                                            @wheel.prevent="onContentImgWheel(ei, $event)">
                                            <div class="preview-content-img-inner"
                                                :style="contentImageTransformStyle(el)"
                                                @pointerdown="contentImgPointerDown(ei, $event)">
                                                <img class="preview-content-img" :src="el.source.src" alt="" draggable="false">
                                            </div>
                                        </div>
                                        <div class="preview-content-img-toolbar" x-init="ensureContentImageStyle(el)">
                                            <span class="preview-content-img-size-label">Resize</span>
                                            <input type="range" class="preview-content-img-range" min="25" max="400" step="1"
                                                x-model.number="el.style.imgScale"
                                                @input="ensureContentImageStyle(el)"
                                                title="Image size">
                                            <span class="preview-content-img-size-val" x-text="Math.round(Number(el.style.imgScale) || 100) + '%'"></span>
                                            <div class="preview-content-img-quick">
                                                <button type="button" class="btn btn-small" @click="nudgeContentImgScale(el, -10)" title="Smaller">−</button>
                                                <button type="button" class="btn btn-small" @click="nudgeContentImgScale(el, 10)" title="Larger">+</button>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                                <template x-if="(el.type === 'ContentImage' || el.type === 'Image') && (!el.source || !el.source.src)">
                                    <div class="preview-placeholder">Content image — drop from AI pane or set in <strong>Slides</strong> (left)</div>
                                </template>
                            </div>
                        </template>
                    </div>
                    <p class="hint" style="margin:0.35rem 0 0;font-size:0.65rem;" x-show="slideHasContentImageWithSrc()">
                        Content image: use <strong>Resize</strong> below, or wheel = scale · drag = pan · Shift+wheel = rotate. Slides tab has the same controls.
                    </p>
                </div>
                <div class="page-num" x-show="doc.config.pageNumber.showNumbers"
                    :style="{ color: doc.config.theme.primary }"
                    x-text="(currentIndex + 1) + ' / ' + doc.slides.length"></div>
            </div>
        </main>

        <aside class="sidebar-right" aria-label="External sources, account, and APIs">
            <div class="tabs">
                <button type="button" :class="{ active: rightTab === 'account' }" @click="rightTab = 'account'">Account</button>
                <button type="button" :class="{ active: rightTab === 'socials' }" @click="rightTab = 'socials'">Socials</button>
                <button type="button" :class="{ active: rightTab === 'media' }" @click="rightTab = 'media'">Media</button>
                <button type="button" :class="{ active: rightTab === 'debug' }" @click="rightTab = 'debug'">Debug</button>
            </div>
            <div class="sidebar-inner">
                <div class="sidebar-tab-panel" x-show="rightTab === 'account'" x-cloak>
                    <section class="sidebar-section">
                        <h3 class="sidebar-heading">Account</h3>
                        <?php if ($user): ?>
                        <p class="hint" style="margin:0 0 0.5rem;word-break:break-all;"><strong><?php echo $userEmail !== '' ? $userEmail : 'Signed in'; ?></strong></p>
                        <form method="post" class="field" style="margin:0;">
                            <input type="hidden" name="action" value="logout">
                            <button type="submit" class="btn btn-small" style="width:100%;">Log out</button>
                        </form>
                        <?php else: ?>
                        <p class="hint" style="margin-top:0;">PocketBase user — sign in to use Instagram linking and Garage-backed media.</p>
                        <form method="post">
                            <input type="hidden" name="action" value="login">
                            <div class="field">
                                <label>Email</label>
                                <input type="email" name="email" required autocomplete="username">
                            </div>
                            <div class="field">
                                <label>Password</label>
                                <input type="password" name="password" required autocomplete="current-password">
                            </div>
                            <button type="submit" class="btn btn-primary" style="width:100%;">Sign in</button>
                        </form>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="sidebar-tab-panel" x-show="rightTab === 'socials'" x-cloak>
                    <section class="sidebar-section">
                        <h3 class="sidebar-heading">Socials</h3>
                        <?php if (!$user): ?>
                        <p class="hint" style="margin:0;">Connect Meta/Instagram after you sign in. Tokens and usernames are stored in PocketBase <code>social_accounts</code>.</p>
                        <?php else: ?>
                            <?php if ($fbConfigured): ?>
                            <p class="hint" style="margin-top:0;">
                                <a class="btn btn-small btn-primary" style="display:inline-flex;width:100%;justify-content:center;box-sizing:border-box;" href="/?instagram_oauth=1"><?php echo count($igAccountsList) > 0 ? 'Add or refresh Instagram' : 'Connect Instagram'; ?></a>
                            </p>
                            <?php else: ?>
                            <p class="hint" style="margin-top:0;">Set <code>FB_APP_ID</code> and <code>FB_APP_SECRET</code> in <code>.env</code> to enable OAuth.</p>
                            <?php endif; ?>
                            <?php if ($igAccountsList !== []): ?>
                            <p class="hint" style="margin:0.5rem 0 0.15rem;">Linked accounts</p>
                            <ul class="sidebar-ig-list">
                                <?php foreach ($igAccountsList as $a): ?>
                                <li>
                                    @<?php echo htmlspecialchars((string) ($a['username'] ?: $a['instagram_user_id']), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?>
                                    <?php if (empty($a['is_active'])): ?><span class="hint"> · inactive</span><?php endif; ?>
                                </li>
                                <?php endforeach; ?>
                            </ul>
                            <?php elseif ($fbConfigured): ?>
                            <p class="hint" style="margin:0.5rem 0 0;">No Instagram accounts linked yet.</p>
                            <?php endif; ?>
                            <?php if ($igAccountsList !== []): ?>
                            <div class="sidebar-ig-schedule" style="margin-top:0.85rem;padding:0.75rem 0.55rem 0.85rem;border-top:1px solid rgba(255,255,255,0.08);border-radius:8px;background:rgba(0,0,0,0.14);">
                                <p class="hint" style="margin:0 0 0.45rem;font-weight:600;">Schedule this carousel</p>
                                <p class="hint" style="margin:0 0 0.5rem;font-size:0.72rem;">Queues the <strong>current document</strong> after rendering each still slide to a <strong>JPEG</strong> on the server (Node + Playwright in <code>video-pipeline/</code>). Images inside slides are inlined or fetched with your session, then Meta downloads the uploaded files via signed URLs (<code>CRON_SECRET</code> + <code>FF_CRON_PB_TOKEN</code>). Server needs <code>node</code> on PATH and Chromium in <code>video-pipeline/.playwright-browsers</code> (see <code>.env.example</code>; php-fpm cannot use only your user’s <code>~/.cache</code>). <strong>Cron</strong> (GET, often every minute): <code style="word-break:break-all;display:block;margin-top:0.25rem;"><?php echo $htmlIgCronProcessUrl; ?><span style="user-select:all;">CRON_SECRET</span></code> The date and time you pick are in <strong>your device timezone</strong>, then sent to the server as <strong>UTC</strong> (preview below). Set <code>FF_IG_USE_SLIDE_RENDER=0</code> only for the legacy “raw image URL” mode.</p>
                                <div class="field" style="margin-bottom:0.5rem;">
                                    <label>Instagram account</label>
                                    <select x-model="garageIgId" @change="refreshInstagramSchedules()" style="width:100%;">
                                        <template x-for="a in igAccounts" :key="a.id">
                                            <option :value="a.id" x-text="'@' + (a.username || a.instagram_user_id || a.id)"></option>
                                        </template>
                                    </select>
                                </div>
                                <div class="field" style="margin-bottom:0.5rem;">
                                    <label>Caption (optional, max 2200)</label>
                                    <textarea x-model="igScheduleCaption" rows="5" maxlength="2200" placeholder="Post caption…" style="width:100%;box-sizing:border-box;min-height:5.5rem;"></textarea>
                                </div>
                                <div class="field" style="margin-bottom:0.5rem;">
                                    <label>Publish at <span class="hint" style="font-weight:400;font-size:0.72rem;" x-show="igScheduleTzLabel()" x-text="'· ' + igScheduleTzLabel()"></span></label>
                                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:0.35rem;margin-bottom:0.45rem;">
                                        <button type="button" class="btn btn-small" style="min-height:2.1rem;" @click="igSchedulePresetMinutes(15)">+15 min</button>
                                        <button type="button" class="btn btn-small" style="min-height:2.1rem;" @click="igSchedulePresetMinutes(60)">+1 hour</button>
                                        <button type="button" class="btn btn-small" style="min-height:2.1rem;" @click="igSchedulePresetMinutes(180)">+3 hours</button>
                                        <button type="button" class="btn btn-small" style="min-height:2.1rem;" @click="igSchedulePresetMinutes(1440)">+24 hours</button>
                                        <button type="button" class="btn btn-small" style="min-height:2.1rem;grid-column:1 / -1;" @click="igSchedulePresetTomorrowAt(9, 0)">Tomorrow 9:00</button>
                                        <button type="button" class="btn btn-small" style="min-height:2.1rem;grid-column:1 / -1;" @click="igSchedulePresetNextWeekdayAt(1, 9, 0)">Next Monday 9:00</button>
                                    </div>
                                    <div style="display:grid;grid-template-columns:1fr;gap:0.45rem;">
                                        <div>
                                            <label class="hint" style="display:block;margin:0 0 0.2rem;font-size:0.72rem;">Date</label>
                                            <input type="date" x-model="igScheduleDate" :min="igScheduleMinDateStr()" style="width:100%;box-sizing:border-box;padding:0.45rem 0.5rem;border-radius:6px;border:1px solid rgba(255,255,255,0.18);background:rgba(0,0,0,0.25);color:inherit;font-size:0.9rem;">
                                        </div>
                                        <div>
                                            <label class="hint" style="display:block;margin:0 0 0.2rem;font-size:0.72rem;">Time</label>
                                            <input type="time" x-model="igScheduleTime" step="60" style="width:100%;box-sizing:border-box;padding:0.45rem 0.5rem;border-radius:6px;border:1px solid rgba(255,255,255,0.18);background:rgba(0,0,0,0.25);color:inherit;font-size:0.9rem;">
                                        </div>
                                    </div>
                                    <div class="hint" style="margin-top:0.45rem;padding:0.45rem 0.5rem;border-radius:6px;background:rgba(0,0,0,0.2);font-size:0.7rem;line-height:1.45;" x-show="igSchedulePreviewLocal()">
                                        <div><strong>Local</strong> <span x-text="igSchedulePreviewLocal()"></span></div>
                                        <div style="margin-top:0.25rem;word-break:break-word;"><strong>UTC (stored)</strong> <code style="font-size:0.68rem;" x-text="igSchedulePreviewUtcIso()"></code></div>
                                    </div>
                                </div>
                                <div style="display:flex;gap:0.45rem;flex-wrap:wrap;align-items:stretch;">
                                    <button type="button" class="btn btn-primary" style="flex:1 1 8rem;min-width:0;" @click="scheduleInstagramCarousel()" :disabled="igScheduleLoading || !garageIgId">
                                        <span x-show="!igScheduleLoading">Schedule</span>
                                        <span x-show="igScheduleLoading">Working…</span>
                                    </button>
                                    <button type="button" class="btn" style="flex:1 1 8rem;min-width:0;border-color:rgba(255,255,255,0.22);" @click="postInstagramCarouselNow()" :disabled="igScheduleLoading || !garageIgId" title="Publish immediately (no cron)">
                                        <span x-show="!igScheduleLoading">Post now</span>
                                        <span x-show="igScheduleLoading">Working…</span>
                                    </button>
                                </div>
                                <p class="hint" style="margin:0.35rem 0 0;font-size:0.68rem;line-height:1.4;"><strong>Post now</strong> renders uploads and calls Meta in this HTTP request unless <code>FF_IG_POST_NOW_BACKGROUND=1</code> (early response can leave items stuck as Scheduled on some hosts). <strong>Schedule</strong> still needs cron for the chosen time.</p>
                                <p class="err" style="margin:0.45rem 0 0;" x-show="igScheduleErr" x-text="igScheduleErr"></p>
                                <p class="hint" style="margin:0.35rem 0 0;color:var(--cg-accent);" x-show="igScheduleMsg" x-text="igScheduleMsg"></p>
                                <p class="hint" style="margin:0.65rem 0 0.35rem;font-weight:600;">Upcoming</p>
                                <div style="display:flex;gap:0.35rem;align-items:center;margin-bottom:0.45rem;">
                                    <button type="button" class="btn btn-small" @click="refreshInstagramSchedules()" :disabled="igScheduleLoading">Refresh list</button>
                                    <span class="hint" style="margin:0;" x-show="igScheduleLoading">Loading…</span>
                                </div>
                                <template x-if="!igSchedules.length && !igScheduleLoading">
                                    <p class="hint" style="margin:0;">Nothing in your Instagram queue yet.</p>
                                </template>
                                <ul class="sidebar-ig-list" style="margin:0;padding-left:0;list-style:none;">
                                    <template x-for="row in igSchedules" :key="row.id">
                                        <li style="margin-bottom:0.55rem;padding:0.45rem;border-radius:6px;background:rgba(0,0,0,0.2);">
                                            <div style="display:flex;flex-wrap:wrap;gap:0.35rem;align-items:center;font-size:0.72rem;">
                                                <span style="font-weight:600;" x-text="formatIgScheduleWhen(row.scheduled_publish_at)"></span>
                                                <span style="font-size:0.65rem;padding:0.08rem 0.32rem;border-radius:4px;background:rgba(255,255,255,0.08);" :class="row.status === 'failed' ? 'err' : 'hint'" x-text="igScheduleStatusLabel(row.status)"></span>
                                            </div>
                                            <div class="hint" style="margin:0.2rem 0 0;font-size:0.68rem;line-height:1.45;white-space:normal;" x-text="igScheduleAccountLabel(row.social_account_id) + ' · ' + row.image_count + ' img' + (row.caption ? ' · ' + row.caption.slice(0, 220) + (row.caption.length > 220 ? '…' : '') : '')"></div>
                                            <template x-if="row.status === 'published' && row.ig_media_id">
                                                <div class="hint" style="margin:0.2rem 0 0;font-size:0.66rem;word-break:break-all;">Instagram media id: <code x-text="row.ig_media_id"></code></div>
                                            </template>
                                            <template x-if="row.status === 'failed' && row.schedule_error">
                                                <p class="err" style="margin:0.25rem 0 0;font-size:0.66rem;line-height:1.4;white-space:pre-wrap;" x-text="row.schedule_error"></p>
                                            </template>
                                            <button type="button" class="btn btn-small" style="margin-top:0.35rem;" x-show="!row.status || row.status === 'scheduled'" @click="cancelInstagramSchedule(row.id)" :disabled="igScheduleLoading">Cancel</button>
                                        </li>
                                    </template>
                                </ul>
                            </div>
                            <?php endif; ?>
                            <details class="hint" style="margin-top:0.85rem;font-size:0.72rem;" open>
                                <summary style="cursor:pointer;color:var(--cg-accent);font-weight:500;">OAuth scope sent to Meta</summary>
                                <p style="margin:0.45rem 0 0;word-break:break-word;line-height:1.5;"><code style="display:block;padding:0.55rem 0.65rem;border-radius:6px;background:rgba(0,0,0,0.25);font-size:0.68rem;white-space:pre-wrap;"><?php echo htmlspecialchars((string) ($CONFIG['instagram_oauth_scope'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code></p>
                            </details>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="sidebar-tab-panel" x-show="rightTab === 'media'" x-cloak>
                    <section class="sidebar-section">
                        <h3 class="sidebar-heading">Media &amp; downloads</h3>
                        <p class="hint" style="margin-top:0;">Carousel JSON stays in the browser. <strong>Input</strong> previews use Garage objects under <code>social_accounts/{PocketBase id}/…</code> in your configured bucket (e.g. <code>my-bucket</code>) when you pick a linked Instagram account; otherwise they use PocketBase <code><?php echo $htmlInputMediaCol; ?></code>. <strong>Output</strong> still uses <code>output_media</code> in PocketBase.</p>
                        <?php if ($htmlPbAdmin !== ''): ?>
                        <p class="hint" style="margin:0.5rem 0 0;">
                            <a class="btn btn-small" style="display:inline-flex;width:100%;justify-content:center;box-sizing:border-box;" href="<?php echo $htmlPbAdmin; ?>" target="_blank" rel="noopener noreferrer">Open PocketBase admin</a>
                        </p>
                        <?php endif; ?>
                        <?php if ($user): ?>
                        <div class="field" style="margin-top:0.65rem;display:flex;gap:0.35rem;flex-wrap:wrap;align-items:center;">
                            <button type="button" class="btn btn-small" @click="refreshMediaLibrary()" :disabled="mediaLoading">Refresh media</button>
                            <span class="hint" style="margin:0;" x-show="mediaLoading">Loading…</span>
                        </div>
                        <p class="hint" style="margin:0.35rem 0;color:#f87171;" x-show="mediaErr" x-text="mediaErr"></p>
                        <div style="margin-top:0.35rem;">
                            <?php if ($garageReady && $igAccountsList !== []): ?>
                            <p class="media-subhead">Input media (Garage)</p>
                            <p class="hint" style="margin:0 0 0.5rem;">Drag thumbnails onto <strong>Intro / Outro</strong> in the Slides tab. Everything for the selected account stays in one bucket (<code><?php echo htmlspecialchars((string) ($CONFIG['garage_social_content_bucket'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'); ?></code>) under <code>social_accounts/&lt;id&gt;/…</code>. AI slide/image saves use <code>ai/slides/</code> and <code>ai/images/</code> under that same prefix when an Instagram account is selected in this tab.</p>
                            <div class="field" style="margin-bottom:0.5rem;">
                                <label>Linked account</label>
                                <select x-model="garageIgId" @change="onGarageIgScopeChange()" style="width:100%;">
                                    <template x-for="a in igAccounts" :key="a.id">
                                        <option :value="a.id" x-text="'@' + (a.username || a.instagram_user_id || a.id)"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="field" style="display:flex;gap:0.35rem;flex-wrap:wrap;margin-bottom:0.65rem;">
                                <label class="btn btn-small" style="cursor:pointer;margin:0;">
                                    Upload to Garage
                                    <input type="file" style="display:none;" @change="garageUploadFile($event)" :disabled="mediaLoading || garageUploading || !garageIgId">
                                </label>
                            </div>
                            <template x-for="row in mediaGarage" :key="row.key">
                                <div class="media-record-card">
                                    <p class="media-record-title" style="font-weight:500;font-size:0.7rem;" x-text="row.rel"></p>
                                    <template x-if="row.kind === 'image'">
                                        <div class="media-entry">
                                            <img class="media-thumb" draggable="true" @dragstart="onMediaDragStart($event, row.public_url || row.preview_url)" :src="row.preview_url" loading="lazy" :alt="row.rel">
                                        </div>
                                    </template>
                                    <template x-if="row.kind === 'video'">
                                        <div class="media-entry">
                                            <video class="media-video" :src="row.preview_url" controls playsinline preload="metadata"></video>
                                        </div>
                                    </template>
                                    <template x-if="row.kind === 'audio'">
                                        <div class="media-entry">
                                            <audio class="media-audio" :src="row.preview_url" controls preload="metadata"></audio>
                                        </div>
                                    </template>
                                    <div class="media-file-row" style="margin-top:0.35rem;">
                                        <span style="opacity:0.75;" x-text="garageFmtSize(row.size)"></span>
                                        <a class="btn btn-small" :href="row.download_url">Download</a>
                                        <button type="button" class="btn btn-small" @click="garageDeleteRel(row.rel)" :disabled="mediaLoading || garageUploading">Delete</button>
                                    </div>
                                </div>
                            </template>
                            <p class="hint" style="margin:0 0 0.65rem;" x-show="!mediaLoading && garageIgId && !mediaGarage.length">No objects in this account’s Garage prefix yet — upload above or run <code>scripts/garage-put-social-placeholder.sh &lt;social_accounts_id&gt;</code> on the server.</p>
                            <?php elseif (!$garageReady): ?>
                            <p class="media-subhead">Input media (PocketBase)</p>
                            <p class="hint" style="margin:0 0 0.5rem;">Garage not configured — showing PocketBase <code><?php echo $htmlInputMediaCol; ?></code> only. Set <code>GARAGE_*</code> and <code>scripts/garage-ensure-social-content-bucket.sh</code> (use <code>GARAGE_SOCIAL_CONTENT_BUCKET=my-bucket</code> for your generic bucket).</p>
                            <template x-if="!mediaPbInput.length">
                                <p class="hint" style="margin:0 0 0.5rem;">No file attachments on recent <code><?php echo $htmlInputMediaCol; ?></code> rows.</p>
                            </template>
                            <template x-for="block in mediaPbInput" :key="block.record_id">
                                <div class="media-record-card">
                                    <p class="media-record-title" x-text="block.title"></p>
                                    <template x-for="(en, ei) in block.entries" :key="block.record_id + '-' + ei + '-' + en.label">
                                        <div class="media-entry">
                                            <template x-if="en.kind === 'image'">
                                                <img class="media-thumb" draggable="true" @dragstart="onMediaDragStart($event, en.url)" :src="en.url" loading="lazy" :alt="en.label">
                                            </template>
                                            <template x-if="en.kind === 'video'">
                                                <video class="media-video" :src="en.url" controls playsinline preload="metadata"></video>
                                            </template>
                                            <template x-if="en.kind === 'audio'">
                                                <audio class="media-audio" :src="en.url" controls preload="metadata"></audio>
                                            </template>
                                            <template x-if="en.kind === 'file'">
                                                <div class="media-file-row">
                                                    <span x-text="en.label"></span>
                                                    <a class="btn btn-small" :href="en.url" target="_blank" rel="noopener noreferrer">Open</a>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <?php else: ?>
                            <p class="media-subhead">Input media (PocketBase)</p>
                            <p class="hint" style="margin:0 0 0.5rem;">Link Instagram in <strong>Socials</strong> to scope input media to Garage (<code>social_accounts/…</code>). Until then, previews use PocketBase <code><?php echo $htmlInputMediaCol; ?></code>.</p>
                            <template x-if="!mediaPbInput.length">
                                <p class="hint" style="margin:0 0 0.5rem;">No file attachments on recent <code><?php echo $htmlInputMediaCol; ?></code> rows.</p>
                            </template>
                            <template x-for="block in mediaPbInput" :key="block.record_id">
                                <div class="media-record-card">
                                    <p class="media-record-title" x-text="block.title"></p>
                                    <template x-for="(en, ei) in block.entries" :key="block.record_id + '-' + ei + '-' + en.label">
                                        <div class="media-entry">
                                            <template x-if="en.kind === 'image'">
                                                <img class="media-thumb" draggable="true" @dragstart="onMediaDragStart($event, en.url)" :src="en.url" loading="lazy" :alt="en.label">
                                            </template>
                                            <template x-if="en.kind === 'video'">
                                                <video class="media-video" :src="en.url" controls playsinline preload="metadata"></video>
                                            </template>
                                            <template x-if="en.kind === 'audio'">
                                                <audio class="media-audio" :src="en.url" controls preload="metadata"></audio>
                                            </template>
                                            <template x-if="en.kind === 'file'">
                                                <div class="media-file-row">
                                                    <span x-text="en.label"></span>
                                                    <a class="btn btn-small" :href="en.url" target="_blank" rel="noopener noreferrer">Open</a>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <?php endif; ?>
                            <p class="media-subhead">Output media (PocketBase)</p>
                            <template x-if="!mediaPbOutput.length">
                                <p class="hint" style="margin:0 0 0.5rem;">No previewable files on recent <code>output_media</code> rows.</p>
                            </template>
                            <template x-for="block in mediaPbOutput" :key="block.record_id">
                                <div class="media-record-card">
                                    <p class="media-record-title" x-text="block.title"></p>
                                    <template x-for="(en, ei) in block.entries" :key="block.record_id + '-o-' + ei + '-' + en.label">
                                        <div class="media-entry">
                                            <template x-if="en.kind === 'image'">
                                                <img class="media-thumb" draggable="true" @dragstart="onMediaDragStart($event, en.url)" :src="en.url" loading="lazy" :alt="en.label">
                                            </template>
                                            <template x-if="en.kind === 'video'">
                                                <video class="media-video" :src="en.url" controls playsinline preload="metadata"></video>
                                            </template>
                                            <template x-if="en.kind === 'audio'">
                                                <audio class="media-audio" :src="en.url" controls preload="metadata"></audio>
                                            </template>
                                            <template x-if="en.kind === 'file'">
                                                <div class="media-file-row">
                                                    <span x-text="en.label"></span>
                                                    <a class="btn btn-small" :href="en.url" target="_blank" rel="noopener noreferrer">Open</a>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                        <?php else: ?>
                        <p class="hint" style="margin-top:0.65rem;">Sign in to load previews from PocketBase and Garage.</p>
                        <?php endif; ?>
                    </section>
                </div>

                <div class="sidebar-tab-panel" x-show="rightTab === 'debug'" x-cloak>
                    <section class="sidebar-section">
                        <h3 class="sidebar-heading">Diagnostics</h3>
                        <p class="hint" style="margin-top:0;"><?php if ($user): ?><a href="?ff_debug_json=1">Download debug JSON</a> (safe config snapshot).<?php else: ?>Sign in to use debug JSON.<?php endif; ?></p>
                    </section>
                </div>
            </div>
        </aside>

        <div class="carousel-scrubber-bar" aria-label="Current slide video segment and music">
            <div class="carousel-scrubber-inner">
                <div class="carousel-scrubber-wrap" x-show="currentSlideIsVideo()" x-cloak>
                    <div class="carousel-scrubber-meta">
                        <button type="button" class="btn btn-small" @click="carouselPlayToggle()"
                            x-text="carouselPlaying ? 'Pause' : 'Play'"></button>
                        <span class="carousel-scrubber-time" x-text="carouselTimeLabel()"></span>
                    </div>
                    <div class="carousel-scrubber-track-wrap">
                        <div class="carousel-scrubber-ticks" aria-hidden="true"></div>
                        <input type="range" class="carousel-scrubber-range" min="0"
                            :max="slideDurationSec()"
                            step="0.05"
                            :value="carouselTimelineSec"
                            @input="onCarouselScrubInput($event)"
                            :style="{ '--carousel-fill-pct': carouselScrubFillPct() + '%' }">
                    </div>
                    <p class="carousel-scrubber-hint">Video slide: scrubber covers <strong>this slide only</strong> (0 → seconds per slide from the <strong>Video</strong> tab). Use slide arrows or the list to change slides; playback does not walk the whole carousel.</p>
                </div>
                <div class="carousel-still-music" x-show="slide && !currentSlideIsVideo()" x-cloak>
                    <div class="carousel-still-music-row">
                        <label class="carousel-still-label" for="carousel-still-music-input">Music for this slide</label>
                        <input id="carousel-still-music-input" type="text" class="carousel-still-input"
                            x-model="slide.musicPath"
                            placeholder="e.g. ./music/intro.mp3 or a public audio URL">
                    </div>
                    <p class="carousel-scrubber-hint">This slide is a <strong>still</strong> (image + hold). Set audio above, or change it to a <strong>video</strong> slide in the Slides tab to use the scrubber. Exported JSON includes <code>musicPath</code> per slide for your pipeline.</p>
                </div>
            </div>
        </div>
    </div>

    <script>
window.__CG_GENERATE_URL__ = <?= json_encode($generateUrl, JSON_UNESCAPED_SLASHES) ?>;
window.__CG_IMAGE_GEN_URL__ = <?= json_encode($generateImageUrl, JSON_UNESCAPED_SLASHES) ?>;
window.__FF_SCRIPT__ = <?= $jsonFfScript ?>;
window.__FF_IG_ACCOUNTS__ = <?= $jsonIgAccounts ?>;
window.__FF_USER__ = <?= $user ? 'true' : 'false' ?>;
    </script>
    <script>
(function () {
  function cgResolveApiUrl(pathOrUrl) {
    var p = pathOrUrl || '';
    if (!p) {
      return '';
    }
    try {
      return new URL(p, window.location.href).href;
    } catch (e) {
      return p;
    }
  }
  const GENERATE_URL = cgResolveApiUrl(window.__CG_GENERATE_URL__);
  const IMAGE_GEN_URL = cgResolveApiUrl(window.__CG_IMAGE_GEN_URL__ || '');

  function cgNonJsonApiError(res, rawTxt, label) {
    var st = res ? res.status : 0;
    var ct = (res && res.headers && res.headers.get('Content-Type')) || '';
    var snip = String(rawTxt || '').replace(/\s+/g, ' ').trim().slice(0, 200);
    var isCf = /cloudflare|cdn-cgi|<!--\[if lt IE 7\]|Ray ID:/i.test(rawTxt || '');
    var msg = label + ' returned HTTP ' + (st || '?') + '. ';
    if (ct.indexOf('text/html') !== -1 || /^\s*<!DOCTYPE/i.test(rawTxt || '')) {
      msg += 'Body is HTML, not JSON — you are seeing an error page from the edge or origin, not the carousel API. ';
      if (st === 502 && isCf) {
        msg += 'Cloudflare 502 = bad response from your server behind Cloudflare (not WAF blocking). Typical causes: nginx cannot talk to PHP-FPM (wrong unix socket in fastcgi_pass vs pool listen=), PHP-FPM request_terminate_timeout or max_execution_time killing the worker while OpenRouter runs (allow ~130s), PHP fatal on that code path, or SSL mode mismatch (Flexible vs Full). Check /var/log/nginx/error.log and php-fpm log; from the server POST the same URL with your session cookie to see the real error. ';
      } else if (st === 524 && isCf) {
        msg += 'Cloudflare 524 = origin too slow. Raise nginx fastcgi_read_timeout and pool request_terminate_timeout; slide AI can take up to ~120s. ';
      } else if (isCf && (st === 403 || st === 429)) {
        msg += 'Often WAF or rate limit — review Cloudflare Security → Events for this Ray ID. ';
      } else if (isCf) {
        msg += 'Pause orange-cloud or curl the origin directly to see the real nginx/PHP error. ';
      } else {
        msg += 'Check nginx and PHP-FPM logs (timeouts, client_max_body_size). ';
      }
    }
    msg += snip ? ('Snippet: ' + snip) : '(empty body)';
    return msg;
  }

  const STORAGE_KEY = 'carousel-generator-doc';
  const PIPELINE_PREFS_KEY = 'carousel-generator-pipeline';
  const SLIDE_TEMPLATES_KEY_LEGACY = 'carousel-slide-templates';
  const SLIDE_TEMPLATES_KEY_V2 = 'carousel-slide-templates-v2';
  const AI_SLIDES_MAX = 10;

  const PALETTES = {
    black: { primary: '#373737', secondary: '#161616', background: '#000000' },
    light: { primary: '#4f46e5', secondary: '#6366f1', background: '#ffffff' },
    dracula: { primary: '#ff79c6', secondary: '#bd93f9', background: '#282a36' },
    nord: { primary: '#5e81ac', secondary: '#81a1c1', background: '#eceff4' },
    night: { primary: '#38bdf8', secondary: '#818cf8', background: '#0f172a' },
    forest: { primary: '#1eb854', secondary: '#1db88e', background: '#171212' },
  };

  const FONT_CSS = {
    DM_Serif_Display: '"DM Serif Display", Georgia, serif',
    DM_Sans: '"DM Sans", system-ui, sans-serif',
    Inter: 'Inter, system-ui, sans-serif',
    Montserrat: 'Montserrat, system-ui, sans-serif',
    Roboto: 'Roboto, system-ui, sans-serif',
    Roboto_Condensed: '"Roboto Condensed", system-ui, sans-serif',
    PT_Serif: '"PT Serif", Georgia, serif',
    Syne: 'Syne, system-ui, sans-serif',
    ArchivoBlack: '"Archivo Black", system-ui, sans-serif',
    Ultra: 'Ultra, Georgia, serif',
  };

  function defaultImage(bgOpacity) {
    return {
      type: 'Image',
      source: { src: '', type: 'URL' },
      style: { opacity: bgOpacity },
    };
  }

  function defaultContentImage() {
    return {
      type: 'ContentImage',
      source: { src: '', type: 'URL' },
      style: {
        opacity: 100,
        objectFit: 'Cover',
        imgScale: 100,
        imgRotateDeg: 0,
        imgPanX: 0,
        imgPanY: 0,
      },
    };
  }

  function textStyle() {
    return { fontSize: 'Medium', align: 'Left' };
  }

  function elTitle(text) {
    return { type: 'Title', text: text || 'YOUR TITLE', style: textStyle() };
  }
  function elSubtitle(text) {
    return { type: 'Subtitle', text: text || 'Your awesome subtitle', style: textStyle() };
  }
  function elDescription(text) {
    return {
      type: 'Description',
      text: text || 'Lorem ipsum dolor sit amet consectetur adipisicing elit.',
      style: textStyle(),
    };
  }

  function defaultSlideMedia() {
    return { mediaKind: 'still', musicPath: '' };
  }

  function normalizeDocSlidesMedia(doc) {
    if (!doc || !Array.isArray(doc.slides)) {
      return;
    }
    doc.slides.forEach(function (s) {
      if (s.mediaKind !== 'video' && s.mediaKind !== 'still') {
        s.mediaKind = 'still';
      }
      if (typeof s.musicPath !== 'string') {
        s.musicPath = '';
      }
      (s.elements || []).forEach(function (el) {
        if (!el || (el.type !== 'ContentImage' && el.type !== 'Image')) {
          return;
        }
        if (!el.style || typeof el.style !== 'object') {
          el.style = {};
        }
        if (typeof el.style.imgScale !== 'number' || el.style.imgScale <= 0) {
          el.style.imgScale = 100;
        }
        if (el.style.imgScale > 400) {
          el.style.imgScale = 400;
        }
        if (typeof el.style.imgRotateDeg !== 'number') {
          el.style.imgRotateDeg = 0;
        }
        if (typeof el.style.imgPanX !== 'number') {
          el.style.imgPanX = 0;
        }
        if (typeof el.style.imgPanY !== 'number') {
          el.style.imgPanY = 0;
        }
      });
    });
  }

  /** Deep clone one slide and run the same media normalization as the full document. */
  function cloneSlideForDoc(s) {
    if (!s || typeof s !== 'object') {
      return slideCommon();
    }
    try {
      var x = JSON.parse(JSON.stringify(s));
      normalizeDocSlidesMedia({ slides: [x], config: {} });
      return x;
    } catch (e) {
      return slideCommon();
    }
  }

  /** One still slide whose main content is an image URL (intro/outro from Media drag). */
  function slideFromImageUrl(url) {
    const ci = defaultContentImage();
    ci.source.src = url;
    return Object.assign(defaultSlideMedia(), {
      elements: [elTitle(), ci],
      backgroundImage: defaultImage(30),
    });
  }

  function slideIntro() {
    return Object.assign(defaultSlideMedia(), {
      elements: [elTitle(), defaultContentImage()],
      backgroundImage: defaultImage(30),
    });
  }
  function slideCommon() {
    return Object.assign(defaultSlideMedia(), {
      elements: [elTitle(), elSubtitle(), defaultContentImage()],
      backgroundImage: defaultImage(30),
    });
  }
  function slideContent() {
    return Object.assign(defaultSlideMedia(), {
      elements: [elTitle(), elDescription()],
      backgroundImage: defaultImage(30),
    });
  }
  function slideOutro() {
    return Object.assign(defaultSlideMedia(), {
      elements: [elTitle(), elSubtitle(), elDescription()],
      backgroundImage: defaultImage(30),
    });
  }

  function defaultDocument() {
    return {
      slides: [
        slideIntro(),
        slideCommon(),
        slideContent(),
        slideContent(),
        slideOutro(),
      ],
      config: {
        brand: {
          avatar: {
            type: 'Image',
            source: { src: '', type: 'URL' },
            style: { opacity: 100 },
          },
          name: 'My name',
          handle: '@name',
        },
        theme: {
          isCustom: false,
          pallette: 'black',
          primary: '#0d0d0d',
          secondary: '#161616',
          background: '#ffffff',
        },
        fonts: { font1: 'DM_Serif_Display', font2: 'DM_Sans' },
        pageNumber: { showNumbers: true },
      },
      filename: 'My Carousel File',
    };
  }

  function normalizeAiElement(raw) {
    const t = raw && raw.type;
    const text = typeof raw.text === 'string' ? raw.text : '';
    if (t === 'Subtitle') return elSubtitle(text);
    if (t === 'Description') return elDescription(text);
    return elTitle(text || 'Slide');
  }

  function normalizeAiSlides(slides) {
    if (!Array.isArray(slides)) return null;
    return slides.slice(0, AI_SLIDES_MAX).map(function (s) {
      const els = Array.isArray(s.elements) ? s.elements : [];
      const mapped = els.slice(0, 3).map(normalizeAiElement);
      if (mapped.length === 0) mapped.push(elTitle('Slide'));
      return Object.assign(defaultSlideMedia(), {
        elements: mapped,
        backgroundImage: defaultImage(30),
      });
    });
  }

  function applyPalette(doc, name) {
    const p = PALETTES[name];
    if (!p) return;
    doc.config.theme.pallette = name;
    doc.config.theme.isCustom = false;
    doc.config.theme.primary = p.primary;
    doc.config.theme.secondary = p.secondary;
    doc.config.theme.background = p.background;
  }

  document.addEventListener('alpine:init', function () {
    Alpine.data('carouselApp', function () {
      return {
        doc: defaultDocument(),
        currentIndex: 0,
        tab: 'slides',
        rightTab: 'account',
        igAccounts: Array.isArray(window.__FF_IG_ACCOUNTS__) ? window.__FF_IG_ACCOUNTS__ : [],
        garageIgId: '',
        mediaPbInput: [],
        mediaPbOutput: [],
        mediaGarage: [],
        mediaLoading: false,
        mediaErr: '',
        garageUploading: false,
        aiPrompt: '',
        aiLoading: false,
        aiError: '',
        imgGenPrompt: '',
        imgGenUrl: '',
        imgGenAspect: '4:5',
        imgGenResolution: '2K',
        imgGenFileB64: '',
        imgGenFileMime: '',
        imgGenLoading: false,
        imgGenError: '',
        imgGenResultUrl: '',
        previewDropActive: false,
        /** Same-page fallback when dataTransfer.getData is empty (common when dragging the AI preview img). */
        dragImgGenUrl: '',
        importError: '',
        videoSeconds: 3,
        videoMusicPath: './music/track.mp3',
        videoOutPath: '../out/carousel.mp4',
        pipelineCopyOk: '',
        carouselTimelineSec: 0,
        carouselPlaying: false,
        carouselRafId: null,
        slideTemplates: { intro: null, outro: null },
        slideTemplatesByAccount: {},
        templateMsg: '',
        draggingOverTpl: '',
        igScheduleCaption: '',
        igScheduleDate: '',
        igScheduleTime: '',
        igSchedules: [],
        igScheduleLoading: false,
        igScheduleErr: '',
        igScheduleMsg: '',

        init: function () {
          const self = this;
          try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (raw) {
              const parsed = JSON.parse(raw);
              if (parsed && parsed.slides && parsed.config) {
                this.doc = parsed;
                normalizeDocSlidesMedia(this.doc);
              }
            }
          } catch (e) { /* ignore */ }
          try {
            const pr = localStorage.getItem(PIPELINE_PREFS_KEY);
            if (pr) {
              const po = JSON.parse(pr);
              if (typeof po.videoSeconds === 'number' && !isNaN(po.videoSeconds)) {
                self.videoSeconds = po.videoSeconds;
              }
              if (typeof po.videoMusicPath === 'string') {
                self.videoMusicPath = po.videoMusicPath;
              }
              if (typeof po.videoOutPath === 'string') {
                self.videoOutPath = po.videoOutPath;
              }
            }
          } catch (e2) { /* ignore */ }
          this.loadSlideTemplatesFromStorage();
          if (this.igAccounts.length && !this.garageIgId) {
            this.garageIgId = this.igAccounts[0].id || '';
          }
          this.igScheduleApplyDefaultPublishTime();
          this.migrateLegacySlideTemplatesIfNeeded();
          this.assignSlideTemplatesFromAccount();
          if (window.__FF_USER__) {
            this.refreshMediaLibrary();
            this.refreshInstagramSchedules();
          }
          this.$watch('doc', function () { this.persist(); }.bind(this), { deep: true });
          ['videoSeconds', 'videoMusicPath', 'videoOutPath'].forEach(function (k) {
            self.$watch(k, function () { self.persistPipeline(); });
          });
          this.$watch('videoSeconds', function () {
            self.carouselStop();
            var tot = self.slideDurationSec();
            if (self.carouselTimelineSec > tot) {
              self.carouselTimelineSec = tot;
            }
          });
          function onDocDragOverCapture(ev) {
            var frame = ev.target && ev.target.closest && ev.target.closest('.preview-frame');
            if (!frame || !self.$el.contains(frame)) return;
            ev.preventDefault();
            if (ev.dataTransfer) ev.dataTransfer.dropEffect = 'copy';
          }
          function onDocDropCapture(ev) {
            var frame = ev.target && ev.target.closest && ev.target.closest('.preview-frame');
            if (!frame || !self.$el.contains(frame)) return;
            var url = self.resolvePreviewDropUrl(ev);
            if (!url) return;
            ev.preventDefault();
            ev.stopPropagation();
            self.previewDropActive = false;
            self.applyContentImageUrl(url);
            if (typeof window !== 'undefined') window.__FF_DRAG_AI_IMG_URL__ = '';
            self.dragImgGenUrl = '';
          }
          document.addEventListener('dragover', onDocDragOverCapture, true);
          document.addEventListener('drop', onDocDropCapture, true);
          document.addEventListener('dragend', function () {
            self.draggingOverTpl = '';
          });
        },

        persistPipeline: function () {
          try {
            localStorage.setItem(
              PIPELINE_PREFS_KEY,
              JSON.stringify({
                videoSeconds: this.videoSeconds,
                videoMusicPath: this.videoMusicPath,
                videoOutPath: this.videoOutPath,
              })
            );
          } catch (e) { /* ignore */ }
        },

        pipelineShellScript: function () {
          let sec = Number(this.videoSeconds);
          if (isNaN(sec) || sec < 0.5) {
            sec = 3;
          }
          const music = (this.videoMusicPath || '').trim();
          let out = (this.videoOutPath || '../out/carousel.mp4').trim();
          if (!out) {
            out = '../out/carousel.mp4';
          }
          const jsonFile = '../carousel-doc.json';
          let cmd = 'node run.mjs ' + jsonFile + ' --seconds ' + sec + ' --out "' + out + '"';
          if (music) {
            cmd += ' --music "' + music + '"';
          }
          return [
            '# Run from project root (folder that contains video-pipeline/).',
            '# Prereqs: Node.js, ffmpeg on PATH, network (Google Fonts).',
            '# 1) Data tab → Export JSON → save as carousel-doc.json in project root.',
            '# Still slides: per-slide musicPath in JSON (Slides tab / bottom bar). Video slides: mediaKind "video".',
            'cd video-pipeline',
            'npm install',
            'PLAYWRIGHT_BROWSERS_PATH="$(pwd)/.playwright-browsers" npx playwright install chromium',
            cmd,
          ].join('\n');
        },

        copyPipelineCommand: function () {
          const self = this;
          const text = this.pipelineShellScript();
          if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
              self.pipelineCopyOk = 'Commands copied to clipboard.';
              setTimeout(function () { self.pipelineCopyOk = ''; }, 2500);
            }).catch(function () {
              self.pipelineCopyOk = 'Could not copy — select the script block below.';
              setTimeout(function () { self.pipelineCopyOk = ''; }, 4000);
            });
          } else {
            self.pipelineCopyOk = 'Clipboard not available — select the script below.';
            setTimeout(function () { self.pipelineCopyOk = ''; }, 4000);
          }
        },

        persist: function () {
          try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(this.doc));
          } catch (e) { /* ignore */ }
        },

        get slide() {
          return this.doc.slides[this.currentIndex] || this.doc.slides[0];
        },

        currentSlideIsVideo: function () {
          var s = this.slide;
          return !!(s && s.mediaKind === 'video');
        },

        slideDurationSec: function () {
          var s = Number(this.videoSeconds);
          if (isNaN(s) || s < 0.5) {
            s = 3;
          }
          if (s > 120) {
            s = 120;
          }
          return s;
        },

        snapTimelineToCurrentSlide: function () {
          this.carouselTimelineSec = 0;
        },

        goToSlide: function (i) {
          if (i < 0 || i >= this.doc.slides.length) {
            return;
          }
          this.carouselStop();
          this.currentIndex = i;
          this.snapTimelineToCurrentSlide();
        },

        carouselGoPrev: function () {
          this.carouselStop();
          this.currentIndex = Math.max(0, this.currentIndex - 1);
          this.snapTimelineToCurrentSlide();
        },

        carouselGoNext: function () {
          this.carouselStop();
          this.currentIndex = Math.min(this.doc.slides.length - 1, this.currentIndex + 1);
          this.snapTimelineToCurrentSlide();
        },

        onCarouselScrubInput: function (e) {
          this.carouselStop();
          var t = parseFloat(e.target.value);
          if (isNaN(t)) {
            return;
          }
          var total = this.slideDurationSec();
          if (t < 0) {
            t = 0;
          }
          if (t > total) {
            t = total;
          }
          this.carouselTimelineSec = t;
        },

        formatCarouselClock: function (sec) {
          var s = Math.max(0, sec);
          var m = Math.floor(s / 60);
          var r = s - m * 60;
          var whole = Math.floor(r);
          var frac = Math.round((r - whole) * 10);
          if (frac >= 10) {
            whole++;
            frac = 0;
          }
          var secPart = String(whole).padStart(2, '0');
          if (frac > 0) {
            secPart += '.' + frac;
          }
          return m + ':' + secPart;
        },

        carouselTimeLabel: function () {
          var d = this.slideDurationSec();
          return this.formatCarouselClock(this.carouselTimelineSec)
            + ' / '
            + this.formatCarouselClock(d);
        },

        carouselScrubFillPct: function () {
          var tot = this.slideDurationSec();
          if (tot <= 0) {
            return 0;
          }
          return Math.min(100, (this.carouselTimelineSec / tot) * 100);
        },

        carouselStop: function () {
          this.carouselPlaying = false;
          if (this.carouselRafId != null) {
            cancelAnimationFrame(this.carouselRafId);
            this.carouselRafId = null;
          }
        },

        carouselPlayToggle: function () {
          var self = this;
          if (this.carouselPlaying) {
            this.carouselStop();
            return;
          }
          if (this.doc.slides.length < 1) {
            return;
          }
          if (!this.currentSlideIsVideo()) {
            return;
          }
          var seg = this.slideDurationSec();
          if (this.carouselTimelineSec >= seg - 0.04) {
            this.carouselTimelineSec = 0;
          }
          this.carouselPlaying = true;
          var last = performance.now();
          function tick(now) {
            if (!self.carouselPlaying) {
              return;
            }
            var dt = (now - last) / 1000;
            last = now;
            var total = self.slideDurationSec();
            self.carouselTimelineSec = Math.min(self.carouselTimelineSec + dt, total);
            var cur = self.doc.slides[self.currentIndex];
            if (!cur || cur.mediaKind !== 'video') {
              self.carouselStop();
              return;
            }
            if (self.carouselTimelineSec >= total - 0.02) {
              self.carouselStop();
              return;
            }
            self.carouselRafId = requestAnimationFrame(tick);
          }
          this.carouselRafId = requestAnimationFrame(tick);
        },

        setPalette: function (name) {
          applyPalette(this.doc, name);
        },

        setCustomMode: function () {
          this.doc.config.theme.isCustom = true;
        },

        moveSlide: function (i, delta) {
          const j = i + delta;
          if (j < 0 || j >= this.doc.slides.length) return;
          const arr = this.doc.slides;
          const tmp = arr[i];
          arr[i] = arr[j];
          arr[j] = tmp;
          if (this.currentIndex === i) this.currentIndex = j;
          else if (this.currentIndex === j) this.currentIndex = i;
          this.snapTimelineToCurrentSlide();
        },

        removeSlide: function (i) {
          if (this.doc.slides.length <= 1) return;
          this.carouselStop();
          this.doc.slides.splice(i, 1);
          if (this.currentIndex >= this.doc.slides.length) {
            this.currentIndex = this.doc.slides.length - 1;
          }
          var tot = this.slideDurationSec();
          if (this.carouselTimelineSec > tot) {
            this.carouselTimelineSec = tot;
          }
          this.snapTimelineToCurrentSlide();
        },

        addSlide: function (kind) {
          let s;
          if (kind === 'intro') s = slideIntro();
          else if (kind === 'content') s = slideContent();
          else if (kind === 'outro') s = slideOutro();
          else s = slideCommon();
          this.carouselStop();
          this.doc.slides.push(s);
          this.currentIndex = this.doc.slides.length - 1;
          this.snapTimelineToCurrentSlide();
        },

        loadSlideTemplatesFromStorage: function () {
          try {
            const raw = localStorage.getItem(SLIDE_TEMPLATES_KEY_V2);
            if (raw) {
              const o = JSON.parse(raw);
              if (o && typeof o === 'object' && !Array.isArray(o)) {
                this.slideTemplatesByAccount = o;
                return;
              }
            }
          } catch (e) { /* ignore */ }
          try {
            const leg = localStorage.getItem(SLIDE_TEMPLATES_KEY_LEGACY);
            if (leg) {
              const o = JSON.parse(leg);
              if (o && o.intro && typeof o.intro === 'object') {
                this.slideTemplatesByAccount = {
                  _legacy: {
                    intro: o.intro,
                    outro: o.outro && typeof o.outro === 'object' ? o.outro : null,
                  },
                };
                localStorage.setItem(SLIDE_TEMPLATES_KEY_V2, JSON.stringify(this.slideTemplatesByAccount));
                return;
              }
            }
          } catch (e2) { /* ignore */ }
          this.slideTemplatesByAccount = {};
        },

        migrateLegacySlideTemplatesIfNeeded: function () {
          try {
            const map = this.slideTemplatesByAccount;
            if (!map || !map._legacy) {
              return;
            }
            const acc = this.igAccounts;
            if (!acc || !acc.length) {
              return;
            }
            const firstId = acc[0].id;
            if (!firstId) {
              return;
            }
            if (!map[firstId]) {
              map[firstId] = {
                intro: map._legacy.intro,
                outro: map._legacy.outro,
              };
            }
            delete map._legacy;
            localStorage.setItem(SLIDE_TEMPLATES_KEY_V2, JSON.stringify(map));
          } catch (e) { /* ignore */ }
        },

        assignSlideTemplatesFromAccount: function () {
          const key = this.garageIgId || '_';
          const map = this.slideTemplatesByAccount || {};
          const entry = map[key] || {};
          this.slideTemplates.intro = entry.intro && typeof entry.intro === 'object' ? entry.intro : null;
          this.slideTemplates.outro = entry.outro && typeof entry.outro === 'object' ? entry.outro : null;
        },

        persistSlideTemplates: function () {
          try {
            const key = this.garageIgId || '_';
            if (!this.slideTemplatesByAccount || typeof this.slideTemplatesByAccount !== 'object') {
              this.slideTemplatesByAccount = {};
            }
            if (!this.slideTemplatesByAccount[key]) {
              this.slideTemplatesByAccount[key] = {};
            }
            this.slideTemplatesByAccount[key].intro = this.slideTemplates.intro;
            this.slideTemplatesByAccount[key].outro = this.slideTemplates.outro;
            localStorage.setItem(SLIDE_TEMPLATES_KEY_V2, JSON.stringify(this.slideTemplatesByAccount));
          } catch (e) { /* ignore */ }
        },

        onGarageIgScopeChange: function () {
          this.assignSlideTemplatesFromAccount();
          if (window.__FF_USER__) {
            this.refreshMediaLibrary();
          }
        },

        igAccountScopeLabel: function () {
          const id = this.garageIgId;
          if (!id || !Array.isArray(this.igAccounts)) {
            return '';
          }
          for (let i = 0; i < this.igAccounts.length; i++) {
            const a = this.igAccounts[i];
            if (a && a.id === id) {
              const u = a.username || a.instagram_user_id || a.id;
              return u ? '@' + u : id;
            }
          }
          return id;
        },

        flashTemplateMsg: function (msg) {
          const self = this;
          this.templateMsg = msg;
          if (this._templateMsgTimer) {
            clearTimeout(this._templateMsgTimer);
          }
          this._templateMsgTimer = setTimeout(function () {
            self.templateMsg = '';
          }, 2500);
        },

        onMediaDragStart: function (e, url) {
          if (!url || !e.dataTransfer) {
            return;
          }
          let u = String(url).trim();
          try {
            u = new URL(u, window.location.href).href;
          } catch (err) {
            return;
          }
          try {
            e.dataTransfer.setData('text/plain', u);
            e.dataTransfer.setData('text/uri-list', u);
          } catch (err2) {
            /* ignore */
          }
          e.dataTransfer.effectAllowed = 'copy';
        },

        onTemplateImageDrop: function (e, which) {
          let url = '';
          if (e.dataTransfer) {
            try {
              url = e.dataTransfer.getData('text/plain') || e.dataTransfer.getData('text/uri-list') || '';
            } catch (err) {
              url = '';
            }
          }
          url = String(url).split('\n')[0].trim();
          if (!url) {
            this.flashTemplateMsg('Drop an image from the Media tab.');
            return;
          }
          try {
            url = new URL(url, window.location.href).href;
          } catch (err2) {
            this.flashTemplateMsg('Invalid URL.');
            return;
          }
          if (!/^https?:\/\//i.test(url)) {
            this.flashTemplateMsg('Use an http(s) image URL from Media.');
            return;
          }
          const slide = slideFromImageUrl(url);
          this.carouselStop();
          if (which === 'intro') {
            this.slideTemplates.intro = cloneSlideForDoc(slide);
            this.flashTemplateMsg('Intro template set from image.');
          } else {
            this.slideTemplates.outro = cloneSlideForDoc(slide);
            this.flashTemplateMsg('Outro template set from image.');
          }
          this.persistSlideTemplates();
        },

        exportJson: function () {
          const blob = new Blob([JSON.stringify(this.doc, null, 2)], {
            type: 'application/json',
          });
          const a = document.createElement('a');
          a.href = URL.createObjectURL(blob);
          a.download = (this.doc.filename || 'carousel').replace(/\s+/g, '-') + '.json';
          a.click();
          URL.revokeObjectURL(a.href);
        },

        onImportFile: function (e) {
          this.importError = '';
          const file = e.target.files && e.target.files[0];
          if (!file) return;
          const reader = new FileReader();
          const self = this;
          reader.onload = function () {
            try {
              const parsed = JSON.parse(String(reader.result));
              if (!parsed.slides || !parsed.config) {
                self.importError = 'Invalid file: need slides and config.';
                return;
              }
              self.doc = parsed;
              normalizeDocSlidesMedia(self.doc);
              self.currentIndex = 0;
              self.carouselStop();
              self.carouselTimelineSec = 0;
            } catch (err) {
              self.importError = 'Could not parse JSON.';
            }
          };
          reader.readAsText(file);
          e.target.value = '';
        },

        ffScriptPath: function () {
          return typeof window.__FF_SCRIPT__ === 'string' && window.__FF_SCRIPT__ !== ''
            ? window.__FF_SCRIPT__
            : (window.location.pathname || '/index.php');
        },

        garageFmtSize: function (n) {
          const x = typeof n === 'number' ? n : parseInt(n, 10) || 0;
          if (x < 1024) return x + ' B';
          if (x < 1048576) return (x / 1024).toFixed(1) + ' KB';
          return (x / 1048576).toFixed(1) + ' MB';
        },

        formatIgScheduleWhen: function (iso) {
          if (!iso) {
            return '';
          }
          const d = new Date(iso);
          if (isNaN(d.getTime())) {
            return String(iso);
          }
          try {
            return d.toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
          } catch (e) {
            return d.toISOString();
          }
        },

        igScheduleAccountLabel: function (sid) {
          const id = String(sid || '');
          const acc = Array.isArray(this.igAccounts) ? this.igAccounts : [];
          for (let i = 0; i < acc.length; i++) {
            if (acc[i] && String(acc[i].id) === id) {
              const a = acc[i];
              return '@' + (a.username || a.instagram_user_id || a.id);
            }
          }
          return id || '—';
        },

        igScheduleStatusLabel: function (st) {
          const s = String(st || 'scheduled').toLowerCase();
          if (s === 'published') {
            return 'Posted';
          }
          if (s === 'failed') {
            return 'Failed';
          }
          if (s === 'scheduled') {
            return 'Scheduled';
          }
          if (s === 'cancelled' || s === 'canceled') {
            return 'Cancelled';
          }
          return s ? s.charAt(0).toUpperCase() + s.slice(1) : 'Scheduled';
        },

        igSchedulePad2: function (n) {
          return String(n).padStart(2, '0');
        },

        igScheduleApplyLocalDate: function (d) {
          if (!d || isNaN(d.getTime())) {
            return;
          }
          d.setSeconds(0, 0);
          const p = this.igSchedulePad2;
          this.igScheduleDate = d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
          this.igScheduleTime = p(d.getHours()) + ':' + p(d.getMinutes());
        },

        igScheduleApplyDefaultPublishTime: function () {
          const d = new Date();
          d.setMinutes(d.getMinutes() + 65);
          this.igScheduleApplyLocalDate(d);
        },

        igScheduleTzLabel: function () {
          try {
            return Intl.DateTimeFormat().resolvedOptions().timeZone || '';
          } catch (e) {
            return '';
          }
        },

        igScheduleMinDateStr: function () {
          const d = new Date();
          const p = this.igSchedulePad2;
          return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        },

        igSchedulePresetMinutes: function (mins) {
          const n = typeof mins === 'number' ? mins : parseInt(mins, 10);
          if (!n || isNaN(n)) {
            return;
          }
          const d = new Date();
          d.setTime(d.getTime() + n * 60000);
          this.igScheduleApplyLocalDate(d);
        },

        igSchedulePresetTomorrowAt: function (hour, minute) {
          const d = new Date();
          d.setDate(d.getDate() + 1);
          d.setHours(hour, minute, 0, 0);
          this.igScheduleApplyLocalDate(d);
        },

        /** Next occurrence of weekday (0=Sun … 6=Sat), always strictly in the future (not “this” weekday). */
        igSchedulePresetNextWeekdayAt: function (targetDow, hour, minute) {
          const d = new Date();
          const cur = d.getDay();
          let delta = (targetDow - cur + 7) % 7;
          if (delta === 0) {
            delta = 7;
          }
          d.setDate(d.getDate() + delta);
          d.setHours(hour, minute, 0, 0);
          this.igScheduleApplyLocalDate(d);
        },

        igScheduleCombinedDate: function () {
          const ds = String(this.igScheduleDate || '').trim();
          const ts = String(this.igScheduleTime || '').trim();
          if (!ds || !ts) {
            return null;
          }
          const d = new Date(ds + 'T' + ts);
          if (isNaN(d.getTime())) {
            return null;
          }
          return d;
        },

        igSchedulePreviewLocal: function () {
          const d = this.igScheduleCombinedDate();
          if (!d) {
            return '';
          }
          try {
            return d.toLocaleString(undefined, { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' });
          } catch (e) {
            return d.toString();
          }
        },

        igSchedulePreviewUtcIso: function () {
          const d = this.igScheduleCombinedDate();
          if (!d) {
            return '';
          }
          return d.toISOString();
        },

        refreshInstagramSchedules: async function () {
          this.igScheduleErr = '';
          if (!window.__FF_USER__) {
            this.igSchedules = [];
            return;
          }
          this.igScheduleLoading = true;
          try {
            const path = this.ffScriptPath();
            const res = await fetch(path + '?action=list_instagram_schedules', { credentials: 'same-origin' });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              throw new Error((data && data.error) ? String(data.error) : ('HTTP ' + res.status));
            }
            this.igSchedules = Array.isArray(data.items) ? data.items : [];
          } catch (err) {
            this.igScheduleErr = err && err.message ? err.message : String(err);
            this.igSchedules = [];
          } finally {
            this.igScheduleLoading = false;
          }
        },

        scheduleInstagramCarousel: async function () {
          this.igScheduleErr = '';
          this.igScheduleMsg = '';
          if (!this.garageIgId) {
            this.igScheduleErr = 'Choose a linked Instagram account.';
            return;
          }
          const d = this.igScheduleCombinedDate();
          if (!d) {
            this.igScheduleErr = 'Choose a valid date and time.';
            return;
          }
          if (d.getTime() < Date.now() + 60000) {
            this.igScheduleErr = 'Publish time must be at least one minute from now.';
            return;
          }
          const scheduledAt = d.toISOString();
          this.igScheduleLoading = true;
          try {
            const path = this.ffScriptPath();
            const res = await fetch(path + '?action=schedule_instagram_carousel', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                social_account_id: this.garageIgId,
                caption: this.igScheduleCaption || '',
                scheduled_at: scheduledAt,
                doc: this.doc,
              }),
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              throw new Error((data && data.error) ? String(data.error) : ('HTTP ' + res.status));
            }
            const n = data.image_count != null ? data.image_count : '?';
            this.igScheduleMsg = 'Scheduled (' + n + ' image' + (n === 1 ? '' : 's') + ').';
            await this.refreshInstagramSchedules();
            await this.refreshMediaLibrary();
          } catch (err) {
            this.igScheduleErr = err && err.message ? err.message : String(err);
          } finally {
            this.igScheduleLoading = false;
          }
        },

        postInstagramCarouselNow: async function () {
          this.igScheduleErr = '';
          this.igScheduleMsg = '';
          if (!this.garageIgId) {
            this.igScheduleErr = 'Choose a linked Instagram account.';
            return;
          }
          if (!window.confirm('Post this carousel to Instagram now? It will go live as soon as Meta accepts it (this cannot be undone from FormatForge).')) {
            return;
          }
          this.igScheduleLoading = true;
          try {
            const path = this.ffScriptPath();
            const res = await fetch(path + '?action=post_instagram_carousel_now', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                social_account_id: this.garageIgId,
                caption: this.igScheduleCaption || '',
                doc: this.doc,
              }),
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              throw new Error((data && data.error) ? String(data.error) : ('HTTP ' + res.status));
            }
            const n = data.image_count != null ? data.image_count : '?';
            if (data.publishing && data.id) {
              const rid = String(data.id);
              this.igScheduleMsg = 'Publishing ' + n + ' image' + (n === 1 ? '' : 's') + '… (Meta can take a minute; queued for tunnel-safe delivery).';
              const deadline = Date.now() + 180000;
              while (Date.now() < deadline) {
                await new Promise(function (r) { setTimeout(r, 2500); });
                await this.refreshInstagramSchedules();
                const rows = Array.isArray(this.igSchedules) ? this.igSchedules : [];
                let row = null;
                for (let i = 0; i < rows.length; i++) {
                  if (rows[i] && String(rows[i].id) === rid) {
                    row = rows[i];
                    break;
                  }
                }
                if (!row) {
                  continue;
                }
                if (row.status === 'published') {
                  const mid = row.ig_media_id ? String(row.ig_media_id) : '';
                  this.igScheduleMsg = 'Posted (' + n + ' image' + (n === 1 ? '' : 's') + ')' + (mid ? (' — id ' + mid) : '') + '.';
                  await this.refreshMediaLibrary();
                  return;
                }
                if (row.status === 'failed') {
                  this.igScheduleErr = row.schedule_error ? String(row.schedule_error) : 'Publish failed.';
                  return;
                }
              }
              this.igScheduleErr = 'Timed out waiting for Instagram. Check Upcoming — publishing may still finish.';
              return;
            }
            const mid = data.ig_media_id ? String(data.ig_media_id) : '';
            this.igScheduleMsg = 'Posted (' + n + ' image' + (n === 1 ? '' : 's') + ')' + (mid ? (' — id ' + mid) : '') + '.';
            await this.refreshInstagramSchedules();
            await this.refreshMediaLibrary();
          } catch (err) {
            this.igScheduleErr = err && err.message ? err.message : String(err);
          } finally {
            this.igScheduleLoading = false;
          }
        },

        cancelInstagramSchedule: async function (id) {
          if (!id) {
            return;
          }
          if (!window.confirm('Cancel this scheduled Instagram post?')) {
            return;
          }
          this.igScheduleErr = '';
          this.igScheduleMsg = '';
          this.igScheduleLoading = true;
          try {
            const path = this.ffScriptPath();
            const res = await fetch(path + '?action=cancel_instagram_schedule', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ id: String(id) }),
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              throw new Error((data && data.error) ? String(data.error) : ('HTTP ' + res.status));
            }
            this.igScheduleMsg = 'Cancelled.';
            await this.refreshInstagramSchedules();
          } catch (err) {
            this.igScheduleErr = err && err.message ? err.message : String(err);
          } finally {
            this.igScheduleLoading = false;
          }
        },

        refreshMediaLibrary: async function () {
          this.mediaErr = '';
          if (!window.__FF_USER__) {
            this.mediaPbInput = [];
            this.mediaPbOutput = [];
            this.mediaGarage = [];
            return;
          }
          this.mediaLoading = true;
          try {
            const path = this.ffScriptPath();
            let url = path + '?action=media_library';
            if (this.garageIgId) {
              url += '&social_account_id=' + encodeURIComponent(this.garageIgId);
            }
            const res = await fetch(url, { credentials: 'same-origin' });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              const base = (data && data.error) ? String(data.error) : ('HTTP ' + res.status);
              const detail = (data && data.detail) ? (' — ' + String(data.detail)) : '';
              throw new Error(base + detail);
            }
            this.mediaPbInput = Array.isArray(data.input_media) ? data.input_media : [];
            this.mediaPbOutput = Array.isArray(data.output_media) ? data.output_media : [];
            const g = data.garage && typeof data.garage === 'object' ? data.garage : {};
            this.mediaGarage = Array.isArray(g.items) ? g.items : [];
            const errParts = [];
            const pe = data.errors && typeof data.errors === 'object' ? data.errors : null;
            if (pe && pe.input_media) {
              errParts.push('Input media: ' + String(pe.input_media));
            }
            if (pe && pe.output_media) {
              errParts.push('Output (PocketBase): ' + String(pe.output_media));
            }
            if (data.garage && data.garage.error) {
              const gErr = String(data.garage.error);
              if (!pe || !pe.input_media || gErr !== String(pe.input_media)) {
                errParts.push('Garage: ' + gErr);
              }
            }
            this.mediaErr = errParts.join(' ');
          } catch (err) {
            this.mediaErr = err && err.message ? err.message : String(err);
            this.mediaPbInput = [];
            this.mediaPbOutput = [];
            this.mediaGarage = [];
          } finally {
            this.mediaLoading = false;
          }
        },

        garageUploadFile: async function (e) {
          this.mediaErr = '';
          const inp = e && e.target;
          const file = inp && inp.files && inp.files[0];
          if (!file || !this.garageIgId) {
            if (inp) inp.value = '';
            return;
          }
          this.garageUploading = true;
          try {
            const fd = new FormData();
            fd.append('social_account_id', this.garageIgId);
            fd.append('file', file);
            const path = this.ffScriptPath();
            const res = await fetch(path + '?action=garage_upload', {
              method: 'POST',
              credentials: 'same-origin',
              body: fd,
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              throw new Error(data.error || ('HTTP ' + res.status));
            }
            await this.refreshMediaLibrary();
          } catch (err) {
            this.mediaErr = err && err.message ? err.message : String(err);
          } finally {
            this.garageUploading = false;
            if (inp) inp.value = '';
          }
        },

        garageDeleteRel: async function (rel) {
          this.mediaErr = '';
          if (!rel || !this.garageIgId) return;
          if (!window.confirm('Delete ' + rel + ' from Garage?')) return;
          this.mediaLoading = true;
          try {
            const path = this.ffScriptPath();
            const res = await fetch(path + '?action=garage_delete', {
              method: 'POST',
              credentials: 'same-origin',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({ social_account_id: this.garageIgId, key: rel }),
            });
            const data = await res.json().catch(function () { return {}; });
            if (!res.ok) {
              throw new Error(data.error || ('HTTP ' + res.status));
            }
            await this.refreshMediaLibrary();
          } catch (err) {
            this.mediaErr = err && err.message ? err.message : String(err);
          } finally {
            this.mediaLoading = false;
          }
        },

        generateAi: async function () {
          this.aiError = '';
          if (!this.aiPrompt.trim()) {
            this.aiError = 'Enter a topic or outline.';
            return;
          }
          this.aiLoading = true;
          try {
            const genBody = { prompt: this.aiPrompt };
            if (this.garageIgId) {
              genBody.social_account_id = this.garageIgId;
            }
            const res = await fetch(GENERATE_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body: JSON.stringify(genBody),
            });
            const rawTxt = await res.text();
            let data = {};
            if (rawTxt) {
              try {
                data = JSON.parse(rawTxt);
              } catch (parseErr) {
                this.aiError = cgNonJsonApiError(res, rawTxt, 'Generate slides');
                return;
              }
            }
            if (!res.ok) {
              this.aiError = (data && data.error) ? data.error : ('HTTP ' + res.status);
              return;
            }
            const normalized = normalizeAiSlides(data.slides);
            if (!normalized || !normalized.length) {
              this.aiError = 'No slides returned.';
              return;
            }
            this.doc.slides = normalized;
            this.currentIndex = 0;
            this.carouselStop();
            this.carouselTimelineSec = 0;
            this.aiPrompt = '';
          } catch (err) {
            this.aiError = (err && err.message) ? err.message : 'Network error (fetch failed).';
          } finally {
            this.aiLoading = false;
          }
        },

        onImgGenFile: function (e) {
          this.imgGenError = '';
          const f = e.target.files && e.target.files[0];
          if (!f) {
            this.imgGenFileB64 = '';
            this.imgGenFileMime = '';
            return;
          }
          const self = this;
          const reader = new FileReader();
          reader.onload = function () {
            const s = String(reader.result || '');
            const m = /^data:([^;]+);base64,(.+)$/i.exec(s);
            if (m) {
              self.imgGenFileMime = m[1].trim();
              self.imgGenFileB64 = m[2].replace(/\s/g, '');
            } else {
              self.imgGenFileB64 = '';
              self.imgGenFileMime = '';
            }
          };
          reader.readAsDataURL(f);
        },

        generateImageAi: async function () {
          this.imgGenError = '';
          this.imgGenResultUrl = '';
          if (!IMAGE_GEN_URL) {
            this.imgGenError = 'Image generation URL not configured.';
            return;
          }
          const prompt = (this.imgGenPrompt || '').trim();
          if (!prompt) {
            this.imgGenError = 'Enter an image prompt.';
            return;
          }
          this.imgGenLoading = true;
          try {
            const payload = {
              prompt: prompt,
              aspect_ratio: this.imgGenAspect || '4:5',
              resolution: this.imgGenResolution || '2K',
            };
            const urlHint = (this.imgGenUrl || '').trim();
            if (urlHint && /^https?:\/\//i.test(urlHint)) {
              payload.image_url = urlHint;
            }
            if (this.imgGenFileB64) {
              payload.image_base64 = this.imgGenFileB64;
              payload.image_mime = this.imgGenFileMime || 'image/jpeg';
            }
            if (this.garageIgId) {
              payload.social_account_id = this.garageIgId;
            }
            const res = await fetch(IMAGE_GEN_URL, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              credentials: 'same-origin',
              body: JSON.stringify(payload),
            });
            const rawTxt = await res.text();
            let data = {};
            if (rawTxt) {
              try {
                data = JSON.parse(rawTxt);
              } catch (parseErr) {
                this.imgGenError = cgNonJsonApiError(res, rawTxt, 'Image generation');
                return;
              }
            }
            if (!res.ok) {
              this.imgGenError = data.error || ('HTTP ' + res.status);
              return;
            }
            if (!data.image_url && !data.public_image_url) {
              this.imgGenError = 'No image URL in response.';
              return;
            }
            this.imgGenResultUrl = data.public_image_url || data.image_url;
          } catch (err) {
            this.imgGenError = (err && err.message) ? err.message : 'Network error (fetch failed).';
          } finally {
            this.imgGenLoading = false;
          }
        },

        applyImgGenToCurrentSlide: function () {
          if (!this.imgGenResultUrl || !this.slide) {
            return;
          }
          this.applyContentImageUrl(this.imgGenResultUrl);
        },

        onImgGenDragStart: function (e) {
          if (!this.imgGenResultUrl || !e.dataTransfer) {
            return;
          }
          this.dragImgGenUrl = this.imgGenResultUrl;
          if (typeof window !== 'undefined') {
            window.__FF_DRAG_AI_IMG_URL__ = this.imgGenResultUrl;
          }
          e.dataTransfer.setData('text/plain', this.imgGenResultUrl);
          e.dataTransfer.setData('text/uri-list', this.imgGenResultUrl);
          e.dataTransfer.effectAllowed = 'copy';
        },

        onImgGenDragEnd: function () {
          this.dragImgGenUrl = '';
          setTimeout(function () {
            if (typeof window !== 'undefined') window.__FF_DRAG_AI_IMG_URL__ = '';
          }, 150);
        },

        resolvePreviewDropUrl: function (e) {
          var url = '';
          if (typeof window !== 'undefined' && window.__FF_DRAG_AI_IMG_URL__) {
            url = window.__FF_DRAG_AI_IMG_URL__;
          }
          if (!url) url = this.dragImgGenUrl || '';
          if (!url && e.dataTransfer) {
            try {
              url = e.dataTransfer.getData('text/plain')
                || e.dataTransfer.getData('text/uri-list')
                || e.dataTransfer.getData('URL')
                || '';
            } catch (err) {
              return '';
            }
          }
          url = String(url).split('\n')[0].trim();
          if (!url || !/^https?:\/\//i.test(url)) {
            return '';
          }
          return url;
        },

        onPreviewDragEnter: function (e) {
          e.preventDefault();
          this.previewDropActive = true;
        },

        onPreviewDragLeave: function (e) {
          e.preventDefault();
          const rect = e.currentTarget.getBoundingClientRect();
          const x = e.clientX;
          const y = e.clientY;
          if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
            return;
          }
          this.previewDropActive = false;
        },

        ensureContentImageOnSlide: function () {
          if (!this.slide) {
            return;
          }
          const els = this.slide.elements || [];
          for (let i = 0; i < els.length; i++) {
            if (els[i] && (els[i].type === 'ContentImage' || els[i].type === 'Image')) {
              return;
            }
          }
          els.push(defaultContentImage());
        },

        ensureContentImageStyle: function (el) {
          if (!el || (el.type !== 'ContentImage' && el.type !== 'Image')) {
            return;
          }
          if (!el.style || typeof el.style !== 'object') {
            el.style = {};
          }
          if (typeof el.style.imgScale !== 'number' || el.style.imgScale <= 0) {
            el.style.imgScale = 100;
          }
          if (el.style.imgScale > 400) {
            el.style.imgScale = 400;
          }
          if (typeof el.style.imgRotateDeg !== 'number') {
            el.style.imgRotateDeg = 0;
          }
          if (typeof el.style.imgPanX !== 'number') {
            el.style.imgPanX = 0;
          }
          if (typeof el.style.imgPanY !== 'number') {
            el.style.imgPanY = 0;
          }
        },

        nudgeContentImgScale: function (el, delta) {
          this.ensureContentImageStyle(el);
          const cur = Number(el.style.imgScale) || 100;
          el.style.imgScale = Math.round(Math.max(25, Math.min(400, cur + delta)));
        },

        applyContentImageUrl: function (url) {
          url = String(url || '').trim().split('\n')[0];
          if (!url || !this.slide) {
            return;
          }
          try {
            url = new URL(url, window.location.href).href;
          } catch (err) {
            return;
          }
          if (!/^https?:\/\//i.test(url)) {
            return;
          }
          this.ensureContentImageOnSlide();
          const els = this.slide.elements || [];
          for (let i = 0; i < els.length; i++) {
            const el = els[i];
            if (el && (el.type === 'ContentImage' || el.type === 'Image')) {
              if (!el.source) {
                el.source = { src: '', type: 'URL' };
              }
              el.source.src = url;
              el.source.type = 'URL';
              this.ensureContentImageStyle(el);
              return;
            }
          }
        },

        resetContentImageTransform: function (el) {
          this.ensureContentImageStyle(el);
          el.style.imgScale = 100;
          el.style.imgRotateDeg = 0;
          el.style.imgPanX = 0;
          el.style.imgPanY = 0;
        },

        clearContentImage: function (el) {
          if (!el || (el.type !== 'ContentImage' && el.type !== 'Image')) {
            return;
          }
          this.ensureContentImageStyle(el);
          if (!el.source) {
            el.source = { src: '', type: 'URL' };
          } else {
            el.source.src = '';
            el.source.type = 'URL';
          }
          this.resetContentImageTransform(el);
          this.carouselStop();
        },

        contentImageTransformStyle: function (el) {
          this.ensureContentImageStyle(el);
          const st = el.style;
          const scale = st.imgScale / 100;
          const rot = st.imgRotateDeg;
          const px = st.imgPanX;
          const py = st.imgPanY;
          return {
            transform: 'translate(' + px + 'px, ' + py + 'px) rotate(' + rot + 'deg) scale(' + scale + ')',
            transformOrigin: 'center center',
          };
        },

        slideHasContentImageWithSrc: function () {
          const els = (this.slide && this.slide.elements) || [];
          return els.some(function (el) {
            return el && (el.type === 'ContentImage' || el.type === 'Image')
              && el.source && el.source.src;
          });
        },

        slideHasContentImageElement: function () {
          const els = (this.slide && this.slide.elements) || [];
          return els.some(function (el) {
            return el && (el.type === 'ContentImage' || el.type === 'Image');
          });
        },

        removeContentImageElementAt: function (ei) {
          if (!this.slide || !Array.isArray(this.slide.elements)) {
            return;
          }
          const i = typeof ei === 'number' ? ei : parseInt(ei, 10);
          if (isNaN(i) || i < 0 || i >= this.slide.elements.length) {
            return;
          }
          const el = this.slide.elements[i];
          if (!el || (el.type !== 'ContentImage' && el.type !== 'Image')) {
            return;
          }
          this.carouselStop();
          this.slide.elements.splice(i, 1);
        },

        addContentImageElement: function () {
          if (!this.slide) {
            return;
          }
          if (!Array.isArray(this.slide.elements)) {
            this.slide.elements = [];
          }
          this.carouselStop();
          this.slide.elements.push(defaultContentImage());
        },

        contentImgPointerDown: function (ei, ev) {
          if (ev.button !== 0 || !this.slide) {
            return;
          }
          const el = this.slide.elements[ei];
          if (!el || (el.type !== 'ContentImage' && el.type !== 'Image') || !el.source || !el.source.src) {
            return;
          }
          ev.preventDefault();
          if (typeof ev.target.setPointerCapture === 'function' && ev.pointerId != null) {
            try {
              ev.target.setPointerCapture(ev.pointerId);
            } catch (err) { /* ignore */ }
          }
          this.ensureContentImageStyle(el);
          const self = this;
          const startX = ev.clientX;
          const startY = ev.clientY;
          const ox = el.style.imgPanX;
          const oy = el.style.imgPanY;
          const cap = 220;
          function move(e) {
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            el.style.imgPanX = Math.round(Math.max(-cap, Math.min(cap, ox + dx)));
            el.style.imgPanY = Math.round(Math.max(-cap, Math.min(cap, oy + dy)));
          }
          function up() {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', up);
            window.removeEventListener('pointercancel', up);
          }
          window.addEventListener('pointermove', move);
          window.addEventListener('pointerup', up);
          window.addEventListener('pointercancel', up);
        },

        onContentImgWheel: function (ei, ev) {
          if (!this.slide) {
            return;
          }
          const el = this.slide.elements[ei];
          if (!el || (el.type !== 'ContentImage' && el.type !== 'Image') || !el.source || !el.source.src) {
            return;
          }
          ev.preventDefault();
          this.ensureContentImageStyle(el);
          const d = ev.deltaY;
          if (ev.shiftKey) {
            let r = el.style.imgRotateDeg - Math.sign(d) * 4;
            el.style.imgRotateDeg = Math.max(-180, Math.min(180, r));
          } else {
            let s = el.style.imgScale - Math.sign(d) * 4;
            el.style.imgScale = Math.max(25, Math.min(400, s));
          }
        },

        previewStyle: function () {
          const t = this.doc.config.theme;
          return {
            background: t.background,
            color: t.primary,
            '--cg-secondary': t.secondary,
          };
        },

        titleFontFamily: function () {
          return FONT_CSS[this.doc.config.fonts.font1] || FONT_CSS.DM_Serif_Display;
        },

        bodyFontFamily: function () {
          return FONT_CSS[this.doc.config.fonts.font2] || FONT_CSS.DM_Sans;
        },

        elementStyle: function (el) {
          const align =
            el.style && el.style.align === 'Center'
              ? 'center'
              : el.style && el.style.align === 'Right'
                ? 'right'
                : 'left';
          const size =
            el.style && el.style.fontSize === 'Large'
              ? '1.35rem'
              : el.style && el.style.fontSize === 'Small'
                ? '0.95rem'
                : '1.1rem';
          const fontWeight = '400';
          if (el.type === 'Title') {
            return {
              textAlign: align,
              fontSize: 'clamp(1.5rem, 4vw, 2.25rem)',
              fontWeight: '600',
              lineHeight: 1.15,
              fontFamily: this.titleFontFamily(),
            };
          }
          if (el.type === 'Subtitle') {
            return {
              textAlign: align,
              fontSize: 'clamp(1.1rem, 2.5vw, 1.35rem)',
              fontWeight: '500',
              opacity: 0.92,
              fontFamily: this.bodyFontFamily(),
            };
          }
          return {
            textAlign: align,
            fontSize: size,
            fontWeight: fontWeight,
            lineHeight: 1.45,
            opacity: 0.88,
            fontFamily: this.bodyFontFamily(),
          };
        },

        elementStyleThumb: function (el) {
          const align =
            el.style && el.style.align === 'Center'
              ? 'center'
              : el.style && el.style.align === 'Right'
                ? 'right'
                : 'left';
          const ffTitle = this.titleFontFamily();
          const ffBody = this.bodyFontFamily();
          if (el.type === 'Title') {
            return {
              textAlign: align,
              fontSize: '0.58rem',
              fontWeight: '600',
              lineHeight: 1.12,
              fontFamily: ffTitle,
            };
          }
          if (el.type === 'Subtitle') {
            return {
              textAlign: align,
              fontSize: '0.48rem',
              fontWeight: '500',
              opacity: 0.92,
              fontFamily: ffBody,
            };
          }
          return {
            textAlign: align,
            fontSize: '0.42rem',
            fontWeight: '400',
            lineHeight: 1.35,
            opacity: 0.88,
            fontFamily: ffBody,
          };
        },
      };
    });
  });
})();
    </script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.3/dist/cdn.min.js"></script>
</body>
</html>
