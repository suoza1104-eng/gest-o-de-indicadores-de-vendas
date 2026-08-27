<?php


require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = db();
    $sourcePdo = source_db();
    $stmt = $pdo->query("SELECT * FROM meta_integrations WHERE status = 'active' ORDER BY id ASC");
    $integrations = $stmt->fetchAll();

    $summary = [];

    foreach ($integrations as $integration) {
        $lastSyncAt = $integration['last_sync_at'] ? strtotime((string) $integration['last_sync_at']) : 0;
        $intervalMinutes = max(5, (int) $integration['sync_interval_minutes']);
        $shouldRun = $lastSyncAt === 0 || (time() - $lastSyncAt) >= ($intervalMinutes * 60);

        if (!$shouldRun) {
            $summary[] = [
                'integration_id' => (int) $integration['id'],
                'status' => 'skipped',
                'reason' => 'Ainda dentro da janela de sincronização.',
            ];
            continue;
        }

        $since = date('Y-m-d', strtotime('-3 days'));
        $until = date('Y-m-d');

        $runResults = [];
        foreach (['account', 'campaign', 'adset', 'ad'] as $scope) {
            $runResults[] = sync_meta_level($pdo, $integration, $scope, $since, $until);
        }

        $attrResults = sync_full_attribution($pdo, $sourcePdo, (int) $integration['id']);

        $summary[] = [
            'integration_id' => (int) $integration['id'],
            'status' => 'success',
            'meta_results' => $runResults,
            'attribution_results' => $attrResults,
        ];
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => true,
        'message' => 'Cron executado com sucesso.',
        'summary' => $summary,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    app_log('Erro no cron Meta/Atribuição', ['error' => $e->getMessage()]);
    header('Content-Type: application/json; charset=utf-8', true, 500);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
