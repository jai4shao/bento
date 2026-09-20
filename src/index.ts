import { Hono } from 'hono';

type Bindings = {
  DB: D1Database;
  ASSETS: Fetcher;
};

const app = new Hono<{ Bindings: Bindings }>();

// 1. 首頁直接轉址到點餐前台
app.get('/', (c) => {
  return c.redirect('/index.html');
});

// 2. 讓無副檔名網址也能對應到 HTML
app.get('/index', (c) => {
  return c.env.ASSETS.fetch(new Request(new URL('/index.html', c.req.url)));
});

app.get('/admin', (c) => {
  return c.env.ASSETS.fetch(new Request(new URL('/admin.html', c.req.url)));
});

app.get('/store_admin', (c) => {
  return c.env.ASSETS.fetch(new Request(new URL('/store_admin.html', c.req.url)));
});

// 3. 後續的 API 邏輯 (保持原本的 /api/...)
// app.get('/api/init-order-page', ...)
// app.post('/api/order/submit', ...)
// ...（其餘 API 代碼維持原樣）...

// 4. 靜態檔案代理 (放最底下，處理所有 public 裡面的 js, css, html)
app.get('/*', async (c) => {
  return c.env.ASSETS.fetch(c.req.raw);
});

export default app;
