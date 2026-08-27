<?php


require_once __DIR__ . '/../includes/bootstrap.php';
require_api_key_if_present();

try {
    $pdo = db();
    $integrationId = (int) (post('integration_id', 0) ?: get_param('integration_id', 0));

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
        json_response(['ok' => false, 'message' => 'Access token e ad account id são obrigatórios.'], 422);
    }

    $result = meta_test_connection((string) $integration['access_token'], (string) $integration['ad_account_id']);

    json_response([
        'ok' => true,
        'message' => 'Conexão com a Meta validada com sucesso.',
        'data' => $result,
    ]);
} catch (Throwable $e) {
    app_log('Erro ao testar conexão Meta', ['error' => $e->getMessage()]);
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
