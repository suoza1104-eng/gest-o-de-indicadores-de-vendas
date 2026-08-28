<?php
session_start();

require_once __DIR__ . '/includes/bootstrap.php';

if (!empty($_SESSION['meta_admin_logged'])) {
    header('Location: /gestaotrafego/admin/');
    exit;
}

$error = '';

function ensure_admin_user_schema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admin_users (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            email VARCHAR(190) NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_users_email (email)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $stmt = $pdo->prepare("
        INSERT INTO admin_users (email, password_hash, is_active, created_at, updated_at)
        VALUES (:email, :password_hash, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            password_hash = VALUES(password_hash),
            is_active = VALUES(is_active),
            updated_at = NOW()
    ");

    $stmt->execute([
        ':email' => 'admin@professoremersonleite.site',
        ':password_hash' => '$2y$12$6/xX10W3jw6yQ21ovAFNdeD/EBbyWmEROm1HQXoU8C96rfaYV1idO',
    ]);
}

if (!empty($_GET['expired'])) {
    $error = 'Sua sessão expirou por inatividade. Faça login novamente.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $usernameKey = strtolower($username);
    $adminAliases = ['admin', 'souza1104', 'suoza1104'];
    $loginEmail = in_array($usernameKey, $adminAliases, true) ? 'admin@professoremersonleite.site' : $username;

    try {
        $pdo = db();
        ensure_admin_user_schema($pdo);

        $stmt = $pdo->prepare("
            SELECT id, email, password_hash, is_active
            FROM admin_users
            WHERE email = :email
            LIMIT 1
        ");
        $stmt->execute([':email' => $loginEmail]);
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

            header('Location: /gestaotrafego/admin/');
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
    <title>Login - Meta Ads Manager Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/lucide@latest"></script>
    <link rel="stylesheet" href="assets/css/app-pro.css">
</head>
<body class="bg-[#090d16] text-slate-100 min-h-screen flex items-center justify-center p-4 relative overflow-hidden">
    <!-- Glow Backdrop Effect -->
    <div class="absolute top-1/4 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[500px] h-[500px] bg-indigo-600/20 rounded-full blur-[140px] pointer-events-none"></div>
    <div class="absolute bottom-10 right-10 w-[350px] h-[350px] bg-violet-600/15 rounded-full blur-[120px] pointer-events-none"></div>

    <div class="w-full max-width-[440px] max-w-md relative z-10">
        <!-- Logo Header -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-indigo-600/10 border border-indigo-500/30 text-indigo-400 mb-4 shadow-[0_0_25px_rgba(99,102,241,0.3)]">
                <i data-lucide="bar-chart-3" class="w-8 h-8"></i>
            </div>
            <h1 class="text-3xl font-extrabold tracking-tight text-white">Meta Ads Pro</h1>
            <p class="text-slate-400 text-sm mt-1">Plataforma de Inteligência e Atribuição de Trafego</p>
        </div>

        <!-- Glass Card -->
        <div class="bg-[#111726]/80 backdrop-blur-xl border border-white/10 rounded-2xl p-8 shadow-[0_20px_50px_rgba(0,0,0,0.6)]">
            <h2 class="text-xl font-bold text-white mb-2">Acesse sua conta</h2>
            <p class="text-slate-400 text-xs mb-6">Entre com suas credenciais de administrador</p>

            <?php if (!empty($error)): ?>
                <div class="mb-6 p-4 rounded-xl bg-rose-500/10 border border-rose-500/30 text-rose-400 text-sm flex items-center gap-3">
                    <i data-lucide="alert-circle" class="w-5 h-5 flex-shrink-0"></i>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="space-y-5">
                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">E-mail ou Usuário</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                            <i data-lucide="mail" class="w-5 h-5"></i>
                        </div>
                        <input type="text" name="email" required placeholder="admin@empresa.com" 
                            class="w-full pl-11 pr-4 py-3 bg-slate-900/80 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm transition-all">
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-slate-300 uppercase tracking-wider mb-2">Senha</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-500">
                            <i data-lucide="lock" class="w-5 h-5"></i>
                        </div>
                        <input type="password" id="password-field" name="password" required placeholder="••••••••" 
                            class="w-full pl-11 pr-11 py-3 bg-slate-900/80 border border-white/10 rounded-xl text-white placeholder-slate-500 focus:outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20 text-sm transition-all">
                        <button type="button" id="toggle-password" class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-500 hover:text-slate-300">
                            <i data-lucide="eye" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <button type="submit" 
                    class="w-full py-3.5 px-4 bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 text-white font-semibold rounded-xl shadow-[0_4px_20px_rgba(99,102,241,0.4)] transition-all transform hover:-translate-y-0.5 active:translate-y-0 flex items-center justify-center gap-2">
                    <span>Entrar no Painel</span>
                    <i data-lucide="arrow-right" class="w-5 h-5"></i>
                </button>
            </form>
        </div>

        <div class="text-center mt-6 text-xs text-slate-500">
            &copy; <?= date('Y') ?> Meta Ads Manager Pro. Todos os direitos reservados.
        </div>
    </div>

    <script>
        lucide.createIcons();
        const toggleBtn = document.getElementById('toggle-password');
        const passField = document.getElementById('password-field');
        if (toggleBtn && passField) {
            toggleBtn.addEventListener('click', () => {
                const type = passField.type === 'password' ? 'text' : 'password';
                passField.type = type;
            });
        }
    </script>
</body>
</html>
