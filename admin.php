<?php
// admin.php
require_once 'db.php';

$msg = '';

// 處理後台 POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. 切換模式 (auto / force_open / force_lock)
    if ($action === 'set_lock_mode') {
        $mode = $_POST['mode'] ?? 'auto';
        $stmt = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'order_manual_lock'");
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
    if ($action === 'reset_orders') {
        $pdo->exec("DELETE FROM orders");
        $msg = '已清空本週所有點餐紀錄！';
    }

   // 4. 收款核銷切換 (依同學姓名整筆核銷)
    if ($action === 'toggle_paid') {
        header('Content-Type: application/json');
        $userName = trim($_POST['user_name'] ?? '');
        $paid = (int)($_POST['is_paid'] ?? 0);
        $newPaid = $paid ? 0 : 1;
        $stmt = $pdo->prepare("UPDATE orders SET is_paid = ? WHERE user_name = ?");
        $stmt->execute([$newPaid, $userName]);
        echo json_encode(['status' => 'success', 'new_paid' => $newPaid]);
        exit;
    }
}

// 讀取設定
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$manualLock = $settings['order_manual_lock'] ?? 'auto';
$startWeekday = (int)($settings['order_start_weekday'] ?? 1);
$startTime = $settings['order_start_time'] ?? '08:00';
$endWeekday = (int)($settings['order_end_weekday'] ?? 5);
$endTime = $settings['order_end_time'] ?? '12:30';

// 判斷當前是否處於開放時段
$curW = (int)date('N');
$curT = date('H:i');
$curNow = sprintf("%d%s", $curW, str_replace(':', '', $curT));
$startPoint = sprintf("%d%s", $startWeekday, str_replace(':', '', $startTime));
$endPoint = sprintf("%d%s", $endWeekday, str_replace(':', '', $endTime));

$isWithinSchedule = ($curNow >= $startPoint && $curNow <= $endPoint);

if ($manualLock === 'force_open') {
    $isOpen = true;
    $statusText = '🔓 強制開放中 (測試模式)';
    $statusClass = 'badge-open';
} elseif ($manualLock === 'force_lock') {
    $isOpen = false;
    $statusText = '🛑 本週停課 / 強制結單 (已關閉)';
    $statusClass = 'badge-locked';
} else {
    $isOpen = $isWithinSchedule;
    $statusText = $isOpen ? '✅ 依排程開放中' : '⛔ 目前非開放時段 (未開放/已截止)';
    $statusClass = $isOpen ? 'badge-open' : 'badge-locked';
}

$weekdayMap = [1 => '週一', 2 => '週二', 3 => '週三', 4 => '週四', 5 => '週五', 6 => '週六', 7 => '週日'];

// 統計彙整 (依品項 + 規格備註 分組)
$summaryRows = $pdo->query("
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

// 將統計資料依「店家」分組
$storeSummaryMap = [];
$totalCount = 0;
$totalAmount = 0;

foreach ($summaryRows as $row) {
    $stId = $row['store_id'];
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
    $storeSummaryMap[$stId]['items'][] = $row;
    $storeSummaryMap[$stId]['store_total_qty'] += $row['qty'];
    $storeSummaryMap[$stId]['store_total_amount'] += $row['subtotal'];

    $totalCount += $row['qty'];
    $totalAmount += $row['subtotal'];
}

$totalCount = 0;
$totalAmount = 0;
foreach ($summary as $r) {
    $totalCount += $r['qty'];
    $totalAmount += $r['subtotal'];
}

// 訂購明細 (撈取並依同學姓名聚合)
$rawOrders = $pdo->query("
    SELECT o.*, m.item_name, s.name AS store_name
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
    ORDER BY o.user_name ASC, o.id ASC
")->fetchAll();

$userOrders = [];
foreach ($rawOrders as $ro) {
    $uname = $ro['user_name'];
    if (!isset($userOrders[$uname])) {
        $userOrders[$uname] = [
            'user_name'    => $uname,
            'total_amount' => 0,
            'is_paid'      => 1, // 預設 1，只要有一筆是 0 就會變成 0
            'items'        => []
        ];
    }
    $userOrders[$uname]['items'][] = [
        'store_name' => $ro['store_name'],
        'item_name'  => $ro['item_name'],
        'note'       => $ro['note'],
        'price'      => (int)$ro['price']
    ];
    $userOrders[$uname]['total_amount'] += (int)$ro['price'];
    if ($ro['is_paid'] == 0) {
        $userOrders[$uname]['is_paid'] = 0;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>訂單管理控制台</title>
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
        * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: var(--bg); color: #1c1917; padding: 20px; max-width: 980px; margin: 0 auto; }
        .nav-bar { display: flex; gap: 16px; margin-bottom: 20px; }
        .nav-link { color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.95rem; }
        .card { background: var(--card); border: 1.5px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
        @media (max-width: 768px) { .grid-2 { grid-template-columns: 1fr; } }
        
        h2 { font-size: 1.25rem; font-weight: 800; margin-bottom: 12px; }
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 0.85rem; font-weight: 700; display: inline-block; }
        .badge-open { background: #dcfce7; color: #15803d; }
        .badge-locked { background: #fee2e2; color: #b91c1c; }

        .btn-group { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 10px; }
        .btn { padding: 8px 14px; border: 1.5px solid var(--border); border-radius: 8px; font-weight: 700; cursor: pointer; background: #fff; }
        .btn.active { background: var(--primary); color: #fff; border-color: var(--primary); }
        
        .input-select, .input-time { padding: 8px 10px; border: 1.5px solid var(--border); border-radius: 8px; font-size: 0.95rem; background: #fff; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: #f5eedf; font-weight: 700; }
        .paid-btn { cursor: pointer; border-radius: 20px; padding: 4px 10px; font-size: 0.8rem; border: none; font-weight: 700; }
        .paid-btn.yes { background: #dcfce7; color: #15803d; }
        .paid-btn.no { background: #fee2e2; color: #b91c1c; }
    </style>
</head>
<body>

<div class="nav-bar">
    <a href="index.php" class="nav-link">&larr; 前往點餐前台</a>
    <a href="store_admin.php" class="nav-link" style="color:var(--accent);">🏪 店家與菜單管理 &rarr;</a>
</div>

<h1>訂餐管理與排程控制台</h1>
<?php if ($msg): ?>
    <div style="padding:12px; background:#dcfce7; color:#15803d; border-radius:8px; margin: 15px 0; font-weight:700;"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="card grid-2">
    <!-- 左側：起訖時間設定 -->
    <div>
        <h2>⏰ 點餐起訖排程設定</h2>
        <form method="POST">
            <input type="hidden" name="action" value="update_schedule">
            <div style="margin-bottom: 12px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:4px; color:#57534e;">開始開放時間：</label>
                <select name="start_weekday" class="input-select">
                    <?php foreach ($weekdayMap as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($startWeekday === $k) ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="time" name="start_time" class="input-time" value="<?= htmlspecialchars($startTime) ?>" required>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:4px; color:#57534e;">截止點餐時間：</label>
                <select name="end_weekday" class="input-select">
                    <?php foreach ($weekdayMap as $k => $v): ?>
                        <option value="<?= $k ?>" <?= ($endWeekday === $k) ? 'selected' : '' ?>><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="time" name="end_time" class="input-time" value="<?= htmlspecialchars($endTime) ?>" required>
            </div>

            <button type="submit" class="btn" style="background:var(--primary); color:#fff; border-color:var(--primary);">
                💾 儲存起訖時間規則
            </button>
        </form>
    </div>

    <!-- 右側：狀態切換與停課控制 -->
    <div>
        <h2>⚙️ 運作模式切換</h2>
        <p style="margin: 8px 0;">前台現況：<span class="badge <?= $statusClass ?>"><?= $statusText ?></span></p>
        <p style="font-size:0.85rem; color:#78716c; margin-bottom:10px;">
            排程規則：每<?= $weekdayMap[$startWeekday] ?> <?= $startTime ?> 至 <?= $weekdayMap[$endWeekday] ?> <?= $endTime ?>
        </p>

        <form method="POST">
            <input type="hidden" name="action" value="set_lock_mode">
            <div class="btn-group">
                <button type="submit" name="mode" value="auto" class="btn <?= $manualLock === 'auto' ? 'active' : '' ?>">
                    ⏰ 自動定時
                </button>
                <button type="submit" name="mode" value="force_open" class="btn <?= $manualLock === 'force_open' ? 'active' : '' ?>">
                    🔓 強制開放 (測試)
                </button>
                <button type="submit" name="mode" value="force_lock" class="btn <?= $manualLock === 'force_lock' ? 'active' : '' ?>" style="color:var(--danger);">
                    🛑 本週停課 (關閉)
                </button>
            </div>
        </form>

        <form method="POST" onsubmit="return confirm('確定要手動清空本週點餐嗎？');" style="margin-top: 16px;">
            <input type="hidden" name="action" value="reset_orders">
            <button type="submit" class="btn" style="color: var(--danger); border-color: var(--danger); font-size:0.85rem;">
                🔄 立即清空本週點餐 (保留名冊)
            </button>
        </form>
    </div>
</div>

<!-- 依店家分開的叫餐統計 -->
<div style="margin-bottom: 14px;">
    <h2 style="font-size:1.3rem; font-weight:800; margin:0;">📞 餐點叫餐統計 (依店家分開)</h2>
</div>

    <?php if (empty($storeSummaryMap)): ?>
        <div class="card" style="text-align:center; color:#a8a29e; padding:30px;">
            目前尚無同學登記點餐。
        </div>
    <?php else: ?>
        <?php foreach ($storeSummaryMap as $stId => $sGroup): 
            $isDrink = ($sGroup['category'] === 'drink');
            $unitText = $isDrink ? '杯' : '份';
        ?>
            <div class="card" style="margin-bottom: 20px; border-top: 4px solid <?= $isDrink ? '#6366f1' : '#f59e0b' ?>;">
                <!-- 店家抬頭與小計 -->
                <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-bottom:1px solid var(--border); padding-bottom:12px; margin-bottom:12px;">
                    <div>
                        <span style="font-size:1.25rem; font-weight:800; color:#1c1917;">
                            <?= $isDrink ? '🧋 ' : '🍱 ' ?><?= htmlspecialchars($sGroup['store_name']) ?>
                        </span>
                        <?php if (!empty($sGroup['store_phone'])): ?>
                            <span style="font-size:0.95rem; color:#78716c; margin-left:10px; font-weight:600;">
                                📞 <?= htmlspecialchars($sGroup['store_phone']) ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size:1.05rem; font-weight:800; color:var(--primary); background:#eff6ff; padding:4px 12px; border-radius:8px;">
                        叫餐小計：<?= $sGroup['store_total_qty'] ?> <?= $unitText ?> / $<?= $sGroup['store_total_amount'] ?> 元
                    </div>
                </div>

                <!-- 該店家的品項與規格明細 -->
                <table>
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
                        <?php foreach ($sGroup['items'] as $item): ?>
                            <tr>
                                <td><strong style="font-size:1.05rem;"><?= htmlspecialchars($item['item_name']) ?></strong></td>
                                <td>
                                    <?php if (!empty($item['note'])): ?>
                                        <span style="background:<?= $isDrink ? '#e0e7ff' : '#fef3c7' ?>; color:<?= $isDrink ? '#3730a3' : '#92400e' ?>; padding:3px 8px; border-radius:6px; font-weight:700; font-size:0.85rem; display:inline-block;">
                                            <?= htmlspecialchars($item['note']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span style="color:#a8a29e; font-size:0.85rem;">標準 (無備註)</span>
                                    <?php endif; ?>
                                </td>
                                <td>$<?= $item['price'] ?></td>
                                <td style="font-weight:800; font-size:1.15rem; color:var(--accent);"><?= $item['qty'] ?></td>
                                <td>$<?= $item['subtotal'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- 收款核銷 (一人一列) -->
<div class="card">
    <h2>同學訂購明細與收款核銷</h2>
    <table>
        <thead>
            <!-- 請參考此結構，替換您迴圈印出 <tr> 內部的對應欄位 -->
            <tr id="order-row-<?php echo $row['id']; ?>" data-order-id="<?php echo $row['id']; ?>" data-price="<?php echo $row['price']; ?>">
              <td><?php echo htmlspecialchars($row['user_name']); ?></td>
              
              <!-- 品項加上 item-text 的 class，方便 JavaScript 控制刪除線 -->
              <td>
                <span class="item-text">
                  <?php echo htmlspecialchars($row['item_name']); ?> 
                  <small class="text-muted">(<?php echo htmlspecialchars($row['store_name'] ?? ''); ?> / <?php echo htmlspecialchars($row['note']); ?>)</small>
                </span>
              </td>
              
              <!-- 應收金額欄位 -->
              <td class="payable-amount"><?php echo $row['price']; ?></td>
              
              <!-- 替換 1：已繳金額改為輸入框，並綁定 oninput 事件 -->
              <td>
                <input type="number" class="form-control paid-input" value="<?php echo $row['paid_amount'] ?? 0; ?>" min="0" style="width: 100px;" oninput="calculateBalance(<?php echo $row['id']; ?>)">
              </td>
              
              <!-- 替換 2：對帳狀態顯示區 -->
              <td>
                <span class="badge bg-danger status-badge">🔴 判定中</span>
              </td>
              
              <!-- 替換 3：操作按鈕，帶入當前品項的所有動態參數 -->
              <td>
                <button class="btn btn-sm btn-warning me-1" onclick="openUpdateModal(<?php echo $row['id']; ?>, '<?php echo addslashes($row['item_name']); ?>', '<?php echo addslashes($row['store_name'] ?? ''); ?>', '<?php echo addslashes($row['note']); ?>', <?php echo $row['price']; ?>)">更新</button>
                <button class="btn btn-sm btn-danger cancel-btn" onclick="toggleCancelItem(<?php echo $row['id']; ?>)">取消品項</button>
              </td>
            </tr>          
        </thead>
        <tbody>
            <?php if (empty($userOrders)): ?>
                <tr><td colspan="4" style="text-align:center; color:#a8a29e;">尚無訂購明細</td></tr>
            <?php else: ?>
                <?php foreach ($userOrders as $u): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($u['user_name']) ?></strong></td>
                        <td>
                            <ul style="margin:0; padding-left:18px; list-style-type:square;">
                                <?php foreach ($u['items'] as $item): ?>
                                    <li style="margin-bottom: 4px;">
                                        <small style="color:#78716c;">[<?= htmlspecialchars($item['store_name']) ?>]</small>
                                        <strong><?= htmlspecialchars($item['item_name']) ?></strong>
                                        <?php if (!empty($item['note'])): ?>
                                            <span style="color:#e11d48; font-size:0.85rem;">(<?= htmlspecialchars($item['note']) ?>)</span>
                                        <?php endif; ?>
                                        - $<?= $item['price'] ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </td>
                        <td>
                            <strong style="font-size:1.15rem; color:<?= $u['is_paid'] ? '#15803d' : 'var(--danger)' ?>;">
                                $<?= $u['total_amount'] ?>
                            </strong>
                        </td>
                        <td>
                            <button class="paid-btn <?= $u['is_paid'] ? 'yes' : 'no' ?>" 
                                    onclick="toggleUserPaid('<?= htmlspecialchars($u['user_name'], ENT_QUOTES) ?>', <?= $u['is_paid'] ?>, this)">
                                <?= $u['is_paid'] ? '✓ 已付款' : '✗ 未付款' ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function toggleUserPaid(userName, curPaid, btn) {
    const fd = new FormData();
    fd.append('action', 'toggle_paid');
    fd.append('user_name', userName);
    fd.append('is_paid', curPaid);

    fetch('admin.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            if (data.new_paid === 1) {
                btn.className = 'paid-btn yes';
                btn.innerText = '✓ 已付款';
                btn.onclick = () => toggleUserPaid(userName, 1, btn);
                // 讓金額變綠色
                btn.closest('tr').querySelector('strong[style*="font-size:1.15rem"]').style.color = '#15803d';
            } else {
                btn.className = 'paid-btn no';
                btn.innerText = '✗ 未付款';
                btn.onclick = () => toggleUserPaid(userName, 0, btn);
                // 讓金額變紅色
                btn.closest('tr').querySelector('strong[style*="font-size:1.15rem"]').style.color = 'var(--danger)';
            }
        }
    });
}
// 💡 功能一：應收 vs 已繳 自動判斷付款/退款
function calculateBalance(orderId) {
  const row = document.getElementById(`order-row-${orderId}`);
  if (!row) return;
  
  const isCancelled = row.getAttribute('data-cancelled') === '1';
  const payable = isCancelled ? 0 : parseInt(row.getAttribute('data-price')) || 0;
  const paidInput = row.querySelector('.paid-input');
  const paid = parseInt(paidInput.value) || 0;
  const statusBadge = row.querySelector('.status-badge');

  // 即時更新畫面的應收金額數字
  row.querySelector('.payable-amount').innerText = payable;

  // 對帳邏輯判斷
  if (paid === payable) {
    statusBadge.className = "badge bg-success status-badge";
    statusBadge.innerText = "🟢 付款完成";
  } else if (paid > payable) {
    const refund = paid - payable;
    statusBadge.className = "badge bg-primary status-badge";
    statusBadge.innerText = `🔵 應退 $${refund}`;
  } else {
    const owe = payable - paid;
    statusBadge.className = "badge bg-danger status-badge";
    statusBadge.innerText = `🔴 欠款 $${owe}`;
  }
}

// 💡 功能二：品項取消與還原 (控制刪除線與金額歸零)
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
    cancelBtn.className = "btn btn-sm btn-danger cancel-btn";
  }

  calculateBalance(orderId);
}

// 💡 功能三：開啟 Modal 並帶入舊資料
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

// 儲存 Modal 的修改結果
function submitItemUpdate() {
  const orderId = document.getElementById('modalOrderId').value;
  const newItemName = document.getElementById('modalItemName').value;
  const newStoreName = document.getElementById('modalStoreName').value;
  const newNote = document.getElementById('modalNote').value;
  const newPrice = parseInt(document.getElementById('modalPrice').value) || 0;

  const row = document.getElementById(`order-row-${orderId}`);
  if (row) {
    row.setAttribute('data-price', newPrice);
    row.querySelector('.item-text').innerHTML = `${newItemName} <small class="text-muted">(${newStoreName} / ${newNote})</small>`;
    calculateBalance(orderId);
  }
  
  bootstrapModal.hide();
}

// 🔄 網頁載入完成後，自動幫每一列執行一次對帳初始化
document.addEventListener("DOMContentLoaded", function() {
  document.querySelectorAll('[data-order-id]').forEach(row => {
    const orderId = row.getAttribute('data-order-id');
    calculateBalance(orderId);
  });
});

</script>
<!-- ======================================================== -->
<!-- 貼在 </body> 之前：品項更新的彈出視窗 (Bootstrap Modal) -->
<!-- ======================================================== -->
<div class="modal fade" id="updateItemModal" tabindex="-1" aria-labelledby="updateModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="updateModalLabel">修改員工品項</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- 隱藏欄位，用來記錄目前正在修改哪一筆訂單 ID -->
        <input type="hidden" id="modalOrderId">
        <div class="mb-3">
          <label class="form-label">品項名稱</label>
          <input type="text" class="form-control" id="modalItemName">
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
          <label class="form-label">新單價</label>
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
<!-- ======================================================== -->
<!-- 貼在 </body> 之前：品項更新的彈出視窗 (Bootstrap Modal) -->
<!-- ======================================================== -->
<div class="modal fade" id="updateItemModal" tabindex="-1" aria-labelledby="updateModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="updateModalLabel">修改員工品項</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <!-- 隱藏欄位，用來記錄目前正在修改哪一筆訂單 ID -->
        <input type="hidden" id="modalOrderId">
        <div class="mb-3">
          <label class="form-label">品項名稱</label>
          <input type="text" class="form-control" id="modalItemName">
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
          <label class="form-label">新單價</label>
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

</body>
</html>
