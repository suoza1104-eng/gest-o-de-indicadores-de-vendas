<?php


require_once __DIR__ . '/../includes/bootstrap.php';
require_api_key_if_present();

try {
    @set_time_limit(300);
    $pdo = db();
    $sourcePdo = source_db();
    $integrationId = (int)(post('integration_id', 0) ?: get_param('integration_id', 0));
    $mode = trim((string)(post('mode', '') ?: get_param('mode', 'daily')));
    $days = (int)(post('days', 0) ?: get_param('days', 0));

    if ($integrationId <= 0) {
        json_response(['ok' => false, 'message' => 'integration_id é obrigatório.'], 422);
    }

    $stmt = $pdo->prepare('SELECT * FROM meta_integrations WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $integrationId]);
    $integration = $stmt->fetch();
    if (!$integration) {
        json_response(['ok' => false, 'message' => 'Integração não encontrada.'], 404);
    }

    $daysBack = $mode === 'history' ? max(1, min(365, $days > 0 ? $days : 90)) : 3;
    $result = sync_full_attribution($pdo, $sourcePdo, $integrationId, $daysBack);
    json_response(['ok' => true, 'message' => 'Sincronização de atribuição concluída.', 'results' => $result]);
} catch (Throwable $e) {
    app_log('Erro ao sincronizar atribuição', ['error' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
    json_response(['ok' => false, 'message' => 'Falha na sincronização de atribuição: ' . $e->getMessage()], 500);
}
