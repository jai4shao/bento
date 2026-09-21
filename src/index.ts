import { Hono } from 'hono';

type Bindings = {
  DB: D1Database;
  ASSETS: Fetcher;
};

const app = new Hono<{ Bindings: Bindings }>();

// 靜態頁面導向
app.get('/', (c) => c.redirect('/index.html'));
app.get('/index', (c) => c.env.ASSETS.fetch(new Request(new URL('/index.html', c.req.url))));
app.get('/admin', (c) => c.env.ASSETS.fetch(new Request(new URL('/admin.html', c.req.url))));
app.get('/store_admin', (c) => c.env.ASSETS.fetch(new Request(new URL('/store_admin.html', c.req.url))));

// ==========================================
// 1. 初始化資料 API
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
// 2. 點餐與人員 API
// ==========================================
app.post('/api/order/submit', async (c) => {
  const { userName, itemId, note, price } = await c.req.json();
  if (!userName || !itemId) return c.json({ success: false, message: '請選擇姓名與餐點' }, 400);

  await c.env.DB.prepare(`
    INSERT INTO orders (user_name, item_id, note, price, paid_amount, is_paid)
    VALUES (?, ?, ?, ?, 0, 0)
  `).bind(userName, itemId, note || '', price || 0).run();

  return c.json({ success: true, message: '點餐成功！' });
});

// 新增人員
app.post('/api/user/add', async (c) => {
  const { name } = await c.req.json();
  if (!name || !name.trim()) return c.json({ success: false, message: '姓名不能為空' }, 400);

  try {
    await c.env.DB.prepare("INSERT INTO users (name) VALUES (?)").bind(name.trim()).run();
    return c.json({ success: true, message: '新增成功' });
  } catch (err: any) {
    return c.json({ success: false, message: '姓名可能已存在或寫入失敗' }, 400);
  }
});

// ==========================================
// 3. 店家與菜單管理 API
// ==========================================
// 新增店家
app.post('/api/store/add', async (c) => {
  const { name, phone, category } = await c.req.json();
  if (!name) return c.json({ success: false, message: '請輸入店家名稱' }, 400);

  await c.env.DB.prepare("INSERT INTO stores (name, phone, category, is_active) VALUES (?, ?, ?, 1)")
    .bind(name.trim(), phone || '', category || '一般')
    .run();

  return c.json({ success: true, message: '店家新增成功' });
});

// 切換店家供餐狀態
app.post('/api/store/toggle', async (c) => {
  const { storeId, isActive } = await c.req.json();
  await c.env.DB.prepare("UPDATE stores SET is_active = ? WHERE id = ?")
    .bind(isActive ? 1 : 0, storeId)
    .run();

  return c.json({ success: true });
});

// 新增菜單品項
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

// ==========================================
// 4. 後台管理與收款 API
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

app.post('/api/admin/orders/reset', async (c) => {
  await c.env.DB.prepare("DELETE FROM orders").run();
  return c.json({ success: true, message: '已清空本週所有點餐紀錄！' });
});

app.get('/*', async (c) => c.env.ASSETS.fetch(c.req.raw));

export default app;
