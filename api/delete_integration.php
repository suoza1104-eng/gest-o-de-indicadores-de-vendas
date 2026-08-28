<?php


require_once __DIR__ . '/../includes/bootstrap.php';
require_api_key_if_present();

if (request_method() !== 'POST') {
    json_response(['ok' => false, 'message' => 'Método não permitido.'], 405);
}

try {
    $pdo = db();
    $id = (int) post('id', 0);

    if ($id <= 0) {
        json_response(['ok' => false, 'message' => 'id é obrigatório.'], 422);
    }

    $stmt = $pdo->prepare('DELETE FROM meta_integrations WHERE id = :id');
    $stmt->execute(['id' => $id]);

    json_response([
        'ok' => true,
        'message' => 'Integração excluída com sucesso.',
    ]);
} catch (Throwable $e) {
    app_log('Erro ao excluir integração', ['error' => $e->getMessage()]);
    json_response([
        'ok' => false,
        'message' => $e->getMessage(),
    ], 500);
}
