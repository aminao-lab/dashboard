<?php
// public/api/session_end.php

require __DIR__ . '/../../config/config.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

// Si aucune session → déjà déconnecté
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'ok' => true,
        'status' => 'already_logged_out'
    ]);
    exit;
}

// Supprimer toutes les variables de session
$_SESSION = [];

// Détruire la session côté serveur
if (session_id() !== '' || isset($_COOKIE[session_name()])) {
    // Supprimer le cookie de session
    setcookie(session_name(), '', time() - 3600, '/');
}

// Détruire la session
session_destroy();

echo json_encode([
    'ok' => true,
    'status' => 'logged_out'
]);
