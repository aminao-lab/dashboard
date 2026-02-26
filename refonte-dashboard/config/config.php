<?php

// Include database functions
if (file_exists(__DIR__ . '/../includes/database.php')) {
    require_once __DIR__ . '/../includes/database.php';
}

$LW_API_TOKEN = getenv('LW_API_TOKEN');
$LW_BASE_URL = getenv('LW_BASE_URL');
$LW_CLIENT_ID = getenv('LW_CLIENT_ID');

$SUPABASE_URL = getenv('SUPABASE_URL');
$SUPABASE_SERVICE_KEY = getenv('SUPABASE_SERVICE_KEY');

$APP_SESSION_SECRET = getenv('APP_SESSION_SECRET');

// Configuration des niveaux
define('NIVEAUX', ['6eme', '5eme', '4eme', '3eme', '2nde', '1ere', 'term', 'term-pc']);

// Mapping course_id LearnWorlds → Niveaux
define('COURSE_MAPPING', [
    // Cours principaux
    'pc-terminale' => 'term-pc',
    'maths-terminale' => 'term',
    'maths-premiere' => '1ere',
    'maths-seconde' => '2nde',
    'maths-6eme' => '6eme',
    'maths-5eme' => '5eme',
    'maths-4eme' => '4eme',
    'maths-3eme' => '3eme',
]);

// Configuration des batches
define('BATCH_SIZE', 100); // Nombre d'utilisateurs par lot
define('API_DELAY', 1); // Délai en secondes entre les appels API
define('MAX_EXECUTION_TIME', 300); // 5 minutes max par exécution

// Timezone
date_default_timezone_set('Europe/Paris');

// Déterminie si le script est exécuté en ligne de commande(CLI) ou via un serveur web
$isCli = (PHP_SAPI === 'cli');

// Récupère le nom du domaine si dispo, sinon une chaîne vide
$serverName = $_SERVER['SERVER_NAME'] ?? '';
if ($isCli || $serverName === 'localhost') { // si en CLI ou localhost
    // Affiche les erreurs directement dans la page
    ini_set('display_errors', 1);
    error_reporting(E_ALL); // active tous les niveaux d'erreurs
} else {
    ini_set('display_errors', 0); // sinon les cacher en prod
    error_reporting(0); // désactive complètement le reporting d'erreurs
}

// Timeout
set_time_limit(MAX_EXECUTION_TIME);
ini_set('memory_limit', '512M');

function getLatestWeeksPerUser($weekLimit) {
    // TODO: Implement the select function in database.php or replace with your database query method
    // $allWeeks = select('temps_week', '*', [
    //     'semaine' => "lt.{$weekLimit}"
    // ], [
    //     'order' => 'semaine.desc'
    // ]);
    $allWeeks = [];
    
    if (!$allWeeks || count($allWeeks) === 0) {
        return [];
    }
    
    $latestWeeks = [];
    foreach ($allWeeks as $week) {
        $userId = $week['user_id'];
        if (!isset($latestWeeks[$userId])) {
            $latestWeeks[$userId] = $week;
        }
    }
    
    return $latestWeeks;
}