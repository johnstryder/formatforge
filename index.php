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
$CONFIG = [
    'pocketbase_url'   => $pbUrl,
    'site_url'         => getenv('APP_URL') ?: ('http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')),
    'site_name'        => getenv('SITE_NAME') ?: 'FormatForge',
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
    'embed_url'        => getenv('EMBED_URL') ?: '',  // Ollama-compatible: /api/embed or OpenAI
    'openai_key'       => getenv('OPENAI_API_KEY') ?: '',
    'pi_trigger_dir'  => getenv('PI_TRIGGER_DIR') ?: (__DIR__ . '/.pi/triggers'),
    'novel_threshold'  => (float)(getenv('NOVEL_DISTANCE_THRESHOLD') ?: '0.35'),  // cosine distance above = novel
];
if (file_exists(__DIR__ . '/config.php')) {
    $CONFIG = array_merge($CONFIG, require __DIR__ . '/config.php');
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
    if ($cfg['openai_key']) {
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
    if (empty($cfg['embed_url']) && empty($cfg['openai_key'])) return false;
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
$authHeader = $token ? 'Bearer ' . $token : null;

// Instagram OAuth
if (isset($_GET['instagram_oauth']) && $user) {
    $cfg = $GLOBALS['CONFIG'];
    $redirect = $cfg['instagram_redirect'] ?: ($cfg['site_url'] . $_SERVER['SCRIPT_NAME'] . '?instagram_callback=1');
    $url = 'https://www.facebook.com/v18.0/dialog/oauth?' . http_build_query([
        'client_id' => $cfg['fb_app_id'],
        'redirect_uri' => $redirect,
        'scope' => 'instagram_basic,instagram_content_publish,pages_show_list,pages_read_engagement',
        'response_type' => 'code',
        'state' => base64_encode(json_encode(['user_id' => $user['id'] ?? ''])),
    ]);
    header('Location: ' . $url);
    exit;
}

if (isset($_GET['instagram_callback']) && isset($_GET['code']) && $user) {
    $cfg = $GLOBALS['CONFIG'];
    $redirect = $cfg['instagram_redirect'] ?: ($cfg['site_url'] . $_SERVER['SCRIPT_NAME'] . '?instagram_callback=1');
    $ch = curl_init('https://api.instagram.com/oauth/access_token');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'client_id' => $cfg['fb_app_id'],
            'client_secret' => $cfg['fb_app_secret'],
            'grant_type' => 'authorization_code',
            'redirect_uri' => $redirect,
            'code' => $_GET['code'],
        ]),
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($res ?: '{}', true) ?? [];
    $igToken = $data['access_token'] ?? null;
    $igUserId = $data['user_id'] ?? null;
    if ($igToken && $igUserId) {
        $ch2 = curl_init("https://graph.instagram.com/v18.0/{$igUserId}?fields=username&access_token={$igToken}");
        curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
        $r2 = curl_exec($ch2);
        curl_close($ch2);
        $u = json_decode($r2 ?: '{}', true) ?? [];
        $username = $u['username'] ?? 'unknown';
        pb_request('POST', '/api/collections/instagram_accounts/records', [
            'instagram_user_id' => $igUserId,
            'username' => $username,
            'access_token' => $igToken,
            'is_active' => true,
        ], $authHeader);
    }
    header('Location: ' . $cfg['site_url'] . $_SERVER['SCRIPT_NAME'] . '?tab=accounts&msg=connected');
    exit;
}

// API: add link, generate, approve, reject, publish
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $user && $authHeader) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'add_link') {
        $url = trim($_POST['url'] ?? '');
        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            echo json_encode(['ok' => false, 'error' => 'Invalid URL']);
            exit;
        }
        $rec = pb_request('POST', '/api/collections/source_links/records', [
            'url' => $url,
            'status' => 'pending',
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
    </div>
<?php else: ?>
    <div class="app">
        <div class="header">
            <div>
                <h1><?= htmlspecialchars($CONFIG['site_name']) ?></h1>
                <span class="user-info" x-text="'Logged in as ' + (userEmail || '')"></span>
            </div>
            <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-secondary">Log out</button>
            </form>
        </div>

        <div class="tabs">
            <a href="#" :class="{ active: tab === 'curate' }" @click.prevent="tab = 'curate'">Curate</a>
            <a href="#" :class="{ active: tab === 'accounts' }" @click.prevent="tab = 'accounts'; loadAccounts()">Accounts</a>
        </div>

        <div x-show="msg" x-transition class="msg" :class="msgError ? 'error' : 'success'" x-text="msg"></div>

        <!-- Curate: Link input + Curate feed -->
        <div x-show="tab === 'curate'" x-transition>
            <div class="card">
                <h3 style="margin-bottom: 0.75rem;">Send links</h3>
                <p style="font-size: 0.875rem; color: var(--muted); margin-bottom: 1rem;">Paste URLs to inspire content generation. Each link is queued for processing.</p>
                <div class="link-input">
                    <input type="url" x-model="linkUrl" placeholder="https://example.com/article-or-video..." @keydown.enter.prevent="addLink()">
                    <button class="btn btn-primary" @click="addLink()" :disabled="!linkUrl.trim() || addingLink">Add link</button>
                </div>
                <div x-show="links.length" style="margin-top: 1rem;">
                    <p style="font-size: 0.875rem; color: var(--muted);">Queued links:</p>
                    <ul style="list-style: none; margin-top: 0.5rem;">
                        <template x-for="l in links" :key="l.id">
                            <li style="padding: 0.5rem 0; font-size: 0.9rem; word-break: break-all;" x-text="l.url"></li>
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
                            <template x-if="c.type === 'carousel'">
                                <img :src="c.thumbnail_url || c.garage_url || ''" alt="">
                            </template>
                            <div class="body">
                                <h4 x-text="c.title || c.prompt?.slice(0,50) || 'Untitled'"></h4>
                                <p x-text="c.prompt?.slice(0,80) || ''"></p>
                                <span class="badge" :class="'badge-' + (c.status || 'pending')" x-text="c.status"></span>
                                <div class="actions" x-show="c.status === 'pending' || c.status === 'approved'">
                                    <select x-model="c.selectedAccount" x-show="accounts.length" style="padding: 0.35rem; font-size: 0.8rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 6px;">
                                        <option value="">Select account</option>
                                        <template x-for="a in accounts" :key="a.id">
                                            <option :value="a.id" x-text="a.username"></option>
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
            <p style="margin-bottom: 1rem; color: var(--muted);">Connect Instagram Business/Creator accounts to publish content.</p>
            <a :href="'?instagram_oauth=1'" class="btn btn-primary" style="margin-bottom: 1rem;">Connect Instagram Account</a>
            <div class="content-grid" x-show="accounts.length">
                <template x-for="a in accounts" :key="a.id">
                    <div class="card">
                        <h3 x-text="'@' + a.username"></h3>
                        <p x-text="a.is_active ? 'Active' : 'Inactive'"></p>
                    </div>
                </template>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('alpine:init', () => {
            Alpine.data('pipelineApp', () => ({
                PB_URL: '<?= addslashes($CONFIG['pocketbase_url']) ?>',
                token: '<?= addslashes($token ?? '') ?>',
                userEmail: '<?= addslashes($user['email'] ?? '') ?>',
                tab: '<?= htmlspecialchars($_GET['tab'] ?? 'curate') ?>',
                msg: '<?= htmlspecialchars($_GET['msg'] ?? '') ?>',
                msgError: false,
                linkUrl: '',
                links: [],
                addingLink: false,
                content: [],
                contentLoading: false,
                accounts: [],
                generatePrompt: '',
                generating: false,
                publishing: false,

                init() {
                    if (this.tab === 'curate') { this.loadLinks(); this.loadContent(); this.loadAccounts(); }
                    if (this.tab === 'accounts') this.loadAccounts();
                    if (this.msg === 'connected') this.msg = 'Instagram account connected.';
                },

                async loadLinks() {
                    try {
                        const r = await fetch(this.PB_URL + '/api/collections/source_links/records?sort=-created', {
                            headers: { 'Authorization': this.token }
                        });
                        const d = await r.json();
                        this.links = d.items || [];
                    } catch (e) { this.links = []; }
                },

                async addLink() {
                    if (!this.linkUrl.trim()) return;
                    this.addingLink = true;
                    this.msg = '';
                    const fd = new FormData();
                    fd.append('action', 'add_link');
                    fd.append('url', this.linkUrl.trim());
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
                            headers: { 'Authorization': this.token }
                        });
                        const d = await r.json();
                        this.content = (d.items || []).map(c => ({ ...c, selectedAccount: '' }));
                    } catch (e) { this.msg = 'Failed to load content'; this.msgError = true; }
                    finally { this.contentLoading = false; }
                },

                async loadAccounts() {
                    try {
                        const r = await fetch(this.PB_URL + '/api/collections/instagram_accounts/records?filter=is_active=true', {
                            headers: { 'Authorization': this.token }
                        });
                        const d = await r.json();
                        this.accounts = d.items || [];
                    } catch (e) { this.accounts = []; }
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
