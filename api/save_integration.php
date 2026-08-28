<?php


require_once __DIR__ . '/../includes/bootstrap.php';
require_api_key_if_present();

if (request_method() !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $pdo = db();
    ensure_meta_integration_schema($pdo);

    $id = (int) post('id', 0);
    $name = trim((string) post('name', 'Meta Principal'));
    $appId = trim((string) post('app_id', ''));
    $appSecret = trim((string) post('app_secret', ''));
    $accessToken = trim((string) post('access_token', ''));
    $adAccountId = normalize_account_id((string) post('ad_account_id', ''));
    $status = post('status', 'active') === 'inactive' ? 'inactive' : 'active';
    $syncInterval = max(5, (int) post('sync_interval_minutes', 30));
    $timezone = trim((string) post('timezone', 'America/Sao_Paulo'));
    $currencyCode = strtoupper(trim((string) post('currency_code', 'BRL')));
    $currencyCode = in_array($currencyCode, ['BRL', 'USD'], true) ? $currencyCode : 'BRL';
    $currencySpreadPercent = max(0, (float) str_replace(',', '.', (string) post('currency_spread_percent', 0)));
    $manualExchangeRate = trim((string) post('manual_exchange_rate', ''));
    $manualExchangeRate = $manualExchangeRate === '' ? null : max(0, (float) str_replace(',', '.', $manualExchangeRate));

    if ($name === '' || $adAccountId === '') {
        json_response(['ok' => false, 'message' => 'Nome e Ad Account ID são obrigatórios.'], 422);
    }

    if ($id > 0) {
        $sql = 'UPDATE meta_integrations SET
            name = :name,
            app_id = :app_id,
            app_secret = :app_secret,
            access_token = :access_token,
            ad_account_id = :ad_account_id,
            status = :status,
            sync_interval_minutes = :sync_interval_minutes,
            timezone = :timezone,
            currency_code = :currency_code,
            currency_spread_percent = :currency_spread_percent,
            manual_exchange_rate = :manual_exchange_rate,
            updated_at = NOW()
            WHERE id = :id';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'id' => $id,
            'name' => $name,
            'app_id' => $appId ?: null,
            'app_secret' => $appSecret ?: null,
            'access_token' => $accessToken ?: null,
            'ad_account_id' => $adAccountId,
            'status' => $status,
            'sync_interval_minutes' => $syncInterval,
            'timezone' => $timezone,
            'currency_code' => $currencyCode,
            'currency_spread_percent' => $currencySpreadPercent,
            'manual_exchange_rate' => $manualExchangeRate,
        ]);
    } else {
        $sql = 'INSERT INTO meta_integrations (
            name, app_id, app_secret, access_token, ad_account_id, status, sync_interval_minutes, timezone, currency_code, currency_spread_percent, manual_exchange_rate, created_at, updated_at
        ) VALUES (
            :name, :app_id, :app_secret, :access_token, :ad_account_id, :status, :sync_interval_minutes, :timezone, :currency_code, :currency_spread_percent, :manual_exchange_rate, NOW(), NOW()
        )';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'name' => $name,
            'app_id' => $appId ?: null,
            'app_secret' => $appSecret ?: null,
            'access_token' => $accessToken ?: null,
            'ad_account_id' => $adAccountId,
            'status' => $status,
            'sync_interval_minutes' => $syncInterval,
            'timezone' => $timezone,
            'currency_code' => $currencyCode,
            'currency_spread_percent' => $currencySpreadPercent,
            'manual_exchange_rate' => $manualExchangeRate,
        ]);
        $id = (int) $pdo->lastInsertId();
    }

    json_response([
        'ok' => true,
        'message' => 'Integração salva com sucesso.',
        'integration_id' => $id,
    ]);
} catch (Throwable $e) {
    app_log('Erro ao salvar integração', ['error' => $e->getMessage()]);
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
