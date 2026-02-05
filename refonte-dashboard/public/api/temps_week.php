<?php
// public/api/temps_week.php

require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../config/supabase.php';

header('Content-Type: application/json; charset=utf-8');

session_start();

$userId = $_SESSION['user_id'] ?? '';
if ($userId === '') {
  http_response_code(401);
  echo json_encode(['error' => 'Not authenticated']);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

//$userId = isset($_GET['user_id']) ? trim($_GET['user_id']) : '';
//if ($userId === '') {
//    http_response_code(400);
//    echo json_encode(['error' => 'Missing user_id']);
//    exit;
//}

$baseUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/temps_week';

$query = http_build_query([
    'select' => '*',
    'user_id' => 'eq.' . $userId,
    'order' => 'semaine.asc',
    'limit' => 600
]);

$headers = [
    'apikey: ' . SUPABASE_SERVICE_KEY,
    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
    'Accept: application/json'
];

$ch = curl_init($baseUrl . '?' . $query);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => $headers,
    CURLOPT_TIMEOUT        => 10,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($response === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Curl failed', 'details' => $curlErr]);
    exit;
}

if ($httpCode >= 400) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase error', 'http' => $httpCode, 'details' => $response]);
    exit;
}

echo json_encode(['ok' => true, 'rows' => json_decode($response, true)], JSON_UNESCAPED_UNICODE);
exit;