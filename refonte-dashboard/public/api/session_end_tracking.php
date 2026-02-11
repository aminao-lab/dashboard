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

$sessionId = $_GET['session_id'] ?? '';
if ($sessionId === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing session_id']);
    exit;
}

// 1) Récupérer la session
$url = SUPABASE_URL . '/rest/v1/sessions_raw?id=eq.' . $sessionId . '&select=*';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY
    ]
]);

$response = curl_exec($ch);
$http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($http >= 400 || !$response) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase fetch failed', 'details' => $response]);
    exit;
}

$data = json_decode($response, true);
if (empty($data)) {
    http_response_code(404);
    echo json_encode(['error' => 'Session not found']);
    exit;
}

$session = $data[0];
$startTs = (int)$session['start_ts'];
$endTs = time();
$duration = max(0, $endTs - $startTs);

// 2) Update session
$payload = [
    'end_ts' => $endTs,
    'duration_sec' => $duration
];

$ch = curl_init(SUPABASE_URL . '/rest/v1/sessions_raw?id=eq.' . $sessionId);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CUSTOMREQUEST => 'PATCH',
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_HTTPHEADER => [
        'apikey: ' . SUPABASE_SERVICE_KEY,
        'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
        'Content-Type: application/json'
    ]
]);

$updateResponse = curl_exec($ch);
$updateHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($updateHttp >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase update failed', 'details' => $updateResponse]);
    exit;
}

echo json_encode([
    'ok' => true,
    'duration_sec' => $duration
]);
