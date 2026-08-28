<?php


function app_config($section = null, ?string $key = null)
{
    global $config;

    if ($section === null) {
        return $config;
    }

    if (!isset($config[$section])) {
        return null;
    }

    if ($key === null) {
        return $config[$section];
    }

    return $config[$section][$key] ?? null;
}

function json_response($data, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function request_method() {
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
}

function post($key, $default = null)
{
    return $_POST[$key] ?? $default;
}

function get_param($key, $default = null)
{
    return $_GET[$key] ?? $default;
}

function h($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_log($message, array $context = []) {
    $file = app_config('paths', 'log_file');
    if (!$file) {
        return;
    }

    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }

    $line = sprintf(
        "[%s] %s %s\n",
        date('Y-m-d H:i:s'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : ''
    );

    @file_put_contents($file, $line, FILE_APPEND);
}

function require_api_key_if_present() {
    $configured = (string) app_config('security', 'admin_api_key');
    if ($configured === '' || $configured === 'troque-esta-chave-forte-antes-de-produzir') {
        return;
    }

    $provided = $_SERVER['HTTP_X_API_KEY'] ?? post('api_key') ?? get_param('api_key');
    if (!hash_equals($configured, (string) $provided)) {
        json_response([
            'ok' => false,
            'message' => 'API key inválida.',
        ], 401);
    }
}


function starts_with_value($haystack, string $needle) {
    if ($needle === '') {
        return true;
    }

    if (function_exists('str_starts_with')) {
        return str_starts_with($haystack, $needle);
    }

    return substr($haystack, 0, strlen($needle)) === $needle;
}

function normalize_account_id($adAccountId) {
    $clean = trim($adAccountId);
    if ($clean === '') {
        return $clean;
    }

    return starts_with_value($clean, 'act_') ? $clean : 'act_' . preg_replace('/\D+/', '', $clean);
}

function extract_action_value($actions, array $types) {
    foreach ($actions as $item) {
        $actionType = $item['action_type'] ?? '';
        if (in_array($actionType, $types, true)) {
            return (int) round((float) ($item['value'] ?? 0));
        }
    }

    return 0;
}

function extract_action_decimal($actions, array $types) {
    foreach ($actions as $item) {
        $actionType = $item['action_type'] ?? '';
        if (in_array($actionType, $types, true)) {
            return (float) ($item['value'] ?? 0);
        }
    }

    return 0.0;
}

function table_exists($pdo, $table) {
    $table = (string) $table;
    $quoted = $pdo->quote($table);
    $sql = 'SHOW TABLES LIKE ' . $quoted;
    $stmt = $pdo->query($sql);
    return $stmt ? (bool) $stmt->fetchColumn() : false;
}

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $table = str_replace('`', '', $table);
    $quotedColumn = $pdo->quote($column);
    $sql = 'SHOW COLUMNS FROM `' . $table . '` LIKE ' . $quotedColumn;
    $stmt = $pdo->query($sql);
    return $stmt ? (bool) $stmt->fetchColumn() : false;
}

function ensure_meta_integration_schema(PDO $pdo): void
{
    if (!table_exists($pdo, 'meta_integrations')) {
        return;
    }

    $columns = [
        'status' => "ALTER TABLE meta_integrations ADD COLUMN status ENUM('active','inactive') NOT NULL DEFAULT 'active'",
        'currency_code' => "ALTER TABLE meta_integrations ADD COLUMN currency_code VARCHAR(3) NOT NULL DEFAULT 'BRL'",
        'currency_spread_percent' => "ALTER TABLE meta_integrations ADD COLUMN currency_spread_percent DECIMAL(8,4) NOT NULL DEFAULT 0.0000",
        'manual_exchange_rate' => "ALTER TABLE meta_integrations ADD COLUMN manual_exchange_rate DECIMAL(12,6) DEFAULT NULL",
    ];

    foreach ($columns as $column => $sql) {
        if (column_exists($pdo, 'meta_integrations', $column)) {
            continue;
        }
        try {
            $pdo->exec($sql);
        } catch (\PDOException $e) {
            error_log('ensure_meta_integration_schema: falha ao adicionar coluna ' . $column . ': ' . $e->getMessage());
        }
    }
}

function transliterator_instance() {
    static $trans = null;
    static $checked = false;

    if (!$checked) {
        $checked = true;
        if (class_exists('Transliterator')) {
            $trans = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC');
        }
    }

    return $trans;
}

function normalize_common_text($value) {
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Decodifica sequências urlencoded que costumam vir nas UTMs.
    if (strpos($value, '%') !== false) {
        $decoded = rawurldecode($value);
        if (is_string($decoded) && $decoded !== '') {
            $value = $decoded;
        }
    }

    $value = str_replace(
        array('+', '%20', '_', '-', '/', '\\', '|', ':', ';', ',', '.', '[', ']', '(', ')', '{', '}', '#', '&', '?', '=', '!', '@', '"', "'"),
        ' ',
        $value
    );

    $trans = transliterator_instance();
    if ($trans) {
        $value = $trans->transliterate($value);
    } elseif (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($converted !== false) {
            $value = $converted;
        }
    }

    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
    $value = preg_replace('/\s+/u', ' ', $value);

    return trim((string) $value);
}

function normalize_text_value($value) {
    $value = normalize_common_text($value);

    if ($value === '') {
        return '';
    }

    return str_replace(' ', '', $value);
}


function normalize_text_soft_value($value) {
    return normalize_common_text($value);
}

function normalized_similarity_score($a, $b) {
    $aHard = normalize_text_value($a);
    $bHard = normalize_text_value($b);
    if ($aHard === '' || $bHard === '') {
        return 0.0;
    }
    if ($aHard === $bHard) {
        return 100.0;
    }

    // Atalhos muito baratos antes de qualquer comparação mais custosa.
    if (strpos($aHard, $bHard) !== false || strpos($bHard, $aHard) !== false) {
        return 92.0;
    }

    $lenA = strlen($aHard);
    $lenB = strlen($bHard);
    $maxLen = max($lenA, $lenB);

    // Se a diferença de tamanho for absurda, não vale comparar.
    if ($maxLen <= 0 || abs($lenA - $lenB) > max(6, (int) floor($maxLen * 0.45))) {
        return 0.0;
    }

    $score = 0.0;
    $aSoft = normalize_text_soft_value($a);
    $bSoft = normalize_text_soft_value($b);

    if ($aSoft !== '' && $bSoft !== '') {
        $aTokens = array_values(array_unique(array_filter(explode(' ', $aSoft), 'strlen')));
        $bTokens = array_values(array_unique(array_filter(explode(' ', $bSoft), 'strlen')));

        if ($aTokens && $bTokens) {
            $setA = array_fill_keys($aTokens, true);
            $setB = array_fill_keys($bTokens, true);

            $intersection = 0;
            foreach ($setA as $token => $true) {
                if (isset($setB[$token])) {
                    $intersection++;
                }
            }

            $union = count(array_unique(array_merge($aTokens, $bTokens)));
            if ($union > 0) {
                $score = max($score, ($intersection / $union) * 100.0);
            }

            $maxTokenCount = max(count($aTokens), count($bTokens));
            if ($maxTokenCount > 0) {
                $score = max($score, ($intersection / $maxTokenCount) * 100.0);
            }
        }
    }

    // Só usa similar_text quando os textos já passaram nos filtros acima
    // e não são absurdamente longos.
    if ($maxLen <= 80 && function_exists('similar_text')) {
        similar_text($aHard, $bHard, $pctHard);
        $score = max($score, (float) $pctHard);
    }

    return $score;
}

function best_fuzzy_key_match($needle, array $candidates, $threshold = 82.0) {
    $needleNorm = normalize_text_value($needle);
    if ($needleNorm === '' || empty($candidates)) {
        return '';
    }
    if (isset($candidates[$needleNorm])) {
        return $needleNorm;
    }

    $bestKey = '';
    $bestScore = 0.0;

    foreach ($candidates as $candidateKey => $candidateValue) {
        $candidateNorm = is_string($candidateKey) ? (string) $candidateKey : normalize_text_value($candidateValue);
        if ($candidateNorm === '') {
            continue;
        }

        // Atalho extremamente barato.
        if ($needleNorm === $candidateNorm) {
            return $candidateNorm;
        }

        $score = normalized_similarity_score($needleNorm, $candidateNorm);
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestKey = $candidateNorm;
        }
    }

    return $bestScore >= $threshold ? $bestKey : '';
}

function normalize_email_value($value) {
    return mb_strtolower(trim((string) $value), 'UTF-8');
}

function normalize_phone_value($value) {
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === '') {
        return '';
    }

    if (starts_with_value($digits, '55') && strlen($digits) > 11) {
        $digits = substr($digits, 2);
    }

    if (strlen($digits) === 10) {
        $ddd = substr($digits, 0, 2);
        $rest = substr($digits, 2);
        if (isset($rest[0]) && (int) $rest[0] >= 6) {
            $digits = $ddd . '9' . $rest;
        }
    }

    if (strlen($digits) > 11) {
        $digits = substr($digits, -11);
    }

    return $digits;
}

function value_to_float($value) {
    return (float) ($value ?? 0);
}

function value_to_int($value) {
    return (int) round((float) ($value ?? 0));
}

function pct_change($current, ?float $baseline) {
    if ($baseline === null || abs($baseline) < 0.000001) {
        return null;
    }

    return (($current ?? 0.0) - $baseline) / $baseline * 100;
}

function trend_direction($metricKey, ?float $change) {
    if ($change === null) {
        return 'neutral';
    }

    $lowerIsBetter = in_array($metricKey, ['cac', 'cpa', 'cpl', 'cpm', 'cpc', 'frequency'], true);
    if (abs($change) < 0.0001) {
        return 'neutral';
    }

    if ($lowerIsBetter) {
        return $change < 0 ? 'good' : 'bad';
    }

    return $change > 0 ? 'good' : 'bad';
}
