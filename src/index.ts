import { Hono } from 'hono';

type Bindings = {
  DB: D1Database;
  ASSETS: Fetcher;
};

const app = new Hono<{ Bindings: Bindings }>();

// ==========================================
// 🛡️ 自動初始化資料表 (Auto-Migration 防呆中介層)
// ==========================================
let isTablesReady = false;

async function ensureTables(db: D1Database) {
  if (isTablesReady) return;

  // 1. 建立完整結構 (補齊 address 欄位)
  await db.batch([
    db.prepare(`CREATE TABLE IF NOT EXISTS users (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL UNIQUE,
      avatar_url TEXT DEFAULT '',
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`),
    db.prepare(`CREATE TABLE IF NOT EXISTS stores (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      name TEXT NOT NULL,
      phone TEXT DEFAULT '',
      address TEXT DEFAULT '',
      category TEXT DEFAULT '便當',
      is_active INTEGER DEFAULT 1,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`),
    db.prepare(`CREATE TABLE IF NOT EXISTS menu_items (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      store_id INTEGER NOT NULL,
      item_name TEXT NOT NULL,
      price INTEGER NOT NULL DEFAULT 0,
      is_available INTEGER DEFAULT 1,
      sort_order INTEGER DEFAULT 0
    )`),
    db.prepare(`CREATE TABLE IF NOT EXISTS orders (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      user_name TEXT NOT NULL,
      item_id INTEGER NOT NULL,
      note TEXT DEFAULT '',
      price INTEGER NOT NULL DEFAULT 0,
      paid_amount INTEGER DEFAULT 0,
      is_paid INTEGER DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`),
    db.prepare(`CREATE TABLE IF NOT EXISTS system_settings (
      setting_key TEXT PRIMARY KEY,
      setting_value TEXT NOT NULL
    )`),
    db.prepare(`CREATE TABLE IF NOT EXISTS payment_cards (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      initiator_name TEXT NOT NULL,
      title TEXT NOT NULL,
      target_store_ids TEXT DEFAULT '',
      qr_image TEXT DEFAULT '',
      app_link TEXT DEFAULT '',
      note TEXT DEFAULT '',
      edit_password TEXT NOT NULL,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`),
    db.prepare(`CREATE TABLE IF NOT EXISTS payment_notifications (
      id INTEGER PRIMARY KEY AUTOINCREMENT,
      card_id INTEGER NOT NULL,
      user_name TEXT NOT NULL,
      amount INTEGER NOT NULL,
      account_tail TEXT DEFAULT '',
      status INTEGER DEFAULT 0,
      created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )`)
  ]);

  // 2. 只有在全新空白資料庫時，才寫入一次初始範例 (避免刪除後又自動長出來)
  try {
    const storeCount: any = await db.prepare("SELECT COUNT(*) AS count FROM stores").first();
    if (storeCount && storeCount.count === 0) {
      await db.batch([
        db.prepare(`INSERT INTO stores (id, name, phone, address, category, is_active) VALUES (1, '美味便當店', '04-7123456', '滿500外送', '便當', 1)`),
        db.prepare(`INSERT INTO menu_items (store_id, item_name, price, is_available) VALUES (1, '招牌排骨飯', 100, 1)`),
        db.prepare(`INSERT INTO menu_items (store_id, item_name, price, is_available) VALUES (1, '香酥雞腿飯', 110, 1)`)
      ]);
    }
  } catch (e) {
    console.error('Initial mock data insert skipped:', e);
  }

  isTablesReady = true;
}

// 攔截所有 /api/* 請求，執行前確認資料表存在
app.use('/api/*', async (c, next) => {
  if (c.env.DB) {
    try {
      await ensureTables(c.env.DB);
    } catch (e) {
      console.error('Auto migration check failed:', e);
    }
  }
  await next();
});

// ==========================================
// 0. 靜態頁面導向
// ==========================================
app.get('/', (c) => c.redirect('/index.html'));
app.get('/index', (c) => c.env.ASSETS.fetch(new Request(new URL('/index.html', c.req.url))));
app.get('/admin', (c) => c.env.ASSETS.fetch(new Request(new URL('/admin.html', c.req.url))));
app.get('/store_admin', (c) => c.env.ASSETS.fetch(new Request(new URL('/store_admin.html', c.req.url))));

// ==========================================
// 🛠️ 資料庫檢查與一鍵修復端點
// ==========================================
app.get('/api/debug-db', async (c) => {
  try {
    const tables = await c.env.DB.prepare(
      "SELECT name FROM sqlite_master WHERE type='table'"
    ).all();
    return c.json({
      success: true,
      message: '成功連上 D1',
      tables: tables.results
    });
  } catch (err: any) {
    return c.json({
      success: false,
      message: '連線 D1 失敗: ' + (err?.message || err)
    }, 500);
  }
});

app.get('/api/setup-database', async (c) => {
  try {
    isTablesReady = false;
    await ensureTables(c.env.DB);
    return c.json({ success: true, message: '🎉 資料庫所有資料表已成功強制建置完成！' });
  } catch (err: any) {
    return c.json({ success: false, error: err?.message || err }, 500);
  }
});

// ==========================================
// 1. 系統狀態與點餐鎖定 API
// ==========================================
app.get('/api/system/status', async (c) => {
  try {
    const row: any = await c.env.DB.prepare(
      "SELECT setting_value FROM system_settings WHERE setting_key = 'is_order_locked'"
    ).first();
    const isLocked = row ? row.setting_value === '1' : false;
    return c.json({ success: true, isLocked });
  } catch (err: any) {
    return c.json({ success: true, isLocked: false });
  }
});

app.post('/api/admin/toggle-lock', async (c) => {
  try {
    const { isLocked } = await c.req.json();
    const val = isLocked ? '1' : '0';
    await c.env.DB.prepare(`
      INSERT INTO system_settings (setting_key, setting_value)
      VALUES ('is_order_locked', ?)
      ON CONFLICT(setting_key) DO UPDATE SET setting_value = ?
    `).bind(val, val).run();

    return c.json({ 
      success: true, 
      isLocked: isLocked, 
      message: isLocked ? '已結單！前台已停止收單與修改。' : '已重新開放前台點餐！' 
    });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '操作失敗' }, 500);
  }
});

// ==========================================
// 2. 初始化前台資料 API
// ==========================================
app.get('/api/init-order-page', async (c) => {
  const db = c.env.DB;

  const stores = await db.prepare("SELECT * FROM stores ORDER BY is_active DESC, category ASC, id ASC").all();
  const menuItems = await db.prepare(`
    SELECT m.*, s.category AS store_category, s.name AS store_name, s.is_active AS store_is_active
    FROM menu_items m
    JOIN stores s ON m.store_id = s.id
    WHERE m.is_available = 1
    ORDER BY m.sort_order ASC, m.id ASC
  `).all();
  const users = await db.prepare("SELECT id, name FROM users ORDER BY id ASC").all();
  const orders = await db.prepare(`
    SELECT o.*, m.item_name, s.name AS store_name
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
    ORDER BY o.id DESC
  `).all();

  return c.json({
    success: true,
    data: {
      stores: stores.results,
      menuItems: menuItems.results,
      users: users.results,
      orders: orders.results
    }
  });
});

// ==========================================
// 3. 點餐與人員 API
// ==========================================
app.post('/api/order/submit', async (c) => {
  try {
    const lockRow: any = await c.env.DB.prepare(
      "SELECT setting_value FROM system_settings WHERE setting_key = 'is_order_locked'"
    ).first();
    if (lockRow && lockRow.setting_value === '1') {
      return c.json({ success: false, message: '今日已截止訂餐，主揪已向店家下單！' }, 403);
    }

    const { userName, items } = await c.req.json();
    if (!userName || !items || !Array.isArray(items) || items.length === 0) {
      return c.json({ success: false, message: '請選擇姓名與至少一項餐點' }, 400);
    }

const db = c.env.DB;

    // 🛡️ 檢查購物車品項所屬店家是否處於「非供餐狀態」 (is_active != 1)
    const itemIds = items.map((it: any) => Number(it.itemId)).filter(id => !isNaN(id));
    if (itemIds.length > 0) {
      const placeholders = itemIds.map(() => '?').join(',');
      const unavailableStore: any = await db.prepare(`
        SELECT s.name, s.is_active FROM menu_items m
        JOIN stores s ON m.store_id = s.id
        WHERE m.id IN (${placeholders}) AND s.is_active != 1
        LIMIT 1
      `).bind(...itemIds).first();

      if (unavailableStore) {
        const reason = unavailableStore.is_active === 2 ? '已提早截止收單' : '今日不供餐';
        return c.json({ 
          success: false, 
          message: `【${unavailableStore.name}】${reason}，無法送出該店餐點！` 
        }, 400);
      }
    }

    const statements: any[] = [];

    for (const item of items) {
      const qty = Math.max(1, Number(item.qty || 1));
      for (let i = 0; i < qty; i++) {
        statements.push(
          db.prepare(`
            INSERT INTO orders (user_name, item_id, note, price, paid_amount, is_paid)
            VALUES (?, ?, ?, ?, 0, 0)
          `).bind(userName, item.itemId, item.note || '', Number(item.price || 0))
        );
      }
    }

    await db.batch(statements);
    return c.json({ success: true, message: `成功送出 ${statements.length} 份餐點！` });
  } catch (err: any) {
    console.error('Submit orders error:', err);
    return c.json({ success: false, message: err?.message || '點餐送出失敗' }, 500);
  }
});

app.post('/api/user/add', async (c) => {
  try {
    const body = await c.req.json();
    const name = body?.name ? String(body.name).trim() : '';
    if (!name) return c.json({ success: false, message: '姓名不能為空' }, 400);

    await c.env.DB.prepare("INSERT INTO users (name) VALUES (?)").bind(name).run();
    return c.json({ success: true, message: '新增成功' });
  } catch (err: any) {
    console.error('Add user error:', err);
    return c.json({ success: false, message: `資料庫錯誤: ${err?.message || err}` }, 400);
  }
});

// 🌟 修改人員姓名 API (同步連動更新 orders 裡的歷史姓名，避免核銷找不到人)
app.post('/api/user/update', async (c) => {
  try {
    const { userId, newName } = await c.req.json();
    const uId = Number(userId);
    const cleanName = String(newName || '').trim();

    if (!uId || !cleanName) {
      return c.json({ success: false, message: '使用者ID與新姓名不能為空' }, 400);
    }

    const db = c.env.DB;
    // 先查舊名字
    const oldUser: any = await db.prepare("SELECT name FROM users WHERE id = ?").bind(uId).first();
    if (!oldUser) {
      return c.json({ success: false, message: '找不到該人員' }, 404);
    }

    await db.batch([
      // 1. 更新 users 表
      db.prepare("UPDATE users SET name = ? WHERE id = ?").bind(cleanName, uId),
      // 2. 同步把 orders 歷史點餐名字一併改成新名字
      db.prepare("UPDATE orders SET user_name = ? WHERE user_name = ?").bind(cleanName, oldUser.name)
    ]);

    return c.json({ success: true, message: `已成功將【${oldUser.name}】修改為【${cleanName}】！` });
  } catch (err: any) {
    if (err?.message?.includes('UNIQUE')) {
      return c.json({ success: false, message: '修改失敗：該姓名已經存在！' }, 400);
    }
    return c.json({ success: false, message: err?.message || '修改失敗' }, 500);
  }
});

// 🌟 刪除人員 API
app.post('/api/user/delete', async (c) => {
  try {
    const { userId } = await c.req.json();
    const uId = Number(userId);
    if (!uId) return c.json({ success: false, message: '缺少人員ID' }, 400);

    const db = c.env.DB;
    const user: any = await db.prepare("SELECT name FROM users WHERE id = ?").bind(uId).first();
    if (!user) return c.json({ success: false, message: '找不到該人員' }, 404);

    // 檢查今天是否有未結清訂單
    const activeOrder: any = await db.prepare("SELECT COUNT(*) AS count FROM orders WHERE user_name = ? AND is_paid = 0").bind(user.name).first();
    if (activeOrder && activeOrder.count > 0) {
      return c.json({ success: false, message: `無法刪除：【${user.name}】今日尚有未結清的訂單！` }, 400); }  await db.prepare("DELETE FROM users WHERE id = ?").bind(uId).run(); return c.json({ success: true, message: `人員【${user.name}】已成功刪除！` });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
  }
});

app.post('/api/order/self-delete', async (c) => {
  try {
    const lockRow: any = await c.env.DB.prepare(
      "SELECT setting_value FROM system_settings WHERE setting_key = 'is_order_locked'"
    ).first();
    if (lockRow && lockRow.setting_value === '1') {
      return c.json({ success: false, message: '已結單下訂，無法取消！請直接聯絡主揪。' }, 403);
    }

    const { orderId, userName } = await c.req.json();
    if (!orderId || !userName) return c.json({ success: false, message: '參數不完整' }, 400);

    const result = await c.env.DB.prepare(
      "DELETE FROM orders WHERE id = ? AND user_name = ?"
    ).bind(orderId, userName).run();

    if (result.meta.changes === 0) {
      return c.json({ success: false, message: '刪除失敗：無權限或找不到該筆點餐' }, 403);
    }

    return c.json({ success: true, message: '已取消該筆餐點！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
  }
});

app.post('/api/order/self-update', async (c) => {
  try {
    const lockRow: any = await c.env.DB.prepare(
      "SELECT setting_value FROM system_settings WHERE setting_key = 'is_order_locked'"
    ).first();
    if (lockRow && lockRow.setting_value === '1') {
      return c.json({ success: false, message: '已結單下訂，無法修改品項！' }, 403);
    }

    const { orderId, userName, itemId, note, price } = await c.req.json();
    if (!orderId || !userName || !itemId) return c.json({ success: false, message: '參數不完整' }, 400);

    const db = c.env.DB;

    // 🛡️ 檢查欲更換的品項其店家是否已截止或休息
    const targetStore: any = await db.prepare(`
      SELECT s.name, s.is_active FROM menu_items m
      JOIN stores s ON m.store_id = s.id
      WHERE m.id = ?
    `).bind(Number(itemId)).first();

    if (targetStore && targetStore.is_active !== 1) {
      const reason = targetStore.is_active === 2 ? '已提早截止收單' : '今日不供餐';
      return c.json({ success: false, message: `【${targetStore.name}】${reason}，無法更改為該店品項！` }, 400);
    }

    const result = await db.prepare(`
      UPDATE orders 
      SET item_id = ?, note = ?, price = ?
      WHERE id = ? AND user_name = ?
    `).bind(itemId, note || '', price, orderId, userName).run();

    if (result.meta.changes === 0) {
      return c.json({ success: false, message: '修改失敗：無權限或找不到該筆點餐' }, 403);
    }

    return c.json({ success: true, message: '餐點修改成功！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '修改失敗' }, 500);
  }
});

// ==========================================
// 4. 店家與菜單管理 API (完整補齊 CRUD)
// ==========================================
app.post('/api/store/add', async (c) => {
  const { name, phone, address, category } = await c.req.json();
  if (!name) return c.json({ success: false, message: '請輸入店家名稱' }, 400);

  await c.env.DB.prepare("INSERT INTO stores (name, phone, address, category, is_active) VALUES (?, ?, ?, ?, 1)")
    .bind(name.trim(), phone || '', address || '', category || '便當')
    .run();

  return c.json({ success: true, message: '店家新增成功' });
});

// 🌟 修改店家資訊 API (解決 404 問題)
app.post('/api/store/update', async (c) => {
  try {
    const { storeId, name, phone, address, category } = await c.req.json();
    if (!storeId || !name) {
      return c.json({ success: false, message: '缺少店家ID或名稱' }, 400);
    }

    await c.env.DB.prepare(`
      UPDATE stores 
      SET name = ?, phone = ?, address = ?, category = ?
      WHERE id = ?
    `).bind(
      String(name).trim(), 
      phone || '', 
      address || '', 
      category || '便當', 
      Number(storeId)
    ).run();

    return c.json({ success: true, message: '店家資訊修改成功！' });
  } catch (err: any) {
    console.error('Update store error:', err);
    return c.json({ success: false, message: err?.message || '修改失敗' }, 500);
  }
});

// 新增菜單品項
app.post('/api/menu/add', async (c) => {
  try {
    const { storeId, itemName, price } = await c.req.json();
    if (!storeId || !itemName) return c.json({ success: false, message: '店家ID與品項名稱不能為空' }, 400);

    await c.env.DB.prepare(`
      INSERT INTO menu_items (store_id, item_name, price, is_available)
      VALUES (?, ?, ?, 1)
    `).bind(Number(storeId), String(itemName).trim(), Number(price || 0)).run();

    return c.json({ success: true, message: '品項新增成功！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '品項新增失敗' }, 500);
  }
});

// 🌟 修改菜單品項 API (同步連動更新未結清的訂單價格)
app.post('/api/menu/update', async (c) => {
  try {
    const { itemId, itemName, price, isAvailable } = await c.req.json();
    if (!itemId) return c.json({ success: false, message: '缺少品項ID' }, 400);

    const mId = Number(itemId);
    const newPrice = Number(price || 0);
    const db = c.env.DB;

    await db.batch([
      // 1. 更新菜單品項本身
      db.prepare(`
        UPDATE menu_items 
        SET item_name = ?, price = ?, is_available = ?
        WHERE id = ?
      `).bind(
        String(itemName).trim(), 
        newPrice, 
        isAvailable !== undefined ? Number(isAvailable) : 1, 
        mId
      ),

      // 2. 同步更新現有未收款/今日訂單的單價，避免前後台金額脫鉤
      db.prepare(`
        UPDATE orders 
        SET price = ? 
        WHERE item_id = ? AND is_paid = 0
      `).bind(newPrice, mId)
    ]);

    return c.json({ success: true, message: '品項更新成功，現有未結清訂單價格已同步更新！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '品項更新失敗' }, 500);
  }
});

// 刪除菜單品項 (修正 404)
app.post('/api/menu/delete', async (c) => {
  try {
    const { itemId } = await c.req.json();
    if (!itemId) return c.json({ success: false, message: '缺少品項ID' }, 400);

    await c.env.DB.prepare("DELETE FROM menu_items WHERE id = ?").bind(Number(itemId)).run();
    return c.json({ success: true, message: '品項已成功刪除！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
  }
});

app.post('/api/admin/raw-sql', async (c) => {
  try {
    const body = await c.req.json();
    let sqlText = body?.sql ? String(body.sql) : '';

    if (!sqlText.trim()) return c.json({ success: false, message: '請輸入 SQL 語法' }, 400);

    sqlText = sqlText.replace(/```[a-zA-Z]*/g, '').replace(/```/g, '').trim();

    const statements = sqlText
      .split(';')
      .map(s => s.trim())
      .filter(s => s.length > 0);

    if (statements.length === 0) return c.json({ success: false, message: '未偵測到有效的 SQL 指令' }, 400);

    const batchList = statements.map(stmt => c.env.DB.prepare(stmt));
    await c.env.DB.batch(batchList);

    return c.json({ success: true, message: `成功匯入！共執行了 ${statements.length} 條 SQL 語句。` });
  } catch (err: any) {
    console.error('Raw SQL error:', err);
    return c.json({ success: false, message: 'SQL 執行失敗: ' + (err?.message || err) }, 500);
  }
});

app.post('/api/store/toggle', async (c) => {
  try {
    const body = await c.req.json();
    const storeId = Number(body.storeId);
    const status = Number(body.status); // 1: 供餐, 2: 截止, 0: 休息

    if (isNaN(storeId) || ![0, 1, 2].includes(status)) {
      return c.json({ success: false, message: '狀態參數錯誤' }, 400);
    }

    const db = c.env.DB;
    const statements: any[] = [];

    // 1. 更新店家狀態
    statements.push(
      db.prepare("UPDATE stores SET is_active = ? WHERE id = ?").bind(status, storeId)
    );

    // 2. 只有切換為「休息 (0)」才清空該店家訂單；切換為「截止 (2)」時完整保留！
    if (status === 0) {
      statements.push(
        db.prepare(`
          DELETE FROM orders 
          WHERE item_id IN (SELECT id FROM menu_items WHERE store_id = ?)
        `).bind(storeId)
      );
    }

    await db.batch(statements);

    const msgMap: Record<number, string> = {
      1: '店家已恢復供餐！',
      2: '店家已截止收單！（已保留現有點餐明細）',
      0: '店家已設為休息，且該店所有點餐已自動清空！'
    };

    return c.json({ success: true, message: msgMap[status] });
  } catch (err: any) {
    console.error('Toggle store error:', err);
    return c.json({ success: false, message: err?.message || '操作失敗' }, 500);
  }
});

// ==========================================
// 5. 後台管理與收款 API
// ==========================================
app.get('/api/admin/summary', async (c) => {
  const query = `
    SELECT 
      s.id AS store_id, s.name AS store_name, s.category, s.phone AS store_phone,
      m.item_name, o.note, m.price, 
      COUNT(o.id) AS qty, 
      SUM(o.price) AS subtotal
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
    GROUP BY s.id, o.item_id, o.note
    ORDER BY s.category ASC, s.id ASC, m.sort_order ASC, qty DESC
  `;
  const { results } = await c.env.DB.prepare(query).all();
  return c.json({ success: true, data: results });
});

app.get('/api/admin/orders', async (c) => {
  const query = `
    SELECT o.*, m.item_name, s.name AS store_name
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
    ORDER BY o.id DESC
  `;
  const { results } = await c.env.DB.prepare(query).all();
  return c.json({ success: true, data: results });
});

app.post('/api/admin/order/paid', async (c) => {
  const { orderId, paidAmount, isPaid } = await c.req.json();
  await c.env.DB.prepare("UPDATE orders SET paid_amount = ?, is_paid = ? WHERE id = ?")
    .bind(paidAmount, isPaid, orderId)
    .run();
  return c.json({ success: true });
});

app.post('/api/admin/user/paid', async (c) => {
  try {
    const { userName, paidAmount, isPaid } = await c.req.json();
    if (!userName) return c.json({ success: false, message: '請提供姓名' }, 400);

    await c.env.DB.prepare(`
      UPDATE orders 
      SET paid_amount = ?, is_paid = ? 
      WHERE user_name = ?
    `).bind(paidAmount, isPaid ? 1 : 0, userName).run();

    return c.json({ success: true, message: '個人核銷已更新！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '更新失敗' }, 500);
  }
});

app.post('/api/store/delete', async (c) => {
  try {
    const { storeId } = await c.req.json();
    if (!storeId) return c.json({ success: false, message: '請提供店家ID' }, 400);

    const db = c.env.DB;
    await db.batch([
      db.prepare("DELETE FROM menu_items WHERE store_id = ?").bind(storeId),
      db.prepare("DELETE FROM stores WHERE id = ?").bind(storeId)
    ]);

    return c.json({ success: true, message: '店家及菜單已成功刪除！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
  }
});

app.post('/api/admin/order/update', async (c) => {
  try {
    const { orderId, itemId, note, price } = await c.req.json();
    if (!orderId || !itemId) return c.json({ success: false, message: '參數不完整' }, 400);

    await c.env.DB.prepare(`
      UPDATE orders 
      SET item_id = ?, note = ?, price = ?
      WHERE id = ?
    `).bind(itemId, note || '', price, orderId).run();

    return c.json({ success: true, message: '訂單更新成功！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '更新失敗' }, 500);
  }
});

app.post('/api/admin/order/delete', async (c) => {
  try {
    const { orderId } = await c.req.json();
    if (!orderId) return c.json({ success: false, message: '請提供訂單編號' }, 400);

    await c.env.DB.prepare("DELETE FROM orders WHERE id = ?").bind(orderId).run();
    return c.json({ success: true, message: '訂單已刪除！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
  }
});

// 🌟 清空訂單：一併清空所有點餐紀錄與匯款回報暫存
app.post('/api/admin/orders/reset', async (c) => {
  try {
    const db = c.env.DB;
    await db.batch([
      db.prepare("DELETE FROM orders"),
      db.prepare("DELETE FROM payment_notifications")
    ]);
    return c.json({ success: true, message: '🎉 已清空本週所有點餐紀錄與轉帳通報！' });
  } catch (err: any) {
    return c.json({ success: false, message: '清空失敗: ' + (err?.message || err) }, 500);
  }
});

// 🌟 一鍵依最新菜單價格校正所有未結清訂單金額
app.post('/api/admin/orders/realign-prices', async (c) => {
  try {
    const db = c.env.DB;
    const result = await db.prepare(`
      UPDATE orders 
      SET price = (SELECT price FROM menu_items WHERE menu_items.id = orders.item_id)
      WHERE is_paid = 0 
        AND item_id IN (SELECT id FROM menu_items)
    `).run();

    return c.json({ 
      success: true, 
      message: `價格校正完成！已同步更新 ${result.meta.changes} 筆未結清餐點金額。` 
    });
  } catch (err: any) {
    return c.json({ success: false, message: '校正失敗: ' + (err?.message || err) }, 500);
  }
});

// ==========================================
// 💳 多人主揪收款卡片與對帳沖帳 API
// ==========================================

// 1. 取得所有有效收款卡片 (惰性清理 6 天過期項目)
app.get('/api/payment/cards', async (c) => {
  try {
    const db = c.env.DB;

    // 🌟 惰性清理：自動刪除超過 6 天的舊卡片及關聯回報
    await db.batch([
      db.prepare(`DELETE FROM payment_notifications WHERE card_id IN (
        SELECT id FROM payment_cards WHERE datetime(created_at, '+6 days') <= datetime('now')
      )`),
      db.prepare(`DELETE FROM payment_cards WHERE datetime(created_at, '+6 days') <= datetime('now')`)
    ]);

    const { results } = await db.prepare(`
      SELECT id, initiator_name, title, target_store_ids, qr_image, app_link, note, created_at,
             datetime(created_at, '+6 days') AS expires_at
      FROM payment_cards
      ORDER BY id DESC
    `).all();

    return c.json({ success: true, data: results });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '讀取失敗' }, 500);
  }
});

// 2. 新增收款卡片 (儲存綁定的店家清單)
app.post('/api/payment/card/add', async (c) => {
  try {
    const { initiatorName, title, targetStoreIds, qrImage, appLink, note, password } = await c.req.json();
    
    if (!initiatorName || !title || !password) {
      return c.json({ success: false, message: '主揪姓名、收款標題與管理密碼為必填！' }, 400);
    }

    const db = c.env.DB;
    await db.prepare(`
      INSERT INTO payment_cards (initiator_name, title, target_store_ids, qr_image, app_link, note, edit_password)
      VALUES (?, ?, ?, ?, ?, ?, ?)
    `).bind(
      String(initiatorName).trim(),
      String(title).trim(),
      String(targetStoreIds || '').trim(),
      qrImage || '',
      appLink ? String(appLink).trim() : '',
      note ? String(note).trim() : '',
      String(password).trim()
    ).run();

    return c.json({ success: true, message: '🎉 收款卡片建立成功！將保留 6 天後自動下架。' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '新增失敗' }, 500);
  }
});

// 🌟 刪除收款卡片：連帶清除轉帳記錄，並重置該卡片負責店家的付款狀態
app.post('/api/payment/card/delete', async (c) => {
  try {
    const { cardId, password } = await c.req.json();
    const cId = Number(cardId);

    if (!cId || !password) {
      return c.json({ success: false, message: '缺少卡片編號或密碼' }, 400);
    }

    const db = c.env.DB;
    const card: any = await db.prepare("SELECT edit_password, target_store_ids FROM payment_cards WHERE id = ?").bind(cId).first();

    if (!card) {
      return c.json({ success: false, message: '找不到該卡片或已過期下架' }, 404);
    }

    if (card.edit_password !== String(password).trim()) {
      return c.json({ success: false, message: '🔒 密碼錯誤！無法刪除此收款卡片。' }, 403);
    }

    const batchStatements: any[] = [
      // 1. 刪除該卡片的所有匯款回報暫存
      db.prepare("DELETE FROM payment_notifications WHERE card_id = ?").bind(cId),
      // 2. 刪除收款卡片本身
      db.prepare("DELETE FROM payment_cards WHERE id = ?").bind(cId)
    ];

    // 3. 🌟 一併重設該卡片所屬店家訂單的已繳金額與狀態 (清空實收與結清標記)
    const storeIdsStr = card.target_store_ids || '';
    const storeIds = storeIdsStr.split(',').map((id: string) => Number(id.trim())).filter((id: number) => !isNaN(id) && id > 0);

    if (storeIds.length > 0) {
      const placeholders = storeIds.map(() => '?').join(',');
      batchStatements.push(
        db.prepare(`
          UPDATE orders 
          SET paid_amount = 0, is_paid = 0
          WHERE item_id IN (SELECT id FROM menu_items WHERE store_id IN (${placeholders}))
        `).bind(...storeIds)
      );
    }

    await db.batch(batchStatements);
    return c.json({ success: true, message: '收款卡片與關聯付款記錄已成功清除！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
  }
});

// 4. 查詢某人在該卡片負責店家的應付金額試算
app.post('/api/payment/calculate-user-amount', async (c) => {
  try {
    const { cardId, userName } = await c.req.json();
    if (!cardId || !userName) return c.json({ success: false, message: '參數不完整' }, 400);

    const db = c.env.DB;
    const card: any = await db.prepare("SELECT target_store_ids FROM payment_cards WHERE id = ?").bind(Number(cardId)).first();
    if (!card) return c.json({ success: false, message: '找不到卡片' }, 404);

    const storeIdsStr = card.target_store_ids || '';
    let storeIds = storeIdsStr.split(',').map((id: string) => Number(id.trim())).filter((id: number) => !isNaN(id) && id > 0);

    let query = `
      SELECT o.id, o.price, o.paid_amount, o.is_paid, m.item_name, s.name as store_name
      FROM orders o
      JOIN menu_items m ON o.item_id = m.id
      JOIN stores s ON m.store_id = s.id
      WHERE o.user_name = ?
    `;

    if (storeIds.length > 0) {
      const placeholders = storeIds.map(() => '?').join(',');
      query += ` AND s.id IN (${placeholders})`;
    }

    const binds = [userName, ...storeIds];
    const { results } = await db.prepare(query).bind(...binds).all();

    let total = 0;
    let paid = 0;
    (results || []).forEach((row: any) => {
      total += Number(row.price || 0);
      paid += Number(row.paid_amount || 0);
    });

    return c.json({
      success: true,
      data: {
        userName,
        targetTotal: total,
        currentPaid: paid,
        suggestedAmount: Math.max(0, total - paid),
        items: results
      }
    });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '試算失敗' }, 500);
  }
});

// 5. 同仁送出轉帳回報
app.post('/api/payment/notify/submit', async (c) => {
  try {
    const { cardId, userName, amount, accountTail } = await c.req.json();
    const cId = Number(cardId);
    const payAmount = Number(amount);

    if (!cId || !userName || !payAmount || isNaN(payAmount)) {
      return c.json({ success: false, message: '請確實選擇姓名並輸入轉帳金額！' }, 400);
    }

    const db = c.env.DB;
    await db.prepare(`
      INSERT INTO payment_notifications (card_id, user_name, amount, account_tail, status)
      VALUES (?, ?, ?, ?, 0)
    `).bind(
      cId, 
      String(userName).trim(), 
      payAmount, 
      accountTail ? String(accountTail).trim() : ''
    ).run();

    return c.json({ success: true, message: '🎉 匯款回報已送出！請靜待主揪核對沖帳。' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '回報失敗' }, 500);
  }
});

// 6. 主揪輸入密碼解鎖查閱回報清單
app.post('/api/payment/notifications/list', async (c) => {
  try {
    const { cardId, password } = await c.req.json();
    const cId = Number(cardId);
    if (!cId || !password) return c.json({ success: false, message: '請提供卡片編號與管理密碼' }, 400);

    const db = c.env.DB;
    const card: any = await db.prepare("SELECT edit_password, target_store_ids FROM payment_cards WHERE id = ?").bind(cId).first();
    if (!card) return c.json({ success: false, message: '找不到此卡片' }, 404);

    if (card.edit_password !== String(password).trim()) {
      return c.json({ success: false, message: '🔒 密碼錯誤！無法查看對帳資訊。' }, 403);
    }

    const { results } = await db.prepare(`
      SELECT * FROM payment_notifications 
      WHERE card_id = ? 
      ORDER BY status ASC, id DESC
    `).bind(cId).all();

    return c.json({ success: true, data: results });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '讀取失敗' }, 500);
  }
});

// 7. 主揪一鍵核准沖帳
app.post('/api/payment/notification/approve', async (c) => {
  try {
    const { notificationId, cardId, password } = await c.req.json();
    const nId = Number(notificationId);
    const cId = Number(cardId);
    if (!nId || !cId || !password) return c.json({ success: false, message: '參數不完整' }, 400);

    const db = c.env.DB;
    const card: any = await db.prepare("SELECT edit_password, target_store_ids, title FROM payment_cards WHERE id = ?").bind(cId).first();
    if (!card || card.edit_password !== String(password).trim()) {
      return c.json({ success: false, message: '🔒 密碼錯誤或無權限操作' }, 403);
    }

    const notif: any = await db.prepare("SELECT * FROM payment_notifications WHERE id = ? AND card_id = ?").bind(nId, cId).first();
    if (!notif) return c.json({ success: false, message: '找不到該筆回報' }, 404);
    if (notif.status === 1) return c.json({ success: false, message: '此筆回報已核准過，請勿重複沖帳' }, 400);

    // 抓出該同仁在該卡片負責店家的訂單
    const storeIdsStr = card.target_store_ids || '';
    let storeIds = storeIdsStr.split(',').map((id: string) => Number(id.trim())).filter((id: number) => !isNaN(id) && id > 0);

    let orderQuery = `
      SELECT o.id, o.price, o.paid_amount, o.is_paid, o.note 
      FROM orders o
      JOIN menu_items m ON o.item_id = m.id
      WHERE o.user_name = ?
    `;
    if (storeIds.length > 0) {
      const placeholders = storeIds.map(() => '?').join(',');
      orderQuery += ` AND m.store_id IN (${placeholders})`;
    }
    orderQuery += ` ORDER BY o.id ASC`;

    const userOrders: any = await db.prepare(orderQuery).bind(notif.user_name, ...storeIds).all();
    const ordersList = userOrders.results || [];

    let remainingCredit = Number(notif.amount);
    const updateStatements: any[] = [];
    const auditTag = `[轉帳$${notif.amount}${notif.account_tail ? `(末5碼${notif.account_tail})` : ''}]`;

    for (const ord of ordersList) {
      if (remainingCredit <= 0) break;
      const unpaidForThisItem = Math.max(0, ord.price - ord.paid_amount);

      if (unpaidForThisItem > 0) {
        const payToItem = Math.min(remainingCredit, unpaidForThisItem);
        const newPaidAmount = ord.paid_amount + payToItem;
        const isPaidFlag = newPaidAmount >= ord.price ? 1 : 0;
        const newNote = ord.note ? `${ord.note} ${auditTag}` : auditTag;

        updateStatements.push(
          db.prepare(`UPDATE orders SET paid_amount = ?, is_paid = ?, note = ? WHERE id = ?`)
            .bind(newPaidAmount, isPaidFlag, newNote, ord.id)
        );
        remainingCredit -= payToItem;
      }
    }

    // 更新回報狀態為已核准
    updateStatements.push(
      db.prepare(`UPDATE payment_notifications SET status = 1 WHERE id = ?`).bind(nId)
    );

    await db.batch(updateStatements);

    return c.json({ 
      success: true, 
      message: `🎉 核准成功！已將 $${notif.amount} 自動沖帳並更新後台點餐紀錄。` 
    });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '核准失敗' }, 500);
  }
});

// 靜態資產全域兜底
app.get('/*', async (c) => c.env.ASSETS.fetch(c.req.raw));

export default app;
