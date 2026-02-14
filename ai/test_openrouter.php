<?php
header('Content-Type: text/html; charset=utf-8');
$apiKey = (string) (getenv('AI_API_KEY') ?: '');
$model = (string) (getenv('AI_MODEL') ?: 'meta-llama/llama-3.3-70b-instruct:free');
echo "<h2>OpenRouter Connection Test</h2>";

if ($apiKey === '') {
    echo "<p style='color:#b91c1c'>AI_API_KEY is not configured.</p>";
    exit;
}

if (!function_exists('curl_init')) {
    echo "<p style='color:#b91c1c'>cURL extension missing.</p>";
    exit;
}

$ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 25,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json',
        'HTTP-Referer: https://ai.velhightech.com',
        'X-Title: VEL AI Test'
    ],
    CURLOPT_POSTFIELDS => json_encode([
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => "Say 'Hello from VEL AI test'"]],
    ]),
]);
$raw = curl_exec($ch);
$code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($err) {
    echo "<p style='color:#b91c1c'>cURL Error: " . htmlspecialchars($err) . "</p>";
    exit;
}
if ($code !== 200) {
    echo "<p style='color:#b91c1c'>HTTP $code</p><pre>" . htmlspecialchars((string) $raw) . "</pre>";
    exit;
}
$j = json_decode((string) $raw, true);
$reply = (string) ($j['choices'][0]['message']['content'] ?? '');
echo "<p style='color:#15803d'>Success.</p>";
echo "<blockquote style='background:#f8fafc;border-left:4px solid #15803d;padding:10px'>" . htmlspecialchars($reply) . "</blockquote>";
?>
