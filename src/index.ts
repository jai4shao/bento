import { Hono } from 'hono';

type Bindings = {
  DB: D1Database;
  ASSETS: Fetcher;
};

const app = new Hono<{ Bindings: Bindings }>();

// ==========================================
// 🛡️ 自動初始化資料表 (Auto-Migration 防呆中介層)
// 確保任何新人一鍵部署後，第一次打開網頁就自動建好所有資料表
// ==========================================
let isTablesReady = false;

async function ensureTables(db: D1Database) {
  if (isTablesReady) return; // 記憶體旗標快取，避免每次請求都重複建表
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
    db.prepare(`INSERT OR IGNORE INTO stores (id, name, phone, category, is_active) VALUES (1, '美味便當店', '04-7123456', '便當', 1)`),
    db.prepare(`INSERT OR IGNORE INTO menu_items (id, store_id, item_name, price, is_available) VALUES (1, 1, '招牌排骨飯', 100, 1)`),
    db.prepare(`INSERT OR IGNORE INTO menu_items (id, store_id, item_name, price, is_available) VALUES (2, 1, '香酥雞腿飯', 110, 1)`)
  ]);
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
// 🛠️ 資料庫檢查與一鍵修復端點 (除錯備用)
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
    SELECT m.*, s.category AS store_category, s.name AS store_name 
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

    const result = await c.env.DB.prepare(`
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
// 4. 店家與菜單管理 API
// ==========================================
app.post('/api/store/add', async (c) => {
  const { name, phone, category } = await c.req.json();
  if (!name) return c.json({ success: false, message: '請輸入店家名稱' }, 400);

  await c.env.DB.prepare("INSERT INTO stores (name, phone, category, is_active) VALUES (?, ?, ?, 1)")
    .bind(name.trim(), phone || '', category || '便當')
    .run();

  return c.json({ success: true, message: '店家新增成功' });
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
  const { storeId, isActive } = await c.req.json();
  await c.env.DB.prepare("UPDATE stores SET is_active = ? WHERE id = ?")
    .bind(isActive ? 1 : 0, storeId)
    .run();
  return c.json({ success: true });
});

app.post('/api/menu/add', async (c) => {
  const { storeId, itemName, price } = await c.req.json();
  if (!storeId || !itemName || price === undefined) {
    return c.json({ success: false, message: '請提供完整品項資訊' }, 400);
  }

  await c.env.DB.prepare("INSERT INTO menu_items (store_id, item_name, price, is_available) VALUES (?, ?, ?, 1)")
    .bind(storeId, itemName.trim(), Number(price))
    .run();

  return c.json({ success: true, message: '品項新增成功' });
});

app.post('/api/store/update', async (c) => {
  try {
    const { storeId, name, phone, category } = await c.req.json();
    if (!storeId || !name) return c.json({ success: false, message: '店家名稱不能為空' }, 400);

    await c.env.DB.prepare(`
      UPDATE stores 
      SET name = ?, phone = ?, category = ? 
      WHERE id = ?
    `).bind(name.trim(), phone || '', category || '便當', storeId).run();

    return c.json({ success: true, message: '店家資訊更新成功！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '更新失敗' }, 500);
  }
});

app.post('/api/menu/update', async (c) => {
  try {
    const { itemId, itemName, price } = await c.req.json();
    if (!itemId || !itemName || price === undefined) {
      return c.json({ success: false, message: '品項名稱與價格為必填' }, 400);
    }

    await c.env.DB.prepare(`
      UPDATE menu_items 
      SET item_name = ?, price = ? 
      WHERE id = ?
    `).bind(itemName.trim(), Number(price), itemId).run();

    return c.json({ success: true, message: '品項更新成功！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '更新失敗' }, 500);
  }
});

app.post('/api/menu/delete', async (c) => {
  try {
    const { itemId } = await c.req.json();
    if (!itemId) return c.json({ success: false, message: '請提供品項ID' }, 400);

    await c.env.DB.prepare("DELETE FROM menu_items WHERE id = ?").bind(itemId).run();
    return c.json({ success: true, message: '品項已刪除！' });
  } catch (err: any) {
    return c.json({ success: false, message: err?.message || '刪除失敗' }, 500);
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

app.post('/api/admin/orders/reset', async (c) => {
  await c.env.DB.prepare("DELETE FROM orders").run();
  return c.json({ success: true, message: '已清空本週所有點餐紀錄！' });
});

// 靜態資產全域兜底
app.get('/*', async (c) => c.env.ASSETS.fetch(c.req.raw));

export default app;
