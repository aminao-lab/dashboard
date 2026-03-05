<?php

// API pour récupérer la liste des étudiants depuis Supabase
require_once __DIR__ . '/../../config/config.php'; // charge la configuration générale
require __DIR__ . '/../../config/supabase.php'; // charge les constantes liées à Supabase

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405); // si requete API different de GET
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Récupère le paramètre 'limit' pour limiter le nombre d'étudiants retournés
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 50;
$limit = max(1, min($limit, 200));

// Vérifs config
if (!defined('SUPABASE_URL') || !defined('SUPABASE_SERVICE_KEY')) {
    http_response_code(500);
    echo json_encode(['error' => 'Supabase config missing']);
    exit;
}

$supabaseUrl = rtrim(SUPABASE_URL, '/') . '/rest/v1/students'; // supprime slash final si présent

// Prépare la requête
$query = http_build_query([
    'select' => 'user_id,email,username,is_enrolled,date_maj',
    'order'  => 'date_maj.desc',
    'limit'  => $limit
]);

// Exécute la requête vers Supabase
$headers = [
    'apikey: ' . SUPABASE_SERVICE_KEY, // Clé API Supabase
    'Authorization: Bearer ' . SUPABASE_SERVICE_KEY, // Authentification Supabase
    'Accept: application/json' // On attend du JSON en retour
];

$ch = curl_init($supabaseUrl . '?' . $query);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true, // Retourne le résultat au lieu de l'afficher
    CURLOPT_HTTPHEADER     => $headers, // Ajoute les headers définis
    CURLOPT_TIMEOUT        => 10, // évite que l'API freeze si Supabase ne répond pas
]);

$response = curl_exec($ch); // contenu JSON renvoyé par Supabase
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE); 
$curlErr  = curl_error($ch);
curl_close($ch); // ferme la connexion cURL

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

// Convertit la réponse JSON en tableau PHP
$data = json_decode($response, true);
echo json_encode([
    'ok' => true,
    'count' => is_array($data) ? count($data) : 0,
    'students' => $data
], JSON_UNESCAPED_UNICODE);
