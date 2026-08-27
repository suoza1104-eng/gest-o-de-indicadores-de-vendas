<?php
session_start();
if (empty($_SESSION['meta_admin_logged'])) {
    http_response_code(401);
    echo 'Sessão expirada.';
    exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';

function redirect_back(array $flash): void
{
    $_SESSION['hotmart_csv_flash'] = $flash;
    header('Location: ../admin/upload_hotmart.php');
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        throw new RuntimeException('Método inválido.');
    }
    if (empty($_FILES['csv_file']) || !is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        throw new RuntimeException('Envie um arquivo CSV válido.');
    }

    $file = $_FILES['csv_file'];
    $tmp = (string)$file['tmp_name'];
    $name = (string)($file['name'] ?? 'hotmart.csv');
    if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'csv') {
        throw new RuntimeException('O arquivo precisa ser CSV.');
    }

    $pdo = db();
    $sourcePdo = source_db();
    $import = hotmart_import_csv($pdo, $sourcePdo, $tmp, $name);

    $attrRuns = [];
    $stmt = $pdo->query("SELECT id FROM meta_integrations WHERE status = 'active' ORDER BY id ASC");
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $integrationId = (int)($row['id'] ?? 0);
        if ($integrationId <= 0) { continue; }
        $attrRuns[] = ['integration_id' => $integrationId, 'result' => sync_full_attribution($pdo, $sourcePdo, $integrationId, 365)];
    }

    $text = sprintf(
        'Importação concluída. Inseridas: %d | Atualizadas: %d | Erros: %d',
        (int)$import['inserted'],
        (int)$import['updated'],
        (int)$import['errors']
    );
    if ($attrRuns) {
        $text .= ' | Atribuição reprocessada.';
    }
    redirect_back(['ok' => true, 'text' => $text, 'import' => $import, 'runs' => $attrRuns]);
} catch (Throwable $e) {
    redirect_back(['ok' => false, 'text' => $e->getMessage()]);
}
