<?php
// admin.php
require_once 'db.php';

$msg = '';

// 處理後台 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    // 1. 切換模式
    if ($action === 'set_lock_mode') {
        $mode =$_POST['mode'] ?? 'auto';
        $stmt =$pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'order_manual_lock'");
        $stmt->execute([$mode]);
        header("Location: admin.php");
        exit;
    }

    // 2. 儲存排程
    if ($action === 'update_schedule') {
        $startW = (int)($_POST['start_weekday'] ?? 1);
        $startTime = trim($_POST['start_time'] ?? '08:00');
        $endW = (int)($_POST['end_weekday'] ?? 5);
        $endTime = trim($_POST['end_time'] ?? '12:30');

        $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('order_start_weekday', ?)")->execute([$startW]);
        $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('order_start_time', ?)")->execute([$startTime]);
        $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('order_end_weekday', ?)")->execute([$endW]);
        $pdo->prepare("REPLACE INTO system_settings (setting_key, setting_value) VALUES ('order_end_time', ?)")->execute([$endTime]);

        $msg = '點餐起訖規則已儲存更新！';
    }

    // 3. 手動重設
    if ($action === 'reset_orders') {$pdo->exec("DELETE FROM orders");
        $msg = '已清空本週所有點餐紀錄！';
    }

    // 4. 更新單筆金額與品項
    if ($action === 'update_order_item') {
        header('Content-Type: application/json');
        $orderId = (int)($_POST['order_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $price = (int)($_POST['price'] ?? 0);
        
        $stmt =$pdo->prepare("UPDATE orders SET note = ?, price = ? WHERE id = ?");
        $stmt->execute([$note, $price,$orderId]);
        echo json_encode(['status' => 'success']);
        exit;
    }
}

// 讀取設定
$settings =$pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$manualLock =$settings['order_manual_lock'] ?? 'auto';
$startWeekday = (int)($settings['order_start_weekday'] ?? 1);
$startTime =$settings['order_start_time'] ?? '08:00';
$endWeekday = (int)($settings['order_end_weekday'] ?? 5);
$endTime =$settings['order_end_time'] ?? '12:30';

// 判斷時段
$curW = (int)date('N');
$curT = date('H:i');$curNow = sprintf("%d%s", $curW, str_replace(':', '',$curT));
$startPoint = sprintf("\%d\%s", $startWeekday, str_replace(':', '', $startTime));$endPoint = sprintf("%d%s", $endWeekday, str_replace(':', '',$endTime));

if ($startPoint <= $endPoint) {$isWithinSchedule = ($curNow >=$startPoint && $curNow <=$endPoint);
} else {
    // 跨週情況 (例如週五到下週一)
    $isWithinSchedule = ($curNow >=$startPoint || $curNow <=$endPoint);
}

if ($manualLock === 'force_open') {$isOpen = true;
    $statusText = '🔓 強制開放中 (測試模式)';
    $statusClass = 'badge-open';
} elseif ($manualLock === 'force_lock') {$isOpen = false;
    $statusText = '🛑 本週停課 / 強制結單 (已關閉)';
    $statusClass = 'badge-locked';
} else {
    $isOpen =$isWithinSchedule;
    $statusText =$isOpen ? '✅ 依排程開放中' : '⛔ 目前非開放時段 (未開放/已截止)';
    $statusClass =$isOpen ? 'badge-open' : 'badge-locked';
}

$weekdayMap = [1 => '週一', 2 => '週二', 3 => '週三', 4 => '週四', 5 => '週五', 6 => '週六', 7 => '週日'];

// 叫餐統計
$summaryRows =$pdo->query("
    SELECT 
        s.id AS store_id, s.name AS store_name, s.category, s.phone AS store_phone,
        m.item_name, o.note, m.price, 
        COUNT(o.id) AS qty, 
        SUM(o.price) AS subtotal
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
    GROUP BY s.id, o.item_id, o.note
    ORDER BY s.category ASC, s.id ASC, m.sort_order ASC, m.id ASC, qty DESC
")->fetchAll();

$storeSummaryMap = [];
foreach ($summaryRows as$row) {
    $stId =$row['store_id'];
    if (!isset($storeSummaryMap[$stId])) {
        $storeSummaryMap[$stId] = [
            'store_name' => $row['store_name'],
            'category' => $row['category'],
            'store_phone' => $row['store_phone'],
            'items' => [],
            'store_total_qty' => 0,
            'store_total_amount' => 0,
        ];
    }
    $storeSummaryMap[$stId]['items'][] =$row;
    $storeSummaryMap[$stId]['store_total_qty'] += $row['qty'];$storeSummaryMap[$stId]['store_total_amount'] +=$row['subtotal'];
}

// 撈取單筆訂單明細
$rawOrders =$pdo->query("
    SELECT o.*, m.item_name, s.name AS store_name
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
    ORDER BY o.id DESC
")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>訂單管理控制台</title>
    <!-- 引入 Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --accent: #ea580c;
            --danger: #dc2626;
            --bg: #faf6ee;
            --card: #ffffff;
            --border: #ebdccb;
        }
        * { box-sizing: border-box; }
        body { background: var(--bg); color: #1c1917; padding: 20px; max-width: 1080px; margin: 0 auto; }
        .nav-bar { display: flex; gap: 16px; margin-bottom: 20px; }
        .nav-link { color: var(--primary); text-decoration: none; font-weight: 700; }
        .card-custom { background: var(--card); border: 1.5px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 24px; }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        .badge-open { background: #dcfce7; color: #15803d; }
        .badge-locked { background: #fee2e2; color: #b91c1c; }
        .btn-group-custom { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { background: #f5eedf; }
    </style>
</head>
<body>

<div class="nav-bar">
    <a href="index.php" class="nav-link">&larr; 前往點餐前台</a>
    <a href="store_admin.php" class="nav-link" style="color:var(--accent);">🏪 店家與菜單管理 &rarr;</a>
</div>

<h1 class="h3 mb-3 font-weight-bold">訂餐管理與排程控制台</h1>
<?php if ($msg): ?>
    <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card-custom grid-2">
    <div>
        <h2>⏰ 點餐起訖排程設定</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_schedule">
            <div class="mb-2">
                <label class="form-label small fw-bold">開始開放時間：</label>
                <div class="d-flex gap-2">
                    <select name="start_weekday" class="form-select w-auto">
                        <?php foreach ($weekdayMap as $k =>$v): ?>
                            <option value="<?= $k ?>" <?= ($startWeekday === $k) ? 'selected' : '' ?>><?=$v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="time" name="start_time" class="form-control w-auto" value="<?= htmlspecialchars($startTime) ?>" required>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-bold">截止點餐時間：</label>
                <div class="d-flex gap-2">
                    <select name="end_weekday" class="form-select w-auto">
                        <?php foreach ($weekdayMap as $k =>$v): ?>
                            <option value="<?= $k ?>" <?= ($endWeekday === $k) ? 'selected' : '' ?>><?=$v ?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="time" name="end_time" class="form-control w-auto" value="<?= htmlspecialchars($endTime) ?>" required>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-sm">💾 儲存排程規則</button>
        </form>
    </div>

    <div>
        <h2>⚙️ 運作模式切換</h2>
        <p>前台現況：<span class="badge <?= $statusClass ?>"><?= $statusText ?></span></p>
        <form method="POST">
            <input type="hidden" name="action" value="set_lock_mode">
            <div class="btn-group">
                <button type="submit" name="mode" value="auto" class="btn btn-outline-secondary <?= $manualLock === 'auto' ? 'active' : '' ?>">⏰ 自動定時</button>
                <button type="submit" name="mode" value="force_open" class="btn btn-outline-secondary <?= $manualLock === 'force_open' ? 'active' : '' ?>">🔓 強制開放</button>
                <button type="submit" name="mode" value="force_lock" class="btn btn-outline-danger <?= $manualLock === 'force_lock' ? 'active' : '' ?>">🛑 強制結單</button>
            </div>
        </form>
        <form method="POST" onsubmit="return confirm('確定要清空所有訂單嗎？');" class="mt-3">
            <input type="hidden" name="action" value="reset_orders">
            <button type="submit" class="btn btn-outline-danger btn-sm">🔄 清空所有點餐紀錄</button>
        </form>
    </div>
</div>

<!-- 叫餐統計 -->
<div class="card-custom">
    <h3>📞 餐點統計 (依店家分開)</h3>
    <?php foreach ($storeSummaryMap as$sGroup): ?>
        <div class="mb-3 p-3 border rounded">
            <div class="d-flex justify-content-between">
                <strong>🍱 <?= htmlspecialchars($sGroup['store_name']) ?> (<?= htmlspecialchars($sGroup['store_phone']) ?>)</strong>
                <span class="text-primary fw-bold">小計：<?= $sGroup['store_total_qty'] ?> 份 / $<?=$sGroup['store_total_amount'] ?> 元</span>
            </div>
            <table class="table table-sm mt-2">
                <thead>
                    <tr><th>品項</th><th>備註</th><th>單價</th><th>數量</th><th>小計</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($sGroup['items'] as$item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['item_name']) ?></td>
                            <td><?= htmlspecialchars($item['note'] ?: '無') ?></td>
                            <td>$<?=$item['price'] ?></td>
                            <td class="fw-bold text-danger"><?= $item['qty'] ?></td>
                            <td>$<?=$item['subtotal'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>

<!-- 單筆訂購明細與對帳 -->
<div class="card-custom">
    <h3>📋 同學訂購明細與收款核銷</h3>
    <table class="table align-middle">
        <thead>
            <tr>
                <th>同學姓名</th>
                <th>品項 (店家 / 備註)</th>
                <th>應收</th>
                <th style="width: 120px;">已繳金額</th>
                <th>對帳狀態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rawOrders)): ?>
                <tr><td colspan="6" class="text-center text-muted">目前尚無訂單</td></tr>
            <?php else: ?>
                <?php foreach ($rawOrders as$row): ?>
                    <tr id="order-row-<?= $row['id'] ?>" data-order-id="<?= $row['id'] ?>" data-price="<?= $row['price'] ?>" data-cancelled="0">
                        <td><strong><?= htmlspecialchars($row['user_name']) ?></strong></td>
                        <td>
                            <span class="item-text">
                                <?= htmlspecialchars($row['item_name']) ?> 
                                <small class="text-muted">(<?= htmlspecialchars($row['store_name'] ?? '') ?> / <?= htmlspecialchars($row['note']) ?>)</small>
                            </span>
                        </td>
                        <td class="payable-amount">$<?=$row['price'] ?></td>
                        <td>
                            <input type="number" class="form-control form-control-sm paid-input" value="0" min="0" oninput="calculateBalance(<?= $row['id'] ?>)">
                        </td>
                        <td>
                            <span class="badge bg-danger status-badge">🔴 欠款</span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-warning me-1" onclick="openUpdateModal(<?= $row['id'] ?>, '<?= addslashes($row['item_name']) ?>', '<?= addslashes($row['store_name'] ?? '') ?>', '<?= addslashes($row['note']) ?>', <?=$row['price'] ?>)">修改</button>
                            <button class="btn btn-sm btn-outline-danger cancel-btn" onclick="toggleCancelItem(<?= $row['id'] ?>)">取消品項</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 修改 Modal (單一實例) -->
<div class="modal fade" id="updateItemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">修改訂單內容</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="modalOrderId">
        <div class="mb-3">
          <label class="form-label">品項</label>
          <input type="text" class="form-control" id="modalItemName" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">店家</label>
          <input type="text" class="form-control" id="modalStoreName" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label">自訂備註</label>
          <input type="text" class="form-control" id="modalNote">
        </div>
        <div class="mb-3">
          <label class="form-label">單價</label>
          <input type="number" class="form-control" id="modalPrice">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">取消</button>
        <button type="button" class="btn btn-primary" onclick="submitItemUpdate()">儲存變更</button>
      </div>
    </div>
  </div>
</div>

<!-- 載入 Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
function calculateBalance(orderId) {
    const row = document.getElementById(`order-row-${orderId}`);
    if (!row) return;
    
    const isCancelled = row.getAttribute('data-cancelled') === '1';
    const originalPrice = parseInt(row.getAttribute('data-price')) || 0;
    const payable = isCancelled ? 0 : originalPrice;
    
    const paidInput = row.querySelector('.paid-input');
    const paid = parseInt(paidInput.value) || 0;
    const statusBadge = row.querySelector('.status-badge');

    row.querySelector('.payable-amount').innerText = `$${payable}`;

    if (paid === payable) {
        statusBadge.className = "badge bg-success status-badge";
        statusBadge.innerText = "🟢 付款完成";
    } else if (paid > payable) {
        statusBadge.className = "badge bg-primary status-badge";
        statusBadge.innerText = `🔵 應退 $${paid - payable}`;
    } else {
        statusBadge.className = "badge bg-danger status-badge";
        statusBadge.innerText = `🔴 欠款 $${payable - paid}`;
    }
}

function toggleCancelItem(orderId) {
    const row = document.getElementById(`order-row-${orderId}`);
    if (!row) return;
    
    const itemText = row.querySelector('.item-text');
    const cancelBtn = row.querySelector('.cancel-btn');
    const isCancelled = row.getAttribute('data-cancelled') === '1';

    if (!isCancelled) {
        itemText.style.textDecoration = "line-through";
        itemText.style.color = "gray";
        row.setAttribute('data-cancelled', '1');
        cancelBtn.innerText = "恢復品項";
        cancelBtn.className = "btn btn-sm btn-outline-secondary cancel-btn";
    } else {
        itemText.style.textDecoration = "none";
        itemText.style.color = "initial";
        row.setAttribute('data-cancelled', '0');
        cancelBtn.innerText = "取消品項";
        cancelBtn.className = "btn btn-sm btn-outline-danger cancel-btn";
    }

    calculateBalance(orderId);
}

let bootstrapModal;
function openUpdateModal(orderId, itemName, storeName, note, price) {
    document.getElementById('modalOrderId').value = orderId;
    document.getElementById('modalItemName').value = itemName;
    document.getElementById('modalStoreName').value = storeName;
    document.getElementById('modalNote').value = note;
    document.getElementById('modalPrice').value = price;

    bootstrapModal = new bootstrap.Modal(document.getElementById('updateItemModal'));
    bootstrapModal.show();
}

function submitItemUpdate() {
    const orderId = document.getElementById('modalOrderId').value;
    const newNote = document.getElementById('modalNote').value;
    const newPrice = parseInt(document.getElementById('modalPrice').value) || 0;

    // 發送 POST 回後端儲存
    const fd = new FormData();
    fd.append('action', 'update_order_item');
    fd.append('order_id', orderId);
    fd.append('note', newNote);
    fd.append('price', newPrice);

    fetch('admin.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            const row = document.getElementById(`order-row-${orderId}`);
            if (row) {
                row.setAttribute('data-price', newPrice);
                const store = document.getElementById('modalStoreName').value;
                const name = document.getElementById('modalItemName').value;
                row.querySelector('.item-text').innerHTML = `${name} <small class="text-muted">(${store} / ${newNote})</small>`;
                calculateBalance(orderId);
            }
            bootstrapModal.hide();
        }
    });
}

document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('[data-order-id]').forEach(row => {
        const orderId = row.getAttribute('data-order-id');
        calculateBalance(orderId);
    });
});
</script>
</body>
</html>
