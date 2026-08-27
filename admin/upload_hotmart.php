<?php
session_start();
if (empty($_SESSION['meta_admin_logged'])) {
    header('Location: ../login.php');
    exit;
}
require_once __DIR__ . '/../includes/bootstrap.php';
$message = $_SESSION['hotmart_csv_flash'] ?? null;
unset($_SESSION['hotmart_csv_flash']);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Importar CSV Hotmart</title>
<style>
body{font-family:Arial,sans-serif;background:#0f172a;color:#e5e7eb;margin:0;padding:24px}
.wrap{max-width:900px;margin:0 auto}
.card{background:#111827;border:1px solid #334155;border-radius:14px;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,.25)}
h1{margin:0 0 8px}p{color:#94a3b8}.muted{font-size:14px;color:#94a3b8}
input[type=file]{display:block;width:100%;padding:12px;border:1px solid #475569;border-radius:10px;background:#0b1220;color:#e5e7eb;margin:16px 0}
button,.btn{background:#22c55e;color:#052e16;border:none;border-radius:10px;padding:12px 18px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block}
.btn.secondary{background:#334155;color:#fff}
.alert{padding:14px 16px;border-radius:10px;margin:0 0 16px}.ok{background:#052e16;border:1px solid #16a34a}.err{background:#3f0d12;border:1px solid #dc2626}
ul{line-height:1.7}
.top{display:flex;gap:12px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:18px}
</style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>Importar vendas da Hotmart por CSV</h1>
            <p>Webhook em tempo real + importação periódica para saneamento da base.</p>
        </div>
        <div>
            <a class="btn secondary" href="index.php">Voltar ao dashboard</a>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert <?= !empty($message['ok']) ? 'ok' : 'err' ?>">
            <?= htmlspecialchars((string)($message['text'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <form method="post" action="../api/import_hotmart_csv.php" enctype="multipart/form-data">
            <label for="csv"><strong>Arquivo CSV da Hotmart</strong></label>
            <input id="csv" type="file" name="csv_file" accept=".csv,text/csv" required>
            <button type="submit">Importar e reprocessar atribuição</button>
        </form>
        <div class="muted" style="margin-top:16px">
            <ul>
                <li>Usa o <strong>HP/transação</strong> como chave.</li>
                <li>Se a venda já existir, <strong>atualiza</strong>; se não existir, <strong>insere</strong>.</li>
                <li>Ao importar, busca lead por <strong>telefone/email normalizados</strong> e herda as UTMs do lead.</li>
                <li>No final, roda novamente a atribuição para atualizar as pendências e os matches automáticos.</li>
            </ul>
        </div>
    </div>
</div>
</body>
</html>
