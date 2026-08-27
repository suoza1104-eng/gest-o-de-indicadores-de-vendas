<?php

ignore_user_abort(true);
set_time_limit(0);
ini_set('max_execution_time', '0');
ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/bootstrap.php';
require_api_key_if_present();

try {
    $pdo = db();
    $integrationId = (int) (post('integration_id', 0) ?: get_param('integration_id', 0));
    $scope = trim((string) (post('scope', '') ?: get_param('scope', 'all')));
    $mode = trim((string) (post('mode', '') ?: get_param('mode', 'daily')));
    $days = (int) (post('days', 0) ?: get_param('days', 0));
    $since = trim((string) (post('since', '') ?: get_param('since', '')));
    $until = trim((string) (post('until', '') ?: get_param('until', '')));

    if ($integrationId <= 0) {
        json_response(['ok' => false, 'message' => 'integration_id é obrigatório.'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM meta_integrations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $integrationId]);
    $integration = $stmt->fetch();

    if (!$integration) {
        json_response(['ok' => false, 'message' => 'Integração não encontrada.'], 404);
    }

    if (empty($integration['access_token']) || empty($integration['ad_account_id'])) {
        json_response(['ok' => false, 'message' => 'A integração precisa ter access token e ad account id preenchidos.'], 422);
    }

    if ($mode === 'history') {
        $daysBack = max(1, min(180, $days > 0 ? $days : 30));
    } else {
        $daysBack = 3;
    }
    $since = $since !== '' ? $since : date('Y-m-d', strtotime('-' . ($daysBack - 1) . ' days'));
    $until = $until !== '' ? $until : date('Y-m-d');

    $scopes = $scope === 'all' ? ['account', 'campaign', 'adset', 'ad'] : [$scope];
    $results = [];

    foreach ($scopes as $item) {
        $results[] = sync_meta_level($pdo, $integration, $item, $since, $until);
    }

    json_response([
        'ok' => true,
        'message' => 'Sincronização concluída.',
        'results' => $results,
    ]);
} catch (Throwable $e) {
    app_log('Erro ao sincronizar Meta', ['error' => $e->getMessage()]);
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
