<?php
// ai_menu_parser.php
require_once 'db.php';
header('Content-Type: application/json; charset=utf-8');

// 取得 API Key (優先從資料庫讀取，若無可填入預設常數)
$apiKey = $pdo->query("SELECT setting_value FROM system_settings WHERE setting_key = 'gemini_api_key'")->fetchColumn();

if (empty($apiKey)) {
    echo json_encode(['status' => 'error', 'message' => '尚未設定 Gemini API Key，請先於系統設定填入！']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['menu_image']['tmp_name'])) {
    echo json_encode(['status' => 'error', 'message' => '請上傳菜單圖片']);
    exit;
}

$fileTmp = $_FILES['menu_image']['tmp_name'];
$fileMime = mime_content_type($fileTmp);
$base64Data = base64_encode(file_get_contents($fileTmp));

// 提示詞：嚴格要求抓取餐點名稱與整數價格，過濾單點小菜或非餐點文字
$prompt = <<<PROMPT
你是一個專業的餐廳菜單資料擷取助理。請從提供的菜單圖片中辨識所有的主餐、便當或飲料品項與其單價。
規則：
1. 忽略店家介紹、電話、營業時間、單點配菜等非主餐/飲料項目。
2. 品項名稱請保留完整中文（若有備註如炸、滷可保留在括號內）。
3. 價格必須是純整數數字（不要包含 $ 或「元」）。
4. 嚴格輸出合法的 JSON Array，格式為：
[
  {"name": "品項名稱", "price": 100},
  {"name": "另一個品項", "price": 80}
]
不需要任何 Markdown 標記、不需要額外說明，只輸出純 JSON。
PROMPT;

// 呼叫 Gemini 2.5 Flash
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";

$payload = [
    "contents" => [
        [
            "parts" => [
                ["text" => $prompt],
                [
                    "inline_data" => [
                        "mime_type" => $fileMime,
                        "data" => $base64Data
                    ]
                ]
            ]
        ]
    ],
    "generationConfig" => [
        "response_mime_type" => "application/json",
        "temperature" => 0.2
    ]
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 30);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    echo json_encode(['status' => 'error', 'message' => "Gemini API 呼叫失敗 (HTTP {$httpCode})"]);
    exit;
}

$resData = json_decode($response, true);
$rawText = $resData['candidates'][0]['content']['parts'][0]['text'] ?? '';

// 清除可能包夾的 markdown 標籤
$rawText = trim(str_replace(['```json', '```'], '', $rawText));
$parsedMenu = json_decode($rawText, true);

if (!is_array($parsedMenu)) {
    echo json_encode(['status' => 'error', 'message' => 'AI 回傳格式解析失敗', 'raw' => $rawText]);
    exit;
}

echo json_encode([
    'status' => 'success',
    'data' => $parsedMenu
]);