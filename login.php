<?php
session_start();

require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['meta_admin_logged'])) {
    header('Location: /meta_ads_manager_project/admin/');
    exit;
}

$error = '';

if (!empty($_GET['expired'])) {
    $error = 'Sua sessão expirou por inatividade. Faça login novamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    try {
        $pdo = db();
        $stmt = $pdo->prepare("
            SELECT id, email, password_hash, is_active
            FROM admin_users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $error = 'Usuário não encontrado.';
        } elseif ((int)$user['is_active'] !== 1) {
            $error = 'Usuário inativo.';
        } elseif (!password_verify($password, (string)$user['password_hash'])) {
            $error = 'Senha inválida.';
        } else {
            session_regenerate_id(true);
            $_SESSION['meta_admin_logged'] = true;
            $_SESSION['meta_admin_id'] = (int)$user['id'];
            $_SESSION['meta_admin_email'] = (string)$user['email'];
            $_SESSION['meta_admin_last_activity'] = time();

            header('Location: /meta_ads_manager_project/admin/');
            exit;
        }
    } catch (Throwable $e) {
        if (function_exists('app_log')) {
            app_log('Falha no login admin', ['error' => $e->getMessage(), 'username' => $username]);
        }
        $error = 'Erro interno ao tentar entrar.';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Meta Ads Manager</title>
    <style>
        body{
            margin:0;
            font-family:Arial,Helvetica,sans-serif;
            background:#f3f6fb;
            display:flex;
            align-items:center;
            justify-content:center;
            min-height:100vh;
        }
        .card{
            width:100%;
            max-width:420px;
            background:#fff;
            border-radius:16px;
            padding:32px;
            box-shadow:0 10px 30px rgba(0,0,0,.08);
        }
        h1{
            margin:0 0 8px;
            font-size:28px;
            color:#13233f;
        }
        p{
            margin:0 0 24px;
            color:#5d6b82;
        }
        label{
            display:block;
            font-size:14px;
            font-weight:700;
            margin:0 0 8px;
            color:#13233f;
        }
        input{
            width:100%;
            box-sizing:border-box;
            padding:14px 16px;
            border:1px solid #d8e0ee;
            border-radius:12px;
            margin-bottom:18px;
            font-size:15px;
        }
        button{
            width:100%;
            border:none;
            border-radius:12px;
            padding:14px 16px;
            background:#13233f;
            color:#fff;
            font-size:15px;
            font-weight:700;
            cursor:pointer;
        }
        .error{
            background:#fdecec;
            color:#b42318;
            border-radius:12px;
            padding:12px 14px;
            margin-bottom:18px;
            font-size:14px;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>Meta Ads Manager</h1>
        <p>Faça login para acessar o painel.</p>

        <?php if ($error !== ''): ?>
            <div class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <form method="post" autocomplete="off">
            <label for="email">Usuário</label>
            <input type="text" name="email" id="email" required>

            <label for="password">Senha</label>
            <input type="password" name="password" id="password" required>

            <button type="submit">Entrar</button>
        </form>
    </div>
</body>
</html>
