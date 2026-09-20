<?php
// admin.php
require_once 'db.php';

$msg = '';

// 處理後台 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action =$_POST['action'] ?? '';

    // 1. 切換模式 (auto / force_open / force_lock)
    if ($action === 'set_lock_mode') {
        $mode =$_POST['mode'] ?? 'auto';
        $stmt =$pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'order_manual_lock'");
        $stmt->execute([$mode]);
        header("Location: admin.php");
        exit;
    }

    // 2. 儲存起訖日期與時間設定
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

    // 3. 手動重設本週訂單
    if ($action === 'reset_orders') {$pdo->exec("DELETE FROM orders");
        $msg = '已清空本週所有點餐紀錄！';
    }

    // 4. 更新單筆訂單品項與金額 (供 Modal 使用)
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

    // 5. 更新已繳金額
    if ($action === 'update_paid_amount') {
        header('Content-Type: application/json');
        $orderId = (int)($_POST['order_id'] ?? 0);
        $paidAmount = (int)($_POST['paid_amount'] ?? 0);
        $isPaid = (int)($_POST['is_paid'] ?? 0);

        $stmt =$pdo->prepare("UPDATE orders SET paid_amount = ?, is_paid = ? WHERE id = ?");
        $stmt->execute([$paidAmount, $isPaid,$orderId]);
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

// 判斷當前是否處於開放時段 (支援跨週末判斷)
$curW = (int)date('N');
$curT = date('H:i');$curNow = sprintf("%d%s", $curW, str_replace(':', '',$curT));
$startPoint = sprintf("\%d\%s", $startWeekday, str_replace(':', '', $startTime));$endPoint = sprintf("%d%s", $endWeekday, str_replace(':', '',$endTime));

if ($startPoint <= $endPoint) {$isWithinSchedule = ($curNow >=$startPoint && $curNow <=$endPoint);
} else {
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

// 統計彙整 (依品項 + 規格備註 分組)
$summaryRows =$pdo->query("
    SELECT 
        s.id AS store_id,
        s.name AS store_name, 
        s.category,
        s.phone AS store_phone,
        m.item_name, 
        o.note,
        m.price, 
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
            'store_name'  => $row['store_name'],
            'category'    => $row['category'],
            'store_phone' => $row['store_phone'],
            'items'       => [],
            'store_total_qty' => 0,
            'store_total_amount' => 0,
        ];
    }
    $storeSummaryMap[$stId]['items'][] =$row;
    $storeSummaryMap[$stId]['store_total_qty'] += $row['qty'];$storeSummaryMap[$stId]['store_total_amount'] +=$row['subtotal'];
}

// 撈取單筆訂購明細
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
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --accent: #ea580c;
            --danger: #dc2626;
            --success: #16a34a;
            --bg: #faf6ee;
            --card: #ffffff;
            --border: #ebdccb;
        }
        * { box-sizing: border-box; }
        body { background: var(--bg); color: #1c1917; padding: 20px; max-width: 1040px; margin: 0 auto; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        .nav-bar { display: flex; gap: 16px; margin-bottom: 20px; }
        .nav-link { color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.95rem; }
        .card-custom { background: var(--card); border: 1.5px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        .badge-open { background: #dcfce7; color: #15803d; }
        .badge-locked { background: #fee2e2; color: #b91c1c; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { background: #f5eedf; font-weight: 700; }
    </style>
</head>
<body>

<div class="nav-bar">
    <a href="index.php" class="nav-link">&larr; 前往點餐前台</a>
    <a href="store_admin.php" class="nav-link" style="color:var(--accent);">🏪 店家與菜單管理 &rarr;</a>
</div>

<h1 class="h3 mb-3 fw-bold">訂餐管理與排程控制台</h1>
<?php if ($msg): ?>
    <div class="alert alert-success fw-bold"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card-custom grid-2">
    <div>
        <h2 class="h5 fw-bold mb-3">⏰ 點餐起訖排程設定</h2>
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

            <button type="submit" class="btn btn-primary btn-sm">💾 儲存起訖時間規則</button>
        </form>
    </div>

    <div>
        <h2 class="h5 fw-bold mb-2">⚙️ 運作模式切換</h2>
        <p class="mb-2">前台現況：<span class="badge <?= $statusClass ?> p-2"><?= $statusText ?></span></p>
        <p class="small text-muted mb-3">
            排程規則：每<?= $weekdayMap[$startWeekday] ?> <?= $startTime ?> 至 <?= $weekdayMap[$endWeekday] ?> <?=$endTime ?>
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="set_lock_mode">
            <div class="btn-group">
                <button type="submit" name="mode" value="auto" class="btn btn-outline-secondary btn-sm <?= $manualLock === 'auto' ? 'active' : '' ?>">⏰ 自動定時</button>
                <button type="submit" name="mode" value="force_open" class="btn btn-outline-secondary btn-sm <?= $manualLock === 'force_open' ? 'active' : '' ?>">🔓 強制開放</button>
                <button type="submit" name="mode" value="force_lock" class="btn btn-outline-danger btn-sm <?= $manualLock === 'force_lock' ? 'active' : '' ?>">🛑 本週停課</button>
            </div>
        </form>

        <form method="POST" onsubmit="return confirm('確定要手動清空本週所有點餐資料嗎？');" class="mt-3">
            <input type="hidden" name="action" value="reset_orders">
            <button type="submit" class="btn btn-outline-danger btn-sm">🔄 立即清空本週點餐 (保留名冊)</button>
        </form>
    </div>
</div>

<div class="card-custom">
    <h2 class="h5 fw-bold mb-3">📞 餐點叫餐統計 (依店家分開)</h2>
    <?php if (empty($storeSummaryMap)): ?>
        <div class="text-center text-muted py-4">目前尚無同學登記點餐。</div>
    <?php else: ?>
        <?php foreach ($storeSummaryMap as $stId =>$sGroup): 
            $isDrink = ($sGroup['category'] === 'drink');
            $unitText =$isDrink ? '杯' : '份';
        ?>
            <div class="border rounded p-3 mb-3" style="border-top: 4px solid <?= $isDrink ? '#6366f1' : '#f59e0b' ?> !important;">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 pb-2 border-bottom">
                    <div>
                        <strong class="fs-5"><?= $isDrink ? '🧋 ' : '🍱 ' ?><?= htmlspecialchars($sGroup['store_name']) ?></strong>
                        <?php if (!empty($sGroup['store_phone'])): ?>
                            <span class="text-muted ms-2 small">📞 <?= htmlspecialchars($sGroup['store_phone']) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="fw-bold text-primary bg-light px-3 py-1 rounded">
                        叫餐小計：<?= $sGroup['store_total_qty'] ?> <?=$unitText ?> / $<?=$sGroup['store_total_amount'] ?> 元
                    </div>
                </div>

                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:35%;">品項名稱</th>
                            <th style="width:35%;">規格 / 備註</th>
                            <th style="width:10%;">單價</th>
                            <th style="width:10%;">數量</th>
                            <th style="width:10%;">小計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sGroup['items'] as$item): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td>
                                    <?php if (!empty($item['note'])): ?>
                                        <span class="badge <?= $isDrink ? 'bg-indigo text-primary' : 'bg-warning text-dark' ?>">
                                            <?= htmlspecialchars($item['note']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted small">標準 (無備註)</span>
                                    <?php endif; ?>
                                </td>
                                <td>$<?=$item['price'] ?></td>
                                <td class="fw-bold fs-6 text-danger"><?= $item['qty'] ?></td>
                                <td>$<?=$item['subtotal'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<div class="card-custom">
    <h2 class="h5 fw-bold mb-3">📋 同學訂購明細與收款核銷</h2>
    <table class="table align-middle">
        <thead>
            <tr>
                <th>姓名</th>
                <th>品項 (店家 / 備註)</th>
                <th>應收</th>
                <th style="width: 130px;">已繳金額</th>
                <th>對帳狀態</th>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rawOrders)): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">尚無訂購明細</td></tr>
            <?php else: ?>
                <?php foreach ($rawOrders as$row): ?>
                    <tr id="order-row-<?= $row['id'] ?>" data-order-id="<?= $row['id'] ?>" data-price="<?= $row['price'] ?>" data-cancelled="0">
                        <td><strong><?= htmlspecialchars($row['user_name']) ?></strong></td>
                        <td>
                            <span class="item-text">
                                <?= htmlspecialchars($row['item_name']) ?> 
                                <small class="text-muted">(<?= htmlspecialchars($row['store_name'] ?? '') ?> / <?= htmlspecialchars($row['note'] ?: '無') ?>)</small>
                            </span>
                        </td>
                        <td class="payable-amount">$<?=$row['price'] ?></td>
                        <td>
                            <input type="number" class="form-control form-control-sm paid-input" value="<?= $row['paid_amount'] ?? 0 ?>" min="0" oninput="calculateBalance(<?= $row['id'] ?>)">
                        </td>
                        <td>
                            <span class="badge status-badge bg-danger">🔴 計算中</span>
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

<div class="modal fade" id="updateItemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">修改員工品項</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="modalOrderId">
        <div class="mb-3">
          <label class="form-label small fw-bold">品項名稱</label>
          <input type="text" class="form-control" id="modalItemName" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold">店家</label>
          <input type="text" class="form-control" id="modalStoreName" readonly>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold">自訂備註</label>
          <input type="text" class="form-control" id="modalNote">
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold">單價</label>
          <input type="number" class="form-control" id="modalPrice">
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">關閉</button>
        <button type="button" class="btn btn-primary" onclick="submitItemUpdate()">儲存更新</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// 對帳邏輯
function calculateBalance(orderId) {
    const row = document.getElementById(`order-row-${orderId}`);
    if (!row) return;
    
    const isCancelled = row.getAttribute('data-cancelled') === '1';
    const payable = isCancelled ? 0 : parseInt(row.getAttribute('data-price')) || 0;
    const paidInput = row.querySelector('.paid-input');
    const paid = parseInt(paidInput.value) || 0;
    const statusBadge = row.querySelector('.status-badge');

    row.querySelector('.payable-amount').innerText = `$${payable}`;

    let isPaidStatus = 0;
    if (paid === payable && payable > 0) {
        statusBadge.className = "badge bg-success status-badge";
        statusBadge.innerText = "🟢 付款完成";
        isPaidStatus = 1;
    } else if (paid > payable) {
        statusBadge.className = "badge bg-primary status-badge";
        statusBadge.innerText = `🔵 應退 $${paid - payable}`;
        isPaidStatus = 1;
    } else {
        statusBadge.className = "badge bg-danger status-badge";
        statusBadge.innerText = `🔴 欠款 $${payable - paid}`;
        isPaidStatus = 0;
    }

    // 發送非同步更新儲存已繳金額
    const fd = new FormData();
    fd.append('action', 'update_paid_amount');
    fd.append('order_id', orderId);
    fd.append('paid_amount', paid);
    fd.append('is_paid', isPaidStatus);
    fetch('admin.php', { method: 'POST', body: fd });
}

// 取消品項
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

// Modal 控制
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

// 初始化所有列的對帳狀態
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll('[data-order-id]').forEach(row => {
        const orderId = row.getAttribute('data-order-id');
        calculateBalance(orderId);
    });
});
</script>
</body>
</html>
