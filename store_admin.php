<?php
// store_admin.php
require_once 'db.php';

$msg = '';
$errorMsg = '';

// 處理 POST 請求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // 1. 新增店家
    if ($action === 'add_store') {
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'bento';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($name !== '') {
            $stmt = $pdo->prepare("INSERT INTO stores (name, category, phone, address, is_active) VALUES (?, ?, ?, ?, 0)");
            $stmt->execute([$name, $category, $phone, $address]);
            $msg = "店家【{$name}】新增成功！";
        }
    }

    // 2. 【新增】修改店家資訊 (店名、電話、地址、類別)
    if ($action === 'edit_store') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $category = $_POST['category'] ?? 'bento';
        $phone = trim($_POST['phone'] ?? '');
        $address = trim($_POST['address'] ?? '');

        if ($storeId > 0 && $name !== '') {
            $stmt = $pdo->prepare("UPDATE stores SET name = ?, category = ?, phone = ?, address = ? WHERE id = ?");
            $stmt->execute([$name, $category, $phone, $address, $storeId]);
            $msg = "店家【{$name}】資料已更新！";
        }
    }

    // 3. 【新增】執行 AI 產生的 SQL 語法 (免開 phpMyAdmin)
    if ($action === 'execute_sql') {
        $sqlScript = trim($_POST['sql_script'] ?? '');
        if (!empty($sqlScript)) {
            try {
                // 支援一次執行多行 SQL (例如含 SET @變數、INSERT INTO 等)
                $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, true);
                $pdo->exec($sqlScript);
                $msg = '✨ SQL 語法已成功執行！店家與菜單已自動新增完畢。';
            } catch (PDOException $e) {
                $errorMsg = '執行失敗：' . $e->getMessage();
            }
        } else {
            $errorMsg = '請貼上要執行的 SQL 語法。';
        }
    }

    // 4. 切換店家啟用狀態
    if ($action === 'toggle_store_active') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $curStatus = (int)($_POST['cur_status'] ?? 0);
        $newStatus = $curStatus ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE stores SET is_active = ? WHERE id = ?");
        $stmt->execute([$newStatus, $storeId]);
        header('Location: store_admin.php?selected_store=' . $storeId);
        exit;
    }

    // 5. 刪除店家
    if ($action === 'delete_store') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM stores WHERE id = ?");
        $stmt->execute([$storeId]);
        $msg = '店家已刪除！';
    }

    // 6. 手動新增菜單品項
    if ($action === 'add_item') {
        $storeId = (int)($_POST['store_id'] ?? 0);
        $itemName = trim($_POST['item_name'] ?? '');
        $price = (int)($_POST['price'] ?? 0);

        if ($storeId > 0 && $itemName !== '' && $price > 0) {
            $stmt = $pdo->prepare("INSERT INTO menu_items (store_id, item_name, price, is_available) VALUES (?, ?, ?, 1)");
            $stmt->execute([$storeId, $itemName, $price]);
            $msg = "品項【{$itemName}】新增成功！";
        }
    }

    // 7. 修改菜單品項名稱與價格
    if ($action === 'edit_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $itemName = trim($_POST['item_name'] ?? '');
        $price = (int)($_POST['price'] ?? 0);
        $storeId = (int)($_POST['store_id'] ?? 0);

        if ($itemId > 0 && $itemName !== '' && $price > 0) {
            $stmt = $pdo->prepare("UPDATE menu_items SET item_name = ?, price = ? WHERE id = ?");
            $stmt->execute([$itemName, $price, $itemId]);
            $msg = "品項【{$itemName}】已更新為 \${$price} 元！";
        }
    }

    // 8. 切換品項供應狀態
    if ($action === 'toggle_item_available') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $curStatus = (int)($_POST['cur_status'] ?? 1);
        $newStatus = $curStatus ? 0 : 1;

        $stmt = $pdo->prepare("UPDATE menu_items SET is_available = ? WHERE id = ?");
        $stmt->execute([$newStatus, $itemId]);
        header('Location: store_admin.php?selected_store=' . (int)$_POST['store_id']);
        exit;
    }

    // 9. 刪除品項
    if ($action === 'delete_item') {
        $itemId = (int)($_POST['item_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM menu_items WHERE id = ?");
        $stmt->execute([$itemId]);
        header('Location: store_admin.php?selected_store=' . (int)$_POST['store_id']);
        exit;
    }
}

// 讀取所有店家
$stores = $pdo->query("SELECT * FROM stores ORDER BY is_active DESC, id ASC")->fetchAll();

$selectedStoreId = (int)($_GET['selected_store'] ?? 0);
if ($selectedStoreId === 0 && !empty($stores)) {
    $selectedStoreId = $stores[0]['id'];
}

// 讀取菜單品項
$menuItems = [];
if ($selectedStoreId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM menu_items WHERE store_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$selectedStoreId]);
    $menuItems = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>店家與菜單管理</title>
    <style>
        :root {
            --primary: #2563eb;
            --accent: #ea580c;
            --danger: #dc2626;
            --success: #16a34a;
            --purple: #7c3aed;
            --bg: #faf6ee;
            --card: #ffffff;
            --border: #ebdccb;
        }
        * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: var(--bg); color: #1c1917; padding: 20px; max-width: 1020px; margin: 0 auto; }
        
        .nav-bar { display: flex; gap: 16px; margin-bottom: 20px; }
        .nav-link { color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.95rem; }
        
        .card { background: var(--card); border: 1.5px solid var(--border); border-radius: 14px; padding: 20px; margin-bottom: 24px; box-shadow: 0 2px 5px rgba(0,0,0,0.03); }
        .grid-layout { display: grid; grid-template-columns: 1.1fr 1.5fr; gap: 20px; }
        @media (max-width: 860px) { .grid-layout { grid-template-columns: 1fr; } }

        h2 { font-size: 1.25rem; font-weight: 800; margin-bottom: 14px; }
        
        .store-item {
            padding: 12px 14px;
            border: 1px solid var(--border);
            border-radius: 10px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: #fff;
        }
        .store-item.active { border-color: var(--primary); background: #eff6ff; }
        .store-title { font-weight: 700; font-size: 1.05rem; }
        
        .cat-badge { font-size: 0.75rem; padding: 2px 6px; border-radius: 4px; font-weight: 700; margin-left: 4px; }
        .cat-bento { background: #fef3c7; color: #b45309; }
        .cat-drink { background: #e0e7ff; color: #4338ca; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 12px 10px; text-align: left; border-bottom: 1px solid var(--border); vertical-align: middle; }
        th { background: #f5eedf; font-weight: 700; }
        
        .btn { padding: 8px 12px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; font-size: 0.85rem; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-purple { background: var(--purple); color: #fff; }
        .btn-outline { background: transparent; border: 1px solid var(--border); }

        .btn-toggle { padding: 5px 10px; border-radius: 20px; font-size: 0.8rem; font-weight: 700; border: none; cursor: pointer; }
        .btn-toggle.on { background: #dcfce7; color: #15803d; }
        .btn-toggle.off { background: #f1f5f9; color: #64748b; }

        .form-row { display: flex; gap: 8px; margin-top: 10px; }
        .input-text, .input-select, textarea { flex: 1; padding: 9px 12px; border: 1.5px solid var(--border); border-radius: 8px; outline: none; background:#fff; font-family: inherit; }
        .status-chip { display: inline-block; padding: 3px 8px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; }
        .status-chip.yes { background: #dcfce7; color: #15803d; }
        .status-chip.no { background: #fee2e2; color: #b91c1c; }

        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0, 0, 0, 0.45); z-index: 100; align-items: center; justify-content: center; padding: 16px; }
        .modal-overlay.active { display: flex; }
        .modal-content { background: #fff; width: 100%; max-width: 460px; border-radius: 14px; padding: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); max-height: 90vh; overflow-y: auto; }
    </style>
</head>
<body>

<div class="nav-bar">
    <a href="index.php" class="nav-link">&larr; 前往點餐前台</a>
    <a href="admin.php" class="nav-link">訂單彙整與核銷後台 &rarr;</a>
</div>

<h1>店家與菜單管理</h1>

<?php if ($msg): ?>
    <div style="padding:12px; background:#dcfce7; color:#15803d; border-radius:8px; margin: 15px 0; font-weight:700;">
        <?= htmlspecialchars($msg) ?>
    </div>
<?php endif; ?>
<?php if ($errorMsg): ?>
    <div style="padding:12px; background:#fee2e2; color:#b91c1c; border-radius:8px; margin: 15px 0; font-weight:700;">
        <?= htmlspecialchars($errorMsg) ?>
    </div>
<?php endif; ?>

<!-- 💡 SQL 一鍵匯入面板 (含 Prompt 一鍵複製) -->
<div class="card" style="border: 1.5px solid #c4b5fd; background: #faf5ff;">
    <div style="display:flex; justify-content:space-between; align-items:center; cursor:pointer;" onclick="toggleSqlBox()">
        <h2 style="margin:0; color:var(--purple); font-size:1.1rem;">
            ✨ 一鍵匯入 AI 產生的 SQL 菜單語法 (免開 phpMyAdmin)
        </h2>
        <span id="sqlArrow" style="font-weight:bold; color:var(--purple);">▼ 展開</span>
    </div>

    <div id="sqlBox" style="display:none; margin-top:14px;">
        <p style="font-size:0.85rem; color:#6b21a8; margin-bottom:8px;">
            請將對話框中為您產生的整段 SQL 語法直接貼在下方，點擊送出即可自動寫入資料庫：
        </p>
        <form method="POST">
            <input type="hidden" name="action" value="execute_sql">
            <textarea name="sql_script" rows="6" style="width:100%; font-family:monospace; font-size:0.85rem;" placeholder="貼上 INSERT INTO `stores` ... 語法" required></textarea>
            
            <!-- 下方操作列：左側為 Prompt 一鍵複製按鈕，右側為執行匯入按鈕 -->
            <div style="margin-top:10px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
                <div style="display:flex; gap:6px; align-items:center;">
                    <span style="font-size:0.8rem; color:#6b21a8; font-weight:700;">發給 AI 提示詞：</span>
                    <button type="button" class="btn btn-outline" style="border-color:#c4b5fd; color:#6b21a8; font-size:0.8rem; padding:5px 9px;" onclick="copyPrompt('bento', this)">
                        📋 複製便當 Prompt
                    </button>
                    <button type="button" class="btn btn-outline" style="border-color:#c4b5fd; color:#6b21a8; font-size:0.8rem; padding:5px 9px;" onclick="copyPrompt('drink', this)">
                        📋 複製飲料 Prompt
                    </button>
                </div>
                <div>
                    <button type="submit" class="btn btn-purple" onclick="return confirm('確定要執行此段 SQL 匯入語法嗎？');">
                        🚀 立即執行匯入
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="grid-layout">
    <!-- 左欄：店家清單 -->
    <div>
        <div class="card">
            <h2>店家列表 (可多選開啟)</h2>
            <?php foreach ($stores as $s): ?>
                <div class="store-item <?= ($s['id'] == $selectedStoreId) ? 'active' : '' ?>">
                    <div>
                        <div class="store-title">
                            <a href="store_admin.php?selected_store=<?= $s['id'] ?>" style="text-decoration:none; color:inherit;">
                                <?= htmlspecialchars($s['name']) ?>
                            </a>
                            <span class="cat-badge <?= ($s['category'] === 'drink') ? 'cat-drink' : 'cat-bento' ?>">
                                <?= ($s['category'] === 'drink') ? '🧋 飲料' : '🍱 便當' ?>
                            </span>
                        </div>
                        <div style="font-size:0.8rem; color:#78716c; margin-top:4px;">
                            📞 <?= htmlspecialchars($s['phone'] ?: '未填電話') ?><br>
                            📍 <?= htmlspecialchars($s['address'] ?: '未填地址/備註') ?>
                        </div>
                    </div>
                    <div style="display:flex; flex-direction:column; gap:6px; align-items:flex-end;">
                        <div style="display:flex; gap:6px;">
                            <!-- 編輯店家資訊按鈕 -->
                            <button type="button" class="btn btn-outline" style="padding:4px 8px; font-size:0.8rem;"
                                    onclick="openEditStoreModal(<?= $s['id'] ?>, '<?= htmlspecialchars(addslashes($s['name'])) ?>', '<?= $s['category'] ?>', '<?= htmlspecialchars(addslashes($s['phone'] ?? '')) ?>', '<?= htmlspecialchars(addslashes($s['address'] ?? '')) ?>')">
                                ✏️ 編輯
                            </button>

                            <form method="POST" style="margin:0;">
                                <input type="hidden" name="action" value="toggle_store_active">
                                <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
                                <input type="hidden" name="cur_status" value="<?= $s['is_active'] ?>">
                                <button type="submit" class="btn-toggle <?= $s['is_active'] ? 'on' : 'off' ?>">
                                    <?= $s['is_active'] ? '供餐中' : '停用' ?>
                                </button>
                            </form>
                        </div>

                        <form method="POST" onsubmit="return confirm('確定要刪除店家【<?= htmlspecialchars($s['name']) ?>】及整份菜單嗎？');" style="margin:0;">
                            <input type="hidden" name="action" value="delete_store">
                            <input type="hidden" name="store_id" value="<?= $s['id'] ?>">
                            <button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:0.75rem; text-decoration:underline;">刪除店家</button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <!-- 手動新增店家表單 -->
            <form method="POST" style="margin-top: 20px; padding-top: 15px; border-top: 1px dashed var(--border);">
                <input type="hidden" name="action" value="add_store">
                <h3 style="font-size: 1rem; margin-bottom: 8px;">➕ 手動新增店家</h3>
                <input type="text" name="name" class="input-text" style="width:100%; margin-bottom:8px;" placeholder="店家名稱 (必填)" required>
                
                <div style="margin-bottom:8px;">
                    <label style="font-size:0.85rem; font-weight:700; display:block; margin-bottom:4px;">店家類別</label>
                    <select name="category" class="input-select" style="width:100%;">
                        <option value="bento">🍱 便當店家 (餐點/備註)</option>
                        <option value="drink">🧋 飲料店家 (自動啟用冰塊/甜度)</option>
                    </select>
                </div>

                <input type="text" name="phone" class="input-text" style="width:100%; margin-bottom:8px;" placeholder="訂餐電話">
                <input type="text" name="address" class="input-text" style="width:100%; margin-bottom:10px;" placeholder="地址或外送備註">
                <button type="submit" class="btn btn-primary" style="width:100%;">新增店家</button>
            </form>
        </div>
    </div>

    <!-- 右欄：菜單管理 -->
    <div>
        <div class="card">
            <?php 
            $curStoreObj = null;
            foreach ($stores as $s) {
                if ($s['id'] == $selectedStoreId) { $curStoreObj = $s; break; }
            }
            ?>
            <h2>
                菜單品項管理
                <?php if ($curStoreObj): ?>
                    <span style="font-size:0.95rem; font-weight:normal; color:#78716c;">
                        (正在管理：<strong><?= htmlspecialchars($curStoreObj['name']) ?></strong>)
                    </span>
                <?php endif; ?>
            </h2>

            <?php if ($selectedStoreId > 0): ?>
                <form method="POST" class="form-row" style="margin-bottom: 16px;">
                    <input type="hidden" name="action" value="add_item">
                    <input type="hidden" name="store_id" value="<?= $selectedStoreId ?>">
                    <input type="text" name="item_name" class="input-text" placeholder="品項名稱 (如: 珍珠鮮奶茶)" required>
                    <input type="number" name="price" class="input-text" style="max-width: 100px;" placeholder="價格" required>
                    <button type="submit" class="btn btn-primary">新增品項</button>
                </form>

                <table>
                    <thead>
                        <tr>
                            <th>品項名稱</th>
                            <th>單價</th>
                            <th>供應狀態</th>
                            <th style="width: 130px;">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($menuItems)): ?>
                            <tr><td colspan="4" style="text-align:center; color:#a8a29e;">目前尚無菜單品項。</td></tr>
                        <?php else: ?>
                            <?php foreach ($menuItems as $m): ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($m['item_name']) ?></strong></td>
                                    <td>$<?= $m['price'] ?></td>
                                    <td>
                                        <form method="POST" style="margin:0;">
                                            <input type="hidden" name="action" value="toggle_item_available">
                                            <input type="hidden" name="store_id" value="<?= $selectedStoreId ?>">
                                            <input type="hidden" name="item_id" value="<?= $m['id'] ?>">
                                            <input type="hidden" name="cur_status" value="<?= $m['is_available'] ?>">
                                            <button type="submit" style="background:none; border:none; cursor:pointer;" title="點擊切換狀態">
                                                <span class="status-chip <?= $m['is_available'] ? 'yes' : 'no' ?>">
                                                    <?= $m['is_available'] ? '供應中' : '暫停供應' ?>
                                                </span>
                                            </button>
                                        </form>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 8px; align-items: center;">
                                            <button type="button" class="btn btn-outline" style="padding: 4px 8px; font-size: 0.8rem;" 
                                                    onclick="openEditItemModal(<?= $m['id'] ?>, '<?= htmlspecialchars(addslashes($m['item_name'])) ?>', <?= $m['price'] ?>)">
                                                ✏️ 修改
                                            </button>
                                            <form method="POST" onsubmit="return confirm('確定要刪除【<?= htmlspecialchars($m['item_name']) ?>】嗎？');" style="margin:0;">
                                                <input type="hidden" name="action" value="delete_item">
                                                <input type="hidden" name="store_id" value="<?= $selectedStoreId ?>">
                                                <input type="hidden" name="item_id" value="<?= $m['id'] ?>">
                                                <button type="submit" style="background:none; border:none; color:var(--danger); cursor:pointer; font-size:0.8rem;">刪除</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 1. 修改店家資訊 Modal -->
<div class="modal-overlay" id="editStoreModal">
    <div class="modal-content">
        <h3 style="margin-bottom: 14px; font-size: 1.15rem;">✏️ 編輯店家資訊</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_store">
            <input type="hidden" id="editStoreId" name="store_id" value="">

            <div style="margin-bottom: 12px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:5px;">店家名稱</label>
                <input type="text" id="editStoreName" name="name" class="input-text" style="width:100%;" required>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:5px;">店家類別</label>
                <select id="editStoreCategory" name="category" class="input-select" style="width:100%;">
                    <option value="bento">🍱 便當店家 (選菜色/填備註)</option>
                    <option value="drink">🧋 飲料店家 (自動啟用冰塊/甜度按鈕)</option>
                </select>
            </div>

            <div style="margin-bottom: 12px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:5px;">訂餐電話</label>
                <input type="text" id="editStorePhone" name="phone" class="input-text" style="width:100%;" placeholder="例如：04-7123456">
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:5px;">地址 / 外送備註</label>
                <input type="text" id="editStoreAddress" name="address" class="input-text" style="width:100%;" placeholder="例如：自取買五送一 / 滿300外送">
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('editStoreModal')">取消</button>
                <button type="submit" class="btn btn-primary">儲存店家資料</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. 修改菜單品項 Modal -->
<div class="modal-overlay" id="editItemModal">
    <div class="modal-content">
        <h3 style="margin-bottom: 14px; font-size: 1.15rem;">✏️ 修改品項與價格</h3>
        <form method="POST">
            <input type="hidden" name="action" value="edit_item">
            <input type="hidden" name="store_id" value="<?= $selectedStoreId ?>">
            <input type="hidden" id="modalItemId" name="item_id" value="">

            <div style="margin-bottom: 12px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:5px;">品項名稱</label>
                <input type="text" id="modalItemName" name="item_name" class="input-text" style="width:100%;" required>
            </div>

            <div style="margin-bottom: 16px;">
                <label style="display:block; font-size:0.85rem; font-weight:700; margin-bottom:5px;">價格 (元)</label>
                <input type="number" id="modalItemPrice" name="price" class="input-text" style="width:100%;" required>
            </div>

            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" class="btn btn-outline" onclick="closeModal('editItemModal')">取消</button>
                <button type="submit" class="btn btn-primary">儲存更新</button>
            </div>
        </form>
    </div>
</div>

<script>
// 開啟編輯店家 Modal
function openEditStoreModal(id, name, category, phone, address) {
    document.getElementById('editStoreId').value = id;
    document.getElementById('editStoreName').value = name;
    document.getElementById('editStoreCategory').value = category;
    document.getElementById('editStorePhone').value = phone;
    document.getElementById('editStoreAddress').value = address;
    document.getElementById('editStoreModal').classList.add('active');
}

// 開啟編輯品項 Modal
function openEditItemModal(id, name, price) {
    document.getElementById('modalItemId').value = id;
    document.getElementById('modalItemName').value = name;
    document.getElementById('modalItemPrice').value = price;
    document.getElementById('editItemModal').classList.add('active');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// 展開/收合 SQL 一鍵匯入面板
function toggleSqlBox() {
    const box = document.getElementById('sqlBox');
    const arrow = document.getElementById('sqlArrow');
    if (box.style.display === 'none') {
        box.style.display = 'block';
        arrow.innerText = '▲ 收合';
    } else {
        box.style.display = 'none';
        arrow.innerText = '▼ 展開';
    }
}

// 預先儲存標準 Prompt 範本
const bentoPromptTemplate = `請幫我辨識這張便當店菜單（或文字菜單），並依照以下 MySQL 資料庫規格輸出 SQL 新增語法。

【資料庫結構說明】
1. stores 表欄位：
   name (店家名稱), category (固定填 'bento'), phone (電話), address (地址或外送備註), is_active (固定填 1)
2. menu_items 表欄位：
   store_id (對應店家ID), item_name (品項名稱), price (純整數金額), is_available (固定填 1), sort_order (排序流水號 1, 2, 3...)

【輸出要求】
1. 嚴格輸出純 SQL 語法，不要加入額外解說文字。
2. 請使用 @new_store_id 變數銜接店家與菜單。
3. 請依以下標準範本格式輸出：

INSERT INTO \`stores\` (\`name\`, \`category\`, \`phone\`, \`address\`, \`is_active\`) 
VALUES ('店家名稱', 'bento', '電話號碼', '地址或外送備註', 1);

SET @new_store_id = LAST_INSERT_ID();

INSERT INTO \`menu_items\` (\`store_id\`, \`item_name\`, \`price\`, \`is_available\`, \`sort_order\`) VALUES
(@new_store_id, '招牌排骨飯', 100, 1, 1),
(@new_store_id, '香酥雞腿飯', 110, 1, 2);`;

const drinkPromptTemplate = `請幫我辨識這張飲料店菜單（或文字菜單），並依照以下 MySQL 資料庫規格輸出 SQL 新增語法。

【資料庫結構說明】
1. stores 表欄位：
   name (店家名稱), category (固定填 'drink'), phone (電話), address (地址或外送備註), is_active (固定填 1)
2. menu_items 表欄位：
   store_id (對應店家ID), item_name (品項名稱), price (純整數金額), is_available (固定填 1), sort_order (排序流水號 1, 2, 3...)

【飲料專屬規則】
若菜單同品項有容量差異（如中杯 M / 大杯 L、大杯 L / 瓶裝），請務必拆為獨立品項，並在品名後方標註規格，例如：'珍珠奶茶 (M)', '珍珠奶茶 (L)'。

【輸出要求】
1. 嚴格輸出純 SQL 語法，不要加入額外解說文字。
2. 請使用 @new_store_id 變數銜接店家與菜單。
3. 請依以下標準範本格式輸出：

INSERT INTO \`stores\` (\`name\`, \`category\`, \`phone\`, \`address\`, \`is_active\`) 
VALUES ('店家名稱', 'drink', '電話號碼', '地址或外送備註', 1);

SET @new_store_id = LAST_INSERT_ID();

INSERT INTO \`menu_items\` (\`store_id\`, \`item_name\`, \`price\`, \`is_available\`, \`sort_order\`) VALUES
(@new_store_id, '茉莉綠茶 (M)', 30, 1, 1),
(@new_store_id, '茉莉綠茶 (L)', 35, 1, 2),
(@new_store_id, '波霸奶茶 (M)', 50, 1, 3),
(@new_store_id, '波霸奶茶 (L)', 60, 1, 4);`;

// 一鍵複製剪貼簿函式 (附帶按鈕提示回饋)
function copyPrompt(type, btn) {
    const textToCopy = (type === 'bento') ? bentoPromptTemplate : drinkPromptTemplate;
    const originalText = btn.innerText;

    if (navigator.clipboard && window.isSecureContext) {
        navigator.clipboard.writeText(textToCopy).then(() => {
            btn.innerText = '✅ 已複製！';
            setTimeout(() => { btn.innerText = originalText; }, 2000);
        }).catch(() => fallbackCopy(textToCopy, btn, originalText));
    } else {
        fallbackCopy(textToCopy, btn, originalText);
    }
}

// 支援不支援 clipboard API 時的降級方案
function fallbackCopy(text, btn, originalText) {
    const tempInput = document.createElement("textarea");
    tempInput.value = text;
    document.body.appendChild(tempInput);
    tempInput.select();
    document.execCommand("copy");
    document.body.removeChild(tempInput);
    
    btn.innerText = '✅ 已複製！';
    setTimeout(() => { btn.innerText = originalText; }, 2000);
}

// 點擊 Modal 外側關閉
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
        }
    });
});
</script>

</body>
</html>