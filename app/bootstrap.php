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
            'epos_card' => true,
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
CREATE TABLE IF NOT EXISTS app_meta (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_transactions_date_id ON transactions(transaction_date DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_transactions_direction_date ON transactions(direction, transaction_date);
CREATE INDEX IF NOT EXISTS idx_transactions_source_payment ON transactions(source_type, payment_date);
CREATE INDEX IF NOT EXISTS idx_transactions_dedup ON transactions(source_type, transaction_date, counterparty_key, amount, direction);
CREATE INDEX IF NOT EXISTS idx_transactions_source_counterparty ON transactions(source_type, counterparty_key);
CREATE INDEX IF NOT EXISTS idx_transactions_cancellation_match ON transactions(direction, source_type, transaction_date, counterparty_key, amount, payment_date, id);
CREATE INDEX IF NOT EXISTS idx_transactions_statement_parent ON transactions(source_type, direction, transaction_date, amount);
CREATE INDEX IF NOT EXISTS idx_transaction_links_type_parent ON transaction_links(link_type, parent_transaction_id);
CREATE INDEX IF NOT EXISTS idx_transaction_links_type_child ON transaction_links(link_type, child_transaction_id);
CREATE INDEX IF NOT EXISTS idx_transaction_tags_tag ON transaction_tags(tag_id, transaction_id);
CREATE INDEX IF NOT EXISTS idx_imports_created ON imports(created_at DESC);
SQL);
    $migrationKey = 'link_reconcile_v1';
    $stmt = $pdo->prepare('SELECT 1 FROM app_meta WHERE key = :key');
    $stmt->execute([':key' => $migrationKey]);
    if ($stmt->fetchColumn() === false) {
        reconcile_cancellation_links($pdo);
        reconcile_card_statement_links($pdo);
        $stmt = $pdo->prepare('INSERT OR REPLACE INTO app_meta(key, value) VALUES(:key, :value)');
        $stmt->execute([':key' => $migrationKey, ':value' => now_utc()]);
    }
}

function reconcile_cancellation_links(PDO $pdo): void
{
    $pdo->exec("DELETE FROM transaction_links WHERE link_type = 'cancellation'");
    $createdAt = $pdo->quote(now_utc());
    $pdo->exec("
        WITH expenses AS (
            SELECT id, source_type, transaction_date, counterparty_key, amount, COALESCE(payment_date, '') AS payment_date,
                   ROW_NUMBER() OVER (PARTITION BY source_type, transaction_date, counterparty_key, amount, COALESCE(payment_date, '') ORDER BY id) AS occurrence
            FROM transactions
            WHERE direction = 'expense'
        ),
        incomes AS (
            SELECT id, source_type, transaction_date, counterparty_key, amount, COALESCE(payment_date, '') AS payment_date,
                   ROW_NUMBER() OVER (PARTITION BY source_type, transaction_date, counterparty_key, amount, COALESCE(payment_date, '') ORDER BY id) AS occurrence
            FROM transactions
            WHERE direction = 'income'
        )
        INSERT OR IGNORE INTO transaction_links(parent_transaction_id, child_transaction_id, link_type, created_at)
        SELECT e.id, i.id, 'cancellation', $createdAt
        FROM expenses e
        JOIN incomes i
          ON i.source_type = e.source_type
         AND i.transaction_date = e.transaction_date
         AND i.counterparty_key = e.counterparty_key
         AND i.amount = e.amount
         AND i.payment_date = e.payment_date
         AND i.occurrence = e.occurrence
    ");
}

function reconcile_card_statement_links(PDO $pdo): void
{
    $pdo->exec("DELETE FROM transaction_links WHERE link_type = 'card_statement'");

    $paypayGroups = $pdo->query("
        SELECT payment_date, SUM(CASE WHEN direction = 'expense' THEN amount ELSE -amount END) AS total_amount
        FROM transactions t
        WHERE source_type = 'paypay_card' AND payment_date IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM transaction_links x WHERE x.link_type = 'cancellation' AND (x.parent_transaction_id = t.id OR x.child_transaction_id = t.id))
        GROUP BY payment_date
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($paypayGroups as $group) {
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
        if ($parentId !== false) {
            link_statement_children($pdo, (int) $parentId, 'paypay_card', 'payment_date = ?', [(string) $group['payment_date']]);
        }
    }

    $eposGroups = $pdo->query("
        SELECT substr(payment_date, 1, 7) AS payment_month, SUM(CASE WHEN direction = 'expense' THEN amount ELSE -amount END) AS total_amount
        FROM transactions t
        WHERE source_type = 'epos_card' AND payment_date IS NOT NULL
          AND NOT EXISTS (SELECT 1 FROM transaction_links x WHERE x.link_type = 'cancellation' AND (x.parent_transaction_id = t.id OR x.child_transaction_id = t.id))
        GROUP BY substr(payment_date, 1, 7)
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($eposGroups as $group) {
        $stmt = $pdo->prepare("
            SELECT id
            FROM transactions
            WHERE source_type = 'mufg_bank'
              AND substr(transaction_date, 1, 7) = :payment_month
              AND amount = :total_amount
              AND direction = 'expense'
              AND (description LIKE '%エポスカ-ド%' OR counterparty_key LIKE '%エポスカ-ド%' OR description LIKE '%EPOS%' OR counterparty_key LIKE '%EPOS%')
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->execute([
            ':payment_month' => $group['payment_month'],
            ':total_amount' => (int) $group['total_amount'],
        ]);
        $parentId = $stmt->fetchColumn();
        if ($parentId !== false) {
            link_statement_children($pdo, (int) $parentId, 'epos_card', 'substr(payment_date, 1, 7) = ?', [(string) $group['payment_month']]);
        }
    }
}

function link_statement_children(PDO $pdo, int $parentId, string $sourceType, string $paymentCondition, array $paymentParams): void
{
    $childStmt = $pdo->prepare("SELECT id FROM transactions t WHERE source_type = ? AND $paymentCondition AND NOT EXISTS (SELECT 1 FROM transaction_links x WHERE x.link_type = 'cancellation' AND (x.parent_transaction_id = t.id OR x.child_transaction_id = t.id)) ORDER BY transaction_date, id");
    $childStmt->execute([$sourceType, ...$paymentParams]);
    $insert = $pdo->prepare("
        INSERT OR IGNORE INTO transaction_links(parent_transaction_id, child_transaction_id, link_type, created_at)
        VALUES(:parent_id, :child_id, 'card_statement', :created_at)
    ");
    foreach ($childStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
        $insert->execute([
            ':parent_id' => $parentId,
            ':child_id' => (int) $childId,
            ':created_at' => now_utc(),
        ]);
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
