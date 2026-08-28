<?php

declare(strict_types=1);

header('Content-Type: text/plain; charset=utf-8');

$token = $_GET['token'] ?? '';
if (!hash_equals('emerson-gestaotrafego-2026', (string) $token)) {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

function print_status(string $label, bool $ok, string $detail = ''): void
{
    echo ($ok ? '[OK] ' : '[ERRO] ') . $label;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo "\n";
}

echo "Diagnostico Gestao Trafego\n";
echo "Data: " . date('Y-m-d H:i:s') . "\n\n";

$configPath = __DIR__ . '/config/config.php';
print_status('Arquivo config.php', is_file($configPath), $configPath);

try {
    if (!is_file($configPath)) {
        throw new RuntimeException('Arquivo config/config.php nao encontrado.');
    }

    $config = require $configPath;
    print_status('Config carregado', is_array($config));

    $db = $config['db'] ?? null;
    if (!is_array($db)) {
        throw new RuntimeException('Secao db nao encontrada no config.php.');
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int) ($db['port'] ?? 3306),
        $db['dbname'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, (string) ($db['username'] ?? ''), (string) ($db['password'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    print_status('Conexao MySQL', true, 'banco=' . (string) ($db['dbname'] ?? ''));

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
    print_status('Tabela admin_users', true, 'criada/verificada');

    $adminEmail = 'admin@professoremersonleite.site';
    $adminHash = '$2y$12$6/xX10W3jw6yQ21ovAFNdeD/EBbyWmEROm1HQXoU8C96rfaYV1idO';

    $stmt = $pdo->prepare("
        INSERT INTO admin_users (email, password_hash, is_active, created_at, updated_at)
        VALUES (:email, :password_hash, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            password_hash = VALUES(password_hash),
            is_active = VALUES(is_active),
            updated_at = NOW()
    ");
    $stmt->execute([
        ':email' => $adminEmail,
        ':password_hash' => $adminHash,
    ]);
    print_status('Usuario admin', true, 'inserido/atualizado');

    $stmt = $pdo->prepare('SELECT id, email, is_active, password_hash FROM admin_users WHERE email = :email LIMIT 1');
    $stmt->execute([':email' => $adminEmail]);
    $user = $stmt->fetch();

    print_status('Busca usuario admin', (bool) $user, $user ? 'id=' . $user['id'] . ', ativo=' . $user['is_active'] : '');
    print_status('Senha Marketingemersonleite*', $user && password_verify('Marketingemersonleite*', (string) $user['password_hash']));

    echo "\nResultado: diagnostico concluido. Se todos os itens estao OK, tente login com admin.\n";
} catch (Throwable $e) {
    print_status('Falha tecnica', false, $e->getMessage());
}

