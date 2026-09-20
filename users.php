<?php
// users.php
require_once 'db.php';

// 處理 AJAX 儲存自介與頭像網址
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        header('Content-Type: application/json');
        $id = (int)($_POST['id'] ?? 0);
        $note = trim($_POST['note'] ?? '');
        $avatarUrl = trim($_POST['avatar_url'] ?? '');

        // 🚀 自動轉換 Google Drive 共用連結為圖片直連網址
        if (!empty($avatarUrl)) {
            if (preg_match('/(?:file\/d\/|id=)([a-zA-Z0-9_-]{25,})/', $avatarUrl, $matches)) {
                $fileId = $matches[1];
                $avatarUrl = "https://drive.google.com/thumbnail?id={$fileId}&sz=w1000";
            }
        }

        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE users SET note = ?, avatar_url = ? WHERE id = ?");
            $stmt->execute([$note, $avatarUrl, $id]);
            echo json_encode([
                'status' => 'success', 
                'note' => $note,
                'avatar_url' => $avatarUrl
            ]);
        } else {
            echo json_encode(['status' => 'error', 'message' => '無效的使用者 ID']);
        }
        exit;
    }
}

// 1. 讀取所有同學 (含 avatar_url)
$users = $pdo->query("SELECT id, name, note, avatar_url FROM users")->fetchAll(PDO::FETCH_ASSOC);

// 2. 依照中文姓名筆劃排序 (zh_Hant_TW)
if (class_exists('Collator')) {
    $collator = new Collator('zh_Hant_TW');
    usort($users, function($a, $b) use ($collator) {
        return $collator->compare($a['name'], $b['name']);
    });
} else {
    usort($users, function($a, $b) {
        return strcmp($a['name'], $b['name']);
    });
}
?>
<!DOCTYPE html>
<html lang="zh-TW">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>同學名冊與自我介紹</title>
    <meta name="referrer" content="no-referrer">
    <style>
        :root {
            --primary: #2563eb;
            --accent: #ea580c;
            --bg: #faf6ee;
            --card: #ffffff;
            --border: #ebdccb;
            --text-main: #1c1917;
            --text-muted: #78716c;
        }

        * { box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
        body { background: var(--bg); color: var(--text-main); padding: 20px; max-width: 980px; margin: 0 auto; }

        .nav-bar { display: flex; gap: 16px; margin-bottom: 20px; }
        .nav-link { color: var(--primary); text-decoration: none; font-weight: 700; font-size: 0.95rem; }

        .header-title { margin-bottom: 24px; }
        .header-title h1 { font-size: 1.5rem; font-weight: 800; margin: 0 0 6px 0; }
        .header-title p { color: var(--text-muted); font-size: 0.9rem; margin: 0; }

        /* 卡片網格佈局 */
        .user-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 16px;
            align-items: start;
        }

        .user-card {
            background: var(--card);
            border: 1.5px solid var(--border);
            border-radius: 14px;
            padding: 16px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.03);
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .user-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.06);
            border-color: #d6c2ad;
        }

        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            cursor: pointer;
            user-select: none;
        }

        .user-info-wrap {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        /* 大頭貼外框 */
        .avatar-box {
            width: 52px;
            height: 52px;
            border-radius: 50%;
            overflow: hidden;
            background: #f1ebd9;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 2px solid #ebdccb;
            position: relative;
        }

        .avatar-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
            cursor: zoom-in;
            transition: transform 0.2s ease;
        }

        .avatar-img:hover {
            transform: scale(1.08);
        }

        .avatar-text {
            color: var(--accent);
            font-weight: 800;
            font-size: 1.25rem;
        }

        .user-name {
            font-size: 1.15rem;
            font-weight: 800;
            margin: 0;
        }

        .expand-hint {
            font-size: 0.8rem;
            color: var(--primary);
            margin-top: 2px;
        }

        .expand-arrow {
            color: var(--text-muted);
            transition: transform 0.2s ease;
        }

        .user-card.expanded .expand-arrow {
            transform: rotate(180deg);
        }

        /* 展開區域 */
        .bio-section {
            display: none;
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px dashed var(--border);
        }

        .user-card.expanded .bio-section {
            display: block;
        }

        /* 1. 閱讀模式 */
        .read-mode {
            display: block;
        }

        .bio-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: var(--text-muted);
            margin-bottom: 6px;
        }

        .bio-content {
            font-size: 0.92rem;
            line-height: 1.6;
            color: #374151;
            white-space: pre-wrap;
            word-break: break-word;
            text-align: left;
            margin: 0 0 12px 0;
            padding: 0;
        }

        .bio-placeholder {
            color: #a8a29e;
            font-style: italic;
        }

        .btn-edit-mode {
            background: #fff;
            color: var(--primary);
            border: 1.5px solid #bfdbfe;
            padding: 6px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }

        .btn-edit-mode:hover {
            background: #eff6ff;
            border-color: var(--primary);
        }

        /* 2. 編輯模式 */
        .edit-mode {
            display: none;
        }

        .field-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #44403c;
            margin: 10px 0 4px 0;
        }

        .field-label:first-child {
            margin-top: 0;
        }

        .field-input, .field-textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-size: 0.9rem;
            outline: none;
            background: #fafaf9;
            color: #1c1917;
            transition: border-color 0.2s, background 0.2s;
        }

        .field-input:focus, .field-textarea:focus {
            border-color: var(--primary);
            background: #ffffff;
        }

        .field-textarea {
            height: 90px;
            resize: vertical;
            line-height: 1.5;
        }

        .action-row {
            display: flex;
            justify-content: flex-end;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
        }

        .btn-save {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 7px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: opacity 0.2s;
        }

        .btn-save:hover {
            opacity: 0.9;
        }

        .btn-cancel {
            background: #e5e7eb;
            color: #374151;
            border: none;
            padding: 7px 14px;
            border-radius: 8px;
            font-size: 0.85rem;
            cursor: pointer;
        }

        /* 🖼️ 大圖彈窗 Lightbox */
        .lightbox-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.85);
            z-index: 9999;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            opacity: 0;
            transition: opacity 0.25s ease;
            backdrop-filter: blur(4px);
        }

        .lightbox-modal.show {
            display: flex;
            opacity: 1;
        }

        .lightbox-img {
            max-width: 90vw;
            max-height: 82vh;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
            border: 2px solid rgba(255, 255, 255, 0.2);
            object-fit: contain;
            transform: scale(0.95);
            transition: transform 0.25s ease;
        }

        .lightbox-modal.show .lightbox-img {
            transform: scale(1);
        }

        .lightbox-caption {
            color: #fff;
            margin-top: 14px;
            font-size: 1.1rem;
            font-weight: 700;
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.6);
        }

        .lightbox-close {
            position: absolute;
            top: 20px;
            right: 24px;
            color: #fff;
            font-size: 32px;
            font-weight: bold;
            cursor: pointer;
            border: none;
            background: none;
            line-height: 1;
            padding: 8px;
            opacity: 0.8;
            transition: opacity 0.2s;
        }

        .lightbox-close:hover {
            opacity: 1;
        }
    </style>
</head>
<body>

<div class="nav-bar">
    <a href="index.php" class="nav-link">&larr; 前往點餐前台</a>
    <a href="admin.php" class="nav-link">&larr; 訂單管理控制台</a>
</div>

<div class="header-title">
    <h1>👋 同學通訊錄與自介牆</h1>
    <p>點擊卡片可展開看自介，點擊底部按鈕可修改資料與照片！請不要留下隱私資料，謝謝</p>
</div>

<div class="user-grid">
    <?php if (empty($users)): ?>
        <div style="grid-column: 1 / -1; text-align: center; color: var(--text-muted); padding: 40px;">
            目前名冊中還沒有同學資料喔！
        </div>
    <?php else: ?>
        <?php foreach ($users as $u): 
            $initial = mb_substr($u['name'], 0, 1, 'UTF-8');
            $hasAvatar = !empty($u['avatar_url']);
        ?>
            <div class="user-card" id="card-<?= $u['id'] ?>">
                <!-- 點擊標題列展開/收合 -->
                <div class="card-header" onclick="toggleCard(<?= $u['id'] ?>)">
                    <div class="user-info-wrap">
                        <div class="avatar-box" id="avatar-box-<?= $u['id'] ?>">
                            <?php if ($hasAvatar): ?>
                                <img src="<?= htmlspecialchars($u['avatar_url']) ?>" 
                                     alt="<?= htmlspecialchars($u['name']) ?>" 
                                     class="avatar-img" 
                                     onclick="openLightbox('<?= htmlspecialchars($u['avatar_url'], ENT_QUOTES) ?>', '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>', event)" 
                                     onerror="handleImgError(this, '<?= htmlspecialchars($initial) ?>')">
                            <?php else: ?>
                                <span class="avatar-text"><?= htmlspecialchars($initial) ?></span>
                            <?php endif; ?>
                        </div>
                        <div>
                            <h2 class="user-name"><?= htmlspecialchars($u['name']) ?></h2>
                            <div class="expand-hint">點擊查看自介 ▾</div>
                        </div>
                    </div>

                    <svg class="expand-arrow" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="6 9 12 15 18 9"></polyline>
                    </svg>
                </div>

                <!-- 展開後的區域 -->
                <div class="bio-section">
                    
                    <!-- 📖 1. 閱讀模式 (預設顯示) -->
                    <div class="read-mode" id="read-mode-<?= $u['id'] ?>">
                        <div class="bio-label">關於我：</div>
                        <div class="bio-content" id="bio-text-<?= $u['id'] ?>"><?php if (!empty($u['note'])): ?><?= htmlspecialchars(trim($u['note'])) ?><?php else: ?><span class="bio-placeholder">尚未填寫自我介紹。</span><?php endif; ?></div>
                        
                        <button type="button" class="btn-edit-mode" onclick="showEditMode(<?= $u['id'] ?>)">
                            ✏️ 修改 / 編輯資料
                        </button>
                    </div>

                    <!-- ✍️ 2. 編輯模式 (點擊後切換) -->
                    <div class="edit-mode" id="edit-mode-<?= $u['id'] ?>">
                        <label class="field-label">📷 自拍 / 照片網址：</label>
                        <input type="url" 
                               class="field-input" 
                               id="input-avatar-<?= $u['id'] ?>" 
                               placeholder="請貼上圖片網址 (支援 Google Drive 分享連結)" 
                               value="<?= htmlspecialchars($u['avatar_url'] ?? '') ?>">

                        <label class="field-label">✍️ 自我介紹：</label>
                        <textarea class="field-textarea" 
                                  id="input-note-<?= $u['id'] ?>" 
                                  placeholder="寫下你的自我介紹、興趣或喜歡的餐點..."><?= htmlspecialchars($u['note'] ?? '') ?></textarea>
                        
                        <div class="action-row">
                            <button type="button" class="btn-cancel" onclick="cancelEditMode(<?= $u['id'] ?>)">取消</button>
                            <button type="button" class="btn-save" onclick="saveProfile(<?= $u['id'] ?>, '<?= htmlspecialchars($initial) ?>', '<?= htmlspecialchars($u['name'], ENT_QUOTES) ?>')">
                                💾 儲存資料
                            </button>
                        </div>
                    </div>

                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <p></p>
    <p>您的照片放在雲端硬碟，請記得開啟『共用』，知道連結的任何人可檢視，才可以正常顯示照片。</p>
</div>

<!-- 🖼️ 彈窗燈箱 HTML -->
<div id="lightbox" class="lightbox-modal" onclick="closeLightbox()">
    <button class="lightbox-close" onclick="closeLightbox()">&times;</button>
    <img id="lightbox-img" class="lightbox-img" src="" alt="大圖" onclick="event.stopPropagation()">
    <div id="lightbox-caption" class="lightbox-caption"></div>
</div>

<script>
// 點擊標題展開/收合卡片
function toggleCard(id) {
    const card = document.getElementById('card-' + id);
    card.classList.toggle('expanded');
}

// 切換至編輯模式
function showEditMode(id) {
    document.getElementById('read-mode-' + id).style.display = 'none';
    document.getElementById('edit-mode-' + id).style.display = 'block';
}

// 取消編輯，切回閱讀模式
function cancelEditMode(id) {
    document.getElementById('edit-mode-' + id).style.display = 'none';
    document.getElementById('read-mode-' + id).style.display = 'block';
}

// 圖片載入失敗自動回退
function handleImgError(img, initial) {
    img.style.display = 'none';
    const parent = img.parentElement;
    parent.innerHTML = `<span class="avatar-text">${initial}</span>`;
}

// Lightbox 打開
function openLightbox(url, name, event) {
    if (event) event.stopPropagation();
    const modal = document.getElementById('lightbox');
    const img = document.getElementById('lightbox-img');
    const caption = document.getElementById('lightbox-caption');

    img.src = url;
    caption.textContent = name;
    modal.classList.add('show');
}

// Lightbox 關閉
function closeLightbox() {
    const modal = document.getElementById('lightbox');
    modal.classList.remove('show');
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLightbox();
});

// 儲存資料 (AJAX)
function saveProfile(id, initial, name) {
    const newNote = document.getElementById('input-note-' + id).value.trim();
    const newAvatar = document.getElementById('input-avatar-' + id).value.trim();

    const fd = new FormData();
    fd.append('action', 'update_profile');
    fd.append('id', id);
    fd.append('note', newNote);
    fd.append('avatar_url', newAvatar);

    fetch('users.php', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
        if (data.status === 'success') {
            // 1. 更新閱讀模式文字
            const bioTextElem = document.getElementById('bio-text-' + id);
            if (data.note !== '') {
                bioTextElem.textContent = data.note;
            } else {
                bioTextElem.innerHTML = '<span class="bio-placeholder">尚未填寫自我介紹。</span>';
            }

            // 2. 更新編輯框內的網址 (帶入轉換後的 Google Drive 直連網址)
            document.getElementById('input-avatar-' + id).value = data.avatar_url;

            // 3. 更新大頭貼 (並綁定 Lightbox)
            const avatarBox = document.getElementById('avatar-box-' + id);
            if (data.avatar_url !== '') {
                avatarBox.innerHTML = `<img src="${data.avatar_url}" 
                                            class="avatar-img" 
                                            onclick="openLightbox('${data.avatar_url}', '${name}', event)" 
                                            onerror="handleImgError(this, '${initial}')">`;
            } else {
                avatarBox.innerHTML = `<span class="avatar-text">${initial}</span>`;
            }

            // 4. 切回閱讀模式
            cancelEditMode(id);
        } else {
            alert('儲存失敗：' + (data.message || '未知錯誤'));
        }
    })
    .catch(() => alert('網路連線發生錯誤'));
}
</script>

</body>
</html>