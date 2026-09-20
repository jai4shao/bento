import { Hono } from 'hono';

type Bindings = {
  DB: D1Database;
  ASSETS: Fetcher;
};

const app = new Hono<{ Bindings: Bindings }>();

// ==========================================
// 0. 前端靜態頁面路由 (對應 public 資料夾內的 HTML)
// ==========================================

// 首頁自動轉向到點餐前台
app.get('/', (c) => {
  return c.redirect('/index.html');
});

// 支援不加 .html 後綴也能開頁面
app.get('/index', (c) => {
  return c.env.ASSETS.fetch(new Request(new URL('/index.html', c.req.url)));
});

app.get('/admin', (c) => {
  return c.env.ASSETS.fetch(new Request(new URL('/admin.html', c.req.url)));
});

app.get('/store_admin', (c) => {
  return c.env.ASSETS.fetch(new Request(new URL('/store_admin.html', c.req.url)));
});

// ==========================================
// 1. 前台點餐 API (供 index.html 使用)
// ==========================================

// 取得前台所需的初始化資料 (店家、啟用菜單、名冊、當前排程開放狀態)
app.get('/api/init-order-page', async (c) => {
  const db = c.env.DB;

  // 取得排程與模式
  const settingsRows = await db.prepare("SELECT setting_key, setting_value FROM system_settings").all();
  const settings: Record<string, string> = {};
  settingsRows.results.forEach((r: any) => { settings[r.setting_key] = r.setting_value; });

  // 取得啟用的店家
  const stores = await db.prepare("SELECT * FROM stores WHERE is_active = 1 ORDER BY category ASC, id ASC").all();

  // 取得供應中的菜單品項
  const menuItems = await db.prepare(`
    SELECT m.*, s.category AS store_category, s.name AS store_name 
    FROM menu_items m
    JOIN stores s ON m.store_id = s.id
    WHERE m.is_available = 1 AND s.is_active = 1
    ORDER BY m.sort_order ASC, m.id ASC
  `).all();

  // 取得名冊
  const users = await db.prepare("SELECT id, name, avatar_url FROM users ORDER BY id ASC").all();

  // 取得目前所有已登記訂單
  const orders = await db.prepare(`
    SELECT o.*, m.item_name, s.name AS store_name
    FROM orders o
    JOIN menu_items m ON o.item_id = m.id
    JOIN stores s ON m.store_id = s.id
  `).all();

  return c.json({
    success: true,
    data: {
      settings,
      stores: stores.results,
      menuItems: menuItems.results,
      users: users.results,
      orders: orders.results
    }
  });
});

// 同學提交點餐
app.post('/api/order/submit', async (c) => {
  const { userName, itemId, note, price } = await c.req.json();
  if (!userName || !itemId) {
    return c.json({ success: false, message: '請提供姓名與品項' }, 400);
  }

  // 寫入或更新訂單 (以姓名為準，每週覆蓋最新點餐)
  await c.env.DB.prepare(`
    INSERT INTO orders (user_name, item_id, note, price, paid_amount, is_paid)
    VALUES (?, ?, ?, ?, 0, 0)
  `).bind(userName, itemId, note || '', price || 0).run();

  return c.json({ success: true, message: '點餐成功！' });
});

// ==========================================
// 2. 後台管理 API (供 admin.html 使用)
// ==========================================

// 叫餐統計 (依店家分組)
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

// 同學訂單明細與收款狀況
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

// 後台：儲存已繳金額與核銷狀態
app.post('/api/admin/order/paid', async (c) => {
  const { orderId, paidAmount, isPaid } = await c.req.json();
  await c.env.DB.prepare("UPDATE orders SET paid_amount = ?, is_paid = ? WHERE id = ?")
    .bind(paidAmount, isPaid, orderId)
    .run();
  return c.json({ success: true });
});

// 後台：單筆修改品項備註或單價
app.post('/api/admin/order/update', async (c) => {
  const { orderId, note, price } = await c.req.json();
  await c.env.DB.prepare("UPDATE orders SET note = ?, price = ? WHERE id = ?")
    .bind(note, price, orderId)
    .run();
  return c.json({ success: true });
});

// 後台：重設所有本週點餐
app.post('/api/admin/orders/reset', async (c) => {
  await c.env.DB.prepare("DELETE FROM orders").run();
  return c.json({ success: true, message: '已清空本週所有點餐紀錄！' });
});

// 後台：儲存排程與鎖定模式
app.post('/api/admin/settings', async (c) => {
  const body = await c.req.json();
  const stmt = c.env.DB.prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
  const batch = Object.entries(body).map(([key, val]) => stmt.bind(key, String(val)));
  await c.env.DB.batch(batch);
  return c.json({ success: true });
});

// ==========================================
// 3. 全域靜態檔案兜底處理 (處理所有 .html、.css、.js、圖片)
// ==========================================
app.get('/*', async (c) => {
  return c.env.ASSETS.fetch(c.req.raw);
});

export default app;
