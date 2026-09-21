# 🍱 辦公室 / 團隊便當飲料團購系統

專為辦公室與三班制團隊設計的點餐系統，支援便當加總、飲料甜度冰量規格選單、滷味炸物個人分袋叫餐、手機對帳找零與一鍵防跑單結單。

---

## 🚀 一鍵部署至 Cloudflare Workers

點擊下方按鈕即可一鍵複製本專案並部署至您的 Cloudflare 帳號：

[![Deploy to Cloudflare Workers](https://deploy.workers.cloudflare.com/button)](https://deploy.workers.cloudflare.com/?url=https://github.com/jai4shao/bento)

---

## 💡 快速啟用指南 (僅需 1 分鐘)

### 事前準備
### 事前準備（若已有帳號可直接略過）
1. <a href="https://github.com/signup" target="_blank">註冊 GitHub 帳號</a>（用於存放您的系統專案複本）。
2. <a href="https://dash.cloudflare.com/sign-up" target="_blank">註冊 Cloudflare 帳號</a>（免費方案即可完整運作）。

### 部署步驟
1. 點擊上方的 **「Deploy to Cloudflare Workers」** 藍色按鈕。
2. 授權 GitHub 並選擇您的 Cloudflare 帳號，點擊確認部署。
   > 系統會全自動為您建立 D1 資料庫並完成設定。
3. 部署完成後，點擊網址（`https://xxx.workers.dev`）即可開始使用！
   > 💡 **小提醒（若首次建立 Worker）**：  
   > 若畫面未出現可點擊的網址，請點Workers & Pages 進該 Worker 專案 ➔ 切換到 **Settings** ➔ **Domains**），將 **`Worker URL`** 網址按鈕切換為「啟用（Enabled）」即可。

---

## 🛠️ 系統功能與操作入口

- **前台登記頁面 (`/`)**：挑選姓名、瀏覽當日供餐店家菜單、支援多份點餐與規格備註；僅能修改/取消自己的點餐紀錄。
- **店家與菜單管理 (`/store_admin`)**：
  - **本日供餐快捷總覽**：隨時檢視與切換當班供餐店家，支援一鍵清空重選。
  - **三大核心分類**：
    - `便當`：正餐加總叫餐。
    - `飲料`：自動帶入固定糖量與冰量規格。
    - `合併點餐`：鹽酥雞、滷味、車輪餅等個人分裝餐點。
  - **AI 匯入助手**：提供 3 種分類專屬 Prompt，複製後讓 AI 讀取菜單圖片即可產生匯入語法。
- **訂單核銷後台 (`/admin`)**：
  - **一鍵結單鎖定**：向店家訂餐後一鍵結單，前台立即上鎖防止跑單加訂。
  - **店家分卡叫餐**：依店家各自獨立成卡，合併點餐自動轉為「個人分袋打包叫餐清單」。
  - **手機卡片對帳**：點擊人名展開點餐明細；輸入大鈔自動計算應找金額，確認找零後自動結清校正。
