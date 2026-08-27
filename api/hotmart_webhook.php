<?php
/**
 * Hotmart webhook receiver for meta ads manager
 * Suggested path:
 *   /public_html/meta_ads_manager_project/api/hotmart_webhook.php
 *
 * Stores raw events and consolidated live sales in prof2543_meta_ads_manager
 * Mirrors/update legacy hotmart_sales in prof2543_area_membros
 * Triggers attribution refresh after each successful webhook.
 */

declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

const DB_HOST = 'localhost';
const DB_MAIN_NAME = 'prof2543_meta_ads_manager';
const DB_MAIN_USER = 'prof2543_meta_ads_manager';
const DB_MAIN_PASS = 'Emerson00*';

const DB_SOURCE_NAME = 'prof2543_area_membros';
const DB_SOURCE_USER = 'prof2543_area_membros';
const DB_SOURCE_PASS = 'Emerson00*';

const HOTMART_HOTTOK = '';

function log_dir_path(): string
{
    $dir = dirname(__DIR__) . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function write_log(string $message, array $context = []): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    if ($context) {
        $line .= ' | ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $line .= PHP_EOL;
    @file_put_contents(log_dir_path() . '/hotmart_webhook.log', $line, FILE_APPEND);
}

function respond(int $status, array $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function get_main_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_MAIN_NAME . ';charset=utf8mb4',
        DB_MAIN_USER,
        DB_MAIN_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    return $pdo;
}

function get_source_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_SOURCE_NAME . ';charset=utf8mb4',
        DB_SOURCE_USER,
        DB_SOURCE_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    return $pdo;
}

function get_request_headers_normalized(): array
{
    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (strpos($key, 'HTTP_') === 0) {
            $name = strtolower(str_replace('_', '-', substr($key, 5)));
            $headers[$name] = (string) $value;
        }
    }
    return $headers;
}

function normalize_email(?string $value): string
{
    return strtolower(trim((string) $value));
}

function normalize_phone(?string $value): string
{
    $digits = preg_replace('/\D+/', '', (string) $value);
    if ($digits === null) {
        return '';
    }
    if (strpos($digits, '55') === 0 && strlen($digits) >= 12) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) > 11) {
        $digits = substr($digits, -11);
    }
    return $digits;
}

function milliseconds_to_datetime($value): ?string
{
    if ($value === null || $value === '' || !is_numeric($value)) {
        return null;
    }
    $seconds = (int) floor(((int) $value) / 1000);
    return date('Y-m-d H:i:s', $seconds);
}

function map_status(string $event, ?string $purchaseStatus): string
{
    $event = strtoupper(trim($event));
    $purchaseStatus = strtoupper(trim((string) $purchaseStatus));

    if ($event === 'PURCHASE_APPROVED' || $purchaseStatus === 'APPROVED') {
        return 'APPROVED';
    }
    if ($event === 'PURCHASE_REFUNDED' || $purchaseStatus === 'REFUNDED') {
        return 'REFUNDED';
    }
    if ($event === 'PURCHASE_CHARGEBACK' || $purchaseStatus === 'CHARGEBACK') {
        return 'CHARGEBACK';
    }
    if ($event === 'PURCHASE_CANCELED' || $purchaseStatus === 'CANCELED' || $purchaseStatus === 'CANCELLED') {
        return 'CANCELED';
    }
    return $purchaseStatus !== '' ? $purchaseStatus : $event;
}

function legacy_status_from_status(string $status): string
{
    switch (strtoupper(trim($status))) {
        case 'APPROVED':
            return 'Aprovado';
        case 'REFUNDED':
            return 'Reembolsado';
        case 'CHARGEBACK':
            return 'Chargeback';
        case 'CANCELED':
            return 'Cancelado';
        default:
            return 'Pendente';
    }
}

function producer_net_from_commissions(array $commissions): float
{
    $producer = 0.0;
    foreach ($commissions as $item) {
        $source = strtoupper((string) ($item['source'] ?? ''));
        if ($source === 'PRODUCER') {
            $producer += (float) ($item['value'] ?? 0);
        }
    }
    return round($producer, 2);
}

function event_table_exists(PDO $pdo): bool
{
    static $exists = null;
    if ($exists !== null) {
        return $exists;
    }
    $check = $pdo->query("SHOW TABLES LIKE 'hotmart_webhook_events'");
    $exists = (bool) ($check && $check->fetch());
    return $exists;
}

function save_event_log(PDO $pdo, string $eventId, string $eventName, ?string $transactionCode, string $rawPayload, string $status, ?string $message = null): void
{
    if (!event_table_exists($pdo)) {
        return;
    }

    $stmt = $pdo->prepare(
        "INSERT INTO hotmart_webhook_events
            (event_id, event_name, transaction_code, process_status, process_message, payload_json, received_at, processed_at)
         VALUES
            (:event_id, :event_name, :transaction_code, :process_status, :process_message, :payload_json, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            event_name = VALUES(event_name),
            transaction_code = VALUES(transaction_code),
            process_status = VALUES(process_status),
            process_message = VALUES(process_message),
            payload_json = VALUES(payload_json),
            processed_at = NOW()"
    );

    $stmt->execute([
        ':event_id' => $eventId,
        ':event_name' => $eventName,
        ':transaction_code' => $transactionCode,
        ':process_status' => $status,
        ':process_message' => $message,
        ':payload_json' => $rawPayload,
    ]);
}

function find_matching_user(PDO $pdo, string $emailNorm, string $phoneNorm): array
{
    if ($phoneNorm !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content
             FROM users
             WHERE REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '') = :phone
                OR RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(telefone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), 11) = :phone
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([':phone' => $phoneNorm]);
        $row = $stmt->fetch();
        if ($row) {
            return ['user' => $row, 'method' => 'phone'];
        }
    }

    if ($emailNorm !== '') {
        $stmt = $pdo->prepare(
            "SELECT id, nome, email, telefone, utm_source, utm_medium, utm_campaign, utm_term, utm_content
             FROM users
             WHERE LOWER(TRIM(email)) = :email
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([':email' => $emailNorm]);
        $row = $stmt->fetch();
        if ($row) {
            return ['user' => $row, 'method' => 'email'];
        }
    }

    return ['user' => null, 'method' => 'none'];
}

function get_existing_legacy_sale(PDO $pdo, string $transactionCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM hotmart_sales WHERE transaction_code = :transaction_code LIMIT 1');
    $stmt->execute([':transaction_code' => $transactionCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_legacy_sale(PDO $pdo, array $saleData): void
{
    $exists = get_existing_legacy_sale($pdo, $saleData['transaction_code']) !== null;

    if ($exists) {
        $sql = "UPDATE hotmart_sales SET
                    status = :status,
                    transaction_date = :transaction_date,
                    payment_confirmed_at = :payment_confirmed_at,
                    product_code = :product_code,
                    product_name = :product_name,
                    price_code = :price_code,
                    price_name = :price_name,
                    currency = :currency,
                    gross_revenue = :gross_revenue,
                    net_revenue = :net_revenue,
                    producer_net = :producer_net,
                    buyer_name = :buyer_name,
                    buyer_email = :buyer_email,
                    buyer_phone_raw = :buyer_phone_raw,
                    buyer_phone_norm = :buyer_phone_norm,
                    matched_user_id = :matched_user_id,
                    match_method = :match_method,
                    utm_source = :utm_source,
                    utm_medium = :utm_medium,
                    utm_campaign = :utm_campaign,
                    utm_term = :utm_term,
                    utm_content = :utm_content,
                    updated_at = NOW()
                WHERE transaction_code = :transaction_code";
    } else {
        $sql = "INSERT INTO hotmart_sales (
                    transaction_code, status, transaction_date, payment_confirmed_at,
                    product_code, product_name, price_code, price_name, currency,
                    gross_revenue, net_revenue, producer_net,
                    buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm,
                    matched_user_id, match_method,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    imported_at, updated_at
                ) VALUES (
                    :transaction_code, :status, :transaction_date, :payment_confirmed_at,
                    :product_code, :product_name, :price_code, :price_name, :currency,
                    :gross_revenue, :net_revenue, :producer_net,
                    :buyer_name, :buyer_email, :buyer_phone_raw, :buyer_phone_norm,
                    :matched_user_id, :match_method,
                    :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                    NOW(), NOW()
                )";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':transaction_code' => $saleData['transaction_code'],
        ':status' => $saleData['legacy_status'],
        ':transaction_date' => $saleData['transaction_date'],
        ':payment_confirmed_at' => $saleData['payment_confirmed_at'],
        ':product_code' => $saleData['product_code'],
        ':product_name' => $saleData['product_name'],
        ':price_code' => $saleData['price_code'],
        ':price_name' => $saleData['price_name'],
        ':currency' => $saleData['currency'],
        ':gross_revenue' => $saleData['gross_revenue'],
        ':net_revenue' => $saleData['net_revenue'],
        ':producer_net' => $saleData['producer_net'],
        ':buyer_name' => $saleData['buyer_name'],
        ':buyer_email' => $saleData['buyer_email'],
        ':buyer_phone_raw' => $saleData['buyer_phone_raw'],
        ':buyer_phone_norm' => $saleData['buyer_phone_norm'],
        ':matched_user_id' => $saleData['matched_user_id'],
        ':match_method' => $saleData['match_method'],
        ':utm_source' => $saleData['utm_source'],
        ':utm_medium' => $saleData['utm_medium'],
        ':utm_campaign' => $saleData['utm_campaign'],
        ':utm_term' => $saleData['utm_term'],
        ':utm_content' => $saleData['utm_content'],
    ]);
}

function get_existing_sale(PDO $pdo, string $transactionCode): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM hotmart_sales_live WHERE transaction_code = :transaction_code LIMIT 1');
    $stmt->execute([':transaction_code' => $transactionCode]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function upsert_sale(PDO $pdo, array $saleData): void
{
    $exists = get_existing_sale($pdo, $saleData['transaction_code']) !== null;

    if ($exists) {
        $sql = "UPDATE hotmart_sales_live SET
                    webhook_event = :webhook_event,
                    webhook_event_id = :webhook_event_id,
                    status = :status,
                    transaction_date = :transaction_date,
                    payment_confirmed_at = :payment_confirmed_at,
                    refund_or_chargeback_at = :refund_or_chargeback_at,
                    product_code = :product_code,
                    product_name = :product_name,
                    price_code = :price_code,
                    price_name = :price_name,
                    currency = :currency,
                    gross_revenue = :gross_revenue,
                    net_revenue = :net_revenue,
                    producer_net = :producer_net,
                    refunded_value = :refunded_value,
                    chargeback_value = :chargeback_value,
                    buyer_name = :buyer_name,
                    buyer_email = :buyer_email,
                    buyer_phone_raw = :buyer_phone_raw,
                    buyer_phone_norm = :buyer_phone_norm,
                    matched_user_id = :matched_user_id,
                    match_method = :match_method,
                    utm_source = :utm_source,
                    utm_medium = :utm_medium,
                    utm_campaign = :utm_campaign,
                    utm_term = :utm_term,
                    utm_content = :utm_content,
                    raw_payload_json = :raw_payload_json,
                    updated_at = NOW()
                WHERE transaction_code = :transaction_code";
    } else {
        $sql = "INSERT INTO hotmart_sales_live (
                    webhook_event, webhook_event_id, transaction_code, status,
                    transaction_date, payment_confirmed_at, refund_or_chargeback_at,
                    product_code, product_name, price_code, price_name, currency,
                    gross_revenue, net_revenue, producer_net, refunded_value, chargeback_value,
                    buyer_name, buyer_email, buyer_phone_raw, buyer_phone_norm,
                    matched_user_id, match_method,
                    utm_source, utm_medium, utm_campaign, utm_term, utm_content,
                    raw_payload_json, imported_at, updated_at
                ) VALUES (
                    :webhook_event, :webhook_event_id, :transaction_code, :status,
                    :transaction_date, :payment_confirmed_at, :refund_or_chargeback_at,
                    :product_code, :product_name, :price_code, :price_name, :currency,
                    :gross_revenue, :net_revenue, :producer_net, :refunded_value, :chargeback_value,
                    :buyer_name, :buyer_email, :buyer_phone_raw, :buyer_phone_norm,
                    :matched_user_id, :match_method,
                    :utm_source, :utm_medium, :utm_campaign, :utm_term, :utm_content,
                    :raw_payload_json, NOW(), NOW()
                )";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':webhook_event' => $saleData['webhook_event'],
        ':webhook_event_id' => $saleData['webhook_event_id'],
        ':transaction_code' => $saleData['transaction_code'],
        ':status' => $saleData['status'],
        ':transaction_date' => $saleData['transaction_date'],
        ':payment_confirmed_at' => $saleData['payment_confirmed_at'],
        ':refund_or_chargeback_at' => $saleData['refund_or_chargeback_at'],
        ':product_code' => $saleData['product_code'],
        ':product_name' => $saleData['product_name'],
        ':price_code' => $saleData['price_code'],
        ':price_name' => $saleData['price_name'],
        ':currency' => $saleData['currency'],
        ':gross_revenue' => $saleData['gross_revenue'],
        ':net_revenue' => $saleData['net_revenue'],
        ':producer_net' => $saleData['producer_net'],
        ':refunded_value' => $saleData['refunded_value'],
        ':chargeback_value' => $saleData['chargeback_value'],
        ':buyer_name' => $saleData['buyer_name'],
        ':buyer_email' => $saleData['buyer_email'],
        ':buyer_phone_raw' => $saleData['buyer_phone_raw'],
        ':buyer_phone_norm' => $saleData['buyer_phone_norm'],
        ':matched_user_id' => $saleData['matched_user_id'],
        ':match_method' => $saleData['match_method'],
        ':utm_source' => $saleData['utm_source'],
        ':utm_medium' => $saleData['utm_medium'],
        ':utm_campaign' => $saleData['utm_campaign'],
        ':utm_term' => $saleData['utm_term'],
        ':utm_content' => $saleData['utm_content'],
        ':raw_payload_json' => $saleData['raw_payload_json'],
    ]);
}

function trigger_attribution_after_webhook(): array
{
    $results = [];
    try {
        require_once dirname(__DIR__) . '/includes/bootstrap.php';
        $pdo = db();
        $sourcePdo = source_db();
        $stmt = $pdo->query("SELECT id FROM meta_integrations WHERE status = 'active' ORDER BY id ASC");
        $rows = $stmt->fetchAll();
        foreach ($rows as $row) {
            $integrationId = (int)($row['id'] ?? 0);
            if ($integrationId <= 0) {
                continue;
            }
            try {
                $results[] = [
                    'integration_id' => $integrationId,
                    'result' => sync_full_attribution($pdo, $sourcePdo, $integrationId, 365),
                ];
            } catch (Throwable $e) {
                $results[] = [
                    'integration_id' => $integrationId,
                    'error' => $e->getMessage(),
                ];
                write_log('Hotmart webhook attribution trigger error', [
                    'integration_id' => $integrationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    } catch (Throwable $e) {
        write_log('Hotmart webhook bootstrap attribution error', ['error' => $e->getMessage()]);
        $results[] = ['error' => $e->getMessage()];
    }
    return $results;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        respond(405, ['ok' => false, 'message' => 'Method not allowed']);
    }

    $rawPayload = file_get_contents('php://input');
    $headers = get_request_headers_normalized();

    if (HOTMART_HOTTOK !== '') {
        $sentToken = $headers['x-hotmart-hottok'] ?? ($headers['hotmart-hottok'] ?? '');
        if (!hash_equals(HOTMART_HOTTOK, (string) $sentToken)) {
            write_log('Hotmart Hottok validation failed', ['sent' => $sentToken]);
            respond(401, ['ok' => false, 'message' => 'Unauthorized']);
        }
    }

    if ($rawPayload === false || trim($rawPayload) === '') {
        respond(400, ['ok' => false, 'message' => 'Empty body']);
    }

    $payload = json_decode($rawPayload, true);
    if (!is_array($payload)) {
        respond(400, ['ok' => false, 'message' => 'Invalid JSON']);
    }

    $eventId = (string) ($payload['id'] ?? '');
    $eventName = (string) ($payload['event'] ?? '');
    $data = $payload['data'] ?? [];
    $purchase = $data['purchase'] ?? [];
    $buyer = $data['buyer'] ?? [];
    $product = $data['product'] ?? [];
    $offer = $purchase['offer'] ?? [];
    $commissions = is_array($data['commissions'] ?? null) ? $data['commissions'] : [];

    $transactionCode = (string) ($purchase['transaction'] ?? '');
    $mainPdo = get_main_db();

    if ($transactionCode === '') {
        save_event_log($mainPdo, $eventId, $eventName, null, $rawPayload, 'error', 'Missing purchase.transaction');
        respond(422, ['ok' => false, 'message' => 'Missing purchase.transaction']);
    }

    $buyerEmail = (string) ($buyer['email'] ?? '');
    $buyerPhoneRaw = trim((string) ($buyer['checkout_phone_code'] ?? '') . (string) ($buyer['checkout_phone'] ?? ''));
    $buyerPhoneNorm = normalize_phone($buyerPhoneRaw);
    $buyerEmailNorm = normalize_email($buyerEmail);

    $sourcePdo = get_source_db();
    $match = find_matching_user($sourcePdo, $buyerEmailNorm, $buyerPhoneNorm);
    $user = $match['user'];
    $matchMethod = (string) $match['method'];

    $status = map_status($eventName, $purchase['status'] ?? null);
    $netRevenue = (float) ($purchase['price']['value'] ?? 0);

    $saleData = [
        'webhook_event' => $eventName,
        'webhook_event_id' => $eventId,
        'transaction_code' => $transactionCode,
        'status' => $status,
        'legacy_status' => legacy_status_from_status($status),
        'transaction_date' => milliseconds_to_datetime($purchase['order_date'] ?? null),
        'payment_confirmed_at' => milliseconds_to_datetime($purchase['approved_date'] ?? null),
        'refund_or_chargeback_at' => in_array($status, ['REFUNDED', 'CHARGEBACK', 'CANCELED'], true)
            ? milliseconds_to_datetime($payload['creation_date'] ?? null)
            : null,
        'product_code' => (int) ($product['id'] ?? 0) ?: null,
        'product_name' => (string) ($product['name'] ?? ''),
        'price_code' => (string) ($offer['code'] ?? ''),
        'price_name' => (string) ($offer['name'] ?? ''),
        'currency' => (string) (($purchase['price']['currency_value'] ?? '') ?: ($purchase['full_price']['currency_value'] ?? 'BRL')),
        'gross_revenue' => (float) ($purchase['full_price']['value'] ?? 0),
        'net_revenue' => $netRevenue,
        'producer_net' => producer_net_from_commissions($commissions),
        'refunded_value' => $status === 'REFUNDED' ? $netRevenue : 0.0,
        'chargeback_value' => $status === 'CHARGEBACK' ? $netRevenue : 0.0,
        'buyer_name' => (string) ($buyer['name'] ?? ''),
        'buyer_email' => $buyerEmail,
        'buyer_phone_raw' => $buyerPhoneRaw,
        'buyer_phone_norm' => $buyerPhoneNorm,
        'matched_user_id' => $user ? (int) $user['id'] : null,
        'match_method' => in_array($matchMethod, ['phone', 'email'], true) ? $matchMethod : 'none',
        'utm_source' => $user ? (string) ($user['utm_source'] ?? '') : null,
        'utm_medium' => $user ? (string) ($user['utm_medium'] ?? '') : null,
        'utm_campaign' => $user ? (string) ($user['utm_campaign'] ?? '') : null,
        'utm_term' => $user ? (string) ($user['utm_term'] ?? '') : null,
        'utm_content' => $user ? (string) ($user['utm_content'] ?? '') : null,
        'raw_payload_json' => $rawPayload,
    ];

    $mainPdo->beginTransaction();
    upsert_sale($mainPdo, $saleData);
    save_event_log($mainPdo, $eventId, $eventName, $transactionCode, $rawPayload, 'success', 'Processed successfully');
    $mainPdo->commit();

    upsert_legacy_sale($sourcePdo, $saleData);

    $attributionTrigger = trigger_attribution_after_webhook();

    write_log('Hotmart webhook processed', [
        'event' => $eventName,
        'transaction' => $transactionCode,
        'status' => $status,
        'legacy_status' => $saleData['legacy_status'],
        'price_code' => $saleData['price_code'],
        'price_name' => $saleData['price_name'],
        'matched_user_id' => $saleData['matched_user_id'],
        'match_method' => $saleData['match_method'],
        'attribution_trigger' => $attributionTrigger,
    ]);

    respond(200, [
        'ok' => true,
        'transaction' => $transactionCode,
        'status' => $status,
        'legacy_status' => $saleData['legacy_status'],
        'matched_user_id' => $saleData['matched_user_id'],
        'match_method' => $saleData['match_method'],
        'attribution_trigger' => $attributionTrigger,
    ]);
} catch (Throwable $e) {
    try {
        if (isset($mainPdo) && $mainPdo instanceof PDO && $mainPdo->inTransaction()) {
            $mainPdo->rollBack();
        }
    } catch (Throwable $ignore) {
    }

    write_log('Hotmart webhook processing error', [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);

    try {
        if (isset($mainPdo) && $mainPdo instanceof PDO) {
            save_event_log(
                $mainPdo,
                (string) ($payload['id'] ?? ''),
                (string) ($payload['event'] ?? ''),
                (string) ($payload['data']['purchase']['transaction'] ?? ''),
                isset($rawPayload) ? (string) $rawPayload : '',
                'error',
                $e->getMessage()
            );
        }
    } catch (Throwable $ignore) {
    }

    respond(500, ['ok' => false, 'message' => 'Internal error', 'error' => $e->getMessage()]);
}
