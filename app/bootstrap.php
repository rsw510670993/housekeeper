<?php
declare(strict_types=1);

const APP_ROOT = __DIR__ . '/..';
const DATA_DIR = APP_ROOT . '/data';
const DEFAULT_DB_PATH = DATA_DIR . '/housekeeper.sqlite';
const DEFAULT_UPLOAD_DIR = DATA_DIR . '/uploads';
const CONFIG_PATH = DATA_DIR . '/config.json';

function ensure_dir(string $path): void
{
    if (!is_dir($path)) {
        mkdir($path, 0775, true);
    }
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

function default_config(): array
{
    return [
        'password_hash' => password_hash('admin123', PASSWORD_DEFAULT),
        'session_ttl_seconds' => 7200,
        'database_path' => DEFAULT_DB_PATH,
        'upload_dir' => DEFAULT_UPLOAD_DIR,
        'python_command' => 'python',
        'sources' => [
            'paypay_card' => true,
            'mufg_bank' => true,
        ],
    ];
}

function app_config(): array
{
    ensure_dir(DATA_DIR);
    if (!file_exists(CONFIG_PATH)) {
        file_put_contents(
            CONFIG_PATH,
            json_encode(default_config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
    }

    $config = json_decode((string) file_get_contents(CONFIG_PATH), true);
    if (!is_array($config)) {
        throw new RuntimeException('Invalid config.json');
    }

    return array_replace_recursive(default_config(), $config);
}

function save_config(array $config): void
{
    ensure_dir(DATA_DIR);
    file_put_contents(
        CONFIG_PATH,
        json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = app_config();
    ensure_dir(dirname((string) $config['database_path']));
    $pdo = new PDO('sqlite:' . $config['database_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON');
    migrate($pdo);

    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS imports (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    filename TEXT NOT NULL,
    source_type TEXT,
    status TEXT NOT NULL,
    inserted_count INTEGER NOT NULL DEFAULT 0,
    duplicate_count INTEGER NOT NULL DEFAULT 0,
    error_message TEXT,
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type TEXT NOT NULL,
    transaction_date TEXT NOT NULL,
    description TEXT NOT NULL,
    counterparty_key TEXT NOT NULL,
    amount INTEGER NOT NULL,
    direction TEXT NOT NULL CHECK(direction IN ('expense', 'income')),
    balance INTEGER,
    payment_date TEXT,
    raw_json TEXT NOT NULL,
    unique_key TEXT NOT NULL,
    import_id INTEGER,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(source_type, unique_key),
    FOREIGN KEY(import_id) REFERENCES imports(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    color TEXT NOT NULL DEFAULT '#3b82f6',
    created_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS transaction_tags (
    transaction_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    origin TEXT NOT NULL CHECK(origin IN ('manual', 'auto')),
    created_at TEXT NOT NULL,
    PRIMARY KEY(transaction_id, tag_id),
    FOREIGN KEY(transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY(tag_id) REFERENCES tags(id) ON DELETE CASCADE
);
CREATE TABLE IF NOT EXISTS transaction_links (
    parent_transaction_id INTEGER NOT NULL,
    child_transaction_id INTEGER NOT NULL,
    link_type TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY(parent_transaction_id, child_transaction_id, link_type),
    FOREIGN KEY(parent_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE,
    FOREIGN KEY(child_transaction_id) REFERENCES transactions(id) ON DELETE CASCADE
);
SQL);
    reconcile_card_statement_links($pdo);
}

function reconcile_card_statement_links(PDO $pdo): void
{
    $pdo->exec("DELETE FROM transaction_links WHERE link_type = 'card_statement'");
    $groups = $pdo->query("
        SELECT payment_date, SUM(amount) AS total_amount
        FROM transactions
        WHERE source_type = 'paypay_card' AND payment_date IS NOT NULL
        GROUP BY payment_date
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as $group) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM transactions
            WHERE source_type = 'mufg_bank'
              AND transaction_date = :payment_date
              AND amount = :total_amount
              AND direction = 'expense'
              AND (description LIKE '%PAYPAY%' OR counterparty_key LIKE '%PAYPAY%')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':payment_date' => $group['payment_date'],
            ':total_amount' => (int) $group['total_amount'],
        ]);
        $parentId = $stmt->fetchColumn();
        if ($parentId === false) {
            continue;
        }

        $childStmt = $pdo->prepare("
            SELECT id
            FROM transactions
            WHERE source_type = 'paypay_card' AND payment_date = :payment_date
            ORDER BY transaction_date, id
        ");
        $childStmt->execute([':payment_date' => $group['payment_date']]);
        $insert = $pdo->prepare("
            INSERT OR IGNORE INTO transaction_links(parent_transaction_id, child_transaction_id, link_type, created_at)
            VALUES(:parent_id, :child_id, 'card_statement', :created_at)
        ");
        foreach ($childStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            $insert->execute([
                ':parent_id' => (int) $parentId,
                ':child_id' => (int) $childId,
                ':created_at' => now_utc(),
            ]);
        }
    }
}

function current_session(): ?array
{
    $token = $_COOKIE['hk_token'] ?? '';
    if (!is_string($token) || $token === '') {
        return null;
    }

    $hash = hash('sha256', $token);
    $stmt = db()->prepare('SELECT * FROM sessions WHERE token_hash = :hash AND expires_at > :now');
    $stmt->execute([':hash' => $hash, ':now' => now_utc()]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);

    return $session ?: null;
}

function require_login(): void
{
    if (current_session() === null) {
        redirect('?page=login');
    }
}

function login_with_password(string $password): bool
{
    $config = app_config();
    if (!password_verify($password, (string) $config['password_hash'])) {
        return false;
    }

    $token = bin2hex(random_bytes(32));
    $expiresAt = gmdate('Y-m-d H:i:s', time() + (int) $config['session_ttl_seconds']);
    $stmt = db()->prepare('INSERT INTO sessions(token_hash, expires_at, created_at) VALUES(:hash, :expires, :created)');
    $stmt->execute([
        ':hash' => hash('sha256', $token),
        ':expires' => $expiresAt,
        ':created' => now_utc(),
    ]);
    setcookie('hk_token', $token, [
        'expires' => time() + (int) $config['session_ttl_seconds'],
        'path' => '/',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    return true;
}

function logout(): void
{
    $token = $_COOKIE['hk_token'] ?? '';
    if (is_string($token) && $token !== '') {
        $stmt = db()->prepare('DELETE FROM sessions WHERE token_hash = :hash');
        $stmt->execute([':hash' => hash('sha256', $token)]);
    }
    setcookie('hk_token', '', ['expires' => time() - 3600, 'path' => '/']);
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_COOKIE['hk_csrf'])) {
        $token = bin2hex(random_bytes(16));
        setcookie('hk_csrf', $token, ['path' => '/', 'httponly' => true, 'samesite' => 'Strict']);
        $_COOKIE['hk_csrf'] = $token;
    }

    return (string) $_COOKIE['hk_csrf'];
}

function verify_csrf(): void
{
    $posted = $_POST['csrf'] ?? '';
    if (!is_string($posted) || !hash_equals(csrf_token(), $posted)) {
        http_response_code(400);
        exit('Invalid CSRF token');
    }
}

function flash(?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'] = $message;
        return null;
    }
    $current = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return is_string($current) ? $current : null;
}
