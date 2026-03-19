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

$pbUrl = null;
if (file_exists('/.dockerenv')) {
    $pbUrl = 'http://pocketbase:8090';
} elseif (file_exists(__DIR__ . '/.pb-port')) {
    $pbUrl = 'http://127.0.0.1:' . trim(file_get_contents(__DIR__ . '/.pb-port') ?: '');
} else {
    $pbUrl = getenv('POCKETBASE_URL') ?: 'http://127.0.0.1:8090';
}
$siteUrl = getenv('APP_URL');
if (!$siteUrl) {
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $proto = 'http';
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'];
    elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') $proto = 'https';
    if (!empty($_SERVER['HTTP_X_FORWARDED_HOST'])) $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
    $siteUrl = $proto . '://' . preg_replace('/:\d+$/', '', $host);
}
$pbPublicUrl = getenv('POCKETBASE_PUBLIC_URL') ?: rtrim($siteUrl, '/') . '/pb';
$CONFIG = [
    'pocketbase_url'   => $pbUrl,
    'pocketbase_public_url' => $pbPublicUrl,
    'site_url'         => $siteUrl,
    'site_name'        => getenv('SITE_NAME') ?: 'FormatForge',
    'app_version'      => getenv('APP_VERSION') ?: 'v1.0.9',
    'users_collection' => 'users',
    'garage_endpoint'  => getenv('GARAGE_ENDPOINT') ?: 'http://127.0.0.1:3900',
    'garage_key'       => getenv('GARAGE_ACCESS_KEY') ?: '',
    'garage_secret'    => getenv('GARAGE_SECRET_KEY') ?: '',
    'garage_bucket'    => getenv('GARAGE_BUCKET') ?: 'formatforge',
    'garage_region'    => getenv('GARAGE_REGION') ?: 'garage',
    'replicate_token' => getenv('REPLICATE_API_TOKEN') ?: '',
    'fal_key'         => getenv('FAL_KEY') ?: '',
    'video_provider'   => getenv('VIDEO_PROVIDER') ?: '',  // replicate|fal; auto if empty
    'fb_app_id'       => getenv('FB_APP_ID') ?: '',
    'fb_app_secret'    => getenv('FB_APP_SECRET') ?: '',
    'instagram_redirect' => getenv('INSTAGRAM_REDIRECT_URI') ?: '',
    'antfly_url'       => rtrim(
        file_exists(__DIR__ . '/.antfly-port')
            ? 'http://127.0.0.1:' . trim(file_get_contents(__DIR__ . '/.antfly-port') ?: '')
            : (getenv('ANTFLY_URL') ?: 'http://127.0.0.1:8080'),
        '/'
    ),
    'antfly_key'       => getenv('ANTFLY_API_KEY') ?: '',
    'winning_threshold' => (float)(getenv('WINNING_TEMPLATE_VIEW_SHARE_RATIO') ?: '0.05'),
    'ffmpeg_path'      => getenv('FFMPEG_PATH') ?: '/usr/bin/ffmpeg',
    'gallery_dl_path'   => getenv('GALLERY_DL_PATH') ?: 'gallery-dl',
    'yt_dlp_path'      => getenv('YT_DLP_PATH') ?: 'yt-dlp',
    'embed_url'        => getenv('EMBED_URL') ?: '',  // Ollama-compatible: /api/embed
    'openai_key'       => getenv('OPENAI_API_KEY') ?: '',
    'openrouter_key'   => getenv('OPENROUTER_API_KEY') ?: '',
    'embed_model'      => getenv('EMBED_MODEL') ?: 'openai/text-embedding-3-small',  // for OpenRouter
    'pi_trigger_dir'  => getenv('PI_TRIGGER_DIR') ?: (__DIR__ . '/.pi/triggers'),
    'novel_threshold'  => (float)(getenv('NOVEL_DISTANCE_THRESHOLD') ?: '0.35'),  // cosine distance above = novel
];
if (file_exists(__DIR__ . '/config.php')) {
    $CONFIG = array_merge($CONFIG, require __DIR__ . '/config.php');
}

function ff_debug_sanitize($value, string $key = '') {
    $k = strtolower($key);
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
    $fetched = pb_fetch_collection('instagram_accounts', $token);
    if (!$fetched['ok']) return ['ok' => false, 'error' => $fetched['error'] ?? 'Collection fetch failed'];
    $collection = $fetched['collection'];
    $fields = is_array($collection['fields'] ?? null) ? $collection['fields'] : [];
    $required = [
        ['name' => 'instagram_user_id', 'type' => 'text'],
        ['name' => 'username', 'type' => 'text'],
        ['name' => 'access_token', 'type' => 'text'],
        ['name' => 'token_expires_at', 'type' => 'date'],
        ['name' => 'is_active', 'type' => 'bool'],
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
        'name' => $collection['name'] ?? 'instagram_accounts',
        'type' => $collection['type'] ?? 'base',
        'listRule' => $collection['listRule'] ?? '@request.auth.id != ""',
        'viewRule' => $collection['viewRule'] ?? '@request.auth.id != ""',
        'createRule' => $collection['createRule'] ?? '@request.auth.id != ""',
        'updateRule' => $collection['updateRule'] ?? '@request.auth.id != ""',
        'deleteRule' => $collection['deleteRule'] ?? '@request.auth.id != ""',
        'fields' => $fields,
    ];
    if (isset($collection['indexes'])) $payload['indexes'] = $collection['indexes'];

    $collectionId = $collection['id'] ?? 'instagram_accounts';
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

function pb_request(string $method, string $path, $data = null, ?string $token = null): array {
    $ch = curl_init($GLOBALS['CONFIG']['pocketbase_url'] . $path);
    $headers = ['Content-Type: application/json'];
    if ($token) $headers[] = 'Authorization: ' . $token;
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($data !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? json_encode($data) : $data);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errNo = curl_errno($ch);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    return ['code' => $code, 'body' => $body, 'raw' => $res ?: '', 'curl_errno' => $errNo];
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
    $resp = pb_request('GET', '/api/collections/instagram_accounts/records?' . $query, null, $authHeader);
    if ($resp['code'] !== 200) return null;
    return $resp['body']['items'][0] ?? null;
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
    return ($code >= 200 && $code < 300) ? ($cfg['garage_endpoint'] . '/' . $cfg['garage_bucket'] . '/' . $key) : null;
}

function replicate_run(string $model, array $input, int $waitSec = 60): ?array {
    $token = $GLOBALS['CONFIG']['replicate_token'];
    if (!$token) return null;
    $ch = curl_init('https://api.replicate.com/v1/predictions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
            'Prefer: wait=' . min(60, max(1, $waitSec)),
        ],
        CURLOPT_POSTFIELDS => json_encode(['version' => $model, 'input' => $input]),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $body = json_decode($res ?: '{}', true) ?? [];
    if ($code >= 200 && $code < 300 && ($body['status'] ?? '') === 'succeeded') return $body;
    if (in_array($body['status'] ?? '', ['starting', 'processing'])) {
        $id = $body['id'] ?? null;
        if ($id) {
            for ($i = 0; $i < 30; $i++) {
                sleep(2);
                $get = curl_init("https://api.replicate.com/v1/predictions/{$id}");
                curl_setopt_array($get, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token]]);
                $r2 = curl_exec($get);
                curl_close($get);
                $b2 = json_decode($r2 ?: '{}', true) ?? [];
                if (($b2['status'] ?? '') === 'succeeded') return $b2;
                if (in_array($b2['status'] ?? '', ['failed', 'canceled'])) return null;
            }
        }
    }
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

/**
 * Download media from URL using gallery-dl (images) or yt-dlp (video/audio).
 * @param string $url Source URL
 * @param string $downloader 'gallery-dl' or 'yt-dlp'
 * @return array{0: array, 1: ?string} [list of file paths, temp dir for cleanup]
 */
function fetch_media_from_url(string $url, string $downloader): array {
    $cfg = $GLOBALS['CONFIG'];
    $tmpDir = sys_get_temp_dir() . '/ff_fetch_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpDir, 0755, true)) return [[], null];
    $files = [];
    if ($downloader === 'gallery-dl') {
        $bin = $cfg['gallery_dl_path'] ?? 'gallery-dl';
        $cmd = escapeshellcmd($bin) . ' -d ' . escapeshellarg($tmpDir) . ' ' . escapeshellarg($url) . ' 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            @array_map('unlink', glob($tmpDir . '/*/*') ?: []);
            @array_map('rmdir', glob($tmpDir . '/*') ?: []);
            @rmdir($tmpDir);
            return [[], null];
        }
        $all = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($it as $f) { if ($f->isFile()) $all[] = $f->getPathname(); }
        $files = $all;
    } elseif ($downloader === 'yt-dlp') {
        $bin = $cfg['yt_dlp_path'] ?? 'yt-dlp';
        $outFile = $tmpDir . '/%(id)s.%(ext)s';
        $cmd = escapeshellcmd($bin) . ' -o ' . escapeshellarg($outFile) . ' ' . escapeshellarg($url) . ' 2>/dev/null';
        exec($cmd, $out, $code);
        if ($code !== 0) {
            @array_map('unlink', glob($tmpDir . '/*') ?: []);
            @rmdir($tmpDir);
            return [[], null];
        }
        $files = glob($tmpDir . '/*') ?: [];
    }
    $files = array_values(array_filter($files, 'is_file'));
    return [$files, $tmpDir];
}

function antfly_index(string $table, array $doc): bool {
    $cfg = $GLOBALS['CONFIG'];
    $url = $cfg['antfly_url'] . '/api/v1/tables/' . urlencode($table) . '/batch';
    $key = !empty($doc['id']) ? ('content:' . $doc['id']) : ('doc:' . bin2hex(random_bytes(8)));
    $inserts = [$key => $doc];
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['antfly_key'])) $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode(['inserts' => $inserts]),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 404 && antfly_create_table($table)) {
        return antfly_index($table, $doc);
    }
    return $code >= 200 && $code < 300;
}

function antfly_create_table(string $table): bool {
    if ($table !== 'content') return false;
    $cfg = $GLOBALS['CONFIG'];
    $url = $cfg['antfly_url'] . '/api/v1/tables/content';
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
                        ],
                        'x-antfly-include-in-all' => ['prompt'],
                    ],
                ],
            ],
            'default_type' => 'content',
        ],
        'indexes' => ['search_idx' => ['type' => 'full_text']],
    ];
    $headers = ['Content-Type: application/json'];
    if (!empty($cfg['antfly_key'])) $headers[] = 'Authorization: Bearer ' . $cfg['antfly_key'];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body),
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

function embed_text(string $text): ?array {
    $cfg = $GLOBALS['CONFIG'];
    // OpenRouter (embeddings + pi) — preferred if key set
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
            ]),
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

function content_is_novel(string $prompt, array $existingPrompts): bool {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['embed_url']) && empty($cfg['openai_key']) && empty($cfg['openrouter_key'])) return false;
    $vec = embed_text($prompt);
    if (!$vec) return false;
    foreach ($existingPrompts as $p) {
        $ev = embed_text($p);
        if (!$ev) continue;
        if (cosine_distance($vec, $ev) < $cfg['novel_threshold']) return false;
    }
    return true;
}

function trigger_pi_for_pipeline(string $reason, array $context): void {
    $cfg = $GLOBALS['CONFIG'];
    $dir = $cfg['pi_trigger_dir'] ?? '';
    if (!$dir) return;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) return;
    if (!is_writable($dir)) return;
    $file = $dir . '/trigger_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.json';
    $payload = [
        'reason' => $reason,
        'context' => $context,
        'created' => date('c'),
        'template_path' => __DIR__ . '/pipelines/template',
    ];
    if (@file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT))) {
        setup_pipeline_from_trigger($file);
    }
}

function setup_pipeline_from_trigger(string $triggerFile): void {
    $projectRoot = __DIR__;
    $cfg = $GLOBALS['CONFIG'];
    $trigger = json_decode(file_get_contents($triggerFile), true) ?: [];
    $reason = $trigger['reason'] ?? 'unknown';
    $context = $trigger['context'] ?? [];
    $templatePath = $trigger['template_path'] ?? $projectRoot . '/pipelines/template';
    $created = $trigger['created'] ?? date('c');
    if (!is_dir($templatePath)) return;
    $triggerBase = basename($triggerFile, '.json');
    $pipelineId = str_replace('trigger_', '', $triggerBase);
    $pipelineDir = $projectRoot . '/pipelines/pipeline-' . $pipelineId;
    if (!is_dir($pipelineDir)) mkdir($pipelineDir, 0755, true);
    foreach (glob($templatePath . '/*') ?: [] as $f) {
        if (is_file($f)) copy($f, $pipelineDir . '/' . basename($f));
    }
    if (is_file($templatePath . '/.env.example')) copy($templatePath . '/.env.example', $pipelineDir . '/.env.example');
    $envVars = ['REPLICATE_API_TOKEN', 'FAL_KEY', 'FAL_VIDEO_MODEL', 'VIDEO_PROVIDER', 'POCKETBASE_URL', 'GARAGE_ENDPOINT', 'GARAGE_ACCESS_KEY', 'GARAGE_SECRET_KEY', 'GARAGE_BUCKET', 'GARAGE_REGION'];
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
    $piDir = $projectRoot . '/.pi';
    if (!is_dir($piDir)) mkdir($piDir, 0755, true);
    if (!is_dir($piDir . '/prompts')) mkdir($piDir . '/prompts', 0755, true);
    $piEnvFile = $piDir . '/pipeline-' . $pipelineId . '.env';
    $piEnvLines = [];
    if (is_file($projectEnv)) {
        $piVars = ['OPENROUTER_API_KEY', 'PI_MODEL', 'ANTHROPIC_API_KEY', 'OPENAI_API_KEY'];
        foreach (file($projectEnv, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if (strpos($line, '#') === 0) continue;
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m) && in_array(trim($m[1]), $piVars)) $piEnvLines[] = 'export ' . $m[1] . '=' . escapeshellarg(trim($m[2], " \t\"'"));
        }
    }
    $hasPiModel = false;
    foreach ($piEnvLines as $l) { if (strpos($l, 'PI_MODEL') !== false) { $hasPiModel = true; break; } }
    if (!$hasPiModel) $piEnvLines[] = 'export PI_MODEL="openai/gpt-4o-mini"';
    file_put_contents($piEnvFile, implode("\n", $piEnvLines) . "\n");
    $promptFile = $piDir . '/prompts/pipeline-' . $pipelineId . '.md';
    $contextJson = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $task = $reason === 'content_rejected'
        ? "Content was rejected. Create or edit a content pipeline to improve output.\n\n1. Pipeline dir: `$pipelineDir`\n2. Edit `$pipelineDir/.env` PROMPT or PROMPT_TEMPLATE based on rejected content and reason\n3. Build: `cd $pipelineDir && go build -o pipeline-generate .`\n4. Crontab: `0 */6 * * * cd $pipelineDir && set -a && . .env && set +a && ./pipeline-generate`"
        : "Novel content.\n\n1. Pipeline dir: `$pipelineDir`\n2. .env copied. Build: `cd $pipelineDir && go build -o pipeline-generate .`\n3. Crontab: `0 */6 * * * cd $pipelineDir && set -a && . .env && set +a && ./pipeline-generate`";
    $promptContent = "# FormatForge pipeline setup\n\n**Trigger:** $reason\n**Created:** $created\n\n## Context\n\n```json\n$contextJson\n```\n\n## Task\n\n$task\n\n## Pi env\n\nSource: `$piEnvFile`\n\nRequired: OPENROUTER_API_KEY or ANTHROPIC_API_KEY or OPENAI_API_KEY\nModel: PI_MODEL\n";
    file_put_contents($promptFile, $promptContent);
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
    $list = pb_request('GET', '/api/collections/instagram_accounts/records?perPage=50', null, $token);
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

// CLI: php index.php setup-pipeline [trigger_file]
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === 'setup-pipeline') {
    $triggerFile = $argv[2] ?? null;
    $triggerDir = $GLOBALS['CONFIG']['pi_trigger_dir'] ?? __DIR__ . '/.pi/triggers';
    if (!$triggerFile) {
        $files = glob($triggerDir . '/trigger_*.json');
        if (empty($files)) { fwrite(STDERR, "No trigger. Usage: php index.php setup-pipeline [trigger_file]\n"); exit(1); }
        usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
        $triggerFile = $files[0];
    }
    if (!is_file($triggerFile)) { fwrite(STDERR, "Not found: $triggerFile\n"); exit(1); }
    setup_pipeline_from_trigger($triggerFile);
    exit(0);
}

function fetch_recent_content_prompts(?string $authHeader): array {
    $qs = http_build_query(['filter' => 'status="approved" || status="published"', 'perPage' => 50, 'sort' => '-created']);
    $r = pb_request('GET', '/api/collections/content_items/records?' . $qs, null, $authHeader);
    if ($r['code'] !== 200 || empty($r['body']['items'])) return [];
    $out = [];
    foreach ($r['body']['items'] as $it) {
        $p = $it['prompt'] ?? '';
        if ($p) $out[] = $p;
    }
    return $out;
}

function maybe_trigger_pi(string $action, array $context): void {
    $cfg = $GLOBALS['CONFIG'];
    if (empty($cfg['pi_trigger_dir'])) return;
    if ($action === 'reject') {
        trigger_pi_for_pipeline('content_rejected', $context);
        return;
    }
    if (($action === 'add_link' || $action === 'approve') && !empty($context['prompt'])) {
        $existing = $context['existing_prompts'] ?? [];
        if (content_is_novel($context['prompt'], $existing)) {
            trigger_pi_for_pipeline('novel_content', $context);
        }
    }
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
    foreach ($pages as $page) {
        $pageId = $page['id'] ?? '';
        $pageName = $page['name'] ?? '';
        $pageToken = trim((string) ($page['access_token'] ?? ''));
        if ($pageToken === '') $pageToken = $fbToken;
        if (!$pageId) continue;
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
            continue;
        }

        $igUserId = trim((string) ($igBiz['id'] ?? ''));
        if ($igUserId === '') continue;
        if (isset($seenIgUsers[$igUserId])) continue;
        $seenIgUsers[$igUserId] = true;

        $username = normalize_instagram_username($igBiz['username'] ?? null);
        $usernameSource = $username ? ($igSource . '_username') : 'fetch_api';
        if (!$username) $username = fetch_instagram_username($igUserId, [$fbToken, $pageToken]);
        if (!$username) {
            $username = 'ig_' . $igUserId;
            $usernameSource = 'fallback_ig_id';
        }

        $payload = [
            'instagram_user_id' => $igUserId,
            'username' => $username,
            'access_token' => $pageToken,
            'is_active' => true,
        ];
        $existing = pb_find_instagram_account_by_user_id($igUserId, $authHeader);
        if ($existing && !empty($existing['id'])) {
            $rec = pb_request('PATCH', "/api/collections/instagram_accounts/records/{$existing['id']}", $payload, $authHeader);
            $upsertMode = 'update';
        } else {
            $rec = pb_request('POST', '/api/collections/instagram_accounts/records', $payload, $authHeader);
            $upsertMode = 'create';
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
                    $retry = pb_request('PATCH', "/api/collections/instagram_accounts/records/{$targetId}", $payload, $authHeader);
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
        if ($rec['code'] >= 200 && $rec['code'] < 300) $saved++;
    }
    ff_debug_log('instagram_callback_complete', ['saved' => $saved, 'pages_count' => count($pages)]);

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

// API: add link, generate, approve, reject, publish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && $authHeader) {
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

    if ($action === 'debug_account_snapshot') {
        $list = pb_request('GET', '/api/collections/instagram_accounts/records?sort=-created&perPage=50', null, $authHeader);
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
            $coll = pb_fetch_collection('instagram_accounts', $adminAuth['token']);
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

    if ($action === 'refresh_instagram_username') {
        $accountId = trim($_POST['account_id'] ?? '');
        ff_debug_log('refresh_username_start', ['account_id' => $accountId ?: null]);
        if (!$accountId) {
            ff_debug_log('refresh_username_failed', ['reason' => 'missing_account_id']);
            echo json_encode(['ok' => false, 'error' => 'Missing account_id']);
            exit;
        }
        $accResp = pb_request('GET', "/api/collections/instagram_accounts/records/{$accountId}", null, $authHeader);
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
        $up = pb_request('PATCH', "/api/collections/instagram_accounts/records/{$accountId}", ['username' => $username, 'is_active' => true], $authHeader);
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
        $url = trim($_POST['url'] ?? '');
        $accountId = trim($_POST['account_id'] ?? '') ?: null;
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
            exit;
        }
        $rec = pb_request('POST', '/api/collections/source_links/records', [
            'url' => $url,
            'status' => 'pending',
            'metadata' => $accountId ? ['instagram_account_id' => $accountId] : (object)[],
        ], $authHeader);
        if ($rec['code'] >= 200 && $rec['code'] < 300) {
            $existing = fetch_recent_content_prompts($authHeader);
            maybe_trigger_pi('add_link', ['url' => $url, 'prompt' => $url, 'existing_prompts' => $existing]);
            echo json_encode(['ok' => true, 'id' => $rec['body']['id'] ?? null]);
        } else {
            echo json_encode(['ok' => false, 'error' => $rec['body']['message'] ?? 'Failed']);
        }
        exit;
    }

    if ($action === 'fetch_link') {
        $linkId = trim($_POST['link_id'] ?? '');
        $downloader = $_POST['downloader'] ?? 'gallery-dl';
        $contentType = $_POST['content_type'] ?? 'auto';
        if (!in_array($downloader, ['gallery-dl', 'yt-dlp'])) $downloader = 'gallery-dl';
        if (!in_array($contentType, ['carousel', 'video', 'image', 'auto'])) $contentType = 'auto';
        if (!$linkId) {
            echo json_encode(['ok' => false, 'error' => 'Missing link_id']);
            exit;
        }
        $link = pb_request('GET', "/api/collections/source_links/records/{$linkId}", null, $authHeader);
        if ($link['code'] !== 200 || empty($link['body']['url'])) {
            echo json_encode(['ok' => false, 'error' => 'Link not found']);
            exit;
        }
        $url = $link['body']['url'];
        $linkAccountId = $link['body']['metadata']['instagram_account_id'] ?? null;
        [$files, $tmpDir] = fetch_media_from_url($url, $downloader);
        $created = 0;
        $cfg = $GLOBALS['CONFIG'];
        foreach ($files as $path) {
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
            $key = 'content/' . $itemId . '/' . basename($path);
            $garageUrl = s3_upload($key, $content, $mime);
            if (!$garageUrl && $content) $garageUrl = $cfg['garage_endpoint'] . '/' . $cfg['garage_bucket'] . '/' . $key;
            $type = $contentType !== 'auto'
                ? ($contentType === 'video' ? 'video' : ($contentType === 'image' ? 'image' : 'carousel'))
                : (str_starts_with($mime, 'video/') ? 'video' : (str_starts_with($mime, 'image/') ? 'carousel' : 'reel'));
            $rec = pb_request('POST', '/api/collections/content_items/records', [
                'type' => $type,
                'title' => basename($path),
                'prompt' => $url,
                'source_link_id' => $linkId,
                'status' => 'pending',
                'garage_key' => $key,
                'garage_url' => $garageUrl ?: '',
                'instagram_account_id' => $linkAccountId,
            ], $authHeader);
            if ($rec['code'] >= 200 && $rec['code'] < 300) $created++;
        }
        foreach ($files as $path) @unlink($path);
        if ($tmpDir && is_dir($tmpDir)) {
            $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iter as $f) { $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
            @rmdir($tmpDir);
        }
        pb_request('PATCH', "/api/collections/source_links/records/{$linkId}", ['status' => 'fetched'], $authHeader);
        echo json_encode(['ok' => true, 'created' => $created, 'downloader' => $downloader]);
        exit;
    }

    if ($action === 'generate_content') {
        $prompt = trim($_POST['prompt'] ?? 'A cinematic shot of a person walking through a modern city at sunset');
        $sourceId = $_POST['source_id'] ?? '';
        $rec = pb_request('POST', '/api/collections/content_items/records', [
            'type' => 'reel',
            'prompt' => $prompt,
            'title' => substr($prompt, 0, 80),
            'source_link_id' => $sourceId ?: null,
            'status' => 'generating',
        ], $authHeader);
        if ($rec['code'] < 200 || $rec['code'] >= 300) {
            echo json_encode(['ok' => false, 'error' => $rec['body']['message'] ?? 'Failed']);
            exit;
        }
        $itemId = $rec['body']['id'] ?? null;
        $cfg = $GLOBALS['CONFIG'];
        $provider = $cfg['video_provider'] ?: ($cfg['replicate_token'] ? 'replicate' : ($cfg['fal_key'] ? 'fal' : 'replicate'));
        $videoUrl = null;
        $errMsg = 'Video generation failed';

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
            $pred = replicate_run($model, ['prompt' => $prompt], 120);
            if ($pred && !empty($pred['output'])) {
                $videoUrl = is_array($pred['output']) ? ($pred['output'][0] ?? $pred['output']['url'] ?? null) : $pred['output'];
            } else {
                $errMsg = 'Replicate generation failed';
            }
        }

        if (!$videoUrl) {
            pb_request('PATCH', "/api/collections/content_items/records/{$itemId}", ['status' => 'failed'], $authHeader);
            echo json_encode(['ok' => false, 'error' => $errMsg]);
            exit;
        }
        $videoData = @file_get_contents($videoUrl) ?: '';
        if (!$videoData) {
            $ch = curl_init($videoUrl);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true]);
            $videoData = curl_exec($ch);
            curl_close($ch);
        }
        $key = 'content/' . $itemId . '/' . date('YmdHis') . '.mp4';
        $garageUrl = s3_upload($key, $videoData ?: '', 'video/mp4');
        if (!$garageUrl && $videoData) $garageUrl = $GLOBALS['CONFIG']['garage_endpoint'] . '/' . $GLOBALS['CONFIG']['garage_bucket'] . '/' . $key;
        pb_request('PATCH', "/api/collections/content_items/records/{$itemId}", [
            'status' => 'pending',
            'garage_key' => $key,
            'garage_url' => $garageUrl ?: $videoUrl,
        ], $authHeader);
        antfly_index('content', ['id' => $itemId, 'prompt' => $prompt, 'type' => 'reel', 'status' => 'pending']);
        echo json_encode(['ok' => true, 'id' => $itemId, 'url' => $garageUrl ?: $videoUrl]);
        exit;
    }

    if ($action === 'approve_content') {
        $id = $_POST['id'] ?? '';
        $accountId = $_POST['account_id'] ?? '';
        if (!$id || !$accountId) { echo json_encode(['ok' => false, 'error' => 'Missing id or account_id']); exit; }
        $item = pb_request('GET', "/api/collections/content_items/records/{$id}", null, $authHeader);
        $up = pb_request('PATCH', "/api/collections/content_items/records/{$id}", [
            'status' => 'approved',
            'instagram_account_id' => $accountId,
        ], $authHeader);
        if ($up['code'] >= 200 && $up['code'] < 300 && $item['code'] === 200) {
            $prompt = $item['body']['prompt'] ?? '';
            $existing = fetch_recent_content_prompts($authHeader);
            maybe_trigger_pi('approve', ['id' => $id, 'prompt' => $prompt, 'existing_prompts' => $existing]);
        }
        echo json_encode(['ok' => $up['code'] >= 200 && $up['code'] < 300]);
        exit;
    }

    if ($action === 'reject_content') {
        $id = $_POST['id'] ?? '';
        $reason = $_POST['reason'] ?? '';
        if (!$id) { echo json_encode(['ok' => false, 'error' => 'Missing id']); exit; }
        $item = pb_request('GET', "/api/collections/content_items/records/{$id}", null, $authHeader);
        $up = pb_request('PATCH', "/api/collections/content_items/records/{$id}", [
            'status' => 'rejected',
            'rejected_reason' => $reason,
        ], $authHeader);
        if ($up['code'] >= 200 && $up['code'] < 300 && $item['code'] === 200) {
            maybe_trigger_pi('reject', [
                'id' => $id,
                'prompt' => $item['body']['prompt'] ?? '',
                'rejected_reason' => $reason,
            ]);
        }
        echo json_encode(['ok' => $up['code'] >= 200 && $up['code'] < 300]);
        exit;
    }

    if ($action === 'publish_content') {
        $id = $_POST['id'] ?? '';
        $accountId = $_POST['account_id'] ?? '';
        if (!$id || !$accountId) { echo json_encode(['ok' => false, 'error' => 'Missing params']); exit; }
        $item = pb_request('GET', "/api/collections/content_items/records/{$id}", null, $authHeader);
        $acc = pb_request('GET', "/api/collections/instagram_accounts/records/{$accountId}", null, $authHeader);
        if ($item['code'] !== 200 || $acc['code'] !== 200) {
            echo json_encode(['ok' => false, 'error' => 'Not found']);
            exit;
        }
        $item = $item['body'];
        $acc = $acc['body'];
        $mediaUrl = $item['garage_url'] ?? '';
        if (!$mediaUrl) { echo json_encode(['ok' => false, 'error' => 'No media URL']); exit; }
        $igToken = $acc['access_token'] ?? '';
        $igUserId = $acc['instagram_user_id'] ?? '';
        $ch = curl_init("https://graph.instagram.com/v18.0/{$igUserId}/media");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'media_type' => 'REELS',
                'video_url' => $mediaUrl,
                'access_token' => $igToken,
            ]),
        ]);
        $res = curl_exec($ch);
        curl_close($ch);
        $create = json_decode($res ?: '{}', true) ?? [];
        $containerId = $create['id'] ?? null;
        if (!$containerId) {
            echo json_encode(['ok' => false, 'error' => $create['error']['message'] ?? 'Create container failed']);
            exit;
        }
        sleep(2);
        $ch2 = curl_init("https://graph.instagram.com/v18.0/{$igUserId}/media_publish");
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'creation_id' => $containerId,
                'access_token' => $igToken,
            ]),
        ]);
        $res2 = curl_exec($ch2);
        curl_close($ch2);
        $pub = json_decode($res2 ?: '{}', true) ?? [];
        $mediaId = $pub['id'] ?? null;
        if ($mediaId) {
            pb_request('PATCH', "/api/collections/content_items/records/{$id}", [
                'status' => 'published',
                'published_at' => date('c'),
                'instagram_account_id' => $accountId,
            ], $authHeader);
            pb_request('POST', '/api/collections/content_metrics/records', [
                'content_item_id' => $id,
                'instagram_media_id' => $mediaId,
                'fetched_at' => date('c'),
            ], $authHeader);
        }
        echo json_encode(['ok' => true, 'media_id' => $mediaId]);
        exit;
    }
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
        .badge-generating { background: rgba(59,130,246,0.2); color: #60a5fa; }
        .badge-failed { background: rgba(239,68,68,0.2); color: var(--danger); }
        .msg { padding: 0.75rem; border-radius: 8px; margin-bottom: 1rem; font-size: 0.9rem; }
        .msg.success { background: rgba(34,197,94,0.15); color: #86efac; }
        .msg.error { background: rgba(239,68,68,0.15); color: #fca5a5; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; font-size: 0.875rem; margin-bottom: 0.25rem; color: var(--muted); }
        .form-group input { width: 100%; padding: 0.6rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text); }
        .tabs { display: flex; gap: 0; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border); }
        .tabs a { padding: 0.75rem 1rem; color: var(--muted); text-decoration: none; border-bottom: 2px solid transparent; margin-bottom: -1px; }
        .tabs a.active { color: var(--accent); border-bottom-color: var(--accent); }
        .tabs a:hover { color: var(--text); }
        @media (max-width: 768px) { .mobile-only { display: inline !important; } }
    </style>
</head>
<body x-data="pipelineApp()" x-init="init()">
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
                Create an account via PocketBase Admin: <?= htmlspecialchars($CONFIG['pocketbase_url']) ?>
            </p>
            <?php endif; ?>
        </div>
        <p style="margin-top: 2rem; font-size: 0.8rem;"><a href="<?= htmlspecialchars($CONFIG['site_url'] . $_SERVER['SCRIPT_NAME']) ?>?privacy=1" style="color: var(--muted);">Privacy Policy</a></p>
    </div>
<?php else: ?>
    <div class="app">
        <div class="header">
            <div>
                <h1><?= htmlspecialchars($CONFIG['site_name']) ?> <span style="font-size: 0.75rem; color: var(--muted); font-weight: 500;"><?= htmlspecialchars($CONFIG['app_version']) ?></span></h1>
                <span class="user-info" x-text="'Logged in as ' + (userEmail || '')"></span>
                <span class="user-info" x-show="connectedAccounts().length" style="margin-left: 0.75rem; color: var(--accent);" x-text="'Instagram: ' + connectedAccounts().map(a => accountHandle(a)).join(', ')"></span>
            </div>
            <div style="display: flex; align-items: center; gap: 1rem;">
                <a href="<?= htmlspecialchars($CONFIG['site_url'] . $_SERVER['SCRIPT_NAME']) ?>?privacy=1" style="font-size: 0.875rem; color: var(--muted);">Privacy</a>
                <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="logout">
                    <button type="submit" class="btn btn-secondary">Log out</button>
                </form>
            </div>
        </div>

        <div class="tabs">
            <a href="#" :class="{ active: tab === 'curate' }" @click.prevent="tab = 'curate'">Curate</a>
            <a href="#" :class="{ active: tab === 'accounts' }" @click.prevent="tab = 'accounts'; loadAccounts()">Accounts</a>
            <a href="#" :class="{ active: tab === 'activity' }" @click.prevent="tab = 'activity'; loadContent()">Activity</a>
        </div>

        <div x-show="msg" x-transition class="msg" :class="msgError ? 'error' : 'success'" x-text="msg"></div>

        <!-- Curate: Link input + Curate feed -->
        <div x-show="tab === 'curate'" x-transition>
            <div class="card">
                <h3 style="margin-bottom: 0.75rem;">Send links</h3>
                <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">Paste URLs to inspire content generation. Each link is queued for processing.</p>
                <div class="link-input">
                    <input type="url" x-model="linkUrl" placeholder="https://example.com/article-or-video..." @keydown.enter.prevent="addLink()">
                    <select x-model="linkAccountId" style="padding: 0.5rem 1rem; font-size: 0.9rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 8px;">
                        <option value="">For account (optional)</option>
                        <template x-for="a in accounts.filter(a => a.is_active && hasInstagramIdentity(a))" :key="a.id">
                            <option :value="a.id" x-text="accountHandle(a)"></option>
                        </template>
                    </select>
                    <button class="btn btn-primary" @click="addLink()" :disabled="!linkUrl.trim() || addingLink">Add link</button>
                </div>
                <div x-show="links.length" style="margin-top: 1rem;">
                    <p style="font-size: 0.875rem; color: var(--muted);">Queued links — fetch with gallery-dl (images) or yt-dlp (video/audio):</p>
                    <ul style="list-style: none; margin-top: 0.5rem;">
                        <template x-for="l in links" :key="l.id">
                            <li style="padding: 0.5rem 0; font-size: 0.9rem; word-break: break-all; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                                <span x-text="l.url" style="flex: 1; min-width: 0;"></span>
                                <span x-show="l.metadata?.instagram_account_id && accounts.length" style="font-size: 0.8rem; color: var(--accent);" x-text="(function(){ const acc = accounts.find(x => x.id === l.metadata?.instagram_account_id); return acc ? 'for ' + accountHandle(acc) : ''; })()"></span>
                                <span class="badge" :class="l.status === 'fetched' ? 'badge-approved' : 'badge-pending'" x-text="l.status || 'pending'" style="flex-shrink: 0;"></span>
                                <span x-show="l.fetching" style="font-size: 0.8rem; color: var(--muted);">Fetching...</span>
                                <template x-if="l.status !== 'fetched' && !l.fetching">
                                    <span style="display: flex; gap: 0.25rem; flex-wrap: wrap; align-items: center;">
                                        <select x-model="l.selectedDownloader" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 6px;">
                                            <option value="gallery-dl">gallery-dl (images)</option>
                                            <option value="yt-dlp">yt-dlp (video/audio)</option>
                                        </select>
                                        <select x-model="l.selectedContentType" style="padding: 0.25rem 0.5rem; font-size: 0.8rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 6px;" title="What type of content is this?">
                                            <option value="auto">Type: auto</option>
                                            <option value="carousel">Carousel (multiple images)</option>
                                            <option value="video">Video</option>
                                            <option value="image">Image (single)</option>
                                        </select>
                                        <button class="btn btn-secondary" style="padding: 0.25rem 0.5rem; font-size: 0.8rem;" @click="fetchLink(l)">Fetch</button>
                                    </span>
                                </template>
                            </li>
                        </template>
                    </ul>
                </div>
            </div>

            <div style="margin-top: 1.5rem;">
                <h3 style="margin-bottom: 0.75rem;">Generated content</h3>
                <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">Review and approve or reject content before publishing.</p>
                <div style="margin-bottom: 1rem;">
                    <input type="text" x-model="generatePrompt" placeholder="Enter prompt for new video..." style="padding: 0.5rem 1rem; width: 280px; margin-right: 0.5rem; border-radius: 8px; background: var(--surface2); border: 1px solid var(--border); color: var(--text);">
                    <button class="btn btn-primary" @click="generateContent()" :disabled="generating">Generate</button>
                </div>
                <template x-if="contentLoading">
                    <p class="msg">Loading...</p>
                </template>
                <div class="content-grid" x-show="!contentLoading && content.length">
                    <template x-for="c in content" :key="c.id">
                        <div class="content-card">
                            <template x-if="c.type === 'reel' || c.type === 'video'">
                                <video :src="c.garage_url || ''" controls muted loop></video>
                            </template>
                            <template x-if="c.type === 'carousel' || c.type === 'image'">
                                <img :src="c.thumbnail_url || c.garage_url || ''" alt="">
                            </template>
                            <div class="body">
                                <h4 x-text="c.title || c.prompt?.slice(0,50) || 'Untitled'"></h4>
                                <p x-text="c.prompt?.slice(0,80) || ''"></p>
                                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap; margin-bottom: 0.25rem;">
                                    <span class="badge" :class="'badge-' + (c.status || 'pending')" x-text="c.status"></span>
                                    <span style="font-size: 0.75rem; color: var(--muted);" x-text="(c.type === 'video' || c.type === 'reel') ? 'Video' : (c.type === 'carousel' ? 'Carousel' : (c.type === 'image' ? 'Image' : (c.type || '')))"></span>
                                </div>
                                <div class="actions" x-show="c.status === 'pending' || c.status === 'approved'">
                                    <select x-model="c.selectedAccount" x-show="accounts.filter(a => a.is_active).length" style="padding: 0.35rem; font-size: 0.8rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 6px;">
                                        <option value="">Select account</option>
                                        <template x-for="a in accounts.filter(a => a.is_active && hasInstagramIdentity(a))" :key="a.id">
                                            <option :value="a.id" x-text="accountHandle(a)"></option>
                                        </template>
                                    </select>
                                    <button class="btn btn-success" x-show="c.status === 'pending'" @click="approveContent(c)" :disabled="!c.selectedAccount">Approve</button>
                                    <button class="btn btn-primary" x-show="c.status === 'approved'" @click="publishContent(c)" :disabled="!c.selectedAccount || publishing">Publish</button>
                                    <button class="btn btn-danger" @click="rejectContent(c)">Reject</button>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
                <p x-show="!contentLoading && !content.length" class="msg">No content yet. Add a link or generate a video to get started.</p>
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
        </div>

        <!-- Activity: generated files log -->
        <div x-show="tab === 'activity'" x-transition>
            <h2>Activity</h2>
            <p style="margin-bottom: 1rem; color: var(--muted); font-size: 0.9rem;">Recent generated files — sanity check that Garage, Replicate/fal.ai, and PocketBase are connected.</p>
            <button class="btn btn-secondary" @click="loadContent()" style="margin-bottom: 1rem;">Refresh</button>
            <template x-if="contentLoading">
                <p class="msg">Loading...</p>
            </template>
            <div x-show="!contentLoading" class="card" style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem;">
                    <thead>
                        <tr style="border-bottom: 1px solid var(--border); text-align: left;">
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Created</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Status</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Type</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">Storage key</th>
                            <th style="padding: 0.5rem 0.75rem; color: var(--muted);">URL</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="c in content" :key="c.id">
                            <tr style="border-bottom: 1px solid var(--border);">
                                <td style="padding: 0.5rem 0.75rem; color: var(--muted);" x-text="c.created ? new Date(c.created).toLocaleString() : '-'"></td>
                                <td style="padding: 0.5rem 0.75rem;"><span class="badge" :class="'badge-' + (c.status || 'pending')" x-text="c.status || '-'"></span></td>
                                <td style="padding: 0.5rem 0.75rem;" x-text="c.type || '-'"></td>
                                <td style="padding: 0.5rem 0.75rem; font-family: monospace; font-size: 0.8rem; word-break: break-all; color: var(--muted);" x-text="c.garage_key || '(none)'"></td>
                                <td style="padding: 0.5rem 0.75rem;">
                                    <a x-show="c.garage_url" :href="c.garage_url" target="_blank" rel="noopener" style="color: var(--accent); font-size: 0.8rem;">Open</a>
                                    <span x-show="!c.garage_url" style="color: var(--muted);">—</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p x-show="!contentLoading && !content.length" class="msg" style="margin-top: 1rem;">No generated content yet. Generate a video from the Curate tab.</p>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pipelineApp', () => ({
                PB_URL: '<?= addslashes($CONFIG['pocketbase_public_url']) ?>',
                token: '<?= addslashes($token ?? '') ?>',
                userEmail: '<?= addslashes($user['email'] ?? '') ?>',
                tab: '<?= htmlspecialchars($_GET['tab'] ?? 'curate') ?>',
                msg: '<?= htmlspecialchars($_GET['msg'] ?? '') ?>',
                msgError: <?= !empty($_GET['msgError']) ? 'true' : 'false' ?>,
                linkUrl: '',
                linkAccountId: '',
                links: [],
                addingLink: false,
                content: [],
                contentLoading: false,
                accounts: [],
                generatePrompt: '',
                generating: false,
                publishing: false,
                disconnectingId: '',
                refreshingId: '',
                activatingId: '',

                init() {
                    if (this.tab === 'curate') { this.loadLinks(); this.loadContent(); this.loadAccounts(); }
                    if (this.tab === 'accounts') this.loadAccounts();
                    if (this.tab === 'activity') this.loadContent();
                    if (this.msg === 'connected') this.msg = 'Instagram account connected.';
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

                async loadLinks() {
                    try {
                        const r = await fetch(this.PB_URL + '/api/collections/source_links/records?sort=-created', {
                            headers: this.pbHeaders()
                        });
                        const d = await r.json();
                        this.links = (d.items || []).map(l => ({ ...l, selectedDownloader: l.selectedDownloader || 'gallery-dl', selectedContentType: l.selectedContentType || 'auto', fetching: false }));
                    } catch (e) { this.links = []; }
                },

                async fetchLink(l) {
                    if (l.fetching) return;
                    l.fetching = true;
                    this.msg = '';
                    const fd = new FormData();
                    fd.append('action', 'fetch_link');
                    fd.append('link_id', l.id);
                    fd.append('downloader', l.selectedDownloader || 'gallery-dl');
                    fd.append('content_type', l.selectedContentType || 'auto');
                    try {
                        const r = await fetch(location.href, { method: 'POST', body: fd });
                        const d = await r.json();
                        if (d.ok) {
                            this.msg = `Fetched ${d.created} file(s) with ${d.downloader}.`;
                            this.loadLinks();
                            this.loadContent();
                        } else {
                            this.msg = d.error || 'Fetch failed';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        l.fetching = false;
                    }
                },

                async addLink() {
                    if (!this.linkUrl.trim()) return;
                    this.addingLink = true;
                    this.msg = '';
                    const fd = new FormData();
                    fd.append('action', 'add_link');
                    fd.append('url', this.linkUrl.trim());
                    if (this.linkAccountId) fd.append('account_id', this.linkAccountId);
                    try {
                        const r = await fetch(location.href, { method: 'POST', body: fd });
                        const d = await r.json();
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
                    try {
                        const r = await fetch(this.PB_URL + '/api/collections/content_items/records?sort=-created', {
                            headers: this.pbHeaders()
                        });
                        const d = await r.json();
                        this.content = (d.items || []).map(c => ({ ...c, selectedAccount: c.instagram_account_id || '' }));
                    } catch (e) { this.msg = 'Failed to load content'; this.msgError = true; }
                    finally { this.contentLoading = false; }
                },

                async loadAccounts() {
                    try {
                        const r = await fetch(this.PB_URL + '/api/collections/instagram_accounts/records?sort=-created', {
                            headers: this.pbHeaders()
                        });
                        const d = await r.json();
                        this.accounts = (d.items || []).map(a => this.normalizeAccount(a));
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
                        const r = await fetch(this.PB_URL + '/api/collections/instagram_accounts/records/' + a.id, {
                            method: 'PATCH',
                            headers: this.pbHeaders({ 'Content-Type': 'application/json' }),
                            body: JSON.stringify({ is_active: true })
                        });
                        if (r.status >= 200 && r.status < 300) {
                            a.is_active = true;
                            this.msg = 'Account activated.';
                            this.msgError = false;
                        } else {
                            const d = await r.json().catch(() => ({}));
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
                        const r = await fetch(location.href, { method: 'POST', body: fd, credentials: 'same-origin' });
                        const d = await r.json();
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
                        const r = await fetch(this.PB_URL + '/api/collections/instagram_accounts/records/' + a.id, {
                            method: 'DELETE',
                            headers: this.pbHeaders()
                        });
                        if (r.status >= 200 && r.status < 300) {
                            this.accounts = this.accounts.filter(x => x.id !== a.id);
                            if (this.linkAccountId === a.id) this.linkAccountId = '';
                            this.content = this.content.map(c => c.selectedAccount === a.id ? { ...c, selectedAccount: '' } : c);
                            this.msg = 'Account disconnected.';
                            this.msgError = false;
                        } else {
                            const d = await r.json().catch(() => ({}));
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
                        const r = await fetch(this.PB_URL + '/api/collections/instagram_accounts/records/' + a.id, {
                            method: 'DELETE',
                            headers: this.pbHeaders()
                        });
                        if (r.status >= 200 && r.status < 300) {
                            this.accounts = this.accounts.filter(x => x.id !== a.id);
                            if (this.linkAccountId === a.id) this.linkAccountId = '';
                            this.content = this.content.map(c => c.selectedAccount === a.id ? { ...c, selectedAccount: '' } : c);
                            window.location.href = '?instagram_oauth=1';
                            return;
                        }
                        const d = await r.json().catch(() => ({}));
                        this.msg = d.message || d.error || 'Failed to reconnect';
                        this.msgError = true;
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.disconnectingId = '';
                    }
                },

                async approveContent(c) {
                    if (!c.selectedAccount) return;
                    const fd = new FormData();
                    fd.append('action', 'approve_content');
                    fd.append('id', c.id);
                    fd.append('account_id', c.selectedAccount);
                    const r = await fetch(location.href, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.ok) { c.status = 'approved'; this.msg = 'Approved.'; }
                    else { this.msg = d.error || 'Failed'; this.msgError = true; }
                },

                async rejectContent(c) {
                    const reason = prompt('Rejection reason (optional):') || '';
                    const fd = new FormData();
                    fd.append('action', 'reject_content');
                    fd.append('id', c.id);
                    fd.append('reason', reason);
                    const r = await fetch(location.href, { method: 'POST', body: fd });
                    const d = await r.json();
                    if (d.ok) { c.status = 'rejected'; this.msg = 'Rejected.'; this.loadContent(); }
                    else { this.msg = d.error || 'Failed'; this.msgError = true; }
                },

                async publishContent(c) {
                    if (!c.selectedAccount) return;
                    this.publishing = true;
                    const fd = new FormData();
                    fd.append('action', 'publish_content');
                    fd.append('id', c.id);
                    fd.append('account_id', c.selectedAccount);
                    try {
                        const r = await fetch(location.href, { method: 'POST', body: fd });
                        const d = await r.json();
                        if (d.ok) { c.status = 'published'; this.msg = 'Published to Instagram!'; this.loadContent(); }
                        else { this.msg = d.error || 'Publish failed'; this.msgError = true; }
                    } finally { this.publishing = false; }
                },

                async generateContent() {
                    if (!this.generatePrompt.trim()) return;
                    this.generating = true;
                    this.msg = '';
                    const fd = new FormData();
                    fd.append('action', 'generate_content');
                    fd.append('prompt', this.generatePrompt.trim());
                    fd.append('type', 'reel');
                    try {
                        const r = await fetch(location.href, { method: 'POST', body: fd });
                        const d = await r.json();
                        if (d.ok) {
                            this.msg = 'Generation started. Refresh to see the new content.';
                            this.generatePrompt = '';
                            setTimeout(() => this.loadContent(), 3000);
                        } else {
                            this.msg = d.error || 'Generation failed';
                            this.msgError = true;
                        }
                    } catch (e) {
                        this.msg = 'Request failed';
                        this.msgError = true;
                    } finally {
                        this.generating = false;
                    }
                },
            }));
        });
    </script>
<?php endif; ?>
</body>
</html>
