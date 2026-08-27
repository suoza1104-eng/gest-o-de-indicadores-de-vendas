<?php

require_once __DIR__ . '/../includes/bootstrap.php';

try {
    $pdo = db();
    $sourcePdo = source_db();
    $stmt = $pdo->query("SELECT * FROM meta_integrations WHERE status = 'active' ORDER BY id ASC");
    $integrations = $stmt->fetchAll();

    $summary = [];

    foreach ($integrations as $integration) {
        $integrationId = (int)($integration['id'] ?? 0);
        $lastSyncAt = !empty($integration['last_sync_at']) ? strtotime((string)$integration['last_sync_at']) : 0;
        $intervalMinutes = max(5, (int)($integration['sync_interval_minutes'] ?? 60));
        $shouldRunMeta = $lastSyncAt === 0 || (time() - $lastSyncAt) >= ($intervalMinutes * 60);

        $metaSummary = [];
        if ($shouldRunMeta) {
            $since = date('Y-m-d', strtotime('-3 days'));
            $until = date('Y-m-d');

            foreach (['account', 'campaign', 'adset', 'ad'] as $scope) {
                $metaSummary[] = sync_meta_level($pdo, $integration, $scope, $since, $until);
            }
        } else {
            $metaSummary[] = [
                'status' => 'skipped',
                'reason' => 'Ainda dentro da janela de sincronização da Meta.',
            ];
        }

        $attrResults = sync_full_attribution($pdo, $sourcePdo, $integrationId, 3);

        $summary[] = [
            'integration_id' => $integrationId,
            'status' => 'success',
            'meta_results' => $metaSummary,
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
