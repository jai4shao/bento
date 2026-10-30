-- 1. 店家資料表 (SQLite 不支援 ENUM，改用 TEXT + CHECK)
CREATE TABLE IF NOT EXISTS stores (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL,
  phone TEXT,
  address TEXT,
  is_active INTEGER DEFAULT 0,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  category TEXT DEFAULT 'bento' CHECK(category IN ('bento', 'drink'))
);

-- 2. 菜單品項表
CREATE TABLE IF NOT EXISTS menu_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  store_id INTEGER NOT NULL,
  item_name TEXT NOT NULL,
  price INTEGER NOT NULL,
  is_available INTEGER DEFAULT 1,
  sort_order INTEGER DEFAULT 0,
  FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE
);

-- 3. 人員名冊
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  note TEXT,
  avatar_url TEXT DEFAULT ''
);

-- 4. 訂單紀錄表 (補上 paid_amount 方便對帳)
CREATE TABLE IF NOT EXISTS orders (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_name TEXT NOT NULL,
  item_id INTEGER,
  price INTEGER DEFAULT 0,
  paid_amount INTEGER DEFAULT 0,
  note TEXT DEFAULT '',
  ice TEXT DEFAULT '',
  sugar TEXT DEFAULT '',
  is_paid INTEGER DEFAULT 0,
  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (item_id) REFERENCES menu_items (id) ON DELETE SET NULL
);

-- 5. 系統設定
CREATE TABLE IF NOT EXISTS system_settings (
  setting_key TEXT PRIMARY KEY,
  setting_value TEXT NOT NULL,
  description TEXT
);

-- 寫入基礎系統設定 (API Key 請透過環境變數管理，不寫死明文)
INSERT OR IGNORE INTO system_settings (setting_key, setting_value, description) VALUES
('gemini_api_key', '', 'Google Gemini API 金鑰'),
('order_manual_lock', 'auto', '手動強制結單開關 (auto / force_open / force_lock)'),
('order_start_weekday', '1', '開放星期 (1: 週一)'),
('order_start_time', '06:00', '開放時間'),
('order_end_weekday', '5', '截止星期 (5: 週五)'),
('order_end_time', '12:30', '截止時間'),
('last_reset_week', '2026-38', '最後自動重設週期');

-- ==========================================
-- 💳 多人主揪收款卡片 (支援 Base64 QR Code、滿 6 天自動下架)
-- ==========================================
CREATE TABLE IF NOT EXISTS payment_cards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  initiator_name TEXT NOT NULL,         -- 主揪姓名
  title TEXT NOT NULL,                  -- 收款標題 (如：週三便當團、飲料團)
  qr_image TEXT DEFAULT '',             -- 裁切壓縮後的 Base64 圖片
  app_link TEXT DEFAULT '',             -- 行動支付或轉帳超連結/帳號
  note TEXT DEFAULT '',                 -- 備註 (如：轉帳請附姓名)
  edit_password TEXT NOT NULL,          -- 卡片專屬刪除/管理密碼
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================
-- 💳 多人主揪收款卡片 (支援綁定特定店家、QR Code、滿 6 天自動下架)
-- ==========================================
CREATE TABLE IF NOT EXISTS payment_cards (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  initiator_name TEXT NOT NULL,         -- 主揪姓名
  title TEXT NOT NULL,                  -- 收款標題 (如：便當團、五十嵐飲料)
  target_store_ids TEXT DEFAULT '',     -- 🌟 綁定的店家 ID 清單 (如 "1" 或 "1,2")
  qr_image TEXT DEFAULT '',             -- 裁切壓縮後的 Base64 圖片
  app_link TEXT DEFAULT '',             -- 行動支付或轉帳超連結/帳號
  note TEXT DEFAULT '',                 -- 備註 (如：轉帳請附姓名)
  edit_password TEXT NOT NULL,          -- 卡片專屬管理/審核/刪除密碼
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- ==========================================
-- 📝 轉帳匯款回報暫存表 (供同仁通報、主揪審核沖帳)
-- ==========================================
CREATE TABLE IF NOT EXISTS payment_notifications (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  card_id INTEGER NOT NULL,            -- 所屬收款卡片 ID
  user_name TEXT NOT NULL,             -- 轉帳人姓名
  amount INTEGER NOT NULL,             -- 回報轉帳金額
  account_tail TEXT DEFAULT '',        -- 帳號末 5 碼 (或轉帳註記)
  status INTEGER DEFAULT 0,            -- 0: 待主揪核對, 1: 已核准入帳, 2: 駁回
  created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
