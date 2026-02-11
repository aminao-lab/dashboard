<?php
require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../config/supabase.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

$userId = $_SESSION['user_id'] ?? '';
if ($userId === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$startTs = time();

$payload = [
    'user_id' => $userId,
    'start_ts' => $startTs
];

$ch = curl_init(SUPABASE_URL . '/rest/v1/sessions_raw');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json'
    ]
]);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase insert failed', 'details' => $response]);
    exit;
}

echo json_encode([
    'ok' => true,
    'session_id' => json_decode($response, true)[0]['id'],
    'start_ts' => $startTs
]);
