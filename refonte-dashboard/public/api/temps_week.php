<?php
// public/api/temps_week.php

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/supabase.php';

header('Content-Type: application/json; charset=utf-8');
session_start();

// Vérifier authentification
$userId = $_SESSION['user_id'] ?? '';
if ($userId === '') {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// Vérifier méthode
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Helper Supabase GET
function sb_get($path, $query = []) {
    $url = rtrim(SUPABASE_URL, '/') . $path . '?' . http_build_query($query);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'apikey: ' . SUPABASE_SERVICE_KEY,
            'Authorization: Bearer ' . SUPABASE_SERVICE_KEY,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 10,
    ]);

    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($http >= 400 || !$resp) {
        return null;
    }

    return json_decode($resp, true);
}

// ✅ RÉCUPÉRER TOUTES LES SEMAINES DE CET UTILISATEUR
$rows = sb_get('/rest/v1/temps_week', [
    'select' => '*',
    'user_id' => 'eq.' . $userId,
    'order' => 'semaine.desc'
]);

if ($rows === null) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to fetch temps_week']);
    exit;
}

// ✅ RÉPONSE
echo json_encode([
    'ok' => true,
    'rows' => $rows
], JSON_UNESCAPED_UNICODE);