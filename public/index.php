<?php
declare(strict_types=1);

session_start();
require __DIR__ . '/../app/bootstrap.php';

$page = $_GET['page'] ?? 'charts';
$config = app_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action !== 'login') {
        require_login();
        verify_csrf();
    }

    if ($action === 'login') {
        if (login_with_password((string) ($_POST['password'] ?? ''))) {
            redirect('?page=charts');
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

    if ($action === 'clear_failed_imports') {
        clear_failed_imports();
        redirect('?page=upload');
    }

    if ($action === 'bulk_tag_untagged') {
        bulk_tag_untagged_expenses();
        $query = ['page' => 'untagged'];
        $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_POST['month'] ?? '')) ? (string) $_POST['month'] : '';
        $source = (string) ($_POST['source'] ?? '');
        $keyword = trim((string) ($_POST['keyword'] ?? ''));
        if ($month !== '') $query['month'] = $month;
        if (in_array($source, ['mufg_bank', 'paypay_card', 'epos_card'], true)) $query['source'] = $source;
        if ($keyword !== '') $query['keyword'] = $keyword;
        redirect('?' . http_build_query($query));
    }

    if ($action === 'save_tags') {
        $result = set_transaction_tags((int) $_POST['transaction_id'], (string) ($_POST['tags'] ?? ''), isset($_POST['batch_same_name']));
        if (is_ajax_request()) {
            json_response($result, $result['ok'] ? 200 : 422);
        }
        flash($result['message']);
        redirect('?page=transactions');
    }

    if ($action === 'create_tag' || $action === 'update_tag' || $action === 'delete_tag') {
        json_response(handle_tag_action((string) $action), 200);
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

function clear_failed_imports(): void
{
    $stmt = db()->prepare("DELETE FROM imports WHERE status = 'failed'");
    $stmt->execute();
    flash(sprintf('已清除 %d 条失败导入记录。', $stmt->rowCount()));
}

function bulk_tag_untagged_expenses(): void
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['transaction_ids'] ?? [])), static fn(int $id): bool => $id > 0)));
    $tagName = trim((string) ($_POST['tag_name'] ?? ''));
    $tagEntry = normalize_tag_entry($tagName);
    if (!$ids || $tagEntry === null) {
        flash('请选择交易并输入 Tag。');
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tagId = find_or_create_tag($pdo, $tagEntry);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $targetStmt = $pdo->prepare("
            SELECT t.id FROM transactions t
            WHERE t.id IN ($placeholders)
              AND t.direction = 'expense'
              AND NOT EXISTS (SELECT 1 FROM transaction_tags tt WHERE tt.transaction_id = t.id)
              AND NOT EXISTS (SELECT 1 FROM transaction_links l WHERE l.parent_transaction_id = t.id AND l.link_type = 'card_statement')
              AND NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = t.id OR cancel_l.child_transaction_id = t.id))
        ");
        $targetStmt->execute($ids);
        $targetIds = array_map('intval', $targetStmt->fetchAll(PDO::FETCH_COLUMN));
        $insert = $pdo->prepare("INSERT OR IGNORE INTO transaction_tags(transaction_id, tag_id, origin, created_at) VALUES(:tx, :tag, 'manual', :created)");
        foreach ($targetIds as $id) {
            $insert->execute([':tx' => $id, ':tag' => $tagId, ':created' => now_utc()]);
        }
        $pdo->commit();
        flash(sprintf('已为 %d 条未标记支出添加 Tag：%s。', count($targetIds), $tagEntry['name']));
    } catch (Throwable $error) {
        $pdo->rollBack();
        flash('批量添加 Tag 失败：' . $error->getMessage());
    }
}

function is_ajax_request(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || (string) ($_POST['ajax'] ?? '') === '1';
}

function json_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_tag_entry(string $rawName): ?array
{
    $value = trim(str_replace((string) json_decode('"\uFF0F"'), '/', $rawName));
    if ($value === '') return null;
    if (str_contains($value, '/')) {
        [$parentName, $name] = array_map('trim', explode('/', $value, 2));
        if ($name === '') return null;
        if ($parentName === '') $parentName = $name;
        return ['name' => $name, 'parent_name' => $parentName];
    }
    return ['name' => $value, 'parent_name' => $value];
}

function display_tag_name(string $rawName): string
{
    $entry = normalize_tag_entry($rawName);
    return $entry === null ? '' : $entry['name'];
}

function display_tag_names_from_list(string $tagNames): string
{
    $names = [];
    foreach (explode(',', $tagNames) as $rawName) {
        $name = display_tag_name($rawName);
        if ($name !== '') $names[$name] = $name;
    }
    return implode(', ', array_values($names));
}

function parse_tag_names(string $tagText): array
{
    $entries = [];
    foreach (preg_split('/[,\x{FF0C}\s]+/u', $tagText) ?: [] as $rawName) {
        $entry = normalize_tag_entry((string) $rawName);
        if ($entry !== null) $entries[$entry['name']] = $entry;
    }
    return array_values($entries);
}

function available_tag_names(PDO $pdo): array
{
    $names = [];
    foreach ($pdo->query('SELECT name FROM tags ORDER BY name')->fetchAll(PDO::FETCH_COLUMN) as $rawName) {
        $name = display_tag_name((string) $rawName);
        if ($name !== '') $names[$name] = $name;
    }
    natcasesort($names);
    return array_values($names);
}

function find_or_create_tag(PDO $pdo, array $tagEntry, string $defaultColor = '#3b82f6'): int
{
    $stmt = $pdo->prepare('INSERT OR IGNORE INTO tags(name, parent_name, color, created_at) VALUES(:name, :parent, :color, :created)');
    $stmt->execute([':name' => $tagEntry['name'], ':parent' => $tagEntry['parent_name'], ':color' => $defaultColor, ':created' => now_utc()]);
    $stmt = $pdo->prepare("UPDATE tags SET parent_name = :parent WHERE name = :name AND (parent_name IS NULL OR parent_name = '' OR parent_name = name)");
    $stmt->execute([':name' => $tagEntry['name'], ':parent' => $tagEntry['parent_name']]);
    return (int) $pdo->query('SELECT id FROM tags WHERE name = ' . $pdo->quote($tagEntry['name']))->fetchColumn();
}

function transaction_tag_names(PDO $pdo, array $transactionIds): array
{
    if (!$transactionIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($transactionIds), '?'));
    $stmt = $pdo->prepare("SELECT t.id, COALESCE(GROUP_CONCAT(g.name, ', '), '') AS tag_names FROM transactions t LEFT JOIN transaction_tags tt ON tt.transaction_id = t.id LEFT JOIN tags g ON g.id = tt.tag_id WHERE t.id IN ($placeholders) GROUP BY t.id");
    $stmt->execute($transactionIds);
    $result = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $result[(string) $row['id']] = display_tag_names_from_list((string) $row['tag_names']);
    }
    return $result;
}

function set_transaction_tags(int $transactionId, string $tagText, bool $batchSameName): array
{
    $pdo = db();
    $tagEntries = parse_tag_names($tagText);

    $txStmt = $pdo->prepare('SELECT id, source_type, counterparty_key FROM transactions WHERE id = :id');
    $txStmt->execute([':id' => $transactionId]);
    $transaction = $txStmt->fetch(PDO::FETCH_ASSOC);
    if (!$transaction) {
        return ['ok' => false, 'message' => '交易不存在。'];
    }

    if ($batchSameName) {
        $targetStmt = $pdo->prepare('SELECT id FROM transactions WHERE source_type = :source_type AND counterparty_key = :counterparty_key');
        $targetStmt->execute([':source_type' => $transaction['source_type'], ':counterparty_key' => $transaction['counterparty_key']]);
        $targetIds = array_map('intval', $targetStmt->fetchAll(PDO::FETCH_COLUMN));
    } else {
        $targetIds = [$transactionId];
    }

    $pdo->beginTransaction();
    $tagIds = [];
    foreach ($tagEntries as $tagEntry) {
        $tagIds[] = find_or_create_tag($pdo, $tagEntry);
    }
    $delete = $pdo->prepare('DELETE FROM transaction_tags WHERE transaction_id = :tx');
    $insert = $pdo->prepare('INSERT INTO transaction_tags(transaction_id, tag_id, origin, created_at) VALUES(:tx, :tag, :origin, :created)');
    foreach ($targetIds as $targetId) {
        $delete->execute([':tx' => $targetId]);
        foreach ($tagIds as $tagId) {
            $insert->execute([':tx' => $targetId, ':tag' => $tagId, ':origin' => 'manual', ':created' => now_utc()]);
        }
    }
    $pdo->commit();

    return [
        'ok' => true,
        'message' => sprintf('已更新 %d 条交易的标签。', count($targetIds)),
        'updated_tags' => transaction_tag_names($pdo, $targetIds),
    ];
}

function handle_tag_action(string $action): array
{
    $pdo = db();
    if ($action === 'create_tag') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $parentName = trim((string) ($_POST['parent_name'] ?? ''));
        $color = normalize_tag_color((string) ($_POST['color'] ?? '#3b82f6'));
        if ($name === '') return ['ok' => false, 'message' => 'Tag 名称不能为空。'];
        if ($parentName === '') $parentName = $name;
        try {
            $stmt = $pdo->prepare('INSERT INTO tags(name, parent_name, color, created_at) VALUES(:name, :parent, :color, :created)');
            $stmt->execute([':name' => $name, ':parent' => $parentName, ':color' => $color, ':created' => now_utc()]);
        } catch (PDOException) {
            return ['ok' => false, 'message' => 'Tag 名称已存在。'];
        }
        $tag = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'parent_name' => $parentName, 'color' => $color, 'usage_count' => 0];
        return ['ok' => true, 'message' => 'Tag 已新增。', 'tag' => $tag, 'row_html' => render_tag_management_row($tag)];
    }

    $id = (int) ($_POST['tag_id'] ?? 0);
    if ($action === 'delete_tag') {
        $stmt = $pdo->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return ['ok' => true, 'message' => 'Tag 已删除。'];
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $parentName = trim((string) ($_POST['parent_name'] ?? ''));
    $color = normalize_tag_color((string) ($_POST['color'] ?? '#3b82f6'));
    if ($name === '') return ['ok' => false, 'message' => 'Tag 名称不能为空。'];
    if ($parentName === '') $parentName = $name;
    try {
        $stmt = $pdo->prepare('UPDATE tags SET name = :name, parent_name = :parent, color = :color WHERE id = :id');
        $stmt->execute([':name' => $name, ':parent' => $parentName, ':color' => $color, ':id' => $id]);
    } catch (PDOException) {
        return ['ok' => false, 'message' => 'Tag 名称已存在。'];
    }
    return ['ok' => true, 'message' => 'Tag 已更新。'];
}
function tag_color_palette(): array
{
    return [
        '#ef4444', '#dc2626', '#f87171', '#ff3232',
        '#f97316', '#ea580c', '#fb923c', '#f59e0b',
        '#eab308', '#facc15', '#fde047', '#ca8a04',
        '#22c55e', '#16a34a', '#4ade80', '#84cc16',
        '#8dee17', '#06b6d4', '#0891b2', '#22d3ee',
        '#14b8a6', '#3b82f6', '#2563eb', '#60a5fa',
        '#0ea5e9', '#8b5cf6', '#7c3aed', '#a78bfa',
        '#d946ef', '#64748b', '#adadad', '#111827',
    ];
}

function normalize_tag_color(string $color): string
{
    $color = strtolower(trim($color));
    return in_array($color, tag_color_palette(), true) ? $color : '#3b82f6';
}

function save_settings(array $config): void
{
    $config['session_ttl_seconds'] = max(300, (int) ($_POST['session_ttl_seconds'] ?? 7200));
    $config['database_path'] = trim((string) ($_POST['database_path'] ?? DEFAULT_DB_PATH)) ?: DEFAULT_DB_PATH;
    $config['upload_dir'] = trim((string) ($_POST['upload_dir'] ?? DEFAULT_UPLOAD_DIR)) ?: DEFAULT_UPLOAD_DIR;
    $config['python_command'] = trim((string) ($_POST['python_command'] ?? 'python')) ?: 'python';
    $config['sources']['paypay_card'] = isset($_POST['source_paypay_card']);
    $config['sources']['epos_card'] = isset($_POST['source_epos_card']);
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
    $csrfToken = csrf_token();
    ?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Housekeeper</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <style>
        body { background: #f6f7f9; }
        .app-shell { width: min(100%, 1440px); margin-inline: auto; }
        @media (min-width: 992px) { .app-shell { padding-inline: 1.5rem !important; } }
        .amount-expense { color: #b42318; font-variant-numeric: tabular-nums; }
        .amount-income { color: #067647; font-variant-numeric: tabular-nums; }
        .table td, .table th { vertical-align: middle; }
        .description-subline { font-size: .82rem; }
        .child-row { background: #fbfcff; }
        .transaction-table thead th { font-size: .78rem; letter-spacing: .02em; text-transform: uppercase; color: #6c757d; }
        .transaction-table tbody tr.transaction-row { border-top: 1px solid #eef1f4; }
        .transaction-table tbody tr.transaction-row:hover { background: #fbfcfd; }
        .date-stack { min-width: 7.5rem; }
        .date-stack .date-main { font-weight: 600; font-variant-numeric: tabular-nums; }
        .amount-cell { min-width: 7.5rem; font-size: 1rem; }
        .action-cell { width: 1%; white-space: nowrap; }
        .tag-list { max-width: 18rem; }
        .tag-list .badge { font-weight: 500; }
        .tag-color-menu { min-width: auto; width: 12.5rem; }
        .tag-color-grid { display: grid; grid-template-columns: repeat(4, 2.25rem); gap: .5rem; }
        .tag-color-option { width: 2.25rem; height: 2.25rem; border: 2px solid transparent; border-radius: .25rem; }
        .tag-color-option:hover, .tag-color-option:focus { border-color: #212529; transform: scale(1.06); }
        .tag-color-option.is-selected { border-color: #212529; box-shadow: 0 0 0 2px #fff inset; }
        .tag-color-trigger { width: 3rem; height: 2.4rem; padding: .3rem; }
        .tag-color-swatch { display: block; width: 100%; height: 100%; border-radius: .2rem; border: 1px solid rgba(0,0,0,.15); }
        .import-table { table-layout: fixed; width: 100%; min-width: 960px; }
        .import-table th, .import-table td { overflow: hidden; }
        .import-cell-wrap { max-width: 100%; overflow-wrap: anywhere; word-break: break-word; }
        .import-time { white-space: nowrap; }
        .import-error { width: 100%; max-height: 5rem; overflow: auto; white-space: pre-wrap; font-size: .85rem; }
        .tag-page { --tag-page-offset: 8.25rem; }
        @media (min-width: 992px) {
            .tag-page { min-height: calc(100vh - var(--tag-page-offset)); }
            .tag-page > [class*="col-"] { min-height: 0; }
            .tag-list-card { max-height: calc(100vh - var(--tag-page-offset)); display: flex; flex-direction: column; }
            .tag-list-scroll { flex: 1 1 auto; min-height: 0; overflow: auto; }
        }
    </style>
</head>
<body>
<?php if ($page !== 'login'): ?>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container-fluid app-shell px-3 px-lg-4">
        <a class="navbar-brand fw-semibold" href="?page=charts">Housekeeper</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $page === 'charts' ? 'active' : '' ?>" href="?page=charts"><i class="bi bi-pie-chart me-1"></i>支出图表</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="?page=transactions"><i class="bi bi-receipt me-1"></i>交易</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'untagged' ? 'active' : '' ?>" href="?page=untagged"><i class="bi bi-check2-square me-1"></i>未标记</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'tags' ? 'active' : '' ?>" href="?page=tags"><i class="bi bi-tags me-1"></i>Tag 管理</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'upload' ? 'active' : '' ?>" href="?page=upload"><i class="bi bi-upload me-1"></i>上传</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><i class="bi bi-gear me-1"></i>设置</a></li>
            </ul>
            <form method="post" class="mb-0">
                <input type="hidden" name="csrf" value="<?= h($csrfToken) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn btn-outline-secondary btn-sm">退出</button>
            </form>
        </div>
    </div>
</nav>
<?php endif; ?>
<main class="container-fluid app-shell px-3 px-lg-4 py-4">
<?php if ($flash): ?><div class="alert alert-info shadow-sm"><?= h($flash) ?></div><?php endif; ?>
<?php
    if ($page === 'login') {
        render_login();
    } elseif ($page === 'upload') {
        render_upload();
    } elseif ($page === 'settings') {
        render_settings($config);
    } elseif ($page === 'tags') {
        render_tags_page();
    } elseif ($page === 'untagged') {
        render_untagged_expenses_page();
    } elseif ($page === 'charts') {
        render_charts_page();
    } else {
        render_transactions();
    }
?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
<script>
let selectedTransactionTags = [];

function renderSelectedTransactionTags() {
    const host = document.getElementById('tagCurrentTags');
    const hidden = document.getElementById('tagSelectedValues');
    if (!host || !hidden) return;
    host.replaceChildren();
    hidden.value = selectedTransactionTags.join(', ');
    if (!selectedTransactionTags.length) {
        const empty = document.createElement('span');
        empty.className = 'text-body-secondary small';
        empty.textContent = '\u65e0\u6807\u7b7e';
        host.appendChild(empty);
        return;
    }
    selectedTransactionTags.forEach(name => {
        const badge = document.createElement('span');
        badge.className = 'badge text-bg-secondary d-inline-flex align-items-center gap-2 py-2';
        const label = document.createElement('span');
        label.textContent = name;
        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'btn-close btn-close-white';
        remove.style.fontSize = '0.55rem';
        remove.setAttribute('aria-label', `\u79fb\u9664 ${name}`);
        remove.addEventListener('click', () => {
            selectedTransactionTags = selectedTransactionTags.filter(tag => tag !== name);
            renderSelectedTransactionTags();
        });
        badge.append(label, remove);
        host.appendChild(badge);
    });
}

function addSelectedTransactionTag(name) {
    const normalized = name.trim();
    if (!normalized || selectedTransactionTags.includes(normalized)) return;
    selectedTransactionTags.push(normalized);
    renderSelectedTransactionTags();
}

document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('tagModal');
    if (!modal) return;
    modal.addEventListener('show.bs.modal', (event) => {
        const button = event.relatedTarget;
        if (!button) return;
        const currentTags = button.getAttribute('data-tags') || '';
        document.getElementById('tagTransactionId').value = button.getAttribute('data-transaction-id') || '';
        document.getElementById('tagTransactionDescription').textContent = button.getAttribute('data-description') || '';
        document.getElementById('tagTransactionMeta').textContent = `${button.getAttribute('data-source') || ''} / ${button.getAttribute('data-counterparty') || ''}`;
        selectedTransactionTags = currentTags.split(',').map(name => name.trim()).filter(Boolean);
        renderSelectedTransactionTags();
        document.getElementById('tagAppendInput').value = '';
        document.getElementById('batchSameName').checked = false;
        const feedback = document.getElementById('tagModalFeedback');
        if (feedback) feedback.textContent = '';
    });
});
</script>

<script>
function showPageFeedback(message, ok = true) {
    const host = document.getElementById('tagPageFeedback');
    if (!host) return;
    const toast = document.createElement('div');
    toast.className = `toast show align-items-center text-bg-${ok ? 'success' : 'danger'} border-0 mb-2`;
    toast.innerHTML = `<div class="d-flex"><div class="toast-body"></div><button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    toast.querySelector('.toast-body').textContent = message;
    host.appendChild(toast);
    setTimeout(() => toast.remove(), 3500);
}

async function postAjax(formData) {
    formData.set('ajax', '1');
    const response = await fetch('?page=transactions', {method: 'POST', body: formData, headers: {'X-Requested-With': 'XMLHttpRequest'}});
    const data = await response.json();
    if (!response.ok || !data.ok) throw new Error(data.message || '操作失败');
    return data;
}

function renderTagNames(target, names) {
    target.replaceChildren();
    const list = names.split(',').map(name => name.trim()).filter(Boolean);
    if (!list.length) {
        const empty = document.createElement('span'); empty.className = 'text-body-secondary small'; empty.textContent = '无标签'; target.appendChild(empty); return;
    }
    list.forEach(name => { const badge = document.createElement('span'); badge.className = 'badge text-bg-secondary me-1'; badge.textContent = name; target.appendChild(badge); });
}

document.addEventListener('DOMContentLoaded', () => {
    const transactionForm = document.getElementById('transactionTagForm');
    const tagInput = document.getElementById('tagAppendInput');
    const suggestionBox = document.getElementById('tagSuggestions');
    const availableTags = (() => { try { return JSON.parse(document.getElementById('availableTagNames')?.textContent || '[]'); } catch { return []; } })();
    let activeSuggestion = -1;

    function currentTagFragment() {
        return (tagInput?.value || '').split(/[,\uFF0C\s]+/).pop().trim();
    }

    function chooseSuggestion(name) {
        addSelectedTransactionTag(name);
        tagInput.value = '';
        suggestionBox.classList.add('d-none');
        activeSuggestion = -1;
        tagInput.focus();
    }

    function renderSuggestions() {
        if (!tagInput || !suggestionBox) return;
        const fragment = currentTagFragment().toLocaleLowerCase();
        const matches = availableTags.filter(name => !selectedTransactionTags.includes(name) && (!fragment || name.toLocaleLowerCase().includes(fragment))).slice(0, 8);
        suggestionBox.replaceChildren();
        activeSuggestion = -1;
        if (!matches.length || (!fragment && !tagInput.value)) { suggestionBox.classList.add('d-none'); return; }
        matches.forEach(name => {
            const button = document.createElement('button');
            button.type = 'button'; button.className = 'list-group-item list-group-item-action'; button.textContent = name;
            button.addEventListener('mousedown', event => { event.preventDefault(); chooseSuggestion(name); });
            suggestionBox.appendChild(button);
        });
        suggestionBox.classList.remove('d-none');
    }

    tagInput?.addEventListener('input', renderSuggestions);
    tagInput?.addEventListener('focus', renderSuggestions);
    tagInput?.addEventListener('blur', () => setTimeout(() => suggestionBox?.classList.add('d-none'), 120));
    tagInput?.addEventListener('keydown', event => {
        const items = [...suggestionBox.querySelectorAll('button')];
        if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && items.length && !suggestionBox.classList.contains('d-none')) {
            event.preventDefault();
            activeSuggestion = event.key === 'ArrowDown' ? (activeSuggestion + 1) % items.length : (activeSuggestion - 1 + items.length) % items.length;
            items.forEach((item, index) => item.classList.toggle('active', index === activeSuggestion));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            if (activeSuggestion >= 0 && items[activeSuggestion]) chooseSuggestion(items[activeSuggestion].textContent);
            else {
                addSelectedTransactionTag(currentTagFragment());
                tagInput.value = '';
                suggestionBox.classList.add('d-none');
            }
        } else if (event.key === 'Escape') suggestionBox.classList.add('d-none');
    });

    transactionForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        const feedback = document.getElementById('tagModalFeedback');
        const submitButton = transactionForm.querySelector('[type="submit"]');
        if (submitButton) submitButton.disabled = true;
        try {
            addSelectedTransactionTag(currentTagFragment());
            tagInput.value = '';
            const data = await postAjax(new FormData(transactionForm));
            Object.entries(data.updated_tags || {}).forEach(([id, names]) => {
                document.querySelectorAll(`[data-tag-display="${id}"]`).forEach(node => renderTagNames(node, names));
                document.querySelectorAll(`[data-transaction-id="${id}"]`).forEach(button => button.setAttribute('data-tags', names));
            });
            bootstrap.Modal.getOrCreateInstance(document.getElementById('tagModal')).hide();
            showPageFeedback(data.message, true);
        } catch (error) {
            if (feedback) { feedback.className = 'alert alert-danger py-2 mt-3 mb-0'; feedback.textContent = error.message; }
        } finally {
            if (submitButton) submitButton.disabled = false;
        }
    });

    function setTagColorPicker(picker, color) {
        const input = picker.querySelector('input[name="color"]');
        const swatch = picker.querySelector('.tag-color-swatch');
        if (!input || !swatch) return;
        input.value = color;
        swatch.style.backgroundColor = color;
        picker.querySelectorAll('.tag-color-option').forEach(option => option.classList.toggle('is-selected', option.dataset.color === color));
    }

    document.addEventListener('click', event => {
        const option = event.target.closest('.tag-color-option');
        if (!option) return;
        setTagColorPicker(option.closest('.tag-color-picker'), option.dataset.color);
    });

    document.addEventListener('submit', async (event) => {
        const form = event.target.closest('.ajax-tag-form');
        if (!form) return;
        event.preventDefault();
        try {
            const data = await postAjax(new FormData(form));
            if (data.row_html) {
                document.getElementById('tagRows').insertAdjacentHTML('beforeend', data.row_html);
                form.reset();
                form.querySelectorAll('.tag-color-picker').forEach(picker => setTagColorPicker(picker, picker.querySelector('input[name="color"]').value));
            }
            showPageFeedback(data.message, true);
        } catch (error) { showPageFeedback(error.message, false); }
    });

    document.addEventListener('click', async (event) => {
        const button = event.target.closest('.tag-delete-button');
        if (!button || !confirm('确定删除该 Tag？交易上的该 Tag 也会移除。')) return;
        const data = new FormData(); data.set('csrf', document.querySelector('.ajax-tag-form input[name="csrf"]').value); data.set('action', 'delete_tag'); data.set('tag_id', button.dataset.tagId);
        try { const result = await postAjax(data); button.closest('tr').remove(); showPageFeedback(result.message, true); } catch (error) { showPageFeedback(error.message, false); }
    });

    document.getElementById('bulkSaveTags')?.addEventListener('click', async (event) => {
        const button = event.currentTarget;
        const forms = [...document.querySelectorAll('#tagRows .ajax-tag-form[data-action="update_tag"]')];
        if (!forms.length) return;
        button.disabled = true;
        const originalText = button.textContent;
        let saved = 0;
        try {
            for (const form of forms) {
                button.textContent = `保存中 ${saved + 1}/${forms.length}`;
                await postAjax(new FormData(form));
                saved += 1;
            }
            showPageFeedback(`已批量保存 ${saved} 个 Tag。`, true);
        } catch (error) {
            showPageFeedback(`批量保存中断：已保存 ${saved} 个，${error.message}`, false);
        } finally {
            button.disabled = false;
            button.textContent = originalText;
        }
    });

    if (window.tagExpenseChartData && document.getElementById('tagExpenseChart')) {
        const source = window.tagExpenseChartData;
        const filters = [...document.querySelectorAll('[data-tag-chart-filter]')];
        const chart = new Chart(document.getElementById('tagExpenseChart'), {
            type: 'bar',
            data: {labels: [], datasets: []},
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {mode: 'nearest', intersect: true},
                plugins: {
                    legend: {display: false},
                    tooltip: {enabled: false, external: renderChartTooltip}
                },
                scales: {
                    x: {stacked: true, grid: {display: false}},
                    y: {stacked: true, beginAtZero: true, ticks: {callback: value => Number(value).toLocaleString()}}
                }
            }
        });

        function chartColor(color, alpha) {
            const value = (color || '').trim();
            if (!/^#[0-9a-f]{6}$/i.test(value)) return color || '#adb5bd';
            const r = parseInt(value.slice(1, 3), 16);
            const g = parseInt(value.slice(3, 5), 16);
            const b = parseInt(value.slice(5, 7), 16);
            return `rgba(${r}, ${g}, ${b}, ${alpha})`;
        }

        function renderChartTooltip(context) {
            const {chart, tooltip} = context;
            let element = chart.canvas.parentNode.querySelector('.chart-tooltip');
            if (!element) {
                element = document.createElement('div');
                element.className = 'chart-tooltip position-absolute bg-dark text-white rounded shadow-sm px-3 py-2 small';
                element.style.pointerEvents = 'none';
                element.style.zIndex = '20';
                element.style.maxWidth = '18rem';
                chart.canvas.parentNode.style.position = 'relative';
                chart.canvas.parentNode.appendChild(element);
            }
            if (tooltip.opacity === 0 || !tooltip.dataPoints?.length) {
                element.style.opacity = '0';
                return;
            }
            const point = tooltip.dataPoints[0];
            const category = point.label;
            const month = point.dataset.month;
            const isCurrent = month === (source.months?.[1] || '');
            const rows = (source.segments || [])
                .filter(segment => segment.category === category)
                .map(segment => ({name: segment.subcategory, amount: Number(isCurrent ? segment.current : segment.previous || 0)}))
                .filter(row => row.amount > 0)
                .sort((a, b) => b.amount - a.amount);
            const total = rows.reduce((sum, row) => sum + row.amount, 0);
            element.innerHTML = `<div class="fw-semibold mb-1">${month} / ${category}</div>`
                + rows.map(row => `<div class="d-flex justify-content-between gap-3"><span>${row.name}</span><span>${Math.round(row.amount).toLocaleString()}</span></div>`).join('')
                + `<div class="border-top border-secondary mt-1 pt-1 d-flex justify-content-between gap-3"><span>合计</span><span>${Math.round(total).toLocaleString()}</span></div>`;
            const {offsetLeft, offsetTop} = chart.canvas;
            const parentWidth = chart.canvas.parentNode.clientWidth || chart.width;
            const parentHeight = chart.canvas.parentNode.clientHeight || chart.height;
            const x = offsetLeft + tooltip.caretX;
            const y = offsetTop + tooltip.caretY;
            element.style.opacity = '1';
            const left = Math.max(8, Math.min(x + 12, parentWidth - element.offsetWidth - 8));
            const top = Math.max(8, Math.min(y - element.offsetHeight / 2, parentHeight - element.offsetHeight - 8));
            element.style.left = `${left}px`;
            element.style.top = `${top}px`;
        }

        function updateTagExpenseChart() {
            const selected = filters.filter(filter => filter.checked).map(filter => Number(filter.dataset.tagChartFilter));
            const selectedCategories = selected.map(index => source.labels[index]);
            const total = selected.reduce((sum, index) => sum + Number(source.currentValues[index] || 0), 0);
            const previousTotal = selected.reduce((sum, index) => sum + Number(source.previousValues[index] || 0), 0);
            chart.data.labels = selectedCategories;
            chart.data.datasets = [];
            (source.segments || []).forEach(segment => {
                if (!selectedCategories.includes(segment.category)) return;
                const previousData = selectedCategories.map(category => category === segment.category ? Number(segment.previous || 0) : 0);
                const currentData = selectedCategories.map(category => category === segment.category ? Number(segment.current || 0) : 0);
                if (previousData.some(Boolean)) {
                    chart.data.datasets.push({
                        label: segment.label,
                        subcategory: segment.subcategory,
                        month: source.months?.[0] || '',
                        data: previousData,
                        backgroundColor: chartColor(segment.color, 0.45),
                        borderColor: '#fff',
                        borderWidth: 1,
                        stack: 'previous'
                    });
                }
                if (currentData.some(Boolean)) {
                    chart.data.datasets.push({
                        label: segment.label,
                        subcategory: segment.subcategory,
                        month: source.months?.[1] || '',
                        data: currentData,
                        backgroundColor: segment.color || '#adb5bd',
                        borderColor: '#fff',
                        borderWidth: 1,
                        stack: 'current'
                    });
                }
            });
            chart.update();
            document.getElementById('tagExpenseTotal').textContent = Math.round(total).toLocaleString();
            const previousTotalElement = document.getElementById('tagExpensePreviousTotal');
            if (previousTotalElement) previousTotalElement.textContent = Math.round(previousTotal).toLocaleString();
            document.querySelectorAll('[data-tag-chart-row]').forEach(row => {
                const index = Number(row.dataset.tagChartRow);
                const visible = selected.includes(index);
                row.classList.toggle('table-light', !visible);
                row.classList.toggle('text-body-secondary', !visible);
            });
        }

        filters.forEach(filter => filter.addEventListener('change', updateTagExpenseChart));
        updateTagExpenseChart();
    }
});
</script>

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
        <div class="d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center mb-3">
            <h2 class="h5 mb-0">最近导入</h2>
            <form method="post" onsubmit="return confirm('\u786e\u5b9a\u6e05\u9664\u6240\u6709\u5931\u8d25\u5bfc\u5165\u8bb0\u5f55\uff1f');">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="clear_failed_imports">
                <button type="submit" class="btn btn-outline-danger btn-sm">清除失败记录</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0 import-table">
                <colgroup><col style="width:13%"><col style="width:25%"><col style="width:11%"><col style="width:7%"><col style="width:6%"><col style="width:6%"><col style="width:32%"></colgroup>
                <thead><tr><th>时间</th><th>文件</th><th>来源</th><th>状态</th><th>新增</th><th>重复</th><th>错误</th></tr></thead>
                <tbody>
                <?php foreach ($imports as $import): ?>
                    <tr>
                        <td class="import-time"><?= h($import['created_at']) ?></td>
                        <td><div class="import-cell-wrap"><?= h($import['filename']) ?></div></td>
                        <td><div class="import-cell-wrap"><?= h(source_label((string) $import['source_type'])) ?></div></td>
                        <td><span class="badge text-bg-<?= $import['status'] === 'success' ? 'success' : ($import['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= h(status_label((string) $import['status'])) ?></span></td>
                        <td><?= h((string) $import['inserted_count']) ?></td>
                        <td><?= h((string) $import['duplicate_count']) ?></td>
                        <td><div class="import-cell-wrap import-error"><?= h($import['error_message']) ?></div></td>
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
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : '';
    $category = trim((string) ($_GET['category'] ?? ''));
    $tag = (string) ($_GET['tag'] ?? '');
    $untagged = (string) ($_GET['untagged'] ?? '') === '1';
    $direction = (string) ($_GET['direction'] ?? '');
    $direction = in_array($direction, ['expense', 'income'], true) ? $direction : '';
    $effectiveDate = "CASE WHEN t.source_type IN ('paypay_card', 'epos_card') AND t.payment_date IS NOT NULL THEN t.payment_date ELSE t.transaction_date END";
    $params = [];
    $where = [
        "NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = t.id OR cancel_l.child_transaction_id = t.id))",
    ];
    if ($tag === '' && $category === '') {
        $where[] = "t.source_type = 'mufg_bank'";
    }

    if ($direction !== '') {
        $where[] = 't.direction = :direction';
        $params[':direction'] = $direction;
    }

    if ($month !== '') {
        $where[] = "substr($effectiveDate, 1, 7) = :month";
        $params[':month'] = $month;
    }

    if ($category !== '') {
        $where[] = "EXISTS (SELECT 1 FROM transaction_tags tt_category JOIN tags g_category ON g_category.id = tt_category.tag_id WHERE tt_category.transaction_id = t.id AND COALESCE(NULLIF(g_category.parent_name, ''), g_category.name) = :category)";
        $params[':category'] = $category;
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

    $pdo = db();
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $childrenByParent = load_children_by_parent(array_map(static fn(array $row): int => (int) $row['id'], $rows));
    $tags = available_tag_names($pdo);
    $categories = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(parent_name, ''), name) AS category_name FROM tags ORDER BY category_name")->fetchAll(PDO::FETCH_COLUMN);
    $filterSummary = ($tag === '' && $category === '') ? '未选择 Tag 或大类时仅显示 MUFG 顶层交易。' : '已选择 Tag 或大类，显示所有来源的匹配交易。';
    ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center mb-3">
            <h1 class="h4 mb-0">交易</h1>
            <span class="text-body-secondary small"><?= h($filterSummary) ?></span>
        </div>
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="transactions">
            <div class="col-12 col-md-2">
                <label class="form-label">归属月份</label>
                <input type="month" name="month" class="form-control" value="<?= h($month) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">收支类型</label>
                <select name="direction" class="form-select">
                    <option value="">全部</option>
                    <option value="expense" <?= $direction === 'expense' ? 'selected' : '' ?>>支出</option>
                    <option value="income" <?= $direction === 'income' ? 'selected' : '' ?>>收入</option>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">大类</label>
                <select name="category" class="form-select">
                    <option value="">全部</option>
                    <?php foreach ($categories as $categoryName): ?><option value="<?= h((string) $categoryName) ?>" <?= $category === (string) $categoryName ? 'selected' : '' ?>><?= h((string) $categoryName) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">Tag</label>
                <select name="tag" class="form-select">
                    <option value="">全部</option>
                    <?php foreach ($tags as $name): ?><option value="<?= h($name) ?>" <?= $tag === $name ? 'selected' : '' ?>><?= h($name) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
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
<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table transaction-table mb-0 align-middle">
            <thead class="table-light"><tr><th>日期</th><th>描述</th><th>标签</th><th class="text-end">金额</th><th>余额 / 还款日</th><th class="text-end">操作</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $collapseId = 'children-' . (int) $row['id']; ?>
                <tr class="transaction-row">
                    <td class="date-stack">
                        <div class="date-main"><?= h($row['transaction_date']) ?></div>
                        <span class="badge text-bg-light border"><?= h(source_label((string) $row['source_type'])) ?></span>
                    </td>
                    <td>
                        <div class="fw-medium"><?= render_description($row) ?></div>
                        <?php if ((int) $row['child_count'] > 0): ?>
                            <button class="btn btn-link btn-sm p-0 mt-1" type="button" data-bs-toggle="collapse" data-bs-target="#<?= h($collapseId) ?>" aria-expanded="false" aria-controls="<?= h($collapseId) ?>">
                                <?= h((string) $row['child_count']) ?> 信用卡明细，合计 <?= h(number_format((int) $row['child_total'])) ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td><div class="tag-list" data-tag-display="<?= h((string) $row['id']) ?>"><?= render_tag_badges((string) $row['tag_names']) ?></div></td>
                    <td class="text-end amount-cell <?= $row['direction'] === 'expense' ? 'amount-expense' : 'amount-income' ?>"><?= $row['direction'] === 'expense' ? '-' : '+' ?><?= number_format((int) $row['amount']) ?></td>
                    <td><?= h($row['balance'] !== null ? number_format((int) $row['balance']) : $row['payment_date']) ?></td>
                    <td class="text-end action-cell"><?= render_tag_edit_button($row) ?></td>
                </tr>
                <?php if ((int) $row['child_count'] > 0): ?>
                    <tr class="child-row">
                        <td colspan="6" class="p-0 border-0">
                            <div class="collapse" id="<?= h($collapseId) ?>">
                                <div class="p-3 border-top bg-body-tertiary">
                                    <div class="table-responsive">
                                        <table class="table table-sm mb-0 align-middle">
                                            <thead><tr><th>日期</th><th>店名</th><th>标签</th><th class="text-end">金额</th><th class="text-end">操作</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($childrenByParent[(int) $row['id']] ?? [] as $child): ?>
                                                <tr>
                                                    <td class="text-body-secondary"><?= h($child['transaction_date']) ?></td>
                                                    <td><?= render_description($child) ?></td>
                                                    <td><div class="tag-list" data-tag-display="<?= h((string) $child['id']) ?>"><?= render_tag_badges((string) $child['tag_names']) ?></div></td>
                                                    <td class="text-end amount-expense">-<?= number_format((int) $child['amount']) ?></td>
                                                    <td class="text-end action-cell"><?= render_tag_edit_button($child) ?></td>
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
<?= render_tag_modal($tags) ?>
<div id="tagPageFeedback" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<?php
}


function normalize_date_filter(string $value): string
{
    $value = trim($value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : '';
}

function load_children_by_parent(array $parentIds): array
{
    $parentIds = array_values(array_unique(array_filter($parentIds)));
    if (!$parentIds) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($parentIds), '?'));
    $stmt = db()->prepare("\n        SELECT l.parent_transaction_id, c.*,\n               COALESCE((SELECT GROUP_CONCAT(g.name, ', ') FROM transaction_tags tt JOIN tags g ON g.id = tt.tag_id WHERE tt.transaction_id = c.id), '') AS tag_names\n        FROM transaction_links l\n        JOIN transactions c ON c.id = l.child_transaction_id\n        WHERE l.link_type = 'card_statement' AND l.parent_transaction_id IN ($placeholders)\n          AND NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = c.id OR cancel_l.child_transaction_id = c.id))\n        ORDER BY c.transaction_date, c.id\n    ");
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


function render_tag_edit_button(array $row): string
{
    $tags = display_tag_names_from_list((string) ($row['tag_names'] ?? ''));
    return '<button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tagModal"'
        . ' data-transaction-id="' . h((string) $row['id']) . '"'
        . ' data-description="' . h((string) $row['description']) . '"'
        . ' data-source="' . h(source_label((string) $row['source_type'])) . '"'
        . ' data-counterparty="' . h((string) $row['counterparty_key']) . '"'
        . ' data-tags="' . h($tags) . '">编辑</button>';
}

function render_tag_modal(array $tagNames): string
{
    ob_start();
    ?>
<div class="modal fade" id="tagModal" tabindex="-1" aria-labelledby="tagModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content" id="transactionTagForm">
            <div class="modal-header">
                <h2 class="modal-title fs-5" id="tagModalLabel">编辑标签</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="关闭"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                <input type="hidden" name="action" value="save_tags">
                <input type="hidden" name="transaction_id" id="tagTransactionId">
                <div class="mb-3">
                    <label class="form-label">交易</label>
                    <div class="form-control-plaintext" id="tagTransactionDescription"></div>
                    <div class="text-body-secondary small" id="tagTransactionMeta"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label">当前标签</label>
                    <div id="tagCurrentTags" class="d-flex flex-wrap gap-2"></div>
                    <input type="hidden" name="tags" id="tagSelectedValues">
                    <div class="form-text">点击标签右侧的 × 即可移除。</div>
                </div>
                <div class="mb-3 position-relative">
                    <label for="tagAppendInput" class="form-label">添加标签</label>
                    <input id="tagAppendInput" class="form-control" placeholder="输入 Tag 名称并选择，或按 Enter 新增" autocomplete="off">
                    <div id="tagSuggestions" class="list-group position-absolute start-0 end-0 shadow-sm mt-1 d-none" style="z-index:1080; max-height:220px; overflow-y:auto"></div>
                    <script type="application/json" id="availableTagNames"><?= json_encode(array_values($tagNames), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" value="1" id="batchSameName" name="batch_same_name">
                    <label class="form-check-label" for="batchSameName">同时替换完全同名交易的标签</label>
                </div>
                <div id="tagModalFeedback"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">取消</button>
                <button type="submit" class="btn btn-primary">保存标签</button>
            </div>
        </form>
    </div>
</div>
    <?php
    return (string) ob_get_clean();
}

function render_tag_badges(string $tagNames): string
{
    $names = array_filter(array_map('display_tag_name', array_map('trim', explode(',', $tagNames))));
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
        'epos_card' => 'EPOS 信用卡',
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

function untagged_expense_base_condition(string $effectiveDate): string
{
    return "t.direction = 'expense'
        AND NOT EXISTS (SELECT 1 FROM transaction_tags tt WHERE tt.transaction_id = t.id)
        AND NOT EXISTS (SELECT 1 FROM transaction_links l WHERE l.parent_transaction_id = t.id AND l.link_type = 'card_statement')
        AND NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = t.id OR cancel_l.child_transaction_id = t.id))";
}

function render_untagged_expenses_page(): void
{
    $pdo = db();
    $effectiveDate = "CASE WHEN t.source_type IN ('paypay_card', 'epos_card') AND t.payment_date IS NOT NULL THEN t.payment_date ELSE t.transaction_date END";
    $baseCondition = untagged_expense_base_condition($effectiveDate);
    $latestStmt = $pdo->query("SELECT substr($effectiveDate, 1, 7) FROM transactions t WHERE $baseCondition ORDER BY substr($effectiveDate, 1, 7) DESC LIMIT 1");
    $latestMonth = (string) ($latestStmt->fetchColumn() ?: date('Y-m'));
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : $latestMonth;
    $sourceFilter = (string) ($_GET['source'] ?? '');
    $sourceFilter = in_array($sourceFilter, ['mufg_bank', 'paypay_card', 'epos_card'], true) ? $sourceFilter : '';
    $keyword = trim((string) ($_GET['keyword'] ?? ''));
    if (strlen($keyword) > 100) $keyword = substr($keyword, 0, 100);
    $where = [$baseCondition, "substr($effectiveDate, 1, 7) = :month"];
    $params = [':month' => $month];
    if ($sourceFilter !== '') {
        $where[] = 't.source_type = :source_type';
        $params[':source_type'] = $sourceFilter;
    }
    if ($keyword !== '') {
        $where[] = '(t.description LIKE :keyword OR t.counterparty_key LIKE :keyword)';
        $params[':keyword'] = '%' . $keyword . '%';
    }

    $stmt = $pdo->prepare("
        SELECT t.*, $effectiveDate AS effective_date
        FROM transactions t
        WHERE " . implode(' AND ', $where) . "
        ORDER BY effective_date DESC, t.transaction_date DESC, t.id DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $total = array_sum(array_map(static fn(array $row): int => (int) $row['amount'], $rows));
    $tags = available_tag_names($pdo);
    ?>
<div class="card shadow-sm mb-4">
    <div class="card-body">
        <div class="d-flex flex-column flex-lg-row gap-2 justify-content-between align-items-lg-center mb-3">
            <div>
                <h1 class="h4 mb-1">未标记支出</h1>
                <div class="text-body-secondary small">信用卡按付款月份归属；可选择多条记录并批量添加一个 Tag。</div>
            </div>
            <form method="get" class="row g-2 align-items-end">
                <input type="hidden" name="page" value="untagged">
                <div class="col-6 col-md-auto"><label class="form-label small">月份</label><input type="month" name="month" class="form-control" value="<?= h($month) ?>" onchange="this.form.submit()"></div>
                <div class="col-6 col-md-auto"><label class="form-label small">来源</label><select name="source" class="form-select" onchange="this.form.submit()"><option value="">全部来源</option><?php foreach (['mufg_bank', 'paypay_card', 'epos_card'] as $sourceOption): ?><option value="<?= h($sourceOption) ?>" <?= $sourceFilter === $sourceOption ? 'selected' : '' ?>><?= h(source_label($sourceOption)) ?></option><?php endforeach; ?></select></div>
                <div class="col-12 col-md-auto"><label class="form-label small">关键词</label><input name="keyword" class="form-control" value="<?= h($keyword) ?>" placeholder="描述或店铺"></div>
                <div class="col-12 col-md-auto d-flex gap-2"><button class="btn btn-primary" type="submit">筛选</button><a class="btn btn-outline-secondary" href="?page=untagged&amp;month=<?= h($month) ?>">重置</a></div>
            </form>
        </div>
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-auto"><div class="text-body-secondary small">当前月份</div><div class="fs-5 fw-semibold"><?= h($month) ?></div></div>
            <div class="col-12 col-md-auto"><div class="text-body-secondary small">未标记笔数</div><div class="fs-5 fw-semibold"><?= h((string) count($rows)) ?></div></div>
            <div class="col-12 col-md-auto"><div class="text-body-secondary small">合计金额</div><div class="fs-5 fw-semibold"><?= number_format($total) ?></div></div>
        </div>
    </div>
</div>
<form method="post" class="card shadow-sm border-0">
    <div class="card-body border-bottom">
        <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="bulk_tag_untagged">
        <input type="hidden" name="month" value="<?= h($month) ?>">
        <input type="hidden" name="source" value="<?= h($sourceFilter) ?>">
        <input type="hidden" name="keyword" value="<?= h($keyword) ?>">
        <div class="row g-3 align-items-end">
            <div class="col-12 col-md-5 col-lg-4">
                <label class="form-label">添加 Tag</label>
                <input name="tag_name" class="form-control" list="bulkTagNames" placeholder="输入或选择一个 Tag" required>
                <datalist id="bulkTagNames"><?php foreach ($tags as $name): ?><option value="<?= h($name) ?>"></option><?php endforeach; ?></datalist>
            </div>
            <div class="col-12 col-md-auto">
                <button class="btn btn-primary" type="submit" <?= !$rows ? 'disabled' : '' ?>>批量添加</button>
            </div>
        </div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 transaction-table">
            <thead class="table-light"><tr><th style="width:3rem"><input class="form-check-input" type="checkbox" id="untaggedSelectAll"></th><th>归属日期</th><th>消费日期</th><th>来源</th><th>描述</th><th class="text-end">金额</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><input class="form-check-input untagged-row-check" type="checkbox" name="transaction_ids[]" value="<?= h((string) $row['id']) ?>"></td>
                    <td class="text-body-secondary"><?= h((string) $row['effective_date']) ?></td>
                    <td><?= h((string) $row['transaction_date']) ?></td>
                    <td><span class="badge text-bg-light border"><?= h(source_label((string) $row['source_type'])) ?></span></td>
                    <td><?= render_description($row) ?><?php if (!empty($row['payment_date'])): ?><div class="text-body-secondary description-subline">付款日：<?= h((string) $row['payment_date']) ?></div><?php endif; ?></td>
                    <td class="text-end amount-cell amount-expense">-<?= number_format((int) $row['amount']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">该月份没有未标记支出。</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</form>
<script>
document.getElementById('untaggedSelectAll')?.addEventListener('change', event => {
    document.querySelectorAll('.untagged-row-check').forEach(input => { input.checked = event.target.checked; });
});
</script>
<?php
}

function render_tags_page(): void
{
    $pdo = db();
    $tags = $pdo->query("SELECT g.id, g.name, COALESCE(NULLIF(g.parent_name, ''), g.name) AS parent_name, g.color, COUNT(tt.transaction_id) AS usage_count FROM tags g LEFT JOIN transaction_tags tt ON tt.tag_id = g.id GROUP BY g.id ORDER BY parent_name, g.name")->fetchAll(PDO::FETCH_ASSOC);
    $parentNames = $pdo->query("SELECT DISTINCT COALESCE(NULLIF(parent_name, ''), name) AS parent_name FROM tags ORDER BY parent_name")->fetchAll(PDO::FETCH_COLUMN);
    ?>
<div class="row g-4 tag-page">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h1 class="h5 mb-3">新增 Tag</h1>
                <form class="ajax-tag-form" data-action="create_tag">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_tag">
                    <div class="mb-3"><label class="form-label">大类</label><input name="parent_name" class="form-control" list="tagParentNames" placeholder="留空时使用小类名称"></div>
                    <div class="mb-3"><label class="form-label">小类名称</label><input name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">颜色</label><?= render_tag_color_picker('#3b82f6') ?></div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>新增</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0 tag-list-card">
            <div class="card-body border-bottom d-flex flex-column flex-md-row gap-2 justify-content-between align-items-md-center">
                <div><h2 class="h5 mb-0">Tag 列表</h2><span class="text-body-secondary small">交易绑定小类，图表按大类汇总</span></div>
                <button type="button" class="btn btn-primary btn-sm" id="bulkSaveTags">批量保存</button>
            </div>
            <div class="table-responsive tag-list-scroll">
                <table class="table align-middle mb-0"><thead class="table-light"><tr><th>颜色</th><th>大类</th><th>小类</th><th>使用次数</th><th class="text-end">操作</th></tr></thead><tbody id="tagRows">
                <?php foreach ($tags as $tag): ?><?= render_tag_management_row($tag) ?><?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
</div>
<datalist id="tagParentNames"><?php foreach ($parentNames as $parentName): ?><option value="<?= h((string) $parentName) ?>"></option><?php endforeach; ?></datalist>
<div id="tagPageFeedback" class="toast-container position-fixed bottom-0 end-0 p-3"></div>
<?php
}

function render_tag_color_picker(string $color, ?string $formId = null): string
{
    $color = normalize_tag_color($color);
    $formAttribute = $formId !== null ? ' form="' . h($formId) . '"' : '';
    ob_start();
    ?>
<div class="dropdown tag-color-picker">
    <input type="hidden" name="color" value="<?= h($color) ?>"<?= $formAttribute ?>>
    <button class="btn btn-outline-secondary dropdown-toggle tag-color-trigger" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="选择颜色">
        <span class="tag-color-swatch" style="background-color:<?= h($color) ?>"></span>
    </button>
    <div class="dropdown-menu tag-color-menu p-3">
        <div class="tag-color-grid">
            <?php foreach (tag_color_palette() as $option): ?>
            <button type="button" class="tag-color-option<?= $option === $color ? ' is-selected' : '' ?>" style="background-color:<?= h($option) ?>" data-color="<?= h($option) ?>" title="<?= h($option) ?>" aria-label="选择颜色 <?= h($option) ?>"></button>
            <?php endforeach; ?>
        </div>
    </div>
</div>
    <?php
    return (string) ob_get_clean();
}

function render_tag_management_row(array $tag): string
{
    $formId = 'tag-form-' . (string) $tag['id'];
    ob_start(); ?>
<tr data-tag-row="<?= h((string) $tag['id']) ?>">
    <td><?= render_tag_color_picker((string) $tag['color'], $formId) ?></td>
    <td><input form="<?= h($formId) ?>" name="parent_name" class="form-control" list="tagParentNames" value="<?= h((string) ($tag['parent_name'] ?? $tag['name'])) ?>"></td>
    <td><input form="<?= h($formId) ?>" name="name" class="form-control" value="<?= h((string) $tag['name']) ?>" required></td>
    <td><span class="badge text-bg-light border"><?= h((string) $tag['usage_count']) ?></span></td>
    <td class="text-end">
        <form id="<?= h($formId) ?>" class="ajax-tag-form d-inline" data-action="update_tag">
            <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update_tag"><input type="hidden" name="tag_id" value="<?= h((string) $tag['id']) ?>">
            <button class="btn btn-outline-primary btn-sm" type="submit">保存</button>
            <button class="btn btn-outline-danger btn-sm tag-delete-button" type="button" data-tag-id="<?= h((string) $tag['id']) ?>">删除</button>
        </form>
    </td>
</tr>
<?php return (string) ob_get_clean();
}


function render_charts_page(): void
{
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : date('Y-m');
    $previousMonth = (new DateTimeImmutable($month . '-01'))->modify('-1 month')->format('Y-m');
    $nextMonth = (new DateTimeImmutable($month . '-01'))->modify('+1 month')->format('Y-m');
    $currentRows = expense_tag_statistics($month);
    $previousRows = expense_tag_statistics($previousMonth);
    $rows = expense_category_comparison($currentRows, $previousRows);
    $segments = expense_subcategory_comparison($currentRows, $previousRows);
    $defaultVisibleRows = array_values(array_filter($rows, static fn(array $row): bool => (string) $row['category_name'] !== '提现'));
    $defaultVisibleTotal = array_sum(array_column($defaultVisibleRows, 'current_amount'));
    $defaultVisiblePreviousTotal = array_sum(array_column($defaultVisibleRows, 'previous_amount'));
    ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-end">
        <div><h1 class="h4 mb-1">大类/小类叠加月度对比</h1><div class="text-body-secondary small">每个大类显示 <?= h($previousMonth) ?> 和 <?= h($month) ?> 两根柱，柱内按小类叠加；信用卡明细按付款月份统计。</div></div>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="page" value="charts">
            <div><label class="form-label small">当前月</label><input type="month" name="month" class="form-control" value="<?= h($month) ?>" onchange="this.form.submit()"></div>
            <div class="btn-group" role="group" aria-label="月份切换">
                <a class="btn btn-outline-secondary" href="?page=charts&amp;month=<?= h($previousMonth) ?>">上月</a>
                <a class="btn btn-outline-secondary" href="?page=charts&amp;month=<?= h($nextMonth) ?>">下月</a>
            </div>
        </form>
    </div>
</div></div>
<div class="row g-4">
    <div class="col-12 col-xl-7"><div class="card shadow-sm border-0"><div class="card-body"><div style="height:420px"><canvas id="tagExpenseChart"></canvas></div></div></div></div>
    <div class="col-12 col-xl-5"><div class="card shadow-sm border-0">
        <div class="card-body border-bottom">
            <div class="row g-3">
                <div class="col"><div class="text-body-secondary small"><?= h($month) ?> 当月总支出</div><div class="fs-3 fw-semibold" id="tagExpenseTotal"><?= number_format((int) round($defaultVisibleTotal)) ?></div></div>
                <div class="col"><div class="text-body-secondary small"><?= h($previousMonth) ?> 上月总支出</div><div class="fs-3 fw-semibold text-body-secondary" id="tagExpensePreviousTotal"><?= number_format((int) round($defaultVisiblePreviousTotal)) ?></div></div>
            </div>
        </div>
        <div class="card-body pt-3">
            <div class="table-responsive">
                <table class="table table-sm mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th style="width:3rem">显示</th>
                            <th>大类</th>
                            <th class="text-end"><?= h($previousMonth) ?></th>
                            <th class="text-end"><?= h($month) ?></th>
                            <th class="text-end">增减</th>
                            <th class="text-end">增减率</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rows as $index => $row): ?>
                            <?php
                                $categoryName = (string) $row['category_name'];
                                $previousAmount = (int) round($row['previous_amount']);
                                $currentAmount = (int) round($row['current_amount']);
                                $previousHref = '?' . http_build_query(['page' => 'transactions', 'month' => $previousMonth, 'category' => $categoryName, 'direction' => 'expense']);
                                $currentHref = '?' . http_build_query(['page' => 'transactions', 'month' => $month, 'category' => $categoryName, 'direction' => 'expense']);
                                $categoryHref = $currentAmount !== 0 ? $currentHref : $previousHref;
                            ?>
                            <tr data-tag-chart-row="<?= h((string) $index) ?>" <?= $categoryName === '提现' ? 'class="table-light text-body-secondary"' : '' ?>>
                                <td><input class="form-check-input" type="checkbox" aria-label="显示 <?= h($categoryName) ?>" data-tag-chart-filter="<?= h((string) $index) ?>" <?= $categoryName !== '提现' ? 'checked' : '' ?>></td>
                                <td class="fw-semibold"><a class="link-body-emphasis text-decoration-none" href="<?= h($categoryHref) ?>"><?= h($categoryName) ?></a></td>
                                <td class="text-end">
                                    <?php if ($previousAmount !== 0): ?><a class="link-body-emphasis text-decoration-none" href="<?= h($previousHref) ?>"><?= number_format($previousAmount) ?></a><?php else: ?>0<?php endif; ?>
                                </td>
                                <td class="text-end">
                                    <?php if ($currentAmount !== 0): ?><a class="link-body-emphasis text-decoration-none" href="<?= h($currentHref) ?>"><?= number_format($currentAmount) ?></a><?php else: ?>0<?php endif; ?>
                                </td>
                                <td class="text-end <?= $row['delta'] > 0 ? 'amount-expense' : ($row['delta'] < 0 ? 'amount-income' : '') ?>"><?= $row['delta'] > 0 ? '+' : '' ?><?= number_format((int) round($row['delta'])) ?></td>
                                <td class="text-end"><?= $row['delta_rate'] === null ? '-' : (($row['delta_rate'] > 0 ? '+' : '') . number_format($row['delta_rate'], 1) . '%') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-4">没有可对比的支出数据。</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>
<script>window.tagExpenseChartData = <?= json_encode(['months' => [$previousMonth, $month], 'labels' => array_column($rows, 'category_name'), 'previousValues' => array_column($rows, 'previous_amount'), 'currentValues' => array_column($rows, 'current_amount'), 'segments' => $segments], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<?php
}

function expense_tag_statistics(string $period): array
{
    $pdo = db();
    $effectiveDate = "CASE WHEN t.source_type IN ('paypay_card', 'epos_card') AND t.payment_date IS NOT NULL THEN t.payment_date ELSE t.transaction_date END";
    $stmt = $pdo->prepare("SELECT t.id, t.amount, COALESCE(NULLIF(g.parent_name, ''), g.name, '未标记') AS category_name, COALESCE(g.name, '未标记') AS subcategory_name, COALESCE(g.color, '#adb5bd') AS color FROM transactions t LEFT JOIN transaction_tags tt ON tt.transaction_id = t.id LEFT JOIN tags g ON g.id = tt.tag_id WHERE t.direction = 'expense' AND substr($effectiveDate, 1, 7) = :period AND NOT EXISTS (SELECT 1 FROM transaction_links l WHERE l.parent_transaction_id = t.id AND l.link_type = 'card_statement') AND NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = t.id OR cancel_l.child_transaction_id = t.id)) ORDER BY t.id");
    $stmt->execute([':period' => $period]);
    $transactions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $transactions[$id]['amount'] = (int) $row['amount'];
        $transactions[$id]['tags'][] = [
            'category_name' => (string) $row['category_name'],
            'subcategory_name' => (string) $row['subcategory_name'],
            'color' => (string) $row['color'],
        ];
    }
    $totals = [];
    foreach ($transactions as $transaction) {
        $tags = $transaction['tags'] ?? [['category_name' => '未标记', 'subcategory_name' => '未标记', 'color' => '#adb5bd']];
        $share = $transaction['amount'] / count($tags);
        foreach ($tags as $tag) {
            $key = $tag['category_name'] . '||' . $tag['subcategory_name'];
            $totals[$key]['category_name'] = $tag['category_name'];
            $totals[$key]['subcategory_name'] = $tag['subcategory_name'];
            $totals[$key]['label'] = $tag['category_name'] === $tag['subcategory_name'] ? $tag['category_name'] : $tag['category_name'] . ' / ' . $tag['subcategory_name'];
            $totals[$key]['color'] = $tag['color'];
            $totals[$key]['amount'] = ($totals[$key]['amount'] ?? 0) + $share;
        }
    }
    usort($totals, static function (array $a, array $b): int {
        $categoryOrder = $a['category_name'] <=> $b['category_name'];
        if ($categoryOrder !== 0) return $categoryOrder;
        $amountOrder = $b['amount'] <=> $a['amount'];
        if ($amountOrder !== 0) return $amountOrder;
        return $a['subcategory_name'] <=> $b['subcategory_name'];
    });
    return array_values($totals);
}

function expense_subcategory_comparison(array $currentRows, array $previousRows): array
{
    $rows = [];
    foreach ($previousRows as $row) {
        $key = $row['category_name'] . '||' . $row['subcategory_name'];
        $rows[$key] = [
            'category' => $row['category_name'],
            'subcategory' => $row['subcategory_name'],
            'label' => $row['label'],
            'color' => $row['color'],
            'previous' => (float) $row['amount'],
            'current' => 0.0,
        ];
    }
    foreach ($currentRows as $row) {
        $key = $row['category_name'] . '||' . $row['subcategory_name'];
        if (!isset($rows[$key])) {
            $rows[$key] = [
                'category' => $row['category_name'],
                'subcategory' => $row['subcategory_name'],
                'label' => $row['label'],
                'color' => $row['color'],
                'previous' => 0.0,
                'current' => 0.0,
            ];
        }
        $rows[$key]['current'] = (float) $row['amount'];
        $rows[$key]['color'] = $row['color'];
    }
    usort($rows, static function (array $a, array $b): int {
        $categoryOrder = $a['category'] <=> $b['category'];
        if ($categoryOrder !== 0) return $categoryOrder;
        $amountOrder = ($b['current'] + $b['previous']) <=> ($a['current'] + $a['previous']);
        if ($amountOrder !== 0) return $amountOrder;
        return $a['subcategory'] <=> $b['subcategory'];
    });
    return array_values($rows);
}

function expense_category_comparison(array $currentRows, array $previousRows): array
{
    $current = expense_category_totals($currentRows);
    $previous = expense_category_totals($previousRows);
    $categories = array_values(array_unique(array_merge(array_keys($current), array_keys($previous))));
    sort($categories, SORT_NATURAL);
    $rows = [];
    foreach ($categories as $categoryName) {
        $currentAmount = (float) ($current[$categoryName] ?? 0);
        $previousAmount = (float) ($previous[$categoryName] ?? 0);
        $delta = $currentAmount - $previousAmount;
        $rows[] = [
            'category_name' => $categoryName,
            'current_amount' => $currentAmount,
            'previous_amount' => $previousAmount,
            'delta' => $delta,
            'delta_rate' => $previousAmount > 0 ? $delta / $previousAmount * 100 : null,
        ];
    }
    usort($rows, static fn(array $a, array $b): int => abs($b['delta']) <=> abs($a['delta']));
    return $rows;
}

function expense_category_totals(array $rows): array
{
    $totals = [];
    foreach ($rows as $row) {
        $categoryName = (string) $row['category_name'];
        $totals[$categoryName] = ($totals[$categoryName] ?? 0) + (float) $row['amount'];
    }
    return $totals;
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
                    <input class="form-check-input" id="source_epos_card" type="checkbox" name="source_epos_card" <?= !empty($config['sources']['epos_card']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="source_epos_card">EPOS 信用卡</label>
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
