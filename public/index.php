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

function parse_tag_names(string $tagText): array
{
    return array_values(array_unique(array_filter(array_map(
        static fn(string $name): string => trim($name),
        preg_split('/[,\x{FF0C}\s]+/u', $tagText) ?: []
    ))));
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
        $result[(string) $row['id']] = (string) $row['tag_names'];
    }
    return $result;
}

function set_transaction_tags(int $transactionId, string $tagText, bool $batchSameName): array
{
    $pdo = db();
    $names = parse_tag_names($tagText);

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
    foreach ($names as $name) {
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO tags(name, created_at) VALUES(:name, :created)');
        $stmt->execute([':name' => $name, ':created' => now_utc()]);
        $tagIds[] = (int) $pdo->query('SELECT id FROM tags WHERE name = ' . $pdo->quote($name))->fetchColumn();
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
        $color = normalize_tag_color((string) ($_POST['color'] ?? '#3b82f6'));
        if ($name === '') return ['ok' => false, 'message' => 'Tag 名称不能为空。'];
        try {
            $stmt = $pdo->prepare('INSERT INTO tags(name, color, created_at) VALUES(:name, :color, :created)');
            $stmt->execute([':name' => $name, ':color' => $color, ':created' => now_utc()]);
        } catch (PDOException) {
            return ['ok' => false, 'message' => 'Tag 名称已存在。'];
        }
        $tag = ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'color' => $color, 'usage_count' => 0];
        return ['ok' => true, 'message' => 'Tag 已新增。', 'tag' => $tag, 'row_html' => render_tag_management_row($tag)];
    }

    $id = (int) ($_POST['tag_id'] ?? 0);
    if ($action === 'delete_tag') {
        $stmt = $pdo->prepare('DELETE FROM tags WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return ['ok' => true, 'message' => 'Tag 已删除。'];
    }

    $name = trim((string) ($_POST['name'] ?? ''));
    $color = normalize_tag_color((string) ($_POST['color'] ?? '#3b82f6'));
    if ($name === '') return ['ok' => false, 'message' => 'Tag 名称不能为空。'];
    try {
        $stmt = $pdo->prepare('UPDATE tags SET name = :name, color = :color WHERE id = :id');
        $stmt->execute([':name' => $name, ':color' => $color, ':id' => $id]);
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
    </style>
</head>
<body>
<?php if ($page !== 'login'): ?>
<nav class="navbar navbar-expand-lg bg-white border-bottom sticky-top">
    <div class="container-fluid px-3 px-lg-4">
        <a class="navbar-brand fw-semibold" href="?page=charts">Housekeeper</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item"><a class="nav-link <?= $page === 'charts' ? 'active' : '' ?>" href="?page=charts"><i class="bi bi-pie-chart me-1"></i>支出图表</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="?page=transactions"><i class="bi bi-receipt me-1"></i>交易</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'tags' ? 'active' : '' ?>" href="?page=tags"><i class="bi bi-tags me-1"></i>Tag 管理</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'upload' ? 'active' : '' ?>" href="?page=upload"><i class="bi bi-upload me-1"></i>上传</a></li>
                <li class="nav-item"><a class="nav-link <?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings"><i class="bi bi-gear me-1"></i>设置</a></li>
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
    } elseif ($page === 'tags') {
        render_tags_page();
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

    if (window.tagExpenseChartData && document.getElementById('tagExpenseChart')) {
        const source = window.tagExpenseChartData;
        const filters = [...document.querySelectorAll('[data-tag-chart-filter]')];
        const chart = new Chart(document.getElementById('tagExpenseChart'), {
            type: 'doughnut',
            data: {labels: [], datasets: [{data: [], backgroundColor: [], borderWidth: 2, borderColor: '#fff'}]},
            options: {responsive: true, maintainAspectRatio: false, cutout: '58%', plugins: {legend: {display: false}, tooltip: {callbacks: {label: context => { const total = context.dataset.data.reduce((a, b) => a + b, 0); return `${context.label}: ${Math.round(context.raw).toLocaleString()} (${total ? (context.raw / total * 100).toFixed(1) : 0}%)`; }}}}}
        });

        function updateTagExpenseChart() {
            const selected = filters.filter(filter => filter.checked).map(filter => Number(filter.dataset.tagChartFilter));
            const total = selected.reduce((sum, index) => sum + Number(source.values[index] || 0), 0);
            chart.data.labels = selected.map(index => source.labels[index]);
            chart.data.datasets[0].data = selected.map(index => source.values[index]);
            chart.data.datasets[0].backgroundColor = selected.map(index => source.colors[index]);
            chart.update();
            document.getElementById('tagExpenseTotal').textContent = Math.round(total).toLocaleString();
            document.querySelectorAll('[data-tag-chart-row]').forEach(row => {
                const index = Number(row.dataset.tagChartRow);
                const visible = selected.includes(index);
                row.classList.toggle('d-none', !visible);
                const percent = row.querySelector('[data-tag-chart-percent]');
                if (percent) percent.textContent = `${total ? (Number(source.values[index]) / total * 100).toFixed(1) : '0.0'}%`;
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
    $dateFrom = normalize_date_filter((string) ($_GET['date_from'] ?? ''));
    $dateTo = normalize_date_filter((string) ($_GET['date_to'] ?? ''));
    $tag = (string) ($_GET['tag'] ?? '');
    $untagged = (string) ($_GET['untagged'] ?? '') === '1';
    $direction = (string) ($_GET['direction'] ?? '');
    $direction = in_array($direction, ['expense', 'income'], true) ? $direction : '';
    $params = [];
    $where = [
        "NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = t.id OR cancel_l.child_transaction_id = t.id))",
    ];
    if ($tag === '') {
        $where[] = "t.source_type = 'mufg_bank'";
    }

    if ($direction !== '') {
        $where[] = 't.direction = :direction';
        $params[':direction'] = $direction;
    }

    if ($dateFrom !== '') {
        $where[] = 't.transaction_date >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 't.transaction_date <= :date_to';
        $params[':date_to'] = $dateTo;
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
            <span class="text-body-secondary small"><?= $tag === '' ? '未选择 Tag 时仅显示 MUFG 顶层交易。' : '已选择 Tag，显示所有来源的匹配交易。' ?></span>
        </div>
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="transactions">
            <div class="col-12 col-md-2">
                <label class="form-label">开始日期</label>
                <input type="date" name="date_from" class="form-control" value="<?= h($dateFrom) ?>">
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label">结束日期</label>
                <input type="date" name="date_to" class="form-control" value="<?= h($dateTo) ?>">
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
    $tags = (string) ($row['tag_names'] ?? '');
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

function render_tags_page(): void
{
    $tags = db()->query("SELECT g.id, g.name, g.color, COUNT(tt.transaction_id) AS usage_count FROM tags g LEFT JOIN transaction_tags tt ON tt.tag_id = g.id GROUP BY g.id ORDER BY g.name")->fetchAll(PDO::FETCH_ASSOC);
    ?>
<div class="row g-4">
    <div class="col-12 col-lg-4">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h1 class="h5 mb-3">新增 Tag</h1>
                <form class="ajax-tag-form" data-action="create_tag">
                    <input type="hidden" name="csrf" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="action" value="create_tag">
                    <div class="mb-3"><label class="form-label">名称</label><input name="name" class="form-control" required></div>
                    <div class="mb-3"><label class="form-label">颜色</label><?= render_tag_color_picker('#3b82f6') ?></div>
                    <button class="btn btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>新增</button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-12 col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body border-bottom d-flex justify-content-between align-items-center">
                <h2 class="h5 mb-0">Tag 列表</h2><span class="text-body-secondary small">平面标签，无层级关系</span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0"><thead class="table-light"><tr><th>颜色</th><th>名称</th><th>使用次数</th><th class="text-end">操作</th></tr></thead><tbody id="tagRows">
                <?php foreach ($tags as $tag): ?><?= render_tag_management_row($tag) ?><?php endforeach; ?>
                </tbody></table>
            </div>
        </div>
    </div>
</div>
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
    $pdo = db();
    $latestDate = (string) ($pdo->query("SELECT MAX(transaction_date) FROM transactions WHERE direction = 'expense'")->fetchColumn() ?: date('Y-m-d'));
    $scope = (string) ($_GET['scope'] ?? 'month');
    $scope = in_array($scope, ['month', 'year'], true) ? $scope : 'month';
    $month = preg_match('/^\\d{4}-\\d{2}$/', (string) ($_GET['month'] ?? '')) ? (string) $_GET['month'] : substr($latestDate, 0, 7);
    $year = preg_match('/^\\d{4}$/', (string) ($_GET['year'] ?? '')) ? (string) $_GET['year'] : substr($latestDate, 0, 4);
    $period = $scope === 'year' ? $year : $month;
    $rows = expense_tag_statistics($period, $scope);
    $defaultVisibleRows = array_values(array_filter($rows, static fn(array $row): bool => (string) $row['name'] !== '提现'));
    $defaultVisibleTotal = array_sum(array_column($defaultVisibleRows, 'amount'));
    ?>
<div class="card shadow-sm border-0 mb-4"><div class="card-body">
    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-end">
        <div><h1 class="h4 mb-1">Tag 支出比例</h1><div class="text-body-secondary small">信用卡明细按付款月份统计；多 Tag 交易平均分摊；信用卡总扣款不重复计算。</div></div>
        <form method="get" class="d-flex flex-wrap gap-2 align-items-end"><input type="hidden" name="page" value="charts">
            <div><label class="form-label small">统计范围</label><select name="scope" class="form-select"><option value="month" <?= $scope === 'month' ? 'selected' : '' ?>>月份</option><option value="year" <?= $scope === 'year' ? 'selected' : '' ?>>全年</option></select></div>
            <div><label class="form-label small">月份</label><input type="month" name="month" class="form-control" value="<?= h($month) ?>"></div>
            <div><label class="form-label small">年份</label><input type="number" name="year" class="form-control" min="2000" max="2100" value="<?= h($year) ?>"></div>
            <button class="btn btn-primary" type="submit">查看</button>
        </form>
    </div>
</div></div>
<div class="card shadow-sm border-0 mb-4"><div class="card-body py-3">
    <div class="d-flex flex-wrap gap-3 align-items-center" id="tagExpenseFilters">
        <span class="small fw-semibold text-body-secondary">显示分类</span>
        <?php foreach ($rows as $index => $row): ?>
        <div class="form-check form-check-inline m-0">
            <input class="form-check-input" type="checkbox" id="tag-filter-<?= h((string) $index) ?>" data-tag-chart-filter="<?= h((string) $index) ?>" <?= (string) $row['name'] !== '提现' ? 'checked' : '' ?>>
            <label class="form-check-label d-inline-flex align-items-center gap-1" for="tag-filter-<?= h((string) $index) ?>"><span class="rounded-1 d-inline-block" style="width:10px;height:10px;background:<?= h($row['color']) ?>"></span><?= h($row['name']) ?></label>
        </div>
        <?php endforeach; ?>
    </div>
</div></div>
<div class="row g-4"><div class="col-12 col-xl-7"><div class="card shadow-sm border-0"><div class="card-body"><div style="height:420px"><canvas id="tagExpenseChart"></canvas></div></div></div></div>
<div class="col-12 col-xl-5"><div class="card shadow-sm border-0"><div class="card-body border-bottom"><div class="text-body-secondary small"><?= h($period) ?> 总支出</div><div class="fs-3 fw-semibold" id="tagExpenseTotal"><?= number_format((int) round($defaultVisibleTotal)) ?></div></div><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Tag</th><th class="text-end">金额</th><th class="text-end">占比</th></tr></thead><tbody><?php foreach ($rows as $index => $row): ?><tr data-tag-chart-row="<?= h((string) $index) ?>" <?= (string) $row['name'] === '提现' ? 'class="d-none"' : '' ?>><td><span class="badge me-2" style="background:<?= h($row['color']) ?>">&nbsp;</span><?= h($row['name']) ?></td><td class="text-end"><?= number_format((int) round($row['amount'])) ?></td><td class="text-end" data-tag-chart-percent><?= $defaultVisibleTotal > 0 ? number_format($row['amount'] / $defaultVisibleTotal * 100, 1) : '0.0' ?>%</td></tr><?php endforeach; ?></tbody></table></div></div></div></div>
<script>window.tagExpenseChartData = <?= json_encode(['labels' => array_column($rows, 'name'), 'values' => array_column($rows, 'amount'), 'colors' => array_column($rows, 'color')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;</script>
<?php
}

function expense_tag_statistics(string $period, string $scope): array
{
    $pdo = db();
    $effectiveDate = "CASE WHEN t.source_type IN ('paypay_card', 'epos_card') AND t.payment_date IS NOT NULL THEN t.payment_date ELSE t.transaction_date END";
    $dateCondition = $scope === 'year' ? "substr($effectiveDate, 1, 4) = :period" : "substr($effectiveDate, 1, 7) = :period";
    $stmt = $pdo->prepare("SELECT t.id, t.amount, g.name, g.color FROM transactions t LEFT JOIN transaction_tags tt ON tt.transaction_id = t.id LEFT JOIN tags g ON g.id = tt.tag_id WHERE t.direction = 'expense' AND $dateCondition AND NOT EXISTS (SELECT 1 FROM transaction_links l WHERE l.parent_transaction_id = t.id AND l.link_type = 'card_statement') AND NOT EXISTS (SELECT 1 FROM transaction_links cancel_l WHERE cancel_l.link_type = 'cancellation' AND (cancel_l.parent_transaction_id = t.id OR cancel_l.child_transaction_id = t.id)) ORDER BY t.id");
    $stmt->execute([':period' => $period]);
    $transactions = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        $transactions[$id]['amount'] = (int) $row['amount'];
        if ($row['name'] !== null) $transactions[$id]['tags'][] = ['name' => (string) $row['name'], 'color' => (string) $row['color']];
    }
    $totals = [];
    foreach ($transactions as $transaction) {
        $tags = $transaction['tags'] ?? [['name' => '未标记', 'color' => '#adb5bd']];
        $share = $transaction['amount'] / count($tags);
        foreach ($tags as $tag) {
            $totals[$tag['name']]['name'] = $tag['name']; $totals[$tag['name']]['color'] = $tag['color']; $totals[$tag['name']]['amount'] = ($totals[$tag['name']]['amount'] ?? 0) + $share;
        }
    }
    usort($totals, static fn(array $a, array $b): int => $b['amount'] <=> $a['amount']);
    return array_values($totals);
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
