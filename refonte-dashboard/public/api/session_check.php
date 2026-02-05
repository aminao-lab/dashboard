<?php
// Démarrage session si nécessaire
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Vérification utilisateur connecté
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode([
        'ok' => false,
        'error' => 'Not authenticated'
    ]);
    exit;
}

// Exposé pour les endpoints
$currentUserId = $_SESSION['user_id'];
