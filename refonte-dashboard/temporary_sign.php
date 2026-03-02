<?php
/**
 * temporary_sign.php — Générateur de lien signé (dev/debug)
 *
 * Utilité : génère un URL signé HMAC pour tester session_start.php
 * sans passer par le flow d'auth normal.
**/

// Chargement du .env si disponible (local)
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if (!isset($_ENV[$key]) && getenv($key) === false) {
            putenv("$key=$value");
            $_ENV[$key] = $value;
        }
    }
}

// Lecture du secret depuis l'environnement
$secret = getenv('APP_SESSION_SECRET') ?: ($_ENV['APP_SESSION_SECRET'] ?? '');

if (!is_string($secret) || $secret === '') {
    fwrite(STDERR, "❌ APP_SESSION_SECRET manquant ou vide.\n");
    fwrite(STDERR, "   → En local   : vérifier le fichier .env\n");
    fwrite(STDERR, "   → En CI/CD   : vérifier GitHub Secrets\n");
    exit(1);
}

// Paramètres du lien signé
$userId = '67a21edbeaae44f4240c2107'; // à adapter
$exp    = time() + 600;               // valide 10 minutes
$sig    = hash_hmac('sha256', $userId . '|' . $exp, $secret);

echo "http://localhost:8000/api/session_start.php"
   . "?user_id=" . urlencode($userId)
   . "&exp=$exp"
   . "&sig=$sig"
   . "\n";