<!DOCTYPE html>
<html lang="zh-TW">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>今日便當與飲料點餐</title>
  <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen text-slate-800 p-4 md:p-8">
  <div class="max-w-4xl mx-auto space-y-6">

    <!-- 頂部卡片 -->
    <header class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 flex flex-col sm:flex-row justify-between sm:items-center gap-4">
      <div>
        <h1 class="text-2xl font-bold text-slate-800">🍱 今日便當 / 飲料登記</h1>
        <p class="text-xs text-slate-500 mt-1">選定姓名後點擊店家挑選餐點，支援多份與規格化備註</p>
      </div>
      <div class="space-x-2">
        <a href="/admin" class="text-xs font-semibold text-blue-600 hover:text-blue-800 bg-blue-50 hover:bg-blue-100 px-3.5 py-2 rounded-xl border border-blue-200 transition">訂單核銷後台</a>
        <a href="/store_admin" class="text-xs font-semibold text-slate-600 hover:text-slate-900 bg-slate-100 hover:bg-slate-200 px-3.5 py-2 rounded-xl border border-slate-200 transition">店家管理</a>
      </div>
    </header>

    <!-- 點餐操作卡片 -->
    <section class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200 space-y-5">
      
      <!-- 1. 人員選擇 -->
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">1. 選擇點餐姓名</label>
        <div class="flex gap-2">
          <select id="userSelect" onchange="onUserSelectChange()" class="w-full border border-slate-300 rounded-xl px-4 py-2.5 bg-slate-50 focus:bg-white text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-500">
            <option value="">-- 請選擇您的名字 --</option>
          </select>
          <button onclick="addNewUser()" class="whitespace-nowrap bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold px-4 py-2.5 rounded-xl transition">
            ➕ 新增人員
          </button>
        </div>
      </div>

      <!-- 2. 店家按鈕區 (點擊跳出該店菜單 Modal) -->
      <div>
        <label class="block text-xs font-bold text-slate-500 uppercase tracking-wider mb-2">2. 點選店家瀏覽菜單</label>
        <div id="storeButtonsContainer" class="flex flex-wrap gap-2.5">
          <!-- 動態載入店家按鈕 -->
        </div>
      </div>

      <!-- 3. 已選餐點清單 (購物車) -->
      <div class="border-t pt-4">
        <div class="flex justify-between items-center mb-2">
          <label class="text-xs font-bold text-slate-700 uppercase tracking-wider">🛒 已選餐點清單</label>
          <span id="cartTotalPrice" class="text-xs font-bold text-blue-600 bg-blue-50 border border-blue-200 px-2.5 py-1 rounded-lg">小計：$0</span>
        </div>
        
        <div id="cartContainer" class="space-y-2 mb-4">
          <p class="text-xs text-slate-400 py-4 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">
            尚未加入任何餐點，請點擊上方店家按鈕挑選餐點
          </p>
        </div>

        <button onclick="submitBatchOrder()" id="submitBtn" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-bold py-3 rounded-xl shadow transition text-sm">
          確認送出全部點餐
        </button>
      </div>
    </section>

    <!-- 今日已登記清單 -->
    <section class="bg-white rounded-2xl p-6 shadow-sm border border-slate-200">
      <h2 class="text-lg font-bold text-slate-800 mb-4">📋 今日已登記清單</h2>
      <div id="ordersContainer" class="overflow-x-auto text-sm">
        <p class="text-slate-400 py-4 text-center">載入清單中...</p>
      </div>
    </section>

  </div>

  <!-- 🏪 菜單選擇彈窗 Modal (首頁不再被長菜單佔據) -->
  <div id="menuModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-2xl shadow-xl max-w-2xl w-full max-h-[85vh] flex flex-col overflow-hidden">
      <!-- Modal 標題列 -->
      <div class="p-4 sm:p-5 border-b flex justify-between items-center bg-slate-50">
        <div>
          <h3 id="modalStoreTitle" class="text-lg font-bold text-slate-800">店家菜單</h3>
          <p id="modalStoreSubtitle" class="text-xs text-slate-500 mt-0.5"></p>
        </div>
        <button onclick="closeMenuModal()" class="text-slate-400 hover:text-slate-600 text-2xl font-bold px-2 leading-none">&times;</button>
      </div>

      <!-- 搜尋菜單 -->
      <div class="p-3 border-b bg-white">
        <input type="text" id="menuSearchInput" oninput="onMenuSearch()" placeholder="🔍 搜尋該店品項名稱..." class="w-full text-xs border border-slate-300 rounded-xl px-3.5 py-2 outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <!-- 菜單品項卡片網格 -->
      <div id="modalMenuGrid" class="p-4 overflow-y-auto grid grid-cols-1 sm:grid-cols-2 gap-2.5 text-sm flex-1">
        <!-- 動態填入品項卡片 -->
      </div>

      <!-- Modal 底部關閉按鈕 -->
      <div class="p-3 border-t bg-slate-50 flex justify-between items-center">
        <span id="modalCartCount" class="text-xs font-semibold text-slate-600">已選 0 份</span>
        <button onclick="closeMenuModal()" class="bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold px-5 py-2 rounded-xl transition">
          完成挑選，返回清單
        </button>
      </div>
    </div>
  </div>

  <!-- 🥤 規格備註選擇彈窗 Modal (針對飲料甜度冰量、或餐點備註) -->
  <div id="itemOptionModal" class="fixed inset-0 bg-black/40 backdrop-blur-sm hidden flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-2xl shadow-xl max-w-md w-full p-5 space-y-4">
      <div class="flex justify-between items-center border-b pb-3">
        <div>
          <h3 id="optionItemName" class="text-base font-bold text-slate-800">餐點規格</h3>
          <p id="optionItemPrice" class="text-xs text-blue-600 font-bold mt-0.5">$0</p>
        </div>
        <button onclick="closeOptionModal()" class="text-slate-400 hover:text-slate-600 text-xl font-bold">&times;</button>
      </div>

      <!-- 甜度選擇 (若是飲料店顯示) -->
      <div id="sugarSection" class="space-y-1.5">
        <label class="text-xs font-bold text-slate-600">糖量選擇</label>
        <div class="grid grid-cols-5 gap-1 text-xs" id="sugarGroup">
          <button type="button" onclick="selectSugar('無糖')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">無糖</button>
          <button type="button" onclick="selectSugar('微糖')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">微糖</button>
          <button type="button" onclick="selectSugar('半糖')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">半糖</button>
          <button type="button" onclick="selectSugar('少糖')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">少糖</button>
          <button type="button" onclick="selectSugar('正常糖')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">正常糖</button>
        </div>
      </div>

      <!-- 冰量選擇 (若是飲料店顯示) -->
      <div id="iceSection" class="space-y-1.5">
        <label class="text-xs font-bold text-slate-600">冰量選擇</label>
        <div class="grid grid-cols-4 sm:grid-cols-7 gap-1 text-[11px]" id="iceGroup">
          <button type="button" onclick="selectIce('熱')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">熱</button>
          <button type="button" onclick="selectIce('常溫')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">常溫</button>
          <button type="button" onclick="selectIce('去冰')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">去冰</button>
          <button type="button" onclick="selectIce('微冰')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">微冰</button>
          <button type="button" onclick="selectIce('半冰')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">半冰</button>
          <button type="button" onclick="selectIce('少冰')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">少冰</button>
          <button type="button" onclick="selectIce('正常冰')" class="spec-btn border border-slate-300 py-1.5 rounded-lg text-slate-700 hover:bg-blue-50">正常冰</button>
        </div>
      </div>

      <!-- 其他客製備註 -->
      <div>
        <label class="text-xs font-bold text-slate-600 mb-1 block">其他備註需求</label>
        <input type="text" id="customNoteInput" placeholder="如：少飯、不辣、加珍珠等" class="w-full border border-slate-300 rounded-xl px-3 py-2 text-xs outline-none focus:ring-2 focus:ring-blue-500">
      </div>

      <!-- 確認加入按鈕 -->
      <div class="flex justify-end space-x-2 pt-2 border-t">
        <button onclick="closeOptionModal()" class="px-4 py-2 text-xs font-semibold text-slate-600 hover:bg-slate-100 rounded-xl transition">取消</button>
        <button onclick="confirmAddToCart()" class="px-5 py-2 text-xs font-bold bg-blue-600 hover:bg-blue-700 text-white rounded-xl shadow transition">加入清單</button>
      </div>
    </div>
  </div>

  <script>
    const STORAGE_KEY = 'bento_user_name';
    let allStores = [];
    let allMenuItems = [];
    let activeModalStoreId = null;
    let currentPendingItem = null;
    let selectedSugar = '';
    let selectedIce = '';
    let cart = [];

    async function initPage() {
      try {
        const res = await fetch('/api/init-order-page');
        const json = await res.json();
        if (!json.success) throw new Error();

        const { stores, menuItems, users, orders } = json.data;
        allStores = stores.filter(s => s.is_active == 1);
        allMenuItems = menuItems;

        renderUsers(users);
        renderStoreButtons();
        renderOrders(orders);
      } catch (err) {
        console.error(err);
      }
    }

    function renderUsers(users) {
      const select = document.getElementById('userSelect');
      const savedUser = localStorage.getItem(STORAGE_KEY);
      let html = '<option value="">-- 請選擇您的名字 --</option>';
      users.forEach(u => {
        const isSel = (u.name === savedUser) ? 'selected' : '';
        html += '<option value="' + u.name + '" ' + isSel + '>' + u.name + '</option>';
      });
      select.innerHTML = html;
    }

    function onUserSelectChange() {
      const val = document.getElementById('userSelect').value;
      if (val) localStorage.setItem(STORAGE_KEY, val);
    }

    async function addNewUser() {
      const name = prompt('請輸入新同學/同事姓名：');
      if (!name || !name.trim()) return;

      const res = await fetch('/api/user/add', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name: name.trim() })
      });
      const result = await res.json();
      if (result.success) {
        localStorage.setItem(STORAGE_KEY, name.trim());
        initPage();
      } else {
        alert(result.message || '新增失敗');
      }
    }

    // 渲染店家按鈕 (點擊跳出該店 Modal)
    function renderStoreButtons() {
      const container = document.getElementById('storeButtonsContainer');
      if (!allStores || allStores.length === 0) {
        container.innerHTML = '<span class="text-xs text-slate-400 py-1">目前無供餐店家</span>';
        return;
      }

      let html = '';
      allStores.forEach(s => {
        const isDrink = s.category && (s.category.includes('飲料') || s.category.includes('茶'));
        const icon = isDrink ? '🥤' : '🍱';

        html += '<button onclick="openMenuModal(' + s.id + ')" class="inline-flex items-center gap-1.5 px-4 py-2.5 rounded-xl text-xs font-bold border border-slate-200 bg-white hover:bg-blue-50 hover:border-blue-400 shadow-sm transition">' +
          '<span>' + icon + '</span>' +
          '<span class="text-slate-800">' + s.name + '</span>' +
          '<span class="text-[10px] text-blue-600 font-semibold bg-blue-50 px-1.5 py-0.5 rounded border border-blue-100">' + (s.category || '一般') + '</span>' +
        '</button>';
      });
      container.innerHTML = html;
    }

    // 打開該店的菜單 Modal
    function openMenuModal(storeId) {
      const store = allStores.find(s => s.id === storeId);
      if (!store) return;

      activeModalStoreId = storeId;
      document.getElementById('modalStoreTitle').innerText = store.name;
      document.getElementById('modalStoreSubtitle').innerText = '分類：' + (store.category || '一般') + ' ｜ 電話：' + (store.phone || '無');
      document.getElementById('menuSearchInput').value = '';

      renderModalMenuItems();
      updateModalCartCount();
      document.getElementById('menuModal').classList.remove('hidden');
    }

    function closeMenuModal() {
      document.getElementById('menuModal').classList.add('hidden');
      activeModalStoreId = null;
    }

    function onMenuSearch() {
      renderModalMenuItems();
    }

    function renderModalMenuItems() {
      const keyword = document.getElementById('menuSearchInput').value.trim().toLowerCase();
      const container = document.getElementById('modalMenuGrid');
      let items = allMenuItems.filter(m => m.store_id === activeModalStoreId);

      if (keyword) {
        items = items.filter(m => m.item_name.toLowerCase().includes(keyword));
      }

      if (items.length === 0) {
        container.innerHTML = '<p class="col-span-full text-slate-400 py-8 text-center text-xs">查無符合的餐點品項</p>';
        return;
      }

      let html = '';
      items.forEach(it => {
        html += '<div onclick="openOptionModal(' + it.id + ')" class="cursor-pointer p-3 rounded-xl border border-slate-200 bg-white hover:border-blue-500 hover:shadow-sm transition flex justify-between items-center">' +
          '<div class="pr-2">' +
            '<span class="font-bold text-slate-800 text-xs block">' + it.item_name + '</span>' +
            '<span class="text-xs font-bold text-blue-600 mt-0.5 block">$' + it.price + '</span>' +
          '</div>' +
          '<span class="text-xs font-bold text-blue-600 bg-blue-50 hover:bg-blue-100 border border-blue-200 px-2.5 py-1 rounded-lg shrink-0">+ 點餐</span>' +
        '</div>';
      });
      container.innerHTML = html;
    }

    function updateModalCartCount() {
      const count = cart.reduce((acc, c) => acc + c.qty, 0);
      document.getElementById('modalCartCount').innerText = '清單目前已選 ' + count + ' 份餐點';
    }

    // 打開規格選擇 Modal (甜度/冰量/客製備註)
    function openOptionModal(itemId) {
      const item = allMenuItems.find(m => m.id === itemId);
      if (!item) return;

      currentPendingItem = item;
      selectedSugar = '';
      selectedIce = '';
      document.getElementById('optionItemName').innerText = item.item_name;
      document.getElementById('optionItemPrice').innerText = '$' + item.price;
      document.getElementById('customNoteInput').value = '';

      // 判斷是否為飲料店：顯示或隱藏糖量/冰量
      const store = allStores.find(s => s.id === item.store_id);
      const isDrink = store && store.category && (store.category.includes('飲料') || store.category.includes('茶'));

      document.getElementById('sugarSection').style.display = isDrink ? 'block' : 'none';
      document.getElementById('iceSection').style.display = isDrink ? 'block' : 'none';

      // 重置按鈕高亮
      document.querySelectorAll('.spec-btn').forEach(btn => {
        btn.classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
        btn.classList.add('text-slate-700');
      });

      // 飲料預設建議
      if (isDrink) {
        selectSugar('微糖');
        selectIce('微冰');
      }

      document.getElementById('itemOptionModal').classList.remove('hidden');
    }

    function selectSugar(val) {
      selectedSugar = val;
      updateSpecButtonGroup('sugarGroup', val);
    }

    function selectIce(val) {
      selectedIce = val;
      updateSpecButtonGroup('iceGroup', val);
    }

    function updateSpecButtonGroup(groupId, val) {
      const group = document.getElementById(groupId);
      if (!group) return;
      group.querySelectorAll('.spec-btn').forEach(btn => {
        if (btn.innerText === val) {
          btn.classList.add('bg-blue-600', 'text-white', 'border-blue-600');
          btn.classList.remove('text-slate-700');
        } else {
          btn.classList.remove('bg-blue-600', 'text-white', 'border-blue-600');
          btn.classList.add('text-slate-700');
        }
      });
    }

    function closeOptionModal() {
      document.getElementById('itemOptionModal').classList.add('hidden');
      currentPendingItem = null;
    }

    function confirmAddToCart() {
      if (!currentPendingItem) return;

      const store = allStores.find(s => s.id === currentPendingItem.store_id);
      const isDrink = store && store.category && (store.category.includes('飲料') || store.category.includes('茶'));
      const customNote = document.getElementById('customNoteInput').value.trim();

      // 組合規格備註 (固定格式：微糖/微冰 或 微糖/微冰/加珍珠)
      let finalNote = '';
      if (isDrink) {
        const specs = [];
        if (selectedSugar) specs.push(selectedSugar);
        if (selectedIce) specs.push(selectedIce);
        if (customNote) specs.push(customNote);
        finalNote = specs.join('/');
      } else {
        finalNote = customNote;
      }

      // 加入購物車 (同品項且相同備註者合併數量)
      const exist = cart.find(c => c.itemId === currentPendingItem.id && c.note === finalNote);
      if (exist) {
        exist.qty += 1;
      } else {
        cart.push({
          itemId: currentPendingItem.id,
          itemName: currentPendingItem.item_name,
          storeName: currentPendingItem.store_name,
          price: currentPendingItem.price,
          qty: 1,
          note: finalNote
        });
      }

      closeOptionModal();
      updateModalCartCount();
      renderCart();
    }

    function updateCartQty(index, delta) {
      cart[index].qty += delta;
      if (cart[index].qty <= 0) {
        cart.splice(index, 1);
      }
      renderCart();
      updateModalCartCount();
    }

    function renderCart() {
      const container = document.getElementById('cartContainer');
      const totalEl = document.getElementById('cartTotalPrice');

      if (cart.length === 0) {
        container.innerHTML = '<p class="text-xs text-slate-400 py-4 text-center bg-slate-50 rounded-xl border border-dashed border-slate-200">尚未加入任何餐點，請點擊上方店家按鈕挑選餐點</p>';
        totalEl.innerText = '小計：$0';
        return;
      }

      let sum = 0;
      let html = '';
      cart.forEach((c, idx) => {
        const itemSubtotal = c.price * c.qty;
        sum += itemSubtotal;

        html += '<div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 p-2.5 bg-slate-50 border border-slate-200 rounded-xl text-xs">' +
          '<div class="flex-1">' +
            '<span class="font-semibold text-blue-600">[' + c.storeName + ']</span> ' +
            '<span class="font-bold text-slate-800">' + c.itemName + '</span> ' +
            (c.note ? '<span class="text-amber-800 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded text-[11px] ml-1">' + c.note + '</span>' : '') +
            '<span class="text-slate-500 font-medium ml-1">($' + c.price + '/份)</span>' +
          '</div>' +
          '<div class="flex items-center gap-2">' +
            '<div class="flex items-center border border-slate-300 rounded-lg bg-white overflow-hidden">' +
              '<button onclick="updateCartQty(' + idx + ', -1)" class="px-2 py-1 hover:bg-slate-100 font-bold">-</button>' +
              '<span class="px-2 font-bold text-slate-700">' + c.qty + '</span>' +
              '<button onclick="updateCartQty(' + idx + ', 1)" class="px-2 py-1 hover:bg-slate-100 font-bold">+</button>' +
            '</div>' +
            '<span class="font-bold text-slate-800 min-w-[50px] text-right">$' + itemSubtotal + '</span>' +
            '<button onclick="removeCartItem(' + idx + ')" class="text-slate-400 hover:text-red-500 text-xs px-1">✕</button>' +
          '</div>' +
        '</div>';
      });

      container.innerHTML = html;
      totalEl.innerText = '小計：$' + sum;
    }

    function removeCartItem(idx) {
      cart.splice(idx, 1);
      renderCart();
      updateModalCartCount();
    }

    async function submitBatchOrder() {
      const userName = document.getElementById('userSelect').value;
      if (!userName) return alert('請先選擇您的名字！');
      if (cart.length === 0) return alert('點餐清單是空的，請先挑選餐點！');

      const btn = document.getElementById('submitBtn');
      btn.disabled = true;
      btn.innerText = '送出點餐中...';

      try {
        const res = await fetch('/api/order/submit', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ userName, items: cart })
        });
        const result = await res.json();
        if (result.success) {
          alert('🎉 ' + result.message);
          cart = [];
          renderCart();
          initPage();
        } else {
          alert(result.message || '點餐失敗');
        }
      } catch (err) {
        alert('網路異常，請稍後再試');
      } finally {
        btn.disabled = false;
        btn.innerText = '確認送出全部點餐';
      }
    }

    function renderOrders(orders) {
      const container = document.getElementById('ordersContainer');
      if (!orders || orders.length === 0) {
        container.innerHTML = '<p class="text-slate-400 py-4 text-center">今日尚無人登記點餐</p>';
        return;
      }

      let html = '<table class="w-full text-left border-collapse">' +
        '<thead><tr class="border-b border-slate-200 text-slate-500 text-xs">' +
          '<th class="py-2.5 px-3">姓名</th>' +
          '<th class="py-2.5 px-3">店家 / 品項</th>' +
          '<th class="py-2.5 px-3">備註</th>' +
          '<th class="py-2.5 px-3 text-right">金額</th>' +
        '</tr></thead><tbody class="divide-y divide-slate-100">';

      orders.forEach(o => {
        html += '<tr class="hover:bg-slate-50 transition">' +
          '<td class="py-2.5 px-3 font-bold text-slate-800">' + o.user_name + '</td>' +
          '<td class="py-2.5 px-3">' +
            '<span class="text-xs text-blue-600 font-semibold">[' + (o.store_name || '未指定') + ']</span> ' +
            '<span class="font-medium text-slate-700">' + o.item_name + '</span>' +
          '</td>' +
          '<td class="py-2.5 px-3 text-xs text-slate-500">' + (o.note || '-') + '</td>' +
          '<td class="py-2.5 px-3 text-right font-bold text-slate-700">$' + o.price + '</td>' +
        '</tr>';
      });
      html += '</tbody></table>';
      container.innerHTML = html;
    }

    initPage();
  </script>
</body>
</html>
