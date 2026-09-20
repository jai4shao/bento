<?php
// index.php
require_once 'db.php';

// ==========================================
// 1. 自動檢查：跨週重設 (只清空訂單，不影響同學名冊)
// ==========================================
$currentIsoWeek = date('o-W');
$stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'last_reset_week'");
$stmt->execute();
$lastResetWeek = $stmt->fetchColumn();

if ($lastResetWeek !== $currentIsoWeek) {
    $pdo->exec("DELETE FROM orders");
    $upd = $pdo->prepare("UPDATE system_settings SET setting_value = ? WHERE setting_key = 'last_reset_week'");
    $upd->execute([$currentIsoWeek]);
    $updTime = $pdo->prepare("UPDATE system_settings SET setting_value = NOW() WHERE setting_key = 'last_reset_time'");
    $updTime->execute();
}

// ==========================================
// 2. 判斷起訖開放狀態 (讀取後台設定)
// ==========================================
$settings = $pdo->query("SELECT setting_key, setting_value FROM system_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$manualLock   = $settings['order_manual_lock'] ?? 'auto';
$startWeekday = (int)($settings['order_start_weekday'] ?? 1);
$startTime    = $settings['order_start_time'] ?? '08:00';
$endWeekday   = (int)($settings['order_end_weekday'] ?? 5);
$endTime      = $settings['order_end_time'] ?? '12:30';

$weekdayMap = [1 => '週一', 2 => '週二', 3 => '週三', 4 => '週四', 5 => '週五', 6 => '週六', 7 => '週日'];

$curW = (int)date('N');
$curT = date('H:i');
$curNow = sprintf("%d%s", $curW, str_replace(':', '', $curT));
$startPoint = sprintf("%d%s", $startWeekday, str_replace(':', '', $startTime));
$endPoint = sprintf("%d%s", $endWeekday, str_replace(':', '', $endTime));

$isWithinSchedule = ($curNow >= $startPoint && $curNow <= $endPoint);

if ($manualLock === 'force_open') {
    $isLocked = false;
    $statusText = '⏰ 測試開放登記中';
} elseif ($manualLock === 'force_lock') {
    $isLocked = true;
    $statusText = '⛔ 本週停課，暫停點餐登記';
} else {
    $isLocked = !$isWithinSchedule;
    if ($curNow < $startPoint) {
        $statusText = "⏳ 尚未開放 (將於 {$weekdayMap[$startWeekday]} {$startTime} 開放)";
    } elseif ($curNow > $endPoint) {
        $statusText = "⛔ 本週登記已截止 ({$weekdayMap[$endWeekday]} {$endTime} 結單)";
    } else {
        $statusText = "⏰ 登記中 (至{$weekdayMap[$endWeekday]} {$endTime})";
    }
}

// ==========================================
// 3. API 處理 (POST 請求)
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $action = $_POST['action'] ?? '';

    // 【新增名冊人員 API】
    if ($action === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '') {
            echo json_encode(['status' => 'error', 'message' => '姓名不能為空']);
            exit;
        }
        try {
            $stmt = $pdo->prepare("INSERT INTO users (name) VALUES (?)");
            $stmt->execute([$name]);
            echo json_encode(['status' => 'success', 'name' => $name]);
        } catch (PDOException $e) {
            echo json_encode(['status' => 'success', 'name' => $name]);
        }
        exit;
    }

    if ($isLocked) {
        echo json_encode(['status' => 'error', 'message' => $statusText]);
        exit;
    }

    // 提交訂單
    if ($action === 'submit_order') {
        $userName = trim($_POST['user_name'] ?? '');
        $itemId = (int)($_POST['item_id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $ice = trim($_POST['ice'] ?? '');
        $sugar = trim($_POST['sugar'] ?? '');

        if ($userName === '' || $itemId <= 0) {
            echo json_encode(['status' => 'error', 'message' => '資料不完整，請選取姓名與餐點']);
            exit;
        }

        $pdo->prepare("INSERT IGNORE INTO users (name) VALUES (?)")->execute([$userName]);

        $itemStmt = $pdo->prepare("SELECT price, item_name FROM menu_items WHERE id = ? AND is_available = 1");
        $itemStmt->execute([$itemId]);
        $item = $itemStmt->fetch();

        if (!$item) {
            echo json_encode(['status' => 'error', 'message' => '該品項不存在或已售完']);
            exit;
        }

        $fullNote = '';
        if ($ice !== '' || $sugar !== '') {
            $fullNote = trim("{$ice}/{$sugar} " . $note);
        } else {
            $fullNote = $note;
        }

        $stmt = $pdo->prepare("INSERT INTO orders (user_name, item_id, price, note) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userName, $itemId, $item['price'], $fullNote]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'update_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $newItemId = (int)($_POST['item_id'] ?? 0);
        $newNote = trim($_POST['note'] ?? '');

        $itemStmt = $pdo->prepare("SELECT price, item_name FROM menu_items WHERE id = ? AND is_available = 1");
        $itemStmt->execute([$newItemId]);
        $item = $itemStmt->fetch();

        if (!$item) {
            echo json_encode(['status' => 'error', 'message' => '品項不存在']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE orders SET item_id = ?, price = ?, note = ? WHERE id = ?");
        $stmt->execute([$newItemId, $item['price'], $newNote, $orderId]);

        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'cancel_order') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);

        echo json_encode(['status' => 'success']);
        exit;
    }
}

// ==========================================
// 4. 讀取前端資料
// ==========================================
$targetSaturday = (date('N') == 6) ? date('m/d') : date('m/d', strtotime('next Saturday'));

$activeStores = $pdo->query("SELECT * FROM stores WHERE is_active = 1 ORDER BY category ASC, id ASC")->fetchAll();

$storesData = [];
foreach ($activeStores as $st) {
    $menuStmt = $pdo->prepare("SELECT * FROM menu_items WHERE store_id = ? AND is_available = 1 ORDER BY sort_order ASC, id ASC");
    $menuStmt->execute([$st['id']]);
    $items = $menuStmt->fetchAll();
    
    $storesData[$st['id']] = [
        'info' => $st,
        'items' => $items
    ];
}

$allOrders = $pdo->query("
    SELECT o.id AS order_id, o.user_name, o.item_id, o.price, o.note, m.item_name, m.store_id 
    FROM orders o 
    JOIN menu_items m ON o.item_id = m.id 
    ORDER BY o.id ASC
")->fetchAll();

$existingUsers = $pdo->query("SELECT name FROM users ORDER BY CONVERT(name USING big5) ASC")->fetchAll(PDO::FETCH_COLUMN);

$itemOrdersMap = [];
foreach ($allOrders as $o) {
    $itemOrdersMap[$o['item_id']][] = $o;
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title><?= htmlspecialchars($targetSaturday) ?> (六) 點餐登記</title>
    <style>
        :root {
            --primary: #2563eb;
            --accent-price: #ea580c;
            --bg: #faf6ee;
            --card-bg: #ffffff;
            --border: #ebdccb;
            --text-main: #1c1917;
            --text-sub: #78716c;
            --my-tag-bg: #1d4ed8;
            --my-tag-text: #fef08a;
            --my-tag-border: #172554;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang TC", "Noto Sans TC", sans-serif; -webkit-text-size-adjust: 100%; }
        body { background-color: var(--bg); color: var(--text-main); padding-bottom: 70px; font-size: 16px; }
        
        .header { 
            background: #fffdfa; 
            border-bottom: 1px solid var(--border); 
            padding: 12px 16px; 
            position: sticky; 
            top: 0; 
            z-index: 20; 
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .header-left h1 { font-size: 1.25rem; font-weight: 800; color: #292524; }
        
        .deadline-badge { display: inline-block; margin-top: 4px; font-size: 0.8rem; padding: 3px 8px; border-radius: 6px; font-weight: 700; }
        .deadline-badge.open { background-color: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .deadline-badge.locked { background-color: #f5f5f4; color: #78716c; border: 1px solid #d6d3d1; }

        .store-tab-group {
            display: flex;
            gap: 6px;
            background: #f5eedf;
            padding: 4px;
            border-radius: 12px;
            border: 1px solid var(--border);
        }
        .store-tab-btn {
            border: none;
            background: transparent;
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.9rem;
            font-weight: 700;
            color: #78716c;
            cursor: pointer;
            transition: all 0.15s ease;
            white-space: nowrap;
        }
        .store-tab-btn.active {
            background: #ffffff;
            color: var(--primary);
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
        }

        .container { max-width: 520px; margin: 0 auto; padding: 16px; }

        .store-panel { display: none; }
        .store-panel.active { display: block; }

        .store-card { background: var(--card-bg); border-radius: 12px; padding: 14px 16px; border: 1px solid var(--border); margin-bottom: 14px; box-shadow: 0 2px 5px rgba(120, 113, 108, 0.05); }
        .store-name { font-size: 1.15rem; font-weight: 800; color: #44403c; margin-bottom: 4px; display: flex; align-items: center; justify-content: space-between; }
        .store-info { font-size: 0.85rem; color: var(--text-sub); line-height: 1.5; }

        .cat-chip { font-size: 0.75rem; padding: 2px 8px; border-radius: 4px; font-weight: 700; }
        .cat-bento { background: #fef3c7; color: #b45309; }
        .cat-drink { background: #e0e7ff; color: #4338ca; }

        .menu-list { display: flex; flex-direction: column; gap: 10px; margin-bottom: 18px; }
        .menu-item { background: var(--card-bg); border: 1.5px solid var(--border); border-radius: 14px; padding: 15px 18px; display: flex; justify-content: space-between; align-items: center; cursor: pointer; box-shadow: 0 2px 4px rgba(120, 113, 108, 0.04); -webkit-tap-highlight-color: transparent; }
        .menu-item:active { transform: scale(0.98); background: #f5eedf; }
        .menu-name { font-size: 1.15rem; font-weight: 700; color: #1c1917; }
        .menu-price { font-size: 1.25rem; font-weight: 800; color: var(--accent-price); }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.5); z-index: 100; align-items: flex-end; justify-content: center; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #ffffff; width: 100%; max-width: 520px; border-radius: 24px 24px 0 0; padding: 24px 20px 32px 20px; max-height: 90vh; overflow-y: auto; animation: slideUp 0.2s ease-out; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }

        .modal-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 1.5px solid var(--border); padding-bottom: 14px; margin-bottom: 18px; }
        .modal-title { font-size: 1.25rem; font-weight: 800; }
        .close-btn { font-size: 1.8rem; border: none; background: none; color: var(--text-sub); padding: 0 8px; cursor: pointer; }

        .user-chip-container, .spec-chip-container { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }
        .user-chip, .spec-chip { padding: 9px 15px; border-radius: 20px; background: #f5f5f4; border: 1.5px solid #e7e5e4; font-size: 0.95rem; font-weight: 600; cursor: pointer; }
        .user-chip.selected, .spec-chip.selected { background: var(--primary); color: #fff; border-color: var(--primary); font-weight: 700; }
        .user-chip.hidden { display: none; }
        .change-user-btn { font-size: 0.95rem; color: var(--primary); text-decoration: underline; background: none; border: none; cursor: pointer; margin-bottom: 14px; font-weight: 600; display: none; }

        .input-group { margin-bottom: 16px; }
        .input-group label { display: block; font-size: 1rem; font-weight: 700; margin-bottom: 8px; color: #44403c; }
        .input-text, .input-select { width: 100%; padding: 13px 14px; border-radius: 10px; border: 1.5px solid var(--border); font-size: 1.05rem; outline: none; background: #fff; }

        .btn-submit { width: 100%; padding: 16px; background: var(--primary); color: #fff; border: none; border-radius: 12px; font-size: 1.15rem; font-weight: 800; cursor: pointer; box-shadow: 0 3px 6px rgba(37, 99, 235, 0.25); }
        .btn-danger { width: 100%; padding: 14px; background: #fff; color: #dc2626; border: 1.5px solid #dc2626; border-radius: 12px; font-size: 1.05rem; font-weight: 700; cursor: pointer; margin-top: 10px; }

        .summary-card { background: var(--card-bg); border-radius: 16px; padding: 18px; border: 1px solid var(--border); margin-top: 26px; box-shadow: 0 2px 6px rgba(120, 113, 108, 0.05); }
        .summary-row { padding: 12px 0; border-bottom: 1px dashed var(--border); }
        .summary-row:last-child { border-bottom: none; }
        .item-line { display: flex; align-items: center; font-weight: 800; font-size: 1.05rem; color: #1c1917; }
        .item-qty-badge { display: inline-flex; align-items: center; justify-content: center; min-width: 28px; height: 24px; background: var(--accent-price); color: #ffffff; font-size: 0.9rem; border-radius: 12px; padding: 0 8px; margin-right: 10px; font-weight: 800; }
        .users-list { margin-top: 8px; padding-left: 38px; display: flex; flex-wrap: wrap; gap: 8px; }

        .user-tag { background: #f5f5f4; border: 1px solid #e7e5e4; border-radius: 8px; padding: 6px 12px; font-size: 0.95rem; color: #44403c; display: inline-flex; align-items: center; font-weight: 600; }
        .user-tag.is-me { background: var(--my-tag-bg); color: var(--my-tag-text); border: 1.5px solid var(--my-tag-border); font-weight: 800; box-shadow: 0 3px 6px rgba(29, 78, 216, 0.3); cursor: pointer; padding: 6px 14px; }
        .user-note { font-size: 0.85rem; margin-left: 4px; font-weight: normal; opacity: 0.95; }

        /* 新增人員輸入列美化 */
        .add-user-container {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            align-items: center;
        }

        .add-user-input {
            flex: 1;
            padding: 12px 14px;
            border-radius: 12px;
            border: 1.5px solid var(--border);
            font-size: 1rem;
            outline: none;
            background: #fffdf9;
            color: var(--text-main);
            transition: border-color 0.2s, box-shadow 0.2s;
        }

        .add-user-input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        .btn-add-user {
            padding: 12px 18px;
            background: var(--primary);
            color: #ffffff;
            border: none;
            border-radius: 12px;
            font-size: 0.95rem;
            font-weight: 700;
            cursor: pointer;
            white-space: nowrap;
            box-shadow: 0 3px 6px rgba(37, 99, 235, 0.2);
            transition: all 0.15s ease;
            -webkit-tap-highlight-color: transparent;
        }

        .btn-add-user:active {
            transform: scale(0.96);
            background: #1d4ed8;
        }
.deadline-badge { 
    display: inline-block; 
    margin-top: 4px; 
    font-size: 0.8rem; 
    padding: 3px 8px; 
    border-radius: 6px; 
    font-weight: 700; 
    cursor: pointer;
    -webkit-tap-highlight-color: transparent;
    transition: transform 0.1s;
}
.deadline-badge:active {
    transform: scale(0.96); /* 點擊時有微微按壓感 */
}
/* 底部導覽容器 */
.footer-nav {
    margin-top: 36px;
    margin-bottom: 24px;
    display: flex;
    justify-content: center;
}

/* 同學自介連結按鈕 */
.btn-intro-link {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    background: #ffffff;
    color: #1c1917;
    border: 1.5px solid #ebdccb;
    padding: 10px 22px;
    border-radius: 999px; /* 膠囊圓角 */
    text-decoration: none;
    font-weight: 700;
    font-size: 0.95rem;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.04);
    transition: all 0.2s ease;
}

.btn-intro-link:hover {
    background: #fdfaf5;
    border-color: #2563eb;
    color: #2563eb;
    transform: translateY(-2px);
    box-shadow: 0 4px 10px rgba(37, 99, 235, 0.12);
}

.btn-intro-link .icon {
    font-size: 1.1rem;
}

.btn-intro-link svg {
    transition: transform 0.2s ease;
}

.btn-intro-link:hover svg {
    transform: translateX(3px); /* 滑鼠懸停時箭頭微幅向右移 */
}
    </style>
</head>
<body>

<div class="header">
    <div class="header-left">
        <h1><?= htmlspecialchars($targetSaturday) ?> (六) 午餐</h1>
        <!-- 將狀態標籤直接做成連結，外觀維持原樣，點擊直達 admin.php -->
        <a href="admin.php" class="deadline-badge <?= $isLocked ? 'locked' : 'open' ?>" style="text-decoration: none;">
            <?= htmlspecialchars($statusText) ?>
        </a>
    </div>

    <!-- 右側店家切換 Tab (移除齒輪，避免同學誤觸) -->
    <?php if (count($activeStores) > 1): ?>
        <div class="store-tab-group">
            <?php 
            $firstTab = true;
            foreach ($activeStores as $st): 
            ?>
                <button type="button" class="store-tab-btn <?= $firstTab ? 'active' : '' ?>" 
                        onclick="switchStoreTab(<?= $st['id'] ?>, this)">
                    <?= ($st['category'] === 'drink') ? '🧋 飲料' : '🍱 便當' ?>
                </button>
            <?php 
                $firstTab = false;
            endforeach; 
            ?>
        </div>
    <?php endif; ?>
</div>

<div class="container">
    <?php if (empty($activeStores)): ?>
        <div class="store-card">目前無啟用的店家，請管理員至後台開啟。</div>
    <?php else: ?>
        <?php 
        $firstPanel = true;
        foreach ($storesData as $storeId => $sData): 
        ?>
            <div class="store-panel <?= $firstPanel ? 'active' : '' ?>" id="store-panel-<?= $storeId ?>">
                <div class="store-card">
                    <div class="store-name">
                        <span><?= htmlspecialchars($sData['info']['name']) ?></span>
                        <span class="cat-chip <?= ($sData['info']['category'] === 'drink') ? 'cat-drink' : 'cat-bento' ?>">
                            <?= ($sData['info']['category'] === 'drink') ? '🧋 飲料專區' : '🍱 便當專區' ?>
                        </span>
                    </div>
                    <div class="store-info">📞 電話：<?= htmlspecialchars($sData['info']['phone'] ?? '無') ?></div>
                    <div class="store-info">📍 地址：<?= htmlspecialchars($sData['info']['address'] ?? '無') ?></div>
                </div>

                <div class="menu-list">
                    <?php foreach ($sData['items'] as $item): ?>
                        <div class="menu-item" onclick="openOrderModal(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['item_name'])) ?>', <?= $item['price'] ?>, '<?= $sData['info']['category'] ?>')">
                            <span class="menu-name"><?= htmlspecialchars($item['item_name']) ?></span>
                            <span class="menu-price">$<?= $item['price'] ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php 
            $firstPanel = false;
        endforeach; 
        ?>
    <?php endif; ?>

    <!-- 底部：已登記餐點彙整 -->
    <div class="summary-card">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2 style="font-size:1.2rem; font-weight:800; color:#292524;">已登記餐點彙整</h2>
            <small style="color:var(--text-sub); font-size:0.75rem;">點擊 <span style="background:var(--my-tag-bg); color:var(--my-tag-text); padding:2px 5px; border-radius:4px;">自己名字</span> 可修改/取消</small>
        </div>

        <?php 
        $hasAnyOrderOverall = false;
        foreach ($storesData as $storeId => $sData): 
            $storeHasOrder = false;
        ?>
            <div style="font-weight:800; font-size:1.05rem; margin:14px 0 6px 0; color:var(--primary);">
                <?= ($sData['info']['category'] === 'drink') ? '🧋 ' : '🍱 ' ?>
                <?= htmlspecialchars($sData['info']['name']) ?>
            </div>

            <?php 
            foreach ($sData['items'] as $item): 
                $orderedList = $itemOrdersMap[$item['id']] ?? [];
                if (!empty($orderedList)): 
                    $storeHasOrder = true;
                    $hasAnyOrderOverall = true;
            ?>
                <div class="summary-row">
                    <div class="item-line">
                        <span class="item-qty-badge"><?= count($orderedList) ?></span>
                        <span><?= htmlspecialchars($item['item_name']) ?></span>
                        <span style="font-size:0.85rem; color:var(--text-sub); margin-left:6px; font-weight:normal;">($<?= $item['price'] ?>)</span>
                    </div>
                    <div class="users-list">
                        <?php foreach ($orderedList as $o): ?>
                            <span class="user-tag" 
                                  data-username="<?= htmlspecialchars($o['user_name']) ?>"
                                  data-orderid="<?= $o['order_id'] ?>"
                                  data-itemid="<?= $o['item_id'] ?>"
                                  data-note="<?= htmlspecialchars($o['note']) ?>"
                                  onclick="handleTagClick(this)">
                                <?= htmlspecialchars($o['user_name']) ?>
                                <?php if ($o['note']): ?>
                                    <span class="user-note">(<?= htmlspecialchars($o['note']) ?>)</span>
                                <?php endif; ?>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php 
                endif;
            endforeach; 

            if (!$storeHasOrder):
            ?>
                <div style="color:#a8a29e; font-size:0.85rem; padding:4px 0 10px 0;">此店家尚無人登記</div>
            <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$hasAnyOrderOverall): ?>
            <div style="text-align:center; color:#94a3b8; padding:15px 0; font-size:0.95rem;">
                目前尚無人登記，點擊上方菜色即可登記！
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- 新增登記 Modal -->
<div class="modal-overlay" id="orderModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <div class="modal-title" id="modalItemTitle">選擇餐點</div>
                <div style="font-size:0.95rem; color:var(--accent-price); font-weight:800;" id="modalItemPrice">$0</div>
            </div>
            <button class="close-btn" onclick="closeModal('orderModal')">&times;</button>
        </div>

        <input type="hidden" id="selectedItemId" value="">
        <input type="hidden" id="selectedUserName" value="">
        <input type="hidden" id="selectedIce" value="去冰(小碎冰)">
        <input type="hidden" id="selectedSugar" value="3分糖">

        <div class="input-group">
            <label>訂餐人是誰？</label>
            <div class="user-chip-container" id="userChipsContainer">
                <?php foreach ($existingUsers as $name): ?>
                    <div class="user-chip" data-name="<?= htmlspecialchars($name) ?>" onclick="selectUser('<?= htmlspecialchars(addslashes($name)) ?>')">
                        <?= htmlspecialchars($name) ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="change-user-btn" id="changeUserBtn" onclick="resetUserSelection()">更換身分</button>
            <div class="add-user-container" id="addUserRow">
                <input type="text" id="newUserName" class="add-user-input" placeholder="名單沒有你？輸入姓名加入">
                <button type="button" class="btn-add-user" onclick="addNewUserFromInput()">
                    ➕ 加入名單
                </button>
            </div>
        </div>

        <!-- 飲料專屬冰量/甜度 -->
        <div id="drinkSpecBlock" style="display:none;">
            <div class="input-group">
                <label>❄️ 冰量選擇</label>
                <div class="spec-chip-container" id="iceGroup">
                    <div class="spec-chip" onclick="setSpec('ice', '常溫', this)">常溫</div>
                    <div class="spec-chip" onclick="setSpec('ice', '完全去冰', this)">完全去冰</div>
                    <div class="spec-chip selected" onclick="setSpec('ice', '去冰(小碎冰)', this)">去冰(小碎冰)</div>
                    <div class="spec-chip" onclick="setSpec('ice', '半冰', this)">半冰</div>
                    <div class="spec-chip" onclick="setSpec('ice', '正常冰', this)">正常冰</div>
                </div>
            </div>

            <div class="input-group">
                <label>🍬 甜度選擇</label>
                <div class="spec-chip-container" id="sugarGroup">
                    <div class="spec-chip" onclick="setSpec('sugar', '無糖', this)">無糖</div>
                    <div class="spec-chip" onclick="setSpec('sugar', '1分糖', this)">1分糖</div>
                    <div class="spec-chip selected" onclick="setSpec('sugar', '3分糖', this)">3分糖</div>
                    <div class="spec-chip" onclick="setSpec('sugar', '半糖', this)">半糖</div>
                    <div class="spec-chip" onclick="setSpec('sugar', '少糖(7-8分)', this)">少糖(7-8分)</div>
                    <div class="spec-chip" onclick="setSpec('sugar', '正常甜', this)">正常甜</div>
                </div>
            </div>
        </div>

        <div class="input-group">
            <label>備註需求 (選填)</label>
            <input type="text" id="orderNote" class="input-text" placeholder="例如：飯少、不辣、自備環保杯">
        </div>

        <button type="button" class="btn-submit" onclick="submitNewOrder()">確認登記</button>
    </div>
</div>

<!-- 修改 / 取消 Modal -->
<div class="modal-overlay" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <div>
                <div class="modal-title">修改餐點</div>
                <div style="font-size:0.95rem; color:var(--primary); font-weight:800;" id="editModalUserName"></div>
            </div>
            <button class="close-btn" onclick="closeModal('editModal')">&times;</button>
        </div>

        <input type="hidden" id="editOrderId" value="">

        <div class="input-group">
            <label>更換品項</label>
            <select id="editItemId" class="input-select">
                <?php foreach ($storesData as $stId => $sData): ?>
                    <optgroup label="<?= htmlspecialchars($sData['info']['name']) ?>">
                        <?php foreach ($sData['items'] as $item): ?>
                            <option value="<?= $item['id'] ?>"><?= htmlspecialchars($item['item_name']) ?> ($<?= $item['price'] ?>)</option>
                        <?php endforeach; ?>
                    </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="input-group">
            <label>備註需求</label>
            <input type="text" id="editOrderNote" class="input-text" placeholder="例如：去冰/無糖、飯少">
        </div>

        <button type="button" class="btn-submit" onclick="saveOrderEdit()">儲存修改</button>
        <button type="button" class="btn-danger" onclick="cancelOrder()">取消此品項 (不訂了)</button>
    </div>
</div>
<!-- 底部導覽按鈕區 -->
<div class="footer-nav">
    <a href="https://594126010.xyz/bento/users.php" class="btn-intro-link">
        <span class="icon">👋</span>
        <span>同學自介</span>
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
            <polyline points="9 18 15 12 9 6"></polyline>
        </svg>
    </a>
</div>
<script>
const isSystemLocked = <?= $isLocked ? 'true' : 'false' ?>;
let currentItem = { id: 0, name: '', price: 0, category: 'bento' };

document.addEventListener('DOMContentLoaded', () => {
    refreshMyTags();

    document.querySelectorAll('.modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    });
});

function switchStoreTab(storeId, btn) {
    document.querySelectorAll('.store-tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    document.querySelectorAll('.store-panel').forEach(p => p.classList.remove('active'));
    const targetPanel = document.getElementById('store-panel-' + storeId);
    if (targetPanel) {
        targetPanel.classList.add('active');
    }
}

function refreshMyTags() {
    const savedName = localStorage.getItem('bento_user_name');
    if (!savedName) return;

    document.querySelectorAll('.user-tag').forEach(tag => {
        if (tag.getAttribute('data-username') === savedName) {
            tag.classList.add('is-me');
            if (!tag.innerText.includes('✏️')) {
                tag.innerHTML = '✏️ ' + tag.innerHTML;
            }
        }
    });
}

function setSpec(type, value, element) {
    if (type === 'ice') {
        document.getElementById('selectedIce').value = value;
        document.querySelectorAll('#iceGroup .spec-chip').forEach(c => c.classList.remove('selected'));
    } else {
        document.getElementById('selectedSugar').value = value;
        document.querySelectorAll('#sugarGroup .spec-chip').forEach(c => c.classList.remove('selected'));
    }
    element.classList.add('selected');
}

function handleTagClick(element) {
    if (isSystemLocked) return alert('本週訂單已截止，無法修改！');

    const savedName = localStorage.getItem('bento_user_name');
    const orderUser = element.getAttribute('data-username');

    if (!savedName || savedName !== orderUser) {
        return alert(`這是【${orderUser}】登記的品項，您目前身分是【${savedName || '未選'}】。`);
    }

    document.getElementById('editOrderId').value = element.getAttribute('data-orderid');
    document.getElementById('editItemId').value = element.getAttribute('data-itemid');
    document.getElementById('editOrderNote').value = element.getAttribute('data-note') || '';
    document.getElementById('editModalUserName').innerText = `登記人：${orderUser}`;
    document.getElementById('editModal').classList.add('active');
}

function openOrderModal(id, name, price, category) {
    if (isSystemLocked) return alert('本週訂單已截止囉！');

    currentItem = { id, name, price, category };
    document.getElementById('selectedItemId').value = id;
    document.getElementById('modalItemTitle').innerText = name;
    document.getElementById('modalItemPrice').innerText = `$${price}`;

    const drinkBlock = document.getElementById('drinkSpecBlock');
    if (category === 'drink') {
        drinkBlock.style.display = 'block';
    } else {
        drinkBlock.style.display = 'none';
    }

    const savedName = localStorage.getItem('bento_user_name');
    if (savedName) selectUser(savedName);

    document.getElementById('orderModal').classList.add('active');
}

function closeModal(id) {
    document.getElementById(id).classList.remove('active');
}

function selectUser(name) {
    const chips = document.querySelectorAll('.user-chip');
    let matched = false;

    chips.forEach(chip => {
        if (chip.getAttribute('data-name') === name) {
            chip.classList.add('selected');
            chip.classList.remove('hidden');
            matched = true;
        } else {
            chip.classList.remove('selected');
            chip.classList.add('hidden');
        }
    });

    if (matched) {
        document.getElementById('selectedUserName').value = name;
        document.getElementById('changeUserBtn').style.display = 'inline-block';
        document.getElementById('addUserRow').style.display = 'flex'; 
        localStorage.setItem('bento_user_name', name);
    }
}

function resetUserSelection() {
    document.getElementById('selectedUserName').value = '';
    document.querySelectorAll('.user-chip').forEach(chip => {
        chip.classList.remove('selected');
        chip.classList.remove('hidden');
    });
    document.getElementById('changeUserBtn').style.display = 'none';
    document.getElementById('addUserRow').style.display = 'flex';
}

function addNewUserFromInput() {
    const nameInput = document.getElementById('newUserName');
    const name = nameInput.value.trim();
    if (!name) return alert('請輸入姓名');

    const fd = new FormData();
    fd.append('action', 'add_user');
    fd.append('name', name);

    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') {
            localStorage.setItem('bento_user_name', name);
            document.getElementById('selectedUserName').value = name;

            const container = document.getElementById('userChipsContainer');
            const chip = document.createElement('div');
            chip.className = 'user-chip';
            chip.setAttribute('data-name', name);
            chip.innerText = name;
            chip.onclick = () => selectUser(name);

            const existingChips = Array.from(container.querySelectorAll('.user-chip'));
            existingChips.push(chip);
            existingChips.sort((a, b) => a.getAttribute('data-name').localeCompare(b.getAttribute('data-name'), 'zh-Hant'));

            container.innerHTML = '';
            existingChips.forEach(c => container.appendChild(c));

            nameInput.value = '';
            selectUser(name);
        } else {
            alert(res.message);
        }
    })
    .catch(() => alert('新增人員失敗，請檢查網路連線'));
}

function submitNewOrder() {
    const userName = document.getElementById('selectedUserName').value;
    const itemId = document.getElementById('selectedItemId').value;
    const note = document.getElementById('orderNote').value.trim();
    const isDrink = (currentItem.category === 'drink');
    const ice = isDrink ? document.getElementById('selectedIce').value : '';
    const sugar = isDrink ? document.getElementById('selectedSugar').value : '';

    if (!userName) return alert('請先點選或輸入你的名字！');
    if (!itemId) return alert('請先選取餐點品項！');

    let specSummary = isDrink ? `\n規格：${ice} / ${sugar}` : '';
    if (!confirm(`確認登記？\n\n姓名：${userName}\n品項：${currentItem.name} ($${currentItem.price})${specSummary}\n備註：${note || '無'}`)) {
        return;
    }

    const fd = new FormData();
    fd.append('action', 'submit_order');
    fd.append('user_name', userName);
    fd.append('item_id', itemId);
    fd.append('note', note);
    fd.append('ice', ice);
    fd.append('sugar', sugar);

    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') location.reload();
        else alert(res.message);
    })
    .catch(() => alert('點餐送出失敗'));
}

function saveOrderEdit() {
    const orderId = document.getElementById('editOrderId').value;
    const itemId = document.getElementById('editItemId').value;
    const note = document.getElementById('editOrderNote').value.trim();

    const fd = new FormData();
    fd.append('action', 'update_order');
    fd.append('order_id', orderId);
    fd.append('item_id', itemId);
    fd.append('note', note);

    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') location.reload();
        else alert(res.message);
    });
}

function cancelOrder() {
    if (!confirm('確定要取消這筆品項嗎？')) return;

    const orderId = document.getElementById('editOrderId').value;
    const fd = new FormData();
    fd.append('action', 'cancel_order');
    fd.append('order_id', orderId);

    fetch('index.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.status === 'success') location.reload();
        else alert(res.message);
    });
}
</script>
</body>
</html>