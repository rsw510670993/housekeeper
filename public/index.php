<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../app/bootstrap.php';

$page = $_GET['page'] ?? 'transactions';
$config = app_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== 'login') {
        require_login();
        verify_csrf();
    }

    if ($action === 'login') {
        if (login_with_password((string) ($_POST['password'] ?? ''))) {
            redirect('?page=transactions');
        }
        flash('密码不正确。');
        redirect('?page=login');
    }

    if ($action === 'logout') {
        logout();
        redirect('?page=login');
    }

    if ($action === 'upload') {
        handle_upload($config);
        redirect('?page=upload');
    }

    if ($action === 'save_tags') {
        save_transaction_tags((int) $_POST['transaction_id'], (string) ($_POST['tags'] ?? ''));
        redirect('?page=transactions');
    }

    if ($action === 'save_settings') {
        save_settings($config);
        redirect('?page=settings');
    }
}

if ($page !== 'login') {
    require_login();
}

render_page((string) $page, $config);

function handle_upload(array $config): void
{
    if (!isset($_FILES['csv']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        flash('上传失败。');
        return;
    }

    $original = basename((string) $_FILES['csv']['name']);
    $uploadDir = (string) $config['upload_dir'];
    ensure_dir($uploadDir);
    $target = $uploadDir . '/' . date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $original);

    if (!move_uploaded_file((string) $_FILES['csv']['tmp_name'], $target)) {
        flash('保存上传文件失败。');
        return;
    }

    $command = [
        (string) $config['python_command'],
        __DIR__ . '/../scripts/import_csv.py',
        '--db',
        (string) $config['database_path'],
        '--file',
        $target,
    ];
    foreach (($config['sources'] ?? []) as $source => $enabled) {
        if ($enabled) {
            $command[] = '--allow-source';
            $command[] = (string) $source;
        }
    }
    $command[] = '--json';
    $escaped = array_map('escapeshellarg', $command);
    $output = [];
    $status = 0;
    exec(implode(' ', $escaped) . ' 2>&1', $output, $status);

    $decoded = json_decode(implode("\n", $output), true);
    if ($status !== 0 || !is_array($decoded)) {
        flash('导入失败：' . implode("
", $output));
        return;
    }

    flash(sprintf(
        '导入完成：来源 %s，新增 %d 条，重复 %d 条，已关联子项 %d 条。',
        source_label((string) ($decoded['source_type'] ?? 'unknown')),
        $decoded['inserted_count'] ?? 0,
        $decoded['duplicate_count'] ?? 0,
        $decoded['linked_count'] ?? 0
    ));
}

function save_transaction_tags(int $transactionId, string $tagText): void
{
    $pdo = db();
    $names = array_values(array_unique(array_filter(array_map(
        static fn(string $name): string => trim($name),
        preg_split('/[,?\s]+/u', $tagText) ?: []
    ))));

    $pdo->beginTransaction();
    $pdo->prepare('DELETE FROM transaction_tags WHERE transaction_id = :id')->execute([':id' => $transactionId]);
    foreach ($names as $name) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO tags(name, created_at) VALUES(:name, :created)');
        $stmt->execute([':name' => $name, ':created' => now_utc()]);
        $tagId = (int) $pdo->query('SELECT id FROM tags WHERE name = ' . $pdo->quote($name))->fetchColumn();
        $stmt = $pdo->prepare('INSERT INTO transaction_tags(transaction_id, tag_id, origin, created_at) VALUES(:tx, :tag, :origin, :created)');
        $stmt->execute([':tx' => $transactionId, ':tag' => $tagId, ':origin' => 'manual', ':created' => now_utc()]);
    }
    $pdo->commit();
    flash('标签已保存。');
}

function save_settings(array $config): void
{
    $config['session_ttl_seconds'] = max(300, (int) ($_POST['session_ttl_seconds'] ?? 7200));
    $config['database_path'] = trim((string) ($_POST['database_path'] ?? DEFAULT_DB_PATH)) ?: DEFAULT_DB_PATH;
    $config['upload_dir'] = trim((string) ($_POST['upload_dir'] ?? DEFAULT_UPLOAD_DIR)) ?: DEFAULT_UPLOAD_DIR;
    $config['python_command'] = trim((string) ($_POST['python_command'] ?? 'python')) ?: 'python';
    $config['sources']['paypay_card'] = isset($_POST['source_paypay_card']);
    $config['sources']['mufg_bank'] = isset($_POST['source_mufg_bank']);

    $newPassword = (string) ($_POST['new_password'] ?? '');
    if ($newPassword !== '') {
        $config['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
    }
    save_config($config);
    flash('设置已保存。');
}

function render_page(string $page, array $config): void
{
    $flash = flash();
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Housekeeper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #f6f7f9; }
        .amount-expense { color: #b42318; font-variant-numeric: tabular-nums; }
        .amount-income { color: #067647; font-variant-numeric: tabular-nums; }
        .table td, .table th { vertical-align: middle; }
        .description-subline { font-size: .82rem; }
        .child-row { background: #fbfcff; }
        .tag-input { min-width: 13rem; }
    </style>
</head>
<body>
<?php if ($page !== 'login'): ?>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold" href="?page=transactions">Housekeeper</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="?page=transactions">交易</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'upload' ? 'active' : '' ?>" href="?page=upload">上传</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings">设置</a></li>
            </ul>
            <form method="post" class="mb-0">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-outline-secondary btn-sm">退出</button>
            </form>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container-fluid px-3 px-lg-4 py-4">
<?php if ($flash): ?><div class="alert alert-info shadow-sm"><?= h($flash) ?></div><?php endif; ?>
<?php
    if ($page === 'login') {
        render_login();
    } elseif ($page === 'upload') {
        render_upload();
    } elseif ($page === 'settings') {
        render_settings($config);
    } else {
        render_transactions();
    }
?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

function render_login(): void
{
    ?>
<div class="row justify-content-center mt-5">
    <div class="col-12 col-sm-8 col-md-5 col-lg-4">
        <div class="card shadow-sm">
            <div class="card-body p-4">
                <h1 class="h4 mb-3">Housekeeper</h1>
                <form method="post">
                    <input type="hidden" name="action" value="login">
                    <div class="mb-3">
                        <label for="password" class="form-label">管理员密码</label>
                        <input id="password" name="password" type="password" class="form-control" required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">登录</button>
                    <p class="text-body-secondary small mt-3 mb-0">首次默认密码：admin123</p>
                </form>
            </div>
        </div>
    </div>
</div>
<?php
}

function render_upload(): void
{
    $imports = db()->query('SELECT * FROM imports ORDER BY id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
    ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <h1 class="h4 mb-3">上传 CSV</h1>
        <form method="post" enctype="multipart/form-data" class="row g-3 align-items-end">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="upload">
            <div class="col-12 col-md-8 col-lg-6">
                <label for="csv" class="form-label">CSV 文件</label>
                <input id="csv" name="csv" type="file" class="form-control" accept=".csv,text/csv" required>
            </div>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary">导入</button>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm">
    <div class="card-body">
        <h2 class="h5 mb-3">最近导入</h2>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead><tr><th>时间</th><th>文件</th><th>来源</th><th>状态</th><th>新增</th><th>重复</th><th>错误</th></tr></thead>
                <tbody>
                <?php foreach ($imports as $import): ?>
                    <tr>
                        <td><?= h($import['created_at']) ?></td>
                        <td><?= h($import['filename']) ?></td>
                        <td><?= h(source_label((string) $import['source_type'])) ?></td>
                        <td><span class="badge text-bg-<?= $import['status'] === 'success' ? 'success' : ($import['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h(status_label((string) $import['status'])) ?></span></td>
                        <td><?= h((string) $import['inserted_count']) ?></td>
                        <td><?= h((string) $import['duplicate_count']) ?></td>
                        <td><?= h($import['error_message']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
}

function render_transactions(): void
{
    $source = (string) ($_GET['source'] ?? '');
    $tag = (string) ($_GET['tag'] ?? '');
    $untagged = (string) ($_GET['untagged'] ?? '') === '1';
    $params = [];
    $where = ["NOT EXISTS (SELECT 1 FROM transaction_links hidden_l WHERE hidden_l.child_transaction_id = t.id AND hidden_l.link_type = 'card_statement')"];

    if ($source !== '') {
        $where[] = 't.source_type = :source';
        $params[':source'] = $source;
    }
    if ($tag !== '') {
        $where[] = 'EXISTS (SELECT 1 FROM transaction_tags tt_filter JOIN tags g_filter ON g_filter.id = tt_filter.tag_id WHERE tt_filter.transaction_id = t.id AND g_filter.name = :tag)';
        $params[':tag'] = $tag;
    }
    if ($untagged) {
        $where[] = 'NOT EXISTS (SELECT 1 FROM transaction_tags tt_missing WHERE tt_missing.transaction_id = t.id)';
    }

    $sql = 'SELECT t.*,
            COALESCE((SELECT GROUP_CONCAT(g.name, ", ") FROM transaction_tags tt JOIN tags g ON g.id = tt.tag_id WHERE tt.transaction_id = t.id), "") AS tag_names,
            COALESCE((SELECT COUNT(*) FROM transaction_links l WHERE l.parent_transaction_id = t.id AND l.link_type = "card_statement"), 0) AS child_count,
            COALESCE((SELECT SUM(c.amount) FROM transaction_links l JOIN transactions c ON c.id = l.child_transaction_id WHERE l.parent_transaction_id = t.id AND l.link_type = "card_statement"), 0) AS child_total
            FROM transactions t
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY t.transaction_date DESC, t.id DESC LIMIT 300';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $childrenByParent = load_children_by_parent(array_map(static fn(array $row): int => (int) $row['id'], $rows));
    $tags = db()->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN);
    ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center mb-3">
            <h1 class="h4 mb-0">交易</h1>
            <span class="text-body-secondary small">已匹配的 PayPay 明细会显示在对应的 MUFG 储蓄卡扣款下。</span>
        </div>
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="transactions">
            <div class="col-12 col-md-3">
                <label class="form-label">来源</label>
                <select name="source" class="form-select">
                    <option value="">全部</option>
                    <option value="paypay_card" <?= $source === 'paypay_card' ? 'selected' : '' ?>>PayPay</option>
                    <option value="mufg_bank" <?= $source === 'mufg_bank' ? 'selected' : '' ?>>MUFG</option>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">Tag</label>
                <select name="tag" class="form-select">
                    <option value="">全部</option>
                    <?php foreach ($tags as $name): ?><option value="<?= h($name) ?>" <?= $tag === $name ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-3">
                <label class="form-label">未标记</label>
                <select name="untagged" class="form-select">
                    <option value="">否</option>
                    <option value="1" <?= $untagged ? 'selected' : '' ?>>是</option>
                </select>
            </div>
            <div class="col-12 col-md-auto">
                <button type="submit" class="btn btn-primary">筛选</button>
            </div>
        </form>
    </div>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0 align-middle">
            <thead class="table-light"><tr><th>日期</th><th>来源</th><th>描述</th><th class="text-end">金额</th><th>余额 / 还款日</th><th>标签</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $collapseId = 'children-' . (int) $row['id']; ?>
                <tr>
                    <td><?= h($row['transaction_date']) ?></td>
                    <td><span class="badge text-bg-light border"><?= h(source_label((string) $row['source_type'])) ?></span></td>
                    <td>
                        <?= render_description($row) ?>
                        <?php if ((int) $row['child_count'] > 0): ?>
                            <div class="mt-1">
                                <button class="btn btn-link btn-sm p-0" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>" aria-expanded="false" aria-controls="<?= h($collapseId) ?>">
                                    <?= h((string) $row['child_count']) ?> PayPay 明细，合计 <?= h(number_format((int) $row['child_total'])) ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end <?= $row['direction'] === 'expense' ? 'amount-expense' : 'amount-income' ?>"><?= $row['direction'] === 'expense' ? '-' : '+' ?><?= number_format((int) $row['amount']) ?></td>
                    <td><?= h($row['balance'] !== null ? number_format((int) $row['balance']) : $row['payment_date']) ?></td>
                    <td>
                        <form method="post" class="d-flex gap-2 align-items-center">
                            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                            <input type="hidden" name="action" value="save_tags">
                            <input type="hidden" name="transaction_id" value="<?= h((string) $row['id']) ?>">
                            <input name="tags" class="form-control form-control-sm tag-input" value="<?= h($row['tag_names']) ?>" placeholder="tag1, tag2">
                            <button type="submit" class="btn btn-outline-primary btn-sm">保存</button>
                        </form>
                    </td>
                </tr>
                <?php if ((int) $row['child_count'] > 0): ?>
                    <tr class="child-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collapse" id="<?= h($collapseId) ?>">
                                <div class="p-3 border-top">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0">
                                            <thead><tr><th>日期</th><th>店名</th><th class="text-end">金额</th><th>标签</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($childrenByParent[(int) $row['id']] ?? [] as $child): ?>
                                                <tr>
                                                    <td><?= h($child['transaction_date']) ?></td>
                                                    <td><?= render_description($child) ?></td>
                                                    <td class="text-end amount-expense">-<?= number_format((int) $child['amount']) ?></td>
                                                    <td><?= render_tag_badges((string) $child['tag_names']) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
}

function load_children_by_parent(array $parentIds): array
{
    $parentIds = array_values(array_unique(array_filter($parentIds)));
    if (!$parentIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
    $stmt = db()->prepare("\n        SELECT l.parent_transaction_id, c.*,\n               COALESCE((SELECT GROUP_CONCAT(g.name, ', ') FROM transaction_tags tt JOIN tags g ON g.id = tt.tag_id WHERE tt.transaction_id = c.id), '') AS tag_names\n        FROM transaction_links l\n        JOIN transactions c ON c.id = l.child_transaction_id\n        WHERE l.link_type = 'card_statement' AND l.parent_transaction_id IN ($placeholders)\n        ORDER BY c.transaction_date, c.id\n    ");
    $stmt->execute($parentIds);
    $children = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $children[(int) $row['parent_transaction_id']][] = $row;
    }
    return $children;
}

function render_description(array $row): string
{
    $description = (string) $row['description'];
    $counterparty = (string) $row['counterparty_key'];
    $html = h($description);
    if ($counterparty !== '' && $counterparty !== $description) {
        $html .= '<div class="text-body-secondary description-subline">' . h($counterparty) . '</div>';
    }
    return $html;
}

function render_tag_badges(string $tagNames): string
{
    $names = array_filter(array_map('trim', explode(',', $tagNames)));
    if (!$names) {
        return '<span class="text-body-secondary small">无标签</span>';
    }
    $html = '';
    foreach ($names as $name) {
        $html .= '<span class="badge text-bg-secondary me-1">' . h($name) . '</span>';
    }
    return $html;
}


function source_label(string $source): string
{
    return match ($source) {
        'paypay_card' => 'PayPay 信用卡',
        'mufg_bank' => 'MUFG 储蓄卡',
        default => $source,
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'success' => '成功',
        'failed' => '失败',
        'running' => '处理中',
        default => $status,
    };
}

function render_settings(array $config): void
{
    ?>
<div class="card shadow-sm">
    <div class="card-body">
        <h1 class="h4 mb-3">设置</h1>
        <form method="post" class="row g-3">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label">新密码</label>
                <input name="new_password" type="password" class="form-control" autocomplete="new-password" placeholder="留空则不修改">
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label">登录有效秒数</label>
                <input name="session_ttl_seconds" type="number" min="300" class="form-control" value="<?= h((string) $config['session_ttl_seconds']) ?>">
            </div>
            <div class="col-12 col-lg-6">
                <label class="form-label">SQLite 路径</label>
                <input name="database_path" class="form-control" value="<?= h((string) $config['database_path']) ?>">
            </div>
            <div class="col-12 col-lg-6">
                <label class="form-label">上传目录</label>
                <input name="upload_dir" class="form-control" value="<?= h((string) $config['upload_dir']) ?>">
            </div>
            <div class="col-12 col-md-6 col-lg-4">
                <label class="form-label">Python 命令</label>
                <input name="python_command" class="form-control" value="<?= h((string) $config['python_command']) ?>">
            </div>
            <div class="col-12">
                <div class="form-check form-check-inline">
                    <input class="form-check-input" id="source_paypay_card" type="checkbox" name="source_paypay_card" <?= !empty($config['sources']['paypay_card']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="source_paypay_card">PayPay 信用卡</label>
                </div>
                <div class="form-check form-check-inline">
                    <input class="form-check-input" id="source_mufg_bank" type="checkbox" name="source_mufg_bank" <?= !empty($config['sources']['mufg_bank']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="source_mufg_bank">MUFG 储蓄卡</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">保存设置</button>
            </div>
        </form>
    </div>
</div>
<?php
}
