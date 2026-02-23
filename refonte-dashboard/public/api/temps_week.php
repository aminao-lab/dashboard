<?php
// public/api/temps_week.php

require __DIR__ . '/../../config/config.php';
require __DIR__ . '/../../config/supabase.php';

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

/* ---------------------------------------------------------
   1) RÉCUPÉRER LES 7 DERNIERS JOURS DANS daily_activity
--------------------------------------------------------- */

$today = new DateTime('today', new DateTimeZone('Europe/Paris'));
$start = (clone $today)->modify('-6 days')->format('Y-m-d');
$end   = $today->format('Y-m-d');

$history = sb_get('/rest/v1/temps_week', [
    'select' => 'activity_date,seconds_spent,is_active',
    'user_id' => 'eq.' . $userId,
    'activity_date' => 'gte.' . $start,
    'order' => 'activity_date.asc'
]);

if ($history === null) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch daily_activity']);
    exit;
}

/* ---------------------------------------------------------
   2) RÉCUPÉRER LE CUMUL TOTAL DANS daily_cumul_snapshot
--------------------------------------------------------- */

$cumulRow = sb_get('/rest/v1/daily_cumul_snapshot', [
    'select' => 'total_cumul_seconds,streak_jours,streak_mois_pct',
    'user_id' => 'eq.' . $userId,
    'order' => 'snapshot_date.desc',
    'limit' => 1
]);

$cumul = [
    'total_cumul_seconds' => 0,
    'streak_jours' => 0,
    'streak_mois_pct' => 0
];

if ($cumulRow && count($cumulRow) > 0) {
    $cumul = [
        'total_cumul_seconds' => (int)$cumulRow[0]['total_cumul_seconds'],
        'streak_jours'        => (int)$cumulRow[0]['streak_jours'],
        'streak_mois_pct'     => (int)$cumulRow[0]['streak_mois_pct']
    ];
}

/* ---------------------------------------------------------
   3) RÉPONSE FINALE POUR LE DASHBOARD
--------------------------------------------------------- */

echo json_encode([
    'ok' => true,
    'history_7d' => $history,
    'cumul' => $cumul
], JSON_UNESCAPED_UNICODE);
